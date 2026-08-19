"""OpenRouter — an OpenAI-shaped gateway fronting hundreds of upstreams on one credential.

Raw ``httpx``, no SDK, deliberately. Pointing the ``openai`` SDK at this base URL parses,
and then quietly loses the four fields this adapter exists to read — ``openrouter_metadata``,
``native_finish_reason``, ``usage.cost``, and the top-level ``error`` object that arrives
inside an HTTP 200 — because an SDK models the vendor it was written for. It also brings its
own retry ladder to stack on ours.

ADR-001 says five vendors, integrated directly, no gateway. OpenRouter *is* a gateway, so
this adapter is structurally unlike its four siblings: one credential, hundreds of models,
dozens of upstreams, and a routing layer of its own between us and whoever actually answers.
Every rule below exists to make that layer **observable and inert**, not to use it.

## The divergence that has no equivalent anywhere else: attribution

**This is the only vendor whose failure may belong to someone else.** A 502 can be
OpenRouter's or an upstream's, and the two need different breaker keys and different pages.
Attribution therefore happens BEFORE classification, and it reads
``error.metadata.error_type`` and ``error.metadata.provider_code`` — never the HTTP status,
which is ambiguous in both directions.

* ``metadata.provider_code`` present -> the upstream produced it. Breaker key gets a fourth
  term: ``(org_id, connection_id, model, upstream_slug)``. A brownout on one upstream must
  not open the breaker for every other model riding the same credential.
* ``provider_code`` absent on a 5xx, or 402 / platform 429 / 401 / moderation 403 /
  unsatisfiable 503 -> the gateway produced it. Key on
  ``(org_id, connection_id, "_openrouter")``, which opens every model on that connection,
  because those are properties of the account.
* No body at all — connect timeout, first-token timeout -> gateway-wide, deliberately
  pessimistic. OpenRouter is the only hop we know was involved.

The upstream slug is a **Valkey key component only, never a metric label**: the slug set is
dozens and grows without our involvement, and unbounded labels are banned. ``kb_provider_*``
keeps ``provider="openrouter"``; the slug goes on the span as ``kb.upstream_provider`` and
into the ``provider_calls`` row.

## Divergences this file exists to absorb

**OpenRouter's own routing must never make a decision we cannot see.** Every request sends
``provider.allow_fallbacks: false`` with an explicit ``provider.order``, and **never** the
``models: [...]`` array. Our ordered fallback writes a ``provider_calls`` row per attempt;
OpenRouter's writes nothing we can read, bills at another upstream's price, and is exactly
the fallback-inside-fallback amplification the taxonomy forbids.

**``require_parameters`` defaults to FALSE**, which means an upstream that does not support
``response_format`` or ``reasoning`` is routed to anyway and the parameter is discarded:
HTTP 200, plausible prose, no schema, no error, and an eval suite that scores the degraded
answer as a model regression. Sending ``require_parameters: true`` converts that silence
into a classifiable 503 — which is why the unsatisfiable-routing 503 below will be the
common one, not the rare one.

**A 503 carries two unrelated meanings.** ``error_type: "provider_overloaded"`` is capacity:
temporary, fallback-eligible. A 503 with **no** ``error_type`` — "no available model provider
meets your routing requirements" — means our own ``order``/``zdr``/``require_parameters``
block excludes every endpoint. That is deterministic and permanent; retrying it three times
and then falling back fails identically everywhere.

**402 is ``provider_billing``, and it is what that class exists for.** Depleted credits,
including a negative balance, and it reaches free model variants too. Never retried, never
fallen back, pages the operator. It was mapped to ``provider_auth`` before the billing class
existed; the policy is identical but the class is what a dashboard and a runbook key on, so
an exhausted balance must not read as a revoked key.

**429 is the gateway's limiter, the upstream's, or both**, and ``Retry-After`` appears only
when every attempted upstream sent a hint. ``X-RateLimit-Limit/Remaining/Reset`` are parsed
when present; nothing is invented when they are not.

**Mid-stream errors arrive inside an HTTP 200.** Headers were already committed, so the
status stays 200 while the chunk carries a top-level ``error`` object and
``finish_reason: "error"``. A reader that inspects only the status records a successful call
with truncated text and no ``error_class``. Check for ``error`` on **every** chunk, not just
the first.

**SSE comment lines (``: OPENROUTER PROCESSING``) are keep-alives**, and a reader that does
not skip them crashes on the first one under load.

**The model that answered is not necessarily the model we priced.**
``gen_ai.response.model`` is read off the response and never assumed equal to the request;
``served_model != req.model`` raises a ``CapabilityWarning``. ``usage.cost`` (credits, always
present) is the authoritative cost — OpenRouter spend is never computed from
``provider_models`` pricing metadata, which describes a model rather than the upstream that
served it. Auto Exacto reorders providers automatically on any request carrying ``tools``,
with no opt-in, which is one more reason the pin is explicit.

**Served-by attribution is opt-in.** ``openrouter_metadata`` requires the
``X-OpenRouter-Metadata: enabled`` request header, and it arrives on the **final** streamed
chunk — so a reader that stops at the last text delta loses it, and ``Diagnostics.served_by``
is null forever, which silently breaks upstream-scoped breaker keying. The documented
response type has **no** top-level ``provider`` field; treat any such field as best-effort
corroboration and the metadata object as the source.
<!-- UNVERIFIED: whether the undocumented top-level ``provider`` field is still populated on
non-error responses. -->

**``HTTP-Referer`` is a module constant naming OUR public URL.** Filled from the inbound
request — or forwarded from the browser by a proxy — it publishes every customer domain
embedding the widget onto a third party's public app-rankings page. The safe failure is
having no app page.

**The moderation error body contains tenant text.** A 403 moderation block returns
``metadata.flagged_input`` — up to 100 characters of the user's question or a retrieved
chunk. ``reasons`` and ``provider_name`` are allow-listed into ``Diagnostics``;
``flagged_input`` is dropped at the parse site, not scrubbed downstream.

**``debug: {echo_upstream_body: true}`` echoes the assembled prompt** — packed tenant
document text plus the question, the most sensitive object in the system. Absent from every
environment, and the debug chunk must never reach a diagnostics payload or a log sink.

**Cancellation billing is upstream-dependent.** Aborting stops generation and billing on some
upstreams and not others, so a cancelled turn's cost is genuinely unknowable at our layer.
``stop_reason=CANCELLED`` always carries ``source="estimated"`` and is never invoiced.

**The catalog is third-party and volatile.** Endpoints carry an expiration date, request ids
may be aliases, and only ``canonical_slug`` is documented as permanent. OpenRouter ships
non-breaking changes without notice and instructs clients to ignore unrecognised fields and
unknown enum values — so parse permissively: an unknown ``finish_reason`` becomes
``StopReason.ERROR`` with ``native_finish_reason`` preserved, never a guess.

**As a fallback target, OpenRouter is conditional.** Falling back from a direct vendor to the
same vendor's model on OpenRouter routes straight back into the outage being escaped, one hop
later and at a markup. Since the served upstream is only knowable after the response, the rule
is enforced at configuration time: an OpenRouter model may be a fallback target only if its
``routing_pin`` names exactly one upstream slug. Unpinned or multi-slug is a valid primary and
an invalid fallback.

## Stream event -> internal event

| Chunk shape | Internal |
|---|---|
| line starting ``:`` | skipped before parsing — keep-alive |
| ``data: [DONE]`` | end of stream |
| ``choices[0].delta.content`` | ``Delta(kind="text")`` |
| ``choices[0].delta.reasoning`` | ``Delta(kind="reasoning")`` where the upstream returns it |
| ``choices[0].delta.tool_calls[].function.arguments`` | ``Delta(kind="tool_args")`` |
| top-level ``error`` inside a 200 | attribute, classify, terminate — never a success |
| ``choices[0].finish_reason``, ``native_finish_reason`` | mapped; native value preserved |
| final chunk: ``usage``, ``openrouter_metadata`` | usage, cost, ``served_by``, terminal event |

## Usage normalization

OpenAI-shaped totals, plus a cost the other four do not report::

    Usage.input_tokens       = usage.prompt_tokens - <cached, when the upstream reports it>
    Usage.cache_read_tokens  = <cached, when reported>
    Usage.cache_write_tokens = 0
    Usage.output_tokens      = usage.completion_tokens
    Usage.reasoning_tokens   = usage.completion_tokens_details.reasoning_tokens, else 0

The cached amount, when present, follows OpenAI's convention — a SUBSET of the prompt count,
so it is subtracted. It is upstream-dependent and frequently absent, and absent means zero
here rather than an inference. ``usage.cost`` is recorded into ``Diagnostics.extras`` and
into the ``provider_calls`` row; it is the only authoritative price on this vendor.
<!-- UNVERIFIED: which upstreams surface a cached-token breakdown through the gateway, and
under which key, was not re-checked against the current usage-accounting page. -->
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import Any, Final, Literal

from pydantic import BaseModel, ConfigDict, SecretStr

from app.core.errors import ErrorClass
from app.providers.contract import (
    CapabilityWarning,
    ChatRequest,
    EmbeddingAdapter,
    EmbeddingRequest,
    EmbeddingResult,
    ModelCapabilities,
    ProviderAdapter,
    RerankAdapter,
    RerankRequest,
    RerankResult,
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import ProviderCallFailed

__all__ = ["Attribution", "OpenRouterAdapter", "RoutingPin"]

URL: Final[str] = "https://openrouter.ai/api/v1/chat/completions"

#: Attribution headers name OUR product, never the tenant's site.
#: ``X-OpenRouter-Metadata`` is opt-in and is the only documented served-by evidence.
STATIC_HEADERS: Final[dict[str, str]] = {
    "X-OpenRouter-Title": "KnowledgeBot AI",
    "X-OpenRouter-Metadata": "enabled",
}

#: ``error.metadata.error_type`` -> class. Read FIRST; the status is the tiebreak.
ERROR_TYPE_TO_CLASS: Final[dict[str, ErrorClass]] = {
    "authentication": ErrorClass.PROVIDER_AUTH,
    "payment_required": ErrorClass.PROVIDER_BILLING,
    "rate_limit_exceeded": ErrorClass.PROVIDER_RATE_LIMIT,
    #: Terminal, and also sets ``StopReason.REFUSAL``. Falling back re-asks a banned question
    #: and bills for it twice.
    "content_policy_violation": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "refusal": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "permission_denied": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "invalid_request": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "context_length_exceeded": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "max_tokens_exceeded": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "payload_too_large": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "invalid_image": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "provider_overloaded": ErrorClass.PROVIDER_TEMPORARY,
    "provider_unavailable": ErrorClass.PROVIDER_TEMPORARY,
    "timeout": ErrorClass.PROVIDER_TEMPORARY,
    "server": ErrorClass.PROVIDER_TEMPORARY,
    "unmapped": ErrorClass.PROVIDER_TEMPORARY,
}

#: A 503 with NO ``error_type`` is our own routing block, not an outage — deterministic and
#: permanent. This constant exists so the branch is named rather than implied.
UNSATISFIABLE_ROUTING: Final[ErrorClass] = ErrorClass.PROVIDER_PERMANENT_REQUEST

#: Upstream ``finish_reason`` values pass through OpenAI's vocabulary. Unknown values are
#: expected here more than anywhere else — the vendor explicitly instructs clients to
#: tolerate unknown enum values — and become ``ERROR`` with the native value preserved.
STOP: Final[dict[str, StopReason]] = {
    "stop": StopReason.COMPLETE,
    "length": StopReason.MAX_OUTPUT,
    "tool_calls": StopReason.TOOL_USE,
    "content_filter": StopReason.REFUSAL,
    #: Mid-stream failure inside a 200. Never a completed answer.
    "error": StopReason.ERROR,
}


class RoutingPin(BaseModel):
    """The upstream pin for one ``provider_models`` row.

    ``slugs`` comes from the routing-pin control on the model page and is stored on the row
    alongside ``canonical_slug`` — the only field the vendor documents as permanent. One slug
    means a deterministic upstream, which is also the precondition for using this model as a
    fallback target at all.
    """

    model_config = ConfigDict(frozen=True)

    slugs: tuple[str, ...]
    zdr: bool = False


class Attribution(BaseModel):
    """Who owns this failure. Produced before classification, consumed by the breaker.

    Vendor-specific and therefore local to this module: the shared contract stays identical
    across five vendors, and four of them have exactly one possible owner. The adapter
    decides attribution; the policy layer composes the breaker key from it and never the
    other way round.
    """

    model_config = ConfigDict(frozen=True)

    scope: Literal["upstream", "gateway"]
    #: Valkey key component only — never a metric label.
    upstream_slug: str | None = None


class OpenRouterAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags are per ``provider_models`` row as everywhere else, but they mean
    something weaker here: they describe the *model*, while the *upstream* that serves it is
    what actually honours a parameter. ``require_parameters: true`` is what turns that gap
    from a silent 200 into a classifiable failure, so it is not optional and is not a tuning
    knob.

    Never used, each because it moves the model out from under its capability row or adds a
    routing layer: ``openrouter/auto``, ``~``-prefixed auto-updating aliases, ``:free`` and
    other variant suffixes, ``plugins``, and the gateway's own responses surface.

    **``embed`` AND ``rerank``, and this is the only adapter in the package that carries all
    three surfaces.** Both cells were ``UNVERIFIED`` and both are now ``SUPPORTED`` against the
    vendor's own documentation: ``POST /api/v1/embeddings`` and ``POST /api/v1/rerank``.

    The skills had half of it. ``nvidia-nim-api``'s first non-negotiable said "OpenRouter
    reaches one only through specific models" and, in the same bullet, that NIM is "the *sole*
    implementation of the ``rerank()`` capability" — a contradiction inside one sentence, of
    which the first half turns out to be right about the model and wrong about the route: the
    route is first-class and it is the *model* that is a namespaced upstream
    (``cohere/rerank-v3.5`` in the vendor's example). ``openrouter-api/SKILL.md`` is silent on
    both surfaces because it predates them, not because they are absent, and that distinction
    is the reason a matrix cell cites a vendor URL and a date rather than a skill.

    **The score scale is still not sourced, and that is a verdict rather than a gap.** This
    vendor is a gateway over many upstreams on one credential and it documents no normalization
    guarantee between them, so ``relevance_score`` means whatever the upstream that served this
    particular request meant by it. ``capabilities.RERANK_SCALE`` therefore has no entry for
    ``openrouter``, which makes it ``RerankScale.UNCALIBRATED``. Reporting anything else —
    ``UNIT_INTERVAL`` is the tempting guess, because a Cohere-shaped score looks like one —
    would make a ``min_score`` constructible against a distribution nobody measured, and the
    only symptom would be a refusal rate that moved with nothing else to explain it.

    **So this adapter is NOT rerank-eligible, and that is finding #47.** This paragraph used to
    end "usable for *ordering* and never for a threshold", and the pipeline has no ordering-only
    path: ``app.rag.rerank.RerankCalibration`` cannot be constructed on an unthresholdable
    scale, stage 11 requires one, and stage 12's only calibration-free route
    (``evidence.select_unranked``) never calls a reranker. ``capabilities.can_rerank`` answers
    ``False`` for this provider. ``capabilities.assert_row_coherent`` would refuse an
    ``openrouter`` rerank row, and this line used to say it does so "when it is saved" — it does
    not, because nothing calls that function on a write path (finding J1). The row saves, and
    ``can_rerank`` is the whole of what stops it.

    **The method below stays, and deleting it would be the wrong fix.**
    ``PROVIDER_TASKS[("openrouter", RERANK)]`` is a statement about the *vendor*, the vendor
    does publish ``POST /api/v1/rerank``, and ``test_method_presence_matches_matrix`` asserts
    the method and the cell move together. Eligibility is the third, separate axis. Making it
    eligible needs ``RERANK_SCALE`` re-keyed off the provider — the scale here belongs to the
    upstream, so no per-provider measurement can be true — which is a contract change and not
    an evaluation run.

    Two rules carry over from the chat surface unchanged, and one is sharper here:

    * The ``provider`` block is mandatory on all three surfaces, with ``allow_fallbacks:
      false``. On chat a silent reroute costs an answer in a different voice; on **embedding**
      it costs the vector space, because the same model id served by a different upstream is
      not guaranteed to be the same weights or the same numerics, and nothing downstream can
      see the difference — Qdrant accepts the points, cosine distance is defined, and only
      recall moves.
    * ``data_collection: "deny"``. Passages sent to ``/rerank`` and chunks sent to
      ``/embeddings`` are tenant documents in full, not a user's phrasing of a question, so
      routing them to an upstream that trains on them is a data-processing breach.
    """

    name = "openrouter"
    min_useful_seconds = 5.0

    def __init__(self, http: Any, public_app_url: str) -> None:
        """``http`` is a shared ``httpx.AsyncClient`` with NO internal retry layer.

        ``public_app_url`` becomes ``HTTP-Referer`` and is a configuration value, provably
        independent of any inbound request — see the module docstring.
        """
        self._http = http
        self._public_app_url = public_app_url

    def _body(self, req: ChatRequest, caps: ModelCapabilities, pin: RoutingPin) -> dict[str, Any]:
        """Build the request body.

        The ``provider`` block is mandatory in full: ``order`` from the pin,
        ``allow_fallbacks: false``, ``require_parameters: true``, ``data_collection: "deny"``,
        and ``zdr: true`` when the connection requires it. Retrieved chunks are tenant
        documents; routing them to an upstream that trains on them is a data-processing
        breach, not a configuration preference.

        Deliberately absent, and asserted absent by a test that greps the serialized body:
        ``models``, ``route``, ``debug``.
        """
        raise NotImplementedError("openrouter-api: chat.completions body + provider block")

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``.

        Also the place a structured-output request gains ``strict: true`` inside its
        ``json_schema``: without it, upstreams lacking a native strict mode treat the schema
        as a hint and return prose.
        """
        raise NotImplementedError

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Stream over raw httpx and translate line by line.

        Two error surfaces, not one: a pre-stream failure with a real status and a JSON
        envelope, and a mid-stream failure inside a 200 carrying a top-level ``error``.
        Both classify; only the second can have emitted tokens first.

        The final chunk carries ``usage`` and ``openrouter_metadata`` — cost, served model,
        routing strategy, and the attempt number. An ``attempt`` above 1 means OpenRouter
        already retried an upstream inside this single call of ours, which is context the
        cost line needs.
        """
        raise NotImplementedError("openrouter-api: SSE translation over httpx")

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """OpenAI-shaped totals; a reported cached amount is a subset and is subtracted."""
        raise NotImplementedError("openrouter-api: usage + cost")

    def attribute(self, body: Any, status: int | None) -> Attribution:
        """Decide whether this failure is the gateway's or an upstream's.

        Runs BEFORE ``classify``. ``metadata.provider_code`` present means upstream; absent on
        a 5xx means gateway; no body at all means gateway, pessimistically.
        """
        raise NotImplementedError("openrouter-api: provider_code / error_type attribution")

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """``error.metadata.error_type`` first, status as tiebreak.

        The one branch a status-first reader always gets wrong: a 503 with no ``error_type``
        is ``UNSATISFIABLE_ROUTING`` and must not fall back.

        Allow-lists the error body on the way into diagnostics — ``reasons`` and
        ``provider_name`` in, ``flagged_input`` dropped at the parse site.
        """
        raise NotImplementedError("openrouter-api: error_type -> ErrorClass")

    # ── embedding (ADR-030) ───────────────────────────────────────────────────

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``. **The window check is not optional.**

        This is the one embedding vendor of the three that may not refuse an over-window
        input. Its documented behaviour is that a text exceeding the model's maximum "will be
        truncated **or** rejected" — the vendor states the disjunction and publishes neither a
        default nor a per-model table, and the choice belongs to whichever upstream serves the
        request. OpenAI errors and NVIDIA errors while ``truncate=NONE``; here a 200 with a
        plausible vector is a possible outcome of sending too much text.

        So the per-input length limit is enforced **here**, against the row's
        ``context_window``, and the enforcement is a rejection. Relying on the vendor to refuse
        would make ``PROVIDER_TRUNCATION_POLICY = "reject"`` a statement about a request
        parameter we do not send rather than about an outcome, and a truncated passage is
        indexed from its head with its tail unsearchable forever — no error, no metric, and a
        symptom that appears only as recall that was never there.

        Also checked: the batch ceilings (item count and summed tokens), and ``dimensions``
        against ``EMBEDDING_DIMENSIONS`` — which this gateway passes through to upstreams that
        honour it, so the flag describes the *upstream model* and not the gateway.

        ``input_type`` is not a parameter on this surface. A request carrying one takes the
        usual reject-or-warn path rather than being dropped, because it is honoured on NIM and
        a silent ignore here would read as a portable field that is not.
        """
        raise NotImplementedError

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """``POST /api/v1/embeddings``. Vectors in INPUT order, never the response order.

        OpenAI-shaped: ``{model, input, encoding_format}`` in, ``data[]`` of ``{index,
        embedding}`` out. Re-sorted on ``index`` and asserted to cover every input exactly
        once; never a partial result.

        Sends the same full ``provider`` block ``_body`` builds for chat, with
        ``allow_fallbacks: false``. That is stricter than the vendor's own recommendation and
        it is deliberate: a reroute mid-batch is the failure ``errors.NON_CHAT_FALLBACK_ELIGIBLE``
        bans us from causing, and letting the gateway cause it on our behalf is the same bug
        with the accounting hidden one layer down.

        ``EmbeddingResult.space`` carries ``provider="openrouter"`` and the namespaced upstream
        id verbatim — ``openai/text-embedding-3-small``, never ``text-embedding-3-small``. The
        two are different ``EmbeddingSpace`` values on purpose even when the same weights
        answer both: the credential, the quota and the failure attribution differ, and a
        collection reachable through two providers is one nobody can re-embed deterministically.
        """
        raise NotImplementedError("openrouter-api: POST /api/v1/embeddings, re-sorted by index")

    @staticmethod
    def _embedding_usage(raw: Any) -> Usage:
        """``prompt_tokens`` only, and no cached amount is subtracted on this surface.

        Separate from ``_usage`` for the reason the chat one records in reverse: ``_usage``
        subtracts a reported cached amount because on ``chat/completions`` it is a subset of
        the input count. This endpoint reports no cached bucket, so sharing the helper would
        subtract zero today and silently start subtracting a real number the day the gateway
        adds one — in whichever direction the author of the shared code happened to guess.

        ``usage.cost`` may also arrive here, as it does on chat. It is diagnostics, never a
        ``Usage`` bucket: every bucket in that type is a token count.
        """
        raise NotImplementedError("openrouter-api: embeddings usage.prompt_tokens")

    # ── reranking (ADR-030) ───────────────────────────────────────────────────

    def validate_rerank(
        self, req: RerankRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Enforce the passage ceiling and the pair-length limit BEFORE the call.

        Both limits belong to the **upstream** reranker rather than to the gateway, so they
        come off the ``provider_models`` row and are not portable between two rerank rows on
        one credential — the same per-model rule NIM has, arriving here for a different reason.

        The pair-length limit is the one with no error to catch: a cross-encoder truncates an
        over-long query-plus-passage pair silently, the truncated pair scores off whatever
        distribution it would otherwise have scored on, and it fires hardest on the largest
        chunks. Never emitted as a ``CapabilityWarning`` with ``action="ignored"`` — a warned
        truncation is still a truncation.

        Cheap on this vendor and worth stating: nothing here may compensate for the unknown
        score scale by normalizing, clamping or rescaling what comes back. Rescaling an
        uncharacterized score produces a characterized-looking one.
        """
        raise NotImplementedError

    async def rerank(
        self,
        req: RerankRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> RerankResult:
        """``POST /api/v1/rerank``. Scores in INPUT order, with an UNCALIBRATED scale attached.

        Cohere-shaped: ``{model, query, documents}`` in, ``results[]`` of ``{index,
        relevance_score, document}`` out. Three things to get right, each of which produces
        plausible floats when wrong:

        **Argument order.** Query first, documents second. Reversed, the endpoint returns
        entirely reasonable numbers and the symptom is a stage that "barely changes the order
        and looks pointless". Locked by an asymmetric fixture, not by review.

        **The scatter.** The response is sorted by relevance and identifies each entry by its
        original ``index``. Scatter it back so ``scores[i]`` is the relevance of
        ``passages[i]``, and assert the result covers every input exactly once. A response
        covering fewer entries than it was given is a **failure**, not a partial answer.

        **The scale.** ``RerankResult.scale`` is ``capabilities.rerank_scale(self.name)``,
        which is ``RerankScale.UNCALIBRATED`` — read from the table, never written as a
        literal, so that characterizing this vendor later is one edit in ``RERANK_SCALE`` and
        not a hunt through adapters. ``UNCALIBRATED.may_threshold`` is False, and under finding
        #47 that makes this body **unreachable rather than degraded** — by ``can_rerank``
        alone, which answers False for this provider, so nothing binds a ``Reranker`` here. The
        second reason this line used to give, that ``assert_row_coherent`` refuses the row at
        save time, is false: no write path calls it (finding J1) and the row saves.

        The scale is still read from the table rather than
        hardcoded, because the day this becomes reachable is the day that table changes, and a
        literal would survive the change silently.

        No ``top_n`` is ever sent, even though the endpoint documents one: a truncated,
        re-sorted response breaks the input alignment the return type promises, and depth is a
        retrieval decision (`kb-rag-query-contract`), not a wire parameter.

        ``RerankResult.usage`` is all zeros with ``source="estimated"``. A Cohere-shaped
        reranker bills in ``search_units``, which is not a token and has no bucket in
        ``Usage`` — see that field's note; it is a recorded contract gap and not a free call.

        Attribution runs here as it does on chat: a failure may belong to the gateway or to the
        upstream, and ``attribute`` decides which before ``classify`` runs. A failed rerank is
        an **error** and must never be recorded as a ``RerankSkipReason`` — that enum is closed
        and holds only pre-call reasons, and an outage laundered into a skip degrades ranking
        while hiding the incident.
        """
        raise NotImplementedError("openrouter-api: POST /api/v1/rerank, scattered to input order")


def _assert_conforms(adapter: OpenRouterAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter


def _assert_embeds(adapter: OpenRouterAdapter) -> EmbeddingAdapter:
    """The static half of the embedding capability gate.

    One of three in the package — ``openai_adapter`` and ``nim`` carry the others — and the
    matching cell is ``capabilities.PROVIDER_TASKS[("openrouter", EMBEDDING)]``, which cites
    the vendor's embeddings reference and the date it was read.
    """
    return adapter


def _assert_reranks(adapter: OpenRouterAdapter) -> RerankAdapter:
    """The static half of the rerank capability gate.

    One of two, with ``nim``. This adapter satisfying **three** Protocols is not a sign the
    surfaces have converged: they share a credential, a base URL and an error envelope, and
    they share nothing else — three routes, three request schemas, three response shapes, and
    a usage story that is a token count on one, absent on another, and denominated in a unit
    ``Usage`` cannot express on the third.
    """
    return adapter

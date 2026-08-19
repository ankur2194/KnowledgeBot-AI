"""NVIDIA NIM, against the **hosted API Catalog**. Skeleton; signatures are final.

``https://integrate.api.nvidia.com/v1``, an OpenAI-shaped ``/v1/chat/completions`` driven by
the ``openai`` SDK as a documented drop-in.

## The two deployment shapes, and why only one is here

NIM is the only vendor in this package that ships in two forms, and they are different
products wearing one API:

| | Hosted API Catalog (what we call) | Self-hosted NIM container (what we do not run) |
|---|---|---|
| Endpoint | ``integrate.api.nvidia.com/v1`` | ``http://<host>:8000/v1`` |
| Auth | ``Authorization: Bearer nvapi-…`` ¹ | **none on inference endpoints** ² |
| Entitlement | developer account + trial credits | free tier, or an enterprise licence |
| Cold start | none | model download plus engine load, minutes, 503 throughout |

¹ <!-- UNVERIFIED: the header is the OpenAI-SDK default and every catalog snippet uses it,
but NVIDIA publishes no normative auth page. -->
² The NGC key authenticates model *downloads* only, which is why a self-hosted container is
never exposed beyond the internal network.

The self-hosted container is documented here and deliberately not operated. Two consequences
that are rules, not preferences: if one is ever added it is an **optional** dependency, so an
open breaker on it leaves ``/health/ready`` green; and it is **never a fallback target** —
its cold start turns a 20 s first-token budget into a hard failure, so it is a primary or
nothing. It would also sit on the internal network beside Qdrant and PostgreSQL and never
behind the edge proxy, because anyone who can reach its port can spend the GPU.

## Divergences this file exists to absorb

**Per-model request schemas genuinely disagree with each other, on one connection.**
``meta/llama-3.1-8b-instruct`` caps ``max_tokens`` at 4096 and ``temperature`` at 1.0 and
defaults ``stream`` to false; ``nvidia/nemotron-3-super-120b-a12b`` allows 32768 output
tokens and defaults ``stream`` to true; ``reasoning_effort`` is enumerated ``none|low|high``
on one model and ``low|medium|high`` on another. Out-of-range is a **422, not a clamp**. So a
bot configuration that validates against one NIM model is a hard failure on the next one
served by the same credential — which is the concrete reason ``ModelCapabilities`` is
per-model and ``stream`` is never defaulted.

**202 means queued, and the SDK has no branch for it.** Every hosted chat endpoint documents
``202 — Result is pending. Client should poll using the requestId``, resolved by
``GET /v1/status/{requestId}``. The OpenAI SDK cannot poll, so the request returns 200-shaped
success, the SSE body never arrives, and the turn hangs until the whole provider budget
expires. Check ``raw.status_code`` BEFORE parsing, classify 202 as ``provider_temporary``,
and let fallback take it — nothing was generated. Do not build a poller: polling inside a
45 s streaming budget only relocates the hang.

**Usage is null on every chunk unless ``stream_options={"include_usage": True}`` is sent**,
including the last one — so every billing row reads ``source="estimated"`` and cost reports
under-report by 100%. The same option creates the second half of the trap: the usage-bearing
chunk arrives with ``choices: []``, so ``chunk.choices[0]`` raises ``IndexError`` on exactly
the chunk that carries the number the option was added for.

**No usage detail beyond the two totals.** The catalog reports ``prompt_tokens`` and
``completion_tokens`` and nothing else: no cached amount, no reasoning amount. So
``cache_read_tokens``, ``cache_write_tokens`` and ``reasoning_tokens`` are all 0 here, and
``PROMPT_CACHING`` is off — not because caching is absent, but because it is unobservable,
and a bucket we cannot measure must read zero rather than be guessed at.

**``nvext`` was removed in NIM LLM 2.0.** The 1.x extension object that carried guided
decoding is gone and the hosted catalog documents no ``response_format`` at all — and
unknown top-level fields are **accepted and ignored**, so a schema sent anyway returns 200
and confident prose. Never set ``STRUCTURED_OUTPUT`` or ``JSON_MODE`` for a NIM model
without a recorded fixture proving enforcement. (``kb-provider-adapter-contract`` still
carries a line saying NIM prefers ``nvext.guided_json``; that was true for 1.x and is now
wrong — flagged there, not silently followed here.)

**``budget_tokens`` is ENFORCED here, uniquely.** NIM's ``reasoning_budget`` is a real
control (``-1`` disables), where every other vendor treats our ``budget_tokens`` as advisory
or rejects the shape outright. It maps, and the mapping is recorded in
``Diagnostics.extras``. It does **not** get a new ``Capability`` member — one vendor's
enforcement of an existing portable field is not a new capability.

**``provider_request_id`` is null unless we supply it.** NIM adopts an ``X-Request-Id`` we
send and forwards it as the backend request id, but it never synthesizes one — so sending it
is mandatory rather than optional, or NVIDIA support has nothing to look up on any row. The
container also forwards ``traceparent``, so the single-trace requirement survives the hop.

**No ``CONTEXT_EXCEEDED`` path exists.** An over-long prompt is rejected up front as a 422
and never stops generation mid-stream, so ``length`` is unambiguously OUR cap.

**TTFT lies by default.** The first streamed chunk is an empty role delta
(``{"role":"assistant","content":""}``) and the stream terminates with ``data: [DONE]``, not
with a usage-bearing final chunk. Time TTFT from the first delta with non-empty content.

**No published rate-limit header schema and no documented 429 body.**
``Diagnostics.rate_limit`` stays empty.
<!-- UNVERIFIED: NVIDIA publishes no rate-limit or 429-header schema for the catalog. -->

**Pinned ids rot on no published schedule.** The free tier publishes models within about 72
hours of upstream availability and retires them without notice, while older ids stay listed
and unmaintained. Re-list ``/v1/models`` on every connection test, store the result on the
connection row, and surface a stale-model warning in the admin panel — a 404 discovered in a
user's chat is the failure this avoids.

## Stream event -> internal event

| Chunk shape | Internal |
|---|---|
| HTTP 202 + ``requestId`` | never parsed; ``provider_temporary`` before any SSE reading |
| first chunk, empty role delta | nothing — it starts no timer and yields no event |
| ``choices[0].delta.content`` | ``Delta(kind="text")`` |
| ``choices[0].delta.reasoning_content`` | ``Delta(kind="reasoning")``, where present ³ |
| ``choices[0].delta.tool_calls[].function.arguments`` | ``Delta(kind="tool_args")`` |
| ``choices[0].finish_reason`` | mapped via ``STOP`` |
| chunk with ``usage`` and ``choices == []`` | usage; ``choices[0]`` here is the crash |
| ``data: [DONE]`` | end of stream; terminal ``ChatResult`` |

³ <!-- UNVERIFIED: the field name on reasoning-capable catalog models was not re-checked. -->

## Usage normalization

Two totals, and honest zeros for everything else::

    Usage.input_tokens       = usage.prompt_tokens        # no cached amount is reported
    Usage.cache_read_tokens  = 0
    Usage.cache_write_tokens = 0
    Usage.output_tokens      = usage.completion_tokens
    Usage.reasoning_tokens   = 0                          # not broken out by the catalog

An absent ``usage`` object stays ``source="estimated"`` and is never aggregated into invoiced
cost.

## The ranking surface — the reason stage 11 is capability-gated at all

**Two of the five vendors rerank — NIM and OpenRouter — and only NIM's scores may ever be cut
against.** ADR-030 moved reranking off a local cross-encoder that always worked, which is why
``app/rag/rerank.py`` exists in the shape it does: a closed ``RerankSkipReason``, a
``rerank_gate`` that runs before any call, and a stage that serves the fused order rather than
failing when the capability is absent (`bge-reranker` finding C1, `nvidia-nim-api`
non-negotiable 1). Three vendors still cannot rerank at all, so the gate is not vestigial.

The two that can are asymmetric in the way that matters downstream, and finding #47 has since
made the asymmetry decisive rather than merely awkward. This adapter's scores are a ``LOGIT``
recorded in ``capabilities.RERANK_SCALE``, so a calibrated threshold is derivable from them.
OpenRouter's route fronts several upstream cross-encoders on one credential with no documented
normalization between them, so it is deliberately absent from that table and its scores are
``UNCALIBRATED``.

This paragraph used to end *"a bot on OpenRouter reranks without ever reaching stage 12's
cut"*, and **that mode does not exist.** Stage 11 takes a ``RerankCalibration`` as a required
argument, ``RerankCalibration`` refuses to be constructed on an unthresholdable scale, and
stage 12's only calibration-free route (``evidence.select_unranked``) selects on branch
agreement and never calls a reranker. So an uncharacterized scale is not a reduced capability;
``capabilities.can_rerank`` answers ``False`` for OpenRouter — which is the whole of it.
``assert_row_coherent`` would refuse the row, and this sentence used to add "at save time"; no
write path calls it (finding J1), so the row saves and simply never reranks.
**NIM is, in practice, the only rerank-eligible provider**, which
is what the skills' original half-sentence meant and got right for the wrong reason: not
because OpenRouter lacks the endpoint, but because a gateway's score scale cannot be
characterized per provider.

Three divergences, and the first is the one that moves numbers silently:

**The score scale is an UNBOUNDED SIGNED LOGIT.** NVIDIA's own example ranks ``0.226``,
``-1.17``, ``-1.52``. The retired cross-encoder returned a sigmoid in [0, 1] and
``evidence.min_score = 0.30`` was calibrated on that; ``0.30`` is a valid float on both scales
and nothing raises when they are swapped — only the refusal rate moves, and only in aggregate,
months later. So ``RerankResult.scale`` is filled from
``capabilities.RERANK_SCALE["nvidia_nim"]`` and is ``RerankScale.LOGIT``.

``LOGIT`` **is** thresholdable — this sentence said "not" for a while, and it is the same
one-word defect that once made ``may_threshold`` an alias of ``is_bounded`` and disabled the
evidence gate platform-wide. A threshold is a percentile read off a *measured* distribution,
so it needs an ordering and not a range, and the retired local cross-encoder derived one on a
raw logit. What is missing is a *measurement*, not a permission: ``app/rag/rerank.py``'s
``CALIBRATIONS`` table is empty on purpose, so ``calibration_for("nvidia_nim", …)`` raises
``RerankNotCalibrated`` until an evaluation run fills it. That is one run away, which is
exactly what makes NIM different from OpenRouter, whose scale cannot be measured per provider
at all (finding #47).

**The ranking request schema is unrelated to the chat one and is not the OpenAI shape.** It
takes a query object and a list of passages, caps the passages accepted per request, and caps
the tokens per query-plus-passage pair. A candidate depth above the passage cap becomes several
sequential round-trips unless they are issued concurrently, which is a property of this adapter
and not of the stage — an unbatched loop over 25 candidates is 25 round-trips inside a 1.5 s
retrieval leg.

**The pair-length limit truncates silently, by documented default.** That is the one with no
error to catch: a truncated pair scores off the distribution the threshold was calibrated on,
it fires hardest on the largest and most information-dense chunks, and the only symptom is a
refusal rate that moved in aggregate. ``validate_rerank`` enforces it before the call rather
than trusting the vendor to reject.

**Chat and ranking must fail independently.** A ranking outage degrades ranking for every org
configured against NIM while chat continues on a fallback — and cross-provider fallback is
banned outright on this surface (``errors.NON_CHAT_FALLBACK_ELIGIBLE``) because a different
provider's scale silently changes what the evidence threshold means.

<!-- UNVERIFIED: the ranking endpoint path, the exact request field names, the per-request
passage cap and the per-pair token ceiling are not stated in nvidia-nim-api/SKILL.md, which
sources the endpoint's existence and its logit scale and nothing further. They are read from
the model's own catalog page at implementation time and pinned per model row, exactly as the
chat schemas are — the ceilings genuinely disagree between ranking models the same way
``max_tokens`` does between chat models. -->
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import Any, Final

from pydantic import SecretStr

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

__all__ = ["NimAdapter"]

BASE_URL: Final[str] = "https://integrate.api.nvidia.com/v1"

#: Verified against docs.api.nvidia.com/nim/reference/llm-apis on 2026-08-04, where each
#: model publishes its own body schema. The per-model ceilings live on
#: ``provider_models.capability_flags`` rows, not here — the note beside each id records why
#: two rows on one connection cannot share a validator.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "meta/llama-3.1-8b-instruct",  # max_tokens <= 4096, temperature <= 1.0, stream default false
    "nvidia/nemotron-3-super-120b-a12b",  # max_tokens <= 32768, stream default true
)

#: The ranking surface. A different endpoint, a different request schema, a different score
#: scale, and rows that are **task-exclusive**: a ranking row never also carries ``TEXT``,
#: ``TOOL_USE`` or ``REASONING``, and ``capabilities.assert_row_coherent`` rejects one that
#: does. Documentation and connection-save validation only.
#:
#: Catalog churn applies here exactly as it does to the chat ids — the free tier retires model
#: ids on no published schedule while leaving them listed — so the connection test re-lists
#: ``/v1/models`` and surfaces a stale-model warning rather than letting a 404 arrive on the
#: retrieval path, where it would be an error and not the skip stage 11 knows how to take.
#:
#: Both ids were read off the catalog's "Retrieval" family on **2026-08-07**
#: (docs.api.nvidia.com/nim/reference), where each is published as "Rank passages by their
#: relation to a query" — so the previous marker here, which said these were "recalled, not
#: read", is resolved for the ids. The family lists several more (``rerank-qa-mistral-4b``,
#: ``llama-3.2-nemoretriever-500m-rerank-v2``, ``llama-nemotron-rerank-1b-v2`` and a VL
#: variant); only these two are pinned, because a pin is a commitment to a request schema and
#: not a catalogue mirror.
#:
#: <!-- UNVERIFIED: the unbounded-logit SCORE SCALE is still sourced only to
#: nvidia-nim-api/SKILL.md and bge-reranker, not to a response read from either of these two
#: ids. The open question is narrow and it is the one that decides whether stage 12 may cut:
#: does `nv-rerankqa-mistral-4b-v3` return the same scale as `llama-3.2-nv-rerankqa-1b-v2`?
#: `capabilities.RERANK_SCALE` is keyed per PROVIDER, so if the two disagree the key is wrong,
#: not the value. A recorded fixture per id closes it; this is what remains of docs/23
#: row 157. -->
RERANK_MODELS: Final[tuple[str, ...]] = (
    "nvidia/nv-rerankqa-mistral-4b-v3",
    "nvidia/llama-3.2-nv-rerankqa-1b-v2",
)

#: The embedding surface, ``POST {BASE_URL}/embeddings``. Read off the same "Retrieval" family
#: on 2026-08-07, where the reference page for the first id is titled "Creates an embedding
#: vector from the input text" and publishes the body schema quoted in ``validate_embedding``.
#:
#: Task-exclusive like the ranking rows: an embedding row never carries ``TEXT``, ``TOOL_USE``
#: or ``REASONING``, and never carries ``RERANK`` either — they are different products on
#: different routes, and ``capabilities.assert_row_coherent`` rejects a row claiming both.
#:
#: One pin, deliberately. The family is large (``baai/bge-m3``, ``nvidia/nv-embed-v1``,
#: ``snowflake/arctic-embed-l``, the ``llama-3.2-nemoretriever`` line) and every one of them is
#: a **different vector space**, so breadth here is not a convenience — each additional id is a
#: collection that has to be built, verified and re-embedded from scratch. Width matters for a
#: second reason: ``nv-embedqa-e5-v5`` is 1024-wide, which is the same width as ``baai/bge-m3``
#: and as ``text-embedding-3-large`` truncated to 1024, and Qdrant accepts all three into one
#: collection without complaint. That coincidence is why ``EmbeddingSpace`` is keyed on the
#: model id and not on the width.
EMBED_MODELS: Final[tuple[str, ...]] = ("nvidia/nv-embedqa-e5-v5",)

#: vLLM's vocabulary. There is no context-window value: an over-long prompt is a 422 up
#: front. Anything absent maps to ``ERROR`` with ``native_stop_reason`` preserved.
STOP: Final[dict[str, StopReason]] = {
    "stop": StopReason.COMPLETE,
    "length": StopReason.MAX_OUTPUT,
    "tool_calls": StopReason.TOOL_USE,
    "content_filter": StopReason.REFUSAL,
}

#: NIM evidence -> class. Never ``INTERNAL_DEPENDENCY``: that class pages, and NIM is
#: external.
#:
#: 404 on a previously valid id is ``PROVIDER_PERMANENT_REQUEST`` and does **not** fall back
#: (ADR-014). Catalog churn looks like a lifecycle event, but the id came from the bot's own
#: configuration snapshot, so falling back would answer from a model the tenant never chose
#: on a bot that still reads ``Ready``. The remedy is the stale-model warning on the
#: connection, raised at connection-test time, not at chat time.
STATUS_TO_CLASS: Final[dict[int, ErrorClass]] = {
    #: Queued, nothing generated. Fallback-eligible.
    202: ErrorClass.PROVIDER_TEMPORARY,
    401: ErrorClass.PROVIDER_AUTH,
    403: ErrorClass.PROVIDER_AUTH,
    404: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    422: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    429: ErrorClass.PROVIDER_RATE_LIMIT,
    500: ErrorClass.PROVIDER_TEMPORARY,
    #: Model loading, or scaled to zero. This is the capacity signal §8.7 actually means.
    503: ErrorClass.PROVIDER_TEMPORARY,
}


class NimAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags expected on a catalog row: ``TEXT``, ``TOOL_USE`` and ``SAMPLING``
    (within the model's own range — 0.0 to 1.0 on the catalog, not OpenAI's 2.0),
    ``STREAM_USAGE``, and ``REASONING`` on the models that publish ``reasoning_effort``. OFF
    everywhere until a recorded fixture proves otherwise: ``STRUCTURED_OUTPUT`` and
    ``JSON_MODE`` (no surface, and unknown fields are ignored rather than rejected),
    ``PROMPT_CACHING`` (unobservable), ``EARLY_INPUT_USAGE``, ``IMAGE_INPUT``.
    """

    name = "nvidia_nim"
    min_useful_seconds = 5.0

    def _client(self, credential: SecretStr, req: ChatRequest) -> Any:
        """``AsyncOpenAI(base_url=BASE_URL, max_retries=0, timeout=...)``.

        The ``max_retries=0`` line is easy to remember for the OpenAI adapter and easy to
        forget here, because this is "the NVIDIA provider" — and it is the same SDK with the
        same default of two invisible retries.

        The ``nvapi-`` credential is broader than a per-project provider key: the same
        credential shape pulls container images and model weights, so a leak exceeds one
        org's token bill. Masked as ``nvapi-…4a91`` anywhere it is referenced at all.
        """
        raise NotImplementedError("nvidia-nim-api: AsyncOpenAI(base_url=BASE_URL, max_retries=0)")

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the chat-completions body.

        Always: explicit ``stream`` (never defaulted — the vendor default differs per model),
        ``stream_options={"include_usage": True}``, and ``max_tokens`` already validated
        against this model's own ceiling.

        NIM documents a ``context`` message role. We do not use it: its handling is per-model
        and undocumented, and retrieved evidence is packed into the user turn by the query
        contract.
        """
        raise NotImplementedError("nvidia-nim-api: chat.completions body")

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Range checks run HERE, not in flight, because out-of-range is a 422.

        At minimum: ``temperature`` outside 0.0–1.0, ``max_output_tokens`` above the row's
        ceiling, and any ``response_schema`` at all while ``STRUCTURED_OUTPUT`` is off.
        """
        raise NotImplementedError

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the chat-completions stream.

        Uses the raw-response form so ``status_code`` is readable before parsing: a 202 must
        be classified before a single SSE line is read. Sends ``X-Request-Id`` from
        ``req.trace_id`` on every attempt, including retries — it is the only way this vendor
        has a request id at all.
        """
        raise NotImplementedError("nvidia-nim-api: chat.completions stream translation")

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """Two totals; the other three buckets stay zero rather than being inferred."""
        raise NotImplementedError("nvidia-nim-api: prompt_tokens / completion_tokens")

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """Status -> class via ``STATUS_TO_CLASS``.

        Two shapes with no HTTP status to read, both ``PROVIDER_TEMPORARY``: a transport-level
        connection refusal (self-hosted only — GPU exhaustion kills the container at boot and
        there is no error body to classify), and no first token inside the budget.

        Credit exhaustion has no documented status or body, so it currently lands wherever its
        status lands. If it turns out to be distinguishable it belongs in ``PROVIDER_BILLING``
        with the other never-self-healing account states.
        <!-- UNVERIFIED: NVIDIA documents no status or body for credit exhaustion. -->

        Shared with the ranking surface: same credential, same account, same status list, so
        ``STATUS_TO_CLASS`` is read the same way and 202/503 stay ``PROVIDER_TEMPORARY``. Two
        differences the caller must respect and this method cannot express:

        * ``tokens_emitted`` is always 0 from ``rerank`` — nothing streams — so the "once a
          token is out we are billed" gate never fires and a retry against the SAME connection
          is legitimate under ``RETRYABLE``.
        * Cross-provider fallback is banned regardless of class
          (``errors.NON_CHAT_FALLBACK_ELIGIBLE``). A failed rerank is an **error** and must
          never be recorded as a ``RerankSkipReason``: the skip enum is closed and contains
          only pre-call reasons, and an outage laundered into a skip degrades ranking *and*
          hides the incident.
        """
        raise NotImplementedError("nvidia-nim-api: status -> ErrorClass")

    # ── reranking (ADR-030) ───────────────────────────────────────────────────

    def validate_rerank(
        self, req: RerankRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Enforce the per-model passage cap and the pair-length limit BEFORE the call.

        Range checks belong here for the same reason the chat ones do — out of range is a 422
        on this vendor, not a clamp — plus one reason unique to this surface: the pair-length
        limit is enforced by **silent truncation**, so it is the one check with no error to
        catch. A truncated query-plus-passage pair scores off the distribution the threshold
        was calibrated on, it fires hardest on the largest chunks, and the only symptom is a
        refusal rate that moved months later.

        At minimum: ``len(req.passages)`` against the model row's per-request cap, and each
        query-plus-passage pair against the row's ``context_window``. Both are per-model on
        this vendor and neither is portable between two ranking rows on one connection.

        Never emits a ``CapabilityWarning`` with ``action="ignored"`` for an over-long passage.
        The only correct actions are to reject it or to have sized the chunk correctly; a
        warned truncation is still a truncation, and ``bge-reranker`` is explicit that latency
        is bought by cutting candidate depth and never passage length.
        """
        raise NotImplementedError

    async def rerank(
        self,
        req: RerankRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> RerankResult:
        """Score every passage against the query. Scores in INPUT order, scale attached.

        Three things this must get right, each of which produces plausible floats when wrong:

        **Argument order.** Query first, passages second. Ranking APIs are order-sensitive and
        return perfectly reasonable numbers when reversed; the symptom is a stage that "barely
        changes the order and looks pointless". Locked by an asymmetric fixture, not by review.

        **The scatter.** The response is sorted by relevance and identifies each entry by its
        original position. Scatter it back so ``scores[i]`` is the relevance of
        ``passages[i]``, and assert the result covers every input exactly once. Reading the
        list positionally attaches sensible scores to the wrong passages. A response covering
        fewer entries than it was given is a **failure**, not a partial answer: silently
        dropped passages become evidence that vanished, and the answer is then grounded in a
        subset nobody chose.

        **The scale.** ``RerankResult.scale`` is ``capabilities.rerank_scale(self.name)``, i.e.
        ``RerankScale.LOGIT``, and it is never omitted or defaulted. It is what lets stage 12
        assert instead of assume. ``LOGIT.may_threshold`` is **True** — an unbounded scale is
        still a thresholdable one, since a threshold is a percentile read off a measured
        distribution and not a fraction of a range. What stops these scores being cut against
        today is the calibration gate, not the scale: ``CALIBRATIONS`` is empty on purpose, so
        ``calibration_for`` raises ``RerankNotCalibrated`` until an evaluation run over the
        golden corpus produces a threshold for this exact ``(provider, model)`` pair.

        ``RerankResult.usage`` is all zeros with ``source="estimated"``: the ranking response
        carries no usage object at all, so nothing here may be aggregated into invoiced cost.
        That is a contract gap and it is recorded as one — see ``RerankResult.usage``.

        No ``top_n`` is ever sent, even though the endpoint offers one: a truncated, re-sorted
        response breaks the input alignment the return type promises, and depth is a retrieval
        decision (`kb-rag-query-contract`), not a wire parameter.

        Raises ``ProviderCallFailed``. Never returns a sentinel score for a passage it could
        not score — an empty passage scored against a query returns a real number that ranks a
        missing passage against present ones.
        """
        raise NotImplementedError("nvidia-nim-api: ranking endpoint, scattered to input order")

    # ── embedding (ADR-030) ───────────────────────────────────────────────────

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``, against a schema NVIDIA publishes.

        Four checks, and the first two are unique to this vendor among the three that embed:

        * **``input_type`` is required and is never inferred.** The catalog schema enumerates
          ``passage | query`` and says in its own description that failing to use the correct
          one "will result in large drops in retrieval accuracy". Nothing errors when it is
          wrong — recall simply falls, uniformly, for the life of the index — so a row on this
          vendor carries ``Capability.EMBEDDING_INPUT_TYPE`` and a request that omits
          ``req.input_type`` is a rejection here rather than a default applied downward.
          ``EmbeddingInputType`` records why only the caller can know which side it is on.
        * **``truncate`` is sent as ``"NONE"`` and is never sent as anything else.** The
          parameter's documented values are ``NONE | START | END``; ``NONE`` returns an error
          when the input exceeds the model's maximum, and the other two discard text until it
          fits. ``NONE`` is also the vendor's default, so this is a pin against a default
          moving rather than an override — and the pin is what makes
          ``PROVIDER_TRUNCATION_POLICY = "reject"`` true on this vendor instead of merely
          hoped for. An adapter that let ``END`` through would index a passage from its head
          and leave its tail unsearchable forever, with a 200 and a plausible vector.
        * The batch ceiling: the ``input`` array is capped at **4096 items**. Item count and
          summed tokens are different limits and both are checked.
        * The per-input length limit, **8192 tokens**, against the row's ``context_window``.
          A hard rejection, never a truncation — see the previous bullet for what the
          alternative silently costs.

        ``dimensions`` is not a parameter on this endpoint at all. A request carrying one on a
        row without ``EMBEDDING_DIMENSIONS`` follows the usual reject-or-warn path; it must
        never be forwarded, because this vendor ignores unknown body fields rather than
        rejecting them, and an ignored ``dimensions`` produces vectors of the wrong width that
        are discovered at upsert — after the whole batch has been paid for.
        """
        raise NotImplementedError

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """``POST /v1/embeddings``. Vectors in INPUT order, never the vendor's response order.

        Re-sorts the response on each entry's ``index`` and asserts the result covers every
        input exactly once. Never returns a partial result: a batch that half succeeded would
        write half a source version's vectors and the version would publish as complete.

        ``EmbeddingResult.space`` is filled from ``app.retrieval.collection.EmbeddingSpace``
        with the width **actually returned** — 1024 on ``nv-embedqa-e5-v5``, which is also the
        width of ``baai/bge-m3`` and of a truncated ``text-embedding-3-large``, so the width is
        never the identity — and ``normalized`` from what the response actually contains rather
        than from the documentation.

        The 202 is this endpoint's alone among the three embedders and it must be classified
        before the body is parsed: the catalog's Retrieval family publishes a companion GET
        that "gets the result of an earlier function invocation request that returned a status
        of 202", so a 202 is a queued call with no vectors in it. It maps to
        ``PROVIDER_TEMPORARY`` through ``STATUS_TO_CLASS`` like every other queued response
        here, and it may retry the SAME connection; what it may never do is fall back to
        another vendor, because a different vendor is a different vector space
        (``errors.NON_CHAT_FALLBACK_ELIGIBLE``).
        """
        raise NotImplementedError("nvidia-nim-api: POST /v1/embeddings, re-sorted by index")

    @staticmethod
    def _embedding_usage(raw: Any) -> Usage:
        """``prompt_tokens`` is the entire cost. There is no cached bucket to subtract.

        Separate from ``_usage`` on purpose, even though both read the same two field names
        off this vendor. The chat response's ``completion_tokens`` is meaningless here —
        nothing is generated — and the day NVIDIA adds a cached amount to one surface and not
        the other, a shared helper would apply the wrong arithmetic to whichever it was not
        written for::

            Usage.input_tokens       = usage.prompt_tokens
            Usage.cache_read_tokens  = 0     # not reported on this endpoint
            Usage.cache_write_tokens = 0
            Usage.output_tokens      = 0     # nothing is generated
            Usage.reasoning_tokens   = 0
            Usage.source             = "provider_final"

        The response also carries ``total_tokens``. It is deliberately not read: it is a
        derived sum, and taking it would let a vendor-side change to what the sum includes
        become a billing change here with nothing to compare against. Billing reads
        ``total_input_tokens``.
        """
        raise NotImplementedError("nvidia-nim-api: embeddings usage.prompt_tokens")


def _assert_conforms(adapter: NimAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter


def _assert_reranks(adapter: NimAdapter) -> RerankAdapter:
    """The static half of the rerank capability gate.

    This function existing **is** the claim that NIM reranks, and it is the claim mypy can
    check. The queryable half is ``capabilities.PROVIDER_TASKS[("nvidia_nim", RERANK)]``, and
    ``tests/unit/test_provider_capability_matrix.py`` asserts the two agree in both directions.

    One of two such claims in the package: ``OpenRouterAdapter`` carries the other. The two
    are not interchangeable and the difference is the score scale, not the route — NIM's is a
    characterized ``LOGIT`` in ``capabilities.RERANK_SCALE`` and OpenRouter's is deliberately
    absent from it, so only one of the two can ever feed an evidence threshold.
    """
    return adapter


def _assert_embeds(adapter: NimAdapter) -> EmbeddingAdapter:
    """The static half of the embedding capability gate.

    This module is the only one in the package that carries **both** non-chat gates, which is
    the concrete reason ``assert_row_coherent`` refuses a row claiming two task families: one
    connection here reaches an embedding endpoint and a ranking endpoint that share nothing
    but a credential — different routes, different request schemas, different response shapes,
    and a score on one and a vector on the other.

    This function did not exist while the matrix recorded NIM's embedding cell as
    ``UNVERIFIED``, and the cell was ``UNVERIFIED`` only because nobody had read the catalog.
    ``bge-reranker`` titles its official-docs entry "NVIDIA NIM retrieval / ranking models" and
    'retrieval' is NVIDIA's own word for this family — the hint was right there and a link
    title was correctly refused as a source. The catalog reference page now cited in
    ``capabilities.PROVIDER_TASKS`` is one, and the method and the cell moved together.
    """
    return adapter

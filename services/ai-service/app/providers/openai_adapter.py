"""OpenAI, through the **Responses** API. Skeleton; signatures are final.

The filename is not ``openai.py`` on purpose: inside this package, that name shadows the
``openai`` SDK for every module that imports it, and the failure is an ``ImportError`` from
a module that looks correct.

SDK ``openai==2.53.0``, endpoint ``POST /v1/responses``. Chat Completions is not used —
every new capability lands on Responses first, and OpenAI measures materially better cache
utilisation there. Chat Completions is only the shape found in stale blog posts, and its
parameter nesting differs enough to 400 if copied in.

## Divergences this file exists to absorb

**``cached_tokens`` is a SUBSET of ``input_tokens``.** OpenAI's own wording: it is "part of
the total input_tokens count". Our buckets are disjoint, so the adapter SUBTRACTS. This is
the opposite of Anthropic, where the same normalization step is a verbatim assignment, and
getting the direction wrong on a 200k-token cached document is a five-figure reporting error
in whichever direction was guessed.

**Cache writes are unreportable.** A write bills at 1.25x uncached input and the API returns
no write amount at all — only reads. ``cache_write_tokens`` stays 0 and the estimate
under-reports the first request against each new prefix. Reconcile against the billing
export; never synthesize a write amount.

**``max_output_tokens`` is a cap on visible output PLUS reasoning tokens, and reasoning is
generated first.** At high effort a small cap is consumed entirely by invisible thinking:
empty answer, ``status: "incomplete"``, full bill. A ``MAX_OUTPUT`` stop with zero text is
an error, not an answer.

**Truncation hides in ``status``.** ``incomplete_details`` has been observed arriving empty
while the output is genuinely truncated, so ``status: "incomplete"`` maps to ``MAX_OUTPUT``
by default and only a ``content_filter`` reason redirects it to ``REFUSAL``.

**Refusals are HTTP 200.** A ``refusal`` content part inside ``response.output``, or
``incomplete`` with ``reason: "content_filter"``. Classifying on status alone marks both
COMPLETE, and a router seeing empty text falls back and re-asks the banned question.

**Sampling is a hard 400 on the reasoning line**, not an ignore, so it looks like an outage
on the fallback dashboard rather than a configuration error. Gate on ``Capability.SAMPLING``.

**``store`` defaults to TRUE.** Left alone, the tenant's retrieved document text and the end
user's question are retained on OpenAI's servers for 30 days and readable from the org
dashboard — tenant content crossing a boundary the org's privacy switches never authorised.
Every request sends ``store=False``, which also forecloses ``previous_response_id`` and
``conversation``: PostgreSQL is the source of truth for conversation state, not OpenAI.

**``truncation`` defaults to dropping items from the START of ``input``** — exactly where
the retrieved evidence sits. The model then answers from a context the citation map no
longer describes, and citations point at text that was never sent. Always ``"disabled"``.

**``x-request-id`` is only reachable from the stream object's ``.response.headers``**, and
only if captured before iterating. On an error it is ``exc.request_id``. Capture it
immediately after ``create()`` returns, with the rate-limit headers, or it is missing on
precisely the failures support asks about.

## Stream event -> internal event

| Responses event | Internal |
|---|---|
| ``response.output_text.delta`` | ``Delta(kind="text")`` — see note below |
| ``response.reasoning_summary_text.delta`` | ``Delta(kind="reasoning")``, if trace is on |
| ``response.refusal.delta`` | ``Delta(kind="refusal")`` |
| ``response.function_call_arguments.delta`` | ``Delta(kind="tool_args")`` ¹ |
| ``response.completed`` / ``.incomplete`` / ``.failed`` | terminal ``ChatResult`` |
| SDK exception, or cancellation | terminal ``ChatResult`` + ``error_class`` |

Empty text deltas are common at the head of a stream, so TTFT starts at the first NON-empty
one; usage and the stop reason are read from the terminal event and nowhere else.

¹ <!-- UNVERIFIED: event name not re-checked against the 2.53 event reference. -->

## Usage normalization

``input_tokens`` here **includes** the cached prefix, so::

    Usage.input_tokens      = usage.input_tokens - input_tokens_details.cached_tokens
    Usage.cache_read_tokens = input_tokens_details.cached_tokens
    Usage.cache_write_tokens = 0                      # never reported
    Usage.output_tokens     = usage.output_tokens     # reasoning is already inside this
    Usage.reasoning_tokens  = output_tokens_details.reasoning_tokens

A fixture test asserts ``input_tokens + cache_read_tokens`` equals the vendor's own
``usage.input_tokens``.

## The embedding surface — a different endpoint, not a different parameter

``POST /v1/embeddings``, present in OpenAI's own published ``openapi.yaml``. **Three of the
five vendors embed** — OpenAI, NVIDIA NIM and OpenRouter — so this adapter is one of three
implementations of ``EmbeddingAdapter`` and not the sole one; this paragraph said "the only
one" while four cells in ``capabilities.PROVIDER_TASKS`` were ``UNVERIFIED``, and two of those
four turned out to be endpoints nobody had read the documentation for.

The three are not interchangeable and none of the differences is stylistic. ``input_type`` is
required on NIM and does not exist here. ``dimensions`` is this vendor's alone. And an
over-window input errors here and on NIM (which pins ``truncate=NONE``) while OpenRouter
documents that it "will be truncated **or** rejected" with no stated default — so the
window check below is a backstop on this vendor and the primary enforcement on that one.

**Not of ``EmbedCallable``, and the distinction is the seam rather than a nicety.**
``EmbeddingAdapter.embed(req, caps, credential)`` is what this class implements: it takes an
``EmbeddingRequest`` carrying ``org_id``, ``provider_connection_id`` and ``input_type``, plus
the decrypted key as a per-call argument. ``app.ingestion.embedding.embedder.EmbedCallable`` is
``__call__(texts, *, model)`` — no credential, no org, no connection — and neither signature
satisfies the other. That is deliberate: **something binds the organization's connection and
its credential to this method and hands ingestion the narrowed callable**, which is exactly why
ingestion cannot hold a secret, log one, or serialize one into a Celery payload. A comment
claiming these are one type tells the next reader the binding does not exist.

Which connection gets bound is finding C1, and it is now answered in code by
``app/providers/embedding_selection.py``: ``resolve_embedding_connection`` picks one
``(connection_id, provider, model)`` for an organization, deterministically, and refuses when
there is none. The refusal is narrower than it was: the organizations that cannot ingest are
the ones holding **only Anthropic or only DeepSeek**, the two vendors with no embedding
endpoint at all, rather than everyone who is not on OpenAI (``docs/23`` row 158). What that
module deliberately does **not** decide is where the designation is stored and whether a second
connection may be added purely to embed; those are control-plane policy and are stated as
options there.

Four divergences that are not shared with the chat surface:

**The response may arrive out of order.** OpenAI documents an ``index`` on every data entry
for exactly this reason. ``EmbeddingResult.vectors[i]`` is contractually the embedding of
``texts[i]``, so the adapter re-sorts on ``index`` and asserts the result covers every input
exactly once. Reading the list positionally produces a fully populated, fully wrong index that
raises nothing — the same shape as the rerank scatter bug one surface over.

**``dimensions`` truncates the vector (Matryoshka) and returns 200.** Whether truncation
degrades gracefully depends on whether *that* model was trained with an MRL objective; it is a
per-model property. Either way the same model at two widths is **two ``EmbeddingSpace``s** and
therefore two collections, which is why ``dimensions`` is gated on
``Capability.EMBEDDING_DIMENSIONS`` and the width is read off the response rather than
predicted from the id.

**Over-window input is an error, and we want it to stay one.** ``text-embedding-3-large``
accepts 8192 tokens and errors above it, which is the good case and the one
``PROVIDER_TRUNCATION_POLICY = "reject"`` exists to preserve. Never send a truncation option;
a request that silently fits is a chunk indexed from its head whose tail is unsearchable
forever, reported as success.

**No cache, no reasoning, no stream.** ``cache_read_tokens``, ``cache_write_tokens``,
``reasoning_tokens`` and ``output_tokens`` are all 0 and stay 0 rather than being inferred;
``usage.prompt_tokens`` is the entire cost. There is no cached bucket on this endpoint, so the
subtraction the chat surface performs has nothing to subtract — copying ``_usage`` across is
the obvious and wrong move.

Model ids on this surface are **aliases with no dated snapshot**, which is the whole of finding
C3: nothing here can detect a re-trained model behind a stable id, and
``embedder.canary_digest`` is the only detector that exists.
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
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import ProviderCallFailed

__all__ = ["OpenAIAdapter"]

#: Explicit ids, verified against developers.openai.com/api/docs/models on 2026-08-04.
#: ``gpt-5.6`` is an alias that floats to ``gpt-5.6-sol`` and there are no dated snapshots
#: on this line, so the alias is never pinned: an alias reassignment moves answers, price
#: and capability flags with no diff here. The served id is read back off ``response.model``
#: and recorded as ``gen_ai.response.model``.
#:
#: Documentation only. Capabilities come from ``provider_models.capability_flags`` — this
#: tuple exists so a connection save can reject a typo, not so code can infer behaviour.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "gpt-5.6-sol",
    "gpt-5.6-terra",
    "gpt-5.6-luna",
)

#: The embedding surface, pinned separately because it is a different endpoint and a different
#: product line. Rows carrying these ids are **task-exclusive**: they never also carry
#: ``TEXT``, ``TOOL_USE`` or ``REASONING``, and ``capabilities.assert_row_coherent`` rejects a
#: row that claims both (`contract.Capability`).
#:
#: Documentation and connection-save validation only, as with ``PINNED_MODELS``. Two
#: differences from the chat line that matter here:
#:
#: * There is **no dated snapshot to pin to** on any of these, so the id is an alias by
#:   construction and finding C3 applies in full. ``embedder.canary_digest`` is the detector.
#: * The **width is not implied by the id**: ``dimensions`` truncates, so the served width is
#:   read off the response and stored on the ``provider_models`` row at connection-test time.
#:   A constant here that disagreed with the served model would be discovered at the first
#:   upsert of a full ingest run, after the parse and the embed spend.
#:
#: Both ids appear in the ``CreateEmbeddingRequest.model`` enum of OpenAI's own published
#: ``openapi.yaml`` (github.com/openai/openai-openapi, read 2026-08-07), alongside the retired
#: ``text-embedding-ada-002``, and the same document states that ``dimensions`` is "only
#: supported in ``text-embedding-3`` and later models" — which is the source for
#: ``Capability.EMBEDDING_DIMENSIONS`` being on for these two rows and off for anything older.
#: The previous marker here said neither had been re-checked against a current catalog; they
#: have been, against the vendor's machine-readable description rather than a marketing page.
#:
#: <!-- UNVERIFIED: the WIDTHS are still not read off a response. 3072 native for
#: `-3-large` and 1536 for `-3-small` come from `bge-m3-embeddings` and `kb-chunking-rules`,
#: and the openapi document does not state either — `dimensions` is typed as a plain integer
#: with no per-model maximum. This is finding C3's live half and the bootstrap probe in
#: `ensure_collection` is what closes it: embed one short string and assert the length equals
#: `space.dimensions`. Until it runs, a width in this tree is a quotation, not a measurement. -->
EMBEDDING_MODELS: Final[tuple[str, ...]] = (
    "text-embedding-3-large",
    "text-embedding-3-small",
)

#: Read off the live response before iterating, and tenant-safe. Parsed into
#: ``Diagnostics.rate_limit``; the SDK reads none of them for us.
RATE_LIMIT_HEADERS: Final[tuple[str, ...]] = (
    "x-ratelimit-remaining-requests",
    "x-ratelimit-reset-requests",
    "x-ratelimit-remaining-tokens",
    "x-ratelimit-reset-tokens",
    "retry-after",
)

#: SDK exception + body ``code`` -> class. The 429 row is the one that costs money: both
#: values arrive as ``RateLimitError``, so a bare ``except RateLimitError`` treats an
#: exhausted account as a transient limit — full backoff ladder, then a silent fallback onto
#: a second account, and no page.
#:
#: | Signal | class |
#: |---|---|
#: | ``APIConnectionError``, ``APITimeoutError``, no first token | ``provider_temporary`` |
#: | 500 ``server_error``, 503 overloaded | ``provider_temporary`` |
#: | 429 ``rate_limit_exceeded`` | ``provider_rate_limit`` |
#: | 429 ``insufficient_quota`` | ``provider_billing`` |
#: | 401, 403 | ``provider_auth`` |
#: | 404 ``model_not_found`` | ``provider_permanent_request`` — never falls back (ADR-014) |
#: | 400 ``context_length_exceeded`` / ``unsupported_value`` | ``provider_permanent_request`` |
#: | ``status="failed"``, or any unmapped code | ``provider_permanent_request`` + alert |
BODY_CODE_TO_CLASS: Final[dict[str, ErrorClass]] = {
    "rate_limit_exceeded": ErrorClass.PROVIDER_RATE_LIMIT,
    "insufficient_quota": ErrorClass.PROVIDER_BILLING,
    "server_error": ErrorClass.PROVIDER_TEMPORARY,
    "model_not_found": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "context_length_exceeded": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "unsupported_value": ErrorClass.PROVIDER_PERMANENT_REQUEST,
}


class OpenAIAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags this adapter expects on a 5.6-line row, and why each is a decision and
    not a description: ``TEXT``, ``IMAGE_INPUT``, ``TOOL_USE``, ``STRUCTURED_OUTPUT`` (native
    strict ``json_schema``, so ``JSON_MODE`` is redundant and stays off), ``REASONING``,
    ``REASONING_TRACE`` (summaries are returned, unlike Anthropic's default),
    ``PROMPT_CACHING``, ``STREAM_USAGE``. ``SAMPLING`` is OFF for the reasoning line — the
    models 400 on ``temperature`` rather than ignoring it. ``EARLY_INPUT_USAGE`` is OFF:
    usage arrives only in the terminal event, so a cancelled turn is always estimated.
    """

    name = "openai"
    #: A first token has to be plausible inside the remaining budget or the attempt bills
    #: for a completion nobody will read.
    min_useful_seconds = 5.0

    def __init__(self, base_url: str | None = None) -> None:
        """No credential here. The key is a ``stream()`` argument (`contract`).

        Holds nothing per-org, so one instance serves every tenant.
        """
        self._base_url = base_url

    def _client(self, credential: SecretStr) -> Any:
        """Build ``AsyncOpenAI`` for one call.

        ``max_retries=0``, always. The SDK retries twice by default, invisibly, inside one
        ``await``, so the span records one call; the adapter policy loop retries twice; the
        browser used to retry three times. Left alone that is 27 billed calls from one click.

        Timeout is an explicit ``httpx.Timeout(connect=..., read=first_token, ...)``, never
        the SDK's flat 600 s default — 13x our provider budget. ``read`` is between chunks,
        not total, so a model emitting one token every fifteen seconds never trips it; the
        caller's absolute deadline is what enforces the total.
        """
        raise NotImplementedError("openai-api: AsyncOpenAI(max_retries=0, explicit httpx.Timeout)")

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the Responses body.

        Mandatory on every request, each for a reason recorded in the module docstring:
        ``store=False``, ``truncation="disabled"``, ``stream=True``, a ``prompt_cache_key``
        keyed on the bot (not the conversation, too sparse to ever hit; not the org, too hot
        for one key), and a one-way hashed ``safety_identifier`` — never an email, a user id
        or an org id, all of which would leave our boundary as plaintext.

        Never present on the chat path: ``previous_response_id``, ``conversation``,
        ``background``. The last one detaches generation from the connection, so cancelling
        stops nothing and the spend continues after the tab closes.

        Structured output nests differently from Chat Completions — no ``json_schema``
        wrapper object and ``name`` is required. The strict subset is narrow: every property
        in ``required``, ``additionalProperties: false`` on every object, an object at the
        root, and no ``allOf``/``not``/``if``. A Pydantic model does not emit that by
        default, so the schema is validated in ``validate()``, not assumed.
        """
        raise NotImplementedError("openai-api: Responses body")

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``; never drop an option silently."""
        raise NotImplementedError

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the Responses event stream.

        Implemented as an ``async def`` generator — see the Protocol's note on why this is
        declared ``def``.

        Order of operations that is not negotiable: capture ``x-request-id`` and the
        rate-limit headers off ``stream.response.headers`` immediately after ``create()``
        returns and BEFORE the first iteration, because a stream that dies mid-flight takes
        them with it.

        Cancellation yields the terminal ``ChatResult`` from the ``except
        asyncio.CancelledError`` branch and then re-raises. Never from ``finally``: an async
        generator that yields while ``GeneratorExit`` unwinds raises ``RuntimeError``, the
        ASGI layer swallows it, and the usage row disappears for a turn OpenAI still billed.
        """
        raise NotImplementedError("openai-api: Responses stream translation")

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """Normalize usage. SUBTRACT the cached amount — it is a subset here."""
        raise NotImplementedError("openai-api: input_tokens - cached_tokens")

    @staticmethod
    def _stop(response: Any) -> StopReason:
        """Map the terminal response onto a stop reason.

        Checked in this order, because each earlier check would otherwise be swallowed by a
        later one: a ``refusal`` content part anywhere in ``output`` -> ``REFUSAL``;
        ``status == "incomplete"`` with ``reason == "content_filter"`` -> ``REFUSAL``; any
        other ``incomplete``, INCLUDING an empty ``incomplete_details`` -> ``MAX_OUTPUT``;
        ``status == "failed"`` -> ``ERROR``; only ``completed`` reaches ``COMPLETE``.
        """
        raise NotImplementedError("openai-api: status + incomplete_details + refusal part")

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """SDK exception plus the body's ``code`` — never the status alone.

        See ``BODY_CODE_TO_CLASS``. The 429 split is decided on ``insufficient_quota``, a
        vendor code, and never on the message.

        Shared with the embedding surface deliberately: it is the same account, the same key
        and the same error envelope, so the same codes mean the same things. ``tokens_emitted``
        is always 0 from ``embed`` — there is no stream and therefore no point past which a
        retry re-bills a partial completion, which is why an embedding call may retry the SAME
        connection under ``RETRYABLE`` while a mid-stream chat failure may not.

        What is NOT shared is the fallback verdict. ``errors.fallback_eligible`` takes
        ``ProviderSurface`` as a second axis and returns False for every class on the embedding
        surface: a different provider is a different vector space, and falling back
        mid-ingestion writes points whose cosine distance to the rest of the collection is
        meaningless — with no width mismatch to make Qdrant reject them.
        """
        raise NotImplementedError("openai-api: exception + body code -> ErrorClass")

    # ── embedding (ADR-030) ───────────────────────────────────────────────────

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``; never drop an option silently.

        At minimum, and each for a failure with no error to catch:

        * ``dimensions`` set while ``Capability.EMBEDDING_DIMENSIONS`` is off. An *ignored*
          ``dimensions`` produces vectors the collection cannot hold, discovered at upsert; an
          *honoured* one produces a second ``EmbeddingSpace`` under the same model id.
        * ``input_type``. OpenAI's models are symmetric and take no query/passage
          discriminator, so ``EMBEDDING_INPUT_TYPE`` is off on these rows and the field is
          ignored — visibly, with a warning, because it is honoured on other vendors and a
          silent ignore here would read as a portable field that is not.
        * The batch ceilings, item count AND summed tokens. They are different limits and the
          token one is the one that surprises: a smoke test embeds fine and a real corpus 400s.
        * The per-input length limit, against the row's ``context_window``. A hard rejection,
          never a truncation — ``embedder.check_window`` performs the same check once per run
          before any customer text leaves the process, and this is the per-request backstop.
        """
        raise NotImplementedError

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """``POST /v1/embeddings``. Vectors in INPUT order, never the vendor's response order.

        ``async def`` here and plain ``def`` on ``stream()`` — see the Protocol's note. This
        one really is a coroutine: one request, one response, nothing to yield.

        Re-sorts the response on each entry's ``index`` and asserts the result covers every
        input exactly once. Never returns a partial result: a batch that half succeeded would
        write half a source version's vectors, and the version would then publish as complete.

        Fills ``EmbeddingResult.space`` from ``app.retrieval.collection.EmbeddingSpace`` with
        the width **actually returned**, and ``normalized`` from what the response actually
        contains — not from the vendor's documentation, which is the claim
        ``collection.DENSE_DISTANCE = "Cosine"`` rests on and the only thing that can check it.

        ``max_retries=0`` and an explicit timeout, as on the chat client, and for the sharper
        reason here: an SDK retry inside one ``await`` re-bills the whole batch and the span
        records one call.
        """
        raise NotImplementedError("openai-api: POST /v1/embeddings, re-sorted by index")

    @staticmethod
    def _embedding_usage(raw: Any) -> Usage:
        """``prompt_tokens`` is the entire cost. Nothing to subtract — there is no cache here.

        Separate from ``_usage`` on purpose. ``_usage`` subtracts ``cached_tokens`` out of
        ``input_tokens`` because on the chat surface the cached amount is a SUBSET; this
        endpoint reports no cached amount at all, so the same code path would subtract zero
        today and silently start subtracting the wrong thing the day OpenAI adds one::

            Usage.input_tokens       = usage.prompt_tokens
            Usage.cache_read_tokens  = 0     # not reported on this endpoint
            Usage.cache_write_tokens = 0
            Usage.output_tokens      = 0     # nothing is generated
            Usage.reasoning_tokens   = 0
            Usage.source             = "provider_final"

        Billing reads ``total_input_tokens`` here as everywhere else.
        """
        raise NotImplementedError("openai-api: embeddings usage.prompt_tokens")


def _assert_conforms(adapter: OpenAIAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter


def _assert_embeds(adapter: OpenAIAdapter) -> EmbeddingAdapter:
    """The static half of the embedding capability gate.

    This function existing **is** the claim that OpenAI embeds, and it is the claim mypy can
    check. The other half is ``capabilities.PROVIDER_TASKS[("openai", EMBEDDING)]``, which is
    the claim a caller can *query*; ``tests/unit/test_provider_capability_matrix.py`` asserts
    the two agree, in both directions, for all five adapters.

    There is deliberately no ``_assert_reranks`` in this module: OpenAI is recorded
    ``UNSUPPORTED`` on the rerank surface by ``bge-reranker`` and ``nvidia-nim-api``, and
    corroborated by enumeration — its published ``openapi.yaml`` describes 182 paths and none
    of them ranks. Adding the method here without moving the matrix cell fails that test.
    """
    return adapter

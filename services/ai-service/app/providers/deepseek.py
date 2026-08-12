"""DeepSeek. OpenAI-compatible **in shape only** — this file is the divergence list.

Called with the ``openai`` SDK against ``https://api.deepseek.com``, which is a documented
OpenAI-shaped surface. That shared shape is exactly what makes this adapter dangerous to
write: the request serializes, the response parses, and four separate behaviours differ
underneath without ever raising.

DeepSeek also publishes an Anthropic-shaped surface at ``/anthropic``. **We never use it.**
It maps ``claude-opus*`` to ``deepseek-v4-pro``, ``claude-sonnet*``/``claude-haiku*`` to
``deepseek-v4-flash``, and *anything unrecognised* to ``deepseek-v4-flash`` — so a typo in a
model id bills the cheap model and answers plausibly instead of failing. It also ignores
``anthropic-version``, ``anthropic-beta``, ``top_k`` and every ``cache_control`` field. A
DeepSeek connection must never be configured through the Anthropic adapter.

## Divergences this file exists to absorb

**An OpenAI-SDK call to api.deepseek.com silently IGNORES unsupported parameters rather than
erroring.** This is the headline divergence and the reason the module exists. DeepSeek's own
documentation says setting the ignored sampling parameters "will not trigger an error but
will also have no effect". With thinking enabled, ``temperature``, ``top_p``,
``presence_penalty`` and ``frequency_penalty`` all do nothing; the last two are marked
deprecated-and-ignored on *every* request, thinking or not. So ``temperature=0`` returns a
differently-worded answer every run and nothing anywhere reports a problem. Forwarding a
parameter we know is inert is the silent drop §8.6 forbids — every one of them becomes a
``CapabilityWarning``.

**The ``thinking`` object, and its default is the reverse of everyone else's.** Thinking is
ON when the field is absent. Both V4 models are hybrid — thinking is a per-request
``thinking.type``, not a model family — so ``REASONING`` and ``SAMPLING`` are mutually
exclusive on the *same model*, and the capability row has to be resolved per
``(model, thinking_enabled)``. The adapter always sends ``thinking`` explicitly, including
``{"type": "disabled"}``, because omitting it buys reasoning nobody asked for.

**``reasoning_content`` is a separate delta field with two opposite replay rules.** Outside a
tool call, echoing it back is silently discarded by the API. Inside an in-flight tool loop it
MUST be replayed in every subsequent request of that turn or the model reasons from a hole
and the second tool call is nonsense. So there is no single policy: preserve it verbatim
within a live tool loop, strip it when replaying persisted conversation turns, and never
persist it into history that a later turn replays.

**The cache hit/miss partition.** ``prompt_tokens == prompt_cache_hit_tokens +
prompt_cache_miss_tokens`` — the two parts partition the input exactly. That is a third
arithmetic: OpenAI's cached amount is a subset of its input count, Anthropic's is a sibling
of it. Feeding ``prompt_tokens`` into our disjoint buckets double-counts the cached prefix
and reports roughly double DeepSeek's invoice against a stable knowledge base. There is no
cache-write bucket: DeepSeek neither bills nor reports writes, and caching is automatic,
prefix-match, and cannot be steered, so ``cache_hint`` is always a warning here.

**Saturation is signalled by HOLDING THE CONNECTION OPEN, not by a 429.** Over the model's
concurrency limit DeepSeek keeps the request connected while it waits for a slot, emitting
blank lines on non-streaming calls and ``: keep-alive`` SSE comments on streaming ones,
closing only after about ten minutes. Three consequences, all of them adapter-level: the
reader must skip comment lines before parsing JSON or it crashes on the first keep-alive
under load; the first-token timeout is the ONLY saturation detector, so it classifies as
``provider_temporary`` and a connection configured to fall back on ``provider_rate_limit``
never fires; and TTFT measured on "first chunk received" reads as instant while the user
waits, so TTFT counts only chunks with non-empty content.

**No rate-limit headers, no ``Retry-After``, no request-id header.**
``Diagnostics.rate_limit`` stays empty and ``provider_request_id`` is null — the body's
``id`` is a completion id, not a support handle. Backoff here is blind full jitter. Do not
fabricate a reset time; an invented value outranks the jittered backoff and pins the caller
inside the rejection window.
<!-- UNVERIFIED: the absence of these headers is inferred from their omission across the
rate-limit and error-code pages, not from a positive statement. -->

**JSON mode is the ceiling.** There is no ``json_schema`` response format, so
``STRUCTURED_OUTPUT`` is false and ``JSON_MODE`` is true for every DeepSeek model; schema
enforcement is ours, after the fact. ``json_object`` additionally requires the literal word
"json" plus an example of the shape in the prompt, and documented behaviour includes
occasionally returning empty content — which means ``tokens_emitted == 0``, the one case
where a single in-adapter retry is legitimately safe.

**``finish_reason: "insufficient_system_resource"`` arrives as HTTP 200 and is a capacity
failure**, not a stop reason. It maps to ``provider_temporary`` and is fallback-eligible. It
is deliberately absent from ``STOP`` so that it can never reach ``COMPLETE``.

**Model ids move under stable configuration.** ``deepseek-chat`` and ``deepseek-reasoner``
were retired 2026-07-24, and for three months before that they silently routed to V4-Flash —
so cost and answer quality changed with no configuration change and no error.
``deepseek-v4-flash`` is itself an alias DeepSeek rolls forward in place. ``system_fingerprint``
is recorded into ``Diagnostics.extras`` on every call because it is the only observable that
moves when the weights do.

## Stream event -> internal event

| Chunk shape | Internal |
|---|---|
| SSE comment line (``: keep-alive``) | skipped BEFORE parsing; see the saturation note |
| ``choices[0].delta.reasoning_content`` | ``Delta(kind="reasoning")`` |
| ``choices[0].delta.content`` | ``Delta(kind="text")``; the first non-empty one starts TTFT |
| ``choices[0].delta.tool_calls[].function.arguments`` | ``Delta(kind="tool_args")`` |
| ``choices[0].finish_reason`` | held; mapped at the end via ``STOP`` |
| final chunk, ``usage`` + ``choices == []`` | terminal ``ChatResult``; ``choices[0]`` crashes |
| ``[DONE]`` | end of stream |

## Usage normalization

A partition, so the miss half is the uncached input::

    Usage.input_tokens       = usage.prompt_cache_miss_tokens
    Usage.cache_read_tokens  = usage.prompt_cache_hit_tokens
    Usage.cache_write_tokens = 0                       # never billed, never reported
    Usage.output_tokens      = usage.completion_tokens
    Usage.reasoning_tokens   = usage.completion_tokens_details.reasoning_tokens

``hit + miss == prompt_tokens`` is asserted in the adapter, not only in a test: a broken
partition means the row must not be billed at all.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import Any, Final

from pydantic import SecretStr

from app.core.errors import ErrorClass
from app.providers.contract import (
    CapabilityWarning,
    ChatRequest,
    ModelCapabilities,
    ProviderAdapter,
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import ProviderCallFailed

__all__ = ["DeepSeekAdapter"]

#: The OpenAI-shaped surface. The ``/anthropic`` surface is not an option, it is a hazard —
#: see the module docstring.
BASE_URL: Final[str] = "https://api.deepseek.com"

#: Verified against api-docs.deepseek.com on 2026-08-04. Documentation and connection-save
#: validation only.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "deepseek-v4-pro",
    "deepseek-v4-flash",
)

#: Retired 2026-07-24. A ``provider_models`` row still carrying either is dead configuration,
#: and the connection save rejects it with a message naming the retirement date — because the
#: failure mode that preceded the retirement was silent rerouting, not an error.
RETIRED_MODELS: Final[tuple[str, ...]] = (
    "deepseek-chat",
    "deepseek-reasoner",
)

#: Our seven levels onto DeepSeek's three. Rounds DOWN, so a portable request never buys more
#: reasoning than it asked for, and every rounded step emits a ``CapabilityWarning`` — an
#: unwarned rounding is indistinguishable from the vendor honouring the request.
EFFORT: Final[dict[str, str]] = {
    "minimal": "low",
    "low": "low",
    "medium": "low",
    "high": "high",
    "xhigh": "high",
    "max": "max",
}
#: Steps with no exact vendor equivalent; each produces a warning naming what it became.
ROUNDED_EFFORT: Final[frozenset[str]] = frozenset({"minimal", "medium", "xhigh"})

#: ``insufficient_system_resource`` is deliberately ABSENT: it is capacity, not a stop
#: reason, and it arrives inside an HTTP 200. Anything absent maps to ``ERROR`` with
#: ``native_stop_reason`` preserved.
STOP: Final[dict[str, StopReason]] = {
    "stop": StopReason.COMPLETE,
    "length": StopReason.MAX_OUTPUT,
    "tool_calls": StopReason.TOOL_USE,
    "content_filter": StopReason.REFUSAL,
}

#: HTTP status -> class, on the vendor's documented status list rather than on message text.
#:
#: 402 Insufficient Balance is ``PROVIDER_BILLING``: same 402 Anthropic uses for the same
#: state. The `deepseek-api` skill maps it to ``provider_permanent_request``, which predates
#: the billing class; retry and fallback verdicts are identical either way, but only the
#: billing class pages the operator, and an exhausted balance is the one failure that will
#: never self-heal on its own.
STATUS_TO_CLASS: Final[dict[int, ErrorClass]] = {
    400: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    401: ErrorClass.PROVIDER_AUTH,
    402: ErrorClass.PROVIDER_BILLING,
    422: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    429: ErrorClass.PROVIDER_RATE_LIMIT,
    500: ErrorClass.PROVIDER_TEMPORARY,
    503: ErrorClass.PROVIDER_TEMPORARY,
}

#: The HTTP-200 capacity signal. Fallback-eligible; never a stop reason.
CAPACITY_FINISH_REASON: Final[str] = "insufficient_system_resource"


class DeepSeekAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags expected on a V4 row: ``TEXT``, ``TOOL_USE``, ``JSON_MODE``,
    ``REASONING``, ``REASONING_TRACE`` (``reasoning_content`` really is returned, unlike
    Anthropic's default), ``PROMPT_CACHING``, ``STREAM_USAGE``. OFF: ``IMAGE_INPUT``
    (text-only), ``STRUCTURED_OUTPUT`` (no ``json_schema`` surface), ``EARLY_INPUT_USAGE``
    (usage arrives on the final chunk only). ``SAMPLING`` is the conditional one — true only
    on the ``thinking: disabled`` resolution of the row, because with thinking on the
    parameters are accepted and inert.

    **No ``embed`` and no ``rerank``, and both cells are now ``UNSUPPORTED`` by enumeration.**
    ``bge-reranker`` and ``nvidia-nim-api`` state "OpenAI, Anthropic and DeepSeek do not offer
    one" for ranking, and DeepSeek's own API reference — the complete index, not a guide —
    holds exactly five operations: create chat completion, create completion (FIM beta), create
    response, get user balance, list models. Neither family is among them. The embedding cell
    used to be ``UNVERIFIED`` because nobody had read the index; the verdict was the same and
    the follow-up was not.

    The distinction matters more here than elsewhere because of this vendor's headline
    divergence: an OpenAI-SDK call to ``api.deepseek.com`` **silently ignores** parameters it
    does not support rather than erroring. A wrongly-optimistic capability cell on a vendor
    with that habit is the worst combination available — the request would serialize, the
    response would parse, and only the numbers would be wrong.

    The embedding denial carries the same consequence Anthropic's does and it is worth stating
    twice rather than cross-referencing once: **a DeepSeek-only organization cannot ingest a
    single document.** The way out is a second connection to a vendor that embeds, not a
    ``provider_models`` row that claims one. The one caveat on this cell is that an enumeration
    retracts nothing — a route added tomorrow would make it stale without contradicting it — so
    it is dated in ``capabilities.PROVIDER_TASKS`` and the index is what gets re-read, never
    the verdict.
    """

    name = "deepseek"
    #: Higher than the other adapters on purpose: saturation here is a held connection, so a
    #: short remaining budget buys a socket that sits open and produces nothing.
    min_useful_seconds = 8.0

    def _client(self, credential: SecretStr, req: ChatRequest) -> Any:
        """``AsyncOpenAI(base_url=BASE_URL, max_retries=0, timeout=...)``.

        ``max_retries=0`` matters more here than anywhere else: DeepSeek produces
        connection-shaped failures constantly because it holds sockets open under load, and
        those are exactly the failures the SDK considers idempotent and retries on its own.
        """
        raise NotImplementedError("deepseek-api: AsyncOpenAI(base_url=BASE_URL, max_retries=0)")

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the chat-completions body.

        Always present: an explicit ``thinking`` object (never omitted — the default is
        enabled), ``stream_options={"include_usage": True}`` on any streamed call (without
        it the stream ends with no usage chunk at all and every call bills as zero), and
        ``user_id`` set to an opaque org handle for KVCache and scheduling isolation. The org
        id is a ULID, which matches the vendor's accepted charset and carries no personal
        data off-platform.

        Ordering is load-bearing: system instruction, then ``context_blocks`` in a stable
        deterministic sort, then conversation, then the new question. Caching is prefix-match
        only, so anything volatile at the front — a timestamp, a trace id, a re-sorted
        evidence list — destroys the hit rate with no error and a 50x cost surprise.
        """
        raise NotImplementedError("deepseek-api: chat.completions body")

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Warn on every parameter DeepSeek accepts and ignores.

        At minimum: ``temperature`` while thinking is enabled, ``reasoning.budget_tokens``
        (effort steps only), ``response_schema`` (json_object, unenforced), ``cache_hint``
        (automatic, unsteerable), ``images`` (text-only, rejected), and any effort level in
        ``ROUNDED_EFFORT``.
        """
        raise NotImplementedError

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the chat-completions stream.

        Skip SSE comment lines before parsing. Guard every ``choices`` access — the usage
        chunk arrives with ``choices == []``, which is precisely the chunk carrying the
        number the ``include_usage`` option was added for.
        """
        raise NotImplementedError("deepseek-api: chat.completions stream translation")

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """Miss is the input, hit is the cache read; assert the partition before billing."""
        raise NotImplementedError("deepseek-api: hit/miss partition")

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """Status -> class via ``STATUS_TO_CLASS``, plus the two DeepSeek-only branches.

        A first-token timeout is ``PROVIDER_TEMPORARY``, and it is the only saturation
        signal that exists here. An HTTP-200 ``finish_reason`` of
        ``insufficient_system_resource`` is also ``PROVIDER_TEMPORARY`` and is classified in
        ``stream()``, not here — no exception is ever raised for it.
        """
        raise NotImplementedError("deepseek-api: status -> ErrorClass")


def _assert_conforms(adapter: DeepSeekAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter

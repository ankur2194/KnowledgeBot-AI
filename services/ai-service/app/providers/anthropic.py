"""Anthropic Claude, through the Messages API. Skeleton; signatures are final.

SDK ``anthropic==0.120.2``, API version header ``2023-06-01``. Named for its vendor, like
three of its four siblings; ``openai_adapter.py`` is the one that breaks the pattern, for
the reason recorded in ``services/ai-service/README.md``.

## Divergences this file exists to absorb

**``input_tokens`` EXCLUDES cached tokens.** ``cache_read_input_tokens`` and
``cache_creation_input_tokens`` are *siblings*, not subsets, and total input is the sum of
all three. This is the exact opposite of OpenAI, and it is the reason usage normalization
cannot be shared code written once: here the mapping is a verbatim assignment into our
already-disjoint buckets, and the bug is "helpfully" subtracting. Read ``input_tokens`` as
"the input" and a 200k-token cached document with a 50-token question reports 50 input
tokens — under-billing by the entire prefix, in the opposite direction from the OpenAI
mistake.

**A third number exists for rate limiting.** ``cache_read_input_tokens`` does not count
toward ITPM on any model we ship, so ITPM headroom is ``input_tokens +
cache_creation_input_tokens`` — neither our ``total_input_tokens`` nor the raw
``input_tokens``. A dashboard built on either of those disagrees with the 429s.

**``message_delta.usage`` is CUMULATIVE, not incremental.** Assign; never accumulate.
Accumulating triple-counts output on a long stream, and the error grows with answer length,
so it is invisible in short test fixtures.

**Anthropic is the only vendor here that reports input usage BEFORE generation.**
``message_start`` carries ``input_tokens``, so an aborted turn still bills exactly — that
path sets ``Capability.EARLY_INPUT_USAGE`` and ``source="provider_partial"`` instead of the
``"estimated"`` every other vendor is stuck with.

**``effort`` is nested inside ``output_config``**, never a top-level parameter. Top-level is
a ``TypeError`` from the SDK signature and a 400 on raw HTTP. ``output_config`` also carries
``format`` for structured output, so it is not a thinking-only object.

**Extended thinking is ``thinking={"type": "adaptive"}``.** The
``{"type": "enabled", "budget_tokens": N}`` shape is a 400 on every model we ship.
``ReasoningOption.budget_tokens`` therefore never becomes a request field here — it becomes
a ``CapabilityWarning``.

**Thinking is billed but not returned by default.** ``thinking.display`` defaults to
``"omitted"``: the thinking block opens, receives one ``signature_delta``, and closes with
no ``thinking_delta`` at all, while the full trace is charged. That is why
``REASONING_TRACE`` is a separate flag from ``REASONING`` — a pane gated on ``REASONING``
renders empty forever and the cost line still climbs.

**``effort: "none"`` is the one lossy step in our ladder.** Opus 5 accepts
``thinking={"type": "disabled"}`` only at effort <= high, so ``none`` pins effort to
``"high"`` and warns. ``xhigh`` maps one-to-one — that is precisely why the portable enum
carries it.

**The SDK emits every token twice.** Iterating the stream fires the raw
``content_block_delta`` AND a synthetic helper event (``text``, ``thinking``, ``signature``,
``input_json``, ``citation``) built from the same delta. Match raw event types only, or the
widget renders every token twice.

**A mid-stream overload reads as HTTP 200.** An SSE ``event: error`` is raised against the
ORIGINAL 200 response, so it downgrades to a bare ``APIStatusError`` with
``status_code == 200`` and escapes ``except OverloadedError`` entirely. Branch on
``exc.type``, or routine capacity blips are filed as our bug and page somebody.

**Sampling is a 400 on every request**, thinking or not, on recent models — not an ignore.

**``max_tokens`` caps thinking plus visible text**, and recent models accept an over-budget
value at validation time and stop mid-generation instead of erroring. A route that never set
``thinking`` and sized ``max_tokens`` tightly starts truncating silently the moment it moves
to a model where thinking is on by default.

**Cache breakpoints belong on the last SYSTEM block only.** Placed on the retrieved context
blocks, every turn writes a fresh entry at ~1.25x and never reads one: hit rate 0%, cost up,
no error. Second cause with identical symptoms — the prefix is below the model's minimum
(512 tokens on Opus 5, 1024 on Sonnet 5, 4096 on the 4.6 line and Haiku 4.5).

**The request id header has no prefix**: ``request-id``, plus ``request_id`` in the error
body. OpenAI spells it ``x-request-id``.

## Stream event -> internal event

| Messages event | Internal |
|---|---|
| ``message_start`` | no delta; seeds ``Usage`` with exact input, ``source="provider_partial"`` |
| ``content_block_start`` | nothing — the text is empty, and TTFT timed from it is fiction |
| ``content_block_delta`` / ``text_delta`` | ``Delta(kind="text", index=event.index)`` |
| ``content_block_delta`` / ``thinking_delta`` | ``Delta(kind="reasoning")``; absent if omitted |
| ``content_block_delta`` / ``input_json_delta`` | ``Delta(kind="tool_args")`` |
| ``content_block_delta`` / ``signature_delta`` | dropped; it is an integrity token, not content |
| ``message_delta`` | cumulative usage (ASSIGN), and the stop reason |
| ``message_stop`` | terminal ``ChatResult`` |
| ``ping`` | ignored |
| ``error`` (mid-stream, inside a 200) | terminal ``ChatResult``, class from ``exc.type`` |

## Usage normalization

Siblings, so a verbatim assignment::

    Usage.input_tokens       = usage.input_tokens              # already excludes cache
    Usage.cache_read_tokens  = usage.cache_read_input_tokens
    Usage.cache_write_tokens = usage.cache_creation_input_tokens
    Usage.output_tokens      = usage.output_tokens
    Usage.reasoning_tokens   = <thinking, inside output_tokens>

Input fields are present on some models and absent on others in ``message_delta``, so the
merge takes only non-None fields — an absent field is "unchanged", not zero.
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

__all__ = ["AnthropicAdapter"]

#: Verified against platform.claude.com/docs model documentation on 2026-08-04.
#: Documentation and connection-save validation only; capabilities come from
#: ``provider_models.capability_flags``.
#:
#: ``claude-haiku-4-5`` is catalogued deliberately WITHOUT ``Capability.REASONING``:
#: ``output_config.effort`` errors on the pre-4.6 line, and the deprecated ``budget_tokens``
#: shape is one we never ship. Without the flag the adapter omits ``thinking`` and ``effort``
#: both, and the question does not arise.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "claude-opus-5",
    "claude-sonnet-5",
    "claude-haiku-4-5",
)

#: Our seven-level portable effort onto Anthropic's five. ``minimal`` and ``none`` both
#: collapse upward because Opus 5 rejects disabled thinking above effort ``high`` — both
#: emit a ``CapabilityWarning`` rather than rounding in silence.
EFFORT: Final[dict[str, str]] = {
    "none": "high",
    "minimal": "high",
    "low": "low",
    "medium": "medium",
    "high": "high",
    "xhigh": "xhigh",
    "max": "max",
}

#: Native stop reason -> ours. Anything absent becomes ``StopReason.ERROR`` with
#: ``native_stop_reason`` preserved: Anthropic adds stop reasons under its versioning policy
#: (``refusal`` and ``pause_turn`` both arrived that way), and a fall-through to ``COMPLETE``
#: would ship a truncated answer as a finished one.
STOP: Final[dict[str, StopReason]] = {
    "end_turn": StopReason.COMPLETE,
    "stop_sequence": StopReason.STOP_SEQUENCE,
    "tool_use": StopReason.TOOL_USE,
    "pause_turn": StopReason.TOOL_USE,
    "refusal": StopReason.REFUSAL,
    #: OUR cap. The answer is truncated.
    "max_tokens": StopReason.MAX_OUTPUT,
    #: The MODEL's window, mid-generation.
    "model_context_window_exceeded": StopReason.CONTEXT_EXCEEDED,
}

#: ``error.type`` from the body -> class. The body type is read FIRST and the status code
#: only as a tiebreak, because a mid-stream error carries the original 200.
#:
#: ``billing_error`` (402) is ``PROVIDER_BILLING``, not ``PROVIDER_AUTH``. The retry and
#: fallback policy is identical, but the class is what a runbook and a dashboard key on, and
#: an exhausted balance must not read as a revoked key. The `anthropic-api` skill still maps
#: it to ``provider_auth``, which predates the billing class; the taxonomy wins.
#:
#: ``permission_error`` (403) covers BOTH "this key lacks permission" and "your org is not
#: entitled to this model". Default to ``PROVIDER_AUTH`` and reclassify to
#: ``PROVIDER_PERMANENT_REQUEST`` only when ``models.retrieve(req.model)`` 404s under the
#: same credential — neither retries nor falls back, so the only cost of confusing them is
#: paging the wrong person and leaving a dead model in the catalog.
ERROR_TYPE_TO_CLASS: Final[dict[str, ErrorClass]] = {
    "overloaded_error": ErrorClass.PROVIDER_TEMPORARY,
    "api_error": ErrorClass.PROVIDER_TEMPORARY,
    "timeout_error": ErrorClass.PROVIDER_TEMPORARY,
    "rate_limit_error": ErrorClass.PROVIDER_RATE_LIMIT,
    "authentication_error": ErrorClass.PROVIDER_AUTH,
    "permission_error": ErrorClass.PROVIDER_AUTH,
    "billing_error": ErrorClass.PROVIDER_BILLING,
    "not_found_error": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "invalid_request_error": ErrorClass.PROVIDER_PERMANENT_REQUEST,
}


class AnthropicAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags expected on an Opus 5 / Sonnet 5 row: ``TEXT``, ``IMAGE_INPUT``,
    ``TOOL_USE``, ``STRUCTURED_OUTPUT`` (``output_config.format``; the accepted schema subset
    differs from OpenAI's — ``allOf`` and ``const`` are fine, recursion and numeric bounds
    are not), ``REASONING``, ``PROMPT_CACHING``, ``STREAM_USAGE``, and — uniquely —
    ``EARLY_INPUT_USAGE``. ``SAMPLING`` is OFF: recent models reject non-default sampling on
    every request. ``REASONING_TRACE`` is ON only where a summarized display is actually
    requested and returned.

    **No ``embed`` and no ``rerank``. Both are now closed questions, sourced to Anthropic.**

    * Rerank is ``UNSUPPORTED``: ``bge-reranker`` and ``nvidia-nim-api`` both state "OpenAI,
      Anthropic and DeepSeek do not offer one", and the SDK's own resource index lists three
      families — Messages, Models, Beta — with no ranking route.
    * Embedding is ``UNSUPPORTED``, in the vendor's own words. Under "How to get embeddings
      with Anthropic", ``docs.claude.com/en/docs/build-with-claude/embeddings`` says
      **"Anthropic does not offer its own embedding model"** and recommends a third party.
      This cell used to be ``UNVERIFIED`` — nobody had read the page — and the difference
      matters, because the verdict is the same and the *consequence* is not: unchecked is a
      fixture somebody owes, while this is permanent.

    That second bullet is the sharp end of finding C1 and it belongs in this file rather than
    only in the matrix, because this is the adapter an operator is looking at when they ask
    why their upload failed. **An organization whose only connection is Anthropic cannot
    ingest a single document.** Not slower, not unreranked: every chunk must be embedded
    before it can be indexed, there is no serve-the-fused-order fallback the way there is for
    reranking, and no amount of editing the ``provider_models`` row can conjure an endpoint.
    The organization needs a second connection to a vendor that embeds — today OpenAI, NVIDIA
    NIM or OpenRouter — and ``embedding_selection.resolve_embedding_connection`` is what says
    so, at save time, before a parse is paid for.

    Adding either method here without first moving its matrix cell fails
    ``tests/unit/test_provider_capability_matrix.py``, which is the point: the two gates —
    absence of the method, and the matrix — are asserted against each other so that neither can
    move alone.
    """

    name = "anthropic"
    min_useful_seconds = 5.0

    def __init__(self, http_client: Any | None = None) -> None:
        """One shared connection pool, process-wide; the client wrapper is per call.

        A fresh ``AsyncAnthropic()`` per turn builds a fresh httpx pool, so every turn pays a
        TCP and TLS handshake against the first-token budget — measured at roughly 150 ms.
        Share one ``DefaultAsyncHttpxClient``, construct the wrapper per call so the key stays
        request-scoped, and never close the wrapper: closing it closes the shared pool for
        every other in-flight turn.
        """
        self._http_client = http_client

    def _client(self, credential: SecretStr) -> Any:
        """``AsyncAnthropic(api_key=..., max_retries=0, http_client=<shared pool>)``.

        ``max_retries`` defaults to 2 and those attempts never appear in our spans.
        """
        raise NotImplementedError("anthropic-api: AsyncAnthropic(max_retries=0, http_client=_POOL)")

    def _build(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the Messages kwargs.

        ``system`` is the bot instruction only, as a list of text blocks so a cache
        breakpoint has somewhere to sit. Retrieved evidence is rendered into the message
        turns as data blocks and never into ``system``.

        ``thinking`` is ``{"type": "adaptive"}`` with ``display`` set to ``"summarized"``
        only when ``include_trace`` is set AND ``REASONING_TRACE`` is on; otherwise omitted,
        which is the vendor default and returns nothing while billing everything.

        ``effort`` lives at ``output_config["effort"]`` and nowhere else. A request-shape
        test asserts no top-level ``effort`` and no ``budget_tokens`` in any built payload.
        """
        raise NotImplementedError("anthropic-api: Messages kwargs")

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``.

        Always warns on ``reasoning.budget_tokens``: the shape that carried it is a 400 here,
        so it is dropped — visibly.
        """
        raise NotImplementedError

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the Messages event stream.

        Match RAW event types only. The SDK fires a synthetic helper event for the same
        token, and matching both emits every token twice.

        Rate-limit headers are parsed off the 200 response too, not only off a 429 —
        ``anthropic-ratelimit-*`` plus ``retry-after``.

        Cancellation builds the terminal ``ChatResult`` inside the
        ``except asyncio.CancelledError`` branch, hands it to the finalizer, and re-raises —
        never swallows, because the span has to unwind. Anthropic is the one vendor where
        that result still carries exact input tokens, from ``message_start``.
        """
        raise NotImplementedError("anthropic-api: Messages stream translation")

    @staticmethod
    def _usage(raw: Any, source: str) -> Usage:
        """Verbatim assignment into disjoint buckets — never a subtraction.

        ``message_delta`` usage is cumulative: assign, never accumulate. Merge only non-None
        fields; an absent input field means unchanged, not zero.
        """
        raise NotImplementedError("anthropic-api: sibling cache buckets")

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """``exc.type`` first, status second — see ``ERROR_TYPE_TO_CLASS``.

        Logs ``exc.type``, ``exc.status_code`` and ``exc.request_id``, never the exception
        object: its ``repr`` can carry request state, and request state carries the prompt.
        """
        raise NotImplementedError("anthropic-api: error.type -> ErrorClass")


def _assert_conforms(adapter: AnthropicAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter

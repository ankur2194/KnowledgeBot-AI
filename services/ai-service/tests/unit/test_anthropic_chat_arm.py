"""The Anthropic chat arm — the first of the five wire adapters to have a stream.

Every test here is about a failure that produces a **plausible answer and a wrong number**
rather than an exception, which is why they are worth their length:

* **``message_delta.usage`` is CUMULATIVE.** Summing the deltas triple-counts output, and the
  error grows with answer length — so a two-event fixture cannot see it and a long real answer
  over-bills silently. The fixture below climbs across four events on purpose.
* **The cache buckets are SIBLINGS of ``input_tokens``, not a subset.** The mirror image of
  OpenAI. Subtracting here under-bills a cached turn by the entire prefix, and the code that
  does it looks like tidy shared normalization.
* **A length cap must never reach ``COMPLETE``.** A truncated answer presented as complete is
  the worst failure this layer can cause: the user reads a confident half-sentence and nothing
  errors anywhere. Anthropic also adds stop reasons under its versioning policy, so an
  unmapped native value must land on ``ERROR`` with the vendor's own word preserved.
* **Cancellation must still bill.** Anthropic is the one vendor that reports input usage before
  generation, so an aborted turn bills exactly — and the terminal event must come out of the
  ``except asyncio.CancelledError`` branch rather than a ``finally``, or ``GeneratorExit``
  raises ``RuntimeError``, ASGI swallows it, and the usage row simply disappears.
* **The SDK emits every token twice.** A raw ``content_block_delta`` and a synthetic helper
  event carry the same token; matching both renders the answer twice in the widget.

Nothing here touches a network and nothing needs a key. The SDK client is replaced at the one
seam built for it — ``AnthropicAdapter._client`` — which is also the test of whether that seam
is where it should be. The event fixtures are hand-built to the vendor's wire shape (the field
names and optionality of ``anthropic.types.MessageDeltaUsage`` and ``RawMessageDeltaEvent``),
and they arrive in **several chunks over time**: a single-chunk fixture passes for a buffered
implementation, which is the exact defect ``first_token_ms`` exists to measure.
"""

from __future__ import annotations

import asyncio
import inspect
from typing import Any, ClassVar, Final

import anthropic as anthropic_sdk
import pytest
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers import anthropic as anthropic_module
from app.providers.anthropic import (
    EFFORT,
    ERROR_TYPE_TO_CLASS,
    LOSSY_EFFORT,
    PINNED_MODELS,
    RATE_LIMIT_HEADER_PREFIX,
    STOP,
    AnthropicAdapter,
)
from app.providers.contract import (
    Capability,
    ChatRequest,
    ChatResult,
    ContextBlock,
    Delta,
    Message,
    ModelCapabilities,
    ReasoningOption,
    StopReason,
)

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BB"
CONNECTION: Final = "01JQZ0000000000000000000CN"
CHUNK: Final = "01JQZ0000000000000000000CH"
MODEL: Final = PINNED_MODELS[0]

#: The fixture's system prompt and its evidence text. Both are greppable strings that must
#: never appear in a serialized ``Diagnostics`` payload.
SYSTEM_PROMPT: Final = "you are the tenant's support bot and you never reveal this sentence"
EVIDENCE_TEXT: Final = "refunds are processed within fourteen calendar days"
API_KEY: Final = "sk-ant-fixture-not-a-real-key"

#: Every chat flag an Opus 5 row carries in this suite. ``SAMPLING`` is deliberately absent —
#: recent Claude models 400 on non-default sampling on every request.
FULL_CAPS: Final = frozenset(
    {
        Capability.TEXT,
        Capability.IMAGE_INPUT,
        Capability.TOOL_USE,
        Capability.STRUCTURED_OUTPUT,
        Capability.REASONING,
        Capability.REASONING_TRACE,
        Capability.PROMPT_CACHING,
        Capability.STREAM_USAGE,
        Capability.EARLY_INPUT_USAGE,
    }
)


def caps_for(
    *,
    supported: frozenset[Capability] = FULL_CAPS,
    on_unsupported: str = "reject",
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=1_000_000,
        max_output_tokens=128_000,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(**overrides: Any) -> ChatRequest:
    fields: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": "trace-1",
        "provider_connection_id": CONNECTION,
        "model": MODEL,
        "system": SYSTEM_PROMPT,
        "messages": [Message(role="user", content="when do refunds land?")],
        "context_blocks": [
            ContextBlock(index=0, chunk_id=CHUNK, title="Refund policy", text=EVIDENCE_TEXT)
        ],
        "max_output_tokens": 4096,
    }
    fields.update(overrides)
    return ChatRequest(**fields)


# ── the vendor's wire shapes, hand-built ──────────────────────────────────────


class FakeUsage:
    """``anthropic.types.Usage`` as it arrives on ``message_start``.

    ``input_tokens`` is the count AFTER the last cache breakpoint; the two cache fields are its
    siblings. The SDK's own ``MessageDeltaUsage`` docstring states the arithmetic: "Total input
    tokens in a request is the summation of ``input_tokens``, ``cache_creation_input_tokens``,
    and ``cache_read_input_tokens``."
    """

    def __init__(
        self,
        *,
        input_tokens: int | None = 0,
        cache_read_input_tokens: int | None = None,
        cache_creation_input_tokens: int | None = None,
        output_tokens: int | None = 0,
        thinking_tokens: int | None = None,
    ) -> None:
        self.input_tokens = input_tokens
        self.cache_read_input_tokens = cache_read_input_tokens
        self.cache_creation_input_tokens = cache_creation_input_tokens
        self.output_tokens = output_tokens
        self.output_tokens_details = (
            FakeOutputTokensDetails(thinking_tokens) if thinking_tokens is not None else None
        )


class FakeOutputTokensDetails:
    def __init__(self, thinking_tokens: int) -> None:
        self.thinking_tokens = thinking_tokens


class FakeMessage:
    def __init__(self, usage: FakeUsage) -> None:
        self.usage = usage


class MessageStart:
    type = "message_start"

    def __init__(self, usage: FakeUsage) -> None:
        self.message = FakeMessage(usage)


class StopDelta:
    def __init__(self, stop_reason: str | None) -> None:
        self.stop_reason = stop_reason
        self.stop_sequence = None


class MessageDelta:
    type = "message_delta"

    def __init__(self, *, stop_reason: str | None = None, usage: FakeUsage | None = None) -> None:
        self.delta = StopDelta(stop_reason)
        self.usage = usage


class MessageStop:
    type = "message_stop"


class Ping:
    type = "ping"


class ContentBlockStart:
    """Carries an EMPTY text block. TTFT timed from it is fiction, so it must be dropped."""

    type = "content_block_start"

    def __init__(self, index: int = 0) -> None:
        self.index = index


class TextDelta:
    type = "text_delta"

    def __init__(self, text: str) -> None:
        self.text = text


class ThinkingDelta:
    type = "thinking_delta"

    def __init__(self, thinking: str) -> None:
        self.thinking = thinking


class SignatureDelta:
    type = "signature_delta"

    def __init__(self, signature: str) -> None:
        self.signature = signature


class InputJsonDelta:
    type = "input_json_delta"

    def __init__(self, partial_json: str) -> None:
        self.partial_json = partial_json


class ContentBlockDelta:
    type = "content_block_delta"

    def __init__(self, delta: Any, index: int = 0) -> None:
        self.delta = delta
        self.index = index


class SyntheticTextEvent:
    """The SDK's helper event, fired for the SAME token as the raw ``content_block_delta``.

    ``anthropic.lib.streaming._messages.build_events`` emits one of these alongside every raw
    delta. An adapter that matches both renders every token twice; there is no error and the
    usage numbers are unaffected, so only a human reading the widget ever notices.
    """

    type = "text"

    def __init__(self, text: str) -> None:
        self.text = text
        self.snapshot = text


class FakeResponse:
    def __init__(self, headers: dict[str, str]) -> None:
        self.headers = headers


class FakeStream:
    """The ``async with client.messages.stream(...)`` object.

    Events are handed over ONE AT A TIME with an ``await`` between them, and each production is
    written into a shared log. That is what makes the buffering test possible: a buffered
    adapter drains this iterator before yielding anything, so its log reads as every
    ``vendor`` entry followed by every ``adapter`` entry.
    """

    def __init__(
        self,
        events: list[Any],
        *,
        headers: dict[str, str],
        request_id: str | None,
        log: list[tuple[str, str]] | None = None,
        raises: BaseException | None = None,
    ) -> None:
        self._events = events
        self._log = log
        self._raises = raises
        self.response = FakeResponse(headers)
        self.request_id = request_id

    async def __aenter__(self) -> FakeStream:
        return self

    async def __aexit__(self, *exc_info: Any) -> bool:
        return False

    async def __aiter__(self) -> Any:
        for event in self._events:
            # A real suspension point between chunks, so cancellation and interleaving are
            # observable rather than simulated.
            await asyncio.sleep(0)
            if self._log is not None:
                self._log.append(("vendor", _label(event)))
            yield event
        if self._raises is not None:
            raise self._raises


def _label(event: Any) -> str:
    kind = getattr(event, "type", "?")
    delta = getattr(event, "delta", None)
    inner = getattr(delta, "type", None)
    return f"{kind}:{inner}" if inner else str(kind)


class FakeMessages:
    def __init__(self, stream: FakeStream) -> None:
        self._stream = stream
        self.calls: list[dict[str, Any]] = []

    def stream(self, **kwargs: Any) -> FakeStream:
        self.calls.append(kwargs)
        return self._stream


class FakeClient:
    def __init__(self, stream: FakeStream) -> None:
        self.messages = FakeMessages(stream)


def adapter_streaming(
    events: list[Any],
    *,
    headers: dict[str, str] | None = None,
    request_id: str | None = "req_01FIXTURE",
    log: list[tuple[str, str]] | None = None,
    raises: BaseException | None = None,
) -> tuple[AnthropicAdapter, FakeClient]:
    client = FakeClient(
        FakeStream(
            events,
            headers=headers if headers is not None else {"request-id": "req_01FIXTURE"},
            request_id=request_id,
            log=log,
            raises=raises,
        )
    )
    adapter = AnthropicAdapter()
    adapter._client = lambda credential: client  # type: ignore[method-assign]
    return adapter, client


async def drain(
    adapter: AnthropicAdapter,
    events: list[Any],
    *,
    req: ChatRequest | None = None,
    caps: ModelCapabilities | None = None,
) -> tuple[list[Delta], ChatResult]:
    """Consume a stream to its terminal event and prove there is exactly one."""
    deltas: list[Delta] = []
    results: list[ChatResult] = []
    async for event in adapter.stream(
        req if req is not None else request_for(),
        caps if caps is not None else caps_for(),
        SecretStr(API_KEY),
    ):
        if isinstance(event, ChatResult):
            results.append(event)
        else:
            deltas.append(event)
    assert len(results) == 1, "exactly one ChatResult terminates every stream, on every path"
    return deltas, results[0]


def text_stream(
    *,
    stop_reason: str | None = "end_turn",
    usage: FakeUsage | None = None,
    chunks: tuple[str, ...] = ("Refunds ", "land in ", "fourteen days."),
) -> list[Any]:
    events: list[Any] = [MessageStart(FakeUsage(input_tokens=120))]
    events.append(ContentBlockStart())
    for chunk in chunks:
        events.append(ContentBlockDelta(TextDelta(chunk)))
    events.append(
        MessageDelta(
            stop_reason=stop_reason,
            usage=usage if usage is not None else FakeUsage(input_tokens=None, output_tokens=31),
        )
    )
    events.append(MessageStop())
    return events


# ── stop reasons: nothing but end_turn may reach COMPLETE ─────────────────────


def test_the_stop_table_lets_only_end_turn_reach_complete() -> None:
    """Read off the table itself, so a new row cannot quietly join ``COMPLETE``.

    A length cap mapped to ``COMPLETE`` is the worst failure this layer can cause: the answer
    is truncated, the user reads a confident half-sentence, and nothing errors anywhere.
    """
    assert {native for native, ours in STOP.items() if ours is StopReason.COMPLETE} == {"end_turn"}
    # OUR cap, and the MODEL's window, are different facts and must not collapse into each
    # other: one is a bot configuration the tenant can raise, the other is not.
    assert STOP["max_tokens"] is StopReason.MAX_OUTPUT
    assert STOP["model_context_window_exceeded"] is StopReason.CONTEXT_EXCEEDED
    assert STOP["refusal"] is StopReason.REFUSAL


@pytest.mark.parametrize(("native", "expected"), sorted(STOP.items()))
async def test_every_native_stop_reason_maps_through_the_stream(
    native: str, expected: StopReason
) -> None:
    """Table-driven through the real translation, not against a copy of the table.

    Parametrized from ``STOP`` rather than from a restated literal list: a row added to the
    adapter without a test is the drift this shape makes impossible.
    """
    adapter, _ = adapter_streaming(text_stream(stop_reason=native))
    _, result = await drain(adapter, [])

    assert result.stop_reason is expected
    assert result.diagnostics.native_stop_reason == native
    assert result.error_class is None, "a stop reason is not a failure of the call"


async def test_a_length_cap_never_reports_as_complete() -> None:
    """The named case, asserted on the result rather than on the table.

    ``max_tokens`` caps thinking PLUS visible text, and recent models accept an over-budget
    request at validation time and stop mid-generation instead of erroring — so this stop
    reason is the ONLY evidence that the answer is truncated.
    """
    adapter, _ = adapter_streaming(text_stream(stop_reason="max_tokens"))
    _, result = await drain(adapter, [])

    assert result.stop_reason is StopReason.MAX_OUTPUT
    assert result.stop_reason is not StopReason.COMPLETE
    assert result.text, "the partial answer still comes back; it is simply not complete"


async def test_an_unknown_native_stop_reason_is_error_with_the_vendors_word_kept() -> None:
    """Anthropic adds stop reasons under its versioning policy — ``refusal`` and ``pause_turn``
    both arrived that way. A fall-through to ``COMPLETE`` would ship the next one as a finished
    answer, and the preserved native value is what tells us the vendor moved.
    """
    invented = "solar_flare_stop"
    assert invented not in STOP

    adapter, _ = adapter_streaming(text_stream(stop_reason=invented))
    _, result = await drain(adapter, [])

    assert result.stop_reason is StopReason.ERROR
    assert result.diagnostics.native_stop_reason == invented


async def test_a_refusal_terminates_the_turn_and_is_not_a_transport_failure() -> None:
    """Refusals arrive as HTTP 200. An adapter classifying on status files them as success and
    a router seeing empty text files them as a fault — either way the banned question gets
    asked again somewhere else and billed twice. ``REFUSAL`` is terminal in the fallback table.
    """
    events = [
        MessageStart(FakeUsage(input_tokens=88)),
        MessageDelta(stop_reason="refusal", usage=FakeUsage(input_tokens=None, output_tokens=3)),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    deltas, result = await drain(adapter, [])

    assert result.stop_reason is StopReason.REFUSAL
    assert deltas == []
    assert result.error_class is None, "a refusal is a 200; it is not a provider failure"
    # Input is still billed, and so is anything already streamed.
    assert result.usage.total_input_tokens == 88


# ── usage: cumulative totals and sibling cache buckets ────────────────────────


async def test_message_delta_usage_is_assigned_not_accumulated() -> None:
    """**The cumulative trap.** ``message_delta.usage`` carries running TOTALS.

    Four events whose ``output_tokens`` climbs 10 -> 25 -> 60 -> 140. The correct answer is the
    LAST value, 140. An adapter that accumulates reports 235 — and because the error grows with
    answer length it is invisible in a two-event fixture and produces a bill that is wrong by
    more the longer the answer is.
    """
    climbing = (10, 25, 60, 140)
    assert sum(climbing) != climbing[-1], "the fixture must be able to tell the two apart"

    events: list[Any] = [MessageStart(FakeUsage(input_tokens=120))]
    for running_total in climbing:
        events.append(ContentBlockDelta(TextDelta("word ")))
        events.append(MessageDelta(usage=FakeUsage(input_tokens=None, output_tokens=running_total)))
    events.append(MessageDelta(stop_reason="end_turn"))
    events.append(MessageStop())

    adapter, _ = adapter_streaming(events)
    _, result = await drain(adapter, [])

    assert result.usage.output_tokens == climbing[-1]
    assert result.usage.output_tokens != sum(climbing)


async def test_an_absent_input_field_on_message_delta_means_unchanged_not_zero() -> None:
    """``MessageDeltaUsage`` types every input field ``Optional`` and several models omit them.

    Reading an absent ``input_tokens`` as 0 discards the exact count ``message_start`` already
    gave us and turns a precisely-billed turn into a free one — with nothing to raise on,
    because zero is a perfectly valid token count.
    """
    events = [
        MessageStart(FakeUsage(input_tokens=4096, cache_read_input_tokens=200_000)),
        ContentBlockDelta(TextDelta("hi")),
        # No input fields at all on the final delta.
        MessageDelta(stop_reason="end_turn", usage=FakeUsage(input_tokens=None, output_tokens=12)),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    _, result = await drain(adapter, [])

    assert result.usage.input_tokens == 4096
    assert result.usage.cache_read_tokens == 200_000
    assert result.usage.output_tokens == 12
    assert result.usage.source == "provider_final"


async def test_cache_buckets_are_siblings_and_are_never_subtracted() -> None:
    """**The mirror image of the OpenAI bug.** Here the cached counts are SIBLINGS.

    A 200k-token cached document with a small question reports ``input_tokens: 512``. Reading
    that as "the input" under-bills by the entire prefix; "helpfully" subtracting the cache
    read out of it under-bills by even more. The mapping is a verbatim assignment, and billing
    reads ``total_input_tokens``.
    """
    billed_input = 512 + 200_000 + 1_024
    events = [
        MessageStart(
            FakeUsage(
                input_tokens=512,
                cache_read_input_tokens=200_000,
                cache_creation_input_tokens=1_024,
            )
        ),
        ContentBlockDelta(TextDelta("answer")),
        MessageDelta(
            stop_reason="end_turn",
            usage=FakeUsage(
                input_tokens=512,
                cache_read_input_tokens=200_000,
                cache_creation_input_tokens=1_024,
                output_tokens=64,
            ),
        ),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    _, result = await drain(adapter, [])

    assert result.usage.input_tokens == 512, "verbatim; nothing is subtracted out of it"
    assert result.usage.cache_read_tokens == 200_000
    assert result.usage.cache_write_tokens == 1_024
    # The vendor's own arithmetic, and what billing reads.
    assert result.usage.total_input_tokens == billed_input


async def test_reasoning_tokens_are_reported_even_when_no_trace_arrives() -> None:
    """``REASONING_TRACE`` is separate from ``REASONING`` for exactly this shape.

    Under the ``omitted`` display the thinking block opens, takes one ``signature_delta`` and
    closes with **no ``thinking_delta`` at all**, while the full trace is billed. A pane gated
    on ``REASONING`` alone renders blank forever and the cost line still climbs, so the deltas
    must be absent and ``reasoning_tokens`` must not be.
    """
    events = [
        MessageStart(FakeUsage(input_tokens=90)),
        ContentBlockDelta(SignatureDelta("EqQBCgIYAhIM..."), index=0),
        ContentBlockDelta(TextDelta("Fourteen days."), index=1),
        MessageDelta(
            stop_reason="end_turn",
            usage=FakeUsage(input_tokens=None, output_tokens=900, thinking_tokens=850),
        ),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    deltas, result = await drain(adapter, [])

    assert [d.kind for d in deltas] == ["text"], "a signature is an integrity token, not content"
    assert result.usage.reasoning_tokens == 850
    # Billed INSIDE output_tokens. Adding it would double-bill every thinking turn.
    assert result.usage.output_tokens == 900


async def test_a_present_trace_is_emitted_as_reasoning_deltas() -> None:
    """The other half: when ``display: "summarized"`` actually returns thinking, it streams."""
    events = [
        MessageStart(FakeUsage(input_tokens=90)),
        ContentBlockDelta(ThinkingDelta("The policy says "), index=0),
        ContentBlockDelta(ThinkingDelta("fourteen days."), index=0),
        ContentBlockDelta(TextDelta("Fourteen days."), index=1),
        MessageDelta(stop_reason="end_turn", usage=FakeUsage(input_tokens=None, output_tokens=40)),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    deltas, _ = await drain(adapter, [])

    assert [(d.kind, d.text) for d in deltas] == [
        ("reasoning", "The policy says "),
        ("reasoning", "fourteen days."),
        ("text", "Fourteen days."),
    ]


# ── streaming really streams, and each token is emitted once ──────────────────


async def test_tokens_are_emitted_as_they_arrive_rather_than_buffered() -> None:
    """The relay upstream measures time-to-first-token, so buffering is a defect, not a style.

    The log interleaves vendor productions with adapter emissions. A buffered implementation —
    one that drains the vendor iterator and then yields — produces every ``vendor`` entry
    before any ``adapter`` entry, which is why a single-chunk fixture cannot catch it.
    """
    log: list[tuple[str, str]] = []
    events = text_stream(chunks=("alpha ", "beta ", "gamma"))
    adapter, _ = adapter_streaming(events, log=log)

    async for event in adapter.stream(request_for(), caps_for(), SecretStr(API_KEY)):
        log.append(("adapter", "result" if isinstance(event, ChatResult) else event.text))

    assert log == [
        ("vendor", "message_start"),
        ("vendor", "content_block_start"),
        ("vendor", "content_block_delta:text_delta"),
        ("adapter", "alpha "),
        ("vendor", "content_block_delta:text_delta"),
        ("adapter", "beta "),
        ("vendor", "content_block_delta:text_delta"),
        ("adapter", "gamma"),
        ("vendor", "message_delta"),
        ("vendor", "message_stop"),
        ("adapter", "result"),
    ]
    first_emission = next(i for i, entry in enumerate(log) if entry[0] == "adapter")
    last_production = max(i for i, entry in enumerate(log) if entry[0] == "vendor")
    assert first_emission < last_production, "a buffered adapter inverts these two"


async def test_the_synthetic_helper_event_does_not_double_emit() -> None:
    """The SDK fires a raw ``content_block_delta`` AND a synthetic ``text`` event per token.

    Matching both renders every token twice in the widget, with no error, no usage discrepancy
    and no failing assertion anywhere else.
    """
    events = [
        MessageStart(FakeUsage(input_tokens=10)),
        ContentBlockDelta(TextDelta("once")),
        SyntheticTextEvent("once"),
        Ping(),
        MessageDelta(stop_reason="end_turn", usage=FakeUsage(input_tokens=None, output_tokens=1)),
        MessageStop(),
    ]
    adapter, _ = adapter_streaming(events)
    deltas, result = await drain(adapter, [])

    assert [d.text for d in deltas] == ["once"]
    assert result.text == "once"


async def test_first_token_ms_ignores_the_empty_content_block_start() -> None:
    """``content_block_start`` carries an empty text block, so TTFT timed from it is fiction."""
    adapter, _ = adapter_streaming(text_stream())
    deltas, result = await drain(adapter, [])

    assert result.first_token_ms is not None
    assert all(d.text for d in deltas), "an empty delta is never emitted"


# ── cancellation: exactly one terminal event, and Anthropic still bills exactly ─


async def test_cancel_after_message_start_yields_one_terminal_result_with_exact_input() -> None:
    """**The divergence worth exploiting, and the ``GeneratorExit`` trap beside it.**

    Every other vendor reports usage only in the final chunk, so a disconnect kills the reader
    before it arrives and a cancelled turn is ``estimated``. Anthropic's ``message_start``
    carries ``input_tokens`` up front, so the aborted turn still bills exactly — hence
    ``source="provider_partial"`` and not ``"estimated"``.

    ``athrow`` is the deterministic equivalent of a task cancellation: it raises
    ``CancelledError`` at the generator's current suspension point, exactly as the event loop
    does, and returns whatever the generator yields in response. Resuming afterwards must
    re-raise — an adapter that swallows the cancellation leaves the span unclosed and tells
    ASGI the generator finished normally.
    """
    adapter, _ = adapter_streaming(text_stream())
    stream = adapter.stream(request_for(), caps_for(), SecretStr(API_KEY))

    first = await anext(stream)
    assert isinstance(first, Delta)

    terminal = await stream.athrow(asyncio.CancelledError())

    assert isinstance(terminal, ChatResult)
    assert terminal.stop_reason is StopReason.CANCELLED
    assert terminal.error_class == ErrorClass.USER_CANCELLATION.value
    # EXACT, not estimated — the whole point of this vendor's cancellation path.
    assert terminal.usage.source == "provider_partial"
    assert terminal.usage.input_tokens == 120
    assert terminal.usage.total_input_tokens == 120
    assert terminal.text == first.text, "what was already streamed is still billed and returned"

    with pytest.raises(asyncio.CancelledError):
        await anext(stream)


async def test_cancelling_emits_exactly_one_terminal_event_and_no_second_one() -> None:
    """One terminal event per stream on every path, so usage finalization has one call site."""
    adapter, _ = adapter_streaming(text_stream())
    stream = adapter.stream(request_for(), caps_for(), SecretStr(API_KEY))

    await anext(stream)
    terminals = [await stream.athrow(asyncio.CancelledError())]
    with pytest.raises(asyncio.CancelledError):
        terminals.append(await anext(stream))

    assert len(terminals) == 1


async def test_closing_the_generator_mid_stream_raises_no_runtime_error() -> None:
    """The ``finally`` trap, asserted directly.

    ``aclose()`` throws ``GeneratorExit`` at the suspension point. A terminal ``ChatResult``
    yielded from a ``finally`` block raises ``RuntimeError: async generator ignored
    GeneratorExit`` there — and ASGI swallows it, so the only symptom in production is a
    missing usage row.
    """
    adapter, _ = adapter_streaming(text_stream())
    stream = adapter.stream(request_for(), caps_for(), SecretStr(API_KEY))

    await anext(stream)
    await stream.aclose()  # must not raise


# ── validation runs before the first byte ─────────────────────────────────────

#: One entry per optional field the adapter gates, with the flag that permits it. Driven from
#: here so an option added to ``validate()`` without a case is a visible omission.
UNSUPPORTED_CASES: Final[tuple[tuple[str, Capability, dict[str, Any]], ...]] = (
    ("temperature", Capability.SAMPLING, {"temperature": 0.7}),
    ("response_schema", Capability.STRUCTURED_OUTPUT, {"response_schema": {"type": "object"}}),
    (
        "images",
        Capability.IMAGE_INPUT,
        {"images": [{"media_type": "image/png", "data_b64": "aGk="}]},
    ),
    (
        "tools",
        Capability.TOOL_USE,
        {"tools": [{"name": "t", "description": "d", "parameters": {}}]},
    ),
    ("cache_hint", Capability.PROMPT_CACHING, {"cache_hint": "prefix"}),
    ("reasoning", Capability.REASONING, {"reasoning": ReasoningOption(effort="high")}),
)


@pytest.mark.parametrize(("option", "flag", "overrides"), UNSUPPORTED_CASES)
def test_an_unsupported_option_is_rejected_under_reject(
    option: str, flag: Capability, overrides: dict[str, Any]
) -> None:
    """``reject`` raises ``VALIDATION`` naming the option, before a byte goes out."""
    caps = caps_for(supported=FULL_CAPS - {flag}, on_unsupported="reject")
    with pytest.raises(KbError) as raised:
        AnthropicAdapter().validate(request_for(**overrides), caps)
    assert raised.value.error_class is ErrorClass.VALIDATION


@pytest.mark.parametrize(("option", "flag", "overrides"), UNSUPPORTED_CASES)
def test_an_unsupported_option_is_warned_under_warn_and_never_dropped(
    option: str, flag: Capability, overrides: dict[str, Any]
) -> None:
    """``warn`` strips the option and records it. The third path — dropping it quietly — is how
    a bot configured for structured output returns prose for a month with nothing in a log.
    """
    caps = caps_for(supported=FULL_CAPS - {flag}, on_unsupported="warn")
    adapter = AnthropicAdapter()
    req = request_for(**overrides)

    warnings = adapter.validate(req, caps)

    assert option in {w.option for w in warnings}
    assert all(w.action == "ignored" for w in warnings)
    # Stripped from the wire as well as reported — a warning about a field that was still sent
    # is the worse of the two failures.
    built = adapter._build(req, caps)
    assert option not in built


def test_budget_tokens_always_warns_and_never_reaches_the_payload() -> None:
    """The only wire form that carried a budget is a 400 on every model we ship, so this is a
    warning under ``reject`` as well: refusing the request would make a portable field unusable
    against this vendor rather than merely ignored.
    """
    caps = caps_for(on_unsupported="reject")
    adapter = AnthropicAdapter()
    req = request_for(reasoning=ReasoningOption(effort="high", budget_tokens=8_000))

    warnings = adapter.validate(req, caps)

    assert "reasoning.budget_tokens" in {w.option for w in warnings}
    assert "budget_tokens" not in repr(adapter._build(req, caps))


@pytest.mark.parametrize("effort", sorted(LOSSY_EFFORT))
def test_a_rounded_effort_level_warns_rather_than_rounding_in_silence(effort: str) -> None:
    """Both levels collapse UPWARD — disabled thinking is a 400 above effort ``high`` — and a
    level that silently becomes another level is a configuration the tenant chose and never got.
    """
    adapter = AnthropicAdapter()
    warnings = adapter.validate(request_for(reasoning=ReasoningOption(effort=effort)), caps_for())

    assert "reasoning.effort" in {w.option for w in warnings}
    assert EFFORT[effort] == "high"


def test_include_trace_without_reasoning_trace_warns_but_does_not_refuse() -> None:
    """A display preference must not cost the answer, and must not be silent either: the trace
    is billed in full whether or not it is returned.
    """
    caps = caps_for(supported=FULL_CAPS - {Capability.REASONING_TRACE}, on_unsupported="reject")
    adapter = AnthropicAdapter()
    req = request_for(reasoning=ReasoningOption(effort="high", include_trace=True))

    warnings = adapter.validate(req, caps)

    assert "reasoning.include_trace" in {w.option for w in warnings}
    assert adapter._build(req, caps)["thinking"]["display"] == "omitted"


async def test_validate_runs_before_the_first_byte_of_the_stream() -> None:
    """A rejection must not cost a provider call, so ``validate`` runs inside ``stream`` before
    the client is ever asked for a connection.
    """
    adapter, client = adapter_streaming(text_stream())
    caps = caps_for(supported=FULL_CAPS - {Capability.SAMPLING}, on_unsupported="reject")
    stream = adapter.stream(request_for(temperature=0.9), caps, SecretStr(API_KEY))

    with pytest.raises(KbError) as raised:
        await anext(stream)

    assert raised.value.error_class is ErrorClass.VALIDATION
    assert client.messages.calls == [], "no request was built and nothing went out"


# ── the request shape ─────────────────────────────────────────────────────────


def test_effort_lives_only_in_output_config_and_thinking_is_adaptive() -> None:
    """Top-level ``effort=`` is a ``TypeError`` from the SDK signature and a 400 on raw HTTP,
    and ``{"type": "enabled", "budget_tokens": N}`` is a 400 on every model we ship.
    """
    built = AnthropicAdapter()._build(
        request_for(reasoning=ReasoningOption(effort="xhigh")), caps_for()
    )

    assert "effort" not in built
    assert built["output_config"]["effort"] == "xhigh"
    assert built["thinking"] == {"type": "adaptive", "display": "omitted"}


def test_output_config_carries_both_effort_and_format() -> None:
    """``output_config`` is not a thinking-only object, so a structured-output request must
    extend it rather than replace it — replacing it drops the effort with no error.
    """
    schema = {"type": "object", "properties": {"answer": {"type": "string"}}}
    built = AnthropicAdapter()._build(
        request_for(reasoning=ReasoningOption(effort="max"), response_schema=schema), caps_for()
    )

    assert built["output_config"]["effort"] == "max"
    assert built["output_config"]["format"] == {"type": "json_schema", "schema": schema}


def test_the_cache_breakpoint_sits_on_the_last_system_block_and_not_on_evidence() -> None:
    """Evidence differs per question, so a breakpoint on it writes a fresh entry at ~1.25x every
    turn and reads none: hit rate 0%, cost up, no error anywhere.
    """
    built = AnthropicAdapter()._build(request_for(cache_hint="prefix"), caps_for())

    assert built["system"][-1]["cache_control"] == {"type": "ephemeral"}
    assert "cache_control" not in repr(built["messages"])


def test_retrieved_evidence_never_enters_the_system_slot() -> None:
    """Source text that reaches the instruction slot is prompt injection with our own retrieval
    pipeline as the delivery mechanism.
    """
    built = AnthropicAdapter()._build(request_for(), caps_for())

    assert EVIDENCE_TEXT not in repr(built["system"])
    assert EVIDENCE_TEXT in repr(built["messages"])
    assert built["system"] == [{"type": "text", "text": SYSTEM_PROMPT}]


def test_a_model_without_reasoning_gets_neither_thinking_nor_effort() -> None:
    """``output_config.effort`` errors on the pre-4.6 line, which is why ``claude-haiku-4-5`` is
    catalogued without the flag: omitting both is what makes the question not arise.
    """
    caps = caps_for(supported=FULL_CAPS - {Capability.REASONING}, on_unsupported="warn")
    built = AnthropicAdapter()._build(request_for(), caps)

    assert "thinking" not in built
    assert "output_config" not in built


def test_pinned_model_ids_are_explicit_rather_than_floating_aliases() -> None:
    """An alias reassignment changes answers, cost and capability flags with no diff in the repo
    and no error anywhere.
    """
    assert PINNED_MODELS
    assert all(not model.endswith(("-latest", "-preview")) for model in PINNED_MODELS)


# ── classification: the vendor's code first, the status only as a tiebreak ────


class FakeHttpResponse:
    def __init__(self, status_code: int, headers: dict[str, str]) -> None:
        self.status_code = status_code
        self.headers = headers


class FakeStatusError(Exception):
    """The shape ``anthropic.APIStatusError`` presents to a classifier.

    Hand-built rather than instantiated from the SDK because constructing the real class needs
    an ``httpx.Response`` with a live request attached; what is being asserted is the reading
    of ``.type``, ``.status_code``, ``.request_id`` and the headers, all of which are here.
    """

    def __init__(
        self,
        *,
        error_type: str | None,
        status_code: int,
        request_id: str | None = "req_01ERR",
        headers: dict[str, str] | None = None,
    ) -> None:
        super().__init__("vendor prose that must never be classified on")
        self.type = error_type
        self.status_code = status_code
        self.request_id = request_id
        self.response = FakeHttpResponse(status_code, headers or {})
        self.body = {"type": "error", "error": {"type": error_type, "message": "prose"}}


@pytest.mark.parametrize(("native", "expected"), sorted(ERROR_TYPE_TO_CLASS.items()))
def test_every_vendor_error_type_maps_to_its_class(native: str, expected: ErrorClass) -> None:
    """Parametrized from the adapter's own table, so a row added without a case is visible."""
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type=native, status_code=400), tokens_emitted=0
    )
    assert failure.error_class is expected
    assert failure.native_code == native
    assert failure.provider_request_id == "req_01ERR"


def test_a_mid_stream_overload_is_temporary_despite_reading_http_200() -> None:
    """**Why the type outranks the status.** An SSE ``event: error`` is raised against the
    ORIGINAL 200 response, so ``status_code`` reads 200 on exactly the capacity blip this split
    exists to call temporary. A status-first reading files it as our own defect and pages
    somebody.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type="overloaded_error", status_code=200), tokens_emitted=17
    )

    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert failure.tokens_emitted == 17


def test_an_exhausted_balance_is_billing_and_never_auth_or_rate_limit() -> None:
    """The retry and fallback policy is the same as ``provider_auth``, but the class is what a
    runbook and a dashboard key on — and an exhausted balance must not read as a revoked key.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type="billing_error", status_code=402), tokens_emitted=0
    )

    assert failure.error_class is ErrorClass.PROVIDER_BILLING
    assert failure.error_class is not ErrorClass.PROVIDER_AUTH
    assert not failure.retryable
    assert not failure.breaker_eligible, "one credential's balance must not open the breaker"


def test_an_unrecognised_model_is_permanent_and_falls_back_nowhere() -> None:
    """The model id came from the bot's own configuration snapshot, so falling back would serve
    every answer from a model the tenant never chose, at another price, with a Ready bot.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type="not_found_error", status_code=404), tokens_emitted=0
    )
    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


def test_an_unknown_error_type_falls_through_to_permanent_not_temporary() -> None:
    """Unknown is permanent: one visible failure, rather than an unbounded queue of retries
    against a request that will never succeed.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type="quantum_error", status_code=418), tokens_emitted=0
    )
    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


def test_the_type_outranks_the_status_when_the_two_disagree() -> None:
    """**The assertion that actually pins the precedence**, and it needed a fixture where the
    two tables disagree.

    A mid-stream ``overloaded_error`` is not enough on its own: it reads HTTP 200, and 200 is
    deliberately absent from the status table, so a status-first adapter falls through to the
    type and gets the right answer by accident. Every other pairing Anthropic ships agrees by
    construction (429 with ``rate_limit_error``, 402 with ``billing_error``, 404 with
    ``not_found_error``), which means a suite built only from those cannot tell the two
    orderings apart — measured, not assumed: the status-first mutation passed 66 of these
    tests before this one existed.

    The disagreement is a gateway rewriting the status while the vendor's body survives: a 502
    carrying ``invalid_request_error``. Status-first calls that capacity — retryable,
    fallback-eligible, an unbounded ladder against a request that can never succeed.
    Type-first calls it what the vendor said it was.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type="invalid_request_error", status_code=502), tokens_emitted=0
    )

    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST
    assert failure.error_class is not ErrorClass.PROVIDER_TEMPORARY
    assert not failure.retryable

    # And the reason the mid-stream case works at all: 200 is not a classification, so it is
    # not in the table. If it were ever added, the overload test above would start passing for
    # the wrong reason.
    assert 200 not in anthropic_module._STATUS_TO_CLASS


def test_the_status_is_the_tiebreak_when_the_body_carried_no_type() -> None:
    """A proxy 502 with an HTML body leaves ``.type`` empty; the status is all there is."""
    failure = AnthropicAdapter().classify(
        FakeStatusError(error_type=None, status_code=503), tokens_emitted=0
    )
    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY


def test_a_429_reads_its_backoff_floor_from_the_suffixed_reset_header() -> None:
    """**The suffix/prefix mistake, caught on a parsed number rather than on "did not raise".**

    Anthropic spells it ``anthropic-ratelimit-{bucket}-reset`` — bucket an infix, ``-reset`` a
    suffix — the mirror image of OpenAI's ``x-ratelimit-reset-{bucket}``. An adapter matching
    the OpenAI shape reads zero headers here and the symptom is a missing backoff floor, not an
    error: ``retry_after_seconds`` returns None and nothing else in the suite notices.
    """
    failure = AnthropicAdapter().classify(
        FakeStatusError(
            error_type="rate_limit_error",
            status_code=429,
            headers={"retry-after": "42"},
        ),
        tokens_emitted=0,
    )

    assert failure.error_class is ErrorClass.PROVIDER_RATE_LIMIT
    assert failure.retry_after == 42


def test_the_vendors_message_never_crosses_into_the_raised_error() -> None:
    """400 and 401 bodies echo request state, and on this service the request carries the packed
    prompt. What crosses is the closed ``error.type`` vocabulary plus our own sentence.
    """
    exc = FakeStatusError(error_type="invalid_request_error", status_code=400)
    failure = AnthropicAdapter().classify(exc, tokens_emitted=0)

    assert "vendor prose" not in failure.message
    assert failure.native_code == "invalid_request_error"


async def test_a_vendor_failure_still_terminates_the_stream_with_one_result() -> None:
    """The adapter classifies and absorbs; it never re-raises past a yield and never calls a
    second vendor itself. The terminal event IS the failure report.
    """

    class MidStreamOverload(anthropic_sdk.AnthropicError):
        type = "overloaded_error"
        status_code = 200
        request_id = "req_01MIDSTREAM"
        body: ClassVar[dict[str, Any]] = {"error": {"type": "overloaded_error"}}

    events = [MessageStart(FakeUsage(input_tokens=64)), ContentBlockDelta(TextDelta("half"))]
    adapter, _ = adapter_streaming(events, raises=MidStreamOverload("prose"))
    deltas, result = await drain(adapter, [])

    assert [d.text for d in deltas] == ["half"]
    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.provider_request_id == "req_01MIDSTREAM"
    # Everything already streamed is still billed, and the input count is still exact.
    assert result.text == "half"
    assert result.usage.input_tokens == 64


# ── the credential and the prompt stop here ───────────────────────────────────


async def test_diagnostics_carries_no_prompt_no_evidence_and_no_credential() -> None:
    """``Diagnostics`` is rendered into a conversation panel and a span, so a vendor detail that
    leaked the packed prompt would leak tenant document text with it.
    """
    headers = {
        "request-id": "req_01FIXTURE",
        f"{RATE_LIMIT_HEADER_PREFIX}requests-remaining": "999",
        f"{RATE_LIMIT_HEADER_PREFIX}tokens-reset": "2026-08-26T12:00:00Z",
        # Must NOT be captured: it identifies the account rather than the limit.
        "anthropic-organization-id": "org_secret",
        "x-api-key": API_KEY,
    }
    adapter, _ = adapter_streaming(text_stream(), headers=headers)
    _, result = await drain(adapter, [])

    payload = result.diagnostics.model_dump_json()
    assert SYSTEM_PROMPT not in payload
    assert EVIDENCE_TEXT not in payload
    assert API_KEY not in payload
    assert "org_secret" not in payload
    # The tenant-safe half is still captured — on the 200, not only on a 429.
    assert result.diagnostics.rate_limit == {
        f"{RATE_LIMIT_HEADER_PREFIX}requests-remaining": "999",
        f"{RATE_LIMIT_HEADER_PREFIX}tokens-reset": "2026-08-26T12:00:00Z",
    }


async def test_the_request_id_comes_off_the_unprefixed_header() -> None:
    """``request-id``, with no ``x-`` — OpenAI spells it the other way, and it is the only handle
    Anthropic support accepts.
    """
    adapter, _ = adapter_streaming(text_stream(), request_id="req_01FIXTURE")
    _, result = await drain(adapter, [])
    assert result.provider_request_id == "req_01FIXTURE"


async def test_the_client_is_built_with_max_retries_zero_and_one_secret_read() -> None:
    """The SDK default is 2, invisible inside one ``await``: three tiers times three attempts is
    27 provider calls from one click, 26 of which never appear in our own spans.

    The ``get_secret_value()`` count is asserted on the module source because the guarantee is
    that extracting the key is a single greppable act rather than an accident of serialization.
    """
    client = AnthropicAdapter()._client(SecretStr(API_KEY))
    try:
        assert client.max_retries == 0
    finally:
        await client.close()

    source = inspect.getsource(anthropic_module)
    assert source.count("get_secret_value()") == 1


# ── the static capability gate ────────────────────────────────────────────────


def test_the_adapter_defines_neither_embed_nor_rerank() -> None:
    """**Absence of the method is the gate**, and it is stronger than a flag: a flag can be set
    to True by an edit that changes no behaviour.

    Anthropic says so in its own words — "Anthropic does not offer its own embedding model" —
    and publishes no ranking route. This is the sharp end of finding C1: an organization whose
    only connection is Anthropic cannot ingest a single document, because every chunk must be
    embedded before it can be indexed and there is no degraded path.
    """
    adapter = AnthropicAdapter()
    for method in ("embed", "rerank", "validate_embedding", "validate_rerank"):
        assert not hasattr(adapter, method), (
            f"AnthropicAdapter must not define {method}: the matrix in capabilities.py and the "
            "absence of the method are asserted against each other, and neither may move alone"
        )

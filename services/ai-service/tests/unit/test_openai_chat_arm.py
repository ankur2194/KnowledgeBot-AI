"""The OpenAI chat arm — Responses translation, usage, stop reasons and the terminal event.

Nothing here touches a network. The SDK client is replaced at the one seam built for it,
``OpenAIAdapter._client``, which is also the test of whether that seam is where it should be.

**The `unit/` README says this tier may not assert "anything about a stream", and that rule is
about TRANSPORT.** There is none here: the fake below is an in-process async generator, and
every assertion is about event *translation* — which Responses event becomes which ``Delta``,
which terminal status becomes which ``StopReason``, and what the one ``ChatResult`` carries.
Whether an SSE frame survives Uvicorn, Traefik and PHP is a different question and belongs to
the live-server tier.

Four failures shape the file, and each is silent in its own way:

* **A truncated answer reported as COMPLETE.** The user reads a confident half-sentence and
  nothing errors anywhere. In Responses shape the trap is ``status: "incomplete"`` with an
  ``incomplete_details`` that has been observed arriving EMPTY while the output was genuinely
  truncated, so the table below includes that row on purpose.
* **A refusal filed as a successful empty answer.** It arrives as HTTP 200, so the router sees
  no text, falls back, and re-asks the banned question on another vendor — billed twice.
* **A cancelled turn with no usage row.** Yielding the terminal event from ``finally`` raises
  ``RuntimeError`` during ``GeneratorExit``, ASGI swallows it, and OpenAI still bills.
* **Cached tokens counted twice or not at all.** ``cached_tokens`` is a SUBSET of
  ``input_tokens`` here and a SIBLING on Anthropic; the same arithmetic cannot serve both.
"""

from __future__ import annotations

import asyncio
import hashlib
from types import SimpleNamespace
from typing import Any, Final

import pytest
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.contract import (
    Capability,
    ChatRequest,
    ChatResult,
    ContextBlock,
    Delta,
    ImageInput,
    Message,
    ModelCapabilities,
    ReasoningOption,
    StopReason,
    ToolDef,
)
from app.providers.openai_adapter import (
    CONTEXT_BLOCK_TEMPLATE,
    DELTA_EVENTS,
    PINNED_MODELS,
    RATE_LIMIT_HEADERS,
    SAFETY_IDENTIFIER_LENGTH,
    STRICT_MAX_DEPTH,
    STRUCTURED_OUTPUT_NAME,
    TERMINAL_EVENTS,
    OpenAIAdapter,
)

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BT"
CONNECTION: Final = "01JQZ0000000000000000000CN"
MODEL: Final = PINNED_MODELS[0]
KEY: Final = SecretStr("sk-fixture-not-a-real-key")

#: The one string every redaction assertion greps for. It is the bot instruction, so if it ever
#: appears in a `Diagnostics` payload the leak is tenant configuration; the context block below
#: is the sharper half, because that is retrieved document text.
SYSTEM_PROMPT: Final = "SYSTEM-CANARY-you-are-a-support-bot-for-Acme"
CONTEXT_TEXT: Final = "CONTEXT-CANARY-the-refund-window-is-thirty-days"

CHAT_FLAGS: Final = frozenset(
    {
        Capability.TEXT,
        Capability.STREAM_USAGE,
        Capability.PROMPT_CACHING,
    }
)


# ── recorded wire fixtures ────────────────────────────────────────────────────
#
# Dicts in the vendor's own shape, converted to attribute objects by `wire()`. Written this way
# rather than as hand-set attributes so the fixture is the payload and not a description of one:
# a field the adapter reads by a name OpenAI does not use fails here instead of in production.

#: A `response.completed` payload with a warm cache and a reasoning model's token details.
#: `input_tokens` is the vendor's own BILLED input and `cached_tokens` sits INSIDE it.
COMPLETED_WITH_CACHE: Final[dict[str, Any]] = {
    "type": "response.completed",
    "response": {
        "id": "resp_fixture",
        "model": "gpt-5.6-sol",
        "status": "completed",
        "incomplete_details": None,
        "error": None,
        "output": [
            {
                "type": "message",
                "role": "assistant",
                "content": [{"type": "output_text", "text": "Thirty days."}],
            }
        ],
        "usage": {
            "input_tokens": 12_480,
            "input_tokens_details": {"cached_tokens": 11_264},
            "output_tokens": 512,
            "output_tokens_details": {"reasoning_tokens": 384},
            "total_tokens": 12_992,
        },
    },
}

#: The refusal shape: HTTP 200, `status: "completed"`, and a `refusal` content part. Classifying
#: on the status alone marks this a successful empty answer.
COMPLETED_WITH_REFUSAL: Final[dict[str, Any]] = {
    "type": "response.completed",
    "response": {
        "id": "resp_refusal",
        "model": "gpt-5.6-sol",
        "status": "completed",
        "incomplete_details": None,
        "error": None,
        "output": [
            {
                "type": "message",
                "role": "assistant",
                "content": [{"type": "refusal", "refusal": "I can't help with that."}],
            }
        ],
        "usage": {
            "input_tokens": 90,
            "input_tokens_details": {"cached_tokens": 0},
            "output_tokens": 8,
            "output_tokens_details": {"reasoning_tokens": 0},
            "total_tokens": 98,
        },
    },
}

TEXT_DELTA: Final[dict[str, Any]] = {
    "type": "response.output_text.delta",
    "delta": "Thirty ",
    "output_index": 0,
}


def wire(payload: Any) -> Any:
    """Turn a recorded wire dict into the attribute object the SDK would hand the adapter."""
    if isinstance(payload, dict):
        return SimpleNamespace(**{key: wire(value) for key, value in payload.items()})
    if isinstance(payload, list):
        return [wire(item) for item in payload]
    return payload


def terminal(status: str, **overrides: Any) -> Any:
    """One terminal event, defaulted to the completed fixture and overridden per row."""
    response: dict[str, Any] = {
        "id": "resp_fixture",
        "model": "gpt-5.6-sol",
        "status": status,
        "incomplete_details": None,
        "error": None,
        "output": [],
        "usage": None,
    }
    response.update(overrides)
    event = "response.failed" if status == "failed" else "response.completed"
    if status == "incomplete":
        event = "response.incomplete"
    return wire({"type": event, "response": response})


# ── the fake client ───────────────────────────────────────────────────────────


class FakeStream:
    """An SSE stream that arrives in more than one chunk, over time.

    ``await asyncio.sleep(0)`` before each event is the load-bearing line. A single-chunk
    fixture passes against a buffered implementation, which is the exact defect the streaming
    tests exist to catch; yielding to the loop between events lets a consumer observe how many
    events had been pulled at the moment it received its first ``Delta``.
    """

    def __init__(
        self,
        events: list[Any],
        headers: dict[str, str],
        *,
        fail_with: BaseException | None = None,
    ) -> None:
        self.response = SimpleNamespace(headers=headers)
        self._events = events
        self._fail_with = fail_with
        #: Every event this stream has actually handed out, in order.
        self.delivered: list[Any] = []

    async def __aiter__(self) -> Any:
        for event in self._events:
            await asyncio.sleep(0)
            self.delivered.append(event)
            yield event
        if self._fail_with is not None:
            await asyncio.sleep(0)
            raise self._fail_with


class FakeClient:
    def __init__(self, stream: FakeStream | BaseException) -> None:
        self._stream = stream
        self.calls: list[dict[str, Any]] = []
        outer = self

        class _Responses:
            async def create(self, **kwargs: Any) -> FakeStream:
                outer.calls.append(kwargs)
                if isinstance(outer._stream, BaseException):
                    raise outer._stream
                return outer._stream

        self.responses = _Responses()


def adapter_streaming(
    events: list[Any],
    *,
    headers: dict[str, str] | None = None,
    fail_with: BaseException | None = None,
) -> tuple[OpenAIAdapter, FakeClient, FakeStream]:
    stream = FakeStream(
        events,
        headers if headers is not None else {"x-request-id": "req_chat_abc"},
        fail_with=fail_with,
    )
    client = FakeClient(stream)
    adapter = OpenAIAdapter()
    adapter._client = lambda credential: client  # type: ignore[method-assign]
    return adapter, client, stream


def adapter_raising(exc: BaseException) -> tuple[OpenAIAdapter, FakeClient]:
    client = FakeClient(exc)
    adapter = OpenAIAdapter()
    adapter._client = lambda credential: client  # type: ignore[method-assign]
    return adapter, client


class FakeStatusError(Exception):
    """The SDK exception shape ``classify`` reads: a status, a body code, a request id."""

    def __init__(self, status: int, code: str | None) -> None:
        super().__init__("vendor message that must never be relayed")
        self.status_code = status
        self.body = {"error": {"code": code}} if code else {}
        self.request_id = "req_err"


def caps_for(
    *,
    supported: frozenset[Capability] = CHAT_FLAGS,
    on_unsupported: str = "reject",
    max_output_tokens: int = 128_000,
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=1_050_000,
        max_output_tokens=max_output_tokens,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(**overrides: Any) -> ChatRequest:
    body: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": "trace-1",
        "provider_connection_id": CONNECTION,
        "model": MODEL,
        "system": SYSTEM_PROMPT,
        "messages": [Message(role="user", content="How long is the refund window?")],
        "context_blocks": [
            ContextBlock(
                index=0,
                chunk_id="01JQZ0000000000000000000CH",
                title="Refund policy",
                text=CONTEXT_TEXT,
            )
        ],
        "max_output_tokens": 2048,
    }
    body.update(overrides)
    return ChatRequest(**body)


async def drain(adapter: OpenAIAdapter, req: ChatRequest, caps: ModelCapabilities) -> list[Any]:
    return [event async for event in adapter.stream(req, caps, KEY)]


def only_result(events: list[Any]) -> ChatResult:
    """Exactly one terminal event, on every path. Asserted here so every caller gets it free."""
    results = [event for event in events if isinstance(event, ChatResult)]
    assert len(results) == 1, f"expected exactly one terminal ChatResult, got {len(results)}"
    return results[0]


# ── stop reasons: no length cap may reach COMPLETE ────────────────────────────

#: Every terminal shape this vendor produces, and what it must map to. The rows carrying a
#: length cap are marked so the assertion below can name them rather than restating them.
STOP_ROWS: Final[tuple[tuple[str, Any, StopReason, bool], ...]] = (
    (
        "completed",
        terminal("completed"),
        StopReason.COMPLETE,
        False,
    ),
    (
        "incomplete/max_output_tokens",
        terminal("incomplete", incomplete_details={"reason": "max_output_tokens"}),
        StopReason.MAX_OUTPUT,
        True,
    ),
    (
        "incomplete/EMPTY incomplete_details",
        terminal("incomplete", incomplete_details=None),
        StopReason.MAX_OUTPUT,
        True,
    ),
    (
        "incomplete/details present, reason absent",
        terminal("incomplete", incomplete_details={"reason": None}),
        StopReason.MAX_OUTPUT,
        True,
    ),
    (
        "incomplete/content_filter",
        terminal("incomplete", incomplete_details={"reason": "content_filter"}),
        StopReason.REFUSAL,
        False,
    ),
    (
        "completed + refusal content part",
        wire(COMPLETED_WITH_REFUSAL),
        StopReason.REFUSAL,
        False,
    ),
    (
        "completed + function_call item",
        terminal("completed", output=[{"type": "function_call", "name": "lookup_order"}]),
        StopReason.TOOL_USE,
        False,
    ),
    (
        "failed",
        terminal("failed", error={"code": "server_error", "message": "x"}),
        StopReason.ERROR,
        False,
    ),
    ("cancelled", terminal("cancelled"), StopReason.ERROR, False),
    ("queued", terminal("queued"), StopReason.ERROR, False),
    ("in_progress", terminal("in_progress"), StopReason.ERROR, False),
    ("a status OpenAI has not shipped yet", terminal("throttled_by_tier"), StopReason.ERROR, False),
)


@pytest.mark.parametrize(
    ("label", "event", "expected"),
    [(label, event, expected) for label, event, expected, _ in STOP_ROWS],
    ids=[row[0] for row in STOP_ROWS],
)
def test_every_terminal_shape_maps_to_its_stop_reason(
    label: str, event: Any, expected: StopReason
) -> None:
    assert OpenAIAdapter._stop(event.response) is expected


def test_no_length_cap_reaches_complete() -> None:
    """The single worst failure this layer can cause, asserted over the whole table.

    A length cap presented as COMPLETE is a confident half-sentence with no error anywhere. The
    `EMPTY incomplete_details` row is the one that catches a reader who decided truncation from
    the presence of a reason rather than from the status.
    """
    capped = {
        label: OpenAIAdapter._stop(event.response) for label, event, _, cap in STOP_ROWS if cap
    }
    assert capped, "the table lost its length-cap rows"
    assert set(capped.values()) == {StopReason.MAX_OUTPUT}, capped
    assert StopReason.COMPLETE not in capped.values()


def test_only_a_completed_status_can_reach_complete() -> None:
    """Stated as a property over the table rather than as one row, so a status added later
    cannot quietly acquire COMPLETE by resembling one that has it."""
    for label, event, expected, _ in STOP_ROWS:
        if expected is StopReason.COMPLETE:
            assert event.response.status == "completed", label


async def test_an_unknown_native_status_maps_to_error_with_the_vendors_word_preserved() -> None:
    """Vendors add stop values under their own versioning policy and without notice.
    ``native_stop_reason`` is how we find out; a fall-through to COMPLETE is how we do not."""
    adapter, _, _ = adapter_streaming([terminal("throttled_by_tier")])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.ERROR
    assert result.diagnostics.native_stop_reason == "throttled_by_tier"
    assert result.error_class == ErrorClass.PROVIDER_PERMANENT_REQUEST.value


async def test_a_refusal_is_a_refusal_and_not_an_empty_success() -> None:
    """HTTP 200 with a ``refusal`` content part. Filed as success, the router sees empty text,
    falls back, and re-asks the banned question on another vendor at a second bill."""
    adapter, _, _ = adapter_streaming([wire(COMPLETED_WITH_REFUSAL)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.REFUSAL
    assert result.error_class is None


# ── usage: buckets disjoint, total equal to the vendor's billed input ─────────


def test_usage_buckets_are_disjoint_and_sum_to_the_vendors_billed_input() -> None:
    """``cached_tokens`` is "part of the total input_tokens count" in OpenAI's own words, so the
    adapter subtracts. Anthropic's are siblings and the same step there is an assignment;
    guessing the direction on a 200k-token cached document is a five-figure reporting error."""
    billed = COMPLETED_WITH_CACHE["response"]["usage"]
    usage = OpenAIAdapter._usage(wire(billed))

    assert usage.cache_read_tokens == billed["input_tokens_details"]["cached_tokens"]
    assert usage.input_tokens == billed["input_tokens"] - usage.cache_read_tokens
    # THE ONE THAT MATTERS: billing reads `total_input_tokens`, never `input_tokens`.
    assert usage.total_input_tokens == billed["input_tokens"]
    # Disjoint: the read bucket is not also inside the input bucket.
    assert usage.input_tokens + usage.cache_read_tokens == billed["input_tokens"]
    assert usage.source == "provider_final"


def test_reasoning_tokens_are_reported_inside_output_and_never_added_to_it() -> None:
    billed = COMPLETED_WITH_CACHE["response"]["usage"]
    usage = OpenAIAdapter._usage(wire(billed))
    assert usage.output_tokens == billed["output_tokens"]
    assert usage.reasoning_tokens == billed["output_tokens_details"]["reasoning_tokens"]
    assert usage.reasoning_tokens <= usage.output_tokens


def test_a_response_reporting_no_write_amount_leaves_the_write_bucket_empty() -> None:
    """The pre-2.53.0 shape, and still what most responses look like: no ``cache_write_tokens``
    key at all. The bucket stays 0 and nothing is synthesized — a made-up number would be
    indistinguishable from a measurement."""
    billed = COMPLETED_WITH_CACHE["response"]["usage"]
    assert "cache_write_tokens" not in billed["input_tokens_details"]
    usage = OpenAIAdapter._usage(wire(billed))
    assert usage.cache_write_tokens == 0
    assert usage.total_input_tokens == billed["input_tokens"]


def test_a_reported_write_amount_is_attributed_and_the_three_buckets_partition_the_bill() -> None:
    """`openai==2.53.0` reports `cache_write_tokens` inside `InputTokensDetails`, a container the
    SDK documents as "a detailed breakdown of the input tokens" — the subset reading. When it
    holds, uncached + read + write is the billed input EXACTLY, with nothing left over."""
    billed = {
        "input_tokens": 12_480,
        "input_tokens_details": {"cached_tokens": 8_000, "cache_write_tokens": 3_200},
        "output_tokens": 512,
        "output_tokens_details": {"reasoning_tokens": 0},
    }
    usage = OpenAIAdapter._usage(wire(billed))
    assert usage.cache_read_tokens == 8_000
    assert usage.cache_write_tokens == 3_200
    assert usage.input_tokens == 12_480 - 8_000 - 3_200
    assert usage.total_input_tokens == billed["input_tokens"]


def test_a_write_amount_that_cannot_be_a_subset_falls_back_instead_of_under_billing() -> None:
    """The whole reason the arithmetic is derived rather than assumed. If `cache_write_tokens`
    turns out to be a SIBLING of `input_tokens` rather than a component of it, subtracting it
    would report a `total_input_tokens` smaller than the vendor billed — the quiet direction.
    Here cached + written exceeds the input, which the subset reading makes impossible, so the
    method drops back to the reading that has always been verified. The bill stays exact and
    only the attribution degrades."""
    billed = {
        "input_tokens": 10_000,
        "input_tokens_details": {"cached_tokens": 8_000, "cache_write_tokens": 5_000},
        "output_tokens": 4,
        "output_tokens_details": {"reasoning_tokens": 0},
    }
    usage = OpenAIAdapter._usage(wire(billed))
    assert usage.cache_write_tokens == 0, "writes stay folded into uncached input"
    assert usage.cache_read_tokens == 8_000
    assert usage.total_input_tokens == billed["input_tokens"], "never smaller than the bill"


def test_an_absent_usage_block_degrades_attribution_and_never_the_bill() -> None:
    assert OpenAIAdapter._usage(None).source == "estimated"
    assert OpenAIAdapter._usage(wire({"input_tokens": None, "output_tokens": 3})).source == (
        "estimated"
    )


def test_a_cached_count_larger_than_the_input_never_produces_a_negative_bucket() -> None:
    """A vendor bug, floored rather than corrected: a negative bucket would make
    ``total_input_tokens`` SMALLER than the billed input, which is the quiet direction."""
    usage = OpenAIAdapter._usage(
        wire(
            {
                "input_tokens": 10,
                "input_tokens_details": {"cached_tokens": 25},
                "output_tokens": 1,
                "output_tokens_details": {"reasoning_tokens": 0},
            }
        )
    )
    assert usage.input_tokens == 0


# ── the stream: deltas first, one terminal event, never buffered ─────────────


async def test_deltas_reach_the_consumer_before_the_stream_has_been_drained() -> None:
    """A buffered implementation "works" and is a defect: the Laravel relay measures
    time-to-first-token. The fixture arrives in several chunks over time, so the count of events
    the fake has handed out at the moment the first ``Delta`` lands is the evidence."""
    events = [wire(TEXT_DELTA), wire(TEXT_DELTA), wire(COMPLETED_WITH_CACHE)]
    adapter, _, stream = adapter_streaming(events)

    generator = adapter.stream(request_for(), caps_for(), KEY)
    first = await generator.__anext__()

    assert isinstance(first, Delta)
    assert len(stream.delivered) == 1, (
        "the whole stream had been pulled before the first Delta was yielded — that is a "
        "buffered implementation, and it reports a first-token time it did not achieve"
    )
    await generator.aclose()


async def test_every_translated_event_kind_reaches_the_consumer() -> None:
    """The translation table is the code's, not this test's: a kind added to ``DELTA_EVENTS``
    without a branch to carry it fails here."""
    events = [
        wire({"type": event_type, "delta": f"<{kind}>", "output_index": 0})
        for event_type, kind in DELTA_EVENTS.items()
    ]
    adapter, _, _ = adapter_streaming([*events, wire(COMPLETED_WITH_CACHE)])
    observed = await drain(adapter, request_for(), caps_for())
    deltas = [event for event in observed if isinstance(event, Delta)]
    assert [delta.kind for delta in deltas] == list(DELTA_EVENTS.values())


async def test_only_text_deltas_become_the_answer_text() -> None:
    """Reasoning summaries and tool arguments are billed and displayed, and neither is the
    answer. Folding them into ``text`` puts the chain of thought in the citation validator."""
    events = [
        wire(
            {
                "type": "response.reasoning_summary_text.delta",
                "delta": "thinking",
                "output_index": 0,
            }
        ),
        wire(TEXT_DELTA),
        wire(COMPLETED_WITH_CACHE),
    ]
    adapter, _, _ = adapter_streaming(events)
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.text == TEXT_DELTA["delta"]


async def test_an_empty_leading_delta_does_not_start_the_first_token_clock() -> None:
    """Empty deltas are common at the head of a Responses stream. Timing from one reports a
    first token that carried nothing, which flatters every TTFT dashboard we own."""
    events = [
        wire({"type": "response.output_text.delta", "delta": "", "output_index": 0}),
        wire(TEXT_DELTA),
        wire(COMPLETED_WITH_CACHE),
    ]
    adapter, _, _ = adapter_streaming(events)
    observed = await drain(adapter, request_for(), caps_for())
    assert [event for event in observed if isinstance(event, Delta)] != []
    assert len([event for event in observed if isinstance(event, Delta)]) == 1


async def test_a_successful_stream_ends_with_exactly_one_terminal_result() -> None:
    adapter, _, _ = adapter_streaming([wire(TEXT_DELTA), wire(COMPLETED_WITH_CACHE)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.COMPLETE
    assert result.usage.source == "provider_final"
    assert result.first_token_ms is not None
    assert result.diagnostics.extras["response_model"] == "gpt-5.6-sol"


def test_the_terminal_event_set_is_the_modules_and_excludes_the_in_progress_events() -> None:
    """``response.created`` and ``response.in_progress`` also carry a ``response`` object, with
    ``status: "in_progress"`` and no usage. Matching on the attribute rather than on this tuple
    would read a stop reason off the first event of every stream."""
    assert "response.created" not in TERMINAL_EVENTS
    assert "response.in_progress" not in TERMINAL_EVENTS
    assert set(TERMINAL_EVENTS).isdisjoint(DELTA_EVENTS)


# ── cancellation ──────────────────────────────────────────────────────────────


async def test_cancellation_yields_one_terminal_result_and_then_re_raises() -> None:
    """The terminal event comes from ``except asyncio.CancelledError`` and never from
    ``finally``: an async generator that yields while ``GeneratorExit`` unwinds raises
    ``RuntimeError``, ASGI swallows it, and the only symptom is a missing usage row for a turn
    OpenAI billed in full."""
    events = [wire(TEXT_DELTA), wire(TEXT_DELTA)]
    adapter, _, _ = adapter_streaming(events, fail_with=asyncio.CancelledError())

    observed: list[Any] = []
    with pytest.raises(asyncio.CancelledError):
        async for event in adapter.stream(request_for(), caps_for(), KEY):
            observed.append(event)

    result = only_result(observed)
    assert result.stop_reason is StopReason.CANCELLED
    assert result.error_class == ErrorClass.USER_CANCELLATION.value
    # NEVER `provider_final`: usage arrives only in the terminal event on this vendor, so a
    # cancelled turn has no exact input count and must not be aggregated into invoiced cost.
    assert result.usage.source != "provider_final"
    assert result.usage.source == "estimated"


async def test_a_cancelled_turn_still_bills_what_it_emitted() -> None:
    """A ``Usage()`` of zeros looks exactly like a free call, which is how billing silently
    under-counts every abandoned turn."""
    events = [wire(TEXT_DELTA), wire(TEXT_DELTA), wire(TEXT_DELTA)]
    adapter, _, _ = adapter_streaming(events, fail_with=asyncio.CancelledError())

    observed: list[Any] = []
    with pytest.raises(asyncio.CancelledError):
        async for event in adapter.stream(request_for(), caps_for(), KEY):
            observed.append(event)

    result = only_result(observed)
    assert result.usage.output_tokens == len(events)
    assert result.text == TEXT_DELTA["delta"] * len(events)


async def test_the_request_id_survives_a_stream_that_dies_mid_flight() -> None:
    """``x-request-id`` is reachable only from the stream object's headers and only before the
    first iteration. It is missing on precisely the failures vendor support asks about."""
    adapter, _, _ = adapter_streaming([wire(TEXT_DELTA)], fail_with=asyncio.CancelledError())
    observed: list[Any] = []
    with pytest.raises(asyncio.CancelledError):
        async for event in adapter.stream(request_for(), caps_for(), KEY):
            observed.append(event)
    assert only_result(observed).provider_request_id == "req_chat_abc"


# ── failures still terminate the stream exactly once ─────────────────────────


async def test_a_failure_before_the_first_byte_terminates_with_one_classified_result() -> None:
    adapter, _ = adapter_raising(FakeStatusError(429, "insufficient_quota"))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_BILLING.value
    assert result.provider_request_id == "req_err"
    assert result.usage.source == "estimated"


async def test_a_mid_stream_failure_keeps_the_text_already_emitted() -> None:
    """A mid-stream failure still bills input plus everything already streamed, and the router
    reads ``text`` to learn a retry would re-bill a partial completion."""
    adapter, _, _ = adapter_streaming(
        [wire(TEXT_DELTA), wire(TEXT_DELTA)], fail_with=FakeStatusError(500, "server_error")
    )
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.text == TEXT_DELTA["delta"] * 2
    assert result.usage.output_tokens == 2


async def test_the_vendors_message_never_reaches_the_terminal_event() -> None:
    """401 and 422 bodies routinely echo the request, and the request carries the packed
    prompt."""
    adapter, _ = adapter_raising(FakeStatusError(401, None))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert "vendor message" not in result.model_dump_json()


# ── the request body: what is never sent is the point ────────────────────────


async def sent_body(**overrides: Any) -> dict[str, Any]:
    caps = overrides.pop("caps", caps_for())
    adapter, client, _ = adapter_streaming([wire(COMPLETED_WITH_CACHE)])
    await drain(adapter, request_for(**overrides), caps)
    return client.calls[0]


async def test_the_mandatory_fields_are_on_every_request() -> None:
    body = await sent_body()
    assert body["store"] is False
    assert body["truncation"] == "disabled"
    assert body["stream"] is True
    assert body["prompt_cache_key"] == f"kb:{BOT}"
    assert body["max_output_tokens"] == 2048


async def test_the_safety_identifier_is_a_one_way_hash_of_the_org() -> None:
    """An abuse-signal handle and nothing more. An email, a user id or an org id in the clear
    would leave our boundary as plaintext."""
    body = await sent_body()
    assert (
        body["safety_identifier"]
        == hashlib.sha256(ORG.encode()).hexdigest()[:SAFETY_IDENTIFIER_LENGTH]
    )
    assert ORG not in body["safety_identifier"]


async def test_the_fields_that_break_cancellation_or_move_state_are_never_sent() -> None:
    """``background=True`` detaches generation from the connection, so cancelling stops nothing
    and the spend continues after the tab closes. ``previous_response_id`` and ``conversation``
    move conversation state to OpenAI, and PostgreSQL is the source of truth."""
    body = await sent_body()
    assert not {"background", "previous_response_id", "conversation"} & body.keys()


async def test_retrieved_evidence_is_a_user_data_part_and_never_the_instruction() -> None:
    """Source text that can reach the instruction slot is prompt injection with our own
    retrieval pipeline as the delivery mechanism."""
    body = await sent_body()
    assert body["instructions"] == SYSTEM_PROMPT
    assert CONTEXT_TEXT not in body["instructions"]
    evidence = body["input"][0]
    assert evidence["role"] == "user"
    assert evidence["content"][0]["text"] == CONTEXT_BLOCK_TEMPLATE.format(
        index=0, title="Refund policy", text=CONTEXT_TEXT
    )


async def test_evidence_precedes_the_conversation_so_the_cacheable_prefix_is_stable() -> None:
    """Prefix caching here is exact string matching. A re-ordered evidence list is a full cache
    miss that costs roughly ten times as much and reports as a perfectly normal request."""
    body = await sent_body()
    roles = [item["role"] for item in body["input"]]
    assert roles == ["user", "user"]
    assert CONTEXT_TEXT in body["input"][0]["content"][0]["text"]
    assert body["input"][-1]["content"][0]["text"] == "How long is the refund window?"


async def test_an_assistant_turn_is_sent_as_a_plain_string() -> None:
    """The list form of an input message accepts ``input_*`` parts only; ``output_text`` belongs
    to an item shape that also needs an ``id`` and a ``status`` we do not have for a turn
    replayed out of PostgreSQL."""
    body = await sent_body(
        messages=[
            Message(role="user", content="hello"),
            Message(role="assistant", content="hi"),
            Message(role="user", content="again"),
        ]
    )
    assistant = [item for item in body["input"] if item["role"] == "assistant"]
    assert assistant == [{"role": "assistant", "content": "hi"}]


async def test_images_ride_on_the_last_user_turn_as_base64_data_urls() -> None:
    """``ImageInput`` has deliberately no URL form: a URL the vendor fetches is a
    tenant-supplied fetch we do not perform and therefore cannot guard."""
    body = await sent_body(
        images=[ImageInput(media_type="image/png", data_b64="AAAA")],
        caps=caps_for(supported=CHAT_FLAGS | {Capability.IMAGE_INPUT}),
    )
    last = body["input"][-1]["content"][-1]
    assert last["type"] == "input_image"
    assert last["image_url"] == "data:image/png;base64,AAAA"


async def test_structured_output_uses_the_responses_nesting_and_not_the_chat_one() -> None:
    """Responses has no ``json_schema`` wrapper object and ``name`` is required. Copying the
    Chat Completions shape here is a 400."""
    schema = {
        "type": "object",
        "additionalProperties": False,
        "properties": {"answer": {"type": "string"}},
        "required": ["answer"],
    }
    body = await sent_body(
        response_schema=schema,
        caps=caps_for(supported=CHAT_FLAGS | {Capability.STRUCTURED_OUTPUT}),
    )
    assert body["text"] == {
        "format": {
            "type": "json_schema",
            "name": STRUCTURED_OUTPUT_NAME,
            "strict": True,
            "schema": schema,
        }
    }
    assert "json_schema" not in body["text"]


async def test_a_function_tool_is_flat_on_responses() -> None:
    body = await sent_body(
        tools=[ToolDef(name="lookup", description="d", parameters={"type": "object"})],
        caps=caps_for(supported=CHAT_FLAGS | {Capability.TOOL_USE}),
    )
    assert body["tools"][0]["type"] == "function"
    assert body["tools"][0]["name"] == "lookup"
    assert "function" not in body["tools"][0]


async def test_reasoning_effort_is_passed_through_and_the_summary_is_gated_on_the_trace_flag() -> (
    None
):
    """The seven contract levels are OpenAI's seven levels, so this is an identity map and not a
    rounding. The summary is billed either way, so it is only requested where a pane renders."""
    body = await sent_body(
        reasoning=ReasoningOption(effort="high", include_trace=True),
        caps=caps_for(supported=CHAT_FLAGS | {Capability.REASONING, Capability.REASONING_TRACE}),
    )
    assert body["reasoning"] == {"effort": "high", "summary": "auto"}

    without_trace = await sent_body(
        reasoning=ReasoningOption(effort="high", include_trace=True),
        caps=caps_for(supported=CHAT_FLAGS | {Capability.REASONING}, on_unsupported="warn"),
    )
    assert without_trace["reasoning"] == {"effort": "high"}


async def test_sampling_is_absent_unless_the_row_declares_it() -> None:
    body = await sent_body(temperature=0.2, caps=caps_for(on_unsupported="warn"))
    assert "temperature" not in body


# ── validate(): a rejection or a warning, never a dropped field ──────────────

#: One row per optional field on ``ChatRequest`` that this vendor can fail to honour. The
#: capability set is the DEFAULT one, so each row is that option against a row lacking its flag.
UNSUPPORTED_ROWS: Final[tuple[tuple[str, dict[str, Any]], ...]] = (
    ("stream", {"stream": False}),
    ("temperature", {"temperature": 0.2}),
    ("response_schema", {"response_schema": {"type": "object"}}),
    ("reasoning", {"reasoning": ReasoningOption(effort="low")}),
    ("images", {"images": [ImageInput(media_type="image/png", data_b64="AAAA")]}),
    ("tools", {"tools": [ToolDef(name="t", description="d", parameters={})]}),
    ("cache_hint", {"cache_hint": "prefix"}),
)


@pytest.mark.parametrize(
    ("option", "overrides"), UNSUPPORTED_ROWS, ids=[row[0] for row in UNSUPPORTED_ROWS]
)
def test_every_unsupported_option_is_rejected_under_reject(
    option: str, overrides: dict[str, Any]
) -> None:
    caps = caps_for(supported=frozenset({Capability.TEXT}))
    with pytest.raises(KbError) as raised:
        OpenAIAdapter().validate(request_for(**overrides), caps)
    assert raised.value.error_class is ErrorClass.VALIDATION
    assert option.split(".")[0] in str(raised.value) or MODEL in str(raised.value)


@pytest.mark.parametrize(
    ("option", "overrides"), UNSUPPORTED_ROWS, ids=[row[0] for row in UNSUPPORTED_ROWS]
)
def test_every_unsupported_option_is_warned_under_warn_and_never_dropped(
    option: str, overrides: dict[str, Any]
) -> None:
    """The third path — returning nothing and sending the option anyway, or returning nothing
    and dropping it — is the silent drop §8.6 forbids. A bot configured for structured output
    that quietly returns prose is not noticed for a month."""
    caps = caps_for(supported=frozenset({Capability.TEXT}), on_unsupported="warn")
    warnings = OpenAIAdapter().validate(request_for(**overrides), caps)
    assert option in {warning.option for warning in warnings}
    assert all(warning.action == "ignored" for warning in warnings)
    assert all(warning.detail for warning in warnings)


def test_budget_tokens_is_reported_because_it_is_honoured_on_another_vendor() -> None:
    """OpenAI has no reasoning budget control — effort is the only dial. Dropping it silently
    would read as a portable field, and NIM ENFORCES it."""
    caps = caps_for(supported=CHAT_FLAGS | {Capability.REASONING}, on_unsupported="warn")
    warnings = OpenAIAdapter().validate(
        request_for(reasoning=ReasoningOption(effort="low", budget_tokens=4096)), caps
    )
    assert "reasoning.budget_tokens" in {warning.option for warning in warnings}


def test_a_supported_request_produces_no_warnings_at_all() -> None:
    """The other half of the previous two tests: a warning list that is never empty is a list
    nobody reads."""
    assert OpenAIAdapter().validate(request_for(), caps_for()) == []


def test_a_cap_above_the_models_ceiling_is_refused_before_the_first_byte() -> None:
    """The cap covers reasoning tokens too, and those are generated first — a request sized
    against the wrong ceiling returns an empty answer and a full bill."""
    with pytest.raises(KbError, match="exceeds the ceiling"):
        OpenAIAdapter().validate(request_for(max_output_tokens=200_000), caps_for())


@pytest.mark.parametrize(
    ("label", "schema"),
    [
        ("anyOf at the root", {"anyOf": [{"type": "object"}]}),
        (
            "a property missing from required",
            {
                "type": "object",
                "additionalProperties": False,
                "properties": {"a": {"type": "string"}, "b": {"type": "string"}},
                "required": ["a"],
            },
        ),
        (
            "additionalProperties left open",
            {"type": "object", "properties": {}, "required": []},
        ),
        (
            "allOf anywhere",
            {
                "type": "object",
                "additionalProperties": False,
                "properties": {"a": {"allOf": [{"type": "string"}]}},
                "required": ["a"],
            },
        ),
    ],
)
def test_a_schema_outside_the_strict_subset_is_refused_rather_than_400d_after_retrieval(
    label: str, schema: dict[str, Any]
) -> None:
    """A schema that passes review 400s at runtime, after the retrieval spend, with a body we
    are not allowed to log. A Pydantic model with an ``Optional[str]`` field emits neither a
    ``required`` entry nor a null union unless configured to."""
    caps = caps_for(supported=CHAT_FLAGS | {Capability.STRUCTURED_OUTPUT}, on_unsupported="warn")
    with pytest.raises(KbError, match="strict subset"):
        OpenAIAdapter().validate(request_for(response_schema=schema), caps)


def test_the_strict_schema_check_is_a_raise_on_both_on_unsupported_settings() -> None:
    """There is no "send it without the schema" form of this: the caller asked for enforced
    structure, so ``warn`` has nothing to degrade to."""
    for mode in ("reject", "warn"):
        caps = caps_for(supported=CHAT_FLAGS | {Capability.STRUCTURED_OUTPUT}, on_unsupported=mode)
        with pytest.raises(KbError):
            OpenAIAdapter().validate(request_for(response_schema={"anyOf": []}), caps)


def test_a_schema_nested_past_the_published_ceiling_is_named() -> None:
    schema: dict[str, Any] = {"type": "string"}
    for _ in range(STRICT_MAX_DEPTH + 2):
        schema = {
            "type": "object",
            "additionalProperties": False,
            "properties": {"child": schema},
            "required": ["child"],
        }
    caps = caps_for(supported=CHAT_FLAGS | {Capability.STRUCTURED_OUTPUT})
    with pytest.raises(KbError, match="ceiling"):
        OpenAIAdapter().validate(request_for(response_schema=schema), caps)


async def test_a_rejection_happens_before_any_request_is_made() -> None:
    """``validate()`` runs before the first byte, so a refused option costs nothing and there is
    no turn to finalize — which is why this path raises instead of yielding a ``ChatResult``."""
    adapter, client, _ = adapter_streaming([wire(COMPLETED_WITH_CACHE)])
    with pytest.raises(KbError):
        await drain(adapter, request_for(temperature=0.2), caps_for())
    assert client.calls == []


async def test_warnings_ride_into_the_terminal_diagnostics() -> None:
    adapter, _, _ = adapter_streaming([wire(COMPLETED_WITH_CACHE)])
    result = only_result(
        await drain(adapter, request_for(temperature=0.2), caps_for(on_unsupported="warn"))
    )
    assert [warning.option for warning in result.diagnostics.warnings] == ["temperature"]


# ── diagnostics carry no prompt, no evidence and no credential ───────────────


async def test_diagnostics_never_serialize_the_prompt_the_evidence_or_the_key() -> None:
    """A vendor error body routinely echoes the request, and the request contains the packed
    prompt, which contains tenant document text. ``extras`` is an allow-list at the parse site,
    not a scrubber afterwards — the scrubber misses the case nobody imagined."""
    adapter, _, _ = adapter_streaming(
        [wire(TEXT_DELTA), wire(COMPLETED_WITH_CACHE)],
        headers={
            "x-request-id": "req_chat_abc",
            "x-ratelimit-remaining-tokens": "17",
            "authorization": f"Bearer {KEY.get_secret_value()}",
        },
    )
    result = only_result(
        await drain(adapter, request_for(temperature=0.2), caps_for(on_unsupported="warn"))
    )

    payload = result.diagnostics.model_dump_json()
    assert SYSTEM_PROMPT not in payload
    assert CONTEXT_TEXT not in payload
    assert KEY.get_secret_value() not in payload
    assert "How long is the refund window?" not in payload
    # The useful half still survives normalization.
    assert result.diagnostics.rate_limit == {"x-ratelimit-remaining-tokens": "17"}
    assert result.provider_request_id == "req_chat_abc"


async def test_only_allow_listed_rate_limit_headers_are_parsed() -> None:
    """An ``authorization`` header copied into diagnostics is the credential leaving the
    adapter. The allow-list is the module's, so a header added to it is a visible diff."""
    adapter, _, _ = adapter_streaming(
        [wire(COMPLETED_WITH_CACHE)],
        headers=dict.fromkeys((*RATE_LIMIT_HEADERS, "authorization", "set-cookie"), "v"),
    )
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert set(result.diagnostics.rate_limit) == set(RATE_LIMIT_HEADERS)


def test_the_credential_is_unwrapped_at_exactly_one_site() -> None:
    """``get_secret_value()`` appears once, at client construction, so extracting the key is a
    greppable act rather than an accident of serialization."""
    from pathlib import Path

    import app.providers.openai_adapter as module

    source = Path(module.__file__).read_text(encoding="utf-8")
    assert source.count("get_secret_value()") == 1

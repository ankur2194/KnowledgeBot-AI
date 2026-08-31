"""The DeepSeek chat arm — five divergences that produce a wrong answer rather than an error.

DeepSeek is OpenAI-compatible **in shape only**, and every test here exists because the shape
matching is what hides the difference: the request serializes, the response parses, and the
numbers or the stop reason are quietly wrong.

* **``length`` reaching ``COMPLETE``.** The worst failure this layer can cause — the user reads
  a confident half-sentence and nothing errors anywhere. Table-driven, both directions: a
  length cap is ``MAX_OUTPUT``, and a value the vendor added this morning is ``ERROR`` with the
  native word preserved rather than a fall-through to success.
* **The keep-alive trap.** Over the concurrency limit DeepSeek does not return 429 — it holds
  the connection and emits ``: keep-alive`` SSE comments. A reader that parses a comment as
  JSON crashes on the first one under load, and TTFT measured on "first chunk received" reads
  as instant while the user waits. Both are asserted against wire bytes that arrive in
  **multiple frames over time**, because a single-frame fixture passes for a buffered
  implementation, which is the exact bug the test exists to catch.
* **The hit/miss partition.** ``prompt_tokens == hit + miss`` on this vendor, which is neither
  OpenAI's subset nor Anthropic's siblings. Reading ``prompt_tokens`` as the input
  double-counts the cached prefix and reports roughly twice the invoice.
* **A cancelled turn billing as free.** The usage chunk arrives last, so a disconnect kills the
  reader before it and a ``Usage()`` of zeros looks exactly like a call that never happened.
* **A fabricated ``Retry-After``.** There is none to read here, and an invented one outranks
  the jittered backoff and pins the caller inside the rejection window.

Nothing here touches a network. The SDK client is replaced at the one seam built for it —
``DeepSeekAdapter._client`` — and the stream fixtures are raw SSE wire bytes decoded by the
**SDK's own reader**, which is the reader this adapter actually ships. A hand-rolled fake
decoder would prove that the fake skips comment lines.
"""

from __future__ import annotations

import asyncio
import inspect
import json
from collections.abc import AsyncIterator, Sequence
from typing import Any, Final

import httpx
import openai
import pytest
from openai._streaming import SSEDecoder
from openai.types.chat import ChatCompletionChunk
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
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
    Timeouts,
)
from app.providers.deepseek import (
    BASE_URL,
    CAPACITY_FINISH_REASON,
    EFFORT,
    ESTIMATED_CHARS_PER_TOKEN,
    EVIDENCE_HEADER,
    PINNED_MODELS,
    ROUNDED_EFFORT,
    SATURATION_TIMEOUT_CODE,
    STATUS_TO_CLASS,
    STOP,
    VENDOR_CODE_TO_CLASS,
    DeepSeekAdapter,
)
from app.providers.errors import UNMAPPED

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BT"
CONNECTION: Final = "01JQZ0000000000000000000CN"
CHUNK: Final = "01JQZ0000000000000000000CH"
MODEL: Final = PINNED_MODELS[0]
FINGERPRINT: Final = "fp_v4pro_0731"
COMPLETION_ID: Final = "chatcmpl-deepseek-fixture"

#: The fixture credential and the fixture system prompt. Both are grepped for in a serialized
#: payload; neither may appear in one.
API_KEY: Final = "sk-fixture-deepseek-must-never-appear"
SYSTEM_PROMPT: Final = "You are Aurelia, the FIXTURE-SYSTEM-PROMPT-CANARY assistant."

#: Every chat flag a V4 row carries, per the adapter's class docstring. ``SAMPLING`` is the
#: conditional one and ``STRUCTURED_OUTPUT``/``IMAGE_INPUT``/``EARLY_INPUT_USAGE`` are off.
V4_FLAGS: Final = frozenset(
    {
        Capability.TEXT,
        Capability.TOOL_USE,
        Capability.JSON_MODE,
        Capability.REASONING,
        Capability.REASONING_TRACE,
        Capability.PROMPT_CACHING,
        Capability.STREAM_USAGE,
    }
)


# ── fixtures: the request side ────────────────────────────────────────────────


def caps_for(
    *,
    supported: frozenset[Capability] = V4_FLAGS,
    on_unsupported: str = "reject",
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=1_000_000,
        max_output_tokens=384_000,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(**overrides: Any) -> ChatRequest:
    fields: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": "trace-deepseek",
        "provider_connection_id": CONNECTION,
        "model": MODEL,
        "system": SYSTEM_PROMPT,
        "messages": [Message(role="user", content="How do I rotate the key?")],
        "max_output_tokens": 1024,
    }
    fields.update(overrides)
    return ChatRequest(**fields)


# ── fixtures: the wire side ───────────────────────────────────────────────────
#
# Raw SSE bytes, decoded by the SDK's own `SSEDecoder`. That import is deliberate and is the
# point of the keep-alive test: the reader that skips `: keep-alive` before parsing has to be
# the reader we ship, not a helper written in this file that skips it by construction.

KEEP_ALIVE: Final = b": keep-alive\n\n"
DONE: Final = b"data: [DONE]\n\n"


def frame(payload: dict[str, Any]) -> bytes:
    return f"data: {json.dumps(payload)}\n\n".encode()


def delta_chunk(
    *,
    content: str | None = None,
    reasoning: str | None = None,
    refusal: str | None = None,
    tool_arguments: str | None = None,
    finish: str | None = None,
) -> bytes:
    delta: dict[str, Any] = {}
    if content is not None:
        delta["content"] = content
    if reasoning is not None:
        delta["reasoning_content"] = reasoning
    if refusal is not None:
        delta["refusal"] = refusal
    if tool_arguments is not None:
        delta["tool_calls"] = [
            {
                "index": 0,
                "id": "call_1",
                "type": "function",
                "function": {"name": "lookup", "arguments": tool_arguments},
            }
        ]
    return frame(
        {
            "id": COMPLETION_ID,
            "object": "chat.completion.chunk",
            "created": 1_770_000_000,
            "model": MODEL,
            "system_fingerprint": FINGERPRINT,
            "choices": [{"index": 0, "delta": delta, "finish_reason": finish}],
        }
    )


def usage_chunk(
    *,
    prompt: int = 1000,
    hit: int = 640,
    miss: int = 360,
    completion: int = 120,
    reasoning: int = 40,
) -> bytes:
    """The final, CHOICE-LESS chunk. ``choices[0]`` on it is an IndexError at the end of every
    successful stream, and it is the chunk carrying the number ``include_usage`` exists for."""
    return frame(
        {
            "id": COMPLETION_ID,
            "object": "chat.completion.chunk",
            "created": 1_770_000_000,
            "model": MODEL,
            "system_fingerprint": FINGERPRINT,
            "choices": [],
            "usage": {
                "prompt_tokens": prompt,
                "completion_tokens": completion,
                "total_tokens": prompt + completion,
                "prompt_cache_hit_tokens": hit,
                "prompt_cache_miss_tokens": miss,
                "completion_tokens_details": {"reasoning_tokens": reasoning},
            },
        }
    )


Frames = Sequence[bytes | tuple[float, bytes]]


async def _chunks(frames: Frames) -> AsyncIterator[ChatCompletionChunk]:
    """Wire bytes -> parsed chunks, through the SDK's real SSE reader.

    A frame may carry a delay, which is what makes this fixture arrive over time rather than
    all at once. A single-frame fixture passes for a buffered implementation.
    """

    async def raw() -> AsyncIterator[bytes]:
        for item in frames:
            delay, payload = item if isinstance(item, tuple) else (0.0, item)
            if delay:
                await asyncio.sleep(delay)
            yield payload

    decoder = SSEDecoder()
    async for event in decoder.aiter_bytes(raw()):
        if event.data == "[DONE]":
            return
        yield ChatCompletionChunk.construct(**json.loads(event.data))


class FakeClient:
    """The two things ``stream()`` asks of a client: an async context and one ``create``."""

    def __init__(self, frames: Frames, *, raises: BaseException | None = None) -> None:
        self._frames = frames
        self._raises = raises
        self.bodies: list[dict[str, Any]] = []
        self.entered = 0
        self.exited = 0

        outer = self

        class _Completions:
            async def create(self, **body: Any) -> AsyncIterator[ChatCompletionChunk]:
                outer.bodies.append(body)
                if outer._raises is not None:
                    raise outer._raises
                return _chunks(outer._frames)

        class _Chat:
            completions = _Completions()

        self.chat = _Chat()

    async def __aenter__(self) -> FakeClient:
        self.entered += 1
        return self

    async def __aexit__(self, *_: Any) -> bool:
        self.exited += 1
        return False


def adapter_streaming(
    frames: Frames, *, raises: BaseException | None = None
) -> tuple[DeepSeekAdapter, FakeClient]:
    client = FakeClient(frames, raises=raises)
    adapter = DeepSeekAdapter()
    adapter._client = lambda credential, req: client  # type: ignore[method-assign]
    return adapter, client


async def run(
    adapter: DeepSeekAdapter,
    req: ChatRequest | None = None,
    caps: ModelCapabilities | None = None,
) -> tuple[list[Delta], ChatResult]:
    """Drain a stream and split it into its deltas and its ONE terminal event."""
    deltas: list[Delta] = []
    terminals: list[ChatResult] = []
    async for event in adapter.stream(
        req if req is not None else request_for(),
        caps if caps is not None else caps_for(),
        SecretStr(API_KEY),
    ):
        (terminals if isinstance(event, ChatResult) else deltas).append(event)  # type: ignore[arg-type]
    assert len(terminals) == 1, "exactly one ChatResult terminates every stream"
    return deltas, terminals[0]


def status_error(
    status: int, *, body: object | None = None, headers: dict[str, str] | None = None
) -> openai.APIStatusError:
    request = httpx.Request("POST", f"{BASE_URL}/chat/completions")
    response = httpx.Response(status, request=request, headers=headers or {})
    subclass = {
        401: openai.AuthenticationError,
        402: openai.APIStatusError,
        422: openai.UnprocessableEntityError,
        429: openai.RateLimitError,
        500: openai.InternalServerError,
        503: openai.InternalServerError,
    }.get(status, openai.APIStatusError)
    # The vendor's own message, deliberately hostile: DeepSeek's 4xx bodies echo request
    # context, and on this service the request carries the packed prompt.
    return subclass(  # type: ignore[call-arg]
        f"upstream said: {SYSTEM_PROMPT}", response=response, body=body
    )


# ── the client seam ───────────────────────────────────────────────────────────


async def test_the_client_is_built_with_no_sdk_retries_against_the_openai_shaped_base_url() -> None:
    """``max_retries=0`` matters more here than anywhere else.

    The SDK retries twice by default on the connection-shaped failures DeepSeek manufactures
    constantly by holding sockets open, and the router tier retries on top — up to nine billed
    completions from one click, one span, and a very visible balance page. The base URL is the
    OpenAI-shaped surface; the ``/anthropic`` one silently remaps unknown model ids to the
    cheap model and answers plausibly.
    """
    client = DeepSeekAdapter()._client(SecretStr(API_KEY), request_for())
    try:
        assert client.max_retries == 0
        assert str(client.base_url).rstrip("/") == BASE_URL
    finally:
        await client.close()


async def test_one_adapter_attempt_produces_exactly_one_outbound_request() -> None:
    adapter, client = adapter_streaming(
        [delta_chunk(content="ok"), delta_chunk(finish="stop"), usage_chunk(), DONE]
    )
    await run(adapter)
    assert len(client.bodies) == 1
    assert (client.entered, client.exited) == (1, 1), "the socket is closed on the way out"


def test_the_credential_is_unwrapped_in_exactly_one_place() -> None:
    """``get_secret_value()`` once, at client construction, so extracting the key is a
    greppable act rather than an accident of serialization."""
    source = inspect.getsource(inspect.getmodule(DeepSeekAdapter))  # type: ignore[arg-type]
    assert source.count("get_secret_value()") == 1


# ── stop reasons: no length cap may reach COMPLETE ────────────────────────────


@pytest.mark.parametrize(
    ("native", "expected_stop", "expected_class"),
    [
        ("stop", StopReason.COMPLETE, None),
        ("length", StopReason.MAX_OUTPUT, None),
        ("tool_calls", StopReason.TOOL_USE, None),
        ("content_filter", StopReason.REFUSAL, None),
        # HTTP 200, and capacity rather than a stop reason. Fallback-eligible.
        (CAPACITY_FINISH_REASON, StopReason.ERROR, ErrorClass.PROVIDER_TEMPORARY.value),
        # A value the vendor added under its versioning policy, with no notice.
        ("max_completion_tokens", StopReason.ERROR, UNMAPPED.value),
        # A connection that dropped mid-answer sends no finish reason at all.
        (None, StopReason.ERROR, UNMAPPED.value),
    ],
)
async def test_finish_reason_maps_without_a_fall_through_to_complete(
    native: str | None, expected_stop: StopReason, expected_class: str | None
) -> None:
    adapter, _ = adapter_streaming(
        [delta_chunk(content="partial"), delta_chunk(finish=native), usage_chunk(), DONE]
    )
    _, result = await run(adapter)
    assert result.stop_reason is expected_stop
    assert result.error_class == expected_class
    # The vendor's own word survives even when it maps to ERROR. It is how we notice a vendor
    # added a stop reason at all.
    assert result.diagnostics.native_stop_reason == native


def test_no_value_but_stop_reaches_complete_and_length_is_truncation() -> None:
    """The table itself, asserted as a property rather than one row at a time.

    A truncated answer presented as complete is the worst failure this layer can cause, and it
    is a one-character edit away at all times.
    """
    assert STOP["length"] is StopReason.MAX_OUTPUT
    assert [native for native, mapped in STOP.items() if mapped is StopReason.COMPLETE] == ["stop"]
    assert CAPACITY_FINISH_REASON not in STOP


# ── the keep-alive trap ───────────────────────────────────────────────────────


def test_the_shipped_sse_reader_drops_a_comment_line_before_parsing() -> None:
    """``: keep-alive`` is not JSON, and under saturation it is most of the stream.

    Asserted against ``openai._streaming.SSEDecoder`` on purpose — that is the reader this
    adapter runs on. A local helper would prove only that the local helper skips comments.
    """
    decoder = SSEDecoder()
    assert decoder.decode(": keep-alive") is None
    assert decoder.decode(": OPENROUTER PROCESSING") is None


async def test_a_stream_that_opens_with_keep_alives_parses_and_ttft_is_the_first_text_delta() -> (
    None
):
    """Two failures in one fixture, and the second is the one nobody sees.

    The frames arrive over time: a reasoning chunk immediately, then two ``: keep-alive``
    comments 60 ms apart, then the first text. A reader that JSON-parses a comment crashes
    here. A reader that stamps TTFT on the first *chunk* records ~0 ms — instant, while the
    user watches nothing happen — because the reasoning chunk arrived at once and, on a row
    without ``REASONING_TRACE``, nothing would have been rendered from it either.
    """
    frames: Frames = [
        delta_chunk(reasoning="considering the key rotation steps"),
        (0.06, KEEP_ALIVE),
        (0.06, KEEP_ALIVE),
        delta_chunk(content="Rotate it "),
        delta_chunk(content="from the console."),
        delta_chunk(finish="stop"),
        usage_chunk(),
        DONE,
    ]
    adapter, _ = adapter_streaming(frames)
    deltas, result = await run(adapter)

    assert [(delta.kind, delta.text) for delta in deltas] == [
        ("reasoning", "considering the key rotation steps"),
        ("text", "Rotate it "),
        ("text", "from the console."),
    ]
    assert result.text == "Rotate it from the console."
    assert result.stop_reason is StopReason.COMPLETE
    assert result.first_token_ms is not None
    assert result.first_token_ms >= 100, (
        "TTFT was stamped on the first chunk or on the reasoning delta, not on the first text "
        "delta — the measurement saturation is invisible to"
    )


async def test_saturation_is_detected_by_the_first_token_timer_and_reads_as_capacity() -> None:
    """DeepSeek returns no 429 when it is over the concurrency limit — it holds the connection.

    Every byte of keep-alive resets the transport read timeout, and the SDK's reader yields no
    chunk for a comment, so neither the socket timer nor a per-chunk check can fire. This
    explicit first-token timer is the only saturation signal that exists, and it has to read as
    ``provider_temporary``: a connection configured to fall back on ``provider_rate_limit``
    would otherwise never fire at all.
    """
    frames: Frames = [(0.01, KEEP_ALIVE) for _ in range(100)]
    adapter, _ = adapter_streaming(frames)
    req = request_for(timeouts=Timeouts(connect=1.0, first_token=0.05, total=2.0))
    _, result = await run(adapter, req)

    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.first_token_ms is None
    assert result.text == ""


async def test_a_long_thinking_phase_is_not_saturation() -> None:
    """The timeout latch and the TTFT metric answer different questions, and collapsing them
    breaks one of them.

    Reasoning proves inference started, which is the only thing the saturation detector asks.
    Latching the timer on the first *text* delta instead would kill a genuinely slow thinking
    phase as "saturated", classify it ``provider_temporary``, and fire a fallback attempt behind
    it — for a request that was working. The metric still starts at the first text delta,
    because that is when the user sees something.
    """
    frames: Frames = [
        delta_chunk(reasoning="step one"),
        (0.12, delta_chunk(content="Rotate it.")),
        delta_chunk(finish="stop"),
        usage_chunk(),
        DONE,
    ]
    adapter, _ = adapter_streaming(frames)
    # A first-token budget far SHORTER than the thinking phase. The reasoning delta released
    # the timer; only keep-alives (which yield no chunk at all) can fail to.
    req = request_for(timeouts=Timeouts(connect=1.0, first_token=0.05, total=2.0))
    _, result = await run(adapter, req)

    assert result.stop_reason is StopReason.COMPLETE
    assert result.error_class is None
    assert result.text == "Rotate it."
    assert result.first_token_ms is not None and result.first_token_ms >= 100


def test_the_saturation_timeout_classifies_as_capacity_and_carries_its_own_native_code() -> None:
    failure = DeepSeekAdapter().classify(TimeoutError(), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert failure.native_code == SATURATION_TIMEOUT_CODE
    assert failure.retry_after is None


# ── usage: the hit/miss partition ─────────────────────────────────────────────


async def test_the_cache_partition_fills_disjoint_buckets_and_totals_the_billed_input() -> None:
    """``prompt_tokens == hit + miss`` on this vendor and nowhere else.

    OpenAI's cached count is a SUBSET of its input count; Anthropic's is a SIBLING of it. Feed
    ``prompt_tokens`` into these disjoint buckets and the cached prefix is counted twice —
    roughly double DeepSeek's invoice against a stable knowledge base, silently.
    """
    adapter, _ = adapter_streaming(
        [
            delta_chunk(content="answer"),
            delta_chunk(finish="stop"),
            usage_chunk(prompt=1000, hit=640, miss=360, completion=120, reasoning=40),
            DONE,
        ]
    )
    _, result = await run(adapter)
    usage = result.usage

    assert usage.input_tokens == 360, "input is the MISS half, never prompt_tokens"
    assert usage.cache_read_tokens == 640
    assert usage.cache_write_tokens == 0, "DeepSeek neither bills nor reports cache writes"
    assert usage.input_tokens + usage.cache_read_tokens == 1000
    assert usage.total_input_tokens == 1000, "what billing actually reads"
    assert usage.output_tokens == 120
    assert usage.reasoning_tokens == 40, "billed INSIDE output_tokens, reported for attribution"
    assert usage.source == "provider_final"


def test_a_partition_that_does_not_hold_refuses_to_produce_a_billable_row() -> None:
    """A sum that disagrees describes no call. The dangerous outcome here is a number that
    looks like a measurement, so this raises rather than returning something plausible."""
    with pytest.raises(KbError, match="must not be billed"):
        DeepSeekAdapter()._usage(
            ChatCompletionChunk.construct(
                choices=[],
                usage={
                    "prompt_tokens": 1000,
                    "completion_tokens": 10,
                    "prompt_cache_hit_tokens": 640,
                    "prompt_cache_miss_tokens": 400,
                },
            ).usage
        )


async def test_a_broken_partition_loses_the_row_and_keeps_the_answer() -> None:
    """The text is already in the user's hands. Failing a good turn over an accounting anomaly
    trades a visible outage for an invisible one; the row degrades to an estimate and says so."""
    adapter, _ = adapter_streaming(
        [
            delta_chunk(content="answer"),
            delta_chunk(finish="stop"),
            usage_chunk(prompt=1000, hit=640, miss=400),
            DONE,
        ]
    )
    _, result = await run(adapter)
    assert result.text == "answer"
    assert result.stop_reason is StopReason.COMPLETE
    assert result.usage.source == "estimated"
    assert result.diagnostics.extras["usage_partition"] == "broken"


async def test_a_missing_cache_report_is_not_a_broken_partition() -> None:
    """Absent hit/miss fields are a missing cache REPORT, not a contradiction. The whole prompt
    reads as uncached, which over-attributes to ``input_tokens`` and leaves
    ``total_input_tokens`` — the number billing reads — exactly right."""
    raw = ChatCompletionChunk.construct(
        choices=[], usage={"prompt_tokens": 700, "completion_tokens": 12}
    ).usage
    usage = DeepSeekAdapter()._usage(raw)
    assert (usage.input_tokens, usage.cache_read_tokens) == (700, 0)
    assert usage.total_input_tokens == 700
    assert usage.source == "provider_final"


# ── cancellation ──────────────────────────────────────────────────────────────


async def test_cancelling_mid_stream_emits_one_terminal_result_and_re_raises() -> None:
    """Emitted from ``except asyncio.CancelledError`` and then re-raised — never from
    ``finally``.

    Yielding while ``GeneratorExit`` unwinds raises ``RuntimeError: async generator ignored
    GeneratorExit``, ASGI swallows it, and the only symptom is a missing usage row for a turn
    DeepSeek still billed. And a ``Usage()`` of zeros looks exactly like a free call, so the
    row is populated from the deltas counted so far.
    """
    adapter, _ = adapter_streaming(
        [
            delta_chunk(content="The first half of the answer"),
            (0.5, delta_chunk(content=" and the half nobody reads")),
            delta_chunk(finish="stop"),
            usage_chunk(),
            DONE,
        ]
    )
    generator = adapter.stream(request_for(), caps_for(), SecretStr(API_KEY))

    first = await anext(generator)
    assert isinstance(first, Delta) and first.kind == "text"

    terminal = await generator.athrow(asyncio.CancelledError())
    assert isinstance(terminal, ChatResult)
    assert terminal.stop_reason is StopReason.CANCELLED
    assert terminal.error_class == ErrorClass.USER_CANCELLATION.value
    assert terminal.text == "The first half of the answer"
    assert terminal.usage.source != "provider_final"
    assert terminal.usage.source == "estimated"
    assert terminal.usage.output_tokens > 0, "a cancelled turn is not a free turn"
    assert terminal.usage.output_tokens == -(
        -len("The first half of the answer") // int(ESTIMATED_CHARS_PER_TOKEN)
    )

    with pytest.raises(asyncio.CancelledError):
        await anext(generator)


# ── refusal ───────────────────────────────────────────────────────────────────


async def test_a_content_policy_refusal_is_terminal_and_not_an_error_class() -> None:
    """Refusals arrive as HTTP 200. An adapter classifying on status files them as success and
    a router seeing empty text files them as a fault — either way the banned question is asked
    again somewhere else and billed twice. The fallback table makes ``REFUSAL`` terminal.
    """
    adapter, _ = adapter_streaming(
        [
            delta_chunk(refusal="I can't help with that."),
            delta_chunk(finish="content_filter"),
            usage_chunk(completion=8, reasoning=0),
            DONE,
        ]
    )
    deltas, result = await run(adapter)

    assert [delta.kind for delta in deltas] == ["refusal"]
    assert result.stop_reason is StopReason.REFUSAL
    assert result.error_class is None, "a refusal is not a provider failure"
    assert result.diagnostics.native_stop_reason == "content_filter"


# ── errors: the status map, and the reset time that does not exist ────────────


async def test_a_429_with_no_retry_after_leaves_the_rate_limit_dict_empty() -> None:
    """There are no rate-limit headers on this vendor and no ``Retry-After``.

    An invented reset time outranks the jittered backoff and pins the caller inside the
    rejection window until the deadline kills the turn — the failure mode where a 429 rate
    stays at 100% long after traffic drops.
    """
    adapter, _ = adapter_streaming([], raises=status_error(429))
    _, result = await run(adapter)

    assert result.error_class == ErrorClass.PROVIDER_RATE_LIMIT.value
    assert result.diagnostics.rate_limit == {}
    assert result.provider_request_id is None, "DeepSeek publishes no request-ID header"


@pytest.mark.parametrize("status", sorted(STATUS_TO_CLASS))
def test_every_documented_status_classifies_without_reading_the_message(status: int) -> None:
    failure = DeepSeekAdapter().classify(status_error(status), tokens_emitted=0)
    assert failure.error_class is STATUS_TO_CLASS[status]
    assert failure.retry_after is None
    assert failure.provider_request_id is None
    assert SYSTEM_PROMPT not in failure.message, "the vendor's prose never crosses into ours"


def test_the_vendor_code_outranks_the_status() -> None:
    """A 503 whose body names the capacity code is still capacity; a 503 whose body names an
    invalid request is not. Branching on the number is branching on the least specific evidence
    available, and branching on the prose is worse — a copy-edit reclassifies a configuration
    defect as capacity, and the bot stays Ready while every turn is served by something the
    tenant never chose.
    """
    permanent = DeepSeekAdapter().classify(
        status_error(503, body={"error": {"code": "invalid_request_error"}}), tokens_emitted=0
    )
    assert permanent.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST
    assert VENDOR_CODE_TO_CLASS[CAPACITY_FINISH_REASON] is ErrorClass.PROVIDER_TEMPORARY


def test_a_transport_failure_with_no_body_is_temporary() -> None:
    request = httpx.Request("POST", f"{BASE_URL}/chat/completions")
    failure = DeepSeekAdapter().classify(openai.APITimeoutError(request), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert failure.native_code == "APITimeoutError"


async def test_tokens_emitted_reaches_the_classification_after_a_mid_stream_failure() -> None:
    """Once DeepSeek has emitted a delta we have been billed, so a retry re-bills and the user
    watches the answer restart. That gate outranks every class verdict."""
    adapter = DeepSeekAdapter()
    seen: list[int] = []
    original = adapter.classify

    def spy(exc: BaseException, *, tokens_emitted: int) -> Any:
        seen.append(tokens_emitted)
        return original(exc, tokens_emitted=tokens_emitted)

    adapter.classify = spy  # type: ignore[method-assign]

    async def failing() -> AsyncIterator[ChatCompletionChunk]:
        async for chunk in _chunks(
            [delta_chunk(content="half an "), delta_chunk(content="answer")]
        ):
            yield chunk
        raise status_error(500)

    client = FakeClient([])
    client.chat.completions.create = lambda **body: _wrap(failing())  # type: ignore[assignment]
    adapter._client = lambda credential, req: client  # type: ignore[method-assign]

    _, result = await run(adapter)
    assert seen == [2]
    assert result.text == "half an answer"
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value


async def _wrap(iterator: AsyncIterator[ChatCompletionChunk]) -> AsyncIterator[ChatCompletionChunk]:
    return iterator


# ── the request body ──────────────────────────────────────────────────────────


async def test_the_body_always_carries_an_explicit_thinking_object_and_stream_usage() -> None:
    """The API default is thinking ENABLED, which is the reverse of every other vendor here.
    Omitting the field buys reasoning nobody asked for; omitting ``stream_options`` ends the
    stream with no usage chunk at all and bills every call as zero."""
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    await run(adapter, request_for(reasoning=ReasoningOption(effort="none")))
    body = client.bodies[0]

    assert body["thinking"] == {"type": "disabled"}
    assert body["stream_options"] == {"include_usage": True}
    assert body["stream"] is True
    assert body["user_id"] == f"org-{ORG}"
    assert body["max_tokens"] == 1024


async def test_temperature_is_never_sent_while_thinking_is_enabled_and_always_warns() -> None:
    """DeepSeek accepts ``temperature`` in thinking mode and does NOTHING with it, without
    erroring — so ``temperature=0`` is a differently-worded answer every run and nothing
    reports a problem. Forwarding a parameter we know is inert is the silent drop §8.6 forbids.
    """
    caps = caps_for(supported=V4_FLAGS | {Capability.SAMPLING})
    req = request_for(temperature=0.0, reasoning=ReasoningOption(effort="high"))
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    _, result = await run(adapter, req, caps)

    assert "temperature" not in client.bodies[0]
    assert client.bodies[0]["thinking"] == {"type": "enabled", "reasoning_effort": "high"}
    ignored = {w.option for w in result.diagnostics.warnings if w.action == "ignored"}
    assert "temperature" in ignored


async def test_temperature_is_sent_on_the_thinking_disabled_resolution_of_the_row() -> None:
    caps = caps_for(supported=V4_FLAGS | {Capability.SAMPLING})
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    await run(adapter, request_for(temperature=0.2), caps)
    assert client.bodies[0]["temperature"] == 0.2
    assert client.bodies[0]["thinking"] == {"type": "disabled"}


async def test_evidence_is_its_own_user_section_ahead_of_the_conversation() -> None:
    """Retrieved text is untrusted data and the instruction slot is the one place it must not
    reach. The order is also the cache prefix: caching here is prefix-match only, so a
    re-sorted evidence list is a full miss that costs 50x and errors nowhere."""
    req = request_for(
        context_blocks=[
            ContextBlock(index=1, chunk_id=CHUNK, title="Second", text="beta"),
            ContextBlock(index=0, chunk_id=CHUNK, title="First", text="alpha"),
        ]
    )
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    await run(adapter, req)
    messages = client.bodies[0]["messages"]

    assert messages[0] == {"role": "system", "content": SYSTEM_PROMPT}
    assert "alpha" not in messages[0]["content"] and "beta" not in messages[0]["content"]
    assert messages[1]["role"] == "user"
    assert messages[1]["content"].startswith(EVIDENCE_HEADER)
    assert messages[1]["content"].index("alpha") < messages[1]["content"].index("beta")
    assert messages[2] == {"role": "user", "content": "How do I rotate the key?"}


async def test_reasoning_content_is_replayed_only_when_the_caller_put_it_there() -> None:
    """Two opposite rules, one adapter. Inside a live tool loop the field MUST come back or the
    model reasons from a hole and the second tool call is nonsense; outside one the API
    discards it. The split belongs to the caller, and a turn replayed from PostgreSQL carries
    ``reasoning=None`` because it is never persisted."""
    in_loop = request_for(
        messages=[
            Message(role="user", content="look it up"),
            Message(role="assistant", content="", reasoning="I should call lookup()"),
        ]
    )
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    await run(adapter, in_loop)
    assert client.bodies[0]["messages"][-1]["reasoning_content"] == "I should call lookup()"

    replayed = request_for(
        messages=[
            Message(role="user", content="look it up"),
            Message(role="assistant", content="Here it is."),
        ]
    )
    adapter, client = adapter_streaming([delta_chunk(finish="stop"), usage_chunk(), DONE])
    await run(adapter, replayed)
    assert "reasoning_content" not in client.bodies[0]["messages"][-1]


async def test_the_rolling_alias_fingerprint_is_recorded_on_every_call() -> None:
    """``deepseek-v4-flash`` is an alias DeepSeek rolls forward in place, and
    ``system_fingerprint`` is the only observable that moves when the weights do. The body's
    ``id`` is recorded under its own name because it is a completion id, not a support handle.
    """
    adapter, _ = adapter_streaming([delta_chunk(content="a"), delta_chunk(finish="stop"), DONE])
    _, result = await run(adapter)
    assert result.diagnostics.extras["system_fingerprint"] == FINGERPRINT
    assert result.diagnostics.extras["served_model"] == MODEL
    assert result.diagnostics.extras["completion_id"] == COMPLETION_ID
    assert result.provider_request_id is None


# ── validate(): rejected or warned, never dropped ─────────────────────────────


@pytest.mark.parametrize(
    ("overrides", "option"),
    [
        ({"response_schema": {"type": "object"}}, "response_schema"),
        ({"cache_hint": "prefix"}, "cache_hint"),
        ({"reasoning": ReasoningOption(effort="high", budget_tokens=4096)}, None),
    ],
)
async def test_every_unsupported_option_is_warned_and_never_silently_dropped(
    overrides: dict[str, Any], option: str | None
) -> None:
    warnings = DeepSeekAdapter().validate(request_for(**overrides), caps_for())
    assert warnings, "an option DeepSeek cannot honour must produce a visible record"
    if option is not None:
        assert option in {warning.option for warning in warnings}
    assert all(warning.action in {"ignored", "rejected"} for warning in warnings)
    assert all(warning.detail for warning in warnings)


@pytest.mark.parametrize("effort", sorted(ROUNDED_EFFORT))
def test_a_rounded_effort_level_names_what_it_became(effort: str) -> None:
    """An unwarned rounding is indistinguishable from the vendor honouring the request."""
    warnings = DeepSeekAdapter().validate(
        request_for(reasoning=ReasoningOption(effort=effort)),  # type: ignore[arg-type]
        caps_for(),
    )
    rounded = next(w for w in warnings if w.option == "reasoning.effort")
    assert EFFORT[effort] in rounded.detail
    assert rounded.action == "ignored"


def test_reject_raises_before_a_byte_goes_out_and_warn_records_the_same_option() -> None:
    """``on_unsupported`` is per-model and admin-set, and it has exactly two settings. The third
    one people reach for is "drop it quietly", which is how a bot configured for structured
    output returns prose for a month."""
    req = request_for(temperature=0.4)  # SAMPLING is off on this row
    with pytest.raises(KbError) as raised:
        DeepSeekAdapter().validate(req, caps_for(on_unsupported="reject"))
    assert raised.value.error_class is ErrorClass.VALIDATION

    warnings = DeepSeekAdapter().validate(req, caps_for(on_unsupported="warn"))
    assert "temperature" in {warning.option for warning in warnings}


def test_images_are_refused_regardless_of_on_unsupported() -> None:
    """Every other entry is an OPTION — a knob whose absence changes how an answer is worded.
    An image is CONTENT: sending the request without it asks a different question, and the
    model answers the changed one plausibly and at length."""
    from app.providers.contract import ImageInput

    req = request_for(images=[ImageInput(media_type="image/png", data_b64="AA==")])
    for policy in ("reject", "warn"):
        with pytest.raises(KbError, match="text-only"):
            DeepSeekAdapter().validate(req, caps_for(on_unsupported=policy))


def test_a_row_claiming_native_schema_enforcement_is_a_configuration_defect() -> None:
    """``JSON_MODE`` is the ceiling and the two are separate flags for exactly this reason. A
    row claiming ``STRUCTURED_OUTPUT`` would offer an admin screen enforcement this vendor has
    no surface for, and the answers would come back plausible and unvalidated."""
    with pytest.raises(KbError, match="json_schema"):
        DeepSeekAdapter().validate(
            request_for(response_schema={"type": "object"}),
            caps_for(supported=V4_FLAGS | {Capability.STRUCTURED_OUTPUT}),
        )


def test_json_mode_is_the_ceiling_and_says_so() -> None:
    warnings = DeepSeekAdapter().validate(
        request_for(response_schema={"type": "object"}), caps_for()
    )
    schema_warning = next(w for w in warnings if w.option == "response_schema")
    assert "json_object" in schema_warning.detail
    assert schema_warning.action == "ignored"


# ── diagnostics carry no prompt, no message, no credential ────────────────────


async def test_a_serialized_diagnostics_payload_carries_no_prompt_and_no_credential() -> None:
    """A vendor error body routinely echoes the request, and on this service the request
    carries the packed prompt, which carries tenant document text. The fixture 401's message is
    the system prompt verbatim, so a payload that quotes the vendor fails here.
    """
    body = {"error": {"message": SYSTEM_PROMPT, "type": "authentication_error"}}
    adapter, _ = adapter_streaming([], raises=status_error(401, body=body))
    req = request_for(
        context_blocks=[
            ContextBlock(index=0, chunk_id=CHUNK, title="Runbook", text="TENANT-CHUNK-CANARY")
        ]
    )
    _, result = await run(adapter, req)

    assert result.error_class == ErrorClass.PROVIDER_AUTH.value
    payload = result.model_dump_json()
    assert SYSTEM_PROMPT not in payload
    assert "FIXTURE-SYSTEM-PROMPT-CANARY" not in payload
    assert "TENANT-CHUNK-CANARY" not in payload
    assert API_KEY not in payload
    assert "How do I rotate the key?" not in payload


# ── the static capability gate ────────────────────────────────────────────────


def test_deepseek_defines_neither_embed_nor_rerank() -> None:
    """The static gate is the ABSENCE of the method, not a boolean.

    DeepSeek's complete API reference indexes five operations and neither family is among them,
    so a DeepSeek-only organization cannot ingest a single document. The way out is a second
    connection to a vendor that embeds, never a ``provider_models`` row that claims one — and a
    method that existed and raised would type-check, satisfy every Protocol check, and fail at
    request time on the ingestion path, where a failure costs a re-parse.
    """
    for method in ("embed", "rerank", "validate_embedding", "validate_rerank"):
        assert not hasattr(DeepSeekAdapter, method), f"DeepSeekAdapter must not define {method}"

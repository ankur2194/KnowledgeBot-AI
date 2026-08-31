"""The OpenRouter adapter — the gateway divergences that produce a wrong answer, not an error.

OpenRouter is OpenAI-shaped on the wire and structurally unlike every other vendor here: one
credential, hundreds of models, dozens of upstreams, and a routing layer of its own between us
and whoever actually answers. Every test below exists because the shape matching is what hides
the difference.

* **The silent parameter drop.** ``provider.require_parameters`` defaults to ``false``, so an
  upstream that cannot honour ``response_format`` or ``reasoning`` is routed to anyway and the
  field is discarded at HTTP 200 — plausible prose, no schema, no error, and an eval suite that
  scores the degraded answer as a model regression. Asserted on the serialized BODY, because a
  flag on the adapter proves only that somebody meant to.
* **The keep-alive that crashes the reader.** ``: OPENROUTER PROCESSING`` is not JSON and under
  load it is most of the stream. Asserted against bytes that arrive in **multiple frames over
  time**, because a single-frame fixture passes for a buffered implementation, which is the
  exact bug the test exists to catch.
* **A mid-stream error inside an HTTP 200.** Headers were committed long before the failure, so
  the status stays 200 while the chunk carries a top-level ``error``. A reader that inspects
  only the status records a successful call with truncated text and no ``error_class``.
* **``served_by`` empty forever.** Attribution is opt-in via ``X-OpenRouter-Metadata: enabled``
  and arrives on the FINAL chunk, so a reader that stops at the last text delta loses it and
  upstream-scoped breaker keying silently degrades to gateway-wide.
* **A slug that no longer resolves falling back.** The catalogue is third-party and volatile, so
  this looks like a lifecycle event — but the id came from the bot's own configuration snapshot,
  and falling back serves every answer from a model the tenant never chose.
* **An uncharacterized rerank scale looking usable.** The route fronts several upstream
  cross-encoders on one credential with no documented normalization, so the honest report is
  ``UNCALIBRATED`` and the pipeline's job is to REFUSE. Tested as a refusal, not as a value.

Nothing here touches a network. The seam is ``httpx.MockTransport`` behind the adapter's own
shared-client constructor argument, so the SSE frames are decoded by the reader this adapter
actually ships rather than by a helper written in this file that skips comment lines by
construction.
"""

from __future__ import annotations

import asyncio
import json
import time
from collections.abc import AsyncIterator, Sequence
from pathlib import Path
from typing import Any, Final

import httpx
import pytest
from pydantic import SecretStr

from app.core.errors import FALLBACK_ELIGIBLE, ErrorClass, KbError
from app.providers.capabilities import rerank_scale
from app.providers.contract import (
    Capability,
    ChatRequest,
    ChatResult,
    ContextBlock,
    Delta,
    EmbeddingInputType,
    EmbeddingRequest,
    Message,
    ModelCapabilities,
    ReasoningOption,
    RerankRequest,
    RerankScale,
    StopReason,
    Timeouts,
    Usage,
)
from app.providers.errors import ProviderSurface, fallback_eligible
from app.providers.openrouter import (
    EFFORT,
    EMBEDDINGS_URL,
    ERROR_TYPE_TO_CLASS,
    ESTIMATED_CHARS_PER_TOKEN,
    RERANK_URL,
    ROUNDED_EFFORT,
    STATIC_HEADERS,
    STATUS_TO_CLASS,
    STOP,
    UNPINNED,
    UNSATISFIABLE_ROUTING,
    URL,
    OpenRouterAdapter,
    RoutingPin,
    WireError,
)
from app.rag.rerank import RerankNotCalibrated, calibration_for

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BT"
CONNECTION: Final = "01JQZ0000000000000000000CN"
CHUNK: Final = "01JQZ0000000000000000000CH"

MODEL: Final = "anthropic/claude-sonnet-5"
EMBED_MODEL: Final = "openai/text-embedding-3-small"
RERANK_MODEL: Final = "cohere/rerank-v3.5"
GENERATION_ID: Final = "gen-fixture-01JQZ"

#: The deployment's own public URL. Grepped for on the wire: it must be THIS value and never
#: anything derived from an inbound request.
APP_URL: Final = "https://app.knowledgebot.test"

#: The fixture credential and the fixture system prompt. Both are grepped for in a serialized
#: diagnostics payload; neither may appear in one.
API_KEY: Final = "sk-or-v1-fixture-must-never-appear"
SYSTEM_PROMPT: Final = "You are Aurelia, the FIXTURE-SYSTEM-PROMPT-CANARY assistant."
TENANT_CHUNK_TEXT: Final = "TENANT-DOCUMENT-CANARY: the key rotates from the console."

CHAT_FLAGS: Final = frozenset(
    {
        Capability.TEXT,
        Capability.TOOL_USE,
        Capability.STRUCTURED_OUTPUT,
        Capability.REASONING,
        Capability.REASONING_TRACE,
        Capability.SAMPLING,
        Capability.PROMPT_CACHING,
        Capability.STREAM_USAGE,
    }
)


# ── fixtures: the request side ────────────────────────────────────────────────


def caps_for(
    *,
    supported: frozenset[Capability] = CHAT_FLAGS,
    on_unsupported: str = "reject",
    context_window: int = 1_000_000,
    max_output_tokens: int = 64_000,
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=context_window,
        max_output_tokens=max_output_tokens,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(**overrides: Any) -> ChatRequest:
    fields: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": "trace-openrouter",
        "provider_connection_id": CONNECTION,
        "model": MODEL,
        "system": SYSTEM_PROMPT,
        "messages": [Message(role="user", content="How do I rotate the key?")],
        "context_blocks": [
            ContextBlock(index=1, chunk_id=CHUNK, title="Key rotation", text=TENANT_CHUNK_TEXT)
        ],
        "max_output_tokens": 1024,
    }
    fields.update(overrides)
    return ChatRequest(**fields)


def embedding_request_for(**overrides: Any) -> EmbeddingRequest:
    fields: dict[str, Any] = {
        "org_id": ORG,
        "trace_id": "trace-openrouter-embed",
        "provider_connection_id": CONNECTION,
        "model": EMBED_MODEL,
        "texts": ["first passage", "second passage", "third passage"],
        "input_type": EmbeddingInputType.PASSAGE,
    }
    fields.update(overrides)
    return EmbeddingRequest(**fields)


def rerank_request_for(**overrides: Any) -> RerankRequest:
    fields: dict[str, Any] = {
        "org_id": ORG,
        "trace_id": "trace-openrouter-rerank",
        "provider_connection_id": CONNECTION,
        "model": RERANK_MODEL,
        "query": "how do I rotate the key",
        "passages": ["about billing", "about key rotation", "about the weather"],
    }
    fields.update(overrides)
    return RerankRequest(**fields)


# ── fixtures: the wire side ───────────────────────────────────────────────────
#
# Raw SSE bytes over `httpx.MockTransport`, so the reader under test is the one this adapter
# ships. A frame may carry a delay, which is what makes the fixture arrive OVER TIME rather than
# all at once — a single-frame fixture passes for a buffered implementation.

KEEP_ALIVE: Final = b": OPENROUTER PROCESSING\n\n"
DONE: Final = b"data: [DONE]\n\n"

Frames = Sequence[bytes | tuple[float, bytes]]


def frame(payload: dict[str, Any]) -> bytes:
    return f"data: {json.dumps(payload)}\n\n".encode()


def delta_chunk(
    *,
    content: str | None = None,
    reasoning: str | None = None,
    refusal: str | None = None,
    tool_arguments: str | None = None,
    finish: str | None = None,
    native_finish: str | None = None,
    model: str = MODEL,
) -> bytes:
    delta: dict[str, Any] = {}
    if content is not None:
        delta["content"] = content
    if reasoning is not None:
        delta["reasoning"] = reasoning
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
    choice: dict[str, Any] = {"index": 0, "delta": delta, "finish_reason": finish}
    if native_finish is not None:
        choice["native_finish_reason"] = native_finish
    return frame(
        {
            "id": GENERATION_ID,
            "object": "chat.completion.chunk",
            "created": 1_770_000_000,
            "model": model,
            "choices": [choice],
        }
    )


def final_chunk(
    *,
    prompt: int = 1000,
    cached: int | None = 640,
    completion: int = 120,
    reasoning: int = 40,
    cost: float = 0.0123,
    upstream: str | None = "Anthropic",
    attempt: int = 1,
) -> bytes:
    """The FINAL chunk: choice-less, and the only place usage and attribution arrive.

    A reader that stops at the last text delta loses both — the usage row reads as a free call
    and ``Diagnostics.served_by`` is null forever.
    """
    usage: dict[str, Any] = {
        "prompt_tokens": prompt,
        "completion_tokens": completion,
        "total_tokens": prompt + completion,
        "completion_tokens_details": {"reasoning_tokens": reasoning},
        "cost": cost,
        "cost_details": {"upstream_inference_cost": None},
    }
    if cached is not None:
        usage["prompt_tokens_details"] = {"cached_tokens": cached}
    payload: dict[str, Any] = {
        "id": GENERATION_ID,
        "object": "chat.completion.chunk",
        "model": MODEL,
        "choices": [],
        "usage": usage,
    }
    if upstream is not None:
        payload["openrouter_metadata"] = {
            "strategy": "order",
            "attempt": attempt,
            "requested": MODEL,
            "endpoints": {
                "available": [
                    {"provider_name": "Together", "selected": False},
                    {"provider_name": upstream, "selected": True},
                ]
            },
        }
    return frame(payload)


def error_frame(
    *,
    code: int = 502,
    error_type: str | None = "provider_unavailable",
    provider_code: str | None = "overloaded_error",
    provider_name: str | None = "Anthropic",
) -> bytes:
    """A mid-stream error, delivered INSIDE an HTTP 200 with ``finish_reason: "error"``."""
    metadata: dict[str, Any] = {}
    if error_type is not None:
        metadata["error_type"] = error_type
    if provider_code is not None:
        metadata["provider_code"] = provider_code
    if provider_name is not None:
        metadata["provider_name"] = provider_name
    return frame(
        {
            "id": GENERATION_ID,
            "object": "chat.completion.chunk",
            "model": MODEL,
            "error": {"code": code, "message": SYSTEM_PROMPT, "metadata": metadata},
            "choices": [{"index": 0, "delta": {}, "finish_reason": "error"}],
        }
    )


def error_body(
    *,
    code: int,
    error_type: str | None,
    provider_code: str | None = None,
    metadata_extra: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """The pre-stream envelope. Always ``{"error": {"code", "message", "metadata"}}``.

    The message is deliberately hostile: OpenRouter's error bodies echo request material, and
    on this service the request carries the packed prompt and the tenant's retrieved chunks.
    """
    metadata: dict[str, Any] = dict(metadata_extra or {})
    if error_type is not None:
        metadata["error_type"] = error_type
    if provider_code is not None:
        metadata["provider_code"] = provider_code
    return {
        "id": GENERATION_ID,
        "error": {"code": code, "message": f"upstream said: {SYSTEM_PROMPT}", "metadata": metadata},
    }


class Recorder:
    """The transport seam. Records every request and serves a scripted response."""

    def __init__(self) -> None:
        self.requests: list[httpx.Request] = []
        self.bodies: list[dict[str, Any]] = []

    def record(self, request: httpx.Request) -> None:
        self.requests.append(request)
        self.bodies.append(json.loads(request.content))

    @property
    def body(self) -> dict[str, Any]:
        assert len(self.bodies) == 1, "exactly one request per fixture"
        return self.bodies[0]

    @property
    def request(self) -> httpx.Request:
        assert len(self.requests) == 1, "exactly one request per fixture"
        return self.requests[0]


def streaming_adapter(
    frames: Frames,
    *,
    status: int = 200,
    body: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    pin: RoutingPin | None = None,
) -> tuple[OpenRouterAdapter, Recorder]:
    recorder = Recorder()

    async def stream_bytes() -> AsyncIterator[bytes]:
        for item in frames:
            delay, payload = item if isinstance(item, tuple) else (0.0, item)
            if delay:
                await asyncio.sleep(delay)
            yield payload

    def handler(request: httpx.Request) -> httpx.Response:
        recorder.record(request)
        if status >= 400:
            return httpx.Response(status, headers=headers or {}, json=body)
        return httpx.Response(status, headers=headers or {}, content=stream_bytes())

    adapter = OpenRouterAdapter(
        http=httpx.AsyncClient(transport=httpx.MockTransport(handler)),
        public_app_url=APP_URL,
    )
    if pin is not None:
        adapter._pin_for = lambda req: pin  # type: ignore[method-assign]
    return adapter, recorder


def json_adapter(
    payload: Any, *, status: int = 200, headers: dict[str, str] | None = None
) -> tuple[OpenRouterAdapter, Recorder]:
    """The non-streaming seam, for ``embed`` and ``rerank``."""
    recorder = Recorder()

    def handler(request: httpx.Request) -> httpx.Response:
        recorder.record(request)
        return httpx.Response(status, headers=headers or {}, json=payload)

    return (
        OpenRouterAdapter(
            http=httpx.AsyncClient(transport=httpx.MockTransport(handler)),
            public_app_url=APP_URL,
        ),
        recorder,
    )


async def run(
    adapter: OpenRouterAdapter,
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


# ── the request body: the routing block is the whole invariant ────────────────


def test_require_parameters_is_true_on_the_serialized_chat_body() -> None:
    """Asserted on the BODY and not on a flag, because the failure is a 200.

    With ``require_parameters`` false — the vendor's default — an upstream that cannot honour
    ``response_format`` is routed to anyway and the field is dropped: HTTP 200, plausible prose,
    no schema, no error. A boolean on the adapter proves somebody meant to send it.
    """
    body = OpenRouterAdapter(http=None, public_app_url=APP_URL)._body(
        request_for(), caps_for(), RoutingPin(slugs=("anthropic",))
    )
    assert body["provider"]["require_parameters"] is True
    assert body["provider"]["allow_fallbacks"] is False
    assert body["provider"]["data_collection"] == "deny"
    assert body["provider"]["order"] == ["anthropic"]


def test_the_gateways_own_fallback_layer_is_never_on_the_wire() -> None:
    """``models`` is OpenRouter's model-level fallback: it writes no ``provider_calls`` row we
    can read, bills at another upstream's price, and is the fallback-inside-fallback
    amplification the taxonomy forbids. ``debug`` echoes the assembled prompt."""
    serialized = json.dumps(
        OpenRouterAdapter(http=None, public_app_url=APP_URL)._body(
            request_for(), caps_for(), UNPINNED
        )
    )
    for banned in ("models", "route", "debug", "echo_upstream_body"):
        assert f'"{banned}"' not in serialized


def test_zdr_rides_only_when_the_pin_asks_for_it() -> None:
    """It narrows the eligible upstream set, so an unnecessary narrowing reads as an outage."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    unpinned = adapter._body(request_for(), caps_for(), RoutingPin(slugs=("a",)))
    assert "zdr" not in unpinned["provider"]
    pinned = adapter._body(request_for(), caps_for(), RoutingPin(slugs=("a",), zdr=True))
    assert pinned["provider"]["zdr"] is True


def test_an_unpinned_request_still_forbids_the_gateways_own_fallback() -> None:
    """``routing_pin`` has no path across the internal contract yet — see ``UNPINNED``.

    The pin's absence costs the CHOICE of upstream and must not cost the invariant: with no
    ``order``, ``allow_fallbacks: false`` still goes out, so the gateway may not re-route after
    the upstream it picked fails.
    """
    block = OpenRouterAdapter(http=None, public_app_url=APP_URL)._body(
        request_for(), caps_for(), UNPINNED
    )["provider"]
    assert "order" not in block
    assert block["allow_fallbacks"] is False
    assert block["require_parameters"] is True


def test_the_evidence_is_a_user_role_data_section_and_never_the_instruction_slot() -> None:
    """Source text that can reach ``system`` is prompt injection with our own retrieval pipeline
    as the delivery mechanism."""
    body = OpenRouterAdapter(http=None, public_app_url=APP_URL)._body(
        request_for(), caps_for(), UNPINNED
    )
    system = next(m for m in body["messages"] if m["role"] == "system")
    assert system["content"] == SYSTEM_PROMPT
    assert TENANT_CHUNK_TEXT not in system["content"]
    evidence = body["messages"][1]
    assert evidence["role"] == "user"
    assert TENANT_CHUNK_TEXT in evidence["content"]


def test_structured_output_carries_strict_inside_the_json_schema() -> None:
    """``require_parameters`` decides whether an upstream WITHOUT response_format is routed to;
    ``strict`` decides whether one that HAS it enforces the schema or treats it as a hint. Both
    are needed, and neither substitutes for the other."""
    body = OpenRouterAdapter(http=None, public_app_url=APP_URL)._body(
        request_for(response_schema={"type": "object", "properties": {}}), caps_for(), UNPINNED
    )
    assert body["response_format"]["json_schema"]["strict"] is True


# ── attribution headers are OURS; the credential is the tenant's ──────────────


async def test_the_metadata_header_is_sent_and_the_referer_is_the_configured_app_url() -> None:
    """Omit ``X-OpenRouter-Metadata`` and ``served_by`` is null forever, with nothing to read.

    ``HTTP-Referer`` names OUR public URL and is provably independent of the inbound request:
    the widget runs on customer sites, so a header filled from a request would publish every
    embedding origin onto a third party's public app-rankings page.
    """
    adapter, recorder = streaming_adapter([delta_chunk(finish="stop"), final_chunk(), DONE])
    await run(adapter)

    headers = recorder.request.headers
    assert headers["x-openrouter-metadata"] == "enabled"
    assert headers["x-openrouter-title"] == STATIC_HEADERS["X-OpenRouter-Title"]
    assert headers["http-referer"] == APP_URL
    assert "knowledgebot.test" in headers["http-referer"], "our host, not the tenant's"


def test_the_credential_is_unwrapped_exactly_once_in_the_module() -> None:
    """``get_secret_value()`` once, so extracting the key is a greppable act rather than an
    accident of serialization. One site serves all three surfaces because the three routes share
    a credential, a host and an auth scheme — three unwrap sites are three chances for one to end
    up inside an f-string that reaches a log."""
    source = Path(OpenRouterAdapter.__module__.replace(".", "/") + ".py").read_text()
    assert source.count("get_secret_value()") == 1


async def test_the_diagnostics_payload_carries_no_prompt_no_chunk_and_no_credential() -> None:
    """A vendor error body routinely echoes the request, and the request contains the packed
    prompt, which contains tenant document text. The allow-list runs at the parse site."""
    adapter, _ = streaming_adapter(
        [error_frame(), DONE],
    )
    _, result = await run(adapter)
    payload = result.model_dump_json()

    assert API_KEY not in payload
    assert SYSTEM_PROMPT not in payload
    assert TENANT_CHUNK_TEXT not in payload


async def test_a_moderation_body_never_carries_flagged_input_into_diagnostics() -> None:
    """A 403 moderation block returns up to 100 characters of the user's own question or of a
    retrieved chunk. Dropped where the shape is known, not scrubbed downstream."""
    adapter, _ = streaming_adapter(
        [],
        status=403,
        body=error_body(
            code=403,
            error_type="content_policy_violation",
            metadata_extra={
                "reasons": ["violence"],
                "provider_name": "Anthropic",
                "flagged_input": TENANT_CHUNK_TEXT,
            },
        ),
    )
    _, result = await run(adapter)

    assert TENANT_CHUNK_TEXT not in result.model_dump_json()
    assert result.diagnostics.extras["error_metadata"]["reasons"] == ["violence"]
    assert result.diagnostics.extras["error_metadata"]["provider_name"] == "Anthropic"
    assert "flagged_input" not in result.diagnostics.extras["error_metadata"]


# ── the stream: comments, timing, and the final chunk ─────────────────────────


async def test_a_stream_opening_with_keep_alive_comments_parses_and_arrives_over_time() -> None:
    """Two failures in one fixture.

    ``: OPENROUTER PROCESSING`` is not JSON, and a reader that hands it to ``json.loads``
    crashes on the first one under load — which is exactly when they appear. And the frames
    arrive 60 ms apart, so a buffered implementation that reads the whole body before yielding
    reports a first token that is indistinguishable from a stall.
    """
    frames: Frames = [
        (0.02, KEEP_ALIVE),
        (0.06, KEEP_ALIVE),
        (0.06, delta_chunk(content="Rotate it ")),
        delta_chunk(content="from the console."),
        delta_chunk(finish="stop"),
        final_chunk(),
        DONE,
    ]
    adapter, _ = streaming_adapter(frames)
    deltas, result = await run(adapter)

    assert [(d.kind, d.text) for d in deltas] == [
        ("text", "Rotate it "),
        ("text", "from the console."),
    ]
    assert result.text == "Rotate it from the console."
    assert result.stop_reason is StopReason.COMPLETE
    assert result.first_token_ms is not None
    assert result.first_token_ms >= 100, (
        "TTFT was stamped on the first frame rather than on the first TEXT delta — the "
        "measurement that saturation is invisible to"
    )
    # THE ASSERTION WITH TEETH, and it is not "the stream survived". A reader that hands the
    # comment to `json.loads` and swallows the failure ALSO survives — it just records an
    # unparseable frame for every keep-alive, which under load is most of the stream and reads
    # in an incident as a malformed vendor response. Recognising the comment and failing to
    # parse it are different behaviours and only this tells them apart.
    assert "unparsed_frames" not in result.diagnostics.extras


async def test_a_frame_that_is_genuinely_unparseable_is_counted_and_not_silently_equal() -> None:
    """The control for the assertion above: the counter exists and does move.

    Without this, ``unparsed_frames not in extras`` would pass for an adapter that never counts
    anything, and the keep-alive test would be asserting the absence of a mechanism rather than
    the presence of one.
    """
    adapter, _ = streaming_adapter(
        [b"data: {not json at all\n\n", delta_chunk(finish="stop"), final_chunk(), DONE]
    )
    _, result = await run(adapter)
    assert result.diagnostics.extras["unparsed_frames"] == 1
    assert result.stop_reason is StopReason.COMPLETE, "one bad frame does not fail a good turn"


async def test_the_deltas_reach_the_consumer_before_the_stream_ends() -> None:
    """The buffering test with teeth: the fixture REFUSES to produce its second half until the
    consumer has been handed the first delta.

    A single-frame fixture, or one that merely sleeps, passes for an implementation that reads
    the whole body and then replays it. This one deadlocks against that implementation and the
    ``wait_for`` turns the deadlock into a failure.
    """
    delivered = asyncio.Event()

    async def stream_bytes() -> AsyncIterator[bytes]:
        yield delta_chunk(content="first")
        await delivered.wait()
        yield delta_chunk(content="second")
        yield delta_chunk(finish="stop")
        yield final_chunk()
        yield DONE

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=stream_bytes())

    adapter = OpenRouterAdapter(
        http=httpx.AsyncClient(transport=httpx.MockTransport(handler)),
        public_app_url=APP_URL,
    )

    async def drain() -> str:
        parts: list[str] = []
        async for event in adapter.stream(request_for(), caps_for(), SecretStr(API_KEY)):
            if isinstance(event, Delta):
                parts.append(event.text)
                delivered.set()
        return "".join(parts)

    assert await asyncio.wait_for(drain(), 2.0) == "firstsecond"


async def test_a_stream_of_nothing_but_keep_alives_ends_as_capacity_not_as_a_hang() -> None:
    """The keep-alives are BYTES, so every one of them resets the transport read timeout while
    the reader yields no chunk at all. Neither the socket timer nor a per-chunk check can fire,
    so the explicit first-token timer is the only bound there is — and it has to read as
    ``provider_temporary``, or a connection configured to fall back never fires."""
    frames: Frames = [(0.01, KEEP_ALIVE) for _ in range(200)]
    adapter, _ = streaming_adapter(frames)
    req = request_for(timeouts=Timeouts(connect=1.0, first_token=0.05, total=5.0))
    _, result = await run(adapter, req)

    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.first_token_ms is None
    assert result.text == ""


async def test_a_pin_reaches_the_wire_as_provider_order() -> None:
    """The seam that closes when ``provider_models.routing_pin`` crosses the internal contract.

    One slug means a deterministic upstream, which is also the precondition for using an
    OpenRouter model as a fallback target at all: falling back from a direct vendor to the same
    vendor's model here routes straight back into the outage being escaped, at a markup.
    """
    adapter, recorder = streaming_adapter(
        [delta_chunk(finish="stop"), final_chunk(), DONE],
        pin=RoutingPin(slugs=("anthropic",), zdr=True),
    )
    _, result = await run(adapter)

    assert recorder.body["provider"]["order"] == ["anthropic"]
    assert recorder.body["provider"]["zdr"] is True
    assert "routing_pin" not in result.diagnostics.extras


async def test_an_unpinned_call_records_the_absence_rather_than_implying_it() -> None:
    adapter, recorder = streaming_adapter([delta_chunk(finish="stop"), final_chunk(), DONE])
    _, result = await run(adapter)

    assert "order" not in recorder.body["provider"]
    assert result.diagnostics.extras["routing_pin"] == "unpinned"


async def test_served_by_is_read_from_openrouter_metadata_on_the_final_chunk() -> None:
    """The final chunk is choice-less, so a reader that stops at the last text delta loses both
    the usage numbers and the attribution — and upstream-scoped breaker keying silently degrades
    to gateway-wide with nothing to read."""
    adapter, recorder = streaming_adapter(
        [
            delta_chunk(content="answer"),
            delta_chunk(finish="stop"),
            final_chunk(upstream="Anthropic", attempt=2),
            DONE,
        ]
    )
    _, result = await run(adapter)

    assert recorder.request.headers["x-openrouter-metadata"] == "enabled"
    assert result.diagnostics.served_by == "Anthropic", "the SELECTED endpoint, not the first"
    assert result.diagnostics.extras["routing_strategy"] == "order"
    assert result.diagnostics.extras["routing_attempt"] == 2, (
        "an attempt above 1 means the gateway already retried an upstream inside this one call"
    )


async def test_the_generation_id_is_read_off_the_body_on_success_and_on_failure() -> None:
    """It is in the body rather than in a header, so a reader that captures only headers has
    nothing to quote to support on precisely the failures worth asking about."""
    adapter, _ = streaming_adapter([delta_chunk(finish="stop"), final_chunk(), DONE])
    _, ok = await run(adapter)
    assert ok.provider_request_id == GENERATION_ID

    failing, _ = streaming_adapter(
        [], status=429, body=error_body(code=429, error_type="rate_limit_exceeded")
    )
    _, failed = await run(failing)
    assert failed.provider_request_id == GENERATION_ID


async def test_reasoning_deltas_are_gated_on_the_trace_flag_and_still_counted() -> None:
    """A pane gated on REASONING alone renders blank forever while the trace is billed."""
    frames: Frames = [
        delta_chunk(reasoning="weighing the rotation steps"),
        delta_chunk(content="Rotate it."),
        delta_chunk(finish="stop"),
        final_chunk(),
        DONE,
    ]
    adapter, _ = streaming_adapter(frames)
    with_trace, _ = await run(adapter)
    assert [d.kind for d in with_trace] == ["reasoning", "text"]

    adapter, _ = streaming_adapter(frames)
    without_trace, result = await run(
        adapter, caps=caps_for(supported=CHAT_FLAGS - {Capability.REASONING_TRACE})
    )
    assert [d.kind for d in without_trace] == ["text"]
    assert result.usage.reasoning_tokens == 40, "billed either way"


# ── stop reasons: no length cap reaches COMPLETE ──────────────────────────────


@pytest.mark.parametrize(
    ("finish", "expected", "has_error_class"),
    [
        ("stop", StopReason.COMPLETE, False),
        ("length", StopReason.MAX_OUTPUT, False),
        ("tool_calls", StopReason.TOOL_USE, False),
        ("content_filter", StopReason.REFUSAL, False),
        ("error", StopReason.ERROR, True),
        # A value the vendor added this morning. This is the vendor that explicitly instructs
        # clients to tolerate unknown enum values, so it is the expected case, not the exotic one.
        ("eos", StopReason.ERROR, True),
        ("stop_sequence_hit", StopReason.ERROR, True),
    ],
)
async def test_finish_reasons_map_without_a_fall_through_to_complete(
    finish: str, expected: StopReason, has_error_class: bool
) -> None:
    """A truncated answer presented as complete is the worst failure this layer can cause: the
    user reads a confident half-sentence and nothing errors anywhere."""
    adapter, _ = streaming_adapter(
        [delta_chunk(content="half an answer"), delta_chunk(finish=finish), final_chunk(), DONE]
    )
    _, result = await run(adapter)

    assert result.stop_reason is expected
    assert result.diagnostics.native_stop_reason == finish, "the vendor's own word, preserved"
    assert (result.error_class is not None) is has_error_class


async def test_a_length_cap_is_never_complete_even_with_text_in_hand() -> None:
    adapter, _ = streaming_adapter(
        [delta_chunk(content="a confident half-sen"), delta_chunk(finish="length"), DONE]
    )
    _, result = await run(adapter)
    assert result.stop_reason is StopReason.MAX_OUTPUT
    assert result.text == "a confident half-sen"


async def test_a_stream_that_ends_with_no_finish_reason_is_an_error_not_a_success() -> None:
    """A connection dropped mid-answer. Unknown is PERMANENT by design: a temporary default
    retries a request that will never succeed."""
    adapter, _ = streaming_adapter([delta_chunk(content="half"), DONE])
    _, result = await run(adapter)
    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_PERMANENT_REQUEST.value


async def test_the_upstreams_own_word_rides_beside_the_gateways_normalization() -> None:
    """``native_stop_reason`` carries the value this adapter MAPPED, because that is the one a
    vendor can add a member to; the upstream's raw vocabulary is recorded separately."""
    adapter, _ = streaming_adapter(
        [delta_chunk(finish="stop", native_finish="end_turn"), final_chunk(), DONE]
    )
    _, result = await run(adapter)
    assert result.diagnostics.native_stop_reason == "stop"
    assert result.diagnostics.extras["native_finish_reason"] == "end_turn"


# ── the in-band error inside an HTTP 200 ─────────────────────────────────────


async def test_a_mid_stream_error_frame_terminates_with_an_error_class_not_a_truncation() -> None:
    """``finish_reason: "error"`` inside an HTTP 200, and the reader files it as a completed
    answer. Headers were committed long before the failure, so the status stays 200 while the
    chunk carries a top-level ``error`` object — and a reader that inspects only the status
    records a successful call with truncated text and no ``error_class``."""
    adapter, _ = streaming_adapter(
        [
            delta_chunk(content="The first half "),
            (0.02, delta_chunk(content="of the answer")),
            (0.02, error_frame(code=502, error_type="provider_unavailable")),
            DONE,
        ]
    )
    deltas, result = await run(adapter)

    assert [d.text for d in deltas] == ["The first half ", "of the answer"]
    assert result.stop_reason is StopReason.ERROR, "never a completed answer"
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.text == "The first half of the answer", "what was streamed is still returned"
    assert result.usage.source == "estimated", "usage never arrived; nothing may be invoiced"


async def test_an_in_band_error_is_attributed_to_the_upstream_that_produced_it() -> None:
    """``metadata.provider_code`` present means the upstream produced it, and the breaker key
    gains a fourth term. A brownout on one upstream must not open the breaker for every other
    model riding the same credential."""
    adapter, _ = streaming_adapter([error_frame(provider_code="overloaded_error"), DONE])
    _, result = await run(adapter)

    assert result.diagnostics.extras["failure_scope"] == "upstream"
    assert result.diagnostics.extras["upstream_slug"] == "Anthropic"


async def test_a_gateway_error_with_no_provider_code_opens_the_account_wide_key() -> None:
    """402, a platform 429, a 401, a moderation 403 and an unsatisfiable 503 are properties of
    the ACCOUNT, so they key on ``(org_id, connection_id, "_openrouter")``."""
    adapter, _ = streaming_adapter(
        [], status=402, body=error_body(code=402, error_type="payment_required")
    )
    _, result = await run(adapter)

    assert result.error_class == ErrorClass.PROVIDER_BILLING.value
    assert result.diagnostics.extras["failure_scope"] == "gateway"
    assert "upstream_slug" not in result.diagnostics.extras


def test_attribution_runs_on_a_body_with_no_error_object_at_all() -> None:
    """A connect timeout has no body. Deliberately pessimistic: the gateway is the only hop we
    know was involved."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    assert adapter.attribute(None, None).scope == "gateway"
    assert adapter.attribute({"error": {"code": 500}}, 500).scope == "gateway"


# ── classification: the code first, the status as a tiebreak, the message never ──


@pytest.mark.parametrize(
    ("error_type", "status", "expected"),
    [
        ("authentication", 401, ErrorClass.PROVIDER_AUTH),
        # 402 is `provider_billing` and NOT `provider_auth`. The policy is identical; the class
        # is what a dashboard and a runbook key on, so an exhausted balance must not read as a
        # revoked key.
        ("payment_required", 402, ErrorClass.PROVIDER_BILLING),
        ("rate_limit_exceeded", 429, ErrorClass.PROVIDER_RATE_LIMIT),
        ("content_policy_violation", 403, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("refusal", 200, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("permission_denied", 403, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("invalid_request", 400, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("context_length_exceeded", 400, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("max_tokens_exceeded", 400, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("payload_too_large", 413, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        ("invalid_image", 400, ErrorClass.PROVIDER_PERMANENT_REQUEST),
        # The 503 that IS an outage.
        ("provider_overloaded", 503, ErrorClass.PROVIDER_TEMPORARY),
        ("provider_unavailable", 502, ErrorClass.PROVIDER_TEMPORARY),
        ("timeout", 504, ErrorClass.PROVIDER_TEMPORARY),
        ("server", 500, ErrorClass.PROVIDER_TEMPORARY),
        ("unmapped", 500, ErrorClass.PROVIDER_TEMPORARY),
    ],
)
def test_the_error_type_decides_the_class_before_the_status_does(
    error_type: str, status: int, expected: ErrorClass
) -> None:
    failure = OpenRouterAdapter(http=None, public_app_url=APP_URL).classify(
        WireError(error_body(code=status, error_type=error_type), status=status),
        tokens_emitted=0,
    )
    assert failure.error_class is expected
    assert failure.native_code == error_type
    assert ERROR_TYPE_TO_CLASS[error_type] is expected


def test_the_two_meanings_of_503_are_split_on_the_error_type_and_not_the_status() -> None:
    """A 503 retries three times, falls back, and fails identically everywhere — because it was
    never an outage. ``provider_overloaded`` is capacity; a 503 with NO ``error_type`` is our own
    ``order``/``zdr``/``require_parameters`` block excluding every endpoint, which is
    deterministic and permanent. And ``require_parameters: true`` is what makes the second one
    the COMMON case rather than the rare one.
    """
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)

    capacity = adapter.classify(
        WireError(error_body(code=503, error_type="provider_overloaded"), status=503),
        tokens_emitted=0,
    )
    unsatisfiable = adapter.classify(
        WireError(error_body(code=503, error_type=None), status=503), tokens_emitted=0
    )

    assert capacity.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert fallback_eligible(capacity.error_class, ProviderSurface.CHAT) is True

    assert unsatisfiable.error_class is UNSATISFIABLE_ROUTING
    assert unsatisfiable.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST
    assert fallback_eligible(unsatisfiable.error_class, ProviderSurface.CHAT) is False
    assert STATUS_TO_CLASS[503] is UNSATISFIABLE_ROUTING


@pytest.mark.parametrize(
    ("status", "error_type"),
    [
        (404, None),
        (404, "invalid_request"),
        (400, "invalid_request"),
    ],
)
async def test_a_slug_that_no_longer_resolves_is_permanent_and_does_not_fall_back(
    status: int, error_type: str | None
) -> None:
    """The catalogue is third-party and volatile, so this LOOKS like a lifecycle event.

    It is not. The id came from the bot's own configuration snapshot, so falling back would
    serve every answer from a model the tenant never chose, at another price and another
    quality, on a bot that still reads ``Ready`` — discovered from an invoice months later.
    """
    adapter, _ = streaming_adapter(
        [], status=status, body=error_body(code=status, error_type=error_type)
    )
    _, result = await run(adapter)

    assert result.error_class == ErrorClass.PROVIDER_PERMANENT_REQUEST.value
    assert FALLBACK_ELIGIBLE[ErrorClass.PROVIDER_PERMANENT_REQUEST] is False
    assert (
        fallback_eligible(ErrorClass.PROVIDER_PERMANENT_REQUEST, ProviderSurface.CHAT) is False
    ), "an unrecognised slug must never be answered by a different vendor"


def test_the_vendors_message_never_crosses_into_the_classified_error() -> None:
    """401 and 422 bodies routinely echo the request, and the request carries the packed prompt.
    What crosses is the closed-vocabulary ``error_type`` plus our own sentence."""
    failure = OpenRouterAdapter(http=None, public_app_url=APP_URL).classify(
        WireError(error_body(code=401, error_type="authentication"), status=401), tokens_emitted=0
    )
    assert SYSTEM_PROMPT not in str(failure)
    assert TENANT_CHUNK_TEXT not in str(failure)


def test_a_transport_failure_with_no_body_is_temporary() -> None:
    """Nothing arrived, so there is no body and no code — the absence IS the evidence."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    for exc in (
        httpx.ConnectError("refused"),
        httpx.ReadTimeout("slow"),
        TimeoutError(),
    ):
        assert adapter.classify(exc, tokens_emitted=0).error_class is ErrorClass.PROVIDER_TEMPORARY


def test_an_unmapped_exception_is_permanent_and_never_temporary() -> None:
    """Unknown must never default to temporary, or a request that can never succeed is retried
    forever and the fact that something new appeared is hidden."""
    failure = OpenRouterAdapter(http=None, public_app_url=APP_URL).classify(
        RuntimeError("something new"), tokens_emitted=0
    )
    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


async def test_a_rate_limit_reply_carries_the_parsed_headers_and_a_retry_after_floor() -> None:
    """Parsed when present and never invented: a fabricated reset time outranks the jittered
    backoff and pins the caller inside the rejection window."""
    reset = str(int(time.time()) + 30)
    adapter, _ = streaming_adapter(
        [],
        status=429,
        body=error_body(code=429, error_type="rate_limit_exceeded"),
        headers={"x-ratelimit-remaining": "0", "x-ratelimit-reset": reset},
    )
    _, result = await run(adapter)

    assert result.error_class == ErrorClass.PROVIDER_RATE_LIMIT.value
    assert result.diagnostics.rate_limit["x-ratelimit-remaining"] == "0"
    assert result.diagnostics.rate_limit["x-ratelimit-reset"] == reset


# ── usage: the OpenAI SUBSET arithmetic, and a cost that is not a token ───────


def test_the_cached_amount_is_a_subset_and_is_subtracted_out() -> None:
    """OpenAI's arithmetic, not Anthropic's and not DeepSeek's. On this surface the cached count
    is PART OF ``prompt_tokens``, so it is subtracted to keep the buckets disjoint. Getting the
    direction wrong on a 200k-token cached document is a five-figure reporting error in whichever
    direction was guessed."""
    usage = OpenRouterAdapter._usage(
        {
            "prompt_tokens": 1000,
            "completion_tokens": 120,
            "prompt_tokens_details": {"cached_tokens": 640},
            "completion_tokens_details": {"reasoning_tokens": 40},
        }
    )
    assert usage.input_tokens == 360
    assert usage.cache_read_tokens == 640
    assert usage.cache_write_tokens == 0
    assert usage.output_tokens == 120
    assert usage.reasoning_tokens == 40
    # DISJOINT, and equal to the vendor's own billed input. Billing reads this, never
    # `input_tokens`.
    assert usage.total_input_tokens == 1000
    assert usage.input_tokens + usage.cache_read_tokens + usage.cache_write_tokens == 1000
    assert usage.source == "provider_final"


def test_an_absent_cached_report_leaves_the_whole_prompt_as_uncached_input() -> None:
    """It is upstream-dependent and frequently absent, and absent means zero here rather than an
    inference. ``total_input_tokens`` is right either way."""
    usage = OpenRouterAdapter._usage({"prompt_tokens": 1000, "completion_tokens": 10})
    assert usage.input_tokens == 1000
    assert usage.cache_read_tokens == 0
    assert usage.total_input_tokens == 1000


def test_a_cached_count_larger_than_the_prompt_cannot_inflate_the_billed_input() -> None:
    """A vendor reporting more cached than prompt is a bug. Clamping keeps
    ``total_input_tokens`` equal to the billed input rather than letting a bad report inflate the
    number billing actually reads."""
    usage = OpenRouterAdapter._usage(
        {
            "prompt_tokens": 100,
            "completion_tokens": 5,
            "prompt_tokens_details": {"cached_tokens": 900},
        }
    )
    assert usage.total_input_tokens == 100


def test_an_unreadable_usage_block_degrades_attribution_and_never_the_bill() -> None:
    for raw in (None, {}, {"prompt_tokens": "1000"}):
        assert OpenRouterAdapter._usage(raw).source == "estimated"


async def test_the_credits_cost_reaches_diagnostics_and_never_a_token_bucket() -> None:
    """``usage.cost`` is the authoritative price on this vendor — spend is never computed from
    ``provider_models`` pricing metadata, which describes a model rather than the upstream that
    served it. Every bucket in ``Usage`` is a token count, and credits are not tokens."""
    adapter, _ = streaming_adapter([delta_chunk(finish="stop"), final_chunk(cost=0.0123), DONE])
    _, result = await run(adapter)

    assert result.diagnostics.extras["credits_cost"] == 0.0123
    assert 0.0123 not in Usage.model_validate(result.usage.model_dump()).model_dump().values()
    assert result.usage.total_input_tokens == 1000


# ── cancellation ─────────────────────────────────────────────────────────────


async def test_cancelling_mid_stream_emits_one_terminal_result_and_re_raises() -> None:
    """Emitted from ``except asyncio.CancelledError`` and then re-raised — never from
    ``finally``. Yielding while ``GeneratorExit`` unwinds raises ``RuntimeError: async generator
    ignored GeneratorExit``, ASGI swallows it, and the only symptom is a missing usage row for a
    turn that was billed.

    On this vendor the bill for a cancelled turn is genuinely unknowable — aborting stops
    generation and billing on some upstreams and not others — so the row is ``estimated`` and is
    never invoiced.
    """
    adapter, _ = streaming_adapter(
        [
            delta_chunk(content="The first half of the answer"),
            (0.5, delta_chunk(content=" and the half nobody reads")),
            delta_chunk(finish="stop"),
            final_chunk(),
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
    assert terminal.usage.source == "estimated"
    assert terminal.usage.output_tokens == -(
        -len("The first half of the answer") // int(ESTIMATED_CHARS_PER_TOKEN)
    ), "a cancelled turn is not a free turn"

    with pytest.raises(asyncio.CancelledError):
        await anext(generator)


# ── validate(): reject or warn, never a dropped field ────────────────────────


@pytest.mark.parametrize(
    ("overrides", "missing", "option"),
    [
        ({"temperature": 0.4}, Capability.SAMPLING, "temperature"),
        ({"response_schema": {"type": "object"}}, Capability.STRUCTURED_OUTPUT, "response_schema"),
        (
            {"reasoning": ReasoningOption(effort="high")},
            Capability.REASONING,
            "reasoning",
        ),
        ({"cache_hint": "prefix"}, Capability.PROMPT_CACHING, "cache_hint"),
        ({"stream": False}, None, "stream"),
    ],
)
def test_every_unsupported_option_is_rejected_or_warned_and_never_dropped(
    overrides: dict[str, Any], missing: Capability | None, option: str
) -> None:
    """There is no third path. The third path people reach for is "drop it quietly", which is how
    a bot configured for structured output returns prose for a month — and on THIS vendor the
    quiet drop is the gateway's own documented default."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    supported = CHAT_FLAGS - ({missing} if missing is not None else set())
    req = request_for(**overrides)

    with pytest.raises(KbError) as excinfo:
        adapter.validate(req, caps_for(supported=supported))
    assert excinfo.value.error_class is ErrorClass.VALIDATION

    warnings = adapter.validate(req, caps_for(supported=supported, on_unsupported="warn"))
    assert [w.option for w in warnings if w.option == option] == [option]
    assert all(w.action == "ignored" for w in warnings)


def test_a_rounded_effort_level_is_always_warned_and_always_rounds_down() -> None:
    """An unwarned rounding is indistinguishable from the upstream honouring the level that was
    asked for. Rounding DOWN so a portable request never buys more reasoning than it asked for."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    for level in sorted(ROUNDED_EFFORT):
        warnings = adapter.validate(
            request_for(reasoning=ReasoningOption(effort=level)),
            caps_for(),  # type: ignore[arg-type]
        )
        rounded = [w for w in warnings if w.option == "reasoning.effort"]
        assert len(rounded) == 1
        assert EFFORT[level] in rounded[0].detail
    # And the levels with an exact equivalent are silent.
    for level in sorted(set(EFFORT) - ROUNDED_EFFORT):
        warnings = adapter.validate(
            request_for(reasoning=ReasoningOption(effort=level)),
            caps_for(),  # type: ignore[arg-type]
        )
        assert not [w for w in warnings if w.option == "reasoning.effort"]


def test_a_reasoning_budget_is_reported_rather_than_dropped() -> None:
    """It is reported because NIM ENFORCES it, so a caller reading it as portable would size a
    budget here that silently does nothing."""
    warnings = OpenRouterAdapter(http=None, public_app_url=APP_URL).validate(
        request_for(reasoning=ReasoningOption(effort="high", budget_tokens=2048)),
        caps_for(on_unsupported="warn"),
    )
    assert [w.option for w in warnings] == ["reasoning.budget_tokens"]


@pytest.mark.parametrize(
    "model",
    ["openrouter/auto", "~openai/gpt-latest", "anthropic/claude-sonnet-5:free", "x/y:nitro"],
)
def test_an_auto_or_variant_model_id_is_refused_on_both_settings(model: str) -> None:
    """Each one moves the model out from under the ``capability_flags`` row that is supposed to
    describe it. There is no "send it without the option" form of a model id."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    for setting in ("reject", "warn"):
        with pytest.raises(KbError):
            adapter.validate(request_for(model=model), caps_for(on_unsupported=setting))


def test_an_over_ceiling_output_cap_raises_on_both_settings() -> None:
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    for setting in ("reject", "warn"):
        with pytest.raises(KbError):
            adapter.validate(
                request_for(max_output_tokens=99_999),
                caps_for(max_output_tokens=4096, on_unsupported=setting),
            )


# ── embed(): input order, exact cover, width off the response ────────────────


def embedding_payload(
    *,
    order: Sequence[int] = (2, 0, 1),
    width: int = 4,
    model: str = EMBED_MODEL,
    prompt_tokens: int = 42,
) -> dict[str, Any]:
    """A response deliberately OUT of input order — which is the documented behaviour and the
    reason ``vectors[i]`` may never be read positionally."""
    vectors = {
        0: [1.0, 0.0, 0.0, 0.0][:width],
        1: [0.0, 1.0, 0.0, 0.0][:width],
        2: [0.0, 0.0, 1.0, 0.0][:width],
    }
    return {
        "id": GENERATION_ID,
        "model": model,
        "object": "list",
        "data": [
            {"object": "embedding", "index": index, "embedding": vectors[index]} for index in order
        ],
        "usage": {"prompt_tokens": prompt_tokens, "total_tokens": prompt_tokens, "cost": 0.0001},
    }


async def test_embed_re_sorts_the_response_on_index_and_never_reads_it_positionally() -> None:
    """The positional read is the quietest bug on this surface: a fully populated, fully wrong
    index — every chunk carrying some other chunk's vector, nothing raised, every count matching,
    and retrieval returning plausible neighbours that are not neighbours at all."""
    adapter, recorder = json_adapter(embedding_payload(order=(2, 0, 1)))
    result = await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))

    assert result.vectors == [[1.0, 0.0, 0.0, 0.0], [0.0, 1.0, 0.0, 0.0], [0.0, 0.0, 1.0, 0.0]]
    assert recorder.request.url == httpx.URL(EMBEDDINGS_URL)
    assert recorder.body["provider"]["require_parameters"] is True
    assert recorder.body["provider"]["allow_fallbacks"] is False
    assert recorder.body["provider"]["data_collection"] == "deny"


def caps_embedding(
    *, supported: frozenset[Capability] | None = None, on_unsupported: str = "reject"
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported if supported is not None else frozenset({Capability.EMBEDDING}),
        context_window=8192,
        max_output_tokens=0,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


@pytest.mark.parametrize(
    "order",
    [
        (0, 1),  # a short cover: one input silently unembedded
        (0, 1, 1),  # a duplicated cover: index 2 never answered
    ],
)
async def test_a_partial_or_duplicated_batch_raises_rather_than_returning_what_arrived(
    order: Sequence[int],
) -> None:
    """A batch that half succeeded would write half a source version's vectors, and the version
    would then publish as complete."""
    adapter, _ = json_adapter(embedding_payload(order=order))
    with pytest.raises(KbError) as excinfo:
        await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))
    assert excinfo.value.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


async def test_the_space_takes_its_width_from_the_response_and_its_id_from_what_answered() -> None:
    """Vendors ship new widths on ids that look like old ones, and several APIs take
    ``dimensions`` as a request parameter, so the id does not imply the width. The namespaced
    upstream id rides verbatim: ``openai/text-embedding-3-small`` and
    ``text-embedding-3-small`` are different ``EmbeddingSpace`` values on purpose."""
    adapter, _ = json_adapter(embedding_payload(width=3))
    result = await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))

    assert result.space.dimensions == 3
    assert result.space.provider == "openrouter"
    assert result.space.model == EMBED_MODEL
    assert "/" in result.space.model, "the namespaced upstream id, never the bare one"
    assert result.normalized is True


async def test_a_substituted_embedding_model_is_recorded_rather_than_hidden() -> None:
    """The space is built from the SERVED id — that is the identity a later query has to
    reproduce — and the difference is a warning because only ``canonical_slug`` is documented as
    permanent, so a differing id is as likely to be canonicalization as substitution."""
    adapter, _ = json_adapter(embedding_payload(model="qwen/qwen3-embedding-0.6b"))
    result = await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))

    assert result.space.model == "qwen/qwen3-embedding-0.6b"
    assert [w.option for w in result.diagnostics.warnings] == ["input_type", "model"]


async def test_embedding_usage_is_prompt_tokens_with_nothing_subtracted() -> None:
    """Separate from ``_usage`` on purpose: that one subtracts a reported cached amount because
    on chat the cached count is a SUBSET; this endpoint reports no cached bucket, so sharing the
    helper would subtract zero today and a real number the day the gateway adds one."""
    adapter, _ = json_adapter(embedding_payload(prompt_tokens=1234))
    result = await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))

    assert result.usage.input_tokens == 1234
    assert result.usage.total_input_tokens == 1234
    assert result.usage.output_tokens == 0
    assert result.usage.cache_read_tokens == 0
    assert result.usage.source == "provider_final"
    assert result.diagnostics.extras["credits_cost"] == 0.0001


async def test_an_embedding_failure_raises_and_is_never_a_partial_result() -> None:
    """``EmbeddingResult`` carries no ``error_class`` on purpose: a request/response call has no
    stream to terminate, so a failure is a raised ``ProviderCallFailed``."""
    adapter, _ = json_adapter(error_body(code=429, error_type="rate_limit_exceeded"), status=429)
    with pytest.raises(KbError) as excinfo:
        await adapter.embed(embedding_request_for(), caps_embedding(), SecretStr(API_KEY))
    assert excinfo.value.error_class is ErrorClass.PROVIDER_RATE_LIMIT


def test_the_window_is_enforced_here_because_this_vendor_may_truncate_instead_of_refusing() -> None:
    """The one embedding vendor of the three that may not refuse an over-window input: its own
    Limitations say a text over the maximum "will be truncated OR rejected", with no stated
    default. A truncated passage is indexed from its head with its tail unsearchable forever."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    req = embedding_request_for(texts=["x" * 9000])
    with pytest.raises(KbError) as excinfo:
        adapter.validate_embedding(req, caps_embedding())
    assert excinfo.value.error_class is ErrorClass.VALIDATION
    # And the enforcement is not conditional on `on_unsupported`.
    with pytest.raises(KbError):
        adapter.validate_embedding(req, caps_embedding(on_unsupported="warn"))


def test_input_type_is_always_a_warning_and_never_a_rejection() -> None:
    """The field is required on ``EmbeddingRequest`` precisely so no caller can omit it and be
    silently right on two vendors and wrong on a third. Rejecting it here would refuse EVERY
    embedding call through this gateway."""
    warnings = OpenRouterAdapter(http=None, public_app_url=APP_URL).validate_embedding(
        embedding_request_for(), caps_embedding()
    )
    assert [w.option for w in warnings] == ["input_type"]
    assert warnings[0].action == "ignored"


def test_dimensions_without_the_flag_is_rejected_or_warned() -> None:
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    req = embedding_request_for(dimensions=512)
    with pytest.raises(KbError):
        adapter.validate_embedding(req, caps_embedding())
    warnings = adapter.validate_embedding(req, caps_embedding(on_unsupported="warn"))
    assert "dimensions" in [w.option for w in warnings]


def test_an_empty_text_names_its_position_rather_than_refusing_the_batch() -> None:
    with pytest.raises(KbError, match=r"texts\[1\]"):
        OpenRouterAdapter(http=None, public_app_url=APP_URL).validate_embedding(
            embedding_request_for(texts=["fine", "   ", "also fine"]), caps_embedding()
        )


# ── rerank(): input order, an exact cover, and an UNCALIBRATED scale ─────────


def rerank_payload(
    *, entries: Sequence[tuple[int, float]] = ((1, 0.91), (0, 0.12), (2, 0.03))
) -> dict[str, Any]:
    """Sorted by relevance, as the endpoint returns it, and identified by original ``index``."""
    return {
        "id": GENERATION_ID,
        "results": [
            {"index": index, "relevance_score": score, "document": {"text": "…"}}
            for index, score in entries
        ],
    }


async def test_rerank_scatters_to_input_order_with_an_exact_cover() -> None:
    """Reading the vendor's list positionally is the bug that produces a rerank stage which
    "works" — plausible floats, a sensible distribution, and every score on the wrong passage."""
    adapter, recorder = json_adapter(rerank_payload())
    result = await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))

    assert result.scores == [0.12, 0.91, 0.03]
    assert result.scores[1] == max(result.scores), "the key-rotation passage is passages[1]"
    assert recorder.request.url == httpx.URL(RERANK_URL)


def caps_rerank(
    *, supported: frozenset[Capability] | None = None, context_window: int = 4096
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported if supported is not None else frozenset({Capability.RERANK}),
        context_window=context_window,
        max_output_tokens=0,
    )


async def test_the_query_goes_first_and_the_documents_second() -> None:
    """Reversed, the endpoint returns entirely reasonable numbers and the symptom is a stage that
    "barely changes the order and looks pointless". Locked by an asymmetric fixture."""
    adapter, recorder = json_adapter(rerank_payload())
    await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))

    assert recorder.body["query"] == "how do I rotate the key"
    assert recorder.body["documents"] == [
        "about billing",
        "about key rotation",
        "about the weather",
    ]
    assert isinstance(recorder.body["documents"], list)


async def test_no_top_n_is_ever_sent() -> None:
    """A truncated, re-sorted response breaks the input alignment ``scores`` promises, and depth
    is a retrieval-stage decision, not a wire parameter."""
    adapter, recorder = json_adapter(rerank_payload())
    await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))
    assert "top_n" not in recorder.body
    assert recorder.body["provider"]["require_parameters"] is True


@pytest.mark.parametrize(
    "entries",
    [
        ((1, 0.91), (0, 0.12)),  # short: passages[2] silently unscored
        ((1, 0.91), (1, 0.12), (0, 0.4)),  # duplicated: passages[2] never answered
    ],
)
async def test_a_short_or_duplicated_cover_raises_rather_than_truncating(
    entries: Sequence[tuple[int, float]],
) -> None:
    """Silently dropped passages become evidence that vanished, and the answer that follows is
    grounded in a subset nobody chose."""
    adapter, _ = json_adapter(rerank_payload(entries=entries))
    with pytest.raises(KbError) as excinfo:
        await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))
    assert excinfo.value.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


async def test_the_reported_scale_is_the_uncalibrated_member_and_the_pipeline_refuses_it() -> None:
    """**Finding #47, both halves in one test.**

    The vendor publishes the route and this platform cannot consume it: the route fronts several
    upstream cross-encoders on one credential with no documented normalization, so
    ``relevance_score`` means whatever the upstream that served THIS request meant by it. Adding
    an ``openrouter`` row to ``RERANK_SCALE`` — even as ``UNIT_INTERVAL``, which is what a
    Cohere-shaped score looks like — would assert a calibration nobody measured and make a
    threshold constructible against a mixture.

    The scores below are perfectly plausible floats in 0..1, which is exactly why the refusal has
    to come from the SCALE and not from the values.
    """
    adapter, _ = json_adapter(rerank_payload())
    result = await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))

    assert result.scale is RerankScale.UNCALIBRATED
    assert result.scale is rerank_scale("openrouter"), "read from the table, never a literal"
    assert result.scale.may_threshold is False
    assert result.scale.is_bounded is False, "an unknown scale is not a bounded scale"
    assert all(0.0 <= score <= 1.0 for score in result.scores), (
        "the numbers look thresholdable, which is the whole hazard"
    )

    with pytest.raises(RerankNotCalibrated) as excinfo:
        calibration_for("openrouter", RERANK_MODEL, provider_scale=result.scale)
    message = str(excinfo.value)
    assert "uncalibrated" in message
    assert "evaluation run" in message, (
        "and the message must not ask for one that could never help — it says so explicitly"
    )


async def test_rerank_usage_is_zero_and_estimated_and_that_is_a_recorded_gap() -> None:
    """A Cohere-shaped reranker bills in ``search_units``, which is not a token and has no bucket
    in ``Usage``. Inventing a token count from passage lengths would make the number look real,
    and a plausible wrong cost is worse than a missing one."""
    adapter, _ = json_adapter(rerank_payload())
    result = await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))

    assert result.usage.source == "estimated"
    assert result.usage.total_input_tokens == 0
    assert result.usage.output_tokens == 0


async def test_a_failed_rerank_raises_and_is_never_recorded_as_a_skip() -> None:
    """``RerankSkipReason`` is closed and holds only pre-call reasons; an outage laundered into a
    skip degrades ranking while hiding the incident."""
    adapter, _ = json_adapter(error_body(code=503, error_type="provider_overloaded"), status=503)
    with pytest.raises(KbError) as excinfo:
        await adapter.rerank(rerank_request_for(), caps_rerank(), SecretStr(API_KEY))
    assert excinfo.value.error_class is ErrorClass.PROVIDER_TEMPORARY


def test_an_over_long_pair_is_refused_before_the_call_and_never_warned() -> None:
    """A warned truncation is still a truncation. The cross-encoder truncates an over-long
    query-plus-passage pair silently, the truncated pair scores off whatever distribution it
    would otherwise have scored on, and it fires hardest on the largest chunks."""
    adapter = OpenRouterAdapter(http=None, public_app_url=APP_URL)
    req = rerank_request_for(passages=["fine", "x" * 5000])
    for setting in ("reject", "warn"):
        caps = ModelCapabilities(
            supported=frozenset({Capability.RERANK}),
            context_window=4096,
            max_output_tokens=0,
            on_unsupported=setting,  # type: ignore[arg-type]
        )
        with pytest.raises(KbError, match=r"passages\[1\]"):
            adapter.validate_rerank(req, caps)


def test_nothing_rescales_what_comes_back() -> None:
    """Rescaling an uncharacterized score produces a characterized-LOOKING one, and the only
    symptom would be a refusal rate that moved with nothing else to explain it."""
    from app.providers import openrouter as module

    source = Path(module.__file__ or "").read_text()
    rerank_body = source.split("async def rerank(", 1)[1]
    for forbidden in ("sigmoid", "math.exp", "/ max(", "normalize"):
        assert forbidden not in rerank_body


# ── the registry entry ───────────────────────────────────────────────────────


def test_the_registry_builds_an_openrouter_adapter_from_the_configured_app_url() -> None:
    """It is the only adapter that is not credential-free to build, because its attribution
    headers carry OUR identity rather than the tenant's. An adapter built against an invented app
    URL sends wrong attribution on every call and nothing raises."""
    from app.core.config import get_settings
    from app.providers.registry import ADAPTERS

    adapter = ADAPTERS["openrouter"]
    assert isinstance(adapter, OpenRouterAdapter)
    assert adapter.name == "openrouter"
    assert adapter._public_app_url == get_settings().public_app_url
    assert adapter._public_app_url, "a placeholder would be an attribution error nothing raises"


def test_the_public_app_url_is_a_setting_with_the_kb_prefix() -> None:
    """Never derived from an inbound request — see the field's note. It is read from the
    environment like every other setting, so a deployment states it once."""
    from app.core.config import Settings

    assert "public_app_url" in Settings.model_fields
    assert Settings(public_app_url="https://app.example").public_app_url == "https://app.example"


# ── the tables this module reads, pinned ─────────────────────────────────────


def test_no_stop_value_maps_to_complete_except_stop() -> None:
    assert [key for key, value in STOP.items() if value is StopReason.COMPLETE] == ["stop"]
    assert STOP["length"] is StopReason.MAX_OUTPUT
    assert STOP["error"] is StopReason.ERROR


def test_the_status_tiebreak_never_makes_a_missing_slug_temporary() -> None:
    assert STATUS_TO_CLASS[404] is ErrorClass.PROVIDER_PERMANENT_REQUEST
    assert STATUS_TO_CLASS[402] is ErrorClass.PROVIDER_BILLING
    assert STATUS_TO_CLASS[401] is ErrorClass.PROVIDER_AUTH


def test_the_chat_url_is_the_completions_route_and_not_the_responses_surface() -> None:
    """The gateway's own responses surface is deliberately never used — it adds a routing layer
    over an already-routing layer."""
    assert URL.endswith("/api/v1/chat/completions")
    assert "responses" not in URL

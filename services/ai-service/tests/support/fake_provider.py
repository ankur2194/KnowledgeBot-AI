"""A second Uvicorn app that replays a **recorded** provider SSE transcript.

One fixture serves two jobs that must not drift apart:

* the cancellation test — did the provider socket actually close when the client vanished,
  and did we stop being billed for tokens nobody will read; and
* the provider adapter tests — point the SDK's ``base_url`` here and nothing inside the
  adapter is stubbed, so real SDK stream parsing, real usage accounting and real stop-reason
  mapping all execute.

If those two used different fixtures, the frame shapes would diverge and the adapter tests
would validate a wire nobody streams.

**Why this is an ASGI app and not a Starlette app.** It has to be importable from the
container-free tier's collection pass without dragging in a web framework, and it has to
observe ``http.disconnect`` directly — which is the one message a framework's convenience
layer is most likely to swallow.

**What is deliberately hostile about the transcript.** Every item below is a bug that only
exists once bytes cross a socket, and every one of them is invisible to a fixture that hands
the reader one complete body:

* **Real gaps.** ``await asyncio.sleep()`` between frames, so "when did the first token
  arrive" is an answerable question. A buffered implementation and a streaming one produce
  identical final text and completely different arrival times, and the arrival time is the
  only observable difference.
* **``\\r\\n`` on some frames.** The SSE grammar allows ``\\n``, ``\\r`` and ``\\r\\n`` as line
  terminators. A parser that splits on ``"\\n\\n"`` alone silently concatenates two events
  into one the first time a provider (or an intermediary) uses CRLF.
* **One frame split mid-UTF-8 codepoint.** A decoder constructed per chunk emits U+FFFD for
  the two halves of a multi-byte character. The same answer streams cleanly in English and
  grows replacement characters in German or Hindi, so no English-only fixture can catch it.
* **A ``: ping`` comment.** It must never surface as an event.
* **A frame split between its two terminating newlines**, which is where a naive
  buffer-and-scan loop drops the frame entirely.

Counters are the assertion surface for cancellation: ``tokens_sent < tokens_scripted`` is
what proves the generation actually stopped, and ``disconnected`` is what proves the socket
closed rather than the server merely finishing.
"""

from __future__ import annotations

import asyncio
import contextlib
import json
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass, field
from typing import Any, Final

__all__ = [
    "OPENAI_CHAT_TRANSCRIPT",
    "FakeProvider",
    "Frame",
    "RecordedRequest",
    "sse_frame",
]


@dataclass(frozen=True, slots=True)
class Frame:
    """One scripted write, with the wire bytes already assembled.

    ``payload`` is exact: terminators included, no re-encoding at send time. If a transcript
    frame is wrong, it is wrong here, in a diff, rather than in a formatter nobody reads.
    """

    payload: bytes
    #: Real seconds slept **before** this frame is written. Not a mock clock: the thing under
    #: test is whether bytes arrive apart in wall-clock time.
    gap: float = 0.0
    #: Byte offset at which to split ``payload`` across two ``send`` calls. Offsets that land
    #: inside a multi-byte codepoint or between the two terminating newlines are the point.
    split_at: int | None = None
    #: Counted into ``tokens_sent``/``tokens_scripted``. Only content deltas are tokens.
    is_token: bool = False


def sse_frame(
    data: Any,
    *,
    event: str | None = None,
    crlf: bool = False,
    gap: float = 0.0,
    split_at: int | None = None,
    is_token: bool = False,
) -> Frame:
    """Assemble one SSE frame. ``data`` is JSON-encoded unless it is already a ``str``."""
    eol = "\r\n" if crlf else "\n"
    body = data if isinstance(data, str) else json.dumps(data, separators=(",", ":"))
    text = ""
    if event is not None:
        text += f"event: {event}{eol}"
    text += f"data: {body}{eol}{eol}"
    return Frame(text.encode("utf-8"), gap=gap, split_at=split_at, is_token=is_token)


def _split_inside_codepoint(frame: Frame, needle: str) -> Frame:
    """Return ``frame`` with ``split_at`` placed **inside** the first byte of ``needle``.

    Computed rather than hardcoded so that editing the transcript text cannot silently move
    the split onto a codepoint boundary, which would turn the nastiest frame in the
    transcript into the most ordinary one with no visible diff.
    """
    encoded = needle.encode("utf-8")
    if len(encoded) < 2:
        msg = f"{needle!r} is single-byte in UTF-8; it cannot be split mid-codepoint"
        raise ValueError(msg)
    index = frame.payload.find(encoded)
    if index < 0:  # pragma: no cover - transcript authoring error
        msg = f"{needle!r} does not appear in the frame payload"
        raise ValueError(msg)
    return Frame(frame.payload, gap=frame.gap, split_at=index + 1, is_token=frame.is_token)


def _chunk(index: int, text: str, **kw: Any) -> Frame:
    """One OpenAI-shaped ``chat.completion.chunk`` carrying a content delta."""
    return sse_frame(
        {
            "id": "chatcmpl-kbfake",
            "object": "chat.completion.chunk",
            "created": 1_770_000_000,
            "model": "gpt-5.1-mini",
            "choices": [{"index": 0, "delta": {"content": text}, "finish_reason": None}],
        },
        is_token=True,
        **kw,
    )


#: A recorded OpenAI-compatible ``chat/completions`` stream. Provider-shaped on purpose: this
#: server stands in for the LLM, not for our own SSE relay, so it emits what an adapter has
#: to parse. The normalized client-facing schema of `kb-internal-api-contracts` is what the
#: service emits *out* of it, and asserting that is a different test.
#:
#: The German sentence is not decoration. "Rückerstattungen" carries a two-byte ``ü`` at a
#: predictable position, and the split below lands between its bytes.
OPENAI_CHAT_TRANSCRIPT: Final[tuple[Frame, ...]] = (
    # First token late on purpose: retrieval and rerank run seconds before generation, and a
    # first-token deadline that is never exercised is not a deadline.
    _chunk(0, "Refunds", gap=0.25),
    _chunk(1, " are accepted", gap=0.05),
    # CRLF terminators. Legal SSE, and the shape that breaks a `split("\n\n")` parser.
    _chunk(2, " for 30 days", gap=0.05, crlf=True),
    # Split inside the two-byte `ü`.
    _split_inside_codepoint(_chunk(3, " — Rückerstattungen", gap=0.05), "ü"),
    # The heartbeat comment. Must never surface as an event.
    Frame(b": ping\n\n", gap=0.05),
    # Split between the two terminating newlines, where a naive buffer-and-scan drops it.
    _chunk(4, " werden erstattet.", gap=0.05, split_at=-2),
    sse_frame(
        {
            "id": "chatcmpl-kbfake",
            "object": "chat.completion.chunk",
            "created": 1_770_000_000,
            "model": "gpt-5.1-mini",
            "choices": [{"index": 0, "delta": {}, "finish_reason": "stop"}],
            # `stream_options: {include_usage: true}` puts usage on a final chunk whose
            # `choices` is empty. Do not make finalization depend on this arriving: a cut
            # stream never delivers it, which is why usage is tallied incrementally.
            "usage": {"prompt_tokens": 1841, "completion_tokens": 96, "total_tokens": 1937},
        },
        gap=0.05,
    ),
    sse_frame("[DONE]", gap=0.01),
)


@dataclass(slots=True)
class RecordedRequest:
    """What the adapter actually put on the wire.

    Kept so a test can assert the credential reached the provider — the positive control for
    a secret-redaction test, which otherwise passes because the key was never used.
    """

    method: str
    path: str
    headers: dict[str, str]
    body: bytes

    def json(self) -> Any:
        return json.loads(self.body) if self.body else None


class FakeProvider:
    """An ASGI app replaying ``transcript`` over a real socket.

    Usage::

        provider = FakeProvider()
        async with serve(provider) as base_url:
            client = AsyncOpenAI(base_url=base_url + "/v1", api_key="sk-test")
            ...
        assert provider.tokens_sent < provider.tokens_scripted
        await asyncio.wait_for(provider.disconnected.wait(), timeout=5)
    """

    def __init__(
        self,
        transcript: Sequence[Frame] = OPENAI_CHAT_TRANSCRIPT,
        *,
        on_disconnect: Callable[[], None] | None = None,
    ) -> None:
        self.transcript: Sequence[Frame] = tuple(transcript)
        self.requests: list[RecordedRequest] = []
        #: Set when the client's socket goes away mid-stream. This is the event the whole
        #: cancellation design turns on, and neither ASGITransport nor TestClient can produce
        #: it — they only deliver `http.disconnect` after the response has completed.
        self.disconnected: asyncio.Event = asyncio.Event()
        self.first_byte_at: float | None = None
        self.tokens_sent: int = 0
        self.completed: bool = False
        self._on_disconnect = on_disconnect
        #: Queued non-stream responses, popped one per request. `(status, body, headers)`.
        self._canned: list[tuple[int, bytes, dict[str, str]]] = []

    @property
    def tokens_scripted(self) -> int:
        return sum(1 for frame in self.transcript if frame.is_token)

    def fail_next(
        self,
        status: int,
        body: Any,
        headers: dict[str, str] | None = None,
    ) -> None:
        """Queue a non-streaming response — an error body, a 402/429 discrimination case.

        Error *shapes* are what cassettes are for; they carry no chunk boundaries, so they
        do not need this server's timing machinery. They are here anyway because an adapter
        that classifies a 429 must do it against the same base URL as the one that streams.
        """
        payload = body if isinstance(body, bytes) else json.dumps(body).encode("utf-8")
        self._canned.append((status, payload, headers or {"content-type": "application/json"}))

    async def __call__(
        self,
        scope: dict[str, Any],
        receive: Callable[[], Any],
        send: Callable[[dict[str, Any]], Any],
    ) -> None:
        if scope["type"] != "http":  # pragma: no cover - no websockets here
            return

        body = await _read_body(receive)
        self.requests.append(
            RecordedRequest(
                method=scope["method"],
                path=scope["path"],
                headers={
                    k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope["headers"]
                },
                body=body,
            )
        )

        if self._canned:
            status, payload, headers = self._canned.pop(0)
            await send(
                {
                    "type": "http.response.start",
                    "status": status,
                    "headers": [
                        (k.encode("latin-1"), v.encode("latin-1")) for k, v in headers.items()
                    ],
                }
            )
            await send({"type": "http.response.body", "body": payload})
            return

        await self._stream(receive, send)

    async def _stream(
        self,
        receive: Callable[[], Any],
        send: Callable[[dict[str, Any]], Any],
    ) -> None:
        await send(
            {
                "type": "http.response.start",
                "status": 200,
                "headers": [
                    (b"content-type", b"text/event-stream"),
                    (b"cache-control", b"no-store"),
                    # Without an early header flush the client's `await` does not return
                    # until the first token, and nothing about the pre-token path is
                    # observable.
                    (b"x-accel-buffering", b"no"),
                ],
            }
        )

        watcher = asyncio.create_task(self._watch_disconnect(receive))
        loop = asyncio.get_running_loop()
        try:
            for frame in self.transcript:
                if frame.gap:
                    await asyncio.sleep(frame.gap)
                if self.disconnected.is_set():
                    # The peer is gone. Stop generating: every further frame is a token
                    # billed for an answer nobody will read.
                    return
                for piece in _pieces(frame):
                    await send({"type": "http.response.body", "body": piece, "more_body": True})
                if self.first_byte_at is None:
                    self.first_byte_at = loop.time()
                if frame.is_token:
                    self.tokens_sent += 1
            await send({"type": "http.response.body", "body": b"", "more_body": False})
            self.completed = True
        except (ConnectionResetError, BrokenPipeError, OSError):
            # Uvicorn surfaces a vanished peer as a write failure. That is not an error
            # here; it is the event under test.
            self.disconnected.set()
        finally:
            watcher.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await watcher

    async def _watch_disconnect(self, receive: Callable[[], Any]) -> None:
        while True:
            message = await receive()
            if message["type"] == "http.disconnect":
                self.disconnected.set()
                if self._on_disconnect is not None:
                    self._on_disconnect()
                return


def _pieces(frame: Frame) -> Iterable[bytes]:
    """Yield the one or two writes this frame becomes.

    A negative ``split_at`` counts from the end, which is how the "split between the two
    terminating newlines" case is written without hardcoding a frame length.
    """
    if frame.split_at is None:
        return (frame.payload,)
    at = frame.split_at if frame.split_at >= 0 else len(frame.payload) + frame.split_at
    return (frame.payload[:at], frame.payload[at:])


async def _read_body(receive: Callable[[], Any]) -> bytes:
    chunks: list[bytes] = []
    while True:
        message = await receive()
        if message["type"] == "http.disconnect":
            break
        chunks.append(message.get("body", b""))
        if not message.get("more_body", False):
            break
    return b"".join(chunks)


@dataclass(slots=True)
class TranscriptCursor:
    """Helper for asserting arrival order and gaps on the client side.

    Records ``(monotonic, line)`` for every line read, so an assertion can be written about
    *when* the first token arrived rather than only about the assembled text. The assembled
    text is identical between a correct implementation and a fully buffered one; the timing
    is the only thing that is not.
    """

    marks: list[tuple[float, str]] = field(default_factory=list)

    def mark(self, line: str) -> None:
        self.marks.append((asyncio.get_running_loop().time(), line))

    def first(self, predicate: Callable[[str], bool]) -> float:
        for at, line in self.marks:
            if predicate(line):
                return at
        msg = "no line matched the predicate"
        raise AssertionError(msg)

    def gap_between(self, first: Callable[[str], bool], second: Callable[[str], bool]) -> float:
        return self.first(second) - self.first(first)

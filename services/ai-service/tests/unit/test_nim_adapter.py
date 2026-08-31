"""The NVIDIA NIM adapter — all three surfaces, and the one provider stage 12 can cut against.

Nothing here touches a network. Each surface's client is replaced at the seam built for it —
``_client``, ``_embedding_client``, ``_ranking_client`` — which is also the test of whether
those seams are where they should be: three construction sites, one ``get_secret_value()``
each, and no other place in the module where a credential is readable.

**The `unit/` README says this tier may not assert "anything about a stream", and that rule is
about TRANSPORT.** There is none here: the chat fake is an in-process async generator and every
assertion is about event *translation*. Whether an SSE frame survives Uvicorn, Traefik and PHP
belongs to the live-server tier.

Seven failures shape the file, and every one of them produces plausible output rather than an
error:

* **A truncated answer reported as COMPLETE.** ``finish_reason: "length"`` is OUR cap on this
  vendor — there is no context-window stop value, because an over-long prompt is a 422 up
  front — so nothing but ``"stop"`` may reach ``COMPLETE``.
* **A vendor that adds a stop reason.** Anything unmapped is ``ERROR`` with the vendor's own
  word preserved, never a fall-through.
* **The usage chunk that crashes the adapter that asked for it.** ``stream_options`` is what
  makes usage arrive at all, and the chunk carrying it has ``choices: []``.
* **A rerank read positionally.** The response is sorted by relevance and identifies each entry
  only by its original position; reading it in order attaches sensible floats to the wrong
  passages, and the stage looks merely pointless rather than broken.
* **A threshold applied to the wrong scale.** ``RerankResult.scale`` is the vendor's own claim,
  which is what makes stage 12's assertion a comparison rather than a value against a copy of
  itself. NIM's is an UNBOUNDED LOGIT; 0.30 is a valid float on every scale.
* **An embedding batch read in response order, or with the wrong ``input_type``.** Both produce
  a fully populated, fully wrong index that nothing raises on.
* **A cancelled turn with no usage row.** Yielding the terminal event from ``finally`` raises
  ``RuntimeError`` during ``GeneratorExit``, ASGI swallows it, and NVIDIA still bills.
"""

from __future__ import annotations

import asyncio
from types import SimpleNamespace
from typing import Any, Final

import pytest
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.capabilities import RERANK_SCALE
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
    ToolDef,
)
from app.providers.nim import (
    BASE_URL,
    CONTEXT_BLOCK_TEMPLATE,
    DELTA_FIELDS,
    EMBED_MODELS,
    EMBEDDING_INPUT_TYPES,
    ESTIMATED_CHARS_PER_TOKEN,
    HTTP_ACCEPTED,
    MAX_EMBEDDING_INPUTS,
    MAX_RERANK_PASSAGES,
    MAX_TEMPERATURE,
    PINNED_MODELS,
    RANKING_INDEX_FIELD,
    RANKING_PATH,
    RANKING_RESULTS_FIELD,
    RANKING_SCORE_FIELD,
    REASONING_EFFORT,
    REQUEST_ID_HEADER,
    RERANK_MODELS,
    ROUNDED_EFFORTS,
    STATUS_TO_CLASS,
    STOP,
    TRUNCATE_POLICY,
    NimAdapter,
    NimStatusError,
)

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BT"
CONNECTION: Final = "01JQZ0000000000000000000CN"
TRACE: Final = "01JQZTRACE0000000000000001"
MODEL: Final = PINNED_MODELS[0]
RERANK_MODEL: Final = RERANK_MODELS[0]
EMBED_MODEL: Final = EMBED_MODELS[0]

#: A real-shaped NGC Personal API Key. It is greppable on purpose: the credential-leak
#: assertions look for this exact string in serialized diagnostics and raised errors.
KEY: Final = SecretStr("nvapi-fixture000000000000000000000000000000000000000004a91")

#: The bot instruction, and the sharper half — retrieved document text. Both are canaries.
SYSTEM_PROMPT: Final = "SYSTEM-CANARY-you-are-a-support-bot-for-Acme"
CONTEXT_TEXT: Final = "CONTEXT-CANARY-the-refund-window-is-thirty-days"

CHAT_FLAGS: Final = frozenset({Capability.TEXT, Capability.STREAM_USAGE})
EMBED_FLAGS: Final = frozenset({Capability.EMBEDDING, Capability.EMBEDDING_INPUT_TYPE})
RERANK_FLAGS: Final = frozenset({Capability.RERANK})


def wire(payload: Any) -> Any:
    """Turn a recorded wire dict into the attribute object the SDK would hand the adapter."""
    if isinstance(payload, dict):
        return SimpleNamespace(**{key: wire(value) for key, value in payload.items()})
    if isinstance(payload, list):
        return [wire(item) for item in payload]
    return payload


# ── recorded wire fixtures: chat ──────────────────────────────────────────────
#
# Dicts in the vendor's own shape. Written this way rather than as hand-set attributes so the
# fixture is the payload and not a description of one: a field the adapter reads by a name NIM
# does not use fails here instead of in production.


def chunk(**overrides: Any) -> Any:
    """One ``chat.completions`` chunk, defaulted to a text delta."""
    payload: dict[str, Any] = {
        "id": "chatcmpl-fixture",
        "model": "meta/llama-3.1-8b-instruct",
        "usage": None,
        "choices": [{"index": 0, "delta": {"content": "Thirty "}, "finish_reason": None}],
    }
    payload.update(overrides)
    return wire(payload)


def finish(reason: str | None) -> Any:
    """The chunk that carries a ``finish_reason`` and an empty delta."""
    return chunk(choices=[{"index": 0, "delta": {"content": ""}, "finish_reason": reason}])


#: THE CHUNK THAT CARRIES THE NUMBER `stream_options` WAS ADDED FOR, and it arrives with
#: `choices: []` — so `chunk.choices[0]` raises IndexError on exactly that chunk.
USAGE_CHUNK: Final[dict[str, Any]] = {
    "id": "chatcmpl-fixture",
    "model": "meta/llama-3.1-8b-instruct",
    "choices": [],
    "usage": {"prompt_tokens": 812, "completion_tokens": 64, "total_tokens": 876},
}

#: The first chunk of every NIM stream: an empty role delta. Timing TTFT from it reports a
#: first token that carried nothing.
EMPTY_ROLE_CHUNK: Final[dict[str, Any]] = {
    "id": "chatcmpl-fixture",
    "model": "meta/llama-3.1-8b-instruct",
    "usage": None,
    "choices": [{"index": 0, "delta": {"role": "assistant", "content": ""}, "finish_reason": None}],
}


# ── the fake clients ──────────────────────────────────────────────────────────


class FakeChatStream:
    """An SSE stream that arrives in more than one chunk, over time.

    ``await asyncio.sleep(0)`` before each chunk is the load-bearing line. A single-chunk
    fixture passes against a buffered implementation, which is the exact defect the streaming
    tests exist to catch; yielding to the loop between chunks lets a consumer observe how many
    chunks had been pulled at the moment it received its first ``Delta``.
    """

    def __init__(self, chunks: list[Any], *, fail_with: BaseException | None = None) -> None:
        self._chunks = chunks
        self._fail_with = fail_with
        #: Every chunk this stream has actually handed out, in order.
        self.delivered: list[Any] = []

    async def __aiter__(self) -> Any:
        for item in self._chunks:
            await asyncio.sleep(0)
            self.delivered.append(item)
            yield item
        if self._fail_with is not None:
            await asyncio.sleep(0)
            raise self._fail_with


class FakeRaw:
    """The ``with_raw_response`` shape: a status and headers now, the body on ``parse()``."""

    def __init__(self, parsed: Any, *, status_code: int = 200, headers: Any = None) -> None:
        self._parsed = parsed
        self.status_code = status_code
        self.headers = {} if headers is None else headers
        self.parsed_calls = 0

    def parse(self) -> Any:
        self.parsed_calls += 1
        return self._parsed


class FakeChatClient:
    def __init__(self, raw: FakeRaw | BaseException) -> None:
        self._raw = raw
        self.calls: list[dict[str, Any]] = []
        outer = self

        class _Raw:
            async def create(self, **kwargs: Any) -> FakeRaw:
                outer.calls.append(kwargs)
                if isinstance(outer._raw, BaseException):
                    raise outer._raw
                return outer._raw

        class _Completions:
            with_raw_response = _Raw()

        self.chat = SimpleNamespace(completions=_Completions())


class FakeEmbeddingClient:
    def __init__(self, raw: FakeRaw | BaseException) -> None:
        self._raw = raw
        self.calls: list[dict[str, Any]] = []
        outer = self

        class _Raw:
            async def create(self, **kwargs: Any) -> FakeRaw:
                outer.calls.append(kwargs)
                if isinstance(outer._raw, BaseException):
                    raise outer._raw
                return outer._raw

        class _Embeddings:
            with_raw_response = _Raw()

        self.embeddings = _Embeddings()


class FakeHttpResponse:
    def __init__(self, payload: Any, *, status_code: int = 200, headers: Any = None) -> None:
        self._payload = payload
        self.status_code = status_code
        self.headers = {} if headers is None else headers

    def json(self) -> Any:
        return self._payload


class FakeRankingClient:
    def __init__(self, response: FakeHttpResponse) -> None:
        self._response = response
        self.calls: list[dict[str, Any]] = []
        self.closed = False

    async def post(self, path: str, *, json: Any, headers: Any) -> FakeHttpResponse:
        self.calls.append({"path": path, "json": json, "headers": headers})
        return self._response

    async def aclose(self) -> None:
        self.closed = True


class FakeStatusError(Exception):
    """The SDK exception shape ``classify`` reads: a status, a body, a request id."""

    def __init__(self, status: int, code: str | None = None) -> None:
        super().__init__("vendor message that must never be relayed")
        self.status_code = status
        self.body = {"type": code} if code else {}
        self.request_id = "req_err"


# ── adapters wired to the fakes ───────────────────────────────────────────────


def chat_adapter(
    chunks: list[Any],
    *,
    fail_with: BaseException | None = None,
    status_code: int = 200,
    headers: Any = None,
) -> tuple[NimAdapter, FakeChatClient, FakeChatStream]:
    stream = FakeChatStream(chunks, fail_with=fail_with)
    raw = FakeRaw(stream, status_code=status_code, headers=headers)
    client = FakeChatClient(raw)
    adapter = NimAdapter()
    adapter._client = lambda credential, req: client  # type: ignore[method-assign]
    return adapter, client, stream


def chat_adapter_raising(exc: BaseException) -> tuple[NimAdapter, FakeChatClient]:
    client = FakeChatClient(exc)
    adapter = NimAdapter()
    adapter._client = lambda credential, req: client  # type: ignore[method-assign]
    return adapter, client


def embed_adapter(
    entries: list[Any],
    *,
    model: str = EMBED_MODEL,
    usage: Any = None,
    status_code: int = 200,
    headers: Any = None,
) -> tuple[NimAdapter, FakeEmbeddingClient]:
    response = wire(
        {
            "data": None,
            "model": model,
            "usage": usage if usage is not None else {"prompt_tokens": 91, "total_tokens": 91},
        }
    )
    response.data = entries
    client = FakeEmbeddingClient(FakeRaw(response, status_code=status_code, headers=headers))
    adapter = NimAdapter()
    adapter._embedding_client = lambda credential, req: client  # type: ignore[method-assign]
    return adapter, client


def entry(index: int, embedding: list[float]) -> Any:
    return SimpleNamespace(index=index, embedding=embedding)


def rank_adapter(
    payload: Any, *, status_code: int = 200, headers: Any = None
) -> tuple[NimAdapter, FakeRankingClient]:
    client = FakeRankingClient(FakeHttpResponse(payload, status_code=status_code, headers=headers))
    adapter = NimAdapter()
    adapter._ranking_client = lambda credential, req: client  # type: ignore[method-assign]
    return adapter, client


def ranking_payload(entries: list[tuple[int, float]]) -> dict[str, Any]:
    """The vendor's own shape: sorted by relevance, position carried in ``index``."""
    return {
        RANKING_RESULTS_FIELD: [
            {RANKING_INDEX_FIELD: index, RANKING_SCORE_FIELD: score} for index, score in entries
        ]
    }


def caps_for(
    *,
    supported: frozenset[Capability] = CHAT_FLAGS,
    on_unsupported: str = "reject",
    max_output_tokens: int = 4096,
    context_window: int = 128_000,
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=context_window,
        max_output_tokens=max_output_tokens,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(**overrides: Any) -> ChatRequest:
    body: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": TRACE,
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


def embed_request(texts: list[str], **overrides: Any) -> EmbeddingRequest:
    body: dict[str, Any] = {
        "org_id": ORG,
        "trace_id": TRACE,
        "provider_connection_id": CONNECTION,
        "model": EMBED_MODEL,
        "texts": texts,
        "input_type": EmbeddingInputType.PASSAGE,
    }
    body.update(overrides)
    return EmbeddingRequest(**body)


def rerank_request(passages: list[str], **overrides: Any) -> RerankRequest:
    body: dict[str, Any] = {
        "org_id": ORG,
        "trace_id": TRACE,
        "provider_connection_id": CONNECTION,
        "model": RERANK_MODEL,
        "query": "how long is the refund window",
        "passages": passages,
    }
    body.update(overrides)
    return RerankRequest(**body)


async def drain(adapter: NimAdapter, req: ChatRequest, caps: ModelCapabilities) -> list[Any]:
    return [event async for event in adapter.stream(req, caps, KEY)]


def only_result(events: list[Any]) -> ChatResult:
    """Exactly one terminal event, on every path. Asserted here so every caller gets it free."""
    results = [event for event in events if isinstance(event, ChatResult)]
    assert len(results) == 1, f"expected exactly one terminal ChatResult, got {len(results)}"
    return results[0]


# ── stop reasons: no length cap may reach COMPLETE ────────────────────────────

#: Every ``finish_reason`` this vendor produces, what it must map to, and whether it is a
#: length cap. The rows are marked so the assertion below can name them rather than restate
#: them.
STOP_ROWS: Final[tuple[tuple[str, StopReason, bool], ...]] = (
    ("stop", StopReason.COMPLETE, False),
    ("length", StopReason.MAX_OUTPUT, True),
    ("tool_calls", StopReason.TOOL_USE, False),
    ("content_filter", StopReason.REFUSAL, False),
    ("max_tokens", StopReason.ERROR, False),
    ("guardrail_intervened", StopReason.ERROR, False),
    ("", StopReason.ERROR, False),
)


@pytest.mark.parametrize(
    ("reason", "expected"),
    [(reason, expected) for reason, expected, _ in STOP_ROWS],
    ids=[row[0] or "<empty>" for row in STOP_ROWS],
)
async def test_every_finish_reason_maps_to_its_stop_reason(
    reason: str, expected: StopReason
) -> None:
    adapter, _, _ = chat_adapter([chunk(), finish(reason), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is expected


def test_no_length_cap_reaches_complete() -> None:
    """The single worst failure this layer can cause: a confident half-sentence with no error.

    ``length`` is unambiguously OUR cap on this vendor — there is no context-window finish
    value, because an over-long prompt is rejected up front as a 422 — so nothing about it is
    ambiguous enough to justify ``COMPLETE``.
    """
    capped = {reason: STOP.get(reason, StopReason.ERROR) for reason, _, cap in STOP_ROWS if cap}
    assert capped, "the table lost its length-cap rows"
    assert set(capped.values()) == {StopReason.MAX_OUTPUT}, capped
    assert StopReason.COMPLETE not in capped.values()


def test_only_stop_can_reach_complete() -> None:
    """Stated as a property over the module's own table, so a value NVIDIA adds later cannot
    quietly acquire ``COMPLETE`` by resembling one that has it."""
    assert [key for key, value in STOP.items() if value is StopReason.COMPLETE] == ["stop"]


async def test_an_unknown_finish_reason_is_error_with_the_vendors_word_preserved() -> None:
    """vLLM's vocabulary grows under NVIDIA's versioning policy and without notice.
    ``native_stop_reason`` is how we find out; a fall-through to COMPLETE is how we do not."""
    adapter, _, _ = chat_adapter([chunk(), finish("guardrail_intervened"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.ERROR
    assert result.diagnostics.native_stop_reason == "guardrail_intervened"
    assert result.error_class == ErrorClass.PROVIDER_PERMANENT_REQUEST.value


async def test_a_refusal_is_a_refusal_and_not_an_empty_success() -> None:
    """``content_filter`` arrives inside an HTTP 200. Filed as success, the router sees empty
    text, falls back, and re-asks the banned question on another vendor at a second bill."""
    adapter, _, _ = chat_adapter([finish("content_filter"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.REFUSAL
    assert result.error_class is None
    assert result.diagnostics.native_stop_reason == "content_filter"


# ── usage: disjoint buckets, and the chunk that carries them has no choices ───


def test_usage_buckets_are_disjoint_and_total_matches_the_vendors_billed_input() -> None:
    """The OpenAI SUBSET pattern in its degenerate form: ``prompt_tokens`` IS the billed input
    and no cached amount is reported, so there is nothing to subtract. Reading it as Anthropic's
    sibling pattern would be indistinguishable today and over-bill by the cached amount the day
    NVIDIA reports one."""
    billed = USAGE_CHUNK["usage"]
    usage = NimAdapter._usage(wire(billed))

    assert usage.input_tokens == billed["prompt_tokens"]
    assert usage.output_tokens == billed["completion_tokens"]
    # THE ONE THAT MATTERS: billing reads `total_input_tokens`, never `input_tokens`.
    assert usage.total_input_tokens == billed["prompt_tokens"]
    # Disjoint, and honestly zero rather than guessed: caching is UNOBSERVABLE here, not absent.
    assert usage.cache_read_tokens == 0
    assert usage.cache_write_tokens == 0
    assert usage.reasoning_tokens == 0
    assert usage.source == "provider_final"


def test_total_tokens_is_never_read() -> None:
    """It is a derived sum. Taking it would let a vendor-side change to what the sum includes
    become a billing change here with nothing to compare against."""
    raw = wire({"prompt_tokens": 10, "completion_tokens": 4, "total_tokens": 99})
    usage = NimAdapter._usage(raw)
    assert usage.total_input_tokens + usage.output_tokens == 14


def test_an_absent_usage_object_degrades_attribution_and_never_the_bill() -> None:
    assert NimAdapter._usage(None).source == "estimated"
    assert NimAdapter._usage(wire({"prompt_tokens": None, "completion_tokens": 3})).source == (
        "estimated"
    )


async def test_the_usage_chunk_arrives_with_no_choices_and_does_not_raise() -> None:
    """The second half of the ``stream_options`` trap: the chunk carrying the number the option
    was added for has ``choices: []``, so ``chunk.choices[0]`` is an IndexError on exactly it."""
    adapter, _, _ = chat_adapter([chunk(), finish("stop"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.usage.source == "provider_final"
    assert result.usage.input_tokens == USAGE_CHUNK["usage"]["prompt_tokens"]


async def test_stream_options_include_usage_is_on_every_streamed_call() -> None:
    """Without it, ``usage`` is null on EVERY chunk including the last, every billing row lands
    as ``estimated``, and cost reports under-report by 100%."""
    body = await sent_chat_body()
    assert body["stream_options"] == {"include_usage": True}


# ── the stream: never buffered, and one terminal event on every path ─────────


async def test_deltas_reach_the_consumer_before_the_stream_has_been_drained() -> None:
    """A buffered implementation "works" and is a defect: the Laravel relay measures
    time-to-first-token. The fixture arrives in several chunks over time, so the count of
    chunks the fake has handed out when the first ``Delta`` lands is the evidence."""
    adapter, _, stream = chat_adapter([chunk(), chunk(), finish("stop"), wire(USAGE_CHUNK)])

    generator = adapter.stream(request_for(), caps_for(), KEY)
    first = await generator.__anext__()

    assert isinstance(first, Delta)
    assert len(stream.delivered) == 1, (
        "the whole stream had been pulled before the first Delta was yielded — that is a "
        "buffered implementation, and it reports a first-token time it did not achieve"
    )
    await generator.aclose()


async def test_the_empty_role_chunk_starts_no_timer_and_yields_no_event() -> None:
    """NIM's first streamed chunk is an empty role delta. Timing TTFT from it reads well under
    target while the user watches a blank box."""
    adapter, _, _ = chat_adapter(
        [wire(EMPTY_ROLE_CHUNK), chunk(), finish("stop"), wire(USAGE_CHUNK)]
    )
    events = await drain(adapter, request_for(), caps_for())
    assert len([event for event in events if isinstance(event, Delta)]) == 1


async def test_every_translated_delta_field_reaches_the_consumer() -> None:
    """The translation table is the module's, not this test's: a field added to
    ``DELTA_FIELDS`` without a branch to carry it fails here."""
    delta = {field: f"<{kind}>" for field, kind in DELTA_FIELDS.items()}
    adapter, _, _ = chat_adapter(
        [
            chunk(choices=[{"index": 0, "delta": delta, "finish_reason": None}]),
            finish("stop"),
            wire(USAGE_CHUNK),
        ]
    )
    events = await drain(adapter, request_for(), caps_for())
    kinds = [event.kind for event in events if isinstance(event, Delta)]
    assert kinds == list(DELTA_FIELDS.values())


async def test_only_text_deltas_become_the_answer_text() -> None:
    """Reasoning is billed and displayed and is not the answer. Folding it into ``text`` puts
    the chain of thought in the citation validator."""
    adapter, _, _ = chat_adapter(
        [
            chunk(
                choices=[
                    {
                        "index": 0,
                        "delta": {"reasoning_content": "thinking", "content": "Thirty days."},
                        "finish_reason": None,
                    }
                ]
            ),
            finish("stop"),
            wire(USAGE_CHUNK),
        ]
    )
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.text == "Thirty days."


async def test_tool_arguments_are_read_two_levels_down() -> None:
    adapter, _, _ = chat_adapter(
        [
            chunk(
                choices=[
                    {
                        "index": 0,
                        "delta": {
                            "content": "",
                            "tool_calls": [{"function": {"arguments": '{"order":'}}],
                        },
                        "finish_reason": None,
                    }
                ]
            ),
            finish("tool_calls"),
            wire(USAGE_CHUNK),
        ]
    )
    events = await drain(adapter, request_for(), caps_for())
    deltas = [event for event in events if isinstance(event, Delta)]
    assert [(delta.kind, delta.text) for delta in deltas] == [("tool_args", '{"order":')]
    assert only_result(events).stop_reason is StopReason.TOOL_USE


async def test_a_successful_stream_ends_with_exactly_one_terminal_result() -> None:
    adapter, _, _ = chat_adapter([chunk(), finish("stop"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.COMPLETE
    assert result.first_token_ms is not None
    assert result.error_class is None


# ── cancellation ──────────────────────────────────────────────────────────────


async def cancelled_result(chunks: list[Any]) -> ChatResult:
    adapter, _, _ = chat_adapter(chunks, fail_with=asyncio.CancelledError())
    observed: list[Any] = []
    with pytest.raises(asyncio.CancelledError):
        async for event in adapter.stream(request_for(), caps_for(), KEY):
            observed.append(event)
    return only_result(observed)


async def test_cancellation_yields_one_terminal_result_and_then_re_raises() -> None:
    """The terminal event comes from ``except asyncio.CancelledError`` and never from
    ``finally``: an async generator that yields while ``GeneratorExit`` unwinds raises
    ``RuntimeError``, ASGI swallows it, and the only symptom is a missing usage row for a turn
    NVIDIA billed in full."""
    result = await cancelled_result([chunk(), chunk()])
    assert result.stop_reason is StopReason.CANCELLED
    assert result.error_class == ErrorClass.USER_CANCELLATION.value
    # NEVER `provider_final`: usage arrives only in the final `stream_options` chunk, so a
    # cancelled turn has no exact input count and must not be aggregated into invoiced cost.
    assert result.usage.source != "provider_final"
    assert result.usage.source == "estimated"


async def test_a_cancelled_turn_still_bills_what_it_emitted() -> None:
    """A ``Usage()`` of zeros looks exactly like a free call, which is how billing silently
    under-counts every abandoned turn."""
    result = await cancelled_result([chunk(), chunk(), chunk()])
    assert result.usage.output_tokens == 3
    assert result.text == "Thirty " * 3


async def test_the_request_id_survives_a_stream_that_dies_mid_flight() -> None:
    """NIM never synthesizes a request id, so the one we sent is the only handle that exists —
    and it is missing on precisely the failures NVIDIA support asks about."""
    result = await cancelled_result([chunk()])
    assert result.provider_request_id == TRACE


# ── failures still terminate the stream exactly once ─────────────────────────


async def test_a_202_is_classified_before_a_single_sse_line_is_read() -> None:
    """202 means QUEUED, resolved by a poll the SDK cannot perform. Parsed rather than
    classified, it is a 200-shaped success whose body never arrives and a turn that hangs for
    the whole provider budget. Nothing was generated, so it is fallback-eligible."""
    adapter, client, stream = chat_adapter([chunk()], status_code=HTTP_ACCEPTED)
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert stream.delivered == [], "the SSE body was read on a queued response"
    assert client._raw.parsed_calls == 0
    assert result.text == ""


@pytest.mark.parametrize("status", sorted(STATUS_TO_CLASS))
def test_the_taxonomy_row_comes_from_the_module_table(status: int) -> None:
    """Table-driven against the module's own map, so a status added there without a class fails
    here rather than defaulting quietly."""
    failure = NimAdapter().classify(FakeStatusError(status), tokens_emitted=0)
    assert failure.error_class is STATUS_TO_CLASS[status]
    assert failure.error_class is not ErrorClass.INTERNAL_DEPENDENCY


def test_a_404_on_a_pinned_id_is_permanent_and_never_falls_back() -> None:
    """ADR-014. Catalog churn looks like a lifecycle event, but the id came from the bot's own
    configuration snapshot, so falling back would answer from a model the tenant never chose on
    a bot that still reads ``Ready``."""
    failure = NimAdapter().classify(FakeStatusError(404), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


def test_an_unmapped_status_is_permanent_rather_than_temporary() -> None:
    """Unknown classifies as PERMANENT: the cost of wrongly-permanent is one visible failure,
    and the cost of wrongly-temporary is an unbounded queue against a request that will never
    succeed."""
    failure = NimAdapter().classify(FakeStatusError(418), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


def test_a_transport_failure_with_no_body_is_temporary() -> None:
    """GPU exhaustion kills a self-hosted container at boot and there is no HTTP status to
    read, so the absence IS the evidence."""
    import httpx

    failure = NimAdapter().classify(httpx.ConnectError("refused"), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert failure.native_code == "ConnectError"


def test_no_retry_after_is_ever_invented() -> None:
    """NVIDIA publishes no rate-limit or 429-header schema for the catalog, and an invented
    reset time outranks the jittered backoff and pins us inside the rejection window."""
    failure = NimAdapter().classify(FakeStatusError(429), tokens_emitted=0)
    assert failure.error_class is ErrorClass.PROVIDER_RATE_LIMIT
    assert failure.retry_after is None


async def test_the_rate_limit_diagnostics_stay_empty() -> None:
    adapter, _ = chat_adapter_raising(FakeStatusError(429))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.diagnostics.rate_limit == {}


async def test_a_failure_before_the_first_byte_terminates_with_one_classified_result() -> None:
    adapter, _ = chat_adapter_raising(FakeStatusError(503))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.stop_reason is StopReason.ERROR
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.provider_request_id == "req_err"


async def test_a_mid_stream_failure_keeps_the_text_already_emitted() -> None:
    """Once a token is out we have been billed, and the router reads ``text`` to learn that a
    retry would re-bill a partial completion."""
    adapter, _, _ = chat_adapter([chunk(), chunk()], fail_with=FakeStatusError(500))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.text == "Thirty " * 2
    assert result.usage.output_tokens == 2


# ── the request body, the headers, and what is never sent ────────────────────


async def sent_chat_body(**overrides: Any) -> dict[str, Any]:
    caps = overrides.pop("caps", caps_for())
    adapter, client, _ = chat_adapter([finish("stop"), wire(USAGE_CHUNK)])
    await drain(adapter, request_for(**overrides), caps)
    return client.calls[0]


async def test_stream_is_explicit_and_never_defaulted() -> None:
    """``stream`` defaults to false on some catalog models and true on others, so an omitted
    value is a different request per model id served by one credential."""
    body = await sent_chat_body()
    assert body["stream"] is True
    assert body["max_tokens"] == 2048


async def test_the_request_id_header_is_sent_on_chat() -> None:
    """NIM adopts an ``X-Request-Id`` we send and never synthesizes one, so sending it is
    mandatory rather than optional — otherwise ``provider_request_id`` is permanently null and
    NVIDIA support has nothing to look up."""
    body = await sent_chat_body()
    assert body["extra_headers"][REQUEST_ID_HEADER] == TRACE


async def test_provider_request_id_is_populated_from_the_id_we_sent() -> None:
    adapter, _, _ = chat_adapter([finish("stop"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.provider_request_id == TRACE


async def test_an_echoed_request_id_is_preferred_over_the_one_we_sent() -> None:
    adapter, _, _ = chat_adapter(
        [finish("stop"), wire(USAGE_CHUNK)], headers={"x-request-id": "echoed-by-nim"}
    )
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert result.provider_request_id == "echoed-by-nim"


async def test_retrieved_evidence_is_a_user_turn_and_never_the_system_message() -> None:
    """Source text that can reach the instruction slot is prompt injection with our own
    retrieval pipeline as the delivery mechanism. NIM documents a ``context`` message role and
    we do not use it: its handling is per-model and undocumented."""
    body = await sent_chat_body()
    system = body["messages"][0]
    assert system == {"role": "system", "content": SYSTEM_PROMPT}
    assert CONTEXT_TEXT not in system["content"]
    evidence = body["messages"][1]
    assert evidence["role"] == "user"
    assert evidence["content"] == CONTEXT_BLOCK_TEMPLATE.format(
        index=0, title="Refund policy", text=CONTEXT_TEXT
    )
    assert not any(message["role"] == "context" for message in body["messages"])


async def test_nvext_is_never_sent() -> None:
    """NIM LLM 2.0 REMOVED the extension object that 1.x used for guided decoding, and unknown
    top-level fields are accepted and IGNORED — so an adapter still writing ``nvext.guided_json``
    writes to a surface that no longer exists and gets 200 and confident prose."""
    body = await sent_chat_body()
    assert "nvext" not in body
    assert "guided_json" not in str(body)


# ── validate(): reject or warn, never a silent drop ──────────────────────────


def test_an_out_of_range_temperature_is_refused_before_the_first_byte() -> None:
    """OpenAI allows 2.0 and NIM answers 422 rather than clamping, so the range has to fail in
    ``validate()`` and not in flight."""
    with pytest.raises(KbError) as raised:
        NimAdapter().validate(
            request_for(temperature=MAX_TEMPERATURE + 0.5),
            caps_for(supported=CHAT_FLAGS | {Capability.SAMPLING}),
        )
    assert raised.value.error_class is ErrorClass.VALIDATION


async def test_an_out_of_range_temperature_is_not_sent_under_warn() -> None:
    """``warn`` records it and strips it. What it must never do is send it anyway: that is a
    422 on a request that would otherwise have worked."""
    caps = caps_for(supported=CHAT_FLAGS | {Capability.SAMPLING}, on_unsupported="warn")
    body = await sent_chat_body(temperature=MAX_TEMPERATURE + 0.5, caps=caps)
    assert "temperature" not in body


async def test_an_in_range_temperature_is_sent() -> None:
    caps = caps_for(supported=CHAT_FLAGS | {Capability.SAMPLING})
    body = await sent_chat_body(temperature=0.4, caps=caps)
    assert body["temperature"] == 0.4


def test_a_response_schema_is_rejected_while_structured_output_is_off() -> None:
    with pytest.raises(KbError):
        NimAdapter().validate(request_for(response_schema={"type": "object"}), caps_for())


def test_a_response_schema_is_a_warning_under_warn_and_never_a_drop() -> None:
    warnings = NimAdapter().validate(
        request_for(response_schema={"type": "object"}),
        caps_for(on_unsupported="warn"),
    )
    assert [(w.option, w.action) for w in warnings] == [("response_schema", "ignored")]


def test_a_row_claiming_structured_output_is_refused_on_both_settings() -> None:
    """The only place this adapter refuses a ROW rather than a request. NIM publishes no
    json_schema surface at all, so honouring the flag would send a field this vendor ignores
    rather than rejects."""
    for setting in ("reject", "warn"):
        with pytest.raises(KbError):
            NimAdapter().validate(
                request_for(response_schema={"type": "object"}),
                caps_for(
                    supported=CHAT_FLAGS | {Capability.STRUCTURED_OUTPUT},
                    on_unsupported=setting,
                ),
            )


def test_max_output_tokens_above_the_rows_ceiling_raises_on_both_settings() -> None:
    """The ceilings genuinely disagree per model on one connection — 4096 on
    llama-3.1-8b-instruct, 32768 on nemotron-3-super — so a bot configuration that validates
    against one NIM model is a 422 on the next."""
    for setting in ("reject", "warn"):
        with pytest.raises(KbError):
            NimAdapter().validate(
                request_for(max_output_tokens=8192),
                caps_for(max_output_tokens=4096, on_unsupported=setting),
            )


@pytest.mark.parametrize("effort", sorted(ROUNDED_EFFORTS))
def test_a_rounded_effort_is_always_reported_and_never_silently_rounded(effort: str) -> None:
    """The contract's rule outranks ``on_unsupported`` here: a vendor lacking a level maps to
    its nearest supported step AND says so. Rejecting instead would refuse every ``max``-effort
    request on a vendor that has a perfectly good ``high``."""
    warnings = NimAdapter().validate(
        request_for(reasoning=ReasoningOption(effort=effort)),
        caps_for(supported=CHAT_FLAGS | {Capability.REASONING}),
    )
    rounded = [w for w in warnings if w.option == "reasoning.effort"]
    assert len(rounded) == 1
    assert REASONING_EFFORT[effort] in rounded[0].detail


@pytest.mark.parametrize("effort", sorted(set(REASONING_EFFORT) - ROUNDED_EFFORTS))
def test_an_exact_effort_produces_no_rounding_warning(effort: str) -> None:
    warnings = NimAdapter().validate(
        request_for(reasoning=ReasoningOption(effort=effort)),
        caps_for(supported=CHAT_FLAGS | {Capability.REASONING}),
    )
    assert [w for w in warnings if w.option == "reasoning.effort"] == []


async def test_the_reasoning_budget_is_sent_because_this_vendor_enforces_it() -> None:
    """NIM is the one vendor where ``budget_tokens`` is a real control rather than advisory.
    It gets no new ``Capability`` member — one vendor enforcing an existing portable field is
    not a new capability — and the mapping is recorded in ``Diagnostics.extras``."""
    caps = caps_for(supported=CHAT_FLAGS | {Capability.REASONING})
    body = await sent_chat_body(
        reasoning=ReasoningOption(effort="medium", budget_tokens=2048), caps=caps
    )
    assert body["reasoning_effort"] == REASONING_EFFORT["medium"]
    assert body["reasoning_budget"] == 2048

    adapter, _, _ = chat_adapter([finish("stop"), wire(USAGE_CHUNK)])
    result = only_result(
        await drain(
            adapter,
            request_for(reasoning=ReasoningOption(effort="medium", budget_tokens=2048)),
            caps,
        )
    )
    assert result.diagnostics.extras["reasoning_budget"] == 2048


def test_reasoning_on_a_row_without_the_flag_is_refused_rather_than_dropped() -> None:
    with pytest.raises(KbError):
        NimAdapter().validate(
            request_for(reasoning=ReasoningOption(effort="high", budget_tokens=512)),
            caps_for(),
        )


@pytest.mark.parametrize(
    ("option", "overrides"),
    [
        ("stream", {"stream": False}),
        ("tools", {"tools": [ToolDef(name="t", description="d", parameters={})]}),
        ("cache_hint", {"cache_hint": "prefix"}),
    ],
)
def test_each_unsupported_option_rejects_or_warns_and_is_never_dropped(
    option: str, overrides: dict[str, Any]
) -> None:
    with pytest.raises(KbError):
        NimAdapter().validate(request_for(**overrides), caps_for())

    warnings = NimAdapter().validate(request_for(**overrides), caps_for(on_unsupported="warn"))
    assert [w.option for w in warnings] == [option]
    assert warnings[0].action == "ignored"


# ── rerank: input alignment, the exact cover, and the scale ──────────────────


async def test_rerank_scatters_the_response_back_to_input_order() -> None:
    """The quietest defect on this surface. The response is sorted by relevance and the
    ``index`` values are the ONLY ordering evidence, so a positional read attaches perfectly
    plausible floats to the wrong passages — a stage that "barely changes the order and looks
    pointless" rather than one that fails."""
    passages = ["alpha", "bravo", "charlie", "delta"]
    # Sorted by relevance, which is nothing like input order.
    payload = ranking_payload([(2, 3.5), (0, 0.226), (3, -1.17), (1, -4.02)])
    adapter, _ = rank_adapter(payload)

    result = await adapter.rerank(rerank_request(passages), caps_for(supported=RERANK_FLAGS), KEY)

    assert result.scores == [0.226, -4.02, 3.5, -1.17]
    # Stated again as the property rather than as the literal list: scores[i] belongs to
    # passages[i], and the highest score belongs to the passage the vendor ranked first.
    assert result.scores[2] == 3.5
    assert result.scores.index(max(result.scores)) == 2


async def test_rerank_is_never_read_positionally() -> None:
    """A response whose entries are shuffled but whose ``index`` values are a permutation must
    produce the same answer as one that arrives sorted. If the two disagree, the adapter is
    reading positions."""
    passages = ["alpha", "bravo", "charlie"]
    scored = [(0, -1.5), (1, 2.25), (2, 0.5)]
    caps = caps_for(supported=RERANK_FLAGS)

    adapter_a, _ = rank_adapter(ranking_payload(scored))
    adapter_b, _ = rank_adapter(ranking_payload(list(reversed(scored))))
    first = await adapter_a.rerank(rerank_request(passages), caps, KEY)
    second = await adapter_b.rerank(rerank_request(passages), caps, KEY)

    assert first.scores == second.scores == [-1.5, 2.25, 0.5]


async def test_a_short_cover_raises_rather_than_silently_truncating() -> None:
    """Silently dropped passages become evidence that vanished, and the answer that follows is
    grounded in a subset nobody chose."""
    adapter, _ = rank_adapter(ranking_payload([(0, 1.0), (2, 0.5)]))
    with pytest.raises(KbError) as raised:
        await adapter.rerank(rerank_request(["a", "b", "c"]), caps_for(supported=RERANK_FLAGS), KEY)
    assert raised.value.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


async def test_a_duplicated_cover_raises() -> None:
    adapter, _ = rank_adapter(ranking_payload([(0, 1.0), (0, 0.5), (1, 0.25)]))
    with pytest.raises(KbError):
        await adapter.rerank(rerank_request(["a", "b"]), caps_for(supported=RERANK_FLAGS), KEY)


async def test_an_index_outside_the_request_raises() -> None:
    adapter, _ = rank_adapter(ranking_payload([(0, 1.0), (7, 0.5)]))
    with pytest.raises(KbError):
        await adapter.rerank(rerank_request(["a", "b"]), caps_for(supported=RERANK_FLAGS), KEY)


async def test_rerank_carries_the_unbounded_logit_scale() -> None:
    """``RerankResult.scale`` is the VENDOR's claim about what its numbers mean, which is what
    makes stage 12's assertion a real comparison rather than a value against a copy of itself.
    0.30 is a valid float on every scale and nothing raises when they are swapped."""
    adapter, _ = rank_adapter(ranking_payload([(0, 0.226), (1, -1.17)]))
    result = await adapter.rerank(rerank_request(["a", "b"]), caps_for(supported=RERANK_FLAGS), KEY)

    assert result.scale is RerankScale.LOGIT
    assert result.scale is RERANK_SCALE["nvidia_nim"]
    assert not result.scale.is_bounded
    # Unbounded and still thresholdable: a threshold is a percentile read off a measured
    # distribution, not a fraction of a range.
    assert result.scale.may_threshold
    # NOT NORMALIZED AND NOT BOUNDED BY THE ADAPTER. A negative score is the vendor's own
    # published example and must survive to the stage that reads it.
    assert min(result.scores) < 0


async def test_no_top_n_is_ever_sent() -> None:
    """The endpoint offers one. Sending it truncates and re-sorts the response, at which point
    ``scores[i]`` no longer refers to ``passages[i]``; depth is a retrieval-stage decision."""
    adapter, client = rank_adapter(ranking_payload([(0, 1.0), (1, 0.0)]))
    await adapter.rerank(rerank_request(["a", "b"]), caps_for(supported=RERANK_FLAGS), KEY)
    body = client.calls[0]["json"]
    assert "top_n" not in body


async def test_the_ranking_request_puts_the_query_first_and_pins_truncate() -> None:
    """Ranking APIs are order-sensitive and return perfectly reasonable numbers when reversed.
    The asymmetric fixture is what locks it: a query string and a passage list are different
    shapes on the wire."""
    adapter, client = rank_adapter(ranking_payload([(0, 1.0), (1, 0.0)]))
    await adapter.rerank(rerank_request(["alpha", "bravo"]), caps_for(supported=RERANK_FLAGS), KEY)
    call = client.calls[0]
    assert call["path"] == RANKING_PATH
    assert call["json"]["query"] == {"text": "how long is the refund window"}
    assert call["json"]["passages"] == [{"text": "alpha"}, {"text": "bravo"}]
    assert call["json"]["truncate"] == TRUNCATE_POLICY
    assert call["headers"][REQUEST_ID_HEADER] == TRACE


async def test_the_ranking_client_is_always_closed() -> None:
    """An unclosed client leaks a connection pool per rerank call, and on the retrieval path
    that is once per query."""
    adapter, client = rank_adapter(ranking_payload([(0, 1.0)]))
    await adapter.rerank(rerank_request(["a"]), caps_for(supported=RERANK_FLAGS), KEY)
    assert client.closed


async def test_a_queued_ranking_call_is_a_failure_and_never_an_empty_result() -> None:
    adapter, _ = rank_adapter({}, status_code=HTTP_ACCEPTED)
    with pytest.raises(KbError) as raised:
        await adapter.rerank(rerank_request(["a"]), caps_for(supported=RERANK_FLAGS), KEY)
    assert raised.value.error_class is ErrorClass.PROVIDER_TEMPORARY


async def test_a_failed_rerank_carries_zero_tokens_emitted() -> None:
    """Nothing streams on this surface, so the "once a token is out we are billed" gate never
    fires and a retry against the SAME connection is legitimate."""
    adapter, _ = rank_adapter({}, status_code=503)
    with pytest.raises(KbError) as raised:
        await adapter.rerank(rerank_request(["a"]), caps_for(supported=RERANK_FLAGS), KEY)
    assert getattr(raised.value, "tokens_emitted", None) == 0


async def test_rerank_usage_is_estimated_zeros_rather_than_an_invented_token_count() -> None:
    """The ranking response carries no usage object at all. A count invented from passage
    lengths would look real, and a plausible wrong cost is worse than a missing one."""
    adapter, _ = rank_adapter(ranking_payload([(0, 1.0)]))
    result = await adapter.rerank(rerank_request(["a"]), caps_for(supported=RERANK_FLAGS), KEY)
    assert result.usage.source == "estimated"
    assert result.usage.total_input_tokens == 0
    assert result.usage.output_tokens == 0
    assert result.provider_request_id == TRACE


# ── validate_rerank(): the checks with no error to catch ─────────────────────


def test_a_pair_over_the_window_is_rejected_and_never_warned() -> None:
    """The pair-length limit truncates SILENTLY by documented default: the passage scores off
    the distribution the threshold was calibrated on, it fires hardest on the largest chunks,
    and the only symptom is a refusal rate that moved months later."""
    window = 128
    oversized = "x" * int((window + 100) * ESTIMATED_CHARS_PER_TOKEN)
    with pytest.raises(KbError) as raised:
        NimAdapter().validate_rerank(
            rerank_request(["fine", oversized]),
            caps_for(supported=RERANK_FLAGS, context_window=window),
        )
    assert raised.value.error_class is ErrorClass.VALIDATION
    assert "passages[1]" in raised.value.message


def test_an_over_long_pair_is_rejected_under_warn_too() -> None:
    """A warned truncation is still a truncation. There is no ``action="ignored"`` form of
    this check on either setting."""
    window = 64
    oversized = "x" * int((window + 100) * ESTIMATED_CHARS_PER_TOKEN)
    with pytest.raises(KbError):
        NimAdapter().validate_rerank(
            rerank_request([oversized]),
            caps_for(supported=RERANK_FLAGS, context_window=window, on_unsupported="warn"),
        )


def test_the_query_is_charged_against_every_pair() -> None:
    """The window has to hold the query AND the passage. Checking the passage alone passes a
    pair that the vendor then trims."""
    window = 40
    caps = caps_for(supported=RERANK_FLAGS, context_window=window)
    passage = "y" * int(window * ESTIMATED_CHARS_PER_TOKEN * 0.75)
    query = "z" * int(window * ESTIMATED_CHARS_PER_TOKEN * 0.75)
    NimAdapter().validate_rerank(rerank_request([passage], query="a"), caps)
    with pytest.raises(KbError):
        NimAdapter().validate_rerank(rerank_request([passage], query=query), caps)


def test_more_passages_than_the_cap_is_a_rejection_and_never_a_split_into_several_calls() -> None:
    """Depth is a retrieval decision. An adapter that quietly issued five round-trips would
    make the retrieval budget a function of a number the stage never chose."""
    with pytest.raises(KbError):
        NimAdapter().validate_rerank(
            rerank_request(["a"] * (MAX_RERANK_PASSAGES + 1)),
            caps_for(supported=RERANK_FLAGS),
        )


def test_a_blank_passage_is_refused_rather_than_given_a_real_score() -> None:
    """An empty passage scored against a query returns a perfectly real number, which then
    ranks a missing passage against present ones."""
    with pytest.raises(KbError) as raised:
        NimAdapter().validate_rerank(
            rerank_request(["fine", "   "]), caps_for(supported=RERANK_FLAGS)
        )
    assert "passages[1]" in raised.value.message


def test_a_row_without_the_rerank_flag_cannot_reach_the_ranking_endpoint() -> None:
    with pytest.raises(KbError):
        NimAdapter().validate_rerank(rerank_request(["a"]), caps_for(supported=frozenset()))


# ── embedding: the input_type switch, the re-sort, and the exact cover ───────


async def test_the_passage_input_type_is_sent_for_a_passage() -> None:
    """NVIDIA's own schema says failing to use the correct one "will result in large drops in
    retrieval accuracy". Nothing errors when it is wrong — recall simply falls, uniformly, for
    the life of the index."""
    adapter, client = embed_adapter([entry(0, [1.0, 0.0])])
    await adapter.embed(embed_request(["chunk"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert (
        client.calls[0]["extra_body"]["input_type"]
        == (EMBEDDING_INPUT_TYPES[EmbeddingInputType.PASSAGE])
    )


async def test_the_query_input_type_is_sent_for_a_query() -> None:
    adapter, client = embed_adapter([entry(0, [1.0, 0.0])])
    await adapter.embed(
        embed_request(["a question"], input_type=EmbeddingInputType.QUERY),
        caps_for(supported=EMBED_FLAGS),
        KEY,
    )
    assert (
        client.calls[0]["extra_body"]["input_type"]
        == (EMBEDDING_INPUT_TYPES[EmbeddingInputType.QUERY])
    )


def test_the_two_input_types_are_distinct_on_the_wire() -> None:
    """The whole value of the switch is that the two sides differ. A mapping that collapsed
    them would be silently wrong on one of them forever."""
    assert len(set(EMBEDDING_INPUT_TYPES.values())) == len(EmbeddingInputType)


async def test_vectors_come_back_in_input_order_not_response_order() -> None:
    """A positional read produces a fully populated, fully wrong index: every chunk carries
    some other chunk's vector, nothing raises, every count matches, and retrieval returns
    plausible neighbours that are not neighbours."""
    adapter, _ = embed_adapter(
        [entry(2, [0.0, 0.0, 1.0]), entry(0, [1.0, 0.0, 0.0]), entry(1, [0.0, 1.0, 0.0])]
    )
    result = await adapter.embed(
        embed_request(["first", "second", "third"]), caps_for(supported=EMBED_FLAGS), KEY
    )
    assert result.vectors == [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]


async def test_a_partial_batch_never_becomes_a_partial_result() -> None:
    """Half a batch written is half a source version's vectors, and the version then publishes
    as complete."""
    adapter, _ = embed_adapter([entry(0, [1.0]), entry(1, [1.0])])
    with pytest.raises(KbError) as raised:
        await adapter.embed(embed_request(["a", "b", "c"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert raised.value.error_class is ErrorClass.PROVIDER_PERMANENT_REQUEST


async def test_a_duplicated_embedding_index_raises() -> None:
    adapter, _ = embed_adapter([entry(0, [1.0]), entry(0, [0.5])])
    with pytest.raises(KbError):
        await adapter.embed(embed_request(["a", "b"]), caps_for(supported=EMBED_FLAGS), KEY)


async def test_the_width_is_read_off_the_response_and_not_off_the_model_id() -> None:
    """``nv-embedqa-e5-v5`` is 1024-wide, and so are ``baai/bge-m3`` and a truncated
    ``text-embedding-3-large``. All three upsert cleanly into one collection and mean nothing
    to each other, which is why the id never implies the width."""
    adapter, _ = embed_adapter([entry(0, [0.1] * 7)])
    result = await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert result.space.dimensions == 7
    assert result.space.provider == "nvidia_nim"
    assert result.space.model == EMBED_MODEL


async def test_a_batch_whose_widths_disagree_is_refused_before_it_reaches_a_collection() -> None:
    adapter, _ = embed_adapter([entry(0, [0.1, 0.2]), entry(1, [0.1])])
    with pytest.raises(KbError):
        await adapter.embed(embed_request(["a", "b"]), caps_for(supported=EMBED_FLAGS), KEY)


async def test_normalized_reports_what_the_vendor_actually_returned() -> None:
    adapter, _ = embed_adapter([entry(0, [1.0, 0.0])])
    unit = await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert unit.normalized is True

    adapter, _ = embed_adapter([entry(0, [3.0, 4.0])])
    scaled = await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert scaled.normalized is False


async def test_embedding_usage_is_prompt_tokens_with_no_generated_half() -> None:
    adapter, _ = embed_adapter([entry(0, [1.0])], usage={"prompt_tokens": 91, "total_tokens": 91})
    result = await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert result.usage.input_tokens == 91
    assert result.usage.total_input_tokens == 91
    assert result.usage.output_tokens == 0
    assert result.usage.source == "provider_final"


def test_the_embedding_usage_helper_is_not_the_chat_one() -> None:
    """Separate on purpose: the chat response's ``completion_tokens`` is meaningless here, and
    the day NVIDIA adds a cached amount to one surface and not the other a shared helper would
    apply the wrong arithmetic to whichever it was not written for."""
    raw = wire({"prompt_tokens": 5, "completion_tokens": 9, "total_tokens": 14})
    assert NimAdapter._embedding_usage(raw).output_tokens == 0
    assert NimAdapter._usage(raw).output_tokens == 9


async def test_truncate_is_pinned_to_none_and_never_sent_as_anything_else() -> None:
    """START and END discard text until it fits and return a 200 with a plausible vector for a
    passage whose tail is then unsearchable forever."""
    adapter, client = embed_adapter([entry(0, [1.0])])
    await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert client.calls[0]["extra_body"]["truncate"] == TRUNCATE_POLICY
    assert TRUNCATE_POLICY == "NONE"


async def test_the_request_id_header_is_sent_on_embed() -> None:
    adapter, client = embed_adapter([entry(0, [1.0])])
    result = await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert client.calls[0]["extra_headers"][REQUEST_ID_HEADER] == TRACE
    assert result.provider_request_id == TRACE


async def test_a_queued_embedding_call_is_classified_before_the_body_is_parsed() -> None:
    """A 202 is a queued invocation with no vectors in it. Parsing it produces an empty ``data``
    and a cover assertion that fires with the wrong explanation."""
    adapter, client = embed_adapter([entry(0, [1.0])], status_code=HTTP_ACCEPTED)
    with pytest.raises(KbError) as raised:
        await adapter.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    assert raised.value.error_class is ErrorClass.PROVIDER_TEMPORARY
    assert client._raw.parsed_calls == 0


# ── validate_embedding(): reject or warn, never a drop ───────────────────────


def test_a_row_without_the_input_type_flag_is_refused_on_both_settings() -> None:
    """The mirror image of the OpenAI adapter, where the same field is always a warning and
    never a rejection: there the parameter is inert, and here it is required and load-bearing."""
    for setting in ("reject", "warn"):
        with pytest.raises(KbError):
            NimAdapter().validate_embedding(
                embed_request(["a"]),
                caps_for(supported=frozenset({Capability.EMBEDDING}), on_unsupported=setting),
            )


def test_dimensions_is_refused_under_reject() -> None:
    with pytest.raises(KbError):
        NimAdapter().validate_embedding(
            embed_request(["a"], dimensions=512), caps_for(supported=EMBED_FLAGS)
        )


async def test_dimensions_is_warned_under_warn_and_never_forwarded() -> None:
    """This vendor ignores unknown body fields rather than rejecting them, so a forwarded
    ``dimensions`` returns the native width with a 200 and fails at upsert — after the whole
    batch has been paid for."""
    caps = caps_for(supported=EMBED_FLAGS, on_unsupported="warn")
    adapter, client = embed_adapter([entry(0, [1.0])])
    result = await adapter.embed(embed_request(["a"], dimensions=512), caps, KEY)
    assert "dimensions" not in client.calls[0]
    assert "dimensions" not in client.calls[0]["extra_body"]
    assert [(w.option, w.action) for w in result.diagnostics.warnings] == [
        ("dimensions", "ignored")
    ]


def test_the_batch_ceiling_is_a_backstop_with_a_sentence() -> None:
    with pytest.raises(KbError):
        NimAdapter().validate_embedding(
            embed_request(["a"] * (MAX_EMBEDDING_INPUTS + 1)), caps_for(supported=EMBED_FLAGS)
        )


def test_a_blank_input_is_named_by_position_rather_than_refused_as_a_batch() -> None:
    with pytest.raises(KbError) as raised:
        NimAdapter().validate_embedding(
            embed_request(["fine", " "]), caps_for(supported=EMBED_FLAGS)
        )
    assert "texts[1]" in raised.value.message


def test_an_over_window_input_is_rejected_and_never_truncated() -> None:
    window = 32
    with pytest.raises(KbError):
        NimAdapter().validate_embedding(
            embed_request(["x" * int((window + 50) * ESTIMATED_CHARS_PER_TOKEN)]),
            caps_for(supported=EMBED_FLAGS, context_window=window),
        )


# ── the credential, and what a Diagnostics payload may carry ─────────────────


async def test_the_terminal_diagnostics_carry_no_prompt_and_no_credential() -> None:
    """A vendor error body routinely echoes the request, and the request contains the packed
    prompt, which contains tenant document text."""
    adapter, _, _ = chat_adapter([chunk(), finish("stop"), wire(USAGE_CHUNK)])
    result = only_result(await drain(adapter, request_for(), caps_for()))
    serialized = result.diagnostics.model_dump_json()
    assert SYSTEM_PROMPT not in serialized
    assert CONTEXT_TEXT not in serialized
    assert KEY.get_secret_value() not in serialized
    assert "nvapi-" not in serialized


async def test_the_vendors_message_never_reaches_the_terminal_event() -> None:
    adapter, _ = chat_adapter_raising(FakeStatusError(422))
    result = only_result(await drain(adapter, request_for(), caps_for()))
    assert "vendor message" not in result.model_dump_json()


async def test_no_surface_leaks_the_credential_into_its_result() -> None:
    embedder, _ = embed_adapter([entry(0, [1.0])])
    embedding = await embedder.embed(embed_request(["a"]), caps_for(supported=EMBED_FLAGS), KEY)
    ranker, _ = rank_adapter(ranking_payload([(0, 1.0)]))
    ranked = await ranker.rerank(rerank_request(["a"]), caps_for(supported=RERANK_FLAGS), KEY)

    for payload in (embedding.model_dump_json(), ranked.model_dump_json()):
        assert KEY.get_secret_value() not in payload
        assert "nvapi-" not in payload


def test_get_secret_value_appears_once_per_surface_and_nowhere_else() -> None:
    """Extracting the key must be a greppable act rather than an accident of serialization.
    Three surfaces, three client construction sites, three extractions — and the count is read
    off the module's source so a fourth cannot be added quietly."""
    import inspect

    from app.providers import nim

    source = inspect.getsource(nim)
    # The CALL, not the prose: the module names the rule in three comments as well, and a
    # bare `get_secret_value()` count would go green on a docstring edit.
    assert source.count("credential.get_secret_value()") == 3


# ── the seams themselves ──────────────────────────────────────────────────────


def test_every_client_is_built_with_retries_disabled() -> None:
    """The SDK retries twice by default, invisibly, inside one ``await``, so the span records
    one call while NVIDIA's dashboard records three — and it is easy to remember for the OpenAI
    adapter and easy to forget here, because this is "the NVIDIA provider"."""
    adapter = NimAdapter()
    chat = adapter._client(KEY, request_for())
    embedding = adapter._embedding_client(KEY, embed_request(["a"]))
    assert chat.max_retries == 0
    assert embedding.max_retries == 0
    assert str(chat.base_url).rstrip("/") == BASE_URL
    assert str(embedding.base_url).rstrip("/") == BASE_URL


async def test_the_ranking_client_carries_the_credential_and_the_base_url() -> None:
    client = NimAdapter()._ranking_client(KEY, rerank_request(["a"]))
    try:
        assert str(client.base_url).rstrip("/") == BASE_URL
        assert client.headers["authorization"].endswith(KEY.get_secret_value())
    finally:
        await client.aclose()


def test_the_status_error_carries_no_vendor_body() -> None:
    """422 bodies on this vendor echo the request, and the request carries the packed prompt or
    the tenant's chunk text."""
    error = NimStatusError(HTTP_ACCEPTED, request_id="req-1")
    assert str(HTTP_ACCEPTED) in str(error)
    assert not hasattr(error, "body")

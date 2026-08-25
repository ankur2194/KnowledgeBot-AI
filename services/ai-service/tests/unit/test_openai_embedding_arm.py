"""The OpenAI embedding arm — the half of the adapter ADR-030 made load-bearing.

Only the embedding surface is implemented in ``openai_adapter``; the chat half is still a
skeleton, deliberately, because the 2026-08-10 line puts the five wire adapters out of scope
and ``run_version`` cannot embed a chunk without exactly one of them. Every test here is about
a failure that produces a **fully populated, fully wrong index** rather than an error:

* **Response order is not input order.** OpenAI documents an ``index`` on every entry.
  ``EmbeddingResult.vectors[i]`` is contractually the embedding of ``texts[i]``, so a
  positional read gives every chunk some other chunk's vector. Nothing raises, every count
  matches, and retrieval returns plausible neighbours that are not neighbours.
* **A partial batch must never become a partial result.** Half a batch written is half a
  source version's vectors, and the version then publishes as complete.
* **The width comes off the response.** ``dimensions`` truncates and ids ship at more than one
  width, so the id does not imply it — and a width COINCIDENCE upserts cleanly and means
  nothing.
* **The 429 splits on a vendor code, not on the status.** Both values arrive as
  ``RateLimitError``; reading the status treats an exhausted account as a transient limit.

Nothing here touches a network. The SDK client is replaced at the one seam built for it —
``OpenAIAdapter._client`` — which is also the test of whether that seam is where it should be.
"""

from __future__ import annotations

from typing import Any, Final

import pytest
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.contract import (
    Capability,
    EmbeddingInputType,
    EmbeddingRequest,
    ModelCapabilities,
)
from app.providers.openai_adapter import MAX_EMBEDDING_INPUTS, OpenAIAdapter

ORG: Final = "01JQZ0000000000000000000AA"
CONNECTION: Final = "01JQZ0000000000000000000CN"
MODEL: Final = "text-embedding-3-large"


def caps_for(
    *,
    supported: frozenset[Capability] = frozenset({Capability.EMBEDDING}),
    on_unsupported: str = "reject",
    context_window: int = 8192,
) -> ModelCapabilities:
    return ModelCapabilities(
        supported=supported,
        context_window=context_window,
        max_output_tokens=0,
        on_unsupported=on_unsupported,  # type: ignore[arg-type]
    )


def request_for(texts: list[str], **overrides: Any) -> EmbeddingRequest:
    return EmbeddingRequest(
        org_id=ORG,
        trace_id="trace",
        provider_connection_id=CONNECTION,
        model=MODEL,
        texts=texts,
        input_type=EmbeddingInputType.PASSAGE,
        **overrides,
    )


class FakeEntry:
    def __init__(self, index: int, embedding: list[float]) -> None:
        self.index = index
        self.embedding = embedding


class FakeUsage:
    def __init__(self, prompt_tokens: int) -> None:
        self.prompt_tokens = prompt_tokens
        self.total_tokens = prompt_tokens


class FakeResponse:
    def __init__(self, data: list[FakeEntry], model: str, usage: FakeUsage | None) -> None:
        self.data = data
        self.model = model
        self.usage = usage


class FakeRaw:
    """The ``with_raw_response`` shape: headers now, body on ``parse()``.

    The adapter uses the raw form because ``x-request-id`` and the rate-limit headers are only
    reachable there, and they are the only handle vendor support accepts.
    """

    def __init__(self, response: FakeResponse, headers: dict[str, str]) -> None:
        self._response = response
        self.headers = headers

    def parse(self) -> FakeResponse:
        return self._response


class FakeClient:
    def __init__(self, raw: FakeRaw) -> None:
        self._raw = raw
        self.calls: list[dict[str, Any]] = []

        outer = self

        class _Raw:
            async def create(self, **kwargs: Any) -> FakeRaw:
                outer.calls.append(kwargs)
                return outer._raw

        class _Embeddings:
            with_raw_response = _Raw()

        self.embeddings = _Embeddings()


def adapter_returning(
    entries: list[FakeEntry],
    *,
    model: str = MODEL,
    usage: FakeUsage | None = None,
    headers: dict[str, str] | None = None,
) -> tuple[OpenAIAdapter, FakeClient]:
    client = FakeClient(
        FakeRaw(
            FakeResponse(entries, model, usage if usage is not None else FakeUsage(11)),
            headers if headers is not None else {"x-request-id": "req_abc"},
        )
    )
    adapter = OpenAIAdapter()
    adapter._client = lambda credential: client  # type: ignore[method-assign]
    return adapter, client


# ── input order is the contract ───────────────────────────────────────────────


async def test_vectors_come_back_in_input_order_not_response_order() -> None:
    """The quietest defect on this surface, and the one the vendor warns about.

    The entries below arrive 2, 0, 1. A positional read would pair `texts[0]` with the vector
    belonging to `texts[2]` — a complete index in which every chunk carries someone else's
    meaning, with no exception, no width mismatch and no count that disagrees.
    """
    adapter, _ = adapter_returning(
        [
            FakeEntry(2, [0.0, 0.0, 1.0]),
            FakeEntry(0, [1.0, 0.0, 0.0]),
            FakeEntry(1, [0.0, 1.0, 0.0]),
        ]
    )
    result = await adapter.embed(
        request_for(["first", "second", "third"]), caps_for(), SecretStr("sk-test")
    )
    assert result.vectors == [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]


async def test_a_short_batch_raises_rather_than_returning_what_arrived() -> None:
    """Half a batch written is half a source version's vectors, and the version publishes as
    complete. A partial result has no honest representation here, so there is none."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0]), FakeEntry(1, [1.0])])
    with pytest.raises(KbError, match="A partial batch is never returned"):
        await adapter.embed(request_for(["a", "b", "c"]), caps_for(), SecretStr("sk-test"))


async def test_a_duplicated_index_raises() -> None:
    """Two entries for one input means the response does not describe the batch that was sent.
    Under a positional read this is invisible; under a dict build it would silently drop one."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0]), FakeEntry(0, [2.0])])
    with pytest.raises(KbError, match="two embeddings for index 0"):
        await adapter.embed(request_for(["a", "b"]), caps_for(), SecretStr("sk-test"))


async def test_an_index_outside_the_batch_raises() -> None:
    adapter, _ = adapter_returning([FakeEntry(0, [1.0]), FakeEntry(7, [1.0])])
    with pytest.raises(KbError, match="outside the 2 inputs"):
        await adapter.embed(request_for(["a", "b"]), caps_for(), SecretStr("sk-test"))


# ── the space is measured, never declared ─────────────────────────────────────


async def test_the_width_is_read_off_the_response_and_not_off_the_model_id() -> None:
    """`dimensions` truncates (Matryoshka) and several ids ship at more than one width, so the
    id does not imply the width. The dangerous case is not a mismatch — Qdrant rejects those —
    it is a COINCIDENCE: three different models at 1024 are mutually meaningless and all upsert
    cleanly."""
    adapter, _ = adapter_returning([FakeEntry(0, [0.1] * 1024)])
    result = await adapter.embed(request_for(["a"]), caps_for(), SecretStr("sk-test"))
    assert result.space.dimensions == 1024


async def test_the_space_carries_the_served_model_not_the_requested_alias() -> None:
    """Model ids on this surface are aliases with no dated snapshot (finding C3), and the space
    is what names the collection. It is built from what answered, never from what was asked."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0])], model="text-embedding-3-large-2026-01")
    result = await adapter.embed(request_for(["a"]), caps_for(), SecretStr("sk-test"))
    assert result.space.model == "text-embedding-3-large-2026-01"


async def test_a_batch_whose_widths_disagree_is_refused_before_it_reaches_a_collection() -> None:
    """Checking only the first vector would name the space from it and let Qdrant reject the
    rest — mid-run, after the whole embed spend, leaving a partially indexed version."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0, 2.0]), FakeEntry(1, [1.0])])
    with pytest.raises(KbError, match="differing widths"):
        await adapter.embed(request_for(["a", "b"]), caps_for(), SecretStr("sk-test"))


async def test_normalized_reports_what_the_vendor_actually_returned() -> None:
    """`DENSE_DISTANCE` is fixed to "Cosine" and folded into the collection name because every
    embedding API here *documents* normalized vectors. This flag is the only evidence the claim
    holds for the vectors that actually arrived; cosine and dot product coincide only while it
    does."""
    unit, _ = adapter_returning([FakeEntry(0, [0.6, 0.8])])
    assert (await unit.embed(request_for(["a"]), caps_for(), SecretStr("k"))).normalized is True

    scaled, _ = adapter_returning([FakeEntry(0, [3.0, 4.0])])
    assert (await scaled.embed(request_for(["a"]), caps_for(), SecretStr("k"))).normalized is False


# ── usage: nothing to subtract on this endpoint ───────────────────────────────


async def test_usage_is_prompt_tokens_with_no_cached_bucket_subtracted() -> None:
    """`_usage` on the chat surface subtracts `cached_tokens` because there the cached amount is
    a SUBSET of the input. This endpoint reports no cached amount at all, so sharing that code
    would subtract zero today and start subtracting the wrong thing the day OpenAI adds one."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0])], usage=FakeUsage(4096))
    usage = (await adapter.embed(request_for(["a"]), caps_for(), SecretStr("k"))).usage
    assert usage.input_tokens == 4096
    assert usage.total_input_tokens == 4096
    assert (usage.cache_read_tokens, usage.output_tokens, usage.reasoning_tokens) == (0, 0, 0)
    assert usage.source == "provider_final"


async def test_an_unreadable_usage_block_degrades_attribution_and_never_the_bill() -> None:
    """`estimated` rows are never aggregated into invoiced cost. Inventing a number here would
    be worse than reporting none: it would be indistinguishable from a measurement."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0])], usage=FakeUsage(0))
    adapter_no_usage, _ = adapter_returning([FakeEntry(0, [1.0])])
    adapter_no_usage._client(SecretStr("k"))._raw._response.usage = None
    result = await adapter_no_usage.embed(request_for(["a"]), caps_for(), SecretStr("k"))
    assert result.usage.source == "estimated"
    assert result.usage.input_tokens == 0
    assert (await adapter.embed(request_for(["a"]), caps_for(), SecretStr("k"))).usage.source == (
        "provider_final"
    )


# ── the request body: what is never sent is the point ─────────────────────────


async def test_no_truncation_option_is_ever_sent() -> None:
    """`PROVIDER_TRUNCATION_POLICY = "reject"`. We want the vendor's 400: a request that
    silently fits is a chunk indexed from its head with its tail unsearchable forever, reported
    as success."""
    adapter, client = adapter_returning([FakeEntry(0, [1.0])])
    await adapter.embed(request_for(["a"]), caps_for(), SecretStr("k"))
    sent = client.calls[0]
    assert not {"truncate", "truncation"} & sent.keys()
    assert sent["input"] == ["a"]


async def test_input_type_is_not_sent_but_is_reported_as_ignored() -> None:
    """OpenAI's models are symmetric and take no query/passage discriminator. The field is
    reported rather than dropped because it IS honoured on NVIDIA NIM, where a wrong value costs
    recall with nothing raised — a silent ignore here would read as a portable field."""
    adapter, client = adapter_returning([FakeEntry(0, [1.0])])
    result = await adapter.embed(request_for(["a"]), caps_for(), SecretStr("k"))
    assert "input_type" not in client.calls[0]
    assert [w.option for w in result.diagnostics.warnings] == ["input_type"]
    assert result.diagnostics.warnings[0].action == "ignored"


async def test_input_type_is_a_warning_even_under_reject() -> None:
    """The one place `on_unsupported` is deliberately not obeyed. `input_type` has no default
    and is required — precisely so no caller can omit it and be silently right on two vendors
    and wrong on a third — so rejecting it would refuse EVERY call to a symmetric vendor."""
    adapter, _ = adapter_returning([FakeEntry(0, [1.0])])
    result = await adapter.embed(
        request_for(["a"]), caps_for(on_unsupported="reject"), SecretStr("k")
    )
    assert result.vectors == [[1.0]]


async def test_dimensions_is_sent_only_when_the_row_declares_the_capability() -> None:
    adapter, client = adapter_returning([FakeEntry(0, [0.1] * 256)])
    await adapter.embed(
        request_for(["a"], dimensions=256),
        caps_for(supported=frozenset({Capability.EMBEDDING, Capability.EMBEDDING_DIMENSIONS})),
        SecretStr("k"),
    )
    assert client.calls[0]["dimensions"] == 256


def test_dimensions_without_the_capability_is_refused_under_reject() -> None:
    """An IGNORED `dimensions` produces the native width, which the collection cannot hold and
    which is discovered at upsert after the whole embed spend. An HONOURED one produces a second
    EmbeddingSpace under the same model id, which upserts cleanly and is meaningless."""
    adapter = OpenAIAdapter()
    with pytest.raises(KbError, match="EMBEDDING_DIMENSIONS"):
        adapter.validate_embedding(request_for(["a"], dimensions=256), caps_for())


def test_dimensions_without_the_capability_is_a_warning_under_warn() -> None:
    adapter = OpenAIAdapter()
    warnings = adapter.validate_embedding(
        request_for(["a"], dimensions=256), caps_for(on_unsupported="warn")
    )
    assert {w.option for w in warnings} == {"dimensions", "input_type"}


def test_a_blank_input_is_named_by_position_rather_than_refused_as_a_batch() -> None:
    """An empty chunk has no embedding and no lexical vector either. It is a chunker defect and
    has to surface as one, not as a vendor 400 on a batch of sixty-four."""
    adapter = OpenAIAdapter()
    with pytest.raises(KbError, match=r"texts\[1\] is empty"):
        adapter.validate_embedding(request_for(["real", "   "]), caps_for())


def test_the_vendor_input_ceiling_is_a_backstop_with_a_sentence() -> None:
    adapter = OpenAIAdapter()
    with pytest.raises(KbError, match="is not batching at all"):
        adapter.validate_embedding(request_for(["x"] * (MAX_EMBEDDING_INPUTS + 1)), caps_for())


# ── classification: the vendor code first, the status only as a tiebreak ──────


class FakeStatusError(Exception):
    def __init__(self, status: int, code: str | None) -> None:
        super().__init__("vendor message that must never be relayed")
        self.status_code = status
        self.body = {"error": {"code": code}} if code else {}
        self.request_id = "req_err"


@pytest.mark.parametrize(
    ("status", "code", "expected"),
    [
        (429, "rate_limit_exceeded", ErrorClass.PROVIDER_RATE_LIMIT),
        (429, "insufficient_quota", ErrorClass.PROVIDER_BILLING),
        (401, None, ErrorClass.PROVIDER_AUTH),
        (403, None, ErrorClass.PROVIDER_AUTH),
        (500, "server_error", ErrorClass.PROVIDER_TEMPORARY),
        (503, None, ErrorClass.PROVIDER_TEMPORARY),
        (400, "context_length_exceeded", ErrorClass.PROVIDER_PERMANENT_REQUEST),
        (404, "model_not_found", ErrorClass.PROVIDER_PERMANENT_REQUEST),
        (418, "a_code_that_does_not_exist_yet", ErrorClass.PROVIDER_PERMANENT_REQUEST),
    ],
)
def test_the_taxonomy_row_comes_from_the_code_before_the_status(
    status: int, code: str | None, expected: ErrorClass
) -> None:
    """**The 429 row is the one that costs money.** Both values arrive as `RateLimitError`, so a
    bare status read treats an exhausted account as a transient limit: full backoff ladder, then
    a silent fallback onto a second account, and no page.

    The last row is the unmapped case, and it lands on permanent deliberately — unknown is
    permanent, never temporary, because a temporary default retries a request that will never
    succeed and hides that the vendor added a code.
    """
    failure = OpenAIAdapter().classify(FakeStatusError(status, code), tokens_emitted=0)
    assert failure.error_class is expected
    assert failure.native_code == code
    assert failure.provider_request_id == "req_err"


def test_the_vendors_message_never_reaches_the_raised_error() -> None:
    """401 and 422 bodies routinely echo the request, and on this service the request carries
    the tenant's chunk text or the packed prompt. What crosses is the closed-vocabulary code."""
    failure = OpenAIAdapter().classify(FakeStatusError(401, None), tokens_emitted=0)
    assert "vendor message" not in str(failure)


def test_a_transport_failure_with_no_body_is_temporary() -> None:
    """No body, so no code — the absence IS the evidence, and it is always temporary."""
    import httpx
    import openai

    failure = OpenAIAdapter().classify(
        openai.APIConnectionError(request=httpx.Request("POST", "https://api.openai.com")),
        tokens_emitted=0,
    )
    assert failure.error_class is ErrorClass.PROVIDER_TEMPORARY


def test_tokens_emitted_is_zero_from_the_embedding_surface() -> None:
    """There is no stream here and therefore no point past which a retry re-bills a partial
    completion, which is why an embedding call may retry the SAME connection under RETRYABLE
    while a mid-stream chat failure may not."""
    failure = OpenAIAdapter().classify(FakeStatusError(503, None), tokens_emitted=0)
    assert failure.tokens_emitted == 0


# ── the seam itself ───────────────────────────────────────────────────────────


def test_the_sdk_client_is_built_with_retries_disabled() -> None:
    """The SDK retries twice by default, invisibly, inside one `await`, so the span records one
    call; the adapter policy loop retries twice; the browser used to retry three times. Left
    alone that is 27 billed calls from one click."""
    client = OpenAIAdapter()._client(SecretStr("sk-test"))
    assert client.max_retries == 0


def test_the_chat_half_is_still_a_skeleton_and_says_so() -> None:
    """Not an oversight. The 2026-08-10 line puts the five wire adapters out of scope; the
    embedding arm crossed it only because `run_version` cannot index a chunk without one. This
    test is what makes the remaining scope visible rather than a surprise at the first chat
    request."""
    adapter = OpenAIAdapter()
    with pytest.raises(NotImplementedError):
        adapter._usage(object())
    with pytest.raises(NotImplementedError):
        adapter._stop(object())

"""``POST /internal/v1/chat/stream`` — the twenty-stage pipeline, on the wire, as nine frames.

This module is the *seam*, not the pipeline. ``app/rag/runner.py`` owns the stages and their
order; this file turns one signed internal request into one ``PipelineRequest``, binds the
things the runner refuses to import, and turns what comes back into Server-Sent Events. Nothing
here reorders, skips, or short-circuits a stage.

═══ THE SSE WIRING, AND THE ONE TRAP THAT MAKES IT LOOK FINE WHILE BEING WRONG ═════════════

``response_class=EventSourceResponse`` **and** an async-generator handler. Both, together.
``fastapi/routing.py:400`` computes ``is_sse_stream`` from the *declared* ``response_class``,
and ``routing.py:1072`` ANDs it with the handler being a generator. Only when both hold does
FastAPI frame each yielded item, insert ``: ping`` after 15 s of generator idleness, and — the
part that actually matters — insert an ``await anyio.sleep(0)`` checkpoint after every item so
a client hangup can be delivered as a cancellation at all.

Return ``EventSourceResponse(agen)`` the ordinary way instead and ``is_sse_stream`` is False:
you get a plain ``StreamingResponse`` carrying the right ``Content-Type`` and **no framing, no
ping and no checkpoints**. Nothing raises, the endpoint "works" in a test that reads the whole
body, and the failure is a stream that never notices the client left. ``ServerSentEvent``
imports from ``fastapi.sse`` — not ``fastapi.responses`` — and a plain object yielded instead of
one is wrapped as ``data:`` with no ``event:`` line, so every *named* frame must be a
``ServerSentEvent(event=…, raw_data=…)``.

═══ WHY THE PIPELINE RUNS IN A TASK AND THE FRAMES COME BACK THROUGH A QUEUE ═══════════════

``run_pipeline`` is a coroutine that returns a value; it streams through an ``on_delta`` sink
rather than by being an async generator, because the evaluation harness runs the same pipeline
with no client attached. So the handler starts it as a task and consumes an ``asyncio.Queue``
that both ``on_delta`` and the provider router's ``record`` sink feed.

An ``asyncio.Task`` and a plain ``Queue`` rather than an anyio task group, deliberately:
yielding from inside a cancel scope in an async generator is the PEP 789 hazard FastAPI's own
SSE path goes out of its way to avoid (it enters its producer context manager on the
request-scoped ``AsyncExitStack`` for exactly that reason). A task plus a queue has no scope to
exit in the wrong task.

The queue is unbounded, which costs backpressure and buys two things worth more: ``AttemptSink``
is a **synchronous** callable that must not block and must not raise, so it needs
``put_nowait`` on a queue that cannot be full; and the whole answer is a few kilobytes, while
FastAPI's own ``max_buffer_size=1`` downstream still paces the socket.

═══ CITATIONS BEFORE TOKEN 1, ON ALL THREE PATHS (NON-NEGOTIABLE 8) ════════════════════════

Labels are assigned in ``pack()`` at stage 13 and recorded on the trace at the moment of
assignment — before the prompt is built and therefore before any provider call. This endpoint
never parses a label out of model output; ``validate_answer_citations`` strips any the model
invents.

The ``citations`` frame is emitted **lazily, on the first delta**, from state the turn has
already accumulated. That placement is what makes it uniform: a grounded answer, a refusal and a
greeting all produce their first delta at stage 16, and all three therefore emit the frame
before their first token. Emitting it from the ``generate`` closure instead would skip the
refusal and the greeting entirely, because those are produced by the runner itself and never
call a provider — and a client that only sees the frame on the answering path cannot clear the
previous turn's sources panel.

The three things a ``Citation`` needs beyond the label map are collected on the way past, by
wrapping two injected dependencies rather than by re-reading anything:

* ``source_id`` / ``source_version_id`` / ``title`` / ``url`` come from :class:`ChunkSource`,
  which is this module's widening of the runner's ``PassageSource``: the same one tenant-scoped
  hydration that stage 11 and stage 13 already make, returning the columns a citation needs
  instead of only the text. A second fetch addressed by chunk id alone would have no tenant
  filter of its own, which is the one thing a citation must never need.
* ``score`` comes from the reranker wrapper, aligned by passage text. ``RerankRequest.passages``
  is built from the hydration this module performed, so the reverse map is exact; two chunks
  cannot share a passage string, because stage 10 drops exact duplicates on ``content_hash``.

═══ WHAT THIS ENDPOINT DOES NOT WRITE ══════════════════════════════════════════════════════

**No ``retrieval_traces`` row and no ``provider_calls`` row.** Both names are Laravel's and
neither is in ``app/db/writes.py::ALLOWED_TABLES`` — ``retrieval_traces`` came off it under
ADR-033 property 2, because the public API reads it, and a data-plane write would land beside
Laravel's own writer with no policy, no audit row and no framework-applied tenant scope. This
service **emits**: one ``retrieval.trace`` frame and one ``provider.usage`` frame per attempt
that made a request. Laravel's stream finalizer persists both in the same transaction as the
message row.

═══ THE ``record`` SINK IS NOT OPTIONAL ════════════════════════════════════════════════════

``ChatResult`` deliberately cannot say which connection produced it, so a caller reading only
the terminal event cannot tell a fallback answer from a primary one — the cost of falling back
would be invisible. ``FallbackRouter.stream`` therefore takes ``record`` as a required argument
and emits one :class:`ProviderAttempt` per attempt; this module turns those into
``provider.fallback`` and ``provider.usage`` frames. One turn that falls back once is two
``provider.usage`` frames, with different ``connection_id`` and ``model``.

═══ RETRIEVED CONTENT IS DATA (NON-NEGOTIABLE 7) ═══════════════════════════════════════════

``build_prompt`` puts every evidence block inside one nonce-fenced region, after every
instruction section, and there is no parameter on it that would let source text reach an
instruction section. This module carries that through by sending ``system=prompt.system`` (the
platform and bot sections only) and the whole fenced region inside a single user message, with
``context_blocks=[]``: the provider contract's own ``ContextBlock`` list is the alternative
delivery for evidence, and using both would put every passage in the request twice.
"""

from __future__ import annotations

import asyncio
import contextlib
import dataclasses
import json
import logging
import re
import time
from collections.abc import AsyncIterator, Mapping, Sequence
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Annotated, Any, Final, Protocol

from fastapi import APIRouter, Depends, Request
from fastapi.responses import EventSourceResponse
from fastapi.sse import ServerSentEvent
from opentelemetry import trace
from pydantic import SecretStr
from qdrant_client import models

from app.api.deps import Deadline, RequestContext, deadline, request_context, verify_hmac
from app.contracts.internal.chat import (
    ChatExecuteRequest,
    Citation,
    Citations,
    Event,
    FinishReason,
    MessageComplete,
    MessageStart,
    ProviderFallback,
    ProviderUsage,
    RetrievalTraceFrame,
    Status,
    StreamError,
    Token,
    Usage,
    assert_scope_agrees,
    credential_for,
    sse_data,
)
from app.core.errors import ErrorClass, KbError, Origin
from app.providers.contract import (
    ChatRequest,
    ChatResult,
    Delta,
    EmbeddingInputType,
    EmbeddingRequest,
    Message,
    ReasoningOption,
    RerankRequest,
    RerankResult,
    StopReason,
)
from app.providers.contract import (
    StreamEvent as ProviderStreamEvent,
)
from app.providers.router import (
    AttemptSink,
    ChainLink,
    FallbackRouter,
    NullBreaker,
    ProviderAttempt,
)
from app.rag.citations import CITATION_LABEL_PATTERN
from app.rag.evidence import BranchAgreementUnavailable
from app.rag.packing import PassageSource
from app.rag.prompt import BuiltPrompt
from app.rag.rerank import (
    Reranker,
    RerankNotCalibrated,
    RerankScaleMismatch,
    calibration_for,
)
from app.rag.runner import (
    BranchSearch,
    EmbedQuery,
    Generate,
    GenerationMode,
    PipelineDeps,
    PipelineRequest,
    PipelineResult,
    QueryVectors,
    RerankCapability,
    RetrievalConfig,
    TraceRun,
    run_pipeline,
)
from app.retrieval.collection import SPARSE_ANALYZER_VERSION, space_from_identity
from app.retrieval.search import BranchName, retrieve_branches
from app.retrieval.sparse import (
    CorpusStatisticsStore,
    EmptySparseQuery,
    SparseAnalyzerMismatch,
    SparseStatisticsUnavailable,
    encode_query,
    term_frequencies,
    tokenize,
)
from app.retrieval.statistics import PostgresCorpusStatisticsStore
from app.retrieval.tenancy import EmptyScopeError, TenantContext

logger = logging.getLogger(__name__)

__all__ = ["ChatDependencies", "ChunkRow", "ChunkSource", "chat_dependencies", "router"]

_TRACER: Final = trace.get_tracer("app.api.internal.v1.chat")

#: ``dependencies=`` on the ROUTER, never on the individual path operation, so an endpoint added
#: to this module later cannot forget signature verification (`fastapi-service`). ``X-KB-Org-Id``
#: is only trustworthy because it is signed, and the tenant filter is built from it.
router = APIRouter(prefix="/internal/v1", tags=["chat"], dependencies=[Depends(verify_hmac)])


def _estimate_tokens(text: str) -> int:
    """The token estimator handed to the packer and the prompt builder.

    **It must OVER-estimate.** There is no local model and therefore no authoritative tokenizer
    (ADR-030); the vendor tokenizes with its own algorithm over its own vocabulary and the
    divergence is largest on non-Latin scripts, exactly where a silent trim would be least
    noticed. An over-estimate costs one chunk of context; an under-estimate costs the end of the
    answer, because recent provider APIs accept an over-budget request and truncate rather than
    rejecting it.

    Four characters per token is the conventional English ratio; dividing by three buys the
    margin. Restated rather than imported from ``app/ingestion/runner.py::_measure`` — that one
    is private to a package this one must not depend on — and
    ``tests/unit/test_internal_chat_contract.py`` pins the two to the same arithmetic.
    """
    return max(1, (len(text) + 2) // 3)


# ── hydration: the runner's PassageSource, widened to what a citation needs ────


@dataclass(frozen=True, slots=True)
class ChunkRow:
    """One hydrated chunk: the exact embedded string, plus what its citation renders.

    ``title`` is the chunk's **heading path**, not the source's title, and that is a gap rather
    than a design — see :func:`_citation_title`.
    """

    chunk_id: str
    #: The exact string that was embedded, heading prefix included. The reranker must score the
    #: string that is in the index; scoring the raw body judges a document that does not exist.
    text: str
    source_id: str
    source_version_id: str
    title: str
    url: str | None = None


class ChunkSource(Protocol):
    """Hydrate chunk bodies and citation metadata. Injected, tenant-scoped, and batched.

    One call for many ids rather than one per chunk: this runs inside the 1.5 s retrieval leg,
    beside a ranking round trip that is already there. The implementation **must** scope its
    read to the organization and to the resolved active-version set — a fetch keyed on chunk
    ids alone is a cross-tenant read that reviews as one harmless line.
    """

    async def __call__(self, chunk_ids: Sequence[str], /) -> Sequence[ChunkRow]: ...


def _citation_title(row: ChunkRow) -> str:
    """The ``citations[].title`` a client renders.

    **This is the one field on the frame with no true producer, and it is reported rather than
    invented.** ``kb-internal-api-contracts`` promises a "display title, always present" and its
    example reads ``"Refund policy"``. The Qdrant payload carries no source title —
    ``PAYLOAD_PROJECTION`` has ``heading_path`` and the per-format locators and nothing else —
    and the relational ``chunks`` row does not either; ``knowledge_sources.title`` is Laravel's.
    So this returns the chunk's heading path, which is the closest true statement the index can
    make about where the sentence came from, and falls back to the URL and then to a fixed
    string rather than to a ULID nobody can read.

    Two ways to close it properly, neither of which is this module's to make: ingestion adds a
    denormalized source title to the chunk payload and it joins ``PAYLOAD_PROJECTION``, or
    Laravel enriches the frame at relay from the table it already owns.
    """
    if row.title:
        return row.title
    if row.url:
        return row.url
    return "Untitled source"


# ── the per-turn state ────────────────────────────────────────────────────────


class _Frames:
    """The one-way channel from inside the pipeline to the SSE generator.

    ``put`` never blocks and never raises, because ``AttemptSink`` promises both and an
    exception raised there loses the turn.
    """

    __slots__ = ("_queue",)

    def __init__(self) -> None:
        self._queue: asyncio.Queue[Event | None] = asyncio.Queue()

    def put(self, event: Event) -> None:
        self._queue.put_nowait(event)

    def close(self) -> None:
        self._queue.put_nowait(None)

    async def drain(self) -> AsyncIterator[Event]:
        while True:
            item = await self._queue.get()
            if item is None:
                return
            yield item


#: A partial bracketed label: ``[``, ``[S``, ``[S1`` … anything that could still close into a
#: citation identifier. Anchored, so a ``[`` followed by anything else is released immediately
#: and an ordinary bracket in prose costs no delay at all.
_PARTIAL_LABEL: Final = re.compile(r"\[S?\d*\Z")


def _split_at_possible_label(buffered: str) -> tuple[str, str]:
    """Split buffered text into what is safe to send now and what must wait for more.

    Returns ``(emit, hold)``. ``hold`` is empty unless the buffer ends mid-identifier, so the
    common case sends everything and the worst case delays a handful of characters.
    """
    match = _PARTIAL_LABEL.search(buffered)
    if match is None:
        return buffered, ""
    return buffered[: match.start()], buffered[match.start() :]


@dataclass
class _Turn:
    """Everything one request accumulates on the way through, and the wrappers that collect it.

    It exists because three facts a ``citations`` frame needs are produced at three different
    stages and none of them is on ``PipelineResult`` **before** generation starts. Collecting
    them by wrapping the injected dependencies is what keeps the runner's own signatures free of
    a citation-shaped parameter that would only ever be used by this endpoint.
    """

    run: TraceRun
    frames: _Frames
    citations_enabled: bool

    #: chunk id -> the row hydration returned. A superset of the packed set.
    chunks: dict[str, ChunkRow] = field(default_factory=dict)
    #: the exact embedded passage string -> chunk id, for aligning rerank scores back.
    by_text: dict[str, str] = field(default_factory=dict)
    #: chunk id -> the rerank score the provider returned, on the provider's own scale.
    scores: dict[str, float] = field(default_factory=dict)
    #: One ``provider.fallback``/``provider.usage`` pair per attempt lands here as well as on
    #: the wire, so the terminal frame can be built from the attempt that actually answered.
    attempts: list[ProviderAttempt] = field(default_factory=list)
    #: Text held back because it might be the start of a bracketed label. See :meth:`on_delta`.
    _pending: str = ""
    _citations_emitted: bool = False
    _reranked: bool = False

    # ── the wrappers ──────────────────────────────────────────────────────────

    def hydrate(self, source: ChunkSource) -> PassageSource:
        """Adapt a :class:`ChunkSource` to the runner's ``PassageSource``, keeping the metadata.

        The runner memoizes this, so stage 11 and stage 13 share one round trip; what this
        wrapper adds is that the columns a citation needs survive the trip instead of being
        thrown away and re-fetched later without a tenant filter.
        """

        async def hydrate(chunk_ids: Sequence[str], /) -> Mapping[str, str]:
            rows = await source(chunk_ids)
            for row in rows:
                self.chunks[row.chunk_id] = row
                self.by_text[row.text] = row.chunk_id
            return {row.chunk_id: row.text for row in rows}

        return hydrate

    def rerank(self, reranker: Reranker | None) -> Reranker | None:
        """Wrap the reranker so stage 11 announces itself and its scores are kept.

        ``status: reranking`` is emitted from **inside** the wrapper rather than before the
        pipeline starts, so the frame appears exactly when a provider is actually asked to rank.
        A bot whose provider cannot rank never emits it, which is the only place the degraded
        mode is visible to an end user, and a frame emitted speculatively would report a stage
        that did not run.
        """
        if reranker is None:
            return None
        turn = self
        bound = reranker

        class _Recording:
            name = bound.name

            async def rerank(self, req: RerankRequest) -> RerankResult:
                if not turn._reranked:
                    turn._reranked = True
                    turn.frames.put(Status(stage="reranking"))
                result = await bound.rerank(req)
                for passage, score in zip(req.passages, result.scores, strict=False):
                    chunk_id = turn.by_text.get(passage)
                    if chunk_id is not None:
                        turn.scores[chunk_id] = score
                return result

        return _Recording()

    # ── the frames it produces ────────────────────────────────────────────────

    def citation_frame(self) -> Citations:
        """Build ``citations`` from the label map fixed at stage 13. Never from model output.

        ``run.labels`` is ``{label: chunk_id}`` in packed order — the order the model reads the
        evidence in, which is not reranked order, because the packer places rank 1 first and
        rank 2 last. The index is the label's own position, so a client's footnote marker and
        the identifier in the prose are the same number by construction.
        """
        rows: list[Citation] = []
        if not self.citations_enabled:
            return Citations(citations=rows)
        for position, (label, chunk_id) in enumerate(self.run.labels.items(), start=1):
            chunk = self.chunks.get(chunk_id)
            if chunk is None:
                # Unreachable through the packer, which only labels what it hydrated. Skipped
                # rather than filled with placeholders: a citation naming a chunk this turn
                # never read is the failure the whole label mechanism exists to prevent.
                logger.warning(
                    "citation label has no hydrated chunk",
                    extra={"kb.citation.label": label, "kb.chunk_id": chunk_id},
                )
                continue
            rows.append(
                Citation(
                    index=position,
                    source_id=chunk.source_id,
                    source_version_id=chunk.source_version_id,
                    chunk_id=chunk_id,
                    title=_citation_title(chunk),
                    url=chunk.url,
                    # None when stage 11 was skipped. Not zero: a zero is a score on some
                    # scale, and every scale here produces plausible floats.
                    score=self.scores.get(chunk_id),
                )
            )
        return Citations(citations=rows)

    def on_delta(self, text: str) -> None:
        """The runner's streaming sink. Emits ``citations`` once, then filtered token frames.

        **Stage 18 cannot un-send a token, so the filter has to run here as well.** The runner
        strips fabricated labels from the answer it *returns* — the string Laravel persists —
        but the deltas reached the client before stage 18 existed, so a model that wrote
        ``[S99]`` put it on the wire and the transcript and the wire then disagreed: the reader
        saw a marker that resolves to nothing while the stored answer was clean. Filtering here
        makes the two the same string, and the runner's own pass stays as the authority on what
        counts as unknown (it also records ``unknown_citation_label`` on the trace).

        The hold-back is what makes it correct across delta boundaries: a label can arrive as
        ``[S`` then ``99]``, and a stripper that only looked at one delta would never see a
        whole one. Text is held from the last ``[`` **only while what follows it could still
        become a label**, so the delay is at most a few characters and never a whole sentence.
        """
        if not self._citations_emitted:
            self._citations_emitted = True
            self.frames.put(self.citation_frame())
            self.frames.put(Status(stage="generating"))
        self._pending += text
        emit, self._pending = _split_at_possible_label(self._pending)
        if emit:
            self.frames.put(Token.model_construct(text=self._known_labels_only(emit)))

    def flush(self) -> None:
        """Emit whatever is still held back. Called once, after the pipeline has finished.

        Without it the last few characters of an answer ending in ``[`` are never sent, which
        is a truncation nothing reports.
        """
        if self._pending:
            self.frames.put(Token.model_construct(text=self._known_labels_only(self._pending)))
            self._pending = ""

    def _known_labels_only(self, text: str) -> str:
        """Remove complete bracketed identifiers that were never packed.

        The valid set is ``run.labels``, fixed at stage 13 before any provider call, so this
        never consults the answer to decide what a citation is. Removed rather than rewritten:
        renumbering would attach the model's sentence to a passage it did not read.
        """
        if not self.run.labels:
            return CITATION_LABEL_PATTERN.sub("", text)
        known = set(self.run.labels)
        return CITATION_LABEL_PATTERN.sub(
            lambda match: match.group(0) if f"S{match.group(1)}" in known else "", text
        )

    def record(self, attempt: ProviderAttempt) -> None:
        """The provider router's ``AttemptSink``. Must not raise and must not block.

        A same-connection **retry** produces no ``provider.fallback`` frame: a retry is not a
        fallback and counting it as one reports a model change that never happened.
        """
        self.attempts.append(attempt)
        if attempt.is_fallback and attempt.fell_back_from is not None:
            self.frames.put(
                ProviderFallback(
                    ordinal=attempt.ordinal,
                    from_connection_id=attempt.fell_back_from,
                    to_connection_id=attempt.connection_id,
                    provider=attempt.provider,
                    model=attempt.model,
                    trigger=attempt.fallback_trigger or "",
                )
            )
        if not attempt.made_request:
            # A skipped or rejected attempt sent nothing. A `provider_calls` row for a call
            # that never happened puts a zero-token, zero-cost row into billing
            # reconciliation and makes the fallback ladder look cheaper than it is.
            return
        usage = attempt.usage
        self.frames.put(
            ProviderUsage(
                ordinal=attempt.ordinal,
                connection_id=attempt.connection_id,
                provider=attempt.provider,
                model=attempt.model,
                outcome=attempt.outcome.value,
                error_class=attempt.error_class,
                stop_reason=attempt.stop_reason.value if attempt.stop_reason else None,
                provider_request_id=attempt.provider_request_id,
                input_tokens=usage.input_tokens,
                output_tokens=usage.output_tokens,
                cached_tokens=usage.cache_read_tokens + usage.cache_write_tokens,
                cache_read_tokens=usage.cache_read_tokens,
                cache_write_tokens=usage.cache_write_tokens,
                reasoning_tokens=usage.reasoning_tokens,
                latency_ms=attempt.latency_ms,
                first_token_ms=attempt.first_token_ms,
            )
        )


# ── the injected dependencies, and the seam a test replaces ───────────────────


@dataclass(frozen=True, slots=True)
class ChatDependencies:
    """Everything the pipeline calls out to that this endpoint has to resolve.

    Deliberately **not** ``PipelineDeps``: the endpoint wraps ``chunks`` and ``reranker`` before
    building that, so the citation material is collected whichever assembler produced these. A
    test double therefore gets ``citations`` frames for free rather than having to reimplement
    the collection.
    """

    embed_query: EmbedQuery
    search: BranchSearch
    chunks: ChunkSource
    generate: Generate
    rewriter: Any | None = None
    reranker: Reranker | None = None
    rerank_capability: RerankCapability | None = None
    provider_connection_id: str = ""


class Assembler(Protocol):
    """Resolve :class:`ChatDependencies` for one turn."""

    async def __call__(
        self,
        body: ChatExecuteRequest,
        ctx: RequestContext,
        expiry: Deadline,
        *,
        record: AttemptSink,
    ) -> ChatDependencies: ...


async def chat_dependencies(request: Request) -> Assembler:
    """The seam a contract test replaces through ``app.dependency_overrides``.

    A dependency rather than a module global so the lifespan-owned clients are reached through
    ``request.state`` and nothing is constructed at import time or per call — a client built in
    a dependency means a fresh TLS handshake on every provider call, straight onto the 4 s
    first-token budget, and one built at import binds to whichever event loop happened to run
    first (`fastapi-service`).

    Faking the assembler is how the suite exercises this endpoint without a provider. **Never
    add a local embedding or reranking model to make a test run** (ADR-030): the callable is the
    thing to fake.
    """
    state = request.state

    async def assemble(
        body: ChatExecuteRequest,
        ctx: RequestContext,
        expiry: Deadline,
        *,
        record: AttemptSink,
    ) -> ChatDependencies:
        return await _resolve_dependencies(body, ctx, expiry, state=state, record=record)

    return assemble


#: Client-side ceiling on the corpus-statistics read, in seconds. **Not the budget** — the
#: budget is whatever is left of the caller's deadline, and this is the backstop that keeps a
#: wedged database from holding the retrieval leg until that deadline. Two indexed reads on a
#: primary-key prefix; anything near this number is a sick server rather than a big scope.
SPARSE_STATISTICS_CEILING_SECONDS: Final[float] = 0.5


async def _lexical_query_vector(
    text: str,
    ctx: TenantContext,
    allowed_version_ids: Sequence[str],
    store: CorpusStatisticsStore,
    /,
    *,
    remaining_seconds: float,
) -> models.SparseVector | None:
    """Stage 8's query vector, or ``None`` for a legitimate dense-only run.

    ``ctx`` and ``allowed_version_ids`` are **positional and required**, the same discipline
    ``retrieve_branches`` and ``tenant_filter`` hold: ``CorpusStatistics.for_scope`` checks the
    statistics against exactly these two, and a signature that let either be omitted would admit
    another scope's document frequencies — which is the one tenancy control on this branch that
    the payload filter does not already provide.

    **The three failure shapes are decided here, explicitly, and they are not the same thing.**

    * ``EmptySparseQuery`` — the query analyzed to no terms (an emoji, punctuation, a script the
      analyzer emits nothing for). That is a **dense-only run and not an error**: it is returned
      as ``None`` so ``retrieve_branches`` is never asked for a lexical branch, and the runner
      records stage 8 as ``branch_not_run``. The distinction is load-bearing — "the lexical
      branch ran and matched nothing" is a very strong signal that the degraded path reads as
      disagreement, and "there was nothing to ask" is no signal at all. It is decided **before**
      any I/O, from the analysis alone, so the empty case costs no round trip.
    * ``SparseStatisticsUnavailable`` — the document frequencies could not be read, including
      the deadline running out. An **error**, never a degradation: the tempting fallback is
      uniform IDF, which produces a complete, plausible ranking in which common words weigh as
      much as part numbers, forever, with nothing in the trace to say so.
    * ``SparseAnalyzerMismatch`` — the statistics were rolled up under a different analyzer, so
      the term ids are a different space entirely. An error and **not retryable**: it is a
      version skew, and retrying reproduces it exactly.

    The text is analyzed twice — once here for the term ids the statistics read has to bind, and
    once inside ``encode_query``. That is the shape ``encode_query`` asks for: it takes the
    statistics rather than fetching them, "so the caller can put a deadline on it". ``tokenize``
    is pure and the question is capped at a few thousand characters, so the second pass is
    microseconds; re-implementing the query side here to save it would be a second copy of the
    BM25 arithmetic and a second thing to keep in step with the analyzer.
    """
    frequencies = term_frequencies(tokenize(text))
    if not frequencies:
        return None

    budget = min(remaining_seconds, SPARSE_STATISTICS_CEILING_SECONDS)
    if budget <= 0.0:
        raise KbError(
            ErrorClass.RETRIEVAL,
            "the deadline was spent before the lexical branch could read its corpus "
            "statistics; refusing rather than ranking without IDF",
            origin=Origin.SELF,
        )
    try:
        async with asyncio.timeout(budget):
            statistics = await store.load(
                ctx,
                allowed_version_ids,
                sorted(frequencies),
                analyzer=SPARSE_ANALYZER_VERSION,
            )
    except TimeoutError:
        raise KbError(
            ErrorClass.RETRIEVAL,
            f"the corpus-statistics read did not answer within {budget:.2f}s",
        ) from None
    except SparseAnalyzerMismatch as exc:
        # Caught on the LOAD as well as on the encode. `for_scope` is the usual raiser, but a
        # store that knows its own rollup was written under another analyzer may say so first,
        # and an uncaught one here is a 500 for a condition with a name.
        raise KbError(ErrorClass.RETRIEVAL, str(exc), retryable=False) from None
    except SparseStatisticsUnavailable as exc:
        raise KbError(ErrorClass.RETRIEVAL, str(exc)) from None

    try:
        # `ctx` and `allowed_version_ids` positionally again: `encode_query` re-checks the
        # statistics against them through `for_scope`, which is why they are passed rather than
        # trusted from the load above.
        return encode_query(text, ctx, allowed_version_ids, statistics)
    except EmptySparseQuery:
        # Unreachable given the analysis above, and caught rather than assumed away: the two
        # analyses are the same pure function over the same string, and if that ever stops being
        # true the honest outcome is still a dense-only run and not a 500.
        return None
    except SparseAnalyzerMismatch as exc:
        raise KbError(ErrorClass.RETRIEVAL, str(exc), retryable=False) from None
    except SparseStatisticsUnavailable as exc:
        raise KbError(ErrorClass.RETRIEVAL, str(exc)) from None


async def _resolve_dependencies(
    body: ChatExecuteRequest,
    ctx: RequestContext,
    expiry: Deadline,
    *,
    state: Any,
    record: AttemptSink,
) -> ChatDependencies:
    """Bind the real Qdrant client, the real pool and the real adapters to this turn.

    Imported lazily: ``app/providers/registry.py`` calls ``get_settings()`` at import, which
    runs ``check_environment``, and this module is imported by ``app/main.py`` at application
    construction — including under pytest.
    """
    from app.providers.registry import ADAPTERS

    config = body.config
    space = _space_for(config.embedding_model_version)

    # ── stage 7's provider call ───────────────────────────────────────────────
    embedding = config.embedding_connection
    embed_adapter = _adapter(ADAPTERS, embedding.provider)
    if not hasattr(embed_adapter, "embed"):
        # The static gate is the ABSENCE of the method (`kb-provider-adapter-contract`): a
        # vendor that cannot embed does not define one. Reached only if Laravel designated a
        # connection the readiness endpoint would have rejected.
        raise KbError(
            ErrorClass.VALIDATION,
            f"the designated embedding connection is on {embedding.provider}, which publishes "
            "no embedding endpoint. POST /internal/v1/embedding/readiness is the rule that "
            "decides which connection embeds; this request names one it would refuse",
            origin=Origin.SELF,
        )
    embed_credential = credential_for(body, embedding.connection_id, surface="embedding")

    # ── the lifespan-owned clients, resolved once, before anything closes over them ──
    qdrant = getattr(state, "qdrant", None)
    if qdrant is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "the Qdrant client was not built; the application is not ready",
            origin=Origin.SELF,
        )
    pool = getattr(state, "db_pool", None)
    if pool is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "the PostgreSQL pool was not built; the application is not ready",
            origin=Origin.SELF,
        )
    statistics_store = PostgresCorpusStatisticsStore(pool)

    async def embed_query(text: str, /) -> QueryVectors:
        """Both query vectors: the dense one from the provider, the lexical one from BM25.

        ``input_type=QUERY`` and never a default: NVIDIA's models run in passage or query mode
        and their own schema says a wrong value causes large drops in retrieval accuracy, while
        OpenAI's are symmetric — so a default is correct on two vendors, silently wrong on a
        third, and invisible on all three.

        The dense vector must come from the **same** ``EmbeddingSpace`` the passages did, and
        the only authority for which that was is the source version's own row — which is why
        ``space`` is parsed from ``config.embedding_model_version`` and never from this
        service's environment.

        **The two are issued concurrently**, and that is latency rather than tidiness: one is a
        provider round trip and the other a PostgreSQL read, both inside a 1.5 s leg that also
        has two Qdrant queries and possibly a ranking call in it. A ``TaskGroup`` rather than
        ``gather`` so a failure on either side cancels the other instead of leaving it running
        against a request that has already lost.

        ``sparse`` is ``None`` only for a query that analyzed to no terms — a real dense-only
        run, which the runner records as ``branch_not_run`` rather than as an empty lexical
        branch, because an empty branch is a very strong signal and a missing one is no signal.
        """
        # The SAME monotonic value `TraceRun` measures `remaining_seconds()` against — the
        # header was converted once, at the `deadline` dependency. Recomputing from
        # `time.time()` here would make an NTP step mid-request look like an expired
        # deadline, forward or backward.
        remaining = max(0.0, expiry.monotonic_expiry - time.monotonic())

        async def dense() -> Any:
            return await embed_adapter.embed(
                EmbeddingRequest(
                    org_id=ctx.org_id,
                    trace_id=body.message_id,
                    provider_connection_id=embedding.connection_id,
                    model=embedding.model,
                    texts=[text],
                    input_type=EmbeddingInputType.QUERY,
                ),
                embedding.caps,
                embed_credential,
            )

        async def lexical() -> models.SparseVector | None:
            return await _lexical_query_vector(
                text,
                ctx,
                config.allowed_version_ids,
                statistics_store,
                remaining_seconds=remaining,
            )

        async with asyncio.TaskGroup() as group:
            dense_task = group.create_task(dense())
            lexical_task = group.create_task(lexical())

        result = dense_task.result()
        sparse = lexical_task.result()
        # No callback reports the dense-only case. `retrieve_branches` returns only the branches
        # it queried, the runner writes `branch_not_run` into stage 8's fragment, and the
        # handler reads `trace.branches_queried` onto the span — so the record is what actually
        # happened rather than what this closure believed was about to.
        return QueryVectors(
            dense=result.vectors[0],
            sparse=sparse,
            provider=result.space.provider,
            model=result.space.model,
        )

    async def search(
        scope_ctx: TenantContext,
        allowed_version_ids: Sequence[str],
        vectors: QueryVectors,
        /,
        *,
        dense_limit: int,
        sparse_limit: int | None,
    ) -> Mapping[BranchName, Sequence[Any]]:
        """Both branches, under one filter expression, with the scope passed **positionally**.

        ``retrieve_branches`` takes ``ctx`` and ``allowed_version_ids`` positionally and builds
        each branch's filter from them itself; there is deliberately no parameter anywhere that
        accepts a pre-built filter, because such a parameter is a caller that built it for
        another tenant and a signature with nowhere to notice. This closure passes them
        straight through and constructs nothing.
        """
        # `sparse` and `sparse_limit` travel together or not at all: the pairing is checked in
        # `retrieve_branches`, because a vector with no depth reads as depth zero and returns
        # nothing, while a depth with no vector reads in the trace as "sparse was configured"
        # on a run that never queried it. Both look like a lexical arm that stopped working and
        # neither raises. The runner sets `sparse_limit` from `vectors.sparse` for the same
        # reason, so this closure passes both through and decides nothing.
        return await retrieve_branches(
            qdrant,
            scope_ctx,
            allowed_version_ids,
            vectors.dense,
            vectors.sparse,
            space=space,
            dense_limit=dense_limit,
            sparse_limit=sparse_limit,
        )

    # ── stages 11 and 13's hydration ──────────────────────────────────────────
    chunks = _chunk_source(pool, org_id=ctx.org_id, version_ids=config.allowed_version_ids)

    # ── stage 11's capability gate ────────────────────────────────────────────
    reranker, capability = _resolve_reranker(body, ctx, ADAPTERS)

    # ── stages 15-16 ──────────────────────────────────────────────────────────
    chain = _chain(body, ADAPTERS)
    credential_for(body, config.connection.connection_id, surface="chat")
    fallback_router = FallbackRouter(breaker=NullBreaker())

    def generate(prompt: BuiltPrompt, run: TraceRun) -> AsyncIterator[str]:
        return _generate(
            fallback_router,
            prompt,
            body=body,
            ctx=ctx,
            chain=chain,
            deadline_monotonic=run.deadline_monotonic,
            record=record,
        )

    return ChatDependencies(
        embed_query=embed_query,
        search=search,
        chunks=chunks,
        generate=generate,
        # Stage 5's rewrite model has no producer on this wire: `ConfigSnapshot` names no
        # rewrite connection, so the rewrite is config-off rather than absent — `rewrite_query`
        # records the fallback and retrieval runs on the normalized original. Reported, not
        # papered over with the chat model, which would spend a generation call per turn on a
        # stage nobody configured.
        rewriter=None,
        reranker=reranker,
        rerank_capability=capability,
        provider_connection_id=config.connection.connection_id,
    )


def _space_for(embedding_model_version: str) -> Any:
    """Parse the version identity into the collection it names, or refuse.

    ``space_from_identity`` raises ``ValueError`` and imports no error taxonomy, so the
    translation happens here. A default would name a real collection and act on someone else's
    vector space, which is why an unparseable identity is a refusal and never a fallback.
    """
    try:
        return space_from_identity(embedding_model_version)
    except ValueError as exc:
        raise KbError(ErrorClass.VALIDATION, str(exc), origin=Origin.SELF) from None


def _adapter(adapters: Mapping[str, Any], provider: str) -> Any:
    try:
        return adapters[provider]
    except KeyError:
        raise KbError(
            ErrorClass.VALIDATION,
            f"no adapter is registered for provider {provider!r} in this build",
            origin=Origin.SELF,
        ) from None


def _chain(body: ChatExecuteRequest, adapters: Mapping[str, Any]) -> list[ChainLink]:
    """The bot's ordered ``(connection, model)`` chain: the primary, then the fallbacks."""
    links: list[ChainLink] = []
    for connection in (body.config.connection, *body.config.fallback_connections):
        links.append(
            ChainLink(
                connection_id=connection.connection_id,
                model=connection.model,
                adapter=_adapter(adapters, connection.provider),
                caps=connection.caps,
                fallback_on_rate_limit=connection.fallback_on_rate_limit,
            )
        )
    return links


def _resolve_reranker(
    body: ChatExecuteRequest, ctx: RequestContext, adapters: Mapping[str, Any]
) -> tuple[Reranker | None, RerankCapability | None]:
    """Bind stage 11's provider call, or report why there is none. **Never raises upward.**

    The capability is read from ``app/providers/capabilities.py`` and no vendor name is
    restated here: ``can_rerank`` is the AND of all three eligibility axes,
    ``provider_offers`` is axis 1 alone (does the *vendor* publish a ranking route) and
    ``rerank_scale`` is axis 3 alone (can this platform threshold what comes back). The two
    single-axis lookups ride along purely so a ``False`` can be attributed to the cause with
    the right remedy — moving the bot to a provider that ranks is a different action from
    running it without reranking.

    ``calibration_for`` is called **here**, at configuration-resolution time, and not left to
    the runner. It raises for an uncalibrated pair, and a raise on the request path would be an
    unhandled 500 after retrieval has already run. Resolving it early turns it into a traced
    skip with the money unspent, which is what its own docstring asks for.

    **The honest gap:** ``CALIBRATIONS`` is empty on purpose, so a provider that *can* rank and
    whose scale *is* thresholdable still lands in the second refusal — "waiting on an evaluation
    run". ``RerankSkipReason`` is closed at five and has no member for it, so the traced reason
    is ``disabled_by_configuration``, which is the least-wrong of the five. The accurate member
    does not exist and adding one is a contract change the playground and the metrics group on,
    not a label. The refusal message is logged in full so an operator is not left reading a
    reason that under-describes the state.
    """
    from app.providers.capabilities import can_rerank, provider_offers, rerank_scale
    from app.providers.errors import ProviderSurface

    connection = body.config.rerank_connection
    if connection is None:
        return None, None
    rerank_connection = connection

    capability = RerankCapability(
        provider=connection.provider,
        model=connection.model,
        supports=can_rerank(connection.provider, connection.caps),
        publishes_endpoint=provider_offers(connection.provider, ProviderSurface.RERANK),
        scale=rerank_scale(connection.provider),
    )
    if not capability.supports:
        # The gate turns this into the accurate closed reason; there is nothing to bind.
        return None, capability

    try:
        calibration_for(connection.provider, connection.model, provider_scale=capability.scale)
    except (RerankNotCalibrated, RerankScaleMismatch) as exc:
        logger.warning(
            "reranking resolved to no calibration and is skipped for this turn",
            extra={
                "kb.org_id": ctx.org_id,
                "kb.rerank.provider": connection.provider,
                "kb.rerank.model": connection.model,
                "kb.rerank.reason": str(exc),
            },
        )
        return None, None

    adapter = _adapter(adapters, connection.provider)
    credential = credential_for(body, connection.connection_id, surface="rerank")

    class _BoundReranker:
        """``caps`` and ``credential`` bound; the tenant-naming request passed on every call."""

        name: str = rerank_connection.provider

        async def rerank(self, req: RerankRequest) -> RerankResult:
            result: RerankResult = await adapter.rerank(req, rerank_connection.caps, credential)
            return result

    bound: Reranker = _BoundReranker()
    return bound, capability


def _chunk_source(pool: Any, *, org_id: str, version_ids: Sequence[str]) -> ChunkSource:
    """Hydrate from ``chunks``, scoped to the organization **and** to the active-version set.

    Both terms, always. A hydration keyed on chunk ids alone is a cross-tenant read that reviews
    as one harmless line — and the version term is what stops a retired version's text being
    packed beside the version that replaced it, which retrieval already excluded.

    This is a **read**. This service writes no row on the chat path at all.
    """

    async def hydrate(chunk_ids: Sequence[str], /) -> Sequence[ChunkRow]:
        if not chunk_ids:
            return ()
        async with pool.connection() as conn:
            cursor = await conn.execute(
                "SELECT id, text, source_id, source_version_id, heading_path, url "
                "FROM chunks "
                "WHERE organization_id = %s AND source_version_id = ANY(%s) AND id = ANY(%s)",
                (org_id, list(version_ids), list(chunk_ids)),
            )
            rows = await cursor.fetchall()
        return [
            ChunkRow(
                chunk_id=row[0],
                text=row[1],
                source_id=row[2],
                source_version_id=row[3],
                title=" > ".join(str(part) for part in (row[4] or ())),
                url=row[5],
            )
            for row in rows
        ]

    return hydrate


async def _generate(
    fallback_router: FallbackRouter,
    prompt: BuiltPrompt,
    *,
    body: ChatExecuteRequest,
    ctx: RequestContext,
    chain: Sequence[ChainLink],
    deadline_monotonic: float,
    record: AttemptSink,
) -> AsyncIterator[str]:
    """Stages 15-16: walk the chain, yield text deltas, keep the terminal result.

    ``system`` is the platform and bot sections only. The evidence is inside ``prompt.user``,
    in one nonce-fenced region marked as data, after every instruction section — so
    ``context_blocks`` is empty: the provider contract's own block list is an *alternative*
    delivery for evidence, and populating both would put every passage in the request twice.

    ``reasoning`` deltas are dropped from the client stream and are **not** dropped from the
    accounting: ``provider.usage`` carries ``reasoning_tokens`` from the attempt record, because
    a trace nobody renders is still billed.
    """
    request = ChatRequest(
        org_id=ctx.org_id,
        bot_id=ctx.bot_id or "",
        trace_id=body.message_id,
        provider_connection_id=body.config.connection.connection_id,
        model=body.config.connection.model,
        system=prompt.system,
        messages=[Message(role="user", content=prompt.user)],
        context_blocks=[],
        max_output_tokens=body.config.max_output_tokens,
        temperature=body.config.temperature,
        reasoning=(
            None
            if body.config.reasoning_effort.value == "none"
            else ReasoningOption(effort=body.config.reasoning_effort.value)
        ),
        stream=True,
    )
    events: AsyncIterator[ProviderStreamEvent] = fallback_router.stream(
        request,
        chain,
        # The map is passed WHOLE and still masked. The router looks one entry up by
        # connection_id at the call site about to make the request; unwrapping it into a plain
        # dict here is the one line that would strip masking off every tenant key at once.
        body.provider_credentials,
        deadline_monotonic=deadline_monotonic,
        record=record,
    )
    async for event in events:
        if isinstance(event, Delta):
            if event.kind == "text" and event.text:
                yield event.text
        elif isinstance(event, ChatResult) and event.error_class:
            raise KbError(
                ErrorClass(event.error_class),
                event.diagnostics.provider + " could not complete the turn",
            )


# ── the endpoint ──────────────────────────────────────────────────────────────


def _now_rfc3339() -> str:
    """RFC 3339 UTC with a ``Z``, formatted once, here, so no consumer has to agree with a
    serializer. ``isoformat()`` renders ``+00:00``, which is equally valid and is not what the
    wire block says."""
    return datetime.now(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def _frame(event: Event) -> ServerSentEvent:
    """One event as a wire frame: a named ``event:`` line and a ``data:`` line.

    ``raw_data`` and not ``data``: ``data=`` re-serializes through the model and would carry the
    union's discriminator inside the payload, where no client type has a key for it.

    ``wire_name`` and not ``model_dump()["event"]``: this runs once per token, and building a
    whole dict to read one constant is p99 first-token latency spent on nothing.
    """
    return ServerSentEvent(event=event.wire_name, raw_data=sse_data(event))


def _trace_payload(result: PipelineResult) -> dict[str, Any]:
    """``RetrievalTrace`` as a JSON object, with the enums rendered as their wire values."""
    payload: dict[str, Any] = json.loads(json.dumps(dataclasses.asdict(result.trace), default=str))
    return payload


def _finish_reason(result: PipelineResult, attempts: Sequence[ProviderAttempt]) -> FinishReason:
    """The terminal frame's verdict.

    ``insufficient_evidence`` is a **correct** outcome and not an error: nothing cleared stage
    12, the bot said so, and no provider was called. Its metric is not an error rate.
    """
    if result.mode is GenerationMode.REFUSAL:
        return "insufficient_evidence"
    for attempt in reversed(attempts):
        if attempt.stop_reason is StopReason.MAX_OUTPUT:
            return "length"
        if attempt.stop_reason is StopReason.CANCELLED:
            return "cancelled"
    return "stop"


def _client_usage(attempts: Sequence[ProviderAttempt]) -> Usage | None:
    """The client-facing token pair, summed across every attempt that billed.

    ``prompt_tokens`` is ``total_input_tokens`` — uncached input plus cache reads plus cache
    writes — because reading a provider's ``input_tokens`` alone reports a 200k-token cached
    document with a 50-token question as 50 input tokens on Anthropic.
    """
    billed = [attempt for attempt in attempts if attempt.made_request]
    if not billed:
        return None
    return Usage(
        prompt_tokens=sum(a.usage.total_input_tokens for a in billed),
        completion_tokens=sum(a.usage.output_tokens for a in billed),
    )


@router.post(
    "/chat/stream",
    response_class=EventSourceResponse,
    summary="Run the grounded-answer pipeline and stream its normalized events",
    # Set explicitly. FastAPI's default is derived from the handler name and the path, which
    # would bake a local identifier into a shipped `v1` shape and make renaming the function a
    # wire change (`kb-internal-api-contracts`).
    operation_id="chatStream",
)
async def chat_stream(
    body: ChatExecuteRequest,
    ctx: Annotated[RequestContext, Depends(request_context)],
    expiry: Annotated[Deadline, Depends(deadline)],
    assemble: Annotated[Assembler, Depends(chat_dependencies)],
) -> AsyncIterator[ServerSentEvent]:
    """Stream one grounded answer as the nine internal events.

    **Everything runs inside this generator.** Work done in an endpoint body before the response
    is returned sits outside Starlette's disconnect-watching task group, so a client that hangs
    up during an eight-second retrieval leg would not be noticed at all.

    **Exactly one terminal frame on every path** — ``message.complete`` or ``error``, never both
    and never neither. The cancellation terminal is emitted from ``except
    asyncio.CancelledError`` and then re-raised, never from ``finally``: yielding while
    ``GeneratorExit`` unwinds raises ``RuntimeError``, the ASGI layer swallows it, and the only
    symptom is a missing terminal frame for a turn the vendor billed in full.

    **The response has already started by the time anything interesting can fail**, so no
    exception handler can run and no status can change: a mid-stream failure is an ``error``
    frame or it is a dropped connection. Body-shape failures still become a 422 envelope,
    because FastAPI validates before this function is called.

    **``provider.fallback`` and ``provider.usage`` are built from ``ProviderAttempt`` records,
    one per attempt, through the router's ``record`` sink** — not from the terminal
    ``ChatResult``, which deliberately cannot say which connection answered. Reading only the
    terminal event, a fallback answer is indistinguishable from a primary one and the cost of
    falling back is invisible.
    """
    span = _TRACER.start_span("kb.chat.pipeline")
    outcome = "error"
    error_class = "none"
    #: The branches retrieval actually queried, read off the finished trace. It is the countable
    #: form of "this turn ran dense-only" — a legitimate state when the query analyzed to no
    #: terms, and one that must be visible, because a degraded run is a different pipeline and
    #: has to be excluded from an evaluation baseline rather than read as a regression.
    branches = "none"
    frames = _Frames()
    turn = _Turn(
        run=TraceRun(
            retrieval_configuration_version=str(body.config.retrieval_configuration_version),
            # The header's absolute epoch milliseconds, already converted once onto the
            # monotonic clock by `app/api/deps.py::deadline`. Recomputing from `time.time()`
            # here would make an NTP step mid-request look like an expired deadline.
            deadline_monotonic=expiry.monotonic_expiry,
        ),
        frames=frames,
        citations_enabled=body.config.retrieval.citations_enabled,
    )
    task: asyncio.Task[PipelineResult] | None = None

    try:
        with trace.use_span(span, end_on_exit=False):
            yield _frame(
                MessageStart(
                    message_id=body.message_id,
                    conversation_id=body.conversation_id,
                    created_at=_now_rfc3339(),
                )
            )
            yield _frame(Status(stage="retrieving"))

            # Scope is the verified headers'. The body's copies are checked against them and
            # then never read again; `run_pipeline` is handed `ctx`, not `body.org_id`.
            assert_scope_agrees(body, org_id=ctx.org_id, bot_id=ctx.bot_id)
            if not ctx.bot_id:
                raise KbError(
                    ErrorClass.VALIDATION,
                    "X-KB-BOT-ID is required on chat.execute: the mandatory Qdrant filter has a "
                    "bot-access term and there is no value to put in it",
                    origin=Origin.SELF,
                )

            deps = await assemble(body, ctx, expiry, record=turn.record)
            task = asyncio.create_task(
                run_pipeline(
                    _pipeline_request(body),
                    ctx,
                    body.config.allowed_version_ids,
                    _retrieval_config(body),
                    PipelineDeps(
                        embed_query=deps.embed_query,
                        search=deps.search,
                        hydrate=turn.hydrate(deps.chunks),
                        measure=_estimate_tokens,
                        generate=deps.generate,
                        rewriter=deps.rewriter,
                        reranker=turn.rerank(deps.reranker),
                        rerank_capability=deps.rerank_capability,
                        provider_connection_id=deps.provider_connection_id,
                    ),
                    turn.run,
                    on_delta=turn.on_delta,
                ),
                name=f"kb.chat.pipeline:{body.message_id}",
            )

            def _finish(_: asyncio.Task[PipelineResult]) -> None:
                # Flush BEFORE the sentinel. A frame put after ``close()`` sits behind the
                # sentinel in the queue and `drain` has already returned, so the last few
                # characters of an answer would vanish with nothing reporting it.
                turn.flush()
                frames.close()

            task.add_done_callback(_finish)

            async for event in frames.drain():
                yield _frame(event)

            result = await task
            branches = ",".join(result.trace.branches_queried) or "none"
            if not turn._citations_emitted:
                # Reachable only when the pipeline produced no delta at all. The frame is still
                # owed: a client that never sees it cannot clear the previous turn's panel.
                yield _frame(turn.citation_frame())

            yield _frame(RetrievalTraceFrame(trace=_trace_payload(result)))
            outcome = result.mode.value
            yield _frame(
                MessageComplete(
                    message_id=body.message_id,
                    finish_reason=_finish_reason(result, turn.attempts),
                    usage=_client_usage(turn.attempts),
                )
            )
    except asyncio.CancelledError:
        outcome, error_class = "cancelled", ErrorClass.USER_CANCELLATION.value
        await _abandon(task)
        yield _frame(
            MessageComplete(
                message_id=body.message_id,
                finish_reason="cancelled",
                usage=_client_usage(turn.attempts),
            )
        )
        # NEVER swallowed. A swallowed cancellation leaks the span, leaves the provider
        # generating billable tokens, and leaves the task alive for the life of the process.
        raise
    except KbError as exc:
        outcome, error_class = "error", exc.error_class.value
        await _abandon(task)
        yield _frame(
            StreamError(
                error_class=exc.error_class.value, message=exc.message, retryable=exc.retryable
            )
        )
    except BranchAgreementUnavailable as exc:
        # Stage 12's refusal to invent a signal: reranking was skipped and only one branch ran,
        # so there is neither a score to threshold nor branch agreement to select on. It is an
        # error and not a refusal on purpose — reporting a dependency gap to a reader as "not in
        # your sources" is a lie (`kb-rag-query-contract`, finding C2).
        outcome, error_class = "error", ErrorClass.RETRIEVAL.value
        await _abandon(task)
        logger.error("retrieval has no selection signal", exc_info=exc)
        yield _frame(
            StreamError(
                error_class=ErrorClass.RETRIEVAL.value,
                message="retrieval could not select evidence for this question",
                retryable=False,
            )
        )
    except EmptyScopeError as exc:
        outcome, error_class = "error", ErrorClass.VALIDATION.value
        await _abandon(task)
        logger.error("refused to build a tenant filter", exc_info=exc)
        yield _frame(
            StreamError(
                error_class=ErrorClass.VALIDATION.value,
                message="the resolved retrieval scope is empty",
                retryable=False,
            )
        )
    except Exception as exc:
        outcome, error_class = "error", ErrorClass.INTERNAL_DEPENDENCY.value
        await _abandon(task)
        # `str(exc)` never reaches the frame: a provider error payload routinely echoes the
        # request, and the request contains the packed prompt, which contains tenant text.
        logger.exception("unhandled exception in the chat stream", exc_info=exc)
        yield _frame(
            StreamError(
                error_class=ErrorClass.INTERNAL_DEPENDENCY.value,
                message="The service could not complete this request.",
                retryable=False,
            )
        )
    finally:
        span.set_attributes(
            {
                "kb.error_class": error_class,
                "kb.finish_reason": outcome,
                "kb.org_id": ctx.org_id,
                "kb.operation": ctx.operation,
                "kb.retrieval.branches": branches,
            }
        )
        # Synchronous, so it is safe on a cancelled task. Anything that had to await here would
        # need shielding and a bound, or cancellation kills it at the first checkpoint.
        span.end()


async def _abandon(task: asyncio.Task[PipelineResult] | None) -> None:
    """Cancel the pipeline and wait for it to actually stop.

    Awaited rather than fired and forgotten: cancelling is what closes the provider socket, and
    the socket closing is what stops the vendor generating tokens nobody will read. Shielded so
    the wait survives the cancellation that got us here, and bounded so a stuck adapter cannot
    hold the worker past the caller's deadline.
    """
    if task is None or task.done():
        return
    task.cancel()
    with contextlib.suppress(asyncio.CancelledError, Exception):
        await asyncio.shield(asyncio.wait_for(asyncio.shield(task), timeout=2.0))


def _pipeline_request(body: ChatExecuteRequest) -> PipelineRequest:
    """One chat turn, as the runner needs it.

    ``model_context_tokens`` is the **chat model's** window from its ``provider_models`` row and
    is never guessed: stage 13 subtracts ``reserve_output`` from it before packing, and a
    guessed window is the mid-sentence truncation defect as a configuration.
    """
    return PipelineRequest(
        question=body.query,
        history=body.history,
        bot_instructions=body.config.bot_instructions,
        model_context_tokens=body.config.connection.caps.context_window,
        message_id=body.message_id,
    )


def _retrieval_config(body: ChatExecuteRequest) -> RetrievalConfig:
    """Project the wire snapshot onto the runner's configuration.

    ``rerank_top_n == 0`` is the config-off form of stage 11 — the stage still executes its
    block, still opens its span, and still writes a fragment carrying
    ``disabled_by_configuration``. It is never a code path that jumps.

    ``rerank_candidates`` is clamped to at least ``retain`` so a snapshot that retains more than
    it scores is refused by ``RetrievalConfig.__post_init__`` with its own message rather than
    quietly producing a short context.
    """
    retrieval = body.config.retrieval
    return RetrievalConfig(
        retrieval_configuration_version=str(body.config.retrieval_configuration_version),
        fusion_k=retrieval.fusion_k,
        dense_top_k=retrieval.dense_top_k,
        sparse_top_k=retrieval.sparse_top_k,
        max_per_document=retrieval.max_per_document,
        rewrite_enabled=retrieval.rewrite_enabled,
        rerank_enabled=retrieval.rerank_top_n > 0,
        rerank_candidates=max(retrieval.rerank_top_n, retrieval.retain),
        rerank_retain=retrieval.retain,
        reserve_output=retrieval.reserve_output,
        history_window_turns=retrieval.history_window_turns,
        question_max_chars=retrieval.question_max_chars,
        citations_enabled=retrieval.citations_enabled,
        require_at_least_one_citation=retrieval.require_at_least_one_citation,
    )


def _assert_no_credential_can_ride_this_endpoint() -> None:
    """Two properties, checked at import. Non-negotiable 9, mechanically.

    The first is the guard every other internal router carries: **no field whose name looks like
    a secret on anything this endpoint emits.** The models here are the outbound frames, so a
    credential that reached one would be serialized into an HTTP response body — worse than the
    ingestion router's case, where it would only reach a broker payload.

    The second is this endpoint's own, and it exists because this is the **one** internal
    endpoint that legitimately receives a credential. A guard that only bans the name would pass
    against ``provider_credentials: dict[str, str]`` — a rename of the *type*, not the field,
    which strips masking off every value in the map and leaves the name looking correct. So the
    permitted field is pinned by type: the values must be ``SecretStr``, or this module refuses
    to import.

    **The word list is matched per underscore-separated part rather than as a substring**, and
    that is a real difference from the ingestion router's version rather than a loosening. This
    endpoint's frames carry ``input_tokens``, ``output_tokens``, ``cached_tokens`` and
    ``reasoning_tokens`` — token *counts*, which a substring match on ``token`` rejects, and
    which are the whole reason ``provider.usage`` exists. The singular ``token`` and the plural
    ``tokens`` are both banned as whole parts, so ``access_token`` and a field named exactly
    ``tokens`` are still refused while ``*_tokens`` counts pass. ``api_key`` is checked as a
    substring as well, because its parts (``api``, ``key``) are each innocuous alone.
    """
    banned_parts = {
        "credential",
        "credentials",
        "secret",
        "secrets",
        "token",
        "tokens",
        "apikey",
        "password",
        "passwords",
        "private",
        "auth",
        "bearer",
    }
    banned_substrings = ("api_key",)
    #: Names that MEASURE tokens rather than being one, listed individually so each exemption is
    #: a decision rather than a pattern. Anything else containing ``token`` is refused until it
    #: is added here on purpose — which is the point: the list is short and a reviewer sees a
    #: diff to it.
    counted = {
        "first_token_ms",
        "input_tokens",
        "output_tokens",
        "cached_tokens",
        "cache_read_tokens",
        "cache_write_tokens",
        "reasoning_tokens",
        "prompt_tokens",
        "completion_tokens",
    }
    emitted = (
        MessageStart,
        Status,
        Citation,
        Citations,
        Token,
        ProviderUsage,
        ProviderFallback,
        RetrievalTraceFrame,
        MessageComplete,
        StreamError,
        Usage,
    )
    for model in emitted:
        for name in model.model_fields:
            lowered = name.lower()
            if lowered in counted:
                continue
            if set(lowered.split("_")) & banned_parts or any(
                word in lowered for word in banned_substrings
            ):
                raise KbError(
                    ErrorClass.INTERNAL_DEPENDENCY,
                    f"{model.__name__}.{name} names a credential-shaped field on a frame this "
                    "endpoint emits. No provider credential may reach an SSE frame, an error "
                    "envelope, a span attribute or a log field — it appears in exactly one "
                    "place, the internal request body",
                    origin=Origin.SELF,
                )

    annotation = ChatExecuteRequest.model_fields["provider_credentials"].annotation
    if getattr(annotation, "__args__", (None, None))[1] is not SecretStr:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "ChatExecuteRequest.provider_credentials must map to SecretStr. A plain str value "
            "renders verbatim in repr(), in model_dump_json(), and in one str() of the map "
            "inside an exception message",
            origin=Origin.SELF,
        )


_assert_no_credential_can_ride_this_endpoint()

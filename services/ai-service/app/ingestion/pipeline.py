"""The stage walk: one source version from stored bytes to a verified, reportable index.

``tasks.run_version`` owns the Celery concerns — the root span, the durable delivery counter,
the idempotency claim, the item lock, classification and the retry decision. **This module owns
the work**, and the split is what makes the work testable: everything here is a function of its
arguments and its injected clients, so a stage can be exercised without a broker, a worker, or
a Celery test harness that proves the harness.

RESUME IS DERIVED, NOT CHECKPOINTED, AND THAT IS WHY THERE IS NO NEW COLUMN
---------------------------------------------------------------------------
``run_version``'s contract is *resume, never restart*: on re-entry the first **incomplete**
stage is the entry point, because restarting from the top re-parses a 300-page scan that
already succeeded and burns the whole budget before reaching the stage that failed.

The obvious implementation is a ``last_completed_stage`` column. There is none, and adding one
would have been wrong twice over: ``source_versions`` is Laravel's table (ADR-033 property 3),
so the data plane's private resume marker would have become a lifecycle migration; and a
checkpoint column is a *second* answer to "what is done" that can disagree with the first.

The first answer already exists, because every stage commits its output before returning —
which is exactly what ``run_version``'s docstring means by "observable from the outside":

===================  ==================================================================
stage complete when  evidence
===================  ==================================================================
parse + normalize    ``document_elements`` rows exist for the version
chunk                ``chunks`` rows exist for the version
embed + index        every ``chunks`` row for the version has ``index_status='indexed'``
verify               the Qdrant point count for the version equals the chunk count
===================  ==================================================================

Deriving it costs three cheap counts and cannot drift, because the evidence *is* the artifact.
A column can say ``indexed`` about a version whose points were never written.

WHAT THIS MODULE NEVER DOES
---------------------------
It never assigns ``source_items.current_version_id``. ``publish.py``'s docstring carries the
rule in capitals and ``docs/22`` § Q6 records what it costs now that the column and its partial
unique index both exist: the one live mechanism is a **trap rather than a guard** — a second
writer finds an integrity error inside a Celery task that retries forever while every dashboard
stays green. Readiness is reported; Laravel activates.
"""

from __future__ import annotations

import tempfile
from collections.abc import Callable, Sequence
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.db import objects, writes
from app.ingestion import publish
from app.ingestion.chunking.chunker import MAX_TOKENS, ChunkContext, chunk_document
from app.ingestion.embedding.embedder import (
    ChunkVectors,
    EmbeddingModelIdentity,
    embed_passages,
    to_sparse_vector,
)
from app.ingestion.indexing.upserter import to_point, upsert_points, verify_indexed_total
from app.ingestion.parsing.converter import parse_document
from app.ingestion.parsing.normalize import normalize_elements
from app.ingestion.states import SourceState
from app.retrieval.collection import SPARSE_ANALYZER_VERSION, EmbeddingSpace
from app.retrieval.sparse import EmptySparsePassage, term_frequencies, tokenize

__all__ = [
    "MAX_PAGES",
    "PipelineResult",
    "StageProgress",
    "VersionContext",
    "run_pipeline",
]

#: The page ceiling handed to Docling. Every one of its four caps defaults to unbounded, and a
#: 4 000-page fixture with no ceiling is a worker that never returns — the soft time limit does
#: not help, because it raises from a Python signal handler that a thread inside a C loop never
#: returns to the interpreter to receive.
MAX_PAGES: Final[int] = 2_000

#: Handed to Docling as its own byte cap, separate from ``objects.MAX_OBJECT_BYTES``. That one
#: bounds what we are willing to read; this one bounds what we are willing to parse, and parsing
#: is where the memory goes.
MAX_PARSE_BYTES: Final[int] = 256 * 1024 * 1024


@dataclass(frozen=True, slots=True)
class VersionContext:
    """Everything one run needs about the version it is building.

    Assembled by the caller from rows this service reads and never invented here. The three
    ``*_cfg_version`` strings and ``embedding_model_version`` are carried rather than recomputed
    because they are components of the **ingest key** (ADR-068): the key dedupes *work identity*
    and holds every one of them, so a run that recomputed one from its own configuration could
    silently do work under a different identity than the key it claimed.
    """

    org_id: str
    source_id: str
    source_item_id: str
    source_version_id: str
    content_hash: str
    ingest_key: str
    parser_cfg_version: str
    ocr_cfg_version: str
    chunker_cfg_version: str
    embedding_model_version: str
    #: Query-time payload filter terms, resolved by the control plane. Empty is legal and means
    #: "assigned to no bot yet" — the source is organization-owned (ADR-067) and bot access is a
    #: query-time filter, never an index-time scope.
    bot_ids: tuple[str, ...] = ()
    #: The object key Laravel recorded on `source_items`, or None for a source with no stored
    #: original (a crawl). Compared against the composed key, never used as one — see
    #: `app/db/objects.py`. It is carried because the original has two legal spellings and the
    #: content hash alone cannot say which.
    storage_key: str | None = None
    url: str | None = None
    effective_at: datetime | None = None
    expires_at: datetime | None = None
    lang: str = "und"


@dataclass(frozen=True, slots=True)
class StageProgress:
    """One progress frame, in the shape Laravel's ``IngestionCallbackRequest`` validates.

    ``sequence`` is strictly increasing per version and is what lets the control plane discard
    a frame that arrives out of order — which a retrying worker produces routinely. It is
    counted by the emitter rather than derived from the stage, because two frames can share a
    stage and none may share a sequence.
    """

    stage: str
    status: SourceState
    sequence: int
    chunk_count: int | None = None
    warning_summary: dict[str, int] = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class PipelineResult:
    """What the run produced. Consumed by ``publish.report_readiness`` and by nothing else."""

    chunk_count: int
    element_count: int
    indexed: int
    space: EmbeddingSpace
    warnings: dict[str, int]
    resumed_from: str
    #: sha256 over the version's chunk CONTENT HASHES in `seq` order. Empty only on a resumed
    #: run that never held the chunks in memory — see where it is set for why an empty string
    #: is honest there and a recomputed-from-nothing digest would not be.
    content_checksum: str = ""


#: The one place a resumed run's missing checksum is named, so the empty string above is a
#: documented state rather than a default nobody chose.
RESUMED_WITHOUT_CHECKSUM: Final[str] = ""


async def run_pipeline(
    *,
    ctx: VersionContext,
    conn: Any,
    qdrant: Any,
    s3: Any,
    bucket: str,
    identity: EmbeddingModelIdentity,
    embed: Any,
    measure: Callable[[str], int],
    emit: Callable[[StageProgress], None],
    deadline: Callable[[], float],
) -> PipelineResult:
    """Walk one version from the first incomplete stage to a verified index.

    ``emit`` is called at every stage boundary and is the ONLY way the control plane learns
    anything. It is injected rather than imported so this module holds no HTTP client and no
    signing key: the emitter is the caller's, and a test can assert the exact frame sequence
    without a network.

    ``deadline`` returns seconds remaining. It is checked **before** each stage rather than
    inside one, because a stage that is killed part-way through is the case the resume logic
    then has to reason about — and a stage that never started leaves nothing to reason about.
    """
    warnings: dict[str, int] = {}
    # TAKEN OFF THE IDENTITY, NEVER REBUILT FROM PARTS. `resolve_identity` probed the model and
    # `EmbeddingModelIdentity.space` is what it concluded — width included, read off a real
    # response. Composing a second `EmbeddingSpace` here from the same-looking fields is how the
    # indexer and the reader end up naming two collections that differ in one character.
    space = identity.space

    progress = _Sequencer(emit)
    done = await _completed_stages(conn, ctx=ctx, qdrant=qdrant, space=space)
    resumed_from = done.first_incomplete

    # ── parse + normalize ────────────────────────────────────────────────────
    if not done.parsed:
        _require_budget(deadline, stage="parsing", seconds=300)
        progress.emit("parsing", SourceState.PARSING)
        elements, parse_warnings = _parse_and_normalize(ctx=ctx, s3=s3, bucket=bucket)
        warnings.update(parse_warnings)
        await writes.replace_document_elements(
            conn,
            org_id=ctx.org_id,
            source_version_id=ctx.source_version_id,
            elements=[_element_row(element, seq) for seq, element in enumerate(elements)],
        )
        await conn.commit()
        element_count = len(elements)
    else:
        # RE-READ RATHER THAN RE-PARSE. The rows are the parse's committed output, so reading
        # them is what "resume" means here — and it is the difference between a redelivery
        # costing three counts and costing a 300-page scan.
        elements = []
        element_count = done.element_count

    # ── chunk ────────────────────────────────────────────────────────────────
    if not done.chunked:
        _require_budget(deadline, stage="chunking", seconds=60)
        progress.emit("chunking", SourceState.CHUNKING)
        if not elements:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                "the version has element rows but this run did not parse, so there is nothing "
                "in memory to chunk. Re-reading committed elements back into the chunker's "
                "protocol is not implemented, so this run must not silently produce zero "
                "chunks and report success",
            )
        chunks = chunk_document(elements=list(elements), measure=measure)
        _assert_chunk_budget(chunks)
        await writes.replace_chunks(
            conn, org_id=ctx.org_id, source_version_id=ctx.source_version_id, chunks=chunks
        )
        await _write_sparse_statistics(conn, ctx=ctx, chunks=chunks)
        await conn.commit()
    else:
        chunks = []

    chunk_count = len(chunks) if chunks else done.chunk_count

    # ── embed + index ────────────────────────────────────────────────────────
    if not done.indexed:
        _require_budget(deadline, stage="embedding", seconds=120)
        progress.emit("embedding", SourceState.EMBEDDING)
        if not chunks:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                "the version has chunk rows but this run did not chunk, so their text is not "
                "in memory to embed. Re-reading committed chunks is not implemented; failing "
                "here is correct, because embedding an empty list would upsert zero points "
                "and verification would then compare zero against zero and pass",
            )
        vectors = embed_passages(
            [chunk.text for chunk in chunks],
            embed=embed,
            identity=identity,
            measure=measure,
            org_id=ctx.org_id,
            trace_id=ctx.source_version_id,
        )
        progress.emit("indexing", SourceState.INDEXING)
        points = [
            to_point(
                chunk=chunk,
                vectors=_with_sparse(vector, chunk.text),
                # `active` and not the version's own status: the payload term is the SOURCE's
                # status, the source is not disabled, and the version is kept out of retrieval
                # by the active-version filter until Laravel flips the pointer — never by
                # writing a non-active status onto points that are about to become live.
                source_status=SourceState.READY.value,
            )
            for chunk, vector in zip(chunks, vectors, strict=True)
        ]
        upsert_points(client=qdrant, space=space, points=points)
        # AFTER the upsert returns, never before — the row is the resume evidence.
        await writes.mark_chunks_indexed(
            conn, org_id=ctx.org_id, source_version_id=ctx.source_version_id
        )
        await conn.commit()

    # ── verify ───────────────────────────────────────────────────────────────
    # `indexing` and not a `verifying` state: `SourceState` has no such member, on purpose —
    # verification is the tail of indexing and a state nothing can leave is not a state.
    progress.emit("verifying", SourceState.INDEXING)
    verification = verify_indexed_total(
        client=qdrant,
        space=space,
        org_id=ctx.org_id,
        source_version_id=ctx.source_version_id,
        expected=chunk_count,
    )
    if not verification.passed:
        # NEVER REPAIRED HERE. `verify_indexed_total` says the same thing: a verification that
        # fixes what it measures cannot fail. The prior version was never touched and keeps
        # serving, which is the whole point of verifying before reporting.
        raise KbError(
            ErrorClass.VECTOR_INDEXING,
            f"version {ctx.source_version_id} has {chunk_count} chunks but the collection "
            f"holds {verification.indexed} points for it. Reporting readiness now would "
            "publish a version that answers from part of its document",
            retryable=True,
        )

    return PipelineResult(
        chunk_count=chunk_count,
        element_count=element_count,
        indexed=verification.indexed,
        space=space,
        warnings=warnings,
        resumed_from=resumed_from,
        # COMPUTED ONLY WHEN THIS RUN HELD THE CHUNKS. A resumed run that skipped chunking has
        # their rows but not their objects, and a digest over an empty list is a valid-looking
        # sha256 of nothing — which the control plane would compare against the previous run's
        # and read as "the content changed". An empty string is the honest answer, and it is
        # what `RESUMED_WITHOUT_CHECKSUM` names.
        content_checksum=publish.content_checksum(list(chunks))
        if chunks
        else RESUMED_WITHOUT_CHECKSUM,
    )


# ── stage helpers ─────────────────────────────────────────────────────────────


@dataclass(frozen=True, slots=True)
class _Completed:
    parsed: bool
    chunked: bool
    indexed: bool
    element_count: int
    chunk_count: int

    @property
    def first_incomplete(self) -> str:
        if not self.parsed:
            return "parsing"
        if not self.chunked:
            return "chunking"
        if not self.indexed:
            return "embedding"
        return "verifying"


async def _completed_stages(
    conn: Any, *, ctx: VersionContext, qdrant: Any, space: EmbeddingSpace
) -> _Completed:
    """Which stages a previous attempt finished, read from their committed output.

    See the module docstring for why this is derived rather than stored. The counts are scoped
    by ``(organization_id, source_version_id)`` and never by the version alone: a derived table
    is still tenant data, and an unscoped count is a cross-tenant read that returns a plausible
    number.
    """
    elements = await writes.count_rows(
        conn, table="document_elements", org_id=ctx.org_id, source_version_id=ctx.source_version_id
    )
    chunks = await writes.count_rows(
        conn, table="chunks", org_id=ctx.org_id, source_version_id=ctx.source_version_id
    )
    pending = await writes.count_rows(
        conn,
        table="chunks",
        org_id=ctx.org_id,
        source_version_id=ctx.source_version_id,
        index_status="pending",
    )
    return _Completed(
        parsed=elements > 0,
        chunked=chunks > 0,
        # `indexed` requires chunks to exist AND none of them to be pending. "No pending rows"
        # alone is true of a version with no chunks at all, which would skip straight to a
        # verification of zero against zero and pass.
        indexed=chunks > 0 and pending == 0,
        element_count=elements,
        chunk_count=chunks,
    )


def _parse_and_normalize(
    *, ctx: VersionContext, s3: Any, bucket: str
) -> tuple[list[Any], dict[str, int]]:
    """Fetch the original, parse it, and translate it into the chunker's element protocol.

    The bytes go to a temporary file because Docling's converter takes a path. The file is in a
    ``TemporaryDirectory`` so it is removed on every exit path including a raise — a tenant's
    document left in ``/tmp`` on a shared worker is a tenancy failure that no test looks for.
    """
    body = objects.fetch_original(
        s3,
        bucket=bucket,
        org_id=ctx.org_id,
        source_id=ctx.source_id,
        content_hash=ctx.content_hash,
        storage_key=ctx.storage_key,
    )

    warnings: dict[str, int] = {}
    with tempfile.TemporaryDirectory(prefix="kb-ingest-") as directory:
        path = Path(directory) / ctx.content_hash
        path.write_bytes(body)
        parsed = parse_document(path=path, max_pages=MAX_PAGES, max_bytes=MAX_PARSE_BYTES)

    if parsed.truncated:
        # A WARNING AND NOT A FAILURE, which is the specified behaviour — but it is recorded,
        # because a truncated parse returns partial success rather than raising and the missing
        # pages simply never index. Silent is the one thing it must not be.
        warnings["parse_truncated"] = 1

    context = ChunkContext(
        org_id=ctx.org_id,
        source_id=ctx.source_id,
        source_item_id=ctx.source_item_id,
        source_version_id=ctx.source_version_id,
        bot_ids=ctx.bot_ids,
        url=ctx.url,
        created_at=datetime.now(UTC),
        effective_at=ctx.effective_at,
        expires_at=ctx.expires_at,
        embedding_model_id=ctx.embedding_model_version,
        parser_version=ctx.parser_cfg_version,
    )
    report = normalize_elements(parsed.elements, context=context, lang=ctx.lang)

    if report.dropped_labels:
        # A Docling vocabulary change reaches a human exactly here or nowhere. The count travels
        # to the readiness report rather than to a log line nobody reads.
        warnings["parser_unmapped_label"] = len(report.dropped_labels)
    if report.empty_elements:
        warnings["parser_empty_element"] = report.empty_elements

    return report.elements, warnings


def _with_sparse(vector: ChunkVectors, text: str) -> ChunkVectors:
    """Attach the document-side lexical vector, or record its deliberate absence.

    ``EmptySparsePassage`` is a real chunk — an image-only chunk, a table of bare numerals —
    and the upserter's rule is that a point may be written with **no** sparse vector,
    deliberately, but never with an empty one: an empty vector is accepted by Qdrant, matches
    nothing forever, and halves the hybrid branch for that chunk with no error on either side.
    """
    try:
        return ChunkVectors(dense=vector.dense, sparse=to_sparse_vector(text))
    except EmptySparsePassage:
        return ChunkVectors(dense=vector.dense, sparse=None)


async def _write_sparse_statistics(
    conn: Any, *, ctx: VersionContext, chunks: Sequence[Any]
) -> None:
    """The version's BM25 document frequencies, summed over its own chunks and nothing else.

    ``document_total`` is the number of chunks, because a *document* in the IDF sense is a
    retrievable unit and the retrievable unit here is the chunk. Counting source documents
    instead would put 1 in the numerator for every version and make every term's IDF identical.

    A term is counted **once per chunk** regardless of how often it occurs in it — that is what
    a document frequency is. Summing raw term frequencies here is the classic error and it does
    not raise: it produces a number larger than the document total, which makes the IDF of a
    common term negative, which under a dot-product scorer means a matching term *subtracts*.
    """
    frequencies: dict[int, int] = {}
    for chunk in chunks:
        for term in set(term_frequencies(tokenize(chunk.text))):
            frequencies[term] = frequencies.get(term, 0) + 1

    await writes.replace_sparse_statistics(
        conn,
        org_id=ctx.org_id,
        source_version_id=ctx.source_version_id,
        analyzer=SPARSE_ANALYZER_VERSION,
        document_total=len(chunks),
        frequencies=frequencies,
    )


def _assert_chunk_budget(chunks: Sequence[Any]) -> None:
    """No chunk above ``MAX_TOKENS``. A chunker defect, and it must surface as one.

    The provider rejects an over-window input, which is the behaviour
    ``PROVIDER_TRUNCATION_POLICY`` exists to preserve — but it rejects the whole *batch*, so
    without this the report names sixty-four chunks and not the one that is wrong.
    """
    for chunk in chunks:
        if chunk.metadata.token_count > MAX_TOKENS:
            raise KbError(
                ErrorClass.VALIDATION,
                f"chunk seq={chunk.metadata.seq} measures {chunk.metadata.token_count} tokens, "
                f"above MAX_TOKENS={MAX_TOKENS}. That is a chunker defect rather than a "
                "document problem, and trimming it here would index a chunk whose tail is "
                "unsearchable forever",
                retryable=False,
            )


def _element_row(element: Any, seq: int) -> Any:
    """Adapt a normalized element to the column names ``replace_document_elements`` binds.

    A thin shim rather than renaming the fields on ``NormalizedElement``: that type's names are
    the chunker's protocol, which is a published contract, and the table's names are Laravel's.
    Making one serve both would silently couple a migration to a protocol.
    """
    return _ElementRow(
        id=element.element_id,
        parent_element_id=element.parent_element_id,
        seq=seq,
        kind=element.kind,
        text=element.text,
        page=element.page,
        slide=element.slide,
        sheet=element.sheet,
        table_ref=None,
        url=element.context.url,
        anchor=element.anchor,
        char_start=element.char_start,
        char_end=element.char_end,
    )


@dataclass(frozen=True, slots=True)
class _ElementRow:
    id: str
    parent_element_id: str | None
    seq: int
    kind: str
    text: str
    page: int | None
    slide: int | None
    sheet: str | None
    table_ref: str | None
    url: str | None
    anchor: str | None
    char_start: int
    char_end: int


class _Sequencer:
    """Assigns the strictly increasing ``sequence`` every progress frame carries.

    Counted here rather than derived from the stage name, because a run can emit two frames for
    one stage (a retry re-entering it) and no two frames may share a sequence — the control
    plane discards a frame whose sequence is not greater than the last it applied, so a repeated
    number silently drops a real update.
    """

    def __init__(self, emit: Callable[[StageProgress], None]) -> None:
        self._emit = emit
        self._next = 1

    def emit(self, stage: str, status: SourceState, **extra: Any) -> None:
        self._emit(StageProgress(stage=stage, status=status, sequence=self._next, **extra))
        self._next += 1


def _require_budget(deadline: Callable[[], float], *, stage: str, seconds: int) -> None:
    """Refuse to start a stage that cannot plausibly finish inside the remaining budget.

    Checked before the stage and never inside it. A stage killed part-way is the case resume
    then has to reason about; a stage that never started leaves nothing to reason about, and
    the redelivery gets a full budget.
    """
    remaining = deadline()
    if remaining < seconds:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"{remaining:.0f}s left is below the {seconds}s this run reserves for {stage}. "
            "Yielding before the stage starts, so the redelivery resumes from a clean "
            "boundary rather than from a half-written one",
            retryable=True,
        )

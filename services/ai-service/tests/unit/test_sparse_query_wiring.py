"""The chat path's lexical arm: the three outcomes, and the store's two reads.

Model and statement shape is what this tier may assert. Nothing here runs a query or a stream —
the endpoint's behaviour is ``tests/contract/test_internal_chat_stream.py``'s, and a filter that
was built correctly and never executed proves nothing about isolation.

The file exists because of a defect worth naming: the chat endpoint shipped with
``sparse=None`` hardcoded and a comment citing ``tokenize`` as an unimplemented stub. It had been
implemented; the comment was read out of a stale line elsewhere and believed. Every contract test
passed, including the one asserting the resulting dense-only error was *reported honestly* — it
was. What no test asserted was that the lexical branch ran at all.
"""

from __future__ import annotations

import asyncio
from collections.abc import Sequence
from typing import Any

import pytest

from app.api.internal.v1.chat import (
    SPARSE_STATISTICS_CEILING_SECONDS,
    _lexical_query_vector,
)
from app.core.errors import ErrorClass, KbError
from app.retrieval.collection import SPARSE_ANALYZER_VERSION
from app.retrieval.sparse import (
    CorpusStatistics,
    SparseAnalyzerMismatch,
    SparseStatisticsUnavailable,
    evidence_fingerprint,
    term_frequencies,
    tokenize,
)
from app.retrieval.statistics import FREQUENCIES_SQL, TOTALS_SQL, PostgresCorpusStatisticsStore


class Scope:
    """Structurally a ``TenantContext``: the two read-only members, from verified headers."""

    def __init__(self, org_id: str = "01J8A4QK7QK7QK7QK7QK7QK7QA") -> None:
        self._org = org_id

    @property
    def org_id(self) -> str:
        return self._org

    @property
    def bot_id(self) -> str | None:
        return "01J8A4QK7QK7QK7QK7QK7QK7QB"


VERSIONS = ("01J8A4QK7QK7QK7QK7QK7QK7QV", "01J8A4QK7QK7QK7QK7QK7QK7QW")


class Store:
    """A ``CorpusStatisticsStore`` that stamps its value the way the real one must."""

    def __init__(self, *, raises: Exception | None = None, delay: float = 0.0) -> None:
        self.raises = raises
        self.delay = delay
        self.calls: list[dict[str, Any]] = []

    async def load(
        self,
        ctx: Any,
        allowed_version_ids: Sequence[str],
        term_ids: Sequence[int],
        *,
        analyzer: str,
    ) -> CorpusStatistics:
        self.calls.append(
            {"org": ctx.org_id, "versions": tuple(allowed_version_ids), "terms": tuple(term_ids)}
        )
        if self.delay:
            await asyncio.sleep(self.delay)
        if self.raises is not None:
            raise self.raises
        return CorpusStatistics(
            org_id=ctx.org_id,
            analyzer=analyzer,
            evidence_fp=evidence_fingerprint(allowed_version_ids),
            document_total=100,
            document_frequencies=dict.fromkeys(term_ids, 4),
        )


# ── the three outcomes, decided explicitly ────────────────────────────────────


async def test_a_query_with_terms_produces_a_real_sparse_vector() -> None:
    store = Store()
    vector = await _lexical_query_vector(
        "does the XR-400B cover accidental damage",
        Scope(),
        VERSIONS,
        store,
        remaining_seconds=5.0,
    )
    assert vector is not None
    assert vector.indices and len(vector.indices) == len(vector.values)
    assert all(weight > 0 for weight in vector.values), (
        "the IDF form cannot go negative, so no matching term may subtract from a score"
    )
    # The term ids bound to the statistics read are the analyzer's own, not a guess.
    assert store.calls[0]["terms"] == tuple(
        sorted(term_frequencies(tokenize("does the XR-400B cover accidental damage")))
    )


async def test_the_scope_reaches_the_store_positionally_and_unaltered() -> None:
    """``for_scope`` checks the statistics against exactly these two, so a signature that let
    either be omitted would admit another scope's document frequencies."""
    store = Store()
    await _lexical_query_vector(
        "refund policy", Scope("01J8A4QK7QK7QK7QK7QK7QK7QZ"), VERSIONS, store, remaining_seconds=5.0
    )
    assert store.calls[0]["org"] == "01J8A4QK7QK7QK7QK7QK7QK7QZ"
    assert store.calls[0]["versions"] == VERSIONS


async def test_a_query_that_analyzes_to_no_terms_is_a_dense_only_run_and_not_an_error() -> None:
    """``EmptySparseQuery`` is a legitimate outcome. It is decided from the analysis alone, so
    the empty case costs no round trip at all."""
    store = Store()
    assert (
        await _lexical_query_vector("!!! ??? ...", Scope(), VERSIONS, store, remaining_seconds=5.0)
        is None
    )
    assert store.calls == [], "nothing to ask means nothing is asked"


async def test_unavailable_statistics_are_an_error_and_never_a_uniform_idf_fallback() -> None:
    """The tempting degradation ranks common words like part numbers, forever, with nothing in
    the trace to say so — the same shape as an outage laundered into a rerank skip."""
    store = Store(raises=SparseStatisticsUnavailable("the rollup is missing"))
    with pytest.raises(KbError) as caught:
        await _lexical_query_vector(
            "refund policy", Scope(), VERSIONS, store, remaining_seconds=5.0
        )
    assert caught.value.error_class is ErrorClass.RETRIEVAL


async def test_an_analyzer_mismatch_is_an_error_and_is_not_retryable() -> None:
    """Term ids are a different space entirely, so no posting matches and the run would read as
    "the corpus has no lexical match" rather than as a version skew. Retrying reproduces it."""
    store = Store(raises=SparseAnalyzerMismatch("read under bm25/v0"))
    with pytest.raises(KbError) as caught:
        await _lexical_query_vector(
            "refund policy", Scope(), VERSIONS, store, remaining_seconds=5.0
        )
    assert caught.value.error_class is ErrorClass.RETRIEVAL
    assert caught.value.retryable is False


async def test_the_read_is_bounded_by_the_callers_remaining_deadline() -> None:
    """The statistics read is passed in rather than fetched inside ``encode_query`` precisely so
    the caller can put a deadline on it; it sits in a leg that already holds round trips."""
    store = Store(delay=0.2)
    with pytest.raises(KbError) as caught:
        await _lexical_query_vector(
            "refund policy", Scope(), VERSIONS, store, remaining_seconds=0.01
        )
    assert caught.value.error_class is ErrorClass.RETRIEVAL
    assert "did not answer" in caught.value.message


async def test_a_spent_deadline_refuses_before_issuing_the_read() -> None:
    store = Store()
    with pytest.raises(KbError):
        await _lexical_query_vector(
            "refund policy", Scope(), VERSIONS, store, remaining_seconds=0.0
        )
    assert store.calls == []


def test_the_ceiling_is_a_backstop_well_inside_the_retrieval_leg() -> None:
    from app.rag.stages import RETRIEVAL_BUDGET_SECONDS

    assert 0.0 < SPARSE_STATISTICS_CEILING_SECONDS < RETRIEVAL_BUDGET_SECONDS


# ── the store's two statements ────────────────────────────────────────────────


def test_both_reads_bind_the_analyzer_and_the_totals_read_is_not_the_prefix_alone() -> None:
    """The totals predicate is a strict prefix of the frequencies predicate, so a store issuing
    only the first would look complete. And the analyzer is easier to forget on the totals,
    where a document total *reads* as analyzer-independent — the column is not, and the wrong
    answer is a clean doubling with no row missing and nothing raised."""
    assert "sparse_term_frequencies" in FREQUENCIES_SQL
    assert "sparse_version_statistics" in TOTALS_SQL
    assert "GROUP BY term_id" in FREQUENCIES_SQL
    for statement in (FREQUENCIES_SQL, TOTALS_SQL):
        assert "organization_id = %s" in statement
        assert "source_version_id = ANY(%s)" in statement
        assert "analyzer = %s" in statement
    assert "term_id = ANY(%s)" in FREQUENCIES_SQL
    assert "term_id" not in TOTALS_SQL
    # No coalesce: `sum()` over no rows must stay NULL so an absent rollup is distinguishable
    # from a scope that genuinely holds no documents.
    assert "coalesce" not in TOTALS_SQL.lower()


def test_the_store_reads_and_never_writes() -> None:
    for statement in (FREQUENCIES_SQL, TOTALS_SQL):
        upper = statement.upper()
        assert upper.startswith("SELECT")
        assert "INSERT" not in upper and "UPDATE" not in upper and "DELETE" not in upper


class Cursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    async def fetchone(self) -> tuple[Any, ...] | None:
        return self._rows[0] if self._rows else None

    async def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class Connection:
    def __init__(self, totals: list[tuple[Any, ...]], freqs: list[tuple[Any, ...]]) -> None:
        self.totals = totals
        self.freqs = freqs
        self.statements: list[tuple[str, tuple[Any, ...]]] = []

    async def execute(self, statement: str, params: tuple[Any, ...]) -> Cursor:
        self.statements.append((statement, params))
        return Cursor(self.totals if statement is TOTALS_SQL else self.freqs)


class Pool:
    def __init__(self, conn: Connection) -> None:
        self._conn = conn

    def connection(self) -> Any:
        conn = self._conn

        class Ctx:
            async def __aenter__(self) -> Connection:
                return conn

            async def __aexit__(self, *exc: Any) -> None:
                return None

        return Ctx()


async def test_the_store_binds_both_statements_to_the_same_resolved_scope() -> None:
    conn = Connection(totals=[(120,)], freqs=[(7, 3), (9, 1)])
    store = PostgresCorpusStatisticsStore(Pool(conn))
    stats = await store.load(Scope(), VERSIONS, [9, 7], analyzer=SPARSE_ANALYZER_VERSION)

    assert [statement for statement, _ in conn.statements] == [TOTALS_SQL, FREQUENCIES_SQL]
    for _, params in conn.statements:
        assert params[0] == Scope().org_id
        assert params[1] == list(VERSIONS)
        assert params[2] == SPARSE_ANALYZER_VERSION
    assert stats.document_total == 120
    assert stats.document_frequencies == {7: 3, 9: 1}
    # `for_scope` has nothing else to check against, so the fingerprint must come from the exact
    # list bound to `$2` — never from a wider set resolved earlier in the request.
    assert stats.evidence_fp == evidence_fingerprint(VERSIONS)
    assert stats.for_scope(Scope(), VERSIONS, analyzer=SPARSE_ANALYZER_VERSION) is stats


async def test_an_absent_totals_row_is_unavailable_and_never_a_zero() -> None:
    """Every id in the scope names an ACTIVE version, and an active version wrote its statistics
    row in the same transaction as its chunks. So no row is a missing write, not an empty
    corpus — and a corpus that genuinely holds no documents stores a ``document_total`` of 0."""
    store = PostgresCorpusStatisticsStore(Pool(Connection(totals=[(None,)], freqs=[])))
    with pytest.raises(SparseStatisticsUnavailable):
        await store.load(Scope(), VERSIONS, [7], analyzer=SPARSE_ANALYZER_VERSION)

    stored_zero = PostgresCorpusStatisticsStore(Pool(Connection(totals=[(0,)], freqs=[])))
    stats = await stored_zero.load(Scope(), VERSIONS, [7], analyzer=SPARSE_ANALYZER_VERSION)
    assert stats.document_total == 0


async def test_the_totals_read_runs_even_when_the_query_has_no_terms() -> None:
    """Unconditional on purpose: a caller may ask for the scope's size on a dense-only run, and
    skipping it would return a zero that means something different from the stored zero."""
    conn = Connection(totals=[(120,)], freqs=[])
    store = PostgresCorpusStatisticsStore(Pool(conn))
    stats = await store.load(Scope(), VERSIONS, [], analyzer=SPARSE_ANALYZER_VERSION)
    assert [statement for statement, _ in conn.statements] == [TOTALS_SQL]
    assert stats.document_total == 120
    assert stats.document_frequencies == {}

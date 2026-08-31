"""The PostgreSQL implementation of :class:`~app.retrieval.sparse.CorpusStatisticsStore`.

``app/retrieval/sparse.py`` is deliberately database-free — it is arithmetic and an analyzer,
and every corpus-dependent quantity it computes is passed in — so the two SQL statements its
``CorpusStatisticsStore`` Protocol specifies live here instead. That Protocol's docstring is the
specification and this module is the only implementation of it in this service; the statements
below are transcribed from it placeholder for placeholder, and
``tests/unit/test_sparse_store_read_shape.py`` already pins that docstring against Laravel's
migration, which is the third statement of the same contract.

**Two reads, not one, and the second is the one that gets forgotten.** The totals predicate is a
strict *prefix* of the frequencies predicate, so a store that issued only the first would look
complete and would return a ``CorpusStatistics`` it cannot populate.

**The analyzer predicate is bound on both reads, and it is easier to forget on the totals.** A
document total *reads* as analyzer-independent — it counts chunks, and a version has the same
chunks under either analyzer — but the row is written per analyzer, so an analyzer-blind sum
over a version indexed under two of them adds both rows into the IDF numerator. And precisely
because the count really does not depend on the analyzer, those two rows normally hold the
*same* number, so the wrong answer is a clean doubling: a corpus twice its real size, every
score shifted by one constant, no row missing and nothing raised.

**``$2`` is the resolved active-version set, on both reads** — never the organization as a
whole. Scoping to the org would leave a weak oracle *inside* an organization, across bots, since
a term's weight would then reflect documents the queried bot has no access to. Scoping to the
version set removes it and makes the statistics agree with what is searchable, which is also
what makes ``for_scope``'s fingerprint check meaningful.

**A missing totals row is an error, not a zero.** ``sum()`` over no rows is SQL ``NULL``, and
this module refuses rather than coalescing it to ``0``. Every id in ``allowed_version_ids`` names
an *active* version, and a version is only active because it was published, and publication
writes its ``sparse_version_statistics`` row in the same transaction as its ``chunks``. So no row
means the statistics were never written or have been removed under a live version — which is
``SparseStatisticsUnavailable``. A genuine zero needs a stored ``document_total = 0``, which the
CHECK permits and which means the version really holds no documents. Coalescing collapses those
two into one number and the degraded one ranks common words like part numbers, forever, with
nothing in the trace to say so.

**This module writes nothing.** Both statements are ``SELECT``; the two tables are written by
the ingestion path in the same transaction as the version's chunks, and purged by
``app/deletion/relational.py``.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any, Final

from app.retrieval.sparse import (
    CorpusStatistics,
    SparseStatisticsUnavailable,
    evidence_fingerprint,
)
from app.retrieval.tenancy import TenantContext

__all__ = ["FREQUENCIES_SQL", "TOTALS_SQL", "PostgresCorpusStatisticsStore"]

#: Read 1 — ``organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3 AND
#: term_id = ANY($4)``, ``sum(document_frequency)`` **grouped by term_id**. The scope spans
#: several versions and a term is counted once per version, so the per-term sum is the read and
#: not a convenience.
FREQUENCIES_SQL: Final[str] = (
    "SELECT term_id, sum(document_frequency) AS df "
    "FROM sparse_term_frequencies "
    "WHERE organization_id = %s AND source_version_id = ANY(%s) "
    "AND analyzer = %s AND term_id = ANY(%s) "
    "GROUP BY term_id"
)

#: Read 2 — the same scope without the term. One number for the whole scope: the IDF numerator.
#: Deliberately no ``coalesce``; see the module docstring.
TOTALS_SQL: Final[str] = (
    "SELECT sum(document_total) AS total "
    "FROM sparse_version_statistics "
    "WHERE organization_id = %s AND source_version_id = ANY(%s) AND analyzer = %s"
)


class PostgresCorpusStatisticsStore:
    """Structurally a ``CorpusStatisticsStore``, over the lifespan-owned psycopg pool.

    The pool is injected rather than imported for the reason every client in this service is:
    one built per call means a fresh handshake on the retrieval leg's budget, and one built at
    import binds to whichever event loop happens to run first.
    """

    __slots__ = ("_pool",)

    def __init__(self, pool: Any) -> None:
        self._pool = pool

    async def load(
        self,
        ctx: TenantContext,
        allowed_version_ids: Sequence[str],
        term_ids: Sequence[int],
        *,
        analyzer: str,
    ) -> CorpusStatistics:
        """Document frequencies for ``term_ids`` within this exact scope.

        ``ctx`` and ``allowed_version_ids`` are positional and required, matching
        ``tenant_filter`` and ``retrieve_branches``: they are the scope, and a signature that let
        either be omitted would admit a statistics read wider than the query is filtered to.

        **The totals read is unconditional** and runs even when ``term_ids`` is empty. That is a
        real call rather than a wasted one — a caller may ask for the scope's size on a query
        that analyzed to no terms — and skipping it would make the empty case return a
        ``document_total`` of zero that means something different from the stored zero.

        ``evidence_fp`` is derived from the exact list bound to ``$2`` and from nothing else.
        Deriving it from the organization's whole version set, or from a resolution cached
        earlier in the request, turns ``for_scope`` into a check that passes on statistics read
        over a wider scope — the one thing it exists to refuse.
        """
        versions = list(allowed_version_ids)
        terms = sorted(set(term_ids))

        async with self._pool.connection() as conn:
            totals_cursor = await conn.execute(TOTALS_SQL, (ctx.org_id, versions, analyzer))
            totals_row = await totals_cursor.fetchone()
            frequencies: dict[int, int] = {}
            if terms:
                cursor = await conn.execute(
                    FREQUENCIES_SQL, (ctx.org_id, versions, analyzer, terms)
                )
                frequencies = {int(row[0]): int(row[1]) for row in await cursor.fetchall()}

        # `sum()` over no rows is NULL. NOT coalesced to 0 — see the module docstring: every id
        # in `versions` names an active version, and an active version has a statistics row by
        # construction, so an absent total is a missing write rather than an empty corpus.
        if totals_row is None or totals_row[0] is None:
            raise SparseStatisticsUnavailable(
                f"no sparse_version_statistics rows for organization {ctx.org_id!r} over "
                f"{len(versions)} active version(s) under analyzer {analyzer!r}. Every active "
                "version writes that row in the same transaction as its chunks, so an absent "
                "total is a missing or purged write and never an empty corpus — a corpus that "
                "genuinely holds no documents stores a document_total of 0"
            )

        return CorpusStatistics(
            org_id=ctx.org_id,
            analyzer=analyzer,
            evidence_fp=evidence_fingerprint(versions),
            document_total=int(totals_row[0]),
            document_frequencies=frequencies,
        )

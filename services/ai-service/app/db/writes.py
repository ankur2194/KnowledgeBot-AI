"""The only module in this service that issues SQL.

This service writes **exactly six** PostgreSQL tables and owns **no** migration. They are
derived, rebuildable artifacts whose schema lives in Laravel's migrations: we write rows
into a schema we do not define.

A seventh name here is a review stop, not a refactor. **The invariant is not the number** —
it is the three properties every name on the list has, and the number is only what makes a
violation visible in a diff:

1. **The row is derived and rebuildable** in the ADR-010 sense: a pure function of content
   already held in PostgreSQL and object storage, reproducible exactly by a rebuild, with
   nothing on it that is a source of truth.
2. **The public API neither reads nor writes it.** This is the one that actually bites. A
   data-plane write into a table Laravel serves would land beside Laravel's own writer with
   no policy, no audit row, and no framework-applied tenant scope — and it fails nowhere.
   The row is simply there.
3. **Laravel owns the migration.** Schema is never defined here, in any form.

That reasoning is what admitted the two sparse-statistics tables (finding **C2**) rather
than an appeal to "one more is fine". ADR-030 removed the sparse retrieval arm along with
local model inference; C2 restored it with locally computed BM25, which CLAUDE.md permits
explicitly — a statistical ranking function is not a model. BM25 needs corpus statistics,
``app/retrieval/sparse.py`` splits the formula so that every corpus-dependent quantity sits
on the query side, and these two tables are where that side reads from. Every row in them is
a pure function of the version's chunk text under ``SPARSE_ANALYZER_VERSION`` — the same
property, for the same reason, as the ``chunks`` rows they are computed from. Nothing in the
public API touches either table, and
``services/core-api/database/migrations/2026_08_07_000600_create_sparse_corpus_statistics_tables.php``
defines them.

They are **two** names and not one, and the second is not a convenience. The per-version
document total is the numerator of the IDF formula and is meaningless outside the scope the
frequencies carry, so it is keyed identically —
``(organization_id, source_version_id, analyzer)`` — rather than stored as a column on
``source_versions``. Which it could not be in any case: **``source_versions`` does not exist
in this repository**, no migration creates it, and inventing it to hang a counter on would
put a table `kb-source-lifecycle` owns into a change about BM25.

Two further rules make the direct write safe:

* Chunk, element **and sparse-statistics** rows are written **only against the new,
  not-yet-active ``source_version_id``**, never against the version currently serving. That
  is why a direct write cannot corrupt live state: nothing reads those rows until Laravel
  flips the pointer. For the statistics that rule is load-bearing in an unobvious way —
  document frequencies that appeared before their version was published would change the IDF
  of terms in queries against the *previous* version, so the old version's ranking would
  shift under a query nobody re-ran. The read is scoped to the resolved active-version set,
  so an unpublished version contributes nothing, automatically.
* This service **never** assigns ``source_items.current_version_id``. It reports counts,
  checksum and readiness on the ingestion status callback, and Laravel activates. Two
  writers on the one column that decides which version is live turns a lifecycle bug into a
  constraint violation inside a Celery task, retried forever.

There is deliberately no ORM and no migration tool. CI greps this whole package
case-insensitively for the names of both, so they must not appear here even in prose — see
``services/ai-service/README.md`` for the list and the reasoning.
"""

from __future__ import annotations

from typing import Final

__all__ = ["ALLOWED_TABLES"]

#: The complete write allow-list. CI reads this module to enforce it, so the tuple is the
#: single place the list exists — do not restate it in a docstring elsewhere.
ALLOWED_TABLES: Final[tuple[str, ...]] = (
    "chunks",
    "document_elements",
    "retrieval_traces",
    "evaluation_results",
    # ── finding C2: the BM25 corpus statistics behind the restored sparse arm ──────────
    # Written in the SAME transaction as the version's `chunks` rows, against the new,
    # not-yet-active `source_version_id`, and upserted on their natural primary keys so a
    # redelivered Celery task overwrites rather than accumulates.
    #
    # `sparse_version_statistics` is the per-version document total: the IDF numerator. It is
    # keyed `(organization_id, source_version_id, analyzer)` — identical to the frequencies —
    # because the number is meaningless outside the scope they are summed over, and because
    # the column it would otherwise live on belongs to a table that does not exist here.
    "sparse_version_statistics",
    # `sparse_term_frequencies` is the per-term document frequency under one analyzer. The
    # analyzer is part of the key: term ids are `blake2b(term)` under a fixed personalization,
    # so two analyzers are two id spaces over the same text and summing across them yields a
    # number that is not a document frequency of anything, with nothing raised.
    "sparse_term_frequencies",
)

# TODO(ingestion-engineer / retrieval-engineer / rag-eval-engineer): the psycopg statements
# land here. Every one of them:
#   * targets a name in ALLOWED_TABLES and nothing else;
#   * carries org_id on the row, because a derived table is still tenant data;
#   * uses parameter binding, never string interpolation, even for a value we produced;
#   * issues no DDL of any kind — schema changes are Laravel migrations, always.

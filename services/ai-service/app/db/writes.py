"""The only module in this service that issues SQL.

This service writes the PostgreSQL tables named in ``ALLOWED_TABLES`` below, and owns **no**
migration. They are derived, rebuildable artifacts whose schema lives in Laravel's
migrations: we write rows into a schema we do not define. **Read the tuple for the list.**
This docstring does not restate it, or its length, and neither should anything else
(ADR-036): a sentence carrying a count goes false without a diff ever touching it. The copies
in ``infrastructure/docker/env/ai-service.env.example`` and
``infrastructure/docker/postgres/initdb/00-extensions.sql`` were the worked example — they
still read "exactly four" long after the tuple had grown, and on 2026-08-20 they were retired
rather than corrected, because correcting a restated count only resets the clock on it.

*Any* name added here is a review stop, not a refactor, and there is no arithmetic to appeal
to. **The invariant is the three properties every name on the list has**, each a question
about that specific table and answerable on its own:

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
``source_versions``.

The argument this docstring once made from ``source_versions`` **not existing here** is no
longer available, and it was never the reason. It does exist: Phase C1 landed
``services/core-api/database/migrations/2026_08_20_002000_create_source_versions_table.php``
on 2026-08-20. The design is unchanged, because the grounds are the key and the ownership.

* **The analyzer.** ``source_versions`` has one row per version, so a column on it can hold
  one number per version — while this total is per version *and per analyzer*. A column would
  either collapse the two analyzers' totals into one or drag ``SPARSE_ANALYZER_VERSION`` into
  the lifecycle table's key. The collapse is the dangerous half, precisely because the count
  genuinely does not depend on the analyzer: the two rows carry equal values, so an
  analyzer-blind read returns twice a plausible-looking number, and no fixture that seeds both
  analyzers can distinguish a bound read from an unbound one by inspecting a single result.
  The primary key on ``sparse_version_statistics`` is that argument, made in the migration
  that creates it.
* **Whose table it is.** ``source_versions`` is `kb-source-lifecycle`'s and its schema is
  Laravel's, so hanging the IDF numerator on it would put a lifecycle migration inside a
  change about BM25 — property 3 above, from the other direction. That migration already
  refuses a column of this shape on its own account: its "what is not on this table" note
  declines ``chunk_count`` because a count over rows the data plane writes would be a second
  number that can disagree with the first while both look authoritative. The IDF numerator is
  that shape exactly.

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

There is deliberately no ORM and no migration tool under ``app/``: schema is Laravel's, and a
second migration authority against a schema we do not own is what that rules out.

**Nothing in this repository enforces it.** It used to — a ``.github/workflows/gates.yml`` job
grepped this package case-insensitively for ``create table``/``alter table``/``drop table``
and for the names of SQLAlchemy, SQLModel, Tortoise and Alembic — but ``.github/`` was deleted
on 2026-08-17 and nothing replaced it, so the rule now holds by review alone: an ``import
alembic`` under ``app/`` would land here with nothing objecting. ``scripts/security/rules/``
does carry a semgrep rule matching those imports, and it is not a backstop — as of 2026-08-20
no Makefile target, script or workflow in this repository invokes semgrep (finding **F7**), so
that rule records the intent rather than holding it. Do not trust this paragraph either:
``ls .github`` and ``git grep -l semgrep`` are the two measurements, and a paragraph
describing a gate is exactly the kind that goes stale in place.

The gate's other legacy is a writing rule that is no longer real. Its grep had no
``--include`` filter, so a docstring or a comment naming one of those tools failed the build
exactly as an import would, and any explanation that needed to name one was pushed out to
``services/ai-service/README.md``. That constraint is gone — the paragraph above names four of
them. The README still describes the gate in the present tense, so read its "things CI will
fail you for" section as reasoning, not as a live enforcement claim.
"""

from __future__ import annotations

from typing import Final

__all__ = ["ALLOWED_TABLES"]

#: The complete write allow-list, and the single place the list exists — do not restate it, or
#: its length, in a docstring, a comment, a config file or a test (ADR-036).
#:
#: **Nothing checks that a statement's target is on it, and nothing checks what joins it.** A
#: `.github/workflows/gates.yml` job used to import this module and compare; `.github/` was
#: deleted on 2026-08-17 and nothing replaced it. So adding a name below changes what the data
#: plane is permitted to write, in a diff that will go green, and the only thing between a wrong
#: name and production is a reviewer working through the three properties in the module
#: docstring above. Treat a change to this tuple as the review stop it is — there is no second
#: chance further down the pipe.
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

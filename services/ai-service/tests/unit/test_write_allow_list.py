"""``app/db/writes.py``'s allow-list, and the property that decides what may join it.

The list is short on purpose and CI counts it, but a count is not the invariant — it is only
what makes a violation visible in a diff. The invariant is that every name on it is a
**derived, rebuildable artifact whose schema Laravel owns and whose rows the public API never
touches**. A data-plane write outside that set lands beside Laravel's own writer with no
policy, no audit row and no framework-applied tenant scope, and it fails nowhere: the row is
simply there.

Finding **C2** added the two sparse-corpus-statistics tables under exactly that property, and
what this file pins is the pair-ness and the cross-plane spelling — the two things that are
invisible in review and silent at runtime.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Final

import pytest

from app.db.writes import ALLOWED_TABLES
from tests.support.tree import SERVICE_ROOT

#: ``services/core-api/database/migrations``. The data plane owns no migration, so every table
#: it writes has its schema defined over here — "we write rows into a schema we do not define".
MIGRATIONS: Final[Path] = SERVICE_ROOT.parent / "core-api" / "database" / "migrations"

_CREATE_TABLE = re.compile(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\"?([a-z_][a-z0-9_]*)", re.I)

#: The C2 pair. Named here because the *relationship* between them is what the test is about,
#: and the relationship cannot be read off the tuple.
SPARSE_TABLES: Final[tuple[str, str]] = (
    "sparse_version_statistics",
    "sparse_term_frequencies",
)


def _migrated_tables() -> set[str]:
    return {
        match.group(1).lower()
        for path in MIGRATIONS.glob("*.php")
        for match in _CREATE_TABLE.finditer(path.read_text(encoding="utf-8"))
    }


def test_the_migration_scan_found_something() -> None:
    """Positive control. Every cross-plane assertion below is "name X was created over there",
    and all of them pass trivially against an empty set if the path or the glob is wrong."""
    assert MIGRATIONS.is_dir(), f"{MIGRATIONS} is not a directory"
    assert len(_migrated_tables()) >= 3, sorted(_migrated_tables())


# ── the sparse arm needs BOTH tables, and the second is not a convenience ─────


@pytest.mark.parametrize("table", SPARSE_TABLES)
def test_the_sparse_statistics_tables_are_writable(table: str) -> None:
    """C2 restored the sparse retrieval arm with locally computed BM25.

    ADR-030 removed the sparse producer along with local model inference; CLAUDE.md permits
    this one back explicitly, because a statistical ranking function is not a model. BM25 needs
    corpus statistics, and ``app/retrieval/sparse.py`` splits the formula so that every
    corpus-dependent quantity sits on the query side — which is what keeps the Qdrant index
    rebuildable and keeps one tenant's statistics out of another's points. These two tables are
    where that side reads from, and the data plane is what computes them.
    """
    assert table in ALLOWED_TABLES


def test_the_frequencies_table_never_travels_without_the_version_total() -> None:
    """Two entries, not one. The document total cannot live anywhere else.

    It is the numerator of the IDF formula and is meaningless outside the scope the frequencies
    are summed over, so it is keyed identically — ``(organization_id, source_version_id,
    analyzer)``. The obvious alternative, a column on ``source_versions``, is unavailable in a
    stronger sense than "we chose not to": **``source_versions`` does not exist in this
    repository**, no migration creates it, and inventing it to hang a counter on would put a
    table `kb-source-lifecycle` owns into a change about BM25.

    Allowing only the frequencies would therefore not be a smaller version of this change. It
    would be an IDF with no numerator, discovered at the first sparse query.
    """
    totals, frequencies = SPARSE_TABLES
    if frequencies in ALLOWED_TABLES:
        assert totals in ALLOWED_TABLES, (
            f"{frequencies} is writable but {totals} is not. The per-version document total is "
            f"the IDF numerator and is keyed identically to the frequencies; it cannot live on "
            f"source_versions, which no migration in this repository creates."
        )


def test_every_sparse_table_on_the_allow_list_is_created_by_a_laravel_migration() -> None:
    """The data plane owns no migration, so an allow-listed name it cannot find over there is
    a table it would write into nothing.

    Read out of ``ALLOWED_TABLES`` and not from this file's own constant, deliberately: the
    failure being caught is a **spelling** one, and a test that names both sides itself cannot
    see it. A singular/plural slip or an ``_stats`` for ``_statistics`` reads as correct on
    each side alone and fails only at the first ``INSERT``, inside a Celery task, after the
    parse and the OCR spend.

    Scoped to the ``sparse_`` prefix because the four older names — ``chunks``,
    ``document_elements``, ``retrieval_traces``, ``evaluation_results`` — have **no migration
    in this repository yet**. That is a real gap and it is reported rather than papered over
    here; widening this assertion to the whole allow-list today would only fail on somebody
    else's unfinished work.
    """
    allowed_sparse = [name for name in ALLOWED_TABLES if name.startswith("sparse_")]
    assert allowed_sparse, "the C2 tables left ALLOWED_TABLES; this assertion is now vacuous"

    missing = [name for name in allowed_sparse if name not in _migrated_tables()]
    assert not missing, (
        f"{missing} are in ALLOWED_TABLES but no migration in {MIGRATIONS} creates them"
    )


# ── properties of the list itself ────────────────────────────────────────────


def test_source_versions_is_not_writable_from_here() -> None:
    """The lifecycle table is Laravel's, and the pointer on it decides which version is live.

    Two writers on ``source_items.current_version_id`` turn a lifecycle bug into an
    ``IntegrityError`` inside a Celery task with no way to make progress. The data plane
    reports counts, checksum and readiness on the ingestion callback; Laravel activates.
    """
    for forbidden in ("source_versions", "source_items", "knowledge_sources"):
        assert forbidden not in ALLOWED_TABLES


def test_the_allow_list_is_a_set_of_plain_identifiers() -> None:
    """A duplicate would make CI's cardinality check disagree with the effective allow-list,
    and anything that is not a bare lowercase identifier is a hint that a schema, a quote or an
    interpolation has crept into a name the gate greps for literally."""
    assert len(set(ALLOWED_TABLES)) == len(ALLOWED_TABLES), ALLOWED_TABLES
    for name in ALLOWED_TABLES:
        assert re.fullmatch(r"[a-z][a-z0-9_]*", name), name

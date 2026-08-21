"""``app/db/writes.py``'s allow-list, and the property that decides what may join it.

The list is short on purpose, but its length was never the invariant and nothing counts it any
more: the CI job that did was deleted with ``.github/`` on 2026-08-17, so a count in prose is now
a number with no checker behind it (ADR-036). The invariant is that every name on it is a
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
from tests.support.tree import APP_ROOT, SERVICE_ROOT

#: ``services/core-api/database/migrations``. The data plane owns no migration, so every table
#: it writes has its schema defined over here — "we write rows into a schema we do not define".
MIGRATIONS: Final[Path] = SERVICE_ROOT.parent / "core-api" / "database" / "migrations"

_CREATE_TABLE = re.compile(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\"?([a-z_][a-z0-9_]*)", re.I)

#: The statement forms that make property 3 *bite*. A name with no migration and no statement
#: is a placeholder; a name with no migration and a statement is finding #79's exact shape.
_WRITES_TO = re.compile(r"\b(?:DELETE\s+FROM|INSERT\s+INTO|UPDATE)\s+\"?([a-z_][a-z0-9_]*)", re.I)

#: The C2 pair. Named here because the *relationship* between them is what the test is about,
#: and the relationship cannot be read off the tuple.
SPARSE_TABLES: Final[tuple[str, str]] = (
    "sparse_version_statistics",
    "sparse_term_frequencies",
)

#: Allow-listed names that **no Laravel migration in this repository creates**, each excused
#: because nothing here writes it yet.
#:
#: This is an exception list, not a permission. It replaced a ``name.startswith("sparse_")``
#: filter, which was a *proxy* for "we expect this one to have a migration" and had the failure
#: mode every proxy has: it could not tell a name that legitimately has no migration from one
#: that lost its migration, and it silently stopped covering ``chunks`` and
#: ``document_elements`` on the day Phase C1 gave them one.
#:
#: **The list is self-expiring, by the three independent paths ``KB_MIGRATION_PIN_79`` had** —
#: which is the shape that would have caught the C1 drift on the day it landed instead of eight
#: days later. Each path is a test below:
#:
#: 1. an excused name that **gains a migration** fails (``…_no_longer_needs_its_excuse``);
#: 2. an excused name that **gains a writer** fails (``…_is_written_by_nothing``) — that is
#:    ADR-033 property 3 actually biting, and the reason a bare "no migration yet" is not by
#:    itself a violation;
#: 3. an excused name that **leaves ALLOWED_TABLES** fails the closing set comparison
#:    (``…_is_still_on_the_allow_list``).
#:
#: Do not add a name here to make a red test green. A name belongs here only while all three
#: paths are honest about it, and adding one is the same review stop that adding one to
#: ``ALLOWED_TABLES`` is.
NO_MIGRATION_YET: Final[frozenset[str]] = frozenset(
    {
        # Per-query retrieval diagnostics. `app/retrieval/` holds no statement against it and
        # `app/deletion/tasks.py` only names it in prose, describing the redaction it will
        # eventually need on `selected_evidence`.
        "retrieval_traces",
        # Per-case evaluation detail. `app/evaluation/run.py` and `tasks.py` describe the row
        # they will write; `evaluation/`'s orchestration is a deliberate stub (CLAUDE.md), so
        # the writer does not exist and neither does the schema.
        "evaluation_results",
    }
)


def _migrated_tables() -> set[str]:
    return {
        match.group(1).lower()
        for path in MIGRATIONS.glob("*.php")
        for match in _CREATE_TABLE.finditer(path.read_text(encoding="utf-8"))
    }


def _tables_written_by_this_service() -> dict[str, set[str]]:
    """Every table name this service issues a DML statement against, to the file it is in.

    A source scan and not an import: the modules that hold these statements pull in psycopg,
    Celery and a populated environment, and the ``unit/`` tier runs on a bare interpreter.
    Prose is not a false positive here — a docstring saying "its ``evaluation_results`` row"
    does not contain ``INSERT INTO evaluation_results``, and the positive control below is
    what keeps that claim honest rather than assumed.
    """
    found: dict[str, set[str]] = {}

    for path in APP_ROOT.rglob("*.py"):
        for match in _WRITES_TO.finditer(path.read_text(encoding="utf-8")):
            found.setdefault(match.group(1).lower(), set()).add(str(path.relative_to(SERVICE_ROOT)))

    return found


def test_the_migration_scan_found_something() -> None:
    """Positive control. Every cross-plane assertion below is "name X was created over there",
    and all of them pass trivially against an empty set if the path or the glob is wrong."""
    assert MIGRATIONS.is_dir(), f"{MIGRATIONS} is not a directory"
    assert len(_migrated_tables()) >= 3, sorted(_migrated_tables())


def test_the_statement_scan_found_something() -> None:
    """The second positive control, and it is the one that is easy to leave out.

    ``_tables_written_by_this_service()`` is used *negatively* — "no excused name appears in
    it" — so a broken regex, a moved ``app/`` or a changed statement style yields an empty
    mapping, and an empty mapping satisfies every negative assertion made against it. Then the
    self-expiring exception list stops expiring and nothing anywhere is red.

    The floor is stated against ``ALLOWED_TABLES`` rather than as a literal: the four names
    ``app/deletion/relational.py`` purges are all allow-listed, so a scan that finds fewer
    distinct allow-listed names than it did has lost sight of some of them.
    """
    written = _tables_written_by_this_service()
    allow_listed = sorted(set(written) & set(ALLOWED_TABLES))

    assert len(allow_listed) >= 4, (
        f"the DML scan over {APP_ROOT} found statements against only {allow_listed}. It is used "
        f"to decide whether an excused name has gained a writer, and that decision is vacuous "
        f"against an empty scan. Everything it found: {sorted(written)}"
    )


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
    analyzer)``.

    This docstring used to rule out the obvious alternative — a column on ``source_versions`` —
    by saying that no such table existed here. That premise expired on 2026-08-20, when Phase C1
    landed ``2026_08_20_002000_create_source_versions_table.php``, and it was never the reason.
    The reasons are the key and the ownership. ``source_versions`` holds one row per version, so
    a column on it holds one number per version, while this total is per version *and per
    analyzer*; a column would either collapse the two analyzers' totals or drag
    ``SPARSE_ANALYZER_VERSION`` into the lifecycle table's key. The collapse is the dangerous
    half, because the count genuinely does not depend on the analyzer — the two rows carry equal
    values, so an analyzer-blind read returns twice a plausible number and no single result
    distinguishes a bound read from an unbound one. And the table is `kb-source-lifecycle`'s with
    a schema Laravel owns, so hanging the IDF numerator on it puts a lifecycle migration inside a
    change about BM25. That migration refuses a column of this shape from its own side, too: its
    "what is not on this table" note declines ``chunk_count`` because a count over rows the data
    plane writes would be a second number that can disagree with the first while both look
    authoritative.

    Allowing only the frequencies would therefore not be a smaller version of this change. It
    would be an IDF with no numerator, discovered at the first sparse query.
    """
    totals, frequencies = SPARSE_TABLES
    if frequencies in ALLOWED_TABLES:
        assert totals in ALLOWED_TABLES, (
            f"{frequencies} is writable but {totals} is not. The per-version document total is "
            f"the IDF numerator and is keyed identically to the frequencies; it cannot move to "
            f"source_versions, which has one row per version while this total is per version AND "
            f"per analyzer, and whose schema Laravel owns."
        )


def test_every_allow_listed_table_is_created_by_a_laravel_migration() -> None:
    """ADR-033 property 3, per name: the data plane owns no migration, so an allow-listed name
    it cannot find over there is a table it would write into nothing.

    Read out of ``ALLOWED_TABLES`` and not from this file's own constant, deliberately: the
    failure being caught is a **spelling** one, and a test that names both sides itself cannot
    see it. A singular/plural slip or an ``_stats`` for ``_statistics`` reads as correct on
    each side alone and fails only at the first ``INSERT``, inside a Celery task, after the
    parse and the OCR spend.

    This used to be scoped to a ``sparse_`` name prefix. The prefix was a *proxy* for "we
    expect this one to have a migration", and it went stale the way proxies do: Phase C1 gave
    ``chunks`` and ``document_elements`` migrations on 2026-08-20 and the assertion went on not
    covering them, so it could not have noticed either one losing its migration again. The
    scope is now the whole allow-list minus a named, self-expiring exception list.
    """
    migrated = _migrated_tables()
    expected = [name for name in ALLOWED_TABLES if name not in NO_MIGRATION_YET]

    assert expected, (
        "every allow-listed name is excused, so this assertion covers nothing. Either "
        "ALLOWED_TABLES was emptied or NO_MIGRATION_YET has swallowed it."
    )

    missing = [name for name in expected if name not in migrated]
    assert not missing, (
        f"{missing} are in ALLOWED_TABLES but no migration in {MIGRATIONS} creates them, and "
        f"they are not excused in NO_MIGRATION_YET. Either the migration was removed or renamed, "
        f"or the name is spelled differently on the two sides. Adding the name to "
        f"NO_MIGRATION_YET is not the fix unless nothing here writes it."
    )


@pytest.mark.parametrize("table", sorted(NO_MIGRATION_YET))
def test_an_excused_table_no_longer_needs_its_excuse(table: str) -> None:
    """Self-expiry path 1: an excused name that GAINS a migration fails the build.

    This is the half that would have caught the drift of 2026-08-20 on the day it landed. The
    excuse is "Laravel has not defined this schema yet"; the moment Laravel does, the name
    belongs under the assertion above and leaving it excused silently narrows the coverage
    again — which is exactly how the ``sparse_`` prefix stopped covering ``chunks``.
    """
    created_by = sorted(
        path.name
        for path in MIGRATIONS.glob("*.php")
        for match in _CREATE_TABLE.finditer(path.read_text(encoding="utf-8"))
        if match.group(1).lower() == table
    )

    assert not created_by, (
        f"{table} is excused in NO_MIGRATION_YET but {created_by} creates it. The excuse has "
        f"expired: remove the name from NO_MIGRATION_YET so the property-3 assertion covers it."
    )


@pytest.mark.parametrize("table", sorted(NO_MIGRATION_YET))
def test_an_excused_table_is_written_by_nothing(table: str) -> None:
    """Self-expiry path 2, and the one that is the actual violation rather than a bookkeeping
    slip. **A name nothing writes yet is not a property-3 violation** — the property bites
    where a statement exists, which is precisely finding #79's shape: the data plane issued
    ``DELETE FROM chunks`` and ``DELETE FROM document_elements`` while no Laravel migration
    created either, and it stayed true for eight days.

    So an excused name is allowed to have no migration only while it also has no writer. The
    day one of these gets its first statement, this fails and the answer is a migration, not a
    wider excuse.
    """
    written = _tables_written_by_this_service()

    assert table not in written, (
        f"{table} has no Laravel migration and {sorted(written[table])} issues a statement "
        f"against it. That is ADR-033 property 3 violated — finding #79's exact shape under a "
        f"new name. The repair is the migration; do not repair it here."
    )


def test_every_excused_table_is_still_on_the_allow_list() -> None:
    """Self-expiry path 3: the closing set comparison ``KB_MIGRATION_PIN_79`` also had.

    A name removed from ``ALLOWED_TABLES`` but left here is an exception protecting nothing,
    and it makes the list read as though more is outstanding than is. Membership of
    ``ALLOWED_TABLES`` is a review decision and is not this file's to change — but an excuse
    for a name that is no longer on it is this file's to delete.
    """
    orphans = sorted(NO_MIGRATION_YET - set(ALLOWED_TABLES))

    assert not orphans, (
        f"{orphans} are excused in NO_MIGRATION_YET but are no longer in ALLOWED_TABLES. The "
        f"exception outlived the entry it was written for; delete it."
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
    """Both halves outlived the gate that used to be their justification.

    A duplicate makes ``len(ALLOWED_TABLES)`` disagree with the effective allow-list. Nothing
    compares those two numbers any more — the cardinality check went with ``.github/`` on
    2026-08-17 — but a human reading the tuple to decide whether a name belongs is now the only
    check there is, and a repeated name is exactly the kind of thing that reads as two reviewed
    entries. Anything that is not a bare lowercase identifier is a hint that a schema qualifier,
    a quote or an interpolation has crept into a name, which matters more without a gate rather
    than less: these strings are spliced into SQL, and the module docstring's promise that no
    value is ever interpolated has to hold for the table name too."""
    assert len(set(ALLOWED_TABLES)) == len(ALLOWED_TABLES), ALLOWED_TABLES
    for name in ALLOWED_TABLES:
        assert re.fullmatch(r"[a-z][a-z0-9_]*", name), name

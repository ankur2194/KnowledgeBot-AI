"""The sparse-corpus-statistics purge, executed against a real PostgreSQL, with a tenant that must
survive it.

`tests/unit/test_relational_purge_plan.py` proves the plan *names* the two tables. This proves the
statements do what the names claim, and it is the half that cannot be faked: the rows are seeded,
counted, deleted and counted again, and a second organization holding rows under the **same
`source_version_id` string** is asserted intact afterwards. That collision is the whole fixture
design. Version ids are ULIDs and never really collide, so a test using distinct ids per org would
survive a delete with no `organization_id` predicate at all — it would prove nothing about filter
width, which is the failure mode that actually destroys data here.

The schema is not invented. Both `CREATE TABLE` statements are extracted from Laravel's own
migration and executed verbatim, so a column renamed over there fails here rather than at the first
statement inside a Celery task. The data plane owns no migration and this test does not give it one:
it reads the control plane's.

`organizations` is created as a bare id table because both sparse tables carry a real foreign key to
it, and that key is `ON DELETE RESTRICT` — which is itself asserted below, because "residue refuses
the organization delete" is a claim about this schema and not about our intentions.
"""

from __future__ import annotations

import re
from collections.abc import Iterator, Mapping
from pathlib import Path
from typing import Any, Final

import psycopg
import pytest

from app.deletion.relational import RELATIONAL_PURGE_ORDER, organization_scope, version_scope
from app.deletion.verification import failed_relational_stores
from tests.support.tree import SERVICE_ROOT

pytestmark = pytest.mark.integration

MIGRATION: Final[Path] = (
    SERVICE_ROOT.parent
    / "core-api"
    / "database"
    / "migrations"
    / "2026_08_07_000600_create_sparse_corpus_statistics_tables.php"
)

#: Two organizations, and the version ids they SHARE. Org B is not a decoration: a deletion test
#: with one tenant in it cannot fail on filter width, and filter width is the one mistake in this
#: file with no repair.
ORG_A: Final[str] = "org_aaaaaaaaaaaaaaaaaaaaaa"
ORG_B: Final[str] = "org_bbbbbbbbbbbbbbbbbbbbbb"
PURGED_VERSION: Final[str] = "ver_11111111111111111111"
KEPT_VERSION: Final[str] = "ver_22222222222222222222"

#: An analyzer bump writes new rows beside the old ones and the old ones keep serving the versions
#: indexed under them, so one version legitimately holds rows under both. A purge narrowed to the
#: analyzer the worker happens to be running would strand the other set — and the proof, narrowed
#: the same way, would not see it either.
ANALYZERS: Final[tuple[str, str]] = ("bm25-v1", "bm25-v2")

#: Term rows per (org, version, analyzer). Small, distinct per scope so a count identifies which
#: scope survived rather than only how many rows did.
TERMS_PER_SCOPE: Final[int] = 5

SPARSE_STEPS = tuple(step for step in RELATIONAL_PURGE_ORDER if step.table.startswith("sparse_"))


def _migration_ddl() -> list[str]:
    """The two `CREATE TABLE` statements, verbatim, out of Laravel's migration heredocs."""
    text = MIGRATION.read_text(encoding="utf-8")
    blocks = re.findall(r"<<<'SQL'\n(.*?)\n\s*SQL\)", text, re.S)
    creates = [block for block in blocks if "CREATE TABLE" in block]
    assert len(creates) == 2, f"expected two CREATE TABLE heredocs in {MIGRATION}, got {blocks}"
    return creates


@pytest.fixture(scope="module")
def sparse_db(pg_dsn: str, resource_suffix: str) -> Iterator[str]:
    """A dedicated schema holding Laravel's two tables, plus the FK target they reference.

    Per worker, because a schema shared between xdist workers is how one worker's purge empties
    another's fixture and the failure reads as flaky isolation.
    """
    schema = f"kb_purge_{resource_suffix}".lower()[:60]
    with psycopg.connect(pg_dsn, autocommit=True) as conn:
        conn.execute(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')
        conn.execute(f'CREATE SCHEMA "{schema}"')
    dsn = f"{pg_dsn}?options=-c%20search_path%3D{schema}"
    with psycopg.connect(dsn, autocommit=True) as conn:
        conn.execute('CREATE TABLE organizations (id char(26) COLLATE "C" PRIMARY KEY)')
        for statement in _migration_ddl():
            conn.execute(statement)  # type: ignore[arg-type]
    yield dsn
    with psycopg.connect(pg_dsn, autocommit=True) as conn:
        conn.execute(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')


@pytest.fixture
def seeded(sparse_db: str) -> Iterator[psycopg.Connection[Any]]:
    """Both organizations, both versions, both analyzers — reseeded for every test.

    Reseeded rather than shared because half these tests delete rows, and a purge test that
    inherits another test's leftovers is one whose assertions depend on ordering.
    """
    with psycopg.connect(sparse_db, autocommit=True) as conn:
        conn.execute("TRUNCATE sparse_term_frequencies, sparse_version_statistics, organizations")
        for org in (ORG_A, ORG_B):
            conn.execute("INSERT INTO organizations (id) VALUES (%s)", (org,))
            for version in (PURGED_VERSION, KEPT_VERSION):
                for analyzer in ANALYZERS:
                    conn.execute(
                        "INSERT INTO sparse_version_statistics "
                        "(organization_id, source_version_id, analyzer, document_total) "
                        "VALUES (%s, %s, %s, %s)",
                        (org, version, analyzer, 42),
                    )
                    for offset in range(TERMS_PER_SCOPE):
                        conn.execute(
                            "INSERT INTO sparse_term_frequencies "
                            "(organization_id, source_version_id, analyzer, term_id, "
                            "document_frequency) VALUES (%s, %s, %s, %s, %s)",
                            (org, version, analyzer, 1_000 + offset, 3),
                        )
        yield conn


def _count(conn: psycopg.Connection[Any], table: str, org: str, version: str) -> int:
    step = next(s for s in SPARSE_STEPS if s.table == table)
    row = conn.execute(step.count, version_scope(org, [version])).fetchone()
    assert row is not None
    return int(row[0])


def _purge(conn: psycopg.Connection[Any], org: str, versions: list[str]) -> dict[str, int]:
    """Issue the plan's sparse deletes in plan order and return the rowcount per table.

    The tallies are what reaches the audit entry and the verification record: identifiers and
    counts, never contents.
    """
    params = version_scope(org, versions)
    return {step.table: conn.execute(step.delete, params).rowcount for step in SPARSE_STEPS}


# ── positive control: the fixture seeds what the assertions claim ────────────


def test_the_fixture_seeded_rows_in_every_scope(seeded: psycopg.Connection[Any]) -> None:
    """Without this, every "is gone" assertion below passes against a fixture that never wrote a
    row — which is worse than no test, because it reports the gap as closed."""
    for org in (ORG_A, ORG_B):
        for version in (PURGED_VERSION, KEPT_VERSION):
            assert _count(seeded, "sparse_version_statistics", org, version) == len(ANALYZERS)
            assert _count(seeded, "sparse_term_frequencies", org, version) == (
                TERMS_PER_SCOPE * len(ANALYZERS)
            )


# ── the purge ────────────────────────────────────────────────────────────────


def test_purging_a_version_removes_its_rows_under_every_analyzer(
    seeded: psycopg.Connection[Any],
) -> None:
    """The gap, closed and measured. Both tables, both analyzers, zero rows left."""
    tallies = _purge(seeded, ORG_A, [PURGED_VERSION])
    assert tallies == {
        "sparse_term_frequencies": TERMS_PER_SCOPE * len(ANALYZERS),
        "sparse_version_statistics": len(ANALYZERS),
    }
    assert _count(seeded, "sparse_term_frequencies", ORG_A, PURGED_VERSION) == 0
    assert _count(seeded, "sparse_version_statistics", ORG_A, PURGED_VERSION) == 0


def test_the_other_organizations_identically_keyed_rows_survive(
    seeded: psycopg.Connection[Any],
) -> None:
    """Org B holds rows under the SAME `source_version_id`. Only `organization_id` separates them,
    which is exactly the point: drop that predicate and this is the assertion that goes red, while
    every "is gone" assertion above stays green."""
    _purge(seeded, ORG_A, [PURGED_VERSION])
    assert _count(seeded, "sparse_term_frequencies", ORG_B, PURGED_VERSION) == (
        TERMS_PER_SCOPE * len(ANALYZERS)
    )
    assert _count(seeded, "sparse_version_statistics", ORG_B, PURGED_VERSION) == len(ANALYZERS)


def test_the_same_organizations_other_version_survives(
    seeded: psycopg.Connection[Any],
) -> None:
    """A source purge resolves *its* versions. Widening to the organization to make a purge finish
    would take every other source in the tenant with it, and nothing would raise."""
    _purge(seeded, ORG_A, [PURGED_VERSION])
    assert _count(seeded, "sparse_term_frequencies", ORG_A, KEPT_VERSION) == (
        TERMS_PER_SCOPE * len(ANALYZERS)
    )
    assert _count(seeded, "sparse_version_statistics", ORG_A, KEPT_VERSION) == len(ANALYZERS)


def test_the_negative_control_a_statement_without_the_tenant_takes_the_other_org(
    seeded: psycopg.Connection[Any],
    sparse_db: str,
) -> None:
    """Proof that the survival assertion above is not vacuous.

    The same delete with `organization_id = %s AND ` removed — the exact one-clause edit somebody
    makes to "get the purge to finish" — is issued here inside a transaction that is rolled back,
    and it takes org B's rows with it. Run against a throwaway schema, never against a plan
    statement, and rolled back so no later test inherits it.
    """
    widened = SPARSE_STEPS[0].delete.replace("organization_id = %s AND ", "")
    assert widened.count("%s") == 1
    with psycopg.connect(sparse_db) as scratch:
        removed = scratch.execute(widened, ([PURGED_VERSION],)).rowcount
        assert removed == TERMS_PER_SCOPE * len(ANALYZERS) * 2, removed
        scratch.rollback()
    assert _count(seeded, "sparse_term_frequencies", ORG_B, PURGED_VERSION) == (
        TERMS_PER_SCOPE * len(ANALYZERS)
    )


# ── idempotency and partial completion ───────────────────────────────────────


def test_purging_an_already_purged_version_is_a_no_op_and_not_an_error(
    seeded: psycopg.Connection[Any],
) -> None:
    """`acks_late` plus a visibility timeout means this task WILL run again over work it already
    did. "Already absent" is the successful outcome; a purge that raised on it would strand the
    source in `Deleting` and hand the reaper a job it can never finish."""
    _purge(seeded, ORG_A, [PURGED_VERSION])
    again = _purge(seeded, ORG_A, [PURGED_VERSION])
    assert again == {"sparse_term_frequencies": 0, "sparse_version_statistics": 0}


def test_a_crash_between_the_two_statements_leaves_the_documented_residue(
    seeded: psycopg.Connection[Any],
) -> None:
    """Child first, and this is what that buys when the commit boundary sits between them.

    Only `sparse_term_frequencies` is issued — the worker dies before the second statement. What
    remains is one counter per analyzer: bounded, inert, and removable by the retry. Had the order
    been reversed, what remained would be the frequencies with no total, and any read of that scope
    raises `sparse.idf`'s "document frequency … exceeds the scope total" — the message this
    codebase reserves for a statistics set read over a wider scope than its query.
    """
    params = version_scope(ORG_A, [PURGED_VERSION])
    seeded.execute(SPARSE_STEPS[0].delete, params)

    assert _count(seeded, "sparse_term_frequencies", ORG_A, PURGED_VERSION) == 0
    assert _count(seeded, "sparse_version_statistics", ORG_A, PURGED_VERSION) == len(ANALYZERS)


def test_the_retry_after_that_crash_completes_the_purge(
    seeded: psycopg.Connection[Any],
) -> None:
    """The resumption is the whole repair — no manual fix-up, no state rolled back to `Ready`."""
    seeded.execute(SPARSE_STEPS[0].delete, version_scope(ORG_A, [PURGED_VERSION]))
    tallies = _purge(seeded, ORG_A, [PURGED_VERSION])

    assert tallies == {"sparse_term_frequencies": 0, "sparse_version_statistics": len(ANALYZERS)}
    assert _count(seeded, "sparse_version_statistics", ORG_A, PURGED_VERSION) == 0


# ── the proof ────────────────────────────────────────────────────────────────


def test_the_verification_reports_the_table_a_half_finished_purge_left_behind(
    seeded: psycopg.Connection[Any],
) -> None:
    """A store where deletion silently failed must read as a failure, not a pass.

    The tally is built from the plan's own `count` statements — the same predicate the delete used,
    differing only in its verb — so this cannot degrade into asking a question the purge never
    answered.
    """
    seeded.execute(SPARSE_STEPS[0].delete, version_scope(ORG_A, [PURGED_VERSION]))
    tallies = _measured(seeded)

    assert failed_relational_stores(tallies) == ("sparse_version_statistics",)


def test_a_tally_that_omits_the_sparse_tables_is_itself_the_failure(
    seeded: psycopg.Connection[Any],
) -> None:
    """The gap as the proof saw it, before this change.

    A verification handed only the counts it remembered to take reads "clean" over every store it
    skipped. Here the two sparse tables are genuinely full and the tally does not mention them —
    and the answer must still be a failure naming both, because `Deleted` may not be written over
    a store nobody queried.
    """
    partial = {"chunks": 0, "document_elements": 0}

    assert failed_relational_stores(partial) == (
        "sparse_term_frequencies",
        "sparse_version_statistics",
    )


def test_the_verification_passes_only_once_both_tables_are_empty(
    seeded: psycopg.Connection[Any],
) -> None:
    """And the tally is over the *purged* scope only. Org B's surviving rows are not a failure of
    this source's deletion, and a proof that counted them would never pass for anyone."""
    _purge(seeded, ORG_A, [PURGED_VERSION])

    assert failed_relational_stores(_measured(seeded)) == ()


def _measured(conn: psycopg.Connection[Any]) -> dict[str, int]:
    """Every plan table's tally for org A's purged version.

    `chunks` and `document_elements` are reported as zero rather than counted: no migration in this
    repository creates either table, so there is nothing here to query. They are included because
    `failed_relational_stores` treats an omitted table as a failure — correctly — and leaving them
    out would make these assertions about that rule instead of about the sparse purge.
    """
    tallies = {step.table: 0 for step in RELATIONAL_PURGE_ORDER}
    for step in SPARSE_STEPS:
        tallies[step.table] = _count(conn, step.table, ORG_A, PURGED_VERSION)
    return tallies


# ── the schema claim the purge order depends on ──────────────────────────────


def test_residue_refuses_the_organization_delete_rather_than_riding_along_with_it(
    seeded: psycopg.Connection[Any],
) -> None:
    """`ON DELETE RESTRICT` on the organizations edge, asserted against the server rather than read
    off the migration.

    This is what made the gap eventually loud instead of merely large: rows keyed to a version that
    no longer exists refuse the organization row at the very end of an account erasure, as a foreign
    key violation raised by a table nobody remembered writing. The fix for that is the purge, not a
    wider delete.
    """
    with pytest.raises(psycopg.errors.RestrictViolation):
        seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,))

    for version in (PURGED_VERSION, KEPT_VERSION):
        _purge(seeded, ORG_A, [version])
    assert seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,)).rowcount == 1


# ═════════════════════════════════════════════════════════════════════════════
# THE TERMINAL ORGANIZATION SWEEP
# ═════════════════════════════════════════════════════════════════════════════
#
# Everything above purges by a caller-supplied version list. A row whose `source_version_id` is
# not in some such list is unreachable by every code path in this service: `source_version_id`
# carries no foreign key, so no cascade reaches it, and the version list is resolved from
# `source_versions`, which cannot name a version that is already gone.
#
# Left there it does not answer wrong and does not raise — it refuses the `organizations` row at
# the very end of an account erasure, on `ON DELETE RESTRICT`, which arranges a compliance
# obligation to fail at the database rather than to complete. `org_residue_delete` is the terminal
# sweep that closes that, and these are its proofs.
#
# ── FIXTURE DESIGN: EVERY PREDICATE INDIVIDUALLY FALSIFIABLE ─────────────────────────────────
#
# The sweep has exactly one predicate — `organization_id` — and two deliberate absences: no
# version term and no analyzer term. So the fixture has to be able to fail on each of the three
# independently, and a fixture whose values agree across the dimension under test cannot:
#
# * **Organization.** Both organizations hold rows under the SAME `source_version_id` strings and
#   the same analyzers, so `organization_id` is the only thing separating them. Distinct ids per
#   org would let a sweep with no tenant predicate at all pass every assertion here.
# * **Version.** Three versions per org, and the row counts per (version, analyzer) are DISTINCT
#   PRIMES, so every subset of scopes sums to a unique number: a rowcount identifies exactly which
#   scopes a statement touched, not merely how many rows it removed. A fixture with equal counts
#   reports the same total for "removed the orphan" as for "removed the version we kept".
# * **Analyzer.** Each version's two analyzers hold different numbers of rows and different
#   `document_total`s. The migration's own note is the reason: a document total genuinely does not
#   depend on the analyzer, so seeding it identically makes an analyzer-blind read a clean doubling
#   — no row missing, nothing raised.

ORPHAN_VERSION: Final[str] = "ver_33333333333333333333"

#: Rows in `sparse_term_frequencies` per (version, analyzer), **identical for both organizations**
#: and pairwise distinct across scopes. Primes, so no subset sum collides with another: 5 is
#: "the purged version, both analyzers", 24 is "the orphan", 41 is "everything one org holds", and
#: 82 is "both organizations", which is the number a sweep that lost its tenant predicate reports.
TERM_ROWS: Final[Mapping[tuple[str, str], int]] = {
    (PURGED_VERSION, ANALYZERS[0]): 2,
    (PURGED_VERSION, ANALYZERS[1]): 3,
    (KEPT_VERSION, ANALYZERS[0]): 5,
    (KEPT_VERSION, ANALYZERS[1]): 7,
    (ORPHAN_VERSION, ANALYZERS[0]): 11,
    (ORPHAN_VERSION, ANALYZERS[1]): 13,
}

#: The versions a source purge can name. `ORPHAN_VERSION` is deliberately absent: its
#: `source_versions` row is gone, so no resolver will ever return it again.
RESOLVABLE_VERSIONS: Final[tuple[str, str]] = (PURGED_VERSION, KEPT_VERSION)

#: One `sparse_version_statistics` row per (version, analyzer).
STATS_ROWS_PER_ORG: Final[int] = len(TERM_ROWS)


def _terms(*versions: str) -> int:
    """Rows one organization holds across the named versions, both analyzers."""
    return sum(count for (version, _), count in TERM_ROWS.items() if version in versions)


def _stats(*versions: str) -> int:
    return sum(1 for (version, _) in TERM_ROWS if version in versions)


@pytest.fixture
def residue_seeded(sparse_db: str) -> Iterator[psycopg.Connection[Any]]:
    """Both organizations, three versions each — one of which no resolver can name any more.

    Reseeded per test, for the same reason `seeded` is: half of these delete rows, and a sweep test
    that inherits another test's leftovers is one whose assertions depend on ordering.
    """
    with psycopg.connect(sparse_db, autocommit=True) as conn:
        conn.execute("TRUNCATE sparse_term_frequencies, sparse_version_statistics, organizations")
        for org in (ORG_A, ORG_B):
            conn.execute("INSERT INTO organizations (id) VALUES (%s)", (org,))
            for index, ((version, analyzer), rows) in enumerate(TERM_ROWS.items()):
                conn.execute(
                    "INSERT INTO sparse_version_statistics "
                    "(organization_id, source_version_id, analyzer, document_total) "
                    "VALUES (%s, %s, %s, %s)",
                    (org, version, analyzer, 100 + index),
                )
                for offset in range(rows):
                    conn.execute(
                        "INSERT INTO sparse_term_frequencies "
                        "(organization_id, source_version_id, analyzer, term_id, "
                        "document_frequency) VALUES (%s, %s, %s, %s, %s)",
                        (org, version, analyzer, 1_000 + offset, 3),
                    )
        yield conn


def _org_count(conn: psycopg.Connection[Any], table: str, org: str) -> int:
    step = next(s for s in SPARSE_STEPS if s.table == table)
    row = conn.execute(step.org_residue_count, organization_scope(org)).fetchone()
    assert row is not None
    return int(row[0])


def _sweep(conn: psycopg.Connection[Any], org: str) -> dict[str, int]:
    """The terminal sweep: every plan entry's `org_residue_delete`, in plan order.

    Terminal and organization-scoped, so it is issued here and nowhere above: no test of a *source*
    purge may reach it, exactly as no source purge may.
    """
    params = organization_scope(org)
    return {
        step.table: conn.execute(step.org_residue_delete, params).rowcount for step in SPARSE_STEPS
    }


def _org_measured(conn: psycopg.Connection[Any], org: str) -> dict[str, int]:
    """Every plan table's organization-scoped tally. `chunks` and `document_elements` are reported
    as zero rather than counted — no migration in this repository creates either — because
    `failed_relational_stores` treats an omitted table as a failure and leaving them out would make
    these assertions about that rule instead of about the sweep."""
    tallies = {step.table: 0 for step in RELATIONAL_PURGE_ORDER}
    for step in SPARSE_STEPS:
        tallies[step.table] = _org_count(conn, step.table, org)
    return tallies


# ── positive control ─────────────────────────────────────────────────────────


def test_the_residue_fixture_seeded_every_scope_in_both_organizations(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """Without this, every "is gone" assertion below passes against a fixture that never wrote a
    row, and the gap gets reported as closed."""
    for org in (ORG_A, ORG_B):
        assert _org_count(residue_seeded, "sparse_term_frequencies", org) == _terms(
            PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION
        )
        assert _org_count(residue_seeded, "sparse_version_statistics", org) == STATS_ROWS_PER_ORG
    assert _terms(PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION) == 41


# ── the gap, measured ────────────────────────────────────────────────────────


def test_the_version_scoped_purge_cannot_reach_a_version_nothing_resolves(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """The finding, as data rather than as an argument.

    Every version a resolver can still return is purged here — this is the widest legitimate source
    purge there is — and the orphan's rows are untouched afterwards. They are not left behind by a
    bug in the statement; they are unreachable by its *shape*, because it names its rows through a
    list that can no longer contain them.
    """
    removed = _purge(residue_seeded, ORG_A, list(RESOLVABLE_VERSIONS))
    assert removed == {
        "sparse_term_frequencies": _terms(*RESOLVABLE_VERSIONS),
        "sparse_version_statistics": _stats(*RESOLVABLE_VERSIONS),
    }

    assert _org_count(residue_seeded, "sparse_term_frequencies", ORG_A) == _terms(ORPHAN_VERSION)
    assert _org_count(residue_seeded, "sparse_version_statistics", ORG_A) == _stats(ORPHAN_VERSION)


def test_the_sweep_removes_exactly_what_no_source_could_name(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """The terminal sweep, in its intended position: after the fan-out has taken everything it can.

    The rowcounts are the evidence and they are the point — 24 and 2 are the measured size of the
    residue, and on a healthy organization they would be zero. A non-zero tally here is recorded as
    a finding even though the run passes.
    """
    _purge(residue_seeded, ORG_A, list(RESOLVABLE_VERSIONS))
    swept = _sweep(residue_seeded, ORG_A)

    assert swept == {
        "sparse_term_frequencies": _terms(ORPHAN_VERSION),
        "sparse_version_statistics": _stats(ORPHAN_VERSION),
    }
    assert _org_measured(residue_seeded, ORG_A) == dict.fromkeys(
        (step.table for step in RELATIONAL_PURGE_ORDER), 0
    )


def test_the_sweep_leaves_the_other_organization_whole(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """The assertion the whole fixture exists for. Org B holds rows under the SAME version ids,
    under the same analyzers, in the same tables — `organization_id` is the only thing between the
    sweep and all of them, and this is an organization-wide `DELETE` with no narrower term left to
    save it if that one is wrong."""
    _purge(residue_seeded, ORG_A, list(RESOLVABLE_VERSIONS))
    _sweep(residue_seeded, ORG_A)

    assert _org_count(residue_seeded, "sparse_term_frequencies", ORG_B) == _terms(
        PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION
    )
    assert _org_count(residue_seeded, "sparse_version_statistics", ORG_B) == STATS_ROWS_PER_ORG


def test_the_negative_control_a_sweep_without_the_tenant_takes_every_organization(
    residue_seeded: psycopg.Connection[Any],
    sparse_db: str,
) -> None:
    """Proof that the survival assertion above is not vacuous.

    The same statement with ` WHERE organization_id = %s` removed — the one-clause edit that turns
    a tenant sweep into an installation sweep, and the reason `organization_scope` refuses a blank
    tenant — is issued inside a transaction that is rolled back. It reports 82: both organizations,
    every version, both analyzers. 41 would mean one org, 24 the orphan alone; the fixture's
    distinct primes are what make those three outcomes distinguishable from each other.
    """
    widened = SPARSE_STEPS[0].org_residue_delete.replace(" WHERE organization_id = %s", "")
    assert widened.count("%s") == 0

    with psycopg.connect(sparse_db) as scratch:
        removed = scratch.execute(widened).rowcount  # type: ignore[arg-type]
        assert removed == 2 * _terms(PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION) == 82, removed
        scratch.rollback()

    assert _org_count(residue_seeded, "sparse_term_frequencies", ORG_B) == _terms(
        PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION
    )


def test_the_sweep_is_terminal_because_run_early_it_removes_live_knowledge(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """Why the sweep runs third and why nothing but `purge_organization` may call it, measured.

    Issued *before* the per-source fan-out it removes all 41 rows rather than the orphan's 24 — the
    live statistics of every source the tenant has, with no legal-hold check, no retention
    decision, no checkpoint and no per-source proof anywhere in its path. Nothing raises. Ordering
    is the whole of its safety, so the ordering is asserted here rather than described in a
    docstring.
    """
    swept_early = _sweep(residue_seeded, ORG_A)

    assert swept_early["sparse_term_frequencies"] == _terms(
        PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION
    )
    assert swept_early["sparse_term_frequencies"] > _terms(ORPHAN_VERSION)


# ── the proof: the RESTRICT foreign key, not the sweep's own predicate ───────


def test_the_organization_row_is_refused_until_the_sweep_runs_and_accepted_after(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """What "verified" means for a sweep whose predicate is the organization itself.

    Counting `organization_id = %s` after deleting `organization_id = %s` is a tautology — the two
    agree on an answer neither measured, which is exactly what `version_scope` refuses an empty
    version list to prevent. So the load-bearing proof is external and belongs to the schema:
    `organizations` is `ON DELETE RESTRICT` from both tables, so the organization row cannot be
    removed while a single row survives in either.

    Note the middle step. After the widest legitimate source purge the organization is still
    refused — that refusal *is* the compliance failure this change exists to remove, and it is a
    foreign-key violation raised by a table nobody remembered writing.
    """
    with pytest.raises(psycopg.errors.RestrictViolation):
        residue_seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,))

    _purge(residue_seeded, ORG_A, list(RESOLVABLE_VERSIONS))
    with pytest.raises(psycopg.errors.RestrictViolation):
        residue_seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,))

    _sweep(residue_seeded, ORG_A)
    assert residue_seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,)).rowcount == 1


def test_erasing_one_organization_does_not_release_the_others_row(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """The cross-tenant half of the proof above. If the sweep had reached org B, org B's row would
    become deletable — so this assertion fails in exactly the case where the survivor count would
    also fail, and it fails through a completely different mechanism."""
    _sweep(residue_seeded, ORG_A)
    assert residue_seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_A,)).rowcount == 1

    with pytest.raises(psycopg.errors.RestrictViolation):
        residue_seeded.execute("DELETE FROM organizations WHERE id = %s", (ORG_B,))


def test_the_proof_reports_the_table_a_half_finished_sweep_left_behind(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """A store where the sweep silently failed must read as a failure, not a pass — and the tally
    is built from the plan's own `org_residue_count`, the same predicate the sweep used, differing
    only in its verb."""
    residue_seeded.execute(SPARSE_STEPS[0].org_residue_delete, organization_scope(ORG_A))

    assert failed_relational_stores(_org_measured(residue_seeded, ORG_A)) == (
        "sparse_version_statistics",
    )


def test_the_proof_passes_only_once_every_table_is_empty_for_the_organization(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """And it is scoped to the organization being erased. Org B's rows are not a failure of org A's
    erasure, and a proof that counted them would never pass for anyone."""
    _sweep(residue_seeded, ORG_A)
    assert failed_relational_stores(_org_measured(residue_seeded, ORG_A)) == ()
    assert failed_relational_stores(_org_measured(residue_seeded, ORG_B)) == (
        "sparse_term_frequencies",
        "sparse_version_statistics",
    )


# ── idempotency and partial completion ───────────────────────────────────────


def test_sweeping_an_already_swept_organization_is_a_no_op_and_not_an_error(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """`acks_late` plus a visibility timeout means this runs again over work it already did.
    "Already absent" is the successful outcome; a sweep that raised on it would strand the erasure
    and hand the reaper a job it can never finish."""
    _sweep(residue_seeded, ORG_A)
    again = _sweep(residue_seeded, ORG_A)

    assert again == {"sparse_term_frequencies": 0, "sparse_version_statistics": 0}


def test_a_sweep_interrupted_between_its_two_statements_is_completed_by_the_retry(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """Child before parent here too, and the retry is the whole repair — no manual fix-up, and no
    state rolled back. The interrupted run leaves one inert counter per (version, analyzer); the
    retry removes them and reports exactly that number, which is how a resumption stays
    distinguishable from a run that had nothing to do."""
    residue_seeded.execute(SPARSE_STEPS[0].org_residue_delete, organization_scope(ORG_A))

    assert _org_count(residue_seeded, "sparse_term_frequencies", ORG_A) == 0
    assert _org_count(residue_seeded, "sparse_version_statistics", ORG_A) == STATS_ROWS_PER_ORG

    completed = _sweep(residue_seeded, ORG_A)
    assert completed == {
        "sparse_term_frequencies": 0,
        "sparse_version_statistics": STATS_ROWS_PER_ORG,
    }
    assert failed_relational_stores(_org_measured(residue_seeded, ORG_A)) == ()


def test_organization_scope_refuses_a_blank_tenant_before_a_statement_is_issued(
    residue_seeded: psycopg.Connection[Any],
) -> None:
    """The guard sits in front of the statement, not inside it: `organization_id = ''` would match
    nothing and report a clean sweep over an organization it never identified."""
    with pytest.raises(ValueError, match="not scoped at all"):
        _sweep(residue_seeded, "")

    assert _org_count(residue_seeded, "sparse_term_frequencies", ORG_A) == _terms(
        PURGED_VERSION, KEPT_VERSION, ORPHAN_VERSION
    )

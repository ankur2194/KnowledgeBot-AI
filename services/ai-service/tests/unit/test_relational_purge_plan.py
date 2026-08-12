"""`RELATIONAL_PURGE_ORDER` is the list of tables a source purge empties. This is what pins it.

The bug this file exists to prevent has already happened once, in this repository: finding C2 added
`sparse_version_statistics` and `sparse_term_frequencies`, ingestion wrote them, `app/db/writes.py`
admitted them and their Laravel migration documented them as removed *with* the version — and the
purge removed neither, while the verification looked for neither. Nothing answered wrong, because
the sparse read is scoped to the resolved active-version set and a purged version leaves that set
immediately. The only symptom was rows keyed to a `source_version_id` that no longer existed.

That failure is invisible in every place a reviewer looks. It is not a wrong answer, not an error,
not a slow query and not a diff — it is an *absence* in a list. So the assertions below are all of
the same shape: a table the data plane may write, keyed by a version, that does not appear in the
purge plan is a failure here rather than a discovery in six months.

Container-free on purpose. The plan is text and the migration is text; a proof that needs a running
PostgreSQL stops running the day a container is slow, which is the day it is needed. The statements
are executed against a real server in `tests/integration/test_sparse_statistics_purge.py`.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Final

import pytest

from app.db.writes import ALLOWED_TABLES
from app.deletion.relational import (
    RELATIONAL_PURGE_ORDER,
    RelationalPurge,
    organization_scope,
    version_scope,
)
from app.deletion.verification import failed_relational_stores
from tests.support.tree import SERVICE_ROOT

MIGRATIONS: Final[Path] = SERVICE_ROOT.parent / "core-api" / "database" / "migrations"

#: Table -> its `CREATE TABLE` body, read out of Laravel's migrations. The data plane owns no
#: schema, so every column its statements name has to be resolved over there or the statement is
#: correct-looking text that fails at its first execution inside a Celery task.
_CREATE_TABLE = re.compile(
    r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\"?([a-z_][a-z0-9_]*)\"?\s*\((.*?)\n\s*\)",
    re.I | re.S,
)

#: Column names that would make a delete a content match wearing a filter's clothes. Deletion
#: targets identifiers PostgreSQL issued, never anything derived from the material being removed —
#: boilerplate repeats verbatim across documents and across tenants.
CONTENT_ADDRESSED: Final[frozenset[str]] = frozenset(
    {
        "text",
        "content",
        "content_hash",
        "chunk_text",
        "body",
        "title",
        "heading",
        "excerpt",
        "url",
        "name",
        "display_title",
        "checksum",
    }
)


def _migration_bodies() -> dict[str, str]:
    bodies: dict[str, str] = {}
    for path in sorted(MIGRATIONS.glob("*.php")):
        for match in _CREATE_TABLE.finditer(path.read_text(encoding="utf-8")):
            bodies[match.group(1).lower()] = match.group(2)
    return bodies


def _sparse_migration_text() -> str:
    matches = sorted(MIGRATIONS.glob("*create_sparse_corpus_statistics_tables.php"))
    assert len(matches) == 1, f"expected one sparse-statistics migration, found {matches}"
    return matches[0].read_text(encoding="utf-8")


PLAN_TABLES: Final[tuple[str, ...]] = tuple(step.table for step in RELATIONAL_PURGE_ORDER)


# ── positive controls ────────────────────────────────────────────────────────


def test_the_plan_is_not_empty() -> None:
    """Every parametrized assertion below iterates the plan; an empty tuple would pass them all
    by iterating nothing, which is the shape of a suite that has stopped testing."""
    assert RELATIONAL_PURGE_ORDER
    assert len(set(PLAN_TABLES)) == len(PLAN_TABLES), PLAN_TABLES


def test_the_migration_scan_found_something() -> None:
    """The cross-plane assertions are all "this column exists over there", and every one of them
    passes trivially against an empty parse if the glob or the regex is wrong."""
    bodies = _migration_bodies()
    assert MIGRATIONS.is_dir(), f"{MIGRATIONS} is not a directory"
    assert "sparse_term_frequencies" in bodies, sorted(bodies)
    assert "organization_id" in bodies["sparse_term_frequencies"]


# ── the gap: a writable, version-keyed table that nothing purges ──────────────


@pytest.mark.parametrize("table", [t for t in ALLOWED_TABLES if t.startswith("sparse_")])
def test_every_sparse_statistics_table_is_purged(table: str) -> None:
    """The assertion this file was written for.

    Read out of `ALLOWED_TABLES` rather than from a list here, so a *third* sparse rollup admitted
    to the write allow-list arrives already failing this test instead of accumulating quietly for
    however long it takes someone to notice rows keyed to a version that does not exist.

    `retrieval_traces` and `evaluation_results` are deliberately not in scope: they are
    conversation-side, an ordinary source deletion **retains** them, and only a data-subject
    erasure sweeps them — in place, by their own row ids, never by version.
    """
    assert table in PLAN_TABLES, (
        f"{table} is on the data plane's write allow-list and is keyed by source_version_id, but "
        f"no step in RELATIONAL_PURGE_ORDER removes it. Its rows would outlive the version they "
        f"describe, keyed to an id nothing resolves, and the deletion proof would report a clean "
        f"purge over a table it never queried."
    )


@pytest.mark.parametrize("table", PLAN_TABLES)
def test_every_purged_table_is_one_this_service_may_write(table: str) -> None:
    """A purge statement against a table outside the allow-list is a delete landing beside
    Laravel's own writer with no policy check, no audit row and no framework-applied scope — and
    a delete is the direction of that mistake nobody gets to undo."""
    assert table in ALLOWED_TABLES, table


# ── order ────────────────────────────────────────────────────────────────────


def test_the_frequencies_are_removed_before_their_version_total() -> None:
    """Child before parent, and the reason is what a crash between them leaves behind.

    Within the transaction a crash leaves nothing — the step rolls back whole. The order matters
    when the boundary moves: a version with millions of term rows is a batched delete that commits
    per batch. Then a crash after the child leaves one inert counter per analyzer, and a crash
    after the *parent* would leave frequencies with no total, which makes `sparse.idf` raise
    "document frequency … exceeds the scope total" — this codebase's cross-tenant alarm, fired by
    a purge that was merely interrupted.
    """
    order = list(PLAN_TABLES)
    assert order.index("sparse_term_frequencies") < order.index("sparse_version_statistics")


def test_the_sparse_tables_are_removed_after_the_chunks_they_are_derived_from() -> None:
    """Safe only because the version ids are resolved from `source_versions` inside the job, never
    from `chunks`: losing the chunk rows first does not lose the handle on the statistics. If these
    rows were ever keyed off something derived from `chunks`, this order would have to invert."""
    order = list(PLAN_TABLES)
    assert order.index("chunks") < order.index("sparse_term_frequencies")
    assert order.index("chunks") < order.index("sparse_version_statistics")


# ── the shape of every statement ─────────────────────────────────────────────


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_every_statement_is_organization_scoped(step: RelationalPurge) -> None:
    """`org_id` is redundant for correctness once the version ids came from PostgreSQL, and
    mandatory for safety: with it a wrong version id deletes nothing; without it a wrong version id
    deletes another tenant's rows, and there is no repair for that one."""
    for sql in (step.delete, step.count):
        assert "organization_id = %s" in sql, sql


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_every_statement_is_version_scoped_and_binds_exactly_two_parameters(
    step: RelationalPurge,
) -> None:
    """Two placeholders, in the order `version_scope` returns them. A third would mean somebody
    added a predicate the proof and the delete may not share."""
    for sql in (step.delete, step.count):
        assert "source_version_id = ANY(%s)" in sql, sql
        assert sql.count("%s") == 2, sql
        assert sql.index("organization_id = %s") < sql.index("source_version_id = ANY(%s)"), sql


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_the_count_is_the_delete_with_its_verb_changed(step: RelationalPurge) -> None:
    """The proof asks the question the purge answered — literally the same predicate, not one a
    reviewer hoped was equivalent. A separately-written verification predicate is how a proof
    becomes a tautology: narrower than the delete, it reports success over rows it never saw."""
    deleting = step.delete.split()
    counting = step.count.split()
    assert deleting[:3] == ["DELETE", "FROM", step.table], deleting
    assert counting[:4] == ["SELECT", "COUNT(*)", "FROM", step.table], counting
    assert deleting[3:] == counting[4:], (deleting, counting)


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_no_statement_narrows_to_an_analyzer(step: RelationalPurge) -> None:
    """`analyzer` is part of both sparse primary keys, and a bump writes new rows *beside* the old
    ones so the old ones keep serving the versions indexed under them. A delete narrowed to the
    analyzer this process happens to run would strand every row written under a previous one — and
    the proof, narrowed identically, would not see them either. Invisible twice."""
    for sql in (step.delete, step.count):
        assert "analyzer" not in sql, sql


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_no_statement_matches_on_anything_derived_from_the_content(step: RelationalPurge) -> None:
    """Non-negotiable 6. Headers, disclaimers, licence blocks and pricing tables repeat verbatim
    across documents and across tenants, so a delete matched on content cuts a hole in a source
    nobody touched, returns without error, and leaves no trace back to itself."""
    for sql in (step.delete, step.count):
        words = set(re.findall(r"[a-z_]+", sql.lower()))
        assert words.isdisjoint(CONTENT_ADDRESSED), sorted(words & CONTENT_ADDRESSED)


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_no_statement_interpolates_anything(step: RelationalPurge) -> None:
    """Parameter binding, never string building — even for a value we produced ourselves. A
    `format` placeholder left in a delete is the one injection site where the payload is a table
    name and the blast radius is every tenant."""
    for sql in _all_statements(step):
        assert "{" not in sql and "}" not in sql, sql
        assert "%(" not in sql, sql
        assert "+" not in sql, sql


# ── the terminal organization sweep ──────────────────────────────────────────
#
# `delete`/`count` name their rows through a caller-supplied version list, so a row whose
# `source_version_id` no longer resolves is unreachable by every code path in this service — no
# cascade reaches it either, because `source_version_id` carries no foreign key. `org_residue_*`
# is the terminal sweep that closes that at organization erasure. Everything below is about the
# one property that makes an organization-wide DELETE affordable: it must be *terminal* and it
# must be *narrower than nothing else in the file*, so it cannot be mistaken for, or reached
# from, a source purge.


def _all_statements(step: RelationalPurge) -> tuple[str, str, str, str]:
    """Every statement on one plan entry. Read from the dataclass rather than listed, so a fifth
    statement added to `RelationalPurge` arrives already inside the shape assertions above."""
    return (step.delete, step.count, step.org_residue_delete, step.org_residue_count)


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_every_table_has_a_terminal_organization_sweep(step: RelationalPurge) -> None:
    """One tuple, four statements, and the pairing is the anti-drift guard.

    The gap this module was written to close was a table present in one list and absent from
    another. Carrying the sweep on the same frozen entry as the version-scoped purge means a table
    cannot join one without joining the other — so the set that gets emptied by a source purge, the
    set emptied by an organization erasure, and the two sets proven empty are all the same set, by
    construction rather than by review.
    """
    assert step.org_residue_delete
    assert step.org_residue_count
    assert step.org_residue_delete.split()[:3] == ["DELETE", "FROM", step.table]
    assert step.org_residue_count.split()[:4] == ["SELECT", "COUNT(*)", "FROM", step.table]


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_the_sweep_is_the_purge_with_the_version_term_removed_and_nothing_else(
    step: RelationalPurge,
) -> None:
    """The exact relationship, asserted rather than assumed — because "widen the predicate until
    the purge finishes" is the one edit in this module with no repair.

    Deriving `org_residue_delete` from `delete` at runtime is *not* the answer: a delete statement
    assembled by editing another delete statement is a widening that no diff shows. It is written
    out at the site, where a reviewer reads the whole predicate, and pinned here. Anything the
    version term's removal does not account for — a dropped `organization_id`, an added `OR`, a
    different table — is a difference this assertion sees.
    """
    version_term = " AND source_version_id = ANY(%s)"
    assert step.delete.endswith(version_term), step.delete
    assert step.count.endswith(version_term), step.count
    assert step.org_residue_delete == step.delete.removesuffix(version_term)
    assert step.org_residue_count == step.count.removesuffix(version_term)


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_the_sweep_binds_the_organization_and_only_the_organization(
    step: RelationalPurge,
) -> None:
    """One placeholder, and it is the tenant.

    A second placeholder would mean the sweep carries a predicate the proof may not share, or —
    worse — that somebody reintroduced a version term and made the "terminal" sweep a second,
    unreviewed version-scoped delete. Zero placeholders would mean an unqualified `DELETE FROM
    sparse_term_frequencies`, which is every tenant in the installation.
    """
    for sql in (step.org_residue_delete, step.org_residue_count):
        assert sql.endswith("WHERE organization_id = %s"), sql
        assert sql.count("%s") == 1, sql
        assert "source_version_id" not in sql, sql
        assert "analyzer" not in sql, sql
        assert " OR " not in sql.upper(), sql


@pytest.mark.parametrize("step", RELATIONAL_PURGE_ORDER, ids=PLAN_TABLES)
def test_the_sweep_matches_on_nothing_derived_from_the_content(step: RelationalPurge) -> None:
    """Non-negotiable 6, restated over the wider statement because the wider statement is where it
    would matter most: a content match at organization scope reaches every source the tenant has."""
    for sql in (step.org_residue_delete, step.org_residue_count):
        words = set(re.findall(r"[a-z_]+", sql.lower()))
        assert words.isdisjoint(CONTENT_ADDRESSED), sorted(words & CONTENT_ADDRESSED)


def test_organization_scope_refuses_a_blank_tenant() -> None:
    """The only guard these statements have left once the version term is gone.

    Not because a blank organization would widen the predicate — `organization_id = ''` matches
    nothing — but because a caller that reached a *terminal, organization-wide* DELETE having lost
    its tenant is one whose next statement may not be so forgiving.
    """
    with pytest.raises(ValueError, match="not scoped at all"):
        organization_scope("")


def test_organization_scope_returns_a_one_tuple_and_never_the_bare_string() -> None:
    """psycopg iterates the parameter sequence. Handed a `str`, it iterates its *characters*, so a
    single-placeholder statement binds the first character of the organization id — a delete under
    a one-character tenant that belongs to nobody, or to somebody. Returning a tuple makes passing
    the bare string a thing a caller has to do on purpose.
    """
    assert organization_scope("org_01") == ("org_01",)
    assert isinstance(organization_scope("org_01"), tuple)


# ── cross-plane: the columns and the cascade that is not there ───────────────


@pytest.mark.parametrize("table", ["sparse_term_frequencies", "sparse_version_statistics"])
def test_the_columns_the_statements_name_exist_in_the_laravel_migration(table: str) -> None:
    """The failure being caught is a **spelling** one, and it reads as correct on each side alone.

    `organisation_id` for `organization_id`, or `version_id` for `source_version_id`, produces a
    statement that reviews fine and fails at its first execution — inside a Celery task, after the
    vector and object steps have already destroyed everything they were pointed at.
    """
    body = _migration_bodies()[table]
    step = next(s for s in RELATIONAL_PURGE_ORDER if s.table == table)
    for column in ("organization_id", "source_version_id"):
        assert column in step.delete, step.delete
        assert re.search(rf"^\s*{column}\s+\w", body, re.M), f"{column} not declared on {table}"


def test_no_cascade_can_reach_the_sparse_rows_so_the_explicit_purge_is_required() -> None:
    """A tripwire, and the finding that decided this whole change: **there is no cascade**.

    `organization_id` references `organizations (id) ON DELETE RESTRICT` — the project-wide choice
    for that edge, so residue *blocks* an organization delete rather than riding along with it —
    and `source_version_id` carries no foreign key at all, because `source_versions` does not exist
    in this repository. The migration names the composite `ON DELETE CASCADE` it owes once that
    table lands.

    When that FK does land this test goes red, deliberately, because the question it asks has to be
    re-answered rather than assumed: the explicit purge **stays**. `purge_retired_version` removes
    a retired version's artifacts after a recrawl published its successor, and the retired
    `source_versions` row survives that purge — so no cascade ever fires on the most frequent path,
    and a purge that leaned on one would tidy up after deletions while accumulating after every
    recrawl. Delete this assertion only after confirming the plan above is unchanged.
    """
    migration = _sparse_migration_text()
    statements = re.findall(r"CREATE TABLE sparse_\w+\s*\((.*?)\n\s*\)", migration, re.S)
    assert len(statements) == 2, "the sparse migration no longer creates exactly two tables"
    for body in statements:
        assert "ON DELETE CASCADE" not in body.upper(), (
            "a cascade now reaches the sparse statistics. Re-read RELATIONAL_PURGE_ORDER before "
            "removing either step: purge_retired_version leaves the source_versions row in place, "
            "so no cascade fires on the recrawl path, which is the frequent one."
        )
        assert "ON DELETE RESTRICT" in body.upper(), (
            "the organizations edge is no longer RESTRICT. If it now cascades, an organization "
            "delete removes these rows through a rule nobody issued and nothing verifies."
        )


# ── version_scope ────────────────────────────────────────────────────────────


def test_version_scope_refuses_an_empty_version_set() -> None:
    """`= ANY('{}')` deletes zero rows and counts zero, so a resolver that came back empty over a
    source that still holds rows would make the purge a no-op *and* make its proof pass, in the
    same breath. This is the last place that distinction still exists."""
    with pytest.raises(ValueError, match="resolution that returned nothing"):
        version_scope("org_01", [])


def test_version_scope_refuses_a_blank_organization() -> None:
    """Not because the predicate would widen — it would match nothing — but because a caller that
    reached a delete statement without a tenant lost its scope somewhere upstream, and the next
    statement it builds may not be so forgiving."""
    with pytest.raises(ValueError, match="not scoped at all"):
        version_scope("", ["ver_01"])


def test_version_scope_deduplicates_and_keeps_order() -> None:
    """A resolver that returns a version twice must not double the work or change the plan, and a
    stable order keeps two runs over the same source comparable in the logs."""
    assert version_scope("org_01", ["b", "a", "b"]) == ("org_01", ["b", "a"])


# ── the proof's own failure modes ────────────────────────────────────────────


def test_a_store_missing_from_the_tally_fails_exactly_like_a_dirty_one() -> None:
    """The gap, expressed as the proof rather than the purge.

    The verification that shipped here counted `chunks` and nothing else. Had it been handed a
    tally without the sparse tables in it, "not measured" would have read as "clean" and the source
    would have been marked `Deleted` over two tables nobody looked at. An absent key is a failure.
    """
    complete = dict.fromkeys(PLAN_TABLES, 0)
    assert failed_relational_stores(complete) == ()

    partial = {table: 0 for table in PLAN_TABLES if not table.startswith("sparse_")}
    assert failed_relational_stores(partial) == (
        "sparse_term_frequencies",
        "sparse_version_statistics",
    )


def test_a_non_zero_tally_names_the_table_and_never_the_count() -> None:
    """ "Verification failed" with no list sends an operator to re-run the whole purge blind. The
    names are store names — never a row count, never anything read out of the rows."""
    tallies = dict.fromkeys(PLAN_TABLES, 0)
    tallies["sparse_term_frequencies"] = 4_812
    assert failed_relational_stores(tallies) == ("sparse_term_frequencies",)


def test_the_failure_list_reads_in_purge_order() -> None:
    """So the operator sees the tables in the order the retry will take them, rather than in
    whatever order a dict happened to be built in."""
    tallies = dict.fromkeys(PLAN_TABLES, 1)
    assert failed_relational_stores(tallies) == PLAN_TABLES

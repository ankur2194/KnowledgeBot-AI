"""Step 3's statements: every version-keyed row this service deletes, and the count that proves it.

One tuple, read by both halves of the contract. ``_purge_relational`` walks it forward inside a
single transaction and ``verification`` runs each entry's ``count`` afterwards, so the set of
tables that get emptied and the set that get proven empty **cannot drift**. That drift is not
hypothetical here: it is the bug this module was written to close. Two derived tables
(``sparse_version_statistics`` and ``sparse_term_frequencies``, finding C2) were admitted to
``app/db/writes.py``'s allow-list and their Laravel migration states they are removed *with* the
version — three files promised the behaviour and nothing implemented it, because the purge named
its tables in one place and the proof named its stores in another.

**Why an explicit purge, and not a foreign key.** The migration
(``…_create_sparse_corpus_statistics_tables.php``) declares no cascade that could reach these
rows. ``organization_id`` references ``organizations (id) ON DELETE RESTRICT`` — RESTRICT is the
project-wide choice for that edge, so residue *blocks* an organization delete rather than riding
along with it — and ``source_version_id`` carries **no foreign key at all**, because
``source_versions`` does not exist in this repository yet. The migration names the composite
``ON DELETE CASCADE`` it owes once that table lands; when it does, these statements stay, for a
reason the cascade cannot cover: ``purge_retired_version`` removes a retired version's artifacts
after a recrawl published its successor, and the retired ``source_versions`` **row survives** that
purge — it carries ``retired_at`` and the version's identity. No cascade ever fires for it, so a
purge that leaned on one would clean up after a source deletion and silently accumulate after
every recrawl, which is the far more frequent path.

**Why the same statement can be counted.** ``delete`` and ``count`` differ only in their verb and
bind the identical parameters in the identical order, so the proof asks the question the purge
answered rather than a question a reviewer hopes is equivalent. A proof built from a
separately-written predicate is how verification becomes a tautology — see ``verification``'s
module docstring for the two shapes that have shipped elsewhere.

**Scoping.** Every statement is ``organization_id = %s AND source_version_id = ANY(%s)``. The org
term is redundant for correctness once the version ids came from PostgreSQL, and mandatory for
safety: with it a wrong version id deletes nothing, without it a wrong version id deletes another
tenant's rows, and that mistake has no repair. Both sparse tables lead their primary key with
``organization_id``, so the scoped statement is also the indexed one.

**No analyzer predicate, deliberately.** ``analyzer`` is part of both primary keys and an analyzer
bump writes new rows *beside* the old ones — the old ones keep serving the versions indexed under
them. So one version can hold rows under several analyzers, and a delete narrowed to the analyzer
this process happens to be running would strand every row written under a previous one. The proof
would be narrowed the same way and would not see them either, which is how a residue becomes
invisible twice. Purging a version removes the version's rows, under every analyzer that ever
indexed it.

**What the rowcounts are for.** Each ``delete`` returns a rowcount, and those tallies are the
deletion's audit entry and its verification record: identifiers and counts only, never contents.
``blake2b`` term ids and integer frequencies are not source text and must not be logged as though
they were a substitute for it either.

── THE ROWS THE VERSION-SCOPED PREDICATE CANNOT REACH ────────────────────────────────────────

Every statement above names its rows through a **caller-supplied version list**. A row whose
``source_version_id`` is not in some list is therefore unreachable by every code path in this
service, and nothing else removes it either: ``source_version_id`` carries no foreign key, so no
cascade fires, and the version-scoped purge is resolved from ``source_versions`` — which cannot
resolve a version that is already gone.

That residue is not merely untidy. ``organization_id`` is ``ON DELETE RESTRICT``, so the *first*
place it becomes visible is at the very end of an account erasure, as a foreign-key violation
raised by a table nobody remembered writing — **account erasure arranged to fail at the database
rather than to complete**, which is a compliance obligation, not a background inconvenience.

So each entry carries a second pair of statements: ``org_residue_delete`` and
``org_residue_count``, scoped to ``organization_id = %s`` and nothing else. Five things make that
width safe, and removing any one of them makes it the exact mistake this module warns about:

1. **It is terminal, and it is not the mechanism.** It runs from ``_sweep_organization_residue``
   only, *after* ``purge_organization``'s per-source fan-out has completed and verified. Every row
   a source could name is already gone by then, so what this statement removes is by definition
   what no source could name. **A non-zero rowcount is a finding, not a success** — it is recorded
   as evidence and reported, because it means rows existed that the checked path could not reach.
2. **It is not reachable from source deletion.** ``purge_source`` and ``purge_retired_version``
   bind the version-scoped pair and cannot reach this one. Widening a source purge to the
   organization would take every other source in the tenant with it and raise nothing.
3. **It lives on the same tuple.** Both pairs are fields of the same frozen ``RelationalPurge``,
   so a table cannot enter the purge without entering the sweep, cannot enter the sweep without
   entering the purge, and cannot be proven empty by one and not the other. The drift this module
   exists to close does not reopen along a new axis.
4. **Its proof is not its own predicate.** Counting ``organization_id = %s`` after deleting
   ``organization_id = %s`` is a tautology — the two agree on an answer neither measured, which is
   exactly what ``version_scope`` refuses an empty list to prevent. The load-bearing proof is
   external and belongs to the database: ``organizations`` is ``ON DELETE RESTRICT`` from both
   these tables, so Laravel's ``DELETE`` of the organization row **succeeds only if nothing
   remains**. That delete is core-api's — this service owns no schema and writes no row outside
   ``app/db/writes.py``'s allow-list — and its success is the evidence the sweep is verified by.
   The tally is recorded as well, because a table with no such foreign key (``chunks``,
   ``document_elements`` — neither exists in this repository yet) is not covered by it.
5. **``organization_scope`` refuses a blank tenant**, for the same reason ``version_scope`` does,
   and it is the only guard left once the version term is gone.

**What is still owed, and what it is blocked on.** This closes the *terminal* case — an
organization being erased. It does not close the **live** one: residue under an organization that
keeps operating stays unreachable, because the predicate that identifies it is an anti-join
against ``source_versions`` (``… AND NOT EXISTS (SELECT 1 FROM source_versions …)``) and **no
migration in this repository creates that table**. Writing that statement now would mean either
inventing the table — putting a table ``kb-source-lifecycle`` owns into a change about deletion —
or hard-coding a live-version list supplied by a caller, which is the version-scoped predicate
again with a worse name. It is reported rather than stubbed. When ``source_versions`` lands, the
migration also owes both tables a composite ``(organization_id, source_version_id) … ON DELETE
CASCADE``; that makes *new* orphans impossible but removes neither the anti-join sweep for the
ones already accumulated nor the terminal sweep, whose job is proof rather than removal.
"""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass
from typing import Final

__all__ = [
    "RELATIONAL_PURGE_ORDER",
    "RelationalPurge",
    "organization_scope",
    "version_scope",
]


@dataclass(frozen=True, slots=True)
class RelationalPurge:
    """One version-keyed table, the statement that empties it, and the statement that proves it.

    Frozen because the tuple below is read by a worker whose job is destroying data: a step
    rewritten at runtime is a delete nobody reviewed.

    Four statements rather than two, and all four are **required** fields on purpose: a table
    cannot join the version-scoped purge without also joining the terminal organization sweep,
    and neither can be added without the count that proves it. The one gap this module was
    written to close was a table present in one list and absent from another; the same gap must
    not reopen between the two scopes.
    """

    #: The table, spelled exactly as ``app/db/writes.py``'s allow-list spells it. A
    #: singular/plural slip or an ``_stats`` for ``_statistics`` reads as correct on both sides
    #: alone and fails only at the first statement, inside a Celery task.
    table: str

    #: ``DELETE`` — binds ``(org_id, version_ids)``.
    delete: str

    #: ``SELECT COUNT(*)`` over the identical predicate, binding the identical parameters.
    count: str

    #: ``DELETE`` scoped to the organization alone — binds ``(org_id,)``. **Terminal.** Reachable
    #: only from ``tasks._sweep_organization_residue``, only after the per-source fan-out has
    #: completed and verified, and never from a source purge. Written out here rather than
    #: derived from ``delete`` at runtime, because a delete statement assembled by editing
    #: another delete statement is a widening that no diff shows and no reviewer reads; the two
    #: are held in step by ``tests/unit/test_relational_purge_plan.py`` instead, which asserts
    #: this is exactly ``delete`` with the version term removed.
    org_residue_delete: str

    #: ``SELECT COUNT(*)`` over that identical predicate. Recorded as evidence, and **not** the
    #: load-bearing proof: counting the predicate you just deleted by is a tautology. The proof
    #: is core-api's ``DELETE`` of the ``organizations`` row succeeding against ``ON DELETE
    #: RESTRICT``, which no residue can survive.
    org_residue_count: str


#: The order ``_purge_relational`` issues them in, and the order this module exists to fix.
#:
#: ── WHAT A CRASH BETWEEN TWO STATEMENTS LEAVES BEHIND ────────────────────────────────────────
#:
#: Inside the transaction, **nothing**. All four statements commit together or not at all, so a
#: worker killed between two of them leaves the rows exactly as they were and the retry redoes the
#: step from the top. That is why every statement is a ``DELETE`` — it reports zero rows and never
#: raises on state that is already gone — and it is what makes the step safe to re-run after the
#: vector and object steps have already completed.
#:
#: The order is still load-bearing, for two reasons that outlive that boundary:
#:
#: 1. **Lock ordering against the writer.** Ingestion writes a version's ``chunks`` rows and its
#:    two sparse tables in one transaction. The purge takes the same tables in the same relative
#:    order, so a purge and a concurrent re-index of the same organization queue behind each other
#:    instead of deadlocking. Reversing it makes the deadlock reachable, and PostgreSQL resolves a
#:    deadlock by killing one side: either a retried purge, or a rolled-back publish.
#: 2. **If the step is ever batched.** A version with millions of term rows is a batched delete
#:    that commits per batch rather than holding locks on a live table (`postgresql-patterns`),
#:    and then the commit boundary moves *inside* this list. Child before parent is chosen for
#:    that case: ``sparse_term_frequencies`` is the many-row child, ``sparse_version_statistics``
#:    is the one row per analyzer that holds ``document_total``. A crash after the child leaves a
#:    single inert counter per analyzer — bounded residue that the next run removes. A crash after
#:    the *parent* would instead leave the frequencies with no total, and anything that read that
#:    scope would hit ``sparse.idf``'s ``document frequency … exceeds the scope total`` — the exact
#:    message this codebase reserves for a statistics set read over a wider scope than the query,
#:    i.e. a cross-tenant alarm raised by a purge that was merely interrupted.
#:
#: The sparse tables come **after** ``chunks``, not before, and that is safe for a specific reason:
#: the version ids that name them are resolved from ``source_versions`` inside the job, never from
#: ``chunks``. Losing the chunk rows first therefore does not lose the handle on the statistics. If
#: these rows were ever keyed off something derived from ``chunks``, this order would have to
#: invert — and the proof would have to move with it.
#:
#: ``chunks`` and ``document_elements`` are stated from `postgresql-patterns`
#: (``organization_id NOT NULL`` on every tenant-owned table, ``chunks.source_version_id``);
#: **no migration in this repository creates either table**, so their column names are
#: unverified against a schema in a way the two sparse entries are not.
RELATIONAL_PURGE_ORDER: Final[tuple[RelationalPurge, ...]] = (
    RelationalPurge(
        table="chunks",
        delete=("DELETE FROM chunks WHERE organization_id = %s AND source_version_id = ANY(%s)"),
        count=(
            "SELECT COUNT(*) FROM chunks WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        org_residue_delete=("DELETE FROM chunks WHERE organization_id = %s"),
        org_residue_count=("SELECT COUNT(*) FROM chunks WHERE organization_id = %s"),
    ),
    RelationalPurge(
        table="document_elements",
        delete=(
            "DELETE FROM document_elements "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        count=(
            "SELECT COUNT(*) FROM document_elements "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        org_residue_delete=("DELETE FROM document_elements WHERE organization_id = %s"),
        org_residue_count=("SELECT COUNT(*) FROM document_elements WHERE organization_id = %s"),
    ),
    # ── finding C2's rollup, the half that nothing removed ────────────────────────────────────
    # The child first: many rows per version per analyzer, and the residue a crash can leave
    # here is the one that does not look like a tenancy alarm.
    RelationalPurge(
        table="sparse_term_frequencies",
        delete=(
            "DELETE FROM sparse_term_frequencies "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        count=(
            "SELECT COUNT(*) FROM sparse_term_frequencies "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        org_residue_delete=("DELETE FROM sparse_term_frequencies WHERE organization_id = %s"),
        org_residue_count=(
            "SELECT COUNT(*) FROM sparse_term_frequencies WHERE organization_id = %s"
        ),
    ),
    RelationalPurge(
        table="sparse_version_statistics",
        delete=(
            "DELETE FROM sparse_version_statistics "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        count=(
            "SELECT COUNT(*) FROM sparse_version_statistics "
            "WHERE organization_id = %s AND source_version_id = ANY(%s)"
        ),
        org_residue_delete=("DELETE FROM sparse_version_statistics WHERE organization_id = %s"),
        org_residue_count=(
            "SELECT COUNT(*) FROM sparse_version_statistics WHERE organization_id = %s"
        ),
    ),
)


def version_scope(org_id: str, version_ids: Sequence[str]) -> tuple[str, list[str]]:
    """The parameter tuple every statement above binds, in that order. Raises on an empty scope.

    An empty version list is **not** a scope that matches nothing — it is a resolution that
    returned nothing, and the two must not share a code path. ``= ANY('{}')`` deletes zero rows
    and counts zero, so a resolver that came back empty over a source that still holds rows would
    make the purge a no-op *and* make its proof pass, in the same breath and with the same
    silence. Whichever half failed, this is the last place that can still tell.

    A source that genuinely has no versions is a real case and reaches this function never: the
    caller skips the version-keyed step and records that it did, so "there was nothing to name"
    stays distinguishable from "nothing was named".

    ``org_id`` is checked for the same reason one line earlier. An empty organization is not a
    wildcard here — the predicate would simply match nothing — but a blank tenant reaching a
    delete statement is a caller that lost its scope somewhere upstream, and the next statement
    it builds may not be so forgiving.
    """
    if not org_id:
        raise ValueError("a deletion statement without an organization is not scoped at all")
    if not version_ids:
        raise ValueError(
            "an empty version set is a resolution that returned nothing, not a scope: the "
            "delete would remove no rows and the count that proves it would report zero, so "
            "the purge and its proof would agree on an answer neither of them measured"
        )
    return org_id, list(dict.fromkeys(version_ids))


def organization_scope(org_id: str) -> tuple[str]:
    """The single parameter every ``org_residue_*`` statement binds. Raises on a blank tenant.

    A one-tuple rather than a bare string, so a caller cannot pass a *string* to ``execute`` and
    have psycopg iterate its characters into the placeholder list. That mistake binds the first
    character of the organization id and reports a syntax-adjacent error at best; against a
    single-placeholder statement it can bind cleanly and delete under an organization id one
    character long, which is nobody's.

    **This is the only guard the organization-scoped statements have.** ``version_scope`` refuses
    an empty list because ``= ANY('{}')`` makes a purge and its proof agree on an answer neither
    measured; there is no analogous empty value here — ``organization_id = %s`` with a blank
    string matches nothing rather than everything. What it means is that a caller reached a
    terminal, organization-wide ``DELETE`` having lost its tenant somewhere upstream, and the next
    statement it builds may not be so forgiving. The rest of the safety is structural and lives in
    the module docstring: the statements are terminal, they are unreachable from source deletion,
    they share a tuple with the version-scoped pair, and their proof is the ``RESTRICT`` foreign
    key rather than their own predicate.
    """
    if not org_id:
        raise ValueError("a deletion statement without an organization is not scoped at all")
    return (org_id,)

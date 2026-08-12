"""Phase 2 — the ordered purge, its reaper, and the retention and legal-hold gates.

Phase 1 already committed in `services/core-api/` before anything here runs, and the API has
already answered the admin. Every task below therefore starts from a source that no query can
reach, and none of them may run against a source whose phase 1 has not committed.

**The order is contractual: vectors, then objects, then relational, then caches.** Each step
depends on the one before it still existing, and getting it wrong leaves a different artifact
behind every time:

* Relational before vectors and `chunks.vector_point_id` is gone, so nothing can name the
  points any more. All that is left is a filter-scoped sweep — and Qdrant stores the *filter*
  in its write-ahead log and re-resolves it against live segments on replay, so a
  filter-removed point can come back after a restart. The orphans have no row that names them
  and no job that will ever look for them again.
* Objects before vectors and a live point cites an object that is already gone: the chunk is
  still retrievable, so the answer still cites it, and the citation resolves to nothing.
* Caches at any point other than last, and a query in flight repopulates them from state the
  purge had already passed. Phase 1 invalidated them once; this is the second invalidation,
  and it exists solely to close that window. Docker Distribution shipped exactly this bug
  (CVE-2026-35172) — the delete cleared the shared entry, left the scoped membership record,
  and the next read made the removed blob readable again.
* Verification before any of it, and it certifies a purge that has not happened.

**Queue.** Every task here is named `kb.deletion.*`, which `app/worker/config.py` already
routes to `maintenance`. That is a correctness decision, not a capacity one: `maintenance` is
a separate queue on its own container precisely so that a purge and its reaper never queue
behind a 400-page crawl. A source stuck in `Deleting` is not a background inconvenience — it
is on the acceptance-criteria demo path (§33 steps 14–15), visible in the admin view while
someone watches.

**Idempotency is not optional and partial completion is the normal case.** `acks_late` plus a
visibility timeout plus `reject_on_worker_lost` means every task here WILL run again over
work it already did. Each step writes a `background_jobs` checkpoint, each tolerates
already-absent state, and none of them treats "already gone" as a failure. A worker
OOM-killed between the vector step and the relational step leaves nothing wrong with the
data — only a state machine with no way out, which is what the reaper is for. Never repair
that by moving the source back to `Ready`: half its chunks are gone and it will answer with
holes.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

from app.deletion.verification import (
    HARD_TIME_LIMIT,
    SOFT_TIME_LIMIT,
    verify_erasure,
    verify_organization_purged,
    verify_source_purged,
)
from app.worker import celery_app

# `verify_source_purged` and `verify_erasure` are re-exported deliberately, not by accident.
# The worker's `conf.imports` names `app.deletion.tasks` and nothing else in this package, so
# the proof pass registers only because this module pulls it in. Drop the import and
# `purge_source` enqueues a task no worker has registered — which does not raise anywhere:
# the message sits in the queue, the source stays `Deleting`, and the reaper re-purges it
# forever without ever verifying.
__all__ = [
    "assert_no_legal_hold",
    "checkpoint",
    "erase_data_subject",
    "original_disposition",
    "purge_organization",
    "purge_retired_version",
    "purge_source",
    "sweep_pending_purges",
    "sweep_retention_releases",
    "verify_erasure",
    "verify_organization_purged",
    "verify_source_purged",
]


# ── the entry point ──────────────────────────────────────────────────────────


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.purge_source",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def purge_source(self: Any, *, org_id: str, source_id: str, job_id: str) -> None:
    """Phase 2 for one source. `kb.deletion.purge_source`, queue `maintenance`.

    Takes **identifiers only**. Never a snapshot of version ids taken at enqueue time: a
    recrawl can activate a new version between the admin's click and the worker picking the
    job up, and a stale id list leaves that version's points alive with nothing left to name
    them. Resolve every version id — active and retired — from PostgreSQL inside the job.

    Holds a Valkey lock on the source (`lock:{org_id}:purge:{source_id}`) with a TTL, so a
    killed worker self-heals instead of blocking the source forever, released with a
    compare-and-delete against the token rather than a bare delete, which would release
    whoever holds it *now*. The lock is an optimisation, not the correctness mechanism: a
    holder that pauses past its TTL — a slow store, a GC pause, a frozen container — loses the
    lock while still running. Correctness comes from a fencing token carried into the writes
    and from every step being idempotent.

    Steps, in the order the module docstring fixes: resolve version ids, `vectors`, `objects`,
    `relational`, `caches`, checkpointing between each, then hand off to
    `verify_source_purged`. The task does not write `Deleted` and does not report success —
    the verification pass does, and only if it passes.

    Both gates run before the first byte goes: `assert_no_legal_hold` and, for the original
    object, `original_disposition`. A bulk path never skips them, because the bulk paths call
    this function per source rather than issuing a query of their own.
    """
    raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.purge_retired_version",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def purge_retired_version(
    self: Any, *, org_id: str, source_id: str, source_version_id: str, job_id: str
) -> None:
    """Remove one retired version's artifacts after a recrawl published its successor (§13.5).

    The only place a version-scoped delete is legitimate: here the id is exact, known and
    already retired. Everywhere else, deleting a source by its version ids is the bug that
    leaves one version alive.

    Runs as a backstop as well as inline — beat re-enqueues it for versions that were retired
    but whose artifacts are still present, which is how a publication that crashed between
    activation and cleanup gets finished.
    """
    raise NotImplementedError


# ── the four steps ───────────────────────────────────────────────────────────


def _purge_vectors(org_id: str, source_id: str, version_ids: Sequence[str]) -> int:
    """Step 1. Remove points by id first, then one identity-scoped sweep. Returns how many
    point ids were addressed.

    By id, because `chunks.vector_point_id` already holds every point this source owns, the
    ids are deterministic (`uuid5` over org, version and sequence), and an id-addressed delete
    is a deterministic log entry. A filter-scoped delete is not: Qdrant re-resolves the stored
    filter against live segments on every apply including replay, and applies it in 512-point
    batches that release the segment write lock in between, so a concurrent search sees a
    half-purged source and a restart can resurrect points.

    The sweep that follows uses `identity_filter` — `org_id` + `source_id`, no status or
    version term — and exists for points whose chunk row was already removed by a previous,
    interrupted run. That is what makes a retry after a partial completion converge instead
    of stranding orphans.

    Dense and sparse ride the same point; one delete removes both. Never issue a second one.
    `wait=True` on every call, or the proof that follows is measuring an unfinished write.
    """
    raise NotImplementedError


def _purge_objects(
    org_id: str,
    source_id: str,
    version_ids: Sequence[str],
    *,
    include_original: bool,
) -> None:
    """Step 2. Sweep the version-scoped prefixes, and only then consider the original.

    `derived/` goes unconditionally; `original/` goes only when `include_original` — which
    comes from `original_disposition`, never from a caller's assumption. Removing knowledge is
    not removing the file, and a source can legitimately end as *knowledge removed, original
    retained*. The admin view has to say so.

    Extracted images are content-hash-deduped **within** the organization, so they are
    dropped only once no surviving element references them. Never widen a prefix to make a
    sweep succeed: prefixes start `org/{org_id}/sources/{source_id}/versions/{id}/` and one
    segment too few is another tenant's data.

    The batch delete reports per-key failures inside a 200 response body, and `Quiet` mode
    suppresses only the successes — inspect the error list on every call, or this step reports
    success and verification discovers the truth hours later with no diagnosis.

    Never hand this to the store as a rule. No lifecycle configuration, no bucket versioning,
    no volume TTL: a rule we do not run is a deletion we cannot prove, and on SeaweedFS the
    lifecycle rule is accepted, readable back, and never executes at all.
    """
    raise NotImplementedError


def _purge_relational(org_id: str, source_id: str, version_ids: Sequence[str]) -> None:
    """Step 3. Empty every version-keyed table in `RELATIONAL_PURGE_ORDER`, in that order, in one
    transaction.

    The tables, the statements, the order and the reason for the order live in
    `app/deletion/relational.py` and are not restated here — `verification` runs each entry's
    `count` afterwards, and the whole point of the shared tuple is that the set which gets emptied
    and the set which gets proven empty cannot drift apart. They did drift once: two derived
    tables were written by ingestion, named on the write allow-list, documented in their migration
    as removed *with* the version, and removed by nothing.

    Bind `relational.version_scope(org_id, version_ids)` into every statement — never a
    hand-assembled parameter list, and never a version id list captured at enqueue time. Each
    statement's rowcount is the tally that reaches the audit entry and the verification record.

    A source that resolved to **no** versions skips this step and checkpoints that it skipped it.
    That is not the same as `version_scope` receiving an empty sequence, which raises: a resolver
    that came back empty over a source that still holds rows would make both the delete and its
    proof report zero, and this is the last place that distinction still exists.

    **Only tables this service may write, and it may write no others.** The writable set is
    `app/db/writes.py`'s `ALLOWED_TABLES`; that module is the list and its docstring is the
    admission rule, so neither the names nor how many there are is restated here. What this
    step must not do is reach past that set: this service owns no
    schema; `source_versions`, `source_items`, `bot_source_assignments` and `citations` are
    Laravel's, read and write. The rest of the relational purge — retiring the version and item
    rows, removing the assignments, and breaking the citation link — is a core-api call made
    from this step, over the same signed seam that later marks the source `Deleted`. Issuing
    it here would land a row change beside Laravel's own writer with no policy check, no audit
    row and no framework-applied scope, and it would fail nowhere.

    The citation link is **broken, never cascaded**. `citations.chunk_id` is set to null and
    the stored label, display title and location metadata are kept, so old transcripts still
    render. A cascade on that foreign key takes past answers with it, and an admin deleting a
    source does not expect to lose the conversation history that quoted it.

    Ordinary source deletion **retains** the verbatim excerpt columns. Only a data-subject
    erasure sweeps them, and only when raised as one — see `erase_data_subject`.

    The two sparse-corpus-statistics tables (finding C2) are in the tuple for the same reason
    `chunks` is: they are derived from the version's chunk text, keyed by the identifiers this
    step already resolves, and their migration states they are removed *with* the version.
    Nothing about them is a special case, and that is deliberate — being ordinary members of the
    list is what makes them inherit the legal-hold gate, the retention decision, the checkpoint
    and the proof without a second code path that could be forgotten a second time.

    Their absence was silent because ranking never depended on it: the sparse read is scoped to
    the resolved active-version set, so a purged version's statistics stop weighting queries the
    instant it leaves that set. Nothing answers wrong. What accumulated was residue keyed to a
    `source_version_id` that no longer exists — unbounded growth, rows the deletion proof could
    not account for, and (because `organization_id` is `ON DELETE RESTRICT`) rows that would
    eventually refuse an organization delete with a foreign-key violation raised by a table
    nobody remembered writing.
    """
    raise NotImplementedError


def _invalidate_caches(org_id: str, source_id: str) -> None:
    """Step 4, and the second time it has been done — phase 1 did it once before committing.

    The second call is not belt-and-braces. A reader that loaded a value *before* phase 1's
    commit can write it back *after*, so between the two invalidations the entry is live again
    with no event that looks wrong anywhere. A deleted source that still answers is almost
    always this, not a surviving vector: vectors are the loud artifact and caches are the
    quiet one.

    Enumerate the tracked index (`ansidx:{org_id}:{source_id}`), never a `SCAN` with a match
    pattern. SCAN only guarantees keys present for the whole iteration, so anything written
    mid-iteration may be missed — which is precisely the read-repopulate case this step is
    here for, and the sweep would report nothing to clean.
    """
    raise NotImplementedError


def _sweep_organization_residue(org_id: str) -> Mapping[str, int]:
    """The terminal organization-scoped sweep. Returns the rowcount per table, in plan order.

    **Not a step of a source purge, and not reachable from one.** It runs from
    `purge_organization` alone, after every source has been through `purge_source` *and* passed
    `verify_source_purged`, and before `verify_organization_purged`. Calling it anywhere else is
    an organization-wide `DELETE` over live knowledge, and nothing in this file would raise.

    It exists because `_purge_relational` names its rows through a caller-supplied version list,
    so a row whose `source_version_id` no longer resolves is unreachable by every other code path
    here — no cascade reaches it either, since `source_version_id` carries no foreign key. Left
    there it refuses the `organizations` row at the very end of an account erasure, on `ON DELETE
    RESTRICT`, which arranges a compliance obligation to fail at the database rather than to
    complete.

    Issue `step.org_residue_delete` for every `RELATIONAL_PURGE_ORDER` entry, in plan order, in one
    transaction, binding `relational.organization_scope(org_id)` unchanged. Plan order still
    matters for the same lock-ordering reason it does in `_purge_relational`, and the statements
    are read off the same tuple so the emptied set and the proven-empty set cannot drift apart.

    **Every returned rowcount is expected to be zero, and a non-zero one is a finding.** It is not
    a repair to be logged at debug and forgotten: it is the measured size of the residue the
    checked path could not reach, and it is the only evidence anyone will ever have of it. Record
    it in the verification artifact and the audit entry — identifiers and counts, never contents —
    even when the run passes.

    **What this does not close.** Residue under an organization that keeps operating stays
    unreachable. Identifying it needs an anti-join against `source_versions` (`… AND NOT EXISTS
    (SELECT 1 FROM source_versions sv WHERE sv.organization_id = %s AND sv.id = source_version_id)`)
    and no migration in this repository creates that table. Do not approximate it with a
    caller-supplied list of live versions: that is the version-scoped predicate again, and a
    resolver that returned a short list would delete live statistics for every version it omitted.
    """
    raise NotImplementedError


def checkpoint(job_id: str, step: str) -> None:
    """Record that a step completed, durably, in `background_jobs`.

    This is what makes partial completion recoverable rather than a mystery. A worker killed
    mid-purge leaves a job whose last checkpoint says exactly how far it got, so the retry is
    a resumption and the reaper has something to reason about. Without it, "stuck in
    `Deleting`" carries no information at all and the only safe response to a restart is to
    redo everything blind.
    """
    raise NotImplementedError


# ── bulk reaches, each fanning out through the checked path ──────────────────


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.purge_organization",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def purge_organization(self: Any, *, org_id: str, job_id: str) -> None:
    """Organization deletion: enumerate the org's sources and enqueue `purge_source` for each.

    An outer sweep over the per-source path, never a hand-written bulk query. The per-source
    path is the one carrying the legal-hold check, the retention decision, the checkpoints and
    the proof; a bulk statement that reaches every store at once has none of them and is
    unverifiable by construction. It is also the shape where one filter written a level too
    wide destroys another tenant, with no way back.

    A hold on any single source refuses that source and records the conflict; it does not
    abort the sweep and does not get skipped.

    **What a per-source fan-out structurally cannot reach**, and the one thing to check before
    reporting an organization clean: rows keyed to a `source_version_id` whose source is already
    gone. `_purge_relational` names its rows through the version list resolved from the source, so
    residue left by a purge that predates that step has nothing left to resolve it. Every
    `RELATIONAL_PURGE_ORDER` table is exposed to this, and the two sparse ones are where it was
    actually accumulating. It surfaces as a foreign-key violation at the very end — the
    `organizations` edge is `ON DELETE RESTRICT` everywhere in this schema, so the residue refuses
    the organization row rather than riding along with it. That is the correct failure and a bad
    diagnosis: the fix is `_sweep_organization_residue` below, recorded as evidence, not a wider
    delete. Never widen one of these statements to make an organization purge finish.

    So the order here is: fan out, wait for every `verify_source_purged` to pass,
    `_sweep_organization_residue`, then `verify_organization_purged`. The sweep is third for a
    reason — it is terminal and it is not the mechanism. Running it before the fan-out would make
    it the mechanism, and an organization-wide `DELETE` that does the work is unverifiable by
    construction: nothing downstream could tell an organization that was purged through the
    checked path from one that was emptied by a single statement carrying none of its gates.
    """
    raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.erase_data_subject",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def erase_data_subject(
    self: Any, *, org_id: str, scope: str, subject_ref: str, job_id: str
) -> None:
    """Data-subject erasure (§18.10, ADR-015) — a strict superset of deletion, never inferred
    from one.

    Everything `purge_source` does, plus an in-place overwrite of the three verbatim columns
    on the conversation side: `citations.excerpt`, `retrieval_traces.selected_evidence` and
    `evaluation_results.retrieved_evidence`. The row survives; ids, labels, scores, ranks and
    timestamps are untouched. Removing the rows instead would move historical evaluation
    scores and retrieval metrics because of a compliance action, and nobody would ever
    reconcile the shift against the reason for it.

    Only two of those three tables are this service's to write. `citations` is Laravel's, so
    that column is swept over the core-api seam, the same way the version and item rows are.

    Scope is one of user, conversation, source, organization. The source scope reaches these
    columns **only when the request was explicitly raised as an erasure** — an `erasure: true`
    flag set by the caller and carried through the job payload and the audit entry. Never
    inferred from who raised the delete or from any heuristic over the request: an ordinary
    source deletion retains the excerpts deliberately, so that transcripts keep rendering.

    A collision with a legal hold is **refused whole and recorded as a conflict** — checked
    before phase 1, changing nothing. A half-erased subject satisfies neither obligation and
    destroys the evidence needed to explain which parts ran.

    "We kept only the vector" is not a defence: embedding inversion recovers roughly 92% of
    32-token inputs exactly, so the vectors go too.
    """
    raise NotImplementedError


# ── sweeps ───────────────────────────────────────────────────────────────────


@celery_app.task(  # type: ignore[misc]
    name="kb.deletion.sweep_pending_purges",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
)
def sweep_pending_purges() -> None:
    """The reaper. Re-enqueue `purge_source` for every source left in `Deleting` past the
    threshold. Beat entry `kb.deletion.sweep_pending_purges`, every 5 minutes, `maintenance`.

    A source is stuck because a worker died between two steps, not because the data is
    inconsistent — the purge is idempotent, so re-running it is the whole repair. The reaper
    exists because the state machine has no other exit, and a source sitting in `Deleting` for
    days is discovered by the customer.

    Itself idempotent: a beat restart across a tick boundary double-fires, and re-enqueuing a
    purge that is already running is caught by the source lock.
    """
    raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    name="kb.deletion.sweep_retention_releases",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
)
def sweep_retention_releases() -> None:
    """Remove originals whose retention window has closed. Beat, `maintenance`.

    Retention is a PostgreSQL-driven sweep that reads release dates, issues the deletes
    itself, and verifies the prefix afterwards — the same two-phase-plus-proof shape as
    everything else here. It is emphatically **not** a bucket lifecycle rule: on SeaweedFS the
    rule is accepted, `GetBucketLifecycleConfiguration` reads it back, and it never runs
    without an admin server and a lifecycle worker we do not deploy. The window then silently
    never closes — originals past their release date stay billed, stay listable and stay
    discoverable in an audit, while the admin view reports the policy as applied.

    Never releases an object under legal hold, whatever its date says.
    """
    raise NotImplementedError


# ── gates, and where they sit ────────────────────────────────────────────────


def assert_no_legal_hold(org_id: str, source_id: str) -> None:
    """Refuse if the source, or the subject behind it, is under a hold. Raises; never returns
    a warning.

    Sits **before phase 1** for an erasure and again at the top of `purge_source`, because the
    two are separated by a queue and a hold can be placed in between. A bulk path reaches it
    through the per-source call, which is the reason bulk paths fan out rather than issuing
    their own statement.

    Hold and erasure are not a contradiction. GDPR Art. 17(3) exempts data needed for a legal
    obligation or the defence of claims, and Art. 18 with Recital 67 supplies the mechanism —
    restriction of processing, which permits *storage*, not retrieval. So the split is exact:
    segregate and flag the original object, and still remove every embedding, chunk and cache
    entry over it. Segregation moves the original into the separate, pre-provisioned,
    versioned, object-lock-enabled bucket, in governance mode only — a compliance-mode lock
    cannot be shortened by anyone, root included, so applying one to data that may later face
    an erasure request creates a conflict with no technical exit.
    """
    raise NotImplementedError


def original_disposition(org_id: str, source_id: str) -> bool:
    """Decide whether the original upload goes with this purge. True means remove it.

    False whenever a retention window is still open or a hold applies, and the decision is
    recorded either way — the deletion report names the retained object and its release date,
    because §8.17 requires the admin view to show remaining retention obligations rather than
    a clean delete that did not happen.

    Never inferred from a caller's flag and never defaulted to True to make a purge "finish".

    This decision governs the **original object only**. Nothing in `RELATIONAL_PURGE_ORDER` is
    subject to it: chunks, elements and the sparse corpus statistics are derived knowledge, and
    Art. 18 with Recital 67 permits *storage* of the restricted original, not retrieval of what
    was built over it. So a source can end as *knowledge removed, original retained* with every
    row in that list already gone — and a retention window that is still open never keeps one of
    them alive.
    """
    raise NotImplementedError

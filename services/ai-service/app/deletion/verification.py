"""The proof step.

**A deletion is not complete when the stores return 200. It is complete when every store has
been re-queried by stable identifier and confirmed empty.** A 200 means the request was
accepted, not that the artifacts are gone — and a purge with no proof step is indistinguishable
from a purge that silently no-opped, because both leave the same evidence: none. Everything in
this module exists so that "we deleted it" is a measurement rather than a claim.

`Deleted` is Laravel's to write, and the call asking for it is made from this module and from
nowhere else — never from the purge, and never from a store's response. Until every check
passes the source stays `Deleting` and the reaper re-enqueues the purge; a failure never
degrades to a warning and never advances the state.

Two ways this job is written such that it can never fail, both of which have shipped
somewhere:

* **It reuses the retrieval filter.** Retrieval already excludes inactive status and
  non-active versions, so the check asks "are any *active* points left for a source I marked
  inactive an hour ago?" — a tautology. It passes on day one, passes forever, and catches
  nothing. Verify on the bare identity: `org_id` + `source_id`, no status term, no version
  term (`filters.identity_filter`).
* **It reads a replica.** Replication lag reads as success, and the window is exactly as long
  as the purge that just finished. Every relational check reads the primary.

And the object store has a third: a bucket whose versioning posture changed. Every delete
since then became a delete marker, the objects survive as noncurrent versions — billed,
restorable, discoverable in an audit — and a key listing keeps reporting the prefix clean. So
the object check asserts the bucket's versioning **status** and enumerates object *versions*,
not keys. It is the only call that can see the difference.

The result is a durable artifact, not a log line that scrolls away. Whoever has to answer
"prove this was deleted", weeks later, reads the recorded verification and the audit entry —
identifiers and tallies only, never source contents, because writing the removed text into
`audit_logs` recreates in the audit table exactly what was asked to be destroyed.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.deletion.relational import RELATIONAL_PURGE_ORDER
from app.worker import celery_app

__all__ = [
    "HARD_TIME_LIMIT",
    "SOFT_TIME_LIMIT",
    "DeletionVerificationFailed",
    "active_assignments_remaining",
    "cache_keys_remaining",
    "chunk_rows_remaining",
    "failed_relational_stores",
    "object_versions_remaining",
    "organization_residue_remaining",
    "points_remaining",
    "record_verification",
    "verify_erasure",
    "verify_organization_purged",
    "verify_source_purged",
    "version_scoped_rows_remaining",
]

#: `maintenance`'s soft limit, and hard = soft + 60 s. That minute is the task's window to
#: checkpoint what it finished and re-raise before SIGKILL — the difference between a retry
#: that resumes and a retry that starts over.
#:
#: Defined here rather than in `tasks`, and shared by both: `tasks` imports `verification`
#: (it chains the proof pass onto the purge), so the constants have to sit on this side or
#: the two modules import each other.
SOFT_TIME_LIMIT: Final[int] = 300
HARD_TIME_LIMIT: Final[int] = 360


class DeletionVerificationFailed(KbError):
    """Raised when any store still holds an artifact of a purged source.

    Classified `internal_dependency` — retryable, so the reaper re-runs the purge — rather
    than as a new taxonomy row. The taxonomy is closed at 18 and a nineteenth entry is a
    review stop, not something a subsystem adds for its own failure.

    Carries which stores failed, because "verification failed" without that list sends an
    operator to re-run the whole purge blind. The names are store names, never contents.
    """

    #: Retryable by the taxonomy, which is what lets the reaper own the recovery rather than
    #: an operator. Narrowing it to non-retryable strands the source in `Deleting`.
    ERROR_CLASS = ErrorClass.INTERNAL_DEPENDENCY

    def __init__(self, source_id: str, failed_stores: Sequence[str]) -> None:
        raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.verify_source_purged",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def verify_source_purged(self: Any, *, org_id: str, source_id: str, job_id: str) -> None:
    """The proof pass. `kb.deletion.verify_source_purged`, queue `maintenance`.

    Runs every check below, collects the failures rather than short-circuiting on the first —
    an operator needs the whole picture, and a store that failed silently behind an earlier
    failure is how a second incident starts. On any failure it records the result and raises
    `DeletionVerificationFailed`, leaving the source `Deleting`. On a clean pass it records
    the result, calls core-api to mark the source `Deleted`, and hands over the retained-object
    report so the admin view can show any original still held and its release date.

    The relational half is two checks, not one, and they overlap on purpose.
    `chunk_rows_remaining` asks by `source_id`, so it can see a version the purge's resolver never
    named. `version_scoped_rows_remaining` asks by the resolved version list, table by table, and
    `failed_relational_stores` turns that tally into store names — including any table the tally
    forgot, because an unmeasured store and a dirty one leave the source in the same state.

    Idempotent and safe to re-run: it is a read-only measurement plus one state transition
    that is already a no-op the second time. Verifying an already-verified source passes; so
    does verifying a source that was already gone before the purge started. "Already absent"
    is the successful outcome, never an error.
    """
    raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.verify_organization_purged",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def verify_organization_purged(self: Any, *, org_id: str, job_id: str) -> None:
    """The proof pass for an organization erasure. Runs after `purge_organization`'s fan-out and
    after `tasks._sweep_organization_residue`, and after nothing else.

    Three things, in this order, and the order is what makes the result mean anything:

    1. **Every source verified.** An organization is not clean because the sweep ran; it is clean
       because each of its sources went through `verify_source_purged` and passed. A source that
       is still `Deleting` — under a legal hold, or waiting on a reaper — stops this task. It never
       degrades to "the sweep found nothing, so we are done": the sweep's predicate cannot tell an
       organization that was purged from one that never held anything.
    2. **`organization_residue_remaining` is zero for every table**, through
       `failed_relational_stores`, so an unmeasured table fails like a dirty one.
    3. **Core-api removes the `organizations` row, and that call is the proof.** `ON DELETE
       RESTRICT` from every table in `RELATIONAL_PURGE_ORDER` — the two sparse ones since
       `2026_08_07_000600`, `chunks` and `document_elements` since Phase C created them — means
       the statement cannot succeed while one row remains,
       so a rowcount of 1 is a fact the database asserted rather than one we counted with our own
       predicate. A `RestrictViolation` here is the correct failure and a bad diagnosis: the repair
       is to find what still references the organization, not to widen a delete.

    `record_verification` stores the tallies and the sweep's own rowcounts either way — identifiers
    and counts only. A non-zero sweep tally is recorded as a **finding** even on a passing run: it
    means rows existed that no source could name, and that number is the only evidence anyone will
    ever have of how much residue had accumulated.

    Idempotent: an organization already erased has no sources to enumerate, an empty tally, and an
    `organizations` delete that removes zero rows because the row is already gone. That is a pass,
    not an error.
    """
    raise NotImplementedError


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.deletion.verify_erasure",
    queue="maintenance",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=5,
)
def verify_erasure(self: Any, *, org_id: str, scope: str, subject_ref: str, job_id: str) -> None:
    """The proof pass for a data-subject erasure.

    Re-reads each affected row **by its own identifier** — citation id, trace id, result id —
    and asserts the verbatim column is null or a tombstone while the row, its labels, its
    scores and its timestamps are still there. Erasure that is not verified is a compliance
    claim with no evidence behind it.

    Never search for the erased text to prove it is gone. That is the banned text-matching
    shape wearing a proof's clothes, and it fails in both directions: it finds another
    tenant's identical boilerplate and reports a failure that is not one, or it finds nothing
    because the text was normalised and reports a success that is not one either.
    """
    raise NotImplementedError


# ── the checks. Each returns what remains; zero is the only passing answer ────


def chunk_rows_remaining(org_id: str, source_id: str) -> int:
    """`chunks` and `document_elements` still attached to any version of this source.

    Reads the **primary**. A replica read here is the check that always passes: the lag window
    is exactly the purge that just finished.

    Scoped by `source_id`, and that is what makes it worth keeping alongside
    `version_scoped_rows_remaining`: this one can see a version the purge's own resolver missed,
    because it does not ask through the resolved version list. The version-scoped tally cannot.

    **Only `chunks` carries `source_id` as a column** (`2026_08_20_002200`). `document_elements`
    does not — its migration is explicit that it holds identity, tenancy, a parent, a sequence and
    a locator — and neither sparse table does. All three reach a source only through
    `source_version_id` → `source_versions.source_item_id` → `source_items.source_id`, which is a
    path that exists to be walked only because Phase C created those two tables; before it, the
    question could not be asked of them at all. So the by-source check is one direct predicate on
    `chunks` and a two-join path for the rest. Do not substitute the resolved version list to
    avoid the joins: that is the list `version_scoped_rows_remaining` already used, and a second
    check asking the first one's question is not a second check.
    """
    raise NotImplementedError


def version_scoped_rows_remaining(org_id: str, version_ids: Sequence[str]) -> Mapping[str, int]:
    """Run every `RELATIONAL_PURGE_ORDER` entry's `count` and return the tally per table.

    **Primary, always**, and the same statements the purge issued, differing only in their verb —
    so the proof asks the question the purge answered rather than a question a reviewer hoped was
    equivalent. Every entry is counted, including the ones expected to be zero: skipping a table
    because "the purge handles it" is how a store that silently failed gets certified.

    Bind `relational.version_scope(org_id, version_ids)`, unchanged, into each. Building a
    predicate here instead would let the proof's scope drift from the delete's, and a proof over a
    *narrower* scope than the delete is one that reports success over rows it never looked at.

    Returns a mapping keyed by table name because the tally is the durable artifact — `{"chunks":
    0, "sparse_term_frequencies": 0, …}` is what `record_verification` stores and what an operator
    reads weeks later. Identifiers and counts only; nothing in these tables is source text and
    nothing from them is logged as though it were.
    """
    raise NotImplementedError


def organization_residue_remaining(org_id: str) -> Mapping[str, int]:
    """Run every `RELATIONAL_PURGE_ORDER` entry's `org_residue_count` and return the tally per
    table. **Primary, always.** Bind `relational.organization_scope(org_id)`, unchanged.

    Runs at the end of an organization erasure, after `_sweep_organization_residue`, and the tally
    it returns goes to `failed_relational_stores` exactly like the version-scoped one — an omitted
    table is a failure here for the same reason it is there.

    **This is evidence, not the proof, and the distinction is the whole design.** Counting
    `organization_id = %s` immediately after deleting `organization_id = %s` is a tautology: the
    two agree on an answer neither measured, which is precisely what `relational.version_scope`
    refuses an empty version list to prevent. What actually verifies the sweep is external and
    belongs to the schema — `organizations` is `ON DELETE RESTRICT` from every table in
    `RELATIONAL_PURGE_ORDER`, so core-api's delete of the organization row **cannot succeed while
    a single row remains**. That
    delete is Laravel's, over the signed seam, and its success is what `verify_organization_purged`
    records.

    The tally is still taken, because the foreign key only proves the tables that have one. That
    used to leave two of the four uncovered — no migration in this repository created `chunks` or
    `document_elements`, which was finding #79 — and `2026_08_20_002100` and `2026_08_20_002200`
    closed it: both now reference `organizations (id) ON DELETE RESTRICT` like the sparse pair, so
    this count is no longer the sole cover for any entry in the tuple. It stays because a table
    added to `RELATIONAL_PURGE_ORDER` later may not carry that edge, because the foreign key
    proves only the schema actually deployed under the worker, and because the count is the
    artifact an operator reads weeks later when the question is how much residue there was.

    A non-zero value here **before** the sweep is the finding the sweep exists to surface: rows the
    per-source fan-out could not name, because the version that named them is already gone. Record
    the count; never widen a version-scoped statement to absorb it.
    """
    raise NotImplementedError


def failed_relational_stores(tallies: Mapping[str, int]) -> tuple[str, ...]:
    """Which relational tables must stop this source becoming `Deleted`. Pure — the counts come
    from the caller's own connection to the primary.

    **A table missing from `tallies` fails exactly like a table with rows in it**, and that is the
    assertion this function exists for. "Not empty" and "not measured" are the same outcome to
    everyone downstream: both leave the source in `Deleting` and both send the reaper round again.
    Treating an absent key as a pass is the precise shape of the gap this module was extended to
    close — the purge grew two tables, the proof did not, and every verification since had been
    reporting a clean result over a store it never queried. A proof that can only report on the
    stores someone remembered to hand it is not a proof.

    Returns table names in `RELATIONAL_PURGE_ORDER`'s own order, so the failure list reads in the
    order the purge would retry them. Never contents, and never a count in the name.
    """
    failed: list[str] = []
    for step in RELATIONAL_PURGE_ORDER:
        remaining = tallies.get(step.table)
        if remaining is None or remaining > 0:
            failed.append(step.table)
    return tuple(failed)


def points_remaining(org_id: str, source_id: str) -> int:
    """Points still in the collection for this identity.

    `filters.identity_filter` — `org_id` + `source_id`, nothing else — and an exact tally,
    passed explicitly as `exact=True`. Explicitly, because the Python client and the REST wire
    disagree with Qdrant's own prose: an approximate result merges per-segment cardinality
    estimates with no cross-segment dedup, so it is a guess rather than a stale truth, and a
    smoke check written in curl or in PHP gets the wire default rather than the client's.

    Never read the collection's `points_count` for this. It is documented as approximate and
    inflates while the optimizer temporarily duplicates points.

    Search exclusion is what this proves, which is what §8.17 and the demo need. It is not
    erasure: a Qdrant delete flips a soft-delete bit, and the raw vector bytes stay in the
    segment until the vacuum optimizer rebuilds it — which needs that segment to be 20%
    deleted *and* to hold at least 1000 vectors, so a small segment is never reclaimed on any
    timeline. A snapshot stores segments verbatim, so a backup taken in between carries the
    removed vectors and restoring it restores them. An erasure attestation additionally needs
    a forced re-index and a backup-expiry plan; do not let this check imply it has one.
    """
    raise NotImplementedError


def active_assignments_remaining(org_id: str, source_id: str) -> int:
    """Bot assignments still active for this source. Primary, again.

    Phase 1 deactivated these before it committed, so a non-zero answer here means phase 1 was
    rolled back or never ran and the purge went ahead anyway — a worse finding than a stray
    vector, because it means the ordering guarantee failed.
    """
    raise NotImplementedError


def object_versions_remaining(org_id: str, source_id: str, prefix: str) -> int:
    """Objects still under the asserted prefix — enumerated as object *versions*, and only after
    asserting the bucket is unversioned.

    **The asserted prefix for a full purge is the source prefix**,
    `org/{org_id}/sources/{source_id}/`, and that is a change from what this docstring used to
    say. `original/` is no longer a child of a version prefix; it is a sibling of `versions/`
    (`tasks._purge_objects` carries the shape and the reason, `ObjectKey::originalPrefix()` in
    core-api carries the ruling). Enumerating `…/versions/{id}/` for each version therefore proves
    nothing whatever about the original — it walks past the bytes and certifies the source clean.
    One enumeration of the source prefix covers both families in one call, and covers a version
    the purge's own resolver never named as well.

    **When the original is retained, the asserted prefix narrows to
    `org/{org_id}/sources/{source_id}/versions/`** — every derived artifact of every version,
    still one enumeration, still no per-version loop. The retained object under `original/` is
    enumerated separately and *reported*, with its release date, rather than counted as remaining.
    That narrowing is not the widening this file warns about and not its mirror image either: the
    prefix stays inside the source, and what it covers is the whole of what a purge that kept the
    original was asked to remove. This is where the layout change makes the check simpler rather
    than harder — under the old shape `original/` sat inside the prefix being asserted, and no
    listing call can exclude a sub-prefix from an enumeration.

    **Retained and missed are not told apart by what is found.** Which of the two assertions
    applies is read from the disposition `original_disposition` recorded *before* the sweep ran,
    never inferred from whether objects turned up. An object under `original/` when the recorded
    disposition was *remove* is a failed purge; an empty `original/` when the disposition was
    *retain* is a different failure — a retention or hold obligation destroyed, with the admin
    view about to report an object that is gone. Both are findings, they are opposite findings,
    and only the recorded decision separates them. That is why the decision is recorded either
    way, and why this check reads it rather than reconstructing it.

    `org_id` and `source_id` are not redundant beside `prefix`; they are what makes the prefix
    checkable. A prefix that does not begin `org/{org_id}/sources/{source_id}/` is refused rather
    than enumerated, so a proof cannot be run against a string that is wider than — or simply
    elsewhere than — the identity it claims to be about. A verification pointed at the wrong
    prefix passes, quietly, forever.

    Both halves of the store call are load-bearing. Versioning silently defeats the entire
    deletion contract: every delete becomes a delete marker, a key listing keeps reporting the
    prefix clean, and the bytes keep billing. Enumerating versions is the only call that sees the
    delete markers and the noncurrent versions a key listing hides, so the day someone changes the
    posture this check fails loudly instead of certifying an empty prefix full of data. The
    versioning status is asserted for the bucket, once, whichever prefix is being enumerated.

    It still cannot see abandoned multipart uploads — no listing of keys or versions can. Those
    are reclaimed by the daily multipart sweep, and a prefix that verifies empty while volumes
    grow is that, not a failure of this check.
    """
    raise NotImplementedError


def cache_keys_remaining(org_id: str, source_id: str) -> int:
    """Cached answers, retrieval results and fingerprints still referencing the source.

    Read the tracked index for the source and test the members. Never `SCAN` with a match
    pattern: SCAN only guarantees keys present for the whole iteration, so a key written
    mid-iteration is exactly what it will miss — and a key written mid-iteration is the
    read-repopulate race this check exists to catch. The tracked index lives on the core
    instance, so a full eviction of the cache instance leaves this check still able to
    enumerate.

    A TTL is not a deletion guarantee and must never stand in for this: expired keys stay
    resident until touched or sampled, and a snapshot taken before expiry stores the value
    together with its TTL.
    """
    raise NotImplementedError


# ── the artifact ─────────────────────────────────────────────────────────────


def record_verification(
    job_id: str,
    org_id: str,
    source_id: str,
    results: Mapping[str, int],
    *,
    passed: bool,
) -> None:
    """Persist the result — the durable evidence that the deletion happened.

    Failures are recorded too, and are recorded first: a verification that only writes a row
    when it passes leaves the interesting case as an exception trace in a worker log with a
    retention shorter than the obligation it was meant to prove.

    Identifiers and tallies only. No chunk contents, no excerpt, no key contents — an audit
    row holding the removed material is the one artifact that turns a completed deletion back
    into an open finding.
    """
    raise NotImplementedError

"""Publication: verify → ready → switch → retire — and the two steps of those four that are
not ours.

**THIS SERVICE NEVER ASSIGNS THE ACTIVE-VERSION POINTER.** Read that again before editing
anything in this file. The data plane's last act in a run is a status callback carrying the
verified totals, the checksum and the readiness verdict; Laravel marks the version ready,
flips ``source_items.current_version_id``, and retires the prior row — all three inside one
transaction, where the audit entry, the policy check and the retention clock already are
(ADR-012).

This is not bureaucracy, and the failure it prevents is not a lost write. The partial unique
index enforcing "at most one active version per item" is a *database* constraint; a second
writer racing it raises an integrity error **inside a Celery task**, which retries, which
raises again. Meanwhile the ingest reports success and the bot keeps answering from the
previous version. The queue burns a worker forever and every dashboard is green.

**Nothing stops you writing that assignment.** A gate used to grep `app/` for it, precisely
because the symptom is so far from the cause; `.github/` was deleted on 2026-08-17 and nothing
replaced it, no test asserts the column's absence from this tree, and `ALLOWED_TABLES` has no
runtime guard behind it — it is a list a reviewer reads, not a check a statement passes. The one
mechanism that is real is the database constraint, and it is the trap rather than the guard:
`source_versions_one_active_per_item` exists as of Phase C1
(`2026_08_20_002000_create_source_versions_table.php:227`), against a
`source_items.current_version_id` that also now exists. So a second writer here does not fail to
find a constraint — it finds one, inside a Celery task, in exactly the shape described above.
The rule holds by review: do not write an assignment to that column anywhere in this tree. The
old corollary "not even in a comment" was a property of the prose-blind grep and is retired
along with it — this paragraph names the column four times on purpose.

The split is drawn where the knowledge is: only the worker that wrote the points can prove
they are all there, and only the control plane can decide what every tenant's next query sees.

Ordering is the whole mechanism, and it does not tolerate rearrangement:

* **Verify before ready.** Marking ready on an unverified index is what users experience as
  "the bot only knows half the document" (§8.9, §13.5).
* **Ready before switch.** The pointer is the only thing that makes a version live.
* **Switch before retire**, and **retire before the old vectors are removed** — on a delay, not
  inline. A request that already retrieved ids from the retired version and is still reranking
  or generating will otherwise cite points that no longer exist.

There is no "activate then backfill" variant of this. A run that dies anywhere before the
callback leaves the prior version serving and a complete-but-unreachable point set behind,
which the orphan sweep collects; that is the designed failure, not a degradation to work
around.
"""

from __future__ import annotations

import hashlib
from collections.abc import Callable, Mapping
from dataclasses import dataclass, field
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.ingestion.frames import FrameScope, progress_frame, readiness_frame
from app.ingestion.indexing.upserter import VerificationResult, verify_indexed_total
from app.ingestion.states import SourceState
from app.retrieval.collection import EmbeddingSpace

__all__ = [
    "PUBLICATION_ORDER",
    "Emitter",
    "ReadinessReport",
    "content_checksum",
    "publish_version",
    "report_identity",
    "report_progress",
    "report_readiness",
    "set_progress_emitter",
    "set_readiness_emitter",
    "verify_version",
]

#: What a callback transport looks like from in here: a frame, the organization it is about,
#: and the ``X-KB-Operation`` it travels under. It returns the ACKNOWLEDGEMENT rather than
#: nothing, because a refused frame on this seam is a 200 — a caller that discards the body
#: cannot tell an applied frame from a superseded one, and the two have opposite consequences.
Emitter = Callable[[dict[str, Any], str, str], dict[str, Any]]

#: The four steps, their owner, and the fact each one establishes. Data rather than prose so a
#: test can assert the order and a reviewer can see the seam at a glance. Steps 3 and 4 are
#: listed because omitting them from the sequence is how someone concludes the worker should
#: do them.
PUBLICATION_ORDER: Final[tuple[tuple[int, str, str, str], ...]] = (
    (1, "index", "ai-worker", "points exist for the new version, unreachable by any query"),
    (2, "verify", "ai-worker", "the indexed total equals the expected chunk total, exactly"),
    (3, "ready+switch", "laravel", "the version is marked ready and the pointer names it"),
    (4, "retire", "laravel", "the prior version is retired; its vectors go later, on a delay"),
)


@dataclass(frozen=True, slots=True)
class ReadinessReport:
    """The payload of the ingestion status callback — the data plane's half of publication.

    Naming the pointer column in a payload model is fine and assigning it is not; this type
    deliberately carries no field that names a version as active. It reports *facts about what
    was written*, and the control plane decides what to do with them.

    ``warning_summary`` is why `Ready with warnings` exists as a separate lifecycle state: a
    document that parsed at 40% OCR confidence publishes and answers exactly like a clean one,
    and the warning is the only trace that says why its answers are poor. Discarding warnings
    here turns a support ticket into an unfalsifiable one.

    **It carries COUNTS, not a set of codes.** A version with one low-confidence page and one
    with forty produce the same set and very different documents, and the count is the only
    thing that tells an operator which they are looking at. `IngestionCallbackRequest` validates
    the field as an object precisely so the counts survive; a list would arrive as
    ``{"0": "ocr_low"}`` and publish warning codes named `0` and `1`.

    THE FIELD THAT USED TO BE HERE, AND WHY IT IS NOT
    -------------------------------------------------
    ``state`` was a string in this service's own vocabulary — ``indexed_verified`` — and
    ``indexed_verified`` is not one of the fifteen ``SourceState`` values the callback's
    ``status`` rule admits. Every frame built from it would have been a 422. The verdict is now
    a ``bool`` and `frames.readiness_frame` is the one place it becomes a lifecycle state, so
    the translation happens once, in a function with a test, instead of at each construction
    site.

    ``content_checksum`` IS COMPUTED AND IS NOT ON THE WIRE, which is a real contract gap rather
    than a decision. `IngestionCallbackRequest` has no rule for it, and Laravel's `validated()`
    silently drops unvalidated keys — so sending it would look delivered and be discarded. It is
    kept here because it is the cheap half of a check the control plane cannot currently make,
    and recorded rather than quietly dropped: adding the column and the rule is a control-plane
    change, and inventing a field the other side ignores is not a substitute for one.
    """

    #: The four identifiers every frame carries. See `frames.FrameScope` — in particular why
    #: ``job_id`` is the submission's and not this version's.
    scope: FrameScope
    #: This service's own handle on the version, for spans and logs. NOT sent: the callback
    #: contract has no version id, because Laravel resolves the row from the identity below.
    source_version_id: str
    sequence: int
    #: All six components. Built by `frames.identity_payload`, which makes the partial and
    #: empty cases unconstructible.
    identity: Mapping[str, str]
    #: The verification verdict, and nothing else decides whether this publishes.
    verified: bool
    chunk_total: int
    #: sha256 over the version's chunk **content hashes**, in `seq` order.
    #:
    #: Over content hashes and deliberately never over vectors. Embedding is an API call now
    #: and providers are not bit-reproducible — the same text re-embedded returns vectors that
    #: differ in their last bits — so a vector-derived checksum would report every legitimate
    #: replay as a content change. Which model produced the vectors is carried separately, on
    #: every chunk's `embedding_model_id`.
    content_checksum: str
    #: Advisory only (§8.11). Parser and OCR warnings never fail a version.
    warning_summary: Mapping[str, int] = field(default_factory=dict)
    #: The durable per-version redelivery count. The worker owns the number and cannot write
    #: the column, because `source_versions` is not in `ALLOWED_TABLES` and never may be.
    delivery_count: int | None = None
    error_class: str | None = None


def verify_version(
    *,
    client: Any,
    space: EmbeddingSpace,
    org_id: str,
    source_version_id: str,
    expected_chunks: int,
) -> VerificationResult:
    """Step 2. Delegates to `indexing.upserter.verify_indexed_total`; kept here so the whole
    sequence reads in one file.

    Fails the run on a mismatch rather than raising past it silently, and never repairs what
    it measures. On failure the version lands in `Failed`, the prior version is still active
    and untouched, and the failure is reviewable (§13.7).

    `space` is the version's own embedding space and travels the whole way down. Embedding is a
    provider call now, so a deployment can hold more than one space and therefore more than one
    collection; a proof that counts the wrong one is not a weaker proof but a proof of a
    different claim, and it can pass a version that was never written.
    """
    return verify_indexed_total(
        client=client,
        space=space,
        org_id=org_id,
        source_version_id=source_version_id,
        expected=expected_chunks,
    )


def report_identity(
    *,
    scope: FrameScope,
    sequence: int,
    identity: Mapping[str, str],
    status: SourceState,
    stage: str,
    delivery_count: int | None = None,
) -> dict[str, Any]:
    """The frame that brings a ``source_versions`` row into existence. Step 0, and it is Laravel's.

    THIS IS THE ONE FRAME WHOSE LOSS IS NOT SURVIVABLE MID-RUN, which is why it goes through the
    must-arrive emitter and not the progress one. The data plane computes the six identity
    components — it is the only side that can (`VersionIdentity`'s docblock enumerates why for
    each) — and Laravel writes the row, keyed by ``(source_item_id, ingest_key)``. Until that
    row exists there is no ``source_version_id`` to write a chunk against, so a swallowed
    failure here does not degrade the run, it deletes it.

    It returns the acknowledgement because the caller has a decision to make that only the body
    can answer: ``live_version_unchanged`` means this submission re-derived the identity of the
    version already serving the item, so the correct next action is to do **nothing at all** —
    not to parse, not to embed, and above all not to re-index a corpus at a provider's
    per-token price for a document that has not changed.
    """
    emit = _emitter
    if emit is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "no callback emitter is configured on this worker, so the version identity cannot "
            "be reported and no version row will ever exist for this item. Every stage after "
            "this one writes against a version id, so there is nothing to degrade to",
            retryable=True,
        )
    return emit(
        progress_frame(
            scope=scope,
            sequence=sequence,
            stage=stage,
            status=status,
            identity=identity,
            delivery_count=delivery_count,
        ),
        scope.org_id,
        "ingestion.identity",
    )


def report_readiness(report: ReadinessReport) -> dict[str, Any]:
    """Step 2½ — the data plane's last act.

    Idempotent on `source_version_id` plus `state`: the callback is delivered at-least-once
    like everything else, and the control plane's activation transaction is itself a no-op the
    second time because the pointer already names this version.

    A transport failure here is `internal_dependency` and retryable. It must not be swallowed:
    a version that indexed and verified but never reported readiness stays unpublished forever
    with a full, correct point set on disk — invisible to retrieval and invisible to deletion.
    """
    emit = _emitter
    if emit is None:
        # NOT A NO-OP, AND NOT A LOG LINE. An unwired emitter means this process cannot tell the
        # control plane anything, and the docstring above names exactly what that produces: a
        # verified point set that is invisible to retrieval AND to deletion, forever. Raising is
        # the only outcome that leaves the version reviewable.
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "no readiness emitter is configured on this worker, so a verified version cannot "
            "be reported. The run is complete on disk and unpublishable; failing here keeps it "
            "in a state a person can see rather than one only an orphan sweep can find",
            retryable=True,
        )
    return emit(
        readiness_frame(
            scope=report.scope,
            sequence=report.sequence,
            verified=report.verified,
            chunk_total=report.chunk_total,
            identity=report.identity,
            warning_summary=report.warning_summary,
            delivery_count=report.delivery_count,
            error_class=report.error_class,
        ),
        report.scope.org_id,
        "ingestion.readiness",
    )


def publish_version(
    *,
    client: Any,
    space: EmbeddingSpace,
    scope: FrameScope,
    source_version_id: str,
    sequence: int,
    identity: Mapping[str, str],
    chunks: list[Any],
    warning_summary: Mapping[str, int] | None = None,
    delivery_count: int | None = None,
) -> None:
    """Index → verify → report. Stops there, deliberately and permanently.

    Everything after the report is Laravel's, on the callback, in one transaction. If this
    function ever grows a fourth step, the reviewer's question is not "is the transaction
    right" but "why is the data plane deciding what is live".

    One `space` for the whole call, resolved once at the start of the run. Re-resolving it
    between index and verify would let a mid-run provider change split a version across two
    collections — and the point total in each would still be an integer nobody could fault.

    The identifiers are explicit parameters rather than attributes read off a ``version``
    object. That object used to supply ``org_id`` and ``source_version_id`` by ``getattr``, and
    a duck-typed source for the fields whose wrong values fail silently — ``job_id`` above all —
    is how the wrong value gets there: a type that merely lacks the attribute yields ``None``
    through `getattr` and a frame that 422s, while a type that has a *similarly named* one
    yields a plausible id and a frame that is discarded as stale.
    """
    verification = verify_version(
        client=client,
        space=space,
        org_id=scope.org_id,
        source_version_id=source_version_id,
        expected_chunks=len(chunks),
    )

    report_readiness(
        ReadinessReport(
            scope=scope,
            source_version_id=source_version_id,
            sequence=sequence,
            identity=identity,
            # VERIFIED ONLY WHEN THE PROOF PASSED. A report that names the verdict
            # optimistically and carries a failing total is a report the control plane will
            # activate on: it reads the flag, not the arithmetic.
            verified=verification.passed,
            chunk_total=verification.indexed,
            content_checksum=content_checksum(chunks),
            warning_summary=warning_summary or {},
            delivery_count=delivery_count,
            error_class=None if verification.passed else ErrorClass.VECTOR_INDEXING.value,
        )
    )

    if not verification.passed:
        raise KbError(
            ErrorClass.VECTOR_INDEXING,
            f"version {source_version_id} verified {verification.indexed} points "
            f"against {verification.expected} expected. The report above already told the "
            "control plane it failed, so the prior version keeps serving; this raise is what "
            "stops the caller treating the run as complete",
            retryable=True,
        )


def content_checksum(chunks: list[Any]) -> str:
    """sha256 over the version's chunk **content hashes**, in `seq` order.

    Over content hashes and never over vectors, which the `ReadinessReport` field comment
    states and this implementation is bound by: embedding is an API call now and providers are
    not bit-reproducible, so the same text re-embedded returns vectors differing in their last
    bits — a vector-derived checksum would report every legitimate replay as a content change.

    Sorted by `seq` rather than by hash. The order IS part of the identity: two versions holding
    the same chunks in a different order are different documents, and a hash-sorted digest would
    call them equal.
    """
    digest = hashlib.sha256()
    for chunk in sorted(chunks, key=lambda c: c.metadata.seq):
        digest.update(chunk.metadata.content_hash.encode("ascii"))
        digest.update(b"|")
    return digest.hexdigest()


def report_progress(*, scope: FrameScope, sequence: int, progress: Any) -> None:
    """One mid-run progress frame. Same transport as readiness, different consequence.

    A LOST PROGRESS FRAME IS NOT A LOST RUN, and that asymmetry is why this is a separate
    function rather than a flag on `report_readiness`. Progress drives a status pill; readiness
    drives activation. So a transport failure here is swallowed after the emitter has had its
    say, and a transport failure there is raised — reversing the two is the mistake that either
    fails a completed version because a pill did not update, or publishes nothing while every
    pill animates.

    `sequence` is what makes the frame safe to lose: the control plane discards any frame whose
    sequence is not greater than the last it applied, so an out-of-order retry cannot roll a
    source's displayed stage backwards. It is ALLOCATED BY THE CALLER, from a `Sequencer` seeded
    off the item's row — this function must not invent one, because two functions counting
    independently is two sequences racing for the same column.

    THE `job_id` THIS SENDS IS THE SUBMISSION'S, and it used to be the version id. That was a
    silent total failure and it is worth stating rather than fixing quietly: Laravel compares
    the value against `source_items.current_job_id` and answers a mismatch with `stale_job` —
    HTTP 200, `applied: false`, no error raised on either side. Every frame of every run would
    have been discarded while the queue drained normally.
    """
    emit = _progress_emitter
    if emit is None:
        return
    try:
        emit(
            progress_frame(
                scope=scope,
                sequence=sequence,
                stage=progress.stage,
                status=progress.status,
                chunk_count=progress.chunk_count,
                warning_summary=getattr(progress, "warning_summary", None),
            ),
            scope.org_id,
            "ingestion.progress",
        )
    except Exception:
        # Deliberate, and narrow in effect rather than in type: the frame is advisory and the
        # run continues. Catching by type would miss the transport exception a future client
        # raises, and the cost of missing it is a completed ingest failed by a status update.
        return


#: The process-wide progress emitter. Separate from the readiness one so a deployment can wire
#: readiness without progress — the reverse of the pair that matters, since readiness is what
#: activation depends on.
_progress_emitter: Emitter | None = None


def set_progress_emitter(emit: Emitter | None) -> None:
    """Install the progress transport. Called once by the worker bootstrap, and by tests."""
    global _progress_emitter
    _progress_emitter = emit


#: The process-wide readiness emitter, installed by the worker bootstrap. A module-level hook
#: rather than a parameter threaded through `publish_version`, because the alternative is an
#: HTTP client and a signing key travelling as arguments through every stage function that has
#: no business holding either.
_emitter: Emitter | None = None


def set_readiness_emitter(emit: Emitter | None) -> None:
    """Install the callback transport. Called once by the worker bootstrap, and by tests.

    Exists so this module holds no `httpx` import and no key ring: the emitter is built where
    the key ring already lives, and this file stays a description of the publication sequence
    rather than a second place transport concerns are configured.

    THE SAME SIGNATURE AS THE PROGRESS HOOK, AND STILL TWO HOOKS. Both take ``(frame, org_id)``
    now that `frames` builds the body, so the pair could be collapsed — and must not be. Each
    closure carries its own ``X-KB-Operation``, which is what makes a redelivery storm on the
    progress path distinguishable from one on the publication path in the control plane's logs;
    and the two differ in the only way that matters at the call site, which is that a transport
    failure on this one is raised and on the other one is swallowed.
    """
    global _emitter
    _emitter = emit


# The sequence is data, so a rearrangement is a diff someone must justify rather than a subtle
# reordering of statements in a function body.
assert [s[1] for s in PUBLICATION_ORDER] == ["index", "verify", "ready+switch", "retire"]
assert [s[2] for s in PUBLICATION_ORDER[2:]] == ["laravel", "laravel"]

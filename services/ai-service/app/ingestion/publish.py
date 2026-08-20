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

from dataclasses import dataclass
from typing import Any, Final

from app.ingestion.indexing.upserter import VerificationResult
from app.retrieval.collection import EmbeddingSpace

__all__ = [
    "PUBLICATION_ORDER",
    "ReadinessReport",
    "publish_version",
    "report_readiness",
    "verify_version",
]

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

    `warnings` is why `Ready with warnings` exists as a separate lifecycle state: a document
    that parsed at 40% OCR confidence publishes and answers exactly like a clean one, and the
    warning is the only trace that says why its answers are poor. Discarding warnings here
    turns a support ticket into an unfalsifiable one.
    """

    source_version_id: str
    org_id: str
    #: `indexed_verified` on success; a terminal failure reports its `error_class` instead.
    state: str
    chunk_total: int
    #: sha256 over the version's chunk **content hashes**, in `seq` order — lets the control
    #: plane detect a second run that produced different content without re-reading every
    #: chunk.
    #:
    #: Over content hashes and deliberately never over vectors. Embedding is an API call now
    #: and providers are not bit-reproducible — the same text re-embedded returns vectors that
    #: differ in their last bits — so a vector-derived checksum would report every legitimate
    #: replay as a content change. Which model produced the vectors is carried separately, on
    #: every chunk's `embedding_model_id`.
    content_checksum: str
    #: Advisory only (§8.11). Parser and OCR warnings never fail a version.
    warnings: tuple[str, ...]
    error_class: str | None


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
    raise NotImplementedError


def report_readiness(report: ReadinessReport) -> None:
    """Step 2½ — the data plane's last act.

    Idempotent on `source_version_id` plus `state`: the callback is delivered at-least-once
    like everything else, and the control plane's activation transaction is itself a no-op the
    second time because the pointer already names this version.

    A transport failure here is `internal_dependency` and retryable. It must not be swallowed:
    a version that indexed and verified but never reported readiness stays unpublished forever
    with a full, correct point set on disk — invisible to retrieval and invisible to deletion.
    """
    raise NotImplementedError


def publish_version(*, client: Any, space: EmbeddingSpace, version: Any, chunks: list[Any]) -> None:
    """Index → verify → report. Stops there, deliberately and permanently.

    Everything after the report is Laravel's, on the callback, in one transaction. If this
    function ever grows a fourth step, the reviewer's question is not "is the transaction
    right" but "why is the data plane deciding what is live".

    One `space` for the whole call, resolved once at the start of the run. Re-resolving it
    between index and verify would let a mid-run provider change split a version across two
    collections — and the point total in each would still be an integer nobody could fault.
    """
    raise NotImplementedError


# The sequence is data, so a rearrangement is a diff someone must justify rather than a subtle
# reordering of statements in a function body.
assert [s[1] for s in PUBLICATION_ORDER] == ["index", "verify", "ready+switch", "retire"]
assert [s[2] for s in PUBLICATION_ORDER[2:]] == ["laravel", "laravel"]

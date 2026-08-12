"""The source-version lifecycle states, as data.

Transcribed from `kb-source-lifecycle` (docs/03-functional-knowledge-sources.md §8.9). This is
**control-plane state**: Laravel owns the `source_versions` row, writes every transition, and
performs the activation. The data plane observes these values and *reports* progress on the
ingestion status callback — it never assigns one. A worker that writes a lifecycle state is
a second writer on a column the partial unique index guards, and that failure surfaces as an
integrity error inside a Celery task that retries forever.

Two things this file exists to make unbreakable:

* **15 values, not 14.** Early summaries collapsed ``READY`` and ``READY_WITH_WARNINGS`` into
  one because they behave identically for retrieval. They are separate rows in the contract,
  and the collapse loses the only signal that says "this document parsed badly but published".
* **``INDEXING`` is not retrievable.** Points exist in the collection at that state and the
  active-version pointer still names the previous version — which is exactly the property that
  makes publication atomic. Anything that treats "points are present" as "the version is live"
  has re-invented the half-a-document bug.
"""

from __future__ import annotations

from enum import StrEnum
from typing import Final

__all__ = [
    "DATA_PLANE_OBSERVED",
    "PROCESSING",
    "RETRIEVABLE",
    "TERMINAL",
    "SourceState",
]


class SourceState(StrEnum):
    """In contract order — the order §8.9 enumerates them, which is also the order a healthy
    run walks. Do not re-sort alphabetically; the ordering is what makes a state diagram
    reviewable against the spec."""

    DRAFT = "draft"
    QUEUED = "queued"
    FETCHING = "fetching"
    PARSING = "parsing"
    NORMALIZING = "normalizing"
    CHUNKING = "chunking"
    EMBEDDING = "embedding"
    INDEXING = "indexing"
    READY = "ready"
    READY_WITH_WARNINGS = "ready_with_warnings"
    FAILED = "failed"
    DISABLED = "disabled"
    DELETING = "deleting"
    DELETED = "deleted"
    ARCHIVED = "archived"


#: The version states an ingestion run walks, rolled up onto the item and the source for
#: display. While any of these is current the *prior* version keeps serving every query.
PROCESSING: Final[frozenset[SourceState]] = frozenset(
    {
        SourceState.FETCHING,
        SourceState.PARSING,
        SourceState.NORMALIZING,
        SourceState.CHUNKING,
        SourceState.EMBEDDING,
        SourceState.INDEXING,
    }
)

#: Reachable by a query — and only ever *through the active-version pointer*. Points can be
#: present in the collection for a version in any other state and remain unreachable, which is
#: the whole mechanism. `READY_WITH_WARNINGS` is identical to `READY` here on purpose: parser
#: and OCR warnings are advisory (§8.11), never a retrieval predicate.
RETRIEVABLE: Final[frozenset[SourceState]] = frozenset(
    {SourceState.READY, SourceState.READY_WITH_WARNINGS}
)

#: No legal edge leaves these for this run. `FAILED` is terminal *for the run*, not for the
#: item — a fresh run re-enters at `QUEUED` with a new version row.
TERMINAL: Final[frozenset[SourceState]] = frozenset({SourceState.DELETED})

#: The states an ingestion worker may report progress *toward*. Deliberately excludes both
#: Ready flavours: the worker's last act is reporting verified readiness with its totals and
#: checksum, and Laravel decides which of the two the row lands in and flips the pointer in the
#: same transaction. A worker that reports `READY` has claimed an authority it does not have.
DATA_PLANE_OBSERVED: Final[frozenset[SourceState]] = PROCESSING | {SourceState.FAILED}


# Checked at import, not in a test: a lifecycle enum that lost a value must not survive to a
# running process, because every consumer of it silently takes its fallback branch instead.
assert len(SourceState) == 15
assert RETRIEVABLE.isdisjoint(PROCESSING)
assert SourceState.INDEXING not in RETRIEVABLE

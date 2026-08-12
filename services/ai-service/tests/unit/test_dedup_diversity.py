"""Stage 10 — exact duplicates, the per-document cap, and the adjacency exemption.

The adjacency exemption is the one with no error to catch it. Without it the answer stops one
sentence short of the fact and the missing sentence is in the corpus: the chunk that completed
the passage was dropped for belonging to a document that had already contributed its quota. The
answer is fluent, the citations are real, and the omission is invisible from the outside.

Every drop is recorded. §8.24 requires the playground to show excluded results *and reasons*,
and a stage that filters without recording makes the panel show fewer candidates than were
fused with no row explaining the difference.

There is no near-duplicate penalty — dropped by ruling on 2026-08-12, because the only detector
this tree can offer (the chunker's ``overlap_of``) fires exactly on candidates the adjacency
exemption already spares, so any implementation would be vacuous. The last two tests here are
the regression guard: the fused scores of retained candidates are untouched, and
``near_duplicate_penalized`` is absent from the exclusion vocabulary.
"""

from __future__ import annotations

from typing import Any, Final

import pytest
from qdrant_client import models

import app.rag.evidence as evidence_module
from app.rag.evidence import dedup_and_diversify
from app.rag.stages import ExclusionReason
from app.retrieval.search import Candidate


class RecordingRun:
    """A ``StageRun`` that remembers every drop and every label assignment."""

    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason]] = []
        self.labels: dict[str, str] = {}

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason))

    def record_labels(self, labels: Any) -> None:
        self.labels = dict(labels)

    def remaining_seconds(self) -> float:
        return 10.0

    def dropped_for(self, reason: ExclusionReason) -> list[str]:
        return [chunk_id for chunk_id, seen in self.excluded if seen is reason]


def candidate(
    chunk_id: str,
    *,
    source_id: str = "src-1",
    content_hash: str | None = None,
    version: str | None = "ver-1",
    seq: int | None = None,
) -> Candidate:
    payload: dict[str, object] = {"chunk_id": chunk_id, "source_id": source_id}
    if content_hash is not None:
        payload["content_hash"] = content_hash
    if version is not None:
        payload["source_version_id"] = version
    if seq is not None:
        payload["seq"] = seq
    return Candidate(
        point=models.ScoredPoint(id=1, version=1, score=0.5, payload=payload),
        fused_score=0.5,
    )


IDS: Final = ExclusionReason


def kept(result: list[Candidate]) -> list[str]:
    return [item.chunk_id for item in result]


# ── exact duplicates ─────────────────────────────────────────────────────────


def test_a_byte_identical_chunk_is_dropped_and_recorded() -> None:
    """Headers, disclaimers, licence blocks and pricing tables repeat verbatim across
    documents, so this is the common case rather than the pathological one."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [
            candidate("a", content_hash="h1"),
            candidate("b", source_id="src-2", content_hash="h1"),
        ],
        run,
        max_per_document=5,
    )
    assert kept(result) == ["a"]
    assert run.dropped_for(IDS.EXACT_DUPLICATE) == ["b"]


def test_the_first_occurrence_in_fused_order_is_the_one_retained() -> None:
    """Order matters and is the fused order, so the surviving copy is the better-ranked one
    rather than whichever document happened to sort first by id."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("better", content_hash="h1"), candidate("worse", content_hash="h1")],
        run,
        max_per_document=5,
    )
    assert kept(result) == ["better"]


def test_a_candidate_with_no_content_hash_is_never_an_exact_duplicate() -> None:
    """ "Cannot be proven identical" is not "is identical". Dedup is a quality control, and
    inventing a hash from the projected payload would compare metadata rather than content."""
    run = RecordingRun()
    result = dedup_and_diversify([candidate("a"), candidate("b")], run, max_per_document=5)
    assert kept(result) == ["a", "b"]
    assert run.excluded == []


def test_an_exact_duplicate_is_dropped_even_when_it_is_adjacent() -> None:
    """The exemption exists so a passage can be completed by its neighbour. A byte-for-byte
    repeat completes nothing and spends context budget saying the same thing twice."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("a", content_hash="h1", seq=4), candidate("b", content_hash="h1", seq=5)],
        run,
        max_per_document=5,
    )
    assert kept(result) == ["a"]
    assert run.dropped_for(IDS.EXACT_DUPLICATE) == ["b"]


# ── the per-document cap ─────────────────────────────────────────────────────


def test_one_document_cannot_take_more_than_the_cap() -> None:
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate(f"c{i}") for i in range(5)],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1"]
    assert run.dropped_for(IDS.DOCUMENT_DIVERSITY_CAP) == ["c2", "c3", "c4"]


def test_the_cap_is_per_document_and_not_global() -> None:
    run = RecordingRun()
    result = dedup_and_diversify(
        [
            candidate("a1", source_id="src-1"),
            candidate("a2", source_id="src-1"),
            candidate("b1", source_id="src-2"),
            candidate("b2", source_id="src-2"),
        ],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["a1", "a2", "b1", "b2"]
    assert run.excluded == []


def test_a_candidate_with_no_source_id_raises_rather_than_escaping_the_cap() -> None:
    """``source_id`` is one of the six payload fields asserted at write time. Treating a
    missing one as its own document would switch the cap off for exactly the malformed points,
    silently — the same reasoning that makes ``Candidate.chunk_id`` raise."""
    run = RecordingRun()
    with pytest.raises(ValueError, match="no source_id"):
        dedup_and_diversify([candidate("a", source_id="")], run, max_per_document=2)


# ── the adjacency exemption ──────────────────────────────────────────────────


def test_a_chunk_adjacent_to_a_retained_one_survives_a_full_cap() -> None:
    """**The exemption.** ``c2`` is over the cap and is the next sentence of ``c1``."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("c0", seq=7), candidate("c1", seq=8), candidate("c2", seq=9)],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1", "c2"]
    assert run.excluded == []


def test_the_exemption_reaches_backwards_as_well_as_forwards() -> None:
    """Fused order is not document order, so the completing chunk arrives before the completed
    one about half the time."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("c0", seq=7), candidate("c1", seq=8), candidate("c2", seq=7 - 1)],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1", "c2"]


def test_a_distant_chunk_of_the_same_document_is_still_capped() -> None:
    """The exemption is adjacency, not membership. Without this, one document with enough hits
    takes the whole answer and the cap never applies to anything."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("c0", seq=1), candidate("c1", seq=2), candidate("far", seq=99)],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1"]
    assert run.dropped_for(IDS.DOCUMENT_DIVERSITY_CAP) == ["far"]


def test_adjacency_is_scoped_to_the_source_version_and_not_the_source() -> None:
    """Sequence numbers restart per version, so ``seq`` 4 of version 2 is not the neighbour of
    ``seq`` 3 of version 1 — it is different text in a different document revision."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [
            candidate("v1a", version="ver-1", seq=2),
            candidate("v1b", version="ver-1", seq=3),
            candidate("v2", version="ver-2", seq=4),
        ],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["v1a", "v1b"]
    assert run.dropped_for(IDS.DOCUMENT_DIVERSITY_CAP) == ["v2"]


def test_a_candidate_with_no_sequence_number_is_never_adjacent() -> None:
    """Erring towards not-exempt keeps the cap honest. The cost is a chunk that could have
    completed a passage; the alternative silently disables the cap for unlocatable chunks."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [candidate("c0", seq=1), candidate("c1", seq=2), candidate("nowhere")],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1"]
    assert run.dropped_for(IDS.DOCUMENT_DIVERSITY_CAP) == ["nowhere"]


def test_an_exempt_chunk_still_counts_towards_its_document() -> None:
    """It occupies context like any other. Not counting it would let one long adjacent run take
    the whole answer while the cap reported itself satisfied."""
    run = RecordingRun()
    result = dedup_and_diversify(
        [
            candidate("c0", seq=1),
            candidate("c1", seq=2),
            candidate("c2", seq=3),
            candidate("far", seq=50),
        ],
        run,
        max_per_document=2,
    )
    assert kept(result) == ["c0", "c1", "c2"]
    assert run.dropped_for(IDS.DOCUMENT_DIVERSITY_CAP) == ["far"]


# ── accounting, and the half that is not written ─────────────────────────────


def test_every_candidate_is_either_retained_or_recorded_as_excluded() -> None:
    """The identity the playground's totals depend on: fused minus retained equals excluded.

    ``test_evidence_threshold.py`` asserts the same identity for the neighbouring stage. It held
    unconditionally once the near-duplicate penalty was dropped: the vocabulary now contains no
    member describing a candidate that is retained, so nothing can appear on both sides.
    """
    run = RecordingRun()
    given = [
        candidate("a", content_hash="h1"),
        candidate("b", content_hash="h1"),
        candidate("c", seq=40),
        candidate("d", seq=80),
        candidate("e", seq=90),
    ]
    result = dedup_and_diversify(given, run, max_per_document=2)
    assert len(given) == len(result) + len(run.excluded)
    assert set(kept(result)) | {chunk_id for chunk_id, _ in run.excluded} == {
        item.chunk_id for item in given
    }


def test_nothing_in_is_nothing_out() -> None:
    run = RecordingRun()
    assert dedup_and_diversify([], run, max_per_document=2) == []
    assert run.excluded == []


def test_stage_ten_does_not_quietly_apply_a_near_duplicate_penalty() -> None:
    """The fused score of every retained candidate is untouched. If a penalty ever appears, it
    appears here first — rather than as a ranking that moved for reasons nobody can name."""
    run = RecordingRun()
    given = [candidate("a", seq=1), candidate("b", seq=2)]
    before = [item.fused_score for item in given]
    result = dedup_and_diversify(given, run, max_per_document=5)
    assert [item.fused_score for item in result] == before


def test_the_near_duplicate_penalty_is_absent_by_ruling_and_stays_absent() -> None:
    """The regression guard for the 2026-08-12 ruling, replacing the assertion that the stub
    raised.

    ``near_duplicate_penalized`` was the one exclusion reason describing a *retained* candidate,
    so it could never be recorded through ``exclude`` without breaking scored-minus-packed; and
    the only detector this tree can offer — the chunker's ``overlap_of``, always a pointer to the
    immediately preceding chunk of the same source version — fires exactly where the adjacency
    exemption above already spares the candidate, so a penalty would have been vacuous. Both
    the member and ``penalize_near_duplicates`` are gone. This fails if either returns.
    """
    assert "near_duplicate_penalized" not in {reason.value for reason in ExclusionReason}
    assert not hasattr(ExclusionReason, "NEAR_DUPLICATE_PENALIZED")
    assert not hasattr(evidence_module, "penalize_near_duplicates")
    assert "penalize_near_duplicates" not in evidence_module.__all__

"""Finding #48 — every ``Scored`` carries the **provider's** scale, never the calibration's.

The bug this file exists to keep fixed cannot be seen by reading either module on its own, and
it produces no symptom at all.

``apply_threshold`` asserts ``scored.scale is calibration.scale`` before comparing anything.
That assertion is the only structural defence against a threshold calibrated on one scale being
applied on another — a provider swap, a model swap, a vendor changing what its numbers mean —
because every scale produces plausible floats and ``0.30`` is valid on all of them. Nothing
downstream raises when they are swapped; only the aggregate refusal rate moves, months later,
with no diff to point at.

The assertion is only meaningful because its two sides come from **different places**:
``RerankResult.scale`` is what the vendor said about this response, ``RerankCalibration.scale``
is what the evaluation run measured. Populate ``Scored.scale`` from the calibration and stage 12
compares ``calibration.scale`` with a copy of ``calibration.scale``. That reads correctly, type-
checks, passes every fixture where the two agree, and can never fail.

So the test below constructs the one case where they disagree and asserts stage 11 propagates
the disagreement rather than erasing it. A fixture where they agree proves nothing here — which
is why ``test_the_scale_is_not_taken_from_the_calibration`` is the load-bearing one and the
agreeing case is only a control.

**Stage 11 is written but unreachable**, and that is stated here rather than left implicit.
There is no concrete ``RerankAdapter`` — every vendor module's ``rerank`` raises — nothing binds
a ``Reranker`` to an organization's connection, and ``CALIBRATIONS`` is empty on purpose, so
``calibration_for`` refuses before a caller reaches this code. The fake below satisfies the
``Reranker`` protocol and nothing else; it is not a provider and must never become one.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Final

import pytest
from qdrant_client import models

from app.providers.contract import (
    Diagnostics,
    RerankRequest,
    RerankResult,
    RerankScale,
    Usage,
)
from app.rag.evidence import apply_threshold
from app.rag.rerank import (
    CALIBRATIONS,
    RerankCalibration,
    RerankScaleMismatch,
    Scored,
    rerank,
)
from app.rag.stages import ExclusionReason
from app.retrieval.search import Candidate

ORG: Final[str] = "01JQZ00000000000000000000A"
TRACE: Final[str] = "0af7651916cd43dd8448eb211c80319c"
CONNECTION: Final[str] = "01JQZ00000000000000000CNN0"


@dataclass(frozen=True)
class Ctx:
    org_id: str = ORG
    bot_id: str | None = "01JQZ00000000000000000BT00"


class RecordingRun:
    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason]] = []

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason))

    def record_labels(self, labels: Any) -> None:
        return None

    def remaining_seconds(self) -> float:
        return 10.0

    def dropped_for(self, reason: ExclusionReason) -> list[str]:
        return [chunk_id for chunk_id, seen in self.excluded if seen is reason]


@dataclass
class FakeReranker:
    """The smallest thing satisfying ``Reranker``. It returns what it is told to return.

    It scores by *position*, not by content, so a test asserting an ordering is asserting that
    stage 11 sorted — not that a fixture ranked well. Binding the scale explicitly is the whole
    point: a reranker that could only report the calibration's scale could not express the
    disagreement this file is about.
    """

    scale: RerankScale = RerankScale.SIGMOID
    scores: list[float] | None = None
    name: str = "fake-ranker"
    requests: list[RerankRequest] = field(default_factory=list)

    async def rerank(self, req: RerankRequest) -> RerankResult:
        self.requests.append(req)
        scores = self.scores
        if scores is None:
            scores = [1.0 - index / 100 for index in range(len(req.passages))]
        return RerankResult(
            scores=scores,
            scale=self.scale,
            usage=Usage(),
            total_ms=7,
            diagnostics=Diagnostics(provider="fake"),
        )


def calibration(
    scale: RerankScale = RerankScale.SIGMOID, min_score: float = 0.4
) -> RerankCalibration:
    return RerankCalibration(
        provider="fake",
        model="rank-1",
        scale=scale,
        min_score=min_score,
        max_passage_tokens=512,
        derived_from="eval-run-test",
    )


def candidate(chunk_id: str) -> Candidate:
    return Candidate(
        point=models.ScoredPoint(
            id=1, version=1, score=0.5, payload={"chunk_id": chunk_id, "source_id": "src-1"}
        )
    )


def passages_for(*chunk_ids: str) -> dict[str, str]:
    return {chunk_id: f"Heading > body text of {chunk_id}" for chunk_id in chunk_ids}


# ── the provenance, which is the point of this file ──────────────────────────


async def test_the_scale_on_every_score_is_the_one_the_provider_reported() -> None:
    reranker = FakeReranker(scale=RerankScale.LOGIT)
    outcome = await rerank(
        reranker,
        calibration(RerankScale.SIGMOID),
        Ctx(),
        "does it cover accidental damage",
        [candidate("a"), candidate("b")],
        passages_for("a", "b"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert {entry.scale for entry in outcome.scored} == {RerankScale.LOGIT}


async def test_the_scale_is_not_taken_from_the_calibration() -> None:
    """**The load-bearing assertion.**

    Calibration says ``SIGMOID``; the provider says ``LOGIT``. If stage 11 copies the
    calibration's scale onto its results, this reads ``SIGMOID`` and stage 12's assertion below
    becomes a comparison of a value with itself — permanently, invisibly, and with the vendor's
    own claim discarded on the way past.
    """
    reranker = FakeReranker(scale=RerankScale.LOGIT)
    cal = calibration(RerankScale.SIGMOID)
    outcome = await rerank(
        reranker,
        cal,
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert outcome.scored[0].scale is not cal.scale


async def test_a_scale_disagreement_reaches_stage_twelve_and_raises_there() -> None:
    """End to end: the vendor moved the scale under an unchanged threshold, and stage 12 is the
    thing that notices. It cannot notice unless stage 11 carried the disagreement to it."""
    reranker = FakeReranker(scale=RerankScale.LOGIT)
    outcome = await rerank(
        reranker,
        calibration(RerankScale.SIGMOID),
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    with pytest.raises(RerankScaleMismatch, match="Re-derive"):
        apply_threshold(outcome, RecordingRun())


async def test_an_agreeing_scale_passes_stage_twelve() -> None:
    """The control. On its own it proves nothing — it is exactly the fixture under which the
    self-comparison bug is invisible — and it is here so the failing case above is known to be
    failing for the scale and not for something else."""
    reranker = FakeReranker(scale=RerankScale.SIGMOID, scores=[0.9])
    outcome = await rerank(
        reranker,
        calibration(RerankScale.SIGMOID),
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert [entry.candidate.chunk_id for entry in apply_threshold(outcome, RecordingRun())] == ["a"]


async def test_an_uncalibrated_provider_scale_fails_the_gate_rather_than_passing_it() -> None:
    """No calibration can carry ``UNCALIBRATED`` — ``RerankCalibration`` refuses to be built
    with it — so a provider reporting it can never agree with a threshold. That is the intended
    outcome and not a special case."""
    reranker = FakeReranker(scale=RerankScale.UNCALIBRATED)
    outcome = await rerank(
        reranker,
        calibration(RerankScale.SIGMOID),
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    with pytest.raises(RerankScaleMismatch):
        apply_threshold(outcome, RecordingRun())


# ── the request the stage builds ─────────────────────────────────────────────


async def test_the_request_names_the_tenant_the_trace_and_the_connection() -> None:
    """A provider call that cannot name its organization cannot be scoped, metered or traced —
    and reranking ships a tenant's document text to a vendor and bills that tenant for it."""
    reranker = FakeReranker()
    await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    sent = reranker.requests[0]
    assert (sent.org_id, sent.trace_id, sent.provider_connection_id) == (ORG, TRACE, CONNECTION)


async def test_the_request_carries_the_hydrated_passage_and_not_the_payload() -> None:
    """The chunk body is not in the Qdrant payload (ADR-010) and the reranker must score the
    exact string that was embedded, heading prefix included. Scoring anything else judges a
    document that does not exist in the index."""
    reranker = FakeReranker()
    await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a")],
        {"a": "Heading > the exact embedded string"},
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert reranker.requests[0].passages == ["Heading > the exact embedded string"]


async def test_the_model_comes_from_the_calibration() -> None:
    """The threshold is a function of ``(provider, model, scale, chunker version)``. Scoring
    with one model and thresholding on another's number is the same defect as the scale swap,
    one field over."""
    reranker = FakeReranker()
    await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a")],
        passages_for("a"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert reranker.requests[0].model == "rank-1"


async def test_scores_stay_aligned_to_the_passages_they_were_sent_for() -> None:
    """Several ranking APIs reply sorted by score with the original position in an ``index``
    field. Re-aligning is the adapter's job; getting it wrong produces plausible floats attached
    to the wrong passages — an answer citing a chunk that scored well only because it was
    third."""
    reranker = FakeReranker(scores=[0.1, 0.9, 0.5])
    outcome = await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a"), candidate("b"), candidate("c")],
        passages_for("a", "b", "c"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert {entry.candidate.chunk_id: entry.score for entry in outcome.scored} == {
        "a": 0.1,
        "b": 0.9,
        "c": 0.5,
    }


async def test_a_provider_returning_the_wrong_number_of_scores_is_a_failure() -> None:
    """A vendor that returns fewer entries than it was given is not offering a partial answer:
    the dropped passages become evidence that vanished, and the answer that follows is grounded
    in a subset nobody chose."""
    reranker = FakeReranker(scores=[0.9])
    with pytest.raises(ValueError):
        await rerank(
            reranker,
            calibration(),
            Ctx(),
            "q",
            [candidate("a"), candidate("b")],
            passages_for("a", "b"),
            RecordingRun(),
            trace_id=TRACE,
            provider_connection_id=CONNECTION,
            max_candidates=25,
        )


# ── what the stage drops before it spends a round trip ───────────────────────


async def test_candidates_beyond_the_cutoff_are_dropped_and_recorded() -> None:
    reranker = FakeReranker()
    run = RecordingRun()
    await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a"), candidate("b"), candidate("c")],
        passages_for("a", "b", "c"),
        run,
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=2,
    )
    assert run.dropped_for(ExclusionReason.BELOW_RERANK_CANDIDATE_CUTOFF) == ["c"]
    assert len(reranker.requests[0].passages) == 2


async def test_an_unhydrated_candidate_is_never_scored_against_an_empty_string() -> None:
    """An empty passage returns a real number, and that number ranks a passage that is not
    there against passages that are."""
    reranker = FakeReranker()
    run = RecordingRun()
    outcome = await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a"), candidate("missing")],
        {"a": "present", "missing": ""},
        run,
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert reranker.requests[0].passages == ["present"]
    assert "missing" in run.dropped_for(ExclusionReason.BELOW_RERANK_CANDIDATE_CUTOFF)
    assert [entry.candidate.chunk_id for entry in outcome.scored] == ["a"]


async def test_nothing_scoreable_is_an_applied_outcome_with_no_scores_and_no_call() -> None:
    """Not a skip. The reranker was available and there was nothing to give it, so stage 12
    returns nothing and the bot refuses — which is the truthful account. Calling the vendor with
    an empty passage list would be a validation error on the way to a call with no work in it.
    """
    reranker = FakeReranker()
    outcome = await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a")],
        {},
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert reranker.requests == []
    assert outcome.applied is True
    assert outcome.scored == ()
    assert apply_threshold(outcome, RecordingRun()) == []


async def test_the_outcome_is_ordered_by_score() -> None:
    reranker = FakeReranker(scores=[0.1, 0.9, 0.5])
    outcome = await rerank(
        reranker,
        calibration(),
        Ctx(),
        "q",
        [candidate("a"), candidate("b"), candidate("c")],
        passages_for("a", "b", "c"),
        RecordingRun(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )
    assert [entry.candidate.chunk_id for entry in outcome.scored] == ["b", "c", "a"]


# ── and the reason none of this runs in production yet ───────────────────────


def test_no_model_is_calibrated_so_the_stage_cannot_be_configured() -> None:
    """``CALIBRATIONS`` is empty on purpose. Filling it is an evaluation run over the golden
    corpus — fifty answerable and fifty unanswerable questions scored through the full pipeline,
    threshold at the 5th percentile of the answerable distribution — and not a code change.
    Until an entry exists, reranking against that model is not configurable, which is the
    correct state rather than a gap to be filled with the retired 0.30.
    """
    assert CALIBRATIONS == {}


def test_a_score_cannot_be_constructed_without_saying_what_scale_it_is_on() -> None:
    """The field is required, so a score physically cannot travel without its meaning."""
    with pytest.raises(TypeError):
        Scored(candidate=candidate("a"), score=0.5)  # type: ignore[call-arg]

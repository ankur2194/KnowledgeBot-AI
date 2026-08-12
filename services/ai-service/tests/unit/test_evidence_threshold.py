"""Stage 12 — the gate that decides whether the bot answers at all, on both of its paths.

Two paths because reranking is capability-gated since ADR-030, and they select on different
things for a reason that is easy to lose:

* **Reranked:** a score, compared against a ``(provider, model)`` calibration on the scale that
  calibration was derived on. The comparison is only meaningful because the two sides come from
  different places — ``Scored.scale`` is what the provider reported, ``calibration.scale`` is
  what the evaluation run measured. Sourcing both from the calibration is the defect these
  tests exist to keep fixed: it reads exactly the same and can never fail.
* **Skipped:** no score at all, therefore no threshold. Branch agreement instead, because it is
  the one relevance signal RRF preserves. Never the fused score — RRF discards magnitude by
  construction, so a cutoff there can only trim the tail and the bot never refuses.
"""

from __future__ import annotations

from typing import Final

import pytest
from qdrant_client import models

from app.rag.evidence import (
    BranchAgreementUnavailable,
    apply_threshold,
    select_unranked,
)
from app.rag.rerank import (
    RerankCalibration,
    RerankOutcome,
    RerankScale,
    RerankScaleMismatch,
    RerankSkipReason,
    Scored,
)
from app.rag.stages import ExclusionReason
from app.retrieval.search import BranchName, Candidate

#: A bounded calibration, so the arithmetic below is easy to read. Nothing here depends on the
#: scale being bounded — ``test_an_unbounded_logit_thresholds_normally`` is the one that
#: matters, because the only provider of the five with a ranking endpoint returns a logit.
SIGMOID_CAL: Final = RerankCalibration(
    provider="fake",
    model="rank-1",
    scale=RerankScale.SIGMOID,
    min_score=0.40,
    max_passage_tokens=512,
    derived_from="eval-run-test",
)


class RecordingRun:
    """A ``StageRun`` that remembers every drop, which is the whole point of the protocol.

    §8.24 requires the playground to show excluded results *and reasons*; a stage that filters
    without recording makes the panel show fewer candidates than were scored with no row
    explaining the difference, and the retain cap is the drop that gets forgotten because
    ``[:retain]`` reads as a slice rather than as a filter.
    """

    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason, float | None]] = []

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason, score))

    def remaining_seconds(self) -> float:
        return 10.0

    def reasons_for(self, reason: ExclusionReason) -> list[str]:
        return [chunk_id for chunk_id, seen, _ in self.excluded if seen is reason]


def candidate(chunk_id: str, *, dense: int | None = 1, sparse: int | None = 1) -> Candidate:
    """One retrieved candidate. ``chunk_id`` rides in the payload, never as the point id."""
    return Candidate(
        point=models.ScoredPoint(
            id="0198f2b0-0000-7000-8000-000000000000",
            version=0,
            score=0.5,
            payload={"chunk_id": chunk_id},
        ),
        dense_rank=dense,
        sparse_rank=sparse,
    )


def scored(chunk_id: str, score: float, scale: RerankScale = RerankScale.SIGMOID) -> Scored:
    return Scored(candidate=candidate(chunk_id), score=score, scale=scale)


def applied(*items: Scored, calibration: RerankCalibration = SIGMOID_CAL) -> RerankOutcome:
    return RerankOutcome(skipped=None, calibration=calibration, scored=items)


def skipped(*items: Candidate) -> RerankOutcome:
    return RerankOutcome(
        skipped=RerankSkipReason.PROVIDER_LACKS_CAPABILITY,
        calibration=None,
        unranked=items,
    )


BOTH: Final[frozenset[BranchName]] = frozenset({"dense", "sparse"})
DENSE_ONLY: Final[frozenset[BranchName]] = frozenset({"dense"})


# ── the reranked path ─────────────────────────────────────────────────────────


def test_candidates_below_the_threshold_are_dropped_with_their_score_recorded() -> None:
    run = RecordingRun()
    kept = apply_threshold(applied(scored("a", 0.9), scored("b", 0.1)), run)

    assert [s.candidate.chunk_id for s in kept] == ["a"]
    assert run.excluded == [("b", ExclusionReason.BELOW_EVIDENCE_THRESHOLD, 0.1)]


def test_the_retain_cap_is_a_filter_and_every_drop_it_makes_is_recorded() -> None:
    """The forgotten drop. Everything here clears the threshold, so without the ``[retain:]``
    loop the panel shows three passing candidates and packs two, with nothing saying why."""
    run = RecordingRun()
    kept = apply_threshold(
        applied(scored("a", 0.9), scored("b", 0.8), scored("c", 0.7)), run, retain=2
    )

    assert [s.candidate.chunk_id for s in kept] == ["a", "b"]
    assert run.reasons_for(ExclusionReason.ABOVE_RETAIN_LIMIT) == ["c"]


def test_the_reranked_count_is_fully_accounted_for_by_exclusions() -> None:
    """Scored minus packed equals excluded, on every run. That identity is what makes the
    playground trustworthy; a stage that drops silently breaks it without failing anything."""
    run = RecordingRun()
    outcome = applied(scored("a", 0.9), scored("b", 0.8), scored("c", 0.7), scored("d", 0.1))
    kept = apply_threshold(outcome, run, retain=2)

    assert len(outcome.scored) - len(kept) == len(run.excluded)


def test_the_retain_cap_is_applied_to_the_highest_scores_not_to_stage_11_order() -> None:
    """``[:retain]`` over an unsorted list keeps the wrong candidates and every trace row still
    looks well-formed."""
    run = RecordingRun()
    kept = apply_threshold(applied(scored("low", 0.5), scored("high", 0.95)), run, retain=1)

    assert [s.candidate.chunk_id for s in kept] == ["high"]


def test_nothing_clearing_the_threshold_is_a_refusal_and_not_an_error() -> None:
    """An empty return is a correct answer. The bot says the answer is not in the sources and
    does not fall through to model knowledge; the metric for it is not an error rate."""
    run = RecordingRun()
    assert apply_threshold(applied(scored("a", 0.05), scored("b", 0.01)), run) == []
    assert run.reasons_for(ExclusionReason.BELOW_EVIDENCE_THRESHOLD) == ["a", "b"]


def test_an_unbounded_logit_thresholds_normally() -> None:
    """The correction that makes stage 12 reachable at all.

    NVIDIA NIM is the only one of the five configured providers with a ranking endpoint and its
    scale is an unbounded logit. Treating "unbounded" as "unthresholdable" — which is what
    ``contract.RerankScale.may_threshold`` currently does, since it is an alias of
    ``is_bounded`` — makes the evidence gate unreachable in production for every organization,
    turning stage 11 into an expensive reordering stage 12 can never act on.

    The derivation procedure never needed a range: it reads the 5th percentile off a measured
    distribution of answerable top-1 scores, which works on any monotonically ordered scale.
    """
    logit_cal = RerankCalibration(
        provider="nvidia_nim",
        model="nvidia/llama-3.2-nv-rerankqa-1b-v2",
        scale=RerankScale.LOGIT,
        min_score=-0.85,
        max_passage_tokens=512,
        derived_from="eval-run-test",
    )
    run = RecordingRun()
    kept = apply_threshold(
        applied(
            scored("a", 0.226, RerankScale.LOGIT),
            scored("b", -1.52, RerankScale.LOGIT),
            calibration=logit_cal,
        ),
        run,
    )

    assert [s.candidate.chunk_id for s in kept] == ["a"]
    assert run.reasons_for(ExclusionReason.BELOW_EVIDENCE_THRESHOLD) == ["b"]


def test_a_provider_reported_scale_that_disagrees_with_the_calibration_raises() -> None:
    """**The assertion that could not previously fail.**

    ``Scored.scale`` is what the provider reported on this response; ``calibration.scale`` is
    what the evaluation run behind ``min_score`` was derived on. When stage 11 populated
    ``Scored.scale`` from the calibration — which a ``list[float]`` return from the adapter
    forced, because a bare score list cannot carry a scale — this comparison had the same value
    on both sides and was structurally incapable of failing.

    Both scales here are bounded 0–1, so ``0.40`` is a perfectly valid threshold on either and
    nothing else in the pipeline would notice. That is the whole failure: no exception, no
    metric, plausible scores in the trace, and only the aggregate refusal rate moves.
    """
    run = RecordingRun()
    with pytest.raises(RerankScaleMismatch, match="unit_interval"):
        apply_threshold(applied(scored("a", 0.9, RerankScale.UNIT_INTERVAL)), run)


def test_a_provider_reporting_uncalibrated_is_never_thresholded() -> None:
    """``UNCALIBRATED`` means "ordering only, forever, until an evaluation run says otherwise".

    No calibration can carry it — ``RerankCalibration`` refuses to be constructed with it — so
    a provider reporting it always disagrees with whatever calibration was resolved, and the
    mismatch above is what stops it. Before the two ``RerankScale`` enums were unified this
    state was unrepresentable in this module, so such a score arrived widened by a guess onto a
    bounded member and was thresholded against a distribution nobody had measured.
    """
    run = RecordingRun()
    with pytest.raises(RerankScaleMismatch, match="uncalibrated"):
        apply_threshold(applied(scored("a", 0.9, RerankScale.UNCALIBRATED)), run)


def test_the_scale_is_checked_before_any_score_is_compared() -> None:
    """Order matters: a mismatched scale must raise even when every score would have passed,
    or the failure only surfaces on the queries that were going to refuse anyway."""
    run = RecordingRun()
    with pytest.raises(RerankScaleMismatch):
        apply_threshold(applied(scored("a", 0.99, RerankScale.UNIT_INTERVAL)), run)
    assert run.excluded == []


def test_a_skipped_rerank_is_never_thresholded_on_the_fused_order() -> None:
    """The substitution stage 12 exists to prevent, and it is more tempting now than when the
    reranker was local and always present: the skipped path has candidates, they have numbers,
    and the numbers look like scores."""
    run = RecordingRun()
    with pytest.raises(ValueError, match="not a substitute"):
        apply_threshold(skipped(candidate("a")), run)


# ── the skipped path ──────────────────────────────────────────────────────────


def test_the_degraded_path_selects_on_branch_agreement_and_records_what_it_drops() -> None:
    run = RecordingRun()
    kept = select_unranked(
        skipped(
            candidate("both", dense=1, sparse=3),
            candidate("dense_only", dense=2, sparse=None),
            candidate("sparse_only", dense=None, sparse=1),
        ),
        run,
        branches=BOTH,
    )

    assert [c.chunk_id for c in kept] == ["both"]
    assert run.reasons_for(ExclusionReason.NO_BRANCH_AGREEMENT) == ["dense_only", "sparse_only"]


def test_the_degraded_path_refuses_when_no_candidate_appears_in_both_branches() -> None:
    run = RecordingRun()
    assert select_unranked(skipped(candidate("a", sparse=None)), run, branches=BOTH) == []


def test_the_degraded_path_records_its_retain_cap_like_the_thresholded_one() -> None:
    """Both paths must account for every candidate, or a degraded run and a normal one are not
    comparable in the panel or in an evaluation."""
    run = RecordingRun()
    kept = select_unranked(
        skipped(candidate("a"), candidate("b"), candidate("c")), run, branches=BOTH, retain=2
    )

    assert [c.chunk_id for c in kept] == ["a", "b"]
    assert run.reasons_for(ExclusionReason.ABOVE_RETAIN_LIMIT) == ["c"]


def test_a_dense_only_run_without_a_reranker_fails_loudly_rather_than_taking_the_top_n() -> None:
    """Finding **C2** arriving on the request path.

    API embedding endpoints return dense only, so nothing currently produces a sparse query
    vector. On a dense-only run every ``sparse_rank`` is ``None``, agreement is undefined, and
    the fused order *is* the dense order — so "take the top N" here is a cutoff on
    dual-encoder cosine, which is not calibrated and not comparable across queries. Raising
    does not resolve C2; it refuses to let the gap be invisible.
    """
    run = RecordingRun()
    with pytest.raises(BranchAgreementUnavailable, match="C2"):
        select_unranked(skipped(candidate("a", sparse=None)), run, branches=DENSE_ONLY)


def test_the_branch_set_comes_from_what_was_queried_not_from_the_candidates() -> None:
    """A sparse branch that ran and matched nothing is a very strong signal; a sparse branch
    that never ran is no signal at all. They are indistinguishable from the candidates alone,
    which is why ``retrieve_branches`` returns only the branches it queried."""
    run = RecordingRun()
    everything_dense_shaped = skipped(candidate("a", sparse=None), candidate("b", sparse=None))

    assert select_unranked(everything_dense_shaped, run, branches=BOTH) == []
    with pytest.raises(BranchAgreementUnavailable):
        select_unranked(everything_dense_shaped, run, branches=DENSE_ONLY)


def test_an_applied_rerank_never_goes_through_the_degraded_path() -> None:
    run = RecordingRun()
    with pytest.raises(ValueError, match="applied rerank"):
        select_unranked(applied(scored("a", 0.9)), run, branches=BOTH)


# ── candidate identity ────────────────────────────────────────────────────────


def test_a_candidate_without_a_chunk_id_cannot_be_excluded_silently() -> None:
    """``chunk_id`` is what an exclusion is recorded against and what a citation resolves
    through. A point that reached retrieval without one is a payload-contract violation
    upstream, and a ``None`` here would become the string ``"None"`` in the trace and group
    every unidentifiable candidate into one playground row.
    """
    orphan = Candidate(
        point=models.ScoredPoint(
            id="0198f2b0-0000-7000-8000-000000000001", version=0, score=0.5, payload={}
        )
    )
    with pytest.raises(ValueError, match="no chunk_id"):
        _ = orphan.chunk_id

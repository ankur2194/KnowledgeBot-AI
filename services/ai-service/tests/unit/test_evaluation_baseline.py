"""Two runs are comparable, or they are not, and the difference is a property of the data.

ADR-030 took away the thing that used to make a baseline safe: the pipeline was a property of
the image, so two runs of the same commit measured the same system by construction. Now the
embedding model, the reranker, the rerank *capability*, and the evidence threshold are all
per-organization provider configuration, and every one of them can move a score without moving
a line of code.

The failure this file exists to prevent is specific and quiet. A run on a provider with no
ranking endpoint serves the RRF-fused order, never applies an evidence threshold, refuses on
different evidence, and packs a different context — and its aggregate is a perfectly plausible
number of exactly the right shape. Dropped into a baseline it becomes the target every later
run is measured against, and the whole series is then comparing two pipelines.

So the exclusion is not a convention. ``EvaluationRunRecord.rerank`` is a union whose skipped
arm has no provider, no model, no scale and no threshold to read; ``BaselineKey``'s rerank
fields are non-optional; and the only constructor of a key raises on a skipped run. There is
no value to forget to check.
"""

from __future__ import annotations

import dataclasses
from typing import Final

import pytest

from app.evaluation.corpus import CorpusVerification
from app.evaluation.judge import PINNED_RAGAS_VERSION, JudgePin
from app.evaluation.run import (
    BaselineKey,
    EvaluationRunRecord,
    IncomparableRuns,
    RerankApplied,
    RerankSkipped,
    RunNotBaselineEligible,
    compare_or_refuse,
    exclusion_totals,
    rerank_record_for_run,
)
from app.ingestion.embedding.embedder import EmbeddingModelIdentity
from app.rag.rerank import RerankScale, RerankSkipReason
from app.rag.stages import ExclusionReason
from app.retrieval.collection import EmbeddingSpace

CORPUS: Final[CorpusVerification] = CorpusVerification(
    corpus_version="corpus-test.0",
    manifest_version=1,
    manifest_sha256="b" * 64,
    fixture_digests={f"doc-{index}": "c" * 64 for index in range(10)},
    files_hashed=11,
    bytes_hashed=4096,
)

EMBEDDING: Final[EmbeddingModelIdentity] = EmbeddingModelIdentity(
    space=EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=3072),
    canary_digest="9f2a1c4e77b1",
)

JUDGE: Final[JudgePin] = JudgePin(
    provider_connection_id="conn-1",
    requested_model="gpt-5.6",
    resolved_model_id="gpt-5.6-sol",
    system_fingerprint="fp_0a1b2c3d",
    temperature=0.0,
    ragas_version=PINNED_RAGAS_VERSION,
)

APPLIED: Final[RerankApplied] = RerankApplied(
    provider="nim",
    model="nvidia/llama-3.2-nv-rerankqa-1b-v2",
    scale=RerankScale.LOGIT,
    calibration_derived_from="run-0001",
)


def _run(**overrides: object) -> EvaluationRunRecord:
    fields: dict[str, object] = {
        "run_id": "run-0002",
        "corpus": CORPUS,
        "dataset_version": "2026.08.0",
        "embedding": EMBEDDING,
        "rerank": APPLIED,
        "judge": JUDGE,
        "retrieval_configuration_version": "retr-cfg-7",
    }
    fields.update(overrides)
    return EvaluationRunRecord(**fields)  # type: ignore[arg-type]


# ─────────────────────────────────────────────────────────────────────────────
# The structural exclusion
# ─────────────────────────────────────────────────────────────────────────────


def test_a_reranked_run_has_a_key() -> None:
    """Positive control. Every exclusion below is "this raises"; without this they are all
    satisfied by a property that raises unconditionally."""
    key = _run().baseline_key
    assert key.rerank_provider == "nim"
    assert key.rerank_scale is RerankScale.LOGIT
    assert _run().is_baseline_eligible


@pytest.mark.parametrize("reason", list(RerankSkipReason))
def test_a_skipped_rerank_run_has_no_baseline_key(reason: RerankSkipReason) -> None:
    """Parametrized over the **whole** closed enum, so a member added later is covered on the
    day it is added rather than on the day someone remembers this file.

    Every one of these is a legitimate, recorded result about a degraded configuration. None
    of them is a baseline: stage 11 did not run, so stage 12 applied no threshold, so the
    refusal behaviour being measured is not the shipped pipeline's.
    """
    run = _run(rerank=RerankSkipped(reason=reason))
    assert not run.is_baseline_eligible
    with pytest.raises(RunNotBaselineEligible, match="different pipeline"):
        _ = run.baseline_key


def test_the_skipped_arm_carries_nothing_a_key_could_be_built_from() -> None:
    """The exclusion in its structural form.

    ``RerankSkipped`` has exactly one field. There is no provider to read, no model, no scale,
    and no threshold — so ``mypy --strict`` rejects any code that reaches for one without
    narrowing the union, and there is nothing to accidentally use if it did.
    """
    assert [field.name for field in dataclasses.fields(RerankSkipped)] == ["reason"]
    overlap = {field.name for field in dataclasses.fields(RerankSkipped)} & {
        field.name for field in dataclasses.fields(RerankApplied)
    }
    assert not overlap, overlap


def test_a_run_with_failed_judge_calls_has_no_key() -> None:
    """A run where judgements errored is a failed run, not a slightly lower score. Averaging
    over the survivors reports a number for a run that did not happen — and errors are not
    distributed evenly across easy and hard cases, so the direction is not even predictable."""
    with pytest.raises(RunNotBaselineEligible, match="failed judge call"):
        _ = _run(judge_calls_errored=3).baseline_key
    with pytest.raises(RunNotBaselineEligible, match="errored case"):
        _ = _run(cases_errored=1).baseline_key


# ─────────────────────────────────────────────────────────────────────────────
# Collapsing per-case outcomes
# ─────────────────────────────────────────────────────────────────────────────


def test_one_skipped_case_makes_the_whole_run_skipped() -> None:
    """Pessimistic on purpose. Nine reranked cases and one fused one average to a figure that
    belongs to neither pipeline, and that figure is precisely what would enter a baseline."""
    per_case = [APPLIED] * 9 + [RerankSkipped(reason=RerankSkipReason.INSUFFICIENT_DEADLINE)]
    collapsed = rerank_record_for_run(per_case)
    assert isinstance(collapsed, RerankSkipped)
    assert collapsed.reason is RerankSkipReason.INSUFFICIENT_DEADLINE


def test_a_uniformly_reranked_run_collapses_to_its_configuration() -> None:
    assert rerank_record_for_run([APPLIED] * 45) == APPLIED


def test_an_empty_run_has_no_rerank_record() -> None:
    """An aborted run that scored nothing must not look comparable."""
    with pytest.raises(ValueError, match="no cases"):
        rerank_record_for_run([])


def test_a_configuration_change_mid_run_is_refused() -> None:
    """A reranker model swapped between case 1 and case 40 cannot be described by one record,
    and an aggregate over it describes no pipeline that exists."""
    other = dataclasses.replace(APPLIED, model="nvidia/nv-rerankqa-mistral-4b-v3")
    with pytest.raises(ValueError, match="different rerank configurations"):
        rerank_record_for_run([APPLIED, other])


def test_a_recalibrated_threshold_is_a_different_configuration() -> None:
    """The threshold *is* the refusal behaviour, so two runs against two calibrations of the
    same model are two experiments even though the provider and model match."""
    other = dataclasses.replace(APPLIED, calibration_derived_from="run-0099")
    with pytest.raises(ValueError, match="different rerank configurations"):
        rerank_record_for_run([APPLIED, other])


def test_an_applied_rerank_needs_the_run_its_threshold_came_from() -> None:
    """``derived_from`` is required on the calibration and it is required here too: a
    threshold whose provenance is unknown cannot be re-derived when the model changes, and
    vendor ids behind a ranking endpoint move without notice."""
    with pytest.raises(ValueError, match="evaluation run its threshold came from"):
        RerankApplied(
            provider="nim", model="m", scale=RerankScale.LOGIT, calibration_derived_from=""
        )


# ─────────────────────────────────────────────────────────────────────────────
# The key itself
# ─────────────────────────────────────────────────────────────────────────────


def test_every_key_field_participates_in_the_comparison() -> None:
    """A field added to ``BaselineKey`` and forgotten in ``differences()`` is recorded, shown
    in the baseline, and silently not compared — the worst of the three states, because it
    looks like it is being checked."""
    declared = {field.name for field in dataclasses.fields(BaselineKey)}
    key = _run().baseline_key
    compared: set[str] = set()
    for name in declared:
        value = getattr(key, name)
        mutated = dataclasses.replace(
            key, **{name: (value + 1 if isinstance(value, (int, float)) else f"{value}-x")}
        )
        if key.differences(mutated) == (name,):
            compared.add(name)
    assert compared == declared, declared - compared


def test_the_key_covers_every_adr_030_axis() -> None:
    """Each of these can move a score with no code change, so each must refuse a comparison.

    Named explicitly rather than counted, because the failure of an axis to be present is
    invisible: the gate keeps comparing and keeps returning a delta.
    """
    declared = {field.name for field in dataclasses.fields(BaselineKey)}
    required = {
        "corpus_version",
        "corpus_manifest_sha256",
        "dataset_version",
        "embedding_provider",
        "embedding_model",
        "embedding_dimensions",
        "embedding_canary_digest",
        "rerank_provider",
        "rerank_model",
        "rerank_scale",
        "rerank_calibration_derived_from",
        "judge_resolved_model_id",
        "judge_system_fingerprint",
        "judge_temperature",
        "ragas_version",
        "retrieval_configuration_version",
    }
    assert required <= declared, required - declared


def test_a_silent_weight_swap_refuses_the_comparison() -> None:
    """The canary digest is the only detector of a vendor re-training behind an unchanged
    alias: the served-model string is the alias echoed back, and cosine distance is defined
    between any two vectors of equal width, so nothing else moves."""
    baseline = _run().baseline_key
    drifted = _run(
        embedding=EmbeddingModelIdentity(space=EMBEDDING.space, canary_digest="ffffffffffff")
    )
    with pytest.raises(IncomparableRuns, match="embedding_canary_digest"):
        compare_or_refuse(baseline, drifted)


def test_a_changed_judge_refuses_the_comparison() -> None:
    """Scores are not comparable across judges. An audit of judge stability found the
    strongest model flipping 14.7% of its verdicts under A/B order reversal alone."""
    baseline = _run().baseline_key
    other_judge = dataclasses.replace(JUDGE, system_fingerprint="fp_deadbeef")
    with pytest.raises(IncomparableRuns, match="judge_system_fingerprint"):
        compare_or_refuse(baseline, _run(judge=other_judge))


def test_an_identical_run_compares_cleanly() -> None:
    """Positive control for ``compare_or_refuse``: a gate that refuses everything blocks every
    change and is removed within a week."""
    compare_or_refuse(_run().baseline_key, _run())


def test_a_skipped_run_is_refused_by_the_comparison_too() -> None:
    """Belt and braces: the exclusion holds at the comparison site as well as at the key, so
    a caller holding a baseline cannot compare a degraded run into it."""
    baseline = _run().baseline_key
    degraded = _run(rerank=RerankSkipped(reason=RerankSkipReason.PROVIDER_LACKS_CAPABILITY))
    with pytest.raises(RunNotBaselineEligible):
        compare_or_refuse(baseline, degraded)


def test_a_degraded_only_exclusion_contradicts_an_applied_rerank() -> None:
    """``NO_BRANCH_AGREEMENT`` is reachable only when stage 11 was skipped.

    A run recording it *and* claiming rerank applied has had its skip/applied split violated
    somewhere upstream: it measured one pipeline and recorded another. Both states produce a
    full set of plausible numbers, so nothing else would notice.
    """
    run = _run(exclusion_counts={ExclusionReason.NO_BRANCH_AGREEMENT: 4})
    assert not run.is_baseline_eligible
    with pytest.raises(RunNotBaselineEligible, match="skipped"):
        _ = run.baseline_key


def test_ordinary_exclusions_do_not_block_a_baseline() -> None:
    """Positive control: the contradiction check must not reject every run that excluded
    anything. Dropping candidates is what stages 10, 11, 12 and 13 are for."""
    run = _run(
        exclusion_counts={
            ExclusionReason.BELOW_EVIDENCE_THRESHOLD: 31,
            ExclusionReason.ABOVE_RETAIN_LIMIT: 12,
            ExclusionReason.EXACT_DUPLICATE: 3,
        }
    )
    assert run.is_baseline_eligible


def test_exclusion_totals_cover_every_member_of_the_vocabulary() -> None:
    """Totals are computed over the enum, never over a hardcoded list.

    ``ExclusionReason`` grows — ``NO_BRANCH_AGREEMENT`` was added when reranking became
    capability-gated, because the vocabulary predated the degraded path and carried no member
    for the only filter it applies. A total over a literal set silently under-counts exactly the
    runs that exercise the new member, and a degraded run that looks like it merely "retrieved
    less" is the misreading that member exists to prevent.
    """
    totals = exclusion_totals({ExclusionReason.ABOVE_RETAIN_LIMIT: 7})
    assert set(totals) == set(ExclusionReason)
    assert totals[ExclusionReason.ABOVE_RETAIN_LIMIT] == 7
    assert totals[ExclusionReason.NO_BRANCH_AGREEMENT] == 0
    assert sum(totals.values()) == 7


def test_a_run_record_cannot_be_built_without_a_corpus_verification() -> None:
    """The integrity gate, structurally. ``CorpusVerification`` is only produced by
    ``verify_corpus()`` and re-asserts its own floors, so a run record carrying one is a run
    whose corpus was hashed — there is no boolean to set to True."""
    annotations = {field.name: field.type for field in dataclasses.fields(EvaluationRunRecord)}
    assert annotations["corpus"] == "CorpusVerification"

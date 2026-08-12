"""What a run is, and which runs may be compared — ADR-030's consequences, as types.

Before ADR-030 the pipeline being measured was a property of the image: one pinned embedding
model, one pinned cross-encoder, both loaded locally, both identical for every organization.
A run was comparable to another run if the code was the same. That is no longer true in three
independent ways, and each of them can move a score without moving a line of code:

* **Embedding is a provider API call**, so the vector space is a per-organization,
  per-connection choice. Worse, the model ids are aliases with no dated snapshot, so a vendor
  can re-train behind an unchanged name. Cosine distance is defined between any two vectors of
  equal width, so nothing raises and no total moves — only ranking degrades. Identity is
  therefore *measured*, through ``EmbeddingModelIdentity``'s canary digest, not declared.
* **Reranking is capability-gated.** Of the five providers only NVIDIA NIM exposes a ranking
  endpoint. A run where stage 11 was skipped served the RRF-fused order and never applied an
  evidence threshold at all — it measured a **different pipeline**, and ``bge-reranker`` is
  explicit that degraded runs are excluded from regression baselines.
* **The evidence threshold is per ``(provider, model)``** and is itself the *output* of an
  evaluation run. Two runs thresholded against two calibrations are two experiments.

So comparability is a property of the data, and this module makes it one that cannot be
forgotten rather than one that has to be remembered.

HOW THE EXCLUSION IS STRUCTURAL
-------------------------------
``EvaluationRunRecord.rerank`` is a union of ``RerankApplied | RerankSkipped``. Under
``mypy --strict`` — which this repository runs — any code that reads ``run.rerank.provider``
without narrowing the union is a type error, and ``RerankSkipped`` has no provider, no model,
no scale and no calibration to read. There is nothing to accidentally use.

``BaselineKey``'s rerank fields are non-optional and it is only ever built by
``EvaluationRunRecord.baseline_key``, which raises ``RunNotBaselineEligible`` on a skipped
run. A skipped-rerank run therefore has **no key**, and every comparison, every baseline
acceptance and every gate takes a key. The exclusion is not a rule a reviewer enforces; it is
a value that does not exist.

``rerank_record_for_run`` collapses per-case outcomes with the pessimistic rule: **one skipped
case makes the whole run skipped.** Averaging six reranked cases with four fused ones produces
an aggregate belonging to neither pipeline, which is precisely the number that would go into a
baseline and be compared against for months.

A RUN WITH FAILED JUDGEMENTS IS NOT A LOWER SCORE
--------------------------------------------------
``judge_calls_errored`` and ``cases_errored`` are on the record and both are checked by
``baseline_key``. A run where 12% of judge calls errored is a failed run. Averaging over the
survivors reports a number for a run that did not happen, and it reports it in the direction
nobody expects — errors are not uniformly distributed across easy and hard cases.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from typing import Final

from app.evaluation.corpus import CorpusVerification
from app.evaluation.judge import JudgePin
from app.ingestion.embedding.embedder import EmbeddingModelIdentity
from app.rag.rerank import RerankScale, RerankSkipReason
from app.rag.stages import ExclusionReason

__all__ = [
    "DEGRADED_ONLY_EXCLUSIONS",
    "BaselineKey",
    "EvaluationRunRecord",
    "IncomparableRuns",
    "RerankApplied",
    "RerankRecord",
    "RerankSkipped",
    "RunNotBaselineEligible",
    "compare_or_refuse",
    "exclusion_totals",
    "rerank_record_for_run",
]

#: Exclusion reasons that are reachable **only** when stage 11 was skipped.
#:
#: ``NO_BRANCH_AGREEMENT`` is the degraded path's only filter: with no rerank score there is no
#: threshold, so ``select_unranked`` keeps candidates carrying both a dense and a sparse rank —
#: branch agreement being the one relevance signal RRF preserves, because it is structural
#: rather than magnitude-based — and drops the rest with this reason.
#:
#: Its presence in a run is therefore a second, independent witness that reranking was skipped.
#: A run claiming ``RerankApplied`` while carrying this exclusion has had its skip/applied split
#: violated somewhere upstream, and the two facts disagree about which pipeline was measured.
DEGRADED_ONLY_EXCLUSIONS: Final[frozenset[ExclusionReason]] = frozenset(
    {ExclusionReason.NO_BRANCH_AGREEMENT}
)


def exclusion_totals(counts: Mapping[ExclusionReason, int]) -> dict[ExclusionReason, int]:
    """Totals over **every** member of ``ExclusionReason``, including the ones not seen.

    Iterating the enum rather than a literal list is the whole point. ``ExclusionReason`` is a
    contract that grows — ``NO_BRANCH_AGREEMENT`` was added when reranking became optional,
    because the vocabulary predated the degraded path and had no member for the only filter it
    applies. A total computed over a hardcoded set silently under-counts the runs that exercise
    the new member, and a degraded run that appears to have simply "retrieved less" is precisely
    the misreading that member exists to prevent.
    """
    return {reason: counts.get(reason, 0) for reason in ExclusionReason}


class RunNotBaselineEligible(Exception):
    """This run measured something a baseline may not contain, so it has no key.

    Raised rather than returning ``None``, because a ``BaselineKey | None`` is one
    ``assert key is not None`` away from being ignored, and the whole point is that the
    exclusion survives a tired afternoon.
    """


class IncomparableRuns(Exception):
    """Two runs differ on something that changes what a score means.

    The gate emits this instead of a delta. A comparison across judge models, corpus versions
    or pipeline shapes blocks good changes and passes bad ones, and once a required check has
    done either twice the team learns to re-run it until it goes green.
    """


@dataclass(frozen=True, slots=True)
class RerankApplied:
    """Stage 11 ran, on this provider and model, against this calibration.

    ``calibration_derived_from`` is the evaluation run id behind the threshold, carried from
    ``RerankCalibration``. Two runs thresholded against different calibrations of the same
    model are not comparable either — the threshold *is* the refusal behaviour — so it is part
    of the key rather than a note in a description field.
    """

    provider: str
    model: str
    scale: RerankScale
    calibration_derived_from: str

    def __post_init__(self) -> None:
        if not (self.provider and self.model and self.calibration_derived_from):
            raise ValueError(
                "an applied rerank needs a provider, a model, and the id of the evaluation "
                "run its threshold came from"
            )


@dataclass(frozen=True, slots=True)
class RerankSkipped:
    """Stage 11 did not run, for a reason known before the call went out.

    Note what this type does not have: a provider, a model, a scale, a threshold. There was no
    score, so there was no evidence threshold, so the refusal behaviour of this run is not the
    refusal behaviour of a reranked one. It also deliberately cannot represent a *failure* —
    ``RerankSkipReason`` is closed and carries no member meaning the provider errored, because
    an outage laundered into a skip degrades ranking and hides an incident at the same time.
    """

    reason: RerankSkipReason


#: One run's stage-11 shape. Union rather than a nullable field: mypy will not let a caller
#: read a provider off a value that may not have one.
RerankRecord = RerankApplied | RerankSkipped


def rerank_record_for_run(per_case: Sequence[RerankRecord]) -> RerankRecord:
    """Collapse per-case stage-11 outcomes into the run's own, pessimistically.

    Rules, in order:

    * No cases at all -> ``ValueError``. An empty run is not a reranked run, and returning
      something comparable for it is how an aborted run enters a baseline.
    * Any case skipped -> the run is skipped, carrying the first skip reason. Partial
      reranking is a mixed pipeline and its aggregate belongs to neither half.
    * Cases disagreeing on provider, model, scale or calibration -> ``ValueError``. That is a
      configuration changing mid-run, and no single record can describe it honestly.
    """
    if not per_case:
        raise ValueError("a run with no cases has no rerank record and cannot be compared")

    skipped = [record for record in per_case if isinstance(record, RerankSkipped)]
    if skipped:
        return skipped[0]

    applied = [record for record in per_case if isinstance(record, RerankApplied)]
    distinct = {
        (record.provider, record.model, record.scale, record.calibration_derived_from)
        for record in applied
    }
    if len(distinct) != 1:
        raise ValueError(
            f"stage 11 used {len(distinct)} different rerank configurations within one run: "
            f"{sorted(distinct)}. A single record cannot describe that, and an aggregate over "
            "it describes no pipeline that exists"
        )
    return applied[0]


@dataclass(frozen=True, slots=True)
class BaselineKey:
    """Everything two runs must agree on before their scores may be subtracted.

    Flat and fully expanded rather than nesting the source objects, because this is what gets
    written into ``expected/baseline.json`` and diffed by a human at the moment a gate fires.
    A nested blob is a diff nobody reads.

    Equality is the whole interface: ``compare_or_refuse`` subtracts nothing when two keys
    differ, and names the fields that differ so the answer to "why is my run incomparable" is
    in the failure message rather than in a debugger.
    """

    corpus_version: str
    corpus_manifest_sha256: str
    dataset_version: str

    embedding_provider: str
    embedding_model: str
    embedding_dimensions: int
    embedding_distance: str
    #: The measured half of embedding identity. A vendor re-training behind an unchanged alias
    #: moves this and nothing else — it is the only detector of a silent weight swap, and a
    #: baseline compared across it is a baseline compared across two vector spaces.
    embedding_canary_digest: str

    #: Non-optional, and that is the exclusion. A skipped-rerank run cannot produce a key.
    rerank_provider: str
    rerank_model: str
    rerank_scale: RerankScale
    rerank_calibration_derived_from: str

    judge_resolved_model_id: str
    judge_system_fingerprint: str
    judge_temperature: float
    ragas_version: str

    retrieval_configuration_version: str

    def differences(self, other: BaselineKey) -> tuple[str, ...]:
        """Field names on which these two keys disagree. Empty means comparable."""
        return tuple(
            field
            for field in (
                "corpus_version",
                "corpus_manifest_sha256",
                "dataset_version",
                "embedding_provider",
                "embedding_model",
                "embedding_dimensions",
                "embedding_distance",
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
            )
            if getattr(self, field) != getattr(other, field)
        )


@dataclass(frozen=True, slots=True)
class EvaluationRunRecord:
    """One evaluation run's provenance — everything needed to reproduce or refuse it.

    ``corpus`` is a ``CorpusVerification`` and not a version string, so a run record cannot
    exist without the corpus having been hashed: ``CorpusVerification`` is only produced by
    ``verify_corpus()`` and re-asserts its own non-vacuity floors at construction. That is the
    wiring that makes integrity part of the eval path rather than a script someone remembers
    to run.

    Aggregate scores are deliberately **not** here. This is the identity of a run; the numbers
    live in ``evaluation_runs`` and the per-case detail in ``evaluation_results``. Keeping
    them apart is what lets a gate refuse a comparison before it has looked at a single score.
    """

    run_id: str
    corpus: CorpusVerification
    dataset_version: str
    embedding: EmbeddingModelIdentity
    rerank: RerankRecord
    judge: JudgePin
    retrieval_configuration_version: str
    #: Failed judge calls. Non-zero means the run produced no publishable score at all.
    judge_calls_errored: int = 0
    #: Cases that errored in the pipeline before any judging.
    cases_errored: int = 0
    #: Cases whose pinned ``source_version_id`` is no longer active. Reported, and a *change*
    #: in it refuses the comparison — the question set effectively changed.
    stale_case_count: int = 0
    #: How many candidates each ``ExclusionReason`` accounted for across the run. Summed with
    #: ``exclusion_totals`` so a member added to the enum is never silently omitted.
    exclusion_counts: Mapping[ExclusionReason, int] = field(default_factory=dict)

    def __post_init__(self) -> None:
        if not self.run_id:
            raise ValueError("a run with no id cannot be referenced by a calibration")
        for name in ("judge_calls_errored", "cases_errored", "stale_case_count"):
            if getattr(self, name) < 0:
                raise ValueError(f"{name} cannot be negative")
        if any(count < 0 for count in self.exclusion_counts.values()):
            raise ValueError(f"negative exclusion count in {dict(self.exclusion_counts)}")

    @property
    def baseline_key(self) -> BaselineKey:
        """This run's comparability key, or a refusal.

        Raises ``RunNotBaselineEligible`` when the run is not a measurement of the shipped
        pipeline, for one of two reasons:

        * **Reranking was skipped.** Stage 11 did not run and stage 12 therefore never
          applied a threshold, so the run's refusal rate, its packed evidence and its
          faithfulness all belong to a different pipeline. It is a legitimate result about a
          degraded configuration and it is not a baseline.
        * **Judge calls or cases errored.** A run with failed judgements is a failed run.
          Averaging over the survivors reports a number for a run that did not happen.
        """
        rerank = self.rerank
        if isinstance(rerank, RerankSkipped):
            raise RunNotBaselineEligible(
                f"run {self.run_id} skipped reranking ({rerank.reason.value}); it served the "
                "RRF-fused order and applied no evidence threshold, so it measured a "
                "different pipeline and must not enter a regression baseline"
            )
        if self.judge_calls_errored or self.cases_errored:
            raise RunNotBaselineEligible(
                f"run {self.run_id} had {self.judge_calls_errored} failed judge call(s) and "
                f"{self.cases_errored} errored case(s). That is a failed run, not a slightly "
                "lower score — never average over the survivors"
            )
        # The cross-check. A degraded-only exclusion in a run that claims stage 11 applied means
        # the two facts disagree about which pipeline ran, and one of them is wrong. Cheap to
        # assert and impossible to notice otherwise: both a reranked run and a degraded one
        # produce a full set of plausible numbers.
        contradictions = sorted(
            reason.value
            for reason in DEGRADED_ONLY_EXCLUSIONS
            if self.exclusion_counts.get(reason, 0) > 0
        )
        if contradictions:
            raise RunNotBaselineEligible(
                f"run {self.run_id} records rerank as applied ({rerank.provider}/{rerank.model}) "
                f"while carrying {contradictions}, which is reachable only when stage 11 was "
                "skipped. The skip/applied split has been violated upstream — the run measured "
                "one pipeline and recorded another, so it may not enter a baseline"
            )
        space = self.embedding.space
        return BaselineKey(
            corpus_version=self.corpus.corpus_version,
            corpus_manifest_sha256=self.corpus.manifest_sha256,
            dataset_version=self.dataset_version,
            embedding_provider=space.provider,
            embedding_model=space.model,
            embedding_dimensions=space.dimensions,
            embedding_distance=space.distance,
            embedding_canary_digest=self.embedding.canary_digest,
            rerank_provider=rerank.provider,
            rerank_model=rerank.model,
            rerank_scale=rerank.scale,
            rerank_calibration_derived_from=rerank.calibration_derived_from,
            judge_resolved_model_id=self.judge.resolved_model_id,
            judge_system_fingerprint=self.judge.system_fingerprint,
            judge_temperature=self.judge.temperature,
            ragas_version=self.judge.ragas_version,
            retrieval_configuration_version=self.retrieval_configuration_version,
        )

    @property
    def is_baseline_eligible(self) -> bool:
        """Whether ``baseline_key`` would succeed. For reporting — never as a guard that a
        caller can forget to write, which is what the raising property is for."""
        try:
            _ = self.baseline_key
        except RunNotBaselineEligible:
            return False
        return True


def compare_or_refuse(baseline: BaselineKey, run: EvaluationRunRecord) -> None:
    """Assert a run may be compared against a baseline. Returns nothing; raises otherwise.

    Two failure modes, and both are refusals rather than deltas:

    * The run has no key at all (``RunNotBaselineEligible`` propagates).
    * The keys differ (``IncomparableRuns``, naming the fields).

    A stale-count change is checked by the caller against the baseline's recorded count, not
    here, because this function is deliberately about *identity* and the stale count is a
    property of the run's execution.
    """
    differences = baseline.differences(run.baseline_key)
    if differences:
        raise IncomparableRuns(
            f"run {run.run_id} is not comparable to the baseline; these differ: "
            f"{list(differences)}. Re-baseline against the new configuration rather than "
            "reading the delta — a comparison across any of these is meaningless"
        )


#: Documented here so the reason survives: a skipped-rerank run is still *recorded*, still
#: shown, and still useful — it is the measurement of what an organization on a provider
#: without a ranking endpoint actually experiences. It is excluded from *baselines*, which is
#: a different thing from being thrown away.
SKIPPED_RUNS_ARE_RECORDED_NOT_DISCARDED: Final[bool] = True


assert SKIPPED_RUNS_ARE_RECORDED_NOT_DISCARDED

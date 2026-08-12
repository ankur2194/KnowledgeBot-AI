"""Reranking became optional, and these assertions are what stop "optional" becoming "vague".

The cross-encoder ran in this process, so stage 11 always worked and could be written as
unconditional. It is a provider API call now, and only some providers expose a ranking
endpoint — so there are two ordinary outcomes where there used to be one, and two ways to
get the distinction wrong:

* an **outage recorded as a skip**, which turns an incident into a configuration and hides
  it for as long as nobody reads answer quality;
* a **threshold carried over from the retired local model**, which is a number tuned on one
  distribution applied to another. `0.30` is a valid float on every reranker scale in
  existence, so the swap raises nothing and moves only the aggregate refusal rate.

Imports nothing but the modules under test.
"""

from __future__ import annotations

import inspect

import pytest

from app.rag.rerank import (
    CALIBRATIONS,
    RERANK_MIN_USEFUL_SECONDS,
    RerankCalibration,
    RerankNotCalibrated,
    RerankOutcome,
    RerankScale,
    RerankSkipReason,
    Scored,
    calibration_for,
    rerank_gate,
)

#: Spelled out independently of the enum, so a member added later fails here and is read as
#: the contract change it is rather than as a label.
#:
#: It has fired once, as designed. ``provider_scale_uncalibrated`` was added for finding #47 —
#: a vendor that publishes a ranking endpoint whose scores this platform cannot cut against —
#: and adding it is a contract change in five other artifacts (the metric-catalog row, the
#: label allow-list, the collector allow-list comment, the Retrieval dashboard panel
#: description and the alerting rules' DELIBERATELY-NOT-ALERTED note), because every one of
#: them enumerates the members. This line is what makes somebody find that list.
EXPECTED_SKIP_REASONS = {
    "provider_lacks_capability",
    "provider_scale_uncalibrated",
    "model_not_configured",
    "disabled_by_configuration",
    "insufficient_deadline",
}

#: Words that would mean a failure had been filed as a skip. Grepped over the vocabulary
#: rather than reviewed, because the member that gets added under incident pressure is
#: exactly the one nobody reviews.
FAILURE_WORDS = ("error", "fail", "timeout", "unavailable", "outage", "exception", "5xx")


def test_the_skip_vocabulary_is_closed() -> None:
    assert {reason.value for reason in RerankSkipReason} == EXPECTED_SKIP_REASONS


def test_no_skip_reason_describes_a_failure() -> None:
    """A provider error, a timeout and an unparseable response are errors, not skips.

    Recording one as a skip makes a vendor outage read as "this bot is configured without
    reranking" — a state operators are used to seeing and have no reason to investigate.
    """
    for reason in RerankSkipReason:
        assert not any(word in reason.value for word in FAILURE_WORDS), reason


def test_gate_returns_none_when_everything_is_available() -> None:
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=True,
            provider_publishes_endpoint=True,
            provider_scale=RerankScale.LOGIT,
            model="nvidia/llama-3.2-nv-rerankqa-1b-v2",
            remaining_seconds=1.0,
        )
        is None
    )


def test_a_vendor_with_no_ranking_endpoint_is_a_skip_not_an_error() -> None:
    """Three of the five configured vendors publish no ranking route at all — OpenAI,
    Anthropic and DeepSeek. That is the ordinary case, not a fault, and it must not raise on
    the request path.
    """
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=False,
            provider_publishes_endpoint=False,
            provider_scale=RerankScale.UNCALIBRATED,
            model="anything",
            remaining_seconds=1.0,
        )
        is RerankSkipReason.PROVIDER_LACKS_CAPABILITY
    )


def test_configuration_outranks_capability_in_the_reported_reason() -> None:
    """A bot with reranking switched off, on a provider that could not rerank anyway,
    reports the switch. Reported the other way round, the graph attributes a deliberate
    setting to a provider limitation and the operator debugs the wrong thing.
    """
    assert (
        rerank_gate(
            enabled=False,
            provider_supports=False,
            provider_publishes_endpoint=False,
            provider_scale=RerankScale.UNCALIBRATED,
            model=None,
            remaining_seconds=0.0,
        )
        is RerankSkipReason.DISABLED_BY_CONFIGURATION
    )


def test_capability_outranks_a_missing_model() -> None:
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=False,
            provider_publishes_endpoint=False,
            provider_scale=RerankScale.UNCALIBRATED,
            model=None,
            remaining_seconds=1.0,
        )
        is RerankSkipReason.PROVIDER_LACKS_CAPABILITY
    )


def test_a_short_deadline_declines_before_the_call_rather_than_during_it() -> None:
    """The one dynamic skip, and still a pre-call decision. A call that ran out of time is a
    timeout and therefore an error; starting one that cannot finish bills the tenant for a
    result the request will never see, and then still falls back to the fused order.
    """
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=True,
            provider_publishes_endpoint=True,
            provider_scale=RerankScale.LOGIT,
            model="rank-1",
            remaining_seconds=RERANK_MIN_USEFUL_SECONDS / 2,
        )
        is RerankSkipReason.INSUFFICIENT_DEADLINE
    )


# ── finding #47: publishing an endpoint and being consumable are two questions ───


def test_a_publishing_vendor_this_platform_cannot_threshold_is_not_reported_as_lacking_one() -> (
    None
):
    """OpenRouter's shape. The vendor documents ``POST /api/v1/rerank``; this platform has no
    characterization of what the numbers mean, so ``can_rerank`` answers ``False`` on its third
    axis and the run is degraded — but the reason must not say the vendor lacks the endpoint.

    An operator handed ``provider_lacks_capability`` here goes and reads OpenRouter's
    documentation, finds the route, and concludes the platform is broken. The accurate reason
    points at the one thing that is actually true and actually theirs: nobody has characterized
    the score distribution, and on a gateway fronting several upstream cross-encoders on one
    credential nobody can — so the remedy is to run the bot without reranking, not to go and
    measure something.
    """
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=False,
            provider_publishes_endpoint=True,
            provider_scale=RerankScale.UNCALIBRATED,
            model="some-vendor/rerank-1",
            remaining_seconds=1.0,
        )
        is RerankSkipReason.PROVIDER_SCALE_UNCALIBRATED
    )


def test_a_vendor_with_no_endpoint_is_never_reported_as_a_calibration_gap() -> None:
    """The discriminating case, and the reason ``provider_publishes_endpoint`` exists.

    OpenAI, Anthropic and DeepSeek are absent from ``RERANK_SCALE`` too, so their scale is
    ``UNCALIBRATED`` exactly like OpenRouter's. A gate that attributed on the scale alone would
    report a calibration gap for three vendors that publish nothing, and somebody would go
    looking for the evaluation run that fills it.

    **Measured, and the first draft of this sentence was wrong.** It said removing the endpoint
    check makes "this test and only this test" fail. It makes **four** fail: this one,
    ``test_a_vendor_with_no_ranking_endpoint_is_a_skip_not_an_error``,
    ``test_capability_outranks_a_missing_model``, and
    ``test_the_gate_reports_the_gateway_as_a_scale_problem`` next door — because the two
    pre-existing gate tests already pass a non-publishing vendor and were re-pointed at the new
    argument rather than left alone. What is distinctive here is not that it is the only
    detector, it is that it is the only one that *names the mechanism*: the other three report
    a wrong enum member and leave the reader to work out that two vendor axes had been merged.
    """
    for scale in (RerankScale.UNCALIBRATED, RerankScale.LOGIT):
        assert (
            rerank_gate(
                enabled=True,
                provider_supports=False,
                provider_publishes_endpoint=False,
                provider_scale=scale,
                model="anything",
                remaining_seconds=1.0,
            )
            is RerankSkipReason.PROVIDER_LACKS_CAPABILITY
        ), scale


def test_a_publishing_vendor_with_a_row_that_does_not_claim_it_is_a_capability_gap() -> None:
    """NIM's shape when the ``provider_models`` row simply does not carry ``RERANK``. The
    vendor publishes, the scale is characterized, and eligibility still failed — so it was the
    row, and the reason must not blame a calibration that exists.
    """
    assert (
        rerank_gate(
            enabled=True,
            provider_supports=False,
            provider_publishes_endpoint=True,
            provider_scale=RerankScale.LOGIT,
            model="nvidia/llama-3.2-nv-rerankqa-1b-v2",
            remaining_seconds=1.0,
        )
        is RerankSkipReason.PROVIDER_LACKS_CAPABILITY
    )


def test_neither_new_axis_can_be_omitted() -> None:
    """An optional guard is not a guard — the same rule ``calibration_for(provider_scale=...)``
    follows. A default on either argument restores the collapsed attribution on every call site
    that had not been updated, which is exactly the state this change exists to leave.
    """
    parameters = inspect.signature(rerank_gate).parameters
    for name in ("provider_supports", "provider_publishes_endpoint", "provider_scale"):
        assert parameters[name].kind is inspect.Parameter.KEYWORD_ONLY, name
        assert parameters[name].default is inspect.Parameter.empty, name


def test_the_gate_returns_a_verdict_on_every_combination_and_raises_on_none() -> None:
    """Totality, asserted over the whole input space rather than over the cases anybody
    remembers. ``RerankSkipReason`` is closed and failure-free precisely so an exception can
    never be caught and filed as a skip; a gate that raised on an incoherent row — one whose
    three axes disagree, which is what a bypassed ``assert_row_coherent`` produces — would
    reintroduce that laundering at the one moment it matters.
    """
    seen = set()
    for enabled in (True, False):
        for supports in (True, False):
            for publishes in (True, False):
                for scale in RerankScale:
                    for model in ("rank-1", None):
                        for remaining in (10.0, 0.0):
                            seen.add(
                                rerank_gate(
                                    enabled=enabled,
                                    provider_supports=supports,
                                    provider_publishes_endpoint=publishes,
                                    provider_scale=scale,
                                    model=model,
                                    remaining_seconds=remaining,
                                )
                            )
    assert seen == {None, *RerankSkipReason}


def test_calibration_table_is_empty_until_an_evaluation_run_fills_it() -> None:
    """Deliberately empty. The retired `0.30` on a sigmoid was `bge-reranker-v2-m3`'s number
    and is void; carrying it forward onto a vendor that returns unbounded logits would
    roughly invert the gate while looking like a no-op diff.
    """
    assert CALIBRATIONS == {}


def test_an_uncalibrated_model_raises_rather_than_guessing() -> None:
    """A pair with no entry, on a scale that *could* carry one. This is the ordinary state
    today, and its remedy really is an evaluation run — which is why the message says so and
    why the case below, whose remedy is the opposite, must not share it."""
    with pytest.raises(RerankNotCalibrated, match="not portable"):
        calibration_for(
            "nim",
            "nvidia/llama-3.2-nv-rerankqa-1b-v2",
            provider_scale=RerankScale.LOGIT,
        )


def test_a_logit_threshold_is_not_rejected_for_being_outside_zero_to_one() -> None:
    """NVIDIA's ranking models return roughly ±10. A bounds check written for a sigmoid
    would reject every legitimate logit threshold, which is how a correct calibration gets
    "fixed" into a wrong one.
    """
    cal = RerankCalibration(
        provider="nim",
        model="rank-1",
        scale=RerankScale.LOGIT,
        min_score=-0.85,
        max_passage_tokens=512,
        derived_from="eval-run-1",
    )
    assert cal.min_score == -0.85


def test_a_bounded_scale_rejects_a_logit_pasted_into_the_threshold() -> None:
    """The one direction a type can catch: 3.5 on a 0-1 scale is a logit in the wrong field.
    The other direction — 0.30 moved between two different bounded scales — is undetectable
    here and only an evaluation run finds it.
    """
    with pytest.raises(ValueError, match="bounded scale"):
        RerankCalibration(
            provider="nim",
            model="rank-1",
            scale=RerankScale.SIGMOID,
            min_score=3.5,
            max_passage_tokens=None,
            derived_from="eval-run-1",
        )


def test_a_threshold_must_name_the_run_that_produced_it() -> None:
    """Without provenance the number cannot be re-derived when the model changes — and model
    ids behind ranking endpoints move without notice.
    """
    with pytest.raises(ValueError, match="evaluation run"):
        RerankCalibration(
            provider="nim",
            model="rank-1",
            scale=RerankScale.LOGIT,
            min_score=0.0,
            max_passage_tokens=None,
            derived_from="",
        )


def test_the_scale_enum_is_the_provider_contract_s_and_not_a_local_copy() -> None:
    """There was a second ``RerankScale`` here, ``LOGIT | SIGMOID | UNIT_INTERVAL``, with no
    conversion to or from the provider layer's. Two types of one name at a boundary is how a
    score's scale gets lost while everything type-checks. The contract's enum was a strict
    superset, so this was a deletion rather than a translation.
    """
    from app.providers.contract import RerankScale as ContractScale

    assert RerankScale is ContractScale


def test_uncalibrated_is_representable_here_now() -> None:
    """The member the local copy could not express, and its absence had a consequence.

    An adapter reporting "nobody has characterized this scale" had to be widened by a guess
    onto ``SIGMOID`` or ``UNIT_INTERVAL``, and a ``min_score`` of 0.30 then passed every check
    against a distribution nobody had ever measured.
    """
    assert RerankScale.UNCALIBRATED in set(RerankScale)
    assert not RerankScale.UNCALIBRATED.may_threshold


def test_an_uncalibrated_scale_cannot_carry_a_threshold_at_all() -> None:
    """Unconstructible rather than discouraged. A threshold on a scale nobody has measured
    compares cleanly against every score it will ever meet and means nothing.

    Note the number: 0.30 is the retired local cross-encoder's sigmoid threshold, and it is a
    valid float on every scale in this enum. Before this check it passed the range check too,
    because the old ``is_bounded`` answered ``True`` for anything that was not ``LOGIT``.
    """
    with pytest.raises(ValueError, match="may not be thresholded"):
        RerankCalibration(
            provider="openrouter",
            model="some/reranker",
            scale=RerankScale.UNCALIBRATED,
            min_score=0.30,
            max_passage_tokens=None,
            derived_from="eval-run-1",
        )


def test_being_unbounded_does_not_make_a_scale_unthresholdable() -> None:
    """``is_bounded`` and ``may_threshold`` are two predicates and must stay two.

    ``is_bounded`` is a **range** question — where a threshold must lie — and thresholdability
    is a **calibration** question. They coincide on the two bounded members, which is what
    makes collapsing them seductive and what makes the collapse invisible in a fixture. What it
    is not invisible in is production: NVIDIA NIM is the only one of the five providers whose
    rerank scores may be cut against at all — OpenRouter publishes a route too, and is
    ``UNCALIBRATED`` — and NIM's scale is an unbounded logit, so a ``LOGIT`` that may never be
    thresholded makes stage 12 unreachable for every organization on the platform, and the
    symptom is a refusal rate of zero with nothing raised anywhere.

    Asserted from this side of the boundary as well as from
    ``tests/unit/test_provider_capability_matrix.py``, because this module is the consumer and
    a one-sided revert would otherwise be caught only in the file that owns the enum.
    """
    assert not RerankScale.LOGIT.is_bounded
    assert RerankScale.LOGIT.may_threshold
    assert {s for s in RerankScale if not s.may_threshold} == {RerankScale.UNCALIBRATED}


def test_the_two_bounded_scales_are_distinct_values() -> None:
    """`sigmoid` and `unit_interval` are both 0-1, and that coincidence is exactly what
    invites treating a threshold as portable between them. Only the bounds transfer; the
    distribution does not, and the distribution is the calibration.
    """
    assert RerankScale.SIGMOID != RerankScale.UNIT_INTERVAL
    assert RerankScale.SIGMOID.is_bounded and RerankScale.UNIT_INTERVAL.is_bounded
    assert not RerankScale.LOGIT.is_bounded


def test_an_outcome_is_either_skipped_or_applied_and_never_both() -> None:
    """Two populated fields with an invariant, rather than two return types: the caller must
    handle both branches, and a union invites an isinstance ladder that quietly accepts the
    wrong one.
    """
    with pytest.raises(ValueError, match="either skipped"):
        RerankOutcome(skipped=None, calibration=None)


def test_a_skipped_outcome_cannot_carry_scores() -> None:
    """Nothing scored them, so any number in that field came from somewhere it should not
    have — the fused score being the obvious candidate, and the one the evidence gate must
    never see.
    """
    scored = Scored(candidate=None, score=1.0, scale=RerankScale.LOGIT)  # type: ignore[arg-type]
    with pytest.raises(ValueError, match="nothing may have scored them"):
        RerankOutcome(
            skipped=RerankSkipReason.PROVIDER_LACKS_CAPABILITY,
            calibration=None,
            scored=(scored,),
        )


def test_an_applied_outcome_cannot_carry_an_unranked_ordering() -> None:
    """The fused order is not an output of a run that reranked, and a packer that finds one
    there has no way to tell which ordering was judged.
    """
    cal = RerankCalibration(
        provider="nim",
        model="rank-1",
        scale=RerankScale.UNIT_INTERVAL,
        min_score=0.2,
        max_passage_tokens=None,
        derived_from="eval-run-1",
    )
    with pytest.raises(ValueError, match="not an output"):
        RerankOutcome(skipped=None, calibration=cal, unranked=(None,))  # type: ignore[arg-type]


def test_a_score_cannot_travel_without_its_scale() -> None:
    """The scale is a field because it is no longer a property of this repository. A score
    that arrives without one is a float that could mean either "clearly relevant" or "leans
    irrelevant", and the difference is invisible in every trace.
    """
    with pytest.raises(TypeError):
        Scored(candidate=None, score=0.3)  # type: ignore[call-arg]

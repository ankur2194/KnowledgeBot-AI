"""Finding #47, retrieval half — a provider that ranks but whose scores may never be thresholded.

The finding is titled *"OpenRouter reranks but can never reach the evidence threshold"*, and the
first thing to record is that the mechanism it assumes is **not** what this code does. There is
no silent under-scoring and no path on which every candidate is quietly dropped below a
threshold: ``apply_threshold`` asserts ``Scored.scale is calibration.scale`` **before** comparing
any number, and an ``UNCALIBRATED`` response therefore raises ``RerankScaleMismatch`` rather than
refusing. ``test_rerank_scale_provenance.py`` proves the raise; the test here proves the other
half of the same claim — that nothing was recorded as *below threshold* on the way past — because
a refusal-shaped outcome is what the finding feared and it would be indistinguishable from a
genuine refusal in every metric the platform has.

What was genuinely missing is **where** the disagreement is caught. Every guard sat either at the
type (``RerankCalibration`` refuses to be built on an unthresholdable scale) or in stage 12,
which runs *after* a tenant's passages have been shipped to a vendor and billed. The vendor's
scale is a static fact on its capability row, so the mismatch is decidable before anything is
spent, and ``calibration_for`` now decides it.

The second thing missing was the *remedy*. "Derive it from an evaluation run and add it to
CALIBRATIONS" is advice an OpenRouter operator cannot follow: the vendor is a gateway fronting
several upstream cross-encoders on one credential with no documented normalization across them,
so an evaluation run measures a mixture, and the entry it produced would be rejected by
``RerankCalibration.__post_init__`` weeks later for a reason the first message never mentioned.
"Not calibrated yet" and "not calibratable" are different states and get different messages.

**``CALIBRATIONS`` stays empty.** Filling it is an evaluation run over the golden corpus and is
out of scope; the local mappings below are ``monkeypatch``ed for the length of one test each, and
``test_the_shipped_table_is_still_empty`` is the guard on that.
"""

from __future__ import annotations

import inspect
from typing import Final

import pytest
from qdrant_client import models

from app.providers.contract import Diagnostics, RerankRequest, RerankResult, RerankScale, Usage
from app.rag import rerank as stage11
from app.rag.evidence import apply_threshold
from app.rag.rerank import (
    CALIBRATIONS,
    RerankCalibration,
    RerankNotCalibrated,
    RerankScaleMismatch,
    calibration_for,
    rerank,
)
from app.rag.stages import ExclusionReason

#: The pair the finding is about. OpenRouter's ``/api/v1/rerank`` is SUPPORTED and wired — the
#: vendor publishes a first-class route — and the provider is deliberately absent from
#: ``capabilities.RERANK_SCALE``, which makes its scale ``UNCALIBRATED``. Those two facts
#: together are the finding.
#:
#: **This comment used to end "so ``can_rerank`` answers True and
#: ``rerank_gate(provider_supports=...)`` lets stage 11 run", and that has been false since the
#: third eligibility axis landed.** ``can_rerank`` reads ``RERANK_SCALE`` as well as the vendor
#: cell and the row, so it answers ``False`` here, and the gate now declines *with the accurate
#: reason* — ``PROVIDER_SCALE_UNCALIBRATED`` rather than ``PROVIDER_LACKS_CAPABILITY``, which
#: would be a false statement about a vendor whose documentation says the opposite. That last
#: step is pinned by ``test_the_gate_reports_the_gateway_as_a_scale_problem`` at the foot of
#: this file, which is the only assertion tying the capability matrix to the skip reason.
GATEWAY: Final[tuple[str, str]] = ("openrouter", "some-vendor/rerank-1")

#: The pair that can carry a threshold: NVIDIA NIM is the only configured provider with a
#: ranking endpoint and a characterized scale, and that scale is an unbounded logit.
RANKER: Final[tuple[str, str]] = ("nvidia_nim", "nvidia/llama-3.2-nv-rerankqa-1b-v2")

ORG: Final[str] = "01JQZ00000000000000000000A"
TRACE: Final[str] = "0af7651916cd43dd8448eb211c80319c"
CONNECTION: Final[str] = "01JQZ00000000000000000CNN0"


def _calibration(scale: RerankScale, *, pair: tuple[str, str] = RANKER) -> RerankCalibration:
    return RerankCalibration(
        provider=pair[0],
        model=pair[1],
        scale=scale,
        min_score=-0.85 if scale is RerankScale.LOGIT else 0.4,
        max_passage_tokens=512,
        derived_from="eval-run-test",
    )


class _Ctx:
    org_id = ORG
    bot_id = "01JQZ00000000000000000BT00"


class _Run:
    """Records what stage 12 excluded, which is the whole point of one test below."""

    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason]] = []

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason))

    def record_labels(self, labels: object) -> None:
        return None

    def remaining_seconds(self) -> float:
        return 10.0


class _Reranker:
    """Satisfies ``Reranker`` and reports whatever scale it is told to. Not a provider."""

    name = "fake-gateway"

    def __init__(self, scale: RerankScale) -> None:
        self.scale = scale
        self.calls = 0

    async def rerank(self, req: RerankRequest) -> RerankResult:
        self.calls += 1
        return RerankResult(
            scores=[0.9 for _ in req.passages],
            scale=self.scale,
            usage=Usage(),
            total_ms=7,
            diagnostics=Diagnostics(provider="fake"),
        )


def _candidate(chunk_id: str) -> stage11.Candidate:
    return stage11.Candidate(
        point=models.ScoredPoint(
            id=1, version=1, score=0.5, payload={"chunk_id": chunk_id, "source_id": "src-1"}
        )
    )


# ── the state the finding is actually about ─────────────────────────────────────


def test_an_unthresholdable_provider_scale_is_refused_and_the_refusal_is_terminal() -> None:
    """No evaluation run produces a calibration here, so the message must not ask for one.

    A gateway routing to several upstreams on one credential has no single distribution to read
    a percentile off. Sending the operator to measure one costs them the run and then fails
    again at ``RerankCalibration.__post_init__``, with a different error, weeks later.
    """
    with pytest.raises(RerankNotCalibrated) as raised:
        calibration_for(*GATEWAY, provider_scale=RerankScale.UNCALIBRATED)

    message = str(raised.value)
    assert "may never be thresholded" in message
    assert "reranking disabled" in message
    assert "add it to CALIBRATIONS" not in message


def test_the_two_refusals_do_not_share_a_remedy() -> None:
    """The distinction is the fix. One says "go and measure"; the other says "you cannot, run
    without it" — and a single message would be wrong for one caller every time.

    **This is also the assertion that pins the check order**, which was measured rather than
    assumed: moving the scale guard below the table lookup makes the unthresholdable pair fall
    through to the missing-entry branch, because ``CALIBRATIONS`` is empty — so the terminal
    caller is handed the one remedy it cannot act on, and this test is what notices.
    """
    with pytest.raises(RerankNotCalibrated) as waiting:
        calibration_for(*RANKER, provider_scale=RerankScale.LOGIT)
    with pytest.raises(RerankNotCalibrated) as terminal:
        calibration_for(*GATEWAY, provider_scale=RerankScale.UNCALIBRATED)

    assert "add it to CALIBRATIONS" in str(waiting.value)
    assert "reranking disabled" not in str(waiting.value)
    assert "add it to CALIBRATIONS" not in str(terminal.value)


def test_an_entry_cannot_smuggle_a_threshold_onto_an_unthresholdable_provider(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A legal entry on an illegal pair must not be returned, whatever else is true.

    A calibration keyed on the gateway pair, carrying a perfectly legal ``LOGIT`` scale, is
    constructible: ``RerankCalibration`` refuses the ``UNCALIBRATED`` *scale*, not the *pair*.
    Without a guard keyed on the provider's own row, that entry is simply returned — stage 11
    runs, and the vendor's ``UNCALIBRATED`` response raises in stage 12, after the passages have
    been shipped and billed. This is the case the table alone cannot refuse.

    Note what it does **not** prove: it survives the guard being moved below the lookup, because
    the entry exists and the guard still fires. Order is pinned above, by the remedy each
    refusal carries.
    """
    monkeypatch.setattr(
        stage11, "CALIBRATIONS", {GATEWAY: _calibration(RerankScale.LOGIT, pair=GATEWAY)}
    )
    with pytest.raises(RerankNotCalibrated, match="may never be thresholded"):
        calibration_for(*GATEWAY, provider_scale=RerankScale.UNCALIBRATED)


def test_an_entry_that_disagrees_with_the_providers_row_is_refused_before_any_call(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The same ``RerankScaleMismatch`` stage 12 raises, caught where it costs nothing.

    Stage 12 compares the calibration against what the vendor reported *on this response*, which
    is the only way to see a vendor moving its scale. This one compares it against the vendor's
    static capability row, which is knowable at configuration time — and the difference between
    the two call sites is one billed round-trip and one failed request.
    """
    monkeypatch.setattr(stage11, "CALIBRATIONS", {RANKER: _calibration(RerankScale.SIGMOID)})
    with pytest.raises(RerankScaleMismatch, match="capability row reports logit"):
        calibration_for(*RANKER, provider_scale=RerankScale.LOGIT)


def test_an_agreeing_entry_is_returned(monkeypatch: pytest.MonkeyPatch) -> None:
    """The control. Without it the three refusals above could all be one over-broad guard."""
    monkeypatch.setattr(stage11, "CALIBRATIONS", {RANKER: _calibration(RerankScale.LOGIT)})
    assert calibration_for(*RANKER, provider_scale=RerankScale.LOGIT).min_score == -0.85


def test_the_provider_scale_cannot_be_omitted() -> None:
    """An optional guard is not a guard: a default would restore exactly the behaviour this
    function was changed to stop, on every call site that had not been updated."""
    parameter = inspect.signature(calibration_for).parameters["provider_scale"]
    assert parameter.kind is inspect.Parameter.KEYWORD_ONLY
    assert parameter.default is inspect.Parameter.empty


# ── and what stage 12 does when one gets past anyway ────────────────────────────


async def test_an_uncalibrated_response_raises_and_refuses_nothing() -> None:
    """The finding's stated mechanism, tested for its **absence**.

    "Silently under-scoring and dropping every candidate below threshold" would produce an empty
    return and a run full of ``below_evidence_threshold`` rows — identical, in every metric the
    platform has, to a bot that correctly found no evidence. What happens instead is a raise,
    with nothing excluded, because the scale assertion runs before any comparison.

    That this raises is also asserted by ``test_rerank_scale_provenance.py``; what is new here is
    that the exclusion ledger is empty, which is the half that distinguishes an error from a
    refusal.
    """
    reranker = _Reranker(RerankScale.UNCALIBRATED)
    outcome = await rerank(
        reranker,
        _calibration(RerankScale.LOGIT),
        _Ctx(),  # type: ignore[arg-type]
        "does it cover accidental damage",
        [_candidate("a"), _candidate("b")],
        {"a": "Heading > a", "b": "Heading > b"},
        _Run(),
        trace_id=TRACE,
        provider_connection_id=CONNECTION,
        max_candidates=25,
    )

    run = _Run()
    with pytest.raises(RerankScaleMismatch):
        apply_threshold(outcome, run)  # type: ignore[arg-type]

    assert run.excluded == []
    assert reranker.calls == 1


# ── the constraint this task was given ──────────────────────────────────────────


def test_the_gate_reports_the_gateway_as_a_scale_problem() -> None:
    """The end of the finding, wired through the real capability matrix rather than fixtures.

    Every other assertion in this file feeds ``calibration_for`` a ``provider_scale`` by hand,
    which proves the refusal and proves nothing about whether the platform would ever hand it
    that value. This one reads all three axes out of ``app/providers/capabilities.py`` for the
    two vendors that publish a ranking route, and asserts the skip reason each produces.

    The pairing is what makes it discriminating. NIM and OpenRouter both publish an endpoint;
    only NIM's scores may be cut against. A gate that attributed on the endpoint alone would
    let OpenRouter through to a stage that cannot threshold it, and a gate that attributed on
    the scale alone would report the same reason for both — and for OpenAI, Anthropic and
    DeepSeek, which publish nothing and are ``UNCALIBRATED`` for that reason rather than this
    one.

    The import sits inside the test body, exactly as
    ``tests/unit/test_provider_capability_matrix.py`` keeps its ``app.rag`` reference inside
    one, so neither module opens an ``app/providers`` → ``app/rag`` edge at import time.
    """
    from app.providers.capabilities import (
        Capability,
        ModelCapabilities,
        ProviderSurface,
        can_rerank,
        provider_offers,
        rerank_scale,
    )
    from app.rag.rerank import RerankSkipReason, rerank_gate

    claims_rerank = ModelCapabilities(
        supported=frozenset({Capability.RERANK}), context_window=8192, max_output_tokens=4096
    )

    def gate(provider: str) -> RerankSkipReason | None:
        return rerank_gate(
            enabled=True,
            provider_supports=can_rerank(provider, claims_rerank),
            provider_publishes_endpoint=provider_offers(provider, ProviderSurface.RERANK),
            provider_scale=rerank_scale(provider),
            model="a-configured-model",
            remaining_seconds=10.0,
        )

    assert gate("openrouter") is RerankSkipReason.PROVIDER_SCALE_UNCALIBRATED
    assert gate("nvidia_nim") is None
    for absent in ("openai", "anthropic", "deepseek"):
        assert gate(absent) is RerankSkipReason.PROVIDER_LACKS_CAPABILITY, absent


def test_the_shipped_table_is_still_empty() -> None:
    """Every mapping above is a ``monkeypatch`` for the length of one test. Filling
    ``CALIBRATIONS`` is an evaluation run over the golden corpus — fifty answerable and fifty
    unanswerable questions scored through the full pipeline — and not a code change."""
    assert CALIBRATIONS == {}
    assert stage11.CALIBRATIONS == {}

"""Stage 11 — reranking, which is now a remote call and an **optional** one.

Everything that used to be in this module was a consequence of the cross-encoder running
in-process: the loader, the thread offload, the dedicated ``CapacityLimiter``, the fp16
gate, the ``max_length`` pin. None of it applies. Embeddings and reranking are external API
calls through ``app/providers/`` (the same adapter layer, the same per-organization
encrypted credentials, the same quota accounting, the same error taxonomy), so what replaces
that machinery is a network round-trip inside the retrieval budget — and a provider that may
not offer the endpoint at all.

**Reranking is capability-gated, not guaranteed — and the gate has two halves that are
routinely collapsed into one.** *Two* of the five configured vendors publish a ranking
endpoint, NVIDIA NIM and OpenRouter, and only NIM's scores may ever be cut against
(``app/providers/nim.py``, whose section on the ranking surface is the sourced statement of
this). OpenAI, Anthropic and DeepSeek publish none at all. The prose that used to sit here —
*"of the five configured providers only NVIDIA NIM exposes a ranking endpoint"* — merged the
two halves and was false as a statement about vendors: ``capabilities.PROVIDER_TASKS`` carries
``("openrouter", RERANK): SUPPORTED`` against two vendor doc URLs, and
``providers_offering(RERANK)`` is pinned to ``{"nvidia_nim", "openrouter"}`` by a test.

**Publishing and being consumable are different questions, and the second one is ours.**
OpenRouter's ``/api/v1/rerank`` is a documented first-class route, and this platform still
cannot threshold what comes back, because the route fronts several upstream cross-encoders on
one credential with no documented normalization across them — so ``capabilities.RERANK_SCALE``
has no entry for it, its scale is ``UNCALIBRATED``, and ``RerankCalibration`` refuses to be
constructed on that scale. That is a refusal about *this platform*, not about the vendor, and
it has its own skip reason below; reporting it as "the provider lacks the capability" sends an
operator to read a vendor's documentation that will tell them the endpoint exists.

A local model always worked, so stage 11 could be written as unconditional. It cannot be now.
When the organization's provider cannot rerank *for either reason*, the pipeline serves the
RRF-fused order and records the skip under the reason that is true; the stage is never jumped,
and the skip is never silent.

**A skip is not a failure, and a failure is never recorded as a skip.** ``RerankSkipReason``
is closed and contains only reasons known *before* the call goes out — a capability the
provider does not have, a model nobody configured, a switch an administrator turned off, a
deadline too short to start. There is deliberately no member meaning "the provider errored",
"the call timed out" or "the response did not parse". Those are errors in
``kb-error-taxonomy`` terms and they propagate as errors. The distinction is worth the extra
type: a skip degrades ranking in a way that is visible and measured, while an outage laundered
into a skip degrades ranking *and* hides an incident, and the only symptom of the second is
answer quality drifting for as long as nobody looks.

**No LLM-scoring fallback.** Scoring passages by asking a chat model is a legitimate
configuration and an illegitimate fallback: it changes cost and latency by an order of
magnitude, and a fallback nobody chose does that silently, on the request path, in whatever
volume the traffic happens to be. If it is ever wanted it is switched on deliberately, per
bot, and it is then the configured reranker rather than a substitute for one.

**The score scale is provider-and-model dependent, and the threshold moved with it.** The
old repo-wide ``SCALE = "sigmoid"`` and ``evidence.min_score = 0.30`` were properties of
``bge-reranker-v2-m3`` under ``normalize=True``; both are void. NVIDIA's ranking models
return an **unbounded logit**, Cohere-shaped APIs return a bounded relevance score, and 0.30
is a valid float on every one of those scales. Nothing raises when they are swapped — only
the refusal rate moves, and only in aggregate. So a threshold now belongs to a
``(provider, model)`` calibration derived from an evaluation run, there is no portable
default, and ``CALIBRATIONS`` below is **empty on purpose**: an uncalibrated model is one a
bot may not threshold against, and the honest behaviour is to raise at configuration time
rather than to answer with a number borrowed from a different model's distribution.

**"Not calibrated yet" and "not calibratable" are different states, and only one of them is
waiting on an evaluation run.** ``capabilities.RERANK_SCALE`` records what a provider's rerank
scores mean; a provider absent from it is ``RerankScale.UNCALIBRATED``, and ``UNCALIBRATED`` is
the one member ``may_threshold`` refuses. That is not a placeholder. OpenRouter's ranking
endpoint is supported and wired, and it is deliberately absent from that table because the
vendor is a gateway fronting several upstream cross-encoders on one credential with no
documented normalization across them — the meaning of a ``relevance_score`` varies with which
upstream served the request, so there is no single distribution for a percentile to be read off.
An evaluation run over the golden corpus cannot fix that; it would measure a mixture. So
``calibration_for`` separates the two states and gives each its own remedy, because telling an
operator to run an evaluation they cannot act on costs them the evaluation and then fails a
second time at ``RerankCalibration.__post_init__``, weeks later, for a reason the first message
did not mention. A bot on such a provider runs with reranking **off** and is served by
``select_unranked``; that is a degraded mode, it is visible on the trace, and it is not an
error.

**Latency now has a different shape.** The old failure was a CPU forward pass blocking the
event loop; the new one is round-trips. Ranking APIs cap the passages accepted per request,
so a candidate depth above that cap becomes several sequential HTTP calls unless they are
issued concurrently — an unbatched loop over 25 candidates is 25 round-trips inside a 1.5 s
leg. Batch size and concurrency are properties of the provider and belong in the adapter;
what belongs here is the rule that stage 11 must be able to state its cost before it starts,
and must decline rather than overrun.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from enum import StrEnum
from typing import Final, Protocol

from opentelemetry import trace

from app.providers.contract import RerankRequest, RerankResult, RerankScale
from app.rag.stages import ExclusionReason, StageRun, span_for
from app.retrieval.search import Candidate
from app.retrieval.tenancy import TenantContext

__all__ = [
    "CALIBRATIONS",
    "RERANK_MIN_USEFUL_SECONDS",
    "RerankCalibration",
    "RerankNotCalibrated",
    "RerankOutcome",
    "RerankScale",
    "RerankScaleMismatch",
    "RerankSkipReason",
    "Reranker",
    "Scored",
    "calibration_for",
    "rerank",
    "rerank_gate",
]

# ``RerankScale`` is imported, not defined. This module carried a local copy —
# ``LOGIT | SIGMOID | UNIT_INTERVAL`` — for as long as the provider layer carried a
# differently-shaped one, and two enums of one name with no conversion between them is how a
# score's meaning gets lost at a boundary that type-checks. The contract's enum was by then a
# strict superset, which is what made the resolution a deletion rather than a translation: no
# member was lost and ``UNCALIBRATED`` was gained. That member is the one this module could not
# express at all, and its absence had a consequence rather than being a tidiness point: an
# adapter reporting "nobody has characterized this scale" had to be widened by a guess onto
# ``SIGMOID`` or ``UNIT_INTERVAL``, at which point a ``min_score`` of 0.30 sailed through the
# range check below against a distribution nobody has ever measured.
#
# The import direction is ``app/rag`` -> ``app/providers`` -> ``app/retrieval``, and it does not
# close. ``tests/unit/test_provider_capability_matrix.py`` keeps its own reference inside a test
# body specifically to avoid opening the reverse edge.
#
# ONE EDGE RUNS THE OTHER WAY, and it is recorded here rather than left for someone to discover:
# ``app/retrieval/search.py`` imports ``span_for`` from ``app/rag/stages.py``. The alternative was
# a second copy of the span catalogue inside ``app/retrieval/``, and a span name that exists in two
# places is a name that will eventually exist in two spellings — at which point one of them matches
# no recording rule, alert or dashboard query and the failure is empty panels rather than an error.
# The edge is safe because ``app/rag/stages.py`` is a leaf: it imports nothing from ``app`` at all,
# so the graph still has no cycle, and ``tests/unit/test_stage_catalogue_is_a_leaf.py`` fails if it
# ever grows one. The direction that must NOT exist is ``app/providers`` -> ``app/rag``, and that
# one is unchanged.


class RerankSkipReason(StrEnum):
    """Why stage 11 did not score anything. **Closed, and deliberately failure-free.**

    Every member is decidable before the request goes out. Adding a member for a provider
    error, a timeout, or an unparseable response would make an outage indistinguishable from
    a configuration, which is the one distinction this type exists to hold: a skipped rerank
    is a known, cheaper mode of operation, and a failed rerank is an incident.

    ``INSUFFICIENT_DEADLINE`` is the only dynamic member, and it is still a pre-call
    decision — taken from the deadline remaining *before* the call, never from a call that
    ran out of time, which is a timeout and therefore an error. Its rate is a load signal:
    rising, it means the retrieval leg is being squeezed upstream, and answers are quietly
    getting worse before anything alerts.

    **CLOSED AT FIVE, and the fifth was added rather than folded into the first** (finding
    #47). ``PROVIDER_SCALE_UNCALIBRATED`` is the case where the vendor *does* publish a
    ranking endpoint and this platform cannot consume its scores — OpenRouter today. Before it
    existed, ``can_rerank`` answered ``False`` on that third axis and ``rerank_gate`` reported
    ``PROVIDER_LACKS_CAPABILITY``, which is a false statement about a vendor whose
    documentation says the opposite: an operator reading that reason goes and confirms the
    endpoint exists, and then has nowhere to go. The two reasons also have opposite remedies —
    ``PROVIDER_LACKS_CAPABILITY`` is fixed by moving the bot to a provider that ranks, and
    ``PROVIDER_SCALE_UNCALIBRATED`` is not fixed by an evaluation run at all (see
    ``calibration_for``'s first refusal), so collapsing them costs a corpus run.

    Adding it does **not** weaken the failure-free rule. It is a pre-call, static fact read off
    ``capabilities.RERANK_SCALE``, the same table ``RerankCalibration`` consults; nothing about
    it depends on a request having been made.

    It is a **defensive path**, and the reason has been corrected (finding J1). It used to
    read: ``assert_row_coherent`` refuses such a row at save time, so a request reaching this
    member got past save-time validation. There is no save-time validation — nothing calls
    ``assert_row_coherent`` on a write path, and a rerank row is examined by nothing. What
    actually keeps this member unreached is ``capabilities.can_rerank``, which answers ``False``
    on an uncharacterized scale, so no ``Reranker`` is ever bound. The row itself saves cleanly.
    That is a reason to name this member accurately, not a reason to
    leave it unnamed — an unreachable branch reporting the wrong thing is discovered by
    reading the metric, months later, on the one day it becomes reachable.
    """

    PROVIDER_LACKS_CAPABILITY = "provider_lacks_capability"
    PROVIDER_SCALE_UNCALIBRATED = "provider_scale_uncalibrated"
    MODEL_NOT_CONFIGURED = "model_not_configured"
    DISABLED_BY_CONFIGURATION = "disabled_by_configuration"
    INSUFFICIENT_DEADLINE = "insufficient_deadline"


class RerankNotCalibrated(Exception):
    """A reranker model has no evaluated threshold, so nothing may be thresholded against it.

    Raised at configuration time — when a bot's rerank model is resolved — and not on the
    request path. The alternative to raising is picking a plausible number, and a threshold
    borrowed from another model's distribution produces either constant refusal or confident
    nonsense, both of which read as a corpus problem for weeks.

    **Two states raise this, and their remedies are opposite.** A ``(provider, model)`` missing
    from ``CALIBRATIONS`` on a scale that *may* be thresholded is waiting on an evaluation run,
    and the message says so. A pair whose provider scale is ``UNCALIBRATED`` is not waiting on
    anything: no calibration can exist for it, because ``RerankCalibration`` refuses to be
    constructed with that scale, and the run that would produce the number would be measuring a
    mixture of upstreams. Its remedy is to run the bot without reranking. Collapsing the two
    into one message sends an operator to do an evaluation whose result is unusable, and the
    second failure arrives weeks later with no reference to the first.
    """


class RerankScaleMismatch(Exception):
    """The provider reported a score scale the calibration was not derived on.

    It is an error and never a skip: a mismatch means the configuration and the vendor disagree
    about what the numbers mean, which is not a degraded mode anybody chose. This is the one
    failure in this area that cannot be caught by reading a number, because every scale produces
    plausible floats and ``0.30`` is valid on all of them. The symptom of not raising is a
    refusal rate that moves in aggregate, months later, with no diff to point at.

    **Raised in two places, deliberately, and the cheap one comes first.**

    * ``calibration_for``, at configuration time, comparing the calibration's scale against the
      provider's own capability row. Nothing has been spent at this point.
    * ``apply_threshold``, in stage 12 on the request path, comparing the calibration's scale
      against what the vendor reported *on this response* — which is the only place a vendor
      silently moving its scale under an unchanged threshold can be seen at all.

    Neither subsumes the other. The first catches a wrong entry before it can bill anybody; the
    second catches a vendor changing its mind, which no static check can see. The request-path
    raise is expensive by nature — it happens after a tenant's passages have been shipped to a
    vendor and billed — so anything decidable earlier is decided earlier.
    """


@dataclass(frozen=True, slots=True)
class RerankCalibration:
    """One evaluated ``(provider, model)`` reranking configuration.

    The threshold and the scale are one setting in two fields and travel together
    everywhere. ``derived_from`` names the evaluation run that produced the number, because
    a threshold whose provenance is unknown cannot be re-derived when the model changes —
    and vendor model ids behind a ranking endpoint move without notice.

    ``max_passage_tokens`` is the provider's documented per-passage ceiling. It matters for
    the same reason the local ``max_length`` did: a passage truncated by the API is scored on
    less text than it contains, the loss falls hardest on the largest and most
    information-dense chunks, and the distribution the threshold was tuned on moves without
    an error. ``None`` means the provider does not document one, which is not the same as
    "there is none" — treat it as unknown and assert chunk sizes against the chunker instead.
    """

    provider: str
    model: str
    scale: RerankScale
    min_score: float
    max_passage_tokens: int | None
    derived_from: str

    def __post_init__(self) -> None:
        # First, because it is a question about whether this object may exist at all. A
        # threshold on a scale nobody has characterized is not a discouraged configuration, it
        # is an unconstructible one — the alternative is a number that compares cleanly against
        # every score it will ever meet and means nothing.
        if not self.scale.may_threshold:
            raise ValueError(
                f"scale {self.scale.value} may not be thresholded: no distribution has been "
                "measured for it, so there is no percentile for min_score to be. Characterize "
                "the (provider, model) pair with an evaluation run first; a score on this "
                "scale may be used for ORDERING and nothing else"
            )
        # Second, and separately. ``is_bounded`` is a RANGE check, not a permission — it asks
        # where a threshold must lie on a scale that may already be thresholded. The two
        # predicates coincide on the bounded members, which is what makes collapsing them
        # seductive and what makes the collapse invisible in a fixture; it is also what makes
        # every unbounded scale unthresholdable, and the only provider here whose scores may
        # be cut against at all returns an unbounded logit. (Two vendors publish a ranking
        # route; one is eligible. See the module docstring — the difference between those two
        # sentences is what `RerankSkipReason.PROVIDER_SCALE_UNCALIBRATED` reports.)
        if self.scale.is_bounded and not 0.0 <= self.min_score <= 1.0:
            raise ValueError(
                f"min_score {self.min_score} is outside 0..1 on the bounded scale "
                f"{self.scale.value} — this is the logit-pasted-into-a-bounded-field case"
            )
        if not self.derived_from:
            raise ValueError(
                "a threshold with no evaluation run behind it is a guess; record the run id"
            )


#: **Empty on purpose.** Every entry is the output of an evaluation run over the golden
#: corpus (`rag-eval-engineer`), scored through the full pipeline: keep the top-1 reranker
#: score per question over at least fifty answerable and fifty unanswerable questions, set
#: the threshold at the 5th percentile of the answerable distribution, then report the
#: false-answer rate. If more than roughly a tenth of unanswerable questions clear it, the
#: distributions overlap and the problem is retrieval or chunking, not the number.
#:
#: Until an entry exists for a model, reranking against that model is not configurable —
#: which is the correct state, not a gap to be filled with the old 0.30.
CALIBRATIONS: Final[Mapping[tuple[str, str], RerankCalibration]] = {}

#: Below this much remaining deadline, stage 11 does not start. Reranking is now a network
#: call, and starting one with less time than it needs spends the tenant's quota on a result
#: the request will never see — and then still has to fall back to the fused order, having
#: paid for both. The number is a placeholder until a provider's p95 is measured; it is
#: stated explicitly rather than left implicit because "no check" reads identically to
#: "checked and fine" in every trace.
RERANK_MIN_USEFUL_SECONDS: Final[float] = 0.35

#: One tracer for the module, created at import. The OTel API hands out a proxy that re-binds
#: when the real provider is installed, which is what makes import-time creation safe in a
#: Celery worker where the provider cannot be built until after ``fork()``.
_TRACER: Final = trace.get_tracer("app.rag.rerank")


class Reranker(Protocol):
    """The slice of the provider adapter stage 11 uses — ``RerankAdapter.rerank`` with its
    capability row and its credential already bound to one organization's connection.

    **What is bound and what is not is the whole design of this type.** Bound: ``caps`` and
    ``credential``. Resolving and decrypting a provider credential is the provider layer's
    business, so nothing under ``app/rag/`` can hold a secret, log one, or serialize one into a
    Celery payload or a span. *Not* bound, and passed explicitly on every call: the
    ``RerankRequest``, because it is what names the tenant.

    That second half was wrong until now and is worth recording rather than quietly fixing.
    This protocol read ``rerank(query, passages, *, model)`` — a shape
    ``app/providers/contract.py`` rejects by name, on the grounds that *tenant isolation is
    enforced in code at every layer and a call that cannot name its organization cannot be
    scoped, metered or traced*. A bare ``(query, passages, model)`` has nowhere to put
    ``org_id``, ``trace_id`` or ``provider_connection_id``, so the org survived only inside
    whichever closure happened to build the callable. Nothing leaked, because nothing binds
    these callables yet — and that is exactly why the shape had to change before something
    does. Reranking is a tenant-scoped provider call that bills a tenant's quota and carries a
    tenant's document text to a vendor; the request object is where all three facts live.

    Taking ``RerankRequest`` rather than a local structural twin also removes the second
    definition. The contract's validator, its per-vendor passage ceiling, and its deliberate
    absence of ``top_n`` — ask for every score in input order and let the pipeline cut — apply
    to this call because it *is* that call.

    **The return is a ``RerankResult`` and not a ``list[float]``, and that is a fix rather
    than a widening.** A bare score list cannot carry the scale, so stage 11 had no source for
    it other than the calibration it was handed — which made ``Scored.scale`` a copy of
    ``RerankCalibration.scale`` by construction, and made stage 12's scale assertion a
    comparison of a value with itself. The vendor's own claim about what its numbers mean never
    entered the comparison at all. It does now, because the only thing stage 11 can read it
    from is this object.

    Scores come back **positionally**, aligned to ``req.passages``. Several ranking APIs reply
    with an index-and-score list sorted by score rather than by input order; re-aligning that
    is the adapter's job, and getting it wrong produces perfectly plausible floats attached
    to the wrong passages — an answer citing a chunk that scored well only because it was
    third in the request.
    """

    name: str

    async def rerank(self, req: RerankRequest) -> RerankResult: ...


@dataclass(frozen=True, slots=True)
class Scored:
    """A candidate, its reranker score, and the scale **the provider said that score is on**.

    The scale is a field rather than a module constant because it is no longer a property of
    this repository. Carrying it here means a score physically cannot travel without it, so
    the comparison in stage 12 can assert instead of assume.

    **It is populated from ``RerankResult.scale`` and never from ``RerankCalibration.scale``.**
    Populating it from the calibration is the shape that reads correctly and asserts nothing:
    stage 12 then compares ``calibration.scale`` with a copy of ``calibration.scale``, which
    can never disagree, while the vendor's actual claim is discarded on the way past. The
    assertion exists to catch a provider or model swap that moved the scale under a threshold
    nobody re-derived — and that is a disagreement *between* the two sources, so both have to
    survive to the comparison.

    A provider reporting ``RerankScale.UNCALIBRATED`` therefore fails that comparison rather
    than passing it, because no calibration can carry that scale (``RerankCalibration``
    refuses to be constructed with it). That is the intended outcome and not a special case.

    Do not do arithmetic on the score — weighting, averaging, or fusing it with anything
    else. On a bounded scale the transform saturates, so a perfect match and a merely good
    one both land near the top and become indistinguishable; on a logit scale the numbers
    are comparable within one query and not across queries. If arithmetic is ever needed, do
    it on the raw provider value and convert once at the end.
    """

    candidate: Candidate
    score: float
    scale: RerankScale


@dataclass(frozen=True, slots=True)
class RerankOutcome:
    """Stage 11's result: scored, or skipped with a reason. Never both, never neither.

    Two populated fields and an invariant rather than two return types, because the caller
    must handle both branches and a union invites an ``isinstance`` ladder that silently
    accepts the wrong one. ``unranked`` carries the fused order through the skipped path so
    stage 13 has something to pack; it is deliberately a different field from ``scored`` so
    that no downstream code can read a fused ordering as if it had been reranked.

    ``skipped`` is what makes a degraded run visible. It belongs on the ``kb.retrieval.
    rerank`` span as an attribute and in the retrieval trace row; an answer produced without
    reranking must be distinguishable from one produced with it, months later, in an
    evaluation replay — otherwise a regression hunt compares two different pipelines and
    concludes the corpus changed.
    """

    skipped: RerankSkipReason | None
    calibration: RerankCalibration | None
    scored: tuple[Scored, ...] = ()
    unranked: tuple[Candidate, ...] = ()

    def __post_init__(self) -> None:
        if (self.skipped is None) == (self.calibration is None):
            raise ValueError(
                "an outcome is either skipped (with a reason, no calibration) or applied "
                "(with a calibration, no reason)"
            )
        if self.skipped is not None and self.scored:
            raise ValueError("a skipped rerank produced scores — nothing may have scored them")
        if self.skipped is None and self.unranked:
            raise ValueError(
                "an applied rerank carried candidates in `unranked` — the fused order is "
                "not an output of a run that reranked"
            )

    @property
    def applied(self) -> bool:
        return self.skipped is None


def rerank_gate(
    *,
    enabled: bool,
    provider_supports: bool,
    provider_publishes_endpoint: bool,
    provider_scale: RerankScale,
    model: str | None,
    remaining_seconds: float,
) -> RerankSkipReason | None:
    """Decide, before any call, whether stage 11 runs. ``None`` means run it.

    The order of the checks is the order of the causes, most deliberate first: an
    administrator's switch outranks a vendor's capability, which outranks this platform's
    ability to consume that vendor's scores, which outranks a missing model, which outranks
    the clock. Reported the other way round, a bot with reranking switched off on a provider
    that could not rerank anyway would be attributed to load, and the graph would show
    pressure that does not exist.

    **Three provider-shaped arguments, and the redundancy is the point.** Each is exactly one
    public lookup in ``app/providers/capabilities.py``, so no caller re-derives an axis:

    * ``provider_supports`` — ``can_rerank(provider, caps)``, the AND of all three eligibility
      axes. Read from the model's capability row, never inferred from the provider name or the
      model id string, the same rule the chat capability flags follow and for the same reason:
      ids get retired and rows do not.
    * ``provider_publishes_endpoint`` — ``provider_offers(provider, ProviderSurface.RERANK)``,
      axis 1 alone: does the *vendor* document a ranking route.
    * ``provider_scale`` — ``rerank_scale(provider)``, axis 3 alone: can this platform cut
      against what comes back.

    A single boolean cannot say *why* it is ``False``, and the two whys have opposite remedies
    and opposite audiences. Axes 1 and 3 are therefore passed alongside the AND purely so a
    ``False`` can be attributed. Keeping ``provider_supports`` as the AND, rather than
    decomposing it here, is deliberate: ``can_rerank`` is the one place eligibility is decided,
    and a gate that recomputed it from parts would be a second decision procedure that can
    disagree with the first.

    **It never raises, on any input, including an incoherent one.** ``RerankSkipReason`` is
    closed and failure-free by design; a gate that could raise would let an exception be caught
    and filed as ``PROVIDER_LACKS_CAPABILITY``, which is an incident laundered into a
    configuration. So there is no coherence assertion across the three arguments — every
    combination, consistent or not, falls out of the ladder below with a verdict.

    **One honest imprecision, stated rather than hidden.** When the vendor publishes the
    endpoint, the row does *not* claim the capability, *and* the scale is uncharacterized, this
    reports ``PROVIDER_SCALE_UNCALIBRATED`` — attributing to the scale a ``False`` the row also
    caused. The only provider that combination is reachable on is OpenRouter, and there the
    scale is the terminal blocker anyway: a row edited to claim the capability is not refused
    anywhere — nothing calls ``assert_row_coherent`` on a write path (finding J1) — but
    ``can_rerank`` still answers ``False`` on it, so the outcome does not change. So the
    reported reason is the one the operator can act on.
    Distinguishing the corner properly would need axis 2 passed separately as well,
    which is a fourth provider argument bought for a case whose answer would not change.
    """
    if not enabled:
        return RerankSkipReason.DISABLED_BY_CONFIGURATION
    if not provider_publishes_endpoint:
        # The vendor documents no ranking route. True of OpenAI, Anthropic and DeepSeek, and
        # checked before the scale because every one of them is also absent from RERANK_SCALE:
        # read the other way round, three vendors with no endpoint at all would be reported as
        # a calibration gap, and somebody would go looking for the evaluation run that fills it.
        return RerankSkipReason.PROVIDER_LACKS_CAPABILITY
    if not provider_supports:
        # The vendor publishes it and eligibility still failed, so it was axis 2 (the row does
        # not claim the capability) or axis 3 (nobody has characterized the scale).
        if not provider_scale.may_threshold:
            return RerankSkipReason.PROVIDER_SCALE_UNCALIBRATED
        return RerankSkipReason.PROVIDER_LACKS_CAPABILITY
    if not model:
        return RerankSkipReason.MODEL_NOT_CONFIGURED
    if remaining_seconds < RERANK_MIN_USEFUL_SECONDS:
        return RerankSkipReason.INSUFFICIENT_DEADLINE
    return None


def calibration_for(provider: str, model: str, *, provider_scale: RerankScale) -> RerankCalibration:
    """The evaluated threshold for a reranker, or a refusal to guess one. **Three refusals.**

    Called when a bot's retrieval configuration is resolved, so an uncalibrated model is
    rejected at configuration time with a name attached, rather than on a request that then
    has to invent a threshold or silently skip a stage the operator believes is running.

    ``provider_scale`` is what ``capabilities.rerank_scale(provider)`` says this vendor's rerank
    scores mean. It is a **required keyword argument rather than an import**, matching
    ``rerank_gate(provider_supports=...)`` one function up: the capability matrix is the
    provider layer's, ``app/rag/`` asks it questions through its caller, and the direction
    ``app/providers`` → ``app/rag`` must not open. Required and without a default, because an
    optional guard is not a guard — a caller that omitted it would get exactly the behaviour
    this function was changed to stop.

    The three refusals, in order, and the order is the order of the causes:

    1. **The scale may never be thresholded** (``UNCALIBRATED``). Terminal. No evaluation run
       fixes it and ``RerankCalibration`` cannot be constructed with it, so the remedy is to run
       the bot without reranking rather than to go and measure something. Checked first
       precisely because the message for the *second* refusal — "derive it from an evaluation
       run" — is advice this caller can never follow, and following it costs a corpus run and
       ends at ``RerankCalibration.__post_init__`` with a different error.
    2. **No entry for this pair.** Waiting on an evaluation run, which is the ordinary state
       today because ``CALIBRATIONS`` is empty on purpose.
    3. **The entry disagrees with the provider's own row about the scale.** A configuration-time
       ``RerankScaleMismatch``, and the point of catching it here is what it costs to catch it
       anywhere else: stage 12 sees the same disagreement, but only after a tenant's passages
       have been shipped to a vendor and billed, on a request that then errors. This check
       cannot replace stage 12's — that one compares against what the vendor reported on the
       response, which is the only way to see a vendor moving its scale — and stage 12's cannot
       replace this one, because a wrong entry is knowable before anything is spent.
    """
    if not provider_scale.may_threshold:
        raise RerankNotCalibrated(
            f"{provider}/{model} reports rerank scores on the {provider_scale.value} scale, "
            "which may never be thresholded — so no evaluation run can produce a calibration "
            "for it, and RerankCalibration refuses to be constructed with it. This is not 'not "
            "calibrated yet': a gateway fronting several upstream cross-encoders on one "
            "credential documents no normalization across them, so there is no single "
            "distribution for a percentile to be read off. Run this bot with reranking "
            "disabled — the fused order and branch agreement are select_unranked's job — or "
            "move it to a provider whose scale is characterized"
        )
    try:
        calibration = CALIBRATIONS[provider, model]
    except KeyError:
        raise RerankNotCalibrated(
            f"no evaluated threshold for {provider}/{model}. A threshold is a function of "
            "(provider, model, scale, chunker version) and is not portable — derive it from "
            "an evaluation run and add it to CALIBRATIONS"
        ) from None
    if calibration.scale is not provider_scale:
        raise RerankScaleMismatch(
            f"the calibration for {provider}/{model} was derived on "
            f"{calibration.scale.value} by {calibration.derived_from}, but the provider's own "
            f"capability row reports {provider_scale.value}. Caught at configuration time "
            "because the alternative is catching it in stage 12 — after a tenant's passages "
            "have been shipped to a vendor and billed, on a request that then errors. "
            "Re-derive the calibration on the scale the provider actually returns"
        )
    return calibration


async def rerank(
    reranker: Reranker,
    calibration: RerankCalibration,
    ctx: TenantContext,
    query: str,
    candidates: Sequence[Candidate],
    passages: Mapping[str, str],
    run: StageRun,
    *,
    trace_id: str,
    provider_connection_id: str,
    max_candidates: int,
) -> RerankOutcome:
    """Stage 11, when the gate said run. Score the top ``max_candidates``, ordered by score.

    ``ctx``, ``trace_id`` and ``provider_connection_id`` are here because this stage builds a
    ``RerankRequest`` and that object requires all three. They are positional or required
    keywords for the same reason ``tenant_filter``'s arguments are: a provider call that cannot
    name its organization cannot be scoped, metered or traced, and reranking sends a tenant's
    document text to a vendor and bills that tenant's quota for it. None of them is a
    credential — the credential is bound into ``reranker`` by the provider layer and never
    reaches this module.

    ``passages`` maps chunk id to the **exact string that was embedded**, heading prefix
    included, hydrated from PostgreSQL — the chunk body is not in the Qdrant payload
    (ADR-010). Scoring the raw body instead judges a document that does not exist in the
    index. That hydration is a tenant-scoped read like any other: a fetch keyed only on
    chunk ids is a cross-tenant read that reviews as one harmless line.

    Candidates beyond the cutoff are dropped with ``below_rerank_candidate_cutoff``; a
    reranker cannot recover recall stages 7 and 8 never had, so candidate depth is the first
    tuning experiment, not the first thing to cut. Candidates with no hydrated passage are
    dropped and recorded too — never scored against an empty string, which returns a real
    number that ranks a missing passage against present ones.

    Every returned ``Scored`` carries **``RerankResult.scale``** — the scale the provider
    reported on this response — and never ``calibration.scale``. ``calibration`` is an argument
    here only so the candidate cutoff and the passage ceiling can be honoured; the number it
    carries is stage 12's business, and copying its scale onto the results is what turned that
    stage's assertion into a comparison of a value with itself.

    **This stage does not re-check the scale after the call, and that is a decision rather than
    an omission.** An adapter whose scores are ``UNCALIBRATED`` cannot reach here through a
    calibration — ``RerankCalibration`` refuses to be constructed with that scale, and
    ``calibration_for`` refuses the pair outright — so the only way the disagreement appears at
    this point is a vendor that moved its scale under an unchanged threshold. That is precisely
    what stage 12 asserts on, and catching it one line earlier here would save nothing: the
    round-trip has already been made and billed. What is decidable before the money is spent is
    decided in ``calibration_for``; what is only observable afterwards is stage 12's.

    Records ``kb.retrieval.rerank``, with the candidate count and the provider request count
    on it — the second is what shows an unbatched loop turning one stage into N round-trips.

    **This body is written and is not reachable today.** There is no concrete ``RerankAdapter``
    — every vendor module's ``rerank`` raises — nothing binds a ``Reranker`` to an
    organization's connection, and ``CALIBRATIONS`` is empty on purpose, so ``calibration_for``
    refuses before a caller ever gets here. It is written anyway because the one thing it must
    get right is a provenance decision (below) that is invisible in a stub and impossible to
    test after the fact.
    """
    scoreable: list[Candidate] = []
    for position, candidate in enumerate(candidates, start=1):
        if position > max_candidates:
            run.exclude(candidate.chunk_id, ExclusionReason.BELOW_RERANK_CANDIDATE_CUTOFF)
            continue
        if not passages.get(candidate.chunk_id):
            # Un-hydrated: the chunk body lives in PostgreSQL (ADR-010) and this one did not
            # come back. Never scored against an empty string, which returns a real number that
            # ranks a missing passage against present ones. Recorded under the cutoff reason
            # because `ExclusionReason` is a closed contract with no member for it — see the
            # note in the report; a new member is a contract change, not a label.
            run.exclude(candidate.chunk_id, ExclusionReason.BELOW_RERANK_CANDIDATE_CUTOFF)
            continue
        scoreable.append(candidate)

    if not scoreable:
        # An applied outcome with nothing scored, not a skip. Stage 12 then returns [] and the
        # bot refuses, which is the truthful account: the reranker was available and there was
        # nothing to give it. `RerankRequest.passages` has `min_length=1`, so the alternative
        # is a validation error on the way to a vendor for a call with no work in it.
        return RerankOutcome(skipped=None, calibration=calibration, scored=())

    with _TRACER.start_as_current_span(span_for("reranking")) as span:
        request = RerankRequest(
            org_id=ctx.org_id,
            trace_id=trace_id,
            provider_connection_id=provider_connection_id,
            model=calibration.model,
            query=query,
            passages=[passages[candidate.chunk_id] for candidate in scoreable],
        )
        result = await reranker.rerank(request)
        span.set_attribute("kb.rerank.candidates", len(scoreable))
        # One today, because the adapter owns batching and concurrency. It is on the span so an
        # unbatched loop turning one stage into N round-trips is visible as a number rather
        # than as a latency graph nobody can attribute.
        span.set_attribute("kb.rerank.provider_requests", 1)

    scored = tuple(
        # `RerankResult.scale` — what the provider said this response's numbers are on — and
        # never `calibration.scale`. Sourcing it from the calibration makes stage 12's
        # assertion compare `calibration.scale` with a copy of `calibration.scale`, which can
        # never disagree, while the vendor's own claim is discarded on the way past. The
        # disagreement that assertion exists to catch is precisely BETWEEN those two sources,
        # so both have to survive to the comparison. Finding #48.
        Scored(candidate=candidate, score=score, scale=result.scale)
        # `strict=True` is the alignment check. Scores come back positionally, aligned to
        # `req.passages`; a vendor that returned fewer entries than it was given is a failure
        # rather than a partial answer, because silently dropped passages become evidence that
        # vanished and the answer that follows is grounded in a subset nobody chose.
        for candidate, score in zip(scoreable, result.scores, strict=True)
    )
    return RerankOutcome(
        skipped=None,
        calibration=calibration,
        scored=tuple(sorted(scored, key=lambda entry: entry.score, reverse=True)),
    )


# Checked at import: a calibration table that has drifted must not reach a running process,
# because every threshold it carries still compares cleanly against every score.
assert all(key == (cal.provider, cal.model) for key, cal in CALIBRATIONS.items())
assert all(cal.scale.may_threshold for cal in CALIBRATIONS.values())
assert RERANK_MIN_USEFUL_SECONDS > 0

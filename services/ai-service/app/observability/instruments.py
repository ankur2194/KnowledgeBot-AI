"""Every metric instrument this service owns, created at module import.

THE CATALOG IS CLOSED. Each instrument below maps to exactly one row in
``kb-observability-conventions/references/metric-catalog.md``, and the Prometheus name that row
promises is written above it. A name that is not in the catalog does not exist: the CI diff
scrapes the Collector's Prometheus exporter and fails on any family it cannot find. Adding a
metric is a catalog pull request FIRST and an instrument here second, never the other way round.

WHY EVERY INSTRUMENT IS CREATED HERE, AT IMPORT, AND NEVER INSIDE A HANDLER
Three separate failures collapse into one rule:

* An instrument created lazily emits no series until the first request touches its code path, so
  the CI catalog diff passes against a freshly booted service that exposes almost nothing. The
  gate is then green and checking nothing, which is worse than absent.
* An instrument created inside a request handler is recreated per request. Duplicate registration
  is not an error in the SDK; it is a slow leak and a stream of half-populated series.
* Dashboards and alerts query names that have to exist before traffic does. An alert cannot fire
  on a series that has never been written, which is the same trap that makes the scheduler's tick
  gauge a seeded PostgreSQL row rather than an event.

Creating instruments before ``otel.configure()`` runs is safe: the OTel API returns proxy
instruments that re-bind when the real ``MeterProvider`` is installed. That is what makes
"instruments at import, providers after ``fork()``" work in the Celery workers.

UNITS ARE DECLARED ON THE INSTRUMENT AND NEVER WRITTEN INTO THE NAME. ``kb.chat.duration`` with
``unit="s"`` exports as ``kb_chat_duration_seconds``. The OTLP-to-Prometheus rule only *SHOULD*
skip a suffix a name already carries, so spelling it yourself is how ``..._seconds_seconds``
happens. Two specific traps in the other direction are marked at their instruments below: a GAUGE
with ``unit="1"`` gains a ``_ratio`` suffix, and a non-UCUM unit string such as ``USD`` is
appended verbatim.

LABELS ARE AN ALLOW-LIST AND A CARDINALITY RULE, AND THE CARDINALITY RULE WINS. ``org_id``,
``bot_id``, ``user_id``, ``conversation_id``, ``message_id``, ``source_id``, ``job_id``,
``chunk_id``, ``url`` and any user question, query or filename are banned outright. Series count
is the product of every label's cardinality, and a histogram multiplies that again by
``buckets + 2``; the guardrail Prometheus offers is ``sample_limit``, which is a cliff rather
than a throttle — exceed it and the ENTIRE scrape fails, so one bad deployment removes a job's
metrics instead of degrading them, and reverting does not bring back the retention window.
Per-tenant numbers come from PostgreSQL. Per-request detail comes from traces and logs.
"""

from __future__ import annotations

import os
from collections.abc import Iterable, Mapping
from typing import Any, Final

from opentelemetry import metrics
from opentelemetry.metrics import CallbackOptions, Observation

__all__ = [
    "ALLOWED_LABELS",
    "BANNED_LABELS",
    "assert_allowed_labels",
    "meter",
]

meter = metrics.get_meter("app.observability")


# =================================================================================================
# LABEL ALLOW-LIST
# =================================================================================================
# The union of the "Extra labels" column of the catalog, plus the two base labels every family
# carries. The allow-list and that column are ONE artifact: a label added to a catalog row is
# added here in the same change, or the unit test that compares them fails.
#
# The list being closed is not the whole rule. A proposed label whose values cannot be enumerated
# is refused rather than appended — that outranks membership. `model` is bounded only because an
# unrecognised string folds to `other` BEFORE the instrument, never in PromQL: a tenant can type
# anything into a provider connection.
ALLOWED_LABELS: Final[frozenset[str]] = frozenset(
    {
        # base, on every family
        "service",
        "env",
        # request shape
        "operation",
        "outcome",
        "disposition",
        "error_class",
        "status_class",
        "finish_reason",
        # providers and models
        "provider",
        "model",
        "from_model",
        "to_model",
        "token_type",
        # pipeline
        "stage",
        "reason",
        # `kb_retrieval_evidence_score` ONLY. Four values, from app.providers.contract.RerankScale,
        # of which three are reachable here: no calibration can carry `uncalibrated`, so a score on
        # that scale fails stage 12 rather than being recorded. Bounded, enumerable from a StrEnum
        # in this repository, and load-bearing rather than descriptive — see the instrument.
        "scale",
        "kind",
        "engine",
        "file_type",
        "queue",
        "collection",
        "task",
        # platform
        "dependency",
        "required",
        # kb_build_info ONLY — the _info pattern, one series per running build
        "version",
        "git_sha",
        "contract_version",
    }
)

#: Named individually so the failure message says which rule was broken rather than "not in list".
#: Every one of these is a real thing somebody has tried to add to a metric for a dashboard.
BANNED_LABELS: Final[frozenset[str]] = frozenset(
    {
        "org_id",
        "organization_id",
        "bot_id",
        "user_id",
        "conversation_id",
        "message_id",
        "source_id",
        "job_id",
        "chunk_id",
        "request_id",
        "trace_id",
        "url",
        "host",
        "path",
        "query",
        "filename",
        "upstream_slug",
    }
)


def assert_allowed_labels(attributes: Mapping[str, Any] | None) -> None:
    """Raise if an attribute set contains a label that may not be a metric label.

    Called from tests and from the debug path, not from every ``add()`` — a dictionary scan on
    the hot path buys nothing that a test cannot prove once. The defence is that the allow-list
    exists and is asserted; the Collector's attribute filter is the backstop.

    ``org_id`` and ``bot_id`` are perfectly legitimate in a LOG LINE and in a SPAN ATTRIBUTE, and
    that asymmetry is the whole point: logs and traces are what make a tenant incident
    debuggable. A metric answers whether the system is healthy, never what one organization did.
    """
    if not attributes:
        return
    for key in attributes:
        if key in BANNED_LABELS:
            raise ValueError(
                f"{key!r} may never be a metric label: its value set is unbounded or tenant-"
                f"identifying. It belongs in a log field or a span attribute. Per-tenant numbers "
                f"come from the usage table and the analytics aggregates."
            )
        if key not in ALLOWED_LABELS:
            raise ValueError(
                f"{key!r} is not in the label allow-list. Add it to the catalog row and to "
                f"ALLOWED_LABELS in the same change, or do not add it."
            )


# =================================================================================================
# HISTOGRAM BUCKETS — one set per unit class, defined once and shared
# =================================================================================================
# Shared so PromQL can add histograms together. A quantile above the largest finite bucket returns
# +Inf, which is why the user-visible set puts an edge at 4 s and 60 s rather than trusting a
# default set that stops at 10.
#
# A histogram costs ``buckets + 2`` series per label combination. The user-visible set is 11
# buckets, so a first-token histogram across 20 models is 260 series — which is the entire reason
# ``model`` is drawn from the pinned catalog and not from a tenant-typed string.
#
# These are explicit-bucket (classic) histograms on purpose: OTLP explicit-bucket histograms map
# directly onto Prometheus classic histograms, and native histograms are still experimental.

#: Sub-request latency: retrieval stages, embedding batches, vector upserts, internal calls.
BUCKETS_SUBREQUEST: Final[tuple[float, ...]] = (
    0.005,
    0.01,
    0.025,
    0.05,
    0.1,
    0.25,
    0.5,
    1,
    2.5,
    5,
    10,
)

#: User-visible latency: chat duration, first token, provider duration. 4 is an edge because
#: docs/17 §23 targets it.
BUCKETS_USER_VISIBLE: Final[tuple[float, ...]] = (
    0.1,
    0.25,
    0.5,
    1,
    2,
    4,
    6,
    10,
    15,
    30,
    60,
)

#: Job duration: ingestion stages, crawl runs.
BUCKETS_JOB: Final[tuple[float, ...]] = (1, 5, 15, 30, 60, 120, 300, 600, 900)

#: Counts: candidates, evidence selected.
BUCKETS_COUNT: Final[tuple[float, ...]] = (1, 2, 5, 10, 20, 50, 100, 200)

#: Reranker score. A UNION OF TWO RANGES over the domain a histogram can actually hold.
#:
#: It has to be one set because explicit bucket boundaries are a property of the INSTRUMENT: no
#: OTel view can vary them per attribute value, so one ``kb.retrieval.evidence_score`` cannot
#: carry one edge list for an unbounded logit and another for a bounded 0–1 relevance score. Since
#: ADR-030 the scale is a property of the ``(provider, model)`` pair rather than of this
#: repository — NVIDIA's ranking models return a logit sitting roughly ±10 and centred near zero,
#: a Cohere-shaped API returns a bounded score — and it can differ per organization, so there is
#: no single range to pin and no future commit that will pin one. The union gives each scale its
#: own sub-range: ``0 … 1`` for ``sigmoid`` and ``unit_interval``, and out to ``10`` for the
#: positive half of a ``logit``. That is only safe because the ``scale`` label separates them, and
#: a query that aggregates it away produces a number with no meaning.
#:
#: THE SET STOPS AT ZERO, AND NOT BECAUSE A NEGATIVE SCORE IS UNINTERESTING.
#: ``opentelemetry-sdk`` 1.44.0 ``Histogram.record()`` **discards any negative amount** — it logs
#: ``"Record amount must be non-negative on Histogram %s."`` at WARNING and returns without
#: consuming the measurement (`sdk/metrics/_internal/instrument.py`). The aggregation accepts
#: negative bucket edges perfectly happily, so a set spanning ``-10 … 10`` looks correct, exports
#: correctly, and receives nothing below zero: the negative half of every logit distribution is
#: dropped, the surviving half reads as the whole, and the p10 panel of "weakest accepted
#: evidence" reports a number biased upward by exactly the samples that were weakest. Nothing
#: errors. That is the failure this catalog exists to prevent, so it is stated at the edge list
#: rather than discovered from a Grafana panel.
#:
#: The consequence is a REAL GAP and not a rounding of one. **Two of the five vendors rerank — NIM
#: and OpenRouter — and only NIM's scores may ever be cut against.** (`app/providers/nim.py:127`;
#: the eligibility axis is `capabilities.py:634`. The narrower claim that only NIM *publishes* a
#: ranking endpoint is false — `capabilities.py:521-523` has OpenRouter's rerank cell SUPPORTED
#: and `tests/unit/test_provider_capability_matrix.py:146` pins
#: `providers_offering(RERANK) == {"nvidia_nim", "openrouter"}`.) So every score that reaches this
#: histogram is on NIM's ``LOGIT`` scale, and roughly half a logit distribution is negative. See
#: the instrument below for what a call site must therefore do.
BUCKETS_RERANK_SCORE: Final[tuple[float, ...]] = (
    0,
    0.1,
    0.25,
    0.5,
    0.75,
    0.9,
    1,
    2,
    5,
    10,
)


# =================================================================================================
# CHAT — kb_chat_*
# =================================================================================================

#: kb_chat_requests_total {operation, outcome, error_class}
#: RPM, error rate, timeout rate AND cancellation rate — four §20.2 bullets from one counter.
#: ``outcome`` has four values here and only ``cancelled`` is legitimately outside the error rate.
#: ``timeout`` is separate from ``error`` because the taxonomy has no timeout class, and that is
#: the reason the error rate matches ``outcome=~"error|timeout"`` rather than the plain-equality
#: form everybody writes first, which silently omits every timed-out request. One recording rule
#: in infrastructure/observability/prometheus/rules/kb-recording.yml owns that matcher; nothing
#: else in the repository may restate it.
CHAT_REQUESTS = meter.create_counter(
    "kb.chat.requests",
    description="Chat requests by operation, outcome and error class.",
)

#: kb_chat_duration_seconds {outcome}
#: Split by outcome so a fast failure cannot flatter p95.
CHAT_DURATION = meter.create_histogram(
    "kb.chat.duration",
    unit="s",
    description="End-to-end chat request duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_USER_VISIBLE),
)

#: kb_chat_answers_total {finish_reason}
#: Insufficient-evidence and truncation rates. Values are the SSE terminal finish_reason enum.
CHAT_ANSWERS = meter.create_counter(
    "kb.chat.answers",
    description="Completed answers by terminal finish reason.",
)

#: kb_chat_active_streams (gauge, no extra labels)
#: An UpDownCounter, not a Counter: it is non-monotonic, so the exporter renders it as a gauge and
#: does NOT append ``_total``. A monotonic counter would export the name with a ``_total`` suffix
#: and match nothing in any dashboard. A monotonic climb in this series is a leaked finalizer —
#: a stream span or generator not closed in a ``finally`` — not organic growth.
CHAT_ACTIVE_STREAMS = meter.create_up_down_counter(
    "kb.chat.active_streams",
    description="SSE streams currently open.",
)

#: kb_chat_fallbacks_total {from_model, to_model, error_class}
#: A silent fallback looks like the primary model produced the answer, and the eval suite then
#: scores the wrong model. Every fallback and every degradation is recorded.
CHAT_FALLBACKS = meter.create_counter(
    "kb.chat.fallbacks",
    description="Model fallbacks, with the class that triggered them.",
)


# =================================================================================================
# RETRIEVAL — kb_retrieval_*
# =================================================================================================

#: kb_retrieval_duration_seconds {stage}
#: The 1.5 s budget, per stage: embed, dense, sparse, fuse, dedupe, rerank, pack.
RETRIEVAL_DURATION = meter.create_histogram(
    "kb.retrieval.duration",
    unit="s",
    description="Retrieval stage duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_SUBREQUEST),
)

#: kb_retrieval_empty_total {reason}
#: reason is a closed FOUR-value enum: no_match, below_threshold, filtered, no_branch_agreement.
#: Empty retrieval produces a refusal, never an invention, so this is the leading indicator for
#: the refusal rate.
#:
#: The fourth value arrived with ADR-030 and is reachable only on the degraded path. A run that
#: skipped stage 11 has no score, therefore no threshold, and selects on branch agreement instead
#: — so recording its empty result as ``below_threshold`` would assert that a threshold ran, and
#: the refusal would be read as a relevance problem in a corpus rather than as a capability gap in
#: one organization's provider. Pairs with kb_retrieval_rerank_skips_total below: a rise here in
#: `no_branch_agreement` with a matching rise there in `provider_lacks_capability` is one bot's
#: configuration, not a retrieval regression.
RETRIEVAL_EMPTY = meter.create_counter(
    "kb.retrieval.empty",
    description="Retrievals that returned no usable evidence, by reason.",
)

#: kb_retrieval_candidates {stage}
#: unit="1" on a HISTOGRAM adds no suffix. The same unit on a GAUGE would add ``_ratio`` — that
#: rule is type-dependent and is the reason the platform gauges further down carry no unit at all.
RETRIEVAL_CANDIDATES = meter.create_histogram(
    "kb.retrieval.candidates",
    unit="1",
    description="Candidates entering each retrieval stage.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_COUNT),
)

#: kb_retrieval_evidence_selected (no extra labels)
RETRIEVAL_EVIDENCE_SELECTED = meter.create_histogram(
    "kb.retrieval.evidence_selected",
    unit="1",
    description="Evidence chunks selected after thresholding.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_COUNT),
)

#: kb_retrieval_evidence_score {scale}
#: The mean is ``_sum / _count``; the buckets are there to show what the mean hides. A bimodal
#: split of excellent and useless evidence averages to the same number as uniform mediocrity.
#:
#: ``scale`` IS MANDATORY ON EVERY RECORDING AND ON EVERY QUERY. It used to be absent, with a note
#: saying the bucket edges were a placeholder "until that scale is pinned" — and ADR-030 is what
#: makes that wait permanent: the scale is a property of the ``(provider, model)`` pair, it can
#: differ per organization, and one histogram fed from a bounded 0–1 relevance score and from an
#: unbounded logit mixes two distributions that are not comparable. ``0.30`` is a valid float on
#: both and means roughly opposite things, so the mixture is not noisy, it is wrong, and nothing
#: raises. This label is the metric-layer counterpart of ``Scored.scale``, which exists to stop
#: exactly this one layer down; the values are ``app.providers.contract.RerankScale``.
#:
#: ``uncalibrated`` is in the enum and unreachable here: ``RerankCalibration`` refuses to be
#: constructed with it, so a score claiming that scale fails stage 12 as an error rather than
#: arriving as a sample. Three values in practice, four at most.
#:
#: There is also no sample at all on the degraded path — a run that skipped stage 11 has no score
#: to record — which is why the skip counter below, and not this histogram, is where a capability
#: gap becomes visible. Reading a flat count here as "quality is stable" is the trap.
#:
#: A CALL SITE MUST NEVER RECORD A NEGATIVE VALUE HERE, AND ON ``scale="logit"`` MUST RECORD
#: NOTHING AT ALL. The SDK silently drops negatives (see BUCKETS_RERANK_SCORE), so recording a
#: logit's positive half is strictly worse than recording none of it: a truncated distribution is
#: indistinguishable from a healthy one and moves the p10 panel in the reassuring direction.
#:
#: RULED 2026-08-12 (`docs/22` § G18), AND THE RULING IS "RECORD NOTHING", NOT "NOT YET DECIDED".
#: This carried a TODO addressed to `retrieval-engineer` and `rag-eval-engineer` asking how a logit
#: distribution should be carried. It is closed as: **logit-scale scores are deliberately not
#: recorded on this histogram, and logit evidence quality is read from the evaluation suite.**
#: The consequence is stated plainly rather than softened — today NVIDIA NIM is the only
#: rerank-eligible provider and it returns ``LOGIT``, so **this histogram records nothing in the
#: one working configuration.** That is a known, bounded gap in a quality panel, not a gap in a
#: correctness signal: stage 12 still asserts the scale, still thresholds, and still refuses an
#: uncalibrated one, none of which depends on a metric being exported.
#:
#: What was rejected, and why the rejections are worth more than the choice:
#:
#: * **Record σ(score).** Monotone, so it preserves quantiles exactly — σ(p10(x)) == p10(σ(x)).
#:   But it is arithmetic on a provider's score, which `bge-reranker` restricts; it saturates
#:   precisely where the accepted evidence lives, so the interesting end of the distribution is
#:   the end it compresses; and it cannot be relabelled ``scale="sigmoid"`` because that value is
#:   the *provider's own claim* — overwriting it turns stage 12's scale assertion into a
#:   comparison of a value with itself. Carrying it under a fifth ``RerankScale`` member would
#:   mean a closed enum growing a member that describes our arithmetic rather than a vendor's
#:   contract, which is what the enum exists to prevent.
#: * **A second catalogued family** for threshold-relative distance. It would work, and it is a
#:   new metric family — outside the skeleton line drawn on 2026-08-11, and a decision to make
#:   with the panels it is for rather than ahead of them.
#:
#: TWO REVISIT CONDITIONS, BOTH MECHANICAL, so this does not become permanent by inattention:
#: a bounded-scale provider (a Cohere-shaped 0–1 relevance score) becoming rerank-eligible, at
#: which point the histogram starts carrying real samples and the gap is only about logit; or an
#: ``opentelemetry-sdk`` release whose ``Histogram.record()`` accepts negative amounts, at which
#: point ``BUCKETS_RERANK_SCORE`` extends below zero and the whole question dissolves. Check the
#: second at every SDK bump — it is a one-line test against ``Histogram.record(-1)``.
RETRIEVAL_EVIDENCE_SCORE = meter.create_histogram(
    "kb.retrieval.evidence_score",
    unit="1",
    description="Score of evidence accepted past the threshold, by the scale it is on.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_RERANK_SCORE),
)

#: kb_retrieval_rerank_skips_total {reason}
#: Stage 11 is CAPABILITY-GATED since ADR-030: TWO of the five configured vendors publish a ranking
#: endpoint — NVIDIA NIM and OpenRouter — and only NIM's scores may ever be cut against, so a bot
#: on OpenAI (no endpoint) and a bot on OpenRouter (an endpoint whose scale nobody has
#: characterized) both serve the RRF-fused order and both rank measurably worse than the same bot
#: on NIM. That is correct behaviour and it must be VISIBLE, or the gap reads as a defect in the
#: retrieval stack and gets debugged as one for weeks.
#:
#: ``reason`` is ``app.rag.rerank.RerankSkipReason``, closed at FIVE and DELIBERATELY FAILURE-FREE:
#: provider_lacks_capability, provider_scale_uncalibrated, model_not_configured,
#: disabled_by_configuration, insufficient_deadline. Every one is decidable before the call goes
#: out. There is no member for "the provider errored", "the call timed out" or "the response did
#: not parse" — those are errors with their own ``error_class`` on kb_provider_requests_total, and
#: an outage laundered into a skip degrades ranking AND hides an incident.
#:
#: THE FIFTH VALUE IS NEW AND THE TWO CAPABILITY REASONS MUST NOT BE READ AS ONE (finding #47).
#: ``provider_lacks_capability`` means the VENDOR publishes no ranking route; the remedy is a
#: different provider. ``provider_scale_uncalibrated`` means the vendor does publish one and THIS
#: PLATFORM cannot threshold what comes back — OpenRouter, a gateway fronting several upstream
#: cross-encoders on one credential with no documented normalization — and no evaluation run fixes
#: it, so the remedy is to run the bot with reranking off. A panel that sums the two together
#: reports a vendor limitation for a platform one, which is the debugging session this counter
#: exists to prevent.
#:
#: WHY THIS COUNTER AND NOT AN ALERT. A capability gap is permanent, expected, and a property of
#: one organization's provider configuration; alerting on a condition that is expected to be true
#: is how an on-call rotation learns to ignore alerts, and PromQL could not name the tenant anyway
#: — org_id is not a label. So: a counter and a dashboard panel here, and the SQL that identifies
#: which bots in the retrieval runbook. ``insufficient_deadline`` is the one member that moves with
#: load rather than with configuration, and a rise in it means the retrieval leg is being squeezed
#: upstream — answers are quietly getting worse before anything else notices. It is a dashboard
#: signal today; making it an alert needs a floor and a baseline nobody has measured yet.
RETRIEVAL_RERANK_SKIPS = meter.create_counter(
    "kb.retrieval.rerank_skips",
    description="Reranking runs that were skipped before the call, by reason. Never an error.",
)


# =================================================================================================
# PROVIDERS — kb_provider_*
# =================================================================================================

#: kb_provider_requests_total {provider, model, outcome, error_class}
#: Request count, success rate, rate-limit rate and timeout rate — four §20.2 bullets, one
#: counter. ``model`` is folded to ``other`` for anything outside the pinned catalog BEFORE it
#: reaches this instrument.
PROVIDER_REQUESTS = meter.create_counter(
    "kb.provider.requests",
    description="Provider calls by provider, model, outcome and error class.",
)

#: kb_provider_duration_seconds {provider, model}
PROVIDER_DURATION = meter.create_histogram(
    "kb.provider.duration",
    unit="s",
    description="Provider call duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_USER_VISIBLE),
)

#: kb_provider_first_token_seconds {provider, model}
#: ATTRIBUTION ONLY. Never the headline number and never an SLO: it starts at the provider call,
#: so the retrieval leg the user is also waiting through is invisible to it. Record it on the
#: first chunk carrying NON-EMPTY text — most providers open a stream with an empty role delta or
#: a message_start, and timing those under-reports by design.
PROVIDER_FIRST_TOKEN = meter.create_histogram(
    "kb.provider.first_token",
    unit="s",
    description="Provider-side time to first token. Attribution only.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_USER_VISIBLE),
)

#: kb_provider_tokens_total {provider, model, token_type}
PROVIDER_TOKENS = meter.create_counter(
    "kb.provider.tokens",
    description="Tokens consumed, split into input and output.",
)

#: kb_provider_cost_usd_total {provider, model}
#: NO UNIT, DELIBERATELY. ``USD`` is not a UCUM unit, so the exporter would append it verbatim and
#: produce ``kb_provider_cost_usd_USD_total`` or similar — the currency belongs in the name here,
#: which is exactly what the catalog row says. This is an ESTIMATE and never a business record:
#: the whole telemetry stack is sampled, TTL'd and permitted to fail silently. Billing truth is
#: the usage table in PostgreSQL.
PROVIDER_COST_USD = meter.create_counter(
    "kb.provider.cost_usd",
    description="Estimated provider spend. Not a billing record.",
)


# =================================================================================================
# INGESTION — kb_ingestion_*, kb_embedding_*, kb_vector_*, kb_version_*
# =================================================================================================

#: kb_ingestion_jobs_total {file_type, outcome, error_class}
INGESTION_JOBS = meter.create_counter(
    "kb.ingestion.jobs",
    description="Ingestion jobs by file type, outcome and error class.",
)

#: kb_ingestion_stage_duration_seconds {stage, file_type}
#: 17 stage values: acquire validate hash store parse ocr normalize clean enrich chunk embed
#: sparse upsert verify activate retire invalidate. docs/08 §13.2 walks 18 steps and both counts
#: are right — its step 1 is a control-plane act in Laravel that emits no ingestion stage.
INGESTION_STAGE_DURATION = meter.create_histogram(
    "kb.ingestion.stage_duration",
    unit="s",
    description="Ingestion stage duration by stage and file type.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_JOB),
)

#: kb_ingestion_retries_total {stage, error_class}
#: Climbing against a flat success rate means a permanent error is misfiled as temporary.
INGESTION_RETRIES = meter.create_counter(
    "kb.ingestion.retries",
    description="Stage retries by stage and error class.",
)

#: kb_ingestion_pages_total {file_type}
#: Pages per minute is ``rate(...[5m]) * 60``. A stored pre-divided rate can be neither
#: re-windowed nor summed.
INGESTION_PAGES = meter.create_counter(
    "kb.ingestion.pages",
    description="Document pages processed.",
)

#: kb_ingestion_ocr_pages_total {engine, disposition}
#: ``disposition``, NOT ``outcome``: success / partial / error. A partially-OCR'd page is a real
#: third result, not a failure, so the set is not the shared four and the label must not claim to
#: be — otherwise every one of these samples falls outside the error-rate matcher and the family
#: drops out of the one rule meant to catch it.
INGESTION_OCR_PAGES = meter.create_counter(
    "kb.ingestion.ocr_pages",
    description="OCR pages by engine and disposition.",
)

#: kb_embedding_chunks_total {model, kind}
EMBEDDING_CHUNKS = meter.create_counter(
    "kb.embedding.chunks",
    description="Chunks embedded, dense and sparse.",
)

#: kb_embedding_batch_duration_seconds {model, kind}
#: One instrument for two budgets: the 5 s query-embedding budget on the chat path and the
#: ingestion batch cost. Same operation, different batch sizes.
EMBEDDING_BATCH_DURATION = meter.create_histogram(
    "kb.embedding.batch_duration",
    unit="s",
    description="Embedding batch duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_SUBREQUEST),
)

#: kb_vector_upsert_duration_seconds {collection}
#: ``collection`` is bounded: one per embedding model, shared across tenants. Tenant separation
#: inside a collection is the four mandatory payload filters, never a collection per org.
VECTOR_UPSERT_DURATION = meter.create_histogram(
    "kb.vector.upsert_duration",
    unit="s",
    description="Vector upsert duration by collection.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_SUBREQUEST),
)

#: kb_vector_upsert_points_total {collection, outcome}
VECTOR_UPSERT_POINTS = meter.create_counter(
    "kb.vector.upsert_points",
    description="Vector points written versus failed.",
)

#: kb_version_publications_total {outcome}
#: Active-version pointer switches versus verification failures. A version becomes searchable only
#: after it is fully indexed and verified, and the previous version serves until then — so a
#: failure here is invisible to users and must not be invisible here.
#:
#: NOTE for the catalog: ``version`` is not in the metric-domain list in the conventions SKILL
#: (chat, retrieval, provider, ingestion, embedding, vector, crawl, internal) even though this row
#: is in the catalog. The catalog is the name authority, so the name stands; the domain list needs
#: the addition. Flagged, not fixed here.
VERSION_PUBLICATIONS = meter.create_counter(
    "kb.version.publications",
    description="Source version publications by outcome.",
)


# =================================================================================================
# CRAWL — kb_crawl_*
# =================================================================================================

#: kb_crawl_pages_total {disposition}
#: ``disposition``, NOT ``outcome``: discovered / changed / unchanged / skipped / missing /
#: failed. Two of those are failures outside the shared four, so an ``outcome`` label here would
#: put every crawl failure outside the error-rate matcher and the rate would read healthy while a
#: third of pages fail. Run-level success stays on the runs counter below.
CRAWL_PAGES = meter.create_counter(
    "kb.crawl.pages",
    description="Per-page crawl results by disposition.",
)

#: kb_crawl_runs_total {outcome}
CRAWL_RUNS = meter.create_counter(
    "kb.crawl.runs",
    description="Crawl runs by outcome.",
)

#: kb_crawl_duration_seconds (no extra labels)
CRAWL_DURATION = meter.create_histogram(
    "kb.crawl.duration",
    unit="s",
    description="Crawl run duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_JOB),
)

#: kb_crawl_fetch_duration_seconds (no extra labels)
#: Against the 20 s per-URL timeout.
CRAWL_FETCH_DURATION = meter.create_histogram(
    "kb.crawl.fetch_duration",
    unit="s",
    description="Per-URL fetch duration.",
    explicit_bucket_boundaries_advisory=list(BUCKETS_SUBREQUEST),
)

#: kb_crawl_responses_total {status_class}
#: 2xx / 3xx / 4xx / 5xx / network. NEVER the host and never the URL: a crawl target set is
#: tenant-controlled and therefore unbounded.
CRAWL_RESPONSES = meter.create_counter(
    "kb.crawl.responses",
    description="Crawl HTTP responses by status class.",
)

#: kb_crawl_robots_blocked_total {reason}
#: Eight reasons: robots scheme credentials dns private_ip redirect content_type size. The guard
#: rejects for all eight, and an enum missing one sends those rejections to no counter at all —
#: the SSRF guard then looks quieter than it is.
CRAWL_ROBOTS_BLOCKED = meter.create_counter(
    "kb.crawl.robots_blocked",
    description="URLs rejected by robots.txt or the SSRF guard, by reason.",
)


# =================================================================================================
# CROSS-CUTTING GAUGES — kb_build_info, kb_dependency_up, kb_circuit_breaker_state, kb_queue_depth
# =================================================================================================
# All four are OBSERVABLE gauges: their value is sampled at export time from state that already
# exists, rather than pushed from an event. That is the right shape for "what is true now" and the
# wrong shape for "what happened" — a gauge cannot be rate()'d and a counter cannot be read.
#
# NONE OF THEM DECLARES A UNIT. A gauge with ``unit="1"`` is exported as ``..._ratio``, which for
# ``kb_dependency_up`` would silently produce ``kb_dependency_up_ratio`` and match nothing in the
# Platform dashboard or in KbOptionalDependencyDown. The suffix rule is type-dependent; the
# histograms above pass ``unit="1"`` safely for exactly that reason.
#
# Their callbacks are placeholders. A callback raising an exception would break the whole export
# cycle for the process, so each yields nothing until the state it reads exists — the series is
# simply absent, which is honest. Absence has a real consequence worth stating: the CI catalog
# diff can only prove "no uncatalogued name is exposed", not "every catalogued name is exposed",
# until these callbacks are filled in.


def _observe_build_info(options: CallbackOptions) -> Iterable[Observation]:
    """kb_build_info {version, git_sha, contract_version} — always 1.

    The ``_info`` pattern: the value carries nothing and the labels carry everything, so one
    series exists per running build and is replaced on deployment. ``git_sha`` is the single
    permitted churning label value in the entire catalog, which is precisely why it is confined
    to one always-1 gauge instead of riding along on a family that has real cardinality.

    TODO(platform-devops-engineer): ``KB_VERSION``, ``KB_GIT_SHA`` and ``KB_CONTRACT_VERSION``
    are not in infrastructure/docker/env/ yet. Until they are, this series reports ``unknown``,
    which is visibly wrong on the Platform dashboard rather than quietly absent — the intended
    failure mode for a build identifier.
    """
    yield Observation(
        1,
        {
            "version": os.getenv("KB_VERSION", "unknown"),
            "git_sha": os.getenv("KB_GIT_SHA", "unknown"),
            "contract_version": os.getenv("KB_CONTRACT_VERSION", "unknown"),
        },
    )


def _observe_dependency_up(options: CallbackOptions) -> Iterable[Observation]:
    """kb_dependency_up {dependency, required} — 0 or 1, straight from the readiness decision.

    ``dependency`` is the fixed SIX: postgres, valkey, qdrant, object_storage, ai_api, core_api.
    A per-org provider credential is NOT a dependency value — breaker state per
    (org, credential, model) is per-tenant data and belongs in PostgreSQL, surfaced on the admin
    dashboard.

    IT WAS EIGHT. ``embedding`` and ``reranker`` were dependency values while both ran as local
    models in this process, where a readiness probe could answer for them. ADR-030 made both
    provider API calls, and app/api/health.py states the rule they now fall under: readiness must
    never probe an external provider. So nothing can produce those two series — not this callback,
    which samples the readiness cache, and not the breaker gauge below, whose surviving embedding
    and rerank breakers are keyed per (org, credential, model) and are banned from this label. They
    are removed rather than left unpopulated because an unpopulated value is not neutral: a rule
    matching it is silent forever and reads as coverage, which is how KbOptionalDependencyDown came
    to describe a reranker outage that no series could ever report. A capability gap is
    kb_retrieval_rerank_skips_total; a provider outage is an error class on the provider families.

    ``required`` distinguishes "readiness fails" from "we are degraded and still serving", which
    is the distinction KbOptionalDependencyDown exists to make visible: an optional dependency
    going away is otherwise completely silent, because readiness deliberately ignores it. On this
    service the only optional one left is ``object_storage`` — ingestion cannot store originals
    while chat is unaffected.

    TODO(fastapi-service): read the cached readiness state in app/api/health.py. Sample the CACHE,
    never the dependency — this callback runs on every export interval, and probing PostgreSQL
    from a metrics callback is a self-inflicted load test that scales with replica count.
    """
    return ()


def _observe_circuit_breaker_state(options: CallbackOptions) -> Iterable[Observation]:
    """kb_circuit_breaker_state {dependency} — 0 closed, 1 half-open, 2 open.

    The breaker is invisible without this series, and KbCircuitBreakerOpen pages on ``== 2`` held
    for five minutes. Half-open is the breaker working and is deliberately not alertable.

    TODO(provider-adapter-engineer / retrieval-engineer): observe the shared-dependency breakers
    only. The per-(org, credential, model) breakers must NOT be reported here; their key set grows
    with tenants, and for OpenRouter it grows with an upstream slug set that changes without our
    involvement. Since ADR-030 that excludes the embedding and rerank breakers entirely — there is
    no shared embedding or rerank dependency left to hold a global breaker, so the only values this
    callback may ever emit are the six above.
    """
    return ()


def _observe_queue_depth(options: CallbackOptions) -> Iterable[Observation]:
    """kb_queue_depth {queue} — Celery's ingest, embed, crawl, evaluate, maintenance.

    The label is the queue NAME, never a connection name. This is the one family emitted by BOTH
    runtimes under one name — Laravel reports ai-dispatch, notify, maintenance and exports, and
    ``maintenance`` exists on both sides and is separated by ``service``. That shared ownership is
    safe only because the label set is identical on both sides; if the two ever diverge,
    ``sum by (queue)`` drops whichever side lacks a label, with no warning.

    TODO(celery-workers): sample the broker directly. Deliberately NOT derived from Celery's
    internal state — a reserved-but-unstarted task is invisible there, so the queue reads empty
    while nothing progresses.
    """
    return ()


BUILD_INFO = meter.create_observable_gauge(
    "kb.build_info",
    callbacks=[_observe_build_info],
    description="Which build is emitting this series. Value is always 1.",
)

# The dotted names below are `kb.dependency.up` and `kb.circuit_breaker.state`, not
# `kb.dependency_up` / `kb.circuit_breaker_state`. Both spellings export to the same Prometheus
# name, so the catalog contract cannot tell them apart — but the Collector's
# infrastructure/docker/otel/metric-allowlist.yaml is an EXACT-NAME filter, and a name that is not
# on it is dropped in the pipeline. Silently: the instrument is created, the SDK exports it, the
# Collector discards it, and the series never reaches Prometheus with no error at either end.
# These match that file. Changing either spelling means changing both, in one commit.
DEPENDENCY_UP = meter.create_observable_gauge(
    "kb.dependency.up",
    callbacks=[_observe_dependency_up],
    description="What /health/ready decided, per dependency.",
)

CIRCUIT_BREAKER_STATE = meter.create_observable_gauge(
    "kb.circuit_breaker.state",
    callbacks=[_observe_circuit_breaker_state],
    description="Breaker state: 0 closed, 1 half-open, 2 open.",
)

QUEUE_DEPTH = meter.create_observable_gauge(
    "kb.queue.depth",
    callbacks=[_observe_queue_depth],
    description="Depth of each named queue.",
)


# =================================================================================================
# CATALOGUED FAMILIES THIS SERVICE DELIBERATELY DOES NOT REGISTER
# =================================================================================================
# One metric name, one owning service. Two services exporting one name with different label sets
# is the failure where ``sum by (operation)`` silently discards every series missing the label,
# and PromQL does not warn. Each line below is an ownership decision, not an omission.
#
# kb_chat_first_token_seconds {model}
#     OWNED BY core-api. The number is measured from Laravel's receipt of the client request to
#     the first token event flushed to the client, so only Laravel can start the clock. Recording
#     it here would time the provider leg and quietly exclude the 1.5 s retrieval budget, which is
#     the exact bug that makes a dashboard read 900 ms while users wait six seconds. The Laravel
#     side must record it inside the streaming callback's ``finally``, because the auto server
#     span ends when the handler returns the StreamedResponse — before a byte of body.
#
# kb_internal_requests_total {operation, outcome, error_class, status_class}
#     OWNED BY core-api, the CALLER. Only the calling side observes a connect failure, a timeout,
#     or a response that never arrived — the side that did not receive the request cannot count
#     it. Registering it on both sides would double every series that both sides can see while
#     leaving the interesting ones single-counted. Flagged for contract-steward: the catalog does
#     not state an owner for this row.
#
# kb_internal_scheduler_tick_timestamp_seconds {task}
# kb_internal_scheduler_tasks_total {task, outcome}
#     OWNED BY core-api, and read from a PostgreSQL row seeded in the migration rather than
#     written by the scheduler. A scheduler that never started after a deployment must read as an
#     ancient timestamp, not as a missing series: a series that never existed cannot be
#     threshold-alerted, and the scheduler container has no scrape target of its own.
#
# kb_crawl_sources_overdue
#     OWNED BY core-api, counted on scrape, and deliberately not by the scheduler or by this
#     service. It is the only signal that catches a scheduler ticking perfectly while the work
#     does not happen.
#
# Infrastructure families — CPU, memory, disk, database connections, Valkey memory, Qdrant
# collection size, object storage usage, worker concurrency — are served by node_exporter,
# cAdvisor, postgres_exporter, redis_exporter, Qdrant's own /metrics, SeaweedFS and the Celery
# exporter. Re-exporting any of them under a kb_ name produces two numbers for one fact that
# disagree during exactly the incident that needs them. See
# infrastructure/observability/exporters/README.md.

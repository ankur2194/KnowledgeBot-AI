"""The stage runner — twenty named stages, in declared order, each recording its own fragment.

**This is the pipeline framework we did not adopt (ADR-016), written out.** Every stage is a
block with a name from ``app/rag/stages.py``, a span from the closed catalogue, a duration, and
a trace fragment. That explicitness is the product: an opaque graph cannot tell you which stage
dropped a candidate, and "excluded results *and reasons*" is a spec requirement (§8.24), not a
debugging nicety. Adding a framework back is a bug, not a refactor.

**No stage is ever jumped.** A stage that is switched off, inapplicable, or degraded still
executes its block, still opens its span, and still writes a fragment — carrying
``not_applicable`` or a closed skip reason. Two consequences worth stating because both look
like exceptions to the rule and are not:

* A greeting (``needs_retrieval = false``) runs stages 6-13 as *recorded no-ops*. Nothing is
  embedded, no vector query is issued, nothing is hydrated, no passage is reranked — and every
  one of those stages is present in the trace saying why it did nothing. "Skips stages 6-13"
  in the contract means the work, not the record; a trace with thirteen stages in it is a
  trace an operator cannot align with a trace that has twenty.
* A refusal runs stages 14-16. The prompt is built (its section counts are what an operator
  compares against the answering case) and the reply is produced by the runner rather than by
  a provider — :class:`GenerationMode` records which, and no provider call is made, because
  falling through to model knowledge on an empty evidence set is the one thing strict RAG
  forbids.

**The four mandatory filters are enforced twice, on purpose.** Stage 6 builds
``assert_scoped(tenant_filter(ctx, allowed_version_ids))`` from the authenticated scope and
records the resolved object, and the injected :class:`BranchSearch` takes ``ctx`` and
``allowed_version_ids`` **positionally** so ``branch_query`` rebuilds the same filter for each
branch from the same scope. There is no parameter anywhere in this module that accepts a
pre-built filter: such a parameter is a caller that built it wrong, or built it for another
tenant, and a signature with nowhere to notice.

**Stages 15-16 are injected, never imported.** The provider adapters are a separate layer with
their own error taxonomy; the runner takes a :class:`Generate` callable and knows nothing about
any vendor. The same is true of query embedding, reranking and passage hydration — this module
imports no adapter, no Qdrant client and no database module, which is also why its tests need
none of the three.

**The trace fragment is the deliverable, not a side effect.** ``retrieval.trace`` is what the
admin playground renders: original and rewritten query, the resolved filter, per-candidate
dense/sparse/fused/rerank scores *and ranks*, every exclusion with its reason and the stage that
recorded it, the packed order, and the timing breakdown — all under a
``retrieval_configuration_version`` without which the row cannot be replayed and is worthless as
a regression baseline. A stage that filters without recording makes the whole panel untrustworthy.
"""

from __future__ import annotations

import time
from collections.abc import AsyncIterator, Callable, Iterator, Mapping, Sequence
from contextlib import contextmanager
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any, Final, Protocol

from opentelemetry import trace

from app.core.errors import ErrorClass, KbError, Origin
from app.rag.citations import CITATION_LABEL_PATTERN, CitationCheck, validate_answer_citations
from app.rag.evidence import (
    DENSE_TOP_K,
    RERANK_CANDIDATES,
    RERANK_RETAIN,
    SPARSE_TOP_K,
    apply_threshold,
    as_candidates,
    dedup_and_diversify,
    fuse,
    select_unranked,
)
from app.rag.normalize import NormalizedQuery, normalize_query
from app.rag.packing import (
    PackedContext,
    PassageSource,
    Selected,
    TokenMeasure,
    compute_budget,
    pack,
    selected_from_candidates,
    selected_from_scored,
)
from app.rag.prompt import BuiltPrompt, SectionName, build_prompt
from app.rag.rerank import (
    Reranker,
    RerankOutcome,
    RerankScale,
    RerankSkipReason,
    calibration_for,
    rerank,
    rerank_gate,
)
from app.rag.rewrite import Rewrite, Rewriter, rewrite_query
from app.rag.stages import (
    ACCESS_BUDGET_SECONDS,
    FIRST_TOKEN_BUDGET_SECONDS,
    RETRIEVAL_BUDGET_SECONDS,
    STAGE_SPANS,
    STAGES,
    ExclusionReason,
)
from app.retrieval.search import BranchName, Candidate, assert_scoped
from app.retrieval.tenancy import MANDATORY_FILTER_KEYS, TenantContext, tenant_filter

__all__ = [
    "NOT_APPLICABLE_NO_RETRIEVAL",
    "RETRIEVAL_STAGES",
    "BranchSearch",
    "CandidateRow",
    "CitationRow",
    "EmbedQuery",
    "Exclusion",
    "Generate",
    "GenerationMode",
    "PipelineDeps",
    "PipelineRequest",
    "PipelineResult",
    "QueryVectors",
    "RerankCapability",
    "RetrievalConfig",
    "RetrievalTrace",
    "StageFragment",
    "TraceRun",
    "run_pipeline",
]

_TRACER: Final = trace.get_tracer("app.rag.runner")

#: The stages a request that needs no retrieval performs as recorded no-ops. Named as data so
#: the trace, the test and this docstring cannot drift: §12.4 says these are what a greeting
#: skips, and "skips" means the work.
RETRIEVAL_STAGES: Final[tuple[str, ...]] = STAGES[5:13]

#: The value a stage records instead of its usual fragment when the turn needs no retrieval.
#: One string, so the playground can group on it rather than parsing prose.
NOT_APPLICABLE_NO_RETRIEVAL: Final[str] = "needs_retrieval=false"


class GenerationMode(StrEnum):
    """Which of the three replies stage 15 produced. **On the trace, always.**

    An answer, a refusal and a pleasantry are three different products of one pipeline, and an
    evaluation run that mixes them measures nothing. The refusal in particular must be
    countable: "nothing cleared the evidence threshold" is a correct outcome and its metric is
    not an error rate.
    """

    #: Evidence was packed and a provider generated an answer from it.
    GROUNDED = "grounded"
    #: Stage 12 passed nothing. The bot states the answer is not in the sources, cites nothing,
    #: and **no provider call is made** — there is no prompt that makes falling through to
    #: model knowledge safe, so the fall-through does not exist.
    REFUSAL = "refusal"
    #: Stage 4 decided the turn needs no retrieval. A bounded canned reply.
    NO_RETRIEVAL = "no_retrieval"


@dataclass(frozen=True, slots=True)
class QueryVectors:
    """What the query embedded to. Dense always; sparse when the lexical arm is running.

    ``provider`` and ``model`` ride along because ``(provider, model)`` *is* the vector space
    (ADR-031) and the trace has to be able to say which space this query was embedded in — the
    authority for that is the source version's own row, never this service's environment.

    ``sparse`` is ``None`` on a dense-only run, and that is a real state rather than an empty
    result: ``select_unranked`` refuses to select on branch agreement when only one branch ran,
    which is the guard that keeps a degraded run from silently becoming a cosine cutoff.
    """

    dense: Sequence[float]
    sparse: Any | None
    provider: str
    model: str


class EmbedQuery(Protocol):
    """Stage 7's provider call. Injected — embedding is an API call (ADR-030)."""

    async def __call__(self, text: str, /) -> QueryVectors: ...


class BranchSearch(Protocol):
    """Stages 7-8. ``ctx`` and ``allowed_version_ids`` are positional and required.

    The production implementation is a closure over ``app.retrieval.search.retrieve_branches``
    bound to a client and an :class:`~app.retrieval.collection.EmbeddingSpace`. It is a
    protocol rather than a direct call so the runner takes no Qdrant client, but the
    **signature** is the load-bearing part: the scope arrives positionally, each branch builds
    its own filter from it, and there is deliberately no pre-built-filter parameter.
    """

    async def __call__(
        self,
        ctx: TenantContext,
        allowed_version_ids: Sequence[str],
        vectors: QueryVectors,
        /,
        *,
        dense_limit: int,
        sparse_limit: int | None,
    ) -> Mapping[BranchName, Sequence[Any]]: ...


class Generate(Protocol):
    """Stages 15-16. The provider call and the token stream, as one injected callable.

    Not imported: the five wire adapters are being written separately, they own their own
    error taxonomy, and a runner that imported one would make every unit test here need a
    provider. It returns an async iterator of text deltas; the runner times the first one
    against :data:`~app.rag.stages.FIRST_TOKEN_BUDGET_SECONDS` and accumulates the rest.
    """

    def __call__(self, prompt: BuiltPrompt, run: TraceRun, /) -> AsyncIterator[str]: ...


@dataclass(frozen=True, slots=True)
class RerankCapability:
    """The four provider facts stage 11's gate needs, read from the provider layer by the caller.

    They are passed in rather than looked up here for the reason ``rerank_gate`` and
    ``calibration_for`` state: ``app/providers/capabilities.py`` is the provider layer's, the
    import direction ``app/providers`` → ``app/rag`` must not open, and no vendor name is ever
    restated in this package. ``supports`` is the AND of all three eligibility axes;
    ``publishes_endpoint`` and ``scale`` are two of them alone, carried so a ``False`` can be
    attributed to the right cause with the right remedy.
    """

    provider: str
    model: str | None
    supports: bool
    publishes_endpoint: bool
    scale: RerankScale


@dataclass(frozen=True, slots=True)
class RetrievalConfig:
    """The immutable retrieval snapshot. **Every trace row carries its version.**

    A trace without ``retrieval_configuration_version`` cannot be replayed and is worthless as
    a regression baseline (§21.4). Every default in here is a starting point to be moved by an
    evaluation run, never by intuition.

    **No credential is in this object and none may ever be added.** The snapshot exists to be
    persisted and replayed — into ``retrieval_traces``, into playground records, into queued
    job bodies — so a provider key put *inside* it lands in columns no redaction fixture covers
    and is hashed into the configuration version, silently changing cache keys on a rotation.
    The credential is a top-level field on the internal request, beside the snapshot and
    excluded from its hash (``kb-security-baseline``, ``kb-internal-api-contracts``).
    """

    retrieval_configuration_version: str
    #: ``fusion.k``. **Required, with no default**, because the failure this guards is exactly
    #: an omitted value: the Qdrant client's own RRF constant is **2**, at which the rank-1 hit
    #: of each branch dominates roughly thirty times more sharply than the literature's 60, and
    #: the symptom is hybrid search preferring whatever sparse returned first on queries where
    #: dense was obviously right. ``app.rag.evidence.RRF_K`` is the value to pass; a config that
    #: cannot state its own ``k`` is a config whose traces cannot be compared.
    fusion_k: int
    dense_top_k: int = DENSE_TOP_K
    sparse_top_k: int = SPARSE_TOP_K
    #: ``diversity.max_per_document``. The adjacency exemption is what makes it safe to apply.
    max_per_document: int = 3
    rewrite_enabled: bool = True
    rerank_enabled: bool = True
    rerank_candidates: int = RERANK_CANDIDATES
    rerank_retain: int = RERANK_RETAIN
    #: ``context.reserve_output``. Subtracted from the window **before** packing. It covers the
    #: answer and anything else the provider counts as output — thinking tokens above all.
    reserve_output: int = 1024
    history_window_turns: int = 8
    question_max_chars: int = 4000
    citations_enabled: bool = True
    require_at_least_one_citation: bool = True
    #: Bot-configured reply text supplied by the control plane. The defaults below are English
    #: placeholders and are a known gap: a Spanish bot that refuses in English is a worse
    #: refusal than one that refuses in Spanish, and nothing here can localize a canned string.
    refusal_text: str = (
        "I could not find an answer to that in the sources available to me. "
        "Try narrowing the question, or ask about a specific document."
    )
    small_talk_text: str = "Hello. Ask me anything about the documents I have been given."

    def __post_init__(self) -> None:
        if self.fusion_k <= 0:
            raise ValueError("fusion.k must be positive; the literature default is 60")
        if self.rerank_retain > self.rerank_candidates:
            raise ValueError(
                "retaining more candidates than were ever scored produces a short context and "
                "no error"
            )
        if self.reserve_output <= 0:
            raise ValueError(
                "reserve_output of zero is the mid-sentence truncation defect as a setting: "
                "the request is accepted, the window overflows, the provider trims the answer, "
                "and nothing raises"
            )


@dataclass(frozen=True, slots=True)
class PipelineRequest:
    """One chat turn, as the runner needs it. Assembled by the router from the internal body."""

    question: str
    #: The full conversation so far, oldest first, as rendered turns. Stage 3 bounds it.
    history: tuple[str, ...] = ()
    bot_instructions: str = ""
    #: The generation model's context window, in tokens. From the model catalogue, not guessed.
    model_context_tokens: int = 8192
    #: Available to ``feedback`` and to "save this question to an evaluation dataset" (stage 20).
    message_id: str = ""


@dataclass(frozen=True, slots=True)
class PipelineDeps:
    """Everything the runner calls out to. All injected, none imported.

    ``reranker`` and ``rewriter`` may be ``None`` — an organization with no ranking-capable
    provider, a bot with no rewrite model. Neither absence is an error and both are visible on
    the trace as a closed reason.
    """

    embed_query: EmbedQuery
    search: BranchSearch
    hydrate: PassageSource
    measure: TokenMeasure
    generate: Generate
    rewriter: Rewriter | None = None
    reranker: Reranker | None = None
    rerank_capability: RerankCapability | None = None
    provider_connection_id: str = ""


@dataclass(frozen=True, slots=True)
class Exclusion:
    """One dropped candidate: what, why, with what score, and **at which stage**.

    The stage is captured from the block that was open when ``exclude`` was called rather than
    passed by the caller, so it cannot be stated wrongly. It is what lets the playground group
    the panel by stage as well as by reason, and it is how "excluded: dedup" and "excluded:
    budget" stop being one undifferentiated list.
    """

    chunk_id: str
    reason: ExclusionReason
    score: float | None
    stage: str


@dataclass(frozen=True, slots=True)
class StageFragment:
    """One stage's row in the trace: its number, its span, its duration, its own fields."""

    number: int
    name: str
    span: str | None
    duration_seconds: float
    fields: Mapping[str, Any]


@dataclass(frozen=True, slots=True)
class CandidateRow:
    """One row of the playground's retrieval table. **All five numbers, plus the rerank score.**

    A candidate found by only one branch keeps ``None`` for the other branch's rank, and that
    asymmetry is the most diagnostic field in the panel — it is also the signal the degraded
    path selects on. Never fill it in from anywhere.
    """

    chunk_id: str
    source_id: str | None
    dense_rank: int | None
    dense_score: float | None
    sparse_rank: int | None
    sparse_score: float | None
    fused_score: float
    rerank_score: float | None
    rerank_scale: str | None


@dataclass(frozen=True, slots=True)
class CitationRow:
    """Stage 17's output: what the UI opens when a reader clicks ``[S1]``.

    Location metadata is per-format and comes straight out of ``PAYLOAD_PROJECTION`` — PDF
    page, slide, sheet plus row range, web URL and anchor. It is carried as the projected
    subset rather than re-fetched, because a fetch addressed by chunk id alone has no tenant
    filter of its own.
    """

    label: str
    chunk_id: str
    source_id: str
    source_version_id: str
    locator: Mapping[str, Any]
    excerpt: str


@dataclass(frozen=True, slots=True)
class RetrievalTrace:
    """The ``retrieval.trace`` frame, whole. Every field §8.24 requires."""

    retrieval_configuration_version: str
    prompt_version: str | None
    needs_retrieval: bool
    generation_mode: GenerationMode
    original_query: str
    rewritten_query: str | None
    retrieval_query: str
    rewrite_fallback: str | None
    detected_script: str | None
    resolved_filter: Mapping[str, Any]
    optional_facets: Mapping[str, str]
    embedding_provider: str | None
    embedding_model: str | None
    branches_queried: tuple[str, ...]
    fusion_k: int
    candidates: tuple[CandidateRow, ...]
    exclusions: tuple[Exclusion, ...]
    packed_order: tuple[tuple[str, str], ...]
    labels: Mapping[str, str]
    rerank_skip_reason: str | None
    rerank_scale: str | None
    insufficient_evidence: bool
    unknown_citation_labels: tuple[str, ...]
    stages: tuple[StageFragment, ...]
    timings: Mapping[str, float]
    retrieval_leg_seconds: float
    first_token_seconds: float | None
    message_id: str


@dataclass(frozen=True, slots=True)
class PipelineResult:
    """What the router streams and what it persists."""

    answer: str
    mode: GenerationMode
    citations: tuple[CitationRow, ...]
    citation_check: CitationCheck
    packed: PackedContext
    prompt: BuiltPrompt
    trace: RetrievalTrace


@dataclass
class TraceRun:
    """The concrete :class:`~app.rag.stages.StageRun`, plus the trace it accumulates.

    It carries the deadline so a stage can decline work it cannot finish, and the trace so a
    stage cannot drop a candidate without recording why. It carries **no organization
    identifier**: scope lives on the tenant context, and a duplicate here would be a second
    thing to get wrong and a second thing to forget to check.

    ``clock`` is injected and monotonic. Wall clock would mean an NTP step mid-request moves
    the deadline — forward and the work is abandoned early, backward and it outlives the caller.
    """

    retrieval_configuration_version: str
    #: Absolute, on the same monotonic clock as ``clock``.
    deadline_monotonic: float
    clock: Callable[[], float] = time.monotonic

    exclusions: list[Exclusion] = field(default_factory=list)
    labels: dict[str, str] = field(default_factory=dict)
    fragments: list[StageFragment] = field(default_factory=list)
    insufficient_evidence: bool = False

    _current_stage: str = "request_validation"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        """Record a dropped candidate. Every drop, every stage, no exceptions.

        The retain cap is the one that gets forgotten, because ``[:retain]`` reads as a slice
        rather than as a filter — and then the panel shows fewer candidates than were reranked,
        every one of them passing the threshold, and no row explaining the difference.
        """
        self.exclusions.append(
            Exclusion(chunk_id=chunk_id, reason=reason, score=score, stage=self._current_stage)
        )

    def record_labels(self, labels: Mapping[str, str]) -> None:
        """Fix the label-to-chunk association, before the prompt and before any provider call.

        Called by :func:`app.rag.citations.assign_labels` at the moment of assignment. Re-recording
        it is a programming error rather than an idempotent write: two assignments in one request
        means one of them is the mapping the prompt used and the other is the mapping the citation
        rows will be written from, and nothing downstream can tell which.
        """
        if self.labels and self.labels != dict(labels):
            raise ValueError(
                "citation labels were assigned twice in one request with different mappings. "
                "One of them is what the model was shown and the other is what a citation will "
                "resolve to, and no downstream check can tell them apart"
            )
        self.labels = dict(labels)

    def remaining_seconds(self) -> float:
        """Time left against the caller's deadline. Never negative."""
        return max(0.0, self.deadline_monotonic - self.clock())

    def retrieval_leg_seconds(self) -> float:
        """Measured duration of stages 6-13, against :data:`RETRIEVAL_BUDGET_SECONDS`.

        Summed from the recorded fragments rather than timed separately, so the number in the
        panel and the numbers in the per-stage rows cannot disagree. Stages 7 and 8 are issued
        concurrently and their spans carry the real per-branch latencies; the runner's own
        figure for stage 7 covers the wall clock of both, which is why stage 8's row reads
        near zero and is not a bug.
        """
        wanted = set(RETRIEVAL_STAGES)
        return sum(f.duration_seconds for f in self.fragments if f.name in wanted)

    @contextmanager
    def stage(self, name: str, *, open_span: bool = True) -> Iterator[dict[str, Any]]:
        """Run one stage: span, timer, current-stage marker, and its fragment.

        ``open_span`` is False for exactly two stages, 7 and 8, whose CLIENT spans are opened
        inside ``app/retrieval/search.py`` around the actual round trip — which is where they
        belong, and opening a second one here would put two spans of the same catalogued name
        on one request.

        The fragment is written in ``finally``, so a stage that raises still leaves a row: a
        trace that loses the failing stage is a trace that shows the failure happening nowhere.
        """
        if name not in STAGES:
            raise KeyError(
                f"{name!r} is not a stage. Stage order lives in app/rag/stages.py and nowhere "
                "else; a name minted here would record a span and a fragment nothing groups on"
            )
        span_name = STAGE_SPANS.get(name)
        previous = self._current_stage
        self._current_stage = name
        fields: dict[str, Any] = {}
        started = self.clock()
        span_cm = (
            _TRACER.start_as_current_span(span_name)
            if (open_span and span_name is not None)
            else None
        )
        if span_cm is not None:
            span_cm.__enter__()
        try:
            yield fields
        finally:
            duration = self.clock() - started
            if span_cm is not None:
                span_cm.__exit__(None, None, None)
            self._current_stage = previous
            self.fragments.append(
                StageFragment(
                    number=STAGES.index(name) + 1,
                    name=name,
                    span=span_name,
                    duration_seconds=duration,
                    fields=dict(fields),
                )
            )

    def timings(self) -> dict[str, float]:
        return {fragment.name: fragment.duration_seconds for fragment in self.fragments}


def _filter_fragment(query_filter: Any) -> dict[str, Any]:
    """Render the resolved filter for the trace, and re-check the four terms while doing it.

    ``assert_scoped`` has already refused anything weaker. This re-derives the key list from
    the object that is actually going to the trace, so a panel showing three terms is
    impossible: the fragment and the filter are the same object read twice.
    """
    must = list(query_filter.must or [])
    keys = tuple(condition.key for condition in must)
    if keys != MANDATORY_FILTER_KEYS:
        raise ValueError(
            f"the resolved filter carries {keys}, not {MANDATORY_FILTER_KEYS}. Every vector "
            "query filters organization, bot access, active source status and active source "
            "version — all four, on every call"
        )
    return {
        "must": [
            {"key": condition.key, "match": condition.match.model_dump(exclude_none=True)}
            for condition in must
        ],
        "must_not": [],
    }


async def _canned(text: str) -> AsyncIterator[str]:
    """A bounded reply the runner produces itself, for a refusal or a pleasantry.

    It goes through stage 16 like any other stream so first-token latency, the streaming span
    and the client's frame sequence are identical on all three paths. What it does not do is
    call a provider: there is no prompt that makes falling through to model knowledge safe on
    an empty evidence set.
    """
    yield text


def _strip_unknown_labels(answer: str, unknown: Sequence[str]) -> str:
    """Remove model-authored identifiers that were never packed.

    The stripping lives here because ``CitationCheck`` deliberately carries no cleaned answer:
    two strippers disagree the moment one learns about a new label form, and the disagreement
    shows up as a citation marker left in prose that resolves to nothing. ``unknown`` names
    every label to remove, and this is the one place that acts on it.
    """
    if not unknown:
        return answer
    drop = set(unknown)
    return CITATION_LABEL_PATTERN.sub(
        lambda match: "" if f"S{match.group(1)}" in drop else match.group(0), answer
    )


class _Memoized:
    """Hydrate once, serve twice.

    Stage 11 hydrates the reranker's candidate set and stage 13 needs the retained subset of
    exactly those chunks. Calling the injected source again would be a second PostgreSQL round
    trip inside a 1.5 s leg that already contains a ranking API round trip, for rows we are
    holding. Anything genuinely new still goes to the source, so the degraded path — where
    stage 11 never ran and nothing was hydrated — works unchanged.
    """

    def __init__(self, source: PassageSource) -> None:
        self._source = source
        self._cache: dict[str, str] = {}
        self.calls: int = 0

    async def __call__(self, chunk_ids: Sequence[str], /) -> Mapping[str, str]:
        missing = [chunk_id for chunk_id in chunk_ids if chunk_id not in self._cache]
        if missing:
            self.calls += 1
            self._cache.update(await self._source(missing))
        return {
            chunk_id: self._cache[chunk_id] for chunk_id in chunk_ids if chunk_id in self._cache
        }


async def run_pipeline(
    request: PipelineRequest,
    ctx: TenantContext,
    allowed_version_ids: Sequence[str],
    cfg: RetrievalConfig,
    deps: PipelineDeps,
    run: TraceRun,
    *,
    on_delta: Callable[[str], Any] | None = None,
) -> PipelineResult:
    """Walk all twenty stages in declared order and return the answer plus its trace.

    ``ctx`` and ``allowed_version_ids`` are positional and required, and they are the only
    source of scope in this function. There is no keyword that relaxes a filter term, no admin
    path and no test hook: "FastAPI is only reachable from Laravel" is network topology, not
    authorization.

    ``on_delta`` is the streaming sink. It is optional because the evaluation harness runs the
    same pipeline with no client attached, and it is a sink rather than this function being an
    async generator so the result and the trace are still returned by value.
    """
    hydrate = _Memoized(deps.hydrate)
    measure = deps.measure

    # ── 1. request validation ────────────────────────────────────────────────
    with run.stage("request_validation") as f:
        question = request.question.strip()
        f["length_chars"] = len(question)
        f["length_cap"] = cfg.question_max_chars
        if not question:
            raise KbError(ErrorClass.VALIDATION, "the question is empty", origin=Origin.SELF)
        if len(question) > cfg.question_max_chars:
            raise KbError(
                ErrorClass.VALIDATION,
                f"the question is {len(question)} characters, over the "
                f"{cfg.question_max_chars} cap",
                origin=Origin.SELF,
            )

    # ── 2. access + quota validation ─────────────────────────────────────────
    with run.stage("access_quota_validation") as f:
        # Authorization, quota and rate limiting are the control plane's and have already run
        # (kb-internal-api-contracts); what is re-checked here is the one thing only this
        # process can see — whether there is any time left to do the work in. Stages 1-2
        # together target ACCESS_BUDGET_SECONDS, before any AI cost is incurred.
        remaining = run.remaining_seconds()
        f["remaining_seconds"] = remaining
        f["access_budget_seconds"] = ACCESS_BUDGET_SECONDS
        f["retrieval_budget_seconds"] = RETRIEVAL_BUDGET_SECONDS
        if remaining <= 0.0:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                "the request deadline had already passed when the pipeline started",
                origin=Origin.SELF,
            )

    # ── 3. conversation-context preparation ──────────────────────────────────
    with run.stage("conversation_context") as f:
        turns = tuple(request.history[-cfg.history_window_turns :]) if request.history else ()
        f["turns_available"] = len(request.history)
        f["turns_used"] = len(turns)
        f["window_turns"] = cfg.history_window_turns
        # `summary.enabled` is a contract knob with no producer in this tree. Recorded as
        # false rather than omitted: an absent field reads as "the summary was empty", and the
        # bounded window above is the half of §12.3 that actually protects stage 13's budget.
        f["summary_enabled"] = False
        f["summary_tokens"] = 0

    # ── 4. query normalization ───────────────────────────────────────────────
    with run.stage("query_normalization") as f:
        normalized: NormalizedQuery = normalize_query(question)
        f["normalized_query"] = normalized.text
        f["detected_script"] = normalized.script
        f["needs_retrieval"] = normalized.needs_retrieval
        f["preserved_tokens"] = list(normalized.preserved)
        f["noise_removal_reverted"] = normalized.noise_removal_reverted

    needs_retrieval = normalized.needs_retrieval

    # ── 5. query rewriting ───────────────────────────────────────────────────
    with run.stage("query_rewriting") as f:
        rewrite: Rewrite = await rewrite_query(
            normalized.text,
            turns,
            deps.rewriter,
            enabled=cfg.rewrite_enabled,
            needs_retrieval=needs_retrieval,
        )
        f["original_query"] = rewrite.original_query
        f["rewritten_query"] = rewrite.rewritten_query
        f["retrieval_query"] = rewrite.retrieval_query
        f["fallback"] = rewrite.fallback.value if rewrite.fallback else None
        f["dropped_entities"] = list(rewrite.dropped_entities)
        f["facets"] = dict(rewrite.facets)

    # ── 6. retrieval filters ─────────────────────────────────────────────────
    resolved_filter: dict[str, Any] = {}
    with run.stage("retrieval_filters") as f:
        if needs_retrieval:
            resolved_filter = _filter_fragment(
                assert_scoped(tenant_filter(ctx, allowed_version_ids))
            )
            f["filter"] = resolved_filter
            # Advisory, and they never join `must`. A facet is model output; the day one can
            # widen a filter is the day a rewritten query is an authorization input.
            f["optional_facets"] = dict(rewrite.facets)
            f["mandatory_keys"] = list(MANDATORY_FILTER_KEYS)
        else:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL

    # ── 7-8. dense and sparse retrieval ──────────────────────────────────────
    branches: Mapping[BranchName, Sequence[Any]] = {}
    vectors: QueryVectors | None = None
    with run.stage("dense_retrieval", open_span=False) as f:
        if needs_retrieval:
            # Query embedding is a provider call and belongs to the dense branch: the vector
            # must come from the SAME EmbeddingSpace the passages did, and the authority for
            # which that was is the source version's row, not this service's environment.
            vectors = await deps.embed_query(rewrite.retrieval_query)
            branches = await deps.search(
                ctx,
                allowed_version_ids,
                vectors,
                dense_limit=cfg.dense_top_k,
                sparse_limit=cfg.sparse_top_k if vectors.sparse is not None else None,
            )
            f["top_k"] = cfg.dense_top_k
            f["embedding_provider"] = vectors.provider
            f["embedding_model"] = vectors.model
            f["hits"] = len(branches.get("dense", ()))
            # Both round trips are issued concurrently inside one call, so this duration is
            # the wall clock of the pair. The per-branch latencies are on the two CLIENT spans
            # `app/retrieval/search.py` opens, which is where the metrics read them from.
            f["latency_is_for_both_branches"] = True
        else:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL

    with run.stage("sparse_retrieval", open_span=False) as f:
        if not needs_retrieval:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL
        elif "sparse" in branches:
            f["top_k"] = cfg.sparse_top_k
            f["hits"] = len(branches["sparse"])
        else:
            # A dense-only run. Recorded as "the branch did not run", never as zero hits: an
            # empty lexical branch is a very strong signal and a missing one is no signal, and
            # `select_unranked` refuses rather than confusing the two.
            f["branch_not_run"] = True

    # ── 9. fusion ────────────────────────────────────────────────────────────
    fused: list[Candidate] = []
    with run.stage("fusion") as f:
        f["k"] = cfg.fusion_k
        if needs_retrieval and branches:
            fused = fuse(as_candidates(dict(branches)), k=cfg.fusion_k)
            f["candidates"] = len(fused)
            f["branches"] = sorted(branches)
        else:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL

    # ── 10. dedup + diversity ────────────────────────────────────────────────
    kept: list[Candidate] = []
    with run.stage("dedup_diversity") as f:
        f["max_per_document"] = cfg.max_per_document
        if fused:
            kept = dedup_and_diversify(fused, run, max_per_document=cfg.max_per_document)
            f["retained"] = len(kept)
        else:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL

    # ── 11. reranking (capability-gated) ─────────────────────────────────────
    outcome: RerankOutcome | None = None
    with run.stage("reranking") as f:
        if not kept:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL
        else:
            capability = deps.rerank_capability
            # `capability is None` means the caller resolved no ranking-capable connection for
            # this organization, and it is folded into `enabled` rather than given a member of
            # its own: the ladder's first rung already says "an administrator's switch outranks
            # a vendor's capability", and nothing configured is that switch in its off position.
            # It is NOT folded into `provider_supports`, which would report the vendor as
            # lacking a route we never asked about.
            skip = rerank_gate(
                enabled=cfg.rerank_enabled and capability is not None,
                provider_supports=capability.supports if capability else False,
                provider_publishes_endpoint=capability.publishes_endpoint if capability else False,
                provider_scale=capability.scale if capability else RerankScale.UNCALIBRATED,
                model=capability.model if capability else None,
                remaining_seconds=run.remaining_seconds(),
            )
            f["candidates"] = min(len(kept), cfg.rerank_candidates)
            if skip is not None:
                # A skip is never a laundered failure: `RerankSkipReason` is closed and has no
                # member meaning "the call failed", so an outage cannot arrive here.
                outcome = RerankOutcome(skipped=skip, calibration=None, unranked=tuple(kept))
                f["skipped"] = skip.value
                f["degraded"] = True
            else:
                if capability is None or capability.model is None or deps.reranker is None:
                    raise ValueError(
                        "the rerank gate said run and no reranker is bound. The gate reads the "
                        "provider capability row; a disagreement between it and the injected "
                        "adapter is a wiring bug, not a degraded mode, and recording it as a "
                        "skip would file an incident as a configuration"
                    )
                calibration = calibration_for(
                    capability.provider, capability.model, provider_scale=capability.scale
                )
                passages = await hydrate(
                    [c.chunk_id for c in kept[: cfg.rerank_candidates]],
                )
                outcome = await rerank(
                    deps.reranker,
                    calibration,
                    ctx,
                    rewrite.retrieval_query,
                    kept,
                    passages,
                    run,
                    trace_id=request.message_id,
                    provider_connection_id=deps.provider_connection_id,
                    max_candidates=cfg.rerank_candidates,
                )
                f["scored"] = len(outcome.scored)
                f["scale"] = calibration.scale.value
                f["min_score"] = calibration.min_score
                f["calibration"] = calibration.derived_from

    # ── 12. evidence threshold ───────────────────────────────────────────────
    selected: tuple[Selected, ...] = ()
    with run.stage("evidence_threshold") as f:
        f["retain"] = cfg.rerank_retain
        if outcome is None:
            f["not_applicable"] = NOT_APPLICABLE_NO_RETRIEVAL
        elif outcome.applied:
            selected = selected_from_scored(apply_threshold(outcome, run, retain=cfg.rerank_retain))
            f["path"] = "threshold"
        else:
            # No score, therefore no threshold. Branch agreement is the one relevance signal
            # RRF preserves; the fused score is never cut against, because RRF discards
            # magnitude and a cutoff there can only trim the tail.
            selected = selected_from_candidates(
                select_unranked(
                    outcome,
                    run,
                    branches=frozenset(branches),
                    retain=cfg.rerank_retain,
                )
            )
            f["path"] = "branch_agreement"
        f["selected"] = len(selected)
        if needs_retrieval and not selected:
            run.insufficient_evidence = True
            f["insufficient_evidence"] = True

    # ── 13. context packing ──────────────────────────────────────────────────
    skeleton = build_prompt(
        rewrite=rewrite,
        bot_instructions=request.bot_instructions,
        history=turns,
        packed=None,
        measure=measure,
        citations_enabled=cfg.citations_enabled,
    )
    with run.stage("context_packing") as f:
        budget = compute_budget(
            model_context_tokens=request.model_context_tokens,
            reserve_output=cfg.reserve_output,
            prompt_overhead_tokens=skeleton.overhead_tokens,
            history_tokens=skeleton.conversation_tokens,
        )
        f["budget_tokens"] = budget
        f["reserve_output"] = cfg.reserve_output
        f["prompt_overhead_tokens"] = skeleton.overhead_tokens
        f["history_tokens"] = skeleton.conversation_tokens
        packed = await pack(selected, run, hydrate=hydrate, measure=measure, budget_tokens=budget)
        f["packed"] = [(e.label, e.chunk_id) for e in packed.evidence]
        f["used_tokens"] = packed.used_tokens
        f["unhydrated"] = list(packed.unhydrated)
        if not selected:
            f["not_applicable"] = (
                NOT_APPLICABLE_NO_RETRIEVAL if not needs_retrieval else "insufficient_evidence"
            )

    # ── 14. prompt construction ──────────────────────────────────────────────
    with run.stage("prompt_construction") as f:
        prompt = build_prompt(
            rewrite=rewrite,
            bot_instructions=request.bot_instructions,
            history=turns,
            packed=packed,
            measure=measure,
            citations_enabled=cfg.citations_enabled,
        )
        f["version"] = prompt.version
        f["section_tokens"] = {name.value: count for name, count in prompt.section_tokens.items()}
        f["sections"] = [section.name.value for section in prompt.sections]

    # ── 15. provider call ────────────────────────────────────────────────────
    if not needs_retrieval:
        mode = GenerationMode.NO_RETRIEVAL
    elif not packed.evidence:
        mode = GenerationMode.REFUSAL
    else:
        mode = GenerationMode.GROUNDED

    with run.stage("provider_call") as f:
        f["mode"] = mode.value
        f["prompt_version"] = prompt.version
        if mode is GenerationMode.GROUNDED:
            stream = deps.generate(prompt, run)
            f["provider_called"] = True
        else:
            text = cfg.small_talk_text if mode is GenerationMode.NO_RETRIEVAL else cfg.refusal_text
            stream = _canned(text)
            f["provider_called"] = False

    # ── 16. response streaming ───────────────────────────────────────────────
    with run.stage("response_streaming") as f:
        started = run.clock()
        first_token: float | None = None
        parts: list[str] = []
        async for delta in stream:
            if first_token is None:
                first_token = run.clock() - started
            parts.append(delta)
            if on_delta is not None:
                result = on_delta(delta)
                if hasattr(result, "__await__"):
                    await result
        answer = "".join(parts)
        f["first_token_seconds"] = first_token
        f["first_token_budget_seconds"] = FIRST_TOKEN_BUDGET_SECONDS
        f["characters"] = len(answer)

    # ── 17. citation linking ─────────────────────────────────────────────────
    with run.stage("citation_linking") as f:
        # Built from the packed set, never from the answer. The association was fixed in
        # stage 13 and recorded on the trace at assignment; this stage renders it into rows
        # the UI can open, and stage 18 decides which of them the answer actually cited.
        rows = tuple(
            CitationRow(
                label=evidence.label,
                chunk_id=evidence.chunk_id,
                source_id=evidence.source_id,
                source_version_id=evidence.source_version_id,
                locator=dict(block.locator),
                excerpt=block.body,
            )
            for evidence, block in zip(packed.evidence, packed.blocks, strict=True)
        )
        f["labels"] = dict(run.labels)
        f["enabled"] = cfg.citations_enabled

    # ── 18. output validation ────────────────────────────────────────────────
    with run.stage("output_validation") as f:
        check = validate_answer_citations(
            answer,
            packed.evidence,
            run,
            require_at_least_one=cfg.citations_enabled and cfg.require_at_least_one_citation,
        )
        answer = _strip_unknown_labels(answer, check.unknown)
        f["resolved"] = list(check.resolved)
        f["unknown"] = list(check.unknown)
        f["uncited_claims"] = check.uncited_claims
        if check.unknown:
            # The reason exists in the vocabulary for stage 18 and is recorded on the trace as
            # a field rather than through `run.exclude`, which takes a chunk id an unknown
            # label does not have — having none is what makes it unknown (ruling D6).
            f["reason"] = ExclusionReason.UNKNOWN_CITATION_LABEL.value
        cited = tuple(row for row in rows if row.label in set(check.resolved))

    # ── 19. usage recording ──────────────────────────────────────────────────
    with run.stage("usage_recording") as f:
        f["mode"] = mode.value
        f["retrieval_leg_seconds"] = run.retrieval_leg_seconds()
        f["retrieval_budget_seconds"] = RETRIEVAL_BUDGET_SECONDS
        f["over_retrieval_budget"] = run.retrieval_leg_seconds() > RETRIEVAL_BUDGET_SECONDS
        # Token counts and cost land in `provider_calls` and belong to the adapter layer: it
        # is the only thing that saw the provider's own usage numbers, and re-deriving them
        # from our estimator here would publish a second, disagreeing figure.

    # ── 20. feedback + evaluation hooks ──────────────────────────────────────
    with run.stage("feedback_eval_hooks") as f:
        f["message_id"] = request.message_id
        f["eval_capture_available"] = bool(request.message_id)

    return PipelineResult(
        answer=answer,
        mode=mode,
        citations=cited,
        citation_check=check,
        packed=packed,
        prompt=prompt,
        trace=_build_trace(
            cfg=cfg,
            run=run,
            request=request,
            normalized=normalized,
            rewrite=rewrite,
            resolved_filter=resolved_filter,
            vectors=vectors,
            branches=branches,
            fused=fused,
            outcome=outcome,
            packed=packed,
            prompt=prompt,
            mode=mode,
            check=check,
        ),
    )


def _build_trace(
    *,
    cfg: RetrievalConfig,
    run: TraceRun,
    request: PipelineRequest,
    normalized: NormalizedQuery,
    rewrite: Rewrite,
    resolved_filter: Mapping[str, Any],
    vectors: QueryVectors | None,
    branches: Mapping[BranchName, Sequence[Any]],
    fused: Sequence[Candidate],
    outcome: RerankOutcome | None,
    packed: PackedContext,
    prompt: BuiltPrompt,
    mode: GenerationMode,
    check: CitationCheck,
) -> RetrievalTrace:
    """Assemble the frame. Every candidate appears, whether it was packed or dropped."""
    scores: dict[str, tuple[float, str]] = {}
    if outcome is not None and outcome.applied:
        scores = {s.candidate.chunk_id: (s.score, s.scale.value) for s in outcome.scored}

    rows: list[CandidateRow] = []
    for candidate in fused:
        payload = candidate.point.payload or {}
        score = scores.get(candidate.chunk_id)
        rows.append(
            CandidateRow(
                chunk_id=candidate.chunk_id,
                source_id=payload.get("source_id"),
                dense_rank=candidate.dense_rank,
                dense_score=candidate.dense_score,
                sparse_rank=candidate.sparse_rank,
                sparse_score=candidate.sparse_score,
                fused_score=candidate.fused_score,
                rerank_score=score[0] if score else None,
                rerank_scale=score[1] if score else None,
            )
        )

    skipped: RerankSkipReason | None = outcome.skipped if outcome is not None else None
    calibration = outcome.calibration if outcome is not None else None
    first_token = next(
        (
            f.fields.get("first_token_seconds")
            for f in run.fragments
            if f.name == "response_streaming"
        ),
        None,
    )
    return RetrievalTrace(
        retrieval_configuration_version=run.retrieval_configuration_version,
        prompt_version=prompt.version,
        needs_retrieval=normalized.needs_retrieval,
        generation_mode=mode,
        original_query=rewrite.original_query,
        rewritten_query=rewrite.rewritten_query,
        retrieval_query=rewrite.retrieval_query,
        rewrite_fallback=rewrite.fallback.value if rewrite.fallback else None,
        detected_script=normalized.script,
        resolved_filter=dict(resolved_filter),
        optional_facets=dict(rewrite.facets),
        embedding_provider=vectors.provider if vectors else None,
        embedding_model=vectors.model if vectors else None,
        branches_queried=tuple(sorted(branches)),
        fusion_k=cfg.fusion_k,
        candidates=tuple(rows),
        exclusions=tuple(run.exclusions),
        packed_order=tuple((e.label, e.chunk_id) for e in packed.evidence),
        labels=dict(run.labels),
        rerank_skip_reason=skipped.value if skipped else None,
        rerank_scale=calibration.scale.value if calibration else None,
        insufficient_evidence=run.insufficient_evidence,
        unknown_citation_labels=check.unknown,
        stages=tuple(run.fragments),
        timings=run.timings(),
        retrieval_leg_seconds=run.retrieval_leg_seconds(),
        first_token_seconds=first_token if isinstance(first_token, float) else None,
        message_id=request.message_id,
    )


# Checked at import: the runner must walk every stage, and the no-retrieval short circuit must
# name exactly the stages the contract says it covers. Both are invisible in a produced answer.
assert RETRIEVAL_STAGES == (
    "retrieval_filters",
    "dense_retrieval",
    "sparse_retrieval",
    "fusion",
    "dedup_diversity",
    "reranking",
    "evidence_threshold",
    "context_packing",
)
assert SectionName.RETRIEVED_CONTENT in set(SectionName)

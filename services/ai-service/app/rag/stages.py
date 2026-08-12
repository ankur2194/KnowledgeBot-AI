"""Stage order, span names, exclusion reasons and latency budgets — as data.

**This is the one place stage order exists.** Nothing else in the service may hold a second
ordering, and no code path may jump a stage. Turning a stage off is a configuration value
(``rewrite.enabled = false``) that makes the stage a documented no-op; it is never a branch
that skips ahead. What breaks otherwise: a run that fused before it filtered, or reranked
before it deduped, produces a retrieval trace nobody can debug and an evaluation run nobody
can reproduce — and the reordering is invisible in the answer, which still looks fine.

The stage numbering is stable and load-bearing beyond this file: trace fields, metrics, and
the admin playground's panels all key off it. Renumbering is a migration.

**Span names are permanent and closed.** ``STAGE_SPANS`` is a lookup that raises on an
unknown stage rather than deriving a name from a string at runtime. A derived name errors
nowhere — it exports cleanly to the trace backend and matches no recording rule, alert, or
dashboard query — so the failure surfaces as empty panels on the dashboard you opened to
debug retrieval. A stage absent from the mapping records no span of its own; that absence is
deliberate and is not an invitation to invent one.
"""

from __future__ import annotations

from collections.abc import Mapping
from enum import StrEnum
from typing import Final, Protocol

__all__ = [
    "ACCESS_BUDGET_SECONDS",
    "FIRST_TOKEN_BUDGET_SECONDS",
    "RETRIEVAL_BUDGET_SECONDS",
    "SPAN_DOMAINS",
    "STAGES",
    "STAGE_SPANS",
    "ExclusionReason",
    "Stage",
    "StageRun",
    "span_for",
]

#: The twenty stages, in contract order. Index + 1 is the stage number used by the spec,
#: the trace, and the playground.
STAGES: Final[tuple[str, ...]] = (
    "request_validation",  # 1
    "access_quota_validation",  # 2
    "conversation_context",  # 3
    "query_normalization",  # 4
    "query_rewriting",  # 5
    "retrieval_filters",  # 6  — a security boundary, and the one stage with no knobs
    "dense_retrieval",  # 7
    "sparse_retrieval",  # 8
    "fusion",  # 9
    "dedup_diversity",  # 10
    "reranking",  # 11
    "evidence_threshold",  # 12
    "context_packing",  # 13  — citation labels are assigned here, before any generation
    "prompt_construction",  # 14
    "provider_call",  # 15
    "response_streaming",  # 16
    "citation_linking",  # 17
    "output_validation",  # 18
    "usage_recording",  # 19
    "feedback_eval_hooks",  # 20
)

#: The closed list of span domains this service may open a span in. It is the observability
#: catalogue's, not ours, and it deliberately does not contain a domain named after this
#: module — the module name is the wrong axis to name a span on, and the names built that
#: way match no rule, alert, or dashboard query in the repository.
SPAN_DOMAINS: Final[frozenset[str]] = frozenset(
    {"chat", "query", "retrieval", "context", "prompt", "usage", "ingestion", "vector"}
)

#: Stage name to span name. Names come from the observability catalogue and nowhere else.
#: Stages 1-3 sit under spans the control plane opens,
#: stage 5 and stage 15 use the generative-AI semantic-convention form ``{operation}
#: {model}`` which carries a model id and therefore cannot be a constant, and stages 17-20
#: record into trace fields rather than opening spans of their own.
STAGE_SPANS: Final[Mapping[str, str]] = {
    "query_normalization": "kb.query.normalize",
    "retrieval_filters": "kb.retrieval.filters",
    "dense_retrieval": "kb.retrieval.dense",
    "sparse_retrieval": "kb.retrieval.sparse",
    "fusion": "kb.retrieval.fuse",
    "dedup_diversity": "kb.retrieval.dedupe",
    "reranking": "kb.retrieval.rerank",
    "evidence_threshold": "kb.retrieval.threshold",
    "context_packing": "kb.context.pack",
    "prompt_construction": "kb.prompt.build",
    "response_streaming": "kb.chat.stream",
    "usage_recording": "kb.usage.record",
}


class ExclusionReason(StrEnum):
    """Why a candidate did not reach the packed context. The strings are fixed.

    The playground groups on these and evaluation totals them, so a new value is a contract
    change rather than a label. Every drop calls into this vocabulary — including the retain
    cap, which is the one that gets forgotten because ``[:retain]`` reads as a slice rather
    than a filter. The panel then shows fewer candidates than were reranked, all of them
    above the threshold, and no row explaining the difference.

    Exclusions may only be reported for stages *after* the tenant filter. To explain why a
    candidate was dropped you must first have retrieved it, so a panel that could ever say
    "excluded: wrong organization" is a panel whose query was unfiltered.
    """

    EXACT_DUPLICATE = "exact_duplicate"
    #: ``near_duplicate_penalized`` used to sit here, marked "retained, score adjusted", and was
    #: removed on 2026-08-12. It was the only member describing a candidate that is *not*
    #: excluded, so recording it would have put one candidate on both sides of the
    #: scored-minus-packed identity; and stage 10 has no near-duplicate penalty to record,
    #: because the only detector this tree can offer is a subset of the adjacency exemption that
    #: already spares those candidates. The reasoning and the vacuity proof are in
    #: ``app/rag/evidence.dedup_and_diversify``. Re-adding the member without re-reading it
    #: reintroduces both problems at once.
    DOCUMENT_DIVERSITY_CAP = "document_diversity_cap"
    BELOW_RERANK_CANDIDATE_CUTOFF = "below_rerank_candidate_cutoff"
    BELOW_EVIDENCE_THRESHOLD = "below_evidence_threshold"
    #: Only reachable on the degraded path, where stage 11 was skipped and there is no score.
    #: Branch agreement — a candidate carrying both a dense and a sparse rank — is the one
    #: relevance signal RRF preserves, because it is structural rather than magnitude-based,
    #: and it is what the unranked path selects on instead of a threshold. A candidate found
    #: by one branch only is dropped for this reason and must appear in the panel saying so,
    #: or the degraded run looks like a run that simply retrieved less.
    #:
    #: New in the ADR-030 rewrite: the vocabulary predates reranking being optional, so it had
    #: no member for the only filter the optional path applies.
    NO_BRANCH_AGREEMENT = "no_branch_agreement"
    ABOVE_RETAIN_LIMIT = "above_retain_limit"
    CONTEXT_BUDGET_EXHAUSTED = "context_budget_exhausted"
    UNKNOWN_CITATION_LABEL = "unknown_citation_label"


#: Stages 1-2, before any AI cost is incurred.
ACCESS_BUDGET_SECONDS: Final[float] = 0.25
#: Stages 6-13 end to end, including reranking. Reranking is still the expensive part, but the
#: shape of the cost changed with ADR-030 and the old note here is void: it said a CPU forward
#: pass missed this budget by an order of magnitude, "which is why the GPU deployment profile is
#: a requirement". There is no GPU profile any more and no local model to put on one — the
#: `gpu` Compose overlay is deleted, and a self-hoster needs no accelerator.
#:
#: What this budget now buys is ROUND TRIPS. Ranking APIs cap the passages accepted per request,
#: so a candidate depth above that cap becomes several sequential HTTP calls unless they are
#: issued concurrently — an unbatched loop over 25 candidates is 25 round-trips inside 1.5 s.
#: Stage 11 must state its cost before it starts and decline rather than overrun; see
#: `RERANK_MIN_USEFUL_SECONDS` in app/rag/rerank.py.
RETRIEVAL_BUDGET_SECONDS: Final[float] = 1.5
#: Stage 16, measured from request receipt to the first streamed token.
FIRST_TOKEN_BUDGET_SECONDS: Final[float] = 4.0

# Per-stage budgets inside the retrieval leg are deliberately absent: they are allocated
# from measurement, not from arithmetic on 1.5. Each stage records its own duration under
# kb_retrieval_duration_seconds{stage} so the allocation can be derived rather than guessed.


class StageRun(Protocol):
    """The per-request accumulator every stage receives beside its own input.

    It carries the trace, so a stage cannot drop a candidate without recording why, and the
    deadline, so a stage can decline work it cannot finish. It carries no organization
    identifier of its own — scope lives on the tenant context, and a duplicate of it here
    would be a second thing to get wrong.
    """

    @property
    def retrieval_configuration_version(self) -> str:
        """The immutable snapshot id every trace row carries.

        A trace without it cannot be replayed and is worthless as a regression baseline.
        """
        ...

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        """Record a dropped candidate. Every drop, every stage, no exceptions."""
        ...

    def record_labels(self, labels: Mapping[str, str]) -> None:
        """Record the citation label to chunk association fixed at stage 13.

        ``{"S1": "01JQ…", "S2": "01JQ…"}``, in packed order, written **before** the prompt is
        built and therefore before any provider call. It is the artifact stage 17 writes
        citation rows from and stage 18 validates against, and a trace without it cannot answer
        "which passage was that" — which is the only question a disputed citation ever raises.

        It is a separate method from ``exclude`` rather than a second reason on it, because it
        is the opposite kind of fact: ``exclude`` records what did **not** reach the context,
        and this records what did and under what name. Collapsing them would put the packed set
        and the dropped set in one list distinguished only by a reason string.
        """
        ...

    def remaining_seconds(self) -> float:
        """Time left against the caller's deadline, on a monotonic clock."""
        ...


class Stage[InT, OutT](Protocol):
    """One stage: typed in, typed out, awaited by the runner.

    Async because most stages await either a client or a threadpool offload; a stage that
    does neither still declares async so the runner has one calling convention rather than
    two. A stage that blocks the event loop instead of offloading stalls every concurrent
    stream in the worker, including their heartbeats.
    """

    async def __call__(self, value: InT, run: StageRun, /) -> OutT: ...


def span_for(stage: str) -> str:
    """The catalogued span name for a stage.

    Raises ``KeyError`` for a stage that records no span, and for a stage name that does not
    exist. Raising is the point: the alternative is minting a plausible name at runtime,
    which succeeds, exports cleanly, and matches nothing.
    """
    return STAGE_SPANS[stage]


# Checked at import rather than in a test: a stage list that has drifted must not survive to
# a running process, because the drift is invisible in every answer the process produces.
assert len(STAGES) == 20
assert len(set(STAGES)) == 20
assert set(STAGE_SPANS) <= set(STAGES)
# Positively, against the closed domain list — not negatively against the names we do not
# want, which would put those names in this file and make them greppable as if they were in
# use. There is no span domain named after this module; the labelled counter-example lives
# in `haystack-pipelines` and is the only place those strings belong.
assert all(name.split(".")[1] in SPAN_DOMAINS for name in STAGE_SPANS.values())

# TODO(retrieval-engineer): the runner itself, plus the stage modules not yet split out —
# packing.py (stage 13, where citation labels are assigned) and prompt.py (stage 14, whose
# six sections put retrieved content in one fenced region, marked as data, ranked below the
# platform and bot instructions, and never interpolated into them).

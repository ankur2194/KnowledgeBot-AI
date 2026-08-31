"""Stage 13 — context packing, where citation labels are assigned and the window is spent.

Four rules, each of which exists because of a specific answer defect.

**The budget is computed before packing, never after.** ``model limit − reserve_output −
prompt overhead − history``. A generated answer that stops mid-sentence with no error is the
packer having eaten the window generation needed, and recent provider APIs accept an
over-budget request and truncate rather than rejecting it — so nothing raises, no metric
moves, and the only symptom is prose that trails off. :func:`compute_budget` takes all four
terms and refuses to return a number when they do not leave room, because a negative budget
silently packs nothing and reads downstream as a corpus with no answer in it.

**Order is positional, not score-descending.** Accuracy is U-shaped in packed position and
worst in the middle (Liu et al., TACL 2024), so :func:`interleave_ends` puts rank 1 first,
rank 2 **last**, and the weakest evidence in the middle where it can do the least harm. This
is also why ``rerank.retain`` stays at 6-10 rather than "everything that passed": past a
point, adding evidence makes the answer worse.

**Nothing is ever split.** Not a table, not a list, not prose. A block that does not fit is
excluded whole with ``context_budget_exhausted`` and packing *continues*, because a smaller
later block may still land. The failure this closes is a model reporting a value from the
wrong column, which is what a table cut between rows produces: the header is orphaned, the
remaining rows are unlabelled, and every number in them is still perfectly readable.

**Labels are assigned here, before the prompt exists.** ``S1``, ``S2``, ... in packed order,
fixed by :func:`app.rag.citations.assign_labels` and recorded on the trace at assignment.
They are the only identifiers the model ever sees. Attaching them afterwards collapses
citation recall roughly threefold (ALCE) and cannot be repaired downstream, because the
association never existed.

**The chunk body is not in the Qdrant payload and must be hydrated from PostgreSQL**
(ADR-010, and ``PAYLOAD_PROJECTION`` says so). Hydration arrives as an injected
:class:`PassageSource` rather than an imported database module: it keeps this stage a pure
function of its inputs, it lets the runner serve the packed subset out of what stage 11
already fetched instead of making a second round trip inside a 1.5 s budget, and it means the
unit tier can test the packer without a database. It is also a **tenant-scoped read like any
other** — a fetch keyed only on chunk ids is a cross-tenant read that reviews as one harmless
line, so the implementation the runner injects carries the organization.

**Fit is the only decision this stage makes, and that is deliberate.** §12.13 lists source
diversity, adjacency, heading context and table integrity among the inputs to packing; every
one of them was already applied — diversity and adjacency by stage 10, relevance by stage 12,
table integrity by the chunker — and re-applying one here would drop a candidate the
threshold passed under a reason no stage owns. The packer's whole authority is "does it fit".
"""

from __future__ import annotations

import math
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass, field
from typing import Final, Protocol

from app.rag.citations import PackedEvidence, assign_labels, label_for
from app.rag.rerank import Scored
from app.rag.stages import ExclusionReason, StageRun
from app.retrieval.search import Candidate

__all__ = [
    "ATOMIC_CONTENT_TYPES",
    "LOCATOR_FIELDS",
    "ContextBlock",
    "ContextBudgetUnavailable",
    "PackedContext",
    "PassageSource",
    "Selected",
    "TokenMeasure",
    "compute_budget",
    "interleave_ends",
    "pack",
    "render_block",
    "selected_from_candidates",
    "selected_from_scored",
]

#: Content types for which splitting is *provably* destructive rather than merely lossy: a
#: table cut between rows orphans its header and every remaining number reads as if it were
#: in the first column; a sheet region cut mid-row does the same; a code block cut anywhere
#: stops being runnable. The packer splits **nothing** — see the module docstring — so this
#: constant does not gate a branch. It is here so the rule has a name: a future "trim the
#: last block to fit" optimization has to explain itself against this list rather than
#: against a reviewer's memory.
#:
#: The authority for the vocabulary is ``CONTENT_TYPES`` in
#: ``app/ingestion/chunking/chunker.py``. It is restated rather than imported, so the query
#: path does not take a runtime dependency on the ingestion package for one tuple;
#: ``tests/unit/test_packing.py`` imports both and fails if they drift.
ATOMIC_CONTENT_TYPES: Final[frozenset[str]] = frozenset({"table_rows", "sheet_region", "code"})

#: Payload fields that locate a chunk for a human, in the order they are rendered. Each is in
#: ``PAYLOAD_PROJECTION``; the set is per-format and sparse — a PDF chunk has ``page`` and no
#: ``slide``, a spreadsheet chunk has ``sheet`` and ``row_range``. Rendered into the block
#: header so the model can say *where* a fact came from, and so the citation the reader opens
#: agrees with the sentence that cited it.
LOCATOR_FIELDS: Final[tuple[str, ...]] = (
    "page",
    "page_end",
    "slide",
    "sheet",
    "row_range",
    "table_ref",
    "url",
    "anchor",
)

#: The injected token estimator. **It must over-estimate**, for the same reason the chunker's
#: does: there is no local model any more (ADR-030), so there is no authoritative tokenizer —
#: the vendor tokenizes with its own algorithm over its own vocabulary, and the divergence is
#: largest on non-Latin scripts, where a ``len(text) // 4`` estimate is off by half.
#:
#: An over-estimate costs one chunk of context. An under-estimate costs the end of the answer:
#: the request is accepted, the window overflows, and the provider truncates without an error.
TokenMeasure = Callable[[str], int]


class PassageSource(Protocol):
    """Hydrate chunk bodies from PostgreSQL. Injected, tenant-scoped, and batched.

    One call for many ids rather than one call per chunk: this runs inside the retrieval leg's
    1.5 s budget, beside a reranking round trip that is already there.

    The returned mapping may be **incomplete**. A chunk present in Qdrant and absent from
    PostgreSQL is a real state — a deletion that ran between the query and the hydration — and
    it is handled by :func:`pack` rather than raised, because losing one passage of eight
    should cost a citation, not the answer.
    """

    async def __call__(self, chunk_ids: Sequence[str], /) -> Mapping[str, str]: ...


class ContextBudgetUnavailable(Exception):
    """The window does not fit the prompt, so there is no budget to pack into.

    An error and not a refusal: "the answer is not in your sources" would be a lie, since
    nothing was ever looked at. The remedy is a larger model, a shorter history window, or a
    smaller ``reserve_output`` — all configuration, all knowable before a request.
    """


@dataclass(frozen=True, slots=True)
class Selected:
    """One candidate that cleared stage 12, on either of stage 12's two paths.

    ``rerank_score`` is ``None`` on the degraded path, where stage 11 was skipped and there is
    no score of any kind. It is **not** back-filled from the fused score: RRF discards
    magnitude by construction, so a fused number in a field named ``rerank_score`` is a
    plausible float that means nothing, and it would be compared against a threshold by the
    next person who reads it.
    """

    candidate: Candidate
    rerank_score: float | None


@dataclass(frozen=True, slots=True)
class ContextBlock:
    """One rendered evidence block: header, body, and what it costs.

    ``text`` is the exact string that goes into the fenced retrieved-content region. It is
    **untrusted data** from here to the prompt and beyond; :mod:`app.rag.prompt` owns the
    fence, and nothing in this module concatenates a block into an instruction.
    """

    chunk_id: str
    label: str
    text: str
    tokens: int
    #: True when this block's content type is in :data:`ATOMIC_CONTENT_TYPES`. Carried for
    #: the trace so an operator can see that the block excluded for budget was a whole table.
    atomic: bool
    #: The passage as hydrated, without this stage's header. What a citation row shows as its
    #: excerpt: the header is ours and repeating it to a reader who clicked ``[S1]`` shows them
    #: the label they just clicked.
    body: str = ""
    #: The per-format locator fields from the payload — PDF page, slide, sheet plus row range,
    #: web URL and anchor. Carried here so stage 17 can write a citation row **without
    #: re-fetching the chunk**: a fetch addressed by chunk id alone has no tenant filter of
    #: its own, which is the one thing a citation must never need.
    locator: Mapping[str, object] = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class PackedContext:
    """Stage 13's output: labelled evidence, rendered blocks, and the budget arithmetic.

    ``evidence`` and ``blocks`` are parallel and in packed order — rank 1 first, rank 2 last.
    An empty ``evidence`` is the refusal path arriving from stage 12 and is a correct outcome,
    not an error.
    """

    evidence: tuple[PackedEvidence, ...]
    blocks: tuple[ContextBlock, ...]
    budget_tokens: int
    used_tokens: int
    #: Chunks that cleared stage 12 and had no body in PostgreSQL. **Recorded here rather
    #: than through ``run.exclude``**, following ruling D6 in ``app/rag/citations.py``:
    #: ``ExclusionReason`` is a closed contract with no member for a hydration miss, and
    #: filing one under ``context_budget_exhausted`` would put a data-plane inconsistency in
    #: the panel that tracks how much window the packer had. This field *is* the record, and
    #: the runner logs it. If every selected chunk lands here the caller refuses.
    unhydrated: tuple[str, ...] = ()


def selected_from_scored(scored: Sequence[Scored]) -> tuple[Selected, ...]:
    """The reranked path's stage 12 output, in the shape the packer takes."""
    return tuple(Selected(candidate=item.candidate, rerank_score=item.score) for item in scored)


def selected_from_candidates(candidates: Sequence[Candidate]) -> tuple[Selected, ...]:
    """The degraded path's stage 12 output. No score exists, so none is invented."""
    return tuple(Selected(candidate=candidate, rerank_score=None) for candidate in candidates)


def compute_budget(
    *,
    model_context_tokens: int,
    reserve_output: int,
    prompt_overhead_tokens: int,
    history_tokens: int,
) -> int:
    """Tokens available for evidence. **All four terms, and all of them before packing.**

    ``reserve_output`` is the one that gets forgotten, and forgetting it produces the defect
    with no error attached: the request is accepted, the window overflows, the provider
    truncates, and the answer stops one sentence short of the fact. It must cover the model's
    output *and* anything else the provider counts as input on the way — tool definitions if
    any, and thinking tokens, which are billed and windowed as output but are not the answer.

    ``prompt_overhead_tokens`` is the six sections minus the evidence region — measured from
    the rendered prompt, not estimated, which is why :mod:`app.rag.prompt` can build a prompt
    with an empty evidence region and hand its section totals back here.

    Raises :class:`ContextBudgetUnavailable` rather than returning zero or a negative number.
    Zero packs nothing and reads exactly like a corpus with no answer in it.
    """
    budget = model_context_tokens - reserve_output - prompt_overhead_tokens - history_tokens
    if budget <= 0:
        raise ContextBudgetUnavailable(
            f"no room for evidence: {model_context_tokens} context - {reserve_output} reserved "
            f"for output - {prompt_overhead_tokens} prompt - {history_tokens} history = "
            f"{budget}. This is a configuration error and not an evidence outage; refusing "
            "with 'not in your sources' here would be a lie about a corpus nobody looked at"
        )
    return budget


def interleave_ends[T](items: Sequence[T]) -> list[T]:
    """Rank 1 first, rank 2 last, the weakest in the middle.

    ``[1, 2, 3, 4, 5]`` becomes ``[1, 3, 5, 4, 2]``. Accuracy is U-shaped in packed position
    and worst in the middle (Liu et al., TACL 2024), so the two strongest passages get the two
    positions models read most reliably and the weakest sits where a miss costs least.

    Newer long-context models flatten the curve somewhat. Flattening is not removing, and
    assuming it away needs an evaluation run, not an intuition.
    """
    front: list[T] = []
    back: list[T] = []
    for position, item in enumerate(items):
        (front if position % 2 == 0 else back).append(item)
    return front + list(reversed(back))


def render_block(
    label: str,
    candidate: Candidate,
    passage: str,
    measure: TokenMeasure,
) -> ContextBlock:
    """Render one evidence block: a labelled header, a locator, and the body verbatim.

    The body is **not** reformatted, re-wrapped, or trimmed. It is the string that was
    embedded and the string the reranker scored; changing it here would mean the answer cites
    a passage that differs from the one retrieval judged, and the difference would be
    invisible in every panel.

    The header carries the label the model must cite and the locator the reader will open, so
    the two cannot drift apart. Header fields come from ``PAYLOAD_PROJECTION`` — heading path
    for context, then whichever of :data:`LOCATOR_FIELDS` this format populates.
    """
    payload = candidate.point.payload or {}
    heading = payload.get("heading_path")
    parts: list[str] = []
    if isinstance(heading, list) and heading:
        parts.append(" > ".join(str(level) for level in heading))
    elif isinstance(heading, str) and heading:
        parts.append(heading)
    locator = {
        name: payload[name]
        for name in LOCATOR_FIELDS
        if payload.get(name) is not None and payload.get(name) != ""
    }
    parts.extend(f"{name}={value}" for name, value in locator.items())

    header = f"[{label}]" if not parts else f"[{label}] {' | '.join(parts)}"
    text = f"{header}\n{passage}"
    content_type = payload.get("content_type")
    return ContextBlock(
        chunk_id=candidate.chunk_id,
        label=label,
        text=text,
        tokens=measure(text),
        atomic=isinstance(content_type, str) and content_type in ATOMIC_CONTENT_TYPES,
        body=passage,
        locator=locator,
    )


async def pack(
    selected: Sequence[Selected],
    run: StageRun,
    *,
    hydrate: PassageSource,
    measure: TokenMeasure,
    budget_tokens: int,
) -> PackedContext:
    """Stage 13. Order positionally, fit against the budget, label, record.

    The loop is deliberately the SKILL's worked example and not an optimization of it:

    * A block that does not fit is excluded with ``context_budget_exhausted`` and the loop
      **continues** — a smaller later block may still land, and stopping at the first miss
      throws away evidence that would have fit.
    * A block is never trimmed to make it fit. Nothing here splits, truncates, or re-wraps.
    * The label is ``label_for(len(packed) + 1)`` — packed position, not rank — so a block
      that did not fit does not consume an identifier and the context is numbered in the order
      the model reads it.

    ``run.exclude`` is called for **every** drop. Together with stage 12's two exclusions this
    is what makes ``selected − packed = Σ exclusions`` hold, which is the identity the
    playground's panel is only trustworthy under.

    :func:`app.rag.citations.assign_labels` is called at the end. It re-derives the same
    positional labels and, more importantly, *records* the label-to-chunk association on the
    trace at the moment it is fixed — before the prompt is built and therefore before any
    provider call.
    """
    ordered = interleave_ends(list(selected))
    passages = await hydrate([item.candidate.chunk_id for item in ordered])

    packed: list[PackedEvidence] = []
    blocks: list[ContextBlock] = []
    unhydrated: list[str] = []
    used = 0

    for item in ordered:
        chunk_id = item.candidate.chunk_id
        passage = passages.get(chunk_id)
        if not passage:
            # See PackedContext.unhydrated. Never rendered against an empty string: an empty
            # block costs header tokens, cites nothing, and gives the model a labelled source
            # with no content, which is an invitation to write what it thinks was there.
            unhydrated.append(chunk_id)
            continue

        block = render_block(label_for(len(packed) + 1), item.candidate, passage, measure)
        if used + block.tokens > budget_tokens:
            run.exclude(
                chunk_id,
                ExclusionReason.CONTEXT_BUDGET_EXHAUSTED,
                score=item.rerank_score,
            )
            continue

        payload = item.candidate.point.payload or {}
        packed.append(
            PackedEvidence(
                label=block.label,
                chunk_id=chunk_id,
                source_id=str(payload.get("source_id", "")),
                source_version_id=str(payload.get("source_version_id", "")),
                # NaN on the degraded path, where stage 11 was skipped. It is the one float
                # that cannot be mistaken for a score: every comparison against it is False,
                # so a threshold accidentally applied to it refuses rather than passes, and
                # any arithmetic on it propagates instead of averaging quietly into a number.
                rerank_score=item.rerank_score if item.rerank_score is not None else math.nan,
                # The block's cost, not the payload's `token_count`. The payload counts the
                # body as the embedder saw it; what the window actually spends is the body
                # plus this stage's header, and the budget is about the window.
                token_count=block.tokens,
            )
        )
        blocks.append(block)
        used += block.tokens

    labelled = assign_labels(packed, run)
    # `assign_labels` numbers by packed position and so does the loop above, so these agree by
    # construction. Asserted rather than assumed because the two are the same fact written in
    # two places, and if they ever disagree the header the model reads cites one chunk while
    # the trace resolves the citation to another — a plausible answer with a plausible link to
    # the wrong passage, which is the failure `citations.py` is arranged around.
    if tuple(item.label for item in labelled) != tuple(block.label for block in blocks):
        raise ValueError(
            "the labels rendered into the evidence blocks disagree with the labels recorded "
            "on the trace. Both are positional; a disagreement means one of them stopped "
            "being"
        )

    return PackedContext(
        evidence=labelled,
        blocks=tuple(blocks),
        budget_tokens=budget_tokens,
        used_tokens=used,
        unhydrated=tuple(unhydrated),
    )

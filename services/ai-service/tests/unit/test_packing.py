"""Stage 13 — the budget, the order, atomicity, and the identity the panel depends on.

Four defects, none of which raises anything:

* **The answer stops mid-sentence.** ``reserve_output`` was not subtracted before packing. The
  provider accepts the over-budget request and truncates.
* **The model reports a value from the wrong column.** A table was cut between rows; the header
  is orphaned and every remaining number is still perfectly readable.
* **Adding evidence made the answer worse.** Packed order was score-descending, so the middle of
  the context — where models lose facts — got the second-best passage instead of the weakest.
* **The playground shows fewer candidates than were selected, with no row saying why.** A drop
  that did not call ``run.exclude``. ``selected − packed = Σ exclusions`` is the identity, and
  ``above_retain_limit`` is the one that gets forgotten because ``[:retain]`` reads as a slice.

The hydration dependency is injected, so nothing here needs PostgreSQL: ``FakePassages`` is a
dict with an ``await`` in front of it, and it also records what was asked for, which is how the
"never scored against an empty string" rule is checked.
"""

from __future__ import annotations

import math
from collections.abc import Mapping, Sequence
from typing import Final

import pytest
from qdrant_client import models

from app.rag.evidence import RERANK_RETAIN, apply_threshold
from app.rag.packing import (
    ATOMIC_CONTENT_TYPES,
    ContextBudgetUnavailable,
    Selected,
    compute_budget,
    interleave_ends,
    pack,
    render_block,
    selected_from_candidates,
    selected_from_scored,
)
from app.rag.rerank import RerankCalibration, RerankOutcome, RerankScale, Scored
from app.rag.stages import ExclusionReason
from app.retrieval.search import PAYLOAD_PROJECTION, Candidate


#: One token per whitespace-delimited word, and it OVER-estimates, which is the contract for an
#: injected measure: an under-estimate is the mid-sentence truncation this stage exists to
#: prevent. Word counting over-estimates for English and under-estimates for nothing here,
#: because every fixture below is ASCII and the arithmetic has to be readable in the assertions.
def measure(text: str) -> int:
    return len(text.split())


CALIBRATION: Final = RerankCalibration(
    provider="fake",
    model="rank-1",
    scale=RerankScale.SIGMOID,
    min_score=0.40,
    max_passage_tokens=512,
    derived_from="eval-run-test",
)


class RecordingRun:
    """A ``StageRun`` that remembers every drop and every label assignment."""

    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason, float | None]] = []
        self.labels: dict[str, str] = {}

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason, score))

    def record_labels(self, labels: Mapping[str, str]) -> None:
        self.labels = dict(labels)

    def remaining_seconds(self) -> float:
        return 10.0

    def chunks_for(self, reason: ExclusionReason) -> list[str]:
        return [chunk_id for chunk_id, seen, _ in self.excluded if seen is reason]


class FakePassages:
    """The injected hydration source. A dict, plus a record of what was asked for."""

    def __init__(self, bodies: Mapping[str, str]) -> None:
        self.bodies = dict(bodies)
        self.asked: list[tuple[str, ...]] = []

    async def __call__(self, chunk_ids: Sequence[str], /) -> Mapping[str, str]:
        self.asked.append(tuple(chunk_ids))
        return {cid: self.bodies[cid] for cid in chunk_ids if cid in self.bodies}


def candidate(chunk_id: str, **payload: object) -> Candidate:
    return Candidate(
        point=models.ScoredPoint(
            id="0198f2b0-0000-7000-8000-000000000000",
            version=0,
            score=0.5,
            payload={
                "chunk_id": chunk_id,
                "source_id": "src-1",
                "source_version_id": "ver-1",
                **payload,
            },
        )
    )


def selected(*chunk_ids: str, **payload: object) -> tuple[Selected, ...]:
    return tuple(
        Selected(candidate=candidate(chunk_id, **payload), rerank_score=0.9)
        for chunk_id in chunk_ids
    )


# ── the budget, computed before packing ──────────────────────────────────────


def test_reserve_output_is_subtracted_before_packing() -> None:
    """The proof is a budget that would otherwise have room.

    Without ``reserve_output`` the arithmetic is 1000 - 50 - 50 = 900 and a 200-token block
    fits comfortably. With it, the budget is 100 and the same block does not.
    """
    budget = compute_budget(
        model_context_tokens=1000,
        reserve_output=800,
        prompt_overhead_tokens=50,
        history_tokens=50,
    )
    assert budget == 100


async def test_a_block_that_only_fits_without_the_reserve_is_excluded() -> None:
    run = RecordingRun()
    budget = compute_budget(
        model_context_tokens=1000, reserve_output=800, prompt_overhead_tokens=50, history_tokens=50
    )
    body = " ".join(["word"] * 200)

    packed = await pack(
        selected("c1"),
        run,
        hydrate=FakePassages({"c1": body}),
        measure=measure,
        budget_tokens=budget,
    )

    assert packed.evidence == ()
    assert run.chunks_for(ExclusionReason.CONTEXT_BUDGET_EXHAUSTED) == ["c1"]


def test_a_window_with_no_room_for_evidence_raises_rather_than_returning_zero() -> None:
    """Zero packs nothing and reads downstream exactly like a corpus with no answer in it."""
    with pytest.raises(ContextBudgetUnavailable, match="no room for evidence"):
        compute_budget(
            model_context_tokens=1000,
            reserve_output=900,
            prompt_overhead_tokens=80,
            history_tokens=40,
        )


# ── positional order ─────────────────────────────────────────────────────────


def test_interleave_puts_rank_one_first_and_rank_two_last() -> None:
    """Liu et al.: accuracy is U-shaped in packed position and worst in the middle."""
    assert interleave_ends([1, 2, 3, 4, 5]) == [1, 3, 5, 4, 2]


async def test_packing_order_really_is_rank_one_first_and_rank_two_last() -> None:
    run = RecordingRun()
    bodies = {f"c{i}": f"body {i}" for i in range(1, 6)}

    packed = await pack(
        selected("c1", "c2", "c3", "c4", "c5"),
        run,
        hydrate=FakePassages(bodies),
        measure=measure,
        budget_tokens=1000,
    )

    order = [evidence.chunk_id for evidence in packed.evidence]
    assert order[0] == "c1"
    assert order[-1] == "c2"
    assert order == ["c1", "c3", "c5", "c4", "c2"]


async def test_labels_follow_packed_position_and_never_rank() -> None:
    """The model reads the context in packed order; a rank-derived label numbers it out of
    sequence and the identifiers it reproduces stop matching what it read."""
    run = RecordingRun()
    packed = await pack(
        selected("c1", "c2", "c3"),
        run,
        hydrate=FakePassages({f"c{i}": f"body {i}" for i in (1, 2, 3)}),
        measure=measure,
        budget_tokens=1000,
    )
    assert [(e.label, e.chunk_id) for e in packed.evidence] == [
        ("S1", "c1"),
        ("S2", "c3"),
        ("S3", "c2"),
    ]
    assert run.labels == {"S1": "c1", "S2": "c3", "S3": "c2"}


async def test_labels_are_recorded_on_the_trace_at_assignment() -> None:
    """Before the prompt is built and therefore before any provider call."""
    run = RecordingRun()
    await pack(
        selected("c1"),
        run,
        hydrate=FakePassages({"c1": "body"}),
        measure=measure,
        budget_tokens=1000,
    )
    assert run.labels == {"S1": "c1"}


# ── atomicity ────────────────────────────────────────────────────────────────


async def test_an_oversized_table_is_excluded_whole_and_never_split() -> None:
    """A table cut between rows orphans its header, and every number left behind still reads
    as if it were in the first column."""
    run = RecordingRun()
    table = "| model | price |\n" + "\n".join(f"| XR-{i} | {i}00 |" for i in range(40))
    hydrate = FakePassages({"table": table, "small": "a short prose chunk"})
    items = (
        Selected(candidate=candidate("table", content_type="table_rows"), rerank_score=0.9),
        Selected(candidate=candidate("small", content_type="prose"), rerank_score=0.8),
    )

    packed = await pack(items, run, hydrate=hydrate, measure=measure, budget_tokens=30)

    joined = "\n".join(block.text for block in packed.blocks)
    assert "XR-0" not in joined
    assert "| model | price |" not in joined
    assert run.chunks_for(ExclusionReason.CONTEXT_BUDGET_EXHAUSTED) == ["table"]


async def test_a_block_that_does_not_fit_does_not_stop_the_loop() -> None:
    """A smaller later block may still land — stopping at the first miss throws away evidence."""
    run = RecordingRun()
    hydrate = FakePassages({"big": " ".join(["word"] * 100), "small": "tiny"})
    items = (
        Selected(candidate=candidate("big"), rerank_score=0.9),
        Selected(candidate=candidate("small"), rerank_score=0.8),
    )

    packed = await pack(items, run, hydrate=hydrate, measure=measure, budget_tokens=20)

    assert [e.chunk_id for e in packed.evidence] == ["small"]
    assert run.chunks_for(ExclusionReason.CONTEXT_BUDGET_EXHAUSTED) == ["big"]


def test_the_atomic_vocabulary_agrees_with_the_chunker() -> None:
    """``ATOMIC_CONTENT_TYPES`` is restated in the query path rather than imported from
    ingestion, so the drift check lives here, where importing both is free."""
    from app.ingestion.chunking.chunker import CONTENT_TYPES

    assert set(CONTENT_TYPES) >= ATOMIC_CONTENT_TYPES


# ── the exclusion identity ───────────────────────────────────────────────────


async def test_selected_minus_packed_is_fully_accounted_for_by_exclusions() -> None:
    """§8.24's requirement, as arithmetic. A drop that does not record makes the panel lie."""
    run = RecordingRun()
    bodies = {"a": " ".join(["word"] * 50), "b": "short", "c": " ".join(["word"] * 50)}

    packed = await pack(
        selected("a", "b", "c"),
        run,
        hydrate=FakePassages(bodies),
        measure=measure,
        budget_tokens=15,
    )

    assert 3 - len(packed.evidence) == len(run.excluded)


async def test_the_retain_cap_drops_candidates_and_each_one_is_recorded() -> None:
    """The reranked count minus the packed count is fully accounted for by exclusion reasons,
    and the cap's own reason is present rather than the panel simply showing fewer rows."""
    run = RecordingRun()
    over = RERANK_RETAIN + 3
    scored = tuple(
        Scored(candidate=candidate(f"c{i}"), score=0.9 - i * 0.001, scale=RerankScale.SIGMOID)
        for i in range(over)
    )
    outcome = RerankOutcome(skipped=None, calibration=CALIBRATION, scored=scored)

    passing = apply_threshold(outcome, run, retain=RERANK_RETAIN)
    packed = await pack(
        selected_from_scored(passing),
        run,
        hydrate=FakePassages({f"c{i}": "body" for i in range(over)}),
        measure=measure,
        budget_tokens=10_000,
    )

    dropped = run.chunks_for(ExclusionReason.ABOVE_RETAIN_LIMIT)
    assert len(dropped) == 3
    assert over - len(packed.evidence) == len(run.excluded)
    assert set(dropped).isdisjoint({e.chunk_id for e in packed.evidence})


# ── hydration, injected and incomplete ───────────────────────────────────────


async def test_an_unhydrated_chunk_is_recorded_and_never_rendered_empty() -> None:
    """A chunk in Qdrant and gone from PostgreSQL is a real state — a deletion that ran between
    the query and the hydration. Rendering it as a labelled source with no content is an
    invitation to write what the model thinks was there."""
    run = RecordingRun()

    packed = await pack(
        selected("present", "missing"),
        run,
        hydrate=FakePassages({"present": "body"}),
        measure=measure,
        budget_tokens=1000,
    )

    assert [e.chunk_id for e in packed.evidence] == ["present"]
    assert packed.unhydrated == ("missing",)
    # Not filed under a budget reason: `ExclusionReason` is closed and has no member for a
    # hydration miss, so `PackedContext.unhydrated` IS the record (ruling D6).
    assert run.excluded == []


async def test_hydration_is_one_batched_call() -> None:
    """One round trip for many ids, inside a leg that already contains a ranking round trip."""
    hydrate = FakePassages({f"c{i}": "body" for i in range(5)})
    await pack(
        selected("c0", "c1", "c2", "c3", "c4"),
        RecordingRun(),
        hydrate=hydrate,
        measure=measure,
        budget_tokens=1000,
    )
    assert len(hydrate.asked) == 1


# ── the degraded path has no score, and none is invented ─────────────────────


async def test_the_unranked_path_carries_no_score_rather_than_the_fused_one() -> None:
    """RRF discards magnitude, so a fused number in a field named ``rerank_score`` is a
    plausible float that means nothing. NaN is the one value no threshold accepts."""
    run = RecordingRun()
    packed = await pack(
        selected_from_candidates([candidate("c1")]),
        run,
        hydrate=FakePassages({"c1": "body"}),
        measure=measure,
        budget_tokens=1000,
    )
    score = packed.evidence[0].rerank_score
    assert math.isnan(score)
    assert not score >= 0.0


# ── rendering ────────────────────────────────────────────────────────────────


def test_the_block_header_carries_the_label_and_the_locator() -> None:
    """The label the model must cite and the locator the reader opens are rendered together,
    so they cannot drift apart."""
    block = render_block(
        "S1",
        candidate("c1", heading_path=["Warranty", "Coverage"], page=4, content_type="prose"),
        "accidental damage is covered.",
        measure,
    )
    header = block.text.splitlines()[0]
    assert header.startswith("[S1]")
    assert "Warranty > Coverage" in header
    assert "page=4" in header
    assert block.body == "accidental damage is covered."
    assert block.locator == {"page": 4}


def test_the_body_is_rendered_verbatim() -> None:
    """It is the string that was embedded and the string the reranker scored. Reformatting it
    means the answer cites a passage that differs from the one retrieval judged."""
    body = "  ragged   spacing\nand a newline  "
    assert render_block("S1", candidate("c1"), body, measure).body == body


def test_every_locator_field_is_projected_by_the_query_path() -> None:
    """A locator field the query never asks Qdrant for is a citation that cannot be opened."""
    from app.rag.packing import LOCATOR_FIELDS

    assert set(LOCATOR_FIELDS) <= set(PAYLOAD_PROJECTION)

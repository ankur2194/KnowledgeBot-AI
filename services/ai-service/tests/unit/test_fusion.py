"""Stage 9 — reciprocal rank fusion, and the seam that feeds it.

Three properties, each guarding a failure that produces a plausible ordering rather than an
error:

* **Only ranks enter the sum.** Branch scores ride along for the trace and the playground and
  are never fused. The moment a magnitude enters, the fused score starts to look thresholdable
  — and a threshold there is the bug ``app/rag/evidence.py`` is shaped around.
* **Ties break on chunk identity.** RRF produces exact ties constantly, two branches iterate in
  ``dict`` order, and an order-dependent sort makes the packed set a function of which branch
  happened to be first in a mapping. A replay of the trace then packs a different set.
* **Zero branches is a programming error.** An empty result means retrieval ran and matched
  nothing; no branches at all means retrieval was never issued, and returning ``[]`` renders
  that as a corpus with no answer in it.
"""

from __future__ import annotations

import pytest

from app.rag.evidence import RRF_K, as_candidates, fuse
from app.retrieval.search import Candidate
from tests.support.fake_qdrant import point


def rrf(*ranks: int) -> float:
    return sum(1.0 / (RRF_K + rank) for rank in ranks)


def candidates(*chunk_ids_and_scores: tuple[str, float]) -> list[Candidate]:
    return [Candidate(point=point(cid, score)) for cid, score in chunk_ids_and_scores]


# ── the seam: one point becomes one candidate, and nothing else happens ──────


def test_the_seam_wraps_every_point_in_every_branch() -> None:
    wrapped = as_candidates(
        {"dense": [point("a", 0.9), point("b", 0.4)], "sparse": [point("b", 7.0)]}
    )
    assert {name: [c.chunk_id for c in items] for name, items in wrapped.items()} == {
        "dense": ["a", "b"],
        "sparse": ["b"],
    }


def test_the_seam_does_not_merge_and_does_not_rank() -> None:
    """Merging here would be stage 9 written twice, in two places, with two tie-break rules.

    Pooling by chunk identity *is* fusion; a wrapper that also pooled would mean the pipeline
    had two answers to "which candidate object represents chunk b".
    """
    wrapped = as_candidates({"dense": [point("b", 0.4)], "sparse": [point("b", 7.0)]})
    dense_b, sparse_b = wrapped["dense"][0], wrapped["sparse"][0]
    assert dense_b is not sparse_b
    for candidate in (dense_b, sparse_b):
        assert candidate.dense_rank is None
        assert candidate.sparse_rank is None
        assert candidate.fused_score == 0.0


def test_a_point_with_no_chunk_id_fails_at_the_seam_and_not_three_stages_later() -> None:
    """A ``None`` becomes the string ``"None"`` in a trace and groups every unidentifiable
    candidate into one playground row. It also cannot be deduped, excluded with a reason, or
    cited, so there is nothing to be gained by carrying it further."""
    from qdrant_client import models

    orphan = models.ScoredPoint(id=1, version=1, score=0.5, payload={"source_id": "s1"})
    with pytest.raises(ValueError, match="no chunk_id"):
        as_candidates({"dense": [orphan]})


# ── the arithmetic ───────────────────────────────────────────────────────────


def test_ranks_are_one_indexed() -> None:
    """Zero-indexed ranks make the top hit ``1/k`` instead of ``1/(k+1)``, which is not a
    rounding difference — it changes the gap between rank 1 and rank 2 by the most of anywhere
    in the curve."""
    fused = fuse({"dense": candidates(("a", 0.9))})
    assert fused[0].fused_score == pytest.approx(rrf(1))


def test_a_candidate_found_by_both_branches_sums_both_reciprocals() -> None:
    fused = fuse(
        {
            "dense": candidates(("a", 0.9), ("b", 0.4)),
            "sparse": candidates(("b", 7.0), ("a", 3.0)),
        }
    )
    by_id = {candidate.chunk_id: candidate for candidate in fused}
    assert by_id["a"].fused_score == pytest.approx(rrf(1, 2))
    assert by_id["b"].fused_score == pytest.approx(rrf(2, 1))


def test_a_candidate_absent_from_a_branch_contributes_zero_for_it() -> None:
    fused = fuse({"dense": candidates(("a", 0.9)), "sparse": candidates(("b", 7.0))})
    by_id = {candidate.chunk_id: candidate for candidate in fused}
    assert by_id["a"].fused_score == pytest.approx(rrf(1))
    assert by_id["a"].sparse_rank is None
    assert by_id["a"].sparse_score is None


def test_the_branch_scores_are_carried_and_never_summed() -> None:
    """**The magnitude test.** Both branches return wildly different score scales — cosine on
    one side, a BM25 dot product on the other — and the fused number must not move by a
    thousandth in response. If it ever does, the fused score has acquired a magnitude and
    somebody will eventually threshold on it.
    """
    modest = fuse(
        {"dense": candidates(("a", 0.01)), "sparse": candidates(("a", 0.02))},
    )
    enormous = fuse(
        {"dense": candidates(("a", 0.99)), "sparse": candidates(("a", 987.6))},
    )
    assert modest[0].fused_score == enormous[0].fused_score
    assert enormous[0].dense_score == pytest.approx(0.99)
    assert enormous[0].sparse_score == pytest.approx(987.6)


def test_per_branch_ranks_survive_fusion_because_the_playground_needs_them() -> None:
    """A server-fused response carries one score per point and discards these. Keeping them is
    the entire reason fusion happens in Python."""
    fused = fuse(
        {
            "dense": candidates(("a", 0.9), ("b", 0.4)),
            "sparse": candidates(("b", 7.0)),
        }
    )
    by_id = {candidate.chunk_id: candidate for candidate in fused}
    assert (by_id["a"].dense_rank, by_id["a"].sparse_rank) == (1, None)
    assert (by_id["b"].dense_rank, by_id["b"].sparse_rank) == (2, 1)


def test_the_fusion_constant_is_ours_and_is_sixty() -> None:
    """The client's own default is 2, at which the rank-1 hit of each branch dominates about
    thirty times more sharply than the literature default. The symptom is hybrid search
    suddenly preferring whatever the sparse branch returned first."""
    assert RRF_K == 60


def test_the_constant_is_overridable_for_the_evaluation_harness() -> None:
    fused = fuse({"dense": candidates(("a", 0.9))}, k=10)
    assert fused[0].fused_score == pytest.approx(1.0 / 11)


# ── ordering, and the tie-break that makes a replay reproducible ─────────────


def test_the_output_is_ordered_by_descending_fused_score() -> None:
    fused = fuse(
        {
            "dense": candidates(("a", 0.9), ("b", 0.8), ("c", 0.7)),
            "sparse": candidates(("c", 5.0)),
        }
    )
    assert [candidate.chunk_id for candidate in fused] == ["c", "a", "b"]


def test_exact_ties_break_on_chunk_id_and_not_on_branch_iteration_order() -> None:
    """Two mappings with the same content in the opposite order must fuse identically.

    Ties are not an edge case here: every candidate found at the same rank in one branch and
    absent from the other scores identically, which on a 20-deep branch is most of them.
    """
    first = fuse({"dense": candidates(("b", 0.9)), "sparse": candidates(("a", 9.0))})
    second = fuse({"sparse": candidates(("a", 9.0)), "dense": candidates(("b", 0.9))})
    assert [c.chunk_id for c in first] == [c.chunk_id for c in second] == ["a", "b"]


def test_the_same_input_fuses_to_the_same_order_twice() -> None:
    branches = {
        "dense": candidates(("m", 0.9), ("n", 0.8), ("o", 0.7)),
        "sparse": candidates(("o", 5.0), ("n", 4.0), ("m", 3.0)),
    }
    assert [c.chunk_id for c in fuse(branches)] == [c.chunk_id for c in fuse(branches)]


# ── the degenerate and the impossible ────────────────────────────────────────


def test_one_branch_is_valid_and_produces_the_branch_ordering() -> None:
    """The stage is not skipped on a dense-only run: the fused field is still populated so the
    trace has the same shape as any other. What must not happen is anything downstream reading
    that number as evidence of agreement, because there was nothing to agree with."""
    fused = fuse({"dense": candidates(("a", 0.9), ("b", 0.8))})
    assert [candidate.chunk_id for candidate in fused] == ["a", "b"]
    assert all(candidate.sparse_rank is None for candidate in fused)


def test_a_branch_that_matched_nothing_is_not_an_error() -> None:
    fused = fuse({"dense": candidates(("a", 0.9)), "sparse": []})
    assert [candidate.chunk_id for candidate in fused] == ["a"]


def test_zero_branches_raises_rather_than_returning_an_empty_list() -> None:
    with pytest.raises(ValueError, match="never issued"):
        fuse({})


def test_a_branch_name_the_collection_does_not_have_raises() -> None:
    """A third key is a branch nothing downstream knows how to read a rank from, so it would be
    fused into the score and then be invisible in every panel."""
    with pytest.raises(ValueError, match="two named"):
        fuse({"colbert": candidates(("a", 0.9))})  # type: ignore[dict-item]

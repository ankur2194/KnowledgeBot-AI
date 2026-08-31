"""Stage 5 — the entity guard, and the three things a rewrite may never change.

The defect the guard exists for leaves no trace: "does it cover accidental damage?" is rewritten
to "does the warranty cover accidental damage?", the product code is gone, retrieval genuinely
succeeds against generic marketing pages, every panel looks healthy, and the answer is about a
different product. Nothing raises and no metric moves.

So the guard is asserted here on *behaviour*, on a rewriter this module never wrote: a fake
rewriter returns whatever the test wants, including the exact rewrite the contract names.
"""

from __future__ import annotations

from collections.abc import Sequence

import pytest

from app.rag.rewrite import (
    FACET_KEYS,
    Rewrite,
    RewriteFallback,
    RewriteProposal,
    entities_lost,
    guard_entities,
    retrieval_query,
    rewrite_query,
)

ORIGINAL = "does the XR-400B cover accidental damage?"
HISTORY = ("user: tell me about the XR-400B", "bot: it is a mid-range unit.")


class FakeRewriter:
    """Returns a fixed proposal and records that it was asked.

    Deliberately not a mock: the thing under test is what the guard does with a *value*, and a
    mock that also asserts on the call would test the harness.
    """

    def __init__(self, proposal: RewriteProposal | None) -> None:
        self.proposal = proposal
        self.calls: list[tuple[str, tuple[str, ...]]] = []

    async def __call__(self, question: str, history: Sequence[str], /) -> RewriteProposal | None:
        self.calls.append((question, tuple(history)))
        return self.proposal


class ExplodingRewriter:
    """A rewriter outage. The contract is that it propagates rather than becoming a fallback."""

    async def __call__(self, question: str, history: Sequence[str], /) -> RewriteProposal | None:
        raise RuntimeError("provider 503")


# ── the entity guard ─────────────────────────────────────────────────────────


async def test_a_rewrite_that_drops_a_product_code_falls_back_and_records_it() -> None:
    """The named failure from the contract, end to end.

    "does the XR-400B cover accidental damage?" must not become "does the warranty cover
    accidental damage?" — and when it does, the rewrite is discarded whole, retrieval runs on
    the original, and the fallback is on the trace with the token that went missing.
    """
    rewriter = FakeRewriter(RewriteProposal(query="does the warranty cover accidental damage?"))

    result = await rewrite_query(ORIGINAL, HISTORY, rewriter, enabled=True)

    assert result.fallback is RewriteFallback.ENTITY_DROPPED
    assert result.rewritten_query is None
    assert result.retrieval_query == ORIGINAL
    assert "XR-400B" in result.dropped_entities


async def test_a_rewrite_that_keeps_the_code_is_used() -> None:
    rewriter = FakeRewriter(RewriteProposal(query="does the XR-400B cover accidental damage?"))
    follow_up = "does it cover accidental damage?"

    result = await rewrite_query(follow_up, HISTORY, rewriter, enabled=True)

    assert result.fallback is None
    assert result.rewritten_query == "does the XR-400B cover accidental damage?"


def test_the_guard_is_callable_without_running_the_stage() -> None:
    """The playground and the eval harness ask the same question without a provider."""
    assert guard_entities(ORIGINAL, RewriteProposal(query="does the warranty cover it?")) == (
        "400",
        "XR",
        "XR-400B",
    )


def test_a_casing_change_is_not_an_entity_drop() -> None:
    """The lexical arm case-folds, so ``XR-400B`` -> ``xr-400b`` loses no retrieval.

    Failing here would discard a good rewrite over a formatting difference, and a guard that
    fires on formatting is a guard someone switches off.
    """
    assert entities_lost(ORIGINAL, "does the xr-400b cover accidental damage?") == ()


def test_an_inflected_code_still_counts_as_surviving() -> None:
    assert entities_lost("what is the XR-400B price?", "what is the XR-400B's price?") == ()


# ── intent immutability, expressed as what the output carries ────────────────


async def test_both_queries_are_stored() -> None:
    """§12.5 requires both. The original is what generation is shown."""
    rewriter = FakeRewriter(RewriteProposal(query="does the XR-400B cover accidental damage?"))
    result = await rewrite_query(
        "does it cover accidental damage?", HISTORY, rewriter, enabled=True
    )
    assert result.original_query == "does it cover accidental damage?"
    assert result.rewritten_query == "does the XR-400B cover accidental damage?"


def test_retrieval_runs_on_the_original_concatenated_with_the_rewrite() -> None:
    """Never the rewrite alone: the user's own phrasing is what the lexical branch scores on,
    and the concatenation is the containment on a bad rewrite that cleared the guard."""
    combined = retrieval_query("does it cover damage?", "does the XR-400B cover damage?")
    assert combined.startswith("does it cover damage?")
    assert "XR-400B" in combined


def test_a_rewrite_identical_to_the_original_is_not_concatenated_with_itself() -> None:
    assert retrieval_query(ORIGINAL, ORIGINAL) == ORIGINAL


def test_no_rewrite_means_the_original_is_the_retrieval_query() -> None:
    assert retrieval_query(ORIGINAL, None) == ORIGINAL


# ── the fallbacks, and the one that deliberately does not exist ──────────────


async def test_disabled_is_a_configuration_and_still_produces_a_recorded_outcome() -> None:
    """``rewrite.enabled = false`` is config-off, not a branch that jumps: the stage still
    produces a ``Rewrite`` with a reason on it."""
    result = await rewrite_query(ORIGINAL, HISTORY, FakeRewriter(None), enabled=False)
    assert result.fallback is RewriteFallback.DISABLED
    assert result.retrieval_query == ORIGINAL


async def test_a_first_turn_is_not_rewritten() -> None:
    """There is nothing to resolve against, so every word a rewrite added would be invented."""
    rewriter = FakeRewriter(RewriteProposal(query="anything at all"))
    result = await rewrite_query(ORIGINAL, (), rewriter, enabled=True)
    assert result.fallback is RewriteFallback.NO_HISTORY
    assert rewriter.calls == []


async def test_a_turn_that_needs_no_retrieval_does_not_spend_a_round_trip() -> None:
    rewriter = FakeRewriter(RewriteProposal(query="anything at all"))
    result = await rewrite_query("thanks", HISTORY, rewriter, enabled=True, needs_retrieval=False)
    assert result.fallback is RewriteFallback.NOT_NEEDED
    assert rewriter.calls == []


async def test_an_empty_proposal_is_no_rewrite_rather_than_an_empty_query() -> None:
    result = await rewrite_query(
        ORIGINAL, HISTORY, FakeRewriter(RewriteProposal(query="  ")), enabled=True
    )
    assert result.fallback is RewriteFallback.NO_REWRITE_PRODUCED
    assert result.retrieval_query == ORIGINAL


async def test_a_rewriter_outage_is_not_a_fallback() -> None:
    """``RewriteFallback`` is closed and failure-free, like ``RerankSkipReason``.

    A provider outage filed as a degraded mode is an incident laundered into a configuration:
    the metric shows a rewrite that was "disabled" and nobody goes looking for the 503.
    """
    with pytest.raises(RuntimeError, match="503"):
        await rewrite_query(ORIGINAL, HISTORY, ExplodingRewriter(), enabled=True)

    assert not any("fail" in member.value or "error" in member.value for member in RewriteFallback)


# ── facets are advisory and closed ───────────────────────────────────────────


async def test_an_unknown_facet_from_a_model_is_dropped_not_raised() -> None:
    rewriter = FakeRewriter(
        RewriteProposal(
            query="does the XR-400B cover accidental damage?",
            facets={"product": "XR-400B", "org_id": "other-org"},
        )
    )
    result = await rewrite_query(
        "does it cover accidental damage?", HISTORY, rewriter, enabled=True
    )
    assert dict(result.facets) == {"product": "XR-400B"}
    assert "org_id" not in FACET_KEYS


def test_a_facet_key_invented_by_our_own_code_raises() -> None:
    """A model inventing a facet name is Tuesday; the runner or the playground doing it is a bug."""
    with pytest.raises(ValueError, match="FACET_KEYS"):
        Rewrite(
            original_query=ORIGINAL,
            rewritten_query=None,
            retrieval_query=ORIGINAL,
            facets={"org_id": "org-a"},
            fallback=RewriteFallback.DISABLED,
        )


def test_a_discarded_rewrite_cannot_be_left_in_the_field_named_rewritten_query() -> None:
    """It would read, in an evaluation replay, as the query retrieval actually ran on."""
    with pytest.raises(ValueError, match="either in force"):
        Rewrite(
            original_query=ORIGINAL,
            rewritten_query="does the warranty cover accidental damage?",
            retrieval_query=ORIGINAL,
            facets={},
            fallback=RewriteFallback.ENTITY_DROPPED,
            dropped_entities=("XR-400B",),
        )

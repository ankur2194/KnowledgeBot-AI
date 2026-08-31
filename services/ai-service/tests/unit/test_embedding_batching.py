"""`plan_batches` and `embed_passages` — the two functions that spend a tenant's embedding
budget and decide what lands in the index.

Everything here is exercised against a fake `EmbedCallable`, and that is not a shortcut. ADR-030
forbids a local embedder, so there is no model to run and nothing to fake at a lower level than
the call itself; the Protocol exists precisely so this file can be complete before a binder does.

The failures under test are the ones a green run hides:

* **A trimmed passage.** Never here. An over-length chunk raises from `plan_batches` before a
  request is built, because sizing is the chunker's job and a defensive trim turns a loud
  chunker bug into a chunk whose tail is unsearchable forever.
* **A short batch.** A provider returning 63 vectors for 64 texts shifts every subsequent
  chunk's vector onto the wrong chunk. The index is then fully populated, fully wrong, counts
  correctly, and verification passes.
* **A vector that cannot be used.** A zero or non-finite vector upserts cleanly and then ranks
  against nothing — `NaN` compares false against every threshold, so the chunk is dropped from
  every result set with no error anywhere.
* **An empty sparse vector.** Accepted by Qdrant, matches nothing forever, halves the hybrid arm
  for that chunk. `None` is the recorded decision; empty is the silent one.
"""

from __future__ import annotations

import itertools
import math
from collections.abc import Sequence
from typing import Final

import pytest

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.chunking.chunker import MAX_TOKENS
from app.ingestion.embedding import embedder
from app.ingestion.embedding.embedder import (
    CANARY_INPUT_TYPE,
    MAX_BATCH_TEXTS,
    NORMALIZE_EMBEDDINGS,
    ChunkVectors,
    EmbeddingModelIdentity,
    embed_passages,
    plan_batches,
)
from app.providers.contract import Diagnostics, EmbeddingInputType, EmbeddingResult, Usage
from app.retrieval import sparse as sparse_module
from app.retrieval.collection import EmbeddingSpace

_WIDTH: Final[int] = 4
_ORG: Final[str] = "01JBQ0000000000000000000AA"
_TRACE: Final[str] = "trace-2b"


def _space(dimensions: int = _WIDTH) -> EmbeddingSpace:
    return EmbeddingSpace(provider="openai", model="probe-model", dimensions=dimensions)


def _identity(digest: str = "9f2a1c4e77b1") -> EmbeddingModelIdentity:
    return EmbeddingModelIdentity(space=_space(), canary_digest=digest)


def _texts(total: int) -> list[str]:
    return [f"chunk {index} body text" for index in range(total)]


class _FakeEmbed:
    """A stand-in for the bound provider callable.

    The vector it returns for the text at document position `i` is `[1, i + 1, 0, 0]`, whose
    *direction* survives L2 normalization. That is what lets a test tell whether vector `k` came
    back attached to chunk `k` across a batch boundary — a mismatch there is the one defect that
    produces a fully populated index and raises nothing.
    """

    def __init__(
        self,
        *,
        space: EmbeddingSpace | None = None,
        drop_last: bool = False,
        override: Sequence[Sequence[float]] | None = None,
    ) -> None:
        self.space = space or _space()
        self.drop_last = drop_last
        self.override = override
        self.calls: list[dict[str, object]] = []
        self.position = 0

    def __call__(
        self,
        texts: list[str],
        *,
        input_type: EmbeddingInputType,
        org_id: str,
        trace_id: str,
    ) -> EmbeddingResult:
        self.calls.append(
            {
                "texts": list(texts),
                "input_type": input_type,
                "org_id": org_id,
                "trace_id": trace_id,
            }
        )
        if self.override is not None:
            vectors = [list(vector) for vector in self.override]
        else:
            vectors = [
                [1.0, float(self.position + offset + 1), 0.0, 0.0] for offset in range(len(texts))
            ]
        self.position += len(texts)
        if self.drop_last:
            vectors = vectors[:-1]
        return EmbeddingResult(
            vectors=vectors,
            space=self.space,
            normalized=False,
            usage=Usage(input_tokens=len(texts)),
            total_ms=1,
            diagnostics=Diagnostics(provider=self.space.provider),
        )


def _short(text: str) -> int:
    """A measure every fixture text passes."""
    return 10


# THE `_whitespace_analyzer` AUTOUSE FIXTURE IS GONE.
#
# It patched `app/retrieval/sparse.py:tokenize` because that function raised
# `NotImplementedError` — the analyzer was an open evaluation question and the fixture's own
# docstring said so. The analyzer decision closed it, so the real analyzer runs here now and one
# fewer thing
# in this file is a fake. Nothing else about the fixture's argument changes: the point was
# always that the *real* encoder should execute — term ids, BM25 passage weights, the sorted
# `SparseVector` and the `EmptySparsePassage` raise — and now the segmentation is real too.


# ── plan_batches ─────────────────────────────────────────────────────────────


def test_no_texts_is_no_batches() -> None:
    assert plan_batches([], _short) == []


def test_ranges_are_half_open_contiguous_and_cover_every_text_in_order() -> None:
    """The ranges are index ranges into `texts`, so a gap silently drops chunks from the index
    and an overlap re-embeds and re-bills them."""
    texts = _texts(MAX_BATCH_TEXTS * 2 + 7)
    plan = plan_batches(texts, _short)

    assert plan[0][0] == 0
    assert plan[-1][1] == len(texts)
    for (_, previous_stop), (next_start, _) in itertools.pairwise(plan):
        assert previous_stop == next_start
    covered = [index for start, stop in plan for index in range(start, stop)]
    assert covered == list(range(len(texts)))


@pytest.mark.parametrize(
    "total", [1, MAX_BATCH_TEXTS - 1, MAX_BATCH_TEXTS, MAX_BATCH_TEXTS + 1, MAX_BATCH_TEXTS * 3]
)
def test_no_batch_exceeds_the_text_cap(total: int) -> None:
    plan = plan_batches(_texts(total), _short)
    assert all(0 < stop - start <= MAX_BATCH_TEXTS for start, stop in plan)
    assert len(plan) == math.ceil(total / MAX_BATCH_TEXTS)


def test_the_plan_is_deterministic() -> None:
    """The batch is the checkpoint and the redelivery unit. A boundary that moves between runs
    re-embeds and re-bills text that was already paid for, so this is a cost property as much as
    a correctness one."""
    texts = _texts(200)
    assert plan_batches(texts, _short) == plan_batches(texts, _short)
    assert plan_batches(texts, lambda text: 1) == plan_batches(texts, lambda text: MAX_TOKENS)


def test_an_over_length_text_raises_validation_rather_than_being_split_around() -> None:
    texts = _texts(3)
    with pytest.raises(KbError) as caught:
        plan_batches(texts, lambda text: MAX_TOKENS + 1)
    assert caught.value.error_class is ErrorClass.VALIDATION
    assert not caught.value.retryable
    assert str(MAX_TOKENS) in str(caught.value)


def test_a_text_exactly_at_the_ceiling_is_accepted() -> None:
    """`MAX_TOKENS` is the chunker's budget, not one below it. Rejecting the boundary makes
    every maximum-size chunk a failed run."""
    assert plan_batches(_texts(2), lambda text: MAX_TOKENS) == [(0, 2)]


def test_the_over_length_check_runs_before_any_range_is_produced() -> None:
    """A chunker defect must surface as itself. Checked per batch instead, the first two batches
    would be embedded and billed before the third failed, and the run would report a provider
    error on a text this service produced."""
    texts = _texts(MAX_BATCH_TEXTS * 3)
    seen: list[str] = []

    def measure(text: str) -> int:
        seen.append(text)
        return MAX_TOKENS + 1 if text == texts[-1] else 10

    with pytest.raises(KbError, match=str(len(texts) - 1)):
        plan_batches(texts, measure)
    assert len(seen) == len(texts), "the check stopped short of the offending text"


# ── embed_passages ───────────────────────────────────────────────────────────


def test_one_chunk_vectors_per_text_in_document_order() -> None:
    embed = _FakeEmbed()
    vectors = embed_passages(
        _texts(5), embed=embed, identity=_identity(), measure=_short, org_id=_ORG, trace_id=_TRACE
    )
    assert len(vectors) == 5
    assert all(isinstance(entry, ChunkVectors) for entry in vectors)
    assert [round(entry.dense[1] / entry.dense[0]) for entry in vectors] == [1, 2, 3, 4, 5]


def test_vectors_stay_attached_to_their_chunk_across_batch_boundaries() -> None:
    """The off-by-one that produces a fully populated, fully wrong index. Nothing downstream can
    detect it: the totals match, the widths match, and verification counts points."""
    total = MAX_BATCH_TEXTS * 2 + 3
    embed = _FakeEmbed()
    vectors = embed_passages(
        _texts(total),
        embed=embed,
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    assert len(embed.calls) == 3
    assert [len(call["texts"]) for call in embed.calls] == [  # type: ignore[arg-type]
        MAX_BATCH_TEXTS,
        MAX_BATCH_TEXTS,
        3,
    ]
    assert [round(entry.dense[1] / entry.dense[0]) for entry in vectors] == list(
        range(1, total + 1)
    )


def test_every_call_names_the_organization_the_trace_and_the_passage_side() -> None:
    """Tenant isolation is enforced in code at every layer, and this is the call that spends a
    tenant's embedding budget. `input_type` is fixed to the probe's value: several vendors embed
    the same string differently in query and passage mode, so a corpus embedded through a
    different request shape than the drift probe is a probe that can agree while the corpus has
    moved."""
    embed = _FakeEmbed()
    embed_passages(
        _texts(MAX_BATCH_TEXTS + 1),
        embed=embed,
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    assert len(embed.calls) == 2
    for call in embed.calls:
        assert call["org_id"] == _ORG
        assert call["trace_id"] == _TRACE
        assert call["input_type"] is CANARY_INPUT_TYPE
        assert call["input_type"] is EmbeddingInputType.PASSAGE


def test_vectors_are_l2_normalized_on_our_side_whatever_the_provider_did() -> None:
    """`normalized=False` on the response above. Normalization is part of index identity —
    flipping it invalidates every vector in the collection while every score still looks like a
    plausible number — and it is the only way two providers' outputs share one cosine
    collection."""
    assert NORMALIZE_EMBEDDINGS
    vectors = embed_passages(
        _texts(4),
        embed=_FakeEmbed(),
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    for entry in vectors:
        assert math.sqrt(sum(value * value for value in entry.dense)) == pytest.approx(1.0)


def test_disabling_normalization_leaves_the_provider_vector_untouched(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(embedder, "NORMALIZE_EMBEDDINGS", False)
    vectors = embed_passages(
        _texts(1),
        embed=_FakeEmbed(),
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    assert vectors[0].dense == [1.0, 1.0, 0.0, 0.0]


def test_a_short_batch_is_a_downstream_failure_and_never_a_shift() -> None:
    embed = _FakeEmbed(drop_last=True)
    with pytest.raises(KbError) as caught:
        embed_passages(
            _texts(3),
            embed=embed,
            identity=_identity(),
            measure=_short,
            org_id=_ORG,
            trace_id=_TRACE,
        )
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.DOWNSTREAM
    assert "wrong chunk" in str(caught.value)


def test_a_wrong_width_vector_is_translated_into_the_taxonomy() -> None:
    """`DimensionMismatch` from the collection module is translated rather than propagated, so
    one taxonomy covers the stage. A width that quietly changed is a moved alias or a dropped
    `dimensions` parameter, and the collection accepts neither."""
    embed = _FakeEmbed(override=[[0.1, 0.2, 0.3]])
    with pytest.raises(KbError) as caught:
        embed_passages(
            _texts(1),
            embed=embed,
            identity=_identity(),
            measure=_short,
            org_id=_ORG,
            trace_id=_TRACE,
        )
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.DOWNSTREAM
    assert "reindex" in str(caught.value)


def test_every_vector_in_a_batch_is_width_checked_not_just_the_first() -> None:
    """A vendor that truncates one long input truncates it alone — the same reason
    `resolve_identity` checks every probe."""
    embed = _FakeEmbed(override=[[1.0, 0.0, 0.0, 0.0], [1.0, 0.0, 0.0]])
    with pytest.raises(KbError, match="reindex"):
        embed_passages(
            _texts(2),
            embed=embed,
            identity=_identity(),
            measure=_short,
            org_id=_ORG,
            trace_id=_TRACE,
        )


@pytest.mark.parametrize(
    ("vector", "label"),
    [
        ([0.0, 0.0, 0.0, 0.0], "zero"),
        ([float("nan"), 1.0, 0.0, 0.0], "nan"),
        ([float("inf"), 1.0, 0.0, 0.0], "inf"),
    ],
)
def test_a_vector_that_cannot_be_used_fails_the_batch(vector: list[float], label: str) -> None:
    """All three upsert cleanly and then never rank. `NaN` is the worst of them: it compares
    false against every threshold, so the chunk is silently absent from every result set while
    the point total, the width and the verification all read healthy."""
    embed = _FakeEmbed(override=[vector])
    with pytest.raises(KbError) as caught:
        embed_passages(
            _texts(1),
            embed=embed,
            identity=_identity(),
            measure=_short,
            org_id=_ORG,
            trace_id=_TRACE,
        )
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert "norm" in str(caught.value), label


def test_an_over_length_chunk_is_refused_before_a_single_request_is_built() -> None:
    """Never trim, and never pay for the batches before the bad one."""
    embed = _FakeEmbed()
    with pytest.raises(KbError) as caught:
        embed_passages(
            _texts(MAX_BATCH_TEXTS * 2),
            embed=embed,
            identity=_identity(),
            measure=lambda text: MAX_TOKENS + 1,
            org_id=_ORG,
            trace_id=_TRACE,
        )
    assert caught.value.error_class is ErrorClass.VALIDATION
    assert embed.calls == [], "a request was sent for text that must never be sent"


def test_the_exact_strings_are_sent_and_never_a_modified_copy() -> None:
    """`texts` are the exact strings `content_hash` covers, heading prefix included.

    A trim, a strip or a normalization here makes every vector describe a string that exists in
    no `chunks` row, and there is no downstream check that can see it: the widths match, the
    totals match, and the sparse arm — computed from the same argument — agrees with the dense
    arm about a document neither of them stores. This is the same defect as a silent provider
    truncation, committed on our side of the wire.
    """
    texts = ["  Heading > body, with trailing space  ", "x" * 4_000, "\ttabbed\n"]
    embed = _FakeEmbed()
    embed_passages(
        texts, embed=embed, identity=_identity(), measure=_short, org_id=_ORG, trace_id=_TRACE
    )
    assert embed.calls[0]["texts"] == texts


def test_an_identity_without_a_digest_cannot_label_a_single_chunk() -> None:
    """The metric label is `identity.version`, and that property refuses an empty digest: a bare
    alias wearing the identity's name is exactly the value that cannot detect a weight swap."""
    embed = _FakeEmbed()
    with pytest.raises(KbError, match="canary digest"):
        embed_passages(
            _texts(1),
            embed=embed,
            identity=_identity(digest=""),
            measure=_short,
            org_id=_ORG,
            trace_id=_TRACE,
        )


# ── the sparse branch riding along ───────────────────────────────────────────


def test_each_chunk_carries_a_real_sparse_vector_from_the_text_that_was_embedded() -> None:
    """The lexical and dense arms must describe the same document, so the sparse vector is
    computed from the exact string sent to the provider — heading prefix included."""
    vectors = embed_passages(
        ["refund policy for annual subscriptions"],
        embed=_FakeEmbed(),
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    sparse = vectors[0].sparse
    assert sparse is not None
    assert len(sparse.indices) == 5
    assert sparse.indices == sorted(sparse.indices)
    assert all(value > 0 for value in sparse.values)
    assert sparse == sparse_module.encode_passage("refund policy for annual subscriptions")


def test_a_chunk_that_analyzes_to_nothing_gets_a_recorded_none_not_an_empty_vector() -> None:
    """An image-only chunk or a table of bare numerals is a real case, not a defect. An empty
    sparse vector is accepted by Qdrant, matches nothing forever, and halves the hybrid arm for
    that chunk with no error on either side — so the two states must not be reachable by one
    code path."""
    vectors = embed_passages(
        ["   ", "real words here"],
        embed=_FakeEmbed(),
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    assert vectors[0].sparse is None
    assert vectors[1].sparse is not None


def test_both_branches_ride_on_one_list_so_they_cannot_disagree_about_which_chunks_exist() -> None:
    texts = _texts(MAX_BATCH_TEXTS + 5)
    vectors = embed_passages(
        texts,
        embed=_FakeEmbed(),
        identity=_identity(),
        measure=_short,
        org_id=_ORG,
        trace_id=_TRACE,
    )
    assert len(vectors) == len(texts)
    assert all(entry.dense for entry in vectors)
    assert all(entry.sparse is not None for entry in vectors)

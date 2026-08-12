"""The lexical arm — finding C2's resolution, and the four things it must not get wrong.

C2: BGE-M3 emitted dense *and* learned-sparse vectors from one local forward pass; ADR-030
removed local model inference and API embedding endpoints return dense only, so hybrid search
lost half of itself and the degraded path lost the only signal it selects on. The resolution is
locally-computed BM25 — a statistical ranking function, not a model, so outside ADR-030.

Every test here guards a failure that is silent. None of them is about whether BM25 ranks well.

* **Term ids must be reproducible across processes.** ``hash()`` is randomized per interpreter,
  so an indexer and a reader would compute different ids for the same word and every lexical
  query would match nothing — HTTP 200, empty branch, no error.
* **The document-side vector must carry no corpus statistic.** Folding IDF into it would make
  every vector a function of the corpus at the moment it was written, and an ADR-010 rebuild
  would then produce a different index that passes every count and checksum it specifies.
* **IDF must not go negative.** The classical form does, once a term is in more than half the
  documents, and under a dot-product scorer that means a matching term *subtracts*.
* **Statistics must not cross a scope.** Document frequencies read for one organization, or for
  an active-version set that has since moved, must raise rather than weight a query.
"""

from __future__ import annotations

import math
import subprocess
import sys
from dataclasses import dataclass
from typing import Final

import pytest

from app.retrieval import sparse
from app.retrieval.collection import (
    SPARSE_ANALYZER_VERSION,
    AnalyzerMismatch,
    EmbeddingSpace,
)
from app.retrieval.search import retrieve_branches
from app.retrieval.sparse import (
    BM25_AVGDL,
    BM25_B,
    BM25_K1,
    MAX_QUERY_TERMS,
    TERM_ID_MODULUS,
    CorpusStatistics,
    EmptySparsePassage,
    EmptySparseQuery,
    SparseAnalyzerMismatch,
    SparseStatisticsUnavailable,
    as_sparse_vector,
    encode_passage,
    encode_query,
    evidence_fingerprint,
    idf,
    passage_weights,
    query_weights,
    term_frequencies,
    term_id,
    tokenize,
)
from tests.support.fake_qdrant import RecordingClient

VERSIONS: Final[tuple[str, ...]] = ("01JQZ0000000000000000VER1", "01JQZ0000000000000000VER2")


@dataclass(frozen=True)
class Ctx:
    """Structurally a ``TenantContext``. Two organizations exist below, deliberately: a
    one-organization fixture cannot fail a scoping test, so it would pass proving nothing."""

    org_id: str
    bot_id: str | None = "01JQZ00000000000000000BOT"


ORG_A: Final = Ctx(org_id="01JQZ000000000000000000A")
ORG_B: Final = Ctx(org_id="01JQZ000000000000000000B")


def stats_for(
    ctx: Ctx,
    frequencies: dict[int, int],
    total: int,
    *,
    versions: tuple[str, ...] = VERSIONS,
    analyzer: str = SPARSE_ANALYZER_VERSION,
) -> CorpusStatistics:
    return CorpusStatistics(
        org_id=ctx.org_id,
        analyzer=analyzer,
        evidence_fp=evidence_fingerprint(versions),
        document_total=total,
        document_frequencies=frequencies,
    )


def whitespace_tokenize(text: str) -> list[str]:
    """A stand-in for the analyzer, and **not** a proposal for one.

    ``tokenize`` is unimplemented on purpose: segmentation is a per-script decision and
    whitespace splitting makes an entire Chinese or Japanese sentence one term. This exists only
    so the composition around it can be exercised — the seam is testable end to end with the one
    genuinely open decision faked.
    """
    return [token for token in text.casefold().split() if token]


# ── term identity ─────────────────────────────────────────────────────────────


def test_term_ids_are_identical_under_different_interpreter_hash_seeds() -> None:
    """The failure that ``hash()`` produces, proved rather than asserted in prose.

    ``PYTHONHASHSEED`` randomizes ``str`` hashing per interpreter. An indexer and a reader that
    disagree about a term's id produce a sparse branch that matches nothing, forever, at HTTP
    200 with no exception and no log line — and it would reproduce inside one process, which is
    where a unit test normally looks. Two subprocesses with pinned, different seeds is the only
    place it is visible.
    """
    program = (
        "from app.retrieval.sparse import term_id;"
        "print(term_id('XR-400B'), term_id('warranty'), term_id('नमस्ते'))"
    )
    outputs = []
    for seed in ("0", "1", "12345"):
        result = subprocess.run(  # noqa: S603 - fixed argv, no shell, no external input
            [sys.executable, "-c", program],
            check=True,
            capture_output=True,
            text=True,
            env={"PYTHONHASHSEED": seed, "PATH": "/usr/bin:/bin"},
        )
        outputs.append(result.stdout.strip())
    assert len(set(outputs)) == 1, f"term ids moved with the hash seed: {outputs}"


def test_term_ids_fit_qdrant_unsigned_32_bit_sparse_index_space() -> None:
    """Qdrant sparse ``indices`` are u32. A wider id is rejected at the wire, per point, mid-run."""
    for word in ("a", "warranty", "XR-400B", "नमस्ते", "错误代码", "x" * 512):
        assert 0 <= term_id(word) < TERM_ID_MODULUS


def test_an_empty_term_has_no_id() -> None:
    with pytest.raises(ValueError, match="empty term"):
        term_id("")


def test_term_frequencies_are_counted_over_ids_not_strings() -> None:
    frequencies = term_frequencies(["refund", "policy", "refund"])
    assert frequencies == {term_id("refund"): 2, term_id("policy"): 1}


# ── the document side is corpus-independent, which is what keeps ADR-010 true ──


def test_the_document_side_weight_ignores_how_common_a_term_is() -> None:
    """**The rebuildability test.**

    Two terms with identical in-chunk frequency get identical document-side weights no matter
    how rare either is in the corpus, because the document vector carries no corpus statistic at
    all. If someone folds IDF in here — the obvious "optimization", since it moves work off the
    request path — this fails, and the failure it prevents is an ADR-010 rebuild producing a
    *different* index that passes every count and checksum the ADR specifies while ranking
    differently forever.
    """
    everywhere, nowhere = term_id("the"), term_id("XR-400B")
    weights = passage_weights({everywhere: 3, nowhere: 3}, 120)
    assert weights[everywhere] == weights[nowhere]


def test_the_document_side_vector_is_byte_identical_on_re_encode() -> None:
    """A rebuild must reproduce the vector, or it cannot be verified by comparison."""
    frequencies = {term_id("warranty"): 2, term_id("accidental"): 1, term_id("damage"): 4}
    first = as_sparse_vector(passage_weights(frequencies, 90))
    second = as_sparse_vector(passage_weights(dict(reversed(list(frequencies.items()))), 90))
    assert first.indices == second.indices
    assert first.values == second.values


def test_term_frequency_saturates_rather_than_accumulating() -> None:
    """The reason BM25 beats raw tf: the tenth occurrence must be worth far less than the first."""
    weights = passage_weights({1: 1, 2: 2, 3: 20}, 200)
    assert weights[1] < weights[2] < weights[3]
    assert weights[3] < weights[1] * 4


def test_a_longer_chunk_is_penalized_for_the_same_term_frequency() -> None:
    short = passage_weights({7: 3}, 40)[7]
    long = passage_weights({7: 3}, 400)[7]
    assert short > long


def test_a_chunk_with_no_analyzed_terms_has_no_length_to_normalize_against() -> None:
    with pytest.raises(ValueError, match="no analyzed terms"):
        passage_weights({7: 1}, 0)


# ── IDF: the sign trap ────────────────────────────────────────────────────────


def test_idf_never_goes_negative_even_for_a_term_in_almost_every_document() -> None:
    """The classical Robertson/Sparck-Jones form turns negative once ``df > N/2``.

    Under a dot-product scorer a negative query weight means a *matching* term subtracts from
    the score, so a chunk containing every word of the question can rank below one containing
    none of them. Nothing errors; the ranking is simply inverted for common terms. This is the
    test that fails if the outer ``1 +`` is ever "simplified" away.
    """
    for document_frequency in range(0, 1001):
        assert idf(1000, document_frequency) > 0.0


def test_idf_falls_as_a_term_becomes_more_common() -> None:
    assert idf(1000, 1) > idf(1000, 100) > idf(1000, 900)


def test_idf_refuses_a_document_frequency_wider_than_the_scope() -> None:
    """The arithmetic shape of a cross-scope read: more documents contain the term than exist
    in the scope, which can only mean the statistics were summed over versions the query is
    filtered away from."""
    with pytest.raises(ValueError, match="wider scope"):
        idf(10, 11)


def test_idf_refuses_negative_counts() -> None:
    with pytest.raises(ValueError, match="cannot be negative"):
        idf(-1, 0)


# ── the split reproduces BM25 exactly ─────────────────────────────────────────


def test_the_query_document_split_reproduces_bm25_term_for_term() -> None:
    """Qdrant scores a sparse pair as a dot product over shared indices, so moving the IDF
    factor onto the query vector has to be exact rather than approximate — otherwise the tenancy
    fix would have quietly changed the ranking function.
    """
    document_length, total = 150, 5000
    corpus = {term_id("warranty"): 800, term_id("XR-400B"): 3}
    chunk = {term_id("warranty"): 4, term_id("XR-400B"): 2, term_id("shipping"): 9}
    query = {term_id("warranty"): 1, term_id("XR-400B"): 2}

    stats = stats_for(ORG_A, corpus, total)
    document = passage_weights(chunk, document_length)
    asked = query_weights(query, stats)
    dot = sum(value * document[term] for term, value in asked.items() if term in document)

    normalizer = BM25_K1 * (1.0 - BM25_B + BM25_B * document_length / BM25_AVGDL)
    reference = sum(
        query_frequency
        * math.log(1.0 + (total - corpus[term] + 0.5) / (corpus[term] + 0.5))
        * (chunk[term] * (BM25_K1 + 1.0) / (chunk[term] + normalizer))
        for term, query_frequency in query.items()
    )
    assert dot == pytest.approx(reference)


def test_a_term_absent_from_the_statistics_is_treated_as_maximally_rare() -> None:
    """A term nobody in this scope has written is the most discriminating term there is —
    dropping it instead would make a query for a unique part number weigh nothing."""
    stats = stats_for(ORG_A, {}, 5000)
    assert stats.idf_for(term_id("XR-400B")) == pytest.approx(idf(5000, 0))


# ── scope: statistics may not cross an organization or an evidence set ────────


def test_statistics_read_for_another_organization_are_refused() -> None:
    """Two organizations, because a one-organization fixture cannot fail this."""
    stats = stats_for(ORG_B, {1: 2}, 100)
    with pytest.raises(SparseStatisticsUnavailable, match="organization"):
        stats.for_scope(ORG_A, VERSIONS, analyzer=SPARSE_ANALYZER_VERSION)


def test_statistics_read_before_a_source_was_disabled_are_refused() -> None:
    """Disabling or deleting a source moves the active-version set, and the fingerprint moves
    with it. Stale statistics would keep weighting the query by documents the four payload
    filters now exclude — the ranking half of a deleted source that keeps answering.
    """
    stats = stats_for(ORG_A, {1: 2}, 100, versions=VERSIONS)
    with pytest.raises(SparseStatisticsUnavailable, match="active-version set"):
        stats.for_scope(ORG_A, VERSIONS[:1], analyzer=SPARSE_ANALYZER_VERSION)


def test_statistics_read_under_another_analyzer_are_refused() -> None:
    stats = stats_for(ORG_A, {1: 2}, 100, analyzer="bm25/v0")
    with pytest.raises(SparseAnalyzerMismatch, match="not comparable"):
        stats.for_scope(ORG_A, VERSIONS, analyzer=SPARSE_ANALYZER_VERSION)


def test_matching_scope_passes_and_returns_the_same_statistics() -> None:
    stats = stats_for(ORG_A, {1: 2}, 100)
    assert stats.for_scope(ORG_A, VERSIONS, analyzer=SPARSE_ANALYZER_VERSION) is stats


def test_statistics_without_a_scope_cannot_be_constructed() -> None:
    with pytest.raises(ValueError, match="not scoped at all"):
        CorpusStatistics(
            org_id="",
            analyzer=SPARSE_ANALYZER_VERSION,
            evidence_fp="x",
            document_total=1,
            document_frequencies={},
        )
    with pytest.raises(ValueError, match="fingerprint"):
        CorpusStatistics(
            org_id=ORG_A.org_id,
            analyzer=SPARSE_ANALYZER_VERSION,
            evidence_fp="",
            document_total=1,
            document_frequencies={},
        )


def test_the_evidence_fingerprint_is_order_and_duplicate_independent() -> None:
    """The resolver's ordering is not part of the contract, so a re-ordered set must not read as
    a different scope and invalidate statistics that are in fact correct."""
    assert evidence_fingerprint(["b", "a"]) == evidence_fingerprint(["a", "b", "a"])
    assert evidence_fingerprint(["a"]) != evidence_fingerprint(["a", "b"])


def test_an_empty_active_version_set_has_no_fingerprint() -> None:
    """``tenant_filter`` raises on that scope; nothing downstream should have been reached."""
    with pytest.raises(ValueError, match="empty active-version set"):
        evidence_fingerprint([])


# ── the empty sparse vector, which is accepted everywhere and matches nothing ──


def test_an_empty_sparse_vector_is_refused_rather_than_sent() -> None:
    """Qdrant accepts one at upsert and at query, matches nothing forever, and raises nothing —
    the only failure in this area that is both silent and permanent."""
    with pytest.raises(ValueError, match="matches nothing forever"):
        as_sparse_vector({})


def test_sparse_indices_are_sorted_so_two_encodings_are_comparable() -> None:
    vector = as_sparse_vector({99: 0.5, 3: 0.25, 40: 1.0})
    assert vector.indices == [3, 40, 99]
    assert vector.values == [0.25, 1.0, 0.5]


def test_a_term_id_outside_the_index_space_is_refused() -> None:
    with pytest.raises(ValueError, match="32-bit"):
        as_sparse_vector({TERM_ID_MODULUS: 1.0})


# ── the tokenizer is the stated gap, and the composition around it is not ─────


def test_the_tokenizer_is_the_one_thing_left_open() -> None:
    """It is unimplemented deliberately. Filling it with whitespace splitting would make the
    lexical arm worthless for unsegmented scripts while every metric stayed green."""
    with pytest.raises(NotImplementedError, match="C2"):
        tokenize("does the XR-400B cover accidental damage")


def test_encode_passage_composes_to_a_deterministic_vector(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(sparse, "tokenize", whitespace_tokenize)
    text = "Warranty coverage for the XR-400B warranty claim"
    first, second = encode_passage(text), encode_passage(text)
    assert first.indices == second.indices
    assert first.values == second.values
    assert term_id("warranty") in first.indices


def test_a_chunk_that_analyzes_to_nothing_is_a_decision_for_ingestion_not_an_empty_vector(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """An image-only chunk, or a table of bare numerals under a tokenizer that drops digits.

    The upserter's rule is what this protects: a point may be written with **no** sparse vector,
    deliberately and recorded as such, but never with an empty one — an empty sparse vector is
    accepted at upsert, matches nothing forever, and halves the hybrid branch for that chunk.
    A distinct exception is what stops the two states sharing a code path.
    """
    monkeypatch.setattr(sparse, "tokenize", whitespace_tokenize)
    with pytest.raises(EmptySparsePassage, match="without a sparse vector"):
        encode_passage("   ")


def test_encode_query_checks_the_statistics_scope_before_weighting_anything(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The tenancy check is inside the encoder, not beside it: a caller that forgets it cannot
    produce a vector at all, because the scope arguments are positional and required."""
    monkeypatch.setattr(sparse, "tokenize", whitespace_tokenize)
    foreign = stats_for(ORG_B, {term_id("warranty"): 3}, 100)
    with pytest.raises(SparseStatisticsUnavailable, match="organization"):
        encode_query("warranty", ORG_A, VERSIONS, foreign)


def test_a_query_that_analyzes_to_no_terms_is_a_dense_only_run_not_an_empty_vector(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """An empty sparse vector would go out, match nothing, and read as "the lexical branch found
    nothing" — which the degraded path treats as strong disagreement. It is no signal at all,
    and the caller has to be able to tell the difference."""
    monkeypatch.setattr(sparse, "tokenize", whitespace_tokenize)
    with pytest.raises(EmptySparseQuery, match="dense-only run"):
        encode_query("   ", ORG_A, VERSIONS, stats_for(ORG_A, {}, 100))


def test_encode_query_produces_idf_weighted_terms(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(sparse, "tokenize", whitespace_tokenize)
    rare, common = term_id("xr-400b"), term_id("the")
    stats = stats_for(ORG_A, {rare: 2, common: 4900}, 5000)
    vector = encode_query("the xr-400b", ORG_A, VERSIONS, stats)
    weights = dict(zip(vector.indices, vector.values, strict=True))
    assert weights[rare] > weights[common]


def test_the_query_term_cap_is_deterministic() -> None:
    """A cost bound on one PostgreSQL read and one index traversal inside a 1.5 s leg. It sheds
    the most-repeated terms first — in a query long enough to hit the cap those are the least
    discriminating — and ties break on term id so two identical queries encode identically."""
    crowded = {index: (index % 5) + 1 for index in range(MAX_QUERY_TERMS * 3)}
    first = sparse._bound_query_terms(crowded)
    second = sparse._bound_query_terms(dict(reversed(list(crowded.items()))))
    assert len(first) == MAX_QUERY_TERMS
    assert first == second


# ── the branch itself: analyzer skew is checked before the call goes out ──────


async def test_a_sparse_vector_from_another_analyzer_never_reaches_the_client() -> None:
    """The sparse twin of ``assert_dimensions``, and quieter than it. A wrong-width dense vector
    is usually a 400; a wrong-analyzer sparse vector is always accepted, matches no posting, and
    returns an empty branch — indistinguishable from a corpus with no exact-term hit.
    """
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072, sparse_analyzer="bm25/v0")
    with pytest.raises(AnalyzerMismatch, match="not comparable"):
        await retrieve_branches(
            client=None,  # type: ignore[arg-type]
            ctx=ORG_A,
            allowed_version_ids=VERSIONS,
            dense=[0.0] * 3072,
            sparse=as_sparse_vector({term_id("warranty"): 1.0}),
            space=space,
            dense_limit=20,
            sparse_limit=20,
        )


async def test_a_dense_only_run_is_not_blocked_by_the_analyzer_check() -> None:
    """A run with no lexical arm has no analyzer to disagree about; it must reach the branch
    calls rather than failing this check.

    It used to assert ``NotImplementedError`` — the branch calls raised — and the docstring
    said "(and, today, their ``NotImplementedError``)" because that was scaffolding rather than
    the subject. The subject is unchanged: a space whose ``sparse_analyzer`` disagrees with the
    encoder must not stop a run that never asks the lexical branch anything. So the stub client
    below is the minimum that lets the branch call complete, and the assertion is that the
    dense branch was reached and the sparse one was not.
    """
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072, sparse_analyzer="bm25/v0")
    client = RecordingClient()
    branches = await retrieve_branches(
        client=client,  # type: ignore[arg-type]
        ctx=ORG_A,
        allowed_version_ids=VERSIONS,
        dense=[0.0] * 3072,
        space=space,
        dense_limit=20,
    )
    assert set(branches) == {"dense"}
    assert [call["using"] for call in client.calls] == ["dense"]

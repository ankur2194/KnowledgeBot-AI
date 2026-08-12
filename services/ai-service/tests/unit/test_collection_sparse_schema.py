"""The collection's half of finding C2 — the analyzer is space identity, and IDF stays off.

The sparse arm has a producer again, which makes two schema facts load-bearing that were not
while the vector was declared and unfed:

* **The analyzer version is part of the space**, so it is part of the collection name, so
  changing it is a reindex rather than an in-place sweep with a half-migrated window.
* **``SPARSE_MODIFIER`` stays ``None``**, and the reason changed rather than expired. It was
  "BGE-M3's weights are already term importance"; that reason died with BGE-M3. What replaced
  it is a tenancy reason that BM25 does not fix: Qdrant computes document frequencies
  collection-wide across every tenant, and the parameter that would scope them per tenant
  arrives in server 1.19.0 while ADR-021 pins 1.18.3.

``app/retrieval/collection.py`` is the one definition of the schema. ``app/ingestion/`` and
``app/deletion/`` are tenants of it, so a change here is a change to both of their contracts.
"""

from __future__ import annotations

import pytest

from app.retrieval.collection import (
    SPARSE_ANALYZER_VERSION,
    SPARSE_MODIFIER,
    SPARSE_VECTOR_NAME,
    AnalyzerMismatch,
    EmbeddingSpace,
    assert_analyzer,
)


def test_the_sparse_analyzer_is_part_of_the_collection_name() -> None:
    """Two analyzers in one collection do not error, and that is the whole problem.

    Term ids from a different analyzer are legal unsigned integers that happen to match no
    posting, so the half of the corpus encoded under the other analyzer quietly loses its
    lexical recall while dense retrieval keeps every panel populated. Only the name keeps them
    apart, exactly as it does for two embedding models.
    """
    current = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    next_analyzer = EmbeddingSpace(
        "openai", "text-embedding-3-large", 3072, sparse_analyzer="bm25/v2"
    )
    assert current.collection != next_analyzer.collection


def test_the_analyzer_default_is_the_shipped_one() -> None:
    """A space built without naming an analyzer must land on the one the encoder produces, or
    the writer and the reader disagree with nothing to say so."""
    assert EmbeddingSpace("openai", "text-embedding-3-large", 3072).sparse_analyzer == (
        SPARSE_ANALYZER_VERSION
    )


def test_a_space_cannot_be_built_without_an_analyzer() -> None:
    with pytest.raises(ValueError, match="sparse analyzer"):
        EmbeddingSpace("openai", "text-embedding-3-large", 3072, sparse_analyzer="")


def test_the_collection_name_is_still_a_bounded_metric_label() -> None:
    """Regression on the added identity field: ``collection`` is a metric label and the digest
    grew an input, so the shape has to be re-proved rather than assumed."""
    name = EmbeddingSpace("openai", "text-embedding-3-large", 3072).collection
    assert name.startswith("kb_")
    assert len(name) < 64
    assert name.replace("_", "").isalnum()


def test_assert_analyzer_rejects_a_vector_from_another_analyzer() -> None:
    """The sparse counterpart of ``assert_dimensions``, and quieter than it.

    A wrong-width dense vector is usually a 400 from the server. A wrong-analyzer sparse vector
    is always accepted — every integer is a legal sparse index — so nothing rejects it, the
    branch returns nothing, and the run reads as a corpus with no exact-term hit.
    """
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    with pytest.raises(AnalyzerMismatch, match="not comparable"):
        assert_analyzer(space, "bm25/v0")


def test_assert_analyzer_passes_the_matching_version() -> None:
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    assert_analyzer(space, SPARSE_ANALYZER_VERSION)


def test_the_idf_modifier_stays_unset_on_the_pinned_server() -> None:
    """**Do not turn this on "because BM25 lands and IDF is what BM25 wants".**

    It is what BM25 wants and it is unavailable here. With the modifier on, Qdrant computes
    document frequencies collection-wide across every tenant: a relevance error (one
    organization's corpus skewing another's ranking) and a weak cross-tenant statistical oracle
    (a term's contribution reveals how common it is in documents the caller cannot read).
    ``SearchParams(idf=IdfCorpusParams(corpus=...))`` scopes it per tenant and arrives in server
    1.19.0; ADR-021 pins 1.18.3.

    The IDF factor is applied by us to the *query* vector instead, from statistics scoped to
    ``(org_id, allowed_version_ids)`` — so turning this on now would also multiply it in twice.
    """
    assert SPARSE_MODIFIER is None


def test_the_sparse_vector_name_is_part_of_the_wire_contract() -> None:
    """A collection with named vectors has no default branch, so this name travels on every
    write and every query. Renaming it is a re-index, not a rename."""
    assert SPARSE_VECTOR_NAME == "sparse"

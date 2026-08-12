"""`EmbeddingSpace` is what stops two embedding models sharing one Qdrant collection.

The dense width used to be a constant in `app/retrieval/collection.py`, because BGE-M3 ran
in this process and 1024 was a fact about code we shipped. Embeddings are external API calls
now, so the width belongs to whichever provider and model a source version was indexed
under, and it changes when a bot's provider connection changes.

Everything here guards the same failure: two spaces in one collection do **not** error.
Cosine distance is defined between any two vectors of equal width, and two vendors can
easily agree on 1024 while agreeing on nothing else — so the mistake surfaces as content
from one model ranking nonsense above the right answer, forever, with no exception anywhere.
Only the name keeps them apart, and only these assertions keep the name honest.

Imports nothing but the module under test: no client, no container, no app factory.
"""

from __future__ import annotations

import pytest

from app.retrieval.collection import (
    MAX_DIMENSIONS,
    PAYLOAD_INDEXES,
    DimensionMismatch,
    EmbeddingSpace,
    assert_dimensions,
)


def test_width_is_part_of_the_name() -> None:
    """The same model at two widths is two spaces.

    Several embedding APIs take `dimensions` as a request parameter (Matryoshka
    truncation), so the model id alone does not identify the space. If both widths resolved
    to one collection, the 1536-dim run would be rejected at upsert — which is the *lucky*
    outcome; the unlucky one is a provider that pads and a collection that accepts both.
    """
    wide = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    narrow = EmbeddingSpace("openai", "text-embedding-3-large", 1536)
    assert wide.collection != narrow.collection


def test_distance_is_part_of_the_name() -> None:
    """Same vectors, different metric, different index.

    A collection created for cosine and queried expecting dot product returns a plausible
    ordering that is simply wrong, at HTTP 200. Nothing downstream can tell.
    """
    cosine = EmbeddingSpace("nim", "nv-embedqa-e5-v5", 1024)
    dot = EmbeddingSpace("nim", "nv-embedqa-e5-v5", 1024, distance="Dot")
    assert cosine.collection != dot.collection


def test_provider_is_part_of_the_name() -> None:
    """Two vendors serving the same model id at the same width are still two spaces.

    Open-weight models are served by several providers here, and their outputs are not
    interchangeable across deployments — quantization, pooling and normalization all differ.
    """
    a = EmbeddingSpace("nim", "bge-m3", 1024)
    b = EmbeddingSpace("openrouter", "bge-m3", 1024)
    assert a.collection != b.collection


def test_slug_collisions_do_not_collapse_two_spaces() -> None:
    """The readable prefix is lossy; the digest is what actually separates the names.

    `embed-v1.5` and `embed_v1_5` slug identically. Without the digest they would share a
    collection, which is the one failure the naming scheme exists to prevent — and it would
    be invisible until someone noticed answers drawn from the wrong half of the corpus.
    """
    a = EmbeddingSpace("openai", "embed-v1.5", 1024)
    b = EmbeddingSpace("openai", "embed_v1_5", 1024)
    assert a.collection != b.collection


def test_collection_name_is_deterministic() -> None:
    """The indexer and the reader derive the name independently and must agree.

    A per-process element — a hash seed, a timestamp, an id — would give the writer and the
    reader different names, and a name nobody wrote to reads as an empty corpus: HTTP 200,
    zero candidates, no error.
    """
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    twin = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    assert space.collection == twin.collection == space.collection


def test_collection_name_is_a_legal_bounded_label() -> None:
    """`collection` is a metric label, so it must stay short and alphanumeric.

    It is a bounded label only because collections are per embedding space rather than per
    tenant; the shape still has to be safe to put on a series.
    """
    name = EmbeddingSpace("openai", "text-embedding-3-large", 3072).collection
    assert name.startswith("kb_")
    assert len(name) < 64
    assert name.replace("_", "").isalnum()


@pytest.mark.parametrize("width", [0, -1, MAX_DIMENSIONS + 1])
def test_impossible_widths_are_rejected_at_construction(width: int) -> None:
    """A zero or placeholder width means the provider model registry row was never
    populated. Rejecting it here fails at the row, not at the first upsert of a long
    ingestion run, in a worker, hours later.
    """
    with pytest.raises(ValueError, match="dimensions"):
        EmbeddingSpace("openai", "text-embedding-3-large", width)


def test_a_space_needs_a_provider_and_a_model() -> None:
    """An empty identity produces a name that looks fine and identifies nothing."""
    with pytest.raises(ValueError, match="provider and a model"):
        EmbeddingSpace("", "text-embedding-3-large", 1024)


def test_assert_dimensions_rejects_a_wrong_width_vector() -> None:
    """The provider changed what it returns; the collection cannot change what it holds.

    A moved model alias, a dropped `dimensions` parameter, or an undocumented truncation all
    look like this, and all of them are silent at the API boundary.
    """
    space = EmbeddingSpace("openai", "text-embedding-3-large", 3072)
    with pytest.raises(DimensionMismatch, match="reindex"):
        assert_dimensions(space, 1536)


def test_assert_dimensions_passes_the_matching_width() -> None:
    assert_dimensions(EmbeddingSpace("openai", "text-embedding-3-small", 1536), 1536)


def test_the_payload_floor_still_carries_the_four_mandatory_filter_keys() -> None:
    """Nothing about external embeddings touches tenant isolation.

    The four keys must remain indexed: an unindexed filter field is not a security failure
    but it makes every filtered query a scan, and the repair is a full re-index.
    """
    fields = {index.field for index in PAYLOAD_INDEXES}
    assert {"org_id", "bot_ids", "source_status", "source_version_id"} <= fields


def test_exactly_one_payload_index_is_the_tenant_key() -> None:
    """`is_tenant` co-locates one organization's vectors. Two tenant keys is not a stricter
    setting, it is an undefined one — and it grants and denies nothing either way, which is
    why the filter remains the access control.
    """
    assert sum(index.is_tenant for index in PAYLOAD_INDEXES) == 1

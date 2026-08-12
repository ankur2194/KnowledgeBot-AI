"""Collection and payload-index bootstrap, against a real Qdrant.

This tier because none of it is checkable without a server. ``create_collection`` accepts a
quantization config the in-memory client ignores, ``create_payload_index`` is where
``is_tenant`` either lands or does not, and "already exists" is a server behaviour that differs
between builds — which is the whole reason ``ensure_payload_indexes`` swallows it by message
rather than blanket-catching.

Two things are asserted that have no error to catch them in production:

* **Idempotence.** Bootstrap runs at start-up and the ADR-010 rebuild drill runs it again. A
  second call that raised would make the rebuild a manual procedure; a second call that
  recreated would drop the index and leave every filtered query scanning.
* **The verification raises.** A collection that already exists at another width, another
  distance, or without the ``dense`` branch accepts writes for a while and then ranks wrongly,
  and nothing in a response distinguishes it. ``QdrantClient(":memory:")`` is not usable for
  any of this: its behaviour on the query path inverts the propagation a real server performs.
"""

from __future__ import annotations

from collections.abc import Iterator
from typing import Final

import pytest
from qdrant_client import QdrantClient, models

from app.retrieval.collection import (
    DENSE_DISTANCE,
    DENSE_VECTOR_NAME,
    HNSW_M,
    HNSW_PAYLOAD_M,
    PAYLOAD_INDEXES,
    SPARSE_VECTOR_NAME,
    DimensionMismatch,
    EmbeddingSpace,
    ensure_collection,
    ensure_payload_indexes,
)

pytestmark = pytest.mark.integration

#: Small on purpose: the width is the space's identity, not a quality parameter, and eight
#: dimensions exercise every code path a thousand do.
WIDTH: Final[int] = 8


@pytest.fixture
def client(qdrant_url: str) -> Iterator[QdrantClient]:
    connected = QdrantClient(url=qdrant_url)
    yield connected
    connected.close()


@pytest.fixture
def space(resource_suffix: str) -> EmbeddingSpace:
    """A space whose collection name is unique per xdist worker.

    Two workers sharing a collection is how ``gw3``'s schema assertion lands on ``gw0``'s
    collection, and it only ever happens in CI, where the parallelism is.
    """
    return EmbeddingSpace("test", f"probe-{resource_suffix}", WIDTH)


@pytest.fixture(autouse=True)
def _drop_afterwards(client: QdrantClient, space: EmbeddingSpace) -> Iterator[None]:
    yield
    if client.collection_exists(space.collection):
        client.delete_collection(space.collection)


# ── creation ─────────────────────────────────────────────────────────────────


def test_the_collection_is_created_with_both_named_vectors(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """Two named vectors on one point. A collection with named vectors has no default branch,
    so both names are part of the wire contract and renaming either is a re-index."""
    ensure_collection(client, space)
    params = client.get_collection(space.collection).config.params
    assert isinstance(params.vectors, dict)
    assert set(params.vectors) == {DENSE_VECTOR_NAME}
    assert params.sparse_vectors is not None
    assert set(params.sparse_vectors) == {SPARSE_VECTOR_NAME}


def test_the_dense_branch_takes_its_width_and_metric_from_the_space(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    ensure_collection(client, space)
    params = client.get_collection(space.collection).config.params
    assert isinstance(params.vectors, dict)
    dense = params.vectors[DENSE_VECTOR_NAME]
    assert isinstance(dense, models.VectorParams)
    assert dense.size == WIDTH
    assert dense.distance == models.Distance(DENSE_DISTANCE)


def test_the_idf_modifier_is_off_on_the_server_and_not_only_in_the_constant(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """With it on, Qdrant computes document frequencies **collection-wide across every
    tenant** — a relevance error and a weak cross-tenant statistical oracle at once. The
    parameter that would scope it per tenant arrives in server 1.19.0; ADR-021 pins 1.18.3.

    We apply the IDF factor to the query vector instead, so turning this on would also
    multiply it in twice: rare terms would dominate every lexical result, with no error and no
    diff.
    """
    ensure_collection(client, space)
    sparse = client.get_collection(space.collection).config.params.sparse_vectors
    assert sparse is not None
    assert sparse[SPARSE_VECTOR_NAME].modifier in (None, models.Modifier.NONE)


def test_the_multitenant_graph_shape_reaches_the_server(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """``m=0`` plus ``payload_m=16``: no collection-wide graph, one sub-graph per ``org_id``, so
    a query never traverses another tenant's region. It is a locality property and never an
    access control — the filter is the access control."""
    ensure_collection(client, space)
    hnsw = client.get_collection(space.collection).config.hnsw_config
    assert hnsw.m == HNSW_M
    assert hnsw.payload_m == HNSW_PAYLOAD_M


def test_quantization_is_configured_on_the_collection(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """Originals on disk, quantized copies resident. The pairing is the point; neither half is
    a useful setting alone."""
    ensure_collection(client, space)
    quantization = client.get_collection(space.collection).config.quantization_config
    assert isinstance(quantization, models.ScalarQuantization)
    assert quantization.scalar.type == models.ScalarType.INT8
    assert quantization.scalar.always_ram is True


# ── idempotence and verification ─────────────────────────────────────────────


def test_running_the_bootstrap_twice_is_a_no_op(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """Start-up calls it and the ADR-010 rebuild drill calls it again. A second call that
    raised would make the rebuild a manual procedure."""
    ensure_collection(client, space)
    ensure_collection(client, space)
    assert client.collection_exists(space.collection)


def test_a_collection_at_another_width_raises_rather_than_being_used(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """Two widths in one collection do not error at the wire — cosine is defined between any
    two vectors of equal width — they rank nonsense above the right answer, forever, for the
    half of the corpus embedded under the other model."""
    ensure_collection(client, space)
    wider = EmbeddingSpace(space.provider, space.model, WIDTH * 2)
    with pytest.raises(DimensionMismatch, match="fixed at creation"):
        ensure_collection(client, _at_name(wider, space.collection))


def test_a_collection_ranked_under_another_metric_raises(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """The same vectors under a different metric are a different index, and the wrong one
    produces a plausible ordering rather than an error."""
    ensure_collection(client, space)
    other = EmbeddingSpace(space.provider, space.model, WIDTH, distance="Dot")
    with pytest.raises(DimensionMismatch, match="a different index"):
        ensure_collection(client, _at_name(other, space.collection))


def test_a_collection_without_the_dense_branch_raises(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """A renamed vector is not something a query can fall back to: named vectors have no
    default branch, so every query names one and a rename is a re-index."""
    client.create_collection(
        collection_name=space.collection,
        vectors_config={
            "embedding": models.VectorParams(size=WIDTH, distance=models.Distance.COSINE)
        },
    )
    with pytest.raises(DimensionMismatch, match="no 'dense' vector"):
        ensure_collection(client, space)


# ── payload indexes ──────────────────────────────────────────────────────────


def test_every_payload_index_is_created(client: QdrantClient, space: EmbeddingSpace) -> None:
    """Before the first upsert, never after: Qdrant builds the extra HNSW edges for a payload
    field only once that field's index exists, and it applies going forward rather than
    retroactively. A late index leaves every filtered query scanning while ``is_tenant``
    appears to do nothing, and repair means forcing a full re-index."""
    ensure_collection(client, space)
    ensure_payload_indexes(client, space)
    schema = client.get_collection(space.collection).payload_schema
    assert set(schema) == {index.field for index in PAYLOAD_INDEXES}


def test_every_index_is_keyword_because_our_identifiers_are_ulids(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """A UUID index over ULID values is a start-up rejection at best and a filter that matches
    nothing at worst — and a filter that matches nothing looks exactly like an empty corpus:
    HTTP 200, zero candidates, no exception, on the five fields carrying every tenant filter."""
    ensure_collection(client, space)
    ensure_payload_indexes(client, space)
    schema = client.get_collection(space.collection).payload_schema
    for field, info in schema.items():
        assert info.data_type == models.PayloadSchemaType.KEYWORD, field


def test_only_the_organization_field_is_marked_as_a_tenant(
    client: QdrantClient, space: EmbeddingSpace
) -> None:
    """``is_tenant`` co-locates one organization's vectors on disk so a tenant-scoped query is
    a sequential read. It grants and denies nothing — a second tenant field would split the
    locality it exists to create, which is a performance regression with no error."""
    ensure_collection(client, space)
    ensure_payload_indexes(client, space)
    schema = client.get_collection(space.collection).payload_schema
    tenanted = {
        field for field, info in schema.items() if getattr(info.params, "is_tenant", None) is True
    }
    assert tenanted == {"org_id"}


def test_creating_the_indexes_twice_is_a_no_op(client: QdrantClient, space: EmbeddingSpace) -> None:
    """Some server builds reject an index name that is already present and others return it as
    a no-op. Both must leave the bootstrap re-runnable, which is why the swallow is by message
    and per field rather than a blanket catch around the loop."""
    ensure_collection(client, space)
    ensure_payload_indexes(client, space)
    ensure_payload_indexes(client, space)
    schema = client.get_collection(space.collection).payload_schema
    assert set(schema) == {index.field for index in PAYLOAD_INDEXES}


def _at_name(space: EmbeddingSpace, collection: str) -> EmbeddingSpace:
    """A space that reports someone else's collection name.

    Needed because ``collection`` is derived from the identity, so two spaces that disagree
    about width never collide by construction — which is the design working. To test the
    verification path the name has to be forced to collide, and this is the only place that
    happens.
    """

    class _Renamed(EmbeddingSpace):
        @property
        def collection(self) -> str:
            return collection

    return _Renamed(
        provider=space.provider,
        model=space.model,
        dimensions=space.dimensions,
        distance=space.distance,
        schema_version=space.schema_version,
        sparse_analyzer=space.sparse_analyzer,
    )

"""The ingestion write path into Qdrant: what a point carries, and what proves it landed.

Three properties, and each one fails at HTTP 200 when it is wrong.

* **A point's id is deterministic**, so a redelivered batch overwrites what it already wrote.
  Anything else writes a second set of vectors, spends the context budget on duplicates, and
  leaves `chunks.vector_point_id` naming only one of them.
* **Every payload identifier is a ULID**, because the payload indexes are `keyword`. A
  UUID-shaped `org_id` satisfies no `must` term, so every bot in every organization retrieves
  nothing — normal latency, no exception, no log line.
* **The pre-activation count is scoped to one organization and one version**, and compared with
  `==`. A tolerant comparison passes a version whose points were written twice; an unscoped one
  passes on somebody else's points.

The client here is a fake, and its limits are stated in `_FakeQdrant`. It is not a Qdrant and
proves nothing about isolation *inside the server* — that claim needs two organizations and a
real one, which is `tests/security/`'s tier. What it does prove is that the request this
service issues is scoped, which is the half that goes wrong silently in our own code.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Final

import pytest
from qdrant_client import models

from app.core.errors import ErrorClass, KbError
from app.ingestion.chunking.chunker import CHUNK_METADATA_FIELDS, Chunk, chunk_document
from app.ingestion.embedding.embedder import ChunkVectors
from app.ingestion.identity import point_id
from app.ingestion.indexing.upserter import (
    UPSERT_BATCH_SIZE,
    VERIFY_EXACT,
    to_point,
    upsert_points,
    verify_indexed_total,
)
from app.retrieval.collection import DENSE_VECTOR_NAME, SPARSE_VECTOR_NAME, EmbeddingSpace
from tests.support.ingestion import Element, context, measure_words, ulid

SPACE: Final[EmbeddingSpace] = EmbeddingSpace("test", "probe", 8)
STATUS: Final[str] = "ready"


@dataclass
class _CountResult:
    count: int


@dataclass
class _FakeQdrant:
    """Enough of a client to observe the request, and exactly two filter terms of behaviour.

    **What it does.** It stores upserted points by id — which is how a replay is observed as an
    overwrite rather than as a second row — and it evaluates `must` `FieldCondition`s carrying a
    `MatchValue` when counting. Those two are here because they are the two things the code
    under test decides: the id it computes, and the scope it asks for.

    **What it is not.** It is not a Qdrant, it ranks nothing, and it may never stand in for one
    in an isolation test: the server's own filter behaviour is a claim about the server, and
    `tests/security/` proves that against two organizations and a real one. A fake that
    evaluated more would start to be a fixture asserting on itself.
    """

    points: dict[Any, models.PointStruct] = field(default_factory=dict)
    upserts: list[dict[str, Any]] = field(default_factory=list)
    counts: list[dict[str, Any]] = field(default_factory=list)
    fail_with: Exception | None = None

    def upsert(self, **kwargs: Any) -> None:
        if self.fail_with is not None:
            raise self.fail_with
        self.upserts.append(kwargs)
        for point in kwargs["points"]:
            self.points[point.id] = point

    def count(self, **kwargs: Any) -> _CountResult:
        self.counts.append(kwargs)
        wanted = {
            condition.key: condition.match.value
            for condition in (kwargs["count_filter"].must or [])
            if isinstance(condition, models.FieldCondition)
            and isinstance(condition.match, models.MatchValue)
        }
        matching = [
            point
            for point in self.points.values()
            if all((point.payload or {}).get(key) == value for key, value in wanted.items())
        ]
        return _CountResult(len(matching))


def chunks_for(org: str, version: str, how_many: int) -> list[Chunk]:
    ctx = context(org=org, version=version)
    elements = [
        Element(
            element_id=ulid(f"{org}{index}"),
            context=ctx,
            text=f"paragraph {index} " * 20,
            heading_path=("Refunds",),
            page=1,
            content_type="faq",
        )
        for index in range(how_many)
    ]
    return chunk_document(elements=elements, measure=measure_words)


def vectors_for(chunk: Chunk) -> ChunkVectors:
    return ChunkVectors(
        dense=[0.1] * SPACE.dimensions,
        sparse=models.SparseVector(indices=[1, 2], values=[0.5, 0.25]),
    )


def points_for(org: str, version: str, how_many: int) -> list[models.PointStruct]:
    return [
        to_point(chunk=chunk, vectors=vectors_for(chunk), source_status=STATUS)
        for chunk in chunks_for(org, version, how_many)
    ]


# ── to_point ─────────────────────────────────────────────────────────────────


def test_the_payload_is_the_chunk_metadata_schema_plus_source_status() -> None:
    """`source_status` is the one payload key that is not chunk metadata, and it is not on the
    chunk because it changes after indexing: disabling a source excludes it from retrieval
    immediately while keeping every vector, so re-enabling is a payload write."""
    [point] = points_for("orga", "verone", 1)
    payload = point.payload or {}
    assert set(payload) == set(CHUNK_METADATA_FIELDS) | {"source_status"}
    assert payload["source_status"] == STATUS


def test_the_point_id_is_the_deterministic_uuid5_of_org_version_and_seq() -> None:
    """Recomputed here from the metadata rather than read back off the point, so a point id
    carried on the chunk and gone stale cannot satisfy this."""
    for point in points_for("orga", "verone", 3):
        payload = point.payload or {}
        assert point.id == point_id(
            org_id=payload["org_id"],
            source_version_id=payload["source_version_id"],
            seq=payload["seq"],
        )


def test_two_organizations_never_produce_the_same_point_id() -> None:
    first = {point.id for point in points_for("orga", "verone", 5)}
    second = {point.id for point in points_for("orgb", "verone", 5)}
    assert first.isdisjoint(second)


def test_both_named_vectors_ride_on_one_point() -> None:
    """A collection with named vectors has no default branch, so one upsert moving both is what
    keeps the two arms agreeing about which chunks exist."""
    [point] = points_for("orga", "verone", 1)
    assert set(point.vector or {}) == {DENSE_VECTOR_NAME, SPARSE_VECTOR_NAME}


def test_a_uuid_shaped_identifier_is_refused_at_the_write() -> None:
    """The failure it prevents is total and silent: a `keyword` index over ULID values matches
    no UUID, so the tenant filter correctly excludes everything and the response is a
    normal-latency 200 with zero candidates."""
    [chunk] = chunks_for("orga", "verone", 1)
    broken = Chunk(
        text=chunk.text,
        metadata=type(chunk.metadata)(
            **{
                **{name: getattr(chunk.metadata, name) for name in CHUNK_METADATA_FIELDS},
                "org_id": "3f0e2b1c-0000-4000-8000-000000000001",
            }
        ),
    )
    with pytest.raises(KbError) as raised:
        to_point(chunk=broken, vectors=vectors_for(chunk), source_status=STATUS)
    assert raised.value.error_class is ErrorClass.VALIDATION
    assert not raised.value.retryable


def test_an_empty_sparse_vector_is_refused_but_an_absent_one_is_not() -> None:
    """An empty sparse vector is accepted by the upsert, matches nothing forever, and halves
    the hybrid branch for that chunk with no error on either side. A chunk that genuinely
    analyzes to no terms is `sparse=None` — a decision that reached the upsert."""
    [chunk] = chunks_for("orga", "verone", 1)
    with pytest.raises(KbError):
        to_point(
            chunk=chunk,
            vectors=ChunkVectors(
                dense=[0.1] * 8, sparse=models.SparseVector(indices=[], values=[])
            ),
            source_status=STATUS,
        )

    absent = to_point(
        chunk=chunk, vectors=ChunkVectors(dense=[0.1] * 8, sparse=None), source_status=STATUS
    )
    assert set(absent.vector or {}) == {DENSE_VECTOR_NAME}


def test_the_payload_carries_json_shapes_so_a_rebuild_can_be_compared() -> None:
    """ADR-010's rebuild proof re-derives these values from PostgreSQL and object storage. A
    payload holding a datetime object the client happens to render one way today is a rebuild
    that cannot be shown to be identical tomorrow."""
    [point] = points_for("orga", "verone", 1)
    payload = point.payload or {}
    assert isinstance(payload["created_at"], str)
    assert isinstance(payload["bot_ids"], list)
    assert isinstance(payload["heading_path"], list)


# ── upsert_points ────────────────────────────────────────────────────────────


def test_every_upsert_waits_and_names_the_versions_own_collection() -> None:
    """`wait=True` because the default is an acknowledgement of receipt, not a commit —
    verifying against that ack reads a collection still absorbing writes, which passes under
    light load and exposes a partial version under a bulk reindex."""
    client = _FakeQdrant()
    upsert_points(client=client, space=SPACE, points=points_for("orga", "verone", 5))
    assert client.upserts
    for call in client.upserts:
        assert call["wait"] is True
        assert call["collection_name"] == SPACE.collection


def test_points_are_batched_and_the_total_is_returned() -> None:
    client = _FakeQdrant()
    points = points_for("orga", "verone", UPSERT_BATCH_SIZE + 7)
    written = upsert_points(client=client, space=SPACE, points=points)
    assert written == len(points)
    assert [len(call["points"]) for call in client.upserts] == [UPSERT_BATCH_SIZE, 7]


def test_a_replayed_upsert_leaves_the_point_total_unchanged() -> None:
    """The idempotency property the whole module rests on, asserted as the number a redelivery
    would move. Deterministic ids make the second run an overwrite; anything else doubles the
    collection while every response stays 200."""
    client = _FakeQdrant()
    points = points_for("orga", "verone", 40)
    upsert_points(client=client, space=SPACE, points=points)
    first = len(client.points)
    upsert_points(client=client, space=SPACE, points=points_for("orga", "verone", 40))
    assert len(client.points) == first == 40


def test_a_client_failure_is_vector_indexing_and_retryable() -> None:
    client = _FakeQdrant(fail_with=RuntimeError("connection reset"))
    with pytest.raises(KbError) as raised:
        upsert_points(client=client, space=SPACE, points=points_for("orga", "verone", 1))
    assert raised.value.error_class is ErrorClass.VECTOR_INDEXING
    assert raised.value.retryable


# ── verify_indexed_total ─────────────────────────────────────────────────────


def test_the_verification_counts_only_the_asking_organizations_points() -> None:
    """TWO ORGANIZATIONS, and they deliberately share a `source_version_id`.

    In production those identifiers are globally unique ULIDs, so a version-only filter would
    usually give the same number — which is exactly why a one-organization fixture cannot fail
    this test and why the identifier's uniqueness must not be what the scope depends on. With
    the organization term deleted this reads 12 instead of 5 and the version publishes against
    another tenant's points.
    """
    client = _FakeQdrant()
    upsert_points(client=client, space=SPACE, points=points_for("orga", "shared", 5))
    upsert_points(client=client, space=SPACE, points=points_for("orgb", "shared", 7))
    assert len(client.points) == 12

    for org, expected in (("orga", 5), ("orgb", 7)):
        result = verify_indexed_total(
            client=client,
            space=SPACE,
            org_id=ulid(org),
            source_version_id=ulid("shared"),
            expected=expected,
        )
        assert result.indexed == expected, f"{org} counted somebody else's points"
        assert result.passed


def test_the_scope_is_the_identity_pair_and_nothing_else() -> None:
    """No status and no bot predicate: the version is not active and its source is not Ready,
    so the retrieval filter would legitimately match zero points and the check would fail every
    healthy run."""
    client = _FakeQdrant()
    verify_indexed_total(
        client=client,
        space=SPACE,
        org_id=ulid("orga"),
        source_version_id=ulid("verone"),
        expected=0,
    )
    [call] = client.counts
    assert call["exact"] is VERIFY_EXACT is True
    assert call["collection_name"] == SPACE.collection
    scope = call["count_filter"]
    assert scope.should is None and scope.must_not is None
    assert [condition.key for condition in scope.must] == ["org_id", "source_version_id"]
    assert [condition.match.value for condition in scope.must] == [
        ulid("orga"),
        ulid("verone"),
    ]


def test_a_mismatch_fails_the_run_rather_than_repairing_itself() -> None:
    """A verification that fixes what it measures cannot fail. On a mismatch the prior version
    was never touched and keeps serving, which is the entire point of the ordering."""
    client = _FakeQdrant()
    upsert_points(client=client, space=SPACE, points=points_for("orga", "verone", 5))
    result = verify_indexed_total(
        client=client,
        space=SPACE,
        org_id=ulid("orga"),
        source_version_id=ulid("verone"),
        expected=6,
    )
    assert result.passed is False
    assert (result.expected, result.indexed) == (6, 5)
    assert len(client.upserts) == 1, "verification must not write"


def test_a_surplus_of_points_fails_too() -> None:
    """`==`, never `>=`. A tolerant comparison passes a version whose points were written twice
    under non-deterministic ids — the exact failure deterministic ids exist to prevent, and it
    passes on a total that is plausibly large."""
    client = _FakeQdrant()
    upsert_points(client=client, space=SPACE, points=points_for("orga", "verone", 5))
    result = verify_indexed_total(
        client=client,
        space=SPACE,
        org_id=ulid("orga"),
        source_version_id=ulid("verone"),
        expected=4,
    )
    assert result.passed is False


def test_an_unscoped_verification_is_refused() -> None:
    """An empty organization is a total over every tenant in the collection, which is both a
    verification that cannot fail for the right reason and a cross-tenant read."""
    with pytest.raises(KbError):
        verify_indexed_total(
            client=_FakeQdrant(),
            space=SPACE,
            org_id="",
            source_version_id=ulid("verone"),
            expected=1,
        )

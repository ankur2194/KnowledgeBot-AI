"""The deletion filters executed against a real Qdrant, with a tenant that must survive them.

``tests/unit/test_deletion_filters.py`` proves the builders *name* the right terms. This proves
the terms do what the names claim, and it is the half a unit test cannot reach: a ``Filter``
object is not a delete, and filter width is only observable once something has been removed.

**The two organizations share every identifier below.** Same ``source_id``, same
``source_version_id``, same ``chunk_id`` suffixes — only ``org_id`` differs. Real identifiers
are ULIDs and never collide, so a fixture giving each organization its own ids would survive a
delete carrying no organization term at all: nothing would match, nothing would be lost, and the
test would report success while proving nothing about the one mistake in deletion that has no
repair. The collision is the design. It is the same design as
``tests/integration/test_sparse_statistics_purge.py`` on the relational side.

``QdrantClient(":memory:")`` is not usable here and is not used: ``tests/security/README.md``
forbids it for isolation claims, and the local implementation is a reimplementation rather than
the server. A width claim measured against a reimplementation is a claim about the
reimplementation.

Marked ``integration`` so it runs in the pytest step that is allowed to start containers. The
``ai-service`` CI job splits on that marker precisely so a test needing a server cannot be
quietly satisfied by the runner's own Docker daemon inside the "no external services" step.
"""

from __future__ import annotations

import uuid
from collections.abc import Iterator
from typing import Final

import pytest
from qdrant_client import QdrantClient, models

from app.deletion.filters import deletion_filter, identity_filter, organization_filter
from app.retrieval.tenancy import ACTIVE_SOURCE_STATUSES

pytestmark = pytest.mark.integration

#: Four dimensions. The vectors are never searched here — every assertion is a filtered tally —
#: and a width exercises no code path a smaller one does not.
WIDTH: Final[int] = 4

ORG_A: Final[str] = "01JQZ000000000000000000A"
ORG_B: Final[str] = "01JQZ000000000000000000B"

#: Shared by both organizations. See the module docstring: distinct ids would make every
#: assertion below pass against a filter with no organization term.
SOURCE: Final[str] = "01JQZ0000000000000000SRC"
OTHER_SOURCE: Final[str] = "01JQZ00000000000000SRC2"
VERSION_1: Final[str] = "01JQZ0000000000000000VE1"
VERSION_2: Final[str] = "01JQZ0000000000000000VE2"
BOT: Final[str] = "01JQZ00000000000000000BT"

CHUNKS_PER_SCOPE: Final[int] = 3


@pytest.fixture
def client(qdrant_url: str) -> Iterator[QdrantClient]:
    connected = QdrantClient(url=qdrant_url)
    yield connected
    connected.close()


@pytest.fixture
def collection(client: QdrantClient, resource_suffix: str) -> Iterator[str]:
    """A collection unique per xdist worker. Two workers sharing one is how ``gw3``'s delete
    lands in ``gw0``'s tally — and it only ever happens in CI, where the parallelism is."""
    name = f"kb-deletion-width-{resource_suffix}"
    client.create_collection(
        collection_name=name,
        vectors_config=models.VectorParams(size=WIDTH, distance=models.Distance.COSINE),
    )
    yield name
    client.delete_collection(name)


def _payload(
    org_id: str, source_id: str, version_id: str, seq: int, status: str
) -> dict[str, object]:
    """The payload contract, plus ``chunk_id``. Nothing content-addressed rides here, because
    nothing content-addressed may ever be a deletion term."""
    return {
        "org_id": org_id,
        "bot_ids": [BOT],
        "source_id": source_id,
        "source_item_id": f"{source_id}-item",
        "source_version_id": version_id,
        "source_status": status,
        "chunk_id": f"{source_id}-{version_id}-{seq}",
    }


def _seed(client: QdrantClient, collection: str, status: str = "ready") -> None:
    """Both organizations, both sources, both versions, identical but for ``org_id``."""
    points: list[models.PointStruct] = []
    for org_id in (ORG_A, ORG_B):
        for source_id in (SOURCE, OTHER_SOURCE):
            for version_id in (VERSION_1, VERSION_2):
                for seq in range(CHUNKS_PER_SCOPE):
                    payload = _payload(org_id, source_id, version_id, seq, status)
                    points.append(
                        models.PointStruct(
                            id=str(uuid.uuid4()),
                            vector=[0.1, 0.2, 0.3, 0.4],
                            payload=payload,
                        )
                    )
    client.upsert(collection_name=collection, points=points, wait=True)


def _tally(client: QdrantClient, collection: str, built: models.Filter) -> int:
    """An **exact** tally, passed explicitly. The approximate form merges per-segment
    cardinality estimates with no cross-segment dedup, so it is a guess rather than a stale
    truth — and a proof built on a guess is not a proof."""
    return client.count(collection_name=collection, count_filter=built, exact=True).count


def _delete(client: QdrantClient, collection: str, built: models.Filter) -> None:
    client.delete(
        collection_name=collection,
        points_selector=models.FilterSelector(filter=built),
        wait=True,
    )


# ── the survival claim ───────────────────────────────────────────────────────


def test_a_source_delete_removes_org_a_and_leaves_org_b_intact(
    client: QdrantClient, collection: str
) -> None:
    """The positive control comes first, and it is not decoration.

    Without it, an empty collection, a filter that matches nothing, and a delete that removed
    everything all satisfy "Org A is empty afterwards". Assert both organizations are present,
    then delete one, then assert the tallies moved in exactly one direction.
    """
    _seed(client, collection)
    expected = CHUNKS_PER_SCOPE * 2  # two versions of the target source
    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) == expected
    assert _tally(client, collection, identity_filter(ORG_B, SOURCE)) == expected

    _delete(client, collection, deletion_filter(ORG_A, source_id=SOURCE))

    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) == 0
    assert _tally(client, collection, identity_filter(ORG_B, SOURCE)) == expected
    assert _tally(client, collection, identity_filter(ORG_A, OTHER_SOURCE)) == expected


def test_an_organization_purge_stops_at_the_organization_boundary(
    client: QdrantClient, collection: str
) -> None:
    """``organization_filter`` is the widest filter this module builds, and the boundary it does
    not cross is the only thing making it safe to build at all."""
    _seed(client, collection)
    whole_org = CHUNKS_PER_SCOPE * 4  # two sources x two versions

    assert _tally(client, collection, organization_filter(ORG_A)) == whole_org
    assert _tally(client, collection, organization_filter(ORG_B)) == whole_org

    _delete(client, collection, organization_filter(ORG_A))

    assert _tally(client, collection, organization_filter(ORG_A)) == 0
    assert _tally(client, collection, organization_filter(ORG_B)) == whole_org


def test_retiring_one_version_leaves_the_other_version_and_the_other_tenant(
    client: QdrantClient, collection: str
) -> None:
    """The recrawl case: the id is exact and known, and it is the only place a version-scoped
    delete is legitimate. Both organizations hold points under the **same** version id."""
    _seed(client, collection)
    per_version = CHUNKS_PER_SCOPE

    _delete(
        client,
        collection,
        deletion_filter(ORG_A, source_id=SOURCE, source_version_ids=[VERSION_1]),
    )

    survivors = _by_version(client, collection, ORG_A, SOURCE)
    assert survivors[VERSION_1] == 0
    assert survivors[VERSION_2] == per_version
    others = _by_version(client, collection, ORG_B, SOURCE)
    assert others[VERSION_1] == per_version
    assert others[VERSION_2] == per_version


def _by_version(
    client: QdrantClient, collection: str, org_id: str, source_id: str
) -> dict[str, int]:
    return {
        version_id: _tally(
            client,
            collection,
            deletion_filter(org_id, source_id=source_id, source_version_ids=[version_id]),
        )
        for version_id in (VERSION_1, VERSION_2)
    }


# ── the proof, and the tautology it must not become ─────────────────────────


def test_the_identity_filter_still_sees_points_whose_source_was_already_marked_deleting(
    client: QdrantClient, collection: str
) -> None:
    """Why the proof carries no ``source_status`` term, executed rather than argued.

    Phase 1 has already run by the time phase 2 starts: the source is ``deleting`` and no query
    can reach it. A verification filter that reused retrieval's status term would therefore tally
    **zero before anything was purged** — it would pass on day one, pass forever, and never again
    distinguish a completed purge from one that silently no-opped. ``identity_filter`` still sees
    them, which is what lets it fail.
    """
    _seed(client, collection, status="deleting")
    surviving = CHUNKS_PER_SCOPE * 2

    status_scoped = models.Filter(
        must=[
            models.FieldCondition(key="org_id", match=models.MatchValue(value=ORG_A)),
            models.FieldCondition(key="source_id", match=models.MatchValue(value=SOURCE)),
            models.FieldCondition(
                key="source_status", match=models.MatchAny(any=list(ACTIVE_SOURCE_STATUSES))
            ),
        ]
    )
    assert _tally(client, collection, status_scoped) == 0, (
        "the status-scoped filter reports a clean store while every point is still there — "
        "this is the tautology identity_filter exists to avoid"
    )
    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) == surviving


def test_the_proof_fails_when_the_delete_silently_removed_nothing(
    client: QdrantClient, collection: str
) -> None:
    """A store where deletion silently failed must report failure, not pass.

    Simulated by never issuing the delete — which is exactly what a purge that no-opped leaves
    behind, and it leaves no other evidence: both cases produce a 200 and an unchanged store.
    """
    _seed(client, collection)
    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) > 0


# ── idempotency: the retry after a partial run ──────────────────────────────


def test_deleting_an_already_deleted_source_is_a_no_op_and_not_an_error(
    client: QdrantClient, collection: str
) -> None:
    """A purge task will rerun. Partial completion is the normal case, not the exception, so
    "already absent" has to be a pass — and the second run must still not touch Org B."""
    _seed(client, collection)
    built = deletion_filter(ORG_A, source_id=SOURCE)

    _delete(client, collection, built)
    _delete(client, collection, built)
    _delete(client, collection, built)

    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) == 0
    assert _tally(client, collection, identity_filter(ORG_B, SOURCE)) == CHUNKS_PER_SCOPE * 2


def test_a_source_id_belonging_to_another_tenant_matches_nothing(
    client: QdrantClient, collection: str
) -> None:
    """The organization term is redundant for correctness and mandatory for safety.

    Org B is handed Org A's source id — the shape a mixed-up job argument takes. With the
    organization term the delete matches nothing; without it, it would take Org A's source.
    Seeded so that Org B genuinely holds no such source, and Org A genuinely does.
    """
    points = [
        models.PointStruct(
            id=str(uuid.uuid4()),
            vector=[0.1, 0.2, 0.3, 0.4],
            payload=_payload(ORG_A, SOURCE, VERSION_1, seq, "ready"),
        )
        for seq in range(CHUNKS_PER_SCOPE)
    ]
    client.upsert(collection_name=collection, points=points, wait=True)

    _delete(client, collection, deletion_filter(ORG_B, source_id=SOURCE))

    assert _tally(client, collection, identity_filter(ORG_A, SOURCE)) == CHUNKS_PER_SCOPE

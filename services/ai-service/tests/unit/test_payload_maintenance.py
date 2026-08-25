"""The two payload rewrites that make a control-plane change reach the index.

The trap these tests exist for is named at
`services/core-api/app/Services/Bots/BotService.php`: ``bot_ids`` is a LIST on each point, so
removing one bot's access is a read-modify-write and never a delete-by-filter — a delete
removes the chunks every other bot on that source still answers from, at HTTP 200, with the
first symptom weeks later. Half of what follows is about that one sentence.

The other half is the verification count. A ``set_payload`` against a filter matching nothing
returns the same acknowledgement as one that rewrote ten thousand points, so the assertions
here are on the *counted* state afterwards, never on "the call did not raise".
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import pytest
from qdrant_client import models

from app.core.errors import ErrorClass, KbError
from app.maintenance.payload import (
    MAX_REWRITE_PASSES,
    SYNCABLE_STATUSES,
    PayloadSyncFailed,
    sync_bot_access,
    sync_source_status,
)
from app.retrieval.collection import EmbeddingSpace

ORG = "01J0000000000000000000000A"
OTHER_ORG = "01J0000000000000000000000B"
SOURCE = "01J000000000000000000000S1"
OTHER_SOURCE = "01J000000000000000000000S2"
BOT = "01J000000000000000000000B1"
SIBLING_BOT = "01J000000000000000000000B2"

SPACE = EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=3072)
SECOND_SPACE = EmbeddingSpace(provider="openai", model="text-embedding-3-small", dimensions=1536)


def _matches(payload: dict[str, Any], condition: Any) -> bool:
    """One `FieldCondition` against one payload, with list membership.

    Qdrant matches an array payload field element-wise — that is why ``bot_ids`` is filterable
    at all — so this double has to do the same or every ``bot_ids`` assertion below would be
    testing the double's scalar comparison instead of the code's filter.
    """
    value = payload.get(condition.key)
    held = value if isinstance(value, list) else [value]
    if isinstance(condition.match, models.MatchValue):
        return condition.match.value in held
    if isinstance(condition.match, models.MatchAny):
        return any(item in held for item in condition.match.any)
    raise AssertionError(f"this double does not evaluate {type(condition.match).__name__}")


def _selected(points: dict[Any, dict[str, Any]], scope: models.Filter) -> list[Any]:
    def keep(payload: dict[str, Any]) -> bool:
        must = all(_matches(payload, c) for c in (scope.must or []))
        blocked = any(_matches(payload, c) for c in (scope.must_not or []))
        return must and not blocked

    return [pid for pid, payload in points.items() if keep(payload)]


@dataclass
class _ScrollPoint:
    id: Any
    payload: dict[str, Any]


@dataclass
class _Count:
    count: int


@dataclass
class _FakeQdrant:
    """Enough of a client to hold payloads and evaluate the filters this module builds.

    **What it does.** It stores a payload per point id per collection, evaluates ``must`` and
    ``must_not`` over ``MatchValue`` and ``MatchAny`` with list membership, pages a scroll, and
    applies ``set_payload`` addressed either to a filter or to a list of ids. Those are exactly
    the four behaviours the code under test decides against.

    **What it is not.** It is not a Qdrant. It ranks nothing and it must never stand in for one
    in an isolation test — the server's own filter semantics are a claim about the server, and
    `tests/security/` proves that against two organizations and a real one.
    """

    collections: dict[str, dict[Any, dict[str, Any]]] = field(default_factory=dict)
    #: Set to make ``set_payload`` acknowledge without applying — the failure the verification
    #: count and ``MAX_REWRITE_PASSES`` both exist to catch.
    swallow_writes: bool = False
    set_payload_calls: list[dict[str, Any]] = field(default_factory=list)
    counts: list[dict[str, Any]] = field(default_factory=list)

    def collection_exists(self, *, collection_name: str) -> bool:
        return collection_name in self.collections

    def count(self, **kwargs: Any) -> _Count:
        self.counts.append(kwargs)
        assert kwargs["exact"] is True, "an approximate count is not a proof"
        points = self.collections[kwargs["collection_name"]]
        return _Count(len(_selected(points, kwargs["count_filter"])))

    def scroll(self, **kwargs: Any) -> tuple[list[_ScrollPoint], None]:
        assert kwargs["with_vectors"] is False
        points = self.collections[kwargs["collection_name"]]
        chosen = _selected(points, kwargs["scroll_filter"])[: kwargs["limit"]]
        return ([_ScrollPoint(pid, dict(points[pid])) for pid in chosen], None)

    def set_payload(self, **kwargs: Any) -> None:
        self.set_payload_calls.append(kwargs)
        assert kwargs["wait"] is True, "verifying against an unwaited write reads a warm cache"
        if self.swallow_writes:
            return
        points = self.collections[kwargs["collection_name"]]
        target = kwargs["points"]
        ids = _selected(points, target) if isinstance(target, models.Filter) else list(target)
        for pid in ids:
            points[pid].update(kwargs["payload"])


def _point(
    *,
    org: str = ORG,
    source: str = SOURCE,
    bots: tuple[str, ...] = (BOT,),
    status: str = "ready",
) -> dict[str, Any]:
    return {"org_id": org, "source_id": source, "bot_ids": list(bots), "source_status": status}


def _client(*payloads: dict[str, Any], collection: str | None = None) -> _FakeQdrant:
    name = collection or SPACE.collection
    return _FakeQdrant(collections={name: {index: dict(p) for index, p in enumerate(payloads)}})


# ── source.status.sync ────────────────────────────────────────────────────────


def test_disabling_rewrites_every_point_of_the_source_and_counts_the_proof() -> None:
    """The whole reason this module exists: Laravel owns the status column, retrieval reads the
    payload term, and until this call nothing joined them — a disabled source went on answering
    with `status = disabled` in PostgreSQL and every dashboard green."""
    client = _client(_point(), _point(), _point())

    outcome = sync_source_status(
        client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
    )

    assert outcome.passed
    assert (outcome.rewritten, outcome.verified) == (3, 3)
    points = client.collections[SPACE.collection].values()
    assert all(p["source_status"] == "disabled" for p in points)


def test_enabling_restores_the_value_the_indexer_wrote() -> None:
    """`ready`, because that is what `app/ingestion/pipeline.py` writes on every point it
    indexes — the payload term is the SOURCE's status, and a version is kept out of retrieval by
    the active-version filter rather than by a non-active status."""
    client = _client(_point(status="disabled"), _point(status="disabled"))

    outcome = sync_source_status(
        client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="ready"
    )

    assert outcome.verified == 2
    assert {p["source_status"] for p in client.collections[SPACE.collection].values()} == {"ready"}


@pytest.mark.parametrize("status", ["deleting", "indexing", "ready_with_warnings", ""])
def test_a_status_outside_the_syncable_pair_is_refused(status: str) -> None:
    """The processing states belong to a version and the deletion states belong to
    `app/deletion/`; writing either here puts the index into a state the control plane has no
    column for."""
    assert status not in SYNCABLE_STATUSES
    with pytest.raises(ValueError, match="not one of"):
        sync_source_status(
            client=_client(_point()),
            spaces=[SPACE],
            org_id=ORG,
            source_id=SOURCE,
            source_status=status,
        )


def test_another_organizations_points_are_never_touched() -> None:
    """`org_id` is on every term this module builds and there is no code path that omits it."""
    client = _client(_point(), _point(org=OTHER_ORG))

    sync_source_status(
        client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
    )

    statuses = [p["source_status"] for p in client.collections[SPACE.collection].values()]
    assert statuses == ["disabled", "ready"]


def test_another_source_in_the_same_organization_is_never_touched() -> None:
    client = _client(_point(), _point(source=OTHER_SOURCE))

    sync_source_status(
        client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
    )

    assert client.collections[SPACE.collection][1]["source_status"] == "ready"


def test_a_source_with_no_indexed_points_passes_on_zero_and_writes_nothing() -> None:
    """Honest rather than lenient: there was nothing to rewrite. What is NOT allowed to look
    like this is an empty collection set, which is the next test."""
    client = _client(_point(source=OTHER_SOURCE))

    outcome = sync_source_status(
        client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
    )

    assert outcome.passed
    assert [c.in_scope for c in outcome.collections] == [0]
    assert client.set_payload_calls == [], "a filtered write over an empty scope is still a write"


def test_no_embedding_space_is_a_refusal_and_not_a_pass() -> None:
    """Zero collections produces zero rewritten and zero verified, which is indistinguishable
    from a successful rewrite of a source with no points. The difference matters: the caller
    commits a status change on the strength of this answer."""
    with pytest.raises(ValueError, match="no embedding space"):
        sync_source_status(
            client=_client(_point()),
            spaces=[],
            org_id=ORG,
            source_id=SOURCE,
            source_status="disabled",
        )


def test_every_collection_the_source_lives_in_is_addressed() -> None:
    """A source re-indexed after its organization changed embedding model has versions in two
    collections. Rewriting one leaves half the corpus answering with the old status."""
    client = _FakeQdrant(
        collections={
            SPACE.collection: {0: _point()},
            SECOND_SPACE.collection: {0: _point()},
        }
    )

    outcome = sync_source_status(
        client=client,
        spaces=[SPACE, SECOND_SPACE],
        org_id=ORG,
        source_id=SOURCE,
        source_status="disabled",
    )

    assert {c.collection for c in outcome.collections} == {
        SPACE.collection,
        SECOND_SPACE.collection,
    }
    assert outcome.verified == 2


def test_two_versions_in_one_space_address_that_collection_once() -> None:
    """Two version rows under the same embedding identity name one collection. Addressing it
    twice doubles every count in the outcome, so the totals stop meaning "points in this
    source"."""
    client = _client(_point(), _point())

    outcome = sync_source_status(
        client=client,
        spaces=[SPACE, EmbeddingSpace(provider="openai", model=SPACE.model, dimensions=3072)],
        org_id=ORG,
        source_id=SOURCE,
        source_status="disabled",
    )

    assert len(outcome.collections) == 1
    assert outcome.verified == 2


def test_a_collection_that_does_not_exist_is_reported_rather_than_swallowed() -> None:
    """A version identity is recorded before its collection is created, and a fully purged
    space leaves the name behind. Both are "nothing to do" — and an operator has to be able to
    tell them from "addressed the wrong name", which is otherwise also a zero."""
    client = _FakeQdrant(collections={SPACE.collection: {0: _point()}})

    outcome = sync_source_status(
        client=client,
        spaces=[SPACE, SECOND_SPACE],
        org_id=ORG,
        source_id=SOURCE,
        source_status="disabled",
    )

    absent = next(c for c in outcome.collections if c.collection == SECOND_SPACE.collection)
    assert (absent.present, absent.passed, absent.in_scope) == (False, True, 0)


def test_a_write_acknowledged_without_being_applied_fails_verification() -> None:
    """The false pass this module is shaped to prevent. `set_payload` returns the same
    acknowledgement whether it rewrote ten thousand points or none."""
    client = _client(_point(), _point())
    client.swallow_writes = True

    with pytest.raises(PayloadSyncFailed, match="did not verify"):
        sync_source_status(
            client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
        )


def test_a_client_failure_is_classified_as_retryable_vector_indexing() -> None:
    class _Broken(_FakeQdrant):
        def count(self, **kwargs: Any) -> _Count:
            raise RuntimeError("connection reset")

    client = _Broken(collections={SPACE.collection: {0: _point()}})
    with pytest.raises(KbError) as raised:
        sync_source_status(
            client=client, spaces=[SPACE], org_id=ORG, source_id=SOURCE, source_status="disabled"
        )
    assert raised.value.error_class is ErrorClass.VECTOR_INDEXING


# ── bot.access.sync ───────────────────────────────────────────────────────────


def test_revoking_removes_one_id_and_leaves_every_sibling_bot_answering() -> None:
    """THE trap. `bot_ids` is a list shared by every bot the source is assigned to, so a
    delete-by-filter on it destroys the chunks the other bots still answer from."""
    client = _client(_point(bots=(BOT, SIBLING_BOT)), _point(bots=(SIBLING_BOT,)))

    outcome = sync_bot_access(
        client=client,
        spaces=[SPACE],
        org_id=ORG,
        bot_id=BOT,
        source_ids=[SOURCE],
        grant=False,
    )

    assert outcome.passed and outcome.verified == 0
    remaining = [p["bot_ids"] for p in client.collections[SPACE.collection].values()]
    assert remaining == [[SIBLING_BOT], [SIBLING_BOT]]


def test_revoking_twice_rewrites_nothing_the_second_time() -> None:
    """Convergent by construction: the scroll filter selects the points still NEEDING the
    change, so a redelivery finds none. A version that rewrote "every point in scope" would
    write every payload back to the value it already held on every retry."""
    client = _client(_point(bots=(BOT, SIBLING_BOT)))
    common = {
        "client": client,
        "spaces": [SPACE],
        "org_id": ORG,
        "bot_id": BOT,
        "source_ids": [SOURCE],
        "grant": False,
    }

    first = sync_bot_access(**common)
    second = sync_bot_access(**common)

    assert (first.rewritten, second.rewritten) == (1, 0)
    assert first.passed and second.passed


def test_granting_adds_the_id_to_every_point_of_the_source() -> None:
    """The mirror gap, and it is real: a bot assigned to an already-indexed source retrieves
    nothing until the payload carries its id, because `bot_ids` is a mandatory query term."""
    client = _client(_point(bots=(SIBLING_BOT,)), _point(bots=(SIBLING_BOT,)))

    outcome = sync_bot_access(
        client=client, spaces=[SPACE], org_id=ORG, bot_id=BOT, source_ids=[SOURCE], grant=True
    )

    assert outcome.passed and outcome.verified == 2
    assert all(BOT in p["bot_ids"] for p in client.collections[SPACE.collection].values())


def test_granting_is_idempotent_and_never_duplicates_the_id() -> None:
    client = _client(_point(bots=(BOT,)))

    outcome = sync_bot_access(
        client=client, spaces=[SPACE], org_id=ORG, bot_id=BOT, source_ids=[SOURCE], grant=True
    )

    assert outcome.rewritten == 0
    assert client.collections[SPACE.collection][0]["bot_ids"] == [BOT]


def test_an_unscoped_grant_is_refused() -> None:
    """`source_ids=None` means every point the tenant owns. Revoking org-wide is legitimate — a
    deleted bot, whose assignment rows are gone — and granting org-wide is nothing anybody asks
    for on purpose."""
    with pytest.raises(ValueError, match="organization-wide grant"):
        sync_bot_access(
            client=_client(_point()),
            spaces=[SPACE],
            org_id=ORG,
            bot_id=BOT,
            source_ids=None,
            grant=True,
        )


def test_an_org_wide_revoke_reaches_sources_laravel_can_no_longer_enumerate() -> None:
    """A deleted bot's assignment rows are gone, so the control plane cannot name its sources.
    An id left behind in a payload is a term a future bot with a recycled id could match."""
    client = _client(
        _point(bots=(BOT,)),
        _point(source=OTHER_SOURCE, bots=(BOT, SIBLING_BOT)),
        _point(org=OTHER_ORG, bots=(BOT,)),
    )

    outcome = sync_bot_access(
        client=client, spaces=[SPACE], org_id=ORG, bot_id=BOT, source_ids=None, grant=False
    )

    assert outcome.passed and outcome.rewritten == 2
    points = client.collections[SPACE.collection]
    assert points[0]["bot_ids"] == [] and points[1]["bot_ids"] == [SIBLING_BOT]
    assert points[2]["bot_ids"] == [BOT], "the other organization is out of scope"


def test_points_with_different_assignment_sets_are_grouped_into_one_write_each() -> None:
    """A page of points sharing a resulting value is one `set_payload`, not one per point. The
    grouping is what keeps an org-wide revoke from being a round trip per chunk."""
    client = _client(
        _point(bots=(BOT, SIBLING_BOT)),
        _point(bots=(BOT, SIBLING_BOT)),
        _point(bots=(BOT,)),
    )

    sync_bot_access(
        client=client, spaces=[SPACE], org_id=ORG, bot_id=BOT, source_ids=[SOURCE], grant=False
    )

    payloads = [call["payload"]["bot_ids"] for call in client.set_payload_calls]
    assert sorted(len(call["points"]) for call in client.set_payload_calls) == [1, 2]
    assert sorted(payloads) == [[], [SIBLING_BOT]]


def test_a_point_with_no_bot_ids_key_is_treated_as_unassigned() -> None:
    """A real state — a source assigned to no bot at index time. Refusing it would abort an
    org-wide revoke halfway, after it had already rewritten the points before it."""
    client = _client({"org_id": ORG, "source_id": SOURCE, "source_status": "ready"})

    outcome = sync_bot_access(
        client=client, spaces=[SPACE], org_id=ORG, bot_id=BOT, source_ids=[SOURCE], grant=True
    )

    assert outcome.verified == 1
    assert client.collections[SPACE.collection][0]["bot_ids"] == [BOT]


def test_a_non_list_bot_ids_is_refused_rather_than_replaced() -> None:
    """The alternative is silently replacing whatever it was with a one-element list and
    calling that a repair."""
    client = _client({"org_id": ORG, "source_id": SOURCE, "bot_ids": BOT})

    with pytest.raises(PayloadSyncFailed, match="non-list bot_ids"):
        sync_bot_access(
            client=client,
            spaces=[SPACE],
            org_id=ORG,
            bot_id=BOT,
            source_ids=[SOURCE],
            grant=False,
        )


def test_a_rewrite_that_never_applies_stops_at_the_pass_cap() -> None:
    """The loop re-scrolls from the beginning every pass — paging with an offset would skip the
    point that moved up into a rewritten one's place — so a write that is acknowledged without
    being applied is an infinite loop rather than a slow one."""
    client = _client(_point(bots=(BOT,)))
    client.swallow_writes = True

    with pytest.raises(PayloadSyncFailed, match=str(MAX_REWRITE_PASSES)):
        sync_bot_access(
            client=client,
            spaces=[SPACE],
            org_id=ORG,
            bot_id=BOT,
            source_ids=[SOURCE],
            grant=False,
        )


def test_a_blank_identifier_is_refused_rather_than_matched() -> None:
    """`MatchValue` on `""` matches no point, so the operation is a no-op whose verification
    count agrees with it."""
    with pytest.raises(ValueError):
        sync_bot_access(
            client=_client(_point()),
            spaces=[SPACE],
            org_id=ORG,
            bot_id="",
            source_ids=[SOURCE],
            grant=False,
        )
    with pytest.raises(ValueError):
        sync_source_status(
            client=_client(_point()),
            spaces=[SPACE],
            org_id="",
            source_id=SOURCE,
            source_status="disabled",
        )

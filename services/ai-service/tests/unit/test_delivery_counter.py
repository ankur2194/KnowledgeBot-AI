"""The durable redelivery counter.

`app/ingestion/deliveries.py` replaced an `UPDATE source_versions` that this service was not
permitted to issue. The tests below hold the three properties that made the replacement viable,
because each is a property the column had for free and a cache key does not:

* it counts (the column had a `+ 1`),
* it is tenant-scoped (the column was reached through `WHERE organization_id = %s`),
* it survives (the column was durable by being a row).

The fake is a dict, deliberately: this module imports no client library, so a test that needed a
server would be testing Valkey rather than the counter.
"""

from __future__ import annotations

import pytest

from app.ingestion import deliveries


class FakeCache:
    """The two generic commands `deliveries` uses, and a record of what it called."""

    def __init__(self) -> None:
        self.values: dict[str, int] = {}
        self.ttls: dict[str, int] = {}
        self.calls: list[tuple[str, str]] = []

    async def incr(self, key: str) -> int:
        self.calls.append(("incr", key))
        self.values[key] = self.values.get(key, 0) + 1
        return self.values[key]

    async def expire(self, key: str, ttl: int) -> bool:
        self.calls.append(("expire", key))
        self.ttls[key] = ttl
        return True


@pytest.mark.asyncio
async def test_it_counts_from_one_and_keeps_counting() -> None:
    cache = FakeCache()

    counts = [
        await deliveries.bump(cache, org_id="org-a", scope=deliveries.version_scope("ver-1"))
        for _ in range(4)
    ]

    assert counts == [1, 2, 3, 4]


@pytest.mark.asyncio
async def test_two_organizations_do_not_share_a_budget() -> None:
    """An unscoped counter is a cross-tenant channel, however boring the value.

    With one key for both, tenant A's redeliveries push tenant B past `MAX_DELIVERIES` and B's
    document fails as `internal_dependency` having been delivered once.
    """
    cache = FakeCache()
    scope = deliveries.version_scope("ver-1")

    assert await deliveries.bump(cache, org_id="org-a", scope=scope) == 1
    assert await deliveries.bump(cache, org_id="org-b", scope=scope) == 1
    assert await deliveries.bump(cache, org_id="org-a", scope=scope) == 2


@pytest.mark.asyncio
async def test_the_two_phases_count_separately() -> None:
    """`prepare_item` and `run_version` are different units of work.

    They are bounded by the same number and must not share a key: a prepare that was redelivered
    twice before succeeding would otherwise hand the run a budget of one.
    """
    cache = FakeCache()

    prepare = deliveries.prepare_scope(job_id="job-1", item_id="item-1")
    run = deliveries.version_scope("ver-1")

    assert await deliveries.bump(cache, org_id="org-a", scope=prepare) == 1
    assert await deliveries.bump(cache, org_id="org-a", scope=prepare) == 2
    assert await deliveries.bump(cache, org_id="org-a", scope=run) == 1


@pytest.mark.asyncio
async def test_the_ttl_slides_so_a_slow_redelivery_cycle_cannot_reset_the_bound() -> None:
    """The one failure mode a redelivery bound may not have.

    A fixed window from the first delivery lets a cycle slower than the window start again from
    zero every time — which is the runaway this counter exists to stop, arriving more slowly.
    """
    cache = FakeCache()
    scope = deliveries.version_scope("ver-1")

    await deliveries.bump(cache, org_id="org-a", scope=scope)
    await deliveries.bump(cache, org_id="org-a", scope=scope)

    key = deliveries.delivery_key(org_id="org-a", scope=scope)
    assert cache.calls == [("incr", key), ("expire", key), ("incr", key), ("expire", key)]
    assert cache.ttls[key] == deliveries.DELIVERY_TTL_SECONDS


@pytest.mark.asyncio
async def test_a_non_positive_ttl_is_refused_rather_than_written() -> None:
    # `EXPIRE key 0` deletes the key on some servers and is an error on others. Neither is a
    # counter, and both fail in the direction that loses the bound.
    with pytest.raises(ValueError, match="ttl_seconds must be positive"):
        await deliveries.bump(
            FakeCache(), org_id="org-a", scope=deliveries.version_scope("v"), ttl_seconds=0
        )


def test_the_key_names_the_organization_first() -> None:
    key = deliveries.delivery_key(org_id="org-a", scope=deliveries.version_scope("ver-1"))
    assert key == "deliveries:org-a:version:ver-1"
    assert key.startswith("deliveries:org-a:")

"""The coordination primitives, against a real Valkey — and the fake, against the same rules.

``app/api/deps.py``'s replay nonce and ``app/core/idempotency.py``'s claim record both live on
`valkey-core`'s coordination DB, and both are **absence-sensitive**: losing the key fails open.
Everything they depend on is a server behaviour rather than a client one — whether ``SET NX``
reports ``None`` or ``False``, whether ``EX`` rides the same command, whether ``DELIFEQ`` exists
at all — so a test written against a hand-written double proves only that the double agrees
with the code that was written beside it.

``COORDINATION_CONTRACT`` is the answer: one set of assertions, run here against a real server
and in `tests/contract/` against ``InMemoryCoordination``. A behaviour the fake gets wrong fails
in this tier, by name, instead of silently weakening the tier that runs on every push.

``DELIFEQ`` is a **Valkey 9.0.0+ verb with no Redis equivalent and no redis-py binding**, which
is exactly why it needs a server test: a unit test can only assert that we sent the string.
"""

from __future__ import annotations

import uuid
from collections.abc import AsyncIterator, Awaitable, Callable
from typing import Any

import pytest

from app.core.idempotency import claim, idempotency_key, new_claim_token, release
from tests.support.coordination import COORDINATION_CONTRACT, InMemoryCoordination

pytestmark = pytest.mark.integration


@pytest.fixture
async def valkey(valkey_url: str) -> AsyncIterator[Any]:
    """A real client on the worker's own logical DB.

    Never ``FLUSHDB``: the DB index is worker-scoped but the server is shared with whatever else
    the merge-queue job is running, and a flush is the one operation that can destroy another
    worker's state. Every key below is prefixed with a fresh uuid instead.
    """
    from redis.asyncio import Redis

    client = Redis.from_url(valkey_url)
    try:
        yield client
    finally:
        await client.aclose()


@pytest.fixture
def prefix() -> str:
    return f"kbtest:{uuid.uuid4().hex}"


_CONTRACT_IDS = [name for name, _ in COORDINATION_CONTRACT]


@pytest.mark.parametrize(("name", "assertion"), COORDINATION_CONTRACT, ids=_CONTRACT_IDS)
async def test_the_real_server_obeys_the_contract(
    valkey: Any, prefix: str, name: str, assertion: Callable[[Any, str], Awaitable[None]]
) -> None:
    await assertion(valkey, f"{prefix}:real")


@pytest.mark.parametrize(("name", "assertion"), COORDINATION_CONTRACT, ids=_CONTRACT_IDS)
async def test_the_in_memory_double_obeys_the_same_contract(
    prefix: str, name: str, assertion: Callable[[Any, str], Awaitable[None]]
) -> None:
    """The pairing that makes the contract tier's fake worth anything. Run here rather than in
    `unit/` so the two arms fail in the same report, against the same assertions, in the same
    run — a fake asserted somewhere else is a fake that drifts between two green suites."""
    await assertion(InMemoryCoordination(), f"{prefix}:fake")


# ── the claim, end to end ─────────────────────────────────────────────────────


async def test_a_second_execution_cannot_claim_a_key_the_first_holds(
    valkey: Any, prefix: str
) -> None:
    key = idempotency_key(org_id=prefix, operation="ingestion.submit", key="abc")
    first, second = new_claim_token(), new_claim_token()

    assert await claim(valkey, key, 120, token=first) is True
    assert await claim(valkey, key, 120, token=second) is False


async def test_the_claim_carries_its_ttl_from_the_same_command(valkey: Any, prefix: str) -> None:
    """``SET`` then ``EXPIRE`` is a bug, not a shortcut: die in between and the key is immortal
    and that operation is suppressed forever (`valkey-keyspaces`)."""
    key = idempotency_key(org_id=prefix, operation="ingestion.submit", key="ttl")

    await claim(valkey, key, 300, token=new_claim_token())

    assert 0 < await valkey.ttl(key) <= 300


@pytest.mark.parametrize("ttl", [0, -1, -900])
async def test_a_non_positive_ttl_is_refused_before_it_reaches_the_server(
    valkey: Any, prefix: str, ttl: int
) -> None:
    """The one part of the module's stated invariant that is checkable where it is stated. "The
    TTL must exceed the task's hard time limit" is not — this module knows no time limits, and
    importing ``app/worker`` to find out would drag Celery into every coordination call site.
    The degenerate case is checkable and is worth the line: a claim with no TTL is a permanent
    suppression of that operation for that organization.
    """
    key = idempotency_key(org_id=prefix, operation="ingestion.submit", key="bad-ttl")

    with pytest.raises(ValueError, match="ttl_seconds must be positive"):
        await claim(valkey, key, ttl, token=new_claim_token())

    assert await valkey.get(key) is None


async def test_release_after_a_failure_lets_the_retry_claim_again(valkey: Any, prefix: str) -> None:
    key = idempotency_key(org_id=prefix, operation="ingestion.submit", key="retry")
    token = new_claim_token()

    assert await claim(valkey, key, 120, token=token) is True
    await release(valkey, key, token=token)

    assert await claim(valkey, key, 120, token=new_claim_token()) is True


async def test_release_cannot_drop_a_claim_a_different_execution_now_holds(
    valkey: Any, prefix: str
) -> None:
    """THE RESIDUAL A BARE ``DELETE`` WOULD LEAVE OPEN, closed against a real server.

    If the first execution's TTL lapsed while it was still running — a 15-minute OCR call, a GC
    pause, a frozen container — a redelivery has already claimed the key. The original's failure
    path then calls ``release``, and with a plain ``DEL`` it would delete the live claim and let
    a third copy start. ``DELIFEQ`` compares first, server-side, in one command.
    """
    key = idempotency_key(org_id=prefix, operation="ingestion.submit", key="stale")
    stale_token = new_claim_token()

    # The first execution claims, and its claim then lapses.
    assert await claim(valkey, key, 120, token=stale_token) is True
    await valkey.delete(key)

    # A redelivery claims the same key.
    live_token = new_claim_token()
    assert await claim(valkey, key, 120, token=live_token) is True

    # The first execution finally fails and releases. It must not touch the live claim.
    await release(valkey, key, token=stale_token)

    assert await valkey.get(key) == live_token.encode()
    assert await claim(valkey, key, 120, token=new_claim_token()) is False


async def test_delifeq_exists_on_this_server(valkey: Any, prefix: str) -> None:
    """Named separately from the behaviour above, because the failure modes differ and only one
    of them is safe. A server without ``DELIFEQ`` answers with an unknown-command error and
    deletes nothing — the claim survives its TTL and the operation is retried late rather than
    run twice — but "release silently never works" is worth failing a build over rather than
    discovering from a latency graph."""
    result = await valkey.execute_command("DELIFEQ", f"{prefix}:absent", "x")

    assert result == 0


async def test_the_key_scheme_puts_the_organization_second(valkey: Any) -> None:
    """`kb-tenancy-isolation` / `valkey-keyspaces`: family first so a family is greppable and
    ACL-matchable, org second, always. An unscoped idempotency key lets one tenant's replay
    suppress another tenant's work."""
    key = idempotency_key(org_id="01JQZORG", operation="ingestion.submit", key="deadbeef")

    assert key == "idem:01JQZORG:ingestion.submit:deadbeef"
    assert key.split(":")[0] == "idem"
    assert key.split(":")[1] == "01JQZORG"

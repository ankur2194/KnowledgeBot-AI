"""What ``await pool.open()`` actually guarantees — measured, not described.

``app/db/pool.py``'s docstring carried a false mechanism for one revision: that awaiting
``open()`` fills the pool to ``min_size`` before ``lifespan`` yields, and that this is what
stops ``/health/ready`` reporting green against an unreachable database. Both halves were
wrong, and nothing in the suite could tell — the integration test next door asserted
``pool_size >= 2``, a stat that reads ``2`` against a host no packet can reach.

These are the two facts the corrected docstring rests on, so the docstring cannot go stale in
silence. **No server is involved and none is needed**: the address is TEST-NET-3
(RFC 5737 §3), reserved for documentation and guaranteed unroutable, and every assertion here
is made before any connection could have succeeded even if it were.

Not integration-marked on purpose. The whole point is that this path performs no round trip;
requiring a container to prove that would be requiring the thing being disproved.
"""

from __future__ import annotations

import inspect
import warnings

from psycopg_pool import AsyncConnectionPool

from app.db.pool import close_pool, open_pool

#: RFC 5737 §3 TEST-NET-3. `connect_timeout=1` bounds the background workers' attempts so a
#: firewall that blackholes rather than refuses cannot make `close_pool` wait on them.
UNROUTABLE_DSN = "postgresql://u:p@203.0.113.9:5432/nope?connect_timeout=1"


def test_open_defaults_to_not_waiting_for_a_connection() -> None:
    """The mechanism, read off the pinned library rather than restated in prose.

    ``AsyncConnectionPool.open(wait=False, timeout=30.0)``. ``app/db/pool.py`` awaits
    ``pool.open()`` with no arguments, so it takes this default deliberately: it starts the
    background fill and returns. A future edit adding ``wait=True`` to close the
    unreachable-PostgreSQL gap would turn a database thirty seconds late into a crash loop —
    Compose's ``restart:`` reacts to a process exiting, not to a failing healthcheck.
    """
    wait = inspect.signature(AsyncConnectionPool.open).parameters["wait"]
    assert wait.default is False

    # The DOCSTRING is stripped before the body is read: it quotes `wait=True` while explaining
    # why the code must not use it, and matching that would make this assertion fire on the
    # explanation rather than on the call. Sliced on the triple quotes rather than by
    # `.replace(open_pool.__doc__)` — CPython 3.13 DEDENTS `__doc__` at compile time, so it is
    # no longer a substring of the source and the replace silently does nothing.
    signature_and_docstring, _, body = inspect.getsource(open_pool).partition('"""')
    _, _, source = body.partition('"""')
    source = signature_and_docstring + source
    assert "await pool.open()" in source, "open_pool no longer takes open()'s defaults"
    assert "wait=True" not in source, (
        "open_pool now waits for the pool to fill; that makes a briefly-unreachable database a "
        "container that never starts. See this module's docstring and app/db/pool.py."
    )


async def test_open_pool_returns_against_a_host_no_packet_can_reach() -> None:
    """The behaviour, and the reason the old integration assertion was vacuous.

    ``pool_size`` counts slots the background workers are filling, **not** connections that
    exist, so it reaches ``min_size`` here — against an address that cannot answer.
    ``pool_available`` is the one that cannot be satisfied without a server, and it is ``0``.
    Any readiness signal derived from this object rather than from a round trip would report
    green on exactly this pool.
    """
    with warnings.catch_warnings():
        # The background workers log their connection failures; nothing here is a warning we
        # want `-W error` to see, and none is raised by the code under test.
        warnings.simplefilter("ignore")
        pool = await open_pool(UNROUTABLE_DSN, min_size=2, max_size=10)
        try:
            stats = pool.get_stats()
            assert stats["pool_size"] == 2, (
                "the stat the old test asserted no longer reads min_size against an "
                "unroutable host; re-check whether that assertion is still vacuous"
            )
            assert stats["pool_available"] == 0
        finally:
            await close_pool(pool)

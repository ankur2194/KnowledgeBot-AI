"""``open_pool`` / ``close_pool`` against a real PostgreSQL.

Nothing here can be asserted without a server. "The pool opened" is a claim about a socket, and
the two failure modes this module exists to prevent — a pool bound to the wrong event loop, and
a pool whose connections outlive the process that closed it — are both invisible to a double.

There is no ORM and no migration tool anywhere in this service: it writes a small set of
derived, rebuildable tables into a schema Laravel's migrations define, and CI fails the build on
the name of one appearing in this tree at all.
"""

from __future__ import annotations

import asyncio
import warnings

import pytest

from app.db.pool import close_pool, open_pool

pytestmark = pytest.mark.integration


async def test_the_pool_fills_in_the_background_and_serves_a_query(pg_dsn: str) -> None:
    """``await pool.open()`` returns BEFORE the pool has filled, and this test used to say the
    opposite.

    Its old name was ``..._opens_filled_...`` and its old assertion was
    ``pool.get_stats()["pool_size"] >= 2`` — which is vacuous for that claim. ``pool_size``
    counts slots the background workers are *filling*, not connections that exist, so it reads
    ``2`` against a host that is guaranteed unroutable
    (``tests/unit/test_db_pool_open_semantics.py`` measures exactly that). The stat that cannot
    be satisfied without a server is ``pool_available``, and reaching it needs an explicit
    ``pool.wait()`` — which is the whole point: filling is asynchronous, so ``lifespan`` yields
    against a database nothing can reach and ``/health/ready`` is what has to notice. See
    ``app/db/pool.py``'s docstring for where that guarantee actually lives.
    """
    pool = await open_pool(pg_dsn, min_size=2, max_size=4)
    try:
        # `wait()` is what "filled" requires. Without it this assertion races the pool's own
        # background workers and passes or fails on scheduling.
        await pool.wait(timeout=10.0)
        assert pool.get_stats()["pool_available"] >= 2

        async with pool.connection() as connection:
            cursor = await connection.execute("SELECT 1")
            assert await cursor.fetchone() == (1,)
    finally:
        await close_pool(pool)


async def test_opening_the_pool_explicitly_emits_no_warning_from_psycopg_pool(
    pg_dsn: str,
) -> None:
    """``open=False`` plus an awaited ``await pool.open()``, asserted by the noise it does NOT
    make.

    psycopg_pool 3.2 emits a **RuntimeWarning** — not a ``DeprecationWarning`` — for a pool
    opened in its constructor, so `-W error::DeprecationWarning` in the suite's ``addopts`` does
    not catch it and the regression is otherwise invisible: an implicitly opened pool works
    here, in this loop, and binds its internals to whichever loop was current, which is the
    "attached to a different loop" failure and the ``fork()`` failure in ``worker_process_init``.
    """
    with warnings.catch_warnings(record=True) as caught:
        warnings.simplefilter("always")
        pool = await open_pool(pg_dsn, min_size=1, max_size=2)
    try:
        assert [str(warning.message) for warning in caught] == []
    finally:
        await close_pool(pool)


async def test_concurrent_callers_share_the_pool_rather_than_opening_a_socket_each(
    pg_dsn: str,
) -> None:
    """The reason a pool exists at all. ``max_size=2`` with eight concurrent queries must
    serve all eight and never hold more than two connections — per-request construction means a
    fresh handshake on every call plus socket exhaustion under load."""
    pool = await open_pool(pg_dsn, min_size=1, max_size=2)
    try:

        async def query(n: int) -> int:
            async with pool.connection() as connection:
                cursor = await connection.execute("SELECT %s::int", (n,))
                row = await cursor.fetchone()
                assert row is not None
                return int(row[0])

        results = await asyncio.gather(*(query(n) for n in range(8)))

        assert results == list(range(8))
        assert pool.get_stats()["pool_size"] <= 2
    finally:
        await close_pool(pool)


async def test_closing_the_pool_actually_closes_its_connections(pg_dsn: str) -> None:
    """``close_pool`` is awaited rather than fired and forgotten: an un-awaited close leaves
    server-side backends alive until their TCP timeout, and a rolling restart then exhausts
    ``max_connections`` with sockets nobody is using."""
    pool = await open_pool(pg_dsn, min_size=1, max_size=2)
    await close_pool(pool)

    assert pool.closed
    with pytest.raises(Exception, match=r"(?i)closed"):
        async with pool.connection():
            pass


async def test_the_pool_is_bound_to_the_loop_that_opened_it_and_not_to_import_time(
    pg_dsn: str,
) -> None:
    """``open=False`` at construction plus an explicit ``await pool.open()``.

    Constructing an already-open pool from a synchronous context binds its internals to
    whichever loop happens to be current — which is the "attached to a different loop" error
    that lands on the *second* test in a file, and in production is a worker child inheriting
    the parent's sockets across ``fork()``. Asserting it directly is not possible from inside
    one loop, so this asserts the observable half: a pool opened here works here, and a second
    pool opened on a second loop works there, with neither disturbing the other.
    """
    first = await open_pool(pg_dsn, min_size=1, max_size=2)

    def on_another_loop() -> int:
        async def run() -> int:
            second = await open_pool(pg_dsn, min_size=1, max_size=2)
            try:
                async with second.connection() as connection:
                    cursor = await connection.execute("SELECT 42")
                    row = await cursor.fetchone()
                    assert row is not None
                    return int(row[0])
            finally:
                await close_pool(second)

        return asyncio.run(run())

    try:
        assert await asyncio.to_thread(on_another_loop) == 42

        async with first.connection() as connection:
            cursor = await connection.execute("SELECT 1")
            assert await cursor.fetchone() == (1,)
    finally:
        await close_pool(first)


@pytest.mark.parametrize(("min_size", "max_size"), [(0, 0), (5, 2), (-1, 4), (1, 0)])
async def test_an_impossible_pool_size_is_refused_before_a_socket_is_opened(
    pg_dsn: str, min_size: int, max_size: int
) -> None:
    """``postgres_pool_min``/``postgres_pool_max`` are two independent settings and nothing
    validates them against each other. A pool that cannot serve anybody should say so at
    startup, where the message names the numbers, rather than at the first query."""
    with pytest.raises(ValueError, match="pool sizes"):
        await open_pool(pg_dsn, min_size=min_size, max_size=max_size)


async def test_the_dsn_arrives_unwrapped_so_the_call_site_owns_not_logging_it() -> None:
    """``config.postgres_dsn()`` returns a ``SecretStr`` because the password is inside it. The
    unwrap is at the call site — greppable, and the one place that has to not log the result.
    Taking a ``SecretStr`` here would move the unwrap into this module and put the password into
    the ``repr()`` of the pool's own configuration.
    """
    import inspect

    from pydantic import SecretStr

    from app.core.config import postgres_dsn

    dsn = postgres_dsn(host="h", port=1, database="d", user="u", password_path=None)

    assert isinstance(dsn, SecretStr)
    assert inspect.signature(open_pool).parameters["dsn"].annotation == "str"

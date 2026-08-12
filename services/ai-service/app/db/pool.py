"""The psycopg connection pool.

Built in ``lifespan`` and handed out through ``request.state``; never constructed at import
time, and never per request.

Celery workers need their own pool per **child process**, not per worker: a prefork child
inherits the parent's file descriptors, so a pool opened before the fork hands two
processes the same sockets and produces protocol desynchronisation that reads as random
query corruption. Open it in a ``worker_process_init`` signal handler.

psycopg, deliberately, and there is no ORM and no migration tool anywhere under
``services/ai-service/app``: this service writes a small set of derived, rebuildable tables
into a schema Laravel's migrations define (`kb-architecture-map`). CI fails the build on the
name of an ORM appearing in this tree at all, including in prose.
"""

from __future__ import annotations

from typing import Any

from psycopg_pool import AsyncConnectionPool

__all__ = ["close_pool", "open_pool"]


async def open_pool(dsn: str, *, min_size: int, max_size: int) -> AsyncConnectionPool[Any]:
    """Open an ``AsyncConnectionPool``.

    ``open=False`` at construction and an explicit ``await pool.open()`` — constructing an
    open pool from a synchronous context binds it to whichever loop is current.

    ``dsn`` is an ALREADY-UNWRAPPED string. ``config.postgres_dsn()`` returns a ``SecretStr``
    because the password is inside it, so the ``.get_secret_value()`` call is at the call
    site, is greppable, and is the one place that has to not log the result. Taking a
    ``SecretStr`` here instead would push the unwrap into this module and put the password
    into the ``repr()`` of the pool's own configuration.

    ``await pool.open()`` DOES NOT WAIT FOR A CONNECTION, and this docstring claimed for one
    revision that it did — that "the pool has already filled to ``min_size`` when ``lifespan``
    yields, so ``/health/ready`` cannot report green against a database nothing can reach."
    Both halves were false. On the pinned psycopg_pool 3.3.1 the signature is
    ``open(wait: bool = False, timeout: float = 30.0)``: awaiting it starts the background
    workers that fill the pool and returns. Measured against 203.0.113.9 (TEST-NET-3,
    guaranteed unroutable): ``open_pool`` returns in **0.00 s** with
    ``{'pool_size': 2, 'pool_available': 0}`` — ``pool_size`` counts slots being filled, not
    live connections — and ``close_pool`` returns in 0.00 s too. The contrast is the call we
    deliberately do not make: ``open(wait=True, timeout=2.0)`` against the same host raises
    ``PoolTimeout: pool initialization incomplete after 2.0 sec``. So ``lifespan`` yields
    against an unreachable database, and the failure surfaces on the first
    ``pool.connection()`` as ``PoolTimeout: couldn't get a connection``.

    THAT BEHAVIOUR IS THE ONE WE WANT — a startup that survives a brief outage. Compose's
    ``restart:`` reacts to a process exiting, not to a failing healthcheck, so a pool that
    refused to open would turn a database thirty seconds late into a crash loop that needs a
    human. ``app/core/runtime.py`` states the same rule for every other client it builds, and
    ``wait=True`` here would break it for this one.

    THE READINESS GUARANTEE IS NOT IN THIS FILE, AND AS OF 2026-08-11 IT EXISTS. What stops
    ``/health/ready`` reporting green against an unreachable dependency is
    ``app/api/health.py`` — one real round trip per probe interval against the lifespan-owned
    object, and its docstring says in as many words that presence of the object is not
    readiness. **This pool is now one of the objects it probes**:
    ``health._postgres_reachable()`` checks a connection out and runs a statement on it, so an
    unreachable PostgreSQL renders 503 with ``"postgres": false`` in the body. For one revision
    of this docstring it was not probed and the sentence here said so — an unreachable database
    was then reported by nothing at all, which is the gap that was closed rather than a state to
    preserve.

    Do not re-add ``wait=True`` to move that check back into this function. The division is the
    point: this function must return against a database that is thirty seconds late, and
    ``ready()`` must answer 503 for as long as it stays late. ``wait=True`` collapses the two
    into a crash loop that needs a human, which is what the paragraph above measures.
    """
    if not 0 <= min_size <= max_size or max_size < 1:
        msg = (
            "pool sizes must satisfy 0 <= min_size <= max_size and max_size >= 1, got "
            f"{min_size=} {max_size=}"
        )
        raise ValueError(msg)
    # `open=False` is passed explicitly rather than relied on: psycopg_pool 3.2 emits a
    # RuntimeWarning for an implicitly-opened pool, and `-W error` in the test configuration
    # turns that into a failure — which is the intended outcome, because an implicitly opened
    # pool binds to whatever loop happened to be running at construction.
    pool: AsyncConnectionPool[Any] = AsyncConnectionPool(
        dsn, min_size=min_size, max_size=max_size, open=False
    )
    await pool.open()
    return pool


async def close_pool(pool: AsyncConnectionPool[Any]) -> None:
    """Close the pool and wait for its connections to go.

    Called from ``lifespan``'s ``finally`` and from ``worker_process_shutdown``. Shutdown
    runs while in-flight streams are still draining — `ai-api` has a 90 s
    ``stop_grace_period``, longer than the 60 s chat deadline — so this is not racing a live
    response, and it must be awaited rather than fired and forgotten: an un-awaited close
    leaves server-side backends alive until their TCP timeout, and a rolling restart then
    exhausts ``max_connections`` with sockets nobody is using.
    """
    await pool.close()

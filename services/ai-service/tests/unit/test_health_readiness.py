"""``/health/ready`` answers a question about REACHABILITY, not about object graphs.

Before the clients existed, readiness tested ``request.state.qdrant is not None`` with a TODO
saying "real round-trips once the clients are built in lifespan". Wiring the clients without
also closing that TODO would have been the worse of the two states: a container reporting 200
while Qdrant was unreachable, because every client this service builds connects LAZILY —
``AsyncQdrantClient`` and ``redis.asyncio`` both do, and ``AsyncConnectionPool.open()`` returns
before its first connection exists. Presence of the object proves nothing at all.

Nothing here talks to a real server. The clients are stubs whose one probe method either answers
or raises, which is precisely the distinction under test.

NO ``ASGITransport`` HERE, AND THAT IS NOT AN OVERSIGHT. ``tests/unit/test_harness_guards.py``
confines in-process transports to ``tests/contract/``, and the endpoint is a coroutine taking a
``Request``: calling it directly is both a purer unit and one fewer thing that can be a false
green. ``request.state`` is ``scope["state"]``, which is exactly the mapping ``lifespan``
yields — ``test_lifespan_wiring.py`` owns the half that proves the mapping arrives.
"""

from __future__ import annotations

import json
from contextlib import asynccontextmanager
from typing import Any

import pytest
from starlette.requests import Request

from app.api import health


class _Reachable:
    async def info(self) -> str:
        return "ok"

    async def ping(self) -> bool:
        return True


class _Refused:
    async def info(self) -> str:
        raise ConnectionRefusedError("qdrant is not listening")

    async def ping(self) -> bool:
        raise ConnectionRefusedError("valkey is not listening")


class _Connection:
    def __init__(self, *, on_execute: BaseException | None) -> None:
        self._on_execute = on_execute
        self.statements: list[str] = []

    async def execute(self, sql: str) -> Any:
        self.statements.append(sql)
        if self._on_execute is not None:
            raise self._on_execute
        return None


class _Pool:
    """A psycopg pool's probe surface, and only it.

    ``AsyncConnectionPool.connection(timeout=…)`` is an async context manager yielding a
    connection with ``execute``. That is the whole surface readiness touches, and it is the
    surface that has to be doubled: a pool exposes no ``ping()``, which is exactly why the probe
    could not reuse ``_reachable``.

    The two failure modes are separate arguments because they fail at different moments. A pool
    against a dead server fails the CHECKOUT — ``open()`` returned without connecting, so this is
    the first thing that can notice. A pool holding connections a restarted server has since
    dropped succeeds the checkout and fails the STATEMENT, which a probe that only took a
    connection out and gave it back would report as ready.

    ``tests/integration/test_db_pool.py`` owns the real ``AsyncConnectionPool``; nothing here
    talks to a server.
    """

    def __init__(
        self,
        *,
        on_checkout: BaseException | None = None,
        on_execute: BaseException | None = None,
    ) -> None:
        self._on_checkout = on_checkout
        self._on_execute = on_execute
        self.timeouts: list[float | None] = []
        self.connections: list[_Connection] = []

    # ASYNC109 wants a `timeout` parameter replaced by `asyncio.timeout` at the call site — which
    # is what `_postgres_reachable` does, on top of this one. The name cannot change here: this
    # is a double for `AsyncConnectionPool.connection(timeout=…)`, and a double free to rename
    # the real API's keyword would keep passing after the production call stopped matching it.
    @asynccontextmanager
    async def connection(self, timeout: float | None = None) -> Any:  # noqa: ASYNC109
        self.timeouts.append(timeout)
        if self._on_checkout is not None:
            raise self._on_checkout
        conn = _Connection(on_execute=self._on_execute)
        self.connections.append(conn)
        yield conn


@pytest.fixture(autouse=True)
def _no_cached_verdict() -> Any:
    """Readiness caches its answer for 5 s across the whole process.

    Without this reset the second test in the file reads the first one's verdict, and the file
    passes or fails on collection order.
    """
    health._ready_cache = None
    yield
    health._ready_cache = None


def _checks(response: Any) -> dict[str, bool]:
    body: dict[str, Any] = json.loads(response.body)
    return body["checks"]


_UNSET: Any = object()


async def _ready(qdrant: Any, cache: Any, db_pool: Any = _UNSET) -> Any:
    """Ask ``ready()`` with these objects present as ``request.state``.

    ``request.state`` is ``scope["state"]``, and ``app.state`` is a DIFFERENT object it does not
    fall back to — which is the whole reason ``app/api/deps.py`` reads ``request.app.state``
    while ``app/api/health.py`` reads ``request.state``. A real ASGI server copies the mapping
    the lifespan yielded into every request scope, so putting it in ``scope["state"]`` here is
    the same thing uvicorn does and not a shortcut around it.

    ``db_pool`` defaults to a reachable pool rather than to ``None``: the tests below that vary
    Qdrant and Valkey are about those two dependencies, and a default of ``None`` would make
    every one of them 503 for a reason they are not testing.
    """
    request = Request(
        {
            "type": "http",
            "method": "GET",
            "path": "/health/ready",
            "headers": [],
            "state": {
                "qdrant": qdrant,
                "cache": cache,
                "db_pool": _Pool() if db_pool is _UNSET else db_pool,
            },
        }
    )
    return await health.ready(request)


async def test_a_container_whose_dependencies_answer_is_ready() -> None:
    response = await _ready(_Reachable(), _Reachable(), _Pool())

    assert response.status_code == 200
    assert _checks(response) == {"qdrant": True, "cache": True, "postgres": True}


async def test_a_well_formed_client_against_an_unreachable_server_is_not_ready() -> None:
    """THE WHOLE POINT OF THE FILE.

    Every object exists, every one is the right type, and every one fails on contact. A presence
    check reports 200 here and routes traffic into a container that cannot serve a single
    request.
    """
    response = await _ready(
        _Refused(), _Refused(), _Pool(on_checkout=OSError("postgres is not listening"))
    )

    assert response.status_code == 503
    assert _checks(response) == {"qdrant": False, "cache": False, "postgres": False}


async def test_one_unreachable_dependency_is_enough() -> None:
    response = await _ready(_Reachable(), _Refused(), _Pool())

    assert response.status_code == 503
    assert _checks(response) == {"qdrant": True, "cache": False, "postgres": True}


async def test_a_client_that_was_never_built_is_not_ready() -> None:
    """`ai-worker-crawl` holds no Qdrant credential and gets no client; the API in a deployment
    that failed to mount one is the same shape. ``None`` is not ready, and must not raise."""
    response = await _ready(None, None, None)

    assert response.status_code == 503
    assert _checks(response) == {"qdrant": False, "cache": False, "postgres": False}


async def test_a_hanging_dependency_does_not_hang_the_probe() -> None:
    """Traefik and Compose treat a timed-out probe the same as a failed one, so a probe that
    hangs on an unreachable dependency spends the whole healthcheck budget and reports nothing.
    """
    import asyncio

    class _Hangs:
        async def info(self) -> str:
            await asyncio.sleep(30)
            return "never"

    original = health._PROBE_TIMEOUT_SECONDS
    health._PROBE_TIMEOUT_SECONDS = 0.05
    try:
        response = await _ready(_Hangs(), _Reachable())
    finally:
        health._PROBE_TIMEOUT_SECONDS = original

    assert response.status_code == 503
    assert _checks(response)["qdrant"] is False


# ─────────────────────────────────────────────────────────────────────────────
# PostgreSQL — finding #104. Until 2026-08-11 an unreachable one was reported by
# NOTHING: `ready()` probed Qdrant and Valkey only, and `app/db/pool.py` documents
# that `await pool.open()` returns before any connection exists, so the pool object
# on a container that cannot reach its database is well-formed and useless.
# ─────────────────────────────────────────────────────────────────────────────


async def test_an_unreachable_database_is_not_ready_and_the_body_says_postgres() -> None:
    """THE FINDING, EXECUTABLE — and the assertion is on the NAME, not only the status.

    A 503 that does not name the failing dependency sends whoever reads it to the wrong one.
    The measured precedent on this exact seam is a mutation that failed with
    ``{'qdrant': False} != {'qdrant': True}`` for a defect that had nothing to do with Qdrant,
    so the two reachable dependencies are asserted still-true here on purpose: this response
    has to be unmistakably about PostgreSQL.
    """
    pool = _Pool(on_checkout=OSError("connection to server at 203.0.113.9 failed"))

    response = await _ready(_Reachable(), _Reachable(), pool)

    assert response.status_code == 503
    assert _checks(response) == {"qdrant": True, "cache": True, "postgres": False}
    assert json.loads(response.body)["status"] == "not_ready"


async def test_a_pool_that_hands_out_a_dead_connection_is_not_ready() -> None:
    """CHECKING A CONNECTION OUT IS NOT A ROUND TRIP, and this is the case that proves it.

    The checkout succeeds — the pool has a connection object to give — and the server is gone
    anyway: a restart, a failover, a proxy reaping idle sessions. A probe that took a connection
    and handed it straight back would report 200 here, which is the presence check one layer
    down from the one this file was written to kill.
    """
    pool = _Pool(on_execute=OSError("server closed the connection unexpectedly"))

    response = await _ready(_Reachable(), _Reachable(), pool)

    assert response.status_code == 503
    assert _checks(response)["postgres"] is False
    assert pool.connections[0].statements == ["SELECT 1"], (
        "the probe checked a connection out without making the server answer on it"
    )


async def test_the_database_probe_is_bounded_by_the_same_budget_as_the_others() -> None:
    """A pool whose checkout never returns must not spend the whole healthcheck budget.

    `AsyncConnectionPool`'s own default timeout is 30 s — six times the healthcheck `timeout`
    and fifteen times this probe's budget — so relying on psycopg's default here would hand
    Traefik a timed-out probe instead of a 503 naming the dependency. Both bounds are asserted:
    the value handed to the checkout, and the wall-clock behaviour when the checkout ignores it.
    """
    import asyncio

    class _NeverConnects(_Pool):
        @asynccontextmanager
        async def connection(self, timeout: float | None = None) -> Any:  # noqa: ASYNC109
            self.timeouts.append(timeout)
            await asyncio.sleep(30)
            yield  # pragma: no cover - unreachable

    pool = _NeverConnects()
    original = health._PROBE_TIMEOUT_SECONDS
    health._PROBE_TIMEOUT_SECONDS = 0.05
    try:
        response = await _ready(_Reachable(), _Reachable(), pool)
    finally:
        health._PROBE_TIMEOUT_SECONDS = original

    assert response.status_code == 503
    assert _checks(response)["postgres"] is False
    assert pool.timeouts == [0.05], (
        "the probe did not pass its own budget to the checkout, so psycopg's 30 s default "
        f"governs it instead: {pool.timeouts}"
    )


async def test_a_reachable_database_is_probed_with_a_statement_not_a_presence_check() -> None:
    """The green direction, asserted on the mechanism rather than on the status code.

    A probe rewritten as `db_pool is not None` passes every other test in this section — they
    all fail for reasons a presence check also reports — and fails only this one.
    """
    pool = _Pool()

    response = await _ready(_Reachable(), _Reachable(), pool)

    assert response.status_code == 200
    assert [conn.statements for conn in pool.connections] == [["SELECT 1"]]

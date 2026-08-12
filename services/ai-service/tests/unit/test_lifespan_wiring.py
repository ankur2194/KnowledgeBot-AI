"""What ``create_app`` and ``lifespan`` must leave behind, and in what order.

``tests/contract/conftest.py:signed_app`` installs three names on ``app.state`` and calls itself
*"the shape the later wiring task has to produce"*. This file is the other half of that claim:
it asserts that ``lifespan`` produces exactly that shape, so the fixture and the application
cannot drift into agreeing with each other about a shape neither one implements.

No network and no containers. ``open_runtime_clients`` is replaced with a stub, because what is
under test is the WIRING — which object ends up under which name, on which of the two states —
and not whether psycopg can reach a database. ``tests/integration/test_db_pool.py`` owns that.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.core.config import Settings
from app.core.runtime import RuntimeClients
from app.main import create_app, lifespan


class _Sentinel:
    """Distinguishable by identity, so an assertion cannot pass on a coincidence."""

    def __init__(self, name: str) -> None:
        self.name = name

    def __repr__(self) -> str:
        return f"<{self.name}>"


@pytest.fixture
def clients() -> RuntimeClients:
    return RuntimeClients(
        settings=Settings(environment="ci"),
        key_ring=_Sentinel("key_ring"),  # type: ignore[arg-type]
        nonce_store=_Sentinel("nonce_store"),
        cache=_Sentinel("cache"),
        qdrant=_Sentinel("qdrant"),
        db_pool=_Sentinel("db_pool"),
    )


@pytest.fixture
def wired(monkeypatch: pytest.MonkeyPatch, clients: RuntimeClients) -> RuntimeClients:
    """``lifespan`` with its client construction replaced and its teardown recorded."""
    import app.main as main

    async def _open(settings: Settings) -> RuntimeClients:
        return clients

    async def _close(built: RuntimeClients) -> None:
        _CLOSED.append(built)

    monkeypatch.setattr(main, "open_runtime_clients", _open)
    monkeypatch.setattr(main, "close_runtime_clients", _close)
    _CLOSED.clear()
    return clients


_CLOSED: list[RuntimeClients] = []


# ─────────────────────────────────────────────────────────────────────────────
# The two states, which are not interchangeable
# ─────────────────────────────────────────────────────────────────────────────


async def test_lifespan_installs_the_three_transport_objects_on_app_state(
    wired: RuntimeClients,
) -> None:
    """``app/api/deps.py`` reads ``settings``, ``key_ring`` and ``nonce_store`` off
    ``request.app.state`` — never ``request.state`` — and its docstring says why: a
    lifespan-yielded mapping only reaches ``request.state`` when lifespan actually RAN, and
    ``httpx.ASGITransport`` does not run it.

    Every ``/internal/v1`` route answers 500 with ``origin=SELF`` if any one of these three is
    missing, so this is the wiring checklist, executable.
    """
    app = create_app(Settings(environment="ci"))

    async with lifespan(app):
        assert app.state.settings is wired.settings
        assert app.state.key_ring is wired.key_ring
        assert app.state.nonce_store is wired.nonce_store


async def test_lifespan_yields_the_clients_health_reads_off_request_state(
    wired: RuntimeClients,
) -> None:
    """``app/api/health.py`` reads ``request.state.qdrant`` / ``.cache`` / ``.db_pool`` /
    ``.settings``, which Starlette merges from the yielded mapping. Setting them on ``app.state``
    instead would leave readiness reporting 503 forever with every client correctly built.

    ``db_pool`` was in this assertion before readiness probed it (#104) — it was yielded, owned
    and unused, which is why 8A measured ``ready()`` never touching it while every wiring test
    here was green."""
    app = create_app(Settings(environment="ci"))

    async with lifespan(app) as state:
        assert state["qdrant"] is wired.qdrant
        assert state["cache"] is wired.cache
        assert state["db_pool"] is wired.db_pool
        assert state["settings"] is wired.settings


async def test_lifespan_closes_what_it_opened(wired: RuntimeClients) -> None:
    app = create_app(Settings(environment="ci"))

    async with lifespan(app):
        assert _CLOSED == []

    assert [wired] == _CLOSED, "the clients were not closed on shutdown"


async def test_a_programmatic_settings_object_governs_the_app_it_was_passed_to(
    monkeypatch: pytest.MonkeyPatch, clients: RuntimeClients
) -> None:
    """``create_app(settings)`` used to accept a ``Settings`` and ignore it, which made every
    ``create_app(settings)`` in the harness a no-op that read as wiring.

    It also has to work WITHOUT ``get_settings()``: that call runs ``check_environment`` against
    the ambient environment, which is exactly what stops ``tests/contract/`` from running a
    lifespan at all.
    """
    import app.main as main

    seen: list[Settings] = []

    async def _open(settings: Settings) -> RuntimeClients:
        seen.append(settings)
        return clients

    monkeypatch.setattr(main, "open_runtime_clients", _open)
    monkeypatch.setattr(main, "close_runtime_clients", lambda built: _noop())
    monkeypatch.setattr(
        main, "get_settings", _must_not_be_called("lifespan fell back to get_settings()")
    )

    mine = Settings(environment="ci", pg_database="a_database_only_this_test_names")
    app = create_app(mine)

    async with lifespan(app):
        pass

    assert seen == [mine]


async def _noop() -> None:
    return None


def _must_not_be_called(message: str) -> Any:
    def _fail() -> Any:
        raise AssertionError(message)

    return _fail


# ─────────────────────────────────────────────────────────────────────────────
# Where telemetry is configured from — the placement that has no error message
# ─────────────────────────────────────────────────────────────────────────────


def _paths(routes: Any) -> set[str]:
    """Every route path, walking the nested routers FastAPI 0.141 keeps unflattened.

    ``include_router`` no longer copies routes onto ``app.routes``; it appends an
    ``fastapi.routing._IncludedRouter`` that holds the original ``APIRouter`` and exposes its
    routes through ``effective_candidates``. Reading only the top level therefore reports two
    anonymous entries and would make this assertion pass whether or not the routers had been
    registered.
    """
    found: set[str] = set()
    for route in routes:
        path = getattr(route, "path", None)
        if isinstance(path, str):
            found.add(path)
        nested = getattr(route, "original_router", None)
        if nested is not None:
            found |= _paths(nested.routes)
    return found


def test_telemetry_is_configured_before_the_application_is_ever_called(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """MEASURED, not assumed, and the failure mode is silence.

    ``Starlette.__call__`` builds and CACHES ``self.middleware_stack`` on first use, and the
    lifespan scope is a ``__call__``. ``FastAPIInstrumentor.instrument_app`` on the pinned
    ``opentelemetry-instrumentation-fastapi==0.65b0`` does not call ``add_middleware`` — it
    replaces ``app.build_middleware_stack``, a method Starlette then never calls again. So
    instrumenting from the lifespan raises nothing, warns about nothing, and produces **zero**
    spans for every subsequent request.

    ``middleware_stack is None`` is therefore the property to assert: it is true exactly while
    the application has not yet been called, which is the only window in which instrumenting
    works.
    """
    import app.main as main

    observed: dict[str, Any] = {}

    def _spy(*, app: Any) -> None:
        observed["stack_is_unbuilt"] = app.middleware_stack is None
        observed["routes"] = _paths(app.routes)

    monkeypatch.setattr(main, "configure_telemetry", _spy)

    create_app(Settings(environment="ci"))

    assert observed, "create_app did not configure telemetry at all"
    assert observed["stack_is_unbuilt"], (
        "telemetry was configured after the application's middleware stack had been built; from "
        "there instrument_app is a silent no-op"
    )
    assert "/health/live" in observed["routes"], (
        "telemetry was configured before the routers were registered, so the instrumentation "
        "wraps a stack that does not contain them yet"
    )


async def _drive(app: Any, path: str) -> int:
    """One HTTP request over the raw ASGI interface, and one lifespan around it.

    Not ``httpx.ASGITransport`` — ``tests/unit/test_harness_guards.py`` confines that to
    ``tests/contract/`` — and not needed here either: what has to be exercised is
    ``Starlette.__call__``, which is the thing that builds and caches the middleware stack.
    """
    import asyncio

    startup: asyncio.Queue[dict[str, Any]] = asyncio.Queue()
    lifespan_sent: list[dict[str, Any]] = []
    started = asyncio.Event()

    async def lifespan_send(message: dict[str, Any]) -> None:
        lifespan_sent.append(message)
        started.set()

    await startup.put({"type": "lifespan.startup"})
    task = asyncio.create_task(app({"type": "lifespan", "state": {}}, startup.get, lifespan_send))
    await asyncio.wait_for(started.wait(), timeout=5)
    assert lifespan_sent[0]["type"] == "lifespan.startup.complete", lifespan_sent

    received: list[dict[str, Any]] = []

    async def receive() -> dict[str, Any]:
        return {"type": "http.request", "body": b"", "more_body": False}

    async def send(message: dict[str, Any]) -> None:
        received.append(message)

    await app(
        {
            "type": "http",
            "asgi": {"version": "3.0"},
            "http_version": "1.1",
            "method": "GET",
            "path": path,
            "raw_path": path.encode(),
            "query_string": b"",
            "headers": [],
            "client": ("127.0.0.1", 1234),
            "server": ("ai-api", 80),
            "scheme": "http",
            "root_path": "",
            "state": {},
        },
        receive,
        send,
    )

    await startup.put({"type": "lifespan.shutdown"})
    await asyncio.wait_for(task, timeout=5)
    return int(next(m["status"] for m in received if m["type"] == "http.response.start"))


async def test_instrumenting_from_a_lifespan_produces_no_spans_at_all() -> None:
    """THE MEASUREMENT BEHIND THE PLACEMENT, run rather than described.

    Two identical applications, one instrumented inside ``create_app`` and one from its own
    lifespan. Both serve 200. Only one produces a span, and nothing anywhere reports the
    difference — which is why the rule is written down in three places and asserted here.

    A private ``TracerProvider`` is passed explicitly: ``trace.set_tracer_provider`` is a
    one-shot process global, so installing one here would bind every proxy instrument in the
    session to it.
    """
    from fastapi import FastAPI
    from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor
    from opentelemetry.sdk.trace import TracerProvider
    from opentelemetry.sdk.trace.export import SimpleSpanProcessor
    from opentelemetry.sdk.trace.export.in_memory_span_exporter import InMemorySpanExporter

    async def _build(instrument_from_lifespan: bool) -> list[str]:
        exporter = InMemorySpanExporter()
        provider = TracerProvider()
        provider.add_span_processor(SimpleSpanProcessor(exporter))

        from contextlib import asynccontextmanager

        @asynccontextmanager
        async def _lifespan(built: Any) -> Any:
            if instrument_from_lifespan:
                FastAPIInstrumentor.instrument_app(built, tracer_provider=provider)
            yield

        app = FastAPI(lifespan=_lifespan)

        @app.get("/probe")
        async def probe() -> dict[str, bool]:
            return {"ok": True}

        if not instrument_from_lifespan:
            FastAPIInstrumentor.instrument_app(app, tracer_provider=provider)

        assert await _drive(app, "/probe") == 200
        provider.force_flush()
        return [span.name for span in exporter.get_finished_spans()]

    from_create_app = await _build(instrument_from_lifespan=False)
    from_lifespan = await _build(instrument_from_lifespan=True)

    assert from_create_app, "instrumenting before the first call produced no spans either"
    assert from_lifespan == [], (
        "instrumenting from the lifespan produced spans on this version, so the placement rule "
        f"in app/main.py and app/observability/otel.py needs rechecking: {from_lifespan}"
    )


def test_the_module_level_app_does_not_start_exporter_threads_at_import(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """``app = create_app()`` at module scope runs at IMPORT, including under pytest.

    Without the endpoint gate in ``otel.configure`` that import would start a
    ``BatchSpanProcessor`` thread and a metric reader aimed at a Collector that is not running.
    """
    from app.observability import otel

    monkeypatch.delenv(otel.ENDPOINT_ENV, raising=False)
    monkeypatch.setattr(otel, "_configured", False)

    create_app(Settings(environment="ci"))

    assert not otel.telemetry_is_configured()

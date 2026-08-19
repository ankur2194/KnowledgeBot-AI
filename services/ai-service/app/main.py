"""The ASGI application.

`create_app()` is a function, not a module-level `app = FastAPI()`, so tests build isolated
apps. `app = create_app()` at the bottom exists only for the server's import string
(`fastapi run app/main.py` / `uvicorn app.main:app`).
"""

from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any, TypedDict

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.api.health import router as health_router
from app.api.internal.v1.embedding import router as embedding_router
from app.core.config import Settings, get_settings
from app.core.errors import ErrorClass, KbError, Origin, Surface, status_for
from app.core.runtime import close_runtime_clients, open_runtime_clients
from app.observability.logging import configure_logging
from app.observability.otel import configure as configure_telemetry

logger = logging.getLogger(__name__)

__all__ = ["app", "create_app"]


class AppState(TypedDict):
    """What `lifespan` yields. Merges into `request.state`, so routes get typed per-request
    access with no `app.state` globals.

    `total=True`, and that is the fix rather than the default. This was `total=False` and
    declared `embedder` and `reranker` — both dead since ADR-030 moved embedding and reranking
    onto the provider adapter layer, and both still typed here months later. Under `total=False`
    every key is optional, so mypy had nothing to say about either the two names that no longer
    existed or the one (`db_pool`) that did: the annotation could not disagree with the literal
    below no matter what either said. `tests/unit/test_api_state_split.py` had to read the dict
    literal out of the AST *because* the declaration was not an oracle. Total, and matching
    `app/api/deps.py`'s `REQUEST_STATE_NAMES` exactly, it is one — a key added to
    `REQUEST_STATE_NAMES` and not here is now a type error at the `state: AppState = {...}`
    assignment rather than a `None` at the first request that wanted it.

    Every value stays `Any` deliberately: `app/core/runtime.py` is the only module in this tree
    that names the client libraries, so that a contract test can install a fifteen-line double
    (see `RuntimeClients`' own docstring).
    """

    settings: Settings
    qdrant: Any
    cache: Any
    db_pool: Any


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[AppState]:
    """Long-lived clients are built here and handed out through `request.state`.

    Never construct them inside a dependency: per-request construction means a fresh TLS
    handshake on every provider call, straight onto the 4 s first-token budget, plus socket
    exhaustion under load. Never construct them at import time either: that binds to
    whichever loop happens to be running and produces "attached to a different loop" the
    moment a test uses a second loop.

    This used to load a 2 GB embedder and a 1.5 GB reranker per worker process, which is
    what forced `ai-api` to run WEB_CONCURRENCY=1 and scale by container rather than by
    process. ADR-030 moved both to provider APIs, so that constraint no longer has a basis
    — the resident set is now clients and pools. Anyone re-tuning concurrency should know
    the original number was justified by models that are gone.

    THIS DOES TWO THINGS WITH ONE SET OF OBJECTS, AND BOTH ARE REQUIRED
    -------------------------------------------------------------------
    It YIELDS the mapping (Starlette merges it into `request.state`) **and** it assigns three
    names onto `app.state`. That is not redundancy:

    * `app/api/health.py` reads `request.state.qdrant`, `request.state.cache`,
      `request.state.db_pool` and `request.state.settings` — the yielded mapping. `db_pool` was
      yielded here long before readiness probed it; it is probed now (#104).
    * `app/api/deps.py` reads `request.app.state.settings`, `.key_ring` and `.nonce_store`,
      and its own docstring says why: a lifespan-yielded mapping only reaches `request.state`
      when lifespan actually RAN, and `httpx.ASGITransport` does not run it. Reading from the
      application is what lets `tests/contract/conftest.py` install a real key ring and a real
      coordination store without booting a lifespan that would call `get_settings()` on the
      harness's own environment.

    `tests/contract/conftest.py:signed_app` installs exactly those three names, under exactly
    these spellings, and calls itself "the shape the later wiring task has to produce". It is
    the acceptance criterion for this function, so keep the two in step.

    WEB_CONCURRENCY IS "1" TODAY, WHICH IS WHY THE FORK QUESTION IS NOT LIVE HERE.
    Uvicorn with more than one worker forks AFTER importing this module, and every object built
    below is fork-hostile in the same way the Celery children are — a psycopg pool crossing a
    fork hands two processes the same sockets. With one worker there is no fork and the
    placement is safe. Raising `WEB_CONCURRENCY` means re-checking this exact placement, not
    only the memory arithmetic in the paragraph above.
    """
    settings = getattr(app.state, "settings", None) or get_settings()

    # Deliberately NOT a provider warm-up call: a provider round trip at startup makes boot
    # depend on a third party, and readiness deliberately does not test provider reachability
    # (see app/api/health.py for why that would take every replica out of rotation at once).
    # Nothing below performs a round trip either — see `app/core/runtime.py` for why a
    # briefly-unreachable dependency must produce a container that starts and reports not-ready
    # rather than one that refuses to start.
    clients = await open_runtime_clients(settings)

    app.state.settings = clients.settings
    app.state.key_ring = clients.key_ring
    app.state.nonce_store = clients.nonce_store

    state: AppState = {
        "settings": clients.settings,
        "qdrant": clients.qdrant,
        "cache": clients.cache,
        "db_pool": clients.db_pool,
    }

    # No `extra={"environment": ...}` here any more, and its absence is the point: `env` is a
    # REQUIRED field on every line (`app/observability/logging.py`), resolved once per process
    # from `deployment.environment.name` so the log field and the `env` metric label cannot
    # disagree. Repeating it as a per-call extra would be a second spelling of one fact, and
    # `environment` is not in the field allow-list, so it would have been dropped anyway.
    logger.info("ai-service starting")
    try:
        yield state
    finally:
        # Shutdown runs while in-flight streams are still draining — `ai-api` has a 90 s
        # stop_grace_period, longer than the 60 s chat deadline, precisely so this is not
        # racing a live response.
        #
        # `otel.shutdown()` is deliberately NOT called here. It is optional for this process by
        # `app/observability/otel.py`'s own contract: a telemetry flush must never spend any of
        # that 90 s window, and the span for a request nobody reads is not worth a second of
        # it. The Celery children are the opposite case and do call it — they exit through
        # `os._exit`, where the SDK's atexit hook never fires.
        await close_runtime_clients(clients)
        logger.info("ai-service stopped")


#: The 5xx message, in ONE place. Byte-identical to bootstrap/app.php's `$status >= 500` arm
#: (ADR-029), so a consumer cannot tell which plane produced the envelope. It was written inline
#: at its single use; it is a constant now because `actionable` below is the field that replaces
#: reading it, and a string two places can spell differently is what that field exists to retire.
#: "an internal dependency failed" would be a false statement: nothing downstream failed, we did.
SERVICE_FAILURE_MESSAGE = "The service could not complete this request."


def _envelope(exc: KbError, request: Request) -> JSONResponse:
    """The one error body, for every failure path.

    Laravel relays `error_class` verbatim and never re-derives it from the status, so this
    is the only place a class becomes a number.
    """
    surface = getattr(request.state, "surface", Surface.ADMIN)
    headers = {"Retry-After": str(exc.retry_after)} if exc.retry_after is not None else None
    return JSONResponse(
        # `exc.origin` is passed, not defaulted: it is what splits a downstream brownout
        # (503, retry) from our own defect (500, do not retry) — see ADR-029.
        status_code=status_for(exc.error_class, surface, exc.origin),
        content={
            "error_class": str(exc.error_class),
            "message": exc.message,
            "retryable": exc.retryable,
            "request_id": getattr(request.state, "request_id", None),
            # Read off the error, never recomputed here: the raiser knows whether it wrote a
            # sentence or a placeholder, and this function cannot tell them apart without
            # comparing strings. Laravel derives the same field from the arms of its own
            # message `match` and relays ours verbatim rather than re-deriving it (ADR-052).
            "actionable": exc.actionable,
        },
        headers=headers,
    )


async def _handle_kb_error(request: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, KbError)
    return _envelope(exc, request)


async def _handle_validation_error(request: Request, exc: Exception) -> JSONResponse:
    """FastAPI would otherwise render `{"detail": [...]}`, which is not our envelope and
    which Laravel cannot relay.

    Only `type`, `loc` and `msg` are echoed. Pydantic's `input` field carries the rejected
    value, which for an internal request is tenant content.
    """
    assert isinstance(exc, RequestValidationError)
    # A MAP of field path -> messages, never a list. Laravel produces exactly this shape from
    # $e->errors(), packages/contracts types it as Record<string, string[]>, and
    # applyServerErrors keys on it. A list still satisfies `typeof value === 'object'`, so the
    # envelope type-guard passes, the form then keys on "0" and "1", no field matches, and
    # every message collapses into one opaque root error.
    detail: dict[str, list[str]] = {}
    for e in exc.errors():
        # Drop the "body"/"query" prefix: it names the request part, not a field the caller
        # can act on. Remaining segments join dotted, matching Laravel's nested-field spelling.
        loc = [str(p) for p in e.get("loc", ()) if p not in ("body", "query", "path", "header")]
        detail.setdefault(".".join(loc) or "_", []).append(str(e.get("msg", "invalid")))
    logger.warning("validation rejected", extra={"errors": detail})
    surface = getattr(request.state, "surface", Surface.ADMIN)
    return JSONResponse(
        status_code=status_for(ErrorClass.VALIDATION, surface),
        content={
            "error_class": str(ErrorClass.VALIDATION),
            "message": "request failed validation",
            "retryable": False,
            "request_id": getattr(request.state, "request_id", None),
            # FALSE. The summary is a fixed string; the thing a caller acts on is the `errors`
            # map below it. Laravel's ValidationException arm answers the same, and for the
            # same reason — while a KbError(VALIDATION, ...) raised deliberately keeps its own
            # True, because those messages are written for the operator (the ADR-031 resolver
            # refusal is a paragraph the console renders verbatim).
            "actionable": False,
            # The `errors` map is a superset present ONLY on this class — the envelope's
            # four fields are identical everywhere else.
            "errors": detail,
        },
    )


async def _handle_unexpected(request: Request, exc: Exception) -> JSONResponse:
    """Unknown errors classify as permanent, never temporary — a permanent error misfiled
    as temporary retries to the attempt cap, lands in failed_jobs, and gets retried again
    by an operator.

    `origin=SELF` is the whole point of this handler (ADR-029, finding O1). Reaching here
    means an exception escaped every mapped path, which is a DEFECT IN OUR CODE, not a
    dependency being briefly unavailable. It renders 500 / retryable=false, byte-identical
    to what Laravel renders for the same situation. Dropping the argument silently restores
    the split this closed: 503 here, 500 there, and a client that retries one plane's bugs
    on a full backoff ladder while reporting the other's immediately.

    `str(exc)` never reaches the body: provider error payloads routinely echo the request,
    including credentials.
    """
    logger.exception("unhandled exception", exc_info=exc)
    return _envelope(
        KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            SERVICE_FAILURE_MESSAGE,
            origin=Origin.SELF,
            # THE PLACEHOLDER, BY DEFINITION — this is the one handler whose message is chosen
            # to say nothing. Marking it lets a client distinguish a deliberate 4xx from a
            # defect without comparing the message against a copy of the constant above, which
            # is what apps/web had to do before (finding J2).
            actionable=False,
        ),
        request,
    )


def create_app(settings: Settings | None = None) -> FastAPI:
    # FIRST, and here rather than in `lifespan`. Uvicorn configures its own logging inside
    # `Config.__init__` and imports this module afterwards, in `Config.load()` — so a call at
    # application-construction time lands after uvicorn's dictConfig (which would otherwise
    # overwrite ours) and before uvicorn logs "Started server process" (which would otherwise
    # bypass our formatter). `lifespan` is later still: everything uvicorn emits during startup
    # would be unformatted, and any exception raised while building the app would land in
    # `logging.lastResort` as bare stderr text.
    configure_logging()

    app = FastAPI(
        title="KnowledgeBot AI Service",
        version="0.1.0",
        lifespan=lifespan,
        # No public docs: this service has no public route and no browser ever reaches it.
        # A schema endpoint on an internal service is free reconnaissance.
        docs_url=None,
        redoc_url=None,
        openapi_url="/internal/v1/openapi.json",
        exception_handlers={
            KbError: _handle_kb_error,
            RequestValidationError: _handle_validation_error,
            Exception: _handle_unexpected,
        },
    )

    if settings is not None:
        # The parameter was declared and then ignored, which made `create_app(settings)` in
        # `tests/conftest.py` and in `tests/contract/conftest.py` a no-op that read as wiring.
        # Recording it here — and letting `lifespan` prefer it over `get_settings()` — is what
        # makes a programmatically-built Settings actually govern the app it was passed to.
        # `get_settings()` is still NOT called at construction: it runs `check_environment`,
        # and `app = create_app()` below is executed at IMPORT, including under pytest.
        app.state.settings = settings

    app.include_router(health_router)
    app.include_router(embedding_router)

    # TODO(owning agents): one router per contract group under app/api/internal/v1/ —
    # chat, providers, retrieval, ingestion, crawl, deletion, evaluation. Each is an
    # APIRouter(prefix="/internal/v1", dependencies=[Depends(verify_hmac)]): signature
    # verification is a ROUTER-level dependency so a new endpoint cannot forget it.

    # LAST, AND NOT FROM `lifespan`. `FastAPIInstrumentor.instrument_app` replaces
    # `app.build_middleware_stack`, and Starlette calls that method exactly once, lazily, on the
    # application's first `__call__` — which the lifespan scope already is. Instrumenting from
    # the lifespan therefore installs a builder that is never called again: no exception, no
    # warning, and every request served untraced for the life of the deployment. Measured both
    # ways on the pinned versions; the reasoning and the numbers are in
    # `app/observability/otel.py`'s module docstring.
    #
    # Last rather than first so that every router this function adds is inside the stack the
    # instrumentation wraps, and so a failure while building the app cannot leave a half-
    # instrumented application behind.
    #
    # A no-op unless OTEL_EXPORTER_OTLP_ENDPOINT is set, which is what keeps exporter threads
    # out of `app = create_app()` at import time under pytest.
    configure_telemetry(app=app)

    return app


app = create_app()

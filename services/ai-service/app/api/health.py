"""Health probes.

Three endpoints, three different questions, and conflating any two of them breaks a
different thing:

* ``/health/live``  — is the event loop responsive? Makes **zero** dependency calls. A
  liveness probe that touches a dependency restarts a healthy container during a Qdrant
  blip, turning a degraded read path into a rolling outage.
* ``/health/ready`` — may this container receive traffic? Checks Qdrant, Valkey and
  PostgreSQL. Reporting ready early routes traffic into a worker that cannot serve it.
* ``/health/deps``  — diagnostic detail, admin-only. Never public: it names internal
  hostnames and versions.

Deliberately **excluded** from readiness: object storage (only ingestion needs it), and
anything reached over a provider API — embeddings, reranking, chat.

That last exclusion is the load-bearing one, and it got stronger rather than weaker when
embedding moved off-process. **Readiness must never probe an external provider.** Traefik
removes an unhealthy container from rotation, and Compose's ``restart:`` reacts to a process
exiting, not to a failing healthcheck — so a readiness probe that trips on a transient
provider blip pulls *every* replica out of the edge simultaneously, with nothing in the
system able to put them back. Provider reachability is per-bot and per-credential anyway,
never per-process: one tenant's revoked key must not take a container out of rotation.

**PostgreSQL is not that case, and it was missing from this endpoint until 2026-08-11** — an
unreachable one was reported by nothing at all. The rule above turns on who the failure
belongs to, not on how many replicas go down: a provider outage is one tenant's credential
and the container can still serve everyone else, while an unreachable source of truth means
*no* request on *any* replica can be served, and pulling them all out of the edge is the
correct answer rather than the feared one. ``app/db/pool.py`` states the other half — that
``await pool.open()`` returns before any connection exists, that this is deliberate so a
database thirty seconds late is not a crash loop, and that the gap therefore belongs here.
It is closed on the same terms as the other two checks: one real round trip per probe
interval, never a presence check.

There was a third check here, ``embedding_model``, asserting a locally-loaded embedder was
non-``None``. It is gone with the model (ADR-030): embedding is an API call now, and by the
rule above an API call is exactly what readiness does not test.
"""

from __future__ import annotations

import asyncio
import time
from typing import Any, Literal

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse

# THIS MODULE READS `request.state`; `app/api/deps.py` reads `request.app.state`. Both are
# correct and `lifespan` populates both — see that module's APP_STATE_NAMES /
# REQUEST_STATE_NAMES for the split and why reading the wrong one has to raise rather than
# quietly return None, which is what these three lookups did before.
from app.api.deps import from_request_state

__all__ = ["router"]

router = APIRouter(tags=["health"])

#: Readiness is cached because a kubelet-style probe every 15 s across N containers is a
#: steady background load on Qdrant and Valkey that shows up in latency percentiles.
_READY_TTL_SECONDS = 5.0
_ready_cache: tuple[float, bool, dict[str, Any]] | None = None

#: A readiness probe is answered or it is not. Traefik and Compose both act on a TIMEOUT the
#: same way they act on a 503, so a probe that hangs on an unreachable dependency costs the
#: whole healthcheck budget and reports nothing; this bounds it well inside that budget and
#: renders the same 503 with the failing dependency named.
_PROBE_TIMEOUT_SECONDS = 2.0


async def _reachable(client: Any, method: str) -> bool:
    """One cheap round trip against a lifespan-owned client.

    PRESENCE OF THE OBJECT IS NOT READINESS, and this function is the difference. Every client
    `lifespan` builds connects lazily — `AsyncQdrantClient` and `redis.asyncio` both do, and
    `AsyncConnectionPool.open()` returns before its first connection exists — so a container
    whose Qdrant is unreachable holds a perfectly well-formed client object. Answering "ready"
    from `is not None` would route traffic into exactly that container and report green while
    every request failed.

    Broad `except` on purpose. A readiness probe converts *any* failure into "not ready": a
    connection refusal, a DNS failure, an auth rejection and a client library raising something
    undocumented are the same answer to the one question this endpoint asks. Letting one of them
    escape would render a 500 through the error envelope instead of the 503 that takes this
    container out of rotation.
    """
    if client is None:
        return False
    try:
        await asyncio.wait_for(getattr(client, method)(), _PROBE_TIMEOUT_SECONDS)
    except Exception:
        return False
    return True


async def _postgres_reachable(pool: Any) -> bool:
    """The same question ``_reachable`` asks, against an object with no one-call probe method.

    A pool is not a client: there is no ``info()`` or ``ping()`` to name, so this cannot go
    through ``_reachable``'s ``getattr(client, method)`` shape and gets its own function rather
    than a special case inside that one.

    BOTH STEPS ARE LOAD-BEARING AND THEY FAIL FOR DIFFERENT REASONS. The CHECKOUT is what fails
    when the server is gone: ``app/db/pool.py`` measured ``open_pool`` returning in 0.00 s
    against TEST-NET-3 with ``pool_size=2`` and ``pool_available=0`` — ``pool_size`` counts
    slots being filled, not live connections — so a pool against an unreachable host is
    indistinguishable from a healthy one until something asks it for a connection. The
    STATEMENT is what fails when the checkout succeeds and the session is dead anyway: a server
    restart, a failover, an idle connection reaped by a proxy. Checking a connection out and
    handing it straight back would report ready in that second case, which is the presence
    check this endpoint exists to refuse, one layer down.

    TWO BOUNDS, NOT ONE. ``timeout=`` on the checkout is psycopg's own, so an exhausted or
    unreachable pool raises ``PoolTimeout`` — a named error inside the budget — instead of being
    cancelled from outside at the same instant; without it psycopg's 30 s default governs, which
    is six times the healthcheck ``timeout``. The surrounding ``asyncio.timeout`` is what also
    covers the statement, and what holds if a future pool implementation ignores the keyword.

    ``None`` is not ready, and must not raise. ``app/core/runtime.py`` builds no pool in a
    container whose password file is not mounted — ``ai-worker-crawl`` is deliberately that
    container — and an API deployment that failed to mount one is the same shape. Broad
    ``except`` for the reason ``_reachable`` gives: every way this can fail is one answer to
    the one question the endpoint asks.
    """
    if pool is None:
        return False
    budget = _PROBE_TIMEOUT_SECONDS
    try:
        async with asyncio.timeout(budget), pool.connection(timeout=budget) as conn:
            await conn.execute("SELECT 1")
    except Exception:
        return False
    return True


@router.get("/health/live", include_in_schema=False)
async def live() -> dict[str, Literal["ok"]]:
    """Zero dependency calls, by contract. If this coroutine is scheduled at all, the
    answer is yes."""
    return {"status": "ok"}


@router.get("/health/ready", include_in_schema=False)
async def ready(request: Request) -> JSONResponse:
    global _ready_cache

    now = time.monotonic()
    if _ready_cache is not None and now - _ready_cache[0] < _READY_TTL_SECONDS:
        # Distinct names from the freshly-computed pair below. Reusing `ok`/`detail` here
        # rebinds them before their annotated definition, which `mypy --strict` reports as a
        # redefinition — and which reads, to a human, as though the cached values flowed into
        # the live computation.
        _, cached_ok, cached_detail = _ready_cache
        return JSONResponse(status_code=200 if cached_ok else 503, content=cached_detail)

    # Real round trips, now that `lifespan` builds the clients — `Qdrant.info()` is a bare
    # `GET /` and `PING` is one RESP command, and `SELECT 1` on a pooled connection is one
    # round trip on a socket that already exists, so the set costs less than the response
    # serialization and is cached for `_READY_TTL_SECONDS` anyway. The three are probed
    # CONCURRENTLY: run in sequence, a dependency sitting at the timeout would multiply the
    # worst-case answer and push it past a 5 s healthcheck `timeout`. That is why adding the
    # third check did not need the budget re-sized.
    #
    # Note the start_period that used to cover the startup window was sized for a multi-GB
    # model load; with no model to load, sizing it that way now only delays the moment a
    # genuinely broken container is noticed.
    qdrant_ok, cache_ok, postgres_ok = await asyncio.gather(
        _reachable(from_request_state(request, "qdrant"), "info"),
        _reachable(from_request_state(request, "cache"), "ping"),
        _postgres_reachable(from_request_state(request, "db_pool")),
    )
    # Each dependency gets its OWN key, and the failing one is named in the body a 503 carries.
    # A merged or elided key is how a red probe sends a debugger to the wrong dependency: the
    # measured precedent is a mutation on this seam that failed with
    # `{'qdrant': False} != {'qdrant': True}` while the defect was somewhere else entirely.
    checks: dict[str, bool] = {"qdrant": qdrant_ok, "cache": cache_ok, "postgres": postgres_ok}
    ok = all(checks.values())
    detail: dict[str, Any] = {"status": "ready" if ok else "not_ready", "checks": checks}

    _ready_cache = (now, ok, detail)
    return JSONResponse(status_code=200 if ok else 503, content=detail)


@router.get("/health/deps", include_in_schema=False)
async def deps(request: Request) -> dict[str, Any]:
    """Admin-only diagnostic detail.

    TODO(control-plane-engineer): gate this behind the same HMAC dependency the internal
    routers use. Until it is gated it must stay off any routable path — `ai-api` has no
    Traefik router and no published port, which is the only thing currently protecting it.
    """
    settings = from_request_state(request, "settings")
    from app.retrieval.collection import COLLECTION

    return {
        "environment": getattr(settings, "environment", "unknown"),
        # Never render a SecretStr, a DSN, or a credential here.
        "qdrant_collection": COLLECTION,
    }

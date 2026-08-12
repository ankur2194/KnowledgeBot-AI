"""The long-lived clients every process shape holds, built in exactly one place.

``app/main.py``'s ``lifespan`` and ``app/worker/process.py``'s ``worker_process_init`` handler
both need the same set of objects, built the same way, from the same settings. Two spellings of
that is how the API ends up verifying signatures against a ring the workers do not hold, or how
one side connects to the coordination database and the other to the cache — neither of which
raises anything. So both call :func:`open_runtime_clients` and neither constructs a client of
its own.

WHAT IS HERE, AND WHY EACH ONE
------------------------------
=================  ===========================================================================
``key_ring``       ``KeyRing`` from ``load_key_ring()``. Built ONCE, at startup, from a
                   directory listing, because ``key_id`` arrives from the wire — see
                   ``app/core/keys.py`` for the forgery this ordering prevents.
``nonce_store``    The coordination Valkey client. ``app/api/deps.py``'s replay check and
                   ``app/core/idempotency.py``'s claim record are its only callers, and
                   between them they issue ``SET … NX EX``, ``TTL`` and ``DELIFEQ``.
``cache``          The cache Valkey client. A DIFFERENT SERVER, not a different logical
                   database: ``maxmemory-policy`` is server-wide, so one instance cannot give
                   the coordination families ``noeviction`` while the answer cache runs
                   ``allkeys-lru``. Mixing them up silently makes a replay nonce evictable.
``qdrant``         ``AsyncQdrantClient``. Constructed, never probed — see below.
``db_pool``        The psycopg pool from ``app/db/pool.py``.
=================  ===========================================================================

NOTHING HERE PERFORMS A ROUND TRIP AT STARTUP, AND THAT IS DELIBERATE
--------------------------------------------------------------------
Every constructor below is cheap and local. ``AsyncQdrantClient`` and ``redis.asyncio`` both
connect lazily, and ``AsyncConnectionPool.open()`` returns without waiting for its first
connection. So a dependency that is briefly unreachable produces a container that starts and
reports **not ready**, rather than a container that refuses to start — which is the difference
between a rolling restart that recovers on its own and one that needs a human. ``/health/ready``
is where reachability is decided, on a probe interval, against these same objects.

The one thing that DOES fail startup is missing key material, and that asymmetry is the point:
a key ring is a deployment fact, not a transient one, and no number of retries loads a file that
was never mounted.

A CONTAINER THAT HOLDS NO CREDENTIAL FOR A DEPENDENCY GETS NO CLIENT FOR IT
---------------------------------------------------------------------------
``env/ai-service.env`` is ONE file shared by all six containers, so ``KB_PG_PASSWORD_PATH`` and
``KB_QDRANT_API_KEY_PATH`` are set in every one of them. What differs is the ``secrets:`` list,
and ``ai-worker-crawl`` deliberately mounts neither: it is the SSRF pivot, it is kept off the
``data`` network on purpose, and ``compose.yaml`` says so in as many words.

``config.postgres_dsn()`` and ``config.qdrant_api_key()`` RAISE on a configured-but-absent
secret file, so building these unconditionally would crash-loop ``ai-worker-crawl`` on a
credential it is designed not to have — the exact outage ``app/core/config.py``'s docstring
records for the previous shape of this code. The capability signal is therefore the MOUNT, which
is what Compose actually varies, and a configured path whose file is absent is logged at WARNING
and skipped rather than raised. A task that then needs the missing client fails loudly at the
point of use, naming the client, instead of taking the container down at boot.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any

from app.core.config import authenticated_valkey_url, postgres_dsn, qdrant_api_key
from app.core.keys import load_key_ring
from app.db.pool import close_pool, open_pool

if TYPE_CHECKING:  # pragma: no cover - typing only
    from pathlib import Path

    from app.core.config import Settings
    from app.core.keys import KeyRing

__all__ = ["RuntimeClients", "close_runtime_clients", "open_runtime_clients"]

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class RuntimeClients:
    """One process's long-lived clients.

    Frozen: these are handed to a lifespan mapping, to ``app.state`` and to a Celery module
    global, and a mutable holder passed to three places is three places that can swap a client
    out from under the other two.

    ``nonce_store``, ``cache`` and ``qdrant`` are ``Any`` on purpose and this module is the only
    one that names their libraries. ``app/api/deps.py`` and ``app/core/idempotency.py`` both
    document that they import no client library — they use three commands through the generic
    surface — which is what lets a contract test run the whole signature path in-process against
    ``tests/support/coordination.py``'s fifteen-line double.
    """

    settings: Settings
    key_ring: KeyRing
    nonce_store: Any
    cache: Any | None
    qdrant: Any | None
    db_pool: Any | None

    def __repr__(self) -> str:
        """Redacted by construction, the same way ``KeyRing.__repr__`` is.

        A dataclass' generated repr renders every field, and three of these fields were built
        from a credential that is still inside them: the two Valkey clients hold the ACL
        password in the connection holder they parsed their URL into, whose own ``__repr__``
        joins every keyword pair; the connection holder behind ``db_pool`` holds the libpq URI.
        So the default repr puts two passwords into any log line, traceback frame, debugger
        view or ``assert`` message that touches this object — and this object is held by
        ``app.state`` in ``ai-api`` and by a module global in every Celery child.

        What an operator actually needs from this object is which clients the process was
        provisioned for, which is exactly what ``None`` versus not-``None`` says. Types, never
        values, and never ``Settings`` — it holds no credential today, and naming it here would
        make that a property the next field addition has to preserve silently.
        """
        built = ", ".join(
            f"{name}={type(value).__name__ if value is not None else None}"
            for name, value in (
                ("key_ring", self.key_ring),
                ("nonce_store", self.nonce_store),
                ("cache", self.cache),
                ("qdrant", self.qdrant),
                ("db_pool", self.db_pool),
            )
        )
        return f"RuntimeClients({built})"


def _mounted(path: Path | None) -> bool:
    """Whether a configured credential path is actually present.

    ``None`` counts as present: it is the local-development shape both ``postgres_dsn`` and
    ``qdrant_api_key`` support explicitly — a Postgres with ``trust`` auth, a Qdrant started
    without ``--api-key``. What this rejects is the other case, a path that IS configured and
    whose file is NOT there, which is how a shared env file describes a container that was never
    granted that secret.
    """
    return path is None or path.is_file()


async def open_runtime_clients(settings: Settings) -> RuntimeClients:
    """Build every long-lived client this process is provisioned for."""
    key_ring = load_key_ring(
        settings.hmac_key_dir,
        active_request_key_id=settings.hmac_active_key_id,
        active_callback_key_id=settings.callback_active_key_id,
    )

    nonce_store = _valkey(settings, settings.coordination_url)
    cache = _valkey(settings, settings.cache_url)

    qdrant: Any | None = None
    if _mounted(settings.qdrant_api_key_path):
        from qdrant_client import AsyncQdrantClient

        api_key = qdrant_api_key(settings.qdrant_api_key_path)
        qdrant = AsyncQdrantClient(
            url=settings.qdrant_url,
            # `.get_secret_value()` at the call site, greppable, and this is the one line that
            # must not log its result — the same placement rule `app/db/pool.py` states for the
            # database password.
            api_key=None if api_key is None else api_key.get_secret_value(),
            timeout=int(settings.retrieval_timeout),
        )
    else:
        logger.warning(
            "no Qdrant client: the configured API-key file is not mounted in this container",
            extra={"dependency": "qdrant"},
        )

    db_pool: Any | None = None
    if _mounted(settings.pg_password_path):
        dsn = postgres_dsn(
            host=settings.pg_host,
            port=settings.pg_port,
            database=settings.pg_database,
            user=settings.pg_user,
            password_path=settings.pg_password_path,
        )
        db_pool = await open_pool(
            dsn.get_secret_value(),
            min_size=settings.postgres_pool_min,
            max_size=settings.postgres_pool_max,
        )
    else:
        logger.warning(
            "no database pool: the configured password file is not mounted in this container",
            extra={"dependency": "postgres"},
        )

    return RuntimeClients(
        settings=settings,
        key_ring=key_ring,
        nonce_store=nonce_store,
        cache=cache,
        qdrant=qdrant,
        db_pool=db_pool,
    )


def _valkey(settings: Settings, url: str) -> Any:
    """One Valkey client, with the ACL credential joined on at connect time.

    The credential is never part of the URL setting — ``users.acl`` sets ``user default off``,
    so an unauthenticated connection gets ``NOAUTH`` on its first command, which surfaces as a
    process that starts, reports healthy and then fails every coordination write.

    ``decode_responses`` is left at its default ``False``. ``tests/support/coordination.py``
    returns ``bytes`` from ``get`` and its contract callables compare against ``bytes``, so
    flipping it here would make the in-process double and the real server disagree about the one
    thing that double exists to pin.
    """
    from redis.asyncio import Redis

    return Redis.from_url(
        # `.get_secret_value()` at the call site, greppable, and this is the one line that must
        # not log its result — the same placement rule the Qdrant key follows above. The client
        # this builds holds the parsed URL in a connection holder whose generated `__repr__`
        # joins every keyword it was given, `password` among them.
        authenticated_valkey_url(
            url,
            username=settings.valkey_username,
            password_path=settings.valkey_password_path,
        ).get_secret_value()
    )


async def close_runtime_clients(clients: RuntimeClients) -> None:
    """Close everything :func:`open_runtime_clients` opened, in reverse order of cost.

    Every close is guarded individually. A teardown that raises halfway leaves the remaining
    clients open — and on the API side this runs inside ``lifespan``'s ``finally``, where an
    exception would be raised out of the ASGI shutdown and turn a clean stop into a hang that
    ends at the stop grace period.
    """
    if clients.db_pool is not None:
        await _closing("postgres", close_pool(clients.db_pool))
    for name, client in (("cache", clients.cache), ("coordination", clients.nonce_store)):
        if client is not None:
            await _closing(name, client.aclose())
    if clients.qdrant is not None:
        await _closing("qdrant", clients.qdrant.close())


async def _closing(name: str, awaitable: Any) -> None:
    try:
        await awaitable
    except Exception:
        logger.warning("client did not close cleanly", exc_info=True, extra={"dependency": name})

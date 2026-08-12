"""Which clients a process builds, and which it deliberately does not.

The failure this file exists to prevent already happened once in a different shape and is
recorded in ``app/core/config.py``'s docstring: six containers crash-looped because settings
that the deployment supplied were silently discarded. The version of it available here is the
mirror image — a container built a client for a credential it was designed not to have, and died
on the read.

``env/ai-service.env`` is ONE file shared by all six ai-service containers, so
``KB_PG_PASSWORD_PATH`` and ``KB_QDRANT_API_KEY_PATH`` are set in every one of them.
``compose.yaml`` varies the ``secrets:`` list instead: ``ai-worker-crawl`` mounts neither,
because it is the SSRF pivot and is kept off the ``data`` network on purpose. So the MOUNT is
the capability signal, and ``postgres_dsn()`` / ``qdrant_api_key()`` both RAISE on a
configured-but-absent file.

Nothing here opens a socket. The Valkey clients are constructed (``redis.asyncio`` connects
lazily) and closed; the pool and the Qdrant client are only ever asserted absent, because the
present case needs a real server and lives in ``tests/integration/``.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from app.core.config import Settings
from app.core.keys import KeyRingError
from app.core.runtime import close_runtime_clients, open_runtime_clients

#: 44 characters, matching what `bootstrap.sh` writes with `openssl rand -base64 32`, and past
#: `app/core/keys.py`'s 32-byte floor — a shorter one is refused at load, and a fixture that
#: could not have been loaded from disk is a fixture that proves nothing.
_KEY = b"unit-tier-hmac-secret-material-0123456789ab"


@pytest.fixture
def secrets_dir(tmp_path: Path) -> Path:
    directory = tmp_path / "run-secrets"
    directory.mkdir()
    for name in ("hmac_key_k1", "hmac_key_k2", "callback_hmac_key_c1"):
        (directory / name).write_bytes(_KEY)
    return directory


def _settings(secrets_dir: Path, **overrides: object) -> Settings:
    """Settings whose Postgres and Qdrant credentials are NOT mounted unless a test says so.

    That is the ``ai-worker-crawl`` shape and it is the default here on purpose: it keeps the
    unit tier from opening a connection pool at all. A pool that is opened and not closed keeps
    its background tasks alive and the pytest process never exits — measured, and it is the same
    leak ``close_pool``'s docstring describes in a deployment as exhausting ``max_connections``
    with sockets nobody is using.
    """
    unmounted = secrets_dir / "not-mounted-in-this-container"
    defaults: dict[str, object] = {
        "pg_password_path": unmounted,
        "qdrant_api_key_path": unmounted,
    }
    return Settings(  # type: ignore[arg-type]
        environment="ci", hmac_key_dir=secrets_dir, **(defaults | overrides)
    )


async def test_a_container_with_no_mounted_credentials_gets_no_pool_and_no_qdrant_client(
    secrets_dir: Path,
) -> None:
    """The ``ai-worker-crawl`` shape: both paths configured, neither file mounted.

    Building either one unconditionally raises out of ``worker_process_init`` — both
    ``postgres_dsn()`` and ``qdrant_api_key()`` read the file and raise when it is absent — and
    crash-loops a container that is correct as it stands.
    """
    clients = await open_runtime_clients(_settings(secrets_dir))
    try:
        assert clients.db_pool is None
        assert clients.qdrant is None
        assert clients.nonce_store is not None, "the broker credential IS mounted everywhere"
        assert clients.key_ring.secret_for("k1") == _KEY
    finally:
        await close_runtime_clients(clients)


async def test_a_container_that_holds_the_credential_does_get_the_pool(
    secrets_dir: Path,
) -> None:
    """The other half, so the capability check cannot pass by always answering "no".

    Opening the pool performs no round trip — ``AsyncConnectionPool.open()`` returns before its
    first connection exists — which is why this runs with no database anywhere.
    """
    password = secrets_dir / "postgres_password"
    password.write_text("not-the-real-one", encoding="utf-8")

    clients = await open_runtime_clients(_settings(secrets_dir, pg_password_path=password))
    try:
        assert clients.db_pool is not None
        assert clients.qdrant is None, "the Qdrant key is still not mounted in this shape"
    finally:
        await close_runtime_clients(clients)


async def test_the_coordination_and_cache_clients_are_two_different_servers(
    secrets_dir: Path,
) -> None:
    """Not two logical databases on one server, and not one client used for both.

    ``maxmemory-policy`` is server-wide, so a single instance cannot give the coordination
    families ``noeviction`` while the answer cache runs ``allkeys-lru``. A replay nonce on an
    evicting server is a replay window that opens under memory pressure and closes again, and
    nothing anywhere reports it.
    """
    settings = _settings(secrets_dir)

    clients = await open_runtime_clients(settings)
    try:
        assert clients.cache is not clients.nonce_store
        assert settings.coordination_url != settings.cache_url
    finally:
        await close_runtime_clients(clients)


async def test_missing_key_material_fails_startup_rather_than_degrading(tmp_path: Path) -> None:
    """The one asymmetry: an unreachable dependency must not stop a container from starting, and
    a missing signing key must.

    A key ring is a deployment fact, not a transient one — no number of retries loads a file
    that was never mounted — and a process serving ``/internal/v1`` with no ring answers 500
    with ``origin=SELF`` on every request.
    """
    empty = tmp_path / "no-secrets-here"
    empty.mkdir()

    with pytest.raises(KeyRingError):
        await open_runtime_clients(Settings(environment="ci", hmac_key_dir=empty))


async def test_the_nonce_store_speaks_the_surface_verify_hmac_actually_uses(
    secrets_dir: Path,
) -> None:
    """``app/api/deps.py`` calls ``set(key, value, nx=…, ex=…)`` and nothing else, and
    ``app/core/idempotency.py`` adds ``execute_command`` and ``ttl``.

    ``tests/support/coordination.py`` is the in-process double for exactly those, and it is only
    worth anything if the real client has the same surface. Asserted by NAME here, because
    calling them needs a server — ``tests/integration/test_coordination_store.py`` runs the
    shared contract callables against one.
    """
    settings = _settings(secrets_dir)

    clients = await open_runtime_clients(settings)
    try:
        for method in ("set", "get", "ttl", "delete", "execute_command", "aclose"):
            assert callable(getattr(clients.nonce_store, method, None)), (
                f"the coordination client has no {method}(); "
                f"tests/support/coordination.py doubles a surface the real client lacks"
            )
    finally:
        await close_runtime_clients(clients)


async def test_close_is_not_derailed_by_one_client_that_refuses_to_close(
    secrets_dir: Path,
) -> None:
    """Teardown runs in ``lifespan``'s ``finally``, where an exception is raised out of the ASGI
    shutdown and turns a clean stop into a hang that ends at the stop grace period."""
    from app.core.runtime import RuntimeClients

    closed: list[str] = []

    class _Angry:
        async def aclose(self) -> None:
            raise RuntimeError("connection already gone")

    class _Polite:
        async def aclose(self) -> None:
            closed.append("polite")

    # Built by hand rather than opened: this is about the ORDER and the guards in
    # `close_runtime_clients`, and opening a real pool here only to replace it in the same
    # breath would leak it — the pool's background tasks then outlive the test and the pytest
    # process never exits.
    await close_runtime_clients(
        RuntimeClients(
            settings=_settings(secrets_dir),
            key_ring=None,  # type: ignore[arg-type]
            nonce_store=_Polite(),
            cache=_Angry(),
            qdrant=None,
            db_pool=None,
        )
    )

    assert closed == ["polite"], "a client that raised on close stopped the others from closing"


# ── the repr of the object that holds every client ───────────────────────────
#
# `RuntimeClients` is a frozen dataclass, so it had the generated `__repr__`, which renders
# every field. Two of those fields were built from a credential that is still inside them:
# each Valkey client holds the ACL password in the connection holder it parsed its URL into,
# whose own `__repr__` joins every keyword pair it was given. Nothing reprs one today — the
# finding is that it is one `logger.debug("clients=%r", clients)`, one `assert clients,
# f"{clients}"`, or one library rendering a holder inside an error message away, in a process
# shape that both `ai-api` and every Celery child run.
#
# Asserted as the SECRET'S ABSENCE, never a mask's presence: `"password" not in repr(...)`
# would pass against a repr that renders `password=hunter2` under a different spelling, and
# `"**********" in repr(...)` passes against one that prints the mask beside the value.


async def test_the_client_holder_does_not_render_the_valkey_password(secrets_dir: Path) -> None:
    password = secrets_dir / "valkey_password"
    password.write_text("hunter2-the-acl-password", encoding="utf-8")

    clients = await open_runtime_clients(_settings(secrets_dir, valkey_password_path=password))
    try:
        rendered = repr(clients)
        assert "hunter2-the-acl-password" not in rendered
        # The clients themselves are what carry it, so the same string must not appear via
        # any nested repr either — this is the whole reason the field types are named and
        # the field values are not.
        assert "hunter2-the-acl-password" not in f"{clients}"
    finally:
        await close_runtime_clients(clients)


async def test_the_password_really_is_inside_the_client_that_was_built(
    secrets_dir: Path,
) -> None:
    """The positive control, without which the test above proves nothing.

    A redaction test against a client that never received the credential passes for the wrong
    reason forever. This asserts the material is genuinely reachable from the object the
    holder stores — which is exactly why the holder must not render it.
    """
    password = secrets_dir / "valkey_password"
    password.write_text("hunter2-the-acl-password", encoding="utf-8")

    clients = await open_runtime_clients(_settings(secrets_dir, valkey_password_path=password))
    try:
        assert "hunter2-the-acl-password" in repr(clients.nonce_store.connection_pool), (
            "the pinned redis client no longer renders its connection kwargs, so the "
            "RuntimeClients repr test above has stopped proving anything — re-derive the "
            "leak before relaxing either test"
        )
    finally:
        await close_runtime_clients(clients)


async def test_the_holder_still_says_which_clients_the_process_was_provisioned_for(
    secrets_dir: Path,
) -> None:
    """A redacting repr that says nothing is a repr an operator turns back into the default.

    What the object is actually asked at 3am is which clients this container holds, which is
    exactly what `None` versus not-`None` records.
    """
    clients = await open_runtime_clients(_settings(secrets_dir))
    try:
        rendered = repr(clients)
        assert rendered.startswith("RuntimeClients(")
        assert "key_ring=KeyRing" in rendered
        assert "qdrant=None" in rendered, "this shape mounts no Qdrant key"
        assert "db_pool=None" in rendered, "this shape mounts no database password"
        assert "nonce_store=Redis" in rendered
    finally:
        await close_runtime_clients(clients)

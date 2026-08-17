"""Session fixtures for the AI data plane suite.

Read `pytest-ai-service` before editing. Two rules from it shape everything below and both
have the same failure mode — a suite that is green while production leaks:

* **Every shared resource is worker-scoped, not just the database.** Under ``-n auto`` the
  Qdrant collection, the Valkey logical DB, the SeaweedFS bucket, the cache prefix **and the
  Celery queue names** must all carry the xdist worker id. Two workers sharing any one of
  them is how ``gw3``'s canary lands in ``gw0``'s assertion — and it only ever happens in
  CI, where the parallelism is.
* **Nothing in-process stands in for the chat stream.** There is deliberately no
  ``ASGITransport``/``TestClient`` fixture here. ``tests/support/live_server.py`` binds a
  real Uvicorn to a real socket, and every streaming or cancellation test uses it.

There is **no ``event_loop`` fixture and no ``event_loop_policy`` fixture**, and there must
never be one: ``event_loop`` was removed in pytest-asyncio 1.0.0 and overriding
``event_loop_policy`` is deprecated as of 1.4.0. Loop scoping is configured once, in
``pyproject.toml``, with ``asyncio_default_fixture_loop_scope`` and
``asyncio_default_test_loop_scope`` set to the *same* value — their defaults disagree, and
the resulting "attached to a different loop" error lands on the second test in a file rather
than the first. ``tests/unit/test_harness_guards.py`` fails if either fixture reappears.

Nothing at module scope imports ``app`` or any third-party package. The ``unit/`` tier must
run on a bare interpreter with pytest and nothing else; an import here is an import for every
tier. Every app import below is inside a fixture body.
"""

from __future__ import annotations

import os
import sys
import uuid
from collections.abc import Iterator, Mapping
from typing import TYPE_CHECKING, Any, Final

import pytest

if TYPE_CHECKING:  # pragma: no cover - typing only
    from pathlib import Path
    from types import ModuleType

# ── image tags, pinned to the Compose `test` profile ─────────────────────────
# testcontainers' own defaults drift on every release, so a fixture that does not pin runs
# the suite against a different server minor than the one we deploy — and a Qdrant minor is
# exactly where filter-propagation behaviour changes. Keep these equal to
# infrastructure/docker/compose.yaml's `test` profile services.
QDRANT_IMAGE: Final[str] = "qdrant/qdrant:v1.18.3"
POSTGRES_IMAGE: Final[str] = "postgres:18-alpine"
VALKEY_IMAGE: Final[str] = "valkey/valkey:9.1.1"
SEAWEEDFS_IMAGE: Final[str] = "chrislusf/seaweedfs:4.40"

#: The five Celery queues, one per cost class — deliberately fewer than the six routing
#: patterns, because deletion shares `maintenance`. Suffixed per worker below: a real prefork
#: worker started by one xdist worker will otherwise happily consume a task another xdist
#: worker published, and the symptom is a task that "ran twice" in CI and never locally.
QUEUE_NAMES: Final[tuple[str, ...]] = (
    "ingest",
    "crawl",
    "embed",
    "evaluate",
    "maintenance",
)


# ─────────────────────────────────────────────────────────────────────────────
# Worker identity
# ─────────────────────────────────────────────────────────────────────────────


@pytest.fixture(scope="session")
def kb_worker_id() -> str:
    """``"gw0"`` … under ``-n``, ``"master"`` otherwise.

    Read from the environment rather than by depending on pytest-xdist's own ``worker_id``
    fixture, so this conftest still imports and the ``unit/`` tier still runs when xdist is
    not installed. ``PYTEST_XDIST_WORKER`` is exactly what that fixture returns.
    """
    return os.environ.get("PYTEST_XDIST_WORKER", "master")


@pytest.fixture(scope="session")
def worker_ordinal(kb_worker_id: str) -> int:
    """``gw7`` -> 7, ``master`` -> 0. Used only where a resource is numeric (Valkey DB)."""
    return int(kb_worker_id[2:]) if kb_worker_id.startswith("gw") else 0


@pytest.fixture(scope="session")
def test_run_uid() -> str:
    """Stable for one ``pytest`` invocation, distinct between invocations.

    Two suites running concurrently against one shared CI Qdrant — a merge-queue job and a
    rerun of the same PR — collide on collection names without this, and the failure reads
    as a flaky isolation test rather than as a collision.
    """
    return os.environ.get("PYTEST_XDIST_TESTRUNUID", uuid.uuid4().hex)[:12]


@pytest.fixture(scope="session")
def resource_suffix(test_run_uid: str, kb_worker_id: str) -> str:
    """The one suffix every shared-resource name below is built from."""
    return f"{test_run_uid}_{kb_worker_id}"


# ─────────────────────────────────────────────────────────────────────────────
# Per-worker names for every shared resource
# ─────────────────────────────────────────────────────────────────────────────


def _rebind_everywhere(defining_module: ModuleType, attr: str, value: object) -> None:
    """Set ``attr`` on its defining module *and* on every module that imported it by name.

    ``from app.retrieval.collection import COLLECTION`` binds the value into the importing
    module's globals at import time, so patching only the defining module leaves every
    existing importer pointing at the old string. This walks ``sys.modules`` and rebinds any
    module whose ``attr`` is still identical to the original object.
    """
    original = getattr(defining_module, attr)
    setattr(defining_module, attr, value)
    for module in list(sys.modules.values()):
        if module is None or module is defining_module:
            continue
        if getattr(module, attr, None) is original:
            setattr(module, attr, value)


@pytest.fixture(scope="session")
def qdrant_collection(resource_suffix: str) -> Iterator[str]:
    """A per-worker Qdrant collection, derived from a **suffix** — never from a setting.

    ``app/retrieval/collection.py`` owns ``COLLECTION`` as a module constant and there is
    deliberately no ``KB_QDRANT_COLLECTION`` setting, because a collection name that can be
    overridden per environment is a name that can disagree between the indexer and the
    reader — and that disagreement raises nothing, shows nothing in a config diff, and reads
    as an empty corpus.

    That decision is correct and this fixture does not undo it. It rebinds the constant
    *in-process, for the test session only*, which is a property of the test harness rather
    than a configuration surface: nothing outside ``tests/`` can reach it, and no deployment
    can set it. If you find yourself wanting an environment variable here, the thing you
    actually want is this fixture.

    The suffix is appended, never substituted, so a test collection is still recognisably
    derived from ``collection.COLLECTION`` and cannot be mistaken for a real one. Do not
    reintroduce a model name here: ``COLLECTION`` is deliberately no longer ``kb_bge_m3_v1``
    (see ``app/retrieval/collection.py``), because that name asserted a model this service no
    longer runs. The base name encodes no embedding identity at all now — that identity lives
    on ``EmbeddingSpace``, taken from the source version being operated on.
    """
    from app.retrieval import collection as collection_module

    name = f"{collection_module.COLLECTION}_t_{resource_suffix}"
    _rebind_everywhere(collection_module, "COLLECTION", name)
    yield name
    # No teardown drop here on purpose: a session-scoped drop is the one operation that can
    # destroy another worker's collection if the name is ever wrong. Cleanup of stale test
    # collections belongs to the CI job, keyed on the run uid prefix.


@pytest.fixture(scope="session")
def valkey_db(worker_ordinal: int) -> int:
    """Logical DB index, offset past the application's own 0-7.

    Mirrors ``pest-testing``'s ``8 + $token``, so a PHP worker and a Python worker running
    the same shard never land on the same DB.
    """
    return 8 + worker_ordinal


@pytest.fixture(scope="session")
def cache_prefix(resource_suffix: str) -> str:
    """Family-first, per `kb-tenancy-isolation`: the org segment follows the family.

    This is the *test* prefix that precedes the family, so a stray key from a previous run
    can never satisfy an assertion in this one.
    """
    return f"kbtest:{resource_suffix}:"


@pytest.fixture(scope="session")
def s3_bucket(resource_suffix: str) -> str:
    """S3 bucket names are DNS labels: lowercase, dots and underscores are not safe."""
    return f"kb-test-{resource_suffix.replace('_', '-').lower()}"


@pytest.fixture(scope="session")
def celery_queues(resource_suffix: str) -> Mapping[str, str]:
    """The six queue names, suffixed per worker.

    Returned as a mapping from the *logical* name to the wire name so a test can say
    ``celery_queues["ingest"]`` and never hardcode the suffix.
    """
    return {name: f"{name}_{resource_suffix}" for name in QUEUE_NAMES}


@pytest.fixture(scope="session")
def celery_routes(celery_queues: Mapping[str, str]) -> Mapping[str, dict[str, str]]:
    """``task_routes`` rewritten onto the per-worker queues.

    Built from the production routing table rather than restated, so a route added to
    ``app/worker/config.py`` without a queue in ``QUEUE_NAMES`` fails here instead of
    publishing onto a queue no test worker consumes.
    """
    from tests.support.celery_config import load_worker_config

    config = load_worker_config()
    routes: dict[str, dict[str, str]] = {}
    for pattern, route in config.task_routes.items():
        queue = route["queue"]
        if queue not in celery_queues:
            msg = (
                f"app/worker/config.py routes {pattern!r} to queue {queue!r}, which is not in "
                f"tests/conftest.py QUEUE_NAMES {QUEUE_NAMES}. Add it there, or the queue is "
                f"shared between xdist workers."
            )
            raise AssertionError(msg)
        routes[pattern] = {"queue": celery_queues[queue]}
    return routes


# ─────────────────────────────────────────────────────────────────────────────
# Containers — testcontainers, in CI and locally alike
# ─────────────────────────────────────────────────────────────────────────────
#
# THE `KB_TEST_*` SHORT-CIRCUITS BELOW ARE NOT A SUPPORTED PATH, AND THIS COMMENT USED TO SAY
# THEY WERE THE CI ONE. There was never a merge-queue job or a workflow `services:` block, and
# since 2026-08-17 there is no workflow at all: every fixture here starts its own container,
# wherever the suite runs, which is now only a developer's machine.
#
# Worse than stale — the path it pointed at detonates. `KB_TEST_PG_DSN`, `KB_TEST_QDRANT_URL`
# and `KB_TEST_VALKEY_URL` carry the `KB_` prefix, so `check_environment` rejects them as
# `KB_*` names matching no setting; `get_settings()` raises, and `app/worker/__init__.py:38`
# calls it at IMPORT. Measured 2026-08-11 with all three set, `pytest -m "not integration"`
# ends `Interrupted: 4 errors during collection` — the whole run, not four tests.
#
# The short-circuits are kept rather than deleted because the branch is the cheap half and the
# expensive half is a decision: admitting these names to `EXTERNALLY_READ_VARIABLES` means
# weakening `test_every_externally_read_variable_is_still_set`, which requires a `compose.yaml`
# assignment or a Dockerfile reader per entry, and permanently pre-authorising the `KB_TEST_*`
# spelling inside every production container. `tests/integration/README.md` carries the full
# reasoning and `tests/unit/test_settings_dsn.py` pins the rejection.
#
# testcontainers 4.15 moved every module to `testcontainers.community.*`. The old top-level
# paths are shims that emit a DeprecationWarning, and `-W error::DeprecationWarning` in
# addopts turns that into a collection error — which is the intended outcome, not a problem
# to work around.


@pytest.fixture(scope="session")
def qdrant_url() -> Iterator[str]:
    """A real Qdrant server. Never ``QdrantClient(":memory:")``.

    The in-memory client does not implement the server's filter propagation into prefetch
    branches, so the leaky hybrid query of `kb-tenancy-isolation` **leaks in memory and is
    safe in production** — exactly inverting what an isolation test proves. In-memory is
    legal for ``Filter`` construction unit tests and nothing else;
    ``tests/unit/test_harness_guards.py`` enforces that.
    """
    supplied = os.environ.get("KB_TEST_QDRANT_URL")
    if supplied:
        yield supplied
        return

    from testcontainers.community.qdrant import QdrantContainer

    with QdrantContainer(image=QDRANT_IMAGE) as container:
        yield f"http://{container.get_container_host_ip()}:{container.get_exposed_port(6333)}"


@pytest.fixture(scope="session")
def pg_dsn() -> Iterator[str]:
    """A real PostgreSQL. Never SQLite: the schema depends on ``jsonb``, partial indexes and
    the composite foreign keys that guard ``bot_source_assignments``."""
    supplied = os.environ.get("KB_TEST_PG_DSN")
    if supplied:
        yield supplied
        return

    from testcontainers.community.postgres import PostgresContainer

    with PostgresContainer(image=POSTGRES_IMAGE, driver=None) as container:
        yield container.get_connection_url()


@pytest.fixture(scope="session")
def valkey_url(valkey_db: int) -> Iterator[str]:
    """``redis://``, never ``valkey://`` — kombu has no valkey transport alias, and the
    scheme raises "No such transport" at first connect, long after startup, looking like a
    broker outage."""
    supplied = os.environ.get("KB_TEST_VALKEY_URL")
    if supplied:
        yield f"{supplied.rstrip('/')}/{valkey_db}"
        return

    from testcontainers.community.valkey import ValkeyContainer

    with ValkeyContainer(image=VALKEY_IMAGE) as container:
        host = container.get_container_host_ip()
        # `ValkeyContainer.get_exposed_port()` OVERRIDES the base class's and takes NO argument:
        # it closes over the `port` it was constructed with and calls up. Passing 6379 the way
        # `qdrant_url` and every generic-container example do raises `TypeError: takes 1
        # positional argument but 2 were given` — at fixture setup, so every integration test
        # that touches Valkey errors rather than fails, and the traceback names testcontainers
        # rather than this line.
        port = container.get_exposed_port()
        yield f"redis://{host}:{port}/{valkey_db}"


# ─────────────────────────────────────────────────────────────────────────────
# The application under test
# ─────────────────────────────────────────────────────────────────────────────


@pytest.fixture(scope="session")
def settings(
    pg_dsn: str,
    qdrant_url: str,
    valkey_url: str,
    s3_bucket: str,
    qdrant_collection: str,  # ordering dependency: rebind before anything imports COLLECTION
    tmp_path_factory: pytest.TempPathFactory,
) -> Any:
    """A ``Settings`` built from the worker's own endpoints.

    ``Settings`` is ``frozen`` with ``extra="forbid"``, so this constructor is also a
    contract test in disguise: a setting renamed in ``app/core/config.py`` fails here.

    There is no ``postgres_dsn`` setting to pass. The DSN carries the password and a password
    is never an environment value, so ``Settings`` holds host/port/database/user plus a
    password **path** and ``config.postgres_dsn()`` joins them where a connection is opened.
    The container's DSN is therefore taken apart here and the password written to a file — the
    harness exercising the real assembly rather than a shape only tests use.
    """
    from urllib.parse import unquote, urlsplit

    from app.core.config import Settings

    parts = urlsplit(pg_dsn)
    password_path: Path | None = None
    if parts.password:
        password_path = tmp_path_factory.mktemp("pg") / "password"
        password_path.write_text(unquote(parts.password), encoding="utf-8")

    return Settings(
        environment="ci",
        pg_host=parts.hostname or "localhost",
        pg_port=parts.port or 5432,
        pg_database=parts.path.lstrip("/") or "kb",
        pg_user=unquote(parts.username or "kb"),
        pg_password_path=password_path,
        qdrant_url=qdrant_url,
        broker_url=valkey_url,
        cache_url=valkey_url,
        s3_bucket=s3_bucket,
    )


@pytest.fixture(scope="session")
def app(settings: Any) -> Any:
    """The ASGI application, built per session by ``create_app(settings)``.

    Returned **unserved**. Mounting it on ``httpx.ASGITransport`` is legal only under
    ``contract/`` and only for non-streaming routes; anything touching
    ``text/event-stream`` takes this to ``tests/support/live_server.py`` instead.
    """
    from app.main import create_app

    return create_app(settings)

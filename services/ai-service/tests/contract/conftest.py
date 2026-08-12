"""The signed-transport harness, shared by every file in this tier.

There is **no `verify_hmac` override here and there must never be one.** `tests/contract/README.md`
is explicit that a test bypassing signing is not a contract test, and the application carries no
skip flag to reach for. What this file supplies instead is the other half: a real key ring, a
real signer holding the same secret, and a coordination store the nonce check can actually
claim against — so signing a request is one call and there is no incentive to shortcut it.

NO ``LifespanManager``, AND THE REASON IS NOT LAZINESS. Running lifespan calls ``get_settings()``,
which runs ``check_environment(os.environ)`` and fails on the ``KB_TEST_*`` variables the harness
itself sets — so the manager would make every file here pass locally and error in CI for a
reason unrelated to anything asserted. The three objects lifespan is *supposed* to put on the
app are therefore installed directly on ``app.state``, which is exactly where ``app/api/deps.py``
reads them from, and is the shape the later wiring task has to produce.
"""

from __future__ import annotations

from collections.abc import Callable, Iterator
from typing import Any, Final

import httpx
import pytest

from tests.support.coordination import InMemoryCoordination
from tests.support.signing import Signer, ulid_like

#: 32 bytes, matching `app/core/keys.py`'s floor — a shorter one is refused at load, and a
#: fixture that could not have been loaded from disk is a fixture that proves nothing.
REQUEST_SECRET: Final[bytes] = b"contract-tier-hmac-secret-k1-321"
#: The second live id. Two are live during a rotation and the verifier must accept both, which
#: is why tests construct two `Signer`s rather than mutating one.
ROTATION_SECRET: Final[bytes] = b"contract-tier-hmac-secret-k2-321"
CALLBACK_SECRET: Final[bytes] = b"contract-tier-hmac-secret-c1-321"


@pytest.fixture
def nonce_store() -> InMemoryCoordination:
    """Per test, never per session: a session-scoped nonce store would make one test's
    request id a replay in the next, and the failure would depend on collection order."""
    return InMemoryCoordination()


@pytest.fixture
def key_ring() -> Any:
    from app.core.keys import KeyRing

    return KeyRing(
        request={"k1": REQUEST_SECRET, "k2": ROTATION_SECRET},
        # A disjoint set on purpose: `k*` signs Laravel→FastAPI and `c*` signs the callbacks
        # back. Sharing one set silently discards the property that a compromised outbound key
        # cannot forge an inbound request, while every test still passes.
        callback={"c1": CALLBACK_SECRET},
    )


@pytest.fixture
def transport_settings() -> Any:
    """A ``Settings`` built programmatically, so no ambient ``KB_*`` reaches it.

    ``environment="ci"`` and nothing else: every value this tier depends on — the skew window,
    the nonce TTL, the accepted prefixes — is a default, and stating them here would let the
    test agree with itself after the default moved.
    """
    from app.core.config import Settings

    return Settings(environment="ci")


@pytest.fixture
def signed_app(transport_settings: Any, key_ring: Any, nonce_store: InMemoryCoordination) -> Any:
    """A fresh app per test with the three lifespan-owned transport objects installed.

    THIS IS THE WIRING CHECKLIST, EXECUTABLE. Whatever ``lifespan`` and ``worker_process_init``
    end up doing, they have to leave these three attributes on ``app.state`` under these names,
    or every ``/internal/v1`` route answers 500 with ``origin=SELF``.
    """
    from app.main import create_app

    app = create_app(transport_settings)
    app.state.settings = transport_settings
    app.state.key_ring = key_ring
    app.state.nonce_store = nonce_store
    return app


@pytest.fixture
def signer() -> Signer:
    """Signs with ``k1``, the active request id."""
    return Signer(
        secret=REQUEST_SECRET,
        key_id="k1",
        org_id=ulid_like("contract-org-a"),
        actor_id=ulid_like("contract-actor"),
    )


@pytest.fixture
def rotation_signer() -> Signer:
    """Signs with ``k2``: the other id that is live during a rotation."""
    return Signer(
        secret=ROTATION_SECRET,
        key_id="k2",
        org_id=ulid_like("contract-org-a"),
        actor_id=ulid_like("contract-actor"),
    )


@pytest.fixture
def send(signed_app: Any) -> Iterator[Callable[..., Any]]:
    """Put a ``SignedRequest`` on the wire, exactly as signed.

    ``content=`` carries the same ``bytes`` object that was hashed. Never ``json=`` alongside
    it: httpx would re-encode, and the signature would cover different bytes than the socket
    carries — the intermittent-401 bug, reproduced in the harness instead of in production.

    ``raise_app_exceptions=False`` because Starlette's ``ServerErrorMiddleware`` sends the 500
    and then re-raises so a real server logs the traceback; through ``ASGITransport`` that
    re-raise reaches the test instead of the response, and several tests here are about what an
    unverified caller actually *receives*.
    """

    async def _send(request: Any, *, raise_app_exceptions: bool = False) -> httpx.Response:
        transport = httpx.ASGITransport(app=signed_app, raise_app_exceptions=raise_app_exceptions)
        async with httpx.AsyncClient(transport=transport, base_url="http://ai-api") as client:
            return await client.request(request.method, request.path, **request.kw)

    yield _send

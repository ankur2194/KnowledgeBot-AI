"""Request-scoped dependencies.

Signatures are final; bodies land with the chat and ingestion routers. They live here now
because `main.py` documents the router shape that depends on them, and because getting
`verify_hmac`'s placement wrong is the single most expensive mistake in this service.

**`verify_hmac` is a ROUTER-level dependency**, declared as
``APIRouter(prefix="/internal/v1", dependencies=[Depends(verify_hmac)])``, so a new endpoint
cannot forget it.

**It must never move into a `BaseHTTPMiddleware`.** It calls ``await request.body()``;
FastAPI caches those bytes on the ``Request`` and re-reads the cache when it parses the body
field, so signing the exact received bytes costs nothing. Consuming the receive stream in a
middleware without replaying it makes the endpoint await a body that no longer exists, and
the request hangs until the deadline with no error line anywhere.

This service authenticates a **caller**, never a user. Org, bot and actor come from the
*verified* headers — never from the body, never inferred from the peer address. There is no
session, no cookie, no token, and no query against Laravel's tables. Skipping this makes
network position the only control, and Compose is one ``ports:`` line away from removing it.

WHAT THIS MODULE READS OFF APPLICATION STATE, AND WHO PUTS IT THERE
-------------------------------------------------------------------
Three objects, all read from ``request.app.state`` and none of them constructed here:

===================  ==================================================================
``settings``         ``Settings`` — skew window, nonce TTL, accepted signing prefixes.
``key_ring``         ``KeyRing`` from ``load_key_ring()``: built ONCE, at startup, from a
                     directory listing, because ``key_id`` arrives from the wire.
``nonce_store``      An async Valkey client on ``coordination_url``'s logical DB. Only
                     ``.set(key, value, nx=…, ex=…)`` is used, so it stays untyped here
                     and this module imports no client library.
===================  ==================================================================

``request.app.state`` rather than ``request.state``: the lifespan-yielded mapping only
reaches ``request.state`` when lifespan actually ran, and ``ASGITransport`` does not run it.
Reading from the app makes the wiring one assignment in ``lifespan`` and one in
``worker_process_init``, and makes a contract test able to install a ring without booting a
lifespan that would call ``get_settings()`` on the harness's own environment.

``app/api/health.py`` reads the OTHER one — the mapping ``lifespan`` yields — and that is
equally deliberate. What the split costs is that reading a name off the wrong object returns
``None`` rather than failing, so ``APP_STATE_NAMES`` / ``REQUEST_STATE_NAMES`` and
``_assert_state_owner`` below make that specific mistake raise. Read that function before
adding a state read anywhere in this package.

Absence is a **500**, not a 401: a process serving requests with no key ring is a deployment
defect of ours, and ``origin=SELF`` is what keeps a client from retrying our bugs forever
(ADR-029).
"""

from __future__ import annotations

import re
import time
from collections.abc import AsyncIterator, Mapping
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any, Final

from fastapi import Request

from app.core.errors import ErrorClass, KbError, Origin
from app.core.signing import HEADER_PREFIX, SIGNATURE_HEADER, canonical_string, verify
from app.observability.logging import bind_log_context

if TYPE_CHECKING:  # pragma: no cover - typing only
    from app.core.config import Settings
    from app.core.keys import KeyRing

__all__ = [
    "APP_STATE_NAMES",
    "REQUEST_STATE_NAMES",
    "Deadline",
    "RequestContext",
    "app_state",
    "deadline",
    "from_request_state",
    "request_context",
    "verify_hmac",
]

#: THE ONLY MESSAGE ANY AUTHENTICATION FAILURE PRODUCES. An unknown key id, a wrong
#: signature, a stale timestamp, a replayed request id and a malformed signature header all
#: render this same string with the same status. Distinguishing them turns the endpoint into
#: an oracle: "unknown key id" enumerates which ids exist (`app/core/keys.py`), and "stale
#: timestamp" versus "bad signature" tells an attacker their forgery attempt was structurally
#: sound. The log line below carries the reason; the response never does.
_AUTHENTICATION_FAILED: Final = "internal request authentication failed"

#: Digits only, no leading zero, and bounded. This is the ``str(int(raw)) == raw``
#: assertion in regex form: PHP signed the header STRING, so a value that does not render
#: back to itself would have this side computing different canonical bytes than the signer.
#: 19 digits is past year 300-billion and short enough that ``int()`` cannot be an attack.
_TIMESTAMP_RE: Final = re.compile(r"\A(?:0|[1-9][0-9]{0,18})\Z")

#: Absolute epoch milliseconds. Same shape, three digits longer.
_EPOCH_MS_RE: Final = re.compile(r"\A(?:0|[1-9][0-9]{0,21})\Z")

#: A canonically-rendered ASCII decimal, bounded. Two rules, each closing a different hole:
#:
#: * ASCII digits only. `str.isdigit()` is NOT this — it accepts every Unicode digit, including
#:   the latin-1 superscripts `¹²³` that a header really can carry, and `int()` then raises on
#:   exactly the subset `isdigit()` was supposed to have cleared: a 500 where a 422 belongs.
#: * No leading zero, so the value renders back to itself. `X-KB-Config-Version` is inside the
#:   canonical string, and a version that has two spellings is a version two comparisons can
#:   disagree about — one comparing the header text, one comparing the parsed integer.
_DECIMAL_RE: Final = re.compile(r"\A(?:0|[1-9][0-9]{0,17})\Z")

#: ``key_id`` as it appears in the signature header, before the ring ever sees it. The ring's
#: own ``secret_for`` re-validates (it is the security boundary); this only keeps a 4 KB
#: header out of a Valkey key name.
_KEY_ID_RE: Final = re.compile(r"\A[a-z][a-z0-9]{0,7}\Z")

#: ``X-KB-Request-Id`` is a ULID on the wire. Bounded and colon-free so that
#: ``nonce:{key_id}:{request_id}`` cannot be made ambiguous by a value containing the
#: separator, and so one signer cannot inflate the coordination keyspace.
_REQUEST_ID_RE: Final = re.compile(r"\A[0-9A-Za-z_.-]{1,128}\Z")


@dataclass(frozen=True, slots=True)
class RequestContext:
    """Everything the data plane is allowed to know about who is asking.

    Populated from verified headers only. Laravel has already resolved identity, org
    membership, bot authorization, quota and rate limit; this service receives an
    authorized instruction and performs work. It does not re-derive whether the caller may
    act — that check exists exactly once, in Laravel, and duplicating it here would drift.
    """

    request_id: str
    org_id: str
    bot_id: str | None
    actor_id: str | None
    #: Always present. This is the gate on whether diagnostics — `retrieval.trace` in
    #: particular — may be returned at all. Leaving it off the context forces every endpoint
    #: that needs it to reach back into the raw request, which is exactly where a
    #: per-endpoint re-implementation drifts from the one that was reviewed.
    actor_type: str
    operation: str
    #: A LABEL, NOT A NUMBER — the wire value is ``"v1"``. This field used to be typed ``int``
    #: with a comment calling it "monotonic", and the wire has said ``v1`` on both sides since
    #: before either plane shipped: `tests/support/signing.py` sends it and
    #: `services/core-api/tests/Unit/InternalRequestSignerTest.php` signs it. An ``int`` here
    #: makes every real request unparseable while every test that built the context by hand
    #: stays green, which is why it is called out rather than quietly changed.
    contract_version: str
    #: The bot configuration snapshot this request was resolved against — and it is a NUMBER,
    #: for the same reason in the other direction: Laravel renders an integer into
    #: ``X-KB-Config-Version``. Parsed here so a non-numeric value is a 422 at the boundary
    #: rather than a string that compares unequal to every snapshot it is checked against.
    config_version: int


@dataclass(frozen=True, slots=True)
class Deadline:
    """An absolute deadline, held on a monotonic clock.

    The wire header is wall-clock epoch millis; it is converted once, on arrival. Checking
    the header value repeatedly means an NTP step mid-request moves the deadline — forward,
    and the work is abandoned early; backward, and it outlives the caller.
    """

    monotonic_expiry: float

    def remaining(self) -> float:
        import time

        return max(0.0, self.monotonic_expiry - time.monotonic())


def _refused() -> KbError:
    """The one rejection. Built by a function so no call site can add a distinguishing word."""
    return KbError(ErrorClass.AUTHENTICATION, _AUTHENTICATION_FAILED)


def _missing(name: str) -> KbError:
    """A header the contract requires is absent or malformed.

    Separate from ``_refused`` on purpose and only reachable AFTER verification: naming a
    header here is safe because the caller already proved it holds a signing key, and an
    unsigned request never reaches this function.
    """
    return KbError(ErrorClass.VALIDATION, f"{name} is required on every internal request")


#: The lifespan-owned names ``app/main.py``'s ``lifespan`` ASSIGNS onto ``app.state``, read
#: here through ``request.app.state``.
APP_STATE_NAMES: Final[frozenset[str]] = frozenset({"settings", "key_ring", "nonce_store"})

#: The lifespan-owned names ``lifespan`` YIELDS, which Starlette merges into ``request.state``,
#: read by ``app/api/health.py``. ``settings`` is on both lists deliberately — lifespan does
#: both things with the same object — and that overlap is exactly what makes the split easy to
#: get wrong somewhere it does NOT overlap.
#:
#: PER-REQUEST STAMPS ARE NOT IN HERE. ``request_id`` and ``surface`` are written onto
#: ``request.state`` by ``verify_hmac`` and ``request_context`` on the way through, and
#: ``app/main.py``'s envelope reads them with an explicit default because a request refused
#: before they were set is a normal case. This set is about objects a *process* holds.
REQUEST_STATE_NAMES: Final[frozenset[str]] = frozenset({"settings", "qdrant", "cache", "db_pool"})


def _assert_state_owner(name: str, *, read_from: str) -> None:
    """Refuse to look a lifespan-owned name up on the state object that does not own it.

    THE HAZARD THIS CLOSES IS SILENCE, NOT ABSENCE. ``lifespan`` deliberately does two things
    with one set of objects — it assigns three names onto ``app.state`` and yields a mapping
    Starlette merges into ``request.state`` — because ``deps.py`` must work under
    ``httpx.ASGITransport``, which does not run lifespan at all, while ``health.py`` wants the
    per-request view. That split is correct and is documented in ``lifespan``, in this module's
    header, and in ``tests/contract/conftest.py``. What it costs is that **reading the right
    name off the wrong object yields nothing rather than failing**: ``getattr(state, name,
    None)`` is how both sides read, so a future dependency asking ``request.app.state`` for
    ``qdrant`` gets ``None`` and reports the container un-provisioned, and one asking
    ``request.state`` for ``key_ring`` gets ``None`` and renders a generic 500 — in both cases
    indistinguishable from the deployment defect the ``None`` branch exists to catch.

    So the mistake is separated from the condition: an unowned name is a ``RuntimeError`` here
    (logged with a traceback by ``app/main.py``'s ``_handle_unexpected`` and naming the other
    state object), while a genuinely missing object keeps its existing meaning. The two lists
    are the executable copy of the split; ``lifespan`` is the one that has to satisfy them, and
    ``tests/unit/test_api_state_split.py`` reads both out of it rather than restating them.
    """
    owners = {"request.app.state": APP_STATE_NAMES, "request.state": REQUEST_STATE_NAMES}
    if name in owners[read_from]:
        return
    other = "request.state" if read_from == "request.app.state" else "request.app.state"
    elsewhere = (
        f" `lifespan` puts it on `{other}` instead."
        if name in owners[other]
        else f" `lifespan` puts it on neither; nothing named {name!r} is lifespan-owned."
    )
    msg = (
        f"{name!r} was read from `{read_from}`, which does not own it.{elsewhere} Reading it "
        f"from there would have returned None and been indistinguishable from a container that "
        f"was never provisioned. See app/api/deps.py APP_STATE_NAMES / REQUEST_STATE_NAMES and "
        f"app/main.py's `lifespan`, which is what has to satisfy both."
    )
    raise RuntimeError(msg)


def _from_app_state(request: Request, name: str) -> Any:
    """Read a lifespan-owned object off the application, or fail as OUR defect.

    ``getattr`` on ``State`` raises ``AttributeError``; letting that escape would render
    ``internal_dependency``/``downstream``, i.e. "a dependency of ours is briefly
    unavailable, retry" — which is the exact misclassification ADR-029 closed. A missing key
    ring is a deployment that never loaded one, and no number of retries loads it.

    A name this state object does not own raises before the lookup — see
    ``_assert_state_owner`` for why that is a different failure from a missing object and must
    not render as one.
    """
    _assert_state_owner(name, read_from="request.app.state")
    value = getattr(request.app.state, name, None)
    if value is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "The service could not complete this request.",
            origin=Origin.SELF,
        )
    return value


def from_request_state(request: Request, name: str) -> Any:
    """The mirror of ``_from_app_state`` for the names ``lifespan`` YIELDS.

    Public because ``app/api/health.py`` is the caller and the guard is worth more there than
    here: readiness legitimately answers ``None`` → not-ready when a client was never built, so
    that module cannot tell a container with no Qdrant credential apart from a typo'd attribute
    name. This does, and it does it without changing what a legitimate ``None`` means — the
    return value is still ``None``, and only an unowned NAME raises.
    """
    _assert_state_owner(name, read_from="request.state")
    return getattr(request.state, name, None)


def _kb_headers(request: Request) -> Mapping[str, str]:
    """The covered header set, with duplicates and non-ASCII values refused.

    Two hardenings that the canonical string cannot express by itself:

    * **A repeated ``X-KB-*`` name is a rejection.** ``Headers.items()`` yields every
      occurrence, so a smuggled second ``X-KB-Org-Id`` would produce two canonical lines
      here while the signer produced one — or, worse, a downstream ``headers["x-kb-org-id"]``
      would read the first while the signature covered both. Neither side of that is worth
      reasoning about; there is exactly one of each header or the request is refused.
    * **A non-ASCII value is a rejection.** Starlette decodes header bytes as latin-1 and we
      encode the canonical string as UTF-8, while PHP signs the wire bytes. On ASCII the
      three agree exactly; off it they do not, and every ``X-KB-*`` value is ASCII by
      contract (ULIDs, enum tokens, decimal integers, dotted operation names).
    """
    covered: dict[str, str] = {}
    for name, value in request.headers.items():
        lowered = name.lower()
        if not lowered.startswith(HEADER_PREFIX):
            continue
        if lowered in covered or not value.isascii():
            raise _refused()
        covered[lowered] = value
    return covered


async def verify_hmac(request: Request) -> None:
    """Verify signature, timestamp skew and replay nonce over the exact received bytes.

    Raises ``KbError(ErrorClass.AUTHENTICATION, ...)`` on any failure, with no detail about
    which check failed — a signature oracle is still an oracle.

    THE ORDER IS LOAD-BEARING. Skew is checked before the HMAC because it is free and cannot
    leak anything. The replay nonce is claimed **after** the signature verifies and never
    before: claiming first would let an unauthenticated caller burn a legitimate request's
    nonce by replaying its id with garbage bytes, turning replay protection into a denial of
    service against the only caller that matters.

    THE SKEW WINDOW AND THE NONCE ARE ONE SETTING, NOT TWO (`routes/internal.php`, rule 3). A
    60 s window with no nonce is a 60 s replay window; a nonce that expires inside the window
    is the same thing with extra steps. ``Settings`` enforces ``ttl > skew`` in a validator,
    and both values are read here rather than restated.

    THE REQUEST ID IS VALIDATED AND STAMPED FIRST, before any check that can refuse. Every
    failure below renders through ``app/main.py``'s envelope, which reads
    ``request.state.request_id``; ``request_context`` — the only place that used to set it —
    runs *after* this dependency, so an authentication failure carried ``request_id: null``,
    which is the identifier support greps for missing from the one class of failure most
    likely to need it. Two properties keep that safe. The id is stamped only after
    ``_REQUEST_ID_RE`` accepts it, so 128 characters of ``[0-9A-Za-z_.-]`` is the most a
    caller can put into a log line or an error body — an unvalidated header would go through
    verbatim. And it is stamped before the signature verifies, deliberately: on a refused
    request the id is the caller's *claim* rather than Laravel's, and that is exactly what an
    operator needs to see, because the alternative is a null on every refusal.
    """
    settings: Settings = _from_app_state(request, "settings")
    ring: KeyRing = _from_app_state(request, "key_ring")
    nonces = _from_app_state(request, "nonce_store")

    covered = _kb_headers(request)

    request_id = covered.get("x-kb-request-id", "")
    if not _REQUEST_ID_RE.match(request_id):
        raise _refused()
    request.state.request_id = request_id

    key_id, separator, provided = covered.get(SIGNATURE_HEADER, "").partition(":")
    if not separator or not _KEY_ID_RE.match(key_id) or not provided:
        raise _refused()

    raw_timestamp = covered.get("x-kb-timestamp", "")
    if not _TIMESTAMP_RE.match(raw_timestamp):
        raise _refused()
    if abs(time.time() - int(raw_timestamp)) > settings.hmac_max_skew_seconds:
        raise _refused()

    # Free: FastAPI caches these bytes on the Request and re-reads the cache when it parses
    # the body field. Re-serializing the parsed body instead is the intermittent-401 bug.
    body = await request.body()

    # Every accepted prefix, not only the active one: ADR-018 bumps the prefix over two
    # deploys, and verifying one makes the first deploy reject the second's in-flight work.
    verified = False
    for prefix in settings.accepted_signing_prefixes:
        canonical = canonical_string(
            method=request.method,
            path=request.url.path,
            timestamp=raw_timestamp,
            body=body,
            kb_headers=covered,
            prefix=prefix,
        )
        # No `break`: an early exit on the first accepted prefix makes the number of HMACs a
        # function of which prefix the caller used. There are at most two.
        verified = verify(provided, key_id, canonical, ring=ring) or verified
    if not verified:
        raise _refused()

    # `nonce:` is the ONE key family with no org segment, and the reason is ordering: this
    # check runs as part of signature verification, before any organization is resolved.
    # Keying it on X-KB-Org-Id would make replay protection depend on a value verification
    # has not yet established (`valkey-keyspaces`).
    key = f"nonce:{key_id}:{request_id}"
    try:
        fresh = await nonces.set(key, b"1", nx=True, ex=settings.replay_nonce_ttl_seconds)
    except Exception as exc:
        # FAIL CLOSED. A coordination store that cannot answer has not told us this request
        # is new, and "we could not check for a replay" must never render as "not a replay".
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "The service could not complete this request.",
            origin=Origin.DOWNSTREAM,
        ) from exc
    if not fresh:
        raise _refused()


async def request_context(request: Request) -> AsyncIterator[RequestContext]:
    """Build the context from verified headers.

    Separate from `verify_hmac` so the signature check cannot be satisfied by a caller that
    supplies context but no signature. `use_cache=True` is FastAPI's default, so resolving
    this from three places costs one call — but the cache key is the callable object, so
    wrapping it in a lambda or functools.partial silently re-runs it.

    A **generator** dependency, and that is the answer to the TODO in
    ``app/observability/logging.py``. Every log line carries ``request_id`` and ``operation``
    as required fields, they are unknowable from the ``LogRecord``, and ``bind_log_context``
    is a context manager — which a ``return``ing coroutine cannot hold open for the duration
    of a request. A dependency with ``yield`` can: FastAPI closes it from the request-scoped
    ``AsyncExitStack`` after the response is sent, so the binding covers the handler, the
    streaming generator and the exception handlers. The alternative — a second, separate
    yield dependency on every router — is one more thing an endpoint can forget, and the
    fields it would bind come from exactly this object.

    ``request.state.request_id`` is stamped here for the error envelope, which reads it off
    the request and would otherwise render ``request_id: null`` on every failure.
    ``verify_hmac`` stamps the same value first, from the same validated header, because it
    runs first and its own refusals are failures too; this restatement is what keeps the
    envelope populated on a router that composes this dependency without that one.

    ``contract_version`` IS STORED AND NOT COMPARED, and that is a recorded deferral rather
    than an oversight. `kb-internal-api-contracts` says a mismatch between
    ``X-KB-Contract-Version`` and the path prefix is a **409** — not a best-effort guess — and
    ``app/core/errors.py`` has no class that renders 409: all eighteen rows of ``_STATUS`` are
    accounted for and none is 409, so implementing the comparison means adding a class to the
    taxonomy, which is a change to a table three runtimes transcribe (Python, PHP, TypeScript)
    and not a fix to make inside this function. Raising ``VALIDATION`` instead would render 422
    and put a third spelling of one rule into the tree. Both values derive from the same
    Laravel configuration key today, so the mismatch is unreachable; the check is owed at the
    same time as the taxonomy row.

    ``config_version`` is likewise stored and read by nothing, and no consumer is possible in
    this scope: it identifies the configuration snapshot a request was resolved against, and
    the thing that would compare it — a cached or replayed snapshot to check it against — is
    not built. It is parsed and range-checked here so a malformed value is a 422 at the
    boundary, and held for a compatibility mechanism (ruling D3) that does not exist yet.
    """
    covered = request.headers

    def required(name: str) -> str:
        value = covered.get(name)
        if not value:
            raise _missing(name.upper())
        return value

    raw_config_version = required("x-kb-config-version")
    # `_DECIMAL_RE`, not `str.isdigit()`. `"¹²".isdigit()` is True — superscripts are latin-1
    # bytes, so they are transmissible in a header — and `int("¹²")` then raises ValueError,
    # turning a malformed header into a 500 with `origin=SELF` instead of a 422.
    if not _DECIMAL_RE.match(raw_config_version):
        raise KbError(
            ErrorClass.VALIDATION,
            "X-KB-CONFIG-VERSION must be an integer configuration-snapshot version",
        )

    ctx = RequestContext(
        request_id=required("x-kb-request-id"),
        org_id=required("x-kb-org-id"),
        # Absent for an organization-scoped operation — embedding readiness, a source upload
        # before assignment. Absent and empty mean the same thing; neither is a default.
        bot_id=covered.get("x-kb-bot-id") or None,
        actor_id=covered.get("x-kb-actor-id") or None,
        actor_type=required("x-kb-actor-type"),
        operation=required("x-kb-operation"),
        contract_version=required("x-kb-contract-version"),
        config_version=int(raw_config_version),
    )
    request.state.request_id = ctx.request_id
    with bind_log_context(
        request_id=ctx.request_id,
        operation=ctx.operation,
        org_id=ctx.org_id,
        bot_id=ctx.bot_id,
    ):
        yield ctx


async def deadline(request: Request) -> Deadline:
    """Convert ``X-KB-Deadline`` into a monotonic absolute deadline.

    The header is **absolute epoch milliseconds**, not a duration and not seconds — Laravel
    sets it from its own remaining budget, so a duration would restart the clock at every
    hop. The conversion happens exactly once, here, because recomputing from ``time.time()``
    later makes an NTP step mid-request look like an expired deadline.
    """
    raw = request.headers.get("x-kb-deadline", "")
    if not _EPOCH_MS_RE.match(raw):
        raise KbError(
            ErrorClass.VALIDATION,
            "X-KB-DEADLINE must be an absolute deadline in epoch milliseconds",
        )
    return Deadline(monotonic_expiry=time.monotonic() + (int(raw) / 1000.0 - time.time()))


async def app_state(request: Request) -> Any:
    """Hand out the lifespan-owned clients and models.

    Always through ``request.state``. Never constructed in a dependency (a fresh TLS
    handshake per provider call, straight onto the first-token budget) and never at import
    time (binds to whichever loop is running, then fails the moment a test uses a second).
    """
    return request.state

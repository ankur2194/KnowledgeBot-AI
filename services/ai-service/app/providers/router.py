"""The fallback loop: N provider attempts, exactly one outcome for the caller.

Every policy input this module needs already exists and none of it is restated here.
``RETRYABLE`` and ``FALLBACK_ELIGIBLE`` are ``app.core.errors``'; ``fallback_eligible``,
``BREAKER_ELIGIBLE`` and ``retry_after_seconds`` are ``app.providers.errors``'. What did not
exist is the walk: taking a bot's ordered chain of ``(connection, model)`` pairs, deciding
after each failure whether to try the same connection again or move to the next one, and
turning the whole thing back into the single stream shape ``ProviderAdapter.stream`` promises.

**THIS MODULE WRITES NO DATABASE ROW, AND THAT IS NOT AN OVERSIGHT.**
``provider_calls`` is a Laravel-owned table. It is not in ``app/db/writes.py::ALLOWED_TABLES``
and it must not be added: a write here would be an ADR-033 property-2 violation of exactly the
shape ``docs/22`` § S1 records, where this service was found ``UPDATE``-ing a Laravel-owned
column twenty lines below a docstring saying it never does. The plan sketch that preceded this
file said the router "writes one ``provider_calls`` row per attempt". It does not. It **emits**
one :class:`ProviderAttempt` per attempt through the ``record`` sink; the chat endpoint
serialises those as ``provider.fallback`` and ``provider.usage`` SSE frames (both internal-only
— Laravel consumes and does not forward them, `kb-internal-api-contracts`), and Laravel's stream
finalizer persists the rows. One user turn that falls back once is two records, because
otherwise the cost of falling back is invisible.

Four properties this file exists to hold, each of which fails silently if it is not held:

**1. Fallback is impossible once the first ``Delta`` has left the router.** You cannot un-send
a token. The provider has already billed for it and the user has already read it, so a second
attempt does not replace the answer, it *appends a second one*. The symptom is duplicated text
in the middle of an answer, and no status code anywhere is wrong. The gate is
:func:`may_switch_chains`, a named function rather than a comment, called by both policy
predicates and asserted directly by the suite. It counts **every** kind of ``Delta`` —
``reasoning`` and ``tool_args`` included — because all four kinds are billed output and three
of the four are rendered somewhere.

**2. Exactly one terminal ``ChatResult`` leaves this generator, on every path.** Success,
exhausted chain, open breaker, spent deadline, cancellation. On the paths where an adapter
produced a terminal event of its own, that event *is* ours — forwarded unchanged, so the
answer's usage, request id and diagnostics attribute the attempt that actually produced it.
Only where no adapter ran (or none could) does this module synthesize one, and then it borrows
the vendor name of the link it was about to call, because ``Diagnostics.provider`` feeds
``gen_ai.provider.name`` and that label set is the five adapters and nothing else. The
cancellation terminal is emitted from ``except asyncio.CancelledError`` and never from
``finally``: yielding while ``GeneratorExit`` unwinds raises ``RuntimeError``, ASGI swallows it,
and the only symptom is a missing usage row for a turn the vendor billed in full.

**3. The no-fallback list is never restated.** Ask :func:`may_fall_back`, which asks
``fallback_eligible(error_class, ProviderSurface.CHAT)``. Typing ``authentication``,
``refusal``, ``tenant_quota`` or ``context_exceeded`` into a set literal in this file would
create a second source of truth that does not move when the first one does. The one narrowing
permitted on top of the table is §8.7's per-connection rate-limit switch, and it can only
subtract: a class the table refuses can never be admitted by a connection setting.

**4. Retry and fallback are two decisions, not one counter.** Same-connection retry runs on
``RETRYABLE``; next-connection fallback runs on ``fallback_eligible``. A class can be one, both
or neither. ``max_retries=0`` stays on every vendor SDK — attempts multiply across tiers, and
three tiers retrying three times is twenty-seven vendor calls from one click.

Two things this module deliberately does not do. It never touches a credential: keys arrive as
a ``Mapping[str, SecretStr]`` and are looked up by ``connection_id`` at the call site that is
about to make the request, exactly as `kb-provider-adapter-contract` requires, and
``get_secret_value()` does not appear in this file. And it exposes **no non-chat path**:
``NON_CHAT_FALLBACK_ELIGIBLE`` is all-False by construction, so an embedding or rerank call may
retry its own connection and must then refuse — a different vendor is a different vector space,
and a chain walk is the wrong shape for that entirely. If one is ever added here it retries and
refuses; it does not walk a chain.
"""

from __future__ import annotations

import asyncio
import random
import time
from collections.abc import AsyncGenerator, AsyncIterator, Awaitable, Callable, Mapping, Sequence
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Final, Literal, Protocol

from pydantic import SecretStr

from app.core.errors import RETRYABLE, ErrorClass, KbError, Origin
from app.providers.contract import (
    ChatRequest,
    ChatResult,
    Diagnostics,
    ModelCapabilities,
    ProviderAdapter,
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import (
    BREAKER_ELIGIBLE,
    UNMAPPED,
    ProviderSurface,
    fallback_eligible,
    retry_after_seconds,
)

__all__ = [
    "BACKOFF_BASE_SECONDS",
    "BACKOFF_CAP_SECONDS",
    "MAX_ATTEMPTS_PER_LINK",
    "RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK",
    "AttemptOutcome",
    "AttemptSink",
    "Breaker",
    "BreakerKey",
    "ChainLink",
    "FallbackRouter",
    "NullBreaker",
    "ProviderAttempt",
    "link_attempt_budget",
    "may_fall_back",
    "may_retry_same_connection",
    "may_switch_chains",
]

#: One try plus two retries against the same connection, budgeted INSIDE the 45 s provider
#: total (`kb-error-taxonomy`, retry ownership). The arithmetic is the constraint, not the
#: number: ``attempts x (inner timeout + max backoff) + overhead < outer timeout``, and with a
#: 20 s first-token budget that permits at most two attempts before the deadline gate below
#: starts refusing to start them. Raising this without moving the deadline check just means
#: more attempts get skipped, more loudly.
MAX_ATTEMPTS_PER_LINK: Final[int] = 3

#: One try plus **one** retry when a rate limit is going to fall back anyway.
#:
#: §19.2 permits retrying a rate limit and §8.7 permits falling back on one, and running both
#: in series doubles latency past the 4 s first-token target for no gain — the ordering rule in
#: `kb-error-taxonomy` says retry the primary at most once, then fall back. This constant is
#: that rule. It applies only when a fallback is actually available and configured; a rate limit
#: with nowhere to go gets the full ladder, because waiting is then the only remedy left.
RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK: Final[int] = 2

#: Full jitter, capped: ``sleep = uniform(0, min(cap, base * 2**attempt))``
#: (`kb-error-taxonomy`; AWS's own measurement). The vendor's ``Retry-After`` is a FLOOR under
#: this, never the sleep itself — obeying a bare hint synchronizes every caller onto the same
#: instant and rebuilds the burst that produced the limit.
BACKOFF_BASE_SECONDS: Final[float] = 0.5
BACKOFF_CAP_SECONDS: Final[float] = 20.0

#: Two ``Diagnostics.extras`` keys that carry a gateway adapter's failure attribution. Read as
#: an allow-list, never as "whatever the adapter put there": ``extras`` is the contract's escape
#: hatch and this module must not grow a reading of it per vendor. Four of the five adapters set
#: neither key and take the default ``model`` scope; nothing here names a vendor.
_FAILURE_SCOPE_KEY: Final[str] = "failure_scope"
_UPSTREAM_SLUG_KEY: Final[str] = "upstream_slug"


async def _close(events: AsyncIterator[StreamEvent]) -> None:
    """Close an adapter's stream, if it is a generator.

    ``ProviderAdapter.stream`` promises an ``AsyncIterator``, which has no ``aclose`` — the five
    implementations are async generators, but the Protocol deliberately does not say so, and a
    test double is entitled to hand back a plain iterator. Breaking out of the ``async for`` on
    the terminal event leaves a real generator suspended at its last ``yield``; closing it there
    throws ``GeneratorExit`` at that point and the body ends without yielding again, which is
    the one shape that does NOT raise ``RuntimeError``.
    """
    if isinstance(events, AsyncGenerator):
        await events.aclose()


async def _sleep(seconds: float) -> None:
    """The injectable clock's other half. A module function rather than ``asyncio.sleep``
    directly, so the default has one concrete type and a test can replace it with a recorder
    that never actually waits."""
    await asyncio.sleep(seconds)


# ── the per-attempt record ────────────────────────────────────────────────────


class AttemptOutcome(StrEnum):
    """What happened to one attempt. Seven values, because seven different remedies.

    The three ``skipped_*`` values and ``rejected`` describe attempts where **no request was
    sent**, which is why :attr:`ProviderAttempt.made_request` exists: a ``provider_calls`` row
    for a call that never happened would put a zero-token, zero-cost row into billing
    reconciliation and make the fallback ladder look cheaper than it is.
    """

    #: The vendor answered. Includes a content-policy refusal, which is a 200 and is billed —
    #: it is terminal for the turn, but it is not a failure of the dependency.
    SUCCESS = "success"
    #: The vendor failed, or a mid-stream error arrived inside an HTTP 200.
    ERROR = "error"
    #: The turn was cancelled while this attempt was streaming. Still billed for whatever the
    #: vendor generated, so it still carries usage.
    CANCELLED = "cancelled"
    #: ``validate()`` refused before the first byte — an unsupported option on a model whose row
    #: says ``on_unsupported="reject"``. Our defect or the tenant's configuration, never the
    #: vendor's, and nothing was sent.
    REJECTED = "rejected"
    #: The breaker for this ``(org, connection, model)`` was open. The dependency is already
    #: known sick; sending would add to the pile.
    SKIPPED_BREAKER_OPEN = "skipped_breaker_open"
    #: Not enough of the caller's deadline was left for this attempt to plausibly finish.
    #: Starting it bills a completion the caller has already abandoned.
    SKIPPED_DEADLINE = "skipped_deadline"
    #: The chain named a connection whose credential is absent from the envelope. Fail closed:
    #: never substitute another connection's key, which would send one tenant's credential to a
    #: vendor they did not choose for this bot.
    SKIPPED_NO_CREDENTIAL = "skipped_no_credential"


#: Outcomes where a request actually reached the vendor.
_MADE_REQUEST: Final[Mapping[AttemptOutcome, bool]] = {
    AttemptOutcome.SUCCESS: True,
    AttemptOutcome.ERROR: True,
    AttemptOutcome.CANCELLED: True,
    AttemptOutcome.REJECTED: False,
    AttemptOutcome.SKIPPED_BREAKER_OPEN: False,
    AttemptOutcome.SKIPPED_DEADLINE: False,
    AttemptOutcome.SKIPPED_NO_CREDENTIAL: False,
}
assert set(_MADE_REQUEST) == set(AttemptOutcome)


@dataclass(frozen=True, slots=True)
class ProviderAttempt:
    """One attempt, as the caller will need to account for it.

    Frozen because it is a record of something that already happened. The field set is the
    ``provider_calls`` attribute list from docs/11 §16.6 minus the four the control plane owns
    (``organization_id``, ``conversation_id``, ``message_id``, ``id``) and minus estimated cost,
    which needs a pricing table this layer does not have and must not grow.
    """

    #: 1-based, across the WHOLE turn rather than per connection. Two attempts on the primary
    #: and one on the fallback are ordinals 1, 2, 3 — so a gap in the sequence is a dropped
    #: record rather than a design.
    ordinal: int
    connection_id: str
    #: The adapter's own ``name``: one of the five, and the ``provider`` metric label.
    provider: str
    model: str
    outcome: AttemptOutcome

    #: One of the 18, as a string, or None. Never an HTTP status and never a vendor code.
    error_class: str | None = None
    #: Present whenever the adapter produced a terminal event. ``REFUSAL`` and ``MAX_OUTPUT``
    #: are the two worth carrying: both are successful HTTP calls that ended badly, and only
    #: this field says so.
    stop_reason: StopReason | None = None
    provider_request_id: str | None = None
    usage: Usage = field(default_factory=Usage)
    #: Deltas this attempt yielded downstream. Non-zero means the answer is already partly on
    #: the wire, which is what makes every later failure terminal.
    deltas: int = 0
    first_token_ms: int | None = None
    latency_ms: int = 0

    #: Seconds actually slept BEFORE starting this attempt. Zero on the first attempt and on
    #: every fallback to a different connection — another vendor's window is not this one's.
    waited_seconds: float = 0.0
    #: The vendor hint that set the floor under that sleep, parsed by ``retry_after_seconds``.
    #: None means the vendor published no reset schema and the jittered ladder decided alone;
    #: it never means zero (`app/providers/errors.py`).
    retry_after_floor_seconds: int | None = None

    #: ``fallback_metadata``: the connection this attempt replaced, and the class that sent us
    #: here. Both None on the first attempt and on a same-connection retry — a retry is not a
    #: fallback and must not be counted as one, or ``kb_chat_fallbacks_total`` reports a model
    #: change that never happened.
    fell_back_from: str | None = None
    fallback_trigger: str | None = None

    #: The Valkey key component the breaker was consulted or updated under. A component only —
    #: **never** a metric label: for a gateway it carries an upstream slug whose value set grows
    #: without our involvement (`kb-observability-conventions` bans unbounded labels).
    breaker_key: str | None = None

    @property
    def made_request(self) -> bool:
        """Whether a ``provider_calls`` row is owed for this attempt."""
        return _MADE_REQUEST[self.outcome]

    @property
    def is_fallback(self) -> bool:
        return self.fell_back_from is not None

    @property
    def retry_after_honoured(self) -> bool:
        """Whether the vendor's own reset hint was obeyed as a floor.

        False when there was no hint, which is the honest reading: nothing was honoured because
        nothing was said. A caller wanting "did we wait at all" reads ``waited_seconds``.
        """
        floor = self.retry_after_floor_seconds
        return floor is not None and self.waited_seconds >= floor


#: Where the caller receives each record. Called synchronously as the attempt ends and BEFORE
#: the next attempt's first delta, so a ``provider.fallback`` frame reaches the client ahead of
#: the replacement model's tokens. It must not raise and must not block: it runs inside the
#: streaming generator, and an exception here loses the turn.
AttemptSink = Callable[[ProviderAttempt], None]


# ── breaker ───────────────────────────────────────────────────────────────────


@dataclass(frozen=True, slots=True)
class BreakerKey:
    """What the breaker is asked about, composed by the router and interpreted by the breaker.

    Scoping is `kb-error-taxonomy`'s: ``(org_id, credential, model)`` for credential-scoped
    classes, plus an upstream term when the provider is a gateway. The router composes the key
    and never the policy — which upstreams fan into which, and how a gateway-scoped record
    shadows every model on that credential, is the breaker implementation's business.

    **Pre-call gating is upstream-blind by construction**, and that is a property of the
    problem rather than a shortcut: you cannot know which upstream will serve a request before
    you send it. So ``allow()`` is always asked the ``model`` scope, and a breaker backed by
    real state is expected to consult its own gateway-scoped entry for the same
    ``(org_id, connection_id)`` — which is exactly why gateway-attributed failures open every
    model on the credential.
    """

    org_id: str
    connection_id: str
    model: str
    #: ``model`` — this credential and this model. ``upstream`` — a gateway told us which of its
    #: upstreams failed. ``gateway`` — a property of the account itself (402, platform 429, 401,
    #: an unsatisfiable routing block), which is not about any one model.
    scope: Literal["model", "upstream", "gateway"] = "model"
    upstream: str | None = None

    def __str__(self) -> str:
        base = f"{self.org_id}:{self.connection_id}"
        if self.scope == "gateway":
            return f"{base}:_gateway"
        if self.scope == "upstream" and self.upstream:
            return f"{base}:{self.model}:{self.upstream}"
        return f"{base}:{self.model}"


class Breaker(Protocol):
    """Injected, never a module global.

    A module-level breaker is per-process, and this code runs in the API process **and** in
    every Celery worker — so a provider that is sick for one is closed for the others and the
    breaker measures nothing except which process happened to make the last call. Real state
    lives in Valkey, which is why every method is async: a synchronous Protocol here would
    force the only correct implementation to block the event loop.
    """

    async def allow(self, key: BreakerKey) -> bool:
        """False when the breaker is open, or half-open with its probe budget in flight."""
        ...

    async def record_success(self, key: BreakerKey) -> None: ...

    async def record_failure(self, key: BreakerKey, error_class: ErrorClass) -> None:
        """Only ever called with a class in ``BREAKER_ELIGIBLE`` — the router gates first, in
        one place, so an implementation cannot forget and start counting one tenant's bad key
        toward an outage for everybody."""
        ...


class NullBreaker:
    """Allows everything and remembers nothing.

    Explicit rather than a default: :class:`FallbackRouter` requires a breaker argument, so
    running without one is a visible act at the construction site and greppable afterwards. A
    silently absent breaker is the failure mode this class exists to make loud — the loop looks
    identical and the protection is simply not there.
    """

    async def allow(self, key: BreakerKey) -> bool:
        return True

    async def record_success(self, key: BreakerKey) -> None:
        return None

    async def record_failure(self, key: BreakerKey, error_class: ErrorClass) -> None:
        return None


# ── the chain ─────────────────────────────────────────────────────────────────


@dataclass(frozen=True, slots=True)
class ChainLink:
    """One ``(connection, model)`` pair in the bot's ordered fallback chain.

    Carries no credential. The key is looked up by ``connection_id`` in the map passed to
    :meth:`FallbackRouter.stream`, at the call site about to make the request — never unwrapped
    into a container, because one comprehension over ``.items()`` produces a plain
    ``dict[str, str]`` with no masking left in it and a single ``str()`` of that object in an
    exception message prints every tenant key in the envelope.
    """

    connection_id: str
    model: str
    adapter: ProviderAdapter
    #: The ``provider_models`` row for THIS ``(provider, model)``. Capabilities are per model,
    #: so a fallback to a second vendor carries a different row and may legitimately reject an
    #: option the primary accepted — which surfaces as a ``CapabilityWarning``, not a silence.
    caps: ModelCapabilities
    #: §8.7's per-connection switch, off by default so a rate limit stays visible instead of
    #: quietly moving spend. It gates ONLY ``provider_rate_limit`` and can only subtract from
    #: what ``fallback_eligible`` already allows.
    fallback_on_rate_limit: bool = False


# ── the policy, as three predicates the suite can address directly ────────────


def may_switch_chains(deltas_yielded: int) -> bool:
    """Whether a different provider may still be tried.

    The single most important line in this module. Once a delta has left the router the
    provider has been billed and the user has read it; a second attempt does not replace that
    text, it appends more after it. The failure is duplicated prose in the middle of an answer,
    with a 200 on every hop and nothing in any log — which is why this is a named function with
    its own tests rather than an ``if`` with a comment above it.
    """
    return deltas_yielded == 0


def may_fall_back(
    error_class: ErrorClass,
    *,
    deltas_yielded: int,
    rate_limit_fallback_configured: bool,
) -> bool:
    """Whether this failure may be re-issued against the NEXT connection in the chain.

    Every no-fallback verdict comes from ``fallback_eligible``. Nothing in this function names
    a class that must not fall back, and nothing may be added that does: the moment
    ``authentication`` or ``refusal`` appears in a literal here, this file becomes a second
    source of truth that will not move when ``app/core/errors.py`` does.

    The rate-limit branch can only SUBTRACT. §8.7 makes that row conditional on a
    per-connection switch, so a connection with the switch off refuses a fallback the table
    would have allowed; no setting anywhere can admit one the table refuses.
    """
    if not may_switch_chains(deltas_yielded):
        return False
    if not fallback_eligible(error_class, ProviderSurface.CHAT):
        return False
    if error_class is ErrorClass.PROVIDER_RATE_LIMIT:
        return rate_limit_fallback_configured
    return True


def may_retry_same_connection(
    error_class: ErrorClass,
    *,
    deltas_yielded: int,
    attempts_on_link: int,
    budget: int,
) -> bool:
    """Whether the SAME connection may be called again.

    A different question from :func:`may_fall_back` with a different table, and collapsing the
    two into one counter is how a class ends up retried because it was fallback-eligible or
    fallen back from because it was retryable. ``provider_auth`` is neither; ``retrieval`` is
    retryable and has no fallback at all; ``provider_temporary`` is both.
    """
    if not may_switch_chains(deltas_yielded):
        return False
    if not RETRYABLE[error_class]:
        return False
    return attempts_on_link < budget


def link_attempt_budget(error_class: ErrorClass, *, fallback_available: bool) -> int:
    """How many attempts this connection gets for this class."""
    if error_class is ErrorClass.PROVIDER_RATE_LIMIT and fallback_available:
        return RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK
    return MAX_ATTEMPTS_PER_LINK


# ── the router ────────────────────────────────────────────────────────────────


class FallbackRouter:
    """Walks the chain and produces one stream.

    Constructed once and injected (`fastapi-service`); holds nothing per-organization, so one
    instance serves every tenant. The clock, the sleep and the jitter are arguments because a
    test that asserts "abandoned instead of sleeping" has to be able to see that no sleep
    happened, and a test of the backoff ladder has to be able to remove the randomness without
    removing the ladder.
    """

    def __init__(
        self,
        *,
        breaker: Breaker,
        clock: Callable[[], float] = time.monotonic,
        sleep: Callable[[float], Awaitable[None]] = _sleep,
        # `random`, not `secrets`: this is retry jitter, not a security decision. S311 does
        # not fire on a bare reference, only on a call, so there is no `noqa` to carry.
        jitter: Callable[[float, float], float] = random.uniform,
    ) -> None:
        self._breaker = breaker
        self._clock = clock
        self._sleep = sleep
        self._jitter = jitter

    # ── public ────────────────────────────────────────────────────────────────

    async def stream(
        self,
        req: ChatRequest,
        chain: Sequence[ChainLink],
        credentials: Mapping[str, SecretStr],
        *,
        deadline_monotonic: float,
        record: AttemptSink,
    ) -> AsyncIterator[StreamEvent]:
        """Yield the winning attempt's deltas, then exactly one terminal ``ChatResult``.

        ``deadline_monotonic`` is ABSOLUTE, on the same monotonic clock as ``clock`` and
        propagated from the caller — the same value ``app/rag/runner.py::TraceRun`` measures
        ``remaining_seconds`` against. Never a fresh duration: an inner tier that restarts the
        budget pays for completions the outer tier has already abandoned, and the symptom is
        attempts 2 and 3 succeeding in this service's logs after Laravel returned 504.

        ``record`` is required rather than optional. A caller that forgets it gets a
        ``TypeError``, not a silently unaccounted fallback.
        """
        if not chain:
            # Before the first byte, so there is no turn to finalize and no ChatResult to
            # attribute. Matches `validate()`: nothing was sent, nothing was billed.
            raise KbError(
                ErrorClass.VALIDATION,
                "the provider chain is empty, so there is no connection to call",
            )

        index = 0
        ordinal = 0
        attempts_on_link = 0
        deltas_yielded = 0
        pending_wait = 0.0
        pending_floor: int | None = None
        fell_back_from: str | None = None
        fallback_trigger: str | None = None
        terminal: ChatResult | None = None
        cancelled_by_adapter = False

        try:
            while index < len(chain) and terminal is None:
                link = chain[index]
                ordinal += 1
                attempts_on_link += 1

                credential = credentials.get(link.connection_id)
                if credential is None:
                    # Fail closed. Substituting another connection's key would send one
                    # tenant's credential to a vendor they did not choose for this bot.
                    record(
                        self._skip(
                            ordinal,
                            link,
                            AttemptOutcome.SKIPPED_NO_CREDENTIAL,
                            ErrorClass.VALIDATION,
                            fell_back_from,
                            fallback_trigger,
                        )
                    )
                    terminal = self._synthetic(
                        link,
                        ErrorClass.VALIDATION,
                        "no credential in the request envelope for this connection",
                        ordinal,
                    )
                    break

                # Any wait was already checked against the deadline where it was decided, so
                # this sleep cannot overrun it. `waited`/`floor_used` ride onto this attempt's
                # record: the sleep happened before it, so it belongs to it.
                waited = 0.0
                floor_used: int | None = None
                if pending_wait > 0.0:
                    await self._sleep(pending_wait)
                    waited, floor_used = pending_wait, pending_floor
                pending_wait, pending_floor = 0.0, None

                floor = link.adapter.min_useful_seconds
                if deadline_monotonic - self._clock() <= floor:
                    # Never START an attempt that cannot plausibly finish: it bills a
                    # completion the caller has already abandoned.
                    record(
                        self._skip(
                            ordinal,
                            link,
                            AttemptOutcome.SKIPPED_DEADLINE,
                            ErrorClass.PROVIDER_TEMPORARY,
                            fell_back_from,
                            fallback_trigger,
                            waited=waited,
                            floor_used=floor_used,
                        )
                    )
                    terminal = self._synthetic(
                        link,
                        ErrorClass.PROVIDER_TEMPORARY,
                        "deadline exhausted before an attempt could plausibly finish",
                        ordinal,
                    )
                    break

                allow_key = BreakerKey(req.org_id, link.connection_id, link.model)
                if not await self._breaker.allow(allow_key):
                    record(
                        self._skip(
                            ordinal,
                            link,
                            AttemptOutcome.SKIPPED_BREAKER_OPEN,
                            ErrorClass.PROVIDER_TEMPORARY,
                            fell_back_from,
                            fallback_trigger,
                            waited=waited,
                            floor_used=floor_used,
                            breaker_key=str(allow_key),
                        )
                    )
                    # An open breaker IS the "this dependency is sick" signal fallback exists
                    # for, so it moves down the chain on the same terms a 503 would.
                    if index + 1 < len(chain) and may_fall_back(
                        ErrorClass.PROVIDER_TEMPORARY,
                        deltas_yielded=deltas_yielded,
                        rate_limit_fallback_configured=link.fallback_on_rate_limit,
                    ):
                        index += 1
                        attempts_on_link = 0
                        fell_back_from = link.connection_id
                        fallback_trigger = ErrorClass.PROVIDER_TEMPORARY.value
                        continue
                    terminal = self._synthetic(
                        link,
                        ErrorClass.PROVIDER_TEMPORARY,
                        "circuit breaker open for this connection and model",
                        ordinal,
                    )
                    break

                # ── the attempt ───────────────────────────────────────────────
                attempt_request = req.model_copy(
                    # `model_copy`, not a re-validated construction: both values come from the
                    # same already-validated internal envelope this ChatRequest was built from,
                    # so re-validating would be validating our own output.
                    update={"model": link.model, "provider_connection_id": link.connection_id}
                )
                started = self._clock()
                attempt_deltas = 0
                first_token_ms: int | None = None
                result: ChatResult | None = None
                rejection: KbError | None = None

                events = link.adapter.stream(attempt_request, link.caps, credential)
                try:
                    async for event in events:
                        if event.kind == "result":
                            # Discriminated on `kind`, not isinstance: the contract carries the
                            # tag so a consumer can tell "more output" from "the turn is over"
                            # without a ladder that silently accepts a mis-typed event.
                            result = event
                            break
                        attempt_deltas += 1
                        deltas_yielded += 1
                        if first_token_ms is None:
                            first_token_ms = int((self._clock() - started) * 1000)
                        # Forwarded the instant it arrives. Buffering here would pass every
                        # translation test and destroy the number the relay is measuring.
                        yield event
                except KbError as exc:
                    # `validate()` raises out of the generator before the first byte. It is OUR
                    # classification (a capability rejection), so it is never re-read as a
                    # vendor fault and never retried.
                    rejection = exc
                finally:
                    await _close(events)

                latency_ms = int((self._clock() - started) * 1000)

                if rejection is not None:
                    record(
                        ProviderAttempt(
                            ordinal=ordinal,
                            connection_id=link.connection_id,
                            provider=link.adapter.name,
                            model=link.model,
                            # REJECTED means nothing was sent, and `made_request` decides
                            # whether a `provider_calls` row is owed. A KbError that escapes
                            # AFTER deltas have flowed is not that: those tokens were billed,
                            # so the row is owed and the outcome is an error.
                            outcome=(
                                AttemptOutcome.REJECTED
                                if attempt_deltas == 0
                                else AttemptOutcome.ERROR
                            ),
                            error_class=rejection.error_class.value,
                            deltas=attempt_deltas,
                            first_token_ms=first_token_ms,
                            latency_ms=latency_ms,
                            waited_seconds=waited,
                            retry_after_floor_seconds=floor_used,
                            fell_back_from=fell_back_from,
                            fallback_trigger=fallback_trigger,
                        )
                    )
                    terminal = self._synthetic(
                        link, rejection.error_class, rejection.message, ordinal
                    )
                    break

                if result is None:
                    # An adapter that ended without a terminal event has broken the one
                    # contract every consumer depends on. Classify it as ours, not the
                    # vendor's — nothing about the provider failed.
                    record(
                        ProviderAttempt(
                            ordinal=ordinal,
                            connection_id=link.connection_id,
                            provider=link.adapter.name,
                            model=link.model,
                            outcome=AttemptOutcome.ERROR,
                            error_class=ErrorClass.INTERNAL_DEPENDENCY.value,
                            deltas=attempt_deltas,
                            first_token_ms=first_token_ms,
                            latency_ms=latency_ms,
                            waited_seconds=waited,
                            retry_after_floor_seconds=floor_used,
                            fell_back_from=fell_back_from,
                            fallback_trigger=fallback_trigger,
                        )
                    )
                    terminal = self._synthetic(
                        link,
                        ErrorClass.INTERNAL_DEPENDENCY,
                        "the adapter's stream ended without a terminal ChatResult",
                        ordinal,
                    )
                    break

                failure = self._failure_class(result)
                outcome = self._outcome(result, failure)
                fail_key = self._failure_key(req, link, result)
                record(
                    ProviderAttempt(
                        ordinal=ordinal,
                        connection_id=link.connection_id,
                        provider=link.adapter.name,
                        model=link.model,
                        outcome=outcome,
                        error_class=None if failure is None else failure.value,
                        stop_reason=result.stop_reason,
                        provider_request_id=result.provider_request_id,
                        usage=result.usage,
                        deltas=attempt_deltas,
                        # The adapter's own figures where it has them: it starts its clock
                        # before the request goes out and this loop starts one after. `or`
                        # would read a legitimate 0 as absent.
                        first_token_ms=(
                            result.first_token_ms
                            if result.first_token_ms is not None
                            else first_token_ms
                        ),
                        latency_ms=result.total_ms if result.total_ms else latency_ms,
                        waited_seconds=waited,
                        retry_after_floor_seconds=floor_used,
                        fell_back_from=fell_back_from,
                        fallback_trigger=fallback_trigger,
                        breaker_key=str(fail_key) if failure is not None else str(allow_key),
                    )
                )

                if failure is None:
                    await self._breaker.record_success(allow_key)
                    terminal = result
                    break

                # Gated in ONE place so an implementation cannot forget: one tenant's revoked
                # key, bad model id or exhausted balance must not take chat down for everybody.
                if failure in BREAKER_ELIGIBLE:
                    await self._breaker.record_failure(fail_key, failure)

                if outcome is AttemptOutcome.CANCELLED:
                    # The adapter told us, in the contract's own vocabulary, that a
                    # CancelledError reached it. Forward its terminal — it carries the tokens
                    # the vendor already billed — and let the cancellation keep unwinding, or
                    # the caller's task quietly survives its own cancellation.
                    terminal = result
                    cancelled_by_adapter = True
                    break

                falls_back = index + 1 < len(chain) and may_fall_back(
                    failure,
                    deltas_yielded=deltas_yielded,
                    rate_limit_fallback_configured=link.fallback_on_rate_limit,
                )
                budget = link_attempt_budget(failure, fallback_available=falls_back)

                if may_retry_same_connection(
                    failure,
                    deltas_yielded=deltas_yielded,
                    attempts_on_link=attempts_on_link,
                    budget=budget,
                ):
                    wait, used = self._backoff(
                        attempts_on_link, retry_after_seconds(result.diagnostics.rate_limit)
                    )
                    if wait + floor < deadline_monotonic - self._clock():
                        pending_wait, pending_floor = wait, used
                        continue
                    # THE WAIT DOES NOT FIT, SO DO NOT TAKE IT. Sleeping past the deadline
                    # bills a completion nobody is waiting for, and a `Retry-After` longer than
                    # the remaining budget is the common way that happens. The failure keeps
                    # its own class and drops through to the fallback decision below, which is
                    # what makes a configured fallback still able to serve the turn.
                    ordinal += 1
                    record(
                        self._skip(
                            ordinal,
                            link,
                            AttemptOutcome.SKIPPED_DEADLINE,
                            failure,
                            fell_back_from,
                            fallback_trigger,
                            floor_used=used,
                        )
                    )

                if falls_back:
                    index += 1
                    attempts_on_link = 0
                    fell_back_from = link.connection_id
                    fallback_trigger = failure.value
                    # No sleep across a switch of connection. A second vendor's capacity is not
                    # bounded by the first one's reset window, and spending the remaining budget
                    # waiting is the opposite of what falling back is for.
                    continue

                # Nothing left to try: no retry budget, and no eligible or configured next
                # connection. This attempt's own terminal event is the turn's, so the usage,
                # the request id and the class all attribute the call that actually failed.
                terminal = result
                break

            if terminal is None:  # pragma: no cover - unreachable by construction
                # An exhausted chain does NOT arrive here. The last link's failure finds no
                # next link, `falls_back` is False, and the loop assigns that attempt's own
                # terminal event — which is what attributes the answer to the attempt that
                # actually produced it. `index` is only ever advanced when a next link exists,
                # so the loop cannot fall out of its condition either. Reaching this line is a
                # defect of ours and renders as one (ADR-029: SELF origin is 500 and not
                # retryable, because no number of attempts fixes a bug).
                raise KbError(
                    ErrorClass.INTERNAL_DEPENDENCY,
                    "the fallback loop ended without a terminal event",
                    origin=Origin.SELF,
                )
        except asyncio.CancelledError:
            # HERE, AND THEN RE-RAISE. Never from `finally`: yielding while GeneratorExit
            # unwinds raises RuntimeError, ASGI swallows it, and the usage row for a turn the
            # vendor billed in full simply disappears.
            yield self._synthetic(
                chain[min(index, len(chain) - 1)],
                ErrorClass.USER_CANCELLATION,
                "the caller cancelled the turn",
                ordinal,
                stop_reason=StopReason.CANCELLED,
            )
            raise

        yield terminal
        if cancelled_by_adapter:
            # The adapter's terminal has been delivered, so the usage row is safe. Now let the
            # cancellation finish unwinding: swallowing it leaves the caller's task alive after
            # it was cancelled, which asyncio will not warn about.
            raise asyncio.CancelledError

    # ── internals ─────────────────────────────────────────────────────────────

    def _backoff(self, attempt: int, hint: int | None) -> tuple[float, int | None]:
        """Full jitter with the vendor's reset as a floor.

        ``attempt`` is 1-based, so the first retry draws from ``[0, base]``. The hint is a floor
        and never the sleep itself: obeying a bare hint synchronizes every caller onto one
        instant and rebuilds the burst that produced the limit.
        """
        ceiling = min(BACKOFF_CAP_SECONDS, BACKOFF_BASE_SECONDS * 2 ** (attempt - 1))
        jittered = self._jitter(0.0, ceiling)
        if hint is None:
            return jittered, None
        return max(float(hint), jittered), hint

    def _failure_class(self, result: ChatResult) -> ErrorClass | None:
        """The terminal event's class, or None when the turn succeeded.

        A refusal is a success here and that is deliberate: it arrives as a 200, it is billed,
        and the taxonomy has no class for it. It is terminal because ``StopReason.REFUSAL`` is
        terminal, not because anything failed — an adapter that filed it as a fault would have
        the router re-ask a banned question on a second vendor and pay for it twice.
        """
        raw = result.error_class
        if raw is None:
            if result.stop_reason is StopReason.ERROR:
                # An error with no class is unmapped, and unmapped is PERMANENT: the cost of
                # wrongly-permanent is one visible failure, the cost of wrongly-temporary is an
                # unbounded queue.
                return UNMAPPED
            return None
        try:
            return ErrorClass(raw)
        except ValueError:
            return UNMAPPED

    def _outcome(self, result: ChatResult, failure: ErrorClass | None) -> AttemptOutcome:
        if failure is None:
            return AttemptOutcome.SUCCESS
        if failure is ErrorClass.USER_CANCELLATION or result.stop_reason is StopReason.CANCELLED:
            return AttemptOutcome.CANCELLED
        return AttemptOutcome.ERROR

    def _failure_key(self, req: ChatRequest, link: ChainLink, result: ChatResult) -> BreakerKey:
        """Compose the key this failure is recorded under.

        Reads two allow-listed ``Diagnostics.extras`` keys and nothing else. Four of the five
        adapters set neither, so they take the ``model`` scope by omission and this function
        names no vendor — which is the point. A gateway that attributed the failure to one of
        its upstreams gets a fourth key term, and one that owns the failure itself gets the
        account-wide scope, because a 402 or a revoked key is a property of the credential and
        not of any model riding on it.
        """
        extras = result.diagnostics.extras
        scope = extras.get(_FAILURE_SCOPE_KEY)
        if scope == "upstream":
            slug = extras.get(_UPSTREAM_SLUG_KEY) or result.diagnostics.served_by
            if isinstance(slug, str) and slug:
                return BreakerKey(
                    req.org_id, link.connection_id, link.model, scope="upstream", upstream=slug
                )
        if scope == "gateway":
            return BreakerKey(req.org_id, link.connection_id, link.model, scope="gateway")
        return BreakerKey(req.org_id, link.connection_id, link.model)

    def _skip(
        self,
        ordinal: int,
        link: ChainLink,
        outcome: AttemptOutcome,
        error_class: ErrorClass,
        fell_back_from: str | None,
        fallback_trigger: str | None,
        *,
        waited: float = 0.0,
        floor_used: int | None = None,
        breaker_key: str | None = None,
    ) -> ProviderAttempt:
        """A record for an attempt that never reached the vendor."""
        return ProviderAttempt(
            ordinal=ordinal,
            connection_id=link.connection_id,
            provider=link.adapter.name,
            model=link.model,
            outcome=outcome,
            error_class=error_class.value,
            waited_seconds=waited,
            retry_after_floor_seconds=floor_used,
            fell_back_from=fell_back_from,
            fallback_trigger=fallback_trigger,
            breaker_key=breaker_key,
        )

    def _synthetic(
        self,
        link: ChainLink,
        error_class: ErrorClass,
        detail: str,
        attempts: int,
        *,
        stop_reason: StopReason = StopReason.ERROR,
    ) -> ChatResult:
        """The terminal event for a turn no adapter finished.

        ``Diagnostics.provider`` borrows the vendor name of the link that was in view, never a
        sixth value like ``"router"``: that field feeds ``gen_ai.provider.name`` and the
        ``provider`` metric label, whose value set is the five adapters. ``usage`` is zeros with
        ``source="estimated"`` because nothing was generated — and estimated rows are never
        aggregated into invoiced cost.

        ``detail`` is our own wording about our own decision. It never carries vendor error
        text, which routinely echoes the request, which carries the packed prompt.
        """
        return ChatResult(
            text="",
            stop_reason=stop_reason,
            usage=Usage(source="estimated"),
            total_ms=0,
            error_class=error_class.value,
            diagnostics=Diagnostics(
                provider=link.adapter.name,
                extras={"router_reason": detail, "router_attempts": attempts},
            ),
        )

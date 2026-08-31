"""The fallback router: which failures move down the chain, and which never can.

Nothing here touches a network. The five wire adapters are replaced by
:class:`ScriptedAdapter`, an in-process async generator that plays a list of
:class:`Step`\\ s — one per attempt — in the shape the real adapters produce: deltas arriving
separately over the loop, then exactly one terminal ``ChatResult`` carrying the class.

**The `unit/` README says this tier may not assert "anything about a stream", and that rule is
about TRANSPORT.** There is none here. Every assertion below is about *policy* — which attempt
was made, in what order, after what wait — plus one about ordering inside the generator
(``test_a_delta_reaches_the_consumer_before_the_next_one_is_produced``), which is a property of
this module's control flow rather than of any wire.

Five failures shape the file, and every one of them is silent in production:

* **Duplicated text in the middle of an answer.** The router fell back after a delta had
  already left it. The first provider was billed, the user read the partial answer, and the
  second provider then wrote a *complete* one after it. Every hop returns 200 and no metric
  moves. This is what the ``deltas_yielded`` gate exists for and why it is a named function.
* **A missing usage row for a cancelled turn.** The terminal event was emitted from ``finally``,
  ``RuntimeError`` was raised during ``GeneratorExit``, ASGI swallowed it, and the vendor billed
  in full for a turn with no accounting anywhere.
* **A tenant's mistyped model id silently answered by a different model.** A class the table
  refuses was allowed to fall back. The bot stays ``Ready``, every turn is served by a model the
  tenant never chose, and the admin finds out from an invoice.
* **A 429 rate pinned at 100% long after traffic drops.** The loop slept for a reset window it
  did not have time for, or re-issued inside one it did.
* **One tenant's bad key taking chat down for everybody.** A caller-fault class was counted
  toward the circuit breaker.

The eligibility assertions are parametrized over ``ErrorClass`` itself and compared against
``FALLBACK_ELIGIBLE`` / ``RETRYABLE``, never against a list typed out here. A hand list would
pass forever after the table moved, which is the exact failure the "never restate the
no-fallback list" rule is about.
"""

from __future__ import annotations

import ast
import asyncio
import pathlib
from collections.abc import AsyncIterator, Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any, Final

import pytest
from pydantic import SecretStr

from app.core.errors import FALLBACK_ELIGIBLE, RETRYABLE, ErrorClass, KbError
from app.providers import router as router_module
from app.providers.contract import (
    Capability,
    ChatRequest,
    ChatResult,
    Delta,
    Diagnostics,
    Message,
    ModelCapabilities,
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import BREAKER_ELIGIBLE, ProviderSurface, fallback_eligible
from app.providers.router import (
    MAX_ATTEMPTS_PER_LINK,
    RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK,
    AttemptOutcome,
    BreakerKey,
    ChainLink,
    FallbackRouter,
    NullBreaker,
    ProviderAttempt,
    link_attempt_budget,
    may_fall_back,
    may_retry_same_connection,
    may_switch_chains,
)

ORG: Final = "01JQZ0000000000000000000AA"
BOT: Final = "01JQZ0000000000000000000BT"
CONN_A: Final = "01JQZ0000000000000000000A1"
CONN_B: Final = "01JQZ0000000000000000000B2"
MODEL_A: Final = "gpt-5.6-2026-07-15"
MODEL_B: Final = "claude-sonnet-5-20260514"

KEY_A: Final = SecretStr("sk-primary-not-a-real-key")
KEY_B: Final = SecretStr("sk-fallback-not-a-real-key")
CREDENTIALS: Final[Mapping[str, SecretStr]] = {CONN_A: KEY_A, CONN_B: KEY_B}

CHAT_FLAGS: Final = frozenset({Capability.TEXT, Capability.STREAM_USAGE})

#: Every class whose fallback verdict is True, read off the table rather than typed out.
ELIGIBLE: Final = tuple(c for c in ErrorClass if FALLBACK_ELIGIBLE[c])
ALL_CLASSES: Final = tuple(ErrorClass)


def _class_id(error_class: ErrorClass) -> str:
    return error_class.value


# ── the doubles ───────────────────────────────────────────────────────────────


@dataclass
class Step:
    """One scripted attempt against one adapter."""

    deltas: tuple[str, ...] = ()
    error_class: ErrorClass | None = None
    stop_reason: StopReason | None = None
    #: Wire headers the adapter parsed, as ``Diagnostics.rate_limit`` carries them.
    rate_limit: Mapping[str, str] = field(default_factory=dict)
    extras: Mapping[str, Any] = field(default_factory=dict)
    #: ``validate()`` refuses before the first byte and raises out of the generator.
    reject: bool = False
    #: Block after the deltas until the test releases it — the seam a real cancellation
    #: arrives through.
    gate: asyncio.Event | None = None
    request_id: str = "req-scripted"


class ScriptedAdapter:
    """Satisfies ``ProviderAdapter`` without a vendor SDK.

    Steps are consumed one per call and the LAST one repeats, so a link that always fails needs
    one entry rather than three — and a test that expected two attempts and got four fails on
    ``calls`` rather than on an IndexError from the double.
    """

    min_useful_seconds = 5.0

    def __init__(self, name: str, *steps: Step) -> None:
        self.name = name
        self._steps: list[Step] = list(steps) or [Step(deltas=("ok",))]
        self.calls: list[tuple[str, str]] = []
        self.credentials: list[SecretStr] = []

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[Any]:
        return []

    async def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        step = self._steps[min(len(self.calls), len(self._steps) - 1)]
        self.calls.append((req.model, req.provider_connection_id))
        self.credentials.append(credential)

        if step.reject:
            # Before the first byte: nothing sent, nothing billed, no turn to finalize.
            raise KbError(ErrorClass.VALIDATION, "this model row rejects response_schema")

        emitted: list[str] = []
        try:
            for text in step.deltas:
                # Separately, over the loop. A single-chunk fixture passes for a buffered
                # implementation, which is the bug the fixture exists to catch.
                await asyncio.sleep(0)
                emitted.append(text)
                yield Delta(kind="text", text=text)
            if step.gate is not None:
                await step.gate.wait()
        except asyncio.CancelledError:
            # HERE, then re-raise — never from `finally`. Mirrors all five real adapters.
            yield self._terminal(
                step,
                emitted,
                StopReason.CANCELLED,
                ErrorClass.USER_CANCELLATION.value,
                Usage(output_tokens=len(emitted), source="estimated"),
            )
            raise

        stop = step.stop_reason or (
            StopReason.ERROR if step.error_class is not None else StopReason.COMPLETE
        )
        usage = (
            Usage(input_tokens=11, output_tokens=len(emitted), source="provider_final")
            if step.error_class is None
            else Usage(source="estimated")
        )
        yield self._terminal(
            step,
            emitted,
            stop,
            None if step.error_class is None else step.error_class.value,
            usage,
        )

    def _terminal(
        self,
        step: Step,
        emitted: Sequence[str],
        stop: StopReason,
        error_class: str | None,
        usage: Usage,
    ) -> ChatResult:
        return ChatResult(
            text="".join(emitted),
            stop_reason=stop,
            usage=usage,
            provider_request_id=f"{step.request_id}-{self.name}",
            first_token_ms=12 if emitted else None,
            total_ms=34,
            error_class=error_class,
            diagnostics=Diagnostics(
                provider=self.name,
                rate_limit=dict(step.rate_limit),
                extras=dict(step.extras),
            ),
        )


class RecordingBreaker:
    """Satisfies ``Breaker``. Denies by rendered key, so a test names the same string the
    router composes rather than a second copy of the composition rule."""

    def __init__(self, *, denied: Sequence[str] = ()) -> None:
        self.denied = set(denied)
        self.asked: list[str] = []
        self.successes: list[str] = []
        self.failures: list[tuple[str, ErrorClass]] = []

    async def allow(self, key: BreakerKey) -> bool:
        self.asked.append(str(key))
        return str(key) not in self.denied

    async def record_success(self, key: BreakerKey) -> None:
        self.successes.append(str(key))

    async def record_failure(self, key: BreakerKey, error_class: ErrorClass) -> None:
        self.failures.append((str(key), error_class))


class Clock:
    """Monotonic and manual. A real clock makes "abandoned instead of sleeping" untestable,
    because the assertion then depends on which side of a second the test ran."""

    def __init__(self, start: float = 1_000.0) -> None:
        self.now = start

    def __call__(self) -> float:
        return self.now


class Sleeper:
    """Records what would have been slept and advances the clock by it. A test that asserts
    ``slept == []`` is asserting the loop never waited, which no wall clock can show."""

    def __init__(self, clock: Clock) -> None:
        self._clock = clock
        self.slept: list[float] = []

    async def __call__(self, seconds: float) -> None:
        self.slept.append(seconds)
        self._clock.now += seconds


# ── harness ───────────────────────────────────────────────────────────────────


def caps_for() -> ModelCapabilities:
    return ModelCapabilities(supported=CHAT_FLAGS, context_window=200_000, max_output_tokens=8192)


def request_for(**overrides: Any) -> ChatRequest:
    body: dict[str, Any] = {
        "org_id": ORG,
        "bot_id": BOT,
        "trace_id": "trace-router",
        "provider_connection_id": CONN_A,
        "model": MODEL_A,
        "system": "Answer only from the evidence provided.",
        "messages": [Message(role="user", content="How long is the refund window?")],
        "max_output_tokens": 1024,
    }
    body.update(overrides)
    return ChatRequest(**body)


def link(
    connection_id: str,
    model: str,
    adapter: ScriptedAdapter,
    *,
    fallback_on_rate_limit: bool = False,
) -> ChainLink:
    return ChainLink(
        connection_id=connection_id,
        model=model,
        adapter=adapter,
        caps=caps_for(),
        fallback_on_rate_limit=fallback_on_rate_limit,
    )


@dataclass
class Run:
    events: list[Any]
    records: list[ProviderAttempt]
    cancelled: bool
    breaker: RecordingBreaker
    clock: Clock
    sleeper: Sleeper

    @property
    def deltas(self) -> list[Any]:
        return [e for e in self.events if e.kind != "result"]

    @property
    def text(self) -> str:
        return "".join(d.text for d in self.deltas)

    @property
    def terminal(self) -> ChatResult:
        """Exactly one terminal, on every path — asserted here so every test carries it."""
        terminals = [e for e in self.events if e.kind == "result"]
        assert len(terminals) == 1, f"expected one terminal ChatResult, got {len(terminals)}"
        return terminals[0]

    @property
    def outcomes(self) -> list[AttemptOutcome]:
        return [r.outcome for r in self.records]


async def run_chain(
    chain: Sequence[ChainLink],
    *,
    budget: float = 60.0,
    credentials: Mapping[str, SecretStr] | None = None,
    breaker: RecordingBreaker | None = None,
    jitter: float | None = 0.0,
) -> Run:
    clock = Clock()
    sleeper = Sleeper(clock)
    breaker = breaker if breaker is not None else RecordingBreaker()
    router = FallbackRouter(
        breaker=breaker,
        clock=clock,
        sleep=sleeper,
        # A fixed draw, not a fixed seed: the ladder's ceiling is asserted separately, and a
        # seeded RNG makes every other assertion depend on the draw order.
        jitter=(lambda _a, b: b) if jitter is None else (lambda _a, _b: jitter),
    )
    events: list[Any] = []
    records: list[ProviderAttempt] = []
    cancelled = False
    try:
        async for event in router.stream(
            request_for(),
            chain,
            CREDENTIALS if credentials is None else credentials,
            deadline_monotonic=clock.now + budget,
            record=records.append,
        ):
            events.append(event)
    except asyncio.CancelledError:
        # Caught rather than allowed to escape: several parametrized cases below drive the
        # user_cancellation row, and the re-raise is the behaviour under test, not an error.
        cancelled = True
    return Run(events, records, cancelled, breaker, clock, sleeper)


# ── the gate: no fallback once a delta has left the router ────────────────────


def test_may_switch_chains_closes_on_the_first_delta() -> None:
    assert may_switch_chains(0) is True
    assert may_switch_chains(1) is False
    assert may_switch_chains(500) is False


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
def test_a_delta_closes_both_decisions_for_every_class(error_class: ErrorClass) -> None:
    """No class survives the first delta, including the two that are otherwise eligible.

    Once a token is out the provider has been billed and the user has read it. A second
    attempt does not replace that text, it appends a whole answer after it.
    """
    assert (
        may_fall_back(error_class, deltas_yielded=1, rate_limit_fallback_configured=True) is False
    )
    assert (
        may_retry_same_connection(
            error_class, deltas_yielded=1, attempts_on_link=1, budget=MAX_ATTEMPTS_PER_LINK
        )
        is False
    )


@pytest.mark.parametrize("error_class", ELIGIBLE, ids=_class_id)
async def test_no_fallback_after_the_first_delta(error_class: ErrorClass) -> None:
    primary = ScriptedAdapter(
        "openai", Step(deltas=("The refund window is ",), error_class=error_class)
    )
    fallback = ScriptedAdapter("anthropic", Step(deltas=("thirty days from delivery.",)))
    result = await run_chain(
        [
            link(CONN_A, MODEL_A, primary, fallback_on_rate_limit=True),
            link(CONN_B, MODEL_B, fallback),
        ]
    )

    assert fallback.calls == [], "fell back after a delta had already been sent"
    assert len(primary.calls) == 1, "retried the same connection after a delta had been sent"
    # The whole point: the user's answer is the partial text and nothing after it.
    assert result.text == "The refund window is "
    assert result.terminal.error_class == error_class.value


async def test_a_retryable_failure_after_a_delta_does_not_duplicate_text() -> None:
    """The named property, spelled out: this is what a broken gate looks like on screen."""
    primary = ScriptedAdapter(
        "openai",
        Step(deltas=("The refund window is ",), error_class=ErrorClass.PROVIDER_TEMPORARY),
        Step(deltas=("The refund window is thirty days.",)),
    )
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert result.text == "The refund window is "
    assert "The refund window is thirty days." not in result.text
    assert len(primary.calls) == 1


# ── the eligibility gate, per class, off the taxonomy ─────────────────────────


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
def test_may_fall_back_is_the_table_and_nothing_else(error_class: ErrorClass) -> None:
    assert (
        may_fall_back(error_class, deltas_yielded=0, rate_limit_fallback_configured=True)
        is FALLBACK_ELIGIBLE[error_class]
    )


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
def test_the_rate_limit_switch_can_only_subtract(error_class: ErrorClass) -> None:
    """§8.7's per-connection switch narrows the table; it can never widen it."""
    off = may_fall_back(error_class, deltas_yielded=0, rate_limit_fallback_configured=False)
    on = may_fall_back(error_class, deltas_yielded=0, rate_limit_fallback_configured=True)
    assert not (off and not on)
    assert off is (
        FALLBACK_ELIGIBLE[error_class] and error_class is not ErrorClass.PROVIDER_RATE_LIMIT
    )


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
def test_may_retry_is_the_retry_table_and_not_the_fallback_one(
    error_class: ErrorClass,
) -> None:
    assert (
        may_retry_same_connection(
            error_class, deltas_yielded=0, attempts_on_link=1, budget=MAX_ATTEMPTS_PER_LINK
        )
        is RETRYABLE[error_class]
    )
    assert (
        may_retry_same_connection(
            error_class,
            deltas_yielded=0,
            attempts_on_link=MAX_ATTEMPTS_PER_LINK,
            budget=MAX_ATTEMPTS_PER_LINK,
        )
        is False
    )


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
async def test_the_chain_advances_exactly_when_the_table_says_so(
    error_class: ErrorClass,
) -> None:
    primary = ScriptedAdapter("openai", Step(error_class=error_class))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("served by the second connection",)))
    result = await run_chain(
        [
            # The switch is ON, so the rate-limit row reads as the table's True rather than as
            # the default-off narrowing — which is asserted separately.
            link(CONN_A, MODEL_A, primary, fallback_on_rate_limit=True),
            link(CONN_B, MODEL_B, fallback),
        ]
    )

    assert bool(fallback.calls) is FALLBACK_ELIGIBLE[error_class]
    if FALLBACK_ELIGIBLE[error_class]:
        assert result.terminal.diagnostics.provider == "anthropic"
        assert result.records[-1].fell_back_from == CONN_A
        assert result.records[-1].fallback_trigger == error_class.value
    else:
        assert result.terminal.diagnostics.provider == "openai"
        assert all(r.fell_back_from is None for r in result.records)


async def test_a_rate_limit_does_not_fall_back_unless_the_connection_says_so() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_RATE_LIMIT))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("second",)))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert fallback.calls == []
    assert len(primary.calls) == MAX_ATTEMPTS_PER_LINK
    assert result.terminal.error_class == ErrorClass.PROVIDER_RATE_LIMIT.value


async def test_a_rate_limit_retries_once_then_falls_back() -> None:
    """§19.2 permits the retry and §8.7 permits the fallback; running both ladders in series
    doubles latency past the first-token target for no gain."""
    assert link_attempt_budget(ErrorClass.PROVIDER_RATE_LIMIT, fallback_available=True) == (
        RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK
    )
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_RATE_LIMIT))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("second",)))
    result = await run_chain(
        [
            link(CONN_A, MODEL_A, primary, fallback_on_rate_limit=True),
            link(CONN_B, MODEL_B, fallback),
        ]
    )

    assert len(primary.calls) == RATE_LIMIT_ATTEMPTS_BEFORE_FALLBACK == 2
    assert len(fallback.calls) == 1
    assert result.terminal.error_class is None


async def test_a_refusal_is_terminal_and_never_falls_back() -> None:
    """A refusal arrives as HTTP 200 with no error class. Falling back re-asks a banned
    question on a second vendor and bills for it twice."""
    primary = ScriptedAdapter("openai", Step(stop_reason=StopReason.REFUSAL))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("an answer to a banned question",)))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert fallback.calls == []
    assert result.terminal.stop_reason is StopReason.REFUSAL
    assert result.terminal.error_class is None
    assert result.outcomes == [AttemptOutcome.SUCCESS]
    # A 200 from a healthy dependency closes the breaker, whatever the model decided to say.
    assert result.breaker.successes == [f"{ORG}:{CONN_A}:{MODEL_A}"]


# ── exactly one terminal, on every path ───────────────────────────────────────


async def test_one_terminal_on_success() -> None:
    primary = ScriptedAdapter("openai", Step(deltas=("thirty ", "days")))
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert result.text == "thirty days"
    assert result.terminal.stop_reason is StopReason.COMPLETE
    assert result.terminal.usage.source == "provider_final"
    assert result.outcomes == [AttemptOutcome.SUCCESS]


async def test_one_terminal_when_the_whole_chain_is_exhausted() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    fallback = ScriptedAdapter("anthropic", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert len(primary.calls) == MAX_ATTEMPTS_PER_LINK
    assert len(fallback.calls) == MAX_ATTEMPTS_PER_LINK
    assert len(result.records) == 2 * MAX_ATTEMPTS_PER_LINK
    # The terminal attributes the attempt that actually failed last, not a synthesized one.
    assert result.terminal.diagnostics.provider == "anthropic"
    assert result.terminal.provider_request_id == "req-scripted-anthropic"
    assert result.terminal.error_class == ErrorClass.PROVIDER_TEMPORARY.value


async def test_one_terminal_when_the_adapter_reports_a_cancellation() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.USER_CANCELLATION))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("nobody is waiting for this",)))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert result.cancelled is True, "a cancellation must keep unwinding, not be swallowed"
    assert fallback.calls == []
    assert result.terminal.error_class == ErrorClass.USER_CANCELLATION.value
    assert result.outcomes == [AttemptOutcome.CANCELLED]
    assert result.records[0].made_request is True


async def test_one_terminal_when_the_turn_is_cancelled_mid_stream() -> None:
    """A real ``task.cancel()`` while the adapter is mid-answer.

    The terminal must be delivered *before* the cancellation finishes unwinding — the vendor
    already billed for the deltas — and the ``CancelledError`` must still reach the caller, or
    a cancelled task quietly survives its own cancellation.
    """
    gate = asyncio.Event()
    primary = ScriptedAdapter("openai", Step(deltas=("The refund ", "window "), gate=gate))
    clock = Clock()
    router = FallbackRouter(
        breaker=NullBreaker(), clock=clock, sleep=Sleeper(clock), jitter=lambda _a, _b: 0.0
    )
    seen: list[Any] = []
    records: list[ProviderAttempt] = []

    async def consume() -> None:
        async for event in router.stream(
            request_for(),
            [link(CONN_A, MODEL_A, primary)],
            CREDENTIALS,
            deadline_monotonic=clock.now + 60.0,
            record=records.append,
        ):
            seen.append(event)

    task = asyncio.create_task(consume())
    for _ in range(20):
        await asyncio.sleep(0)
        if len(seen) == 2:
            break
    assert [e.text for e in seen] == ["The refund ", "window "]

    task.cancel()
    with pytest.raises(asyncio.CancelledError):
        await task

    terminals = [e for e in seen if e.kind == "result"]
    assert len(terminals) == 1
    assert terminals[0].stop_reason is StopReason.CANCELLED
    assert terminals[0].error_class == ErrorClass.USER_CANCELLATION.value
    # Cancelled turns still bill, so the record must exist and must be marked estimated —
    # estimated rows are never aggregated into invoiced cost.
    assert [r.outcome for r in records] == [AttemptOutcome.CANCELLED]
    assert records[0].usage.source == "estimated"
    assert records[0].deltas == 2


async def test_the_terminal_is_never_emitted_from_finally() -> None:
    """``aclose()`` mid-stream must not produce a terminal event.

    Yielding while ``GeneratorExit`` unwinds raises ``RuntimeError``, ASGI swallows it, and the
    symptom is a usage row that silently disappears. The correct behaviour is that the
    generator closes cleanly and emits nothing — which is why the terminal lives in
    ``except asyncio.CancelledError`` and not in ``finally``.
    """
    gate = asyncio.Event()
    primary = ScriptedAdapter("openai", Step(deltas=("partial",), gate=gate))
    clock = Clock()
    router = FallbackRouter(
        breaker=NullBreaker(), clock=clock, sleep=Sleeper(clock), jitter=lambda _a, _b: 0.0
    )
    records: list[ProviderAttempt] = []
    events = router.stream(
        request_for(),
        [link(CONN_A, MODEL_A, primary)],
        CREDENTIALS,
        deadline_monotonic=clock.now + 60.0,
        record=records.append,
    )

    # Every `anext` in this file reachable while the adapter is blocked carries a timeout. A
    # buffered router does not FAIL these assertions, it DEADLOCKS on the gate — and a hanging
    # suite is a worse signal than a red one, as this file found out by hanging.
    first = await asyncio.wait_for(anext(events), timeout=2.0)
    # `kind`, not just the text: a buffered router that is cancelled by `wait_for` yields its
    # terminal event, whose `text` is the same string, and the assertion passes by accident.
    assert first.kind == "text"
    assert first.text == "partial"
    await events.aclose()  # must not raise RuntimeError


async def test_a_delta_reaches_the_consumer_before_the_next_one_is_produced() -> None:
    """The anti-buffering fixture: the adapter is BLOCKED when the first delta is consumed.

    A buffered implementation deadlocks here rather than failing an assertion, because the gate
    is only released after the first delta has been received.
    """
    gate = asyncio.Event()
    primary = ScriptedAdapter("openai", Step(deltas=("first ",), gate=gate))
    clock = Clock()
    router = FallbackRouter(
        breaker=NullBreaker(), clock=clock, sleep=Sleeper(clock), jitter=lambda _a, _b: 0.0
    )
    records: list[ProviderAttempt] = []
    events = router.stream(
        request_for(),
        [link(CONN_A, MODEL_A, primary)],
        CREDENTIALS,
        deadline_monotonic=clock.now + 60.0,
        record=records.append,
    )

    first = await asyncio.wait_for(anext(events), timeout=2.0)
    assert first.kind == "text"
    assert first.text == "first "
    assert not gate.is_set(), "the adapter is still blocked, so nothing was buffered"
    assert records == [], "no attempt has finished, so nothing may be accounted yet"

    gate.set()
    terminal = await asyncio.wait_for(anext(events), timeout=2.0)
    assert terminal.kind == "result"
    with pytest.raises(StopAsyncIteration):
        await anext(events)


# ── deadline ──────────────────────────────────────────────────────────────────


async def test_retry_after_is_honoured_as_a_floor_under_the_jitter() -> None:
    primary = ScriptedAdapter(
        "openai",
        Step(error_class=ErrorClass.PROVIDER_RATE_LIMIT, rate_limit={"retry-after": "4"}),
        Step(deltas=("recovered",)),
    )
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert result.sleeper.slept == [4.0]
    second = result.records[1]
    assert second.waited_seconds == 4.0
    assert second.retry_after_floor_seconds == 4
    assert second.retry_after_honoured is True
    # The first attempt waited for nothing and honoured nothing — and "nothing was said" is
    # not the same answer as "zero".
    assert result.records[0].retry_after_floor_seconds is None
    assert result.records[0].retry_after_honoured is False


async def test_backoff_without_a_vendor_hint_climbs_the_capped_ladder() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    result = await run_chain([link(CONN_A, MODEL_A, primary)], jitter=None)

    # jitter=None draws the ceiling: base * 2**(attempt-1), capped.
    assert result.sleeper.slept == [0.5, 1.0]
    assert len(primary.calls) == MAX_ATTEMPTS_PER_LINK


async def test_retry_after_longer_than_the_budget_abandons_instead_of_sleeping() -> None:
    primary = ScriptedAdapter(
        "openai",
        Step(error_class=ErrorClass.PROVIDER_RATE_LIMIT, rate_limit={"retry-after": "600"}),
    )
    result = await run_chain([link(CONN_A, MODEL_A, primary)], budget=10.0)

    assert result.sleeper.slept == [], "slept past a deadline the caller had already spent"
    assert len(primary.calls) == 1
    assert result.outcomes == [AttemptOutcome.ERROR, AttemptOutcome.SKIPPED_DEADLINE]
    assert result.records[1].made_request is False
    assert result.records[1].retry_after_floor_seconds == 600
    # The turn keeps the class the vendor actually produced, so the client still sees a 429
    # with the vendor's own reset rather than a manufactured 503.
    assert result.terminal.error_class == ErrorClass.PROVIDER_RATE_LIMIT.value


async def test_an_unaffordable_wait_still_falls_back_when_one_is_configured() -> None:
    """Abandoning the *wait* is not abandoning the turn. A second vendor is not inside the
    first one's reset window, so it can still answer inside the remaining budget."""
    primary = ScriptedAdapter(
        "openai",
        Step(error_class=ErrorClass.PROVIDER_RATE_LIMIT, rate_limit={"retry-after": "600"}),
    )
    fallback = ScriptedAdapter("anthropic", Step(deltas=("served in time",)))
    result = await run_chain(
        [
            link(CONN_A, MODEL_A, primary, fallback_on_rate_limit=True),
            link(CONN_B, MODEL_B, fallback),
        ],
        budget=10.0,
    )

    assert result.sleeper.slept == []
    assert len(fallback.calls) == 1
    assert result.text == "served in time"
    assert result.outcomes == [
        AttemptOutcome.ERROR,
        AttemptOutcome.SKIPPED_DEADLINE,
        AttemptOutcome.SUCCESS,
    ]


async def test_an_attempt_that_cannot_finish_is_never_started() -> None:
    primary = ScriptedAdapter("openai", Step(deltas=("never sent",)))
    # Below `min_useful_seconds`: starting bills a completion the caller has abandoned.
    result = await run_chain([link(CONN_A, MODEL_A, primary)], budget=1.0)

    assert primary.calls == []
    assert result.outcomes == [AttemptOutcome.SKIPPED_DEADLINE]
    assert result.records[0].made_request is False
    assert result.terminal.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.terminal.diagnostics.provider == "openai"


# ── circuit breaker ───────────────────────────────────────────────────────────


async def test_an_open_breaker_skips_the_connection_and_falls_back() -> None:
    primary = ScriptedAdapter("openai", Step(deltas=("never sent",)))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("served by the second",)))
    breaker = RecordingBreaker(denied=[f"{ORG}:{CONN_A}:{MODEL_A}"])
    result = await run_chain(
        [link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)], breaker=breaker
    )

    assert primary.calls == [], "sent to a connection whose breaker was open"
    assert len(fallback.calls) == 1
    assert result.outcomes == [AttemptOutcome.SKIPPED_BREAKER_OPEN, AttemptOutcome.SUCCESS]
    assert result.records[0].breaker_key == f"{ORG}:{CONN_A}:{MODEL_A}"
    assert result.records[1].fell_back_from == CONN_A
    assert result.text == "served by the second"


async def test_an_open_breaker_with_nowhere_to_go_is_terminal() -> None:
    primary = ScriptedAdapter("openai", Step(deltas=("never sent",)))
    breaker = RecordingBreaker(denied=[f"{ORG}:{CONN_A}:{MODEL_A}"])
    result = await run_chain([link(CONN_A, MODEL_A, primary)], breaker=breaker)

    assert primary.calls == []
    assert result.terminal.error_class == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.outcomes == [AttemptOutcome.SKIPPED_BREAKER_OPEN]


@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
async def test_only_breaker_eligible_classes_reach_the_breaker(
    error_class: ErrorClass,
) -> None:
    """One tenant's revoked key, bad model id or exhausted balance must not take chat down for
    every tenant. Excluding the caller-fault classes is what makes the breaker mean "the
    dependency is sick"."""
    primary = ScriptedAdapter("openai", Step(error_class=error_class))
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert bool(result.breaker.failures) is (error_class in BREAKER_ELIGIBLE)
    assert result.breaker.successes == []
    if result.breaker.failures:
        assert {c for _, c in result.breaker.failures} == {error_class}


async def test_a_gateway_attributed_failure_is_recorded_on_the_account_key() -> None:
    """The router reads two allow-listed ``extras`` keys and names no vendor.

    Pre-call gating is upstream-blind by construction — you cannot know who will serve a
    request before you send it — so ``allow()`` is always asked the model-scoped key while the
    failure is recorded on the scope the adapter attributed it to.
    """
    primary = ScriptedAdapter(
        "openai",
        Step(error_class=ErrorClass.PROVIDER_TEMPORARY, extras={"failure_scope": "gateway"}),
    )
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert result.breaker.asked == [f"{ORG}:{CONN_A}:{MODEL_A}"] * MAX_ATTEMPTS_PER_LINK
    assert {key for key, _ in result.breaker.failures} == {f"{ORG}:{CONN_A}:_gateway"}


async def test_an_upstream_attributed_failure_keys_on_the_upstream() -> None:
    primary = ScriptedAdapter(
        "openai",
        Step(
            error_class=ErrorClass.PROVIDER_TEMPORARY,
            extras={"failure_scope": "upstream", "upstream_slug": "together"},
        ),
    )
    result = await run_chain([link(CONN_A, MODEL_A, primary)])

    assert {key for key, _ in result.breaker.failures} == {f"{ORG}:{CONN_A}:{MODEL_A}:together"}


def test_the_breaker_key_renders_each_scope_distinctly() -> None:
    base = BreakerKey(ORG, CONN_A, MODEL_A)
    assert str(base) == f"{ORG}:{CONN_A}:{MODEL_A}"
    assert str(BreakerKey(ORG, CONN_A, MODEL_A, scope="gateway")) == f"{ORG}:{CONN_A}:_gateway"
    assert (
        str(BreakerKey(ORG, CONN_A, MODEL_A, scope="upstream", upstream="fireworks"))
        == f"{ORG}:{CONN_A}:{MODEL_A}:fireworks"
    )


# ── the per-attempt record set ────────────────────────────────────────────────


async def test_the_records_match_the_attempts_actually_made() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("thirty ", "days")))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert len(primary.calls) == MAX_ATTEMPTS_PER_LINK
    assert len(fallback.calls) == 1
    assert len(result.records) == MAX_ATTEMPTS_PER_LINK + 1

    # One ordinal per attempt, contiguous across the whole turn — a gap is a dropped record.
    assert [r.ordinal for r in result.records] == list(range(1, len(result.records) + 1))
    assert [r.provider for r in result.records] == ["openai"] * 3 + ["anthropic"]
    assert [r.connection_id for r in result.records] == [CONN_A] * 3 + [CONN_B]
    assert [r.model for r in result.records] == [MODEL_A] * 3 + [MODEL_B]
    assert all(r.made_request for r in result.records)

    # A retry is NOT a fallback. Counting one as the other reports a model change that never
    # happened, and the eval suite then scores the wrong model.
    assert [r.is_fallback for r in result.records] == [False, False, False, True]
    assert result.records[3].fallback_trigger == ErrorClass.PROVIDER_TEMPORARY.value
    assert result.records[3].fell_back_from == CONN_A

    winner = result.records[3]
    assert winner.outcome is AttemptOutcome.SUCCESS
    assert winner.error_class is None
    assert winner.usage.total_input_tokens == 11
    assert winner.usage.output_tokens == 2
    assert winner.provider_request_id == "req-scripted-anthropic"
    assert winner.deltas == 2


async def test_a_capability_rejection_is_terminal_and_sends_nothing() -> None:
    primary = ScriptedAdapter("openai", Step(reject=True))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("would have answered",)))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert fallback.calls == [], "a validation defect of ours multiplied onto a second vendor"
    assert result.outcomes == [AttemptOutcome.REJECTED]
    assert result.records[0].made_request is False
    assert result.terminal.error_class == ErrorClass.VALIDATION.value
    assert result.breaker.failures == []


async def test_the_ordinal_is_the_turn_and_not_the_connection() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    fallback = ScriptedAdapter("anthropic", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert [r.ordinal for r in result.records] == [1, 2, 3, 4, 5, 6]


# ── credentials ───────────────────────────────────────────────────────────────


def test_the_module_never_unwraps_a_credential() -> None:
    """No ``.get_secret_value()`` anywhere in the router.

    Asserted against the AST rather than the source text, and that is not fastidiousness: the
    plain ``"get_secret_value" not in source`` version FAILED on first run, because the module
    docstring states the rule it was checking. A self-tripping grep is a test that has to be
    weakened to pass, and the weakening is what kills it — the rule would have been deleted from
    the prose to make the assertion go green.
    """
    tree = ast.parse(pathlib.Path(router_module.__file__).read_text())
    unwraps = [
        node
        for node in ast.walk(tree)
        if isinstance(node, ast.Attribute) and node.attr == "get_secret_value"
    ]
    assert unwraps == [], "the credential is unwrapped here; it belongs at the adapter's client"


async def test_each_connection_receives_only_its_own_key() -> None:
    primary = ScriptedAdapter("openai", Step(error_class=ErrorClass.PROVIDER_TEMPORARY))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("second",)))
    result = await run_chain([link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)])

    assert primary.credentials == [KEY_A] * MAX_ATTEMPTS_PER_LINK
    assert fallback.credentials == [KEY_B]
    # Nothing the caller serialises may carry a key. `repr` is the shape that leaks: one
    # str() of an unwrapped map prints every tenant key in the envelope.
    rendered = repr(result.records) + repr(result.terminal.model_dump())
    assert KEY_A.get_secret_value() not in rendered
    assert KEY_B.get_secret_value() not in rendered


async def test_a_missing_credential_fails_closed_and_does_not_substitute_another() -> None:
    primary = ScriptedAdapter("openai", Step(deltas=("never sent",)))
    fallback = ScriptedAdapter("anthropic", Step(deltas=("also never sent",)))
    result = await run_chain(
        [link(CONN_A, MODEL_A, primary), link(CONN_B, MODEL_B, fallback)],
        credentials={CONN_B: KEY_B},
    )

    assert primary.calls == []
    assert fallback.calls == [], "a missing credential is a refusal, not a fallback"
    assert result.outcomes == [AttemptOutcome.SKIPPED_NO_CREDENTIAL]
    assert result.terminal.error_class == ErrorClass.VALIDATION.value


# ── boundaries this module must not cross ─────────────────────────────────────


def test_this_module_writes_no_database_row() -> None:
    """``provider_calls`` is Laravel-owned and absent from ``ALLOWED_TABLES``.

    A write here would be an ADR-033 property-2 violation of exactly the shape ``docs/22`` § S1
    records. The tree-wide AST scan in ``test_write_allow_list_scan.py`` catches the statement;
    this catches the import that would precede it, which is the cheaper thing to notice.
    """
    source = pathlib.Path(router_module.__file__).read_text()
    tree = ast.parse(source)
    modules = {node.module for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)}
    modules |= {
        alias.name
        for node in ast.walk(tree)
        if isinstance(node, ast.Import)
        for alias in node.names
    }
    assert not any(m and m.startswith(("app.db", "sqlalchemy", "asyncpg")) for m in modules)
    for literal in (node.value for node in ast.walk(tree) if isinstance(node, ast.Constant)):
        if isinstance(literal, str):
            assert "INSERT INTO" not in literal
            assert "DELETE FROM" not in literal
            assert "UPDATE " not in literal


@pytest.mark.parametrize(
    "surface", [ProviderSurface.EMBEDDING, ProviderSurface.RERANK], ids=lambda s: s.value
)
@pytest.mark.parametrize("error_class", ALL_CLASSES, ids=_class_id)
def test_no_non_chat_surface_can_ever_fall_back(
    error_class: ErrorClass, surface: ProviderSurface
) -> None:
    """A different provider is a different vector space, and the failure is silent: widths
    coincide, Qdrant accepts the upsert, and some chunks simply stop being retrievable."""
    assert fallback_eligible(error_class, surface) is False


def test_the_router_only_ever_asks_about_the_chat_surface() -> None:
    source = pathlib.Path(router_module.__file__).read_text()
    assert "ProviderSurface.CHAT" in source
    assert "ProviderSurface.EMBEDDING" not in source
    assert "ProviderSurface.RERANK" not in source


def test_the_no_fallback_list_is_not_restated_here() -> None:
    """The classes that must never fall back appear in this module only inside prose.

    Typing one into a set literal or an ``is`` comparison creates a second source of truth that
    will not move when ``app/core/errors.py`` does.
    """
    source = pathlib.Path(router_module.__file__).read_text()
    tree = ast.parse(source)
    banned = {c.value for c in ErrorClass if not FALLBACK_ELIGIBLE[c]}
    named = {
        node.attr
        for node in ast.walk(tree)
        if isinstance(node, ast.Attribute)
        and isinstance(node.value, ast.Name)
        and node.value.id == "ErrorClass"
    }
    # The three the loop legitimately RAISES or SYNTHESIZES are its own verdicts about its own
    # decisions, not verdicts about a vendor failure's eligibility.
    allowed = {
        ErrorClass.VALIDATION.name,
        ErrorClass.PROVIDER_TEMPORARY.name,
        ErrorClass.USER_CANCELLATION.name,
        ErrorClass.INTERNAL_DEPENDENCY.name,
        ErrorClass.PROVIDER_RATE_LIMIT.name,
    }
    leaked = {n for n in named if n not in allowed and ErrorClass[n].value in banned}
    assert leaked == set(), f"the no-fallback list is being restated: {sorted(leaked)}"


async def test_an_empty_chain_raises_before_any_event() -> None:
    router = FallbackRouter(breaker=NullBreaker())
    records: list[ProviderAttempt] = []
    with pytest.raises(KbError) as caught:
        async for _ in router.stream(
            request_for(), [], CREDENTIALS, deadline_monotonic=1e9, record=records.append
        ):
            pass
    assert caught.value.error_class is ErrorClass.VALIDATION
    assert records == []

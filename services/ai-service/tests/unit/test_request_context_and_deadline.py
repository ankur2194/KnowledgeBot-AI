"""``request_context`` and ``deadline``: the two dependencies that read verified headers.

No app, no transport, no container — a ``Request`` is a scope dict, and building one directly
is what lets these assert the field *types* rather than the status code a handler happened to
return. The signed-transport half is `tests/contract/test_internal_transport.py`.

RULING D4 IS WHAT THIS FILE PINS. Two fields on ``RequestContext`` were typed against the
opposite of what the wire carries, and both were commented confidently:

* ``contract_version`` was ``int`` with the note "Monotonic integer, not a string". The wire
  value is ``"v1"`` — `services/core-api/app/Services/Internal/InternalAiClient.php` sends
  ``config('kb.contract_version')``, which `config/kb.php` sets to ``'v1'``, and
  `InternalRequestSignerTest.php` signs that literal.
* ``config_version`` was ``str``. The wire value is a decimal integer —
  `kb-internal-api-contracts` calls it "monotonic integer of the resolved configuration
  snapshot", and Laravel renders one.

Both were flipped. The tests below assert the *parsed* types, because a string that happens to
contain digits satisfies every assertion written against ``payload["config_version"]``.
"""

from __future__ import annotations

from typing import Any, Final

import pytest
from starlette.requests import Request

from app.api.deps import Deadline, RequestContext, deadline, request_context
from app.core.errors import ErrorClass, KbError

BASE_HEADERS: Final[dict[str, str]] = {
    "x-kb-request-id": "01JQZ0000000000000000000RR",
    "x-kb-org-id": "01JQZ0000000000000000000AA",
    "x-kb-actor-type": "user",
    "x-kb-operation": "embedding.readiness",
    "x-kb-contract-version": "v1",
    "x-kb-config-version": "7",
    "x-kb-deadline": "1786000000000",
    "x-kb-timestamp": "1786000000",
}


def _request(headers: dict[str, str], *, method: str = "POST", path: str = "/p") -> Request:
    return Request(
        {
            "type": "http",
            "http_version": "1.1",
            "method": method,
            "path": path,
            "raw_path": path.encode(),
            "query_string": b"",
            "root_path": "",
            "scheme": "http",
            "headers": [(k.lower().encode(), v.encode("latin-1")) for k, v in headers.items()],
            "client": ("10.0.0.2", 51234),
            "server": ("ai-api", 8000),
            "state": {},
        }
    )


async def _context(headers: dict[str, str]) -> RequestContext:
    """Drive the generator dependency the way FastAPI's exit stack does."""
    generator = request_context(_request(headers))
    try:
        return await anext(generator)
    finally:
        await generator.aclose()


# ── the two flipped types ─────────────────────────────────────────────────────


async def test_the_contract_version_is_the_label_the_wire_carries() -> None:
    ctx = await _context(BASE_HEADERS)

    assert ctx.contract_version == "v1"
    assert isinstance(ctx.contract_version, str)


async def test_the_config_version_is_parsed_as_an_integer() -> None:
    """A ``str`` here compares unequal to every snapshot version it is checked against, and
    ``"10" < "9"`` — so an "is this snapshot newer" comparison would be wrong in a way that only
    shows up past version 9."""
    ctx = await _context(BASE_HEADERS)

    assert ctx.config_version == 7
    assert isinstance(ctx.config_version, int)
    assert not isinstance(ctx.config_version, str)


@pytest.mark.parametrize("raw", ["v1", "", "1.0", "-1", "0x7", "¹²", " 7 ", "07"])
async def test_a_config_version_that_is_not_a_decimal_integer_is_a_validation_failure(
    raw: str,
) -> None:
    """``"¹²"`` is the case a ``str.isdigit()`` guard gets exactly backwards: superscripts are
    latin-1 bytes so a header really can carry them, ``isdigit()`` says ``True``, and ``int()``
    then raises ``ValueError`` — turning a malformed header into a 500 with ``origin=SELF``,
    our own defect, instead of a 422. ``"07"`` is refused for the same reason the timestamp is:
    a value that does not render back to itself has no single correct reading.
    """
    with pytest.raises(KbError) as caught:
        await _context(BASE_HEADERS | {"x-kb-config-version": raw})

    assert caught.value.error_class is ErrorClass.VALIDATION
    assert caught.value.retryable is False


# ── what is read, and what is deliberately not ────────────────────────────────


async def test_every_field_comes_from_a_header_and_none_from_the_body_or_the_peer() -> None:
    ctx = await _context(BASE_HEADERS | {"x-kb-bot-id": "01JQZ0000000000000000000BB"})

    assert ctx.request_id == "01JQZ0000000000000000000RR"
    assert ctx.org_id == "01JQZ0000000000000000000AA"
    assert ctx.bot_id == "01JQZ0000000000000000000BB"
    assert ctx.actor_type == "user"
    assert ctx.operation == "embedding.readiness"


async def test_the_optional_headers_are_none_rather_than_empty_strings() -> None:
    """``X-KB-Bot-Id`` is absent on organization-scoped work and ``X-KB-Actor-Id`` on
    scheduler-initiated work. ``""`` would satisfy ``if ctx.bot_id:`` differently from ``None``
    in some places and identically in others, which is the shape that produces one filter that
    is narrower than intended (`kb-tenancy-isolation`)."""
    ctx = await _context(BASE_HEADERS | {"x-kb-bot-id": "", "x-kb-actor-id": ""})

    assert ctx.bot_id is None
    assert ctx.actor_id is None


@pytest.mark.parametrize(
    "name",
    [
        "x-kb-request-id",
        "x-kb-org-id",
        "x-kb-actor-type",
        "x-kb-operation",
        "x-kb-contract-version",
    ],
)
async def test_a_missing_required_header_is_refused_and_never_defaulted(name: str) -> None:
    """`kb-internal-api-contracts`: absent ``X-KB-Org-Id`` is rejected at the dependency, never
    defaulted and never inferred from the body — FastAPI cannot build a tenant-safe Qdrant
    filter without it. The others are required for the same structural reason: every one of
    them is a metric label, a log field or a diagnostics gate that has no safe default.
    """
    headers = {k: v for k, v in BASE_HEADERS.items() if k != name}

    with pytest.raises(KbError) as caught:
        await _context(headers)

    assert caught.value.error_class is ErrorClass.VALIDATION
    assert name.upper() in caught.value.message


async def test_the_config_version_header_is_required_though_the_control_plane_omits_it() -> None:
    """`kb-internal-api-contracts` marks ``X-KB-Config-Version`` **always** required.
    ``InternalAiClient::embeddingReadiness()`` does not send it — a real drift, pinned from this
    side in `tests/contract/test_signing_cross_language.py` and owned by the control plane. The
    contract is the authority, so the dependency enforces it rather than tolerating the gap."""
    headers = {k: v for k, v in BASE_HEADERS.items() if k != "x-kb-config-version"}

    with pytest.raises(KbError) as caught:
        await _context(headers)

    assert caught.value.error_class is ErrorClass.VALIDATION


async def test_the_request_id_is_stamped_on_request_state_for_the_error_envelope() -> None:
    """``app/main.py``'s ``_envelope`` reads ``request.state.request_id`` and would otherwise
    render ``request_id: null`` on every failure — the field a failure is greppable by across
    both services."""
    request = _request(BASE_HEADERS)
    generator = request_context(request)
    try:
        ctx = await anext(generator)
        assert request.state.request_id == ctx.request_id
    finally:
        await generator.aclose()


async def test_the_log_context_is_bound_for_the_scope_and_released_after() -> None:
    """The answer to the TODO in ``app/observability/logging.py``: a ``return``ing coroutine
    cannot hold a context manager open, so this dependency yields. Until it did, every log line
    rendered ``request_id: null``, which that TODO calls "the honest value"."""
    from app.observability.logging import current_log_context

    assert current_log_context()["request_id"] is None

    generator = request_context(_request(BASE_HEADERS))
    try:
        ctx = await anext(generator)
        bound = current_log_context()
        assert bound["request_id"] == ctx.request_id
        assert bound["operation"] == ctx.operation
        assert bound["org_id"] == ctx.org_id
    finally:
        await generator.aclose()

    assert current_log_context()["request_id"] is None, (
        "the binding outlived the request; the next task on this loop inherits its identifiers"
    )


# ── the deadline ──────────────────────────────────────────────────────────────


async def test_the_deadline_is_absolute_epoch_milliseconds_and_not_a_duration(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """55 000 in the header is 55 seconds from the epoch, not 55 seconds from now. Reading it as
    a duration makes a deadline fire the instant the request arrives — or never."""
    monkeypatch.setattr("time.time", lambda: 1_786_000_000.0)
    monkeypatch.setattr("time.monotonic", lambda: 5_000.0)

    result = await deadline(_request(BASE_HEADERS | {"x-kb-deadline": "1786000055000"}))

    assert isinstance(result, Deadline)
    assert result.monotonic_expiry == pytest.approx(5_055.0)


async def test_the_deadline_is_held_on_the_monotonic_clock_so_an_ntp_step_cannot_move_it(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The docstring's whole point. Convert once, on arrival; recomputing from ``time.time()``
    mid-request makes a clock step look like an expired deadline — forward and the work is
    abandoned early, backward and it outlives the caller."""
    clock: dict[str, float] = {"wall": 1_786_000_000.0, "mono": 5_000.0}
    monkeypatch.setattr("time.time", lambda: clock["wall"])
    monkeypatch.setattr("time.monotonic", lambda: clock["mono"])

    result = await deadline(_request(BASE_HEADERS | {"x-kb-deadline": "1786000030000"}))
    assert result.remaining() == pytest.approx(30.0)

    # An NTP step of ten minutes. Nothing about the remaining budget may change.
    clock["wall"] += 600.0
    assert result.remaining() == pytest.approx(30.0)

    # Real elapsed time, however, must.
    clock["mono"] += 10.0
    assert result.remaining() == pytest.approx(20.0)


async def test_a_deadline_already_in_the_past_floors_at_zero_rather_than_going_negative(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr("time.time", lambda: 1_786_000_000.0)
    monkeypatch.setattr("time.monotonic", lambda: 5_000.0)

    result = await deadline(_request(BASE_HEADERS | {"x-kb-deadline": "1785999000000"}))

    assert result.remaining() == 0.0


@pytest.mark.parametrize(
    "raw",
    ["", "   ", "55", "not-a-number", "1786000000.5", "-1786000000000", "0x1", "¹²", "01786"],
)
async def test_a_missing_or_unparseable_deadline_is_a_validation_failure(raw: str) -> None:
    """``"55"`` is in the list on purpose: it parses, and reading it as "55 seconds" is the
    duration mistake. It is a legal epoch-millisecond value (1970) and produces an already-
    expired deadline, which is the correct and visible outcome rather than a silent 55-second
    budget. The unparseable ones are what this raises on.

    ``"¹²"`` is the case a ``str.isdigit()`` check gets exactly backwards: superscript digits
    are latin-1 bytes, so a header really can carry them; ``"¹²".isdigit()`` is ``True``; and
    ``int("¹²")`` then raises ``ValueError``. A validator written with ``isdigit`` therefore
    turns this header into a 500 with ``origin=SELF`` — our own defect — instead of a 422.
    ``"01786"`` is the leading zero: legal to ``int()``, and refused here so that no value in
    the covered header set can render back to something other than itself.
    """
    if raw == "55":
        pytest.skip("a legal epoch value; covered by the already-expired test above")

    headers = {k: v for k, v in BASE_HEADERS.items() if k != "x-kb-deadline"}
    if raw:
        headers["x-kb-deadline"] = raw

    with pytest.raises(KbError) as caught:
        await deadline(_request(headers))

    assert caught.value.error_class is ErrorClass.VALIDATION


async def test_a_deadline_that_is_a_legal_epoch_but_long_past_is_accepted_and_already_expired(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The counterpart to the case skipped above: ``"55"`` is 55 ms after the epoch. It parses,
    it is honest, and it expires immediately — which is what a caller who sent a duration
    deserves to see, rather than a fresh 55-second budget nobody granted."""
    monkeypatch.setattr("time.time", lambda: 1_786_000_000.0)
    monkeypatch.setattr("time.monotonic", lambda: 5_000.0)

    result = await deadline(_request(BASE_HEADERS | {"x-kb-deadline": "55"}))

    assert result.remaining() == 0.0


def test_the_context_is_frozen_so_a_handler_cannot_widen_its_own_scope() -> None:
    ctx: Any = RequestContext(
        request_id="r",
        org_id="a",
        bot_id=None,
        actor_id=None,
        actor_type="user",
        operation="chat.execute",
        contract_version="v1",
        config_version=1,
    )

    with pytest.raises(AttributeError):
        ctx.org_id = "b"


# ── the contract-version check that is deferred, and the reason it is ─────────


def test_the_taxonomy_still_has_no_row_that_renders_409() -> None:
    """A tripwire on a recorded deferral, not an assertion about the error table.

    `kb-internal-api-contracts` says a mismatch between ``X-KB-Contract-Version`` and the path
    prefix is a **409**, not a best-effort guess. ``request_context`` stores the header and
    never compares it, because none of the eighteen error classes renders 409 — adding one is a
    change to a table Python, PHP and TypeScript each transcribe, and not a fix to make inside
    a dependency function. Both values come from the same Laravel configuration key today, so
    the mismatch is unreachable.

    The day a 409 row exists this fails, which is the prompt to go and write the comparison
    ``app/api/deps.py:request_context`` documents as owed.
    """
    from app.core.errors import ErrorClass, Surface, status_for

    rendered = {status_for(cls, Surface.ADMIN) for cls in ErrorClass}
    assert 409 not in rendered, (
        "the taxonomy now renders 409, so the X-KB-Contract-Version comparison "
        "app/api/deps.py:request_context defers is implementable — write it"
    )

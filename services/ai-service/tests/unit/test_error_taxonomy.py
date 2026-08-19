"""The 18-class error taxonomy: every class has a verdict, and the verdicts are load-bearing.

`kb-error-taxonomy` is a lookup table that every retry, fallback, breaker and paging decision
in the platform reads. Three things about it fail silently and this file is where each one is
caught:

* **A partially populated table.** ``app/core/errors.py`` asserts key-set equality at import,
  which is the right place for it — a half-built table must not survive to a running process.
  Those import-time assertions vanish under ``python -O``, and the rendering layer's
  ``KeyError`` would then be raised *inside an exception handler*, which is the one place an
  exception cannot be handled. These tests hold the same invariants where ``-O`` cannot reach
  them.
* **A status re-derived into a class.** Several classes share a status. Asserting that the
  status map is *not injective* is what makes "never re-derive ``error_class`` from an HTTP
  status" a structural fact rather than a convention.
* **A verdict flipped for convenience.** ``provider_billing`` becoming fallback-eligible
  quietly moves a tenant's spend to a second account when an invoice goes unpaid;
  ``provider_permanent_request`` becoming fallback-eligible serves every answer from a model
  the tenant never configured, with a Ready bot and no error anywhere. Both read as one-word
  diffs.

No containers, no app, no fixtures — this is a table and a pure function.
"""

from __future__ import annotations

import pytest

from app.core.errors import (
    FALLBACK_ELIGIBLE,
    RETRYABLE,
    ErrorClass,
    KbError,
    Origin,
    Surface,
    retryable_for,
    status_for,
)

#: Transcribed from the skill, in its order, deliberately **not** derived from ``ErrorClass``.
#: Deriving it would make this test agree with any edit to the enum, which is the one thing it
#: must not do: the taxonomy staying at 18 is a review stop, not a refactor.
EXPECTED_CLASSES = (
    "validation",
    "authentication",
    "authorization",
    "tenant_quota",
    "rate_limit",
    "provider_auth",
    "provider_rate_limit",
    "provider_billing",
    "provider_temporary",
    "provider_permanent_request",
    "retrieval",
    "parsing",
    "ocr",
    "crawl",
    "vector_indexing",
    "storage",
    "internal_dependency",
    "user_cancellation",
)


def test_the_taxonomy_is_exactly_these_eighteen_classes() -> None:
    """Tuple equality catches membership, count and order in one assertion.

    A nineteenth class does not error anywhere: it silently takes whatever the fallback
    branch of each lookup does, which for retry means "no" and for paging means "never".
    """
    assert tuple(c.value for c in ErrorClass) == EXPECTED_CLASSES
    assert len(ErrorClass) == 18


@pytest.mark.parametrize("error_class", list(ErrorClass), ids=lambda c: c.value)
def test_every_class_has_a_status_a_retry_verdict_and_a_fallback_verdict(
    error_class: ErrorClass,
) -> None:
    """Parametrized rather than asserted over the whole set, so a gap names the class.

    ``status_for`` is exercised instead of ``_STATUS`` so this also covers the rendering
    function, which is what the exception handler actually calls.
    """
    assert isinstance(status_for(error_class), int)
    assert error_class in RETRYABLE
    assert error_class in FALLBACK_ELIGIBLE


@pytest.mark.parametrize("error_class", list(ErrorClass), ids=lambda c: c.value)
def test_every_status_is_a_plausible_http_code(error_class: ErrorClass) -> None:
    """400-499 or 500-599. A class rendering 200 is an error the caller cannot see."""
    status = status_for(error_class)
    assert 400 <= status <= 599


def test_authorization_renders_403_to_admin_and_404_to_the_public_and_sdk_surfaces() -> None:
    """The enumeration-oracle rule, and the only row where the surface matters.

    A 403 on a foreign identifier *confirms the row exists*, which turns any endpoint taking
    an id into a membership oracle for every other tenant. The public runtime and the SDK
    must be unable to distinguish "not yours" from "not there".
    """
    assert status_for(ErrorClass.AUTHORIZATION, Surface.ADMIN) == 403
    assert status_for(ErrorClass.AUTHORIZATION, Surface.PUBLIC) == 404
    assert status_for(ErrorClass.AUTHORIZATION, Surface.SDK) == 404


def test_authorization_is_the_only_class_whose_status_depends_on_the_surface() -> None:
    """If a second class ever renders per-surface, a caller branching on status branches on
    who asked — and that is how a retry policy starts differing between the widget and the
    admin console for the same failure."""
    varying = {c for c in ErrorClass if len({status_for(c, surface) for surface in Surface}) > 1}
    assert varying == {ErrorClass.AUTHORIZATION}


def test_the_status_map_is_not_injective_so_a_class_cannot_be_recovered_from_it() -> None:
    """The structural form of "never re-derive ``error_class`` from an HTTP status".

    503 alone is retrieval, vector_indexing, storage, internal_dependency and
    provider_temporary — with different retry policies and different paging. A relay that
    reconstructs the class from the status picks one of them, and it will be wrong four
    times out of five.
    """
    by_status: dict[int, set[str]] = {}
    for error_class in ErrorClass:
        by_status.setdefault(status_for(error_class), set()).add(error_class.value)

    ambiguous = {status: names for status, names in by_status.items() if len(names) > 1}
    assert ambiguous, (
        "every class rendered a unique status, so the taxonomy would be recoverable from it "
        "and the no-re-derivation rule would be a style preference rather than a necessity"
    )
    assert by_status[503] == {
        "provider_temporary",
        "retrieval",
        "vector_indexing",
        "storage",
        "internal_dependency",
    }


def test_only_two_classes_are_fallback_eligible() -> None:
    """An organization that configured a fallback authorized it for *provider outages*.

    ``provider_billing`` is excluded because an exhausted account is not an outage: falling
    back quietly moves the tenant's spend to a second account because an invoice went unpaid.
    ``provider_permanent_request`` is excluded because an unknown model id came from the
    bot's own configuration snapshot — falling back then answers every message from a model
    the tenant did not choose, at a different price and quality, with no error anywhere.
    """
    eligible = {c.value for c in ErrorClass if FALLBACK_ELIGIBLE[c]}
    assert eligible == {"provider_rate_limit", "provider_temporary"}


def test_no_class_outside_the_provider_group_is_fallback_eligible() -> None:
    """Falling back on ``retrieval`` or ``storage`` re-issues a call against a different
    provider for a failure that had nothing to do with the provider."""
    for error_class in ErrorClass:
        if not error_class.value.startswith("provider_"):
            assert FALLBACK_ELIGIBLE[error_class] is False, error_class


@pytest.mark.parametrize(
    "error_class",
    [
        ErrorClass.VALIDATION,
        ErrorClass.AUTHENTICATION,
        ErrorClass.AUTHORIZATION,
        ErrorClass.TENANT_QUOTA,
        ErrorClass.PROVIDER_AUTH,
        ErrorClass.PROVIDER_BILLING,
        ErrorClass.PROVIDER_PERMANENT_REQUEST,
        ErrorClass.USER_CANCELLATION,
    ],
    ids=lambda c: c.value,
)
def test_permanent_failures_are_never_retryable(error_class: ErrorClass) -> None:
    """None of these self-heal. Retrying them burns the budget that a genuinely transient
    failure later in the same request needed."""
    assert RETRYABLE[error_class] is False


def test_retryable_answers_may_the_caller_retry_not_do_we_retry_internally() -> None:
    """``rate_limit`` is ``True``, and the subject of the verb is the whole point.

    This table is **client-facing**: it populates the ``retryable`` field of the error
    envelope, which Laravel relays verbatim and every client branches on. For ``rate_limit``
    the honest answer is yes — the caller may retry once ``Retry-After`` has elapsed — even
    though *we* never self-retry our own 429.

    Pinned here because the two readings diverge for exactly this one row and the divergence
    is invisible at the call site. A server-side retry ladder gated on ``RETRYABLE[...]``
    alone will self-retry our own limiter, converting shaped load into self-inflicted load
    while the tenant never learns it is over its limit. A retry gate needs the
    ``rate_limit``-shaped exclusion in addition to this table, not instead of it.
    """
    assert RETRYABLE[ErrorClass.RATE_LIMIT] is True
    assert RETRYABLE[ErrorClass.PROVIDER_RATE_LIMIT] is True
    # Neither 429 is ever failed over: our own limiter is not a provider fault, and a
    # provider 429 falls back only when the bot configured one, which is a policy decision
    # made per bot rather than a property of the class.
    assert FALLBACK_ELIGIBLE[ErrorClass.RATE_LIMIT] is False


def test_cancellation_is_an_outcome_not_an_error() -> None:
    """499 keeps it out of the 5xx ratio, and it is neither retried nor failed over.

    The client is the one that left; there is nobody to answer. It still finalizes usage —
    that is asserted where the shielded ``finally`` runs, not here.
    """
    assert status_for(ErrorClass.USER_CANCELLATION) == 499
    assert RETRYABLE[ErrorClass.USER_CANCELLATION] is False
    assert FALLBACK_ELIGIBLE[ErrorClass.USER_CANCELLATION] is False


def test_kb_error_takes_its_retry_verdict_from_the_table() -> None:
    """Defaulting at construction rather than at the call site is what stops a subsystem
    quietly promoting a permanent failure to a temporary one."""
    error = KbError(ErrorClass.PROVIDER_BILLING, "account exhausted")
    assert error.retryable is RETRYABLE[ErrorClass.PROVIDER_BILLING] is False
    assert error.error_class is ErrorClass.PROVIDER_BILLING


def test_an_explicit_retry_verdict_narrows_a_transient_sub_case() -> None:
    """``parsing`` is retryable as a class because some sub-cases are transient; an
    unsupported file is not one of them and must say so at the raise site."""
    assert RETRYABLE[ErrorClass.PARSING] is True
    unsupported = KbError(ErrorClass.PARSING, "unsupported container format", retryable=False)
    assert unsupported.retryable is False


def test_kb_error_carries_no_status_of_its_own() -> None:
    """The class is the row key; the status is a *rendering* of it for one surface.

    An exception that carries a status is an exception that can disagree with the table, and
    the disagreement shows up as a 403 on a public surface — the enumeration oracle again,
    reached from the other direction.
    """
    error = KbError(ErrorClass.AUTHORIZATION, "not a member")
    assert not hasattr(error, "status")
    assert not hasattr(error, "status_code")
    # `origin` is here and `status` is not, and the difference is the point: origin is an
    # INPUT to the rendering, chosen at the raise site; a status would be an output the
    # exception had already decided for itself.
    #
    # `actionable` is here too and is a WIRE field, which `origin` is not -- the test for that
    # is test_a_kb_error_carries_its_own_actionable_verdict_onto_the_wire. It does not weaken
    # the rule above: it says whether `message` is addressed to a person, which is a fact about
    # the message, not a status by another name. A client that inferred 409 from it would be
    # making exactly the mistake this test guards, and the field's docstring says so.
    assert set(KbError.__slots__) == {
        "actionable",
        "error_class",
        "message",
        "origin",
        "retry_after",
        "retryable",
    }


def test_retry_after_defaults_to_absent_rather_than_zero() -> None:
    """``0`` would render ``Retry-After: 0`` and invite an immediate retry storm; ``None``
    renders no header at all."""
    assert KbError(ErrorClass.PROVIDER_RATE_LIMIT, "slow down").retry_after is None
    assert KbError(ErrorClass.PROVIDER_RATE_LIMIT, "slow down", retry_after=30).retry_after == 30


def test_error_class_is_a_string_enum_so_it_serializes_as_its_wire_value() -> None:
    """``error_class`` crosses the wire verbatim and Laravel relays it unchanged. A plain
    ``Enum`` would serialize as ``ErrorClass.PROVIDER_RATE_LIMIT`` and every client's
    discriminator would stop matching."""
    assert ErrorClass.PROVIDER_RATE_LIMIT == "provider_rate_limit"
    assert f"{ErrorClass.PROVIDER_RATE_LIMIT}" == "provider_rate_limit"


# ── ADR-029 / finding O1: the internal_dependency origin sub-case ─────────────────────────
#
# The defect this section guards: an unmapped exception in OUR OWN code used to render
# 503/retryable=true here and 500/retryable=false in Laravel. A client obeying the envelope
# retried one plane's defects on a full backoff ladder against something that could not
# succeed on any attempt, and reported the other plane's immediately. Same failure, two
# contradictory instructions, neither side obviously wrong in isolation.


def test_the_taxonomy_is_still_eighteen_classes_after_the_origin_sub_case() -> None:
    """The whole reason origin is a second axis rather than a nineteenth class.

    ``internal_error`` as a new member would have moved a count asserted at import, in this
    file, in the metric label allow-list, and across at least five skills.
    """
    assert len(ErrorClass) == 18
    assert not hasattr(ErrorClass, "INTERNAL_ERROR")


def test_a_self_origin_internal_dependency_renders_500_and_is_not_retryable() -> None:
    """Our own defect. No number of attempts fixes a bug, so the envelope must not invite
    any — this is the exact pair Laravel renders for an unmapped Throwable."""
    assert status_for(ErrorClass.INTERNAL_DEPENDENCY, origin=Origin.SELF) == 500
    assert retryable_for(ErrorClass.INTERNAL_DEPENDENCY, Origin.SELF) is False


def test_a_downstream_internal_dependency_still_renders_503_and_is_retryable() -> None:
    """The reading that was always correct and had to survive.

    Collapsing both origins onto 500 was the rejected resolution precisely because it
    destroys the honest "a dependency is briefly unavailable, come back shortly" signal.
    """
    assert status_for(ErrorClass.INTERNAL_DEPENDENCY) == 503
    assert status_for(ErrorClass.INTERNAL_DEPENDENCY, origin=Origin.DOWNSTREAM) == 503
    assert retryable_for(ErrorClass.INTERNAL_DEPENDENCY) is True
    assert RETRYABLE[ErrorClass.INTERNAL_DEPENDENCY] is True


def test_the_default_origin_is_downstream_so_the_table_reading_is_unchanged() -> None:
    """Every existing call site passes no origin and must keep the behaviour it had."""
    for error_class in ErrorClass:
        assert status_for(error_class) == status_for(error_class, Surface.ADMIN, Origin.DOWNSTREAM)
        assert retryable_for(error_class) is RETRYABLE[error_class]


@pytest.mark.parametrize(
    "error_class",
    [c for c in ErrorClass if c is not ErrorClass.INTERNAL_DEPENDENCY],
    ids=lambda c: c.value,
)
def test_internal_dependency_is_the_only_class_whose_status_depends_on_origin(
    error_class: ErrorClass,
) -> None:
    """Origin is a second axis on exactly ONE row, the way Surface is on exactly one row.

    A second origin-sensitive class would mean a caller branching on status is branching on
    which side of a seam failed — an implementation detail no client can observe.
    """
    assert status_for(error_class, Surface.ADMIN, Origin.SELF) == status_for(error_class)
    assert retryable_for(error_class, Origin.SELF) is RETRYABLE[error_class]


def test_a_self_origin_kb_error_cannot_be_retryable_by_omission() -> None:
    """The specific mechanism that produced O1.

    ``KbError`` defaults its verdict from ``retryable_for(class, origin)``, not from
    ``RETRYABLE[class]``. Reading the raw table at a construction site is how a self-origin
    defect gets born claiming it is worth retrying.
    """
    defect = KbError(ErrorClass.INTERNAL_DEPENDENCY, "boom", origin=Origin.SELF)
    assert defect.origin is Origin.SELF
    assert defect.retryable is False

    downstream = KbError(ErrorClass.INTERNAL_DEPENDENCY, "qdrant unreachable")
    assert downstream.origin is Origin.DOWNSTREAM
    assert downstream.retryable is True


async def test_the_unhandled_exception_handler_renders_exactly_what_laravel_renders() -> None:
    """The end-to-end form of O1, asserted against the real handler.

    This is the assertion that would have caught the original split: Laravel renders an
    unmapped Throwable as 500 / internal_dependency / retryable=false, and a consumer cannot
    tell which plane produced an envelope, so this handler must produce the same four values.

    It also holds the redaction rule — ``str(exc)`` never reaches the body, because provider
    error payloads routinely echo the request, credentials included.
    """
    import json

    from starlette.requests import Request

    from app.main import _handle_unexpected

    scope = {"type": "http", "method": "POST", "path": "/internal/v1/chat", "headers": []}
    request = Request(scope)
    response = await _handle_unexpected(request, RuntimeError("psycopg: password=hunter2"))

    assert response.status_code == 500
    body = json.loads(bytes(response.body))
    assert body["error_class"] == "internal_dependency"
    assert body["retryable"] is False
    # THE PLACEHOLDER, MARKED AS ONE (finding J2). This handler's message is chosen to say
    # nothing, and saying so on the wire is what lets a client tell a deliberate 4xx from a
    # defect -- the two are otherwise the same (error_class, retryable) pair, because the
    # taxonomy has no 409 row on purpose.
    assert body["actionable"] is False
    # `origin` selects the rendering; it is not itself a wire field. Adding one would be a
    # contract change across three clients for something no client can act on. `actionable`
    # IS one, and the difference is that a client can act on it: it decides whether the
    # message may be rendered.
    #
    # THIS SET IS EXACT ON PURPOSE. It is the tripwire that fires when either plane grows a
    # field the other does not have, and it did its job when `actionable` was added.
    assert set(body) == {"error_class", "message", "retryable", "request_id", "actionable"}
    assert "hunter2" not in bytes(response.body).decode()


async def test_a_kb_error_carries_its_own_actionable_verdict_onto_the_wire() -> None:
    """The other side of finding J2: a raised KbError's message IS written for a person.

    ``_envelope`` reads the flag off the error rather than recomputing it, because this
    function cannot tell a sentence from a placeholder without comparing strings -- which is
    exactly the client-side workaround the field exists to retire.
    """
    import json

    from starlette.requests import Request

    from app.core.errors import ErrorClass, KbError
    from app.main import _envelope

    scope = {"type": "http", "method": "POST", "path": "/internal/v1/chat", "headers": []}
    request = Request(scope)

    refusal = KbError(ErrorClass.VALIDATION, "two eligible connections disagree")
    default = _envelope(refusal, request)
    assert json.loads(bytes(default.body))["actionable"] is True

    # Narrowable at the call site, the same way `retryable` is.
    muted = _envelope(
        KbError(ErrorClass.VALIDATION, "two eligible connections disagree", actionable=False),
        request,
    )
    assert json.loads(bytes(muted.body))["actionable"] is False


async def test_the_pydantic_validation_envelope_is_not_actionable_but_carries_its_map() -> None:
    """A generic summary beside a real per-field map.

    ``"request failed validation"`` is a placeholder; the payload a caller acts on is
    ``errors``. Laravel answers the same for its own ValidationException and True for a
    deliberately raised ``KbException::validation`` -- the split is by PRODUCER, not by class.
    """
    import json

    from fastapi.exceptions import RequestValidationError
    from pydantic import BaseModel, ValidationError
    from starlette.requests import Request

    from app.main import _handle_validation_error

    class Body(BaseModel):
        top_k: int

    try:
        Body(top_k="not an int")  # type: ignore[arg-type]
    except ValidationError as exc:  # pragma: no branch - always raises
        wrapped = RequestValidationError(exc.errors())

    scope = {"type": "http", "method": "POST", "path": "/internal/v1/chat", "headers": []}
    response = await _handle_validation_error(Request(scope), wrapped)

    body = json.loads(bytes(response.body))
    assert body["actionable"] is False
    assert body["errors"]  # the map is what the caller keys on instead

"""The 18-class error taxonomy, as data.

Transcribed from `kb-error-taxonomy` (docs/14-reliability.md §19). This module owns the
*rendering* inputs only — which status a class becomes, whether it may be retried, whether
it may trigger fallback. It does not decide when a class applies; each subsystem maps its
own failures into this table rather than inventing a status.

Two rules that this file exists to make unbreakable:

* Nothing re-derives an ``error_class`` from an HTTP status. The class is the row key; the
  status is a rendering of it. Laravel relays the class verbatim.
* The taxonomy stays at 18. A nineteenth entry is a review stop, not a refactor — every
  retry, fallback, breaker and paging decision in the platform is a lookup into these
  tables, and an unlisted class silently gets whatever the fallback branch does.

``internal_dependency`` carries a second axis, ``Origin`` — see ADR-029 (finding O1). It is
the SAME idiom as ``Surface`` on ``authorization``: one row whose rendering depends on a
second input, with the class itself unchanged. It is not a nineteenth class, and the count
assertion at the bottom of this file still reads 18.
"""

from __future__ import annotations

from collections.abc import Mapping
from enum import StrEnum
from typing import Final

__all__ = [
    "FALLBACK_ELIGIBLE",
    "RETRYABLE",
    "ErrorClass",
    "KbError",
    "Origin",
    "Surface",
    "retryable_for",
    "status_for",
]


class ErrorClass(StrEnum):
    """The row key: the value in telemetry, the discriminator in the error envelope, and
    the input to every retry, fallback and breaker decision."""

    VALIDATION = "validation"
    AUTHENTICATION = "authentication"
    AUTHORIZATION = "authorization"
    TENANT_QUOTA = "tenant_quota"
    RATE_LIMIT = "rate_limit"
    PROVIDER_AUTH = "provider_auth"
    PROVIDER_RATE_LIMIT = "provider_rate_limit"
    PROVIDER_BILLING = "provider_billing"
    PROVIDER_TEMPORARY = "provider_temporary"
    PROVIDER_PERMANENT_REQUEST = "provider_permanent_request"
    RETRIEVAL = "retrieval"
    PARSING = "parsing"
    OCR = "ocr"
    CRAWL = "crawl"
    VECTOR_INDEXING = "vector_indexing"
    STORAGE = "storage"
    INTERNAL_DEPENDENCY = "internal_dependency"
    USER_CANCELLATION = "user_cancellation"


class Surface(StrEnum):
    """Which caller is being answered.

    Only ``AUTHORIZATION`` renders differently per surface, and the difference is not
    cosmetic: a 403 on a foreign identifier confirms the row exists and turns the endpoint
    into an enumeration oracle. Never branch on the surface in retry or fallback logic —
    branch on ``error_class``, which is identical in both cases.
    """

    ADMIN = "admin"
    PUBLIC = "public"
    SDK = "sdk"


class Origin(StrEnum):
    """Which side of a seam failed — the second axis on ``internal_dependency`` (ADR-029).

    ``DOWNSTREAM`` is a real dependency of ours being briefly unavailable: 503, retryable,
    "come back shortly", and that reading is worth keeping honest.

    ``SELF`` is an unmapped exception in our own code — a defect. It renders 500 and is NOT
    retryable, because no number of attempts fixes a bug. Before ADR-029 this case rendered
    503/retryable here and 500/not-retryable in Laravel, so a client obeying the envelope
    retried one plane's defects on a full backoff ladder and reported the other's
    immediately. Two planes, one failure, two contradictory instructions.

    Never branch on origin in retry, fallback or breaker logic — those are keyed on
    ``error_class``, which is identical either way. This changes the RENDERING only.
    """

    DOWNSTREAM = "downstream"
    SELF = "self"


# 499 is nginx's client-closed-request. It never reaches a client — the client is the one
# that left — but it keeps cancellation out of the 5xx error ratio, which is the whole
# point of giving it a distinct code.
_STATUS: Final[Mapping[ErrorClass, int]] = {
    ErrorClass.VALIDATION: 422,
    ErrorClass.AUTHENTICATION: 401,
    ErrorClass.AUTHORIZATION: 403,  # PUBLIC and SDK surfaces render 404 — see status_for()
    ErrorClass.TENANT_QUOTA: 403,
    ErrorClass.RATE_LIMIT: 429,
    ErrorClass.PROVIDER_AUTH: 502,
    ErrorClass.PROVIDER_RATE_LIMIT: 429,
    ErrorClass.PROVIDER_BILLING: 502,
    ErrorClass.PROVIDER_TEMPORARY: 503,
    ErrorClass.PROVIDER_PERMANENT_REQUEST: 502,
    ErrorClass.RETRIEVAL: 503,
    ErrorClass.PARSING: 422,
    ErrorClass.OCR: 422,
    ErrorClass.CRAWL: 502,
    ErrorClass.VECTOR_INDEXING: 503,
    ErrorClass.STORAGE: 503,
    ErrorClass.INTERNAL_DEPENDENCY: 503,
    ErrorClass.USER_CANCELLATION: 499,
}

#: Whether the tier that owns the adapter may retry. Exactly one tier retries a given
#: call; attempts multiply across tiers (3 tiers x 3 attempts = 27 provider calls from one
#: click), which is how a recoverable brownout becomes an outage.
#:
#: ``parsing``/``ocr``/``crawl`` are True only for the transient sub-cases — an unsupported
#: file and a 4xx fetch are never retried. The sub-case split lives with the owning
#: subsystem; this table records that a retry is *permitted* at all.
RETRYABLE: Final[Mapping[ErrorClass, bool]] = {
    ErrorClass.VALIDATION: False,
    ErrorClass.AUTHENTICATION: False,
    ErrorClass.AUTHORIZATION: False,
    ErrorClass.TENANT_QUOTA: False,
    # True, and the subject matters: this field is CLIENT-facing, so it answers "may the
    # caller retry", not "do we retry internally". We never self-retry a 429 — the client
    # retries once Retry-After has elapsed. Laravel's render closure carries the same value;
    # they must agree, because a consumer cannot tell which plane produced the envelope.
    ErrorClass.RATE_LIMIT: True,
    ErrorClass.PROVIDER_AUTH: False,
    ErrorClass.PROVIDER_RATE_LIMIT: True,  # bounded; Retry-After is a floor, not a hint
    ErrorClass.PROVIDER_BILLING: False,  # an exhausted account will never self-heal
    ErrorClass.PROVIDER_TEMPORARY: True,
    ErrorClass.PROVIDER_PERMANENT_REQUEST: False,
    ErrorClass.RETRIEVAL: True,  # query only — never an index write
    ErrorClass.PARSING: True,
    ErrorClass.OCR: True,
    ErrorClass.CRAWL: True,
    ErrorClass.VECTOR_INDEXING: True,
    ErrorClass.STORAGE: True,
    ErrorClass.INTERNAL_DEPENDENCY: True,  # owning tier only
    ErrorClass.USER_CANCELLATION: False,
}

#: Whether a failed call may be re-issued against a different provider. Fallback runs on
#: the *classified* class, never on a raw provider HTTP status.
#:
#: ``provider_billing`` is False deliberately: an org that configured a fallback authorized
#: it for provider outages, not for quietly moving its spend to a second account because an
#: invoice went unpaid. ``provider_permanent_request`` is False because an unknown model id
#: came from the bot's own configuration snapshot — falling back would serve every answer
#: from a model the tenant did not configure, at a different price and quality, with a
#: Ready bot and no error anywhere.
FALLBACK_ELIGIBLE: Final[Mapping[ErrorClass, bool]] = {
    ErrorClass.PROVIDER_RATE_LIMIT: True,  # only when the bot configured one
    ErrorClass.PROVIDER_TEMPORARY: True,
    **{
        c: False
        for c in ErrorClass
        if c not in (ErrorClass.PROVIDER_RATE_LIMIT, ErrorClass.PROVIDER_TEMPORARY)
    },
}


def status_for(
    error_class: ErrorClass,
    surface: Surface = Surface.ADMIN,
    origin: Origin = Origin.DOWNSTREAM,
) -> int:
    """Render a class as an HTTP status for a given caller.

    Two rows take a second axis, and both defaults are the safe reading:

    * ``authorization`` + a non-admin surface renders 404. Baking a single int here is the
      enumeration-oracle bug: 403 on a foreign identifier confirms the row exists.
    * ``internal_dependency`` + ``Origin.SELF`` renders 500 (ADR-029). Our own defect is
      not a dependency brownout, and 503 tells the client to retry it forever.
    """
    if error_class is ErrorClass.AUTHORIZATION and surface is not Surface.ADMIN:
        return 404
    if error_class is ErrorClass.INTERNAL_DEPENDENCY and origin is Origin.SELF:
        return 500
    return _STATUS[error_class]


def retryable_for(error_class: ErrorClass, origin: Origin = Origin.DOWNSTREAM) -> bool:
    """Whether the CLIENT may retry — the value rendered into the envelope.

    Paired with ``status_for``: the 500/503 split and the retryable/not split are the same
    decision, so they must be derived from the same inputs. Reading ``RETRYABLE`` directly
    at a rendering site is how the two drift back apart.
    """
    if error_class is ErrorClass.INTERNAL_DEPENDENCY and origin is Origin.SELF:
        return False
    return RETRYABLE[error_class]


class KbError(Exception):
    """The one error type every failure path in this service raises.

    ``retryable`` defaults from the table rather than the call site so a subsystem cannot
    quietly promote a permanent failure to a temporary one. Pass it explicitly only to
    *narrow* — a transient-only sub-case of ``parsing`` staying True, an unsupported file
    forcing False.
    """

    __slots__ = ("actionable", "error_class", "message", "origin", "retry_after", "retryable")

    def __init__(
        self,
        error_class: ErrorClass,
        message: str,
        *,
        retryable: bool | None = None,
        retry_after: int | None = None,
        origin: Origin = Origin.DOWNSTREAM,
        actionable: bool = True,
    ) -> None:
        super().__init__(message)
        self.error_class = error_class
        self.message = message
        #: Selects the rendering for ``internal_dependency`` and nothing else (ADR-029).
        #: It is NOT a wire field — it picks the status and the retryable value, both of
        #: which the envelope already carries.
        self.origin = origin
        # Via retryable_for, not RETRYABLE, so a SELF-origin error cannot be constructed as
        # retryable by omission — which is exactly how O1 happened.
        self.retryable = retryable_for(error_class, origin) if retryable is None else retryable
        #: Seconds. Rendered as the ``Retry-After`` header, never as a body field.
        self.retry_after = retry_after
        #: Whether ``message`` was written for THIS condition and may be shown to an operator,
        #: as opposed to being a fixed placeholder chosen to say nothing. Unlike ``origin``
        #: this IS a wire field, because it answers a question no other field answers: a
        #: deliberate 4xx and an unhandled exception both render ``internal_dependency`` with
        #: ``retryable=False``, so a client had no way to tell an actionable refusal from a
        #: defect except by comparing the message against a copy of the placeholder string
        #: (finding J2). It is not a status and nothing may infer one from it.
        #:
        #: Defaults True because every KbError raised in this service carries a message written
        #: for its condition. The one producer that must pass False is
        #: ``main.py::_handle_unexpected``, which is the placeholder by definition. Laravel's
        #: ``bootstrap/app.php`` derives the same field from the arms of its message ``match``;
        #: a consumer cannot tell which plane produced an envelope, so a divergence is a bug.
        self.actionable = actionable

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return (
            f"KbError({self.error_class}, origin={self.origin}, "
            f"retryable={self.retryable}, actionable={self.actionable})"
        )


# Every class has a status, a retry verdict and a fallback verdict, or a lookup at the
# rendering layer raises KeyError inside an exception handler — which is the one place an
# exception cannot be handled. Checked at import, not in a test, because a partially
# populated table must not survive to a running process.
assert set(_STATUS) == set(ErrorClass)
assert set(RETRYABLE) == set(ErrorClass)
assert set(FALLBACK_ELIGIBLE) == set(ErrorClass)
assert len(ErrorClass) == 18

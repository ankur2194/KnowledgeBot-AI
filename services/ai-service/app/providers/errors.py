"""Vendor status / SDK exception / stream error -> ``ErrorClass``.

``ErrorClass`` is imported from ``app.core.errors`` and is **not** redefined here. The
taxonomy stays at 18; a nineteenth entry is a review stop, not a refactor, and a vendor's
own vocabulary never leaks upward past this module. If a vendor returns a condition the
table cannot express, that is a contract gap to report — not a new class, and not a raw
status handed to the router.

Two rules this module exists to enforce, both of which have already cost somebody money
somewhere:

**Fallback runs on the CLASSIFIED class, never on a raw HTTP status.** The class is the row
key; the status is a rendering of it. The same 429 is a rate limit on one code and an
exhausted account on another; the same 503 is capacity on one ``error_type`` and an
unsatisfiable routing block on another; the same 200 carries a refusal, a mid-stream
overload, and a normal answer. Anything that branches on the number is branching on the
least specific evidence available.

**The split between ``provider_temporary`` and ``provider_permanent_request`` is decided on
the VENDOR'S OWN CODE — ``error.type``, ``error.code``, ``error.metadata.error_type``, the
SDK exception class — and never on message text.** Vendor error prose is not a contract and
is reworded without notice. A substring test for ``"unavailable"`` fails in the dangerous
direction: a tenant's mistyped model id gets reclassified as capacity, the bot stays
``Ready``, every turn is served by a model the tenant never chose at another price and
another quality, and the admin discovers it from an invoice months later. Capacity is
retryable and fallback-eligible; an unrecognised model id is a configuration defect the
tenant must see, immediately and specifically.

**Why ``provider_billing`` is its own class.** OpenAI expresses an exhausted account as
**429 ``insufficient_quota``** — the same status as a rate limit, raised by the SDK as the
same ``RateLimitError``. Without the split it lands in ``provider_rate_limit``, which is
retryable *and* fallback-eligible: the one condition that will never self-heal gets the
full backoff ladder, then quietly falls back onto a second account, and nobody is paged.
Anthropic and DeepSeek express the same state as **402**, and OpenRouter as
``payment_required``. The class is never fallback-eligible — an org that configured a
fallback authorized it for vendor *outages*, not for moving its spend to another account
because an invoice went unpaid — and it never counts toward the circuit breaker, because it
is a property of the credential and not a sick dependency.
"""

from __future__ import annotations

import math
import re
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from datetime import UTC, datetime
from email.utils import parsedate_to_datetime
from enum import StrEnum
from typing import Any, Final, Protocol

from app.core.errors import FALLBACK_ELIGIBLE, ErrorClass, KbError

__all__ = [
    "BREAKER_ELIGIBLE",
    "MAX_PLAUSIBLE_RETRY_AFTER_SECONDS",
    "NON_CHAT_FALLBACK_ELIGIBLE",
    "NO_RESET_HEADER_SCHEMA",
    "RESET_HEADERS",
    "UNMAPPED",
    "ProviderCallFailed",
    "ProviderErrorClassifier",
    "ProviderSurface",
    "ResetFormat",
    "ResetHeader",
    "VendorCodeMap",
    "fallback_eligible",
    "retry_after_seconds",
]

#: What an unrecognised vendor code becomes. Unknown classifies as PERMANENT, never
#: temporary: the cost of wrongly-permanent is one visible failure, the cost of
#: wrongly-temporary is an unbounded queue that an operator then retries by hand. An
#: unmapped code also raises an alert, so the map gets fixed rather than absorbing traffic.
UNMAPPED: Final[ErrorClass] = ErrorClass.PROVIDER_PERMANENT_REQUEST

#: The only classes a provider failure may open a breaker on.
#:
#: Everything absent is caller-fault or credential-scoped: one tenant's revoked key, bad
#: model id, exhausted balance or cancelled tab must not take chat down for every tenant.
#: ``PROVIDER_BILLING`` is the one most likely to be added back by someone reasoning that
#: "the provider is unusable, so the breaker should open" — it is unusable for exactly one
#: credential, and opening the breaker hides the page that fixes it.
#:
#: Scoped here rather than in ``app.core.errors`` because only this layer consults it. If a
#: second subsystem ever needs the same set, it moves to core rather than being restated.
BREAKER_ELIGIBLE: Final[frozenset[ErrorClass]] = frozenset(
    {
        ErrorClass.PROVIDER_TEMPORARY,
        ErrorClass.PROVIDER_RATE_LIMIT,
        ErrorClass.RETRIEVAL,
        ErrorClass.STORAGE,
        ErrorClass.INTERNAL_DEPENDENCY,
    }
)

#: A vendor's own code -> our class, per adapter. Each adapter owns its own table and keys
#: it on whatever field that vendor actually populates. Deliberately a plain mapping and not
#: shared code: the five vendors do not agree on where the code lives, and a clever shared
#: extractor would have to guess.
VendorCodeMap = Mapping[str, ErrorClass]


class ProviderSurface(StrEnum):
    """Which family of provider call failed. The second axis on fallback eligibility.

    Same idiom as ``Surface`` on ``authorization`` and ``Origin`` on
    ``internal_dependency``: one table whose reading depends on a second input, with the
    18 classes themselves unchanged. It is not a new class and it changes no status.
    """

    CHAT = "chat"
    EMBEDDING = "embedding"
    RERANK = "rerank"


#: Cross-provider fallback on the non-chat surfaces, as a closed table: **never**.
#:
#: THE TRAP, AND WHY THIS IS A TABLE RATHER THAN A CONVENTION.
#: ---------------------------------------------------------------------------------------
#: ``FALLBACK_ELIGIBLE`` admits ``provider_rate_limit`` and ``provider_temporary``, and both
#: are correct for chat: a second vendor answers the same question, worse or better, and the
#: user gets an answer. Neither is correct for an embedding, because **a different provider
#: is a different vector space**. Falling back mid-ingestion writes points whose cosine
#: distance to every other point in the collection is meaningless.
#:
#: The failure is not loud. A width mismatch would be — Qdrant rejects the upsert. What
#: actually happens is a width COINCIDENCE: ``baai/bge-m3`` (1024) and
#: ``nvidia/nv-embedqa-e5-v5`` (1024) and ``text-embedding-3-large`` truncated to 1024 all
#: upsert cleanly into the same collection, and the only symptom is that some chunks stop
#: being retrievable. Nothing raises, no metric moves, the source version publishes as
#: complete, and the vectors are wrong until somebody re-ingests a corpus nobody knows is
#: damaged. OpenRouter's own RAG guide states the rule from the other side: "Use the same
#: embedding model for indexing and querying. Mixing models produces incompatible vector
#: spaces."
#:
#: Reranking is banned for the same shape of reason one layer up: the scales disagree
#: (``RerankScale``), so a fallback silently changes what the evidence threshold means, and
#: the symptom is a refusal rate that moved in aggregate. This module deliberately quotes no
#: count of how many vendors rerank — ``capabilities.PROVIDER_TASKS`` is the one place that
#: is answered, with a source per cell. A number repeated here is a second copy of the matrix
#: that nothing checks, and the sentence this replaced ("only two vendors rerank at all") was
#: written while ``capabilities.py`` said one; the ban would have read as moot either way, and
#: "moot today" is exactly how a ban stops being enforced.
#:
#: **This bans cross-provider fallback, NOT retry.** An embedding call still retries the SAME
#: connection on ``provider_temporary`` and ``provider_rate_limit`` under ``RETRYABLE``: the
#: same credential and the same model id reproduce the same vector space, so a retry is
#: idempotent in the only sense that matters here. Reading this table as "no retry" would
#: turn every provider blip into a failed ingest.
NON_CHAT_FALLBACK_ELIGIBLE: Final[Mapping[ErrorClass, bool]] = dict.fromkeys(ErrorClass, False)


def fallback_eligible(error_class: ErrorClass, surface: ProviderSurface) -> bool:
    """Whether a failed call may be re-issued against a DIFFERENT provider.

    Structured so that ``True`` is unreachable for anything but chat. A caller cannot get a
    cross-provider embedding fallback by passing the right class, by adding a class, or by
    editing ``FALLBACK_ELIGIBLE`` — the only way is to delete this branch, which is a diff
    that names what it is doing.
    """
    if surface is not ProviderSurface.CHAT:
        return NON_CHAT_FALLBACK_ELIGIBLE[error_class]
    return FALLBACK_ELIGIBLE[error_class]


class ProviderCallFailed(KbError):
    """A classified vendor failure.

    A ``KbError``, so the application's one exception handler renders it with no special
    case. It adds the three things the retry and fallback policy needs and that the base
    class has no room for:

    ``tokens_emitted`` — the gate that outranks every other policy. Once a vendor has
    emitted a token we have been billed and the completion is not idempotent, so a retry
    re-bills and the user watches the answer restart. ``tokens_emitted > 0`` means terminate
    the turn with a stream error event, whatever the class says is allowed.

    ``native_code`` — the vendor's own code, kept verbatim for the classification that
    produced this. It is what a table-driven test asserts against, and it is how we notice
    a vendor added a code.

    ``provider_request_id`` — the only handle a vendor's support will accept, and it is
    missing on precisely the failures worth asking about unless the adapter captures it
    before iterating.

    Never carries the vendor's message text into a log or an envelope: 401 and 422 bodies
    routinely echo the request, and the request carries the packed prompt and sometimes the
    header that authenticated it.
    """

    def __init__(
        self,
        error_class: ErrorClass,
        message: str,
        *,
        tokens_emitted: int = 0,
        native_code: str | None = None,
        provider_request_id: str | None = None,
        retryable: bool | None = None,
        retry_after: int | None = None,
    ) -> None:
        super().__init__(error_class, message, retryable=retryable, retry_after=retry_after)
        self.tokens_emitted = tokens_emitted
        self.native_code = native_code
        self.provider_request_id = provider_request_id

    @property
    def breaker_eligible(self) -> bool:
        """Whether this failure counts toward the circuit breaker.

        ``tokens_emitted`` does not appear here: a failure after first token is still a real
        failure of the dependency. It gates retry and fallback, not the breaker.
        """
        return self.error_class in BREAKER_ELIGIBLE


class ProviderErrorClassifier(Protocol):
    """The half of an adapter the retry loop talks to.

    Split out from ``ProviderAdapter`` because ``call_with_policy`` needs it around the
    ``stream()`` call and must not import a vendor SDK to get it.
    """

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """Map one vendor failure onto the taxonomy.

        Reads the vendor's code first and the HTTP status only as a tiebreak. Three shapes
        every implementation has to handle, and each is a real trap:

        * A **transport** failure with no body at all — connect reset, no first token
          inside the budget. Temporary, and the only evidence is that nothing arrived.
        * A **mid-stream** error inside an HTTP 200. Headers were already committed, so the
          status is a lie; Anthropic raises it against the original 200 response and
          OpenRouter delivers it as an SSE chunk carrying a top-level error object.
        * A **200 that is not an error at all** but is not success either — a refusal, or
          DeepSeek's ``insufficient_system_resource`` arriving as a finish reason.
        """
        ...


class ResetFormat(StrEnum):
    """How one vendor spells a reset time. Three mutually unintelligible answers.

    The split is not cosmetic: read a ``GO_DURATION`` as an ``EPOCH`` and ``6m0s`` is not a
    number at all; read an ``EPOCH`` in milliseconds as one in seconds and the reset lands
    fifty thousand years out. Each value below names a *parser*, and every parser is
    responsible for rejecting the other two forms rather than coercing them.
    """

    #: Go's ``time.Duration.String()`` — ``6m0s``, ``88ms``, ``1.5s``, ``1h0m0s``. Relative,
    #: so it is immune to clock skew, which makes it the only form we can trust unconditionally.
    GO_DURATION = "go_duration"
    #: RFC 3339 instant — ``2026-08-10T12:34:56Z``. Absolute; skew-vulnerable.
    RFC3339 = "rfc3339"
    #: Absolute Unix epoch, seconds *or* milliseconds — the unit is disambiguated by
    #: magnitude, because reading it wrong is not a rounding error. Skew-vulnerable.
    EPOCH = "epoch"


@dataclass(frozen=True, slots=True)
class ResetHeader:
    """One vendor reset header, with the provenance of both facts about it.

    ``source`` is a field rather than a comment on purpose. The header *name* and the header
    *format* used to come from different places for every vendor here — the names were stated
    in our own adapter skills and the value formats were stated nowhere in this repository —
    and the difference is exactly what a reviewer needs to see without leaving the file.

    **Two of the three formats are no longer in that state.** ``openai-api/SKILL.md:189`` and
    ``anthropic-api/SKILL.md:186`` now state the shape of the header name AND the grammar of
    its value, so for those two vendors one citation covers both facts and the ``UNVERIFIED``
    markers that used to sit above their rows are discharged. OpenRouter's unit is still
    unstated and its marker is still there, which is the point of keeping this field: the
    provenance is per row, so discharging one does not quietly discharge the others.
    """

    provider: str
    format: ResetFormat
    source: str


#: Vendor reset headers, keyed by the LOWERCASED header name.
#:
#: The header name is the dispatch key, and it can be, because the five vendors do not share
#: one. That matters here: ``retry_after_seconds`` takes headers and nothing else — the
#: adapter that knows which vendor answered is skeleton under ADR-030's scope line, and this
#: module is scoped to parsing. Keying on the name keeps the vendor split expressible without
#: a provider argument that no caller could supply today.
#:
#: WHAT IS DOCUMENTED AND WHAT IS NOT. The names below are transcribed from our adapter
#: skills. **Two of the three value formats are now stated there as well, and the third is
#: not.** `openai-api/SKILL.md:189` and `anthropic-api/SKILL.md:186` each state the name shape
#: and the value grammar in one sentence; `openrouter-api/SKILL.md:110,153` names the header
#: and not its unit, so that row alone is still UNVERIFIED. The markers are per row on purpose
#: — the three grammars were settled by three separate readings and a blanket note would let
#: one vendor's evidence discharge another's.
#:
#: **The two shapes are mirror images and that is the trap, not a detail.** OpenAI puts
#: `reset` first and the bucket last (`x-ratelimit-reset-{bucket}`); Anthropic puts the bucket
#: in the middle and `-reset` last (`anthropic-ratelimit-{bucket}-reset`). A matcher written
#: against "the `x-ratelimit-reset-*` family" reads **zero** Anthropic headers, and the symptom
#: is not an error: `retry_after_seconds` returns None, the caller falls back to its own
#: backoff, nothing raises and no test fails. That is why dispatch here is on the FULL
#: lowercased header name and never on a `reset` substring.
RESET_HEADERS: Final[Mapping[str, ResetHeader]] = {
    # openai-api/SKILL.md:34-35 lists `x-ratelimit-reset-requests` and
    # `x-ratelimit-reset-tokens` in `_RL_HEADERS`; :189 states BOTH facts in one sentence —
    # "Reset headers here are `x-ratelimit-reset-{bucket}` — `reset` prefixes, the bucket
    # trails — and the value is a Go duration (`6m0s`, `88ms`, `1.5s`)". The `6m0s` in
    # ResetFormat.GO_DURATION's own note is that example, so name and grammar now share one
    # citation. A recorded 429 fixture is still the thing that would settle the sub-second
    # forms (`88ms`, `1.5s`), which the guide gives as examples rather than as a grammar.
    "x-ratelimit-reset-requests": ResetHeader(
        "openai", ResetFormat.GO_DURATION, "openai-api/SKILL.md:34-35,189"
    ),
    "x-ratelimit-reset-tokens": ResetHeader(
        "openai", ResetFormat.GO_DURATION, "openai-api/SKILL.md:34-35,189"
    ),
    # anthropic-api/SKILL.md:186 states the shape (`anthropic-ratelimit-{bucket}-reset`, the
    # bucket an infix and `-reset` a SUFFIX), enumerates the four buckets below (`requests`,
    # `tokens`, `input-tokens`, `output-tokens`), and states the value form as RFC 3339 —
    # explicitly "not OpenAI's Go-duration `6m0s` and not OpenRouter's epoch". An unenumerated
    # fifth bucket still parses, via _ANTHROPIC_RESET below, because the skill documents the
    # *set* as well as its members and a bucket Anthropic adds tomorrow must not silently
    # become unparseable while another vendor's header still must not match.
    "anthropic-ratelimit-requests-reset": ResetHeader(
        "anthropic", ResetFormat.RFC3339, "anthropic-api/SKILL.md:186"
    ),
    "anthropic-ratelimit-tokens-reset": ResetHeader(
        "anthropic", ResetFormat.RFC3339, "anthropic-api/SKILL.md:186"
    ),
    "anthropic-ratelimit-input-tokens-reset": ResetHeader(
        "anthropic", ResetFormat.RFC3339, "anthropic-api/SKILL.md:186"
    ),
    "anthropic-ratelimit-output-tokens-reset": ResetHeader(
        "anthropic", ResetFormat.RFC3339, "anthropic-api/SKILL.md:186"
    ),
    # openrouter-api/SKILL.md:110 and :153 name `X-RateLimit-Limit/Remaining/Reset`.
    # <!-- UNVERIFIED: the skill names the header but not its unit. It is read here as an
    # absolute epoch with the unit inferred from magnitude, which is correct whether
    # OpenRouter emits seconds or milliseconds and refuses rather than guesses if it emits
    # neither.
    # DO NOT discharge this against `anthropic-api/SKILL.md:186`. That line does say "not
    # OpenRouter's epoch", but it says so while describing THIS table — it is our own reading
    # quoted back at us, so treating it as evidence would close the row on a circular
    # citation. Only OpenRouter's own limits page or a recorded 429 settles it. -->
    "x-ratelimit-reset": ResetHeader(
        "openrouter", ResetFormat.EPOCH, "openrouter-api/SKILL.md:110,153"
    ),
}

#: Anthropic documents a header *set* as well as its current members, so match the family and
#: not only the four names above. A bucket Anthropic adds tomorrow parses; a header from
#: another vendor does not. The pattern is the same one `anthropic-api/SKILL.md:186` names.
_ANTHROPIC_RESET: Final[re.Pattern[str]] = re.compile(r"anthropic-ratelimit-[a-z0-9-]+-reset")

#: Providers that publish NO rate-limit header schema at all. This is a recorded fact, not a
#: gap: DeepSeek returns a bare 429 with no headers and no `Retry-After`
#: (deepseek-api/SKILL.md:162), and NVIDIA publishes no 429-header schema for the hosted
#: catalog (nvidia-nim-api/SKILL.md:30,147). Both skills instruct leaving
#: `Diagnostics.rate_limit` empty rather than fabricating a reset time.
#:
#: Asserted against `RESET_HEADERS` at import: the moment somebody adds a plausible-looking
#: DeepSeek or NIM header the disjointness fails, which is the only way to stop a guess from
#: being added here later and read as documentation by everyone after.
NO_RESET_HEADER_SCHEMA: Final[frozenset[str]] = frozenset({"deepseek", "nvidia_nim"})

#: The bound past which any hint is discarded, in seconds. One hour.
#:
#: It has to sit in a gap, and there is a wide one. Below it: every reset window we could
#: legitimately honour. OpenAI's RPM/TPM, Anthropic's ITPM/OTPM and OpenRouter's per-key
#: window are all per-MINUTE, so an hour is two orders of magnitude of headroom and discards
#: no real hint. Above it: everything the failure modes produce. An epoch in milliseconds
#: read as seconds lands ~50,000 years out; a year typo in an RFC 3339 instant lands decades
#: out; a host whose clock is a day off lands a day out.
#:
#: The asymmetry is what justifies discarding rather than clamping. This value is a FLOOR
#: under the jittered backoff, so a wrong-large value is not "slightly slow" — it outranks
#: the backoff entirely and pins the caller inside the rejection window until the deadline
#: kills the turn, which is precisely the failure `kb-error-taxonomy` describes as a 429 rate
#: staying pinned at 100% long after traffic drops. A wrong-small value costs one extra
#: request. Returning None hands the decision back to the full-jitter ladder, which is always
#: a defensible sleep; clamping to 3600 would assert an hour we have no evidence for.
#:
#: Nothing in-request can wait this long anyway — the chat deadline is 60 s and the provider
#: budget 45 s (kb-error-taxonomy, timeout table) — so the only consumer of a value near the
#: bound is the client-facing `Retry-After` header rendered by `app/main.py`.
MAX_PLAUSIBLE_RETRY_AFTER_SECONDS: Final[int] = 3600

#: RFC 9110 §10.2.3 delta-seconds is `1*DIGIT`: no sign, no fraction. The optional fraction
#: is tolerated because origin servers emit it and the intent is unambiguous; the absent sign
#: is not, because a negative delta is not a shorter wait, it is a value we did not
#: understand. `[0-9]` rather than `\d`, which also matches Arabic-Indic digits under Unicode.
_DELTA_SECONDS: Final[re.Pattern[str]] = re.compile(r"[0-9]+(?:\.[0-9]+)?")

#: One `<number><unit>` term of a Go duration. `ms` precedes `s` in the alternation and `ns`
#: and `us` precede `m`, so `88ms` reads as 88 milliseconds rather than 88 metres of nothing
#: followed by an unconsumed `s`.
_DURATION_TERM: Final[re.Pattern[str]] = re.compile(r"([0-9]+(?:\.[0-9]+)?)(ns|us|µs|ms|s|m|h)")

_DURATION_UNITS: Final[Mapping[str, float]] = {
    "ns": 1e-9,
    "us": 1e-6,
    "µs": 1e-6,
    "ms": 1e-3,
    "s": 1.0,
    "m": 60.0,
    "h": 3600.0,
}

#: Epoch values at or above this are milliseconds; below, seconds. 1e11 seconds is the year
#: 5138 and 1e11 milliseconds is 1973, so no plausible present-day value of either unit is
#: near the boundary — the two candidate readings of a real header are ~1.7e9 and ~1.7e12,
#: three orders of magnitude either side. This is a disambiguation, not a guess: whichever
#: unit a vendor picked, the magnitude says which, and a value that is neither (a bare `60`
#: meaning "sixty seconds from now") resolves to an instant in 1970 and is rejected by the
#: plausibility bound rather than silently reinterpreted as a duration we were not told about.
_EPOCH_MILLISECONDS_FLOOR: Final[float] = 1e11


def _bounded(seconds: float) -> int | None:
    """Ceiling to whole seconds, floored at 0, or None past the plausibility bound.

    Ceiling rather than rounding: OpenAI's `88ms` must not become `0`, which would mean
    "retry immediately" — the same wrong answer the absent-not-zero rule exists to prevent,
    arrived at by arithmetic instead of by omission.
    """
    if seconds > MAX_PLAUSIBLE_RETRY_AFTER_SECONDS:
        return None
    return max(0, math.ceil(seconds))


def _seconds_until(instant: datetime, now: datetime) -> int | None:
    """An absolute instant as a wait, or None if it is absurd in either direction.

    Both directions, and symmetrically, because both have the same causes — a skewed clock, a
    misread unit, a wrong year — and only the sign differs. A reset an hour in the *past* is
    no more a reset this response is describing than one an hour in the future; treating it
    as `0` would return "retry immediately" on evidence we have just decided is unreliable.
    A reset a few minutes past is ordinary (the window elapsed while the response was in
    flight) and correctly yields 0.
    """
    delta = (instant - now).total_seconds()
    if delta < -MAX_PLAUSIBLE_RETRY_AFTER_SECONDS:
        return None
    return _bounded(delta)


def _parse_http_retry_after(value: str, now: datetime) -> int | None:
    """RFC 9110 §10.2.3 `Retry-After`: delta-seconds or an HTTP-date.

    The HTTP-date form is the one implementations forget, and a vendor under load is exactly
    when it appears — a fronting CDN or load balancer emits a date where the origin emits a
    count, so the form flips precisely during the incident the parser exists for. Dropping it
    is not a parse failure anyone sees; it is a silently absent floor.
    """
    text = value.strip()
    if not text:
        return None

    if _DELTA_SECONDS.fullmatch(text):
        return _bounded(float(text))

    try:
        instant = parsedate_to_datetime(text)
    except (TypeError, ValueError):
        return None
    if instant.tzinfo is None:
        # `-0000` parses naive. RFC 9110 §5.6.7 requires HTTP-date to be GMT, so UTC is the
        # only reading; guessing the local zone here would make the answer depend on TZ.
        instant = instant.replace(tzinfo=UTC)
    return _seconds_until(instant, now)


def _parse_go_duration(value: str, now: datetime) -> int | None:
    """OpenAI's reset headers: a Go `time.Duration` string.

    Relative, so `now` is unused — the parameter is here only so every format has one
    signature and the dispatch table stays a table. See ResetFormat.GO_DURATION.
    """
    text = value.strip()
    if not text:
        return None

    total = 0.0
    position = 0
    for term in _DURATION_TERM.finditer(text):
        if term.start() != position:
            return None  # unparseable filler between terms
        total += float(term.group(1)) * _DURATION_UNITS[term.group(2)]
        position = term.end()
    if position != len(text):
        return None  # trailing garbage, or nothing matched at all
    return _bounded(total)


def _parse_rfc3339(value: str, now: datetime) -> int | None:
    """Anthropic's reset headers: an RFC 3339 instant.

    A value without an offset is rejected rather than assumed UTC. RFC 3339 requires one, so
    its absence means we misread the value — most likely a date-only string, which
    `fromisoformat` accepts happily and resolves to midnight, an answer that is wrong by up
    to a day in whichever direction the local reading falls.
    """
    text = value.strip()
    if not text:
        return None
    try:
        instant = datetime.fromisoformat(text)
    except ValueError:
        return None
    if instant.tzinfo is None:
        return None
    return _seconds_until(instant, now)


def _parse_epoch(value: str, now: datetime) -> int | None:
    """OpenRouter's reset header: an absolute epoch, unit inferred from magnitude."""
    text = value.strip()
    if not _DELTA_SECONDS.fullmatch(text):
        return None
    raw = float(text)
    epoch = raw / 1000.0 if raw >= _EPOCH_MILLISECONDS_FLOOR else raw
    try:
        instant = datetime.fromtimestamp(epoch, tz=UTC)
    except (OSError, OverflowError, ValueError):
        # A unit error large enough to leave the representable range: microseconds read as
        # milliseconds is year 58569, and `fromtimestamp` raises rather than returning
        # something absurd for the bound to catch. The absurdity check below never runs on
        # these, so the guard has to be here — this is the same rejection, one step earlier.
        return None
    return _seconds_until(instant, now)


_PARSERS: Final[Mapping[ResetFormat, Callable[[str, datetime], int | None]]] = {
    ResetFormat.GO_DURATION: _parse_go_duration,
    ResetFormat.RFC3339: _parse_rfc3339,
    ResetFormat.EPOCH: _parse_epoch,
}


def _reset_format(name: str) -> ResetFormat | None:
    header = RESET_HEADERS.get(name)
    if header is not None:
        return header.format
    if _ANTHROPIC_RESET.fullmatch(name):
        return ResetFormat.RFC3339
    return None


def retry_after_seconds(headers: Mapping[str, str] | Any) -> int | None:
    """Parse the vendor's own reset hint into whole seconds, or None.

    Returned to the policy loop as a **floor** under the jittered backoff, never as the
    sleep itself: obeying a bare hint synchronizes every caller onto the same instant and
    rebuilds the burst that produced the limit.

    Returns None — not a guess — when the vendor publishes no header schema. DeepSeek and
    the NVIDIA catalog both do that, and a fabricated reset time outranks the backoff and
    pins the caller inside the rejection window indefinitely.

    Precedence, and why it is not "first header wins":

    1. **`Retry-After`, if it parses.** It is the vendor's own considered answer to this
       question, in a form standardised since RFC 7231, and it is the only header here whose
       meaning does not depend on knowing which vendor sent it.
    2. Otherwise the **largest** of the recognised reset headers. A vendor meters several
       buckets at once — OpenAI sends a requests reset and a tokens reset on the same
       response — and nothing in the headers says which bucket rejected us. The limit is not
       cleared until every exhausted bucket has reset, so the shorter of two windows is a
       floor that reissues *into* the rejection window; those attempts still consume the
       provider's slot, which is how a 429 rate stays pinned at 100% long after traffic drops
       (`kb-error-taxonomy`). Over-waiting cannot do that, and cannot overrun the budget
       either: `call_with_policy` checks the remaining deadline before every attempt, so a
       floor larger than what is left ends the turn as `provider_temporary` — which is
       fallback-eligible — instead of sleeping past it.

    Zero and None are different answers and both are reachable. **0** means "the window has
    already elapsed", which is a real thing for an absolute reset that expired while the
    response was in flight; as a floor under full jitter it costs nothing. **None** means
    "no answer", and it is what every unparseable, absurd, or undocumented input produces.
    Never collapse the second into the first: `app/main.py` renders any non-None value as the
    `Retry-After` response header, so a fabricated `0` tells every client to retry
    immediately — the inverse of what an unreadable header should say.
    """
    if not isinstance(headers, Mapping):
        # httpx.Headers and the SDK exceptions' `.response.headers` are Mappings; an SDK
        # exception itself, a None from a transport failure with no response at all, or a
        # list of tuples is not. Unwrapping `.response.headers` is the adapter's job — this
        # function is scoped to parsing and must not reach into a vendor object to find it.
        return None

    normalized: dict[str, str] = {}
    for key, value in headers.items():
        if isinstance(key, str) and isinstance(value, str):
            normalized[key.lower()] = value

    direct = normalized.get("retry-after")
    now = datetime.now(UTC)
    if direct is not None:
        parsed = _parse_http_retry_after(direct, now)
        if parsed is not None:
            return parsed

    best: int | None = None
    for name, value in normalized.items():
        fmt = _reset_format(name)
        if fmt is None:
            continue
        candidate: int | None = _PARSERS[fmt](value, now)
        if candidate is not None and (best is None or candidate > best):
            best = candidate
    return best


# Total and uniformly False, checked at import for the same reason ``app/core/errors.py``
# checks its own tables there: a partially populated table must not reach a running process,
# and a lookup that raises KeyError inside an exception handler cannot be handled. The second
# assertion is the one with teeth — it says the ban is a property of the table rather than of
# the comprehension that happened to build it today.
assert set(NON_CHAT_FALLBACK_ELIGIBLE) == set(ErrorClass)
assert not any(NON_CHAT_FALLBACK_ELIGIBLE.values())

# A provider that publishes no header schema must own no header. Checked here rather than in
# a test because the failure it guards is additive and looks harmless in review: somebody
# writes a plausible `x-deepseek-ratelimit-reset` row from memory, and from then on the table
# reads as documentation to everybody who comes after it. Every key is lowercase for the same
# reason `retry_after_seconds` lowercases its input — one side doing it is not enough.
assert all(name == name.lower() for name in RESET_HEADERS)
assert {header.provider for header in RESET_HEADERS.values()}.isdisjoint(NO_RESET_HEADER_SCHEMA)
assert set(_PARSERS) == set(ResetFormat)

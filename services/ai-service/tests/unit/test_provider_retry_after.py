"""``retry_after_seconds``: five vendors, three spellings of a reset time, one integer.

The value this function returns is not internal. ``app/main.py:88`` renders any non-None
result straight into the ``Retry-After`` response header, Laravel relays it, and
``packages/contracts`` parses it back out into ``KbError.retry_after`` on every client. It is
also the floor under the provider retry ladder (``kb-error-taxonomy``'s ``call_with_policy``),
so a wrong-large value does not read as "slightly slow" — it outranks the jittered backoff
entirely and holds the caller inside the rejection window until the deadline kills the turn.

Three properties carry that weight, and each has its own section below:

* **Absent is not zero.** ``test_retry_after_defaults_to_absent_rather_than_zero`` in
  ``test_error_taxonomy.py`` pins the same principle one layer down, on the field this feeds.
  ``0`` is a real answer meaning "the window already elapsed"; ``None`` means "no answer".
  Every unparseable, absurd or undocumented input must produce the second, because the first
  renders ``Retry-After: 0`` and tells every client to retry immediately.
* **Never invent a schema.** DeepSeek and the NVIDIA catalog publish none. A fabricated
  default there is worse than no hint at all, and both skills say so explicitly.
* **Absolute forms are skew-vulnerable and relative ones are not.** Every test that involves
  a clock freezes it, because otherwise the assertion depends on which side of a second the
  test ran — and the one bug this parser is most likely to grow is an off-by-one from
  rounding instead of ceiling.

No network, no SDK object, no credential: this is pure header parsing against hand-built
headers in the vendors' own wire spellings.
"""

from __future__ import annotations

import re
from datetime import UTC, datetime, timedelta

import httpx
import pytest
from freezegun import freeze_time

from app.providers.errors import (
    MAX_PLAUSIBLE_RETRY_AFTER_SECONDS,
    NO_RESET_HEADER_SCHEMA,
    RESET_HEADERS,
    ResetFormat,
    retry_after_seconds,
)

#: A fixed instant every clock-dependent test freezes to. Deliberately not "now": a test that
#: reads the wall clock to build its own fixture cannot fail an off-by-one, because it moves
#: with the code.
NOW = datetime(2026, 8, 10, 12, 0, 0, tzinfo=UTC)
NOW_ISO = "2026-08-10T12:00:00Z"
NOW_EPOCH = int(NOW.timestamp())
#: The same instant in the RFC 9110 §5.6.7 IMF-fixdate spelling an origin server emits.
NOW_HTTP_DATE = "Mon, 10 Aug 2026 12:00:00 GMT"


# ── RFC 9110 §10.2.3: Retry-After, delta-seconds form ─────────────────────────────────────


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("120", 120),
        ("1", 1),
        ("0", 0),
        ("3600", 3600),
        # Not RFC-legal (delta-seconds is `1*DIGIT`) but emitted in the wild. Ceiled, never
        # rounded: 1.2 s of remaining window must not become 1 s of permission.
        ("1.2", 2),
        ("0.001", 1),
    ],
)
def test_delta_seconds_is_read_as_a_count(value: str, expected: int) -> None:
    assert retry_after_seconds({"Retry-After": value}) == expected


def test_a_signed_delta_is_not_understood_rather_than_clamped() -> None:
    """RFC 9110 admits no sign. A negative value is not "zero seconds from now" — it is a
    value we did not understand, and clamping it to 0 would launder that into permission to
    retry immediately."""
    assert retry_after_seconds({"Retry-After": "-5"}) is None


# ── RFC 9110 §10.2.3: Retry-After, HTTP-date form ─────────────────────────────────────────
#
# The form implementations forget. A fronting CDN or load balancer emits a date where the
# origin emits a count, so which form arrives can flip *during* the incident the parser exists
# for — and dropping it produces no parse error anybody sees, just a silently absent floor.


@freeze_time(NOW)
def test_an_http_date_in_the_future_is_the_seconds_until_it() -> None:
    future = NOW + timedelta(seconds=90)
    header = future.strftime("%a, %d %b %Y %H:%M:%S GMT")
    assert retry_after_seconds({"Retry-After": header}) == 90


@freeze_time(NOW)
def test_an_http_date_at_the_present_instant_is_zero() -> None:
    assert retry_after_seconds({"Retry-After": NOW_HTTP_DATE}) == 0


@freeze_time(NOW)
def test_an_http_date_in_the_past_floors_at_zero_rather_than_going_negative() -> None:
    """Ordinary, not pathological: the window elapsed while the response was in flight. A
    negative floor would be handed to ``max(floor, backoff)`` and silently ignored, which is
    the same outcome — but it would also be rendered into the header as ``Retry-After: -30``,
    which is not a legal field value."""
    past = NOW - timedelta(seconds=30)
    header = past.strftime("%a, %d %b %Y %H:%M:%S GMT")
    assert retry_after_seconds({"Retry-After": header}) == 0


@freeze_time(NOW)
def test_a_sub_second_remainder_ceils_to_one_rather_than_rounding_to_zero() -> None:
    """The ceiling boundary, asserted on every path that can produce a fraction.

    ``round`` returns 0 for all three and the caller retries into a window that has not
    opened. The IMF-fixdate spelling carries no sub-second field, so the HTTP-date form is
    pinned at its own smallest step instead.
    """
    assert retry_after_seconds({"Retry-After": "0.001"}) == 1
    assert retry_after_seconds({"x-ratelimit-reset-requests": "88ms"}) == 1
    one_second = (NOW + timedelta(seconds=1)).strftime("%a, %d %b %Y %H:%M:%S GMT")
    assert retry_after_seconds({"Retry-After": one_second}) == 1


@freeze_time(NOW)
def test_a_naive_http_date_is_read_as_gmt_and_not_as_local_time() -> None:
    """``-0000`` parses to a naive datetime. RFC 9110 §5.6.7 requires GMT, so UTC is the only
    reading — assuming the local zone would make the answer depend on the container's TZ,
    which differs between a developer's laptop and CI by hours."""
    future = (NOW + timedelta(seconds=60)).strftime("%a, %d %b %Y %H:%M:%S -0000")
    assert retry_after_seconds({"Retry-After": future}) == 60


# ── Vendor reset headers, in each vendor's own spelling ───────────────────────────────────


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("6m0s", 360),
        ("88ms", 1),  # ceiled, not floored — see the fractional-second test above
        ("1.5s", 2),
        ("1h0m0s", 3600),
        ("2m59.56s", 180),
        ("500ms", 1),
        ("0s", 0),
    ],
)
def test_openai_ships_a_go_duration_string(value: str, expected: int) -> None:
    """OpenAI's ``x-ratelimit-reset-*`` values are Go ``time.Duration`` strings.

    Relative, which makes them the only reset form here immune to clock skew. The unit
    alternation is the trap: ``88ms`` read with ``s`` matched first is 88 *seconds*, an
    88x over-wait that fits comfortably inside the plausibility bound and so is never caught.
    """
    assert retry_after_seconds({"x-ratelimit-reset-requests": value}) == expected


@pytest.mark.parametrize("value", ["6m0x", "m0s", "6", "", "6 m", "-5s", "6m 0s", "6m0s5"])
def test_a_malformed_duration_is_not_partially_consumed(value: str) -> None:
    """Every one of these has a prefix or a suffix that a lenient parser would take and the
    rest of which it would drop. Consuming ``6`` out of ``6m0x`` and answering 6 seconds is a
    plausible-looking number derived from a string we could not read. ``6`` on its own is the
    one that matters most: it is a legal *delta-seconds*, and accepting it here would mean the
    parser had stopped distinguishing OpenAI's grammar from RFC 9110's."""
    assert retry_after_seconds({"x-ratelimit-reset-tokens": value}) is None


def test_surrounding_whitespace_is_stripped_but_internal_filler_is_not() -> None:
    """Header values arrive with the optional whitespace RFC 9110 §5.5 permits around them,
    and a proxy that re-serialises can add more. Stripping the edges is not leniency; taking a
    value apart around interior spaces would be."""
    assert retry_after_seconds({"x-ratelimit-reset-tokens": " 6m0s "}) == 360
    assert retry_after_seconds({"Retry-After": "  120  "}) == 120


@freeze_time(NOW)
def test_anthropic_ships_rfc3339() -> None:
    future = (NOW + timedelta(seconds=45)).isoformat().replace("+00:00", "Z")
    assert retry_after_seconds({"anthropic-ratelimit-requests-reset": future}) == 45


@freeze_time(NOW)
@pytest.mark.parametrize(
    "name",
    [
        "anthropic-ratelimit-requests-reset",
        "anthropic-ratelimit-tokens-reset",
        "anthropic-ratelimit-input-tokens-reset",
        "anthropic-ratelimit-output-tokens-reset",
        # Not in the table. `anthropic-api/SKILL.md:186` documents a header *set*, not a list,
        # so a bucket Anthropic adds tomorrow must parse rather than silently vanish.
        "anthropic-ratelimit-cache-tokens-reset",
    ],
)
def test_every_anthropic_bucket_in_the_family_parses(name: str) -> None:
    future = (NOW + timedelta(seconds=30)).isoformat().replace("+00:00", "Z")
    assert retry_after_seconds({name: future}) == 30


@freeze_time(NOW)
def test_an_rfc3339_value_without_an_offset_is_refused_rather_than_assumed_utc() -> None:
    """``fromisoformat`` accepts a date-only string and resolves it to midnight — an answer
    wrong by up to a day, in whichever direction the reading falls. RFC 3339 requires an
    offset, so its absence means we misread the value."""
    assert retry_after_seconds({"anthropic-ratelimit-tokens-reset": "2026-08-10"}) is None
    assert retry_after_seconds({"anthropic-ratelimit-tokens-reset": "2026-08-10T12:01:00"}) is None


@freeze_time(NOW)
@pytest.mark.parametrize(
    ("value", "expected"),
    [
        (str(NOW_EPOCH + 75), 75),  # seconds
        (str((NOW_EPOCH + 75) * 1000), 75),  # milliseconds
        (str(NOW_EPOCH), 0),  # already elapsed
        (str(NOW_EPOCH - 20), 0),
    ],
)
def test_an_absolute_epoch_is_read_in_whichever_unit_its_magnitude_implies(
    value: str, expected: int
) -> None:
    """OpenRouter's ``X-RateLimit-Reset``. The skill names the header and not its unit, so the
    unit is inferred: ~1.7e9 and ~1.7e12 are three orders of magnitude either side of the
    boundary, which makes this a disambiguation rather than a guess. Reading milliseconds as
    seconds lands the reset ~50,000 years out — caught by the bound, but only because the
    bound exists."""
    assert retry_after_seconds({"X-RateLimit-Reset": value}) == expected


@freeze_time(NOW)
def test_an_epoch_header_carrying_a_bare_duration_is_refused_not_reinterpreted() -> None:
    """``60`` in a header we were told is absolute resolves to 1970. Answering ``0`` would be
    "retry immediately" on a value we just decided we cannot read, and answering ``60`` would
    invent a relative reading no skill documents for this vendor."""
    assert retry_after_seconds({"x-ratelimit-reset": "60"}) is None


# ── The two vendors that publish nothing ──────────────────────────────────────────────────


def test_a_deepseek_429_has_no_headers_to_read_and_produces_no_hint() -> None:
    """``deepseek-api/SKILL.md:162``: no rate-limit headers, no ``Retry-After``, no request-id.
    Backoff there is blind full-jitter, and that is correct — an invented reset time outranks
    the jitter and pins the caller inside the rejection window."""
    deepseek_429 = {
        "date": "Mon, 10 Aug 2026 12:00:00 GMT",
        "content-type": "application/json",
        "access-control-allow-credentials": "true",
    }
    assert retry_after_seconds(deepseek_429) is None


def test_a_nim_429_has_no_published_header_schema_and_produces_no_hint() -> None:
    """``nvidia-nim-api/SKILL.md:30,147``: NVIDIA publishes no rate-limit or 429-header schema
    for the hosted catalog, and the error map says to leave ``Diagnostics.rate_limit`` empty."""
    nim_429 = {"date": "Mon, 10 Aug 2026 12:00:00 GMT", "content-type": "application/json"}
    assert retry_after_seconds(nim_429) is None


def test_no_provider_without_a_published_schema_owns_a_header() -> None:
    """The structural form of the rule above, and the one that survives somebody adding a
    plausible-looking ``x-deepseek-ratelimit-reset`` row from memory. Once a guess is in the
    table it reads as documentation to everybody after."""
    assert set(NO_RESET_HEADER_SCHEMA) == {"deepseek", "nvidia_nim"}
    owners = {header.provider for header in RESET_HEADERS.values()}
    assert owners.isdisjoint(NO_RESET_HEADER_SCHEMA)


def test_every_table_entry_records_where_its_header_name_came_from() -> None:
    """The header names are transcribed from skills. **Two of the three value formats are now
    transcribed from skills as well** — ``openai-api/SKILL.md:189`` and
    ``anthropic-api/SKILL.md:186`` each state the name shape and the value grammar in one
    sentence — and OpenRouter's unit is still unstated and still carries its UNVERIFIED marker.
    Carrying the provenance in the row per vendor is what keeps one vendor's evidence from
    quietly discharging another's."""
    for name, header in RESET_HEADERS.items():
        assert name == name.lower(), name
        assert header.source, name
        assert isinstance(header.format, ResetFormat), name


def test_the_two_reset_name_shapes_are_mirror_images() -> None:
    """OpenAI prefixes ``reset``; Anthropic suffixes it. **Neither matcher reads the other.**

    This is the failure the two discharged UNVERIFIED markers were about, and its whole danger
    is that it is silent. ``openai-api/SKILL.md:189`` gives the shape as
    ``x-ratelimit-reset-{bucket}`` — ``reset`` first, bucket trailing.
    ``anthropic-api/SKILL.md:186`` gives it as ``anthropic-ratelimit-{bucket}-reset`` — bucket
    infix, ``-reset`` trailing — and says in as many words that an adapter written against
    "the ``x-ratelimit-reset-*`` family" reads **zero** Anthropic headers, with no error to
    catch: ``retry_after_seconds`` returns ``None``, the caller falls back to its own backoff,
    nothing raises and no test fails.

    So this asserts the shapes as data and the disjointness as a set relation, rather than
    spot-checking two names. A substring dispatch on ``reset`` would pass a name check and
    still be the bug, which is why the table keys on the full lowercased header name.
    """
    openai_shape = re.compile(r"^x-ratelimit-reset-[a-z0-9-]+$")
    anthropic_shape = re.compile(r"^anthropic-ratelimit-[a-z0-9-]+-reset$")

    by_provider: dict[str, set[str]] = {}
    for name, header in RESET_HEADERS.items():
        by_provider.setdefault(header.provider, set()).add(name)

    assert by_provider["openai"] == {n for n in RESET_HEADERS if openai_shape.fullmatch(n)}
    assert by_provider["anthropic"] == {n for n in RESET_HEADERS if anthropic_shape.fullmatch(n)}

    # The mirror image, stated as the emptiness that matters. Anthropic's four buckets are the
    # ones an OpenAI-family matcher would miss, and OpenRouter's bare `x-ratelimit-reset` is
    # the one an Anthropic-family matcher would miss.
    assert not any(openai_shape.fullmatch(n) for n in by_provider["anthropic"])
    assert not any(anthropic_shape.fullmatch(n) for n in by_provider["openai"])
    assert not any(n.startswith("x-ratelimit-reset-") for n in by_provider["anthropic"])

    # Anthropic's four buckets, named. `anthropic-api/SKILL.md:186` enumerates exactly these,
    # and the family pattern below them is what lets a fifth arrive without an edit.
    assert by_provider["anthropic"] == {
        "anthropic-ratelimit-requests-reset",
        "anthropic-ratelimit-tokens-reset",
        "anthropic-ratelimit-input-tokens-reset",
        "anthropic-ratelimit-output-tokens-reset",
    }


@freeze_time(NOW)
def test_an_openai_family_matcher_would_read_nothing_from_an_anthropic_429() -> None:
    """The same claim as a behaviour, on a header set with no OpenAI header in it at all.

    The table reads it; the naive prefix family does not. Both halves are asserted, because
    "the prefix family finds nothing" is trivially true of a header set the real parser also
    cannot read, and that vacuous version is the one a reader would believe.
    """
    future = (NOW + timedelta(seconds=42)).isoformat().replace("+00:00", "Z")
    anthropic_429 = {
        "anthropic-ratelimit-requests-remaining": "0",
        "anthropic-ratelimit-input-tokens-reset": future,
        "request-id": "req_011CS",
    }
    assert retry_after_seconds(anthropic_429) == 42
    assert not [n for n in anthropic_429 if n.startswith("x-ratelimit-reset")]


def test_each_vendor_owns_exactly_one_reset_grammar_and_no_two_vendors_share_one() -> None:
    """Three vendors, three formats, no overlap — which is what makes a shared parser wrong.

    The formats are no longer inferred here: OpenAI's Go duration and Anthropic's RFC 3339 are
    both stated in the cited skill lines, and OpenRouter's epoch is the one still read from a
    magnitude rather than from a document. Pinning the partition means a future edit that
    "unifies" two vendors onto one parser has to delete an assertion rather than change a
    default.
    """
    formats: dict[str, set[ResetFormat]] = {}
    for header in RESET_HEADERS.values():
        formats.setdefault(header.provider, set()).add(header.format)

    assert formats == {
        "openai": {ResetFormat.GO_DURATION},
        "anthropic": {ResetFormat.RFC3339},
        "openrouter": {ResetFormat.EPOCH},
    }
    # Asserted as a partition and not only as a mapping: three single-element sets that
    # happened to hold the same member would still satisfy the dict above if one vendor's
    # expected value were edited to match another's.
    assert len({fmt for fmts in formats.values() for fmt in fmts}) == 3


# ── Absurd, unparseable, and not-a-mapping ────────────────────────────────────────────────


@freeze_time(NOW)
@pytest.mark.parametrize(
    ("name", "value"),
    [
        # A host whose clock is a decade behind ours; the reset reads as a decade out.
        ("x-ratelimit-reset", str(NOW_EPOCH + 10 * 365 * 86400)),
        # Milliseconds read as seconds by a vendor that changed units: ~50,000 years.
        ("x-ratelimit-reset", "1786104000000000"),
        # A year typo in RFC 3339.
        ("anthropic-ratelimit-tokens-reset", "2126-08-10T12:00:00Z"),
        # The same skew in the opposite direction, which has the same causes and is no more
        # trustworthy for having a negative sign.
        ("anthropic-ratelimit-tokens-reset", "1926-08-10T12:00:00Z"),
        # An HTTP-date from a clock a day ahead.
        ("retry-after", "Tue, 11 Aug 2026 12:00:00 GMT"),
        # A relative form does not escape the bound either: a day is not a floor, it is a
        # refusal wearing a number.
        ("retry-after", "86400"),
        ("x-ratelimit-reset-requests", "24h0m0s"),
    ],
)
def test_an_absurd_reset_is_discarded_rather_than_clamped(name: str, value: str) -> None:
    """The failure the docstring names: a skewed absolute reset outranks the backoff and pins
    the caller inside the rejection window indefinitely.

    Discarded, not clamped, and the asymmetry is the argument. A wrong-small floor costs one
    extra request; a wrong-large one costs the whole turn. ``None`` hands the decision back to
    the full-jitter ladder, which is always a defensible sleep; clamping to the bound would
    assert an hour we have no evidence for and would render ``Retry-After: 3600`` to a client.
    """
    assert retry_after_seconds({name: value}) is None


def test_the_bound_sits_in_the_gap_between_real_windows_and_skew() -> None:
    """Two orders of magnitude above every per-minute reset window we could honour, and
    orders of magnitude below what a misread unit or a skewed clock produces. It is also far
    past useful: the chat deadline is 60 s and the provider budget 45 s."""
    assert MAX_PLAUSIBLE_RETRY_AFTER_SECONDS == 3600
    assert retry_after_seconds({"Retry-After": str(MAX_PLAUSIBLE_RETRY_AFTER_SECONDS)}) == 3600
    assert retry_after_seconds({"Retry-After": str(MAX_PLAUSIBLE_RETRY_AFTER_SECONDS + 1)}) is None


@pytest.mark.parametrize(
    "value", ["", "   ", "soon", "later, maybe", "NaN", "1e3", "0x10", "١٢٠", "6m0s"]
)
def test_an_unparseable_retry_after_yields_no_hint_at_all(value: str) -> None:
    """``١٢٠`` is Arabic-Indic 120: ``\\d`` matches it under Unicode and ``float`` accepts it,
    so a parser written with ``\\d`` silently honours a header no vendor sent. ``6m0s`` is
    OpenAI's *reset* grammar arriving in the standardised field, where it is not legal — a
    parser that accepts it here has stopped distinguishing the two schemas."""
    assert retry_after_seconds({"Retry-After": value}) is None


@pytest.mark.parametrize(
    "headers",
    [
        None,
        "Retry-After: 30",
        [("retry-after", "30")],
        30,
        object(),
        # An SDK exception object. Unwrapping `.response.headers` is the adapter's job; this
        # function is scoped to parsing and must not reach into a vendor object to find them.
        httpx.HTTPStatusError(
            "429",
            request=httpx.Request("POST", "https://api.openai.com/v1/responses"),
            response=httpx.Response(429, headers={"retry-after": "30"}),
        ),
    ],
)
def test_anything_that_is_not_a_mapping_yields_none(headers: object) -> None:
    assert retry_after_seconds(headers) is None


def test_an_httpx_headers_object_is_a_mapping_and_is_read() -> None:
    """The case the ``| Any`` in the signature exists for. ``httpx.Headers`` is a
    case-insensitive ``Mapping``, not a ``dict[str, str]``, and it is what every adapter
    actually has in hand."""
    assert retry_after_seconds(httpx.Headers({"Retry-After": "42"})) == 42


def test_header_names_are_matched_case_insensitively() -> None:
    """A plain ``dict`` built from a recorded fixture keeps the vendor's own casing, and the
    vendors disagree: OpenRouter documents ``X-RateLimit-Reset``, OpenAI writes
    ``x-ratelimit-reset-requests``, and RFC 9110 spells the standard field ``Retry-After``."""
    assert retry_after_seconds({"RETRY-AFTER": "15"}) == 15
    assert retry_after_seconds({"X-RateLimit-Reset-Requests": "30s"}) == 30


def test_non_string_keys_and_values_are_skipped_rather_than_coerced() -> None:
    assert retry_after_seconds({"Retry-After": 30}) is None
    assert retry_after_seconds({b"retry-after": "30"}) is None


def test_an_empty_mapping_yields_none() -> None:
    assert retry_after_seconds({}) is None


# ── Precedence between several present headers ────────────────────────────────────────────


@freeze_time(NOW)
def test_retry_after_wins_over_a_vendor_reset_header() -> None:
    """It is the vendor's own considered answer, standardised since RFC 7231, and the only
    header here whose meaning does not depend on knowing which vendor sent it."""
    headers = {"retry-after": "10", "x-ratelimit-reset-requests": "6m0s"}
    assert retry_after_seconds(headers) == 10


@freeze_time(NOW)
def test_an_unreadable_retry_after_falls_through_to_the_vendor_header() -> None:
    """Precedence is over *answers*, not over presence. A ``Retry-After`` we cannot parse has
    given us nothing, and discarding a readable reset header because an unreadable one
    outranked it would throw away the only hint on the response."""
    headers = {"retry-after": "soon", "x-ratelimit-reset-requests": "30s"}
    assert retry_after_seconds(headers) == 30


@freeze_time(NOW)
def test_the_longest_reset_window_wins_when_several_buckets_are_metered() -> None:
    """OpenAI meters requests and tokens on the same response and nothing in the headers says
    which bucket rejected us. The limit is not cleared until every exhausted bucket has reset,
    so the shorter window is a floor that reissues *into* the rejection window — and those
    attempts still consume the provider's slot, which is how a 429 rate stays pinned at 100%
    long after traffic drops (``kb-error-taxonomy``).

    Over-waiting cannot overrun the budget in exchange: ``call_with_policy`` checks the
    remaining deadline before every attempt, so a floor larger than what is left ends the turn
    as ``provider_temporary`` — fallback-eligible — rather than sleeping past it.
    """
    headers = {"x-ratelimit-reset-requests": "1s", "x-ratelimit-reset-tokens": "5m0s"}
    assert retry_after_seconds(headers) == 300


@freeze_time(NOW)
def test_an_unreadable_bucket_does_not_suppress_a_readable_one() -> None:
    headers = {"x-ratelimit-reset-requests": "???", "x-ratelimit-reset-tokens": "45s"}
    assert retry_after_seconds(headers) == 45


@freeze_time(NOW)
def test_unrecognised_headers_are_ignored_entirely() -> None:
    """Including the ``remaining`` and ``limit`` members of the same families, which are
    counts rather than times. ``x-ratelimit-remaining-requests: 0`` read as a reset is a
    perfectly plausible ``0``."""
    headers = {
        "x-ratelimit-limit-requests": "10000",
        "x-ratelimit-remaining-requests": "0",
        "x-ratelimit-remaining-tokens": "0",
        "anthropic-ratelimit-requests-limit": "50",
        "x-request-id": "req_abc123",
        "server": "cloudflare",
    }
    assert retry_after_seconds(headers) is None


# ── The property the whole file exists to protect ─────────────────────────────────────────


@pytest.mark.parametrize(
    "headers",
    [
        {},
        {"Retry-After": "garbage"},
        {"x-ratelimit-reset-requests": "not-a-duration"},
        {"anthropic-ratelimit-tokens-reset": "2026-08-10"},
        {"x-ratelimit-reset": "60"},
        {"date": "Mon, 10 Aug 2026 12:00:00 GMT"},
        None,
    ],
)
def test_no_answer_is_none_and_never_zero(headers: object) -> None:
    """The sibling of ``test_retry_after_defaults_to_absent_rather_than_zero``, one layer up.

    ``0`` and ``None`` are different answers and ``main.py`` renders them differently: ``0``
    becomes ``Retry-After: 0``, which invites the immediate retry storm the field exists to
    prevent, and ``None`` becomes no header at all. Every input here is one we could not read,
    and ``is not None`` in the renderer means a falsy-but-present ``0`` still reaches the wire
    — so ``assert not retry_after_seconds(...)`` would pass on the bug.
    """
    result = retry_after_seconds(headers)
    # `is None`, never `not result` and never `== 0`: `0 == False` and both of the weaker
    # spellings pass on exactly the bug this asserts against.
    assert result is None

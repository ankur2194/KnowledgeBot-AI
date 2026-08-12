"""The KB1 canonical string, and the four ways two implementations of it drift apart.

`app/core/signing.py` and `tests/support/signing.py` are **independent transcriptions** of one
wire format, which is what makes comparing them worth anything: a test that asks the verifier
to compute the bytes it will then verify asserts only that the verifier agrees with itself.
The third implementation is PHP's, and `tests/contract/test_signing_cross_language.py` runs it.
This file is the part that needs no container, so it runs in the per-push tier and is what
actually fails first when someone edits the format.

Every case below was a real divergence between the two Python sides before this pass, and
none of them could be observed through the deployed header set — which is the whole argument
for pinning them now:

* the covered set (``x-kb-`` prefix, signature excluded),
* the sort key (the joined LINE, not the ``(name, value)`` tuple),
* the timestamp (the raw header STRING, never an ``int`` round-trip),
* the trim character list (PHP's five, not Python's Unicode-whitespace set).
"""

from __future__ import annotations

import hashlib
import hmac
from typing import Final

import pytest

from app.core.keys import KeyRing
from app.core.signing import DEFAULT_PREFIX, canonical_string, verify
from tests.support import signing as oracle

SECRET: Final[bytes] = b"a" * 32
OTHER_SECRET: Final[bytes] = b"b" * 32
RING: Final[KeyRing] = KeyRing(request={"k1": SECRET, "k2": OTHER_SECRET}, callback={})

#: The header set `InternalRequestSignerTest.php` signs, verbatim, so a change on either side
#: shows up here as well as in the cross-language file.
READINESS_HEADERS: Final[dict[str, str]] = {
    "X-KB-Org-Id": "01JQZ0000000000000000000AA",
    "X-KB-Actor-Type": "user",
    "X-KB-Operation": "embedding.readiness",
    "X-KB-Request-Id": "01JQZ0000000000000000000RR",
    "X-KB-Contract-Version": "v1",
    "X-KB-Config-Version": "7",
    "X-KB-Deadline": "1786000000000",
    "X-KB-Timestamp": "1786000000",
}


def _ours(headers: dict[str, str], *, body: bytes = b"", timestamp: str | None = None) -> bytes:
    return canonical_string(
        method="POST",
        path="/internal/v1/embedding/readiness",
        timestamp=headers.get("X-KB-Timestamp", "") if timestamp is None else timestamp,
        body=body,
        kb_headers=headers,
    )


def _theirs(headers: dict[str, str], *, body: bytes = b"", timestamp: str | None = None) -> bytes:
    return oracle.canonical_string(
        method="POST",
        path="/internal/v1/embedding/readiness",
        timestamp=headers.get("X-KB-Timestamp", "") if timestamp is None else timestamp,
        body=body,
        kb_headers=headers,
    )


# ── the two Python transcriptions agree, byte for byte ────────────────────────


@pytest.mark.parametrize(
    "headers",
    [
        pytest.param(READINESS_HEADERS, id="the-deployed-set"),
        pytest.param({}, id="no-kb-headers-at-all-empty-tail"),
        pytest.param({"X-KB-Timestamp": "1786000000"}, id="one-header"),
        pytest.param(
            READINESS_HEADERS | {"content-type": "application/json", "traceparent": "00-a-b-01"},
            id="non-kb-headers-present-and-excluded",
        ),
        pytest.param(
            READINESS_HEADERS | {"X-KB-Signature": "k1:deadbeef"},
            id="the-signature-header-excludes-itself",
        ),
        pytest.param(
            READINESS_HEADERS | {"X-KB-Org-Id-2": "01JQZ0000000000000000000BB"},
            id="one-name-is-a-strict-prefix-of-another",
        ),
        pytest.param(
            {"X-KB-Timestamp": "  1786000000\t", "X-KB-Org-Id": "\r\nA\x00"},
            id="values-needing-a-trim",
        ),
        pytest.param(
            {"X-KB-Config-Version": "10", "X-KB-Contract-Version": "9"},
            id="all-digit-values-that-a-numeric-sort-would-reorder",
        ),
        pytest.param({"x-kb-org-id": "lowercased-already"}, id="already-lowercase-name"),
    ],
)
def test_the_two_python_transcriptions_produce_the_same_bytes(headers: dict[str, str]) -> None:
    body = b'{"connections":[]}'

    assert _ours(headers, body=body) == _theirs(headers, body=body)


def test_the_positive_control_the_comparison_can_actually_fail() -> None:
    """Every assertion above is an equality between two functions, and equality is exactly the
    shape that passes when both sides are broken the same way. This is the line that proves the
    comparison discriminates at all."""
    assert _ours(READINESS_HEADERS) != _theirs(READINESS_HEADERS | {"X-KB-Org-Id": "other"})


# ── the four properties, stated directly rather than by agreement ─────────────


def test_the_covered_set_is_the_x_kb_prefix_and_nothing_else() -> None:
    """The one place a verifier CANNOT copy PHP.

    ``InternalRequestSigner`` skips only ``X-KB-Signature`` and signs every key it was handed;
    its safety comes from ``InternalAiClient`` handing it an X-KB-only array. A verifier
    recomputes the set from the request's ACTUAL headers, which also carry ``content-type``
    and ``traceparent`` — so the prefix filter is not a stylistic choice, it is the only thing
    that makes the two sides computable from different inputs.
    """
    lines = _ours(READINESS_HEADERS | {"content-type": "application/json"}).split(b"\n")[5:]
    assert all(line.startswith(b"x-kb-") for line in lines)
    assert not any(line.startswith(b"content-type:") for line in lines)


def test_the_lines_are_sorted_as_lines_and_not_as_name_value_tuples() -> None:
    """``':'`` is ``0x3A`` and ``'-'`` is ``0x2D``, so the two orders disagree exactly when one
    header name is a strict prefix of another. PHP sorts the joined lines (``SORT_STRING``), so
    ``x-kb-org-id-2:B`` comes BEFORE ``x-kb-org-id:A``. Tuple-sorting puts it after.

    No shipped header name prefixes another, which is why this was latent and why it is worth a
    test rather than a comment: the day somebody adds ``X-KB-Org-Id-2`` the failure is a 401 on
    every internal call, with both sides looking correct in the log.
    """
    headers = {"X-KB-Org-Id": "A", "X-KB-Org-Id-2": "B", "X-KB-Timestamp": "1786000000"}
    lines = _ours(headers).split(b"\n")[5:]

    assert lines == [b"x-kb-org-id-2:B", b"x-kb-org-id:A", b"x-kb-timestamp:1786000000"]
    assert lines != sorted(lines, key=lambda line: tuple(line.split(b":", 1)))


def test_the_timestamp_is_the_raw_header_string_and_is_never_round_tripped() -> None:
    """PHP interpolates ``$kbHeaders['X-KB-Timestamp']`` verbatim.

    ``"01786000000"`` survives ``int()`` as ``1786000000`` and renders back one character
    shorter, so a verifier that takes an ``int`` computes a canonical string the signer never
    signed — and the symptom is an intermittent 401 that everybody blames on clock skew.
    """
    padded = _ours({"X-KB-Timestamp": "01786000000"}, timestamp="01786000000")
    plain = _ours({"X-KB-Timestamp": "1786000000"}, timestamp="1786000000")

    assert padded.split(b"\n")[3] == b"01786000000"
    assert padded != plain


def test_trimming_follows_php_and_not_python() -> None:
    """PHP ``trim()`` strips ``" \\t\\n\\r\\0\\x0B"``. Python ``str.strip()`` strips every Unicode
    whitespace character — including U+00A0, which is a perfectly transmissible latin-1 header
    byte and which Starlette hands us as ``"\\xa0"``.

    Same request on the wire, two different canonical strings, no way to see it in either log.
    """
    php_trimmed = _ours({"X-KB-Org-Id": " \t\r\n\x00\x0bA \x0b"})
    assert php_trimmed.split(b"\n")[5] == b"x-kb-org-id:A"

    nbsp = _ours({"X-KB-Org-Id": "\xa0A"})
    assert nbsp.split(b"\n")[5].endswith(b"A")
    assert nbsp.split(b"\n")[5] != b"x-kb-org-id:A", (
        "U+00A0 was stripped, so this side used Python's Unicode whitespace set and PHP did not"
    )


def test_the_body_hash_covers_the_exact_bytes_and_the_method_and_path_are_in_the_head() -> None:
    body = b'{"b":1,"a":2}'
    head = canonical_string(
        method="post", path="/internal/v1/chat/stream", timestamp="1", body=body, kb_headers={}
    ).split(b"\n")

    assert head[0] == DEFAULT_PREFIX.encode()
    assert head[1] == b"POST"
    assert head[2] == b"/internal/v1/chat/stream"
    assert head[3] == b"1"
    assert head[4] == hashlib.sha256(body).hexdigest().encode()
    # Re-serializing `{"a":2,"b":1}` would hash differently. That is the whole point.
    assert head[4] != hashlib.sha256(b'{"a":2,"b":1}').hexdigest().encode()


def test_the_prefix_is_a_parameter_so_a_two_deploy_bump_is_possible() -> None:
    """ADR-018 bumps the prefix over two deploys and the verifier accepts both for one window.
    A hardcoded ``KB1`` here makes the first deploy reject the second's in-flight requests."""
    assert canonical_string(
        method="GET", path="/p", timestamp="1", body=b"", kb_headers={}, prefix="KB2"
    ).startswith(b"KB2\n")


# ── verify ────────────────────────────────────────────────────────────────────


def _signature(secret: bytes, canonical: bytes) -> str:
    return hmac.new(secret, canonical, hashlib.sha256).hexdigest()


def test_a_correct_signature_verifies_and_a_wrong_one_does_not() -> None:
    canonical = _ours(READINESS_HEADERS)

    assert verify(_signature(SECRET, canonical), "k1", canonical, ring=RING)
    assert not verify(_signature(OTHER_SECRET, canonical), "k1", canonical, ring=RING)


def test_both_live_key_ids_verify_because_a_rotation_has_two() -> None:
    """Verification tries the id named in the header and nothing else. A rotation that drops
    the previous key before in-flight retries drain fails them with an authentication error
    that looks exactly like an attack."""
    canonical = _ours(READINESS_HEADERS)

    assert verify(_signature(SECRET, canonical), "k1", canonical, ring=RING)
    assert verify(_signature(OTHER_SECRET, canonical), "k2", canonical, ring=RING)


@pytest.mark.parametrize(
    "key_id",
    ["k9", "", "../../etc/passwd", "K1", "k1 ", "k" * 64, "k1\x00"],
)
def test_an_unknown_or_malformed_key_id_never_falls_back_to_a_key_that_is_present(
    key_id: str,
) -> None:
    """``key_id`` ARRIVES FROM THE WIRE and is attacker-chosen on every unauthenticated
    request. ``secret_for`` returns ``None`` with no fallback to the active key; a fallback
    would make the field decorative and let anything verify against whatever the ring holds."""
    canonical = _ours(READINESS_HEADERS)

    assert not verify(_signature(SECRET, canonical), key_id, canonical, ring=RING)


def test_a_signature_that_is_not_even_ascii_is_false_rather_than_an_exception() -> None:
    """``hmac.compare_digest`` raises ``TypeError`` on a non-ASCII ``str``. The value came off
    the wire, so an exception here is a 500 where a 401 belongs — and a 500 is a distinguishable
    answer, which is the oracle this scheme spends real effort not to be."""
    canonical = _ours(READINESS_HEADERS)

    assert not verify("dëadbeef", "k1", canonical, ring=RING)


def test_an_empty_ring_verifies_nothing_it_does_not_verify_everything() -> None:
    """The fail-open shape `app/core/keys.py` exists to prevent, asserted at the verifier: an
    empty ring must refuse, not accept an empty-key HMAC."""
    canonical = _ours(READINESS_HEADERS)
    empty = KeyRing(request={}, callback={})

    assert not verify(_signature(b"", canonical), "k1", canonical, ring=empty)
    assert not verify(_signature(SECRET, canonical), "k1", canonical, ring=empty)


def test_the_callback_direction_is_not_reachable_through_the_request_direction() -> None:
    """``k*`` signs Laravel→FastAPI, ``c*`` signs FastAPI→Laravel, and the sets are disjoint so
    a compromised outbound key cannot forge an inbound request. Sharing one set discards that
    property while every test still passes."""
    canonical = _ours(READINESS_HEADERS)
    ring = KeyRing(request={"k1": SECRET}, callback={"c1": OTHER_SECRET})

    assert not verify(_signature(OTHER_SECRET, canonical), "c1", canonical, ring=ring)

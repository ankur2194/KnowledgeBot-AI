"""The KB1 canonical string and its verification.

Signatures are final; bodies belong to whoever implements the internal transport, on both
sides at once — this file and Laravel's `App\\Services\\Internal` must agree byte for byte
or every internal call 401s.

Three properties the implementation must have, each of which has a silent failure mode:

* **Sign the exact received bytes**, not a re-serialization of the parsed body. Key order,
  unicode escaping and float formatting all differ between PHP's and Python's JSON
  encoders, so a re-serialized payload verifies only by luck.
* **Two key ids are live during a rotation.** Verification tries the id named in the header
  and nothing else; a rotation that removes the previous key before every in-flight retry
  has drained fails those retries with an authentication error that looks like an attack.
* **The nonce store must outlive the skew window**, or a replayed request that arrives after
  the nonce expired but inside the skew window verifies.

THE AUTHORITY IS `InternalRequestSigner::canonicalString()`, AND FOUR THINGS ARE EASY TO GET
WRONG WHILE STILL LOOKING RIGHT
--------------------------------------------------------------------------------------------
1. **The covered header set.** PHP skips only ``X-KB-Signature`` and signs *every key it was
   handed*; its safety comes entirely from ``InternalAiClient`` handing it an X-KB-only array.
   A verifier cannot work that way — it recomputes the set from the request's ACTUAL headers,
   which also carry ``content-type`` and ``traceparent``. So the filter here is the ``x-kb-``
   prefix, and ``tests/contract/test_signing_cross_language.py`` is what pins the two sides
   together over a header set containing both.
2. **The sort key is the joined LINE, not the ``(name, value)`` tuple.** PHP sorts
   ``name:value`` strings with ``SORT_STRING``. ``':'`` is ``0x3A`` and ``'-'`` is ``0x2D``,
   so whenever one header name is a strict prefix of another the two orders disagree:
   line-sort puts ``x-kb-org-id-2:B`` BEFORE ``x-kb-org-id:A``, tuple-sort puts it after. No
   header name in the current set prefixes another, which is exactly why this is worth
   pinning now rather than after somebody adds ``X-KB-Org-Id-2``.
3. **The timestamp is the RAW HEADER STRING.** PHP interpolates ``$kbHeaders['X-KB-Timestamp']``
   verbatim. Taking an ``int`` here and rendering it back is the "verifies only by luck" class
   above in miniature: ``"01786000000"`` round-trips through ``int()`` to ``1786000000`` and
   produces different canonical bytes than the signer computed. The caller does the
   digits-only validation and hands the raw value through untouched.
4. **Whitespace trimming follows PHP, not Python.** PHP ``trim()`` strips exactly
   ``" \\t\\n\\r\\0\\x0B"``. Python ``str.strip()`` strips every Unicode whitespace character,
   and header values reach us latin-1 decoded — so a value beginning ``\\xa0`` (NBSP, a
   perfectly transmissible latin-1 byte) would be trimmed here and not there, and the
   signature would differ for a request that looked identical in both logs.

One thing deliberately NOT handled here: the canonical string is encoded UTF-8, while PHP
signs the raw wire bytes. Every ``X-KB-*`` value is ASCII by contract — ULIDs, enum tokens,
decimal integers, dotted operation names — and on ASCII the two encodings are identical.
``verify_hmac`` rejects a non-ASCII ``X-KB-*`` value rather than letting the two sides
silently disagree about what a byte is.
"""

from __future__ import annotations

import hashlib
import hmac
from collections.abc import Mapping
from typing import TYPE_CHECKING, Final

if TYPE_CHECKING:  # pragma: no cover - typing only
    from app.core.keys import KeyRing

__all__ = [
    "DEFAULT_PREFIX",
    "HEADER_PREFIX",
    "SIGNATURE_HEADER",
    "canonical_string",
    "verify",
]

#: The wire prefix, and the default for callers that hold no ``Settings``. Production reads
#: ``Settings.accepted_signing_prefixes`` and tries each, because ADR-018 bumps the prefix
#: over two deploys and verifying only the active one makes the first deploy reject the
#: second's in-flight requests.
DEFAULT_PREFIX: Final = "KB1"

#: The covered set, lowercased. Everything else on the request — ``content-type``,
#: ``traceparent``, whatever a proxy adds — is outside the signature by construction.
HEADER_PREFIX: Final = "x-kb-"

#: Excluded from its own canonical string; otherwise signing is a fixed point nobody can
#: compute, and the verifier — which recomputes the set from the headers actually present —
#: would include it and never match.
SIGNATURE_HEADER: Final = "x-kb-signature"

#: PHP's ``trim()`` default character list, exactly. See point 4 in the module docstring.
_PHP_TRIM_CHARS: Final = " \t\n\r\x00\x0b"

#: Used when ``key_id`` names nothing in the ring, so that an unknown id costs the same HMAC
#: as a wrong signature. It is a constant, never a fallback: the comparison it feeds is
#: discarded and ``verify`` returns False regardless.
_ABSENT_KEY_SECRET: Final = b"\x00" * 32


def _php_trim(value: str) -> str:
    """``trim()`` with PHP's character list rather than Python's Unicode-whitespace set."""
    return value.strip(_PHP_TRIM_CHARS)


def canonical_string(
    *,
    method: str,
    path: str,
    timestamp: str,
    body: bytes,
    kb_headers: Mapping[str, str],
    prefix: str = DEFAULT_PREFIX,
) -> bytes:
    """Build the KB1 canonical string.

    See `kb-internal-api-contracts` for the exact field order and separator — it is a wire
    format shared with Laravel, so it is specified there, not decided here.

    ``kb_headers`` is EVERY ``X-KB-*`` header present on the request, sorted, and it is not
    optional. Signing method+path+body alone leaves ``X-KB-Org-Id`` outside the signature —
    and that header is the tenant scope for the entire data plane, so a validly signed
    request could be replayed against another organization with the signature still
    verifying. There is deliberately no separate ``nonce`` parameter either: the nonce is
    ``X-KB-Request-Id``, and pulling it out of the header set is how the two planes end up
    signing different strings while both believing they implement KB1.

    ``timestamp`` is the raw header value, passed through untouched — see point 3 of the
    module docstring. Non-``X-KB-*`` headers in ``kb_headers`` are ignored rather than
    rejected, so the caller may hand this the whole request header mapping.
    """
    head = (
        f"{prefix}\n{method.upper()}\n{path}\n{timestamp}\n{hashlib.sha256(body).hexdigest()}\n"
    ).encode()
    # Sorted as BYTES, which is what `sort($lines, SORT_STRING)` does. On the ASCII domain
    # this is identical to sorting the `str`s; doing it on the encoded form means the two
    # sides cannot drift apart on a value that leaves it.
    lines = sorted(
        f"{name.lower()}:{_php_trim(value)}".encode()
        for name, value in kb_headers.items()
        if name.lower().startswith(HEADER_PREFIX) and name.lower() != SIGNATURE_HEADER
    )
    return head + b"\n".join(lines)


def verify(signature: str, key_id: str, canonical: bytes, *, ring: KeyRing) -> bool:
    """Constant-time compare against the key named by ``key_id``.

    Must use ``hmac.compare_digest``. A ``==`` on the hex digest leaks the prefix length
    through timing, and this endpoint is reachable from anywhere on the application network.

    ``ring`` IS A KEYWORD-ONLY PARAMETER AND THERE IS DELIBERATELY NO MODULE GLOBAL.
    ``app/core/keys.py``'s whole design is that the ring is built once, at startup, from a
    directory listing, and then lives on application state — a module-level ``_RING`` here
    would be a second place it lives, which is the shape that lets a test, a Celery child and
    the API disagree about which ids are loaded. Passing it explicitly also keeps this
    function callable from a unit test with no ``Request``, no app and no lifespan, which is
    what makes the wrong-signature and unknown-key-id cases assertable at all.

    An unknown ``key_id`` costs the same HMAC as a wrong signature and returns the same
    ``False``, because the caller renders both as one indistinguishable rejection
    (``keys.py``: distinguishing them tells an attacker which ids exist).
    """
    secret = ring.secret_for(key_id)
    expected = hmac.new(_ABSENT_KEY_SECRET if secret is None else secret, canonical, hashlib.sha256)
    # `compare_digest` on `str` requires both sides to be ASCII-only, and `signature` came off
    # the wire. Comparing bytes has no such precondition and no early exit.
    matches = hmac.compare_digest(expected.hexdigest().encode("ascii"), signature.encode("utf-8"))
    return secret is not None and matches

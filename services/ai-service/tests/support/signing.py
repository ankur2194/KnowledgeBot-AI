"""KB1 request signing, for tests. It signs **the exact bytes the test sends**.

The one property this module exists to preserve: the body is serialized **once**, into
``bytes``, and the same object is both hashed and transmitted. ``json.dumps(await
request.json())`` is not byte-stable — key order, unicode escaping and separators all differ
between encoders and between calls — so a signer that re-serializes verifies by luck and
produces intermittent 401s that reviewers blame on clock skew.

The second property is subtler and is the reason ``build()`` returns headers and body
together rather than exposing a ``sign(headers)`` helper: **every ``X-KB-*`` header is inside
the canonical string.** ``X-KB-Org-Id`` is the tenant scope for the entire data plane, so a
signature over method, path, timestamp and body alone leaves it forgeable — flip one header
and a legitimately signed request executes against another organization, defeating every
downstream layer at once. A fixture that adds a header after signing therefore breaks the
signature *by construction*, which is the correct outcome and is why there is no seam here
to add one through.

This module **re-implements** the canonical string from `kb-internal-api-contracts` rather
than calling ``app.core.signing.canonical_string``. That is deliberate and is what makes the
contract tests worth running: a test that asks the implementation to compute the bytes it
will then verify asserts only that the implementation agrees with itself. The wire format is
specified in the skill; this file is the test-side transcription of it, and a divergence
between the two implementations is exactly the finding a contract test is for.

    canonical = "KB1\\n" + METHOD + "\\n" + PATH + "\\n" + timestamp + "\\n"
              + sha256_hex(raw_body) + "\\n"
              + "\\n".join(sorted(f"{k}:{v}" for k, v in kb_headers))

where ``kb_headers`` is **every** ``X-KB-*`` header actually present except the signature
itself, name lowercased and value stripped. The verifier recomputes that set from the headers
on the request rather than from a caller-supplied signed-headers list, because such a list is
itself attacker-controlled — anything omitted from it can be added or dropped freely.

THREE CORRECTIONS THAT LANDED WITH THE VERIFIER, ALL LATENT UNTIL SOMETHING MOVES
---------------------------------------------------------------------------------
This module used to sort the ``(name, value)`` TUPLES, take ``timestamp`` as an ``int``, and
trim with Python's ``str.strip()``. All three disagree with the deployed PHP signer
(``InternalRequestSigner::canonicalString()``), and none of them could be observed with the
current header set — which is precisely why they were worth fixing before something moved:

* ``sort($lines, SORT_STRING)`` sorts the joined ``name:value`` LINES. ``':'`` is ``0x3A`` and
  ``'-'`` is ``0x2D``, so the two orders disagree the moment one header name is a strict
  prefix of another — ``x-kb-org-id-2:B`` sorts before ``x-kb-org-id:A`` by line and after it
  by tuple. No current name prefixes another.
* PHP interpolates the timestamp header STRING. ``"01786000000"`` round-trips through
  ``int()`` into ``"1786000000"`` and signs different bytes than the signer sent.
* PHP ``trim()`` strips ``" \\t\\n\\r\\0\\x0B"``; Python ``str.strip()`` also strips every
  Unicode whitespace character, including ``\\xa0``, which is a transmissible latin-1 header
  byte.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
import uuid
from dataclasses import dataclass, field
from typing import Any, Final

__all__ = ["PREFIX", "SignedRequest", "Signer", "canonical_string", "ulid_like"]

#: The wire prefix. It stays ``KB1``: spec defect 13 rewrote the canonical string before any
#: signer had been deployed, so ``KB1`` has never meant anything else on the wire, and a
#: bump for a shape nothing ever spoke turns the prefix into a changelog of drafts. Any
#: change to the canonical string, the covered header set, or the hash function bumps it —
#: and during a bump the verifier accepts both prefixes for one release window.
PREFIX: Final[str] = "KB1"

#: Matches ``Settings.hmac_max_skew_seconds``. Restated rather than imported so a skew test
#: that widens the setting still fails.
MAX_SKEW_SECONDS: Final[int] = 60


def ulid_like(seed: str | None = None) -> str:
    """A 26-character Crockford-base32 identifier.

    Not a real ULID — nothing here decodes one — but the right *shape*, which is what the
    payload indexes assume (``keyword``, not ``uuid``) and what a length or charset
    validation will reject if it drifts.
    """
    alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
    raw = uuid.uuid5(uuid.NAMESPACE_OID, seed).int if seed else uuid.uuid4().int
    out = []
    for _ in range(26):
        raw, rem = divmod(raw, 32)
        out.append(alphabet[rem])
    return "".join(reversed(out))


#: PHP ``trim()``'s default character list, exactly — not Python's Unicode-whitespace set.
PHP_TRIM_CHARS: Final[str] = " \t\n\r\x00\x0b"


def canonical_string(
    *,
    method: str,
    path: str,
    timestamp: str,
    body: bytes,
    kb_headers: dict[str, str],
) -> bytes:
    """Build the KB1 canonical string over the exact body bytes.

    ``path`` is the path only — no query string, no scheme, no host — and is part of the
    signature because a body-only signature is replayable against a different endpoint that
    accepts the same body.

    ``timestamp`` is the raw ``X-KB-Timestamp`` header value, passed through untouched.
    """
    covered = sorted(
        f"{name.lower()}:{value.strip(PHP_TRIM_CHARS)}"
        for name, value in kb_headers.items()
        if name.lower().startswith("x-kb-") and name.lower() != "x-kb-signature"
    )
    head = f"{PREFIX}\n{method.upper()}\n{path}\n{timestamp}\n{hashlib.sha256(body).hexdigest()}\n"
    return (head + "\n".join(covered)).encode("utf-8")


@dataclass(frozen=True, slots=True)
class SignedRequest:
    """A request that is ready to send and cannot be modified without invalidating itself.

    ``kw`` is spread straight into httpx::

        req = signer.build("POST", "/internal/v1/chat/stream", body)
        async with client.stream("POST", live_url + req.path, **req.kw) as resp:
            ...

    ``content=`` carries the same ``bytes`` object that was hashed. Never pass ``json=``
    alongside it: httpx would re-encode, and the signature would cover different bytes than
    the socket carries.
    """

    method: str
    path: str
    body: bytes
    headers: dict[str, str]

    @property
    def kw(self) -> dict[str, Any]:
        return {"headers": dict(self.headers), "content": self.body}

    @property
    def request_id(self) -> str:
        return self.headers["X-KB-Request-Id"]

    @property
    def canonical(self) -> bytes:
        return canonical_string(
            method=self.method,
            path=self.path,
            # The RAW header value. `int(...)` here would re-render "01786000000" as
            # "1786000000" and compute a canonical string the signer never signed.
            timestamp=self.headers.get("X-KB-Timestamp", ""),
            body=self.body,
            kb_headers=self.headers,
        )

    def with_header(self, name: str, value: str) -> SignedRequest:
        """Add or replace a header **without** re-signing.

        This exists for exactly one kind of test: proving that tampering with any ``X-KB-*``
        header after signing is rejected. It is named to be conspicuous at the call site, so
        it can never be mistaken for a convenience.
        """
        headers = dict(self.headers)
        headers[name] = value
        return SignedRequest(self.method, self.path, self.body, headers)


@dataclass(slots=True)
class Signer:
    """Mints signed internal requests for one key id.

    Two key ids are live during a rotation and the verifier must accept both, so tests
    construct two ``Signer``s rather than mutating one.
    """

    secret: bytes = b"test-hmac-secret-not-a-real-key"
    key_id: str = "k1"
    org_id: str = field(default_factory=lambda: ulid_like("org-a"))
    bot_id: str | None = None
    actor_id: str | None = None
    actor_type: str = "user"
    contract_version: str = "v1"
    config_version: int = 1
    #: Absolute epoch **milliseconds** at which the caller stops caring, not a duration.
    #: Laravel sets it from its own remaining budget; FastAPI checks the remainder before
    #: each provider attempt and never constructs a fresh duration downstream.
    deadline_ms: int | None = None

    def build(
        self,
        method: str,
        path: str,
        body: bytes | dict[str, Any] | None = None,
        *,
        operation: str = "chat.execute",
        idempotency_key: str | None = None,
        request_id: str | None = None,
        timestamp: int | None = None,
        traceparent: str | None = None,
        extra_headers: dict[str, str] | None = None,
        omit: tuple[str, ...] = (),
    ) -> SignedRequest:
        """Serialize once, hash that, send that.

        ``omit`` drops a header *before* signing, so a "missing ``X-KB-Org-Id`` returns 400"
        test exercises the dependency rather than the signature verifier — those are two
        different rejections and a test that conflates them proves neither.
        """
        if isinstance(body, bytes):
            raw = body
        elif body is None:
            raw = b""
        else:
            # One serialization, kept. `separators` and `sort_keys` are pinned so that a
            # payload built twice in one test is byte-identical, which is what makes an
            # idempotency-replay assertion meaningful.
            raw = json.dumps(body, separators=(",", ":"), sort_keys=True).encode("utf-8")

        ts = int(time.time()) if timestamp is None else timestamp
        headers: dict[str, str] = {
            "content-type": "application/json",
            "traceparent": traceparent or "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01",
            "X-KB-Request-Id": request_id or ulid_like(),
            "X-KB-Org-Id": self.org_id,
            "X-KB-Actor-Type": self.actor_type,
            "X-KB-Operation": operation,
            "X-KB-Config-Version": str(self.config_version),
            "X-KB-Contract-Version": self.contract_version,
            "X-KB-Timestamp": str(ts),
            "X-KB-Deadline": str(
                self.deadline_ms if self.deadline_ms is not None else (ts + 55) * 1000
            ),
        }
        if self.bot_id is not None:
            headers["X-KB-Bot-Id"] = self.bot_id
        if self.actor_id is not None:
            headers["X-KB-Actor-Id"] = self.actor_id
        if idempotency_key is not None:
            headers["X-KB-Idempotency-Key"] = idempotency_key
        if extra_headers:
            headers.update(extra_headers)
        for name in omit:
            headers.pop(name, None)

        canonical = canonical_string(
            method=method,
            path=path,
            timestamp=headers.get("X-KB-Timestamp", ""),
            body=raw,
            kb_headers=headers,
        )
        digest = hmac.new(self.secret, canonical, hashlib.sha256).hexdigest()
        headers["X-KB-Signature"] = f"{self.key_id}:{digest}"
        return SignedRequest(method.upper(), path, raw, headers)

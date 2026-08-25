"""The outbound callback signs with the CALLBACK ring — Phase C's critical finding, reversed.

WHY THIS FILE EXISTS AND WHY A GREEN SUITE WAS NOT EVIDENCE
-------------------------------------------------------------
There are two key rings. ``k1``/``k2`` are Laravel→FastAPI; ``c1``/``c2`` are FastAPI→Laravel.
Phase C's worst defect was a verifier resolving secrets from the *outbound* ring, and it was a
liveness bug and a security bug at once: every callback 401s so no source ever leaves ``queued``,
and the only key that verifies inbound is the one held by the busiest outbound path — so leaking
it lets anyone forge a callback with any ``X-KB-Org-Id`` and publish or retire any tenant's
version.

**The suite could not catch it, because the test helper signed with the same wrong ring.** A
green callback suite was evidence that two mistakes agreed with each other. So every ring built
in this file gives the two directions **different** secrets, and the first test asserts that
property of the fixture itself rather than assuming it.
"""

from __future__ import annotations

import hashlib
import hmac
import json
from typing import Any

import httpx
import pytest

from app.core.errors import KbError
from app.core.keys import KeyRing
from app.core.signing import DEFAULT_PREFIX, canonical_string
from app.ingestion.callback import CALLBACK_PATH, SIGNED_HEADER_NAMES, CallbackEmitter

#: 32 bytes each, matching `app/core/keys.py`'s floor. DISJOINT, and the disjointness is the
#: whole fixture: a ring sharing one secret between the directions makes every assertion below
#: pass whichever ring the implementation reaches for.
REQUEST_SECRET = b"security-tier-inbound-secret-k1x"
CALLBACK_SECRET = b"security-tier-outbnd-secret-c1x!"

ORG = "01JQZ0000000000000000000AA"

FRAME: dict[str, Any] = {
    "job_id": "01JQZ0000000000000000000BB",
    "source_id": "01JQZ0000000000000000000CC",
    "source_item_id": "01JQZ0000000000000000000DD",
    "sequence": 3,
    "stage": "publish",
    "status": "ready",
    "verified": True,
}


@pytest.fixture
def ring() -> KeyRing:
    return KeyRing(request={"k1": REQUEST_SECRET}, callback={"c1": CALLBACK_SECRET})


def _emitter(ring: KeyRing, handler: Any) -> CallbackEmitter:
    return CallbackEmitter(
        base_url="http://laravel-api:8080",
        key_ring=ring,
        key_id="c1",
        prefix=DEFAULT_PREFIX,
        client=httpx.Client(transport=httpx.MockTransport(handler)),
    )


def _accept(captured: list[httpx.Request]) -> Any:
    def handler(request: httpx.Request) -> httpx.Response:
        captured.append(request)
        return httpx.Response(
            200, json={"applied": True, "reason": "applied", "status": "ready", "activated": True}
        )

    return handler


def test_the_two_rings_hold_different_secrets(ring: KeyRing) -> None:
    """THE FIXTURE'S OWN PROPERTY, ASSERTED. Without it every other test in this file is
    satisfied by an implementation that reaches for the wrong ring."""
    assert ring.secret_for("c1", callback=True) != ring.secret_for("k1")
    assert ring.secret_for("k1", callback=True) is None
    assert ring.secret_for("c1") is None


def test_the_callback_is_signed_with_the_callback_ring(ring: KeyRing) -> None:
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    request = captured[0]
    kb_headers = {k: v for k, v in request.headers.items() if k.lower().startswith("x-kb-")}
    canonical = canonical_string(
        method="POST",
        path=CALLBACK_PATH,
        timestamp=request.headers["x-kb-timestamp"],
        body=request.content,
        kb_headers=kb_headers,
        prefix=DEFAULT_PREFIX,
    )

    expected = hmac.new(CALLBACK_SECRET, canonical, hashlib.sha256).hexdigest()
    forged_with_inbound = hmac.new(REQUEST_SECRET, canonical, hashlib.sha256).hexdigest()

    # `key_id:hex`, ONE HEADER. `VerifyInternalSignature` splits on the first colon and excludes
    # exactly this header from the covered set — so a separate `X-KB-Key-Id` would be inside the
    # string the verifier computes and outside the one the signer computed.
    assert request.headers["x-kb-signature"] == f"c1:{expected}"
    assert f"c1:{forged_with_inbound}" != request.headers["x-kb-signature"]


def test_no_separate_key_id_header_is_sent(ring: KeyRing) -> None:
    """The key id rides INSIDE the signature value. This is asserted on its own because the
    natural shape — a `X-KB-Key-Id` header beside the digest — 401s every callback ever sent,
    and neither plane logs a reason: the source simply never leaves `queued`."""
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    names = {k.lower() for k in captured[0].headers}
    assert "x-kb-key-id" not in names
    assert captured[0].headers["x-kb-signature"].split(":", 1)[0] == "c1"


def test_the_signature_covers_the_exact_bytes_that_are_sent(ring: KeyRing) -> None:
    """Re-encoding JSON to hash it is not byte-stable — key order and separator whitespace both
    move — and the failure is an intermittent 401 on a request that looks correct in every log."""
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    request = captured[0]
    body_digest = hashlib.sha256(request.content).hexdigest()
    assert (
        body_digest
        in canonical_string(
            method="POST",
            path=CALLBACK_PATH,
            timestamp=request.headers["x-kb-timestamp"],
            body=request.content,
            kb_headers={k: v for k, v in request.headers.items() if k.lower().startswith("x-kb-")},
            prefix=DEFAULT_PREFIX,
        ).decode()
    )
    assert json.loads(request.content) == FRAME


def test_no_bot_id_header_rides_the_callback(ring: KeyRing) -> None:
    """ADR-067: the source is organization-owned, so there is no single bot id to send.
    Inventing one would put a bot inside a signature that scopes nothing — and because the
    header set is inside the canonical string, neither signer could be corrected alone."""
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    assert "x-kb-bot-id" not in {k.lower() for k in captured[0].headers}


def test_the_organization_travels_inside_the_signature(ring: KeyRing) -> None:
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    request = captured[0]
    assert request.headers["x-kb-org-id"] == ORG

    tampered = {k: v for k, v in request.headers.items() if k.lower().startswith("x-kb-")}
    tampered["x-kb-org-id"] = "01JQZ0000000000000000000ZZ"
    forged = hmac.new(
        CALLBACK_SECRET,
        canonical_string(
            method="POST",
            path=CALLBACK_PATH,
            timestamp=request.headers["x-kb-timestamp"],
            body=request.content,
            kb_headers=tampered,
            prefix=DEFAULT_PREFIX,
        ),
        hashlib.sha256,
    ).hexdigest()
    assert forged != request.headers["x-kb-signature"]


def test_a_key_id_with_no_callback_material_refuses_rather_than_falling_back(ring: KeyRing) -> None:
    """Falling back to the inbound ring is the exact defect this module is arranged against, and
    it would look like success: the request would be signed, and silently 401 forever."""
    emitter = CallbackEmitter(
        base_url="http://laravel-api:8080",
        key_ring=ring,
        key_id="c9",
        prefix=DEFAULT_PREFIX,
        client=httpx.Client(transport=httpx.MockTransport(_accept([]))),
    )
    with pytest.raises(KbError, match="no callback secret"):
        emitter.post(FRAME, org_id=ORG, operation="ingestion.readiness")


def test_the_outbound_prefix_is_the_default_and_not_the_inbound_accept_list() -> None:
    """`Settings.accepted_signing_prefixes` is what this service will VERIFY. Reusing it for the
    outbound direction makes the two move together, when the whole point of a two-deploy prefix
    bump is that they move apart."""
    from app.core.config import Settings
    from app.ingestion.callback import build_emitter

    settings = Settings(environment="ci")
    emitter = build_emitter(
        settings, KeyRing(request={"k1": REQUEST_SECRET}, callback={"c1": CALLBACK_SECRET})
    )
    assert emitter._prefix == DEFAULT_PREFIX
    emitter.close()


def test_a_refused_callback_raises_and_is_not_swallowed(ring: KeyRing) -> None:
    """A version that indexed and verified but never reported readiness stays unpublished
    forever with a full, correct point set on disk — invisible to retrieval and to deletion."""

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(401, json={"message": "nope"})

    with pytest.raises(KbError) as raised:
        _emitter(ring, handler).post(FRAME, org_id=ORG, operation="ingestion.readiness")
    # A 401 is a wrong ring and will not come right on a retry.
    assert raised.value.retryable is False


def test_a_server_error_is_retryable_while_a_401_is_not(ring: KeyRing) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503, json={"message": "later"})

    with pytest.raises(KbError) as raised:
        _emitter(ring, handler).post(FRAME, org_id=ORG, operation="ingestion.readiness")
    assert raised.value.retryable is True


def test_the_refusal_message_does_not_relay_the_control_planes_body(ring: KeyRing) -> None:
    """A 422 from the control plane echoes the frame, and the frame carries identifiers."""

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(422, json={"errors": {"job_id": ["01JQZSECRETLOOKINGVALUE"]}})

    with pytest.raises(KbError) as raised:
        _emitter(ring, handler).post(FRAME, org_id=ORG, operation="ingestion.readiness")
    assert "01JQZSECRETLOOKINGVALUE" not in str(raised.value)


def test_the_acknowledgement_is_returned_to_the_caller(ring: KeyRing) -> None:
    """A refused frame is a 200 with `applied: false`, so a caller that discarded the body could
    not tell an applied frame from a superseded one — and `intake` decides whether to dispatch a
    whole run on exactly that difference."""

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"applied": False, "reason": "live_version_unchanged"})

    result = _emitter(ring, handler).post(FRAME, org_id=ORG, operation="ingestion.readiness")
    assert result["reason"] == "live_version_unchanged"


def test_the_emitter_sends_exactly_the_declared_signed_header_set(ring: KeyRing) -> None:
    """The set IS the contract, because it is inside the canonical string.

    `VerifyInternalSignature` recomputes the covered set from the headers that arrive, excluding
    only `X-KB-Signature`. So a header added here and not to `SIGNED_HEADER_NAMES` — or the
    reverse — changes the bytes the far side hashes, and the failure is a 401 on every callback
    with no field to point at. The declared tuple is what the cross-language matrix pins the
    canonical bytes for; this is what keeps the emitter honest about matching it.
    """
    captured: list[httpx.Request] = []
    _emitter(ring, _accept(captured)).post(FRAME, org_id=ORG, operation="ingestion.readiness")

    sent = {
        name
        for name in captured[0].headers
        if name.lower().startswith("x-kb-") and name.lower() != "x-kb-signature"
    }
    assert sent == {name.lower() for name in SIGNED_HEADER_NAMES}

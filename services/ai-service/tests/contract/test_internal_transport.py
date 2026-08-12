"""``verify_hmac`` on the wire: the cross-plane authentication boundary, end to end.

Every request here is signed by `tests/support/signing.py`, which signs **the exact bytes it
sends**. There is no override, no skip flag and no seam to add one through — a test that
bypasses signing is not a contract test, and if a bypass ever appears in the application, that
is the finding (`tests/contract/README.md`).

The positive control is asserted first, in `test_a_correctly_signed_request_is_served`, and it
is not decoration. Every other assertion in this file is a refusal, and a refusal is satisfied
by a broken route, an unbuilt app, a 500 from an unrelated defect, or a dependency that raises
before it ever looks at the signature. Without a green positive control none of them mean
anything — the same failure shape `pytest-ai-service` calls out for the tenancy suite.

WHAT AN ATTACKER MUST NOT BE ABLE TO TELL APART
------------------------------------------------
`app/core/keys.py` is explicit: distinguishing "unknown key id" from "wrong signature" tells an
attacker which ids exist. So the two are asserted **byte-identical in body and equal in status**,
and separately compared for timing. The timing assertion is coarse on purpose — a CI runner is
not an oscilloscope — but it does catch the shape that matters, which is one path skipping the
HMAC entirely and returning in a fraction of the other's time.
"""

from __future__ import annotations

import time
from typing import Any, Final

import httpx
import pytest

from tests.support.coordination import InMemoryCoordination
from tests.support.signing import Signer

PATH: Final[str] = "/internal/v1/embedding/readiness"
BODY: Final[dict[str, Any]] = {"connections": [], "designated": None}


# ── the positive control ──────────────────────────────────────────────────────


async def test_a_correctly_signed_request_is_served(send: Any, signer: Signer) -> None:
    """First, and load-bearing for every refusal below.

    It also pins that the whole dependency chain resolves against a *signed* request: the
    router's ``verify_hmac``, then ``request_context`` reading the verified headers, then the
    handler. A green refusal suite over an app that cannot serve anything proves nothing.
    """
    response = await send(signer.build("POST", PATH, BODY, operation="embedding.readiness"))

    assert response.status_code == 200, response.text
    assert response.json()["selected"] is None


async def test_both_key_ids_live_during_a_rotation_are_accepted(
    send: Any, rotation_signer: Signer
) -> None:
    """The verifier tolerates extra ids; that is what makes a rotation survivable. Signer and
    verifier deploy at different times, so a single-id scheme 401s every internal call through a
    rolling deploy — and the failure looks exactly like an attack."""
    response = await send(rotation_signer.build("POST", PATH, BODY))

    assert response.status_code == 200, response.text


# ── the five refusals ─────────────────────────────────────────────────────────


async def test_an_unsigned_request_is_refused(send: Any, signer: Signer) -> None:
    request = signer.build("POST", PATH, BODY)
    unsigned = request.with_header("X-KB-Signature", "")
    del unsigned.headers["X-KB-Signature"]

    response = await send(unsigned)

    assert response.status_code == 401
    assert response.json()["error_class"] == "authentication"
    assert "selected" not in response.json()


async def test_a_wrong_signature_is_refused(send: Any, signer: Signer) -> None:
    """The secret is wrong; everything else about the request is perfect."""
    forger = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k1", org_id=signer.org_id)

    response = await send(forger.build("POST", PATH, BODY))

    assert response.status_code == 401
    assert response.json()["error_class"] == "authentication"


async def test_an_unknown_key_id_is_refused_and_never_falls_back(send: Any, signer: Signer) -> None:
    """``key_id`` arrives from the wire and is attacker-chosen on every unauthenticated
    request. ``secret_for`` returns ``None`` with no fallback to the active key — a fallback
    makes the field decorative and lets a stale signer verify forever."""
    stranger = Signer(secret=b"whatever-32-bytes-long-secret-ab", key_id="k9", org_id=signer.org_id)

    response = await send(stranger.build("POST", PATH, BODY))

    assert response.status_code == 401


async def test_an_unknown_key_id_and_a_wrong_signature_are_indistinguishable(
    send: Any, signer: Signer
) -> None:
    """Byte-identical bodies, identical statuses, and no ``WWW-Authenticate`` or other header
    that differs. Anything that separates them enumerates which key ids exist."""
    wrong = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k1", org_id=signer.org_id)
    unknown = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k9", org_id=signer.org_id)

    a = await send(wrong.build("POST", PATH, BODY))
    b = await send(unknown.build("POST", PATH, BODY))

    assert a.status_code == b.status_code == 401
    # `request_id` echoes the caller's own header, so it legitimately differs; everything else
    # must not.
    assert {k: v for k, v in a.json().items() if k != "request_id"} == {
        k: v for k, v in b.json().items() if k != "request_id"
    }
    assert set(a.headers) - {"date"} == set(b.headers) - {"date"}


async def test_the_unknown_key_path_does_not_short_circuit_ahead_of_the_wrong_key_path(
    send: Any, signer: Signer
) -> None:
    """Timing, coarsely. A CI runner is not an oscilloscope and this is deliberately not a
    constant-time proof — what it catches is the *shape*: an unknown id returning without
    computing an HMAC at all, which is an order-of-magnitude difference and the thing
    `app/core/signing.py` spends a dummy HMAC to avoid.

    Compared as medians of interleaved runs so a garbage-collection pause or a noisy neighbour
    lands on both arms, and with a factor that a scheduler hiccup cannot cross.
    """
    wrong = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k1", org_id=signer.org_id)
    unknown = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k9", org_id=signer.org_id)

    async def elapsed(who: Signer) -> float:
        start = time.perf_counter()
        await send(who.build("POST", PATH, BODY))
        return time.perf_counter() - start

    wrong_times, unknown_times = [], []
    for _ in range(15):
        wrong_times.append(await elapsed(wrong))
        unknown_times.append(await elapsed(unknown))

    wrong_times.sort()
    unknown_times.sort()
    ratio = unknown_times[7] / wrong_times[7]

    assert 0.2 < ratio < 5.0, (
        f"unknown-key and wrong-signature medians differ by {ratio:.2f}x; one path is skipping "
        "work the other does, which is an oracle for which key ids exist"
    )


@pytest.mark.parametrize("skew", [-61, -3600, 61, 3600])
async def test_a_timestamp_outside_the_skew_window_is_refused(
    send: Any, signer: Signer, skew: int
) -> None:
    """Both directions. A future timestamp is not "harmless" — it extends the window in which a
    captured request stays replayable past the nonce's own TTL."""
    response = await send(signer.build("POST", PATH, BODY, timestamp=int(time.time()) + skew))

    assert response.status_code == 401


@pytest.mark.parametrize("skew", [-59, 0, 59])
async def test_a_timestamp_inside_the_skew_window_is_served(
    send: Any, signer: Signer, skew: int
) -> None:
    """The positive control for the skew test above: without it, a verifier that rejected every
    timestamp would satisfy the refusal cases."""
    response = await send(signer.build("POST", PATH, BODY, timestamp=int(time.time()) + skew))

    assert response.status_code == 200, response.text


async def test_a_replayed_request_id_is_refused(send: Any, signer: Signer) -> None:
    """`routes/internal.php` rule 3: reject a repeated ``X-KB-Request-Id`` inside 120 s. The
    same signed bytes, sent twice — which is exactly what a network capture gives an attacker,
    and what a stuck proxy gives everyone else."""
    request = signer.build("POST", PATH, BODY)

    first = await send(request)
    second = await send(request)

    assert first.status_code == 200, first.text
    assert second.status_code == 401
    assert second.json()["error_class"] == "authentication"


async def test_a_second_request_with_a_fresh_id_is_not_a_replay(send: Any, signer: Signer) -> None:
    """Positive control for the replay test: a verifier that refused every second request would
    satisfy it."""
    assert (await send(signer.build("POST", PATH, BODY))).status_code == 200
    assert (await send(signer.build("POST", PATH, BODY))).status_code == 200


# ── the nonce, in the keyspace it is specified to live in ─────────────────────


async def test_the_nonce_is_keyed_on_key_id_and_request_id_with_no_org_segment(
    send: Any, signer: Signer, nonce_store: InMemoryCoordination
) -> None:
    """`valkey-keyspaces`: ``nonce:`` is the ONE family with no org segment, and the reason is
    ordering — the replay check runs as part of signature verification, before any organization
    has been resolved. Keying it on ``X-KB-Org-Id`` would make replay protection depend on a
    value verification has not yet established.
    """
    request = signer.build("POST", PATH, BODY)

    await send(request)

    key = f"nonce:k1:{request.request_id}"
    assert await nonce_store.get(key) is not None
    assert signer.org_id not in key


async def test_the_nonce_ttl_outlives_the_skew_window(
    send: Any, signer: Signer, nonce_store: InMemoryCoordination, transport_settings: Any
) -> None:
    """The two are one setting. A nonce that expires inside the skew window lets a replay
    arrive after the nonce is gone but while the timestamp still verifies — a replay window
    with extra steps. ``Settings`` enforces ``ttl > skew``; this asserts the verifier actually
    passes the setting through rather than hardcoding a number beside it."""
    request = signer.build("POST", PATH, BODY)

    await send(request)

    ttl = await nonce_store.ttl(f"nonce:k1:{request.request_id}")
    assert ttl > transport_settings.hmac_max_skew_seconds
    assert ttl <= transport_settings.replay_nonce_ttl_seconds


async def test_the_nonce_is_claimed_in_one_command_and_only_after_the_signature_verifies(
    send: Any, signer: Signer, nonce_store: InMemoryCoordination
) -> None:
    """Two properties in one assertion because they share a failure.

    Claiming *before* verification lets an unauthenticated caller burn a legitimate request's
    nonce by replaying its id with garbage bytes — replay protection turned into a denial of
    service against the only caller that matters. And a ``GET`` then ``SET`` is a race whose
    losing side lets the replay through.
    """
    forger = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k1", org_id=signer.org_id)
    request = forger.build("POST", PATH, BODY)

    assert (await send(request)).status_code == 401
    assert nonce_store.commands == [], "an unverified request touched the coordination store"

    # And the legitimate holder of that id is still able to use it.
    legitimate = signer.build("POST", PATH, BODY, request_id=request.request_id)
    assert (await send(legitimate)).status_code == 200
    assert [c[0] for c in nonce_store.commands] == ["SET"], "the claim was not a single command"


async def test_a_coordination_store_that_cannot_answer_fails_closed(
    send: Any, signer: Signer, signed_app: Any
) -> None:
    """ "We could not check for a replay" must never render as "not a replay". It is a 503 —
    a real dependency of ours being unavailable — and never a 200."""

    class Broken:
        async def set(self, *args: Any, **kwargs: Any) -> None:
            msg = "connection refused"
            raise OSError(msg)

    signed_app.state.nonce_store = Broken()

    response = await send(signer.build("POST", PATH, BODY))

    assert response.status_code == 503
    assert response.json()["error_class"] == "internal_dependency"
    assert "selected" not in response.json()


# ── the header set is inside the signature ────────────────────────────────────


@pytest.mark.parametrize(
    "header",
    [
        "X-KB-Org-Id",
        "X-KB-Operation",
        "X-KB-Actor-Type",
        "X-KB-Contract-Version",
        "X-KB-Config-Version",
        "X-KB-Deadline",
        "X-KB-Request-Id",
    ],
)
async def test_tampering_with_any_covered_header_after_signing_is_refused(
    send: Any, signer: Signer, header: str
) -> None:
    """THE ASSERTION THE WHOLE SCHEME EXISTS FOR, one header at a time.

    ``X-KB-Org-Id`` is the tenant scope for the entire data plane: a signature over method,
    path, timestamp and body alone leaves it forgeable, so a legitimately signed request would
    execute against another organization and defeat every downstream layer at once.
    """
    tampered = signer.build("POST", PATH, BODY).with_header(header, "01JQZ000000000000000000ZZZ")

    response = await send(tampered)

    assert response.status_code == 401


async def test_adding_an_unsigned_x_kb_header_after_signing_is_refused(
    send: Any, signer: Signer
) -> None:
    """The verifier recomputes the covered set from the headers ACTUALLY PRESENT, never from a
    caller-supplied signed-headers list — a list is itself attacker-controlled, so anything
    omitted from it can be added or dropped freely."""
    response = await send(signer.build("POST", PATH, BODY).with_header("X-KB-Bot-Id", "smuggled"))

    assert response.status_code == 401


async def test_removing_an_optional_x_kb_header_after_signing_is_refused(
    send: Any, signer: Signer
) -> None:
    signer.actor_id = "01JQZ0000000000000000000AC"
    request = signer.build("POST", PATH, BODY)
    del request.headers["X-KB-Actor-Id"]

    assert (await send(request)).status_code == 401


async def test_a_repeated_x_kb_header_is_refused_rather_than_resolved(
    signed_app: Any, signer: Signer
) -> None:
    """Header smuggling. A second ``X-KB-Org-Id`` would produce two canonical lines here while
    the signer produced one — or, worse, a downstream ``headers["x-kb-org-id"]`` reads the first
    while the signature covered both. Neither is worth reasoning about.

    THE DUPLICATE CARRIES THE SAME VALUE, and that is what makes this test discriminate. A
    *different* second value changes whichever one the mapping keeps, so the canonical string
    moves and the request 401s on the signature no matter what the duplicate rule does — the
    test would pass against a verifier that has no rule at all. An identical duplicate collapses
    to exactly the signed set, so a verifier without the rule answers **200**.

    Built with its own client because a duplicate header cannot be expressed through a ``dict``,
    which is the only reason this one test does not go through the ``send`` fixture.
    """
    request = signer.build("POST", PATH, BODY)
    raw = [(k.encode("latin-1"), v.encode("latin-1")) for k, v in request.headers.items()]
    raw.append((b"x-kb-org-id", signer.org_id.encode("latin-1")))

    transport = httpx.ASGITransport(app=signed_app, raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://ai-api") as client:
        response = await client.request(
            "POST", PATH, headers=httpx.Headers(raw), content=request.body
        )

    assert response.status_code == 401


async def test_a_non_ascii_x_kb_header_value_is_refused(signed_app: Any, signer: Signer) -> None:
    """Starlette decodes header bytes as latin-1 and this side encodes the canonical string as
    UTF-8, while PHP signs the wire bytes. On ASCII all three agree; off it they do not, and a
    request that verified here would not have verified there. Refuse rather than let the two
    planes disagree about what a byte is.

    THE SIGNATURE IS CORRECT, which is what makes this test about the ASCII rule rather than
    about a bad signature: the value is signed as the same ``str`` Starlette decodes the
    latin-1 byte back into, so a verifier without the rule would answer 200. httpx refuses to
    encode a non-ASCII header value at all, so the request is assembled from raw bytes.
    """
    request = signer.build("POST", PATH, BODY, extra_headers={"X-KB-Operation": "café"})
    raw = [(k.encode("latin-1"), v.encode("latin-1")) for k, v in request.headers.items()]

    transport = httpx.ASGITransport(app=signed_app, raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://ai-api") as client:
        response = await client.request(
            "POST", PATH, headers=httpx.Headers(raw), content=request.body
        )

    assert response.status_code == 401


async def test_a_body_that_differs_from_the_signed_bytes_is_refused(
    send: Any, signer: Signer
) -> None:
    """``sha256(body)`` is inside the canonical string, which is what integrity-protects the
    ``provider_credential`` field in transit (`kb-security-baseline`)."""
    request = signer.build("POST", PATH, BODY)
    swapped = type(request)(
        request.method, request.path, b'{"connections":[],"designated":{}}', request.headers
    )

    assert (await send(swapped)).status_code == 401


async def test_a_signature_for_another_path_is_not_portable_to_this_one(
    send: Any, signer: Signer
) -> None:
    """A body-only signature is replayable against any endpoint that accepts the same body."""
    elsewhere = signer.build("POST", "/internal/v1/chat/stream", BODY)
    moved = type(elsewhere)(elsewhere.method, PATH, elsewhere.body, elsewhere.headers)

    assert (await send(moved)).status_code == 401


@pytest.mark.parametrize(
    "signature",
    ["", "k1", "k1:", ":deadbeef", "deadbeef", "k1:deadbeef", "k1:" + "0" * 64, "K1:x", "../x:y"],
)
async def test_a_malformed_signature_header_is_refused_without_an_exception(
    send: Any, signer: Signer, signature: str
) -> None:
    """Every one of these is a 401 and not a 500. A 500 is a *distinguishable* answer, which is
    the oracle this scheme spends a dummy HMAC to avoid — and ``../x:y`` is the traversal shape
    ``app/core/keys.py`` exists to make harmless."""
    response = await send(signer.build("POST", PATH, BODY).with_header("X-KB-Signature", signature))

    assert response.status_code == 401


async def test_a_leading_zero_timestamp_is_refused_rather_than_reparsed(
    send: Any, signer: Signer
) -> None:
    """PHP would sign ``"01786000000"`` verbatim, so a verifier that rendered ``int()`` back
    would compute different bytes than the signer. This side refuses any timestamp that does not
    render back to itself, which makes the round-trip unrepresentable instead of merely
    handled."""
    padded = str(int(time.time())).rjust(11, "0")
    response = await send(
        signer.build("POST", PATH, BODY, extra_headers={"X-KB-Timestamp": f"0{padded}"})
    )

    assert response.status_code == 401


# ── missing transport objects are OUR defect, not a downstream brownout ───────


@pytest.mark.parametrize("attribute", ["settings", "key_ring", "nonce_store"])
async def test_a_process_with_no_transport_state_refuses_with_a_500_not_a_503(
    send: Any, signer: Signer, signed_app: Any, attribute: str
) -> None:
    """ADR-029. A missing key ring is a deployment that never loaded one — our defect — and no
    number of retries loads it. 503/retryable would put a client on a full backoff ladder
    against a bug."""
    delattr(signed_app.state, attribute)

    response = await send(signer.build("POST", PATH, BODY))

    assert response.status_code == 500
    assert response.json()["retryable"] is False
    assert "selected" not in response.json()


# ── no bypass ─────────────────────────────────────────────────────────────────
#
# `test_the_router_declares_signature_verification` is deliberately NOT here: it lives in
# `test_embedding_readiness.py`, beside the router it inspects, and a second copy would be a
# second thing to update when a router moves.


def test_no_bypass_exists_to_be_exercised() -> None:
    """`pytest-ai-service` NN6: no ``verify_hmac`` skip flag, no ``internal=True`` kwarg, no
    fixture that widens a check. If a test is awkward without one, the production code is wrong.
    """
    import inspect

    from app.api import deps

    source = inspect.getsource(deps)

    for bypass in ("skip_verification", "internal=True", "allow_unsigned", "DEBUG_SKIP"):
        assert bypass not in source, f"{bypass} is a signature bypass in app/api/deps.py"


# ── the identifier a support engineer greps for, on the failures they grep for ─
#
# `app/main.py`'s envelope reads `request.state.request_id`, and `request_context` — which used
# to be the only thing that set it — runs AFTER this router's `verify_hmac`. So every
# authentication-failure envelope carried `request_id: null`: the one identifier that ties a
# refusal to the Laravel request that caused it, absent from the class of failure most likely to
# need it. Stamping it inside `verify_hmac` is what these pin, together with the two properties
# that make that safe — the header is format-validated first, and nothing else about the request
# is trusted.


async def test_an_authentication_failure_carries_the_request_id_the_caller_sent(
    send: Any, signer: Signer
) -> None:
    """The refusal case. Everything about the request is right except the secret."""
    forger = Signer(secret=b"a-different-32-byte-hmac-secret!", key_id="k1", org_id=signer.org_id)
    request = forger.build("POST", PATH, BODY)

    response = await send(request)

    assert response.status_code == 401
    assert response.json()["request_id"] == request.request_id


@pytest.mark.parametrize(
    "shape",
    [
        "X-KB-Signature",  # unsigned
        "X-KB-Timestamp",  # unverifiable timestamp
    ],
)
async def test_the_id_is_stamped_before_every_check_that_can_refuse(
    send: Any, signer: Signer, shape: str
) -> None:
    """Not only on the signature check. Each of these refuses at a different line, and a stamp
    placed one line too late renders ``null`` on whichever refusals precede it."""
    request = signer.build("POST", PATH, BODY)
    broken = request.with_header(shape, "")
    del broken.headers[shape]

    response = await send(broken)

    assert response.status_code == 401
    assert response.json()["request_id"] == request.request_id


async def test_a_malformed_request_id_is_refused_and_never_reaches_the_envelope(
    send: Any, signer: Signer
) -> None:
    """The id is the caller's claim on a refused request, so it is stamped only after
    ``_REQUEST_ID_RE`` accepts it — 128 characters of ``[0-9A-Za-z_.-]`` and nothing else.

    An unvalidated header would go into the error body and into every log line for the request
    verbatim, which is a caller-controlled string in a field three runtimes render.
    """
    forged = 'a b"c<script>'
    response = await send(signer.build("POST", PATH, BODY, request_id=forged))

    assert response.status_code == 401
    assert response.json()["request_id"] is None
    assert "script" not in response.text


async def test_an_over_long_request_id_is_refused_rather_than_truncated(
    send: Any, signer: Signer
) -> None:
    """129 characters of otherwise-legal alphabet. Truncating would make two distinct ids one
    nonce key, which is a replay window that only opens for a caller that pads its ids."""
    response = await send(signer.build("POST", PATH, BODY, request_id="a" * 129))

    assert response.status_code == 401
    assert response.json()["request_id"] is None


async def test_a_dependency_failure_envelope_carries_it_too(
    send: Any, signer: Signer, signed_app: Any
) -> None:
    """The 503 path, which renders through the same envelope from a different handler."""

    class Broken:
        async def set(self, *args: Any, **kwargs: Any) -> None:
            msg = "connection refused"
            raise OSError(msg)

    signed_app.state.nonce_store = Broken()
    request = signer.build("POST", PATH, BODY)

    response = await send(request)

    assert response.status_code == 503
    assert response.json()["request_id"] == request.request_id

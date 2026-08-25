"""The data plane's only outbound HTTP client: the signed ingestion status callback.

Everything else on this seam runs the other way — Laravel signs, this service verifies. This is
the return leg, and until it existed the ``c1`` callback key ring was loaded by every worker and
used by nothing.

THE RING IS THE FINDING, SO IT IS THE FIRST THING THIS MODULE SAYS
--------------------------------------------------------------------
There are **two** rings and they are not interchangeable. ``k1``/``k2`` are Laravel→FastAPI;
``c1``/``c2`` are FastAPI→Laravel. Phase C's worst defect was the inbound verifier resolving
secrets from the *outbound* ring, and it was both a liveness bug and a security bug at once:
every callback 401s so no source ever leaves ``queued``, and the only key that verifies inbound
is the one held by the busiest outbound path, so leaking it lets anyone forge a callback with
any ``X-KB-Org-Id`` and publish or retire any tenant's version.

**The suite could not catch it, because the test helper signed with the same wrong ring** — a
green callback suite was evidence that two mistakes agreed with each other. So the rule for
anything in this file: the ring is named at the call, ``callback=True`` is passed explicitly,
and a test that constructs a ring must give the two directions **different** secrets or it
proves nothing.

WHY THE CANONICAL STRING IS BUILT BY ``core.signing`` AND NOT HERE
-------------------------------------------------------------------
It is a wire format shared with a PHP implementation. One builder, used by the verifier and the
signer, is what makes a drift a test failure instead of a 401 nobody can reproduce — and the
header set is *inside* the string, so neither signer can be corrected alone (ADR-067's
consequence, from the other direction).
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
import uuid
from typing import Any, Final

import httpx

from app.core.errors import ErrorClass, KbError
from app.core.signing import DEFAULT_PREFIX, canonical_string

__all__ = [
    "CALLBACK_PATH",
    "SIGNED_HEADER_NAMES",
    "CallbackEmitter",
    "build_emitter",
    "install_emitters",
]

#: The one path this service POSTs to. Declared rather than composed at the call site because it
#: is inside the signature: a path that differs by a trailing slash is a 401, not a 404.
CALLBACK_PATH: Final[str] = "/internal/v1/callbacks/ingestion"

#: Every ``X-KB-*`` header this emitter sends, **as data**, because the set is inside the
#: canonical string. `VerifyInternalSignature` recomputes the covered set from the headers it
#: actually receives — excluding exactly one, ``X-KB-Signature`` — rather than from a
#: caller-supplied list, so adding or dropping a name here changes the bytes the far side hashes
#: and neither signer can be corrected alone.
#:
#: Declared rather than left implicit in the dict literal below so two things can be checked
#: against it: that this emitter sends exactly these, and that the cross-language matrix pins the
#: canonical bytes for exactly these. `X-KB-Bot-Id` is absent and its absence is ADR-067.
SIGNED_HEADER_NAMES: Final[tuple[str, ...]] = (
    "X-KB-Actor-Type",
    "X-KB-Operation",
    "X-KB-Org-Id",
    "X-KB-Request-Id",
    "X-KB-Timestamp",
)


class CallbackEmitter:
    """Signs and delivers one progress or readiness frame.

    Holds the key ring and an ``httpx.Client``. One instance per worker process, installed at
    bootstrap, because a client per call re-establishes TLS for every stage boundary of every
    document.
    """

    def __init__(
        self,
        *,
        base_url: str,
        key_ring: Any,
        key_id: str,
        prefix: str,
        timeout: float = 10.0,
        client: httpx.Client | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._ring = key_ring
        self._key_id = key_id
        self._prefix = prefix
        # `client` is a seam for tests, and it is a NAMED PARAMETER rather than an attribute a
        # test reaches in and replaces. The difference matters here more than usual: what the
        # security suite has to be able to inspect is the exact bytes and headers that leave
        # this object, and a test that patches a private attribute is one refactor away from
        # asserting against a transport the production path no longer uses.
        self._client = client if client is not None else httpx.Client(timeout=timeout)

    def post(self, body: dict[str, Any], *, org_id: str, operation: str) -> dict[str, Any]:
        """Deliver one frame and return the acknowledgement. Raises on anything but a 2xx.

        THE ACKNOWLEDGEMENT IS THE POINT OF THE RETURN VALUE, and it is the one thing on this
        seam a caller genuinely cannot infer. A refused frame is a **200** with
        ``applied: false`` — `IngestionAcknowledgementResource` explains why at length — so a
        caller that ignores the body cannot tell "the version was created" from "this run was
        superseded and everything it does next is wasted". `intake` reads ``reason`` to decide
        whether to dispatch the run at all.

        **A transport failure here must not be swallowed.** ``publish.report_readiness`` says
        why in one sentence: a version that indexed and verified but never reported readiness
        stays unpublished forever with a full, correct point set on disk — invisible to
        retrieval and invisible to deletion. So this raises ``internal_dependency`` and the
        task's retry ladder handles it.
        """
        # SERIALIZE ONCE AND SIGN THOSE EXACT BYTES. Re-encoding JSON to hash it is not
        # byte-stable — key order and separator whitespace both move — and the failure is an
        # intermittent 401 on a request that looks correct in every log.
        payload = json.dumps(body, separators=(",", ":"), sort_keys=True).encode()

        timestamp = str(int(time.time()))
        headers = {
            "X-KB-Org-Id": org_id,
            # NO X-KB-BOT-ID. A knowledge source is organization-owned (ADR-067), so there is no
            # single bot id to send; inventing one would put a bot inside a signature that
            # scopes nothing. `kb-internal-api-contracts`:64 lists ingestion among the
            # bot-scoped operations and is wrong about this.
            "X-KB-Actor-Type": "system",
            "X-KB-Operation": operation,
            # A UUID4 AND NOT A ULID, WHICH IS A DEVIATION WORTH NAMING. Laravel's replay check
            # keys on `nonce:{key_id}:{request_id}` and its comment calls this "the request
            # ULID"; nothing validates the shape, and what the nonce actually needs is global
            # uniqueness, which a UUID4 has. The data plane ships no ULID generator, and adding
            # one to mint a decorative id would be a second identifier scheme to keep in step.
            "X-KB-Request-Id": str(uuid.uuid4()),
            "X-KB-Timestamp": timestamp,
        }

        canonical = canonical_string(
            method="POST",
            path=CALLBACK_PATH,
            timestamp=timestamp,
            body=payload,
            kb_headers=headers,
            prefix=self._prefix,
        )

        # `callback=True`, EXPLICITLY. This is the line Phase C got wrong in the other
        # direction, and it is one keyword away from being wrong again in a way that a suite
        # sharing one secret between the rings cannot see.
        secret = self._ring.secret_for(self._key_id, callback=True)
        if secret is None:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                f"no callback secret for key id {self._key_id!r} in this worker's ring. The "
                "outbound ring is `c1`/`c2` and is separate from the inbound `k1`/`k2`; a "
                "callback signed with an inbound key is a silent 401 on every job report",
                retryable=False,
            )

        signature = hmac.new(secret, canonical, hashlib.sha256).hexdigest()

        try:
            response = self._client.post(
                f"{self._base_url}{CALLBACK_PATH}",
                content=payload,
                headers={
                    **headers,
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    # `key_id:hex` IN ONE HEADER, AND THERE IS NO `X-KB-Key-Id`. The key id
                    # travels inside the signature value because `VerifyInternalSignature`
                    # excludes exactly one header — `X-KB-Signature` — from the covered set and
                    # signs every other `x-kb-*` it finds. A separate key-id header would
                    # therefore be inside the string the verifier computes and outside the one
                    # this signer computed, which is a 401 on every callback ever sent: the
                    # source never leaves `queued`, and nothing on either side logs a reason.
                    "X-KB-Signature": f"{self._key_id}:{signature}",
                },
            )
        except httpx.HTTPError as exc:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                "the control plane could not be reached to report ingestion progress. The "
                "version's work is committed; only the report failed, so a retry is safe",
                retryable=True,
            ) from exc

        if response.status_code >= 400:
            # The body is NOT relayed. A 422 from the control plane echoes the frame, and the
            # frame carries identifiers; the status and the operation are enough to act on.
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                f"the control plane refused the {operation} callback with status "
                f"{response.status_code}",
                # A 401 or a 422 will not come right on a retry — a wrong ring and a wrong shape
                # are both permanent — while a 5xx will.
                retryable=response.status_code >= 500,
            )

        try:
            acknowledgement = response.json()
        except ValueError as exc:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                f"the {operation} callback returned {response.status_code} with a body that is "
                "not JSON. The frame may or may not have applied, which is why this is "
                "retryable: every frame on this seam is idempotent under its sequence guard",
                retryable=True,
            ) from exc

        if not isinstance(acknowledgement, dict):
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                f"the {operation} callback returned a JSON {type(acknowledgement).__name__} "
                "rather than an acknowledgement object",
                retryable=False,
            )
        return acknowledgement

    def close(self) -> None:
        """Release the HTTP client. Called by the worker's process-shutdown hook."""
        self._client.close()


def build_emitter(settings: Any, key_ring: Any) -> CallbackEmitter:
    """One emitter from this process's settings and ring. Called by the worker bootstrap."""
    return CallbackEmitter(
        base_url=settings.core_api_url,
        key_ring=key_ring,
        key_id=settings.callback_active_key_id,
        # THE OUTBOUND PREFIX IS THE DEFAULT, NOT A SETTING, AND THAT IS ON PURPOSE.
        # `Settings.accepted_signing_prefixes` is the INBOUND list — what this service
        # will verify — and reusing it here would make the two directions move together
        # when the whole point of a two-deploy prefix bump is that they move apart.
        prefix=DEFAULT_PREFIX,
    )


def install_emitters(*, settings: Any, key_ring: Any) -> CallbackEmitter:
    """Build one emitter for this process and wire it into both of `publish`'s hooks.

    THE WIRING IS THE POINT AND IT IS EASY TO SKIP. `publish.report_readiness` raises when no
    emitter is installed — deliberately, because the alternative is a version that indexed and
    verified, whose points are on disk and correct, and which nothing will ever activate: it is
    invisible to retrieval because it is not the active version, and invisible to deletion
    because no row names it. A worker that forgot this call would run the entire pipeline
    successfully and then fail at the last statement, every time.

    ONE EMITTER, TWO HOOKS. The hooks stay separate because their *callers* differ in whether a
    transport failure is fatal, not because the transport does; a single `httpx.Client` behind
    both is what keeps a stage boundary from re-establishing TLS.
    """
    from app.ingestion import publish

    emitter = build_emitter(settings, key_ring)

    def emit(frame: dict[str, Any], org_id: str, operation: str) -> dict[str, Any]:
        return emitter.post(frame, org_id=org_id, operation=operation)

    publish.set_progress_emitter(emit)
    publish.set_readiness_emitter(emit)
    return emitter

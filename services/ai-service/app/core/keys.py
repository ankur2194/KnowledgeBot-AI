"""Signing key material for the two internal directions.

`kb-internal-api-contracts` fixes the wire form as ``X-KB-Signature = key_id + ":" +
hex(hmac_sha256(secret[key_id], canonical))``, and states that two key ids are live at once
during a rotation: verifiers accept both, signers use the newer. So key material has to be
addressable **by key id**, and the id is what stays stable while the rotation moves around it.

That is why the mounted files are named for the id they hold — ``hmac_key_k1``, not
``hmac_key_current``. A file named for its rotation ROLE cannot answer "which id is this",
and the same physical key would have to be renamed on every rotation, in lockstep with the
one identifier that exists precisely so it never has to change.

Two directions, deliberately disjoint key sets (see `Settings.hmac_active_key_id`):

* ``k*`` — request signing, Laravel to this service.
* ``c*`` — callback signing, this service back to Laravel.

Both are signed so that a compromised outbound key cannot forge a callback. Sharing one key
set silently discards that property while every test still passes.

THE SECURITY PROPERTY THIS MODULE EXISTS FOR
--------------------------------------------
``key_id`` ARRIVES FROM THE WIRE. It is attacker-chosen on every unauthenticated request.

So the ring is built ONCE, at startup, from a directory listing, and a lookup is a mapping
access against ids that were already there. A path is never constructed from a caller's
bytes. The alternative — ``hmac_key_dir / f"hmac_key_{key_id}"`` — reads as ordinary code and
hands an attacker a file selector: ``../``-style traversal aims it anywhere the process can
read, and the payoff is not disclosure but forgery. Aim it at ANY zero-length file and the
HMAC key becomes ``b""``; the attacker then computes a perfectly valid signature over a
canonical string of their choosing, for any organization, and every downstream tenant filter
agrees with the result. Validating the id after building the path closes traversal and leaves
the empty-file case; loading eagerly closes both, and costs one directory listing per boot.

Empty and short files are rejected at load, loudly, for the same reason: a truncated write or
a placeholder that never got filled in must fail the process, not quietly become a key that
some other equally-empty peer agrees with.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path
from typing import Final

__all__ = [
    "CALLBACK_KEY_PREFIX",
    "REQUEST_KEY_PREFIX",
    "KeyRing",
    "KeyRingError",
    "load_key_ring",
]

#: Filename stems. ``callback_hmac_key_c1`` does not collide with the request glob because
#: both are anchored at the start of the name.
REQUEST_KEY_PREFIX: Final = "hmac_key_"
CALLBACK_KEY_PREFIX: Final = "callback_hmac_key_"

#: A key id is an opaque label, not a path segment. Anchored, lowercase, no separators — so
#: ``.``, ``/`` and ``\`` cannot appear even before the mapping lookup makes them harmless.
_KEY_ID_RE: Final = re.compile(r"\A[a-z][a-z0-9]{0,7}\Z")

#: HMAC-SHA256 keys shorter than the block-relevant 32 bytes are a configuration mistake.
#: `scripts/dev/bootstrap.sh` writes ``openssl rand -base64 32``, which is 44 characters.
_MIN_KEY_BYTES: Final = 32


class KeyRingError(RuntimeError):
    """Key material is missing, malformed, or unreadable.

    Raised at startup, never per request. The message names the FILE and the KEY ID and
    never the material — this string reaches logs and an error envelope.
    """


@dataclass(frozen=True)
class KeyRing:
    """Immutable id-to-secret mappings for both signing directions."""

    request: dict[str, bytes]
    callback: dict[str, bytes]

    def __repr__(self) -> str:
        """Redacted by construction.

        A dataclass' generated repr renders every field, so the default would put both
        signing keys into any log line, traceback or debugger frame that touches a KeyRing.
        Ids are safe to show and are what an operator actually needs.
        """
        return f"KeyRing(request={sorted(self.request)!r}, callback={sorted(self.callback)!r})"

    def secret_for(self, key_id: str, *, callback: bool = False) -> bytes | None:
        """Return the secret for ``key_id``, or ``None`` if this ring does not hold it.

        ``None`` is the whole vocabulary for "unknown id" — never a fallback to the active
        key, and never a default. Falling back would make the ``key_id`` field decorative
        and let a stale signer verify forever against whatever the ring happened to hold.

        The caller turns ``None`` into the same rejection as a bad signature, with the same
        timing and the same message: distinguishing "unknown key id" from "wrong signature"
        tells an attacker which ids exist.
        """
        if not _KEY_ID_RE.match(key_id):
            return None
        return (self.callback if callback else self.request).get(key_id)


def _read_key(path: Path) -> bytes:
    """Read one key file, or raise ``KeyRingError`` explaining which and why."""
    try:
        raw = path.read_bytes()
    except OSError as exc:
        # strerror, not the exception's str: the latter renders the full path twice and is
        # the kind of message that gets pasted into a ticket.
        raise KeyRingError(f"{path.name}: unreadable ({exc.strerror})") from exc

    # A trailing newline is the difference between `openssl rand ... > file` and the same
    # file after an editor saves it. One byte, and the only symptom is that every internal
    # call 401s with a message that points at neither side.
    secret = raw.strip()

    if not secret:
        raise KeyRingError(
            f"{path.name}: empty. An empty signing key verifies against any other empty-keyed "
            f"peer, so this fails the process rather than starting a service that trusts "
            f"forged signatures."
        )
    if len(secret) < _MIN_KEY_BYTES:
        raise KeyRingError(
            f"{path.name}: {len(secret)} bytes, below the {_MIN_KEY_BYTES}-byte floor"
        )
    return secret


def _load_direction(directory: Path, prefix: str) -> dict[str, bytes]:
    """Build one direction's mapping from every file matching ``prefix``."""
    found: dict[str, bytes] = {}
    for path in sorted(directory.glob(f"{prefix}*")):
        # A missing Compose `file:` source becomes a DIRECTORY rather than an error, which is
        # the same fail-open shape `scripts/ops/preflight.sh` guards at deploy time. Skipping
        # it here would start the service with a silently smaller ring.
        if not path.is_file():
            raise KeyRingError(f"{path.name}: not a regular file (a directory?)")
        key_id = path.name[len(prefix) :]
        if not _KEY_ID_RE.match(key_id):
            raise KeyRingError(f"{path.name}: {key_id!r} is not a well-formed key id")
        found[key_id] = _read_key(path)
    return found


def load_key_ring(
    directory: Path,
    *,
    active_request_key_id: str,
    active_callback_key_id: str,
) -> KeyRing:
    """Build the ring once, at startup, and assert both signers can actually sign.

    Verification tolerates extra ids — that is what makes a rotation survivable. Signing does
    not: an active id with no material means this process cannot sign at all, and for the
    callback direction the symptom is that async jobs never report terminal state while every
    health check stays green.
    """
    if not directory.is_dir():
        raise KeyRingError(f"{directory}: signing key directory does not exist")

    ring = KeyRing(
        request=_load_direction(directory, REQUEST_KEY_PREFIX),
        callback=_load_direction(directory, CALLBACK_KEY_PREFIX),
    )

    for label, active, held in (
        ("request", active_request_key_id, ring.request),
        ("callback", active_callback_key_id, ring.callback),
    ):
        if active not in held:
            raise KeyRingError(
                f"the active {label} key id {active!r} has no material in {directory} "
                f"(present: {sorted(held) or 'none'})"
            )

    return ring

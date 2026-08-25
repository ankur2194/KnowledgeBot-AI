"""Opening a provider credential sealed by the control plane. The data plane's read half.

Laravel's ``App\\Support\\Crypto\\CredentialVault`` seals; this opens. **There is no ``seal``
here and there must not be one** — a second writer of the envelope is a second definition of
the format, and the two would diverge in the direction nobody tests: an envelope this service
wrote that Laravel cannot open is discovered at the next rotation, months later, on a
credential nobody can replace without deleting the connection.

THE FORMAT, AND THE THREE PLACES IT CAN GO WRONG SILENTLY
----------------------------------------------------------
Two levels. A random **data key** encrypts the credential; the **KEK** encrypts the data key.
Both levels use the same envelope layout and the same cipher::

    envelope = iv(12) || tag(16) || ciphertext        AES-256-GCM

* **The tag is in the MIDDLE, not appended.** Every reference implementation and most of the
  internet appends it, so the natural port of this format produces an envelope that decrypts
  to garbage — except GCM authenticates, so it does not decrypt at all and the symptom is an
  authentication failure that reads like key corruption.
* **The KEK is DERIVED, never used raw.** ``hash('sha256', trim($kek), true)`` — the file may
  hold raw bytes, base64 or hex, and the derivation is what stops its encoding becoming an
  operational detail. Skipping the ``trim`` is the whole bug: a secret file with a trailing
  newline derives a different key, and the failure is indistinguishable from a wrong KEK.
* **A failure here is never "try without encryption".** There is no fallback path and no
  plaintext branch; a credential that cannot be opened fails the run.

WHY THIS IS ``core`` AND NOT ``providers``
-------------------------------------------
``app/providers/`` maps vendor wire formats. This maps *our* envelope, and putting it beside
the adapters would invite the next reader to add a per-vendor variant. One format, one module,
one direction.
"""

from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Final

from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError

__all__ = ["IV_BYTES", "TAG_BYTES", "CredentialUnavailable", "open_credential", "read_kek"]

#: Matches ``CredentialVault::IV_BYTES``. AES-GCM's standard nonce width, and the one every
#: implementation interoperates on — a 16-byte nonce is legal and is a different key stream.
IV_BYTES: Final[int] = 12

#: Matches ``CredentialVault::TAG_BYTES``, and it sits BETWEEN the iv and the ciphertext.
TAG_BYTES: Final[int] = 16


class CredentialUnavailable(KbError):
    """The credential could not be opened.

    Its own class so a caller can tell "this organization's key is unusable" from "the vendor
    rejected our key", which are the same HTTP status and completely different operator
    actions. It never carries the ciphertext, the key, or their lengths: a length is a small
    oracle and it is not worth the debugging convenience.
    """


def read_kek(path: Path | None) -> bytes:
    """Derive the 32-byte key-encrypting key from the mounted secret file.

    ``sha256(trim(contents))``, byte-for-byte what Laravel's ``kekKey()`` does. Reimplemented
    rather than shared because there is nothing to share across two runtimes — which is exactly
    why the derivation is pinned in both places and stated in both docstrings.

    ``trim`` matters more than it looks: PHP's ``trim`` strips ``" \\t\\n\\r\\0\\x0B"``, and a
    secret file written by an editor almost always ends in a newline. Deriving from the
    untrimmed bytes yields a valid-looking 32-byte key that opens nothing, and the failure
    presents as corruption rather than as configuration.
    """
    if path is None or not path.is_file():
        raise CredentialUnavailable(
            ErrorClass.INTERNAL_DEPENDENCY,
            "KB_KEK is not mounted in this container, so no provider credential can be "
            "opened. A missing key-encrypting key is never a reason to proceed unwrapped",
            retryable=False,
        )
    raw = path.read_bytes()
    # PHP's trim character set, exactly. Python's `.strip()` with no argument strips a WIDER
    # set (every Unicode space), which would agree on every realistic file and disagree on one
    # containing a vertical tab — a difference that would only ever be found in production.
    return hashlib.sha256(raw.strip(b" \t\n\r\0\x0b")).digest()


def open_credential(
    *, credential_ciphertext: bytes, data_key_ciphertext: bytes, kek: bytes
) -> SecretStr:
    """Open one sealed credential. Two unwraps, KEK first.

    Returns ``SecretStr`` rather than ``str`` so the value cannot reach a log line, a traceback
    frame or an ``assert`` message by being formatted — non-negotiable 9 has no ingestion
    exemption, and the places a bare string leaks from are all places nobody chose.
    """
    data_key = _decrypt(data_key_ciphertext, kek, level="data key")
    if len(data_key) != 32:
        raise CredentialUnavailable(
            ErrorClass.INTERNAL_DEPENDENCY,
            "the unwrapped data key is not 256 bits. The envelope opened and authenticated, so "
            "this is a format disagreement between the planes rather than a wrong KEK",
            retryable=False,
        )
    return SecretStr(_decrypt(credential_ciphertext, data_key, level="credential").decode())


def _decrypt(envelope: bytes, key: bytes, *, level: str) -> bytes:
    """One AES-256-GCM unwrap over ``iv || tag || ciphertext``.

    The layout is split here rather than inline at both call sites so the tag's position is
    stated once. See the module docstring for why that position is the trap.
    """
    from cryptography.exceptions import InvalidTag
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    if len(envelope) <= IV_BYTES + TAG_BYTES:
        raise CredentialUnavailable(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"the sealed {level} is too short to be an envelope. It is truncated rather than "
            "wrong, which usually means it was read through a cast that drained the stream "
            "rather than that the key is bad",
            retryable=False,
        )

    iv = envelope[:IV_BYTES]
    tag = envelope[IV_BYTES : IV_BYTES + TAG_BYTES]
    ciphertext = envelope[IV_BYTES + TAG_BYTES :]

    try:
        # `cryptography` takes the tag APPENDED, which is the opposite of our layout — so it is
        # moved here, at the one place that knows where it was.
        return AESGCM(key).decrypt(iv, ciphertext + tag, None)
    except InvalidTag:
        raise CredentialUnavailable(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"the sealed {level} did not authenticate. GCM cannot tell a wrong key from a "
            "modified envelope, so this is either the wrong KEK for this deployment or a "
            "tampered row — and neither is retryable",
            retryable=False,
        ) from None

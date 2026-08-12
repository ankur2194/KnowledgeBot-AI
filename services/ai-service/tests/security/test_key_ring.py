"""The key ring's security properties, which are not the same as its behaviour.

`key_id` is attacker-chosen: it arrives inside `X-KB-Signature` on every request, including
unauthenticated ones. The tests that matter here are therefore about what a hostile id CANNOT
reach, and they are written against the public surface so that a refactor to path-building —
the natural, readable, wrong implementation — fails them.

No containers, no app factory, no network: a security assertion that needs a running service
stops running on the day a container is slow, which is the day it is needed.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from app.core.keys import (
    CALLBACK_KEY_PREFIX,
    REQUEST_KEY_PREFIX,
    KeyRing,
    KeyRingError,
    load_key_ring,
)

pytestmark = pytest.mark.security

#: 44 characters, the shape `openssl rand -base64 32` produces in `scripts/dev/bootstrap.sh`.
_FIXTURE_KEY = b"3mSk1Vv0pQ8xT2rL9nB4hJ7cW5yZ6aD1fG0eR3uI8oM="
_OTHER_KEY = b"9zQ2wE4rT6yU8iO0pA1sD3fG5hJ7kL9xC2vB4nM6qW8="


def _ring_dir(tmp_path: Path, **files: bytes) -> Path:
    directory = tmp_path / "secrets"
    directory.mkdir()
    for name, body in files.items():
        (directory / name).write_bytes(body)
    return directory


def _valid_dir(tmp_path: Path) -> Path:
    return _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": _FIXTURE_KEY,
            f"{REQUEST_KEY_PREFIX}k2": _OTHER_KEY,
            f"{CALLBACK_KEY_PREFIX}c1": _FIXTURE_KEY,
        },
    )


def _load(directory: Path) -> KeyRing:
    return load_key_ring(directory, active_request_key_id="k1", active_callback_key_id="c1")


# ── the attacker-controlled id ───────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    "hostile_id",
    [
        "../../../../etc/hostname",
        "../hmac_key_k1",
        "/etc/hostname",
        "k1/../../k1",
        "k1\x00",
        "K1",
        "",
        ".",
        "..",
        "k" * 64,
    ],
)
def test_hostile_key_id_resolves_to_nothing(tmp_path: Path, hostile_id: str) -> None:
    """No caller-supplied id reaches the filesystem or yields material.

    Traversal is the obvious half. The half that actually forges signatures is aiming the id
    at ANY zero-length file the process can read: an empty HMAC key verifies against any
    other empty-keyed peer, so the attacker signs a canonical string of their choosing for
    an organization of their choosing.
    """
    ring = _load(_valid_dir(tmp_path))
    assert ring.secret_for(hostile_id) is None
    assert ring.secret_for(hostile_id, callback=True) is None


def test_empty_file_elsewhere_on_disk_is_unreachable(tmp_path: Path) -> None:
    """The concrete forgery: a reachable empty file must not become a key."""
    directory = _valid_dir(tmp_path)
    decoy = tmp_path / "empty"
    decoy.write_bytes(b"")

    ring = _load(directory)

    for spelling in ("../empty", str(decoy), "empty"):
        assert ring.secret_for(spelling) is None


def test_unknown_id_never_falls_back_to_the_active_key(tmp_path: Path) -> None:
    """`None`, not the active key. A fallback makes `key_id` decorative."""
    ring = _load(_valid_dir(tmp_path))
    assert ring.secret_for("k9") is None


def test_the_two_directions_are_disjoint(tmp_path: Path) -> None:
    """A request key must not verify a callback.

    Both directions are signed so a compromised outbound key cannot forge an inbound one.
    Resolving `k1` against the callback ring silently discards exactly that property.
    """
    ring = _load(_valid_dir(tmp_path))
    assert ring.secret_for("k1") == _FIXTURE_KEY
    assert ring.secret_for("k1", callback=True) is None
    assert ring.secret_for("c1", callback=True) == _FIXTURE_KEY
    assert ring.secret_for("c1") is None


# ── material that must fail the process rather than degrade ──────────────────────────────


def test_empty_key_file_raises(tmp_path: Path) -> None:
    directory = _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": b"",
            f"{CALLBACK_KEY_PREFIX}c1": _FIXTURE_KEY,
        },
    )
    with pytest.raises(KeyRingError, match="empty"):
        _load(directory)


def test_short_key_file_raises(tmp_path: Path) -> None:
    directory = _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": b"tooshort",
            f"{CALLBACK_KEY_PREFIX}c1": _FIXTURE_KEY,
        },
    )
    with pytest.raises(KeyRingError, match="below the"):
        _load(directory)


def test_directory_in_place_of_a_key_file_raises(tmp_path: Path) -> None:
    """A missing Compose `file:` source becomes a directory, not an error."""
    directory = _valid_dir(tmp_path)
    (directory / f"{REQUEST_KEY_PREFIX}k3").mkdir()
    with pytest.raises(KeyRingError, match="not a regular file"):
        _load(directory)


def test_active_id_without_material_raises(tmp_path: Path) -> None:
    """Verification tolerates extra ids; signing does not tolerate a missing one."""
    directory = _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": _FIXTURE_KEY,
            f"{CALLBACK_KEY_PREFIX}c2": _FIXTURE_KEY,
        },
    )
    with pytest.raises(KeyRingError, match="active callback key id"):
        _load(directory)


def test_trailing_newline_is_stripped(tmp_path: Path) -> None:
    """One byte between `>` redirection and an editor save; symptom is a bare 401."""
    directory = _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": _FIXTURE_KEY + b"\n",
            f"{CALLBACK_KEY_PREFIX}c1": _FIXTURE_KEY,
        },
    )
    assert _load(directory).secret_for("k1") == _FIXTURE_KEY


# ── the material must not leak through a repr ────────────────────────────────────────────


def test_repr_shows_ids_and_never_material(tmp_path: Path) -> None:
    """A dataclass' generated repr would render both keys into every traceback."""
    ring = _load(_valid_dir(tmp_path))
    rendered = repr(ring)

    assert _FIXTURE_KEY.decode() not in rendered
    assert _OTHER_KEY.decode() not in rendered
    assert "k1" in rendered and "c1" in rendered


def test_error_messages_never_carry_material(tmp_path: Path) -> None:
    """`KeyRingError` reaches logs; it names the file and the id, never the secret."""
    material = b"correct-horse-battery-staple"
    directory = _ring_dir(
        tmp_path,
        **{
            f"{REQUEST_KEY_PREFIX}k1": material,
            f"{CALLBACK_KEY_PREFIX}c1": _FIXTURE_KEY,
        },
    )
    with pytest.raises(KeyRingError) as caught:
        _load(directory)

    rendered = str(caught.value)
    assert material.decode() not in rendered
    assert _FIXTURE_KEY.decode() not in rendered
    # The id and the length are what an operator needs, and neither is the secret.
    assert f"{REQUEST_KEY_PREFIX}k1" in rendered

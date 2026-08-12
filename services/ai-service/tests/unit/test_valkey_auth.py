"""Valkey ACL credentials: how they are joined to a URL, and how they must not be.

`users.acl` sets `user default off`, so every connection authenticates. The credential
arrives as a mounted file rather than inside the URL, because a URL is an environment value
and an environment value is in `docker inspect` and in every rendered config.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from pydantic import ValidationError

from app.core.config import Settings, authenticated_valkey_url


def _settings(**overrides: object) -> Settings:
    # No database settings are passed: `Settings` no longer requires a DSN, because a DSN
    # carries a password and `ai-beat` and `ai-worker-crawl` legitimately hold none.
    return Settings(**overrides)  # type: ignore[arg-type]


def test_no_password_path_leaves_the_url_alone() -> None:
    """Local development runs a Valkey with no ACL file."""
    url = "redis://valkey-core:6379/0"
    joined = authenticated_valkey_url(url, username="kb-ai", password_path=None)
    assert joined.get_secret_value() == url


def test_base64_password_is_percent_encoded(tmp_path: Path) -> None:
    """The bug this function exists to prevent.

    `openssl rand -base64 24` emits `+`, `/` and `=`. A raw `/` in the userinfo terminates
    the authority component, so the host becomes `kb-ai:pa` and the rest becomes a path —
    not a parse error, a connection to the wrong place.
    """
    secret = tmp_path / "pw"
    secret.write_text("a/b+c=d", encoding="utf-8")

    joined = authenticated_valkey_url(
        "redis://valkey-core:6379/1", username="kb-ai", password_path=secret
    ).get_secret_value()

    assert joined == "redis://kb-ai:a%2Fb%2Bc%3Dd@valkey-core:6379/1"
    # The host and the logical DB must survive intact — that is the whole point.
    assert joined.endswith("@valkey-core:6379/1")


def test_trailing_newline_in_the_password_file_is_stripped(tmp_path: Path) -> None:
    secret = tmp_path / "pw"
    secret.write_text("swordfish\n", encoding="utf-8")
    joined = authenticated_valkey_url(
        "redis://valkey-core:6379/0", username="kb-ai", password_path=secret
    )
    assert joined.get_secret_value() == "redis://kb-ai:swordfish@valkey-core:6379/0"


# ── the joined URL is a SecretStr, in both shapes ────────────────────────────
#
# `postgres_dsn` returns one and its docstring says why: a bare `str` return puts the password
# into any traceback frame that happens to hold the value, and into `repr()` of every object
# that stores it. The same reasoning was not applied here for a while, and the object that
# stores this one does exactly what that sentence predicts — the client built from it holds
# the parsed URL in a connection holder whose generated `__repr__` joins every keyword pair,
# `password` among them. The assertions below name the SECRET'S ABSENCE, never a mask's
# presence: asserting `"**********" in repr(...)` passes just as happily against a build that
# renders the mask beside the value.


def test_the_joined_url_does_not_render_the_password_in_its_repr(tmp_path: Path) -> None:
    secret = tmp_path / "pw"
    secret.write_text("hunter2-the-acl-password", encoding="utf-8")

    joined = authenticated_valkey_url(
        "redis://valkey-core:6379/1", username="kb-ai", password_path=secret
    )

    assert "hunter2-the-acl-password" not in repr(joined)
    assert "hunter2-the-acl-password" not in str(joined)
    assert "hunter2-the-acl-password" not in f"{joined}"
    # And it is still recoverable at the one call site allowed to ask for it.
    assert "hunter2-the-acl-password" in joined.get_secret_value()


def test_the_unauthenticated_shape_is_a_secret_too(tmp_path: Path) -> None:
    """Both branches return the same type.

    A `str` on the no-password branch and a `SecretStr` on the other is a function whose
    return type depends on the deployment, so every call site would need `.get_secret_value()`
    guarded by a check nobody writes — and local development, the shape with no credential, is
    the one where the mistake goes unnoticed.
    """
    assert not isinstance(
        authenticated_valkey_url(
            "redis://valkey-core:6379/0", username="kb-ai", password_path=None
        ),
        str,
    )


def test_empty_password_file_raises(tmp_path: Path) -> None:
    """NOAUTH on the first command reads as an outage, not as a missing file."""
    secret = tmp_path / "pw"
    secret.write_text("   \n", encoding="utf-8")
    with pytest.raises(RuntimeError, match="is empty"):
        authenticated_valkey_url(
            "redis://valkey-core:6379/0", username="kb-ai", password_path=secret
        )


def test_missing_password_file_raises(tmp_path: Path) -> None:
    with pytest.raises(RuntimeError, match="unreadable"):
        authenticated_valkey_url(
            "redis://valkey-core:6379/0",
            username="kb-ai",
            password_path=tmp_path / "absent",
        )


@pytest.mark.parametrize(
    "url",
    [
        "redis://kb-ai:s3cr3t@valkey-core:6379/1",
        "redis://:s3cr3t@valkey-cache:6379/0",
        "redis://kb-ai@valkey-core:6379/2",
    ],
)
def test_credentials_embedded_in_a_url_fail_startup(url: str) -> None:
    """An inlined password is an environment value, which is the thing being avoided."""
    with pytest.raises(ValidationError, match="must not be embedded"):
        _settings(broker_url=url)


def test_a_path_containing_an_at_sign_is_not_mistaken_for_credentials() -> None:
    """The guard inspects the authority only; `@` after the first `/` is not userinfo."""
    assert _settings(cache_url="redis://valkey-cache:6379/0").cache_url.endswith("/0")


def test_coordination_url_is_a_real_setting() -> None:
    """Locks, fences, nonces, idempotency and breaker state — absence-sensitive families."""
    assert _settings().coordination_url == "redis://valkey-core:6379/2"
    with pytest.raises(ValidationError, match="valkey transport alias"):
        _settings(coordination_url="valkey://valkey-core:6379/2")

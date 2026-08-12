"""Every secret this process holds is a mounted file path, and none of them is required.

Two rules, and each one is a bug that already happened here.

**A secret is never an environment value.** `docker inspect`, `docker compose config`, a crash
dump and every child process all read the environment; none of them read a file mounted at
`/run/secrets`. The Valkey ACL password and the PostgreSQL password already worked this way;
the Qdrant API key and the object-storage secret key were still `SecretStr` fields fed from
environment values, while the deployment template had been supplying `_PATH` variables for both
— which had no fields, so pydantic-settings discarded them in silence and the two credentials
were simply absent.

**A container that legitimately holds no credential must still construct `Settings`.** `ai-beat`
ticks a schedule and `ai-worker-crawl` fetches URLs; neither touches PostgreSQL, and the crawl
worker is deliberately kept off the `data` network so it cannot reach Qdrant at all. A required
credential field makes those containers unstartable over a secret they are designed not to have
— the exact outage that was just fixed for Postgres, so every path field here is `| None` and
every reader accepts `None`.

The public half is deliberately NOT symmetric: `s3_access_key_id` is a plain `str`, because an
access key id is an identifier that belongs in a log line. The `_ID` / `_PATH` asymmetry is the
signal that tells a reader which half is the secret.

Nothing here needs a container, a network, or a real credential.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from pathlib import Path
from typing import Final

import pytest
from pydantic import SecretStr

from app.core.config import (
    ENV_PREFIX,
    KNOWN_UNMAPPED_VARIABLES,
    Settings,
    check_environment,
    get_settings,
    postgres_dsn,
    qdrant_api_key,
    s3_secret_key,
    unknown_kb_variables,
)

#: The template the deployment is actually started with — read, never restated. A copy here
#: would agree with itself forever while the real file drifted, which is the failure under test.
ENV_TEMPLATE: Final[Path] = (
    Path(__file__).resolve().parents[4]
    / "infrastructure"
    / "docker"
    / "env"
    / "ai-service.env.example"
)

#: Laravel's half of the same deployment. Three object-storage values must be byte-identical
#: across the two files or SigV4 fails in a way that reads like a credential problem.
CORE_API_TEMPLATE: Final[Path] = ENV_TEMPLATE.with_name("core-api.env.example")

#: Not a credential — a string a test writes into a temporary file and expects back out.
_PROBE_SECRET: Final[str] = "not-a-real-key"

#: Every credential reader has the same signature, deliberately: one shape, one helper
#: underneath, one set of failure messages.
type Reader = Callable[[Path | None], SecretStr | None]


def _declared(template: Path) -> dict[str, str]:
    """Every `NAME=value` line in a template, with trailing comments stripped."""
    values: dict[str, str] = {}
    for line in template.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not re.match(r"^[A-Z][A-Z0-9_]*=", stripped):
            continue
        name, _, value = stripped.partition("=")
        values[name] = value.split("#")[0].strip()
    return values


def _template_environment() -> dict[str, str]:
    declared = _declared(ENV_TEMPLATE).items()
    return {name: value for name, value in declared if name.startswith(ENV_PREFIX)}


def _fields() -> set[str]:
    return {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}


# ── shape: paths in, values out ──────────────────────────────────────────────


def test_no_setting_is_a_secret_value() -> None:
    """The invariant the whole file exists for, stated once against the model itself.

    A `SecretStr` field on `Settings` can only be filled from an environment variable, so its
    presence means a secret is in the environment — `SecretStr` stops it printing, it does not
    stop `docker inspect` from reading it. Credentials are `Path | None` fields plus a reader;
    the readers are what return `SecretStr`.
    """
    # Matched on the rendered annotation rather than on the type itself, because a credential
    # field is `SecretStr | None` far more often than bare `SecretStr`, and a union has no
    # `__mro__` to inspect. Both spellings render the name.
    offenders = sorted(
        name
        for name, field in Settings.model_fields.items()
        if SecretStr.__name__ in str(field.annotation)
    )
    assert not offenders, (
        f"{offenders} are secret-typed settings, which means a secret is being read from the "
        "environment. Use a `*_path: Path | None` field plus a reader."
    )


@pytest.mark.parametrize(
    ("removed", "replacement"),
    [
        ("qdrant_api_key", "qdrant_api_key_path"),
        ("s3_secret_key", "s3_secret_key_path"),
    ],
)
def test_each_credential_is_a_path_field_and_the_value_field_is_gone(
    removed: str, replacement: str
) -> None:
    """`extra="forbid"` rejects a keyword passed directly, so this also proves a call site
    cannot smuggle the old value-shaped field back in programmatically."""
    assert removed not in Settings.model_fields
    assert replacement in Settings.model_fields
    with pytest.raises(Exception, match=removed):
        Settings(**{removed: "a-value"})  # type: ignore[arg-type]


def test_the_access_key_id_is_a_public_identifier_and_prints() -> None:
    """The deliberate asymmetry, asserted rather than commented.

    An access key id is what AWS calls `AWS_ACCESS_KEY_ID`, boto3 calls `aws_access_key_id` and
    SeaweedFS's identities file calls a name. It is not a credential, it belongs in the log line
    that says which identity signed a rejected request, and wrapping it in `SecretStr` would
    both hide it there and make it read as a sibling of the secret rather than its opposite.
    """
    assert "s3_access_key" not in Settings.model_fields, "the old ambiguous name is gone"
    settings = Settings(s3_access_key_id="kb-app")
    assert settings.s3_access_key_id == "kb-app"
    assert "kb-app" in repr(settings), "an identifier that cannot be printed is useless in a log"


# ── the readers ──────────────────────────────────────────────────────────────


#: The two readers under test, and the label each puts in front of its failure messages.
_READERS: Final[list[tuple[Reader, str]]] = [
    (qdrant_api_key, "Qdrant API key"),
    (s3_secret_key, "S3 secret key"),
]


@pytest.mark.parametrize(("reader", "label"), _READERS)
def test_a_reader_returns_the_mounted_file_contents(
    reader: Reader, label: str, tmp_path: Path
) -> None:
    path = tmp_path / f"{reader.__name__}-secret"
    path.write_text(f"{_PROBE_SECRET}\n", encoding="utf-8")
    secret = reader(path)
    assert secret is not None
    assert secret.get_secret_value() == _PROBE_SECRET, "trailing newline must be stripped"


@pytest.mark.parametrize(("reader", "label"), _READERS)
def test_a_reader_returns_a_secret_that_does_not_print(
    reader: Reader, label: str, tmp_path: Path
) -> None:
    """A bare `str` return would put the key into the `repr()` of every object that holds it,
    into any traceback frame carrying that object, and from there into a log line."""
    path = tmp_path / f"{reader.__name__}-printing"
    path.write_text(_PROBE_SECRET, encoding="utf-8")
    secret = reader(path)
    assert secret is not None
    assert _PROBE_SECRET not in repr(secret)
    assert _PROBE_SECRET not in str(secret)
    assert _PROBE_SECRET in secret.get_secret_value()


@pytest.mark.parametrize(("reader", "label"), _READERS)
def test_a_reader_accepts_no_mount_at_all(reader: Reader, label: str) -> None:
    """`ai-beat` and `ai-worker-crawl` hold neither mount by design, and Qdrant's REST API is
    unauthenticated unless the server was started with `--api-key`. `None` in, `None` out — not
    a raise, and not a guessed default path, which would turn an unmounted secret into a
    file-read error about a path nobody configured."""
    assert reader(None) is None


@pytest.mark.parametrize(("reader", "label"), _READERS)
def test_an_empty_credential_file_fails_naming_the_file(
    reader: Reader, label: str, tmp_path: Path
) -> None:
    """An empty secret authenticates as nothing: Valkey answers NOAUTH, Qdrant answers 403, and
    both read as an outage of that service rather than as a missing mount. Failing here is what
    lets the message name the file — and the label is what makes it name the right credential,
    since all four share one reader."""
    path = tmp_path / f"{reader.__name__}-empty"
    path.write_text("  \n", encoding="utf-8")
    with pytest.raises(RuntimeError, match=rf"{re.escape(label)} file .* is empty"):
        reader(path)


@pytest.mark.parametrize(("reader", "label"), _READERS)
def test_an_unreadable_credential_file_fails_naming_the_file(
    reader: Reader, label: str, tmp_path: Path
) -> None:
    with pytest.raises(RuntimeError, match=rf"{re.escape(label)} file .* is unreadable"):
        reader(tmp_path / f"{reader.__name__}-never-mounted")


def test_the_readers_read_at_call_time_and_not_at_construction(tmp_path: Path) -> None:
    """What makes the no-credential container work, and it is a property of *when*, not of
    `| None`: nothing touches a secret until a client is built, in `lifespan` or
    `worker_process_init`. A container that never builds one never reads a file it was never
    given — so a settings object constructed against paths that do not exist is fine.
    """
    qdrant_path = tmp_path / "qdrant_api_key"
    s3_path = tmp_path / "s3_secret_key"
    settings = Settings(qdrant_api_key_path=qdrant_path, s3_secret_key_path=s3_path)
    assert not qdrant_path.exists() and not s3_path.exists()  # constructing read nothing

    qdrant_path.write_text("written-later-q", encoding="utf-8")
    s3_path.write_text("written-later-s", encoding="utf-8")

    qdrant_secret = qdrant_api_key(settings.qdrant_api_key_path)
    s3_secret = s3_secret_key(settings.s3_secret_key_path)
    assert qdrant_secret is not None and qdrant_secret.get_secret_value() == "written-later-q"
    assert s3_secret is not None and s3_secret.get_secret_value() == "written-later-s"


# ── the environment check ────────────────────────────────────────────────────


@pytest.mark.parametrize(
    ("removed", "replacement"),
    [
        (f"{ENV_PREFIX}QDRANT_API_KEY", f"{ENV_PREFIX}QDRANT_API_KEY_PATH"),
        (f"{ENV_PREFIX}S3_SECRET_KEY", f"{ENV_PREFIX}S3_SECRET_KEY_PATH"),
    ],
)
def test_a_value_shaped_credential_is_refused_with_the_path_named(
    removed: str, replacement: str
) -> None:
    """Removing a field is itself a way to create the silent-default bug. An operator whose
    environment still carries the old value-shaped variable would otherwise have it discarded
    like any other unrecognised name, and the process would run with no credential at all —
    while the generic message for an unknown variable ends "add the field", which is exactly the
    wrong fix for a secret that just moved out of the environment on purpose.
    """
    with pytest.raises(RuntimeError) as caught:
        check_environment({removed: "sk-whatever"})
    message = str(caught.value)
    assert replacement in message
    assert "docker inspect" in message
    assert "sk-whatever" not in message, "the check must not echo the credential it refused"


@pytest.mark.parametrize(
    "deleted",
    [
        f"{ENV_PREFIX}ENV",
        f"{ENV_PREFIX}RESULT_BACKEND",
        f"{ENV_PREFIX}S3_ADDRESSING_STYLE",
    ],
)
def test_a_deleted_variable_has_no_field_no_exemption_and_fails_startup(deleted: str) -> None:
    """The three that were closed by deleting the variable rather than by adding a field.

    `KB_ENV` was superseded by `KB_ENVIRONMENT`; `KB_RESULT_BACKEND` is an empty knob that
    invites the next person debugging a stuck job to fill it in and ship unbounded Valkey growth
    plus a pool deadlock; `KB_S3_ADDRESSING_STYLE` has exactly one working value, since
    virtual-host addressing resolves `kb.seaweedfs-s3` and Compose has no wildcard DNS.

    A setting with one correct value is not configuration — it is a way to break the deployment
    that looks like a knob — so the correct home for `path` is the boto3 client config, and
    re-adding any of these three must break boot rather than be quietly accepted.
    """
    assert deleted not in _fields()
    assert deleted not in KNOWN_UNMAPPED_VARIABLES
    assert deleted not in _template_environment()
    with pytest.raises(RuntimeError, match="match no setting"):
        check_environment({deleted: "x"})


def test_the_real_env_template_would_start_a_container() -> None:
    """The headline assertion, read from the file Compose actually mounts: not one `KB_*` line
    in it would be silently discarded or would fail the startup check.

    `unknown_kb_variables` is asserted directly rather than only through `check_environment`, so
    a regression names the offending variables instead of raising a bare "match no setting".
    """
    environment = _template_environment()
    assert environment, "no KB_* lines parsed — the template moved or the parser broke"
    assert unknown_kb_variables(environment) == []
    check_environment(environment)


@pytest.mark.parametrize(
    "name",
    [
        f"{ENV_PREFIX}QDRANT_API_KEY_PATH",
        f"{ENV_PREFIX}S3_ACCESS_KEY_ID",
        f"{ENV_PREFIX}S3_SECRET_KEY_PATH",
        f"{ENV_PREFIX}S3_REGION",
        f"{ENV_PREFIX}CORE_API_URL",
    ],
)
def test_the_five_reconciled_variables_map_to_fields_and_are_still_declared(name: str) -> None:
    """Each was in the deployment template with no field, i.e. supplied and discarded. Both
    halves are asserted so a rename on either side fails here rather than at container start."""
    assert name in _fields(), f"{name} has no field and would be silently ignored"
    assert name in _template_environment(), f"{name} is no longer in the env template"


# ── defaults, which are what a test-built Settings() actually gets ───────────


def test_the_object_storage_defaults_match_the_deployment_template() -> None:
    """Runtime is fine either way because the env sets these — but `Settings()` built without an
    environment, which means every test, gets the defaults. `s3_bucket` defaulted to
    `kb-objects` while both templates said `kb`, so every test-built settings object pointed at
    a bucket that does not exist, and would have failed at first use with a NoSuchBucket that
    looks like a provisioning problem.
    """
    declared = _declared(ENV_TEMPLATE)
    settings = Settings()
    assert declared[f"{ENV_PREFIX}S3_ENDPOINT"] == settings.s3_endpoint
    assert declared[f"{ENV_PREFIX}S3_BUCKET"] == settings.s3_bucket
    assert declared[f"{ENV_PREFIX}S3_REGION"] == settings.s3_region
    assert declared[f"{ENV_PREFIX}S3_ACCESS_KEY_ID"] == settings.s3_access_key_id
    assert declared[f"{ENV_PREFIX}CORE_API_URL"] == settings.core_api_url


def test_object_storage_agrees_with_laravel_byte_for_byte() -> None:
    """The cross-service half, and the one that fails obscurely.

    SeaweedFS ignores the region string and SigV4 *signs* it, so a region that differs from
    Laravel's produces `SignatureDoesNotMatch` — which reads as a credential problem and sends
    everyone to look at the wrong secret. The bucket and the identity must match for the plainer
    reason that they name the same objects and the same SeaweedFS identity.
    """
    laravel = _declared(CORE_API_TEMPLATE)
    settings = Settings()
    assert laravel["AWS_BUCKET"] == settings.s3_bucket
    assert laravel["AWS_DEFAULT_REGION"] == settings.s3_region
    assert laravel["AWS_ACCESS_KEY_ID"] == settings.s3_access_key_id


# ── the containers ───────────────────────────────────────────────────────────


def test_an_api_container_boots_from_the_real_env_template_and_reads_every_secret(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    """As close to `docker compose up` as a unit test gets: the real template into the
    environment, every mounted secret redirected to a real temporary file, then the four things
    `lifespan` does — assemble the DSN, authenticate Valkey, read the Qdrant key, read the S3
    key. `ai-api` is the container that holds all of them.
    """
    values = _template_environment()
    for variable, filename, content in (
        (f"{ENV_PREFIX}PG_PASSWORD_PATH", "postgres_password", "pg-from-the-mount"),
        (f"{ENV_PREFIX}QDRANT_API_KEY_PATH", "qdrant_api_key", "qdrant-from-the-mount"),
        (f"{ENV_PREFIX}S3_SECRET_KEY_PATH", "s3_secret_key", "s3-from-the-mount"),
    ):
        assert variable in values, f"{variable} vanished from the template"
        secret_file = tmp_path / filename
        secret_file.write_text(content, encoding="utf-8")
        values[variable] = str(secret_file)

    for name, value in values.items():
        monkeypatch.setenv(name, value)

    get_settings.cache_clear()
    try:
        settings = get_settings()
        assert settings.environment == "production"
        assert settings.core_api_url == "http://laravel-api:8080"
        assert settings.s3_access_key_id == "kb-app"

        dsn = postgres_dsn(
            host=settings.pg_host,
            port=settings.pg_port,
            database=settings.pg_database,
            user=settings.pg_user,
            password_path=settings.pg_password_path,
        )
        assert "pg-from-the-mount" in dsn.get_secret_value()

        qdrant = qdrant_api_key(settings.qdrant_api_key_path)
        s3 = s3_secret_key(settings.s3_secret_key_path)
        assert qdrant is not None and qdrant.get_secret_value() == "qdrant-from-the-mount"
        assert s3 is not None and s3.get_secret_value() == "s3-from-the-mount"
    finally:
        get_settings.cache_clear()


def test_a_beat_container_boots_with_no_credential_mounted_at_all(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The regression this change must not reintroduce, now for all four credentials.

    `ai-beat` and `ai-worker-crawl` get the same env file and none of the secret mounts — beat
    only ticks a schedule, and the crawl worker is deliberately off the `data` network, so it
    could not reach Qdrant even holding the key. They must start. Making any of these fields
    required is the bug `compose.yaml`'s own `ai-beat` comment predicted for the key ring: the
    settings object refuses to exist over a credential the process is designed not to have.
    """
    for name, value in _template_environment().items():
        monkeypatch.setenv(name, value)
    for variable in (
        f"{ENV_PREFIX}PG_PASSWORD_PATH",
        f"{ENV_PREFIX}QDRANT_API_KEY_PATH",
        f"{ENV_PREFIX}S3_SECRET_KEY_PATH",
        f"{ENV_PREFIX}VALKEY_PASSWORD_PATH",
    ):
        monkeypatch.delenv(variable, raising=False)

    get_settings.cache_clear()
    try:
        settings = get_settings()
        assert settings.pg_password_path is None
        assert settings.qdrant_api_key_path is None
        assert settings.s3_secret_key_path is None
        assert settings.valkey_password_path is None
        # And every reader tolerates it, because "constructs" is not the property that matters —
        # the process must also get through the code that would have opened those clients.
        assert qdrant_api_key(settings.qdrant_api_key_path) is None
        assert s3_secret_key(settings.s3_secret_key_path) is None
    finally:
        get_settings.cache_clear()

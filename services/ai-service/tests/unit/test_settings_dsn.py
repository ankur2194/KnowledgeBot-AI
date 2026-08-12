"""Database configuration, and the silence that made six containers crash-loop.

`Settings` required `postgres_dsn: SecretStr`. Nothing set `KB_POSTGRES_DSN`. The deployment
supplied `KB_PG_HOST`, `KB_PG_PORT`, `KB_PG_DATABASE`, `KB_PG_USER` and `KB_PG_PASSWORD_PATH`,
none of which had a matching field — and pydantic-settings resolves a field by *looking up* its
own name rather than enumerating the environment, so all five were discarded without a word
while the one required field stayed unset. Every ai-service container failed at import.

Two defects, and fixing only the visible one leaves the other loaded:

* **The DSN was a setting at all.** A DSN carries the database password, and an environment
  value is readable through `docker inspect`, `docker compose config`, a crash dump and every
  child process. It is now assembled at the point of use from four plain fields plus a password
  *path*, mirroring the Valkey ACL mechanism in the same module.
* **`extra="forbid"` does not reject unknown environment variables**, though the file's own
  header used to claim it did. `check_environment` is the enumeration that actually does it.

Nothing here needs a database, a container or a network. A configuration test that needs the
thing it configures stops running on the day the thing is down.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Final
from urllib.parse import urlsplit

import pytest

from app.core.config import (
    ENV_PREFIX,
    EXTERNALLY_READ_VARIABLES,
    KNOWN_UNMAPPED_VARIABLES,
    Settings,
    check_environment,
    get_settings,
    postgres_dsn,
    unknown_kb_variables,
)

#: The deployment template this service is actually started with. Read rather than restated:
#: a copy here would agree with itself forever while the real file drifted, which is precisely
#: the failure mode under test.
ENV_TEMPLATE: Final[Path] = (
    Path(__file__).resolve().parents[4]
    / "infrastructure"
    / "docker"
    / "env"
    / "ai-service.env.example"
)

#: The other place the deployment sets a `KB_*`: an `environment:` block, for the one variable
#: that is deliberately a literal rather than a `${...}` the template could supply.
COMPOSE_FILE: Final[Path] = (
    Path(__file__).resolve().parents[4] / "infrastructure" / "docker" / "compose.yaml"
)

#: The third place a `KB_*` is read: the `kb-worker-healthcheck` probe baked into the image.
#: It runs in its own interpreter and imports nothing from `app.`, which is exactly why its
#: variables cannot be fields and why `check_environment` has to be told about them.
DOCKERFILE: Final[Path] = Path(__file__).resolve().parents[2] / "Dockerfile"


def _template_variables() -> list[str]:
    return [
        match.group(1)
        for line in ENV_TEMPLATE.read_text(encoding="utf-8").splitlines()
        if (match := re.match(r"^(KB_[A-Z0-9_]+)=", line.strip()))
    ]


# ── the container that has no database credential ────────────────────────────


def test_settings_constructs_with_no_database_credential_at_all() -> None:
    """The headline regression, and the reason the DSN stopped being a required field.

    `ai-beat` and `ai-worker-crawl` hold no database secret **by design** — beat only ticks a
    schedule and the crawl worker only fetches URLs. A required `SecretStr` made `Settings()`
    unconstructable in a container that is correct as it stands, which is exactly the failure
    `compose.yaml`'s own `ai-beat` comment predicted for the key ring: the object refuses to
    exist over a credential the process is deliberately not given.
    """
    settings = Settings()
    assert settings.pg_password_path is None
    assert not hasattr(settings, "postgres_dsn")


def test_the_dsn_is_not_a_setting() -> None:
    """A field would put the password back into the environment, which is the whole point.

    `extra="forbid"` does reject an unknown keyword passed directly, so this also proves a call
    site cannot smuggle one in programmatically.
    """
    assert "postgres_dsn" not in Settings.model_fields
    with pytest.raises(Exception, match="postgres_dsn"):
        Settings(postgres_dsn="postgresql://u:p@h:5432/d")  # type: ignore[call-arg]


def test_the_five_deployment_variables_now_map_to_fields() -> None:
    """The exact five that were being discarded. Read from the real template, so a rename on
    either side fails here rather than at container start."""
    fields = {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}
    for name in (
        f"{ENV_PREFIX}PG_HOST",
        f"{ENV_PREFIX}PG_PORT",
        f"{ENV_PREFIX}PG_DATABASE",
        f"{ENV_PREFIX}PG_USER",
        f"{ENV_PREFIX}PG_PASSWORD_PATH",
    ):
        assert name in fields, f"{name} has no field and would be silently ignored again"
        assert name in _template_variables(), f"{name} is no longer in the env template"


def test_the_defaults_match_the_deployment_template() -> None:
    """Three of the four plain values need no override in Compose. If a default drifts from the
    template the service still starts — pointed at a database that may not be the one intended,
    which is worse than not starting."""
    declared = dict(
        line.split("=", 1)
        for line in ENV_TEMPLATE.read_text(encoding="utf-8").splitlines()
        if line.strip().startswith(f"{ENV_PREFIX}PG_") and "=" in line
    )
    settings = Settings()
    assert declared[f"{ENV_PREFIX}PG_HOST"].split("#")[0].strip() == settings.pg_host
    assert int(declared[f"{ENV_PREFIX}PG_PORT"].split("#")[0].strip()) == settings.pg_port
    assert declared[f"{ENV_PREFIX}PG_DATABASE"].split("#")[0].strip() == settings.pg_database
    assert declared[f"{ENV_PREFIX}PG_USER"].split("#")[0].strip() == settings.pg_user


# ── the assembly ─────────────────────────────────────────────────────────────

#: Not a credential — the value a test writes into a temporary file and expects back out.
_PROBE_SECRET: Final[str] = "s3cr3t"


def _dsn(tmp_path: Path, password: str | None = _PROBE_SECRET, **overrides: object) -> str:
    path: Path | None = None
    if password is not None:
        path = tmp_path / "postgres_password"
        path.write_text(password, encoding="utf-8")
    arguments: dict[str, object] = {
        "host": "postgres",
        "port": 5432,
        "database": "knowledgebot",
        "user": "knowledgebot",
        "password_path": path,
    }
    arguments.update(overrides)
    return postgres_dsn(**arguments).get_secret_value()  # type: ignore[arg-type]


def test_the_dsn_is_assembled_from_the_parts_and_the_mounted_file(tmp_path: Path) -> None:
    assert _dsn(tmp_path) == "postgresql://knowledgebot:s3cr3t@postgres:5432/knowledgebot"


def test_the_password_is_percent_encoded(tmp_path: Path) -> None:
    """`openssl rand -base64 24` — what `bootstrap.sh` writes — emits `+`, `/` and `=`.

    A raw `/` inside the userinfo terminates the authority component, so
    `postgresql://kb:pa/ss@postgres:5432/kb` parses with host `kb:pa` and path
    `/ss@postgres:5432/kb`. libpq does not reject that; it resolves somewhere else, or fails
    with an error that sends everyone to look at the wrong secret.
    """
    dsn = _dsn(tmp_path, password="pa/ss+wo=rd")
    assert "%2F" in dsn and "%2B" in dsn and "%3D" in dsn
    parts = urlsplit(dsn)
    assert parts.hostname == "postgres"
    assert parts.port == 5432
    assert parts.path == "/knowledgebot"


def test_a_password_with_no_special_characters_still_parses_back(tmp_path: Path) -> None:
    parts = urlsplit(_dsn(tmp_path))
    assert parts.username == "knowledgebot"
    assert parts.password == "s3cr3t"


def test_no_password_path_yields_a_passwordless_dsn(tmp_path: Path) -> None:
    """Local development runs a Postgres with `trust` auth — the same allowance
    `authenticated_valkey_url` makes for a Valkey with no ACL file. In a deployment the server
    rejects this at connect with an authentication failure naming the user, which is loud and
    correct; inventing a default password path would instead turn an unmounted secret into a
    file-read error about a path nobody configured."""
    assert _dsn(tmp_path, password=None) == "postgresql://knowledgebot@postgres:5432/knowledgebot"


def test_an_empty_password_file_fails_naming_the_file(tmp_path: Path) -> None:
    """An empty secret authenticates as nothing, and the server's rejection reads as a database
    outage rather than as a missing mount."""
    path = tmp_path / "postgres_password"
    path.write_text("   \n", encoding="utf-8")
    with pytest.raises(RuntimeError, match=r"PostgreSQL password file .* is empty"):
        postgres_dsn(host="postgres", port=5432, database="kb", user="kb", password_path=path)


def test_an_unreadable_password_file_fails_naming_the_file(tmp_path: Path) -> None:
    with pytest.raises(RuntimeError, match=r"PostgreSQL password file .* is unreadable"):
        postgres_dsn(
            host="postgres",
            port=5432,
            database="kb",
            user="kb",
            password_path=tmp_path / "never-mounted",
        )


def test_the_dsn_is_a_secret_and_does_not_print(tmp_path: Path) -> None:
    """The password is inside the string, so a plain `str` return would put it into any
    traceback frame holding the value and into the `repr()` of everything that stores it."""
    path = tmp_path / "postgres_password"
    path.write_text("hunter2", encoding="utf-8")
    secret = postgres_dsn(host="postgres", port=5432, database="kb", user="kb", password_path=path)
    assert "hunter2" not in repr(secret)
    assert "hunter2" not in str(secret)
    assert "hunter2" in secret.get_secret_value()


def test_the_assembly_reads_the_file_at_call_time_not_at_import(tmp_path: Path) -> None:
    """What makes the no-credential container work: nothing touches the secret until a
    connection is opened, in `lifespan` or `worker_process_init`. A container that never opens
    one never reads a file it was never given."""
    path = tmp_path / "late"
    settings = Settings(pg_password_path=path)
    assert settings.pg_password_path == path  # constructing did not read it

    path.write_text("written-later", encoding="utf-8")
    assert (
        "written-later"
        in postgres_dsn(
            host=settings.pg_host,
            port=settings.pg_port,
            database=settings.pg_database,
            user=settings.pg_user,
            password_path=settings.pg_password_path,
        ).get_secret_value()
    )


# ── the environment check ────────────────────────────────────────────────────


def test_a_dsn_in_the_environment_is_refused_with_the_replacement_named() -> None:
    """Removing the field is itself a way to create the bug it fixed: `KB_POSTGRES_DSN` would
    otherwise become just another silently-ignored name, and the process would connect with an
    assembled DSN carrying a different password than the operator set."""
    with pytest.raises(RuntimeError) as caught:
        check_environment({f"{ENV_PREFIX}POSTGRES_DSN": "postgresql://u:p@h:5432/d"})
    message = str(caught.value)
    assert f"{ENV_PREFIX}PG_PASSWORD_PATH" in message
    assert "docker inspect" in message


def test_an_unknown_variable_fails_startup_instead_of_defaulting_a_setting() -> None:
    """The check `extra="forbid"` is assumed to perform and does not. A typo'd name is not a
    harmless no-op — it is a setting silently left at its default with no indication anywhere,
    which is precisely how the DSN failure happened."""
    assert unknown_kb_variables({f"{ENV_PREFIX}QDRANT_URLL": "x"}) == [f"{ENV_PREFIX}QDRANT_URLL"]
    with pytest.raises(RuntimeError, match="match no setting"):
        check_environment({f"{ENV_PREFIX}QDRANT_URLL": "x"})


def test_a_real_field_and_a_non_kb_variable_are_both_accepted() -> None:
    assert unknown_kb_variables({f"{ENV_PREFIX}QDRANT_URL": "x", "PATH": "/usr/bin"}) == []


def test_the_recorded_gaps_are_exempt_but_still_listed() -> None:
    """The allow-list is the enforcement's escape hatch, so it has to be explicit and it has to
    shrink. Every entry carries the reconciliation it waits on, and none of them may be a name
    that already has a field — a stale exemption silently re-opens the hole for that variable.

    It is empty today and this test is therefore vacuous today. That is deliberate: the
    assertion it used to open with — "an empty allow-list means the list rotted, not that it
    won" — was guarding nothing. An empty list makes `check_environment` *maximally* strict, so
    emptying it cannot hide a variable; only ADDING to it can. Every one of the eight entries
    closed by earning a field or by the variable being deleted from the template.
    """
    fields = {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}
    for name, reason in KNOWN_UNMAPPED_VARIABLES.items():
        assert name.startswith(ENV_PREFIX)
        assert reason.strip(), f"{name} is exempt with no reason"
        assert name not in fields, f"{name} now has a field; drop the exemption"
    assert unknown_kb_variables(dict.fromkeys(KNOWN_UNMAPPED_VARIABLES, "x")) == []


def test_every_recorded_gap_is_still_in_the_template() -> None:
    """The other half of the exemption's shelf life, and the half nothing checked.

    Its neighbour asserts an entry has no *field*, which catches the exemption that was
    reconciled by adding one. It cannot catch the exemption reconciled by deleting the
    *variable*: `KB_ENV`, `KB_RESULT_BACKEND` and `KB_S3_ADDRESSING_STYLE` all vanished from
    the template and all three sat here afterwards, excusing names nothing sets, with the whole
    suite green. That is the same silent-coverage class as the bug this module exists to
    prevent — an exemption that outlives its variable is an exemption nobody will re-examine,
    and it pre-authorises the name if it ever comes back for a different reason.
    """
    declared = set(_template_variables())
    for name in KNOWN_UNMAPPED_VARIABLES:
        assert name in declared, (
            f"{name} is exempt but no longer appears in {ENV_TEMPLATE.name}; "
            "the exemption outlived the variable it excuses — drop it"
        )


# ── the variables that are configuration and are deliberately not fields ─────


def test_the_externally_read_variables_are_exempt_and_each_says_why() -> None:
    """The other exemption, and the one whose entries are *closed*, not pending.

    Same shape as its neighbour and the opposite meaning, so it gets the same three guards: a
    reason, no field (a name that earned one must drop the exemption rather than hold both), and
    actual exemption from the enumeration. One extra guard has no counterpart above — a name may
    not sit in both maps, because the two answer "should someone close this?" differently and a
    reader who finds it in the wrong one draws the wrong conclusion.
    """
    fields = {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}
    assert EXTERNALLY_READ_VARIABLES, "emptying this map re-breaks ai-worker-evaluation"
    for name, reason in EXTERNALLY_READ_VARIABLES.items():
        assert name.startswith(ENV_PREFIX)
        assert reason.strip(), f"{name} is exempt with no reason"
        assert name not in fields, f"{name} now has a field; drop the exemption"
        assert name not in KNOWN_UNMAPPED_VARIABLES, f"{name} is filed in both maps"
    assert unknown_kb_variables(dict.fromkeys(EXTERNALLY_READ_VARIABLES, "x")) == []


def test_every_externally_read_variable_is_still_set() -> None:
    """The shelf life, read from where these variables actually live.

    `test_every_recorded_gap_is_still_in_the_template` makes the same statement against
    `ai-service.env.example`, and it cannot make it for these: `KB_SAMPLES_ROOT` is set as a
    literal in `compose.yaml` precisely so it is NOT a `${...}` the env template could supply.
    An exemption whose variable has been deleted is an exemption nobody re-examines and a
    spelling pre-authorised for whatever comes back under that name next.

    TWO PLACES, NOT ONE, AND THE SECOND IS NOT A WEAKENING. `KB_WORKER_HEARTBEAT_PATH` and
    `KB_WORKER_HEARTBEAT_MAX_AGE` are exempt for the same reason as `KB_SAMPLES_ROOT` — real
    configuration, read by a module that must not import `app.core.config` — but they are
    *read with defaults* by a standalone probe script baked into the image, and the whole
    point of exempting them is that the deployment may start assigning them without killing
    every process. So "is anybody still reading this?" is the honest question for them, and
    `DOCKERFILE` is where that is answerable. An entry satisfying NEITHER file is dead.

    Assignments only, on the compose side. `compose.yaml` names `KB_SAMPLES_ROOT` four more
    times in comments, and a grep that counted those would keep the exemption alive after the
    line that matters was gone. On the Dockerfile side the reader is an `os.environ.get(...)`
    call naming the variable, which is equally specific and equally deletable.
    """
    compose = COMPOSE_FILE.read_text(encoding="utf-8")
    dockerfile = DOCKERFILE.read_text(encoding="utf-8")
    for name in EXTERNALLY_READ_VARIABLES:
        assigned = re.search(rf"^\s*-?\s*{re.escape(name)}\s*[:=]\s*\S", compose, re.MULTILINE)
        read = re.search(rf"environ\.get\(\s*[\"']{re.escape(name)}[\"']", dockerfile)
        assert assigned or read, (
            f"{name} is exempt but {COMPOSE_FILE.name} no longer assigns it and "
            f"{DOCKERFILE.name} no longer reads it; the exemption outlived the variable it "
            "excuses — drop it"
        )


def test_the_heartbeat_probe_variables_are_exempt_under_the_names_the_probe_reads() -> None:
    """The trap this exemption exists to disarm, stated as the thing that would spring it.

    The `kb-worker-healthcheck` probe is a standalone script in the image. It imports nothing
    from `app.`, which is what lets it answer while a worker is wedged — and which is also why
    `check_environment` had never heard of its two variables. Both carry the `KB_` prefix, and
    `KNOWN_UNMAPPED_VARIABLES` is empty, so before this exemption existed, putting either name
    in `env/ai-service.env` to tune the probe would have raised `RuntimeError` in every
    ai-service process at startup **while the probe itself kept working**: an operator would
    have tuned a healthcheck and killed the service, with the healthcheck reporting on a
    container that no longer had anything to check.

    Asserted against the Dockerfile's own spellings rather than against literals here, for the
    same reason `test_the_exempt_name_is_the_one_the_reader_actually_reads` binds
    `KB_SAMPLES_ROOT` to `corpus.py`'s constant: two files spelling one variable independently
    is how one of them gets renamed.
    """
    dockerfile = DOCKERFILE.read_text(encoding="utf-8")
    probe_variables = set(re.findall(r"environ\.get\(\s*[\"'](KB_[A-Z0-9_]+)[\"']", dockerfile))

    assert probe_variables, f"{DOCKERFILE.name} reads no KB_* variable; did the probe move?"
    # The probe also reads `KB_BROKER_URL`, and that one needs no exemption: it HAS a field, so
    # `check_environment` already recognises it and the env file may set it freely. Only a name
    # with no field is a trap, which is the same subtraction `unknown_kb_variables` performs.
    fields = {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}
    trapped = sorted(probe_variables - fields)

    assert trapped, "the probe reads only names that already have fields; drop this test"
    for name in trapped:
        assert name in EXTERNALLY_READ_VARIABLES, (
            f"{name} is read by the healthcheck probe in {DOCKERFILE.name} and is not in "
            "EXTERNALLY_READ_VARIABLES. Setting it in env/ai-service.env would then raise "
            "RuntimeError in every ai-service process at startup while the probe kept working."
        )

    # The guard tolerates them: an environment carrying every name the probe reads — the two
    # exempt ones and the one that has a field — constructs rather than raising.
    check_environment(dict.fromkeys(probe_variables, "x"))


def test_the_kb_test_endpoint_overrides_are_rejected_by_check_environment() -> None:
    """The exemption that was NOT granted, pinned so the documents cannot drift back.

    `tests/conftest.py` short-circuits three container fixtures on `KB_TEST_PG_DSN`,
    `KB_TEST_QDRANT_URL` and `KB_TEST_VALKEY_URL`, and both that file and
    `tests/integration/README.md` used to present them as *the CI path*. They are not a path at
    all: they carry the `KB_` prefix, `check_environment` finds no field, and `get_settings()`
    raises — at IMPORT, because `app/worker/__init__.py` calls it at module scope. Setting all
    three ends `pytest -m "not integration"` with `Interrupted: 4 errors during collection`,
    which is the whole run rather than four tests.

    **The fix was the documents, not an exemption, and this test is where that choice is
    recorded.** `EXTERNALLY_READ_VARIABLES` means *deployment* configuration read at its point
    of use by a module that must not import `app.core.config`, and its shelf life is enforced by
    `test_every_externally_read_variable_is_still_set`, which demands a `compose.yaml`
    assignment or a Dockerfile reader for every entry. A test-harness variable has neither, so
    admitting these three would mean carving a hole in that guard and permanently
    pre-authorising the `KB_TEST_*` spelling inside every production container — the exact
    "exemption that outlives its variable" failure
    `test_every_recorded_gap_is_still_in_the_template` was written against.

    If a future workflow-`services:` tier makes that trade worth making, this test is what has to
    be deleted, and deleting it is the prompt to fix the two documents in the same commit.
    """
    overrides = {
        f"{ENV_PREFIX}TEST_PG_DSN": "postgresql://u:p@localhost:5432/kb",
        f"{ENV_PREFIX}TEST_QDRANT_URL": "http://localhost:6333",
        f"{ENV_PREFIX}TEST_VALKEY_URL": "redis://localhost:6379",
    }

    for name in overrides:
        assert name not in EXTERNALLY_READ_VARIABLES, (
            f"{name} was admitted to EXTERNALLY_READ_VARIABLES. That is a decision, not a "
            "refactor: correct tests/conftest.py and tests/integration/README.md in the same "
            "change, and check test_every_externally_read_variable_is_still_set still guards "
            "what it claims to."
        )
        assert name not in KNOWN_UNMAPPED_VARIABLES

    assert unknown_kb_variables(overrides) == sorted(overrides)

    with pytest.raises(RuntimeError, match=r"match no setting"):
        check_environment(overrides)


def test_the_exempt_name_is_the_one_the_reader_actually_reads() -> None:
    """Binds the exemption to `corpus.py`'s own constant instead of restating the string.

    Two modules spelling the same variable independently is how one gets renamed. `corpus.py`
    imports only the standard library — that is the whole reason it does not read `Settings` —
    so this direction of the import is the safe one.
    """
    from app.evaluation.corpus import SAMPLES_ROOT_ENV

    assert SAMPLES_ROOT_ENV in EXTERNALLY_READ_VARIABLES


def test_the_evaluation_worker_boots_with_the_samples_root_compose_sets(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """The crash loop, as a unit test. `ai-worker-evaluation` gets the shared env template plus
    one literal `KB_SAMPLES_ROOT: /samples`, and `check_environment` — which had no idea that
    variable existed — killed the container at import with a message telling the operator to add
    a field, which is the one fix that would have broken the corpus resolver.

    Asserting `samples_root` is absent from the settings object is not belt-and-braces: it is the
    statement that this was fixed by recording a decision and not by quietly widening `Settings`
    until the error went away.
    """
    for name, value in _template_environment().items():
        monkeypatch.setenv(name, value)
    monkeypatch.setenv(f"{ENV_PREFIX}SAMPLES_ROOT", "/samples")

    get_settings.cache_clear()
    try:
        settings = get_settings()
        assert not hasattr(settings, "samples_root")
    finally:
        get_settings.cache_clear()


def test_the_deployment_template_passes_the_check() -> None:
    """The end-to-end statement, and the one that says the containers boot: every `KB_*` in the
    file the deployment actually mounts either maps to a field or is a recorded gap.

    It is also half of what stops the allow-list from being written to fit: a variable added to
    the template without a field fails here. The other half — a variable dropped from the
    template and left in the allow-list — is NOT caught by this test and was not caught by its
    neighbour either, whatever this docstring used to claim; asserting an entry has no field
    says nothing about whether the variable still exists.
    `test_every_recorded_gap_is_still_in_the_template` is the assertion that actually makes the
    claim true.
    """
    check_environment(dict.fromkeys(_template_variables(), "x"))


def _template_environment() -> dict[str, str]:
    values: dict[str, str] = {}
    for line in ENV_TEMPLATE.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not re.match(r"^KB_[A-Z0-9_]+=", stripped):
            continue
        name, _, value = stripped.partition("=")
        values[name] = value.split("#")[0].strip()
    return values


def test_a_container_boots_from_the_real_env_template(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    """The closest a unit test gets to `docker compose up`, and the state that was broken.

    Every `KB_*` line from the deployment template is put into the environment, the mounted
    secret is written to a real file, and the two things a container does at start are done in
    order: read the settings, then assemble a DSN. Before this change the first step raised on a
    missing required `postgres_dsn` while all five `KB_PG_*` variables sat in that same
    environment being discarded.
    """
    values = _template_environment()
    password_file = tmp_path / "postgres_password"
    password_file.write_text("from-the-mount", encoding="utf-8")
    values[f"{ENV_PREFIX}PG_PASSWORD_PATH"] = str(password_file)

    for name, value in values.items():
        monkeypatch.setenv(name, value)

    get_settings.cache_clear()
    try:
        settings = get_settings()
        assert settings.pg_host == "postgres"
        assert settings.pg_database == "knowledgebot"

        dsn = postgres_dsn(
            host=settings.pg_host,
            port=settings.pg_port,
            database=settings.pg_database,
            user=settings.pg_user,
            password_path=settings.pg_password_path,
        ).get_secret_value()
        assert dsn == "postgresql://knowledgebot:from-the-mount@postgres:5432/knowledgebot"
    finally:
        get_settings.cache_clear()


def test_a_beat_container_boots_with_the_secret_unmounted(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """`ai-beat` and `ai-worker-crawl` get the same env file and no database secret mount.

    They must start. Neither opens a pool, so the DSN is never assembled and the absent file is
    never read — which is the whole reason the assembly is a function called at use rather than
    a field resolved at import.
    """
    for name, value in _template_environment().items():
        monkeypatch.setenv(name, value)
    monkeypatch.delenv(f"{ENV_PREFIX}PG_PASSWORD_PATH", raising=False)

    get_settings.cache_clear()
    try:
        settings = get_settings()
        assert settings.pg_password_path is None
    finally:
        get_settings.cache_clear()


def test_get_settings_runs_the_check(monkeypatch: pytest.MonkeyPatch) -> None:
    """The check lives on the environment-reading path — `app/main.py` and
    `app/worker/__init__.py` both come through `get_settings`, which covers all six containers —
    and deliberately not in a model validator, which would also fire on the programmatic
    `Settings(...)` a test builds, where the ambient environment is not the configuration under
    test."""
    get_settings.cache_clear()
    monkeypatch.setenv(f"{ENV_PREFIX}NOT_A_SETTING", "x")
    try:
        with pytest.raises(RuntimeError, match="match no setting"):
            get_settings()
    finally:
        get_settings.cache_clear()


# ── the Valkey logical DBs, against every place they are also written down ───


def _template_assignments() -> dict[str, str]:
    """Every ``KB_NAME=value`` in the deployment template, comments stripped."""
    assignments: dict[str, str] = {}
    for line in ENV_TEMPLATE.read_text(encoding="utf-8").splitlines():
        match = re.match(r"^(KB_[A-Z0-9_]+)=(\S+)", line.strip())
        if match:
            assignments[match.group(1)] = match.group(2)
    return assignments


@pytest.mark.parametrize("field", ["broker_url", "cache_url", "coordination_url"])
def test_every_valkey_url_default_matches_the_template_the_deployment_ships(field: str) -> None:
    """THE DEFECT THIS TEST EXISTS FOR: ``broker_url`` defaulted to ``…/0`` while every other
    statement of the same fact said ``/1``.

    Containers load `env/ai-service.env` and so were never affected, which is exactly what made
    it survive. What the default governs is every process that does **not** load the env file —
    a pytest run, an ad-hoc `celery inspect`, a shell in the image — and those landed in DB 0,
    which is *Laravel's*: its queues **and** its default cache store. The two then share
    `_kombu.binding.*` and `unacked`, so a stray consumer quietly eats the other plane's jobs
    and nothing raises anywhere.

    Asserted against the template rather than against a literal here, because a literal in a
    test is a fourth place to state one fact and the third was already wrong.
    """
    template = _template_assignments()
    name = f"{ENV_PREFIX}{field.upper()}"

    assert name in template, f"{ENV_TEMPLATE.name} no longer sets {name}"
    assert getattr(Settings(), field) == template[name], (
        f"Settings.{field} defaults to something the deployment template disagrees with; a "
        "process that does not load the env file lands on the wrong logical DB"
    )


def test_the_broker_and_coordination_dbs_are_the_ones_the_acl_grants() -> None:
    """The second independent statement of the same fact, read from the ACL.

    `infrastructure/docker/valkey/users.acl` grants the `kb-ai` user the pub/sub channels
    `&/1.celery.pidbox` and `&/1.celeryev/worker.*` — the `/1` prefix in a Valkey channel name
    IS the logical database. So the broker is DB 1 by ACL, not by convention, and a process on
    DB 0 does not merely land in the wrong keyspace: its pidbox is unreachable.
    """
    acl = (
        Path(__file__).resolve().parents[4] / "infrastructure" / "docker" / "valkey" / "users.acl"
    ).read_text(encoding="utf-8")
    granted = set(re.findall(r"&/(\d+)\.celery", acl))

    assert granted == {"1"}, f"the ACL grants Celery pidbox channels on {granted}, not on DB 1"
    assert Settings().broker_url.endswith("/1")
    assert Settings().coordination_url.endswith("/2")

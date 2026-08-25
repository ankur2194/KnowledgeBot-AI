"""Process configuration.

Every setting arrives from the environment with the ``KB_`` prefix.

``extra="forbid"`` is deliberate, but it does NOT do what it is often assumed to do, and
this file previously claimed otherwise. Verified against pydantic-settings 2.13.0: a
``KB_``-prefixed environment variable with no matching field is **silently ignored**, and
the model constructs. ``forbid`` rejects extras passed to ``Settings(...)`` directly and
extras found in a dotenv file; it cannot reject environment variables, because nothing
enumerates the environment looking for the prefix.

So a typo'd ``KB_`` variable is exactly the silently-defaulted setting the old comment
promised it was not — and that is not hypothetical. It is how six containers came to
crash-loop: ``env/ai-service.env.example`` supplies ``KB_PG_HOST``, ``KB_PG_PORT``,
``KB_PG_DATABASE``, ``KB_PG_USER`` and ``KB_PG_PASSWORD_PATH``, none of which had a field,
all five discarded without a word, while the one field that did exist — a required
``postgres_dsn`` — was set by nothing at all.

``check_environment`` below closes it: it enumerates ``KB_`` in ``os.environ``, subtracts the
field names, and fails on the remainder against an allow-list of the variables that are known
to have no field yet. Enforcement now, with a shrinking list, rather than a warning nobody
reads or a validator deferred until somebody else's reconciliation lands. **That list is now
empty**, which is the end state it was aimed at, not an oversight — see the note beside it.

That enforcement has a third case, and leaving it out crash-looped ``ai-worker-evaluation``.
A ``KB_*`` variable can be *real configuration* and *deliberately not a field here*: read at
its point of use by a module that must not import this one. ``KB_SAMPLES_ROOT`` is that, and
it is not debt waiting on a reconciliation, so it is recorded in
``EXTERNALLY_READ_VARIABLES`` rather than in the list that exists to shrink. The two lists
are exempt identically and mean opposite things — one says "nobody has done this yet", the
other says "this was decided" — and folding either into the other loses the distinction the
next reader needs to know whether to close the entry or leave it alone.

NO SECRET IS AN ENVIRONMENT VARIABLE. EVERY ONE OF THEM IS A MOUNTED FILE PATH
------------------------------------------------------------------------------
There is no ``postgres_dsn`` field and there must not be one. A DSN carries the password, an
environment value is visible in ``docker inspect``, in ``docker compose config``, in a crash
dump and to every child process, and a mounted file is none of those. The DSN is assembled at
the point of use by ``postgres_dsn()`` from four plain fields plus a password *path* — exactly
the mechanism ``valkey_password_path`` and ``authenticated_valkey_url`` already use for the
Valkey ACL credential, deliberately copied rather than re-invented in a second shape.

That is now the shape of **all four** credentials this process holds, and there is one helper
under all of them (``_read_password``) rather than four spellings of the same read:

===========================  ==============================  ==========================
credential                   field                           reader, at point of use
===========================  ==============================  ==========================
Valkey ACL password          ``valkey_password_path``        ``authenticated_valkey_url``
PostgreSQL password          ``pg_password_path``            ``postgres_dsn``
Qdrant API key               ``qdrant_api_key_path``         ``qdrant_api_key``
Object-storage secret key    ``s3_secret_key_path``          ``s3_secret_key``
===========================  ==============================  ==========================

The **access key id is not in that table and must not join it**: it is a public identifier, it
belongs in a log line, and it stays a plain ``str`` environment value. The ``_ID`` / ``_PATH``
asymmetry between ``s3_access_key_id`` and ``s3_secret_key_path`` is what tells a reader which
half is which at a glance; naming them as siblings would erase exactly that signal.

The assembly is also why the settings object no longer refuses to construct without a database
credential. ``ai-beat`` and ``ai-worker-crawl`` hold none by design; a required ``SecretStr``
made ``Settings()`` unbuildable in a container that is correct as it stands, which is the bug
``compose.yaml``'s own ``ai-beat`` comment predicted for the key ring. Nothing reads a secret at
import; the pool reads the DSN in ``lifespan`` or in ``worker_process_init``, and a container
with no mounted file simply never calls the function. **Every path field is therefore
``| None``, and every reader accepts ``None``** — re-requiring one reintroduces that outage.

Every reader returns ``SecretStr`` so a stray ``repr()`` in a log line, a traceback frame, or a
``/health/deps`` response cannot print the value.
"""

from __future__ import annotations

import os
from collections.abc import Mapping
from functools import lru_cache
from pathlib import Path
from typing import Final, Literal
from urllib.parse import quote

from pydantic import SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

__all__ = [
    "ENV_PREFIX",
    "EXTERNALLY_READ_VARIABLES",
    "KNOWN_UNMAPPED_VARIABLES",
    "REMOVED_CREDENTIAL_VARIABLES",
    "Settings",
    "authenticated_valkey_url",
    "check_environment",
    "get_settings",
    "postgres_dsn",
    "qdrant_api_key",
    "s3_secret_key",
    "unknown_kb_variables",
]

ENV_PREFIX: Final[str] = "KB_"

#: Variables the deployment sets that have no field **yet**, each with the reconciliation it is
#: waiting on. This list is the enforcement's escape hatch and it is deliberately explicit: a
#: variable here is a known gap somebody owns, and anything NOT here is a typo that fails at
#: startup instead of silently defaulting a setting.
#:
#: Every entry is a real reconciliation and none of them is this module's to make alone — the
#: templates in ``infrastructure/docker/env/`` belong to ``platform-devops-engineer``. Shrinking
#: this list is the measure of that work; growing it needs a reason written beside the name.
#:
#: IT IS EMPTY, AND EMPTY IS THE STATE IT WAS AIMED AT. Eight entries closed in one pass and
#: none of them by relaxing anything: five earned fields (`qdrant_api_key_path`,
#: `s3_access_key_id`, `s3_secret_key_path`, `s3_region`, `core_api_url`) and three named
#: variables that no longer exist in the template at all — `KB_ENV` was superseded by
#: `KB_ENVIRONMENT`, and `KB_RESULT_BACKEND` and `KB_S3_ADDRESSING_STYLE` were deleted rather
#: than blanked, because a knob with exactly one correct value is a way to break the deployment
#: that looks like configuration.
#:
#: An empty list cannot hide anything — it makes `check_environment` maximally strict, so
#: "somebody emptied it to make the check pass" is not a failure mode. The failure mode that IS
#: real is the opposite: an exemption outliving the variable it excuses, so the entry sits here
#: forever excusing a name nothing sets. `test_every_recorded_gap_is_still_in_the_template`
#: exists for exactly that, because asserting an entry has no field does not catch it.
KNOWN_UNMAPPED_VARIABLES: Final[Mapping[str, str]] = {}

#: Variables that ARE configuration, are deliberately NOT fields here, and are read at their
#: point of use by a named module. Exempt from ``check_environment`` for the opposite reason to
#: ``KNOWN_UNMAPPED_VARIABLES``: nothing above is waiting on anything, so an entry here is closed
#: business and shrinking this list is not the goal.
#:
#: The distinction is not bookkeeping. The generic failure message's advice ends "add the field",
#: and for every name below that is the wrong fix — the same reason
#: ``REMOVED_CREDENTIAL_VARIABLES`` gets its own message. An entry still has to justify itself
#: and still has a shelf life: ``test_every_externally_read_variable_is_still_set`` reads
#: ``compose.yaml`` and fails when the deployment stops setting the name, so an exemption cannot
#: outlive its variable and quietly pre-authorise the spelling for something else.
EXTERNALLY_READ_VARIABLES: Final[Mapping[str, str]] = {
    f"{ENV_PREFIX}SAMPLES_ROOT": (
        "Read by `app.evaluation.corpus._resolve_samples_root` as the middle term of an "
        "explicit-argument -> variable -> repository-relative-walk precedence chain, and set "
        "as a literal on `ai-worker-evaluation` in compose.yaml (never as `${KB_SAMPLES_ROOT}`, "
        "which would turn one unset Compose variable into a failed eval run). Three reasons it "
        "is not a field, and each is a behaviour change rather than a preference: (1) "
        "`corpus.py` imports nothing outside the standard library, which is what lets the "
        "corpus-integrity gate run from a bare checkout with an interpreter and no settings "
        "object; (2) reading it from here means `get_settings()`, which runs `check_environment` "
        "— corpus verification would then fail with a RuntimeError about an unrelated variable "
        "instead of the `CorpusUnverified` that maps to `validation` in kb-error-taxonomy, which "
        "is the exact regression the `IndexError: 4` fix closed; (3) pydantic coerces a "
        "set-but-empty value to `Path('.')`, silently converting the deployment error that "
        "resolver refuses by name into a corpus root of the working directory."
    ),
    f"{ENV_PREFIX}WORKER_HEARTBEAT_PATH": (
        "Read by the `kb-worker-healthcheck` probe baked into services/ai-service/Dockerfile, "
        "which is a standalone script that imports NOTHING from `app.` — that is the whole "
        "reason it can answer while the worker is wedged. It carries the `KB_` prefix, so "
        "without this entry putting it in `env/ai-service.env` to point the probe at a "
        "different file would raise RuntimeError in every ai-service process at startup while "
        "the probe itself kept working: the deployment would look like it had been tuned and "
        "would in fact be dead. Not a field, because a field here would be read by the wrong "
        "process — the probe runs in its own interpreter, before and after the one this "
        "module configures. It now has a SECOND reader inside `app.`, "
        "`app/worker/heartbeat.py`, which is the bootstep that writes the file the probe reads. "
        "That does not make it a field: the two readers must agree on one path, and the probe "
        "cannot import a Settings object, so the environment variable IS the shared contract "
        "and a field would be a third spelling of it."
    ),
    f"{ENV_PREFIX}WORKER_HEARTBEAT_MAX_AGE": (
        "The staleness ceiling for the same probe, in seconds (default 120), read by the same "
        "standalone script for the same reason. Exempt as a pair with WORKER_HEARTBEAT_PATH: "
        "tuning a heartbeat interval without being able to tune its staleness window is not a "
        "usable knob, so exempting one and not the other would leave the trap open under the "
        "other name."
    ),
}

#: Variables that WERE settings here and are not any more, each with what replaced it.
#:
#: Removing a field is itself a way to create the silent-default bug this module exists to
#: prevent: with no `postgres_dsn` field, a deployment that still sets `KB_POSTGRES_DSN` has it
#: discarded like any other unrecognised name and then connects with an assembled DSN carrying a
#: different password than the operator set. Every entry here is a secret that moved from an
#: environment VALUE to a mounted FILE, which is why they get their own message instead of being
#: folded into the generic list — that message ends with "add the field", and re-adding these
#: fields is precisely the wrong fix.
REMOVED_CREDENTIAL_VARIABLES: Final[Mapping[str, str]] = {
    f"{ENV_PREFIX}POSTGRES_DSN": (
        f"A DSN carries the database password. Use {ENV_PREFIX}PG_HOST, {ENV_PREFIX}PG_PORT, "
        f"{ENV_PREFIX}PG_DATABASE, {ENV_PREFIX}PG_USER and {ENV_PREFIX}PG_PASSWORD_PATH, which "
        "point at a mounted secret file the way the Valkey ACL credential already does."
    ),
    f"{ENV_PREFIX}QDRANT_API_KEY": (
        f"Use {ENV_PREFIX}QDRANT_API_KEY_PATH; `qdrant_api_key()` reads the mounted file where "
        "the Qdrant client is built."
    ),
    f"{ENV_PREFIX}S3_SECRET_KEY": (
        f"Use {ENV_PREFIX}S3_SECRET_KEY_PATH; `s3_secret_key()` reads the mounted file where the "
        "boto3 client is built. The access key id is the public half and stays a value, as "
        f"{ENV_PREFIX}S3_ACCESS_KEY_ID."
    ),
}


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="KB_",
        extra="forbid",
        frozen=True,
    )

    environment: Literal["local", "ci", "staging", "production"] = "local"

    # ── PostgreSQL ────────────────────────────────────────────────────────────
    # Four plain values and a password PATH — never a DSN, and never a password. See the module
    # docstring. `postgres_dsn()` joins them at the point of use, so a container that holds no
    # database credential (`ai-beat`, `ai-worker-crawl`) still constructs a Settings object.
    #
    # The defaults match `env/ai-service.env.example` so the Compose deployment needs no
    # override for three of the four; the password path has no default because guessing one
    # would turn a missing secret mount into a confusing read error instead of "not configured".
    pg_host: str = "postgres"
    pg_port: int = 5432
    pg_database: str = "knowledgebot"
    # `kb_app`, NOT `knowledgebot` — the PostgreSQL role split moved every runtime service onto
    # the non-superuser application role, and `env/ai-service.env.example` was updated to
    # `KB_PG_USER=kb_app` while this default was not. That is precisely the drift the comment
    # above promises does not exist, and test_the_defaults_match_the_deployment_template in
    # tests/unit/test_settings_dsn.py exists to catch it: `assert 'kb_app' == 'knowledgebot'`.
    # The database name is unchanged — the split renamed the ROLE, not the database, which is
    # why only this one line moves.
    pg_user: str = "kb_app"
    pg_password_path: Path | None = None

    postgres_pool_min: int = 2
    postgres_pool_max: int = 10

    qdrant_url: str = "http://qdrant:6333"
    #: A PATH, not the key. Qdrant's REST API is unauthenticated by default, so network
    #: isolation is the primary control and this is the second latch — but a second latch
    #: delivered as an environment value is one `docker inspect` away from being no latch at
    #: all. `qdrant_api_key()` reads it where the client is built.
    #:
    #: `None` is valid and must stay valid: `ai-worker-crawl` is deliberately kept off the
    #: `data` network and holds no such mount, and a Qdrant started without `--api-key` needs
    #: none either.
    qdrant_api_key_path: Path | None = None
    # There is deliberately NO `qdrant_collection` setting. The name lives in
    # app/retrieval/collection.py as a module constant, because a name that can be
    # overridden per environment is a name that can disagree between the indexer and the
    # reader — and that disagreement raises nothing, shows nothing in a config diff, and
    # reads as an empty corpus.
    #
    # Parallel tests still need isolation, but that is a different mechanism: the test
    # harness derives a per-worker suffix from the xdist worker id rather than reading an
    # environment variable. See tests/conftest.py.

    # Two Valkey servers, not two logical databases: maxmemory-policy, save and appendonly
    # are server-level settings, so `SELECT n` cannot give the broker noeviction while the
    # cache runs allkeys-lru. The broker DB must never evict — kombu raises
    # InconsistencyError if a _kombu.binding.* key disappears.
    #: DB **1**, not 0. Verified against three independent statements of the same fact:
    #: `valkey-keyspaces`' catalogue ("Celery broker … core db 1"),
    #: `infrastructure/docker/valkey/users.acl` (the `kb-ai` user's channel grants are
    #: `&/1.celery.pidbox` and `&/1.celeryev/worker.*`), and `env/ai-service.env`
    #: (`KB_BROKER_URL=redis://valkey-core:6379/1`). DB 0 is Laravel's — its queues AND its
    #: default cache store. Containers load the env file so they were never affected; what
    #: this default governs is every process that does NOT — a pytest run, an ad-hoc `celery
    #: inspect`, a shell in the image — and those landed in Laravel's keyspace, where the two
    #: share `_kombu.binding.*` and `unacked` and quietly consume each other's jobs.
    broker_url: str = "redis://valkey-core:6379/1"
    cache_url: str = "redis://valkey-cache:6379/0"
    #: Locks, fences, nonces, idempotency records and breaker state. A separate logical DB on
    #: the non-evicting server; every family here is absence-sensitive.
    coordination_url: str = "redis://valkey-core:6379/2"

    # ── Valkey authentication ─────────────────────────────────────────────────
    # `users.acl` sets `user default off`, so every connection authenticates or the server
    # answers NOAUTH. The credential is deliberately NOT part of the three URLs above and
    # must never be written into one: a URL is an environment value, and an environment
    # value is visible in `docker inspect`, in `docker compose config`, and in a crash dump.
    # It arrives as a mounted file, and `_reject_embedded_credentials` fails startup if
    # somebody helpfully inlines it anyway.
    valkey_username: str = "kb-ai"
    valkey_password_path: Path | None = None

    # ── object storage ────────────────────────────────────────────────────────
    # THERE IS NO `s3_addressing_style`, AND ADDING ONE IS A REGRESSION. Exactly one value
    # works: virtual-host addressing resolves `kb.seaweedfs-s3`, which needs wildcard DNS a
    # Compose network does not have, and fails with NXDOMAIN / EndpointConnectionError. Pin
    # `s3={"addressing_style": "path"}` in the boto3 client config, beside SigV4 and the
    # `when_required` checksum setting, where the three that must travel together are visible
    # at once. A setting whose only correct value is one value is not configuration — it is a
    # way to break the deployment that looks like a knob.
    #
    # The defaults here match `env/ai-service.env.example` AND `env/core-api.env.example`,
    # which matters for two of them in different ways (see below). Test-built `Settings()`
    # objects read no environment at all, so a default that disagrees with the template points
    # every test at a bucket that does not exist.
    s3_endpoint: str = "http://seaweedfs-s3:8333"
    #: ONE bucket, forever; tenancy is the key prefix, enforced in code. Laravel's `AWS_BUCKET`
    #: names the same one — this default used to read `kb-objects` while both templates said
    #: `kb`.
    #: `kb-objects`, not `kb`: S3 bucket names are 3–63 characters and SeaweedFS enforces it in
    #: the filer on every write, so a two-character name cannot hold one object. See
    #: `services/core-api/config/filesystems.php` for the measurement and `docs/22` § R5 for the
    #: record. The two planes must agree on this string or Laravel writes an original the data
    #: plane cannot find, which surfaces as a 404 per object rather than as a configuration error.
    s3_bucket: str = "kb-objects"
    #: SeaweedFS IGNORES the region and SigV4 SIGNS it, so this string must be byte-identical to
    #: Laravel's `AWS_DEFAULT_REGION`. A drift is a `SignatureDoesNotMatch` that reads like a
    #: credential problem, which is why the deployment states it on both sides rather than
    #: leaving each codebase to its own default.
    s3_region: str = "us-east-1"
    #: A PUBLIC IDENTIFIER, NOT A SECRET — a plain `str`, and deliberately not `SecretStr`. It
    #: is the SeaweedFS identity name (`AWS_ACCESS_KEY_ID` in Laravel, `aws_access_key_id` in
    #: boto3, the same `kb-app` in the identities file); it belongs in a log line when a request
    #: is signed by the wrong identity. The secret is the field below, and the `_ID`/`_PATH`
    #: asymmetry is what tells a reader which half is which.
    s3_access_key_id: str = "kb-app"
    #: A PATH, not the key. `s3_secret_key()` reads it where the boto3 client is built. `None`
    #: stays valid for the containers that hold no object-storage mount.
    s3_secret_key_path: Path | None = None

    # ── document processing ───────────────────────────────────────────────────
    #: Which OCR engine this deployment runs, and in which languages. **Both are inside
    #: `ocr_cfg_version`** and therefore inside the ingest key: changing either re-versions
    #: every scanned document, which is the intended behaviour and is why they are named
    #: settings rather than constants read at the call site.
    #:
    #: THEY ARE DEPLOYMENT-WIDE HERE AND THE SPEC WANTS THEM NARROWER. `ocr_cfg_version`'s own
    #: docstring says the engine is admin-selectable and the language list is per-corpus — a
    #: single-script tenant routes to RapidOCR, a mixed-script one to tesserocr. Neither has a
    #: column on any table yet, so a deployment default is what exists; it is recorded here as
    #: the gap it is rather than hidden behind a constant, because the day a per-source column
    #: lands, THIS is the value it has to supersede.
    #:
    #: The engine string must be a key of `OCR_ENGINE_CLASSES`, which the worker asserts at
    #: startup — not imported here, because reaching into `app.ingestion.ocr.guarded` from
    #: settings would pull Docling and torch into every process that reads configuration.
    ocr_engine: str = "rapidocr"
    #: Never empty. An empty list lets the engine's own default win silently, which is a
    #: different model reading the page than the one recorded — `ocr_cfg_version` refuses it.
    ocr_languages: tuple[str, ...] = ("en",)

    # ── internal transport ────────────────────────────────────────────────────
    # Read from a mounted secret path, never from an environment variable: `docker compose
    # config` renders interpolated values in full, and an env var is visible to every
    # `docker inspect`.
    hmac_key_dir: Path = Path("/run/secrets")
    #: Outbound: the key id this service signs its own callbacks WITH is NOT this one.
    #: `k1`/`k2` are Laravel→FastAPI; `c1`/`c2` are FastAPI→Laravel callbacks. Signing a
    #: callback with `k1` gets rejected by a verifier holding only `c1`/`c2`, and the failure
    #: is a silent 401 on every async job callback — jobs simply never report terminal state.
    hmac_active_key_id: str = "k1"
    callback_active_key_id: str = "c1"

    #: Where those callbacks GO. Four async flows in `kb-internal-api-contracts` — ingestion
    #: progress, crawl page results, deletion verification, evaluation metrics — answer
    #: `202 {job_id}` and deliver their real result over a signed callback, so every one of them
    #: needs a base URL and there is nowhere else for it to come from. Without it the callback
    #: direction is a credential (`callback_active_key_id`, above) for a destination with no
    #: name.
    core_api_url: str = "http://laravel-api:8080"

    #: Both prefixes stay accepted across a rotation. ADR-018 bumps the scheme prefix over two
    #: deploys; verifying only the active one makes the first deploy reject the second's
    #: in-flight requests.
    accepted_signing_prefixes: tuple[str, ...] = ("KB1",)

    #: Rejects a signature whose timestamp is further than this from now, in seconds.
    hmac_max_skew_seconds: int = 60
    #: Must EXCEED hmac_max_skew_seconds. A nonce that expires inside the skew window lets a
    #: replay arrive after the nonce is gone but while the timestamp still verifies.
    replay_nonce_ttl_seconds: int = 120

    #: A mismatch is a 409, never a best-effort guess at what the caller meant.
    contract_version: int = 1

    # ── local model weights: PARSING AND OCR ONLY ─────────────────────────────
    # ADR-030 moved embedding and reranking to provider APIs. This path is NOT dead as a
    # result: Docling's layout and TableFormer models and RapidOCR's ONNX weights still live
    # here, and both are deliberately still local — they extract text from files, need no
    # credential, cost nothing per call, and never send a customer document to a third party.
    #
    # `HF_HOME` is the variable that actually drives caching for those libraries; this
    # setting is the value we point it at. Keep the two equal — a mismatch means the weights
    # are downloaded at RUNTIME instead of found, which `HF_HUB_OFFLINE=1` then turns into a
    # first-parse failure rather than a slow first parse.
    #
    # Revisions live in models.manifest.toml, not here. A revision in two places drifts.
    model_cache_dir: Path = Path("/models")

    #: How many chunk texts go into ONE provider embedding request. This is now a request-
    #: shaping number, not a GPU-memory number: it trades requests-per-minute against per-
    #: request latency and against how much work is lost when one call fails. There is no
    #: `embedding_device` any more — nothing embeds locally, so there is no device to pick.
    embedding_batch_size: int = 8

    # ── timeout budgets (kb-error-taxonomy) ───────────────────────────────────
    # The rule is arithmetic, not preference:
    #   attempts x (inner timeout + max backoff) + overhead < outer timeout.
    # 8 + 45 = 53 < 55, so the internal call always has room to close its stream cleanly
    # before Laravel's 55 s budget expires. Raising retrieval_timeout without lowering
    # provider_timeout bills tokens for a completion the caller already abandoned.
    internal_call_timeout: float = 55.0
    retrieval_timeout: float = 8.0
    provider_total_timeout: float = 45.0
    provider_first_token_timeout: float = 20.0
    provider_connect_timeout: float = 3.0

    # ── telemetry ─────────────────────────────────────────────────────────────
    otel_service_name: str = "ai-api"

    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"

    @model_validator(mode="after")
    def _nonce_outlives_skew(self) -> Settings:
        if self.replay_nonce_ttl_seconds <= self.hmac_max_skew_seconds:
            msg = (
                f"replay_nonce_ttl_seconds ({self.replay_nonce_ttl_seconds}) must exceed "
                f"hmac_max_skew_seconds ({self.hmac_max_skew_seconds}): a nonce that expires "
                "inside the skew window lets a replay verify"
            )
            raise ValueError(msg)
        return self

    @field_validator("broker_url", "cache_url", "coordination_url")
    @classmethod
    def _reject_valkey_scheme(cls, v: str) -> str:
        # kombu has no valkey:// transport alias; the scheme raises "No such transport" at
        # first connect, which is long after startup and looks like a broker outage.
        if v.startswith("valkey://"):
            msg = (
                "use redis:// — kombu has no valkey transport alias "
                "(the server is Valkey, the wire is RESP)"
            )
            raise ValueError(msg)
        return v

    @field_validator("broker_url", "cache_url", "coordination_url")
    @classmethod
    def _reject_embedded_credentials(cls, v: str) -> str:
        # `redis://kb-ai:s3cr3t@valkey-core:6379/1` works, which is exactly why it has to
        # fail here: it puts the ACL password into an environment variable, and from there
        # into `docker inspect` and every rendered config. The password belongs in a mounted
        # file; `authenticated_valkey_url` joins the two at connect time.
        _, _, remainder = v.partition("://")
        if "@" in remainder.partition("/")[0]:
            msg = (
                "Valkey credentials must not be embedded in a URL — set KB_VALKEY_USERNAME "
                "and KB_VALKEY_PASSWORD_PATH instead. An inlined password is an environment "
                "value, and an environment value is in `docker inspect`."
            )
            raise ValueError(msg)
        return v


@lru_cache(maxsize=8)
def _read_password(path: Path, label: str = "Valkey password") -> str:
    """Read a mounted secret once per path, and never log it.

    THE ONE READER FOR ALL FOUR CREDENTIALS. ``label`` names the whole thing — "PostgreSQL
    password", "Qdrant API key" — and only shapes the message, but the message is the whole
    value of this function: a secret that is missing, unreadable or empty produces an
    authentication failure at first connect, which reads as a datastore outage. Naming the file
    turns a half-hour into a glance. A second reader written beside this one is a second set of
    messages that will disagree about which file was involved.

    ``lru_cache`` because a mounted secret does not change under a running process, and the
    alternative is a filesystem read per connection. Exceptions are not cached, so a mount that
    appears late still works on the next call.
    """
    try:
        secret = path.read_text(encoding="utf-8").strip()
    except OSError as exc:
        msg = f"{label} file {path} is unreadable ({exc.strerror})"
        raise RuntimeError(msg) from exc
    if not secret:
        # An empty secret authenticates as nothing. With `user default off` the Valkey server
        # answers NOAUTH on the first command, and Qdrant answers 403 on the first query —
        # both of which read as an outage of that service rather than as a missing secret
        # file. So fail here, where the message can say which file.
        msg = f"{label} file {path} is empty"
        raise RuntimeError(msg)
    return secret


def postgres_dsn(
    *,
    host: str,
    port: int,
    database: str,
    user: str,
    password_path: Path | None,
) -> SecretStr:
    """Assemble the libpq URI from plain settings plus the mounted password.

    Called where a connection is opened — ``lifespan`` for the API, ``worker_process_init`` for
    a Celery child — and **never at import**. That placement is the point: ``ai-beat`` and
    ``ai-worker-crawl`` legitimately hold no database credential, and a settings object that
    could not be constructed without one made those containers unstartable for a credential
    they are designed not to have.

    Returns a ``SecretStr``. The password is in the string, so a bare ``str`` return would put
    it into any traceback frame that happens to hold the value, and into ``repr()`` of every
    object that stores it.

    ``password_path=None`` yields a DSN with no password, which is the local-development shape —
    a Postgres with ``trust`` auth, the same allowance ``authenticated_valkey_url`` makes for a
    Valkey with no ACL file. In a deployment the server rejects it at connect with an
    authentication failure naming the user, which is loud and correct; the alternative,
    inventing a default password path, turns an unmounted secret into a file-read error about a
    path nobody configured.

    PERCENT-ENCODING IS NOT OPTIONAL, and it matters more here than for Valkey because the
    database name follows the authority. ``bootstrap.sh`` writes ``openssl rand -base64``
    output, which emits ``+``, ``/`` and ``=``. A raw ``/`` inside the userinfo terminates the
    authority, so ``postgresql://kb:pa/ss@postgres:5432/kb`` parses as host ``kb:pa`` with path
    ``/ss@postgres:5432/kb``. libpq does not reject that — it resolves somewhere else, or fails
    with an error that sends everyone to look at the wrong secret.
    """
    userinfo = quote(user, safe="")
    if password_path is not None:
        secret = _read_password(password_path, "PostgreSQL password")
        userinfo = f"{userinfo}:{quote(secret, safe='')}"
    return SecretStr(f"postgresql://{userinfo}@{host}:{port}/{quote(database, safe='')}")


def qdrant_api_key(path: Path | None) -> SecretStr | None:
    """Read the mounted Qdrant API key, or ``None`` when no key is mounted.

    Called where the client is constructed — ``lifespan`` for the API, ``worker_process_init``
    for a Celery child — and **never at import**, for the same reason ``postgres_dsn`` is:
    ``ai-worker-crawl`` is deliberately kept off the ``data`` network and holds no such mount,
    and a settings object that could not be constructed without one would make a correct
    container unstartable.

    ``None`` in, ``None`` out, and that is a supported deployment rather than a fallback:
    Qdrant's REST API is unauthenticated unless the server was started with ``--api-key``, so
    the isolation of the ``data`` network is the primary control and this is the second latch.
    Pass the result through as ``api_key=…`` and let the server answer 403 if it disagrees —
    inventing a default path here would turn an unmounted secret into a file-read error about a
    path nobody configured.

    Returns ``SecretStr``: an API key that reaches a log line, a span attribute or a
    ``/health/deps`` body is a leaked credential, and a bare ``str`` return puts it into the
    ``repr()`` of every object that then holds it.
    """
    if path is None:
        return None
    return SecretStr(_read_password(path, "Qdrant API key"))


def s3_secret_key(path: Path | None) -> SecretStr | None:
    """Read the mounted object-storage secret key, or ``None`` when none is mounted.

    The counterpart to ``s3_access_key_id``, which is a plain ``str`` field because it is a
    public identifier. This half is the credential, so it arrives as a file path and is read at
    the point the boto3 client is built.

    ``None`` in, ``None`` out. ``ai-beat`` and ``ai-worker-crawl`` never touch object storage
    and hold no such mount, so the failure they must not have is at construction; what happens
    at first *use* is the caller's to decide. Do not rely on boto3 tolerating ``None`` — pass
    the credential explicitly and let the client builder refuse when it is absent.

    .. UNVERIFIED: botocore's documented behaviour for an explicit
       ``aws_secret_access_key=None`` (fall through to the credential chain, versus an
       unsigned request) has not been re-checked against current boto3 docs. Whoever writes
       the S3 client should confirm it rather than inherit the assumption from here.
    """
    if path is None:
        return None
    return SecretStr(_read_password(path, "S3 secret key"))


def unknown_kb_variables(environ: Mapping[str, str]) -> list[str]:
    """Every ``KB_`` variable in ``environ`` with no matching field and no recorded exemption.

    Pure and takes the environment as an argument, so it is testable without mutating the
    process — and so a startup check and a diagnostic endpoint can ask the same question.

    This is the check ``extra="forbid"`` is widely assumed to perform and does not: pydantic-
    settings resolves each field by *looking up* its own name, and never enumerates the
    environment, so a name it does not recognise is a name it never sees.

    "No matching field" is subtracted from twice, and the two subtractions are not
    interchangeable. ``KNOWN_UNMAPPED_VARIABLES`` is a name nobody has reconciled yet;
    ``EXTERNALLY_READ_VARIABLES`` is a name somebody decided will never be a field and is read
    somewhere else. Both are exempt, and only the first should ever be expected to shrink.
    """
    fields = {f"{ENV_PREFIX}{name.upper()}" for name in Settings.model_fields}
    return sorted(
        name
        for name in environ
        if name.startswith(ENV_PREFIX)
        and name not in fields
        and name not in KNOWN_UNMAPPED_VARIABLES
        and name not in EXTERNALLY_READ_VARIABLES
    )


def check_environment(environ: Mapping[str, str]) -> None:
    """Fail startup on an environment this process would otherwise misread in silence.

    Two checks, and the first exists because removing a field is itself a way to create the
    silent-default bug: with ``postgres_dsn`` gone, a deployment that still sets
    ``KB_POSTGRES_DSN`` would have it discarded like any other unknown name, and would then
    connect with an assembled DSN carrying a different password. Every entry in
    ``REMOVED_CREDENTIAL_VARIABLES`` is that same transition — a secret that used to be an
    environment value and is now a mounted file — so each gets its own message naming its
    replacement, rather than being folded into a generic list whose advice ends "add the field".
    """
    for name, replacement in REMOVED_CREDENTIAL_VARIABLES.items():
        if name in environ:
            msg = (
                f"{name} is set and this service has no such setting. {replacement} An "
                "environment value is readable through `docker inspect`, `docker compose "
                "config`, a crash dump and every child process; a mounted file is none of "
                "those."
            )
            raise RuntimeError(msg)

    unknown = unknown_kb_variables(environ)
    if unknown:
        msg = (
            f"{len(unknown)} {ENV_PREFIX}* environment variable(s) match no setting and are "
            f"not recorded as a known gap: {unknown}. pydantic-settings would ignore each one "
            'silently — `extra="forbid"` cannot see environment variables — so the process '
            "would start with that setting at its default and no indication anywhere. Fix the "
            "spelling, add the field, record it in KNOWN_UNMAPPED_VARIABLES with the "
            "reconciliation it is waiting on, or — if it is real configuration read at its "
            "point of use by a module that must not import this one — record it in "
            "EXTERNALLY_READ_VARIABLES with why it is not a field."
        )
        raise RuntimeError(msg)


def authenticated_valkey_url(url: str, *, username: str, password_path: Path | None) -> SecretStr:
    """Join a credential-free URL with the mounted ACL credential.

    Returns ``url`` unchanged when no password path is configured — local development runs
    a Valkey with no ACL file, and requiring one there would make the service unrunnable
    outside Compose.

    Returns a ``SecretStr``, for the reason ``postgres_dsn`` states and for one more that is
    specific to this URL's consumers. The ACL password is *in* the string, so a bare ``str``
    return puts it into any traceback frame holding the value and into ``repr()`` of every
    object that stores it — and here the storing object is a ``redis`` connection holder whose
    own ``__repr__`` renders every keyword it was built with, including ``password``. Unwrap
    with ``.get_secret_value()`` on the line that builds the client, never earlier, so the
    plain form exists for exactly one expression.

    PERCENT-ENCODING IS NOT OPTIONAL. `openssl rand -base64 24` — what `bootstrap.sh`
    writes — emits ``+``, ``/`` and ``=``. A raw ``/`` inside the userinfo terminates the
    authority component, so ``redis://kb-ai:pa/ss@valkey-core:6379/1`` parses with host
    ``kb-ai:pa`` and path ``/ss@valkey-core:6379/1``. That is not a parse error; it is a
    connection to the wrong place, or a NOAUTH that sends everyone looking at the ACL file.
    """
    if password_path is None:
        return SecretStr(url)
    scheme, separator, remainder = url.partition("://")
    if not separator:
        msg = f"not a URL: {url!r}"
        raise ValueError(msg)
    credential = f"{quote(username, safe='')}:{quote(_read_password(password_path), safe='')}"
    return SecretStr(f"{scheme}://{credential}@{remainder}")


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Cached so `Depends(get_settings)` resolves once per process.

    The cache key is the callable object, so wrapping this in a lambda or functools.partial
    at a call site silently re-reads the environment on every request.

    `check_environment` runs **here** rather than in a model validator, and the distinction is
    exact: this is the only path that reads the environment — `app/main.py` and
    `app/worker/__init__.py` both come through it, which covers all six containers — while a
    validator would also fire on the programmatic `Settings(...)` a test constructs, where the
    ambient environment is irrelevant and the process's own `KB_*` variables are not the
    configuration under test.
    """
    check_environment(os.environ)
    # No `type: ignore[call-arg]` here: the pydantic mypy plugin (configured in
    # pyproject.toml) understands that a BaseSettings subclass fills required fields from the
    # environment, so the ignore is reported as unused and `--strict` fails on it. Running
    # mypy WITHOUT the plugin does need one — which is the tell that the plugin is missing
    # from the invocation rather than a reason to add the comment back.
    return Settings()

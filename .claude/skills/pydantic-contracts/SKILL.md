---
name: pydantic-contracts
description: Pydantic v2 model design and validation policy for the FastAPI AI service — extra="forbid" inbound, strict types on the config snapshot, discriminated unions for stream events, SecretStr for credentials, and how a model changes without breaking a deployed Laravel. Use whenever adding or editing a model under services/ai-service/app/, or when an internal request 422s unexpectedly. Owns model shape only; the wire protocol is kb-internal-api-contracts. Pairs with kb-error-taxonomy (validation is a class).
---

# Pydantic Contract Models

Pydantic **2.13.4** (2026-05-06, latest stable — verified against the published changelog), Python 3.12+, in `services/ai-service/`.
**Authoritative spec:** docs/06-architecture.md §11.2, §11.4, docs/12-api-areas.md §17.5, docs/11-data-model.md §16.2

## Non-negotiables

- **`extra="forbid"` on every model we receive from our own code.** Pydantic's default is `extra="ignore"`. A field Laravel renamed — `top_k` → `dense_top_k` — is then silently dropped: the caller believes it configured retrieval, FastAPI runs its default, no error is raised anywhere, and `config_version` still asserts the two sides agree. The only exceptions are the two boundaries named below.
- **`strict=True` on every inbound contract model.** Lax mode turns `"8"` into `8` and `1` into `True`. The whole point of shipping the configuration snapshot in the body (docs/06 §11.2) is that a replayed job reproduces byte-identically; a snapshot whose meaning changes during parsing breaks that guarantee silently.
- **Every credential field is `SecretStr`, and no model holding one is ever serialized anywhere but into the provider call.** `model_dump()` yields the masked object, `model_dump_json()` yields `"**********"` — protective for logs, destructive for anything that has to survive a round trip. `kb-security-baseline` owns the rule; this file owns the type.
- **A `ValidationError` never reaches a log, a span, or an error envelope with its defaults.** `errors()` and `json()` default to `include_input=True`, so the rejected value — the tenant's question, or the API key — is rendered verbatim. Validation failures map to the `validation` class (422, never retried) in `kb-error-taxonomy`.
- **Any union crossing the wire carries a discriminator.** An untagged union validates every member and reports every member's failure; during an incident the error names the wrong variant. Tagged, a bad tag is one `union_tag_invalid` naming the value actually received.
- **A shipped model shape never changes in place.** Additive only, or a new `/internal/v2` (`kb-internal-api-contracts`). With `extra="forbid"`, *removing* a field is as breaking as adding a required one — see the versioning table.

## How we use it

Owned elsewhere, cited not restated: headers, signing, SSE semantics, idempotency-key composition → `kb-internal-api-contracts`. Routers, dependencies, exception-handler registration → `fastapi-service`. The provider request/response models themselves → `kb-provider-adapter-contract`. Table and column types → `postgresql-patterns`.

### The `extra` policy, and its two exceptions

| Model | `extra` | Why |
|---|---|---|
| Internal request bodies and the nested config snapshot | `forbid` | Sender is our own Laravel; a dropped field is a silent misconfiguration |
| Internal responses and SSE event payloads | `forbid` | We construct these; `forbid` catches a typo'd kwarg at construction, not in a client |
| **Raw provider responses** | `ignore` | Providers add fields weekly; `forbid` converts every one into an outage |
| **Celery task args and anything read back out of Valkey** | `ignore` | A queued message written by the previous deploy is validated by the next one |

### Where validation runs, and where it deliberately does not

| Boundary | Validate |
|---|---|
| Request body from Laravel | **yes** — strict + forbid; the only place a bad snapshot is catchable |
| Raw provider response → normalized model | **yes** — `extra="ignore"` |
| Qdrant payload read back (≤30 candidates/query) | **yes** — small N, and a payload missing `org_id` must be loud (`kb-tenancy-isolation`) |
| Per-token `Token`/`Delta` we produced ourselves | **no** — `model_construct()` |
| A model already validated, handed down the call stack | **no** — `revalidate_instances` stays at its `never` default |

### The internal chat request, complete

```python
# services/ai-service/app/contracts/internal/chat.py
from enum import StrEnum
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, SecretStr, StringConstraints, TypeAdapter, ValidationError

# ULIDs, not UUIDs (kb-internal-api-contracts, kb-observability-conventions).
# `uuid.UUID("01J8...")` raises — a `UUID`-typed id field 422s every real request.
Ulid = Annotated[str, StringConstraints(pattern=r"^[0-7][0-9A-HJKMNP-TV-Z]{25}$")]


class Inbound(BaseModel):
    """Base for everything Laravel sends. Three settings, three distinct jobs.
    strict: "8" must not become 8 and 1 must not become True — X-KB-Config-Version
            claims both sides hold the same snapshot, and coercion makes that a lie.
    forbid: a renamed field must 422 here rather than evaporate.
    frozen: a request is evidence, not scratch space; it also makes the model hashable,
            so a resolved snapshot can key a per-request cache."""
    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

class ReasoningEffort(StrEnum):
    NONE = "none"
    MINIMAL = "minimal"   # OpenAI ships this between NONE and LOW
    LOW, MEDIUM, HIGH = "low", "medium", "high"
    XHIGH = "xhigh"       # Anthropic ships this between HIGH and MAX
    MAX = "max"
    # Seven members. MINIMAL and XHIGH are not synonyms of their neighbours — an enum
    # missing either silently rounds a provider's recommended setting to the nearest
    # level we happen to model (kb-provider-adapter-contract).

class ProviderConnection(Inbound):
    connection_id: Ulid
    provider: Literal["openai", "anthropic", "deepseek", "nvidia_nim", "openrouter"]
    model: str
    api_key: SecretStr          # repr/str -> '**********'; get_secret_value() appears
    base_url: str | None = None # exactly once in the codebase, inside the adapter

class RetrievalConfig(Inbound):
    dense_top_k: int = Field(ge=1, le=200)
    sparse_top_k: int = Field(ge=1, le=200)
    rerank_top_n: int = Field(ge=0, le=100)   # 0 disables reranking
    fusion_k: int = Field(ge=1, le=100)       # never inherit Qdrant's k=2 (kb-rag-query-contract)
    retain: int = Field(ge=1, le=50)

class ConfigSnapshot(Inbound):
    """docs/06 §11.2 — FastAPI never queries Laravel's tables; this is the whole input."""
    config_version: int = Field(ge=1)                  # mirrors X-KB-Config-Version
    retrieval_configuration_version: int = Field(ge=1)
    connection: ProviderConnection
    retrieval: RetrievalConfig
    max_output_tokens: int = Field(ge=1, le=200_000)
    temperature: float | None = None                   # int->float stays legal under strict
    reasoning_effort: ReasoningEffort = ReasoningEffort.NONE
    stream: bool = True

class ChatExecuteRequest(Inbound):
    org_id: Ulid
    bot_id: Ulid
    conversation_id: Ulid
    client_message_id: Ulid
    actor_type: Literal["user", "anonymous_session", "scheduler", "system"]
    query: str = Field(min_length=1, max_length=32_000)
    deadline_epoch_ms: int = Field(ge=0)   # absolute, per kb-internal-api-contracts.
    config: ConfigSnapshot                 # int, not datetime — see the strict-mode gotcha.


# --- the outbound stream, as a discriminated union -------------------------------
class Event(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)
class MessageStart(Event):
    event: Literal["message.start"] = "message.start"
    message_id: Ulid
    conversation_id: Ulid
class Status(Event):
    event: Literal["status"] = "status"
    stage: Literal["retrieving", "reranking", "generating"]
class Token(Event):
    event: Literal["token"] = "token"
    text: str                              # nothing else — every key is paid per token
class Citation(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)
    n: int; source_id: Ulid; source_version_id: Ulid; chunk_id: Ulid; locator: str
class Citations(Event):
    # MANDATORY and easy to forget: kb-internal-api-contracts requires this BEFORE the first
    # token, because citations are assigned from evidence pre-generation. Leaving it out of the
    # union does not fail at import — it fails at runtime with `union_tag_invalid` the first
    # time a real answer streams, since Event sets extra="forbid".
    event: Literal["citations"] = "citations"
    citations: list[Citation]
class Usage(Event):
    event: Literal["provider.usage"] = "provider.usage"
    input_tokens: int; output_tokens: int; cached_tokens: int = 0
class MessageComplete(Event):
    event: Literal["message.complete"] = "message.complete"
    message_id: Ulid
    finish_reason: Literal["stop", "length", "cancelled", "insufficient_evidence", "error"]
    usage: Usage | None = None             # the wire schema and every client type carry it
class StreamError(Event):
    event: Literal["error"] = "error"
    error_class: str                       # one of the 18 in kb-error-taxonomy
    message: str
    retryable: bool

StreamEvent = Annotated[
    MessageStart | Status | Citations | Token | Usage | MessageComplete | StreamError,
    Field(discriminator="event"),
]
STREAM_EVENT = TypeAdapter(StreamEvent)    # module level: each construction builds a
                                           # fresh Rust validator, so never inside a handler
def emit_token(text: str) -> bytes:
    """Hot path — one call per generated token. `model_construct` applies defaults and
    skips validation; re-validating a `str` we just produced is pure cost per token."""
    return f"event: token\ndata: {Token.model_construct(text=text).model_dump_json()}\n\n".encode()
def parse_request(raw: bytes) -> ChatExecuteRequest:
    try:
        return ChatExecuteRequest.model_validate_json(raw)
    except ValidationError as exc:
        # include_input=False is not optional: the rejected input is routinely the
        # config snapshot, and errors() renders it raw (kb-security-baseline).
        raise KbError("validation", detail=exc.errors(
            include_input=False, include_context=False, include_url=False))
```

### Changing a model without breaking a deployed Laravel

`X-KB-Contract-Version` versions the **shape**; `X-KB-Config-Version` versions the **content** of one snapshot. Bumping the config version tells FastAPI nothing about a new field, and adding a field does not make old snapshots invalid — conflating the two is how a shape change ships with no version bump at all.

| Change to an inbound model | Safe against a Laravel that has not redeployed? |
|---|---|
| Add an optional field with a default | **yes** — the old sender omits it |
| Add a required field | **no** — `missing` on every request. Ship optional-with-default, migrate the sender, then tighten |
| Remove a field | **no** — `extra_forbidden` on every request. This is the price of `forbid`. Stop reading it, migrate the sender, then delete |
| Rename a field | **no** — it is remove + add. Bridge with `validation_alias=AliasChoices("new", "old")`, which accepts both *without* relaxing `extra` |
| Widen a constraint (`le=100` → `le=200`) | **yes** |
| Narrow a constraint, or tighten a `Literal` | **no** |
| Add a member to an enum we **receive** | **yes** — the old sender never sends it |
| Add a member to an enum we **send** (e.g. `finish_reason`) | **no** — client matches are exhaustive; that is a `/internal/v2` change |
| Change a field's type | **never** — add a new field and deprecate the old one |

Enum discipline at the two edges is deliberately asymmetric. **Our own seam is strict:** an unrecognized `reasoning_effort` from Laravel is a `validation` error, because Laravel is our code and a typo there is a bug. **The provider edge is tolerant but loud:** parse the native value as `str`, map it through an explicit table, fall back to a known member, and preserve the raw string in `Diagnostics.native_stop_reason` plus a counter. An unknown provider value must not crash the pipeline and must not disappear.

## Gotchas

- **Every async job fails with "invalid API key" while the provider connection test passes green.** The snapshot was re-serialized on its way to Celery: `model_dump_json()` on a model holding a `SecretStr` writes the literal `"**********"`, the worker calls the provider with that, gets 401, and the adapter classifies `provider_auth` — which is neither retryable nor fallback-eligible, so the tenant is told to rotate a key that was never wrong. Sync chat keeps working, which is why it reads as an ingestion bug. Never put a credential-bearing model on the broker; pass `connection_id` and re-resolve inside the task.
- **A 422 body in the Laravel log contains the tenant's question and the provider key.** `ValidationError.errors()` and `.json()` default `include_input=True`, `include_context=True`, `include_url=True`. FastAPI registers a `RequestValidationError` handler by default and it renders `exc.errors()` into the response body, so the value crosses the wire as well as landing in a log. <!-- UNVERIFIED: that the default handler's output includes the `input` key was not re-checked against the pinned FastAPI 0.141.1 --> `fastapi-service` owns registering the override; this file owns what it may emit — `type` + `loc` + `msg`, nothing else.
- **A payload that passes the unit test 422s against the running service with `datetime_type` / `uuid_type` / `decimal_type`.** Strict mode is *looser* from JSON than from Python: `TypeAdapter(date).validate_json('"2000-01-01"', strict=True)` succeeds, `validate_python('2000-01-01', strict=True)` raises, because JSON has no native date type. The test called `model_validate_json`; the server validated a dict someone had already parsed. Decision: no `datetime`, `UUID`, or `Decimal` field on a strict inbound model — epoch milliseconds (`X-KB-Deadline` already is one), ULID `str`, money as `str` converted explicitly. Contract tests go through an HTTP client, never straight into `model_validate_json`.
- **One malformed event produces five validation errors naming the wrong variant.** An untagged `Union` runs in smart mode: it tries every member and reports every member's failures, so a missing `stage` on a `status` event is reported as a broken `token`, a broken `citations`, and a broken `message.complete`. `Field(discriminator="event")` validates exactly one member and yields a single `union_tag_invalid` carrying the tag it actually saw. It is also the documented performance recommendation — N type-checks become one dict lookup.
- **Reranking is off in production and the config diff is empty.** `rerank_top_n` arrived as `"0"` from a PHP cast, or `rerank_enabled` as `0`, and lax mode coerced both without complaint while `config_version` continued to assert the snapshot matched. `strict=True` turns them into `int_type` / `bool_type` at the boundary, where a contract test sees it. Note the deliberate exception: `int` → `float` remains legal in strict mode, so `temperature: 1` → `1.0` is intended, not a leak in the policy.
- **An `org_id: UUID` field rejects every real request.** Identifiers on this platform are ULIDs; `uuid.UUID` cannot parse Crockford base32. `kb-provider-adapter-contract`'s example types `org_id`/`bot_id` as `UUID` — that contradicts the wire format in `kb-internal-api-contracts` and must be reconciled there, not worked around here with a coercing validator. Type IDs as a pattern-constrained `str`.
- **Queued ingestion tasks start failing with `extra_forbidden` during a rolling deploy.** A Celery message serialized by the old workers is validated by the new ones. Broker payloads time-shift across deploys exactly the way a third party's response shape does, which is why they are the second named exception to `forbid`. The mirror-image failure — a field removed from the task model — is the same table row as removing an inbound field.
- **p99 first-token climbs after a change that "only added typing".** A `TypeAdapter` was constructed inside the request handler; each construction compiles a new validator and serializer. Hoist it to module scope. The same instinct applies per token: `Token(text=...)` validates a string we just built, thousands of times per answer — use `model_construct`.
- **Idempotency keys change on every deploy and legitimate replays re-execute.** The fingerprint was `sha256(snapshot.model_dump_json())`, so one new optional field on `ConfigSnapshot` changed every key in flight. Fingerprints are built from an explicit, ordered projection of the components `kb-internal-api-contracts` names for that operation — never from a whole-model dump. `SecretStr` masking hides the same bug pointing the other way: a key rotation leaves that dump byte-identical, so the fingerprint would ignore it by accident rather than by decision.
- **A 2023 snippet emits a deprecation warning on every model build.** `class Config:` is gone in favour of `model_config = ConfigDict(...)`, `@validator`/`@root_validator` in favour of `@field_validator` (modes `before`/`after`/`wrap`/`plain`, `@classmethod` required) and `@model_validator` (modes `before`/`after`/`wrap`), `.dict()`/`.parse_obj()` in favour of `model_dump()`/`model_validate()`, and — newer than most examples on the web — `populate_by_name` in favour of `validate_by_name` + `validate_by_alias` as of 2.11. `protected_namespaces` narrowed to `('model_validate', 'model_dump')` in 2.10, so this project's many `model_id` / `model_version` fields no longer warn.
- **A subclass leaks a field the base contract never declared — or stops leaking one you were counting on.** V2 serializes by the *declared* annotation, so a `ProviderConnection` subclass carrying extra credential fields is silently trimmed. 2.13 adds opt-in `polymorphic_serialization`; leave it off on any model that can hold a secret, and never enable it to "fix" a missing field — declare the field instead.

## Official docs

- [Strict mode](https://pydantic.dev/docs/validation/latest/concepts/strict_mode/) and the [conversion table](https://pydantic.dev/docs/validation/latest/concepts/conversion_table/) — the five ways to enable strict, and exactly which coercions survive it in Python vs JSON input.
- [Unions](https://pydantic.dev/docs/validation/latest/concepts/unions/) — `Field(discriminator=)`, callable `Discriminator`/`Tag`, smart-mode error behaviour.
- [Model config](https://pydantic.dev/docs/validation/latest/api/pydantic/config/) — every `ConfigDict` key and its default, with the version each was added or renamed.
- [Fields](https://pydantic.dev/docs/validation/latest/concepts/fields/), [Validators](https://pydantic.dev/docs/validation/latest/concepts/validators/), [Serialization](https://pydantic.dev/docs/validation/latest/concepts/serialization/), [Secret types](https://pydantic.dev/docs/validation/latest/api/pydantic/types/) — constraint arguments, validator modes, `model_dump` options, `computed_field`, and `SecretStr` masking on `repr`/`model_dump`/`model_dump_json`.
- [Validation errors](https://pydantic.dev/docs/validation/latest/errors/validation_errors/), [`ValidationError` API](https://pydantic.dev/docs/validation/latest/api/pydantic-core/pydantic_core/), [Performance](https://pydantic.dev/docs/validation/latest/concepts/performance/) — the `type` identifiers, the `include_input`/`include_url`/`include_context` arguments, and `TypeAdapter` reuse / `model_validate_json` / `FailFast`.

## Definition of done

- [ ] Every model reachable from an internal request body inherits a base carrying `ConfigDict(strict=True, extra="forbid", frozen=True)`; a test asserts an unknown key returns `extra_forbidden` and that `{"dense_top_k": "8"}` returns `int_type`.
- [ ] `extra="ignore"` appears only on raw-provider-response models and broker/Valkey payload models, each with a one-line comment naming which exception it is.
- [ ] No `datetime`, `UUID`, or `Decimal` field on a strict inbound model; ids are the constrained ULID `str`; a test drives the payload through an HTTP client, not `model_validate_json`.
- [ ] Every credential field is `SecretStr`; `grep -rn "get_secret_value" services/ai-service/` returns only adapter call sites; a test asserts no Celery task signature accepts a model containing one.
- [ ] The `RequestValidationError` handler (`fastapi-service`) emits `type`/`loc`/`msg` only; a fixture with a known API key and a known question asserts neither appears in the 422 body or in any log line.
- [ ] Every wire-crossing union is `Annotated[..., Field(discriminator=...)]`; a test asserts a bad tag yields exactly one `union_tag_invalid`.
- [ ] Every `TypeAdapter` is module-level (`grep` for `TypeAdapter(` inside function bodies returns nothing); per-token event objects use `model_construct`.
- [ ] The idempotency fingerprint is an explicit projection, not a model dump; a test adds an unused optional field to `ConfigSnapshot` and asserts the key is unchanged.
- [ ] Any shape change is checked against the versioning table, the regenerated OpenAPI document in `packages/contracts/` is committed, and a removal or rename ships as three deploys or as `/internal/v2`.

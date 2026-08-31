"""The internal chat seam's models — the inbound request and the outbound stream union.

Spec: ``docs/06-architecture.md`` §11.2, §11.4, ``docs/11-data-model.md`` §16.2,
``docs/12-api-areas.md`` §17.5. Conventions: `pydantic-contracts` (shape) and
`kb-internal-api-contracts` (the wire). This module owns neither the field *names* on the
outbound frames — those are `kb-internal-api-contracts`' and are mirrored field for field in
``packages/contracts/src/sse/events.ts`` — nor the transport, which is `fastapi-service`'s.

═══ THE THREE SETTINGS ON ``Inbound``, AND WHY EACH IS THERE ═══════════════════════════════

``strict`` — ``"8"`` must not become ``8`` and ``1`` must not become ``True``.
``X-KB-Config-Version`` claims both planes hold the same snapshot; a value that changed shape
during parsing makes that claim false without moving the version.

``forbid`` — a field Laravel renames must ``422`` here rather than evaporate. Pydantic's
default is ``extra="ignore"``, under which a renamed ``top_k`` leaves this service running its
own default while ``config_version`` still asserts the two sides agree.

``frozen`` — a request is evidence, not scratch space. It also makes a model hashable, but only
one whose fields are all hashable: :class:`ConfigSnapshot` is hashable (and is the correct
per-request cache key), :class:`ChatExecuteRequest` is **not**, because its credential map is a
``dict``. Nothing may hash the request anyway — hashing it would pull the credentials in, which
is the exact mistake :func:`snapshot_hash` exists to prevent.

Every container field that crosses the wire as a JSON array is a ``tuple`` carrying
``Field(strict=False)``, and that pairing is not decoration. FastAPI parses the body itself and
validates a Python ``dict``, so under ``strict=True`` a bare ``tuple[...]`` field rejects the
JSON array with ``tuple_type`` on **every** real request — while ``model_validate_json`` on the
same bytes succeeds, so a unit test written that way stays green. That is `pydantic-contracts`'
"strict mode is *looser* from JSON than from Python" gotcha, and
``app/providers/contract.py::ModelCapabilities.supported`` already carries the same exemption
for the same reason. The exemption is scoped to the container, never taken model-wide: taking
it on the model would also admit ``"8192"`` as an ``int``.

═══ THE CREDENTIAL, AND THE TWO PLACES IT IS NOT ═══════════════════════════════════════════

``provider_credentials`` is a **sibling of** ``config`` and never a member of it (ADR-011;
finding F12/G7 made it a map). Inside the snapshot it would be hashed into
``configuration_version`` — so every rotation would invalidate every cached answer and stop
every replayed job reproducing byte-identically — *and* persisted by every path that stores a
snapshot: the playground record, ``retrieval_traces``, a queued job body. ``SecretStr`` does not
rescue that: it masks ``model_dump()``, so a hash taken over the containing model digests
``"**********"`` and stops moving when the configuration genuinely changes.

The map is keyed by ``connection_id`` because that is the identity **both planes already
compute** — ``app/providers/embedding_selection.py``'s ``EmbeddingConnection`` and core-api's
``EmbeddingCandidate`` each produce one and each holds no credential. It carries only the
connections **this turn** needs, never every connection the organization owns.

**A surface whose ``connection_id`` is absent from the map is a refusal, not a fallback to the
chat key.** :func:`credential_for` is the only lookup, and it raises ``validation``. Falling
back would send one tenant's key to a vendor they did not choose for that surface and would
embed the question in the wrong vector space.

**Never unwrap the map into a container.** ``{k: v.get_secret_value() for k, v in ...}`` —
written to "make a lookup the adapter can use" — produces a plain ``dict[str, str]`` with no
masking left anywhere in it, and one ``str()`` of that object in an exception message or a span
attribute prints every tenant key. Look the entry up by ``connection_id`` at the call site that
is about to make the request, and unwrap there.

═══ WHERE THIS MODULE DEPARTS FROM ITS OWN SPECIFICATION SKETCH ════════════════════════════

``.claude/skills/pydantic-contracts/references/internal-chat-request.md`` is the sketch this
file is written from. Eight fields here are **not** in that sketch, and every one of them is
forced rather than convenient — a chat turn cannot be executed correctly without it. They are
listed on the fields themselves, each with the rule that forces it; the summary is:

* ``ConfigSnapshot.allowed_version_ids`` — non-negotiable 2. The fourth mandatory Qdrant filter
  term. `kb-tenancy-isolation` says Laravel resolves it and "ships it in the config snapshot",
  and ``SourceService``'s docblock says the same; the sketch simply predates the filter.
* ``ConfigSnapshot.embedding_model_version`` — `bge-m3-embeddings`. The query must be embedded
  in the **same** ``EmbeddingSpace`` the passages were, and the only authority for which that
  was is the source version's own row. A service-level default would name a real collection and
  read someone else's vectors.
* ``ConfigSnapshot.embedding_connection`` and ``rerank_connection`` — ADR-031 and finding F12.
  The credential map is keyed by ``connection_id``, so a surface the body cannot *name* is a
  surface whose credential cannot be looked up, and the fallback that fills that gap is the one
  ADR-011 forbids.
* ``ProviderConnection.caps`` — ``ModelCapabilities``' own docstring: the row "arrives from
  Laravel … inside the configuration snapshot on a chat request". Every adapter's ``validate()``
  takes it, and it is where ``context_window`` comes from.
* ``ConfigSnapshot.fallback_connections`` — §8.7's ordered chain. ``FallbackRouter.stream``
  takes a ``Sequence[ChainLink]``; a body that can name only one connection can express no
  fallback at all, and ``provider.fallback`` would then be an event nothing can emit.
* ``ConfigSnapshot.bot_instructions`` — stage 14's bot-behaviour section. It is configuration,
  so it belongs inside the hashed snapshot: changing a bot's instructions must move
  ``configuration_version`` and invalidate cached answers.
* ``ChatExecuteRequest.message_id`` — ``message.start`` and ``message.complete`` both carry it,
  and it must be the id Laravel writes the ``messages`` row under. Minting one here would give
  the client an id that resolves to no row.
* ``ChatExecuteRequest.history`` — stage 3's window. It is per-turn content and therefore
  deliberately **outside** the snapshot: inside it, ``configuration_version`` would move on
  every turn and stop being a configuration identity at all.

Two fields the sketch **does** specify are kept and then deliberately not used as scope:
``org_id`` and ``bot_id``. `kb-tenancy-isolation` NN6 is that the organization comes from the
authenticated context and never from request input, and ``app/api/internal/v1/embedding.py``
says outright that a body-supplied tenant must not exist. They survive here because the sketch
pins them and because every ``X-KB-*`` header is inside the canonical signing string, so body
and header cannot be forged apart — but :func:`assert_scope_agrees` compares them against the
verified headers and refuses on a disagreement, and nothing downstream reads them.
"""

from __future__ import annotations

import hashlib
from enum import StrEnum
from typing import Annotated, Any, Final, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    SecretStr,
    TypeAdapter,
    ValidationError,
)

from app.core.errors import ErrorClass, KbError, Origin
from app.providers.contract import ModelCapabilities, Ulid

__all__ = [
    "CLIENT_FORWARDED_EVENTS",
    "EVENT_NAMES",
    "INTERNAL_ONLY_EVENTS",
    "STREAM_EVENT",
    "ChatExecuteRequest",
    "Citation",
    "Citations",
    "ConfigSnapshot",
    "Event",
    "FinishReason",
    "Inbound",
    "MessageComplete",
    "MessageStart",
    "ProviderConnection",
    "ProviderFallback",
    "ProviderUsage",
    "ReasoningEffort",
    "RetrievalConfig",
    "RetrievalTraceFrame",
    "Status",
    "StreamError",
    "StreamEvent",
    "Token",
    "Usage",
    "assert_scope_agrees",
    "credential_for",
    "parse_request",
    "sse_data",
]


# ── inbound ───────────────────────────────────────────────────────────────────


class Inbound(BaseModel):
    """Base for everything Laravel sends. See the module docstring for the three settings."""

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)


class ReasoningEffort(StrEnum):
    """Seven levels, and two of them exist only because a vendor ships them.

    ``MINIMAL`` sits between ``NONE`` and ``LOW`` because OpenAI ships it as a distinct level;
    ``XHIGH`` between ``HIGH`` and ``MAX`` because Anthropic does. An enum missing either
    silently rounds a provider's recommended setting to the nearest level we happen to model,
    with no diff and no warning (`kb-provider-adapter-contract`).
    """

    NONE = "none"
    MINIMAL = "minimal"
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    XHIGH = "xhigh"
    MAX = "max"


class ProviderConnection(Inbound):
    """One ``provider_connections`` row plus the ``provider_models`` row it will be used with.

    **There is no ``api_key`` here and none may be added.** ADR-011 puts credentials on
    :class:`ChatExecuteRequest`, outside the snapshot, because :class:`ConfigSnapshot` is hashed
    into ``configuration_version`` and persisted into ``retrieval_traces`` and the playground. A
    ``SecretStr`` inside it would digest as ``'**********'`` — so the hash looks stable for the
    wrong reason — and every rotation would move a version that nothing about the configuration
    actually changed. Moving it back inside is the reflexive tidy-up; this comment is the reason
    it is wrong.
    """

    connection_id: Ulid
    provider: Literal["openai", "anthropic", "deepseek", "nvidia_nim", "openrouter"]
    #: The official vendor id, verbatim. Never a floating alias: an alias reassignment changes
    #: answers, price and capability flags with no diff in the repo and no error anywhere.
    model: str = Field(min_length=1, max_length=256)
    base_url: str | None = None
    #: The ``provider_models`` row for **this** ``(provider, model)``. Not in the sketch, and
    #: forced: every adapter's ``validate()`` takes it, ``can_rerank`` reads it, and
    #: ``context_window`` — which stage 13's budget is computed against — exists nowhere else on
    #: this wire. ``ModelCapabilities``' own docstring says the row arrives here.
    caps: ModelCapabilities
    #: §8.7's per-connection rate-limit fallback switch, **off by default** so a rate limit
    #: stays visible instead of quietly moving spend. It gates only ``provider_rate_limit`` and
    #: can only subtract from what ``fallback_eligible`` already allows.
    fallback_on_rate_limit: bool = False


class RetrievalConfig(Inbound):
    """The tunable core of the pipeline (stages 5-13). Every value is an evaluation-run output.

    `kb-rag-query-contract`'s standing instruction applies to all of them: a changed top-K,
    threshold or window without an evaluation run attached is an unreviewable change.

    The first five are the sketch's. The rest are additive with defaults matching
    ``app/rag/runner.py::RetrievalConfig``, so a body that omits them behaves exactly as the
    runner's own defaults do — which is what makes them safe to add to a shipped shape.
    """

    dense_top_k: int = Field(ge=1, le=200)
    sparse_top_k: int = Field(ge=1, le=200)
    #: Stage 11's candidate depth. **0 disables reranking** — the config-off form of the stage,
    #: which still executes its block and still writes a fragment carrying
    #: ``disabled_by_configuration``. It is never a code path that jumps.
    rerank_top_n: int = Field(ge=0, le=100)
    #: Never inherit the client's own RRF constant. Qdrant defaults ``k`` to **2**, at which the
    #: rank-1 hit of each branch dominates roughly thirty times more sharply than the
    #: literature's 60, and the symptom is hybrid search preferring whatever sparse returned
    #: first on queries where dense was obviously right (`kb-rag-query-contract`).
    fusion_k: int = Field(ge=1, le=100)
    #: ``rerank.retain``. Must not exceed ``rerank_top_n`` when reranking runs — retaining more
    #: candidates than were ever scored produces a short context and no error.
    retain: int = Field(ge=1, le=50)

    #: ``diversity.max_per_document``. The adjacency exemption is what makes it safe to apply.
    max_per_document: int = Field(default=3, ge=1, le=50)
    rewrite_enabled: bool = True
    #: ``context.reserve_output``, subtracted from the window **before** packing. It covers the
    #: answer and everything else the provider counts as output — thinking tokens above all.
    reserve_output: int = Field(default=1024, ge=1, le=100_000)
    history_window_turns: int = Field(default=8, ge=0, le=100)
    #: Stage 1's length cap. Deliberately lower than ``ChatExecuteRequest.query``'s wire
    #: maximum and bounded by it: the wire bound is a transport ceiling, this is the product
    #: rule, and a value above the wire bound would be a cap that can never be reached.
    question_max_chars: int = Field(default=4_000, ge=1, le=32_000)
    citations_enabled: bool = True
    require_at_least_one_citation: bool = True


class ConfigSnapshot(Inbound):
    """docs/06 §11.2 — FastAPI never queries Laravel's tables; this is the whole input.

    **Hashable, and that is load-bearing.** No field here is a ``dict`` or a ``list``, so a
    frozen instance hashes and is the correct per-request cache key. :class:`ChatExecuteRequest`
    is deliberately not hashable; see :func:`snapshot_hash`.

    **No credential is in this object and none may ever be added.** The snapshot exists to be
    persisted and replayed, so a provider key put inside it lands in columns no redaction
    fixture covers.
    """

    #: Mirrors ``X-KB-Config-Version``. A HASH rendered as a decimal integer, not a counter and
    #: not ordered — comparable for equality only (`kb-internal-api-contracts`).
    config_version: int = Field(ge=1)
    #: The immutable retrieval-configuration identity. Every trace row carries it; a trace
    #: without it cannot be replayed and is worthless as a regression baseline (§21.4).
    retrieval_configuration_version: int = Field(ge=1)

    #: The chat connection. Its ``connection_id`` must be a key of ``provider_credentials``.
    connection: ProviderConnection
    #: **Required, and required separately from ``connection``** (ADR-031, finding F12). ADR-031
    #: explicitly permits the embedding connection to be a *different* connection from the chat
    #: one — that is the entire content of the designation — and the credential map is keyed by
    #: ``connection_id``, so a body that cannot name this surface is a body whose embedding
    #: credential cannot be looked up. Sending the same ``connection_id`` twice is the ordinary
    #: single-vendor case and is explicit rather than inferred.
    embedding_connection: ProviderConnection
    #: ``None`` when the organization has no ranking-capable connection, which is a normal
    #: state and not an error: stage 11 records a closed skip reason and stage 12 selects on
    #: branch agreement instead (`bge-reranker`).
    rerank_connection: ProviderConnection | None = None
    #: §8.7's ordered fallback chain, after ``connection``. Empty means no fallback.
    fallback_connections: Annotated[tuple[ProviderConnection, ...], Field(strict=False)] = ()

    retrieval: RetrievalConfig

    #: **Non-negotiable 2's fourth filter term.** Laravel resolves it from
    #: ``bot_source_assignments`` joined to ``knowledge_sources`` and ``source_versions``,
    #: restricted to this organization and to versions active *right now*, and ships it here —
    #: which is what makes a disable take effect at once rather than after millions of payload
    #: rewrites, and what stops a retired version answering beside the version that replaced it.
    #:
    #: ``min_length=1`` and not an empty tuple. An empty scope is a valid *outcome* — a bot with
    #: no assigned source retrieves nothing and refuses — but it is expressed by not querying,
    #: never by a filter: ``Filter(must=[])`` is a confirmed match-all and ``MatchAny(any=[])``
    #: matches nothing without saying so. ``tenant_filter`` raises ``EmptyScopeError`` on it, so
    #: this bound turns a request that could not build a filter into a ``422`` naming the field
    #: instead of a 500 from inside stage 6.
    allowed_version_ids: Annotated[
        tuple[Ulid, ...], Field(strict=False, min_length=1, max_length=10_000)
    ]
    #: ``emb/v1:provider:model:dNNNN:digest`` — the identity the source versions were indexed
    #: under, which derives the Qdrant collection name. It comes from the versions' own rows and
    #: never from this service's environment: a service-level setting lets the indexer and the
    #: reader disagree, and the disagreement produces zero candidates or wrong ones, never an
    #: error (`bge-m3-embeddings`, ADR-035).
    embedding_model_version: str = Field(min_length=1, max_length=512)

    #: Stage 14's BOT INSTRUCTIONS section. Inside the snapshot because it is configuration:
    #: changing it must move ``configuration_version`` and invalidate cached answers. It is
    #: rendered into its own labelled section and is never concatenated with retrieved text.
    bot_instructions: str = Field(default="", max_length=32_000)

    max_output_tokens: int = Field(ge=1, le=200_000)
    #: ``int`` -> ``float`` stays legal in strict mode, so ``temperature: 1`` arrives as ``1.0``
    #: by design rather than by a leak in the policy.
    temperature: float | None = None
    reasoning_effort: ReasoningEffort = ReasoningEffort.NONE
    stream: bool = True


class ChatExecuteRequest(Inbound):
    """One chat turn, as the control plane resolved it. **Deliberately unhashable.**

    A ``dict`` field costs ``frozen=True`` its hashability, and that is the correct outcome
    rather than something to shim around: hashing this model would pull the credentials in,
    which is exactly what :func:`snapshot_hash` exists to prevent. The hashable object is
    :class:`ConfigSnapshot`.
    """

    #: **Present, checked against the verified headers, and then unused.** Scope comes from
    #: ``X-KB-Org-Id`` / ``X-KB-Bot-Id``, which are inside the canonical signing string; a
    #: tenant a caller can set in a body is not a scope, it is a parameter
    #: (`kb-tenancy-isolation` NN6). :func:`assert_scope_agrees` is the whole of their use.
    org_id: Ulid
    bot_id: Ulid

    conversation_id: Ulid
    #: The assistant ``messages`` row this turn will be persisted under, minted by Laravel
    #: before the call. ``message.start`` and ``message.complete`` both carry it; minting one
    #: here would hand the client an id that resolves to no row.
    message_id: Ulid
    #: Client-minted, on the *user's* message. It is what makes a resend idempotent for the
    #: client; it is not the assistant message id and the two are never interchangeable.
    client_message_id: Ulid

    actor_type: Literal["user", "anonymous_session", "scheduler", "system"]

    #: The **internal** field name, scoped to this request. The *public* body a client posts to
    #: Laravel is ``{client_message_id, content}`` — `kb-internal-api-contracts` owns it, and
    #: Laravel maps ``content`` onto ``query`` when it builds the snapshot. Neither name is an
    #: alias for the other; a client that posts ``query`` to the public API gets a ``422``.
    query: str = Field(min_length=1, max_length=32_000)
    #: Stage 3's conversation window, oldest first, already rendered as turns. Per-turn content
    #: and therefore outside the snapshot: inside it, ``configuration_version`` would move on
    #: every turn and stop being a configuration identity.
    history: Annotated[tuple[str, ...], Field(strict=False, max_length=200)] = ()

    #: **Absolute** epoch milliseconds, per `kb-internal-api-contracts` — not a duration and not
    #: seconds. ``int`` and not ``datetime``: strict mode is looser from JSON than from Python,
    #: so a ``datetime`` field passes ``model_validate_json`` in a unit test and ``422``s with
    #: ``datetime_type`` against the running service, which validates an already-parsed dict.
    deadline_epoch_ms: int = Field(ge=0)

    config: ConfigSnapshot

    #: ADR-011: a sibling of ``config``, never inside it. A MAP and not one field (finding F12,
    #: ruled 2026-08-12): one turn can need three keys — chat, embedding (ADR-031 lets that be a
    #: different connection), rerank. ``Ulid`` keys and not ``str``: a ``dict`` has no
    #: ``extra="forbid"``, so the key type is the only thing keeping it from being a free-form
    #: bag, and a non-ULID key is a ``422`` rather than an ignored entry.
    #:
    #: ``repr``/``str``/``model_dump()``/``model_dump_json()`` all render ``'**********'`` per
    #: value, nested in the map. ``get_secret_value()`` is called on ONE entry, at the call site
    #: about to make the request — see the module docstring on why a comprehension over
    #: ``.items()`` destroys that guarantee in a single line.
    provider_credentials: dict[Ulid, SecretStr]


# ── the request-side helpers ──────────────────────────────────────────────────


def snapshot_hash(req: ChatExecuteRequest) -> str:
    """Over ``config`` only — never over the request model.

    Hashing the request would pull the credentials in, and ``SecretStr`` would hide that it
    had: ``model_dump_json()`` writes ``"**********"``, so the digest looks stable for the wrong
    reason and stops moving when the configuration genuinely changes. Masking is a display
    property, not a hashing strategy.
    """
    return hashlib.sha256(req.config.model_dump_json().encode()).hexdigest()


def credential_for(req: ChatExecuteRequest, connection_id: str, *, surface: str) -> SecretStr:
    """The one lookup into the credential map. **Absent is a refusal, never a fallback.**

    Reusing the chat credential for an embedding or rerank surface would send a tenant's key to
    a vendor they did not choose for that surface and would embed the question in a vector space
    the corpus was not indexed in — neither of which raises anywhere downstream. Fail closed.

    The returned value is still a ``SecretStr``. Unwrap it with ``get_secret_value()`` at the
    call site making the request, on this one entry, and never into a container.
    """
    secret = req.provider_credentials.get(connection_id)
    if secret is None:
        raise KbError(
            ErrorClass.VALIDATION,
            f"the {surface} connection {connection_id} has no entry in provider_credentials. "
            "A surface whose connection_id is absent from the map is refused rather than "
            "falling back to the chat credential (ADR-011): the fallback sends one tenant's "
            "key to a vendor they did not choose for that surface",
            origin=Origin.SELF,
        )
    return secret


def assert_scope_agrees(req: ChatExecuteRequest, *, org_id: str, bot_id: str | None) -> None:
    """Refuse a body whose tenant fields disagree with the **verified** headers.

    This never *supplies* scope — ``org_id`` and ``bot_id`` here are the ones
    ``app/api/deps.py::request_context`` read from the signed headers, and they are what the
    tenant filter is built from. The body's copies are checked and discarded.

    It is not a security control on its own: every ``X-KB-*`` header is inside the canonical
    signing string, so the two cannot be forged apart. It is a control-plane bug detector — a
    Laravel that resolved a snapshot for one bot and signed the request for another would
    otherwise retrieve correctly for the header's bot while every persisted row said the body's.
    """
    if req.org_id != org_id or req.bot_id != bot_id:
        raise KbError(
            ErrorClass.VALIDATION,
            "the body's org_id/bot_id disagree with the verified X-KB-Org-Id/X-KB-Bot-Id "
            "headers. Scope is taken from the signed headers and the body's copies are only "
            "ever checked against them; a disagreement means the caller resolved a snapshot "
            "for one tenant and signed the request for another",
            origin=Origin.SELF,
        )


def parse_request(raw: bytes) -> ChatExecuteRequest:
    """Validate a raw body, with the rejected input redacted out of the error.

    The HTTP path does not use this — FastAPI binds the model itself and
    ``app/main.py::_handle_validation_error`` performs the same redaction on
    ``RequestValidationError``. This exists for the callers that have no ``Request``: an
    evaluation replay and a queued re-execution, both of which read a stored body.

    ``include_input=False`` is not optional. The rejected input is routinely the configuration
    snapshot and the tenant's question, and ``errors()`` renders it verbatim by default
    (`kb-security-baseline`).
    """
    try:
        return ChatExecuteRequest.model_validate_json(raw)
    except ValidationError as exc:
        detail = exc.errors(include_input=False, include_context=False, include_url=False)
        raise KbError(
            ErrorClass.VALIDATION,
            f"the internal chat request failed validation: "
            f"{[(e['type'], e['loc']) for e in detail]}",
            origin=Origin.SELF,
        ) from None


# ── the outbound stream ───────────────────────────────────────────────────────
#
# Every field below reaches a browser, a widget and a phone unaltered for the six forwarded
# names: Laravel's relay forwards approved frames verbatim, and `Event` sets extra="forbid", so
# what this file emits is exactly what three clients parse. The names are
# `kb-internal-api-contracts`' and are mirrored in `packages/contracts/src/sse/events.ts` — it
# owns the wire, this file owns the shape, and drift here is a client outage.


class Event(BaseModel):
    """Base for every emitted frame.

    ``frozen`` because a frame is a record of something that already happened; ``forbid``
    because a key added here without a matching client type renders as ``undefined`` rather
    than as a parse error, which is a defect nothing catches.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    @property
    def wire_name(self) -> str:
        """The value of this frame's ``event:`` line, read off the class.

        Every subclass declares ``event`` as a ``Literal`` with a default, so the name is a
        class constant and this is a dict lookup. The alternative at the emit site —
        ``model_dump()["event"]`` — builds a whole dict to read one constant, once per token.
        """
        return str(type(self).model_fields["event"].default)


class MessageStart(Event):
    event: Literal["message.start"] = "message.start"
    message_id: Ulid
    conversation_id: Ulid
    #: RFC 3339 UTC with a ``Z``. ``str`` and not ``datetime``, for the same reason ids are
    #: ``str``: format once, here, so no consumer has to agree with a serializer.
    created_at: str


class Status(Event):
    """What the UI shows during the seconds before the first token.

    ``reranking`` is emitted only when stage 11 actually calls a provider. A bot whose provider
    cannot rank never sees it, which is correct and is the one place the degraded mode is
    visible to an end user at all.
    """

    event: Literal["status"] = "status"
    stage: Literal["retrieving", "reranking", "generating"]


class Citation(BaseModel):
    """One footnote, assigned from retrieved evidence **before** generation.

    Field for field ``kb-internal-api-contracts``' ``citations`` frame. ``index`` is what the
    answer's markers count from; a client reading ``citation.index`` off a model that spells it
    ``n`` gets ``undefined`` and renders every marker blank with no error anywhere.

    There is deliberately no ``locator`` and no ``excerpt``: the wire carries neither, and the
    transcript's ``location_metadata`` and ``excerpt`` are denormalized onto Laravel's
    ``citations`` row at persist time from its own ``chunks`` table.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    #: 1-based, in packed order — the order the model reads the evidence in, which is not
    #: reranked order (the packer places rank 1 first and rank 2 last).
    index: int
    source_id: Ulid
    source_version_id: Ulid
    chunk_id: Ulid
    #: Display title. See ``app/api/internal/v1/chat.py::_citation_title`` for what actually
    #: produces it and for the gap it is working around — the Qdrant payload carries no source
    #: title, so this is the chunk's heading path.
    title: str
    #: Crawled sources only; explicitly ``null`` for uploads. Never omitted.
    url: str | None = None
    #: The rerank score, and **the scale is provider-and-model dependent** (ADR-030) — never
    #: assume a sigmoid. ``None`` when stage 11 was skipped, which is a real state and not a
    #: zero: `packages/contracts` types this ``number`` today, and that disagreement is
    #: reported rather than papered over with a fabricated float.
    score: float | None = None


class Citations(Event):
    """**Emitted before the first token, on every path including a refusal** (non-negotiable 8).

    A refusal emits it with an empty list rather than omitting it: a client that only sees this
    frame on the answering path has no way to clear the previous turn's sources panel.
    """

    event: Literal["citations"] = "citations"
    citations: list[Citation]


class Token(Event):
    """Text and nothing else. The only high-frequency event; every extra key is paid per token."""

    event: Literal["token"] = "token"
    text: str


class Usage(BaseModel):
    """Client-facing token counts, nested on ``message.complete`` only.

    Deliberately **not** an ``Event`` subclass. A ``Usage`` that inherited ``Event`` would put
    ``event: "provider.usage"`` *inside* a terminal frame, shipping an internal event name to
    every widget.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    #: The whole input, i.e. ``Usage.total_input_tokens`` — uncached input plus cache reads plus
    #: cache writes. Reading the provider's ``input_tokens`` alone reports a 200k-token cached
    #: document with a 50-token question as 50 input tokens on Anthropic.
    prompt_tokens: int
    completion_tokens: int


class ProviderUsage(Event):
    """**Internal only.** One frame per provider attempt that actually made a request.

    Laravel writes the ``provider_calls`` row from this and drops the frame, so the cache split
    and the cost data stay server-side. This service writes no conversation-side row itself:
    ``provider_calls`` is Laravel-owned and is not in ``app/db/writes.py::ALLOWED_TABLES``.

    **Wider than the sketch, and that is what makes it usable.** The sketch carries three token
    fields, which cannot attribute a cost to a connection or a model — so a turn that fell back
    would produce two indistinguishable rows. The attribution keys here are exactly
    ``ProviderAttempt``'s, minus the breaker key (an unbounded label) and minus estimated cost
    (which needs a pricing table this plane does not have).
    """

    event: Literal["provider.usage"] = "provider.usage"
    #: 1-based across the whole turn, so a gap in the sequence is a dropped record.
    ordinal: int
    connection_id: str
    provider: str
    model: str
    outcome: str
    error_class: str | None = None
    stop_reason: str | None = None
    provider_request_id: str | None = None

    #: Uncached input only, as the vendor reported it.
    input_tokens: int
    output_tokens: int
    #: The sketch's rollup, ``cache_read + cache_write``. The two disjoint buckets ride beside
    #: it because they are **not** interchangeable — Anthropic bills a cache write at a premium
    #: and reports the three as siblings, while OpenAI reports cached tokens as a subset of
    #: input — and a consumer that only has the sum cannot reconstruct either.
    cached_tokens: int = 0
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    #: Billed INSIDE ``output_tokens``; reported separately for attribution only. Adding it to
    #: the output count double-bills every reasoning turn.
    reasoning_tokens: int = 0

    latency_ms: int = 0
    first_token_ms: int | None = None


class ProviderFallback(Event):
    """**Internal only.** A different connection answered than the one the bot names first.

    Emitted from a ``ProviderAttempt`` record, ahead of the replacement model's first token,
    because ``ChatResult`` deliberately cannot say which connection produced it: read only the
    terminal event and a fallback answer is indistinguishable from a primary one.

    A same-connection **retry** is not a fallback and never produces this frame, or
    ``kb_chat_fallbacks_total`` reports a model change that never happened.
    """

    event: Literal["provider.fallback"] = "provider.fallback"
    ordinal: int
    from_connection_id: str
    to_connection_id: str
    #: The vendor and model that are about to be tried, not the ones that failed.
    provider: str
    model: str
    #: The ``ErrorClass`` that sent us here, as a string. Never an HTTP status.
    trigger: str


class RetrievalTraceFrame(Event):
    """**Internal only.** The whole ``retrieval.trace`` payload, for the admin playground.

    Returned to the playground only when ``X-KB-Actor-Type=user`` and the actor holds the
    diagnostics permission — that gate is Laravel's, not ours; this frame is always emitted and
    Laravel's allow-list decides who sees it.

    ``trace`` is an open object rather than a typed model **on purpose**. Its shape is
    ``app/rag/runner.py::RetrievalTrace``, which is the pipeline's own record and changes with
    the pipeline; a second transcription of it here would be a second thing to keep in step, and
    the consumer is one admin panel rather than three clients. It is also the reason this frame
    is not forwarded: a typed public shape would have to be pinned, and this one must not be.

    **This service does not write ``retrieval_traces``.** That name came off ``ALLOWED_TABLES``
    under ADR-033 property 2 — the public API reads it, so a data-plane write would land beside
    Laravel's own writer with no policy and no audit row. Laravel's stream finalizer persists it
    in the same transaction as the message row.
    """

    event: Literal["retrieval.trace"] = "retrieval.trace"
    trace: dict[str, Any]


FinishReason = Literal["stop", "length", "cancelled", "insufficient_evidence", "error"]


class MessageComplete(Event):
    """One of the two terminal frames. ``insufficient_evidence`` is a **correct** outcome.

    A refusal is not an error and its metric is not an error rate: the bot states the answer is
    not in the sources, cites nothing, and no provider call was made.
    """

    event: Literal["message.complete"] = "message.complete"
    message_id: Ulid
    finish_reason: FinishReason
    usage: Usage | None = None


class StreamError(Event):
    """The other terminal frame.

    ``error_class`` is one of the eighteen in `kb-error-taxonomy` and crosses the wire verbatim;
    Laravel relays it and never re-derives one from a status. ``stream_lost`` is **not** a wire
    class — it is a client-local sentinel for the case where no terminal frame arrived at all.
    """

    event: Literal["error"] = "error"
    error_class: str
    message: str
    retryable: bool


StreamEvent = Annotated[
    MessageStart
    | Status
    | Citations
    | Token
    | ProviderUsage
    | ProviderFallback
    | RetrievalTraceFrame
    | MessageComplete
    | StreamError,
    Field(discriminator="event"),
]

#: Module level: each ``TypeAdapter`` construction compiles a fresh Rust validator, so building
#: one inside a handler is p99 first-token latency spent on nothing.
STREAM_EVENT: Final[TypeAdapter[Any]] = TypeAdapter(StreamEvent)

#: The nine names, in the order a well-formed stream tends to produce them. They are the
#: contract; which of them reach a client is Laravel's allow-list and not this service's
#: decision, which is exactly why all nine are emitted here.
EVENT_NAMES: Final[tuple[str, ...]] = (
    "message.start",
    "status",
    "citations",
    "token",
    "provider.usage",
    "provider.fallback",
    "retrieval.trace",
    "message.complete",
    "error",
)

#: The six Laravel forwards. Mirrored by ``CLIENT_EVENT_NAMES`` in
#: ``packages/contracts/src/sse/events.ts``; a divergence is a client outage.
CLIENT_FORWARDED_EVENTS: Final[tuple[str, ...]] = (
    "message.start",
    "status",
    "citations",
    "token",
    "message.complete",
    "error",
)

#: Laravel consumes and drops these. Leaking any of them to a widget exposes internal topology
#: and cost data.
INTERNAL_ONLY_EVENTS: Final[tuple[str, ...]] = (
    "provider.usage",
    "provider.fallback",
    "retrieval.trace",
)


def sse_data(event: Event) -> str:
    """The ``data:`` payload for one frame: the model's JSON **without the discriminator**.

    The name is already on the frame's own ``event:`` line, and every ``*Data`` interface in
    ``packages/contracts/src/sse/events.ts`` — the type three clients parse — has no ``event``
    key. Emitting it inside ``data`` too would be one extra key per token on the only
    high-frequency frame, and would make the published wire block and the shipped client types
    disagree with what the server actually sends.

    The field is kept on the *model* because it is the union's discriminator: a consumer
    rebuilds ``{**json.loads(data), "event": frame.event}`` from the SSE frame and validates
    that through :data:`STREAM_EVENT`, which is exactly what the contract test does.
    """
    return event.model_dump_json(exclude={"event"})


# ── import-time invariants ────────────────────────────────────────────────────
#
# Checked at import rather than in a test: each of these is invisible in every stream the
# process produces, and a process holding a broken one produces plausible frames.

assert len(EVENT_NAMES) == len(set(EVENT_NAMES)) == 9
assert set(CLIENT_FORWARDED_EVENTS) | set(INTERNAL_ONLY_EVENTS) == set(EVENT_NAMES)
assert not set(CLIENT_FORWARDED_EVENTS) & set(INTERNAL_ONLY_EVENTS)

# The union covers all nine, and every member's discriminator is its wire name. A member left
# out of the union does not fail at import — it fails at runtime with `union_tag_invalid` the
# first time a real answer streams.
assert {
    member.model_fields["event"].default
    for member in (
        MessageStart,
        Status,
        Citations,
        Token,
        ProviderUsage,
        ProviderFallback,
        RetrievalTraceFrame,
        MessageComplete,
        StreamError,
    )
} == set(EVENT_NAMES)

# ConfigSnapshot must stay hashable: it is the per-request cache key, and a `dict` or `list`
# field added to it would remove that silently. The request must stay UNhashable, because
# hashing it would digest the credential map.
assert ConfigSnapshot.model_config.get("frozen") is True
assert not any(
    getattr(field.annotation, "__origin__", None) in (dict, list)
    for field in ConfigSnapshot.model_fields.values()
)

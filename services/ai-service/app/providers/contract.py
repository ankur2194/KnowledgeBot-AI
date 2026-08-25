"""The one internal shape five genuinely different vendor APIs are flattened into.

Transcribed from `kb-provider-adapter-contract` (docs/02-functional-auth-tenancy-bots.md
§8.4–8.7, docs/11-data-model.md §16.2). Every type here is real even though every adapter
is a skeleton: this module is the reason the RAG pipeline contains no
``if provider == "anthropic"`` branch, and it only works if all five adapters were written
against the same definitions from the first line.

Three rules this file exists to make unbreakable:

* **A vendor feature the contract cannot express becomes a capability flag or a
  ``CapabilityWarning`` — never a new response field.** A sixth field that only OpenRouter
  populates is a conditional in every consumer of the stream, which is the exact cost
  ADR-001 already accepted once at the adapter boundary and must not pay again upward.
* **``Usage`` buckets are DISJOINT.** The three vendors here disagree about what "input
  tokens" means — OpenAI's cached count is a subset of its input count, Anthropic's is a
  sibling of it, DeepSeek's two halves partition it. A shared normalization helper written
  without that in mind over- or under-bills by exactly the cached-token amount, silently,
  in whichever direction the author guessed. Each adapter normalizes for itself; billing
  reads ``total_input_tokens`` and never ``input_tokens``.
* **``stop_reason`` never lies about truncation.** A length cap is ``MAX_OUTPUT``, never
  ``COMPLETE``. A truncated answer presented as complete is the worst failure this layer
  can cause: the user reads a confident half-sentence and nothing errors anywhere.

Two shapes here deliberately differ from the sketch in the skill file, because the sketch
contradicts a rule that outranks it:

* Identifiers are ULID ``str``, not ``UUID``. Every id on this platform is a Crockford
  base32 ULID and ``uuid.UUID("01J8...")`` raises, so a ``UUID``-typed field rejects every
  real request (`pydantic-contracts`, which names this contradiction and says to reconcile
  it here).
* The decrypted credential is a **parameter of ``stream()``**, not a field of
  ``ChatRequest``. See ``ProviderAdapter.stream``.

Since the no-local-inference decision (ADR-030) this module carries **three** task families,
not one: chat, embedding and reranking. They share the credential path, the capability
mechanism, the usage buckets and the error taxonomy, and they share nothing else. Each has its
own request/result pair and its own Protocol, because a Protocol whose methods raise
``NotImplementedError`` on most implementations is a lie that type-checks — it turns a
capability question into an exception-handling problem at every call site, and the site that
forgets the ``except`` is the ingestion path, where a failure costs a re-parse.

**Which vendors can embed and which can rerank is data, in ``capabilities.py``, with a source
per cell.** This paragraph used to assert "only two of the five vendors can embed and only two
can rerank" and name neither pair, while ``Capability.EMBEDDING`` forty lines below named
three; ``docs/23`` rows 157–158 record that no adapter had ever called any of those endpoints,
so neither count was ever evidence. Every cell has since been read off the vendor's own current
documentation and dated: **three embed — OpenAI, NVIDIA NIM, OpenRouter — and two rerank —
NVIDIA NIM, OpenRouter.** Anthropic and DeepSeek can do neither, which is a permanent fact
about those two vendors and the whole of finding C1. No count is repeated here or anywhere
else; the pair is the claim and ``capabilities.py`` is the only place it lives. Two gates
enforce it and are asserted against each other by
``tests/unit/test_provider_capability_matrix.py``:

* the **absence of the method** — ``AnthropicAdapter`` has no ``embed``, so
  ``_assert_embeds(AnthropicAdapter())`` does not compile. The strongest statement mypy can
  make, and the one a boolean cannot: a flag can be set to ``True`` by an edit that changes no
  behaviour.
* the **matrix**, because absence of a method is not a question a caller can *ask*. Asking it
  means ``hasattr``, which is the same runtime branch in a different costume and can carry
  neither a source nor a score scale.

One import crosses outward from this package, and it is deliberate: ``EmbeddingSpace`` comes
from ``app/retrieval/collection.py``. That type derives the Qdrant collection name from
provider, model, width, distance and schema version, and ``app/ingestion/embedding/embedder.py``
already states the rule — "imported and never restated, because a second definition of it would
let the indexer and the reader compute different names from the same facts". This module briefly
carried such a second definition, missing ``distance`` and ``schema_version`` and therefore
unable to name a collection at all. ``normalized``, which only the provider layer observes, now
sits on ``EmbeddingResult`` beside the space rather than inside it — see the field's note.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from enum import StrEnum
from typing import Annotated, Any, Literal, Protocol

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    SecretStr,
    StringConstraints,
    field_serializer,
)

from app.retrieval.collection import EmbeddingSpace

# ``EmbeddingSpace`` is imported, not re-exported. It belongs to ``app/retrieval/collection.py``
# and appears here only as the annotation of ``EmbeddingResult.space``; re-exporting it would
# offer a second import path for one type, which is how "imported and never restated" quietly
# becomes two definitions again.
__all__ = [
    "Capability",
    "CapabilityWarning",
    "ChatRequest",
    "ChatResult",
    "ContextBlock",
    "Delta",
    "Diagnostics",
    "EmbeddingAdapter",
    "EmbeddingInputType",
    "EmbeddingRequest",
    "EmbeddingResult",
    "ImageInput",
    "Message",
    "ModelCapabilities",
    "ProviderAdapter",
    "ReasoningOption",
    "RerankAdapter",
    "RerankRequest",
    "RerankResult",
    "RerankScale",
    "StopReason",
    "StreamEvent",
    "Timeouts",
    "ToolDef",
    "Ulid",
    "Usage",
]

#: ULIDs, not UUIDs (`kb-internal-api-contracts`, `kb-observability-conventions`).
# Both cases: Laravel lowercases model keys via `HasUlids` and upper-cases `Str::ulid()` ids,
# and one request body carries both. See `app/ingestion/indexing/upserter.py` for the full
# note and `docs/22` § R6 for how it was found.
Ulid = Annotated[str, StringConstraints(pattern=r"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$")]


# ── capabilities ──────────────────────────────────────────────────────────────


class Capability(StrEnum):
    """What a *model* can do, as recorded on ``provider_models.capability_flags``.

    Never inferred from the model id string. ``"gpt-5.6-luna"`` and ``"deepseek-v4-pro"``
    tell you nothing about whether that deployment accepts sampling parameters, and both
    vendors have already retired ids that code was parsing. The id decays; the row does not.

    Adding a member is a THREE-part change — this enum, the adapter's ``validate()``
    mapping, and the ``provider_models.capability_flags`` backfill. Ship two of the three
    and the flag exists but no model carries it, so the feature is dark and nothing errors.

    **Rows are task-exclusive.** A row carrying ``EMBEDDING`` never carries ``TEXT``,
    ``TOOL_USE`` or ``REASONING``, and the reverse holds too: ``openai/text-embedding-3-large``
    and ``gpt-5.6-sol`` are different products reached through different endpoints, and a row
    that claims both describes a model that does not exist. The chat flags below are
    meaningless on an embedding or rerank row rather than merely false, which is why the
    check belongs in the connection save and in a test, not in a runtime branch.
    """

    TEXT = "text"
    IMAGE_INPUT = "image_input"
    TOOL_USE = "tool_use"
    #: Native ``json_schema`` with enforcement. NOT the same thing as JSON_MODE, and the
    #: schema subsets are mutually incompatible across vendors — a schema validated against
    #: OpenAI strict mode is a 400 on Anthropic.
    STRUCTURED_OUTPUT = "structured_output"
    #: Free-form valid JSON only, no schema enforcement. DeepSeek's ceiling.
    JSON_MODE = "json_mode"
    REASONING = "reasoning"
    #: Thinking text is actually RETURNED, not merely billed. Separate from REASONING
    #: because Anthropic's default display returns empty thinking blocks and charges for
    #: the full trace; a pane gated on REASONING alone renders blank forever.
    REASONING_TRACE = "reasoning_trace"
    #: temperature/top_p accepted at all. Recent reasoning models 400 on them rather than
    #: ignoring them, so this is a hard gate, not a nicety.
    SAMPLING = "sampling"
    PROMPT_CACHING = "prompt_caching"
    #: Usage arrives on the stream rather than only on a follow-up call.
    STREAM_USAGE = "stream_usage"
    #: Input tokens are known BEFORE the stream ends, so an aborted turn still bills
    #: exactly. Anthropic only, via ``message_start``.
    EARLY_INPUT_USAGE = "early_input_usage"

    # ── the non-chat families (ADR-030; no local inference) ───────────────────
    #: The row serves ``embed()``.
    #:
    #: **This flag is one of two gates and is never sufficient on its own.** It says the model
    #: serves the surface; ``capabilities.PROVIDER_TASKS`` says whether the vendor publishes
    #: the endpoint at all, and ``capabilities.can_embed`` is the AND. A row carrying this flag
    #: on a vendor with no embedding endpoint is a configuration defect —
    #: ``capabilities.assert_row_coherent`` rejects it — on the embedding-readiness walk,
    #: which is the only thing that calls it, and NOT on the save as this line used to claim
    #: (finding J1); for this flag the distinction is small, because that walk is what the
    #: designation screen renders — and ``can_embed`` answers False at request time rather
    #: than sending a call that 404s in the
    #: middle of an ingest run, after the parse and the OCR have been paid for.
    #:
    #: This comment previously read "verified present on OpenAI, NVIDIA NIM and OpenRouter,
    #: and verified ABSENT on Anthropic and DeepSeek", and nothing had verified any of it. It
    #: has since been checked against each vendor's own documentation and it was right on both
    #: halves — which changes nothing about why it was deleted, and is the reason the verdicts
    #: now live in ``capabilities.PROVIDER_TASKS`` with a URL and a read-date per cell instead
    #: of in a comment nobody can query.
    EMBEDDING = "embedding"
    #: The model distinguishes a query embedding from a passage embedding and the
    #: discriminator has to be sent. NVIDIA's own schema: "It is very important to use the
    #: correct ``input_type``. Failure to do so will result in large drops in retrieval
    #: accuracy." Nothing errors when it is wrong — recall just falls, uniformly, forever.
    #: OpenAI's models take no such parameter at all, which is why this is a flag and not a
    #: universal request field.
    EMBEDDING_INPUT_TYPE = "embedding_input_type"
    #: The ``dimensions`` parameter is honoured (Matryoshka truncation on OpenAI's ``-3``
    #: line, and passed through by OpenRouter). OFF means the model has exactly one width
    #: and asking for another is a rejection or, worse, an ignore — and an ignored
    #: ``dimensions`` produces vectors the collection cannot hold, discovered at upsert.
    EMBEDDING_DIMENSIONS = "embedding_dimensions"
    #: The row serves ``rerank()``.
    #:
    #: Same two-gate rule as ``EMBEDDING``: this flag plus
    #: ``capabilities.PROVIDER_TASKS[(provider, RERANK)]``, ANDed by
    #: ``capabilities.can_rerank``, which is what feeds
    #: ``app.rag.rerank.rerank_gate(provider_supports=...)``. That function's
    #: ``RerankSkipReason`` is closed and failure-free by design, so ``can_rerank`` returns a
    #: verdict on every input and raises on none — a gate that could raise would let an
    #: exception be recorded as ``PROVIDER_LACKS_CAPABILITY``, which is an outage laundered
    #: into a skip.
    #:
    #: This comment previously read "verified present on NVIDIA NIM and OpenRouter ONLY",
    #: which nothing had verified either; the vendors' documentation now says exactly that.
    #: The flag alone still cannot carry the part that matters downstream — NIM returns a
    #: ``LOGIT`` and OpenRouter returns a score whose scale varies with which upstream served
    #: the request — so ``capabilities.RERANK_SCALE`` is read beside it, never inferred from
    #: it. See ``capabilities.py``.
    RERANK = "rerank"


class ModelCapabilities(BaseModel):
    """Mirrors one ``provider_models`` row (docs/11 §16.2). The input to ``validate()``.

    ``max_output_tokens`` is the model's ceiling, not the request's budget, and the two
    disagree per model on NIM — a bot configuration that validates against one NIM model
    is a 422 on the next one served by the same connection.

    ``strict=True, extra="forbid"`` because this row **arrives from Laravel** — inside the
    configuration snapshot on a chat request, and inside every candidate on
    ``POST /internal/v1/embedding/readiness``. It is therefore an inbound contract model and
    `pydantic-contracts` applies in full. Both settings do a different job here and both have
    the same silent failure mode:

    * ``forbid`` — a renamed or mis-spelled cap key (``capabilities`` for ``supported``,
      ``max_tokens`` for ``max_output_tokens``) would otherwise evaporate and leave
      ``supported`` at whatever the model defaults to. On an *embedding* row that is the whole
      of finding C1 arriving one layer lower: a row that silently lost its embedding flag is a
      connection that silently stops being eligible, and the operator is told to fix a
      connection that was never broken.
    * ``strict`` — ``"8192"`` must not become ``8192`` and ``1`` must not become ``True``.
      ``X-KB-Config-Version`` claims both planes hold the same snapshot; coercion makes that
      claim false without moving the version.
    """

    model_config = ConfigDict(frozen=True, strict=True, extra="forbid")

    #: ``strict=False`` on this field and on its members, and on **nothing else on this row**.
    #:
    #: JSON has no set type and no enum type, so the wire form of ``frozenset[Capability]`` is
    #: an array of strings. FastAPI parses the body itself and validates a Python ``dict``, so
    #: under ``strict=True`` this field rejects every real request with ``frozen_set_type`` and
    #: then ``is_instance_of`` — while ``model_validate_json`` on the same bytes succeeds, which
    #: means a unit test written that way stays green while the endpoint 422s. That is
    #: `pydantic-contracts`' "strict mode is *looser* from JSON than from Python" gotcha, and it
    #: is why contract tests go through an HTTP client.
    #:
    #: The exemption is scoped to the one field JSON genuinely cannot express, rather than being
    #: taken on the model. Dropping ``strict`` model-wide would also admit ``"8192"`` as
    #: ``context_window`` — silencing coercion on every scalar here in order to fix a collection
    #: type. It is not a hole either: an array of unknown flags still fails with ``enum``, and a
    #: bare string still fails with ``frozen_set_type``, because lax mode does not treat a string
    #: as a sequence of members.
    supported: Annotated[frozenset[Annotated[Capability, Field(strict=False)]], Field(strict=False)]
    context_window: int
    max_output_tokens: int
    #: Per-model and admin-set, never per-call. ``reject`` raises before the first byte
    #: goes out; ``warn`` strips the option and records a ``CapabilityWarning``. There is
    #: no third setting, because the third setting people reach for is "drop it quietly",
    #: which is how a bot configured for structured output returns prose for a month.
    on_unsupported: Literal["reject", "warn"] = "reject"

    @field_serializer("supported", when_used="json")
    def _sorted_flags(self, supported: frozenset[Capability]) -> list[Capability]:
        """A set has no order, so its JSON rendering has none either — until this.

        The return type is ``list[Capability]`` and not ``list[str]``, which matters only
        outside this file: FastAPI derives the *output* half of the OpenAPI schema from this
        annotation, so ``list[str]`` would publish ``supported`` as an untyped ``string[]``
        and every generated client would lose the enum on the reply while keeping it on the
        request. ``Capability`` is a ``StrEnum``, so its members already sort by their wire
        value and ``sorted`` needs no key.

        The row reaches a wire in exactly one place today (the readiness reply, which an
        admin screen renders), and an unordered rendering there means the capability chips
        shuffle between two page loads of an unchanged configuration. It also makes a
        response body impossible to compare byte for byte in a contract test, which is the
        cheapest assertion available at a seam.

        ``when_used="json"`` and not unconditionally, because the two dump modes answer
        different questions. Python mode is a faithful Python representation of the model and
        every in-process reader treats it as one: a ``frozenset`` field dumps as a
        ``frozenset`` of ``Capability`` members, which is what makes
        ``ModelCapabilities(**row.model_dump())`` a round trip and what a row-to-row
        comparison relies on. Sorting there would make ``model_dump()`` lossy about both the
        container type and the enum members, and lossy in a way nothing raises on — a
        ``list[str]`` simply compares unequal to a ``frozenset[Capability]`` at whichever
        site happens to compare. Ordering is a *wire* concern, so it belongs in the mode that
        produces the wire.
        <!-- Verified against FastAPI 0.141.1: `serialize_response` validates the returned
        model instance itself and does not round-trip it through `model_dump()`, so an
        unconditional serializer would NOT surface as a response-validation error here. It
        would simply be wrong everywhere else and silent. -->
        """
        return sorted(supported)


# ── request ───────────────────────────────────────────────────────────────────


class Timeouts(BaseModel):
    """The three-level budget from docs/14 §19.4, in seconds.

    Nested, not parallel: ``connect`` < ``first_token`` < ``total``. These are the values
    handed to the vendor client; the *authoritative* stop is the caller's absolute
    deadline, because a per-chunk read timeout never fires on a model that emits one token
    every fifteen seconds (`fastapi-service`).
    """

    model_config = ConfigDict(frozen=True)

    connect: float = 3.0
    first_token: float = 20.0
    total: float = 45.0


class ReasoningOption(BaseModel):
    """Portable intent, not a vendor parameter.

    Seven levels, and two of them exist only because a vendor ships them: ``xhigh`` sits
    between high and max because Anthropic ships it as a distinct level, ``minimal``
    between none and low because OpenAI does. An enum missing either would round a
    recommended setting to a neighbour with no diff and no warning. A vendor lacking a
    level maps to its nearest supported step AND emits a ``CapabilityWarning``; it never
    silently rounds.

    ``budget_tokens`` is advisory almost everywhere — Anthropic rejects the shape that
    carried it, DeepSeek exposes effort steps instead, OpenAI has no equivalent. NIM is the
    single vendor that enforces it.
    """

    model_config = ConfigDict(frozen=True)

    effort: Literal["none", "minimal", "low", "medium", "high", "xhigh", "max"]
    budget_tokens: int | None = None
    #: Whether a UI will actually render the trace. Only send a vendor's "show me the
    #: thinking" flag when this is True — several vendors bill the trace either way.
    include_trace: bool = False


class Message(BaseModel):
    """One conversation turn. Retrieved evidence is NEVER a message — see ``ContextBlock``."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    role: Literal["user", "assistant"]
    content: str
    #: DeepSeek's ``reasoning_content``, and only DeepSeek's. It has two opposite rules:
    #: echoed back outside a tool call it is discarded, but inside an in-flight tool loop
    #: it MUST be replayed or the model reasons from a hole. So it is carried on the
    #: in-memory turn and is never persisted into conversation history that a later turn
    #: replays. Every other adapter ignores this field.
    reasoning: str | None = None


class ContextBlock(BaseModel):
    """One piece of retrieved evidence.

    Untrusted data. It enters the vendor request as its own section — a data block, a
    user-role message part — and is **never** concatenated into ``system``. Source text
    that can reach the instruction slot is prompt injection with our own retrieval pipeline
    as the delivery mechanism (`kb-security-baseline`, `kb-rag-query-contract` stage 14).

    ``index`` is assigned from the evidence set BEFORE generation, so citations are ours
    and not free-form model output. Ordering must be deterministic: prefix caching is exact
    string matching at every vendor here, and a re-sorted evidence list is a full cache miss
    that costs 10x and reports as normal.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    index: int
    chunk_id: Ulid
    title: str
    text: str


class ToolDef(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    name: str
    description: str
    #: JSON Schema. The accepted subset differs per vendor and the differences are not
    #: cosmetic; validate against the target vendor's rules in ``validate()``, not in review.
    parameters: dict[str, Any]


class ImageInput(BaseModel):
    """Base64 bytes only — deliberately no URL form.

    A URL the vendor fetches is a tenant-supplied fetch we do not perform and therefore
    cannot guard, which puts an SSRF surface outside our own hardened fetch path
    (`kb-security-baseline`).
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    media_type: str
    data_b64: str


class ChatRequest(BaseModel):
    """The one shape every caller builds. Adapters translate; callers never branch.

    There is deliberately **no credential field**. This object is safe to hold in a span
    processor, a replay record, or a diagnostics payload precisely because it cannot carry
    a secret; the key travels as a separate argument to ``stream()``.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    org_id: Ulid
    bot_id: Ulid
    trace_id: str
    idempotency_key: str | None = None
    provider_connection_id: Ulid

    #: The official vendor id, verbatim and explicit. Never a floating alias: an alias
    #: reassignment changes answers, price and capability flags with no diff in the repo
    #: and no error anywhere. Read the served id back off the response and compare.
    model: str
    #: The bot instruction ONLY. Retrieved text goes in ``context_blocks``.
    system: str
    messages: list[Message]
    context_blocks: list[ContextBlock] = Field(default_factory=list)

    max_output_tokens: int
    temperature: float | None = None
    reasoning: ReasoningOption | None = None
    response_schema: dict[str, Any] | None = None
    tools: list[ToolDef] = Field(default_factory=list)
    images: list[ImageInput] = Field(default_factory=list)

    #: Streaming is the default and the only path we measure. A buffered implementation
    #: that "works" is a defect: the Laravel relay is timing first-token latency.
    stream: bool = True
    cache_hint: Literal["none", "prefix"] = "none"
    timeouts: Timeouts = Timeouts()


# ── response ──────────────────────────────────────────────────────────────────


class StopReason(StrEnum):
    """Why generation ended. Eight values, and the two that matter are the two that get
    confused.

    ``MAX_OUTPUT`` is OUR cap and means the answer is truncated. ``CONTEXT_EXCEEDED`` is
    the model's own window. An unmapped native value becomes ``ERROR`` with
    ``Diagnostics.native_stop_reason`` preserved — never a fall-through to ``COMPLETE``,
    because vendors add stop reasons under their versioning policy without notice.
    """

    COMPLETE = "complete"
    MAX_OUTPUT = "max_output"
    CONTEXT_EXCEEDED = "context_exceeded"
    STOP_SEQUENCE = "stop_sequence"
    TOOL_USE = "tool_use"
    #: Content policy. Terminal: never retried, never fallen back. A refusal arrives as
    #: HTTP 200, so an adapter classifying on status alone files it as success and a router
    #: seeing empty text files it as a fault — either way the banned question gets asked
    #: again somewhere else and billed twice.
    REFUSAL = "refusal"
    CANCELLED = "cancelled"
    ERROR = "error"


class Usage(BaseModel):
    """Token accounting, in DISJOINT buckets.

    The three arithmetics this normalizes:

    * **OpenAI** — ``cached_tokens`` is a SUBSET of ``input_tokens``. Subtract it out.
    * **Anthropic** — ``input_tokens`` EXCLUDES cache reads and writes; the three are
      siblings. Assign each verbatim; subtracting here is the mirror-image bug.
    * **DeepSeek** — hit + miss PARTITION ``prompt_tokens``. Miss is the uncached input.

    Billing reads ``total_input_tokens``. Reading ``input_tokens`` as "the input" reports a
    200k-token cached document with a 50-token question as 50 input tokens on Anthropic,
    and double-counts the same prefix on DeepSeek.
    """

    model_config = ConfigDict(frozen=True)

    #: Uncached input only.
    input_tokens: int = 0
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    output_tokens: int = 0
    #: Billed INSIDE ``output_tokens``; reported separately for attribution only. Adding it
    #: to the output count double-bills every reasoning turn.
    reasoning_tokens: int = 0
    #: ``estimated`` rows are never aggregated into invoiced cost. A cancelled stream is
    #: always estimated except on Anthropic, whose ``message_start`` gives exact input.
    source: Literal["provider_final", "provider_partial", "estimated"] = "estimated"

    @property
    def total_input_tokens(self) -> int:
        return self.input_tokens + self.cache_read_tokens + self.cache_write_tokens


class CapabilityWarning(BaseModel):
    """An option the model could not honour, made visible.

    There is no silent-drop path. ``rejected`` means the request did not go out;
    ``ignored`` means it went out without the option and the tenant is told so.
    """

    model_config = ConfigDict(frozen=True)

    option: str
    action: Literal["rejected", "ignored"]
    detail: str


class Diagnostics(BaseModel):
    """Restricted vendor detail (§8.5), so useful features survive normalization.

    Allow-listed keys only — never a raw response dump. A vendor error body routinely
    echoes the request, and the request contains the packed prompt, which contains tenant
    document text. ``extras`` is an allow-list at the parse site, not a scrubber
    afterwards: the scrubber misses the case nobody imagined (`kb-security-baseline` §20.3).

    Nothing in here may carry a credential, a prompt, a message, or a retrieved chunk. A
    test greps the serialized payload for the fixture's system prompt and its API key.
    """

    model_config = ConfigDict(frozen=True)

    provider: str
    #: The vendor's own word, preserved even when it maps to ERROR. This is what tells us a
    #: vendor added a stop reason.
    native_stop_reason: str | None = None
    #: OpenRouter's actual upstream. Null everywhere else, and null on OpenRouter too
    #: unless the metadata header was sent.
    served_by: str | None = None
    #: Parsed rate-limit headers, tenant-safe. Left EMPTY where a vendor publishes no
    #: header schema (DeepSeek, NIM) — an invented reset time outranks the jittered backoff
    #: and pins us inside the rejection window.
    rate_limit: dict[str, str] = Field(default_factory=dict)
    warnings: list[CapabilityWarning] = Field(default_factory=list)
    extras: dict[str, Any] = Field(default_factory=dict)


class Delta(BaseModel):
    """One incremental piece of output.

    ``kind`` is the discriminator of ``StreamEvent``. ``reasoning`` deltas are gated on
    ``Capability.REASONING_TRACE`` by the UI, not dropped here — the cost line needs them
    even when no pane renders them.
    """

    model_config = ConfigDict(frozen=True)

    kind: Literal["text", "reasoning", "tool_args", "refusal"]
    text: str
    index: int = 0


class ChatResult(BaseModel):
    """The terminal event. Exactly one of these ends every stream, on every path.

    Success, vendor error, cancellation: one call site finalizes usage because there is one
    terminal event. Emit it from the ``except asyncio.CancelledError`` branch and then
    re-raise — **never** from ``finally``. Yielding while ``GeneratorExit`` unwinds raises
    ``RuntimeError: async generator ignored GeneratorExit``, the ASGI layer swallows it,
    and the usage row for a cancelled turn is simply lost while the vendor still bills it.
    """

    model_config = ConfigDict(frozen=True)

    #: ``"result"`` rather than a ``Delta`` kind: this is the discriminator that lets the
    #: consumer tell "more output" from "the turn is over" with a dict lookup instead of an
    #: isinstance ladder that silently accepts a mis-typed event.
    kind: Literal["result"] = "result"

    text: str
    stop_reason: StopReason
    usage: Usage
    provider_request_id: str | None = None
    first_token_ms: int | None = None
    total_ms: int
    #: One of the 18 in ``app.core.errors.ErrorClass``, as a string, or None on success.
    #: The fallback router reads THIS, never an HTTP status.
    error_class: str | None = None
    diagnostics: Diagnostics


#: What ``stream()`` yields. Tagged on ``kind``: an untagged union validates every member
#: and, when something is malformed, reports the failure of the variant that was never
#: intended — during an incident the error names the wrong shape (`pydantic-contracts`).
StreamEvent = Annotated[Delta | ChatResult, Field(discriminator="kind")]


# ── embeddings ────────────────────────────────────────────────────────────────
#
# The spec for this family writes the conceptual signature as
# ``embed(texts: list[str], *, model: str) -> list[list[float]]``. Both halves are honoured
# and both are widened here, each for a rule that outranks the sketch:
#
# * The arguments become an ``EmbeddingRequest``, matching ``stream(req, caps, credential)``
#   exactly. ``org_id`` and ``trace_id`` are not decoration: tenant isolation is enforced in
#   code at every layer and a call that cannot name its organization cannot be scoped,
#   metered or traced. A bare ``(texts, model)`` has nowhere to put either, and a caller
#   would have to learn a second idiom for the same credential rules.
# * The return becomes an ``EmbeddingResult`` whose ``.vectors`` IS the
#   ``list[list[float]]``. A bare list discards the token count, and embedding is billed per
#   token against the same per-org quota as chat — so a bare list is an unbilled call.


class EmbeddingInputType(StrEnum):
    """Which side of the retrieval pair a text is. **Deliberately has no default.**

    A default here is the exact silent-degradation path this contract exists to close.
    NVIDIA's models run in passage or query mode and their schema says a wrong value causes
    "large drops in retrieval accuracy"; OpenAI's take no such parameter and are symmetric.
    So a default would be correct on two vendors, silently wrong on a third, and invisible
    on all three — nothing raises, nothing is logged, recall is just worse for the life of
    the index.

    Only the caller knows which side it is on: the ingestion path embeds passages, the query
    path embeds a query. That is not derivable inside the adapter, and guessing it from
    ``len(texts) == 1`` is the shortcut that breaks on a one-chunk document.
    """

    QUERY = "query"
    PASSAGE = "passage"


class EmbeddingRequest(BaseModel):
    """One embedding call. No credential field, for the reasons ``ChatRequest`` records.

    There is no ``bot_id``: embedding happens during ingestion, where a source belongs to the
    organization and has not been assigned to any bot yet. Inventing one would encode a
    relationship that does not exist and would then be wrong at query time, where the same
    call embeds one question for whichever bot asked.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    org_id: Ulid
    trace_id: str
    idempotency_key: str | None = None
    provider_connection_id: Ulid

    #: The official vendor id, verbatim and explicit — never a floating alias, for the same
    #: reason as ``ChatRequest.model``, and with one extra consequence here: an alias that
    #: moves changes the vector space under a collection that cannot tell.
    model: str
    #: Order is the contract. ``EmbeddingResult.vectors[i]`` is the embedding of ``texts[i]``,
    #: and the adapter re-sorts the vendor's response by its own ``index`` field to guarantee
    #: it. OpenAI documents that the response may arrive out of order.
    texts: list[str] = Field(min_length=1)
    input_type: EmbeddingInputType
    #: Requires ``Capability.EMBEDDING_DIMENSIONS``. Left None means the model's native
    #: width; setting it on a model without the flag is a rejection or a warning, never a
    #: quiet send.
    dimensions: int | None = None
    #: ``first_token`` is meaningless on this surface — there is no stream — and ``total``
    #: is what governs. Reused rather than forked so one deadline configuration covers every
    #: provider call the service makes.
    timeouts: Timeouts = Timeouts()


class EmbeddingResult(BaseModel):
    """The result of one embedding call.

    Unlike ``ChatResult`` this carries **no ``error_class``**, and the asymmetry is
    deliberate: a stream must terminate with exactly one event on every path, so its
    terminal event has to be able to represent a failure. A request/response call has no
    stream to terminate, so a failure is a raised ``ProviderCallFailed`` and the ordinary
    handler renders it. Giving this model an error field would create a second failure
    channel that every caller would then have to check and half of them would forget.
    """

    model_config = ConfigDict(frozen=True)

    #: One per input, in input order. Never the vendor's response order.
    vectors: list[list[float]]
    #: ``app.retrieval.collection.EmbeddingSpace``, imported and never restated — see the
    #: module docstring. This is the type that derives the collection name, so the adapter and
    #: the indexer compute one name from one set of facts.
    #:
    #: ``dimensions`` on it is **the width actually returned**, read off this response, never
    #: hardcoded per model id: vendors ship new widths on ids that look like old ones, and
    #: several APIs take ``dimensions`` as a request parameter, so the id does not imply the
    #: width. The dangerous case is not a mismatch — Qdrant rejects those at upsert — it is a
    #: width COINCIDENCE: ``baai/bge-m3``, ``nvidia/nv-embedqa-e5-v5`` and
    #: ``text-embedding-3-large`` truncated to 1024 are all 1024-wide, mutually meaningless,
    #: and every one of them upserts cleanly.
    space: EmbeddingSpace
    #: Whether the VENDOR returned unit-norm vectors, as observed on this response.
    #:
    #: Deliberately here and not on ``EmbeddingSpace``: it is a property of the response, not
    #: of the identity, and putting it inside the space would fold it into the collection name
    #: — so a vendor changing its normalization would silently open a second collection, which
    #: is the same failure ``bge-m3-embeddings`` records for the canary digest.
    #:
    #: It is not decoration. ``collection.DENSE_DISTANCE`` is fixed to ``"Cosine"`` and folded
    #: into the collection name "because every embedding API here documents its vectors as
    #: normalized" — this flag is the only evidence that claim holds for the vectors actually
    #: returned, and cosine and dot product coincide only while it does. The indexer
    #: L2-normalizes on our side regardless (``embedder.NORMALIZE_EMBEDDINGS``), so a False
    #: here is a finding to record rather than a failure to raise; unrecorded it is a
    #: plausible ordering that is simply wrong.
    normalized: bool
    #: ``output_tokens`` is always 0 here — nothing is generated. ``input_tokens`` carries
    #: the whole cost and ``total_input_tokens`` is what billing reads, as everywhere else.
    #: No vendor on this surface reports a cached bucket, so those stay 0 rather than guessed.
    usage: Usage
    provider_request_id: str | None = None
    total_ms: int
    diagnostics: Diagnostics


# ── reranking ─────────────────────────────────────────────────────────────────


class RerankScale(StrEnum):
    """What a rerank score MEANS, and the reason this is a first-class field.

    The local cross-encoder returned a sigmoid in [0, 1] and ``app/rag/evidence.py``
    calibrated its refusal threshold on that scale. The API rerankers do not agree with it or
    with each other: NVIDIA's ranking endpoint returns an unbounded signed ``logit`` (its own
    example ranks ``0.226``, ``-1.17``, ``-1.52``), while Cohere- and Voyage-shaped responses
    return a bounded ``relevance_score``.

    Applying a 0.30 threshold to a logit passes almost everything; applying a logit threshold
    to a bounded score refuses almost everything. Neither raises — only the refusal rate moves,
    and only in aggregate. So the scale travels with every score and is never assumed.

    **The rule, corrected:** a scale may be thresholded unless it is ``UNCALIBRATED``, and the
    range a threshold must lie in is a separate question answered by ``is_bounded``. See the
    comment above the two properties, which records why they must not collapse into one.

    This docstring previously stated the rule as *"only ``PROBABILITY`` may be thresholded;
    ``LOGIT`` and ``UNCALIBRATED`` may be used for ORDERING and nothing else."* Both halves
    were wrong. ``PROBABILITY`` no longer exists, and barring ``LOGIT`` from the threshold
    would have disabled the evidence gate for the entire platform, since ``LOGIT`` is the only
    scale any configured provider can actually return.

    ``UNCALIBRATED`` is not a hedge, it is the honest answer for a gateway that documents no
    normalization guarantee across the upstreams it routes to. A model reaching that state is
    a model whose row has not been characterized yet.

    ONE ENUM, AFTER BRIEFLY BEING TWO.
    ---------------------------------------------------------------------------------------
    ``app/rag/rerank.py`` used to define its own ``RerankScale`` — ``LOGIT | SIGMOID |
    UNIT_INTERVAL`` — while this one read ``PROBABILITY | LOGIT | UNCALIBRATED``. Two types of
    one name sharing a single member, with no conversion in either direction, across a boundary
    that type-checked. Two defects followed, neither of which raised anything:

    * ``PROBABILITY`` could not express that module's split between ``SIGMOID`` and
      ``UNIT_INTERVAL`` — a split it makes explicitly because "both are 0–1 and the coincidence
      invites treating a threshold as portable between them". An adapter reporting
      ``PROBABILITY`` had to be widened to one of them by a guess.
    * ``UNCALIBRATED`` had no counterpart at all, so the one state whose entire meaning is
      "never threshold this" was unrepresentable downstream — and that module's ``is_bounded``
      answered ``True`` for every member but ``LOGIT``, so an uncalibrated score widened onto
      either bounded member sailed through the range check.

    ``PROBABILITY`` was replaced by that module's own two bounded members, making this enum a
    strict superset, which turned the resolution into a deletion rather than a translation.
    ``retrieval-engineer`` has since deleted the local copy and imports this one; no member was
    lost and ``UNCALIBRATED`` was gained. There is now exactly one ``RerankScale``, and
    ``tests/unit/test_provider_capability_matrix.py`` asserts the two names denote the same
    object so they cannot fork again.
    """

    #: Unbounded, signed, roughly ±10, centred near zero. NVIDIA's ranking models.
    LOGIT = "logit"
    #: A logistic transform of a logit, bounded 0–1. What the retired local cross-encoder
    #: produced under ``normalize=True``. A threshold of 0.30 here means "leans irrelevant";
    #: the same float on ``LOGIT`` means "clearly relevant" — roughly opposite instructions.
    SIGMOID = "sigmoid"
    #: Bounded 0–1 but NOT a sigmoid of a logit: a vendor-defined relevance score whose
    #: distribution is its own. Listed separately from ``SIGMOID`` precisely because both are
    #: 0–1 and the coincidence invites treating a threshold as portable between them. Only the
    #: bounds transfer, and the bounds are not the calibration.
    UNIT_INTERVAL = "unit_interval"
    #: No characterization exists for this ``(provider, model)``. It is not a bounded scale and
    #: it is not a logit; it is the absence of a claim, and it is the member the previous split
    #: could not carry.
    #:
    #: **This note used to say "ordering only, forever, until an evaluation run says
    #: otherwise", and there is no ordering-only path** (finding #47). Stage 11 takes a
    #: ``RerankCalibration`` as a required argument and one cannot be constructed on this
    #: member; stage 12 raises on a skipped outcome and on a scale mismatch. The only
    #: calibration-free route through stage 12 is ``evidence.select_unranked``, which selects on
    #: branch agreement and never calls a reranker at all. So this member means the provider is
    #: not rerank-eligible, and ``capabilities.can_rerank`` reads it that way.
    UNCALIBRATED = "uncalibrated"

    # ``is_bounded`` and ``may_threshold`` are TWO PROPERTIES AND MUST STAY TWO.
    #
    # They were briefly one — ``may_threshold`` was written as ``return self.is_bounded`` —
    # and that single line disabled the evidence gate for every organization on the platform.
    # The collapse is seductive because on the two bounded members the answers coincide, so
    # nothing in a fixture disagrees. Trace it against the capability matrix instead: NVIDIA
    # NIM is the ONLY one of the five providers with a ranking endpoint
    # (``capabilities.PROVIDER_TASKS``) and its scale is ``LOGIT``
    # (``capabilities.RERANK_SCALE``). A ``LOGIT`` that may never be thresholded makes stage 12
    # unreachable in production, turns stage 11 into an expensive reordering nothing can act
    # on, and leaves the bot answering from whatever the reranker put first with no evidence
    # gate anywhere. Nothing raises; the refusal rate simply goes to zero.
    #
    # Keep them apart, and keep this comment: the collapse IS the bug, and it is the kind a
    # reader re-derives from the member names alone.

    @property
    def is_bounded(self) -> bool:
        """Whether a score on this scale must lie within 0–1. A **range** question.

        Used to sanity-check a ``min_score`` at configuration time: 0.30 is plausible on a
        bounded scale and implausible on a logit, so this catches a logit pasted into a bounded
        field. It catches nothing about whether the number means anything — see
        ``may_threshold``, which answers the other question.

        ``UNCALIBRATED`` is False here, and deliberately so: an unknown scale is not a bounded
        scale, and answering True would let a ``min_score`` of 0.30 pass its own range check
        against a distribution nobody has measured.
        """
        return self in (RerankScale.SIGMOID, RerankScale.UNIT_INTERVAL)

    @property
    def may_threshold(self) -> bool:
        """Whether a score on this scale may be compared to a number at all. A **calibration**
        question, and emphatically not a range one.

        **Being unbounded does not make a scale unthresholdable.** The derivation procedure in
        `bge-reranker` sets ``min_score`` to the 5th percentile of the answerable top-1
        distribution — it reads a percentile off a *measured* distribution, works on any
        monotonically ordered scale, and never assumes a range. The retired local cross-encoder
        is the proof: it natively returned a **logit** and the sigmoid was applied for
        readability, so the same threshold could have been derived on the raw logit and would
        have meant exactly the same thing.

        ``UNCALIBRATED`` is therefore the only honest False. A gateway that documents no
        normalization guarantee across the upstreams it routes to returns numbers whose meaning
        varies per request, so no measured distribution exists and there is no percentile to
        read. That is not hypothetical: OpenRouter's ``/api/v1/rerank`` is ``SUPPORTED`` on the
        vendor axis, and it is deliberately absent from ``capabilities.RERANK_SCALE`` for
        exactly this reason, so it is the member's first real inhabitant rather than a
        defensive default.

        This is a permission, not the gate. The gate is ``app.rag.rerank.calibration_for``
        raising ``RerankNotCalibrated`` for a ``(provider, model)`` pair with no evaluation run
        behind it; this property must never become a second, stricter one that overrules it.

        **It is also, as of finding #47, the third axis of ``capabilities.can_rerank``.** That
        is not the same thing as overruling ``calibration_for``: a ``False`` here says no
        evaluation run *could* produce a usable calibration, because ``RerankCalibration``
        refuses to be constructed on this member at all, while ``calibration_for`` says none
        *has*. NIM sits in the second state and OpenRouter in the first.
        """
        return self is not RerankScale.UNCALIBRATED


class RerankRequest(BaseModel):
    """One reranking call.

    **No ``top_n``.** Every vendor on this surface offers one, and sending it makes the
    response a truncated, re-sorted list — at which point ``scores[i]`` no longer refers to
    ``passages[i]`` and the missing entries need a sentinel that every consumer would have to
    handle. Ask for every score, in input order, and let the pipeline cut. Depth control is a
    retrieval-stage decision (`kb-rag-query-contract`), not a wire parameter.
    """

    model_config = ConfigDict(extra="forbid", frozen=True)

    org_id: Ulid
    trace_id: str
    provider_connection_id: Ulid

    model: str
    query: str
    #: Untrusted tenant document text, exactly as embedded — heading prefix included, so the
    #: passage scored is the passage indexed. The per-model ceiling (512 on NVIDIA) is
    #: enforced in the adapter's validator, not here, because it differs per vendor.
    passages: list[str] = Field(min_length=1)
    timeouts: Timeouts = Timeouts()


class RerankResult(BaseModel):
    """Scores for one reranking call.

    ``scores`` is **input-aligned**: ``scores[i]`` is the relevance of ``passages[i]``. Both
    vendors return a list sorted by relevance and carrying the original position in an
    ``index`` field, so the adapter scatters it back. Reading the vendor's list positionally
    is the bug that produces a rerank stage which "works" — plausible floats, sensible
    distribution, and the scores attached to the wrong passages.
    """

    model_config = ConfigDict(frozen=True)

    scores: list[float]
    #: Read this before comparing a score to anything. See ``RerankScale``.
    scale: RerankScale
    #: **All zeros today, and that is a contract gap rather than a free call.** ``Usage`` has
    #: five buckets and every one of them is a token count. NVIDIA NIM's ranking endpoint
    #: returns no usage object at all, and OpenRouter's ``/api/v1/rerank`` is Cohere-shaped,
    #: where the billing unit is the ``search_unit`` — not a token, and with no bucket here.
    #: Both wired rerankers are therefore unaccountable in this type, so this stays
    #: ``source="estimated"`` and is never aggregated into invoiced cost, which means rerank
    #: spend is currently invisible to the per-org quota that ADR-030 says it shares with chat.
    #:
    #: Reported rather than papered over: inventing a token count from passage lengths would
    #: make the number look real, and a plausible wrong cost is worse than a missing one.
    #: Resolution needs either a per-call unit bucket on ``Usage`` — which is a contract change
    #: across every consumer — or rerank spend accounted from call counts outside this type.
    usage: Usage
    provider_request_id: str | None = None
    total_ms: int
    diagnostics: Diagnostics


# ── the adapter ───────────────────────────────────────────────────────────────


class ProviderAdapter(Protocol):
    """What all five vendor modules satisfy, and the entire surface the pipeline sees.

    Conformance is checked statically. ``runtime_checkable`` is deliberately absent: it
    verifies attribute presence and not signatures, so it would pass an adapter whose
    ``stream`` takes the wrong arguments — assurance that is worse than none.
    """

    #: Stable, lowercase, and the value of the ``gen_ai.provider.name`` span attribute and
    #: the ``provider`` metric label. A bounded set of five; never a model id, never an
    #: upstream slug (`kb-observability-conventions` bans unbounded labels).
    name: str

    #: Below this many seconds of remaining deadline, do not start an attempt. Starting one
    #: bills a completion the caller has already abandoned (`kb-error-taxonomy`).
    min_useful_seconds: float

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Check every optional field against the model's flags, BEFORE the first byte.

        ``on_unsupported="reject"`` raises ``KbError(ErrorClass.VALIDATION, ...)`` naming
        the option and the model. ``"warn"`` strips the option and returns a warning that
        rides into ``Diagnostics`` and the conversation diagnostics pane. Both paths are
        visible. A third path — returning nothing and sending the option anyway — is the
        silent drop §8.6 forbids.
        """
        ...

    def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Call the vendor and translate its stream, event by event.

        Declared ``def`` and not ``async def`` on purpose. The implementation is an async
        *generator* function, which is an ordinary callable returning an ``AsyncIterator``.
        Writing ``async def`` here — in a body with no ``yield`` — returns a coroutine
        instead, and the Protocol check passes while every call site awaits the wrong thing.

        ``credential`` is a per-call argument for three reasons, each of which is a bug we
        would otherwise ship:

        * Not a field of ``ChatRequest``, so the request object stays safe to serialize
          into a span, a replay record or a diagnostics payload.
        * Not a constructor argument, so one long-lived adapter instance cannot hold one
          org's key while serving another org's turn.
        * Not resolved from storage in here. Under ADR-011 the decrypted key arrives on the
          already-validated internal request; an adapter that reaches for storage is a
          second credential path with no audit entry (`kb-security-baseline`).

        ``get_secret_value()`` is called exactly once per implementation, at the client
        construction site, so extracting the key is a greppable act rather than an accident
        of serialization.

        Terminates with exactly one ``ChatResult`` on every path — including cancellation,
        including a vendor error. The adapter classifies; it never decides fallback and it
        never calls a second vendor itself. An adapter that retries elsewhere has broken the
        per-attempt ``provider_calls`` accounting that makes the cost of fallback visible.
        """
        ...


class EmbeddingAdapter(Protocol):
    """What a vendor module satisfies **only if that vendor can actually embed**.

    Separate from ``ProviderAdapter`` rather than bolted onto it. Widening ``ProviderAdapter``
    would force ``AnthropicAdapter.embed`` to exist and raise, which type-checks, satisfies
    every Protocol check, and fails at request time on the ingestion path — the one place a
    failure costs a re-parse.

    **The static gate is the absence of the method.** ``AnthropicAdapter`` and
    ``DeepSeekAdapter`` do not define ``embed``, so ``_assert_embeds(AnthropicAdapter())`` does
    not compile, and a unit test asserts the attribute is missing at runtime for the benefit of
    anyone reaching for ``getattr``. That is a stronger statement than a boolean flag, because a
    flag can be set to True by an edit that changes no behaviour.

    **The runtime gate is ``capabilities.can_embed(provider, caps)``**, because absence of a
    method is not a question a caller can ask without ``hasattr``. The two are asserted against
    each other: a method present where the matrix says the vendor has no endpoint, or absent
    where it says ``SUPPORTED``, fails ``tests/unit/test_provider_capability_matrix.py``.

    This docstring previously said "three of the five vendors have no embedding endpoint and
    never will have one on our schedule", then "exactly one cell has a source (OpenAI); the
    other four are ``UNVERIFIED``". Both were wrong and wrong in opposite directions, which is
    the argument for keeping the answer in one cited table rather than in a docstring. On the
    vendors' own documentation it is **three: OpenAI, NVIDIA NIM and OpenRouter**. The two that
    genuinely have no endpoint are Anthropic — which says so in its own words — and DeepSeek,
    whose complete API reference indexes five routes and none of them embeds.

    The three that do are not interchangeable and the differences are not stylistic:
    ``input_type`` is required on NIM and meaningless on OpenAI, ``dimensions`` is OpenAI's
    alone, and OpenRouter documents that an over-window input may be *truncated* rather than
    refused. Which is why this is a Protocol with per-vendor implementations and not a shared
    helper. See ``capabilities.py``.
    """

    name: str
    min_useful_seconds: float

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Check every optional field against the row's flags, BEFORE the first byte.

        Same two-path rule as ``ProviderAdapter.validate``: ``reject`` raises, ``warn``
        strips and records. At minimum this covers ``dimensions`` against
        ``EMBEDDING_DIMENSIONS``, the batch ceilings (item count AND summed tokens — they are
        different limits and the token one is the one that surprises), and the per-input
        length limit, which is a hard rejection on every vendor here by default rather than a
        truncation. That default is correct and must not be relaxed: a silently truncated
        passage is indexed from its head and its tail becomes unsearchable forever, which is
        precisely the failure the local embedder's load-time assertion existed to prevent.
        """
        ...

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """Embed every text in one call and return the vectors in INPUT order.

        Declared ``async def`` here and ``def`` on ``stream()``, and the difference is not
        style. ``stream`` is an async *generator* function — an ordinary callable returning
        an ``AsyncIterator`` — so declaring it ``async def`` in the Protocol would demand a
        coroutine and every call site would await the wrong thing. This one really is a
        coroutine: one request, one response, nothing to yield.

        The credential is a per-call argument for the three reasons ``stream`` records, and
        one more that is specific to this surface: embedding runs inside a Celery task rather
        than a request, so an adapter holding a key on the instance would hold it across
        every org whose documents that worker process happens to ingest next.

        Raises ``ProviderCallFailed`` on failure. It never returns a partial result: a batch
        that half succeeded would write half a source version's vectors and leave the rest
        missing, and the version would then publish as complete.
        """
        ...


class RerankAdapter(Protocol):
    """What a vendor module satisfies **only if that vendor exposes a reranking endpoint**.

    **Two of five do** — NVIDIA NIM and OpenRouter. OpenAI, Anthropic and DeepSeek are
    positively denied by ``bge-reranker`` and ``nvidia-nim-api`` and by each vendor's own
    surface enumeration. This docstring has said "two of five" before, while the matrix said
    one and while it named nobody; it is now two *and names them*, which is the part that was
    always missing.

    The two are not equivalent. NIM's scores are an unbounded signed ``LOGIT`` with a
    characterized shape, so a calibrated threshold can be derived. OpenRouter's route fronts
    several upstream cross-encoders on one credential with no documented normalization, so its
    scale is ``UNCALIBRATED`` and its scores may **order** candidates and may never cut them.
    An adapter that reported anything else for it would make a ``min_score`` constructible
    against a distribution nobody measured.

    Reranking is therefore an OPTIONAL stage under ADR-030 — when the org's connection cannot
    rerank, the pipeline serves the fused order and records the skip on the trace. It does
    **not** silently substitute an LLM-scoring pass: that changes cost and latency by an order
    of magnitude and has to be a configuration somebody chose.

    Same two-gate arrangement as ``EmbeddingAdapter``: absence of the method statically,
    ``capabilities.can_rerank`` at runtime, and a test asserting the two agree.
    """

    name: str
    min_useful_seconds: float

    def validate_rerank(
        self, req: RerankRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Enforce the per-vendor passage ceiling and the pair-length limit.

        The pair-length check is the one that matters and it is the one with no error to
        catch: every vendor here truncates an over-long query-plus-passage pair silently, by
        documented default. A truncated pair scores off the distribution the evidence
        threshold was calibrated on, it fires hardest on the largest and most
        information-dense chunks, and the only symptom is a refusal rate that moved in
        aggregate months later.
        """
        ...

    async def rerank(
        self,
        req: RerankRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> RerankResult:
        """Score every passage against the query, returning scores in INPUT order.

        The vendor's response is sorted by relevance and identifies each entry by its
        original position; the adapter scatters it back and asserts the result covers every
        input exactly once. A vendor that returns fewer entries than it was given is a
        failure, not a partial answer — silently dropped passages become evidence that
        vanished, and the answer that follows is grounded in a subset nobody chose.
        """
        ...

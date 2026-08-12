# The internal chat request, complete — reference

Depth for `pydantic-contracts`. Spec: `docs/06-architecture.md` §11.2, §11.4, `docs/11-data-model.md` §16.2.
The inbound half of `services/ai-service/app/contracts/internal/chat.py`. The outbound stream union lives in the skill body and shares this module; `Ulid` is declared once, there.

**This module does not exist yet.** `ls services/ai-service/app/contracts/internal/` returns `__init__.py` and `.gitkeep`. The chat router and the five provider wire adapters are out of the current scope by explicit ruling, so everything below is a specification for whoever writes them — read it as what the file must be, not as what it is. The credential field in particular carries a shape that **no code implements**: see the note on `provider_credentials`.

```python
# services/ai-service/app/contracts/internal/chat.py
from enum import StrEnum
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, SecretStr, ValidationError


class Inbound(BaseModel):
    """Base for everything Laravel sends. Three settings, three distinct jobs.
    strict: "8" must not become 8 and 1 must not become True — X-KB-Config-Version
            claims both sides hold the same snapshot, and coercion makes that a lie.
    forbid: a renamed field must 422 here rather than evaporate.
    frozen: a request is evidence, not scratch space. It also makes a model hashable —
            but only one whose fields are all hashable, so ConfigSnapshot is hashable and
            ChatExecuteRequest is NOT (its credential map is a dict). The snapshot is what
            keys a per-request cache; the request must never be hashed anyway."""
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
    base_url: str | None = None
    # NO api_key here. ADR-011 puts credentials on ChatExecuteRequest, outside the
    # snapshot, because ConfigSnapshot is hashed into configuration_version and persisted
    # into retrieval_traces and the playground. A SecretStr inside it would digest as
    # '**********' — so the hash looks stable for the wrong reason — and every rotation
    # would move a version that nothing about the configuration actually changed.

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
    provider_credentials: dict[Ulid, SecretStr]   # ADR-011: a sibling of `config`, never
    # inside it. A MAP, not one field (docs/22 finding F12, ruled 2026-08-12): one turn can
    # need three keys — chat, embedding (ADR-031 lets that be a DIFFERENT connection), rerank
    # — and EmbeddingRequest/RerankRequest already carry their own provider_connection_id.
    # Keyed by connection_id because that is the identity BOTH planes already compute:
    # app/providers/embedding_selection.py's EmbeddingConnection and core-api's
    # EmbeddingCandidate.php each produce one and each holds no credential, so neither
    # changes when this lands. Ulid keys, not str: a dict has no extra="forbid", so the key
    # type is the only thing keeping it from being a free-form bag. Carries only the
    # connections THIS turn needs, never every connection the org owns. A surface whose
    # connection_id is missing is a `validation` refusal — never a fallback to the chat key.
    # repr/str/model_dump() -> '**********' per value, nested in the map (verified on
    # pydantic 2.13.4). get_secret_value() appears exactly once in the codebase, inside the
    # adapter, ON ONE ENTRY: a {k: v.get_secret_value() ...} comprehension unwraps all three
    # into a plain dict with no masking left, and one str() of that prints every key.
    # The WHOLE MAP is excluded from snapshot_hash() and from every idempotency fingerprint:
    # rotating any one key must not move configuration_version, or it invalidates every
    # cached answer and stops replayed jobs reproducing byte-identically.
    # COST, measured: a dict field makes this model UNHASHABLE despite frozen=True
    # (`TypeError: unhashable type: 'dict'`). That is fine — nothing may hash the request,
    # since that would pull the credentials in. ConfigSnapshot has no dict field, stays
    # hashable, and is the object that keys a per-request cache.

def parse_request(raw: bytes) -> ChatExecuteRequest:
    try:
        return ChatExecuteRequest.model_validate_json(raw)
    except ValidationError as exc:
        # include_input=False is not optional: the rejected input is routinely the
        # config snapshot, and errors() renders it raw (kb-security-baseline).
        raise KbError("validation", detail=exc.errors(
            include_input=False, include_context=False, include_url=False))
```

`query` is the **internal** field name and is scoped to this request. The *public* body a client posts to Laravel is `{client_message_id, content}` — `kb-internal-api-contracts` owns it, and Laravel maps `content` onto `query` when it builds the snapshot. Neither name is an alias for the other; a client that posts `query` gets a `422`.

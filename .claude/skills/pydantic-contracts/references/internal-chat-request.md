# The internal chat request, complete — reference

Depth for `pydantic-contracts`. Spec: `docs/06-architecture.md` §11.2, §11.4, `docs/11-data-model.md` §16.2.
The inbound half of `services/ai-service/app/contracts/internal/chat.py`. The outbound stream union lives in the skill body and shares this module; `Ulid` is declared once, there.

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

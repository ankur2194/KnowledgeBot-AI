---
name: kb-provider-adapter-contract
description: The internal abstraction over OpenAI, Anthropic, DeepSeek, NVIDIA NIM, and OpenRouter — shared request shape, normalized response, capability flags, fallback eligibility. Use whenever editing services/ai-service/app/providers/, mapping a provider's stream events, token usage, or stop reason, or deciding whether a failed call may fall back. There is no LiteLLM gateway (ADR-001); each provider is called through its official API. Pairs with kb-error-taxonomy (the classes this maps into).
---

# Provider Adapter Contract

FastAPI AI service — Python, Pydantic v2 typed contracts, official provider SDKs or documented HTTP clients (`docs/05-tech-stack.md` §9.4). Lives in `services/ai-service/app/providers/`.
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.4–8.7, docs/19-repo-structure-adrs.md ADR-001, docs/11-data-model.md §16.2, §16.6

## Non-negotiables

- **No provider credential leaves the adapter.** The adapter receives a decrypted secret from the credential resolver, uses it for one call, and never puts it in a log line, a span attribute, a `Diagnostics` payload, or an exception message. Encryption and storage belong to `kb-security-baseline`; this skill only guarantees the value stops here.
- **Capabilities come from `provider_models.capability_flags`, never from the model id string.** Parsing `"gpt-5"` or `"deepseek-reasoner"` to infer support is how capability drift ships to production — and both of those ids are already dead (`deepseek-reasoner` was retired 2026-07-24), which is the point: id strings decay, the capability row does not. `docs/11-data-model.md` §16.2 is the source of truth; the DB row is the input to `validate()`.
- **An unsupported option is rejected or warned — never silently dropped** (§8.6). Silent drops mean a bot configured for structured output quietly returns prose and nobody notices for a month.
- **`stop_reason` never lies about truncation.** A length cap maps to `MAX_OUTPUT`, never `COMPLETE`. Truncated answers presented as complete are the worst failure this layer can cause: the user sees a confident half-sentence and no error anywhere.
- **Every provider error maps into the taxonomy in `kb-error-taxonomy`** — this skill decides *which* class a given HTTP code, header, and body shape belongs to; it does not define the classes or the backoff schedule.
- **Retrieved context is untrusted data** and enters the request as `context_blocks`, never concatenated into `system`. Prompt section layout is `kb-rag-query-contract` (stage 14); injection defence in depth is `kb-security-baseline`.

## How we use it

One module owns the shapes; every adapter imports them and adds nothing to the public surface.

```python
# services/ai-service/app/providers/contract.py
from enum import StrEnum
from typing import Any, AsyncIterator, Literal, Protocol
from uuid import UUID
from pydantic import BaseModel, ConfigDict, Field

class Capability(StrEnum):
    TEXT               = "text"
    IMAGE_INPUT        = "image_input"
    TOOL_USE           = "tool_use"
    STRUCTURED_OUTPUT  = "structured_output"       # native json_schema; NOT the same as JSON_MODE
    JSON_MODE          = "json_mode"               # free-form valid JSON only (DeepSeek's ceiling)
    REASONING          = "reasoning"
    REASONING_TRACE    = "reasoning_trace"         # thinking text is actually returned, not just billed
    SAMPLING           = "sampling"                # temperature/top_p accepted at all
    PROMPT_CACHING     = "prompt_caching"
    STREAM_USAGE       = "stream_usage"            # usage arrives on the stream
    EARLY_INPUT_USAGE  = "early_input_usage"       # input tokens known before the stream ends

class ModelCapabilities(BaseModel):
    """Mirrors provider_models.capability_flags (docs/11 §16.2)."""
    model_config = ConfigDict(frozen=True)
    supported: frozenset[Capability]
    context_window: int
    max_output_tokens: int
    on_unsupported: Literal["reject", "warn"] = "reject"   # per-model, admin-set; never per-call

class ChatRequest(BaseModel):
    """The one shape every caller builds. Adapters translate; callers never branch on provider."""
    model_config = ConfigDict(extra="forbid", frozen=True)
    org_id: UUID
    bot_id: UUID
    trace_id: str
    idempotency_key: str | None = None
    provider_connection_id: UUID
    model: str                                     # official provider id, verbatim
    system: str                                    # bot instruction ONLY
    messages: list["Message"]
    context_blocks: list["ContextBlock"] = []      # retrieved evidence; untrusted; cacheable prefix
    max_output_tokens: int
    temperature: float | None = None               # needs Capability.SAMPLING
    reasoning: "ReasoningOption | None" = None
    response_schema: dict[str, Any] | None = None  # needs STRUCTURED_OUTPUT
    tools: list["ToolDef"] = []
    images: list["ImageInput"] = []
    stream: bool = True
    cache_hint: Literal["none", "prefix"] = "none"
    timeouts: "Timeouts"                           # connect / first_token / total, docs/14 §19.4

class ReasoningOption(BaseModel):
    """Portable intent, not a provider parameter — adapters map effort onto the provider's own
    control. budget_tokens is a hint only; several providers ignore it."""
    effort: Literal["none", "minimal", "low", "medium", "high", "xhigh", "max"]
    budget_tokens: int | None = None
    include_trace: bool = False
    # Seven levels, and two of them exist only because a provider ships them. `xhigh` sits
    # between high and max because Anthropic ships it as a distinct level and recommends it
    # for coding/agentic work; `minimal` sits between none and low because OpenAI ships it.
    # An enum missing either would silently round a recommended setting to a neighbour,
    # which is exactly the over-normalization
    # this contract forbids. Providers lacking a fifth level map it to their nearest
    # supported step and emit an `ignored_option` diagnostic rather than failing.

class StopReason(StrEnum):
    COMPLETE        = "complete"          # model chose to stop
    MAX_OUTPUT      = "max_output"        # hit OUR max_output_tokens — answer is truncated
    CONTEXT_EXCEEDED = "context_exceeded" # hit the model's window mid-generation
    STOP_SEQUENCE   = "stop_sequence"
    TOOL_USE        = "tool_use"
    REFUSAL         = "refusal"           # content policy; terminal, never retried
    CANCELLED       = "cancelled"         # client disconnect
    ERROR           = "error"

class Usage(BaseModel):
    """Buckets are DISJOINT. Providers disagree on whether cached tokens are a subset of the
    input count (OpenAI, DeepSeek) or a sibling of it (Anthropic); adapters normalize to
    disjoint here, and billing reads total_input_tokens, never input_tokens."""
    input_tokens: int = 0            # uncached input only
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    output_tokens: int = 0
    reasoning_tokens: int = 0        # billed inside output_tokens; reported for attribution
    source: Literal["provider_final", "provider_partial", "estimated"] = "estimated"

    @property
    def total_input_tokens(self) -> int:
        return self.input_tokens + self.cache_read_tokens + self.cache_write_tokens

class CapabilityWarning(BaseModel):
    option: str                                    # "response_schema", "temperature", ...
    action: Literal["rejected", "ignored"]
    detail: str

class Diagnostics(BaseModel):
    """Restricted provider detail (§8.5) so useful features survive normalization. Allow-listed
    keys only — never a raw response dump, which carries the prompt, which carries tenant text."""
    provider: str
    native_stop_reason: str | None = None
    served_by: str | None = None                   # OpenRouter's actual upstream
    rate_limit: dict[str, str] = Field(default_factory=dict)   # parsed headers, tenant-safe
    warnings: list[CapabilityWarning] = Field(default_factory=list)
    extras: dict[str, Any] = Field(default_factory=dict)

class Delta(BaseModel):
    kind: Literal["text", "reasoning", "tool_args", "refusal"]
    text: str
    index: int = 0

class ChatResult(BaseModel):   # exactly one of these terminates every stream
    text: str
    stop_reason: StopReason
    usage: Usage
    provider_request_id: str | None = None
    first_token_ms: int | None = None
    total_ms: int
    error_class: str | None = None                 # a kb-error-taxonomy class name
    diagnostics: Diagnostics

class ProviderAdapter(Protocol):
    name: str
    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]: ...
    def stream(self, req: ChatRequest, caps: ModelCapabilities) -> AsyncIterator[Delta | ChatResult]: ...
```

`validate()` runs **before** the first byte goes out. With `on_unsupported="reject"` it raises a validation error naming the option and the model; with `"warn"` it strips the option and appends a `CapabilityWarning` that rides into `Diagnostics` and the conversation diagnostics panel. Both paths are visible; neither is silent. The stream terminates with a `ChatResult` on every path — success, error, cancellation — so usage finalization has one call site.

### Fallback eligibility

Ordered fallback (§8.7) consults this table on the `error_class` the adapter produced. There is no "probably transient" bucket; an unmapped class does **not** fall back.

| Condition | Typical provider evidence | Fallback? | Why |
|---|---|---|---|
| Provider outage / 5xx | Anthropic `529 overloaded_error`, OpenAI 503, DeepSeek `503 Server Overloaded`, OpenRouter 502/503 | **Yes** | Another provider can serve it |
| Connection / first-token timeout | no bytes before `timeouts.first_token` | **Yes** | Nothing generated, nothing charged |
| Temporary server error | 500 `api_error` | **Yes** | Non-deterministic |
| Rate limit | 429 + reset headers | **Yes, if configured** | Per-connection switch; off by default so limits stay visible |
| Model temporarily unavailable | 404/503 on a previously valid model id | **Yes** | Capacity, not configuration |
| Capacity signal (DeepSeek) | `finish_reason: insufficient_system_resource` | **Yes** | Reached us as a 200 — classify it, don't call it `COMPLETE` |
| Auth failure | 401/403 | **No** | Deterministic; a second key hides a broken connection |
| Invalid request | 400/422 | **No** | Our bug — retrying elsewhere multiplies it |
| Content-policy refusal | Anthropic `stop_reason: refusal`, OpenAI `refusal` part | **No** | Deterministic. Falling back re-asks a banned question and bills for it |
| Tenant quota exceeded | our own quota check | **No** | Fallback would defeat the quota |
| Context too large from an app bug | prompt-packing overflow | **No** | Same prompt overflows the fallback too |
| Provider billing exhausted | Anthropic 402 `billing_error`, DeepSeek 402 | **No** | Operator action needed; it will not self-heal |
| User cancellation | client disconnect | **No** | Nobody is waiting |

Every fallback attempt writes a `provider_calls` row of its own with `fallback_metadata` set (docs/11 §16.6). One user turn that falls back once is two rows, not one — otherwise the cost of fallback is invisible.

## Gotchas

- **Billing silently under-counts when a stream is cancelled.** Every provider except Anthropic emits usage only in the final chunk; a client disconnect kills the reader before it arrives, and a `Usage()` of zeros looks exactly like a free call. Emit the terminal `ChatResult` from a `finally` block with `stop_reason=CANCELLED` and `source="estimated"`, populated from the deltas counted so far. Anthropic is the exception worth exploiting: `message_start` carries `input_tokens` up front, so an aborted Anthropic call still yields exact input cost — that path sets `EARLY_INPUT_USAGE` and `source="provider_partial"`. Never aggregate `source="estimated"` rows into invoiced cost.
- **`message_delta.usage` from Anthropic is cumulative; OpenAI's usage chunk is a total.** Summing Anthropic's deltas triple-counts output on a long stream. Assign, don't `+=`, and keep the distinction in the adapter, not the caller.
- **Anthropic's `input_tokens` excludes cached tokens; OpenAI's `cached_tokens` is a subset of `prompt_tokens`; DeepSeek's hit+miss partition it.** Three different arithmetics for one number. Reading `prompt_tokens` as "input" under-reports Anthropic by the entire cached prefix — a 200k-token cached document with a 50-token question reports `input_tokens: 50`. This is why `Usage` buckets are disjoint and `total_input_tokens` is derived.
- **`finish_reason: "length"` mapped to `COMPLETE` truncates answers with no error anywhere.** The same trap in Responses-shaped APIs is `status: "incomplete"` with `incomplete_details.reason: "max_output_tokens"` — and `incomplete_details` has been observed arriving empty while the output is genuinely truncated, so never decide truncation from `status` alone. Anthropic splits the case: `max_tokens` is our cap (`MAX_OUTPUT`), `model_context_window_exceeded` is the model's window (`CONTEXT_EXCEEDED`) — and on recent models an over-budget request is accepted at validation time and stops mid-generation instead of erroring, so a request that used to fail loudly now fails quietly.
- **A refusal that triggers fallback burns money twice for the same answer.** Refusals arrive as HTTP 200, so an adapter that only classifies on status code files them as success and a naive router that only classifies on empty text files them as a fault. Detect them explicitly (`stop_reason: refusal` with `content: []`; a `refusal` content part) and set `StopReason.REFUSAL` — the table above makes them terminal. A mid-stream refusal still bills input plus everything already streamed.
- **OpenRouter drops unsupported parameters silently by default.** `provider.require_parameters` defaults to `false`, so `response_format` or `reasoning` can be discarded by whichever upstream served the request, returning 200 with plausible prose. Always send `provider: {require_parameters: true}`. Recording who actually answered into `Diagnostics.served_by` is mandatory — but the current `Response` type has **no top-level `provider` field**; attribution requires opting in with an `X-OpenRouter-Metadata: enabled` request header and reading `openrouter_metadata` off the **final** streamed chunk. An adapter written against the old field leaves `served_by` permanently null, which silently breaks upstream-scoped breaker keying (`openrouter-api`). Also skip SSE comment lines (`: OPENROUTER PROCESSING`) before parsing, or the reader crashes on the first keep-alive under load.
- **Capability drift is a rejection, not an outage — and it looks like neither.** Providers add parameters faster than adapters learn them, and `extra="forbid"` plus `on_unsupported="reject"` means a new option is a 422 from *us* long before the provider would refuse it. The fix is procedural: adding a capability is a three-step change — a `Capability` member, the adapter's `validate()` mapping, and the `provider_models.capability_flags` migration/backfill. Ship one or two of the three and the flag exists but no model has it, so the feature is dark.
- **`temperature` is not universally accepted, and rejection is a 400, not an ignore.** OpenAI reasoning models reject `temperature`/`top_p` outright; recent Anthropic models reject non-default sampling on *every* request regardless of thinking. Gate on `Capability.SAMPLING` and let `on_unsupported` decide, rather than sending a default `temperature=0.2` everywhere and discovering it per model.
- **"Structured output" is two different capabilities.** Native `json_schema` with schema enforcement is not the same thing as free-form JSON mode, and the JSON Schema subsets are mutually incompatible — OpenAI strict mode requires every field in `required` and `additionalProperties: false` and rejects `allOf`/`if`/`then`; Anthropic accepts `allOf` and `const` but rejects recursion and numeric bounds; DeepSeek offers `json_object` only. **NIM's `nvext` object was removed in NIM LLM 2.0** — its migration guide directs sampling parameters to top-level fields, so an adapter still sending `nvext.guided_json` is writing to a surface that no longer exists (`nvidia-nim-api`). A schema validated against one provider will 400 on another. `STRUCTURED_OUTPUT` vs `JSON_MODE` are separate flags for exactly this reason.
- **`REASONING_TRACE` is separate from `REASONING` because some models bill thinking tokens without returning them.** Anthropic's summarized/omitted thinking display returns blocks with an empty `thinking` field and a populated signature, and no `thinking_delta` events at all — while charging for the full trace. A UI gated on `REASONING` alone renders an empty reasoning pane; gate the pane on `REASONING_TRACE` and the cost line on `reasoning_tokens`.
- **Request-ID headers disagree on spelling and location.** OpenAI `x-request-id`; Anthropic `request-id` (no prefix) *and* `request_id` in the error body; OpenRouter puts a `gen-` prefixed `id` in the body. Read them in the adapter and fill `provider_request_id` on every path including errors — it is the only handle a provider's support will accept. **NIM never synthesizes one, but it does adopt an `X-Request-Id` you send** — so there it is null only if you fail to send it, which makes sending it mandatory rather than optional. <!-- UNVERIFIED: DeepSeek publishes no request-ID header; treat as null -->
- **DeepSeek signals saturation by holding the connection open, not by returning 429.** Over the concurrency limit it keeps the request connected and emits blank lines (non-stream) or `: keep-alive` SSE comments (stream), closing only after ~10 minutes. Three consequences: a reader that does not skip comment lines crashes on the first keep-alive; the first-token timeout is the only saturation detector, so it classifies as `provider_temporary` and a connection configured to fall back on `provider_rate_limit` **never fires**; and TTFT measured on "first chunk" reads as instant. When a 429 *is* returned there are no headers and no `Retry-After` — leave `Diagnostics.rate_limit` empty rather than fabricating a reset time.

## Official docs

- [Anthropic streaming](https://platform.claude.com/docs/en/build-with-claude/streaming), [stop reasons](https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons), [prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching) — event names, the seven stop reasons, and the disjoint cache-token buckets.
- [OpenAI streaming events](https://developers.openai.com/api/reference/resources/responses/streaming-events), [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs) — Responses event families and the strict-mode schema subset.
- [DeepSeek chat completion](https://api-docs.deepseek.com/api/create-chat-completion), [error codes](https://api-docs.deepseek.com/quick_start/error_codes) — `reasoning_content`, the cache hit/miss split, `insufficient_system_resource`.
- [OpenRouter streaming](https://openrouter.ai/docs/api-reference/streaming), [provider routing](https://openrouter.ai/docs/features/provider-routing) — in-band SSE errors, `require_parameters`. [NVIDIA NIM LLM release notes and 2.0 migration guide](https://docs.nvidia.com/nim/large-language-models/latest/release-notes.html) — the `nvext` removal and the vLLM backend switch.

Owned elsewhere: per-provider API surfaces → `openai-api`, `anthropic-api`, `deepseek-api`, `nvidia-nim-api`, `openrouter-api`; error classes and backoff → `kb-error-taxonomy`; Laravel↔FastAPI transport → `kb-internal-api-contracts`; credential encryption → `kb-security-baseline`.

## Definition of done

- [ ] Adapter satisfies `ProviderAdapter`; caller code contains no `if provider == ...` branch.
- [ ] `validate()` covers every optional field in `ChatRequest`; a contract test asserts each unsupported option produces a rejection or a `CapabilityWarning`, and never a dropped field.
- [ ] Every provider stop/finish value maps to a `StopReason`; a table-driven test asserts no length-cap value reaches `COMPLETE`, and that an unknown native value maps to `ERROR` with `native_stop_reason` preserved.
- [ ] Usage buckets verified disjoint against a recorded fixture per provider; `total_input_tokens` matches the provider's own billed input.
- [ ] Cancellation test: abort mid-stream, assert exactly one terminal `ChatResult` with `stop_reason=CANCELLED` and `source != "provider_final"`.
- [ ] Refusal fixture per provider that supports one; asserts `StopReason.REFUSAL` and that the router does **not** fall back.
- [ ] Fallback table exercised end to end: one eligible and one ineligible class per row group, each writing its own `provider_calls` row with `fallback_metadata`.
- [ ] `Diagnostics` snapshot contains no prompt text, no message content, and no credential; asserted by a test that greps the serialized payload for the fixture's system prompt.
- [ ] New capability shipped as all three of: `Capability` member, `validate()` mapping, `provider_models.capability_flags` backfill.

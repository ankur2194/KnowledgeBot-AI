---
name: deepseek-api
description: The DeepSeek translation layer for our provider adapter — deepseek-v4-pro/deepseek-v4-flash, the `thinking` object, `reasoning_content`, and the cache hit/miss token partition. Use whenever editing services/ai-service/app/providers/deepseek.py, mapping DeepSeek finish reasons or usage, or debugging why an OpenAI-SDK call to api.deepseek.com silently ignored a parameter. DeepSeek is OpenAI-compatible in shape only; this file is the divergence list. Pairs with kb-provider-adapter-contract (the contract it implements).
---

# DeepSeek API

DeepSeek API as of **2026-08-04**; models `deepseek-v4-pro` and `deepseek-v4-flash` (currently V4-Flash-0731); OpenAI-shaped endpoint at `https://api.deepseek.com`, called with the `openai` Python SDK (`docs/05-tech-stack.md` §9.4: "official provider SDKs or official documented HTTP clients").
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.4–8.7, docs/05-tech-stack.md §9.4, docs/14-reliability.md §19.4

## Non-negotiables

- **The `openai` SDK client is constructed with `max_retries=0` and an explicit `timeout`.** Its default is 2 internal retries, which multiply against the adapter's 2 (`kb-error-taxonomy`, retry ownership) — up to 9 billed completions from one user click, invisible in our logs and very visible on DeepSeek's balance page.
- **`Usage.input_tokens` is `prompt_cache_miss_tokens`, never `prompt_tokens`.** DeepSeek documents `prompt_tokens = prompt_cache_hit_tokens + prompt_cache_miss_tokens`; the two parts partition the input exactly. Feeding `prompt_tokens` into the disjoint `Usage` buckets of `kb-provider-adapter-contract` double-counts the cached prefix.
- **Capabilities come from `provider_models.capability_flags`, never from the id.** Both V4 models are hybrid: thinking is a per-request `thinking.type`, not a model family. `REASONING` and `SAMPLING` are therefore mutually exclusive on the *same* model — the flags must be resolved per `(model, thinking_enabled)`, and no code may parse `"deepseek-v4-pro"` to infer anything.
- **`finish_reason: "insufficient_system_resource"` arrives as HTTP 200 and is a capacity failure.** Map it to `provider_temporary` (fallback-eligible), never `StopReason.COMPLETE`. `"length"` maps to `MAX_OUTPUT`, never `COMPLETE` (§8.5).
- **The API key stops at the adapter.** It goes into one `AsyncOpenAI(...)` for one call and never into a span attribute, `Diagnostics`, an exception message, or a log line — DeepSeek's 401/422 bodies echo request context, so redact before logging (`kb-security-baseline`, `kb-error-taxonomy`).
- **Retrieved evidence enters as `context_blocks`** and is rendered into its own user-role message section, never appended to `system` (`kb-rag-query-contract`).

## How we use it

`services/ai-service/app/providers/deepseek.py`. One base URL — `https://api.deepseek.com`, the OpenAI-shaped surface. DeepSeek also publishes an Anthropic-shaped surface at `https://api.deepseek.com/anthropic`; **we do not use it** (see Gotchas — it silently remaps unknown model names).

| Model | Context | Max output | Thinking | Concurrency limit | Notes |
|---|---|---|---|---|---|
| `deepseek-v4-flash` | 1M | 384K | `enabled` (default) / `disabled` | 2500 | Silently rolls forward (`…-0731`); pin nothing to the weights |
| `deepseek-v4-pro` | 1M | 384K | `enabled` (default) / `disabled` | 500 | `reasoning_effort: low` is currently mapped up to `high` |

`deepseek-chat` and `deepseek-reasoner` were **retired 2026-07-24**. Any `provider_models` row still carrying them is dead configuration.

```python
# services/ai-service/app/providers/deepseek.py
from openai import AsyncOpenAI, APIStatusError
from .contract import (Capability, CapabilityWarning, ChatRequest, ChatResult, Delta,
                       Diagnostics, ModelCapabilities, StopReason, Usage)

# DeepSeek offers three effort steps plus an off switch. We round DOWN to the nearest
# supported step so a portable request never buys more reasoning than it asked for, and
# we surface the rounding rather than dropping it (§8.6).
_EFFORT = {"low": "low", "medium": "low", "high": "high", "xhigh": "high", "max": "max"}
_ROUNDED = {"medium", "xhigh"}

_STOP = {"stop": StopReason.COMPLETE, "length": StopReason.MAX_OUTPUT,
         "tool_calls": StopReason.TOOL_USE, "content_filter": StopReason.REFUSAL}
# "insufficient_system_resource" is deliberately absent: it is capacity, not a stop reason.


def _client(api_key: str, req: ChatRequest) -> AsyncOpenAI:
    return AsyncOpenAI(
        api_key=api_key, base_url="https://api.deepseek.com",
        max_retries=0,                       # the adapter owns retries; the SDK must not
        timeout=req.timeouts.total,          # DeepSeek holds the socket open under load
    )


def translate_in(req: ChatRequest, caps: ModelCapabilities) -> tuple[dict, list[CapabilityWarning]]:
    warn: list[CapabilityWarning] = []
    thinking_on = req.reasoning is not None and req.reasoning.effort != "none"
    body: dict = {
        "model": req.model,
        "messages": [{"role": "system", "content": req.system}, *render(req.messages, req.context_blocks)],
        "max_tokens": req.max_output_tokens,
        "stream": req.stream,
        # KVCache + scheduling isolation per tenant. org_id is an opaque ULID, so it matches
        # DeepSeek's [a-zA-Z0-9\-_]{,512} charset and carries no PII off-platform.
        "user_id": f"org-{req.org_id.hex}",
    }
    if req.stream:
        # Without this, the stream ends with no usage chunk at all and every call bills as zero.
        body["stream_options"] = {"include_usage": True}

    if thinking_on:
        body["thinking"] = {"type": "enabled", "reasoning_effort": _EFFORT[req.reasoning.effort]}
        if req.reasoning.effort in _ROUNDED:
            warn.append(CapabilityWarning(option="reasoning.effort", action="ignored",
                                          detail=f"{req.reasoning.effort} rounded down to "
                                                 f"{_EFFORT[req.reasoning.effort]}"))
        if req.reasoning.budget_tokens is not None:
            warn.append(CapabilityWarning(option="reasoning.budget_tokens", action="ignored",
                                          detail="DeepSeek exposes effort levels, not a token budget"))
        if req.temperature is not None:
            # Thinking mode accepts temperature/top_p and does NOTHING with them, with no error.
            # Silently forwarding it is the exact silent-drop §8.6 forbids.
            warn.append(CapabilityWarning(option="temperature", action="ignored",
                                          detail="no effect while thinking.type=enabled"))
    else:
        body["thinking"] = {"type": "disabled"}     # explicit: the API default is ENABLED
        if req.temperature is not None:
            body["temperature"] = req.temperature   # DeepSeek's own default is 1.0, not 0

    if req.response_schema is not None:
        # JSON_MODE is the ceiling — there is no json_schema response_format. Schema enforcement
        # is ours, after the fact; caps.on_unsupported decides reject-vs-warn upstream.
        body["response_format"] = {"type": "json_object"}
        warn.append(CapabilityWarning(option="response_schema", action="ignored",
                                      detail="json_object only; schema is not enforced by DeepSeek"))
    if req.cache_hint == "prefix":
        warn.append(CapabilityWarning(option="cache_hint", action="ignored",
                                      detail="DeepSeek caching is automatic and cannot be steered"))
    if req.images:
        warn.append(CapabilityWarning(option="images", action="rejected", detail="text-only"))
    return body, warn


def translate_usage(u) -> Usage:
    """prompt_tokens == hit + miss (DeepSeek's own definition). Our buckets are disjoint,
    so miss is the uncached input and hit is the cache read. There is no cache-write bucket:
    DeepSeek does not bill or report cache writes."""
    hit, miss = u.prompt_cache_hit_tokens, u.prompt_cache_miss_tokens
    assert hit + miss == u.prompt_tokens, "DeepSeek usage partition broken — do not bill this row"
    return Usage(input_tokens=miss, cache_read_tokens=hit, cache_write_tokens=0,
                 output_tokens=u.completion_tokens,
                 reasoning_tokens=(u.completion_tokens_details or {}).get("reasoning_tokens", 0),
                 source="provider_final")


async def stream(self, req, caps, api_key):
    body, warn = translate_in(req, caps)
    diag = Diagnostics(provider="deepseek", warnings=warn)   # rate_limit stays EMPTY — see Gotchas
    emitted, text, usage, native = 0, [], Usage(), None
    try:
        async with _client(api_key, req) as c:
            resp = await c.chat.completions.create(**body)
            async for chunk in resp:
                diag.extras.setdefault("system_fingerprint", chunk.system_fingerprint)
                if chunk.usage:                      # arrives on the final, choice-less chunk
                    usage = translate_usage(chunk.usage)
                if not chunk.choices:
                    continue
                d = chunk.choices[0].delta
                if rc := getattr(d, "reasoning_content", None):
                    yield Delta(kind="reasoning", text=rc)   # gated on REASONING_TRACE in the UI
                if d.content:
                    emitted += 1                     # after this, no retry and no fallback
                    text.append(d.content)
                    yield Delta(kind="text", text=d.content)
                if fr := chunk.choices[0].finish_reason:
                    native = fr
    except APIStatusError as exc:
        raise classify(exc, tokens_emitted=emitted) from None   # never chains the key-bearing request
    finally:
        diag.native_stop_reason = native
        yield ChatResult(text="".join(text), usage=usage, diagnostics=diag,
                         stop_reason=_STOP.get(native, StopReason.ERROR),
                         provider_request_id=None, total_ms=...)   # no request-id header exists
```

**Error map into `kb-error-taxonomy`:** 400 Invalid Format and 422 Invalid Parameters → `provider_permanent_request`; 401 → `provider_auth`; 402 Insufficient Balance → `provider_permanent_request`, never retried and never fallen back (operator action); 429 → `provider_rate_limit`; 500 → `provider_temporary`; 503 Server Overloaded → `provider_temporary`; a 200 with `finish_reason: insufficient_system_resource` → `provider_temporary`. Only the last three are fallback-eligible.

## Gotchas

- **DeepSeek's dashboard shows ~3× the completions your logs do, and users occasionally see the answer twice.** The `openai` SDK retries twice by default and the adapter retries twice on top — and it retries on the *idempotent-looking* connection errors that DeepSeek produces constantly, because it holds the socket open under load. `max_retries=0` at construction, every time, plus the `tokens_emitted == 0` guard that also gates fallback: once DeepSeek has emitted a content delta you have been billed, and a retry or a fallback to another provider bills the whole turn again (`kb-error-taxonomy`).
- **Recorded input tokens run roughly double DeepSeek's invoice on a stable knowledge base.** `total_input_tokens` was computed from `prompt_tokens + prompt_cache_hit_tokens`. The hit count is *already inside* `prompt_tokens` — this is a partition, not a sibling bucket, and it is the opposite of Anthropic, whose `input_tokens` **excludes** cache reads, and a superset of OpenAI, which reports only the hit half. Set `input_tokens = prompt_cache_miss_tokens`, `cache_read_tokens = prompt_cache_hit_tokens`, and assert the sum against `prompt_tokens` in the adapter, not the test.
- **A request hangs for the entire 45 s provider budget and no 429 appears anywhere in the logs.** When you exceed the model's concurrency limit DeepSeek does **not** reject — it keeps the HTTP request connected while it waits for a slot, emitting blank lines on non-streaming calls and `: keep-alive` SSE comments on streaming ones, and closing only after 10 minutes if inference never starts. Three consequences: the SSE reader must skip comment lines before JSON-parsing or it crashes on the first keep-alive under load; the 20 s first-token timeout is the *only* thing that detects saturation, so it classifies as `provider_temporary` and a connection configured to fall back on `provider_rate_limit` never triggers; and TTFT measured on "first chunk received" reads as instant while the user waits (`kb-observability-conventions` — count only chunks with non-empty content).
- **`temperature=0` produces a differently-worded answer on every run, and no error is raised.** Thinking mode is on by default and ignores `temperature`, `top_p`, `presence_penalty` and `frequency_penalty` — DeepSeek's docs say setting them "will not trigger an error but will also have no effect". `frequency_penalty` and `presence_penalty` are additionally marked deprecated-and-ignored on *every* request, thinking or not. Send `thinking: {"type": "disabled"}` explicitly whenever the bot wants deterministic-ish output; leaving the field off gets you thinking mode, which is the reverse of every other provider's default.
- **A tool-calling turn loses the model's plan halfway through and the second tool call is nonsense.** `reasoning_content` has two opposite rules: with no tool call in the turn, echoing it back is silently discarded by the API; with a tool call, the assistant's `reasoning_content` **must** be passed back in every subsequent request of that turn or the model reasons from a hole. So the adapter cannot have one policy — it strips `reasoning_content` when replaying prior conversation turns from PostgreSQL, and preserves it verbatim inside an in-flight tool loop. Never persist it into conversation history that later turns replay.
- **A bot configured for structured output starts returning empty strings a few times a day.** DeepSeek documents that JSON output mode "may occasionally return empty content". Empty content means `tokens_emitted == 0`, so this is one of the rare cases where a retry is legitimately safe — retry once inside the adapter, then surface `provider_permanent_request`. Also: `json_object` requires the literal word "json" plus an example of the shape in the prompt, and there is no `json_schema` form, so `STRUCTURED_OUTPUT` is false and `JSON_MODE` is true for every DeepSeek model. Validate the schema on our side.
- **A typo in a model id bills the cheap model and answers plausibly instead of failing.** On the Anthropic-shaped endpoint (`/anthropic`) DeepSeek maps `claude-opus*` → `deepseek-v4-pro`, `claude-sonnet*`/`claude-haiku*` → `deepseek-v4-flash`, and **anything unrecognised → `deepseek-v4-flash`**. It also ignores `anthropic-version`, `anthropic-beta`, `top_k`, and all `cache_control` fields. This is why we use only the OpenAI-shaped base URL and why a DeepSeek connection must never be configured through the Anthropic adapter. <!-- UNVERIFIED: whether the OpenAI-shaped endpoint rejects an unknown model id with 400 rather than remapping is not documented; assert it with a live fixture before trusting model-id validation to the provider. -->
- **Cache hit rate sits near zero against an unchanging knowledge base, and cost is 50× the projection.** Caching is automatic, on-disk, prefix-match only, and cannot be requested — so anything volatile at the front of the prompt destroys it. Order every request as system instruction → `context_blocks` in a stable, deterministic sort → conversation → the new question, and keep timestamps, trace ids, and request ids out of the prompt entirely. The cache is also best-effort with a construction delay of seconds and a TTL "from a few hours to a few days", so a cold cache is normal and never an error.
- **A `provider_connection` that worked in July returns an error for every request in August.** `deepseek-chat` and `deepseek-reasoner` were fully retired on 2026-07-24. Worse than the hard failure is what preceded it: from 2026-04-24 those ids silently routed to V4-Flash, so cost and answer quality changed under stable configuration months before anything broke. Any model id we accept must be validated against a pinned catalog at connection-save time; `kb-observability-conventions` already maps unrecognised model labels to `other`, which would hide this in metrics.
- **Cost estimates are wrong by exactly 2× for six hours a day.** DeepSeek is introducing peak pricing at 2× the listed rate during 09:00–12:00 and 14:00–18:00 Beijing time (UTC+8), effective date not yet announced. Pricing metadata is "used only for estimated reporting" (§8.4) — keep it that way, and make the estimate time-aware or label it a floor. <!-- UNVERIFIED: not yet in effect as of 2026-08-04; the pricing page says an official announcement is pending. -->
- **`Diagnostics.rate_limit` is empty for DeepSeek and that is correct.** No documented rate-limit headers, no `Retry-After`, and no request-id header — backoff here is blind full-jitter and `provider_request_id` is null (the body's `id` is a completion id, not a support handle). Do not fabricate a reset time from a guess; an invented value outranks the jittered backoff and pins you inside the rejection window. <!-- UNVERIFIED: absence of headers is inferred from their omission across the rate-limit and error-code pages, not from a positive statement. -->
- **An eval baseline drifts overnight with no config change.** `deepseek-v4-flash` is an alias that DeepSeek rolls forward in place (V4-Flash-0731 is the current target) and `deepseek-v4-pro`'s `reasoning_effort` mapping is being changed in early August 2026 — today `low` is served as `high` on pro, so a "cheap" tier costs full price. Record `system_fingerprint` into `Diagnostics.extras` on every call and pin eval runs to it; it is the only observable that changes when the weights do.

## Official docs

- [Chat completion API reference](https://api-docs.deepseek.com/api/create-chat-completion) — parameters, the `thinking` object, `finish_reason` values, and the `usage` object including the cache hit/miss fields.
- [Thinking mode](https://api-docs.deepseek.com/guides/thinking_mode) — `reasoning_content`, the effort mapping table per model, the multi-turn tool-call rule, and the ignored sampling parameters.
- [Context caching](https://api-docs.deepseek.com/guides/kv_cache) — prefix-unit granularity, best-effort semantics, TTL.
- [Rate limits](https://api-docs.deepseek.com/quick_start/rate_limit) — the connection-holding behaviour and the keep-alive shapes. [Error codes](https://api-docs.deepseek.com/quick_start/error_codes) — the seven documented statuses.
- [Models and pricing](https://api-docs.deepseek.com/quick_start/pricing) — context, max output, concurrency limits, cache-hit/miss rates, peak pricing. [Changelog](https://api-docs.deepseek.com/updates) — retirement dates and alias rollovers.
- [JSON output](https://api-docs.deepseek.com/guides/json_mode) — the prompt requirements and the empty-content caveat. [Anthropic-format endpoint](https://api-docs.deepseek.com/guides/anthropic_api) — the model remapping and ignored-field list we avoid.

Owned elsewhere: the request/response shapes and fallback table → `kb-provider-adapter-contract`; error classes, backoff, and timeout budgets → `kb-error-taxonomy`; credential encryption and audit redaction → `kb-security-baseline`; span and metric names → `kb-observability-conventions`.

## Definition of done

- [ ] `AsyncOpenAI` is constructed with `max_retries=0`; a test asserts one adapter attempt produces exactly one outbound HTTP request.
- [ ] A recorded-usage fixture asserts `input_tokens == prompt_cache_miss_tokens`, `cache_read_tokens == prompt_cache_hit_tokens`, and `total_input_tokens == prompt_tokens`.
- [ ] Table-driven `finish_reason` test: `length` → `MAX_OUTPUT`, `content_filter` → `REFUSAL` (no fallback), `insufficient_system_resource` → `provider_temporary` (fallback), unknown → `ERROR` with `native_stop_reason` preserved.
- [ ] Streaming request always sets `stream_options.include_usage`; a test asserts a non-zero `Usage` on the terminal `ChatResult`.
- [ ] SSE reader skips `:` comment lines; a fixture interleaving `: keep-alive` with data chunks parses cleanly.
- [ ] `thinking.type` is set explicitly on every request; a test asserts `temperature` is never sent while thinking is enabled and always produces a `CapabilityWarning`.
- [ ] `reasoning_content` is preserved inside an in-flight tool loop and stripped when replaying persisted conversation turns; one test per direction.
- [ ] Cancellation test: abort mid-stream, assert one terminal `ChatResult` with `stop_reason=CANCELLED` and `source != "provider_final"`.
- [ ] A serialized `Diagnostics` payload for a failing 401 contains no API key, no prompt text, and no `Authorization` header; asserted by grepping the payload for the fixture key.
- [ ] Model ids validated against the pinned catalog at connection save; `deepseek-chat`/`deepseek-reasoner` are rejected with a message naming the 2026-07-24 retirement.

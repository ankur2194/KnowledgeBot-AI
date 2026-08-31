---
name: openai-api
description: The OpenAI adapter in services/ai-service/app/providers/openai_adapter.py — Responses API, streaming event translation, usage and cached-token math, reasoning effort, and the status/code to error_class map. Use whenever editing that adapter, pinning a gpt-5.x model id, debugging a truncated or empty OpenAI answer, or reading token counts off a stream. OpenAI's cached_tokens is a SUBSET of input_tokens, unlike Anthropic's sibling buckets. Pairs with kb-provider-adapter-contract (the shape it translates into).
---

# OpenAI API — KnowledgeBot Adapter

`openai` Python SDK **2.53.0** (2026-08-03, requires Python ≥ 3.10), **Responses API** (`POST /v1/responses`). Verified against the SDK changelog and developers.openai.com on 2026-08-04.
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.4–8.7, docs/05-tech-stack.md §9.5, docs/14-reliability.md §19.2/§19.4

## Non-negotiables

- **Target the Responses API, not Chat Completions.** OpenAI's own migration guide: *"While Chat Completions remains supported, Responses is recommended for all new projects"* — no sunset date is published for Chat Completions, but every new capability (reasoning-item reuse, `prompt_cache_key`, hosted tools) lands on Responses first, and OpenAI measures 40–80% better cache utilisation on it. Chat Completions appears in this skill only as the shape you will find in stale blog posts.
- **`store=False` on every request.** The API default is `true`, which retains the full request and response — the tenant's retrieved document text and the end user's question — on OpenAI's servers for 30 days and makes it readable from the org's dashboard. That is tenant content crossing a boundary the org's privacy switches (`kb-security-baseline` §18.10) never authorised. It also forecloses `previous_response_id`/`conversation`, which is correct: PostgreSQL is the source of truth for conversation state, not OpenAI.
- **The API key lives inside the `AsyncOpenAI` client and nowhere else.** Not in a span attribute, a log line, `Diagnostics.extras`, or an exception message — OpenAI 401 bodies do not echo the key, but our own `repr()` of a mis-constructed client would. See `kb-provider-adapter-contract`.
- **`max_retries=0` on the client.** The SDK retries 2× by default; the adapter policy loop retries 2× (`kb-error-taxonomy`, "Retry ownership"); the browser used to retry 3×. Left alone that is 3 × 3 × 3 = 27 billed provider calls from one user click.
- **`truncation="disabled"`, always.** `"auto"` drops items from the *start* of `input`, which is exactly where the retrieved evidence sits — the model then answers from a context the citation map no longer describes, and citations point at text that was never sent.
- **Capabilities come from `provider_models.capability_flags`, never from the model id.** `"gpt-5.6-luna"` tells you nothing about whether this deployment accepts `temperature`.

## How we use it

Models pinned in the catalog as of 2026-08: **`gpt-5.6-sol`** (alias `gpt-5.6`), **`gpt-5.6-terra`**, **`gpt-5.6-luna`** — all 1,050,000-token context, 128,000 max output, knowledge cutoff 2026-02-16. `gpt-5.5`, `gpt-5.4{,-mini,-nano,-pro}`, `gpt-5.2`, `gpt-5.1`, `gpt-5{,-mini,-nano,-pro}` and `gpt-4.1`/`gpt-4o` remain callable and remain valid catalog rows for tenants who pinned them. There are no dated snapshot aliases for the 5.6 line — `gpt-5.6` floats to `gpt-5.6-sol`, so store the resolved id, and read `response.model` back into `gen_ai.response.model`.

```python
# services/ai-service/app/providers/openai_adapter.py
from __future__ import annotations
import asyncio, hashlib, time
from typing import Any, AsyncIterator
import httpx
from openai import AsyncOpenAI, APIConnectionError, APIStatusError, APITimeoutError
from .contract import (Capability, CapabilityWarning, ChatRequest, ChatResult, Delta,
                       Diagnostics, ModelCapabilities, StopReason, Usage)

_RL_HEADERS = ("x-ratelimit-remaining-requests", "x-ratelimit-reset-requests",
               "x-ratelimit-remaining-tokens", "x-ratelimit-reset-tokens", "retry-after")

class OpenAIAdapter:
    name = "openai"

    def __init__(self, api_key: str, base_url: str | None, t) -> None:
        self._c = AsyncOpenAI(
            api_key=api_key, base_url=base_url, max_retries=0,   # see Non-negotiables
            # SDK default is a 600 s flat timeout — 13× our 45 s provider budget. `read` is
            # per-chunk, not total, so a slow stream never trips it; the caller's asyncio
            # deadline is what enforces the total (kb-error-taxonomy §19.4).
            timeout=httpx.Timeout(connect=t.connect, read=t.first_token, write=10.0, pool=5.0),
        )

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        p: dict[str, Any] = {
            "model": req.model,
            "instructions": req.system,        # bot instruction ONLY — never retrieved text
            "input": [*self._context_items(req.context_blocks), *self._messages(req.messages)],
            "max_output_tokens": req.max_output_tokens,   # INCLUDES reasoning tokens
            "stream": True,
            "store": False,
            "truncation": "disabled",
            # Cache routing hashes the first ~256 tokens and needs a stable ≥1024-token prefix;
            # gpt-5.6+ needs this key for reliable matching. Keyed on the bot, because the bot's
            # instruction + context layout is what is actually identical across requests.
            # Not conversation_id (too sparse to ever hit) and not org_id (>15 req/min per key).
            "prompt_cache_key": f"kb:{req.bot_id}",
            # Abuse-signal handle only. A one-way hash — never an email, user id, or org id,
            # all of which would leave our boundary as plaintext (kb-security-baseline).
            "safety_identifier": hashlib.sha256(f"{req.org_id}".encode()).hexdigest()[:32],
        }
        if req.reasoning and Capability.REASONING in caps.supported:
            # OpenAI's ladder is none|minimal|low|medium|high|xhigh|max, per-model subsets.
            # Our contract has no `minimal`; a model without `none` gets `minimal` + a warning.
            p["reasoning"] = {"effort": req.reasoning.effort}
            if req.reasoning.include_trace and Capability.REASONING_TRACE in caps.supported:
                p["reasoning"]["summary"] = "auto"
        if req.temperature is not None and Capability.SAMPLING in caps.supported:
            p["temperature"] = req.temperature
        if req.response_schema and Capability.STRUCTURED_OUTPUT in caps.supported:
            # Responses nests differently from Chat Completions: no `json_schema` wrapper
            # object, and `name` is required. Copying the Chat shape here is a 400.
            p["text"] = {"format": {"type": "json_schema", "name": "answer",
                                    "strict": True, "schema": req.response_schema}}
        return p

    async def stream(self, req: ChatRequest, caps: ModelCapabilities
                     ) -> AsyncIterator[Delta | ChatResult]:
        t0, first_ms, emitted, parts = time.monotonic(), None, 0, []
        usage, stop, err = Usage(), StopReason.ERROR, None
        diag, request_id = Diagnostics(provider=self.name), None
        try:
            s = await self._c.responses.create(**self._translate_in(req, caps))
            # `.response` is the live httpx.Response: the request id and rate-limit headers are
            # readable BEFORE the first token and survive a stream that dies mid-flight.
            request_id = s.response.headers.get("x-request-id")
            diag.rate_limit = {h: v for h in _RL_HEADERS
                               if (v := s.response.headers.get(h)) is not None}
            async for ev in s:
                if ev.type == "response.output_text.delta":
                    if first_ms is None and ev.delta:      # empty first deltas are common
                        first_ms = int((time.monotonic() - t0) * 1000)
                    emitted += 1; parts.append(ev.delta)
                    yield Delta(kind="text", text=ev.delta)
                elif ev.type == "response.reasoning_summary_text.delta":
                    yield Delta(kind="reasoning", text=ev.delta)
                elif ev.type == "response.refusal.delta":
                    yield Delta(kind="refusal", text=ev.delta)
                elif ev.type in ("response.completed", "response.incomplete", "response.failed"):
                    usage, stop = self._usage(ev.response.usage), self._stop(ev.response)
                    diag.native_stop_reason = ev.response.status
                    diag.extras["response_model"] = ev.response.model
                    if stop is StopReason.ERROR and ev.response.error:
                        err = "provider_permanent_request"   # unknown ⇒ permanent, never retry
        except APIStatusError as exc:
            request_id, err = exc.request_id, self.classify(exc)
        except (APIConnectionError, APITimeoutError):
            err = "provider_temporary"
        except asyncio.CancelledError:
            # Yield the terminal event HERE, then re-raise. Never from `finally`: an async
            # generator that yields while unwinding GeneratorExit raises RuntimeError and the
            # terminal event — and the usage row — is simply lost.
            yield self._result(parts, StopReason.CANCELLED, usage, request_id, first_ms,
                               t0, diag, "user_cancellation", estimated=True)
            raise
        yield self._result(parts, stop, usage, request_id, first_ms, t0, diag, err,
                           estimated=err is not None)

    @staticmethod
    def _usage(u) -> Usage:
        if u is None:
            return Usage()
        cached = getattr(u.input_tokens_details, "cached_tokens", 0) or 0
        return Usage(
            input_tokens=u.input_tokens - cached,   # cached_tokens ⊆ input_tokens — SUBTRACT
            cache_read_tokens=cached,
            cache_write_tokens=0,                   # OpenAI never reports writes (Gotchas)
            output_tokens=u.output_tokens,
            reasoning_tokens=getattr(u.output_tokens_details, "reasoning_tokens", 0) or 0,
            source="provider_final")

    @staticmethod
    def _stop(r) -> StopReason:
        if any(getattr(p, "type", None) == "refusal"
               for it in r.output for p in (getattr(it, "content", None) or [])):
            return StopReason.REFUSAL
        if r.status == "incomplete":
            reason = getattr(r.incomplete_details, "reason", None)
            if reason == "content_filter":
                return StopReason.REFUSAL
            return StopReason.MAX_OUTPUT        # empty incomplete_details still means truncated
        return StopReason.ERROR if r.status == "failed" else StopReason.COMPLETE
```

### Status and code → `error_class`

`error_class` is decided on the SDK exception **plus the body's `code`**, never the status alone.

| Signal | `error_class` | Fallback |
|---|---|---|
| `APIConnectionError`, `APITimeoutError`, no first token in budget | `provider_temporary` | yes |
| `InternalServerError` (500 `server_error`, 503 overloaded) | `provider_temporary` | yes |
| `RateLimitError` 429 `rate_limit_exceeded` | `provider_rate_limit` | when configured |
| **`RateLimitError` 429 `insufficient_quota`** | **`provider_billing`** | **no** — pages immediately |
| `AuthenticationError` 401, `PermissionDeniedError` 403 | `provider_auth` | no |
| `NotFoundError` 404 `model_not_found` — **whether or not** the model is in our catalog | `provider_permanent_request` | **no** (ADR-014) |
| 503, `InternalServerError` 500 `server_error` | `provider_temporary` | yes — this is the capacity signal §8.7 means |
| `BadRequestError` 400 (`context_length_exceeded`, `unsupported_value`, bad schema) | `provider_permanent_request` | no |
| `status="failed"`, or any unmapped code | `provider_permanent_request` + alert | no |

The 429 split is the one that costs money. Both arrive as `RateLimitError`, so a bare `except RateLimitError` handler treats an exhausted billing account as a transient limit: it retries, falls back if fallback is on, and the org never sees the one message that would fix it. `insufficient_quota` never self-heals. **It maps to `provider_billing`, a real row in the taxonomy** — `app/core/errors.py` renders it `502`, `RETRYABLE` is `False`, `FALLBACK_ELIGIBLE` is `False`, and `app/providers/errors.py` keeps it out of `BREAKER_ELIGIBLE` because one credential being out of money must not take chat down for every tenant. The shipped mapping is `app/providers/openai_adapter.py`'s `BODY_CODE_TO_CLASS`: `"insufficient_quota": ErrorClass.PROVIDER_BILLING`. **`provider_auth` is the wrong answer and was this file's earlier one**: it is defined as "provider rejected our credential", which is narrower, and it would put a second spelling of one condition into a table Python, PHP and TypeScript each transcribe. Anthropic and DeepSeek express the same state as `402` and OpenRouter as `payment_required`; all four land on the same class.

## Gotchas

- **A reasoning model returns an empty answer, `status: "incomplete"`, and a full bill.** `max_output_tokens` is an upper bound on *visible output plus reasoning tokens*, and reasoning is generated first. At `effort: "high"` a 1,024-token cap is consumed entirely by invisible thinking; the user sets nothing, sees nothing, and pays for all of it. Either keep the chat path at `effort` `none`/`minimal`, or size `max_output_tokens` as answer budget + reasoning budget and treat a `MAX_OUTPUT` stop with zero text as an error, not an answer.
- **Token spend is 3–27× the request count and the provider dashboard disagrees with your logs.** `AsyncOpenAI(...)` defaults to `max_retries=2` and retries connection errors, 408, 409, 429 and every 5xx with its own backoff — invisibly, inside one `await`, so your span shows one call. Construct with `max_retries=0`; a CI test asserts it on every provider client.
- **A stream hangs for ten minutes and the outer request 504s while tokens keep being billed.** The SDK's default timeout is 600 s, and `httpx`'s `read` timeout is *between chunks*, not total — a model emitting one token every 15 s never trips a 20 s read timeout. Set `httpx.Timeout(connect=3, read=first_token)` **and** wrap the whole `async for` in the caller's absolute deadline.
- **Billing under-reports the cached prefix by exactly the cached amount — or double-counts it.** `usage.input_tokens_details.cached_tokens` is a **subset** of `usage.input_tokens` (OpenAI's own wording: "part of the total input_tokens count"). Our `Usage` buckets are disjoint, so the adapter subtracts. Anthropic's are siblings and must be added instead; DeepSeek's hit/miss partition. Getting this backwards on a 200k-token cached document is a five-figure reporting error in whichever direction you guessed.
- **Cache-write cost is attributable only when the SDK reports it, and it must never be synthesised.** On gpt-5.6+ a cache write bills at 1.25× the uncached input rate. This bullet said the API reports no write count at all until 2026-08-27; `openai==2.53.0` added `InputTokensDetails.cache_write_tokens` and `openai_adapter._usage` reads it. Absent the field the bucket stays 0 and the shortfall is reconciled against the OpenAI billing export monthly — that half of the old wording is the durable half. Whether the reported amount is a **subset** of `input_tokens` or a **sibling** is decided from the numbers, not assumed: `_usage` falls back to subset-free arithmetic when `cached + written` cannot fit inside the reported input, and `total_input_tokens` equals the vendor's billed input on both branches, so a wrong reading costs attribution and never the bill.
- **Cache hit rate reads 0% with a perfectly stable system prompt.** Three causes, all silent: the prefix is under the 1,024-token minimum (a hard floor on gpt-5.6+); something varies inside the first ~256 tokens — a timestamp, a `conversation_id`, a re-ordered context block — because matching is exact-prefix, not semantic; or the entries expired (30 min TTL, and `prompt_cache_retention` is deprecated for 5.6+). Put the bot instruction and the retrieved blocks first and the user turn last, and never interpolate anything per-request into the prefix.
- **`temperature: 0.2` sent everywhere returns 400 `unsupported_value` on exactly the models you most want.** The 5.x reasoning line rejects sampling parameters outright rather than ignoring them — and it is a hard failure at request time, so it looks like an outage on the fallback dashboard. Gate on `Capability.SAMPLING` and let `on_unsupported` decide (`kb-provider-adapter-contract`).
- **A structured-output schema that passes review 400s at runtime.** Strict mode requires every property in `required`, `additionalProperties: false` on every object, an object (not `anyOf`) at the root, and rejects `allOf`, `not`, `if`/`then`/`else` and `dependentRequired`; limits are 5,000 properties, 10 levels, 1,000 enum values. A Pydantic model with an `Optional[str]` field emits neither `required` nor a null union unless you configure it to. Generate the schema, then validate it against these rules in a test — the model is not the schema.
- **The terminal event vanishes on cancellation and usage is never written.** Yielding a `ChatResult` from a `finally` block in an async generator raises `RuntimeError: async generator ignored GeneratorExit` during unwinding, which is swallowed by the ASGI layer. Catch `asyncio.CancelledError`, yield the terminal result, then re-raise — and have the consumer `await gen.aclose()` rather than `break` out of the `async for`.
- **A refusal or a content filter is filed as a successful, empty answer.** Both arrive as HTTP 200: a `refusal` content part inside `response.output`, or `status: "incomplete"` with `incomplete_details.reason: "content_filter"`. Classifying on status code alone marks them `COMPLETE`, and the router — seeing empty text — falls back and re-asks the banned question on another provider, billing twice for the same refusal.
- **`x-request-id` is missing on precisely the failures support asks about.** On a non-streaming call it is `response._request_id`; on an error it is `exc.request_id`; on a stream it is only reachable through the stream object's `.response.headers`, and only if you capture it before iterating. Capture it immediately after `create()` returns, together with the `x-ratelimit-*` headers — the SDK reads none of them for you beyond honouring `Retry-After` on its own (disabled) retries.
- **A background job silently keeps running after the client leaves.** `background=True` detaches the response from the connection; it is genuinely useful for the eval harness and completely wrong for chat, where cancellation must stop the spend. Never set it on the chat path.

## Official docs

- [Migrate to the Responses API](https://developers.openai.com/api/docs/guides/migrate-to-responses) — the recommendation, and the Chat Completions parameter mapping. [Models](https://developers.openai.com/api/docs/models) and [pricing](https://developers.openai.com/api/docs/pricing) — the current lineup and per-model effort/parameter support.
- [Streaming responses](https://developers.openai.com/api/docs/guides/streaming-responses) and [streaming event reference](https://developers.openai.com/api/reference/resources/responses/streaming-events) — the full `response.*` event union.
- [Prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching) — the 1,024-token floor, `prompt_cache_key`, and where `cached_tokens` sits. [Data controls](https://developers.openai.com/api/docs/guides/your-data) — 30-day retention, `store`, ZDR.
- [Reasoning](https://developers.openai.com/api/docs/guides/reasoning) — the seven effort levels, summaries, encrypted reasoning items. [Structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs) — the strict-mode schema subset.
- [Rate limits](https://developers.openai.com/api/docs/guides/rate-limits) — the `x-ratelimit-*` header set and `Retry-After`. **Reset headers here are `x-ratelimit-reset-{bucket}` — `reset` prefixes, the bucket trails — and the value is a Go duration (`6m0s`, `88ms`, `1.5s`).** Anthropic's are the mirror image (`anthropic-ratelimit-{bucket}-reset`, RFC 3339) and OpenRouter's is an epoch; a matcher written for one family silently reads nothing from the others, so dispatch on the full header name via `app/providers/errors.py`'s `RESET_HEADERS`, never on a `reset` substring. [openai-python README](https://github.com/openai/openai-python) + [CHANGELOG](https://github.com/openai/openai-python/blob/main/CHANGELOG.md) — retries, timeouts, exception hierarchy, version pin.

## Definition of done

- [ ] `openai==2.53.*` pinned; client constructed with `max_retries=0` and an explicit `httpx.Timeout` — asserted by a test that inspects the client.
- [ ] Every request carries `store=False`, `truncation="disabled"`, `prompt_cache_key`, and a hashed `safety_identifier`; `previous_response_id`, `conversation` and `background` appear nowhere in the chat path.
- [ ] Recorded-fixture test: `input_tokens + cache_read_tokens` equals the provider's reported `usage.input_tokens`, and `reasoning_tokens ≤ output_tokens`.
- [ ] Table-driven stop-reason test covers `completed`, `incomplete`/`max_output_tokens`, `incomplete`/`content_filter`, empty `incomplete_details`, a `refusal` part, and `failed` — no path reaches `COMPLETE` for a truncated response.
- [ ] Error-map test asserts 429 `rate_limit_exceeded` → `provider_rate_limit` and 429 `insufficient_quota` → **`provider_billing`** (never `provider_auth`), with different retry **and** fallback outcomes: the rate limit is retryable and fallback-eligible when configured, the billing state is neither and does not open the breaker.
- [ ] Cancellation test: abort mid-stream, assert exactly one terminal `ChatResult` with `CANCELLED` and `source != "provider_final"`, and no `RuntimeError` in the log.
- [ ] Strict-schema validator runs over every `response_schema` the bot config can produce.
- [ ] `Diagnostics` and log snapshots grepped for the fixture API key, the system prompt, and any context-block text — all absent.

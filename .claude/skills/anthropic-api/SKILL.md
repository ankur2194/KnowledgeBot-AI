---
name: anthropic-api
description: Anthropic Claude adapter in services/ai-service/app/providers/anthropic.py — official `anthropic` Python SDK, adaptive thinking, `output_config.effort`, prompt caching, stream usage. Use whenever translating a ChatRequest for Claude, mapping an Anthropic stop reason or error into our taxonomy, or debugging token counts, empty reasoning panes, or truncated answers. Anthropic's `input_tokens` excludes cached tokens, unlike every other provider here. Pairs with kb-provider-adapter-contract (the shape this implements).
---

# Anthropic Claude — Provider Adapter

`anthropic` Python SDK **0.120.2** (PyPI, verified 2026-08-04), API version header `2023-06-01`, CPython ≥ 3.12.4 (`kb-security-baseline`). One module: `services/ai-service/app/providers/anthropic.py`.
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.5–8.7, docs/05-tech-stack.md §9.5, docs/14-reliability.md §19.2 §19.4

## Non-negotiables

- **`max_retries=0` on every `AsyncAnthropic`.** The SDK default is **2**, so a single user click can become 3 (browser) × 3 (adapter) × 3 (SDK) = **27** provider calls — and 26 of them are invisible in our own logs. Only `call_with_policy` retries (`kb-error-taxonomy`, retry ownership). We set `max_retries=0`, `retry: false` on the chat mutation, and cap the adapter at 1 try + 2 retries.
- **`effort` is nested inside `output_config`, never a top-level request parameter.** `output_config={"effort": "xhigh"}`. A top-level `effort=` is a `TypeError` from the SDK signature and a 400 on raw HTTP. `output_config` also carries `format` for structured outputs, so it is not thinking-only.
- **Extended thinking is `thinking={"type": "adaptive"}`.** `{"type": "enabled", "budget_tokens": N}` is **rejected with a 400** on Fable 5 / Sonnet 5 / Opus 5 / 4.8 / 4.7 and deprecated on 4.6. `ReasoningOption.budget_tokens` therefore never reaches this adapter — it becomes a `CapabilityWarning`, never a request field.
- **Anthropic's `input_tokens` counts only the tokens after the last cache breakpoint.** `cache_read_input_tokens` and `cache_creation_input_tokens` are *siblings*, not subsets. Total input is the sum of all three. OpenAI's `cached_tokens` is a subset of `prompt_tokens` and DeepSeek partitions its count — three arithmetics, one field name. Our `Usage` buckets are already disjoint, so the Anthropic mapping is a straight copy; the bug is "helpfully" subtracting.
- **The decrypted key exists only inside `stream()`.** It is passed to a per-call `AsyncAnthropic` and never stored on the adapter, put in a span attribute, a `Diagnostics` field, or a log line. Log `exc.type`, `exc.status_code`, `exc.request_id` — never the exception object, whose `repr` can carry request state (`kb-security-baseline` §18.2).
- **Retry and fallback both die at the first emitted token.** Anthropic bills what it streamed; a retry re-bills and the user sees the answer restart. The adapter carries a live `emitted` counter into every raised `KbError`, and both `call_with_policy` and the fallback router read it — `emitted > 0` means terminate the turn with a stream error event, whatever the error class says.
- **`gen_ai.usage.input_tokens` and `kb_provider_tokens_total{token_type="input"}` take `Usage.total_input_tokens`, not the raw field.** Reporting Anthropic's `input_tokens` under-counts a cached turn by the entire prefix (`kb-observability-conventions`).

## How we use it

Models are catalogued in `provider_models`; the adapter reads `capability_flags` and never parses the id string.

| Model id | Context | Max output | Adapter-relevant |
|---|---|---|---|
| `claude-opus-5` | 1M | 128K | Default. Thinking is **on when `thinking` is omitted**; `{"type":"disabled"}` is a 400 above effort `high`. 512-token cache minimum. |
| `claude-sonnet-5` | 1M | 128K | High-volume tier. 1024-token cache minimum. |
| `claude-haiku-4-5` | 200K | 64K | Pre-4.6: `output_config.effort` **errors**. Catalogue it *without* `Capability.REASONING` so the adapter omits `thinking` and `effort` both; we never ship the deprecated `budget_tokens` shape. |

```python
# services/ai-service/app/providers/anthropic.py
import asyncio, time
from typing import Any, AsyncIterator

import anthropic
from anthropic import AsyncAnthropic, DefaultAsyncHttpxClient

from ..errors import KbError
from .contract import (Capability, CapabilityWarning, ChatRequest, ChatResult, Delta,
                       Diagnostics, ModelCapabilities, StopReason, Usage)

# One connection pool for the process; the *client wrapper* is per-call so the key stays
# request-scoped. A fresh pool per turn costs a TCP+TLS handshake against the 4 s TTFT budget.
_POOL = DefaultAsyncHttpxClient()

# Our six-level effort onto Anthropic's five. `none` is the only lossy step: Opus 5 accepts
# thinking {"type": "disabled"} ONLY at effort <= high, so `none` also pins effort to "high".
# `xhigh` maps 1:1 — that is exactly why the contract enum carries it.
_EFFORT = {"none": "high", "low": "low", "medium": "medium",
           "high": "high", "xhigh": "xhigh", "max": "max"}

_STOP = {"end_turn": StopReason.COMPLETE, "stop_sequence": StopReason.STOP_SEQUENCE,
         "tool_use": StopReason.TOOL_USE, "pause_turn": StopReason.TOOL_USE,
         "refusal": StopReason.REFUSAL,
         "max_tokens": StopReason.MAX_OUTPUT,                     # OUR cap -> truncated answer
         "model_context_window_exceeded": StopReason.CONTEXT_EXCEEDED}


class AnthropicAdapter:
    name = "anthropic"

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        warnings: list[CapabilityWarning] = []
        for option, needed in (("temperature", Capability.SAMPLING),
                               ("response_schema", Capability.STRUCTURED_OUTPUT),
                               ("images", Capability.IMAGE_INPUT)):
            if not getattr(req, option) or needed in caps.supported:
                continue
            if caps.on_unsupported == "reject":
                raise KbError("validation", detail=f"{req.model} does not accept {option}")
            warnings.append(CapabilityWarning(option=option, action="ignored",
                                              detail=f"{req.model} rejects {option}"))
        if req.reasoning and req.reasoning.budget_tokens is not None:
            warnings.append(CapabilityWarning(option="reasoning.budget_tokens", action="ignored",
                            detail="removed on Claude 4.7+; effort is the only depth control"))
        return warnings

    def _build(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        system = [{"type": "text", "text": req.system}]           # bot instruction ONLY
        if req.cache_hint == "prefix":
            system[-1]["cache_control"] = {"type": "ephemeral"}   # last STABLE block; see Gotchas
        kwargs: dict[str, Any] = {
            "model": req.model, "max_tokens": req.max_output_tokens, "system": system,
            "messages": build_messages(req),   # evidence = data blocks; kb-rag-query-contract §14
        }
        if Capability.REASONING in caps.supported:
            kwargs["thinking"] = {
                "type": "adaptive",            # NOT {"type": "enabled", "budget_tokens": N} -> 400
                # Default is "omitted": blocks arrive empty with no thinking_delta, still billed.
                "display": "summarized" if (req.reasoning and req.reasoning.include_trace) else "omitted",
            }
            kwargs["output_config"] = {"effort": _EFFORT[req.reasoning.effort if req.reasoning else "high"]}
        if req.response_schema and Capability.STRUCTURED_OUTPUT in caps.supported:
            kwargs.setdefault("output_config", {})["format"] = {
                "type": "json_schema", "schema": req.response_schema}
        if req.temperature is not None and Capability.SAMPLING in caps.supported:
            kwargs["temperature"] = req.temperature
        return kwargs

    async def stream(self, req: ChatRequest, caps: ModelCapabilities
                     ) -> AsyncIterator[Delta | ChatResult]:
        diag = Diagnostics(provider="anthropic", warnings=self.validate(req, caps))
        usage, stop, emitted, parts = Usage(), StopReason.ERROR, 0, []
        started, first_ms, err_class = time.monotonic(), None, None
        client = AsyncAnthropic(
            api_key=await self._secret(req.provider_connection_id),  # lives only in this frame
            max_retries=0, timeout=req.timeouts.total, http_client=_POOL,
        )
        try:
            async with client.messages.stream(**self._build(req, caps)) as stream:
                diag.rate_limit = parse_limits(stream.response.headers)   # 200s carry them too
                diag.extras["request_id"] = stream.request_id
                async for event in stream:
                    # The SDK fires the raw event AND a synthetic helper (`text`, `thinking`,
                    # `input_json`) for the SAME token. Match raw types only, or emit twice.
                    if event.type == "message_start":
                        # Anthropic is the only provider we call that reports input usage before
                        # generation — an aborted turn still bills exactly.
                        usage = to_usage(event.message.usage, "provider_partial")
                    elif event.type == "message_delta":
                        # usage here is CUMULATIVE, not a delta: assign, never `+=`. Input fields
                        # are present on some models and absent on others — merge only non-None.
                        usage = merge_usage(usage, event.usage, source="provider_final")
                        diag.native_stop_reason = event.delta.stop_reason
                        stop = _STOP.get(event.delta.stop_reason, StopReason.ERROR)
                    elif event.type == "content_block_delta":
                        d = event.delta
                        if d.type == "text_delta":
                            first_ms = first_ms or int((time.monotonic() - started) * 1000)
                            emitted += 1
                            parts.append(d.text)
                            yield Delta(kind="text", text=d.text, index=event.index)
                        elif d.type == "thinking_delta":
                            yield Delta(kind="reasoning", text=d.thinking, index=event.index)
        except anthropic.APIStatusError as exc:
            diag.extras["request_id"] = exc.request_id
            err_class = classify(exc, emitted).error_class      # never re-raise past the yield
        except anthropic.APIConnectionError as exc:             # APITimeoutError subclasses this
            err_class = "provider_temporary"
            diag.extras["transport"] = type(exc).__name__
        except asyncio.CancelledError:
            # Yielding from `finally` while CancelledError unwinds raises GeneratorExit — see Gotchas.
            self._finalize(req, ChatResult(text="".join(parts), stop_reason=StopReason.CANCELLED,
                                           usage=usage, total_ms=_ms(started), diagnostics=diag))
            raise
        yield ChatResult(text="".join(parts),
                         stop_reason=StopReason.ERROR if err_class else stop,
                         usage=usage, error_class=err_class,
                         provider_request_id=diag.extras.get("request_id"),
                         first_token_ms=first_ms, total_ms=_ms(started), diagnostics=diag)


def classify(exc: anthropic.APIStatusError, emitted: int) -> KbError:
    """Body `error.type` first, status code second — a mid-stream SSE `error` event is raised
    against the ORIGINAL 200 response, so `exc.status_code` reads 200, not 529."""
    kind = exc.type or ""
    if kind in ("overloaded_error", "api_error", "timeout_error"):        # 529 / 500 / 504
        return KbError("provider_temporary", emitted=emitted, retry_after=retry_after(exc))
    if kind == "rate_limit_error":
        return KbError("provider_rate_limit", emitted=emitted, retry_after=retry_after(exc))
    if kind == "billing_error": return KbError("provider_billing", emitted=emitted)  # 402 — NOT provider_auth
    if kind in ("authentication_error", "permission_error"): return KbError("provider_auth", emitted=emitted)  # 401/403
    return KbError("provider_permanent_request", emitted=emitted, detail=kind)  # incl. unknown
```

## Gotchas

- **Every token renders twice in the widget.** Iterating `async for event in stream` yields the raw `content_block_delta` **and** a synthetic helper event (`text`, `thinking`, `signature`, `input_json`, `citation`) built from the same delta. Match on raw event types only, or use `stream.text_stream` — never both.
- **A mid-stream overload escapes `except OverloadedError` and `exc.status_code` reads 200.** An SSE `event: error` is raised via `_make_status_error(response=<the original 200>)`, so it downgrades to a bare `APIStatusError`. Branch on `exc.type` (`"overloaded_error"`), not the status code, or a routine capacity blip is filed as `provider_permanent_request` and pages someone.
- **The provider dashboard shows ~3× our logged request count during a brownout.** `max_retries` defaults to **2** and the SDK's retries never appear in our spans. Set it to `0` at construction; the adapter owns retry (`kb-error-taxonomy`).
- **TTFT regresses ~150 ms per turn after credentials were moved per-call.** A fresh `AsyncAnthropic()` builds a fresh httpx pool, so every turn pays a TCP+TLS handshake against the 4 s budget (§23). Share one `DefaultAsyncHttpxClient` process-wide, construct the wrapper per call — and never `await client.close()`, which would close the shared pool for everyone.
- **Cache hit rate sits at 0% and cost went up.** `cache_control` was put on the retrieved context blocks. Evidence differs per question, so every turn writes a new entry at ~1.25× and never reads one. It belongs on the last **system** block only. Second cause with the same symptom and no error: the prefix is below the model's minimum — **512** tokens on Opus 5, **1024** on Sonnet 5 / Opus 4.8, **4096** on Opus 4.6 / Haiku 4.5 — so a short bot instruction silently reports `cache_creation_input_tokens: 0`.
- **Billing under-reports a cached turn by the entire prefix.** `input_tokens` is only the tokens after the last breakpoint: a 200k-token cached document with a 50-token question reports `input_tokens: 50`. Bill from `total_input_tokens`. Separately, the *rate limiter* sees a third number — `cache_read_input_tokens` does **not** count toward ITPM on any model we ship, so ITPM headroom is `input_tokens + cache_creation_input_tokens`, and a dashboard built on either of the other two totals will disagree with the 429s.
- **The reasoning pane is empty while `reasoning_tokens` climbs.** `thinking.display` defaults to `"omitted"` on Opus 5 / Sonnet 5 / Opus 4.8 / 4.7 / Fable 5: the thinking block opens, receives one `signature_delta`, and closes with **no `thinking_delta` at all**, and the trace is billed in full. Gate the pane on `Capability.REASONING_TRACE` and only send `display: "summarized"` when a UI will actually show it.
- **A truncated answer ships as complete.** `stop_reason: "max_tokens"` is *our* `max_tokens` → `MAX_OUTPUT`; `model_context_window_exceeded` is the model's window → `CONTEXT_EXCEEDED`. Anthropic also adds stop reasons under its versioning policy (`refusal` and `pause_turn` both arrived that way), so an unmapped native value must become `ERROR` with `native_stop_reason` preserved — never fall through to `COMPLETE`.
- **A 403 that reads like a revoked key is a missing model entitlement.** Anthropic returns `permission_error` for both "this key lacks permission" and "your org is not entitled to this model". Neither retries nor falls back, so the only cost of confusing them is paging the wrong person and leaving a bad model in the catalogue: default 403 → `provider_auth`, and reclassify to `provider_permanent_request` when `client.models.retrieve(req.model)` 404s under that credential.
- **`RuntimeError: async generator ignored GeneratorExit` on client disconnect.** The terminal `ChatResult` was yielded from a `finally` while `CancelledError` was unwinding. Build the result inside the `except asyncio.CancelledError` branch, hand it to `_finalize()` there, re-raise (never swallow — the span must unwind, `kb-observability-conventions`), and `yield` only on paths that return normally.
- **A request that used to 400 now silently truncates.** Recent models accept an over-budget `max_tokens` at validation time and stop mid-generation instead of erroring. `max_tokens` also caps thinking **plus** visible text, so a route that never set `thinking` and sized `max_tokens` tightly starts truncating the moment it moves to Opus 5, where thinking is on by default.
- **`temperature` is a 400, not an ignore.** Recent Claude models reject non-default `temperature`/`top_p`/`top_k` on *every* request, thinking or not. Gate on `Capability.SAMPLING` and let `on_unsupported` decide; never send a house default. <!-- UNVERIFIED: Haiku 4.5's response to `thinking={"type":"adaptive"}` is undocumented — `output_config.effort` is confirmed to error there, so catalogue it without REASONING and the question does not arise. -->

## Official docs

- [Streaming Messages](https://platform.claude.com/docs/en/build-with-claude/streaming) — event flow, the cumulative-`usage` warning, `ping`, mid-stream `error` events, `display: "omitted"` behaviour.
- [Handling stop reasons](https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons) and [Errors](https://platform.claude.com/docs/en/api/errors) — the stop-reason set; the status→`error.type` map including 402 `billing_error` and 504 `timeout_error`; the `request-id` header.
- [Rate limits](https://platform.claude.com/docs/en/api/rate-limits) — cache-aware ITPM, the `anthropic-ratelimit-*` header set, `retry-after`. **The reset header is `anthropic-ratelimit-{bucket}-reset`: the bucket is an infix and `-reset` is a SUFFIX** — the opposite shape from OpenAI's `x-ratelimit-reset-{bucket}`, where `reset` is the prefix and the bucket trails. An adapter written against "the `x-ratelimit-reset-*` family" therefore reads **zero** Anthropic headers, and **the symptom is a missing backoff floor, not an error**: `retry_after_seconds` returns `None`, the caller falls back to its own backoff, nothing raises and no test fails. `app/providers/errors.py` is the implemented table — four buckets (`requests`, `tokens`, `input-tokens`, `output-tokens`), values **RFC 3339** (not OpenAI's Go-duration `6m0s` and not OpenRouter's epoch), plus the family pattern `anthropic-ratelimit-[a-z0-9-]+-reset` so a bucket Anthropic adds later still parses while another vendor's header does not. Read that table before writing a header name here. [Prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching) — breakpoint placement, TTLs, per-model minimum prefix.
- [Adaptive thinking](https://platform.claude.com/docs/en/build-with-claude/adaptive-thinking) and [Effort](https://platform.claude.com/docs/en/build-with-claude/effort) — the `adaptive` shape and the five effort levels. [anthropic-sdk-python](https://github.com/anthropics/anthropic-sdk-python) — `lib/streaming/_messages.py` (`build_events`), `_streaming.py` (mid-stream error raising).
- Owned elsewhere: the request/response shape → `kb-provider-adapter-contract`; error classes, retry, backoff, breakers → `kb-error-taxonomy`; key storage and audit → `kb-security-baseline`; span and metric names → `kb-observability-conventions`; prompt section layout → `kb-rag-query-contract`.

## Definition of done

- [ ] Every `AsyncAnthropic` is constructed with `max_retries=0` and a shared `http_client`; a grep for `AsyncAnthropic(` shows no call without both, and no `client.close()`.
- [ ] `output_config` is the only place `effort` appears; a request-shape test asserts no top-level `effort` and no `budget_tokens` in any built payload.
- [ ] Stop-reason table test: `max_tokens` → `MAX_OUTPUT`, `model_context_window_exceeded` → `CONTEXT_EXCEEDED`, `refusal` → `REFUSAL`, and an invented native value → `ERROR` with `native_stop_reason` set. Nothing reaches `COMPLETE` but `end_turn`.
- [ ] Usage fixture with a cached prefix asserts `total_input_tokens == input + cache_read + cache_creation`, that `gen_ai.usage.input_tokens` carries the total, and that ITPM attribution excludes `cache_read_input_tokens`.
- [ ] Recorded-stream test asserts each text token yields exactly one `Delta` (guards the synthetic-helper double-emit).
- [ ] Mid-stream `event: error` fixture with `overloaded_error` classifies as `provider_temporary` despite `status_code == 200`.
- [ ] `402 billing_error` classifies as `provider_billing` — never `provider_auth` and never `provider_rate_limit` — with retry `no`, fallback `no`, and no breaker credit; the same class OpenAI's `429 insufficient_quota` lands on.
- [ ] A recorded 429 with real `anthropic-ratelimit-*-reset` headers yields a non-`None` `retry_after_seconds`. **This is the assertion that catches the suffix/prefix mistake**: an adapter matching OpenAI's `x-ratelimit-reset-*` shape returns `None` here and nothing else in the suite notices, because the failure is a missing backoff floor rather than an error. Assert on a parsed number, not on "did not raise".
- [ ] Cancellation test aborts mid-stream: exactly one terminal `ChatResult` with `stop_reason=CANCELLED` and non-zero `input_tokens` from `message_start`; no `GeneratorExit` RuntimeError.
- [ ] `emitted > 0` test asserts no retry and no fallback after the first `text_delta`.
- [ ] `Diagnostics` snapshot greps clean for the API key, the system prompt, and any context-block text.

---
name: nvidia-nim-api
description: The NVIDIA NIM adapter against the hosted API Catalog at integrate.api.nvidia.com, plus the self-hosted NIM container we deliberately do not run. Use whenever editing services/ai-service/app/providers/nim.py, pinning a NIM model id, mapping NIM finish reasons or usage, or debugging a 202, 422, or empty-usage response. NIM is the only provider with two deployment shapes and per-model request schemas that disagree. Pairs with kb-provider-adapter-contract (the contract this implements).
---

# NVIDIA NIM API

Hosted NVIDIA API Catalog, `https://integrate.api.nvidia.com/v1`, OpenAI-shaped `/v1/chat/completions` — model list and per-model schemas verified 2026-08-04. Self-hosted NIM LLM container **2.0.9** (vLLM 0.25.1 backend) is documented here only as the deployment shape we deliberately do **not** run — there is no `gpu` profile any more (ADR-030).
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.4–8.7, docs/05-tech-stack.md §9.4/§9.17, docs/14-reliability.md §19.4, docs/18-deployment-backup-cicd.md §24.5

## Non-negotiables

- **NIM is the only *rerank-eligible* provider today — which is not the same claim as "the only one with a ranking endpoint", and the difference is load-bearing.** Two vendors publish a ranking route (NIM and OpenRouter, which serves `POST /api/v1/rerank`); OpenAI, Anthropic and DeepSeek publish none. Only NIM's scores may be **cut against**, because `capabilities.RERANK_SCALE` has an entry for it (`LOGIT`) and none for a gateway whose route fronts several upstream cross-encoders on one credential — finding #47, and the reason `RerankSkipReason` carries `provider_scale_uncalibrated` as a separate member. This file used to say NIM was "the *sole* implementation of the `rerank()` capability"; `OpenRouterAdapter.rerank` exists and the drift test asserts it moves with the matrix, so that was false. Do not restate either half from memory — the authoritative matrix is `app/providers/capabilities.py` and the measuring command is in `bge-reranker` § Non-negotiables (measured 2026-08-12: publishes `['nvidia_nim', 'openrouter']`, eligible `['nvidia_nim']`). Two consequences for this adapter: the ranking models have their own request schema and their own **unbounded logit** score scale, unrelated to the chat schema above and never interchangeable with another provider's threshold; and a NIM outage degrades ranking for every org configured against it while chat continues on a fallback, so the two capabilities must fail independently.
- **KnowledgeBot targets the hosted endpoint. Self-hosted NIM is not a dependency we operate.** §9.17 classes NVIDIA with OpenAI/Anthropic/DeepSeek/OpenRouter as "hosted commercial or third-party services" and defers self-hosting to "a future self-hosted inference provider"; §24.5's `gpu` profile was scoped to "local embedding or OCR acceleration" and **no longer exists** (ADR-030 — nothing embeds locally, and OCR runs on CPU); and the spec's only NIM reference link is the hosted API Catalog quickstart. Build one adapter against `integrate.api.nvidia.com`. If a self-hosted container is ever added it is an **optional** dependency — it reports degraded and never fails `/health/ready` (`kb-observability-conventions`, liveness/readiness table).
- **A self-hosted NIM is never a fallback target.** Cold start is model-download plus vLLM load, and `/v1/health/ready` returns 503 for the whole window (see Gotchas). A fallback that lands on a loading container converts a 20 s first-token budget into a hard failure. Hosted NIM is fallback-eligible under the normal table in `kb-provider-adapter-contract`; a self-hosted one is a primary or nothing.
- **The `nvapi-` key never reaches a log, a response, an audit `details`, or a span attribute** (`kb-security-baseline` §18.2). An NGC Personal API Key is broader than a per-project provider key — the same credential shape pulls container images and model weights from NGC — so the blast radius of a leak exceeds one org's token bill. Mask as `nvapi-…4a91` everywhere.
- **Capabilities come from `provider_models.capability_flags`, never the model id.** NIM's per-model request schemas genuinely disagree: `meta/llama-3.1-8b-instruct` caps `max_tokens` at 4096 and `temperature` at 1.0 with `stream` defaulting to `false`, while `nvidia/nemotron-3-super-120b-a12b` allows 32768 output tokens and defaults `stream` to `true`. One `NimAdapter` serving two models on one connection is why the flags exist (`kb-provider-adapter-contract`).
- **NIM needs no new `Capability` member.** It is the one provider where `ReasoningOption.budget_tokens` is *enforced* rather than advisory (`reasoning_budget`, `-1` disables), so map it and say so in `Diagnostics.extras`; do not add a flag for it. `seed` is likewise a diagnostics extra the eval suite may set, not a capability.

## How we use it

### Which shape, and what each costs

| | Hosted API Catalog | Self-hosted NIM container |
|---|---|---|
| Endpoint | `https://integrate.api.nvidia.com/v1` | `http://<host>:8000/v1` (nginx proxy; vLLM on loopback 8001) |
| Auth | `Authorization: Bearer nvapi-…` <!-- UNVERIFIED: the header is the OpenAI-SDK default and every catalog snippet uses it, but NVIDIA publishes no normative auth page --> | **None on inference endpoints.** `NGC_API_KEY`/`HF_TOKEN` authenticate *model downloads* only |
| Entitlement | free NVIDIA Developer Program account + trial API credits | free "NIM" offering, or "NIM Certified" which **requires NVIDIA AI Enterprise** ($4,500/GPU/yr, $1/GPU/hr cloud) |
| Ops burden | none | GPU driver 580+, CUDA 12.9+, Container Toolkit 1.14+, a cache volume, and a cold start per restart |
| Rate limits | undocumented; credits-metered <!-- UNVERIFIED: NVIDIA publishes no rate-limit or 429-header schema for the catalog --> | yours |

Drive the hosted endpoint with the **OpenAI Python SDK** — it is a documented drop-in (`docs/05` §9.4 permits "official documented HTTP clients"), and it saves us re-implementing SSE. It also brings its own retry ladder, which we turn off.

```python
# services/ai-service/app/providers/nim.py
from typing import AsyncIterator
import time
from openai import AsyncOpenAI, APIStatusError
from .contract import (Capability, CapabilityWarning, ChatRequest, ChatResult, Delta,
                       Diagnostics, ModelCapabilities, StopReason, Usage)
from ..errors import KbError

BASE_URL = "https://integrate.api.nvidia.com/v1"

# NIM's finish_reason vocabulary is vLLM's. There is no distinct "context window"
# value: an over-long prompt is rejected up front as 422, never mid-stream. So
# CONTEXT_EXCEEDED is unreachable here and "length" is unambiguously OUR cap.
_STOP = {"stop": StopReason.COMPLETE, "length": StopReason.MAX_OUTPUT,
         "tool_calls": StopReason.TOOL_USE, "content_filter": StopReason.REFUSAL}

class NimAdapter:
    name = "nvidia_nim"

    def __init__(self, api_key: str, timeouts):
        # max_retries=0 — the SDK defaults to 2, which would stack on the adapter's
        # own ladder and bill 9 completions for one click (kb-error-taxonomy,
        # "Retry ownership"). The key is held for this call only; never logged.
        self._c = AsyncOpenAI(base_url=BASE_URL, api_key=api_key,
                              max_retries=0, timeout=timeouts.total)

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        w: list[CapabilityWarning] = []
        # NIM 2.0 deleted the `nvext` extension object that 1.x used for guided
        # decoding, and the hosted catalog documents no `response_format` at all.
        # Unknown top-level fields are accepted and ignored, so a schema sent here
        # returns 200 and prose — the silent drop §8.6 forbids.
        if req.response_schema and Capability.STRUCTURED_OUTPUT not in caps.supported:
            w.append(CapabilityWarning(option="response_schema", action="rejected",
                                       detail="NIM publishes no json_schema surface"))
        # The catalog caps temperature at 1.0 per model; OpenAI allows 2.0. Out of
        # range is a 422, not a clamp, so it must fail in validate() not in flight.
        if req.temperature is not None and not 0.0 <= req.temperature <= 1.0:
            w.append(CapabilityWarning(option="temperature", action="rejected",
                                       detail="NIM accepts 0.0–1.0"))
        if req.max_output_tokens > caps.max_output_tokens:
            w.append(CapabilityWarning(option="max_output_tokens", action="rejected",
                                       detail=f"model ceiling {caps.max_output_tokens}"))
        return w

    async def stream(self, req, caps) -> AsyncIterator[Delta | ChatResult]:
        body = {
            "model": req.model,
            # Retrieved evidence is untrusted data and is packed by kb-rag-query-contract
            # into user-turn content. NIM documents a `context` message role; we do not
            # use it — its handling is per-model and undocumented.
            "messages": [{"role": "system", "content": req.system}, *render(req)],
            "max_tokens": req.max_output_tokens,
            "stream": True,
            # Without this, `usage` is null on EVERY streamed chunk and every billing
            # row lands as source="estimated".
            "stream_options": {"include_usage": True},
        }
        if req.temperature is not None:
            body["temperature"] = req.temperature
        if req.reasoning:                       # enforced, not advisory, on NIM
            body["reasoning_effort"] = {"none": "none", "low": "low", "medium": "medium",
                                        "high": "high", "xhigh": "high", "max": "high"}[req.reasoning.effort]
            if req.reasoning.budget_tokens:
                body["reasoning_budget"] = req.reasoning.budget_tokens

        started, first_ms, emitted, text = time.monotonic(), None, 0, []
        usage, stop = Usage(), StopReason.ERROR
        # Raw response, because the catalog answers 202 + {requestId} when a model is
        # queued — an async invocation the OpenAI SDK cannot poll.
        raw = await self._c.chat.completions.with_raw_response.create(
            **body, extra_headers={"X-Request-Id": req.trace_id})
        if raw.status_code == 202:
            raise KbError("provider_temporary", detail="nim_async_202")
        try:
            async for chunk in raw.parse():
                if chunk.usage:                 # arrives on a chunk with choices == []
                    usage = Usage(input_tokens=chunk.usage.prompt_tokens,
                                  output_tokens=chunk.usage.completion_tokens,
                                  source="provider_final")
                if not chunk.choices:           # indexing [0] here is the crash
                    continue
                ch = chunk.choices[0]
                if ch.finish_reason:
                    stop = _STOP.get(ch.finish_reason, StopReason.ERROR)
                if ch.delta and ch.delta.content:
                    if first_ms is None:
                        first_ms = int((time.monotonic() - started) * 1000)
                    emitted += 1; text.append(ch.delta.content)
                    yield Delta(kind="text", text=ch.delta.content)
        except APIStatusError as exc:
            # Once a token is out we have been billed; the router must not retry or
            # fall back (kb-error-taxonomy). tokens_emitted is the live gate.
            raise KbError(classify(exc), tokens_emitted=emitted) from None
        finally:
            yield ChatResult(
                text="".join(text), stop_reason=stop, usage=usage,
                total_ms=int((time.monotonic() - started) * 1000),
                first_token_ms=first_ms, provider_request_id=req.trace_id,
                diagnostics=Diagnostics(provider=self.name, native_stop_reason=str(stop)))
```

### Error mapping into the 18 classes

`kb-error-taxonomy` owns the classes; this is which NIM evidence lands in which.

| NIM evidence | Class | Why |
|---|---|---|
| 401/403 on `Authorization` | `provider_auth` | Deterministic; a retry hides a revoked key |
| 422 validation failed | `provider_permanent_request` | Our params broke a per-model range — our bug |
| 404 on a previously valid model id | `provider_permanent_request` | **ADR-014.** Catalog churn *looks* like lifecycle, but the id came from the bot's config snapshot, so falling back would silently answer from a different model on a `Ready` bot. Raise the stale-model warning on the connection instead — which is what this skill's own gotcha and DoD already prescribe |
| 503 while the model loads or is scaled to zero | `provider_temporary` | Capacity. Fallback-eligible: this is the signal §8.7 actually means |
| 429 | `provider_rate_limit` | Leave `Diagnostics.rate_limit` **empty** — no header schema is published |
| 500 "invocation ended with an error" | `provider_temporary` | Non-deterministic |
| **202 + `requestId`** | `provider_temporary` | Queued, not failed. Fallback-eligible: nothing was generated |
| Self-hosted: `/v1/health/ready` 503 | `provider_temporary`, **degraded** | Model loading. Never `internal_dependency` — that pages |
| Self-hosted: connection refused | `provider_temporary` | GPU OOM kills the container at boot; there is no HTTP error to read |
| Credits exhausted | `provider_permanent_request` | Operator action; it will not self-heal <!-- UNVERIFIED: NVIDIA documents no status/body for credit exhaustion -->|

Never map a NIM failure to `internal_dependency` — that class pages, and NIM is external.

## Gotchas

- **The NVIDIA dashboard shows 3× the request count your logs show, and 429s never clear.** We drive NIM through `AsyncOpenAI`, whose `max_retries` defaults to **2**; those attempts stack multiplicatively on the adapter's own ladder. Set `max_retries=0` on the NIM client specifically — it is easy to remember for the OpenAI adapter and forget for this one, because "it's the NVIDIA provider."
- **A request returns 200, the SSE body never arrives, and the turn hangs until the 45 s provider budget expires.** Every hosted chat endpoint documents `202 — Result is pending. Client should poll using the requestId`, resolved by `GET /v1/status/{requestId}`. The OpenAI SDK has no polling path and no 202 branch. Check `raw.status_code` before `parse()`; classify 202 as `provider_temporary` and let fallback take it. Do **not** build a poller — polling inside a 45 s streaming budget just relocates the hang.
- **Every billing row for NIM reads `source="estimated"`, and cost reports under-report by 100%.** vLLM emits `usage` on a streamed response only when `stream_options={"include_usage": True}` is set; otherwise `usage` is `null` on all chunks including the last. The same fix creates the second half of the trap: the usage chunk arrives with `choices: []`, so the obvious `chunk.choices[0]` crashes with `IndexError` on exactly the chunk that carries the number you added it for.
- **`temperature=1.5` works on OpenAI and 422s on NIM; `max_tokens=8192` works on Nemotron Super and 422s on Llama 3.1 8B.** The catalog publishes a *per-model* body schema — `temperature ≤ 1`, `max_tokens` ceilings of 4096 vs 32768, `reasoning_effort` enumerated `none|low|high` on Nemotron Super but `low|medium|high` on gpt-oss-120b, and `stream` defaulting to `false` on some models and `true` on others. A bot config that validates against one NIM model rejects on the next. Populate `capability_flags.max_output_tokens` per model row and never default `stream`.
- **A bot configured for structured output returns confident prose and nothing errors.** NIM LLM 2.0's migration guide states the `nvext` extension object "in the request body is removed", and the hosted catalog documents no `response_format`. Unknown top-level fields are ignored rather than rejected, so the request succeeds. Do not set `STRUCTURED_OUTPUT` or `JSON_MODE` for any NIM model without a recorded fixture proving enforcement. Note `kb-provider-adapter-contract`'s gotcha still says "NIM prefers `nvext.guided_json`" — that was true for 1.x and is now wrong; flagged, not edited.
- **A pinned model id starts returning 404 weeks after it worked.** The free "NIM" offering publishes models "within about 72 hours of upstream model availability" and retires them on no published schedule; between checks the catalog gained `deepseek-v4-flash/pro`, `minimax-m2.5/m2.7`, `kimi-k2-thinking`, and the `nemotron-3-{nano,super,ultra}` family while older ids stayed listed but unmaintained. Re-list `/v1/models` on every connection test, store the result on the connection row, and surface a stale-model warning in the admin panel rather than discovering it in a user's chat.
- **Self-hosted only: every request for the first several minutes after `docker run` fails, then all of them succeed.** Startup is proxy up → GPU profile selection → **model download** → vLLM load → readiness. `/v1/health/live` returns 200 the entire time (it is served by nginx and has no backend dependency), so a naive orchestrator routes traffic immediately. `/v1/health/ready` is the only truthful probe and returns **503 while loading**. Mount `LOCAL_NIM_CACHE` or you pay the download on every restart. This is the whole reason self-hosted NIM is not in our fallback matrix.
- **Self-hosted only: the container vanishes and the adapter sees connection refused, with no HTTP status to classify.** GPU OOM is a *startup* failure — weights alone need `params × bytes_per_param / TP` (Llama 3.3 70B at BF16 TP=4 is 35 GB per GPU) — and NIM's fail-fast supervision shuts the whole container down when either process exits so the orchestrator reschedules. Classify transport-level refusal as `provider_temporary` so the breaker opens; do not run the retry ladder into a crash-looping container.
- **Self-hosted only: anyone who can reach port 8000 can spend your GPU.** There is no API-key check on the inference endpoints — the entire `Authentication` section of the environment-variable reference concerns model *downloads*, and the docs state outright that NIM "does not validate the `x-api-key` header" on `/v1/messages`. If it is ever deployed it sits on the internal network with Qdrant and PostgreSQL, never behind Traefik (`kb-security-baseline` §18: public and internal networks are separate).
- **`provider_request_id` is null on every NIM row, so NVIDIA support has nothing to look up.** NIM forwards `X-Request-Id` and adopts it as the backend request id, but "if the header is not present, NIM does not add one and the response does not carry an X-Request-Id" — it never synthesizes a value. Always send our own; it also gives free log correlation, and the container forwards `traceparent` on inference endpoints so the single-trace requirement survives the hop.
- **First-token latency reads well under target while users watch a blank box.** NIM's first streamed chunk is an empty role delta (`"delta":{"role":"assistant","content":""}`) and the stream terminates with `data: [DONE]`, not a usage-bearing final chunk. Time TTFT from the first delta with non-empty `content`, per `kb-observability-conventions`.

## Official docs

- [NIM LLM API reference](https://docs.nvidia.com/nim/large-language-models/latest/reference/api-reference.html) — endpoint list, health/management endpoints, the Anthropic-compatible `/v1/messages` surface we do not use.
- [NIM LLM architecture](https://docs.nvidia.com/nim/large-language-models/latest/reference/architecture.html) — startup sequence, the 503-while-loading readiness contract, fail-fast supervision.
- [NIM LLM 1.x → 2.0 migration guide](https://docs.nvidia.com/nim/large-language-models/latest/reference/1.x-migration-guide.html) — the removal of `nvext`, `nim-run` → `nim-serve`, `NIM_MODEL_NAME` → `NIM_MODEL_PATH`.
- [Environment variables](https://docs.nvidia.com/nim/large-language-models/latest/reference/environment-variables.html) and [prerequisites](https://docs.nvidia.com/nim/large-language-models/latest/get-started/prerequisites.html) — NGC Personal API Key (legacy keys unsupported), driver/CUDA floors, NVAIE entitlement.
- [Logging and observability](https://docs.nvidia.com/nim/large-language-models/latest/reference/logging-and-observability.html) — `/v1/metrics` vLLM passthrough, `X-Request-Id` and `traceparent` forwarding rules.
- [Hosted LLM APIs](https://docs.api.nvidia.com/nim/reference/llm-apis) — the live catalog, `integrate.api.nvidia.com`, and each model's own body schema and 202 response.
- [NIM FAQ](https://docs.api.nvidia.com/nim/docs/faq) — credits vs browser use, NVAIE pricing, NGC key types.

## Definition of done

- [ ] `AsyncOpenAI` for NIM is constructed with `max_retries=0`; a test asserts one adapter failure produces exactly one HTTP attempt.
- [ ] A 202-with-`requestId` fixture raises `provider_temporary` before any SSE parsing, and the router falls back.
- [ ] `stream_options={"include_usage": True}` is sent on every streamed call; a recorded fixture asserts `Usage.source == "provider_final"` and that a `choices: []` chunk does not raise.
- [ ] `validate()` rejects `temperature > 1.0`, `max_output_tokens` above the model's row ceiling, and any `response_schema`; each produces a `CapabilityWarning`, never a dropped field.
- [ ] Table-driven stop-reason test: `length` → `MAX_OUTPUT`, `content_filter` → `REFUSAL`, unknown → `ERROR` with `native_stop_reason` preserved; nothing reaches `COMPLETE` but `stop`.
- [ ] `X-Request-Id` is set from `trace_id` on every request including retries; `provider_request_id` is non-null on success *and* error paths.
- [ ] Cancellation test: abort mid-stream, assert one terminal `ChatResult` with `CANCELLED`, and that `tokens_emitted > 0` blocks both retry and fallback.
- [ ] Connection test re-lists `/v1/models` and stores the ids; a pinned id absent from the response surfaces a stale-model warning, not a 404 at chat time.
- [ ] A log/audit fixture containing a real-shaped `nvapi-` key asserts it reaches no sink, no span attribute, and no `Diagnostics` payload.
- [ ] If a self-hosted container is ever added: it is registered as an **optional** dependency (open breaker leaves `/health/ready` green), it is absent from every fallback chain, and it is reachable only from the internal network.

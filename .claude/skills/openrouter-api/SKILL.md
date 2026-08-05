---
name: openrouter-api
description: The OpenRouter adapter in services/ai-service/app/providers/openrouter.py — an OpenAI-shaped gateway fronting hundreds of upstream models on one credential. Use whenever editing that adapter, pinning an upstream with provider.order, reading openrouter_metadata, classifying a 402/429/503, or debugging a model that answered differently from the one requested. The only provider whose failure may belong to someone else; attribution rules live here. Pairs with kb-provider-adapter-contract (the contract it implements).
---

# OpenRouter API

OpenRouter API `v1` (single version, path-selected — no date pinning, no version header), `POST https://openrouter.ai/api/v1/chat/completions`, called over raw `httpx` from `services/ai-service/app/providers/openrouter.py`. Verified against openrouter.ai/docs on 2026-08-04.
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.4–8.7, docs/14-reliability.md §19, docs/19-repo-structure-adrs.md ADR-001

ADR-001 says five providers, integrated directly, **no gateway**. OpenRouter is a gateway, so this adapter is structurally unlike the other four: one credential, hundreds of models, dozens of upstreams, and a routing layer of its own between us and whoever actually answers. Every rule below exists to make that layer *observable* and *inert*, not to use it.

## Non-negotiables

- **OpenRouter's own routing must never make a decision we cannot see.** Send `provider.allow_fallbacks: false` and `provider.order` on every request, and **never** send the `models: [...]` array (OpenRouter's model-level fallback). Our ordered fallback (§8.7) writes a `provider_calls` row per attempt; OpenRouter's writes nothing we can read, bills at a different upstream's price, and is exactly the fallback-inside-fallback amplification `kb-error-taxonomy` forbids.
- **`require_parameters: true` on every request.** It defaults to `false`, which means an upstream that does not support `response_format` or `reasoning` is routed to anyway and the parameter is dropped — HTTP 200, plausible prose, no schema, no error. §8.6 forbids the silent drop; this flag converts it into a `503`/`provider_unavailable` we can classify.
- **Attribution before classification.** A failure is OpenRouter's or an upstream's, and the two get different breaker keys and different pages. Decide from `error.metadata.error_type` and `error.metadata.provider_code` **before** touching the HTTP status — status alone is ambiguous (see the 503 gotcha).
- **`gen_ai.response.model` is read from the response, never assumed equal to the request.** OpenRouter substitutes silently. Cost, evals, and `provider_calls.model` all key off the served value; `kb-observability-conventions` already defines both attributes for precisely this case.
- **The API key stops at the adapter** (`kb-security-baseline` §18.2). It is never in a span attribute, a `Diagnostics.extras` entry, an audit `details` payload, or a log line — and OpenRouter's error bodies can echo request material, so redact before logging, never after.
- **`data_collection: "deny"` always; `zdr: true` when the org's connection requires it.** Retrieved chunks are tenant documents; routing them to an upstream that trains on them is a data-processing breach, not a config preference.

## How we use it

The surface we use is the OpenAI Chat Completions shape plus four OpenRouter-only keys: `provider`, `usage` (automatic), `openrouter_metadata` (header opt-in), and `native_finish_reason`. We do **not** use `openrouter/auto`, `~`-prefixed auto-updating aliases (`~openai/gpt-latest`), `:free` variants, `plugins`, or the `/api/v1/responses` surface — each one either moves the model out from under `provider_models.capability_flags` or adds a routing layer.

`provider_models` carries two OpenRouter-only columns beyond the shared ones: `routing_pin` (the upstream slug list, from the copy button on the model page — `deepinfra`, `google-vertex/us-east5`) and `canonical_slug` (the `/api/v1/models` field documented as permanent; the request `id` is not).

```python
# services/ai-service/app/providers/openrouter.py
URL = "https://openrouter.ai/api/v1/chat/completions"

# Attribution headers name OUR product. Never the tenant's site — see Gotchas.
HEADERS = {
    "HTTP-Referer": settings.public_app_url,
    "X-OpenRouter-Title": "KnowledgeBot AI",     # legacy X-Title still accepted
    "X-OpenRouter-Metadata": "enabled",          # opt-in; the only documented served-by evidence
}

class OpenRouterAdapter:                          # satisfies ProviderAdapter (kb-provider-adapter-contract)
    name = "openrouter"

    def _body(self, req: ChatRequest, pin: RoutingPin) -> dict:
        return {
            "model": req.model,                   # "author/slug" verbatim; no alias, no ":free"
            "messages": build_messages(req),      # context_blocks stay user-role; kb-rag-query-contract
            "max_tokens": req.max_output_tokens,
            "stream": True,
            "user": str(req.org_id),              # opaque ULID for OpenRouter's abuse detection, not PII
            "provider": {
                "order": pin.slugs,               # one slug ⇒ deterministic upstream
                "allow_fallbacks": False,         # never route outside `order`
                "require_parameters": True,       # 503 instead of a silent parameter drop
                "data_collection": "deny",
                **({"zdr": True} if pin.zdr else {}),
            },
            # deliberately absent: "models" (OpenRouter's fallback), "route", "debug"
        }

    async def stream(self, req, caps) -> AsyncIterator[Delta | ChatResult]:
        body, started, emitted = self._body(req, pin_for(req)), time.monotonic(), 0
        served, usage, stop, err = Served(), Usage(), StopReason.ERROR, None
        try:
            async with self.http.stream("POST", URL, json=body,
                                        headers={**HEADERS, "Authorization": f"Bearer {key}"},
                                        timeout=to_httpx(req.timeouts)) as r:
                if r.status_code >= 400:          # pre-stream error: real status, JSON envelope
                    raise classify(await r.aread(), r.status_code, r.headers, emitted=0)
                async for line in r.aiter_lines():
                    if not line or line.startswith(":"):
                        continue                  # ": OPENROUTER PROCESSING" keep-alive
                    if (data := line.removeprefix("data: ")) == "[DONE]":
                        break
                    chunk = json.loads(data)
                    served.absorb(chunk)          # model / provider / openrouter_metadata, any chunk
                    if e := chunk.get("error"):   # mid-stream error arrives INSIDE a 200
                        raise classify(chunk, e["code"], r.headers, emitted=emitted)
                    if u := chunk.get("usage"):   # final chunk; totals, not increments
                        usage, stop = to_usage(u), map_stop(chunk)
                    for d in deltas(chunk):
                        emitted += 1
                        yield d
        except KbError as exc:
            err, stop = exc, StopReason.ERROR
        except asyncio.CancelledError:
            stop = StopReason.CANCELLED; raise
        finally:                                  # one terminal ChatResult on every path
            yield ChatResult(
                text=..., stop_reason=stop, usage=usage,
                provider_request_id=served.gen_id,             # "gen-…" from the body, not a header
                total_ms=int((time.monotonic() - started) * 1000),
                error_class=err.error_class if err else None,
                diagnostics=Diagnostics(
                    provider="openrouter",
                    served_by=served.upstream,                 # "Anthropic" — attribution, breaker key
                    native_stop_reason=served.native_finish_reason,
                    extras={"served_model": served.model,      # may differ from req.model
                            "routing_strategy": served.strategy,
                            "routing_attempt": served.attempt, # >1 ⇒ OpenRouter retried internally
                            "credits_cost": served.cost}))     # usage.cost, authoritative
```

### Error map into the 18 classes

Read `error.metadata.error_type` first; the status is the tiebreak. Envelope is always `{"error": {"code", "message", "metadata"}}`.

| Evidence | `error_class` | Notes |
|---|---|---|
| `authentication` / 401 | `provider_auth` | Key revoked or disabled. No retry, no fallback. |
| `payment_required` / 402 | `provider_auth` | **Depleted credits, including a negative balance, and it hits `:free` models too.** Deliberate mapping: its policy — never retry, never fall back, alert the operator — is the one we want; `provider_permanent_request` would page it as our bug. |
| `rate_limit_exceeded` / 429 | `provider_rate_limit` | `X-RateLimit-Limit/Remaining/Reset`; `Retry-After` only when every attempted upstream sent a hint. |
| `content_policy_violation`, `refusal`, moderation 403 | `provider_permanent_request` + `StopReason.REFUSAL` | Terminal. Fallback re-asks a banned question and bills for it. |
| `permission_denied` / 403, `invalid_request`, `context_length_exceeded`, `max_tokens_exceeded`, `payload_too_large`, `invalid_image` | `provider_permanent_request` | Our request. Never retried, never fallen back. |
| `provider_overloaded` (503), `provider_unavailable` (502), `timeout` (408/504), `server`/`unmapped` (500) | `provider_temporary` | Retryable and fallback-eligible only while `emitted == 0`. |
| 503, no `error_type` — "no available model provider meets your routing requirements" | `provider_permanent_request` | Our `provider` block is unsatisfiable. See gotchas. |

`error.metadata.provider_code` present ⇒ the upstream produced it. Absent on a 5xx ⇒ OpenRouter produced it.

### Circuit-breaker keying — the verdict

`kb-error-taxonomy` scopes one breaker per `(org_id, provider_credential, model)`. For OpenRouter that is under-keyed in one direction and over-keyed in the other, so **use a fourth term**:

- **Upstream-attributed failures** (`provider_code` present, or `openrouter_metadata` names a selected endpoint) key on `(org_id, connection_id, model, upstream_slug)`. A Together brownout on one Llama endpoint must not open the breaker for `anthropic/claude-*` riding the same credential.
- **Gateway-attributed failures** (402, platform 429, 401, moderation 403, 503-unsatisfiable, 5xx with no `provider_code`) key on `(org_id, connection_id, "_openrouter")` and open **every** model on that connection. They are properties of the account, not the model.
- **Unattributable failures** (connect timeout, first-token timeout — no body, no metadata) key gateway-wide. Deliberately pessimistic: OpenRouter is the only hop we know was involved.

The upstream slug is a Valkey key component, never a metric label — the slug set is dozens and grows without our involvement, and `kb-observability-conventions` bans unbounded labels. `kb_provider_*` keeps `provider="openrouter"`; the upstream goes on the span as `kb.upstream_provider` and into the `provider_calls` row.

### Does OpenRouter belong in the fallback matrix?

Yes, as a **target only**, under one rule: a fallback pair is valid only when the fallback's *upstream* differs from the primary's provider. Falling back from Anthropic-direct to `anthropic/claude-…` on OpenRouter routes straight back into the outage you are escaping, one hop later and at a markup. Because the served upstream is only knowable after the response, enforce it at configuration time: **an OpenRouter model may be selected as a fallback target only if its `routing_pin` names exactly one upstream slug**, validated when the admin saves the bot. An OpenRouter model with an unpinned or multi-slug route is a valid primary and an invalid fallback.

## Gotchas

- **Tenant customer domains appear on OpenRouter's public app-rankings page.** The widget runs on the customer's site, and a proxy that forwards the browser's `Referer` — or an adapter that fills `HTTP-Referer` from the request — publishes every embedding origin to a third party's leaderboard. `HTTP-Referer` is a module constant naming our own public URL. It is optional for the call and required only for attribution, so the safe failure is having no app page, not a leak.
- **Structured output silently degrades to prose, and the eval suite scores the degraded answer as a model regression.** `provider.require_parameters` defaults to `false`, so an upstream lacking `response_format` is routed to and the field is dropped at 200. Send `require_parameters: true` **and** `strict: true` inside the `json_schema` — strict is optional and, without it, providers without a native strict mode treat the schema as a hint.
- **A 503 retries three times, falls back, and fails identically everywhere — because it was never an outage.** 503 carries two unrelated meanings: `error_type: "provider_overloaded"` (capacity, temporary, fallback-eligible) and "there is no available model provider that meets your routing requirements" (our `only`/`order`/`zdr`/`require_parameters` block excludes every endpoint — deterministic, `provider_permanent_request`). Branch on `error_type`, not the status. This is the failure mode `require_parameters: true` converts a silent 200 into, so it will be the common one.
- **`choices[0].finish_reason == "error"` inside an HTTP 200, and the reader files it as a completed answer.** OpenRouter emits mid-stream errors as an SSE chunk with a top-level `error` object and `finish_reason: "error"`; headers were already committed so the status stays 200. A reader that only inspects the status records a successful call with truncated text and no `error_class`. Check for `error` on every chunk, not just the first.
- **Retries after an incident show 2–3× the token cost, and users see duplicate text.** Two amplifiers stack here. If you point the `openai` SDK at OpenRouter's base URL, it retries twice internally on top of the adapter's two — set `max_retries=0` (`kb-error-taxonomy`). And `openrouter_metadata.attempt > 1` means **OpenRouter already retried upstream** for that single call of ours. Guard every retry and every fallback on `emitted == 0`: once a token has been streamed the request is billed and non-idempotent, and OpenRouter's zero-completion insurance no longer covers you.
- **A cancelled stream is billed in full for some upstreams and free for others, so cancellation cost looks random.** Aborting the connection stops generation and billing on OpenAI, Anthropic, Fireworks, Together and others, but Groq, Google and Mistral keep generating and charge for the whole completion. Our `stop_reason=CANCELLED` result carries `source="estimated"` either way (`kb-provider-adapter-contract`) — never invoice from it. Reconcile against `GET /api/v1/generation?id=gen-…` if the number has to be exact. <!-- UNVERIFIED: the generation-endpoint reference page 404s from the current docs nav; only its existence and query shape are confirmed, not its field set -->
- **`Diagnostics.served_by` is empty on every request and nobody notices until a post-incident review.** Served-by attribution comes from `openrouter_metadata`, which is **opt-in via the `X-OpenRouter-Metadata: enabled` request header** — omit it and you get nothing. The documented `Response` type has no top-level `provider` field either; `provider` does appear on error chunks and in practice on normal ones, so treat it as best-effort corroboration and the metadata object as the source. Read `endpoints.available[]` where `selected == true` for the upstream, plus `strategy`, `attempt` and `requested`. It arrives on the **final** streamed chunk, so a reader that stops at the last text delta loses it. <!-- UNVERIFIED: whether the undocumented top-level `provider` field is still populated on non-error responses -->
- **The model that answered is not the model you priced.** `response.model` is the served model and billing follows it. Auto Exacto reorders providers automatically on any request carrying `tools` — no opt-in — and `~`-aliases, `:free`/`:nitro`/`:floor` variants and `openrouter/auto` all move the target. Record `usage.cost` (credits, always present) as the authoritative cost; **never** compute OpenRouter spend from `provider_models` pricing metadata, which describes a model, not the upstream that served it. Assert `served_model == req.model` and raise a `CapabilityWarning` when it does not.
- **The moderation-error payload puts the tenant's own text in your logs.** A 403 moderation block returns `metadata.flagged_input` — up to 100 characters of the prompt, i.e. the user's question or a retrieved chunk — alongside `reasons`, `provider_name` and `model_slug`. Allow-list `reasons`/`provider_name` into `Diagnostics`; drop `flagged_input` at the parse site (§20.3, `kb-security-baseline`).
- **`usage: {include: true}` and `stream_options: {include_usage: true}` are dead parameters.** Both are deprecated no-ops; full usage including `cost` and `cost_details` is now always returned. Code that gates usage recording on having sent them still works, but code that *asserts* they were echoed will not. `cost_details.upstream_inference_cost` is 0 or null unless the connection is BYOK.
- **`debug: {echo_upstream_body: true}` echoes the assembled prompt.** That payload is the packed context — tenant document text plus the user's question, the most sensitive object in the system. It is streaming-only and must be absent from every environment; if a developer enables it locally, the debug chunk (first chunk, empty `choices`) must never reach `Diagnostics` or a log sink.
- **A model id that worked last month 404s, and `capability_flags` describe a model that no longer exists.** The catalogue is third-party and volatile: endpoints carry an `expiration_date`, request ids may be aliases, and only `canonical_slug` is documented as permanent. OpenRouter ships non-breaking changes without notice and explicitly instructs clients to ignore unrecognised response fields and unknown enum values — so parse permissively (unknown `finish_reason` → `StopReason.ERROR` with `native_finish_reason` preserved) and reconcile `provider_models` against `/api/v1/models` on a schedule.

## Official docs

- [API overview](https://openrouter.ai/docs/api_reference/overview.md) and [versioning](https://openrouter.ai/docs/api_reference/versioning.md) — the response type, the single `v1`, and the ignore-unknown-fields contract.
- [Provider selection](https://openrouter.ai/docs/guides/routing/provider-selection.md) and [model fallbacks](https://openrouter.ai/docs/guides/routing/model-fallbacks.md) — the `provider` object, slugs, and the `models[]` layer we disable.
- [Router metadata](https://openrouter.ai/docs/guides/features/router-metadata.md) — `X-OpenRouter-Metadata`, `endpoints.available[].selected`, `strategy`, `attempt`.
- [Errors and debugging](https://openrouter.ai/docs/api_reference/errors-and-debugging.md) — the canonical `error_type` list, `provider_code`, mid-stream shape, `echo_upstream_body`.
- [Streaming](https://openrouter.ai/docs/api_reference/streaming.md), [limits](https://openrouter.ai/docs/api_reference/limits.md), [usage accounting](https://openrouter.ai/docs/use-cases/usage-accounting) — keep-alive comments, cancellation billing, `X-RateLimit-*`, `cost`/`cost_details`.
- [App attribution](https://openrouter.ai/docs/app-attribution), [structured outputs](https://openrouter.ai/docs/guides/features/structured-outputs.md), [zero-completion insurance](https://openrouter.ai/docs/guides/features/zero-completion-insurance.md), [auto exacto](https://openrouter.ai/docs/guides/routing/auto-exacto.md).

## Definition of done

- [ ] Every outgoing body carries `provider.allow_fallbacks: false`, a non-empty `provider.order`, `require_parameters: true`, `data_collection: "deny"`; a test greps the serialized body and fails on a `models` key.
- [ ] `X-OpenRouter-Metadata: enabled` is sent and `Diagnostics.served_by` is non-null on a recorded live fixture; `HTTP-Referer` equals `settings.public_app_url` and is provably independent of the inbound request.
- [ ] Fixture per row of the error map asserts the `error_class`; a 503 with `provider_overloaded` falls back and a 503 with no `error_type` does not.
- [ ] Mid-stream-error fixture (HTTP 200, `finish_reason: "error"`) produces `StopReason.ERROR` with an `error_class`, never a success.
- [ ] Breaker test: 20 upstream-attributed failures on one `upstream_slug` leave a second model on the same connection closed; one 402 opens every model on that connection.
- [ ] A test asserts no retry and no fallback once `emitted > 0`, and that the HTTP client is constructed with no internal retry layer.
- [ ] `served_model != req.model` emits a `CapabilityWarning`; `provider_calls` records `usage.cost` and `openrouter_metadata.attempt`, never a locally computed price.
- [ ] Config validation rejects an OpenRouter model as a fallback target unless `routing_pin` names exactly one slug.
- [ ] Log/serialization fixture containing an API key, a moderation `flagged_input`, and a debug echo asserts none reach any sink.

# Fallback eligibility, condition by condition

Companion to `SKILL.md`. The full ordered-fallback table (§8.7): fourteen conditions, the provider evidence
that identifies each, whether an ordered fallback may fire, and why — plus the `provider_calls` accounting
rule for every attempt. Spec: docs/02-functional-auth-tenancy-bots.md §8.7, docs/11-data-model.md §16.6.

Ordered fallback (§8.7) consults this table on the `error_class` the adapter produced. There is no "probably transient" bucket; an unmapped class does **not** fall back.

| Condition | Typical provider evidence | Fallback? | Why |
|---|---|---|---|
| Provider outage / 5xx | Anthropic `529 overloaded_error`, OpenAI 503, DeepSeek `503 Server Overloaded`, OpenRouter 502/503 | **Yes** | Another provider can serve it |
| Connection / first-token timeout | no bytes before `timeouts.first_token` | **Yes** | Nothing generated, nothing charged |
| Temporary server error | 500 `api_error` | **Yes** | Non-deterministic |
| Rate limit | 429 + reset headers | **Yes, if configured** | Per-connection switch; off by default so limits stay visible |
| "Model temporarily unavailable" (§8.7) — **capacity reading only** | 503/529/500 or an explicit overload code while serving a model | **Yes** | Provider-side capacity. This is the branch that makes §8.7's bullet reachable |
| Model name the provider does not recognise | `model_not_found`, 404 on the models route, an OpenRouter slug that no longer resolves | **No** | Configuration, not capacity — see below |
| Capacity signal (DeepSeek) | `finish_reason: insufficient_system_resource` | **Yes** | Reached us as a 200 — classify it, don't call it `COMPLETE` |
| Auth failure | 401/403 | **No** | Deterministic; a second key hides a broken connection |
| Invalid request | 400/422 | **No** | Our bug — retrying elsewhere multiplies it |
| Content-policy refusal | Anthropic `stop_reason: refusal`, OpenAI `refusal` part | **No** | Deterministic. Falling back re-asks a banned question and bills for it |
| Tenant quota exceeded | our own quota check | **No** | Fallback would defeat the quota |
| Context too large from an app bug | prompt-packing overflow | **No** | Same prompt overflows the fallback too |
| Provider billing exhausted | Anthropic 402 `billing_error`, DeepSeek 402 | **No** | Operator action needed; it will not self-heal |
| User cancellation | client disconnect | **No** | Nobody is waiting |

Every fallback attempt writes a `provider_calls` row of its own with `fallback_metadata` set (docs/11 §16.6). One user turn that falls back once is two rows, not one — otherwise the cost of fallback is invisible.

## Capacity vs unknown model, per adapter

§8.7's "model temporarily unavailable" is **provider capacity**, never a missing model. Capacity → `provider_temporary`, retryable and fallback-eligible. An unrecognised model id → `provider_permanent_request`, non-retryable and **not** fallback-eligible: the id came from the bot's configuration snapshot, so it is a misconfiguration the tenant must see — a deprecated pin, a typo, a vendor retirement. Falling back would serve every answer from a model the tenant never chose, at a different price and quality, with a `Ready` bot and no error anywhere. No new error class; `kb-error-taxonomy` stays at 18.

Each adapter owns its own row and decides **on the vendor's own code**, never by string-matching the message — vendors reword error prose without notice, and a substring match on "unavailable" fails in the dangerous direction, turning a config error into a silent fallback.

| Adapter | Capacity / overload → `provider_temporary` (eligible) | Unknown model → `provider_permanent_request` (not eligible) |
|---|---|---|
| Anthropic | `type: "overloaded_error"` (529), `type: "api_error"` (500). Branch on `exc.type`: a mid-stream `event: error` carries `status_code == 200` | `not_found_error` on the model, including `models.retrieve(req.model)` 404ing under that credential |
| OpenAI | 503, and `InternalServerError` with code `server_error` (500) | `NotFoundError` 404 `model_not_found` |
| DeepSeek | 503 `Server Overloaded`, 500, and the HTTP-200 `finish_reason: insufficient_system_resource` | 400 naming an unknown model id on the OpenAI-shaped base URL <!-- UNVERIFIED: DeepSeek does not document the OpenAI-shaped endpoint's response to an unrecognised id; the `/anthropic` surface silently remaps it, which is why we never use that surface --> |
| NVIDIA NIM | 503 while a model is loading or scaled to zero, `202` + `requestId` (queued, nothing generated), 500 "invocation ended with an error" | 404 on a pinned id the catalog retired — surface it as a stale-model warning on the connection, do not fall back |
| OpenRouter | `error.metadata.error_type` of `provider_overloaded` (503), `provider_unavailable` (502 from an upstream), `timeout`, `server` | a slug that no longer resolves (404). A 503 with **no** `error_type` is an unsatisfiable routing block, also permanent |

A test per adapter asserts both branches from synthetic errors: capacity → `provider_temporary` and falls back, unknown model → `provider_permanent_request` and does not.

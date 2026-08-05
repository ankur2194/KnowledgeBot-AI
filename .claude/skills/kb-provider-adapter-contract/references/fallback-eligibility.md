# Fallback eligibility, condition by condition

Companion to `SKILL.md`. The full ordered-fallback table (§8.7): thirteen conditions, the provider evidence
that identifies each, whether an ordered fallback may fire, and why — plus the `provider_calls` accounting
rule for every attempt. Spec: docs/02-functional-auth-tenancy-bots.md §8.7, docs/11-data-model.md §16.6.

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

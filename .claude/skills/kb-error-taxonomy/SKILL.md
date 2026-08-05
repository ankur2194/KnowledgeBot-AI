---
name: kb-error-taxonomy
description: The 18-class error taxonomy for KnowledgeBot AI — each class's client-visible status, whether it may be retried, whether it may trigger fallback, and whether it pages. Use whenever writing an except/catch block, adding a retry or backoff loop, setting a timeout, wiring a circuit breaker, or deciding what may degrade. Every other service-side skill maps its errors into this table instead of inventing statuses. Pairs with kb-provider-adapter-contract (maps provider errors in) and kb-observability-conventions (names the fields).
---

# KnowledgeBot Error Taxonomy, Retry, and Degradation

Stack: Laravel + Laravel Queue (Valkey) control plane, FastAPI + Celery data plane, Qdrant, PostgreSQL, object storage, external LLM/embedding providers.
**Authoritative spec:** docs/14-reliability.md §19, docs/02-functional-auth-tenancy-bots.md §8.7, docs/08-ingestion-pipeline.md §13.7, docs/15-observability.md §20.3

## Non-negotiables

- **Exactly one tier retries a given call.** The tier that owns the adapter retries; every tier above it does not. Attempts multiply across tiers (3 tiers × 3 attempts = 27 provider calls from one user click), which is how a recoverable brownout becomes a full outage. Laravel does **not** retry its chat call to FastAPI; FastAPI's provider adapter does. See "Retry ownership" below.
- **Unknown errors classify as permanent, never temporary.** A permanent error misfiled as temporary retries until the attempt cap, lands in `failed_jobs`, and gets retried again by an operator. The cost of wrongly-permanent is one visible failure; the cost of wrongly-temporary is an unbounded queue.
- **Never retry, ever:** invalid credentials, invalid input, unsupported file, authorization failure, confirmed content-policy refusal (§19.2). No exception, no "just once to be sure."
- **Never fall back for:** authentication failure, invalid request, content-policy refusal, tenant quota exceeded, context-too-large from an application bug, user cancellation (§8.7). Fallback runs on the *classified* class, never on a raw provider HTTP status.
- **Inner timeouts, including their retries and backoff sleeps, must sum to less than the enclosing timeout.** Otherwise the inner tier bills tokens for a completion the outer tier already abandoned.
- **Errors never degrade tenant isolation or grounding.** No fallback path, cached answer, or partial result may skip the org filter (`kb-tenancy-isolation`) or answer without evidence — with no evidence the bot refuses (§19.6).
- **Logged error payloads carry `error_class` and never carry raw keys, passwords, full `Authorization` headers, or unredacted user content** (§20.3). Provider error bodies frequently echo the request — redact before logging.

## How we use it

### The 18 classes

`error_class` is the row key: the value in telemetry, the discriminator in the internal error envelope, and the input to every retry, fallback, and breaker decision. Page = `page` (wake someone), `alert` (dashboard + ticket), `no`.

| `error_class` | Applies when | Client status | Retryable | Fallback-eligible | Page |
|---|---|---|---|---|---|
| `validation` | Request fails schema/business validation at Laravel or the internal contract | 422 | **no** | no | no |
| `authentication` | Missing/expired/invalid Sanctum token, session, or widget key | 401 | **no** | no | no |
| `authorization` | Authenticated but not permitted: wrong org, unassigned bot, missing RBAC permission | 403 admin / **404 public** ¹ | **no** | no | no |
| `tenant_quota` | Org exceeded its plan's token/storage/source allowance | 403 `tenant_quota_exceeded` | **no** | no (§8.7) | no |
| `rate_limit` | *Our* limiter rejected the caller | 429 + `Retry-After` | client-only, after `Retry-After` | no | alert on sustained |
| `provider_auth` | Provider rejected our credential (401, invalid key, revoked) | **502** | **no** | no (§8.7) | alert; page if >1 org in 15 min |
| `provider_rate_limit` | Provider 429 / quota / concurrency limit | 429 + `Retry-After` | bounded, `Retry-After` as floor | yes **when configured** (§8.7) | alert on sustained |
| `provider_billing` ² | Account credit or quota exhausted: OpenAI 429 `insufficient_quota`, Anthropic/DeepSeek 402 | **502** | **no** | no | **page** immediately |
| `provider_temporary` | Provider 5xx, 529, connection reset, connect or first-token timeout | 503 | bounded | yes | page if breaker open >5 min |
| `provider_permanent_request` | Provider 4xx we caused: bad params, unknown model, context too large, content-policy refusal | 502 (refusal → see gotchas) | **no** | no | page (it is our bug) |
| `retrieval` | Qdrant query/fusion/rerank failed or timed out | 503 | bounded (query only) | n/a | page — chat is down |
| `parsing` | Document parser failed or timed out | job; 422 on source detail | only if transient; unsupported file **no** | n/a | no |
| `ocr` | OCR failed on a page | job; partial success allowed (§13.7) | per page, bounded | n/a | no |
| `crawl` | Fetch/robots/render failure for a URL | job; per-page in crawl report | 5xx & timeout yes, 4xx **no** | n/a | no |
| `vector_indexing` | Qdrant upsert/delete/alias failure | 503 | yes, from stored normalized chunks (§13.7) | n/a | alert |
| `storage` | Object storage read/write/presign failure | 503 | bounded | n/a | alert |
| `internal_dependency` | PostgreSQL, Valkey, Laravel↔FastAPI, embedding or rerank service unreachable | 503 | bounded, **owning tier only** | n/a | page |
| `user_cancellation` | Client aborted the stream or cancelled a job | **499** | **no** | no (§8.7) | no |

² **`provider_billing` exists because without it an exhausted account is retried.** OpenAI expresses it as **429 `insufficient_quota`** — the same status as a rate limit, raised by the SDK as the same `RateLimitError` — so it lands in `provider_rate_limit`, which is retryable *and* fallback-eligible. The one condition that will never self-heal gets the full backoff ladder, then silently falls back, and nobody is paged. Anthropic and DeepSeek use 402 for the same state. **Fallback is `no`** because an org that configured a fallback authorized it for provider *outages*, not for quietly moving its spend to a second account because an invoice went unpaid — the failure should be loud. That trade is genuinely arguable and is recorded in `docs/22` for ADR ratification. Never counted toward the circuit breaker: it is a credential property, not a sick dependency.

¹ The class is `authorization` either way — only the rendered status differs by surface. Authenticated admin surfaces return **403**; the public runtime and SDK surfaces return **404**, because a 403 on a foreign identifier confirms the row exists and turns the endpoint into an enumeration oracle. Never branch on this in retry or fallback logic — branch on `error_class`, which is identical in both cases. Owned by `kb-security-baseline`.

Provider-specific error shapes (which SDK exception or status maps to which class) belong to `kb-provider-adapter-contract`. It maps *into* this table; it does not extend it.

### Timeout budgets

| Timeout | Default | Nested inside | Breaks when wrong |
|---|---|---|---|
| Chat request deadline (client → Laravel) | 60 s | — outermost | — |
| Internal service call (Laravel → FastAPI) | 55 s | chat deadline | Leaves no room for Laravel to write usage and close the SSE stream cleanly |
| Retrieval leg (embed + dense + sparse + fuse + rerank) | 8 s | internal service call, **in series** with the provider budget | §23 targets 1.5 s; 8 s is the hard stop, not the goal |
| — Embedding batch (query) | 5 s | retrieval leg | |
| — Qdrant query | 2 s per leg | retrieval leg | |
| Provider total response | 45 s | internal service call (8 + 45 = 53 < 55 ✓) | |
| — Provider first token | 20 s | provider total (§23 targets 4 s) | |
| — Provider connection | 3 s | provider first token | |
| Ingestion job soft limit | 900 s | Celery `soft_time_limit` < broker visibility timeout | Exceeding visibility timeout redelivers a *still-running* task — two workers, duplicate vectors |
| — Document parsing | 300 s per document | ingestion job | |
| — OCR page | 30 s per page | document parsing | 30 × pages must stay under 300 s, or cap pages per task |
| Crawl request | 20 s per URL | crawl-run job | |

The rule is arithmetic, not vibes: `attempts × (inner timeout + max backoff) + overhead < outer timeout`. Provider retries are budgeted *inside* the 45 s total, so a 20 s first-token timeout permits at most two attempts.
<!-- UNVERIFIED --> All numeric defaults are chosen to satisfy §23's latency targets with headroom; the spec (§19.4) names the nine timeouts but sets no values. Re-derive from measured p99.9 per AWS's method once real traffic exists.

### Retry ownership

| Call | Retries | Everyone else |
|---|---|---|
| Provider HTTP/stream | FastAPI provider adapter (max 2 retries) | Provider SDK `max_retries=0`; Laravel no retry; TanStack Query `retry: false` on chat mutations |
| Qdrant, object storage, embedding service | The FastAPI adapter that calls it | Celery task does not also retry the same call |
| Ingestion stage | Celery task-level retry at the safe boundary (§13.7) | Do not additionally retry inside the stage |
| Laravel → FastAPI | Nobody for chat. Job submission only, and only on connect failure | |

Backoff is full jitter, capped: `sleep = uniform(0, min(cap, base × 2**attempt))`. Provider `base=0.5 s, cap=20 s`; internal `base=0.2 s, cap=5 s`. A **retry budget** caps retries at 10% of that adapter's requests over a rolling 60 s; over budget, fail fast as `internal_dependency` instead of retrying.

### One complete adapter loop (FastAPI)

```python
async def call_with_policy(adapter, req, deadline: float, breaker: Breaker) -> Response:
    """deadline is an absolute monotonic time propagated from Laravel's header,
    never a fresh duration — that is what keeps inner budgets under the outer one."""
    if not breaker.allow():                      # open, or half-open with probes in flight
        raise KbError("provider_temporary", degraded=True, retryable=False)

    last: KbError | None = None
    for attempt in range(3):                     # 1 try + 2 retries, budgeted inside 45 s
        remaining = deadline - time.monotonic()
        if remaining <= adapter.min_useful_seconds:
            raise KbError("provider_temporary", detail="deadline exhausted")

        try:
            resp = await adapter.send(req, timeout=min(adapter.total, remaining))
            breaker.record_success()
            return resp
        except Exception as exc:
            err = adapter.classify(exc)          # kb-provider-adapter-contract owns this map
            breaker.record(err)                  # only breaker-eligible classes count
            if not RETRYABLE[err.error_class] or req.tokens_emitted:
                raise err                        # emitted tokens ⇒ already billed, never retry
            if not retry_budget.take(adapter.name):
                raise KbError("internal_dependency", detail="retry budget exhausted")
            last = err
            floor = err.retry_after or 0.0       # provider's Retry-After is a floor, not a hint
            back = random.uniform(0, min(20.0, 0.5 * 2 ** attempt))
            await asyncio.sleep(max(floor, back))
    raise last
```

### Circuit breakers and degraded state

Scope one breaker per `(org_id, provider_credential, model)` for credential-scoped classes — **plus an upstream term when the provider is a gateway.** OpenRouter serves one model from many upstreams, so a three-term key conflates a Together brownout with a healthy Fireworks endpoint. There, upstream-attributed failures key on `(org_id, connection_id, model, upstream_slug)`; gateway-attributed ones (402, platform 429, 401, moderation 403, unsatisfiable 503, any 5xx with no upstream code) key on `(org_id, connection_id, "_gateway")` and open every model on that credential, because they are account properties; unattributable timeouts go gateway-wide, deliberately pessimistic. The upstream slug is a **Valkey key component only, never a metric label** — the slug set grows without our involvement (`kb-observability-conventions`). Details in `openrouter-api`. Also scope and one global breaker per shared dependency (Qdrant, PostgreSQL, Valkey, object storage, embedding, reranker). **Only `provider_temporary`, `provider_rate_limit`, `retrieval`, `storage`, `internal_dependency`, and timeouts count toward the breaker.** Open at ≥50% failures over a 30 s window with ≥20 samples; stay open 30 s, doubling to a 5 min cap on each reopen; half-open admits at most 3 concurrent probes and closes only after 3 consecutive successes, reopening on the first failure. Open breakers surface on the admin dashboard as degraded provider/dependency state (§19.3).

**Degradation matrix.** Fail silently, chat continues: analytics aggregation, tracing export, answer/retrieval cache, usage-estimate refresh. Degrade *and record the event*: reranker unavailable → fused retrieval only, **only if bot policy permits**; primary model unavailable → configured fallback (§8.7). Fail loudly: PostgreSQL, Qdrant, credential decryption, auth/authz, tenant-filter construction, and empty retrieval — which produces a refusal, not an invention.

## Gotchas

- **Provider dashboard shows 9–27× your logged request count during an incident; your own logs show one attempt per request.** Every tier retried: TanStack Query defaults to 3 attempts, the OpenAI/Anthropic Python SDKs default to 2 internal retries, and the adapter added its own. Set `max_retries=0` on every provider SDK client, `retry: false` on chat mutations, and let only the adapter retry.
- **429 rate stays pinned at 100% long after traffic drops.** Rejected requests still consume the provider's per-minute request slot, so a tight retry loop keeps you inside the rejection window indefinitely. Honor `Retry-After` as a *floor* under the jittered backoff, and shed concurrency for the next window — delaying without reducing in-flight requests does not clear it.
- **Token usage is 2–3× conversation count, and users see duplicate assistant text.** A completion is not idempotent: once the provider has emitted a token you have been billed, and the retry bills again. Guard every retry and every fallback with `tokens_emitted == 0`; after first token, convert failures into a stream error event and end the turn.
- **FastAPI logs show attempts 2 and 3 succeeding after Laravel already returned 504.** The inner budget exceeded the outer, so the inner tier paid for completions no user ever saw. Propagate an absolute deadline on every internal call and check remaining time before each attempt — never restart a fresh duration downstream.
- **`failed_jobs` fills with the same `source_id` at max attempts while the retry-count metric climbs against a flat success rate.** A permanent 400 (unsupported file, unknown model, context too large) was classified temporary. Classification defaults to permanent; anything unmapped raises `provider_permanent_request` *and* an alert so the map gets fixed, rather than retrying forever.
- **One tenant's bad API key or malformed requests take chat down for every tenant.** `validation`, `authentication`, `authorization`, `tenant_quota`, `provider_auth`, `provider_permanent_request`, and `user_cancellation` were counted toward the breaker. They are all caller-fault or per-credential — excluding them is what makes the breaker mean "the dependency is sick."
- **A breaker sits open for hours with the dependency healthy.** Either the probe was a `/health` ping on a different code path (returns 200 while inference is dead → false close, then instant reopen), or the probe ran with a shortened timeout against a still-draining backlog so it could never succeed. Probe with real traffic, at the normal timeout, capped to 3 concurrent — and never shrink the probe interval as open time grows.
- **Error-rate alarms fire during a normal demo.** `user_cancellation` was folded into the error rate. It is a 499, it is expected, and §20.2 tracks cancellation rate separately from error rate. Concretely that means `outcome="cancelled"` on `kb_chat_requests_total`, **not** a new metric name — `kb-observability-conventions` owns the catalog and an uncatalogued name fails the CI `/metrics` diff. The cancelled stream must still finalize usage, because the provider billed the tokens it already generated.
- **A pod restart loop when the reranker is down.** Readiness failed on an optional dependency. Readiness fails only when a *required* dependency prevents useful service (§20.4); optional-dependency breakers report degraded, they do not fail readiness.
- **An org receives another org's answer on a retry.** The idempotency key was client-supplied and not namespaced. Every key is stored as `idem:{org_id}:{operation}:{key}` in Valkey (24 h TTL) plus a durable record; a replay returns the stored response only on an exact `(org_id, operation, request-hash)` match, and a mismatched hash is a `validation` error, not a fresh execution. Required for file ingestion, crawl runs, source deletion, provider-usage finalization, and message submission (§19.5).
- **Rate-limit handling doubles latency past the 4 s first-token target.** §19.2 permits retrying a rate-limit and §8.7 permits falling back on one, so both fired in series. Ordering rule: when fallback is configured for `provider_rate_limit`, retry the primary **at most once**, then fall back; never run the full retry ladder and then the fallback.
- **Every fallback and every degradation must be written to telemetry and conversation diagnostics** (§8.7) — a silent fallback looks like the primary model produced the answer, and the eval suite then scores the wrong model.

## Not defined here

- Provider-specific error shapes and the SDK-exception → class map — `kb-provider-adapter-contract`.
- The wire format that carries an error between Laravel and FastAPI — `kb-internal-api-contracts`.
- Metric and log field names, cardinality rules, trace attributes — `kb-observability-conventions`.
- Which ingestion state a failure moves a source into — `kb-source-lifecycle`.

## Official docs

- [AWS Builders' Library — Timeouts, retries, and backoff with jitter](https://aws.amazon.com/builders-library/timeouts-retries-and-backoff-with-jitter) — deriving timeouts from p99.9, capped backoff, why jitter belongs on all timers.
- [AWS Architecture Blog — Exponential Backoff and Jitter](https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/) — the full-jitter measurement this skill's formula comes from.
- [Google SRE — Addressing Cascading Failures](https://sre.google/sre-book/addressing-cascading-failures/) — retry amplification across tiers, retry budgets, retry at one layer only.
- [Azure Architecture Center — Circuit Breaker](https://learn.microsoft.com/en-us/azure/architecture/patterns/circuit-breaker) — state machine and half-open probing.
- [gRPC deadlines and propagation](https://grpc.io/docs/guides/deadlines/) — deadline-as-absolute-time, the model our internal deadline header follows.
- [Celery Tasks — retries and time limits](https://docs.celeryq.dev/en/stable/userguide/tasks.html) — `autoretry_for`, `retry_backoff`, `retry_jitter` (default `True`), `retry_backoff_max` (default 600 s), `acks_late`.

## Definition of done

- [ ] Every raised error carries one of the 18 `error_class` values; a grep for handlers raising untyped exceptions across `services/ai-service/` returns nothing.
- [ ] Provider SDK clients are constructed with `max_retries=0`; frontend chat mutations set `retry: false`.
- [ ] Every internal call reads an absolute deadline from the request and passes the remainder down; no call constructs a fresh duration.
- [ ] Timeout arithmetic verified: for each nesting level, `attempts × (inner + max backoff) + overhead < outer`.
- [ ] Breaker counts only the six breaker-eligible classes; a test asserts that 100 `validation` errors leave the breaker closed.
- [ ] A test asserts no retry and no fallback occurs once `tokens_emitted > 0`.
- [ ] Idempotency keys are namespaced by `org_id`; a replay with the same key under a different org is rejected.
- [ ] Cancellation is excluded from the error-rate metric and still finalizes usage.
- [ ] Readiness fails only for required dependencies; an open reranker breaker keeps the pod ready.
- [ ] Log assertions confirm no key, password, `Authorization` header, or unredacted user content in any error payload (§20.3).

# Structured logs, the telemetry/audit split, and health endpoints

Companion to `kb-observability-conventions/SKILL.md`, which owns the rules these sections mechanise.
Field names below are as permanent as metric and span names: a log field a Loki query or an alert
runbook references cannot be renamed without breaking both.

## Structured logs

One JSON object per line on stdout, RFC3339 UTC. **Required on every line:** `timestamp`, `severity`,
`service`, `env`, `trace_id`, `span_id`, `request_id`, `operation`. **When applicable:** `org_id`,
`bot_id`, `job_id`, `error_class`, `duration_ms`. `org_id` and `bot_id` are opaque ULIDs and belong in
logs — that is what makes a tenant incident debuggable; §20.3's "where safe" means *not as metric
labels* and *not shipped outside the DPA*, not "omit them". Durations are `duration_ms` in logs and
seconds in metrics; the asymmetry is deliberate. **Never logged, at any level, in any environment:**
provider API keys, passwords, `Authorization` and `X-KB-Signature` values, session tokens and cookies,
the user's question, retrieved chunk text, the assembled prompt, model output, file contents. Evaluation
content capture goes to the eval store behind the org's privacy switch (§18.10) — never to Loki, which has no per-tenant access control.

## Telemetry vs audit

| | Telemetry (traces, metrics, logs) | Audit log |
|---|---|---|
| Store / lifetime | Tempo, Prometheus, Loki; retention window, then gone | PostgreSQL `audit_logs`, append-only, outlives the record it describes |
| Completeness | **Sampled and lossy by design** | **Every event, never sampled** |
| Write coupling | Fire-and-forget; failure degrades silently (§19.6) | In the operation's transaction; failure fails the operation |
| Reader | On-call, mid-incident | Compliance, the tenant, an investigator months later |

Put audit rows in Loki and sampling plus retention delete the compliance record silently — you find out
when someone asks who deleted a source in March. Put telemetry in `audit_logs` and the audit table
becomes the hottest write path in the system, unqueryable exactly when it is needed. What audit
captures: `kb-security-baseline`.

## Liveness vs readiness

| Service | `/health/live` — process only, **zero dependency I/O** | `/health/ready` — required deps | Degraded, does **not** fail readiness |
|---|---|---|---|
| Laravel `core-api` | PHP-FPM answers | PostgreSQL, Valkey | FastAPI, object storage, any provider |
| FastAPI `ai-api` | event loop responsive | Qdrant, Valkey, embedding model loaded | reranker, object storage, provider credentials |
| Celery / queue worker | heartbeat file touched within 2× the longest poll | broker reachable, `celery inspect ping` | reranker, OCR engine |

Readiness caches its dependency check for 5 s — uncached, probe interval × replica count is a
self-inflicted load test on PostgreSQL. `/health/deps` returns per-dependency detail and is
**admin-authenticated**; it maps the topology. Optional-dependency breakers report degraded (§19.3) and never fail readiness (`kb-error-taxonomy`).


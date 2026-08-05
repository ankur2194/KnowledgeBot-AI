# Retention windows and the correlation chain

Companion to `prometheus-grafana-loki-tempo/SKILL.md`. Both settings below are checked into git and
mounted read-only; neither is adjustable from any UI.

## Retention — four stores, four windows

| Store | Window | Where it is set | What it costs / why |
|---|---|---|---|
| Prometheus | **15d**, hard cap 20 GB | `--storage.tsdb.retention.time=15d --storage.tsdb.retention.size=20GB` | ~50k series at a 15 s scrape ≈ 0.5 GB/day compressed <!-- UNVERIFIED: derived from ~1.7 bytes/sample, not measured — re-derive from `prometheus_tsdb_head_series` once real traffic exists -->. The size cap is the safety net: enforced *after* the time window, so a cardinality incident truncates history instead of filling the disk. |
| Loki | **30d** | `limits_config.retention_period: 720h` **plus** `compactor.retention_enabled: true` and `delete_request_store` | Logs are the tenant-incident record (`org_id` is a *field*, so debugging one tenant means 30d of JSON). Cheapest of the four per byte; minimum permitted is 24h. |
| Tempo | **7d** | `backend_scheduler.provider.compaction.block_retention: 168h` (default 336h) | Most expensive per byte and least often read past a week. Head-100% sampling with Collector tail sampling keeps volume sane. |
| Grafana | n/a | provisioned; `grafana.db` on a volume for sessions only | Nothing to retain — that is the point. |

On 8–16 GB / fast SSD (§24.8) this is roughly 8 GB Prometheus + 20–40 GB Loki + 15–30 GB Tempo. All three are **separate named volumes**; one filling must not take the others down.

## Correlation — `request_id` → `trace_id` → span, and back

Clients never receive `traceparent` (`kb-observability-conventions` rule 4); they receive `X-KB-Request-Id`. So the chain from a support ticket is: **`request_id` → Loki (`| json | request_id="…"`) → `trace_id` → Tempo**. Wire both directions in datasource provisioning:

- Tempo datasource, `tracesToLogsV2`: `datasourceUid: loki`, `filterByTraceID: true`, `tags: [{key: service.name, value: service}]`, and `spanStartTimeShift: -1h` / `spanEndTimeShift: 1h`.
- Loki datasource, `derivedFields`: `matcherType: label`, `name: trace_id`, `datasourceUid: tempo`, `url: "${__value.raw}"` — `matcherType: label` works because the Collector writes `trace_id` as **structured metadata** (requires `tsdb` + `schema: v13`), so no regex over the line body is needed. <!-- UNVERIFIED: `matcherType` accepts `label` as well as `regex`; confirm against the pinned Grafana before relying on it, and keep a `matcherRegex` over the JSON as the fallback. -->

Confirm both directions against a real trace before shipping — a mistyped `datasourceUid` produces a link that renders and goes nowhere, with no error in any log.


---
name: prometheus-grafana-loki-tempo
description: The self-hosted telemetry backend — Prometheus rules, Alertmanager routing, Loki logs, Tempo traces, and Grafana as a provisioned read-only surface. Use whenever writing a recording rule, an alert, a dashboard, a retention setting, a Loki label, or a trace-to-logs link under infrastructure/observability/. Owns what pages and how long data lives; the metric catalog it queries is closed and belongs to kb-observability-conventions. Pairs with kb-error-taxonomy (which classes page).
---

# Prometheus, Grafana, Loki, Tempo — the telemetry backend

Prometheus **3.13.2**, Alertmanager **0.33.1**, Grafana **13.1.2**, Loki **3.7.5**, Tempo **3.0.2** (monolithic, `target: all`). One Compose profile, `observability` (§24.5), on the `observability` network (§24.3).
**Authoritative spec:** docs/15-observability.md §20, docs/14-reliability.md §19.3/§19.6, docs/18-deployment-backup-cicd.md §24.2/§24.5/§25.1

## Non-negotiables

- **The metric catalog is closed.** Every series a rule, panel, or alert references exists in `kb-observability-conventions` and its `references/metric-catalog.md` **before** it exists here. If a dashboard needs a number that no catalogued metric answers, **report the gap and stop** — a metric invented in a PromQL expression is a metric nobody emits, and the panel reads "No data" forever while looking configured.
- **The error rate is `outcome=~"error|timeout"`, never `outcome="error"`.** `timeout` is a fourth `outcome` value precisely because `kb-error-taxonomy` has no timeout class, so the obvious matcher silently omits every timed-out request — the exact failure mode you are alerting on. Write it **once**, as a recording rule, and let every alert and panel reference the recorded series. `cancelled` stays out (a user hanging up is not our failure); `skipped` exists only on the scheduler family.
- **The label allow-list from `kb-observability-conventions` binds Loki as hard as Prometheus.** `org_id`, `bot_id`, `conversation_id`, `source_id`, `job_id`, `url` and any query text are banned as **Loki stream labels** too — there they are an operational failure as well as a privacy one (Gotchas). Loki labels are `service`, `env`, `level`, `container` and nothing else; every other field stays inside the JSON line. **A per-tenant question is a PostgreSQL query, not a PromQL query** — the `usage` table and the analytics aggregates (§8.22) answer "what did org X spend"; Prometheus only answers "is the system healthy".
- **Everything in `infrastructure/observability/` is provisioned as code and Grafana owns no state.** Datasources, dashboards, rule files, Alertmanager routing — all in git, all mounted read-only. §25.1 requires dashboards to be backed up; provisioned from files, the repo *is* the backup and a restore is `docker compose up`. Grafana's own `grafana.db` holds only sessions and users.
- **Alerting lives in Prometheus + Alertmanager, not Grafana Alerting.** Rules evaluate whether or not Grafana is running, they are the same PromQL as the recording rules they consume, and file-provisioned Grafana rules are read-only in the UI anyway — so Grafana-managed rules buy nothing and add a second evaluation engine with its own state.
- **This stack is lossy by design and is never a business record** (`kb-observability-conventions`, §19.6). Cost comes from the `usage` table, not `kb_provider_cost_usd_total`; who deleted a source comes from `audit_logs`, not Loki. Retention below deletes all of it on schedule.

## How we use it

```
infrastructure/observability/
├── prometheus/prometheus.yml        rules/kb-recording.yml  rules/kb-alerts.yml
├── alertmanager/alertmanager.yml
├── loki/loki.yml      tempo/tempo.yml
└── grafana/provisioning/{datasources,dashboards}/*.yml   grafana/dashboards/*.json
```

Compose services, ports, volumes and network membership → `docker-compose-stack`. What emits the data — SDKs, exporters, the Collector pipeline → `opentelemetry-instrumentation`.

### The recording rule and the alerts that reference it

```yaml
# infrastructure/observability/prometheus/rules/kb-recording.yml
# Rules inside ONE group evaluate sequentially, in file order; rules in DIFFERENT groups
# evaluate concurrently. So the recorded series and the alert that consumes it live in the
# same group, recording first — otherwise the alert reads last interval's value, and reads
# nothing at all for the first interval after a reload.
groups:
  - name: kb_chat
    interval: 30s
    rules:
      # The ONE definition of "an error". Colons are reserved for recorded series: a rule
      # named `kb_chat_requests_total` would merge with the scraped series of that name and
      # PromQL would silently return doubled values.
      - record: kb:chat_requests:rate5m
        expr: sum by (service, env, operation) (rate(kb_chat_requests_total[5m]))
      - record: kb:chat_errors:rate5m
        expr: |
          sum by (service, env, operation) (
            rate(kb_chat_requests_total{outcome=~"error|timeout"}[5m])
          )
      - record: kb:chat_error_ratio:5m
        expr: kb:chat_errors:rate5m / kb:chat_requests:rate5m

      - alert: KbChatErrorRateHigh
        # References the recorded ratio — never re-derives the matcher. The second term is
        # the traffic floor: at 03:00 one failure out of one request is a 100% error ratio.
        expr: kb:chat_error_ratio:5m > 0.05 and kb:chat_requests:rate5m > 0.1
        for: 10m
        keep_firing_for: 5m          # stops a flapping provider paging four times in an hour
        labels: {severity: page}
        annotations:
          summary: "Chat error+timeout ratio {{ $value | humanizePercentage }} on {{ $labels.service }}"
          runbook: "Split by error_class: sum by (error_class) (rate(kb_chat_requests_total{outcome=~\"error|timeout\"}[5m]))"

  - name: kb_scheduler
    interval: 30s
    rules:
      # Layer 1 of three. The gauge is exported by laravel-api from a PostgreSQL row SEEDED
      # IN THE MIGRATION (`laravel-scheduler`) — a scheduler that never started after a deploy
      # reads as an ancient timestamp, not a missing series. You cannot threshold-alert a
      # series that never existed, and the scheduler container has no scrape target of its own.
      - alert: KbSchedulerStalled
        expr: time() - kb_internal_scheduler_tick_timestamp_seconds > 900
        for: 5m
        labels: {severity: page}
        annotations: {summary: "Scheduled task {{ $labels.task }} has not ticked in 15m"}

      # `skipped` is outside the error rate (a skip is the lock working) but needs its own
      # alert, because its failure mode is SILENCE: withoutOverlapping() registers a skip
      # filter, so a lock stranded by SIGKILL — default TTL 24h — leaves the tick timestamp
      # advancing while no work happens. KbSchedulerStalled stays green throughout.
      - alert: KbSchedulerSkipStuck
        expr: increase(kb_internal_scheduler_tasks_total{outcome="skipped"}[30m]) >= 5
        for: 0m
        labels: {severity: ticket}
        annotations:
          summary: "{{ $labels.task }} skipped every tick for 30m — stranded mutex, not contention"
          runbook: "php artisan schedule:clear-cache; see laravel-scheduler Gotchas"
```

Layer 2 is `->pingOnSuccess()` to a **dead-man's switch outside this stack** — layer 1 dies with Prometheus. Layer 3 is `kb_crawl_sources_overdue`, computed from PostgreSQL on scrape: the only signal that catches a scheduler ticking perfectly while the work does not happen (`alert: kb_crawl_sources_overdue > 50 for: 30m`).

### What pages, what waits for morning

Severity is not invented here — it is `kb-error-taxonomy`'s **Page** column, mechanised. Route `severity: page` to the pager, `severity: ticket` to a chat channel with `repeat_interval: 12h`.

| Pages now | Waits (`ticket`) | Never alerts |
|---|---|---|
| `error_class="provider_billing"` on **any** sample (never self-heals, never retried) | sustained `rate_limit` — *our* limiter rejecting callers | `validation`, `authentication`, `authorization` |
| `retrieval`, `internal_dependency` — chat is down | `vector_indexing`, `storage` | `tenant_quota` (a plan boundary, not a fault) |
| `provider_permanent_request` — it is our bug | `provider_auth` (but see Gotchas) | `parsing`, `ocr`, `crawl` — per-job, shown on the source detail |
| `kb_circuit_breaker_state == 2` for >5m | `kb_dependency_up{required="false"} == 0` | `user_cancellation` — an `outcome`, deliberately outside the error rate |
| `KbChatErrorRateHigh`, `KbSchedulerStalled`, dead-man's-switch silence | `KbSchedulerSkipStuck`, `kb_crawl_sources_overdue` | insufficient-evidence rate — a *product* number, dashboard only |

### Retention — four stores, four windows

| Store | Window | Where it is set | What it costs / why |
|---|---|---|---|
| Prometheus | **15d**, hard cap 20 GB | `--storage.tsdb.retention.time=15d --storage.tsdb.retention.size=20GB` | ~50k series at a 15 s scrape ≈ 0.5 GB/day compressed <!-- UNVERIFIED: derived from ~1.7 bytes/sample, not measured — re-derive from `prometheus_tsdb_head_series` once real traffic exists -->. The size cap is the safety net: enforced *after* the time window, so a cardinality incident truncates history instead of filling the disk. |
| Loki | **30d** | `limits_config.retention_period: 720h` **plus** `compactor.retention_enabled: true` and `delete_request_store` | Logs are the tenant-incident record (`org_id` is a *field*, so debugging one tenant means 30d of JSON). Cheapest of the four per byte; minimum permitted is 24h. |
| Tempo | **7d** | `backend_scheduler.provider.compaction.block_retention: 168h` (default 336h) | Most expensive per byte and least often read past a week. Head-100% sampling with Collector tail sampling keeps volume sane. |
| Grafana | n/a | provisioned; `grafana.db` on a volume for sessions only | Nothing to retain — that is the point. |

On 8–16 GB / fast SSD (§24.8) this is roughly 8 GB Prometheus + 20–40 GB Loki + 15–30 GB Tempo. All three are **separate named volumes**; one filling must not take the others down.

### Correlation — `request_id` → `trace_id` → span, and back

Clients never receive `traceparent` (`kb-observability-conventions` rule 4); they receive `X-KB-Request-Id`. So the chain from a support ticket is: **`request_id` → Loki (`| json | request_id="…"`) → `trace_id` → Tempo**. Wire both directions in datasource provisioning:

- Tempo datasource, `tracesToLogsV2`: `datasourceUid: loki`, `filterByTraceID: true`, `tags: [{key: service.name, value: service}]`, and `spanStartTimeShift: -1h` / `spanEndTimeShift: 1h`.
- Loki datasource, `derivedFields`: `matcherType: label`, `name: trace_id`, `datasourceUid: tempo`, `url: "${__value.raw}"` — `matcherType: label` works because the Collector writes `trace_id` as **structured metadata** (requires `tsdb` + `schema: v13`), so no regex over the line body is needed. <!-- UNVERIFIED: `matcherType` accepts `label` as well as `regex`; confirm against the pinned Grafana before relying on it, and keep a `matcherRegex` over the JSON as the fallback. -->

Confirm both directions against a real trace before shipping — a mistyped `datasourceUid` produces a link that renders and goes nowhere, with no error in any log.

### Dashboards

Five JSON files, provisioned with `allowUiUpdates: false`, `disableDeletion: false`, `foldersFromFilesStructure: true`: **Chat SLO** (RPM, `kb:chat_error_ratio:5m`, first-token p95, active streams, cancellation and fallback rate), **Retrieval** (per-`stage` p95 against the 1.5 s budget, candidate funnel, empty-retrieval by `reason`), **Providers** (per `provider`/`model` success, latency, tokens, cost), **Ingestion & Crawl** (queue depth, stage duration by `file_type`, `kb_crawl_pages_total` by `outcome`, `status_class` distribution), **Platform** (`kb_build_info`, `kb_dependency_up`, `kb_circuit_breaker_state`, scheduler freshness, plus node/cAdvisor/postgres/redis exporters). No dashboard has an org or bot variable — there is no series to fill it.

## Gotchas

- **The error-rate panel reads 0.4% during an outage in which a third of chat requests time out.** `outcome="error"` was written directly into the panel. Timeouts carry `outcome="timeout"` and are invisible to that matcher, so the dashboard is calmest exactly when the provider is slowest. One recording rule, referenced everywhere; a CI grep for `outcome="error"` outside `kb-recording.yml` fails the build.
- **A rule that mentions a tenant cannot be written, and someone adds the label to make it work.** `kb-error-taxonomy` says page `provider_auth` when it affects >1 org in 15 min — unexpressible, because `org_id` is not and never will be a metric label. Split it: PromQL alerts on the *sustained rate*, and the runbook annotation carries the SQL that counts distinct organizations. Every alert whose condition names a tenant resolves this way. Adding the label instead is the OOM described in `kb-observability-conventions`.
- **Loki starts rejecting writes with "maximum active stream limit exceeded", queries time out, and the ingesters OOM — days after a "small" logging change.** Someone promoted `org_id` (or `job_id`, or `url`) to a stream label. A Loki stream is one chunk per unique label-set, so streams multiply combinatorially and each one holds an under-filled chunk in memory. Labels stay `service`, `env`, `level`, `container`; `org_id`, `bot_id`, `job_id`, `error_class` live inside the JSON line and are queried with `| json | org_id="…"` — line filters are fast enough because the label set already narrowed the stream.
- **Loki disk grows forever while `retention_period: 720h` sits in the config.** `compactor.retention_enabled` defaults to **false**: without it the compactor compacts indices and deletes nothing. It also needs `delete_request_store`, and it must run as a **singleton** — two compactors applying retention corrupt each other's markers. Second trap: retention changes are **not retroactive**, so shortening the window does not reclaim yesterday's disk.
- **After editing `schema_config` all historical logs vanish from queries.** Schema entries are immutable and **additive**: a chunk is read with the schema in force at its timestamp, so changing an existing entry's `from`, `store`, or `schema` orphans every chunk written under it. Migrate by appending a new entry with a **future** `from` date. Structured metadata (which carries `trace_id`) requires `store: tsdb` and `schema: v13`; on v12 the write is rejected and the Tempo↔Loki links quietly stop working.
- **Tempo will not start after the 3.x bump, complaining about unknown config fields.** 3.0 deleted the `ingester`, `ingester_client`, `compactor` and `metrics_generator_client` blocks, dropped the scalable-single-binary target, and moved block retention to `backend_scheduler.provider.compaction.block_retention`. Monolithic `target: all` needs **no Kafka** — the distributor pushes in-process to the live-store and metrics-generator — but microservices mode does, which is the whole reason we stay monolithic on one box. **There is no downgrade path from 3.0 to 2.x**; snapshot the volume before upgrading.
- **A trace link in a log line returns "trace not found", and only for older logs.** Loki keeps 30d, Tempo keeps 7d — the link is structurally dead past day 7. Separately, a fresh link that returns nothing usually means the trace-to-logs query is bounded exactly to the span's start and end: the log line's timestamp sits milliseconds outside. Set `spanStartTimeShift`/`spanEndTimeShift`; without them the correlation looks broken while both stores are healthy.
- **Metric names in the dashboards do not match anything Prometheus holds.** The OTLP path was changed. `--web.enable-otlp-receiver` is off by default, and `otlp.translation_strategy` defaults to `UnderscoreEscapingWithSuffixes` — which is what turns `kb.chat.requests` into `kb_chat_requests_total`. Switch it to `NoTranslation` or `NoUTF8EscapingWithSuffixes` and you get dotted UTF-8 names that no rule, panel, or alert in the repo matches, with no error anywhere. Keep the default. If more than one Collector replica writes the same series, set `storage.tsdb.out_of_order_time_window: 30m` or half the samples are silently rejected as out-of-order.
- **`histogram_quantile` returns nonsense, or nothing.** Two causes. Aggregating away `le` before the quantile (`sum(rate(..._bucket[5m]))`) leaves no buckets to interpolate — always `sum by (le) (rate(..._bucket[5m]))`, adding any grouping labels *alongside* `le`. And a quantile above the largest finite bucket returns `+Inf`, which is why the shared bucket sets in the catalog put an edge at 4 s and 60 s rather than trusting defaults. We use **classic** histograms: OTLP explicit-bucket histograms map to them directly, and native histograms remain experimental.
- **Grafana comes back from a volume restore with every dashboard gone, or a panel silently rewritten mid-incident.** A dashboard built in the UI exists only in `grafana.db`; §25.1's "back up Grafana dashboards" then means backing up a SQLite file nobody diffs, and an on-call edit is invisible to review. Provision from JSON with `allowUiUpdates: false` so the UI's Save button is disabled rather than divergent. Same reasoning for Alertmanager: a silence is ephemeral state, a route is code.
- **An alert flaps four times in an hour for one provider brownout, or fires once and then goes quiet during a rolling incident.** `for:` restarts from zero on every interval the expression is false, so a marginal condition never accumulates; `keep_firing_for` is what holds a resolved alert open across the gap. Pair them, and set Alertmanager `group_by: [alertname, service]` — grouping by a label the alert does not carry sends every instance as its own notification.
- **Prometheus shows the alert firing and nobody is notified.** Alertmanager routing matched a `severity` value no route defines and fell through to a default receiver that goes nowhere. The severity vocabulary is exactly two values, `page` and `ticket`; a CI check asserts every rule carries one of them and that both have a route.

## Official docs

- [Prometheus — recording rules](https://prometheus.io/docs/prometheus/latest/configuration/recording_rules/) and [alerting rules](https://prometheus.io/docs/prometheus/latest/configuration/alerting_rules/) — group evaluation order, `for`, `keep_firing_for`, templating.
- [Prometheus — OpenTelemetry support](https://prometheus.io/docs/guides/opentelemetry/) — `--web.enable-otlp-receiver`, the four `translation_strategy` values, `promote_resource_attributes`, out-of-order ingestion.
- [Prometheus — storage & retention](https://prometheus.io/docs/prometheus/latest/storage/), [histograms](https://prometheus.io/docs/practices/histograms/) — TSDB sizing, `retention.size` semantics, `histogram_quantile` and `le`.
- [Alertmanager configuration](https://prometheus.io/docs/alerting/latest/configuration/) — routing tree, `group_by`, `group_wait`, `repeat_interval`, inhibition.
- [Loki — storage & schema](https://grafana.com/docs/loki/latest/configure/storage/) and [retention](https://grafana.com/docs/loki/latest/operations/storage/retention/) — TSDB + `schema: v13`, immutable additive schema entries, `retention_enabled`, `delete_request_store`, singleton compactor.
- [Loki — labels and cardinality](https://grafana.com/docs/loki/latest/get-started/labels/) — why a stream label is not a metric label and costs more.
- [Tempo — upgrade to 3.0](https://grafana.com/docs/tempo/latest/setup/upgrade/) and [architecture](https://grafana.com/docs/tempo/latest/introduction/architecture/) — removed blocks, live-store/block-builder/backend-scheduler, monolithic-without-Kafka, no downgrade.
- [Grafana — provisioning](https://grafana.com/docs/grafana/latest/administration/provisioning/) and the [Tempo datasource provisioning example](https://grafana.com/docs/grafana/latest/datasources/tempo/configure-tempo-data-source/provision/) — `allowUiUpdates`, `foldersFromFilesStructure`, `tracesToLogsV2`, `derivedFields`.

## Definition of done

- [ ] Every metric name in every rule file and dashboard JSON exists in `kb-observability-conventions/references/metric-catalog.md`; a CI check diffs the two name sets and fails on a name that exists in neither `/metrics` nor the catalog.
- [ ] `outcome=~"error|timeout"` appears in exactly one place, `kb-recording.yml`; a CI grep for `outcome="error"` in any other rule, dashboard, or docs file returns nothing.
- [ ] Every alert carries `severity: page` or `severity: ticket`, and `amtool config routes test` resolves both to a real receiver. Ratio alerts carry a traffic-floor term.
- [ ] `promtool check rules` and `promtool test rules` pass, including a unit test asserting `kb:chat_error_ratio:5m` counts a timeout and ignores a cancellation.
- [ ] The three scheduler layers are live and independently verified: stopping the `laravel-scheduler` container fires `KbSchedulerStalled`; holding its mutex fires `KbSchedulerSkipStuck` while the freshness gauge stays fresh; stopping the whole Compose stack alerts via the external dead-man's switch.
- [ ] No Loki stream label outside `service`, `env`, `level`, `container` — asserted against `/loki/api/v1/labels` in an integration test; a fixture log line containing `org_id` is queryable via `| json` and produces no new stream.
- [ ] Loki retention proven: `retention_enabled: true`, `delete_request_store` set, exactly one compactor, and a test writes a line with a backdated timestamp beyond the window and asserts it is gone after compaction.
- [ ] Tempo runs `target: all` with no Kafka, `block_retention: 168h`, and the volume was snapshotted before any 3.x upgrade.
- [ ] Round-trip correlation asserted end to end: a chat request's `X-KB-Request-Id` finds one Loki line, whose `trace_id` structured-metadata link opens the Tempo trace, whose trace-to-logs link returns that same line.
- [ ] Grafana starts with zero manual configuration: datasources, dashboards and folders all provisioned, `allowUiUpdates: false`, and deleting the `grafana` volume loses nothing but sessions.
- [ ] Each of the four stores has its own named volume and a retention setting checked into git; Prometheus carries both `retention.time` and `retention.size`.

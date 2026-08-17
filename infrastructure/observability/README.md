# `infrastructure/observability/`

The self-hosted telemetry backend: Prometheus 3.13.2, Alertmanager 0.33.1, Grafana 13.1.2,
Loki 3.7.5, Tempo 3.0.2. Everything here is configuration-as-code, mounted read-only, and owned by
`observability-engineer`.

The **metric catalog is closed and lives elsewhere**:
`.claude/skills/kb-observability-conventions/references/metric-catalog.md`. Every series named in
a rule, an alert or a dashboard panel in this directory exists there first. A metric invented in a
PromQL expression is a metric nobody emits, and the panel reads "No data" forever while looking
perfectly configured.

## Layout

```
infrastructure/observability/
├── README.md                        this file
├── prometheus/
│   ├── prometheus.yml               scrape config. RETENTION IS NOT HERE — it is a CLI flag
│   ├── rules/kb-recording.yml       the four recorded series
│   ├── rules/kb-alerts.yml          eight alerts, and a loud TODO for the ones still missing
│   └── tests/*_test.yml             promtool unit tests — the only green-on-day-one artifact
├── alertmanager/
│   ├── alertmanager.yml             severity routing, two values, plus a catch-all
│   └── templates/kb.tmpl            notification rendering; runbook prose stays on the rules
├── loki/loki.yml                    tsdb + v13, 30d, compactor retention actually enabled
├── tempo/tempo.yml                  monolithic, no Kafka, 7d block retention
├── grafana/
│   ├── provisioning/datasources/    prometheus, loki, tempo + both directions of trace↔log
│   ├── provisioning/dashboards/     allowUiUpdates:false
│   └── dashboards/*.json            five dashboards, empty templating.list
└── exporters/README.md              the do-not-re-implement table and the network prerequisite
```

Compose services, ports, volumes and network membership are `platform-devops-engineer`'s
(`infrastructure/docker/`). What produces the data — SDKs, the Collector pipeline, the OTLP
transport — is `infrastructure/docker/otel/collector.yaml` plus the per-runtime instrumentation.
This directory only says what the backends do with it once it arrives.

## §25.1: provisioning-from-git IS the dashboard backup

docs/18 §25.1 requires "Grafana dashboards and infrastructure configuration" to be backed up.
**That requirement is discharged by this directory existing in git, and this section is the
written record of that decision.**

Taken literally, "back up the Grafana dashboards" means backing up `grafana.db`, a SQLite file.
That is a bad backup in three specific ways, none of which show up until a restore:

1. **Nobody can diff it.** A dashboard edited during an incident is invisible to review. There is
   no commit, no author, no reason — just a file whose bytes changed.
2. **It restores to a moment, not to a state.** Restoring last night's `grafana.db` also restores
   last night's sessions, users and preferences, and silently reverts any dashboard change made
   since. Nothing tells you which panels moved backwards.
3. **It is a second source of truth for something that already has one.** The alert rules and the
   recording rules those panels query are already in git. A dashboard living somewhere else drifts
   from them, and the drift is invisible precisely because the panel still renders.

So: every dashboard is a JSON file in `grafana/dashboards/`, provisioned by
`grafana/provisioning/dashboards/kb.yml`. **The repository is the backup and a restore is
`docker compose up`.** Deleting the `grafana` volume outright loses sessions and user accounts and
nothing else — the `grafana` service in compose carries a comment saying exactly that, and it is
true because of the files in this directory.

**`allowUiUpdates: false` is what makes the sentence above true rather than aspirational.** With it
`true`, the UI's Save button works: an on-call edit is written to `grafana.db`, survives until the
next provisioning reload, and then vanishes — with no reviewer having seen it and no record that
it existed. With it `false` the Save button is disabled, so the UI is read-only and the only way
to change a dashboard is a commit. `disableDeletion: false` is the other half: a dashboard removed
from git is removed from Grafana too, rather than lingering as an orphan whose panels keep
answering questions with whatever metric names they were last saved with.

The same reasoning applies to Alertmanager, and it is why routing is a file while silences are not:
a silence is ephemeral incident state, a route is code.

## Retention — four stores, four windows

| Store | Window | Where the setting actually lives |
|---|---|---|
| Prometheus | **15d**, hard cap 20 GB | `--storage.tsdb.retention.time=15d --storage.tsdb.retention.size=20GB` in the compose `command:` — **not** in `prometheus.yml` |
| Loki | **30d** | `limits_config.retention_period: 720h` **plus** `compactor.retention_enabled: true` **plus** `delete_request_store` |
| Tempo | **7d** | `backend_scheduler.provider.compaction.block_retention: 168h` |
| Grafana | none | provisioned; the volume holds sessions only |

Three traps, one per store:

- **Prometheus retention is a flag, not config.** Writing `retention:` into `prometheus.yml` is
  accepted by the YAML parser and ignored by Prometheus. The disk fills anyway. The size cap is
  enforced *after* the time window, so a cardinality incident truncates history instead of taking
  the host down.
- **Loki's `retention_period` alone deletes nothing.** `compactor.retention_enabled` defaults to
  `false`, and with it false the compactor compacts indices and removes no data at all — disk
  grows forever while the config says 720h. Retention is also **not retroactive**: shortening the
  window does not reclaim yesterday's bytes.
- **The 30d/7d asymmetry between Loki and Tempo is deliberate and visible.** A `trace_id` link in
  a log line older than seven days returns "trace not found". That is structural. Traces are the
  most expensive store per byte and the least read past a week.

## The correlation chain, both directions

Clients never receive `traceparent` — returning it would leak internal topology to a widget on a
third party's page. They receive `X-KB-Request-Id`. So a support ticket is walked like this:

```
request_id  →  Loki: {service="ai-api"} | json | request_id="..."
            →  trace_id (structured metadata on the line)
            →  Tempo: the derived-field link on the Loki datasource
            →  back to Loki: tracesToLogsV2 on the Tempo datasource
```

Both directions are wired in `grafana/provisioning/datasources/`. The two settings that decide
whether it works at all are `matcherType: label` (which needs Loki on `tsdb` + `schema: v13`, or
structured metadata is rejected and `trace_id` never exists) and `spanStartTimeShift`/
`spanEndTimeShift` (without which the log query is bounded to exactly the span's start and end,
and the line describing the span falls milliseconds outside). Confirm both against a real trace
before believing either: a mistyped `datasourceUid` renders a link that goes nowhere with no error
in any log.

## Running the tests

`promtool test rules` needs no stack, no containers, no application code and no network. It is
the only executable, green-on-day-one artifact in this repository, which is why the test files
carry more weight than the rules they cover.

```sh
cd infrastructure/observability/prometheus/tests
promtool test rules kb-recording_test.yml kb-alerts_test.yml kb-scheduler_test.yml
```

Run from that directory. `rule_files:` entries are relative paths and promtool resolves them
against the working directory, so running from the repository root fails with a file-not-found
that reads like a missing rule file rather than a wrong `cd`.

Also worth running when the stack is up:

```sh
promtool check config  infrastructure/observability/prometheus/prometheus.yml
promtool check rules   infrastructure/observability/prometheus/rules/*.yml
amtool check-config    infrastructure/observability/alertmanager/alertmanager.yml
amtool config routes test --config.file=.../alertmanager.yml severity=page severity=ticket
```

## The CI gates this directory is written against

There is no CI in this repository — `.github/` was deleted on 2026-08-17 — so nothing below is
enforced by a gate. It is kept as a specification anyway, for whoever wires this up somewhere else,
and each item stays phrased precisely for the original reason: a gate that is slightly wrong is
worse than none, because it is a green check over a hole.

1. **Catalog diff.** Scrape **the Collector's `prometheus` exporter on `:8889`**, never a service's
   own `/metrics`. Under PHP-FPM and Celery prefork each request lands in a different process, so a
   per-service endpoint returns one worker's fragment: the gate passes while checking almost
   nothing. Diff that name set against the catalog in both directions.
2. **Dashboard and rule name diff.** Every `kb_*` identifier appearing in
   `prometheus/rules/*.yml` and `grafana/dashboards/*.json` must exist in the catalog. Four
   specifics, each of which breaks a naively-written version of this gate — all four were found
   by running it against this directory:
   - Strip the `_bucket`, `_sum` and `_count` suffixes before comparing. A histogram row in the
     catalog is one name; PromQL references three.
   - Read the **`expr:` values only**, not whole files. Prometheus rule GROUP names are
     `kb_chat`, `kb_provider`, `kb_chat_alerts`, `kb_scheduler_alerts`, `kb_platform_alerts` —
     they match `kb_[a-z0-9_]+` perfectly and are not metrics.
   - Recorded series carry colons (`kb:chat_error_ratio:5m`) and are defined by
     `rules/kb-recording.yml`, not by the catalog. Match on `kb_[a-z0-9_]+`, never on `kb[:_]`.
   - **Do not run the reverse direction naively.** The catalog's "Infrastructure — do not
     re-implement" table lists eight names in the same backtick formatting as the real rows —
     `kb_cpu_percent`, `kb_db_connections`, `kb_valkey_memory_bytes`, `kb_qdrant_points`,
     `kb_storage_bytes`, `kb_workers_active`, plus `kb_ingestion_queue_depth` and
     `kb_crawl_queue_depth`. Those are names that must NEVER exist. A gate demanding that every
     catalogued name be referenced would demand exactly the metrics the catalog forbids.
3. **The wrong-matcher grep.** Fail on the literal string `outcome="error"` in
   `prometheus/rules/*.yml`, `grafana/dashboards/*.json`, `apps/`, `services/` and `docs/`,
   excluding `prometheus/rules/kb-recording.yml`, `prometheus/tests/**` and this README.
   - The tests are excluded because a unit test proving the rule counts timeouts must construct
     an `outcome="error"` input sample to count.
   - This README is excluded because it is the gate's own specification.
   - `outcome=~"error|timeout"` is a **different string** and is permitted. It appears in
     `kb-recording.yml` as the definition, and once inside a runbook annotation as a diagnostic
     query an on-call engineer pastes at 03:00. The failure being prevented is a panel or an
     alert re-deriving the matcher and dropping timeouts — not the correct matcher appearing in
     prose.
4. **Alert metadata.** Every rule in `rules/kb-alerts.yml` carries `severity` ∈ {`page`, `ticket`}
   plus `summary` plus `runbook`. All three: an alert with no runbook is an on-call engineer
   reading PromQL at 03:00.
5. **Routing.** `amtool config routes test` resolves both severity values to a real receiver and
   neither to `kb-unrouted`.
6. **Taxonomy coverage.** Diff `kb-error-taxonomy`'s Page column against the alert names in
   `rules/kb-alerts.yml`. **This gate is expected to fail today**, and the `TODO(ADR)` block at the
   top of that file enumerates exactly which classes are uncovered and what decision each needs.
   That failure is the feature: a class sitting in doctrine as "alert on sustained" with no rule
   anywhere is how `provider_rate_limit` went unpaged.
7. **No dashboard variables.** `templating.list` is empty in every dashboard JSON. This is an
   enforced property, not an oversight: **no org or bot variable can exist because no series could
   fill one.** `org_id` and `bot_id` are banned as metric labels outright, and a variable over a
   label that does not exist renders an empty dropdown above panels that ignore it.
8. **`promtool test rules`** over `prometheus/tests/`, as above.

## Open cross-boundary blockers

Everything in this directory is written correctly against the topology as it should be. Six
things it depends on live in `infrastructure/docker/` (`platform-devops-engineer`) and are not
done. None of them fails loudly, which is why they are listed rather than assumed:

1. **`resource_to_telemetry_conversion` is disabled on the Collector's `prometheus` exporter**,
   so `service` and `env` never become labels. Every `sum by (service, env, ...)` in `rules/`
   then groups the whole fleet under two empty label values, and nothing errors. The fix is a
   `transform` processor projecting exactly those two resource attributes — not blanket
   conversion, which would put a git sha on every family. Details are in the comment on the
   `otel-collector` job in `prometheus/prometheus.yml`.
2. **`observability` is `internal: true` and no exporter joins it.** Every infrastructure scrape
   job reads as DOWN until the exporters join both their target's network and `observability`.
   See `exporters/README.md`.
3. **`alertmanager_deadman_url` does not exist as a secret**, so the `KbDeadMansSwitch` heartbeat
   has nowhere to go and Alertmanager logs a notify failure once a minute.
4. **Prometheus has no `qdrant_api_key`**, so Qdrant's `/metrics` returns 401. The scrape job
   carries the `authorization` block commented out with the compose change it needs.
5. **`KB_VERSION`, `KB_GIT_SHA` and `KB_CONTRACT_VERSION` are not in any env file**, so
   `kb_build_info` reports `unknown` for all three.
6. **`kb.retrieval.rerank_skips` is not on the Collector's metric allow-list.**
   `infrastructure/docker/otel/metric-allowlist.yaml` is an EXACT-NAME filter, so the instrument is
   created, the SDK exports it, the Collector discards it, and the series never reaches Prometheus
   — with no error at either end. One line is needed, in the `retrieval` block beside
   `kb.retrieval.evidence_score`:

   ```yaml
   - kb.retrieval.rerank_skips              # -> kb_retrieval_rerank_skips_total {reason}
   ```

   Until it lands, the "Reranking skipped, by reason" panel on the Retrieval dashboard reads
   "No data" while looking configured. The `scale` label added to `kb.retrieval.evidence_score`
   needs no change there: that file filters metric names, not attributes.

## Things that are deliberately not here

- **Grafana Alerting.** Rules live in Prometheus and evaluate whether or not Grafana is running,
  they are the same PromQL as the recording rules they consume, and file-provisioned Grafana rules
  are read-only in the UI anyway. A second evaluation engine with its own state buys nothing.
- **Tempo's metrics-generator / service graphs.** Its `traces_spanmetrics_*` series are not in the
  closed catalog and would fail gate 1 on their first scrape. Wanting one is a catalog PR first.
- **A Loki ruler.** Alerting on a lossy, TTL'd store that is explicitly not a business record.
- **Any per-tenant panel, variable or alert.** `org_id` is not a metric label and never will be —
  series count is the product of every label's cardinality, and a histogram multiplies that again
  by `buckets + 2`. Per-tenant numbers come from the `usage` table and the analytics aggregates in
  PostgreSQL; per-request detail comes from traces and logs. **Prometheus answers "is the system
  healthy", never "what did org X do".** Every alert whose condition names a tenant resolves the
  same way: PromQL detects the condition, the runbook annotation carries the SQL that identifies
  who.

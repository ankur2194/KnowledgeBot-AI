# KnowledgeBot metric catalog — full

**This file is the whole catalog** — every `kb_*` family, in one place, so no row can drift out of
sight of another. `kb-observability-conventions/SKILL.md` holds the naming rules and the label
allow-list. **Names below are the exposed Prometheus names** — the dashboard and alert contract.
Instruments are declared in dotted OTel form with a `unit`, never with the unit written into the name
(see SKILL Gotchas).

Every family below inherits the base labels `service`, `env`. **The allow-list in `SKILL.md` is the
union of the "Extra labels" column below, and the two are one artifact**: a label added to a row here
is added to the allow-list in the same commit, or the allow-list unit test fails. Adding `org_id`,
`bot_id`, `conversation_id`, `source_id`, `job_id`, `url`, or any query text is forbidden outright.

## Chat, retrieval, providers — docs/15 §20.2, docs/17 §23

| Prometheus name | Type | Extra labels | Answers |
|---|---|---|---|
| `kb_chat_requests_total` | Counter | `operation`, `outcome`, `error_class` | RPM, error rate (`outcome=~"error\|timeout"`), timeout rate **and** cancellation rate — four §20.2 bullets from one counter, because cancellation is an `outcome`, not an error (`kb-error-taxonomy`) |
| `kb_chat_first_token_seconds` | Histogram | `model` | §23's "first visible token below 4 s", measured end to end |
| `kb_chat_duration_seconds` | Histogram | `outcome` | Total latency, split so a fast failure cannot flatter p95 |
| `kb_retrieval_duration_seconds` | Histogram | `stage` | The 1.5 s budget per stage: `embed` `dense` `sparse` `fuse` `dedupe` `rerank` `pack` |
| `kb_retrieval_empty_total` | Counter | `reason` | Empty-retrieval rate → refusals. `reason` ∈ `no_match` `below_threshold` `filtered` |
| `kb_provider_requests_total` | Counter | `provider`, `model`, `outcome`, `error_class` | Request count, success rate, rate-limit rate, timeout rate — four §20.2 bullets, one counter |
| `kb_provider_duration_seconds` | Histogram | `provider`, `model` | Latency by model |
| `kb_provider_first_token_seconds` | Histogram | `provider`, `model` | Provider-side TTFT. **Attribution only** — never the headline number |
| `kb_provider_tokens_total` | Counter | `provider`, `model`, `token_type` | Token usage; `token_type` ∈ `input` `output` |
| `kb_chat_answers_total` | Counter | `finish_reason` | Insufficient-evidence and truncation rates; values are the SSE terminal `finish_reason` |
| `kb_chat_active_streams` | Gauge | — | Open SSE streams now: capacity headroom, and a monotonic climb is a leaked finalizer |
| `kb_retrieval_candidates` | Histogram | `stage` | Candidates entering each stage — where the funnel collapses |
| `kb_chat_fallbacks_total` | Counter | `from_model`, `to_model`, `error_class` | Fallback rate and trigger (§8.7) — a silent fallback scores the wrong model |
| `kb_retrieval_evidence_selected` | Histogram | — | Selected evidence count after thresholding |
| `kb_retrieval_evidence_score` | Histogram | — | Average evidence score is `_sum / _count`; the buckets show what the average hides |
| `kb_provider_cost_usd_total` | Counter | `provider`, `model` | Estimated cost. The `usage` table in PostgreSQL is the billing truth |

## Ingestion — docs/15 §20.2, stages in docs/08 §13.2

| Prometheus name | Type | Extra labels | Answers |
|---|---|---|---|
| `kb_queue_depth` | Gauge | `queue` | Depth of **every** queue in both runtimes — Celery's `ingest` `embed` `crawl` `evaluate` `maintenance` (`celery-workers`) and Laravel's `ai-dispatch` `notify` `maintenance` `exports` (`laravel-queues-valkey`). Queue **names**, never connection names: `valkey`/`valkey-long` are Laravel *connections* carrying two queues each, so labelling with them merges two backlogs into one series and a wedged `exports` queue hides behind a healthy `maintenance`. `maintenance` exists in both runtimes and is disambiguated by `service`. Deliberately **not** `kb_ingestion_queue_depth`: an `ingestion`-prefixed name reporting the crawl queues reads as a bug and invites someone to add a `kb_crawl_queue_depth` that duplicates it. Backlog alarm; sampled by the worker, not derived from Celery internals |
| `kb_ingestion_jobs_total` | Counter | `file_type`, `outcome`, `error_class` | Failure rate by file type; `outcome` ∈ `success` `error` `timeout` `cancelled` |
| `kb_ingestion_stage_duration_seconds` | Histogram | `stage`, `file_type` | Processing duration by file type, split by stage — which of the 17 `stage` values is slow |
| `kb_ingestion_retries_total` | Counter | `stage`, `error_class` | Retry count. Climbing against flat success = a permanent error misfiled as temporary (`kb-error-taxonomy`) |
| `kb_ingestion_pages_total` | Counter | `file_type` | Pages per minute is `rate(...[5m]) * 60`; never store a pre-divided rate |
| `kb_ingestion_ocr_pages_total` | Counter | `engine`, `disposition` | OCR usage and per-page failure. `disposition` ∈ `success` `partial` `error` — **not `outcome`**: a partially-OCR'd page is a real third result, not a failure, so the set is not the shared four and the label must not claim to be (`ocr-pipeline`) |
| `kb_embedding_chunks_total` | Counter | `model`, `kind` | Embedding throughput; `kind` ∈ `dense` `sparse` |
| `kb_embedding_batch_duration_seconds` | Histogram | `model`, `kind` | The 5 s query-embedding budget and the ingestion batch cost, same metric |
| `kb_vector_upsert_duration_seconds` | Histogram | `collection` | Vector upsert latency. `collection` is bounded — collections are per embedding model, shared across tenants (`kb-tenancy-isolation`) |
| `kb_vector_upsert_points_total` | Counter | `collection`, `outcome` | Points written vs failed; pairs with the verification count |
| `kb_version_publications_total` | Counter | `outcome` | Pointer switches vs verification failures (`kb-source-lifecycle`) |

`stage` values are exactly the safe boundaries plus their substages: `acquire` `validate` `hash` `store`
`parse` `ocr` `normalize` `clean` `enrich` `chunk` `embed` `sparse` `upsert` `verify` `activate`
`retire` `invalidate` — **17 values**, which is the number the allow-list in `SKILL.md` carries.
docs/08 §13.2 walks **18** steps and both counts are right: its step 1, *Source creation*, is a
control-plane act in Laravel that emits no ingestion stage at all, so it has no `stage` value to
label. Do not "correct" 17 to 18 to match the doc — the label would then permit a value nothing
ever emits, and the allow-list unit test is the only thing that would notice.
`file_type` is a fixed enum from the supported-format list, with everything
else folded to `other` — a tenant-supplied extension is not a label value.

## Crawling — docs/15 §20.2, docs/03 §8.14

| Prometheus name | Type | Extra labels | Answers |
|---|---|---|---|
| `kb_crawl_pages_total` | Counter | `disposition` | Per-page result, one counter. `disposition` ∈ `discovered` `changed` `unchanged` `skipped` `missing` `failed`. **Not `outcome`**: six per-page results, and `failed`/`missing` are not in the shared four, so an `outcome` label here would put every crawl failure outside `outcome=~"error\|timeout"` and the error rate would read healthy while pages fail (SKILL Gotchas). Run-level success stays on `kb_crawl_runs_total{outcome}` |
| `kb_crawl_runs_total` | Counter | `outcome` | Crawl-run success rate |
| `kb_crawl_duration_seconds` | Histogram | — | Crawl duration per run |
| `kb_crawl_fetch_duration_seconds` | Histogram | — | Per-URL fetch latency against the 20 s per-URL timeout |
| `kb_crawl_responses_total` | Counter | `status_class` | HTTP error distribution. `status_class` ∈ `2xx` `3xx` `4xx` `5xx` `network`. **Never the host, never the URL** — a crawl target set is tenant-controlled and therefore unbounded |
| `kb_crawl_robots_blocked_total` | Counter | `reason` | robots.txt / SSRF-guard rejections; `reason` ∈ `robots` `scheme` `credentials` `dns` `private_ip` `redirect` `content_type` `size`. The guard rejects for all eight; an enum missing one sends those rejections to no counter at all, so the SSRF guard looks quieter than it is (`crawl4ai-crawler`) |
| `kb_crawl_sources_overdue` | Gauge | — | Sources past their recrawl due time by over an hour, counted **by the API on scrape** — deliberately not by the scheduler. It is the only signal that catches a scheduler ticking perfectly while the work does not happen: a stranded `withoutOverlapping` lock, a wedged queue, a dispatcher that claims and throws (`laravel-scheduler`) |

## Infrastructure — do not re-implement

§20.2's infrastructure list is served by exporters that already exist. Re-exporting them under `kb_`
produces two numbers for one fact that disagree during exactly the incident you need them for.

| §20.2 item | Source | Do not write |
|---|---|---|
| CPU, memory, disk | node_exporter + cAdvisor (`node_*`, `container_*`) | `kb_cpu_percent` |
| Database connections | postgres_exporter (`pg_stat_activity_count`) | `kb_db_connections` |
| Valkey memory | redis_exporter (`redis_memory_used_bytes`) | `kb_valkey_memory_bytes` |
| Qdrant collection size | Qdrant's own `/metrics` | `kb_qdrant_points` |
| Object storage usage | SeaweedFS metrics | `kb_storage_bytes` |
| Worker concurrency | Celery exporter (`celery_worker_*`), Horizon for Laravel queues | `kb_workers_active` |

## Cross-cutting — the four `kb_` metrics every service exports

| Prometheus name | Type | Extra labels | Answers |
|---|---|---|---|
| `kb_build_info` | Gauge (always `1`) | `version`, `git_sha`, `contract_version` | Which build is emitting this series. The `_info` convention: value carries nothing, labels carry everything |
| `kb_dependency_up` | Gauge (`0`/`1`) | `dependency`, `required` | What `/health/ready` decided, and whether that dependency is required or optional |
| `kb_circuit_breaker_state` | Gauge | `dependency` | `0` closed, `1` half-open, `2` open. The breaker in `kb-error-taxonomy` is invisible without this |
| `kb_internal_requests_total` | Counter | `operation`, `outcome`, `error_class`, `status_class` | The Laravel↔FastAPI seam itself, keyed on `X-KB-Operation` — not the URL path (`kb-internal-api-contracts`) |
| `kb_internal_scheduler_tick_timestamp_seconds` | Gauge | `task` | Unix time of each scheduled task's last tick, **exported by the always-scraped API from a PostgreSQL row, not by the scheduler**. Seed the row in the migration: a scheduler that never started after a deploy must read as an ancient timestamp, not a missing series, because a series that never existed cannot be threshold-alerted (`laravel-scheduler`) |
| `kb_internal_scheduler_tasks_total` | Counter | `task`, `outcome` | Scheduled-task runs. This is the one family where `outcome` may be `skipped` — see below |

`dependency` values are fixed: `postgres` `valkey` `qdrant` `object_storage` `embedding` `reranker`
`ai_api` `core_api`. A per-org provider credential is **not** a dependency label — breaker state per
`(org_id, credential, model)` is per-tenant data and belongs in PostgreSQL, surfaced on the admin
dashboard (§19.3), not in a metric label.

**`skipped` is the fifth `outcome` value and exists only on `kb_internal_scheduler_tasks_total`.**
`withoutOverlapping()` registers a *skip filter*, so a task blocked by its own mutex emits
`ScheduledTaskSkipped` every tick — the exact fingerprint of a lock stranded by `SIGKILL`, which by
default survives **24 hours**. It is outside the error rate (a skip is the lock working) but, unlike
`cancelled`, needs **its own alert on sustained skips**: the failure mode is silence — the tick
timestamp keeps advancing and no work happens (`laravel-scheduler`).

## Histogram buckets

One bucket set per unit class, defined once and shared, so PromQL can add histograms together.

| Class | Buckets |
|---|---|
| Sub-request latency (`retrieval`, `embedding`, `vector_upsert`, internal calls) | `.005 .01 .025 .05 .1 .25 .5 1 2.5 5 10` |
| User-visible latency (`chat_duration`, `first_token`, `provider_duration`) | `.1 .25 .5 1 2 4 6 10 15 30 60` — 4 s is a bucket edge because §23 targets it |
| Job duration (`ingestion_stage`, `crawl`) | `1 5 15 30 60 120 300 600 900` |
| Counts (`candidates`, `evidence_selected`) | `1 2 5 10 20 50 100 200` |

A histogram costs `buckets + 2` series per label combination. The user-visible set is 11 buckets, so
`kb_chat_first_token_seconds` with 20 model values is 260 series — which is the whole reason `model`
is drawn from the pinned catalog and not from a tenant-typed string.

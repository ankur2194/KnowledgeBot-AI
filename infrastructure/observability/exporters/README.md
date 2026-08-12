# Infrastructure exporters — do not re-implement

This directory holds no configuration. It exists to carry one table and one network prerequisite,
both of which are otherwise learned the expensive way.

## Do not re-implement

docs/15 §20.2 asks for CPU, memory, disk, database connections, Valkey memory, Qdrant collection
size, object-storage usage and worker concurrency. Every one of those is already served by an
exporter that ships with the thing it measures. **Re-exporting them under a `kb_` name produces
two numbers for one fact, and the two disagree during exactly the incident you need them for** —
one is sampled by a process that is itself struggling, the other is not.

| §20.2 item | Where it comes from | Never write |
|---|---|---|
| CPU, memory, disk | node_exporter + cAdvisor (`node_*`, `container_*`) | `kb_cpu_percent` |
| Database connections | postgres_exporter (`pg_stat_activity_count`) | `kb_db_connections` |
| Valkey memory | redis_exporter (`redis_memory_used_bytes`) | `kb_valkey_memory_bytes` |
| Qdrant collection size | Qdrant's own `/metrics` | `kb_qdrant_points` |
| Object storage usage | SeaweedFS metrics | `kb_storage_bytes` |
| Worker concurrency | Celery exporter (`celery_worker_*`), Horizon for Laravel queues | `kb_workers_active` |

Two of those names are also unit violations that the naming rules would reject on sight
(`_percent`, and a count with no `_total`), which is a useful tell: if a proposed `kb_` metric is
hard to name legally, it is usually because something else already owns the number.

The catalog's positive rule applies here as well. **A metric that is not in
`kb-observability-conventions/references/metric-catalog.md` does not exist.** Adding one is a
catalog PR first and code second — never the other way round, because the CI catalog diff fails
on the first scrape and the fix is then a rename in a live dashboard.

## What must exist before any of this reports anything

`infrastructure/observability/prometheus/prometheus.yml` already contains correctly-written scrape
jobs for all of these. They resolve to nothing today, and will read as `DOWN` in Prometheus's
target page, because the services below do not exist yet. That is honest and intentional — the
jobs light up when the containers land.

Owned by `platform-devops-engineer`, in `infrastructure/docker/compose.yaml`:

| Job in `prometheus.yml` | Container needed | Port assumed |
|---|---|---|
| `node-exporter` | `prom/node-exporter` | 9100 |
| `cadvisor` | `gcr.io/cadvisor/cadvisor` | 8080 |
| `postgres-exporter` | `prometheuscommunity/postgres-exporter` | 9187 |
| `redis-exporter-core` | `oliver006/redis_exporter` against `valkey-core` | 9121 |
| `redis-exporter-cache` | `oliver006/redis_exporter` against `valkey-cache` | 9121 |
| `qdrant` | the existing `qdrant` service | 6333 |
| `seaweedfs` | the existing `seaweedfs` service, `-metricsPort` | 9327 |

### The network prerequisite, which is the part that surprises people

`observability` is `internal: true` and Prometheus is on **that network only**. `postgres`,
`qdrant`, `valkey-core`, `valkey-cache` and `seaweedfs` are on `data`. As the topology stands, a
correct scrape config produces a page of `DOWN` targets and a DNS error per interval.

**Each exporter must join BOTH the network of the thing it measures and `observability`.** An
exporter is a sidecar: it is the component whose job is to sit on that seam.

The tempting alternative — putting Prometheus on `data` — is refused. It would give the metrics
stack a route to PostgreSQL, Qdrant and the object store, which is precisely the boundary `data`
exists to draw, and it would do so for the convenience of a graph.

`qdrant` and `seaweedfs` expose metrics themselves and have no sidecar, so they are the two that
must join `observability` directly. For Qdrant that also means the scrape needs its api-key: the
`/metrics` endpoint sits behind it, and without a credential the target is a 401 that reads
exactly like a down service. The job in `prometheus.yml` carries a commented `authorization`
block and the compose change it needs.

## Two exporters that are not in the table

- **A Celery exporter** would answer worker concurrency, but the queue *depth* it also reports
  would collide with `kb_queue_depth`, which is sampled by the workers themselves and is in the
  catalog. If a Celery exporter is added, its queue-length series must be dropped at the scrape
  with `metric_relabel_configs` — two series for one backlog is the same disagreement problem as
  the table above, one level down.
- **Blackbox exporter** is deliberately absent. Probing our own public hostnames from inside the
  same host proves that the host can reach itself. External availability checking belongs to the
  same external monitor that watches the `KbDeadMansSwitch` heartbeat, which is outside this
  stack because layer 1 dies with Prometheus.

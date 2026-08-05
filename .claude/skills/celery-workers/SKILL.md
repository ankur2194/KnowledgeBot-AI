---
name: celery-workers
description: Celery 5.6 task and worker design for the FastAPI data plane in services/ai-service/. Use whenever adding or editing a task, queue, route, retry decorator, time limit, beat entry, or worker command line — and whenever a job runs twice, stalls mid-stage, or starves another queue. Owns visibility-timeout arithmetic, late acks, prefetch fairness, and beat; the lifecycle states a task moves through belong to kb-source-lifecycle. Pairs with kb-error-taxonomy, whose per-class retry policy overrides Celery's own.
---

# Celery Workers — Task and Worker Design

Celery **5.6.3** (2026-03-26) + Kombu **5.6.2**, prefork pool, broker `redis://valkey:6379/<db>` (see below), **no result backend**. Python per `fastapi-service`.
**Authoritative spec:** docs/08-ingestion-pipeline.md §13.1 §13.5 §13.7, docs/05-tech-stack.md §9.5 §9.8, docs/06-architecture.md §10.2, docs/14-reliability.md §19.2 §19.5, docs/18-deployment-backup-cicd.md §24.2

## Non-negotiables

- **Every task is idempotent, because the broker guarantees at-least-once and nothing else.** Late acks, visibility-timeout redelivery, `reject_on_worker_lost`, and connection-loss replay all re-execute a task that already ran. Idempotency is a property of *our* code — no broker or Celery setting supplies it. Key scheme `idem:{org_id}:{operation}:{key}` (`kb-error-taxonomy`); ingestion's key composition and the deterministic point ids that make an upsert replay-safe are `kb-source-lifecycle`.
- **`kb-error-taxonomy` decides every retry; Celery's `autoretry_for` is never used.** Two retry engines on one call multiply attempts (§19.2), and `autoretry_for` dispatches on exception *type* while the taxonomy dispatches on `error_class` — one `KbError` type spans both retryable and never-retryable classes, so type dispatch retries an unsupported file until the attempt cap.
- **No task may outlive the broker's visibility timeout.** `task_time_limit` < `visibility_timeout` with the margin below, or two workers process the same source concurrently and race the version pointer (§13.5).
- **A task starts a NEW ROOT span with a link to the submitter, never a child** (`kb-observability-conventions` rule 3). `traceparent` travels in the job payload. A child span arriving forty minutes after its parent closed is dropped by the tail sampler and the whole job is invisible.
- **A task never returns state that PostgreSQL should own.** There is no result backend; `background_jobs` rows and the Laravel callbacks in `kb-internal-api-contracts` are the only job status. A task's return value is discarded.
- **Only one `beat` process exists, and it schedules only data-plane sweeps.** Recrawl and maintenance orchestration is the Laravel scheduler's (§9.4). Two schedulers for one schedule means duplicate ticks.

## How we use it

### Valkey as the broker — the support verdict

Celery 5.6.3 and Kombu 5.6.2 contain **zero occurrences of the string "valkey"** across source, tests, changelog, and docs; `TRANSPORT_ALIASES` has no `valkey://` scheme and there is no `kombu/transport/valkey.py`. So: **Valkey is not officially supported, is not named anywhere upstream, and is not in upstream CI.** It works only as a RESP-compatible drop-in behind the **Redis transport** — `redis://`, spoken by `redis-py` (`>=4.5.2,!=4.5.5,!=5.0.2,<6.5`, from `kombu[redis]`). The transport issues only `BRPOP`, `LPUSH`/`RPUSH`, `LLEN`, `ZADD`/`ZREM`/`ZREVRANGEBYSCORE`, `SADD`/`SREM`/`SMEMBERS`, `HSET`/`HGET`/`HDEL`, `EXISTS`, `PUBLISH`/`PSUBSCRIBE` — every one of them long predates the fork point Valkey's README describes as "right before the transition to their new source available licenses", so all are present. Practical consequences, not theory: the URL is `redis://`, never `valkey://` (which raises `No such transport`); a broker bug reproduced only on Valkey has no upstream owner, so reproduce it against a Redis container before filing; and `broker_transport_options` documented as "Redis" apply verbatim. Broker deployment, logical-DB split from Laravel's queues, and `maxmemory-policy` belong to `docker-compose-stack` and `valkey-keyspaces` — but note that kombu raises `InconsistencyError` if `_kombu.binding.*` is evicted, so the broker DB must not run an eviction policy.

### The timing contract — one arithmetic, four settings

| Knob | Value | Why exactly this |
|---|---|---|
| `visibility_timeout` | **7200 s** (default is **3600 s**) | ≥ 2 × (longest hard limit + `retry_backoff_max` + soft-shutdown window) = 2 × (960 + 600 + 300) |
| `task_soft_time_limit` | 900 s (`ingest`), 600 s (`embed`), 300 s (`crawl`, `maintenance`, `evaluate`) | §19.4's ingestion budget via `kb-error-taxonomy`. **`evaluate` is per *case*, not per run** — a 500-case run fits no limit at all, so `ragas-evaluation` fans out one task per case and aggregates through a durable counter. A run-scoped eval task is the mistake this row exists to prevent |
| `task_time_limit` | soft + 60 s on every queue; longest is **960 s** | The 60 s is the task's window to checkpoint and re-raise before SIGKILL |
| retry countdown | capped at `retry_backoff_max` = 600 s | A countdown ≥ `visibility_timeout` re-executes forever (the ETA loop the Celery docs warn about) |

`visibility_timeout` must be set in **all three** places or the lowest wins: `broker_transport_options`, `result_backend_transport_options`, and `app.conf.visibility_timeout`. Where multiple apps share a broker DB, **the shortest configured value applies to all of them** — including an app that never set it and therefore contributes the 3600 s default.

### Queues, workers, and isolation

One queue per cost class, one container per queue (§24.2). Never route a 15-minute OCR job onto a queue a 5-second job shares.

| Queue | Service | Tasks | Pool / concurrency | Recycling |
|---|---|---|---|---|
| `ingest` | `ai-worker-ingestion` | acquire, parse, OCR, normalize, chunk | prefork, `-c 4` | `worker_max_tasks_per_child=50`, `worker_max_memory_per_child=4000000` |
| `embed` | `ai-worker-embedding` | embed batches, upsert, verify, publish, retire | prefork, `-c 2` | tasks-per-child **unset**, `worker_max_memory_per_child=6000000` |
| `crawl` | `ai-worker-crawl` | fetch/render one URL; egress-restricted (`kb-architecture-map`) | prefork, `-c 8` | `worker_max_tasks_per_child=100` |
| `evaluate` | `ai-worker-evaluation` | eval runs | prefork, `-c 2` | — |
| `maintenance` | co-located with `ai-worker-evaluation` | purge, verification, reapers, orphan sweeps, cache invalidation | prefork, `-c 2` | — |

`maintenance` is separate because `kb-deletion-and-verification`'s purge and its reaper must not queue behind a 400-page crawl — a source stuck in `Deleting` is a visible product failure (§33 steps 14–15). Route with `task_routes`, never `task_default_queue`; a task with no route lands on `celery` where no worker listens and hangs with no error.

```python
# services/ai-service/app/worker/config.py — the settings that are decisions, not defaults.
task_acks_late = True                   # ingestion is expensive; a crash must replay, not vanish
task_reject_on_worker_lost = True       # OOM-killed child requeues instead of silently failing
task_acks_on_failure_or_timeout = True  # DEFAULT, kept: a hard-time-limit kill must NOT replay
worker_prefetch_multiplier = 1          # default 4 — see Gotchas
worker_eta_task_limit = 64              # 5.6; bounds countdown-retry messages held in worker RAM
worker_cancel_long_running_tasks_on_connection_loss = True   # default False; True in Celery 6
worker_soft_shutdown_timeout = 300.0    # requeue in-flight work on SIGTERM instead of stranding it
task_ignore_result = True
result_backend = None                   # job state is PostgreSQL + Laravel callbacks
broker_transport_options = {"visibility_timeout": 7200, "socket_keepalive": True}
result_backend_transport_options = {"visibility_timeout": 7200}
visibility_timeout = 7200
broker_connection_retry_on_startup = True
task_default_queue = "maintenance"      # a mis-routed task lands somewhere a worker is listening
```

### One complete task

```python
# services/ai-service/app/ingestion/tasks.py
from celery.exceptions import SoftTimeLimitExceeded, Ignore
from celery.utils.time import get_exponential_backoff_interval   # Celery's own full-jitter helper
from opentelemetry import trace
from opentelemetry.trace import Link, SpanKind

MAX_DELIVERIES = 3          # redelivery is NOT counted by request.retries — we count it ourselves

@celery_app.task(bind=True, name="kb.ingest_version", queue="ingest",
                 soft_time_limit=900, time_limit=960, max_retries=None)
def ingest_version(self, *, org_id: str, version_id: str, idem_key: str, traceparent: str) -> None:
    """Idempotent, restartable, deadline-aware. Safe to run twice; safe to kill anywhere."""
    submitter = extract_span_context(traceparent)   # from the payload, not the ambient context
    # NEW ROOT + link. A child of a request that ended at 202 is unsampleable (kb-observability-conventions).
    span = tracer.start_span("kb.ingestion.job", kind=SpanKind.CONSUMER,
                             context=Context(), links=[Link(submitter)] if submitter else [])
    with trace.use_span(span, end_on_exit=True):
        span.set_attributes({"kb.org_id": org_id, "kb.operation": "ingestion.run",
                             "kb.job_id": self.request.id,
                             "messaging.redelivered": bool(
                                 (self.request.delivery_info or {}).get("redelivered"))})

        # Durable delivery counter: a requeue after an OOM does not increment request.retries,
        # so a task that deterministically OOMs would otherwise redeliver forever.
        if jobs.bump_delivery(version_id, self.request.id) > MAX_DELIVERIES:
            lifecycle.fail(version_id, error_class="internal_dependency", detail="delivery cap")
            raise Ignore()

        # Idempotency is ours, not the broker's. Claim wins the run; a loser is a completed replay.
        if not idem.claim(f"idem:{org_id}:ingestion.run:{idem_key}", ttl=86_400):
            span.set_attribute("kb.idempotent_replay", True)
            return

        # Lock for the WHOLE run, TTL above the hard limit so a killed worker self-heals.
        with valkey_lock(f"ingest:{org_id}:{version_id}", ttl=1020):
            v = lifecycle.load(version_id)          # states + legal transitions: kb-source-lifecycle
            deadline = time.monotonic() + 900 - 30  # leave 30 s to checkpoint before the soft limit
            try:
                for stage in lifecycle.stages_from_first_incomplete(v):   # resume, never restart
                    if time.monotonic() + stage.p95_seconds > deadline:
                        raise DeadlineTooTight(stage.name)   # yield rather than be SIGKILLed mid-upsert
                    with tracer.start_as_current_span(f"kb.ingestion.{stage.name}"):
                        stage.run(v)                # each stage commits its output before returning
                        INGEST_STAGE.record(stage.elapsed, {"stage": stage.name,
                                                            "file_type": v.file_type})
                publish_version(v, v.chunks)        # verify → ready → switch → retire, in that order
            except SoftTimeLimitExceeded:
                lifecycle.checkpoint(v)             # 60 s of hard-limit headroom to land this
                raise DeadlineTooTight(v.current_stage)
            except Exception as exc:
                err = classify(exc)                 # the 18 classes; kb-error-taxonomy owns the map
                span.set_attribute("error.type", err.error_class)
                INGEST_RETRIES.add(1, {"stage": v.current_stage, "error_class": err.error_class})
                if not RETRYABLE[err.error_class] or self.request.retries >= MAX_ATTEMPTS[err.error_class]:
                    lifecycle.fail(version_id, error_class=err.error_class)   # reviewable, §13.7
                    raise Ignore()                  # Ignore, not raise: a re-raise re-enters retry
                idem.release(f"idem:{org_id}:ingestion.run:{idem_key}")   # the retry must reclaim it
                raise self.retry(exc=exc, countdown=get_exponential_backoff_interval(
                    factor=max(1, int(err.retry_after or 2)), retries=self.request.retries,
                    maximum=600, full_jitter=True))     # ≤ retry_backoff_max, ≪ visibility_timeout
```

### Beat

One replica, `celery -A app beat --schedule /var/lib/celerybeat/schedule` on a persisted volume; never `celery worker -B`. Entries are **crontab** schedules only — a `timedelta` schedule re-fires immediately on every beat restart, so a deploy loop replays every sweep. Beat owns exactly: the `Deleting` reaper, the orphan-version sweep (`activated_at IS NULL`, terminal, past grace), the retired-vector deletion backstop, the Qdrant↔PostgreSQL reconciliation sweep, and the silent-job re-query from `kb-internal-api-contracts`. It does **not** own recrawl. Each entry is itself idempotent, because a scheduler restart across a tick boundary can double-fire.

## Gotchas

- **Two workers ingest the same document at once; the version pointer flips twice and the collection holds duplicate points.** The Redis/Valkey transport keeps unacked messages in a hash plus a ZSET scored by delivery time, and any connected worker — *including the one still running the task* — runs `restore_visible` every tenth poll, moving anything older than `visibility_timeout` back onto the queue. The default is **3600 s**; an OCR-heavy parse plus embedding plus upsert crosses it, and neither worker sees an error. Set 7200 s in all three places, keep every hard limit under it, and hold the per-`source_item_id` Valkey lock for the whole run.
- **A crashed worker loses an hour of parsing and the source sits in `Parsing` forever.** `task_acks_late` defaults to **False**: the message is acked *before* execution, so a SIGKILL discards it with nothing to redeliver. We set it True. The mirror trap: with late acks a *signal-killed child is still acked* unless `task_reject_on_worker_lost=True`, which is also off by default — so an OOM-killed OCR job vanishes the same way. Both on. The pair costs replays, which is exactly what the idempotency above pays for.
- **One task redelivers forever, pinning a worker slot.** A requeue is not a retry: `request.retries` stays 0, so `max_retries` never trips. A document that reliably OOMs the child loops until someone notices. Count deliveries durably in `background_jobs` and hard-fail past the cap. Keep `task_acks_on_failure_or_timeout` at its default `True` so a *hard time-limit* kill acks and fails rather than replaying a task that will time out again.
- **A hard time limit does nothing, or a task hangs past it forever.** Time limits require SIGUSR1 and the prefork pool; the gevent pool implements no soft limit at all and will not enforce the hard limit on a blocking call, and `--pool=threads`/`solo` are no better. A worker started with a non-prefork pool silently removes the ceiling the visibility-timeout arithmetic depends on — the duplicate-execution bug above, arriving by a different door. Prefork everywhere; put real socket timeouts on every I/O call and treat the limits as a backstop.
- **One worker sits at 100% while its identical sibling idles, and `celery inspect active` shows a queue with depth 0 that is not draining.** `worker_prefetch_multiplier` defaults to **4**, so a `-c 4` worker reserves 16 messages the instant it connects; with 15-minute tasks the other fifteen are hostage for hours. Set it to 1 on every queue. Do **not** reach for 5.6's `worker_disable_prefetch` here — it is documented for workers that keep *early* acknowledgment, and it is Redis-broker-only.
- **`--prefetch-multiplier=1` visibly does nothing during a retry storm and the worker's RSS climbs until the OOM killer fires.** ETA/countdown messages — which is what every one of our backoff retries is — are fetched into memory and scheduled on an internal timer, outside the per-process prefetch window. A queue full of retrying ingestion jobs pulls thousands of payloads into one worker. `worker_eta_task_limit` (new in 5.6) caps them and also caps unacked messages via kombu's `max_prefetch`.
- **A task runs twice, in parallel, after a Valkey blip that lasted two seconds.** With late acks, a task whose channel died cannot ack: the message is redelivered while the original is still executing. `worker_cancel_long_running_tasks_on_connection_loss` defaults to False and Celery emits a warning saying so; it becomes True in Celery 6. Turn it on now.
- **Embedding throughput halves after a config change nobody connects to it, or the GPU worker crashes on the second task.** `worker_max_tasks_per_child` recycles a child after N tasks — and a recycled child re-imports and reloads the embedding/OCR model, tens of seconds of dead time per respawn <!-- UNVERIFIED: reload cost not yet measured on our models -->. On `embed` leave it unset and bound memory with `worker_max_memory_per_child` (kilobytes of RSS) instead, so a child is replaced when it actually leaks. Separately: load models in a `worker_process_init` handler, never at import in the parent — a CUDA context initialised before `fork()` is unusable in the child, so a model loaded pre-fork gives every child a broken handle <!-- UNVERIFIED: CUDA/fork interaction not re-checked against the pinned torch build -->.
- **Valkey memory grows without bound and no one can say what is holding it.** A result backend was configured "for debugging". Every task then writes a tombstone that lives for `result_expires` (default 1 day) whether or not anything reads it, and `.get()` inside a task deadlocks the pool. We run with `result_backend = None` and `task_ignore_result = True`; the price is that chords, `GroupResult`, and `worker_deduplicate_successful_tasks` are unavailable — chain tasks explicitly (`verify.delay(...)` at the end of `purge`, as `kb-deletion-and-verification` does) and make idempotency ours.
- **Every scheduled sweep runs twice a night; the crawl report shows two runs and `kb_ingestion_jobs_total` doubles at exactly the tick minute.** `beat` was scaled to 2 replicas, or embedded with `-B` in a worker that has 3 replicas. Celery requires a single scheduler per schedule. Pin `replicas: 1`, and persist the schedule file — an ephemeral one plus `timedelta` entries re-fires every sweep on every restart.
- **Ingestion spans are missing from Tempo, or hang off an HTTP request that ended forty minutes earlier.** `opentelemetry-instrumentation-celery` still parents the task span to the submitter by default. Instrument with `CeleryInstrumentor().instrument(use_span_links=True)` *and* start the root explicitly from the payload's `traceparent`, as above. Full rationale: `kb-observability-conventions`.
- **The worker is `Ready` in the orchestrator while every job has stopped.** Readiness was pointed at the broker socket only. Liveness is a heartbeat file touched by a `bootsteps.StartStopStep` on the **worker** blueprint (`requires = {'celery.worker.components:Timer'}`, `worker.timer.call_repeatedly`) — it runs in the main process, so it stays true while every pool child is busy — checked against 2× the longest poll; readiness is broker reachable plus `celery inspect ping`. Zero dependency I/O in liveness (`kb-observability-conventions`).

## Official docs

- [Celery — Redis broker transport](https://docs.celeryq.dev/en/stable/getting-started/backends-and-brokers/redis.html) — `visibility_timeout`, the three-place rule, the ETA loop, soft shutdown, key eviction.
- [Celery — Tasks](https://docs.celeryq.dev/en/stable/userguide/tasks.html) — `acks_late` and idempotency, `autoretry_for`, `retry_backoff`, `retry_jitter`, `Ignore`.
- [Celery — Configuration](https://docs.celeryq.dev/en/stable/userguide/configuration.html) — every default quoted above, including 5.6's `worker_disable_prefetch` and `worker_eta_task_limit`.
- [Celery — Workers: time limits, pools, remote control](https://docs.celeryq.dev/en/stable/userguide/workers.html) and [Optimizing](https://docs.celeryq.dev/en/stable/userguide/optimizing.html) — prefetch, `-Ofair` (default since 4.0), memory per child.
- [Celery — Periodic tasks](https://docs.celeryq.dev/en/stable/userguide/periodic-tasks.html) — "ensure only a single scheduler is running for a schedule at a time".
- [Kombu — Redis transport source](https://github.com/celery/kombu/blob/main/kombu/transport/redis.py) — `visibility_timeout = 3600`, `restore_visible`, the unacked hash/ZSET; the only place Valkey compatibility can actually be checked.
- [Valkey — command compatibility](https://valkey.io/commands/) — the fork's surface, against which the transport's command list is verified.

## Definition of done

- [ ] Broker URL is `redis://`, on a logical DB used by nothing else, with eviction disabled.
- [ ] `visibility_timeout` is set in `broker_transport_options`, `result_backend_transport_options`, and `app.conf`; a test asserts `max(task_time_limit over all queues) + retry_backoff_max + worker_soft_shutdown_timeout < visibility_timeout`.
- [ ] `task_acks_late`, `task_reject_on_worker_lost`, and `worker_cancel_long_running_tasks_on_connection_loss` are all True; `worker_prefetch_multiplier` is 1.
- [ ] Every worker runs the prefork pool and every task declares both `soft_time_limit` and `time_limit`.
- [ ] No task in `services/ai-service/` uses `autoretry_for`, `retry_backoff`, or a bare `self.retry()` without an `error_class` check — a grep proves it.
- [ ] Every task claims `idem:{org_id}:{operation}:{key}` before side effects and releases it before a retry; running a completed task a second time produces zero net writes (asserted for ingestion and for purge).
- [ ] A durable delivery counter fails a job past `MAX_DELIVERIES`; a test SIGKILLs the child mid-stage and asserts the job fails rather than looping.
- [ ] Each queue has its own worker service; `task_routes` covers every registered task and a test asserts no task resolves to an unconsumed queue.
- [ ] `result_backend` is unset in every environment; no code calls `.get()`, `chord`, or `GroupResult`.
- [ ] Tasks start a new root span with a link to the submitter's `traceparent` from the payload; a test asserts the task span's trace id differs from the submitter's and the link is present.
- [ ] `beat` runs with `replicas: 1`, a persisted schedule file, and crontab-only entries; no worker uses `-B`. Recrawl is not scheduled here.
- [ ] `/health/live` touches a heartbeat file from a worker-blueprint bootstep and stays true while all pool children are busy; `/health/ready` runs `celery inspect ping`.

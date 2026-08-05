---
name: laravel-scheduler
description: Laravel 13 task scheduling for the control plane in services/core-api/ — the recrawl dispatcher, maintenance orchestration, and the cron entry behind them. Use whenever editing routes/console.php, a due-time or jitter calculation, an onOneServer or withoutOverlapping lock, or when a sweep runs twice, silently stops running, or stampedes the crawl queue at midnight. onOneServer silently does nothing without a shared cache lock. Pairs with celery-workers (which owns data-plane sweeps and must never schedule recrawl).
---

# Laravel Scheduler — Recrawl and Maintenance Orchestration

Laravel **13.24.0** (tagged 2026-08-04; 13.x released 17 Mar 2026, PHP 8.3–8.5, bug fixes to Q3 2027). One `laravel-scheduler` container (§24.2), schedule in `services/core-api/routes/console.php` — there is no `app/Console/Kernel.php` in Laravel 11+, so any `Kernel::schedule()` example you find is pre-11 and stale.
**Authoritative spec:** docs/03-functional-knowledge-sources.md §8.14 §8.16, docs/05-tech-stack.md §9.4, docs/06-architecture.md §10.2, docs/11-data-model.md §16.5, docs/18-deployment-backup-cicd.md §24.2

## Non-negotiables

- **One scheduler per schedule, and the line runs through the plane boundary.** Celery beat schedules **data-plane repair** — six entries, all on the `maintenance` queue: the `Deleting` reaper, the orphan-version sweep, the retired-vector deletion backstop, the Qdrant↔PostgreSQL reconciliation sweep, the silent-job re-query, and `sweep-abandoned-multipart-uploads` (task `kb.sweep_abandoned_multipart_uploads`, `crontab(minute=17, hour=4)`, aborting multipart uploads older than 24 h). `celery-workers` is authoritative for beat's contents and keeps that list closed; if it and this table disagree, it wins. The Laravel scheduler decides **whether a tenant's work should start** — recrawl due-times, retention timers, manual-entry expiry, quota windows — all of it policy over control-plane data. Nothing appears in both. Two schedulers on one schedule is not a race, it is a guaranteed double tick every period.
- **A scheduled task claims rows and dispatches queued jobs. It never does the work.** `schedule:run` is a single PHP process running due events **sequentially**; one 4-minute task delays every task defined after it. Work goes to Laravel queues (`laravel-queues-valkey`), which then submit to FastAPI (`kb-internal-api-contracts`). The scheduler's own budget is seconds.
- **Every task carries `->name()` and `->onOneServer()`, and `CACHE_STORE` points at shared Valkey.** Without a shared store `onOneServer` is a silent no-op (Gotchas). Writing it in from day one is what keeps scaling the container from becoming a duplicate-billing incident later.
- **The schedule runs in UTC and never in a tenant's timezone.** `config('app.timezone')` is `UTC`, `schedule_timezone` is unset, and no task calls `->timezone()`. Tenant-local times are *data*, converted to a UTC `timestamptz` by our code. Laravel's own docs: *"we recommend avoiding timezone scheduling when possible."*
- **A sweep that iterates organizations sets tenant context per row and clears it in a `finally`** (`kb-tenancy-isolation`). The scheduler is one long-lived process; the previous tenant still being set is the silent failure, not the loud one.
- **A scheduled run is a trace ROOT with no parent** (`kb-observability-conventions` rule 3) and injects `traceparent` into every job payload it dispatches, so the queued job and the eventual Celery task link back rather than starting orphan traces.

## How we use it

### What we schedule

| Entry | Cadence | Owner of the work | Why not beat |
|---|---|---|---|
| `kb:dispatch-due-crawls` | `everyFiveMinutes()` | `SubmitCrawlRun` job → `POST /internal/v1/crawl/jobs` | Recrawl frequency is per-source tenant config in PostgreSQL (§16.5); the data plane cannot see it |
| `kb:expire-manual-entries` | `hourly()` | disable manual sources past `expiry_date` (§8.15) | Source status is Laravel's |
| `kb:apply-retention-deletes` | `hourly()` | phase 1 + enqueue purge (`kb-deletion-and-verification`) | Only the "delete after retention" missing-page policy may start phase 2 |
| `kb:rollup-usage` | `hourlyAt(7)` | usage/quota aggregates | Control-plane analytics |

Everything else on the maintenance list belongs to beat. If you are about to schedule something that reads only Qdrant, SeaweedFS, or Celery job state, it is beat's.

### Recrawl scheduling that scales

A nightly `WHERE schedule = 'daily'` loop fails twice: it dumps every tenant's whole site onto the `crawl` queue at 00:00:00, and as tenants grow the loop cannot finish inside its window. Replace the global sweep with a **per-source due time**:

- `crawl_configurations.next_crawl_due_at timestamptz NOT NULL` (indexed; partial on `schedule_kind <> 'manual'`), advanced **at claim time**, inside the claiming transaction. This is what §8.16's "next scheduled crawl" displays.
- **Deterministic jitter.** For the presets (daily/weekly/monthly) we own the wall time entirely: the offset inside the window is `crc32("crawl-jitter:{$source_id}") % window_seconds`, so 50,000 daily sources land on 50,000 different minutes and a given source lands on the *same* minute every day — a random offset per tick would make the displayed next-crawl time jump on every refresh. An admin-supplied cron expression is honoured, with jitter clamped to ±30 min so a copy-pasted `0 0 * * *` across 400 tenants still spreads.
- **Bounded claim per tick.** `GLOBAL_BATCH = 200` at 12 ticks/hour is 57,600 submissions/day, matched to what `crawl` (`-c 8`, `celery-workers`) actually drains. The cap is the backpressure; the jitter is the smoothing.
- **Tenant fairness is a window function, not a queue setting.** `ROW_NUMBER() OVER (PARTITION BY organization_id ORDER BY next_crawl_due_at)` capped at `PER_ORG_BATCH`, plus a live-run cap per org. One org with 50,000 pages therefore takes at most 3 of each 200-row tick and holds at most 2 concurrent runs; it drains over hours while every other org still gets its slot on the next tick. Ordering by `next_crawl_due_at` alone is strict-oldest-first and lets one bulk import own every tick for a day.
- **A run already in flight blocks its own successor.** Claim excludes sources with a `crawl_runs` row in `queued`/`running` and pushes the due time forward. A weekly crawl that takes eight days must never stack.
- **Consecutive failures back off exponentially.** `crawl4ai-crawler`'s run-level circuit breaker fails a run outright when >20% of items look missing and applies no policy — correct, and from here it reads as an ordinary failed run. Without a ladder (`min(window, 1h × 2^(n−1))`, admin-flagged past `n = 5`) a site behind a maintenance page is recrawled, JS-rendered, and failed every night forever.

### The dispatcher

```php
// services/core-api/routes/console.php
use Illuminate\Support\Facades\Schedule;

// onOneServer — inert unless CACHE_STORE is the shared Valkey store. See Gotchas.
// name()      — required: it IS the mutex identity for closure and job entries.
// withoutOverlapping(n) — never the 1440-minute default; the arithmetic is in Gotchas.
// No ->timezone(), no ->dailyAt(): an interval frequency has no local clock to skip or repeat.
// No ->runInBackground(): it disables Laravel 13's signal-based mutex release.
Schedule::onOneServer()->group(function () {
    Schedule::command('kb:dispatch-due-crawls')
        ->name('kb:dispatch-due-crawls')->everyFiveMinutes()->withoutOverlapping(15);

    // …and the three hourly entries from the table above, same four modifiers,
    // ->withoutOverlapping(30). Stagger them with hourlyAt() so one tick runs one task.
});
```

**The command and its claim query live in [`references/dispatcher-and-claim.md`](references/dispatcher-and-claim.md)**: `DispatchDueCrawls` end to end — the claim-and-advance transaction, per-row tenant context, the freshness write, the non-zero exit — and the `claimDue` SQL with its `FOR UPDATE SKIP LOCKED` fairness window. Read it before changing a batch constant.

### Making a stalled scheduler visible

A dead scheduler emits **nothing**: no error, no failed job, no queue depth (the jobs were never enqueued), and a perfectly healthy API. `absent_over_time()` on a series the scheduler itself exported is not a fix — the `laravel-scheduler` container has no HTTP listener to scrape, and if it never started after a deploy the series never existed, so there is nothing to be absent. Three independent layers, each of which fails differently:

1. **Freshness, exported by a process that is not the scheduler.** `recordTick()` writes `scheduler_ticks(task, last_tick_at, dispatched, failed)` in PostgreSQL; the **`laravel-api`** `/metrics` endpoint — always up, always scraped — exports it as `kb_internal_scheduler_tick_timestamp_seconds`. **Seed the row in the migration** so a scheduler that never started reads as an ancient timestamp rather than a missing series: a threshold alert (`time() - kb_internal_scheduler_tick_timestamp_seconds > 900`) fires on a value, and a value that is merely stale always exists.
2. **An external dead-man's switch.** `->pingOnSuccess($url)` to a heartbeat monitor outside the stack, which alerts on *silence*. Layer 1 cannot fire when Prometheus, Alertmanager, or the whole Compose stack is what died.
3. **An overdue-work gauge that does not depend on the scheduler at all.** `kb_crawl_sources_overdue` = `count(*) where next_crawl_due_at < now() - interval '1 hour'`, computed by the API on scrape over the existing index. This is the only one of the three that catches *"the scheduler ticks on time and the work still is not happening"* — a stuck `withoutOverlapping` lock, a wedged `ai-dispatch` queue, a dispatcher that claims and then throws. A heartbeat cannot see any of those.

For failures, register **one** listener on `Illuminate\Console\Events\ScheduledTaskFailed` in a service provider, plus one on `ScheduledTaskSkipped`, rather than chaining `->onFailure()` per task — a per-task hook is a hook someone forgets on the next task. `ScheduleRunCommand` catches every `Throwable`, dispatches `ScheduledTaskFailed`, and hands it to the exception handler, which writes one stdout line nobody reads.

> All three metrics are catalogued in `kb-observability-conventions`, along with `outcome="skipped"` and the scheduled-task names in the `operation` enum. `skipped` sits **outside** the error rate — a skip means the lock is working — but carries its own sustained-skip alert, because its failure mode is silence: a lock stranded by a `SIGKILL` leaves the tick timestamp advancing for 24 hours while no work happens. The PromQL is `prometheus-grafana-loki-tempo`'s.

### Not this skill

Queue connections, workers, `SubmitCrawlRun`'s retries and backoff → `laravel-queues-valkey`. Celery beat entries and worker config → `celery-workers`. Fetching, change detection, the missing-page counter and its run-level breaker → `crawl4ai-crawler`. States and version publication → `kb-source-lifecycle`. Purge and the `Deleting` reaper → `kb-deletion-and-verification`. Migrations and index shape → `postgresql-patterns`.

## Gotchas

- **Every recrawl runs two or three times, `crawl_runs` holds duplicates, and the crawl bill multiplies.** `onOneServer` with a per-instance cache driver. `CacheSchedulingMutex` uses the store's own lock (or `add`) with a hard-coded 3600 s TTL keyed on `mutexName().$time->format('Hi')` — and both `FileStore` and `ArrayStore` **implement `LockProvider`**, so the lock is acquired successfully *on each replica's own filesystem or memory*. No exception, no warning, no log line: every replica wins its own lock and runs. Requires `database`, `memcached`, `dynamodb`, or `redis` as the **default** driver (or `Schedule::useCache('valkey')`) against one shared server. Second-order: that Valkey logical DB must run **no eviction policy** — an evicted key inside the window lets a second replica win (`valkey-keyspaces`, and the same rule `celery-workers` states for the broker DB).
- **Two copies of the dispatcher claim rows at once — or the backlog stops draining for exactly 24 hours.** `withoutOverlapping()`'s `$expiresAt` defaults to **1440 minutes**, and the mutex is normally released in `Event::finish()`. Both directions bite. TTL below the run time: the lock expires mid-run, the next tick acquires a fresh one, two dispatchers run concurrently (`FOR UPDATE SKIP LOCKED` saves correctness, not the duplicate work). TTL at the default and the container is SIGKILLed or OOM-killed mid-run: the lock survives for the rest of the day and every tick is silently skipped. Arithmetic against our longest scheduled task — `kb:dispatch-due-crawls`, p99 ≈ 40 s for 200 claims plus 200 dispatches — is `withoutOverlapping(15)`: 22× p99 so a slow tick never doubles, and a stranded lock costs 15 minutes, not a day. Recovery is `php artisan schedule:clear-cache`. Laravel 13 does release the mutex on `SIGTERM`/`SIGINT`/`SIGQUIT` via `ensureMutexIsReleasedOnSignal()` — but only when `pcntl` is loaded **and** the task is not `runInBackground`, and never for `SIGKILL`. Keep `pcntl` in the image; keep scheduled tasks in the foreground.
- **One of two near-identical entries never runs, and nothing reports it.** Mutex identity is computed differently per event type and neither is the obvious one. `CallbackEvent::mutexName()` is `sha1($this->description)` — and `Schedule::job()` sets the description to the *job class name*, so `Schedule::job(new SubmitCrawlRun($a))` and `…($b)` share one mutex and the second is skipped every tick until you give each an explicit `->name()` (`name()` is just an alias of `description()`). `Event::mutexName()` for a `command()` entry ignores the name entirely: it is `sha1($expression . normalizeCommand($command))`, so two entries with the same command string *and* the same cron expression collide no matter what you name them — differentiate by argument or by `createMutexNameUsing()`. Unnamed **closures** fail loudly (`CallbackEvent::withoutOverlapping()`/`onOneServer()` throw `LogicException`); `job()` and `command()` never do.
- **A sweep is "running" on every dashboard and has done nothing for a week.** `withoutOverlapping()` registers a `skip` filter, so a stranded lock makes the event fail `filtersPass()` and dispatch **`ScheduledTaskSkipped`** — not `ScheduledTaskFailed`, not an exception, and nothing writes a log line by default. A `ScheduledTaskSkipped` counter climbing at exactly one per tick is the fingerprint. (`onOneServer` losing to another replica dispatches no event at all — only console output — which is why the counter is per-task, not per-process.)
- **The command failed and `onFailure` never fired.** `onFailure` keys on a **non-zero exit code**, and `ScheduleRunCommand` only raises for a non-zero exit when `! $event->runInBackground`. A command that catches its own exception and returns `Command::SUCCESS` is indistinguishable from success; so is any failure inside a `runInBackground` task. Return `self::FAILURE` on partial failure, and treat the global `ScheduledTaskFailed` listener as the alert path.
- **The crawl queue spikes to 40,000 at 00:00:00 UTC and ingestion is starved for an hour.** Every source was `->daily()`, so every tenant's whole site was enqueued in one tick. Per-source due times with a deterministic offset plus the global batch cap mean the scheduler can never enqueue faster than `crawl` drains.
- **The recrawl backlog grows all week and one tenant is always at the front.** Strict `ORDER BY next_crawl_due_at` is oldest-first: an org that bulk-imports 5,000 sources occupies every tick until it is done. The `PARTITION BY organization_id` cap bounds it per tick; the in-flight count stops it re-occupying the crawl queue between ticks.
- **A task scheduled at 02:30 runs twice in October and not at all in March.** A `->timezone('Europe/Berlin')->dailyAt('02:30')` expression is evaluated against local wall time every minute: on fall-back, 02:30 CEST and 02:30 CET are two distinct instants that both render as `02:30`, so it matches twice; on spring-forward, 02:30 local never exists. Our schedule uses interval frequencies in UTC, which have no wall clock to skip or repeat. Tenant-local recrawl times are resolved to a UTC instant *once*, when `next_crawl_due_at` is computed, with an explicit DST rule — and because the row is advanced at claim time, a repeated local hour cannot produce a second dispatch.
- **A ten-minute deploy silently eats ten ticks.** Scheduled tasks do not run in maintenance mode unless marked `evenInMaintenanceMode()`. Due-time scheduling absorbs this for free (the rows are still due and drain on the next tick); a pure cron-expression entry loses that firing permanently. Do not paper over it with `evenInMaintenanceMode` on anything that writes.
- **`schedule:run` stops exiting and deploys start running old code.** Someone added one `everySecond()`/`everyTenSeconds()` entry. `schedule:run` then blocks for the remainder of the minute to service sub-minute tasks, so the process outlives the deploy. Either define no sub-minute tasks, or add `php artisan schedule:interrupt` to the deploy script.
- **The scheduler container is a single point of failure and its death is silent.** `laravel-scheduler` is one replica (§24.2); if it exits, nothing runs and nothing errors. `restart: unless-stopped` plus a container healthcheck reading the tick freshness from inside the container is the floor. Scaling to two replicas is legal **only** because every entry is `->name()->onOneServer()` against shared Valkey — which converts an availability problem into a correctness dependency on the cache. Layer 1 above is what tells you either one broke.

## Official docs

- [Laravel 13 — Task Scheduling](https://laravel.com/docs/13.x/scheduling) — `routes/console.php` and `withSchedule()`, frequency and constraint tables, `onOneServer` cache requirement and the naming rule, `withoutOverlapping` lock expiry, `runInBackground`, timezones and the DST warning, groups, pause/continue, hooks, pings, and the five scheduling events.
- [Laravel 13 — Artisan console](https://laravel.com/docs/13.x/artisan) — command signatures, exit codes, and `schedule:list` / `schedule:test`.
- [`Illuminate\Console\Scheduling\CacheSchedulingMutex`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Console/Scheduling/CacheSchedulingMutex.php) and [`CacheEventMutex`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Console/Scheduling/CacheEventMutex.php) — the only place the 3600 s single-server TTL, the `$expiresAt * 60` overlap TTL, and the `LockProvider` fallback are visible.
- [`ScheduleRunCommand`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php) and [`Event`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Console/Scheduling/Event.php) — which event is dispatched on skip vs failure, `ensureMutexIsReleasedOnSignal()`, and `mutexName()`.
- [PostgreSQL — `FOR UPDATE ... SKIP LOCKED`](https://www.postgresql.org/docs/current/sql-select.html#SQL-FOR-UPDATE-SHARE) — the claim semantics the dispatcher depends on.
- [Prometheus — alerting on absence](https://prometheus.io/docs/prometheus/latest/querying/functions/#absent_over_time) — why a seeded staleness gauge beats `absent()` for a process with no scrape target.

## Definition of done

- [ ] Every entry in `routes/console.php` has `->name()`, `->onOneServer()`, and an explicit `withoutOverlapping($minutes)` whose value exceeds the task's measured p99 by at least 10×; a test asserts no entry uses the 1440-minute default.
- [ ] `CACHE_STORE` resolves to shared Valkey in every non-local environment, on a logical DB with eviction disabled; a two-replica test asserts a task with `onOneServer` executes exactly once per window.
- [ ] No entry calls `->timezone()`, `->dailyAt()`, or any wall-clock frequency; `config('app.schedule_timezone')` is unset and `app.timezone` is `UTC`.
- [ ] No entry appears in both `routes/console.php` and Celery `beat_schedule`; a CI check diffs the two entry-name sets.
- [ ] `kb:dispatch-due-crawls` claims with `FOR UPDATE SKIP LOCKED`, advances `next_crawl_due_at` inside the claiming transaction, and dispatches after commit; a test kills the process between claim and dispatch and asserts no source is dispatched twice.
- [ ] A fairness test seeds one org with 5,000 due sources and nine orgs with one each, runs a tick, and asserts every small org was dispatched.
- [ ] A test asserts a source with a `queued`/`running` `crawl_runs` row is never claimed, and that five consecutive failed runs push `next_crawl_due_at` out on the backoff ladder and flag the source.
- [ ] Deterministic jitter: the same source's next due time is byte-identical across two computations; 10,000 daily sources spread over the window with no minute holding more than `GLOBAL_BATCH`.
- [ ] `scheduler_ticks` is seeded by the migration; `laravel-api` exports the freshness gauge; an alert rule fires when the row is stale, verified by stopping the scheduler container in an integration environment.
- [ ] Global `ScheduledTaskFailed` and `ScheduledTaskSkipped` listeners are registered and covered by tests; a command returning `FAILURE` produces an alert, and a stranded mutex increments the skip counter.
- [ ] `pcntl` is present in the scheduler image; no entry uses `runInBackground()`; no sub-minute entry exists, or the deploy script runs `schedule:interrupt`.
- [ ] `php artisan schedule:list` output is asserted in CI against the expected entry set, so a deleted or renamed entry fails the build rather than silently ceasing to run.

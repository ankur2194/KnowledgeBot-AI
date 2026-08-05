---
name: laravel-queues-valkey
description: Laravel 13 queued jobs on the Valkey-backed redis driver in services/core-api. Use whenever adding or editing a job class, queue connection, job middleware, retry or backoff rule, unique/overlap lock, failed-job handler, or worker command line — and whenever a job runs twice, a lock never releases, or a dispatch vanishes. Owns retry_after-versus-timeout arithmetic, lock TTLs, failed_jobs, and Horizon; Celery's side is celery-workers. Pairs with kb-error-taxonomy, whose per-class policy overrides Laravel's tries.
---

# Laravel Queues on Valkey — Control-Plane Jobs

Laravel **13.24.0** (13.x released 2026-03-17, PHP ≥ 8.3), `redis` queue driver over **phpredis**, Horizon **5.48**, `opentelemetry-auto-laravel` **1.8**. Broker is Valkey **9.1**, `valkey-core` db 0 (`valkey-keyspaces`).
**Authoritative spec:** docs/05-tech-stack.md §9.4 §9.8, docs/06-architecture.md §10.2 §11.2, docs/11-data-model.md §16.8, docs/14-reliability.md §19.2 §19.5, docs/18-deployment-backup-cicd.md §24.2

## Non-negotiables

- **Every job is idempotent, because the driver guarantees at-least-once and nothing else.** `retry_after` expiry, `release()`, a `queue:restart` mid-job, and a redelivered reservation all re-run work that already ran. Idempotency is our code: a durable unique key on `(organization_id, idempotency_key)` in `background_jobs`, plus the `X-KB-Idempotency-Key` FastAPI dedupes on (`kb-internal-api-contracts`). The Valkey `idem:{org_id}:{operation}:{key}` store is FastAPI's, not ours (`kb-error-taxonomy`).
- **`kb-error-taxonomy` decides every retry.** `tries`/`backoff` are the cap, never the policy: they dispatch on *nothing*, and `FailOnException`'s array form dispatches on exception *type*, while our taxonomy dispatches on `error_class`. One `KbException` type spans retryable and never-retryable classes, so type dispatch retries an unsupported file to the attempt cap. Use `FailOnException` only with a closure reading `error_class`.
- **A job's timeout must be shorter than its connection's `retry_after`, and every lock TTL longer.** Otherwise Laravel releases a still-running job and two workers process one source (see the arithmetic below). This is the exact analogue of Celery's `visibility_timeout` trap (`celery-workers`).
- **A queued job starts a NEW ROOT span with a link to the dispatcher, never a child** (`kb-observability-conventions` rule 3). Implement `TracingLinked`; the default in `opentelemetry-auto-laravel` is parent-child, and a child arriving after its parent's request closed is dropped by the tail sampler.
- **A job carries `organization_id` in its payload, sets tenant context on entry, and clears it in `finally`** (`kb-tenancy-isolation`). Worker processes are pooled; the *previous* tenant still set is the silent failure. Every lock, cache, and rate-limit key the job derives is org-prefixed. Queue *names* are workload classes, not tenants — a per-org queue is an unbounded set and `queue:work --queue=` takes a fixed list, so an org whose queue nobody listens to hangs with no error.
- **No job payload ever contains a decrypted provider credential, a config snapshot, or user content.** The payload sits in Valkey in plaintext and in `failed_jobs` indefinitely (`kb-security-baseline`). Tenant-bearing jobs implement `ShouldBeEncrypted`; credentials are resolved inside `handle()`.

## How we use it

### Valkey behind the `redis` driver — the support verdict

Laravel has **no `valkey` queue driver and no `valkey` cache/Redis client**; `queues.md`, `redis.md`, and `horizon.md` in `laravel/docs` 13.x contain **zero occurrences of the string "valkey"**. It is supported only as a RESP drop-in behind the `redis` driver — the same posture Kombu has (`celery-workers`). Two things make Laravel's position *stronger* than Celery's, and they are the reason this is a safe choice rather than a tolerated one: `laravel/docs` **sail.md ships a first-party Valkey service** and instructs you to point `REDIS_HOST` at it, and Laravel **13.5.0** added Redis Cluster support for the queue driver specifically to fix `CROSSSLOT` errors on ElastiCache Serverless (Valkey). Valkey is a tested target of first-party tooling, just not a named driver.

What the driver actually issues, read from `Illuminate/Queue/LuaScripts.php`: **`EVAL`** (every operation is a Lua script), `LPOP`, `RPUSH`, `LLEN`, `BLPOP`, `ZADD`, `ZREM`, `ZCARD`, `ZRANGE`, `ZRANGEBYSCORE`, `ZREMRANGEBYRANK`, `DEL`. Four keys per queue: `queues:{name}` (list), `:delayed` (ZSET), `:reserved` (ZSET scored `now + retry_after`), `:notify` (list, the `BLPOP` wakeup). All predate Valkey's fork point, so all are present — but note **the driver requires server-side Lua**, which is the one capability worth re-checking on any Valkey major bump.

**Client: `phpredis`, not Predis.** It is Laravel's documented recommendation (*"we encourage you to install and use the PhpRedis PHP extension via PECL… may yield better performance for applications that make heavy use of Redis"*) and the default value of `redis.client`. A worker executes an `EVAL` per poll on every queue, forever; the C extension is the right side of that trade. Predis 3.x is the fallback only if the extension cannot be built. **Leave `options.serializer` and `options.compression` unset** — the docs state plainly that *"the `serializer` and `compression` Redis options are not supported by the `redis` queue driver."*

### The timing contract — the one arithmetic

`retry_after` is set on the **connection**, not the queue, and is stamped onto the reservation at pop time — so queues needing different budgets need different connections. The rule in one line: `--timeout < retry_after < lock TTL`, with `stop_grace_period > --timeout`. Every arrow that points the wrong way produces a *silent* duplicate or a permanent wedge, never an error.

| Knob | Value | Why exactly this |
|---|---|---|
| `retry_after` (`valkey`) | **180 s** (Laravel's stub default is 90) | `--timeout` + 60 s of SIGKILL settle and clock skew |
| Horizon supervisor `timeout` (`valkey`) | 120 s | Longest control-plane job: submit-to-FastAPI + `background_jobs` write. **Under Horizon there is no `queue:work` process to pass `--timeout` to** — the supervisor owns it, and this row is the value it carries |
| `retry_after` (`valkey-long`) | **1800 s** | Same rule: 1500 + 300 |
| Horizon supervisor `timeout` (`valkey-long`) | 1500 s | Export/rollup jobs over `usage_events` and `conversations` |
| `WithoutOverlapping::expireAfter` / `UniqueFor` | **> the connection's `retry_after`** | A worker killed at the supervisor `timeout` releases nothing; the TTL is the only thing that unwedges it |
| Compose `stop_grace_period` | > the supervisor `timeout` | A worker finishes its current job on SIGTERM; a shorter grace SIGKILLs it mid-write |

### Connections, queues, and workers

One worker container per cost class (§24.2), never one `--queue=a,b,c` worker for everything — a 20-minute export starves ingestion submission behind it.

| Queue | Connection | Service | Work | Worker |
|---|---|---|---|---|
| `ai-dispatch` | `valkey` | `laravel-worker` | submit ingestion / crawl / deletion / evaluation jobs to FastAPI | `--tries=5 --max-time=3600` |
| `notify` | `valkey` | `laravel-worker` | mail, webhooks, in-app notifications | `--tries=3` |
| `maintenance` | `valkey-long` | `laravel-worker-long` | reconciliation sweep, quota rollups, `queue:prune-failed` targets | `--tries=2` |
| `exports` | `valkey-long` | `laravel-worker-long` | CSV / ZIP / PDF generation | `--tries=2` |

Scheduler-initiated work (recrawl, nightly sweeps) is dispatched *onto* these queues by the Laravel scheduler — the scheduler owns *when*, this skill owns *how the job behaves*. Cron entries, overlap prevention on the scheduler side, and the single-scheduler rule: `laravel-scheduler`. Celery `beat` deliberately does **not** schedule recrawl (`celery-workers`); do not add it there. Set `'after_commit' => true` on both connections — we dispatch from inside `DB::transaction` (audit row + job row + dispatch), and without it the worker pops the job before the transaction commits.

### Which store holds the locks — and why it must not evict

Four independent mechanisms resolve through Laravel's **cache**, not the queue: `WithoutOverlapping` (`Illuminate\Contracts\Cache\Repository` — the **default store**, with no way to point it elsewhere), `ShouldBeUnique` (default store, overridable via `uniqueVia()`), `RateLimited` (the store named by `cache.limiter`), and the `queue:restart` / `queue:pause` signals the worker polls each iteration. So **Laravel's queues and its default cache store both live on `valkey-core`** — the `noeviction` instance, queues on db 0 (`valkey-keyspaces`). `maxmemory-policy` is a *server* setting, so this is not something a logical-DB index can fix: an evicting instance evicts locks and queues alike. `celery-workers` established the no-eviction constraint for the Celery broker DB; it applies identically here with a nastier failure mode — Kombu raises `InconsistencyError` when its bindings are evicted, whereas Laravel's Lua scripts read an evicted `queues:*` key as an empty queue and an evicted lock key as "lock is free". Nothing errors, in either direction. The evictable `valkey-cache` instance is reachable from Laravel only through an explicitly named store (`Cache::store('ephemeral')`, for `cfg:`/`quota:` read-through) and never as the default. And per `valkey-keyspaces`, these locks are advisory: anything that would be *incorrect* under two holders needs a conditional write in PostgreSQL, not just the lock.

### One complete job

```php
<?php
// services/core-api/app/Jobs/SubmitIngestionJob.php
namespace App\Jobs;

use App\Models\BackgroundJob;
use App\Support\Kb\{IngestionClient, KbException, RetryPolicy, Tenancy};
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\{Timeout, Tries, UniqueFor};
use Illuminate\Queue\Middleware\{FailOnException, WithoutOverlapping};
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Throwable;

// TracingLinked = new root + link to the dispatcher (the default is a CHILD). ShouldBeEncrypted =
// the payload in Valkey and in failed_jobs is ciphertext. ShouldBeUniqueUntilProcessing collapses
// duplicate *dispatches* while queued; WithoutOverlapping below stops two workers *running* one
// version — and both locks resolve through the DEFAULT cache store, which must therefore be the
// noeviction `valkey-core` instance, because WithoutOverlapping cannot be pointed anywhere else.
#[Tries(5), Timeout(60), UniqueFor(1800)]  // 60 < retry_after 180 < UniqueFor 1800. All three matter.
final class SubmitIngestionJob implements
    ShouldQueue, ShouldBeUniqueUntilProcessing, ShouldBeEncrypted, TracingLinked
{
    use Queueable;
    public function __construct(
        public readonly string $organizationId,   // ULIDs, never Eloquent models — see Gotchas
        public readonly string $sourceVersionId,
        public readonly string $idempotencyKey,   // fingerprint per kb-internal-api-contracts
    ) { $this->onConnection('valkey')->onQueue('ai-dispatch'); }

    // Org-prefixed, always: a bare source id collides across tenants (kb-tenancy-isolation).
    public function uniqueId(): string { return "{$this->organizationId}:{$this->sourceVersionId}"; }

    public function middleware(): array
    {
        return [
            // expireAfter defaults to 0, i.e. NEVER EXPIRES — the permanent wedge in the Gotchas.
            // 240 > retry_after 180, so a SIGKILLed worker's lock self-heals instead. The stored
            // key is xxh128(job display name) . this string unless shared() — grep accordingly.
            (new WithoutOverlapping("lock:{$this->organizationId}:ingest:{$this->sourceVersionId}"))
                ->expireAfter(240)->releaseAfter(60),
            // Declarative never-retry. The array form dispatches on exception TYPE and is wrong for
            // us: one KbException spans both halves of the taxonomy. Closure, on error_class.
            new FailOnException(fn (Throwable $e) => $e instanceof KbException
                && ! RetryPolicy::retryable($e->errorClass)),
        ];
    }

    public function handle(IngestionClient $ai): void
    {
        // Auto-instrumentation injected traceparent at dispatch; TracingLinked made this a linked
        // root. Only attributes are ours — org_id is an attribute, never a metric label.
        Span::getCurrent()->setAttributes(['kb.org_id' => $this->organizationId,
            'kb.operation' => 'ingestion.submit', 'kb.job_id' => $this->job->uuid(),
            'messaging.message.attempt' => $this->attempts()]);
        Tenancy::set($this->organizationId);           // pooled process: set on entry...
        try {
            // Durable idempotency. The unique index on (organization_id, idempotency_key) is what
            // makes a redelivery a no-op; the driver promises at-least-once and nothing more.
            $record = BackgroundJob::firstOrCreate(
                ['organization_id' => $this->organizationId, 'idempotency_key' => $this->idempotencyKey],
                ['type' => 'ingestion', 'entity_id' => $this->sourceVersionId, 'status' => 'submitting'],
            );
            if ($record->external_job_id !== null) {
                Span::getCurrent()->setAttribute('kb.idempotent_replay', true);
                return;                                // FastAPI already accepted it
            }
            // Laravel never retries the HTTP call itself (kb-error-taxonomy): this job-level
            // release is the one and only retry tier for submission.
            $record->update(['external_job_id' => $ai->submit($this)->jobId, 'status' => 'submitted']);
        } catch (KbException $e) {
            if (! RetryPolicy::retryable($e->errorClass)) {
                throw $e;                              // FailOnException converts it to a failure
            }
            if ($this->attempts() >= RetryPolicy::maxAttempts($e->errorClass)) {
                $this->fail($e); return;               // straight to failed_jobs; no more attempts
            }
            // Full jitter, floored by the provider's Retry-After. Safe at any size: release() moves
            // the payload to queues:*:delayed, NOT the reserved set — so unlike a Celery countdown
            // it can never collide with retry_after.
            $this->release(max($e->retryAfter ?? 0,
                random_int(0, (int) min(30, 0.2 * 2 ** $this->attempts()))));
        } finally {
            Tenancy::forget();   // ...and clear on exit. The PREVIOUS tenant still set is the leak.
        }
    }

    // Runs after the final attempt, OUTSIDE handle()'s finally — so tenant context is unset here.
    public function failed(Throwable $e): void
    {
        Tenancy::set($this->organizationId);
        try { BackgroundJob::markFailed($this, RetryPolicy::classOf($e)); }
        finally { Tenancy::forget(); }
    }
}
```

### Horizon — yes, behind its own authorization

**We run Horizon.** `queue:monitor` reports one number (depth); Horizon reports per-queue wait time, throughput, runtime distribution and failed-job detail, and its `auto` balancer suits our bursty `ai-dispatch`/`maintenance` mix. It needs the `redis` driver (we have it) plus `ext-pcntl` and `ext-posix` in the worker image, and does not support Redis Cluster — fine on single-node Valkey. Four conditions, all load-bearing:

- **The dashboard is a cross-tenant surface**: it renders every org's job payloads, tags and exception traces side by side. The `viewHorizon` gate must resolve a **platform operator**, never a tenant admin — a tenant admin who can open `/horizon` is a `kb-tenancy-isolation` layer-1 breach, not a UX bug. The published gate defaults to local-only; keeping that default in production is a lockout, replacing it with `true` is the breach.
- **`/horizon` gets no Traefik router label and never joins the `edge` network** (same rule as `ai-api`, `kb-internal-api-contracts`), and every tenant-bearing job implements `ShouldBeEncrypted`, so what the dashboard renders is ciphertext. <!-- UNVERIFIED: that Horizon still infers tags correctly from an encrypted payload — confirm on first deploy -->
- **Supervisor `timeout` < the connection's `retry_after`.** Horizon's docs: *"when using the `auto` balancing strategy, Horizon will consider in-progress workers as 'hanging' and force-kill them after the Horizon timeout during scale down."*
- **Its own logical DB via the reserved `horizon` connection name** on `valkey-core`, with `horizon:snapshot` every five minutes and `horizon:terminate` on deploy (`laravel-scheduler`). Never run `queue:work` alongside it.

**`failed_jobs`** receives a job only after it exhausts `tries`/`retryUntil`, is `$this->fail()`ed, or trips `FailOnException`; synchronously dispatched jobs never land there. Each row holds the **full payload** plus the exception trace, with no expiry until `queue:prune-failed --hours=` runs — a tenant-data store, hence `ShouldBeEncrypted` and inclusion in the retention scope. The never-retryable classes (`validation`, `authentication`, `authorization`, `tenant_quota`, `provider_auth`, `provider_billing`, `provider_permanent_request`, `user_cancellation`) reach it on the **first** attempt or not at all; the same `source_id` arriving at max attempts means the classifier is wrong (`kb-error-taxonomy`).

## Gotchas

- **One document ingests twice, the version pointer flips twice, and neither worker logs an error.** `--timeout` exceeded `retry_after`, so the job's reservation in `queues:*:reserved` expired while it was still running and the next `pop()` from any worker migrated it back onto the list. Laravel's own warning: *"If your `--timeout` option is longer than your `retry_after` configuration value, your jobs may be processed twice."* The stub `retry_after` is **90 s** and the default `--timeout` is **60 s** — a 30 s margin that any job doing two HTTP round trips will eat. Unlike Celery, redelivery *does* increment `attempts` (the pop script rewrites the payload), so `tries` eventually stops the loop — it just stops it after the damage.
- **`--timeout` does nothing at all and a hung job pins a worker until `retry_after` redelivers it.** `Worker::supportsAsyncSignals()` returns `extension_loaded('pcntl')`; with no pcntl there is no `SIGALRM`, no timeout, and no error saying so. A slim PHP image without `ext-pcntl` silently removes the ceiling the whole arithmetic above depends on — the same shape as Celery's non-prefork-pool trap. Horizon additionally hard-requires `ext-pcntl` and `ext-posix`.
- **One source can never be ingested again, and nothing appears in `failed_jobs` — or, the mirror image, two workers run it at once despite `WithoutOverlapping`.** One knob, two failures. `$expiresAfter` defaults to **0**, which `Cache::lock($key, 0)` reads as *never expires*; the middleware releases in a `finally`, so a normal failure is fine, but a `--timeout` SIGKILL, an OOM kill, or a container stop holds the lock forever and every later dispatch for that key is silently released or dropped. `UniqueFor` has the identical default. Set *shorter* than the job's runtime instead and the lock expires mid-execution, letting the sibling in. The only correct value is bounded below by `retry_after`.
- **Jobs disappear between dispatch and execution; queue depth reads zero; no exception anywhere.** Laravel was pointed at the `allkeys-lru` instance and Valkey evicted `queues:{name}`. There is no Laravel-side equivalent of Kombu's `InconsistencyError` — a missing key is an empty queue, which is a valid state. The same eviction on a lock key produces the opposite symptom, duplicate execution, from the same root cause. `maxmemory-policy` is server-wide, so `SELECT`-ing another logical DB does not help: queues and the default cache store must both sit on `valkey-core` (`valkey-keyspaces`).
- **A job fails with `ModelNotFoundException` for a row the dispatcher just created.** `after_commit` was `false` and the dispatch happened inside `DB::transaction`; the worker popped it before the commit landed. Related and worse: `SerializesModels` re-queries the model at handle time, so a job holding an Eloquent model reads post-dispatch state and depends on whatever tenant scope the *worker* has — pass ULIDs and let the job set its own context.
- **Ingestion spans hang off an admin HTTP request that ended forty minutes earlier, or vanish from Tempo.** `opentelemetry-auto-laravel` 1.8 hooks `Illuminate\Queue\Queue::createPayloadArray` to inject `traceparent` into the payload, then `Worker::process` **extracts it and sets it as the parent by default**. Implementing `TracingLinked` (or `TracingIsolated`) is the only thing that changes that — it is the exact counterpart of Celery's `use_span_links=True`. Assert the trace ids differ.
- **A worker sits at 100% while its sibling idles, or SIGTERM is ignored for minutes during a deploy.** `block_for` was set to `0`. Laravel: *"Setting `block_for` to `0` will cause queue workers to block indefinitely until a job is available. This will also prevent signals such as SIGTERM from being handled until the next job has been processed."* Use `block_for: 5`, or `null` to poll.
- **A tenant's runaway dispatch starves every other org on the queue.** Fairness is not a queue property here — one queue is one FIFO list. `(new RateLimited('ingest-per-org'))` keyed on `org_id`, plus `WithoutOverlapping` per `(org, entity)`, is the only mechanism; per-tenant queues are not (see Non-negotiables). Note that `WithoutOverlapping` scopes its key by `xxh128(job display name)` unless you call `shared()`, so two job classes named for the same entity do *not* exclude each other, and the key in Valkey is not the string you passed.
- **Deploys lose in-flight jobs, or `queue:restart` does nothing.** The restart and pause signals are polled from the **cache** store each iteration, so an evicted or mis-pointed cache store makes them no-ops; and Compose's default 10 s `stop_grace_period` SIGKILLs a worker mid-job. Set `stop_grace_period` above `--timeout` on every worker service (`docker-compose-stack`).

## Official docs

- [Laravel — Queues](https://laravel.com/docs/13.x/queues) — `retry_after` vs `--timeout`, job middleware, unique/encrypted/debounced jobs, batching, `failed_jobs`, `queue:monitor` — and [Laravel — Redis](https://laravel.com/docs/13.x/redis) for the phpredis recommendation, the `redis.client` default, and the unsupported `serializer`/`compression` options.
- [Laravel — Horizon](https://laravel.com/docs/13.x/horizon) — the `viewHorizon` gate, supervisor `timeout` vs `retry_after`, balancing strategies, `snapshot`/`terminate`. [`Illuminate/Queue/LuaScripts.php`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Queue/LuaScripts.php) and [`RedisQueue.php`](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Queue/RedisQueue.php) — the four keys, the reservation score, and the only place Valkey compatibility can actually be checked, against [Valkey — command compatibility](https://valkey.io/commands/).
- [`opentelemetry-php/contrib-auto-laravel`](https://github.com/opentelemetry-php/contrib-auto-laravel/tree/main/src/Hooks/Illuminate/Queue) — payload injection, the CONSUMER span, and the `TracingLinked` / `TracingIsolated` / `TracingParent` contracts.

## Definition of done

- [ ] A test asserts, for every connection in `config/queue.php`, that `worker --timeout < retry_after` and that every `expireAfter`/`UniqueFor` in `app/Jobs/` exceeds its connection's `retry_after`; no `WithoutOverlapping` or `ShouldBeUnique` is constructed without an explicit TTL (a grep proves it).
- [ ] `QUEUE_CONNECTION` and the default cache store both resolve to `valkey-core` (`maxmemory-policy noeviction`, `CONFIG GET` asserted against the running container, not the config file); only an explicitly named store reaches `valkey-cache`.
- [ ] `REDIS_CLIENT=phpredis`, `ext-pcntl` and `ext-posix` present in the worker image, and `options.serializer`/`options.compression` unset.
- [ ] Every job class implements `ShouldQueue` + `TracingLinked`, takes ULIDs (no Eloquent models), and sets/clears tenant context in a `try`/`finally`; a test asserts the job span's trace id differs from the dispatcher's and the link is present.
- [ ] Every tenant-bearing job implements `ShouldBeEncrypted`; a test dispatches one and asserts the raw Valkey payload contains neither the org id nor any user-supplied string.
- [ ] No job uses `FailOnException`'s array form; every retry decision reads `error_class`. Running a completed job a second time produces zero net writes (asserted for ingestion submission and for deletion submission).
- [ ] `after_commit` is `true` on both connections; a test dispatching inside a rolled-back transaction asserts the job never runs.
- [ ] Each queue has its own worker service with `stop_grace_period > --timeout`; `--max-time` recycling is set; no worker listens to both a `valkey` and a `valkey-long` queue.
- [ ] Horizon: `viewHorizon` resolves a platform operator (a test asserts a tenant admin gets 403), no Traefik label, no `edge` membership, supervisor `timeout < retry_after`, `horizon:snapshot` scheduled.
- [ ] `queue:prune-failed` scheduled and `failed_jobs` in the retention policy; a test asserts a `validation`-class failure reaches `failed_jobs` on attempt 1, not attempt 5. Only one tier retries a given call: the job, never the HTTP client on top of it (`kb-error-taxonomy`).

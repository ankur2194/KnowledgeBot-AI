<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console commands and THE schedule
|--------------------------------------------------------------------------
|
| There is no app/Console/Kernel.php in Laravel 11+, so any Kernel::schedule() example you find is
| pre-11 and stale. withSchedule() is deliberately unused too: the schedule lives beside the
| commands, in one file, so the entry set can be asserted against — a deleted, renamed or ADDED
| entry then fails the build instead of silently ceasing to run, or silently starting to.
|
| THAT ASSERTION EXISTS, AND IT IS NOT `schedule:list`. tests/Feature/ScheduleTest.php reads the
| Schedule object after booting the console kernel and pins the entry set, the ->name(), the
| non-default overlap TTL and ->onOneServer(). It runs inside `php artisan test --parallel`, which
| ci.yml already runs, so it needs no CI job of its own. This paragraph promised a CI assertion for
| a while and there was none: measured 2026-08-11, `schedule:list` appeared in ZERO lines of
| .github/, tests/, Makefile and scripts/ (control: `artisan` in ci.yml -> 7).
|
| Do not "improve" it into a `schedule:list` diff. `schedule:list` renders next-due and overlap
| state, so it needs the mutex store — Valkey — reachable, for a fact the Schedule object already
| holds. And `schedule:test` is a substitute for neither: it reports DONE for a command that exited
| non-zero, because it never reads the child's exit code.
|
| ONE SCHEDULER PER SCHEDULE, and the line runs through the plane boundary. This file decides
| WHETHER A TENANT'S WORK SHOULD START — recrawl due times, retention timers, manual-entry expiry,
| quota windows: all policy over control-plane data. Celery beat schedules DATA-PLANE REPAIR (the
| Deleting reaper, orphan-version sweep, retired-vector backstop, Qdrant<->PostgreSQL reconciliation,
| silent-job re-query, abandoned multipart uploads). Nothing appears in both. Two schedulers on one
| schedule is not a race, it is a guaranteed double tick every period, and a CI check diffs the two
| entry-name sets.
|
| A scheduled task CLAIMS ROWS AND DISPATCHES QUEUED JOBS. It never does the work. `schedule:run` is
| a single PHP process running due events SEQUENTIALLY, so one 4-minute task delays every task
| defined after it. The scheduler's own budget is seconds.
|
| FOUR MODIFIERS ON EVERY ENTRY, ALL LOAD-BEARING:
|
|   ->name()               For a command() entry, mutexName() is sha1($expression . $command) and
|                          IGNORES the name — but Schedule::job() sets the description to the job
|                          CLASS NAME, so two dispatches of one class share a mutex and the second
|                          is skipped every tick, forever, with no error. Name everything.
|   ->onOneServer()        INERT without a shared cache store. CacheSchedulingMutex uses the store's
|                          own lock, and both FileStore and ArrayStore implement LockProvider — so
|                          every replica acquires its own lock successfully, on its own filesystem
|                          or in its own memory, and every replica runs the task. No exception, no
|                          warning, no log line. CACHE_STORE must be the shared, NON-EVICTING
|                          valkey-core store (config/cache.php).
|   ->withoutOverlapping(n)  NEVER the 1440-minute default. Both directions bite: a TTL below the
|                          run time lets two dispatchers run concurrently; the default TTL plus a
|                          SIGKILL strands the lock for the rest of the day and every tick is
|                          silently SKIPPED (ScheduledTaskSkipped, not ScheduledTaskFailed — see
|                          the listeners in AppServiceProvider). Target ~10-20x measured p99.
|   interval frequency     No ->timezone(), no ->dailyAt(). An interval frequency has no local wall
|                          clock to skip or repeat; app.timezone is UTC and schedule_timezone is
|                          unset (config/app.php).
|
| And never ->runInBackground(): it disables Laravel 13's signal-based mutex release
| (ensureMutexIsReleasedOnSignal, which needs pcntl and a foreground task) and makes onFailure stop
| firing on a non-zero exit.
|
| NO SUB-MINUTE ENTRIES. One everySecond()/everyTenSeconds() entry makes `schedule:run` block for
| the remainder of the minute, the process outlives the deploy, and deploys start running old code.
|
*/

Schedule::onOneServer()->group(function (): void {

    /*
     * LIVE ENTRIES — only entries whose command class AND whose tables exist today.
     *
     * Everything else stays COMMENTED OUT. The reason written here before was that `schedule:list`
     * resolves each entry's command, so a missing command throws and the schedule becomes
     * uninspectable. MEASURED FALSE on this tree (Laravel 13.x, 2026-08-11), and stated here
     * because a false reason invites a revert: ScheduleListCommand reads `$event->command` as a
     * STRING (ScheduleListCommand.php:189) and resolves nothing, so an entry naming a command that
     * does not exist lists cleanly and exits 0. `schedule:test` is worse — it reported DONE for a
     * command that exited 1, because it never reads the exit code.
     *
     * The cost lands at RUN time instead, once per tick, forever. ScheduleRunCommand::runEvent()
     * throws on `$event->exitCode != 0 && ! $event->runInBackground` — the second reason never to
     * use ->runInBackground() — which dispatches ScheduledTaskFailed to the AppServiceProvider
     * listener and writes one `internal_dependency` error per tick against a dependency that was
     * never coming. Meanwhile `schedule:list` keeps displaying the entry as though its work is
     * handled. Inspection is not what a premature entry breaks; honesty is.
     */

    // Horizon's per-queue wait time, throughput and runtime distribution are computed from these
    // snapshots. Without it the dashboard's metrics pages are permanently empty.
    // `horizon:terminate` is a DEPLOY step, not a scheduled one — do not add it here.
    Schedule::command('horizon:snapshot')
        ->name('horizon:snapshot')
        ->everyFiveMinutes()
        ->withoutOverlapping(5);

    /*
     * PENDING ENTRIES — uncomment each one together with whatever it is still missing: its command
     * class for the kb:* entries, its TABLE for the two framework commands at the bottom.
     * The cadence beside each is the intended schedule, not a suggestion (laravel-scheduler).
     */

    // Recrawl dispatcher. Claims due sources with FOR UPDATE SKIP LOCKED, advances
    // next_crawl_due_at INSIDE the claiming transaction, and dispatches AFTER commit. Fairness is a
    // window function — ROW_NUMBER() OVER (PARTITION BY organization_id ORDER BY next_crawl_due_at)
    // capped per org — not a queue setting; GLOBAL_BATCH 200 per tick is the backpressure and the
    // deterministic crc32 jitter is the smoothing. withoutOverlapping(15) is ~22x the measured p99.
    // Schedule::command('kb:dispatch-due-crawls')
    //     ->name('kb:dispatch-due-crawls')->everyFiveMinutes()->withoutOverlapping(15);

    // Disable manual sources past their expiry_date (§8.15). Source status is Laravel's.
    // Schedule::command('kb:expire-manual-entries')
    //     ->name('kb:expire-manual-entries')->hourlyAt(5)->withoutOverlapping(30);

    // Retention phase 1 + enqueue purge. Only the "delete after retention" missing-page policy may
    // start phase 2 (kb-deletion-and-verification).
    // Schedule::command('kb:apply-retention-deletes')
    //     ->name('kb:apply-retention-deletes')->hourlyAt(11)->withoutOverlapping(30);

    // Usage and quota aggregates.
    // Schedule::command('kb:rollup-usage')
    //     ->name('kb:rollup-usage')->hourlyAt(7)->withoutOverlapping(30);

    // failed_jobs holds full payloads and exception traces indefinitely until this runs; it is a
    // tenant-data store and belongs to the retention policy. Uncomment once the table exists.
    // Schedule::command('queue:prune-failed --hours=336')
    //     ->name('queue:prune-failed')->hourlyAt(41)->withoutOverlapping(30);

    // Token hygiene, and the same shape as queue:prune-failed above: the command class ships, the
    // TABLE does not. sanctum.expiration is null on purpose (lowering it retro-expires every issued
    // token), so every token would carry its own expires_at and this would be the only thing
    // reaping the rows — but nothing mints a token yet. No migration creates personal_access_tokens
    // and App\Models\User does not use Laravel\Sanctum\HasApiTokens. Installing the package is not
    // enough: Sanctum 4.3's provider only publishesMigrations(..., 'sanctum-migrations') and never
    // loadMigrationsFrom(), so the table appears only when someone publishes that tag.
    //
    // MEASURED before commenting this out, against the six migrations applied to PostgreSQL 18:
    // `php artisan sanctum:prune-expired --hours=24` exits 1 with SQLSTATE[42P01] Undefined table
    // relation "personal_access_tokens" does not exist. It does not prune nothing; it fails, once
    // an hour, forever. Uncomment together with the published
    // database/migrations/*_create_personal_access_tokens_table.php AND the HasApiTokens trait on
    // the User model — the PAT lifecycle is rt/v1 control-plane work, out of scope today.
    // hourlyAt(23) staggers it off the top of the hour so one tick runs one task.
    // Schedule::command('sanctum:prune-expired --hours=24')
    //     ->name('sanctum:prune-expired')->hourlyAt(23)->withoutOverlapping(30);
});

/*
| MAKING A STALLED SCHEDULER VISIBLE — three independent layers, each failing differently. None of
| them is this file's code, and all three are required (laravel-scheduler):
|   1. scheduler_ticks(task, last_tick_at, …) written by each command and exported as
|      kb_internal_scheduler_tick_timestamp_seconds by the ALWAYS-SCRAPED laravel-api /metrics
|      endpoint. SEEDED BY THE MIGRATION, so a scheduler that never started reads as an ancient
|      timestamp rather than a missing series — a threshold alert fires on a value.
|   2. ->pingOnSuccess($url) to a heartbeat monitor OUTSIDE this stack, which alerts on silence.
|      Layer 1 cannot fire when Prometheus or the whole Compose stack is what died.
|   3. kb_crawl_sources_overdue, computed by the API on scrape. The only one of the three that
|      catches "the scheduler ticks on time and the work still is not happening" — a stuck
|      withoutOverlapping lock, a wedged ai-dispatch queue, a dispatcher that claims and throws.
|
| Failures and skips are handled by ONE global ScheduledTaskFailed listener and ONE
| ScheduledTaskSkipped listener in AppServiceProvider — never a per-task ->onFailure(), which is a
| hook someone forgets on the next task.
*/

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
| non-default overlap TTL and ->onOneServer(). It runs inside `php artisan test --parallel`, so it
| needs no job of its own — which is now the only arrangement available, since this repo has no CI.
| This paragraph promised a CI assertion for a while and there never was one; that promise is now
| unkeepable rather than merely unkept, and the Pest test is the whole of the enforcement.
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
| schedule is not a race, it is a guaranteed double tick every period.
|
| RETRACTION, SAME SHAPE AS THE ONE THREE PARAGRAPHS UP. That sentence used to end "and a CI check
| diffs the two entry-name sets". IT DOES NOT AND IT NEVER DID: there is no CI, and no test in
| either service compares this file's entry names against Celery beat's. tests/Feature/ScheduleTest
| pins THIS schedule's entry set and its modifiers, which catches an entry added here and says
| nothing about one added on the other plane. The overlap is checked by review alone.
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
     * ════════════════════════════════════════════════════════════════════════════════════════════
     * THE ENTRY THIS FILE CANNOT BE WITHOUT.
     * ════════════════════════════════════════════════════════════════════════════════════════════
     *
     * `audit_logs` is PARTITION BY RANGE (created_at), monthly, and it has NO DEFAULT PARTITION —
     * deliberately, because a default partition forces every later `CREATE TABLE … PARTITION OF` to
     * scan it under ACCESS EXCLUSIVE. The migration created two months of runway. So at 00:00 on the
     * first of the month after that runway ends, EVERY audit insert fails with SQLSTATE 23514 ("no
     * partition of relation \"audit_logs\" found for row") and EVERY ON_FAILURE_ABORT audited action —
     * email verification, password reset completion, every invitation transition, every role change —
     * returns 500. The failure is total, instant, and on a clock; nothing degrades first and no
     * dependency is down to blame.
     *
     * DAILY, not monthly. The command is idempotent by construction (it reads the catalogue, then
     * `CREATE TABLE IF NOT EXISTS`) and costs one catalogue query on the 30 days out of 31 when there
     * is nothing to do. A monthly entry has one firing per month to lose — to a stranded
     * withoutOverlapping lock, a maintenance-mode deploy, or a scheduler container that died in
     * September — and losing it is the outage above. Twenty-nine cheap no-ops are the premium on that.
     * The command keeps three months of runway ahead of the current month, so any single day's run
     * repairs any single missed month.
     *
     * withoutOverlapping(10): the work is a handful of DDL statements against an empty table, p99 well
     * under a second, and a stranded lock costs ten minutes against a three-month runway.
     *
     * `->daily()` AND NOT `->dailyAt('04:07')`, even though 00:00 UTC is the busiest tick in this file.
     * The header bans ->dailyAt() (and ->timezone(), which is the one that actually skips and repeats
     * around DST), and there is nothing to gain by breaking it here: with three months of runway no
     * single run is load-bearing, so this firing landing at a month boundary — or being lost to one —
     * changes nothing. If the runway were ever cut to one month this entry would need a stagger AND a
     * reason, in that order.
     */
    Schedule::command('kb:create-audit-partitions')
        ->name('kb:create-audit-partitions')
        ->daily()
        ->withoutOverlapping(10);

    /*
     * AND NOT ITS SIBLING. `kb:prune-audit-partitions` EXISTS AND IS DELIBERATELY NOT SCHEDULED: it
     * DETACHes and DROPs whole months of the compliance record, and no retention decision stands
     * behind it yet. An unattended DROP of audit data is not a maintenance task, it is a deletion
     * policy, and it needs a stated retention window (and the operator-facing dry run the command
     * already has) before a cron entry. Adding it here would fail tests/Feature/ScheduleTest.php,
     * which is the intended speed bump.
     */

    // The framework's own reset-token sweep, and it is scheduled — unlike `sanctum:prune-expired`
    // below — because it has BOTH halves: the command class ships with the framework AND
    // `password_reset_tokens` now exists (2026_08_13_000700). It deletes rows past
    // `config('auth.passwords.users.expire')` minutes old, using the BROKER's definition of expired,
    // which is why kb:prune-auth-tokens deliberately does not touch that table: two definitions of
    // "expired" on one table, both scheduled, is how they drift.
    //
    // hourlyAt(17) rather than hourly(): `schedule:run` executes due events SEQUENTIALLY in one
    // process, so staggering keeps one tick to one task.
    Schedule::command('auth:clear-resets')
        ->name('auth:clear-resets')
        ->hourlyAt(17)
        ->withoutOverlapping(10);

    // The other two auth capabilities, which have no framework command: expired-and-never-used rows in
    // `organization_invitations` and `email_verification_tokens`. The invitation half is not mere
    // hygiene — `organization_invitations_one_pending_per_email` is partial on
    // `accepted_at IS NULL AND revoked_at IS NULL` and expiry is NOT one of its predicates, so an
    // expired invitation holds that (organization, email) slot forever and re-inviting the same person
    // fails with a 23505. See the command's docblock.
    //
    // hourlyAt(29) staggers it off both the hour and auth:clear-resets. withoutOverlapping(10) is
    // ~far above the p99 of two indexed DELETEs and costs ten minutes if a SIGKILL strands the lock.
    Schedule::command('kb:prune-auth-tokens')
        ->name('kb:prune-auth-tokens')
        ->hourlyAt(29)
        ->withoutOverlapping(10);

    /*
     * THE OBJECT-STORAGE ORPHAN SWEEP (security finding S3), and the OTHER end of a boundary this
     * file's header draws. `sweep-abandoned-multipart-uploads` is Celery beat's — it aborts
     * multipart uploads whose parts `ListObjectsV2` cannot even see — and it is DATA-PLANE REPAIR.
     * This one is not repair: its input is `pending_source_objects`, a CONTROL-PLANE table this
     * application writes before every object and deletes after every commit, and no process in the
     * data plane can read it. Two sweeps, two planes, disjoint inputs, nothing running twice.
     *
     * HOURLY AT :43, staggered off the other three so one tick stays one task. Hourly rather than
     * daily because the rows it collects are the residue of failed create requests and an orphan
     * costs storage for as long as it survives; the grace window
     * (`kb.upload_orphan_grace_minutes`, six hours) is what decides how OLD a row must be, so the
     * cadence only decides how promptly an eligible row is noticed, and 24 hours of extra latency
     * buys nothing.
     *
     * withoutOverlapping(30) against a tick bounded by `kb.upload_orphan_sweep_limit` (500 rows,
     * two indexed queries and one object DELETE each). A stranded lock costs half an hour against a
     * six-hour window, so nothing becomes ineligible while it is held.
     *
     * IT CAN FAIL, AND THAT IS DESIGNED. The command returns FAILURE when an object delete raced a
     * commit — bytes of a live source destroyed, unrecoverable — so this entry is one of the few
     * here whose ScheduledTaskFailed is a real incident rather than a dependency blip. See the
     * command's docblock for the check-then-act window it detects.
     */
    Schedule::command('kb:sweep-orphan-objects')
        ->name('kb:sweep-orphan-objects')
        ->hourlyAt(43)
        ->withoutOverlapping(30);

    /*
     * RETENTION FOR failed_jobs, live since 2026_08_24_002700 created the table (finding R8).
     *
     * That table holds the full serialized job plus an exception trace with arguments. Payloads are
     * ciphertext for every job implementing ShouldBeEncrypted; traces are not, so the row is
     * tenant-adjacent data with a retention obligation rather than an operational log. 336 hours is
     * two weeks: long enough that a failure over a weekend is still retryable on the Monday after
     * next, short enough that the table is not an unbounded store of decrypted context.
     *
     * IT PRUNES BY `failed_at`, WHICH IS INDEXED, and it is the only consumer of that index. A
     * failure here is a dependency blip, not an incident — unlike kb:sweep-orphan-objects above,
     * whose FAILURE means bytes were destroyed.
     */
    Schedule::command('queue:prune-failed --hours=336')
        ->name('queue:prune-failed')
        ->hourlyAt(41)
        ->withoutOverlapping(30);

    /*
     * PENDING ENTRIES — uncomment each one together with its command class. The cadence beside each
     * is the intended schedule, not a suggestion (laravel-scheduler).
     *
     * THIS BLOCK NO LONGER CONTAINS AN ENTRY WAITING ON A TABLE, and that is worth one sentence:
     * `queue:prune-failed` sat here for the entire life of the repository behind the words
     * "uncomment once the table exists", which is a to-do whose blocking half nobody owned. The
     * table's absence was found by a real upload failing, not by this comment. A pending entry that
     * names a missing artifact should be read as a defect report, not as a plan.
     *
     * `sanctum:prune-expired` at the bottom is NOT one of these: it is a PERMANENT omission under
     * decision D11, not a pending entry. Read its comment before adding it back.
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

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // PERMANENTLY OMITTED UNDER DECISION D11 — NOT PENDING, NOT WAITING FOR A TABLE.
    // ════════════════════════════════════════════════════════════════════════════════════════════
    //
    // The reason this comment used to give was "the command class ships, the TABLE does not", filed
    // beside queue:prune-failed as though both were waiting for a migration. That framing is now
    // WRONG, and the correction matters because it changes what a future reader should do about it.
    //
    // Decision D11: the admin surface has EXACTLY ONE authentication mechanism, the Sanctum SPA cookie
    // session. NO TOKEN IS EVER MINTED here — App\Models\User deliberately does not use
    // Laravel\Sanctum\HasApiTokens (its docblock states that as a decision, with the measurement that
    // cookie authentication works without the trait because Guard::__invoke() returns the session user
    // unchanged when supportsTokens() is false). A pruner for a table nothing ever writes is not a
    // pending entry; it is a no-op with a cron slot, and scheduling it would suggest a second
    // credential exists on this surface. `sanctum.expiration` staying null is part of the same
    // decision, not an argument for this entry.
    //
    // MEASURED, and left here because it is the shape of what a premature entry costs: against the
    // migrations applied to PostgreSQL 18, `php artisan sanctum:prune-expired --hours=24` exits 1 with
    // SQLSTATE[42P01] Undefined table relation "personal_access_tokens" does not exist. It does not
    // prune nothing; it FAILS, once an hour, forever, dispatching ScheduledTaskFailed to the
    // AppServiceProvider listener while `schedule:list` displays the entry as though it were handled.
    //
    // WHAT WOULD MAKE IT LEGITIMATE is not a migration. It is a future mobile personal-access-token
    // surface — its own surface, its own decision — landing FOUR things in one change: the trait, the
    // published personal_access_tokens migration, Sanctum::authenticateAccessTokensUsing() so a revoked
    // membership stops an already-minted token on its next request, and this entry. Uncommenting it
    // alone, at any point before that, is wrong for a reason that has nothing to do with the table.
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

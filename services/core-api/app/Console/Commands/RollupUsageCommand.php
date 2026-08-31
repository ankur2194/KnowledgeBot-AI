<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuotaMetric;
use App\Services\Usage\UsageRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `kb:rollup-usage` — DERIVE the ledger rows nothing recorded, and RECONCILE the Valkey counters.
 *
 * ═══ IT IS NOT A WAREHOUSE BUILD, AND THE NAME IS SLIGHTLY MISLEADING ═══════════════════════
 *
 * "Rollup" suggests it aggregates raw rows into summary rows. It does not, and there is no summary
 * table for it to write: §8.23's tiles are aggregates over `conversations`, `messages`,
 * `provider_calls`, `retrieval_traces`, `feedback` and `knowledge_sources`, which are the source of
 * truth for each of those facts, and a mirrored copy would be a second number for one fact with no
 * statement of which is right. The name is `routes/console.php`'s and it is kept because the entry
 * was reserved there before this command existed; what it actually does is the two things below.
 *
 * ═══ ONE — DERIVE MISSING LEDGER ROWS ══════════════════════════════════════════════════════
 *
 * `usage_events` is written on the request path, and every request path can die between the state
 * change and the meter reading. Two known holes, both by design rather than accident:
 *
 *   PROVIDER CALLS.  A stream that is finalized on the abort path, a worker OOM-killed after the
 *                    `provider_calls` row commits, a `usage_events` insert that fails on a missing
 *                    partition. The call row is the truth and it holds everything needed.
 *   STORAGE.         `SourceService::recordStorageUsage()` runs AFTER the transaction commits — it
 *                    must, because the Valkey bump is not transactional and a rollback would leave
 *                    the counter HIGH, the one direction it may never be — and it swallows its own
 *                    failures, because failing an upload over a meter reading would turn a healthy
 *                    201 into a 500 for a source that WAS created.
 *
 * BOTH ARE SAFE TO RE-DERIVE FOREVER, and that is the property the whole command rests on: the
 * dedupe key is the SOURCE ROW'S OWN ULID and `occurred_at` is the SOURCE ROW'S OWN TIMESTAMP, so a
 * second derivation computes the same `(organization, type, dedupe_key, occurred_at)` and collides
 * on `usage_events_dedupe` instead of adding. An implementation that generated a fresh key, or used
 * `now()`, would double-count every hour, forever, in a direction that reads as ordinary growth.
 *
 * ═══ TWO — RECONCILE THE COUNTERS ══════════════════════════════════════════════════════════
 *
 * `quota:{org}:{period}:{metric}` is a read-through cache of a PostgreSQL aggregate and it can only
 * ever be LOW. This command re-reads the primary for every metered organization, which refreshes
 * each key with the exact value — so a counter that drifted low (a lost increment, an eviction) is
 * corrected within the hour rather than at the next natural miss.
 *
 * ═══ IT ITERATES ORGANIZATIONS AND SETS TENANT CONTEXT PER ROW ═════════════════════════════
 *
 * `laravel-scheduler`: *"A sweep that iterates organizations sets tenant context per row and clears
 * it in a `finally`. The scheduler is one long-lived process; the previous tenant still being set is
 * the silent failure, not the loud one."* `TenantContext::runFor()` is that `finally`, which is why
 * it is a scoped runner rather than a setter.
 *
 * NOTE WHAT THIS BUYS BEYOND HYGIENE: it means every query this command issues is org-scoped in both
 * layers — the explicit predicate AND the global scope — so there is no `// tenancy-exempt:` marker
 * anywhere in this path and no cross-tenant read to review. The cost is O(organizations) statements
 * per tick, which for a control-plane sweep is the right trade and is what the skill prescribes.
 *
 * ═══ IT CLAIMS AND DISPATCHES NOTHING, WHICH IS UNUSUAL FOR AN ENTRY IN THIS FILE ══════════
 *
 * `laravel-scheduler`'s rule is that a scheduled task claims rows and dispatches queued jobs, never
 * does the work, because `schedule:run` executes due events SEQUENTIALLY in one process and one slow
 * task delays every task after it. This one does the work inline, and the justification is the
 * BOUND: `--lookback-hours` (default 3) times a per-organization row cap, over indexed reads, with
 * no network call anywhere. If that stops being true — if the derivation ever needs to talk to the
 * data plane, or the lookback grows — this becomes a dispatcher and the work moves to the
 * `maintenance` queue, which `config/queue.php` already names for "reconciliation sweeps, quota
 * rollups".
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it. The artisan
 * signature — the part that is a contract — is `kb:rollup-usage`.
 */
final class RollupUsageCommand extends Command
{
    /**
     * How far back to look for un-metered work, in hours.
     *
     * THREE, AND IT IS A MULTIPLE OF THE SCHEDULE RATHER THAN A GUESS. The entry runs hourly at :07,
     * so a one-hour lookback would lose everything that happened during any tick that was skipped —
     * a stranded `withoutOverlapping` lock, a maintenance-mode deploy, a scheduler container that
     * died — and a skip is precisely the condition under which rows go unmetered. Three hours covers
     * two consecutive misses.
     *
     * IT COSTS NOTHING TO OVERLAP, which is what makes a generous window the right default: every
     * derivation is idempotent against `usage_events_dedupe`, so re-examining an hour that was
     * already metered writes zero rows and reads one index range.
     */
    private const DEFAULT_LOOKBACK_HOURS = 3;

    /** Beyond this, somebody wants a backfill rather than a sweep, and should say so per organization. */
    private const MAX_LOOKBACK_HOURS = 720;

    protected $signature = 'kb:rollup-usage
                            {--hours= : How far back to look for unmetered work (default 3)}
                            {--dry-run : Report what would be derived and reconciled, and write nothing}';

    protected $description = 'Derive missing usage_events rows and reconcile the quota counters against PostgreSQL.';

    public function handle(UsageRollupService $rollup): int
    {
        $hours = $this->lookbackHours();

        if ($hours === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $since = CarbonImmutable::now('UTC')->subHours($hours);

        $this->line(
            "Looking back {$hours} hour(s), to ".$since->toIso8601String()
            .($dryRun ? ' (dry run — nothing will be written).' : '.'),
        );

        $rows = [];
        $failed = 0;

        foreach ($rollup->organizationIds() as $organizationId) {
            try {
                $result = $rollup->reconcile($organizationId, $since, $dryRun);
            } catch (Throwable $failure) {
                // ONE ORGANIZATION'S FAILURE DOES NOT STOP THE SWEEP, unlike the partition commands,
                // and the difference is what a partial run leaves behind. There, a failure can leave
                // a detach pending and the next statement would be refused anyway, so continuing
                // turns one clear failure into N confusing ones. Here every organization is
                // independent and every derivation is idempotent — so skipping one and carrying on
                // means the other tenants are metered this hour and the failed one is retried next
                // hour, which is strictly better than stopping at the first bad row.
                $this->error("Organization {$organizationId}: ".$failure->getMessage());
                $failed++;

                continue;
            }

            if ($result->isEmpty()) {
                continue;
            }

            $rows[] = [
                $organizationId,
                (string) $result->derivedTokenEvents,
                (string) $result->derivedStorageEvents,
                implode(', ', array_map(
                    static fn (QuotaMetric $m): string => $m->value,
                    $result->reconciledMetrics,
                )),
            ];
        }

        if ($rows !== []) {
            $this->table(['organization', 'token rows', 'storage rows', 'counters refreshed'], $rows);
        } else {
            $this->info('Nothing to derive; every metered organization was already up to date.');
        }

        if ($dryRun) {
            $this->comment('--dry-run: nothing was written and no counter was refreshed.');
        }

        // A NON-ZERO EXIT ON ANY ORGANIZATION'S FAILURE, so the global ScheduledTaskFailed listener
        // in AppServiceProvider sees it. `laravel-scheduler` records the trap this avoids: a command
        // that catches its own exception and returns SUCCESS is indistinguishable from success, and
        // `onFailure` keys on the exit code.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function lookbackHours(): ?int
    {
        $option = $this->option('hours');

        if ($option === null || $option === '') {
            return self::DEFAULT_LOOKBACK_HOURS;
        }

        // NORMALISE, THEN CHECK — see CreatePartitionsCommand::monthsAhead() for why `is_int()` is
        // not the way to do it: `InputInterface::getOption()` is declared `string|array|bool|null`,
        // so PHPStan narrows `is_int($option)` to `never` while Symfony genuinely stores an int for
        // a programmatic `Artisan::call(...)`. Rejecting the two types that are actually wrong and
        // then casting keeps both the analyser and the runtime honest.
        if (is_array($option) || is_bool($option)) {
            $this->error('--hours must be a positive integer.');

            return null;
        }

        $candidate = (string) $option;

        if (preg_match('/^\d+$/', $candidate) !== 1) {
            $this->error('--hours must be a positive integer.');

            return null;
        }

        $hours = (int) $candidate;

        if ($hours < 1) {
            $this->error('--hours must be at least 1. A zero-hour window examines nothing.');

            return null;
        }

        if ($hours > self::MAX_LOOKBACK_HOURS) {
            $this->error(
                '--hours may not exceed '.self::MAX_LOOKBACK_HOURS.' (30 days). A longer window is a '
                .'backfill rather than a sweep: it scans every provider call and every source item '
                .'for every organization in one scheduled tick, and `schedule:run` executes due '
                .'events sequentially — so it would delay every entry defined after it.',
            );

            return null;
        }

        return $hours;
    }
}

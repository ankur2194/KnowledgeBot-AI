<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keep `audit_logs` writable by staying ahead of the calendar.
 *
 * WHAT HAPPENS WITHOUT THIS. `audit_logs` is `PARTITION BY RANGE (created_at)` with no DEFAULT
 * partition (the migration explains that choice). An INSERT whose `created_at` falls outside every
 * partition fails with SQLSTATE 23514, *"no partition of relation \"audit_logs\" found for row"* — so
 * at 00:00 on the first of a month with no runway, every login stops being audited and every
 * transactional audited action (an invitation, a role change) starts returning 500. The failure is
 * total, instant, and on a clock.
 *
 * IDEMPOTENT, AND THAT IS A REQUIREMENT RATHER THAN A COURTESY. It runs on a schedule, twice on a
 * bad day, and possibly on two hosts in the same second: it checks the catalogue and then uses
 * `CREATE TABLE IF NOT EXISTS`, so a second run reports "exists" and exits 0. A command that failed
 * on re-run would be a command whose failure alert everyone learns to ignore.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it for
 * everything in App\Console\Commands. The artisan signature — the part that is a contract — is
 * `kb:create-audit-partitions`.
 *
 * NOT SCHEDULED BY THIS CHANGE. routes/console.php and tests/Feature/ScheduleTest.php are owned by
 * another agent in a later batch, and ScheduleTest pins the whole entry set, so the schedule entry
 * and the test update have to land in one edit. See the report: this command needs a monthly (or,
 * cheaply, daily) entry with `->onOneServer()` and a non-default `->withoutOverlapping()`.
 */
final class CreateAuditPartitionsCommand extends Command
{
    /**
     * Three months of runway by default.
     *
     * One would be enough for a correct scheduler and is not enough for a real one: the whole point
     * of the runway is to survive the scheduler being broken, and a stranded `withoutOverlapping`
     * lock or a wedged worker is exactly the condition under which nobody notices for weeks
     * (laravel-scheduler). Three months of empty partitions cost three catalogue entries.
     */
    private const DEFAULT_MONTHS_AHEAD = 3;

    /** Beyond this, someone has confused a runway with a retention policy. */
    private const MAX_MONTHS_AHEAD = 60;

    protected $signature = 'kb:create-audit-partitions
                            {--months= : Months of runway to guarantee beyond the current one (default 3)}
                            {--dry-run : Report what would be created and change nothing}';

    protected $description = 'Create the monthly audit_logs partitions that keep the table writable.';

    public function handle(EloquentAuditLogPartitionRepository $partitions): int
    {
        $monthsAhead = $this->monthsAhead();

        if ($monthsAhead === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // UTC, not the app timezone. The partition bounds in the migration are written with an
        // explicit offset and `app.timezone` is UTC — but reading the clock in UTC here means a
        // future timezone change cannot silently shift which month this command considers current
        // and leave a one-hour hole at a month boundary.
        $current = CarbonImmutable::now('UTC')->startOfMonth();
        $existing = $partitions->partitionNames();

        $rows = [];
        $created = 0;

        for ($offset = 0; $offset <= $monthsAhead; $offset++) {
            $month = $current->addMonths($offset);
            $name = EloquentAuditLogPartitionRepository::nameFor($month);

            if (in_array($name, $existing, true)) {
                $rows[] = [$name, $month->format('Y-m'), 'exists'];

                continue;
            }

            if ($dryRun) {
                $rows[] = [$name, $month->format('Y-m'), 'would create'];

                continue;
            }

            try {
                $partitions->ensureMonth($month);
            } catch (Throwable $failure) {
                // Report and STOP. Continuing would create later months while the earliest missing
                // one — the one the next INSERT needs — is still absent, and the command would exit
                // non-zero having made the gap harder to see.
                $this->error("Failed to create {$name}: ".$failure->getMessage());
                $this->table(['partition', 'month', 'status'], $rows);

                return self::FAILURE;
            }

            $rows[] = [$name, $month->format('Y-m'), 'created'];
            $created++;
        }

        $this->table(['partition', 'month', 'status'], $rows);

        if ($dryRun) {
            $this->comment('--dry-run: nothing was created.');

            return self::SUCCESS;
        }

        $this->info($created === 0
            ? 'audit_logs already has the full runway; nothing to do.'
            : "Created {$created} audit_logs partition(s).");

        return self::SUCCESS;
    }

    private function monthsAhead(): ?int
    {
        $option = $this->option('months');

        if ($option === null || $option === '') {
            return self::DEFAULT_MONTHS_AHEAD;
        }

        // TWO CALLERS, AND THEY DISAGREE ABOUT THE TYPE — so normalise before checking, rather than
        // testing for one spelling and refusing the other.
        //
        // The terminal always hands an option through as a string: `--months=4` arrives as `'4'`.
        // `Artisan::call('kb:create-audit-partitions', ['--months' => 4])` — the natural programmatic
        // form, and what a test or another command writes — puts a real **int** into the input at
        // runtime. The guard used to open `! is_string($option) || …`, which refused that int, printed
        // "--months must be a non-negative integer" *about an integer*, and returned exit 1. Found by
        // 5A, whose two specs were written the programmatic way and were right to be.
        //
        // WHY NOT `is_int($option)`: `InputInterface::getOption()` is DECLARED
        // `string|array|bool|null`, so PHPStan narrows `is_int($option)` to `never` and errors on the
        // comparison — the declared type does not admit the value Symfony actually stores. Rejecting
        // the two types that are genuinely wrong and then casting keeps both the analyser and the
        // runtime honest, instead of asserting a type the signature says cannot occur.
        if (is_array($option) || is_bool($option)) {
            $this->error('--months must be a non-negative integer.');

            return null;
        }

        // `'4'` from the terminal and `4` from Artisan::call both become `'4'` here. A negative int
        // becomes `'-1'`, which the pattern refuses — so the sign check is the pattern's job, not a
        // separate branch that could disagree with it.
        $candidate = (string) $option;

        if (preg_match('/^\d+$/', $candidate) !== 1) {
            $this->error('--months must be a non-negative integer.');

            return null;
        }

        $months = (int) $candidate;

        if ($months > self::MAX_MONTHS_AHEAD) {
            $this->error('--months may not exceed '.self::MAX_MONTHS_AHEAD.'.');

            return null;
        }

        return $months;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\MonthlyPartitionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keep a monthly range-partitioned table writable by staying ahead of the calendar.
 *
 * WHAT HAPPENS WITHOUT THIS. Both partitioned tables in this schema — `audit_logs` (ADR-041) and
 * `usage_events` (2026_08_27_003500) — have NO DEFAULT partition, deliberately: with one present,
 * every later `CREATE TABLE … PARTITION OF` must scan it under ACCESS EXCLUSIVE while the parent is
 * being written. So an INSERT whose partition-key column falls outside every partition fails with
 * SQLSTATE 23514, *"no partition of relation … found for row"* — at 00:00 on the first of a month
 * past the runway, on a clock, with nothing degrading first and no dependency to blame.
 *
 * The consequence differs per table and both are bad in different directions: on `audit_logs` every
 * ON_FAILURE_ABORT audited action starts returning 500, loudly; on `usage_events` metering simply
 * STOPS, quietly, and every quota reads low while the platform keeps serving.
 *
 * IDEMPOTENT, AND THAT IS A REQUIREMENT RATHER THAN A COURTESY. It runs on a schedule, twice on a
 * bad day, and possibly on two hosts in the same second: it checks the catalogue and then uses
 * `CREATE TABLE IF NOT EXISTS`, so a second run reports "exists" and exits 0. A command that failed
 * on re-run would be a command whose failure alert everyone learns to ignore.
 *
 * ── AN ABSTRACT BASE RATHER THAN TWO COPIES ─────────────────────────────────────────────────
 *
 * The two concrete commands differ in exactly two strings — the artisan signature and the relation
 * name — and everything below is the runway arithmetic and the `--months` parsing, of which the
 * parsing is the part that must not be duplicated: it has already been wrong once, in a way that
 * printed "--months must be a non-negative integer" about an integer (see `monthsAhead()`).
 *
 * Subclasses carry the `Command` suffix because arch()->preset()->laravel() asserts it for
 * everything in App\Console\Commands. This base does not, and does not need to: it is abstract, so
 * it is never resolved as a command.
 *
 * ── IT DECLARES `handle()` AND ITS SUBCLASSES DO NOT INJECT THEIR REPOSITORY INTO ONE, WHICH IS
 *    THE ARCH PRESET SPEAKING ────────────────────────────────────────────────────────────────
 *
 * `arch()->preset()->laravel()` pins two rules that together leave exactly one shape available, and
 * both were measured rather than reasoned about:
 *
 *   1. EVERY class in `App\Console\Commands` must declare a `handle` method. Without one the preset
 *      fails with "Expecting 'app/Console/Commands/CreatePartitionsCommand.php' to have method 'handle'" — which is
 *      the rule doing its job, because a class in that namespace with no `handle` is normally a
 *      command that will never run.
 *   2. NOTHING OUTSIDE that namespace may extend `Illuminate\Console\Command`. Moving the base to
 *      `App\Support\Console` to dodge rule 1 fails with "Expecting … not to extend
 *      'Illuminate\Console\Command'".
 *
 * So the base stays here and declares `handle()`. The consequence is that subclasses CANNOT use
 * method injection for their repository — `handle(EloquentAuditLogPartitionRepository $r)` would be
 * an override that adds a required parameter, which PHP rejects outright — so they name their
 * repository through `partitions()` instead. That is service location in three lines, and it is the
 * price of the two rules above rather than a preference.
 */
abstract class CreatePartitionsCommand extends Command
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

    /**
     * The catalogue for THIS command's partitioned table. See the class docblock for why it is a
     * method rather than a method-injected argument on `handle()`.
     */
    abstract protected function partitions(): MonthlyPartitionRepository;

    public function handle(): int
    {
        $partitions = $this->partitions();

        return $this->createRunway($partitions, $partitions::PARENT);
    }

    private function createRunway(MonthlyPartitionRepository $partitions, string $parent): int
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
            $name = $partitions::nameFor($month);

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
            ? "{$parent} already has the full runway; nothing to do."
            : "Created {$created} {$parent} partition(s).");

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

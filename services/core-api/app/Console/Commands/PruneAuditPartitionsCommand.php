<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Audit retention: DETACH PARTITION CONCURRENTLY, then DROP TABLE. There is no DELETE in this file.
 *
 * WHY NOT A DELETE (`postgresql-patterns:166`). Deleting a month of rows from a heap leaves a month
 * of dead tuples that autovacuum has to find, and the space is reused rather than returned — the
 * table bloats past its own live size and every query on it slows down, permanently. Dropping a
 * partition returns the files to the filesystem in O(1). On `audit_logs` there is a second reason
 * that matters more: the application role has no DELETE on this table at all (the migration revokes
 * it), so a retention DELETE would not merely be slow, it would be refused.
 *
 * WHY CONCURRENTLY. A plain `DETACH PARTITION` takes ACCESS EXCLUSIVE on the parent, which blocks
 * READERS as well as writers — and PostgreSQL's lock queue is ordered, so one long report in front of
 * it stalls every audit write that arrives behind it (`postgresql-patterns:117`). The cost is that
 * CONCURRENTLY cannot run inside a transaction block; the command checks for one and says so rather
 * than letting a 25001 surface as a mystery.
 *
 * THREE REFUSALS, ALL DELIBERATE. This command destroys the compliance record, so it is the one place
 * in this unit that argues with its operator:
 *   1. a retention window below :self::MIN_RETENTION_MONTHS needs `--force`;
 *   2. it will not run for real inside an open transaction;
 *   3. it never touches a relation whose name is not `audit_logs_YYYY_MM`, and reports each one it
 *      skipped — an unrecognised table near this one is a thing to look at, not a thing to drop.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it. The artisan
 * signature is `kb:prune-audit-partitions`.
 *
 * NOT SCHEDULED BY THIS CHANGE, and unlike the create command it should not be scheduled casually:
 * an unattended job that drops audit history wants a retention decision behind it first. See the
 * report.
 */
final class PruneAuditPartitionsCommand extends Command
{
    /**
     * The default window, in months, and it is deliberately long.
     *
     * Nothing in the specification pins an audit retention period, so this is the conservative
     * reading rather than a measured one: two years covers the usual annual-audit cycle with a
     * margin. It belongs in config once the retention surface exists (config/ is not this unit's to
     * edit), and until then `--months` is how an operator states a different policy.
     */
    private const DEFAULT_RETENTION_MONTHS = 24;

    /**
     * Below this, `--force` is required.
     *
     * The failure this guards against is a fat-fingered `--months=1` on a table whose entire value is
     * that it is complete, executed by a command that would otherwise cheerfully report success.
     */
    private const MIN_RETENTION_MONTHS = 12;

    protected $signature = 'kb:prune-audit-partitions
                            {--months= : Retention window in months (default 24; below 12 needs --force)}
                            {--dry-run : Report which partitions would be dropped and change nothing}
                            {--force : Permit a retention window below the floor}';

    protected $description = 'Drop audit_logs partitions older than the retention window (detach-concurrently, then drop).';

    public function handle(EloquentAuditLogPartitionRepository $partitions): int
    {
        $retention = $this->retentionMonths();

        if ($retention === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && $partitions->insideTransaction()) {
            $this->error(
                'Refusing to run inside an open transaction: ALTER TABLE … DETACH PARTITION '
                .'CONCURRENTLY is rejected by PostgreSQL in a transaction block (25001), and a '
                .'non-concurrent DETACH would take ACCESS EXCLUSIVE on audit_logs and stall every '
                .'audit write behind it. Run it outside a transaction, or use --dry-run.',
            );

            return self::FAILURE;
        }

        // The cutoff is a MONTH BOUNDARY, not "now minus N months". A partition is dropped only when
        // every timestamp it can hold is older than the window — so with a 24-month window on
        // 2026-08-13, audit_logs_2024_08 (which holds rows up to 2024-08-31) stays, because rows in
        // it are less than 24 months old. Off-by-one here deletes a month of evidence that was still
        // inside the retention promise.
        $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($retention);

        $this->line("Retention window: {$retention} months. Dropping partitions covering months before "
            .$cutoff->format('Y-m').'.');

        // Wreckage first. A partition left attached-but-detach-pending by an interrupted run makes
        // PostgreSQL refuse every subsequent CONCURRENTLY detach on this parent, so without this the
        // command would fail identically forever.
        if (! $this->finalizePending($partitions, $dryRun)) {
            return self::FAILURE;
        }

        $expired = [];
        $skipped = [];

        foreach ($partitions->partitionNames() as $name) {
            $month = EloquentAuditLogPartitionRepository::monthOf($name);

            if ($month === null) {
                // Unreachable through the create paths, which is exactly why it is reported rather
                // than ignored: something else made this relation.
                $skipped[] = $name;

                continue;
            }

            if (! $month->lessThan($cutoff)) {
                continue;
            }

            $expired[] = $name;
        }

        // Detached by an earlier run that died before the DROP. Still holding their rows, attached to
        // nothing, read by nobody — retained data with no partition to belong to.
        foreach ($partitions->detachedTableNames() as $name) {
            $month = EloquentAuditLogPartitionRepository::monthOf($name);

            if ($month === null) {
                $skipped[] = $name;

                continue;
            }

            if ($month->lessThan($cutoff)) {
                $expired[] = $name;
            }
        }

        foreach ($skipped as $name) {
            $this->warn("Skipped '{$name}': not an audit partition name (audit_logs_YYYY_MM). "
                .'Nothing was done to it — check what created it.');
        }

        if ($expired === []) {
            $this->info('No audit_logs partition is outside the retention window.');

            return self::SUCCESS;
        }

        return $this->drop($partitions, $expired, $dryRun);
    }

    /**
     * @param  list<string>  $expired
     */
    private function drop(
        EloquentAuditLogPartitionRepository $partitions,
        array $expired,
        bool $dryRun,
    ): int {
        $rows = [];
        $attached = $partitions->partitionNames();

        foreach ($expired as $name) {
            // The row count is recorded BEFORE the drop and printed either way, because this output
            // is the only trace that the partition ever existed once the files are gone.
            $count = $partitions->rowCount($name);
            $isAttached = in_array($name, $attached, true);

            if ($dryRun) {
                $rows[] = [$name, (string) $count, $isAttached ? 'would detach + drop' : 'would drop (already detached)'];

                continue;
            }

            try {
                if ($isAttached) {
                    $partitions->detachConcurrently($name);
                }

                $partitions->dropDetached($name);
            } catch (Throwable $failure) {
                $this->table(['partition', 'rows', 'action'], $rows);
                $this->error("Failed on {$name}: ".$failure->getMessage());

                // STOP rather than continue. A failure here can leave a detach pending, and the next
                // detach on this parent would be refused anyway — a loop would turn one clear failure
                // into N confusing ones.
                return self::FAILURE;
            }

            $rows[] = [$name, (string) $count, $isAttached ? 'detached + dropped' : 'dropped (was detached)'];
        }

        $this->table(['partition', 'rows', 'action'], $rows);

        if ($dryRun) {
            $this->comment('--dry-run: nothing was detached and nothing was dropped.');
        }

        return self::SUCCESS;
    }

    private function finalizePending(EloquentAuditLogPartitionRepository $partitions, bool $dryRun): bool
    {
        foreach ($partitions->pendingDetachNames() as $name) {
            if ($dryRun) {
                $this->warn("'{$name}' is mid-detach from an interrupted run; it would be FINALIZEd first.");

                continue;
            }

            $this->warn("Finalizing an interrupted detach of '{$name}'.");

            try {
                $partitions->finalizeDetach($name);
            } catch (Throwable $failure) {
                $this->error("Could not finalize the pending detach of {$name}: ".$failure->getMessage()
                    .' No further detach on audit_logs can succeed until this is resolved.');

                return false;
            }
        }

        return true;
    }

    private function retentionMonths(): ?int
    {
        $option = $this->option('months');

        if ($option === null || $option === '') {
            return self::DEFAULT_RETENTION_MONTHS;
        }

        // NORMALISE, THEN CHECK — see the long note in CreateAuditPartitionsCommand::monthsAhead() for
        // why `is_int()` is not the way to do it (the declared type of `getOption()` does not admit the
        // int Symfony actually stores, so PHPStan narrows it to `never`).
        //
        // It matters more here than there, because this command DESTROYS partitions: a programmatic
        // caller silently taking the type-error branch means the retention floor below was never
        // reached, and a reader who attributes the refusal to the floor is reading the wrong guard.
        if (is_array($option) || is_bool($option)) {
            $this->error('--months must be a positive integer.');

            return null;
        }

        $candidate = (string) $option;

        if (preg_match('/^\d+$/', $candidate) !== 1) {
            $this->error('--months must be a positive integer.');

            return null;
        }

        $months = (int) $candidate;

        if ($months < 1) {
            $this->error('--months must be at least 1. There is no "drop everything" mode here.');

            return null;
        }

        if ($months < self::MIN_RETENTION_MONTHS && ! (bool) $this->option('force')) {
            $this->error(
                "Refusing a {$months}-month audit retention window without --force (floor is "
                .self::MIN_RETENTION_MONTHS.' months). audit_logs is the compliance record and this '
                .'command destroys it irreversibly.',
            );

            return null;
        }

        return $months;
    }
}

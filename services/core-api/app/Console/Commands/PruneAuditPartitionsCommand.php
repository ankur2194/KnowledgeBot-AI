<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use App\Repositories\Eloquent\MonthlyPartitionRepository;

/**
 * Audit retention. The mechanism — detach-concurrently, then drop, with three refusals — is
 * `PrunePartitionsCommand`; what is here is the signature, the relation, and one sentence about why
 * the floor is where it is.
 *
 * THE 12-MONTH FLOOR AND THE 24-MONTH DEFAULT ARE ABOUT THE COMPLIANCE RECORD. Nothing in the
 * specification pins an audit retention period, so 24 months is the conservative reading rather than
 * a measured one: it covers the usual annual-audit cycle with a margin. The floor exists because a
 * fat-fingered `--months=1` on a table whose entire value is that it is COMPLETE would otherwise
 * report success.
 *
 * NOT SCHEDULED, DELIBERATELY. `routes/console.php` states it: an unattended DROP of audit data is a
 * deletion policy, not a maintenance task, and it needs a stated retention window before a cron
 * entry. Adding it there fails tests/Feature/ScheduleTest.php, which is the intended speed bump.
 *
 * The artisan signature is `kb:prune-audit-partitions`.
 */
final class PruneAuditPartitionsCommand extends PrunePartitionsCommand
{
    protected $signature = 'kb:prune-audit-partitions
                            {--months= : Retention window in months (default 24; below 12 needs --force)}
                            {--dry-run : Report which partitions would be dropped and change nothing}
                            {--force : Permit a retention window below the floor}';

    protected $description = 'Drop audit_logs partitions older than the retention window (detach-concurrently, then drop).';

    protected function partitions(): MonthlyPartitionRepository
    {
        // `$this->laravel->make()` rather than the `app()` helper: a Command already holds the
        // container it was resolved from, and reaching for the global helper inside one is how a
        // console class ends up untestable against a swapped binding.
        return $this->laravel->make(EloquentAuditLogPartitionRepository::class);
    }
}

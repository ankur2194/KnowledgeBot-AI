<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentUsageEventPartitionRepository;
use App\Repositories\Eloquent\MonthlyPartitionRepository;

/**
 * Keep `usage_events` writable by staying ahead of the calendar.
 *
 * WHAT HAPPENS WITHOUT THIS, AND WHY IT IS WORSE THAN THE AUDIT CASE EVEN THOUGH IT LOOKS MILDER.
 * `usage_events` is `PARTITION BY RANGE (occurred_at)` with no DEFAULT partition, so past the runway
 * every ledger INSERT fails with SQLSTATE 23514. The audit twin's failure is LOUD — every
 * ON_FAILURE_ABORT action returns 500 and somebody is paged within minutes. This one is SILENT:
 *
 *   * `UsageRecorder` is called from `SourceService::recordStorageUsage()`, which swallows and logs,
 *     because failing an upload over a meter reading would turn a healthy 201 into a 500 for a
 *     source that WAS created;
 *   * so metering simply stops, every quota reads low, `QuotaGate` admits everything, and the
 *     platform keeps serving perfectly while nobody is billed.
 *
 * The first symptom is an invoice. That asymmetry is why this entry is scheduled daily beside its
 * audit twin rather than monthly, and why its absence would not be caught by any alert that watches
 * error rates.
 *
 * The mechanism, the `--months` parsing and the idempotency argument live in
 * `CreatePartitionsCommand`. The artisan signature is `kb:create-usage-partitions`.
 */
final class CreateUsagePartitionsCommand extends CreatePartitionsCommand
{
    protected $signature = 'kb:create-usage-partitions
                            {--months= : Months of runway to guarantee beyond the current one (default 3)}
                            {--dry-run : Report what would be created and change nothing}';

    protected $description = 'Create the monthly usage_events partitions that keep the quota ledger writable.';

    protected function partitions(): MonthlyPartitionRepository
    {
        // `$this->laravel->make()` rather than the `app()` helper: a Command already holds the
        // container it was resolved from, and reaching for the global helper inside one is how a
        // console class ends up untestable against a swapped binding.
        return $this->laravel->make(EloquentUsageEventPartitionRepository::class);
    }
}

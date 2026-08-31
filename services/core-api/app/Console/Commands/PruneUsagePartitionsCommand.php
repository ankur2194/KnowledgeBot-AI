<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentUsageEventPartitionRepository;
use App\Repositories\Eloquent\MonthlyPartitionRepository;

/**
 * Usage-ledger retention. The mechanism is `PrunePartitionsCommand`; what is here is the signature,
 * the relation, and the reason the numbers differ from the audit twin's.
 *
 * ── THE DEFAULT IS 24 MONTHS AND THE FLOOR IS 12, THE SAME AS `audit_logs`, FOR A DIFFERENT
 *    REASON ─────────────────────────────────────────────────────────────────────────────────
 *
 * There is no compliance obligation on this table. What there is instead is an INVOICE DISPUTE
 * WINDOW: a customer questioning a bill is answered by the ledger rows behind it, and a partition
 * dropped inside that window makes the question unanswerable rather than merely inconvenient. Two
 * years is the same conservative reading, and the floor exists for the same fat-finger reason.
 *
 * ── DROPPING A PARTITION HERE ALSO MOVES A NUMBER, WHICH THE AUDIT TWIN DOES NOT ────────────
 *
 * `QuotaMetric::StorageBytes` is summed over ALL TIME with no period lower bound — `periodStart()`
 * returns null for it — so dropping the month in which an organization's oldest objects were
 * uploaded REDUCES its computed storage usage while the bytes are still in the bucket. The
 * organization silently gains storage allowance it did not buy.
 *
 * THAT IS WHY THIS COMMAND IS NOT SCHEDULED AND MUST NOT BE UNTIL THE STORAGE METRIC IS
 * PERIOD-BOUNDED OR SNAPSHOTTED. `monthly_tokens` is unaffected — its period is the calendar month,
 * so a partition older than the current month contributes nothing to it — and that difference is
 * exactly what makes the hazard easy to miss: the tile most people look at would keep reading
 * correctly.
 *
 * The artisan signature is `kb:prune-usage-partitions`.
 */
final class PruneUsagePartitionsCommand extends PrunePartitionsCommand
{
    protected $signature = 'kb:prune-usage-partitions
                            {--months= : Retention window in months (default 24; below 12 needs --force)}
                            {--dry-run : Report which partitions would be dropped and change nothing}
                            {--force : Permit a retention window below the floor}';

    protected $description = 'Drop usage_events partitions older than the retention window (detach-concurrently, then drop).';

    protected function partitions(): MonthlyPartitionRepository
    {
        // `$this->laravel->make()` rather than the `app()` helper: a Command already holds the
        // container it was resolved from, and reaching for the global helper inside one is how a
        // console class ends up untestable against a swapped binding.
        return $this->laravel->make(EloquentUsageEventPartitionRepository::class);
    }
}

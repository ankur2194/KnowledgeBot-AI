<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use App\Repositories\Eloquent\MonthlyPartitionRepository;

/**
 * Keep `audit_logs` writable by staying ahead of the calendar.
 *
 * WHAT HAPPENS WITHOUT THIS. `audit_logs` is `PARTITION BY RANGE (created_at)` with no DEFAULT
 * partition (the migration explains that choice). An INSERT whose `created_at` falls outside every
 * partition fails with SQLSTATE 23514, *"no partition of relation \"audit_logs\" found for row"* —
 * so at 00:00 on the first of a month with no runway, every login stops being audited and every
 * transactional audited action (an invitation, a role change) starts returning 500. The failure is
 * total, instant, and on a clock.
 *
 * The mechanism, the `--months` parsing and the idempotency argument live in
 * `CreatePartitionsCommand`, which `kb:create-usage-partitions` shares. What is here is the
 * signature and the relation — the only two things the two commands do not have in common.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it for
 * everything in App\Console\Commands. The artisan signature — the part that is a contract — is
 * `kb:create-audit-partitions`.
 *
 * SCHEDULED DAILY in routes/console.php, which carries the argument for daily rather than monthly:
 * a monthly entry has one firing per month to lose, and losing it is the outage above.
 */
final class CreateAuditPartitionsCommand extends CreatePartitionsCommand
{
    protected $signature = 'kb:create-audit-partitions
                            {--months= : Months of runway to guarantee beyond the current one (default 3)}
                            {--dry-run : Report what would be created and change nothing}';

    protected $description = 'Create the monthly audit_logs partitions that keep the table writable.';

    protected function partitions(): MonthlyPartitionRepository
    {
        // `$this->laravel->make()` rather than the `app()` helper: a Command already holds the
        // container it was resolved from, and reaching for the global helper inside one is how a
        // console class ends up untestable against a swapped binding.
        return $this->laravel->make(EloquentAuditLogPartitionRepository::class);
    }
}

# The recrawl dispatcher — command and claim query

Companion to `SKILL.md`. The schedule entry itself stays there; this file is the implementation it
dispatches — `DispatchDueCrawls` end to end, and the `claimDue` statement that does the fairness and
the claiming in one round trip. The comments are the reasoning, not decoration.

```php
// services/core-api/app/Console/Commands/DispatchDueCrawls.php
namespace App\Console\Commands;

use App\Jobs\SubmitCrawlRun;
use App\Repositories\Contracts\CrawlScheduleRepositoryInterface as Schedules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchDueCrawls extends Command
{
    protected $signature = 'kb:dispatch-due-crawls';
    protected $description = 'Claim crawl configurations past their due time and dispatch one submission job each.';

    private const GLOBAL_BATCH = 200;   // ≈ what the `crawl` queue drains between ticks
    private const PER_ORG_BATCH = 3;    // fairness: no org exceeds this per tick
    private const PER_ORG_INFLIGHT = 2; // …or this many concurrent runs

    public function handle(Schedules $schedules, TenantContext $tenant): int
    {
        $now = now();                                    // UTC, always
        $failed = 0;

        // Claim and advance in ONE transaction, then dispatch after commit. Dispatching first
        // means a crash between dispatch and UPDATE re-dispatches the same source next tick.
        $claimed = DB::transaction(function () use ($schedules, $now) {
            $rows = $schedules->claimDue($now, self::GLOBAL_BATCH, self::PER_ORG_BATCH, self::PER_ORG_INFLIGHT);
            foreach ($rows as $row) {
                $schedules->advance($row->id, $this->nextDueAt($row, $now));  // due time moves on CLAIM
            }
            return $rows;
        });

        foreach ($claimed as $row) {
            try {
                $tenant->setOrganization($row->organization_id);   // per row — never once, outside the loop
                // afterCommit is belt and braces: the transaction above already closed, but a
                // future caller wrapping this command must not enqueue a job for an unclaimed row.
                SubmitCrawlRun::dispatch($row->organization_id, $row->source_id, $row->crawl_run_id)
                    ->onQueue('ai-dispatch')->afterCommit();   // Laravel's closed queue list (laravel-queues-valkey);
                                                        // 'crawl-submit' is not a queue in either runtime
                                                        // and nothing would ever consume it.
            } catch (\Throwable $e) {
                $failed++;
                report($e);                                        // one bad row must not kill the tick
            } finally {
                $tenant->clear();                                  // pooled context; see kb-tenancy-isolation
            }
        }

        // Freshness is written every tick, success or not — the alert is on staleness, not on absence.
        $schedules->recordTick($now, dispatched: count($claimed) - $failed, failed: $failed);

        // Non-zero exit is what makes onFailure/ScheduledTaskFailed fire. Swallowing it here
        // is the single most common way a broken sweep reports success forever.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

```sql
-- CrawlScheduleRepository::claimDue — the fairness and the claim, in one statement.
-- Raw SQL, deliberately org-agnostic (it iterates all tenants), so it is an allow-listed
-- exception to the DB::select CI grep in kb-tenancy-isolation; the org is set per row above.
WITH ranked AS (
    SELECT cc.id,
           row_number() OVER (PARTITION BY cc.organization_id ORDER BY cc.next_crawl_due_at) AS org_rank
    FROM crawl_configurations cc
    JOIN knowledge_sources ks ON ks.id = cc.source_id
    WHERE cc.schedule_kind <> 'manual'
      AND cc.next_crawl_due_at <= :now
      AND ks.status IN ('ready', 'ready_with_warnings', 'failed')       -- never a source mid-delete
      AND NOT EXISTS (SELECT 1 FROM crawl_runs r
                      WHERE r.source_id = cc.source_id AND r.status IN ('queued', 'running'))
      AND (SELECT count(*) FROM crawl_runs r2
           WHERE r2.organization_id = cc.organization_id
             AND r2.status IN ('queued', 'running')) < :per_org_inflight
)
SELECT cc.* FROM crawl_configurations cc
WHERE cc.id IN (SELECT id FROM ranked WHERE org_rank <= :per_org_batch)
ORDER BY cc.next_crawl_due_at
LIMIT :global_batch
FOR UPDATE SKIP LOCKED;   -- two ticks, or two replicas, cannot claim the same row
```

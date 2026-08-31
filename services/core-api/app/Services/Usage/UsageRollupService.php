<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Models\ProviderCall;
use App\Models\SourceItem;
use App\Services\Quotas\QuotaCounters;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The work behind `kb:rollup-usage`, one organization at a time.
 *
 * ── EVERY QUERY RUNS INSIDE `TenantContext::runFor()` ───────────────────────────────────────
 *
 * `laravel-scheduler` NN: a sweep that iterates organizations sets tenant context per row and clears
 * it in a `finally`, because the scheduler is one long-lived process and the previous tenant STILL
 * BEING SET is the silent failure rather than the loud one. `runFor()` is that `finally` — which is
 * why it is a scoped runner rather than a setter a caller can forget to unset.
 *
 * The consequence worth stating: every read below is scoped in BOTH layers, the explicit
 * `organization_id` predicate and the `#[ScopedBy]` backstop, so there is no `// tenancy-exempt:`
 * marker anywhere in this path and no cross-tenant query for a reviewer to check. The cost is
 * O(organizations) statements per tick.
 *
 * ── THE ONE ORG-AGNOSTIC READ IS THE ORGANIZATION LIST ITSELF ───────────────────────────────
 *
 * `organizationIds()` reads `organizations`, which carries no `OrganizationScope` by construction —
 * it IS the tenant root, it has no `organization_id` column, and its model's docblock says so. That
 * is not an exemption being claimed; there is no scope to bypass.
 */
final readonly class UsageRollupService
{
    /**
     * The most rows one organization may have derived for it in one pass, per source.
     *
     * A BOUND ON THE TICK, NOT ON CORRECTNESS. Anything left over is picked up next hour, because
     * the window is three hours wide and every derivation is idempotent — so a cap that bites simply
     * spreads one organization's backlog over several ticks instead of letting it delay every
     * scheduled entry defined after this one.
     */
    private const PER_ORGANIZATION_LIMIT = 5_000;

    public function __construct(
        private UsageRecorder $recorder,
        private QuotaCounters $counters,
        private TenantContext $tenants,
    ) {}

    /**
     * Every organization, oldest first.
     *
     * ── IT IS A `lazy()` WALK AND NOT A `pluck()` ──────────────────────────────────────────
     *
     * `pluck('id')` would materialise every organization id in memory. That is fine today and stops
     * being fine at the scale this command is written for, and the failure mode of getting it wrong
     * is an OOM inside a scheduled process that produces no error anybody reads.
     *
     * // tenancy-exempt: `organizations` is the TENANT ROOT. It has no `organization_id` column and
     * // carries no OrganizationScope — there is nothing here to scope BY, and this is the query
     * // that decides which organization every subsequent query is scoped TO. Each id it yields is
     * // then bound with `TenantContext::runFor()` before any tenant-owned table is read.
     *
     * @return iterable<int, string>
     */
    public function organizationIds(): iterable
    {
        foreach (Organization::query()->orderBy('id')->lazyById() as $organization) {
            yield $organization->id;
        }
    }

    /**
     * Derive whatever was not metered, then refresh the counters, for ONE organization.
     */
    public function reconcile(
        string $organizationId,
        CarbonImmutable $since,
        bool $dryRun = false,
    ): UsageRollupResult {
        return $this->tenants->runFor($organizationId, function () use ($organizationId, $since, $dryRun): UsageRollupResult {
            $tokens = $this->deriveTokenEvents($organizationId, $since, $dryRun);
            $storage = $this->deriveStorageEvents($organizationId, $since, $dryRun);

            return new UsageRollupResult(
                derivedTokenEvents: $tokens,
                derivedStorageEvents: $storage,
                reconciledMetrics: $dryRun ? [] : $this->refreshCounters($organizationId),
            );
        });
    }

    /**
     * One ledger pair per provider attempt in the window that has not been metered.
     *
     * ── IT DOES NOT ASK "WHICH CALLS ARE MISSING", IT RE-DERIVES ALL OF THEM ────────────────
     *
     * There is no `WHERE NOT EXISTS (SELECT … FROM usage_events)` anti-join here, and its absence is
     * the design rather than an oversight. That join would be over a PARTITIONED table on a
     * four-column unique index, per organization, per hour — and it would buy nothing, because
     * `UsageRecorder::record()` already claims each row with `INSERT … ON CONFLICT DO NOTHING`. A
     * duplicate derivation writes zero rows and costs one index probe, which is cheaper than the
     * anti-join that would have avoided it.
     *
     * THAT IS ALSO WHY THE COUNT RETURNED IS TRUSTWORTHY: it counts rows that were actually WRITTEN,
     * so a non-zero number means a real hole was found rather than that the sweep ran.
     *
     * ── THE MODEL AND PROVIDER COME FROM THE JOINED ROWS, NEVER FROM THE CALL ───────────────
     *
     * `provider_calls` holds `model_id` and `provider_connection_id`, which are references. The
     * ledger holds the vendor NAME and the vendor MODEL STRING, because a billing record must still
     * name what answered after a catalogue row is removed. Both relations are eager-loaded, and a
     * call whose catalogue row has since been deleted is SKIPPED rather than metered with a null —
     * `usage_events_provider_attribution_paired` would refuse the row anyway, and refusing it here
     * gives the skip a reason instead of a constraint name.
     */
    private function deriveTokenEvents(string $organizationId, CarbonImmutable $since, bool $dryRun): int
    {
        $written = 0;

        $calls = ProviderCall::query()
            // EXPLICIT, and it is the mechanism — the ambient context bound by `runFor()` is the
            // backstop. Both, always: on this path they happen to agree, and the explicit predicate
            // is what keeps that true if a future caller forgets the runner.
            ->where('organization_id', '=', $organizationId)
            ->where('created_at', '>=', $since)
            ->with(['model', 'connection'])
            ->orderBy('created_at')
            ->limit(self::PER_ORGANIZATION_LIMIT)
            ->get();

        foreach ($calls as $call) {
            $model = $call->model;
            $connection = $call->connection;

            if ($model === null || $connection === null) {
                // The catalogue row or the connection was removed. Both are ON DELETE RESTRICT while
                // a call references them, so this is unreachable today — it is a guard rather than a
                // branch, and it fails by skipping rather than by writing a row the database would
                // refuse.
                continue;
            }

            if ($dryRun) {
                // A DRY RUN CANNOT KNOW WHETHER A ROW WOULD BE WRITTEN without doing the insert,
                // because the answer is "did it collide". Counting every candidate would report the
                // whole window as a hole; counting none reports the sweep as a no-op. The second is
                // the honest one: this number means "rows written", and a dry run writes none.
                continue;
            }

            $written += $this->recorder->recordProviderCall(
                $call,
                $connection->provider,
                $model->model,
            );
        }

        return $written;
    }

    /**
     * One storage row per stored source item in the window that has not been metered.
     *
     * ── IT KEYS ON `byte_size` AND `storage_key` BEING PRESENT, NOT ON THE SOURCE TYPE ──────
     *
     * A crawl target has no object until the crawler fetches one — `storage_key`, `content_hash`,
     * `mime` and `byte_size` are four nulls together, which `source_items_stored_object_is_complete`
     * requires — so this filter cannot meter bytes that do not exist yet, whatever the source's type
     * column says.
     *
     * ── `occurred_at` IS THE ITEM'S `created_at` ────────────────────────────────────────────
     *
     * The same instant `SourceService::recordStorageUsage()` uses, which is what makes this
     * derivation collide with that write instead of adding to it.
     */
    private function deriveStorageEvents(string $organizationId, CarbonImmutable $since, bool $dryRun): int
    {
        $written = 0;

        $items = SourceItem::query()
            ->where('organization_id', '=', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('byte_size')
            ->whereNotNull('storage_key')
            ->orderBy('created_at')
            ->limit(self::PER_ORGANIZATION_LIMIT)
            ->get();

        foreach ($items as $item) {
            if ($dryRun || $item->byte_size === null || $item->created_at === null) {
                continue;
            }

            $written += (int) $this->recorder->recordStorageAdded(
                $organizationId,
                $item->id,
                $item->byte_size,
                CarbonImmutable::instance($item->created_at),
            );
        }

        return $written;
    }

    /**
     * Re-read every LEDGER-BACKED metric from PostgreSQL, which refreshes its counter with the exact
     * value.
     *
     * ── IT REFRESHES RATHER THAN COMPARING, AND THAT IS WHY THERE IS NO "DRIFT" NUMBER ─────
     *
     * A comparison would need to read the cached value and the primary value and report the
     * difference — and the difference is not actionable: the counter is ALLOWED to be low (it is a
     * lower bound with a 60 s TTL on an evicting instance), so any non-zero drift is expected rather
     * than a finding. What is worth doing is making it zero, which `authoritative()` does as a side
     * effect of the read.
     *
     * POINT-IN-TIME METRICS ARE SKIPPED, because they are never cached: `bots` and `users` go DOWN
     * when a row is deleted, so a stale-low value would be wrong in the ADMITTING direction with no
     * mechanism to notice. `QuotaCounters` reads them straight through, every time.
     *
     * @return list<QuotaMetric>
     */
    private function refreshCounters(string $organizationId): array
    {
        $refreshed = [];

        foreach (QuotaMetric::cases() as $metric) {
            if (! $metric->isLedgerBacked()) {
                continue;
            }

            $this->counters->authoritative($organizationId, $metric);
            $refreshed[] = $metric;
        }

        return $refreshed;
    }
}

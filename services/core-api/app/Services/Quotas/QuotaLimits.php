<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Enums\QuotaMetric;
use App\Models\Organization;

/**
 * The four limits as they sit on an `organizations` row, read in one place.
 *
 * ── `null` IS UNLIMITED AND `0` IS "NOTHING IS ALLOWED", AND THEY ARE DIFFERENT STATES ───────
 *
 * The single most important sentence about these four columns, restated here because this is the
 * object every caller actually reads. `limitFor()` returns null for an unmetered metric and
 * `QuotaGate` SKIPS it entirely — it does not compare against zero, does not fetch a counter, and
 * does not issue the aggregate query. An organization that has never been given a plan is therefore
 * unmetered rather than frozen, which is the only safe direction for a migration that adds four
 * nullable columns to a live table.
 *
 * ── IT IS BUILT FROM THE ROW, NEVER FROM REQUEST INPUT ───────────────────────────────────────
 *
 * `fromOrganization()` is the only constructor a request path uses, and the row it reads is the one
 * the route binding already resolved and `org.member` already proved this caller belongs to. There
 * is no path by which a caller names their own limit.
 */
final readonly class QuotaLimits
{
    public function __construct(
        public ?int $storageBytes,
        public ?int $bots,
        public ?int $users,
        public ?int $monthlyTokens,
    ) {}

    public static function fromOrganization(Organization $organization): self
    {
        return new self(
            storageBytes: $organization->storage_bytes_quota,
            bots: $organization->bots_quota,
            users: $organization->users_quota,
            monthlyTokens: $organization->monthly_tokens_quota,
        );
    }

    /**
     * The ceiling for one metric, or null when the organization is not metered on it.
     *
     * A `match` over the enum rather than a `$this->{$metric->limitColumn()}` lookup: the dynamic
     * form would compile and would silently return null for a metric whose column name was
     * mistyped, which reads on every screen as "unlimited".
     */
    public function limitFor(QuotaMetric $metric): ?int
    {
        return match ($metric) {
            QuotaMetric::StorageBytes => $this->storageBytes,
            QuotaMetric::Bots => $this->bots,
            QuotaMetric::Users => $this->users,
            QuotaMetric::MonthlyTokens => $this->monthlyTokens,
        };
    }

    /**
     * @return array<string, int|null> keyed by `QuotaMetric->value`, for a Resource or an audit
     *                                 detail. Nulls are PRESENT rather than omitted: "unlimited" is
     *                                 a value a screen has to render, and an absent key would be
     *                                 indistinguishable from a projection that forgot the field.
     */
    public function toArray(): array
    {
        $out = [];

        foreach (QuotaMetric::cases() as $metric) {
            $out[$metric->value] = $this->limitFor($metric);
        }

        return $out;
    }
}

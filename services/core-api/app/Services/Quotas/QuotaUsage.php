<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Enums\QuotaMetric;

/**
 * One metric's answer: what is used, what the ceiling is, and WHICH STORE ANSWERED.
 *
 * ── `source` IS PUBLISHED, AND IT IS NOT DEBUG DECORATION ────────────────────────────────────
 *
 * Valkey is the fast path and PostgreSQL is the truth (`valkey-keyspaces`: "Valkey is never the
 * source of truth… usage and quota truth live in PostgreSQL"). The counter can only ever be LOW —
 * a lost increment or an expiry loses counted usage, it never invents any — so a value that came
 * from the cache is a lower bound and a value that came from the database is exact.
 *
 * That distinction decides what a caller may do with the number, so it travels WITH the number
 * rather than being implied by which method was called:
 *
 *   * A REFUSAL computed from a cached value is sound, because true usage is at least the cached
 *     value. `QuotaGate` takes that shortcut deliberately, and it is the shortcut that matters —
 *     it is the one that fires under abuse, which is exactly when the aggregate query is most
 *     expensive.
 *   * An ADMISSION is never made on a cached value. `QuotaGate` re-reads the primary before it lets
 *     anything through, which is `valkey-keyspaces`' own rule for this family, stated there as "a
 *     stale value never authorizes an over-quota action — the enforcing check re-reads the primary".
 *
 * It is also what makes a cache outage VISIBLE rather than silent: a dashboard rendering
 * `source: database` for every metric is a Valkey that is down, and nothing else in the system says
 * so.
 */
final readonly class QuotaUsage
{
    public const SOURCE_CACHE = 'cache';

    public const SOURCE_DATABASE = 'database';

    public function __construct(
        public QuotaMetric $metric,
        public int $used,
        public ?int $limit,
        public string $source,
    ) {}

    /** An unmetered metric — `limit === null` — is never exceeded, whatever `used` says. */
    public function exceeded(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }

    /**
     * Would `$additional` more units breach the ceiling?
     *
     * `>` and not `>=`: a request that lands EXACTLY on the limit is inside it. The asymmetry with
     * `exceeded()` is deliberate and is the difference between "you have used your allowance" and
     * "this would take you past it".
     */
    public function wouldExceed(int $additional): bool
    {
        return $this->limit !== null && ($this->used + $additional) > $this->limit;
    }

    /** Null when unmetered. Never negative: an over-quota organization has zero left, not less. */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }
}

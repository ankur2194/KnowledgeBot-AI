<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\QuotaMetric;
use App\Services\Usage\RecordedUsage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The quota ledger's seam: one write, and the reads that turn it into a number.
 *
 * `$organizationId` is REQUIRED AND POSITIONAL on every method, for the reason every repository in
 * this application takes it that way: `#[ScopedBy(OrganizationScope::class)]` reads the AMBIENT
 * context, so on a path where that context is stale or empty both layers fail together unless the
 * argument is explicit (`laravel-control-plane`). Here the consequence of getting it wrong is not a
 * read leak, it is one tenant's spend counted against another tenant's quota.
 */
interface UsageEventRepositoryInterface
{
    /**
     * Append one ledger row, IDEMPOTENTLY.
     *
     * ── THE RETURN VALUE IS "WAS THIS NEW", AND CALLERS MUST NOT IGNORE IT ────────────────────
     *
     * An implementation claims the row with `INSERT … ON CONFLICT DO NOTHING`, so a second call
     * naming the same `(organization, event_type, dedupe_key, occurred_at)` writes nothing and
     * returns false. That is not an error and must not be raised as one: `kb:rollup-usage` runs
     * hourly and re-derives events for provider calls it has already seen, and a path that treated
     * a duplicate as a failure would fail every hour forever.
     *
     * `SELECT` then `INSERT` is NOT an acceptable implementation — it is not atomic at READ
     * COMMITTED, so two workers both see nothing and both insert (`postgresql-patterns`, and its
     * note directing concurrent upserts away from `MERGE`, which is a join and raises rather than
     * skipping).
     *
     * @return bool true when a row was written, false when this usage was already recorded
     */
    public function record(string $organizationId, RecordedUsage $usage): bool;

    /**
     * How much of one LEDGER-BACKED metric this organization has consumed in the metric's current
     * period: the sum of its `add` types minus the sum of its `subtract` types.
     *
     * `$at` decides the period — `QuotaMetric::periodStart()` turns it into a lower bound, or into
     * "no lower bound" for a metric that never resets. It is an argument rather than `now()` so a
     * test can assert that a month boundary binds, and so a reconciliation can ask about a period
     * that has closed.
     *
     * @throws InvalidArgumentException when `$metric` is point-in-time — asking a ledger question
     *                                  about `bots` or `users` would produce an empty predicate
     *                                  over the whole table, and a plausible zero is the worst
     *                                  possible answer to a quota question
     */
    public function consumed(string $organizationId, QuotaMetric $metric, DateTimeImmutable $at): int;

    /**
     * Tokens and their model, for §8.23's "token usage and estimated cost by model" tile.
     *
     * Returns one row per `(provider, model)` with input and output totals kept APART, because they
     * are priced apart and a single total cannot be costed. Output already includes reasoning
     * tokens — that is a property of what was written, not of this query (see
     * `App\Enums\UsageEventType::ChatTokensOutput` and `App\Services\Usage\UsageArithmetic`).
     *
     * @return list<array{provider: string, model: string, input_tokens: int, output_tokens: int}>
     */
    public function tokensByModel(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array;
}

<?php

declare(strict_types=1);

namespace App\Enums;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The four things an organization has a quota in, and the one place each one's ARITHMETIC lives.
 *
 * ── TWO SHAPES, AND CONFLATING THEM IS THE DEFECT THIS ENUM EXISTS TO PREVENT ─────────────────
 *
 * LEDGER-BACKED metrics accumulate over a PERIOD and are answered by summing `usage_events`:
 * `monthly_tokens` resets every calendar month, `storage_bytes` never resets and is added minus
 * removed. POINT-IN-TIME metrics are `count(*)` over a primary table right now: `bots` and `users`
 * go DOWN when a row is deleted, and there is no period in which they accumulate.
 *
 * Writing the second kind as a ledger is the tempting mistake, because it makes one code path
 * instead of two. It produces a running sum that drifts from `count(*)` the first time a row leaves
 * by a route nobody instrumented — a cascade, a purge worker, a support script — and the drift is
 * silent and one-directional: the ledger says the organization is at its bot limit while the console
 * shows four bots. `isLedgerBacked()` is that split, declared once and read by `QuotaGate`,
 * `QuotaCounters` and the reconciliation in `kb:rollup-usage` alike.
 *
 * ── THE VALKEY KEY FAMILY IS `quota:{org}:{period}:{metric}` AND `metric` IS `->value` ────────
 *
 * `valkey-keyspaces` fixes the family, the 60 s TTL and the instance (the EVICTING one, reachable
 * only as `Cache::store('ephemeral')`), and `config/cache.php:34` names it as one of exactly two
 * read-through families permitted there. `period()` below is the other segment, and it is what
 * makes a monthly counter roll over without anyone deleting a key.
 */
enum QuotaMetric: string
{
    /**
     * Bytes of object storage this organization currently occupies.
     *
     * LEDGER-BACKED WITH NO PERIOD: `storage.bytes.added` minus `storage.bytes.removed` over ALL
     * time. `period()` therefore returns the sentinel `all`, not a month, and the key is
     * `quota:{org}:all:storage_bytes` — a monthly key here would reset the organization's storage
     * usage to zero on the first of every month, which reads as a plausible number and is a quota
     * that never binds.
     */
    case StorageBytes = 'storage_bytes';

    /** How many bots exist in this organization right now. POINT-IN-TIME: `count(*)` over `bots`. */
    case Bots = 'bots';

    /**
     * How many members exist in this organization right now.
     *
     * POINT-IN-TIME over `organization_users`, and it counts MEMBERSHIPS rather than users: one
     * person in two organizations occupies a seat in each, which is the thing being metered.
     */
    case Users = 'users';

    /**
     * Provider tokens billed to this organization in the CURRENT CALENDAR MONTH, input plus output.
     *
     * LEDGER-BACKED WITH A MONTHLY PERIOD. Output INCLUDES reasoning tokens — see
     * `UsageEventType::ChatTokensOutput`, which carries the whole argument, and
     * `App\Services\Usage\UsageArithmetic`, which is the only place the sum is written.
     *
     * THE MONTH IS UTC AND CALENDAR, NOT A ROLLING 30 DAYS AND NOT THE TENANT'S BILLING ANNIVERSARY.
     * `app.timezone` is UTC and `laravel-scheduler` forbids wall-clock scheduling for the reason
     * that bites here too: a tenant-local month boundary is two distinct instants under DST, so a
     * quota window keyed on one would either grant an extra hour of budget or lose one, once a year,
     * per tenant. When per-tenant billing anniversaries are introduced they become a COLUMN that
     * `period()` reads, not a second spelling of the key.
     */
    case MonthlyTokens = 'monthly_tokens';

    /**
     * Is this metric answered by summing `usage_events`, or by counting a primary table?
     *
     * @see self for why the two must not be collapsed.
     */
    public function isLedgerBacked(): bool
    {
        return match ($this) {
            self::StorageBytes, self::MonthlyTokens => true,
            self::Bots, self::Users => false,
        };
    }

    /**
     * The `usage_events.event_type` values this metric adds, and the ones it subtracts.
     *
     * Returns `[]` for a point-in-time metric, which is what makes "asked a ledger question about
     * `bots`" a visibly empty query rather than a query over the whole table.
     *
     * @return array{add: list<UsageEventType>, subtract: list<UsageEventType>}
     */
    public function ledgerTerms(): array
    {
        return match ($this) {
            self::StorageBytes => [
                'add' => [UsageEventType::StorageBytesAdded],
                'subtract' => [UsageEventType::StorageBytesRemoved],
            ],
            self::MonthlyTokens => [
                'add' => [UsageEventType::ChatTokensInput, UsageEventType::ChatTokensOutput],
                'subtract' => [],
            ],
            self::Bots, self::Users => ['add' => [], 'subtract' => []],
        };
    }

    /**
     * The `organizations` column holding this metric's limit. NULL in that column means UNLIMITED.
     */
    public function limitColumn(): string
    {
        return match ($this) {
            self::StorageBytes => 'storage_bytes_quota',
            self::Bots => 'bots_quota',
            self::Users => 'users_quota',
            self::MonthlyTokens => 'monthly_tokens_quota',
        };
    }

    /**
     * The `{period}` segment of `quota:{org}:{period}:{metric}` for a given instant.
     *
     * `YYYYMM` for a monthly metric, the literal `all` for one that never resets. A metric whose
     * period is `all` still carries the segment rather than omitting it: a key family with a
     * variable number of segments cannot be matched by an ACL pattern or walked by an org purge,
     * and both of those are the reason the grammar is fixed (`valkey-keyspaces`).
     */
    public function period(DateTimeInterface $at): string
    {
        return match ($this) {
            self::MonthlyTokens => $at->format('Ym'),
            self::StorageBytes, self::Bots, self::Users => 'all',
        };
    }

    /**
     * The first instant of this metric's current period, or null when it has none.
     *
     * Null is what a ledger query reads as "no lower bound" — the whole-of-time sum `storage_bytes`
     * needs — and it is a different fact from "the period started at the epoch", which would put a
     * useless predicate on the index.
     */
    public function periodStart(DateTimeImmutable $at): ?DateTimeImmutable
    {
        return match ($this) {
            self::MonthlyTokens => $at->setDate((int) $at->format('Y'), (int) $at->format('n'), 1)
                ->setTime(0, 0, 0, 0),
            self::StorageBytes, self::Bots, self::Users => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

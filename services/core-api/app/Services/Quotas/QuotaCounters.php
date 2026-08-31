<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Enums\QuotaMetric;
use App\Models\Bot;
use App\Models\OrganizationUser;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The `quota:{org_id}:{period}:{metric}` family — the fast path in front of a PostgreSQL aggregate.
 *
 * ═══ WHICH STORE A DISAGREEMENT RESOLVES TO, AND WHY ════════════════════════════════════════
 *
 * POSTGRESQL. Without qualification, in every direction, for every metric.
 *
 * `valkey-keyspaces` states it as a non-negotiable — *"Valkey is never the source of truth… job
 * state, delivery counts, usage and quota truth, and audit live in PostgreSQL. Every Valkey family
 * must be reconstructible or discardable"* — and the catalog row for this family states the
 * operational half: *"Read-through of a PostgreSQL aggregate. A miss reads PostgreSQL; a stale
 * value never authorizes an over-quota action — the enforcing check re-reads the primary."*
 *
 * The reason is not deference to a skill file, it is that the two stores fail differently and only
 * one of them can be wrong in a direction that matters:
 *
 *   * THE CACHE CAN ONLY EVER BE LOW. This family lives on `valkey-cache`, which runs
 *     `allkeys-lru` with no RDB and no AOF, so an entry can vanish at any moment. Every way it goes
 *     wrong — eviction, expiry, a lost increment, a restart — LOSES counted usage. There is no
 *     mechanism by which it invents usage that did not happen.
 *   * SO A REFUSAL FROM THE CACHE IS SOUND and an ADMISSION FROM THE CACHE IS NOT. True usage is at
 *     least the cached value, so `cached >= limit` implies `true >= limit`. The converse does not
 *     hold, which is why `QuotaGate` re-reads the primary before it admits anything.
 *
 * ── WHAT THE CACHE THEREFORE BUYS, STATED HONESTLY ──────────────────────────────────────────
 *
 * Two things, and NOT "one less query per request":
 *
 *   1. THE REFUSAL SHORTCUT. An organization that is already over its allowance is refused without
 *      touching PostgreSQL. That is the case where the aggregate is most expensive and most
 *      frequent — an over-quota tenant retrying — so it is the one worth short-circuiting.
 *   2. THE DISPLAY VALUE. `GET /quotas` renders on every dashboard load and needs a number, not a
 *      verdict. A 60 s stale-low reading there costs nothing.
 *
 * If the admission-path aggregate ever stops fitting its budget, THE FIX IS NOT A LONGER TTL — that
 * makes the number wronger in the one direction that lets usage through. It is a materialised
 * per-period aggregate row maintained by `kb:rollup-usage`, which is exact and is a `WHERE
 * organization_id = ? AND period = ?` primary-key read. That is the stated re-open condition, and
 * it re-opens on a MEASUREMENT — the aggregate's p99 against the pre-turn budget — never on a
 * feeling.
 *
 * ═══ A CACHE OUTAGE DEGRADES SILENTLY AND FAILS *CLOSED* ═══════════════════════════════════
 *
 * `phpunit.xml` deliberately leaves `REDIS_CACHE_HOST` unset so the `ephemeral` connection refuses
 * to connect in the suite — pointing it at the test Valkey would put the evicting and non-evicting
 * keyspaces on one server, and a lock wrongly written to the evicting store would then pass every
 * test. So the suite's DEFAULT path through this class is the degraded one, which is the right way
 * round: the degraded path is the one that must never be wrong.
 *
 * Every cache call below is wrapped, and a failure means "no cached value" — which routes the caller
 * to PostgreSQL. That is failing CLOSED, not open: losing the cache costs a query, never an
 * admission. `Throwable` and not a driver-specific exception, because `phpredis`, `predis` and a
 * DNS failure raise three different types and the correct handling is identical for all three.
 *
 * ═══ POINT-IN-TIME METRICS ARE NEVER CACHED ════════════════════════════════════════════════
 *
 * `bots` and `users` are `count(*)` over a primary table with an org-leading index — a cheaper query
 * than the round trip that would cache it — and they go DOWN when a row is deleted, so a stale-low
 * cached value would be wrong in the ADMITTING direction with no mechanism to notice. They are read
 * straight through, always, and `QuotaMetric::isLedgerBacked()` is the switch.
 */
final class QuotaCounters
{
    /**
     * 60 seconds, from `valkey-keyspaces`' catalog row for this family, unmodified.
     *
     * It is short because the value is a lower bound on money. Lengthening it widens the window in
     * which a refusal is not reached; shortening it converts the whole family into a per-request
     * aggregate, which is what it exists to avoid.
     */
    public const TTL_SECONDS = 60;

    /**
     * THE EVICTING INSTANCE, REACHABLE ONLY BY NAME. `config/cache.php` admits exactly two families
     * to `ephemeral` — `cfg:` and `quota:` — and the DEFAULT store is deliberately the non-evicting
     * one, because `Cache::lock()`, `WithoutOverlapping` and the scheduler mutexes all fail OPEN
     * when their key disappears. Writing this family to the default store would put tenant-derived
     * counters on the instance that must never fill up; reading a LOCK from this one would make an
     * evicted lock read as free.
     */
    private const STORE = 'ephemeral';

    /**
     * SET ONCE A CACHE OPERATION HAS FAILED IN THIS INSTANCE, AND NEVER UNSET.
     *
     * ── WHY A LATCH, MEASURED RATHER THAN ASSUMED ────────────────────────────────────────────
     *
     * A cache failure is not free to discover. Measured in this repository's own suite against an
     * unresolvable `valkey-cache` host, ONE failed connect costs ~3.3 SECONDS — the failure is DNS
     * resolution (`getaddrinfo`), which no `timeout` option bounds, because phpredis' timeout
     * applies to the TCP connect that never gets attempted. Without this latch a single request that
     * touches two metrics pays it twice, and `kb:rollup-usage` pays it once per metric per
     * organization: the ledger derivation for a hundred tenants would take five minutes of waiting
     * for a hostname.
     *
     * So the first failure disables the cache for the LIFETIME OF THIS INSTANCE. That bounds the
     * cost of an outage to one timeout per unit of work rather than one per operation, and it stops
     * a single blip from filling the log with an identical line per metric.
     *
     * ── WHAT THE LIFETIME ACTUALLY IS, AND WHY THAT IS THE RIGHT SCOPE ──────────────────────
     *
     * This class is bound with `bind()` rather than `singleton()` — like every repository here — so
     * it is constructed fresh per resolution: one instance per request, and one per console command.
     * A request that latches is degraded for that request and healthy on the next one; the hourly
     * rollup that latches skips the remaining COUNTER REFRESHES for that tick and picks them up an
     * hour later. Neither loses correctness, because PostgreSQL answered every question either way —
     * which is the whole reason a latch is safe here and would not be on a lock or a rate limiter.
     */
    private bool $degraded = false;

    public function __construct(
        private readonly UsageEventRepositoryInterface $ledger,
        private readonly CacheFactory $cache,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * The cached lower bound, or null when there is none.
     *
     * NULL AND NOT ZERO on a miss, and the distinction is the whole safety property: zero would be a
     * cached value saying "this organization has used nothing", which `QuotaGate` would then be
     * entitled to admit against. Null means "ask PostgreSQL".
     */
    public function cachedLowerBound(string $organizationId, QuotaMetric $metric): ?QuotaUsage
    {
        if (! $metric->isLedgerBacked()) {
            // Never cached. See the class docblock: a stale-low count of bots or users is wrong in
            // the ADMITTING direction, because these metrics decrease.
            return null;
        }

        $value = $this->read($this->key($organizationId, $metric));

        return $value === null
            ? null
            : new QuotaUsage($metric, $value, null, QuotaUsage::SOURCE_CACHE);
    }

    /**
     * The exact value, from PostgreSQL, and refresh the cache with it.
     *
     * THE ONLY METHOD `QuotaGate` MAY ADMIT AGAINST. The refresh is best-effort and its failure is
     * not the caller's problem: the number returned came from the primary either way.
     */
    public function authoritative(
        string $organizationId,
        QuotaMetric $metric,
        ?CarbonImmutable $at = null,
    ): QuotaUsage {
        $at ??= CarbonImmutable::now('UTC');

        $used = $metric->isLedgerBacked()
            ? $this->ledger->consumed($organizationId, $metric, $at->toDateTimeImmutable())
            : $this->countLive($organizationId, $metric);

        if ($metric->isLedgerBacked()) {
            // WRITE WITH AN EXPLICIT TTL, never a bare SET on an existing key: `SET` DISCARDS any
            // prior TTL unless KEEPTTL is passed, which is how a cache entry becomes immortal
            // (`valkey-keyspaces`). Laravel's `put()` always sends the TTL, so this is the safe
            // form — it is stated because the unsafe form is `forever()`, one method along.
            $this->write($this->key($organizationId, $metric, $at), $used);
        }

        return new QuotaUsage($metric, $used, null, QuotaUsage::SOURCE_DATABASE);
    }

    /**
     * Move the cached lower bound by `$delta` after a ledger row was written.
     *
     * BEST-EFFORT AND DELIBERATELY NOT LOAD-BEARING. It keeps the counter close to the truth between
     * refreshes so the refusal shortcut fires promptly. If it is lost — the increment races an
     * eviction, the instance is down, the key expired between the read and this call — the counter
     * is merely lower, which is the direction this whole family is allowed to be wrong in.
     *
     * IT DOES NOT CREATE THE KEY. `Cache::increment()` on a missing Redis key is `INCRBY`, which
     * creates it WITH NO TTL — an immortal counter that never re-reads PostgreSQL and therefore
     * never notices a new period. So a miss here is a no-op and the next `authoritative()` call is
     * what seeds it, with a TTL.
     */
    public function bump(
        string $organizationId,
        QuotaMetric $metric,
        int $delta,
        ?CarbonImmutable $at = null,
    ): void {
        if ($this->degraded || ! $metric->isLedgerBacked() || $delta === 0) {
            return;
        }

        $key = $this->key($organizationId, $metric, $at ?? CarbonImmutable::now('UTC'));

        try {
            $store = $this->cache->store(self::STORE);

            // The read is what makes this a no-op on a miss. It is not a check-then-act race worth
            // closing: losing an increment leaves the counter low, which is the safe direction, and
            // the alternative (an unconditional INCRBY) creates the immortal key described above.
            if ($store->get($key) === null) {
                return;
            }

            $delta > 0
                ? $store->increment($key, $delta)
                : $store->decrement($key, -$delta);
        } catch (Throwable $failure) {
            $this->degraded('bump', $organizationId, $metric, $failure);
        }
    }

    /**
     * Drop one metric's cached value — used by `kb:rollup-usage` when it finds a disagreement, and
     * by any path that has just changed the truth by a route this class cannot see.
     *
     * FORGET RATHER THAN RE-WRITE. Writing a corrected value would race whatever else is bumping the
     * key; deleting it makes the next read go to PostgreSQL, which is the answer anyway.
     */
    public function forget(string $organizationId, QuotaMetric $metric, ?CarbonImmutable $at = null): void
    {
        if ($this->degraded) {
            return;
        }

        try {
            $this->cache->store(self::STORE)->forget(
                $this->key($organizationId, $metric, $at ?? CarbonImmutable::now('UTC')),
            );
        } catch (Throwable $failure) {
            $this->degraded('forget', $organizationId, $metric, $failure);
        }
    }

    /**
     * `quota:{org_id}:{period}:{metric}` — the catalog's pattern, built in ONE place.
     *
     * FAMILY FIRST, ORG SECOND. The ordering is not cosmetic: Valkey ACL key patterns match on
     * prefixes, so family-first is what lets a service be granted `~quota:*` without also being
     * granted the queues, and it makes an org-wide purge a deterministic walk of known family
     * prefixes instead of a `SCAN MATCH` that can miss a key written mid-iteration.
     *
     * NO SEGMENT IS DERIVED FROM A USER-CHOSEN STRING. Two ULIDs and two closed vocabularies — an
     * organization slug or a bot name here would be a key that is unique per organization and not
     * globally, which is WSO2 CVE-2025-13475 exactly (`valkey-keyspaces`, and the same rule
     * `kb-tenancy-isolation` states).
     */
    private function key(
        string $organizationId,
        QuotaMetric $metric,
        ?CarbonImmutable $at = null,
    ): string {
        $period = $metric->period($at ?? CarbonImmutable::now('UTC'));

        return "quota:{$organizationId}:{$period}:{$metric->value}";
    }

    /**
     * `count(*)` for a point-in-time metric, org-scoped and explicit.
     *
     * `OrganizationUser` deliberately carries no `#[ScopedBy]` (it is read BEFORE a tenant context
     * exists, by the middleware whose job is to establish one), so the `where` below is the ONLY
     * tenancy on that half — there is no backstop under it. `Bot` does carry the scope, and the
     * explicit predicate is still the mechanism.
     */
    private function countLive(string $organizationId, QuotaMetric $metric): int
    {
        return match ($metric) {
            QuotaMetric::Bots => Bot::query()
                ->where('organization_id', '=', $organizationId)
                ->count(),
            // MEMBERSHIPS, NOT USERS. One person in two organizations occupies a seat in each, and
            // the seat is what is metered. Counting DISTINCT users would also be a different number
            // from the one the members screen shows, with nothing saying which is the quota.
            QuotaMetric::Users => OrganizationUser::query()
                ->where('organization_id', '=', $organizationId)
                ->count(),
            // Unreachable: the caller checked `isLedgerBacked()`. Kept exhaustive so a fifth metric
            // is a compile-time decision rather than a silent zero.
            QuotaMetric::StorageBytes, QuotaMetric::MonthlyTokens => 0,
        };
    }

    private function read(string $key): ?int
    {
        if ($this->degraded) {
            return null;
        }

        try {
            $value = $this->cache->store(self::STORE)->get($key);

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable $failure) {
            $this->degraded('read', null, null, $failure);

            return null;
        }
    }

    private function write(string $key, int $value): void
    {
        if ($this->degraded) {
            return;
        }

        try {
            $this->cache->store(self::STORE)->put($key, $value, self::TTL_SECONDS);
        } catch (Throwable $failure) {
            $this->degraded('write', null, null, $failure);
        }
    }

    /**
     * One log line per degraded cache operation, at WARNING.
     *
     * NOT `error`, because nothing is broken from the caller's point of view — the number was still
     * correct, it just cost a query. NOT silent either: a Valkey that has been unreachable for a
     * week is invisible otherwise, since every read simply gets slower and every answer stays right.
     *
     * `error_class` is on the line so the existing error panels see it. It is `internal_dependency`
     * — a dependency of ours is briefly unavailable — and NOT `tenant_quota`, which would put a
     * cache outage in the same bucket as an organization hitting its plan ceiling.
     *
     * NO KEY AND NO VALUE ON THE LINE. The key carries an organization id, which is fine, but the
     * habit of logging cache keys is how a family whose key later includes a user-chosen string ends
     * up in Loki. The organization is logged as its own field, where the log contract expects it.
     */
    private function degraded(
        string $operation,
        ?string $organizationId,
        ?QuotaMetric $metric,
        Throwable $failure,
    ): void {
        // THE LATCH IS SET BEFORE THE LOG LINE, so the line that reports the outage is also the LAST
        // one this instance writes about it. See the property's docblock: an identical warning per
        // metric per organization is not more information, it is the same information at a volume
        // that hides the next thing.
        $this->degraded = true;

        $this->log->warning(
            "Quota counter cache unavailable during `{$operation}`; the quota decision fell back to "
            .'PostgreSQL, which is the source of truth. This process will not attempt the cache '
            .'again — every quota read from here is an aggregate query. One line per process, not '
            .'one per metric.',
            [
                'error_class' => 'internal_dependency',
                'org_id' => $organizationId,
                'outcome' => 'degraded',
                'quota_metric' => $metric?->value,
                'exception' => $failure,
            ],
        );
    }
}

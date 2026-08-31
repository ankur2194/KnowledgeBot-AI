<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Models\Bot;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * `bots.rate_limit_per_minute` and `bots.rate_limit_per_day`, ENFORCED.
 *
 * ═══ WHAT THIS CLOSES ═══════════════════════════════════════════════════════════════════════
 *
 * Both columns have existed since 2026_08_19_001400 with a CHECK constraint, a cast, a FormRequest
 * rule and an admin form — and NOTHING READ THEM. An operator could set a per-minute limit, see it
 * persisted, see it rendered back, and have it enforce nothing. That is the worst shape a security
 * control can have: visibly configured, invisibly absent.
 *
 * ═══ SLIDING WINDOW, NOT A FIXED ONE, AND NOT LARAVEL'S `RateLimiter` ═══════════════════════
 *
 * `valkey-keyspaces` is explicit: *"Not a fixed window: it lets 2× the limit through across a
 * boundary, which is exactly how a burst-shaped abuser gets by. Laravel's built-in `throttle` is
 * fixed-window and stays only on coarse admin routes."* `AppServiceProvider::defineRateLimiters()`
 * says the same thing from the other side — the framework limiter is acceptable on the admin console
 * and not on a metered chat surface, because there the 2× is the bill.
 *
 * The approximation is a SLIDING-WINDOW COUNTER: two adjacent fixed buckets, with the previous one
 * weighted by the fraction of it still inside the window.
 *
 *     estimate = previous × (1 − elapsedFraction) + current
 *
 * Its error is small and, for the burst-at-boundary case fixed windows get wrong, conservative.
 *
 * ═══ ONE `EVAL`, TWO WINDOWS, AND THE CHECK-THEN-COMMIT SPLIT INSIDE IT ═════════════════════
 *
 * Both windows are decided in ONE script, which is what makes the composite decision atomic — and
 * the script CHECKS EVERY WINDOW BEFORE IT INCREMENTS ANY. That two-pass shape is the whole reason
 * the script is not two calls: incrementing the minute bucket and then discovering the day bucket is
 * full charges the caller for a request that was refused, so an organization that has exhausted its
 * daily allowance would keep burning its minute allowance and the per-minute number would be a lie
 * for the rest of the day.
 *
 * `INCRBY` AND `EXPIRE` ARE TWO COMMANDS AND THAT IS SAFE **HERE ONLY** because the whole script is
 * atomic. Outside a script the split is a real bug: die in between and the counter is immortal and
 * that subject is banned forever (`valkey-keyspaces`, which names this family as the one on the
 * "must be one command" side of that rule).
 *
 * ═══ WHY `coordination` AND NOT THE CACHE STORE ════════════════════════════════════════════
 *
 * `rl:` is a `valkey-core` family. `config/database.php` names the `coordination` connection as
 * holding "locks, fences, idempotency records, replay nonces, breakers, rate-limit counters —
 * everything shared with the data plane and everything that fails OPEN when it disappears." An
 * evicted rate-limit counter reads as "no requests yet", so this family may never sit on the LRU
 * instance beside the quota counters, which may.
 *
 * ═══ A VALKEY OUTAGE FAILS OPEN HERE, DELIBERATELY, AND IT IS THE ONLY PLACE IN THIS CHANGE
 *     THAT DOES ═══════════════════════════════════════════════════════════════════════════
 *
 * This class does NOT catch. If `coordination` is unreachable the exception propagates and the
 * request 503s, which is failing CLOSED. That is stated explicitly because the neighbouring class
 * — `QuotaCounters` — swallows its cache failures, and the difference is not an inconsistency:
 *
 *   * A QUOTA counter failure has a correct fallback that costs a query. Falling back is strictly
 *     better than refusing.
 *   * A RATE-LIMIT counter failure has NO fallback. There is no PostgreSQL table holding
 *     requests-per-minute, so "carry on without the limiter" is the only alternative to refusing —
 *     and that is a bot with no rate limit at exactly the moment the platform is unwell.
 *
 * ═══ THE SUBJECT IS THE BOT, WHICH IS THE `bot` SCOPE AND ONLY THAT ════════════════════════
 *
 * `kb-security-baseline` §18.5 composes FOUR scopes — bot, origin, session, ip — and
 * `laravel-sanctum-auth` owns the other three and their `by()` composition. Those three key on
 * values that only exist once a public chat request is being authenticated, which is Phase 4's
 * surface. This class enforces the one scope whose limit is a COLUMN ON `bots`, and it is written so
 * the other three are added as more `(key, limit, window)` triples rather than as a second script.
 */
final readonly class BotRateLimiter
{
    /**
     * The two-pass sliding-window script.
     *
     * KEYS: `2n` — for each window, the current bucket then the previous bucket.
     * ARGV: `cost`, then per window `limit`, `ttl`, `weightMicros` (the elapsed fraction of the
     *       current bucket, scaled by 1e6 because Lua's number formatting of a bare float through
     *       the protocol is not something to depend on).
     *
     * Returns `{allowed, refusedWindowIndex}` — a Lua table, which phpredis surfaces as a PHP list.
     */
    private const SCRIPT = <<<'LUA'
        local cost = tonumber(ARGV[1])
        local windows = #KEYS / 2

        for i = 1, windows do
            local limit  = tonumber(ARGV[(i - 1) * 3 + 2])
            local weight = tonumber(ARGV[(i - 1) * 3 + 4]) / 1000000
            local cur    = tonumber(redis.call('GET', KEYS[(i - 1) * 2 + 1]) or '0')
            local prev   = tonumber(redis.call('GET', KEYS[(i - 1) * 2 + 2]) or '0')
            local estimate = prev * (1 - weight) + cur

            if estimate + cost > limit then
                return {0, i}
            end
        end

        for i = 1, windows do
            local key = KEYS[(i - 1) * 2 + 1]
            local ttl = tonumber(ARGV[(i - 1) * 3 + 3])
            local value = redis.call('INCRBY', key, cost)

            if value == cost then
                redis.call('EXPIRE', key, ttl)
            end
        end

        return {1, 0}
    LUA;

    public function __construct(private RedisFactory $redis) {}

    /**
     * Charge one request against the bot's configured windows.
     *
     * A bot with BOTH columns null is unlimited and this is a no-op that touches no key — the same
     * shape `QuotaLimits` uses, and for the same reason: an unconfigured limit must cost nothing,
     * not merely allow everything.
     *
     * @param  int  $cost  units to charge. 1 for a chat turn. Present so a future surface can charge
     *                     a batch as one call rather than looping, which would make the composite
     *                     decision non-atomic again.
     * @param  ChatRateLimitScopes|null  $scopes  THE OTHER THREE SCOPES OF §18.5 — origin, session
     *                                            and ip — supplied by the public chat surface and absent everywhere else. When
     *                                            present they are appended as three more `(key, limit, window)` triples to the
     *                                            SAME script call, which is what makes the four-scope decision atomic and one
     *                                            round trip; the class docblock says this is how the file was written to grow, and
     *                                            this is that growth rather than a second limiter.
     *
     *              Their limits are PLATFORM configuration (`config/kb.php`), not columns, because
     *              they are not the tenant's to set: a per-origin limit an operator could raise is
     *              not a defence against that operator's own site. Their breaches come back with
     *              `scope` set, and the caller renders them 429 rather than 403 — see
     *              `QuotaGate::refuseRateLimit()`.
     */
    public function charge(
        Bot $bot,
        int $cost = 1,
        ?CarbonImmutable $at = null,
        ?ChatRateLimitScopes $scopes = null,
    ): RateLimitDecision {
        $at ??= CarbonImmutable::now('UTC');
        $timestamp = $at->getTimestamp();

        /** @var list<array{0: string, 1: string, 2: int, 3: int, 4: string}> $windows */
        $windows = [];

        if ($bot->rate_limit_per_minute !== null) {
            $windows[] = [RateLimitDecision::SCOPE_BOT, 'minute', 60, $bot->rate_limit_per_minute, (string) $bot->id];
        }

        if ($bot->rate_limit_per_day !== null) {
            // 86400 is a fixed 24-hour bucket aligned to the UTC epoch, which for a UTC-only
            // application IS the calendar day. `->startOfDay()` would be the same instant and would
            // introduce a timezone the rest of the key does not have.
            $windows[] = [RateLimitDecision::SCOPE_BOT, 'day', 86_400, $bot->rate_limit_per_day, (string) $bot->id];
        }

        foreach ($this->platformWindows($scopes) as $window) {
            $windows[] = $window;
        }

        if ($windows === []) {
            return RateLimitDecision::allowed();
        }

        $keys = [];
        $args = [$cost];

        foreach ($windows as [$scope, $name, $seconds, $limit, $subject]) {
            $bucket = intdiv($timestamp, $seconds);
            $elapsed = $timestamp % $seconds;

            $keys[] = $this->key($bot, $scope, $subject, $seconds, $bucket);
            $keys[] = $this->key($bot, $scope, $subject, $seconds, $bucket - 1);

            $args[] = $limit;
            // TWICE THE WINDOW, from the catalog: the previous bucket must survive long enough to
            // be weighted into the current one, and one window of TTL would drop it exactly when it
            // is still half inside the window.
            $args[] = $seconds * 2;
            $args[] = (int) round(($elapsed / $seconds) * 1_000_000);
        }

        // ── THE `eval` CALL SHAPE, AND WHY IT IS SPELLED THIS WAY ───────────────────────────
        //
        // `Illuminate\Redis\Connections\PhpRedisConnection::eval($script, $numberOfKeys,
        // ...$arguments)` re-orders those into phpredis' own `eval($script, $args, $numkeys)`, so
        // the KEYS AND THE ARGV ARE ONE FLAT VARIADIC LIST with the key count telling the server
        // where the boundary is. Getting the count wrong does not raise: Lua simply sees fewer KEYS
        // and more ARGV, and the script reads a limit out of a key name.
        //
        // `$keys` and `$args` are both built by appending, so both are already lists and the
        // variadic spread preserves their order. There is no `array_values()` normalisation here on
        // purpose: PHPStan proves they are lists, so the call would be a no-op it reports as one —
        // and a future edit that gives either one string keys becomes a type error at this line
        // rather than a silent reordering of the arguments.
        $arguments = array_merge($keys, $args);

        // THE SUPPRESSION AND EXACTLY WHAT IT IS FOR. Larastan resolves
        // `Illuminate\Contracts\Redis\Factory::connection()` to `Illuminate\Redis\Connections\
        // Connection`, whose `__call` forwards to the phpredis client — so the analyser matches
        // `Redis::eval($script, $args, $numkeys)` (phpredis' own three-argument order) rather than
        // `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)`, which is the method
        // actually invoked and which re-orders them for phpredis itself. Two of the three reported
        // "errors" are that re-ordering being read backwards.
        //
        // Verified by reading the framework source rather than assumed:
        // vendor/laravel/framework/src/Illuminate/Redis/Connections/PhpRedisConnection.php declares
        // `public function eval($script, $numberOfKeys, ...$arguments)` and returns
        // `$this->command('eval', [$script, $arguments, $numberOfKeys])`.
        //
        // Scoped to this one line, per phpstan.neon's rule about suppressions — and the connection
        // is resolved on its own line first, because the directive applies to the line that FOLLOWS
        // it and a two-line fluent call puts the reported expression on the second one.
        $connection = $this->redis->connection('coordination');

        // @phpstan-ignore-next-line
        $result = $connection->eval(self::SCRIPT, count($keys), ...$arguments);

        // A malformed reply is treated as a REFUSAL, not as an allowance. This is the one branch
        // where "we could not tell" has to pick a side, and the side is the safe one: see the class
        // docblock on why this limiter has no fallback.
        if (! is_array($result) || ! isset($result[0])) {
            return RateLimitDecision::refused('unknown', 0, 60);
        }

        if ((int) $result[0] === 1) {
            return RateLimitDecision::allowed();
        }

        $index = max(1, (int) ($result[1] ?? 1)) - 1;
        [$scope, $name, $seconds, $limit] = $windows[$index] ?? $windows[0];

        return RateLimitDecision::refused(
            $name,
            $limit,
            // Time until the current bucket rolls over: a FLOOR, since traffic in the meantime
            // pushes it out further. RateLimitDecision's docblock says so on the field.
            $seconds - ($timestamp % $seconds),
            $scope,
        );
    }

    /**
     * The three platform windows, or none when the caller supplied no scopes.
     *
     * ONE WINDOW EACH, per minute, and deliberately not a per-day twin. The bot's own limits are a
     * SPEND ceiling and a day is the unit an operator thinks in; these three are an ABUSE control
     * and the thing they defend against is a burst. A per-day platform limit would also be
     * indistinguishable, from the caller's side, from a tenant quota — which is the one thing the
     * scope field on the decision exists to keep apart.
     *
     * A limit of 0 or less means UNCONFIGURED and produces no window at all, which is the same shape
     * `charge()` already uses for a bot with both columns null: an unconfigured limit costs nothing,
     * rather than allowing everything at the price of two Valkey reads per request.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    private function platformWindows(?ChatRateLimitScopes $scopes): array
    {
        if ($scopes === null) {
            return [];
        }

        $windows = [];

        foreach ([
            ['origin', (int) config('kb.chat_rate_limits.origin_per_minute'), $scopes->originSubject],
            ['session', (int) config('kb.chat_rate_limits.session_per_minute'), $scopes->sessionSubject],
            ['ip', (int) config('kb.chat_rate_limits.ip_per_minute'), $scopes->ipSubject],
        ] as [$scope, $limit, $subject]) {
            if ($limit > 0) {
                $windows[] = [$scope, 'minute', 60, $limit, $subject];
            }
        }

        return $windows;
    }

    /**
     * `rl:{org_id}:{bot_id}:{scope}:{subject}:{window}` — the catalog's pattern, unmodified.
     *
     * ON THE `bot` SCOPE THE SUBJECT IS THE BOT ID, so the third and fourth segments repeat. That
     * looks redundant and is the price of one uniform key builder across four scopes: for `origin`,
     * `session` and `ip` the subject is a different value under the same bot, and a family whose
     * segment COUNT varies by scope cannot be matched by an ACL pattern or walked by an org purge.
     *
     * NO SEGMENT IS DERIVED FROM A USER-CHOSEN STRING. Two ULIDs, a closed scope token, two integers
     * — and for the three platform scopes a 16-hex-character HMAC built by `ChatRateLimitScopes`,
     * never the raw origin, session id or address. A bot slug here would be unique per organization
     * and not globally, which is WSO2 CVE-2025-13475 exactly; a raw IP would put a directly
     * identifying value into a keyspace we may dump during an incident.
     */
    private function key(Bot $bot, string $scope, string $subject, int $seconds, int $bucket): string
    {
        return "rl:{$bot->organization_id}:{$bot->id}:{$scope}:{$subject}:{$seconds}:{$bucket}";
    }
}

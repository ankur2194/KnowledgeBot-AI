<?php

declare(strict_types=1);

namespace App\Services\Quotas;

/**
 * The three PLATFORM scopes of `kb-security-baseline` §18.5, beside the bot scope the bot's own row
 * already carries.
 *
 * ═══ WHY ALL FOUR, AND WHY NONE OF THEM WORKS ALONE ════════════════════════════════════════
 *
 *   IP ONLY       punishes one NAT'd corporate network for one abuser, and is defeated by any
 *                 residential proxy pool.
 *   SESSION ONLY  is defeated by discarding the session — the mint is one unauthenticated POST.
 *   ORIGIN ONLY   is defeated by hosting the page somewhere else.
 *   BOT ONLY      is the tenant's own ceiling, not a platform defence: it is a number the operator
 *                 chose, and one abuser inside a generous limit is still an abuser.
 *
 * They are checked TOGETHER, in one `EVALSHA`, so the composite decision is atomic and costs one
 * round trip. Four separate calls would let a caller sit just under each individual limit while
 * being over the combination, and would charge the earlier scopes for a request the later ones
 * refused.
 *
 * ═══ EVERY SUBJECT IS AN HMAC, AND FOR THE `ip` SCOPE THAT IS A PRIVACY CONTROL ════════════
 *
 * `valkey-keyspaces` specifies the subject as a 16-hex-character truncated HMAC of the raw value
 * keyed on an application secret. For `origin` and `session` it is tidiness — neither is secret. For
 * `ip` it keeps a directly identifying value out of a keyspace we may dump during an incident, and
 * it does NOT reduce cardinality: nothing does except the /64 aggregation below.
 *
 * ═══ IPv6 IS AGGREGATED TO THE /64 ═════════════════════════════════════════════════════════
 *
 * A single /64 allocation is 2^64 distinct addresses, each of which would be a key with a
 * two-window TTL. `rl:` is the one family in the catalog whose cardinality an ATTACKER sets, so an
 * un-aggregated IPv6 subject is a memory exhaustion of the non-evicting instance — at which point
 * every enqueue in the system starts failing, because `noeviction` means writes fail at the ceiling.
 */
final readonly class ChatRateLimitScopes
{
    private function __construct(
        public string $originSubject,
        public string $sessionSubject,
        public string $ipSubject,
    ) {}

    /**
     * @param  string  $origin  the embedder origin proved at mint. Not the request's own `Origin`,
     *                          which for the widget is always our iframe's and would collapse every
     *                          customer onto one bucket.
     * @param  string  $sessionId  the chat session's derived id — already a one-way function of the
     *                             bearer, and hashed again here only so every subject in the family
     *                             has one shape.
     * @param  string|null  $ip  `$request->ip()`, which is `X-Forwarded-For` behind Traefik. Null —
     *                           no resolvable peer — is bucketed under a fixed subject rather than
     *                           skipped: skipping would make "send no address" the way past the
     *                           limiter.
     */
    public static function from(string $origin, string $sessionId, ?string $ip): self
    {
        return new self(
            self::subject('origin', $origin),
            self::subject('session', $sessionId),
            self::subject('ip', self::normalizeIp($ip)),
        );
    }

    /**
     * 16 hex characters of an HMAC keyed on the application key, DOMAIN-SEPARATED by scope.
     *
     * The scope prefix is inside the HMAC input, not only in the key name: without it the same
     * string appearing as two different subjects — a session id that happened to equal an origin —
     * would produce one bucket, and the two scopes would silently charge each other.
     */
    private static function subject(string $scope, string $value): string
    {
        return substr(hash_hmac('sha256', $scope."\x1f".$value, (string) config('app.key')), 0, 16);
    }

    /**
     * The address, aggregated to the /64 when it is IPv6.
     *
     * A LITERAL PREFIX AND NOT A PARSED NETWORK: `inet_pton` gives 16 bytes and the first 8 are the
     * /64, which is what needs bucketing. IPv4 is used whole — a /24 aggregation there would punish
     * a whole ISP block for one host, which is the failure the composite exists to avoid.
     */
    private static function normalizeIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            // A FIXED SUBJECT AND NOT A SKIP. Everything with no resolvable address shares one
            // bucket, which is exactly what "we cannot tell you apart" should cost.
            return 'unknown';
        }

        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16) {
            return bin2hex(substr($packed, 0, 8)).'::/64';
        }

        return $ip;
    }
}

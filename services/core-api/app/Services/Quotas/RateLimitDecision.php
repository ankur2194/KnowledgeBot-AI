<?php

declare(strict_types=1);

namespace App\Services\Quotas;

/**
 * The composite verdict of one rate-limit evaluation, plus WHICH window refused.
 *
 * The window name travels with the verdict because the operator's remedy differs: a per-minute
 * breach is a burst and clears in seconds, a per-day breach clears at midnight UTC and usually means
 * the limit is wrong for the traffic. A single boolean makes those two the same support ticket.
 *
 * `retryAfterSeconds` is a FLOOR, not a promise — it is the time until the refusing window's counter
 * has decayed below the limit ASSUMING NO FURTHER TRAFFIC, and further traffic is the normal case.
 */
final readonly class RateLimitDecision
{
    /**
     * The `bot` scope, spelled once.
     *
     * IT IS THE ONE SCOPE WHOSE BREACH IS NOT A 429, and the caller branches on this exact value —
     * `bots.rate_limit_per_minute` is a number the ORGANIZATION set on its own bot, so reaching it is
     * `tenant_quota` (403, non-retryable). Every other scope is the PLATFORM speaking and is
     * `rate_limit` (429 + `Retry-After`). `QuotaGate::refuseRateLimit()` carries the full argument.
     */
    public const SCOPE_BOT = 'bot';

    private function __construct(
        public bool $allowed,
        public ?string $window,
        public ?int $limit,
        public ?int $retryAfterSeconds,
        /**
         * WHICH SCOPE refused — `bot`, `origin`, `session` or `ip`.
         *
         * It travels beside the window because the two answer different questions and the caller
         * needs both: the SCOPE decides the error class and therefore the status, and the WINDOW
         * decides what the operator is told to do about it. Defaulted to `bot` so the pre-existing
         * single-scope call sites are unchanged.
         */
        public string $scope = self::SCOPE_BOT,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null, null);
    }

    public static function refused(
        string $window,
        int $limit,
        int $retryAfterSeconds,
        string $scope = self::SCOPE_BOT,
    ): self {
        return new self(false, $window, $limit, $retryAfterSeconds, $scope);
    }
}

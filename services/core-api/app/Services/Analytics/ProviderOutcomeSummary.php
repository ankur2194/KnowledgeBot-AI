<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * The provider error rate and the fallback rate, with the counts each is computed from.
 *
 * ── THE COUNTS SHIP BESIDE THE RATES, DELIBERATELY ───────────────────────────────────────────
 *
 * A 100% error rate over two calls and a 100% error rate over two hundred thousand are the same
 * number and different incidents. Publishing only the ratio makes the first one page somebody at
 * 3am and makes the second indistinguishable from it.
 *
 * ── WHAT IS IN THE ERROR-RATE DENOMINATOR, AND WHAT IS DELIBERATELY OUT ──────────────────────
 *
 * `succeeded + failed`. `cancelled` is EXCLUDED FROM BOTH sides and `pending` is excluded because it
 * has not finished. `App\Enums\MessageStatus` already records the reason in its own terms: counting
 * a closed laptop lid as a provider error makes the error-rate dashboard unusable, and it does it in
 * the direction that hides real outages behind ordinary user behaviour.
 *
 * ── THE FALLBACK RATE'S DENOMINATOR IS TURNS, AND IT IS NOT `count(*)` ───────────────────────
 *
 * One turn writes exactly ONE primary attempt (`fallback_metadata = '{}'`) plus zero or more
 * fallback attempts. So:
 *
 *     fallbackRate = fallbackAttempts / primaryAttempts
 *
 * and `primaryAttempts` IS the turn count, without joining `messages` — which matters because
 * `provider_calls.message_id` is `ON DELETE SET NULL` and nulls out when retention sweeps the
 * thread. A denominator built from `count(DISTINCT message_id)` would therefore SHRINK as retention
 * ran, and the fallback rate for a closed month would climb every night with no fallback having
 * happened. `organization_id` and `bot_id` never null, and `fallback_metadata` never changes.
 *
 * The rate can legitimately exceed 1.0: a turn that fell back twice writes two fallback attempts. It
 * is not clamped, because clamping would hide exactly the configuration that is worth looking at.
 */
final readonly class ProviderOutcomeSummary
{
    public function __construct(
        public int $succeeded,
        public int $failed,
        public int $primaryAttempts,
        public int $fallbackAttempts,
    ) {}

    /**
     * Failures as a fraction of finished attempts, or null when nothing finished.
     *
     * NULL AND NOT 0.0 for an empty window, for the reason `LatencySummary` gives: "no calls" and
     * "no failures" are different facts, and a dashboard that renders 0% for an organization that
     * made no calls is reporting health it did not measure.
     */
    public function errorRate(): ?float
    {
        $finished = $this->succeeded + $this->failed;

        return $finished === 0 ? null : $this->failed / $finished;
    }

    /**
     * Fallback attempts per turn, or null when there were no turns.
     */
    public function fallbackRate(): ?float
    {
        return $this->primaryAttempts === 0
            ? null
            : $this->fallbackAttempts / $this->primaryAttempts;
    }
}

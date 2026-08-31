<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * First-token and total latency, as percentiles rather than as a mean.
 *
 * ── PERCENTILES AND NOT AN AVERAGE, AND THIS IS NOT A STYLE PREFERENCE ───────────────────────
 *
 * A mean latency over a population containing one 55-second timeout and ninety-nine 900 ms answers
 * reads as 1.4 s, which is a number no user experienced. p50 and p95 are what a person feels and
 * what an SLO is written against.
 *
 * `percentile_disc` (discrete) rather than `percentile_cont` (interpolated): the value returned is
 * one an actual request measured, so it can be found in the log. An interpolated 1,247.5 ms belongs
 * to no request and cannot be investigated.
 *
 * ── EVERY FIELD IS NULLABLE, AND NULL MEANS "NOTHING TO MEASURE" ─────────────────────────────
 *
 * Not zero. `provider_calls.first_token_latency_ms` is NULL on a non-streaming call and on one that
 * never produced a token, and a window with no successful streaming call has no p50 at all.
 * Rendering that as 0 would put "0 ms to first token" on a dashboard for an outage.
 */
final readonly class LatencySummary
{
    public function __construct(
        public ?int $firstTokenP50Ms,
        public ?int $firstTokenP95Ms,
        public ?int $totalP50Ms,
        public ?int $totalP95Ms,
    ) {}
}

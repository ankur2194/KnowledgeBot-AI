<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The thumb (docs/11 §16.6, docs/04 §8.23 "positive and negative feedback rate").
 *
 * ── TWO VALUES AND NOT AN INTEGER, WHICH LOOKS LIKE A PREFERENCE AND IS NOT ─────────────────
 *
 * Stored as `+1` / `-1` this column would accept `0`, `7` and `-3`, and the first thing to write
 * one would be a client sending a five-star scale somebody added to a settings screen. The
 * analytics then divides by a denominator that means two different things across two date ranges,
 * silently. A closed two-value vocabulary with a CHECK generated from it cannot do that.
 *
 * WIDENING THIS IS A MIGRATION AND SHOULD BE. A five-point scale is a product decision with a
 * backfill question attached — what does an existing thumb become — and making it a schema change
 * is what forces that question to be answered rather than assumed.
 */
enum FeedbackRating: string
{
    case Positive = 'positive';

    case Negative = 'negative';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

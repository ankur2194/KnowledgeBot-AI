<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * The thumbs, split.
 *
 * TWO COUNTS AND NOT A RATIO, because the ratio is the derived thing and the counts are what a
 * reviewer acts on: `1 positive, 0 negative` and `40,000 positive, 0 negative` are both "100%" and
 * only one of them is evidence.
 *
 * NO `comment` ANYWHERE NEAR THIS OBJECT. `feedback.comment` is free text from a stranger — the
 * migration says so — and an aggregate is not where untrusted prose belongs. Reading comments is a
 * separate, paginated, per-message surface with its own authorization.
 */
final readonly class FeedbackSummary
{
    public function __construct(
        public int $positive,
        public int $negative,
    ) {}
}

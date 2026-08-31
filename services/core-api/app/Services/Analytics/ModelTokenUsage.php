<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * One `(provider, model)` row of the token-and-cost tile.
 *
 * ── INPUT AND OUTPUT STAY APART BECAUSE THEY ARE PRICED APART ────────────────────────────────
 *
 * A single `tokens` total cannot be costed: every vendor here prices output at a multiple of input,
 * and the multiple differs per model. Publishing one number would make the estimate a function of
 * the input/output mix, which nobody would notice and nobody could reproduce.
 *
 * OUTPUT ALREADY INCLUDES REASONING TOKENS. That is a property of what was WRITTEN to the ledger —
 * `App\Services\Usage\UsageArithmetic` is the one place the sum is spelled — and not something this
 * object does. An aggregate over bare `provider_calls.output_tokens` under-reports a reasoning turn
 * silently and plausibly, which is the defect that class exists to name.
 *
 * ── THE COST IS ESTIMATED, IS NULLABLE, AND CARRIES ITS CURRENCY ─────────────────────────────
 *
 * Nullable because an unpriced catalogue row yields tokens with no cost — a real state, and a
 * different one from a cost of zero. The currency travels with the number because SUMMING TWO
 * CURRENCIES IS A SILENT WRONG ANSWER (the `provider_calls` migration's words): an organization with
 * a USD-priced connection and a EUR-priced one produces a total that is neither, and nothing raises.
 * That is also why there is no `totalCost()` on `AnalyticsSnapshot` — there is no rate to convert
 * with, and inventing one is the kind of thing that ends up in an invoice.
 */
final readonly class ModelTokenUsage
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $estimatedCost,
        public ?string $currency,
    ) {}
}

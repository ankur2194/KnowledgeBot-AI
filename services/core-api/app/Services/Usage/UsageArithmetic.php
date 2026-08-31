<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\ProviderCall;

/**
 * THE BILLING ARITHMETIC, WRITTEN DOWN ONCE.
 *
 * `provider_calls` carries FIVE DISJOINT token counters, and two of the five are the reason this
 * class exists rather than being two `+` signs at each call site.
 *
 * ═══ INPUT: `input_tokens` IS ALREADY THE TOTAL ══════════════════════════════════════════════
 *
 * `provider_calls.input_tokens` is the NORMALIZED total input — cache reads and cache writes
 * INCLUDED — and the two cache columns are a BREAKDOWN of it, never an addition to it. The migration
 * states that as the column's contract and `provider_calls_cache_within_input` is the one half of it
 * a constraint can check (`coalesce(cache_read, 0) + coalesce(cache_write, 0) <= input_tokens`).
 *
 * THE NORMALIZATION IS THE ADAPTER'S JOB AND IT IS NOT OPTIONAL: Anthropic's `input_tokens`
 * EXCLUDES cached tokens while every other provider here includes them, so the adapter adds them
 * before the row is written. **So the input side of a bill is `input_tokens` ALONE.** Writing
 * `input_tokens + cache_read_tokens + cache_write_tokens` — which reads as the obviously correct
 * sum of "input + cache read + cache write" — DOUBLE-COUNTS every cached token, and does it only on
 * the providers that cache, which is a bill that is wrong by a percentage that varies by vendor.
 *
 * `totalInputTokens()` exists anyway, and it exists as a NAME rather than as a bare column read, so
 * that the sentence above has somewhere to live and so a future provider whose adapter cannot
 * normalize has one place to be handled.
 *
 * ═══ OUTPUT: REASONING IS BILLED AT THE OUTPUT RATE AND IS A SEPARATE COLUMN ═════════════════
 *
 * `output_tokens` and `reasoning_tokens` are DISJOINT, and EVERY VENDOR THIS PLATFORM SPEAKS TO
 * BILLS REASONING AT THE OUTPUT RATE. An aggregate over bare `output_tokens` therefore
 * UNDER-REPORTS a reasoning turn — silently, plausibly, by a factor that depends on the model and
 * on the question, with a number that still looks like a token count and a total that still moves
 * in the right direction when traffic grows.
 *
 * That is the failure this class is named for. `SUM(output_tokens)` is not a bug any test catches
 * unless the test's fixture has a non-zero `reasoning_tokens`, and the default fixture's is 0.
 *
 * ═══ WHY THIS IS PHP AND SQL, IN TWO PLACES THAT MUST AGREE ═════════════════════════════════
 *
 * The ledger writer reads a `ProviderCall` object; the analytics aggregate sums in the database over
 * millions of rows and cannot pull them into PHP. So the arithmetic exists twice — here and as
 * :self::OUTPUT_SQL / :self::INPUT_SQL, which the repository interpolates. They are in ONE FILE, ten
 * lines apart, so that a reader changing one cannot avoid seeing the other, and
 * tests/Unit/UsageArithmeticTest.php asserts the two agree on the same fixture.
 */
final class UsageArithmetic
{
    /**
     * The SQL expression for one row's billable INPUT tokens.
     *
     * `coalesce(..., 0)` because NULL means "the provider told us nothing" — a stream that died
     * before its usage frame — which is a real outcome and is different from zero. In a SUM, NULL
     * rows are skipped rather than zeroed, so the coalesce changes nothing for `SUM()`; it is here
     * so the expression is also correct in a per-row projection, where the two differ.
     */
    public const INPUT_SQL = 'coalesce(input_tokens, 0)';

    /**
     * The SQL expression for one row's billable OUTPUT tokens — REASONING INCLUDED.
     *
     * This is the expression `SUM(output_tokens)` is silently wrong instead of.
     */
    public const OUTPUT_SQL = 'coalesce(output_tokens, 0) + coalesce(reasoning_tokens, 0)';

    /**
     * Billable input for one attempt: `input_tokens` ALONE, because it is already the total.
     *
     * See the class docblock for why adding the two cache columns to it is the tempting wrong
     * answer.
     */
    public static function totalInputTokens(ProviderCall $call): int
    {
        return $call->input_tokens ?? 0;
    }

    /**
     * Billable output for one attempt: output PLUS reasoning.
     */
    public static function totalOutputTokens(ProviderCall $call): int
    {
        return ($call->output_tokens ?? 0) + ($call->reasoning_tokens ?? 0);
    }

    /**
     * Everything a monthly token quota counts for one attempt.
     *
     * BOTH DIRECTIONS, and `App\Enums\QuotaMetric::MonthlyTokens` carries the argument: an
     * output-only quota would let one organization spend an unbounded amount of the platform's
     * provider budget on packed retrieval context while its meter barely moved.
     */
    public static function billableTokens(ProviderCall $call): int
    {
        return self::totalInputTokens($call) + self::totalOutputTokens($call);
    }
}

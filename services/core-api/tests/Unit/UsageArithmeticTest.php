<?php

declare(strict_types=1);

use App\Enums\ProviderCallStatus;
use App\Models\ProviderCall;
use App\Services\Usage\UsageArithmetic;

/*
|--------------------------------------------------------------------------
| The billing arithmetic — the two ways it is silently wrong
|--------------------------------------------------------------------------
|
| A Unit test: `UsageArithmetic` is four static methods over an unsaved model, so no container, no
| database and no request. The model is instantiated and its attributes are set directly, which
| opens no connection.
|
| BOTH ASSERTIONS BELOW FAIL AGAINST A PLAUSIBLE IMPLEMENTATION, WHICH IS THE ONLY REASON THEY EARN
| THEIR PLACE. A test over a fixture whose `reasoning_tokens` is 0 and whose cache columns are 0
| passes against `SUM(output_tokens)` and against `input + cache_read + cache_write` alike — and 0 is
| exactly what `ProviderCallFactory` sets, so the natural fixture proves nothing. Every fixture here
| carries a NON-ZERO value in the column the wrong implementation ignores or double-counts.
|
| THE SQL HALF IS ASSERTED AS TEXT, WHICH IS WEAKER THAN EVALUATING IT AND IS DELIBERATE. Running the
| expression needs PostgreSQL, which makes this a Feature test and buys one thing: proof that
| PostgreSQL parses it. tests/Feature/UsageLedgerTest.php gets that for free — it sums real rows
| through EloquentUsageEventRepository and EloquentAnalyticsRepository — so what is left for this
| file is the property no database can check: that the PHP and the SQL name the SAME COLUMNS.
*/

/**
 * One provider attempt, as an unsaved model with explicit attributes.
 *
 * NOT `ProviderCall::factory()->make()`: the factory requires four recycled parents and would drag
 * the container in. Attributes are set directly, which is also what the real writer does.
 */
function usageCall(
    ?int $input,
    ?int $cacheRead,
    ?int $cacheWrite,
    ?int $output,
    ?int $reasoning,
): ProviderCall {
    $call = new ProviderCall;
    $call->status = ProviderCallStatus::Succeeded;
    $call->input_tokens = $input;
    $call->cache_read_tokens = $cacheRead;
    $call->cache_write_tokens = $cacheWrite;
    $call->output_tokens = $output;
    $call->reasoning_tokens = $reasoning;

    return $call;
}

it('counts input tokens ONCE, because input_tokens already includes the cache breakdown', function (): void {
    // THE FIRST SILENT WRONG ANSWER. `input_tokens` is the NORMALIZED total — cache reads and writes
    // INCLUDED — and `provider_calls_cache_within_input` enforces `cache_read + cache_write <=
    // input_tokens` in the database. So the expression that reads as obviously correct,
    // `input + cache_read + cache_write`, DOUBLE-COUNTS every cached token, and it does so only on
    // the vendors that cache: the bill is then wrong by a percentage that varies by provider.
    //
    // THE FIXTURE IS BUILT SO THE WRONG ANSWER IS VISIBLY DIFFERENT. 4,000 total with 3,000 of it
    // cached would come back as 9,000 under the additive form — more than twice the truth.
    $call = usageCall(input: 4_000, cacheRead: 2_500, cacheWrite: 500, output: 100, reasoning: 0);

    expect(UsageArithmetic::totalInputTokens($call))->toBe(4_000);
});

it('counts reasoning tokens as output, because every vendor here bills them at the output rate', function (): void {
    // THE SECOND, AND IT IS THE ONE THAT READS AS CORRECT FOREVER. `output_tokens` and
    // `reasoning_tokens` are DISJOINT counters, so `SUM(output_tokens)` under-reports a reasoning
    // turn — plausibly, by a factor that depends on the model and the question, with a number that
    // still looks like a token count and a total that still grows with traffic.
    //
    // 200 visible tokens behind 1,800 reasoning tokens is an ordinary shape for a hard question on a
    // thinking model: the wrong answer is 200, the right one is 2,000, and nothing about 200 looks
    // wrong on a dashboard.
    $call = usageCall(input: 1_000, cacheRead: 0, cacheWrite: 0, output: 200, reasoning: 1_800);

    expect(UsageArithmetic::totalOutputTokens($call))->toBe(2_000)
        ->and(UsageArithmetic::billableTokens($call))->toBe(3_000);
});

it('treats "the provider told us nothing" as nothing to bill, not as zero measured', function (): void {
    // ALL FIVE COLUMNS ARE NULLABLE and NULL means the provider sent no usage — a stream that died
    // before its usage frame. `UsageRecorder` writes NO ledger row for such a call, which is what
    // keeps `kb:rollup-usage` able to record the real numbers later if they ever arrive; if this
    // returned anything but 0 the call would be permanently deduped against a measurement nobody
    // made.
    $call = usageCall(input: null, cacheRead: null, cacheWrite: null, output: null, reasoning: null);

    expect(UsageArithmetic::totalInputTokens($call))->toBe(0)
        ->and(UsageArithmetic::totalOutputTokens($call))->toBe(0)
        ->and(UsageArithmetic::billableTokens($call))->toBe(0);
});

it('names the same columns in SQL as it does in PHP', function (): void {
    // THE PROPERTY NO DATABASE CAN CHECK. The ledger writer reads a model; the analytics aggregate
    // sums in the database over millions of rows and cannot pull them into PHP — so the arithmetic
    // exists twice, and the failure that matters is the two halves DRIFTING rather than either one
    // being unparseable.
    //
    // ASSERTED AS "WHICH COLUMNS APPEAR", NOT AS A STRING EQUALITY. Pinning the exact expression
    // would fail on a whitespace change and would say nothing about correctness; the columns are the
    // decision. `cache_read_tokens` and `cache_write_tokens` must appear in NEITHER, which is the
    // first test above expressed against the SQL half.
    $input = UsageArithmetic::INPUT_SQL;
    $output = UsageArithmetic::OUTPUT_SQL;

    expect($input)->toContain('input_tokens')
        ->and($input)->not->toContain('cache_read_tokens')
        ->and($input)->not->toContain('cache_write_tokens')
        ->and($output)->toContain('output_tokens')
        ->and($output)->toContain('reasoning_tokens')
        ->and($output)->not->toContain('input_tokens');
});

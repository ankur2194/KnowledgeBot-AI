<?php

declare(strict_types=1);

use App\Enums\ProviderCallStatus;
use App\Services\Chat\RecordedProviderCall;

/*
|--------------------------------------------------------------------------
| `input_tokens` means two different things on the two planes
|--------------------------------------------------------------------------
|
| DATA PLANE — `providers.contract.Usage.input_tokens` is DISJOINT: uncached input ONLY, with cache
| reads and cache writes as separate fields and `total_input_tokens` as the derived sum. The
| `provider.usage` frame carries the disjoint value.
|
| THIS PLANE — `provider_calls.input_tokens` is the NORMALIZED TOTAL, cache INCLUDED, and
| `provider_calls_cache_within_input` enforces `cache_read + cache_write <= input_tokens`.
|
| A FIELD-BY-NAME COPY TYPE-CHECKS AND IS A SILENT FIVE-FIGURE ERROR. It also passes the CHECK
| constraint in the one case people test — a call with no cache activity, where the two definitions
| coincide — which is why every fixture below has NON-ZERO cache fields. A test whose cache columns
| are 0 cannot fail this.
|
| The output side has the mirror-image trap: `reasoning_tokens` is billed INSIDE `output_tokens` on
| the wire and is a DISJOINT column here, so it is subtracted out rather than copied across. Copying
| both unchanged double-counts every reasoning token in every aggregate.
*/

/**
 * A `provider.usage` frame, as the data plane emits it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usageFrame(array $overrides = []): array
{
    return array_replace([
        'event' => 'provider.usage',
        'ordinal' => 1,
        'connection_id' => '01JKB000000000000000000001',
        'provider' => 'anthropic',
        'model' => 'claude-fixture',
        'outcome' => 'success',
        'error_class' => null,
        'stop_reason' => 'stop',
        'provider_request_id' => 'req_fixture',
        // DISJOINT: uncached input only.
        'input_tokens' => 50,
        'output_tokens' => 300,
        // The data plane's own rollup, deliberately NOT read — see the class docblock.
        'cached_tokens' => 200_000,
        'cache_read_tokens' => 180_000,
        'cache_write_tokens' => 20_000,
        'reasoning_tokens' => 120,
        'latency_ms' => 4200,
        'first_token_ms' => 900,
    ], $overrides);
}

it('writes the NORMALIZED TOTAL into input_tokens, cache included', function (): void {
    $call = RecordedProviderCall::fromFrame(usageFrame());

    // 50 uncached + 180 000 read + 20 000 written. A field-by-name copy would write 50 — a
    // 200 000-token cached document reported as the question that queried it.
    expect($call->inputTokens)->toBe(200_050)
        ->and($call->cacheReadTokens)->toBe(180_000)
        ->and($call->cacheWriteTokens)->toBe(20_000);
});

it('satisfies provider_calls_cache_within_input, which a field-by-name copy would violate', function (): void {
    $call = RecordedProviderCall::fromFrame(usageFrame());

    // The constraint the migration writes: `cache_read + cache_write <= input_tokens`.
    expect($call->cacheReadTokens + $call->cacheWriteTokens)->toBeLessThanOrEqual($call->inputTokens);

    // AND THE COUNTER-CASE, spelled out so the constraint is not being satisfied by accident: the
    // naive copy puts 50 in the column and 200 000 in the breakdown, which the database refuses.
    // That refusal is the ONE case of this defect that is loud — and it fires inside the finalizer's
    // transaction, taking the transcript with it, which is why the conversion is done here.
    $frame = usageFrame();
    expect($frame['cache_read_tokens'] + $frame['cache_write_tokens'])
        ->toBeGreaterThan($frame['input_tokens']);
});

it('subtracts reasoning out of output, because the two columns are disjoint here', function (): void {
    $call = RecordedProviderCall::fromFrame(usageFrame());

    // The wire says 300 output of which 120 were reasoning. The columns are disjoint, and
    // `UsageArithmetic::totalOutputTokens()` sums them — so copying both across unchanged would meter
    // 420 output tokens for a turn that produced 300.
    expect($call->outputTokens)->toBe(180)
        ->and($call->reasoningTokens)->toBe(120)
        ->and($call->outputTokens + $call->reasoningTokens)->toBe(300);
});

it('never produces a negative token count, whatever the vendor reported', function (): void {
    // A vendor reporting more reasoning than output is reporting something this model cannot
    // express. `provider_calls_tokens_nonnegative` would refuse the row INSIDE the finalizer's
    // transaction and lose the whole transcript, so it is clamped rather than passed through.
    $call = RecordedProviderCall::fromFrame(usageFrame([
        'output_tokens' => 10,
        'reasoning_tokens' => 40,
    ]));

    expect($call->outputTokens)->toBe(0)->and($call->reasoningTokens)->toBe(40);
});

it('ignores the data plane\'s `cached_tokens` rollup and reads the two parts', function (): void {
    // The rollup is `cache_read + cache_write` on the far side. Reading it instead of the parts
    // would lose the split the cost model needs — Anthropic bills a cache WRITE at a premium and a
    // READ at a discount — and a consumer holding only the sum cannot price either.
    $call = RecordedProviderCall::fromFrame(usageFrame([
        // A rollup that DISAGREES with its parts. If the conversion read it, the total would move.
        'cached_tokens' => 999_999,
    ]));

    expect($call->inputTokens)->toBe(200_050);
});

it('pairs a fallback frame with the usage frame of the same ordinal', function (): void {
    // TWO FRAMES ON THE WIRE, and `provider.usage` carries no fallback fields at all. Reading only
    // the usage frame writes `fallback_metadata: {}` for an attempt that WAS a fallback, which makes
    // the ladder look free in every cost report.
    $call = RecordedProviderCall::fromFrame(usageFrame(['ordinal' => 2]), [
        'event' => 'provider.fallback',
        'ordinal' => 2,
        'from_connection_id' => '01JKB000000000000000000009',
        'to_connection_id' => '01JKB000000000000000000001',
        'provider' => 'anthropic',
        'model' => 'claude-fixture',
        'trigger' => 'provider_temporary',
    ]);

    expect($call->isFallback)->toBeTrue()
        ->and($call->fallbackMetadata())->toBe([
            'ordinal' => 2,
            'fell_back_from' => '01JKB000000000000000000009',
            'trigger' => 'provider_temporary',
        ]);
});

it('renders a primary attempt\'s fallback_metadata as an OBJECT, never an array', function (): void {
    $call = RecordedProviderCall::fromFrame(usageFrame());

    // `json_encode([])` is `[]`, a JSON ARRAY, and `provider_calls_fallback_metadata_is_object`
    // refuses it. The cast is the writer-side half; this asserts the value it is handed.
    expect($call->fallbackMetadata())->toBe([])
        ->and(json_encode((object) $call->fallbackMetadata()))->toBe('{}');
});

it('normalises the status and error_class into a pair the CHECK constraint accepts', function (
    string $outcome,
    ?string $errorClass,
    ProviderCallStatus $expectedStatus,
    ?string $expectedClass,
): void {
    // `provider_calls_error_class_paired_with_status` refuses a `failed` row with no class and a
    // `succeeded` row with one. Both halves are normalised before the write, because a constraint
    // violation inside the finalizer's transaction loses the message row, the citations and the
    // trace along with it.
    $call = RecordedProviderCall::fromFrame(usageFrame([
        'outcome' => $outcome,
        'error_class' => $errorClass,
    ]));

    expect($call->status)->toBe($expectedStatus)->and($call->errorClass)->toBe($expectedClass);
})->with([
    'a clean success' => ['success', null, ProviderCallStatus::Succeeded, null],
    'a success carrying a class is recorded as a failure' => ['success', 'provider_temporary', ProviderCallStatus::Failed, 'provider_temporary'],
    'a classified failure' => ['error', 'provider_rate_limit', ProviderCallStatus::Failed, 'provider_rate_limit'],
    'an unclassified failure gains the honest placeholder' => ['error', null, ProviderCallStatus::Failed, 'internal_dependency'],
    'a cancellation may carry a class' => ['cancelled', 'user_cancellation', ProviderCallStatus::Cancelled, 'user_cancellation'],
    'a cancellation may carry none' => ['cancelled', null, ProviderCallStatus::Cancelled, null],
    'an outcome nobody has defined lands on the one status that is free either way' => ['who-knows', null, ProviderCallStatus::Cancelled, null],
]);

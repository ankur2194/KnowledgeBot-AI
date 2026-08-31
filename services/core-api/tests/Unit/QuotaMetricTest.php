<?php

declare(strict_types=1);

use App\Enums\QuotaMetric;
use App\Enums\UsageEventType;

/*
|--------------------------------------------------------------------------
| The four quota metrics — the two shapes, and the period arithmetic
|--------------------------------------------------------------------------
|
| A Unit test: both enums are pure PHP, so the whole table is checkable with no container, no
| database and no request.
|
| WHAT THIS FILE IS FOR. `QuotaMetric` encodes one distinction that everything downstream branches
| on — LEDGER-BACKED (summed from `usage_events` over a period) versus POINT-IN-TIME (`count(*)` over
| a primary table, right now) — and conflating them is the defect the enum exists to prevent. A
| running sum of `bot.created` minus `bot.deleted` drifts from `count(*)` the first time a row leaves
| by a route nobody instrumented, silently and in one direction only.
*/

it('splits the four metrics into exactly the two shapes, by name', function (): void {
    // PINNED BY NAME AND NOT BY COUNT. A count says "expected 2, got 3" and does not say WHICH
    // metric changed shape — and changing a metric's shape changes which store answers it, whether
    // it is cached at all, and whether it resets.
    $ledger = array_values(array_map(
        static fn (QuotaMetric $m): string => $m->value,
        array_filter(QuotaMetric::cases(), static fn (QuotaMetric $m): bool => $m->isLedgerBacked()),
    ));

    $pointInTime = array_values(array_map(
        static fn (QuotaMetric $m): string => $m->value,
        array_filter(QuotaMetric::cases(), static fn (QuotaMetric $m): bool => ! $m->isLedgerBacked()),
    ));

    expect($ledger)->toBe(['storage_bytes', 'monthly_tokens'])
        ->and($pointInTime)->toBe(['bots', 'users']);
});

it('gives a point-in-time metric no ledger terms at all', function (): void {
    // THE PROPERTY THAT MAKES THE WRONG QUERY UNWRITABLE. An empty term list means
    // `EloquentUsageEventRepository::consumed()` would build `event_type IN ()`, which PostgreSQL
    // rejects — and the tempting "fix" for that is dropping the predicate, which sums the whole
    // table. The repository raises on these two metrics instead, and this is the fact that makes the
    // raise necessary rather than defensive.
    foreach ([QuotaMetric::Bots, QuotaMetric::Users] as $metric) {
        expect($metric->ledgerTerms())->toBe(['add' => [], 'subtract' => []]);
    }
});

it('defines storage as added minus removed, in one place', function (): void {
    // TWO POSITIVE EVENT TYPES AND A SUBTRACTION, rather than one type with a signed quantity.
    // `usage_events_quantity_nonnegative` refuses a negative quantity for a reason: a ledger that
    // admits one admits a row that silently cancels another, and no constraint can tell that from a
    // correction. So "storage used" is a subtraction, and this is the only place it is written.
    expect(QuotaMetric::StorageBytes->ledgerTerms())->toBe([
        'add' => [UsageEventType::StorageBytesAdded],
        'subtract' => [UsageEventType::StorageBytesRemoved],
    ]);
});

it('counts BOTH token directions against the monthly allowance', function (): void {
    // THE DECISION, ASSERTED. An output-only quota would let one organization spend an unbounded
    // amount of the platform's provider budget on packed retrieval context while its meter barely
    // moved — a RAG turn's cost is dominated by the input side.
    expect(QuotaMetric::MonthlyTokens->ledgerTerms())->toBe([
        'add' => [UsageEventType::ChatTokensInput, UsageEventType::ChatTokensOutput],
        'subtract' => [],
    ]);
});

it('rolls the monthly period over at the UTC calendar boundary, and never rolls the others', function (): void {
    $lastInstantOfJanuary = new \DateTimeImmutable('2026-01-31 23:59:59', new \DateTimeZone('UTC'));
    $firstInstantOfFebruary = new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC'));

    // THE PERIOD SEGMENT IS PART OF THE VALKEY KEY, so a rollover is what makes a monthly counter
    // reset without anyone deleting a key. One second apart, two different keys.
    expect(QuotaMetric::MonthlyTokens->period($lastInstantOfJanuary))->toBe('202601')
        ->and(QuotaMetric::MonthlyTokens->period($firstInstantOfFebruary))->toBe('202602');

    // AND THE OTHER THREE NEVER ROLL. `storage_bytes` is summed over ALL TIME — a monthly key here
    // would reset an organization's storage usage to zero on the first of every month, which reads
    // as a plausible number and is a quota that never binds.
    foreach ([QuotaMetric::StorageBytes, QuotaMetric::Bots, QuotaMetric::Users] as $metric) {
        expect($metric->period($lastInstantOfJanuary))->toBe('all')
            ->and($metric->period($firstInstantOfFebruary))->toBe('all');
    }
});

it('gives the monthly metric a period start and the others none', function (): void {
    $at = new \DateTimeImmutable('2026-02-17 09:41:12', new \DateTimeZone('UTC'));

    // NULL IS "NO LOWER BOUND", WHICH IS A DIFFERENT FACT FROM "THE EPOCH". A zero-timestamp lower
    // bound would put a useless predicate on the index and would read as a windowed sum that happens
    // to reach back forever; null is what makes `consumed()` omit the predicate entirely.
    expect(QuotaMetric::MonthlyTokens->periodStart($at)?->format('Y-m-d H:i:s'))
        ->toBe('2026-02-01 00:00:00');

    foreach ([QuotaMetric::StorageBytes, QuotaMetric::Bots, QuotaMetric::Users] as $metric) {
        expect($metric->periodStart($at))->toBeNull();
    }
});

it('names a real organizations column for every metric', function (): void {
    // `limitColumn()` IS A `match` AND NOT A STRING CONVENTION, so this asserts the four names
    // against the migration rather than against a derivation. A mistyped column name in a dynamic
    // lookup would return null, and null reads as UNLIMITED on every screen and in every gate —
    // which is the quietest possible way to remove a quota.
    expect(array_map(
        static fn (QuotaMetric $m): string => $m->limitColumn(),
        QuotaMetric::cases(),
    ))->toBe([
        'storage_bytes_quota',
        'bots_quota',
        'users_quota',
        // PLURAL, and the plural is load-bearing rather than stylistic: AuditLoggerTest's credential
        // sweep refuses a detail key with a SINGULAR `token` segment, so `monthly_token_quota` is
        // rejected as a bearer capability while `monthly_tokens_quota` reads as a measurement. That
        // guard fired on this column when it was first written.
        'monthly_tokens_quota',
    ]);
});

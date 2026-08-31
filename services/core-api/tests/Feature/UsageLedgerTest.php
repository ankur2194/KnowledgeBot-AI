<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Enums\QuotaMetric;
use App\Enums\UsageEventType;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Services\Usage\RecordedUsage;
use App\Services\Usage\UsageRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| The quota ledger — idempotency, the period boundary, and the partition
|--------------------------------------------------------------------------
|
| A Feature suite because every assertion here is about what PostgreSQL does: the four-column unique
| index that makes a re-derivation collide, the partition the row has to land in, and the aggregate
| that turns rows into a quota number. None of it is provable against a fake.
|
| THE ONE PROPERTY THIS WHOLE FILE ORBITS: `usage_events_dedupe` is
| `(organization_id, event_type, dedupe_key, occurred_at)` — it must contain the partition key,
| because PostgreSQL requires that of a unique index on a partitioned table — so it CANNOT catch the
| same work recorded twice under two different instants. What closes that gap is not the index, it is
| the rule that `occurred_at` is DERIVED FROM THE SOURCE ROW and never from the clock. Every test
| below that re-derives something re-derives it from the same row, which is the production path.
*/

/**
 * Run a closure with the tenant context bound, which is what every production caller already has.
 *
 * `UsageEvent` carries `#[ScopedBy(OrganizationScope::class)]` and that scope FAILS CLOSED — with no
 * bound context it appends `1 = 0` — so a read here without this reports "no rows" for a reason that
 * has nothing to do with the ledger.
 */
function inOrg(Organization $organization, callable $callback): mixed
{
    return app(TenantContext::class)->runFor($organization->id, $callback);
}

/**
 * One organization with one bot, one connection and one catalogue row — the parents a token event
 * needs before it can name a provider.
 *
 * @return array{org: Organization, bot: Bot, connection: ProviderConnection, model: ProviderModelEntry}
 */
function ledgerFixture(): array
{
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->withModel('gpt-5.1', ['chat'])->create();

    return [
        'org' => $org,
        'bot' => Bot::factory()->recycle($org)->create(),
        'connection' => $connection,
        // READ INSIDE A BOUND CONTEXT, because `ProviderModelEntry` carries
        // `#[ScopedBy(OrganizationScope::class)]` and that scope FAILS CLOSED — with nothing bound
        // it appends `1 = 0`, so this lookup would report "no such catalogue row" for a row the
        // factory just created. That is the scope behaving exactly as designed, and it is why every
        // read in this file goes through `inOrg()`.
        'model' => inOrg($org, static fn (): ProviderModelEntry => ProviderModelEntry::query()
            ->where('organization_id', '=', $org->id)
            ->where('provider_connection_id', '=', $connection->id)
            ->firstOrFail()),
    ];
}

it('records a usage event once, however many times the same work is re-derived', function (): void {
    $fixture = ledgerFixture();
    $ledger = app(UsageEventRepositoryInterface::class);

    // THE SAME `occurred_at` AND THE SAME `dedupe_key` ON BOTH CALLS, which is what a re-derivation
    // from the same source row produces. A fresh ULID or a `now()` here would make the second call
    // write a second row, which on this table is a duplicate charge.
    $usage = new RecordedUsage(
        type: UsageEventType::ChatTokensInput,
        quantity: 1_500,
        occurredAt: CarbonImmutable::now('UTC')->startOfMonth()->addDay(),
        dedupeKey: 'call_deterministic',
        botId: $fixture['bot']->id,
        provider: Provider::OpenAI,
        model: 'gpt-5.1',
    );

    inOrg($fixture['org'], function () use ($ledger, $fixture, $usage): void {
        expect($ledger->record($fixture['org']->id, $usage))->toBeTrue()
            // FALSE, NOT AN EXCEPTION. `kb:rollup-usage` runs hourly and re-derives events it has
            // already seen; a path that treated a duplicate as a failure would fail every hour
            // forever.
            ->and($ledger->record($fixture['org']->id, $usage))->toBeFalse()
            ->and(UsageEvent::query()->count())->toBe(1)
            ->and($ledger->consumed($fixture['org']->id, QuotaMetric::MonthlyTokens, new \DateTimeImmutable))
            ->toBe(1_500);
    });
});

it('sums input and output together for the monthly allowance, and only inside the period', function (): void {
    $fixture = ledgerFixture();
    $month = CarbonImmutable::now('UTC')->startOfMonth();

    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->input(1_000)->occurredAt($month->addDay())->create();

    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->output(250)->occurredAt($month->addDays(2))->create();

    // LAST MONTH'S ROW. It is in a DIFFERENT PARTITION and outside the period, and it exists to
    // prove that `consumed()` bounds the sum rather than reading the whole table — a test with only
    // in-period rows passes against a query with no lower bound at all.
    $previous = $month->subMonth()->addDays(3);
    app(\App\Repositories\Eloquent\EloquentUsageEventPartitionRepository::class)->ensureMonth($previous);

    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->input(9_999_999)->occurredAt($previous)->create();

    inOrg($fixture['org'], function () use ($fixture): void {
        expect(app(UsageEventRepositoryInterface::class)->consumed(
            $fixture['org']->id,
            QuotaMetric::MonthlyTokens,
            new \DateTimeImmutable,
        ))->toBe(1_250);
    });
});

it('computes storage as added minus removed, across every period', function (): void {
    $fixture = ledgerFixture();
    $month = CarbonImmutable::now('UTC')->startOfMonth();

    UsageEvent::factory()->recycle($fixture['org'])->storageAdded(10_000)
        ->occurredAt($month->addDay())->create();
    UsageEvent::factory()->recycle($fixture['org'])->storageRemoved(2_500)
        ->occurredAt($month->addDays(2))->create();

    inOrg($fixture['org'], function () use ($fixture): void {
        // 7,500 AND NOT 12,500. A `SUM(quantity)` over both types — the shape a reader gets by
        // forgetting the subtraction — is the number that never goes down, so an organization that
        // deleted everything would stay at its ceiling forever.
        expect(app(UsageEventRepositoryInterface::class)->consumed(
            $fixture['org']->id,
            QuotaMetric::StorageBytes,
            new \DateTimeImmutable,
        ))->toBe(7_500);
    });
});

it('refuses to answer a ledger question about a point-in-time metric', function (): void {
    $fixture = ledgerFixture();

    // THE GUARD, AND WHY IT IS A RAISE RATHER THAN A ZERO. `bots` has no ledger terms, so the query
    // would carry `event_type IN ()` — which PostgreSQL rejects, and whose tempting "fix" is
    // dropping the predicate and summing the whole table. A plausible number is the worst possible
    // answer to a quota question, so this fails loudly instead.
    inOrg($fixture['org'], function () use ($fixture): void {
        expect(fn (): int => app(UsageEventRepositoryInterface::class)->consumed(
            $fixture['org']->id,
            QuotaMetric::Bots,
            new \DateTimeImmutable,
        ))->toThrow(\InvalidArgumentException::class);
    });
});

it('derives both token rows from a provider call, with the call\'s own timestamp and id', function (): void {
    $fixture = ledgerFixture();

    $call = ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->create([
            'input_tokens' => 4_000,
            'cache_read_tokens' => 2_500,
            'cache_write_tokens' => 500,
            'output_tokens' => 200,
            // THE COLUMN THE WRONG AGGREGATE IGNORES. Non-zero on purpose: with 0 here the
            // assertion below passes against `SUM(output_tokens)`.
            'reasoning_tokens' => 1_800,
        ]);

    $written = inOrg($fixture['org'], fn (): int => app(UsageRecorder::class)
        ->recordProviderCall($call, Provider::OpenAI, 'gpt-5.1'));

    expect($written)->toBe(2);

    inOrg($fixture['org'], function () use ($call): void {
        $input = UsageEvent::query()
            ->where('event_type', '=', UsageEventType::ChatTokensInput->value)->firstOrFail();
        $output = UsageEvent::query()
            ->where('event_type', '=', UsageEventType::ChatTokensOutput->value)->firstOrFail();

        // INPUT IS `input_tokens` ALONE — 4,000, not 7,000. The cache columns are a BREAKDOWN of it.
        expect($input->quantity)->toBe(4_000)
            // OUTPUT INCLUDES REASONING — 2,000, not 200.
            ->and($output->quantity)->toBe(2_000)
            // BOTH KEYED ON THE CALL'S OWN ULID, which is what makes a re-derivation collide. They
            // share the key legitimately: the unique index includes `event_type`.
            ->and($input->dedupe_key)->toBe($call->id)
            ->and($output->dedupe_key)->toBe($call->id)
            // AND ON THE CALL'S OWN TIMESTAMP, never `now()`.
            ->and($input->occurred_at->equalTo($call->created_at))->toBeTrue();
    });

    // RE-DERIVED: zero rows written, and the ledger unchanged. This is the property `kb:rollup-usage`
    // depends on to be safe to run every hour forever.
    $again = inOrg($fixture['org'], fn (): int => app(UsageRecorder::class)
        ->recordProviderCall($call, Provider::OpenAI, 'gpt-5.1'));

    expect($again)->toBe(0)
        ->and(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(2);
});

it('writes nothing for a call the provider never reported usage for', function (): void {
    $fixture = ledgerFixture();

    // `withoutUsage()` NULLS ALL FIVE COUNTERS — a stream that died before its usage frame, which is
    // a real outcome. Recording zeroes would assert a measurement nobody made AND would permanently
    // dedupe the call, so a later rollup could never record the real numbers if they arrived.
    $call = ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->withoutUsage()->create();

    $written = inOrg($fixture['org'], fn (): int => app(UsageRecorder::class)
        ->recordProviderCall($call, Provider::OpenAI, 'gpt-5.1'));

    expect($written)->toBe(0)
        ->and(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(0);
});

it('derives the ledger rows kb:rollup-usage was written to find', function (): void {
    $fixture = ledgerFixture();

    // A PROVIDER CALL WITH NO LEDGER ROW BEHIND IT — the state left by a finalizer that died, an
    // OOM-killed worker, or an insert that failed on a missing partition.
    ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->create(['input_tokens' => 900, 'output_tokens' => 100, 'reasoning_tokens' => 0]);

    expect(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(0);

    expect(Artisan::call('kb:rollup-usage'))->toBe(0);

    expect(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(2)
        ->and(inOrg($fixture['org'], fn (): int => app(UsageEventRepositoryInterface::class)
            ->consumed($fixture['org']->id, QuotaMetric::MonthlyTokens, new \DateTimeImmutable)))
        ->toBe(1_000);

    // RUN AGAIN: still two rows. The whole point of the command is that it is safe on a schedule.
    expect(Artisan::call('kb:rollup-usage'))->toBe(0);

    expect(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(2);
});

it('writes nothing on a dry run', function (): void {
    $fixture = ledgerFixture();

    ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->create(['input_tokens' => 900, 'output_tokens' => 100, 'reasoning_tokens' => 0]);

    expect(Artisan::call('kb:rollup-usage', ['--dry-run' => true]))->toBe(0)
        ->and(inOrg($fixture['org'], fn (): int => UsageEvent::query()->count()))->toBe(0);
});

it('refuses a lookback window that is not a positive integer, from either caller shape', function (): void {
    // BOTH SPELLINGS. The terminal hands `--hours=x` through as a STRING and `Artisan::call(...,
    // ['--hours' => 4])` puts a real INT into the input — the same divergence that made
    // CreateAuditPartitionsCommand print "must be a non-negative integer" about an integer.
    expect(Artisan::call('kb:rollup-usage', ['--hours' => 'lots']))->toBe(1)
        ->and(Artisan::call('kb:rollup-usage', ['--hours' => 0]))->toBe(1)
        ->and(Artisan::call('kb:rollup-usage', ['--hours' => 100_000]))->toBe(1)
        // AND THE INTEGER FORM IS ACCEPTED, which is the half a string-only guard would refuse.
        ->and(Artisan::call('kb:rollup-usage', ['--hours' => 6, '--dry-run' => true]))->toBe(0);
});

it('keeps three months of usage partitions ahead of the calendar', function (): void {
    // THE COMMAND WHOSE ABSENCE IS THE QUIET OUTAGE: past the runway every ledger INSERT fails with
    // 23514, `SourceService::recordStorageUsage()` swallows it, metering stops, every quota reads
    // low, and the first symptom is an invoice.
    $partitions = app(\App\Repositories\Eloquent\EloquentUsageEventPartitionRepository::class);
    $now = CarbonImmutable::now('UTC')->startOfMonth();

    expect(Artisan::call('kb:create-usage-partitions'))->toBe(0);

    $names = $partitions->partitionNames();

    for ($offset = 0; $offset <= 3; $offset++) {
        expect($names)->toContain($partitions::nameFor($now->addMonths($offset)));
    }

    // IDEMPOTENT, which is a requirement rather than a courtesy: it runs on a schedule, twice on a
    // bad day, and possibly on two hosts in the same second.
    expect(Artisan::call('kb:create-usage-partitions'))->toBe(0);
});

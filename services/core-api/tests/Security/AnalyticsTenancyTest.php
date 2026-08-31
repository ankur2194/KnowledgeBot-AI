<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\QuotaMetric;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Models\User;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Services\Analytics\AnalyticsWindow;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The analytics and quota surfaces never add up another organization's numbers (§22.5)
|--------------------------------------------------------------------------
|
| `kb-tenancy-isolation` names ANALYTICS AND EXPORTS as their own isolation layer — separate from
| HTTP authorization and from query scopes — and names the exact way it fails:
|
|     "A dashboard or CSV export shows another org's numbers. Aggregates over `usage_events`,
|      `provider_calls`, and `messages` get grouped by `bot_id` and the `organization_id` predicate
|      is dropped as redundant. §8.2 lists analytics and exports as their own layers precisely
|      because that reasoning is so easy to accept."
|
| IT IS NEVER REDUNDANT, AND ON THREE OF THE SIX TABLES IT IS THE ONLY TENANCY THERE IS. `messages`,
| `retrieval_traces` and `feedback` hold NO `organization_id` — they reach one through
| `conversations`, over a one- or two-hop join — so those three models carry no `#[ScopedBy]` and
| CANNOT: the column the scope would filter does not exist. Drop the join predicate and the query
| counts every tenant's rows, with no exception, no log line and a number that looks exactly like a
| correct one.
|
| ── WHY THIS FILE ASSERTS AT THE REPOSITORY AS WELL AS OVER HTTP ─────────────────────────────
|
| Because the HTTP assertion alone is the weaker one, and this is the same trap
| EmbeddingDesignationTenancyTest records: a request as Org A's admin carries Org A's tenant context,
| so the `#[ScopedBy]` backstop supplies the predicate even when the EXPLICIT one has been deleted —
| and on `conversations` and `provider_calls` the test would stay green with the mechanism gone. The
| repository assertions below run under Org A's context and ASK FOR ORG B's id, which is the shape
| only the explicit argument can answer correctly, and the shape a stale pooled worker actually
| produces.
|
| EVERY FIXTURE IS `tenantPair()`. There is deliberately no single-organization helper: with one
| tenant there is nothing to leak, so such a test passes against code with no filter at all.
|
| A POSITIVE CONTROL COMES FIRST IN EVERY TEST. Without it these also pass when the surface is broken
| and returns nothing at all — and that is the assertion people delete when it is slow.
*/

it('never counts another organization\'s conversations, messages or sessions', function (): void {
    $t = tenantPair();
    $now = CarbonImmutable::now('UTC');

    // BOTH ORGANIZATIONS CARRY TRAFFIC, AND THE TWO POPULATIONS ARE DIFFERENT SIZES. That is what
    // makes every assertion below DISCRIMINATE: with traffic in only one tenant, a query whose
    // organization predicate had been deleted would return the same number as a correct one, and
    // this whole file would be green against no filter at all.
    //
    // `tenantPair()` deliberately gives Org A no KNOWLEDGE — its docblock explains why an unpaired
    // source makes a canary assertion stronger — and says a test that needs rows in Org A creates
    // them explicitly, which is what this is.
    // BOTH TIMESTAMPS SET EXPLICITLY, AND BOTH INSIDE THE WINDOW. `ConversationFactory` defaults
    // `started_at` to a faker date over the last THIRTY days, so a seven-day window admits roughly a
    // quarter of them at random — a test that would fail one run in five for a reason nobody
    // reproduces. They move together because `conversations_activity_after_start` refuses the other
    // order.
    $inWindow = ['started_at' => $now->subDay(), 'last_activity_at' => $now->subDay()];

    $threadA = Conversation::factory()->recycle($t->a)->recycle($t->botA)->create($inWindow);
    Message::factory()->recycle($threadA)->count(2)->create();

    $threadsB = Conversation::factory()->recycle($t->b)->recycle($t->botB)
        ->count(3)->create($inWindow);

    // `firstOrFail()` AND NOT `first()`: a `Collection::first()` is nullable, and `recycle(null)`
    // would make MessageFactory mint its OWN conversation — in a THIRD organization — which is
    // exactly the shape that makes an isolation assertion pass with the filter deleted.
    Message::factory()->recycle($threadsB->firstOrFail())->count(7)->create();

    $window = new AnalyticsWindow($now->subDays(7), $now->addHour());
    $analytics = app(AnalyticsRepositoryInterface::class);

    // POSITIVE CONTROL FIRST, under each organization's own context. Without it these tests also
    // pass when the surface is broken and returns nothing at all — which is the assertion people
    // delete when it is slow.
    app(TenantContext::class)->runFor($t->b->id, function () use ($analytics, $t, $window): void {
        expect($analytics->conversationCount($t->b->id, $window))->toBe(3)
            ->and($analytics->messageCount($t->b->id, $window))->toBe(7)
            ->and($analytics->uniqueSessionCount($t->b->id, $window))->toBe(3);
    });

    // AND ORG A SEES ONLY ITS OWN. `messages` is the one that matters most: it holds NO
    // `organization_id` and carries no `#[ScopedBy]` — it cannot, because the column does not exist
    // — so the join predicate onto `conversations` is the ONLY tenancy on this number. Delete it and
    // this reads 9 (2 + 7), which is a count that looks entirely plausible.
    app(TenantContext::class)->runFor($t->a->id, function () use ($analytics, $t, $window): void {
        expect($analytics->conversationCount($t->a->id, $window))->toBe(1)
            ->and($analytics->messageCount($t->a->id, $window))->toBe(2)
            ->and($analytics->uniqueSessionCount($t->a->id, $window))->toBe(1);
    });
});

it('scopes an aggregate by the explicit argument and not by the ambient context', function (): void {
    // ── THE TRAP THIS FILE EXISTS FOR, AND THE ONE AN HTTP ASSERTION CANNOT SEE ───────────────
    //
    // A request as Org A's admin carries Org A's tenant context, so on a SCOPED model the
    // `#[ScopedBy]` backstop supplies the predicate even when the EXPLICIT one has been deleted —
    // and the test stays green with the mechanism gone. `EmbeddingDesignationTenancyTest` records
    // the same shape and calls it out as verified by mutation.
    //
    // What the explicit argument actually defends is a context that DISAGREES with it: a pooled
    // queue worker holding the previous tenant, a scheduled sweep that iterates organizations. So
    // this asks for ORG B's numbers under ORG A's bound context, and the two models answer
    // DIFFERENTLY — which is the whole point:
    //
    //   `conversations` IS scoped, so the two layers CONFLICT and the query fails CLOSED: the
    //   explicit `organization_id = B` and the scope's `organization_id = A` cannot both hold, and
    //   the answer is 0. A query that returns nothing is loud, immediate and debuggable.
    //
    //   `messages` is NOT scoped and cannot be, so the explicit join predicate is the only layer and
    //   it answers truthfully for the organization it was ASKED about. That is correct behaviour for
    //   a repository whose contract takes the organization positionally — and it is precisely why
    //   the argument is required and positional rather than defaulted.
    $t = tenantPair();
    $now = CarbonImmutable::now('UTC');

    // Explicit and in-window, for the reason the test above records: the factory's default start is
    // a faker date over thirty days and a seven-day window would admit them at random.
    $threadsB = Conversation::factory()->recycle($t->b)->recycle($t->botB)->count(3)->create([
        'started_at' => $now->subDay(),
        'last_activity_at' => $now->subDay(),
    ]);

    // `firstOrFail()` for the reason the test above records: `recycle(null)` mints a THIRD
    // organization's conversation.
    Message::factory()->recycle($threadsB->firstOrFail())->count(7)->create();

    $window = new AnalyticsWindow($now->subDays(7), $now->addHour());
    $analytics = app(AnalyticsRepositoryInterface::class);

    app(TenantContext::class)->runFor($t->a->id, function () use ($analytics, $t, $window): void {
        expect($analytics->conversationCount($t->b->id, $window))->toBe(0)
            ->and($analytics->messageCount($t->b->id, $window))->toBe(7);
    });
});

it('never sums another organization\'s usage into a quota', function (): void {
    $t = tenantPair();

    UsageEvent::factory()->recycle($t->b)->storageAdded(9_000)->create();
    UsageEvent::factory()->recycle($t->b)->recycle($t->botB)->input(7_000)->create();

    $ledger = app(UsageEventRepositoryInterface::class);

    // POSITIVE CONTROL FIRST, under Org B's own context.
    app(TenantContext::class)->runFor($t->b->id, function () use ($ledger, $t): void {
        expect($ledger->consumed($t->b->id, QuotaMetric::StorageBytes, new \DateTimeImmutable))
            ->toBe(9_000)
            ->and($ledger->consumed($t->b->id, QuotaMetric::MonthlyTokens, new \DateTimeImmutable))
            ->toBe(7_000);
    });

    // ORG A HAS METERED NOTHING. If either of these returned Org B's figure, Org A would be refused
    // an upload for storage it does not occupy — and, in the other direction, an organization could
    // be admitted against a ceiling somebody else's usage had already consumed.
    app(TenantContext::class)->runFor($t->a->id, function () use ($ledger, $t): void {
        expect($ledger->consumed($t->a->id, QuotaMetric::StorageBytes, new \DateTimeImmutable))
            ->toBe(0)
            ->and($ledger->consumed($t->a->id, QuotaMetric::MonthlyTokens, new \DateTimeImmutable))
            ->toBe(0);
    });
});

it('serves an organization\'s own dashboard and 403s a foreign one', function (): void {
    $t = tenantPair();

    Conversation::factory()->recycle($t->b)->recycle($t->botB)->count(2)->create();

    $analystA = User::factory()->recycle($t->a)->orgRole(OrgRole::Analyst)->create();

    // POSITIVE CONTROL: Org A's analyst reads Org A's dashboard, which exists and answers.
    currentTest()->actingAs($analystA)
        ->getJson("/api/v1/organizations/{$t->a->id}/analytics")
        ->assertOk()
        ->assertJsonPath('data.conversations', 0);

    // AND ORG B'S IS A 403 ON THIS SURFACE — not a 404. The admin API is authenticated and is NOT
    // enumeration-sensitive: a member of an organization is already entitled to know another
    // organization exists. The 404 deny belongs to the public runtime and SDK surfaces, and
    // `error_class` is `authorization` either way, which is what nothing branches on.
    currentTest()->actingAs($analystA)
        ->getJson("/api/v1/organizations/{$t->b->id}/analytics")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('never leaks the other organization\'s canary through any tile', function (): void {
    $t = tenantPair();

    // ORG B'S CANARY IS IN ITS BOT'S WELCOME MESSAGE, and a conversation with that bot is the
    // closest this surface comes to touching it. THE ASSERTION IS OVER THE RAW BODY rather than a
    // decoded field, so a leak through a key nobody thought to check still trips it.
    Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    $analystA = User::factory()->recycle($t->a)->orgRole(OrgRole::Analyst)->create();

    $response = currentTest()->actingAs($analystA)
        ->getJson("/api/v1/organizations/{$t->a->id}/analytics")
        ->assertOk();

    expect((string) $response->getContent())->not->toContain($t->canary);
});

it('never leaks another organization\'s quota state', function (): void {
    $t = tenantPair();

    $t->b->storage_bytes_quota = 12_345;
    $t->b->save();

    UsageEvent::factory()->recycle($t->b)->storageAdded(6_789)->create();

    $ownerA = User::factory()->recycle($t->a)->orgRole(OrgRole::Owner)->create();

    // POSITIVE CONTROL: Org A's own quota page answers, with Org A's (absent) ceilings.
    $response = currentTest()->actingAs($ownerA)
        ->getJson("/api/v1/organizations/{$t->a->id}/quotas")
        ->assertOk();

    /** @var list<array{metric: string, used: int, limit: int|null}> $metrics */
    $metrics = $response->json('data.metrics');
    $storage = $metrics[0];

    expect($storage['metric'])->toBe('storage_bytes')
        ->and($storage['limit'])->toBeNull()
        ->and($storage['used'])->toBe(0);

    // NEITHER ORG B'S CEILING NOR ITS CONSUMPTION APPEARS ANYWHERE IN THE BODY. Asserted over the
    // RAW response rather than the two fields, because a quota page that leaked a foreign number
    // into a third key would satisfy a field-by-field assertion.
    $body = (string) $response->getContent();

    expect(str_contains($body, '12345'))->toBeFalse()
        ->and(str_contains($body, '6789'))->toBeFalse();

    // AND THE WRITE SURFACE IS 403 FOR A FOREIGN ORGANIZATION, which matters more here than on the
    // read: raising another tenant's ceiling is the one edit on this surface that costs money.
    currentTest()->actingAs($ownerA)
        ->putJson("/api/v1/organizations/{$t->b->id}/quotas", [
            'storage_bytes_quota' => null,
            'bots_quota' => null,
            'users_quota' => null,
            'monthly_tokens_quota' => null,
        ])
        ->assertStatus(403);

    expect($t->b->fresh()?->storage_bytes_quota)->toBe(12_345);
});

<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Quotas\QuotaLimitService;
use Carbon\CarbonImmutable;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| E4 — the four quota ceilings, and who may move them
|--------------------------------------------------------------------------
|
| WHAT THIS SUITE IS REALLY ABOUT is the sentence a reader is most likely to get backwards:
| `limit: null` MEANS UNLIMITED and `limit: 0` MEANS NOTHING IS ALLOWED. Both render as "no number"
| on a form, they are opposite states, and the whole gate branches on the difference — a null metric
| is SKIPPED (no counter read, no aggregate query) and a zero metric refuses everything.
|
| THE SECOND THING IT ASSERTS IS A GUARD THAT LIVES IN NO POLICY. `QuotaLimitService` lets an
| organization owner LOWER a ceiling and requires `users.is_platform_owner` to RAISE or REMOVE one,
| because §6.1 gives limit control to the platform owner while §6.2 gives the org owner "manage
| organization settings" — a contradiction this change records rather than resolves. It cannot be a
| permission: `OrgScopedPolicy::permit()` takes `(user, record, permission)` and has no argument
| position for the DIRECTION of a change.
|
| Cross-organization isolation is tests/Security/AnalyticsTenancyTest.php, on `tenantPair()`.
*/

function quotaUrl(Organization $organization): string
{
    return "/api/v1/organizations/{$organization->id}/quotas";
}

/**
 * The response's metric list, keyed by metric name.
 *
 * A HELPER RATHER THAN `collect($response->json(...))->keyBy('metric')` AT EACH CALL SITE, because
 * `json()` is `mixed` and static analysis cannot resolve Collection's template parameters from it —
 * so every call site would need its own annotation. The narrowing happens once, here, where the
 * shape is also documented.
 *
 * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 * @return array<string, array{metric: string, used: int, limit: int|null, remaining: int|null, exceeded: bool, source: string}>
 */
function quotaMetrics(\Illuminate\Testing\TestResponse $response): array
{
    /** @var list<array{metric: string, used: int, limit: int|null, remaining: int|null, exceeded: bool, source: string}> $metrics */
    $metrics = $response->json('data.metrics');

    $keyed = [];

    foreach ($metrics as $metric) {
        $keyed[$metric['metric']] = $metric;
    }

    return $keyed;
}

/**
 * The four ceilings as a complete PUT body. Every key is `present`-required, so a partial set is a
 * 422 rather than a silent removal.
 *
 * @return array<string, int|null>
 */
function quotaBody(
    ?int $storage = null,
    ?int $bots = null,
    ?int $users = null,
    ?int $tokens = null,
): array {
    return [
        'storage_bytes_quota' => $storage,
        'bots_quota' => $bots,
        'users_quota' => $users,
        'monthly_tokens_quota' => $tokens,
    ];
}

it('reports every metric, with null limits for an organization nobody has metered', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    // THE STATE EVERY EXISTING ORGANIZATION IS IN THE MOMENT THE MIGRATION RUNS. Four nullable
    // columns with no default and no backfill — which is the only safe direction: `DEFAULT 0` would
    // refuse every upload and every chat turn in the platform at the instant of the deploy.
    currentTest()->actingAs($owner)
        ->getJson(quotaUrl($org))
        ->assertOk()
        ->assertJsonCount(4, 'data.metrics')
        ->assertJsonPath('data.metrics.0.metric', 'storage_bytes')
        ->assertJsonPath('data.metrics.0.limit', null)
        ->assertJsonPath('data.metrics.0.remaining', null)
        ->assertJsonPath('data.metrics.0.exceeded', false)
        // THIS ENDPOINT ALWAYS READS THE PRIMARY, so a healthy response is `database` on every row.
        // The field is worth shipping precisely because its value never varies until something is
        // wrong, which makes it the cheapest detector of an otherwise silent cache outage.
        ->assertJsonPath('data.metrics.0.source', 'database');
});

it('counts bots and members live rather than from the ledger', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    Bot::factory()->recycle($org)->count(3)->create();
    User::factory()->recycle($org)->orgRole(OrgRole::Analyst)->count(2)->create();

    // POINT-IN-TIME, NOT LEDGER-BACKED, and this is what that distinction buys: `count(*)` goes DOWN
    // when a row is deleted, while a running sum of created-minus-deleted events drifts the first
    // time a row leaves by a route nobody instrumented. `QuotaMetric::isLedgerBacked()` is the
    // split, and `EloquentUsageEventRepository::consumed()` RAISES rather than answering for these
    // two.
    //
    // `users` COUNTS MEMBERSHIPS: three here — the owner plus two analysts — because a seat is what
    // is metered, and one person in two organizations occupies one in each.
    $response = currentTest()->actingAs($owner)->getJson(quotaUrl($org))->assertOk();

    $metrics = quotaMetrics($response);

    expect($metrics['bots']['used'])->toBe(3)
        ->and($metrics['users']['used'])->toBe(3);
});

it('reports storage and tokens from the ledger, and remaining against the ceiling', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();
    $bot = Bot::factory()->recycle($org)->create();

    $org->storage_bytes_quota = 10_000;
    $org->monthly_tokens_quota = 5_000;
    $org->save();

    UsageEvent::factory()->recycle($org)->storageAdded(4_000)->create();
    UsageEvent::factory()->recycle($org)->storageRemoved(1_500)->create();
    UsageEvent::factory()->recycle($org)->recycle($bot)->input(1_000)->create();
    UsageEvent::factory()->recycle($org)->recycle($bot)->output(250)->create();

    $metrics = quotaMetrics(
        currentTest()->actingAs($owner)->getJson(quotaUrl($org))->assertOk()
    );

    // 2,500 AND NOT 5,500: storage is ADDED MINUS REMOVED, which is the number that can go down.
    expect($metrics['storage_bytes']['used'])->toBe(2_500)
        ->and($metrics['storage_bytes']['remaining'])->toBe(7_500)
        // BOTH TOKEN DIRECTIONS. An output-only quota would let an organization spend an unbounded
        // amount of the platform's budget on packed retrieval context while its meter barely moved.
        ->and($metrics['monthly_tokens']['used'])->toBe(1_250)
        ->and($metrics['monthly_tokens']['remaining'])->toBe(3_750)
        ->and($metrics['monthly_tokens']['exceeded'])->toBeFalse();
});

it('never reports a negative remaining, and marks an over-quota metric exceeded', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    $org->storage_bytes_quota = 1_000;
    $org->save();

    UsageEvent::factory()->recycle($org)->storageAdded(2_500)->create();

    $metrics = quotaMetrics(
        currentTest()->actingAs($owner)->getJson(quotaUrl($org))->assertOk()
    );

    // AN OVER-QUOTA ORGANIZATION HAS ZERO LEFT, NEVER LESS. A negative remaining renders as a
    // negative bar on every progress control and invites a client to treat it as headroom.
    expect($metrics['storage_bytes']['used'])->toBe(2_500)
        ->and($metrics['storage_bytes']['remaining'])->toBe(0)
        ->and($metrics['storage_bytes']['exceeded'])->toBeTrue();
});

it('lets an organization owner LOWER a ceiling and refuses a raise', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    $org->storage_bytes_quota = 10_000;
    $org->bots_quota = 5;
    $org->save();

    // LOWERING IS PERMITTED AND IS THE POINT. Capping your own spend, or stopping a runaway
    // integration, is something an operator genuinely needs — and it is safe by construction,
    // because the only direction it moves is towards refusing more.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: 4_000, bots: 5))
        ->assertOk()
        // RENDERED OFF THE ROW THE WRITE RETURNED. The bound organization still held 10,000 when the
        // request was routed, so reading `limit` off it would echo the operator's PREVIOUS ceiling
        // back on the very response that changed it.
        ->assertJsonPath('data.metrics.0.limit', 4_000);

    assertDatabaseHas('organizations', ['id' => $org->id, 'storage_bytes_quota' => 4_000]);

    // RAISING IS NOT. A quota an organization can raise is not a quota — §6.1 assigns limit control
    // to the PLATFORM owner, and this is the narrow reading of a specification that gives the same
    // four numbers to two different roles without saying which wins.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: 999_999, bots: 5))
        ->assertStatus(403)
        // `authorization`, NOT `tenant_quota`. The caller is not over a quota — they are not
        // permitted to perform this act — and rendering it as `tenant_quota` would put a permission
        // refusal into the bucket a dashboard counts plan breaches in.
        ->assertJsonPath('error_class', 'authorization');

    // AND REMOVING A CEILING IS A RAISE, WHICH IS THE COMPARISON THAT IS EASY TO GET BACKWARDS.
    // `null` means UNLIMITED, so it is the LARGEST possible raise — and a naive `$proposed >
    // $current` reads it as `null > 4000`, which PHP evaluates as FALSE. That one comparison would
    // let any owner remove every ceiling on their own account, and it would review as correct.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: null, bots: 5))
        ->assertStatus(403);

    assertDatabaseHas('organizations', ['id' => $org->id, 'storage_bytes_quota' => 4_000]);
});

it('lets a platform owner raise a ceiling, and records that it was raised', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    // §6.1's ROLE, WHICH IS A PLATFORM FLAG AND NOT AN ORGANIZATION ROLE. It still needs
    // `quotas.manage` in this organization — the flag adds the direction, it does not replace
    // membership — which is why the fixture is an owner who is ALSO flagged.
    $owner->is_platform_owner = true;
    $owner->save();

    $org->storage_bytes_quota = 1_000;
    $org->save();

    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: 50_000))
        ->assertOk()
        ->assertJsonPath('data.metrics.0.limit', 50_000);

    // THE AUDIT ROW IS THE AUTHORITY, NOT THE PRE-CHECK. `raised` is derived INSIDE the transaction
    // from the two sets the repository read under the row lock, so it is true of the write that
    // actually happened rather than of the proposal the caller submitted — which matters because the
    // service's direction check reads the bound row OUTSIDE the lock and can lose a race.
    //
    // A `raised: true` ROW WHOSE ACTOR IS NOT A PLATFORM OWNER IS THE FINDING an audit of this
    // surface exists to produce.
    $row = AuditLog::query()
        ->where('operation', '=', AuditLogger::QUOTA_LIMITS_UPDATED)
        ->firstOrFail();

    expect($row->details['raised'])->toBeTrue()
        ->and($row->details['storage_bytes_quota'])->toBe(50_000)
        ->and($row->details['previous_storage_bytes_quota'])->toBe(1_000)
        ->and($row->actor_id)->toBe($owner->id)
        // ABSENT, NOT NULL. The three metrics that were and remain unlimited are the ABSENCE of
        // their keys — the sanitizer drops a null without reporting it, which is the same rule the
        // two designation pairs' `previous_*` keys rely on.
        ->and($row->details)->not->toHaveKey('bots_quota')
        ->and($row->details)->not->toHaveKey('previous_bots_quota');
});

it('refuses a partial body, because an omitted key and a null key mean opposite things', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    // `present` ON ALL FOUR. A PATCH shape would collapse "leave it alone" and "remove this ceiling
    // entirely" into the same request, so a client that dropped a key from its payload would
    // silently remove a limit.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), ['storage_bytes_quota' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['bots_quota', 'users_quota', 'monthly_tokens_quota']);

    // NEGATIVE IS REFUSED AND ZERO IS NOT. Zero is a real ceiling meaning "nothing is allowed" — it
    // is how an operator freezes an organization without deleting anything — while a negative
    // ceiling is a number nothing can satisfy AND one that makes `used <= limit` false for a
    // brand-new organization.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: -1))
        ->assertStatus(422);

    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody(storage: 0))
        ->assertOk()
        ->assertJsonPath('data.metrics.0.limit', 0)
        ->assertJsonPath('data.metrics.0.exceeded', true);
});

it('splits the read and the write across two permissions', function (): void {
    $org = Organization::factory()->create();

    $analyst = User::factory()->recycle($org)->orgRole(OrgRole::Analyst)->create();
    $admin = User::factory()->recycle($org)->orgRole(OrgRole::Admin)->create();

    // READING IS `analytics.view`, because it is a REPORT — and it is the same figure
    // `AnalyticsResource.storage_bytes_used` already publishes to every holder of that permission.
    // Gating it behind the write permission would hide a number that is already on the dashboard.
    currentTest()->actingAs($analyst)->getJson(quotaUrl($org))->assertOk();

    // WRITING IS `quotas.manage`, which the Organization Owner alone holds among the four org roles.
    // An ADMINISTRATOR IS REFUSED, and that is the grant that will surprise a reader: §6.3 opens
    // "an administrator manages operational settings but may not own BILLING or destructive
    // organization-level actions", and a quota ceiling is the billing boundary.
    currentTest()->actingAs($admin)
        ->putJson(quotaUrl($org), quotaBody())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    currentTest()->actingAs($analyst)
        ->putJson(quotaUrl($org), quotaBody())
        ->assertStatus(403);
});

it('refuses a quota edit on a suspended organization but still serves the read', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();

    $org->status = OrganizationStatus::Suspended;
    $org->save();

    // CHECK 5, ON THE WRITE ONLY. The only edit that would matter on a suspended organization is
    // RAISING a ceiling — the act §6.1 assigns to the platform owner, and frequently the thing a
    // suspension is a consequence of.
    currentTest()->actingAs($owner)
        ->putJson(quotaUrl($org), quotaBody())
        ->assertStatus(409);

    // AND THE READ IS SERVED, deliberately: reading how much of an allowance is left is exactly
    // what a suspended organization's operator needs to do, and the action writes nothing.
    currentTest()->actingAs($owner)->getJson(quotaUrl($org))->assertOk();
});

it('rolls the monthly token period over at the calendar boundary', function (): void {
    $org = Organization::factory()->create();
    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create();
    $bot = Bot::factory()->recycle($org)->create();

    $org->monthly_tokens_quota = 1_000_000;
    $org->save();

    // LAST MONTH'S USAGE, IN ITS OWN PARTITION. It must not count against this month's allowance —
    // and a `consumed()` with no period lower bound would report 9,000,000 here, which reads as a
    // plausible number and is a quota that never resets.
    $previous = CarbonImmutable::now('UTC')->startOfMonth()->subMonth()->addDays(3);
    app(\App\Repositories\Eloquent\EloquentUsageEventPartitionRepository::class)->ensureMonth($previous);

    UsageEvent::factory()->recycle($org)->recycle($bot)
        ->input(9_000_000)->occurredAt($previous)->create();

    UsageEvent::factory()->recycle($org)->recycle($bot)->input(400)->create();

    $metrics = quotaMetrics(
        currentTest()->actingAs($owner)->getJson(quotaUrl($org))->assertOk()
    );

    expect($metrics['monthly_tokens']['used'])->toBe(400);
});

it('publishes the raise refusal as an actionable sentence and never as a flag oracle', function (): void {
    // THE MESSAGE NAMES THE DIRECTION AND THE REMEDY, and deliberately does NOT say "you are not a
    // platform owner" — that would be an oracle over a flag the caller can neither see nor act on.
    expect(QuotaLimitService::RAISING_NEEDS_PLATFORM_OWNER)->toContain('lowered');
    expect(str_contains(QuotaLimitService::RAISING_NEEDS_PLATFORM_OWNER, 'platform owner flag'))
        ->toBeFalse();
});

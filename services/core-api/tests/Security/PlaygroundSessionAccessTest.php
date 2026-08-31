<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Enums\BotStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\Bot;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Sdk\WidgetSessionService;
use App\Support\Kb\ClientEvents;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;
use Tests\Support\TenantPair;

/*
|--------------------------------------------------------------------------
| The D5 playground credential — who may be issued one, and what it may do (§22.5)
|--------------------------------------------------------------------------
|
| THE BEHAVIOUR HALF IS tests/Feature/PlaygroundSessionTest.php, including the two stream
| assertions — a playground session receives `retrieval.trace` and only the trace; a widget session
| receives every OTHER frame and not the trace. This file is the boundary half: who may mint, who
| may not, and what happens to a credential after the authorization behind it changes.
|
| EVERY FIXTURE IS `tenantPair()`. A one-organization fixture cannot fail an isolation test — there
| is nothing to leak, so it passes against code with no filter at all — and there is deliberately no
| single-org helper to reach for (pest-testing NN1).
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` AND NEVER
| `->not->toContain(...)`. Pest's `toContain(mixed ...$needles)` takes no message argument, so a
| label passed there becomes a second needle, and `not` treats any failure as success — the
| expression passes unconditionally. That exact shape has already hidden a real tenant-id leak here.
|
| `Http::fake()` APPEARS NOWHERE and cannot: pest-testing bans it under tests/Security outright.
| Nothing in this file streams — the mint computes no answer — so there is nothing to fake.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    relaxPublicSurfaceLimiters();
});

/**
 * The mint route for one organization and one bot, as a template both halves of a cross-org test
 * can address.
 */
function playgroundPath(string $organizationId, string $botId): string
{
    return "/api/v1/organizations/{$organizationId}/bots/{$botId}/playground-session";
}

/**
 * `tenantPair()`, with both bots moved into `testing`.
 *
 * BotFactory defaults to `draft`, which `BotStatus::isPlaygroundReachable()` refuses — so without
 * this every assertion below would be answered by CHECK 5 with a 409 and the authorization matrix
 * would prove nothing. `testing` and not `published`: publishing runs the publish guard, which
 * refuses a bot with no provider model, and the shared fixture's bots deliberately have none.
 */
function playgroundTenantPair(): TenantPair
{
    $t = tenantPair();

    foreach ([$t->botA, $t->botB] as $bot) {
        $bot->status = BotStatus::Testing;
        $bot->save();
    }

    return $t;
}

// -------------------------------------------------------------------------------------------
// Property 3 — minting is permission-gated, org-scoped, and cross-org is 403
// -------------------------------------------------------------------------------------------

it('issues a playground credential only to a role that may MANAGE the bot', function (OrgRole $role, bool $allowed): void {
    $t = playgroundTenantPair();

    $actor = User::factory()->recycle($t->a)->orgRole($role)->create([
        'email' => SpaSession::uniqueEmail('playground-'.$role->value),
    ]);

    SpaSession::establish(currentTest(), $actor);

    $response = currentTest()->postJson(playgroundPath($t->a->id, $t->botA->id), [], spaHeaders());

    if ($allowed) {
        $response->assertStatus(201);

        expect($response->json('data.token'))->toBeString();

        return;
    }

    // 403 AND NOT 404, because this is an AUTHENTICATED ADMIN SURFACE and the caller is a member of
    // the organization that owns the row — admitting the row exists tells them nothing they could
    // not already learn from the bot list, which all four roles may read.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');

    // AND NO CREDENTIAL CAME BACK IN ANY SHAPE. A 403 whose body still carried a token would be the
    // one failure a status assertion cannot see.
    expect(str_contains((string) $response->getContent(), WidgetSessionService::PREFIX))->toBeFalse(
        'a refused mint returned something token-shaped',
    );
})->with([
    // THE MATRIX IS ASSERTED PER ROLE rather than inferred from a uniform dataset. Every role that
    // holds `bots.manage` also holds `bots.view`, so the two rows that matter are precisely the two
    // a hand-written dataset is most likely to under-cover — and they are the two the shipped panel
    // hides its composer for. A UI check is not an authorization check.
    'owner may' => [OrgRole::Owner, true],
    'admin may' => [OrgRole::Admin, true],
    'knowledge_manager may NOT — it holds bots.view and not bots.manage' => [OrgRole::KnowledgeManager, false],
    'analyst may NOT — reporting-only, and a playground turn spends provider quota' => [OrgRole::Analyst, false],
]);

it('proves the refused roles really can READ the bot, so the 403 is about the ACTION', function (OrgRole $role): void {
    // THE POSITIVE CONTROL FOR THE MATRIX ABOVE. Without it, both refusals are equally satisfied by
    // a route that denies everyone, or by a fixture whose bot the caller cannot reach at all — and
    // the claim "`bots.view` is not enough" would be untested.
    $t = playgroundTenantPair();

    $actor = User::factory()->recycle($t->a)->orgRole($role)->create([
        'email' => SpaSession::uniqueEmail('playground-read-'.$role->value),
    ]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson("/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}", spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $t->botA->id);
})->with([
    'knowledge_manager' => OrgRole::KnowledgeManager,
    'analyst' => OrgRole::Analyst,
]);

it('denies a member of another organization with a 403 and issues nothing', function (): void {
    $t = playgroundTenantPair();

    // POSITIVE CONTROL FIRST: org B's own admin CAN mint for org B's bot, so the refusal below is a
    // tenant boundary rather than a broken endpoint.
    SpaSession::establish(currentTest(), $t->actorB);

    currentTest()->postJson(playgroundPath($t->b->id, $t->botB->id), [], spaHeaders())
        ->assertStatus(201);

    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    // ORG A'S ADMIN, ADDRESSING ORG B'S ORGANIZATION SEGMENT. `org.member` runs BEFORE the policy
    // and re-reads `organization_users` from PostgreSQL — a session value is not evidence of
    // current membership.
    $response = currentTest()->postJson(playgroundPath($t->b->id, $t->botB->id), [], spaHeaders());

    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');

    $body = (string) $response->getContent();

    expect(str_contains($body, WidgetSessionService::PREFIX))->toBeFalse('a cross-org mint issued a token');
    expect(str_contains($body, $t->canary))->toBeFalse('a cross-org refusal echoed org B\'s content');
});

it('404s org B\'s bot addressed under org A, at BINDING time, before any policy runs', function (): void {
    $t = playgroundTenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    // POSITIVE CONTROL: org A's OWN bot mints on this exact route.
    currentTest()->postJson(playgroundPath($t->a->id, $t->botA->id), [], spaHeaders())
        ->assertStatus(201);

    // THE FOREIGN ID AND AN ID THAT NEVER EXISTED MUST BE INDISTINGUISHABLE. `->scopeBindings()`
    // resolves `{bot}` through `$organization->bots()`, so the row is never in memory — nothing
    // built from it (a relation query, a log line, a response-time difference) can leak before
    // authorization would have fired.
    //
    // THE REQUEST ID IS PINNED ACROSS BOTH CALLS, which is the same thing `toDenyAsNotFound()` does
    // for the public surfaces: `request_id` is in the envelope by design and is DIFFERENT per
    // request, so a byte comparison of two unpinned bodies fails on the one field that is supposed
    // to differ. `RequestId` adopts a bounded inbound value, and using one id for both is what makes
    // the rest of the body comparable at all.
    $headers = spaHeaders(['X-KB-Request-Id' => (string) Str::ulid()->toBase32()]);

    $foreign = currentTest()->postJson(playgroundPath($t->a->id, $t->botB->id), [], $headers);
    $unknown = currentTest()->postJson(playgroundPath($t->a->id, (string) Str::ulid()), [], $headers);

    $foreign->assertStatus(404);
    $unknown->assertStatus(404);

    expect($foreign->getContent())->toBe($unknown->getContent(), 'a foreign bot id is distinguishable from an unknown one');
    expect(str_contains((string) $foreign->getContent(), $t->canary))->toBeFalse();
});

it('denies a guest and a suspended member', function (): void {
    $t = playgroundTenantPair();

    // GUEST: 401, not 403. Nothing about the row is revealed because nothing about it was looked at.
    currentTest()->postJson(playgroundPath($t->a->id, $t->botA->id), [], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    // A SUSPENDED MEMBERSHIP IS NOT A MEMBERSHIP. The row still exists and the role is still
    // `owner`; `OrganizationUser::grants()` ANDs the ACTIVE status itself.
    $suspended = User::factory()->recycle($t->a)
        ->orgRole(OrgRole::Owner, MembershipStatus::Suspended)
        ->create(['email' => SpaSession::uniqueEmail('playground-suspended')]);

    SpaSession::establish(currentTest(), $suspended);

    currentTest()->postJson(playgroundPath($t->a->id, $t->botA->id), [], spaHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

// -------------------------------------------------------------------------------------------
// Property 5 — the two kinds cannot be used as each other, in either direction
// -------------------------------------------------------------------------------------------

it('makes the DISCRIMINATOR a stored field, not something the token string announces', function (): void {
    $fixture = chatFixture();

    $actor = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-discriminator'),
    ]);

    SpaSession::establish(currentTest(), $actor);

    $playground = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    $widget = chatSessionToken($fixture);

    // THE TWO TOKENS ARE THE SAME GRAMMAR: same prefix, same two ULID routing segments, same secret
    // length. A token whose SHAPE announced its authority would tell anyone who found one in a paste
    // which of the two they had — and would let an attacker who obtained one know what to try.
    $shape = static fn (string $token): array => [
        'prefix' => substr($token, 0, strlen(WidgetSessionService::PREFIX)),
        'segments' => count(explode('.', substr($token, strlen(WidgetSessionService::PREFIX)))),
        'secret_length' => strlen(substr($token, strrpos($token, '.') + 1)),
    ];

    expect($shape($playground))->toBe($shape($widget));

    // AND YET THEY RESOLVE TO DIFFERENT AUTHORITY, WHICH IS THE POINT: the difference is in the
    // record, not in the bearer.
    $resolver = app(WidgetSessionService::class);

    $resolvedPlayground = $resolver->resolve($playground);
    $resolvedWidget = $resolver->resolve($widget);

    expect($resolvedPlayground->actorType)->toBe(ActorType::User)
        ->and($resolvedPlayground->diagnostics)->toBeTrue()
        ->and($resolvedPlayground->userId)->toBe($actor->id);

    expect($resolvedWidget->actorType)->toBe(ActorType::AnonymousSession)
        ->and($resolvedWidget->diagnostics)->toBeFalse()
        ->and($resolvedWidget->userId)->toBeNull();

    // ── AND THE ALLOW-LIST AGREES WITH BOTH, WHICH IS THE PROPERTY `ClientEvents` ENCODES ──────
    //
    // Asserted through the class rather than only through a stream, because it is an AND of two
    // conjuncts and a stream test can only ever show the combined outcome. Either conjunct alone
    // must refuse.
    expect(ClientEvents::allows(ClientEvents::DIAGNOSTIC, $resolvedPlayground->actorType, $resolvedPlayground->diagnostics))
        ->toBeTrue();
    expect(ClientEvents::allows(ClientEvents::DIAGNOSTIC, $resolvedWidget->actorType, $resolvedWidget->diagnostics))
        ->toBeFalse();

    // NEITHER CONJUNCT ALONE IS ENOUGH — a caller that resolved the permission on one surface must
    // not be able to forward the trace on another, and a signed-in user who fails the permission
    // check must not receive it either.
    expect(ClientEvents::allows(ClientEvents::DIAGNOSTIC, ActorType::AnonymousSession, true))->toBeFalse();
    expect(ClientEvents::allows(ClientEvents::DIAGNOSTIC, ActorType::User, false))->toBeFalse();

    // AND THE OTHER THREE INTERNAL FRAMES STAY REFUSED TO THE PLAYGROUND TOO — `allows()` is not
    // widened, it is one name.
    foreach (['provider.usage', 'provider.fallback', 'heartbeat'] as $name) {
        expect(ClientEvents::allows($name, ActorType::User, true))->toBeFalse(
            "`{$name}` is forwardable to a playground actor",
        );
    }
});

it('does not let a playground credential outlive the permission behind it', function (): void {
    // THE REVOCATION STORY, AND IT IS WHAT MAKES A MINT-TIME-ONLY CHECK INSUFFICIENT. Neither a
    // token row nor a session value is evidence of CURRENT membership (laravel-sanctum-auth NN1):
    // both were written in the past.
    $fixture = chatFixture();

    $actor = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-revocation'),
    ]);

    SpaSession::establish(currentTest(), $actor);

    $token = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    // POSITIVE CONTROL: the credential works right now, on the runtime surface it was minted for.
    currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($token))->assertStatus(201);

    // DEMOTED — NOT REMOVED. An analyst is still an active member of this organization and still
    // holds `bots.view`, so a check that only re-read MEMBERSHIP would keep answering. It is the
    // PERMISSION that has to be re-read.
    OrganizationUser::query()
        ->where('organization_id', '=', $fixture->organization->id)
        ->where('user_id', '=', $actor->id)
        ->update(['role' => OrgRole::Analyst->value]);

    expect(currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($token)))
        ->toDenyAsNotFound('rt/v1');

    // AND A SUSPENDED MEMBERSHIP TOO, from a fresh credential — the other half of `grants()`.
    OrganizationUser::query()
        ->where('organization_id', '=', $fixture->organization->id)
        ->where('user_id', '=', $actor->id)
        ->update(['role' => OrgRole::Admin->value]);

    SpaSession::freshProcess();

    $second = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($second))->assertStatus(201);

    OrganizationUser::query()
        ->where('organization_id', '=', $fixture->organization->id)
        ->where('user_id', '=', $actor->id)
        ->update(['status' => MembershipStatus::Suspended->value]);

    expect(currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($second)))
        ->toDenyAsNotFound('rt/v1');
});

it('refuses a playground credential the moment its bot leaves the playground-reachable set', function (BotStatus $status): void {
    $fixture = chatFixture();

    $actor = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-status-'.$status->value),
    ]);

    SpaSession::establish(currentTest(), $actor);

    $token = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($token))->assertStatus(201);

    Bot::query()->withoutGlobalScopes()->whereKey($fixture->bot->id)->update(['status' => $status->value]);

    // ON THE NEXT REQUEST, not at the next TTL boundary. This is the same live re-read the widget's
    // credential gets against the origin allow-list.
    expect(currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($token)))
        ->toDenyAsNotFound('rt/v1');
})->with([
    'draft' => BotStatus::Draft,
    'paused' => BotStatus::Paused,
    'archived' => BotStatus::Archived,
]);

it('never lets one tenant\'s playground credential reach another tenant\'s conversation', function (): void {
    $a = chatFixture(origin: 'https://a.example');
    $b = chatFixture(origin: 'https://b.example');

    $actorA = User::factory()->recycle($a->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-cross-a'),
    ]);

    SpaSession::establish(currentTest(), $actorA);

    $tokenA = (string) currentTest()
        ->postJson(playgroundPath($a->organization->id, $a->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    $tokenB = chatSessionToken($b);

    $conversationB = (string) currentTest()
        ->postJson('/rt/v1/conversations', [], chatHeaders($tokenB))
        ->assertStatus(201)
        ->json('data.id');

    // POSITIVE CONTROL: org B's own session reads it.
    currentTest()->getJson("/rt/v1/conversations/{$conversationB}/messages", chatHeaders($tokenB))->assertOk();

    // THE REAL ASSERTION. A `user` actor is NOT a wildcard participant: the playground credential
    // carries an organization AND a bot out of its own record, and the participant predicate it
    // supplies is a `user_id` that org B's conversation does not carry.
    expect(currentTest()->getJson("/rt/v1/conversations/{$conversationB}/messages", chatHeaders($tokenA)))
        ->toDenyAsNotFound('rt/v1');

    expect(currentTest()->postJson("/rt/v1/conversations/{$conversationB}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Whose conversation is this?',
    ], chatHeaders($tokenA, 'text/event-stream')))->toDenyAsNotFound('rt/v1');
});

it('never lets one ADMIN read another admin\'s playground turns in the same organization', function (): void {
    // THE CHEAPER ATTACK AND THE ONE AN ORGANIZATION-ONLY CHECK MISSES ENTIRELY. Two administrators
    // of one organization are both legitimate and both pass every tenant predicate; only the
    // participant scope keeps their transcripts apart.
    $fixture = chatFixture();

    $first = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-peer-one'),
    ]);
    $second = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-peer-two'),
    ]);

    SpaSession::establish(currentTest(), $first);

    $tokenFirst = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    $conversation = (string) currentTest()
        ->postJson('/rt/v1/conversations', [], chatHeaders($tokenFirst))
        ->assertStatus(201)
        ->json('data.id');

    currentTest()->getJson("/rt/v1/conversations/{$conversation}/messages", chatHeaders($tokenFirst))
        ->assertOk();

    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $second);

    $tokenSecond = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    expect($tokenSecond)->not->toBe($tokenFirst, 'two mints produced the same token, so this test proves nothing');

    expect(currentTest()->getJson("/rt/v1/conversations/{$conversation}/messages", chatHeaders($tokenSecond)))
        ->toDenyAsNotFound('rt/v1');
});

it('refuses a session record whose kind is neither of the two, rather than picking a branch', function (): void {
    // FAIL CLOSED ON AN UNKNOWN DISCRIMINATOR. A branch chosen by a value nobody wrote is a branch
    // nobody reviewed — and the failure would be silent, because both branches return a working
    // session.
    $fixture = chatFixture();
    $token = chatSessionToken($fixture);
    $secret = substr($token, strrpos($token, '.') + 1);

    $key = 'sess:'.$fixture->organization->id.':'.$fixture->bot->id.':'.substr(hash('sha256', $secret), 0, 32);

    $resolver = app(WidgetSessionService::class);

    // POSITIVE CONTROL: it resolves before the record is tampered with.
    expect($resolver->resolve($token)->botId)->toBe($fixture->bot->id);

    \Illuminate\Support\Facades\Redis::connection('coordination')->hset($key, 'kind', 'superuser');

    expect(static fn () => $resolver->resolve($token))->toThrow(AuthenticationException::class);
});

it('cannot be used to submit feedback, which the widget token can', function (): void {
    $fixture = chatFixture();

    $actor = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-feedback'),
    ]);

    SpaSession::establish(currentTest(), $actor);

    $token = (string) currentTest()
        ->postJson(playgroundPath($fixture->organization->id, $fixture->bot->id), [], spaHeaders())
        ->assertStatus(201)
        ->json('data.token');

    $session = app(WidgetSessionService::class)->resolve($token);

    // THE ABILITY CAP, ASSERTED AT THE SOURCE. The endpoint's own refusal is the surface's ordinary
    // 404, which is indistinguishable from an unknown message id — correct, and therefore unable on
    // its own to say WHY it refused.
    expect($session->can('chat:send'))->toBeTrue()
        ->and($session->can('chat:read'))->toBeTrue()
        ->and($session->can('feedback:submit'))->toBeFalse();

    // AND THE WIDGET'S STILL CAN, so the asymmetry above is a decision rather than a broken mint.
    expect(app(WidgetSessionService::class)->resolve(chatSessionToken($fixture))->can('feedback:submit'))
        ->toBeTrue();
});

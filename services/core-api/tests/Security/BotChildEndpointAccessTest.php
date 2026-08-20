<?php

declare(strict_types=1);

use App\Enums\BotDomainStatus;
use App\Enums\OrgRole;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\BotFallbackEntry;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The bot CHILD endpoints — who may reach them, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| The origin allow-list and the starter questions, which are the first routes in this application
| with TWO scoped binding hops below the organization. That is what this file exists for, and it is
| the case no cross-tenant test can see:
|
|     LOSING THE BOT PREDICATE DOES NOT EXPOSE ANOTHER TENANT'S ROW. Dropping `->scopeBindings()`,
|     renaming a segment, or omitting `Bot $bot` from an action signature leaves
|     `#[ScopedBy(OrganizationScope::class)]` still appending the organization term — the backstop
|     doing its job. What is lost is the predicate that says WHICH BOT, so any allow-list entry of
|     any of this organization's bots resolves under any other bot's URL. Every cross-tenant
|     assertion in this repo passes against that defect, which is why "404s a child of a DIFFERENT
|     bot inside the SAME organization" is asserted on its own below.
|
| AND WHY IT MATTERS MORE HERE THAN ON THE PROVIDER CATALOGUE, which has the same shape: a
| `bot_domains` row is a PERMANENT GRANT that every downstream check AGREES with, because it has been
| told that this origin belongs to that bot. A promotion applied to the wrong bot's row is not a
| visible error — it is a widget that boots on a site the operator never authorised for it.
|
| THE POLICY MATRIX. Every write here goes through `BotPolicy::manageChildren()`, an ability that had
| NO CALL SITE until these routes landed. The three child models still have no policies of their own,
| so `Gate::authorize('update', $domain)` would silently DENY — no policy means deny — and a
| controller written that way would look correct and refuse everybody. The matrix is asserted PER
| ACTION rather than from a uniform dataset, for the reason `BotEndpointAccessTest` states: every
| role that holds `bots.manage` also holds `bots.view`, so a `view` silently mapped to `bots.manage`
| differs only for knowledge_manager and analyst.
|
| `Http::fake()` APPEARS NOWHERE, and cannot: pest-testing bans it under tests/Security outright.
| None of these endpoints calls the AI service.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a label
| passed there becomes a second needle, and `not` treats any failure as success — the expression
| passes unconditionally. That exact shape has already hidden a real tenant-id leak in this repo.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Every child endpoint as `[method, path template, body]`, with `{org}`, `{bot}` and `{child}` to
 * be substituted.
 *
 * ONE LIST, USED BY EVERY TEST BELOW, so an endpoint added to the route file without a line here is
 * an endpoint no isolation test covers. A per-test literal would let the ninth route ship
 * unasserted and nothing would say so.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function botChildEndpoints(): array
{
    return [
        'domains.index' => ['getJson', '/api/v1/organizations/{org}/bots/{bot}/domains', []],
        'domains.store' => ['postJson', '/api/v1/organizations/{org}/bots/{bot}/domains', ['origin' => 'https://probe.example']],
        'domains.update' => ['patchJson', '/api/v1/organizations/{org}/bots/{bot}/domains/{child}', ['status' => BotDomainStatus::Active->value]],
        'domains.destroy' => ['deleteJson', '/api/v1/organizations/{org}/bots/{bot}/domains/{child}', []],

        'questions.index' => ['getJson', '/api/v1/organizations/{org}/bots/{bot}/starter-questions', []],
        'questions.store' => ['postJson', '/api/v1/organizations/{org}/bots/{bot}/starter-questions', ['question' => 'Probe question?']],
        'questions.update' => ['patchJson', '/api/v1/organizations/{org}/bots/{bot}/starter-questions/{child}', ['question' => 'Probe renamed?']],
        'questions.destroy' => ['deleteJson', '/api/v1/organizations/{org}/bots/{bot}/starter-questions/{child}', []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function callBotChildEndpoint(string $method, string $path, array $body = []): TestResponse
{
    // `getJson()` takes no body argument, so the two spellings cannot be collapsed into one dynamic
    // call — and a GET that carried one would be silently dropped rather than refused.
    /** @var TestResponse<\Illuminate\Http\JsonResponse> $response */
    $response = $method === 'getJson'
        ? currentTest()->getJson($path, spaHeaders())
        : currentTest()->{$method}($path, $body, spaHeaders());

    return $response;
}

/**
 * A `bot_domains` origin carrying the canary, in a form the exact-origin grammar admits.
 *
 * The canary is `CANARY-<ULID>`; the grammar is lower-case only, so it is folded. That keeps the
 * value distinguishable per test while staying a legal origin — a fixture that used an illegal one
 * would fail at the database and the isolation assertion would never run.
 */
function canaryOrigin(string $canary): string
{
    return 'https://'.Str::lower($canary).'.example';
}

// ── the canary: org B's child rows never reach org A ─────────────────────────────────────────────

it('never returns another organization\'s allow-list or starter questions', function (): void {
    $t = tenantPair();

    $origin = canaryOrigin($t->canary);

    BotDomain::factory()->recycle($t->b)->recycle($t->botB)->active()->origin($origin)->create();
    BotStarterQuestion::factory()->recycle($t->b)->recycle($t->botB)
        ->at(0)->asking("Refunds are accepted for 30 days. {$t->canary}")->create();

    // Org A gets rows of its own, so "org A's response is empty" cannot be what makes the negative
    // assertion pass.
    BotDomain::factory()->recycle($t->a)->recycle($t->botA)->origin('https://alpha.example')->create();
    BotStarterQuestion::factory()->recycle($t->a)->recycle($t->botA)
        ->at(0)->asking('An entirely unrelated question?')->create();

    // POSITIVE CONTROL, FIRST (pest-testing NN2). Without these two lines every absence assertion
    // below also passes when the endpoint is broken and returns nothing at all — which is how an
    // isolation suite becomes decoration.
    SpaSession::establish(currentTest(), $t->actorB);

    $domainsForB = currentTest()->getJson(
        "/api/v1/organizations/{$t->b->id}/bots/{$t->botB->id}/domains",
        spaHeaders(),
    )->assertOk();

    $questionsForB = currentTest()->getJson(
        "/api/v1/organizations/{$t->b->id}/bots/{$t->botB->id}/starter-questions",
        spaHeaders(),
    )->assertOk();

    expect(str_contains((string) $domainsForB->getContent(), Str::lower($t->canary)))
        ->toBeTrue('the canary origin is not reaching the organization that OWNS it, so no absence assertion below proves anything');
    expect(str_contains((string) $questionsForB->getContent(), $t->canary))
        ->toBeTrue('the canary question is not reaching the organization that OWNS it');

    // AND NOW THE NEGATIVE, from a fresh session as org A's admin.
    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $domainsForA = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/domains",
        spaHeaders(),
    )->assertOk();

    $questionsForA = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/starter-questions",
        spaHeaders(),
    )->assertOk();

    $bodies = (string) $domainsForA->getContent().(string) $questionsForA->getContent();

    expect(str_contains($bodies, $t->canary))->toBeFalse('org B\'s child content reached org A');
    expect(str_contains($bodies, Str::lower($t->canary)))->toBeFalse('org B\'s canary origin reached org A');
    expect(str_contains($bodies, $t->botB->id))->toBeFalse('org B\'s bot id reached org A');
});

// ── route-model binding: two hops, and the second one is the interesting one ─────────────────────

it('404s a child of a DIFFERENT bot inside the SAME organization', function (string $family): void {
    // THE CASE NO CROSS-TENANT TEST CAN SEE. `#[ScopedBy(OrganizationScope::class)]` would still
    // hold if the bot predicate were lost, so this defect leaks nothing across tenants — it lets
    // any of an organization's own child rows resolve under any of its other bots' URLs. On the
    // allow-list that is a promotion applied to a bot the operator never authorised the origin for.
    $t = tenantPair();

    $mine = $family === 'domains'
        ? BotDomain::factory()->recycle($t->a)->recycle($t->botA)->origin('https://mine.example')->create()->id
        : BotStarterQuestion::factory()->recycle($t->a)->recycle($t->botA)->at(0)->create()->id;

    // A SECOND BOT IN THE SAME ORGANIZATION, with no child rows of its own.
    $sibling = Bot::factory()->recycle($t->a)->create(['slug' => 'sibling-bot']);

    SpaSession::establish(currentTest(), $t->actorA);

    [$method, $template, $payload] = botChildEndpoints()[$family.'.update'];

    // POSITIVE CONTROL FIRST: the row IS reachable under its OWN bot, so the 404 below is about the
    // parent rather than about the route being broken.
    callBotChildEndpoint(
        $method,
        str_replace(['{org}', '{bot}', '{child}'], [$t->a->id, $t->botA->id, $mine], $template),
        $payload,
    )->assertOk();

    foreach (['update', 'destroy'] as $action) {
        [$method, $template, $payload] = botChildEndpoints()[$family.'.'.$action];

        callBotChildEndpoint(
            $method,
            str_replace(['{org}', '{bot}', '{child}'], [$t->a->id, $sibling->id, $mine], $template),
            $payload,
        )
            ->assertStatus(404)
            ->assertJsonPath('error_class', 'authorization');
    }
})->with(['domains', 'questions']);

it('404s a foreign or unknown child on every route that takes one, before any policy runs', function (
    string $family,
    string $case,
): void {
    $t = tenantPair();

    $foreign = $case === 'another organization\'s row'
        ? ($family === 'domains'
            ? BotDomain::factory()->recycle($t->b)->recycle($t->botB)->origin('https://theirs.example')->create()->id
            : BotStarterQuestion::factory()->recycle($t->b)->recycle($t->botB)->at(0)->create()->id)
        : (string) Str::ulid();

    SpaSession::establish(currentTest(), $t->actorA);

    $bodies = [];

    foreach (['update', 'destroy'] as $action) {
        [$method, $template, $payload] = botChildEndpoints()[$family.'.'.$action];

        $response = callBotChildEndpoint(
            $method,
            str_replace(['{org}', '{bot}', '{child}'], [$t->a->id, $t->botA->id, $foreign], $template),
            $payload,
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');

        $bodies[] = (string) $response->getContent();
    }

    // THE ENUMERATION ORACLE IS CLOSED AT THE BODY AND NOT ONLY AT THE STATUS. Rendering
    // `authorization` as 404 is worthless if the body says which 404 it is: bootstrap/app.php
    // replaces the message with ONE CONSTANT per rendered status, so a denied row, a row in another
    // organization and a row that never existed are one response. `request_id` is the only field
    // that legitimately differs, so it is stripped before the comparison.
    $normalized = array_map(
        static fn (string $body): string => (string) preg_replace('/"request_id":"[^"]*"/', '"request_id":"*"', $body),
        $bodies,
    );

    expect(array_unique($normalized))->toHaveCount(1);
})->with(['domains', 'questions'])->with([
    'another organization\'s row',
    'a row that never existed',
]);

it('404s a foreign BOT on the collection routes, so the child list is unreachable too', function (
    string $family,
): void {
    // THE PARENT HOP. `{bot}` resolves through `$organization->bots()`, so org B's bot under org A's
    // organization is a 404 at BINDING time — before any policy is constructed and before either
    // row is in memory, which is what stops a response-time difference from being an oracle.
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    foreach (['index', 'store'] as $action) {
        [$method, $template, $payload] = botChildEndpoints()[$family.'.'.$action];

        callBotChildEndpoint(
            $method,
            str_replace(['{org}', '{bot}'], [$t->a->id, $t->botB->id], $template),
            $payload,
        )
            ->assertStatus(404)
            ->assertJsonPath('error_class', 'authorization');
    }
})->with(['domains', 'questions']);

// ── the role matrix, PER ACTION, through the endpoints ───────────────────────────────────────────

it('enforces bots.view on the reads and bots.manage on the writes, per action', function (
    string $action,
    OrgRole $role,
    bool $allowed,
): void {
    $t = tenantPair();

    $domain = BotDomain::factory()->recycle($t->a)->recycle($t->botA)
        ->origin('https://matrix.example')->create();
    $question = BotStarterQuestion::factory()->recycle($t->a)->recycle($t->botA)->at(0)->create();

    $actor = User::factory()->recycle($t->a)->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail('bot-child-'.$role->value)]);

    SpaSession::establish(currentTest(), $actor);

    [$method, $template, $payload] = botChildEndpoints()[$action];

    $child = str_starts_with($action, 'domains') ? $domain->id : $question->id;

    $response = callBotChildEndpoint(
        $method,
        str_replace(['{org}', '{bot}', '{child}'], [$t->a->id, $t->botA->id, $child], $template),
        $payload,
    );

    if ($allowed) {
        expect($response->getStatusCode())->toBeLessThan(300, "{$action} was denied for {$role->value}");

        return;
    }

    // 403 AND NOT 404, because this is an AUTHENTICATED ADMIN SURFACE and the caller is a member of
    // the organization that owns the row — admitting it exists tells them nothing they could not
    // already learn. The public runtime and SDK surfaces make the opposite call, and `error_class`
    // is `authorization` in both cases so nothing branches on the status.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');
})->with(function (): array {
    // ASSERTED PER ACTION, NEVER FROM A UNIFORM DATASET. Every role that holds `bots.manage` also
    // holds `bots.view`, so a read silently mapped to `bots.manage` produces identical answers for
    // owner and admin and differs only for knowledge_manager and analyst — the two rows a
    // hand-written dataset is most likely to under-cover, and the two whose `bots.view` grant is an
    // EXTENSION of the specification (ADR-056) rather than a reading of it.
    //
    // `Permission::BotsView` names "a bot's configuration, its origin allow-list, and its starter
    // questions" in as many words, so the reads below are the grant that sentence describes.
    $matrix = [
        'domains.index' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager, OrgRole::Analyst],
        'domains.store' => [OrgRole::Owner, OrgRole::Admin],
        'domains.update' => [OrgRole::Owner, OrgRole::Admin],
        'domains.destroy' => [OrgRole::Owner, OrgRole::Admin],
        'questions.index' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager, OrgRole::Analyst],
        'questions.store' => [OrgRole::Owner, OrgRole::Admin],
        'questions.update' => [OrgRole::Owner, OrgRole::Admin],
        'questions.destroy' => [OrgRole::Owner, OrgRole::Admin],
    ];

    $rows = [];

    foreach ($matrix as $action => $permitted) {
        foreach (OrgRole::cases() as $role) {
            $rows[$action.' / '.$role->value] = [$action, $role, in_array($role, $permitted, true)];
        }
    }

    return $rows;
});

// ── over-posting the ownership columns ───────────────────────────────────────────────────────────

it('cannot move a child row to another organization or another bot by over-posting', function (): void {
    // `organization_id` and `bot_id` are absent from every rule set, from every DTO and from both
    // models' `$fillable` — three layers, because a missing validation rule is one careless line
    // away from coming back. `bot_id` is the one peculiar to this surface: it is the ownership edge
    // INSIDE the tenant, and the composite foreign key cannot object to a move between two bots of
    // the same organization because both are legal targets.
    $t = tenantPair();

    $domain = BotDomain::factory()->recycle($t->a)->recycle($t->botA)
        ->origin('https://overpost.example')->create();
    $sibling = Bot::factory()->recycle($t->a)->create(['slug' => 'overpost-sibling']);

    SpaSession::establish(currentTest(), $t->actorA);

    currentTest()->patchJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/domains/{$domain->id}",
        [
            'status' => BotDomainStatus::Active->value,
            'organization_id' => $t->b->id,
            'bot_id' => $sibling->id,
            'origin' => 'https://elsewhere.example',
        ],
        spaHeaders(),
    )->assertOk();

    $reloaded = BotDomain::query()->withoutGlobalScopes()->whereKey($domain->id)->firstOrFail();

    expect($reloaded->organization_id)->toBe($t->a->id)
        ->and($reloaded->bot_id)->toBe($t->botA->id)
        ->and($reloaded->origin)->toBe('https://overpost.example')
        // The one field the request was actually entitled to move.
        ->and($reloaded->status)->toBe(BotDomainStatus::Active);
});

// ── the delete cascade, and the two collections it must not reach ────────────────────────────────

/**
 * One bot's three child collections, planted with values that name the bot they belong to.
 *
 * DISTINGUISHABLE PER BOT AND PER ORGANIZATION, because the assertion below counts survivors: two
 * bots whose origins were both `https://example.com` would make a row deleted from one and read
 * back from the other indistinguishable from a row that was never touched.
 *
 * @return array{domain: string, question: string, fallback: string}
 */
function plantChildCollections(Organization $organization, Bot $bot, string $label): array
{
    $connection = ProviderConnection::factory()->recycle($organization)
        ->create(['label' => $label.' key']);

    $model = ProviderModelEntry::factory()->recycle($organization)->recycle($connection)
        ->supporting(['text'])
        ->create(['model' => 'gpt-5.1', 'display_name' => $label.' chat row']);

    $domain = BotDomain::factory()->recycle($organization)->recycle($bot)
        ->origin('https://'.Str::lower($label).'.example')->create();

    $question = BotStarterQuestion::factory()->recycle($organization)->recycle($bot)
        ->at(0)->asking('What does '.$label.' cover?')->create();

    $fallback = BotFallbackEntry::factory()->recycle($organization)->recycle($bot)->recycle($model)
        ->at(0)->create();

    return ['domain' => $domain->id, 'question' => $question->id, 'fallback' => $fallback->id];
}

/**
 * Whether each of the three child rows still exists, read WITHOUT the global scopes.
 *
 * DELIBERATELY UNSCOPED, and it is the same call `BotEndpointAccessTest` makes for the same reason:
 * this assertion is about what is IN THE TABLE, not about what a request can see. Reading it
 * through the tenant scope would make "org B's rows survived" pass whenever the scope hid them —
 * which is the assertion inverted.
 *
 * @param  array{domain: string, question: string, fallback: string}  $ids
 * @return array{domain: bool, question: bool, fallback: bool}
 */
function childCollectionsExist(array $ids): array
{
    return [
        'domain' => BotDomain::query()->withoutGlobalScopes()->whereKey($ids['domain'])->exists(),
        'question' => BotStarterQuestion::query()->withoutGlobalScopes()->whereKey($ids['question'])->exists(),
        'fallback' => BotFallbackEntry::query()->withoutGlobalScopes()->whereKey($ids['fallback'])->exists(),
    ];
}

it('removes only the deleted bot\'s children, leaving a sibling bot\'s and another organization\'s', function (): void {
    /*
     * THE DELETE IS THE ONE WRITE THAT REACHES ROWS THE REQUEST NEVER NAMED. Every other endpoint in
     * this file touches one child row identified in the URL; DELETE …/bots/{bot} removes three whole
     * collections by predicate, in code, because all three reference `bots (organization_id, id)`
     * with ON DELETE RESTRICT and a bot with one origin would otherwise raise SQLSTATE 23503.
     *
     * A PREDICATE THAT LOSES ITS `bot_id` TERM STILL PASSES EVERY OTHER TEST IN THIS REPOSITORY.
     * The organization term keeps it inside one tenant, so no cross-tenant assertion anywhere moves;
     * the existing delete test in tests/Feature/BotCrudTest.php asserts its own bot's domain is gone
     * and that the OTHER bot's ROW survives, and neither notices that the other bot's CHILDREN went
     * with it. What that costs the operator is the whole allow-list of every other bot in the
     * organization — every widget on every site they run stops booting — from a request that
     * returned 200 and named one bot.
     *
     * WHAT THIS TEST CANNOT PROVE, stated rather than implied: the `organization_id` term in the
     * same predicate is UNOBSERVABLE from here, and no test can make it observable. `bot_id` is a
     * ULID primary key, so two organizations cannot share one, and dropping the organization term
     * changes the result set of no query that could ever run. It is written out in the repository
     * anyway — that layer's property is that it is correct on its own rather than correct because of
     * a constraint in another file — and it stays a review obligation.
     */
    $t = tenantPair();

    $doomed = Bot::factory()->recycle($t->a)->create(['name' => 'ALPHA doomed', 'slug' => 'alpha-doomed']);
    $sibling = Bot::factory()->recycle($t->a)->create(['name' => 'ALPHA sibling', 'slug' => 'alpha-sibling']);

    $doomedChildren = plantChildCollections($t->a, $doomed, 'DOOMED');
    $siblingChildren = plantChildCollections($t->a, $sibling, 'SIBLING');
    $foreignChildren = plantChildCollections($t->b, $t->botB, 'BRAVO');

    // POSITIVE CONTROL, FIRST (pest-testing NN2). All nine rows are really there, so "org B's
    // children survived" cannot be satisfied by a fixture that never created them — which is how
    // this test would go green against a delete that removed every child row in the table.
    expect(childCollectionsExist($doomedChildren))->toBe(['domain' => true, 'question' => true, 'fallback' => true])
        ->and(childCollectionsExist($siblingChildren))->toBe(['domain' => true, 'question' => true, 'fallback' => true])
        ->and(childCollectionsExist($foreignChildren))->toBe(['domain' => true, 'question' => true, 'fallback' => true]);

    SpaSession::establish(currentTest(), $t->actorA);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$doomed->id}",
        [],
        spaHeaders(),
    )->assertOk();

    // THE DELETE DID HAPPEN. Without this the three survival assertions below all pass against an
    // endpoint that deleted nothing at all.
    expect(childCollectionsExist($doomedChildren))
        ->toBe(['domain' => false, 'question' => false, 'fallback' => false], 'the deleted bot kept its children');

    expect(childCollectionsExist($siblingChildren))->toBe(
        ['domain' => true, 'question' => true, 'fallback' => true],
        'deleting one bot removed a SIBLING bot\'s children — same organization, different bot, so no '
        .'cross-tenant assertion in this repository would have caught it',
    );

    expect(childCollectionsExist($foreignChildren))->toBe(
        ['domain' => true, 'question' => true, 'fallback' => true],
        'deleting org A\'s bot removed org B\'s children',
    );

    // AND ORG B'S BOT ITSELF. A deletion test needs a surviving second tenant
    // (kb-deletion-and-verification), and the bot row is the parent all three collections hang off.
    expect(Bot::query()->withoutGlobalScopes()->whereKey($t->botB->id)->exists())->toBeTrue()
        ->and(Bot::query()->withoutGlobalScopes()->whereKey($sibling->id)->exists())->toBeTrue();
});

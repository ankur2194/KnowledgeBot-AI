<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\Bot;
use App\Models\ProviderConnection;
use App\Models\User;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The bot ENDPOINTS — who may reach them, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| THE POLICY MATRIX ITSELF IS tests/Security/BotAccessTest.php, which exercises the Gate directly
| and predates these routes. That file names, in its own header, exactly what it cannot cover and
| must gain when the routes land: ROUTE-MODEL BINDING — a foreign `{bot}` under `{organization}`
| 404ing at BINDING time, before any policy is constructed — and CHECK 5, the entity-status
| refusals `OrgScopedPolicy::permit()` has no argument position for. THIS FILE IS THAT, plus the
| matrix re-asserted THROUGH the endpoints, because a correct policy reached by no `Gate::authorize`
| call is a correct policy that authorizes nothing.
|
| The BEHAVIOUR half is tests/Feature/BotCrudTest.php. The two are separate files because they fail
| for different reasons: a red test here is a tenant or privilege boundary, a red test there is a
| bug in an endpoint.
|
| `Http::fake()` APPEARS NOWHERE, and cannot: pest-testing bans it under tests/Security outright.
| None of the five endpoints calls the AI service — configuring a bot computes nothing — so there is
| nothing to fake and nothing that would silently pass because a faked response short-circuited a
| real code path.
|
| EVERY FIXTURE IS `tenantPair()`, THE SHARED HARNESS, and every absence assertion carries a
| POSITIVE CONTROL asserted first. A one-organization fixture passes every test below against code
| with no tenant filter at all, and an absence assertion with no control passes the moment the
| endpoint breaks and returns nothing.
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
 * Every bot endpoint as `[method, path template]`, with `{org}` and `{bot}` to be substituted.
 *
 * ONE LIST, USED BY EVERY TEST BELOW, so an endpoint added to the route file without a line here is
 * an endpoint no isolation test covers. A per-test literal would let the sixth route ship
 * unasserted and nothing would say so.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function botEndpoints(): array
{
    return [
        'index' => ['getJson', '/api/v1/organizations/{org}/bots', []],
        'store' => ['postJson', '/api/v1/organizations/{org}/bots', ['name' => 'Probe', 'slug' => 'probe-bot']],
        'show' => ['getJson', '/api/v1/organizations/{org}/bots/{bot}', []],
        'update' => ['patchJson', '/api/v1/organizations/{org}/bots/{bot}', ['name' => 'Probe rename']],
        'destroy' => ['deleteJson', '/api/v1/organizations/{org}/bots/{bot}', []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function callBotEndpoint(string $method, string $path, array $body = []): TestResponse
{
    // `getJson()` takes no body argument, so the two spellings cannot be collapsed into one
    // dynamic call — and a GET that carried one would be silently dropped rather than refused.
    /** @var TestResponse<\Illuminate\Http\JsonResponse> $response */
    $response = $method === 'getJson'
        ? currentTest()->getJson($path, spaHeaders())
        : currentTest()->{$method}($path, $body, spaHeaders());

    return $response;
}

// ── the canary: org B's content never reaches org A ──────────────────────────────────────────────

it('never returns another organization\'s bot content on the list or the detail', function (): void {
    $t = tenantPair();

    // POSITIVE CONTROL, FIRST (pest-testing NN2). The canary lives in ORG B's bot welcome message,
    // and without this line every absence assertion below also passes when the endpoint is broken
    // and returns nothing at all — which is how an isolation suite becomes decoration.
    SpaSession::establish(currentTest(), $t->actorB);

    $forB = currentTest()->getJson("/api/v1/organizations/{$t->b->id}/bots", spaHeaders());

    $forB->assertOk();

    expect(str_contains((string) $forB->getContent(), $t->canary))
        ->toBeTrue('the canary is not reaching the organization that OWNS it, so no absence assertion below proves anything');

    // AND NOW THE NEGATIVE, from a fresh session as org A's admin.
    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $list = currentTest()->getJson("/api/v1/organizations/{$t->a->id}/bots", spaHeaders());

    $list->assertOk();

    $body = (string) $list->getContent();

    expect(str_contains($body, $t->canary))->toBeFalse('org B\'s bot content reached org A\'s list');
    expect(str_contains($body, $t->botB->id))->toBeFalse('org B\'s bot id reached org A\'s list');
    expect(str_contains($body, $t->botB->public_bot_id))->toBeFalse('org B\'s public bot id reached org A\'s list');

    // AND THE DETAIL ENDPOINT, addressed with org A's organization and org B's bot. It 404s at
    // BINDING time — `->scopeBindings()` resolves `{bot}` through `$organization->bots()` — so the
    // row is never in memory and nothing built from it (a relation query, a log line, a cache key,
    // a response-time difference) can leak before authorization would have fired.
    $detail = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botB->id}",
        spaHeaders(),
    );

    $detail->assertStatus(404);

    expect(str_contains((string) $detail->getContent(), $t->canary))->toBeFalse();
});

it('does not leak another organization\'s bot through the free-text filter', function (): void {
    $t = tenantPair();

    // A TERM THAT MATCHES A BOT IN BOTH ORGANIZATIONS. The filter is a grouped OR, and `AND` binds
    // tighter than `OR` in SQL — so a repository that chained `orWhere` onto the tenant predicate
    // without the closure group produces `organization_id = ? AND name ILIKE ? OR slug ILIKE ?`,
    // whose second disjunct carries NO TENANT PREDICATE. A filter test whose term matches only one
    // organization cannot see that, and the defect reads as a formatting choice in review.
    Bot::query()->withoutGlobalScopes()->whereKey($t->botA->id)->update(['slug' => 'shared-term-alpha']);
    Bot::query()->withoutGlobalScopes()->whereKey($t->botB->id)->update(['slug' => 'shared-term-bravo']);

    SpaSession::establish(currentTest(), $t->actorB);

    // POSITIVE CONTROL: the term really does match org B's bot, from org B's own session.
    currentTest()->getJson("/api/v1/organizations/{$t->b->id}/bots?filter=shared-term", spaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data.bots')
        ->assertJsonPath('data.bots.0.id', $t->botB->id);

    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots?filter=shared-term",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonCount(1, 'data.bots')
        ->assertJsonPath('data.bots.0.id', $t->botA->id)
        ->assertJsonPath('data.meta.total', 1);

    expect(str_contains((string) $response->getContent(), $t->botB->id))
        ->toBeFalse('the filter\'s OR group lost the tenant predicate');
});

// ── route-model binding: a foreign id is indistinguishable from one that never existed ───────────

it('404s a foreign or unknown bot on every route that takes one, before any policy runs', function (string $case): void {
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    // POSITIVE CONTROL FIRST: org A's OWN bot is reachable on all three, so the 404s below are
    // about the identifier rather than about the routes being broken.
    foreach (['show', 'update', 'destroy'] as $action) {
        [$method, $template, $payload] = botEndpoints()[$action];

        callBotEndpoint(
            $method,
            str_replace(['{org}', '{bot}'], [$t->a->id, $t->botA->id], $template),
            $payload,
        )->assertOk();

        // `destroy` really does delete, so re-create the row for the next iteration rather than
        // asserting against a bot that is no longer there.
        if ($action === 'destroy') {
            $t = tenantPair();
            SpaSession::freshProcess();
            SpaSession::establish(currentTest(), $t->actorA);
        }
    }

    $foreign = $case === 'another organization\'s bot' ? $t->botB->id : (string) Str::ulid();

    $bodies = [];

    foreach (['show', 'update', 'destroy'] as $action) {
        [$method, $template, $payload] = botEndpoints()[$action];

        $response = callBotEndpoint(
            $method,
            str_replace(['{org}', '{bot}'], [$t->a->id, $foreign], $template),
            $payload,
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');

        $bodies[] = (string) $response->getContent();
    }

    // THE ENUMERATION ORACLE IS CLOSED AT THE BODY AND NOT ONLY AT THE STATUS. Rendering
    // `authorization` as 404 is worthless if the body says which 404 it is: bootstrap/app.php
    // replaces the message with ONE CONSTANT per rendered status, so a denied row, a row in another
    // organization and a row that never existed are one response. The `request_id` is the only
    // field that legitimately differs, so it is stripped before the comparison.
    $normalized = array_map(
        static fn (string $body): string => (string) preg_replace('/"request_id":"[^"]*"/', '"request_id":"*"', $body),
        $bodies,
    );

    expect(array_unique($normalized))->toHaveCount(1);
})->with([
    'another organization\'s bot',
    'a bot that never existed',
]);

it('renders the same 404 for a foreign bot as for a path with no route at all', function (): void {
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    $foreign = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botB->id}",
        spaHeaders(),
    );

    // A REAL REQUEST AT A PATH THAT CERTAINLY HAS NO ROUTE, rather than a copy of the expected
    // strings: comparing against a literal would go stale the day the constant moves, and the
    // property being asserted is that the two are INDISTINGUISHABLE rather than that either equals
    // some particular sentence.
    $noRoute = currentTest()->getJson('/api/v1/there-is-no-such-path-'.Str::ulid(), spaHeaders());

    $foreign->assertStatus(404);
    $noRoute->assertStatus(404);

    /**
     * The comparable part of a deny body: everything except the one field that legitimately
     * differs between two requests.
     *
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $r
     */
    $strip = static fn (TestResponse $r): string => (string) preg_replace(
        '/"request_id":"[^"]*"/',
        '"request_id":"*"',
        (string) $r->getContent(),
    );

    expect($strip($foreign))->toBe($strip($noRoute));
});

// ── the role matrix, PER ACTION, through the endpoints ───────────────────────────────────────────

it('enforces bots.view on the reads and bots.manage on the writes, per action', function (
    string $action,
    OrgRole $role,
    bool $allowed,
): void {
    $t = tenantPair();

    $actor = User::factory()->recycle($t->a)->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail('bot-endpoint-'.$role->value)]);

    SpaSession::establish(currentTest(), $actor);

    [$method, $template, $payload] = botEndpoints()[$action];

    $response = callBotEndpoint(
        $method,
        str_replace(['{org}', '{bot}'], [$t->a->id, $t->botA->id], $template),
        $payload,
    );

    if ($allowed) {
        expect($response->getStatusCode())->toBeLessThan(300, "{$action} was denied for {$role->value}");

        return;
    }

    // 403 AND NOT 404, because this is an AUTHENTICATED ADMIN SURFACE and the caller is a member of
    // the organization that owns the row — admitting the row exists tells them nothing they could
    // not already learn. The public runtime and SDK surfaces make the opposite call, and
    // `error_class` is `authorization` in both cases so nothing branches on the status.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');
})->with(function (): array {
    // THE MATRIX IS ASSERTED PER ACTION, NEVER FROM A UNIFORM DATASET. Every role that holds
    // `bots.manage` ALSO holds `bots.view`, so a `view` silently mapped to `bots.manage` produces
    // identical answers for owner and admin and differs only for knowledge_manager and analyst —
    // the two rows a hand-written dataset is most likely to under-cover, and the two whose grants
    // are an EXTENSION of the specification (§6.4 and §6.5 mention bots in neither direction)
    // rather than a reading of it.
    $matrix = [
        'index' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager, OrgRole::Analyst],
        'show' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager, OrgRole::Analyst],
        'store' => [OrgRole::Owner, OrgRole::Admin],
        'update' => [OrgRole::Owner, OrgRole::Admin],
        'destroy' => [OrgRole::Owner, OrgRole::Admin],
    ];

    $rows = [];

    foreach ($matrix as $action => $permitted) {
        foreach (OrgRole::cases() as $role) {
            $rows[$action.' / '.$role->value] = [$action, $role, in_array($role, $permitted, true)];
        }
    }

    return $rows;
});

// ── the instruction projection: `bots.view` is not `bots.manage` ─────────────────────────────────

/**
 * The two operator-authored prompt strings, distinct so a projection that leaked one and hid the
 * other cannot pass. Both are file-scope constants because Pest declares them globally and a name
 * another test file already uses is a redeclaration fatal in a full run and only in a full run.
 */
const BOT_SYSTEM_INSTRUCTION_PROBE = 'PROMPTPROBE-SYSTEM answer only from the sealed handbook.';
const BOT_ANSWER_STYLE_PROBE = 'PROMPTPROBE-STYLE reply in two sentences, never more.';

it('renders the instruction fields only to a caller holding bots.manage, on show AND on index', function (
    OrgRole $role,
    bool $visible,
): void {
    // ASSERTED PER ROLE AND NOT FROM A UNIFORM FIXTURE, which is the whole point of this test: the
    // defect it exists to catch was invisible to every fixture that used one actor, because the
    // actor was always an owner or an admin. `bots.view` is the WIDEST permission in the catalog —
    // ADR-056 extends it to knowledge_manager and analyst — and an ANALYST holds it and NOTHING
    // ELSE, so the narrowest role in the product was reading every bot's full system prompt off
    // `GET …/bots?per_page=100`. A single-role fixture cannot fail this test.
    $t = tenantPair();

    Bot::query()->withoutGlobalScopes()->whereKey($t->botA->id)->update([
        'system_instruction' => BOT_SYSTEM_INSTRUCTION_PROBE,
        'answer_style_instruction' => BOT_ANSWER_STYLE_PROBE,
    ]);

    $actor = User::factory()->recycle($t->a)->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail('bot-instruction-'.$role->value)]);

    SpaSession::establish(currentTest(), $actor);

    $show = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}",
        spaHeaders(),
    );

    $index = currentTest()->getJson("/api/v1/organizations/{$t->a->id}/bots", spaHeaders());

    // BOTH READS SUCCEED FOR EVERY ROLE. The projection narrows a FIELD, not the endpoint: all four
    // roles hold `bots.view` and a 403 here would be a different — and wrong — fix for the same
    // finding. Without this line every absence assertion below also passes when the read broke.
    $show->assertOk()->assertJsonPath('data.id', $t->botA->id);
    $index->assertOk()->assertJsonCount(1, 'data.bots')
        ->assertJsonPath('data.bots.0.id', $t->botA->id);

    $expectedSystem = $visible ? BOT_SYSTEM_INSTRUCTION_PROBE : null;
    $expectedStyle = $visible ? BOT_ANSWER_STYLE_PROBE : null;

    $show->assertJsonPath('data.system_instruction', $expectedSystem)
        ->assertJsonPath('data.answer_style_instruction', $expectedStyle);

    // AND THE LIST, WHICH IS THE ENDPOINT THAT MADE THE DISCLOSURE CHEAP. `BotCollectionResource`
    // maps the item resource per row, so a projection wired only into `show` would look fixed and
    // leak a hundred prompts per request.
    $index->assertJsonPath('data.bots.0.system_instruction', $expectedSystem)
        ->assertJsonPath('data.bots.0.answer_style_instruction', $expectedStyle);

    // THE KEYS ARE PRESENT EITHER WAY, asserted separately because `assertJsonPath(..., null)` is
    // satisfied by an ABSENT key just as well as by a null one — `data_get()` returns null for
    // both. Dropping a key would change the response SHAPE by role, which is exactly what
    // `packages/contracts/src/resources/bots.ts` cannot absorb; a null costs no contract change
    // because both fields are already typed nullable there.
    /** @var array<string, mixed> $detail */
    $detail = (array) $show->json('data');
    /** @var array<string, mixed> $row */
    $row = (array) $index->json('data.bots.0');

    foreach (['system_instruction', 'answer_style_instruction'] as $field) {
        expect(array_key_exists($field, $detail))->toBeTrue("`{$field}` is missing from the detail body");
        expect(array_key_exists($field, $row))->toBeTrue("`{$field}` is missing from the list row");
    }

    if ($visible) {
        return;
    }

    // AND THE STRINGS ARE ABSENT FROM THE RAW BODIES, not merely null at the documented path. A
    // projection that nulled the field and echoed the same text somewhere else — a `meta` block, a
    // future `_debug` key — would satisfy every assertion above.
    foreach (['detail' => $show, 'list' => $index] as $label => $response) {
        $body = (string) $response->getContent();

        expect(str_contains($body, BOT_SYSTEM_INSTRUCTION_PROBE))
            ->toBeFalse("the system instruction reached a {$role->value} on the {$label} response");
        expect(str_contains($body, BOT_ANSWER_STYLE_PROBE))
            ->toBeFalse("the answer-style instruction reached a {$role->value} on the {$label} response");
    }
})->with(function (): array {
    // EVERY ROLE, WITH ITS EXPECTED VERDICT CARRIED ON THE ROW rather than split into two datasets,
    // so a role that vanished from the catalog fails tests/Unit/RolePermissionMatrixTest.php's
    // totality check instead of quietly shrinking this one. The two `true` rows are the positive
    // control for the two `false` rows: without them, a resource that nulled the fields for
    // EVERYBODY would pass.
    $visible = [OrgRole::Owner, OrgRole::Admin];

    $rows = [];

    foreach (OrgRole::cases() as $role) {
        $rows[$role->value] = [$role, in_array($role, $visible, true)];
    }

    return $rows;
});

it('still returns the stored instruction to the caller who just wrote it', function (): void {
    // THE WRITE PATHS PASS `withInstructions: true` AS A LITERAL, on the ground that
    // `Gate::authorize('createBot'|'update', …)` has already proved `bots.manage` one line above.
    // That reasoning is sound and invisible, so it is asserted: an echo that came back nulled would
    // make the console's edit form show an empty prompt immediately after a successful save.
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    currentTest()->postJson(
        "/api/v1/organizations/{$t->a->id}/bots",
        [
            'name' => 'Instruction echo',
            'slug' => 'instruction-echo',
            'system_instruction' => BOT_SYSTEM_INSTRUCTION_PROBE,
        ],
        spaHeaders(),
    )
        ->assertStatus(201)
        ->assertJsonPath('data.system_instruction', BOT_SYSTEM_INSTRUCTION_PROBE);

    currentTest()->patchJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}",
        ['answer_style_instruction' => BOT_ANSWER_STYLE_PROBE],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.answer_style_instruction', BOT_ANSWER_STYLE_PROBE);
});

it('denies an owner of ANOTHER organization on every bot route', function (string $action): void {
    $t = tenantPair();

    // ORG B'S ADMIN, ADDRESSING ORG A'S ORGANIZATION SEGMENT. This is the "admin of some
    // organization" bug in its endpoint form: the role read is true, and the only thing that makes
    // it a denial is that membership is resolved from THE RECORD'S organization.
    SpaSession::establish(currentTest(), $t->actorB);

    [$method, $template, $payload] = botEndpoints()[$action];

    $response = callBotEndpoint(
        $method,
        str_replace(['{org}', '{bot}'], [$t->a->id, $t->botA->id], $template),
        $payload,
    );

    // 403 FROM `org.member`, WHICH RUNS BEFORE THE POLICY AND RE-READS `organization_users` FROM
    // POSTGRESQL. Neither a session value nor a token row is evidence of CURRENT membership.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');

    expect(str_contains((string) $response->getContent(), $t->canary))->toBeFalse();
})->with(['index', 'store', 'show', 'update', 'destroy']);

it('denies a suspended member and an unauthenticated caller on every bot route', function (string $action): void {
    $t = tenantPair();

    [$method, $template, $payload] = botEndpoints()[$action];
    $path = str_replace(['{org}', '{bot}'], [$t->a->id, $t->botA->id], $template);

    // GUEST: 401, not 403. Nothing about the row is revealed, because nothing about the row was
    // looked at.
    callBotEndpoint($method, $path, $payload)
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    // A SUSPENDED MEMBERSHIP IS NOT A MEMBERSHIP. The row still exists, the role is still `owner`,
    // and `org.member` re-reads it on every request — which is what makes a suspension take effect
    // on the very next call rather than when the session expires.
    $suspended = User::factory()->recycle($t->a)
        ->orgRole(OrgRole::Owner, MembershipStatus::Suspended)
        ->create(['email' => SpaSession::uniqueEmail('bot-endpoint-suspended')]);

    SpaSession::establish(currentTest(), $suspended);

    callBotEndpoint($method, $path, $payload)
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
})->with(['index', 'store', 'show', 'update', 'destroy']);

// ── no credential, in any form, from any of the five ─────────────────────────────────────────────

it('renders no provider credential from a bot whose connection\'s key really was sealed', function (): void {
    $t = tenantPair();

    // A REAL SEALED CREDENTIAL ON THE CONNECTION THIS BOT NAMES. The factory encrypts
    // FIXTURE_CREDENTIAL for real, so the plaintext genuinely exists in the row the bot points at —
    // without that, "the plaintext is absent" is satisfied by a fixture that never had one.
    $connection = ProviderConnection::factory()->recycle($t->a)->create(['label' => 'ALPHA sealed key']);

    Bot::query()->withoutGlobalScopes()->whereKey($t->botA->id)
        ->update(['provider_connection_id' => $connection->id]);

    expect($connection->last_four)->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    SpaSession::establish(currentTest(), $t->actorA);

    foreach (['index' => "/api/v1/organizations/{$t->a->id}/bots",
        'show' => "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}"] as $label => $path) {
        $response = currentTest()->getJson($path, spaHeaders());

        $response->assertOk();

        $body = (string) $response->getContent();

        // POSITIVE CONTROL FOR THE READ ITSELF: the connection reference IS rendered, so the
        // absences below are about the credential and not about an endpoint that returned nothing.
        expect(str_contains($body, $connection->id))
            ->toBeTrue("{$label} did not render the connection reference, so nothing below is a real absence");

        expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse("the plaintext provider credential reached the bot {$label} response");
        expect(str_contains($body, 'last_four'))->toBeFalse();
        expect(str_contains($body, 'masked_key'))->toBeFalse();
        expect(str_contains($body, 'credential'))->toBeFalse();
    }
});

it('never lets a request body move a bot to another organization', function (): void {
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    // OVER-POSTING A TENANT KEY IS AN AUTHORIZATION BUG WITH A 200 RESPONSE, which is why it is
    // asserted at the ROW rather than at the status. `organization_id` is absent from every rule
    // set and from `BotEdit::WRITABLE`, so the FormRequest drops it before a DTO could carry one
    // and `Model::shouldBeStrict()` never sees it.
    currentTest()->patchJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}",
        [
            'name' => 'ALPHA still',
            'organization_id' => $t->b->id,
            'public_bot_id' => $t->botB->public_bot_id,
            'retrieval_configuration_version' => 500,
        ],
        spaHeaders(),
    )->assertOk();

    $row = Bot::query()->withoutGlobalScopes()->findOrFail($t->botA->id);

    expect($row->organization_id)->toBe($t->a->id)
        // AND THE PUBLIC TOKEN IS UNMOVED. A client that could change it would break every live
        // embed on the customer's own site with a 200 — and one that could SET it could collide
        // with another organization's token, which the global unique index would refuse, turning
        // the column into an existence oracle over the whole platform.
        ->and($row->public_bot_id)->toBe($t->botA->public_bot_id)
        ->and($row->retrieval_configuration_version)->toBe(1);

    // ORG B GAINED NOTHING. Counted with `withoutGlobalScopes()` DELIBERATELY: this assertion is
    // made from a test process whose ambient TenantContext is org A's (or unset), and
    // `#[ScopedBy(OrganizationScope::class)]` fails CLOSED — it appends `1 = 0` with no bound
    // context — so a scoped count here would answer 0 whether the row moved or not and the
    // assertion would be a tautology in the direction that hides the bug.
    expect(
        Bot::query()->withoutGlobalScopes()->where('organization_id', '=', $t->b->id)->count(),
    )->toBe(1);
});

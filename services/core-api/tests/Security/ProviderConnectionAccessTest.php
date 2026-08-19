<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Factories\ProviderConnectionFactory;
use Database\Factories\UserFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Provider connections — who may reach them, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| THE BEHAVIOUR IS tests/Feature/ProviderConnectionResourceTest.php. This file asserts only the
| access rules, and it is a separate file because the two fail for different reasons: a red test
| here is a tenant or privilege boundary, a red test there is a bug in an endpoint.
|
| `Http::fake()` APPEARS NOWHERE IN THIS FILE, and cannot: pest-testing bans it under tests/Security
| outright. None of the five endpoints under test calls the AI service — only `store` computes an
| embedding readiness — so there is nothing to fake and nothing that would silently pass because a
| faked response short-circuited a real code path.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS with a connection in each, and every absence assertion carries
| a POSITIVE CONTROL asserted first. A one-organization fixture passes every test below against code
| with no tenant filter at all, and an absence assertion with no control passes the moment the
| endpoint breaks and returns nothing.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a
| "label" passed there becomes a second needle, and `not` treats any failure as success — the
| expression passes unconditionally. That exact shape has already hidden a real tenant-id leak in
| this repo (tests/Contract/OpenApiDocumentTest.php:40-46).
|
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() is the intended home for this pair and
| throws by design until the Bot and KnowledgeSource factories exist.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Two organizations, one connection each, distinguishable by label and by vendor.
 *
 * `->recycle($org)` on every factory. ProviderConnectionFactory refuses to run without a recycled
 * organization precisely because a connection minted into a THIRD organization is the failure that
 * makes an isolation test pass with the tenant filter deleted.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     connectionA: ProviderConnection, connectionB: ProviderConnection,
 * }
 */
function providerAccessPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Access Org ALPHA', 'slug' => 'access-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Access Org BRAVO', 'slug' => 'access-bravo']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Alpha', 'email' => SpaSession::uniqueEmail('access-owner-alpha')]),
        'ownerB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Bravo', 'email' => SpaSession::uniqueEmail('access-owner-bravo')]),
        'connectionA' => ProviderConnection::factory()->recycle($orgA)
            ->provider(Provider::OpenAI)->create(['label' => 'ALPHA production key']),
        'connectionB' => ProviderConnection::factory()->recycle($orgB)
            ->provider(Provider::Anthropic)->create(['label' => 'BRAVO production key']),
    ];
}

/**
 * The five routes under test, as (verb, path suffix, body) triples.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function providerRoutes(): array
{
    return [
        'index' => ['GET', '', []],
        'show' => ['GET', '/{id}', []],
        'update' => ['PATCH', '/{id}', ['label' => 'renamed']],
        'destroy' => ['DELETE', '/{id}', []],
        'rotate' => ['PUT', '/{id}/credential', [
            'current_password' => UserFactory::PASSWORD,
            'credential' => 'kb-access-test-credential-DO-NOT-LOG-w4k2',
        ]],
    ];
}

/**
 * Fire one of providerRoutes() at a connection.
 *
 * @param  array{0: string, 1: string, 2: array<string, mixed>}  $route
 * @return TestResponse<JsonResponse>
 */
function callProviderRoute(string $organizationId, string $connectionId, array $route): TestResponse
{
    [$verb, $suffix, $body] = $route;

    $url = "/api/v1/organizations/{$organizationId}/provider-connections"
        .str_replace('{id}', $connectionId, $suffix);

    return match ($verb) {
        'GET' => currentTest()->getJson($url, spaHeaders()),
        'PATCH' => currentTest()->patchJson($url, $body, spaHeaders()),
        'DELETE' => currentTest()->deleteJson($url, $body, spaHeaders()),
        default => currentTest()->putJson($url, $body, spaHeaders()),
    };
}

// ── the {organization} segment: 403, on the admin surface, for every verb ────────────────────────

it('403s a member of one organization who addresses another, on every provider route', function (
    string $route,
): void {
    $fixture = providerAccessPair();

    [$verb, $suffix, $body] = providerRoutes()[$route];

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // "Owner of SOME organization" is the cross-tenant bug, and this is the layer that stops it:
    // App\Http\Middleware\TenantContext RE-READS `organization_users` for the {organization}
    // SEGMENT and throws before route binding and before any policy. 403 and not 404 is correct on
    // the admin surface — a member is entitled to know an organization exists — and
    // tests/Security/DenyOracleTest.php exists to keep it at 403 so its public 404 arm cannot go
    // vacuous.
    $response = callProviderRoute($fixture['orgB']->id, $fixture['connectionB']->id, [$verb, $suffix, $body]);

    $response->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization')
        ->assertJsonPath('message', 'This action is not permitted.');

    // NOTHING OF ORG B'S IS IN THE DENIAL BODY. A 403 that named the label or the vendor would
    // hand the caller the record it just refused them.
    $content = (string) $response->getContent();

    expect(str_contains($content, 'BRAVO'))->toBeFalse('the denial body carried org B\'s label');
    expect(str_contains($content, $fixture['connectionB']->id))->toBeFalse('the denial body carried org B\'s id');

    // AND IT DID NOT ACT ANYWAY. The write verbs are the ones where a status assertion alone is
    // insufficient: a 403 rendered after the service ran looks identical from the outside.
    $survivor = ProviderConnection::query()->withoutGlobalScopes()->find($fixture['connectionB']->id);

    expect($survivor)->not->toBeNull("[{$route}/{$verb}] deleted another organization's connection anyway");
    expect($survivor?->label)->toBe('BRAVO production key', "[{$route}/{$verb}] edited it anyway");
    expect($survivor?->credential_version)->toBe(1, "[{$route}/{$verb}] rotated its credential anyway");
})
    // THE DATASET CARRIES A NAME, NOT THE TRIPLE. A dataset row of `[string, string, array]` would
    // give the test closure an untyped `array` parameter, which PHPStan reports at level 8 as an
    // iterable with no value type and which no docblock can annotate cleanly on a closure argument.
    // Looking the triple up by name inside the body keeps every closure parameter a scalar.
    ->with(fn (): array => array_keys(providerRoutes()));

it('serves org B\'s owner their own connection, which is the control for every 403 above', function (): void {
    $fixture = providerAccessPair();

    SpaSession::establish(currentTest(), $fixture['ownerB']);

    // WITHOUT THIS TEST the file above passes against a route that 403s everybody, including the
    // organization that owns the row — which is not isolation, it is an outage.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgB']->id}/provider-connections",
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonCount(1, 'data.connections')
        ->assertJsonPath('data.connections.0.id', $fixture['connectionB']->id)
        ->assertJsonPath('data.connections.0.label', 'BRAVO production key');
});

// ── role x action: the split is NOT "owner and admin only" ───────────────────────────────────────

it('lets providers.view read the list, and refuses everyone else', function (string $role, int $status): void {
    $fixture = providerAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("prov-index-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections",
        spaHeaders(),
    )->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    // KNOWLEDGE_MANAGER IS 200 HERE, NOT 403, AND THAT IS THE SPECIFICATION RATHER THAN A
    // RELAXATION. §6.4 grants `providers.view` and nothing else: an ingestion operator has to be
    // able to see whether the organization can embed at all, and OrgRole::grants() reads
    // `self::KnowledgeManager => $permission === Permission::ProvidersView`. A uniform
    // owner/admin-only dataset would go green against a `view` ability that had silently been
    // mapped to `providers.manage`, which is the one mistake this row can catch.
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 200],
    // Holds nothing in this catalog at all — a deliberate row in the matrix, not an oversight.
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets providers.view read one connection, and refuses everyone else', function (string $role, int $status): void {
    $fixture = providerAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("prov-show-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}",
        spaHeaders(),
    )->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 200],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage edit a connection', function (string $role, int $status): void {
    $fixture = providerAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("prov-update-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}",
        ['label' => 'renamed by '.$role],
        spaHeaders(),
    )->assertStatus($status);

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($fixture['connectionA']->id);

    // The 403 rows have to prove the edit did not happen, not merely that a 403 was rendered.
    expect($after->label)->toBe(
        $status === 200 ? 'renamed by '.$role : 'ALPHA production key',
        "[{$role}] the label does not match the status that was returned",
    );
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    // 403 EVEN THOUGH THE SAME ROLE READS THIS ROW. §6.4 excludes provider credentials from a
    // Knowledge Manager wholesale, and the label is part of the credential record.
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage delete a connection', function (string $role, int $status): void {
    $fixture = providerAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("prov-destroy-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}",
        [],
        spaHeaders(),
    )->assertStatus($status);

    $survived = ProviderConnection::query()->withoutGlobalScopes()->find($fixture['connectionA']->id) !== null;

    expect($survived)->toBe(
        $status !== 200,
        "[{$role}] the row's survival does not match the status that was returned",
    );

    // Org B is untouched on every row of the dataset, including the ones that succeeded.
    expect(ProviderConnection::query()->withoutGlobalScopes()->find($fixture['connectionB']->id))
        ->not->toBeNull("[{$role}] the delete crossed into the other organization");
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage rotate a credential', function (string $role, int $status): void {
    $fixture = providerAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("prov-rotate-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/credential",
        [
            'current_password' => UserFactory::PASSWORD,
            'credential' => 'kb-access-test-credential-DO-NOT-LOG-w4k2',
        ],
        spaHeaders(),
    )->assertStatus($status);

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($fixture['connectionA']->id);

    // THE PASSWORD SUBMITTED ON EVERY ROW IS CORRECT, deliberately: that is what makes the 403 rows
    // prove the POLICY refused them rather than the re-authentication. A dataset that sent a wrong
    // password would 422 the denied roles and look like a passing authorization test.
    expect($after->credential_version)->toBe(
        $status === 200 ? 2 : 1,
        "[{$role}] the credential version does not match the status that was returned",
    );
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

// ── membership status and organization status ────────────────────────────────────────────────────

it('403s a suspended member of the organization they are addressing', function (): void {
    $fixture = providerAccessPair();

    $suspended = User::factory()->recycle($fixture['orgA'])
        ->orgRole(OrgRole::Owner, MembershipStatus::Suspended)
        ->create(['email' => SpaSession::uniqueEmail('prov-suspended')]);

    SpaSession::establish(currentTest(), $suspended);

    // The role is OWNER, so this is not a permission failure — `org.member` re-reads the
    // membership row and finds it inactive. A matrix test over OrgRole cannot express this, which
    // is why it is asserted over HTTP.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections",
        spaHeaders(),
    )->assertStatus(403);
});

it('lets a suspended organization READ its connections and refuses every write', function (): void {
    $fixture = providerAccessPair();

    $fixture['orgA']->forceFill(['status' => OrganizationStatus::Suspended])->save();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections";

    // READS STAY OPEN — check 5 is deliberately absent from `index` and `show`. Reading which
    // credentials exist and why ingestion is blocked is exactly what a suspended organization's
    // operator needs to do, and it changes nothing. This is also the positive control for the
    // three 409s below: without it they also pass against a suspension that broke the whole route.
    currentTest()->getJson($base, spaHeaders())->assertOk();
    currentTest()->getJson("{$base}/{$fixture['connectionA']->id}", spaHeaders())->assertOk();

    // AND EVERY ONE OF THE THREE CARRIES THE SENTENCE, not an empty `message`. A 409 with no
    // message is rendered by bootstrap/app.php as `internal_dependency`, whose class-mapped client
    // copy is "Something on our side is unavailable. Try again shortly." — false twice over here,
    // because nothing is unavailable and retrying never works while the organization is suspended.
    // The render closure's `default => $e->getMessage()` arm carries this through verbatim.
    currentTest()->patchJson("{$base}/{$fixture['connectionA']->id}", ['label' => 'nope'], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);
    currentTest()->deleteJson("{$base}/{$fixture['connectionA']->id}", [], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);
    currentTest()->putJson(
        "{$base}/{$fixture['connectionA']->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => 'kb-access-test-credential-DO-NOT-LOG-w4k2'],
        spaHeaders(),
    )->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($fixture['connectionA']->id);

    expect($after->label)->toBe('ALPHA production key')
        ->and($after->credential_version)->toBe(1)
        ->and($after->status)->toBe(ProviderConnectionStatus::Active);
});

// ── the credential itself: never in a body, never in an audit row, on any of these paths ─────────

it('renders no credential on any provider route, from rows whose keys really were sealed', function (): void {
    $fixture = providerAccessPair();

    // POSITIVE CONTROL FIRST. If the fixture credential is not actually in the row, every absence
    // assertion below is vacuous — the classic redaction test that passes because nothing was ever
    // encrypted. `last_four` is written by the vault from the plaintext it sealed, so this is a
    // statement about the row rather than about the factory's arguments.
    expect($fixture['connectionA']->last_four)
        ->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections";

    $bodies = [
        'index' => (string) currentTest()->getJson($base, spaHeaders())->assertOk()->getContent(),
        'show' => (string) currentTest()->getJson("{$base}/{$fixture['connectionA']->id}", spaHeaders())
            ->assertOk()->getContent(),
        'update' => (string) currentTest()->patchJson(
            "{$base}/{$fixture['connectionA']->id}",
            ['label' => 'ALPHA production key'],
            spaHeaders(),
        )->assertOk()->getContent(),
        'rotate' => (string) currentTest()->putJson(
            "{$base}/{$fixture['connectionA']->id}/credential",
            [
                'current_password' => UserFactory::PASSWORD,
                'credential' => 'kb-access-test-credential-DO-NOT-LOG-w4k2',
            ],
            spaHeaders(),
        )->assertOk()->getContent(),
    ];

    foreach ($bodies as $label => $body) {
        // The key the fixture sealed, the key the rotation submitted, and the actor's password.
        expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse("{$label} rendered the sealed provider credential");
        expect(str_contains($body, 'kb-access-test-credential-DO-NOT-LOG-w4k2'))
            ->toBeFalse("{$label} rendered the submitted provider credential");
        expect(str_contains($body, UserFactory::PASSWORD))
            ->toBeFalse("{$label} rendered the actor's password");

        // And no column name a credential lives behind.
        foreach (['credential_ciphertext', 'data_key_ciphertext', 'key_version', 'current_password'] as $needle) {
            expect(str_contains($body, $needle))->toBeFalse("{$label} rendered `{$needle}`");
        }
    }

    // THE MASK IS THE ONLY DERIVED FORM THAT LEAVES THE PROCESS, and after the rotation it is the
    // NEW key's last four — which also proves the rotation really happened, so the absence
    // assertions above ran against a path that did something.
    //
    // THE LAST FOUR ONLY, NOT THE WHOLE MASK. `getContent()` is the RAW body, and Symfony's
    // JsonResponse encodes with JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT and NOT
    // JSON_UNESCAPED_UNICODE — so U+2026 is on the wire as the six ASCII characters
    // backslash-u-2-0-2-6, and searching the raw bytes for the literal ellipsis never matches. The decoded form
    // is asserted with assertJsonPath in tests/Feature/ProviderConnectionResourceTest.php, which is
    // where the mask's exact shape belongs.
    expect(str_contains($bodies['rotate'], 'w4k2'))->toBeTrue('the rotation did not update the mask');

    // AND NOT ONE AUDIT ROW CARRIES ANY OF IT. Every operation this test drove, in one sweep.
    $rows = AuditLog::query()->whereIn('operation', [
        AuditLogger::PROVIDER_CONNECTION_UPDATED,
        AuditLogger::PROVIDER_CREDENTIAL_ROTATED,
    ])->get();

    expect($rows)->toHaveCount(2, 'the audited writes left no rows, so the sweep below is vacuous');

    foreach ($rows as $row) {
        $details = json_encode($row->details, JSON_THROW_ON_ERROR);

        expect(str_contains($details, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse("{$row->operation} echoed the sealed credential");
        expect(str_contains($details, 'kb-access-test-credential-DO-NOT-LOG-w4k2'))
            ->toBeFalse("{$row->operation} echoed the submitted credential");
        // THE LAST FOUR OF EITHER KEY. It is the only derived form that may be rendered in an API
        // response, and it still has no business in an append-only table. Both literals are
        // checked, and both are chosen so neither can occur by accident inside a ULID (upper-case
        // Crockford base32) or a timestamp.
        expect(str_contains($details, 'w4k2'))
            ->toBeFalse("{$row->operation} carried the new key's last four");
        expect(str_contains($details, substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4)))
            ->toBeFalse("{$row->operation} carried the replaced key's last four");
        expect(str_contains($details, UserFactory::PASSWORD))
            ->toBeFalse("{$row->operation} carried the actor's password");
    }
});

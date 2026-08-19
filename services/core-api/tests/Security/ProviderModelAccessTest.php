<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Provider models — who may reach them, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| THE BEHAVIOUR IS tests/Feature/ProviderModelCatalogueTest.php. This file asserts only the access
| rules, and it is a separate file because the two fail for different reasons: a red test here is a
| tenant or privilege boundary, a red test there is a bug in an endpoint.
|
| `Http::fake()` APPEARS NOWHERE IN THIS FILE, and cannot: pest-testing bans it under tests/Security
| outright. None of the five endpoints under test calls the AI service — a catalogue write does not
| compute an embedding readiness — so there is nothing to fake and nothing that would silently pass
| because a faked response short-circuited a real code path.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS, each with a connection whose catalogue carries THE SAME MODEL
| IDENTIFIER, and every absence assertion carries a POSITIVE CONTROL asserted first. A
| one-organization fixture passes every test below against code with no tenant filter at all, and an
| absence assertion with no control passes the moment the endpoint breaks and returns nothing.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a
| "label" passed there becomes a second needle, and `not` treats any failure as success — the
| expression passes unconditionally. That exact shape has already hidden a real tenant-id leak in
| this repo.
|
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() is the intended home for this pair and
| throws by design until the Bot and KnowledgeSource factories exist. The helper below carries a
| name of its own because Pest declares test-file helpers at FILE SCOPE — `providerAccessPair()`
| exists only when ProviderConnectionAccessTest.php has been loaded, and a second declaration under
| that name would be a redeclaration fatal in a full run.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The model identifier BOTH organizations register.
 *
 * A SHARED IDENTIFIER IS THE WHOLE FIXTURE. A missing organization or connection predicate returns
 * a row that looks exactly right — same vendor, same id — and only the display name and the ULID
 * betray it. A fixture whose two organizations held different model ids would let a cross-tenant
 * read pass every assertion that checks the id.
 */
const MODEL_ACCESS_SHARED_MODEL = 'text-embedding-3-large';

/**
 * Two organizations, one connection each, one catalogue row each.
 *
 * `->recycle()` on every factory, and BOTH parents on the model factory.
 * ProviderModelEntryFactory refuses to run without them — and refuses an organization and a
 * connection that disagree — precisely because a row minted into a THIRD organization is what makes
 * an isolation test pass with the tenant filter deleted.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     connectionA: ProviderConnection, connectionB: ProviderConnection,
 *     modelA: ProviderModelEntry, modelB: ProviderModelEntry,
 * }
 */
function modelAccessPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Model Access Org ALPHA', 'slug' => 'model-access-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Model Access Org BRAVO', 'slug' => 'model-access-bravo']);

    $connectionA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::OpenAI)->create(['label' => 'ALPHA production key']);
    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->provider(Provider::OpenAI)->create(['label' => 'BRAVO production key']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Alpha', 'email' => SpaSession::uniqueEmail('model-owner-alpha')]),
        'ownerB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Bravo', 'email' => SpaSession::uniqueEmail('model-owner-bravo')]),
        'connectionA' => $connectionA,
        'connectionB' => $connectionB,
        'modelA' => ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
            ->supporting(['embedding'])
            ->create(['model' => MODEL_ACCESS_SHARED_MODEL, 'display_name' => 'ALPHA catalogue row']),
        'modelB' => ProviderModelEntry::factory()->recycle($orgB)->recycle($connectionB)
            ->supporting(['embedding'])
            ->create(['model' => MODEL_ACCESS_SHARED_MODEL, 'display_name' => 'BRAVO catalogue row']),
    ];
}

/**
 * A complete, valid PUT body. The endpoint replaces the whole mutable state, so a partial body
 * would 422 before any authorization decision was reached — which would make every 403 row of every
 * dataset below pass for the wrong reason.
 *
 * @return array<string, mixed>
 */
function modelAccessPutBody(): array
{
    return [
        'display_name' => 'renamed by an access test',
        'supported' => ['embedding'],
        'context_window' => 8192,
        'max_output_tokens' => 0,
        'enabled' => true,
        'input_price_per_million' => null,
        'output_price_per_million' => null,
        'price_currency' => null,
    ];
}

/**
 * The five routes under test, as (verb, path suffix, body) triples.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function modelRoutes(): array
{
    return [
        'index' => ['GET', '', []],
        'store' => ['POST', '', [
            'model' => 'registered-by-an-access-test',
            'display_name' => 'ALPHA access-test row',
            'supported' => [],
            'context_window' => 0,
            'max_output_tokens' => 0,
        ]],
        'show' => ['GET', '/{model}', []],
        'update' => ['PUT', '/{model}', []],
        'destroy' => ['DELETE', '/{model}', []],
    ];
}

/**
 * Fire one of modelRoutes() at a (connection, model) pair.
 *
 * @param  array{0: string, 1: string, 2: array<string, mixed>}  $route
 * @return TestResponse<JsonResponse>
 */
function callModelRoute(
    string $organizationId,
    string $connectionId,
    string $modelId,
    array $route,
): TestResponse {
    [$verb, $suffix, $body] = $route;

    $url = "/api/v1/organizations/{$organizationId}/provider-connections/{$connectionId}/models"
        .str_replace('{model}', $modelId, $suffix);

    return match ($verb) {
        'GET' => currentTest()->getJson($url, spaHeaders()),
        'POST' => currentTest()->postJson($url, $body, spaHeaders()),
        'DELETE' => currentTest()->deleteJson($url, $body, spaHeaders()),
        default => currentTest()->putJson($url, modelAccessPutBody(), spaHeaders()),
    };
}

// ── the {organization} segment: 403, on the admin surface, for every verb ────────────────────────

it('403s a member of one organization who addresses another, on every model route', function (
    string $route,
): void {
    $fixture = modelAccessPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // "Owner of SOME organization" is the cross-tenant bug, and this is the layer that stops it:
    // App\Http\Middleware\TenantContext RE-READS `organization_users` for the {organization}
    // SEGMENT and throws before route binding and before any policy. 403 and not 404 is correct on
    // the admin surface — a member is entitled to know an organization exists — and
    // tests/Security/DenyOracleTest.php exists to keep it at 403 so its public 404 arm cannot go
    // vacuous.
    $response = callModelRoute(
        $fixture['orgB']->id,
        $fixture['connectionB']->id,
        $fixture['modelB']->id,
        modelRoutes()[$route],
    );

    $response->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization')
        ->assertJsonPath('message', 'This action is not permitted.');

    // NOTHING OF ORG B'S IS IN THE DENIAL BODY. A 403 that named the display name or the model id
    // would hand the caller the record it just refused them.
    $content = (string) $response->getContent();

    expect(str_contains($content, 'BRAVO'))->toBeFalse('the denial body carried org B\'s display name');
    expect(str_contains($content, $fixture['modelB']->id))->toBeFalse('the denial body carried org B\'s row id');

    // AND IT DID NOT ACT ANYWAY. The write verbs are the ones where a status assertion alone is
    // insufficient: a 403 rendered after the service ran looks identical from the outside.
    $survivor = ProviderModelEntry::query()->withoutGlobalScopes()->find($fixture['modelB']->id);

    expect($survivor)->not->toBeNull("[{$route}] deleted another organization's catalogue row anyway");
    expect($survivor?->display_name)->toBe('BRAVO catalogue row', "[{$route}] edited it anyway");

    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgB']->id)->count())
        ->toBe(1, "[{$route}] registered a row in another organization's catalogue anyway");
})
    // THE DATASET CARRIES A NAME, NOT THE TRIPLE. A dataset row of `[string, string, array]` would
    // give the test closure an untyped `array` parameter, which PHPStan reports at level 8 as an
    // iterable with no value type and which no docblock can annotate cleanly on a closure argument.
    ->with(fn (): array => array_keys(modelRoutes()));

it('serves org B\'s owner their own catalogue, which is the control for every 403 above', function (): void {
    $fixture = modelAccessPair();

    SpaSession::establish(currentTest(), $fixture['ownerB']);

    // WITHOUT THIS TEST the file above passes against a route that 403s everybody, including the
    // organization that owns the rows — which is not isolation, it is an outage.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgB']->id}/provider-connections/{$fixture['connectionB']->id}/models",
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonCount(1, 'data.models')
        ->assertJsonPath('data.models.0.id', $fixture['modelB']->id)
        ->assertJsonPath('data.models.0.display_name', 'BRAVO catalogue row');
});

// ── the {providerConnection} segment: 404 at binding time ────────────────────────────────────────

it('404s a foreign or unknown {providerConnection} before any policy runs', function (string $route): void {
    $fixture = modelAccessPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // ORG A'S OWN ORGANIZATION SEGMENT, so `org.member` passes and the denial can only come from
    // the scoped binding. The connection belongs to org B, and `->scopeBindings()` resolves it
    // through `$organization->providerConnections()` — so it is simply not found, before any
    // policy is constructed and before the row is in memory.
    callModelRoute(
        $fixture['orgA']->id,
        $fixture['connectionB']->id,
        $fixture['modelB']->id,
        modelRoutes()[$route],
    )->assertStatus(404);

    // AND AN ID THAT NEVER EXISTED IS THE SAME 404, so the status does not distinguish "someone
    // else's" from "nobody's".
    callModelRoute(
        $fixture['orgA']->id,
        Str::ulid()->toBase32(),
        $fixture['modelA']->id,
        modelRoutes()[$route],
    )->assertStatus(404);

    // Nothing of org B's moved.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()->find($fixture['modelB']->id))
        ->not->toBeNull("[{$route}] reached another organization's catalogue through its connection id");
})->with(fn (): array => array_keys(modelRoutes()));

// ── the {model} segment: 404 at binding time, INCLUDING under a connection the caller owns ───────

it('404s a foreign {model} under a connection the caller DOES own', function (string $route): void {
    $fixture = modelAccessPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THIS IS THE CASE THE COMPOSITE FOREIGN KEY AND THE SCOPED BINDING EXIST FOR TOGETHER, and it
    // is the one a single-level scope would miss: the organization is the caller's, the connection
    // is the caller's, and the MODEL ROW belongs to another organization entirely. The binding
    // resolves `{model}` through `$providerConnection->models()`, so the row is not in the set —
    // 404, before the policy, before the row is in memory.
    //
    // A bare `ProviderModelEntry $model` binding would be a global find with no organization and
    // no connection predicate, executed inside SubstituteBindings, upstream of every check.
    callModelRoute(
        $fixture['orgA']->id,
        $fixture['connectionA']->id,
        $fixture['modelB']->id,
        modelRoutes()[$route],
    )->assertStatus(404);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()->find($fixture['modelB']->id))
        ->not->toBeNull("[{$route}] reached another organization's catalogue row");
})
    // ONLY THE THREE ROUTES THAT CARRY A `{model}` SEGMENT. `index` and `store` address the
    // collection and never bind a child, so including them would assert a 404 on a request that
    // correctly succeeds — a dataset row that fails for a reason unrelated to the property.
    ->with(['show', 'update', 'destroy']);

it('404s a model of a DIFFERENT connection inside the SAME organization', function (): void {
    $fixture = modelAccessPair();

    // A SECOND CONNECTION OF ORG A, with its own row. The tenant predicate is satisfied for both,
    // so ONLY the parent scoping refuses this — which means a scope that stopped at the
    // organization would serve it, and no cross-tenant test would ever notice.
    $second = ProviderConnection::factory()->recycle($fixture['orgA'])
        ->provider(Provider::Anthropic)->create(['label' => 'ALPHA second key']);

    $onSecond = ProviderModelEntry::factory()->recycle($fixture['orgA'])->recycle($second)
        ->supporting(['text'])
        ->create(['model' => 'claude-sonnet-5', 'display_name' => 'ALPHA second-connection row']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections";

    // POSITIVE CONTROL FIRST: addressed under its OWN connection, the row is served. Without it,
    // the 404 below is satisfied by a route that 404s everything.
    currentTest()->getJson("{$base}/{$second->id}/models/{$onSecond->id}", spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $onSecond->id);

    currentTest()->getJson("{$base}/{$fixture['connectionA']->id}/models/{$onSecond->id}", spaHeaders())
        ->assertStatus(404);

    currentTest()->deleteJson("{$base}/{$fixture['connectionA']->id}/models/{$onSecond->id}", [], spaHeaders())
        ->assertStatus(404);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()->find($onSecond->id))->not->toBeNull();
});

// ── role x action: the split is NOT "owner and admin only" ───────────────────────────────────────

it('lets providers.view read the catalogue, and refuses everyone else', function (string $role, int $status): void {
    $fixture = modelAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("model-index-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        spaHeaders(),
    )->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    // KNOWLEDGE_MANAGER IS 200 HERE, NOT 403, AND THAT IS THE SPECIFICATION RATHER THAN A
    // RELAXATION. §6.4 grants `providers.view` and nothing else, and `OrgRole::grants()` reads
    // `self::KnowledgeManager => $permission === Permission::ProvidersView`. An ingestion operator
    // has to be able to see whether the organization can embed at all — and these rows' capability
    // flags are half of that answer, so the catalogue is precisely what they need to read. A
    // uniform owner/admin-only dataset would go green against a `view` ability that had silently
    // been mapped to `providers.manage`, which is the one mistake this row can catch.
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 200],
    // Holds nothing in this catalog at all — a deliberate row in the matrix, not an oversight.
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets providers.view read one catalogue row, and refuses everyone else', function (string $role, int $status): void {
    $fixture = modelAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("model-show-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['modelA']->id}",
        spaHeaders(),
    )->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 200],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage register a model', function (string $role, int $status): void {
    $fixture = modelAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("model-store-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => 'registered-by-'.$role,
            'display_name' => 'ALPHA row by '.$role,
            'supported' => ['embedding'],
            'context_window' => 8192,
            'max_output_tokens' => 0,
        ],
        spaHeaders(),
    )->assertStatus($status);

    // The 403 rows have to prove the write did not happen, not merely that a 403 was rendered.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('model', '=', 'registered-by-'.$role)->count())
        ->toBe($status === 201 ? 1 : 0, "[{$role}] the row count does not match the status returned");
})->with([
    'owner' => [OrgRole::Owner->value, 201],
    'admin' => [OrgRole::Admin->value, 201],
    // 403 EVEN THOUGH THE SAME ROLE READS THIS CATALOGUE. §6.4 excludes provider configuration from
    // a Knowledge Manager wholesale — and a catalogue row is not decoration: its `embedding` flag
    // decides which of the organization's credentials embeds the corpus, at a provider's per-token
    // price and under a different account.
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage replace a model row', function (string $role, int $status): void {
    $fixture = modelAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("model-update-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['modelA']->id}",
        modelAccessPutBody(),
        spaHeaders(),
    )->assertStatus($status);

    $after = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['modelA']->id);

    expect($after->display_name)->toBe(
        $status === 200 ? 'renamed by an access test' : 'ALPHA catalogue row',
        "[{$role}] the display name does not match the status that was returned",
    );
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('lets only providers.manage delete a model row', function (string $role, int $status): void {
    $fixture = modelAccessPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("model-destroy-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['modelA']->id}",
        [],
        spaHeaders(),
    )->assertStatus($status);

    $survived = ProviderModelEntry::query()->withoutGlobalScopes()->find($fixture['modelA']->id) !== null;

    expect($survived)->toBe(
        $status !== 200,
        "[{$role}] the row's survival does not match the status that was returned",
    );

    // Org B is untouched on every row of the dataset, including the ones that succeeded — and its
    // row carries the SAME model identifier, so a delete keyed on `model` alone would have taken
    // it.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()->find($fixture['modelB']->id))
        ->not->toBeNull("[{$role}] the delete crossed into the other organization");
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

// ── membership status and organization status ────────────────────────────────────────────────────

it('403s a suspended member of the organization they are addressing', function (): void {
    $fixture = modelAccessPair();

    $suspended = User::factory()->recycle($fixture['orgA'])
        ->orgRole(OrgRole::Owner, MembershipStatus::Suspended)
        ->create(['email' => SpaSession::uniqueEmail('model-suspended')]);

    SpaSession::establish(currentTest(), $suspended);

    // The role is OWNER, so this is not a permission failure — `org.member` re-reads the
    // membership row and finds it inactive. A matrix test over OrgRole cannot express this, which
    // is why it is asserted over HTTP.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        spaHeaders(),
    )->assertStatus(403);
});

it('lets a suspended organization READ its catalogue and refuses every write', function (): void {
    $fixture = modelAccessPair();

    $fixture['orgA']->forceFill(['status' => OrganizationStatus::Suspended])->save();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models";

    // READS STAY OPEN — check 5 is deliberately absent from `index` and `show`. Reading which
    // models exist and which claim `embedding` is exactly what a suspended organization's operator
    // needs while working out why ingestion stopped, and it changes nothing. This is also the
    // positive control for the three 409s below: without it they also pass against a suspension
    // that broke the whole route.
    currentTest()->getJson($base, spaHeaders())->assertOk();
    currentTest()->getJson("{$base}/{$fixture['modelA']->id}", spaHeaders())->assertOk();

    // AND EVERY ONE OF THE THREE CARRIES THE SENTENCE, not an empty `message`. A 409 with no
    // message is rendered by bootstrap/app.php as `internal_dependency`, whose class-mapped client
    // copy is "Something on our side is unavailable. Try again shortly." — false twice over here,
    // because nothing is unavailable and retrying never works while the organization is suspended.
    // The render closure's `default => $e->getMessage()` arm is what carries this through verbatim,
    // and apps/web's deleteConflictMessage() renders it in place of the class copy.
    currentTest()->postJson($base, [
        'model' => 'registered-while-suspended',
        'display_name' => 'nope',
        'supported' => [],
        'context_window' => 0,
        'max_output_tokens' => 0,
    ], spaHeaders())->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    currentTest()->putJson("{$base}/{$fixture['modelA']->id}", modelAccessPutBody(), spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    currentTest()->deleteJson("{$base}/{$fixture['modelA']->id}", [], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    $after = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['modelA']->id);

    expect($after->display_name)->toBe('ALPHA catalogue row')
        ->and($after->enabled)->toBeTrue();

    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('model', '=', 'registered-while-suspended')->count())->toBe(0);
});

// ── the credential itself: never in a body, never in an audit row, on any of these paths ─────────

it('renders no credential on any model route, from a connection whose key really was sealed', function (): void {
    $fixture = modelAccessPair();

    // POSITIVE CONTROL FIRST. If the fixture credential is not actually in the parent row, every
    // absence assertion below is vacuous — the classic redaction test that passes because nothing
    // was ever encrypted. `last_four` is written by the vault from the plaintext it sealed, so this
    // is a statement about the row rather than about the factory's arguments.
    expect($fixture['connectionA']->last_four)
        ->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models";

    $bodies = [
        'index' => (string) currentTest()->getJson($base, spaHeaders())->assertOk()->getContent(),
        'show' => (string) currentTest()->getJson("{$base}/{$fixture['modelA']->id}", spaHeaders())
            ->assertOk()->getContent(),
        'store' => (string) currentTest()->postJson($base, [
            'model' => 'redaction-probe',
            'display_name' => 'ALPHA redaction probe',
            'supported' => [],
            'context_window' => 0,
            'max_output_tokens' => 0,
        ], spaHeaders())->assertStatus(201)->getContent(),
        'update' => (string) currentTest()->putJson(
            "{$base}/{$fixture['modelA']->id}",
            modelAccessPutBody(),
            spaHeaders(),
        )->assertOk()->getContent(),
    ];

    foreach ($bodies as $label => $body) {
        expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse("{$label} rendered the sealed provider credential");
        // NOT EVEN THE MASKED FORM. A catalogue row has no business re-rendering a credential's
        // display string: it would put the same value in two components for two different reasons,
        // and the day one of them stops being masked the other still looks fine.
        expect(str_contains($body, 'masked_key'))->toBeFalse("{$label} rendered the parent's masked key");
        expect(str_contains($body, 'last_four'))->toBeFalse("{$label} rendered last_four");
        expect(str_contains($body, 'credential_ciphertext'))->toBeFalse("{$label} named a ciphertext column");
        expect(str_contains($body, 'data_key_ciphertext'))->toBeFalse("{$label} named a ciphertext column");
        expect(str_contains($body, 'key_version'))->toBeFalse("{$label} rendered a key version");
        // AND NO TENANT IDENTIFIER. The caller asked through a URL that already named the
        // organization; echoing the ownership column back puts a tenant id in every cached body.
        expect(str_contains($body, $fixture['orgA']->id))
            ->toBeFalse("{$label} echoed the organization id into the response body");
    }

    // AND NO AUDIT ROW CARRIES ONE EITHER. The two write operations above both wrote one, so this
    // is a statement about rows that exist.
    $rows = AuditLog::query()
        ->whereIn('operation', [AuditLogger::PROVIDER_MODEL_CREATED, AuditLogger::PROVIDER_MODEL_UPDATED])
        ->get();

    expect($rows)->toHaveCount(2, 'the two writes above produced no audit rows, so nothing below is asserted');

    foreach ($rows as $row) {
        $encoded = (string) json_encode($row->details, JSON_THROW_ON_ERROR);

        expect(str_contains($encoded, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse('an audit detail carried the plaintext provider credential');

        // THE KEY NAMES, NOT THE LAST-FOUR VALUE, AND THE DIFFERENCE IS DELIBERATE.
        // ProviderConnectionFactory::FIXTURE_CREDENTIAL ends in `1JQZ`, which is four characters of
        // Crockford base32 — the SAME alphabet a ULID is written in — so searching an encoded
        // detail (which is mostly ULIDs) for that substring is a coin toss that comes up heads
        // about once in ten thousand runs. ProviderConnectionResourceTest names exactly this trap
        // and picks lower-case last-fours for its own literals to avoid it. Asserting on the KEY
        // names instead is the property that actually matters here: nothing on this path has any
        // business recording a credential's masked form, and a key name cannot collide with a
        // ULID.
        expect(str_contains($encoded, 'last_four'))->toBeFalse('an audit detail named last_four');
        expect(str_contains($encoded, 'masked_key'))->toBeFalse('an audit detail named masked_key');
        expect(str_contains($encoded, 'credential'))->toBeFalse('an audit detail named a credential field');
    }
});

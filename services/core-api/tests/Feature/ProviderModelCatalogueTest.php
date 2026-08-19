<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Repositories\Eloquent\EloquentProviderModelRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Providers\ProviderModelService;
use Database\Factories\ProviderConnectionFactory;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\Support\RacingProviderModelRepository;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The provider-model catalogue — index, store, show, update, destroy
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half. The authorization and isolation half is
| tests/Security/ProviderModelAccessTest.php, and the two are separate files because they fail for
| different reasons and a reviewer reads them for different questions.
|
| EVERY FIXTURE HERE IS TWO ORGANIZATIONS with overlapping, distinguishable data, even in the
| behaviour file. A one-organization fixture passes every assertion below against code with the
| tenant filter deleted, so there is no "this test does not need isolation" case. Both
| organizations carry the SAME vendor and the SAME model identifiers; only the DISPLAY NAME differs,
| which is exactly the shape that makes a missing tenant predicate return plausible rows and only
| the label betray it.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a
| "label" passed there becomes a second needle, and `not` treats any failure as success — the
| expression passes unconditionally. That exact shape has already hidden a real tenant-id leak in
| this repo. Every absence assertion carries a POSITIVE CONTROL asserted first.
|
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() is the intended home for this pair and
| throws by design until the Bot and KnowledgeSource factories exist. The hand-rolled pair below
| copies providerOrgPair()'s shape — and carries a DIFFERENT NAME on purpose: Pest declares
| test-file helpers at FILE SCOPE, so that one exists only when its own file has been loaded
| (running this file alone would fatal on an undefined function) and a second declaration under the
| same name would be a redeclaration fatal in a full run.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing
    // else, and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket
    // survives the test that filled it and the next run of the suite.
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The model identifier BOTH organizations register, so a missing tenant predicate returns a row
 * that looks exactly right.
 *
 * A vendor id and not a made-up string, because the whole point of the fixture is that the two
 * organizations' rows are indistinguishable except by the fields this suite asserts on.
 */
const CATALOGUE_SHARED_MODEL = 'text-embedding-3-large';

/**
 * Two organizations, one connection each, each connection carrying TWO catalog rows — and the two
 * organizations' rows share their model identifiers.
 *
 * TWO ROWS PER CONNECTION so `index` can assert a COUNT as well as a membership: a list that
 * returned one row of the right organization and one of the wrong one has the right length for a
 * fixture with one row each.
 *
 * `->recycle()` on every factory, without exception, and BOTH parents on the model factory.
 * ProviderModelEntryFactory refuses to run without them — and refuses a pair that disagrees —
 * because a row minted into a THIRD organization is what makes an isolation test pass with the
 * tenant filter deleted.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     connectionA: ProviderConnection, connectionB: ProviderConnection,
 *     embedA: ProviderModelEntry, chatA: ProviderModelEntry,
 *     embedB: ProviderModelEntry,
 * }
 */
function catalogueOrgPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Catalogue Org ALPHA', 'slug' => 'catalogue-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Catalogue Org BRAVO', 'slug' => 'catalogue-bravo']);

    $connectionA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::OpenAI)->create(['label' => 'ALPHA catalogue key']);
    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->provider(Provider::OpenAI)->create(['label' => 'BRAVO catalogue key']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Alpha', 'email' => SpaSession::uniqueEmail('cat-owner-alpha')]),
        'ownerB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Bravo', 'email' => SpaSession::uniqueEmail('cat-owner-bravo')]),
        'connectionA' => $connectionA,
        'connectionB' => $connectionB,

        'embedA' => ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
            ->supporting(['embedding'])
            ->priced('0.130000', '0.000000')
            ->create(['model' => CATALOGUE_SHARED_MODEL, 'display_name' => 'ALPHA embedding row']),
        'chatA' => ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
            ->supporting(['text', 'tool_use'])
            ->create(['model' => 'gpt-5-alpha-chat', 'display_name' => 'ALPHA chat row']),

        // SAME MODEL ID AS embedA. Only the display name tells them apart.
        'embedB' => ProviderModelEntry::factory()->recycle($orgB)->recycle($connectionB)
            ->supporting(['embedding'])
            ->create(['model' => CATALOGUE_SHARED_MODEL, 'display_name' => 'BRAVO embedding row']),
    ];
}

/**
 * Point an organization's embedding designation at one of its own (connection, model) pairs.
 *
 * `forceFill` because neither column is fillable — the designation is normally written only by
 * EmbeddingDesignationService, which validates the pair against the organization's own connections
 * first. Both columns together, because `organizations_embedding_designation_complete` CHECKs
 * `num_nonnulls(...) <> 1`: half a designation is not a state the table can hold.
 */
function designateCatalogueModel(Organization $organization, ProviderModelEntry $model): void
{
    $organization->forceFill([
        'embedding_connection_id' => $model->provider_connection_id,
        'embedding_model' => $model->model,
    ])->save();
}

/**
 * A complete, valid PUT body — the endpoint replaces the whole mutable state, so every test that
 * edits one field still has to send the rest.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cataloguePutBody(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'replaced',
        'supported' => ['embedding'],
        'context_window' => 8192,
        'max_output_tokens' => 0,
        'enabled' => true,
        'input_price_per_million' => null,
        'output_price_per_million' => null,
        'price_currency' => null,
    ], $overrides);
}

// ── GET …/provider-connections/{providerConnection}/models ───────────────────────────────────────

it('lists only the addressed connection\'s rows, enabled or not, deterministically ordered', function (): void {
    $fixture = catalogueOrgPair();

    // A disabled row, so the list is exercised as "every state" rather than "the happy ones".
    $disabled = ProviderModelEntry::factory()
        ->recycle($fixture['orgA'])->recycle($fixture['connectionA'])
        ->supporting([])->disabled()
        ->create(['model' => 'aaa-retired-model', 'display_name' => 'ALPHA retired row']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        spaHeaders(),
    );

    // POSITIVE CONTROL FIRST: org A's own three rows are there, including the disabled one. Hiding
    // a disabled row would make "why is this model missing from the bot's dropdown" unanswerable
    // from the console — and without this assertion every absence check below also passes against
    // an endpoint that returns nothing at all.
    $response->assertOk()->assertJsonCount(3, 'data.models');

    $ids = array_column((array) $response->json('data.models'), 'id');

    expect($ids)->toContain($fixture['embedA']->id)
        ->and($ids)->toContain($fixture['chatA']->id)
        ->and($ids)->toContain($disabled->id);

    // ORDERED BY MODEL IDENTIFIER, which is what the index range yields and what makes two reads of
    // an unchanged set byte-identical. `aaa-retired-model` sorts first, so an accidental
    // insertion-order or id-order sort is visible here rather than as a flaky diff later.
    expect(array_column((array) $response->json('data.models'), 'model'))
        ->toBe(['aaa-retired-model', 'gpt-5-alpha-chat', CATALOGUE_SHARED_MODEL]);

    $body = (string) $response->getContent();

    // THE ISOLATION ASSERTION. Org B holds a row with the SAME model identifier under the same
    // vendor, so a missing organization or connection predicate returns a row that looks perfectly
    // plausible — only the id and the display name give it away.
    expect(str_contains($body, $fixture['embedB']->id))->toBeFalse('org B\'s row id is in org A\'s catalogue');
    expect(str_contains($body, 'BRAVO'))->toBeFalse('org B\'s display name is in org A\'s catalogue');
    expect(str_contains($body, $fixture['connectionB']->id))->toBeFalse('org B\'s connection id is in org A\'s catalogue');

    // NO CREDENTIAL, IN ANY FORM. The positive control is the factory: it seals FIXTURE_CREDENTIAL
    // for real, so the plaintext genuinely exists in the parent connection's row.
    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the plaintext provider credential reached a catalogue response');
    expect(str_contains($body, 'masked_key'))->toBeFalse('the catalogue rendered the parent credential\'s masked form');
    expect(str_contains($body, 'last_four'))->toBeFalse();
});

it('lists a second connection\'s catalogue separately, which is what the parent scoping is for', function (): void {
    $fixture = catalogueOrgPair();

    // A SECOND CONNECTION IN THE SAME ORGANIZATION. Without it, "the list is scoped to the
    // connection" is indistinguishable from "the list is scoped to the organization" — every
    // assertion in the test above passes under both readings.
    $second = ProviderConnection::factory()->recycle($fixture['orgA'])
        ->provider(Provider::Anthropic)->create(['label' => 'ALPHA second key']);

    $onSecond = ProviderModelEntry::factory()->recycle($fixture['orgA'])->recycle($second)
        ->supporting(['text'])
        ->create(['model' => 'claude-sonnet-5', 'display_name' => 'ALPHA second-connection row']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$second->id}/models",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonCount(1, 'data.models')
        ->assertJsonPath('data.models.0.id', $onSecond->id)
        ->assertJsonPath('data.models.0.connection_id', $second->id);

    $body = (string) $response->getContent();

    expect(str_contains($body, $fixture['embedA']->id))
        ->toBeFalse('the other connection\'s catalogue leaked into this one, inside the same organization');
    expect(str_contains($body, 'ALPHA chat row'))->toBeFalse();
});

// ── POST …/models ────────────────────────────────────────────────────────────────────────────────

it('registers a row, storing the capability flags inside the `supported` envelope', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => 'text-embedding-3-small',
            'display_name' => 'ALPHA small embedding',
            'supported' => ['embedding'],
            'context_window' => 8191,
            'max_output_tokens' => 0,
            'enabled' => false,
            'input_price_per_million' => '0.02',
            'output_price_per_million' => '0',
            'price_currency' => 'USD',
        ],
        spaHeaders(),
    );

    $response->assertStatus(201)
        ->assertJsonPath('data.model', 'text-embedding-3-small')
        ->assertJsonPath('data.connection_id', $fixture['connectionA']->id)
        ->assertJsonPath('data.supported', ['embedding'])
        // `enabled` IS WRITABLE HERE and is not on the nested create path, which always writes
        // true. A false here proves the field is read rather than defaulted.
        ->assertJsonPath('data.enabled', false)
        // EXACT DECIMAL STRINGS, from numeric(14, 6) through the `decimal:6` cast. `'0.02'` in and
        // `'0.020000'` out is the round trip working: a `float` cast would have produced
        // `0.019999999999999998` on some inputs and nothing would have said so.
        ->assertJsonPath('data.input_price_per_million', '0.020000')
        ->assertJsonPath('data.output_price_per_million', '0.000000')
        ->assertJsonPath('data.price_currency', 'USD');

    $id = $response->json('data.id');

    assert(is_string($id));

    // ── THE ENVELOPE, ASSERTED AT THE COLUMN AND THEN THROUGH THE ACCESSOR ──────────────────────
    //
    // `capability_flags` is an OBJECT with a `supported` key and NOT a bare list.
    // `ProviderModelEntry::supportedCapabilities()` reads `capability_flags['supported']` and
    // returns [] for anything else — so writing a bare list makes every capability read silently
    // answer "claims nothing", which is a readiness bug with NO error anywhere. Asserting the
    // rendered `supported` field alone would not catch it: a bare list would render as [] and the
    // assertion above would have to be wrong too before anyone noticed.
    $row = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($id);

    expect($row->capability_flags)->toBe(['supported' => ['embedding']])
        ->and($row->supportedCapabilities())->toBe(['embedding'])
        ->and($row->organization_id)->toBe($fixture['orgA']->id)
        ->and($row->provider_connection_id)->toBe($fixture['connectionA']->id);

    // AND THE AUDIT ROW, with the flags flattened to a scalar because sanitize() drops an array.
    assertDatabaseHas('audit_logs', [
        'operation' => AuditLogger::PROVIDER_MODEL_CREATED,
        'organization_id' => $fixture['orgA']->id,
        'actor_id' => $fixture['ownerA']->id,
        'subject_type' => ProviderModelEntry::class,
        'subject_id' => $id,
    ]);

    $audit = AuditLog::query()
        ->where('operation', '=', AuditLogger::PROVIDER_MODEL_CREATED)
        ->where('subject_id', '=', $id)
        ->firstOrFail();

    // toEqual AND NOT toBe. `details` is `jsonb`, and jsonb does NOT preserve insertion order —
    // it stores keys sorted by length then bytewise — so `===` on the read-back array would
    // compare key ORDER that the database is free to change. `==` compares key/value pairs
    // recursively and ignores order, while still failing on a missing, extra or altered field,
    // which is the property being asserted. Array ELEMENT order inside a jsonb array IS preserved,
    // so nothing is lost.
    expect($audit->details)->toEqual([
        'connection_id' => $fixture['connectionA']->id,
        'model' => 'text-embedding-3-small',
        'display_name' => 'ALPHA small embedding',
        'capabilities' => 'embedding',
        'enabled' => false,
        // THE TWO LIMITS, WHICH THIS ROW USED TO OMIT. They were kept out of the allow-list while
        // the ECHO guard refused any field name containing `token`, under which `max_output_tokens`
        // is a false positive — an integer limit off a vendor's documentation page. The guard now
        // reads the name segment by segment (singular `token` is a capability; a plural `tokens`
        // beside a magnitude word is a count), so both are recorded.
        //
        // 0 AND NOT ABSENT. sanitize() keeps an int by the `is_int()` path, so a zero limit is
        // written as zero — unlike a null price, which is skipped without being reported.
        'context_window' => 8191,
        'max_output_tokens' => 0,
        'input_price_per_million' => '0.020000',
        'output_price_per_million' => '0.000000',
        'price_currency' => 'USD',
    ]);
});

it('defaults `enabled` to the value the nested create path always writes', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // NO `enabled` IN THE BODY. `StoreProviderConnectionRequest`'s nested path has no rule for the
    // field and `attachModel()` hard-codes true, so the two paths must agree when neither says
    // otherwise — a default of false here would make the same catalogue behave differently
    // depending on which endpoint created it.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => 'gpt-5-defaulted',
            'display_name' => 'ALPHA defaulted',
            'supported' => [],
            'context_window' => 0,
            'max_output_tokens' => 0,
        ],
        spaHeaders(),
    )
        ->assertStatus(201)
        ->assertJsonPath('data.enabled', true)
        // AN EMPTY FLAG LIST IS A LEGITIMATE STATE — "this row claims nothing yet" — which is why
        // the rule is `present` and not `required`.
        ->assertJsonPath('data.supported', [])
        ->assertJsonPath('data.input_price_per_million', null)
        ->assertJsonPath('data.price_currency', null);
});

it('refuses a duplicate model identifier with a 422 keyed on the field, not a 500', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL: the row this collides with really is there, in THIS organization, under
    // THIS connection. Without it the 422 could be produced by anything.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)
        ->where('provider_connection_id', '=', $fixture['connectionA']->id)
        ->where('model', '=', CATALOGUE_SHARED_MODEL)
        ->count())->toBe(1);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => CATALOGUE_SHARED_MODEL,
            'display_name' => 'ALPHA duplicate attempt',
            'supported' => ['embedding'],
            'context_window' => 8192,
            'max_output_tokens' => 0,
        ],
        spaHeaders(),
    );

    // A 422 AND NOT A 500. Without the pre-flight check the unique index
    // `provider_models_org_connection_model` raises SQLSTATE 23505, which the error envelope
    // classifies as `internal_dependency` / 500 — a bug report about the server for what is
    // plainly a bad request.
    $response->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        // KEYED ON `model`, so the SPA's applyServerErrors puts it under the input the operator
        // typed into rather than raising a banner about the whole form.
        ->assertJsonStructure(['errors' => ['model']]);

    // AND NOTHING WAS WRITTEN.
    assertDatabaseMissing('provider_models', ['display_name' => 'ALPHA duplicate attempt']);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)->count())->toBe(2);
});

it('maps the unique index onto the SAME 422 when the pre-flight loses the race', function (): void {
    // THE SECOND LAYER OF THE DUPLICATE GUARD, WHICH NOTHING HAD EVER EXECUTED.
    //
    // The pre-flight above always wins in a single-threaded test, so `catch (QueryException)` on
    // SQLSTATE 23505 — the `violates()` helper, the constraint-name comparison, and the mapping
    // onto the same ValidationException — had never once run. A `getCode()` that returned an int
    // instead of the SQLSTATE string, or a renamed index, would both surface as a 500 in production
    // and as a green suite here.
    //
    // NOTHING IS FAKED. RacingProviderModelRepository delegates every method to the real repository
    // and only wedges a competing writer into the gap between the pre-flight's SELECT and the
    // INSERT: the pre-flight runs its real org-scoped query and returns its real answer, and the
    // conflicting row is committed after that answer is computed and before it is returned. That is
    // the true state of the world in the race — the other administrator's transaction had not
    // committed when our SELECT ran and had by the time our INSERT did.
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $racedModel = 'gpt-5-raced-registration';

    $racer = new RacingProviderModelRepository(
        app(EloquentProviderModelRepository::class),
        function () use ($fixture, $racedModel): void {
            // THE OTHER ADMINISTRATOR'S ROW. Same organization, same connection, same identifier —
            // which is precisely the triple `provider_models_org_connection_model` is UNIQUE on.
            ProviderModelEntry::factory()->recycle($fixture['orgA'])->recycle($fixture['connectionA'])
                ->supporting(['text'])
                ->create(['model' => $racedModel, 'display_name' => 'THE COMPETITOR']);
        },
    );

    app()->instance(ProviderModelRepositoryInterface::class, $racer);

    $body = [
        'model' => $racedModel,
        'display_name' => 'ALPHA raced attempt',
        'supported' => ['embedding'],
        'context_window' => 8192,
        'max_output_tokens' => 0,
    ];

    $url = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models";

    $raced = currentTest()->postJson($url, $body, spaHeaders());

    // ── THE POSITIVE CONTROL, ASSERTED BEFORE THE RESPONSE ─────────────────────────────────────
    //
    // Without this the test passes identically when the pre-flight caught the duplicate after all
    // — which is the ordinary path, already covered one test up, and would leave this case proving
    // nothing at all.
    expect($racer->preflights)->toBe(1, 'the pre-flight did not run, so the race was never set up')
        ->and($racer->preflightSaw)->toBeFalse(
            'the pre-flight SAW the competing row, so this 422 came from the pre-flight and the '
            .'23505 catch is still unexecuted'
        );

    // AND THE COMPETING ROW REALLY LANDED, so the INSERT really did have something to collide with.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)
        ->where('provider_connection_id', '=', $fixture['connectionA']->id)
        ->where('model', '=', $racedModel)
        ->count())->toBe(1);

    // A 422 AND NOT A 500. Unmapped, SQLSTATE 23505 reaches the render closure as a QueryException,
    // which is not an HttpExceptionInterface — so it renders 500 / internal_dependency, a bug
    // report about the server for what is plainly a bad request.
    $raced->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['model']]);

    // AND NOTHING OF OURS SURVIVED THE ROLLBACK. `create()` writes the row and its audit entry in
    // one transaction; the INSERT failed, so neither exists and the competitor's row is untouched.
    assertDatabaseMissing('provider_models', ['display_name' => 'ALPHA raced attempt']);
    assertDatabaseMissing('audit_logs', [
        'operation' => AuditLogger::PROVIDER_MODEL_CREATED,
        'organization_id' => $fixture['orgA']->id,
    ]);
    assertDatabaseHas('provider_models', ['model' => $racedModel, 'display_name' => 'THE COMPETITOR']);

    // ── "THE SAME 422", ASSERTED RATHER THAN CLAIMED ───────────────────────────────────────────
    //
    // Put the real repository back and repeat the identical request. Now the competing row IS
    // visible, so the PRE-FLIGHT produces the refusal — and the two bodies must be byte-identical,
    // because the whole point of the catch is that the loser of a race deserves the message the
    // winner's neighbour would have got a millisecond earlier, not a constraint name in a 500.
    // forgetInstance and not a second instance(): dropping the override lets AppServiceProvider's
    // ordinary `bind(ProviderModelRepositoryInterface::class, EloquentProviderModelRepository::class)`
    // take over again, so the second request runs the wiring production runs.
    app()->forgetInstance(ProviderModelRepositoryInterface::class);

    $preflight = currentTest()->postJson($url, $body, spaHeaders());

    $preflight->assertStatus(422);

    expect($preflight->json('errors'))->toBe(
        $raced->json('errors'),
        'the 23505 mapping and the pre-flight produced different messages for the same conflict'
    );
});

it('lets the SAME model identifier exist under a different connection and a different organization', function (): void {
    $fixture = catalogueOrgPair();

    // THE OTHER HALF OF THE UNIQUENESS RULE, and the one a too-broad check would break. The index
    // is UNIQUE on (organization, connection, model): the same identifier under a second
    // connection is legitimate — two credentials to the same vendor is the normal way an
    // organization separates billing — and org B already holds the same id, which a
    // `unique:provider_models,model` rule with no tenant predicate would have refused.
    $second = ProviderConnection::factory()->recycle($fixture['orgA'])
        ->provider(Provider::OpenAI)->create(['label' => 'ALPHA billing key']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$second->id}/models",
        [
            'model' => CATALOGUE_SHARED_MODEL,
            'display_name' => 'ALPHA same id, second connection',
            'supported' => ['embedding'],
            'context_window' => 8192,
            'max_output_tokens' => 0,
        ],
        spaHeaders(),
    )->assertStatus(201);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)
        ->where('model', '=', CATALOGUE_SHARED_MODEL)
        ->count())->toBe(2);
});

it('refuses a price with no currency, which is the CHECK constraint one layer up', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models";

    currentTest()->postJson($base, [
        'model' => 'priced-without-currency',
        'display_name' => 'ALPHA unpriceable',
        'supported' => [],
        'context_window' => 0,
        'max_output_tokens' => 0,
        'input_price_per_million' => '15.00',
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['price_currency']]);

    assertDatabaseMissing('provider_models', ['model' => 'priced-without-currency']);

    // THE OTHER DIRECTION IS PERMITTED, one-directionally, matching
    // `provider_models_price_needs_currency`: an operator who recorded the billing currency before
    // looking the prices up must be able to save. Without this assertion the rule above could have
    // been written as a mutual `required_with` and nothing would notice.
    currentTest()->postJson($base, [
        'model' => 'currency-without-price',
        'display_name' => 'ALPHA currency only',
        'supported' => [],
        'context_window' => 0,
        'max_output_tokens' => 0,
        'price_currency' => 'GBP',
    ], spaHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.price_currency', 'GBP')
        ->assertJsonPath('data.input_price_per_million', null);
});

it('refuses a price with more fractional digits than the column stores, rather than rounding it', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // WITHOUT `decimal:0,6` THIS WOULD BE A 201 AND A SILENT ROUND. numeric(14, 6) truncates the
    // seventh digit, so the value the operator typed would not be the value the estimate uses and
    // nothing would ever say so.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => 'over-precise',
            'display_name' => 'ALPHA over-precise',
            'supported' => [],
            'context_window' => 0,
            'max_output_tokens' => 0,
            'input_price_per_million' => '0.1234567',
            'price_currency' => 'USD',
        ],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['input_price_per_million']]);

    assertDatabaseMissing('provider_models', ['model' => 'over-precise']);
});

// ── GET …/models/{model} ─────────────────────────────────────────────────────────────────────────

it('serves one row and renders nothing of the parent credential', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.id', $fixture['embedA']->id)
        ->assertJsonPath('data.model', CATALOGUE_SHARED_MODEL)
        ->assertJsonPath('data.display_name', 'ALPHA embedding row')
        ->assertJsonPath('data.supported', ['embedding'])
        ->assertJsonPath('data.input_price_per_million', '0.130000')
        ->assertJsonPath('data.price_currency', 'USD');

    $body = (string) $response->getContent();

    // POSITIVE CONTROL for the absence assertions: the parent connection really does hold a sealed
    // plaintext, so "the key was never in play" cannot be why they pass.
    expect($fixture['connectionA']->last_four)
        ->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))->toBeFalse();
    expect(str_contains($body, 'ALPHA catalogue key'))
        ->toBeFalse('the catalogue row rendered the parent connection\'s label');
    expect(str_contains($body, 'organization_id'))
        ->toBeFalse('the row echoed its own ownership column back into a cacheable body');
});

// ── PUT …/models/{model} ─────────────────────────────────────────────────────────────────────────

it('replaces every mutable attribute and leaves the model identifier alone', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        cataloguePutBody([
            // A `model` KEY IN THE BODY, DELIBERATELY. There is no rule for it, so it is not in
            // validated() and cannot reach the DTO or the column — and this is the assertion that
            // proves the omission is real rather than a comment. A rename would silently re-point
            // a live corpus at another vector space and leave `organizations.embedding_model`
            // naming a row that no longer exists.
            'model' => 'renamed-by-over-posting',
            'display_name' => 'ALPHA renamed',
            'supported' => ['embedding', 'text'],
            'context_window' => 16384,
            'max_output_tokens' => 4096,
            'enabled' => false,
            'input_price_per_million' => '0.5',
            'output_price_per_million' => '1.5',
            'price_currency' => 'EUR',
        ]),
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.model', CATALOGUE_SHARED_MODEL)
        ->assertJsonPath('data.display_name', 'ALPHA renamed')
        ->assertJsonPath('data.supported', ['embedding', 'text'])
        ->assertJsonPath('data.context_window', 16384)
        ->assertJsonPath('data.max_output_tokens', 4096)
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.input_price_per_million', '0.500000')
        ->assertJsonPath('data.output_price_per_million', '1.500000')
        ->assertJsonPath('data.price_currency', 'EUR');

    assertDatabaseMissing('provider_models', ['model' => 'renamed-by-over-posting']);

    $row = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['embedA']->id);

    // THE ENVELOPE SURVIVES THE EDIT. An update that assigned `$edit->supported` straight to the
    // column would store a bare list, and every capability read would silently return [] with no
    // error — the same readiness bug the create path is guarded against.
    expect($row->capability_flags)->toBe(['supported' => ['embedding', 'text']])
        ->and($row->supportedCapabilities())->toBe(['embedding', 'text'])
        ->and($row->model)->toBe(CATALOGUE_SHARED_MODEL);

    // ORG B'S ROW, WHICH CARRIES THE SAME MODEL IDENTIFIER, IS UNTOUCHED.
    $other = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['embedB']->id);

    expect($other->display_name)->toBe('BRAVO embedding row')
        ->and($other->enabled)->toBeTrue();

    assertDatabaseHas('audit_logs', [
        'operation' => AuditLogger::PROVIDER_MODEL_UPDATED,
        'organization_id' => $fixture['orgA']->id,
        'subject_id' => $fixture['embedA']->id,
    ]);

    // AND THE ROW RECORDS THE NEW LIMITS. This is the gap the narrowed ECHO guard closed: the PUT
    // above moved `context_window` from 8192 to 16384 and `max_output_tokens` from 0 to 4096, and
    // until both keys were allow-listed the audit trail said a model was edited without being able
    // to say that its billing ceiling had quadrupled. Asserted as the values AFTER the replacement,
    // which is what PROVIDER_MODEL_UPDATED carries.
    $updateAudit = AuditLog::query()
        ->where('operation', '=', AuditLogger::PROVIDER_MODEL_UPDATED)
        ->where('subject_id', '=', $fixture['embedA']->id)
        ->firstOrFail();

    expect($updateAudit->details)->toEqual([
        'connection_id' => $fixture['connectionA']->id,
        'model' => CATALOGUE_SHARED_MODEL,
        'display_name' => 'ALPHA renamed',
        'capabilities' => 'embedding,text',
        'enabled' => false,
        'context_window' => 16384,
        'max_output_tokens' => 4096,
        'input_price_per_million' => '0.500000',
        'output_price_per_million' => '1.500000',
        'price_currency' => 'EUR',
    ]);
});

it('clears a price back to null, which is not the same as setting it to zero', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL: the row is priced right now, so "it is null afterwards" is a change rather
    // than a statement about a column that was always empty.
    expect($fixture['embedA']->input_price_per_million)->toBe('0.130000');

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        cataloguePutBody(['display_name' => 'ALPHA unpriced now']),
        spaHeaders(),
    )
        ->assertOk()
        // NULL AND NOT `'0.000000'`. "No price recorded" has to stay distinguishable from "free",
        // or a spend estimate over an unpriced catalogue reports that it costs nothing.
        ->assertJsonPath('data.input_price_per_million', null)
        ->assertJsonPath('data.output_price_per_million', null)
        ->assertJsonPath('data.price_currency', null);

    $row = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['embedA']->id);

    expect($row->input_price_per_million)->toBeNull()
        ->and($row->price_currency)->toBeNull();
});

it('refuses a PUT that omits a field, because the endpoint replaces the whole state', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE PATCH-SHAPED BODY A CLIENT WOULD SEND OUT OF HABIT. It is refused, and that is the point
    // of the PUT: with seven mutable attributes, "a body that changes nothing is refused" is not
    // expressible in rules() as a partial-update rule set that `kb:dump-form-rules` can see, so the
    // endpoint states the complete desired state instead. A silently-partial PUT would leave an
    // audit row describing a replacement that did not replace.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        ['display_name' => 'ALPHA partial'],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => [
            'supported', 'context_window', 'max_output_tokens', 'enabled',
            'input_price_per_million', 'output_price_per_million', 'price_currency',
        ]]);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($fixture['embedA']->id)->display_name)
        ->toBe('ALPHA embedding row');
});

// ── DELETE …/models/{model} ──────────────────────────────────────────────────────────────────────

it('hard-deletes a row and leaves the audit entry as its only description', function (): void {
    $fixture = catalogueOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['chatA']->id}",
        [],
        spaHeaders(),
    )
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    assertDatabaseMissing('provider_models', ['id' => $fixture['chatA']->id]);

    // THE AUDIT ROW IS THE ONLY SURVIVING DESCRIPTION, so it has to carry enough to say WHICH row
    // was removed from WHOSE catalogue — `subject_id` now points at a ULID no table resolves.
    $audit = AuditLog::query()
        ->where('operation', '=', AuditLogger::PROVIDER_MODEL_DELETED)
        ->where('subject_id', '=', $fixture['chatA']->id)
        ->firstOrFail();

    // toEqual and not toBe — `details` is jsonb and does not preserve key insertion order. The
    // three pricing keys are ABSENT rather than null: sanitize() skips a null without reporting
    // it, so an unpriced row simply omits them.
    expect($audit->details)->toEqual([
        'connection_id' => $fixture['connectionA']->id,
        'model' => 'gpt-5-alpha-chat',
        'display_name' => 'ALPHA chat row',
        'capabilities' => 'text,tool_use',
        'enabled' => true,
        // The factory's defaults, and load-bearing HERE more than anywhere else: after a hard
        // delete this row is the only surviving description of the entry, so a limit that was
        // never recorded is a limit nobody can reconstruct.
        'context_window' => 8192,
        'max_output_tokens' => 0,
    ]);

    // Org A's other row and org B's identically-named row both survive.
    assertDatabaseHas('provider_models', ['id' => $fixture['embedA']->id]);
    assertDatabaseHas('provider_models', ['id' => $fixture['embedB']->id]);
});

it('409s a delete of the designated embedding model, and changes nothing', function (): void {
    $fixture = catalogueOrgPair();

    designateCatalogueModel($fixture['orgA'], $fixture['embedA']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        [],
        spaHeaders(),
    );

    // 409, AND THE ACTIONABLE SENTENCE REACHES THE CLIENT VERBATIM. bootstrap/app.php's render
    // closure preserves an unclassified 4xx's own status AND its own message, which is what makes
    // a 409 acceptable here — there is no field to key a 422 on, because the refusal is about the
    // state of a DIFFERENT record.
    $response->assertStatus(409)
        ->assertJsonPath('message', ProviderModelService::DESIGNATED_FOR_EMBEDDING)
        // 409 is a RENDERING of an existing taxonomy row, not a nineteenth class, and it is never
        // retryable: the request conflicts with current state and would fail identically forever.
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', false);

    // NOTHING CHANGED — the row, and the designation that protects it.
    assertDatabaseHas('provider_models', [
        'id' => $fixture['embedA']->id,
        'organization_id' => $fixture['orgA']->id,
        'display_name' => 'ALPHA embedding row',
    ]);

    $organization = Organization::query()->findOrFail($fixture['orgA']->id);

    expect($organization->embedding_connection_id)->toBe($fixture['connectionA']->id)
        ->and($organization->embedding_model)->toBe(CATALOGUE_SHARED_MODEL);

    // AND NO AUDIT ROW. A refused delete that still wrote `provider.model.deleted` would make the
    // trail claim a removal that never happened.
    assertDatabaseMissing('audit_logs', [
        'operation' => AuditLogger::PROVIDER_MODEL_DELETED,
        'subject_id' => $fixture['embedA']->id,
    ]);
});

it('still deletes a NON-designated row under the designated connection', function (): void {
    $fixture = catalogueOrgPair();

    // THE DESIGNATION NAMES A PAIR, NOT A CONNECTION. Comparing only the connection id would
    // refuse every row under the designated credential — a dozen models an operator can no longer
    // tidy up because one of their siblings is in use. Without this test the 409 above passes
    // against exactly that over-broad check.
    designateCatalogueModel($fixture['orgA'], $fixture['embedA']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['chatA']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    assertDatabaseMissing('provider_models', ['id' => $fixture['chatA']->id]);
    assertDatabaseHas('provider_models', ['id' => $fixture['embedA']->id]);
});

it('does not treat another organization\'s designation of the same pair as a refusal', function (): void {
    $fixture = catalogueOrgPair();

    // ORG B DESIGNATES ITS OWN (connection, model) PAIR, whose MODEL IDENTIFIER is identical to
    // org A's. A check that compared `embedding_model` without also comparing the connection — or
    // that read the designation from the wrong organization — would refuse org A's delete for a
    // reason that lives in another tenant entirely.
    designateCatalogueModel($fixture['orgB'], $fixture['embedB']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$fixture['embedA']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    assertDatabaseMissing('provider_models', ['id' => $fixture['embedA']->id]);

    // AND ORG B'S DESIGNATION AND ROW ARE BOTH INTACT.
    assertDatabaseHas('provider_models', ['id' => $fixture['embedB']->id]);

    $orgB = Organization::query()->findOrFail($fixture['orgB']->id);

    expect($orgB->embedding_model)->toBe(CATALOGUE_SHARED_MODEL);
});

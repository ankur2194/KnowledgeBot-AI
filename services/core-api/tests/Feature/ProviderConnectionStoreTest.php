<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Support\Crypto\CredentialVault;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| Finding C1, items 2 and 3 — the connection save
|--------------------------------------------------------------------------
|
| Item 2: the save must NOT hard-fail on embedding readiness. Refusing to store an organization's
| only chat connection because it cannot also embed is worse than the disease.
| Item 3: a connection configured PURELY to embed is a first-class configuration, because with one
| sourced embedding vendor it is the only way an organization on any other vendor can ingest at all.
*/

const STORE_TEST_CREDENTIAL = 'kb-store-test-credential-DO-NOT-LOG-01JQZ';

/**
 * @return array{org: Organization, actor: User}
 */
function orgWithOwner(): array
{
    $org = Organization::factory()->create();

    return ['org' => $org, 'actor' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create()];
}

it('stores a connection whose only purpose is to embed', function (): void {
    // The Anthropic-only organization: it has a chat provider and no embedding provider, so it
    // cannot ingest a single document. Adding an OpenAI connection carrying nothing but an
    // embedding row is the whole remedy, and nothing may treat it as a malformed chat connection.
    $fixture = orgWithOwner();

    ProviderConnection::factory()->recycle($fixture['org'])
        ->provider(\App\Enums\Provider::Anthropic)
        ->withModel('claude-sonnet-5', ['text', 'tool_use'])
        ->create();

    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'selected' => [
            'connection_id' => '01JQZ0000000000000000000AA',
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
        ],
        'eligible' => [], 'rejected' => [], 'explanation' => '',
    ], 200)]);

    $response = currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Embedding only',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [[
                'model' => 'text-embedding-3-large',
                'display_name' => 'Text Embedding 3 Large',
                // Embedding and NOTHING else. Rows are task-exclusive: a row claiming both an
                // embedding flag and a chat flag describes a model that does not exist.
                'supported' => ['embedding'],
                'context_window' => 8192,
                'max_output_tokens' => 0,
            ]],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('embedding_readiness.ready', true);

    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['org']->id,
        'model' => 'text-embedding-3-large',
    ]);
});

it('stores a chat-only connection and reports the blocking banner instead of refusing', function (): void {
    $explanation = 'This organization has no embedding-capable provider connection, so it cannot '
        .'ingest any document.';

    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => $explanation,
    ], 200)]);

    $fixture = orgWithOwner();

    currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'anthropic',
            'label' => 'Claude',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [[
                'model' => 'claude-sonnet-5',
                'display_name' => 'Claude Sonnet 5',
                'supported' => ['text', 'tool_use'],
                'context_window' => 200000,
                'max_output_tokens' => 64000,
            ]],
        ])
        // 201, NOT 422. The organization now has a working chat provider and a real, named reason
        // it cannot ingest yet — which is exactly the state C1 exists to make visible early.
        ->assertCreated()
        ->assertJsonPath('embedding_readiness.blocks_ingestion', true)
        ->assertJsonPath('embedding_readiness.explanation', $explanation)
        // AND `designated` IS NULL AND PRESENT, not absent. This organization has designated
        // nothing, and the field carries that as a value rather than as a missing key — which is
        // what lets the providers screen say "choose a connection" here and "the connection you
        // chose is failing" when the same blocked verdict arrives with a pair stored. Creating a
        // connection never writes `organizations.embedding_connection_id`, so this is the bound
        // row and it is current.
        ->assertJsonPath('embedding_readiness.designated', null);

    assertDatabaseHas('provider_connections', [
        'organization_id' => $fixture['org']->id,
        'provider' => 'anthropic',
    ]);
});

it('seals the credential and renders only the last four', function (): void {
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => 'not yet',
    ], 200)]);

    $fixture = orgWithOwner();

    $response = currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Primary',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [],
        ]);

    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('data.id');

    // withoutGlobalScopes() HERE AND ONLY HERE, in a test, after the request has ended. The
    // OrganizationScope fails CLOSED when no tenant context is bound — which is the point of it —
    // and the context was cleared in the middleware's `finally` when the response was returned.
    // tests/Arch/StringLevelDoctrineTest.php FAILS on this call under app/, where it would be a real
    // finding; tests/ is deliberately not scanned, because reading the raw row is how a test proves
    // the filter hid it. That used to be a CI grep, and there is no CI.
    $connection = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($id);

    // POSITIVE CONTROL. Assert the credential ACTUALLY reached storage and round-trips, before
    // asserting it is absent from the response. A redaction test that passes because the key was
    // never used is not a redaction test.
    expect(app(CredentialVault::class)->open(
        (string) $connection->getAttribute('credential_ciphertext'),
        (string) $connection->getAttribute('data_key_ciphertext'),
    ))->toBe(STORE_TEST_CREDENTIAL);

    // str_contains(...)->toBeFalse(), not ->not->toContain(...): the latter takes only needles,
    // and `not` treats any failure as success, so it passes unconditionally.
    $body = (string) $response->getContent();

    expect(str_contains($body, STORE_TEST_CREDENTIAL))->toBeFalse()
        ->and(str_contains($body, 'credential_ciphertext'))->toBeFalse()
        ->and(str_contains($body, 'data_key_ciphertext'))->toBeFalse();

    // The masked form, and only the last four — never a prefix, which identifies the vendor and on
    // several providers the account.
    $response->assertJsonPath('data.masked_key', '…'.substr(STORE_TEST_CREDENTIAL, -4));
});

it('does not leak the credential into a validation error', function (): void {
    $fixture = orgWithOwner();

    $response = currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            // `provider` is invalid, so validation fails with the credential in the input.
            'provider' => 'not-a-vendor',
            'label' => 'Primary',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [],
        ]);

    $response->assertStatus(422)->assertJsonPath('error_class', 'validation');

    expect(str_contains((string) $response->getContent(), STORE_TEST_CREDENTIAL))->toBeFalse();
});

it('refuses an unknown vendor rather than letting the matrix answer for one', function (): void {
    // A provider string that is not one of the five resolves to `_UNKNOWN` on the data-plane side
    // and is rejected as VENDOR_HAS_NO_ENDPOINT — which fails closed, but for the wrong reason and
    // with a message that blames the vendor rather than the typo.
    $fixture = orgWithOwner();

    currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'nim',   // the spelling docs/11's factory scaffold used; capabilities.py says nvidia_nim
            'label' => 'NIM',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['provider']]);

    Http::assertNothingSent();
});

it('refuses the masked display value on the CREATE path, not just on rotation', function (): void {
    // THE HOLE S5 NAMED. `ProviderConnectionResource::openApiSchemas()` says posting `masked_key`
    // back into "a create or rotate request" would set the tenant's key to the literal text
    // `…abcd` — and until this rule landed only the ROTATE half was true. The create path relied
    // on `min:8` refusing it by coincidence, because `'…'.$last_four` happens to be five
    // characters, which is one column change away from evaporating.
    //
    // A create form is where the mistake is MOST likely: the obvious way to build "add another
    // connection like this one" is to seed the form from a resource, and `reset({...connection})`
    // keeps every key it is handed.
    $fixture = orgWithOwner();

    $existing = ProviderConnection::factory()->recycle($fixture['org'])->create();
    $mask = '…'.$existing->last_four;

    $response = currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Seeded from the resource',
            'credential' => $mask,
            'models' => [],
        ]);

    $response->assertStatus(422)->assertJsonPath('error_class', 'validation');

    // THE MESSAGE, NOT MERELY THE FIELD. `min:8` also puts an `errors.credential` key there, so a
    // field-presence assertion is green whether the guard runs or not — which is exactly how the
    // rotate path's own test stayed green against a rule that had never executed. Read from
    // `messages()` rather than copied, so it can only match if `not_regex` is what refused it.
    $response->assertJsonPath(
        'errors.credential.0',
        (new \App\Http\Requests\StoreProviderConnectionRequest)->messages()['credential.not_regex'],
    );

    // Nothing was stored, and no second connection appeared beside the fixture.
    expect(ProviderConnection::query()->withoutGlobalScopes()->count())->toBe(1);

    // The readiness call is made only after a successful write, so a refused body never reaches
    // the data plane.
    Http::assertNothingSent();
});

it('refuses a capability flag that is not a lower-case identifier', function (): void {
    // FINDING S3, THE INPUT HALF. `supported.*` was `['string', 'max:64']` with no vocabulary and
    // no character class, and the value is joined into `capabilities` and ECHOED into an
    // append-only `provider.model.*` audit row. A tenant posting a credential-shaped flag made
    // AuditLogger's shape backstop fire and erased the security-relevant field of the operation —
    // the flag list that decides which credential embeds the corpus — from a table that cannot be
    // corrected afterwards. The same string also crosses the internal seam as a `capability_flags`
    // member.
    //
    // The class is deliberately a SHAPE and not a vocabulary: the capability matrix that matters is
    // services/ai-service/app/providers/capabilities.py, and a second copy here would drift.
    $fixture = orgWithOwner();

    currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Primary',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [[
                'model' => 'text-embedding-3-large',
                'display_name' => 'Text Embedding 3 Large',
                'supported' => ['embedding', 'sk-aaaaaaaaaaaa'],
                'context_window' => 8192,
                'max_output_tokens' => 0,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['models.0.supported.1']]);

    expect(ProviderConnection::query()->withoutGlobalScopes()->count())->toBe(0);

    Http::assertNothingSent();
});

it('still accepts a flag nobody here has heard of', function (): void {
    // THE CONTROL. The character class must not become a vocabulary by accident: this repository
    // does not own the capability matrix, so a flag it has never seen has to save. Without this,
    // the obvious "improvement" — a Rule::in over the flags we happen to know — passes the test
    // above and quietly makes a vendor's new capability a code change here.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => 'nothing can embed',
    ], 200)]);

    $fixture = orgWithOwner();

    currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Primary',
            'credential' => STORE_TEST_CREDENTIAL,
            'models' => [[
                'model' => 'gpt-6',
                'display_name' => 'GPT-6',
                'supported' => ['text', 'tool_use', 'a_flag_nobody_here_has_heard_of'],
                'context_window' => 8192,
                'max_output_tokens' => 4096,
            ]],
        ])
        ->assertCreated();
});

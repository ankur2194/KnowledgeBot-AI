<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Providers\ProviderConnectionService;
use App\Support\Crypto\CredentialVault;
use Database\Factories\ProviderConnectionFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The provider-connection resource — index, show, update, destroy, rotate
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half. The authorization and isolation half is
| tests/Security/ProviderConnectionAccessTest.php, and the two are separate files because they fail
| for different reasons and a reviewer reads them for different questions.
|
| EVERY FIXTURE HERE IS TWO ORGANIZATIONS with overlapping, distinguishable data, even in the
| behaviour file. A one-organization fixture passes every assertion below against code with the
| tenant filter deleted, so there is no "this test does not need isolation" case — the delete tests
| in particular assert that org B's connection and its model rows are still standing afterwards,
| which is the only way a delete keyed on the wrong predicate becomes visible.
|
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() is the intended home for this and throws
| by design until the Bot and KnowledgeSource factories exist. The hand-rolled pair below copies
| invitationOrgPair()'s shape and must be replaced by tenantPair() when it lands.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing
    // else, and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket
    // survives the test that filled it and the next run of the suite. `login` is 20/minute per IP
    // and every request here arrives from one address, so without this a suite that logs in for
    // real is a 429 storm that only appears in CI.
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The plaintext a rotation submits.
 *
 * DELIBERATELY DIFFERENT FROM ProviderConnectionFactory::FIXTURE_CREDENTIAL, so a rotation test can
 * tell "the new key was stored" apart from "nothing happened and the old key is still there" — with
 * one literal both assertions pass against a no-op endpoint. The LAST FOUR is lower-case
 * alphanumeric on purpose: a ULID is upper-case base32, so `zq7x` cannot appear inside a subject id
 * or a request id and an absence assertion over an audit row cannot go green or red by accident.
 */
const ROTATION_TEST_CREDENTIAL = 'kb-rotation-test-credential-DO-NOT-LOG-zq7x';

/**
 * Two organizations, each with two connections, all four distinguishable by label.
 *
 * The LABELS are the readable fixture markers the isolation assertions grep for; the ADDRESSES are
 * unique per test, because `throttle:login` keys on the submitted address at five per minute and
 * this fixture is used by more than a dozen tests across two files.
 *
 * `->recycle($org)` on every factory, without exception. `for($org)` fixes one edge; every NESTED
 * factory the definition resolves still mints its own organization, and ProviderConnectionFactory
 * refuses to run without a recycled one for exactly that reason.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     chatA: ProviderConnection, embedA: ProviderConnection,
 *     chatB: ProviderConnection, embedB: ProviderConnection,
 * }
 */
function providerOrgPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Provider Org ALPHA', 'slug' => 'provider-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Provider Org BRAVO', 'slug' => 'provider-bravo']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Alpha', 'email' => SpaSession::uniqueEmail('prov-owner-alpha')]),
        'ownerB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
            ->create(['name' => 'Owner Bravo', 'email' => SpaSession::uniqueEmail('prov-owner-bravo')]),

        // Two per organization so `index` can assert a COUNT as well as a membership — a list that
        // returned one row of the right organization and one of the wrong one has the right length
        // for a fixture with one connection each.
        'chatA' => ProviderConnection::factory()->recycle($orgA)
            ->provider(Provider::Anthropic)
            ->withModel('claude-sonnet-5', ['text'])
            ->create(['label' => 'ALPHA chat']),
        'embedA' => ProviderConnection::factory()->recycle($orgA)
            ->provider(Provider::OpenAI)
            ->withModel('text-embedding-3-large', ['embedding'])
            ->create(['label' => 'ALPHA embedding']),
        'chatB' => ProviderConnection::factory()->recycle($orgB)
            ->provider(Provider::Anthropic)
            ->withModel('claude-sonnet-5', ['text'])
            ->create(['label' => 'BRAVO chat']),
        'embedB' => ProviderConnection::factory()->recycle($orgB)
            ->provider(Provider::OpenAI)
            ->withModel('text-embedding-3-large', ['embedding'])
            ->create(['label' => 'BRAVO embedding']),
    ];
}

/**
 * Point an organization's embedding designation at one of its own connections.
 *
 * `forceFill` because neither column is fillable — the designation is normally written only by
 * EmbeddingDesignationService, which validates the pair against the organization's own connections
 * first. Both columns together, because `organizations_embedding_designation_complete` CHECKs
 * `num_nonnulls(...) <> 1`: half a designation is not a state the table can hold.
 */
function designateForEmbedding(Organization $organization, ProviderConnection $connection, string $model): void
{
    $organization->forceFill([
        'embedding_connection_id' => $connection->id,
        'embedding_model' => $model,
    ])->save();
}

// ── GET /api/v1/organizations/{organization}/provider-connections ────────────────────────────────

it('lists only the addressed organization\'s connections, every status, masked', function (): void {
    $fixture = providerOrgPair();

    // A revoked row, so the list is exercised as "every status" rather than "the happy ones".
    $revoked = ProviderConnection::factory()->recycle($fixture['orgA'])->revoked()
        ->create(['label' => 'ALPHA retired']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections",
        spaHeaders(),
    );

    // POSITIVE CONTROL FIRST: org A's own three rows are there, including the revoked one. Hiding
    // a revoked credential would make "why did ingestion stop" unanswerable from the console — and
    // without this assertion every absence check below also passes against an endpoint that
    // returns nothing at all.
    $response->assertOk()->assertJsonCount(3, 'data.connections');

    $ids = array_column((array) $response->json('data.connections'), 'id');

    expect($ids)->toContain($fixture['chatA']->id)
        ->and($ids)->toContain($fixture['embedA']->id)
        ->and($ids)->toContain($revoked->id);

    $statuses = array_column((array) $response->json('data.connections'), 'status');

    expect($statuses)->toContain(ProviderConnectionStatus::Active->value)
        ->and($statuses)->toContain(ProviderConnectionStatus::Revoked->value);

    $body = (string) $response->getContent();

    // THE ISOLATION ASSERTION. Org B holds connections to the SAME two vendors carrying the same
    // two model ids, so a missing organization predicate returns rows that look perfectly
    // plausible — only the label and the id give it away.
    //
    // str_contains(...)->toBeFalse(), never ->not->toContain(...): toContain() takes only needles,
    // so a message argument becomes a second needle, and `not` treats any failure as success. That
    // exact shape has already hidden a real tenant-id leak in this repo.
    expect(str_contains($body, $fixture['chatB']->id))->toBeFalse('org B\'s connection id is in org A\'s list');
    expect(str_contains($body, $fixture['embedB']->id))->toBeFalse('org B\'s connection id is in org A\'s list');
    expect(str_contains($body, 'BRAVO'))->toBeFalse('org B\'s label is in org A\'s list');

    // NO CREDENTIAL, IN ANY FORM. The positive control for this one is the factory: it seals
    // FIXTURE_CREDENTIAL for real, so the plaintext genuinely exists in the row being rendered.
    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the plaintext provider credential reached a list response');
    expect(str_contains($body, 'credential_ciphertext'))->toBeFalse();
    expect(str_contains($body, 'data_key_ciphertext'))->toBeFalse();
    expect(str_contains($body, 'key_version'))->toBeFalse();

    // The masked form is the only derived form that leaves the process, and it is four characters
    // and never a prefix.
    $response->assertJsonPath(
        'data.connections.0.masked_key',
        '…'.substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4),
    );
});

it('orders the list deterministically, so two reads of an unchanged set agree', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $url = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections";

    // POSITIVE CONTROL: there is more than one row, so an ordering assertion is capable of failing
    // — over a single-row list every possible order is the same order.
    $first = currentTest()->getJson($url, spaHeaders())->assertOk()->assertJsonCount(2, 'data.connections');
    $second = currentTest()->getJson($url, spaHeaders())->assertOk()->assertJsonCount(2, 'data.connections');

    expect(array_column((array) $second->json('data.connections'), 'id'))
        ->toBe(array_column((array) $first->json('data.connections'), 'id'));
});

// ── GET …/provider-connections/{providerConnection} ──────────────────────────────────────────────

it('shows one connection, masked, and nothing about the key', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['chatA']->id}",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.id', $fixture['chatA']->id)
        ->assertJsonPath('data.provider', Provider::Anthropic->value)
        ->assertJsonPath('data.label', 'ALPHA chat')
        ->assertJsonPath('data.masked_key', '…'.substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    $body = (string) $response->getContent();

    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))->toBeFalse();
    expect(str_contains($body, 'BRAVO'))->toBeFalse();
});

// ── PATCH …/provider-connections/{providerConnection} ────────────────────────────────────────────

it('edits the label and the status, and audits the result', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['chatA']->id}",
        ['label' => 'ALPHA chat (renamed)', 'status' => ProviderConnectionStatus::Revoked->value],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.label', 'ALPHA chat (renamed)')
        ->assertJsonPath('data.status', ProviderConnectionStatus::Revoked->value);

    assertDatabaseHas('provider_connections', [
        'id' => $fixture['chatA']->id,
        'organization_id' => $fixture['orgA']->id,
        'label' => 'ALPHA chat (renamed)',
        'status' => ProviderConnectionStatus::Revoked->value,
    ]);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CONNECTION_UPDATED)
        ->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->actor_id)->toBe($fixture['ownerA']->id)
        ->and($audit->subject_id)->toBe($fixture['chatA']->id)
        // The values AFTER the edit, which is what makes the row a description of the change
        // rather than of the request.
        ->and($audit->details['label'] ?? null)->toBe('ALPHA chat (renamed)')
        ->and($audit->details['status'] ?? null)->toBe(ProviderConnectionStatus::Revoked->value)
        ->and($audit->details['provider'] ?? null)->toBe(Provider::Anthropic->value);
});

it('accepts either field alone and refuses a body that changes neither', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $url = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['chatA']->id}";

    // Label alone.
    currentTest()->patchJson($url, ['label' => 'ALPHA renamed once'], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.label', 'ALPHA renamed once')
        ->assertJsonPath('data.status', ProviderConnectionStatus::Active->value);

    // Status alone — the label from the previous call must survive, which is what proves the
    // partial update is partial rather than a full replace with defaults.
    currentTest()->patchJson($url, ['status' => ProviderConnectionStatus::Revoked->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.label', 'ALPHA renamed once')
        ->assertJsonPath('data.status', ProviderConnectionStatus::Revoked->value);

    // Neither. A 200 here would leave an audit row describing an edit that did not happen.
    currentTest()->patchJson($url, [], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['label', 'status']]);
});

it('refuses a blank label, which used to erase the connection\'s identity from the audit trail', function (): void {
    // FINDING S7. `required_without:status` is satisfied by `status` being PRESENT, and `string` and
    // `max:120` both pass on the empty string — so this exact body was accepted and blanked the
    // label, while StoreProviderConnectionRequest makes the same field `required`. Two endpoints,
    // two answers to "may a connection be nameless".
    //
    // WHAT IT COST IS AN AUDIT ROW. `label` is ECHOED into `provider.connection.updated` and into
    // every later `provider.connection.deleted` row for the same connection — and a delete is a
    // HARD delete, so that row is the only surviving description of what was removed and its
    // `subject_id` resolves to nothing afterwards. AuditLogger::sanitize() skips an empty string
    // SILENTLY and deliberately (a model row with no capability flags legitimately renders as `''`,
    // and warning on that would fire on every unflagged write), so the field simply vanished from
    // an append-only table with nothing raised anywhere.
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $connection = $fixture['chatA'];
    $url = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}";
    $before = $connection->label;

    currentTest()->patchJson(
        $url,
        ['label' => '', 'status' => ProviderConnectionStatus::Active->value],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['label']]);

    // The row is untouched, and so is the trail: a refused edit writes no `updated` row at all.
    expect(ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id)->label)
        ->toBe($before);

    expect(AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CONNECTION_UPDATED)
        ->where('subject_id', $connection->id)
        ->count())->toBe(0);

    // A ONE-CHARACTER LABEL IS STILL LEGAL. `min:1` is a floor on emptiness, not a naming policy,
    // and asserting the boundary is what stops it drifting into one.
    currentTest()->patchJson($url, ['label' => 'x'], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.label', 'x');
});

it('will not accept a credential on the edit endpoint', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $connection = $fixture['chatA'];
    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}",
        [
            'label' => 'ALPHA chat',
            // Over-posted. There is no rule for it, no DTO member for it, and no vault call on
            // this path — so it is dropped by `validated()` and cannot reach a column.
            'credential' => ROTATION_TEST_CREDENTIAL,
            // And the ownership column, for the same reason: over-posting a tenant key is an
            // authorization bug with a 200 response.
            'organization_id' => $fixture['orgB']->id,
        ],
        spaHeaders(),
    )->assertOk();

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // POSITIVE CONTROL FIRST: the row really does hold a sealed credential, so "the ciphertext did
    // not change" is a statement about a credential that exists rather than about an empty column.
    expect(app(CredentialVault::class)->open(
        (string) $after->getAttribute('credential_ciphertext'),
        (string) $after->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    expect($after->getAttribute('credential_ciphertext'))->toBe($before->getAttribute('credential_ciphertext'))
        ->and($after->last_four)->toBe($before->last_four)
        ->and($after->credential_version)->toBe($before->credential_version)
        // The connection did not move organization either.
        ->and($after->organization_id)->toBe($fixture['orgA']->id);
});

// ── DELETE …/provider-connections/{providerConnection} ───────────────────────────────────────────

it('deletes a connection and its model rows, and leaves the other organization standing', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL FIRST: the model row this delete has to take with it actually exists. The
    // FK from provider_models is ON DELETE RESTRICT, so a delete that forgot the children would
    // fail with 23503 rather than silently orphan them — but a fixture with no children would make
    // the whole assertion vacuous.
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $fixture['chatA']->id,
    ]);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['chatA']->id}",
        [],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    assertDatabaseMissing('provider_connections', ['id' => $fixture['chatA']->id]);
    assertDatabaseMissing('provider_models', ['provider_connection_id' => $fixture['chatA']->id]);

    // ORG A'S OTHER CONNECTION IS UNTOUCHED — a delete keyed on the organization alone would take
    // it too.
    assertDatabaseHas('provider_connections', ['id' => $fixture['embedA']->id]);

    // AND ORG B IS UNTOUCHED, connections and model rows alike. This is the assertion a delete
    // whose predicate lost its organization term fails.
    assertDatabaseHas('provider_connections', ['id' => $fixture['chatB']->id]);
    assertDatabaseHas('provider_connections', ['id' => $fixture['embedB']->id]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgB']->id,
        'provider_connection_id' => $fixture['chatB']->id,
    ]);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CONNECTION_DELETED)
        ->firstOrFail();

    // The row outlives the record it describes, which is the whole reason `provider`, `label` and
    // `status` are echoed: `subject_id` now points at a ULID no table resolves.
    expect($audit->subject_id)->toBe($fixture['chatA']->id)
        ->and($audit->details['label'] ?? null)->toBe('ALPHA chat')
        ->and($audit->details['provider'] ?? null)->toBe(Provider::Anthropic->value);
});

it('refuses to delete the designated embedding connection, and changes nothing', function (): void {
    $fixture = providerOrgPair();

    designateForEmbedding($fixture['orgA'], $fixture['embedA'], 'text-embedding-3-large');

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['embedA']->id}",
        [],
        spaHeaders(),
    );

    // 409, with the actionable sentence reaching the client verbatim: bootstrap/app.php's
    // unclassified-4xx arm preserves an HttpException's own status AND its own message, so
    // `message` is the constant rather than a class-mapped platitude.
    $response->assertStatus(409)
        ->assertJsonPath('message', ProviderConnectionService::DESIGNATED_FOR_EMBEDDING)
        // `retryable` is false: nothing is unavailable and retrying will never work while the
        // designation stands.
        ->assertJsonPath('retryable', false);

    // NOTHING CHANGED — the connection, its model rows, and the designation itself.
    assertDatabaseHas('provider_connections', ['id' => $fixture['embedA']->id]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $fixture['embedA']->id,
    ]);
    expect($fixture['orgA']->fresh()?->embedding_connection_id)->toBe($fixture['embedA']->id);

    // And no audit row claiming a deletion that did not happen.
    expect(AuditLog::query()->where('operation', AuditLogger::PROVIDER_CONNECTION_DELETED)->count())
        ->toBe(0);

    // THE POSITIVE CONTROL FOR THE GUARD: the SAME organization's other connection, which is not
    // designated, deletes fine. Without this the test also passes against an endpoint that refuses
    // every delete.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['chatA']->id}",
        [],
        spaHeaders(),
    )->assertOk();
});

// ── PUT …/provider-connections/{providerConnection}/credential ───────────────────────────────────

it('rotates the credential, reseals it, and returns a new mask', function (): void {
    $fixture = providerOrgPair();

    $connection = $fixture['chatA'];
    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // POSITIVE CONTROL FIRST: the OLD key really is in the row and round-trips. A rotation test
    // that passes because nothing was ever encrypted is not a rotation test.
    expect(app(CredentialVault::class)->open(
        (string) $before->getAttribute('credential_ciphertext'),
        (string) $before->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        [
            'current_password' => UserFactory::PASSWORD,
            'credential' => ROTATION_TEST_CREDENTIAL,
        ],
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.id', $connection->id)
        // The mask moved, which is the only visible evidence a client gets.
        ->assertJsonPath('data.masked_key', '…'.substr(ROTATION_TEST_CREDENTIAL, -4))
        // And the connection came back to `active`, which is what makes rotation the remedy for an
        // `invalid` or `revoked` credential rather than a dead end.
        ->assertJsonPath('data.status', ProviderConnectionStatus::Active->value);

    // withoutGlobalScopes() HERE AND ONLY HERE, in a test, after the request has ended. The
    // OrganizationScope fails CLOSED with no tenant context bound — which is the point of it — and
    // the context was cleared in the middleware's `finally` when the response was returned.
    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // THE NEW KEY IS WHAT IS STORED, and it round-trips through the vault.
    expect(app(CredentialVault::class)->open(
        (string) $after->getAttribute('credential_ciphertext'),
        (string) $after->getAttribute('data_key_ciphertext'),
    ))->toBe(ROTATION_TEST_CREDENTIAL);

    expect($after->last_four)->toBe(substr(ROTATION_TEST_CREDENTIAL, -4))
        ->and($after->last_four)->not->toBe($before->last_four)
        // THE MONOTONIC COUNTER MOVED. `credential_version` and not `key_version`: the latter is
        // the KEY-ENCRYPTING KEY's version and stands still while the platform's KEK does — see
        // the migration that adds this column for why the two cannot be one.
        ->and($after->credential_version)->toBe($before->credential_version + 1)
        ->and($after->key_version)->toBe($before->key_version);

    // NEITHER KEY REACHES THE BODY — not the new one, and not the one it replaced.
    $body = (string) $response->getContent();

    expect(str_contains($body, ROTATION_TEST_CREDENTIAL))
        ->toBeFalse('the new provider credential reached the response body');
    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the replaced provider credential reached the response body');
    expect(str_contains($body, 'current_password'))->toBeFalse();
});

it('refuses a wrong password without touching the row', function (): void {
    $fixture = providerOrgPair();

    $connection = $fixture['chatA'];
    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        [
            'current_password' => 'not-the-fixture-password',
            'credential' => ROTATION_TEST_CREDENTIAL,
        ],
        spaHeaders(),
    );

    $response->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['current_password']]);

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // BOTH ASSERTED: the counter and the ciphertext. Either alone would pass against a partial
    // write, and a partial write is exactly what a re-authentication placed after the update would
    // produce.
    expect($after->credential_version)->toBe($before->credential_version)
        ->and($after->getAttribute('credential_ciphertext'))->toBe($before->getAttribute('credential_ciphertext'))
        ->and($after->getAttribute('data_key_ciphertext'))->toBe($before->getAttribute('data_key_ciphertext'))
        ->and($after->last_four)->toBe($before->last_four);

    // The old key is still the one that opens the row.
    expect(app(CredentialVault::class)->open(
        (string) $after->getAttribute('credential_ciphertext'),
        (string) $after->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    // No SUCCESS row for a rotation that did not happen.
    expect(AuditLog::query()->where('operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATED)->count())
        ->toBe(0);

    // BUT THE ATTEMPT IS RECORDED, and until this row existed it was not. §18.11 asks for
    // credential changes to be auditable, and an ATTEMPT on the endpoint that changes a credential
    // is the half a post-incident timeline needs: `auth.login.failed` covers the login surface and
    // this one had no equivalent, so a session stolen through XSS could grind at the
    // re-authentication behind it and leave `audit_logs` completely silent.
    $failure = AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATION_FAILED)
        ->sole();

    expect($failure->outcome)->toBe(AuditLogger::OUTCOME_FAILURE)
        // The actor IS known here, unlike on a failed login: the session authenticated and it is
        // the password behind it that did not. That asymmetry is the value of the row.
        ->and($failure->actor_id)->toBe($fixture['ownerA']->id)
        ->and($failure->organization_id)->toBe($fixture['orgA']->id)
        ->and($failure->subject_id)->toBe($connection->id)
        // `toEqual` AND NOT `toBe`, AND THE ORDER BELOW IS THE CALL SITE'S, NOT THE ROW'S. `toBe`
        // is `===`, which for arrays compares key ORDER as well as contents, and `details` is a
        // `jsonb` column. jsonb does not store an object as written: it sorts keys by LENGTH first
        // and bytewise second, so these three come back `label` (5), `status` (6), `provider` (8)
        // however the call site or the allow-list arranged them. Writing them in jsonb's order here
        // would look like an arbitrary choice and would silently stop tracking the call site the
        // moment a field is renamed — `label` -> `display_label` reorders the row with nothing
        // changing in this file. So they are written in the order
        // RotateProviderCredentialRequest::recordFailedReauthentication() passes them, and the
        // comparison is made order-insensitive instead.
        //
        // THE "EXACTLY" PROPERTY IS NOT WEAKENED, which is the only reason this is the right fix
        // rather than a loosened assertion: `toEqual` on arrays still requires the same KEY SET, so
        // an extra field appearing in the row still fails here. What it gives up is scalar type
        // strictness across the comparison, and all three values are strings.
        ->and($failure->details)->toEqual([
            'provider' => $connection->provider->value,
            'label' => $connection->label,
            'status' => $connection->status->value,
        ]);

    // NEITHER SECRET IS IN THE ROW, and `details` is asserted EXACTLY above rather than by
    // key-presence so a new key cannot appear without this failing. This is the belt: the allow-list
    // is the braces, and the call site offers neither value in the first place.
    $encoded = (string) json_encode($failure->details);

    expect(str_contains($encoded, 'not-the-fixture-password'))->toBeFalse();
    expect(str_contains($encoded, ROTATION_TEST_CREDENTIAL))->toBeFalse();

    // Neither secret is in the failure body — the submitted key, or the password that was wrong.
    $body = (string) $response->getContent();

    expect(str_contains($body, ROTATION_TEST_CREDENTIAL))->toBeFalse();
    expect(str_contains($body, 'not-the-fixture-password'))->toBeFalse();
});

it('writes no rotation-failure row when the password was right', function (): void {
    // THE CONTROL FOR THE ROW ABOVE. Without it, `credential_rotation_failed` could be written on
    // EVERY 422 — a mask posted back, a missing field, a key over `max:512` — and the assertion
    // that it exists after a wrong password would still pass. The row means one thing: the actor
    // could not prove they are who the session says. `failedValidation()` keys on the
    // `CurrentPassword` rule for exactly that reason, not on the FIELD, because `current_password`
    // also carries `required` and a form posting before it is filled in is a client bug rather
    // than an attempt.
    $fixture = providerOrgPair();

    $connection = $fixture['chatA'];

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // A CORRECT password with a credential the mask guard refuses: the request is a 422 and the
    // re-authentication succeeded.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => '…'.$connection->last_four],
        spaHeaders(),
    )->assertStatus(422);

    expect(AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATION_FAILED)
        ->count())->toBe(0);

    // And a body with NO password at all is likewise not an attempt.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        ['credential' => ROTATION_TEST_CREDENTIAL],
        spaHeaders(),
    )->assertStatus(422);

    expect(AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATION_FAILED)
        ->count())->toBe(0);
});

it('refuses the masked display value posted back as a credential', function (): void {
    $fixture = providerOrgPair();

    $connection = $fixture['chatA'];
    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE BUG THIS RULE EXISTS TO STOP, reproduced exactly: a console form seeded from the
    // resource it just fetched posts `masked_key` back. Without the rule it would be sealed and
    // stored as the tenant's provider key — whose next chat turn fails with `provider_auth`
    // against the literal text `…1JQZ`.
    //
    // THE COMMENT THAT USED TO SIT HERE SAID THE MASK "CLEARS THE LENGTH FLOOR", AND IT WAS FALSE.
    // `masked_key` is U+2026 plus four characters — FIVE — so it fails `min:8`, and while
    // `not_regex` sat BELOW the length rules under `bail` the guard never executed once. This test
    // was green the whole time, because it asserted only that an `errors.credential` key existed
    // and `min` puts one there. `not_regex` now runs first, and the assertion is on the MESSAGE,
    // which is the only thing that can tell the two rules apart.
    $mask = '…'.$before->last_four;

    expect(mb_strlen($mask))->toBeLessThan(
        8,
        'the mask now clears the length floor, so this test would pass on `min` again if the '
        .'message were not asserted — which is exactly how the guard went unexercised before',
    );

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => $mask],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        // READ FROM THE FormRequest RATHER THAN COPIED. A literal here would go stale the day the
        // sentence is reworded and would then assert nothing about which rule fired; taken from
        // `messages()`, it can only match if `not_regex` is what refused the value.
        ->assertJsonPath(
            'errors.credential.0',
            (new \App\Http\Requests\RotateProviderCredentialRequest)->messages()['credential.not_regex'],
        );

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    expect($after->credential_version)->toBe($before->credential_version)
        ->and($after->getAttribute('credential_ciphertext'))->toBe($before->getAttribute('credential_ciphertext'));

    // The password was CORRECT on that request, so this proves the mask rule and not the
    // re-authentication is what refused it.
    expect(app(CredentialVault::class)->open(
        (string) $after->getAttribute('credential_ciphertext'),
        (string) $after->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);
});

it('rotates a revoked connection back into service', function (): void {
    $fixture = providerOrgPair();

    $connection = ProviderConnection::factory()->recycle($fixture['orgA'])->revoked()
        ->create(['label' => 'ALPHA leaked key']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // §18.4's worked example refuses to rotate anything but an `active` credential. That is wrong
    // for this product: `revoked` is what an operator sets when a key leaks and `invalid` is what a
    // failed connection check leaves behind, and replacing the key is the remedy for both. A status
    // check here would make "delete the connection and re-create it" the only way out — losing its
    // id, its model rows and any designation pointing at it.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => ROTATION_TEST_CREDENTIAL],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.status', ProviderConnectionStatus::Active->value);
});

// ── the audit trail carries no part of any credential ────────────────────────────────────────────

it('writes no part of a credential into any audit row it creates', function (): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $base = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections";

    // Drive all three audited write paths in one test, so the assertion runs over the whole set
    // rather than over whichever one somebody remembered.
    currentTest()->patchJson("{$base}/{$fixture['chatA']->id}", ['label' => 'ALPHA edited'], spaHeaders())
        ->assertOk();
    currentTest()->putJson(
        "{$base}/{$fixture['chatA']->id}/credential",
        ['current_password' => UserFactory::PASSWORD, 'credential' => ROTATION_TEST_CREDENTIAL],
        spaHeaders(),
    )->assertOk();
    currentTest()->deleteJson("{$base}/{$fixture['chatA']->id}", [], spaHeaders())->assertOk();

    $rows = AuditLog::query()
        ->whereIn('operation', [
            AuditLogger::PROVIDER_CONNECTION_UPDATED,
            AuditLogger::PROVIDER_CREDENTIAL_ROTATED,
            AuditLogger::PROVIDER_CONNECTION_DELETED,
        ])
        ->get();

    // POSITIVE CONTROL FIRST. If the three writes produced no rows, every absence assertion below
    // iterates an empty collection and the test is decoration.
    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        $details = json_encode($row->details, JSON_THROW_ON_ERROR);

        // The plaintext of both keys — the one the fixture sealed and the one the rotation
        // submitted — and the LAST FOUR of each, which is the only derived form that may be
        // rendered anywhere and still has no business in an append-only table.
        expect(str_contains($details, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
            ->toBeFalse("{$row->operation} echoed the sealed credential");
        expect(str_contains($details, ROTATION_TEST_CREDENTIAL))
            ->toBeFalse("{$row->operation} echoed the rotated credential");
        expect(str_contains($details, substr(ROTATION_TEST_CREDENTIAL, -4)))
            ->toBeFalse("{$row->operation} carried the new key's last four");
        expect(str_contains($details, UserFactory::PASSWORD))
            ->toBeFalse("{$row->operation} carried the actor's password");

        // And no credential-shaped KEY, whatever its value. `credential_version` is an integer
        // count and is exempt by exact name; anything else matching is a map defect.
        foreach (array_keys((array) $row->details) as $key) {
            $key = (string) $key;

            if ($key === 'credential_version') {
                continue;
            }

            expect((bool) preg_match('/credential|api[_-]?key|secret|password|token|ciphertext|kek|last_four|masked/i', $key))
                ->toBeFalse("{$row->operation} allow-lists a credential-shaped detail key: {$key}");
        }
    }

    // The rotation row DOES carry the two version numbers, and they identify which key was live
    // without being derivable back to it. Asserted positively so the absence checks above cannot
    // pass by the row being empty.
    /** @var AuditLog $rotation */
    $rotation = $rows->firstWhere('operation', AuditLogger::PROVIDER_CREDENTIAL_ROTATED);

    expect($rotation->details['credential_version'] ?? null)->toBe(2)
        ->and($rotation->details['key_version'] ?? null)->toBeInt();
});

// ── the create path is audited too, which it was not before this change ──────────────────────────

it('audits the connection that the store action creates, which it did not before', function (): void {
    $fixture = providerOrgPair();

    // PRECONDITION, asserted rather than assumed: the fixture's four connections were made by the
    // FACTORY, which is not the endpoint and writes no audit row. Without this line a row left by
    // the fixture would satisfy the firstOrFail() below and the test would pass against a `store`
    // that still audits nothing — which is exactly the state this change set found it in.
    expect(AuditLog::query()->where('operation', AuditLogger::PROVIDER_CONNECTION_CREATED)->count())
        ->toBe(0, 'something other than the endpoint already wrote a `created` row');

    // Http::fake() is confined to tests/Feature by the suite's own rules — it is banned outright
    // under tests/Security and tests/Contract — and stands in here only for the readiness call
    // `store` makes AFTER the write. Nothing about the audit row depends on it.
    Http::fake([
        '*/internal/v1/embedding/readiness' => Http::response([
            'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => 'not yet',
        ], 200),
    ]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections",
        [
            'provider' => Provider::OpenAI->value,
            'label' => 'ALPHA newly stored',
            'credential' => ROTATION_TEST_CREDENTIAL,
            'models' => [],
        ],
        spaHeaders(),
    );

    $response->assertCreated();

    /** @var string $id */
    $id = $response->json('data.id');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()
        ->where('operation', AuditLogger::PROVIDER_CONNECTION_CREATED)
        ->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->actor_id)->toBe($fixture['ownerA']->id)
        ->and($audit->subject_id)->toBe($id)
        ->and($audit->details['provider'] ?? null)->toBe(Provider::OpenAI->value)
        ->and($audit->details['label'] ?? null)->toBe('ALPHA newly stored')
        ->and($audit->details['status'] ?? null)->toBe(ProviderConnectionStatus::Active->value);

    // The key that was just sealed is in neither the row nor the response.
    expect(str_contains(json_encode($audit->details, JSON_THROW_ON_ERROR), ROTATION_TEST_CREDENTIAL))
        ->toBeFalse('the created audit row echoed the provider credential');
    expect(str_contains((string) $response->getContent(), ROTATION_TEST_CREDENTIAL))
        ->toBeFalse('the create response echoed the provider credential');

    // ON_FAILURE_ABORT, pinned here as well as in AuditLoggerTest, because THIS is the endpoint
    // whose transaction the policy governs: a failed audit write must leave no stored credential.
    expect(AuditLogger::OPERATIONS[AuditLogger::PROVIDER_CONNECTION_CREATED]['on_failure'])
        ->toBe(AuditLogger::ON_FAILURE_ABORT);
});

it('404s a foreign or unknown connection id at binding time, on every verb', function (
    string $case,
    bool $foreign,
    string $verb,
): void {
    $fixture = providerOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $id = $foreign ? $fixture['chatB']->id : (string) Str::ulid();
    $url = "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$id}";

    // ->scopeBindings() resolves {providerConnection} through $organization->providerConnections(),
    // so a foreign id never loads: this 404s BEFORE the policy runs and before the row is in
    // memory. A bare `ProviderConnection $providerConnection` binding would be a global find with
    // no organization predicate, executed upstream of every check.
    $response = match ($verb) {
        'GET' => currentTest()->getJson($url, spaHeaders()),
        'PATCH' => currentTest()->patchJson($url, ['label' => 'stolen'], spaHeaders()),
        'DELETE' => currentTest()->deleteJson($url, [], spaHeaders()),
        default => currentTest()->putJson(
            $url.'/credential',
            ['current_password' => UserFactory::PASSWORD, 'credential' => ROTATION_TEST_CREDENTIAL],
            spaHeaders(),
        ),
    };

    $response->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization')
        ->assertJsonPath('message', 'The requested resource was not found.');

    // DID IT ACT ANYWAY? A 404 that still performed the write is the failure this half catches,
    // and it is invisible to a status assertion.
    $survivor = ProviderConnection::query()->withoutGlobalScopes()->find($fixture['chatB']->id);

    expect($survivor)->not->toBeNull("[{$case}/{$verb}] deleted org B's connection anyway");
    expect($survivor?->label)->toBe('BRAVO chat', "[{$case}/{$verb}] edited org B's connection anyway");
    expect($survivor?->credential_version)->toBe(1, "[{$case}/{$verb}] rotated org B's credential anyway");
})->with([
    'another organization\'s connection, GET' => ['foreign connection', true, 'GET'],
    'another organization\'s connection, PATCH' => ['foreign connection', true, 'PATCH'],
    'another organization\'s connection, DELETE' => ['foreign connection', true, 'DELETE'],
    'another organization\'s connection, PUT credential' => ['foreign connection', true, 'PUT'],
    'an id that never existed, GET' => ['unknown connection', false, 'GET'],
    'an id that never existed, PATCH' => ['unknown connection', false, 'PATCH'],
    'an id that never existed, DELETE' => ['unknown connection', false, 'DELETE'],
    'an id that never existed, PUT credential' => ['unknown connection', false, 'PUT'],
]);

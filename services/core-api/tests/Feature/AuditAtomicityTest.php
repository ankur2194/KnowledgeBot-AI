<?php

declare(strict_types=1);

use App\Enums\BotDomainStatus;
use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\BotStarterQuestion;
use App\Models\EmailVerificationToken;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Crypto\CredentialVault;
use App\Support\Kb\OpaqueToken;
use Database\Factories\ProviderConnectionFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Psr\Log\LoggerInterface;
use Tests\Support\AuditLogRepositorySpy;
use Tests\Support\Mailbox;
use Tests\Support\RecordingLogger;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Audit atomicity — the ABORT policy, proved by breaking the INSERT
|--------------------------------------------------------------------------
|
| AuditLogger::OPERATIONS gives every operation a write-failure policy: ON_FAILURE_ABORT re-throws, so
| the audited state change must roll back with it; ON_FAILURE_LOG swallows and the action stands.
|
| THE TECHNIQUE, AND WHY IT IS BETTER THAN A MOCK. `audit_logs` is RANGE-partitioned on `created_at`,
| so DROPPING the current month's partition makes every INSERT fail at the database with
| SQLSTATE 23514 — "no partition of relation audit_logs found for row". That is a real failure of the
| real write, inside the real transaction, on the real connection. A mocked repository that throws
| proves the PHP branch and nothing about whether the audit INSERT and the state change share a
| transaction at all — which is the entire property. It also cannot fail the way this can: if somebody
| moves the audit write outside the transaction, the mock version still passes.
|
| RefreshDatabase holds one transaction around each test and Laravel's DB::transaction() nests as a
| SAVEPOINT, so the rollback under test is a ROLLBACK TO SAVEPOINT and the outer transaction survives
| to be queried afterwards. That is what makes the assertions below readable at all.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN, so no rate-limiter bucket is shared with another test or
    // with the previous run of the suite. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());

    // Every case here provokes a logged error by construction; the `stack` channel writes JSON to
    // php://stdout and would interleave with Pest's own output.
    config(['logging.default' => 'null']);
});

/**
 * Make every audit INSERT fail, and prove that it does.
 *
 * @return list<string> the partitions that were dropped
 */
function auditWritesBroken(): array
{
    $partitions = app(EloquentAuditLogPartitionRepository::class);

    $names = $partitions->partitionNames();

    // POSITIVE CONTROL BEFORE THE BREAK: an audit row can be written right now. Without this, a
    // migration that stopped creating partitions would make every assertion below pass for the wrong
    // reason — "nothing committed" is satisfied by an application that does nothing.
    expect(app(AuditLogger::class)->record(AuditLogger::LOGOUT, null, null))->not->toBeNull(
        'the audit table could not be written even BEFORE the partitions were dropped, so this test '
        .'would prove nothing about the ABORT policy',
    );

    expect($names)->not->toBeEmpty('audit_logs has no partitions, so there is nothing to drop and the '
        .'INSERT would succeed');

    foreach ($names as $name) {
        // Dropping a partition detaches it implicitly. Transactional in PostgreSQL, so RefreshDatabase
        // puts it back at the end of the test.
        Schema::getConnection()->statement('DROP TABLE '.$name);
    }

    // AND THE BREAK ITSELF IS ASSERTED, so a future partitioning change that made this a no-op fails
    // here rather than turning six tests into tautologies.
    //
    // THE PROBE RUNS INSIDE ITS OWN SAVEPOINT, and that is not tidiness. AuditLogger::record() writes
    // WITHOUT opening a transaction of its own — the callers that need one open it — so an unwrapped
    // failing INSERT here would abort RefreshDatabase's ambient transaction and every later statement
    // in the test would die with 25P02 for a reason that has nothing to do with the property.
    expect(static fn () => Schema::getConnection()->transaction(
        static fn () => app(AuditLogger::class)->record(AuditLogger::PASSWORD_RESET_COMPLETED, null, null),
    ))->toThrow(\Illuminate\Database\QueryException::class);

    return $names;
}

/**
 * Break the audit write ABOVE the driver, and capture what gets logged.
 *
 * WHY THE ON_FAILURE_LOG CASES CANNOT USE auditWritesBroken(), AND IT IS A PROPERTY OF THE HARNESS
 * RATHER THAN OF THE APPLICATION. An ABORT operation's write lives inside a DB::transaction() the
 * caller opened, which under RefreshDatabase is a SAVEPOINT — so the failure rolls back to it and the
 * request can carry on to render a 500. A LOG operation's write is deliberately NOT in a transaction
 * (login, logout and the reset REQUEST are not transactional actions), so a failing INSERT aborts
 * RefreshDatabase's ambient transaction and every subsequent statement in the same request fails with
 * 25P02 — the login 500s, and the test would report that ON_FAILURE_LOG does not work.
 *
 * IN PRODUCTION THAT DOES NOT HAPPEN: there is no ambient transaction around an HTTP request, so the
 * failed INSERT aborts only its own implicit one and the action really does stand. Measured 2026-08-13.
 *
 * So these cases provoke the failure at the repository boundary instead. That is a double, and it is
 * the weaker instrument — it cannot notice a write that moved outside its transaction — which is
 * exactly why the six ABORT cases above do NOT use it.
 *
 * @return array{0: AuditLogRepositorySpy, 1: RecordingLogger}
 */
function auditWritesBrokenAboveTheDriver(): array
{
    $spy = new AuditLogRepositorySpy(new \RuntimeException('audit write refused by the fixture'));
    $recorder = new RecordingLogger;

    // #[Give(EloquentAuditLogRepository::class)] on AuditLogger's constructor pins the CONCRETE class,
    // so binding the interface would have no effect — the container has to be told about that class.
    app()->instance(EloquentAuditLogRepository::class, $spy);
    app()->instance(LoggerInterface::class, $recorder);

    return [$spy, $recorder];
}

/**
 * @return array{organization: Organization, owner: User, invitation: OrganizationInvitation, token: string}
 */
function auditAtomicityFixture(): array
{
    $organization = Organization::factory()->create(['name' => 'Atomicity Org ALPHA']);
    $owner = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('atomicity-owner')]);

    $token = OpaqueToken::mint();

    return [
        'organization' => $organization,
        'owner' => $owner,
        'token' => $token,
        'invitation' => OrganizationInvitation::factory()->recycle($organization)->invitedBy($owner)
            ->token($token)->role(OrgRole::Analyst)->create(['email' => 'atomicity-invitee@example.test']),
    ];
}

/**
 * The plaintext a provider-connection test submits.
 *
 * THREE LITERALS NOW EXIST FOR ONE CONCEPT AND THAT IS DELIBERATE: this one, the factory's
 * FIXTURE_CREDENTIAL (what a fixture row already holds), and ProviderConnectionResourceTest's
 * ROTATION_TEST_CREDENTIAL. Each is greppable and distinct, so a value found somewhere it should not
 * be names the file that put it there. It is NOT this file's job to reuse that file's constant —
 * a top-level `const` is process-global, and reaching for one declared in a sibling test file
 * couples two files that PHPUnit may load in either order or, running one file alone, not at all.
 *
 * Its last four are lower-case alphanumeric for the same reason ROTATION_TEST_CREDENTIAL's are: a
 * ULID is upper-case base32, so `v8r2` cannot appear inside a subject id or a request id by chance.
 */
const ATOMICITY_PROVIDER_CREDENTIAL = 'kb-atomicity-credential-DO-NOT-LOG-v8r2';

/**
 * TWO ORGANIZATIONS, each holding a connection to the SAME vendor carrying the SAME model id.
 *
 * A one-organization fixture would pass every assertion below against a repository whose
 * organization predicate had been deleted — including the rollback assertions, because "the row is
 * still there" is satisfied by a delete that ran against nobody. Org B's rows are the control: every
 * test here asserts they are still standing afterwards, and only the LABEL distinguishes them, which
 * is why both labels are written out rather than left to the factory's faker default.
 *
 * A HELPER OF THIS FILE'S OWN, not ProviderConnectionResourceTest's providerOrgPair(). Pest test
 * files declare their helpers at file scope, so that one exists only when that file has been loaded
 * — running `pest tests/Feature/AuditAtomicityTest.php` alone would fatal on an undefined function —
 * and declaring a second copy under the same name in this file would be a redeclaration fatal in a
 * full run. tests/Support/tenancy.php's tenantPair() is the intended eventual home for both and
 * is live for bots as of the bots-schema step; its KnowledgeSource half is still commented for Phase C, so a suite that needs INDEXED SOURCE content still builds its own fixture.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User,
 *     connectionA: ProviderConnection, connectionB: ProviderConnection,
 * }
 */
function providerAtomicityFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Atomicity Provider Org ALPHA']);
    $orgB = Organization::factory()->create(['name' => 'Atomicity Provider Org BRAVO']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('atomicity-provider-owner')]),

        // ->recycle($org) on every factory without exception: ProviderConnectionFactory refuses to
        // run without one, because a connection minted into a THIRD organization is what makes an
        // isolation assertion pass with the tenant filter deleted.
        'connectionA' => ProviderConnection::factory()->recycle($orgA)
            ->provider(Provider::Anthropic)
            ->withModel('claude-sonnet-5', ['text'])
            ->create(['label' => 'ALPHA atomicity chat']),
        'connectionB' => ProviderConnection::factory()->recycle($orgB)
            ->provider(Provider::Anthropic)
            ->withModel('claude-sonnet-5', ['text'])
            ->create(['label' => 'BRAVO atomicity chat']),
    ];
}

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: the state change rolls back with the audit row
// -------------------------------------------------------------------------------------------

it('creates no invitation when its audit row cannot be written', function (): void {
    $fixture = auditAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['organization']->id}/invitations",
        ['email' => 'unauditable@example.test', 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )->assertStatus(500)->assertJsonPath('error_class', 'internal_dependency');

    expect(
        OrganizationInvitation::query()->where('email', 'unauditable@example.test')->count()
    )->toBe(0, 'the invitation was created without an audit row, so the compliance record is '
        .'permanently incomplete for a live capability that was mailed to somebody');

    // AND NO MAIL WENT OUT. `deliver()` runs after the transaction, so a rollback that left the mail
    // sent would hand out a link to a row that does not exist.
    expect(Mailbox::count())->toBe(0);
});

it('does not revoke an invitation when its audit row cannot be written', function (): void {
    $fixture = auditAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['organization']->id}/invitations/{$fixture['invitation']->id}",
        [],
        spaHeaders(),
    )->assertStatus(500);

    expect($fixture['invitation']->fresh()?->revoked_at)->toBeNull(
        'the invitation was revoked with no audit row — the record of who withdrew somebody\'s access '
        .'is exactly what an audit trail is for',
    );

    // The link still works, which is the observable consequence of the rollback.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['token']], spaHeaders())
        ->assertOk();
});

it('does not rotate a resend token when its audit row cannot be written', function (): void {
    $fixture = auditAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['organization']->id}/invitations/{$fixture['invitation']->id}/resend",
        [],
        spaHeaders(),
    )->assertStatus(500);

    // THE ORIGINAL TOKEN STILL WORKS. A rotation that committed without its audit row would silently
    // kill a link the recipient already has, with nothing recording that it happened.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['token']], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Atomicity Org ALPHA');

    expect(Mailbox::count())->toBe(0);
});

it('creates no account when the acceptance audit row cannot be written', function (): void {
    $fixture = auditAtomicityFixture();

    auditWritesBroken();

    currentTest()->postJson('/api/v1/auth/register', [
        'token' => $fixture['token'],
        'name' => 'Unauditable Registrant',
        'password' => 'Atomicity-Secret-9',
        'password_confirmation' => 'Atomicity-Secret-9',
    ], spaHeaders())->assertStatus(500);

    // NOTHING: no user, no membership, and the invitation is still pending. All three are written in
    // one transaction with the audit row, and a partial commit here would be an account that exists
    // with no record of how it came to.
    expect(User::query()->where('email', 'atomicity-invitee@example.test')->exists())->toBeFalse();

    /** @var OrganizationInvitation $invitation */
    $invitation = $fixture['invitation']->fresh();

    expect($invitation->accepted_at)->toBeNull()
        ->and($invitation->accepted_by_id)->toBeNull()
        ->and($invitation->isPending())->toBeTrue();
});

it('does not verify an address when its audit row cannot be written', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->recycle($organization)->orgRole(OrgRole::Admin)
        ->create(['email' => SpaSession::uniqueEmail('atomicity-verify'), 'email_verified_at' => null]);

    SpaSession::establish(currentTest(), $user);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $token = Mailbox::latestToken();

    auditWritesBroken();

    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $token], spaHeaders())
        ->assertStatus(500);

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse(
        'the address was verified with no audit row',
    );

    // The token is still live, so the user can try again — a consumed-but-unaudited token would be a
    // dead link and a support ticket with no trail.
    /** @var EmailVerificationToken $row */
    $row = EmailVerificationToken::query()->firstOrFail();

    expect($row->consumed_at)->toBeNull();
});

it('does not change a password when the completion audit row cannot be written', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->recycle($organization)->orgRole(OrgRole::Admin)
        ->create(['email' => SpaSession::uniqueEmail('atomicity-reset')]);

    // PASSWORD_RESET_REQUESTED is ON_FAILURE_LOG, so the link is fetched BEFORE the break — otherwise
    // this test would be asserting the wrong operation's policy.
    currentTest()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], spaHeaders())
        ->assertOk();

    $token = Mailbox::latestToken();

    auditWritesBroken();

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Atomicity-Secret-9',
        'password_confirmation' => 'Atomicity-Secret-9',
    ], spaHeaders())->assertStatus(500);

    expect(Hash::check('Atomicity-Secret-9', (string) $user->fresh()?->getAuthPassword()))->toBeFalse(
        'the password changed with no audit row: a credential change is the single most important '
        .'thing in §18.11\'s list and it would be invisible',
    );

    // The old credential still works, which is the whole point of the rollback rather than a
    // half-applied reset the user cannot recover from.
    expect(Hash::check(UserFactory::PASSWORD, (string) $user->fresh()?->getAuthPassword()))->toBeTrue();
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: the four provider-connection operations
//
// ALL FOUR AUDIT WRITES ARE PASSED INTO THE REPOSITORY AS A CLOSURE and invoked inside its own
// DB::transaction() — App\Repositories\Eloquent\EloquentProviderConnectionRepository, one call site
// per method. That is why breaking the INSERT at the DATABASE is the right instrument here and a
// mocked repository is not: the property under test is that the audit write and the business write
// share a transaction, and a PHP-level double proves only that the ABORT branch rethrows.
//
// WHERE THE AUDIT CALL SITS RELATIVE TO THE STATE CHANGE IS NOT UNIFORM, and it decides how much
// each of these four tests can prove:
//
//   create / update / rotateCredential  — save() FIRST, audit SECOND. The row really is written and
//       really is rolled back, so these three fail if the audit write is moved out of the
//       transaction in either direction.
//   delete                              — audit FIRST, deletes SECOND, because a hard delete leaves
//       the audit row as the only surviving description of the connection. So the delete test proves
//       "nothing committed" and proves the write is not AFTER the commit — but it cannot, by
//       construction, distinguish an audit call inside the transaction from one just before it. That
//       limitation is written here rather than papered over; the other three carry that half.
// -------------------------------------------------------------------------------------------

it('stores no provider connection when its audit row cannot be written', function (): void {
    $fixture = providerAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL BEFORE THE BREAK: org A owns exactly one connection right now. Without it,
    // "org A still owns one connection" below is satisfied by a fixture that never created any and
    // by an endpoint that has been 500ing since long before the partitions were dropped.
    expect(ProviderConnection::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'the fixture did not create org A\'s connection, so nothing below is a rollback');

    // UNREACHABLE ON CORRECT CODE, and that is the point of stating it. `store` computes embedding
    // readiness AFTER the write, so the ABORT rethrow means this stub is never consulted. It is here
    // so that if the audit failure is ever swallowed, this test fails on its assertions rather than
    // on a socket the suite must never open.
    Http::fake([
        '*/internal/v1/embedding/readiness' => Http::response([
            'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => 'not yet',
        ], 200),
    ]);

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections",
        [
            'provider' => Provider::OpenAI->value,
            'label' => 'ALPHA unauditable connection',
            'credential' => ATOMICITY_PROVIDER_CREDENTIAL,
            // A MODEL ROW TOO, so the rollback assertion covers the child writes attachModel()
            // performs inside the same transaction and not just the parent INSERT.
            'models' => [[
                'model' => 'text-embedding-3-large',
                'display_name' => 'Unauditable embedding',
                'supported' => ['embedding'],
                'context_window' => 8192,
                'max_output_tokens' => 0,
            ]],
        ],
        spaHeaders(),
    )
        // ON_FAILURE_ABORT rethrows the QueryException UNWRAPPED, so the taxonomy classifies the
        // SQLSTATE rather than a service-layer wrapper.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // NOTHING COMMITTED. A credential stored with no record of who stored it is precisely the state
    // kb-security-baseline §18.11 exists to make unreachable — and it is invisible in every other
    // test, because the endpoint's own response would have been a 201.
    assertDatabaseMissing('provider_connections', ['label' => 'ALPHA unauditable connection']);
    assertDatabaseMissing('provider_models', ['display_name' => 'Unauditable embedding']);

    expect(ProviderConnection::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'a connection was created without an audit row');

    // AND ORG B SURVIVES. A rollback that reached beyond its own savepoint would take the other
    // tenant's rows with it, and no assertion on org A can see that.
    assertDatabaseHas('provider_connections', [
        'id' => $fixture['connectionB']->id,
        'organization_id' => $fixture['orgB']->id,
        'label' => 'BRAVO atomicity chat',
    ]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgB']->id,
        'provider_connection_id' => $fixture['connectionB']->id,
    ]);
});

it('does not relabel or restatus a provider connection when its audit row cannot be written', function (): void {
    $fixture = providerAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $connection = $fixture['connectionA'];

    // POSITIVE CONTROL FIRST: the values this test claims survive are the values the row actually
    // holds right now. Asserting only the "after" state would pass against a fixture whose label was
    // never what the assertion names.
    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    expect($before->label)->toBe('ALPHA atomicity chat')
        ->and($before->status)->toBe(ProviderConnectionStatus::Active);

    auditWritesBroken();

    // `update()` calls save() BEFORE the audit closure, so both columns really are written and both
    // really have to come back on the ROLLBACK TO SAVEPOINT. This is the shape that fails if the
    // audit write is moved outside the repository's transaction.
    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}",
        [
            'label' => 'ALPHA renamed by an unauditable edit',
            'status' => ProviderConnectionStatus::Revoked->value,
        ],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    expect($after->label)->toBe(
        'ALPHA atomicity chat',
        'the connection was relabelled with no audit row, so the trail cannot say which credential '
        .'the operator was describing when they later revoked it',
    );

    // THE STATUS HALF MATTERS MORE THAN THE LABEL. `revoked` takes the credential out of every
    // capability query, so a status change that committed without its audit row is an organization
    // whose ingestion silently stopped with nothing recording who stopped it.
    expect($after->status)->toBe(ProviderConnectionStatus::Active);

    assertDatabaseMissing('provider_connections', ['label' => 'ALPHA renamed by an unauditable edit']);

    // ORG B UNTOUCHED.
    assertDatabaseHas('provider_connections', [
        'id' => $fixture['connectionB']->id,
        'label' => 'BRAVO atomicity chat',
        'status' => ProviderConnectionStatus::Active->value,
    ]);
});

it('deletes no provider connection when its audit row cannot be written', function (): void {
    $fixture = providerAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $connection = $fixture['connectionA'];

    // POSITIVE CONTROL FIRST, BOTH HALVES: the connection and the `provider_models` row the delete
    // would have to take with it both exist. Without the child assertion, "the model rows are still
    // there" is satisfied by a fixture that never attached one.
    assertDatabaseHas('provider_connections', [
        'id' => $connection->id,
        'organization_id' => $fixture['orgA']->id,
    ]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $connection->id,
        'model' => 'claude-sonnet-5',
    ]);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE HARD DELETE DID NOT HAPPEN. This is the one operation whose audit row is the ONLY thing
    // that would have survived it, so a delete that committed without one erases the connection AND
    // the record that it ever existed — `subject_id` would point at a ULID no table resolves and no
    // row would carry the provider, label or status that made it readable.
    assertDatabaseHas('provider_connections', [
        'id' => $connection->id,
        'organization_id' => $fixture['orgA']->id,
        'label' => 'ALPHA atomicity chat',
    ]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $connection->id,
        'model' => 'claude-sonnet-5',
    ]);

    // ORG B SURVIVES — connection and child row alike. A delete whose predicate lost its
    // organization term is invisible to every assertion above.
    assertDatabaseHas('provider_connections', [
        'id' => $fixture['connectionB']->id,
        'organization_id' => $fixture['orgB']->id,
    ]);
    assertDatabaseHas('provider_models', [
        'organization_id' => $fixture['orgB']->id,
        'provider_connection_id' => $fixture['connectionB']->id,
    ]);
});

it('does not replace a stored credential when the rotation audit row cannot be written', function (): void {
    $fixture = providerAtomicityFixture();

    // A REVOKED CONNECTION ON PURPOSE. rotateCredential() writes four credential columns, bumps
    // `credential_version` AND returns the connection to `active`, all in one save() before the
    // audit closure runs — so a revoked fixture gives this test a fifth, independently observable
    // column that must also roll back. An active fixture would make the status assertion vacuous.
    $connection = ProviderConnection::factory()->recycle($fixture['orgA'])
        ->provider(Provider::OpenAI)
        ->revoked()
        ->create(['label' => 'ALPHA atomicity revoked']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $before = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // POSITIVE CONTROL FIRST, AND IT IS THE WHOLE TEST: the row really does hold a sealed credential
    // that round-trips through the vault. "The ciphertext did not change" is otherwise a statement
    // about an empty column, and it would go green against a factory that sealed nothing.
    expect(app(CredentialVault::class)->open(
        (string) $before->getAttribute('credential_ciphertext'),
        (string) $before->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    expect($before->credential_version)->toBe(1)
        ->and($before->last_four)->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4))
        ->and($before->status)->toBe(ProviderConnectionStatus::Revoked);

    auditWritesBroken();

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$connection->id}/credential",
        [
            // §18.3 re-authentication. It is a validation rule, so it runs before the row is read —
            // a 422 here would mean this test never reached the transaction it is about.
            'current_password' => UserFactory::PASSWORD,
            'credential' => ATOMICITY_PROVIDER_CREDENTIAL,
        ],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    $after = ProviderConnection::query()->withoutGlobalScopes()->findOrFail($connection->id);

    // THE OLD KEY STILL OPENS, which is the observable consequence of the rollback rather than a
    // restatement of it: a half-applied rotation would leave the organization authenticating to the
    // provider with a key nobody holds, and no audit row saying when that started.
    expect(app(CredentialVault::class)->open(
        (string) $after->getAttribute('credential_ciphertext'),
        (string) $after->getAttribute('data_key_ciphertext'),
    ))->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    expect($after->getAttribute('credential_ciphertext'))
        ->toBe($before->getAttribute('credential_ciphertext'))
        ->and($after->getAttribute('data_key_ciphertext'))
        ->toBe($before->getAttribute('data_key_ciphertext'))
        ->and($after->last_four)->toBe($before->last_four)
        // THE VERSION COUNTER IS THE TELL. It is the one column an investigation reads to answer
        // "which generation of this credential was live on that date", and a rotation that committed
        // without its audit row moves it to 2 with nothing to explain the gap.
        ->and($after->credential_version)->toBe(1)
        ->and($after->status)->toBe(ProviderConnectionStatus::Revoked);

    // AND THE SUBMITTED PLAINTEXT IS NOWHERE IN THE ROW. `credential_ciphertext` is bytea, so read
    // the whole row back AS TEXT: a plaintext that landed in a column this test does not know about
    // still trips it.
    //
    // str_contains(...)->toBeFalse(), never ->not->toContain(...): toContain() takes only needles, so
    // a message argument becomes a second needle and `not` treats any failure as success. That exact
    // shape has already hidden a real tenant-id leak in this repo.
    $raw = (string) json_encode(
        Schema::getConnection()->select(
            'SELECT provider_connections::text AS value FROM provider_connections WHERE id = ?',
            [$connection->id],
        ),
        JSON_THROW_ON_ERROR,
    );

    expect($raw)->not->toBe('[]');
    expect(str_contains($raw, ATOMICITY_PROVIDER_CREDENTIAL))
        ->toBeFalse('the rotation plaintext reached a column despite the rollback');

    // ORG B'S CREDENTIAL IS UNTOUCHED — same vendor, same fixture plaintext, so only the id and the
    // version counter distinguish it from org A's.
    $survivor = ProviderConnection::query()->withoutGlobalScopes()
        ->findOrFail($fixture['connectionB']->id);

    expect($survivor->credential_version)->toBe(1, 'rotated org B\'s credential anyway')
        ->and($survivor->label)->toBe('BRAVO atomicity chat');
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: the PROVIDER MODEL CATALOG
// -------------------------------------------------------------------------------------------
//
// THE SAME TECHNIQUE AND A DIFFERENT SUBJECT. `provider.model.created`, `.updated` and `.deleted`
// are all ABORT and all written inside EloquentProviderModelRepository's transaction, so each
// change must roll back with its row.
//
// WHAT EACH OF THE THREE CAN PROVE, which is not the same for all three:
//   create   — the row does not exist afterwards. It cannot distinguish "the audit write was
//       inside the transaction" from "it happened before the INSERT", because there is nothing to
//       observe before the INSERT.
//   update   — the strongest of the three. `update()` calls save() BEFORE the audit closure, so
//       five columns really are written and really have to come back on the ROLLBACK TO SAVEPOINT.
//       This is the shape that fails if the audit write is moved outside the transaction.
//   delete   — audit FIRST, delete SECOND, because a hard delete leaves the audit row as the only
//       surviving description. So it proves "nothing committed" and proves the write is not AFTER
//       the commit, but cannot distinguish inside-the-transaction from just-before-it. The update
//       test carries that half.

/**
 * TWO ORGANIZATIONS, each holding a connection whose catalog carries THE SAME MODEL ID.
 *
 * Only the DISPLAY NAME distinguishes them, which is the point: a repository whose organization
 * predicate had been deleted would still return, edit or delete a plausible-looking row, and every
 * assertion keyed on the model identifier would pass. Org B's rows are the control, and every test
 * below asserts they are still standing afterwards.
 *
 * A HELPER OF THIS FILE'S OWN, and a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so `providerOrgPair()` from ProviderConnectionResourceTest.php exists only when that file
 * has been loaded — running this file alone would fatal on an undefined function — and declaring a
 * second copy under the same name here would be a redeclaration fatal in a full run.
 * tests/Support/tenancy.php's tenantPair() is the intended eventual home for all of them and
 * is live for bots as of the bots-schema step; its KnowledgeSource half is still commented for Phase C, so a suite that needs INDEXED SOURCE content still builds its own fixture.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User,
 *     connectionA: ProviderConnection, connectionB: ProviderConnection,
 *     modelA: ProviderModelEntry, modelB: ProviderModelEntry,
 * }
 */
function providerModelAtomicityFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Model Atomicity Org ALPHA']);
    $orgB = Organization::factory()->create(['name' => 'Model Atomicity Org BRAVO']);

    $connectionA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::OpenAI)->create(['label' => 'ALPHA model atomicity']);
    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->provider(Provider::OpenAI)->create(['label' => 'BRAVO model atomicity']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('model-atomicity-owner')]),
        'connectionA' => $connectionA,
        'connectionB' => $connectionB,

        // ->recycle() of BOTH parents on every factory call: ProviderModelEntryFactory refuses to
        // run without them, and refuses a pair that disagrees, because a row minted into a THIRD
        // organization is what makes an isolation test pass with the tenant filter deleted.
        'modelA' => ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
            ->supporting(['embedding'])
            ->create(['model' => 'text-embedding-3-large', 'display_name' => 'ALPHA embedding row']),
        'modelB' => ProviderModelEntry::factory()->recycle($orgB)->recycle($connectionB)
            ->supporting(['embedding'])
            ->create(['model' => 'text-embedding-3-large', 'display_name' => 'BRAVO embedding row']),
    ];
}

it('registers no provider model when its audit row cannot be written', function (): void {
    $fixture = providerModelAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL BEFORE THE BREAK: org A's connection carries exactly one catalog row right
    // now. Without it, "still one row" below is satisfied by a fixture that created none and by an
    // endpoint that has been 500ing since long before the partitions were dropped.
    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'the fixture did not create org A\'s catalog row, so nothing below is a rollback');

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models",
        [
            'model' => 'gpt-5-unauditable',
            'display_name' => 'ALPHA unauditable model',
            'supported' => ['text'],
            'context_window' => 200000,
            'max_output_tokens' => 32000,
        ],
        spaHeaders(),
    )
        // ON_FAILURE_ABORT rethrows the QueryException UNWRAPPED, so the taxonomy classifies the
        // SQLSTATE rather than a service-layer wrapper.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // NOTHING COMMITTED. A capability flag that reached the table with no record of who declared
    // it is exactly the state §18.11 exists to make unreachable — and it is invisible in every
    // other test, because the endpoint's own response would have been a 201.
    assertDatabaseMissing('provider_models', ['model' => 'gpt-5-unauditable']);
    assertDatabaseMissing('provider_models', ['display_name' => 'ALPHA unauditable model']);

    expect(ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'a catalog row was created without an audit row');

    // AND ORG B SURVIVES. A rollback that reached beyond its own savepoint would take the other
    // tenant's rows with it, and no assertion on org A can see that.
    assertDatabaseHas('provider_models', [
        'id' => $fixture['modelB']->id,
        'organization_id' => $fixture['orgB']->id,
        'display_name' => 'BRAVO embedding row',
    ]);
});

it('does not replace a provider model when its audit row cannot be written', function (): void {
    $fixture = providerModelAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $model = $fixture['modelA'];

    // POSITIVE CONTROL FIRST: the values this test claims survive are the values the row actually
    // holds right now. Asserting only the "after" state would pass against a fixture whose display
    // name was never what the assertion names.
    $before = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($model->id);

    expect($before->display_name)->toBe('ALPHA embedding row')
        ->and($before->supportedCapabilities())->toBe(['embedding'])
        ->and($before->enabled)->toBeTrue()
        ->and($before->price_currency)->toBeNull();

    auditWritesBroken();

    // `update()` calls save() BEFORE the audit closure, so all five of these really are written and
    // all five really have to come back on the ROLLBACK TO SAVEPOINT. This is the shape that fails
    // if the audit write is moved outside the repository's transaction.
    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$model->id}",
        [
            'display_name' => 'ALPHA renamed by an unauditable edit',
            'supported' => [],
            'context_window' => 1,
            'max_output_tokens' => 1,
            'enabled' => false,
            'input_price_per_million' => '9.500000',
            'output_price_per_million' => '19.500000',
            'price_currency' => 'EUR',
        ],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    $after = ProviderModelEntry::query()->withoutGlobalScopes()->findOrFail($model->id);

    expect($after->display_name)->toBe(
        'ALPHA embedding row',
        'the row was renamed with no audit row, so the trail cannot say what the operator was '
        .'describing when they later changed its capabilities',
    );

    // THE CAPABILITY AND ENABLED HALVES MATTER MORE THAN THE LABEL. Dropping `embedding` or
    // disabling the row takes this organization's only embedder out of the candidate set, so a
    // change that committed without its audit row is an organization whose ingestion silently
    // stopped with nothing recording who stopped it.
    expect($after->supportedCapabilities())->toBe(['embedding'])
        ->and($after->enabled)->toBeTrue()
        ->and($after->context_window)->toBe($before->context_window)
        ->and($after->price_currency)->toBeNull()
        ->and($after->input_price_per_million)->toBeNull();

    assertDatabaseMissing('provider_models', ['display_name' => 'ALPHA renamed by an unauditable edit']);

    // ORG B UNTOUCHED — same model identifier, so only the display name distinguishes it.
    assertDatabaseHas('provider_models', [
        'id' => $fixture['modelB']->id,
        'organization_id' => $fixture['orgB']->id,
        'display_name' => 'BRAVO embedding row',
    ]);
});

it('deletes no provider model when its audit row cannot be written', function (): void {
    $fixture = providerModelAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $model = $fixture['modelA'];

    // POSITIVE CONTROL FIRST: the row this test claims survives is really there, under the
    // organization and the connection the assertion names.
    assertDatabaseHas('provider_models', [
        'id' => $model->id,
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $fixture['connectionA']->id,
        'model' => 'text-embedding-3-large',
    ]);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/provider-connections/{$fixture['connectionA']->id}/models/{$model->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE HARD DELETE DID NOT HAPPEN. This is the one operation whose audit row is the ONLY thing
    // that would have survived it, so a delete that committed without one erases the catalog entry
    // AND the record that it ever existed — `subject_id` would point at a ULID no table resolves
    // and no row would carry the connection, model id or capabilities that made it readable.
    assertDatabaseHas('provider_models', [
        'id' => $model->id,
        'organization_id' => $fixture['orgA']->id,
        'provider_connection_id' => $fixture['connectionA']->id,
        'display_name' => 'ALPHA embedding row',
    ]);

    // ORG B SURVIVES. A delete whose predicate lost its organization term is invisible to every
    // assertion above — and org B's row carries the SAME model identifier, so a delete keyed on
    // `model` alone would have taken it.
    assertDatabaseHas('provider_models', [
        'id' => $fixture['modelB']->id,
        'organization_id' => $fixture['orgB']->id,
    ]);
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: BOTS
// -------------------------------------------------------------------------------------------
//
// THE SAME TECHNIQUE AND A DIFFERENT SUBJECT AGAIN. `bot.created`, `.updated` and `.deleted` are
// all ABORT and all written inside EloquentBotRepository's transaction, so each change must roll
// back with its row.
//
// WHAT EACH OF THE THREE CAN PROVE, which is not the same for all three:
//   create   — the row does not exist afterwards. It cannot distinguish "the audit write was inside
//       the transaction" from "it happened before the INSERT", because there is nothing to observe
//       before the INSERT.
//   update   — the strongest of the three, and stronger here than on the catalog. `update()` calls
//       save() BEFORE the audit closure AND may have bumped `retrieval_configuration_version`, so a
//       rollback has to take both the column values and the version increment back. A version that
//       moved without an audit row is the specific defect: the §21.5 regression gate would then
//       replay a trace against a configuration identity no audit row explains.
//   delete   — audit FIRST, children SECOND, bot THIRD, because a hard delete leaves the audit row
//       as the only surviving description. So it proves "nothing committed" — including the CHILD
//       rows, which no other test in this file has an equivalent of — and proves the write is not
//       AFTER the commit, but cannot distinguish inside-the-transaction from just-before-it. The
//       update test carries that half.

/**
 * TWO ORGANIZATIONS, one bot each, and the two bots share a SLUG.
 *
 * A bot slug is unique PER ORGANIZATION, so the pair is legal — and it is exactly the fixture that
 * makes a missing organization predicate visible: a repository whose tenant term had been deleted
 * would edit or delete a plausible-looking row and every assertion keyed on the slug would pass.
 * Org B's bot is the control and every test below asserts it is still standing afterwards.
 *
 * ORG A'S BOT CARRIES ONE ROW IN EACH CHILD TABLE, which no other fixture in this file needs: the
 * delete path removes three child collections inside the same transaction, and a rollback that took
 * the bot back while leaving an orphaned domain would be invisible without them.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own, for the reason
 * providerModelAtomicityFixture() states: Pest declares test-file helpers at FILE SCOPE, so a
 * second declaration of a name another test file already uses is a redeclaration fatal in a full
 * run and only in a full run.
 *
 * @return array{orgA: Organization, orgB: Organization, ownerA: User, botA: Bot, botB: Bot}
 */
function botAtomicityFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Bot Atomicity Org ALPHA']);
    $orgB = Organization::factory()->create(['name' => 'Bot Atomicity Org BRAVO']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('bot-atomicity-owner')]),

        // ->recycle() on both, without exception: BotFactory REFUSES to run without a recycled
        // organization, because a bot minted into a THIRD organization is what makes an isolation
        // assertion pass with the tenant filter deleted.
        'botA' => Bot::factory()->recycle($orgA)
            ->withOrigins(['https://alpha.example.com'])
            ->create(['name' => 'ALPHA atomicity bot', 'slug' => 'shared-handle']),
        'botB' => Bot::factory()->recycle($orgB)
            ->create(['name' => 'BRAVO atomicity bot', 'slug' => 'shared-handle']),
    ];
}

it('creates no bot when its audit row cannot be written', function (): void {
    $fixture = botAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL BEFORE THE BREAK: org A holds exactly one bot right now. Without it, "still
    // one bot" below is satisfied by a fixture that created none and by an endpoint that has been
    // 500ing since long before the partitions were dropped.
    expect(Bot::query()->withoutGlobalScopes()->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'the fixture did not create org A\'s bot, so nothing below is a rollback');

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        ['name' => 'ALPHA unauditable bot', 'slug' => 'unauditable-bot'],
        spaHeaders(),
    )
        // ON_FAILURE_ABORT rethrows the QueryException UNWRAPPED, so the taxonomy classifies the
        // SQLSTATE rather than a service-layer wrapper.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // NOTHING COMMITTED. A bot that reached the table with no record of who created it is a
    // retrieval scope nobody can attribute — and it is invisible in every other test, because the
    // endpoint's own response would have been a 201.
    assertDatabaseMissing('bots', ['slug' => 'unauditable-bot']);
    assertDatabaseMissing('bots', ['name' => 'ALPHA unauditable bot']);

    expect(Bot::query()->withoutGlobalScopes()->where('organization_id', '=', $fixture['orgA']->id)->count())
        ->toBe(1, 'a bot was created without an audit row');

    // AND ORG B SURVIVES. A rollback that reached beyond its own savepoint would take the other
    // tenant's rows with it, and no assertion on org A can see that.
    assertDatabaseHas('bots', [
        'id' => $fixture['botB']->id,
        'organization_id' => $fixture['orgB']->id,
        'name' => 'BRAVO atomicity bot',
    ]);
});

it('does not edit a bot, or move its configuration version, when the audit row cannot be written', function (): void {
    $fixture = botAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $bot = $fixture['botA'];

    // POSITIVE CONTROL FIRST: the values this test claims survive are the values the row actually
    // holds. Without it a rollback assertion passes against a fixture that never had them.
    assertDatabaseHas('bots', [
        'id' => $bot->id,
        'organization_id' => $fixture['orgA']->id,
        'name' => 'ALPHA atomicity bot',
        'dense_top_k' => 20,
        'retrieval_configuration_version' => 1,
    ]);

    auditWritesBroken();

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        // A NAME CHANGE **AND** A RETRIEVAL KNOB, deliberately. The knob is what makes this the
        // strongest of the three: `update()` bumps `retrieval_configuration_version` inside the same
        // transaction, so a rollback has to take the increment back as well as the columns. A
        // version that moved with no audit row explaining it is a configuration identity the §21.5
        // regression gate would replay against and nobody could account for.
        ['name' => 'ALPHA unauditable rename', 'dense_top_k' => 42],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    assertDatabaseHas('bots', [
        'id' => $bot->id,
        'organization_id' => $fixture['orgA']->id,
        'name' => 'ALPHA atomicity bot',
        'dense_top_k' => 20,
        // THE ASSERTION THIS TEST EXISTS FOR. Everything above would also pass if the audit write
        // had happened before the UPDATE; only a version that came back proves the write is inside
        // the transaction that performed it.
        'retrieval_configuration_version' => 1,
    ]);

    assertDatabaseMissing('bots', ['name' => 'ALPHA unauditable rename']);

    assertDatabaseHas('bots', [
        'id' => $fixture['botB']->id,
        'organization_id' => $fixture['orgB']->id,
        'name' => 'BRAVO atomicity bot',
    ]);
});

it('deletes no bot, and no child of one, when its audit row cannot be written', function (): void {
    $fixture = botAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $bot = $fixture['botA'];

    // POSITIVE CONTROL FIRST, ON BOTH THE BOT AND ITS CHILD. The child is the half no other test in
    // this file has: the delete path removes three child collections inside the same transaction,
    // and an orphaned allow-list entry left behind by a partial rollback is invisible from `bots`.
    assertDatabaseHas('bots', [
        'id' => $bot->id,
        'organization_id' => $fixture['orgA']->id,
        'slug' => 'shared-handle',
    ]);
    assertDatabaseHas('bot_domains', [
        'bot_id' => $bot->id,
        'organization_id' => $fixture['orgA']->id,
        'origin' => 'https://alpha.example.com',
    ]);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE HARD DELETE DID NOT HAPPEN, AND NEITHER DID THE CASCADE. This is the one operation whose
    // audit row is the ONLY thing that would have survived it, so a delete that committed without
    // one erases the bot, its origin allow-list, its starter questions and its fallback chain AND
    // the record that any of them existed — `subject_id` would point at a ULID no table resolves.
    assertDatabaseHas('bots', [
        'id' => $bot->id,
        'organization_id' => $fixture['orgA']->id,
        'name' => 'ALPHA atomicity bot',
    ]);
    assertDatabaseHas('bot_domains', [
        'bot_id' => $bot->id,
        'origin' => 'https://alpha.example.com',
    ]);

    // ORG B SURVIVES. A delete whose predicate lost its organization term is invisible to every
    // assertion above — and org B's bot carries the SAME slug, so a delete keyed on the handle
    // alone would have taken it.
    assertDatabaseHas('bots', [
        'id' => $fixture['botB']->id,
        'organization_id' => $fixture['orgB']->id,
    ]);
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: THE BOT CHILD COLLECTIONS
// -------------------------------------------------------------------------------------------
//
// THE SAME TECHNIQUE ONE LEVEL DOWN, AND FOR THE ORIGIN ALLOW-LIST IT IS THE POINT OF THE WHOLE
// FEATURE. Finding L2 is that a bot delete destroyed its allow-list with no record of what it
// permitted. The fix is that every origin ever granted has its own append-only row — so a grant
// that COMMITS WITHOUT ITS ROW is the finding re-opened in the one shape nobody would notice: the
// widget works, the console shows the origin, and the trail says it was never granted.
//
// WHAT EACH CASE CAN PROVE, and it is not the same for all six:
//   domain create    the row does not exist afterwards. It cannot distinguish "inside the
//                    transaction" from "before the INSERT" — there is nothing observable before it.
//   domain status    the STRONGEST of the six: `changeStatus()` calls save() BEFORE the audit
//                    closure, so the rollback has to take a column value back rather than merely
//                    not insert a row. A promotion that stuck without its audit row is an origin
//                    that started granting embeds with nothing recording who turned it on.
//   domain delete    audit FIRST, row SECOND, so it proves "nothing committed" and proves the write
//                    is not AFTER the commit.
//   question create  as above; the list length is the observable.
//   question update  the same strength as the domain status case, through the re-sequence.
//   question delete  the same shape as the domain delete, plus the compaction that would otherwise
//                    have rewritten the surviving rows' positions.

/**
 * One organization, one owner, one bot carrying one origin and two starter questions.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own, for the reason botAtomicityFixture() states:
 * Pest declares test-file helpers at FILE SCOPE, so a second declaration of a name another test
 * file already uses is a redeclaration fatal in a full run and only in a full run.
 *
 * TWO QUESTIONS RATHER THAN ONE, because the delete path COMPACTS the survivors — with a single
 * row there is no re-sequence to roll back and the delete case would prove strictly less.
 *
 * @return array{org: Organization, owner: User, bot: Bot, domain: BotDomain, first: BotStarterQuestion, second: BotStarterQuestion}
 */
function botChildAtomicityFixture(): array
{
    $org = Organization::factory()->create(['name' => 'Bot Child Atomicity Org']);

    // ->recycle() on every child, without exception: BotFactory and both child factories REFUSE to
    // run without a recycled organization, because a row minted into a THIRD organization is what
    // makes an isolation assertion pass with the tenant filter deleted.
    $bot = Bot::factory()->recycle($org)->create([
        'name' => 'Child atomicity bot',
        'slug' => 'child-atomicity-bot',
    ]);

    return [
        'org' => $org,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('bot-child-atomicity-owner')]),
        'bot' => $bot,
        'domain' => BotDomain::factory()->recycle($org)->recycle($bot)
            ->origin('https://fixture-child.example')->create(),
        'first' => BotStarterQuestion::factory()->recycle($org)->recycle($bot)
            ->at(0)->asking('First fixture question?')->create(),
        'second' => BotStarterQuestion::factory()->recycle($org)->recycle($bot)
            ->at(1)->asking('Second fixture question?')->create(),
    ];
}

it('grants no origin when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    // POSITIVE CONTROL BEFORE THE BREAK: the bot holds exactly one origin right now, so "still one"
    // below is a rollback rather than a fixture that created none.
    expect(BotDomain::query()->withoutGlobalScopes()->where('bot_id', '=', $fixture['bot']->id)->count())
        ->toBe(1, 'the fixture did not create an origin, so nothing below is a rollback');

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/domains",
        ['origin' => 'https://unauditable.example'],
        spaHeaders(),
    )
        // ON_FAILURE_ABORT rethrows the QueryException UNWRAPPED, so the taxonomy classifies the
        // SQLSTATE rather than a service-layer wrapper.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // NOTHING COMMITTED. A grant to a page on the public internet with no record of who made it is
    // finding L2 re-opened, and it is invisible in every other test because the endpoint's own
    // response would have been a 201.
    assertDatabaseMissing('bot_domains', ['origin' => 'https://unauditable.example']);

    expect(BotDomain::query()->withoutGlobalScopes()->where('bot_id', '=', $fixture['bot']->id)->count())
        ->toBe(1, 'an origin was granted without an audit row');
});

it('does not promote an origin when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    // POSITIVE CONTROL: the row starts `pending`, which grants nothing. Without this the assertion
    // below is satisfied by a fixture that was already pending for some other reason.
    assertDatabaseHas('bot_domains', [
        'id' => $fixture['domain']->id,
        'status' => BotDomainStatus::Pending->value,
    ]);

    auditWritesBroken();

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}"
            ."/domains/{$fixture['domain']->id}",
        ['status' => BotDomainStatus::Active->value],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE COLUMN CAME BACK. `changeStatus()` calls save() BEFORE the audit closure, so this is a
    // real rollback of a written value rather than an INSERT that never happened — and the value in
    // question is the one that decides whether this origin may boot a widget at all.
    assertDatabaseHas('bot_domains', [
        'id' => $fixture['domain']->id,
        'status' => BotDomainStatus::Pending->value,
    ]);
});

it('does not remove an origin when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}"
            ."/domains/{$fixture['domain']->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE HARD DELETE DID NOT HAPPEN. This row's audit entry is the only thing that would have
    // survived it — `subject_id` points at a ULID no table resolves afterwards — so a delete that
    // committed without one erases both the grant and the record that it ever existed.
    assertDatabaseHas('bot_domains', [
        'id' => $fixture['domain']->id,
        'origin' => 'https://fixture-child.example',
    ]);
});

it('adds no starter question when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    expect(BotStarterQuestion::query()->withoutGlobalScopes()
        ->where('bot_id', '=', $fixture['bot']->id)->count())
        ->toBe(2, 'the fixture did not create the questions, so nothing below is a rollback');

    auditWritesBroken();

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}/starter-questions",
        ['question' => 'Unauditable question?'],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    assertDatabaseMissing('bot_starter_questions', ['question' => 'Unauditable question?']);

    expect(BotStarterQuestion::query()->withoutGlobalScopes()
        ->where('bot_id', '=', $fixture['bot']->id)->count())
        ->toBe(2, 'a starter question was added without an audit row');
});

it('does not move a starter question when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}"
            ."/starter-questions/{$fixture['second']->id}",
        ['question' => 'Renamed question?', 'sort_order' => 0],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // BOTH HALVES CAME BACK, and the second is what makes this the strong case: the re-sequence
    // rewrites EVERY row in the list, so a partial rollback would leave the two questions in an
    // order nobody asked for with no audit row to explain it.
    assertDatabaseHas('bot_starter_questions', [
        'id' => $fixture['first']->id,
        'question' => 'First fixture question?',
        'sort_order' => 0,
    ]);
    assertDatabaseHas('bot_starter_questions', [
        'id' => $fixture['second']->id,
        'question' => 'Second fixture question?',
        'sort_order' => 1,
    ]);
});

it('does not remove a starter question when the audit row cannot be written', function (): void {
    $fixture = botChildAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['owner']);

    auditWritesBroken();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['org']->id}/bots/{$fixture['bot']->id}"
            ."/starter-questions/{$fixture['first']->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE ROW SURVIVES AND SO DOES THE ORDER. The delete compacts the survivors in the same
    // transaction, so a rollback that took the DELETE back but left the re-sequence would leave the
    // second question at position 0 beside a first question that is also at 0 — which the unique
    // index would have refused, meaning the observable failure would be a later 23505 from an
    // unrelated request.
    assertDatabaseHas('bot_starter_questions', [
        'id' => $fixture['first']->id,
        'sort_order' => 0,
    ]);
    assertDatabaseHas('bot_starter_questions', [
        'id' => $fixture['second']->id,
        'sort_order' => 1,
    ]);
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_ABORT: THE THREE ORGANIZATION-LEVEL SETTINGS
// -------------------------------------------------------------------------------------------
//
// THE SAME TECHNIQUE ON A SUBJECT THAT IS NOT A ROW OF ITS OWN. All five operations below write
// COLUMNS ON `organizations` — the embedding designation, the rerank designation, and the four
// quota ceilings — and that changes what "nothing committed" can be observed AS: there is no row
// to be absent afterwards, only a value that has to come back. So every case here is arranged as a
// REPLACEMENT of a value that is already there rather than as a first write, which makes all five
// the strong shape the create-flavoured cases above cannot reach: `save()` runs BEFORE the audit
// closure in each of the three repository methods, so the columns really are written and really
// have to come back on the ROLLBACK TO SAVEPOINT, to a specific previous value rather than to null.
//
//   embedding set      THE MOST CONSEQUENTIAL OPERATION IN THIS FILE. `(provider, model)` IS the
//                      vector space (ADR-031) and it is part of the Qdrant collection name, so a
//                      re-designation that committed without its audit row strands every chunk
//                      already indexed — undoing it costs a full re-embed at a provider's
//                      per-token price — and `organizations` has no history table, `updated_at`
//                      moves for a rename too, and the pair is on no resource a reader can diff.
//                      "Who moved the vector space, and from what" would have no answer anywhere.
//   embedding cleared  the same write with both columns going to null, which does NOT mean "stop
//                      embedding": it hands the choice back to the resolution rule in
//                      services/ai-service/app/providers/embedding_selection.py, which may resolve
//                      to a different connection or REFUSE outright and block ingestion for the
//                      whole organization. "Did anybody clear it, and when" is therefore the first
//                      question asked when uploads start failing, and it is a separate operation
//                      precisely so it can be a query rather than a scan of every row's details.
//   rerank set         the same shape one surface over, and QUIETER when it goes wrong: a lost
//                      rerank designation raises nothing, changes no status and moves no metric on
//                      any single request. The only symptom is answers getting worse.
//   rerank cleared     as above; `rerank_gate` returns MODEL_NOT_CONFIGURED and fused order is
//                      served, which is a supported mode — so nothing downstream ever objects.
//   quota updated      FOUR columns in ONE save(), all four written before the audit closure, so
//                      the rollback has to bring back four independently observable numbers. A
//                      ceiling that moved with nothing recording who moved it is the one state
//                      this table exists to make impossible, and `raised` is derived INSIDE the
//                      transaction — the audit row is the authority over the service's pre-check,
//                      which reads the bound row outside the lock and can lose a race.
//
// EVERY CASE ESTABLISHES ITS STARTING STATE THROUGH THE REAL ENDPOINT, BEFORE THE BREAK, and that
// is the positive control. It is stronger than a fixture write, because it shows this exact
// request shape returning 200 and persisting moments before the identical shape has to 500 and
// persist nothing. Without it every rollback assertion below is also satisfied by an endpoint that
// has been failing for an unrelated reason since long before the partitions were dropped.

/**
 * The four model ids these cases designate between. All four are real NVIDIA NIM catalogue ids, so
 * the fixture reads like a configuration somebody would make — and NOTHING IN LARAVEL CHECKS THAT,
 * which RerankConfigurationTest asserts explicitly. They are real only for readability.
 *
 * FOUR CONSTANTS OF THIS FILE'S OWN, WITH NAMES OF THIS FILE'S OWN, for the reason
 * ATOMICITY_PROVIDER_CREDENTIAL states at length: a top-level `const` is process-global once its
 * file is loaded, so reusing RerankConfigurationTest.php's `RERANK_MODEL` would couple two files
 * that PHPUnit may load in either order or — running this file alone — not at all, and declaring a
 * second `RERANK_MODEL` here would be a redeclaration fatal in a full run and only in a full run.
 *
 * THE REPLACEMENT PAIR DIFFERS FROM THE ORIGINAL IN BOTH HALVES on purpose. A re-designation that
 * moved only the model would leave `*_connection_id` identical before and after, and half of each
 * rollback assertion below would then be true whatever the transaction did.
 */
const ATOMICITY_EMBEDDING_MODEL = 'nvidia/nv-embedqa-e5-v5';

const ATOMICITY_EMBEDDING_REPLACEMENT = 'nvidia/llama-3.2-nv-embedqa-1b-v2';

const ATOMICITY_RERANK_MODEL = 'nvidia/llama-3.2-nv-rerankqa-1b-v2';

const ATOMICITY_RERANK_REPLACEMENT = 'nvidia/nv-rerankqa-mistral-4b-v3';

/**
 * TWO ORGANIZATIONS. Org A holds TWO connections — the one it is designated to and the one each
 * case tries to move to — and org B holds one, carrying THE SAME TWO MODEL IDS as org A's first.
 *
 * ORG B IS FULLY CONFIGURED AND IS ONLY EVER A CONTROL: both designations set, all four ceilings
 * set, and every test below asserts it is still standing afterwards. Only the connection ULID and
 * the four numbers distinguish it from org A, which is the point — a write whose organization
 * predicate had been deleted would overwrite a plausible-looking row, and no assertion keyed on
 * org A's id can see that. A one-organization fixture would pass every case here with the tenant
 * term removed.
 *
 * ORG B'S QUOTAS ARE SET DIRECTLY ON THE ROW rather than through the endpoint, because it has no
 * member to act as: it exists to be left alone. Org A's starting state, by contrast, is always
 * established through the real endpoint — see the section header for why that is the positive
 * control rather than a convenience.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own, for the reason botAtomicityFixture() states:
 * Pest declares test-file helpers at FILE SCOPE, so a second declaration of a name another test
 * file already uses is a redeclaration fatal in a full run and only in a full run. That is also why
 * the three URL helpers below are not `rerankUrl()` and `quotaUrl()`, which
 * RerankConfigurationTest.php and QuotaEndpointTest.php already declare.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User,
 *     primaryA: ProviderConnection, standbyA: ProviderConnection,
 *     connectionB: ProviderConnection,
 * }
 */
function organizationSettingsAtomicityFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Settings Atomicity Org ALPHA']);
    $orgB = Organization::factory()->create(['name' => 'Settings Atomicity Org BRAVO']);

    // ->recycle($org) on every factory without exception: ProviderConnectionFactory refuses to run
    // without one, because a connection minted into a THIRD organization is what makes a tenancy
    // assertion pass with the filter deleted.
    //
    // ONE CONNECTION CARRYING BOTH AN EMBEDDING ROW AND A RERANK ROW, which is the ordinary shape:
    // one credential, one vendor, several catalogue entries. It is also what lets the embedding and
    // rerank cases share a fixture without either one's designation being able to satisfy the
    // other's assertion — the two model ids are different strings.
    $primaryA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::NvidiaNim)
        ->withModel(ATOMICITY_EMBEDDING_MODEL, ['embedding'])
        ->withModel(ATOMICITY_RERANK_MODEL, ['rerank'])
        ->create(['label' => 'ALPHA atomicity primary']);

    // A SECOND CREDENTIAL WITH THE SAME VENDOR, which is a real configuration (two billing
    // accounts) and keeps every id in this fixture a ULID rather than letting the provider column
    // become the thing that distinguishes them.
    $standbyA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::NvidiaNim)
        ->withModel(ATOMICITY_EMBEDDING_REPLACEMENT, ['embedding'])
        ->withModel(ATOMICITY_RERANK_REPLACEMENT, ['rerank'])
        ->create(['label' => 'ALPHA atomicity standby']);

    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->provider(Provider::NvidiaNim)
        ->withModel(ATOMICITY_EMBEDDING_MODEL, ['embedding'])
        ->withModel(ATOMICITY_RERANK_MODEL, ['rerank'])
        ->create(['label' => 'BRAVO atomicity primary']);

    // NOT FILLABLE AND `forceFill` IS NOT USED, exactly as the repository writes them: assigning
    // the attributes directly keeps Model::shouldBeStrict()'s guard on mass assignment intact.
    $orgB->embedding_connection_id = $connectionB->id;
    $orgB->embedding_model = ATOMICITY_EMBEDDING_MODEL;
    $orgB->rerank_connection_id = $connectionB->id;
    $orgB->rerank_model = ATOMICITY_RERANK_MODEL;
    $orgB->storage_bytes_quota = 22_000;
    $orgB->bots_quota = 9;
    $orgB->users_quota = 11;
    $orgB->monthly_tokens_quota = 1_800_000;
    $orgB->save();

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('settings-atomicity-owner')]),
        'primaryA' => $primaryA,
        'standbyA' => $standbyA,
        'connectionB' => $connectionB,
    ];
}

function atomicityEmbeddingUrl(Organization $organization): string
{
    return "/api/v1/organizations/{$organization->id}/embedding-configuration";
}

function atomicityRerankUrl(Organization $organization): string
{
    return "/api/v1/organizations/{$organization->id}/rerank-configuration";
}

function atomicityQuotaUrl(Organization $organization): string
{
    return "/api/v1/organizations/{$organization->id}/quotas";
}

/**
 * The data plane's readiness verdict, faked.
 *
 * IT IS NOT THE SUBJECT AND IT IS NOT A STREAM. pest-testing NN4 bans Http::fake() on the CHAT
 * RELAY path, where a faked body is a string the relay drains in microseconds and every buffering,
 * heartbeat and ordering bug passes. This is a small buffered JSON round trip and the rule it
 * carries belongs to embedding_selection.py, which tests it. What matters here is only that the
 * verdict is READY: an unready one is a 422 raised BEFORE the transaction opens, and a case that
 * got one would never reach the property this section is about.
 */
function atomicityReadinessFake(string $connectionId): void
{
    Http::fake([
        '*/internal/v1/embedding/readiness' => Http::response([
            'selected' => [
                'connection_id' => $connectionId,
                'provider' => Provider::NvidiaNim->value,
                'model' => ATOMICITY_EMBEDDING_MODEL,
                'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
            ],
            'eligible' => [],
            'rejected' => [],
            'explanation' => '',
        ], 200),
    ]);
}

it('does not move the embedding designation when its audit row cannot be written', function (): void {
    $fixture = organizationSettingsAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    atomicityReadinessFake($fixture['primaryA']->id);

    $url = atomicityEmbeddingUrl($fixture['orgA']);

    // POSITIVE CONTROL, AND IT IS THE REQUEST ITSELF. See the section header: this exact shape
    // returns 200 and persists here, which is what makes the identical shape 500ing below a
    // statement about the audit write rather than about the endpoint.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_EMBEDDING_MODEL,
    ], spaHeaders())->assertOk();

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'embedding_connection_id' => $fixture['primaryA']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);

    // AND THE OPERATION THIS TEST IS REGISTERED AGAINST IS THE ONE THIS REQUEST ACTUALLY WRITES.
    // Without this line the completeness gate's claim — that every ABORT operation with a producer
    // has a test HERE — is a claim about a test name. `record()` derives the operation from the
    // PERSISTED state, so a future path that wrote these columns another way would produce a
    // different row and every assertion below would still pass.
    expect(AuditLog::query()->where('operation', '=', AuditLogger::EMBEDDING_DESIGNATION_SET)->count())
        ->toBe(1, 'this request did not write an `'.AuditLogger::EMBEDDING_DESIGNATION_SET
            .'` row, so nothing below is a test of that operation\'s failure policy');

    auditWritesBroken();

    // A REPLACEMENT AND NOT A FIRST WRITE. Both columns are written by save() before the audit
    // closure runs (EloquentOrganizationRepository::designateEmbeddingConnection), so both really
    // have to come back — to a specific previous PAIR rather than to the null a create-flavoured
    // case would assert. This is the shape that fails if the audit write is moved out of the
    // repository's transaction in either direction.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['standbyA']->id,
        'model' => ATOMICITY_EMBEDDING_REPLACEMENT,
    ], spaHeaders())
        // ON_FAILURE_ABORT rethrows the QueryException UNWRAPPED, so the taxonomy classifies the
        // SQLSTATE rather than a service-layer wrapper.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE VECTOR SPACE DID NOT MOVE. That pair IS the vector space (ADR-031) and it is part of the
    // Qdrant collection name, so a designation that committed without its audit row strands every
    // chunk already indexed, and the one record of what to re-index BACK to — the `previous_*`
    // half of the row that was refused — would never have existed.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'embedding_connection_id' => $fixture['primaryA']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);

    // NOWHERE, not merely "not on org A". A commit that reached any row is visible here.
    assertDatabaseMissing('organizations', ['embedding_model' => ATOMICITY_EMBEDDING_REPLACEMENT]);
    assertDatabaseMissing('organizations', ['embedding_connection_id' => $fixture['standbyA']->id]);

    // AND ORG B SURVIVES, designated to a connection of ITS OWN that carries the SAME model id — so
    // a write whose organization predicate had been deleted is visible here and in no assertion
    // above, and a rollback that reached beyond its own savepoint is visible only here.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgB']->id,
        'embedding_connection_id' => $fixture['connectionB']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);
});

it('does not clear the embedding designation when its audit row cannot be written', function (): void {
    $fixture = organizationSettingsAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    atomicityReadinessFake($fixture['primaryA']->id);

    $url = atomicityEmbeddingUrl($fixture['orgA']);

    // POSITIVE CONTROL: the designation this test claims survives is really there, and it got there
    // through the endpoint that is about to fail.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_EMBEDDING_MODEL,
    ], spaHeaders())->assertOk();

    // A CLEAR RUN TO COMPLETION FIRST, AND IT IS THE CONTROL THIS CASE CANNOT DO WITHOUT. The
    // request below never writes its row, so nothing after the break can show WHICH operation this
    // test covers — and the completeness gate registers it against `cleared`, not `set`. This is
    // the one clear that is allowed to succeed, and it proves the endpoint produces that operation.
    currentTest()->putJson($url, ['connection_id' => null, 'model' => null], spaHeaders())->assertOk();

    expect(AuditLog::query()->where('operation', '=', AuditLogger::EMBEDDING_DESIGNATION_CLEARED)->count())
        ->toBe(1, 'clearing the designation did not write an `'
            .AuditLogger::EMBEDDING_DESIGNATION_CLEARED
            .'` row, so nothing below is a test of that operation\'s failure policy');

    // RESTORED, so the break below has a non-null pair to roll back TO. A clear attempted from an
    // already-cleared row would assert two nulls came back, which is true of a transaction that did
    // nothing at all.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_EMBEDDING_MODEL,
    ], spaHeaders())->assertOk();

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'embedding_connection_id' => $fixture['primaryA']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);

    auditWritesBroken();

    // CLEARING IS A DIFFERENT OPERATION AND NOT A `set` WITH NULLS — `record()` derives it from the
    // PERSISTED state, so this request is the only producer of
    // `organization.embedding_designation.cleared` and the only thing that can prove its policy.
    currentTest()->putJson($url, ['connection_id' => null, 'model' => null], spaHeaders())
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE PAIR CAME BACK. A clear that committed without its audit row hands the choice to the
    // resolution rule in embedding_selection.py, which may pick a DIFFERENT connection — silently
    // changing the vector space under a corpus nobody reindexed — or refuse outright and block
    // ingestion for the organization, with nothing recording that anybody cleared anything.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'embedding_connection_id' => $fixture['primaryA']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);

    assertDatabaseMissing('organizations', [
        'id' => $fixture['orgA']->id,
        'embedding_connection_id' => null,
    ]);

    // ORG B SURVIVES, still designated. A clear whose predicate lost its organization term would
    // leave org B with two nulls, which is a state nothing downstream raises about.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgB']->id,
        'embedding_connection_id' => $fixture['connectionB']->id,
        'embedding_model' => ATOMICITY_EMBEDDING_MODEL,
    ]);
});

it('does not move the rerank designation when its audit row cannot be written', function (): void {
    $fixture = organizationSettingsAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // UNREACHABLE ON CORRECT CODE, and that is the point of stating it: neither rerank action
    // crosses the seam, which RerankConfigurationTest pins with Http::assertNothingSent(). It is
    // armed here so that if this endpoint ever grows a round trip, this test fails on its
    // assertions rather than on a socket the suite must never open.
    Http::fake();

    $url = atomicityRerankUrl($fixture['orgA']);

    // POSITIVE CONTROL, AND IT IS THE REQUEST ITSELF.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_RERANK_MODEL,
    ], spaHeaders())->assertOk();

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'rerank_connection_id' => $fixture['primaryA']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);

    // AND THE OPERATION THIS TEST IS REGISTERED AGAINST IS THE ONE THIS REQUEST ACTUALLY WRITES.
    // Without it the completeness gate's claim is a claim about a test name.
    expect(AuditLog::query()->where('operation', '=', AuditLogger::RERANK_DESIGNATION_SET)->count())
        ->toBe(1, 'this request did not write an `'.AuditLogger::RERANK_DESIGNATION_SET
            .'` row, so nothing below is a test of that operation\'s failure policy');

    auditWritesBroken();

    // Both columns are written by save() before the audit closure runs
    // (EloquentOrganizationRepository::designateRerankConnection), so both have to come back.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['standbyA']->id,
        'model' => ATOMICITY_RERANK_REPLACEMENT,
    ], spaHeaders())
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE QUIETEST OF THE FIVE, WHICH IS WHY IT IS AUDITED. A rerank designation that committed
    // without its audit row raises nothing, changes no status, and moves no metric on any single
    // request — the credential that sees this tenant's end-user questions and its retrieved chunk
    // text changes, the billing moves with it, and the only symptom is answers getting worse.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'rerank_connection_id' => $fixture['primaryA']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);

    assertDatabaseMissing('organizations', ['rerank_model' => ATOMICITY_RERANK_REPLACEMENT]);
    assertDatabaseMissing('organizations', ['rerank_connection_id' => $fixture['standbyA']->id]);

    // ORG B SURVIVES, on a connection of its own carrying the SAME model id.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgB']->id,
        'rerank_connection_id' => $fixture['connectionB']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);
});

it('does not clear the rerank designation when its audit row cannot be written', function (): void {
    $fixture = organizationSettingsAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    Http::fake();

    $url = atomicityRerankUrl($fixture['orgA']);

    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_RERANK_MODEL,
    ], spaHeaders())->assertOk();

    // A CLEAR RUN TO COMPLETION FIRST, for the reason the embedding clear states: the request
    // below never writes its row, so nothing after the break can show which operation this test
    // covers, and the completeness gate registers it against `cleared` rather than `set`.
    currentTest()->putJson($url, ['connection_id' => null, 'model' => null], spaHeaders())->assertOk();

    expect(AuditLog::query()->where('operation', '=', AuditLogger::RERANK_DESIGNATION_CLEARED)->count())
        ->toBe(1, 'clearing the designation did not write an `'
            .AuditLogger::RERANK_DESIGNATION_CLEARED
            .'` row, so nothing below is a test of that operation\'s failure policy');

    // RESTORED, so the break below has a non-null pair to roll back TO.
    currentTest()->putJson($url, [
        'connection_id' => $fixture['primaryA']->id,
        'model' => ATOMICITY_RERANK_MODEL,
    ], spaHeaders())->assertOk();

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'rerank_connection_id' => $fixture['primaryA']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);

    auditWritesBroken();

    // The only producer of `organization.rerank_designation.cleared`: `record()` derives the
    // operation from the PERSISTED state, so a clear is the one request that can write it.
    currentTest()->putJson($url, ['connection_id' => null, 'model' => null], spaHeaders())
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // THE PAIR CAME BACK, and this is the case where "nothing committed" is hardest to notice in
    // production: a clear that stuck is a SUPPORTED operating mode — `rerank_gate` returns
    // MODEL_NOT_CONFIGURED before any call goes out and `evidence.select_unranked` serves fused
    // order — so no error is raised, no status changes, and the trail says nobody turned it off.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'rerank_connection_id' => $fixture['primaryA']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);

    assertDatabaseMissing('organizations', [
        'id' => $fixture['orgA']->id,
        'rerank_connection_id' => null,
    ]);

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgB']->id,
        'rerank_connection_id' => $fixture['connectionB']->id,
        'rerank_model' => ATOMICITY_RERANK_MODEL,
    ]);
});

it('moves no quota ceiling when the audit row cannot be written', function (): void {
    $fixture = organizationSettingsAtomicityFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // UNREACHABLE ON CORRECT CODE: neither quota action crosses the seam. Armed for the reason the
    // rerank cases arm it.
    Http::fake();

    $url = atomicityQuotaUrl($fixture['orgA']);

    // POSITIVE CONTROL, AND IT IS THE REQUEST ITSELF. Setting a ceiling where there was none is a
    // LOWERING rather than a raise — `null` is unlimited, so nothing is higher — which is why an
    // organization owner may make this call and the fixture needs no platform-owner flag.
    currentTest()->putJson($url, [
        'storage_bytes_quota' => 10_000,
        'bots_quota' => 5,
        'users_quota' => 7,
        'monthly_tokens_quota' => 900_000,
    ], spaHeaders())->assertOk();

    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'storage_bytes_quota' => 10_000,
        'bots_quota' => 5,
        'users_quota' => 7,
        'monthly_tokens_quota' => 900_000,
    ]);

    // AND THE OPERATION THIS TEST IS REGISTERED AGAINST IS THE ONE THIS REQUEST ACTUALLY WRITES.
    // Without it the completeness gate's claim is a claim about a test name.
    expect(AuditLog::query()->where('operation', '=', AuditLogger::QUOTA_LIMITS_UPDATED)->count())
        ->toBe(1, 'this request did not write an `'.AuditLogger::QUOTA_LIMITS_UPDATED
            .'` row, so nothing below is a test of that operation\'s failure policy');

    auditWritesBroken();

    // FOUR COLUMNS IN ONE save(), ALL FOUR BEFORE THE AUDIT CLOSURE
    // (EloquentOrganizationRepository::setQuotaLimits), so the rollback has to bring back four
    // independently observable numbers rather than one. All four move DOWN, so the service's
    // direction guard admits the write and this test reaches the transaction it is about.
    currentTest()->putJson($url, [
        'storage_bytes_quota' => 4_000,
        'bots_quota' => 2,
        'users_quota' => 3,
        'monthly_tokens_quota' => 100_000,
    ], spaHeaders())
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency');

    // EVERY CEILING CAME BACK. A quota that moved with nothing recording who moved it is the one
    // state this table exists to make impossible — §6.1 assigns limit control to the PLATFORM owner
    // while §6.2 gives the organization owner "manage organization settings", and the audit row is
    // what makes that unresolved contradiction reviewable at all.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgA']->id,
        'storage_bytes_quota' => 10_000,
        'bots_quota' => 5,
        'users_quota' => 7,
        'monthly_tokens_quota' => 900_000,
    ]);

    // AND NO ROW ANYWHERE HOLDS THE SUBMITTED NUMBERS. Keyed on the metric a partial commit would
    // be most damaging on: a storage ceiling that stuck refuses every further upload.
    assertDatabaseMissing('organizations', ['storage_bytes_quota' => 4_000]);
    assertDatabaseMissing('organizations', ['monthly_tokens_quota' => 100_000]);

    // ORG B SURVIVES, WITH ALL FOUR OF ITS OWN NUMBERS. This is the assertion a missing
    // organization predicate lands on: `setQuotaLimits()` writes four plain integers, so a write
    // that reached the wrong row leaves no constraint violation and no dangling reference behind
    // it — unlike the two designations, whose composite foreign keys would refuse.
    assertDatabaseHas('organizations', [
        'id' => $fixture['orgB']->id,
        'storage_bytes_quota' => 22_000,
        'bots_quota' => 9,
        'users_quota' => 11,
        'monthly_tokens_quota' => 1_800_000,
    ]);
});

// -------------------------------------------------------------------------------------------
// ON_FAILURE_LOG: the action stands, and the failure is loud
// -------------------------------------------------------------------------------------------

it('still logs a user in when the login audit row cannot be written, and says so', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->recycle($organization)->orgRole(OrgRole::Admin)->create();

    [$spy, $recorder] = auditWritesBrokenAboveTheDriver();

    // ON_FAILURE_LOG, and the trade is deliberate: refusing every login because the audit table is
    // unwritable turns an audit outage into a total outage. What must not happen is silence.
    $sessionId = SpaSession::establish(currentTest(), $user);

    expect($sessionId)->not->toBe('');

    currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    // POSITIVE CONTROL that the double was actually reached: a binding that silently did not take
    // effect would leave the real repository in place, every write would succeed, and "the login
    // worked" would be asserted about an application whose audit path was never exercised.
    expect($spy->writes)->toBe([]);

    $errors = $recorder->messagesAt('error');

    expect($errors)->not->toBeEmpty(
        'the audit write failed and nothing was logged. A LOG-policy operation whose failure is silent '
        .'is worse than an ABORT one: the action stands, the record is missing, and no alert fires.',
    );

    $joined = implode(' | ', $errors);

    expect(str_contains($joined, 'AUDIT WRITE FAILED'))->toBeTrue($joined);
    expect(str_contains($joined, AuditLogger::LOGIN_SUCCEEDED))->toBeTrue($joined);
});

it('still logs a user out when the logout audit row cannot be written', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->recycle($organization)->orgRole(OrgRole::Admin)->create();

    SpaSession::establish(currentTest(), $user);
    currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    [, $recorder] = auditWritesBrokenAboveTheDriver();

    currentTest()->postJson('/api/v1/auth/logout', [], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    SpaSession::freshProcess();

    currentTest()->getJson('/api/v1/me', spaHeaders())->assertStatus(401);

    expect(implode(' | ', $recorder->messagesAt('error')))->toContain('AUDIT WRITE FAILED');
});

// -------------------------------------------------------------------------------------------
// The policy table itself
// -------------------------------------------------------------------------------------------

it('has a test in this file for every ABORT-policy operation that has a producer', function (): void {
    // THE GATE THAT MAKES THIS FILE STAY COMPLETE. A new ON_FAILURE_ABORT operation with no atomicity
    // test is an operation whose transaction boundary nobody has checked, and the failure mode —
    // a state change that commits without its audit row — is invisible in every other test.
    $abort = [];

    foreach (AuditLogger::OPERATIONS as $operation => $spec) {
        if ($spec['on_failure'] === AuditLogger::ON_FAILURE_ABORT) {
            $abort[] = $operation;
        }
    }

    sort($abort);

    $covered = [
        AuditLogger::EMAIL_VERIFIED,
        AuditLogger::INVITATION_ACCEPTED,
        AuditLogger::INVITATION_CREATED,
        AuditLogger::INVITATION_RESENT,
        AuditLogger::INVITATION_REVOKED,
        AuditLogger::PASSWORD_RESET_COMPLETED,

        // The four provider-connection operations. All four have live producers —
        // ProviderConnectionController@store/@update/@destroy and
        // RotateProviderCredentialController@__invoke — so none of them belongs in $noProducer, and
        // each has a test above that breaks the audit INSERT and asserts the business row is
        // unchanged. See that section's header for which of the four can also prove the audit write
        // is INSIDE the transaction rather than merely before the commit.
        AuditLogger::PROVIDER_CONNECTION_CREATED,
        AuditLogger::PROVIDER_CONNECTION_DELETED,
        AuditLogger::PROVIDER_CONNECTION_UPDATED,
        AuditLogger::PROVIDER_CREDENTIAL_ROTATED,

        // The three provider-MODEL operations. All three have live producers —
        // ProviderModelController@store/@update/@destroy — so none of them belongs in $noProducer,
        // and each has a test above that breaks the audit INSERT and asserts the catalog row is
        // unchanged. See that section's header for which of the three can also prove the audit
        // write is INSIDE the transaction rather than merely before the commit.
        AuditLogger::PROVIDER_MODEL_CREATED,
        AuditLogger::PROVIDER_MODEL_DELETED,
        AuditLogger::PROVIDER_MODEL_UPDATED,

        // The three BOT operations. All three have live producers — BotController@store/@update/
        // @destroy — so none of them belongs in $noProducer, and each has a test above that breaks
        // the audit INSERT and asserts the bot is unchanged. The update test is the one that also
        // proves the write is INSIDE the transaction rather than merely before the commit, because
        // it asserts `retrieval_configuration_version` came back; the delete test additionally
        // asserts the CHILD rows the same transaction removes came back, which no other operation
        // in this file has an equivalent of.
        AuditLogger::BOT_CREATED,
        AuditLogger::BOT_DELETED,
        AuditLogger::BOT_UPDATED,

        // THE THREE ORIGIN-ALLOW-LIST OPERATIONS. All three have live producers —
        // BotDomainController@store/@update/@destroy — and this is the family where the ABORT
        // policy carries the most weight: a grant to a page on the public internet that committed
        // without its audit row is finding L2 re-opened in the shape nobody notices, because the
        // widget works and only the trail is wrong. The `status` case is the one that also proves
        // the write is INSIDE the transaction rather than merely before the commit, because
        // `changeStatus()` calls save() first and the assertion is that the column came back.
        AuditLogger::BOT_DOMAIN_CREATED,
        AuditLogger::BOT_DOMAIN_DELETED,
        AuditLogger::BOT_DOMAIN_STATUS_CHANGED,

        // THE THREE STARTER-QUESTION OPERATIONS. All three have live producers —
        // BotStarterQuestionController@store/@update/@destroy. The update case is the strong one
        // for the same structural reason as the domain status case, and additionally because a
        // partial rollback of the RE-SEQUENCE would leave two rows claiming one position, which the
        // unique index refuses — so the observable failure would be a 23505 on somebody else's
        // later request.
        AuditLogger::BOT_STARTER_QUESTION_CREATED,
        AuditLogger::BOT_STARTER_QUESTION_DELETED,
        AuditLogger::BOT_STARTER_QUESTION_UPDATED,

        // THE FIVE ORGANIZATION-LEVEL SETTING OPERATIONS, from THREE producers. All three are live
        // and all three are reachable through a shipped controller, so none of these five belongs
        // in $noProducer:
        //
        //   EmbeddingDesignationService  <- EmbeddingConfigurationController@update
        //                                   (PUT /organizations/{organization}/embedding-configuration)
        //   RerankDesignationService     <- RerankConfigurationController@update
        //                                   (PUT /organizations/{organization}/rerank-configuration)
        //   QuotaLimitService            <- QuotaController@update
        //                                   (PUT /organizations/{organization}/quotas)
        //
        // FIVE OPERATIONS AND THREE PRODUCERS BECAUSE EACH DESIGNATION IS A PAIR: `record()` in
        // both services derives `set` versus `cleared` from the PERSISTED state rather than from
        // the argument it was passed, so a clear is the only request that can write the `cleared`
        // row and each half needs its own case. Each of the five is covered above by a test that
        // breaks the audit INSERT and asserts the column values came back.
        //
        // ALL FIVE ARE THE STRONG SHAPE — they REPLACE a value rather than write a first one, and
        // `save()` runs before the audit closure in all three repository methods — so every one of
        // them fails if the audit write is moved outside the transaction. The embedding pair is the
        // one to read first: that `(provider, model)` IS the vector space (ADR-031).
        AuditLogger::EMBEDDING_DESIGNATION_CLEARED,
        AuditLogger::EMBEDDING_DESIGNATION_SET,
        AuditLogger::QUOTA_LIMITS_UPDATED,
        AuditLogger::RERANK_DESIGNATION_CLEARED,
        AuditLogger::RERANK_DESIGNATION_SET,
    ];

    // ROLE_CHANGED has no producer: PATCH /members/{user} is deliberately not built yet, because the
    // escalation guard for it is its own unit of work. It is listed here with its reason rather than
    // omitted, so the day the route lands this assertion fails and asks for its test.
    //
    // ── AND THE ELEVEN ABORT-POLICY SOURCE OPERATIONS, WHICH ARE HERE BY DESIGN RATHER THAN BY
    //    OVERSIGHT ────────────────────────────────────────────────────────────────────────────
    //
    // Phase C1 registered every knowledge-source audit operation IN ONE PASS, before any endpoint
    // that calls one exists, because the later Phase C steps are forbidden from editing
    // App\Services\Audit\AuditLogger. So all eleven genuinely have no producer today, and this
    // list is what makes that a stated position instead of a gap.
    //
    // THIS IS THE MECHANISM THAT MAKES THE ONE-PASS REGISTRATION SAFE. The moment a controller
    // calls one of these, its atomicity is unproven — and the day somebody moves the name out of
    // this list without writing the test, or writes the endpoint and leaves the name here, this
    // assertion is what says so. Each is ABORT because it is written inside the transaction that
    // performs the change; the twelfth source operation, `source.upload.rejected`, is LOG and is
    // therefore not in scope for this file at all.
    //
    // `source.version.activated` IS THE ONE TO WRITE FIRST when the ingestion callback lands. The
    // pointer switch is the single act that decides what every subsequent query against an item
    // sees, and it is Laravel's rather than the data plane's PRECISELY because the audit row lives
    // here (ADR-012) — an activation that committed without its row would defeat the main argument
    // for the split.
    $noProducer = [
        AuditLogger::ROLE_CHANGED,
        AuditLogger::SOURCE_CREATED,
        AuditLogger::SOURCE_UPDATED,
        AuditLogger::SOURCE_DELETED,
        AuditLogger::SOURCE_DISABLED,
        AuditLogger::SOURCE_ENABLED,
        AuditLogger::SOURCE_REPROCESS_REQUESTED,
        AuditLogger::SOURCE_UPLOAD_ACCEPTED,
        AuditLogger::SOURCE_VERSION_ACTIVATED,
        AuditLogger::SOURCE_VERSION_RETIRED,
        AuditLogger::BOT_SOURCE_ASSIGNMENT_CREATED,
        AuditLogger::BOT_SOURCE_ASSIGNMENT_DELETED,
    ];

    sort($covered);
    $expected = array_merge($covered, $noProducer);
    sort($expected);

    expect($abort)->toBe(
        $expected,
        'the set of ON_FAILURE_ABORT operations no longer matches what this file covers. Either add a '
        .'test that breaks the audit INSERT and asserts nothing committed, or — if the operation still '
        .'has no producer — move it into $noProducer with the reason written beside it.',
    );
});

it('keeps every plaintext capability out of audit_logs across four separate flows', function (): void {
    // FOUR SECRET-SHAPED VALUES FROM FOUR DIFFERENT OPERATIONS, and every one asserted. A one-token
    // fixture passes while the second and third leak: each operation has its OWN details allow-list in
    // AuditLogger::OPERATIONS, so `token => FINGERPRINTED` being right on one row says nothing about
    // the next.
    $organization = Organization::factory()->create(['name' => 'Redaction Org ALPHA']);

    // VERIFIED, because the org-scoped routes carry `verified` and an unverified owner would 403 on
    // the invite. The unverified account the verification flow needs is a second user, below.
    $owner = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('redaction-owner')]);

    $unverified = User::factory()->create([
        'email' => SpaSession::uniqueEmail('redaction-unverified'),
        'email_verified_at' => null,
    ]);

    SpaSession::establish(currentTest(), $owner);

    $tokens = [];

    // 1. INVITATION_CREATED
    currentTest()->postJson(
        "/api/v1/organizations/{$organization->id}/invitations",
        ['email' => 'redaction-invitee@example.test', 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )->assertStatus(201);
    $tokens['invitation.created'] = Mailbox::latestToken();

    // 2. INVITATION_RESENT
    /** @var OrganizationInvitation $invitation */
    $invitation = OrganizationInvitation::query()->where('email', 'redaction-invitee@example.test')->firstOrFail();

    currentTest()->postJson(
        "/api/v1/organizations/{$organization->id}/invitations/{$invitation->id}/resend",
        [],
        spaHeaders(),
    )->assertOk();
    $tokens['invitation.resent'] = Mailbox::latestToken();

    // 3. EMAIL_VERIFIED — a different account, because the owner above is already verified.
    SpaSession::establish(currentTest(), $unverified);

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $tokens['email.verified'] = Mailbox::latestToken();
    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $tokens['email.verified']], spaHeaders())
        ->assertOk();

    // 4. PASSWORD_RESET_REQUESTED and PASSWORD_RESET_COMPLETED share one token
    currentTest()->postJson('/api/v1/auth/forgot-password', ['email' => $owner->email], spaHeaders())
        ->assertOk();
    $tokens['password_reset'] = Mailbox::latestToken();
    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $tokens['password_reset'],
        'email' => $owner->email,
        'password' => 'Redaction-Secret-9',
        'password_confirmation' => 'Redaction-Secret-9',
    ], spaHeaders())->assertOk();

    // POSITIVE CONTROL: the four flows really did write rows, and the tokens are genuinely distinct.
    expect(count(array_unique($tokens)))->toBe(4);
    expect(AuditLog::query()->count())->toBeGreaterThanOrEqual(5);

    // Read the rows back out of PostgreSQL AS TEXT, so a token hiding in a column this test does not
    // know about still trips it.
    $rows = Schema::getConnection()->select('SELECT audit_logs::text AS value FROM audit_logs');
    $raw = (string) json_encode($rows);

    expect($raw)->not->toBe('');

    foreach ($tokens as $operation => $token) {
        expect(str_contains($raw, $token))->toBeFalse(
            "the plaintext capability token from `{$operation}` is stored in audit_logs. That table is "
            .'append-only by grant, so it cannot be scrubbed — and every row in it is a live '
            .'credential for as long as the token has not been consumed.',
        );
    }

    // And the fingerprints ARE there, so the redaction is not "the field was dropped entirely" —
    // which would satisfy every assertion above while destroying the ability to correlate an
    // incident to a token.
    $fingerprinted = AuditLog::query()->get()->filter(
        static fn (AuditLog $row): bool => array_key_exists('token_fingerprint', $row->details),
    );

    expect($fingerprinted)->not->toBeEmpty(
        'no audit row carries a token_fingerprint, so the token was dropped rather than fingerprinted '
        .'and an incident cannot be traced back to the capability that was used',
    );
});

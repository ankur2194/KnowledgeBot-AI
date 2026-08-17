<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\EmailVerificationToken;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Repositories\Eloquent\EloquentAuditLogPartitionRepository;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
    ];

    // ROLE_CHANGED has no producer: PATCH /members/{user} is deliberately not built yet, because the
    // escalation guard for it is its own unit of work. It is listed here with its reason rather than
    // omitted, so the day the route lands this assertion fails and asks for its test.
    $noProducer = [AuditLogger::ROLE_CHANGED];

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

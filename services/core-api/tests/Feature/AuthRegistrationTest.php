<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RegistrationService;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Mailbox;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Invitation preview, registration and acceptance — routes 4, 5 and 11
|--------------------------------------------------------------------------
|
| The enumeration property of all three lives in tests/Security/AccountEnumerationTest.php. This file
| asserts the flows work, that the invitation is the SOLE authority for the address and the role, and
| that acceptance grants membership of ONE organization.
|
| TWO ORGANIZATIONS IN EVERY FIXTURE, with distinguishable names. A one-organization fixture cannot
| fail "registration granted membership of the wrong tenant": there is no wrong tenant to grant.
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() once its KnowledgeSource half lands
| in Phase C. Its bot half is already live.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite. `login` is 20/minute per IP and every request
    // here arrives from one address, so without this a suite that logs in for real is a 429 storm that
    // only appears in CI. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Two organizations, an inviter in each, and one pending invitation into org A.
 *
 * @return array{orgA: Organization, orgB: Organization, inviterA: User, inviterB: User, email: string, token: string, invitation: OrganizationInvitation}
 */
function registrationFixture(OrgRole $role = OrgRole::Analyst, ?string $email = null): array
{
    // The invited address is unique per test: on the acceptance path the invitee LOGS IN, and
    // `throttle:login` keys on the submitted address at five per minute against a cache that outlives
    // the test. The `invitee-`/`joiner-` prefix is what keeps a failure message readable.
    $email ??= SpaSession::uniqueEmail('invitee');

    $orgA = Organization::factory()->create(['name' => 'Registration Org ALPHA', 'slug' => 'registration-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Registration Org BRAVO', 'slug' => 'registration-bravo']);

    $inviterA = User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
        ->create(['name' => 'Inviter Alpha', 'email' => SpaSession::uniqueEmail('inviter-alpha')]);
    $inviterB = User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
        ->create(['name' => 'Inviter Bravo', 'email' => SpaSession::uniqueEmail('inviter-bravo')]);

    $token = OpaqueToken::mint();

    $invitation = OrganizationInvitation::factory()->recycle($orgA)->invitedBy($inviterA)
        ->token($token)->role($role)->create(['email' => $email]);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'inviterA' => $inviterA,
        'inviterB' => $inviterB,
        'email' => $email,
        'token' => $token,
        'invitation' => $invitation,
    ];
}

// ── Route 4: POST /api/v1/auth/invitations/preview ──────────────────────────────────────────────

it('previews a pending invitation without disclosing the organization id or the inviter', function (): void {
    $fixture = registrationFixture(OrgRole::KnowledgeManager);

    $response = currentTest()->postJson('/api/v1/auth/invitations/preview', [
        'token' => $fixture['token'],
    ], spaHeaders());

    $response->assertOk()->assertExactJson(['data' => [
        'organization_name' => 'Registration Org ALPHA',
        'email' => $fixture['email'],
        'role' => OrgRole::KnowledgeManager->value,
        'expires_at' => $fixture['invitation']->expires_at->toAtomString(),
    ]]);

    // The reader is NOT a member yet, so the body carries the name and not the addressable id, and
    // says nothing about who invited them.
    $body = (string) $response->getContent();

    expect(str_contains($body, $fixture['orgA']->id))->toBeFalse(
        'the preview leaked the organization ULID — the value that addresses every tenant route — to '
        .'somebody who is not a member',
    );
    expect(str_contains($body, $fixture['inviterA']->email))->toBeFalse();
    expect(str_contains($body, 'Inviter Alpha'))->toBeFalse();

    // And nothing about the second organization, which exists and has its own invitations.
    expect(str_contains($body, 'BRAVO'))->toBeFalse();
});

it('rejects a token of the wrong size before any lookup', function (string $token): void {
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $token], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['token']);
})->with([
    'too short' => str_repeat('a', 63),
    'too long' => str_repeat('a', 65),
    'empty' => '',
]);

// ── Route 5: POST /api/v1/auth/register ─────────────────────────────────────────────────────────

it('registers, consumes the invitation and establishes a session, unverified', function (): void {
    $fixture = registrationFixture(OrgRole::KnowledgeManager);

    $response = currentTest()->withCredentials()->postJson('/api/v1/auth/register', [
        'token' => $fixture['token'],
        'name' => 'New Registrant',
        'password' => 'Registration-Secret-9',
        'password_confirmation' => 'Registration-Secret-9',
    ], spaHeaders());

    // 201, and the body is the same SessionResource shape login returns, wrapped in `data`.
    $response->assertStatus(201)
        ->assertJsonPath('data.user.name', 'New Registrant')
        // THE ADDRESS COMES FROM THE INVITATION AND FROM NOWHERE ELSE (D3).
        ->assertJsonPath('data.user.email', $fixture['email'])
        // UNVERIFIED. Verification is not a precondition of registration; it gates tenant data.
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.current_organization_id', $fixture['orgA']->id)
        ->assertJsonCount(1, 'data.organizations')
        ->assertJsonPath('data.organizations.0.id', $fixture['orgA']->id)
        // THE ROLE COMES FROM THE INVITATION, set by an administrator at invitation time.
        ->assertJsonPath('data.organizations.0.role', OrgRole::KnowledgeManager->value)
        ->assertJsonPath('data.organizations.0.status', MembershipStatus::Active->value);

    /** @var User $user */
    $user = User::query()->where('email', $fixture['email'])->firstOrFail();

    expect(Hash::check('Registration-Secret-9', (string) $user->getAuthPassword()))->toBeTrue()
        ->and($user->is_platform_owner)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    // ONE organization, and it is org A. Org B exists and is untouched — the assertion that a
    // one-organization fixture cannot make.
    expect($user->isActiveMemberOf($fixture['orgA']->id))->toBeTrue()
        ->and($user->membershipFor($fixture['orgB']->id))->toBeNull();

    /** @var OrganizationInvitation $invitation */
    $invitation = $fixture['invitation']->fresh();

    expect($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->accepted_by_id)->toBe($user->id)
        ->and($invitation->revoked_at)->toBeNull()
        ->and($invitation->isPending())->toBeFalse();

    // A REAL SESSION, and the verification mail went out on the Registered event.
    expect(SpaSession::idFrom($response))->not->toBe('');

    SpaSession::resume(currentTest(), SpaSession::idFrom($response));

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.email', $fixture['email']);

    expect(Mailbox::latestRecipients())->toBe([$fixture['email']]);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::INVITATION_ACCEPTED)->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->subject_id)->toBe($fixture['invitation']->id)
        ->and($audit->details['role'] ?? null)->toBe(OrgRole::KnowledgeManager->value);
});

it('ignores an over-posted address, role and organization, because the token is the authority', function (): void {
    // OVER-POSTING A TENANT KEY OR A ROLE IS AN AUTHORIZATION BUG WITH A 200 RESPONSE. RegisterRequest
    // declares neither field, so these values must be discarded rather than merely mistrusted — and
    // `email` is absent by decision D3, which is why submitting a different address cannot even be a
    // mismatch error.
    $fixture = registrationFixture(OrgRole::Analyst);

    currentTest()->postJson('/api/v1/auth/register', [
        'token' => $fixture['token'],
        'name' => 'Escalation Attempt',
        'password' => 'Registration-Secret-9',
        'password_confirmation' => 'Registration-Secret-9',
        'email' => 'attacker-chosen@example.test',
        'role' => OrgRole::Owner->value,
        'organization_id' => $fixture['orgB']->id,
        'is_platform_owner' => true,
    ], spaHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.user.email', $fixture['email'])
        ->assertJsonPath('data.user.is_platform_owner', false)
        ->assertJsonPath('data.organizations.0.id', $fixture['orgA']->id)
        ->assertJsonPath('data.organizations.0.role', OrgRole::Analyst->value)
        ->assertJsonCount(1, 'data.organizations');

    expect(User::query()->where('email', 'attacker-chosen@example.test')->exists())->toBeFalse(
        'the over-posted address created an account, so the invitation is not the authority for the '
        .'address it was issued to',
    );
});

it('tells a token holder that an account already exists, and only them', function (): void {
    // A DELIBERATE DISCLOSURE, and the reason it is acceptable: the caller has already proved
    // possession of an invitation token bound to that exact address, which an administrator of the
    // organization deliberately sent there.
    $fixture = registrationFixture();
    $existing = User::factory()->create(['email' => $fixture['email']]);

    currentTest()->postJson('/api/v1/auth/register', [
        'token' => $fixture['token'],
        'name' => 'Duplicate Registrant',
        'password' => 'Registration-Secret-9',
        'password_confirmation' => 'Registration-Secret-9',
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('errors.email', [RegistrationService::ACCOUNT_EXISTS])
        // NOT a 409: bootstrap/app.php's default arm assigns 409 the class `internal_dependency`,
        // which is a correct rendering and a terrible contract for a registration conflict.
        ->assertJsonMissingPath('errors.token');

    // Nothing happened to the existing account or to the invitation.
    expect($existing->fresh()?->name)->toBe($existing->name)
        ->and($fixture['invitation']->fresh()?->accepted_at)->toBeNull();
});

it('enforces the password policy and the name bounds on registration', function (
    string $case,
    array $overrides,
    string $field,
): void {
    $fixture = registrationFixture();

    currentTest()->postJson('/api/v1/auth/register', array_merge([
        'token' => $fixture['token'],
        'name' => 'Policy Registrant',
        'password' => 'Registration-Secret-9',
        'password_confirmation' => 'Registration-Secret-9',
    ], $overrides), spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors([$field]);

    expect(User::query()->where('email', $fixture['email'])->exists())
        ->toBeFalse("[{$case}] created an account anyway");
})->with([
    'short password' => ['short password', ['password' => 'Short-1a', 'password_confirmation' => 'Short-1a'], 'password'],
    'unconfirmed password' => ['unconfirmed', ['password_confirmation' => 'Something-Else-9'], 'password'],
    'no name' => ['no name', ['name' => ''], 'name'],
    'name over 120 characters' => ['long name', ['name' => str_repeat('n', 121)], 'name'],
]);

// ── Route 11: POST /api/v1/auth/invitations/accept ──────────────────────────────────────────────

it('adds an existing signed-in user to the inviting organization and selects it', function (): void {
    $fixture = registrationFixture(OrgRole::Admin, SpaSession::uniqueEmail('joiner'));

    // A user who already belongs to org B — so acceptance is an ADDITION and the assertion can see
    // whether the existing membership survived.
    $joiner = User::factory()->recycle($fixture['orgB'])->orgRole(OrgRole::Analyst)
        ->create(['email' => $fixture['email']]);

    SpaSession::establish(currentTest(), $joiner);

    currentTest()->postJson('/api/v1/auth/invitations/accept', [
        'token' => $fixture['token'],
    ], spaHeaders())
        ->assertOk()
        // THE NEW ORGANIZATION IS ALREADY SELECTED, so the SPA lands the user inside it without a
        // second call.
        ->assertJsonPath('data.current_organization_id', $fixture['orgA']->id)
        ->assertJsonCount(2, 'data.organizations');

    expect($joiner->isActiveMemberOf($fixture['orgA']->id))->toBeTrue()
        // THE PRE-EXISTING MEMBERSHIP SURVIVES. Acceptance adds; it does not move a user between
        // tenants.
        ->and($joiner->isActiveMemberOf($fixture['orgB']->id))->toBeTrue();

    $membership = $joiner->membershipFor($fixture['orgA']->id);

    expect($membership?->role)->toBe(OrgRole::Admin)
        ->and($joiner->membershipFor($fixture['orgB']->id)?->role)->toBe(OrgRole::Analyst);

    expect($fixture['invitation']->fresh()?->accepted_by_id)->toBe($joiner->id);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::INVITATION_ACCEPTED)->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->actor_id)->toBe($joiner->id);
});

it('401s acceptance with no session, because it is the signed-in half of the flow', function (): void {
    $fixture = registrationFixture(OrgRole::Admin, SpaSession::uniqueEmail('joiner'));

    currentTest()->postJson('/api/v1/auth/invitations/accept', [
        'token' => $fixture['token'],
    ], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    expect($fixture['invitation']->fresh()?->accepted_at)->toBeNull();
});

it('does not let acceptance carry a role or an organization from the request body', function (): void {
    $fixture = registrationFixture(OrgRole::Analyst, SpaSession::uniqueEmail('joiner'));

    $joiner = User::factory()->create(['email' => $fixture['email']]);

    SpaSession::establish(currentTest(), $joiner);

    currentTest()->postJson('/api/v1/auth/invitations/accept', [
        'token' => $fixture['token'],
        'role' => OrgRole::Owner->value,
        'organization_id' => $fixture['orgB']->id,
    ], spaHeaders())->assertOk();

    expect($joiner->membershipFor($fixture['orgA']->id)?->role)->toBe(OrgRole::Analyst)
        ->and($joiner->membershipFor($fixture['orgB']->id))->toBeNull();
});

it('refuses an invitation that expired while the user was signing in', function (): void {
    $fixture = registrationFixture(OrgRole::Admin, SpaSession::uniqueEmail('joiner'));

    $joiner = User::factory()->create(['email' => $fixture['email']]);

    SpaSession::establish(currentTest(), $joiner);

    $fixture['invitation']->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

    currentTest()->postJson('/api/v1/auth/invitations/accept', [
        'token' => $fixture['token'],
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('errors.token', [RegistrationService::INVALID_INVITATION]);

    expect($joiner->membershipFor($fixture['orgA']->id))->toBeNull();
});

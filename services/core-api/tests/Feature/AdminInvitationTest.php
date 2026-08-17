<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\InvitationService;
use App\Support\Kb\OpaqueToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Mailbox;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Invitations and members, admin side — routes 12 to 16
|--------------------------------------------------------------------------
|
| These are the only ORG-SCOPED routes in this change set, so they are the only ones where the seven
| layers of tenant isolation are reachable at all. Every fixture here is therefore TWO
| ORGANIZATIONS with overlapping, distinguishable data: the same invited address in both, so a
| missing organization predicate on any list, lookup, revoke or resend shows up as the wrong row
| rather than as no row. A one-organization fixture passes every assertion below with the tenant
| filter deleted.
|
| TODO(fixtures): tests/Support/tenancy.php's tenantPair() is the intended home for this, and it
| throws by design until the bots and knowledge_sources migrations exist.
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
 * Two organizations with OVERLAPPING data: the same invited address pending in both, one actor in
 * each, and distinguishable names so a cross-tenant leak is visible in an assertion message.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     invitationA: OrganizationInvitation, invitationB: OrganizationInvitation,
 *     tokenA: string, tokenB: string,
 * }
 */
function invitationOrgPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Invite Org ALPHA', 'slug' => 'invite-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Invite Org BRAVO', 'slug' => 'invite-bravo']);

    // THE NAMES are the readable fixture markers the isolation assertions grep for; the ADDRESSES are
    // unique per test, because `throttle:login` keys on the submitted address at five per minute and
    // this fixture is used by more than twenty tests in this file.
    $ownerA = User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
        ->create(['name' => 'Owner Alpha', 'email' => SpaSession::uniqueEmail('owner-alpha')]);
    $ownerB = User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
        ->create(['name' => 'Owner Bravo', 'email' => SpaSession::uniqueEmail('owner-bravo')]);

    $tokenA = OpaqueToken::mint();
    $tokenB = OpaqueToken::mint();

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => $ownerA,
        'ownerB' => $ownerB,
        'tokenA' => $tokenA,
        'tokenB' => $tokenB,
        // THE SAME ADDRESS IN BOTH ORGANIZATIONS. That is what makes the isolation assertions
        // meaningful: `liveFor(org, email)` without its organization predicate returns a row, just
        // the wrong one, and no assertion that only counts rows would notice.
        'invitationA' => OrganizationInvitation::factory()->recycle($orgA)->invitedBy($ownerA)
            ->token($tokenA)->role(OrgRole::Analyst)->create(['email' => 'shared-invitee@example.test']),
        'invitationB' => OrganizationInvitation::factory()->recycle($orgB)->invitedBy($ownerB)
            ->token($tokenB)->role(OrgRole::Admin)->create(['email' => 'shared-invitee@example.test']),
    ];
}

// ── Route 12: GET /api/v1/organizations/{organization}/invitations ───────────────────────────────

it('lists only the addressed organization\'s invitations, and every status', function (): void {
    $fixture = invitationOrgPair();

    // A terminal row, so `status` is exercised as DERIVED rather than stored.
    $revoked = OrganizationInvitation::factory()->recycle($fixture['orgA'])->invitedBy($fixture['ownerA'])
        ->token(OpaqueToken::mint())->revoked()->create(['email' => 'revoked-invitee@example.test']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        spaHeaders(),
    );

    // POSITIVE CONTROL FIRST: org A's own two rows are there, including the revoked one — hiding
    // terminal rows would make "why can I not re-invite this address" unanswerable from the UI.
    $response->assertOk()->assertJsonCount(2, 'data.invitations');

    $ids = array_column((array) $response->json('data.invitations'), 'id');

    expect($ids)->toContain($fixture['invitationA']->id)
        ->and($ids)->toContain($revoked->id);

    $statuses = array_column((array) $response->json('data.invitations'), 'status');

    expect($statuses)->toContain(InvitationStatus::Pending->value)
        ->and($statuses)->toContain(InvitationStatus::Revoked->value);

    // THE ISOLATION ASSERTION. Org B has a pending invitation for the SAME address, so a missing
    // organization predicate returns a row that looks perfectly plausible.
    $body = (string) $response->getContent();

    expect(str_contains($body, $fixture['invitationB']->id))->toBeFalse(
        'org B\'s invitation id is in org A\'s list',
    );
    expect(str_contains($body, 'BRAVO'))->toBeFalse();
    expect(str_contains($body, 'Owner Bravo'))->toBeFalse();

    // No token, in any form — not the plaintext and not the digest.
    expect(str_contains($body, $fixture['tokenA']))->toBeFalse('the invitation token reached a response body')
        ->and(str_contains($body, 'token'))->toBeFalse();
});

it('403s an owner of one organization who addresses another', function (): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // "Owner of SOME organization" is the cross-tenant bug, and this is the layer that stops it:
    // App\Http\Middleware\TenantContext re-reads organization_users for the {organization} SEGMENT and
    // throws before the policy. 403 and not 404 is correct on the admin surface — a member is
    // entitled to know an organization exists, and DenyOracleTest exists to keep this at 403 so its
    // public 404 arm cannot go vacuous. (The 404-at-binding-time layer is the {invitation} segment,
    // asserted separately below.)
    currentTest()->getJson("/api/v1/organizations/{$fixture['orgB']->id}/invitations", spaHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // And org B's OWNER still gets their own list — the positive control that this is isolation and
    // not a broken route.
    SpaSession::establish(currentTest(), $fixture['ownerB']);

    currentTest()->getJson("/api/v1/organizations/{$fixture['orgB']->id}/invitations", spaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data.invitations')
        ->assertJsonPath('data.invitations.0.id', $fixture['invitationB']->id);
});

// ── Route 13: POST /api/v1/organizations/{organization}/invitations ──────────────────────────────

it('creates an invitation, mails a working link, and keeps the token out of the body', function (): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        ['email' => 'Fresh.Invitee@Example.Test', 'role' => OrgRole::KnowledgeManager->value],
        spaHeaders(),
    );

    $response->assertStatus(201)
        // LOWER-CASED by prepareForValidation(), which the database also enforces with
        // CHECK (email = lower(email)) — a mixed-case row is unreachable by the lookup that has to
        // find it.
        ->assertJsonPath('data.email', 'fresh.invitee@example.test')
        ->assertJsonPath('data.role', OrgRole::KnowledgeManager->value)
        ->assertJsonPath('data.status', InvitationStatus::Pending->value)
        ->assertJsonPath('data.invited_by_name', 'Owner Alpha');

    expect(Mailbox::latestRecipients())->toBe(['fresh.invitee@example.test']);

    $token = Mailbox::latestToken();

    // THE TOKEN IS A BEARER CAPABILITY AND LIVES ONLY IN THE MAIL.
    expect(str_contains((string) $response->getContent(), $token))->toBeFalse(
        'the invitation token is in the API response, so anyone who can read an admin\'s network log '
        .'holds every invitation this organization ever sent',
    );

    // POSITIVE CONTROL: the emailed token actually resolves — otherwise the assertion above passes
    // for an invitation nobody can accept.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $token], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Invite Org ALPHA')
        ->assertJsonPath('data.email', 'fresh.invitee@example.test');

    // The digest is stored, never the token.
    $stored = Schema::getConnection()
        ->table('organization_invitations')
        ->where('email', 'fresh.invitee@example.test')
        ->value('token_hash');

    expect(str_contains((string) $stored, $token))->toBeFalse();

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::INVITATION_CREATED)->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->actor_id)->toBe($fixture['ownerA']->id)
        ->and($audit->details)->toHaveKey('token_fingerprint')
        ->and($audit->details)->not->toHaveKey('token')
        ->and($audit->details['email'] ?? null)->toBe('fresh.invitee@example.test');
});

it('refuses a second live invitation for an address already pending in THIS organization', function (): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        ['email' => 'shared-invitee@example.test', 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('errors.email', [InvitationService::ALREADY_INVITED]);

    expect(Mailbox::count())->toBe(0);

    // AND THE MIRROR IMAGE, which is what makes the assertion above about THIS organization rather
    // than about the address: org B may invite the same address, because the partial unique index is
    // on (organization_id, email).
    SpaSession::establish(currentTest(), $fixture['ownerB']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgB']->id}/invitations",
        ['email' => 'another-invitee@example.test', 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )->assertStatus(201);
});

it('refuses to invite someone who is already a member of this organization', function (): void {
    $fixture = invitationOrgPair();

    $member = User::factory()->recycle($fixture['orgA'])->orgRole(OrgRole::Analyst)
        ->create(['email' => 'existing-member@example.test']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        ['email' => 'existing-member@example.test', 'role' => OrgRole::Admin->value],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('errors.email', [InvitationService::ALREADY_MEMBER]);

    // Org B may still invite them: membership is per organization.
    SpaSession::establish(currentTest(), $fixture['ownerB']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgB']->id}/invitations",
        ['email' => 'existing-member@example.test', 'role' => OrgRole::Admin->value],
        spaHeaders(),
    )->assertStatus(201);

    expect($member->membershipFor($fixture['orgB']->id))->toBeNull();
});

it('lets only an owner invite another owner', function (
    string $role,
    int $ownerInviteStatus,
    int $memberInviteStatus,
): void {
    $fixture = invitationOrgPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("actor-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    // The escalation guard: Permission::MembersManageOwner is held by `owner` alone, so creating a
    // second owner is the one action an admin cannot perform — and cannot undo if they could.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        ['email' => "owner-invite-{$role}@example.test", 'role' => OrgRole::Owner->value],
        spaHeaders(),
    )->assertStatus($ownerInviteStatus);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        ['email' => "member-invite-{$role}@example.test", 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )->assertStatus($memberInviteStatus);
})->with([
    'owner' => [OrgRole::Owner->value, 201, 201],
    'admin' => [OrgRole::Admin->value, 403, 201],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403, 403],
    'analyst' => [OrgRole::Analyst->value, 403, 403],
]);

it('refuses to invite into a suspended organization, as a 422 and not a 409', function (): void {
    // DECISION D36. A 409 would be rendered by bootstrap/app.php's default arm as
    // `internal_dependency`, which says "a dependency of ours is briefly unwell" about an
    // organization-lifecycle refusal that no amount of retrying fixes.
    $organization = Organization::factory()->suspended()->create(['name' => 'Suspended Org CHARLIE']);
    $owner = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)->create();

    SpaSession::establish(currentTest(), $owner);

    currentTest()->postJson(
        "/api/v1/organizations/{$organization->id}/invitations",
        ['email' => 'suspended-invitee@example.test', 'role' => OrgRole::Analyst->value],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['organization']);

    expect(Mailbox::count())->toBe(0)
        ->and(OrganizationInvitation::query()->where('organization_id', $organization->id)->count())->toBe(0);
});

it('rejects a role outside the fixed catalog, and a malformed address', function (
    array $payload,
    string $field,
): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations",
        $payload,
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors([$field]);
})->with([
    'invented role' => [['email' => 'x@example.test', 'role' => 'superuser'], 'role'],
    'no role' => [['email' => 'x@example.test'], 'role'],
    'malformed address' => [['email' => 'not-an-address', 'role' => 'analyst'], 'email'],
    'no address' => [['role' => 'analyst'], 'email'],
]);

// ── Route 14: DELETE /api/v1/organizations/{organization}/invitations/{invitation} ───────────────

it('revokes an invitation and kills its link', function (): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL FIRST: the link works before the revoke.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenA']], spaHeaders())
        ->assertOk();

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$fixture['invitationA']->id}",
        [],
        spaHeaders(),
    )
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect($fixture['invitationA']->fresh()?->revoked_at)->not->toBeNull()
        ->and($fixture['invitationA']->fresh()?->status())->toBe(InvitationStatus::Revoked);

    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenA']], spaHeaders())
        ->assertStatus(404);

    // ORG B'S IDENTICALLY-ADDRESSED INVITATION SURVIVES. This is the assertion a one-organization
    // fixture cannot make, and the one that catches a revoke without an organization predicate.
    expect($fixture['invitationB']->fresh()?->revoked_at)->toBeNull(
        'revoking org A\'s invitation also revoked org B\'s invitation for the same address',
    );

    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenB']], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Invite Org BRAVO');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::INVITATION_REVOKED)->firstOrFail();

    expect($audit->organization_id)->toBe($fixture['orgA']->id)
        ->and($audit->subject_id)->toBe($fixture['invitationA']->id);
});

it('404s a foreign or unknown invitation id at binding time', function (string $case, bool $foreign): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $id = $foreign ? $fixture['invitationB']->id : (string) Str::ulid();

    // ->scopeBindings() resolves {invitation} through $organization->invitations(), so a foreign id
    // never loads: this 404s BEFORE the policy runs and before the row is in memory. A bare
    // `OrganizationInvitation $invitation` binding would be a global find with no organization
    // predicate, executed upstream of every check.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization')
        ->assertJsonPath('message', 'The requested resource was not found.');

    expect($fixture['invitationB']->fresh()?->revoked_at)->toBeNull("[{$case}] revoked it anyway");
})->with([
    'another organization\'s invitation' => ['foreign invitation', true],
    'an id that never existed' => ['unknown invitation', false],
]);

it('refuses to revoke an invitation that has already been accepted', function (): void {
    $fixture = invitationOrgPair();

    $accepter = User::factory()->create(['email' => 'accepter@example.test']);

    $fixture['invitationA']->forceFill([
        'accepted_at' => now(),
        'accepted_by_id' => $accepter->id,
    ])->save();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$fixture['invitationA']->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('errors.invitation', [InvitationService::NOT_REVOCABLE]);

    expect($fixture['invitationA']->fresh()?->revoked_at)->toBeNull();
});

it('lets only members.manage revoke', function (string $role, int $status): void {
    $fixture = invitationOrgPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("revoker-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$fixture['invitationA']->id}",
        [],
        spaHeaders(),
    )->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

// ── Route 15: POST …/invitations/{invitation}/resend ────────────────────────────────────────────

it('mints a new token on resend and kills the previously mailed link', function (): void {
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL FIRST.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenA']], spaHeaders())
        ->assertOk();

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$fixture['invitationA']->id}/resend",
        [],
        spaHeaders(),
    );

    $response->assertOk()->assertExactJson(['data' => ['acknowledged' => true]]);

    $fresh = Mailbox::latestToken();

    expect($fresh)->not->toBe($fixture['tokenA'])
        ->and(str_contains((string) $response->getContent(), $fresh))->toBeFalse();

    // TWO LIVE LINKS TO ONE INVITATION WOULD BE TWO CAPABILITIES WITH ONE AUTHORITY. The old one is
    // dead and the new one works.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenA']], spaHeaders())
        ->assertStatus(404);

    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fresh], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Invite Org ALPHA');

    // Org B's token is untouched by a resend in org A.
    currentTest()->postJson('/api/v1/auth/invitations/preview', ['token' => $fixture['tokenB']], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Invite Org BRAVO');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::INVITATION_RESENT)->firstOrFail();

    expect($audit->details)->toHaveKey('token_fingerprint')
        ->and($audit->details)->not->toHaveKey('token');
});

it('refuses to resend an accepted or revoked invitation', function (string $case, string $state): void {
    $fixture = invitationOrgPair();

    if ($state === 'accepted') {
        $accepter = User::factory()->create();
        $fixture['invitationA']->forceFill(['accepted_at' => now(), 'accepted_by_id' => $accepter->id])->save();
    } else {
        $fixture['invitationA']->forceFill(['revoked_at' => now()])->save();
    }

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$fixture['invitationA']->id}/resend",
        [],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('errors.invitation', [InvitationService::NOT_RESENDABLE]);

    expect(Mailbox::count())->toBe(0, "[{$case}] mailed a link anyway");
})->with([
    'accepted' => ['accepted invitation', 'accepted'],
    'revoked' => ['revoked invitation', 'revoked'],
]);

it('refuses to resend from a suspended organization, as a 422', function (): void {
    // D36 again, on the second endpoint that checks it. Asserted separately because the check is
    // duplicated in ResendInvitationController rather than shared, so one of the two can be deleted
    // without the other test noticing.
    $organization = Organization::factory()->suspended()->create(['name' => 'Suspended Org CHARLIE']);
    $owner = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)->create();
    $invitation = OrganizationInvitation::factory()->recycle($organization)->invitedBy($owner)
        ->token(OpaqueToken::mint())->create(['email' => 'suspended-resend@example.test']);

    SpaSession::establish(currentTest(), $owner);

    currentTest()->postJson(
        "/api/v1/organizations/{$organization->id}/invitations/{$invitation->id}/resend",
        [],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['organization']);

    expect(Mailbox::count())->toBe(0);
});

it('throttles resend on the RECIPIENT, which the actor-keyed admin limiter cannot do', function (): void {
    // `throttle:admin` keys on (organization, user) at 120/min — the ACTOR — so on its own it permits
    // one administrator to mail one invitee 120 live links a minute. That is a mailbox flood aimed at
    // a third party who never asked to be invited, performed with legitimate credentials, and no other
    // control sees it: the policy passes, the org is active, and the invitation is valid every time.
    $fixture = invitationOrgPair();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $resend = static fn (string $invitationId) => currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/invitations/{$invitationId}/resend",
        [],
        spaHeaders(),
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $resend($fixture['invitationA']->id)->assertOk();
    }

    $resend($fixture['invitationA']->id)
        ->assertStatus(429)
        ->assertJsonPath('error_class', 'rate_limit')
        ->assertHeader('Retry-After');

    expect(Mailbox::count())->toBe(3);

    // A DIFFERENT recipient is unaffected: the budget belongs to the invitee, so one exhausted
    // invitation cannot deny every other invitee in the organization a resend.
    $other = OrganizationInvitation::factory()->recycle($fixture['orgA'])->invitedBy($fixture['ownerA'])
        ->token(OpaqueToken::mint())->create(['email' => 'other-invitee@example.test']);

    $resend($other->id)->assertOk();
});

// ── Route 16: GET /api/v1/organizations/{organization}/members ───────────────────────────────────

it('lists only this organization\'s members, active and suspended', function (): void {
    $fixture = invitationOrgPair();

    $suspended = User::factory()->recycle($fixture['orgA'])->orgRole(OrgRole::Analyst, MembershipStatus::Suspended)
        ->create(['name' => 'Suspended Member ALPHA', 'email' => 'suspended-member@example.test']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/members",
        spaHeaders(),
    );

    // POSITIVE CONTROL: both of org A's members are listed, including the suspended one — an
    // administrator restoring access has to be able to find the person.
    $response->assertOk()->assertJsonCount(2, 'data.members');

    $emails = array_column((array) $response->json('data.members'), 'email');

    expect($emails)->toContain($fixture['ownerA']->email)
        ->and($emails)->toContain('suspended-member@example.test');

    $statuses = array_column((array) $response->json('data.members'), 'status');

    expect($statuses)->toContain(MembershipStatus::Suspended->value);

    // THE ISOLATION ASSERTION.
    $body = (string) $response->getContent();

    expect(str_contains($body, $fixture['ownerB']->email))->toBeFalse(
        'org B\'s member is in org A\'s member list',
    );
    expect(str_contains($body, 'Owner Bravo'))->toBeFalse()
        ->and(str_contains($body, $fixture['ownerB']->id))->toBeFalse();

    // ORG B'S LIST STILL WORKS, which is what makes the above isolation rather than a broken query.
    SpaSession::establish(currentTest(), $fixture['ownerB']);

    currentTest()->getJson("/api/v1/organizations/{$fixture['orgB']->id}/members", spaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data.members')
        ->assertJsonPath('data.members.0.email', $fixture['ownerB']->email);

    expect($suspended->fresh())->not->toBeNull();
});

it('lets only members.view read the member list', function (string $role, int $status): void {
    $fixture = invitationOrgPair();

    $actor = User::factory()->recycle($fixture['orgA'])->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail("reader-{$role}")]);

    SpaSession::establish(currentTest(), $actor);

    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/members", spaHeaders())
        ->assertStatus($status);

    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/invitations", spaHeaders())
        ->assertStatus($status);
})->with([
    'owner' => [OrgRole::Owner->value, 200],
    'admin' => [OrgRole::Admin->value, 200],
    'knowledge_manager' => [OrgRole::KnowledgeManager->value, 403],
    'analyst' => [OrgRole::Analyst->value, 403],
]);

it('403s a suspended member of the organization they are addressing', function (): void {
    $fixture = invitationOrgPair();

    $suspended = User::factory()->recycle($fixture['orgA'])->orgRole(OrgRole::Owner, MembershipStatus::Suspended)
        ->create(['email' => SpaSession::uniqueEmail('suspended-owner')]);

    SpaSession::establish(currentTest(), $suspended);

    // OWNER of the organization, and still refused: `org.member` re-reads the membership row and only
    // `active` confers anything. A suspended owner is the case where a status check written as a
    // role check passes.
    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/members", spaHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

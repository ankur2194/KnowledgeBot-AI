<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Http\Requests\LoginRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Login, /me, logout, organization switch — routes 1, 7, 8, 9
|--------------------------------------------------------------------------
|
| EVERY REQUEST IN THIS FILE CARRIES spaHeaders(). Without the Origin header
| EnsureFrontendRequestsAreStateful::fromFrontend() classifies the request as third-party, the whole
| stateful pipeline including StartSession is skipped, and Auth::login() throws "Session store not
| set on request." as a 500 whose message points at the controller. phpunit.xml's
| SANCTUM_STATEFUL_DOMAINS=localhost is the other half and is necessary but not sufficient.
|
| THE FIXTURES ARE TWO-ORGANIZATION WHEREVER A QUERY IS SCOPED, and org B's data is deliberately
| distinguishable — a different name, a different actor, a different role. A one-organization
| fixture cannot fail an isolation assertion: it passes with every tenant filter deleted.
| TODO(fixtures): fold these into tests/Support/tenancy.php's tenantPair() once the bots and
| knowledge_sources migrations exist and it can be implemented. It throws by design until then.
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
 * Two organizations whose data is told apart by inspection, plus one actor in each.
 *
 * @return array{orgA: Organization, orgB: Organization, actorA: User, actorB: User}
 */
function sessionOrgPair(): array
{
    $orgA = Organization::factory()->create(['name' => 'Session Org A ALPHA', 'slug' => 'session-org-a-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Session Org B BRAVO', 'slug' => 'session-org-b-bravo']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        // THE ADDRESSES ARE UNIQUE PER TEST, not readable literals: `throttle:login` allows five per
        // minute keyed on the submitted address, this fixture is used by most of the file, and every
        // rate-limiter bucket outlives the test that filled it.
        'actorA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Admin)
            ->create(['email' => SpaSession::uniqueEmail('actor-a-alpha')]),
        'actorB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Analyst)
            ->create(['email' => SpaSession::uniqueEmail('actor-b-bravo')]),
    ];
}

/**
 * A membership row created AT a specific instant, so "oldest membership wins" has something to
 * order. Written by hand rather than through UserFactory::orgRole() because that state derives its
 * organization from recycle() and this needs a SECOND organization for an existing user.
 */
function sessionMembershipAt(
    CarbonImmutable $at,
    Organization $organization,
    User $user,
    OrgRole $role,
): OrganizationUser {
    Carbon::setTestNow($at);

    try {
        $membership = new OrganizationUser;
        $membership->organization_id = $organization->id;
        $membership->user_id = $user->id;
        $membership->role = $role;
        $membership->status = MembershipStatus::Active;
        $membership->save();

        return $membership;
    } finally {
        Carbon::setTestNow();
    }
}

// ── Route 1: POST /api/v1/auth/login ────────────────────────────────────────────────────────────

it('establishes a session and answers with the data-wrapped snapshot', function (): void {
    $fixture = sessionOrgPair();

    $response = currentTest()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => $fixture['actorA']->email,
        'password' => UserFactory::PASSWORD,
    ], spaHeaders());

    // D23: the Resource is returned from the action, so Laravel wraps it in `data`. Asserting a bare
    // object here would pass against a controller that returned an array and quietly change the
    // published document.
    $response->assertOk()
        ->assertJsonPath('data.user.id', $fixture['actorA']->id)
        ->assertJsonPath('data.user.email', $fixture['actorA']->email)
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.is_platform_owner', false)
        ->assertJsonPath('data.current_organization_id', $fixture['orgA']->id)
        ->assertJsonPath('data.organizations.0.id', $fixture['orgA']->id)
        ->assertJsonPath('data.organizations.0.role', OrgRole::Admin->value)
        ->assertJsonPath('data.organizations.0.status', MembershipStatus::Active->value)
        ->assertJsonCount(1, 'data.organizations');

    // THE ISOLATION ASSERTION, with the positive control above it: org B exists, has a member, and
    // is distinguishable — and none of it is in org A's actor's snapshot.
    $body = (string) $response->getContent();

    expect(str_contains($body, 'BRAVO'))->toBeFalse(
        'org B leaked into another user\'s session snapshot',
    );
    expect(str_contains($body, $fixture['orgB']->id))->toBeFalse(
        'org B\'s identifier leaked into another user\'s session snapshot',
    );

    // A real session, not a guard-level impersonation.
    expect(SpaSession::idFrom($response))->not->toBe('');

    // The password hash is never on the wire, in any spelling.
    expect(str_contains($body, 'password'))->toBeFalse();
});

it('lowercases the submitted address, so a mixed-case registrant can still log in', function (): void {
    // NOT COSMETIC. users_email_unique is on lower(email) but EloquentUserProvider does an EXACT
    // where('email', ...), so without prepareForValidation()'s normalisation a user who registered
    // as Bob@x.test could never log in — or reset their password — as bob@x.test.
    $email = SpaSession::uniqueEmail('mixed.case');
    $user = User::factory()->create(['email' => $email]);

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => '  '.strtoupper($email).'  ',
        'password' => UserFactory::PASSWORD,
    ], spaHeaders())->assertOk()->assertJsonPath('data.user.id', $user->id);
});

it('rejects bad credentials as 422 validation on the email key and never on password', function (
    string $case,
    bool $userExists,
): void {
    $email = SpaSession::uniqueEmail('known');

    if ($userExists) {
        User::factory()->create(['email' => $email]);
    }

    $response = currentTest()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $userExists ? 'not-the-fixture-password' : 'anything-at-all',
    ], spaHeaders());

    // 422 AND NOT 401, decided during the build: every SPA has a global 401 interceptor that
    // redirects to /login, so a 401 FROM /login is a redirect loop.
    $response->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('errors.email', [LoginRequest::FAILED]);

    // A message on `password` would say "the address exists, the secret is wrong" — the enumeration
    // oracle in one field name.
    expect($response->json('errors.password'))->toBeNull("[{$case}] put a message on the password key");
})->with([
    'wrong password for a real account' => ['wrong password for a real account', true],
    'no such account' => ['no such account', false],
]);

it('succeeds for a user with no active membership, with a null current organization', function (): void {
    // Refusing this login is the classic dead end: POST /auth/email/verification-notification needs
    // auth:sanctum, so a user who cannot log in can never get out of an unverified state either.
    $org = Organization::factory()->create();
    $user = User::factory()->recycle($org)->orgRole(OrgRole::Admin, MembershipStatus::Suspended)->create();

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.current_organization_id', null)
        // The suspended membership is still LISTED, so the SPA can explain the empty console
        // instead of rendering "you belong to nothing".
        ->assertJsonCount(1, 'data.organizations')
        ->assertJsonPath('data.organizations.0.status', MembershipStatus::Suspended->value);
});

it('succeeds for an unverified user, because verification gates tenant data and not identity', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->recycle($org)->orgRole(OrgRole::Admin)
        ->create(['email_verified_at' => null]);

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', false);
});

it('rejects a malformed body as validation without touching the guard', function (array $payload, string $field): void {
    currentTest()->postJson('/api/v1/auth/login', $payload, spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors([$field]);
})->with([
    'no email' => [['password' => 'x'], 'email'],
    'no password' => [['email' => 'a@example.test'], 'password'],
    'not an address' => [['email' => 'not-an-address', 'password' => 'x'], 'email'],
]);

it('picks the oldest membership deterministically when a user belongs to two organizations', function (): void {
    // An unordered ->first() is the bug this pins: PostgreSQL may return a different row per plan,
    // so the same user would land in a different organization on alternate logins.
    $first = Organization::factory()->create(['name' => 'Older Org ALPHA']);
    $second = Organization::factory()->create(['name' => 'Newer Org BRAVO']);

    $user = User::factory()->recycle($first)->orgRole(OrgRole::Admin)->create();

    // Owner in the NEWER organization, so a role-precedence rule (rejected during the build as a UX
    // guess that cannot be reproduced from the schema) would pick the wrong one and fail here.
    sessionMembershipAt(CarbonImmutable::now()->addMinute(), $second, $user, OrgRole::Owner);

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.current_organization_id', $first->id)
        ->assertJsonPath('data.organizations.0.id', $first->id)
        ->assertJsonPath('data.organizations.1.id', $second->id);
});

it('records one audit row for a login and one for a failure, with the address echoed', function (): void {
    $email = SpaSession::uniqueEmail('audited');
    $user = User::factory()->create(['email' => $email]);

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'wrong-password-entirely',
    ], spaHeaders())->assertStatus(422);

    currentTest()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => UserFactory::PASSWORD,
    ], spaHeaders())->assertOk();

    $operations = AuditLog::query()->orderBy('created_at')->orderBy('id')->pluck('operation')->all();

    expect($operations)->toContain(AuditLogger::LOGIN_FAILED)
        ->and($operations)->toContain(AuditLogger::LOGIN_SUCCEEDED);

    /** @var AuditLog $failure */
    $failure = AuditLog::query()->where('operation', AuditLogger::LOGIN_FAILED)->firstOrFail();

    expect($failure->actor_id)->toBeNull('a failed login has no proven actor to attribute it to')
        ->and($failure->outcome)->toBe(AuditLogger::OUTCOME_FAILURE)
        ->and($failure->details['email'] ?? null)->toBe($email);

    /** @var AuditLog $success */
    $success = AuditLog::query()->where('operation', AuditLogger::LOGIN_SUCCEEDED)->firstOrFail();

    expect($success->actor_id)->toBe($user->id);

    // The submitted password reaches no audit row, under any key.
    $raw = (string) AuditLog::query()->get()->toJson();

    expect(str_contains($raw, 'wrong-password-entirely'))->toBeFalse()
        ->and(str_contains($raw, UserFactory::PASSWORD))->toBeFalse();
});

// ── Route 7: GET /api/v1/me ─────────────────────────────────────────────────────────────────────

it('answers /me from a carried session and repairs a revoked current organization', function (): void {
    $fixture = sessionOrgPair();
    $actor = $fixture['actorA'];

    // A second, older membership so there is something to repair TO. Without it the repair is
    // indistinguishable from "the value was already null".
    $other = Organization::factory()->create(['name' => 'Fallback Org CHARLIE']);

    sessionMembershipAt(CarbonImmutable::now()->subHour(), $other, $actor, OrgRole::Analyst);

    SpaSession::establish(currentTest(), $actor);

    // POSITIVE CONTROL: /me answers, and it answers with the org A the session was pointed at.
    currentTest()->postJson('/api/v1/session/organization', [
        'organization_id' => $fixture['orgA']->id,
    ], spaHeaders())->assertOk()->assertJsonPath('data.current_organization_id', $fixture['orgA']->id);

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.current_organization_id', $fixture['orgA']->id);

    // Revoke org A's membership. No cache flush, no restart, no re-login.
    $membership = $actor->membershipFor($fixture['orgA']->id);
    expect($membership)->toBeInstanceOf(OrganizationUser::class);
    assert($membership instanceof OrganizationUser);
    $membership->status = MembershipStatus::Suspended;
    $membership->save();

    SpaSession::freshProcess();

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        // REPAIRED to the oldest remaining ACTIVE membership rather than left pointing at a
        // membership that confers nothing.
        ->assertJsonPath('data.current_organization_id', $other->id);
});

it('403s an org-scoped route on the very next request after a membership is revoked', function (): void {
    // NO CACHE FLUSH, NO RESTART, NO RE-LOGIN. `org.member` (App\Http\Middleware\TenantContext)
    // re-reads organization_users from PostgreSQL on every request, which is the whole reason the
    // session's current_organization_id is allowed to be a UI preference rather than an
    // authorization input.
    $fixture = sessionOrgPair();
    $actor = $fixture['actorA'];

    SpaSession::establish(currentTest(), $actor);

    // POSITIVE CONTROL: the route works while the membership stands. Without this the test passes
    // against a route that 403s for everyone, always.
    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/members", spaHeaders())
        ->assertOk();

    $membership = $actor->membershipFor($fixture['orgA']->id);
    expect($membership)->toBeInstanceOf(OrganizationUser::class);
    assert($membership instanceof OrganizationUser);
    $membership->status = MembershipStatus::Suspended;
    $membership->save();

    SpaSession::freshProcess();

    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/members", spaHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // And the session is still a valid IDENTITY: revocation of a membership is not revocation of a
    // credential, so /me must keep answering — it is the SPA's only channel for explaining the 403.
    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.current_organization_id', null);
});

it('401s /me with no session at all', function (): void {
    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');
});

it('never shows one user another user\'s memberships through /me', function (): void {
    $fixture = sessionOrgPair();

    SpaSession::establish(currentTest(), $fixture['actorB']);

    $response = currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    // POSITIVE CONTROL FIRST: actor B really does see org B, so the negative below is not passing
    // because the endpoint returns nothing.
    $response->assertJsonPath('data.organizations.0.id', $fixture['orgB']->id);

    $body = (string) $response->getContent();

    expect(str_contains($body, 'ALPHA'))->toBeFalse('org A leaked into org B\'s member session')
        ->and(str_contains($body, $fixture['orgA']->id))->toBeFalse()
        ->and(str_contains($body, $fixture['actorA']->email))->toBeFalse();
});

// ── Route 9: POST /api/v1/session/organization ──────────────────────────────────────────────────

it('refuses to store an organization the caller is not an active member of', function (
    string $case,
    bool $sameOrg,
    ?MembershipStatus $status,
): void {
    $fixture = sessionOrgPair();

    SpaSession::establish(currentTest(), $fixture['actorA']);

    $target = $sameOrg ? $fixture['orgA']->id : $fixture['orgB']->id;

    if ($status !== null) {
        $membership = $fixture['actorA']->membershipFor($fixture['orgA']->id);
        expect($membership)->toBeInstanceOf(OrganizationUser::class);
        assert($membership instanceof OrganizationUser);
        $membership->status = $status;
        $membership->save();
    }

    // 403 rather than 404, and a nonexistent organization gets the SAME 403 — so this is neither an
    // org-existence oracle nor a break in the admin surface's deliberate 403 split.
    currentTest()->postJson('/api/v1/session/organization', ['organization_id' => $target], spaHeaders())
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
})->with([
    'another organization the caller is not in' => ['another organization', false, null],
    'own organization after suspension' => ['own organization suspended', true, MembershipStatus::Suspended],
]);

it('gives a nonexistent organization the same 403 as one the caller is not in', function (): void {
    $fixture = sessionOrgPair();

    SpaSession::establish(currentTest(), $fixture['actorA']);

    $absent = currentTest()->postJson('/api/v1/session/organization', [
        'organization_id' => (string) Str::ulid(),
    ], spaHeaders(['X-KB-Request-Id' => 'session-switch-probe-0001']));

    $foreign = currentTest()->postJson('/api/v1/session/organization', [
        'organization_id' => $fixture['orgB']->id,
    ], spaHeaders(['X-KB-Request-Id' => 'session-switch-probe-0001']));

    $absent->assertStatus(403);
    $foreign->assertStatus(403);

    expect((string) $absent->getContent())->toBe(
        (string) $foreign->getContent(),
        'a nonexistent organization id is distinguishable from one the caller is merely not a member '
        .'of, which makes this endpoint a global organization-existence oracle',
    );
});

it('rejects a malformed organization id as validation, with no existence query', function (): void {
    $user = User::factory()->create();

    SpaSession::establish(currentTest(), $user);

    currentTest()->postJson('/api/v1/session/organization', ['organization_id' => 'not-a-ulid'], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['organization_id']);
});

it('401s the organization switcher with no session', function (): void {
    currentTest()->postJson('/api/v1/session/organization', [
        'organization_id' => (string) Str::ulid(),
    ], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');
});

// ── Route 8: POST /api/v1/auth/logout ───────────────────────────────────────────────────────────

it('invalidates the session on logout, so the very next request is unauthenticated', function (): void {
    $fixture = sessionOrgPair();

    $sessionId = SpaSession::establish(currentTest(), $fixture['actorA']);

    currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    $logout = currentTest()->postJson('/api/v1/auth/logout', [], spaHeaders());

    $logout->assertOk()->assertExactJson(['data' => ['acknowledged' => true]]);

    // invalidate() = flush() + migrate(true): the old id is DESTROYED in the handler and a new one is
    // issued. The new cookie value is the observable half of that.
    expect(SpaSession::idFrom($logout))->not->toBe($sessionId);

    SpaSession::freshProcess();

    // The SAME (old) cookie, re-sent — which is what a browser that missed the Set-Cookie would do.
    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    expect(Auth::guard('web')->check())->toBeFalse();

    /** @var AuditLog $row */
    $row = AuditLog::query()->where('operation', AuditLogger::LOGOUT)->firstOrFail();

    expect($row->actor_id)->toBe($fixture['actorA']->id)
        ->and($row->organization_id)->toBe($fixture['orgA']->id)
        ->and($row->details)->toBe([]);
});

it('401s logout with no session, before the controller runs', function (): void {
    currentTest()->postJson('/api/v1/auth/logout', [], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    expect(AuditLog::query()->where('operation', AuditLogger::LOGOUT)->count())->toBe(0);
});

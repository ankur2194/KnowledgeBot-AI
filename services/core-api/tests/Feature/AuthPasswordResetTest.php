<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Mailbox;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Password reset — routes 2 and 3
|--------------------------------------------------------------------------
|
| The account-enumeration property of these two endpoints is asserted in
| tests/Security/AccountEnumerationTest.php, which is where the byte comparisons live. This file
| asserts that the flow WORKS — which is the other half, and the half whose absence would let the
| enumeration suite pass against an endpoint that does nothing at all for everybody equally.
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
 * A user who can log in, plus one organization so the session snapshot is not degenerate.
 *
 * @return array{organization: Organization, email: string, user: User}
 */
function passwordResetFixture(): array
{
    $organization = Organization::factory()->create(['name' => 'Reset Org ALPHA']);

    // A UNIQUE ADDRESS PER TEST, NOT A LITERAL. `throttle:password-request` allows three requests per
    // FIFTEEN MINUTES keyed on the SUBMITTED address, and RefreshDatabase rolls back the database while
    // leaving every rate-limiter bucket in Valkey — so a shared literal would 429 the fourth test in
    // this file and then every re-run of the suite inside the window. That is the "fails only in CI"
    // flake pest-testing names, and loosening the assertion is the wrong repair.
    $email = SpaSession::uniqueEmail('reset-subject');

    return [
        'organization' => $organization,
        'email' => $email,
        'user' => User::factory()->recycle($organization)->orgRole(OrgRole::Admin)
            ->create(['email' => $email]),
    ];
}

// ── Route 2: POST /api/v1/auth/forgot-password ──────────────────────────────────────────────────

it('mails a working reset link and acknowledges in the data envelope', function (): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $fixture['email'],
    ], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect(Mailbox::count())->toBe(1)
        ->and(Mailbox::latestRecipients())->toBe([$fixture['email']]);

    // The row exists, and it holds a HASH rather than the token: DatabaseTokenRepository bcrypts the
    // payload, so the plaintext in the mail must not be findable in the table.
    $stored = Schema::getConnection()->table('password_reset_tokens')
        ->where('email', $fixture['email'])->value('token');

    expect($stored)->toBeString();

    $token = Mailbox::latestToken();

    expect(str_contains((string) $stored, $token))->toBeFalse(
        'password_reset_tokens.token holds the plaintext token rather than a hash of it, so a read '
        .'of that table is a read of every live reset capability',
    );

    // AND THE EMAILED TOKEN IS THE ONE THE ENDPOINT ACCEPTS. This is the assertion that a
    // mint-your-own-token test cannot make, and the one that catches a URL builder or a query-shape
    // change.
    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $fixture['email'],
        'password' => 'Replacement-Secret-9',
        'password_confirmation' => 'Replacement-Secret-9',
    ], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect(Hash::check('Replacement-Secret-9', (string) $fixture['user']->fresh()?->getAuthPassword()))->toBeTrue();
});

it('normalises the submitted address, so a mixed-case account can still be reset', function (): void {
    $email = SpaSession::uniqueEmail('reset-mixed');

    User::factory()->create(['email' => $email]);

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => strtoupper($email),
    ], spaHeaders())->assertOk();

    // The oracle in reverse: without prepareForValidation() the lookup misses and the user gets the
    // same 200 with no mail, forever, with nothing in any log to say why.
    expect(Mailbox::count())->toBe(1);
});

it('rejects a malformed address as validation and mails nothing', function (): void {
    currentTest()->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-address'], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['email']);

    expect(Mailbox::count())->toBe(0);
});

it('429s with Retry-After once the per-account budget is spent', function (): void {
    $flooded = SpaSession::uniqueEmail('reset-flooded');
    $bystander = SpaSession::uniqueEmail('reset-bystander');

    User::factory()->create(['email' => $flooded]);

    // The limiter is 3 per 15 minutes on `acct:` and 10 per minute on `ip:`, so the ACCOUNT axis is
    // what fires first — which is the axis that matters, because this endpoint mails somebody else's
    // mailbox and the flood is aimed at them rather than at us.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        currentTest()->postJson('/api/v1/auth/forgot-password', [
            'email' => $flooded,
        ], spaHeaders())->assertOk();
    }

    $throttled = currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $flooded,
    ], spaHeaders());

    $throttled->assertStatus(429)
        ->assertJsonPath('error_class', 'rate_limit')
        ->assertHeader('Retry-After');

    // A different address is unaffected: the budget is per account, not global, or one prober denies
    // every user in the system a password reset.
    User::factory()->create(['email' => $bystander]);

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $bystander,
    ], spaHeaders())->assertOk();
});

it('records the reset request as an audit row with the token fingerprinted, never echoed', function (): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $fixture['email'],
    ], spaHeaders())->assertOk();

    $token = Mailbox::latestToken();

    /** @var AuditLog $row */
    $row = AuditLog::query()->where('operation', AuditLogger::PASSWORD_RESET_REQUESTED)->firstOrFail();

    expect($row->details)->toHaveKey('token_fingerprint')
        ->and($row->details)->not->toHaveKey('token')
        ->and($row->details['email'] ?? null)->toBe($fixture['email']);

    // The plaintext capability reaches no audit row, in any column.
    $raw = Schema::getConnection()->select('SELECT audit_logs::text AS value FROM audit_logs');
    $serialised = json_encode($raw);

    expect(str_contains((string) $serialised, $token))->toBeFalse(
        'the plaintext reset token is in audit_logs, which is append-only — so it cannot be removed',
    );
});

// ── Route 3: POST /api/v1/auth/reset-password ───────────────────────────────────────────────────

it('refuses an invalid, expired or consumed token with one 422 on the token key', function (
    string $case,
    string $mutate,
): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $fixture['email'],
    ], spaHeaders())->assertOk();

    $token = Mailbox::latestToken();

    if ($mutate === 'expire') {
        // config/auth.php's `expire` is 60 MINUTES. Ageing the row is the only way to reach the
        // tokenExpired() branch without waiting an hour, and it is a row this suite owns.
        Schema::getConnection()->table('password_reset_tokens')
            ->where('email', $fixture['email'])
            ->update(['created_at' => now()->subMinutes(61)]);
    }

    if ($mutate === 'consume') {
        currentTest()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $fixture['email'],
            'password' => 'First-Replacement-9',
            'password_confirmation' => 'First-Replacement-9',
        ], spaHeaders())->assertOk();
    }

    // A FRESH random token rather than a repeated literal: `throttle:password-reset` keys on
    // sha256(token) at five per minute, so a shared literal accumulates across datasets, across the
    // file and across runs of the suite against any cache that outlives one test.
    $submitted = $mutate === 'garbage' ? OpaqueToken::mint() : $token;

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $submitted,
        'email' => $fixture['email'],
        'password' => 'Second-Replacement-9',
        'password_confirmation' => 'Second-Replacement-9',
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('errors.token', [ResetPasswordRequest::INVALID]);

    // The second password never took effect.
    expect(Hash::check('Second-Replacement-9', (string) $fixture['user']->fresh()?->getAuthPassword()))->toBeFalse(
        "[{$case}] changed the password anyway",
    );
})->with([
    'a token that never existed' => ['garbage token', 'garbage'],
    'a token past its 60-minute expiry' => ['expired token', 'expire'],
    'a token already used once' => ['consumed token', 'consume'],
]);

it('enforces the password policy as explicit rules on the password key', function (
    string $case,
    string $password,
): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => OpaqueToken::mint(),
        'email' => $fixture['email'],
        'password' => $password,
        'password_confirmation' => $password,
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        // ON `password`, and the ordering matters: the policy is checked by the FormRequest BEFORE
        // the token is resolved, so a weak password never reaches the broker and never consumes a
        // guess against the token's rate-limit budget.
        ->assertJsonValidationErrors(['password'])
        ->assertJsonMissingPath('errors.token');
})->with([
    'shorter than twelve characters' => ['too short', 'Short-1a'],
    'no upper case' => ['no upper case', 'nouppercase-1'],
    'no lower case' => ['no lower case', 'NOLOWERCASE-1'],
    'no digit' => ['no digit', 'NoDigitsHereAtAll'],
]);

it('rejects an unconfirmed password without reaching the token', function (): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => OpaqueToken::mint(),
        'email' => $fixture['email'],
        'password' => 'Confirmed-Secret-9',
        'password_confirmation' => 'A-Different-Secret-9',
    ], spaHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('kills every other session for that user on its next request, as 401 and not 419', function (): void {
    $fixture = passwordResetFixture();
    $user = $fixture['user'];

    // TWO REAL SESSIONS FOR ONE USER — the whole point. A single session cannot fail this test: the
    // reset endpoint establishes no session of its own, so with one session there is nothing to kill
    // and the assertion passes against an application with AuthenticateSession deleted.
    $sibling = SpaSession::establish(currentTest(), $user);

    // The sibling has to make one authenticated request before it holds a password hash:
    // AuthenticateSession early-returns when there is no user, so the LOGIN request itself does not
    // seed `password_hash_web`; the NEXT request does. That one-request window is a real (and
    // harmless) gap and this line is what makes the test exercise the state after it closes.
    currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ], spaHeaders())->assertOk();

    $token = Mailbox::latestToken();

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Rotated-Secret-9',
        'password_confirmation' => 'Rotated-Secret-9',
    ], spaHeaders())->assertOk();

    SpaSession::resume(currentTest(), $sibling);

    // 401 `authentication`, NOT 419. Sanctum's AuthenticateSession compares a password hash per
    // request and throws AuthenticationException, which bootstrap/app.php renders as 401 — the SPA
    // never sees a 419 on this path, and a test asserting 419 here would be asserting the framework
    // default rather than this application's envelope.
    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    // The reset itself established NO session: logging the user in would mean invalidating the
    // session we had just created, one middleware later.
    expect(Hash::check('Rotated-Secret-9', (string) $user->fresh()?->getAuthPassword()))->toBeTrue()
        ->and(Hash::check(UserFactory::PASSWORD, (string) $user->fresh()?->getAuthPassword()))->toBeFalse();

    /** @var AuditLog $row */
    $row = AuditLog::query()->where('operation', AuditLogger::PASSWORD_RESET_COMPLETED)->firstOrFail();

    expect($row->actor_id)->toBe($user->id)
        ->and($row->details)->toHaveKey('token_fingerprint')
        ->and($row->details)->not->toHaveKey('token');
});

it('deletes the reset row on success, so the link cannot be replayed', function (): void {
    $fixture = passwordResetFixture();

    currentTest()->postJson('/api/v1/auth/forgot-password', [
        'email' => $fixture['email'],
    ], spaHeaders())->assertOk();

    $token = Mailbox::latestToken();

    currentTest()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $fixture['email'],
        'password' => 'Replayable-Secret-9',
        'password_confirmation' => 'Replayable-Secret-9',
    ], spaHeaders())->assertOk();

    expect(
        Schema::getConnection()->table('password_reset_tokens')
            ->where('email', $fixture['email'])->count()
    )->toBe(0, 'the reset row survived a successful reset, so the emailed link is replayable');
});

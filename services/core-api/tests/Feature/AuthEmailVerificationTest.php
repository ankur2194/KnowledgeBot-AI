<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\EmailVerificationToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Mailbox;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Email verification — routes 6 and 10, plus the `verified` gate
|--------------------------------------------------------------------------
|
| VERIFICATION IS ONLY REAL WHERE IT IS APPLIED, AND IT IS APPLIED IN ONE PLACE:
| routes/api_admin.php's org-scoped group. Login deliberately succeeds for an unverified user, so a
| test that only checked "login works" would pass against an application where `verified` had been
| deleted from that group and email verification had become decoration. The gate test below is
| therefore the load-bearing one in this file.
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
 * @return array{organization: Organization, email: string, user: User}
 */
function verificationFixture(bool $verified = false): array
{
    $organization = Organization::factory()->create(['name' => 'Verification Org ALPHA']);

    // A UNIQUE ADDRESS PER TEST. `throttle:login` allows five per minute keyed on the SUBMITTED
    // address and every test in this file logs in for real, so a shared literal 429s the sixth test
    // against any cache that outlives one test — which phpunit.xml's Valkey store does.
    $email = SpaSession::uniqueEmail('verify-subject');

    return [
        'organization' => $organization,
        'email' => $email,
        'user' => User::factory()->recycle($organization)->orgRole(OrgRole::Admin)->create([
            'email' => $email,
            'email_verified_at' => $verified ? CarbonImmutable::now() : null,
        ]),
    ];
}

// ── Route 10: POST /api/v1/auth/email/verification-notification ─────────────────────────────────

it('mails a verification link to the authenticated user and nobody else', function (): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    // THE ADDRESS IS NOT A PARAMETER, and the mailbox is the proof: an endpoint that took one would
    // be an authenticated mail relay pointable at anybody.
    expect(Mailbox::count())->toBe(1)
        ->and(Mailbox::latestRecipients())->toBe([$fixture['email']]);

    // One live token per user, and it holds a digest rather than the token.
    /** @var EmailVerificationToken $row */
    $row = EmailVerificationToken::query()->firstOrFail();

    expect($row->user_id)->toBe($fixture['user']->id)
        ->and($row->email)->toBe($fixture['email'])
        ->and($row->consumed_at)->toBeNull()
        ->and($row->token_hash)->toBe(OpaqueToken::digest(Mailbox::latestToken()));
});

it('acknowledges identically for an already-verified user, and mails nothing', function (): void {
    $fixture = verificationFixture(verified: true);

    SpaSession::establish(currentTest(), $fixture['user']);

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect(Mailbox::count())->toBe(0)
        ->and(EmailVerificationToken::query()->count())->toBe(0);
});

it('kills the previous link when a new one is requested', function (): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $first = Mailbox::latestToken();

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $second = Mailbox::latestToken();

    expect($second)->not->toBe($first);

    // One live token per user is a PARTIAL UNIQUE INDEX, so the resend has to DELETE then INSERT.
    expect(EmailVerificationToken::query()->whereNull('consumed_at')->count())->toBe(1);

    // POSITIVE CONTROL FIRST: the new link works.
    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $second], spaHeaders())->assertOk();

    expect($fixture['user']->fresh()?->hasVerifiedEmail())->toBeTrue();

    // The old link is dead — asserted on a user whose verification has now happened, so this goes
    // through EmailVerificationService's already-verified branch and returns 200. That is the
    // idempotent path and it is correct; the assertion that matters is that the row is gone.
    expect(
        EmailVerificationToken::query()
            ->where('token_hash', '\x'.bin2hex(OpaqueToken::digest($first)))
            ->count()
    )->toBe(0, 'the superseded verification token row survived the resend, so two live capabilities '
        .'exist for one authority');
});

it('401s the resend endpoint with no session', function (): void {
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    expect(Mailbox::count())->toBe(0);
});

it('429s the resend endpoint after six mails in an hour', function (): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())
            ->assertOk();
    }

    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())
        ->assertStatus(429)
        ->assertJsonPath('error_class', 'rate_limit')
        ->assertHeader('Retry-After');

    expect(Mailbox::count())->toBe(6, 'the throttled request still queued a mail');
});

// ── Route 6: POST /api/v1/auth/email/verify ─────────────────────────────────────────────────────

it('verifies the address from a guest request, because the link opens in another browser', function (): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $token = Mailbox::latestToken();

    // ANOTHER BROWSER, which is the situation this endpoint exists for. Pointing the client at a
    // session id nothing ever wrote is how the suite expresses "no valid session"; the test client
    // has no way to un-send a cookie it was told to send, and simply keeping the authenticated
    // session would leave the guest-reachability property unasserted.
    SpaSession::resume(currentTest(), Str::random(40));

    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $token], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect($fixture['user']->fresh()?->hasVerifiedEmail())->toBeTrue();

    /** @var EmailVerificationToken $row */
    $row = EmailVerificationToken::query()->firstOrFail();

    expect($row->consumed_at)->not->toBeNull();

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('operation', AuditLogger::EMAIL_VERIFIED)->firstOrFail();

    expect($audit->actor_id)->toBe($fixture['user']->id)
        ->and($audit->subject_id)->toBe($fixture['user']->id)
        ->and($audit->details)->toHaveKey('token_fingerprint')
        ->and($audit->details)->not->toHaveKey('token');

    $raw = json_encode(Schema::getConnection()->select('SELECT audit_logs::text AS value FROM audit_logs'));

    expect(str_contains((string) $raw, $token))->toBeFalse(
        'the plaintext verification token is in audit_logs, which is append-only',
    );
});

it('is idempotent for an already-verified user, so a link preview is not an error page', function (): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $token = Mailbox::latestToken();

    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $token], spaHeaders())->assertOk();

    // The second click — a mail client's link preview followed by the human's own click.
    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $token], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    // And exactly one audit row: the second call took the already-verified branch and wrote nothing.
    expect(AuditLog::query()->where('operation', AuditLogger::EMAIL_VERIFIED)->count())->toBe(1);
});

it('404s a token whose issued address no longer matches the user', function (): void {
    // The reason email_verification_tokens carries its OWN `email` column. A token minted before an
    // address change must not verify the new address, and comparing the two at consume time is that
    // check. Without the column there is nothing to compare and the token verifies whatever the row
    // now says.
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $token = Mailbox::latestToken();

    $fixture['user']->forceFill(['email' => SpaSession::uniqueEmail('verify-moved')])->save();

    SpaSession::freshProcess();

    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $token], spaHeaders())
        ->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization');

    expect($fixture['user']->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('404s an unknown, expired or consumed verification token with one body', function (
    string $case,
    string $mutate,
): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();
    $token = Mailbox::latestToken();

    if ($mutate === 'expire') {
        EmailVerificationToken::query()->update(['expires_at' => CarbonImmutable::now()->subHour()]);
    }

    if ($mutate === 'consume') {
        // Consumed but the USER still unverified — the combination that reaches the isConsumed()
        // branch rather than the already-verified short circuit above it.
        EmailVerificationToken::query()->update(['consumed_at' => CarbonImmutable::now()]);
    }

    $submitted = $mutate === 'unknown' ? OpaqueToken::mint() : $token;

    currentTest()->postJson('/api/v1/auth/email/verify', ['token' => $submitted], spaHeaders())
        ->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization')
        ->assertJsonPath('message', 'The requested resource was not found.');

    expect($fixture['user']->fresh()?->hasVerifiedEmail())->toBeFalse("[{$case}] verified the address anyway");
})->with([
    'a token that never existed' => ['unknown token', 'unknown'],
    'a token past its expiry' => ['expired token', 'expire'],
    'a token already consumed' => ['consumed token', 'consume'],
]);

it('rejects a malformed verification token as validation', function (): void {
    // A FRESH SHORT TOKEN PER RUN, NOT THE LITERAL 'too-short' THIS USED TO POST. The `verification`
    // limiter's second axis is `hash('sha256', $request->input('token'))` at 6 per SIXTY MINUTES,
    // and isolateRateLimits() moves only the `ip:` axis — so a fixed literal spent one unit of one
    // permanent bucket per run and the seventh run inside an hour got a 429 where this asserts a
    // 422. Measured; see Tests\Support\SpaSession::uniqueToken(). The length is what makes the
    // value malformed (`size:OpaqueToken::LENGTH` is 64), so it is the half that must not vary.
    currentTest()->postJson(
        '/api/v1/auth/email/verify',
        ['token' => SpaSession::uniqueToken(9)],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['token']);
});

// ── The `verified` gate on routes/api_admin.php ─────────────────────────────────────────────────

it('403s every org-scoped route for an unverified member, whatever the Accept header says', function (
    string $accept,
): void {
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);

    // App\Http\Middleware\EnsureEmailIsVerified (ours, D25) throws AuthorizationException and has NO
    // redirect branch — Laravel's own middleware would 302 to a named route for a non-JSON request,
    // and there is no such route here, so the framework's version would either redirect into nothing
    // or throw RouteNotFoundException as a 500.
    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['organization']->id}/members",
        spaHeaders(['Accept' => $accept]),
    );

    expect($response->isRedirect())->toBeFalse(
        "[{$accept}] produced a redirect: a JSON API that 302s an unverified user has no way to tell "
        .'the SPA what happened, and the redirect target does not exist',
    );

    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');
})->with([
    'json' => 'application/json',
    'html' => 'text/html',
    'anything' => '*/*',
]);

it('lets the same member through the moment the address is verified', function (): void {
    // THE POSITIVE CONTROL FOR THE GATE, and without it the test above passes against a route that
    // 403s everybody — which is what a mis-ordered middleware stack produces.
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);
    currentTest()->postJson('/api/v1/auth/email/verification-notification', [], spaHeaders())->assertOk();

    currentTest()->getJson("/api/v1/organizations/{$fixture['organization']->id}/members", spaHeaders())
        ->assertStatus(403);

    currentTest()->postJson(
        '/api/v1/auth/email/verify',
        ['token' => Mailbox::latestToken()],
        spaHeaders(),
    )->assertOk();

    SpaSession::freshProcess();

    currentTest()->getJson("/api/v1/organizations/{$fixture['organization']->id}/members", spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.members.0.email', $fixture['email']);
});

it('keeps /me readable for an unverified user, because it is the only explanation channel', function (): void {
    // The envelope rewrites every `authorization` message to one constant, so the 403 above cannot
    // say "unverified". `user.email_verified` is the SPA's only way to explain it — which is exactly
    // why `verified` is NOT applied to /me or to the resend route.
    $fixture = verificationFixture();

    SpaSession::establish(currentTest(), $fixture['user']);

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', false);
});

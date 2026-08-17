<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| CSRF, and the 419 the SPA must never see
|--------------------------------------------------------------------------
|
| CSRF IS A NO-OP IN THIS SUITE, AND PRETENDING OTHERWISE IS THE TRAP.
| `PreventRequestForgery::handle()` short-circuits on `runningUnitTests()`, so the middleware never
| looks at the token: a test that "asserts CSRF works" by posting a wrong X-XSRF-TOKEN asserts nothing
| and is unconditionally green. The first test below demonstrates exactly that, on purpose, so nobody
| writes the useless version — and everything else in this file is either a REFLECTION assertion over
| the configuration or a probe route that throws the exception the harness cannot provoke.
|
| WHY THAT IS WORTH TESTING AT ALL. The two "simplifications" a future reader reaches for —
| `PreventRequestForgery::useOriginOnly()` and `::allowSameSite()` — are STATIC and global, they are one
| line in a service provider, and their consequences are invisible locally: origin-only 403s every
| admin mutation from a client that does not send Origin, and allowSameSite re-opens CSRF to every
| subdomain of the registrable domain, chat.<domain> included. Neither shows up in a functional test.
| A red build is the only affordable feedback.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite. `login` is 20/minute per IP and every request
    // here arrives from one address, so without this a suite that logs in for real is a 429 storm that
    // only appears in CI. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());
});

it('cannot observe a token mismatch by sending a bad token, which is why the rest of this file exists', function (): void {
    $user = User::factory()->create();

    // A DELIBERATELY WRONG TOKEN on a real mutation, and it succeeds. This is not a finding: it is
    // PreventRequestForgery::handle()'s `runningUnitTests()` short circuit, and the assertion is here
    // so that the next person who writes `->assertStatus(419)` on this shape finds out why it is
    // green rather than concluding CSRF is broken in production.
    currentTest()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => \Database\Factories\UserFactory::PASSWORD,
    ], spaHeaders(['X-XSRF-TOKEN' => 'this-is-not-a-valid-csrf-token']))
        ->assertOk();

    // The mechanism, named rather than inferred.
    expect((new \ReflectionMethod(PreventRequestForgery::class, 'runningUnitTests'))->isProtected())
        ->toBeTrue();
});

it('keeps both CSRF relaxations off, by reflection over the static state', function (): void {
    // Static properties, so this is a global switch and not a per-instance setting: one call to
    // useOriginOnly() or allowSameSite() anywhere in a boot path changes it for the whole process.
    $originOnly = new \ReflectionProperty(PreventRequestForgery::class, 'originOnly');
    $allowSameSite = new \ReflectionProperty(PreventRequestForgery::class, 'allowSameSite');

    expect($originOnly->isStatic())->toBeTrue()
        ->and($allowSameSite->isStatic())->toBeTrue();

    expect($originOnly->getValue())->toBeFalse(
        'PreventRequestForgery::$originOnly is true. Every admin mutation from a client that does not '
        .'send an Origin header now 403s, and the failure is a blanket 403 storm in production that no '
        .'functional test reproduces — the test client and curl both send nothing.',
    );

    expect($allowSameSite->getValue())->toBeFalse(
        'PreventRequestForgery::$allowSameSite is true. A Sec-Fetch-Site: same-site request is now '
        .'accepted without a token, which means EVERY subdomain of the registrable domain can forge an '
        .'admin mutation — chat.<domain> is a public surface on that domain, so this is the same hazard '
        .'tests/Security/SessionCookieScopeTest.php closes for the cookie, re-opened for the request.',
    );
});

it('routes CSRF validation through the class the stateful pipeline expects', function (): void {
    // If this is swapped for a custom class, or for `null`, the stateful pipeline silently stops
    // validating tokens: EnsureFrontendRequestsAreStateful reads these three config keys and puts
    // whatever it finds into the pipeline. A null here is CSRF off with no error anywhere.
    expect(config('sanctum.middleware.validate_csrf_token'))->toBe(PreventRequestForgery::class);
    expect(config('sanctum.middleware.encrypt_cookies'))->toBe(EncryptCookies::class);

    // The third one is what makes tests/Feature/AuthPasswordResetTest.php's sibling-logout property
    // exist at all: it is the ONLY mechanism by which a stolen session dies on a password change, and
    // there is no session index to replace it with.
    expect(config('sanctum.middleware.authenticate_session'))->toBe(AuthenticateSession::class);
});

it('renders a token mismatch as 401 authentication, never as a 419 the SPA cannot parse', function (): void {
    // THE PROBE-ROUTE TECHNIQUE, because the harness cannot provoke the real thing (see the header).
    // The exception TYPE is the load-bearing part: bootstrap/app.php maps TokenMismatchException, and
    // nothing else in the closure produces a 419.
    Route::middleware(['api', 'surface:admin'])
        ->post('api/v1/_probe/csrf', static function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

    $response = currentTest()->postJson('api/v1/_probe/csrf', [], spaHeaders());

    $response->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication')
        ->assertJsonPath('retryable', false);

    expect($response->status())->not->toBe(
        419,
        'a 419 reached the client. The envelope has four keys and the SPA branches on error_class '
        .'alone, so a status nothing in the taxonomy names renders as "Something went wrong."',
    );

    // AND THE COST OF THAT MAPPING, WRITTEN DOWN: an expired CSRF token and an expired SESSION are now
    // indistinguishable from the envelope. That is why the SPA contract is "on a 401 from a mutation,
    // re-fetch /sanctum/csrf-cookie, then GET /me — if /me 200s it was CSRF and the mutation may be
    // retried once; if /me 401s the session is gone". The GET is in the middle because retrying a
    // non-idempotent POST blind is unsafe.
    $session = currentTest()->getJson('/api/v1/me', spaHeaders());

    expect($session->json('error_class'))->toBe(
        'authentication',
        'the /me probe in the SPA\'s recovery contract must answer with the same error_class as the '
        .'mutation did when the session really is gone, or the branch cannot be written',
    );
});

it('lets the SPA distinguish the two 401s with the /me probe the contract names', function (): void {
    // The other arm of that contract, and the one that makes it useful: with a LIVE session, /me 200s,
    // so the SPA knows the 401 it got was CSRF rather than expiry.
    $user = User::factory()->create();

    SpaSession::establish(currentTest(), $user);

    Route::middleware(['api', 'auth:sanctum', 'surface:admin'])
        ->post('api/v1/_probe/csrf-live', static function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

    currentTest()->postJson('api/v1/_probe/csrf-live', [], spaHeaders())
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('issues a fresh CSRF cookie from the endpoint the SPA primes with', function (): void {
    $response = currentTest()->getJson('/sanctum/csrf-cookie', spaHeaders());

    $response->assertNoContent();

    $names = array_map(
        static fn ($cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    );

    expect($names)->toContain('XSRF-TOKEN');
});

it('regenerates the session and its token on login, so a cached XSRF token is stale', function (): void {
    // `Store::regenerate()` = migrate() + regenerateToken(), so the login response carries a NEW
    // XSRF-TOKEN. INTEGRATION REQUIREMENT for the SPA, and the reason this is asserted here: a token
    // cached at boot 419s (rendered 401) on the first mutation after login, and the symptom looks like
    // a broken login.
    $user = User::factory()->create();

    $primed = currentTest()->getJson('/sanctum/csrf-cookie', spaHeaders());
    $before = $primed->getCookie('XSRF-TOKEN', false)?->getValue();

    expect($before)->toBeString();

    $login = currentTest()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => \Database\Factories\UserFactory::PASSWORD,
    ], spaHeaders());

    $login->assertOk();

    $after = $login->getCookie('XSRF-TOKEN', false)?->getValue();

    expect($after)->toBeString()
        ->and($after)->not->toBe(
            $before,
            'the CSRF token survived session regeneration, which means either regenerate() was not '
            .'called or the token is not tied to the session — a session fixation hazard either way',
        );
});

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A REAL Sanctum SPA cookie session, carried across more than one test request.
 *
 * WHY THIS EXISTS AND WHY actingAs() IS NOT A SUBSTITUTE. `actingAs()` sets the user on the guard
 * instance for one request; it never writes a session, so it cannot observe any property that lives
 * IN the session — `current_organization_id`, session regeneration on login, invalidation on logout,
 * and above all `password_hash_web`, the value Sanctum's AuthenticateSession compares on every
 * request and the ONLY mechanism by which a stolen session dies on a password change. A test that
 * asserts "siblings are logged out" with actingAs() asserts nothing at all: the middleware returns
 * early when there is no session, and the assertion passes against an application with
 * AuthenticateSession deleted.
 *
 * TWO HARNESS FACTS MAKE THIS NECESSARY, AND NEITHER IS OBVIOUS:
 *
 *   1. `getJson()`/`postJson()` send NO cookies unless withCredentials() was called
 *      (MakesHttpRequests::prepareCookiesForJsonRequest returns [] otherwise), and Laravel's test
 *      client NEVER feeds a response's Set-Cookie back into the next request. So a session
 *      established by POST /auth/login is invisible to the next GET /me unless the id is carried by
 *      hand, and the symptom is a 401 that reads like an authentication bug.
 *   2. The session STORE is a singleton inside the booted application, and SESSION_DRIVER=array
 *      keys its data by session id in memory. So carrying the id is sufficient — and it is also
 *      exactly what a browser does.
 *
 * The Origin header still has to be on every request (tests/Support/spa.php); without it the
 * stateful pipeline never runs and there is no session to carry.
 *
 * ── AND THE THIRD FACT, WHICH IS THE ONE THAT SILENTLY INVERTS ASSERTIONS ────────────────────────
 *
 * `AuthManager` caches every resolved guard for the life of the PROCESS, and Sanctum's guard is an
 * `Illuminate\Auth\RequestGuard`, which caches the resolved user in `$this->user` and never clears it
 * on a new request — `app()->refresh('request', $guard, 'setRequest')` swaps the request and leaves
 * the user alone. In production one process serves one request, so this is invisible. In the suite
 * one process serves EVERY request of a test, so the second request reads a User object resolved
 * during the first.
 *
 * MEASURED, 2026-08-13, and both directions are wrong in the reassuring direction:
 *   * after POST /auth/logout, a re-sent cookie gets 200 from GET /me with the cache and 401 without
 *     it — so a logout test written the obvious way reports that logout does not work;
 *   * after a password change, GET /me on a sibling session gets 200 with the cache and 401 without
 *     — so a "reset logs the siblings out" test would report the OPPOSITE of the truth, because
 *     Sanctum's AuthenticateSession compares `$request->user()->getAuthPassword()` and the cached
 *     model instance still holds the old hash.
 *
 * `freshProcess()` is therefore called at the end of `establish()` and must be called after any
 * request that changes authentication state. It is `Auth::forgetGuards()` — public framework API,
 * doing exactly what a new PHP-FPM request does — and NOT a bypass: it removes a test-only cache, it
 * does not weaken a check. Nothing in this class touches the session, the credential or the guard's
 * own logic.
 */
final class SpaSession
{
    /**
     * Log in for real and arrange for every subsequent request from $test to carry the session.
     *
     * Returns the session ID so a test can hold TWO sessions for one user at once — which is what a
     * sibling-logout test needs, and the reason this returns a value rather than being void.
     */
    public static function establish(
        TestCase $test,
        User $user,
        string $password = UserFactory::PASSWORD,
    ): string {
        $response = $test->withCredentials()->postJson(
            '/api/v1/auth/login',
            ['email' => $user->email, 'password' => $password],
            spaHeaders(),
        );

        $response->assertOk();

        $id = self::idFrom($response);

        self::resume($test, $id);

        return $id;
    }

    /**
     * Drop every resolved guard, so the next request re-reads the session and the user from scratch.
     *
     * This is what a new PHP-FPM request does for free. See the class docblock for the two
     * assertions that invert without it.
     */
    public static function freshProcess(): void
    {
        Auth::forgetGuards();
    }

    /**
     * Give this test its own client address, so it cannot share a rate-limiter bucket with any other.
     *
     * THE FLAKE THIS PREVENTS IS THE ONE pest-testing NAMES: "the rate-limit test fails only in CI".
     * phpunit.xml pins CACHE_STORE=valkey against `valkey-test`, and RefreshDatabase rolls back the
     * DATABASE and nothing else — so every rate-limiter bucket SURVIVES the test that filled it, the
     * next test in the file, and the next run of the whole suite. `login` allows 20 requests per
     * minute per IP and every request in the suite arrives from 127.0.0.1, so a suite that logs in for
     * real dozens of times is a 429 storm the moment it runs against a shared store.
     *
     * The fix is NOT to flush the cache: under --parallel that wipes the sibling workers' locks, which
     * pest-testing bars outright. It is to make the KEY unique, which is also the honest model — these
     * are different clients.
     *
     * 2001:db8::/32 is the IPv6 documentation range, so the value can never be a real address, and it
     * passes FILTER_VALIDATE_IP (which App\Services\Audit\AuditLogger applies before storing an IP).
     * A test that wants to EXERCISE a limiter still can: the bucket is unique to it, not absent.
     */
    public static function isolateRateLimits(TestCase $test): void
    {
        $test->withServerVariables([
            'REMOTE_ADDR' => sprintf(
                '2001:db8::%04x:%04x:%04x',
                random_int(0, 0xFFFF),
                random_int(0, 0xFFFF),
                random_int(0, 0xFFFF),
            ),
        ]);
    }

    /**
     * A globally unique fixture address.
     *
     * Same reason as isolateRateLimits(): `password-request` allows 3 per FIFTEEN MINUTES per account,
     * and it keys on the SUBMITTED address — so a literal shared by six requests in one file is a
     * guaranteed 429 against a persistent store, and a literal reused by two runs of the suite inside
     * the same window fails the second run only.
     */
    public static function uniqueEmail(string $label): string
    {
        return $label.'-'.Str::lower((string) Str::ulid()).'@example.test';
    }

    /**
     * Point $test's subsequent requests at an existing session id.
     *
     * withCookie() encrypts and prefixes the value exactly as EncryptCookies expects on the way in,
     * so this is the same wire shape a browser sends.
     */
    public static function resume(TestCase $test, string $id): void
    {
        $test->withCredentials()->withCookie(self::cookieName(), $id);

        self::freshProcess();
    }

    /**
     * The decrypted session id from a response's Set-Cookie.
     *
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $response
     */
    public static function idFrom(TestResponse $response): string
    {
        $cookie = $response->getCookie(self::cookieName());

        if ($cookie === null) {
            throw new RuntimeException(
                'The response set no '.self::cookieName().' cookie, so no session was established. '
                .'The usual cause is a missing Origin header: without it '
                .'EnsureFrontendRequestsAreStateful classifies the request as third-party and '
                .'StartSession never runs. Send spaHeaders().',
            );
        }

        $value = $cookie->getValue();

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('The session cookie carried no value.');
        }

        return $value;
    }

    public static function cookieName(): string
    {
        $name = config('session.cookie');

        return is_string($name) && $name !== '' ? $name : 'kb_session';
    }
}

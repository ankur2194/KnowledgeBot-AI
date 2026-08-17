<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| A guest reaching an auth:sanctum route gets 401 — WHATEVER IT ACCEPTS
|--------------------------------------------------------------------------
|
| THE REGRESSION GUARD FOR A DEFECT THAT WAS LIVE IN PRODUCTION WHILE THIS ENTIRE SUITE WAS GREEN.
|
| `Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()` installs
| `redirectGuestsTo(fn () => route('login'))` for every application, before bootstrap/app.php's own
| closure runs. No route here is named `login` — ours is `auth.login` — and none ever will be: this
| service serves no HTML and the sign-in screen is a Next.js page on another host.
|
| WHY EVERY EXISTING TEST MISSED IT. `Authenticate::unauthenticated()` reads
|
|     $request->expectsJson() ? null : $this->redirectTo($request)
|
| so the callback is evaluated ONLY for a caller that does not accept JSON. `spaHeaders()` sends
| `Accept: application/json` and 5A's brief REQUIRES it on every request — for a different and entirely
| correct reason (without an Origin header the stateful pipeline never runs). So every test took the
| `null` branch. `spaHeaders()`'s own docblock even predicted the other branch, describing it as "a
| redirect or an HTML error page instead of the error envelope"; nobody measured what it actually did,
| which was throw RouteNotFoundException from inside the middleware.
|
| MEASURED on the deployed stack, `GET /api/v1/me` with no session:
|
|     Accept: application/json    -> 401 authentication
|     Accept: text/html           -> 500 internal_dependency   <-- before bootstrap/app.php's fix
|     no Accept header            -> 500 internal_dependency   <-- before
|
| Reported by a user who pasted the URL into the address bar. A 500 there is wrong three times over: a
| server-fault status for a client condition, an `internal_dependency` class when no dependency was
| involved, and `retryable: false` telling the caller their credentials are irreparable when they merely
| need to sign in.
|
| SO THESE TESTS DELIBERATELY DO NOT USE spaHeaders(). That is the whole point — the helper's Accept
| header is what hid the bug. Origin is still sent where a session must exist, but not here: a guest
| needs no session store to be refused.
*/

// `throttle:admin` sits ahead of the guard, so a bucket exhausted by an earlier file in the same
// process would answer 429 and this suite would fail for a reason it is not about.
beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

it('refuses a guest with 401 for every Accept header a real client sends', function (?string $accept): void {
    // `get()` rather than `getJson()`: getJson FORCES `Accept: application/json`, which is precisely the
    // branch that already worked. A test for this defect cannot be written with the JSON helpers.
    //
    // `currentTest()` rather than `$this`: inside a Pest closure PHPStan resolves `$this` to
    // `Pest\PendingCalls\TestCall`, which declares no HTTP helpers, so `$this->get()` is a level-8
    // `method.notFound` — measured, 2 errors, while the test itself passed. tests/Pest.php:80 exists to
    // narrow that once for the whole suite, and its docblock says so. Not a suppression: phpstan.neon
    // deliberately has no baseline.
    $headers = $accept === null ? [] : ['Accept' => $accept];

    $response = currentTest()->get('/api/v1/me', $headers);

    $response->assertStatus(401);
    // The envelope, not an HTML page and not a redirect. `error_class` is asserted because the bug's
    // signature was the CLASS being wrong (`internal_dependency`) even once the status was noticed.
    expect($response->json('error_class'))->toBe('authentication');
    expect($response->json('retryable'))->toBeFalse();
})->with([
    'no Accept header at all' => [null],
    'a browser navigation' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
    'text/html only' => ['text/html'],
    'the wildcard curl sends' => ['*/*'],
    'the SPA (the one that always worked)' => ['application/json'],
]);

it('pins the guest-redirect callback to null so the framework default cannot come back', function (): void {
    // A behavioural test above would also catch a deleted `redirectGuestsTo(null)`, but only for the
    // routes it names. This asserts the CONFIGURATION, so a new auth:sanctum route is covered the moment
    // it exists. Reflection because `Authenticate::$redirectToCallback` is protected static and there is
    // no accessor — the alternative is asserting nothing about it at all.
    $property = new \ReflectionProperty(Authenticate::class, 'redirectToCallback');
    $callback = $property->getValue();

    expect($callback)->not->toBeNull(
        'Laravel installs a default guest redirect in ApplicationBuilder::withMiddleware(); a null '
        .'callback here means the framework changed and this test needs re-deriving, not that the app is safe.',
    );

    expect(call_user_func($callback, request()))->toBeNull(
        'The guest-redirect callback must resolve to null. If this fails, bootstrap/app.php has lost '
        ."\$middleware->redirectGuestsTo(null) and the framework default — route('login'), a route that "
        .'does not exist here — is back, turning every non-JSON unauthenticated request into a 500.',
    );
});

it('refuses a guest on EVERY parameterless GET route that requires auth:sanctum', function (): void {
    // A closure over the route table rather than a list, because the defect was global: it affected
    // every auth:sanctum route at once, and a spec naming `/api/v1/me` says nothing about the next one.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn ($route): bool => $route->parameterNames() === [])
        ->map(fn ($route): string => '/'.ltrim($route->uri(), '/'))
        ->unique()
        ->values();

    // Positive control. A renamed guard, a moved route file or a changed middleware spelling would
    // otherwise empty this set and leave the test green while asserting nothing.
    expect($routes)->not->toBeEmpty('found no parameterless GET route behind auth:sanctum — the filter is wrong');

    foreach ($routes as $uri) {
        $response = currentTest()->get($uri, ['Accept' => 'text/html']);
        expect($response->getStatusCode())->toBe(401, "$uri answered {$response->getStatusCode()} to a guest sending Accept: text/html");
    }
});

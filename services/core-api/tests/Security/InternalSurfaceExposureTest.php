<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The internal surface's exposure — what this repository can actually check
|--------------------------------------------------------------------------
|
| ── WHY THIS FILE EXISTS, AND WHAT IT DELIBERATELY DOES NOT CLAIM ──────────────────────────────────
|
| `routes/internal.php` used to say: "A CI check asserts an external curl against the public host
| returns something other than 200 for /internal/v1/*." THERE IS NO CI — `.github/` was deleted on
| 2026-08-17 and nothing replaced it — and the claim sat on the route file of the one surface whose
| only stated defence it was. Worse, `CLAUDE.md`'s published sweep for false enforcement claims
| matches `gates.yml`, `.github/workflows` and `CI grep`, and that sentence contains none of the
| three, so the sweep returned clean while the claim was live.
|
| THE CLAIM IS RETRACTED IN THAT FILE RATHER THAN RE-HOMED HERE, because the thing it claimed is not
| checkable from this process. Whether `ai-api`, or this service's own `/internal/v1/*`, is reachable
| from the public internet is a property of the Docker network topology, the Traefik router labels
| and the absence of a host `ports:` mapping — all of which live in `infrastructure/`, none of which
| a Pest test booting this application can observe. **That property is checked by nothing today.**
|
| WHAT IS CHECKABLE FROM HERE IS THE PART THAT LIVES IN THIS SERVICE, and it is worth pinning
| because both halves are one-line regressions:
|
|   1. NO BROWSER MAY EVER PREFLIGHT THIS PREFIX. A `cors.paths` entry covering `internal/*` is the
|      first step to the surface being reachable from a page, and it is exactly the kind of entry
|      somebody adds while widening the list for a new SDK route.
|   2. NO AMBIENT AUTHORITY REACHES IT. The surface has no cookie, no bearer token and no session by
|      construction; a route that acquired the `web` or `api` stack would gain
|      `EnsureFrontendRequestsAreStateful` and with it a session a browser could carry.
|
| Neither is a substitute for the network check, and this file says so rather than letting a green
| suite read as one.
*/

it('exposes no CORS entry covering the internal prefix', function (): void {
    /** @var list<string> $paths */
    $paths = (array) config('cors.paths');

    // POSITIVE CONTROL FIRST. The matcher below is `Str::is`, which is what
    // `HandleCors`/`laravel-cors` uses against the request path — so if the list were empty, or if
    // this were the wrong matcher, the negative assertion would pass while proving nothing.
    expect($paths)->not->toBeEmpty();

    $matches = fn (string $path): bool => Str::is($paths, $path);

    expect($matches('api/v1/organizations/01J8A4B5C6D7E8F9G0H1J2K3M4/sources'))->toBeTrue(
        'the admin API is no longer covered by config/cors.php, so this test is matching nothing',
    );

    foreach (['internal/v1/callbacks/ingestion', 'internal/v1/health', 'internal/v1'] as $path) {
        expect($matches($path))->toBeFalse(
            "config/cors.php now covers `{$path}`. A CORS entry on the internal surface is the "
            .'first step to it being reachable from a browser, and no browser may ever call it.',
        );
    }
});

it('gives every internal route the signature verifier and no ambient credential', function (): void {
    $internal = array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn ($route): bool => str_starts_with($route->uri(), 'internal/'),
    ));

    // POSITIVE CONTROL. `routes/internal.php` holds one route today and will hold four; an empty
    // set would satisfy every assertion in the loop below.
    expect($internal)->not->toBeEmpty();

    foreach ($internal as $route) {
        // RESOLVED, NOT GATHERED. `gatherMiddleware()` returns the GROUP NAME — `['internal']` —
        // so every class assertion below would be checking a string that is never in the list, in
        // both directions: the verifier would never be found and no forbidden class ever would
        // either. `resolveMiddleware()` expands groups and aliases into the classes that actually
        // run, which is the only list worth asserting against. (Measured: the first version of this
        // test failed on the presence assertion and would have passed every absence one vacuously.)
        $middleware = app('router')->resolveMiddleware($route->gatherMiddleware());

        // `in_array()` INSIDE THE EXPECTATION AND NOT `->toContain($class, $message)`. Pest's
        // `toContain()` is VARIADIC, so a second argument is a second NEEDLE rather than a failure
        // message — which is how the first version of this line came to assert that the middleware
        // list contained its own explanatory sentence. Measured, not reasoned: it failed with
        // "Failed asserting that an array contains '`internal/v1/...` is on the internal surface
        // with no signature verification...'".
        expect(in_array(VerifyInternalSignature::class, $middleware, true))->toBeTrue(
            "`{$route->uri()}` is on the internal surface with no signature verification. The HMAC "
            .'is the ONLY thing authenticating this surface: "it is only reachable from the private '
            .'network" is topology, not authorization.',
        );

        // A SESSION OR A BEARER GUARD ON THIS SURFACE WOULD BE AMBIENT AUTHORITY A BROWSER CAN
        // CARRY. The realistic regression is not somebody adding `StartSession` by name — it is a
        // route being moved onto the `api` group, which `statefulApi()` has prepended
        // `EnsureFrontendRequestsAreStateful` to, and which drags the whole stateful pipeline in
        // with one edit. Matching on class-name fragments catches the group and the individual
        // class alike, and survives an alias being renamed.
        $forbidden = [
            'StartSession', 'EncryptCookies', 'AddQueuedCookiesToResponse', 'PreventRequestForgery',
            'EnsureFrontendRequestsAreStateful', 'AuthenticateSession', 'Authenticate',
        ];

        foreach ($middleware as $entry) {
            foreach ($forbidden as $name) {
                expect(str_contains((string) $entry, $name))->toBeFalse(
                    "`{$route->uri()}` runs `{$entry}`. The internal surface must have no cookie, "
                    .'no session and no bearer token: a service caller proves itself with the '
                    .'signature and with nothing else.',
                );
            }
        }
    }
});

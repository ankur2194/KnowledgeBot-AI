<?php

declare(strict_types=1);

/**
 * THE HEADERS THAT MAKE A SANCTUM SESSION EXIST IN THE TEST SUITE.
 *
 * Every Feature, Security or Contract test that touches an authenticated admin route sends these.
 * It is a helper rather than a line each test remembers because the failure mode of forgetting is
 * not a 401 — it is a 500 with a message that points at the controller.
 *
 * WHY IT IS NEEDED. `EnsureFrontendRequestsAreStateful::fromFrontend()` decides whether a request is
 * first-party, and it decides it from a `Referer` or an `Origin` header. Laravel's test client sends
 * NEITHER. When it returns false the whole stateful pipeline is skipped — EncryptCookies,
 * AddQueuedCookiesToResponse, StartSession, PreventRequestForgery, AuthenticateSession — so there is
 * no session store on the request at all, and `Auth::guard('web')->login()` throws
 * "Session store not set on request." That renders as a 500 `internal_error`, and nothing in it says
 * "your test forgot a header".
 *
 * TWO HALVES, BOTH REQUIRED. This helper is the first; `SANCTUM_STATEFUL_DOMAINS=localhost` in
 * phpunit.xml is the second, because `config/sanctum.php`'s list has no default and fails closed to
 * `[]`. The Origin below must MATCH that entry: fromFrontend() strips only the scheme, appends a
 * trailing slash, and `Str::is`-matches each configured entry as `<entry>/*` — so the PORT is part of
 * the comparison and `http://localhost:3000` would NOT match a `localhost` entry.
 *
 * CSRF IS A NO-OP IN THIS SUITE, AND THAT IS A TRAP OF ITS OWN.
 * `PreventRequestForgery::handle()` short-circuits on `runningUnitTests()`, so no test in this suite
 * can observe a token mismatch by sending a wrong token — the middleware never looks. CSRF behaviour
 * (the 419 that an idle admin session produces, and the SPA's re-fetch-and-retry contract) must
 * therefore be tested by throwing `Illuminate\Session\TokenMismatchException` from a probe route
 * registered inside the test, which is the technique tests/Security/DenyOracleTest.php already uses
 * for conditions the harness cannot reproduce naturally. A test that "asserts CSRF works" by posting
 * a bad token asserts nothing.
 *
 * @param  array<string, string>  $extra  merged last, so a test may override or add headers
 * @return array<string, string>
 */
function spaHeaders(array $extra = []): array
{
    return array_merge([
        // The one header without which none of the above runs. Origin rather than Referer because a
        // real SPA sends Origin on every cross-origin mutation and Referer is stripped by referrer
        // policies — so this is also the header the production path actually depends on.
        'Origin' => 'http://localhost',

        // The admin surface is JSON only. Without this an authentication or authorization failure
        // renders as a redirect or an HTML error page instead of the error envelope every assertion
        // reads, and `assertJson` fails on a response the application considers correct.
        'Accept' => 'application/json',
    ], $extra);
}

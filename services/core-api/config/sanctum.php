<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    // EXACT HOSTS, WITH PORTS. Never a pattern and never '*'.
    // EnsureFrontendRequestsAreStateful::fromFrontend() strips the scheme and Str::is-matches only
    // the HOST, so `*.example.com` matches a dangling DNS record AND matches over plain http://.
    // There is no default value on purpose: an empty list fails closed (every request is treated as
    // third-party and gets no session), which is loud. A default containing localhost is what ships
    // to production by accident.
    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')),
    ))),

    'guard' => ['web'],

    // FALSE — we register `sanctum/csrf-cookie` ourselves in routes/web.php, so this suppresses
    // Sanctum's own registration and `route:list` shows exactly one entry for that URI.
    //
    // This config key IS the opt-out in Sanctum 4.3: SanctumServiceProvider::defineRoutes() returns
    // early on `config('sanctum.routes') === false`, and the `Sanctum::ignoreRoutes()` that
    // AppServiceProvider::register() used to call DOES NOT EXIST in this version — it fatals with
    // "Call to undefined method" on the first boot after `composer install`, i.e. on every artisan
    // command and every test. Note the strict comparison in the vendor code: `null` or an unset key
    // is NOT an opt-out.
    'routes' => false,

    // NULL, and it must stay null.
    // Guard::isValidAccessToken() evaluates `created_at > now()->subMinutes(config('sanctum.
    // expiration'))` AT REQUEST TIME — so lowering this value retroactively expires every token
    // already issued, and every device is logged out at once, hours after a deploy nobody connects
    // it to. Per-token lifetime is the third argument to createToken($name, $abilities, $expiresAt)
    // (30 days for mobile), and `sanctum:prune-expired --hours=24` is scheduled in routes/console.php.
    'expiration' => null,

    // Undocumented, and read as config('sanctum.last_used_at', true) by
    // SanctumServiceProvider::createGuard(). Left at the default, Sanctum's guard save()s
    // last_used_at on EVERY token-authenticated request — one UPDATE per stream, per poll, per
    // prefetch — and personal_access_tokens becomes the hottest write table in the database.
    // Last use is derived from usage_events instead.
    'last_used_at' => false,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'kb_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        // Laravel 13 replaced VerifyCsrfToken with PreventRequestForgery, which checks
        // Sec-Fetch-Site first and only falls back to token validation when origin verification is
        // unavailable. That header is only sent over HTTPS, so plain-HTTP dev takes the token path
        // and HTTPS production takes the origin path — different code, different verdict. Both
        // `originOnly` and `allowSameSite` stay at their defaults: app.<domain> -> api.<domain> is
        // same-SITE but not same-ORIGIN, so the token flow is what actually carries every admin
        // mutation (laravel-sanctum-auth).
        'validate_csrf_token' => PreventRequestForgery::class,
    ],

];

<?php

declare(strict_types=1);

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    // FOUR CLIENT CLASSES, FOUR MECHANISMS, NO FIFTH (laravel-sanctum-auth NN2):
    //   admin SPA   -> `web`, Sanctum SPA cookie session + CSRF
    //   mobile      -> `sanctum`, personal access token with explicit abilities and an expires_at
    //   widget      -> NOT a guard: an opaque origin-bound session token in Valkey, resolved by
    //                  middleware on the `runtime` group. A Sanctum token needs a tokenable model
    //                  and an anonymous visitor is not a User.
    //   hosted chat -> the same widget session token, so both public surfaces share one auth path
    //   internal    -> NOT a guard: HMAC signature verification on the `internal` group.
    //
    // THE GUARD CHAIN, AND WHY `web` AND `sanctum` ARE ONE MECHANISM RATHER THAN TWO.
    // `auth:sanctum` does not authenticate on its own: Laravel\Sanctum\Guard::__invoke() iterates
    // config('sanctum.guard') — which config/sanctum.php sets to ['web'] — and returns whatever the
    // SESSION guard resolved. So an admin request carrying the SPA cookie is authenticated by `web`
    // and merely NAMED `sanctum` at the route. There is no second credential and no second
    // authorization path; NN2's "four mechanisms, no fifth" is not violated by the two entries below.
    //
    // DELETING `guards.sanctum` BREAKS EVERY EXISTING ADMIN ROUTE. `auth:sanctum` resolves this key
    // to build the guard, so its absence is an "Auth guard [sanctum] is not defined"
    // InvalidArgumentException on the first authenticated request — not a fallback to `web`.
    //
    // The bearer branch of that same guard is reached only when no session resolves AND the request
    // carries an Authorization: Bearer header. Nothing in this application mints a personal access
    // token and no migration creates `personal_access_tokens` (App\Models\User records decision D11),
    // so that branch is dead by construction rather than by check.
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Sanctum registers this guard itself; declaring it keeps the whole set readable in one file.
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            // A string, not ::class: the model does not exist yet and static analysis runs over
            // config/. Replace with App\Models\User::class when the model lands.
            'model' => env('AUTH_MODEL', 'App\\Models\\User'),
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    // Re-authentication window for destructive actions (§18.3). Short on purpose: this is the timer
    // behind "confirm your password to rotate a provider credential".
    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),

];

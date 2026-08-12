<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

/*
|--------------------------------------------------------------------------
| Web routes — the ONLY session-bearing, cookie-bearing, CSRF-checked group
|--------------------------------------------------------------------------
|
| Two entries, and it stays two. Everything a browser, widget or mobile client can reach lives in
| one of the four API groups registered in bootstrap/app.php. There is deliberately no
| routes/api.php: one shared api file is exactly how a public runtime route ends up inheriting this
| group's session, cookie and PreventRequestForgery stack (laravel-control-plane DoD).
|
| GET /up — the health endpoint — is registered by `withRouting(health: '/up')` in bootstrap/app.php
| and is NOT redefined here. Both would answer the same URI, the first-registered would win, and
| `route:list` would show two entries for one endpoint; a route-list test asserting the exact route
| set could not tell that apart from a real duplicate. One registration, in bootstrap/app.php.
|
*/

// Sanctum registers `sanctum/csrf-cookie` itself; config/sanctum.php sets `'routes' => false`
// so this is the only registration. Owning it here is what lets the route carry our own throttle
// and sit in exactly one group — an SPA that cannot fetch this route cannot perform any mutation,
// because Laravel 13's PreventRequestForgery falls through to token validation for app.<host> ->
// api.<host> (same-site, not same-origin).
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

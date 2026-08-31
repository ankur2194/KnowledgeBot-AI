<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Sdk\BootstrapController;
use App\Http\Controllers\Api\V1\Sdk\ChatSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SDK API — Surface: sdk (404 deny)
|--------------------------------------------------------------------------
|
| Mounted at `sdk/v1` on the `sdk` middleware group by bootstrap/app.php.
| UNAUTHENTICATED and origin-checked: the caller is a loader script on a customer's page, and that
| page is hostile. The public bot id grants nothing by itself.
|
| `sdk/*` sits OUTSIDE `api/` on purpose, and config/cors.php lists it explicitly. Laravel 11+
| leaves config/cors.php unpublished with `paths` defaulting to ['api/*', 'sanctum/csrf-cookie'] —
| an SDK route mounted anywhere else gets no CORS headers, the browser rejects the OPTIONS preflight
| before any controller runs, and the server log is empty.
|
| ── THE TWO ROUTES, AND THE THIRD AREA THAT IS DELIBERATELY ABSENT ─────────────────────────────
|
|   POST /bootstrap    origin-validated public bot configuration, before any session exists
|   POST /session      short-lived chat-session token minting
|
| SIGNED USER-METADATA VALIDATION (docs/12 §17.3's third area, control 3) IS NOT HERE AND IS NOT AN
| OVERSIGHT. The end-user identity is signed by the CUSTOMER'S backend with a per-bot shared secret;
| there is no per-bot secret column in this schema and no rotation story for one, so there is nothing
| to verify against. `user_token` is accepted for shape and IGNORED, which is why the shipped
| loader's body does not 422.
|
| The alternative — parsing the token without cryptographically verifying it — is strictly worse
| than ignoring it: identity claims presented as plain loader config are attacker-controlled BY
| DEFINITION (§8.20), so a "best-effort verified" token is a downgrade to anonymous wearing an
| authenticated name. When it lands, a bad signature, `alg: none`, an unknown `kid`, an expired
| `exp` or an `iat` in the future are each a REJECTION and never a silent downgrade.
|
| Fixed by kb-security-baseline -> references/widget-embedding-and-output.md, controls 1-4 and 8.
| Implement it; do not redesign it. In particular:
|   - Origin comes from the HEADER only, never from the body or a query parameter;
|   - every rejection is a 404 with a byte-identical body: unlisted origin,
|     https://<allowed>.evil.com, absent Origin, `Origin: null`, unknown bot id, valid bot from the
|     wrong origin;
|   - the minted token is stored as a HASH with a TTL, never the token itself;
|   - a widget token may never carry an admin ability. It is minted with no human authentication at
|     all, so an XSS on the customer's marketing site would otherwise delete their knowledge base.
|
| ── ONE CONTROL FROM THAT REFERENCE IS NOT IMPLEMENTED, AND IT IS REPORTED RATHER THAN FAKED ───
|
| `laravel-sanctum-auth`'s `resolve()` sketch compares the REQUEST's `Origin` against a fixed list of
| OUR OWN embed origins, on top of re-validating the stored embedder origin. It is not implemented:
| the list would have to be configuration, an unset or mis-set one denies hosted chat and the widget
| outright, and the shape people reach for to fix that — skip the check when the list is empty — is a
| fail-open control that reads as a closed one. The property it defends is already held by
| config/cors.php against a browser and by the bearer against everything that is not one.
| WidgetSessionService's docblock carries the same note.
|
| The bootstrap limiter counts MISSES only (Limit::…->after(fn ($res) => $res->getStatusCode() ===
| 404)), so probing bot ids costs the prober and legitimate traffic pays nothing — enumeration cover
| for the 404 rule (laravel-sanctum-auth).
|
*/

Route::middleware('throttle:sdk-bootstrap')->group(function (): void {
    // CONFIGURATION WITHOUT A CREDENTIAL. The loader draws a launcher before the iframe boots and
    // must not mint a session to do it: a page that loads the loader and never opens the widget
    // would otherwise burn one session per view.
    Route::post('/bootstrap', BootstrapController::class)->name('bootstrap');

    // THE MINT. The one request in the system carrying an unforgeable embedder `Origin`, because it
    // is made by a document on the customer's own page. Every later request comes from the iframe,
    // whose `Origin` is ours and proves nothing about who is embedding us.
    Route::post('/session', ChatSessionController::class)->name('session.store');
});

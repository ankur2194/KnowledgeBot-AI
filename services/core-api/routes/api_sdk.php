<?php

declare(strict_types=1);

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
| Areas this file will hold (docs/12 §17.3):
|   - origin-validated bootstrap configuration
|   - short-lived widget session token minting
|   - signed user-metadata validation (the end-user identity is signed by the CUSTOMER'S backend)
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
| The bootstrap limiter counts MISSES only (Limit::…->after(fn ($res) => $res->getStatusCode() ===
| 404)), so probing bot ids costs the prober and legitimate traffic pays nothing — enumeration cover
| for the 404 rule (laravel-sanctum-auth).
|
*/

Route::group([], function (): void {
    // TODO: SDK bootstrap endpoints land here.
});

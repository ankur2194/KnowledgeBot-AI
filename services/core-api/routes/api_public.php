<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public chat runtime API — Surface: public_runtime (404 deny)
|--------------------------------------------------------------------------
|
| Mounted at `rt/v1` on the `runtime` middleware group by bootstrap/app.php.
|
| NO SESSION, NO COOKIE, NO CSRF, NO AMBIENT AUTHORITY. Identity is the opaque, origin-bound chat
| session token minted by the SDK bootstrap — the same token for the embedded widget and for hosted
| chat, because most hosted-chat visitors are anonymous and one auth path keeps the 404 rule, the
| rate-limit keys and the abuse hooks identical across both public surfaces. The mobile app is the
| exception: it carries a Sanctum personal access token with explicit abilities.
|
| This group is deliberately NOT the `api` group: statefulApi() prepends
| EnsureFrontendRequestsAreStateful to `api`, and a request from a stateful Origin would then
| acquire the admin session stack on a surface that must have none.
|
| Every rejection here is a 404 with a byte-identical body — foreign bot id, unknown bot id, wrong
| origin, expired session. A 403 on a foreign identifier confirms the row exists and turns the
| endpoint into an enumeration oracle. The error_class stays `authorization` either way and nothing
| branches on the status (kb-error-taxonomy footnote 1).
|
| Areas this file will hold (docs/12 §17.2): bot public configuration, chat session creation,
| conversation creation, message submission, the SSE response stream, permitted conversation
| history, feedback submission, citation detail.
|
| Two contracts are already pinned for the streaming route and must not be re-derived here:
|   - the public request body is exactly {client_message_id, content}, with `extra` REJECTED
|     (kb-internal-api-contracts);
|   - the relay is response()->stream(), never eventStream(), because the mandated `: ping` is an
|     SSE comment and eventStream() can only emit named events. Read
|     laravel-control-plane/references/sse-relay-controller.md before writing the callback.
|
| Rate limiting is the composite sliding window over bot + origin + session + IP evaluated in one
| EVALSHA (valkey-keyspaces) — not Laravel's fixed-window throttle, which lets 2x the limit through
| across a window boundary.
|
*/

Route::group([], function (): void {
    // TODO: public chat runtime endpoints land here.
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Runtime\RuntimeBotController;
use App\Http\Controllers\Api\V1\Runtime\RuntimeCitationController;
use App\Http\Controllers\Api\V1\Runtime\RuntimeConversationController;
use App\Http\Controllers\Api\V1\Runtime\RuntimeFeedbackController;
use App\Http\Controllers\Api\V1\Runtime\RuntimeTranscriptController;
use App\Http\Controllers\Api\V1\Runtime\StreamChatMessageController;
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
| MOBILE IS STILL UNWIRED AND THAT IS STATED RATHER THAN LEFT TO BE DISCOVERED. Nothing in this
| application mints a personal access token — App\Models\User deliberately omits HasApiTokens, and
| `RejectBearerToken` refuses one on `api/*` by design — so the React Native client has no credential
| for this surface today. `ResolveChatSession` resolves ONE mechanism, the `kbw_` chat session, and
| adding a second is a deliberate change to the four-mechanisms rule (laravel-sanctum-auth NN2),
| not an omission to patch in a later route.
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
| THE ONE EXCEPTION TO THE 404 RULE, AND IT IS DELIBERATE: 401 `authentication` for a missing,
| malformed or expired BEARER. It is a different fact from "you may not do this" and the client acts
| on it differently — the widget's refresh flow triggers on exactly that pair and on nothing else, so
| rendering it as a 404 would leave a live visitor with a dead composer and no re-mint. What must
| never be a 401 is a valid bearer against a resource it does not own; that is the 404.
|
| ── THE SIX AREAS (docs/12 §17.2), ALL PRESENT ──────────────────────────────────────────────────
|
|   GET  /bot                                  public bot configuration
|   POST /conversations                        conversation creation
|   POST /conversations/{conversation}/messages   message submission — the SSE relay
|   GET  /conversations/{conversation}/messages   permitted history
|   POST /messages/{message}/feedback          feedback submission
|   GET  /messages/{message}/citations         citation detail
|
| Chat SESSION creation is `sdk/v1`'s, not this file's: it is the one request that carries an
| unforgeable embedder `Origin`, and it is made by the loader on the customer's page rather than by
| anything holding a session.
|
| Two contracts are already pinned for the streaming route and are not re-derived here:
|   - the public request body is exactly {client_message_id, content}, with `extra` REJECTED
|     (kb-internal-api-contracts). Laravel IGNORES unknown keys by default, so the refusal is an
|     explicit check in SendChatMessageRequest::withValidator();
|   - the relay is response()->stream(), never eventStream(), because the mandated `: ping` is an
|     SSE comment and eventStream() can only emit named events.
|
| ── RATE LIMITING IS NOT `throttle:` ON THIS GROUP, AND THAT IS THE POINT ───────────────────────
|
| The composite sliding window over bot + origin + session + IP is evaluated in ONE EVALSHA inside
| ChatGate -> QuotaGate -> BotRateLimiter, on the SUBMISSION route only. Laravel's `throttle`
| middleware is fixed-window and lets 2x the limit through across a boundary, which on a metered
| chat surface is the bill; and it keys on values that only exist once the credential has been
| resolved, which is after the middleware stack has decided.
|
| THE READ ROUTES CARRY `throttle:runtime-read` AND THE WRITE ROUTES DO NOT. That is not
| inconsistency: the reads are cheap and unmetered, so a coarse fixed window is the right tool and
| the 2x boundary slop costs nothing. The submission route is the one that spends money, and it gets
| the sliding window instead — not as well, because two limiters on one route would charge a request
| the second one refuses.
|
*/

Route::middleware('throttle:runtime-read')->group(function (): void {
    // The bot this session is bound to. NO IDENTIFIER IN THE PATH: a `{bot}` segment would be a
    // value the caller chooses, which is a lookup that can miss, which is an enumeration surface.
    Route::get('/bot', RuntimeBotController::class)->name('bot.show');

    // Permitted history. Scoped to the organization AND to the participant — the second is the one
    // an ordinary user could otherwise breach by changing a ULID.
    Route::get('/conversations/{conversation}/messages', RuntimeTranscriptController::class)
        ->name('conversations.messages.index');

    // The evidence behind one answer. The only public surface that returns tenant source text, and
    // every row it can reach was written under the turn's own organization and version scope.
    Route::get('/messages/{message}/citations', RuntimeCitationController::class)
        ->name('messages.citations.index');
});

Route::middleware('throttle:runtime-write')->group(function (): void {
    Route::post('/conversations', RuntimeConversationController::class)->name('conversations.store');

    Route::post('/messages/{message}/feedback', RuntimeFeedbackController::class)
        ->name('messages.feedback.store');
});

/*
| THE SUBMISSION ROUTE CARRIES NO `throttle:` MIDDLEWARE AT ALL — see the header. Its limiter is the
| four-scope sliding window inside the authorization gate, which runs before the first byte and
| refuses with two DIFFERENT error classes: `tenant_quota` (403) for the bot's own ceiling, which the
| organization set on its own bot, and `rate_limit` (429 + Retry-After) for the platform's origin,
| session and IP scopes. A fixed-window `throttle:` in front of it would refuse with a third
| rendering of the same idea and would charge requests the real limiter then rejected.
|
| The path spelling is pinned by three shipped clients — apps/web/src/features/chat/stream-answer.ts,
| apps/widget/src/app/stream.ts and apps/mobile/src/features/chat/stream-answer.ts — and by
| kb-internal-api-contracts -> references/public-chat-request-body.md. It is not ours to tidy.
*/
Route::post('/conversations/{conversation}/messages', StreamChatMessageController::class)
    ->name('conversations.messages.store');

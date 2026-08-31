<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sdk;

use App\Http\Controllers\Controller;
use App\Http\Requests\MintChatSessionRequest;
use App\Http\Resources\ChatSessionResource;
use App\Services\Sdk\WidgetSessionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;

/**
 * `POST /sdk/v1/session` — mint a chat-session token.
 *
 * ═══ THE ONE REQUEST IN THE SYSTEM THAT CARRIES AN UNFORGEABLE EMBEDDER `Origin` ═══════════
 *
 * It is made by the LOADER, from a document on the customer's own page, so the browser sets
 * `Origin: https://customer.example` and page script cannot change it. Every request after the
 * handshake comes from the IFRAME, whose `Origin` is our own widget host and proves nothing about
 * who is embedding us — which is why the frame can never mint or re-mint for itself, and why
 * renewal is loader-driven over a `session-expiring` message.
 *
 * ═══ EVERY REJECTION IS A 404 WITH A BYTE-IDENTICAL BODY ═══════════════════════════════════
 *
 * Unknown bot id, a bot that is not published, a bot that is not public, an absent `Origin`,
 * `Origin: null`, an unlisted origin, `https://<allowed>.evil.com`, a valid bot from the wrong
 * origin. `WidgetSessionService::mint()` returns null for all of them rather than throwing eight
 * distinguishable exceptions, and this method turns null into one `abort(404)`.
 *
 * A 403 anywhere in that set would answer "that bot id is real, your domain just is not on its
 * list", which is an enumeration oracle over every tenant's bots — and the body matters as much as
 * the status, because an attacker reads the body.
 *
 * ═══ THE RESPONSE CARRIES A LIVE CREDENTIAL ═══════════════════════════════════════════════
 *
 * `no-store` is set here rather than left to a default: a credential in an intermediary's cache is a
 * credential for whoever shares that cache. The token exists in this body, in the loader's memory,
 * and in the `postMessage` payload that hands it to the frame — never in a URL, never in a log,
 * never in a column.
 *
 * ═══ THE LIMITER COUNTS MISSES ═════════════════════════════════════════════════════════════
 *
 * `throttle:sdk-bootstrap` counts only 404 responses, so probing bot ids costs the prober and
 * legitimate traffic pays nothing. That is the enumeration cover that makes the 404 rule affordable:
 * without it, an attacker gets unlimited attempts at the one lookup that is deliberately
 * unscoped.
 */
final class ChatSessionController extends Controller
{
    public function __construct(private readonly WidgetSessionService $sessions) {}

    #[ResponseShape(
        status: 201,
        properties: ['data' => ChatSessionResource::class],
        description: 'A chat-session token bound to one bot and one embedder origin.',
        errors: [404, 422, 429],
    )]
    public function __invoke(MintChatSessionRequest $request): JsonResponse
    {
        $session = $this->sessions->mint(
            (string) $request->validated('bot_id'),
            // THE HEADER, AND ONLY THE HEADER. Never `$request->input('origin')` and never a query
            // parameter — those are page script's to choose, and the entire value of this value is
            // that it is not. `ValidateEmbedOrigin` has already refused an absent, `null` or
            // malformed one; the bot-specific match happens inside the service.
            $request->headers->get('Origin'),
        );

        if ($session === null) {
            abort(404);
        }

        return response()
            ->json(['data' => (new ChatSessionResource($session))->toArray($request)], 201)
            // A LIVE CREDENTIAL — see the class docblock. `private` alone is not enough: it permits
            // the browser's own cache, and this response must not be replayable from a back button.
            ->header('Cache-Control', 'no-store');
    }
}

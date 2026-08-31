<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Http\Controllers\Controller;
use App\Http\Resources\RuntimeMessageCollectionResource;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Chat\ChatGate;
use App\Services\Sdk\WidgetSession;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /rt/v1/conversations/{conversation}/messages` — the history a visitor may read.
 *
 * ═══ "PERMITTED" IS TWO SCOPES, AND THE SECOND IS THE ONE THAT MATTERS HERE ════════════════
 *
 * The organization scope stops a cross-tenant read. The PARTICIPANT scope stops the cheaper attack:
 * an organization-only check would let any visitor holding any session read every other visitor's
 * transcript in the same tenant by changing a ULID in the URL. That is a leak an ordinary user can
 * perform with a browser, which makes it the more likely one.
 *
 * `ChatGate::authorizeConversationAccess()` does both and 404s on either failure, so a foreign
 * conversation id and one that never existed are the same response — body included.
 *
 * ═══ IT IS BOUNDED, NOT PAGINATED, AND THAT IS A DELIBERATE DIFFERENCE FROM THE ADMIN LISTS ═
 *
 * A visitor re-reading their own conversation wants the whole thing in order. A conversation long
 * enough to need pages is one whose earlier turns the model itself has already dropped from its
 * window, so a cursor would be machinery for a case the product does not have. The cap is what stops
 * an unauthenticated caller asking for an unbounded read; it is not a product limit and there is no
 * `per_page` parameter to argue about.
 */
final class RuntimeTranscriptController extends Controller
{
    /**
     * The most entries one read returns.
     *
     * Two hundred is roughly a hundred turns, which is far beyond any real widget conversation and
     * an order of magnitude above the prompt window. It bounds the response body and the query, and
     * it is a constant rather than configuration because there is no operator decision in it.
     */
    private const MAX_MESSAGES = 200;

    public function __construct(
        private readonly ChatGate $gate,
        private readonly ConversationRepositoryInterface $conversations,
    ) {}

    #[ResponseShape(
        status: 200,
        properties: ['data' => RuntimeMessageCollectionResource::class],
        description: 'The settled entries of one conversation, oldest first.',
        errors: [401, 404, 429],
    )]
    public function __invoke(Request $request, string $conversation, WidgetSession $session): JsonResponse
    {
        $this->gate->authorizeConversationAccess($session, $conversation, 'chat:read');

        $messages = $this->conversations->transcript(
            $session->organizationId,
            $conversation,
            self::MAX_MESSAGES,
        );

        return response()->json([
            'data' => (new RuntimeMessageCollectionResource($messages))->toArray($request),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Http\Controllers\Controller;
use App\Http\Resources\RuntimeCitationCollectionResource;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Sdk\WidgetSession;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /rt/v1/messages/{message}/citations` — the evidence behind one answer.
 *
 * ═══ THE MESSAGE IS AUTHORIZED, NOT THE CONVERSATION ══════════════════════════════════════
 *
 * The path names a message, so the ownership check has to reach the conversation THROUGH it —
 * `findMessageForParticipant()` joins `messages -> conversations` and predicates on both the
 * organization and the participant. Reading the conversation id out of the message and then
 * authorizing THAT would be a check on a value the row supplied, which is not a check.
 *
 * ═══ THIS IS THE ONE PUBLIC SURFACE THAT RETURNS TENANT SOURCE TEXT ═══════════════════════
 *
 * §12.15's contract is that the evidence is shown. What makes it safe is upstream: every citation
 * row was written by `StreamFinalizer` from a `chunks` read scoped to the turn's organization AND to
 * its resolved `allowed_version_ids`, so a footnote can never name evidence the bot was not
 * permitted to search — including a retired version of the tenant's own source.
 *
 * The excerpt is uploaded or crawled content, which is hostile data permanently, and it is NOT
 * escaped here: the renderer is the boundary, and one renderer serves the widget, hosted chat,
 * mobile and the admin conversation-review screens alike.
 */
final class RuntimeCitationController extends Controller
{
    public function __construct(private readonly ConversationRepositoryInterface $conversations) {}

    #[ResponseShape(
        status: 200,
        properties: ['data' => RuntimeCitationCollectionResource::class],
        description: 'The citations attached to one answer, in label order.',
        errors: [401, 404, 429],
    )]
    public function __invoke(Request $request, string $message, WidgetSession $session): JsonResponse
    {
        if (! $session->can('chat:read')) {
            abort(404);
        }

        $row = $this->conversations->findMessageForParticipant(
            $session->organizationId,
            $message,
            $session->sessionId,
            $session->userId,
        );

        if ($row === null) {
            abort(404);
        }

        $citations = $this->conversations->citationsFor($session->organizationId, $message);

        return response()->json([
            'data' => (new RuntimeCitationCollectionResource($citations))->toArray($request),
        ]);
    }
}

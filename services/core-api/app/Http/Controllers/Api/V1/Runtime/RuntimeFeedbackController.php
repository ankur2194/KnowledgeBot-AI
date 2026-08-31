<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Enums\FeedbackRating;
use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatFeedbackRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Sdk\WidgetSession;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;

/**
 * `POST /rt/v1/messages/{message}/feedback` — a visitor's verdict on one answer.
 *
 * ═══ ONLY AN ASSISTANT TURN CAN BE RATED ═══════════════════════════════════════════════════
 *
 * A thumbs-up on the visitor's OWN question is not a verdict on anything, and it would put a row
 * into the satisfaction metric that measures nothing. The database cannot object — `feedback` has no
 * role predicate — so it is checked here, and it is a 404 rather than a 422 for the same reason
 * every other refusal on this surface is: the caller has no business learning which message ids are
 * which role.
 *
 * ═══ ONE VERDICT PER SUBMITTER, UPDATED RATHER THAN APPENDED ══════════════════════════════
 *
 * `feedback_message_session_unique` and `feedback_message_user_unique` are partial unique indexes,
 * so a visitor changing their mind rewrites one row. Appending would let one visitor move the
 * satisfaction metric by clicking repeatedly, which is the cheapest possible way to make an
 * analytics panel useless.
 *
 * The response is an acknowledgement rather than the row: what a client needs to know is that the
 * verdict landed, and returning the row would publish `submitted_by_session` — a value derived from
 * a live bearer credential.
 */
final class RuntimeFeedbackController extends Controller
{
    public function __construct(private readonly ConversationRepositoryInterface $conversations) {}

    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The verdict was recorded, or the existing one for this submitter was replaced.',
        errors: [401, 404, 422, 429],
    )]
    public function __invoke(StoreChatFeedbackRequest $request, string $message, WidgetSession $session): JsonResponse
    {
        if (! $session->can('feedback:submit')) {
            abort(404);
        }

        $row = $this->conversations->findMessageForParticipant(
            $session->organizationId,
            $message,
            $session->sessionId,
            $session->userId,
        );

        // ONE `abort(404)` FOR THREE DIFFERENT FACTS — no such message, another visitor's message,
        // and a message that is not an assistant turn. They are the same response deliberately: a
        // caller that could tell them apart could enumerate both ids and roles.
        if ($row === null || $row->role !== MessageRole::Assistant) {
            abort(404);
        }

        $comment = $request->validated('comment');

        $this->conversations->recordFeedback(
            $session->organizationId,
            $message,
            FeedbackRating::from((string) $request->validated('rating')),
            is_string($comment) ? $comment : null,
            $session->sessionId,
            $session->userId,
        );

        return response()->json([
            'data' => (new AcknowledgementResource(null))->toArray($request),
        ]);
    }
}

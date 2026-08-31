<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\FeedbackRating;
use App\Models\Feedback;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One verdict left on one answer.
 *
 * ── THE COMMENT IS END-USER FREE TEXT FROM A STRANGER ───────────────────────────────────────
 *
 * Bounded at 4,000 characters by the column's own CHECK, written by an unauthenticated visitor on
 * somebody else's website, and rendered here on a page an administrator reads. It is untrusted
 * exactly as an excerpt is: escape it at render, through the same sanitizer, in every client.
 *
 * §18.10 makes "whether user feedback comments are stored" a per-organization switch. THAT SWITCH
 * DOES NOT EXIST AS A COLUMN — see `ConversationController`, which names the same gap for admin
 * review — so nothing gates this field today. An organization that has not opted in to storing
 * comments has none to show, which is a coincidence of there being no writer for the setting rather
 * than an enforcement of it.
 *
 * ── THE SUBMITTER IS PUBLISHED, AND EXACTLY ONE OF THE TWO COLUMNS IS SET ───────────────────
 *
 * `feedback_submitter_exclusive` refuses both and neither. Publishing which one it was is what
 * distinguishes a customer's thumbs-down from a colleague's — §6.5 gives an Analyst "Add feedback",
 * so an administrator reviewing a transcript may legitimately rate an answer a customer received,
 * and a satisfaction figure that cannot tell the two apart is a figure the team can move by
 * clicking.
 */
final class TranscriptFeedbackResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(Feedback $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $feedback = $this->resource;

        return [
            'rating' => $feedback->rating->value,
            'comment' => $feedback->comment,
            'submitted_by_user_id' => $feedback->submitted_by_user_id,
            'submitted_by_session' => $feedback->submitted_by_session,
            'created_at' => $feedback->created_at->toIso8601String(),
            'updated_at' => $feedback->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'TranscriptFeedbackResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One verdict on one answer. At most one per submitter per message '
                    .'— a visitor who changes their mind UPDATES rather than appending, so a '
                    .'thumbs-down that became a thumbs-up is one row and the satisfaction figure '
                    .'counts one opinion.',
                'required' => [
                    'rating', 'comment', 'submitted_by_user_id', 'submitted_by_session',
                    'created_at', 'updated_at',
                ],
                'properties' => [
                    'rating' => ['type' => 'string', 'enum' => FeedbackRating::values()],
                    'comment' => [
                        'type' => ['string', 'null'],
                        'description' => 'Free text from the submitter, or null — most thumbs carry '
                            .'none, and an empty string is refused by the column so there is only '
                            .'one spelling of "no comment". UNTRUSTED END-USER TEXT: escape it at '
                            .'render, through the same sanitizer as model output.',
                    ],
                    'submitted_by_user_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the member who rated the answer — an '
                            .'administrator or analyst reviewing a transcript — or null when the '
                            .'verdict came from the visitor. EXACTLY ONE of this and '
                            .'`submitted_by_session` is populated.',
                    ],
                    'submitted_by_session' => [
                        'type' => ['string', 'null'],
                        'description' => 'The anonymous visitor\'s session handle, or null for a '
                            .'member\'s verdict. Not a credential: a one-way digest of the session '
                            .'bearer, the same value `conversations.anonymous_session_id` carries.',
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'Later than `created_at` when the submitter changed their '
                            .'mind, which is an UPDATE rather than a second row.',
                    ],
                ],
            ],
        ];
    }
}

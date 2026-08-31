<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as its own participant may see it.
 *
 * ═══ NEITHER PARTICIPANT COLUMN IS PUBLISHED ══════════════════════════════════════════════
 *
 * `anonymous_session_id` is a BEARER VALUE — it is derived from the session token, and publishing it
 * would put a component of a credential into a response body that a customer's own analytics can
 * scrape off the DOM. `user_id` is not published either: the caller either is that user or is not
 * entitled to know one exists.
 *
 * `bot_id` is absent for the reason `RuntimeBotResource` gives — the internal ULID is what the ADMIN
 * surface authorizes against, and the public surface already addresses the bot through the session.
 */
final class RuntimeConversationResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(Conversation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this->resource;

        return [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'locale' => $conversation->locale,
            // WHAT THE VISITOR WAS SHOWN, snapshotted at creation — so an edit to the bot's wording
            // afterwards cannot rewrite the record of what they agreed to.
            'consent_required' => $conversation->consent_required,
            'consent_text' => $conversation->consent_text_snapshot,
            'consent_granted_at' => $conversation->consent_granted_at?->toIso8601String(),
            'started_at' => $conversation->started_at?->toIso8601String(),
            'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'RuntimeConversationResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One conversation, as its own participant sees it. Neither '
                    .'participant column is published: the session id is a component of a bearer '
                    .'credential, and the user id would confirm an account exists.',
                'required' => [
                    'id', 'status', 'locale', 'consent_required', 'consent_text',
                    'consent_granted_at', 'started_at', 'last_activity_at',
                ],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID. It addresses the conversation on the message and '
                            .'transcript routes, and it authorizes nothing on its own — every read '
                            .'re-checks that the calling session owns it.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['active', 'ended', 'expired'],
                        'description' => 'Only `active` accepts messages. `ended` and `expired` are '
                            .'refusals with a reason rather than 404s: the caller owns the row and '
                            .'is entitled to be told it is over.',
                    ],
                    'locale' => [
                        'type' => ['string', 'null'],
                        'description' => 'BCP-47, as the client reported it. Null means "we were not '
                            .'told", which is different from `en`.',
                    ],
                    'consent_required' => ['type' => 'boolean'],
                    'consent_text' => [
                        'type' => ['string', 'null'],
                        'description' => 'The wording shown at creation, snapshotted. A later edit '
                            .'to the bot cannot change it.',
                    ],
                    'consent_granted_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'Null when consent was never asked for or not yet given.',
                    ],
                    'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'last_activity_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'Moves when a message is SENT, not only when a turn '
                            .'settles, so an abandoned mid-answer conversation does not age as '
                            .'though nobody had touched it.',
                    ],
                ],
            ],
        ];
    }
}

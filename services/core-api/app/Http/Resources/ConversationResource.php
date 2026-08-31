<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One conversation thread, as an authenticated admin reviewer sees it. THE HEADER ONLY — no
 * messages, no counts.
 *
 * ── WHY THERE IS NO `message_count` ON THE LIST ROW ─────────────────────────────────────────
 *
 * It is one aggregate against `messages` per row, so a page of twenty-five would be twenty-six
 * statements — the same arithmetic that keeps `SourceResource` free of the four counts
 * `SourceDetailResource` carries. The transcript endpoint is where the messages are, and §8.23's
 * dashboard is where the totals are.
 *
 * ── `anonymous_session_id` IS PUBLISHED, AND IT IS NOT A BEARER VALUE ───────────────────────
 *
 * This needs stating because the column's own migration comment calls a session id "a bearer value"
 * while pinning its grammar, and reading that sentence alone would make publishing it look like a
 * credential disclosure. What is STORED is not the bearer: `RuntimeConversationController` writes
 * `WidgetSession::$sessionId`, which is `substr(hash('sha256', $secret), 0, 32)` — "derivable from
 * the token, useless without it. The TOKEN never reaches a column." The same value is already used
 * as a rate-limit subject and written to log lines for that reason. It is published here because it
 * is the only handle an operator has on an anonymous visitor: it is what `?session_id=` filters on,
 * and without it the filter would accept a value no response ever supplies.
 *
 * ── `consent_text_snapshot` IS PUBLISHED IN FULL, AND THAT IS THE POINT OF THE COLUMN ───────
 *
 * `bots.consent_text` is editable, so a record of "consent: true" would be a record of agreement to
 * whatever the bot says today. The snapshot is what the visitor was actually shown, copied at the
 * moment they were shown it, and a reviewer answering a complaint needs the wording rather than the
 * flag.
 *
 * ── NOTHING HERE IS A CREDENTIAL AND NOTHING HERE IS MODEL OUTPUT ───────────────────────────
 *
 * Every field is a column of `conversations`: two identifiers, two closed vocabularies, a locale,
 * the consent record and four timestamps. The untrusted text — the visitor's questions, the model's
 * answers, the retrieved excerpts — is all one table down, on `TranscriptMessageResource`, which
 * carries the sanitizer obligation.
 */
final class ConversationResource extends JsonResource implements ProvidesOpenApiSchema
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
            'bot_id' => $conversation->bot_id,
            'channel' => $conversation->channel->value,
            'status' => $conversation->status->value,
            // EXACTLY ONE OF THESE TWO IS POPULATED —
            // `conversations_participant_exclusive` refuses both and neither.
            'user_id' => $conversation->user_id,
            'anonymous_session_id' => $conversation->anonymous_session_id,
            'locale' => $conversation->locale,
            'consent_required' => $conversation->consent_required,
            'consent_granted_at' => $conversation->consent_granted_at?->toIso8601String(),
            'consent_text_snapshot' => $conversation->consent_text_snapshot,
            'started_at' => $conversation->started_at->toIso8601String(),
            'last_activity_at' => $conversation->last_activity_at->toIso8601String(),
            'retention_expires_at' => $conversation->retention_expires_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ConversationResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One conversation thread — the header, without its messages. A '
                    .'thread belongs to exactly one bot and to exactly one participant, who is '
                    .'either an authenticated user or an anonymous session and never both.',
                'required' => [
                    'id', 'bot_id', 'channel', 'status', 'user_id', 'anonymous_session_id',
                    'locale', 'consent_required', 'consent_granted_at', 'consent_text_snapshot',
                    'started_at', 'last_activity_at', 'retention_expires_at', 'updated_at',
                ],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the conversation.'],
                    'bot_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the bot that answered. NEVER NULL and never '
                            .'nulled: the foreign key is `ON DELETE RESTRICT`, so a bot that has '
                            .'held a conversation cannot be deleted and is ARCHIVED instead — which '
                            .'is what keeps a transcript interpretable after the bot is withdrawn.',
                    ],
                    'channel' => [
                        'type' => 'string',
                        'enum' => ConversationChannel::values(),
                        'description' => 'Which surface the visitor used. Derived server-side from '
                            .'how the caller authenticated, never from a request field — a body '
                            .'field naming the channel would let a widget claim `playground`, which '
                            .'is the actor type that unlocks retrieval diagnostics on the relay.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ConversationStatus::values(),
                        'description' => 'Lifecycle state of the thread.',
                    ],
                    'user_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the authenticated participant, or null for an '
                            .'anonymous visitor. EXACTLY ONE of this and `anonymous_session_id` is '
                            .'populated: a signed-in visitor on hosted chat also carries a session '
                            .'cookie, so "both" is reachable and is the state that would make one '
                            .'person count twice in the unique-session figure.',
                    ],
                    'anonymous_session_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'The anonymous visitor\'s session handle, or null for an '
                            .'authenticated participant. IT IS NOT A CREDENTIAL: what is stored is a '
                            .'one-way digest of the session bearer — derivable from the token, '
                            .'useless without it — and the token itself never reaches a column. It '
                            .'is the value `?session_id=` filters on.',
                    ],
                    'locale' => [
                        'type' => ['string', 'null'],
                        'description' => 'BCP-47 tag the client declared. Null means "we were not '
                            .'told", which is a different fact from `en`.',
                    ],
                    'consent_required' => [
                        'type' => 'boolean',
                        'description' => 'Whether this bot was collecting end-user data at the '
                            .'moment the thread opened.',
                    ],
                    'consent_granted_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'When the visitor agreed. Null when consent was never '
                            .'asked for; it can never be set while `consent_required` is false.',
                    ],
                    'consent_text_snapshot' => [
                        'type' => ['string', 'null'],
                        'description' => 'The exact wording the visitor was shown, copied at the '
                            .'moment they were shown it. Present whenever `consent_required` is '
                            .'true. The bot\'s live consent text is editable, so recording only the '
                            .'boolean would make the record mean "they agreed to whatever it says '
                            .'today". OPERATOR-AUTHORED TEXT: escape it at render.',
                    ],
                    'started_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When the thread was opened. THIS IS THE CREATION TIME — '
                            .'there is no separate `created_at` on this record, because two columns '
                            .'holding one fact is how they end up disagreeing.',
                    ],
                    'last_activity_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When a turn was last TAKEN. A different fact from '
                            .'`updated_at`, which also moves on a status flip or a consent record; '
                            .'this is the column the idle sweeper and the list\'s default sort read, '
                            .'and it is never before `started_at`.',
                    ],
                    'retention_expires_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'When the retention sweeper may remove this thread and '
                            .'everything under it. Resolved from the bot\'s retention setting at '
                            .'CREATION, so a later edit cannot retroactively shorten or extend a '
                            .'conversation somebody has already had. Null means the organization\'s '
                            .'own policy decides.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Moves on any column change.',
                    ],
                ],
            ],
        ];
    }
}

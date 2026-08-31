<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One transcript entry, as its own participant may re-read it.
 *
 * ═══ FOUR COLUMNS ARE DELIBERATELY ABSENT AND EACH ONE IS COST OR TOPOLOGY ═════════════════
 *
 *   `provider_call_id`   names the attempt that settled the turn, which is the join key to token
 *                        counts, latencies and the vendor's own request id. Cost data stops at
 *                        Laravel — the same rule that keeps `provider.usage` off the wire.
 *   `parent_message_id`  is the retry graph. Publishing it tells a visitor how many times the
 *                        platform tried, which is internal reliability information.
 *   `conversation_id`    is already in the path the caller used to get here.
 *   `client_message_id`  is the caller's own value coming back; echoing it would make the field look
 *                        like a server-assigned identity that other requests could address.
 *
 * ═══ `content` IS MODEL OUTPUT DOWNSTREAM OF RETRIEVED SOURCE TEXT ════════════════════════
 *
 * Which is attacker-controlled (non-negotiable 7). It is NOT sanitized here, because the renderer is
 * the boundary and only the renderer knows the context — and because ONE renderer serves the hosted
 * chat, the widget, mobile AND the admin conversation-review screens. An administrator reading a
 * stored conversation is reading attacker-controlled text in a session with real privileges, so the
 * admin UI must not get a more permissive renderer than the widget.
 */
final class RuntimeMessageResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(Message $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->resource;

        return [
            'id' => $message->id,
            'role' => $message->role->value,
            // NULL is a real value: a turn that failed before producing text has none, and
            // `messages_content_not_blank` refuses the empty-string spelling of the same fact.
            'content' => $message->content,
            'status' => $message->status->value,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'RuntimeMessageResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One transcript entry. Carries no provider call, no retry parent '
                    .'and no cost data — those stop at Laravel.',
                'required' => ['id', 'role', 'content', 'status', 'created_at'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID. For an assistant turn it is the same id the '
                            .'`message.start` and `message.complete` SSE frames carried, so a '
                            .'streamed answer and its re-read entry are the same row.',
                    ],
                    'role' => [
                        'type' => 'string',
                        'enum' => ['user', 'assistant', 'system'],
                    ],
                    'content' => [
                        'type' => ['string', 'null'],
                        'description' => 'MODEL OUTPUT, downstream of retrieved source content, '
                            .'which is attacker-controlled. Render it through the shared sanitizer '
                            .'— the same one on every surface including the admin console — and '
                            .'never auto-load an image it names. Null when the turn produced no '
                            .'text; never an empty string.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['pending', 'streaming', 'complete', 'failed', 'cancelled'],
                        'description' => 'Only settled entries appear in a transcript. `cancelled` '
                            .'is a normal outcome — a closed tab — and is not an error; the tokens '
                            .'generated before it were still billed.',
                    ],
                    'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
        ];
    }
}

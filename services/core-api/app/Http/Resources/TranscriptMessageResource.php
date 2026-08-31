<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Citation;
use App\Models\Feedback;
use App\Models\ProviderCall;
use App\Services\Conversations\TranscriptTurn;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One transcript entry as an admin reviewer sees it: the message, plus everything the four child
 * tables recorded about it.
 *
 * ═══ IT IS DELIBERATELY WIDER THAN `RuntimeMessageResource`, IN EXACTLY FOUR DIRECTIONS ══════
 *
 * That resource lists four columns it omits and gives the reason for each. Three of them come back
 * here and the fourth does not, and the split is the difference between the two audiences:
 *
 *   `provider_call_id`     RETURNED, as `settling_provider_call_id`, plus the whole attempt list.
 *                          The runtime withholds it because "cost data stops at Laravel" — a rule
 *                          about END USERS. An operator is the party being billed.
 *   `parent_message_id`    RETURNED. The runtime withholds the retry graph because telling a
 *                          visitor how many times the platform tried is internal reliability
 *                          information. It is exactly what an operator is looking at.
 *   the four child tables  RETURNED. §6.5 gives an Analyst "Review source citations and retrieval
 *                          traces" in as many words.
 *   `client_message_id`    STILL WITHHELD, and not because it is sensitive. It is the caller's own
 *                          value coming back, and echoing it makes it look like a server-assigned
 *                          identity that other requests could address. `id` is that identity.
 *
 * ═══ UNSETTLED ROWS APPEAR HERE AND DO NOT APPEAR ON THE RUNTIME ════════════════════════════
 *
 * A `pending` or `streaming` message would render as a blank bubble that never fills for a visitor
 * polling their own transcript, so the runtime filters them out. "Which turns are still open" is an
 * operational question with a partial index behind it (`messages_unsettled`), and a turn that died
 * mid-stream is precisely what somebody opens this screen to look at — so the admin transcript
 * carries every status and `content` is legitimately null on the rows that have not produced text.
 *
 * ═══ `content` IS MODEL OUTPUT DOWNSTREAM OF RETRIEVED SOURCE TEXT ══════════════════════════
 *
 * Which is attacker-controlled (non-negotiable 7). It is NOT sanitized here, because the renderer is
 * the boundary and only the renderer knows the context — and because ONE renderer serves the hosted
 * chat, the widget, mobile AND this screen. An administrator reading a stored conversation is
 * reading attacker-controlled text in a session with real privileges, so the admin UI must not get a
 * more permissive renderer than the widget gets.
 */
final class TranscriptMessageResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @property-read TranscriptTurn $resource
     */
    public function __construct(TranscriptTurn $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TranscriptTurn $turn */
        $turn = $this->resource;
        $message = $turn->message;

        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'status' => $message->status->value,
            'parent_message_id' => $message->parent_message_id,
            'settling_provider_call_id' => $message->provider_call_id,
            'created_at' => $message->created_at->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
            'citations' => array_map(
                fn (Citation $citation): array => (new TranscriptCitationResource($citation))->toArray($request),
                $turn->citations,
            ),
            'retrieval_trace' => $turn->trace === null
                ? null
                : (new RetrievalTraceResource($turn->trace))->toArray($request),
            'feedback' => array_map(
                fn (Feedback $feedback): array => (new TranscriptFeedbackResource($feedback))->toArray($request),
                $turn->feedback,
            ),
            'provider_calls' => array_map(
                fn (ProviderCall $call): array => (new ProviderCallResource($call))->toArray($request),
                $turn->providerCalls,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return TranscriptCitationResource::openApiSchemas()
            + RetrievalTraceResource::openApiSchemas()
            + TranscriptFeedbackResource::openApiSchemas()
            + ProviderCallResource::openApiSchemas()
            + [
                'TranscriptMessageResource' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'One transcript entry with its citations, its retrieval trace, '
                        .'its feedback and every provider attempt behind it. Wider than the '
                        .'runtime\'s message shape in three directions — cost, the retry graph, and '
                        .'the diagnostics — because the audience is the party that is billed for '
                        .'the call and accountable for the answer.',
                    'required' => [
                        'id', 'role', 'content', 'status', 'parent_message_id',
                        'settling_provider_call_id', 'created_at', 'updated_at', 'citations',
                        'retrieval_trace', 'feedback', 'provider_calls',
                    ],
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'ULID. For an assistant turn it is the same id the '
                                .'`message.start` and `message.complete` SSE frames carried, so a '
                                .'streamed answer and its stored entry are the same row.',
                        ],
                        'role' => ['type' => 'string', 'enum' => MessageRole::values()],
                        'content' => [
                            'type' => ['string', 'null'],
                            'description' => 'MODEL OUTPUT, downstream of retrieved source content, '
                                .'which is attacker-controlled. Render it through the shared '
                                .'sanitizer — the same one on every surface INCLUDING THIS ONE — '
                                .'and never auto-load an image it names. Null when the turn has not '
                                .'produced text: a `pending` turn, or one that failed before saying '
                                .'anything. Never an empty string.',
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => MessageStatus::values(),
                            'description' => 'EVERY status appears on this surface, unlike the '
                                .'runtime transcript: `pending` and `streaming` are turns still in '
                                .'flight, and a stuck one is what an operator opens this screen to '
                                .'find. `cancelled` is a normal outcome — a closed tab — and the '
                                .'tokens generated before it were still billed.',
                        ],
                        'parent_message_id' => [
                            'type' => ['string', 'null'],
                            'description' => 'Set when this turn is a RETRY of an earlier one, and '
                                .'null otherwise. It means retry and nothing else — it is NOT "the '
                                .'question this answers", which is deliberately not a column: '
                                .'overloading it would make a resend indistinguishable from a '
                                .'retry in every later read. The parent is always in this same '
                                .'conversation, by composite foreign key.',
                        ],
                        'settling_provider_call_id' => [
                            'type' => ['string', 'null'],
                            'description' => 'Which of the attempts in `provider_calls` actually '
                                .'settled this turn — the last one, when a fallback was reached. '
                                .'Null on a user or system turn, which cost nothing, and on an '
                                .'assistant turn that never reached a provider.',
                        ],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'updated_at' => [
                            'type' => ['string', 'null'],
                            'format' => 'date-time',
                            'description' => 'An assistant row is written three times in a normal '
                                .'streaming turn — pending, streaming, settled — so this is "when '
                                .'the answer finished" and `created_at` is "when the turn opened". '
                                .'The gap between them is the wall clock the visitor waited.',
                        ],
                        'citations' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/TranscriptCitationResource'],
                            'description' => 'The evidence behind this answer, in label order. '
                                .'Empty for a user turn, for a system notice, and for an answer '
                                .'given with no retrieval.',
                        ],
                        'retrieval_trace' => [
                            'anyOf' => [
                                ['$ref' => '#/components/schemas/RetrievalTraceResource'],
                                ['type' => 'null'],
                            ],
                            'description' => 'Why this answer said what it said. At most one per '
                                .'message. Null on a user turn, a system notice, and any assistant '
                                .'turn whose retrieval never ran — a turn that failed before the '
                                .'pipeline reached the trace-writing stage has none, which is '
                                .'itself diagnostic.',
                        ],
                        'feedback' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/TranscriptFeedbackResource'],
                            'description' => 'Verdicts left on this answer, oldest first. At most '
                                .'one per submitter; a thread can carry the visitor\'s thumb and a '
                                .'reviewer\'s separately.',
                        ],
                        'provider_calls' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/ProviderCallResource'],
                            'description' => 'Every attempt behind this turn, oldest first — one on '
                                .'a normal answer, more when a fallback was reached. This is where '
                                .'the turn\'s latency, its token counts and its fallback events '
                                .'live. Empty for a user turn and for a system notice, both of '
                                .'which cost nothing.',
                        ],
                    ],
                ],
            ];
    }
}

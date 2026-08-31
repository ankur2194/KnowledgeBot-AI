<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Services\Conversations\ConversationTranscript;
use App\Services\Conversations\TranscriptTurn;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * One conversation with everything that was said in it — the shape
 * `GET .../conversations/{conversation}` returns.
 *
 * ── WHY THERE ARE TWO COMPONENTS AND NOT ONE WIDER ONE ──────────────────────────────────────
 *
 * `ConversationResource` is what a LIST row is, and its fields are all columns of one table.
 * Everything added here costs five extra statements against `messages`, `citations`,
 * `retrieval_traces`, `feedback` and `provider_calls`. Folding them into the list would turn a page
 * of twenty-five threads into a hundred and twenty-six queries and a body measured in megabytes, so
 * the transcript is its own component and the list is untouched. The same split
 * `SourceDetailResource` makes against `SourceResource`, for the same arithmetic.
 *
 * THE HEADER FIELDS ARE DERIVED FROM `ConversationResource` RATHER THAN RESTATED, in `toArray()`
 * and in `openApiSchemas()` alike, so a field added to the list row reaches the transcript with no
 * second edit and the two can never disagree about a description.
 *
 * ── `messages_truncated` IS PUBLISHED AND IS NOT AN IMPLEMENTATION DETAIL ───────────────────
 *
 * `ConversationReader::MAX_MESSAGES` bounds the read, because every entry drags its citations, its
 * feedback, its provider attempts and a retrieval trace carrying a whole candidate list. A cap a
 * client cannot detect is worse than no cap: a reviewer would see the thread end mid-argument and
 * conclude the conversation ended there, which on a surface whose entire purpose is answering "what
 * did we actually tell this customer" is the one wrong answer that matters.
 *
 * ── EVERYTHING BELOW `conversation` IS UNTRUSTED TEXT ───────────────────────────────────────
 *
 * Visitor questions, model answers, quoted document passages, feedback comments and the rewritten
 * query are all attacker-influenced. None of it is escaped here — escaping belongs to the renderer,
 * which is the only layer that knows the context the string is entering — and the renderer that
 * serves this screen is the SAME one that serves the widget. An administrator reading a stored
 * conversation is reading hostile data in a session with real privileges.
 */
final class ConversationTranscriptResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(
        Conversation $resource,
        private readonly ConversationTranscript $transcript,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = (new ConversationResource($this->resource))->toArray($request);

        $added = [
            'messages' => array_map(
                fn (TranscriptTurn $turn): array => (new TranscriptMessageResource($turn))->toArray($request),
                $this->transcript->turns,
            ),
            'messages_truncated' => $this->transcript->truncated,
        ];

        // `+` IS LEFT-WINS AND SILENT, WHICH IS WHY THIS LINE EXISTS. See `refuseCollisions()`.
        self::refuseCollisions($base, $added, 'toArray()');

        return $base + $added;
    }

    /**
     * Refuse a key that both halves of this projection declare, rather than silently dropping one.
     *
     * PHP'S `+` ON ARRAYS IS LEFT-WINS AND SAYS NOTHING. The header fields are DERIVED from
     * `ConversationResource` rather than restated — in the payload and in the schema alike — so a
     * field added to the list reaches the transcript with no second edit. The cost of that
     * derivation is this: if a name ever appeared in both halves, the base value would win and this
     * resource's own declaration would be discarded with nothing raised, and the only trace would
     * be a DUPLICATED entry in the schema's `required` array (which is a spread, not a `+`) — which
     * is invalid JSON Schema and is asserted by nothing. `SourceDetailResource` carries the same
     * guard for the same reason, recorded there as finding S4.
     *
     * A `RuntimeException` and not a client-visible refusal: this is unreachable by anything a
     * caller sends, so the audience is the person editing one of the two resources and the correct
     * outcome is that their test run stops.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $added
     */
    private static function refuseCollisions(array $base, array $added, string $where): void
    {
        $collisions = array_keys(array_intersect_key($added, $base));

        if ($collisions === []) {
            return;
        }

        throw new RuntimeException(
            'ConversationTranscriptResource::'.$where.' declares '.implode(', ', $collisions)
            .', which ConversationResource already declares. The two are composed with `+`, which '
            .'is left-wins and silent, so the transcript\'s declaration would be discarded with '
            .'nothing raised. Remove the duplicate from whichever of the two should not own the '
            .'field — the list row is the cheaper place only for values that are columns of '
            .'`conversations`.',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        $base = ConversationResource::openApiSchemas();

        /** @var array<string, mixed> $conversation */
        $conversation = $base['ConversationResource'];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($conversation['properties'] ?? null) ? $conversation['properties'] : [];

        /** @var list<string> $required */
        $required = is_array($conversation['required'] ?? null) ? array_values($conversation['required']) : [];

        $added = [
            'messages' => [
                'type' => 'array',
                'items' => ['$ref' => '#/components/schemas/TranscriptMessageResource'],
                'description' => 'The thread, oldest first, in a total order — `(created_at, id)`. '
                    .'The tie-break is not decoration: a user turn and the pending assistant row it '
                    .'opens are written in one transaction and can share a microsecond, so without '
                    .'it a second read may legally return the answer before the question. EVERY '
                    .'status is included, including turns still in flight.',
            ],
            'messages_truncated' => [
                'type' => 'boolean',
                'description' => 'True when the thread is longer than one transcript read returns '
                    .'and the tail was left unread. It is published rather than inferred from the '
                    .'array length, because a client cannot tell a capped read from a conversation '
                    .'that happened to be exactly that long — and a transcript that ends '
                    .'mid-argument reads as a conversation that ended there.',
            ],
        ];

        self::refuseCollisions($properties, $added, 'openApiSchemas()');

        return $base
            + TranscriptMessageResource::openApiSchemas()
            + [
                'ConversationTranscriptResource' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'One conversation and everything that was said in it: every '
                        .'field of the list row, plus the messages with their citations, retrieval '
                        .'traces, feedback and provider attempts. EVERYTHING BELOW THE HEADER IS '
                        .'UNTRUSTED TEXT — visitor questions, model output and quoted document '
                        .'passages — and renders through the same sanitizer as the widget\'s, '
                        .'because an administrator reads it in a session with real privileges.',
                    'required' => [...$required, ...array_keys($added)],
                    'properties' => $properties + $added,
                ],
            ];
    }
}

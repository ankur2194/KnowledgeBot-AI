<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BotStarterQuestion;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One suggested starter question on the wire.
 *
 * ── THE TEXT IS TENANT-AUTHORED AND IS PUBLISHED EXACTLY AS STORED ────────────────────────────
 *
 * No escaping, no truncation, no sanitisation. That is the correct call and it puts an obligation
 * on every consumer: `question` reaches the chat surface as a suggestion chip, the widget iframe,
 * the mobile app and the admin console's own editor, and it is escaped AT EACH OF THEM, because
 * only the renderer knows which context it is entering. Escaping here would be wrong twice — it
 * would show an operator something other than what they typed in the editor, and it would produce
 * double-escaped text wherever a client escaped it again.
 *
 * `sort_order` IS PUBLISHED AND IS ZERO-BASED, matching the column and the database, so nobody has
 * to remember which layer counts from one. It is also an INVARIANT rather than a free value: every
 * write on this surface re-sequences the whole list to 0..n-1 with no gaps, so a client can render
 * from it directly and a gap in a response is a bug rather than a state.
 *
 * ── WHAT IS NOT RENDERED ──────────────────────────────────────────────────────────────────────
 *
 * `organization_id` and `bot_id`, both for the reason `BotDomainResource` records: they are in the
 * path already, and the second is the ownership edge inside the tenant.
 *
 * @property-read BotStarterQuestion $resource
 */
final class BotStarterQuestionResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(BotStarterQuestion $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->resource;

        return [
            'id' => $question->id,
            'question' => $question->question,
            'sort_order' => $question->sort_order,
            'created_at' => $question->created_at?->toIso8601String(),
            'updated_at' => $question->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'BotStarterQuestionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One suggested starter question, at the position the operator set.',
                'required' => ['id', 'question', 'sort_order', 'created_at', 'updated_at'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the question.',
                    ],
                    'question' => [
                        'type' => 'string',
                        'description' => 'The chip label, exactly as the operator typed it. '
                            .'TENANT-AUTHORED TEXT: escape it at render, in every client, because '
                            .'only the renderer knows the context it is entering. Never blank — '
                            .'the database refuses a whitespace-only value, since a chip with no '
                            .'label is a control an end user can see, can click, and cannot read.',
                    ],
                    'sort_order' => [
                        'type' => 'integer',
                        'description' => 'Zero-based position, unique within the bot. The set of '
                            .'positions is always 0..n-1 with no gaps: every write re-sequences '
                            .'the whole list inside one transaction, so render straight from this '
                            .'and treat a gap as a defect rather than a state.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Moves when the text or the position '
                            .'changes — including when another question was moved past this one, '
                            .'because a reorder rewrites every row in the list.',
                    ],
                ],
            ],
        ];
    }
}

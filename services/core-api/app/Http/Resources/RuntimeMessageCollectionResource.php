<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One conversation's permitted history.
 *
 * AN OBJECT WRAPPING THE ARRAY rather than a bare array, so fields can join it later — a cursor, a
 * truncation flag — without moving the list and breaking every client's index.
 *
 * IT IS NOT PAGINATED AND IT IS BOUNDED INSTEAD, which is a deliberate difference from the admin
 * list endpoints. A visitor re-reading their own conversation wants the whole thing in order, and a
 * conversation long enough to need pages is one whose earlier turns the model itself has already
 * dropped from its window. The bound is what keeps an unauthenticated caller from asking for an
 * unbounded read.
 */
final class RuntimeMessageCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<Message>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'messages' => array_map(
                static fn (Message $m): array => (new RuntimeMessageResource($m))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return RuntimeMessageResource::openApiSchemas() + [
            'RuntimeMessageCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The settled entries of one conversation, oldest first. Entries '
                    .'still in flight are omitted: a `pending` row has no content and would render '
                    .'as a bubble that never fills, because this endpoint is a poll and not a '
                    .'stream.',
                'required' => ['messages'],
                'properties' => [
                    'messages' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/RuntimeMessageResource'],
                        'description' => 'Ordered by creation, then by id — deterministic, so two '
                            .'reads of an unchanged conversation are byte-identical.',
                    ],
                ],
            ],
        ];
    }
}

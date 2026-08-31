<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Models\Conversation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PAGE of an organization's conversation threads.
 *
 * ── THE BODY, EXACTLY ─────────────────────────────────────────────────────────────────────────
 *
 *     { "data": { "conversations": [ …rows… ],
 *                 "meta": { "page": 2, "per_page": 25, "total": 137, "total_pages": 6,
 *                           "sort": "last_activity_at", "dir": "desc", "filter": null } } }
 *
 * `meta` is a SIBLING OF THE COLLECTION INSIDE `data` — the shape `apps/web/src/lib/table/
 * envelope.ts` reads and THROWS rather than degrading when it cannot, because an unreadable
 * envelope is not an empty list.
 *
 * `meta.filter` IS ALWAYS NULL ON THIS ENDPOINT and that is not a defect. There is no free-text
 * `filter` parameter here — `IndexConversationsRequest` passes `freeText: false`, because the
 * columns a reviewer narrows by are all indexed equalities and ranges and the only prose in a
 * thread lives one table down in `messages`. The key stays present because `meta` is the SHARED
 * `ListMetaResource` component and a client that had to branch on its presence would be branching
 * on which endpoint it called.
 *
 * The item schema is CONTRIBUTED by `ConversationResource` rather than re-declared, so the item
 * type is ONE component in the generated client — the same component the transcript endpoint
 * composes its header from. The dumper compares bodies when a component name appears twice, so an
 * identical contribution is a no-op and a divergent one is a build failure.
 *
 * ── NO PROJECTION FLAG ───────────────────────────────────────────────────────────────────────
 *
 * `BotCollectionResource` carries a `$withInstructions` flag because two of its fields are gated on
 * `bots.manage`, and asking the Gate per row would be one `organization_users` read per row.
 * Nothing on a conversation header is permission-projected: `conversations.view` is the whole of
 * what it takes to read every field here. If one is ever added, resolve it ONCE in the controller
 * and pass it — never inside the `array_map`.
 *
 * @property-read LengthAwarePaginator<int, Conversation> $resource
 */
final class ConversationCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * @param  LengthAwarePaginator<int, Conversation>  $resource
     */
    public function __construct(
        LengthAwarePaginator $resource,
        private readonly ListQuery $query,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // `->items()` and not the paginator itself: the envelope publishes an ARRAY under its
            // key, and a paginator serialized directly would carry Laravel's own `links`/`meta`
            // shape, which is not the one this API publishes and not the one the console reads.
            'conversations' => array_map(
                fn (Conversation $conversation): array => (new ConversationResource($conversation))->toArray($request),
                array_values($this->resource->items()),
            ),
            'meta' => $this->listMeta($this->resource, $this->query, $request),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return ConversationResource::openApiSchemas() + self::listEnvelopeSchemas(
            component: 'ConversationCollectionResource',
            key: 'conversations',
            itemComponent: 'ConversationResource',
            description: 'One page of an organization\'s conversation threads, with the pagination '
                .'and applied-query state beside it. `meta.filter` is always null here: this '
                .'endpoint accepts no free-text term, and the key remains because `meta` is one '
                .'shared component across every list in this API.',
            itemsDescription: 'The threads on this page, in the applied order — by default the most '
                .'recently active first, which is what a reviewer opens the screen to see. EVERY '
                .'status is included, including `expired` threads whose retention sweep has marked '
                .'them and threads still in flight. The set is scoped to the organization in the '
                .'path and to nothing else.',
        );
    }
}

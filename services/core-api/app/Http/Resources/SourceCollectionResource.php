<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Models\KnowledgeSource;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PAGE of an organization's knowledge sources.
 *
 * ── THE BODY, EXACTLY ─────────────────────────────────────────────────────────────────────────
 *
 *     { "data": { "sources": [ …rows… ],
 *                 "meta": { "page": 2, "per_page": 25, "total": 137, "total_pages": 6,
 *                           "sort": "name", "dir": "asc", "filter": null } } }
 *
 * `meta` is a SIBLING OF THE COLLECTION INSIDE `data`, and that is not this class's to revisit:
 * `apps/web/src/lib/table/envelope.ts` reads exactly this shape and THROWS rather than degrading
 * when it cannot, because an unreadable envelope is not an empty list — `{rows: [], rowCount: 0}`
 * would render the first-run "Add your first source" state to an administrator whose organization
 * has two hundred documents, which is the moment somebody uploads them all again.
 *
 * The item schema is CONTRIBUTED by `SourceResource` rather than re-declared, so the item type is
 * ONE component in the generated client — the same component the create, read and update actions
 * all return. The dumper compares bodies when a component name appears twice, so an identical
 * contribution is a no-op and a divergent one is a build failure.
 *
 * ── NO PROJECTION FLAG, UNLIKE THE BOT ENVELOPE ──────────────────────────────────────────────
 *
 * `BotCollectionResource` carries a `$withInstructions` flag because two of its fields are gated on
 * `bots.manage` and asking the Gate per row would be one `organization_users` read per row. Nothing
 * on a source is permission-projected: `sources.view` is the whole of what it takes to read every
 * field here, so there is no per-row question and no flag to carry. If one is ever added, resolve
 * it ONCE in the controller and pass it — never inside the `array_map`.
 *
 * @property-read LengthAwarePaginator<int, KnowledgeSource> $resource
 */
final class SourceCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * @param  LengthAwarePaginator<int, KnowledgeSource>  $resource
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
            'sources' => array_map(
                fn (KnowledgeSource $source): array => (new SourceResource($source))->toArray($request),
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
        return SourceResource::openApiSchemas() + self::listEnvelopeSchemas(
            component: 'SourceCollectionResource',
            key: 'sources',
            itemComponent: 'SourceResource',
            description: 'One page of an organization\'s knowledge sources, with the pagination and '
                .'applied-query state beside it. `meta` is present on an empty page too — a client '
                .'that had to branch on its absence would be branching on "did this list have '
                .'results", which is exactly the question `total` answers.',
            itemsDescription: 'The sources on this page, in the applied order. EVERY lifecycle '
                .'state is included, including sources whose purge is in flight: "where did that '
                .'source go" is asked precisely while a deletion is running, and hiding the row '
                .'would make the question unanswerable from the console while the record still '
                .'existed. The set is scoped to the organization in the path and to nothing else.',
        );
    }
}

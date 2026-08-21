<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * One PAGE of the sources one bot may answer from.
 *
 * ── THE BODY, EXACTLY ─────────────────────────────────────────────────────────────────────────
 *
 *     { "data": { "source_assignments": [ …rows… ],
 *                 "meta": { "page": 1, "per_page": 25, "total": 3, "total_pages": 1,
 *                           "sort": "priority", "dir": "asc", "filter": null } } }
 *
 * `meta` is a SIBLING OF THE COLLECTION INSIDE `data`, and that is not this class's to revisit:
 * `apps/web/src/lib/table/envelope.ts` reads exactly this shape and THROWS rather than degrading
 * when it cannot, because an unreadable envelope is not an empty list.
 *
 * ── THE SOURCE COMES FROM THE EAGER LOAD AND ITS ABSENCE IS AN INVARIANT VIOLATION ───────────
 *
 * `EloquentBotSourceAssignmentRepository::paginate()` eager-loads `source` with an explicit
 * organization predicate, so every row on this page already holds its source and this class issues
 * no query. A missing one is not a rendering case to degrade around: the composite foreign key
 * `bot_source_assignments_source_same_org` refuses an assignment whose `(organization_id,
 * source_id)` does not name a live `knowledge_sources` row, so a null here means either the eager
 * load was dropped or the constraint is gone — and both are worth an exception rather than a
 * response with a hole in it.
 *
 * ── NO PROJECTION FLAG ────────────────────────────────────────────────────────────────────────
 *
 * `BotCollectionResource` carries a `$withInstructions` flag because two of its fields are gated on
 * `bots.manage` and asking the Gate per row would be one `organization_users` read per row. Nothing
 * on an assignment or on a source is permission-projected — reading either takes one permission and
 * publishes every field — so there is no per-row question and no flag to carry. If one is ever
 * added, resolve it ONCE in the controller and pass it, never inside the `array_map`.
 *
 * @property-read LengthAwarePaginator<int, BotSourceAssignment> $resource
 */
final class BotSourceAssignmentCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * @param  LengthAwarePaginator<int, BotSourceAssignment>  $resource
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
            'source_assignments' => array_map(
                function (BotSourceAssignment $assignment) use ($request): array {
                    $source = $assignment->source;

                    if (! $source instanceof KnowledgeSource) {
                        throw new RuntimeException(
                            'A bot_source_assignments row rendered with no source. Either the '
                            .'repository stopped eager-loading `source` with its organization '
                            .'predicate, or `bot_source_assignments_source_same_org` is no longer '
                            .'on the table — and that constraint is one half of the guard against '
                            .'the one row in this schema that can span two organizations. '
                            .'Assignment: '.$assignment->id,
                        );
                    }

                    return (new BotSourceAssignmentResource($assignment, $source))->toArray($request);
                },
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
        return BotSourceAssignmentResource::openApiSchemas() + self::listEnvelopeSchemas(
            component: 'BotSourceAssignmentCollectionResource',
            key: 'source_assignments',
            itemComponent: 'BotSourceAssignmentResource',
            description: 'One page of the grants that decide which knowledge sources this bot may '
                .'answer from, with the pagination and applied-query state beside it. `meta` is '
                .'present on an empty page too — a client that had to branch on its absence would '
                .'be branching on "did this list have results", which is exactly the question '
                .'`total` answers.',
            itemsDescription: 'The grants on this page, in the applied order. DISABLED GRANTS ARE '
                .'INCLUDED: a disabled row grants nothing, and hiding it would make "why is this '
                .'bot not answering from that document" unanswerable from the console while the '
                .'row sat in the table. The set is scoped to the organization and the bot in the '
                .'path and to nothing else.',
        );
    }
}

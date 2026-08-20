<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Models\Bot;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PAGE of an organization's bots — the first paginated envelope this API publishes.
 *
 * ── THE BODY, EXACTLY ─────────────────────────────────────────────────────────────────────────
 *
 *     { "data": { "bots": [ …rows… ],
 *                 "meta": { "page": 2, "per_page": 25, "total": 137, "total_pages": 6,
 *                           "sort": "name", "dir": "asc", "filter": null } } }
 *
 * `meta` is a SIBLING OF THE COLLECTION INSIDE `data`, not a sibling of `data`, and that is not a
 * detail this class is free to revisit: `apps/web/src/lib/table/envelope.ts` reads exactly this
 * shape for every table in the admin console and THROWS rather than degrading when it cannot,
 * because an envelope nobody can read is not an empty list — returning `{rows: [], rowCount: 0}`
 * would render the first-run "Create your first bot" state to an administrator whose organization
 * has two hundred of them.
 *
 * ── AN OBJECT WRAPPING THE ARRAY, NEVER A BARE ARRAY ──────────────────────────────────────────
 *
 * The reason is mechanical rather than stylistic and is already written out in
 * `InvitationCollectionResource` and `ProviderConnectionCollectionResource`: `#[ResponseShape]`
 * maps a response KEY to a resource class, so it cannot express "an array of"; and
 * `tests/Contract/OpenApiDocumentTest.php` requires every published component to carry
 * `additionalProperties: false`, which an array-typed schema cannot. Both of those resources also
 * say the wrapper "leaves room for pagination fields to join later without moving the list" — THIS
 * IS THAT DAY, and the array did not move.
 *
 * ── THE ITEM SCHEMA IS CONTRIBUTED, NOT RE-DECLARED ───────────────────────────────────────────
 *
 * `BotResource::openApiSchemas()` supplies it, so `BotResource` is ONE component in the generated
 * client — the same component the create, read and update actions all return. The dumper compares
 * bodies when a component name appears twice, so an identical contribution is a no-op and a
 * divergent one is a build failure.
 *
 * ── `$withInstructions` IS CARRIED, NOT RE-DECIDED ────────────────────────────────────────────
 *
 * `BotResource` renders the two instruction fields only to a caller holding `bots.manage`, and this
 * class exists on the exact path where getting that wrong is expensive: asking the Gate inside the
 * `array_map` below would be one `organization_users` read PER ROW — `OrgScopedPolicy::permit()`
 * resolves membership per check and is deliberately never memoized — for an answer that cannot
 * differ across rows, because every row on this page belongs to the one organization in the path.
 * `BotController::index()` resolves it once through `Gate::allows('manageBots', $organization)` and
 * hands it here. This class makes no authorization decision of its own and must not start.
 *
 * @property-read LengthAwarePaginator<int, Bot> $resource
 */
final class BotCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * @param  LengthAwarePaginator<int, Bot>  $resource
     * @param  bool  $withInstructions  whether the caller holds `bots.manage` on the organization
     *                                  every row on this page belongs to — resolved ONCE by the
     *                                  controller. Required, never defaulted.
     */
    public function __construct(
        LengthAwarePaginator $resource,
        private readonly ListQuery $query,
        private readonly bool $withInstructions,
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
            'bots' => array_map(
                fn (Bot $bot): array => (new BotResource($bot, $this->withInstructions))
                    ->toArray($request),
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
        return BotResource::openApiSchemas() + self::listEnvelopeSchemas(
            component: 'BotCollectionResource',
            key: 'bots',
            itemComponent: 'BotResource',
            description: 'One page of an organization\'s bots, with the pagination and '
                .'applied-query state beside it. `meta` is present on an empty page too — a client '
                .'that had to branch on its absence would be branching on "did this list have '
                .'results", which is exactly the question `total` answers.',
            itemsDescription: 'The bots on this page, in the applied order. Every lifecycle state '
                .'is included, drafts and archived rows alike: an operator asking "where did that '
                .'bot go" has to be able to find it, and hiding a state would make the question '
                .'unanswerable from the console while the row sat in the table. The set is scoped '
                .'to the organization in the path and to nothing else.',
        );
    }
}

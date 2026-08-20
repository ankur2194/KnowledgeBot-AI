<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Http\Resources\ListMetaResource;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * The paginated half of a collection resource: the `meta` block and the schema that describes it.
 *
 * ── THE ENVELOPE IS AN OBJECT WRAPPING THE ARRAY, NEVER A BARE ARRAY ──────────────────────────
 *
 * The reason is mechanical rather than stylistic and is already written out once in
 * `InvitationCollectionResource`: `#[ResponseShape]` maps a response KEY to a resource class, so it
 * cannot express "an array of"; and `tests/Contract/OpenApiDocumentTest.php` requires every
 * published component to carry `additionalProperties: false`, which an array-typed schema cannot.
 * `ProviderConnectionCollectionResource` states the forward-looking half — "the wrapper leaves room
 * for pagination fields to join later without moving the list" — and THIS TRAIT IS THAT DAY. The
 * body is `{"data": {"<key>": [...], "meta": {...}}}`, and the array did not move.
 *
 * ── A TRAIT AND NOT AN ABSTRACT BASE RESOURCE ─────────────────────────────────────────────────
 *
 * An abstract `PaginatedCollectionResource` would have to fix the item type, the response key and
 * the component name as abstract methods, and every subclass would implement three methods to gain
 * two. More importantly it would put `openApiSchemas()` on a parent, where
 * `OpenApiDocumentTest`'s discovery — `glob(app_path('Http/Resources').'/*.php')`, non-recursive,
 * `is_subclass_of(..., ProvidesOpenApiSchema::class)` — would still find every child and assert
 * against the inherited implementation, so the failure of a subclass that forgot to name its own
 * component would surface as the PARENT's name being missing. A trait keeps the declaration in the
 * file whose `toArray()` it describes, which is the property that whole test file rests on.
 *
 * This directory is NOT scanned by that discovery glob — it is one level down — so nothing here
 * needs to implement `ProvidesOpenApiSchema` and nothing here is published on its own.
 *
 * ── WHAT A CONSUMER WRITES ────────────────────────────────────────────────────────────────────
 *
 *     final class BotCollectionResource extends JsonResource implements ProvidesOpenApiSchema
 *     {
 *         use PaginatedCollection;
 *
 *         public function __construct(LengthAwarePaginator $resource, private readonly ListQuery $query)
 *         { parent::__construct($resource); }
 *
 *         public function toArray(Request $request): array
 *         {
 *             return [
 *                 'bots' => array_map(fn (Bot $b): array => (new BotResource($b))->toArray($request),
 *                                     $this->resource->items()),
 *                 'meta' => $this->listMeta($this->resource, $this->query, $request),
 *             ];
 *         }
 *
 * (The shipped `BotCollectionResource` additionally carries a `$withInstructions` flag into each
 * item, because `BotResource` projects two fields by permission and asking the Gate inside that
 * `array_map` would be one membership read per row. That is the ITEM resource's concern, not this
 * trait's, so the example above stays the minimal shape.)
 *
 *         public static function openApiSchemas(): array
 *         {
 *             return BotResource::openApiSchemas() + self::listEnvelopeSchemas(
 *                 component: 'BotCollectionResource', key: 'bots',
 *                 itemComponent: 'BotResource', description: '...', itemsDescription: '...',
 *             );
 *         }
 *     }
 *
 * The item schema is CONTRIBUTED by the item resource rather than re-declared, so the item type is
 * ONE component in the generated client — the same component the create, read and update actions
 * all return. The dumper compares bodies when a component name appears twice, so an identical
 * contribution is a no-op and a divergent one is a build failure.
 */
trait PaginatedCollection
{
    /**
     * The `meta` block, rendered through `ListMetaResource` so there is exactly one place the
     * pagination shape is decided.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, mixed>
     */
    protected function listMeta(LengthAwarePaginator $paginator, ListQuery $query, Request $request): array
    {
        return (new ListMetaResource($paginator, $query))->toArray($request);
    }

    /**
     * The envelope component, plus the `ListMetaResource` component it references.
     *
     * Returns BOTH so a consumer's `openApiSchemas()` is one `+` of three contributions — the item
     * resource's, the envelope's and the meta block's — and cannot publish an envelope whose
     * `$ref` points at a component nobody contributed. A dangling `$ref` is not a dump failure; it
     * is a generated client with a missing type, discovered by the person importing it.
     *
     * @param  string  $component  the envelope's own component name, i.e. the resource's short class name
     * @param  string  $key  the response key the array lives under
     * @param  string  $itemComponent  the component name of one item
     * @return array<string, array<string, mixed>>
     */
    protected static function listEnvelopeSchemas(
        string $component,
        string $key,
        string $itemComponent,
        string $description,
        string $itemsDescription,
    ): array {
        return ListMetaResource::openApiSchemas() + [
            $component => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => $description,
                // BOTH KEYS REQUIRED, ALWAYS. `meta` is present on an empty page too — a client
                // that had to branch on its absence would be branching on "did this list have
                // results", which is exactly the question `total` answers.
                'required' => [$key, 'meta'],
                'properties' => [
                    $key => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/'.$itemComponent],
                        'description' => $itemsDescription,
                    ],
                    'meta' => [
                        '$ref' => '#/components/schemas/ListMetaResource',
                        'description' => 'Pagination and applied-query state for this page.',
                    ],
                ],
            ],
        ];
    }
}

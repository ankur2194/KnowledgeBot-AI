<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A collection resource that exists only to EXERCISE the pagination concern.
 *
 * WHY THE PRIMITIVE'S ONLY CONSUMER IS A FIXTURE, AND WHY THAT IS THE RIGHT SHAPE HERE.
 * `PaginatedCollection` is a reusable primitive delivered ahead of its first endpoint: the bot list
 * that uses it is the next task's, and Phases C4, D and E2 reuse it after that. A production
 * collection resource written now would have to fix an item type — which is the next task's shape
 * decision, not this one's — so the concern would be published with a consumer somebody else has to
 * unpick.
 *
 * It could instead have shipped with no consumer at all, and that fails for a reason worth writing
 * down: PHPStan reports `trait.unused` and refuses to analyse a trait nothing uses, so an unused
 * concern is a concern whose types are never checked. This probe is what makes the analyser see it.
 *
 * It lives in tests/Support rather than in app/Http/Resources deliberately.
 * `tests/Contract/OpenApiDocumentTest.php` discovers published resources by globbing
 * `app_path('Http/Resources')` for `ProvidesOpenApiSchema` implementors, and a probe found there
 * would be asserted against as though it were a real endpoint's body — and, worse, would be
 * published into `packages/contracts/` the day an endpoint's `#[ResponseShape]` happened to name
 * it. Same reasoning, and the same directory, as ResponseShapeProbe.
 *
 * The item type is `AcknowledgementResource`, chosen because it is the smallest published component
 * in the tree and because reusing an existing one proves the property that matters: the envelope
 * REFERENCES the item component rather than re-declaring it, so an item type is one component in
 * the generated client no matter how many lists carry it.
 *
 * @property-read LengthAwarePaginator<int, mixed> $resource
 */
final class ListEnvelopeProbe extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * @param  LengthAwarePaginator<int, mixed>  $resource
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
            // shape, which is not the one this API publishes.
            'items' => array_values($this->resource->items()),
            'meta' => $this->listMeta($this->resource, $this->query, $request),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return self::listEnvelopeSchemas(
            component: 'ListEnvelopeProbe',
            key: 'items',
            itemComponent: 'AcknowledgementResource',
            description: 'A fixture envelope. Never published: this class is not in '
                .'app/Http/Resources and no #[ResponseShape] names it.',
            itemsDescription: 'The page of items.',
        );
    }
}

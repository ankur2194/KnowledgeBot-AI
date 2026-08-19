<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProviderModelEntry;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One connection's model catalog.
 *
 * AN OBJECT WRAPPING THE ARRAY, never a bare array, and the reason is mechanical rather than
 * stylistic — it is spelled out once in InvitationCollectionResource and holds identically here.
 * `#[ResponseShape]` maps a response KEY to a resource class, so it cannot express "an array of";
 * and tests/Contract/OpenApiDocumentTest.php requires every published component to carry
 * `additionalProperties: false`, which an array-typed schema cannot. Body is
 * `{"data": {"models": [...]}}`.
 *
 * THERE IS NO PAGINATION IN THIS APPLICATION AND THIS IS NOT ADDING ANY. The wrapper exists so
 * `next_cursor` and `total` can join `models` inside the same object later without moving the
 * array or versioning the endpoint — which matters here because a catalog is the list most likely
 * to grow: OpenRouter alone fronts hundreds of upstream models, and an organization that mirrors a
 * meaningful slice of one is the day a bare array would become a breaking change.
 *
 * The item schema is contributed by ProviderModelResource rather than re-declared, so
 * `ProviderModelResource` is ONE component in the generated client — the same component `POST`,
 * `GET /{model}` and `PUT` all return. The dumper compares bodies when a component name appears
 * twice, so an identical contribution is a no-op and a divergent one is a build failure.
 *
 * NOTHING HERE TOUCHES THE VAULT, and nothing here can: the item resource renders no field of the
 * parent connection except its ULID, so a catalog listing performs zero decryptions and has no
 * masked credential to leak even from a fixture whose plaintext really was sealed.
 *
 * @property-read list<ProviderModelEntry> $resource
 */
final class ProviderModelCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<ProviderModelEntry>  $resource
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
            'models' => array_map(
                static fn (ProviderModelEntry $model): array => (new ProviderModelResource($model))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return ProviderModelResource::openApiSchemas() + [
            'ProviderModelCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One provider connection\'s model catalog. An OBJECT wrapping the '
                    .'array so pagination fields can join it later without moving the list.',
                'required' => ['models'],
                'properties' => [
                    'models' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ProviderModelResource'],
                        'description' => 'Every model row under this connection, ENABLED OR NOT. '
                            .'Disabled rows are included because an operator asking "why is this '
                            .'model missing from the bot\'s dropdown" has to be able to find it — '
                            .'hiding it makes the question unanswerable from the console while the '
                            .'row sits in the table. Ordered by model identifier, '
                            .'deterministically, so two reads of an unchanged set are '
                            .'byte-identical.',
                    ],
                ],
            ],
        ];
    }
}

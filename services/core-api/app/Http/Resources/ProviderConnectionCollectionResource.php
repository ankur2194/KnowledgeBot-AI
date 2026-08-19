<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProviderConnection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's provider connections.
 *
 * AN OBJECT WRAPPING THE ARRAY, never a bare array, and the reason is mechanical rather than
 * stylistic — it is spelled out once in InvitationCollectionResource and holds identically here.
 * `#[ResponseShape]` maps a response KEY to a resource class, so it cannot express "an array of";
 * and tests/Contract/OpenApiDocumentTest.php requires every published component to carry
 * `additionalProperties: false`, which an array-typed schema cannot. Body is
 * `{"data": {"connections": [...]}}`.
 *
 * The wrapper also leaves room for pagination fields to join later without moving the list, which
 * matters more here than for members: an organization accumulates connections over years and the
 * day this grows a cursor is the day a bare array would become a breaking change.
 *
 * The item schema is contributed by ProviderConnectionResource rather than re-declared, so
 * `ProviderConnectionResource` is ONE component in the generated client — the same component
 * `POST`, `GET /{providerConnection}`, `PATCH` and the credential rotation all return.
 *
 * NOTHING HERE TOUCHES THE VAULT. The item resource reads `last_four` from a stored column, so
 * rendering a hundred connections performs zero decryptions and cannot leak a key even under a
 * fixture whose plaintext really was sealed (asserted in tests/Contract/OpenApiDocumentTest.php).
 *
 * @property-read list<ProviderConnection> $resource
 */
final class ProviderConnectionCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<ProviderConnection>  $resource
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
            'connections' => array_map(
                static fn (ProviderConnection $connection): array => (new ProviderConnectionResource($connection))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return ProviderConnectionResource::openApiSchemas() + [
            'ProviderConnectionCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The organization\'s provider connections. An OBJECT wrapping the '
                    .'array so pagination fields can join it later without moving the list.',
                'required' => ['connections'],
                'properties' => [
                    'connections' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ProviderConnectionResource'],
                        'description' => 'Every connection belonging to this organization, whatever '
                            .'its status. Revoked and invalid rows are included because an operator '
                            .'diagnosing "why can I not ingest" has to be able to see the credential '
                            .'that stopped working — hiding it makes the question unanswerable from '
                            .'the console. Ordered oldest first, deterministically, so two reads of '
                            .'an unchanged set are byte-identical.',
                    ],
                ],
            ],
        ];
    }
}

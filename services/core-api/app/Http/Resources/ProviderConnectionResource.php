<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\ProviderConnection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProviderConnection $resource
 */
final class ProviderConnectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(ProviderConnection $resource)
    {
        parent::__construct($resource);
    }

    /**
     * THE MASKED FORM IS THE ONLY DERIVED FORM OF THE KEY THAT LEAVES THIS PROCESS, and it is read
     * from a stored column rather than computed, so rendering it never requires a decryption. This
     * class does not import the vault and cannot reach a plaintext key even by accident; an arch
     * rule pins that for the whole App\Http\Resources namespace.
     *
     * Four characters and never a prefix: a provider key prefix identifies the vendor and, on
     * several providers, the account.
     *
     * `organization_id` is not rendered either. A client that received this resource asked for it
     * inside a URL that already named the organization; echoing the ownership column back adds
     * nothing and puts a tenant identifier into every cached response body.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $connection = $this->resource;

        return [
            'id' => $connection->id,
            'provider' => $connection->provider->value,
            'label' => $connection->label,
            'masked_key' => '…'.$connection->last_four,
            'status' => $connection->status->value,
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    /**
     * The published shape.
     *
     * `provider` and `status` ARE published as closed enums, and the members come from the PHP
     * backed enums rather than from a literal — these values are ours and are enforced by a cast on
     * the model, so a client may exhaustively switch on them. That is the opposite call from
     * EmbeddingReadinessResource's `provider`, which is relayed from the data plane and validated
     * nowhere on this side; the difference is enforcement, not taste.
     *
     * `masked_key` is published as a plain string and is described as NOT a credential. That
     * description is load-bearing: `…4a91` posted back into a create or rotate request would
     * become the tenant's new key, which is precisely why no shared form schema names the
     * `credential` field (see packages/contracts/test/form-drift.test.ts, NO_CLIENT_FORM).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ProviderConnectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One stored provider connection. Carries no ciphertext, no key '
                    .'version, and no decrypted material; rendering it never touches the vault.',
                'required' => ['id', 'provider', 'label', 'masked_key', 'status', 'created_at'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the connection.',
                    ],
                    'provider' => [
                        'type' => 'string',
                        'enum' => Provider::values(),
                        'description' => 'The vendor this connection authenticates against.',
                    ],
                    'label' => [
                        'type' => 'string',
                        'description' => 'Operator-supplied name. Tenant-controlled text: escape it '
                            .'on render.',
                    ],
                    'masked_key' => [
                        'type' => 'string',
                        'description' => 'A horizontal ellipsis (U+2026) followed by the last four '
                            .'characters of the key, read from a stored column. NEVER a credential: '
                            .'it is a display string, it cannot authenticate anything, and posting '
                            .'it back into a create or rotate request would set the tenant\'s key to '
                            .'the literal text `…abcd`. Four characters and never a prefix — a '
                            .'provider key prefix identifies the vendor and, on several providers, '
                            .'the account.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ProviderConnectionStatus::values(),
                        'description' => 'Lifecycle state of the connection.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Null only for a record whose '
                            .'timestamp was never set.',
                    ],
                ],
            ],
        ];
    }
}

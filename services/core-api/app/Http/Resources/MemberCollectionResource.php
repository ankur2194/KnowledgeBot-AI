<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationUser;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's member list.
 *
 * The wrapper-object rationale is spelled out once, in InvitationCollectionResource: `ResponseShape`
 * cannot express "an array of" for a response key, and OpenApiDocumentTest requires every resource
 * component to be closed, which an array-typed schema cannot be. Body is
 * `{"data": {"members": [...]}}`.
 *
 * The item schema is contributed by MemberResource rather than re-declared here, so `MemberResource` is
 * one component in the generated client.
 *
 * @property-read list<OrganizationUser> $resource
 */
final class MemberCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<OrganizationUser>  $resource  each with `user` eager-loaded
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
            'members' => array_map(
                static fn (OrganizationUser $membership): array => (new MemberResource($membership))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return MemberResource::openApiSchemas() + [
            'MemberCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The organization\'s members. An OBJECT wrapping the array so '
                    .'pagination fields can join it later without moving the list.',
                'required' => ['members'],
                'properties' => [
                    'members' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/MemberResource'],
                        'description' => 'Every membership of this organization, active or suspended. '
                            .'Suspended rows are included because an administrator restoring access '
                            .'has to be able to find the person.',
                    ],
                ],
            ],
        ];
    }
}

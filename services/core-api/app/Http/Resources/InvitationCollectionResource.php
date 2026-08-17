<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's invitation list.
 *
 * ── WHY A NAMED WRAPPER OBJECT AND NOT A BARE ARRAY UNDER `data` ────────────────────────────────
 *
 * The design document specifies `{"data": [...]}` for this endpoint. IT IS NOT PUBLISHABLE, and the
 * deviation is forced rather than chosen. `ResponseShape::$properties` maps a response KEY to a class
 * implementing ProvidesOpenApiSchema, and `DumpOpenApiCommand::operation()` composes exactly one
 * object whose properties are those keys with `$ref` values — there is no shape in that attribute
 * that says "an array of". Separately, tests/Contract/OpenApiDocumentTest.php requires EVERY resource
 * component to carry `additionalProperties: false`, which an array-typed schema cannot, so a component
 * that WAS the array would fail the suite even if the dumper could reference it.
 *
 * So the body is `{"data": {"invitations": [...]}}`. That is one nesting level more than the design
 * asked for, and it buys something real: a paginated list later adds `next_cursor` and `total`
 * alongside `invitations` inside the same object, without moving the array or versioning the endpoint.
 * The dumper limitation is REPORTED rather than fixed here — changing the artifact generator inside an
 * auth change set is how a contract regression arrives attributed to the wrong commit.
 *
 * The item schema is not re-declared: `openApiSchemas()` returns InvitationResource's own components
 * plus this wrapper, so `InvitationResource` is ONE component in the generated client whether it
 * arrives from `store` or from `index`. The dumper compares bodies when a component name appears
 * twice, so an identical contribution is a no-op and a divergent one is a build failure.
 *
 * @property-read list<OrganizationInvitation> $resource
 */
final class InvitationCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<OrganizationInvitation>  $resource  each with `invitedBy` eager-loaded
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
            'invitations' => array_map(
                static fn (OrganizationInvitation $invitation): array => (new InvitationResource($invitation))->toArray($request),
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return InvitationResource::openApiSchemas() + [
            'InvitationCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The organization\'s invitations. An OBJECT wrapping the array rather '
                    .'than the array itself, so pagination fields can join it later without moving '
                    .'the list — and because the generated document cannot express a top-level array '
                    .'for a response key.',
                'required' => ['invitations'],
                'properties' => [
                    'invitations' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/InvitationResource'],
                        'description' => 'Every invitation belonging to this organization, in whatever '
                            .'order the endpoint documents. Includes accepted, revoked and expired '
                            .'rows: their `status` is derived, and hiding them would make "why can I '
                            .'not re-invite this address" unanswerable from the UI.',
                    ],
                ],
            ],
        ];
    }
}

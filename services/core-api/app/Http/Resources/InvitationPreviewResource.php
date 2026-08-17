<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * What the holder of a valid invitation token is shown before they register.
 *
 * A DELIBERATE DISCLOSURE TO THE HOLDER OF A 256-BIT RANDOM, and nothing more. It exists so the SPA
 * can prefill the register form and say "you have been invited to <organization> as <role>" instead of
 * asking someone to retype an address the invitation already names.
 *
 * WHAT IS ABSENT, AND WHY EACH ABSENCE IS DELIBERATE:
 *   organization_id   the token holder needs to RECOGNISE the invitation, not to address the
 *                     organization. Publishing the ULID hands an unauthenticated caller a real tenant
 *                     identifier to try against every other route.
 *   inviter identity  who invited you is a fact about a member of an organization you are not in yet.
 *                     It is on the admin-side InvitationResource, where the reader is a member.
 *   invitation id     nothing addresses an invitation by id on the guest path; the token does.
 *   status            unreachable — every non-`pending` state is a 404 before this resource is built,
 *                     with one body for all four, so a field naming the state could only ever say
 *                     `pending`.
 *
 * The four invalid states (unknown, expired, accepted, revoked) collapse into one 404 with one body,
 * because distinguishing them would prove a guessed token had once been real.
 *
 * @property-read OrganizationInvitation $resource
 */
final class InvitationPreviewResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(OrganizationInvitation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        $organization = $invitation->organization;

        if (! $organization instanceof Organization) {
            // The FK is NOT NULL with ON DELETE RESTRICT, so a missing organization means the
            // relation was not eager-loaded. Loud, because the published schema declares
            // `organization_name` non-nullable and rendering null there would ship a document the
            // body violates.
            throw new RuntimeException(
                'InvitationPreviewResource needs the invitation\'s organization eager-loaded: '
                .'`with(\'organization\')`. Model::shouldBeStrict() would throw on the lazy access '
                .'anyway, and the published schema declares `organization_name` non-nullable.',
            );
        }

        return [
            'organization_name' => $organization->name,
            // The address the invitation was ISSUED TO, echoed so the register form can prefill it and
            // so the recipient can see they are looking at the right invitation. It is not a
            // disclosure: the caller already holds a token bound to this exact address.
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toAtomString(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'InvitationPreviewResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'A pending invitation, as shown to the holder of its token before '
                    .'registration. Carries no organization ULID and no inviter identity: the reader '
                    .'is not a member yet. Every invalid token — unknown, expired, accepted, revoked '
                    .'— answers 404 with one shared body instead, so this shape is only ever '
                    .'produced for a pending invitation.',
                'required' => ['organization_name', 'email', 'role', 'expires_at'],
                'properties' => [
                    'organization_name' => [
                        'type' => 'string',
                        'description' => 'Display name of the inviting organization. The name, never '
                            .'the ULID.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'The address the invitation was issued to. Registration '
                            .'requires the submitted address to match it, so the form prefills from '
                            .'here rather than asking.',
                    ],
                    'role' => [
                        'type' => 'string',
                        'enum' => OrgRole::values(),
                        'description' => 'The role the invitation grants on acceptance. Fixed at '
                            .'invitation time by an administrator; it is not negotiable at '
                            .'registration and is published for display only.',
                    ],
                    'expires_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When the token stops working, RFC 3339. Always in the '
                            .'future for a body that renders at all.',
                    ],
                ],
            ],
        ];
    }
}

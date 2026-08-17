<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * One member of ONE organization.
 *
 * IT WRAPS THE MEMBERSHIP ROW, NOT THE USER, and that is the tenancy decision in this file. A user is
 * not owned by an organization — one user may belong to several — so "a member" only exists relative
 * to an organization, and the row that carries `role` and `status` is the membership. Wrapping the
 * `User` instead would produce a resource that could be rendered for a user with no membership in the
 * organization being listed, which is the "admin of some organization" bug wearing a different hat.
 *
 * `user_id` AND NOT `id`. The membership has no single-column identity — its primary key is
 * (organization_id, user_id) — and naming the field `id` would invite a client to use it as an
 * addressable resource id, which it is not: member routes are keyed by user within an organization.
 *
 * WHAT IS ABSENT. No `is_platform_owner`: that is a PLATFORM flag and publishing it on a tenant's
 * member list would tell an organization's administrator which of their members is a platform
 * operator. No permission list — UI hiding is not authorization. No last-seen timestamp: nothing
 * records one yet, and a field that is always null is a field a client learns to ignore.
 *
 * @property-read OrganizationUser $resource
 */
final class MemberResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(OrganizationUser $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->resource;
        $user = $membership->user;

        if (! $user instanceof User) {
            // `user_id` is NOT NULL with ON DELETE RESTRICT; an absent relation is an unloaded one,
            // which Model::shouldBeStrict() would already have thrown on. Loud rather than nulls in
            // two fields the published schema declares non-nullable.
            throw new RuntimeException(
                'MemberResource needs `with(\'user\')` on the membership query. The column is NOT '
                .'NULL with ON DELETE RESTRICT, so an absent relation is an unloaded one, and the '
                .'published schema declares `name` and `email` non-nullable.',
            );
        }

        return [
            'user_id' => $membership->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $membership->role->value,
            'status' => $membership->status->value,
            'joined_at' => $membership->created_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'MemberResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One membership of one organization. The subject is the MEMBERSHIP '
                    .'row, not the user: `role` and `status` are per organization, and the same person '
                    .'can be an owner in one tenant and an analyst in another.',
                'required' => ['user_id', 'name', 'email', 'role', 'status', 'joined_at'],
                'properties' => [
                    'user_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the user. Named `user_id` rather than `id` because '
                            .'a membership has no single-column identity — its primary key is '
                            .'(organization_id, user_id) — and member routes are keyed by user within '
                            .'an organization.',
                    ],
                    'name' => ['type' => 'string', 'description' => 'Display name.'],
                    'email' => [
                        'type' => 'string',
                        'description' => 'The member\'s login identifier, globally unique on '
                            .'`lower(email)`.',
                    ],
                    'role' => [
                        'type' => 'string',
                        'enum' => OrgRole::values(),
                        'description' => 'The role held IN THIS ORGANIZATION. One role per user per '
                            .'organization; the catalog is fixed and there is no per-tenant role CRUD.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => MembershipStatus::values(),
                        'description' => 'Whether the membership currently confers anything. Only '
                            .'`active` does; `suspended` authorizes no read at all.',
                    ],
                    'joined_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'When the membership row was created, RFC 3339. Nullable only '
                            .'because an unsaved model has no timestamp; every persisted row has one.',
                    ],
                ],
            ],
        ];
    }
}

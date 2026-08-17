<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Services\Auth\SessionSnapshot;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * WHO YOU ARE, WHICH ORGANIZATION THE CONSOLE IS POINTED AT, AND WHAT YOU MAY SWITCH TO.
 *
 * The single body of `POST /auth/login`, `GET /me`, `POST /session/organization`,
 * `POST /auth/register` and `POST /auth/invitations/accept`. FIVE ENDPOINTS, ONE SHAPE, ON PURPOSE:
 * the SPA has exactly one reducer for "the session changed", and an endpoint that returned a subset
 * would make the client's state depend on which call it happened to arrive from.
 *
 * FLAT AND TOTAL (decision D5). Every field below is in `required` and every nullable field is typed
 * `["string","null"]`, because tests/Contract/OpenApiDocumentTest.php asserts that every resource
 * component is both CLOSED (`additionalProperties: false`) and TOTAL (declared ⊆ required). There
 * are no optional fields and there must not be: an optional field in a generated TypeScript type is
 * one a client silently stops reading.
 *
 * `email_verified` IS A BOOLEAN, NOT A TIMESTAMP. The SPA renders a banner, not a date, and two
 * spellings of one fact drift — the day someone needs the timestamp they will add a second field and
 * the two will disagree about the same user.
 *
 * `organizations` LISTS EVERY MEMBERSHIP WITH ITS `status`, not only the active ones. A suspended
 * member seeing an empty list concludes their account is broken; a suspended member seeing their
 * organization greyed out with `status: "suspended"` can go and ask someone. `current_organization_id`
 * is the opposite and is only ever an ACTIVE membership or null.
 *
 * WHAT IS NOT IN HERE. No permission list and no role capability matrix: UI hiding is not
 * authorization, and publishing the matrix would invite a client to compute a verdict the server
 * already owns (App\Enums\OrgRole::grants). No `password`, no `remember_token`, no session id — the
 * resource cannot reach them because App\Services\Auth\SessionSnapshot does not carry them.
 *
 * @property-read SessionSnapshot $resource
 */
final class SessionResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(SessionSnapshot $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;
        $user = $snapshot->user;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // hasVerifiedEmail() rather than a null check on the column: it is the accessor the
                // `verified` middleware itself calls, so the banner and the 403 can never disagree.
                'email_verified' => $user->hasVerifiedEmail(),
                'is_platform_owner' => $user->isPlatformOwner(),
            ],
            'current_organization_id' => $snapshot->currentOrganizationId,
            'organizations' => array_map($this->membership(...), $snapshot->memberships),
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string, role: string, status: string}
     */
    private function membership(OrganizationUser $membership): array
    {
        $organization = $membership->organization;

        if (! $organization instanceof Organization) {
            // A membership row whose organization is missing is an FK violation the database forbids
            // (`organization_users.organization_id REFERENCES organizations (id) ON DELETE
            // RESTRICT`), so reaching this line means the relation was not eager-loaded and
            // Model::shouldBeStrict() is off. Failing loudly beats rendering `name: null` into a
            // field the published schema declares non-nullable.
            throw new RuntimeException(
                'A membership reached SessionResource without its organization loaded. The '
                .'repository must eager-load it — the published schema declares `name` and `slug` '
                .'non-nullable, and a lazy access under Model::shouldBeStrict() throws anyway.',
            );
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            // The BACKED VALUES, and the schema publishes both sets as closed enums — unlike
            // EmbeddingReadinessResource's `provider`, these come off PHP backed enums this service
            // owns and validates, so a closed enum here is a claim the code actually enforces
            // (a CHECK constraint on both columns says the same thing one layer down).
            'role' => $membership->role->value,
            'status' => $membership->status->value,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'SessionUser' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The authenticated user. Deliberately narrow: no password hash, no '
                    .'remember token, no session identifier, and no permission list — UI hiding is '
                    .'not authorization, so the role capability matrix stays server-side.',
                'required' => ['id', 'name', 'email', 'email_verified', 'is_platform_owner'],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the user.'],
                    'name' => ['type' => 'string', 'description' => 'Display name.'],
                    'email' => [
                        'type' => 'string',
                        'description' => 'The login identifier. Globally unique on `lower(email)`, '
                            .'not per organization — the login form has no tenant yet.',
                    ],
                    'email_verified' => [
                        'type' => 'boolean',
                        'description' => 'A BOOLEAN, not a timestamp. False does not block login; it '
                            .'blocks the org-scoped write routes, which answer 403 with a message '
                            .'the closed error envelope deliberately does not distinguish from a '
                            .'role denial — so THIS FIELD is the SPA\'s only channel for explaining '
                            .'that 403.',
                    ],
                    'is_platform_owner' => [
                        'type' => 'boolean',
                        'description' => 'A PLATFORM flag, not an organization role. It reaches no '
                            .'tenant data; it gates platform surfaces such as /horizon.',
                    ],
                ],
            ],

            'SessionMembership' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One organization this user belongs to, and how. Present even when '
                    .'`status` is not `active`, so a suspended member sees why their organization is '
                    .'greyed out rather than an empty list.',
                'required' => ['id', 'name', 'slug', 'role', 'status'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the organization — the value to send to '
                            .'POST /session/organization and the value that appears as the '
                            .'`{organization}` path segment on every tenant-owned route.',
                    ],
                    'name' => ['type' => 'string', 'description' => 'Display name.'],
                    'slug' => ['type' => 'string', 'description' => 'URL-safe short name.'],
                    'role' => [
                        'type' => 'string',
                        'enum' => OrgRole::values(),
                        'description' => 'The FIXED role catalog. One role per user per '
                            .'organization; there is no per-tenant role CRUD. Published closed '
                            .'because it comes off a PHP backed enum this service validates and a '
                            .'CHECK constraint enforces.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => MembershipStatus::values(),
                        'description' => 'Whether the membership currently confers anything. Only '
                            .'`active` does. `invited` has no producer today — pending state lives '
                            .'in `organization_invitations` — and is published because the column\'s '
                            .'CHECK constraint still admits it.',
                    ],
                ],
            ],

            'SessionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The whole client-visible session state, returned identically by '
                    .'login, register, invitation acceptance, GET /me and the organization switcher. '
                    .'FLAT AND TOTAL: every field is required, so the SPA has one reducer rather '
                    .'than one per endpoint.',
                'required' => ['user', 'current_organization_id', 'organizations'],
                'properties' => [
                    'user' => ['$ref' => '#/components/schemas/SessionUser'],
                    'current_organization_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'A UI PREFERENCE THAT AUTHORIZES NOTHING. Only ever an '
                            .'ACTIVE membership or null; every tenant-owned route carries the '
                            .'organization in its path and re-reads the membership row from '
                            .'PostgreSQL regardless of this value. Null is normal and is not an '
                            .'error: a user with no active membership still has a valid session, '
                            .'which is what makes the resend-verification endpoint reachable. '
                            .'GET /me repairs a value whose membership has since been revoked.',
                    ],
                    'organizations' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/SessionMembership'],
                        'description' => 'EVERY membership, oldest first, active or not. Empty for a '
                            .'user who belongs to nothing.',
                    ],
                ],
            ],
        ];
    }
}

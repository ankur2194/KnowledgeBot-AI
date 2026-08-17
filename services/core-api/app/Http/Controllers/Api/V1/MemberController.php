<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberCollectionResource;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's members.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   `auth:sanctum` on the route group.
 *   2. organization membership  `org.member`, re-reading the row from PostgreSQL every request.
 *   3. role / permission        `Gate::authorize('viewMembers', $organization)` -> `members.view`.
 *                               Owner and admin hold it; knowledge_manager and analyst do not — a
 *                               member list is the org chart, and the roles that cannot change it have
 *                               no reason to enumerate it.
 *   4. entity ownership         `{organization}` is bound and `org.member` resolved membership of THAT
 *                               organization. The read itself must take `organization_id` as an
 *                               explicit argument: `organization_users` deliberately carries no
 *                               OrganizationScope (it is the table the scope is derived FROM), so this
 *                               is one of the few queries where the explicit predicate is the only
 *                               layer, not the backstop's companion.
 *   5. entity status            NO 409. A suspended organization's administrator must still be able to
 *                               see who is in it — that is often the first thing they are asked.
 *   6. rate limit               `throttle:admin` on the group, plus `verified`.
 *
 * WHAT THIS ENDPOINT MUST NEVER GROW: a cross-organization member search, or a "find user by email"
 * parameter. Either one turns a tenant-scoped list into a global user directory, and the scoping is not
 * recoverable once a client depends on the shape.
 */
final class MemberController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => MemberCollectionResource::class],
        description: 'Every membership of this organization, active and suspended, each with the '
            .'member\'s name, address, role and join date. The array is nested inside an object; see '
            .'InvitationCollectionResource for why the document cannot express a bare array here.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        OrganizationRepositoryInterface $organizations,
    ): MemberCollectionResource {
        // CHECKS 3 AND 4. `viewMembers` -> Permission::MembersView, resolved against THIS RECORD's
        // organization. Owner and admin hold it; knowledge_manager and analyst do not.
        Gate::authorize('viewMembers', $organization);

        // NO 409 — see the class docblock, check 5.
        //
        // The read goes through the repository and takes `organization_id` as a required positional
        // argument, because `organization_users` deliberately carries no OrganizationScope: it is the
        // table the scope is DERIVED from, so here the explicit predicate is the only layer rather than
        // the backstop's companion. The repository interface also states, in prose, that this method
        // must never grow a cross-organization or find-by-email variant.
        return new MemberCollectionResource(
            $organizations->membersOf($organization->organizationId()),
        );
    }
}

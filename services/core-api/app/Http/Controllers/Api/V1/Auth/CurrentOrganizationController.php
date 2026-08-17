<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\SwitchOrganizationRequest;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\Auth\SessionOrganizationResolver;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;

/**
 * `POST /api/v1/session/organization` — point the console at another organization.
 *
 * THE ONLY WRITER OF `current_organization_id`, apart from login's initial resolution and `/me`'s
 * repair. It re-checks membership against PostgreSQL BEFORE writing, so a value that is not currently
 * an active membership can never be STORED — which is the other half of why nothing downstream has to
 * treat the session value as untrusted.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   `auth:sanctum` on the route.
 *   2. organization membership  RE-READ HERE, from `organization_users`, for the organization named
 *                               in the BODY. It cannot be `org.member`: that middleware reads
 *                               `$request->route('organization')` and throws when the segment is
 *                               absent, and this endpoint's whole job is to accept an organization
 *                               that is not yet in any URL.
 *   3. role / permission        THE MEMBERSHIP *IS* THE PERMISSION, and there is deliberately no
 *                               Gate::authorize() call. Every role in the catalog may switch to an
 *                               organization it belongs to — there is no `organizations.switch`
 *                               permission and inventing one would be a case nobody grants and
 *                               nobody checks. More importantly, `Gate::authorize()` needs a RECORD,
 *                               and loading the Organization in order to have one would query
 *                               `organizations` by a caller-supplied id — the exact global
 *                               org-existence oracle that the missing `exists:` rule on
 *                               SwitchOrganizationRequest exists to avoid. `isActiveMemberOf()`
 *                               cannot answer a question about an organization the caller is not in.
 *   4. entity ownership         Same call: the membership row IS the ownership fact, resolved from
 *                               (organization_id, user_id) rather than from ambient state.
 *   5. entity status            NOT APPLIED, and this is a decision. Switching INTO a suspended
 *                               organization stays possible, for the same reason
 *                               `GET /embedding-configuration` has no 409: reading why you are
 *                               blocked is exactly what a suspended organization's operator needs to
 *                               do, and a console that cannot be pointed at the organization cannot
 *                               show them. The suspension is enforced on the WRITES, per route.
 *   6. rate limit               `throttle:admin` on the route.
 *
 * WHY 403 AND NOT 404. This is the admin surface, which is not enumeration-sensitive: a member is
 * already entitled to know their own organizations exist. And a non-existent organization produces the
 * SAME 403 with the SAME body as one the caller is merely not a member of — bootstrap/app.php rewrites
 * every `authorization` message to one constant — so the endpoint is not an org-existence oracle even
 * though it answers 403.
 */
final class CurrentOrganizationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => SessionResource::class],
        description: 'The session state AFTER the switch, with `current_organization_id` set to the '
            .'requested organization. Wrapped in `data` because the action returns the Resource '
            .'itself and Laravel wraps it. The full snapshot rather than an acknowledgement, so the '
            .'SPA re-renders from one payload instead of firing a follow-up GET /me.',
        errors: [401, 403, 422, 429, 500, 503],
    )]
    public function store(
        SwitchOrganizationRequest $request,
        SessionOrganizationResolver $resolver,
    ): SessionResource {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $organizationId = $request->organizationId();

        // CHECKS 2 AND 4, in one read, against PostgreSQL. isActiveMemberOf() is NOT memoized on the
        // User instance (see App\Models\User::membershipFor()), so this is a fresh row and not a
        // value cached earlier in the request.
        if (! $user->isActiveMemberOf($organizationId)) {
            // `authorization` -> 403 on the admin surface, with the one constant message. A revoked
            // member, a suspended member, and a ULID that never existed are one response.
            throw new AuthorizationException;
        }

        $request->session()->put('current_organization_id', $organizationId);

        // Passed as the PREFERENCE rather than assumed: the resolver re-reads every membership anyway
        // for the response, and handing it the requested id keeps one selection rule in one place. It
        // will agree with the check above, because the check above is the same fact from the same
        // table.
        return new SessionResource($resolver->snapshot($user, $organizationId));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\Auth\SessionOrganizationResolver;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me` — who the caller is, right now, re-read from PostgreSQL.
 *
 * THIS IS THE READ SURFACE'S RE-VERIFICATION (laravel-sanctum-auth NN1). Every authenticated request
 * must resolve to a membership re-read from the database; the org-scoped routes do that in
 * `org.member`, and this route — which has no organization — does it here, by reading every
 * membership on every call.
 *
 * IT ALSO REPAIRS THE SESSION. If `current_organization_id` is no longer an ACTIVE membership —
 * revoked, suspended, or an organization the user was removed from — the stored value is replaced
 * with the freshly resolved one and written back. That is what makes mid-session membership
 * revocation a non-event: nothing has to hunt down and invalidate sessions, because the value was
 * never authority in the first place (see SessionOrganizationResolver) and the next `/me` corrects
 * the console's pointer.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   `auth:sanctum` on the route.
 *   2. organization membership  RE-READ HERE, for all organizations, rather than by `org.member`.
 *                               `org.member` must NOT be on this route: TenantContext::handle()
 *                               throws AuthorizationException when the `{organization}` route
 *                               segment is absent, so it would 403 every single request.
 *   3. role / permission        N/A — a user is always permitted to read their own session. The
 *                               resource publishes no permission list, so nothing is disclosed that
 *                               a role would gate.
 *   4. entity ownership         The subject IS the caller; there is no other record to own.
 *   5. entity status            DELIBERATELY NOT APPLIED, in two directions. `verified` is not on
 *                               this route, because an unverified user must be able to read
 *                               `email_verified: false` — that field is the SPA's ONLY channel for
 *                               explaining the 403s it will get elsewhere, since the closed error
 *                               envelope cannot distinguish "unverified" from "wrong role". And a
 *                               suspended membership does not 403 here; it appears in
 *                               `organizations[].status`, which is the point.
 *   6. rate limit               `throttle:admin` on the route.
 */
final class CurrentSessionController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => SessionResource::class],
        description: 'The current session state, re-read from PostgreSQL on every call and with a '
            .'stale `current_organization_id` repaired. Wrapped in `data` because the action returns '
            .'the Resource itself and Laravel wraps it. Identical in shape to the login response.',
        errors: [401, 429, 500, 503],
    )]
    public function show(Request $request, SessionOrganizationResolver $resolver): SessionResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // `auth:sanctum` has already run, so this is unreachable — and it is here because
            // "unreachable" is a claim about a middleware stack, and middleware stacks are edited by
            // people. Throwing AuthenticationException renders 401 `authentication`, which is the
            // honest answer; letting it through would be a TypeError 500.
            throw new AuthenticationException;
        }

        $session = $request->session();

        $stored = $session->get('current_organization_id');
        $preferred = is_string($stored) ? $stored : null;

        // The resolver HONOURS $preferred only if it is currently an active membership, and silently
        // re-resolves otherwise. There is no branch here on "was it stale" because there is nothing
        // to tell the client: the value is a preference, and a corrected preference is not an event.
        $snapshot = $resolver->snapshot($user, $preferred);

        if ($snapshot->currentOrganizationId !== $preferred) {
            // WRITTEN BACK, so the repair is durable rather than per-response. Without this line a
            // user whose membership was revoked would have the value re-resolved on every /me and
            // still see the stale one in any code path that reads the session directly.
            $session->put('current_organization_id', $snapshot->currentOrganizationId);
        }

        return new SessionResource($snapshot);
    }
}

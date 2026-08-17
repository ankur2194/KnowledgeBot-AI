<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\SessionOrganizationResolver;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\AuthenticationException;

/**
 * `POST /api/v1/auth/invitations/accept` — join an organization as an EXISTING, signed-in user.
 *
 * THE SIBLING OF `/auth/register`, AND THE REASON BOTH EXIST. One invitation flow has two entry points
 * because the recipient either has an account or does not, and they cannot be one endpoint: register
 * takes a password and creates a user, accept takes a session and creates only a membership. Merging
 * them would mean an endpoint that accepts a password from an already-authenticated caller.
 *
 * WHAT THE IMPLEMENTATION MUST NOT LOSE:
 *   * THE INVITATION'S ADDRESS IS COMPARED, WITH `hash_equals`, AGAINST THE AUTHENTICATED USER'S — not
 *     against anything in the body. Otherwise a signed-in user who intercepts somebody else's
 *     invitation link joins an organization it was never issued to.
 *   * Invalid token, address mismatch, and "already a member" collapse into ONE 422 on `token`.
 *   * `AuditLogger::INVITATION_ACCEPTED` is `ON_FAILURE_ABORT`: the row goes in the SAME transaction as
 *     the membership insert, so a failed audit write rolls the membership back.
 *   * The response carries the NEW organization already selected, so the SPA lands the user inside it
 *     without a second call.
 *
 * THE SIX CHECKS: 1 is `auth:sanctum` on the route. 2 is deliberately NOT `org.member` — the caller is
 * by definition not yet a member of the organization being joined, and the middleware would 403 the one
 * request that is supposed to make them one (it also throws outright when the `{organization}` segment
 * is absent, which it is here). 3-4 are the invitation token plus the address comparison: the token is
 * what authorizes, and the role comes off the row rather than the request. 5 belongs to the
 * implementation — a suspended organization may not grow. 6 is `throttle:invitation`.
 */
final class AcceptInvitationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => SessionResource::class],
        description: 'The membership was created and the session now points at the new organization. '
            .'Wrapped in `data`; identical in shape to the login response, so the SPA re-renders from '
            .'one payload.',
        errors: [401, 422, 429, 500, 503],
    )]
    public function store(
        AcceptInvitationRequest $request,
        RegistrationService $registrations,
        SessionOrganizationResolver $resolver,
    ): SessionResource {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable behind `auth:sanctum`; asserted rather than assumed because everything below
            // reads $user, and a null here would surface as a TypeError 500 pointing at the resolver
            // instead of at the guard. Same shape as CurrentOrganizationController.
            throw new AuthenticationException;
        }

        // The membership is created, the invitation consumed and the ON_FAILURE_ABORT
        // `organization.invitation.accepted` audit row written, all in ONE transaction inside the
        // repository. Invalid token, address mismatch and "already a member" all leave here as the one
        // 422 on `token`.
        $accepted = $registrations->accept($user, $request->token(), $request);

        // The NEW organization, selected. The resolver honours a preference only when it is currently an
        // ACTIVE membership, so this is not a claim being trusted — it is the row that was just written,
        // re-read from PostgreSQL. The SPA therefore lands the user inside the organization they just
        // joined without a second call.
        $snapshot = $resolver->snapshot($user, $accepted['organizationId']);

        $request->session()->put('current_organization_id', $snapshot->currentOrganizationId);

        return new SessionResource($snapshot);
    }
}

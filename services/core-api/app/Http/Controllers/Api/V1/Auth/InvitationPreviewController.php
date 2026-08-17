<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewInvitationRequest;
use App\Http\Resources\InvitationPreviewResource;
use App\Services\Auth\RegistrationService;
use App\Support\Contracts\ResponseShape;

/**
 * `POST /api/v1/auth/invitations/preview` — what an invitation token is for.
 *
 * WHY IT IS A POST AND NOT `GET /auth/invitations/{token}`. An invitation token is a bearer capability,
 * and laravel-sanctum-auth NN4 bars a capability from a URL: it lands in Traefik access logs, in
 * `Referer` on the next navigation away from the page, and in browser history. The REST awkwardness is
 * the price, and it is paid once here rather than argued about per endpoint.
 *
 * WHAT THE IMPLEMENTATION MUST NOT LOSE:
 *
 *   * FOUR INVALID STATES, ONE RESPONSE. Unknown, expired, accepted and revoked all answer 404
 *     `authorization` with the one constant body bootstrap/app.php renders for that status. A
 *     distinguishable "already used" tells a prober the token was real.
 *   * THE LOOKUP IS BY `token_hash`, never by scanning. App\Support\Kb\OpaqueToken::digest() produces
 *     the 32 raw bytes the UNIQUE index is built on — which is also what makes this guest read safe on
 *     a model that deliberately carries no OrganizationScope.
 *   * DO NOT ADD `#[ScopedBy(OrganizationScope::class)]` to App\Models\OrganizationInvitation to "fix"
 *     the unscoped read. The scope fails closed, this path runs before any organization is known, and
 *     the result would be a permanently plausible "this invitation is no longer valid".
 *
 * THE SIX CHECKS: 1-4 N/A — the token is the authority, and the disclosure it buys (organization name,
 * invited address, role) is deliberate. 5 is arguably in scope for the implementation: an invitation
 * into a SUSPENDED organization should not be previewable as though it will work, and the enumeration-
 * safe expression of that is the same 404, not a 409. 6 is `throttle:invitation`, keyed on the token
 * digest and the IP — never on an email, which is attacker-supplied on this surface.
 *
 * CHECK 5 IS IMPLEMENTED, in App\Services\Auth\RegistrationService::resolve(): a pending invitation
 * into a suspended organization resolves to null, which becomes the same 404 as the other four states.
 * The service is where it belongs rather than here, because register and accept need the identical
 * rule and a copy in each controller is the pair that drifts.
 */
final class InvitationPreviewController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => InvitationPreviewResource::class],
        description: 'The pending invitation the token names, so the SPA can prefill the register '
            .'form. Wrapped in `data` because the action returns the Resource itself and Laravel wraps '
            .'it. Every invalid token answers 404 with the one shared deny body instead.',
        // 401 IS PUBLISHED ON A GUEST ROUTE ON PURPOSE, and it is not about being signed in.
        // Two producers reach it. `PreventRequestForgery` (from `statefulApi()`) throws 419 for a
        // dead or absent XSRF cookie, and the render closure maps 419 -> `authentication`/401
        // (bootstrap/app.php: the `$httpStatus === 401, $httpStatus === 419` arm). `RejectBearerToken`
        // is the second: an `Authorization: Bearer` header anywhere on the `api` group is a 401.
        // Omitting it made the document assert a status is impossible when it is reachable with one
        // expired tab -- the mirror image of the 409 that D36 removed for being unreachable.
        errors: [401, 404, 422, 429, 500, 503],
    )]
    public function store(
        PreviewInvitationRequest $request,
        RegistrationService $registrations,
    ): InvitationPreviewResource {
        // No Gate::authorize() and no organization scope, and neither is an omission. There is no
        // authenticated identity to hold a role, and the organization is not known until the row is
        // read — the token IS the scope. `previewable()` aborts 404 on every invalid state, so nothing
        // downstream of this line can be reached with a row the caller is not entitled to see.
        return new InvitationPreviewResource(
            $registrations->previewable($request->token()),
        );
    }
}

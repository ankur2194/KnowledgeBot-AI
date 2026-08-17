<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\SessionResource;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\SessionOrganizationResolver;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /api/v1/auth/register` — create an account by accepting an invitation.
 *
 * REGISTRATION IS INVITATION-ONLY. There is no open sign-up: the field is `token` (decision D13 —
 * NOT `invitation_token`, which the design document used and which is superseded), it is required, and
 * an organization cannot be created here. `organization_id` and `role` appear in no rule and in no DTO;
 * over-posting a tenant key or a role is an authorization bug with a 200 response.
 *
 * ── THE `email` FIELD CONTRADICTION, NOW RESOLVED: THERE IS NO `email` FIELD (DECISION D3) ──────
 * The stub recorded a live disagreement between the approved design (§3 endpoint 5: `email` REQUIRED
 * and `hash_equals`-compared against the invitation) and the already-committed
 * `packages/contracts/src/forms/auth.ts` `registerSchema` (`{token, name, password,
 * password_confirmation}`, NO `email`). It is resolved in favour of the narrower version, and the
 * design's §3 endpoint 5 is superseded on this point. Two reasons:
 *   * security, not convenience — a submitted address is an address the caller chose, and every
 *     "did the comparison run on this path too" question disappears when the field does not exist;
 *   * `packages/contracts/test/form-drift.test.ts` asserts PATH-SET EQUALITY between that schema and
 *     App\Http\Requests\RegisterRequest's dumped manifest, so adding `email` here would break a
 *     committed contract in CI rather than merely disagreeing with a document.
 * Consequence for the sequence below: the design's steps 3 and 4 collapse into one. There is no
 * address-mismatch branch left, because the only address in play comes off the invitation row.
 *
 * FAILURE ORDERING IS THE SECURITY PROPERTY, in this exact sequence:
 *   1. FormRequest -> 422 with field errors (`token` not 64 characters, weak or unconfirmed password,
 *      missing name). These ARE distinguishable from each other, and that discloses nothing: they are
 *      facts about the caller's own body.
 *   2. Resolve the invitation by `sha256(token)`. Unknown, expired, accepted, revoked — AND a pending
 *      invitation into a suspended organization — -> 422 on `token`, BYTE-IDENTICAL across all five.
 *      "Already used" would tell a prober the token was real; so would "expired"; so would a 409 about
 *      an organization the caller is not in. One string, one key, one body.
 *   3. An account already exists for the INVITATION'S address -> 422 on `email`, with its own message.
 *      This IS a disclosure and it is acceptable: the caller has already proven possession of a token
 *      bound to that exact address, which the organization's administrator deliberately sent there.
 *      It is a 422 and not a 409 because bootstrap/app.php's default arm assigns 409 the class
 *      `internal_dependency` — a correct rendering and a terrible registration contract.
 *   4. Success -> 201, in ONE transaction: create the User (UNVERIFIED), create the OrganizationUser
 *      (role from the invitation, status active), mark the invitation accepted, and write the
 *      `organization.invitation.accepted` audit row — which is ON_FAILURE_ABORT, so a failed audit
 *      write rolls the user, the membership and the acceptance back. After COMMIT, and only after:
 *      `event(new Registered($user))`, log in, `session()->regenerate()`, set the current organization.
 *
 * NO `unique:users,email` RULE, deliberately — and with `email` gone there is no field it could even be
 * attached to. Uniqueness is enforced by `users_email_unique` and reported at step 3.
 *
 * THE SIX CHECKS: 1-4 N/A — the invitation token is the authority, and the role it grants is read from
 * the row, never from the body. 5 belongs to the implementation: a suspended organization may not grow,
 * and the enumeration-safe expression is the shared invalid-token 422. 6 is `throttle:invitation`.
 */
final class RegisteredUserController extends Controller
{
    /**
     * 201 rather than 200: a resource was created, and the body is the session that now exists. The
     * user is logged in so the SPA does not have to re-transmit the password it just sent, and so
     * `POST /auth/email/verification-notification` — which needs `auth:sanctum` — is reachable by
     * somebody whose verification mail went astray.
     *
     * THE RETURN TYPE IS `JsonResponse` AND NOT `SessionResource`, and it is the 201 that forces it: a
     * JsonResource returned from an action is rendered by `JsonResource::toResponse()`, which always
     * sets 200, so the only way to publish and actually SEND a 201 is to build the response and set the
     * status. `#[ResponseShape]` is unaffected — DumpOpenApiCommand reads the attribute, never the
     * return type — and EmbeddingConfigurationController::update() already does exactly this.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => SessionResource::class],
        description: 'The account was created, the invitation consumed, and a session established. '
            .'Wrapped in `data`; identical in shape to the login response, and `user.email_verified` '
            .'is FALSE — verification is not a precondition of registration.',
        // 401 IS PUBLISHED ON A GUEST ROUTE ON PURPOSE, and it is not about being signed in.
        // Two producers reach it. `PreventRequestForgery` (from `statefulApi()`) throws 419 for a
        // dead or absent XSRF cookie, and the render closure maps 419 -> `authentication`/401
        // (bootstrap/app.php: the `$httpStatus === 401, $httpStatus === 419` arm). `RejectBearerToken`
        // is the second: an `Authorization: Bearer` header anywhere on the `api` group is a 401.
        // Omitting it made the document assert a status is impossible when it is reachable with one
        // expired tab -- the mirror image of the 409 that D36 removed for being unreachable.
        errors: [401, 422, 429, 500, 503],
    )]
    public function store(
        RegisterRequest $request,
        RegistrationService $registrations,
        SessionOrganizationResolver $resolver,
    ): JsonResponse {
        // STEPS 2, 3 and 4. Everything that can refuse, and everything that writes, happens inside one
        // call — so there is no ordering for a future edit to this method to get wrong, and no window in
        // which a user row exists without its membership.
        $registered = $registrations->register(
            $request->token(),
            $request->displayName(),
            $request->plaintextPassword(),
            $request,
        );

        $user = $registered['user'];

        // ── EVERYTHING BELOW THIS LINE IS AFTER COMMIT, AND THE ORDER IS DELIBERATE ────────────────

        // The verification mail. It is fired here rather than inside the transaction because the
        // framework's auto-registered SendEmailVerificationNotification listener queues a job, and
        // Valkey does not participate in a PostgreSQL transaction: a rollback would leave a live
        // verification link for a user that never existed.
        event(new Registered($user));

        // `Auth::guard('web')` and not `Auth::login()`: the request's default guard is a configurable
        // value (`AUTH_GUARD`, config/auth.php), and naming the guard makes registration independent of
        // it — exactly as LoginController does.
        Auth::guard('web')->login($user);

        // SESSION FIXATION. regenerate() is migrate() + regenerateToken(), so both the session id and
        // the CSRF token change. The SPA consequence is the same one login has: a client that cached
        // `XSRF-TOKEN` at boot fails its first mutation after registering, so read the cookie fresh.
        $request->session()->regenerate();

        // `null` as the preference rather than the invitation's organization, and the two agree anyway:
        // the membership just created is this user's only one, so "oldest active membership" resolves to
        // it. Passing null keeps ONE selection rule in one place instead of a second spelling here that
        // would drift the day a user can hold two memberships at registration.
        $snapshot = $resolver->snapshot($user, null);

        $request->session()->put('current_organization_id', $snapshot->currentOrganizationId);

        return (new SessionResource($snapshot))
            ->response()
            ->setStatusCode(201);
    }
}

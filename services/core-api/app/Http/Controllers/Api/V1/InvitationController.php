<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\InvitationCollectionResource;
use App\Http\Resources\InvitationResource;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Auth\InvitationService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The organization's invitations — list, create, revoke, resend.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4) for all four actions:
 *   1. authenticated identity   `auth:sanctum` on the route group.
 *   2. organization membership  `org.member`, which RE-READS the row from PostgreSQL every request.
 *   3. role / permission        Gate::authorize() in each body — `members.view` on `index`,
 *                               `members.manage` on the other three. PLUS, on `store` only,
 *                               `Gate::authorize('inviteOwner', $organization)` when the requested role
 *                               is `owner`: `OrgScopedPolicy::permit()` has no argument position for
 *                               "the role being granted", so the escalation guard is a second
 *                               permission (`members.manage_owner`) rather than a role comparison
 *                               inside a policy body. An admin may invite; only an owner may create
 *                               another owner, because that is the one act the role performing it
 *                               cannot undo.
 *   4. entity ownership         `->scopeBindings()` on the group resolves `{invitation}` through
 *                               `$organization->invitations()`, so a foreign or unknown id 404s at
 *                               BINDING time — before any policy runs and before the row is in memory.
 *                               A bare `OrganizationInvitation $invitation` binding would be a global
 *                               find with no organization predicate, executed in SubstituteBindings,
 *                               upstream of everything.
 *   5. entity status            a 422 when the organization is not Active (NOT a 409 — see store())
 *                               on `store` and `resend`: a suspended organization may not grow and may
 *                               not send mail. `index` has no 409 — reading why you are blocked is
 *                               exactly what a suspended organization's administrator needs to do.
 *                               `destroy` has no 409 EITHER, and that is a decision rather than an
 *                               omission: revocation only ever REMOVES access, and refusing it in a
 *                               suspended organization would leave a live invitation link that nobody
 *                               can kill for as long as the suspension lasts. The invitation's OWN
 *                               derived status is check 5 for both write actions and is enforced under
 *                               a row lock in the repository — an accepted invitation cannot be
 *                               revoked, and an accepted or revoked one cannot be resent.
 *   6. rate limit               `throttle:admin` on the route group, plus `verified` — which this group
 *                               now carries, so an unverified administrator cannot invite anyone.
 *
 * ── ONE THING THIS CLASS DOES NOT DO ────────────────────────────────────────────────────────────
 *
 * No action here reads or writes a token. Minting, digesting and mailing all live in
 * App\Services\Auth\InvitationService, and the plaintext never returns to this layer — so there is no
 * response body, log line or exception message on this surface that could carry one.
 *
 * RESEND LIVES IN ITS OWN CLASS, App\Http\Controllers\Api\V1\ResendInvitationController, and NOT as a
 * fifth method here. `arch()->preset()->laravel()` restricts a controller's public methods to the
 * resource verb set (`index`, `show`, `create`, `store`, `edit`, `update`, `destroy`) plus
 * `__construct`, `__invoke` and `middleware` — so a `resend()` method fails the Arch suite. That is the
 * preset working rather than an obstacle: a verb that is not one of the seven is a single-action
 * controller, which is also how it reads at the route file.
 */
final class InvitationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => InvitationCollectionResource::class],
        description: 'Every invitation belonging to this organization, including accepted, revoked and '
            .'expired ones — `status` is derived, and hiding the terminal rows makes "why can I not '
            .'re-invite this address" unanswerable. The array is nested inside an object; see '
            .'InvitationCollectionResource for why the document cannot express a bare array here.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        InvitationService $invitations,
    ): InvitationCollectionResource {
        // CHECKS 3 AND 4. `viewMembers` -> Permission::MembersView, resolved by OrgScopedPolicy against
        // THIS RECORD's organization, so "admin of some organization" is unrepresentable.
        //
        // The SAME ability as the member list, and there is deliberately no `viewInvitations`: the two
        // screens are one question ("who is or is about to be in this organization"), the role catalog
        // draws no line between them, and a Permission case nobody grants and nobody checks is the
        // failure mode App\Enums\Permission's own docblock names.
        Gate::authorize('viewMembers', $organization);

        // No 409 — see the class docblock, check 5.
        //
        // The read takes `organization_id` as a required positional argument (the repository interface
        // enforces it), because App\Models\OrganizationInvitation carries no OrganizationScope: on this
        // table the explicit predicate is the only layer, not the backstop's companion.
        return new InvitationCollectionResource($invitations->listFor($organization));
    }

    #[ResponseShape(
        status: 201,
        properties: ['data' => InvitationResource::class],
        description: 'The invitation was created and its mail queued. 422 when the organization is not '
            .'active; 422 when a live invitation already exists for the address or the person is '
            .'already a member — both of which an administrator may read from `index` anyway.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function store(
        StoreInvitationRequest $request,
        Organization $organization,
        InvitationService $invitations,
    ): JsonResponse {
        // CHECKS 3 AND 4.
        Gate::authorize('inviteMember', $organization);

        $role = $request->role();

        if ($role === OrgRole::Owner) {
            // THE ESCALATION GUARD, checked IN ADDITION and never INSTEAD. It is a second permission
            // rather than a role comparison inside a policy body because OrgScopedPolicy::permit() takes
            // (user, record, permission) and has no argument position for "the role being granted" —
            // see App\Enums\Permission::MembersManageOwner. An admin may invite; only an owner may
            // create another owner, because that is the one act the role performing it cannot undo.
            Gate::authorize('inviteOwner', $organization);
        }

        // CHECK 5. A suspended organization may not grow and may not send mail.
        //
        // A 422 AND NOT A 409, and the reason is the client contract rather than REST purity. A 409 is
        // rendered by bootstrap/app.php's documented unclassified-4xx arm as `internal_dependency` with
        // `retryable = false` — a correct *rendering*, but the class-mapped sentence the SPA shows for
        // that class is "Something on our side is unavailable. Try again shortly.", which is false twice
        // over: nothing is unavailable, and retrying will never work while the organization is
        // suspended. The client cannot repair it either, because the envelope carries the class and not
        // the status, so `internal_dependency` from a suspended org is indistinguishable from a real 503.
        //
        // This is the same trade the design already made for the registration conflict, and for the same
        // stated reason. A 422 keyed on a path no form renders routes through applyServerErrors to the
        // banner and shows THIS sentence verbatim, which is both true and actionable.
        if ($organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages([
                'organization' => ['This organization is suspended, so it cannot invite new members.'],
            ]);
        }

        $invitation = $invitations->invite(
            $organization,
            $this->actor(),
            $request->email(),
            $role,
            $request,
        );

        // 201 forces a JsonResponse return type: JsonResource::toResponse() always sets 200, so a
        // published 201 that the endpoint does not actually send would be a document the body violates.
        // `#[ResponseShape]` is read from the attribute, never from the return type.
        return (new InvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The invitation is revoked and its link dead. A foreign or unknown '
            .'`{invitation}` 404s at binding time, before this action runs.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        OrganizationInvitation $invitation,
        InvitationService $invitations,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4 — on the INVITATION, not on the organization, so the policy resolves membership
        // of THE RECORD's organization. Check 4 has in fact already been made by `->scopeBindings()`,
        // which resolved `{invitation}` through `$organization->invitations()`: a foreign id 404s at
        // binding time, before this line and before the row is in memory. The policy is what refuses the
        // remaining case — the row IS in this organization and the caller's role is wrong.
        Gate::authorize('revoke', $invitation);

        // NO 409. Revocation only ever removes access; refusing it while an organization is suspended
        // would leave a live invitation link nobody can kill. See the class docblock.
        //
        // The invitation's own status IS check 5, and it is enforced under a row lock inside the
        // repository rather than here: an accepted invitation is not revocable (a membership exists, and
        // `organization_invitations_terminal_once` would refuse the write anyway) and comes back as a
        // 422 on `invitation`. An already-revoked one is a no-op 200, so DELETE stays idempotent.
        $invitations->revoke($organization, $invitation, $this->actor(), $request);

        // An acknowledgement and not the revoked resource: the only thing the caller learns is that it
        // happened, and `index` is where the new derived `status` is read.
        return AcknowledgementResource::ok();
    }

    /**
     * The authenticated administrator, for `invited_by_id` and for the audit row's `actor_id`.
     *
     * Never used as a SCOPE — the organization is. It is read through the request rather than passed
     * into every signature because `auth:sanctum` has already established it and a nullable parameter
     * threaded through four methods is four places to forget the null check.
     */
    private function actor(): User
    {
        $user = request()->user();

        if (! $user instanceof User) {
            // Unreachable behind `auth:sanctum`, and asserted rather than assumed: a null actor would
            // otherwise reach `invited_by_id`, which is NOT NULL with ON DELETE RESTRICT, and surface as
            // a constraint name in a 500 instead of as the authentication failure it is.
            throw new AuthenticationException;
        }

        return $user;
    }
}

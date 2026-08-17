<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AcknowledgementResource;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Auth\InvitationService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/organizations/{organization}/invitations/{invitation}/resend` — mail the invitation
 * again, under a NEW token.
 *
 * A SINGLE-ACTION CONTROLLER, and not a fifth method on InvitationController, because
 * `arch()->preset()->laravel()` limits a controller's public methods to the seven resource verbs plus
 * `__construct`, `__invoke` and `middleware`. "Resend" is not one of the seven, and the preset is right
 * that it should not pretend to be.
 *
 * IT IS A POST BECAUSE IT IS NOT IDEMPOTENT. Each call MINTS A NEW TOKEN and overwrites `token_hash`,
 * so the previously mailed link stops working. That is the correct semantics — two live links to one
 * invitation are two bearer capabilities carrying one authority — and it is the property that makes an
 * invitation revocable at all: revocation of a digest column only means something if the digest is the
 * only way in. A PUT would advertise "same result if you call it twice", which is exactly false here.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   `auth:sanctum` on the route group.
 *   2. organization membership  `org.member`, re-reading the row from PostgreSQL every request.
 *   3. role / permission        `Gate::authorize('resend', $invitation)` -> `members.manage`, via
 *                               App\Policies\OrganizationInvitationPolicy, which resolves membership of
 *                               THE RECORD's organization. No owner-escalation check is needed: a
 *                               resend cannot change the role the invitation already grants.
 *   4. entity ownership         `->scopeBindings()` resolves `{invitation}` through
 *                               `$organization->invitations()`, so a foreign or unknown id 404s at
 *                               BINDING time, before the policy and before the row is in memory.
 *   5. entity status            TWO status checks, and both belong to the implementation.
 *                               a 422 when the organization is not Active — a suspended organization
 *                               may not send mail (NOT a 409; see the check in handle()). AND the
 *                               invitation's own derived status: only `pending` (or `expired`, which a
 *                               resend legitimately renews) can be resent; an accepted or revoked
 *                               invitation is 422, because there is nothing left to deliver.
 *   6. rate limit               TWO limiters, and the second is why this route carries its own.
 *                               `throttle:admin` from the group bounds the ACTOR at 120/min per
 *                               (org, user) — which cannot bound volume aimed at one RECIPIENT at all,
 *                               so on its own it permits mailing one invitee 120 live invitation links
 *                               a minute: a mailbox flood against someone who never asked to be
 *                               invited, performed with legitimate credentials, invisible to every
 *                               other control (the policy passes, the invitation is valid, the org is
 *                               active). `throttle:invitation-resend` on the route closes it at 3/hour
 *                               keyed on the INVITATION, plus 10/min per actor+org so one
 *                               administrator cannot exhaust a colleague's budget for a shared org.
 *                               Middleware is additive, so both apply.
 *
 * THE NEW TOKEN NEVER APPEARS IN THE RESPONSE. It goes into the queued notification and nowhere else —
 * an administrator does not need to see a capability issued to somebody else, and a token in a JSON
 * body is a token in a browser cache.
 *
 * ── BOTH GAPS THIS FILE ONCE REPORTED ARE CLOSED, AND THE HISTORY IS WHY THE FIXES LOOK ODD ──────
 *
 * This docblock used to carry two "reported gaps" — no audit row, and no per-recipient cooldown — and
 * kept carrying them for two batches after both were fixed. That is worse than never having written
 * them: these are the exact paragraphs somebody reads before touching resend, and each false gap
 * invites a "fix" that regresses. Reusing `INVITATION_CREATED` (which AuditLogger argues against at
 * length) or deleting `invitation-resend` as redundant with `throttle:admin` are both one edit away.
 *
 * AUDITED as `organization.invitation.resent` — a new operation added for this action under D28, ABORT
 * policy, written inside the token-rotation transaction through a REQUIRED `Closure $audit` argument on
 * `rotateToken()`, so the capability cannot be re-issued without the row.
 *
 * RATE-LIMITED per recipient by `throttle:invitation-resend` (3/hour keyed on the INVITATION, plus
 * 10/min per actor+org). See check 6 above for why `throttle:admin` could not do this job: it keys on
 * the ACTOR, so it bounds nothing at all about volume aimed at one mailbox.
 */
final class ResendInvitationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'A NEW token was minted and its mail queued; the previously mailed link stops '
            .'working. The token itself is never in this body. 422 when the organization is not '
            .'active; 422 when the invitation has already been accepted or revoked.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function __invoke(
        Organization $organization,
        OrganizationInvitation $invitation,
        InvitationService $invitations,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4 — on the INVITATION, so OrganizationInvitationPolicy resolves membership of THE
        // RECORD's organization. No owner-escalation check is needed and adding one would be wrong: a
        // resend cannot change the role the invitation already grants, so it is not an escalation path.
        Gate::authorize('resend', $invitation);

        // CHECK 5, first half: a suspended organization may not send mail.
        //
        // A 422, not a 409, for the reason InvitationController::store() spells out: a 409 renders as
        // `internal_dependency`, whose client-facing sentence promises that retrying shortly will help.
        // It will not, and the client cannot tell that case apart from a genuine 503 because the
        // envelope carries the class rather than the status.
        if ($organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages([
                'organization' => ['This organization is suspended, so it cannot send invitations.'],
            ]);
        }

        // CHECK 5, second half: the invitation's OWN derived status, enforced under a row lock inside
        // the repository so two administrators resending at once cannot both win. Accepted or revoked is
        // a 422 on `invitation`; EXPIRED is deliberately allowed, because renewing the expiry is exactly
        // what a resend is for.
        $invitations->resend($organization, $invitation, $this->actor());

        // An acknowledgement, and never the invitation: the interesting thing that happened is a
        // capability that must not be in this body.
        return AcknowledgementResource::ok();
    }

    /**
     * The authenticated administrator, for the mail's "who invited you" line.
     *
     * Never used as a scope — the organization is. See InvitationController::actor().
     */
    private function actor(): User
    {
        $user = request()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}

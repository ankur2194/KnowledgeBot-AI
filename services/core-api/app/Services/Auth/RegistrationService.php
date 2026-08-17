<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Repositories\Eloquent\EloquentOrganizationInvitationRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Closure;
use Illuminate\Container\Attributes\Give;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * The GUEST half of invitations: preview a token, register against it, or accept it while signed in.
 *
 * ── THE FAILURE ORDERING IS THE SECURITY PROPERTY, NOT AN IMPLEMENTATION DETAIL ────────────────
 *
 * Every path here resolves the invitation by `sha256(token)` and then collapses FOUR distinct invalid
 * states — unknown, expired, accepted, revoked — plus a suspended organization into ONE response with
 * ONE body. A distinguishable "already used" proves a guessed token was once real; a distinguishable
 * "expired" proves the same thing; and "the organization is suspended" proves both that the token was
 * real AND something about a tenant the caller is not in.
 *
 * The collapse is achieved by there being exactly one refusal expression per surface, used by every
 * branch:
 *
 *   preview   `abort(404)` -> bootstrap/app.php renders `authorization` / 404 with the single constant
 *             body 'The requested resource was not found.' Every 404 in this application is that same
 *             body, so the four states are indistinguishable from each other AND from a URI with no
 *             route at all.
 *   register  `ValidationException` on `token` with :self::INVALID_INVITATION. The envelope's `message`
 *   accept    is ValidationException::summarize()'s first message, so one string produces one body.
 *
 * ONE EXCEPTION IS ALLOWED TO DISCLOSE, AND ONLY ONE: register answers 422 on `email` when an account
 * already exists for the invitation's address. The caller has, by then, proven possession of a token
 * the organization's administrator deliberately sent to that exact address — so they are being told
 * something about a mailbox they demonstrably control the invitation to. Without it the only remaining
 * answer would be "this invitation is no longer valid" for an invitation that is perfectly valid,
 * leaving the recipient with no route to their own account.
 *
 * ── WHY THIS IS A SEPARATE CLASS FROM InvitationService ────────────────────────────────────────
 *
 * Nothing here is authorized by a membership or a role, because the caller has neither in the
 * organization being joined — the token is the authority, and the role comes off the row. Keeping the
 * two halves in one class would put a method that reads `Gate` beside a method that must not, and the
 * one that must not is the one an unauthenticated caller reaches.
 */
final class RegistrationService
{
    /**
     * The ONE message for unknown, expired, accepted, revoked, address-mismatch, already-a-member, and
     * suspended-organization. It goes on `token` and never on `email`: on `email` it would say "this
     * token is real but was issued to somebody else".
     */
    public const INVALID_INVITATION = 'This invitation is no longer valid.';

    public const ACCOUNT_EXISTS = 'An account already exists for this address. Sign in to accept the invitation.';

    public function __construct(
        // Contextual binding, so no service-provider edit is needed — see InvitationService.
        #[Give(EloquentOrganizationInvitationRepository::class)]
        private readonly OrganizationInvitationRepositoryInterface $invitations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The invitation a token names, for the PREVIEW endpoint.
     *
     * Aborts 404 on every invalid state. That is the deliberate surface difference from register and
     * accept: this endpoint has no request body worth talking about and no field to key an error on, so
     * the enumeration-safe answer is the same body a nonexistent route returns.
     *
     * The organization is eager-loaded by the repository, which InvitationPreviewResource requires.
     */
    public function previewable(#[SensitiveParameter] string $token): OrganizationInvitation
    {
        $invitation = $this->resolve($token);

        if ($invitation === null) {
            // ONE body for all five states, and identical to a URI with no route. See the class
            // docblock.
            abort(404);
        }

        return $invitation;
    }

    /**
     * Create an account against an invitation, in the order §3 endpoint 5 prescribes.
     *
     * 1. (the FormRequest has already produced its 422 by the time we are here)
     * 2. resolve by digest; unknown / expired / accepted / revoked / suspended organization -> ONE 422
     *    on `token`
     * 3. an account already exists for the invitation's address -> 422 on `email`
     * 4. success -> the user, the membership and the consumed invitation, in one transaction
     *
     * The design document's separate step for `hash_equals`-comparing a SUBMITTED address is gone, and
     * its absence is decision D3: RegisterRequest has no `email` field, so the only address in play is
     * the invitation's. See RegisterRequest for the full argument.
     *
     * WHAT THIS METHOD DOES NOT DO: log the user in, regenerate the session, or fire `Registered`.
     * All three are the controller's, and all three must happen AFTER the transaction commits — a
     * session established against a rolled-back user is a session authenticated as nobody, and
     * `event(new Registered($user))` queues verification mail for a user that may not exist.
     *
     * @return array{user: User, invitation: OrganizationInvitation}
     *
     * @throws ValidationException on every refusal
     */
    public function register(
        #[SensitiveParameter] string $token,
        string $name,
        #[SensitiveParameter] string $plaintextPassword,
        ?Request $request = null,
    ): array {
        $invitation = $this->resolve($token);

        if ($invitation === null) {
            throw $this->invalidInvitation();
        }

        // STEP 3, and the one deliberate disclosure on this surface. It is checked BEFORE the write so
        // the caller gets an actionable message rather than a constraint name, and again by
        // `users_email_unique` below so a race cannot create a second account for one address.
        if ($this->invitations->accountExistsFor($invitation->email)) {
            throw ValidationException::withMessages(['email' => [self::ACCOUNT_EXISTS]]);
        }

        try {
            $accepted = $this->invitations->acceptWithNewUser(
                $invitation->id,
                $name,
                $plaintextPassword,
                $this->acceptanceAudit($invitation, $request),
            );
        } catch (QueryException $conflict) {
            // The race the pre-check cannot win: two registrations for one address, or an address that
            // exists in `users` under different case (the pre-check is an exact match; the index is on
            // `lower(email)`). Either way the answer is the message the pre-check would have given.
            if ($this->violates($conflict, 'users_email_unique')) {
                throw ValidationException::withMessages(['email' => [self::ACCOUNT_EXISTS]]);
            }

            throw $conflict;
        }

        if ($accepted === null) {
            // The invitation stopped being pending between the read above and the repository's
            // FOR UPDATE re-read — revoked or accepted by somebody else in that window. The same 422 as
            // every other invalid state: a caller cannot be told they lost a race for a token they may
            // never have held.
            throw $this->invalidInvitation();
        }

        return ['user' => $accepted['user'], 'invitation' => $invitation];
    }

    /**
     * Join an organization as an EXISTING, signed-in user.
     *
     * THE ADDRESS COMPARISON IS THE AUTHORIZATION DECISION. `hash_equals` against the AUTHENTICATED
     * user's address, never against anything in the body — otherwise a signed-in user who intercepts
     * somebody else's invitation link joins an organization it was never issued to. `hash_equals` and
     * not `===` because this is a comparison that decides access, and no call site in this application
     * reaches for `===` on one of those.
     *
     * Invalid token, address mismatch and "already a member" collapse into the one 422 on `token`.
     *
     * @return array{invitation: OrganizationInvitation, organizationId: string}
     *
     * @throws ValidationException on every refusal
     */
    public function accept(
        User $user,
        #[SensitiveParameter] string $token,
        ?Request $request = null,
    ): array {
        $invitation = $this->resolve($token);

        if ($invitation === null) {
            throw $this->invalidInvitation();
        }

        if (! hash_equals($invitation->email, $user->email)) {
            throw $this->invalidInvitation();
        }

        // Already a member — including a SUSPENDED membership, because acceptance INSERTs into
        // `organization_users`, whose primary key is (organization_id, user_id): a second row is a
        // 23505, not a reinstatement. Reinstating a suspended member is a status change an
        // administrator makes, not something an invitation link can do.
        if ($user->membershipFor($invitation->organization_id) !== null) {
            throw $this->invalidInvitation();
        }

        try {
            $membership = $this->invitations->acceptForExistingUser(
                $invitation->id,
                $user,
                $this->acceptanceAudit($invitation, $request),
            );
        } catch (QueryException $conflict) {
            // The race: the same user accepting twice, or accepting while an administrator adds them.
            // `organization_users_pkey` is the constraint the composite primary key creates.
            if ($this->violates($conflict, 'organization_users_pkey')) {
                throw $this->invalidInvitation();
            }

            throw $conflict;
        }

        if ($membership === null) {
            throw $this->invalidInvitation();
        }

        return [
            'invitation' => $invitation,
            'organizationId' => $invitation->organization_id,
        ];
    }

    /**
     * Resolve a token to a PENDING invitation in an ACTIVE organization, or null.
     *
     * Null is returned for every one of: a token this application never issued, an expired one, an
     * accepted one, a revoked one, and a pending one whose organization is suspended. The caller
     * converts that single null into the single refusal its surface uses — which is the whole
     * mechanism by which the five states are one response.
     *
     * CHECK 5 (entity status) LIVES HERE. A suspended organization may not grow, and the
     * enumeration-safe expression of that is the shared refusal rather than a 409: a 409 would say
     * "this token is real, and here is a fact about a tenant you are not in".
     */
    private function resolve(#[SensitiveParameter] string $token): ?OrganizationInvitation
    {
        $invitation = $this->invitations->findByTokenDigest(OpaqueToken::digest($token));

        if ($invitation === null || ! $invitation->isPending()) {
            return null;
        }

        $organization = $invitation->organization;

        if (! $organization instanceof Organization) {
            // The FK is NOT NULL with ON DELETE RESTRICT and the repository eager-loads the relation,
            // so this is unreachable. Loud rather than treated as "invalid invitation": swallowing it
            // would turn a broken eager load into a permanent, plausible "this invitation is no longer
            // valid" for every token in the system.
            throw new RuntimeException(
                'The invitation lookup returned a row with no organization loaded. '
                .OrganizationInvitationRepositoryInterface::class
                .'::findByTokenDigest() must eager-load `organization`.',
            );
        }

        return $organization->status === OrganizationStatus::Active ? $invitation : null;
    }

    /**
     * The `organization.invitation.accepted` audit call, as a closure the repository invokes INSIDE
     * its transaction.
     *
     * The operation is `ON_FAILURE_ABORT`, so a failed audit write rolls back the membership, the
     * consumed invitation and — on the register path — the user itself. That is the correct direction:
     * a membership nobody can prove was granted is worse than a registration the caller can retry.
     *
     * The actor is the ACCEPTING user, which on the register path does not exist until the INSERT — so
     * the closure takes it as an argument rather than closing over it.
     *
     * @return Closure(User): void
     */
    private function acceptanceAudit(OrganizationInvitation $invitation, ?Request $request): Closure
    {
        $organizationId = $invitation->organization_id;
        $invitationId = $invitation->id;
        $email = $invitation->email;
        $role = $invitation->role->value;

        return function (User $user) use (
            $organizationId,
            $invitationId,
            $email,
            $role,
            $request,
        ): void {
            $this->audit->record(
                AuditLogger::INVITATION_ACCEPTED,
                organizationId: $organizationId,
                actorId: $user->id,
                details: [
                    'email' => $email,
                    // BOTH ends of what was granted. `email` alone cannot answer "what did this person
                    // just become", which is the only question an escalation review asks of an
                    // acceptance row.
                    'role' => $role,
                ],
                subjectType: OrganizationInvitation::class,
                subjectId: $invitationId,
                request: $request,
            );
        };
    }

    private function invalidInvitation(): ValidationException
    {
        return ValidationException::withMessages(['token' => [self::INVALID_INVITATION]]);
    }

    /**
     * Whether a QueryException is a unique violation on ONE named constraint. The NAME is checked and
     * not merely SQLSTATE 23505, so an unrelated unique index cannot be reported as this refusal.
     */
    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), $constraint);
    }
}

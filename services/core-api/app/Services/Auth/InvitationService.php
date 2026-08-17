<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\InviteToOrganization;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Eloquent\EloquentOrganizationInvitationRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Give;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The ADMIN half of invitations: issue, revoke, re-issue, list.
 *
 * The guest half — preview, register, accept — is App\Services\Auth\RegistrationService. They are two
 * classes because they authorize by two different things: everything here is authorized by a
 * membership and a role in a KNOWN organization, and everything there is authorized by possession of a
 * token that is what identifies the organization in the first place.
 *
 * ── THE TOKEN IS MINTED HERE AND LEAVES ONLY IN THE MAIL ───────────────────────────────────────
 *
 * `OpaqueToken::mint()` is called in this class, its DIGEST goes to the database, and its PLAINTEXT
 * goes to exactly one place: the queued App\Notifications\InviteToOrganization. It is never returned
 * to the caller, never in a response body, never in a log line, and in the audit row it is
 * FINGERPRINTED — AuditLogger's map declares `token` as FINGERPRINTED, so there is no call site that
 * could echo it even by passing it under that key.
 *
 * ── EVERY AUDIT WRITE SHARES A TRANSACTION WITH THE STATE CHANGE IT RECORDS ────────────────────
 *
 * `organization.invitation.created` and `.revoked` are `ON_FAILURE_ABORT`, which means an audit write
 * that fails must roll the state change back. AuditLogger opens no transaction of its own, and
 * `Illuminate\Support\Facades\DB` is arch-pinned to `App\Repositories\Eloquent`, so the wrapping is
 * done by the repository: each mutating method takes the audit call as a REQUIRED closure argument and
 * invokes it inside its own transaction. See OrganizationInvitationRepositoryInterface for the full
 * argument. The closures below are what land in that transaction.
 *
 * ── MAIL IS QUEUED AFTER THE TRANSACTION, NEVER INSIDE IT ──────────────────────────────────────
 *
 * A `Notification::route(...)->notify()` inside the transaction would push the job to Valkey before
 * PostgreSQL committed. Valkey does not participate in the transaction, so a rollback leaves a live
 * invitation link in a worker's hands for a row that does not exist — and the recipient's "this
 * invitation is no longer valid" is unexplainable from either side.
 */
final class InvitationService
{
    /**
     * A live invitation already occupies (organization, address).
     *
     * "Resend it instead" rather than "revoke it first" because resending is the shorter correct path
     * and it renews the expiry: `organization_invitations_one_pending_per_email` counts an EXPIRED but
     * un-revoked row as live, so "expired" and "still pending" reach this message alike and both are
     * fixed by the same button.
     */
    public const ALREADY_INVITED = 'An invitation for this address is already pending. Resend it instead.';

    public const ALREADY_MEMBER = 'This person is already a member of this organization.';

    public const NOT_RESENDABLE = 'This invitation can no longer be resent.';

    public const NOT_REVOCABLE = 'This invitation can no longer be revoked.';

    /**
     * Fallback TTL when `kb.invitation_ttl_hours` is missing or nonsense.
     *
     * config/kb.php already casts the env value to `int`, so the routes here are an unconfigured test
     * harness or somebody setting it to 0 — and a zero-hour TTL mints links that expire before the
     * mail is delivered, which reads to the recipient as "the link never worked".
     */
    private const FALLBACK_TTL_HOURS = 168;

    public function __construct(
        // No service-provider binding needed: `#[Give]` is a contextual container attribute, so this
        // resolves with nothing registered in any provider — which is what lets this unit ship without
        // editing AppServiceProvider, another agent's file this batch. Moving the binding into
        // AppServiceProvider::bindRepositories() and deleting the attribute later is behaviour-
        // preserving.
        #[Give(EloquentOrganizationInvitationRepository::class)]
        private readonly OrganizationInvitationRepositoryInterface $invitations,
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<OrganizationInvitation>
     */
    public function listFor(Organization $organization): array
    {
        return $this->invitations->forOrganization($organization->organizationId());
    }

    /**
     * Issue an invitation and queue its mail.
     *
     * The two refusals below are 422s and they DO disclose, to an administrator of this organization,
     * whether an address is already invited or already a member. That is information they are entitled
     * to and can read from `GET /invitations` and `GET /members` anyway, and withholding it would
     * leave "why did nothing happen when I clicked invite" unanswerable.
     *
     * @throws ValidationException on either refusal, keyed on `email`
     */
    public function invite(
        Organization $organization,
        User $actor,
        string $email,
        OrgRole $role,
        ?Request $request = null,
    ): OrganizationInvitation {
        $organizationId = $organization->organizationId();

        if ($this->organizations->hasMemberWithEmail($organizationId, $email)) {
            throw ValidationException::withMessages(['email' => [self::ALREADY_MEMBER]]);
        }

        if ($this->invitations->liveFor($organizationId, $email) !== null) {
            throw ValidationException::withMessages(['email' => [self::ALREADY_INVITED]]);
        }

        $token = OpaqueToken::mint();
        $expiresAt = $this->expiryFrom(CarbonImmutable::now());

        try {
            $invitation = $this->invitations->createPending(
                organizationId: $organizationId,
                email: $email,
                role: $role,
                tokenDigest: OpaqueToken::digest($token),
                invitedById: $actor->id,
                expiresAt: $expiresAt,
                // INSIDE the repository's transaction. `organization.invitation.created` is
                // ON_FAILURE_ABORT, so if this write fails the invitation does not exist and no mail
                // is queued — the ordering below depends on that.
                audit: function (OrganizationInvitation $created) use (
                    $organizationId,
                    $actor,
                    $email,
                    $role,
                    $token,
                    $expiresAt,
                    $request,
                ): void {
                    $this->audit->record(
                        AuditLogger::INVITATION_CREATED,
                        organizationId: $organizationId,
                        actorId: $actor->id,
                        details: [
                            'email' => $email,
                            'role' => $role->value,
                            // Declared FINGERPRINTED in AuditLogger::OPERATIONS, so what lands in the
                            // row is `token_fingerprint` — a keyed HMAC prefix that answers "was THIS
                            // link the one used" without being a live capability in an append-only
                            // table.
                            'token' => $token,
                            'expires_at' => $expiresAt->toAtomString(),
                        ],
                        subjectType: OrganizationInvitation::class,
                        subjectId: $created->id,
                        request: $request,
                    );
                },
            );
        } catch (QueryException $conflict) {
            // THE RACE THE PRE-CHECK CANNOT WIN. Two administrators invite the same address in the
            // same instant, both read "nothing pending", both INSERT, and the second COMMIT gets 23505
            // from `organization_invitations_one_pending_per_email`. The outcome the loser deserves is
            // the same 422 the pre-check produces, not a 500: the database has simply told them what
            // the pre-check would have, a few milliseconds later.
            if ($this->violates($conflict, 'organization_invitations_one_pending_per_email')) {
                throw ValidationException::withMessages(['email' => [self::ALREADY_INVITED]]);
            }

            throw $conflict;
        }

        $this->deliver($invitation, $organization, $actor, $token);

        // Set rather than loaded: InvitationResource renders `invited_by_name` and throws without the
        // relation, `Model::shouldBeStrict()` forbids the lazy read, and the inviter is the actor we
        // already hold. A `->load('invitedBy')` here would be one query to fetch a row in memory.
        $invitation->setRelation('invitedBy', $actor);

        return $invitation;
    }

    /**
     * Revoke, killing the mailed link.
     *
     * @throws ValidationException when the invitation has been accepted, keyed on `invitation`
     */
    public function revoke(
        Organization $organization,
        OrganizationInvitation $invitation,
        User $actor,
        ?Request $request = null,
    ): OrganizationInvitation {
        $organizationId = $organization->organizationId();
        $email = $invitation->email;
        $role = $invitation->role->value;

        $revoked = $this->invitations->revoke(
            $organizationId,
            $invitation->id,
            // Inside the transaction. `organization.invitation.revoked` is ON_FAILURE_ABORT, so a
            // failed audit write leaves the invitation LIVE rather than silently revoking it with no
            // record — which is the correct direction for a revocation nobody can prove happened.
            function (OrganizationInvitation $row) use (
                $organizationId,
                $actor,
                $email,
                $role,
                $request,
            ): void {
                $this->audit->record(
                    AuditLogger::INVITATION_REVOKED,
                    organizationId: $organizationId,
                    actorId: $actor->id,
                    details: [
                        'email' => $email,
                        'role' => $role,
                    ],
                    subjectType: OrganizationInvitation::class,
                    subjectId: $row->id,
                    request: $request,
                );
            },
        );

        if ($revoked === null) {
            // Accepted invitations only. An already-revoked one comes back unchanged, so a
            // double-click gets a 200 and DELETE stays idempotent.
            throw ValidationException::withMessages(['invitation' => [self::NOT_REVOCABLE]]);
        }

        return $revoked;
    }

    /**
     * Mail the invitation again, under a NEW token. The previously mailed link stops working.
     *
     * AUDITED AS `organization.invitation.resent`, under ABORT policy, written inside the same
     * transaction as the token rotation via the required `Closure $audit` argument on `rotateToken()`.
     *
     * That operation was ADDED for this method (D28) rather than reused: re-issuing a bearer capability
     * with no audit row is a real §18.11 gap, and reusing `organization.invitation.created` to mean
     * "re-created" would make the table assert one invitation was created twice — breaking the
     * "count creations to count invitees" reading. The audit closure is a REQUIRED parameter, not an
     * optional one, so a future caller cannot rotate a token without recording it.
     *
     * @throws ValidationException when the invitation is accepted or revoked, keyed on `invitation`
     */
    public function resend(
        Organization $organization,
        OrganizationInvitation $invitation,
        User $actor,
    ): OrganizationInvitation {
        $token = OpaqueToken::mint();

        $expiresAt = $this->expiryFrom(CarbonImmutable::now());
        $organizationId = $organization->organizationId();
        $request = request();

        $rotated = $this->invitations->rotateToken(
            $organizationId,
            $invitation->id,
            OpaqueToken::digest($token),
            // RENEWED, not carried over. A resend of an expired invitation whose `expires_at` stayed
            // in the past would mail a link that is dead on arrival.
            $expiresAt,
            // Audited as `organization.invitation.resent`, NOT as a second `created`: a resend issues a
            // new capability and kills the old one, so the trail must show one row per link actually
            // mailed. Without this, an administrator could mail an unbounded number of live links to a
            // third party's mailbox and the record would show a single creation from months earlier.
            function (OrganizationInvitation $fresh) use ($organizationId, $actor, $token, $expiresAt, $request): void {
                $this->audit->record(
                    AuditLogger::INVITATION_RESENT,
                    organizationId: $organizationId,
                    actorId: $actor->id,
                    details: [
                        'email' => $fresh->email,
                        'role' => $fresh->role->value,
                        // The NEW token. Declared FINGERPRINTED, so the row holds a keyed HMAC prefix
                        // and never the capability — which is what lets two resends be told apart
                        // later without either being replayable from the audit table.
                        'token' => $token,
                        'expires_at' => $expiresAt->toAtomString(),
                    ],
                    subjectType: OrganizationInvitation::class,
                    subjectId: $fresh->id,
                    request: $request,
                );
            },
        );

        if ($rotated === null) {
            throw ValidationException::withMessages(['invitation' => [self::NOT_RESENDABLE]]);
        }

        $this->deliver($rotated, $organization, $actor, $token);

        return $rotated;
    }

    /**
     * Queue the mail. AFTER the transaction, always — see the class docblock.
     *
     * `Notification::route('mail', $email)` and not `$user->notify()`: the recipient usually has no
     * account, which is what an invitation is for. Every argument is a SCALAR, because a queued
     * notification carrying the model would re-query it on the worker through SerializesModels and
     * could then mail an invitation whose row was revoked in the interim — or fail outright for a row
     * an administrator deleted a second after sending.
     */
    private function deliver(
        OrganizationInvitation $invitation,
        Organization $organization,
        User $actor,
        string $token,
    ): void {
        Notification::route('mail', $invitation->email)->notify(new InviteToOrganization(
            $token,
            $organization->name,
            $invitation->role,
            $actor->name,
            $invitation->expires_at,
        ));
    }

    /**
     * The TTL, read from config once per call and applied to a caller-supplied clock read.
     */
    private function expiryFrom(CarbonImmutable $now): CarbonImmutable
    {
        $hours = config('kb.invitation_ttl_hours');

        return $now->addHours(
            is_int($hours) && $hours > 0 ? $hours : self::FALLBACK_TTL_HOURS,
        );
    }

    /**
     * Whether a QueryException is a unique violation on ONE named constraint.
     *
     * The NAME is checked and not merely SQLSTATE 23505: this table carries three unique indexes, and
     * mapping any of them onto "already invited" would report a token-digest collision or a primary-key
     * clash as a validation error on an address.
     */
    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), $constraint);
    }
}

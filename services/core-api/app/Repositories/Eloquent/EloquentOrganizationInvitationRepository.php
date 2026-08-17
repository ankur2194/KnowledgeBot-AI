<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * See the interface for why every mutating method takes an audit closure, and for the tenancy
 * argument. Three mechanical notes belong here, beside the code they bite.
 *
 * ── ELOQUENT DOES NOT APPLY CASTS TO WHERE BINDINGS ─────────────────────────────────────────────
 *
 * `token_hash` is a `bytea` cast through App\Support\Crypto\BinaryCast, which encodes on WRITE only.
 * A `where('token_hash', $rawDigest)` bypasses it — `Query\Builder::castBinding()` special-cases
 * BackedEnum and nothing else — so a raw 32-byte sha256 arrives as a text parameter and either fails
 * with `22021 invalid byte sequence for encoding "UTF8"` or, on the digests that happen to be valid
 * UTF-8, silently matches nothing. The second outcome is the dangerous one: it renders as "this
 * invitation is no longer valid" for a perfectly good token. :self::bytea() exists for that one line.
 * The identical note and the identical helper live in EloquentEmailVerificationTokenRepository, which
 * has the same column shape; they are two call sites of one PostgreSQL wire format rather than two
 * ideas.
 *
 * ── EVERY STATE TRANSITION RE-READS THE ROW `FOR UPDATE` ───────────────────────────────────────
 *
 * Revoke, resend and both accept paths lock the invitation and re-check its state inside the
 * transaction. The caller has already checked — it needed the row to authorize and to render — but
 * that check is a read outside the transaction, and the gap between it and the write is exactly where
 * two administrators (or an administrator and an invitee) disagree. Without the re-read the loser
 * writes over the winner and `organization_invitations_terminal_once` reports it as a bare constraint
 * name in a 500; with it, the loser gets the endpoint's own 422.
 *
 * ── THE CLOCK IS READ ONCE PER TRANSACTION ─────────────────────────────────────────────────────
 *
 * `accepted_at` and the membership's `created_at` come from one CarbonImmutable. Two reads inside one
 * transaction can disagree, and an acceptance whose timestamps differ by a millisecond is a fact an
 * investigation has to explain for no reason.
 */
final class EloquentOrganizationInvitationRepository implements OrganizationInvitationRepositoryInterface
{
    public function findByTokenDigest(string $tokenDigest): ?OrganizationInvitation
    {
        // tenancy-exempt: this is the GUEST lookup, and it runs before any organization is known —
        // the token is what identifies the organization. App\Models\OrganizationInvitation carries no
        // OrganizationScope for exactly this reason (its docblock explains that the scope fails closed
        // and would break registration silently). The narrowing predicate is 32 bytes of sha256 over
        // 256 bits of entropy behind a UNIQUE index, so there is no shape of this query that returns
        // some organization's invitations to a caller who does not already hold the token.
        return OrganizationInvitation::query()
            ->where('token_hash', self::bytea($tokenDigest))
            // shouldBeStrict() turns a lazy access into an exception, and every caller needs the
            // organization: the preview renders its name, register and accept check its status.
            ->with('organization')
            ->first();
    }

    public function accountExistsFor(string $email): bool
    {
        // tenancy-exempt: `users` has no organization_id, because a user is not owned by an
        // organization — membership is a separate row. See the interface for why the address this
        // takes cannot be chosen by a caller.
        //
        // An exact match, not `whereRaw('lower(email) = ?')`. The address always arrives from an
        // `organization_invitations` row, which `organization_invitations_email_lowercase` guarantees
        // is lower-case, and every path that creates a user lower-cases first. The residual case — a
        // mixed-case `users` row from some other era — is caught one layer down by
        // `users_email_unique`, which IS on `lower(email)`: the INSERT raises 23505 and the caller
        // maps it to the same 422 this check produces. A false negative here is therefore a slower
        // route to the same answer, not a wrong one.
        return User::query()->where('email', $email)->exists();
    }

    /**
     * @return list<OrganizationInvitation>
     */
    public function forOrganization(string $organizationId): array
    {
        $invitations = OrganizationInvitation::query()
            // The only tenant predicate there is on this table — see the interface. Required and
            // positional so it cannot be omitted.
            ->where('organization_id', $organizationId)
            // InvitationResource throws without it, and shouldBeStrict() would throw first.
            ->with('invitedBy')
            // Matches `organization_invitations_org_created` (organization_id, created_at DESC), so
            // this is an index scan rather than a sort. `id` is the tiebreak: `char(26) COLLATE "C"`
            // makes ULID byte order total and reproducible for two rows created in the same
            // microsecond, and an unordered list is a list that reorders itself between requests.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->all();

        return array_values($invitations);
    }

    public function liveFor(string $organizationId, string $email): ?OrganizationInvitation
    {
        // The predicate is the partial unique index's, character for character. See the interface for
        // why expired rows are deliberately included.
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * @param  Closure(OrganizationInvitation): void  $audit
     */
    public function createPending(
        string $organizationId,
        string $email,
        OrgRole $role,
        string $tokenDigest,
        string $invitedById,
        CarbonImmutable $expiresAt,
        Closure $audit,
    ): OrganizationInvitation {
        return DB::transaction(function () use (
            $organizationId,
            $email,
            $role,
            $tokenDigest,
            $invitedById,
            $expiresAt,
            $audit,
        ): OrganizationInvitation {
            $invitation = new OrganizationInvitation;

            // The ownership and authority columns are assigned here and only here. None of them is in
            // `$fillable` — `organization_id` is the tenant key, `token_hash` is the capability, and
            // `invited_by_id` is who is accountable — so this is the one place they can be set, and
            // `Model::shouldBeStrict()` turns any mass-assignment attempt on them into an exception.
            $invitation->organization_id = $organizationId;
            $invitation->invited_by_id = $invitedById;
            // Raw bytes in; BinaryCast encodes them for the `bytea` column on write.
            $invitation->token_hash = $tokenDigest;

            $invitation->email = $email;
            $invitation->role = $role;
            $invitation->expires_at = $expiresAt;

            $invitation->save();

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT so
            // an ON_FAILURE_ABORT audit failure rethrows and takes the invitation with it. This is the
            // whole reason the closure is a required argument. See the interface.
            $audit($invitation);

            return $invitation;
        });
    }

    /**
     * @param  Closure(OrganizationInvitation): void  $audit
     */
    public function revoke(
        string $organizationId,
        string $invitationId,
        Closure $audit,
    ): ?OrganizationInvitation {
        return DB::transaction(function () use ($organizationId, $invitationId, $audit): ?OrganizationInvitation {
            $invitation = $this->lock($organizationId, $invitationId);

            if ($invitation === null || $invitation->accepted_at !== null) {
                // An accepted invitation is not revocable: the membership exists, and
                // `organization_invitations_terminal_once` would reject the write anyway. Null so the
                // caller renders its own 422 rather than a constraint name in a 500.
                return null;
            }

            if ($invitation->revoked_at !== null) {
                // ALREADY REVOKED — a no-op, and no audit row. DELETE stays idempotent (a double-click
                // in the console is not an error), and the audit table does not accumulate a second
                // "revoked" row for a revocation that did not happen.
                return $invitation;
            }

            $invitation->revoked_at = CarbonImmutable::now();
            $invitation->save();

            $audit($invitation);

            return $invitation;
        });
    }

    public function rotateToken(
        string $organizationId,
        string $invitationId,
        string $tokenDigest,
        CarbonImmutable $expiresAt,
        Closure $audit,
    ): ?OrganizationInvitation {
        return DB::transaction(function () use (
            $organizationId,
            $invitationId,
            $tokenDigest,
            $expiresAt,
            $audit,
        ): ?OrganizationInvitation {
            $invitation = $this->lock($organizationId, $invitationId);

            if ($invitation === null
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null) {
                // Nothing left to deliver. An EXPIRED invitation is deliberately NOT refused here —
                // renewing one is the product answer to "the link expired", and the row is still the
                // one `organization_invitations_one_pending_per_email` considers live, so re-inviting
                // instead would collide.
                return null;
            }

            // THE PREVIOUS LINK DIES HERE. Overwriting the digest is what makes an invitation
            // revocable at all, and it is why this endpoint is a POST rather than a PUT.
            $invitation->token_hash = $tokenDigest;
            $invitation->expires_at = $expiresAt;

            $invitation->save();

            // INSIDE the transaction, after the rotation. An ON_FAILURE_ABORT write that raised here
            // rolls the new digest back, so the previously-mailed link keeps working rather than the
            // invitation ending up with a capability nothing recorded.
            $audit($invitation);

            return $invitation;
        });
    }

    /**
     * @param  Closure(User): void  $audit
     * @return array{user: User, membership: OrganizationUser}|null
     */
    public function acceptWithNewUser(
        string $invitationId,
        string $name,
        #[SensitiveParameter] string $plaintextPassword,
        Closure $audit,
    ): ?array {
        return DB::transaction(function () use ($invitationId, $name, $plaintextPassword, $audit): ?array {
            $invitation = $this->lockAcceptable($invitationId);

            if ($invitation === null) {
                return null;
            }

            $now = CarbonImmutable::now();

            $user = new User;
            $user->name = $name;
            // FROM THE INVITATION ROW, NEVER FROM THE REQUEST. RegisterRequest has no `email` field
            // at all (decision D3), so this is the only address in play and an invitee cannot register
            // under somebody else's. The row is guaranteed lower-case by a CHECK constraint.
            $user->email = $invitation->email;
            // `setAttribute` AND NOT `$user->password = …`, for the same reason
            // EloquentProviderConnectionRepository writes its ciphertext columns this way: neither
            // `password` nor `email_verified_at` is declared in App\Models\User's `@property` block, so
            // direct assignment is a PHPStan level-8 `property.notFound`. `setAttribute()` is the same
            // code path — it is what `__set` calls — so the `hashed` cast still applies
            // (HasAttributes::castAttributeAsHashedString). Nothing here holds a hash and nothing
            // pre-hashes: one algorithm, decided by config/hashing.php.
            $user->setAttribute('password', $plaintextPassword);
            // UNVERIFIED, EXPLICITLY. An invitation proves an ADMINISTRATOR chose the address, which
            // is not the same as the recipient controlling the mailbox. The verification mail is
            // queued after COMMIT by the caller's `event(new Registered($user))`.
            $user->setAttribute('email_verified_at', null);
            $user->is_platform_owner = false;

            $user->save();

            $membership = $this->attachMembership($invitation, $user, $now);

            $this->consume($invitation, $user, $now);

            // Inside the transaction, and after the user exists so the audit row can name an actor.
            $audit($user);

            return ['user' => $user, 'membership' => $membership];
        });
    }

    /**
     * @param  Closure(User): void  $audit
     */
    public function acceptForExistingUser(
        string $invitationId,
        User $user,
        Closure $audit,
    ): ?OrganizationUser {
        return DB::transaction(function () use ($invitationId, $user, $audit): ?OrganizationUser {
            $invitation = $this->lockAcceptable($invitationId);

            if ($invitation === null) {
                return null;
            }

            $now = CarbonImmutable::now();

            $membership = $this->attachMembership($invitation, $user, $now);

            $this->consume($invitation, $user, $now);

            $audit($user);

            return $membership;
        });
    }

    /**
     * The membership acceptance creates: role FROM THE INVITATION ROW, status Active.
     *
     * Neither value is a parameter and neither can be: a role that arrived from a request would be a
     * privilege the caller granted themselves, and `MembershipStatus::Invited` has no producer by
     * design — an invitation creates no membership row until it is accepted, so there is no
     * "pending" membership for `membershipFor()` to have to reason about.
     */
    private function attachMembership(
        OrganizationInvitation $invitation,
        User $user,
        CarbonImmutable $now,
    ): OrganizationUser {
        $membership = new OrganizationUser;

        // The composite primary key. Neither column is fillable.
        $membership->organization_id = $invitation->organization_id;
        $membership->user_id = $user->id;

        $membership->role = $invitation->role;
        $membership->status = MembershipStatus::Active;

        // Written explicitly from the one clock read, because `attachMembership` and `consume` must
        // agree: MemberResource publishes this as `joined_at` beside the invitation's `accepted_at`, and
        // two reads of the clock in one transaction can disagree.
        //
        // `setAttribute` rather than direct assignment: the model's `@property` block types these as
        // `Illuminate\Support\Carbon|null`, which does not accept a CarbonImmutable at PHPStan level 8.
        // The date attributes go through `fromDateTime()` either way, which takes any DateTimeInterface.
        // Setting them at all is what stops `updateTimestamps()` overwriting them — it skips a column
        // that is already dirty.
        $membership->setAttribute('created_at', $now);
        $membership->setAttribute('updated_at', $now);

        $membership->save();

        return $membership;
    }

    /**
     * Burn the invitation. `accepted_at` and `accepted_by_id` move together because
     * `organization_invitations_accepted_has_actor` CHECKs that they do.
     */
    private function consume(
        OrganizationInvitation $invitation,
        User $user,
        CarbonImmutable $now,
    ): void {
        $invitation->accepted_at = $now;
        $invitation->accepted_by_id = $user->id;

        $invitation->save();
    }

    /**
     * The locked row for an ADMIN action: scoped to the organization, because that is the only tenant
     * layer this table has.
     */
    private function lock(string $organizationId, string $invitationId): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($invitationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The locked row for an ACCEPT action, re-checked as still pending.
     *
     * No organization argument, and that is not an omission: the caller reached this row by presenting
     * its token, and the organization is a fact the row carries rather than a scope the caller could
     * supply. `isPending()` collapses expired, accepted and revoked into one null, exactly as the
     * endpoints collapse them into one 422.
     */
    private function lockAcceptable(string $invitationId): ?OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()
            ->whereKey($invitationId)
            ->lockForUpdate()
            ->first();

        return $invitation !== null && $invitation->isPending() ? $invitation : null;
    }

    /**
     * PostgreSQL's own `bytea` hex input format — pure ASCII, losslessly cast by the server.
     *
     * The write path gets this from BinaryCast; a WHERE binding does not. See the class docblock.
     */
    private static function bytea(string $raw): string
    {
        return '\x'.bin2hex($raw);
    }
}

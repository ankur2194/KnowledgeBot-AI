<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\OrgRole;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use SensitiveParameter;

/**
 * Every read and every write of `organization_invitations`, plus the two multi-table units of work
 * that acceptance is.
 *
 * ── WHY EVERY MUTATING METHOD TAKES A `Closure $audit` ─────────────────────────────────────────
 *
 * All four invitation audit operations — `organization.invitation.created`, `.revoked`, `.accepted`
 * and `organization.member.role_changed` — are `ON_FAILURE_ABORT` in
 * App\Services\Audit\AuditLogger::OPERATIONS. That policy means what it says: if the audit row cannot
 * be written, the state change MUST NOT COMMIT. AuditLogger deliberately opens no transaction of its
 * own (its docblock: "a service that opens its own transaction around one INSERT gives the appearance
 * of atomicity without the fact"), so wrapping is the caller's job.
 *
 * The caller cannot do it, though: `Illuminate\Support\Facades\DB` is pinned to
 * `App\Repositories\Eloquent` by `tests/Arch/DoctrineTest.php:40-42`, so `DB::transaction()` in a
 * service or a controller fails the Arch suite. Two shapes were available:
 *
 *   * expose a generic `transaction(Closure)` here and let the service compose the write and the
 *     audit inside it — which works, and makes "did anyone forget to wrap it" a question about every
 *     future call site;
 *   * take the audit closure as a REQUIRED argument on each mutating method, and invoke it inside the
 *     transaction the method already opens.
 *
 * The second is chosen because it is not forgettable. There is no way to call `createPending()`
 * without supplying the thing that has to happen inside its transaction, and the compiler asks for
 * it. Each closure receives whatever value only exists after the INSERT — the invitation, or the
 * newly created user — so a caller cannot audit an id it has not got.
 *
 * ── TENANCY ────────────────────────────────────────────────────────────────────────────────────
 *
 * App\Models\OrganizationInvitation carries NO `#[ScopedBy(OrganizationScope::class)]`, by a decision
 * documented at length on the model: the guest paths read this table before any organization is
 * known, and OrganizationScope fails closed. So the explicit `organization_id` argument on every
 * ADMIN method here is not the backstop's companion — it is the only layer, and it is required and
 * positional for exactly that reason. The two GUEST reads (`findByTokenDigest`,
 * `accountExistsFor`) are org-agnostic by construction and each carries a `// tenancy-exempt:` marker
 * at its implementation.
 */
interface OrganizationInvitationRepositoryInterface
{
    /**
     * The ONE guest lookup: by the 32-byte sha256 digest, behind the UNIQUE index.
     *
     * Takes the DIGEST and not the plaintext token, so no repository method has a parameter a
     * plaintext capability could be passed to by mistake. The `organization` relation is eager-loaded
     * because every caller needs it — the preview renders the organization's name, and register and
     * accept both check its status — and `Model::shouldBeStrict()` turns a lazy access into an
     * exception rather than an N+1.
     *
     * Returns null for a token this application never issued. It does NOT filter on expiry,
     * acceptance or revocation: the caller decides validity from the timestamps, because all four
     * invalid states must produce ONE indistinguishable response and a filtering query would give
     * the caller no way to tell "expired" from "never existed" even for its own logs.
     */
    public function findByTokenDigest(string $tokenDigest): ?OrganizationInvitation;

    /**
     * Whether a `users` row already exists for an address.
     *
     * ORG-AGNOSTIC ON PURPOSE — `users` has no `organization_id`, because a user is not owned by an
     * organization. It is safe because the address it takes comes off an invitation row the
     * organization's own administrator addressed, never from a request body: there is no shape of
     * this method a caller can point at an address of their choosing.
     */
    public function accountExistsFor(string $email): bool;

    /**
     * The organization's invitations for the admin list — ALL of them, including accepted, revoked
     * and expired rows, newest first, with `invitedBy` eager-loaded (InvitationResource throws
     * without it, and `Model::shouldBeStrict()` would throw first).
     *
     * @return list<OrganizationInvitation>
     */
    public function forOrganization(string $organizationId): array;

    /**
     * The row `organization_invitations_one_pending_per_email` considers LIVE for (organization,
     * address): `accepted_at IS NULL AND revoked_at IS NULL`.
     *
     * The predicate mirrors the partial unique index exactly, INCLUDING expired rows, which is not an
     * oversight: an expired-but-not-revoked invitation still occupies the index, so a re-invite would
     * fail with 23505 if this pre-check pretended otherwise. The product answer to "the invitation
     * expired" is to resend it, which renews the same row.
     */
    public function liveFor(string $organizationId, string $email): ?OrganizationInvitation;

    /**
     * Create one pending invitation and audit it, atomically.
     *
     * @param  string  $tokenDigest  OpaqueToken::digest() output — 32 RAW bytes, never the plaintext
     * @param  Closure(OrganizationInvitation): void  $audit  invoked INSIDE the transaction, after the
     *                                                        INSERT and before the COMMIT, so an
     *                                                        `ON_FAILURE_ABORT` audit write that
     *                                                        throws rolls the invitation back
     */
    public function createPending(
        string $organizationId,
        string $email,
        OrgRole $role,
        string $tokenDigest,
        string $invitedById,
        CarbonImmutable $expiresAt,
        Closure $audit,
    ): OrganizationInvitation;

    /**
     * Revoke, killing the mailed link.
     *
     * Re-reads the row FOR UPDATE inside the transaction and re-checks its state, so two
     * administrators acting at once cannot produce a revoked-and-accepted row (which
     * `organization_invitations_terminal_once` would reject with a constraint name).
     *
     * @param  Closure(OrganizationInvitation): void  $audit  invoked inside the transaction, and only
     *                                                        when a transition actually happened
     * @return OrganizationInvitation|null the updated row; null when the invitation was already
     *                                     ACCEPTED, which is not revocable — a membership exists and
     *                                     saying otherwise would be a lie in an append-only table. An
     *                                     already-revoked row is returned unchanged with no audit
     *                                     row, so DELETE stays idempotent.
     */
    public function revoke(string $organizationId, string $invitationId, Closure $audit): ?OrganizationInvitation;

    /**
     * Mint a new capability over the same invitation: overwrite `token_hash` and push `expires_at`
     * out, so the PREVIOUSLY MAILED LINK STOPS WORKING.
     *
     * That is the correct semantics rather than a limitation — two live links to one invitation are
     * two bearer capabilities carrying one authority — and it is what makes revocation mean anything:
     * revoking a digest column only matters if the digest is the only way in.
     *
     * NO AUDIT CLOSURE, and it is a REPORTED GAP rather than a decision: AuditLogger::OPERATIONS has
     * no `organization.invitation.resent` operation, an unknown operation name throws by design, and
     * that file is not in this change set's scope. Re-issuing a bearer capability with no audit row
     * is a real §18.11 gap; it is flagged in the report, not papered over by reusing
     * `organization.invitation.created` to mean something it does not.
     *
     * @return OrganizationInvitation|null null when the row is accepted or revoked — there is nothing
     *                                     left to deliver
     */
    /**
     * A RESEND MINTS A NEW BEARER CAPABILITY, so it is audited like any other issuance, and the audit
     * write is a REQUIRED argument rather than the caller's responsibility for the same reason every
     * other mutator here takes one: `organization.invitation.resent` is ON_FAILURE_ABORT, the row must
     * land in the same transaction as the digest it describes, and `AuditLogger` opens no transaction
     * of its own. Passing it in makes omitting it impossible instead of merely discouraged.
     *
     * @param  Closure(OrganizationInvitation): void  $audit  invoked INSIDE the transaction, after the
     *                                                        rotation, with the rotated row
     */
    public function rotateToken(
        string $organizationId,
        string $invitationId,
        string $tokenDigest,
        CarbonImmutable $expiresAt,
        Closure $audit,
    ): ?OrganizationInvitation;

    /**
     * REGISTRATION: create the user, create the membership, consume the invitation, audit — one
     * transaction, in that order.
     *
     * The user is created UNVERIFIED. `email_verified_at` stays null and the verification mail is
     * queued after COMMIT by `event(new Registered($user))`; an invitation proves an administrator
     * chose the address, which is not the same as the recipient controlling the mailbox.
     *
     * The membership's role comes off the invitation row and its status is Active. Neither is a
     * parameter, because neither may come from a request.
     *
     * @param  string  $plaintextPassword  assigned to `User::$password`, whose `hashed` cast hashes
     *                                     it. It is not pre-hashed by the caller: the cast is the one
     *                                     place hashing happens, and a second one would drift on the
     *                                     algorithm the day config/hashing.php changes.
     * @param  Closure(User): void  $audit  invoked inside the transaction with the created user, so
     *                                      the `organization.invitation.accepted` row can name an
     *                                      actor that did not exist when the caller was written
     * @return array{user: User, membership: OrganizationUser}|null null when the invitation stopped
     *                                                              being pending between the caller's
     *                                                              read and this method's FOR UPDATE
     *                                                              re-read
     */
    public function acceptWithNewUser(
        string $invitationId,
        string $name,
        #[SensitiveParameter] string $plaintextPassword,
        Closure $audit,
    ): ?array;

    /**
     * ACCEPTANCE BY AN EXISTING USER: create the membership, consume the invitation, audit — one
     * transaction.
     *
     * @param  Closure(User): void  $audit  invoked inside the transaction
     * @return OrganizationUser|null null when the invitation stopped being pending under the lock
     */
    public function acceptForExistingUser(
        string $invitationId,
        User $user,
        Closure $audit,
    ): ?OrganizationUser;
}

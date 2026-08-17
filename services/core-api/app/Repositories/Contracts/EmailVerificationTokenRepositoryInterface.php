<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The only reader and writer of `email_verification_tokens`.
 *
 * ── NO `organization_id` ARGUMENT ON ANY METHOD, AND THAT IS DELIBERATE ──────────────────────────
 *
 * tenancy-exempt: verification is a fact about a USER, not about a tenant. The table has no
 * `organization_id` column and no FK chain to one (see the migration and App\Models\
 * EmailVerificationToken), so there is no scope to apply and adding one would be inventing a
 * relationship the schema does not have. It is one of exactly two tables in this application with
 * that property; `password_reset_tokens` is the other, and both are read on GUEST paths where no
 * organization can be known — the verification link is opened from a mail client, frequently in a
 * different browser from the one that registered.
 *
 * WHAT MAKES THE ABSENCE SAFE, stated per method below rather than once in general: every read is
 * narrowed either by `token_hash` — 32 bytes of sha256 over 256 bits of CSPRNG entropy, behind a
 * UNIQUE index — or by a `user_id` taken from the authenticated session and never from a request
 * body. There is no method here whose arguments an attacker can shape into "somebody else's row".
 *
 * ── WHY `consume()` TAKES A CLOSURE ─────────────────────────────────────────────────────────────
 *
 * `AuditLogger::EMAIL_VERIFIED` is an `ON_FAILURE_ABORT` operation, so its row must be written
 * inside the same transaction as the `email_verified_at` write — otherwise a verification can commit
 * with no audit row and the compliance record is quietly incomplete. AuditLogger deliberately opens
 * no transaction of its own (its docblock says so and explains why), and
 * `Illuminate\Support\Facades\DB` is arch-banned outside `App\Repositories\Eloquent`
 * (tests/Arch/DoctrineTest.php). Those three facts together leave exactly one shape: the
 * transaction lives here, and the caller hands in the audit write as a closure that runs inside it.
 *
 * The alternative — a public `transaction(Closure)` passthrough on a repository — was rejected
 * because it re-exports the facade under a new name and every service would then own an
 * ad-hoc transaction boundary again.
 */
interface EmailVerificationTokenRepositoryInterface
{
    /**
     * The row a presented plaintext token addresses, with its `user` relation loaded, or null.
     *
     * The lookup is BY DIGEST and never by scanning: `OpaqueToken::digest()` over the presented
     * plaintext is compared against the unique `email_verification_tokens_token_hash` index, so this
     * is one index probe and it is not shaped by anything else the caller supplied.
     *
     * IT DELIBERATELY FILTERS NOTHING ELSE. Expiry, consumption and the row-email-versus-user-email
     * check all live in App\Services\Auth\EmailVerificationService, in one readable sequence, because
     * one of those four outcomes is an idempotent SUCCESS (a consumed row belonging to a user who is
     * now verified — the mail-client link preview) and a repository that pre-filtered them could not
     * tell the caller which case it had. A `whereNull('consumed_at')` here would turn a double-clicked
     * link into a 404, which is the one thing that endpoint must not do.
     *
     * `user` is EAGER LOADED because `Model::shouldBeStrict()` turns a lazy access into an exception,
     * and every caller needs the user's current address to compare against the row's.
     */
    public function findByToken(string $token): ?EmailVerificationToken;

    /**
     * Replace whatever live token the user holds with a fresh one, atomically.
     *
     * DELETE-THEN-INSERT IN ONE TRANSACTION, and the shape is forced by the schema:
     * `email_verification_tokens_one_live_per_user` is a partial UNIQUE index on `(user_id) WHERE
     * consumed_at IS NULL`, so a second live row is a 23505 rather than a silent duplicate. It is the
     * same shape as Illuminate\Auth\Passwords\DatabaseTokenRepository::create(), and it is also the
     * reason an expired-but-unconsumed row does not wedge the resend path: the delete does not care
     * whether the row it removes had expired.
     *
     * CONSUMED ROWS ARE NOT TOUCHED. They fall outside the partial index, so they cannot block the
     * insert, and they are the record that a particular link was used. kb:prune-auth-tokens removes
     * them once they are past their expiry.
     *
     * @param  string  $email  the user's CURRENT address, lowercased — the table CHECKs
     *                         `email = lower(email)`, and the value is what a later consume compares
     *                         against `users.email` to refuse a token minted before an address change
     * @param  string  $digest  raw 32-byte sha256 from OpaqueToken::digest(); never the plaintext
     */
    public function replaceForUser(
        string $userId,
        string $email,
        string $digest,
        CarbonImmutable $expiresAt,
    ): EmailVerificationToken;

    /**
     * Mark the token consumed, mark the address verified, and write the audit row — all or nothing.
     *
     * @param  Closure(): void  $audit  invoked INSIDE the transaction, after both writes. It throws
     *                                  on an audit-write failure (EMAIL_VERIFIED is
     *                                  ON_FAILURE_ABORT), which rolls the verification back.
     * @return bool true when this call performed the verification; false when another transaction had
     *              already consumed the row — the caller still reports success, because the address is
     *              verified either way, and no second audit row is written
     */
    public function consume(EmailVerificationToken $token, User $user, Closure $audit): bool;

    /**
     * Delete every expired token that was never consumed.
     *
     * `expires_at < $now AND consumed_at IS NULL`, which is exactly the
     * `email_verification_tokens_pending_expires` partial index.
     *
     * CONSUMED ROWS ARE NOT SWEPT, AND THERE IS DELIBERATELY NO METHOD HERE THAT WOULD. Their
     * predicate would need a CUTOFF, and a cutoff is a retention decision — how long "was this
     * particular link the one that was used" has to stay answerable — not a detail of a prune command.
     * The consequence is real and is reported rather than papered over: consumed rows accumulate
     * without bound until that decision is made. They are small, they are outside the two partial
     * unique indexes so they block nothing, and inventing a 30-day default here would be a retention
     * policy set by whoever happened to write the sweep.
     *
     * @return int rows deleted
     */
    public function deleteExpired(CarbonImmutable $now): int;
}

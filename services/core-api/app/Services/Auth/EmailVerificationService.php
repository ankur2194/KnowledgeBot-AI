<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Repositories\Contracts\EmailVerificationTokenRepositoryInterface;
use App\Repositories\Eloquent\EloquentEmailVerificationTokenRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Give;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Mint, mail and consume the email-verification capability. There is one of these, and both ends of
 * the flow live here so the TTL, the invalidation rule and the address binding cannot drift apart.
 *
 * ── WHY THE MINT IS NOT INLINE AT ITS THREE CALL SITES ──────────────────────────────────────────
 *
 * `App\Models\User::sendEmailVerificationNotification()` delegates here (so the auto-registered
 * `Registered` -> SendEmailVerificationNotification listener reaches it), the resend endpoint calls
 * it, and registration reaches it through the event. Three inlined copies is how two of them drift on
 * the expiry or forget to invalidate the previous live token — and forgetting the invalidation is not
 * a cosmetic bug: `email_verification_tokens_one_live_per_user` is a partial UNIQUE index, so the
 * second mint would be a 23505 and a 500 rather than a second link.
 *
 * ── THE ADDRESS IS STORED ON THE ROW, AND THAT IS THE POINT ─────────────────────────────────────
 *
 * The row records the address the token was ISSUED FOR, alongside the digest, because a token minted
 * before an email change must not verify the new address — comparing the row's `email` against
 * `users.email` at consume time IS that check, and it is why this is not simply a boolean flag on
 * the user. (Reasoning preserved verbatim from the placeholder this file replaces; it was the
 * placeholder's whole contract.)
 *
 * ── WHAT THIS CLASS DOES NOT DO ─────────────────────────────────────────────────────────────────
 *
 * It opens no transaction. `Illuminate\Support\Facades\DB` is arch-banned outside
 * `App\Repositories\Eloquent` (tests/Arch/DoctrineTest.php), and the transaction that
 * `auth.email.verified` needs — the audit row and the `email_verified_at` write together, because
 * that operation is `ON_FAILURE_ABORT` — lives in
 * EloquentEmailVerificationTokenRepository::consume(), which takes the audit write as a closure. See
 * the repository interface for why that shape rather than a `transaction()` passthrough.
 *
 * NO CONTAINER BINDING IS NEEDED, for the same reason AuditLogger needs none: `#[Give]` is a
 * contextual container attribute, so `app(EmailVerificationService::class)` resolves with nothing
 * registered in any service provider. That matters here specifically — App\Providers\
 * AppServiceProvider is another agent's file this batch, and `User::sendEmailVerificationNotification()`
 * resolves this class out of the container. Moving the binding into
 * AppServiceProvider::bindRepositories() later and deleting the attribute is a valid,
 * behaviour-preserving change.
 */
final class EmailVerificationService
{
    /**
     * Fallback TTL when `kb.email_verification_ttl_hours` is missing or nonsense.
     *
     * config/kb.php already casts the env value to `int`, so the only ways to get here are an
     * unconfigured test harness or someone setting it to 0 — and a zero-hour TTL mints links that are
     * expired before the mail is delivered, which reads to the recipient as "the link never worked".
     */
    private const FALLBACK_TTL_HOURS = 24;

    public function __construct(
        // No service-provider binding needed — see the class docblock.
        #[Give(EloquentEmailVerificationTokenRepository::class)]
        private readonly EmailVerificationTokenRepositoryInterface $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Mint a single-use opaque token for the user's CURRENT address, invalidate any live token they
     * already hold, and queue App\Notifications\VerifyEmailAddress.
     *
     * The address is stored on the token row alongside the digest, because a token minted before an
     * email change must not verify the new address — comparing the row's `email` against `users.email`
     * at consume time is that check, and it is why this is not simply a boolean flag.
     *
     * UNCONDITIONAL: it does not ask whether the user is already verified. Both call sites ask that
     * question themselves — the framework's `SendEmailVerificationNotification` listener checks
     * `! $user->hasVerifiedEmail()` before it ever reaches here, and
     * EmailVerificationNotificationController checks it before calling — so a guard here would be a
     * third copy of one decision, and it would silently swallow the case where a deliberate re-mint
     * is what the caller wanted.
     *
     * ORDER IS LOAD-BEARING: the row is committed BEFORE the notification is queued. The notification
     * is `ShouldQueue` and both queue connections set `after_commit => true`, so a worker can never
     * pick up a job for a token row that was rolled back — but relying on that alone would leave the
     * plaintext held across a transaction boundary for no reason.
     */
    public function send(User $user): void
    {
        // The plaintext exists in this local, in the queued job body, and in the mail. It never
        // reaches a column, a log line, an audit `details` payload or a response.
        $plaintext = OpaqueToken::mint();

        $this->tokens->replaceForUser(
            $user->id,
            // The address is lowercased because `email_verification_tokens_email_lowercase` is a
            // CHECK constraint: the database refuses a mixed-case row, and it refuses it because such
            // a row would be unreachable by the normalised comparison that has to match it later.
            mb_strtolower($user->email),
            OpaqueToken::digest($plaintext),
            CarbonImmutable::now()->addHours($this->ttlHours()),
        );

        $user->notify(new VerifyEmailAddress($plaintext));
    }

    /**
     * Consume a presented token, or report that there is nothing to consume.
     *
     * FOUR INTERNAL OUTCOMES COLLAPSED INTO TWO ANSWERS, AND THE ORDER BETWEEN THEM IS THE DESIGN:
     *
     *   false — DENY. The digest addresses no row, the row's address is no longer the user's, the row
     *           was consumed by a user who is NOT verified, or it has expired. The caller renders ONE
     *           404 `authorization` body for all four; distinguishing them would say whether a guessed
     *           token was ever real.
     *   true  — the address is verified. Either this call did it (one audit row), or it was ALREADY
     *           verified at the address this token was issued for (no write, NO audit row), or a
     *           concurrent request consumed the row first (no second audit row). One answer, because
     *           the caller's contract is idempotent success and there is nothing for it to branch on.
     *
     * A BOOLEAN AND NOT AN OUTCOME ENUM, deliberately: `app/Enums/` is out of this change set's file
     * scope, and an enum whose four cases map onto two responses would invite a caller to render a
     * different body for a double-click than for a first click — which is the enumeration signal the
     * shared 404 exists to remove.
     *
     * WHY `already verified` IS CHECKED BEFORE `consumed` AND BEFORE `expired`, which looks backwards. A mail
     * client that pre-fetches link previews, or a recipient who double-clicks, sends the SAME token
     * twice: the first request consumes the row, so the second necessarily finds it consumed. Ordering
     * the consumed-check first would answer that with a 404 error page, which is precisely the failure
     * the endpoint's docblock forbids. Checking "is this user already verified at the address this
     * token was issued for" first collapses both the double-click and the genuinely-already-verified
     * case into one silent success. It discloses nothing: reaching the branch requires already holding
     * a token that belongs to that user at that address.
     *
     * WHY THE ADDRESS COMPARISON COMES FIRST OF ALL. A token minted for the old address must not
     * report success just because the user has since verified a NEW one — it proves nothing about the
     * address it is being presented for.
     */
    public function verify(#[SensitiveParameter] string $token, ?Request $request = null): bool
    {
        $row = $this->tokens->findByToken($token);

        if (! $row instanceof EmailVerificationToken) {
            return false;
        }

        $user = $row->user;

        if (! $user instanceof User) {
            // Unreachable through the schema — `user_id` is NOT NULL with ON DELETE RESTRICT — and
            // handled anyway, because the alternative is a TypeError rendered as a 500 on a guest
            // route if that ever stops being true.
            return false;
        }

        // hash_equals over the digest already in hand. The lookup above matched on a unique index so
        // this cannot fail in practice; it is here because OpaqueToken is the one implementation of
        // mint/digest/compare and no call site of it should reach for `===` on a secret.
        if (! OpaqueToken::matches($token, $row->token_hash)) {
            return false;
        }

        // THE ADDRESS BINDING. Compared against the lowercased current address because
        // `users_email_unique` is on `lower(email)` while the column itself is not normalised; the
        // token row IS normalised, by a CHECK constraint.
        if (! hash_equals($row->email, mb_strtolower($user->email))) {
            return false;
        }

        if ($user->hasVerifiedEmail()) {
            return true;
        }

        if ($row->isConsumed()) {
            // Consumed, yet the user is not verified — the address changed after this token was used
            // and cleared the verification. A spent token must not verify the new address.
            return false;
        }

        if ($row->isExpired(CarbonImmutable::now())) {
            return false;
        }

        // consume() returns false when a concurrent request had already consumed the row — which is
        // DELIBERATELY NOT PROPAGATED. The address is verified either way, exactly one audit row was
        // written, and the two cases must not produce different responses: a caller that could tell
        // them apart is a caller that can time a token's first use.
        $this->tokens->consume(
            $row,
            $user,
            // Runs INSIDE the repository's transaction, so a failed audit write rolls the
            // verification back. `auth.email.verified` is ON_FAILURE_ABORT for that reason.
            function () use ($user, $row, $token, $request): void {
                $this->audit->record(
                    AuditLogger::EMAIL_VERIFIED,
                    // NULL, and not the user's "current" organization. Verification is a fact about a
                    // user; this route is guest-reachable and user-scoped, so there is no
                    // `{organization}` segment and no session to read one from. Guessing a membership
                    // here would attribute the event to whichever organization happened to sort first.
                    organizationId: null,
                    actorId: $user->id,
                    details: [
                        // ECHOED by the allow-list: it is the identifier a compliance reader searches
                        // by. The row's address, which is the address actually being verified.
                        'email' => $row->email,
                        // FINGERPRINTED by the allow-list — AuditLogger hashes it and stores
                        // `token_fingerprint`. The plaintext never reaches the table, and passing it
                        // here cannot make it: the map decides, not the call site.
                        'token' => $token,
                    ],
                    subjectType: User::class,
                    subjectId: $user->id,
                    request: $request,
                );
            },
        );

        return true;
    }

    private function ttlHours(): int
    {
        $configured = config('kb.email_verification_ttl_hours');

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_TTL_HOURS;
    }
}

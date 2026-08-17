<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Repositories\Contracts\EmailVerificationTokenRepositoryInterface;
use App\Support\Kb\OpaqueToken;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * See the interface for the tenancy exemption, the DELETE-then-INSERT requirement, and why consume()
 * takes a closure. Two mechanical notes belong here, next to the code they bite:
 *
 * ── ELOQUENT DOES NOT APPLY CASTS TO WHERE BINDINGS ─────────────────────────────────────────────
 *
 * `token_hash` is a `bytea` and the model casts it through App\Support\Crypto\BinaryCast, which
 * encodes on WRITE. A `where('token_hash', $rawDigest)` does NOT go through that cast:
 * Illuminate\Database\Query\Builder::castBinding() special-cases BackedEnum and nothing else, and
 * Grammar::prepareBindings() special-cases DateTimeInterface and nothing else. A raw 32-byte sha256
 * therefore reaches the server as a text parameter, is almost never valid UTF-8, and the query fails
 * with `22021 invalid byte sequence for encoding "UTF8"` — or, on the bytes that happen to be valid
 * UTF-8, silently matches nothing. That second outcome is the dangerous one, because it renders as
 * "this verification link is no longer valid" for a link that is perfectly good. :self::bytea()
 * exists for exactly that one line, and there is one call site.
 *
 * ── THE CLOCK IS READ ONCE PER OPERATION ────────────────────────────────────────────────────────
 *
 * consume() writes `consumed_at` and `email_verified_at` from ONE CarbonImmutable, and the sweeps
 * take `$now` as an argument. Two reads of the clock inside one transaction can disagree, and a
 * verification whose two timestamps differ by a millisecond is a fact an investigation has to explain.
 */
final class EloquentEmailVerificationTokenRepository implements EmailVerificationTokenRepositoryInterface
{
    public function findByToken(#[SensitiveParameter] string $token): ?EmailVerificationToken
    {
        // tenancy-exempt: email_verification_tokens has no organization_id — verification is a fact
        // about a user (see the interface). The narrowing predicate is the token digest itself,
        // behind a UNIQUE index, so this query cannot be widened by anything a caller supplies.
        return EmailVerificationToken::query()
            ->where('token_hash', self::bytea(OpaqueToken::digest($token)))
            // shouldBeStrict() turns a lazy access into an exception, and every caller compares the
            // row's `email` against the user's current address.
            ->with('user')
            ->first();
    }

    public function replaceForUser(
        string $userId,
        string $email,
        #[SensitiveParameter] string $digest,
        CarbonImmutable $expiresAt,
    ): EmailVerificationToken {
        return DB::transaction(function () use ($userId, $email, $digest, $expiresAt): EmailVerificationToken {
            // THE USER ROW IS LOCKED FIRST, and it is what makes two concurrent resends safe rather
            // than merely rare. Without it: both transactions DELETE (each sees no live row, because
            // the other's insert is not yet visible), both INSERT, the second blocks on
            // `email_verification_tokens_one_live_per_user` and then fails with 23505 the moment the
            // first commits — a 500 on a double-clicked "resend" button. Locking the user
            // serialises the whole replace on the only thing both callers agree about.
            //
            // tenancy-exempt: `users` is not tenant-owned (a user may belong to several
            // organizations); `user_id` here comes from the authenticated session or from the token
            // row, never from a request body.
            User::query()->whereKey($userId)->lockForUpdate()->first();

            // LIVE rows only. Consumed rows fall outside the partial unique index so they cannot
            // block the insert, and they are the record that a particular link was used.
            EmailVerificationToken::query()
                ->where('user_id', $userId)
                ->whereNull('consumed_at')
                ->delete();

            // Attributes assigned explicitly, never create()/fill(): $fillable on this model is
            // empty on purpose because every column is an authority column.
            $token = new EmailVerificationToken;

            $token->user_id = $userId;
            $token->email = $email;
            // Raw bytes in; BinaryCast encodes them for the `bytea` column on write.
            $token->token_hash = $digest;
            $token->expires_at = $expiresAt;

            $token->save();

            return $token;
        });
    }

    public function consume(EmailVerificationToken $token, User $user, Closure $audit): bool
    {
        return DB::transaction(function () use ($token, $user, $audit): bool {
            // RE-READ UNDER A ROW LOCK. The service's checks ran outside this transaction, so two
            // clicks arriving together would both pass them. The loser blocks here, re-reads the
            // committed row, sees `consumed_at`, and returns false — one verification, one audit row.
            //
            // tenancy-exempt: as above. The predicate is this row's own primary key.
            $locked = EmailVerificationToken::query()
                ->whereKey($token->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof EmailVerificationToken || $locked->isConsumed()) {
                return false;
            }

            $now = CarbonImmutable::now();

            $locked->consumed_at = $now;
            $locked->save();

            // The framework's own method from Illuminate\Auth\MustVerifyEmail, which App\Models\User
            // already inherits. Used rather than assigning the column here so there is one spelling
            // of "this address is verified" in the application, and so hasVerifiedEmail() and this
            // write can never disagree about which column carries it.
            $user->markEmailAsVerified();

            // INSIDE the transaction, and last. `auth.email.verified` is ON_FAILURE_ABORT, so an
            // audit-write failure throws from here and rolls both writes above back rather than
            // leaving a verified address with no compliance record. This is the whole reason the
            // audit arrives as a closure — see the interface.
            $audit();

            return true;
        });
    }

    public function deleteExpired(CarbonImmutable $now): int
    {
        // tenancy-exempt: a maintenance sweep over a table with no tenant key. It is bounded by
        // `expires_at` and `consumed_at` only, which is exactly the
        // `email_verification_tokens_pending_expires` partial index.
        // Cast, because Illuminate\Database\Eloquent\Builder::delete() is an untyped passthrough and
        // reads as `mixed` at PHPStan level 8.
        return (int) EmailVerificationToken::query()
            ->where('expires_at', '<', $now)
            ->whereNull('consumed_at')
            ->delete();
    }

    /**
     * PostgreSQL's own `bytea` hex input format — pure ASCII, losslessly cast by the server.
     *
     * The write path gets this from BinaryCast; a WHERE binding does not. See the class docblock.
     */
    private static function bytea(#[SensitiveParameter] string $raw): string
    {
        return '\x'.bin2hex($raw);
    }
}

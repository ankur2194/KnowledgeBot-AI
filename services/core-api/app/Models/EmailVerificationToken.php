<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Crypto\BinaryCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use email-verification capability, addressed by a 256-bit random.
 *
 * ============================================================================================
 * NO #[ScopedBy(OrganizationScope::class)] — DO NOT ADD IT, AND HERE THERE IS NOT EVEN A COLUMN
 * TO SCOPE BY.
 * ============================================================================================
 *
 * Verification is a fact about a USER, and a user is not owned by an organization (see the `users`
 * migration): the row therefore has no `organization_id` and no FK chain to one. Even if it did, the
 * scope would be wrong for the same reason it is wrong on OrganizationInvitation:
 * `OrganizationScope::apply()` FAILS CLOSED with `whereRaw('1 = 0')` when no `TenantContext` is
 * bound, and this table is read on a GUEST path — the verification link may legitimately be opened
 * in a different browser from the one that registered, so there is no session, no organization, and
 * nothing to bind. Every lookup would return nothing, always, and the endpoint would render that as
 * "this link is no longer valid": a totally broken flow behind code that reviews as correct.
 *
 * REJECTED ALTERNATIVE: scope it and call `withoutGlobalScope(OrganizationScope::class)` on the
 * guest read. Worse than the absence, because a bypass a reviewer can see is better than one a
 * reader has to reconstruct — the read would then depend on a call whose whole purpose is to undo
 * the annotation above it.
 *
 * THAT ARGUMENT USED TO BE MADE WITH A FALSE FACT, AND BOTH HALVES WERE FALSE. It read: "the CI
 * grep for scope bypasses matches `withoutGlobalScopes(` — PLURAL — and the singular call passes
 * the gate unseen." There is no CI (`.github/` was deleted 2026-08-17), and the check that replaced
 * the published grep — tests/Arch/StringLevelDoctrineTest.php, a token scan rather than a grep —
 * bans BOTH spellings in `app/` and says so at its own call site. So the singular does not pass
 * unseen; it fails the suite. The rejection stands on the reviewability argument alone.
 *
 * WHAT MAKES THE ABSENCE SAFE: the only read is by `token_hash`, 32 bytes of sha256 over 256 bits of
 * entropy behind a UNIQUE index, and the row's `email` is re-compared against the user's current
 * address at consume time so a token minted before an address change cannot verify the new one.
 *
 * @property string $id
 * @property string $user_id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $created_at
 */
final class EmailVerificationToken extends Model
{
    use HasUlids;

    protected $table = 'email_verification_tokens';

    /**
     * There is no `updated_at` COLUMN on this table: a token is written once and consumed once, so a
     * second timestamp would only ever restate `created_at` or `consumed_at`. Eloquent's timestamps
     * are therefore off wholesale rather than half-disabled through `const UPDATED_AT = null`,
     * matching SparseTermFrequency. `created_at` is filled by the column's `DEFAULT now()`, so it is
     * absent from the model until the row is re-read — which is fine, because nothing reads it.
     */
    public $timestamps = false;

    /**
     * EMPTY ON PURPOSE — every column is an authority column. `user_id` is whose identity is being
     * proven, `email` is the address the proof is bound to, `token_hash` IS the capability, and
     * `expires_at`/`consumed_at` are the two facts that decide whether it still counts. All five are
     * written by the repository that mints and consumes the row, by explicit assignment, so a
     * request body has no route into any of them at all.
     *
     * NOTE FOR THE NEXT PERSON: there is no factory for this model and therefore no `HasFactory`.
     * That is not an omission. A fixture row is only usable by a test that knows the PLAINTEXT
     * token, which the row deliberately does not contain — so a factory would either invent a hash
     * no test can consume or take the plaintext as a required argument, at which point it is the
     * mint service with extra steps. Build fixtures through that service. Adding `HasFactory` here
     * without a matching `Database\Factories\EmailVerificationTokenFactory` also fails PHPStan
     * level 8, because the trait is generic.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Takes `$now` rather than reading the clock, so one request cannot disagree with itself between
     * two checks.
     */
    public function isExpired(CarbonImmutable $now): bool
    {
        return $this->expires_at <= $now;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Reused, not reimplemented: BinaryCast already handles PDO_PGSQL's two documented
            // bytea read shapes. See OrganizationInvitation.
            'token_hash' => BinaryCast::class,
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            // Cast even though Eloquent's timestamps are off: without it, `created_at` reads back as
            // whatever string the driver hands over, and the one place that would bite is a prune
            // command comparing it to a Carbon instance.
            'created_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\OrgRole;
use App\Support\Crypto\BinaryCast;
use App\Support\Tenancy\OrgOwned;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending organization membership, addressed by an email and authorized by a 256-bit random.
 *
 * ============================================================================================
 * NO #[ScopedBy(OrganizationScope::class)] — AND THIS IS THE MOST IMPORTANT NEGATIVE DECISION
 * ON THIS MODEL. DO NOT ADD IT.
 * ============================================================================================
 *
 * `OrganizationScope::apply()` FAILS CLOSED: with no `TenantContext` bound it appends
 * `whereRaw('1 = 0')` rather than applying nothing, which is correct for every tenant-owned table
 * and catastrophic for this one. The guest paths — invitation preview, register, accept — read this
 * table BEFORE any organization is known, because the token is what identifies the organization.
 * With the scope attached, every invitation lookup returns nothing, ALWAYS, and the controller
 * renders that as a perfectly plausible "this invitation is no longer valid". Registration would be
 * totally broken by code that reviews as correct, with no exception and no log line.
 *
 * REJECTED ALTERNATIVE: keep `#[ScopedBy]` and call `withoutGlobalScope(OrganizationScope::class)`
 * on the guest read. That is WORSE than not having the scope, because the CI grep that exists to
 * catch scope bypasses matches `withoutGlobalScopes\(` — PLURAL — so the singular form passes the
 * gate silently. A bypass a reviewer can see is better than one the gate cannot.
 *
 * WHAT MAKES THE ABSENCE SAFE, from the other direction, exactly as on OrganizationUser:
 *   - the guest read is by `token_hash` — 32 bytes of sha256 over 256 bits of entropy, behind a
 *     UNIQUE index, so there is no query shape that returns "some organization's invitations" to
 *     someone who does not already hold the token;
 *   - every ADMIN read goes through a repository method taking `organization_id` as a required
 *     positional argument, and the route is nested under `{organization}` with `->scopeBindings()`
 *     so a foreign id 404s at binding time, before any policy runs.
 *
 * `token_hash` never holds the plaintext token and there is no accessor that could produce one; the
 * plaintext exists only in the mail that carries it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property OrgRole $role
 * @property string $token_hash
 * @property string $invited_by_id
 * @property string|null $accepted_by_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class OrganizationInvitation extends Model implements OrgOwned
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'organization_invitations';

    /**
     * `email`, `role` and `expires_at` are the whole of what a caller supplies. EVERY other column
     * is an ownership or an authority column and is therefore absent:
     *
     *   organization_id  the tenant key — over-posting it is an authorization bug with a 200
     *   token_hash       the capability itself, written only by the service that mints the token
     *   invited_by_id    who is accountable; taken from the authenticated user, never the body
     *   accepted_by_id   \
     *   accepted_at       > the terminal transitions, written only by the accept/revoke services
     *   revoked_at       /
     *
     * `Model::shouldBeStrict()` turns a mass-assignment attempt on any of them into an exception
     * rather than a silent drop.
     *
     * @var list<string>
     */
    protected $fillable = ['email', 'role', 'expires_at'];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The accountable actor. A relation rather than a denormalized name because
     * `Model::shouldBeStrict()` forbids lazy loading, so the admin list has to `with('invitedBy')`
     * explicitly — which is the point: an N+1 here is a compile-time-visible decision.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    /**
     * Takes `$now` as an argument rather than reading the clock, so the caller that has already
     * decided what "now" means for one request cannot disagree with itself between two checks.
     */
    public function isExpired(CarbonImmutable $now): bool
    {
        return $this->expires_at <= $now;
    }

    /**
     * Derived, never stored. See InvitationStatus for why, and note the ORDER: revoked beats
     * accepted beats expired. An invitation cannot be both revoked and accepted (a CHECK constraint
     * says so), but it can certainly be accepted after its `expires_at` has passed, and an accepted
     * invitation must not later read as `expired`.
     */
    public function status(): InvitationStatus
    {
        if ($this->revoked_at !== null) {
            return InvitationStatus::Revoked;
        }

        if ($this->accepted_at !== null) {
            return InvitationStatus::Accepted;
        }

        return $this->isExpired(CarbonImmutable::now())
            ? InvitationStatus::Expired
            : InvitationStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            // `bytea` has no PDO binding, so the digest travels as PostgreSQL's own `\x` hex input
            // format. Reused rather than reimplemented: BinaryCast already handles PDO_PGSQL's two
            // documented bytea READ shapes (stream resource and `\x…` text), and a second cast that
            // handled only one would fail as a decryption-shaped bug on some builds and not others.
            'token_hash' => BinaryCast::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}

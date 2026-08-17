<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Notifications\ResetPassword;
use App\Services\Auth\EmailVerificationService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

/**
 * The actor.
 *
 * A user is NOT owned by an organization — membership lives in `organization_users` and one user
 * may belong to several. Every authorization question therefore starts from the RECORD's
 * organization and asks this user for a membership in it.
 *
 * `Laravel\Sanctum\HasApiTokens` IS DELIBERATELY ABSENT — decision D11.
 *
 * The admin surface has EXACTLY ONE authentication mechanism, the Sanctum SPA cookie session, so no
 * token-minting capability exists anywhere in this application. Verified safe, not assumed:
 * `Laravel\Sanctum\Guard::__invoke()` checks `supportsTokens($user)` and returns the session user
 * UNCHANGED when the trait is absent, so cookie authentication works exactly as it does with the
 * trait. What the trait would add is `createToken()`, `tokenCan()`, `currentAccessToken()` and a
 * `TransientToken` attached to every session request — capabilities with no caller here, and
 * `tokenCan()` returning true for every string is a documented way for an `abilities:` middleware to
 * look like a gate while gating nothing.
 *
 * A FUTURE MOBILE PERSONAL-ACCESS-TOKEN SURFACE ADDS IT DELIBERATELY, ON ITS OWN SURFACE, AND ADDS
 * FOUR THINGS IN THE SAME CHANGE: the trait, the `personal_access_tokens` migration (absent today —
 * which is why a bearer token on an `auth:sanctum` route currently reaches a table that does not
 * exist), the `sanctum:prune-expired` schedule entry, and
 * `Sanctum::authenticateAccessTokensUsing()` so a revoked membership stops an already-minted token
 * on its very next request. Adding the trait alone buys nothing and creates the appearance of a
 * second auth path on the admin surface.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $is_platform_owner
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;

    /*
     * MANDATORY, and it fixes a live fatal rather than adding a feature. Illuminate\Foundation\Auth\
     * User already `use`s CanResetPassword and MustVerifyEmail, so this class already HAS
     * sendPasswordResetNotification() and sendEmailVerificationNotification() — and both bodies call
     * `$this->notify()`, which comes from Notifiable and did not exist here. Either one was an
     * "undefined method" fatal the moment anything triggered it.
     */
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * The membership row for ONE organization, read fresh.
     *
     * DELIBERATELY NOT MEMOIZED ON THE INSTANCE. Queue workers and Octane keep a User instance
     * alive across requests and across tenants, so a cached membership is how a user removed from
     * an organization keeps full access until the process recycles (laravel-rbac-policies,
     * Gotchas). If this ever needs caching it is keyed by (user_id, org_id) in a request-lifetime
     * store and invalidated on membership change — never a property on this object.
     *
     * Uses the relation's own query rather than a repository because a membership row is not
     * tenant-owned data in the scoped sense: it IS the tenancy fact, and the organization it is
     * being asked about arrives as the argument.
     */
    public function membershipFor(string $organizationId): ?OrganizationUser
    {
        return $this->memberships()->newQuery()
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->first();
    }

    public function isActiveMemberOf(string $organizationId): bool
    {
        $membership = $this->membershipFor($organizationId);

        return $membership !== null && $membership->status === MembershipStatus::Active;
    }

    public function isPlatformOwner(): bool
    {
        return $this->is_platform_owner;
    }

    /**
     * OVERRIDDEN, and the framework's version cannot be used at all.
     *
     * Illuminate\Auth\Notifications\ResetPassword builds `route('password.reset', …)`. This
     * application registers no such route — there are no Blade pages here, and every emailed link
     * points at the SPA — so the framework's notification throws RouteNotFoundException from INSIDE
     * PasswordBroker::sendResetLink()'s 200 ms timebox. `ResetPassword::createUrlUsing()` would fix
     * the URL and leave the worse half untouched: the framework's notification is not `ShouldQueue`,
     * so it opens an SMTP connection inside that same timebox, blowing its floor on the
     * account-EXISTS branch only and re-opening the enumeration timing oracle the timebox exists to
     * close. Ours is queued on `notify`.
     *
     * The parameter is deliberately UNTYPED. Illuminate\Contracts\Auth\CanResetPassword declares
     * `sendPasswordResetNotification($token)` with no type, and narrowing an inherited parameter is
     * an LSP violation that PHP 8 raises as a FATAL error, not a warning. The type therefore lives
     * in the phpdoc annotation below, which is what static analysis reads.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        // getEmailForPasswordReset() and not $this->email: it is the accessor the broker itself uses
        // to key the token row, so the address in the link is by construction the address the row
        // will be looked up by.
        $this->notify(new ResetPassword((string) $token, (string) $this->getEmailForPasswordReset()));
    }

    /**
     * OVERRIDDEN to delegate, so the token mint lives in exactly one place.
     *
     * The framework's own version notifies with a SIGNED URL to `route('verification.verify')`, which
     * cannot be validated after the SPA echoes it back — `URL::hasValidSignature()` checks the
     * signature against THIS API's URL while the recipient clicked the SPA's. We mint an opaque
     * single-use row instead, and three call sites need it: the auto-registered `Registered` listener
     * (which reaches this method), the resend endpoint, and registration itself.
     *
     * THE CONTAINER READ INSIDE A MODEL IS DELIBERATE AND GREPPABLE. The alternative is duplicating
     * the mint-store-notify sequence at all three call sites, which is how two of them drift on the
     * expiry or forget to invalidate the previous live token. One line pointing at one service is
     * the smaller cost.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationService::class)->send($this);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_platform_owner' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }
}

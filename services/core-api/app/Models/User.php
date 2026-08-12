<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The actor.
 *
 * A user is NOT owned by an organization — membership lives in `organization_users` and one user
 * may belong to several. Every authorization question therefore starts from the RECORD's
 * organization and asks this user for a membership in it.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $is_platform_owner
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;

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

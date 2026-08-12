<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;

/**
 * The membership row — the only database fact about authorization.
 *
 * NO #[ScopedBy(OrganizationScope::class)]. This model is read BEFORE a tenant context exists, by
 * the middleware whose job is to establish one; scoping it by the context it produces would be
 * circular and would 403 every request. Its safety comes from the other direction: every read of
 * it takes both `organization_id` and `user_id` as explicit arguments, so there is no query shape
 * that returns "this user's memberships everywhere" to an authorization decision.
 *
 * @property string $organization_id
 * @property string $user_id
 * @property OrgRole $role
 * @property MembershipStatus $status
 */
final class OrganizationUser extends Model
{
    protected $table = 'organization_users';

    /**
     * Composite primary key. Eloquent does not support one natively, which is why incrementing is
     * off and the key is declared as a non-incrementing string: nothing in this change set loads a
     * membership by a single-column key, and a route-model binding on this table would be a bug.
     */
    public $incrementing = false;

    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['role', 'status'];

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function grants(Permission $permission): bool
    {
        return $this->isActive() && $this->role->grants($permission);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            'status' => MembershipStatus::class,
        ];
    }
}

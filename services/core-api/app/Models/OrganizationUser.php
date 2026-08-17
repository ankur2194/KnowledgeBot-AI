<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Enums\Permission;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The membership row — the only database fact about authorization.
 *
 * NO #[ScopedBy(OrganizationScope::class)]. This model is read BEFORE a tenant context exists, by
 * the middleware whose job is to establish one; scoping it by the context it produces would be
 * circular and would 403 every request. Its safety comes from the other direction: every read of
 * it takes both `organization_id` and `user_id` as explicit arguments, so there is no query shape
 * that returns "this user's memberships everywhere" to an authorization decision.
 *
 * IT NOW `implements OrgOwned`, AND THAT DID NOT CHANGE THE PARAGRAPH ABOVE. The interface only
 * means "this record can name its own organization", which is what lets a policy authorize a
 * membership row through `OrgScopedPolicy::permit()` — the org still comes from the RECORD, so
 * "admin of some organization" stays unrepresentable. `#[ScopedBy]` was deliberately NOT added at
 * the same time, and the two are unrelated: `OrgOwned` is how a row is authorized once you hold it;
 * the scope is how a QUERY is narrowed before you do. Adding the attribute here reintroduces exactly
 * the circularity above, and `OrganizationScope` fails closed, so the symptom would be a 403 on
 * every authenticated request with nothing in the log to explain it.
 *
 * @property string $organization_id
 * @property string $user_id
 * @property OrgRole $role
 * @property MembershipStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class OrganizationUser extends Model implements OrgOwned
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

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * Needed so a membership list can be eager-loaded. `Model::shouldBeStrict()` is on, so
     * `preventLazyLoading()` turns an unloaded access into an exception rather than an N+1 nobody
     * notices — which means the relation has to EXIST before `with('organization')` can be written,
     * and `/me` cannot render an organization's name without it.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The member. Needed for the same reason `organization()` is: `Model::shouldBeStrict()` forbids
     * lazy loading, so a member LIST has to `with('user')` explicitly, and the relation must exist
     * before that can be written. `App\Http\Resources\MemberResource` renders the name and address
     * off it; nothing here denormalizes either onto this table, because two spellings of one address
     * drift and only one of them is the login identifier.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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

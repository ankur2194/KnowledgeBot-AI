<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The actor. Two of these per isolation fixture, and they are NOT interchangeable.
 *
 * THE TRAP THIS FACTORY EXISTS TO AVOID: a user is not owned by an organization. Membership lives
 * in `organization_users`, so `User::factory()->recycle($org)` alone makes nobody a member of
 * anything — the membership row does, and orgRole() is the only place it should be created. A test
 * whose actor has no membership row gets a 403 from every policy and reads as "authorization
 * works", which is the false green that hides a missing scope.
 *
 * "Admin of SOME organization" is the cross-tenant bug. orgRole() therefore takes the ROLE only
 * and derives the organization from the recycled one, so a test cannot accidentally mint an admin
 * of one org and point it at another's record without saying so out loud.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * The shared fixture password. A constant rather than a faker value because Playwright's setup
     * project drives the real login route with it; nothing in this suite ever asserts on it.
     */
    public const PASSWORD = 'fixture-password-not-a-secret';

    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->name(),
            // GLOBAL uniqueness, not per organization: the login form has no tenant yet.
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make(self::PASSWORD),
            'is_platform_owner' => false,
            'email_verified_at' => now(),
        ];
    }

    /**
     * Attach a membership row for the RECYCLED organization with the given role.
     *
     * Takes the role only. The organization comes from recycle(), so the call site cannot silently
     * create a membership in a third organization — the failure mode that makes an isolation test
     * pass with the filter deleted.
     *
     * $role is typed OrgRole|string rather than `mixed`: the catalog is FIXED (§6.2–6.5), so a
     * role that is not one of its cases is a typo, and `OrgRole::from()` refusing it at the
     * fixture is a better failure than a membership row nobody's policy matches.
     */
    public function orgRole(OrgRole|string $role, MembershipStatus $status = MembershipStatus::Active): static
    {
        $role = $role instanceof OrgRole ? $role : OrgRole::from($role);

        return $this->afterCreating(function (User $user) use ($role, $status): void {
            // The documented accessor, not $this->recycle directly: it is the one that returns
            // null rather than raising when nothing was recycled, which is the case this method
            // has to detect and refuse loudly.
            $organization = $this->getRandomRecycledModel(Organization::class);

            if (! $organization instanceof Organization) {
                throw new RuntimeException(
                    'UserFactory::orgRole() requires a recycled organization: '
                    .'User::factory()->recycle($org)->orgRole(OrgRole::Admin). Deriving the '
                    .'organization from anywhere else reintroduces "admin of some organization", '
                    .'which is the cross-tenant bug.',
                );
            }

            $membership = new OrganizationUser;
            $membership->organization_id = $organization->id;
            $membership->user_id = $user->id;
            $membership->role = $role;
            $membership->status = $status;
            $membership->save();
        });
    }
}

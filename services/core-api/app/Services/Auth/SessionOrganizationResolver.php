<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\OrganizationUser;
use App\Models\User;
use App\Repositories\Contracts\MembershipRepositoryInterface;

/**
 * Which organization the admin console should be pointed at, and the list it may switch between.
 *
 * WHAT `current_organization_id` IS, AND WHAT IT IS NOT
 * ====================================================
 * It is a UI PREFERENCE stored in the session, and IT AUTHORIZES NOTHING. Every tenant-owned admin
 * route carries `{organization}` as a URL segment and `App\Http\Middleware\TenantContext` re-reads
 * `organization_users` from PostgreSQL on every one of them; `App\Models\Scopes\OrganizationScope`
 * reads the context that middleware binds from the ROUTE PARAMETER, never from the session; and the
 * `admin` rate limiter keys on `$request->route('organization')`, so a stale session value cannot
 * even mis-key a bucket. Two independent confirmations that nothing downstream reads this value as
 * authority.
 *
 * That is what makes "the stored organization's membership was revoked mid-session" a NON-EVENT:
 * the org-scoped routes deny at `org.member` on the very next request with no cache flush and no
 * process restart, and `GET /me` REPAIRS the stored value the next time the SPA asks. Nothing has to
 * hunt down and invalidate sessions on a membership change.
 *
 * ONE METHOD, NOT TWO
 * ===================
 * The design named a `defaultFor(User)` beside a separate repair path. They collapsed into
 * `snapshot()` on purpose: two entry points would each have to know the selection rule, and the two
 * copies of "oldest active membership wins" are exactly the pair that drifts. Login passes
 * `$preferred = null` (nothing stored yet), `/me` passes whatever the session holds (so an invalid
 * value is repaired), and the org switcher passes the id it has just re-verified. All three are the
 * same question with a different starting preference.
 *
 * WHY OLDEST-ACTIVE AND NOT OWNER-FIRST. Role precedence was considered and rejected: it is a UX
 * guess, and it makes the rule un-reproducible from the schema alone. "Oldest active membership,
 * tie-broken by ULID" is a fact two people reading the table will agree on.
 */
final class SessionOrganizationResolver
{
    public function __construct(
        private readonly MembershipRepositoryInterface $memberships,
    ) {}

    /**
     * Read every membership, decide the current organization, and hand back both.
     *
     * @param  string|null  $preferred  the caller's preference — a stored session value, an
     *                                  explicitly requested organization, or null. It is HONOURED
     *                                  ONLY IF it is currently an active membership; anything else
     *                                  (revoked, suspended, another tenant's id, a ULID that never
     *                                  existed) is silently discarded and re-resolved. Discarding
     *                                  rather than refusing is deliberate: the value is a
     *                                  preference, and a preference that has gone stale is not an
     *                                  error the caller can act on.
     */
    public function snapshot(User $user, ?string $preferred): SessionSnapshot
    {
        $memberships = $this->memberships->forUser($user->id);

        return new SessionSnapshot(
            user: $user,
            currentOrganizationId: $this->select($memberships, $preferred),
            memberships: $memberships,
        );
    }

    /**
     * @param  list<OrganizationUser>  $memberships  oldest first — the order the repository fixed
     */
    private function select(array $memberships, ?string $preferred): ?string
    {
        $active = array_values(array_filter(
            $memberships,
            static fn (OrganizationUser $membership): bool => $membership->isActive(),
        ));

        foreach ($active as $membership) {
            if ($membership->organization_id === $preferred) {
                return $membership->organization_id;
            }
        }

        // The oldest ACTIVE membership, or null. NULL IS A LEGITIMATE OUTCOME AND MUST NOT BECOME A
        // REFUSAL: a user whose only membership was suspended, or who has not accepted an invitation
        // yet, is still a proven identity. Refusing to establish a session for them would make
        // POST /auth/email/verification-notification — which needs `auth:sanctum` — unreachable,
        // which is the classic dead end where the only way out of a broken state requires the state
        // to not be broken.
        return $active === [] ? null : $active[0]->organization_id;
    }
}

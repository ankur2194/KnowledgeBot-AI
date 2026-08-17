<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\OrganizationInvitation -> App\Policies\OrganizationInvitationPolicy.
 * No Gate::policy() call and no provider edit — the `Surface` this class needs is constructor-injected
 * by the container binding and overridden per request by the `surface:admin` middleware.
 *
 * Every ability here authorizes a row the caller ALREADY HOLDS, and the organization comes from that
 * row via OrgOwned. The complementary half — "may this user create an invitation at all", which has
 * no row yet — is on OrganizationPolicy, because there is no invitation to take an organization from.
 *
 * These abilities are layer 3 of seven. A passing policy does not license an unscoped query: the
 * admin routes still nest under `{organization}` with `->scopeBindings()`, so a foreign invitation id
 * 404s at binding time before this class is ever constructed, and the repository read still takes
 * `organization_id` as a required argument. This policy is what refuses the case where the row IS in
 * this organization and the caller's role is wrong.
 *
 * There is deliberately no `accept` ability. Acceptance is authorized by POSSESSION OF THE TOKEN, not
 * by a membership the accepter does not have yet — routing it through permit() would ask for a
 * membership in the organization the invitation exists to grant one in, and deny every time.
 */
final class OrganizationInvitationPolicy extends OrgScopedPolicy
{
    public function view(?User $user, OrganizationInvitation $invitation): Response
    {
        return $this->permit($user, $invitation, Permission::MembersView);
    }

    public function revoke(?User $user, OrganizationInvitation $invitation): Response
    {
        return $this->permit($user, $invitation, Permission::MembersManage);
    }

    public function resend(?User $user, OrganizationInvitation $invitation): Response
    {
        return $this->permit($user, $invitation, Permission::MembersManage);
    }
}

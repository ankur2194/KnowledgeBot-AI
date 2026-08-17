<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\Organization -> App\Policies\OrganizationPolicy.
 *
 * The two embedding abilities sit behind the PROVIDER permissions rather than a generic
 * "settings" permission, because the designation selects which credential pays for every embedding
 * call and which vector space every future corpus lands in. A Knowledge Manager may read it
 * (§6.4 needs to know why an upload is blocked) and may not change it (§6.4 excludes provider
 * credentials).
 */
final class OrganizationPolicy extends OrgScopedPolicy
{
    public function viewEmbeddingReadiness(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ProvidersView);
    }

    public function designateEmbeddingConnection(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ProvidersManage);
    }

    public function createProviderConnection(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ProvidersManage);
    }

    /**
     * The three membership abilities have no MODEL of their own — listing members and creating an
     * invitation both act on the organization itself — so they live here rather than on
     * OrganizationInvitationPolicy, which can only authorize a row that already exists. `Organization`
     * already implements OrgOwned, so no OrgContext shim is needed and auto-discovery resolves
     * `Gate::authorize('inviteMember', $organization)` to this class.
     */
    public function viewMembers(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::MembersView);
    }

    public function inviteMember(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::MembersManage);
    }

    /**
     * Checked IN ADDITION to inviteMember, never instead of it, when the requested role is `owner`.
     * Separate because permit() has no argument position for "the role being granted" — see
     * Permission::MembersManageOwner.
     */
    public function inviteOwner(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::MembersManageOwner);
    }
}

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
     * LIST the organization's connections.
     *
     * Here and not on ProviderConnectionPolicy because a list has no row to take an organization
     * from — the same reason `viewMembers` and `inviteMember` live here rather than on
     * OrganizationInvitationPolicy. `ProviderConnectionPolicy::view()` is the per-row half and
     * carries the identical permission, so the two never disagree about who may read a connection;
     * what differs is only which record supplied the organization.
     *
     * A `viewAny` on the connection policy would be the alternative spelling. It is not used,
     * because Laravel resolves `viewAny` from a CLASS NAME rather than an instance, which means
     * the organization would have to come from somewhere other than a record — and "somewhere
     * other than a record" is precisely the shape OrgScopedPolicy exists to make unrepresentable.
     */
    public function viewProviderConnections(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ProvidersView);
    }

    /**
     * LIST the organization's bots.
     *
     * Here and not on BotPolicy because a list has no row to take an organization from — the same
     * reason `viewProviderConnections` and `viewMembers` live here. `BotPolicy::view()` is the
     * per-row half and carries the identical permission, so the two never disagree about who may
     * read a bot; what differs is only which record supplied the organization.
     *
     * A `viewAny` on BotPolicy would be the alternative spelling and is not used, for the reason
     * `viewProviderConnections` records: Laravel resolves `viewAny` from a CLASS NAME rather than an
     * instance, so the organization would have to come from somewhere other than a record — which
     * is precisely the shape OrgScopedPolicy exists to make unrepresentable.
     */
    public function viewBots(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::BotsView);
    }

    /**
     * Create a bot.
     *
     * The record is the ORGANIZATION and not a bot, because there is no bot yet — the same split
     * `ProviderConnectionPolicy::createModel()` makes one level down, except that there the parent
     * is a connection and here the organization IS the parent. `Organization` already implements
     * OrgOwned, so `Gate::authorize('createBot', $organization)` resolves to this class with no
     * OrgContext shim.
     */
    public function createBot(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::BotsManage);
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

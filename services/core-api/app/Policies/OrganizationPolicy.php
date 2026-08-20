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
     * May this caller WRITE bots in this organization — asked of a LIST, and asked once.
     *
     * ── THIS ABILITY AUTHORIZES NOTHING. IT DECIDES A PROJECTION ──────────────────────────────
     *
     * `BotResource` renders `system_instruction` and `answer_style_instruction` only to a caller
     * holding `bots.manage` (the resource's docblock carries the reasoning). `show` can ask that
     * question of the ROW — `Gate::allows('update', $bot)` — because there is exactly one row. A
     * hundred-row page cannot: `OrgScopedPolicy::permit()` resolves membership per check and is
     * deliberately never memoized across organizations, so a per-row check is a hundred
     * `organization_users` reads for one answer that cannot differ between them. Every row on that
     * page belongs to the organization in the path, so ONE check against the organization is the
     * same answer, arrived at once.
     *
     * ── WHY NOT REUSE `createBot`, WHICH CARRIES THE IDENTICAL PERMISSION ─────────────────────
     *
     * It does carry the identical permission, and that is exactly the trap. An ability name is
     * read at the call site as the action being performed, so `Gate::allows('createBot', $org)`
     * inside a GET reads as a creation check somebody forgot to remove — and the next reader either
     * deletes it or "fixes" the endpoint. The two must never diverge, which is why both delegate to
     * `Permission::BotsManage` and neither adds a condition of its own: this is one permission with
     * two call-site spellings, not two permissions.
     *
     * NOT A REPLACEMENT FOR `createBot` ON `store`, AND NOT A REPLACEMENT FOR `BotPolicy::update()`
     * ON `update`. An authorization gate names the action it is guarding; this one names a question
     * about rendering, and a 403 must never be produced from it — `Gate::allows()`, never
     * `Gate::authorize()`.
     */
    public function manageBots(?User $user, Organization $organization): Response
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

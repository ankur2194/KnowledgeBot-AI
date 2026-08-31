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
 *
 * The two RERANK abilities beside them carry the identical permissions for the identical reason —
 * see their own docblock for why no new `Permission` case was minted.
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

    /**
     * The two rerank abilities carry the SAME permissions as the two embedding ones, and no new
     * `Permission` case was added for them.
     *
     * That is a decision rather than a shortcut, and it follows the rule `Permission::BotsManage`
     * states: a permission nobody grants differently is a permission that fails silently in both
     * directions. A `providers.designate_rerank` case would be granted to exactly the roles that
     * hold `providers.manage` today and refused to exactly the roles that do not — the designation
     * selects which credential pays for the rerank call and which provider account sees this
     * organization's end-user questions, which is the same kind of fact as the embedding
     * designation and the credential itself. §6.4 puts a Knowledge Manager on the read side of that
     * line and not the write side, which is what `providers.view` versus `providers.manage` already
     * expresses.
     *
     * The consequence worth naming: tests/Unit/RolePermissionMatrixTest.php is UNCHANGED by this
     * surface, because it pins the role x permission matrix and no permission moved. What proves
     * these two abilities are wired to the right side of that line is the endpoint test, which
     * asserts a Knowledge Manager reads the designation and is refused the write.
     */
    public function viewRerankConfiguration(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ProvidersView);
    }

    public function designateRerankConnection(?User $user, Organization $organization): Response
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
     * LIST the organization's knowledge sources.
     *
     * Here and not on KnowledgeSourcePolicy because a list has no row to take an organization from
     * — the same reason `viewBots`, `viewProviderConnections` and `viewMembers` live here.
     * `KnowledgeSourcePolicy::view()` is the per-row half and carries the identical permission, so
     * the two never disagree about who may read a source; what differs is only which record
     * supplied the organization.
     *
     * A `viewAny` on KnowledgeSourcePolicy would be the alternative spelling and is not used, for
     * the reason `viewProviderConnections` records: Laravel resolves `viewAny` from a CLASS NAME
     * rather than an instance, so the organization would have to come from somewhere other than a
     * record — which is precisely the shape OrgScopedPolicy exists to make unrepresentable.
     */
    public function viewSources(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::SourcesView);
    }

    /**
     * Create a knowledge source.
     *
     * The record is the ORGANIZATION and not a source, because there is no source yet — the same
     * split `createBot()` makes one entity over. `Organization` already implements OrgOwned, so
     * `Gate::authorize('createSource', $organization)` resolves to this class with no OrgContext
     * shim.
     */
    public function createSource(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::SourcesManage);
    }

    /**
     * Create a source BY UPLOADING — the one-step path where the file arrives with the request that
     * creates the source it belongs to.
     *
     * TWO ABILITIES ON ONE ENDPOINT, NOT ONE. A create-with-upload authorizes `createSource` AND
     * this, because it performs both acts: it adds a row to the corpus and it consumes storage
     * quota while handing bytes to an untrusted parser. Collapsing them would make whichever
     * permission was chosen govern both, and the pair is separate precisely because a future plan
     * gate attaches to the second and not the first (see `Permission::SourcesUpload`).
     *
     * `KnowledgeSourcePolicy::upload()` is the per-row half, for adding an item or a version to a
     * source that already exists, and it carries the identical permission.
     */
    public function uploadSource(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::SourcesUpload);
    }

    /**
     * May this caller WRITE sources in this organization — asked of a LIST, and asked once.
     *
     * THIS ABILITY AUTHORIZES NOTHING. IT DECIDES A PROJECTION, exactly as `manageBots()` does, and
     * for the identical reason: `OrgScopedPolicy::permit()` resolves membership per check and is
     * deliberately never memoized across organizations, so asking the question per row on a
     * hundred-row page is a hundred `organization_users` reads for one answer that cannot differ
     * between them. Every row on that page belongs to the organization in the path.
     *
     * A 403 MUST NEVER BE PRODUCED FROM IT — `Gate::allows()`, never `Gate::authorize()` — and it
     * is not a replacement for `createSource` on `store` or for `KnowledgeSourcePolicy::update()`
     * on `update`. It exists as its own name rather than reusing `createSource` because an ability
     * name is read at the call site as the action being performed, and `Gate::allows('createSource',
     * $org)` inside a GET reads as a creation check somebody forgot to remove.
     */
    public function manageSources(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::SourcesManage);
    }

    /**
     * Read §8.23's usage and quality dashboard.
     *
     * The record is the ORGANIZATION because the dashboard is an aggregate over it — there is no
     * per-row subject, exactly as there is none for `viewBots` or `viewSources`. `Organization`
     * already implements OrgOwned, so `Gate::authorize('viewAnalytics', $organization)` resolves
     * here with no OrgContext shim and permit() still resolves membership of THE RECORD'S
     * organization.
     *
     * `Permission::AnalyticsView` and not `BotsView`, even though the dashboard filters by bot: the
     * two are held by different role sets on purpose (a Knowledge Manager may read a bot and may not
     * read the organization's spend), and that difference is the whole reason the permission was
     * added rather than reused.
     */
    public function viewAnalytics(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::AnalyticsView);
    }

    /**
     * LIST the organization's stored conversations.
     *
     * Here and not on `ConversationPolicy` because a list has no row to take an organization from —
     * the same reason `viewBots`, `viewSources` and `viewMembers` live here.
     * `ConversationPolicy::view()` is the per-row half and carries the identical permission, so the
     * two never disagree about who may read a thread; what differs is only which record supplied
     * the organization.
     *
     * `Permission::ConversationsView` and not `AnalyticsView`, even though the two are held by the
     * same three roles today: one reads counts and percentiles and the other reads the questions
     * customers asked in their own words. The case's own docblock carries the argument, including
     * why an identical role row is not by itself a reason to reuse.
     *
     * ── THE §18.10 PRIVACY SWITCH IS NOT CHECKED HERE, AND IT CANNOT BE ──────────────────────
     *
     * docs/04 §8.22 and docs/13 §18.10 both require a per-organization setting for whether
     * administrators may review conversations at all. It is CHECK 5 (entity status) rather than a
     * permission — `permit()` has no argument position for it — and no column exists for it yet.
     * `ConversationController` names that gap where an operator can see it.
     */
    public function viewConversations(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::ConversationsView);
    }

    /**
     * Read this organization's own audit trail.
     *
     * THE RECORD IS THE ORGANIZATION AND THERE IS NO PER-ROW HALF, deliberately. `AuditLog`
     * implements `OrgOwned` but `organizationId()` THROWS on a platform-scope row — a failed login
     * for an address that belongs to no user has no organization — so a per-row policy would be
     * unreachable for exactly the rows an intrusion investigation opens with, and reachable only
     * through an exception. There is no `AuditLogPolicy` for that reason, and the list endpoint is
     * the only reader: `EloquentAuditLogRepository::paginate()` takes `organization_id` as a
     * required positional argument and that filter is the whole of this table's tenancy.
     *
     * `Permission::AuditView` is an EXTENSION of the specification — §6.1 assigns "Access
     * platform-level audit logs" to the platform owner and §6.2-§6.5 name no org role — and the
     * case's own docblock says so rather than letting a filled-in silence read as a grant.
     */
    public function viewAuditLog(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::AuditView);
    }

    /**
     * Read the four quota ceilings and how much of each is used.
     *
     * `Permission::AnalyticsView` AND NOT `QuotasManage`, deliberately. Reading how much of an
     * allowance is left is a REPORT, and it is the same figure `AnalyticsResource`'s
     * `storage_bytes_used` already publishes to every holder of `analytics.view`. Gating the read
     * behind the WRITE permission would mean only the Organization Owner could see a number that is
     * already on the dashboard — a difference nobody could act on, and a rule the next reader would
     * delete as inconsistent.
     */
    public function viewQuotas(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::AnalyticsView);
    }

    /**
     * Set the four quota ceilings.
     *
     * `Permission::QuotasManage`, which the Organization Owner alone holds among the four org roles
     * — an Administrator cannot touch a quota at all. That grant is the narrow reading of a
     * specification that does not agree with itself: §6.1 gives "control limits for storage, bots,
     * users, and ingestion" to the PLATFORM owner while §6.2 gives the Organization Owner "manage
     * organization settings". `Permission::QuotasManage` records the contradiction rather than
     * resolving it.
     *
     * ── THIS ABILITY DOES NOT DECIDE WHETHER A LIMIT MAY BE *RAISED* ────────────────────────
     *
     * `App\Services\Quotas\QuotaLimitService` does, and it must, because `permit()` takes
     * `(user, record, permission)` and has NO ARGUMENT POSITION for the direction of the change —
     * the same structural reason `Permission::MembersManageOwner` is its own case rather than logic
     * inside a policy body. Here the missing argument is not a role, it is a comparison between the
     * submitted numbers and the persisted ones, which only the service can make. An org owner may
     * LOWER a ceiling; raising or removing one needs `users.is_platform_owner`.
     */
    public function manageQuotas(?User $user, Organization $organization): Response
    {
        return $this->permit($user, $organization, Permission::QuotasManage);
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

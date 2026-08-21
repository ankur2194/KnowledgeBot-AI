<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\KnowledgeSource -> App\Policies\KnowledgeSourcePolicy. No
 * `Gate::policy()` call and no provider edit — the `Surface` OrgScopedPolicy needs is
 * constructor-injected by the container binding and set per request by the `surface:*` middleware.
 *
 * Every ability here authorizes a row the caller ALREADY HOLDS, and the organization comes from
 * that row through OrgOwned. The complementary halves — "may this user list sources at all" and
 * "may they create one" — are `OrganizationPolicy::viewSources()`, `createSource()` and
 * `uploadSource()`, because there is no source row to take an organization from before the list is
 * fetched. The same split `BotPolicy` makes against `OrganizationPolicy`.
 *
 * ── EVERY ABILITY IS A ONE-LINE DELEGATION, AND THAT SHAPE IS THE CONTROL ────────────────────
 *
 * `OrgScopedPolicy::permit()` resolves membership of THE RECORD'S organization and then the role,
 * so "admin of some organization" is unrepresentable — there is no argument position a caller could
 * pass a session's current org into. An arch test pins every class in App\Policies to that base for
 * exactly this reason, and a body here that compared a role directly would be the first violation.
 *
 * ── A PASSING POLICY DOES NOT LICENSE AN UNSCOPED QUERY ──────────────────────────────────────
 *
 * This class is layer 3 of seven. The admin routes nest `{source}` under `{organization}` with
 * `->scopeBindings()`, so a foreign or unknown id 404s at BINDING time — before this class is
 * constructed and before the row is in memory — and every repository method still takes
 * `organization_id` as a required positional argument. What this policy refuses is the one case
 * neither of those can see: the row IS in this organization and the caller's role is wrong.
 *
 * ── WHY THERE IS NO `publish`, NO `disable` AND NO `archive` ABILITY ─────────────────────────
 *
 * All three are `update` behind `sources.manage`, because a separate permission for each would be
 * granted to exactly the same three roles — and `Permission::BotsManage` records what that costs:
 * a permission nobody grants differently is a permission that fails silently in both directions.
 *
 * WHAT MAKES A DISABLE STRICTER THAN A RENAME IS NOT THE PERMISSION. It is CHECK 5 of the six
 * (kb-security-baseline §18.4) — the ENTITY STATUS — and `permit()` has no argument position for
 * it. `SourceState::canTransitionTo()` is that check, it is asked by the service, and it is the one
 * that refuses `Ready -> Deleted` skipping `Deleting` and `Indexing -> Ready` without a passing
 * verification. A policy authorizes a caller; it cannot authorize a state.
 *
 * ── THE THREE ABILITIES THAT ARE *NOT* `sources.manage`, AND WHY EACH IS SEPARATE ───────────
 *
 * `upload` carries `sources.upload` and `assign` carries `sources.assign`. Both permissions'
 * docblocks state the argument; the short form is that uploading is the one action that consumes a
 * quota and hands bytes to an untrusted parser, and assigning is the one action that changes the
 * retrieval SCOPE rather than the corpus — it writes the row `bot_ids` is resolved from, which is
 * the only row in the schema that can span two organizations.
 */
final class KnowledgeSourcePolicy extends OrgScopedPolicy
{
    public function view(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesView);
    }

    public function update(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesManage);
    }

    public function delete(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesManage);
    }

    /**
     * Trigger a reprocess of this source.
     *
     * `sources.manage` and not `sources.upload`, even though it re-runs the same pipeline over the
     * same bytes: no new content is admitted, no quota is consumed by the object store, and nothing
     * untrusted enters the system that was not already there. What it does spend is provider
     * embedding tokens — which is a billing concern the service checks, not an authorization one
     * this class can express.
     */
    public function reprocess(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesManage);
    }

    /**
     * Add an item or a new version to this source: upload a file, submit pasted text, or start a
     * crawl run against it.
     *
     * The record is the PARENT SOURCE, deliberately. A `source_items` row may not exist yet — an
     * upload has nothing to take an organization from — and even when it does, the source is the
     * correct scope: the composite foreign key the write is about to be checked against is
     * `(organization_id, source_id)`, so authorizing against the ORGANIZATION instead would let
     * this pass for a caller who belongs to the right organization and is addressing a source in it
     * that they were never shown.
     */
    public function upload(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesUpload);
    }

    /**
     * Assign this source to a bot, or remove that assignment.
     *
     * ── AUTHORIZED AGAINST THE SOURCE, AND THE BOT IS AUTHORIZED SEPARATELY ──────────────────
     *
     * The write names two records in two potentially different organizations, and this ability
     * covers ONE of them. The caller must additionally hold `BotPolicy::view()` on the bot — which
     * every role does, and which is why `Permission::BotsView` is granted to all four. That is a
     * controller obligation and it is stated here because a reader of this method will otherwise
     * assume it covers both halves.
     *
     * NEITHER CHECK IS THE TENANCY GUARD. The guard is the pair of composite foreign keys on
     * `bot_source_assignments`, which refuse a row whose bot and source disagree about their
     * organization — including when the caller is a legitimate member of one of the two. An
     * authorization check cannot substitute for it: a user really can be an admin of the
     * organization whose bot is named, and the write would still be a cross-tenant disclosure.
     */
    public function assign(?User $user, KnowledgeSource $source): Response
    {
        return $this->permit($user, $source, Permission::SourcesAssign);
    }
}

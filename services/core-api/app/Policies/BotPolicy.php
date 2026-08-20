<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\Bot -> App\Policies\BotPolicy. No `Gate::policy()` call and no
 * provider edit — the `Surface` OrgScopedPolicy needs is constructor-injected by the container
 * binding and set per request by the `surface:*` middleware.
 *
 * Every ability here authorizes a row the caller ALREADY HOLDS, and the organization comes from
 * that row through OrgOwned. The complementary halves — "may this user list bots at all" and "may
 * they create one", neither of which has a bot row yet — are `OrganizationPolicy::viewBots()` and
 * `OrganizationPolicy::createBot()`, because there is no bot to take an organization from before
 * the list is fetched. The same split `ProviderConnectionPolicy` makes against `OrganizationPolicy`
 * one level up.
 *
 * ── THIS CLASS IS LAYER 3 OF SEVEN AND IT IS ALSO THE MOST OVERLOADED ONE ─────────────────────
 *
 * A passing policy does not license an unscoped query. The admin routes nest `{bot}` under
 * `{organization}` with `->scopeBindings()`, so a foreign or unknown id 404s at BINDING time —
 * before this class is constructed and before the row is in memory — and every repository method
 * still takes `organization_id` as a required positional argument. This policy refuses the one case
 * those cannot see: the row IS in this organization and the caller's role is wrong.
 *
 * It is overloaded because a bot is the retrieval scope. `bot_ids` is one of the four mandatory
 * Qdrant filter terms, so an authorization mistake here is not "somebody saw a settings page" — it
 * is a bot answering out of a corpus, on a credential, and against a quota, that authorization was
 * supposed to bound. Which is exactly why none of that boundary lives in this file: the tenant
 * filter is the data plane's, the credential is the vault's, and this class decides one thing.
 *
 * ── THE ROLE SPLIT IS NOT "OWNER AND ADMIN ONLY", AND IT IS WIDER THAN THE PROVIDER POLICIES ──
 *
 * `view` is `bots.view` and every write is `bots.manage`. All FOUR roles hold `bots.view` —
 * knowledge_manager because Phase C6 has them assign sources to bots, analyst because Phase E has
 * them review conversations per bot — and §6.4/§6.5 say nothing about bots in either direction, so
 * `Permission::BotsView` and `OrgRole::grants()` both record that this is an EXTENSION of the
 * specification decided with the repo owner rather than a reading of it.
 *
 * The asymmetry is asserted PER ACTION in tests/Security/BotAccessTest.php rather than assumed from
 * a uniform dataset, for the reason `ProviderModelEntryPolicy` states: a uniform dataset would go
 * green against a `view` that had silently been mapped to `bots.manage`. Here that would be
 * invisible in a different way — every role that holds `bots.manage` also holds `bots.view`, so the
 * mis-mapping would only show up for the two roles the dataset is most likely to under-cover.
 *
 * ── WHY THERE IS NO `publish` ABILITY ────────────────────────────────────────────────────────
 *
 * Publishing is `update` behind `bots.manage`, because a `bots.publish` permission would be granted
 * to exactly the same two roles — and a permission nobody grants differently is a permission that
 * fails silently in both directions (App\Enums\Permission's own docblock). What makes publishing
 * stricter than a rename is CHECK 5, entity status: a bot with no model, with no assigned source,
 * or with `allow_general_answers` false in RAG-first mode is refused by the publish guard in the
 * controller, which is a check `permit()` has no argument position for.
 */
final class BotPolicy extends OrgScopedPolicy
{
    public function view(?User $user, Bot $bot): Response
    {
        return $this->permit($user, $bot, Permission::BotsView);
    }

    public function update(?User $user, Bot $bot): Response
    {
        return $this->permit($user, $bot, Permission::BotsManage);
    }

    public function delete(?User $user, Bot $bot): Response
    {
        return $this->permit($user, $bot, Permission::BotsManage);
    }

    /**
     * Add, edit or remove an entry in the widget origin ALLOW-LIST, a starter question, or a link
     * in the fallback chain.
     *
     * The record is the PARENT BOT, deliberately, and for two reasons that both matter. A child row
     * may not exist yet — `createDomain` has nothing to take an organization from — and even when
     * it does, the bot is the correct scope: the composite foreign key the write is about to be
     * checked against is `(organization_id, bot_id)`, so authorizing against the organization
     * instead would let this pass for a caller who belongs to the right organization and is
     * addressing a bot in it that they were never shown.
     *
     * One ability for all three child collections rather than three, because they carry the same
     * permission and are edited from the same screen. If the origin allow-list ever needs a
     * stricter rule than the starter questions, it gets its own ability then — splitting it now
     * would produce two methods with identical bodies and no reviewer would know which to change.
     */
    public function manageChildren(?User $user, Bot $bot): Response
    {
        return $this->permit($user, $bot, Permission::BotsManage);
    }
}

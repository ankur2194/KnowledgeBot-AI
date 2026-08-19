<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The permission catalog, in code (laravel-rbac-policies, "Package or hand-rolled").
 *
 * Roles are fixed by the spec, so there is no per-tenant role CRUD to store and nothing here needs
 * a table. The only database fact about authorization is the membership row.
 *
 * Only the permissions this change set actually authorizes are listed. A case nobody grants and
 * nobody checks is a permission that fails silently in both directions.
 */
enum Permission: string
{
    /** Read a provider connection, its masked credential, and the organization's embedding readiness. */
    case ProvidersView = 'providers.view';

    /**
     * Create or edit a provider connection, and DESIGNATE which connection supplies the embedding
     * credential.
     *
     * The designation sits behind the same permission as the credential itself, not behind a
     * weaker "settings" permission, because moving it moves the vector space every future corpus
     * is indexed under and moves which credential pays for the embedding calls.
     */
    case ProvidersManage = 'providers.manage';

    /**
     * Read a bot's configuration, its origin allow-list, and its starter questions.
     *
     * HELD BY FOUR ROLES OUT OF FOUR, WHICH MAKES IT THE WIDEST PERMISSION IN THIS CATALOG, and
     * that is an EXTENSION OF THE SPECIFICATION rather than a reading of it. §6.3 gives an
     * Organization Administrator "Manage bots"; §6.4 and §6.5 describe the Knowledge Manager and
     * the Analyst without mentioning bots at all, in either direction. The grant below was decided
     * with the repo owner against two upcoming surfaces, and it is written out here because a
     * silence in the spec that somebody filled in should not read later as something the spec said:
     *
     *   Knowledge Manager  Phase C6 has them assign knowledge sources TO bots. The assignment
     *                      screen is a list of bots; a role that cannot read a bot cannot use it.
     *   Analyst            Phase E has them review conversations PER BOT. A conversation is
     *                      unreadable without the bot that produced it — the name, the model, and
     *                      the answer mode are what make a transcript interpretable.
     *
     * Neither grant leaks anything a member of the organization is not already entitled to: a bot's
     * configuration carries no credential (`provider_connection_id` is a reference, and the key
     * behind it never leaves the vault) and no end-user content.
     *
     * ── THAT SENTENCE WAS SILENT ABOUT THE PROMPT, AND THE SILENCE WAS A DISCLOSURE ───────────
     *
     * "No credential, no end-user content" is true and does not cover `system_instruction` or
     * `answer_style_instruction`, which are OPERATOR-authored text and neither of those things. As
     * first shipped, `BotResource` rendered both unconditionally, so this permission — held by an
     * Analyst who holds nothing else — read every bot's full system prompt off one page of the list
     * endpoint. Fixed by NARROWING THE PROJECTION, not this grant: both fields are rendered only to
     * a caller holding `BotsManage` and are null otherwise, the keys stay present, and the flag is
     * resolved once per request in `BotController`. `BotResource`'s docblock carries the reasoning
     * — including the asymmetry that settles it, `AuditLogger` refusing the same string from
     * `details` — and ADR-056 carries the amendment and the alternatives it rejected.
     *
     * The rule to take from it: this permission's justification is about a bot's CONFIGURATION.
     * Adding a column that is operator-authored PROSE THE MODEL EXECUTES puts it on the other side
     * of the line, and it belongs in the projection rather than in this sentence.
     */
    case BotsView = 'bots.view';

    /**
     * Create, edit, publish, pause, archive or delete a bot; edit its origin allow-list, its
     * starter questions, its retrieval configuration and its fallback chain.
     *
     * OWNER AND ADMIN ONLY, which IS a reading of the spec — but read what it actually says.
     * §6.2 gives the Organization Owner "Create and publish bots" and §6.3 gives the Organization
     * Administrator "Manage bots". Those two grants are the whole of the positive evidence, and
     * they are enough.
     *
     * §6.4 DOES NOT EXCLUDE ANYTHING, and an earlier draft of this docblock said it did. That
     * section is a six-item list about SOURCES — upload, add sites, review parsed content, trigger
     * reprocessing, disable/archive/delete sources, view freshness — and it never mentions bots in
     * either direction, which is exactly what `bots.view`'s note below says correctly. Restricting
     * writes to the two roles the spec names is a decision that SILENCE IS NOT A GRANT, not a
     * refusal the spec performed for us. Citing a silence as an explicit exclusion is how a
     * defensible decision acquires a false justification that outlives the person who made it.
     *
     * ONE PERMISSION FOR EVERY WRITE, INCLUDING PUBLISH AND DELETE, for the reason
     * `ProviderConnectionPolicy::rotateCredential()` records about rotation: a `bots.publish` case
     * would be granted to exactly the same two roles as this one, and a permission nobody grants
     * differently is a permission that fails silently in both directions. What makes publishing
     * stricter than a rename is not the permission — it is the publish guard, which refuses a bot
     * with no model, with no assigned source, or with `allow_general_answers` still false in
     * RAG-first mode. That is check 5 of the six (entity status) and `OrgScopedPolicy::permit()`
     * has no argument position for it.
     *
     * The origin allow-list sits behind this permission and not behind a stricter one, and that is
     * deliberate even though the list is a security control: the roles that would hold a stricter
     * permission are the same two, and splitting it would only move where the reviewer looks.
     */
    case BotsManage = 'bots.manage';

    /** List the organization's members and its invitations. */
    case MembersView = 'members.view';

    /** Invite, revoke, resend, and change a member's role or status. */
    case MembersManage = 'members.manage';

    /**
     * Grant or revoke the `owner` role.
     *
     * A SEPARATE PERMISSION RATHER THAN LOGIC INSIDE A POLICY BODY, and the reason is structural.
     * `OrgScopedPolicy::permit()` takes `(user, record, permission)` and has no argument position
     * for "the role being granted" — so "only an owner may create another owner" is either a
     * permission or it is hand-rolled inside a policy method. Hand-rolling it puts a role
     * comparison in the one place the arch shape exists to keep free of them, and every ability on
     * every policy stops being a one-line delegation. As a permission it is one more row in the
     * role x permission matrix the table-driven test already asserts.
     */
    case MembersManageOwner = 'members.manage_owner';
}

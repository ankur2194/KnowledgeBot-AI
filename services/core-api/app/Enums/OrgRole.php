<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The FIXED role catalog of docs/01 §6.2–6.5. `organization_users.role` is a single scalar — one
 * role per user per organization.
 *
 * Platform Owner (§6.1) is deliberately absent: it is a PLATFORM flag on the user
 * (`users.is_platform_owner`), not an organization role, and it reaches no tenant data without an
 * audited impersonation flow (laravel-rbac-policies NN4).
 */
enum OrgRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case KnowledgeManager = 'knowledge_manager';
    case Analyst = 'analyst';

    /**
     * The role x permission matrix, as data, in one place.
     *
     * KnowledgeManager may NOT reach provider credentials (§6.4 lists them as excluded) — and the
     * embedding designation is on the credential side of that line for the reason Permission
     * records: it selects which credential pays and which vector space a corpus lands in.
     *
     * ── THE BOT GRANTS ARE AN EXTENSION OF THE SPEC, NOT A READING OF IT ──────────────────────
     *
     * `bots.manage` goes to Owner and Admin, and that IS the spec: §6.2 gives the Owner "Create
     * and publish bots" and §6.3 gives the Administrator "Manage bots". Nothing is read out of
     * §6.4 to get there — that section excludes nothing, as the paragraph below says, and an
     * earlier draft of this comment claimed otherwise. Withholding the write from the other two
     * roles is a decision that silence is not a grant.
     *
     * `bots.view` ADDITIONALLY goes to Knowledge Manager and Analyst, and §6.4 and §6.5 NEVER
     * MENTION BOTS — in either direction. This is therefore an extension of the specification
     * decided with the repo owner, and it is said plainly here rather than left to read like
     * something §6.4 implied:
     *
     *   Knowledge Manager  Phase C6 has them assign sources to bots. The assignment screen is a
     *                      list of bots, so a role that cannot read one cannot do the job §6.4
     *                      does give them.
     *   Analyst            Phase E has them review conversations per bot. A transcript is
     *                      uninterpretable without the bot that produced it.
     *
     * The Analyst arm consequently stops being a blanket `false`, and that is the change most
     * likely to surprise a reader of the old file: `analyst` now holds exactly one permission, and
     * `RolePermissionMatrixTest` states the whole row independently so the two have to agree.
     */
    public function grants(Permission $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            // Admin holds everything EXCEPT owner promotion and demotion. §6.3 excludes destructive
            // org-level actions, and creating a second owner is the one action the role that
            // performed it cannot undo: the new owner may immediately demote or remove them. An
            // admin who needs another owner asks an owner.
            // TWO EXCEPTIONS NOW, NOT ONE. `quotas.manage` joins `members.manage_owner` on the
            // list an Administrator does not hold, and the argument is §6.3's own opening
            // sentence: an administrator "manages operational settings but may not own BILLING or
            // destructive organization-level actions." A quota ceiling is the billing boundary
            // wearing an operational hat — and §6.1 assigns limit control to the PLATFORM owner
            // outright, which is a contradiction Permission::QuotasManage records rather than
            // resolves. Written as an `in_array` over a named set rather than a second `!==`,
            // because a chain of negated comparisons is where a reviewer stops reading and the
            // next exception gets appended with `&&` in the wrong place.
            self::Admin => ! in_array(
                $permission,
                [Permission::MembersManageOwner, Permission::QuotasManage],
                true,
            ),
            // Written as an explicit `in_array` over a NAMED SET rather than as a chain of `===`
            // comparisons: the set is about to keep growing (sources, conversations, evaluation
            // datasets all land on this role in later phases) and a growing `||` chain is where a
            // reviewer stops reading. Strict comparison, so no enum coerces into another.
            // THE SET GREW BY FOUR IN PHASE C1, AND THOSE FOUR ARE THE ROLE'S ACTUAL JOB. §6.4 is
            // a six-item list and every item is a source operation — upload documents, add
            // websites, review parsed content, trigger reprocessing, disable/archive/delete
            // sources, view freshness. `sources.assign` is C6's, and it is what `bots.view` was
            // granted to this role FOR: the assignment screen is a list of bots.
            //
            // `providers.view` and NOT `providers.manage` is unchanged and is the line §6.4 draws
            // explicitly: an ingestion operator has to know whether the organization can embed at
            // all, and may not touch the credential or the embedding designation.
            //
            // THE SET DID NOT GROW IN PHASE 6a, AND BOTH ABSENCES ARE DECISIONS. `conversations.view`
            // is withheld because §6.4's "review parsed content" is the SOURCE detail projection
            // that `sources.view` already serves, and a transcript is verbatim end-user text rather
            // than parsed content. `audit.view` is withheld because the audit endpoint is one list
            // over the whole operation vocabulary — granting it to answer a `source.*` question
            // hands over credential rotations, member role changes and login events carrying
            // colleagues' addresses and IPs. Each case's own docblock carries the argument.
            self::KnowledgeManager => in_array(
                $permission,
                [
                    Permission::ProvidersView,
                    Permission::BotsView,
                    Permission::SourcesView,
                    Permission::SourcesManage,
                    Permission::SourcesUpload,
                    Permission::SourcesAssign,
                ],
                true,
            ),
            // STILL EXACTLY ONE PERMISSION. §6.5 is reporting-only and `bots.view` is the one
            // permission reporting needs: Phase E's conversation review is per bot.
            //
            // THE FOUR `sources.*` CASES ARE DELIBERATELY NOT HERE, and the tempting argument for
            // adding `sources.view` is refused in Permission::SourcesView's docblock: a transcript
            // renders label, display title, location and excerpt off the CITATION row, which is
            // denormalized precisely so it survives the source being purged. Conversation review
            // reads nothing from `knowledge_sources`, so the grant would widen an analyst's reach
            // to every document title, tag and crawl URL in the organization to serve a screen that
            // does not read them.
            // NO LONGER EXACTLY ONE PERMISSION. §6.5 is reporting-only and reporting is now
            // expressible: `analytics.view` is §8.23's dashboard, and §6.5's own words are "A
            // reviewer inspects chatbot quality" plus "Compare retrieval or model configurations"
            // — the feedback split, the insufficient-evidence count and the latency percentiles
            // ARE that comparison. `bots.view` stays for the reason it was granted: a transcript is
            // uninterpretable without the bot that produced it.
            //
            // AND `conversations.view` IS THE THIRD, WHICH IS THE ONE §6.5 NAMES MOST DIRECTLY.
            // The section's first two responsibilities are "Review conversations." and "Add
            // feedback", and its fifth is "Review source citations and retrieval traces" — so the
            // transcript, its citations and its trace are this role's stated job rather than an
            // inference from it. It is the read half only; capturing feedback is the runtime's own
            // surface, authorized by conversation ownership.
            //
            // STILL NO WRITE, NO CREDENTIAL, NO MEMBER AND NO SOURCE. `audit.view` is deliberately
            // NOT here: an audit row is about COLLEAGUES — who rotated a credential, who changed a
            // role, which address failed a login — and none of §6.5's five items is an
            // administrative action. `analytics.view` reaches only aggregates, and `quotas.manage`
            // stays out because reading how much of a quota is used is a report while changing the
            // ceiling is a plan change.
            self::Analyst => in_array(
                $permission,
                [Permission::BotsView, Permission::AnalyticsView, Permission::ConversationsView],
                true,
            ),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

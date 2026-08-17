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

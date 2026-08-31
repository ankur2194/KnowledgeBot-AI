<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\OrganizationUser;

interface MembershipRepositoryInterface
{
    /**
     * Every membership row belonging to ONE user, with its organization loaded, in a TOTAL and
     * DETERMINISTIC order.
     *
     * THIS IS THE ONE QUERY IN THE APPLICATION THAT IS KEYED ON A USER RATHER THAN ON AN
     * ORGANIZATION, and that is what it is for. `organization_users` is not tenant-owned data in the
     * scoped sense — it IS the tenancy fact, the table every scoped read is authorized against — so
     * "which organizations does this person belong to" cannot itself be narrowed by an organization
     * without becoming circular. It is safe because `user_id` is the only predicate a caller can
     * supply and it comes from the authenticated session, never from a request body: there is no
     * shape of this method that returns another user's memberships, and none that returns an
     * organization's member list (that is a separate, org-scoped read).
     *
     * ORDER BY created_at ASC, organization_id ASC — EXPLICIT, AND NOT A PREFERENCE. An unordered
     * `->first()` is a real bug rather than untidiness: PostgreSQL is free to return a different row
     * per plan, so a user in two organizations would land in a different one on alternate logins,
     * intermittently, with nothing in any log. `organization_id` is `char(26) COLLATE "C"`, so ULID
     * byte order is a total, reproducible tiebreak for two memberships created in the same
     * microsecond.
     *
     * The organization is EAGER LOADED because `Model::shouldBeStrict()` is on: `/me` renders each
     * organization's name and slug, and a lazy access would throw rather than N+1 quietly.
     *
     * @return list<OrganizationUser> oldest membership first; `[]` for a user who belongs to nothing
     */
    public function forUser(string $userId): array;

    /**
     * ONE membership row — this user, in THIS organization — re-read from PostgreSQL.
     *
     * ── IT EXISTS SO A CREDENTIAL CAN BE RE-AUTHORIZED WITHOUT A USER MODEL IN HAND ─────────
     *
     * `User::membershipFor()` answers the same question and needs a hydrated `User`. The caller
     * that needs this one — `WidgetSessionService::resolve()`, on the playground branch — holds a
     * ULID out of a Valkey record and nothing else, and re-reading the row on EVERY request is the
     * whole revocation story for a credential with no database row of its own
     * (`laravel-sanctum-auth` NN1: neither a token row nor a session value is evidence of CURRENT
     * membership). Hydrating a `User` first would add a query to answer a question this one
     * answers.
     *
     * BOTH ARGUMENTS ARE REQUIRED AND THE ORGANIZATION IS FIRST, matching every other scoped read
     * in this application. There is no shape of this method that answers "any organization".
     *
     * The returned row is not evidence of anything on its own: `OrganizationUser::grants()` ANDs
     * the ACTIVE status with the role's permission set, and a suspended membership therefore grants
     * nothing while still returning a row a caller could mistake for one.
     */
    public function find(string $organizationId, string $userId): ?OrganizationUser;
}

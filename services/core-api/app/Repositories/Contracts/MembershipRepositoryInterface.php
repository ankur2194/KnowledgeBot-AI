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
}

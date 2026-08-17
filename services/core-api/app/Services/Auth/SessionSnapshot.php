<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\OrganizationUser;
use App\Models\User;

/**
 * Everything `SessionResource` renders, resolved once, from PostgreSQL.
 *
 * A VALUE OBJECT RATHER THAN THE USER MODEL, for the same reason EmbeddingReadiness is one: the
 * resource must not be able to reach a column nobody decided to publish. A `User` handed straight to
 * a resource carries `password`, `remember_token` and every future column with it, and the only
 * thing standing between those and the wire would be the resource remembering not to touch them.
 * Here the shape is fixed by the constructor.
 *
 * `$memberships` holds EVERY membership, including suspended and (should one ever be written)
 * invited ones — with its `organization` relation already loaded. A suspended member sees why their
 * organization list is greyed out instead of seeing an empty list and concluding the account is
 * broken. `$currentOrganizationId` is the opposite: it is only ever an ACTIVE membership or null.
 */
final class SessionSnapshot
{
    /**
     * @param  list<OrganizationUser>  $memberships  oldest first, each with `organization` loaded
     */
    public function __construct(
        public readonly User $user,
        public readonly ?string $currentOrganizationId,
        public readonly array $memberships,
    ) {}
}

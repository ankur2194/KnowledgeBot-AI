<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\OrganizationUser;
use App\Repositories\Contracts\MembershipRepositoryInterface;

final class EloquentMembershipRepository implements MembershipRepositoryInterface
{
    /**
     * @return list<OrganizationUser>
     */
    public function forUser(string $userId): array
    {
        // tenancy-exempt: `organization_users` carries no OrganizationScope by construction (see
        // App\Models\OrganizationUser) — it is the table that ESTABLISHES the tenant scope, so
        // scoping it by the context it produces is circular. The narrowing predicate here is
        // `user_id`, taken from the authenticated session and never from a request body, which is
        // the same guarantee OrganizationScope gives one column over.
        //
        // array_values() rather than ->all() alone: `Collection::all()` is typed `array<int, T>`,
        // which is not a `list<T>` to PHPStan at level 8, and the annotation is worth keeping
        // narrow — callers index position 0 as "the oldest membership".
        $memberships = OrganizationUser::query()
            ->where('user_id', $userId)
            // The organization row itself is loaded here rather than in a second query because
            // Model::shouldBeStrict() turns a lazy access into an exception, and /me renders each
            // organization's name and slug.
            ->with('organization')
            // Total and deterministic. See the interface for why an unordered ->first() is a bug
            // rather than a style choice.
            ->orderBy('created_at')
            ->orderBy('organization_id')
            ->get()
            ->all();

        return array_values($memberships);
    }

    public function find(string $organizationId, string $userId): ?OrganizationUser
    {
        // tenancy-exempt: `organization_users` carries no OrganizationScope by construction — it is
        // the table that ESTABLISHES the tenant scope, so scoping it by the context it produces is
        // circular (App\Models\OrganizationUser). BOTH narrowing predicates are supplied here and
        // both are positional arguments rather than ambient state, which is stricter than the
        // global scope would have been: `organization_id` comes from the credential's own stored
        // record and `user_id` from the same record, and neither is reachable from request input.
        //
        // NO `->with('organization')`: the caller wants the membership FACT — status and role — and
        // eager-loading a row nobody reads would be a second query per chat turn on the request
        // path this method exists to guard.
        $membership = OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();

        return $membership;
    }
}

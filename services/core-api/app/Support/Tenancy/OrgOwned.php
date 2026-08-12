<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * A record whose organization can be resolved FROM THE RECORD.
 *
 * This interface is the reason "admin of some organization" is unrepresentable in a policy:
 * OrgScopedPolicy::permit() takes an OrgOwned and resolves membership of THAT organization, so
 * there is no argument position a caller could pass a session's "current org" into
 * (laravel-rbac-policies NN1).
 */
interface OrgOwned
{
    public function organizationId(): string;
}

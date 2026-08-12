<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Surface;
use App\Models\User;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Auth\Access\Response;

/**
 * The ONLY place an authorization decision is made in this application.
 *
 * Note the argument order on permit(): the organization comes from the RECORD, so "admin of some
 * organization" is unrepresentable. A policy that asks "is this user an admin?" without "of THIS
 * record's organization?" grants cross-tenant write access to anyone who is an admin anywhere —
 * and it reviews as correct, because the role read is true (laravel-rbac-policies NN1).
 *
 * Every ability on every subclass is a one-line delegation to permit(). That is deliberate: it
 * leaves no syntactic room to write a role check that forgot its organization, and it makes the
 * arch rule ("policies are org-scoped by construction") checkable by shape rather than by reading.
 */
abstract class OrgScopedPolicy
{
    /** Bound per route group by App\Http\Middleware\BindSurface. */
    public function __construct(protected readonly Surface $surface) {}

    /**
     * Resolve membership of THE RECORD'S organization, then the role.
     */
    protected function permit(?User $user, OrgOwned $record, Permission $permission): Response
    {
        if ($user === null) {
            // Guests reach here only on public surfaces, where the deny is a 404.
            return $this->refuse();
        }

        // Resolved per check, never memoized across organizations: workers and Octane reuse the
        // User instance, and a cached membership is how a removed user keeps access until the
        // process recycles.
        $membership = $user->membershipFor($record->organizationId());

        return $membership !== null && $membership->grants($permission)
            ? Response::allow()
            : $this->refuse();
    }

    /**
     * The error_class is `authorization` either way; only the rendered status differs by surface
     * (kb-error-taxonomy footnote 1). Never branch retry, fallback or breaker logic on it.
     */
    private function refuse(): Response
    {
        return $this->surface->isPublic()
            ? Response::denyAsNotFound()
            : Response::deny('This action is unauthorized.');
    }
}

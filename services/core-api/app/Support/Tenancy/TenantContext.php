<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * The resolved organization for the current request or job. A container singleton.
 *
 * THE FAILURE THIS CLASS IS SHAPED AROUND IS THE POOLED ONE. Queue workers and Octane reuse
 * processes, so a context that is never cleared retains its PREVIOUS occupant — the shape of
 * CVE-2023-28859, where a cancelled request left a connection holding the next reader's data. *No*
 * tenant set is the loud failure; the *previous* tenant still set is the silent one
 * (kb-tenancy-isolation, Gotchas). Hence: every setter has a matching clear in a `finally`, and
 * `orgId()` raises rather than returning null.
 *
 * IT IS NEVER SET FROM REQUEST INPUT. The organization comes from the authenticated session or
 * token and from the membership row re-read out of PostgreSQL; an org_id a caller can set is a
 * parameter, not a scope (kb-tenancy-isolation NN6).
 */
final class TenantContext
{
    private ?string $organizationId = null;

    /**
     * Run $callback with $organizationId in scope and clear it afterwards, whatever happens.
     *
     * The `finally` is the whole point and is why this is a scoped runner rather than a bare
     * setter: a setter can be called and not unset, and the code that forgets is a queue worker
     * whose next job then reads and writes as the previous tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(string $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * The organization every scoped query is filtered by.
     *
     * Raises rather than returning null, because every caller of this method is about to build a
     * predicate and a null one is a query with no tenant term.
     */
    public function orgId(): string
    {
        if ($this->organizationId === null) {
            throw new RuntimeException(
                'No tenant context is bound. Every tenant-owned read resolves its organization '
                .'from the authenticated context, never from request input, and a query issued '
                .'without one has no tenant predicate at all.',
            );
        }

        return $this->organizationId;
    }

    /** Whether a context is bound. Read by OrganizationScope, which must not raise. */
    public function isBound(): bool
    {
        return $this->organizationId !== null;
    }
}

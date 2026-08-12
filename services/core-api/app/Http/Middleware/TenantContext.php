<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext as Context;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization for this request, RE-READS the membership row out of PostgreSQL, and
 * binds the tenant context for the duration.
 *
 * IT RUNS BEFORE SubstituteBindings — the priority list in bootstrap/app.php has said so since
 * before this class existed, and the reason is that a scoped route binding needs the organization
 * already in the container. Registered after SubstituteBindings, bindings resolve with no context
 * and the route 404s, which reads as a routing bug rather than an ordering one. Because it runs
 * first, `{organization}` here is still the raw ULID string and not a model.
 *
 * THE MEMBERSHIP IS RE-READ, EVERY REQUEST. Neither a token row nor a session value is evidence of
 * CURRENT membership — both were written in the past (laravel-sanctum-auth NN1). A user removed
 * from an organization must stop working on the very next request, with no cache flush and no
 * process restart.
 *
 * THE CONTEXT IS CLEARED IN A finally. Queue workers and FPM children are pooled, and a context
 * that is set and not cleared retains its PREVIOUS occupant. *No* tenant set is the loud failure;
 * the *previous* tenant still set is the silent one.
 */
final class TenantContext
{
    public function __construct(private readonly Context $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->route('organization');

        if (! is_string($organizationId) || $organizationId === '') {
            // A route in this group with no {organization} segment is a configuration error, not a
            // request the caller can fix. Denying is the fail-closed direction.
            throw new AuthorizationException;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            // `auth:sanctum` has already run and this should be unreachable; it is here because
            // "unreachable" is a claim about the middleware stack, and the middleware stack is
            // edited by people.
            throw new AuthenticationException;
        }

        if (! $user->isActiveMemberOf($organizationId)) {
            // `authorization` — 403 here because this is the admin surface, which is not
            // enumeration-sensitive: an organization id is not a secret and its members are
            // already entitled to know it exists. The public runtime and SDK surfaces render the
            // same class as 404 (kb-error-taxonomy footnote 1). Nothing branches on the status.
            throw new AuthorizationException;
        }

        return $this->context->runFor($organizationId, fn (): Response => $next($request));
    }
}

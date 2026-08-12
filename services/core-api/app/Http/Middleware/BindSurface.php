<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Surface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds which of the four surfaces this request arrived on, so OrgScopedPolicy can choose between
 * a 403 that admits the record exists and a 404 that does not.
 *
 * A MIDDLEWARE RATHER THAN A CONSTANT, because the same policy class authorizes the same model on
 * more than one surface: a bot is read by an admin (403 on a foreign id — a member is already
 * entitled to know it is there) and by the widget (404 — a 403 confirms it exists and turns the
 * endpoint into an enumeration oracle). The error_class is `authorization` in both cases and
 * nothing branches on the rendered status.
 */
final class BindSurface
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        // instance(), not bind(): the value must be fixed for this request, and a closure binding
        // would be re-evaluated per resolution — which is how one request ends up resolving two
        // different surfaces if anything ever mutates the argument.
        app()->instance(Surface::class, Surface::from($surface));

        return $next($request);
    }
}

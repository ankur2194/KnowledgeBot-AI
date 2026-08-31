<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Surface;
use App\Services\Sdk\WidgetSession;
use App\Services\Sdk\WidgetSessionService;
use App\Support\Contracts\PublicSurfaceGuard;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public runtime surface's credential, resolved before anything else runs.
 *
 * ═══ IT IS THE `org.member` OF THIS SURFACE, AND IT DOES THE SAME TWO JOBS IN ONE CLASS ════
 *
 * `TenantContext` (the middleware, aliased `org.member`) re-reads a membership row and binds the
 * tenant context, and its own comment says why those are one thing rather than two: the organization
 * a request may act in is the organization it is a member of, and splitting them allows a stack
 * where one ran and the other did not. The same argument applies here with a different credential —
 * `WidgetSessionService::resolve()` re-reads the bot's LIVE status and domain allow-list on every
 * request, which is this surface's equivalent of re-reading the membership, and the organization it
 * returns is what gets bound.
 *
 * ═══ IT RUNS BEFORE `SubstituteBindings`, AND THAT ORDERING IS NOT COSMETIC ════════════════
 *
 * Route-model binding issues queries for the ids in the path. Every model in the conversation graph
 * is org-scoped — directly or through `conversations` — so a binding resolved before the tenant
 * context is bound resolves against `1 = 0` and 404s, which reads as a routing bug rather than an
 * ordering one. `bootstrap/app.php`'s priority list carries the same rule for the admin surface.
 *
 * ═══ `runFor()` AND NOT A BARE SETTER, AND THE `finally` MATTERS UNDER FPM TOO ═════════════
 *
 * `TenantContext` is a container SINGLETON. Under PHP-FPM one request is one process, so a leaked
 * binding is harmless; under Octane, a queue worker, or `artisan serve` it is the pooled-worker
 * failure `kb-tenancy-isolation` calls the CVE-2023-28859 shape — the next request inherits the
 * previous tenant's scope and every "scoped" query is scoped to the wrong tenant. `runFor()`
 * restores the previous value in a `finally`, so there is no path out of this method that leaves one
 * bound.
 *
 * THE CONSEQUENCE IS THE STREAM CALLBACK, AND IT IS DEALT WITH ELSEWHERE: a `StreamedResponse`'s
 * callback runs during `send()`, after this stack has unwound, so it executes with NOTHING bound.
 * `StreamFinalizer::commit()` re-binds it deliberately and its constructor says why.
 *
 * ═══ 401 AND 404 ARE BOTH CORRECT AND THEY ARE DIFFERENT FACTS ═════════════════════════════
 *
 * A missing, malformed or expired bearer is `authentication` -> 401: the widget's refresh flow
 * triggers on exactly that pair and on nothing else, so rendering it as a 404 would leave a live
 * visitor with a dead composer and no re-mint. A valid bearer whose bot has been archived, or whose
 * embedder origin has left the allow-list, is `authorization` -> 404, which is the
 * enumeration-sensitive case. `WidgetSessionService::resolve()` owns both.
 */
final class ResolveChatSession implements PublicSurfaceGuard
{
    public function __construct(
        private readonly WidgetSessionService $sessions,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * THE SURFACE, DECLARED SO THE OPENAPI DUMPER CAN READ IT rather than match this class's name.
     * It is the same value `handle()` binds one screen below; a class that answered one thing here
     * and bound another would publish a document it does not produce.
     */
    public static function guardedSurface(): Surface
    {
        return Surface::PublicRuntime;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // BEARER ONLY, AND NEVER A QUERY PARAMETER. `laravel-sanctum-auth` non-negotiable 4: a token
        // in a URL lands in Traefik access logs, in `Referer` on every navigation away from the page,
        // and in browser history. `Sanctum::getAccessTokenFromRequestUsing()` would make a
        // query-string token work and is deliberately unused.
        $session = $this->sessions->resolve($request->bearerToken());

        // THE SURFACE, BOUND BEFORE ANY POLICY CAN READ IT. It is what `OrgScopedPolicy` reads to
        // choose 404 over 403, and `AppServiceProvider` binds `Surface::Admin` as the default — so a
        // public route that did not bind its own would deny with an admin status and become an
        // enumeration oracle.
        app()->instance(Surface::class, self::guardedSurface());
        app()->instance(WidgetSession::class, $session);

        /** @var Response $response */
        $response = $this->tenancy->runFor($session->organizationId, static fn (): Response => $next($request));

        return $response;
    }
}

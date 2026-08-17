<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REPLACES Illuminate\Auth\Middleware\EnsureEmailIsVerified, which cannot be used on this API.
 *
 * The framework's version branches on `$request->expectsJson()`: JSON callers get `abort(403)`, and
 * everyone else gets `Redirect::guest(URL::route('verification.notice'))`. There is no route named
 * `verification.notice` in this application and there never will be — Laravel serves no HTML here, and
 * the verification notice is a Next.js page on a different host. So the else branch throws
 * RouteNotFoundException, which renders as `internal_dependency` / 500.
 *
 * That branch is reachable. `expectsJson()` is false for a request with no `Accept` header and no
 * `X-Requested-With`, i.e. plain `curl https://api.…/api/v1/organizations/{id}/…` with a valid session
 * cookie. The result is an unauthenticated-adjacent 500 on a route whose only fault was that the
 * caller did not ask for JSON — a 500 that also tells the caller their session is good, which a 403
 * would have told them more cheaply.
 *
 * This version has no else branch, because on this API there is no second kind of client:
 * `config/cors.php` scopes every surface to `api/*`, `rt/*`, `sdk/*`, and browsers, the widget and
 * mobile all send `Accept: application/json`. A caller that does not is malformed, and a malformed
 * caller still gets the right answer about their own state.
 *
 * `AuthorizationException` rather than `abort(403)` so the status is not hard-coded here: the render
 * closure in bootstrap/app.php maps it to `authorization` and then to 403 on the admin surface and
 * **404 on the public runtime and SDK surfaces**, which is the enumeration rule. A literal 403 would
 * confirm on a public surface that the record exists.
 *
 * Unverified is `authorization`, not `authentication`, and the distinction is load-bearing: identity
 * is proven — the session is valid and the user is who they say — so a 401 would send the SPA to the
 * login form, where signing in again changes nothing and loops. What is missing is a permission.
 */
final class EnsureEmailIsVerified
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A user model that does not implement MustVerifyEmail cannot be unverified, so it passes.
        // This is the framework's own semantics and it is why the check is `instanceof` rather than a
        // truthiness test on a column: a model without the contract has no verification concept, and
        // failing closed here would lock out every future non-human principal.
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

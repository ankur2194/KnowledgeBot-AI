<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "FOUR CLIENT CLASSES, FOUR MECHANISMS, NO FIFTH" (laravel-sanctum-auth NN2), made executable on
 * the one surface where a second mechanism would otherwise be reachable.
 *
 * The admin API's credential is the Sanctum SPA cookie session. Nothing in this application mints a
 * personal access token — App\Models\User deliberately does not use `HasApiTokens` (decision D11) —
 * so an `Authorization: Bearer` header arriving on `api/*` is, by definition, either a client that
 * has confused two surfaces or somebody probing for a second auth path. Either way it is a 401.
 *
 * AND IT CLOSES A LIVE, UNAUTHENTICATED 500 THAT EXISTS INDEPENDENTLY OF THIS UNIT.
 * `Laravel\Sanctum\Guard::__invoke()` falls through to its bearer branch whenever an `auth:sanctum`
 * route carries a non-empty bearer token and no valid session, and that branch calls
 * `PersonalAccessToken::findToken()` against a table NO MIGRATION CREATES — `SQLSTATE[42P01]`,
 * rendered by bootstrap/app.php as 500 / `internal_dependency`, from any anonymous caller. Throwing
 * here means the guard is never reached, so the fix needs neither the table nor a schedule entry.
 * DO NOT "fix" it instead by creating `personal_access_tokens`: that would make a bearer token
 * merely *fail* rather than be *refused*, which is a weaker statement, and it would add a table with
 * no writer plus a `sanctum:prune-expired` entry with nothing to prune.
 *
 * ── BOTH EXTRACTORS, AND THE REASON IS A MEASURED BYPASS ─────────────────────────────────────────
 * This class used to match ONLY the scheme, with `preg_match('/^\s*bearer(\s|$)/i', …)`. That is
 * anchored to the START of the header, and `Request::bearerToken()` is not: it uses
 * `strripos($header, 'Bearer ')`, which is case-insensitive, matches ANYWHERE in the value, and takes
 * the LAST occurrence. The two extractors therefore disagreed, and the disagreement was the hole.
 * Measured on the project image:
 *
 *   "Bearer abc"              rejected  -> guard never reached
 *   "X bearer abc"            PASSED    -> Sanctum extracted "abc"
 *   "NotBearer abc"           PASSED    -> Sanctum extracted "abc"
 *   "Basic Zm9v, Bearer abc"  PASSED    -> Sanctum extracted "abc"
 *
 * So `curl -H 'Authorization: NotBearer x' …/api/v1/me` walked past this middleware, reached
 * `Guard::__invoke()`'s bearer branch with no session, and hit `personal_access_tokens` — the 42P01
 * this class exists to prevent, as an anonymous 500 on every `auth:sanctum` route. And the day that
 * table exists it would have been a live second credential mechanism on the surface D11 says has one.
 * Found by the Batch 6 security audit; the suite was green because its five spellings were all
 * prefix-anchored, i.e. it asserted the property the middleware happened to have rather than the
 * property Sanctum's extractor requires.
 *
 * The fix is to stop re-implementing the extraction. `$request->bearerToken()` is BY DEFINITION what
 * the guard will see, so agreeing with it cannot drift; the scheme regex is kept as a second clause
 * because `bearerToken()` returns null for a header of exactly `Bearer`, or `Bearer ` with only
 * whitespace — harmless at the guard, but still a client using the wrong mechanism, and the invariant
 * this class states is "this surface has no bearer credential" rather than "no bearer token parsed".
 *
 * Other schemes are left alone on purpose: `Basic` alone is not a Sanctum path, is not a claim about
 * our API, and is already ignored by every guard here — refusing it would be scope this class has no
 * reason to take. Note that `Basic …, Bearer …` IS now refused, correctly: what makes it a finding is
 * not the `Basic` half but that Sanctum reads a bearer token out of it.
 *
 * WHY IT IS PREPENDED TO THE `api` GROUP AND NOT REGISTERED GLOBALLY. `rt/*` and `sdk/*` are the
 * public runtime and SDK surfaces, and `internal/*` is the HMAC seam; when those grow their own
 * credential handling, a bearer token may be exactly the right thing there (mobile carries one).
 * A global registration would pre-empt that decision for three surfaces this class knows nothing
 * about.
 *
 * WHY IT IS NOT IN bootstrap/app.php's `priority()` LIST. It reads one header and throws; it needs
 * nothing else to have run first, and it must run before `auth:sanctum`. Leaving it out of the list
 * is what keeps it at the head of the group — `SortedMiddleware` only ever moves entries that ARE
 * in the priority map, and only relative to each other, so an unlisted entry at index 0 stays at
 * index 0. Adding it to the list would imply an ordering guarantee it does not need and would put a
 * fifth name in a list whose whole value is being diffable against the framework's own.
 */
final class RejectBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->headers->get('Authorization');

        // FIRST CLAUSE: exactly what the guard will extract. Anything `bearerToken()` finds, Sanctum
        // finds — so this clause cannot fall behind the framework's parsing, however odd the header.
        // SECOND CLAUSE: the scheme with no parsable value, which `bearerToken()` returns null for.
        if (
            $request->bearerToken() !== null
            || (is_string($authorization) && preg_match('/^\s*bearer(\s|$)/i', $authorization) === 1)
        ) {
            // `authentication` -> 401 through bootstrap/app.php's render closure. Not 403: the
            // caller presented no valid proof for THIS surface, which is the definition of the
            // authentication class rather than the authorization one (kb-error-taxonomy).
            throw new AuthenticationException;
        }

        return $next($request);
    }
}

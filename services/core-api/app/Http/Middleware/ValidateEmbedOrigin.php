<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Surface;
use App\Support\Contracts\PublicSurfaceGuard;
use App\Support\Web\ExactOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SDK surface's boundary: an `Origin` header that is a real, exact origin — or a 404.
 *
 * ═══ WHAT THIS CHECKS AND WHAT IT DELIBERATELY DOES NOT ════════════════════════════════════
 *
 * It checks the header EXISTS and PARSES. Whether that origin is on a particular bot's allow-list is
 * `BotDomainMatcher`'s, inside the service, because the answer depends on which bot the body names
 * and this middleware runs before any body has been read. Splitting it that way means the two checks
 * cannot disagree about what an origin IS: both go through `ExactOrigin`, which is byte-for-byte the
 * same normalisation the stored rows were written under.
 *
 * ═══ THE FOUR REJECTIONS, AND WHY EACH IS A 404 AND NOT A 400 ══════════════════════════════
 *
 *   ABSENT `Origin`        The browser sets it and page script cannot forge it, so its absence means
 *                          the claim was never made. It is never a default-allow — a non-browser
 *                          client can omit it freely, which is exactly why omission cannot be
 *                          treated as trustworthy.
 *   `Origin: null`         A real header VALUE, sent by a sandboxed iframe, a `data:` document and a
 *                          cross-origin redirect. Allow-listing the string `"null"` allow-lists every
 *                          opaque context on the internet (`kb-security-baseline` control 4).
 *   A NON-ORIGIN          A path, a query, userinfo, a wildcard, an IPv6 literal, a non-ASCII host.
 *                          `ExactOrigin` refuses each with a reason and this discards the reason:
 *                          the SDK surface answers one 404 with one body, and a per-reason message
 *                          would be a channel an attacker reads.
 *   A DIFFERENT ORIGIN     Not decided here — see above.
 *
 * There is no `400` in the taxonomy at all, so a malformed header cannot render as one even if that
 * felt more honest: the eighteen classes are closed and a status not in that table cannot be
 * produced by a correct implementation.
 *
 * ═══ IT BINDS THE SURFACE, WHICH IS WHAT MAKES THE 404 HAPPEN AT ALL ═══════════════════════
 *
 * `AppServiceProvider` binds `Surface::Admin` as the container default. A public route that did not
 * bind its own would have `OrgScopedPolicy` deny with a 403 and the render closure produce the admin
 * body — turning every SDK rejection into an existence oracle, silently, on a surface whose entire
 * threat model is a hostile page.
 */
final class ValidateEmbedOrigin implements PublicSurfaceGuard
{
    /**
     * THE SURFACE, DECLARED SO THE OPENAPI DUMPER CAN READ IT — see `PublicSurfaceGuard`. On this
     * surface the "credential" is a browser-set header rather than anything the caller holds, which
     * is why the document publishes `security: []` beside an `x-kb-origin-validated` extension.
     */
    public static function guardedSurface(): Surface
    {
        return Surface::Sdk;
    }

    public function handle(Request $request, Closure $next): Response
    {
        app()->instance(Surface::class, self::guardedSurface());

        // THE HEADER, AND ONLY THE HEADER. Never `$request->input('origin')` and never a query
        // parameter: those are page script's to choose, and the whole value of this value is that it
        // is not. A custom `X-KB-Embedder-Origin` is not a substitute for the same reason.
        $origin = $request->headers->get('Origin');

        // A PREFLIGHT CARRIES NO CREDENTIAL AND MUST NOT BE ANSWERED WITH A 404. Laravel's CORS
        // middleware answers `OPTIONS` before the route runs; if any path reaches here with one, a
        // 404 would fail the preflight and the browser would report an opaque CORS error with an
        // empty server log — the exact symptom `config/cors.php` exists to prevent.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        if ($origin === null || $origin === '' || $origin === 'null') {
            abort(404);
        }

        // PARSED, NOT PATTERN-MATCHED. `ExactOrigin` is the same class the stored allow-list rows
        // were normalised through, so "is this an origin" has one answer in this application rather
        // than one per call site.
        if (! ExactOrigin::parse($origin) instanceof ExactOrigin) {
            abort(404);
        }

        return $next($request);
    }
}

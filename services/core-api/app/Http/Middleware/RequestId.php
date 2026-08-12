<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Observability\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mints (or adopts) the correlation id for one request and makes it reachable from the log
 * formatter, the error envelope, and the client.
 *
 * ONE HEADER NAME ACROSS ALL FOUR SURFACES: `X-KB-Request-Id`.
 *
 * That is not a new name. It is already the internal wire's (`kb-internal-api-contracts` — it is
 * inside the HMAC signature and is the replay nonce), it is already what
 * `App\Services\Internal\InternalAiClient` sends outbound, and `bootstrap/app.php`'s render
 * closure already echoes it back in `request_id` on EVERY error envelope, public surfaces
 * included. A second, public-only spelling would mean the id in the envelope and the id in the log
 * line were different strings for the same request, which defeats the only thing a correlation id
 * is for. Because this middleware writes the resolved value back onto the Request's own headers,
 * that render closure keeps working unchanged and now agrees with the logs by construction.
 *
 * AN INBOUND VALUE IS VALIDATED, NEVER TRUSTED VERBATIM. On `internal/*` the header is covered by
 * the signature, but on the three browser-reachable surfaces it is attacker-controlled, and a
 * request id is written into a log store and echoed into a response body. An unvalidated value is
 * therefore both a log-forging primitive (a newline splits one line into two, the second of which
 * the attacker wrote) and an unbounded string on a field an operator greps. :self::PATTERN is a
 * bounded alphabet and a bounded length; anything else is discarded and a fresh ULID is minted,
 * silently — refusing the request would let a caller DoS itself with a typo.
 *
 * IT IS GLOBAL MIDDLEWARE, AND THAT POSITION IS THE POINT. Registered in a group it would run
 * after routing and after `TenantContext`, so a 404 from route-model binding, a 403 from
 * `TenantContext`, a 419 from CSRF and a 401 from Sanctum — the four denials somebody actually
 * has to debug — would all log and render with `request_id: null`. Global middleware runs before
 * the router, so every request that reaches PHP has an id, including one that matches no route at
 * all. Global middleware is NOT sorted by `$middleware->priority()`; that list orders route and
 * group middleware only, so adding an entry there for this class would be an inert line implying
 * an ordering guarantee it does not provide.
 *
 * THE CONTEXT IS CLEARED IN `terminate()`, NOT IN A `finally`. Laravel's kernel catches a
 * Throwable OUTSIDE the middleware pipeline, so the pipeline has already unwound — every `finally`
 * with it — by the time `report()` writes the exception's log line and the render closure builds
 * the envelope. A `finally` here would therefore strip the id from precisely the lines that need
 * it. `terminate()` runs after the response is sent, which is after both. And because
 * `LogContext::bind()` overwrites unconditionally on the way in, a `terminate()` that never runs
 * (an artisan command, a fatal) cannot leave one request's id on the next one.
 */
final class RequestId
{
    public const HEADER = 'X-KB-Request-Id';

    /**
     * Bounded alphabet, bounded length. Wide enough for a ULID (26), a UUID with hyphens (36) and
     * whatever a client SDK already generates; narrow enough that no CR, LF, quote, brace or
     * control character can reach a JSON log line or a response header.
     */
    private const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        // Onto the REQUEST's own headers, so anything downstream that reads the header — the error
        // render closure in bootstrap/app.php, a controller, a job payload — sees the one resolved
        // value rather than re-deriving a second one.
        $request->headers->set(self::HEADER, $id);

        LogContext::bind($id, static function () use ($request): ?string {
            $route = $request->route();

            if (! $route instanceof Route) {
                return null;
            }

            // Route NAME first, then METHOD + the registered URI PATTERN. Both are bounded by
            // construction — they are strings this application declared — so neither can carry a
            // tenant identifier into `operation`. The actual path would: `rt/v1/bots/01J.../chat`
            // puts a bot ULID on a field the catalogue does not admit one on.
            return $route->getName()
                ?? $request->getMethod().' '.$route->uri();
        });

        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        LogContext::forget();
    }

    private function resolve(Request $request): string
    {
        $supplied = $request->headers->get(self::HEADER);

        if (is_string($supplied) && preg_match(self::PATTERN, $supplied) === 1) {
            return $supplied;
        }

        // A ULID rather than a UUID: lexicographically sortable, 26 characters, and the same shape
        // InternalAiClient already mints for the outbound leg of the seam.
        return (string) Str::ulid();
    }
}

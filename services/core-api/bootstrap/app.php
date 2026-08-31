<?php

declare(strict_types=1);

use App\Support\Kb\ErrorTaxonomy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // `web` holds exactly two routes (health + the CSRF cookie). Everything a client can reach
        // lives in one of the four groups registered in `then:` below.
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // The schedule itself lives in routes/console.php, NOT in withSchedule(): Laravel 11+ removed
        // app/Console/Kernel.php, and keeping the schedule beside the commands is what lets
        // `schedule:list` be asserted in CI against one file (laravel-scheduler).
        health: '/up',
        then: function (): void {
            // FOUR DISJOINT GROUPS — AND FIVE FILES. There is deliberately no routes/api.php: one
            // shared api file is exactly how a public runtime route ends up inheriting the admin
            // group's session, CSRF and cookie stack (laravel-sanctum-auth NN2,
            // laravel-control-plane DoD).
            //
            // A FIFTH FILE IS NOT A FIFTH GROUP, AND THIS SENTENCE EXISTS SO NOBODY READS IT AS ONE.
            // routes/api_auth.php mounts on the SAME `api` middleware group as routes/api_admin.php,
            // at the same `api/v1` prefix, and every route in it binds `surface:admin`. The split is
            // by SHAPE, not by surface: everything in api_admin.php sits under `{organization}` with
            // ->scopeBindings() and carries auth:sanctum + org.member, which login, /me and the
            // password-reset family cannot. Two files, one group, one surface — the count of GROUPS
            // below is still four, and the day a sixth file appears it must join one of them or
            // declare itself a fifth group here explicitly.
            //
            // Only the admin group uses the `api` middleware group, because statefulApi() prepends
            // EnsureFrontendRequestsAreStateful to `api` — and the public runtime, the SDK, and the
            // internal surface must never acquire ambient session authority from a stateful Origin.

            // 1. Admin API — Sanctum SPA cookie session + org membership. Surface: admin (403 deny).
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('admin.')
                ->group(base_path('routes/api_admin.php'));

            // 1b. Authentication & session — the SAME group, the SAME surface, the SAME prefix.
            //     Mounted with NO name prefix because its routes name themselves in full (`auth.*`):
            //     these are not admin.* resources, they are the session itself, and `admin.auth.me`
            //     would read as an organization-scoped route.
            //
            //     ORDER MATTERS ONLY IN ONE DIRECTION and it is satisfied here: api_admin.php's
            //     routes are all under `organizations/{organization}/...`, so no static path in this
            //     file can be shadowed by one of its patterns. Registering it second keeps the admin
            //     file — the one that grows — first in `route:list`.
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_auth.php'));

            // 2. Public chat runtime — widget/hosted-chat/mobile. Surface: public (404 deny).
            //    `rt` sits OUTSIDE `api/` so it is impossible to accidentally inherit the admin
            //    group by prefix; config/cors.php lists it explicitly for that reason.
            Route::middleware('runtime')
                ->prefix('rt/v1')
                ->name('runtime.')
                ->group(base_path('routes/api_public.php'));

            // 3. SDK bootstrap — unauthenticated, origin-checked, 404 on every rejection.
            Route::middleware('sdk')
                ->prefix('sdk/v1')
                ->name('sdk.')
                ->group(base_path('routes/api_sdk.php'));

            // 4. Internal — HMAC-verified callbacks FROM FastAPI. No cookie, no token, no CORS,
            //    no Traefik router label, never on the `edge` network (kb-internal-api-contracts).
            Route::middleware('internal')
                ->prefix('internal/v1')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // GLOBAL, AND FIRST. RequestId mints X-KB-Request-Id and binds it for the log formatter and
        // for the error envelope, so `request_id` — one of the eight fields every log line must
        // carry (kb-observability-conventions) — is present on every line of every request.
        //
        // WHY THE POSITION. Registered in a group instead, it would run after routing and after
        // TenantContext, so a 404 from route-model binding, a 403 from TenantContext, a 419 from
        // CSRF and a 401 from Sanctum — the four denials somebody actually has to debug — would
        // each log and render with `request_id: null`. Global middleware runs before the router,
        // so even a request matching no route at all is correlated. prepend() rather than append()
        // puts it ahead of TrustProxies and ValidatePostSize too: it reads no client-controlled
        // value it has to trust (the inbound header is validated against a bounded pattern, never
        // adopted verbatim), so there is nothing it needs those to have run first.
        //
        // It is deliberately NOT in the priority() list below. That list sorts ROUTE and GROUP
        // middleware only; a global entry there would be inert while implying an ordering
        // guarantee it does not provide.
        $middleware->prepend(\App\Http\Middleware\RequestId::class);

        // TRUSTED PROXIES — Traefik is the direct TCP peer of every request from the internet, so
        // `$request->ip()` comes from `X-Forwarded-For` or from nowhere. Without this, the per-IP axis
        // of five rate limiters is ONE GLOBAL BUCKET (20 logins/minute for the whole internet;
        // 10 password-reset requests/minute, i.e. a one-host denial of service against every user), and
        // `audit_logs.ip_address` records the reverse proxy on every row. See config/trustedproxy.php.
        //
        // The ADDRESSES come from that config file (env `TRUSTED_PROXIES`, set from compose.yaml's
        // `edge` subnet anchor); only the BITMASK can be set here, because `getTrustedHeaderNames()`
        // does not read config.
        //
        // NO `at:` ARGUMENT ON PURPOSE. `trustProxies()` guards with `if (! is_null($at))`, so passing
        // only `headers:` leaves `TrustProxies::$alwaysTrustProxies` null and lets the middleware read
        // config at REQUEST time — which is the only time config is loaded. A `config()` call in this
        // closure would return null: the application builder runs before config boots.
        //
        // THREE HEADERS, NOT THE FRAMEWORK DEFAULT OF SIX. Dropped, each for a reason:
        //   X_FORWARDED_HOST     Traefik's `passHostHeader` defaults to true and is not overridden, so
        //                        the real Host already arrives intact and the forwarded copy adds
        //                        nothing. Trusting it would make `getHost()` — and therefore `url()`,
        //                        `route()` and every Location header — depend on a client header rather
        //                        than on the router rule the unroutability latches are built around.
        //                        (It is NOT about SANCTUM_STATEFUL_DOMAINS: `fromFrontend()` matches
        //                        `Referer`/`Origin`, never the Host.)
        //   X_FORWARDED_PREFIX   Traefik emits it only behind StripPrefix, which is not used here; a
        //                        forged value would rewrite `getBaseUrl()`.
        //   X_FORWARDED_AWS_ELB  there is no ELB.
        // PROTO and PORT are NOT optional: without them `isSecure()` is false and `getPort()` is the
        // container port behind TLS, so Laravel builds `http://` URLs and admin login loops silently.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT);

        // Sanctum SPA cookie auth for the admin API. Prepends EnsureFrontendRequestsAreStateful to
        // the `api` group only — which is why the other three groups are defined separately below.
        $middleware->statefulApi();

        // "FOUR CLIENT CLASSES, FOUR MECHANISMS, NO FIFTH" (laravel-sanctum-auth NN2), made
        // executable. The admin surface's credential is the cookie session; nothing in this
        // application mints a personal access token (App\Models\User deliberately omits
        // HasApiTokens), so an `Authorization: Bearer` header on `api/*` is a 401 BY DEFINITION.
        //
        // IT ALSO CLOSES A LIVE UNAUTHENTICATED 500 THAT PREDATES THIS UNIT: Sanctum's guard falls
        // through to its bearer branch whenever an auth:sanctum route carries a bearer token and no
        // valid session, and that branch queries `personal_access_tokens` — a table no migration
        // creates. SQLSTATE 42P01, rendered as 500 / internal_dependency, from any anonymous caller.
        // Do NOT "fix" that by creating the table: it would make the wrong credential merely FAIL
        // instead of being REFUSED, and add a table with no writer plus a prune schedule with
        // nothing to prune.
        //
        // AFTER statefulApi() ON PURPOSE — prependToGroup puts the LAST prepend first, so this runs
        // ahead of EnsureFrontendRequestsAreStateful and never touches the session at all. Scoped to
        // `api` rather than global because `rt/*`, `sdk/*` and `internal/*` will make their own
        // credential decisions, and mobile's is a bearer token.
        $middleware->prependToGroup('api', \App\Http\Middleware\RejectBearerToken::class);

        // The three non-admin groups. Each is defined explicitly rather than reusing `api`, so that
        // a route-list test can assert no session middleware reaches a token-authenticated surface.
        // SubstituteBindings is present in all of them because route-model binding is what turns a
        // foreign identifier into a 404 at binding time (laravel-rbac-policies).
        // FIRST, AND BEFORE BINDINGS. One class resolves the credential, binds the SURFACE and binds
        // the TENANT CONTEXT, for the same reason `org.member` does all three on the admin surface:
        // the organization a request may act in is the organization its credential names, and a
        // stack where one of the three ran and another did not is a scoped query with no scope.
        //
        // ORDERING. `SubstituteBindings` issues queries for the ids in the path, and every model in
        // the conversation graph is org-scoped — directly or through `conversations`. Resolved
        // before the tenant context is bound, a binding runs against `1 = 0` and 404s, which reads
        // as a routing bug rather than an ordering one. The priority list below carries the same
        // rule for the admin surface's TenantContext; this group states it by ORDER because
        // ResolveChatSession is not in that list.
        //
        // THERE IS NO `BindSurface` ENTRY: ResolveChatSession binds `Surface::PublicRuntime` itself.
        // Splitting it into a second middleware would allow a route that authenticated without
        // binding the surface — and the default binding is `Surface::Admin`, so such a route would
        // deny with a 403 and an admin body, turning every rejection into an existence oracle.
        $middleware->group('runtime', [
            \App\Http\Middleware\ResolveChatSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // UNAUTHENTICATED BY DESIGN. There is no credential to resolve — the caller is a loader
        // script on a page we do not control — so the only thing that can be checked before a
        // controller runs is that an `Origin` header is present and is a real, exact origin. WHICH
        // origin is allowed depends on which bot the body names, so that half lives in the service.
        //
        // It binds `Surface::Sdk` for the same reason the runtime group's entry does.
        $middleware->group('sdk', [
            \App\Http\Middleware\ValidateEmbedOrigin::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->group('internal', [
            // FIRST, AND BEFORE BINDINGS. Nothing on this surface has any other credential — no
            // cookie, no bearer token, no session — so an unverified request must not reach a route
            // model binding, a controller, or a database read. `SubstituteBindings` issues queries
            // for the ids in the path; running it ahead of verification would make an unsigned
            // request a probe.
            \App\Http\Middleware\VerifyInternalSignature::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // MIDDLEWARE PRIORITY. The single ordering constraint that matters here is
        // TenantContext BEFORE SubstituteBindings: implicit binding resolves the model, and a
        // scoped binding needs the organization to already be in the container. Registered after
        // SubstituteBindings, bindings resolve with no tenant context and the route 404s — which
        // reads as a routing bug, not an ordering bug (laravel-rbac-policies, Gotchas).
        //
        // This is the full list, not a splice, so the order is reviewable in one place. Re-diff it
        // against Illuminate\Foundation\Http\Kernel::$middlewarePriority on every framework upgrade.
        // Forward-referencing a class that does not exist yet is safe: ::class does not autoload,
        // and SortedMiddleware compares priority entries as strings.
        //
        // THE LEADING BACKSLASHES BELOW ARE DELIBERATE AND PINT IS CONFIGURED TO KEEP THEM.
        // This file declares no namespace, so `Illuminate\Routing\...::class` without the `\` is a
        // RELATIVE name that resolves only because the file happens to sit in the global namespace
        // — correct, and indistinguishable at a glance from the same line inside app/, where it
        // would mean something else entirely. `\App\Http\Middleware\TenantContext::class` is the
        // case that forced the choice: absolute is what makes "forward reference" read as
        // deliberate rather than as a missing import. pint.json therefore sets
        // fully_qualified_strict_types.leading_backslash_in_global_namespace = true; JSON has no
        // comments, so the reason lives here. Namespaced files are unaffected by that option.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \App\Http\Middleware\TenantContext::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // `org.member` is App\Http\Middleware\TenantContext — one class doing one thing, not two.
        // It re-reads the membership row from PostgreSQL AND binds the tenant context, and those
        // are the same fact: the organization a request may act in is the organization it is a
        // member of. Splitting them into two middleware would allow a stack where one ran and the
        // other did not, and the failure of that stack is a scoped query with no scope.
        //
        // The class name matches the entry already in the priority list above, which is what puts
        // it BEFORE SubstituteBindings — a scoped route binding needs the organization already in
        // the container, and registered after it, bindings resolve with no context and 404.
        // `verified` DELIBERATELY SHADOWS the framework's own alias, and the shadowing is the fix.
        // Illuminate's EnsureEmailIsVerified redirects a non-JSON caller to a route named
        // `verification.notice`, which does not exist here and never will — this application serves no
        // HTML, and the notice is a Next.js page on another host. That branch is reachable with a plain
        // curl carrying a valid session cookie and no Accept header, and it renders as a 500. Ours
        // throws AuthorizationException instead, so the render closure applies the surface-aware
        // 403-admin / 404-public split rather than leaking a 500. See the class docblock.
        $middleware->alias([
            'org.member' => \App\Http\Middleware\TenantContext::class,
            'surface' => \App\Http\Middleware\BindSurface::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // NO GUEST REDIRECT TARGET. THIS IS THE SAME DEFECT AS THE `verified` ALIAS ABOVE, on the
        // authentication middleware instead of the verification one — and it was live in production
        // while the entire Pest suite was green.
        //
        // `ApplicationBuilder::withMiddleware()` installs `redirectGuestsTo(fn () => route('login'))`
        // BEFORE it calls this closure, unconditionally, for every application. No route here is named
        // `login` — ours is `auth.login` — and none ever will be, because this service serves no HTML
        // and the sign-in screen is a Next.js page on another host.
        //
        // Why it hid. `Authenticate::unauthenticated()` reads
        //     $request->expectsJson() ? null : $this->redirectTo($request)
        // so the callback is evaluated ONLY for a caller that does not accept JSON. The SPA always
        // sends `Accept: application/json`, and `tests/Support/spa.php`'s `spaHeaders()` — which 5A's
        // brief REQUIRES on every request, for an unrelated and correct reason — sends it too. So every
        // test took the `null` branch and every one of them passed, while a plain browser navigation to
        // `https://api.<domain>/api/v1/me` threw RouteNotFoundException from inside the middleware.
        //
        // MEASURED, on the deployed stack, before and after this line:
        //     with    `Accept: application/json` -> 401 authentication      (both)
        //     without that header                -> 500 internal_dependency (before) / 401 (after)
        // A 500 there is wrong three times over: it is a server-fault status for a client condition, it
        // claims `internal_dependency` when no dependency was involved, and `retryable: false` tells a
        // caller their credentials are irreparable when they merely need to sign in.
        //
        // `null` is the framework's own supported spelling — `redirectGuestsTo()` converts it to
        // `fn () => null` (Middleware.php:541) — so the exception carries no redirect and the render
        // closure produces one 401 `authentication` for every caller, whatever they accept. Do not
        // replace this with a route name to "make the redirect work": an HTML redirect from a JSON API
        // is how a fetch() ends up parsing a login page as a session payload.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nothing here may echo a provider credential, a signature, or a provider error body.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            // `credential` is the field name StoreProviderConnectionRequest posts the plaintext
            // provider key under. It is listed here beside the others rather than relying on the
            // request never failing: a ValidationException flashes the input, and a flashed
            // plaintext key is a key in the session store.
            'api_key', 'secret', 'provider_credential', 'credential',
        ]);

        /*
         * THE SEALED CREDENTIAL AND THE WRAPPED DATA KEY MUST NOT REACH THE LOG STORE.
         *
         * `BinaryCast::set()` hands PDO a `'\x'.bin2hex(...)` string because there is no way to
         * bind a `bytea`, and `QueryException::formatMessage()` interpolates EVERY binding into the
         * message it builds. So any database failure on a statement that writes a vault column —
         * a deadlock, a CHECK violation, a mid-statement reset — reports the tenant's key material
         * into Loki twice over, in a store nobody classifies as sensitive. `KbJsonFormatter::redact()`
         * cannot catch it: every rule in it recognises a credential by its own SHAPE, and hex has
         * none.
         *
         * REGISTERED HERE RATHER THAN AS A try/catch AT THE TWO WRITE SITES, because the property
         * is about the COLUMN and not about a call site: a KEK-rewrap command, an Eloquent backfill
         * or a second credential-bearing table reaches the same PDO path with none of a local
         * guard, and `AuditLogger` rethrows an ON_FAILURE_ABORT failure unwrapped so somebody
         * else's QueryException can arrive from inside the same transaction. This is the one funnel
         * every reported throwable passes through. VaultQueryScrubber carries the full argument and
         * the two detection tests.
         *
         * RETURNING `false` STOPS THE DEFAULT LOGGING STACK (Handler::reportThrowable — a report
         * callback returning false returns early). That is the entire point: the default stack is
         * what would log `$e->getMessage()`. A scrubbed ERROR is emitted here in its place, so the
         * failure is still loud, still carries `error_class`, and is still correlatable by
         * `request_id` — which KbJsonFormatter stamps on every line.
         *
         * NOTHING IS PASSED UNDER `exception`. Handing the Throwable to the formatter would render
         * its message again and undo the whole exercise; the SQLSTATE, the connection and the
         * PARAMETERIZED sql (placeholders, never values) are what an operator can act on.
         *
         * THE RENDERED RESPONSE IS UNAFFECTED. `report` and `render` are separate funnels: a
         * QueryException is not an HttpExceptionInterface, so the render closure still emits 500
         * with the fixed constant message.
         */
        $exceptions->report(function (\Illuminate\Database\QueryException $e): bool {
            if (! \App\Support\Crypto\VaultQueryScrubber::isVaultQuery($e)) {
                // Not our concern: fall through to the default stack, which logs it normally.
                return true;
            }

            \Illuminate\Support\Facades\Log::error(
                \App\Support\Crypto\VaultQueryScrubber::summarize($e),
                [
                    // From ErrorTaxonomy, so the existing error panels see this line. A database
                    // failure is a dependency of ours failing, whatever the statement was.
                    'error_class' => 'internal_dependency',
                    'dependency' => 'postgresql',
                    'outcome' => 'error',
                ],
            );

            return false;
        });

        // ONE render closure. Both the non-streaming body and the SSE `error` frame carry the same
        // four keys, so one parser serves every surface and `error_class` is the only field a client
        // branches on (kb-internal-api-contracts). Status comes from the CLASS, never from the
        // exception type — including the 403-admin / 404-public deny split.
        $exceptions->render(function (\Throwable $e, Request $request): ?JsonResponse {
            $isApiSurface = $request->is('api/*', 'rt/*', 'sdk/*', 'internal/*');

            if (! $isApiSurface && ! $request->expectsJson()) {
                return null;   // /up and /sanctum/csrf-cookie keep the framework's own rendering
            }

            // Public runtime and SDK are enumeration-sensitive: a 403 on a foreign identifier
            // confirms the row exists. Same error_class, different rendered status; nothing
            // branches on the status (kb-error-taxonomy, footnote 1).
            $isPublicSurface = $request->is('rt/*', 'sdk/*');

            $httpStatus = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            // ORIGIN — the second axis on `internal_dependency`, and on that row only (ADR-029,
            // finding O1). The taxonomy STAYS AT 18 CLASSES: this is a rendering input, not a
            // nineteenth member, and it is exactly the idiom `authorization` already uses with
            // $isPublicSurface above — one row whose status depends on a second input while the
            // class itself is unchanged. `origin` is NOT a wire field: it selects a status and a
            // `retryable` value, both of which the envelope already carries.
            //
            //   downstream  a real dependency of ours is briefly unavailable  -> 503, retryable
            //   self        an unmapped exception in OUR code — a defect      -> 500, NOT retryable
            //
            // `downstream` needs POSITIVE evidence that something downstream said it was
            // unavailable: a gateway-family status on an HttpException we raised on a dependency's
            // behalf. Everything else landing on this row — a Throwable with no status of its own,
            // an explicit abort(500), an unclassified 4xx — is ours. A bug is not a brownout: no
            // number of attempts fixes it, and a 503/retryable=true tells the client to hammer a
            // guaranteed failure down a full backoff ladder.
            //
            // services/ai-service/app/core/errors.py carries the same table (Origin, status_for,
            // retryable_for) and app/main.py::_handle_unexpected raises with origin=SELF, so both
            // planes render an unhandled internal exception identically: 500 /
            // error_class=internal_dependency / retryable=false / the same message string. A
            // consumer cannot tell which plane produced an envelope, so any divergence is a bug.
            //
            // A KbException carries its own origin because it KNOWS which of the two it is —
            // `aiServiceUnavailable()` is positive evidence that a dependency said it was
            // unavailable, and `validation()` is a configuration state of ours. Deriving it from
            // the status here would re-guess a fact the raiser already established.
            $origin = $e instanceof \App\Exceptions\KbException
                ? $e->origin
                : ($httpStatus >= 502 ? ErrorTaxonomy::ORIGIN_DOWNSTREAM : ErrorTaxonomy::ORIGIN_SELF);

            [$errorClass, $status] = match (true) {
                // FIRST, AND THAT ORDER IS THE RULE. A KbException already knows its class —
                // either because we assigned one, or because FastAPI did and we are relaying it
                // verbatim. Every branch below this one DERIVES a class from a status, which is
                // exactly what must not happen to a relayed error: a `provider_temporary` arriving
                // as 503 would come back out as `internal_dependency`, and the client's retry
                // decision would be made against a class the data plane never assigned
                // (laravel-control-plane, kb-error-taxonomy).
                $e instanceof \App\Exceptions\KbException => [$e->errorClass, $e->status],

                $e instanceof ValidationException => ['validation', 422],
                $e instanceof AuthenticationException => ['authentication', 401],
                $e instanceof AuthorizationException => ['authorization', $isPublicSurface ? 404 : 403],
                $httpStatus === 401, $httpStatus === 419 => ['authentication', 401],
                $httpStatus === 403 => ['authorization', $isPublicSurface ? 404 : 403],
                $httpStatus === 404, $httpStatus === 405 => ['authorization', 404],
                $httpStatus === 422 => ['validation', 422],
                $httpStatus === 429 => ['rate_limit', 429],
                // The one row with two renderings. Mirrors status_for(): the 500/503 split and the
                // retryable split below are the SAME decision, so both read $origin.
                $httpStatus >= 500 => [
                    'internal_dependency',
                    $origin === ErrorTaxonomy::ORIGIN_SELF ? 500 : 503,
                ],
                // AN UNCLASSIFIED 4xx KEEPS ITS OWN STATUS. THIS ARM IS THE ONLY STATEMENT OF THAT
                // RULE ANYWHERE IN THE TREE, WHICH IS WHY IT IS WRITTEN OUT HERE.
                //
                // `kb-error-taxonomy`'s table gives `internal_dependency` as **503**, and that is
                // the status the class MINTS — what Laravel renders when it decides, on its own,
                // that a dependency is unavailable. It is not a promise that every response
                // carrying this class is a 503. An exception that already arrived with a status of
                // its own keeps it: `$httpStatus` above came from `HttpExceptionInterface::
                // getStatusCode()`, and a 409, 410, 415 or 428 raised deliberately by our code is a
                // fact about the request, not a guess this closure should overwrite.
                //
                // The worked case, pinned by tests/Feature/ErrorEnvelopeTest.php:157 ("does not
                // promote an unclassified 4xx to retryable"): a ConflictHttpException renders
                // **409 / internal_dependency / retryable=false**. Two things make that correct
                // rather than a gap:
                //
                //   * the STATUS is preserved because rewriting a 409 to 503 would tell the client
                //     the server is temporarily unwell when the truth is that its request conflicts
                //     with current state — the retry would fail identically forever;
                //   * `retryable` is false because $origin is SELF (nothing downstream declared
                //     itself unavailable; see the $origin assignment above), and retryable is read
                //     from the CLASS PLUS ORIGIN, never from the rendered status.
                //
                // So the taxonomy stays at 18 classes and gains no 409 row: 409 is a RENDERING of
                // an existing row, exactly as 404-on-public is a rendering of `authorization`.
                // services/ai-service/tests/unit/test_request_context_and_deadline.py:321
                // (`test_the_taxonomy_still_has_no_row_that_renders_409`) is a live tripwire on
                // that: it asserts no ErrorClass MINTS a 409 via status_for(), and it is the prompt
                // to implement a deferred contract-version comparison the day one does. This arm
                // does not disturb it — nothing here mints a status, it relays one — and adding a
                // 409 row to the table to describe this behaviour would fire that tripwire for the
                // wrong reason and put a third spelling of one rule in the tree. If a 4xx here ever
                // needs its own class, that is an ADR, not an extra `match` arm.
                default => ['internal_dependency', $httpStatus],
            };

            // Retryability is a property of the CLASS, never of the rendered status. Deriving it
            // from $status instead (`$status === 503`) is how the two planes drifted apart in the
            // first place: change the downstream rendering and the retry verdict follows silently,
            // with nothing saying a client was just told to retry a defect.
            //
            // The table itself lives in App\Support\Kb\ErrorTaxonomy, not as a literal here, and
            // that is not tidying: the literal was a hand-transcription of Python's RETRYABLE with
            // NOTHING comparing the two, which is finding O1's exact shape waiting to recur on
            // another row. tests/Contract/ErrorTaxonomyParityTest.php now reads errors.py as data
            // and fails on any disagreement — it can only do that against a named table.
            //
            // ErrorTaxonomy::retryable() mirrors retryable_for(): the row, then the ADR-029
            // override. It reads as "a self-origin internal_dependency is not retryable" because
            // that is literally the only branch in it.
            $retryable = ErrorTaxonomy::retryable($errorClass, $origin);

            $payload = [
                'error_class' => $errorClass,
                // Operator-facing and safe to log; never rendered verbatim to an end user, and never
                // a provider message — provider error bodies echo the request (kb-error-taxonomy).
                // The 5xx string is byte-identical to the one _handle_unexpected() uses in
                // services/ai-service/app/main.py, on purpose (ADR-029).
                'message' => match (true) {
                    $status >= 500 => 'The service could not complete this request.',

                    // THE ENUMERATION ORACLE IS CLOSED AT THE BODY, NOT ONLY AT THE STATUS.
                    //
                    // Rendering `authorization` as 404 on the public surfaces is worthless if the
                    // body then says which 404 it is, because an attacker reads the body. Before
                    // this line a denied bot returned 404 with "This action is unauthorized." and
                    // an id that never existed returned 404 with "" — two statuses the same, two
                    // bodies trivially distinguishable, and the whole point of the split gone one
                    // layer down. A 405 was worse: Symfony's message names the route and the
                    // methods it allows, so the probe learned the URI existed AND its verb list.
                    //
                    // One constant per rendered status, so every deny of a given status is
                    // byte-identical: a denied record, a record in another organization, a record
                    // that never existed, and a URI with no route are one response. Asserted by
                    // `toDenyAsNotFound()` in tests/Pest.php, which does not compare against a
                    // copy of these strings — it fires a REAL request at a path that certainly has
                    // no route and requires the two bodies to match byte for byte.
                    //
                    // The admin 403 gets the same treatment even though admin deliberately admits
                    // existence: a policy's free-form message is not something any client branches
                    // on (`error_class` is), and one rule is one thing to get right.
                    $errorClass === 'authorization' => $status === 404
                        ? 'The requested resource was not found.'
                        : 'This action is not permitted.',

                    default => $e->getMessage(),
                },
                'retryable' => $retryable,
                // Echoes X-KB-Request-Id so one failure is greppable across both services.
                'request_id' => (string) ($request->headers->get('X-KB-Request-Id') ?: Str::ulid()),
            ];

            // ── `actionable`: IS THE MESSAGE ABOVE ADDRESSED TO A PERSON? ──────────────────────
            //
            // WHAT IT ANSWERS. True when `message` was written for THIS condition and names
            // something about this request; false when it is a fixed placeholder chosen to say
            // nothing. It is NOT a status and nothing may infer one from it — `error_class` and
            // `retryable` keep every job they have, and a client that branches on a status is the
            // coupling the 18-class taxonomy exists to remove.
            //
            // WHY IT EXISTS (finding J2). The taxonomy has no 409 row on purpose: a deliberate 4xx
            // our own code raised renders as `internal_dependency` with the status preserved, and
            // an unhandled exception renders as `internal_dependency` too. So a 409 that says
            // "clear the designation first, then delete" and a 500 that says nothing arrive at the
            // browser as the SAME (error_class, retryable) pair. apps/web told them apart by
            // comparing `message` against a client-side copy of the 5xx constant below — a
            // deny-by-exclusion filter whose premise was a property of the WHOLE TREE: the first
            // `abort(400, $detail)` reachable from those screens broke it silently, and in the
            // worse direction (a defect whose message happened to differ would read as advice).
            //
            // DERIVED FROM THE SAME CONDITIONS AS THE `match` ABOVE, ARM FOR ARM, so the two cannot
            // drift. Each conjunct names the arm it mirrors:
            //
            //   $status < 500                  the >=500 arm replaced the message with a constant
            //   not `authorization`            both authorization arms are enumeration-oracle
            //                                  constants; a sentence there would REOPEN the oracle
            //   not a ValidationException      Laravel's own summary string is generic; the payload
            //                                  a client acts on is the `errors` map beside it. Note
            //                                  this is deliberately narrower than "not validation":
            //                                  KbException::validation() carries the ADR-031
            //                                  resolver refusal, a full paragraph written for the
            //                                  operator that apps/web renders verbatim.
            //   the KbException's own verdict  a RELAYED envelope's verdict wins, exactly as
            //                                  $origin does above. Recomputing it here would be
            //                                  ADR-052 recurring on a fourth field.
            //   a non-empty message            "" is not advice however it got here.
            //
            // services/ai-service/app/main.py carries the mirror: `_handle_unexpected` emits false,
            // `_handle_validation_error` emits false, and every other KbError emits its own flag.
            // A consumer cannot tell which plane produced an envelope, so a divergence is a bug.
            $payload['actionable'] = $status < 500
                && $errorClass !== 'authorization'
                && ! $e instanceof ValidationException
                && (! $e instanceof \App\Exceptions\KbException || $e->actionable)
                && $payload['message'] !== '';

            // `errors` is a SUPERSET present only on `validation` — never null, never {} elsewhere.
            //
            // TWO PRODUCERS, AND THE SECOND ONE WAS MISSING. A FormRequest produces the map from
            // its own rules; the DATA PLANE produces one too — `_handle_validation_error` in
            // services/ai-service/app/main.py builds a real `dict[str, list[str]]` out of Pydantic's
            // `loc` paths on EVERY validation envelope. While this branch tested only
            // `instanceof ValidationException`, a relayed 422 reached the browser as `validation`
            // with NO map, and a form had nothing to key its per-field errors on.
            //
            // The condition is `error_class === 'validation'` and NOT `$e instanceof KbException`,
            // because the field the contract keys the superset on is the class, and a KbException
            // carrying any other class must not grow one.
            //
            // AN EMPTY MAP IS NOT FORWARDED. The contract is "present only on `validation`, and only
            // when a producer made one — never null, never {}", so the relay normalizes a malformed
            // or empty map to null (InternalAiClient::fieldErrors) and this omits the key entirely.
            // That absence is itself an invariant a client tests: apps/web discriminates the ADR-031
            // resolver refusal on `validation` WITH NO MAP, which is only sound while every 422 that
            // HAS per-field detail carries it.
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            } elseif (
                $errorClass === 'validation'
                && $e instanceof \App\Exceptions\KbException
                && $e->errors !== null
                && $e->errors !== []
            ) {
                $payload['errors'] = $e->errors;
            }

            $headers = [];

            if ($errorClass === 'rate_limit' && $e instanceof HttpExceptionInterface) {
                $headers = array_intersect_key($e->getHeaders(), ['Retry-After' => true]);
            }

            return new JsonResponse($payload, $status, $headers);
        });
    })
    ->create();

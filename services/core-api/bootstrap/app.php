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
            // FOUR DISJOINT GROUPS. There is deliberately no routes/api.php: one shared api file is
            // exactly how a public runtime route ends up inheriting the admin group's session,
            // CSRF and cookie stack (laravel-sanctum-auth NN2, laravel-control-plane DoD).
            //
            // Only the admin group uses the `api` middleware group, because statefulApi() prepends
            // EnsureFrontendRequestsAreStateful to `api` — and the public runtime, the SDK, and the
            // internal surface must never acquire ambient session authority from a stateful Origin.

            // 1. Admin API — Sanctum SPA cookie session + org membership. Surface: admin (403 deny).
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('admin.')
                ->group(base_path('routes/api_admin.php'));

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

        // Sanctum SPA cookie auth for the admin API. Prepends EnsureFrontendRequestsAreStateful to
        // the `api` group only — which is why the other three groups are defined separately below.
        $middleware->statefulApi();

        // The three non-admin groups. Each is defined explicitly rather than reusing `api`, so that
        // a route-list test can assert no session middleware reaches a token-authenticated surface.
        // SubstituteBindings is present in all of them because route-model binding is what turns a
        // foreign identifier into a 404 at binding time (laravel-rbac-policies).
        $middleware->group('runtime', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // TODO(auth): App\Http\Middleware\ResolveChatSession + BindSurface(public) — laravel-sanctum-auth.
            // TODO(tenancy): App\Http\Middleware\TenantContext — see the priority list below.
        ]);

        $middleware->group('sdk', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // TODO(auth): App\Http\Middleware\ValidateEmbedOrigin + BindSurface(public).
        ]);

        $middleware->group('internal', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // TODO(contracts): App\Http\Middleware\VerifyInternalSignature — HMAC + timestamp skew
            // + X-KB-Request-Id replay nonce (kb-internal-api-contracts).
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
        $middleware->alias([
            'org.member' => \App\Http\Middleware\TenantContext::class,
            'surface' => \App\Http\Middleware\BindSurface::class,
        ]);
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

            // `errors` is a SUPERSET present only on `validation` — never null, never {} elsewhere.
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            $headers = [];

            if ($errorClass === 'rate_limit' && $e instanceof HttpExceptionInterface) {
                $headers = array_intersect_key($e->getHeaders(), ['Retry-After' => true]);
            }

            return new JsonResponse($payload, $status, $headers);
        });
    })
    ->create();

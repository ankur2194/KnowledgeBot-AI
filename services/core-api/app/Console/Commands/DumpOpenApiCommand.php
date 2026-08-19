<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Contracts\ResponseShape;
use App\Support\Kb\ErrorTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Dump the public control-plane API as an OpenAPI 3.1 document into packages/contracts/openapi/.
 *
 * WHY THIS EXISTS. packages/contracts/src/resources/ is a GENERATED directory: the TypeScript
 * types for BotResource, SourceResource and friends are produced from this document, never
 * hand-written, because a hand-written fixture encodes what its author believed the wire says and
 * a server-side rename then ships instead of failing a typecheck. Nothing could be generated while
 * packages/contracts/openapi/ held only a .gitkeep, so admin-web-engineer was carrying a
 * structural placeholder. This command is what removes it.
 *
 * NOTHING HERE IS TRANSCRIBED, and each half comes from a different piece of executing code:
 *
 *   paths, methods, path parameters   the real router — Router::getRoutes()
 *   security                          the route's own middleware stack, and the session cookie name
 *                                     from config, not a literal. Emitted on EVERY operation,
 *                                     including as `[]` for a guest route, so "no credential
 *                                     required" is something this document SAYS rather than
 *                                     something a reader infers from a missing key
 *   response ENVELOPES                the #[ResponseShape] attribute on the controller action, read
 *                                     by reflection. Declared and not inferred because two actions
 *                                     return JsonResponse and one returns a Resource that Laravel
 *                                     wraps — see the attribute class for why a return type cannot
 *                                     answer this
 *   response FIELDS                   each Resource's own openApiSchemas(), which
 *                                     tests/Contract/OpenApiDocumentTest.php asserts against what
 *                                     toArray() actually emits
 *   error_class enum                  array_keys(ErrorTaxonomy::RETRYABLE) — the same table the
 *                                     render closure and the parity test read
 *   enum members                      the PHP backed enums themselves, via ::values()
 *
 * WHAT IS DELIBERATELY ABSENT: request body schemas. docs/22 finding 19 rules that the FormRequest
 * is the only source of a request rule, and that the drift manifest is dumped from executing
 * `rules()` rather than from an OpenAPI document — precisely so nobody diffs two documents while
 * the enforcing code walks away from both. A requestBody schema here would be that third copy. Each
 * operation instead carries `x-kb-request-rules`, pointing at the manifest `kb:dump-form-rules`
 * produces, which is where a client-side schema is drift-checked against
 * (packages/contracts/test/form-drift.test.ts).
 *
 * DETERMINISM, for the same reason as kb:dump-form-rules: keys are sorted, JSON flags are pinned,
 * and `--check` exits non-zero if the file would change. A gate that flaps teaches people to re-run
 * CI instead of reading it.
 *
 * The class carries the `Command` suffix because arch()->preset()->laravel() asserts it.
 */
final class DumpOpenApiCommand extends Command
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * The document version. Bumped by hand, in the same commit as the change it describes — it is
     * the one field no piece of executing code can supply.
     */
    private const DOCUMENT_VERSION = '0.1.0';

    /**
     * URI prefixes that belong to a CLIENT. `internal/` is excluded on purpose: that seam is
     * FastAPI's own exported document and lives beside this one, and publishing our view of it
     * would create two descriptions of one wire. `up`, `sanctum/csrf-cookie` and anything else on
     * the `web` group are excluded because they carry no JSON body a type could be generated from.
     */
    private const SURFACES = ['api/', 'rt/', 'sdk/'];

    protected $signature = 'kb:dump-openapi
                            {--path= : Output file (defaults to <repo>/packages/contracts/openapi/core-api.openapi.json)}
                            {--check : Do not write; exit non-zero if the document would change}';

    protected $description = 'Dump the public control-plane API to packages/contracts/openapi as OpenAPI 3.1.';

    public function handle(Router $router): int
    {
        $target = (string) ($this->option('path') ?? '') ?: dirname(base_path(), 2).'/packages/contracts/openapi/core-api.openapi.json';

        try {
            $json = json_encode($this->document($router), self::JSON_FLAGS)."\n";
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('check') === true) {
            if (is_file($target) && file_get_contents($target) === $json) {
                return self::SUCCESS;
            }

            $this->components->error('The OpenAPI document is out of date. Run: php artisan kb:dump-openapi');
            $this->line('  '.$target);

            return self::FAILURE;
        }

        if (! is_dir($dir = dirname($target)) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            $this->components->error("Could not create {$dir}");

            return self::FAILURE;
        }

        file_put_contents($target, $json);

        $this->components->info("Wrote {$target}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Router $router): array
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = [];
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = $this->envelopeSchemas();

        foreach ($this->clientRoutes($router) as $route) {
            $shape = $this->responseShapeFor($route);

            foreach ($shape->properties as $resource) {
                foreach ($resource::openApiSchemas() as $name => $schema) {
                    if (array_key_exists($name, $schemas) && $schemas[$name] !== $schema) {
                        // Two resources contributing DIFFERENT bodies under one component name
                        // would silently overwrite each other and publish whichever was reflected
                        // last. Fail instead: the collision is the finding.
                        throw new RuntimeException("Component schema '{$name}' is declared twice with different bodies.");
                    }

                    $schemas[$name] = $schema;
                }
            }

            $operation = $this->operation($route, $shape);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;   // Laravel registers HEAD alongside every GET; it is not an operation
                }

                $paths['/'.ltrim($route->uri(), '/')][strtolower($method)] = $operation;
            }
        }

        foreach ($paths as &$operations) {
            ksort($operations, SORT_STRING);
        }

        unset($operations);

        ksort($paths, SORT_STRING);
        ksort($schemas, SORT_STRING);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'KnowledgeBot AI — control plane',
                'version' => self::DOCUMENT_VERSION,
                'description' => implode("\n", [
                    'GENERATED BY `php artisan kb:dump-openapi`. Never hand-edited: every path comes',
                    'from the router, every response field from an API Resource\'s own schema',
                    'declaration, and every enum from the PHP backed enum it mirrors. Edit the code',
                    'and re-run; a hand-edit is reverted by the next CI run.',
                    '',
                    'REQUEST BODIES ARE DELIBERATELY NOT DESCRIBED HERE. The FormRequest is the only',
                    'source of a request rule (docs/22 finding 19), and its rules are dumped from',
                    'executing `rules()` into packages/contracts/rules/ by `kb:dump-form-rules`.',
                    'Each operation carries `x-kb-request-rules` pointing at its manifest.',
                    '',
                    'Clients never reach the FastAPI data plane. Every path below is Laravel.',
                ]),
            ],
            'servers' => [[
                'url' => '/',
                'description' => 'Same origin as the admin console. Paths are absolute from the root.',
            ]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->securitySchemes(),
                'schemas' => $schemas,
            ],
        ];
    }

    /**
     * @return list<Route>
     */
    private function clientRoutes(Router $router): array
    {
        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');

            foreach (self::SURFACES as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    $routes[] = $route;

                    break;
                }
            }
        }

        usort($routes, static fn (Route $a, Route $b): int => [$a->uri(), $a->methods()] <=> [$b->uri(), $b->methods()]);

        return $routes;
    }

    /**
     * A client-facing route with no declared response shape is a route no client can be generated
     * for. That is a finding, not something to skip: skipping it publishes a document that is
     * quietly missing an endpoint, and nothing downstream can tell the difference between "absent"
     * and "does not exist".
     */
    private function responseShapeFor(Route $route): ResponseShape
    {
        $controller = $route->getControllerClass();
        $method = $this->actionMethod($route);
        $label = $route->methods()[0].' /'.ltrim($route->uri(), '/');

        if ($controller === null || $method === '' || ! method_exists($controller, $method)) {
            throw new RuntimeException("{$label} is not a controller action; every client route must be one.");
        }

        $attributes = (new ReflectionMethod($controller, $method))->getAttributes(ResponseShape::class);

        if ($attributes === []) {
            throw new RuntimeException(
                "{$label} ({$controller}::{$method}) carries no #[ResponseShape]; it cannot be published.",
            );
        }

        $shape = $attributes[0]->newInstance();

        // A BODYLESS SUCCESS IS REFUSED HERE RATHER THAN PUBLISHED, and refusing is the decision.
        //
        // operation() composes one object from $shape->properties, so an empty attribute emits
        // `"properties": []` — a JSON ARRAY where JSON Schema requires an object — and
        // `"required": []` beside it. The document would be INVALID rather than merely terse, and
        // the person who found out would be whoever ran a generator against it, days later, with
        // nothing pointing back at the action that caused it. D8 ("no bodyless success anywhere")
        // had avoided that by convention alone: the first 204 anybody wrote got the invalid
        // document, and the convention lived in a resource docblock rather than in executing code.
        //
        // The alternative was to emit something valid — `properties: (object) []`, or omit
        // `content` entirely, which is the correct OpenAPI spelling for a 204. Rejected, because it
        // makes the dumper silently ACCEPT the shape the convention forbids, and the convention is
        // not stylistic: every success body on this surface is `{"data": …}` and the SPA unwraps it
        // once at the fetch boundary (D23, `browserFetch<{data: T}>` then `.data`), so a 204 hands
        // that boundary `undefined` at a call site with no branch for it. A dump that fails NAMING
        // THE ACTION teaches the rule at the moment it is broken, which is the only moment anybody
        // reads it. AcknowledgementResource exists precisely so the fix is one line at the call
        // site. If a bodyless success is ever genuinely wanted, this guard is what has to be
        // deleted deliberately, together with the `content` omission and the client's unwrap.
        if ($shape->properties === []) {
            throw new RuntimeException(
                "{$label} ({$controller}::{$method}) declares #[ResponseShape] with no properties. A "
                .'bodyless success would publish `"properties": []`, which is not a JSON Schema '
                .'object. Every success body on this surface carries a `data` key (D8) — return '
                .'AcknowledgementResource::ok() rather than a 204.',
            );
        }

        foreach ($shape->properties as $key => $resource) {
            if (! is_subclass_of($resource, ProvidesOpenApiSchema::class)) {
                throw new RuntimeException(
                    "{$label} declares '{$key}' => {$resource}, which does not implement ProvidesOpenApiSchema.",
                );
            }

            $short = (new ReflectionClass($resource))->getShortName();

            if (! array_key_exists($short, $resource::openApiSchemas())) {
                throw new RuntimeException("{$resource}::openApiSchemas() does not declare its own '{$short}' component.");
            }
        }

        return $shape;
    }

    /**
     * The controller method a route dispatches to.
     *
     * NOT `Route::getActionMethod()`, which is wrong for a single-action controller. That method is
     * `Arr::last(explode('@', $this->getActionName()))`, and `getActionName()` reads the `controller`
     * key — which Router::convertToControllerAction() writes BEFORE RouteAction::makeInvokable()
     * appends `@__invoke` to `uses`. So `Route::post($uri, SomeController::class)` answers with the
     * CLASS NAME as its "method", `method_exists()` then fails, and the dump aborted with
     * "is not a controller action", taking the whole document with it — for a route the router
     * itself dispatches perfectly well.
     *
     * `uses` is the value the framework actually dispatches, and Str::parseCallback's default is the
     * same `__invoke` the router falls back to, so this agrees with the dispatcher by construction
     * rather than by imitation. A Closure route has no string `uses`; it falls through to the
     * framework's answer, which is `Closure`, which responseShapeFor() then rejects as before.
     */
    private function actionMethod(Route $route): string
    {
        $uses = $route->getAction('uses');

        if (! is_string($uses)) {
            return $route->getActionMethod();
        }

        return (string) (Str::parseCallback($uses, '__invoke')[1] ?? '__invoke');
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, ResponseShape $shape): array
    {
        $properties = [];

        foreach ($shape->properties as $key => $resource) {
            $properties[$key] = [
                '$ref' => '#/components/schemas/'.(new ReflectionClass($resource))->getShortName(),
            ];
        }

        ksort($properties, SORT_STRING);

        $responses = [
            (string) $shape->status => [
                'description' => $shape->description !== '' ? $shape->description : 'Success.',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_keys($properties),
                    'properties' => $properties,
                ]]],
            ],
        ];

        foreach ($shape->errors as $status) {
            $responses[(string) $status] = $this->errorResponse($status);
        }

        ksort($responses, SORT_STRING);

        $operation = [
            'operationId' => (string) $route->getName(),
            'parameters' => $this->parameters($route),
            // ALWAYS PRESENT, including when it is empty — see securityFor(). An omitted `security`
            // resolves to the document-level one, and with none declared that reads as "no
            // authentication required" — the same answer as `[]`, arrived at by silence. Silence
            // cannot be told apart from a generator that never asked the question.
            'security' => $this->securityFor($route),
            'responses' => $responses,
        ];

        $manifest = $this->requestRulesManifest($route);

        if ($manifest !== null) {
            // A pointer, never a copy. The manifest is dumped from executing rules() and is the
            // only place a request rule is defined.
            $operation['x-kb-request-rules'] = $manifest;
        }

        ksort($operation, SORT_STRING);

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'description' => $name === 'organization'
                    // Worth spelling out: this is the tenant scope, and it is resolved by a scoped
                    // route-model binding plus a membership re-read, so a foreign value is denied
                    // before any handler runs.
                    ? 'ULID of the organization. The tenant scope for everything below this path; a '
                        .'value the caller is not a current member of is denied, never served.'
                    : 'ULID of the bound {'.$name.'} record, scoped to the organization above it.',
            ];
        }

        return $parameters;
    }

    /**
     * The FormRequest manifest an operation's body is validated by, as a repo-relative path — or
     * null when the action takes no FormRequest.
     */
    private function requestRulesManifest(Route $route): ?string
    {
        $controller = $route->getControllerClass();
        $method = $this->actionMethod($route);

        if ($controller === null || ! method_exists($controller, $method)) {
            return null;
        }

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, \Illuminate\Foundation\Http\FormRequest::class)) {
                return '../rules/'.(new ReflectionClass($class))->getShortName().'.json';
            }
        }

        return null;
    }

    /**
     * Which credential an operation requires, derived from the route's OWN middleware stack.
     *
     * DERIVED AND NOT DECLARED, deliberately. An argument on #[ResponseShape] would be a second
     * statement of "does this route need a session", and the middleware is the half that actually
     * enforces it — so the two could disagree and the document would publish whichever the author
     * typed. `auth:sanctum` is the single mechanism on this surface (D10/D11: the bearer branch is
     * rejected by RejectBearerToken and nothing mints a token), so the presence of that one string
     * IS the answer, and a route that loses the middleware loses the requirement in the document on
     * the same commit.
     *
     * AN EMPTY REQUIREMENT IS EMITTED RATHER THAN OMITTED. Eleven auth routes split six guest / five
     * authenticated (routes/api_auth.php, GROUP A and GROUP B), and before this the split reached
     * the document only as the absence of a key — indistinguishable from a generator that had never
     * considered it, and unassertable. `security: []` is this service SAYING the route is
     * guest-reachable; tests/Contract/OpenApiDocumentTest.php pins that set by name, so silently
     * dropping `auth:sanctum` from GET /api/v1/me fails a test that names the operation.
     *
     * TWO REFUSALS, both guarding the one direction of this field that is dangerous — publishing
     * "no authentication required" for a route that has some:
     *
     *   1. An auth-family middleware that is NOT `auth:sanctum` (`auth`, `auth:web`, `auth.basic`,
     *      `auth.session`). Answering `[]` there would under-claim, and answering `sanctumSession`
     *      would be a lie about which credential. It is also a second mechanism on a surface that
     *      has exactly one, which is what D10/D11 exist to prevent, so the dump is the right place
     *      to find out.
     *   2. A surface with no mechanism described at all. SURFACES carries `rt/` and `sdk/`, whose
     *      route files are empty today; their credentials are a resolved chat session and a
     *      validated embed origin, both still TODOs in bootstrap/app.php's `runtime` and `sdk`
     *      groups. `[]` for the first route added there would publish a public endpoint. Whoever
     *      adds it teaches this method the mechanism and declares the scheme in securitySchemes();
     *      until then the dump fails naming the route.
     *
     * @return list<array<string, list<string>>>
     */
    private function securityFor(Route $route): array
    {
        $uri = ltrim($route->uri(), '/');
        $label = $route->methods()[0].' /'.$uri;

        if (! str_starts_with($uri, 'api/')) {
            throw new RuntimeException(
                "{$label} is on a surface this command declares no security scheme for. Teach "
                .'DumpOpenApiCommand::securityFor() and securitySchemes() how its caller '
                .'authenticates; publishing it with an empty requirement would describe it as '
                .'public.',
            );
        }

        $authenticators = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            // `auth`, `auth:<guard>`, `auth.basic`, `auth.session` — and nothing that merely starts
            // with those letters, which is why the boundary is anchored rather than a prefix match.
            if (is_string($middleware) && preg_match('/^auth($|[:.])/', $middleware) === 1) {
                $authenticators[] = $middleware;
            }
        }

        if ($authenticators === ['auth:sanctum']) {
            return [['sanctumSession' => []]];
        }

        if ($authenticators !== []) {
            throw new RuntimeException(
                "{$label} authenticates with ".implode(', ', $authenticators).', which this command '
                .'cannot describe. The admin surface has exactly one mechanism, the Sanctum SPA '
                .'session (D10/D11); a second one is a finding, not a document change.',
            );
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function securitySchemes(): array
    {
        $cookie = config('session.cookie');

        return [
            'sanctumSession' => [
                'type' => 'apiKey',
                'in' => 'cookie',
                'name' => is_string($cookie) ? $cookie : 'session',
                'description' => 'The Sanctum SPA session cookie, issued by GET /sanctum/csrf-cookie '
                    .'and sent by the browser. NOT a bearer token: a bearer token in a browser SPA '
                    .'must live where JavaScript can read it, so one XSS becomes a stolen, '
                    .'long-lived, replayable credential. Requests also carry the XSRF header the '
                    .'same endpoint sets.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(int $status): array
    {
        $schema = $status === 422
            ? ['$ref' => '#/components/schemas/ValidationErrorEnvelope']
            : ['$ref' => '#/components/schemas/ErrorEnvelope'];

        $response = [
            'description' => match ($status) {
                401 => 'Not authenticated. `error_class` is `authentication`.',
                403 => 'Denied. `error_class` is `authorization`. The admin surface answers 403 by '
                    .'design — a member is already entitled to know the record exists. Public and '
                    .'SDK surfaces render the same class as 404.',
                409 => 'The organization is not active. Its configuration may be read but not '
                    .'changed.',
                422 => 'The request was rejected. `error_class` is `validation`; `errors` is present '
                    .'only when a FormRequest produced per-field messages, and absent when the '
                    .'refusal came from the data plane\'s resolver.',
                429 => 'Rate limited. `error_class` is `rate_limit`; wait for `Retry-After`, which '
                    .'is a floor and not a hint.',
                500 => 'An unmapped exception in this service. `error_class` is '
                    .'`internal_dependency` and `retryable` is FALSE — a bug is not a brownout '
                    .'(ADR-029).',
                503 => 'A dependency was briefly unavailable, or the data plane assigned its own '
                    .'class. `retryable` is the authority, never the status.',
                default => 'Error.',
            },
            'content' => ['application/json' => ['schema' => $schema]],
        ];

        if ($status === 429) {
            $response['headers'] = ['Retry-After' => [
                'description' => 'Seconds to wait. A floor, not a hint: retrying inside the window '
                    .'keeps consuming the rejection.',
                'schema' => ['type' => 'integer'],
            ]];
        }

        return $response;
    }

    /**
     * The one error envelope both planes emit. The `error_class` enum is generated from
     * ErrorTaxonomy::RETRYABLE, which tests/Contract/ErrorTaxonomyParityTest.php already diffs
     * against services/ai-service/app/core/errors.py — so the published set cannot drift from the
     * data plane's without that test failing first.
     *
     * WHY `errors` IS DECLARED ON THE BASE ENVELOPE AND NOT ONLY ON THE VALIDATION ONE.
     * `additionalProperties: false` in an `allOf` branch is evaluated against THAT BRANCH'S
     * `properties` alone — it cannot see a sibling branch's. So while `errors` lived only in the
     * second branch of ValidationErrorEnvelope, the first branch rejected it as an unexpected
     * property and NO REAL 422 BODY VALIDATED AGAINST THE SCHEMA THAT DESCRIBES IT. Both planes
     * emit that key (bootstrap/app.php's render closure; services/ai-service/app/main.py), so the
     * document was unsatisfiable rather than merely strict.
     *
     * Of the two available fixes, this is the one that loses nothing. Dropping
     * `additionalProperties: false` would have to drop it from THIS schema — the composed branch —
     * and that opens all six non-422 error responses as well, to fix one; and closedness is
     * precisely what makes a generated type fail to compile on a server-side rename instead of
     * silently gaining an optional field (the reason OpenApiDocumentTest asserts it on the
     * resource). Declaring `errors` here instead matches packages/contracts/src/envelope.ts, where
     * `KbErrorEnvelope.errors?` is optional on the base interface and `KbValidationEnvelope`
     * narrows it to required — so the generated document and the hand-maintained TypeScript
     * describe one shape, which is the entire point of publishing this artifact.
     *
     * `errors` stays OUT of `required` on both schemas: it is a superset present on `validation`
     * alone — never null, never `{}` on another class — and a `validation` refusal relayed from the
     * data plane's resolver carries no map at all.
     *
     * @return array<string, array<string, mixed>>
     */
    private function envelopeSchemas(): array
    {
        $classes = array_keys(ErrorTaxonomy::RETRYABLE);
        sort($classes, SORT_STRING);

        return [
            'ErrorEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The single error envelope, as it appears in a NON-STREAMING '
                    .'response body. Identical across both services — a consumer cannot tell which '
                    .'plane produced one, so any divergence between them is a bug. The SSE `error` '
                    .'frame carries the same fields with ONE documented difference: it omits '
                    .'`request_id`, which this schema requires, so a client cannot validate a frame '
                    .'against this component unmodified. That is why '
                    .'packages/contracts/src/envelope.ts types `request_id` as OPTIONAL while this '
                    .'schema requires it — the TypeScript interface is the union of both surfaces '
                    .'(src/sse/events.ts reuses it for the `error` frame) and this component is the '
                    .'HTTP one; the widening is deliberate and is the safe direction. '
                    .'packages/contracts/src/errors.ts is the hand-maintained TypeScript carrier '
                    .'(class KbError, declared exactly once); do not generate a second copy of it '
                    .'from this component.',
                'required' => ['error_class', 'message', 'retryable', 'request_id', 'actionable'],
                'properties' => [
                    'error_class' => [
                        'type' => 'string',
                        'enum' => $classes,
                        'description' => 'The closed 18-member taxonomy, and the only field to '
                            .'branch on. `internal_dependency` has two renderings — 503/retryable '
                            .'for a real dependency, 500/not-retryable for our own unmapped '
                            .'exception — so a retry predicate reading this field ALONE is wrong '
                            .'for that row. Read `retryable`.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Operator-facing. It can carry an internal hostname or raw '
                            .'upstream provider text: log it, never render it to an end user. Show a '
                            .'class-mapped sentence plus `request_id` instead.',
                    ],
                    'retryable' => [
                        'type' => 'boolean',
                        'description' => 'THE AUTHORITY on whether the caller may try again. Never '
                            .'re-derive it from `error_class` or from the status.',
                    ],
                    'actionable' => [
                        // REQUIRED HERE, OPTIONAL IN TYPESCRIPT — the same asymmetry `request_id`
                        // carries one property up, and for the same reason: the SSE `error` frame
                        // does not carry this field, and packages/contracts/src/envelope.ts is the
                        // union of the frame and the body. Absent is read as `false` on the client,
                        // which is the fail-closed direction (a class-mapped sentence instead of a
                        // server one), so widening the TS type costs nothing.
                        'type' => 'boolean',
                        'description' => 'Whether `message` was written for THIS condition and may '
                            .'be shown to an operator, as opposed to being a fixed placeholder '
                            .'chosen to say nothing — the >= 500 constant, or the enumeration-oracle '
                            .'constants both planes render for `authorization`. IT IS NOT A STATUS '
                            .'AND NOTHING MAY INFER ONE FROM IT: it exists because a deliberate 4xx '
                            .'our own code raised and an unhandled exception both render '
                            .'`internal_dependency` with `retryable: false`, so an actionable '
                            .'refusal (`clear the designation first, then delete`) was structurally '
                            .'indistinguishable from `the service could not complete this request`. '
                            .'Clients rendering a server message must gate on this; clients choosing '
                            .'a retry must still read `retryable`, and clients choosing copy must '
                            .'still read `error_class`. `false` on a FormRequest 422, whose payload '
                            .'is the `errors` map rather than the summary; `true` on a KbException '
                            .'`validation`, whose message is written for a person.',
                    ],
                    'request_id' => [
                        // NULLABLE, and the null belongs to the OTHER plane. Laravel always sends a
                        // string — bootstrap/app.php falls back to Str::ulid() when the caller sent
                        // no X-KB-Request-Id — but FastAPI reads it off request.state, which is
                        // stamped in `request_context`, which runs AFTER the router-level
                        // verify_hmac. So every authentication-failure envelope from the data plane
                        // carries request_id: null, and this component describes both planes: the
                        // description says a consumer cannot tell which one produced an envelope.
                        // Declaring it non-nullable made the document describe a body the data
                        // plane does not emit, on exactly the failures people debug.
                        //
                        // It stays in `required` — the key is always PRESENT — and it stays
                        // non-null on everything Laravel emits, which is the correct behaviour and
                        // is not being relaxed to match. Narrowing the type back is the data
                        // plane's fix to earn (stamp it inside verify_hmac), not ours to pretend.
                        //
                        // AND IT IS `required` ON THE HTTP BODY ONLY, which is the one asymmetry
                        // this component has. The client-facing SSE `error` frame does not carry the
                        // field at all (kb-internal-api-contracts, client-facing SSE event schema:
                        // `data: {"error_class":…,"message":…,"retryable":…}`), so
                        // packages/contracts/src/envelope.ts declares `request_id?` — optional, so
                        // one interface covers a frame and a body — and src/sse/events.ts reuses it.
                        // The component's own description used to claim the two were "identical",
                        // which contradicted this `required` entry; the DESCRIPTION was the wrong
                        // half and is now narrowed to the HTTP body. `required` is not touched: it
                        // describes what this service really sends, and dropping it to match a frame
                        // this schema does not describe would weaken the half that is accurate.
                        'type' => ['string', 'null'],
                        'description' => 'Echoes `X-KB-Request-Id`, so one failure is greppable '
                            .'across both services. The one identifier a user is ever shown. Null '
                            .'only on a data-plane authentication failure, which is rejected before '
                            .'the id is stamped. ABSENT ENTIRELY — not null — from the SSE `error` '
                            .'frame, which this component does not describe.',
                    ],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'description' => 'Per-field messages, keyed by the field path the '
                            .'FormRequest validated. A SUPERSET present only on `validation`, and '
                            .'only when a FormRequest produced it — never null, never {} on another '
                            .'class. Declared here rather than only on ValidationErrorEnvelope '
                            .'because `additionalProperties: false` in an allOf branch cannot see a '
                            .'sibling branch\'s properties, so a real 422 body would not validate.',
                    ],
                ],
            ],

            'ValidationErrorEnvelope' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ErrorEnvelope'],
                    // This branch DECLARES NO NEW PROPERTY — that is the whole point. `errors` is
                    // declared on the branch above, where the additionalProperties:false that
                    // guards it can actually see it. All this branch does is narrow a value that
                    // already exists there.
                    [
                        'type' => 'object',
                        'properties' => [
                            'error_class' => ['enum' => ['validation']],
                        ],
                    ],
                ],
                // `errors` is NOT required, and that is not laxity — but the reason stated here
                // used to be WRONG, and the wrongness mattered because a client tests the invariant
                // structurally. "Absent when the refusal was relayed from the data plane" describes
                // ONE relayed sub-case and mis-describes the other:
                //
                //   * the data plane's PYDANTIC refusal DOES carry a map. `_handle_validation_error`
                //     in services/ai-service/app/main.py builds `dict[str, list[str]]` from every
                //     `loc` path, and InternalAiClient::relay() now forwards it verbatim.
                //   * what carries no map is a DELIBERATE refusal with no field to key on — either
                //     KbException::validation() raised here, or the data plane's embedding
                //     RESOLVER, which refuses the (connection, model) PAIR an operator named rather
                //     than a field of the body. tests/Feature/EmbeddingConfigurationTest.php,
                //     "refuses a designation the resolver cannot resolve", is that case.
                //
                // So the axis is DELIBERATE-REFUSAL versus PER-FIELD, not WHICH PLANE. Stating it
                // the other way made `errors === null` read as "came from over there", when what
                // apps/web/src/features/embedding/api.ts actually keys on is "there is no field to
                // show this against" — the ADR-031 resolver refusal.
                'description' => 'The error envelope narrowed to `error_class: validation`. `errors` '
                    .'is present whenever a per-field producer made a map — a Laravel FormRequest, '
                    .'or the data plane\'s Pydantic validation, whose map is relayed verbatim — and '
                    .'absent when the refusal is a deliberate one with no field to key on, such as '
                    .'the embedding resolver refusing the (connection, model) pair an operator '
                    .'named. It is never null and never `{}`, so a client reads it defensively '
                    .'rather than assuming it.',
            ],
        ];
    }
}

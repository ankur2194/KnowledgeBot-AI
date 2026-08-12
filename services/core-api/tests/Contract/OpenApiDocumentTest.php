<?php

declare(strict_types=1);

use App\Exceptions\KbException;
use App\Http\Resources\EmbeddingReadinessResource;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Embedding\EmbeddingRejection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The published OpenAPI document
|--------------------------------------------------------------------------
|
| packages/contracts/src/resources/ is a GENERATED directory. Its .gitkeep names the failure it
| exists to prevent: "a fixture typed by hand encodes what the author believed the wire says, so a
| server-side rename ships instead of failing a typecheck." Generating those types requires a
| document, and a document is only worth generating from if it is PROVED against the code.
|
| That proof is this file, and it has two halves that fail differently:
|
|   1. DECLARATION vs EXECUTION. Every ProvidesOpenApiSchema resource is rendered through its real
|      toArray() over a fixture matrix — including the null and empty cases one fixture cannot
|      distinguish — and the emitted structure is validated against the schema the resource
|      publishes. A field added to toArray() and not to the schema fails here; so does the reverse.
|
|   2. THE CREDENTIAL INVARIANT. No provider credential reaches a client, a log, an API response or
|      an audit detail (non-negotiable 9). The fixture connection is sealed with a distinctive
|      plaintext literal, so "the key was never in play" cannot be why the assertion passes.
|
| NOTE ON `toContain`. Pest's toContain(mixed ...$needles) takes NO message argument — a "label"
| passed there becomes a second NEEDLE — and `not` treats any failure as success, so
| `expect($body)->not->toContain('x', $label)` passes unconditionally. That exact shape has already
| hidden a real tenant-id leak in this repo. Every absence assertion below is therefore written as
| an explicit str_contains(...) reduced to a boolean, where the message argument is real.
*/

// ── a minimal JSON Schema conformance check, sufficient for the dialect we publish ───────────────

/**
 * @param  array<string, mixed>  $schema
 * @param  array<string, array<string, mixed>>  $components
 * @return list<string> one message per disagreement
 */
function schemaViolations(mixed $value, array $schema, array $components, string $path = '$'): array
{
    if (isset($schema['$ref']) && is_string($schema['$ref'])) {
        $name = substr($schema['$ref'], strlen('#/components/schemas/'));

        if (! array_key_exists($name, $components)) {
            return ["{$path}: \$ref to an undeclared component '{$name}'"];
        }

        return schemaViolations($value, $components[$name], $components, $path);
    }

    // allOf, and the reason its semantics are reproduced EXACTLY rather than approximated: an
    // `additionalProperties: false` inside one branch is evaluated against THAT BRANCH'S
    // `properties` only, never against a sibling's. That is precisely the rule that made the
    // published ValidationErrorEnvelope unsatisfiable while `errors` lived in the second branch,
    // and validating each branch independently here is what makes this file able to see it.
    // (Keywords sibling to `allOf` are not applied; the only schema that composes carries none.)
    if (isset($schema['allOf']) && is_array($schema['allOf'])) {
        $violations = [];

        foreach ($schema['allOf'] as $branch) {
            if (is_array($branch)) {
                $violations = [...$violations, ...schemaViolations($value, $branch, $components, $path)];
            }
        }

        return $violations;
    }

    if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
        foreach ($schema['anyOf'] as $branch) {
            if (is_array($branch) && schemaViolations($value, $branch, $components, $path) === []) {
                return [];
            }
        }

        return ["{$path}: matched no branch of anyOf"];
    }

    /** @var list<string> $types */
    $types = match (true) {
        is_string($schema['type'] ?? null) => [$schema['type']],
        is_array($schema['type'] ?? null) => array_values(array_filter($schema['type'], 'is_string')),
        default => [],
    };

    $actual = match (true) {
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        $value === null => 'null',
        is_array($value) => array_is_list($value) ? 'array' : 'object',
        default => get_debug_type($value),
    };

    // An empty PHP array is ambiguous: [] is both an empty list and an empty map. Accept it for
    // whichever of the two the schema declares rather than guessing from the value.
    if ($actual === 'array' && $value === [] && in_array('object', $types, true)) {
        $actual = 'object';
    }

    if ($types !== [] && ! in_array($actual, $types, true)) {
        return ["{$path}: emitted {$actual}, schema declares ".implode('|', $types)];
    }

    if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        return ["{$path}: value is outside the declared enum"];
    }

    $violations = [];

    if ($actual === 'object' && is_array($value)) {
        /** @var array<string, mixed> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null)
            ? array_values(array_filter($schema['required'], 'is_string'))
            : [];

        // THE ASSERTION THIS WHOLE FILE IS FOR: the key set is compared BOTH ways. An extra emitted
        // key is an undocumented field a generated client silently drops; a missing one is a type
        // that promises something the wire does not carry.
        //
        // Each direction is now keyed on the keyword that actually governs it, because the error
        // envelopes have an OPTIONAL property (`errors`) and a plain key-set equality cannot
        // express that: `required` decides what must be present, `additionalProperties: false`
        // decides what may not appear. Every resource schema in this application requires every
        // property it declares and closes itself, so this is the same check it always was for them
        // — proven by mutation, not by inspection.
        $missing = array_values(array_diff($required, array_keys($value)));

        $undeclared = ($schema['additionalProperties'] ?? null) === false
            ? array_values(array_diff(array_keys($value), array_keys($properties)))
            : [];

        if ($missing !== [] || $undeclared !== []) {
            $violations[] = "{$path}: keys differ — missing required [".implode(', ', $missing)
                .'], undeclared ['.implode(', ', $undeclared).']';
        }

        foreach ($properties as $key => $child) {
            if (is_array($child) && array_key_exists($key, $value)) {
                $violations = [...$violations, ...schemaViolations($value[$key], $child, $components, "{$path}.{$key}")];
            }
        }

        // An `additionalProperties` that is a SCHEMA rather than `false` types every key the
        // `properties` block does not name — which is the whole of the `errors` map, where the
        // field names are the tenant's input and only the VALUES have a declared shape.
        if (is_array($schema['additionalProperties'] ?? null)) {
            foreach ($value as $key => $child) {
                if (! array_key_exists($key, $properties)) {
                    $violations = [
                        ...$violations,
                        ...schemaViolations($child, $schema['additionalProperties'], $components, "{$path}.{$key}"),
                    ];
                }
            }
        }
    }

    if ($actual === 'array' && is_array($value) && is_array($schema['items'] ?? null)) {
        foreach ($value as $index => $item) {
            $violations = [...$violations, ...schemaViolations($item, $schema['items'], $components, "{$path}[{$index}]")];
        }
    }

    return $violations;
}

/**
 * Every string a rendered payload contains, at any depth — what a client actually receives.
 *
 * @return list<string>
 */
function flattenStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    $found = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $found[] = $key;
        }

        $found = [...$found, ...flattenStrings($child)];
    }

    return $found;
}

function candidateFixture(string $connectionId): EmbeddingCandidate
{
    return new EmbeddingCandidate(
        connectionId: $connectionId,
        provider: 'openai',
        model: 'text-embedding-3-large',
        supported: ['embedding'],
        contextWindow: 8191,
        maxOutputTokens: 0,
    );
}

/**
 * The fixture matrix. Every branch a single fixture cannot express is a row here: a null
 * `selected`, an empty `eligible`, an empty `rejected`, and both populated.
 *
 * @return array<string, EmbeddingReadiness>
 */
function readinessFixtures(): array
{
    $id = (string) Str::ulid();

    return [
        'ready, with a rejection alongside' => new EmbeddingReadiness(
            selected: candidateFixture($id),
            eligible: [candidateFixture($id)],
            rejected: [new EmbeddingRejection($id, 'anthropic', 'claude-x', 'vendor_has_no_endpoint', 'no embeddings endpoint')],
            explanation: '',
        ),
        'ready, nothing rejected' => new EmbeddingReadiness(
            selected: candidateFixture($id),
            eligible: [candidateFixture($id)],
            rejected: [],
            explanation: '',
        ),
        'blocked, nothing eligible' => new EmbeddingReadiness(
            selected: null,
            eligible: [],
            rejected: [],
            explanation: 'This organization has no embedding-capable provider connection.',
        ),
        'blocked, with rejections' => new EmbeddingReadiness(
            selected: null,
            eligible: [],
            rejected: [new EmbeddingRejection($id, 'openai', 'gpt-x', 'row_lacks_embedding_flag', 'the row does not claim embedding')],
            explanation: 'Every candidate was refused.',
        ),
    ];
}

// ── half one: the declaration matches what the resource emits ────────────────────────────────────

it('publishes exactly the keys EmbeddingReadinessResource emits, on every branch', function (): void {
    $components = EmbeddingReadinessResource::openApiSchemas();
    $request = Request::create('/api/v1/organizations/01JQZ0000000000000000000AA/embedding-configuration');

    foreach (readinessFixtures() as $label => $readiness) {
        $emitted = (new EmbeddingReadinessResource($readiness))->toArray($request);

        expect(schemaViolations($emitted, $components['EmbeddingReadinessResource'], $components))
            ->toBe([], "the published schema disagrees with toArray() for: {$label}");
    }
});

it('publishes exactly the keys ProviderConnectionResource emits', function (): void {
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->create();

    $components = ProviderConnectionResource::openApiSchemas();
    $emitted = (new ProviderConnectionResource($connection))->toArray(Request::create('/'));

    expect(schemaViolations($emitted, $components['ProviderConnectionResource'], $components))->toBe([]);

    // The null branch of created_at, which the factory cannot produce.
    $emitted['created_at'] = null;

    expect(schemaViolations($emitted, $components['ProviderConnectionResource'], $components))->toBe([]);
});

it('names its own component, so the generated type has the name the client imports', function (): void {
    // DISCOVERED, not listed. A hard-coded pair would pass forever while a NEW resource added the
    // interface and forgot its own component — and that resource's endpoint would then fail the
    // dump at deploy time instead of here. (PHPStan also proves a literal list already implements
    // the interface, so a literal list makes the assertion a narrowed-type error rather than a
    // check.)
    $implementors = [];

    foreach (glob(app_path('Http/Resources').'/*.php') ?: [] as $file) {
        $class = 'App\\Http\\Resources\\'.basename($file, '.php');

        if (is_subclass_of($class, ProvidesOpenApiSchema::class)) {
            $implementors[] = $class;
        }
    }

    // The positive control: if the glob found nothing, everything below iterates an empty list.
    expect($implementors)->toContain(
        EmbeddingReadinessResource::class,
        ProviderConnectionResource::class,
    );

    foreach ($implementors as $resource) {
        expect(array_keys($resource::openApiSchemas()))->toContain(class_basename($resource));
    }
});

it('keeps every resource component closed AND total, which is what schemaViolations relies on', function (): void {
    // THE GUARD FOR A RELAXATION MADE ABOVE. schemaViolations() used to compare the key set both
    // ways with one equality; it now keys "must be present" on `required` and "may not appear" on
    // `additionalProperties: false`, because the error envelopes have an OPTIONAL property and no
    // single equality can express that. For a schema that requires everything it declares and
    // closes itself, the two formulations are identical — and this asserts that every resource
    // component is such a schema, so the relaxation cannot quietly become a hole.
    //
    // Discovered, not listed: a NEW resource with a merely-optional property is exactly the case
    // that would slip through, and a hard-coded list would not see it.
    $implementors = [];

    foreach (glob(app_path('Http/Resources').'/*.php') ?: [] as $file) {
        $class = 'App\\Http\\Resources\\'.basename($file, '.php');

        if (is_subclass_of($class, ProvidesOpenApiSchema::class)) {
            $implementors[] = $class;
        }
    }

    expect($implementors)->toContain(EmbeddingReadinessResource::class, ProviderConnectionResource::class);

    foreach ($implementors as $resource) {
        foreach ($resource::openApiSchemas() as $name => $schema) {
            expect($schema['additionalProperties'] ?? null)
                ->toBeFalse("{$name} is not closed, so an undocumented field would not be detected");

            $declared = array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []);
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

            expect(array_values(array_diff($declared, $required)))
                ->toBe([], "{$name} declares a property it does not require, which schemaViolations no longer checks for");
        }
    }
});

// ── half two: no credential reaches the wire or the document ─────────────────────────────────────

it('renders no provider credential, from a connection whose key really was sealed', function (): void {
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->create();

    // Positive control FIRST. If the fixture credential is not actually in the row, every absence
    // assertion below is vacuous — the classic redaction test that passes because nothing was ever
    // encrypted (see ProviderConnectionFactory's own docblock).
    expect($connection->last_four)->toBe(substr(ProviderConnectionFactory::FIXTURE_CREDENTIAL, -4));

    $rendered = (new ProviderConnectionResource($connection))->toArray(Request::create('/'));
    $body = json_encode($rendered, JSON_THROW_ON_ERROR);

    // str_contains and not ->not->toContain(): `not` treats any failure as success, and
    // toContain() would read a message argument as a second needle.
    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the plaintext provider credential reached an API Resource');

    expect($rendered['masked_key'] ?? null)
        ->toBe('…'.$connection->last_four, 'masked_key must be the stored last four, never a prefix');

    // Four characters and an ellipsis, and nothing else: a longer mask is a longer key.
    expect(mb_strlen((string) ($rendered['masked_key'] ?? '')))->toBe(5);

    foreach (array_keys($rendered) as $key) {
        expect((bool) preg_match('/credential|api[_-]?key|secret|token|password|ciphertext|kek/i', (string) $key))
            ->toBeFalse("ProviderConnectionResource emits a credential-shaped field: {$key}");
    }
});

it('emits no key at all from EmbeddingReadinessResource', function (): void {
    // The readiness answers a CONFIGURATION question. Even a masked key would be a value on a
    // screen that had no reason to render one — and EmbeddingCandidate/EmbeddingRejection have
    // nowhere to put a secret by construction, which is what this asserts is still true.
    $request = Request::create('/');

    foreach (readinessFixtures() as $label => $readiness) {
        $rendered = (new EmbeddingReadinessResource($readiness))->toArray($request);

        foreach (flattenStrings($rendered) as $fragment) {
            expect((bool) preg_match('/credential|api[_-]?key|secret|password|ciphertext|kek|last_four/i', $fragment))
                ->toBeFalse("EmbeddingReadinessResource leaked a credential-shaped string for {$label}: {$fragment}");
        }
    }
});

// ── the document itself ──────────────────────────────────────────────────────────────────────────

/**
 * @return array{path: string, document: array<string, mixed>}
 */
function dumpDocument(): array
{
    $dir = sys_get_temp_dir().'/kb-openapi-'.getmypid().'-'.Str::random(8);
    $path = $dir.'/core-api.openapi.json';

    expect(Artisan::call('kb:dump-openapi', ['--path' => $path]))->toBe(0, Artisan::output());

    $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

    assert(is_array($decoded));

    return ['path' => $path, 'document' => $decoded];
}

afterEach(function (): void {
    foreach (File::directories(sys_get_temp_dir()) as $dir) {
        if (str_starts_with(basename($dir), 'kb-openapi-'.getmypid().'-')) {
            File::deleteDirectory($dir);
        }
    }
});

it('publishes EmbeddingReadinessResource as a named component with its six fields', function (): void {
    // The specific thing admin-web-engineer is waiting on: a component they can point a generator
    // at, so their structural placeholder can be deleted rather than maintained.
    $document = dumpDocument()['document'];

    $schemas = $document['components']['schemas'] ?? null;

    assert(is_array($schemas));

    expect(array_keys($schemas))->toContain('EmbeddingReadinessResource', 'EmbeddingCandidate', 'EmbeddingRejection');

    $readiness = $schemas['EmbeddingReadinessResource'];

    assert(is_array($readiness) && is_array($readiness['properties']));

    expect(array_keys($readiness['properties']))->toEqualCanonicalizing([
        'ready', 'blocks_ingestion', 'selected', 'eligible', 'rejected', 'explanation',
    ]);

    // additionalProperties:false is what makes the generated type CLOSED. Without it a renamed
    // server field generates as an optional extra rather than as a compile error, which is the
    // whole failure the generated-types rule exists to prevent.
    expect($readiness['additionalProperties'] ?? null)->toBeFalse();
});

it('carries both envelopes the same resource is served in', function (): void {
    // One resource, two shapes: wrapped in `data` on the configuration endpoints, top-level under
    // `embedding_readiness` on the connection create. A client generated from a document that
    // published only one of them would be wrong on the other endpoint.
    $document = dumpDocument()['document'];
    $paths = $document['paths'] ?? null;

    assert(is_array($paths));

    $configuration = $paths['/api/v1/organizations/{organization}/embedding-configuration'] ?? null;
    $connections = $paths['/api/v1/organizations/{organization}/provider-connections'] ?? null;

    assert(is_array($configuration) && is_array($connections));

    expect(array_keys($configuration))->toEqualCanonicalizing(['get', 'put']);

    $wrapped = $configuration['get']['responses']['200']['content']['application/json']['schema']['properties'] ?? null;
    $flat = $connections['post']['responses']['201']['content']['application/json']['schema']['properties'] ?? null;

    assert(is_array($wrapped) && is_array($flat));

    expect(array_keys($wrapped))->toBe(['data'])
        ->and($wrapped['data'])->toBe(['$ref' => '#/components/schemas/EmbeddingReadinessResource'])
        ->and(array_keys($flat))->toEqualCanonicalizing(['data', 'embedding_readiness'])
        ->and($flat['data'])->toBe(['$ref' => '#/components/schemas/ProviderConnectionResource'])
        ->and($flat['embedding_readiness'])->toBe(['$ref' => '#/components/schemas/EmbeddingReadinessResource']);
});

// ── the error envelopes, validated against bodies this service really emits ───────────────────────

/**
 * The schema an operation's response of `$status` points at, read out of the dumped document.
 *
 * Read rather than named: hardcoding '#/components/schemas/ValidationErrorEnvelope' here would keep
 * passing on the day the 422 stopped pointing at it.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function responseSchemaFor(array $document, string $path, string $method, int $status): array
{
    $schema = $document['paths'][$path][$method]['responses'][(string) $status]['content']['application/json']['schema'] ?? null;

    assert(is_array($schema), "the document declares no {$status} for {$method} {$path}");

    return $schema;
}

it('validates the error bodies this service actually emits against the schemas that describe them', function (): void {
    // THE DEFECT THIS TEST IS THE ABSENCE OF. `ErrorEnvelope` carries `additionalProperties: false`
    // and `ValidationErrorEnvelope` composes it with `allOf`. Under JSON Schema the first branch's
    // `additionalProperties` cannot see the second branch's `properties`, so while `errors` was
    // declared only in that second branch, EVERY REAL 422 BODY WAS INVALID AGAINST THE SCHEMA
    // PUBLISHED FOR IT — "Additional properties are not allowed ('errors' was unexpected)". Nothing
    // caught it because nothing here had ever fed a real body to either envelope schema; the
    // assertions were about the resource components only.
    //
    // So the test is not "read the schema and agree with it". It is: fire real requests, take the
    // bodies the render closure produced, and validate those bytes.
    // The default `stack` channel writes JSON to php://stdout and phpunit.xml sets
    // beStrictAboutOutputDuringTests. Silencing the LOGGER never silences the RENDERER, which is
    // the thing under test.
    config(['logging.default' => 'null']);

    $document = dumpDocument()['document'];
    $schemas = $document['components']['schemas'] ?? null;

    assert(is_array($schemas));

    $org = Organization::factory()->create();
    $owner = \App\Models\User::factory()->recycle($org)->orgRole(\App\Enums\OrgRole::Owner)->create();
    $connections = "/api/v1/organizations/{$org->id}/provider-connections";

    // (1) A REAL FormRequest 422 — the shape with the `errors` map. No Http::fake and none needed:
    // validation refuses before anything reaches the data plane.
    $withMap = currentTest()->actingAs($owner)->postJson($connections, [
        'provider' => 'not-a-vendor',
        'label' => 'Primary',
        'credential' => 'kb-openapi-contract-credential-01JQZ',
        'models' => [],
    ]);

    $withMap->assertStatus(422);

    $body = $withMap->json();

    assert(is_array($body));

    // Positive control BEFORE the schema check: without it this passes just as happily against a
    // 422 that carried no map at all, which is the branch the bug lived in.
    expect($body)->toHaveKey('errors')
        ->and($body['errors'])->toBeArray()
        ->and($body['errors'])->not->toBe([]);

    expect(schemaViolations($body, responseSchemaFor($document, '/api/v1/organizations/{organization}/provider-connections', 'post', 422), $schemas))
        ->toBe([], 'a real FormRequest 422 body does not validate against the schema published for it');

    // (2) A REAL 422 with NO map. `validation` relayed from the data plane's embedding resolver
    // carries a sentence and no per-field messages, so `errors` must stay OPTIONAL on the
    // validation envelope — requiring it would be the same defect facing the other way.
    Route::get('api/v1/_test/mapless-422', static fn () => throw KbException::validation('The designated embedding connection cannot embed.'));

    $mapless = currentTest()->getJson('api/v1/_test/mapless-422');

    $mapless->assertStatus(422)->assertJsonPath('error_class', 'validation');

    $maplessBody = $mapless->json();

    assert(is_array($maplessBody));

    expect($maplessBody)->not->toHaveKey('errors');

    expect(schemaViolations($maplessBody, ['$ref' => '#/components/schemas/ValidationErrorEnvelope'], $schemas))
        ->toBe([], 'a validation envelope with no per-field map does not validate');

    // (3) A REAL non-422 body, against the base envelope — because the fix moved a property ONTO
    // that schema and this is what would notice if it had been moved as `required`. A stranger is
    // denied by `org.member` before any controller runs, so this reaches no data plane either.
    $denied = currentTest()->actingAs(\App\Models\User::factory()->create())
        ->getJson("/api/v1/organizations/{$org->id}/embedding-configuration");

    $denied->assertStatus(403);

    $deniedBody = $denied->json();

    assert(is_array($deniedBody));

    expect($deniedBody)->not->toHaveKey('errors');

    expect(schemaViolations($deniedBody, responseSchemaFor($document, '/api/v1/organizations/{organization}/embedding-configuration', 'get', 403), $schemas))
        ->toBe([], 'a real 403 body does not validate against ErrorEnvelope');
});

it('types request_id so the data plane\'s null is describable, without relaxing what Laravel sends', function (): void {
    // THREE CONTRACTS DISAGREED. The document said `required` and non-nullable; the TypeScript
    // carrier says optional and nullable; Laravel always sends a non-empty string; FastAPI sends
    // NULL on every authentication failure, because request.state.request_id is stamped in
    // request_context, which runs after the router-level verify_hmac. One component describes both
    // planes — its own description says a consumer cannot tell which produced an envelope — so the
    // type has to admit the null the data plane emits.
    //
    // What must NOT change is Laravel's behaviour, and that is asserted here rather than left to
    // the reader: widening a schema is not permission to start omitting the id.
    config(['logging.default' => 'null']);

    $schemas = dumpDocument()['document']['components']['schemas'] ?? null;

    assert(is_array($schemas));

    $envelope = $schemas['ErrorEnvelope'];

    assert(is_array($envelope) && is_array($envelope['properties']));

    expect($envelope['properties']['request_id']['type'] ?? null)->toBe(['string', 'null'])
        ->and($envelope['required'] ?? [])->toContain('request_id');

    // The key is always PRESENT and, from this service, always a non-empty string — including when
    // the caller sent no X-KB-Request-Id at all and bootstrap/app.php had to mint one.
    Route::get('api/v1/_test/no-request-id-header', static fn () => throw KbException::validation('nope'));

    $minted = currentTest()->getJson('api/v1/_test/no-request-id-header');

    expect($minted->json('request_id'))->toBeString()
        ->and($minted->json('request_id'))->not->toBe('');

    // And an echoed one is echoed, not replaced.
    $echoed = currentTest()->getJson('api/v1/_test/no-request-id-header', ['X-KB-Request-Id' => '01JKB0000000000000000ECHO']);

    expect($echoed->json('request_id'))->toBe('01JKB0000000000000000ECHO');
});

it('describes no request body, and points at the manifest that owns the rules instead', function (): void {
    // docs/22 finding 19: the FormRequest is the only source of a request rule, and the drift
    // manifest is dumped from executing rules(). A requestBody schema here would be a third copy.
    $document = dumpDocument()['document'];

    $json = json_encode($document, JSON_THROW_ON_ERROR);

    expect(str_contains($json, '"requestBody"'))
        ->toBeFalse('a request body schema in the OpenAPI document is the third copy of the request rules');

    $post = $document['paths']['/api/v1/organizations/{organization}/provider-connections']['post'] ?? null;

    assert(is_array($post));

    expect($post['x-kb-request-rules'] ?? null)->toBe('../rules/StoreProviderConnectionRequest.json');

    // …and the manifest it points at must actually exist, or the pointer is decoration.
    expect(File::exists(dirname(base_path(), 2).'/packages/contracts/rules/StoreProviderConnectionRequest.json'))
        ->toBeTrue();
});

it('names no credential anywhere in the document', function (): void {
    // The published document is read by generators, by reviewers, and by anyone with the repo. A
    // `credential` property on a RESPONSE schema would be a leak; `x-kb-request-rules` naming the
    // manifest file is the only permitted occurrence of the word, and it is a path, not a field.
    $document = dumpDocument()['document'];

    $schemas = $document['components']['schemas'] ?? [];

    assert(is_array($schemas));

    foreach (flattenStrings($schemas) as $fragment) {
        // Property names and enum members only — descriptions legitimately discuss credentials.
        if (str_contains($fragment, ' ')) {
            continue;
        }

        expect((bool) preg_match('/^(credential|api_key|apiKey|secret|token|password|ciphertext|kek)/i', $fragment))
            ->toBeFalse("the published document names a credential-shaped schema key: {$fragment}");
    }
});

it('is byte-identical across two runs and --check is a real gate', function (): void {
    $first = dumpDocument();
    $second = dumpDocument();

    expect(File::get($second['path']))->toBe(File::get($first['path']));

    expect(Artisan::call('kb:dump-openapi', ['--path' => $first['path'], '--check' => true]))
        ->toBe(0, Artisan::output());

    File::put($first['path'], str_replace('"3.1.0"', '"3.0.3"', File::get($first['path'])));

    expect(Artisan::call('kb:dump-openapi', ['--path' => $first['path'], '--check' => true]))->toBe(1);
});

// NOTE: "keeps the committed document current" used to sit here. It called
// `Artisan::call('kb:dump-openapi', ['--check' => true])` with the DEFAULT path — against the real
// committed artifact — and its own comment said it existed "because ci.yml has no step for this
// command yet". That premise is false: `.github/workflows/ci.yml:347` now runs
// `php artisan kb:dump-openapi --check`, before the Pest step, where it is the only reachable copy
// of that assertion (a step after Pest could never report — the suite would go red first). The
// test above stays, and is not a duplicate of it: `--path` a temp dir proves the GENERATOR is
// deterministic and that `--check` can fail, which no CI step covers. Suite proves the generator;
// ci.yml proves the artifact.

it('refuses to publish a client route with no declared response shape', function (): void {
    // The rule that keeps the document from silently missing an endpoint. A new route under
    // api/v1 with no #[ResponseShape] must fail the dump, not be skipped: a generated client
    // cannot tell "absent" from "does not exist".
    app('router')->get('api/v1/undeclared-probe', fn (): string => 'x');
    app('router')->getRoutes()->refreshNameLookups();

    $path = sys_get_temp_dir().'/kb-openapi-'.getmypid().'-probe/core-api.openapi.json';

    expect(Artisan::call('kb:dump-openapi', ['--path' => $path]))->toBe(1)
        ->and(File::exists($path))->toBeFalse();
});

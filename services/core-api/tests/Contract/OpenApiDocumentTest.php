<?php

declare(strict_types=1);

use App\Enums\BotDomainStatus;
use App\Enums\EvidenceThresholdScale;
use App\Exceptions\KbException;
use App\Http\Resources\BotCollectionResource;
use App\Http\Resources\BotDomainCollectionResource;
use App\Http\Resources\BotDomainResource;
use App\Http\Resources\BotResource;
use App\Http\Resources\BotStarterQuestionCollectionResource;
use App\Http\Resources\BotStarterQuestionResource;
use App\Http\Resources\EmbeddingReadinessResource;
use App\Http\Resources\ProviderConnectionCollectionResource;
use App\Http\Resources\ProviderConnectionResource;
use App\Http\Resources\ProviderModelCollectionResource;
use App\Http\Resources\ProviderModelResource;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingDesignation;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Embedding\EmbeddingRejection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        // ADDED WITH THE FIRST RESOURCE THAT PUBLISHES A JSON `number`, and it was a real gap
        // rather than a missing convenience: without this arm a float fell to `get_debug_type()`
        // and reported as the type `float`, which matches no JSON Schema keyword at all — so the
        // only way to make such a field pass was to declare a type JSON Schema does not have.
        // `bots.evidence_threshold` is that field: a CUTOFF compared once against a double, where
        // the decimal-string representation the prices use would claim a precision the score does
        // not have.
        is_float($value) => 'number',
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

    // JSON Schema's `integer` is a SUBTYPE of `number`, so a whole-numbered value emitted as a PHP
    // int satisfies a `number` declaration. Written as a widening of the ACTUAL type rather than of
    // the declared set, so a schema that declares `integer` still refuses a float — which is the
    // direction that matters: a client generated from `integer` and served 0.5 is a parse error.
    if ($actual === 'integer' && ! in_array('integer', $types, true) && in_array('number', $types, true)) {
        $actual = 'number';
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
 * EVERY ROW IS A PAIR, because `designated` is a SECOND, INDEPENDENT axis and not a function of the
 * verdict. The resource renders the stored `(connection_id, model)` off `organizations`, which the
 * readiness object does not carry and cannot be derived from: `selected: null` happens both when
 * nothing was designated and when a designation stopped resolving, and those are the two cells this
 * matrix has to keep apart. The `null`/populated column is therefore crossed with the ready/blocked
 * column rather than folded into it.
 *
 * @return array<string, array{readiness: EmbeddingReadiness, designated: ?EmbeddingDesignation}>
 */
function readinessFixtures(): array
{
    $id = (string) Str::ulid();

    return [
        'ready, with a rejection alongside, nothing designated' => [
            'readiness' => new EmbeddingReadiness(
                selected: candidateFixture($id),
                eligible: [candidateFixture($id)],
                rejected: [new EmbeddingRejection($id, 'anthropic', 'claude-x', 'vendor_has_no_endpoint', 'no embeddings endpoint')],
                explanation: '',
            ),
            'designated' => null,
        ],
        'ready, nothing rejected, nothing designated' => [
            'readiness' => new EmbeddingReadiness(
                selected: candidateFixture($id),
                eligible: [candidateFixture($id)],
                rejected: [],
                explanation: '',
            ),
            'designated' => null,
        ],
        // THE READY-AND-DESIGNATED CELL. A designation is never substituted, so when one resolves
        // the two fields agree on connection and model and `selected` alone carries `provider`.
        'ready, and the designation is what resolved' => [
            'readiness' => new EmbeddingReadiness(
                selected: candidateFixture($id),
                eligible: [candidateFixture($id)],
                rejected: [],
                explanation: '',
            ),
            'designated' => new EmbeddingDesignation($id, 'text-embedding-3-large'),
        ],
        'blocked, nothing eligible, nothing designated' => [
            'readiness' => new EmbeddingReadiness(
                selected: null,
                eligible: [],
                rejected: [],
                explanation: 'This organization has no embedding-capable provider connection.',
            ),
            'designated' => null,
        ],
        'blocked, with rejections, nothing designated' => [
            'readiness' => new EmbeddingReadiness(
                selected: null,
                eligible: [],
                rejected: [new EmbeddingRejection($id, 'openai', 'gpt-x', 'row_lacks_embedding_flag', 'the row does not claim embedding')],
                explanation: 'Every candidate was refused.',
            ),
            'designated' => null,
        ],
        // THE CELL `designated` WAS ADDED FOR: a pair really is stored and it no longer resolves.
        // Before the field, this row and the two above it were byte-identical apart from
        // `explanation`, which is prose the data plane owns and a client may not parse.
        'blocked, and the STORED designation is what stopped resolving' => [
            'readiness' => new EmbeddingReadiness(
                selected: null,
                eligible: [],
                rejected: [new EmbeddingRejection($id, 'openai', 'text-embedding-3-large', 'row_lacks_embedding_flag', 'the row does not claim embedding')],
                explanation: 'The designated connection and model no longer resolve to an embedder.',
            ),
            'designated' => new EmbeddingDesignation($id, 'text-embedding-3-large'),
        ],
    ];
}

// ── half one: the declaration matches what the resource emits ────────────────────────────────────

it('publishes exactly the keys EmbeddingReadinessResource emits, on every branch', function (): void {
    $components = EmbeddingReadinessResource::openApiSchemas();
    $request = Request::create('/api/v1/organizations/01JQZ0000000000000000000AA/embedding-configuration');

    foreach (readinessFixtures() as $label => $fixture) {
        $emitted = (new EmbeddingReadinessResource($fixture['readiness'], $fixture['designated']))
            ->toArray($request);

        expect(schemaViolations($emitted, $components['EmbeddingReadinessResource'], $components))
            ->toBe([], "the published schema disagrees with toArray() for: {$label}");
    }
});

it('renders a stored-but-unresolvable designation as a null `selected` AND a populated `designated`', function (): void {
    // THE WHOLE REASON `designated` EXISTS, asserted as one fact rather than left to the matrix.
    //
    // Before it, "you designated nothing and nothing resolved by rule" and "you designated X and X
    // is failing" produced IDENTICAL structure — `ready: false`, `selected: null`, and the stored
    // pair mentioned only inside `explanation`, which is the data plane's prose, rendered verbatim
    // by contract and therefore unparseable by a client that wants to name the failing connection.
    // So the designation screen could only ever fall back to resolve-by-rule copy, which is a
    // DIFFERENT claim from the truth.
    $request = Request::create('/');
    $connectionId = (string) Str::ulid();

    $blocked = new EmbeddingReadiness(
        selected: null,
        eligible: [],
        rejected: [new EmbeddingRejection(
            $connectionId,
            'openai',
            'text-embedding-3-large',
            'row_lacks_embedding_flag',
            'the row does not claim embedding',
        )],
        explanation: 'The designated connection and model no longer resolve to an embedder.',
    );

    $designated = new EmbeddingDesignation($connectionId, 'text-embedding-3-large');

    $rendered = (new EmbeddingReadinessResource($blocked, $designated))->toArray($request);

    expect($rendered['ready'])->toBeFalse()
        ->and($rendered['blocks_ingestion'])->toBeTrue()
        ->and($rendered['selected'])->toBeNull()
        // POPULATED, AND WITH THE STORED PAIR RATHER THAN ANYTHING THE RESOLVER PRODUCED. The two
        // keys and no third: `organizations` stores a connection id and a model string, and a
        // `provider` here would be a join publishing a value the designation does not contain.
        ->and($rendered['designated'])->toBe([
            'connection_id' => $connectionId,
            'model' => 'text-embedding-3-large',
        ]);

    // AND THE DISCRIMINATION IS REAL: the same blocked verdict with nothing stored differs in
    // exactly this one key. Without this half the assertion above would pass against a `designated`
    // that echoed something from the readiness object instead of reading the organization.
    $undesignated = (new EmbeddingReadinessResource($blocked, null))->toArray($request);

    expect($undesignated['designated'])->toBeNull()
        ->and($undesignated['selected'])->toBe($rendered['selected'])
        ->and($undesignated['explanation'])->toBe($rendered['explanation']);
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

    // AND THE COLLECTION WRAPPER — the one published component that had no toArray()-versus-schema
    // case, while EmbeddingReadinessResource, ProviderConnectionResource, ProviderModelResource and
    // ProviderModelCollectionResource all had one. `names its own component` proves it DECLARES a
    // schema; nothing proved the schema matched what it emits, which is the half that catches a key
    // renamed on one side only.
    //
    // BOTH THE EMPTY AND THE POPULATED CASE. An empty list is what a new organization's index
    // returns and is the branch a single fixture cannot distinguish — `items` is never evaluated
    // against a zero-length array, so a `$ref` to a component that does not exist would validate.
    $collection = ProviderConnectionCollectionResource::openApiSchemas();

    foreach ([[], [$connection]] as $index => $rows) {
        $emittedCollection = (new ProviderConnectionCollectionResource($rows))->toArray(Request::create('/'));

        expect(schemaViolations($emittedCollection, $collection['ProviderConnectionCollectionResource'], $collection))
            ->toBe([], "the collection schema disagrees with toArray() for fixture set {$index}");
    }
});

it('publishes exactly the keys ProviderModelResource emits, priced and unpriced', function (): void {
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->create();

    $components = ProviderModelResource::openApiSchemas();

    // TWO FIXTURES, BECAUSE ONE CANNOT DISTINGUISH THE BRANCH THAT MATTERS. A priced row proves the
    // decimal STRING representation is what the schema declares; an unpriced one proves the null
    // branch is declared as `["string", "null"]` rather than as `string`. A generated client built
    // from the second-best answer would type `price_currency` as non-nullable and break on the
    // majority of real rows.
    $fixtures = [
        'priced' => ProviderModelEntry::factory()->recycle($org)->recycle($connection)
            ->supporting(['embedding'])
            ->priced('0.130000', '0.000000')
            ->create(['model' => 'text-embedding-3-large']),
        // An EMPTY flag list too: `supported: []` is a legitimate state ("this row claims nothing")
        // and is the value a malformed stored envelope also renders as, so the schema has to admit
        // it.
        'unpriced, no flags, disabled' => ProviderModelEntry::factory()
            ->recycle($org)->recycle($connection)
            ->supporting([])->disabled()
            ->create(['model' => 'gpt-5-unpriced']),
    ];

    foreach ($fixtures as $label => $row) {
        $emitted = (new ProviderModelResource($row))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $components['ProviderModelResource'], $components))
            ->toBe([], "the published schema disagrees with toArray() for: {$label}");
    }

    // The null branch of created_at, which the factory cannot produce.
    $emitted = (new ProviderModelResource($fixtures['priced']))->toArray(Request::create('/'));
    $emitted['created_at'] = null;

    expect(schemaViolations($emitted, $components['ProviderModelResource'], $components))->toBe([]);

    // And the collection wrapper, over BOTH rows plus the empty case a connection with no catalogue
    // returns.
    $collection = ProviderModelCollectionResource::openApiSchemas();

    foreach ([[], array_values($fixtures)] as $index => $rows) {
        $emitted = (new ProviderModelCollectionResource($rows))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $collection['ProviderModelCollectionResource'], $collection))
            ->toBe([], "the collection schema disagrees with toArray() for fixture set {$index}");
    }
});

it('publishes exactly the keys BotResource emits, unconfigured and fully configured', function (): void {
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->create();
    $model = ProviderModelEntry::factory()->recycle($org)->recycle($connection)
        ->supporting(['text'])->create(['model' => 'gpt-5.1']);

    $components = BotResource::openApiSchemas();

    // TWO FIXTURES, BECAUSE ONE CANNOT DISTINGUISH THE BRANCH THAT MATTERS. Almost every column on
    // this table is nullable and the UNCONFIGURED state — draft, no model, no threshold, no limits,
    // no theme — is the state EVERY bot is in when it is created, so a schema validated only
    // against a populated row would type half the resource as non-nullable and break on the
    // majority of real rows. The configured one proves the populated branch and, in particular,
    // that `theme` is published as an OBJECT: PHP cannot tell an empty array from an empty map, so
    // the unthemed case is the one that would silently emit a JSON array.
    $fixtures = [
        'unconfigured draft' => Bot::factory()->recycle($org)->create(),
        'configured, thresholded, themed, collecting' => Bot::factory()->recycle($org)
            ->usingModel($connection, $model)
            ->published()
            ->thresholdedAt(0.3, EvidenceThresholdScale::Sigmoid)
            ->collecting('We store your email to follow up.')
            ->create([
                'theme' => ['primary' => 'oklch(0.525 0.235 264)', 'radius' => '1rem'],
                'rate_limit_per_minute' => 30,
                'rate_limit_per_day' => 5000,
                'retention_days' => 90,
                'system_instruction' => 'Answer only from the handbook.',
            ]),
    ];

    // BOTH SIDES OF THE INSTRUCTION PROJECTION, AGAINST ONE COMPONENT. `BotResource` nulls
    // `system_instruction` and `answer_style_instruction` for a caller without `bots.manage`, and
    // there is exactly ONE published component for both renderings — which is only sound because
    // both fields are declared `["string", "null"]`. Validating only the management rendering would
    // let the projection publish a value the schema forbids and nothing would say so until a
    // generated client hit it.
    foreach ([true, false] as $withInstructions) {
        foreach ($fixtures as $label => $bot) {
            // THROUGH json_encode AND BACK, deliberately, and this is the only assertion in this
            // file that does it. `toArray()` returns `theme` as a stdClass so the wire carries `{}`
            // rather than `[]` for an unthemed bot, and schemaViolations() types a stdClass as its
            // class name — so validating the PHP array would report a type violation for a body
            // that is correct. Round-tripping validates the shape the client actually receives,
            // which is the shape the document describes.
            /** @var array<string, mixed> $emitted */
            $emitted = (array) json_decode(
                (string) json_encode(
                    (new BotResource($bot, $withInstructions))->toArray(Request::create('/')),
                    JSON_THROW_ON_ERROR,
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            // THE KEYS ARE PRESENT IN BOTH RENDERINGS. `additionalProperties: false` plus the
            // component's `required` list already forces this through schemaViolations(), but it is
            // asserted directly too: a projection that DROPPED a key would make the response shape
            // vary by caller, which is the one thing a generated client cannot absorb.
            expect($emitted)->toHaveKeys(['system_instruction', 'answer_style_instruction']);

            expect(schemaViolations($emitted, $components['BotResource'], $components))->toBe(
                [],
                sprintf(
                    'the published schema disagrees with toArray() for: %s (withInstructions: %s)',
                    $label,
                    $withInstructions ? 'true' : 'false',
                ),
            );
        }
    }

    // The null branch of the timestamps, which the factory cannot produce.
    /** @var array<string, mixed> $emitted */
    $emitted = (array) json_decode(
        (string) json_encode(
            (new BotResource($fixtures['unconfigured draft'], true))->toArray(Request::create('/')),
            JSON_THROW_ON_ERROR,
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $emitted['created_at'] = null;
    $emitted['updated_at'] = null;

    expect(schemaViolations($emitted, $components['BotResource'], $components))->toBe([]);
});

it('publishes exactly the keys BotDomainResource emits, on all three statuses', function (): void {
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->create();

    $components = BotDomainResource::openApiSchemas();

    // ONE FIXTURE PER STATUS, BECAUSE `permits_embedding` IS THE FIELD THAT MATTERS AND IT IS TRUE
    // FOR EXACTLY ONE OF THEM. A single-status fixture would validate the schema against whichever
    // branch the factory happened to default to, and the branch a client actually gates on — the
    // one that decides whether a widget may boot — is the one that would go unchecked.
    $fixtures = [
        'pending, and grants nothing' => BotDomain::factory()->recycle($org)->recycle($bot)
            ->origin('http://localhost:3000')->create(),
        'active, the one status that permits an embed' => BotDomain::factory()->recycle($org)->recycle($bot)
            ->active()->origin('https://alpha.example.com')->create(),
        'disabled, retained so audit entries still resolve' => BotDomain::factory()->recycle($org)->recycle($bot)
            ->disabled()->origin('https://bravo.example.com:8443')->create(),
    ];

    foreach ($fixtures as $label => $row) {
        $emitted = (new BotDomainResource($row))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $components['BotDomainResource'], $components))
            ->toBe([], "the published schema disagrees with toArray() for: {$label}");

        // AND THE PUBLISHED PREDICATE AGREES WITH THE ENUM'S, per row. A resource that recomputed
        // it as `status !== 'disabled'` would validate against this schema perfectly and hand a
        // client the wrong answer for `pending` — the exact negative-test hole
        // `BotDomainStatus::permitsEmbedding()` is written positively to avoid.
        expect($emitted['permits_embedding'])
            ->toBe($row->status === BotDomainStatus::Active, "permits_embedding is wrong for: {$label}");
    }

    // The null branch of the two timestamps, which the factory cannot produce.
    $emitted = (new BotDomainResource($fixtures['pending, and grants nothing']))->toArray(Request::create('/'));
    $emitted['created_at'] = null;
    $emitted['updated_at'] = null;

    expect(schemaViolations($emitted, $components['BotDomainResource'], $components))->toBe([]);

    // AND THE COLLECTION WRAPPER, over the populated set AND the empty one. The empty case is not a
    // formality here: an empty allow-list DENIES EVERY ORIGIN, so it is a state clients must be
    // able to receive and describe rather than one they only meet as a bug.
    $collection = BotDomainCollectionResource::openApiSchemas();

    foreach ([[], array_values($fixtures)] as $index => $rows) {
        $emitted = (new BotDomainCollectionResource($rows))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $collection['BotDomainCollectionResource'], $collection))
            ->toBe([], "the collection schema disagrees with toArray() for fixture set {$index}");
    }
});

it('publishes exactly the keys BotStarterQuestionResource emits', function (): void {
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->create();

    $components = BotStarterQuestionResource::openApiSchemas();

    $rows = [
        BotStarterQuestion::factory()->recycle($org)->recycle($bot)
            ->at(0)->asking('How do I get a refund?')->create(),
        BotStarterQuestion::factory()->recycle($org)->recycle($bot)
            ->at(1)->asking('What are your opening hours?')->create(),
    ];

    foreach ($rows as $row) {
        $emitted = (new BotStarterQuestionResource($row))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $components['BotStarterQuestionResource'], $components))
            ->toBe([], 'the published schema disagrees with toArray()');
    }

    // The null branch of the two timestamps, which the factory cannot produce.
    $emitted = (new BotStarterQuestionResource($rows[0]))->toArray(Request::create('/'));
    $emitted['created_at'] = null;
    $emitted['updated_at'] = null;

    expect(schemaViolations($emitted, $components['BotStarterQuestionResource'], $components))->toBe([]);

    $collection = BotStarterQuestionCollectionResource::openApiSchemas();

    // THE EMPTY CASE IS THE DEFAULT STATE OF EVERY BOT, so a schema validated only against a
    // populated list would describe the shape almost no bot is actually in.
    foreach ([[], $rows] as $index => $set) {
        $emitted = (new BotStarterQuestionCollectionResource($set))->toArray(Request::create('/'));

        expect(schemaViolations($emitted, $collection['BotStarterQuestionCollectionResource'], $collection))
            ->toBe([], "the collection schema disagrees with toArray() for fixture set {$index}");
    }
});

it('publishes the paginated envelope with `meta` beside the collection inside `data`', function (): void {
    $org = Organization::factory()->create();

    $bots = Bot::factory()->recycle($org)->count(3)->create()->all();

    $components = BotCollectionResource::openApiSchemas();

    $query = ListQuery::fromValidated(['per_page' => 2, 'page' => 1], defaultSort: 'id');

    // BOTH THE POPULATED AND THE EMPTY PAGE. `meta` is present on an empty page too — a client that
    // had to branch on its absence would be branching on "did this list have results", which is
    // exactly the question `total` answers — so a schema validated only against a populated page
    // would let the empty one drift.
    foreach (['a populated page' => $bots, 'an empty page' => []] as $label => $rows) {
        $paginator = new LengthAwarePaginator($rows, count($bots), 2, 1);

        /** @var array<string, mixed> $emitted */
        $emitted = (array) json_decode(
            (string) json_encode(
                (new BotCollectionResource($paginator, $query, true))->toArray(Request::create('/')),
                JSON_THROW_ON_ERROR,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // THE ENVELOPE THE ADMIN CONSOLE IS ALREADY WRITTEN AGAINST, asserted as a shape before the
        // schema check: apps/web/src/lib/table/envelope.ts reads `data.<collection>` and
        // `data.meta` and THROWS rather than degrading, so `meta` promoted out of `data` would
        // render the error state on every table in the console.
        expect(array_keys($emitted))->toBe(['bots', 'meta'], "the envelope moved for: {$label}");

        expect(schemaViolations($emitted, $components['BotCollectionResource'], $components))
            ->toBe([], "the published schema disagrees with toArray() for: {$label}");
    }

    // AND THE ITEM COMPONENT IS CONTRIBUTED RATHER THAN RE-DECLARED, so `BotResource` is ONE
    // component in the generated client — the same one the create, read and update actions return.
    // A second, divergent declaration would be a build failure in the dumper; an identical one is a
    // no-op, and neither is what this asserts. What this asserts is that the envelope's `$ref`
    // resolves at all: a dangling `$ref` is not a dump failure, it is a generated client with a
    // missing type, discovered by the person importing it.
    expect($components)->toHaveKeys(['BotResource', 'BotCollectionResource', 'ListMetaResource']);
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
    // screen that had no reason to render one — and EmbeddingCandidate, EmbeddingRejection and
    // EmbeddingDesignation all have nowhere to put a secret by construction, which is what this
    // asserts is still true. The designation matters most of the three here: it is the only field
    // read off the ORGANIZATION row rather than relayed from the data plane, so it is the only one
    // whose source table sits beside `provider_connections` and its credential columns.
    $request = Request::create('/');

    foreach (readinessFixtures() as $label => $fixture) {
        $rendered = (new EmbeddingReadinessResource($fixture['readiness'], $fixture['designated']))
            ->toArray($request);

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

    expect(array_keys($schemas))->toContain(
        'EmbeddingReadinessResource',
        'EmbeddingCandidate',
        'EmbeddingRejection',
        // A COMPONENT OF ITS OWN, because a designation is a genuinely different shape from a
        // candidate: it is the two columns `organizations` stores and carries no `provider`.
        'EmbeddingDesignation',
    );

    $readiness = $schemas['EmbeddingReadinessResource'];

    assert(is_array($readiness) && is_array($readiness['properties']));

    expect(array_keys($readiness['properties']))->toEqualCanonicalizing([
        'ready', 'blocks_ingestion', 'selected', 'designated', 'eligible', 'rejected', 'explanation',
    ]);

    // `designated` IS PUBLISHED THE SAME WAY `selected` IS — `anyOf: [{$ref}, {type: null}]` and
    // not a type array — because a $ref and a type cannot be siblings in one schema object, and
    // packages/contracts' `wireNullable` reads exactly this form. A `nullable: true` here would be
    // OpenAPI 3.0 vocabulary in a 3.1 document and would generate a non-nullable type.
    expect($readiness['properties']['designated']['anyOf'] ?? null)->toBe([
        ['$ref' => '#/components/schemas/EmbeddingDesignation'],
        ['type' => 'null'],
    ]);

    // AND IT IS REQUIRED. Every component in this document is closed AND total, so a key that can
    // be null is still a key that is always present — `designated: null` means "nothing stored",
    // never "the server did not say".
    expect($readiness['required'] ?? [])->toContain('designated');

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

    // (4) A RELAYED 422 THAT DOES CARRY A MAP — the half the relay used to erase. FastAPI's
    // `_handle_validation_error` emits `errors` on EVERY validation envelope, so this body is the
    // common case rather than an exotic one, and the published schema has to admit it.
    //
    // Constructed through KbException::relayed() rather than through Http::fake, because
    // tests/Contract is a no-fake suite (pest-testing NN4) and the property under test here is the
    // RENDERING of the carrier, not the HTTP hop that fills it. The hop itself is proved
    // end-to-end in tests/Feature/EmbeddingConfigurationTest.php.
    Route::get('api/v1/_test/relayed-422', static fn () => throw KbException::relayed(
        'validation',
        'request failed validation',
        422,
        false,
        ['connections.0.model' => ['Input should be a valid string'], 'designated' => ['Field required']],
    ));

    $relayed = currentTest()->getJson('api/v1/_test/relayed-422');

    $relayed->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('errors.designated', ['Field required']);

    $relayedBody = $relayed->json();

    assert(is_array($relayedBody));

    // THE DOTTED KEY IS ASSERTED THROUGH THE DECODED ARRAY, NOT THROUGH assertJsonPath. Pydantic
    // joins its `loc` segments dotted (`connections.0.model`), and `Arr::get` — which every
    // assertJsonPath goes through — has no escape for a dot INSIDE a key: it explodes on '.' and
    // walks segments. Asserting it that way would look right and check a path that does not exist,
    // so the map is read as data instead.
    expect($relayedBody['errors'] ?? null)
        ->toHaveKey('connections.0.model')
        ->and(($relayedBody['errors'] ?? [])['connections.0.model'] ?? null)
        ->toBe(['Input should be a valid string']);

    expect(schemaViolations($relayedBody, ['$ref' => '#/components/schemas/ValidationErrorEnvelope'], $schemas))
        ->toBe([], 'a RELAYED validation envelope carrying a field map does not validate');
});

it('relays the data plane\'s own retry verdict instead of recomputing it (ADR-029 at the relay)', function (): void {
    // THE SECOND HALF OF B1, AND THE ONE WITH TEETH. `_handle_unexpected()` on the data plane
    // raises with `origin=Origin.SELF` and puts `retryable: false` on the wire — "our bug, do not
    // retry". KbException::relayed() used to omit $origin entirely, so the carrier defaulted to
    // ORIGIN_DOWNSTREAM and bootstrap/app.php RECOMPUTED `true` from class-plus-origin. The
    // browser then ran apps/web/src/lib/query/client.ts's full backoff ladder against a defect:
    // finding O1, re-opened one hop later on the exact envelope O1 was about.
    //
    // Note what is deliberately NOT asserted: nothing here reads the STATUS to decide the verdict.
    // The relayed 500 keeps its 500 because the class carries it, and `retryable` comes from the
    // relayed field. Deriving either from the other is the mutation this test exists to catch.
    config(['logging.default' => 'null']);

    Route::get('api/v1/_test/relayed-self-500', static fn () => throw KbException::relayed(
        'internal_dependency',
        'The service could not complete this request.',
        500,
        false,
    ));

    currentTest()->getJson('api/v1/_test/relayed-self-500')
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', false);

    // THE POSITIVE CONTROL, and it is what stops the pin above being satisfied by hard-coding
    // `false`: a genuine downstream brownout still relays 503 / retryable=true on the same class.
    Route::get('api/v1/_test/relayed-downstream-503', static fn () => throw KbException::relayed(
        'internal_dependency',
        'ai-service is draining',
        503,
        true,
    ));

    currentTest()->getJson('api/v1/_test/relayed-downstream-503')
        ->assertStatus(503)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', true);

    // AND A MISSING `retryable` STILL READS AS DOWNSTREAM. An envelope that did not say is not an
    // envelope that said "no": treating an absent field as SELF would suppress every legitimate
    // retry the day a producer omits it.
    Route::get('api/v1/_test/relayed-silent-503', static fn () => throw KbException::relayed(
        'internal_dependency',
        'ai-service is draining',
        503,
    ));

    currentTest()->getJson('api/v1/_test/relayed-silent-503')
        ->assertJsonPath('retryable', true);
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
// command yet". That premise became false when a CI step took the assertion over, and it is TRUE
// AGAIN as of 2026-08-17: `.github/` was deleted, and with it the only thing that ran
// `php artisan kb:dump-openapi --check` against the real committed artifact.
//
// SO THERE IS NOW A REAL GAP, AND IT IS NAMED HERE RATHER THAN LEFT TO BE REDISCOVERED. The test
// above proves the GENERATOR is deterministic and that `--check` CAN fail, using `--path` into a
// temp dir. Nothing proves the COMMITTED document is current. A stale packages/contracts/openapi/
// artifact is therefore invisible until someone runs the command by hand. Restoring the deleted
// default-path variant of this test is the cheapest way to close it, and it is deliberately not
// done here: it would have to be a decision about what the suite guarantees, not a drive-by edit.

// ── security: which routes require the session cookie, and which are reachable by a stranger ──────

it('states a security requirement on every operation, and the guest set is pinned by name', function (): void {
    // WHAT THIS IS THE ABSENCE OF. `securitySchemes.sanctumSession` was declared and the split
    // reached the document only as the PRESENCE OR ABSENCE of an operation-level `security` key.
    // Under OpenAPI an absent one inherits the document-level requirement, and with none declared
    // that resolves to "no credential required" — the right answer for a guest route, arrived at by
    // silence, and indistinguishable from a generator that never asked. Now it is emitted always,
    // `[]` included, so this test can assert the split instead of the reader inferring it.
    //
    // THE GUEST SET IS PINNED BY NAME, not counted (D29). A count says "expected 6, got 7" and a
    // deliberate new guest route fails identically to `auth:sanctum` being dropped from GET /me by
    // accident — which is the failure that matters, since the document would then describe the whole
    // session surface as public and nothing else in the tree would notice.
    $document = dumpDocument()['document'];

    $paths = $document['paths'] ?? null;
    $schemes = $document['components']['securitySchemes'] ?? null;

    assert(is_array($paths) && is_array($schemes));

    $guest = [];
    $authenticated = [];

    foreach ($paths as $path => $operations) {
        assert(is_array($operations));

        foreach ($operations as $method => $operation) {
            assert(is_array($operation));

            $label = strtoupper((string) $method).' '.$path;

            expect(array_key_exists('security', $operation))
                ->toBeTrue("{$label} publishes no `security` key, so whether it needs a session is not stated");

            $requirement = $operation['security'];

            assert(is_array($requirement));

            if ($requirement === []) {
                $guest[] = $label;

                continue;
            }

            $authenticated[] = $label;

            foreach ($requirement as $alternative) {
                assert(is_array($alternative));

                foreach (array_keys($alternative) as $scheme) {
                    // A requirement naming a scheme the document does not declare is a dangling
                    // reference — and it is the shape a rename of `sanctumSession` would take, which
                    // is the constant apps/web/src/proxy.ts pins the session cookie name through.
                    //
                    // array_key_exists rather than ->toHaveKey(): that expectation's SECOND
                    // parameter is an expected VALUE, so a message passed there is silently asserted
                    // as the value of the key. Same family of trap as the toContain() note at the
                    // head of this file.
                    expect(array_key_exists($scheme, $schemes))
                        ->toBeTrue("{$label} requires the security scheme '{$scheme}', which the document does not declare");
                }
            }
        }
    }

    // routes/api_auth.php GROUP A, and nothing else anywhere. Each of the six is guest-reachable for
    // a reason written at its route: the credential does not exist yet (login, register), it is being
    // re-established (forgot-password, reset-password), or the link is opened from a mail client in
    // another browser (email/verify, invitations/preview).
    expect($guest)->toEqualCanonicalizing([
        'POST /api/v1/auth/login',
        'POST /api/v1/auth/register',
        'POST /api/v1/auth/forgot-password',
        'POST /api/v1/auth/reset-password',
        'POST /api/v1/auth/email/verify',
        'POST /api/v1/auth/invitations/preview',
    ], 'the guest-reachable set changed — a route gained or LOST `auth:sanctum`');

    // The positive control. Without it a document in which every operation lost its requirement
    // would satisfy "every operation states one" and fail only the set above, and a future edit that
    // moved a route out of the pinned list could make the whole file vacuous.
    expect($authenticated)->toContain(
        'GET /api/v1/me',
        'POST /api/v1/auth/logout',
        'POST /api/v1/session/organization',
        'GET /api/v1/organizations/{organization}/members',
    );
});

it('derives the requirement from the middleware, so a route that loses auth:sanctum loses it here', function (): void {
    // The half the pinned set above cannot prove: that the published requirement follows the
    // ENFORCING middleware rather than a declaration beside it. Two probe routes, identical but for
    // `auth:sanctum`, both pointed at the same action.
    app('router')->post('api/v1/_probe/guest', [\Tests\Support\ResponseShapeProbe::class, '__invoke']);
    app('router')->post('api/v1/_probe/session', [\Tests\Support\ResponseShapeProbe::class, '__invoke'])
        ->middleware('auth:sanctum');
    app('router')->getRoutes()->refreshNameLookups();

    $paths = dumpDocument()['document']['paths'] ?? null;

    assert(is_array($paths));

    expect($paths['/api/v1/_probe/guest']['post']['security'] ?? null)->toBe([])
        ->and($paths['/api/v1/_probe/session']['post']['security'] ?? null)->toBe([['sanctumSession' => []]]);
});

it('refuses to publish a route authenticated by anything other than the one mechanism', function (): void {
    // D10/D11: the admin surface has exactly one credential, the Sanctum SPA session. A route on a
    // second guard is a finding, not a document change — and answering `[]` for it would publish an
    // authenticated endpoint as public, which is the one direction of this field that is dangerous.
    app('router')->post('api/v1/_probe/second-mechanism', [\Tests\Support\ResponseShapeProbe::class, '__invoke'])
        ->middleware('auth:web');
    app('router')->getRoutes()->refreshNameLookups();

    $path = sys_get_temp_dir().'/kb-openapi-'.getmypid().'-mechanism/core-api.openapi.json';

    expect(Artisan::call('kb:dump-openapi', ['--path' => $path]))->toBe(1);

    expect(Artisan::output())->toContain('auth:web')
        ->and(File::exists($path))->toBeFalse();
});

it('refuses to publish a surface it has no security scheme for, rather than calling it public', function (): void {
    // `rt/` and `sdk/` are in DumpOpenApiCommand::SURFACES and their route files are empty. Their
    // credentials are a resolved chat session and a validated embed origin — both still TODOs in
    // bootstrap/app.php's `runtime` and `sdk` groups — so neither has a scheme to name. Answering
    // `security: []` for the first route added there would publish an endpoint as PUBLIC, which is
    // the one direction of this field that is dangerous. Inert today by construction; it is a
    // forcing function for whoever writes the first hosted-chat route.
    app('router')->post('rt/v1/_probe/chat', [\Tests\Support\ResponseShapeProbe::class, '__invoke']);
    app('router')->getRoutes()->refreshNameLookups();

    $path = sys_get_temp_dir().'/kb-openapi-'.getmypid().'-surface/core-api.openapi.json';

    expect(Artisan::call('kb:dump-openapi', ['--path' => $path]))->toBe(1);

    expect(Artisan::output())->toContain('rt/v1/_probe/chat')
        ->and(File::exists($path))->toBeFalse();
});

// ── the two remaining DumpOpenApiCommand gaps ────────────────────────────────────────────────────

it('refuses an empty #[ResponseShape], naming the action, rather than publishing invalid JSON Schema',
    function (): void {
        // D8 ("no bodyless success anywhere") was a CONVENTION with nothing enforcing it: the first
        // 204 written here would have published `"properties": []` — a JSON array where JSON Schema
        // requires an object — and the finder would have been a downstream generator, days later,
        // with nothing naming the action responsible. The refusal is what teaches the rule.
        app('router')->post('api/v1/_probe/bodyless', [\Tests\Support\ResponseShapeProbe::class, 'store']);
        app('router')->getRoutes()->refreshNameLookups();

        $path = sys_get_temp_dir().'/kb-openapi-'.getmypid().'-bodyless/core-api.openapi.json';

        expect(Artisan::call('kb:dump-openapi', ['--path' => $path]))->toBe(1);

        // The message must name the OFFENDING ACTION. "invalid schema somewhere" is the failure this
        // guard replaces, so a guard that does not say where is barely better than the invalid file.
        expect(Artisan::output())->toContain('ResponseShapeProbe::store')
            ->and(File::exists($path))->toBeFalse();
    });

it('publishes a single-action controller registered as a bare class string', function (): void {
    // `Route::post($uri, SomeController::class)` stores `controller` with no `@method`, and
    // Route::getActionMethod() — `Arr::last(explode('@', …))` — therefore answered with the CLASS
    // NAME. method_exists() then failed and the dump aborted with "is not a controller action",
    // taking the whole document with it, for a route the router dispatches perfectly well.
    // routes/api_admin.php worked around it by spelling `'__invoke'` out; the workaround is now
    // belt-and-braces rather than load-bearing, and this is what says so.
    app('router')->post('api/v1/_probe/invokable', \Tests\Support\ResponseShapeProbe::class);
    app('router')->getRoutes()->refreshNameLookups();

    $paths = dumpDocument()['document']['paths'] ?? null;

    assert(is_array($paths));

    $properties = $paths['/api/v1/_probe/invokable']['post']['responses']['200']['content']['application/json']['schema']['properties'] ?? null;

    expect($properties)->toBe(['data' => ['$ref' => '#/components/schemas/AcknowledgementResource']]);
});

it('does not claim the error envelope is identical to the SSE frame while requiring request_id', function (): void {
    // A PUBLISHED DOCUMENT THAT CONTRADICTED ITSELF. The component's description said it was
    // "identical in the non-streaming body and in the SSE `error` frame" while its `required` list
    // carried `request_id` — and the client-facing SSE `error` frame does not carry that field
    // (kb-internal-api-contracts). packages/contracts/src/envelope.ts types `request_id?` as
    // OPTIONAL precisely so one interface covers both surfaces, and src/sse/events.ts reuses it, so
    // the TypeScript was the safe half and the PROSE was the wrong one.
    //
    // The invariant, not the wording: whichever half moves, the two must keep agreeing. `required`
    // is asserted because it describes what this service really sends and must not be relaxed to
    // match a frame this component does not describe.
    $envelope = dumpDocument()['document']['components']['schemas']['ErrorEnvelope'] ?? null;

    assert(is_array($envelope));

    $description = (string) ($envelope['description'] ?? '');

    expect($envelope['required'] ?? [])->toContain('request_id');

    expect(str_contains($description, 'NON-STREAMING') && str_contains($description, 'omits `request_id`'))
        ->toBeTrue(
            'ErrorEnvelope requires `request_id`, so its description must scope itself to the '
            .'non-streaming body and say the SSE `error` frame omits the field. Either state the '
            .'exception or drop `request_id` from `required` — but a description claiming the two '
            .'are identical is false against this schema.',
        );
});

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

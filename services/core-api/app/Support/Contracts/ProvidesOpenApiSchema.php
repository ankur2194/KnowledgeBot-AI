<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * An API Resource that can describe the JSON it emits.
 *
 * WHY A DECLARATION AND NOT AN INFERENCE. The obvious design is to run `toArray()` over a fixture
 * and read the shape off the result, and it does not work: one fixture cannot distinguish
 * `selected: null` from "this field is an object", nor `eligible: []` from "an array of what". An
 * inferred document would be confidently wrong on exactly the fields a client branches on.
 *
 * So the schema is declared beside `toArray()`, where a reviewer sees both in one screen — and
 * tests/Contract/OpenApiDocumentTest.php then asserts the declaration against what `toArray()`
 * ACTUALLY emits, over a fixture matrix that includes the null and empty cases. A field added to
 * `toArray()` and not to the schema fails the suite; so does the reverse. That is what earns this
 * the right to be published as a generated type in packages/contracts/src/resources/, whose own
 * .gitkeep names hand-written fixtures as the failure it exists to prevent.
 *
 * The schema dialect is JSON Schema 2020-12 — i.e. OpenAPI 3.1's dialect, unmodified.
 */
interface ProvidesOpenApiSchema
{
    /**
     * Every component schema this resource contributes, keyed by component name.
     *
     * The resource's OWN schema must be present under its short class name (e.g.
     * `EmbeddingReadinessResource`); nested object types are contributed as siblings and referenced
     * with `$ref`, so a candidate that appears in three fields is one type in the generated client
     * rather than three anonymous ones.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array;
}

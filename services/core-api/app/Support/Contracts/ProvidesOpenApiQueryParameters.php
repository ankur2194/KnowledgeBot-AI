<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * A FormRequest that validates a QUERY STRING and can describe it as OpenAPI parameters.
 *
 * ── WHY A DECLARATION AND NOT AN INFERENCE FROM `rules()` ─────────────────────────────────────
 *
 * The obvious design is to derive the parameters from the dumped rules manifest, and it does not
 * work for two independent reasons.
 *
 * The first is mechanical: the manifest is a dump of `rules()` AFTER Laravel has stringified it, so
 * a closed set arrives as the literal `in:"id","name","slug","status"` — embedded quotes and all,
 * because `Rule::in()` quotes every member. Reading an `enum` back out of that means parsing
 * Laravel's rule serialization with a splitter that has to know about quoting, escaping and the
 * comma inside a value. That is a parser for a format nobody promised to keep stable.
 *
 * The second is that the manifest DOES NOT CONTAIN the facts a client most needs. A validation rule
 * cannot express a default: `ListQuery::rules()` says `page` is an integer at least 1 and says
 * nothing about it being 1 when absent, because the default is an argument to `fromValidated()` and
 * therefore the endpoint's decision rather than the rule's. Same for `per_page` and `sort`. A
 * generated client built from the rules alone would publish three parameters whose absent behaviour
 * is unstated, which is exactly the part a caller has to guess.
 *
 * So the parameters are declared beside the rules — `ListQuery::openApiQueryParameters()` sits
 * directly next to `ListQuery::rules()` and reads its bounds from the same constants, which is what
 * keeps the two from drifting — and `tests/Contract/OpenApiDocumentTest.php` asserts the published
 * document against them.
 *
 * ── THE SAME SHAPE AS `ProvidesOpenApiSchema`, ONE LAYER OVER ─────────────────────────────────
 *
 * That interface lets a Resource describe the JSON it EMITS; this one lets a FormRequest describe
 * the query string it ACCEPTS. Between them, `#[ResponseShape]` on the controller method names the
 * status codes. A request BODY needs none of this: it is already fully described by the dumped
 * rules manifest the operation points at, because a body's shape IS its rule set.
 *
 * The schema dialect is JSON Schema 2020-12 — i.e. OpenAPI 3.1's dialect, unmodified.
 */
interface ProvidesOpenApiQueryParameters
{
    /**
     * The query-string parameters this request accepts, as OpenAPI parameter objects.
     *
     * Every entry MUST carry `in: query`. `DumpOpenApiCommand` appends these AFTER the path
     * parameters it derives from the route's URI, and it does not reorder or de-duplicate them — a
     * declaration that collided with a path placeholder would publish the name twice, which is why
     * the contract suite asserts the path parameters are unchanged and unique.
     *
     * `required` is written out on every entry rather than left to OpenAPI's default. The default
     * is `false`, so omitting it says the same thing by silence — and silence cannot be told apart
     * from a generator that never asked the question, which is the argument `securityFor()` already
     * makes about an omitted `security` key.
     *
     * @return list<array<string, mixed>>
     */
    public static function openApiQueryParameters(): array;
}

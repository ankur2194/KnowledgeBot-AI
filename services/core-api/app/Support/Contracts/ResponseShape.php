<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use Attribute;

/**
 * The response body a controller action returns, declared where the action decides it.
 *
 * WHY THIS IS NOT DERIVED FROM THE RETURN TYPE. Two of the three actions in this service return
 * `JsonResponse`, and the third returns a Resource that Laravel then WRAPS in `data`. The wrapping
 * is the interesting part and it is a controller decision, not a resource one:
 *
 *   GET/PUT  /embedding-configuration   -> {"data": EmbeddingReadinessResource}
 *   POST     /provider-connections      -> {"data": ProviderConnectionResource,
 *                                           "embedding_readiness": EmbeddingReadinessResource}
 *
 * The same resource appears under two different keys at two different nesting levels. A return-type
 * reflection would publish both as the same thing and a client generated from it would be wrong on
 * one of them. So the envelope is declared here and the FIELDS come from the resource — neither
 * half is transcribed.
 *
 * `errors` is an explicit list rather than a default set, because "which failures can this endpoint
 * actually produce" is not derivable either: 409 comes from an `abort_unless` on organization
 * status that only two of the three actions perform, and 422 is unreachable on an action with no
 * request body. A default set would document responses that cannot happen, which is the same defect
 * as omitting ones that can.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ResponseShape
{
    /**
     * @param  int  $status  the success status this action returns
     * @param  array<string, class-string<ProvidesOpenApiSchema>>  $properties  response key => resource
     * @param  list<int>  $errors  every error status this action can render, from the one envelope
     */
    public function __construct(
        public int $status,
        public array $properties,
        public string $description = '',
        public array $errors = [],
    ) {}
}

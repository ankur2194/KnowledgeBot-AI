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
     * @param  array<string, class-string<ProvidesOpenApiSchema>>  $properties  response key => resource.
     *                                                                          EMPTY IS PERMITTED ONLY FOR A STREAM — see `$mediaType`. On a JSON action an empty
     *                                                                          set is refused by the dumper, because it would publish `"properties": []`, a JSON
     *                                                                          ARRAY where JSON Schema requires an object.
     * @param  list<int>  $errors  every error status this action can render, from the one envelope
     * @param  string  $mediaType  THE ONE THING A RETURN TYPE GENUINELY CANNOT ANSWER FOR A STREAM.
     *
     *              A `StreamedResponse` is `StreamedResponse` whatever it carries, and the SSE relay
     *              returns one whose body is `text/event-stream` — a sequence of frames, not a JSON
     *              document. Publishing it as `application/json` with a `data` object would describe
     *              a body no client will ever receive, and a generated client built from that
     *              description would call `.json()` on a stream and hang.
     *
     *              When this is not `application/json`, the dumper publishes the media type with a
     *              string schema and a pointer to the frame contract, and it does NOT compose
     *              `properties` — which is why the empty-properties refusal is scoped to the JSON
     *              case. The SSE frame schema lives in `packages/contracts/src/sse/events.ts` and is
     *              mirrored field-for-field by `app/contracts/internal/chat.py`; it is deliberately
     *              NOT transcribed into this document, because a third copy of a union three clients
     *              already parse is a third thing to keep in step.
     */
    public function __construct(
        public int $status,
        public array $properties,
        public string $description = '',
        public array $errors = [],
        public string $mediaType = 'application/json',
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grant — this bot may answer from this source — on the wire.
 *
 * ── THE SOURCE IS NESTED RATHER THAN FLATTENED, AND IT IS `SourceResource` ────────────────────
 *
 * A console rendering "which documents can this bot use" needs the source's name and lifecycle
 * state beside the grant, and the alternative to nesting the existing component is a second,
 * narrower source shape — which is exactly the divergence `contract-steward` exists to catch. One
 * component means the assignment list, the source list and the source detail all hand a client the
 * same type, and a field added to `SourceResource` reaches all three without a second edit.
 *
 * IT IS PASSED IN RATHER THAN READ OFF THE RELATION. `Model::shouldBeStrict()` forbids lazy
 * loading, so `$assignment->source` outside an eager load is an exception; and an eager load's
 * scoping comes from the ambient `TenantContext` unless the caller constrains it, which is the
 * backstop rather than the mechanism. The repository constrains it explicitly and the collection
 * resource hands the result here, so this class never issues a query and never depends on which
 * context happened to be bound.
 *
 * ── `enabled` IS ONE TERM OF FOUR AND THE SCHEMA SAYS SO ─────────────────────────────────────
 *
 * There is deliberately NO derived `grants_retrieval` boolean here, and the omission is the
 * opposite call from `BotDomainResource::permits_embedding` — which exists because `status ===
 * 'active'` is genuinely the whole of that row's answer. Here it is not: whether this bot can
 * actually retrieve from this source is the AND of the organization, this row's `enabled`, the
 * source's status and the item's active-version pointer, and three of those four live somewhere
 * other than this row. A boolean named `grants_retrieval` on an assignment to a source with no
 * published version would be a lie a console would render as a green tick — the same reason
 * `SourceResource` calls its own field `status_permits_retrieval` rather than `retrievable`.
 *
 * ── WHAT IS NOT RENDERED ─────────────────────────────────────────────────────────────────────
 *
 * `organization_id` — the client asked for this row through a URL that already named the
 * organization, so echoing the ownership column adds nothing and puts a tenant identifier into
 * every cached response body. The same call `BotDomainResource` and `ProviderModelResource` make.
 *
 * `bot_id` — likewise in the path, and it is the ownership edge inside the tenant: a client that
 * reads it back is one step from posting it, and there is no endpoint that accepts it.
 *
 * @property-read BotSourceAssignment $resource
 */
final class BotSourceAssignmentResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(
        BotSourceAssignment $resource,
        private readonly KnowledgeSource $source,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->resource;

        return [
            'id' => $assignment->id,
            // The ULID of the granted source, published beside the nested object rather than only
            // inside it: it is the value a client sends back to create the same grant on another
            // bot, and digging it out of a nested object to do that is how clients end up copying
            // the whole object instead.
            'source_id' => $assignment->source_id,
            'priority' => $assignment->priority,
            'enabled' => $assignment->enabled,
            'created_at' => $assignment->created_at?->toIso8601String(),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
            'source' => (new SourceResource($this->source))->toArray($request),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        // CONTRIBUTED, NOT RE-DECLARED, so `SourceResource` is ONE component in the generated
        // client — the same one the source list, the source detail and this list all return. The
        // dumper compares bodies when a component name appears twice, so an identical contribution
        // is a no-op and a divergent one is a build failure.
        return SourceResource::openApiSchemas() + [
            'BotSourceAssignmentResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One grant: this bot may answer from this knowledge source. It is '
                    .'the row `bot_ids` — one of the four mandatory vector-search filter terms — is '
                    .'resolved from, so it is what makes a corpus REACHABLE from a bot rather than '
                    .'a preference about it.',
                'required' => [
                    'id', 'source_id', 'priority', 'enabled', 'created_at', 'updated_at', 'source',
                ],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the assignment. This is what DELETE addresses — '
                            .'not the source id, because the grant is the resource being withdrawn.',
                    ],
                    'source_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the granted source, identical to `source.id`. '
                            .'Published at the top level because it is the value a client posts to '
                            .'create the equivalent grant on another bot.',
                    ],
                    'priority' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'description' => 'The operator\'s preference between this bot\'s sources. A '
                            .'TIE-BREAK a retrieval stage may consult and NEVER a filter: a lower '
                            .'priority must not make a source unretrievable, because "less '
                            .'important" and "not visible" are different statements and only the '
                            .'second one is `enabled`. Zero is the default and the floor. Nothing '
                            .'in this platform ranks on it yet, so treat it today as a stable '
                            .'display order.',
                    ],
                    'enabled' => [
                        'type' => 'boolean',
                        'description' => 'Whether this grant is live. Distinct from disabling the '
                            .'SOURCE, which removes it from every bot at once. It is ONE TERM OF '
                            .'FOUR and never the whole answer — reachability is the AND of the '
                            .'organization, this flag, the source\'s status and the item\'s '
                            .'active-version pointer — so do not render it as "this bot is '
                            .'answering from this document".',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. When the grant was made.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. In practice equal to `created_at`: '
                            .'there is no endpoint that edits an assignment, because the audit '
                            .'catalog defines a created and a deleted operation and no updated '
                            .'one. Changing the priority or the off switch is a delete and a '
                            .'re-create, and the trail then says both things happened.',
                    ],
                    'source' => [
                        '$ref' => '#/components/schemas/SourceResource',
                        'description' => 'The granted source, in the same shape the source list and '
                            .'the source detail publish. It is always present: the composite '
                            .'foreign key `bot_source_assignments_source_same_org` refuses an '
                            .'assignment whose source is absent or belongs to another '
                            .'organization, so there is no null case to branch on.',
                    ],
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SourceState;
use App\Models\KnowledgeSource;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One knowledge source, as an authenticated admin sees it.
 *
 * ── NO FIELD HERE IS DERIVED FROM ANOTHER ORGANIZATION'S ROW, AND NONE IS A SECRET ───────────
 *
 * A source carries no credential and there is nothing on this table to redact. What it does carry
 * is `origin_url`, which is the one column on it a security review reads — it is published because
 * the operator typed it and needs to see it, and it is the SAME value the audit trail echoes.
 *
 * ── `status_permits_retrieval` IS ONE TERM OF FOUR AND SAYS SO ───────────────────────────────
 *
 * `SourceState::isRetrievable()` answers the STATUS term of the four mandatory Qdrant filter terms
 * and nothing else. Reachability is the AND of the status, the ACTIVE-VERSION POINTER, the bot
 * assignment and the organization — three of which live somewhere other than this row. The field is
 * named for what it is rather than `retrievable`, because a boolean called `retrievable` on a
 * source with no published version would be a lie a console would render as a green tick.
 *
 * ── `deleted_at` AND `purged_at` ARE BOTH PUBLISHED, AND THEY ARE NOT THE SAME CLAIM ─────────
 *
 * `deleted_at` is the moment the source stopped being retrievable — logical exclusion, immediate,
 * the only thing a customer experiences. `purged_at` is the moment the background purge was
 * VERIFIED, which is what a retention or erasure obligation is measured against. Collapsing them
 * would make "we removed it" and "we proved we removed it" one claim, and only one of those is
 * defensible. A console showing a source mid-purge needs both.
 */
final class SourceResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(KnowledgeSource $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = $this->resource;

        return [
            'id' => $source->id,
            'type' => $source->type->value,
            'name' => $source->name,
            'description' => $source->description,
            'origin_url' => $source->origin_url,
            'status' => $source->status->value,
            'status_permits_retrieval' => $source->status->isRetrievable(),
            // Whether the pipeline is currently working on it — `Queued` is deliberately NOT one of
            // these (see the enum): a version queued for an hour is a scheduling problem and a
            // version parsing for an hour is a document problem, and a console that conflated them
            // would show a spinner for both and explain neither.
            'status_is_processing' => $source->status->isProcessing(),
            'tags' => $source->tags,
            'effective_at' => $source->effective_at?->toIso8601String(),
            'expires_at' => $source->expires_at?->toIso8601String(),
            'created_by' => $source->created_by,
            'created_at' => $source->created_at?->toIso8601String(),
            'updated_at' => $source->updated_at?->toIso8601String(),
            'deleted_at' => $source->deleted_at?->toIso8601String(),
            'purged_at' => $source->purged_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'SourceResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One knowledge source — the admin\'s unit of intent: one upload, '
                    .'one sitemap, one crawl configuration, one paste. It is NOT the unit of '
                    .'versioning: a crawl gives one source hundreds of independently-versioned '
                    .'items, so there is no active-version pointer on this shape and there never '
                    .'will be one.',
                'required' => [
                    'id', 'type', 'name', 'description', 'origin_url', 'status',
                    'status_permits_retrieval', 'status_is_processing', 'tags', 'effective_at',
                    'expires_at', 'created_by', 'created_at', 'updated_at', 'deleted_at',
                    'purged_at',
                ],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the source.'],
                    'type' => [
                        'type' => 'string',
                        'enum' => \App\Enums\SourceType::values(),
                        'description' => 'What kind of thing this is, and deliberately not what '
                            .'kind of FILE it is: a PDF, a DOCX, a spreadsheet and a slide deck are '
                            .'all `file`, because the parser decides from the sniffed MIME type '
                            .'rather than from a column somebody typed.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The operator\'s label for the whole source. '
                            .'TENANT-AUTHORED TEXT: escape it at render, in every client, because '
                            .'only the renderer knows the context it is entering. Never blank.',
                    ],
                    'description' => [
                        'type' => ['string', 'null'],
                        'description' => 'Optional operator note. Null means not set; it is never '
                            .'an empty string, because two spellings of "unset" are two branches '
                            .'every renderer has to have and one of them forgets.',
                    ],
                    'origin_url' => [
                        'type' => ['string', 'null'],
                        'description' => 'The crawl target. Present exactly when `type` is `url` '
                            .'and null otherwise — the database enforces the equality in both '
                            .'directions, so a `file` source can never carry one. This is the '
                            .'column that answers "which sources cause this platform to make '
                            .'outbound requests".',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => SourceState::values(),
                        'description' => 'One of the fifteen lifecycle states, in contract order. '
                            .'`ready` and `ready_with_warnings` are IDENTICAL for retrieval — the '
                            .'warnings are advisory and never a retrieval predicate — and they are '
                            .'two values rather than one because collapsing them loses the only '
                            .'signal that says "this document parsed badly and published anyway".',
                    ],
                    'status_permits_retrieval' => [
                        'type' => 'boolean',
                        'description' => 'Whether the STATUS term of the retrieval filter is '
                            .'satisfied — ONE OF FOUR, and never the whole answer. Reachability is '
                            .'the AND of this, the item\'s active-version pointer, the bot '
                            .'assignment and the organization. Do not render it as "this source is '
                            .'answering".',
                    ],
                    'status_is_processing' => [
                        'type' => 'boolean',
                        'description' => 'Whether an ingestion run is in flight. `queued` is '
                            .'deliberately NOT included: a source queued for an hour is a '
                            .'scheduling problem and one parsing for an hour is a document '
                            .'problem, and the distinction is what a stuck-run sweep keys off.',
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Operator labels. Always present, empty when nobody has '
                            .'tagged the source; never null, and never containing a blank element.',
                    ],
                    'effective_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'Start of the retrieval window, ISO 8601 with offset. Null '
                            .'means "from always".',
                    ],
                    'expires_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'End of the retrieval window. Null means "until further '
                            .'notice"; when set it is always after `effective_at`.',
                    ],
                    'created_by' => [
                        'type' => ['string', 'null'],
                        'description' => 'ULID of the administrator who added the source. Null for '
                            .'anything the platform created without a person behind it.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset.',
                    ],
                    'updated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Moves on a metadata edit AND on '
                            .'every lifecycle transition, including the ones the ingestion pipeline '
                            .'reports, so it is a freshness signal rather than an edit log.',
                    ],
                    'deleted_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'When the source stopped being retrievable — phase 1 of a '
                            .'two-phase removal, immediate, and the only half a customer '
                            .'experiences. Non-null with a null `purged_at` means the purge is '
                            .'still in flight.',
                    ],
                    'purged_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'When the background purge was VERIFIED — vectors, objects '
                            .'and cache entries confirmed gone. This is the timestamp a retention '
                            .'or erasure obligation is measured against, and it is a different '
                            .'claim from `deleted_at`: one says we removed it, the other says we '
                            .'proved it.',
                    ],
                ],
            ],
        ];
    }
}

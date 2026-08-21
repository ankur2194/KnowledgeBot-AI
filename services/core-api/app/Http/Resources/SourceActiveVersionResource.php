<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SourceState;
use App\Services\Sources\ActiveSourceVersion;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one live version of a single-item source, on the wire.
 *
 * ── IT IS NULLABLE ON ITS PARENT, AND THAT IS THE WHOLE DESIGN ───────────────────────────────
 *
 * Activation is a POINTER — `source_items.current_version_id` — and there is no source-level
 * counterpart. `SourceResource`'s docblock says so and is not contradicted here: this shape is
 * populated only for a source with EXACTLY ONE item, where the live set has one member and naming
 * it is not an approximation. A crawl has hundreds of independently-versioned items, so its
 * `active_version` is null and `active_version_count` is what answers the question.
 *
 * ── THE FOUR CONFIG VERSIONS ARE THE POINT OF PUBLISHING THIS AT ALL ─────────────────────────
 *
 * All four are ingest-key components, so the difference between them and the platform's current
 * configuration is exactly the set of reasons a reprocess would produce a NEW version rather than
 * dedupe against this one and report "already processed". That is the question an operator asks in
 * front of a document that read badly, and it is unanswerable from `status`.
 *
 * `content_hash` and `ingest_key` are deliberately absent — neither is a secret, both are sha256
 * hexdigests of public inputs, and both are already on the `source.version.activated` audit row for
 * replayability. What they are not is a question a console asks.
 *
 * @property-read ActiveSourceVersion $resource
 */
final class SourceActiveVersionResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(ActiveSourceVersion $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'id' => $version->id,
            // The ITEM this version belongs to, which is the unit of independent versioning. It is
            // published even though this shape only exists for a one-item source, because a client
            // that later grows a per-item view needs the same identifier and should not learn it
            // from a different field.
            'source_item_id' => $version->sourceItemId,
            'version_number' => $version->versionNumber,
            'status' => $version->status->value,
            'activated_at' => $version->activatedAt?->toIso8601String(),
            'parser_cfg_version' => $version->parserCfgVersion,
            'ocr_cfg_version' => $version->ocrCfgVersion,
            'chunker_cfg_version' => $version->chunkerCfgVersion,
            'embedding_model_version' => $version->embeddingModelVersion,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'SourceActiveVersionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The immutable processing result a single-item source is currently '
                    .'serving. It is what an item\'s active-version pointer names; there is no '
                    .'source-level pointer, so this shape is present only when the source has '
                    .'exactly one item.',
                'required' => [
                    'id', 'source_item_id', 'version_number', 'status', 'activated_at',
                    'parser_cfg_version', 'ocr_cfg_version', 'chunker_cfg_version',
                    'embedding_model_version',
                ],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the version.'],
                    'source_item_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the item this version belongs to. The item — one '
                            .'uploaded file, one crawled page, one paste — is the unit of '
                            .'independent versioning, not the source.',
                    ],
                    'version_number' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Monotonic per item, starting at 1. A gap means a version '
                            .'was created and never activated, which is what a failed run leaves.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => SourceState::values(),
                        'description' => 'The version\'s own lifecycle state, which may differ from '
                            .'the source\'s: a source shows the state of the run in flight while '
                            .'this shows the state of what is currently SERVING. `ready` and '
                            .'`ready_with_warnings` are identical for retrieval.',
                    ],
                    'activated_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. When this version became the one '
                            .'answering. Never null in practice for a version an item points at, '
                            .'because the pointer switch and this timestamp are written in one '
                            .'transaction.',
                    ],
                    'parser_cfg_version' => [
                        'type' => 'string',
                        'description' => 'The document-parser configuration this version was '
                            .'produced under. An ingest-key component: if it differs from the '
                            .'platform\'s current one, a reprocess produces a new version rather '
                            .'than deduplicating against this one.',
                    ],
                    'ocr_cfg_version' => [
                        'type' => 'string',
                        'description' => 'The OCR configuration. Also an ingest-key component — '
                            .'which is the whole reason it is a separate field: a content-only key '
                            .'would make an OCR retune silently no-op and report "already '
                            .'processed".',
                    ],
                    'chunker_cfg_version' => [
                        'type' => 'string',
                        'description' => 'The chunking configuration this version was split under.',
                    ],
                    'embedding_model_version' => [
                        'type' => 'string',
                        'description' => 'Provider, model id, returned vector width and a digest '
                            .'over a fixed probe set — e.g. '
                            .'`emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1`. NOT a bare '
                            .'vendor model name: that is an alias, and an alias can be re-pointed '
                            .'at different weights with no diff anywhere. Two versions with '
                            .'different values here are in different vector spaces and their scores '
                            .'are not comparable.',
                    ],
                ],
            ],
        ];
    }
}

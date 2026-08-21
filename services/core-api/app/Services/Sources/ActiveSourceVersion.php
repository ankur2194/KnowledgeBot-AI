<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;
use Carbon\CarbonImmutable;

/**
 * The one live version of a SINGLE-ITEM source, as a value.
 *
 * ── WHY IT IS NULLABLE ON THE PROJECTION THAT CARRIES IT ─────────────────────────────────────
 *
 * Activation is a POINTER — `source_items.current_version_id` — and there is no source-level
 * counterpart, deliberately: a crawl gives one source hundreds of independently-versioned items, so
 * "the current version of this source" is a SET and a join rather than a value.
 * `SourceResource`'s docblock states that in as many words and is not contradicted here, because
 * this type is only ever populated for a source with EXACTLY ONE item — an upload, a paste, a
 * single file — where the set has one member and naming it is not an approximation. For every other
 * source `SourceContentSummary::$activeVersion` is null and `activeVersionCount` is the answer.
 *
 * ── THE FOUR CONFIG VERSIONS ARE HERE AND THE TWO DIGESTS ARE NOT ────────────────────────────
 *
 * `parser_cfg_version`, `ocr_cfg_version`, `chunker_cfg_version` and `embedding_model_version` are
 * what a console needs to answer "why does this document read badly, and would reprocessing change
 * anything" — all four are ingest-key components, so a difference between them and the current
 * configuration is exactly the set of reasons a reprocess would produce a new version rather than
 * dedupe against this one.
 *
 * `content_hash` and `ingest_key` are deliberately absent. Neither is a secret — both are sha256
 * hexdigests of public inputs — but neither answers a question a console asks: they are the
 * IDENTITY of an ingestion run, they are already echoed on the `source.version.activated` audit
 * row for replayability, and a 64-character hex string on a settings page is a value people copy
 * into support tickets without knowing what it means.
 */
final readonly class ActiveSourceVersion
{
    public function __construct(
        public string $id,
        public string $sourceItemId,
        public int $versionNumber,
        public SourceState $status,
        public ?CarbonImmutable $activatedAt,
        public string $parserCfgVersion,
        public string $ocrCfgVersion,
        public string $chunkerCfgVersion,
        public string $embeddingModelVersion,
    ) {}
}

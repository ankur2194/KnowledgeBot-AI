<?php

declare(strict_types=1);

namespace App\Services\Sources;

use RuntimeException;

/**
 * The six values that make a `source_versions` row what it is — and every one of them is COMPUTED
 * BY THE DATA PLANE and arrives on a callback.
 *
 * ── WHY LARAVEL CANNOT COMPUTE THESE, WHICH IS WHY THIS CLASS IS A PARSER AND NOT A BUILDER ───
 *
 * `parser_cfg_version`, `ocr_cfg_version` and `chunker_cfg_version` are digests over the Docling,
 * RapidOCR and chunker option maps plus the resolved model-pin commit shas, composed by
 * `services/ai-service/app/ingestion/cfg_version.py`. `embedding_model_version` is
 * `EmbeddingModelIdentity.version` — provider, model id, the width the vendor actually returned,
 * and a digest over a fixed five-string probe set (ADR-035), so it cannot be known without making
 * the call. `content_hash` is the RAW BYTES for an upload — which Laravel does hold — and the
 * NORMALIZED content for a crawl, which only the fetcher has. And `ingest_key` is a sha256 over all
 * of those in a fixed order (`app/ingestion/identity.py`, `INGEST_KEY_PARTS`).
 *
 * A control plane that guessed any one of them would produce a key that matches nothing, and the
 * failure is silent in the expensive direction: `UNIQUE (source_item_id, ingest_key)` would stop
 * deduplicating, so every resubmission would mint a new version and re-embed the corpus at a
 * provider's per-token price.
 *
 * ── AND WHY LARAVEL STILL WRITES THE ROW ──────────────────────────────────────────────────────
 *
 * `source_versions` is not in `services/ai-service/app/db/writes.py`'s `ALLOWED_TABLES` and must
 * never be: ADR-033 property 2 fails immediately, because Laravel serves this row. So the data
 * plane computes the identity and Laravel persists it. That is the same split as activation — the
 * verification is a data-plane fact only the worker that wrote the points can establish, and the
 * pointer flip is the one act that decides what every tenant's next query sees.
 */
final readonly class VersionIdentity
{
    public function __construct(
        public string $contentHash,
        public string $ingestKey,
        public string $parserCfgVersion,
        public string $ocrCfgVersion,
        public string $chunkerCfgVersion,
        public string $embeddingModelVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            contentHash: self::str($payload, 'content_hash'),
            ingestKey: self::str($payload, 'ingest_key'),
            parserCfgVersion: self::str($payload, 'parser_cfg_version'),
            ocrCfgVersion: self::str($payload, 'ocr_cfg_version'),
            chunkerCfgVersion: self::str($payload, 'chunker_cfg_version'),
            embeddingModelVersion: self::str($payload, 'embedding_model_version'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            // RAISED RATHER THAN DEFAULTED TO ''. Every one of these columns carries a NOT NULL and
            // a non-blank CHECK, so an empty string reaches the database as a constraint violation
            // rendered 500 — and a blank config version is worse than a rejected one: it is a
            // component of the ingest key that contributes nothing, which makes the version trigger
            // it represents permanently undetectable.
            throw new RuntimeException(
                "The ingestion callback's version identity is missing `{$key}`. Every component of "
                .'the ingest key is required: one that is absent makes the version trigger it '
                .'represents a silent no-op, and the admin sees "already processed" forever.',
            );
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceType;
use Carbon\CarbonImmutable;

/**
 * Everything a caller may decide about a source at creation time.
 *
 * ── WHAT IS NOT HERE IS THE POINT ─────────────────────────────────────────────────────────────
 *
 * No `organization_id`: it comes from the authenticated context and is a positional argument on
 * every repository method. No `status`: a source is created `Draft`, always, and every move out of
 * it goes through `SourceState::canTransitionTo()`. No `created_by`: an attribution a client can
 * set is not one. No `storage_key`, `content_hash`, `mime` or `byte_size`: those are per ITEM and
 * are DERIVED — `kb-security-baseline`'s upload rules refuse a client-declared MIME and a
 * client-chosen storage key is a path traversal with a 201.
 *
 * ── `content` IS THE ONE FIELD THAT IS NOT A COLUMN ───────────────────────────────────────────
 *
 * A `text` source has no object behind it and no URL to refetch, so the pasted prose IS the
 * content and it has to be persisted somewhere before a worker can ever read it. It goes to object
 * storage under this organization's own prefix, exactly as an upload would, and the item row keeps
 * the key, the sniffed-equivalent MIME (`text/plain`, because we generated the bytes), the size and
 * the sha256 of the RAW BYTES — which is the hash rule for uploads, not the normalized-content rule
 * crawls use (`kb-source-lifecycle`, Gotchas).
 *
 * It is null for a `url` source, and `SourceService` refuses the mismatch in both directions
 * rather than silently ignoring the field that does not apply.
 */
final readonly class NewSource
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public SourceType $type,
        public string $name,
        public ?string $description,
        public ?string $originUrl,
        public ?string $content,
        public array $tags,
        public ?CarbonImmutable $effectiveAt,
        public ?CarbonImmutable $expiresAt,
    ) {}
}

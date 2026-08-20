<?php

declare(strict_types=1);

namespace App\Services\Sources;

/**
 * One `source_items` row, as the service decided it, before the repository writes it.
 *
 * ── WHY THIS TYPE EXISTS AT ALL: A SOURCE HAS N ITEMS AND USED TO HAVE 1 ─────────────────────
 *
 * `KnowledgeSourceRepositoryInterface::create()` took the item's five columns as five loose
 * positional scalars — `$canonicalKey, $storageKey, $contentHash, $mime, $byteSize` — which works
 * exactly as long as every source has one item. An upload batch has up to
 * `StoreSourceRequest::MAX_FILES` of them, and the `source_items` migration is emphatic that this is
 * not a special case to be bolted on: "There is no special case for a one-item source and there must
 * never be one… code that reads a version pointer off the SOURCE for uploads and off the ITEM for
 * crawls diverges the first time somebody adds a second file to an existing source — and it diverges
 * silently, because both branches work in isolation."
 *
 * So the scalars became a list of these. A pasted-text source and a crawl target each build ONE, and
 * the code path they take is the same one a ten-file batch takes.
 *
 * ── EVERY FIELD IS SERVER-DERIVED, INCLUDING THE ONE THAT LOOKS TENANT-AUTHORED ──────────────
 *
 * `displayName` is the user's filename and is the only value here they chose — and it reaches this
 * object only after `UploadIntake` has NFKC-normalized it and proved it is a bare filename, because
 * `source_items_display_name_is_not_a_path` refuses a separator, a control character and the two
 * directory-relative names at the INSERT. It is a column and never a path;
 * `kb-security-baseline/references/file-upload-safety.md` allows it back out only inside
 * `Content-Disposition: attachment; filename*=UTF-8''<pct-encoded>`.
 *
 * `canonicalKey` for an upload IS the generated storage key (the `source_items` migration:
 * "For a crawl it is the normalized URL; for an upload it is the generated object key"), which makes
 * `source_items_org_source_canonical` the index that refuses the same object twice inside one source.
 *
 * `mime` is the SNIFFED type. There is no field on this object a client's `Content-Type` could reach.
 */
final readonly class NewSourceItem
{
    /**
     * @param  string  $canonicalKey  the item's stable identity within its source: a normalized URL
     *                                for a crawl, `CanonicalKey::TEXT` for a paste, the generated
     *                                object key for an upload
     * @param  string|null  $url  the live URL a citation can link to, when there is one
     * @param  string|null  $title  human-readable; the source name for a paste, the filename for an
     *                              upload, the page title for a crawl
     * @param  string|null  $displayName  the user's filename, normalized. Null for anything that did
     *                                    not arrive as a file
     * @param  string|null  $storageKey  built by `App\Support\Kb\ObjectKey`, never interpolated
     * @param  string|null  $contentHash  sha256 hexdigest, lowercase — the four storage columns are
     *                                    nullable together and `source_items_stored_object_is_complete`
     *                                    refuses the half-populated combination
     */
    public function __construct(
        public string $canonicalKey,
        public ?string $url,
        public ?string $title,
        public ?string $displayName,
        public ?string $storageKey,
        public ?string $contentHash,
        public ?string $mime,
        public ?int $byteSize,
    ) {}
}

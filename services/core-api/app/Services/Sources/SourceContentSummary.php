<?php

declare(strict_types=1);

namespace App\Services\Sources;

/**
 * What is actually INSIDE a knowledge source, as of its live versions.
 *
 * ── WHY THIS EXISTS: THE CONSOLE COULD NOT STATE THE CONSEQUENCE OF A DELETE IN NUMBERS ──────
 *
 * `SourceService::delete()` builds a `SourceChildSummary` and writes it to the AUDIT ROW, where no
 * client can read it, and `SourceResource` publishes no counts at all — so the sources list shipped
 * a delete confirmation that could describe its consequence only in prose, while `kb-ui-patterns`
 * requires the consequence in numbers. This type is what makes those numbers available to a client
 * without a second endpoint and without exporting the audit trail.
 *
 * ── EVERY NUMBER IS OVER THE LIVE VERSIONS AND NOTHING ELSE ──────────────────────────────────
 *
 * "Live" means named by an item's `current_version_id` — the POINTER, which is the definition of
 * active (`kb-source-lifecycle`), and never `activated_at IS NOT NULL AND retired_at IS NULL`,
 * which is an inference from a partial unique index and is the reading that would survive somebody
 * dropping it. `KnowledgeSourceRepositoryInterface::hasWarnedActiveVersion()` already states that
 * rule and this summary follows it.
 *
 * The consequence is worth stating because it makes a number look wrong when it is right: a source
 * whose ingestion has never completed has an item, no pointer, and therefore ZERO pages, elements
 * and chunks — even though rows for a superseded or an unpublished version may exist in the table.
 * Counting those instead would tell an operator that deleting the source removes chunks nothing can
 * retrieve, which is true and is not what the confirmation is asking.
 *
 * ── THE PREVIEW IS BOUNDED, AND IT IS UNTRUSTED DATA ─────────────────────────────────────────
 *
 * It is `document_elements.text`, which is tenant-authored content extracted from a document a
 * tenant uploaded — non-negotiable 7 — rendered on a page an administrator reads. Two bounds, both
 * of them in the repository rather than here: the number of elements read, and the number of
 * characters taken from each, so the query cannot return a hundred megabytes for one badly-shaped
 * PDF. `previewTruncated` says the bound was reached, so a client can render an ellipsis and a link
 * rather than implying the document is that short.
 *
 * NOTHING HERE ESCAPES IT. Escaping is the renderer's, because only the renderer knows the context
 * the string is entering — the same statement `SourceResource` makes about `name`.
 */
final readonly class SourceContentSummary
{
    /**
     * @param  int  $itemCount  independently-versioned items: one per uploaded file, one per
     *                          crawled page, one for a paste. Every source has at least one once it
     *                          has been submitted
     * @param  int  $activeVersionCount  how many of those items currently point at a version. Zero
     *                                   means nothing about this source is retrievable, whatever
     *                                   its `status` says
     * @param  ActiveSourceVersion|null  $activeVersion  populated ONLY for a source with exactly one
     *                                                   item that has a pointer; null otherwise —
     *                                                   see the type's own docblock for why a
     *                                                   source-level pointer does not exist
     * @param  list<SourceWarningCount>  $warnings  advisory parser/OCR warning CODES with a version
     *                                              count each, never the values behind them
     */
    public function __construct(
        public int $itemCount,
        public int $activeVersionCount,
        public ?ActiveSourceVersion $activeVersion,
        public int $pageCount,
        public int $slideCount,
        public int $sheetCount,
        public int $elementCount,
        public int $chunkCount,
        public array $warnings,
        public bool $warningsTruncated,
        public ?string $preview,
        public bool $previewTruncated,
    ) {}
}

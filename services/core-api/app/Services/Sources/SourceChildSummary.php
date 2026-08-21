<?php

declare(strict_types=1);

namespace App\Services\Sources;

/**
 * How much of a source there is, as scalars, for the audit row.
 *
 * `source.deleted` and `source.reprocess.requested` both carry counts and neither carries a list.
 * The reason is in `AuditLogger`: a reader who lands on a `source.deleted` row and sees 412 items
 * and 1,340 versions knows a CRAWL was removed rather than a document, and knows to go looking at
 * the `source.version.retired` rows that preceded it. A list of 412 canonical keys would be an
 * array, and `sanitize()` drops an array outright — so the choice is a scalar or nothing.
 *
 * Read INSIDE the audit closure, which is inside the mutating transaction. On the delete path that
 * ordering is what makes the numbers true: the closure runs before the children are removed, so it
 * counts the rows that are about to be destroyed. The same read taken afterwards records two zeroes
 * onto exactly the row that needs them most — which is finding L2's shape, one table over.
 */
final readonly class SourceChildSummary
{
    public function __construct(
        public int $itemCount,
        public int $versionCount,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toAuditDetails(): array
    {
        return [
            'item_count' => $this->itemCount,
            'version_count' => $this->versionCount,
        ];
    }
}

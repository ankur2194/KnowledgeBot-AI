<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Ingestion outcomes in the window, counted over `source_versions`.
 *
 * ── VERSIONS AND NOT SOURCES, WHICH IS THE WHOLE OF THE DEFINITION ───────────────────────────
 *
 * A knowledge source is ingested many times — every reprocess, every recrawl, every new upload into
 * an existing source is a new `source_versions` row. Counting SOURCES would answer "how many
 * documents does this organization have", which is a different tile and never changes when an
 * ingestion fails. Counting VERSIONS answers "did the work succeed", which is what a freshness
 * banner and an on-call rotation read.
 *
 * `succeeded` is `ready` OR `ready_with_warnings`: a version with warnings IS searchable and IS
 * serving answers, so filing it as a failure would report an outage that is not happening. The
 * warning count is its own surface (`SourceWarningResource`) and is deliberately not folded in here.
 *
 * `inFlight` is everything still moving — queued, fetching, parsing, normalizing, chunking,
 * embedding, indexing. It is published rather than omitted because without it "succeeded + failed"
 * does not add up to the number of versions created, and a reader who cannot make the numbers add
 * up assumes one of them is wrong.
 */
final readonly class IngestionSummary
{
    public function __construct(
        public int $succeeded,
        public int $failed,
        public int $inFlight,
    ) {}
}

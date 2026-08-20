<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Models\KnowledgeSource;
use App\Models\SourceVersion;

/**
 * What one ingestion callback frame actually did.
 *
 * ── A REFUSED FRAME IS A 200, AND THAT IS DELIBERATE ──────────────────────────────────────────
 *
 * A stale or out-of-order frame is not an error: it is the guard working. Answering it with a 4xx
 * would put a permanently-failing request in front of a Celery task that is going to re-emit it,
 * and `kb-error-taxonomy` would then have the data plane retry a frame whose whole meaning is
 * "already superseded". The response says `applied: false` and names the reason, so the seam is
 * observable without being retried.
 *
 * The three reasons are distinguishable on purpose. `out_of_order` is expected under redelivery and
 * is a normal Tuesday. `stale_job` means a reprocess superseded the run mid-flight, which is also
 * normal but worth counting. `unknown_item` and `item_source_mismatch` are neither — they mean the
 * data plane is describing a row this organization does not have, and that is the shape a
 * cross-tenant callback would take.
 */
final readonly class IngestionApplication
{
    public const APPLIED = 'applied';

    public const OUT_OF_ORDER = 'out_of_order';

    public const STALE_JOB = 'stale_job';

    public const UNKNOWN_ITEM = 'unknown_item';

    public const ITEM_SOURCE_MISMATCH = 'item_source_mismatch';

    public function __construct(
        public bool $applied,
        public string $reason,
        public ?KnowledgeSource $source = null,
        public ?SourceVersion $version = null,
        public ?SourceVersion $retired = null,
        public bool $activated = false,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(applied: false, reason: $reason);
    }
}

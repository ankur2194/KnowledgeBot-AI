<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;

/**
 * One ingestion progress frame, as it arrives from the data plane.
 *
 * ── THE FOUR FIELDS THE CONTRACT PINS, AND WHAT THIS ADDS TO THEM ────────────────────────────
 *
 * `kb-internal-api-contracts`: "every callback carries `(job_id, sequence, stage, status)`", and
 * "Laravel applies it under `WHERE sequence > progress_sequence` so a Celery retry cannot rewind
 * the job". Everything below beyond those four is this seam's own, because `source_versions` is not
 * in `ALLOWED_TABLES` and therefore every value that has to land on that row has to arrive here:
 *
 *   `identity`        the six ingest-key components. Only the data plane can compute them, and
 *                     only Laravel may write them. See VersionIdentity.
 *   `verified`        the verification gate. `SourceState::canTransitionTo()` defaults it to FALSE
 *                     precisely so a frame that omits it is refused rather than publishing.
 *   `delivery_count`  THE GAP THIS FIELD CLOSES. `services/ai-service/app/ingestion/tasks.py`
 *                     bumps a durable per-version redelivery counter as its step 2, and
 *                     `source_versions` is not — and must never be — in `ALLOWED_TABLES`, because
 *                     ADR-033 property 2 fails the moment Laravel serves the row. So the worker
 *                     cannot write the column it depends on. It rides here rather than on a second
 *                     internal endpoint because this callback already crosses the seam on every
 *                     delivery, so the counter arrives with the frame it describes and is applied
 *                     under the same sequence guard and the same row lock as everything else on it.
 *                     There is deliberately no CHECK at MAX_DELIVERIES: the worker reads a value
 *                     ABOVE the cap to decide to give up.
 *   `chunk_count`     the verified total the worker counted with `exact=True`. Audit only — no
 *                     column holds it, on purpose (the create migration records why).
 *   `error_class`     one of the 18, assigned by the data plane and relayed verbatim. Laravel
 *                     never re-derives a class from a status.
 */
final readonly class IngestionProgress
{
    /**
     * @param  array<string, mixed>  $warningSummary
     */
    public function __construct(
        public string $jobId,
        public string $sourceId,
        public string $sourceItemId,
        public int $sequence,
        public string $stage,
        public SourceState $status,
        public bool $verified,
        public ?VersionIdentity $identity,
        public ?int $deliveryCount,
        public ?int $chunkCount,
        public array $warningSummary,
        public ?string $errorClass,
    ) {}
}

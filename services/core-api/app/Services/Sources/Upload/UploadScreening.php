<?php

declare(strict_types=1);

namespace App\Services\Sources\Upload;

/**
 * The verdict on one multipart batch: what passed, and what each refusal was.
 *
 * ── EVERY FILE IS SCREENED, AND THE FIRST REFUSAL DOES NOT STOP THE REST ─────────────────────
 *
 * The gate's STEP ORDER is a security property and is enforced per file inside `UploadIntake::
 * admit()`. The BATCH order is not: files are independent, and stopping at the first refusal would
 * make an operator who dragged ten documents in fix them one round trip at a time, each round trip
 * spending the bandwidth of the nine that were already fine. So every file is screened and the 422
 * carries a `files.{index}` entry for each one that failed, which is the shape `apps/web` renders
 * against the row the operator can see (`StoreSourceRequest`'s docblock: a flat `files` key can only
 * produce a banner about "the upload").
 *
 * ── THE INDEXES ARE THE REQUEST'S OWN, AND THAT IS WHY BOTH MAPS ARE KEYED RATHER THAN LISTS ─
 *
 * `files.0` in the error map has to name the part the client sent as `files[0]`. A `list` of
 * rejections would renumber from zero and point the console at the wrong row — a per-file error
 * rendered against the wrong file is worse than a banner, because it is confidently wrong.
 *
 * ── A BATCH WITH ANY REJECTION CREATES NOTHING ───────────────────────────────────────────────
 *
 * All-or-nothing, decided by the caller and stated here because it is the reason this object exists
 * instead of a stream of accepted files. A partial success would leave a source whose name and
 * description describe ten documents and whose corpus holds seven, with no state anywhere recording
 * which three are missing — and the operator's remedy would be a second source, not a retry.
 */
final readonly class UploadScreening
{
    /**
     * @param  array<int, AcceptedUpload>  $accepted  keyed by the part index in the request
     * @param  array<int, UploadRejected>  $rejected  keyed by the part index in the request
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
    ) {}

    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }

    /**
     * The accepted files in request order, as a list, for the caller that is about to write objects.
     *
     * ORDERED BY THE PART INDEX rather than by insertion, because `IngestionSubmission::
     * fingerprint()` hashes the items in the order the repository created them and a submission
     * whose key changed between two identical requests would be a duplicate job rather than a
     * replay.
     *
     * @return list<AcceptedUpload>
     */
    public function inOrder(): array
    {
        $accepted = $this->accepted;
        ksort($accepted, SORT_NUMERIC);

        return array_values($accepted);
    }
}

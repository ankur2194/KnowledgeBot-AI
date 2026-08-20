<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SourceState;
use App\Services\Sources\IngestionProgress;
use App\Services\Sources\VersionIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /internal/v1/callbacks/ingestion` — one progress frame from the data plane.
 *
 * ── A SIGNED CALLER IS STILL VALIDATED, AND THAT IS NOT BELT-AND-BRACES ──────────────────────
 *
 * The HMAC proves WHO sent the frame. It proves nothing about the frame: a signed request carrying
 * `status: "ready"` with no verification flag would publish an unindexed version, and a signed
 * request carrying a `sequence` of `-1` would sit permanently below every future frame's guard.
 * `kb-internal-api-contracts` renders a malformed internal request as `validation` -> 422, which is
 * exactly what this produces.
 *
 * ── THE FOUR FIELDS THE CONTRACT PINS ─────────────────────────────────────────────────────────
 *
 * `(job_id, sequence, stage, status)` on every callback, applied under
 * `WHERE sequence > progress_sequence`. `source_id` and `source_item_id` are added because a frame
 * has to name the row it is about — the item is the unit a run is scoped to, and it is what the
 * guard's two columns live on.
 *
 * ── EVERYTHING ELSE IS HERE BECAUSE `source_versions` IS LARAVEL'S TO WRITE ──────────────────
 *
 * ADR-033 property 2 fails immediately for that table — Laravel serves the row — so it is not in
 * `ALLOWED_TABLES` and never may be. The consequence is that every value which has to land on a
 * version row has to arrive on this frame: the six identity components, the verification verdict,
 * the durable delivery counter, the warning summary, and the verified chunk total.
 *
 * ── `error_class` IS RELAYED, NEVER RE-DERIVED ────────────────────────────────────────────────
 *
 * Closed to the eighteen classes with `Rule::in`, so a frame naming a nineteenth is a 422 rather
 * than a value that reaches telemetry and a client's retry decision. Laravel never derives a class
 * from a status and never invents one.
 */
final class IngestionCallbackRequest extends FormRequest
{
    /**
     * The ceiling on a frame's sequence number.
     *
     * A BOUND RATHER THAN A COUNT OF STAGES, because the stage set is the data plane's and will
     * grow. What it prevents is the poisoning move: a single frame carrying `PHP_INT_MAX` sets
     * `progress_sequence` to a value no legitimate frame can ever exceed, and the run is silently
     * frozen for the rest of its life with a 200 on every subsequent callback. Ten thousand is far
     * past any real run (a 400-page crawl reports per item, not per page of the source) and far
     * short of anything unrecoverable.
     */
    public const MAX_SEQUENCE = 10_000;

    /**
     * Authorization on this surface is the SIGNATURE, verified by middleware before this request is
     * constructed. There is no user, no session and no policy: the caller is a service, and what it
     * is permitted to do is decided by the route it reached and by the organization inside the
     * signed `X-KB-Org-Id` header.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'job_id' => ['bail', 'required', 'string', 'ulid'],
            'source_id' => ['bail', 'required', 'string', 'ulid'],
            'source_item_id' => ['bail', 'required', 'string', 'ulid'],

            // MIN 1, NOT 0. `source_items.progress_sequence` starts at 0 and the guard is strictly
            // greater-than, so a frame at 0 could never apply and would look like a silent drop.
            'sequence' => ['bail', 'required', 'integer', 'min:1', 'max:'.self::MAX_SEQUENCE],

            // The pipeline's own label for what it was doing. Free text, bounded, and recorded on
            // the span rather than in a column: it is an OBSERVABILITY field, and closing it to an
            // enum here would make adding a stage on the far side a coordinated deploy.
            'stage' => ['bail', 'required', 'string', 'max:64'],

            // Closed to the fifteen, generated from the enum so this rule and
            // `source_versions_status_check` cannot drift.
            'status' => ['bail', 'required', 'string', Rule::in(SourceState::values())],

            // THE VERIFICATION GATE. Defaulted to FALSE by omission, which matches
            // `canTransitionTo()`'s own default and for the same reason: a frame that forgot to
            // report a verification must be refused at the `Indexing -> Ready` edge rather than
            // publishing a version that indexed half a document.
            'verified' => ['bail', 'sometimes', 'boolean'],

            // ── THE VERSION IDENTITY ────────────────────────────────────────────────────────
            //
            // Present once the run has resolved it; absent on the frames before that. Every
            // component is `required_with:version` rather than merely optional, because a partial
            // identity is worse than none: it would mint a version row whose ingest key describes a
            // different run than the row it sits on, and the dedup index would then match a future
            // request against a version that is not what it asked for.
            'version' => ['bail', 'sometimes', 'array'],
            'version.content_hash' => ['bail', 'required_with:version', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'version.ingest_key' => ['bail', 'required_with:version', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'version.parser_cfg_version' => ['bail', 'required_with:version', 'string', 'max:200'],
            'version.ocr_cfg_version' => ['bail', 'required_with:version', 'string', 'max:200'],
            'version.chunker_cfg_version' => ['bail', 'required_with:version', 'string', 'max:200'],
            'version.embedding_model_version' => ['bail', 'required_with:version', 'string', 'max:200'],

            // THE GAP THIS FIELD CLOSES. The worker bumps a durable per-version redelivery counter
            // and cannot write the column, because `source_versions` is not in `ALLOWED_TABLES`.
            // NO MAXIMUM AT `MAX_DELIVERIES`: the worker reads a value ABOVE the cap to decide to
            // give up, so a rule at 3 would refuse the very frame that reports the give-up.
            'delivery_count' => ['bail', 'sometimes', 'integer', 'min:0'],

            // The verified total counted with `exact=True`. Audit only — no column holds it, and
            // the create migration records why a denormalized copy would be a second number that
            // can disagree with the first while both look authoritative.
            'chunk_count' => ['bail', 'sometimes', 'integer', 'min:0'],

            // Advisory parser and OCR warnings (§8.11), which never gate retrieval. An OBJECT and
            // never a list: `source_versions_warning_summary_is_object` refuses the array spelling
            // outright, so a list here would be a constraint violation rendered as a 500 rather
            // than the 422 it is.
            'warning_summary' => ['bail', 'sometimes', 'array'],

            'error_class' => [
                'bail', 'sometimes', 'nullable', 'string',
                Rule::in(array_keys(\App\Support\Kb\ErrorTaxonomy::RETRYABLE)),
            ],
        ];
    }

    /**
     * The validated frame, as a type.
     */
    public function toFrame(): IngestionProgress
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $version = $data['version'] ?? null;
        $warnings = $data['warning_summary'] ?? [];
        $errorClass = $data['error_class'] ?? null;

        return new IngestionProgress(
            jobId: (string) $data['job_id'],
            sourceId: (string) $data['source_id'],
            sourceItemId: (string) $data['source_item_id'],
            sequence: (int) $data['sequence'],
            stage: (string) $data['stage'],
            status: SourceState::from((string) $data['status']),
            // `=== true` and not a truthy cast. `boolean` validation admits `"0"`, `0` and `false`,
            // and every one of those has to mean NOT verified — a cast that treated `"false"` as
            // true would publish an unverified version, which is the one failure this flag exists
            // to make impossible.
            verified: ($data['verified'] ?? false) === true,
            identity: is_array($version) ? VersionIdentity::fromArray($version) : null,
            deliveryCount: isset($data['delivery_count']) ? (int) $data['delivery_count'] : null,
            chunkCount: isset($data['chunk_count']) ? (int) $data['chunk_count'] : null,
            warningSummary: is_array($warnings) ? $warnings : [],
            errorClass: is_string($errorClass) && $errorClass !== '' ? $errorClass : null,
        );
    }
}

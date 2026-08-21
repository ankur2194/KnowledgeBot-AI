<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SourceState;
use App\Rules\JsonObjectMap;
use App\Rules\LiteralBoolean;
use App\Services\Sources\IngestionProgress;
use App\Services\Sources\SourceWarningCount;
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
     * The ceiling on how many distinct warning CODES one frame may carry.
     *
     * ── WHERE 416 COMES FROM, AND WHY IT IS NOT A ROUND NUMBER ──────────────────────────────
     *
     * The key set of `warning_summary` belongs to the data plane and is enumerated nowhere on this
     * side, so the only honest way to size a bound is to count what that side can actually emit.
     * Today exactly one warning vocabulary is written down — `assess()` in
     * `services/ai-service/app/ingestion/ocr/guarded.py` — and it is not the four fixed strings it
     * looks like, because two of the four INTERPOLATE A FLOAT:
     *
     *   `ocr_low_confidence:{mass:.2f}`   fires above `LOW_CONF_MASS_WARN = 0.30`, so at two
     *                                     decimals it spans `0.30`…`1.00` — 71 distinct keys
     *   `ocr_low_coverage:{coverage:.2f}` fires below `COVERAGE_WARN = 0.30`, so it spans
     *                                     `0.00`…`0.30` — 31 distinct keys
     *   `ocr_coverage_unmeasurable`       1
     *   `ocr_text_unplaced`               1
     *
     * 104 for one version, reachable by any document with enough pages — and the WHOLE STRING is
     * what the producer keeps: `_confidence_record()` in
     * `services/ai-service/app/ingestion/parsing/converter.py` rolls the per-page lists up as
     * `sorted(set(rolled))`, deduplicating the interpolated strings rather than stripping their
     * suffixes. So the high-cardinality reading is the measured one, not the pessimistic one.
     *
     * Nothing yet turns that roll into THIS field — no caller of this endpoint exists on the data
     * plane at all — so the last step is unwritten and the bound is sized for what the step before
     * it produces.
     *
     * 416 is that 104 once per ingestion stage that could grow a family of the same shape: parse,
     * OCR, chunk, embed. It is a bound on a BUGGY WORKER rather than on an attacker — this seam is
     * HMAC-signed, so nothing unsigned reaches it — which is why it is sized to be unreachable by
     * correct code rather than tight. What it refuses is the 10,000-key frame: a runaway loop on
     * the far side would otherwise put 10,000 rows in a jsonb column and 25 arbitrary ones on an
     * administrator's page, with `warnings_truncated: true` implying the other 9,975 are real.
     */
    public const MAX_WARNING_CODES = 416;

    /**
     * The ceiling on one warning code's length, in CHARACTERS.
     *
     * NOT CHOSEN HERE, AND NOT COPIED HERE EITHER. It is `SourceWarningCount::MAX_CODE_LENGTH`, the
     * same constant `EloquentKnowledgeSourceRepository` truncates a published code at — so the
     * ingress refusal and the read-side cut are one number by construction rather than two numbers
     * a test has to keep equal. What that buys: a code that is stored is a code that is published
     * WHOLE, so nobody reads a cut code that neither plane emitted. See that constant for where
     * 128 comes from.
     */
    public const MAX_WARNING_CODE_LENGTH = SourceWarningCount::MAX_CODE_LENGTH;

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
            //
            // `LiteralBoolean` AND NOT `boolean`, AND THE DIFFERENCE IS FINDING B2. Laravel's
            // `boolean` rule accepts `1`, `0`, `"1"` and `"0"` as well as the two JSON literals,
            // and `validated()` does not cast — so `"verified": 1` PASSED this gate as a
            // well-formed verification claim and was then read as NOT verified by the strict
            // comparison in `toFrame()`. The version did not activate, `Indexing -> Ready` was
            // refused, and the worker got a 200 that looks exactly like an ordinary mid-run frame.
            // The accepted set and the read set are now the same two values.
            'verified' => ['bail', 'sometimes', new LiteralBoolean],

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
            // never a list — and `JsonObjectMap` is what makes that true, because until it landed
            // nothing did.
            //
            // THE COMMENT HERE USED TO CLAIM `source_versions_warning_summary_is_object` CAUGHT A
            // LIST, and it does not: the value never reaches that CHECK as a list. `SourceVersion`
            // casts the column with `JsonObjectCast`, whose `set()` is `json_encode((object) $v)`,
            // and `(object) ["ocr_low","table_unplaced"]` encodes to
            // `{"0":"ocr_low","1":"table_unplaced"}` — `jsonb_typeof` `object`, CHECK satisfied,
            // 200 returned. The detail projection then publishes warning codes `0` and `1`, which
            // nothing on either plane can distinguish from real ones because the vocabulary is the
            // data plane's. `JsonObjectCast`'s own docblock states the list-to-numeric-keys
            // behaviour; it simply had never been carried across to the request that admits lists.
            //
            // BOUNDED IN BOTH DIMENSIONS, AND THE TWO BOUNDS ARE EXPRESSED DIFFERENTLY ON PURPOSE.
            // The key COUNT is Laravel's own `max:`, which counts an array's elements and is
            // therefore visible in `packages/contracts/rules/IngestionCallbackRequest.json`; the
            // key LENGTH is the rule object's argument, and is NOT visible there, because
            // `kb:dump-form-rules` writes a rule object out as its class name and nothing else.
            // That asymmetry is stated rather than hidden: a client generated from the manifest can
            // see the count refusal coming and cannot see the length one.
            'warning_summary' => [
                'bail', 'sometimes', 'array', 'max:'.self::MAX_WARNING_CODES,
                new JsonObjectMap(self::MAX_WARNING_CODE_LENGTH),
            ],

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
            // `=== true` and not a truthy cast, over a value the rule has already narrowed to a
            // JSON `true` or `false`. Those are now the SAME SET — which is the whole of the fix
            // for finding B2. While the rule was Laravel's `boolean`, the accepted set was six
            // values and this comparison read four of them as NOT verified, so `"verified": 1`
            // was admitted as a verification claim and applied as its opposite.
            //
            // THE COMPARISON STAYS STRICT ANYWAY, and deliberately: a rule object is a thing
            // somebody can widen in one line, and a truthy cast under a widened rule would publish
            // an unverified version — the one failure this flag exists to make impossible.
            // Reading it strictly means a widening turns into a refused publication, which is
            // visible, rather than into a published one, which is not.
            verified: ($data['verified'] ?? false) === true,
            identity: is_array($version) ? VersionIdentity::fromArray($version) : null,
            deliveryCount: isset($data['delivery_count']) ? (int) $data['delivery_count'] : null,
            chunkCount: isset($data['chunk_count']) ? (int) $data['chunk_count'] : null,
            warningSummary: is_array($warnings) ? $warnings : [],
            errorClass: is_string($errorClass) && $errorClass !== '' ? $errorClass : null,
        );
    }
}

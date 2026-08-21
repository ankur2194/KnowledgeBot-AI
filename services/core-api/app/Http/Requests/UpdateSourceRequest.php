<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\KnowledgeSource;
use App\Services\Sources\SourceEdit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * `PATCH .../sources/{source}` — edit one source's METADATA. An absent field is left alone.
 *
 * ── FOUR FIELDS ARE DECLARED `missing` RATHER THAN SIMPLY OMITTED ────────────────────────────
 *
 * `type`, `origin_url`, `status` and `content` are all things a caller might reasonably try to
 * PATCH, and all four are refused here — but an ABSENT RULE is not a refusal, it is a silent drop:
 * `validated()` discards an undeclared field, so a console that had not been updated would rename a
 * crawl target, receive a 200, and find the old URL still being fetched. `missing` produces a 422
 * that names the field and can carry a message pointing at the route that does the job.
 *
 * `missing` AND NOT `prohibited`. `UpdateBotRequest` measured the difference and it is the whole
 * reason this spelling is used: `prohibited` PASSES for `null`, `""` and `[]`, so
 * `{"status": null}` would slip through a `prohibited` rule and be dropped anyway.
 *
 * Why each is refused:
 *
 *   `status`      a lifecycle move, with its own route and its own guard. Two doors to the column
 *                 that decides whether a source answers is two places a check has to be.
 *   `type`        not a preference — it is WHICH PIPELINE runs and which half of
 *                 `knowledge_sources_origin_url_matches_type` applies. A source of the wrong kind
 *                 is a new source.
 *   `origin_url`  the other half of that constraint, and changing it on a live crawl source alters
 *                 what this platform fetches without re-running the SSRF envelope that admitted the
 *                 original value.
 *   `content`     editing a paste is a new VERSION of the same item, which is `reprocess` plus new
 *                 bytes. Accepting it here would write a content hash with no run behind it.
 *
 * ── AN EMPTY BODY IS REFUSED IN THE CONTROLLER, NOT HERE ─────────────────────────────────────
 *
 * With five optional fields the declarative spelling is `required_without_all` naming four siblings
 * on each of five fields, which is the construction `UpdateProviderModelRequest` rejected as
 * unreadable. The refusal is a `ValidationException` with a real per-field map rather than a bare
 * `abort(422)`, because `apps/web` discriminates the ADR-031 resolver refusal on the shape
 * `validation` WITH NO MAP, and a second map-less 422 on this surface would wear that signature.
 */
final class UpdateSourceRequest extends FormRequest
{
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
            'name' => ['bail', 'sometimes', 'string', 'max:200'],
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],

            'tags' => ['bail', 'sometimes', 'array', 'max:50'],
            'tags.*' => ['bail', 'string', 'min:1', 'max:64', 'distinct'],

            // NO `after:effective_at` HERE, AND ITS ABSENCE IS THE FIX. On a PATCH the sibling is
            // usually ABSENT, and Laravel's `after` compares against whatever `$this->input()`
            // returns for the named field — `null` — which every timestamp is "after". The rule
            // passed vacuously while nothing checked the value actually STORED, so
            // `{"expires_at": "2026-08-01"}` against a stored `effective_at` of `2026-09-01`
            // reached the INSERT, hit `knowledge_sources_window_ordered`, and surfaced as an
            // unconverted `QueryException` — a 500 on a response documented as 422. The window is
            // checked against the MERGED row in `withValidator()` below instead.
            'effective_at' => ['bail', 'sometimes', 'nullable', 'date'],
            'expires_at' => ['bail', 'sometimes', 'nullable', 'date'],

            // See the class docblock. `missing`, never `prohibited`.
            'status' => ['missing'],
            'type' => ['missing'],
            'origin_url' => ['missing'],
            'content' => ['missing'],
        ];
    }

    /**
     * THE WINDOW IS A PROPERTY OF THE MERGED ROW, NOT OF THE REQUEST BODY.
     *
     * A PATCH names one end of the window and leaves the other stored, so the only comparison that
     * means anything is between what the caller sent and what is already on the row. Both
     * directions matter and neither is expressible as a `date` rule: sending only `expires_at`
     * must be checked against the stored `effective_at`, and sending only `effective_at` against
     * the stored `expires_at` — a caller can invert the window from either end.
     *
     * `has()` AND NOT `filled()`. An explicit `null` CLEARS that end, which makes the window
     * open-ended and therefore always valid; `filled()` would read the clear as "not supplied" and
     * compare against a value the request is removing.
     *
     * The refusal is a per-field 422, which is the whole point — `knowledge_sources_window_ordered`
     * would catch it either way, as a `QueryException` nothing converts, on a route whose
     * documented failure shape is a field map.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only when both ends parsed. Comparing a value the `date` rule already rejected would
            // add a second, confusing error to a field that has one.
            if ($validator->errors()->hasAny(['effective_at', 'expires_at'])) {
                return;
            }

            $source = $this->route('source');

            if (! $source instanceof KnowledgeSource) {
                return;
            }

            $effective = $this->has('effective_at')
                ? $this->windowEnd($this->input('effective_at'))
                : $source->effective_at;

            $expires = $this->has('expires_at')
                ? $this->windowEnd($this->input('expires_at'))
                : $source->expires_at;

            if ($effective === null || $expires === null) {
                return;
            }

            if ($expires->greaterThan($effective)) {
                return;
            }

            $validator->errors()->add('expires_at', 'A source stops answering strictly after it '
                .'starts. This request would leave the window ending at or before it begins, '
                .'counting the value already stored for the end it does not name — which is a row '
                .'`knowledge_sources_window_ordered` refuses, and a refusal that reaches the '
                .'database arrives as a 500 rather than as this message.');
        });
    }

    private function windowEnd(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            // Unparsable, which the `date` rule has already refused — the guard above means this
            // is unreachable, and returning null rather than raising keeps it that way if it ever
            // is not.
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.missing' => 'A source\'s lifecycle state is not an editable field. Disabling and '
                .'re-enabling go through PUT .../sources/{source}/status, which is where the '
                .'transition table is asked and where the audit row that records who did it is '
                .'written.',
            'type.missing' => 'A source\'s kind decides which pipeline processes it and cannot be '
                .'changed after its items and versions were produced by another one. Create a new '
                .'source.',
            'origin_url.missing' => 'A crawl target cannot be re-pointed in place: the new URL would '
                .'be fetched without re-running the checks that admitted the original one, and every '
                .'existing version would claim to have come from it. Create a new source.',
            'content.missing' => 'Replacing a paste is a new VERSION of the same item rather than an '
                .'edit of this row. Submit it through POST .../sources/{source}/reprocess once the '
                .'new content is in place.',
        ];
    }

    /**
     * The validated body, as a type.
     *
     * ── ONLY THE KEYS THE CALLER ACTUALLY SENT ────────────────────────────────────────────────
     *
     * `SourceEdit` distinguishes "not named" from "named as null", and the two mean different
     * things on every nullable column here: an absent `description` keeps what is stored, and an
     * explicit `null` clears it. `array_key_exists` and never `isset` — `isset` is false for a key
     * whose value IS null, which is exactly the case the distinction exists for.
     */
    public function toData(): SourceEdit
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $columns = [];

        foreach (['name', 'description'] as $column) {
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
                $columns[$column] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('name', $columns) && $columns['name'] === null) {
            // `name` is NOT NULL with a non-blank CHECK, and the rule above has no `nullable`, so
            // this is unreachable from HTTP. It is written out because `toData()` is a public method
            // and a future caller reaching it another way would otherwise produce a NOT NULL
            // violation rendered as a 500 for what is plainly a bad request.
            unset($columns['name']);
        }

        if (array_key_exists('tags', $data)) {
            $tags = $data['tags'];
            $columns['tags'] = is_array($tags) ? array_values(array_map(strval(...), $tags)) : [];
        }

        foreach (['effective_at', 'expires_at'] as $column) {
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
                $columns[$column] = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)
                    : null;
            }
        }

        return new SourceEdit($columns);
    }
}

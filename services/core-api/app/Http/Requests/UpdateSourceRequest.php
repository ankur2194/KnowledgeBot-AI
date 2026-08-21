<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Sources\SourceEdit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

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

            'effective_at' => ['bail', 'sometimes', 'nullable', 'date'],
            'expires_at' => ['bail', 'sometimes', 'nullable', 'date', 'after:effective_at'],

            // See the class docblock. `missing`, never `prohibited`.
            'status' => ['missing'],
            'type' => ['missing'],
            'origin_url' => ['missing'],
            'content' => ['missing'],
        ];
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

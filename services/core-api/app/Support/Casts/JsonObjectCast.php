<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use RuntimeException;

/**
 * A `jsonb` column that is always a JSON OBJECT, never a JSON array — including when it is empty.
 *
 * ── THE BUG THIS EXISTS TO PREVENT, WHICH IS INVISIBLE UNTIL A CONSTRAINT CATCHES IT ──────────
 *
 * Eloquent's built-in `array` cast is `json_encode($value)`, and in PHP an empty array and an empty
 * map are the same value. So `$bot->theme = []` — the overwhelmingly common state, "this bot uses
 * the platform theme" — is written as `[]`, whose `jsonb_typeof` is `array` and not `object`.
 * Every non-empty value on the same column writes as `{...}`, so the column silently holds TWO JSON
 * TYPES depending on whether anybody has themed the bot.
 *
 * Nothing complains unless something asks. `bots_theme_vocabulary` asks — its subset test is
 * `theme - 'primary' - 'accent' - 'radius' = '{}'::jsonb`, and `'[]'::jsonb` is not `'{}'::jsonb`
 * — so with the plain `array` cast EVERY unthemed bot is refused by the database and the failure
 * reads as a constraint bug rather than an encoding one. Without the constraint it would be worse:
 * `theme -> 'primary'` over an array returns NULL, a generated TypeScript client typed from the
 * OpenAPI document would declare an object and receive an array, and `Object.entries([])` happens
 * to be `[]` — so the renderer would work, on the empty case, forever, and break the first time
 * somebody wrote a migration that assumed the column's type.
 *
 * ── WHY A CAST AND NOT A DEFAULT, A MUTATOR, OR `AsArrayObject` ───────────────────────────────
 *
 * A COLUMN DEFAULT (`'{}'::jsonb`) fixes only the rows nobody wrote a value to; it is present on
 * `bots.theme` and it does not help, because an explicit `[]` from a FormRequest overrides it.
 *
 * A MUTATOR on the one model would work and would have to be repeated on every future jsonb map —
 * bot theme, retention configuration, evaluation snapshots — and the one somebody forgets is the
 * one that fails in production.
 *
 * `AsArrayObject` is Laravel's own answer and encodes `{}` correctly, but it changes the PHP-side
 * type to `ArrayObject`: every read site becomes `$bot->theme->getArrayCopy()`, every resource has
 * to remember it, and `array<string, mixed>` stops being the annotation. That is a large change to
 * every call site to fix an encoding detail at one.
 *
 * `(object)` CASTING IS WHAT DOES THE WORK, and it does it for the non-empty case too: a LIST like
 * `['primary', 'accent']` becomes `{"0":"primary","1":"accent"}`, which the key-set CHECK then
 * refuses by name. That is the correct outcome — a list is not a map and the refusal says so —
 * whereas encoding it as `["primary","accent"]` would produce a `jsonb_typeof` failure whose
 * message points at the type rather than at the keys.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObjectCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            // NOT an error and not a null return: the column is NOT NULL with a `'{}'` default, so
            // the only way to see this is a model that has never been persisted or refreshed. "No
            // keys" is the honest reading and it is the same value an empty object decodes to.
            return [];
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' was not a JSON string. A jsonb column '
                .'reaches PHP as text; anything else means something wrote past this cast.',
            );
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' is not decodable JSON.', 0, $e,
            );
        }

        // A stored SCALAR or LIST is not a map. Returning it unchanged would hand a caller a shape
        // its `array<string, mixed>` annotation says it cannot be, and the first symptom would be
        // somewhere else entirely.
        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' decoded to a '.get_debug_type($decoded)
                .' rather than to a JSON object. The column\'s CHECK constraint refuses that shape '
                .'on write, so a row holding one predates the constraint or was written past it.',
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            // The column is NOT NULL. Encoding null as `'null'::jsonb` would store a JSON null,
            // which is a THIRD spelling of "empty" beside `{}` and `[]` — the exact ambiguity this
            // cast exists to remove.
            return '{}';
        }

        if (! is_array($value)) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' must be assigned an array. This column '
                .'is a closed key map, not a free JSON value.',
            );
        }

        // (object) is the whole cast: it makes `[]` encode as `{}` and makes a LIST encode as a map
        // with numeric string keys, which the column's key-set CHECK then refuses by name.
        return json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

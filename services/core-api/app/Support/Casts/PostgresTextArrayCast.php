<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A PostgreSQL ONE-DIMENSIONAL text array (`text[]`, `char(26)[]`) as a PHP `list<string>`.
 *
 * ── WHY THIS EXISTS AT ALL, GIVEN THAT ELOQUENT SHIPS AN `array` CAST ────────────────────────
 *
 * Eloquent's `array` cast is JSON, not a PostgreSQL array. Applied to a `text[]` column it writes
 * `["a","b"]` — a JSON document — into a column whose input syntax is `{a,b}`, and PostgreSQL
 * accepts it: `'["a","b"]'::text[]` is a malformed-literal error, but the value Eloquent binds is a
 * plain string parameter, so what actually happens depends on the driver's type inference. Read
 * back, `heading_path` then decodes to a one-element array whose single element is the literal text
 * `["a","b"]`, and a citation renders a heading path that is a JSON fragment. Nothing raises.
 *
 * Uncast is no better: the column reaches PHP as the raw literal `{Refunds,"Section 4, clause 2"}`,
 * and every read site would have to know the quoting rules. The one that does not know them is the
 * one that splits on a comma and cuts an element in half.
 *
 * ── EVERY ELEMENT IS QUOTED ON WRITE, INCLUDING THE ONES THAT DO NOT NEED IT ─────────────────
 *
 * Deciding per element whether quoting is required means re-deriving PostgreSQL's rules for the
 * unquoted form — whitespace, braces, commas, backslashes, the literal word `NULL`, and the empty
 * string, which has NO unquoted spelling at all. Quoting unconditionally is one rule instead of
 * seven, it is what PostgreSQL's own `array_out` does for anything ambiguous, and it costs two
 * bytes per element in a bind parameter that is never stored verbatim.
 *
 * A NULL ELEMENT IS REFUSED IN BOTH DIRECTIONS rather than mapped to PHP `null`. The three columns
 * this cast serves — `knowledge_sources.tags`, `chunks.element_ids`, `chunks.heading_path` — each
 * carry a CHECK forbidding a NULL element, because "an element id that is absent" is not a fact any
 * of them can express: a chunk that names a missing element is a citation that cannot resolve and a
 * deletion that cannot find its rows. Accepting one here would make the type annotation
 * `list<string>` a lie and would push the failure to whoever dereferences it.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresTextArrayCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            // The columns using this cast are NOT NULL with a `'{}'` default, so the only way to
            // see this is a model that has never been persisted or refreshed. The empty list is
            // the same value the empty array literal decodes to.
            return [];
        }

        if (is_array($value)) {
            // Already decoded — a value assigned in-memory and read back before a save. Validated
            // rather than trusted, so the annotation holds on this path too.
            return $this->assertListOfStrings($model, $key, $value);
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' was not a PostgreSQL array literal. A '
                .'text[] column reaches PHP as text; anything else means something wrote past this '
                .'cast.',
            );
        }

        return $this->parse($model, $key, $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            // The column is NOT NULL. A SQL NULL would be a third spelling of "no elements" beside
            // `{}` and an absent attribute.
            return '{}';
        }

        if (! is_array($value)) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' must be assigned a list of strings. This '
                .'column is a PostgreSQL text array, not a free scalar.',
            );
        }

        $elements = $this->assertListOfStrings($model, $key, $value);

        return '{'.implode(',', array_map(
            // Backslash first: escaping the quote first would then have its own backslash escaped.
            static fn (string $element): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $element).'"',
            $elements,
        )).'}';
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private function assertListOfStrings(Model $model, string $key, array $value): array
    {
        $elements = [];

        foreach ($value as $element) {
            if (! is_string($element)) {
                throw new RuntimeException(
                    "The {$key} attribute on ".$model::class.' holds a '.get_debug_type($element)
                    .' element. Every element of this column is a string, and a NULL element is '
                    .'refused by the column\'s own CHECK constraint as well.',
                );
            }

            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * Decode a one-dimensional array literal.
     *
     * Deliberately NOT `str_getcsv`, `explode`, or a regular expression. PostgreSQL's array output
     * quotes an element only when it has to, escapes with backslashes rather than by doubling, and
     * spells an absent element as an UNQUOTED `NULL` — so the quoted string `"NULL"` and the
     * element NULL are different values that every shortcut collapses.
     *
     * @return list<string>
     */
    private function parse(Model $model, string $key, string $literal): array
    {
        $trimmed = trim($literal);

        if ($trimmed === '' || $trimmed === '{}') {
            return [];
        }

        if (! str_starts_with($trimmed, '{') || ! str_ends_with($trimmed, '}')) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' is not a PostgreSQL array literal.',
            );
        }

        $body = substr($trimmed, 1, -1);
        $elements = [];
        $current = '';
        $quoted = false;
        $inQuotes = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inQuotes) {
                if ($char === '\\') {
                    $i++;

                    if ($i >= $length) {
                        throw new RuntimeException(
                            "The {$key} attribute on ".$model::class.' ends inside an escape sequence.',
                        );
                    }

                    $current .= $body[$i];

                    continue;
                }

                if ($char === '"') {
                    $inQuotes = false;

                    continue;
                }

                $current .= $char;

                continue;
            }

            if ($char === '"') {
                $inQuotes = true;
                $quoted = true;

                continue;
            }

            if ($char === '{') {
                // Multi-dimensional arrays are a different type with a different PHP shape, and
                // silently flattening one would produce a `list<string>` whose elements are
                // fragments of rows. No column in this schema declares one.
                throw new RuntimeException(
                    "The {$key} attribute on ".$model::class.' is a multi-dimensional array, which '
                    .'this cast does not decode.',
                );
            }

            if ($char === ',') {
                $elements[] = $this->finish($model, $key, $current, $quoted);
                $current = '';
                $quoted = false;

                continue;
            }

            $current .= $char;
        }

        if ($inQuotes) {
            throw new RuntimeException(
                "The {$key} attribute on ".$model::class.' ends inside a quoted element.',
            );
        }

        $elements[] = $this->finish($model, $key, $current, $quoted);

        return $elements;
    }

    private function finish(Model $model, string $key, string $raw, bool $quoted): string
    {
        if (! $quoted) {
            $raw = trim($raw);

            if (strcasecmp($raw, 'NULL') === 0) {
                throw new RuntimeException(
                    "The {$key} attribute on ".$model::class.' contains a NULL element. Every column '
                    .'using this cast carries a CHECK forbidding one, so a row holding it predates '
                    .'that constraint or was written past it.',
                );
            }
        }

        return $raw;
    }
}

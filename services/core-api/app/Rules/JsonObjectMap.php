<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A JSON OBJECT keyed by name — never a JSON list, and never a map with numeric keys.
 *
 * ── WHY `array` IS NOT THAT RULE, AND WHY THE DIFFERENCE IS INVISIBLE ─────────────────────────
 *
 * Laravel's `array` rule accepts both JSON spellings, because PHP has one type for both: after
 * `json_decode(..., true)` a list and an object are both `array`. So a field declared `array` and
 * documented as an object admits `["ocr_low","table_unplaced"]` without a word.
 *
 * The comment that used to defend the gap on `IngestionCallbackRequest::warning_summary` said a
 * list "would be a constraint violation rendered as a 500", because
 * `source_versions_warning_summary_is_object` refuses the array spelling. THAT IS WRONG, AND THE
 * VALUE NEVER REACHES THE CONSTRAINT AS A LIST: `SourceVersion` casts the column with
 * `JsonObjectCast`, whose `set()` does `json_encode((object) $value)`, and
 * `(object) ["ocr_low","table_unplaced"]` encodes to `{"0":"ocr_low","1":"table_unplaced"}`. Its
 * `jsonb_typeof` is `object`, so the CHECK passes; unlike `bots.theme`, this column carries no
 * key-set CHECK to catch the numeric keys afterwards. The frame is accepted with
 * `200 applied: true`, and the detail projection then publishes `warnings: [{code: "0"}]` — which
 * nothing on either plane can distinguish from a real warning code, because the vocabulary belongs
 * to the data plane and `SourceWarningResource` correctly puts no enum on it. That is finding S1.
 *
 * ── SO THE RULE IS CLOSED HERE, WHERE THE REFUSAL CAN NAME THE FIELD ──────────────────────────
 *
 * A comment cannot refuse anything. This produces `validation` -> 422 with `errors.warning_summary`
 * populated, which is what a worker author reading a 422 needs, instead of a value that is mangled
 * on the way into a column and surfaces months later as an unexplained warning code in a console.
 *
 * ── WHAT IT ACCEPTS ───────────────────────────────────────────────────────────────────────────
 *
 * An EMPTY array passes, and that is not a hole. `[]` and `{}` decode to the same PHP value, so
 * "the empty list" is not a distinguishable input to refuse — and `JsonObjectCast::set()` writes
 * either as `{}`, which is the column's default and its correct empty state.
 *
 * EVERY KEY MUST BE A NON-EMPTY STRING, which is a stronger test than `! array_is_list()`.
 * `json_decode` turns the object key `"0"` into the PHP integer key `0`, so `{"0":"a","2":"b"}` is
 * neither a list nor a name-keyed map and would slip past a list check into exactly the mangled
 * shape above. Refusing every integer key refuses both spellings of the same defect.
 *
 * ── AND EVERY KEY IS BOUNDED IN LENGTH, BY AN ARGUMENT THE CALLER MUST SUPPLY ────────────────
 *
 * `$maxKeyLength` has no default on purpose. A name-keyed map whose keys are somebody else's
 * vocabulary is unbounded in two dimensions, not one — how many keys, and how long each is — and
 * the second is the one a shape rule silently leaves open. Requiring the argument means a second
 * caller has to decide the number rather than inherit one chosen for a different field.
 *
 * THE COUNT IS NOT THIS RULE'S JOB and is deliberately left to Laravel's own `max:` on the array,
 * which counts elements. That is not tidiness: a rule OBJECT is written into
 * `packages/contracts/rules/*.json` as nothing but its CLASS NAME, so a bound expressed as a
 * constructor argument is invisible in the published manifest, while a `max:` is written into it
 * verbatim. Whatever can be a string rule should be; what cannot — this one, because no Laravel
 * rule constrains a KEY — says so where the number is chosen.
 *
 * A rule OBJECT and not a closure, for the reason `ExactWidgetOrigin` records: `kb:dump-form-rules`
 * writes a closure out as the literal string `Closure`, which no client can be generated from.
 */
final class JsonObjectMap implements ValidationRule
{
    public function __construct(private readonly int $maxKeyLength) {}

    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            // `array` in the rule list already covers this for an HTTP caller. The guard is here
            // because a rule object is reachable from `Validator::make()` anywhere.
            $fail('The :attribute field must be a JSON object.');

            return;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || $key === '') {
                $fail(
                    'The :attribute field must be a JSON object keyed by name, not a list. A list '
                    .'is not refused by the column\'s CHECK constraint — it is stored as an object '
                    .'with numeric keys (`["a","b"]` becomes `{"0":"a","1":"b"}`), which then reads '
                    .'back as warning codes `0` and `1` that nothing can tell from real ones.',
                );

                return;
            }

            // CHARACTERS, not bytes, and `mb_strlen` because the reader that publishes these keys
            // truncates with `mb_substr`. Measuring one in bytes and cutting the other in
            // characters is how a bound that looks equal admits a key the projection still cuts.
            if (mb_strlen($key) > $this->maxKeyLength) {
                $fail(
                    'Each key of the :attribute field must be at most '.$this->maxKeyLength
                    .' characters. These keys are published verbatim to administrators, and the '
                    .'projection that publishes them truncates at exactly this width — so a longer '
                    .'key would be stored whole and read back cut, which is a code nothing on '
                    .'either plane emitted.',
                );

                return;
            }
        }
    }
}

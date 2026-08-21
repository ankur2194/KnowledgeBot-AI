<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A JSON `true` or `false`, and nothing that merely converts to one.
 *
 * ── WHY LARAVEL'S `boolean` RULE IS THE WRONG GATE ON A SERVICE-TO-SERVICE SEAM ───────────────
 *
 * `boolean` accepts `true`, `false`, `1`, `0`, `"1"` and `"0"`, and `validated()` performs NO cast
 * — it returns the input it was given. So a reader written as `($data['flag'] ?? false) === true`,
 * which is the correct way to read a flag whose false value must be unambiguous, disagrees with the
 * rule that admitted the value: `1 === true` is `false` and `"1" === true` is `false`. The frame is
 * accepted as a well-formed claim and then applied as its opposite, with a 200 on the way out.
 *
 * That is finding B2, and its live instance was `IngestionCallbackRequest`'s `verified` flag: a
 * frame carrying `"verified": 1` passed validation and was applied as NOT verified, so the version
 * did not activate, `Indexing -> Ready` was refused, and the caller got
 * `200 {applied: true, activated: false}` — indistinguishable from an ordinary mid-run frame unless
 * the worker inspects `activated`. Any serialization that yields `1` rather than a JSON `true`
 * (`int(chunks_ok)`, a numpy bool, a value round-tripped through a Celery payload) would mean the
 * source never publishes and the previous version serves forever.
 *
 * ── NARROWING THE RULE, NOT WIDENING THE READ ─────────────────────────────────────────────────
 *
 * The two halves have to describe the same set, and there are only two ways to make them: coerce
 * `1` to `true` on the read, or refuse it at the boundary. This is the second. On a seam between
 * two services a malformed claim should fail LOUDLY and immediately, at the field, in front of the
 * worker author who is still holding the serializer — `validation` -> 422 with `errors.verified`
 * populated — rather than being silently accepted and then read as something the sender did not
 * say. Coercion would also make the gate's meaning depend on which of six spellings arrived.
 *
 * ── A RULE OBJECT AND NOT A CLOSURE ───────────────────────────────────────────────────────────
 *
 * `kb:dump-form-rules` records a closure as the literal string `Closure`, which its own docblock
 * calls "a rule no client can be generated from — treat it as a finding, not as noise". A rule
 * object is recorded by class name, which a generator and a drift test can both key on.
 *
 * IT REPLACES `boolean` RATHER THAN JOINING IT. Listing both would publish `boolean` in the rule
 * document — a rule that admits six values — beside an object that admits two, and the published
 * contract would then describe a wider set than the endpoint accepts.
 */
final class LiteralBoolean implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value)) {
            return;
        }

        // The message names the spellings that are refused, because the caller is a program and its
        // author's next question is "which of these did I send?". `1` and `"true"` are the two that
        // actually turn up: the first from an integer-typed counter, the second from a form encoder.
        $fail(
            'The :attribute field must be a JSON boolean — `true` or `false`. The values `1`, `0`, '
            .'`"1"`, `"0"`, `"true"` and `"false"` are refused rather than coerced: this flag is read '
            .'with a strict comparison, so a coerced value would be accepted here and applied as its '
            .'opposite.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Web\ExactOrigin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A widget origin allow-list entry: an EXACT origin, no wildcards, in the serialisation a browser
 * actually sends.
 *
 * ── THIS IS A SECURITY CONTROL WEARING A VALIDATION RULE ──────────────────────────────────────
 *
 * A row in `bot_domains` is what lets a page on the public internet boot a chat widget that speaks
 * with this organization's credential, on this organization's corpus, against this organization's
 * quota. `bot_domains_origin_exact` is the database's statement of the same grammar and is the
 * authority for every writer that is not an HTTP request; this rule exists so an HTTP caller gets a
 * 422 keyed on the field they typed into rather than SQLSTATE 23514 rendered as a 500 — a bug
 * report about the server for what is plainly a bad request, on a form that has an input for it.
 *
 * ── A RULE OBJECT AND NOT A `regex:` STRING, FOR THE REASON `ReadableThemeColor` RECORDS ──────
 *
 * `kb:dump-form-rules` records a closure as the literal string `Closure`, which its own docblock
 * calls "a rule no client can be generated from — treat it as a finding, not as noise". A rule
 * OBJECT is recorded by class name, which a generator and a drift test can both key on. And a
 * `regex:` string could carry only part of the truth here: the default-port and trailing-slash
 * NORMALISATIONS are not expressible as a pattern at all, so a published regex would describe a
 * grammar that is neither what is accepted nor what is stored.
 *
 * ── IT IS STRICTER THAN THE DATABASE CHECK, DELIBERATELY ──────────────────────────────────────
 *
 * `ExactOrigin` refuses `:0`, `:00443`, a trailing dot and a non-ASCII host, all of which the CHECK
 * would either accept or reject with a constraint name. Every value this rule admits is one the
 * CHECK admits; the reverse does not hold, and the gap is entirely values that would be stored as
 * rows a real `Origin` header can never equal.
 *
 * ── THE MESSAGE IS THE REFUSAL'S OWN, NOT A GENERIC ONE ───────────────────────────────────────
 *
 * `ExactOrigin::parse()` returns the sentence explaining what is wrong — a wildcard, a path,
 * userinfo, an IPv6 literal, a bad port, a host it cannot store — because "invalid origin" leaves
 * an operator with nothing to do but guess, and the guess most people make on an allow-list is to
 * try a wildcard.
 */
final class ExactWidgetOrigin implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            // `string` in the rule list already covers this for an HTTP caller. The guard is here
            // because a rule object is reachable from `Validator::make()` anywhere, and an array
            // reaching `ExactOrigin::parse()` would be a TypeError rendered as a 500.
            $fail('The :attribute must be an origin, given as a string.');

            return;
        }

        $parsed = ExactOrigin::parse($value);

        if (is_string($parsed)) {
            $fail($parsed);
        }
    }
}

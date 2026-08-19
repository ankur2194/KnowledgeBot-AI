<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Theme\OklchColor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A tenant-supplied surface colour: a legal `oklch()` triple that can be given readable text.
 *
 * ── WHY THE SECOND HALF IS HERE AND NOT LEFT TO THE RENDERER ──────────────────────────────────
 *
 * `apps/web/src/lib/theme.ts:48` carries a standing FLAG addressed to this service by name:
 *
 *     "Laravel validates `bots.theme` on write and its grammar must carry this same refusal, or the
 *      console will silently fall back to the platform accent for a colour the customer was told
 *      was accepted."
 *
 * `deriveForeground()` returns null when NEITHER fixed text candidate clears 4.5:1 against the
 * supplied surface, and the renderer's answer to that is to DROP the whole declaration and serve
 * the platform default. That is the correct answer at render time — a stylesheet cannot fail — and
 * it is the wrong answer at write time, because the customer is looking at a form that just said
 * "saved". This rule is the refusal that makes the console honest.
 *
 * It is a real hole rather than a rounding problem, and it cannot be closed by picking better
 * candidates: the unreachable band is L in [0.538, 0.634] for some chroma/hue combinations,
 * bottoming out at 4.143:1, and even pure black against pure white only reaches 4.583:1 at its
 * crossover. `OklchColor` records the measurement and its source.
 *
 * ── A RULE OBJECT AND NOT A CLOSURE, AND NOT A `regex:` STRING ────────────────────────────────
 *
 * `kb:dump-form-rules` records a closure as the string `Closure`, and its own docblock calls that
 * "a rule no client can be generated from — treat it as a finding, not as noise". A rule OBJECT is
 * recorded by class name, which is a name a client generator and `form-drift.test.ts` can both key
 * on. And a bare `regex:` string could carry only the first half of the rule: the contrast refusal
 * is arithmetic over a gamut-mapped sRGB triple and is not expressible as a pattern at all, so
 * splitting the two would publish a grammar that is half the truth.
 *
 * ── THE MESSAGE NAMES THE REMEDY, BECAUSE "INVALID COLOUR" IS UNACTIONABLE ────────────────────
 *
 * A designer who typed a legal, pretty, mid-lightness brand colour needs to be told that the
 * problem is the LIGHTNESS and which direction to move it, or the only available next step is
 * guessing. The two failures therefore have two messages: one for a value that is not an `oklch()`
 * at all, one for a value that is a perfectly good colour we cannot put text on.
 */
final class ReadableThemeColor implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            // `string` in the rule list already covers this for an HTTP caller; the guard is here
            // because a rule object is reachable from `Validator::make()` anywhere, and an array
            // reaching `OklchColor::parse()` would be a TypeError rendered as a 500.
            $fail('The :attribute must be an `oklch()` colour, given as a string.');

            return;
        }

        $color = OklchColor::parse($value);

        if ($color === null) {
            $fail(
                'The :attribute must be a CSS `oklch()` colour — for example `oklch(0.525 0.235 264)`. '
                .'Lightness is 0-1, chroma is 0-0.5, and hue is 0-360; an optional `/ alpha` is '
                .'accepted and ignored, because a translucent colour has no contrast ratio until it '
                .'is composited over a page we do not control. Hex, `rgb()` and named colours are '
                .'not accepted: the renderer derives a whole accent ramp from this value in OKLCH, '
                .'and a conversion here would be a second colour space to keep in step.',
            );

            return;
        }

        if (! $color->isReadable()) {
            $fail(
                'The :attribute is a valid colour, but no text this platform is willing to draw '
                .'reads on it: neither the light nor the dark foreground clears the 4.5:1 contrast '
                .'floor, so a button in this colour would ship with unreadable text. Move the '
                .'lightness away from the middle of the range — noticeably darker or noticeably '
                .'lighter — and the same hue will pass. Accepting it here would store a colour the '
                .'renderer refuses and silently replaces with the platform accent, which is the '
                .'one outcome nobody could debug from the console.',
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Theme;

/**
 * OKLCH colour maths, dependency-free — and the SECOND copy of `apps/web/src/lib/color.ts` on
 * purpose.
 *
 * ── WHY A SECOND COPY EXISTS AT ALL, WHEN THIS REPOSITORY'S STANDING RULE IS THAT THE DRIFTING
 *    COPY IS ALWAYS THE ONE THAT SHIPS ──────────────────────────────────────────────────────────
 *
 * Because the two copies answer at different times and one of them cannot be removed. `theme.ts`
 * runs at RENDER time, in the browser and in the Next.js route handler, and it DROPS a value it
 * cannot use — the platform default is always a valid answer, so the renderer never fails. This
 * copy runs at WRITE time, in Laravel, and its job is to REFUSE. Deleting either one produces a
 * different, specific defect:
 *
 *   without the renderer's copy   a row written before this validator existed, or by a fixture, or
 *                                 by a repair script, reaches `<style>` unchecked. That is CSS
 *                                 injection with a tenant string, which is the thing theme.ts opens
 *                                 by describing.
 *   without THIS copy             the console tells a customer their brand colour was accepted,
 *                                 stores it, and then silently serves the platform accent forever.
 *                                 `apps/web/src/lib/theme.ts:48` raises exactly that as a standing
 *                                 FLAG addressed to this service, by name.
 *
 * So the rule this file lives under is not "do not duplicate" but "the two must stay
 * byte-identical in BEHAVIOUR, and a change to either is a change to both". theme.ts says the same
 * sentence from its side ("It must stay byte-identical to Laravel's rule; whichever way that is
 * settled, both sides move together"). `tests/Contract/ThemeGrammarParityTest.php` is what holds
 * the pair together mechanically rather than by memory: it re-reads the regex and the two
 * candidate colours out of the TypeScript source and fails when they move.
 *
 * ── WHAT IS TRANSCRIBED, AND FROM WHERE ───────────────────────────────────────────────────────
 *
 * `SYNTAX`, `MAX_CHROMA`, the numeric range checks   apps/web/src/lib/color.ts `parseOklch`
 * `oklabToLinearSrgb`, the gamut-mapping search      apps/web/src/lib/color.ts `oklchToSrgb`
 * `relativeLuminance`, `contrastRatio`               apps/web/src/lib/color.ts, WCAG 2.2
 * `ON_DARK`, `ON_LIGHT`, the 4.5 floor               apps/web/src/lib/theme.ts `deriveForeground`
 *
 * ── THE GAMUT MAPPING IS NOT OPTIONAL DECORATION, AND THAT IS THE SUBTLE PART ─────────────────
 *
 * A contrast ratio is computed from an sRGB triple, and an OKLCH colour outside the sRGB gamut has
 * no triple until something decides one. CSS Color 4 §13.2 GAMUT-MAPS by walking chroma down with
 * lightness and hue fixed; the naive alternative, clipping each channel, preserves the hue angle
 * and destroys the lightness relationship. Since lightness is the only axis contrast actually
 * depends on, a clipping implementation here would compute a contrast ratio for a colour the
 * browser will not render and would accept or refuse the wrong values — near the boundary of the
 * unreachable band, which is precisely where this class is asked the question.
 */
final class OklchColor
{
    /**
     * The accepted grammar, transcribed from `OKLCH_SYNTAX` in apps/web/src/lib/color.ts.
     *
     * DELIBERATELY PERMISSIVE ABOUT NOTATION AND STRICT ABOUT RANGE. `oklch(1 0 0)`,
     * `oklch(.5 .1 20)` and `oklch(0.205 0.014 266 / 0.45)` are all legitimate spellings of
     * legitimate colours. The pattern this replaced on the TypeScript side required a decimal point
     * in every component and therefore rejected the platform's OWN defaults while failing closed —
     * safe and wrong, which is the hardest kind of defect to notice. The bounds are checked
     * numerically in `parse()` rather than in the pattern, because a regex that also enforces
     * `0 <= l <= 1` is unreadable and gets copied wrong.
     *
     * NO `u` MODIFIER. PCRE's `\s` without it is the ASCII set, which is a strict SUBSET of
     * JavaScript's; a value this pattern accepts is therefore always one `parseOklch` accepts too,
     * and never the reverse. That is the safe direction: the renderer is the one that drops.
     */
    private const SYNTAX = '/^oklch\(\s*(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})'
        .'\s+(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})'
        .'\s+(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})'
        .'\s*(?:\/\s*(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})\s*)?\)$/';

    /** Chroma above this is beyond any real display primary and is treated as malformed input. */
    private const MAX_CHROMA = 0.5;

    /**
     * The two candidates for any derived `-foreground`, from theme.ts.
     *
     * A TENANT NEVER SUPPLIES A FOREGROUND. `kb-design-language`: "Contrast is derived, never
     * chosen." The tenant supplies the surface and the renderer picks the text — returning the
     * better of two FIXED candidates rather than computing an arbitrary colour, which is what keeps
     * the result inside the palette and therefore contrast-checkable.
     */
    private const ON_DARK = [0.985, 0.0, 0.0];

    private const ON_LIGHT = [0.205, 0.014, 266.0];

    /** WCAG 2.2 AA for body text. Below it, neither candidate reads and the colour is refused. */
    private const CONTRAST_FLOOR = 4.5;

    private function __construct(
        public readonly float $l,
        public readonly float $c,
        public readonly float $h,
    ) {}

    /**
     * `null` for anything that is not a well-formed, in-range `oklch()` — never a guess.
     *
     * The ALPHA component is parsed and then discarded, exactly as `deriveForeground` discards it:
     * a translucent colour has no contrast ratio until it is composited over a known backdrop, and
     * the backdrop is whatever page the widget is embedded in. Accepting the syntax and ignoring
     * the value keeps this side from refusing a spelling the renderer accepts; refusing translucency
     * outright would be a THIRD grammar, agreeing with neither.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match(self::SYNTAX, trim($value), $matches) !== 1) {
            return null;
        }

        $l = (float) $matches[1];
        $c = (float) $matches[2];
        $h = (float) $matches[3];
        $alpha = ($matches[4] ?? '') === '' ? null : (float) $matches[4];

        if ($l < 0.0 || $l > 1.0) {
            return null;
        }

        if ($c < 0.0 || $c > self::MAX_CHROMA) {
            return null;
        }

        if ($h < 0.0 || $h > 360.0) {
            return null;
        }

        if ($alpha !== null && ($alpha < 0.0 || $alpha > 1.0)) {
            return null;
        }

        return new self($l, $c, $h);
    }

    /**
     * Whether this colour can be given readable text — the one test a brand colour has to pass.
     *
     * `deriveForeground` in theme.ts returns `null` when NEITHER fixed candidate clears 4.5:1, and
     * that is a real hole rather than a rounding problem: two fixed text colours cannot cover the
     * whole lightness axis. Measured over the accepted grammar, the unreachable band is L in
     * [0.538, 0.634] for some chroma/hue combinations, bottoming out at 4.143:1 — and even pure
     * black against pure white only reaches 4.583:1 at its crossover, so no choice of candidates
     * closes it.
     *
     * A colour that lands there is REFUSED here, which is the whole reason this class exists. The
     * renderer's answer to the same colour is to drop it and serve the platform accent, silently.
     */
    public function isReadable(): bool
    {
        return max(
            self::contrastRatio(self::ON_DARK, $this->toSrgb()),
            self::contrastRatio(self::ON_LIGHT, $this->toSrgb()),
        ) >= self::CONTRAST_FLOOR;
    }

    /**
     * OKLCH -> gamma-encoded sRGB, gamut-mapped by chroma reduction (CSS Color 4 §13.2).
     *
     * The binary search walks chroma down with lightness and hue FIXED, stopping as soon as the
     * clipped result is within dEOK 0.02 of the reduced colour. Lightness — the axis every contrast
     * floor depends on — is preserved exactly; only saturation is spent.
     *
     * @return array{0: float, 1: float, 2: float} r, g, b in 0..1
     */
    public function toSrgb(): array
    {
        $direct = self::oklabToLinearSrgb(self::toLab($this->l, $this->c, $this->h));

        if (self::inGamut($direct)) {
            return [
                self::linearToGamma($direct[0]),
                self::linearToGamma($direct[1]),
                self::linearToGamma($direct[2]),
            ];
        }

        // Pure white and pure black are always representable; the search below is undefined at c=0.
        if ($this->l >= 1.0) {
            return [1.0, 1.0, 1.0];
        }

        if ($this->l <= 0.0) {
            return [0.0, 0.0, 0.0];
        }

        $low = 0.0;
        $high = $this->c;
        $best = 0.0;

        for ($i = 0; $i < 32 && $high - $low > 1e-5; $i++) {
            $candidate = ($low + $high) / 2;
            $lab = self::toLab($this->l, $candidate, $this->h);
            $linear = self::oklabToLinearSrgb($lab);

            if (self::inGamut($linear)) {
                $best = $candidate;
                $low = $candidate;

                continue;
            }

            $clipped = [
                self::clampChannel($linear[0]),
                self::clampChannel($linear[1]),
                self::clampChannel($linear[2]),
            ];

            if (self::deltaEOk($lab, self::linearSrgbToOklab($clipped)) < 0.02) {
                $best = $candidate;

                break;
            }

            $high = $candidate;
        }

        $linear = self::oklabToLinearSrgb(self::toLab($this->l, $best, $this->h));

        return [
            self::linearToGamma(self::clampChannel($linear[0])),
            self::linearToGamma(self::clampChannel($linear[1])),
            self::linearToGamma(self::clampChannel($linear[2])),
        ];
    }

    /**
     * WCAG 2.2 contrast ratio, 1..21, between a candidate text colour and a surface.
     *
     * @param  array{0: float, 1: float, 2: float}  $textOklch  l, c, h
     * @param  array{0: float, 1: float, 2: float}  $surfaceSrgb  r, g, b
     */
    private static function contrastRatio(array $textOklch, array $surfaceSrgb): float
    {
        $text = new self($textOklch[0], $textOklch[1], $textOklch[2]);

        $a = self::relativeLuminance($text->toSrgb());
        $b = self::relativeLuminance($surfaceSrgb);

        [$hi, $lo] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $srgb
     */
    private static function relativeLuminance(array $srgb): float
    {
        $channel = static function (float $n): float {
            $v = self::clampChannel($n);

            // The inner parentheses are load-bearing: `**` binds TIGHTER than `/` in PHP, so
            // `($v + 0.055) / 1.055 ** 2.4` is `($v + 0.055) / (1.055 ** 2.4)` — a different
            // function, still monotonic, still returning plausible ratios, and wrong by enough to
            // move a colour across the 4.5:1 floor.
            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($srgb[0]) + 0.7152 * $channel($srgb[1]) + 0.0722 * $channel($srgb[2]);
    }

    /**
     * @return array{0: float, 1: float, 2: float} L, a, b
     */
    private static function toLab(float $l, float $c, float $h): array
    {
        $rad = ($h * M_PI) / 180.0;

        return [$l, $c * cos($rad), $c * sin($rad)];
    }

    /**
     * Oklab -> LINEAR sRGB (Ottosson). Components may fall outside 0..1; that is what gamut
     * mapping is for.
     *
     * @param  array{0: float, 1: float, 2: float}  $lab
     * @return array{0: float, 1: float, 2: float}
     */
    private static function oklabToLinearSrgb(array $lab): array
    {
        [$bigL, $a, $b] = $lab;

        $l = ($bigL + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($bigL - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($bigL - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /**
     * Linear sRGB -> Oklab. Only used to measure how far the clip moved a colour inside the
     * gamut-mapping loop.
     *
     * @param  array{0: float, 1: float, 2: float}  $srgb
     * @return array{0: float, 1: float, 2: float}
     */
    private static function linearSrgbToOklab(array $srgb): array
    {
        [$r, $g, $b] = $srgb;

        $l = self::cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = self::cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = self::cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * `Math.cbrt`'s sign behaviour, which `**(1/3)` does NOT have in PHP: a negative base with a
     * fractional exponent yields NAN, and a NAN here would silently poison the whole gamut search.
     */
    private static function cbrt(float $n): float
    {
        return $n < 0 ? -((-$n) ** (1 / 3)) : $n ** (1 / 3);
    }

    /**
     * Perceptual distance in Oklab. The CSS Color 4 gamut-mapping loop stops at 0.02.
     *
     * @param  array{0: float, 1: float, 2: float}  $a
     * @param  array{0: float, 1: float, 2: float}  $b
     */
    private static function deltaEOk(array $a, array $b): float
    {
        return sqrt(
            ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2,
        );
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $linear
     */
    private static function inGamut(array $linear, float $epsilon = 1e-6): bool
    {
        foreach ($linear as $channel) {
            if ($channel < -$epsilon || $channel > 1 + $epsilon) {
                return false;
            }
        }

        return true;
    }

    private static function clampChannel(float $n): float
    {
        return $n < 0.0 ? 0.0 : ($n > 1.0 ? 1.0 : $n);
    }

    private static function linearToGamma(float $n): float
    {
        return $n <= 0.0031308 ? 12.92 * $n : 1.055 * $n ** (1 / 2.4) - 0.055;
    }
}

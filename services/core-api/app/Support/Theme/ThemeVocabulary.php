<?php

declare(strict_types=1);

namespace App\Support\Theme;

/**
 * The closed vocabulary of `bots.theme` — the three keys a tenant may set, and the radii they may
 * choose from.
 *
 * ── THREE STATEMENTS OF ONE KEY SET, AND EACH CATCHES SOMETHING THE OTHERS CANNOT ─────────────
 *
 *   `bots_theme_vocabulary` (the CHECK)   catches a repair script, a seeder and a fixture — every
 *                                         writer that never runs a FormRequest. It constrains the
 *                                         key set and the value TYPES and stops there.
 *   `KEYS` below (the FormRequest)        constrains the value GRAMMAR, which no CHECK can: whether
 *                                         `primary` is a legal `oklch()` triple, whether `radius` is
 *                                         one of the published values, and whether the colour can be
 *                                         given readable text at all.
 *   `WRITABLE_PROPERTIES` (theme.ts)      the render-time drop. A key absent from its closed set can
 *                                         never reach the DOM whatever this service returns.
 *
 * The create migration names the second of those as belonging here, in as many words: "That rule
 * belongs in the FormRequest, and it is named here so the PR that writes one cannot claim nobody
 * said."
 *
 * ── `RADII` IS A MIRROR OF A GENERATED TypeScript CONSTANT, AND THE MIRROR IS TESTED ──────────
 *
 * The authority is `packages/design-tokens/src/tokens.json` -> `radius.enum`, published as
 * `RADIUS_VALUES` in `packages/design-tokens/generated/index.js`. Laravel cannot import a
 * TypeScript package, and reading a file out of another workspace package at REQUEST time would
 * make this service's behaviour depend on a build artifact that is not in its container image — so
 * the list is transcribed and the transcription is held in place by
 * `tests/Contract/ThemeGrammarParityTest.php`, which parses the generated module and fails on any
 * divergence. A value that is not in that set is one the renderer drops on the floor: theme.ts
 * checks membership with an exact `Set.has`, not a shape rule.
 */
final class ThemeVocabulary
{
    /**
     * The only keys `bots.theme` may carry, in the order the CHECK constraint lists them.
     *
     * EVERY OTHER CUSTOM PROPERTY IS DERIVED AT RENDER TIME AND IS EXPLICITLY NEVER FORM-SETTABLE
     * — the whole `-foreground` and accent-ramp family. `kb-design-language`: "Contrast is derived,
     * never chosen." A fourth key here would be a value stored forever and rendered nowhere.
     *
     * @var list<string>
     */
    public const KEYS = ['primary', 'accent', 'radius'];

    /**
     * The six radii, transcribed from `RADIUS_VALUES` in packages/design-tokens.
     *
     * @var list<string>
     */
    public const RADII = ['0rem', '0.25rem', '0.5rem', '0.625rem', '0.75rem', '1rem'];

    /**
     * The `array:` rule argument that closes the key set, e.g. `array:primary,accent,radius`.
     *
     * Built from `KEYS` rather than written as a literal so the rule, the DTO and this constant
     * cannot drift — and so `packages/contracts/rules/` records the real set rather than a copy of
     * it somebody updated in one of two places.
     */
    public static function arrayRule(): string
    {
        return 'array:'.implode(',', self::KEYS);
    }
}

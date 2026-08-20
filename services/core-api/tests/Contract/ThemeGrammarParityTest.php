<?php

declare(strict_types=1);

use App\Support\Theme\OklchColor;
use App\Support\Theme\ThemeVocabulary;

/*
|--------------------------------------------------------------------------
| The bot theme grammar, held against the two files it is a transcription of
|--------------------------------------------------------------------------
|
| `App\Support\Theme\OklchColor` is a SECOND COPY of apps/web/src/lib/color.ts, and
| `ThemeVocabulary::RADII` is a second copy of `RADIUS_VALUES` in packages/design-tokens. This
| repository's standing rule is that the drifting copy is always the one that ships, so a second
| copy needs both a reason to exist and a mechanism that keeps it honest. The reason is in
| OklchColor's docblock: the two answer at DIFFERENT TIMES and neither can be deleted — the renderer
| DROPS a value it cannot use, because a stylesheet cannot fail, and Laravel must REFUSE it,
| because a form that says "saved" and then serves the platform default is a lie the customer
| cannot debug. THIS FILE IS THE MECHANISM.
|
| IT READS THE TYPESCRIPT RATHER THAN RESTATING IT. Every constant below is parsed out of the file
| that owns it; a test carrying its own copy of the regex would be a THIRD copy, and the first one
| to go stale.
|
| WHY THIS IS A CONTRACT TEST AND NOT A UNIT TEST. It asserts an agreement between two runtimes
| across a package boundary, which is what tests/Contract/ is for — the same category as the OpenAPI
| document and the internal error taxonomy. It touches no database and no HTTP.
|
| IT DOES NOT SKIP WHEN THE FILES ARE ABSENT. Both are tracked in git; a missing one is a broken
| checkout or a moved file, and a skip would turn "the grammar drifted" into a silent pass.
*/

/**
 * The capture groups of a pattern that MUST match, as a plain list.
 *
 * ── WHY THIS EXISTS RATHER THAN `preg_match($p, $s, $m)` AT EACH CALL SITE ────────────────────
 *
 * Two reasons and both are about honesty rather than tidiness. `preg_match` returns 0 for "no
 * match" and leaves `$matches` empty, so a call site that read `$m[1]` after a FAILED match would
 * compare a PHP constant against the empty string — which is a comparison that fails for the right
 * reason today and would pass the moment somebody made the constant empty. And level-8 analysis
 * infers the union `array{}|array{...}` from `preg_match`, so every group read is an
 * `offsetAccess.notFound` that can only be silenced by asserting the match FIRST, which is exactly
 * what this does.
 *
 * `array_values()` is what turns `preg_match`'s inferred shape union into a plain `list<string>`
 * the call sites can index without the analyser objecting to an offset it cannot prove — and it is
 * a no-op at runtime, because an unnamed capture group set is already a list.
 *
 * @return list<string> group 0 is the whole match, as `preg_match` yields it
 */
function requiredMatch(string $pattern, string $subject, string $what): array
{
    $matches = [];

    expect(preg_match($pattern, $subject, $matches))->toBe(1, $what);

    return array_values($matches);
}

/**
 * Read a file from the monorepo ROOT, which is two levels above this service.
 */
function repoFile(string $relative): string
{
    $path = dirname(base_path(), 2).'/'.$relative;

    expect(is_file($path))->toBeTrue(
        "{$relative} is missing. It is tracked in git, so this is a broken checkout or a moved "
        .'file — and either way the PHP transcription it holds in place is now unheld.',
    );

    return (string) file_get_contents($path);
}

it('publishes exactly the radii the design tokens publish', function (): void {
    $source = repoFile('packages/design-tokens/generated/index.js');

    $m = requiredMatch(
        '/export const RADIUS_VALUES = Object\.freeze\(\[([^\]]*)\]\)/',
        $source,
        'RADIUS_VALUES could not be located in the generated token module',
    );

    preg_match_all("/'([^']*)'/", $m[1], $values);

    // THE POSITIVE CONTROL FOR THE PARSE ITSELF: an empty match list would make the comparison below
    // pass against an empty PHP constant, which is the one way this test could go green while
    // asserting nothing.
    expect($values[1])->not->toBeEmpty('the radius list parsed as empty, so the comparison proves nothing');

    // EXACT EQUALITY, IN ORDER. The renderer matches this string with `Set.has` — an exact
    // comparison, not a shape rule — so a value in one list and not the other is a radius Laravel
    // accepts and the browser drops, or one Laravel refuses and the console offers.
    expect(ThemeVocabulary::RADII)->toBe($values[1]);
});

it('accepts exactly the `oklch()` grammar the renderer parses', function (): void {
    $source = repoFile('apps/web/src/lib/color.ts');

    // THE REGEX IS READ OUT OF THE TypeScript SOURCE, not restated here. The JavaScript literal and
    // the PHP pattern use the same delimiter and the same escapes, so the bodies are comparable
    // byte for byte once the delimiters are stripped — which is the strongest form this assertion
    // can take and the reason the PHP constant is written as a concatenation of the same fragments
    // rather than in a tidier equivalent spelling.
    $m = requiredMatch(
        '/const OKLCH_SYNTAX =\s*\/(.*)\/;/s',
        $source,
        'OKLCH_SYNTAX could not be located in lib/color.ts',
    );

    $php = (new \ReflectionClassConstant(OklchColor::class, 'SYNTAX'))->getValue();

    expect($php)->toBeString();

    // Strip the PHP delimiters (`/` … `/`) and compare the bodies.
    expect(substr((string) $php, 1, -1))->toBe(trim($m[1]));

    // AND THE NUMERIC BOUND THE PATTERN DELIBERATELY DOES NOT CARRY. color.ts checks the ranges
    // numerically because "a regex that also enforces `0 <= l <= 1` is unreadable and gets copied
    // wrong"; the chroma ceiling is the one of the three that is not implied by the units.
    $chroma = requiredMatch(
        '/const MAX_CHROMA = ([0-9.]+);/',
        $source,
        'MAX_CHROMA could not be located in lib/color.ts',
    );

    $maxChroma = (new \ReflectionClassConstant(OklchColor::class, 'MAX_CHROMA'))->getValue();

    expect((float) $chroma[1])->toBe((float) $maxChroma);
});

it('derives its foreground from the same two candidates and the same floor as the renderer', function (): void {
    $source = repoFile('apps/web/src/lib/theme.ts');

    $dark = requiredMatch(
        '/const ON_DARK: Oklch = \{ l: ([0-9.]+), c: ([0-9.]+), h: ([0-9.]+) \}/',
        $source,
        'ON_DARK could not be located in lib/theme.ts',
    );
    $light = requiredMatch(
        '/const ON_LIGHT: Oklch = \{ l: ([0-9.]+), c: ([0-9.]+), h: ([0-9.]+) \}/',
        $source,
        'ON_LIGHT could not be located in lib/theme.ts',
    );

    $reflect = static fn (string $name): mixed => (new \ReflectionClassConstant(OklchColor::class, $name))->getValue();

    expect($reflect('ON_DARK'))->toBe([(float) $dark[1], (float) $dark[2], (float) $dark[3]])
        ->and($reflect('ON_LIGHT'))->toBe([(float) $light[1], (float) $light[2], (float) $light[3]]);

    // THE FLOOR, read out of `deriveForeground`'s own return expression. A tenant colour is refused
    // here and dropped there at exactly the same threshold, or one of the two is deciding something
    // the other will contradict.
    $floor = requiredMatch(
        '/Math\.max\(onDark, onLight\) >= ([0-9.]+)/',
        $source,
        'the contrast floor could not be located in deriveForeground()',
    );

    expect((float) $floor[1])->toBe((float) $reflect('CONTRAST_FLOOR'));
});

it('agrees with the renderer on which colours are legal and which are readable', function (
    string $value,
    bool $parses,
    bool $readable,
): void {
    // BEHAVIOUR PARITY, NOT ONLY CONSTANT PARITY. Two implementations can share every constant and
    // still disagree — a gamut-mapping loop that CLIPPED instead of reducing chroma would preserve
    // the hue and destroy the lightness relationship, and lightness is the only axis contrast
    // depends on. The rows below are the cases where that difference is visible.
    $color = OklchColor::parse($value);

    expect($color !== null)->toBe($parses, "parse disagreement on: {$value}");

    if ($color !== null) {
        expect($color->isReadable())->toBe($readable, "readability disagreement on: {$value}");
    }
})->with([
    // THE PLATFORM ACCENT, which is also the shape the pattern this grammar replaced on the
    // TypeScript side used to REJECT: it required a decimal point in every component, so our own
    // defaults were dropped, silently, while failing closed.
    'the platform accent' => ['oklch(0.525 0.235 264)', true, true],
    'an integer lightness and zero chroma' => ['oklch(1 0 0)', true, true],
    'a leading-dot spelling' => ['oklch(.205 .014 266)', true, true],
    // ALPHA IS PARSED AND DISCARDED. A translucent colour has no contrast ratio until it is
    // composited over a backdrop, and the backdrop is whatever page the widget is embedded in —
    // so the syntax is accepted (refusing it would be a THIRD grammar, agreeing with neither side)
    // and the value is ignored.
    'an alpha component' => ['oklch(0.205 0.014 266 / 0.45)', true, true],
    // THE UNREACHABLE BAND. A perfectly legal colour that NEITHER fixed text candidate clears 4.5:1
    // against — measured as L in [0.538, 0.634] for some chroma/hue combinations, bottoming out at
    // 4.143:1. This is the row the whole file exists for: the renderer drops it and serves the
    // platform accent, so a Laravel grammar that accepted it would tell the customer their brand
    // colour was saved and quietly ignore it.
    'a mid-lightness colour no text reads on' => ['oklch(0.58 0.2 264)', true, false],
    'white' => ['oklch(1 0 0)', true, true],
    'black' => ['oklch(0 0 0)', true, true],
    'a hex colour' => ['#4f46e5', false, false],
    'an rgb() colour' => ['rgb(79 70 229)', false, false],
    'a bare token name' => ['var(--primary)', false, false],
    'a lightness above 1' => ['oklch(1.5 0.2 264)', false, false],
    'a hue above 360' => ['oklch(0.5 0.2 400)', false, false],
    'a chroma beyond any display primary' => ['oklch(0.5 0.9 264)', false, false],
    'a CSS rule smuggled after a legal colour' => ['oklch(0.5 0.1 264)} body{background:red', false, false],
    'seven fractional digits' => ['oklch(0.5000000 0.1 264)', false, false],
    'a missing component' => ['oklch(0.5 0.1)', false, false],
]);

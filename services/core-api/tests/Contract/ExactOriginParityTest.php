<?php

declare(strict_types=1);

use App\Support\Web\ExactOrigin;

/*
|--------------------------------------------------------------------------
| The widget origin grammar, and the DIRECTION its two implementations must differ in
|--------------------------------------------------------------------------
|
| `App\Support\Web\ExactOrigin` is the authority: an RFC 6454 serialisation, three named
| normalisations and eleven refusals, each carrying the sentence a form renders.
| `botDomainCreateSchema` in packages/contracts/src/forms/bot-domain.ts is the client mirror, and it
| is DELIBERATELY INCOMPLETE — it carries the length, the type and the two-scheme prefix, and leaves
| every other refusal to a 422 whose message is `ExactOrigin::parse()`'s own return value.
|
| ── WHY THAT ASYMMETRY NEEDS A TEST AND THE THEME GRAMMAR'S SYMMETRY DID NOT ──────────────────────
|
| ThemeGrammarParityTest holds two implementations that must AGREE, so equality is the assertion.
| Here they must not: a schema that refused everything the server refuses would be a third copy of a
| security control, and the module docblock argues at length that it should not exist.
|
| What is left is a ONE-DIRECTIONAL property, and only one of its two failure modes is harmful:
|
|   the client accepts what the server refuses    a 422 with the server's sentence. Working as
|                                                 designed — this is the whole point of the design.
|   THE CLIENT REFUSES WHAT THE SERVER ACCEPTS    THE BUG. The operator is told their origin is
|                                                 invalid by a form that never asked, there is no
|                                                 422 to carry a reason because no request was made,
|                                                 and the origin they may legitimately embed on is
|                                                 unreachable. Nothing anywhere is red.
|
| So the assertion is an IMPLICATION, `server accepts ⇒ client accepts`, and never an equality.
|
| ── HOW THE CLIENT SIDE IS EVALUATED, AND THE ONE THING THAT MAKES IT HONEST ──────────────────────
|
| The regex and the length ceiling are PARSED OUT OF THE TypeScript, exactly as ThemeGrammarParityTest
| parses OKLCH_SYNTAX and MAX_CHROMA — a test carrying its own copy would be a third copy and the
| first to go stale. The chain around them (`.trim().min(1).max().regex()`) is transcribed rather
| than parsed, which would be the weak point, so the transcription is PINNED: the test reads the set
| of checks the schema actually declares and fails if it is not exactly the four modelled here. A
| fifth check added client-side — which is precisely the change that can break the direction — cannot
| slip past a model that does not know about it.
|
| ── HELPERS ARE NAMED FOR THIS FILE ───────────────────────────────────────────────────────────────
|
| Pest declares test-file helpers at FILE SCOPE, so `repoFile()` and `requiredMatch()` in
| ThemeGrammarParityTest are already taken and redeclaring either is a fatal in a full run and only
| in a full run. Hence the `origin` prefixes.
|
| IT DOES NOT SKIP WHEN THE TypeScript IS ABSENT. The file is tracked in git; a missing one is a
| broken checkout or a moved file, and a skip would turn "the mirror drifted" into a silent pass.
*/

/**
 * Read a file from the monorepo ROOT, which is two levels above this service.
 */
function originRepoFile(string $relative): string
{
    $path = dirname(base_path(), 2).'/'.$relative;

    expect(is_file($path))->toBeTrue(
        "{$relative} is missing. It is tracked in git, so this is a broken checkout or a moved "
        .'file — and either way the client mirror it holds is now unheld.',
    );

    return (string) file_get_contents($path);
}

/**
 * The capture groups of a pattern that MUST match.
 *
 * `preg_match` returns 0 on no match and leaves `$matches` empty, so a call site reading `$m[1]`
 * after a failed match compares against the empty string — a comparison that fails for the right
 * reason today and passes the moment somebody makes the other side empty.
 *
 * @return list<string>
 */
function originRequiredMatch(string $pattern, string $subject, string $what): array
{
    $matches = [];

    expect(preg_match($pattern, $subject, $matches))->toBe(1, $what);

    return array_values($matches);
}

/** The client mirror's source, read once per test. */
function originSchemaSource(): string
{
    return originRepoFile('packages/contracts/src/forms/bot-domain.ts');
}

/**
 * `ORIGIN_SCHEME` from the TypeScript, translated into a PCRE pattern.
 *
 * The JavaScript literal and a PHP pattern share the `/` delimiter and the same escapes, so the
 * body transfers byte for byte. Only the `i` flag is carried across — any other flag would change
 * what the pattern means and is a loud failure rather than a silent reinterpretation.
 */
function originClientSchemePattern(): string
{
    $m = originRequiredMatch(
        '/const ORIGIN_SCHEME = \/(.*)\/([a-z]*);/',
        originSchemaSource(),
        'ORIGIN_SCHEME could not be located in packages/contracts/src/forms/bot-domain.ts',
    );

    expect(str_split($m[2] === '' ? 'i' : $m[2]))->each->toBeIn(
        ['i'],
        'ORIGIN_SCHEME carries a regex flag this test does not know how to translate into PCRE',
    );

    return '/'.$m[1].'/'.$m[2];
}

/** `ORIGIN_MAX` from the TypeScript. */
function originClientMaxLength(): int
{
    $m = originRequiredMatch(
        '/const ORIGIN_MAX = ([0-9]+);/',
        originSchemaSource(),
        'ORIGIN_MAX could not be located in packages/contracts/src/forms/bot-domain.ts',
    );

    return (int) $m[1];
}

/**
 * Whether `botDomainCreateSchema` would accept `$value`.
 *
 * The four checks the schema declares, in the order Zod runs them: trim, then a lower bound, then
 * the ceiling, then the scheme prefix. That this really is the whole list is asserted separately —
 * see the check-set test — because a transcription that has silently fallen behind is the one way
 * this file could go green while the property it names is broken.
 */
function originClientAccepts(string $value): bool
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return false;
    }

    // `.max()` counts UTF-16 code units in JavaScript and this counts codepoints, so an origin
    // carrying an astral character could in principle differ. It cannot in practice: every
    // non-ASCII host is refused by `ExactOrigin::HOST`, so no such value is ever on the accepting
    // side of the implication this file asserts.
    if (mb_strlen($trimmed) > originClientMaxLength()) {
        return false;
    }

    return preg_match(originClientSchemePattern(), $trimmed) === 1;
}

/** Whether `ExactOrigin` would store `$value`. */
function originServerAccepts(string $value): bool
{
    return ExactOrigin::parse($value) instanceof ExactOrigin;
}

/**
 * A legal origin of EXACTLY `$length` characters.
 *
 * `https://` plus dot-separated labels of at most 63 characters, which is what the host grammar
 * admits. The boundary rows are the ones the drift suite's own generator exists for, and they are
 * the rows a hand-written corpus always gets one off.
 */
function originOfExactLength(int $length): string
{
    $hostLength = $length - strlen('https://');

    $labels = [];
    $remaining = $hostLength;

    while ($remaining > 63) {
        $labels[] = str_repeat('a', 63);
        $remaining -= 64;   // the label plus the dot that follows it
    }

    $labels[] = str_repeat('b', $remaining);

    return 'https://'.implode('.', $labels);
}

it('models exactly the checks the client schema declares, and no others', function (): void {
    // THE ASSERTION THAT MAKES originClientAccepts() TRUSTWORTHY. Everything below is a claim about
    // a transcription; this is what stops the transcription drifting away from the thing it
    // transcribes. A `.refine()`, a second `.regex()` or a `.startsWith()` added to the schema is a
    // check this file does not evaluate, so the direction property would be asserted against a
    // client that is stricter than the one that ships.
    $source = originSchemaSource();

    $m = originRequiredMatch(
        '/export const botDomainCreateSchema = z\.strictObject\(\{(.*?)^\}\);/ms',
        $source,
        'botDomainCreateSchema could not be located in packages/contracts/src/forms/bot-domain.ts',
    );

    preg_match_all('/\.([a-zA-Z]+)\(/', $m[1], $calls);

    expect($calls[1])->toBe(
        ['string', 'trim', 'min', 'max', 'regex'],
        'the client origin schema declares a different set of checks from the four '
        .'originClientAccepts() evaluates. If a check was ADDED, it must be proven no stricter than '
        .'App\Support\Web\ExactOrigin before this file can model it — a client-side refusal the '
        .'server would have accepted withdraws an origin the operator may legitimately use, with no '
        .'422 anywhere to explain it.',
    );

    // AND THE ONE CONSTANT BOTH SIDES SPELL. `ExactOrigin::MAX_LENGTH` feeds `max:255` in the
    // FormRequest; `ORIGIN_MAX` is the mirror. A client ceiling BELOW the server's is the harmful
    // direction and would not otherwise be visible until an operator pasted a long origin.
    expect(originClientMaxLength())->toBe(ExactOrigin::MAX_LENGTH);
});

it('never refuses an origin the server would store', function (string $value, bool $serverStores): void {
    // THE PROPERTY. Stated as an implication rather than an equality, because the two are meant to
    // disagree — see the header. Only one direction is a defect.
    expect(originServerAccepts($value))->toBe(
        $serverStores,
        "the corpus row disagrees with ExactOrigin about: {$value}",
    );

    if (! $serverStores) {
        return;
    }

    expect(originClientAccepts($value))->toBeTrue(
        "the client schema refuses `{$value}`, which App\Support\Web\ExactOrigin stores. The form "
        .'would reject it before a request is ever made, so there is no 422 and no sentence — the '
        .'operator is simply told an origin they may legitimately embed on is invalid.',
    );
})->with([
    // ── the server STORES these, so the client must accept every one ────────────────────────────
    'the plainest origin' => ['https://example.com', true],
    'a two-label host' => ['https://a.b', true],
    'http, because local development is a real origin' => ['http://localhost:3000', true],
    'an IPv4 loopback with a port' => ['https://127.0.0.1:8443', true],
    'a hyphenated multi-label host with a port' => ['https://sub-domain.example.co.uk:8080', true],
    // THE THREE NORMALISATIONS. Each one is a value the server MUTATES and stores, so a client
    // mirror that refused any of them would refuse an origin the operator can really use.
    'mixed case, which the server folds' => ['HTTPS://Example.COM', true],
    'a trailing slash, which the server drops' => ['https://example.com/', true],
    'the https default port, which the server drops' => ['https://example.com:443', true],
    'the http default port, which the server drops' => ['http://example.com:80', true],
    'surrounding whitespace, which both sides trim' => ['  https://example.com  ', true],
    // A TRAILING NEWLINE IS ACCEPTED, and this row is here because the class docblock's discussion
    // of one reads as though it were refused. It is not: `trim()` runs FIRST, so the control-
    // character guard never sees it and the value normalises to the origin the operator meant.
    // Both sides trim — JavaScript's String.prototype.trim() strips `\n` too — so this is a row the
    // implication genuinely covers rather than a curiosity. The guard's real target is an INTERIOR
    // control character, which is the row further down.
    'a trailing newline, which both sides trim away' => ["https://example.com\n", true],
    // PUNYCODE IS THE FORM THE BROWSER SENDS and the only spelling of an IDN this platform stores.
    'a punycode host' => ['http://xn--bcher-kva.example', true],
    // THE CEILING, from both sides. `max` is the one rule the client genuinely mirrors, so an
    // off-by-one there is a refusal of a value the server stores.
    'an origin of exactly MAX_LENGTH characters' => [originOfExactLength(255), true],
    'an origin one character under the ceiling' => [originOfExactLength(254), true],

    // ── the server REFUSES these. The client is free to accept them and mostly does; the rows are
    //    here so the implication above is not asserted over an all-accepting corpus, and so a
    //    server-side refusal that quietly became an acceptance is caught. ──────────────────────────
    'a wildcard' => ['https://*.example.com', false],
    'a path, which is refused rather than trimmed' => ['https://example.com/widget', false],
    'a query string' => ['https://example.com?tenant=1', false],
    'a fragment' => ['https://example.com#top', false],
    'userinfo' => ['https://trusted.example@evil.test', false],
    'an IPv6 literal' => ['http://[::1]:3000', false],
    'a non-ASCII host' => ['https://bücher.example', false],
    'the Kelvin-sign homoglyph that ASCII folding must not admit' => ["https://\u{212A}elvin.example", false],
    'port zero' => ['https://example.com:0', false],
    'a port with leading zeros' => ['https://example.com:00443', false],
    'a port above 65535' => ['https://example.com:70000', false],
    'a trailing dot on the host' => ['https://example.com.', false],
    'an underscore in the host' => ['https://my_host.example', false],
    'an interior space' => ['https://exa mple.com', false],
    'an interior newline, which is what the control-character guard is for' => ["https://exa\nmple.com", false],
    'no scheme at all' => ['example.com', false],
    'a scheme this platform does not embed from' => ['ftp://example.com', false],
    'nothing' => ['', false],
    'one character over the ceiling' => [originOfExactLength(256), false],
]);

it('is looser than the server in the direction the design says, on values that matter', function (
    string $value,
): void {
    // THE POSITIVE CONTROL FOR THE LOOSENESS ITSELF. Without it the implication above is also
    // satisfied by a client schema that had quietly become a full second copy of the grammar — the
    // outcome src/forms/bot-domain.ts argues against at length, and the one that would make every
    // refusal message the server composes unreachable.
    expect(originServerAccepts($value))->toBeFalse("the corpus row is wrong: the server stores {$value}");

    expect(originClientAccepts($value))->toBeTrue(
        "the client schema now refuses `{$value}` on its own. That is not a bug in itself — the "
        .'value is refused either way — but it means the mirror has grown a refusal of its own, and '
        .'the next one may not be safe. Every refusal belongs in ExactOrigin, whose return value IS '
        .'the sentence the form renders.',
    );
})->with([
    'a wildcard' => 'https://*.example.com',
    'a path' => 'https://example.com/widget',
    'userinfo' => 'https://trusted.example@evil.test',
    'an IPv6 literal' => 'http://[::1]:3000',
    'a non-ASCII host' => 'https://bücher.example',
    'port zero' => 'https://example.com:0',
]);

<?php

declare(strict_types=1);

use App\Logging\KbJsonFormatter;

/*
|--------------------------------------------------------------------------
| Cross-plane parity: the structured-log field contract
|--------------------------------------------------------------------------
|
| App\Logging\KbJsonFormatter is a hand-transcription of
| services/ai-service/app/observability/logging.py. It has to be — PHP cannot import a Python
| module — and the log contract is explicit that its allow-list is "implemented per runtime". Two
| implementations of one closed vocabulary, with nothing comparing them, is exactly the shape
| finding O1 had on the error taxonomy: both halves look right in isolation and a Loki query
| written against one plane silently returns nothing from the other.
|
| THIS FILE IS THE COMPARISON, and it has three sides rather than two, because the AUTHORITY is
| neither implementation: it is
| .claude/skills/kb-observability-conventions/references/logs-health-audit.md, which says the field
| names are "as permanent as metric and span names". So the required eight are read out of the
| skill, and both runtimes are checked against it and against each other.
|
| PARSING PYTHON AND MARKDOWN WITH REGEX IS ONLY ACCEPTABLE BECAUSE OF THE COUNT ASSERTIONS. Every
| extraction below is followed by a count. Without those, a refactor the regex stops matching
| yields an empty set, an empty set agrees with everything pairwise, and the test goes green on the
| day it stops testing anything.
*/

/**
 * The monorepo root: tests/Contract -> tests -> core-api -> services -> <root>.
 */
function kbRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function kbFileAsData(string $relative): string
{
    $path = kbRepoRoot().'/'.$relative;

    expect(is_file($path))->toBeTrue(
        "[{$path}] is missing. This test compares the two planes' log contracts and cannot do that "
        .'from half a checkout — run the suite from the monorepo, and if the file genuinely moved, '
        .'fix this path rather than deleting the test.',
    );

    return (string) file_get_contents($path);
}

/**
 * The eight names the skill requires on every line.
 *
 * @return list<string>
 */
function kbRequiredLogFields(): array
{
    $source = kbFileAsData('.claude/skills/kb-observability-conventions/references/logs-health-audit.md');

    if (preg_match('/\*\*Required on every line:\*\*(.*?)\*\*When applicable:\*\*/s', $source, $body) !== 1) {
        throw new \RuntimeException(
            'Could not locate the "Required on every line" sentence in logs-health-audit.md. Update '
            .'this parser rather than deleting the assertion it feeds — an empty parse agrees with '
            .'everything.',
        );
    }

    preg_match_all('/`([a-z_]+)`/', $body[1], $names);

    return $names[1];
}

/**
 * A Python tuple or frozenset of string literals, by constant name.
 *
 * @return list<string>
 */
function kbPythonLogConstant(string $constant): array
{
    $source = kbFileAsData('services/ai-service/app/observability/logging.py');

    // From the assignment line to the line that is exactly `)`. Deliberately tolerant of the two
    // shapes in that file — a bare tuple and a `frozenset({...})` — and deliberately intolerant of
    // matching nothing.
    if (preg_match('/^'.preg_quote($constant, '/').':[^\n]*\n(.*?)^\)$/ms', $source, $body) !== 1) {
        throw new \RuntimeException(
            "Could not locate {$constant} in services/ai-service/app/observability/logging.py. See "
            .'the note at the top of this file: an empty parse is the failure mode this parser must '
            .'never have.',
        );
    }

    // Literals only, one per line — comments in that block are prose and contain no quoted names.
    preg_match_all('/^\s+"([a-z0-9_]+)",$/m', $body[1], $names);

    return $names[1];
}

it('reads all three sources as data, and finds a non-empty set in each', function (): void {
    // THE GUARD ON EVERY OTHER ASSERTION IN THIS FILE.
    expect(kbRequiredLogFields())->toHaveCount(8)
        ->and(kbPythonLogConstant('FORMATTER_OWNED_FIELDS'))->toHaveCount(12)
        ->and(count(kbPythonLogConstant('ALLOWED_EXTRA_FIELDS')))->toBeGreaterThan(30);
});

it('requires exactly the eight fields the observability skill names', function (): void {
    // Order matters here and is asserted: the skill lists them in the order a reader scans a line,
    // and the formatter emits them in that order for the same reason.
    expect(KbJsonFormatter::REQUIRED_FIELDS)->toBe(kbRequiredLogFields());
});

it('owns exactly the fields the FastAPI formatter owns', function (): void {
    $php = KbJsonFormatter::FORMATTER_OWNED_FIELDS;
    $python = kbPythonLogConstant('FORMATTER_OWNED_FIELDS');

    sort($php);
    sort($python);

    expect($php)->toBe($python);
});

it('admits exactly the context fields the FastAPI logger admits', function (): void {
    // Set equality both ways. A name admitted on one plane and not the other means a field an
    // author sees in one service's logs and silently loses in the other's — and `dropped_fields`
    // reports it only on the plane that dropped it.
    $php = KbJsonFormatter::ALLOWED_EXTRA_FIELDS;
    $python = kbPythonLogConstant('ALLOWED_EXTRA_FIELDS');

    sort($php);
    sort($python);

    expect($php)->toBe($python);
});

it('admits none of the identifiers the catalogue deliberately withholds from logs', function (): void {
    // logs-health-audit.md names these explicitly: they are NOT in the "when applicable" list, and
    // admitting one is an edit to that file rather than to a code constant.
    foreach (['source_id', 'conversation_id', 'message_id', 'chunk_id', 'user_id', 'url'] as $withheld) {
        expect(KbJsonFormatter::ALLOWED_EXTRA_FIELDS)->not->toContain($withheld);
    }

    // Tenant content and credential material, which are never admissible at any level.
    foreach (['query', 'prompt', 'packed_context', 'text', 'content', 'messages',
        'provider_credential', 'authorization', 'x-kb-signature'] as $forbidden) {
        expect(KbJsonFormatter::ALLOWED_EXTRA_FIELDS)->not->toContain($forbidden);
    }
});

it('spells the severity field the way the Collector reads it', function (): void {
    // The reason `severity` is not `level` or `level_name`. If this operator is ever removed or
    // renamed, the emitter has to change with it — and this is the only place positioned to notice.
    $collector = kbFileAsData('infrastructure/docker/otel/collector.yaml');

    expect($collector)->toContain('parse_from: attributes.severity')
        ->and(KbJsonFormatter::REQUIRED_FIELDS)->toContain('severity');
});

/*
|--------------------------------------------------------------------------
| Two runtimes, one TREATMENT — not only one vocabulary
|--------------------------------------------------------------------------
|
| Every assertion above pins a field-NAME set or the spelling of a severity. None of them pinned
| what either formatter DOES to a value, and that is exactly where the two planes diverged: PHP's
| normalize() walked arrays to MAX_DEPTH and redacted every nested string, while the Python side
| applied redact() to top-level strings only — so a dict or list under an allowed key reached
| json.dumps untouched. Measured on the same input, PHP emitted
| `"errors":{"body":["Authorization: Bearer [REDACTED]"]}` and Python emitted the token in full.
|
| The live shape is app/main.py's validation handler, whose `errors` extra is a nested map of
| field path to messages — nested exactly deep enough that the redaction which fires on a bare
| string does not fire on the same string one level down.
|
| ASSERTED AS THE SECRET'S ABSENCE, NEVER AS THE MASK'S PRESENCE, for the reason the header of
| tests/Unit/KbJsonFormatterTest.php gives: both Python redaction bugs found earlier produced
| output containing [REDACTED].
|
| The Python half is read as DATA rather than executed — there is no Python runtime in the PHP
| test image — and every extraction below is followed by a count, for the reason at the top of
| this file.
*/

/**
 * The nested credential both planes must swallow. One string, used on both sides.
 */
const KB_NESTED_CANARY = 'Authorization: Bearer sk-live-CANARYNESTEDTOKEN0123';

/**
 * The value shape app/main.py:_handle_validation_error logs: field path -> list of messages.
 *
 * @return array<string, array<int, string>>
 */
function kbNestedErrorContext(): array
{
    return [
        'body.credentials' => [KB_NESTED_CANARY],
        'body.model' => ['is required'],
    ];
}

it('redacts a credential nested under an allowed field, on this plane', function (): void {
    $formatter = new KbJsonFormatter(service: 'core-api', env: 'testing');

    $line = $formatter->format(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable('2026-08-10T14:34:56.789000+02:00'),
        channel: 'stdout',
        level: \Monolog\Level::Warning,
        message: 'validation rejected',
        context: ['errors' => kbNestedErrorContext()],
    ));

    expect($line)->not->toContain('CANARYNESTEDTOKEN0123');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    // Still a map of field path to a LIST of messages. A "fix" that stringified the whole value
    // would also satisfy the absence assertion above and would break every consumer of the field.
    expect($decoded['errors'])->toBe([
        'body.credentials' => ['Authorization: Bearer [REDACTED]'],
        'body.model' => ['is required'],
    ]);
});

it('walks nested values on the FastAPI plane too, rather than only top-level strings', function (): void {
    $python = kbFileAsData('services/ai-service/app/observability/logging.py');

    // (1) The partition hands EVERY surviving value to the recursive renderer. The line this
    // replaced was `allowed[key] = redact(value) if isinstance(value, str) else value`, which is
    // the whole finding: a dict or a list took the `else` branch and was never looked at.
    expect(substr_count($python, '_normalize(value, 0)'))->toBe(1)
        ->and($python)->not->toContain('redact(value) if isinstance(value, str) else value');

    // (2) The renderer recurses. Two call sites — the map branch and the sequence branch — and a
    // count rather than a `toContain`, so a version that walks only one of the two container
    // types cannot satisfy this.
    expect(substr_count($python, '_normalize(item, depth + 1)'))->toBe(2);

    // (3) Its leaf case redacts. Without this, a recursion that faithfully rebuilt the structure
    // and never redacted anything would pass (1) and (2).
    expect(substr_count($python, 'return redact(value)'))->toBe(1);
});

it('caps recursion at the same depth on both planes', function (): void {
    // Both constants are read as data. MAX_DEPTH is `private const` on this side and stays that
    // way — widening a formatter's visibility so a test can read it is a worse trade than a
    // regex with a count assertion behind it.
    $python = kbFileAsData('services/ai-service/app/observability/logging.py');
    $php = kbFileAsData('services/core-api/app/Logging/KbJsonFormatter.php');

    $inPython = preg_match_all('/^_MAX_EXTRA_DEPTH: Final = (\d+)$/m', $python, $pythonMatch);
    $inPhp = preg_match_all('/private const MAX_DEPTH = (\d+);/', $php, $phpMatch);

    expect($inPython)->toBe(1, 'could not read _MAX_EXTRA_DEPTH from the FastAPI formatter; fix '
        .'this parser rather than deleting the assertion it feeds — an empty parse agrees with '
        .'everything')
        ->and($inPhp)->toBe(1, 'could not read MAX_DEPTH from this plane\'s formatter; same rule')
        ->and((int) $pythonMatch[1][0])->toBe((int) $phpMatch[1][0]);

    // And the cap is a real bound on this plane, at the same nesting position. Five levels of map
    // under the allowed key: four are walked, the fifth is named.
    $formatter = new KbJsonFormatter(service: 'core-api', env: 'testing');

    $line = $formatter->format(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable('2026-08-10T14:34:56.789000+02:00'),
        channel: 'stdout',
        level: \Monolog\Level::Warning,
        message: 'validation rejected',
        context: ['errors' => ['a' => ['b' => ['c' => ['d' => ['e' => KB_NESTED_CANARY]]]]]],
    ));

    expect($line)->not->toContain('CANARYNESTEDTOKEN0123');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['errors']['a']['b']['c']['d'])->toBe('<array>');
});

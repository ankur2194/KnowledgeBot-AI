<?php

declare(strict_types=1);

use App\Support\Kb\ErrorTaxonomy;

/*
|--------------------------------------------------------------------------
| Cross-plane parity: the error taxonomy
|--------------------------------------------------------------------------
|
| App\Support\Kb\ErrorTaxonomy is a hand-transcription of services/ai-service/app/core/errors.py.
| It has to be — PHP cannot import a Python module — and a transcription with nothing comparing the
| two halves is precisely how finding O1 happened: one plane said an unhandled internal exception
| was retryable, the other said it was not, and a client obeying the envelope could not tell which
| plane it was talking to.
|
| THIS FILE IS THE COMPARISON. It reads errors.py AS DATA and fails on any disagreement in either
| direction — a class one plane has and the other does not, or a row whose retry verdict differs.
| Adding a 19th class to either side, or flipping a `True` to a `False` in one, is a red test rather
| than a client that retries half its failures and reports the other half.
|
| It is a CONTRACT test and not a Unit test because that is what this directory is for: the seam
| between the two runtimes. It needs no database and no HTTP — it needs the other plane's source,
| which is why it asserts the file is present rather than skipping when it is not. A skipped parity
| test is a deleted parity test.
|
| PARSING PYTHON WITH REGEX IS ONLY ACCEPTABLE BECAUSE OF THE COUNT ASSERTIONS. Every extraction
| below is followed by "and it found exactly 18". Without those, a refactor on the Python side that
| the regex stops matching yields an empty set, an empty set agrees with everything it is compared
| against pairwise, and the test goes green on the day it stops testing anything. That is the
| failure mode this comment exists to stop someone reintroducing.
*/

/**
 * services/ai-service/app/core/errors.py, resolved from this file.
 *
 * Four levels up from tests/Contract/ is the repository root: tests/Contract -> tests ->
 * core-api -> services -> <root>.
 */
function kbPythonTaxonomySource(): string
{
    $path = dirname(__DIR__, 4).'/services/ai-service/app/core/errors.py';

    expect(is_file($path))->toBeTrue(
        "The FastAPI taxonomy is not at [{$path}]. This test compares the two planes' error "
        .'tables and cannot do that from half a checkout — run the suite from the monorepo, and '
        .'if the file genuinely moved, fix this path rather than deleting the test.',
    );

    return (string) file_get_contents($path);
}

/**
 * The 18 `ErrorClass` members, as their wire values.
 *
 * @return list<string>
 */
function kbPythonErrorClasses(): array
{
    $source = kbPythonTaxonomySource();

    // The enum body only — so a value that merely appears elsewhere in the file cannot be counted.
    if (preg_match('/class ErrorClass\(StrEnum\):(.*?)\n\nclass /s', $source, $body) !== 1) {
        // A THROW, NOT AN EMPTY RESULT. If the Python side is restructured so this stops matching,
        // an empty list would agree with everything it is compared against and the parity suite
        // would go green on the day it stopped testing anything.
        throw new \RuntimeException(
            'Could not locate the ErrorClass enum body in errors.py. The file was restructured; '
            .'update this parser rather than deleting the assertion it feeds.',
        );
    }

    preg_match_all('/^\s+[A-Z_]+ = "([a-z_]+)"$/m', $body[1], $members);

    return $members[1];
}

/**
 * The `RETRYABLE` mapping, as `wire value => bool`.
 *
 * @return array<string, bool>
 */
function kbPythonRetryable(): array
{
    $source = kbPythonTaxonomySource();

    if (preg_match('/^RETRYABLE: Final\[Mapping\[ErrorClass, bool\]\] = \{(.*?)^\}/ms', $source, $body) !== 1) {
        throw new \RuntimeException(
            'Could not locate the RETRYABLE mapping in errors.py. See the note above: an empty '
            .'parse is the failure mode this parser must never have.',
        );
    }

    // ErrorClass.NAME: True,  — the NAME is the Python identifier, not the wire value, so the
    // enum body is used to translate. Comments between rows are ignored by the anchor.
    preg_match_all('/^\s+ErrorClass\.([A-Z_]+): (True|False),/m', $body[1], $rows, PREG_SET_ORDER);

    $enum = [];
    preg_match_all('/^\s+([A-Z_]+) = "([a-z_]+)"$/m', $source, $members, PREG_SET_ORDER);

    foreach ($members as $member) {
        $enum[$member[1]] = $member[2];
    }

    $retryable = [];

    foreach ($rows as $row) {
        // Not toHaveKey(): its second argument is the expected VALUE, not a failure message.
        expect(array_key_exists($row[1], $enum))->toBeTrue(
            "RETRYABLE names {$row[1]}, which is not an ErrorClass member",
        );
        $retryable[$enum[$row[1]]] = $row[2] === 'True';
    }

    return $retryable;
}

it('reads the FastAPI taxonomy as data, and finds all 18 classes', function (): void {
    // THE GUARD ON EVERY OTHER ASSERTION IN THIS FILE. An extraction that silently matched nothing
    // would make every comparison below vacuously true.
    expect(kbPythonErrorClasses())->toHaveCount(18)
        ->and(kbPythonRetryable())->toHaveCount(18);
});

it('carries exactly the same set of error classes as the FastAPI plane', function (): void {
    // Set equality both ways. A class added to one plane and not the other is the O1 shape on a
    // different row: FastAPI emits it, Laravel relays it verbatim, and Laravel's retry verdict for
    // a class it has never heard of is a default rather than a decision.
    $php = array_keys(ErrorTaxonomy::RETRYABLE);
    $python = kbPythonErrorClasses();

    sort($php);
    sort($python);

    expect($php)->toBe($python);
});

it('agrees with the FastAPI plane on every retry verdict', function (): void {
    // The downstream reading, row for row. `internal_dependency` is True on BOTH sides here — it
    // is the downstream row, and the self-origin override is asserted separately below, exactly as
    // errors.py keeps RETRYABLE[INTERNAL_DEPENDENCY] = True and subtracts it in retryable_for().
    $python = kbPythonRetryable();

    foreach ($python as $class => $expected) {
        expect(ErrorTaxonomy::retryable($class, ErrorTaxonomy::ORIGIN_DOWNSTREAM))->toBe(
            $expected,
            "retryable('{$class}') disagrees with services/ai-service/app/core/errors.py. A client "
            .'cannot tell which plane produced an envelope, so both must answer identically.',
        );
    }
});

it('mirrors the ADR-029 self-origin override that FastAPI applies in retryable_for()', function (): void {
    $source = kbPythonTaxonomySource();

    // Assert the override still EXISTS over there, not merely that our side has one. If someone
    // deletes it from retryable_for(), FastAPI silently returns to 503/retryable for its own bugs
    // and this file is the only thing positioned to notice.
    expect($source)->toContain('def retryable_for(')
        ->and($source)->toMatch(
            '/if error_class is ErrorClass\.INTERNAL_DEPENDENCY and origin is Origin\.SELF:\s+return False/',
        );

    expect(ErrorTaxonomy::retryable('internal_dependency', ErrorTaxonomy::ORIGIN_SELF))->toBeFalse();
    expect(ErrorTaxonomy::retryable('internal_dependency', ErrorTaxonomy::ORIGIN_DOWNSTREAM))->toBeTrue();

    // The override is scoped to one row. Applying it to a second class would change a retry policy
    // on the quiet, and origin is not a wire field, so nothing downstream could see it happen.
    foreach (array_keys(ErrorTaxonomy::RETRYABLE) as $class) {
        if ($class === 'internal_dependency') {
            continue;
        }

        expect(ErrorTaxonomy::retryable($class, ErrorTaxonomy::ORIGIN_SELF))
            ->toBe(ErrorTaxonomy::retryable($class, ErrorTaxonomy::ORIGIN_DOWNSTREAM), $class);
    }
});

it('still says 18, in both planes', function (): void {
    // ADR-029 resolved O1 as a SUB-CASE and not a nineteenth class, and the count assertion is how
    // that stays true. errors.py asserts it at import; this asserts it from the other side, so
    // deleting the Python assert does not quietly retire the rule.
    expect(ErrorTaxonomy::RETRYABLE)->toHaveCount(18)
        ->and(kbPythonTaxonomySource())->toContain('assert len(ErrorClass) == 18');
});

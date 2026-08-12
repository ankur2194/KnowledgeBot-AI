import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { ERROR_CLASSES } from '../src/error-classes.js';

/*
|--------------------------------------------------------------------------
| Cross-runtime parity: the error taxonomy, TypeScript vs the FastAPI plane
|--------------------------------------------------------------------------
|
| `ERROR_CLASSES` is a HAND-TRANSCRIPTION of services/ai-service/app/core/errors.py. It has to be —
| TypeScript cannot import a Python module — and it is the THIRD such transcription: Laravel's
| App\Support\Kb\ErrorTaxonomy is the second, and services/core-api/tests/Contract/
| ErrorTaxonomyParityTest.php has pinned that one to Python since it was written. Nothing pinned
| this one. THIS FILE IS THAT COMPARISON, and it is modelled on the PHP test deliberately: same
| referent (errors.py), same set-equality-both-ways, same count guard, so the three lists form a
| triangle with Python at the apex rather than two pinned edges and one free one.
|
| WHY IT MATTERS MORE THAN A NAMING NIT. `isErrorClass` gates `isKbErrorEnvelope` gates `toKbError`.
| A class renamed or added on the server that this list has not heard of produces no type error and
| no failing test — it produces `KbError(null, false, retrySeconds, null, 'HTTP 503')`. The server's
| `retryable: true` verdict is silently INVERTED to permanent, `request_id` is discarded, and all
| three clients render "Something went wrong." There is no symptom to grep for.
|
| PARSING PYTHON WITH REGEX IS ONLY ACCEPTABLE BECAUSE OF THE COUNT ASSERTION, and the reasoning is
| the PHP file's verbatim: an extraction that silently matched nothing yields an empty list, and an
| empty list compared PAIRWISE agrees with everything. `setDisagreements` below is set equality in
| BOTH directions precisely so an empty Python side is a loud failure and not a vacuous pass — and
| the "teeth" suite at the bottom proves that, rather than asserting it in a comment.
|
| WHAT IS DELIBERATELY NOT HERE: the retry verdicts. The PHP side can compare them because Laravel
| owns a `RETRYABLE` table of its own; TypeScript owns no such table and must not grow one.
| `KbError.retryable` is a STRAIGHT CARRY of the envelope's flag and is never re-derived from the
| class name (ADR-029, finding O1) — a `RETRYABLE` map in this package would be exactly the
| re-derivation that contract forbids, and writing one to make a parity test possible would be
| inventing the data the test claims to check. The one retry-shaped table on this side of the wire
| is apps/web's `CLIENT_RETRYABLE`, which is a deliberate NARROWING subset of the envelope's verdict
| (three classes out of eighteen) and therefore has no parity relationship with Python's table at
| all: it is correct precisely by disagreeing.
*/

/**
 * services/ai-service/app/core/errors.py, resolved from this file.
 *
 * Three levels up from test/ is the repository root: test -> contracts -> packages -> <root>.
 * `packages/contracts` is the only workspace package that can see both the TypeScript tree and the
 * Python one, which is why this test lives here and not in apps/web.
 *
 * THE DISABLE BELOW IS NOT A LOOPHOLE IN NN1. `kbRestrictedSyntax` bans any literal matching
 * `(^|//)(ai-api|ai-service)(:|/|$)` because no client and no Next server may address FastAPI over
 * the network — and the rule matches LITERALS, so it cannot tell a hostname apart from a path
 * segment on a checkout. This is a `readFileSync` of a source file at build/test time. Nothing here
 * opens a socket, nothing here runs in a browser or a request, and this module is under `test/`, so
 * it is not in the shipped `dist/`. The rule is suppressed on exactly one line, with the reason
 * written down, rather than by spelling the segment some way the regex misses — an evaded guard is
 * worse than a disabled one, because nobody can grep for it.
 */
const PYTHON_TAXONOMY = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  'services',
  // eslint-disable-next-line no-restricted-syntax -- a filesystem path in a test, not a network hop
  'ai-service',
  'app',
  'core',
  'errors.py',
);

/**
 * THROWS when the file is absent rather than skipping. A skipped parity test is a deleted parity
 * test, and this suite's whole value is that it is red on the day the two lists diverge.
 */
function pythonTaxonomySource(): string {
  try {
    return readFileSync(PYTHON_TAXONOMY, 'utf8');
  } catch (cause) {
    throw new Error(
      `The FastAPI taxonomy is not at [${PYTHON_TAXONOMY}]. This test compares two runtimes' ` +
        'error tables and cannot do that from half a checkout — run the suite from the monorepo, ' +
        'and if the file genuinely moved, fix this path rather than deleting the test.',
      { cause },
    );
  }
}

/**
 * The `ErrorClass` members, as their WIRE values.
 *
 * Takes the source as an argument rather than reading it, so the teeth suite below can feed it a
 * deliberately tampered copy without writing to services/ — a tree this agent does not own.
 */
function pythonErrorClasses(source: string): string[] {
  // The enum BODY only, so a value that merely appears elsewhere in the file (`_STATUS`, the
  // docstrings, `__all__`) cannot be counted as a member.
  const body = /class ErrorClass\(StrEnum\):([\s\S]*?)\n\nclass /.exec(source);

  if (body?.[1] === undefined) {
    // A THROW, NOT AN EMPTY RESULT. If the Python side is restructured so this stops matching, an
    // empty list must not quietly become the thing this suite compares against.
    throw new Error(
      'Could not locate the ErrorClass enum body in errors.py. The file was restructured; update ' +
        'this parser rather than deleting the assertion it feeds.',
    );
  }

  return [...body[1].matchAll(/^\s+[A-Z_]+ = "([a-z_]+)"$/gm)].map((match) => match[1] as string);
}

/**
 * Set equality in BOTH directions, as messages. A class one runtime has and the other does not is
 * the same shape of bug either way round, but the two need different fixes, so they are reported
 * apart.
 *
 * A function rather than inline expectations so the teeth suite can prove the comparison fails when
 * it should — a parity check that cannot go red is indistinguishable from no parity check.
 */
function setDisagreements(python: readonly string[], typescript: readonly string[]): string[] {
  const py = new Set(python);
  const ts = new Set(typescript);

  return [
    ...[...py]
      .filter((c) => !ts.has(c))
      .map(
        (c) =>
          `errors.py has \`${c}\`; ERROR_CLASSES does not — isErrorClass() rejects it, so ` +
          'isKbErrorEnvelope() fails, so toKbError() yields error_class: null and inverts the ' +
          "server's retryable verdict to permanent",
      ),
    ...[...ts]
      .filter((c) => !py.has(c))
      .map((c) => `ERROR_CLASSES has \`${c}\`; errors.py does not — a class no server can emit`),
  ].sort();
}

describe('the 18 error classes, against services/ai-service/app/core/errors.py', () => {
  it('reads the FastAPI taxonomy as data, and finds all 18 classes', () => {
    // THE GUARD ON EVERY OTHER ASSERTION IN THIS FILE. An extraction that silently matched nothing
    // would make the comparison below a comparison against an empty set.
    expect(pythonErrorClasses(pythonTaxonomySource())).toHaveLength(18);
  });

  it('carries exactly the same set of error classes as the FastAPI plane', () => {
    expect(setDisagreements(pythonErrorClasses(pythonTaxonomySource()), ERROR_CLASSES)).toEqual([]);
  });

  it('agrees on the ORDER as well, so a diff of the two files reads straight across', () => {
    // Not required by any consumer — `ERROR_CLASSES` is used as a set — but the two files are
    // maintained by reading one and typing the other, and a reordered list is how a missing member
    // hides in review. Cheap to keep, and it is the same list either way.
    expect([...ERROR_CLASSES]).toEqual(pythonErrorClasses(pythonTaxonomySource()));
  });

  it('still says 18, in both runtimes', () => {
    // ADR-029 resolved finding O1 as a SUB-CASE of `internal_dependency`, not a nineteenth class.
    // errors.py asserts that at import; this asserts it from the other side, so deleting the Python
    // assert does not quietly retire the rule.
    expect(ERROR_CLASSES).toHaveLength(18);
    expect(pythonTaxonomySource()).toContain('assert len(ErrorClass) == 18');
  });

  it('does not mistake the Origin sub-case for a nineteenth class', () => {
    // `origin` is not a wire field and never will be (ADR-029). If `downstream`/`self` ever showed
    // up in ERROR_CLASSES the extraction above would not catch it — Origin is a different enum —
    // so it is asserted directly.
    expect(ERROR_CLASSES).not.toContain('downstream');
    expect(ERROR_CLASSES).not.toContain('self');
  });
});

/**
 * The suite that proves the suite. Every case runs through the SAME `pythonErrorClasses` and
 * `setDisagreements` the real assertions use, on a tampered copy of the REAL source text — never a
 * hand-written fixture, which would drift from errors.py on its own and never a write into
 * services/, a tree this agent does not own.
 */
describe('the parity check has teeth', () => {
  const source = pythonTaxonomySource();

  it('goes red when the FastAPI plane RENAMES a class', () => {
    const renamed = source.replace('RATE_LIMIT = "rate_limit"', 'RATE_LIMIT = "rate_limited"');
    expect(renamed).not.toBe(source);

    // Both directions at once, which is what a rename IS: one list gained a name, the other kept
    // one. Sorted, so `ERROR_CLASSES` (capital E) precedes `errors.py`.
    expect(setDisagreements(pythonErrorClasses(renamed), ERROR_CLASSES)).toEqual([
      expect.stringContaining('ERROR_CLASSES has `rate_limit`'),
      expect.stringContaining('errors.py has `rate_limited`'),
    ]);
  });

  it('goes red when the FastAPI plane ADDS a class this package has never heard of', () => {
    const added = source.replace(
      '    USER_CANCELLATION = "user_cancellation"',
      '    USER_CANCELLATION = "user_cancellation"\n    MODERATION = "moderation"',
    );
    expect(added).not.toBe(source);

    const failures = setDisagreements(pythonErrorClasses(added), ERROR_CLASSES);
    expect(failures).toHaveLength(1);
    expect(failures[0]).toContain('errors.py has `moderation`');
    // The blast radius, spelled out in the failure message so the next reader does not have to
    // re-derive it from three files.
    expect(failures[0]).toContain('inverts');
  });

  it('goes red when the FastAPI plane REMOVES a class', () => {
    const removed = source.replace('    OCR = "ocr"\n', '');
    expect(removed).not.toBe(source);

    expect(setDisagreements(pythonErrorClasses(removed), ERROR_CLASSES)).toEqual([
      expect.stringContaining('ERROR_CLASSES has `ocr`'),
    ]);
  });

  it('THROWS rather than returning an empty set when the enum body cannot be located', () => {
    // The refactor this parser cannot survive: `class ErrorClass(StrEnum)` becoming
    // `class ErrorClass(str, Enum)`. Returning [] here would make the count assertion the only
    // thing standing between the suite and a permanent vacuous pass — so it throws instead.
    const restructured = source.replace(
      'class ErrorClass(StrEnum):',
      'class ErrorClass(str, Enum):',
    );
    expect(restructured).not.toBe(source);

    expect(() => pythonErrorClasses(restructured)).toThrow(/Could not locate the ErrorClass enum/);
  });

  it('an empty extraction is a LOUD failure, not a vacuous pass', () => {
    // The failure mode the PHP file's header calls out: an empty list agrees with everything it is
    // compared against PAIRWISE. This is why the comparison is set equality both ways.
    expect(setDisagreements([], ERROR_CLASSES)).toHaveLength(18);
    // …and the count guard catches it one assertion earlier, which is the belt to that braces.
    expect([]).not.toHaveLength(18);
  });
});

<?php

declare(strict_types=1);

use App\Enums\SourceState;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cross-plane parity: the fifteen-value source lifecycle (docs/22 finding P3)
|--------------------------------------------------------------------------
|
| The same vocabulary is written down FOUR times and, until this file existed, nothing compared any
| two of them:
|
|   1. App\Enums\SourceState                                     the control plane's enum
|   2. knowledge_sources_status_check / source_versions_status_check   the CHECK constraints
|   3. services/ai-service/app/ingestion/states.py               the data plane's StrEnum
|   4. ACTIVE_SOURCE_STATUSES in app/retrieval/tenancy.py        the Qdrant `source_status` filter
|
| Each one asserts its own internal consistency — `states.py` even has an import-time
| `assert len(SourceState) == 15` — and self-consistency is exactly what a divergence survives. A
| value added to one and not the others is a string that one plane writes and the other refuses, or
| worse a status the data plane reports and `SourceState::from()` throws on inside a callback
| handler that has already committed the row it describes.
|
| ── WHY THIS LIVES IN PEST AND NOT IN PYTEST ─────────────────────────────────────────────────
|
| Two of the four definitions are PHP (the enum) and SQL owned by PHP (the two migrations), and the
| CHECK constraints can only be read from a MIGRATED DATABASE — `pg_get_constraintdef`, below —
| which this suite already has and the pytest suite does not. Hosting it in pytest would mean
| parsing a PHP enum AND two PHP migration files from Python, and then still not being able to see
| what the database actually holds. So the direction is: PHP reads Python, which is the direction
| `ErrorTaxonomyParityTest` already established for `errors.py`, and it reads the SQL from the
| server rather than from the migration that wrote it.
|
| The third option — both sides pinning against a shared artifact in `packages/contracts/` — was
| considered and refused for this vocabulary. `packages/contracts/` is consumed by the BROWSER
| clients; the lifecycle vocabulary is a server-to-server and server-to-schema concern, and adding
| it there would put a fifth copy in the tree and give the two runtimes a build-order dependency on
| a TypeScript package neither of them imports. The error taxonomy has the same shape and the same
| answer.
|
| ── PARSING PYTHON WITH REGEX IS ONLY ACCEPTABLE BECAUSE OF THE COUNT ASSERTIONS ─────────────
|
| Same rule as `ErrorTaxonomyParityTest`, same reason: a refactor the regex stops matching yields an
| EMPTY set, and an empty set agrees with everything it is compared against. Every extraction below
| is followed by a count assertion against the PHP side, and the count the PHP side uses is
| `SourceState::cases()` rather than a literal 15 — so a value legitimately added to BOTH planes
| passes, and a value added to one fails.
|
| IT DOES NOT SKIP WHEN THE PYTHON IS ABSENT. A skipped parity test is a deleted parity test.
*/

/**
 * Read a file from the monorepo ROOT.
 *
 * Four levels up from tests/Contract/ is the repository root: tests/Contract -> tests -> core-api
 * -> services -> <root>.
 */
function sourceStateRepoFile(string $relative): string
{
    $path = dirname(__DIR__, 4).'/'.$relative;

    expect(is_file($path))->toBeTrue(
        "[{$relative}] is not at [{$path}]. This test compares the two planes' lifecycle "
        .'vocabularies and cannot do that from half a checkout — run the suite from the monorepo, '
        .'and if the file genuinely moved, fix this path rather than deleting the test.',
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
function sourceStateRequiredMatch(string $pattern, string $subject, string $what): array
{
    $matches = [];

    expect(preg_match($pattern, $subject, $matches))->toBe(1, $what);

    return array_values($matches);
}

/** `services/ai-service/app/ingestion/states.py`, read once per test. */
function sourceStatePythonSource(): string
{
    return sourceStateRepoFile('services/ai-service/app/ingestion/states.py');
}

/** `services/ai-service/app/retrieval/tenancy.py`, read once per test. */
function sourceStateTenancySource(): string
{
    return sourceStateRepoFile('services/ai-service/app/retrieval/tenancy.py');
}

/**
 * The `SourceState` StrEnum members of `states.py`, as `MEMBER_NAME => 'wire value'`, IN ORDER.
 *
 * The class body is captured up to the next line that starts at column 0, which is the first
 * module-level statement after the enum. Every member line and every docstring line inside the
 * class is indented, and a blank line is not `\S`, so the boundary is unambiguous.
 *
 * @return array<string, string>
 */
function sourceStatePythonCases(): array
{
    $body = sourceStateRequiredMatch(
        '/\nclass SourceState\(StrEnum\):\n(.*?)(?=\n\S)/s',
        sourceStatePythonSource(),
        'The `SourceState` StrEnum could not be located in states.py. It is the data plane\'s half '
        .'of this contract; if it was renamed or moved, this test has to move with it.',
    )[1];

    $members = [];
    preg_match_all('/^ {4}([A-Z][A-Z0-9_]*) = "([a-z_]+)"$/m', $body, $members, PREG_SET_ORDER);

    $cases = [];

    foreach ($members as $member) {
        $cases[$member[1]] = $member[2];
    }

    return $cases;
}

/**
 * The `SourceState.MEMBER` names inside a module-level `frozenset(...)` in `states.py`.
 *
 * The lazy `(.*?)\)` stops at the first `)`, which is `frozenset`'s own closing parenthesis: the
 * bodies are set literals and contain no other parentheses. A member added inside a call would
 * break that assumption, and the count assertions at every call site are what would catch it.
 *
 * @return list<string>
 */
function sourceStatePythonFrozenset(string $name): array
{
    $body = sourceStateRequiredMatch(
        '/^'.preg_quote($name, '/').': Final\[frozenset\[SourceState\]\] = frozenset\((.*?)\)$/ms',
        sourceStatePythonSource(),
        "`{$name}` could not be located as a module-level frozenset in states.py.",
    )[1];

    $names = [];
    preg_match_all('/SourceState\.([A-Z][A-Z0-9_]*)/', $body, $names);

    return $names[1];
}

/**
 * Translate `states.py` member NAMES into wire values, refusing any name the enum does not have.
 *
 * Going through the values is what makes the comparison independent of the two naming conventions
 * (`ReadyWithWarnings` here, `READY_WITH_WARNINGS` there). A name that is not a member is a
 * frozenset referring to a state that does not exist, which Python would catch at import — the
 * assertion is here so that THIS test reports it rather than dying on an undefined key.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function sourceStateValuesFor(array $names): array
{
    $cases = sourceStatePythonCases();

    return array_values(array_map(static function (string $name) use ($cases): string {
        // NOT `expect($cases)->toHaveKey($name, ...)`: toHaveKey()'s second argument is the
        // expected VALUE, not a failure message, so that spelling asserts something else entirely.
        expect(array_key_exists($name, $cases))->toBeTrue(
            "states.py refers to `SourceState.{$name}`, which is not one of its own members.",
        );

        return $cases[$name];
    }, $names));
}

/**
 * The values of a `CHECK (status IN (...))` constraint, read back from the SERVER.
 *
 * Not from the migration file. Both migrations build their list from `SourceState::values()`, so
 * comparing the enum to the migration source would compare the enum to itself and pass forever.
 * What can actually drift is the DATABASE: a sixteenth value added to the enum with no `ALTER
 * TABLE ... DROP CONSTRAINT / ADD CONSTRAINT` migration behind it leaves every deployed schema
 * refusing the row the enum now permits, and nothing in PHP notices.
 *
 * PostgreSQL normalises `status IN ('a','b')` to `status = ANY (ARRAY['a'::text, 'b'::text])`, so
 * the values are read out of the rendered definition rather than matched as an `IN` list.
 *
 * @return list<string>
 */
function sourceStateCheckConstraint(string $constraint): array
{
    /** @var object{def: string}|null $row */
    $row = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        [$constraint],
    );

    expect($row)->not->toBeNull(
        "There is no constraint named [{$constraint}] in the test database. Either the migration "
        .'that creates it did not run, or it was renamed — and a renamed CHECK constraint is a '
        .'vocabulary nothing is holding.',
    );
    assert($row !== null);

    $values = [];
    preg_match_all("/'([a-z_]+)'::text/", $row->def, $values);

    expect($values[1])->not->toBeEmpty(
        "No quoted values could be read out of [{$constraint}]'s definition: {$row->def}. The "
        .'rendering changed shape; fix the pattern rather than the assertion.',
    );

    return $values[1];
}

it('states the same fifteen values, in the same order, in both planes', function (): void {
    $php = SourceState::values();
    $python = array_values(sourceStatePythonCases());

    // ORDER, not just membership. Both files argue in their own docblocks that the order is §8.9's
    // — the order a healthy run walks — and that it is what makes the transition table reviewable
    // line by line against the specification. `toBe` on two lists is an ordered comparison.
    expect($python)->toBe(
        $php,
        'App\Enums\SourceState and services/ai-service/app/ingestion/states.py no longer hold the '
        .'same values in the same order. The value crosses the process boundary as a bare string '
        .'on the ingestion status callback, so a divergence is a type error in neither plane: it '
        .'is a status one side writes and the other refuses to parse.',
    );
});

it('keeps the data plane\'s own import-time count guard in step with the enum', function (): void {
    // states.py asserts its length AT IMPORT rather than in a test, deliberately — a lifecycle enum
    // that lost a value must not reach a running process. That guard is a literal, so it is a
    // sixteenth place the number is written down, and the one most likely to be forgotten by
    // somebody legitimately adding a state to both planes.
    $literal = (int) sourceStateRequiredMatch(
        '/^assert len\(SourceState\) == (\d+)$/m',
        sourceStatePythonSource(),
        'The `assert len(SourceState) == N` import guard is no longer in states.py. It is the only '
        .'thing that stops a truncated enum from reaching a worker; restore it rather than '
        .'deleting this assertion.',
    )[1];

    expect($literal)->toBe(
        count(SourceState::cases()),
        'states.py asserts a member count that the control plane\'s enum does not have. Whichever '
        .'side is right, the guard now passes for the wrong reason.',
    );
});

it('holds the same vocabulary in both status CHECK constraints as in the enum', function (string $constraint): void {
    $expected = SourceState::values();

    // Sorted, because a CHECK constraint is a set and PostgreSQL is free to render it in any order
    // — asserting the enum's contract order here would be asserting an implementation detail of
    // pg_get_constraintdef. Membership in both directions is the whole property.
    $actual = sourceStateCheckConstraint($constraint);
    sort($expected);
    sort($actual);

    expect($actual)->toBe(
        $expected,
        "[{$constraint}] in the database and App\\Enums\\SourceState no longer list the same "
        .'values. R3 of the Phase C1 rulings is that knowledge_sources.status and '
        .'source_versions.status share ONE vocabulary; a value the enum has and the constraint '
        .'does not is a row the application believes it may write and the database rejects at '
        .'COMMIT, inside whatever transaction was mid-transition.',
    );
})->with([
    'knowledge_sources_status_check',
    'source_versions_status_check',
]);

it('agrees on which states are retrievable, and the Qdrant filter uses that same set', function (): void {
    $php = array_values(array_map(
        static fn (SourceState $state): string => $state->value,
        array_filter(SourceState::cases(), static fn (SourceState $state): bool => $state->isRetrievable()),
    ));

    $python = sourceStateValuesFor(sourceStatePythonFrozenset('RETRIEVABLE'));

    sort($php);
    sort($python);

    expect($python)->toBe(
        $php,
        'SourceState::isRetrievable() and states.py\'s RETRIEVABLE disagree. This term is one of '
        .'the four mandatory Qdrant filter terms (kb-tenancy-isolation NN3); a state one plane '
        .'calls retrievable and the other does not is either a document that answers when it '
        .'should not, or one that has been indexed and can never be found.',
    );

    // The FOURTH copy, and the one that actually reaches Qdrant. It is a bare tuple of strings in
    // retrieval/tenancy.py with no reference to states.py, so nothing but this assertion ties the
    // payload term the query matches on to the vocabulary the rest of the system uses.
    $filter = [];
    preg_match_all(
        '/"([a-z_]+)"/',
        sourceStateRequiredMatch(
            '/^ACTIVE_SOURCE_STATUSES: Final\[tuple\[str, \.\.\.\]\] = \((.*?)\)$/ms',
            sourceStateTenancySource(),
            'ACTIVE_SOURCE_STATUSES could not be located in services/ai-service/app/retrieval/'
            .'tenancy.py. It is the literal the Qdrant `source_status` FieldCondition matches on.',
        )[1],
        $filter,
    );

    $filterValues = $filter[1];
    sort($filterValues);

    expect($filterValues)->toBe(
        $php,
        'ACTIVE_SOURCE_STATUSES — the values the Qdrant source_status filter matches — is not the '
        .'set of states this system calls retrievable. It is written as bare strings with no '
        .'reference to states.py, so nothing else in either runtime compares the two.',
    );
});

it('agrees on which states are processing and which are terminal', function (): void {
    $processingPhp = array_values(array_map(
        static fn (SourceState $state): string => $state->value,
        array_filter(SourceState::cases(), static fn (SourceState $state): bool => $state->isProcessing()),
    ));
    $processingPython = sourceStateValuesFor(sourceStatePythonFrozenset('PROCESSING'));

    sort($processingPhp);
    sort($processingPython);

    expect($processingPython)->toBe(
        $processingPhp,
        'SourceState::isProcessing() and states.py\'s PROCESSING disagree. Both are read as "a run '
        .'is in flight and the PRIOR version keeps serving"; a state only one of them counts is a '
        .'stuck-run sweep that skips it, or an activation that races one.',
    );

    // Terminal is derived from the transition table on the PHP side and stated as a literal on the
    // Python side, which is the asymmetry worth holding: PHP cannot go stale here, Python can.
    $terminalPhp = array_values(array_map(
        static fn (SourceState $state): string => $state->value,
        array_filter(SourceState::cases(), static fn (SourceState $state): bool => $state->isTerminal()),
    ));
    $terminalPython = sourceStateValuesFor(sourceStatePythonFrozenset('TERMINAL'));

    sort($terminalPhp);
    sort($terminalPython);

    expect($terminalPython)->toBe(
        $terminalPhp,
        'SourceState::isTerminal() (derived from transitionTable(), so it cannot go stale) and '
        .'states.py\'s TERMINAL literal disagree. Note that `failed` belongs in NEITHER: it is '
        .'terminal for the run and not for the item.',
    );
});

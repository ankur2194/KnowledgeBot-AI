<?php

declare(strict_types=1);

use App\Enums\SourceState;

/*
|--------------------------------------------------------------------------
| The source lifecycle machine — kb-source-lifecycle's state table
|--------------------------------------------------------------------------
|
| THE EXPECTED TABLE BELOW IS TRANSCRIBED FROM THE SKILL AND FROM NOWHERE ELSE. It is not a
| re-implementation of `SourceState::transitionTable()` and it must never become one: a test that
| derives its expectation from the code it is testing passes against the inverted machine. When the
| two disagree, one of them is wrong and a human has to decide which — that is the point, and it is
| the same construction tests/Unit/RolePermissionMatrixTest.php uses for the privilege matrix.
|
| THIS FILE IS A UNIT TEST AND BOOTS NOTHING. The enum is pure PHP, so the whole machine is
| checkable with no container, no database and no request.
|
| WHAT IT DELIBERATELY DOES NOT COVER, because no table can express it:
|
|   * WHETHER THE VERIFICATION ACTUALLY RAN. `$verified` is a fact from the data plane — only the
|     worker that wrote the points can count them with exact=True — and this file asserts that the
|     flag GATES the edge, never that anybody set it honestly. The integration half of that is an
|     ingestion-callback test and does not exist yet.
|   * WHETHER A CALLER ASKS. A machine nobody consults is decoration; the services that consult it
|     are Phase C's later steps.
*/

/**
 * `kb-source-lifecycle`'s "Legal next" column, transcribed row by row.
 *
 * @return array<string, list<string>>
 */
function expectedSourceTransitions(): array
{
    return [
        // Created, never submitted. It can be started, thrown away, or shelved.
        'draft' => ['queued', 'deleted', 'archived'],
        // Accepted and awaiting a worker. `deleting` is reachable from here and from every
        // in-flight state: a customer may delete a source mid-ingestion.
        'queued' => ['fetching', 'failed', 'deleting'],
        'fetching' => ['parsing', 'failed', 'deleting'],
        // NOTE THAT `deleting` DROPS OUT AFTER `fetching`, which is the skill's table verbatim and
        // is the row a reader is most likely to assume is a typo. It is not restated as a rule
        // here, because this file's job is to transcribe rather than to reason — if it is wrong,
        // the skill is where it is wrong.
        'parsing' => ['normalizing', 'failed'],
        'normalizing' => ['chunking', 'failed'],
        'chunking' => ['embedding', 'failed'],
        'embedding' => ['indexing', 'failed'],
        // The two Ready edges additionally require a passing verification. The TABLE admits them;
        // the gate is asserted separately below.
        'indexing' => ['ready', 'ready_with_warnings', 'failed'],
        'ready' => ['queued', 'disabled', 'deleting', 'archived'],
        'ready_with_warnings' => ['queued', 'disabled', 'deleting', 'archived'],
        // Terminal for the RUN, not for the item: a fresh run re-enters at `queued`, which mints a
        // NEW version row. There is no edge to `ready`.
        'failed' => ['queued', 'disabled', 'deleting', 'archived'],
        // Vectors retained, so re-enabling is a metadata write and lands straight back on a Ready
        // flavour without re-ingesting.
        'disabled' => ['ready', 'ready_with_warnings', 'deleting', 'archived'],
        'deleting' => ['deleted'],
        // TERMINAL. No edges out, at all.
        'deleted' => [],
        // Metadata and objects retained, vectors dropped; restore is a rebuild.
        'archived' => ['ready', 'deleting'],
    ];
}

it('holds the fifteen states of the contract, in contract order', function (): void {
    // NOT SORTED, AND THE ORDER IS THE ASSERTION. It is §8.9's order, which is also the order a
    // healthy run walks, and `services/ai-service/app/ingestion/states.py` asserts the same
    // sequence at import time. Re-sorting alphabetically on either side makes the two files
    // disagree about a contract they exchange as bare strings across a process boundary.
    expect(SourceState::values())->toBe([
        'draft', 'queued', 'fetching', 'parsing', 'normalizing', 'chunking', 'embedding',
        'indexing', 'ready', 'ready_with_warnings', 'failed', 'disabled', 'deleting', 'deleted',
        'archived',
    ]);

    // FIFTEEN, NOT FOURTEEN. Early summaries of the spec collapsed the two Ready flavours because
    // they behave identically for retrieval, and the collapse loses the only signal that says "this
    // document parsed badly and published anyway".
    expect(SourceState::cases())->toHaveCount(15);
});

it('permits exactly the transitions the state table names', function (string $from, array $expected): void {
    $actual = array_map(
        static fn (SourceState $state): string => $state->value,
        SourceState::transitionTable()[$from],
    );

    sort($actual);
    sort($expected);

    expect($actual)->toBe(
        $expected,
        sprintf(
            'SourceState::%s\'s legal next states are [%s] and kb-source-lifecycle says [%s]. A '
            .'lifecycle machine is not a place to guess which is right.',
            $from,
            implode(', ', $actual),
            implode(', ', $expected),
        ),
    );
})->with(function (): array {
    $rows = [];

    foreach (expectedSourceTransitions() as $from => $to) {
        $rows[$from] = [$from, $to];
    }

    return $rows;
});

it('names every state in the table, so a new one cannot arrive with no edges decided', function (): void {
    // A case added to the enum without a row here would get no entry in `transitionTable()` either
    // — and `canTransitionTo()` reads that table by key, so the omission would be an undefined
    // array key at runtime rather than a refusal. Asserted in both directions.
    expect(array_keys(SourceState::transitionTable()))->toEqualCanonicalizing(SourceState::values());
    expect(array_keys(expectedSourceTransitions()))->toEqualCanonicalizing(SourceState::values());
});

/*
|--------------------------------------------------------------------------
| The four transitions kb-source-lifecycle names as bugs
|--------------------------------------------------------------------------
|
| "Transitions not in that table are bugs. In particular: `Indexing -> Ready` without a passing
| verification, `Ready -> Deleted` skipping `Deleting`, any edge out of `Deleted`, and
| `Failed -> Ready` without a new run."
|
| Each is asserted separately below rather than left to the transcription above, because three of
| the four are ABSENCES — and an absence in a data table is exactly what a reader stops seeing.
*/

it('refuses Indexing to Ready without a passing verification', function (): void {
    // NON-NEGOTIABLE 5, AND THE FAILURE IT PREVENTS IS THE ONE USERS DESCRIBE AS "the bot only
    // knows half the document". The edge EXISTS — it is the normal end of a successful run — and it
    // carries the one extra condition in the whole machine.
    expect(SourceState::Indexing->canTransitionTo(SourceState::Ready))->toBeFalse();
    expect(SourceState::Indexing->canTransitionTo(SourceState::ReadyWithWarnings))->toBeFalse();

    // THE DEFAULT IS WHAT MATTERS. `$verified` defaults to FALSE, so a caller who forgot to thread
    // the verification result through is refused rather than publishing quietly. The two calls
    // above pass no argument at all, deliberately: they are what that caller's code looks like.
    expect(SourceState::Indexing->canTransitionTo(SourceState::Ready, verified: true))->toBeTrue();
    expect(SourceState::Indexing->canTransitionTo(SourceState::ReadyWithWarnings, verified: true))->toBeTrue();

    // AND THE GATE IS NARROW. `Indexing -> Failed` is a legal move that has nothing to do with
    // verification, so it must not be swept up by the flag — a run that failed cannot be blocked
    // from recording that it failed.
    expect(SourceState::Indexing->canTransitionTo(SourceState::Failed))->toBeTrue();

    // The gated set is published as data so a caller can ask which edges need it, and it is exactly
    // the two Ready flavours.
    expect(SourceState::verificationGatedTargets())
        ->toBe([SourceState::Ready, SourceState::ReadyWithWarnings]);
});

it('refuses Ready to Deleted, because physical removal has a phase of its own', function (): void {
    // Deletion is two-phase: immediate logical exclusion, then a background purge that is VERIFIED.
    // `Deleting` is the phase the purge worker owns, and skipping it would mark a source removed
    // with nothing having removed anything — a claim with no proof behind it, in the one place this
    // schema exists to keep defensible.
    expect(SourceState::Ready->canTransitionTo(SourceState::Deleted))->toBeFalse();
    expect(SourceState::ReadyWithWarnings->canTransitionTo(SourceState::Deleted))->toBeFalse();

    // The legal route, for the positive control. Without it this test would also pass against a
    // machine that refused EVERY transition.
    expect(SourceState::Ready->canTransitionTo(SourceState::Deleting))->toBeTrue();
    expect(SourceState::Deleting->canTransitionTo(SourceState::Deleted))->toBeTrue();

    // The same skip from every other state that can reach `Deleting`, so the guard is not a
    // property of `Ready` alone.
    foreach ([SourceState::Queued, SourceState::Fetching, SourceState::Disabled, SourceState::Failed] as $from) {
        expect($from->canTransitionTo(SourceState::Deleted))->toBeFalse(
            $from->value.' can reach `deleted` without passing through `deleting`, so a purge that '
            .'never ran would be recorded as one that did.'
        );
    }
});

it('leaves no edge out of Deleted, from any state, including itself', function (): void {
    // Removal has been verified. Every subsequent claim about this row is a claim about something
    // that is not there — and an edge out would let a purged source be re-enabled, which reads as a
    // restore and is a source with no objects, no elements, no chunks and no vectors.
    foreach (SourceState::cases() as $to) {
        expect(SourceState::Deleted->canTransitionTo($to))->toBeFalse(
            'deleted -> '.$to->value.' is an edge out of a terminal state.'
        );

        // Including with the verification flag set, so nobody can reach one by passing `true`.
        expect(SourceState::Deleted->canTransitionTo($to, verified: true))->toBeFalse();
    }

    expect(SourceState::Deleted->isTerminal())->toBeTrue();

    // AND IT IS THE ONLY TERMINAL STATE. `Failed` is terminal for the RUN and not for the item — a
    // fresh run re-enters at `queued` with a new version row — and treating it as terminal is how a
    // retry surface ends up refusing to retry.
    $terminal = array_values(array_filter(
        SourceState::cases(),
        static fn (SourceState $s): bool => $s->isTerminal(),
    ));

    expect($terminal)->toBe([SourceState::Deleted]);
});

it('refuses Failed to Ready, because a failed run is not promoted in place', function (): void {
    // A failed version's points were never verified and may not exist at all. The route back is a
    // NEW RUN, which re-enters at `queued` and mints a new `source_versions` row with its own
    // ingest key — so the prior version keeps serving until the new one is verified and activated.
    expect(SourceState::Failed->canTransitionTo(SourceState::Ready))->toBeFalse();
    expect(SourceState::Failed->canTransitionTo(SourceState::ReadyWithWarnings))->toBeFalse();

    // NOT EVEN WITH THE VERIFICATION FLAG. The flag gates two edges out of `Indexing` and creates
    // none — an argument that could conjure an edge would be a way past the table itself.
    expect(SourceState::Failed->canTransitionTo(SourceState::Ready, verified: true))->toBeFalse();

    // The legal route, as the positive control.
    expect(SourceState::Failed->canTransitionTo(SourceState::Queued))->toBeTrue();

    // AND THE CONTRAST THAT EXPLAINS WHY `Disabled -> Ready` IS LEGAL WHILE THIS IS NOT: a disabled
    // version was verified and activated once and kept every vector, so re-enabling is a metadata
    // write. A failed one was never either.
    expect(SourceState::Disabled->canTransitionTo(SourceState::Ready))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The three named subsets
|--------------------------------------------------------------------------
*/

it('calls exactly the two Ready flavours retrievable', function (): void {
    // ONE TERM OF FOUR. Reachability is the AND of the status, the active-version pointer, the bot
    // assignment and the organization; this predicate answers the status term and nothing else.
    // Widening it is a source answering somebody it was not published for.
    $retrievable = array_values(array_filter(
        SourceState::cases(),
        static fn (SourceState $s): bool => $s->isRetrievable(),
    ));

    expect($retrievable)->toBe([SourceState::Ready, SourceState::ReadyWithWarnings]);

    // `Indexing` IS THE ONE THAT MATTERS HERE. Its points are already in the collection and the
    // pointer still names the previous version — which is exactly what makes publication atomic.
    // Anything that treats "points are present" as "the version is live" has re-invented the
    // half-a-document bug.
    expect(SourceState::Indexing->isRetrievable())->toBeFalse();
    expect(SourceState::Disabled->isRetrievable())->toBeFalse();
});

it('calls exactly the six pipeline stages processing, and not Queued', function (): void {
    $processing = array_values(array_filter(
        SourceState::cases(),
        static fn (SourceState $s): bool => $s->isProcessing(),
    ));

    expect($processing)->toBe([
        SourceState::Fetching, SourceState::Parsing, SourceState::Normalizing,
        SourceState::Chunking, SourceState::Embedding, SourceState::Indexing,
    ]);

    // `Queued` IS ACCEPTANCE, NOT WORK IN FLIGHT, and the distinction is what a stuck-run sweep
    // keys off: a version queued for an hour is a scheduling problem, a version parsing for an hour
    // is a document problem. Folding them together makes both unactionable.
    expect(SourceState::Queued->isProcessing())->toBeFalse();

    // The two sets are disjoint, which `states.py` asserts at import on the other side.
    foreach ($processing as $state) {
        expect($state->isRetrievable())->toBeFalse();
    }
});

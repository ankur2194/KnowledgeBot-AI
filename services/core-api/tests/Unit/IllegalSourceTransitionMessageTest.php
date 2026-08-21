<?php

declare(strict_types=1);

use App\Enums\SourceState;
use App\Services\Sources\IllegalSourceTransition;

/*
|--------------------------------------------------------------------------
| The two messages of a refused transition, and which one is published
|--------------------------------------------------------------------------
|
| `SourceService::illegal()` puts `clientMessage()` in `errors.status` and `getMessage()` in a log
| line. `apps/web` renders a `validation` envelope's per-field messages VERBATIM, on the correct
| general premise that a Laravel validation message is end-user copy — so whatever is in that map
| is, in practice, product copy on a customer's console.
|
| IT WAS NOT. The single message named `App\Enums\SourceState::transitionTable()`, and
| `apps/web/src/features/sources/source-row-actions.tsx` carries a special case that discards it and
| substitutes a sentence of its own, plus a test asserting `transitionTable` never reaches the DOM.
| That workaround is a client compensating for server copy; this file is what makes the server copy
| correct, so the workaround can eventually go.
|
| A UNIT TEST AND IT BOOTS NOTHING. The exception is pure PHP over an enum, and the assertion is
| about STRINGS — which is exactly the kind of claim that rots silently, because no runtime failure
| follows from copy drifting back into jargon.
*/

it('publishes copy a tenant administrator can act on, and names no class, table or column', function (
    SourceState $from,
    SourceState $to,
): void {
    $refused = new IllegalSourceTransition($from, $to, verified: false);

    $client = $refused->clientMessage();

    // ── THE LEAK THIS FILE EXISTS FOR ────────────────────────────────────────────────────────
    //
    // Every spelling of the internal vocabulary `kb-ui-patterns` -> `references/states.md` bans:
    // "chunks, embeddings, collections, spans, queues and error classes are ours. The user has
    // documents, answers, sources and bots."
    foreach (['App\\', '::', 'transitionTable', 'knowledge_sources', 'source_versions'] as $leak) {
        expect(str_contains($client, $leak))->toBeFalse(
            "the client message contains `{$leak}`, which is ours and not the reader's: ".$client,
        );
    }

    // POSITIVE CONTROL. Without it this test passes against a message that says nothing at all —
    // the same shape as an isolation suite that goes green because everything returns nothing.
    expect($client)->toStartWith('This source is ');
    expect(mb_strlen($client))->toBeGreaterThan(60);

    // ── AND THE OPERATOR'S HALF STILL EXISTS ────────────────────────────────────────────────
    //
    // Splitting the message is only a fix if the operator sentence survives somewhere. It is the
    // exception's own `getMessage()`, which `illegal()` logs; if this assertion ever fails, the
    // refusal has stopped naming the one table that answers "why not".
    expect($refused->getMessage())->toContain('App\Enums\SourceState::transitionTable()');
    expect($refused->getMessage())->toContain($from->value);
    expect($refused->getMessage())->toContain($to->value);
})->with([
    // A TERMINAL ROW, the case an administrator meets by clicking an action on a stale list.
    'deleted cannot be re-enabled' => [SourceState::Deleted, SourceState::Ready],
    // A ONE-WAY PHASE. `deleting` is the purge worker's, and every console action is refused here.
    'deleting cannot be re-enabled' => [SourceState::Deleting, SourceState::Ready],
    // AN ORDINARY MISSING EDGE, with nothing dramatic about either end.
    'draft cannot be disabled' => [SourceState::Draft, SourceState::Disabled],
    // THE GATED EDGE, which is a different sentence — see the next test.
    'indexing needs a verification' => [SourceState::Indexing, SourceState::Ready],
]);

it('tells somebody watching an indexing run to wait, rather than to change something', function (): void {
    // ── THE TWO REFUSALS ARE NOT THE SAME REFUSAL ───────────────────────────────────────────
    //
    // `Indexing -> Ready` without a passing verification is a WAIT: the run is under way and will
    // reach that state on its own. Every other refusal is a MISREAD ROW, where reloading is the
    // useful next step. One sentence for both would tell the first reader to go and do the one
    // thing that cannot help — and `states.md` is explicit that the words owe "what to do next".
    $gated = new IllegalSourceTransition(SourceState::Indexing, SourceState::Ready, verified: false);
    $missing = new IllegalSourceTransition(SourceState::Draft, SourceState::Disabled, verified: false);

    expect($gated->gatedOnVerification)->toBeTrue();
    expect($missing->gatedOnVerification)->toBeFalse();

    expect($gated->clientMessage())->toContain('still being indexed');
    expect($gated->clientMessage())->not->toContain('reload');

    expect($missing->clientMessage())->toContain('reload');
    expect($missing->clientMessage())->not->toContain('still being indexed');

    // THE GATE IS NARROW AND THE MESSAGE FOLLOWS IT. `Indexing -> Failed` is a legal edge that has
    // nothing to do with verification, so a refusal FROM `indexing` to some other state must not
    // borrow the waiting sentence and send a reader looking for a run that is not the problem.
    $unrelated = new IllegalSourceTransition(SourceState::Indexing, SourceState::Draft, verified: false);

    expect($unrelated->gatedOnVerification)->toBeFalse();
    expect($unrelated->clientMessage())->toContain('reload');
});

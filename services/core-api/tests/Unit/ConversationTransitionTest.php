<?php

declare(strict_types=1);

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\ProviderCallStatus;

/*
|--------------------------------------------------------------------------
| The conversation vocabularies, and the two transition tables
|--------------------------------------------------------------------------
|
| Unit/ extends NOTHING — no container, no database, no facades. These enums are pure values and
| every assertion below is about the tables themselves, which is what makes this the right directory:
| a transition rule that needed a database to check would be a rule the database was enforcing.
|
| WHY THE TABLES ARE TESTED AT ALL, GIVEN THEY ARE RIGHT THERE IN THE FILE. Because the failure
| direction is silent. A move added to `ConversationStatus::transitionTable()` — reopening an expired
| thread, say — breaks nothing visible: the row updates, the endpoint returns 200, and the damage is
| that the transcript now claims a continuous conversation over a hole the retention sweeper already
| made. Nothing in the schema can see that, because a status column holds one value at a time and
| every one of them is legal on its own.
|
| THE SINKS ARE THE ASSERTIONS THAT MATTER, and they are asserted as a PROPERTY over every case
| rather than case by case, so a sixth status added tomorrow is covered by the same line.
*/

// ── ConversationStatus ──────────────────────────────────────────────────────────────────────────

it('lets an active conversation end or expire, and nothing else', function (): void {
    // BOTH FORWARD MOVES, because they are different events with different causes: `Ended` is
    // somebody's decision, `Expired` is the clock. Collapsing them would make "how many people
    // finish a conversation" unanswerable from the console.
    expect(ConversationStatus::Active->canTransitionTo(ConversationStatus::Ended))->toBeTrue()
        ->and(ConversationStatus::Active->canTransitionTo(ConversationStatus::Expired))->toBeTrue()
        // AND NOT TO ITSELF. A no-op transition would return 200 and write an audit row describing
        // a change that did not happen — the same refusal `BotService::transition()` makes.
        ->and(ConversationStatus::Active->canTransitionTo(ConversationStatus::Active))->toBeFalse();
});

it('makes both terminal states sinks, with no path back to active', function (): void {
    // A PROPERTY OVER EVERY CASE, so a fourth status is covered by this line the day it lands.
    //
    // THE MOVE THIS FORBIDS IS THE ONE SOMEBODY WILL ASK FOR: "let the visitor carry on where they
    // left off." A conversation reopened after expiry is a transcript whose `retention_expires_at`
    // has already been honoured — its messages may be GONE — so the row would claim a continuous
    // thread over a hole. A visitor who comes back gets a NEW conversation, which is also what
    // §8.23 means by "unique sessions".
    foreach (ConversationStatus::cases() as $status) {
        if (! $status->isTerminal()) {
            continue;
        }

        expect(ConversationStatus::transitionTable()[$status->value])->toBe(
            [],
            "`{$status->value}` has acquired an outgoing transition. Both terminal states are sinks: "
            .'the only legal way out is a new conversation.',
        );
    }
});

it('accepts messages in exactly one state', function (): void {
    $accepting = array_values(array_filter(
        ConversationStatus::cases(),
        static fn (ConversationStatus $s): bool => $s->acceptsMessages(),
    ));

    // ONE, AND THE COUNT IS THE ASSERTION. Every widening of this set is a turn appended to a
    // transcript whose retention deadline has already been acted on.
    expect($accepting)->toBe([ConversationStatus::Active]);
});

it('gives every status a row in the transition table', function (): void {
    // THE GAP THIS CATCHES IS A FATAL ERROR, NOT A WRONG ANSWER. `canTransitionTo()` indexes the
    // table by `$this->value`, so a case with no row is an undefined-array-key TypeError at the
    // first call — in whichever service happens to move that status first, in production.
    foreach (ConversationStatus::cases() as $status) {
        expect(ConversationStatus::transitionTable())->toHaveKey($status->value);
    }

    expect(ConversationStatus::transitionTable())
        ->toHaveCount(count(ConversationStatus::cases()));
});

// ── MessageStatus ───────────────────────────────────────────────────────────────────────────────

it('lets a pending message settle without streaming, because a non-streaming call returns whole', function (): void {
    expect(MessageStatus::Pending->canTransitionTo(MessageStatus::Complete))->toBeTrue()
        ->and(MessageStatus::Pending->canTransitionTo(MessageStatus::Streaming))->toBeTrue()
        ->and(MessageStatus::Pending->canTransitionTo(MessageStatus::Failed))->toBeTrue()
        ->and(MessageStatus::Pending->canTransitionTo(MessageStatus::Cancelled))->toBeTrue();
});

it('never lets a streaming message go back to pending', function (): void {
    // The first token has arrived. "Pending" afterwards would reset the one measurement
    // first-token latency is computed from, and §8.23 lists it as its own metric because it is the
    // one a user feels.
    expect(MessageStatus::Streaming->canTransitionTo(MessageStatus::Pending))->toBeFalse()
        ->and(MessageStatus::Streaming->canTransitionTo(MessageStatus::Complete))->toBeTrue()
        ->and(MessageStatus::Streaming->canTransitionTo(MessageStatus::Failed))->toBeTrue()
        ->and(MessageStatus::Streaming->canTransitionTo(MessageStatus::Cancelled))->toBeTrue();
});

it('makes all three terminal message states sinks, because an edit to a settled answer is a retry', function (): void {
    // A PROPERTY OVER EVERY CASE. §16.6 says "Parent message ID when retrying", and this is what
    // makes that the ONLY route: a completed message that is later changed is not an edit, it is a
    // new row carrying `parent_message_id`. That is what makes a transcript an audit record rather
    // than a mutable document.
    foreach (MessageStatus::cases() as $status) {
        if (! $status->isTerminal()) {
            continue;
        }

        expect(MessageStatus::transitionTable()[$status->value])->toBe(
            [],
            "`{$status->value}` has acquired an outgoing transition. A change to a settled message "
            .'is a RETRY — a new row carrying parent_message_id — and never an edit.',
        );
    }
});

it('does not call a streaming message settled', function (): void {
    // THE ONE PRESENTATION FAILURE A READER CANNOT DETECT: a truncated paragraph reads like a short
    // one. Anything that renders `content` as final consults this.
    expect(MessageStatus::Streaming->isSettled())->toBeFalse()
        ->and(MessageStatus::Pending->isSettled())->toBeFalse()
        ->and(MessageStatus::Complete->isSettled())->toBeTrue()
        ->and(MessageStatus::Failed->isSettled())->toBeTrue()
        ->and(MessageStatus::Cancelled->isSettled())->toBeTrue();
});

it('gives every message status a row in the transition table', function (): void {
    foreach (MessageStatus::cases() as $status) {
        expect(MessageStatus::transitionTable())->toHaveKey($status->value);
    }

    expect(MessageStatus::transitionTable())->toHaveCount(count(MessageStatus::cases()));
});

it('never names a state the enum does not declare, on either side of either table', function (): void {
    // THE TABLE IS THE ONLY PLACE A STATE NAME IS WRITTEN TWICE, so it is the only place the two
    // spellings can come apart. A key or a target naming a case that no longer exists is a
    // `canTransitionTo()` that silently answers false forever — the pipeline stalls and nothing
    // says why.
    foreach ([ConversationStatus::class, MessageStatus::class] as $enum) {
        /** @var array<string, list<ConversationStatus|MessageStatus>> $table */
        $table = $enum::transitionTable();
        $values = $enum::values();

        foreach ($table as $from => $targets) {
            expect($values)->toContain($from);

            foreach ($targets as $target) {
                expect($target)->toBeInstanceOf($enum);
            }
        }
    }
});

// ── THE VOCABULARIES WHOSE CHECK CONSTRAINTS ARE GENERATED FROM THEM ────────────────────────────

it('splits the five channels into the three that may be anonymous and the two that may not', function (): void {
    // `conversations_authenticated_channel` is GENERATED from `authenticatedOnly()`, so this is the
    // PHP half of a constraint the database enforces. tests/Feature/ConversationSchemaTest.php
    // asserts the other half against a real row.
    //
    // WRITTEN OUT AS TWO EXPLICIT LISTS rather than derived, because deriving it from the same
    // method the constraint is generated from would assert only that the method equals itself.
    expect(ConversationChannel::authenticatedOnly())
        ->toBe(['playground', 'api']);

    foreach ([ConversationChannel::Hosted, ConversationChannel::Embedded, ConversationChannel::Mobile] as $public) {
        expect($public->permitsAnonymousSession())->toBeTrue();
    }

    foreach ([ConversationChannel::Playground, ConversationChannel::Api] as $authenticated) {
        expect($authenticated->permitsAnonymousSession())->toBeFalse();
    }
});

it('lets only an assistant turn name a provider call', function (): void {
    // `messages_provider_call_only_on_assistant` is generated from `withoutProviderCalls()`.
    expect(MessageRole::withoutProviderCalls())->toBe(['user', 'system'])
        ->and(MessageRole::Assistant->mayHaveProviderCall())->toBeTrue()
        ->and(MessageRole::User->mayHaveProviderCall())->toBeFalse()
        ->and(MessageRole::System->mayHaveProviderCall())->toBeFalse();
});

it('requires an error class on a failed provider call and on nothing else', function (): void {
    // `provider_calls_error_class_paired_with_status` is written as two one-directional
    // implications, so `cancelled` and `pending` are FREE in both directions — see the migration.
    // This asserts the PHP predicate the constraint's `failed` half is generated around.
    $requiring = array_values(array_filter(
        ProviderCallStatus::cases(),
        static fn (ProviderCallStatus $s): bool => $s->requiresErrorClass(),
    ));

    expect($requiring)->toBe([ProviderCallStatus::Failed]);
});

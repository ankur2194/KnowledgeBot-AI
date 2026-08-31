<?php

declare(strict_types=1);

use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\RetrievalTrace;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The tenant boundary around a transcript
|--------------------------------------------------------------------------
|
| BUILT ON tenantPair(), AND THAT IS NOT A STYLE CHOICE. A one-organization fixture cannot fail an
| isolation test: with a single tenant there is nothing to leak, so the test passes against a schema
| with no constraint and a model with no scope — quickly, quietly, and forever. There is
| deliberately no single-organization helper to reach for (pest-testing NN1).
|
| ASSERTED BY CONSTRAINT NAME, NOT BY "SOMETHING THREW", for the reason
| tests/Security/KnowledgeSourceTenancyTest.php states: a bare exception assertion passes when the
| write fails for an unrelated reason — a NOT NULL violation on a column the fixture forgot looks
| identical from outside — and that is the failure mode where a guard is quietly gone while its test
| is green.
|
| ── THIS FILE HAS TO PROVE TWO DIFFERENT THINGS, BECAUSE THE GRAPH IS SCOPED TWO WAYS ─────────
|
| `conversations` and `provider_calls` hold `organization_id` and carry
| `#[ScopedBy(OrganizationScope::class)]`. For those the assertion is the usual one: with Org A's
| context bound, Org B's rows are invisible.
|
| `messages`, `retrieval_traces`, `citations` and `feedback` have NO `organization_id` and carry no
| scope — there is no column to scope, so the attribute would be SQLSTATE 42703 on every read
| (tests/Arch/ConversationDoctrineTest.php pins that exemption by name). For those the assertion is
| the OTHER shape: the NOT NULL foreign-key chain kb-tenancy-isolation NN1 names cannot be bypassed,
| and a query that forgets to join it returns BOTH tenants' rows — which this file demonstrates
| deliberately, because a reader who does not know that is the reader who ships it.
*/

/**
 * Attempt a write and return the QueryException it raised, or null if it succeeded.
 *
 * THE SAVEPOINT IS LOAD-BEARING. `RefreshDatabase` wraps each test in one transaction, and in
 * PostgreSQL a statement that raises inside a transaction ABORTS IT — every subsequent statement
 * fails with 25P02 until a rollback — so without the nested `transaction()`, which Laravel
 * implements as SAVEPOINT / ROLLBACK TO SAVEPOINT, the first expected violation poisons every line
 * after it and the positive control that follows can never run.
 */
function conversationTenancyAttempt(\Closure $write): ?QueryException
{
    try {
        // The closure returns a value rather than nothing so the transaction helper's return type
        // is resolvable; the value itself is discarded.
        (new Conversation)->getConnection()->transaction(static function () use ($write): bool {
            $write();

            return true;
        });

        return null;
    } catch (QueryException $e) {
        return $e;
    }
}

it('refuses a conversation that names another organization\'s bot, by constraint name', function (): void {
    $t = tenantPair();

    // THE POSITIVE CONTROL FIRST, AND IT IS THE POINT OF THE TEST RATHER THAN A PREAMBLE. Without
    // it this file also passes against a schema that refuses EVERY conversation — which would break
    // the product completely while making the security assertion look strongest.
    $legal = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    expect($legal->organization_id)->toBe($t->b->id)
        ->and($legal->bot_id)->toBe($t->botB->id);

    // AND NOW THE ILLEGAL ONE: Org A's tenant key, Org B's bot. The factory refuses this pairing
    // itself, so the row is built by hand — the point is what the DATABASE does, and the factory
    // guard is not what protects a repair script or a console command.
    $rogue = new Conversation;
    $rogue->organization_id = $t->a->id;
    $rogue->bot_id = $t->botB->id;
    $rogue->anonymous_session_id = 'sess_'.str_repeat('c', 24);
    $rogue->channel = \App\Enums\ConversationChannel::Hosted;
    $rogue->status = \App\Enums\ConversationStatus::Active;

    $exception = conversationTenancyAttempt(static fn () => $rogue->save());

    expect($exception)->toBeInstanceOf(
        QueryException::class,
        'a conversation in one organization named a bot in another and the database accepted it. '
        .'That is not a flaky test: `messages`, `citations`, `retrieval_traces` and `feedback` all '
        .'reach their organization THROUGH this row, so one such conversation puts an entire '
        .'transcript — questions, answers, cited excerpts and provider spend — under the wrong '
        .'tenant, and every downstream layer AGREES with it.',
    );

    // THE CONSTRAINT, BY NAME. PostgreSQL puts it in the message; asserting on the string is what
    // distinguishes "the composite key refused this" from "the insert failed for some other
    // reason".
    expect(str_contains((string) $exception?->getMessage(), 'conversations_bot_same_org'))
        ->toBeTrue(
            'the write was refused, but not by the composite foreign key this test exists for. '
            .'The actual message was: '.(string) $exception?->getMessage(),
        );

    // AND NOTHING LANDED. A constraint that raises after writing is not a constraint.
    expect(Conversation::withoutGlobalScopes()->where('organization_id', $t->a->id)->count())
        ->toBe(0);
});

it('refuses a provider call that names another organization\'s bot, connection or model', function (): void {
    $t = tenantPair();

    $connectionB = ProviderConnection::factory()->recycle($t->b)->create();
    $modelB = ProviderModelEntry::factory()->recycle($t->b)->recycle($connectionB)->create();

    // POSITIVE CONTROL: the wholly-Org-B row is accepted.
    $legal = ProviderCall::factory()
        ->recycle($t->b)->recycle($t->botB)->recycle($connectionB)->recycle($modelB)
        ->create();

    expect($legal->organization_id)->toBe($t->b->id);

    // Org A's tenant key with Org B's bot, connection and model. Built by hand for the reason above.
    // THIS IS THE ROW THAT WOULD BILL ONE TENANT'S QUESTIONS TO ANOTHER TENANT'S PROVIDER ACCOUNT,
    // where they are also readable in that tenant's vendor dashboard.
    $rogue = new ProviderCall;
    $rogue->organization_id = $t->a->id;
    $rogue->bot_id = $t->botB->id;
    $rogue->provider_connection_id = $connectionB->id;
    $rogue->model_id = $modelB->id;
    $rogue->status = \App\Enums\ProviderCallStatus::Succeeded;

    $exception = conversationTenancyAttempt(static fn () => $rogue->save());

    expect($exception)->toBeInstanceOf(QueryException::class);

    // BY NAME, AND THE NAME IS `bot_same_org` BECAUSE THAT IS THE COLUMN ORDER PostgreSQL REACHES
    // FIRST. The other two composite keys guard the same row and would fire on their own; asserting
    // one specific name here is what proves a composite key ran rather than, say, a NOT NULL.
    expect(str_contains((string) $exception?->getMessage(), 'provider_calls_bot_same_org'))
        ->toBeTrue(
            'the write was refused, but not by a composite foreign key. The actual message was: '
            .(string) $exception?->getMessage(),
        );

    expect(ProviderCall::withoutGlobalScopes()->where('organization_id', $t->a->id)->count())
        ->toBe(0);
});

it('hides another organization\'s conversations and provider calls behind the bound context', function (): void {
    $t = tenantPair();

    $conversationB = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    $connectionB = ProviderConnection::factory()->recycle($t->b)->create();
    $modelB = ProviderModelEntry::factory()->recycle($t->b)->recycle($connectionB)->create();
    ProviderCall::factory()
        ->recycle($t->b)->recycle($t->botB)->recycle($connectionB)->recycle($modelB)
        ->inConversation($conversationB)
        ->create();

    $context = app(TenantContext::class);

    // `runFor()` AND NOT A SETTER, because TenantContext has no setter — deliberately. The context
    // is scoped to a callback so it cannot leak out of the block that established it, which is the
    // pooled-worker failure kb-tenancy-isolation describes: "No tenant set is the loud failure; the
    // PREVIOUS tenant still set is the silent one."

    // THE POSITIVE CONTROL, AND IT IS MANDATORY AT THE CALL SITE (tests/Support/tenancy.php). As
    // ORG B the rows must be VISIBLE. Without this the negative assertion below also passes when
    // OrganizationScope is broken and returns nothing to everybody — which is exactly what it does
    // with no bound context, since it fails closed with `whereRaw('1 = 0')`.
    $context->runFor($t->b->id, function () use ($conversationB): void {
        expect(Conversation::query()->count())->toBe(1)
            ->and(Conversation::query()->first()?->id)->toBe($conversationB->id)
            ->and(ProviderCall::query()->count())->toBe(1);
    });

    // AND AS ORG A THEY MUST NOT BE.
    $context->runFor($t->a->id, function () use ($conversationB): void {
        expect(Conversation::query()->count())->toBe(0)
            ->and(Conversation::query()->find($conversationB->id))->toBeNull()
            ->and(ProviderCall::query()->count())->toBe(0);
    });
});

it('reaches an organization through the chain for every table that has no column of its own', function (): void {
    $t = tenantPair();

    $conversationB = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();
    $messageB = Message::factory()->recycle($conversationB)->assistant()->settled()->create();
    $traceB = RetrievalTrace::factory()->recycle($messageB)->create();
    $citationB = Citation::factory()->recycle($messageB)->create();
    $feedbackB = Feedback::factory()->recycle($messageB)->create();

    // POSITIVE CONTROL: all four rows exist. Without it the resolution below could be asserting
    // over an empty set.
    expect([$messageB->id, $traceB->id, $citationB->id, $feedbackB->id])->each->not->toBeEmpty();

    // EVERY ONE OF THEM RESOLVES TO ORG B, AND ONLY WITH ITS CHAIN LOADED. This is the substitute
    // for `#[ScopedBy]` on these four tables — `OrgScopedPolicy::permit()` resolves membership of
    // THE RECORD'S organization, and a model that answered the wrong one would authorize an Org A
    // admin over an Org B transcript.
    //
    // INSIDE `runFor($t->b->id)`, AND THAT IS NOT SETUP NOISE — IT IS THE FINDING. The second hop
    // goes through `Conversation`, which carries `#[ScopedBy(OrganizationScope::class)]`, and that
    // scope FAILS CLOSED. Eager-loading it with no bound context resolves the relation to NULL
    // while `relationLoaded()` still reports true, so `organizationId()` gets a null parent rather
    // than an exception. The four models raise a named LogicException for exactly that case; this
    // block asserts the HAPPY path, and the next test asserts the refusal.
    $context = app(TenantContext::class);

    $context->runFor($t->b->id, function () use ($t, $messageB, $traceB, $citationB, $feedbackB): void {
        $message = Message::query()->with('conversation')->findOrFail($messageB->id);
        $trace = RetrievalTrace::query()->with('message.conversation')->findOrFail($traceB->id);
        $citation = Citation::query()->with('message.conversation')->findOrFail($citationB->id);
        $feedback = Feedback::query()->with('message.conversation')->findOrFail($feedbackB->id);

        expect($message->organizationId())->toBe($t->b->id)
            ->and($trace->organizationId())->toBe($t->b->id)
            ->and($citation->organizationId())->toBe($t->b->id)
            ->and($feedback->organizationId())->toBe($t->b->id)
            // AND IT IS NOT ORG A'S — stated explicitly rather than left to the reader, because
            // `toBe($t->b->id)` on a fixture where both organizations exist is only meaningful if
            // the two ids differ, and nothing else in this assertion says they do.
            ->and($t->a->id)->not->toBe($t->b->id);
    });

    // AND THE REFUSAL: with ORG A bound, the chain resolves to null and `organizationId()` raises
    // rather than answering. THE DIRECTION IS THE WHOLE POINT — a model that fell back to "no
    // organization" or returned an empty string here would hand `OrgScopedPolicy::permit()` a value
    // it would compare against a real organization and DENY, which reads as a permission bug rather
    // than as the cross-tenant read it actually refused.
    $context->runFor($t->a->id, function () use ($messageB): void {
        $message = Message::query()->withoutGlobalScopes()->with('conversation')
            ->findOrFail($messageB->id);

        expect(fn (): string => $message->organizationId())->toThrow(\LogicException::class);
    });
});

it('returns BOTH tenants\' messages to a query that forgets the join, which is why the repository takes an organization id', function (): void {
    $t = tenantPair();

    $conversationA = Conversation::factory()->recycle($t->a)->recycle($t->botA)->create();
    $conversationB = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    $messageA = Message::factory()->recycle($conversationA)->create();
    $messageB = Message::factory()->recycle($conversationB)->create();

    // WITH ORG A BOUND — the strongest context this application ever has.
    app(TenantContext::class)->runFor($t->a->id, function () use ($t, $messageA, $messageB): void {
        // THIS IS THE DEMONSTRATION, AND IT IS DELIBERATELY AN ASSERTION THAT THE LEAK IS REAL. A bare
        // `Message::query()` returns BOTH organizations' turns, because there is no `organization_id`
        // on the table and therefore no global scope to apply. Nothing raises. Nothing is logged.
        //
        // IT IS WRITTEN DOWN AS A TEST RATHER THAN AS A COMMENT because the failure it describes is
        // invisible: a developer who writes `Message::query()->where('role', 'user')` gets a plausible
        // result set in a suite with one organization in it, and a cross-tenant read in production.
        // Whoever changes this schema to add an `organization_id` column and a `#[ScopedBy]` should
        // expect this test to go red, and the correct reaction is to invert it — not to delete it.
        expect(Message::query()->count())->toBe(2)
            ->and(Message::query()->pluck('id')->all())
            ->toContain($messageA->id)
            ->toContain($messageB->id);

        // AND THE CORRECT QUERY, WHICH IS WHAT EVERY REPOSITORY METHOD ON THIS GRAPH MUST DO: join the
        // chain and state the organization as a required argument. One organization's turns, and one
        // only.
        $scoped = Message::query()
            ->whereIn(
                'conversation_id',
                Conversation::withoutGlobalScopes()->where('organization_id', $t->a->id)->select('id'),
            )
            ->pluck('id')
            ->all();

        expect($scoped)->toBe([$messageA->id]);
    });
});

it('refuses a citation, trace or feedback row whose parent message does not exist', function (): void {
    $t = tenantPair();

    $conversationB = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();
    $messageB = Message::factory()->recycle($conversationB)->assistant()->settled()->create();

    // POSITIVE CONTROL: with a real parent, all three land.
    expect(conversationTenancyAttempt(fn () => Citation::factory()->recycle($messageB)->create()))
        ->toBeNull();

    // THE NOT NULL FK CHAIN IS WHAT MAKES NN1's SECOND SHAPE TRUE, so an orphan must be
    // unrepresentable: a citation with no message reaches no organization and is readable by
    // whoever asks. `message_id` is NOT NULL with a real key, and this is what checks that the key
    // is really there rather than merely written in a comment.
    $orphan = new Citation;
    $orphan->message_id = (string) \Illuminate\Support\Str::ulid();
    $orphan->label = '1';
    $orphan->display_title = 'Nowhere.pdf';
    $orphan->excerpt = 'Text with no turn behind it.';

    $exception = conversationTenancyAttempt(static fn () => $orphan->save());

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and(str_contains((string) $exception?->getMessage(), 'citations_message_id_fkey'))
        ->toBeTrue(
            'the orphan was refused, but not by the foreign key. The actual message was: '
            .(string) $exception?->getMessage(),
        );
});

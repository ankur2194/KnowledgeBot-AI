<?php

declare(strict_types=1);

use App\Enums\ConversationChannel;
use App\Enums\FeedbackRating;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\ProviderCallStatus;
use App\Models\Bot;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\RetrievalTrace;
use App\Models\User;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Chat\NewConversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| What the conversation schema refuses, asserted against the database itself
|--------------------------------------------------------------------------
|
| EVERY TEST BELOW WRITES THROUGH THE MODEL AND NOT THROUGH AN ENDPOINT, the same call
| tests/Feature/BotSchemaTest.php and tests/Feature/SourceCascadeSchemaTest.php make and for the
| same reason: these are the constraints that must hold for a writer that never ran a FormRequest —
| a repair script, a console command, a seeder, a retention sweeper, a future chat service nobody
| has written yet. A test that drove an HTTP route would prove the FormRequest works and would say
| nothing about the row. D1 ships no routes at all, so there is nothing else it could prove.
|
| THE FK ACTIONS ARE ASSERTED, NOT ASSUMED, AND THAT IS MOST OF THIS FILE. Every migration in this
| step carries a comment claiming what a delete does — cascade, restrict, set null — and a comment
| is not a mechanism. postgresql-patterns names this exact table set in its Definition of done: "a
| test deletes a source and asserts the prior transcript still renders label, title, location, and
| excerpt", which is the last test here.
|
| tests/Security/ConversationTenancyTest.php holds the OTHER half — the composite foreign keys and
| the organization scope — because those fail for a different reason, and a red test there is a
| tenant boundary rather than a shape.
*/

/**
 * Attempt a write and return the QueryException it raised, or null if it succeeded.
 *
 * THE SAVEPOINT IS LOAD-BEARING AND IS NOT DEFENSIVE PROGRAMMING. `RefreshDatabase` wraps each test
 * in one transaction, and in PostgreSQL a statement that raises inside a transaction ABORTS IT:
 * every subsequent statement fails with 25P02 until a rollback. Without the nested `transaction()`
 * — which Laravel implements as SAVEPOINT / ROLLBACK TO SAVEPOINT when one is already open — the
 * first expected violation poisons every line after it, and the positive control that follows can
 * never run. The symptom is a SECOND assertion failing with a SQLSTATE nobody recognises, pointing
 * at a statement that is perfectly valid.
 */
function conversationAttempt(\Closure $write): ?QueryException
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

/**
 * A provider connection and a model registered under it, both in this organization.
 *
 * BOTH RECYCLED FROM THE SAME ORGANIZATION AND THE MODEL FROM THE SAME CONNECTION.
 * `ProviderCallFactory` refuses the second pairing itself — nothing in the database does, because
 * the two composite foreign keys each check their reference against the ORGANIZATION and neither
 * checks them against each other — so building the pair correctly here is what keeps these tests
 * about the constraint under test rather than about the fixture.
 *
 * @return array{0: ProviderConnection, 1: ProviderModelEntry}
 */
function seedProviderPair(Organization $organization): array
{
    $connection = ProviderConnection::factory()->recycle($organization)->create();
    $model = ProviderModelEntry::factory()->recycle($organization)->recycle($connection)->create();

    return [$connection, $model];
}

/**
 * An organization, a bot in it, and a conversation on that bot.
 *
 * @return array{0: Organization, 1: Bot, 2: Conversation}
 */
function seedConversation(): array
{
    $organization = Organization::factory()->create();
    $bot = Bot::factory()->recycle($organization)->create();
    $conversation = Conversation::factory()->recycle($organization)->recycle($bot)->create();

    return [$organization, $bot, $conversation];
}

// ── THE CLOCK ───────────────────────────────────────────────────────────────────────────────────

it('opens a conversation across a second boundary without violating its own clock constraint', function (): void {
    $organization = Organization::factory()->create();
    $bot = Bot::factory()->recycle($organization)->create();

    /*
     * ═══ A CLOCK THAT STEPS OVER A SECOND BOUNDARY BETWEEN ITS FIRST AND SECOND READING ═══════
     *
     * `Conversation::CREATED_AT` is `started_at`, so Eloquent stamps that column itself from
     * `freshTimestamp()` inside `save()` — a reading of the clock taken AFTER the repository has
     * finished assigning attributes. `EloquentConversationRepository::create()` used to read the
     * clock separately for `last_activity_at`, and the table CHECKs `last_activity_at >=
     * started_at`.
     *
     * Two microseconds apart is enough when they straddle a second: Laravel's date cast stores a
     * date attribute as a `Y-m-d H:i:s` STRING, so both columns are truncated to the second in
     * memory and in PostgreSQL. 06:34:05.999999 and 06:34:06.000001 become `06:34:05` and
     * `06:34:06` — activity BEFORE the start — and the insert dies with SQLSTATE 23514. The caller
     * gets a 500 from `POST /rt/v1/conversations` with nothing wrong with the request, and a retry
     * a moment later works. It surfaced once in a Feature run on 2026-08-31 and never in the same
     * file run alone.
     *
     * THE FIRST VERSION OF THIS TEST COMPARED THE TWO TIMESTAMPS TO THE MICROSECOND AND WAS USELESS,
     * which is worth recording because the reasoning was plausible: two clock readings differ in
     * their microseconds, so an equality ought to catch a second reading every run. It cannot — the
     * cast has already flattened both to whole seconds before any assertion sees them, so the
     * comparison agreed with the defect present and passed a positive control it should have failed.
     * Pinning the CLOCK is what makes the boundary a certainty instead of a coincidence.
     */
    $readings = [
        CarbonImmutable::parse('2026-08-31 06:34:05.999999', 'UTC'),
        CarbonImmutable::parse('2026-08-31 06:34:06.000001', 'UTC'),
    ];

    $taken = 0;

    CarbonImmutable::setTestNow(function () use ($readings, &$taken): CarbonImmutable {
        return $readings[min($taken++, 1)];
    });

    $conversation = null;

    // Through the savepoint helper for the reason its docblock gives: a violation here would abort
    // the RefreshDatabase transaction and every assertion after it would fail with 25P02 instead.
    $failure = conversationAttempt(function () use ($organization, $bot, &$conversation): Conversation {
        $conversation = app(ConversationRepositoryInterface::class)->create(
            (string) $organization->id,
            (string) $bot->id,
            NewConversation::forAnonymousSession(
                ConversationChannel::Embedded,
                'sess_'.str_repeat('z', 20),
                null,
                false,
                null,
                30,
            ),
        );

        return $conversation;
    });

    CarbonImmutable::setTestNow();

    // Named rather than asserted as `toBeNull()`, so a regression prints WHICH constraint refused
    // the row instead of "expected null, got object".
    expect($failure?->getMessage())->toBeNull();

    /*
     * BOTH COLUMNS CARRY THE FIRST READING. This is the half that says the anchor is one clock read
     * and not merely two readings that happened to agree: with a second reading behind
     * `started_at`, `create()` above would have thrown, and with the clock un-pinned this pair
     * agrees roughly 999 times in 1000 no matter which version is running.
     */
    expect($conversation?->started_at->format('Y-m-d H:i:s'))->toBe('2026-08-31 06:34:05')
        ->and($conversation?->last_activity_at->format('Y-m-d H:i:s'))->toBe('2026-08-31 06:34:05')
        // The sibling constraint reads the same anchor: `retention_expires_at > started_at`.
        ->and($conversation?->retention_expires_at?->format('Y-m-d H:i:s'))->toBe('2026-09-30 06:34:05');
});

// ── THE CLOSED VOCABULARIES ─────────────────────────────────────────────────────────────────────

it('refuses a channel outside the five-value vocabulary', function (): void {
    [$organization, $bot] = seedConversation();

    // THE POSITIVE CONTROL, AND IT IS THE POINT RATHER THAN A PREAMBLE: every one of the five
    // declared values must be accepted. A CHECK generated from the wrong list — or from an enum
    // somebody re-cased — would refuse them all, which is a total outage that makes the negative
    // assertion below look strongest.
    foreach (ConversationChannel::cases() as $channel) {
        $row = Conversation::factory()->recycle($organization)->recycle($bot)->make([
            'channel' => $channel,
        ]);

        // The two authenticated-only channels need a user; the constraint that says so is asserted
        // separately below. Here we are only proving the vocabulary.
        if (! $channel->permitsAnonymousSession()) {
            $user = User::factory()->recycle($organization)->create();
            $row->user_id = $user->id;
            $row->anonymous_session_id = null;
        }

        expect(conversationAttempt(fn () => $row->save()))->toBeNull(
            "channel `{$channel->value}` was refused by the database, which means "
            .'`conversations_channel_check` and App\Enums\ConversationChannel have come apart.',
        );
    }

    // And the negative: a value the enum does not declare.
    $rogue = Conversation::factory()->recycle($organization)->recycle($bot)->make();

    // `sync: false`, AND THE FALSE IS LOAD-BEARING ON THIS MODEL. Syncing marks every attribute
    // clean, and `Model::updateTimestamps()` then overwrites CREATED_AT — which on Conversation is
    // `started_at` — with now(), leaving `last_activity_at` in the past and tripping
    // `conversations_activity_after_start` instead of the constraint under test. The failure reads
    // as the wrong constraint firing, which is exactly the kind of misdirection that gets a test
    // 'fixed' by relaxing the assertion.
    $rogue->setRawAttributes(array_merge($rogue->getAttributes(), ['channel' => 'sms']), false);

    $violation = conversationAttempt(fn () => $rogue->save());

    expect($violation)->toBeInstanceOf(QueryException::class)
        ->and($violation?->getMessage())->toContain('conversations_channel_check');
});

it('refuses a message role and a message status outside their vocabularies', function (): void {
    [, , $conversation] = seedConversation();

    // POSITIVE CONTROL on both vocabularies, for the reason above.
    foreach (MessageRole::cases() as $role) {
        $row = Message::factory()->recycle($conversation)->make([
            'role' => $role, 'content' => 'x', 'status' => MessageStatus::Complete,
        ]);

        expect(conversationAttempt(fn () => $row->save()))->toBeNull();
    }

    foreach (MessageStatus::cases() as $status) {
        $row = Message::factory()->recycle($conversation)->make([
            'role' => MessageRole::User,
            // `messages_content_present_when_complete` permits a null outside `complete` only.
            'content' => $status === MessageStatus::Complete ? 'x' : null,
            'status' => $status,
        ]);

        expect(conversationAttempt(fn () => $row->save()))->toBeNull();
    }

    $rogue = Message::factory()->recycle($conversation)->make();
    $rogue->setRawAttributes(array_merge($rogue->getAttributes(), ['role' => 'tool']), false);

    expect(conversationAttempt(fn () => $rogue->save())?->getMessage())
        ->toContain('messages_role_check');
});

it('refuses an error_class that is not one of the taxonomy\'s eighteen', function (): void {
    [$organization, $bot] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $call = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();

    // POSITIVE CONTROL: a real class from App\Support\Kb\ErrorTaxonomy, which the contract suite
    // pins against services/ai-service/app/core/errors.py. If this is refused, the constraint was
    // generated from the wrong list and every failed call in production becomes a 500.
    $call->status = ProviderCallStatus::Failed;
    $call->error_class = 'provider_rate_limit';

    expect(conversationAttempt(fn () => $call->save()))->toBeNull();

    // And a class neither plane knows. `provider_error` is the plausible invention — it is what a
    // dashboard bucket would be called — which is exactly why it must be refused.
    $rogue = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();
    $rogue->status = ProviderCallStatus::Failed;
    $rogue->error_class = 'provider_error';

    expect(conversationAttempt(fn () => $rogue->save())?->getMessage())
        ->toContain('provider_calls_error_class_check');
});

// ── THE PAIRED CONSTRAINTS ──────────────────────────────────────────────────────────────────────

it('requires exactly one participant, never two and never none', function (): void {
    [$organization, $bot] = seedConversation();
    $user = User::factory()->recycle($organization)->create();

    // BOTH: a signed-in visitor on hosted chat who also carries a session cookie. This is a
    // REACHABLE state and it is the one that double-counts §8.23's unique sessions.
    $both = Conversation::factory()->recycle($organization)->recycle($bot)->make();
    $both->user_id = $user->id;

    expect(conversationAttempt(fn () => $both->save())?->getMessage())
        ->toContain('conversations_participant_exclusive');

    // NEITHER: a transcript belonging to nobody.
    $neither = Conversation::factory()->recycle($organization)->recycle($bot)->make();
    $neither->anonymous_session_id = null;

    expect(conversationAttempt(fn () => $neither->save())?->getMessage())
        ->toContain('conversations_participant_exclusive');
});

it('refuses an anonymous playground or api conversation', function (): void {
    [$organization, $bot] = seedConversation();

    foreach (ConversationChannel::authenticatedOnly() as $channel) {
        $row = Conversation::factory()->recycle($organization)->recycle($bot)->make([
            'channel' => ConversationChannel::from($channel),
        ]);

        // NO CUSTOM MESSAGE: `toContain()` takes NEEDLES, and a second string argument would be
        // asserted as a second needle rather than shown on failure. The reason lives in the
        // paragraph above instead — the playground proves an identity through the admin SPA session
        // and the API through a Sanctum token that belongs to a user, so an anonymous row on either
        // is a transcript with no identity attached to it.
        expect(conversationAttempt(fn () => $row->save())?->getMessage())
            ->toContain('conversations_authenticated_channel');
    }
});

it('refuses a consent-collecting conversation with no snapshot of what was shown', function (): void {
    [$organization, $bot] = seedConversation();

    // POSITIVE CONTROL: the complete, legal shape.
    $ok = Conversation::factory()->recycle($organization)->recycle($bot)->collecting()->make();

    expect(conversationAttempt(fn () => $ok->save()))->toBeNull();

    // The flag with no text. `bots.consent_text` is editable, so a row recording only "consent:
    // true" is a record of agreement to whatever the text says TODAY.
    $unsnapshotted = Conversation::factory()->recycle($organization)->recycle($bot)->make();
    $unsnapshotted->consent_required = true;

    expect(conversationAttempt(fn () => $unsnapshotted->save())?->getMessage())
        ->toContain('conversations_consent_snapshot_present');
});

it('ties a provider call\'s error_class to its status in both directions', function (): void {
    [$organization, $bot] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $make = fn (): ProviderCall => ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();

    // A failure with no class: an outage nobody can categorise.
    $classless = $make();
    $classless->status = ProviderCallStatus::Failed;

    expect(conversationAttempt(fn () => $classless->save())?->getMessage())
        ->toContain('provider_calls_error_class_paired_with_status');

    // A success WITH a class: a row §8.23 would count in both buckets.
    $contradictory = $make();
    $contradictory->error_class = 'provider_temporary';

    expect(conversationAttempt(fn () => $contradictory->save())?->getMessage())
        ->toContain('provider_calls_error_class_paired_with_status');

    // AND THE DELIBERATE GAP: `cancelled` is free in both directions, because the caller going away
    // is a real member of the taxonomy (`user_cancellation`) and equally legitimately no fault at
    // all. An equivalence constraint would force one spelling and refuse the other.
    $cancelledWithClass = $make();
    $cancelledWithClass->status = ProviderCallStatus::Cancelled;
    $cancelledWithClass->error_class = 'user_cancellation';

    expect(conversationAttempt(fn () => $cancelledWithClass->save()))->toBeNull();

    $cancelledWithout = $make();
    $cancelledWithout->status = ProviderCallStatus::Cancelled;

    expect(conversationAttempt(fn () => $cancelledWithout->save()))->toBeNull();
});

// ── THE JSONB SHAPES ────────────────────────────────────────────────────────────────────────────

it('refuses a non-object in a jsonb column whose shape is an object', function (): void {
    [$organization, $bot] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $call = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();

    // POSITIVE CONTROL, AND IT IS THE JsonObjectCast REGRESSION. An EMPTY map is the overwhelmingly
    // common state on this column — every primary attempt has one — and Eloquent's built-in `array`
    // cast would write `[]`, a JSON ARRAY, which this constraint refuses. If this line fails, the
    // cast has been changed to `'array'` and every unremarkable provider call is about to 500.
    $call->fallback_metadata = [];

    expect(conversationAttempt(fn () => $call->save()))->toBeNull();

    // And a LIST written past the cast: the shape a `$request->all()` one nesting level down
    // produces. Written raw, because JsonObjectCast deliberately converts a list into a map with
    // numeric keys rather than letting one through.
    $rogue = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();
    $rogue->setRawAttributes(
        array_merge($rogue->getAttributes(), ['fallback_metadata' => '["primary","fallback"]']),
        true,
    );

    expect(conversationAttempt(fn () => $rogue->save())?->getMessage())
        ->toContain('provider_calls_fallback_metadata_is_object');
});

it('requires a retrieval trace to name all four mandatory filter terms, and keeps its two ranked lists arrays', function (): void {
    [, , $conversation] = seedConversation();
    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    // POSITIVE CONTROL: the complete four-term shape the factory produces.
    expect(conversationAttempt(
        fn () => RetrievalTrace::factory()->recycle($message)->create(),
    ))->toBeNull();

    // Three terms. THE MISSING ONE IS `source_version_id`, deliberately: it is the term
    // kb-tenancy-isolation calls load-bearing alongside `org_id`, because it is resolved per request
    // and is what makes a disable or a delete take effect immediately.
    $incomplete = RetrievalTrace::factory()->recycle($message)->filteredBy([
        'org_id' => 'x', 'bot_ids' => ['y'], 'source_status' => ['ready'],
    ])->make();

    expect(conversationAttempt(fn () => $incomplete->save())?->getMessage())
        ->toContain('retrieval_traces_filters_name_mandatory_terms');

    // AND THE ARRAY HALF, WHICH IS THE ASYMMETRY THIS SCHEMA MAKES ON PURPOSE. `selected_evidence`
    // is a RANKED LIST — its order is the ranking — so its constraint demands `array` and not
    // `object`. A map written there is refused.
    $mapped = RetrievalTrace::factory()->recycle($message)->make();
    $mapped->setRawAttributes(
        array_merge($mapped->getAttributes(), ['selected_evidence' => '{"0":"first"}']),
        true,
    );

    expect(conversationAttempt(fn () => $mapped->save())?->getMessage())
        ->toContain('retrieval_traces_selected_evidence_is_array');
});

// ── THE FOREIGN KEY ACTIONS ─────────────────────────────────────────────────────────────────────

it('cascades a conversation delete to its messages, and each message to its citations, trace and feedback', function (): void {
    [, , $conversation] = seedConversation();

    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();
    $trace = RetrievalTrace::factory()->recycle($message)->create();
    $citation = Citation::factory()->recycle($message)->create();
    $feedback = Feedback::factory()->recycle($message)->create();

    // POSITIVE CONTROL: all four children exist before anything is deleted. Without it a cascade
    // test passes against a fixture that never wrote them.
    expect(Message::query()->whereKey($message->id)->exists())->toBeTrue()
        ->and(RetrievalTrace::query()->whereKey($trace->id)->exists())->toBeTrue()
        ->and(Citation::query()->whereKey($citation->id)->exists())->toBeTrue()
        ->and(Feedback::query()->whereKey($feedback->id)->exists())->toBeTrue();

    // ONE STATEMENT, AND IT REACHES FOUR TABLES. That is the property the comment in
    // 2026_08_26_002800 claims and this is the only thing that checks it.
    $conversation->delete();

    expect(Message::query()->whereKey($message->id)->exists())->toBeFalse()
        ->and(RetrievalTrace::query()->whereKey($trace->id)->exists())->toBeFalse()
        ->and(Citation::query()->whereKey($citation->id)->exists())->toBeFalse()
        ->and(Feedback::query()->whereKey($feedback->id)->exists())->toBeFalse();
});

it('refuses to delete a bot that has held a conversation, and permits it once the conversation is gone', function (): void {
    [, $bot, $conversation] = seedConversation();

    // THE REFUSAL, AT THE DATABASE. `BotService::delete()` turns this into a 409 with a sentence
    // about archiving, and BotCrudTest asserts that; this asserts the guarantee underneath it,
    // which is what protects a repair script and a console command too.
    expect(conversationAttempt(fn () => $bot->delete())?->getMessage())
        ->toContain('conversations_bot_same_org');

    // AND THE POSITIVE CONTROL, WHICH IS WHAT MAKES THE ASSERTION ABOVE MEAN SOMETHING: with no
    // conversation the same delete succeeds. A key written with the wrong action — or a bot that
    // could not be deleted for some unrelated reason — would fail the first half identically.
    $conversation->delete();

    expect(conversationAttempt(fn () => $bot->delete()))->toBeNull();
});

it('keeps a provider call after its conversation and message are swept, because retention removes content and not cost', function (): void {
    [$organization, $bot, $conversation] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    $call = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->forTurn($message)
        ->create();

    // POSITIVE CONTROL: the links are really there before the sweep.
    expect($call->conversation_id)->toBe($conversation->id)
        ->and($call->message_id)->toBe($message->id);

    $conversation->delete();

    $survivor = ProviderCall::query()->withoutGlobalScopes()->find($call->id);

    expect($survivor)->not->toBeNull(
        'the provider call was destroyed with its conversation. Honouring a retention policy would '
        .'then silently rewrite last quarter\'s spend, which is why both links are SET NULL.',
    )
        ->and($survivor?->conversation_id)->toBeNull()
        ->and($survivor?->message_id)->toBeNull()
        // THE FOUR COLUMNS THAT MUST SURVIVE, because every §8.23 aggregate groups by the first two
        // and reconciles against the last two.
        ->and($survivor?->organization_id)->toBe($organization->id)
        ->and($survivor?->bot_id)->toBe($bot->id)
        ->and($survivor?->estimated_cost)->not->toBeNull()
        ->and($survivor?->input_tokens)->not->toBeNull();
});

it('refuses to delete a provider connection or model that a recorded call names', function (): void {
    [$organization, $bot] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->create();

    // "A provider_connection must not be deletable out from under a provider_calls row that records
    // history" — the credential that was billed is not derivable from anything else on the row.
    //
    // THE CONSTRAINT NAME IS NOT ASSERTED HERE, AND THE REASON IS WORTH RECORDING RATHER THAN
    // WORKING AROUND. The connection is protected TWICE: by `provider_models_connection_same_org`
    // (its own catalog rows) and by `provider_calls_connection_same_org` (this row). PostgreSQL
    // reports whichever it checks first, which is the catalog one — and the pair cannot be
    // separated, because `provider_calls.model_id` is NOT NULL and a model row only exists under a
    // connection, so a call naming a connection with no models is unrepresentable. Asserting the
    // SQLSTATE is therefore the strongest honest claim: the delete is refused.
    $refusal = conversationAttempt(fn () => $connection->delete());

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and($refusal?->getMessage())->toContain('RESTRICT');

    // AND THE MODEL, WHICH IS THE LARGER COMMITMENT AND IS ASSERTED SO IT CANNOT BE WEAKENED
    // QUIETLY. `ProviderModelService::delete()` hard-deletes catalog rows today; once this table has
    // rows that path must become a refusal with a sentence, exactly as the bot delete did. The
    // migration names that obligation and this is what makes forgetting it a red test rather than a
    // 500 in production.
    expect(conversationAttempt(fn () => $model->delete())?->getMessage())
        ->toContain('provider_calls_model_same_org');
});

it('keeps a citation readable after the chunk it points at is gone', function (): void {
    [, , $conversation] = seedConversation();
    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    $citation = Citation::factory()->recycle($message)->rendering(
        'Employee Handbook.pdf',
        'Refunds are accepted for 30 days from delivery.',
        ['page' => 7],
    )->create();

    // THE TEST postgresql-patterns ASKS FOR BY NAME: "a test deletes a source and asserts the prior
    // transcript still renders label, title, location, and excerpt". The chunk is left NULL by the
    // factory default, which IS the post-purge state — the four rendered columns are denormalized
    // onto this row precisely so the footnote survives without it.
    $citation->refresh();

    expect($citation->chunk_id)->toBeNull()
        ->and($citation->label)->not->toBe('')
        ->and($citation->display_title)->toBe('Employee Handbook.pdf')
        ->and($citation->location_metadata)->toBe(['page' => 7])
        ->and($citation->excerpt)->toBe('Refunds are accepted for 30 days from delivery.');
});

it('permits one verdict per person per answer and refuses a second', function (): void {
    [, , $conversation] = seedConversation();
    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    $session = 'sess_'.str_repeat('a', 24);

    // POSITIVE CONTROL: the first thumb lands.
    expect(conversationAttempt(
        fn () => Feedback::factory()->recycle($message)->bySession($session)->create(),
    ))->toBeNull();

    // A DIFFERENT PERSON ON THE SAME ANSWER IS FINE — which is what makes the refusal below about
    // the person rather than about the message.
    expect(conversationAttempt(
        fn () => Feedback::factory()->recycle($message)->bySession('sess_'.str_repeat('b', 24))->create(),
    ))->toBeNull();

    // The same person twice. Changing your mind is an UPDATE, not a second row: without this,
    // §8.23's feedback rate is a count of clicks any bored visitor can move on their own.
    expect(conversationAttempt(
        fn () => Feedback::factory()->recycle($message)->bySession($session)->negative()->create(),
    )?->getMessage())->toContain('feedback_message_session_unique');
});

it('refuses a retry that names a message in another conversation', function (): void {
    [$organization, $bot, $first] = seedConversation();
    $second = Conversation::factory()->recycle($organization)->recycle($bot)->create();

    $parent = Message::factory()->recycle($first)->create();

    // POSITIVE CONTROL: a retry inside the SAME conversation is legal.
    expect(conversationAttempt(
        fn () => Message::factory()->recycle($first)->retryOf($parent)->create(),
    ))->toBeNull();

    // Across conversations it is not — and the reason is a tenant boundary, not tidiness: a
    // conversation is what carries the organization, so a simple key on `messages (id)` would have
    // let a retry point at another tenant's message with no column on this row to contradict it.
    $foreign = Message::factory()->recycle($second)->make();
    $foreign->parent_message_id = $parent->id;

    expect(conversationAttempt(fn () => $foreign->save())?->getMessage())
        ->toContain('messages_parent_same_conversation');
});

it('refuses a provider call on a user or system turn', function (): void {
    [$organization, $bot, $conversation] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $assistant = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    $call = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->forTurn($assistant)
        ->create();

    // POSITIVE CONTROL: the assistant turn may name it.
    $assistant->provider_call_id = $call->id;

    expect(conversationAttempt(fn () => $assistant->save()))->toBeNull();

    // A user turn costs nothing and a platform notice is produced here, so a call on either is a
    // mis-attributed cost that the per-bot spend would report as real.
    $userTurn = Message::factory()->recycle($conversation)->create();
    $userTurn->provider_call_id = $call->id;

    expect(conversationAttempt(fn () => $userTurn->save())?->getMessage())
        ->toContain('messages_provider_call_only_on_assistant');
});

it('refuses a token breakdown larger than the input total it partitions', function (): void {
    [$organization, $bot] = seedConversation();
    [$connection, $model] = seedProviderPair($organization);

    $call = ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->make();

    // THE ANTHROPIC NORMALIZATION, AS FAR AS A CONSTRAINT CAN SEE IT. `input_tokens` is the TOTAL
    // input with cache included; the two cache columns partition it. An adapter that ADDED where it
    // should have partitioned produces this row, and the symptom is a cost estimate wrong for
    // exactly one vendor.
    $call->input_tokens = 1_000;
    $call->cache_read_tokens = 900;
    $call->cache_write_tokens = 400;

    expect(conversationAttempt(fn () => $call->save())?->getMessage())
        ->toContain('provider_calls_cache_within_input');
});

it('refuses a feedback rating outside the two-value vocabulary', function (): void {
    [, , $conversation] = seedConversation();
    $message = Message::factory()->recycle($conversation)->assistant()->settled()->create();

    // POSITIVE CONTROL on both values.
    foreach (FeedbackRating::cases() as $rating) {
        $row = Feedback::factory()->recycle($message)
            ->bySession('sess_'.str_pad($rating->value, 20, 'z'))
            ->make(['rating' => $rating]);

        expect(conversationAttempt(fn () => $row->save()))->toBeNull();
    }

    // `neutral` is the plausible invention — it is what a three-point scale would add — which is why
    // widening this must be a migration with a backfill question attached.
    $rogue = Feedback::factory()->recycle($message)->make();
    $rogue->setRawAttributes(array_merge($rogue->getAttributes(), ['rating' => 'neutral']), false);

    expect(conversationAttempt(fn () => $rogue->save())?->getMessage())
        ->toContain('feedback_rating_check');
});

<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Bots\BotStarterQuestionService;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Bot starter questions — index, store, update, destroy
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half. The authorization and isolation half is
| tests/Security/BotChildEndpointAccessTest.php.
|
| WHAT THE HARD PART IS HERE, and it is not authorization. `bot_starter_questions_org_bot_position`
| is UNIQUE per bot and DELIBERATELY NOT DEFERRABLE — the migration records the trade — so the whole
| difficulty of this surface is that no naive UPDATE can reorder a list: "set this row to 2" collides
| with whichever row holds 2, as SQLSTATE 23505 rendered as a 500 for a request the operator has
| every right to make. Every write here re-sequences the whole list inside one transaction, and the
| assertions below are mostly about the invariant that follows: POSITIONS ARE 0..n-1 WITH NO GAPS
| AND NO DUPLICATES, after every operation.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS, even in the behaviour file — a one-organization fixture passes
| every assertion here against code with the tenant filter deleted.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey, so every rate-limiter bucket survives the
    // test that filled it.
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Two organizations, one bot each, org A's bot carrying three questions in a known order.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run.
 *
 * THREE QUESTIONS AND NOT TWO, because a reorder has three distinguishable outcomes (moved to the
 * front, to the middle, to the end) and a two-item list makes all three the same swap.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization, ownerA: User,
 *     botA: Bot, botB: Bot,
 *     first: BotStarterQuestion, second: BotStarterQuestion, third: BotStarterQuestion,
 * }
 */
function botStarterQuestionFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Chips Org ALPHA', 'slug' => 'chips-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Chips Org BRAVO', 'slug' => 'chips-bravo']);

    // ->recycle() on every child, without exception: both factories REFUSE to run without a
    // recycled organization, because a row minted into a THIRD organization is what makes an
    // isolation assertion pass with the tenant filter deleted.
    $botA = Bot::factory()->recycle($orgA)->create(['name' => 'ALPHA bot', 'slug' => 'alpha-chips']);
    $botB = Bot::factory()->recycle($orgB)->create(['name' => 'BRAVO bot', 'slug' => 'bravo-chips']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('chips-owner-alpha')]),
        'botA' => $botA,
        'botB' => $botB,
        // ->at() AND NOT THE FACTORY'S COUNTER, because every assertion below is about ORDER and a
        // position that came from a counter is a position nobody wrote down.
        'first' => BotStarterQuestion::factory()->recycle($orgA)->recycle($botA)
            ->at(0)->asking('Where is my order?')->create(),
        'second' => BotStarterQuestion::factory()->recycle($orgA)->recycle($botA)
            ->at(1)->asking('How do I get a refund?')->create(),
        'third' => BotStarterQuestion::factory()->recycle($orgA)->recycle($botA)
            ->at(2)->asking('What are your opening hours?')->create(),
    ];
}

/**
 * The collection URL for org A's bot.
 *
 * @param  array{orgA: Organization, botA: Bot, ...}  $fixture
 */
function botStarterQuestionUrl(array $fixture): string
{
    return "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/starter-questions";
}

/**
 * The bot's questions as `[question => sort_order]`, read straight from the table.
 *
 * READ WITHOUT GLOBAL SCOPES AND WITH AN EXPLICIT BOT PREDICATE, so the helper cannot accidentally
 * be the thing that makes an assertion pass: it is asserting the DATABASE's state rather than what
 * the endpoint chose to render.
 *
 * @return array<string, int>
 */
function starterQuestionOrder(Bot $bot): array
{
    $rows = BotStarterQuestion::query()->withoutGlobalScopes()
        ->where('bot_id', '=', $bot->id)
        ->orderBy('sort_order')
        ->get();

    $order = [];

    foreach ($rows as $row) {
        $order[$row->question] = $row->sort_order;
    }

    return $order;
}

// ── GET …/starter-questions ──────────────────────────────────────────────────────────────────────

it('lists the questions in the order the operator set, wrapped in an object', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(botStarterQuestionUrl($fixture), spaHeaders())->assertOk();

    expect($response->json('data.starter_questions.*.question'))
        ->toBe(['Where is my order?', 'How do I get a refund?', 'What are your opening hours?'])
        ->and($response->json('data.starter_questions.*.sort_order'))->toBe([0, 1, 2]);

    // THE ENVELOPE IS AN OBJECT WRAPPING THE ARRAY, not a bare array: `#[ResponseShape]` maps a
    // response KEY to a resource class and cannot express "an array of".
    expect(array_keys((array) $response->json('data')))->toBe(['starter_questions']);
});

// ── POST …/starter-questions ─────────────────────────────────────────────────────────────────────

it('appends a new question and decides its position itself', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        botStarterQuestionUrl($fixture),
        [
            'question' => 'Do you ship internationally?',
            // OVER-POSTED AND IGNORED. `sort_order` is the server's arithmetic: the set of
            // positions is an invariant, and an invariant a client can name is one a client can
            // break. A caller choosing an occupied position would be SQLSTATE 23505 as a 500.
            'sort_order' => 0,
        ],
        spaHeaders(),
    )
        ->assertStatus(201)
        ->assertJsonPath('data.question', 'Do you ship internationally?')
        ->assertJsonPath('data.sort_order', 3);

    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'Where is my order?' => 0,
        'How do I get a refund?' => 1,
        'What are your opening hours?' => 2,
        'Do you ship internationally?' => 3,
    ]);
});

it('refuses a blank chip and one past the length cap', function (string $question): void {
    // A WHITESPACE-ONLY LABEL IS A CONTROL A USER CAN SEE, CAN CLICK, AND CANNOT READ, which is why
    // `bot_starter_questions_not_blank` exists in the database. The rule here reaches the same
    // verdict through the global middleware: TrimStrings makes `"   "` empty,
    // ConvertEmptyStringsToNull makes it null, and `required` refuses it — a chain worth asserting,
    // because `min:1` (the obvious spelling) would NOT catch it: `"   "` is three characters long.
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(botStarterQuestionUrl($fixture), ['question' => $question], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('question');

    expect(starterQuestionOrder($fixture['botA']))->toHaveCount(3);
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
    'over the cap' => [str_repeat('a', BotStarterQuestionService::MAX_LENGTH + 1)],
]);

it('refuses a seventh question, because the chat surface renders at most six', function (): void {
    $fixture = botStarterQuestionFixture();

    // UP TO THE CAP, counted from the constant rather than from a literal, so raising the ceiling
    // does not silently turn this into a test of nothing.
    for ($position = 3; $position < BotStarterQuestionService::MAX_PER_BOT; $position++) {
        BotStarterQuestion::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
            ->at($position)->asking("Filler question {$position}?")->create();
    }

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE CAP MATCHES WHAT IS RENDERED, and that is the reason for it: storing a seventh would make
    // the console promise a chip no client draws, with no error anywhere.
    currentTest()->postJson(
        botStarterQuestionUrl($fixture),
        ['question' => 'One too many?'],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('question');

    assertDatabaseMissing('bot_starter_questions', ['question' => 'One too many?']);
});

// ── PATCH …/starter-questions/{starterQuestion} ──────────────────────────────────────────────────

it('edits the text without touching the order', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$fixture['second']->id}",
        ['question' => 'How do refunds work?'],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.question', 'How do refunds work?')
        ->assertJsonPath('data.sort_order', 1);

    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'Where is my order?' => 0,
        'How do refunds work?' => 1,
        'What are your opening hours?' => 2,
    ]);
});

it('writes no audit row for a PATCH naming both fields at their stored values', function (): void {
    // Both branches of the repository's edit are VALUE comparisons rather than presence tests, so
    // this body issues no UPDATE at all — and it still answers 200, because a no-op save is a
    // supported shape on this surface rather than an error. What it must not do is write a
    // `bot.starter_question.updated` row: that row is the ONLY record that a bot's suggestions
    // moved, and one describing an edit that did not happen makes "who reordered the chips" worse
    // to answer than not recording it.
    //
    // THE ROW USED TO BE WRITTEN WITH NO `changed` KEY AT ALL, which reads as an ordinary edit
    // rather than as a vacuous one: `AuditLogger::sanitize()` drops empty strings silently, so the
    // empty `implode(',', [])` never reached the payload. The defect was the row, not the detail.
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $subject = $fixture['second'];

    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$subject->id}",
        ['question' => $subject->question, 'sort_order' => $subject->sort_order],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.question', $subject->question)
        ->assertJsonPath('data.sort_order', $subject->sort_order);

    assertDatabaseMissing('audit_logs', [
        'operation' => AuditLogger::BOT_STARTER_QUESTION_UPDATED,
        'subject_id' => $subject->id,
    ]);

    // THE LIST IS UNTOUCHED, which is the other half: a re-sequence that ran and produced the same
    // order would be invisible to the assertion above and is not what happened here.
    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'Where is my order?' => 0,
        'How do I get a refund?' => 1,
        'What are your opening hours?' => 2,
    ]);

    // THE POSITIVE CONTROL. Without it, an endpoint that stopped auditing entirely passes.
    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$subject->id}",
        ['question' => 'How do refunds work?'],
        spaHeaders(),
    )->assertOk();

    expect(
        AuditLog::query()
            ->where('operation', '=', AuditLogger::BOT_STARTER_QUESTION_UPDATED)
            ->where('subject_id', '=', $subject->id)
            ->count(),
    )->toBe(1);
});

it('moves a question and re-sequences the whole list, without colliding', function (
    string $subject,
    int $target,
    array $expected,
): void {
    // THE TEST THE NON-DEFERRABLE UNIQUE INDEX EXISTS TO MAKE NECESSARY. A repository that wrote
    // `sort_order` directly would raise SQLSTATE 23505 on the first of these cases and render it as
    // a 500; one that wrote the final positions in a single pass would collide on the row it had
    // not visited yet. Both failures are invisible to a test that only moves the LAST item.
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$fixture[$subject]->id}",
        ['sort_order' => $target],
        spaHeaders(),
    )
        ->assertOk()
        // THE RESPONSE REPORTS THE NEW POSITION, which is only true because the repository refreshes
        // the row after re-sequencing it through the query builder — the in-memory model still holds
        // the old value at that point.
        ->assertJsonPath('data.sort_order', $target);

    expect(starterQuestionOrder($fixture['botA']))->toBe($expected);
})->with([
    'last to first' => ['third', 0, [
        'What are your opening hours?' => 0,
        'Where is my order?' => 1,
        'How do I get a refund?' => 2,
    ]],
    'first to last' => ['first', 2, [
        'How do I get a refund?' => 0,
        'What are your opening hours?' => 1,
        'Where is my order?' => 2,
    ]],
    'middle to first' => ['second', 0, [
        'How do I get a refund?' => 0,
        'Where is my order?' => 1,
        'What are your opening hours?' => 2,
    ]],
]);

it('refuses a body that names nothing, and a position past the end of the list', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $url = botStarterQuestionUrl($fixture)."/{$fixture['first']->id}";

    // A REQUEST THAT CHANGES NOTHING would still return 200 and would still write a
    // `bot.starter_question.updated` audit row claiming an edit happened. Refused declaratively by
    // `required_without` in both directions, which is visible to `kb:dump-form-rules`.
    currentTest()->patchJson($url, [], spaHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['question', 'sort_order']);

    // A POSITION PAST THE END IS A CONSOLE WORKING FROM A STALE READ. Appending instead would
    // return 200 for an instruction that was not carried out.
    currentTest()->patchJson($url, ['sort_order' => 3], spaHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort_order');

    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'Where is my order?' => 0,
        'How do I get a refund?' => 1,
        'What are your opening hours?' => 2,
    ]);
});

// ── DELETE …/starter-questions/{starterQuestion} ─────────────────────────────────────────────────

it('removes a question and closes the gap it leaves', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        botStarterQuestionUrl($fixture)."/{$fixture['first']->id}",
        [],
        spaHeaders(),
    )
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    // THE COMPACTION IS NOT TIDINESS: `sort_order` is published as a renderable index and
    // `BotStarterQuestionResource` promises there are no gaps, so a delete that left 1, 2 would make
    // that promise false everywhere at once.
    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'How do I get a refund?' => 0,
        'What are your opening hours?' => 1,
    ]);

    // NOT IDEMPOTENT, ON PURPOSE: a 200 for the second delete would claim this actor performed a
    // deletion the trail does not record. It 404s at BINDING time.
    currentTest()->deleteJson(
        botStarterQuestionUrl($fixture)."/{$fixture['first']->id}",
        [],
        spaHeaders(),
    )->assertStatus(404);
});

it('leaves the order alone when the LAST question is removed', function (): void {
    // THE CASE THE COMPACTION MUST NOT TOUCH. Deleting the tail leaves 0..n-2 already compact, and
    // rewriting it would move `updated_at` on every surviving row for nothing — which a console
    // polling the list would render as an edit nobody made.
    $fixture = botStarterQuestionFixture();

    $before = BotStarterQuestion::query()->withoutGlobalScopes()
        ->whereKey($fixture['first']->id)->firstOrFail()->updated_at?->toIso8601String();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        botStarterQuestionUrl($fixture)."/{$fixture['third']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    expect(starterQuestionOrder($fixture['botA']))->toBe([
        'Where is my order?' => 0,
        'How do I get a refund?' => 1,
    ]);

    $after = BotStarterQuestion::query()->withoutGlobalScopes()
        ->whereKey($fixture['first']->id)->firstOrFail()->updated_at?->toIso8601String();

    // COMPARED AS STRINGS RATHER THAN AS CARBON INSTANCES, so a null on either side is a value the
    // assertion can report rather than a TypeError inside the comparison.
    expect($after)->toBe($before, 'an untouched question was rewritten by the compaction');
});

// ── the audit trail ──────────────────────────────────────────────────────────────────────────────

it('audits the write without recording the question text', function (): void {
    // §18.11 REQUIRES BOT CONFIG CHANGES AUDITED, and a starter question is bot configuration:
    // without these rows, editing the suggestions would be the one bot configuration change that
    // left no trace anywhere, because `bots` is untouched by it and not even `bot.updated` fires.
    //
    // THE TEXT IS DELIBERATELY ABSENT, and the asymmetry with `bot.domain.*` is the point rather
    // than an inconsistency: an origin string IS a security fact, a chip label is text on a button,
    // and `AuditLogger` already refuses `welcome_message`, `placeholder_text` and `description`
    // from the `bot.*` rows on exactly that ground.
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $created = currentTest()->postJson(
        botStarterQuestionUrl($fixture),
        ['question' => 'AUDITPROBE do you deliver on Sundays?'],
        spaHeaders(),
    )->assertStatus(201);

    $questionId = (string) $created->json('data.id');

    $row = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_STARTER_QUESTION_CREATED)
        ->where('subject_id', '=', $questionId)
        ->firstOrFail();

    /** @var array<string, mixed> $details */
    $details = (array) $row->details;

    expect($details['bot_id'])->toBe($fixture['botA']->id)
        ->and($details['sort_order'])->toBe(3)
        ->and($details['question_count'])->toBe(4)
        ->and($row->actor_id)->toBe($fixture['ownerA']->id);

    // ASSERTED ON THE RAW PAYLOAD, not by checking a key is absent: a row that recorded the text
    // under some other key would satisfy `not->toHaveKey('question')` and still be the defect.
    expect(str_contains((string) json_encode($details), 'AUDITPROBE'))
        ->toBeFalse('the question text reached an append-only audit row');

    // AND THE UPDATE ROW SAYS WHICH HALF MOVED. Without `changed`, a text edit and a reorder produce
    // byte-identical rows and "who reordered the suggestions" is unanswerable from a trail that
    // recorded both.
    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$questionId}",
        ['sort_order' => 0],
        spaHeaders(),
    )->assertOk();

    /** @var array<string, mixed> $changed */
    $changed = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_STARTER_QUESTION_UPDATED)
        ->where('subject_id', '=', $questionId)
        ->firstOrFail()
        ->details;

    expect($changed['changed'])->toBe('sort_order')
        ->and($changed['sort_order'])->toBe(0);
});

it('records how many questions a deleted bot had, on the bot row itself', function (): void {
    $fixture = botStarterQuestionFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    /** @var array<string, mixed> $details */
    $details = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DELETED)
        ->where('subject_id', '=', $fixture['botA']->id)
        ->firstOrFail()
        ->details;

    // A COUNT AND NOT THE TEXT, which is the same call the summary makes about the allow-list in
    // the other direction: there the origins ARE echoed, because an origin is a grant.
    expect($details['starter_question_count'])->toBe(3);

    expect(str_contains((string) json_encode($details), 'Where is my order?'))
        ->toBeFalse('a starter question\'s text reached the bot\'s audit row');
});

// ── check 5: the organization's status ───────────────────────────────────────────────────────────

it('refuses every write while the organization is suspended, and still serves the read', function (): void {
    $fixture = botStarterQuestionFixture();

    Organization::query()->whereKey($fixture['orgA']->id)
        ->update(['status' => OrganizationStatus::Suspended->value]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->getJson(botStarterQuestionUrl($fixture), spaHeaders())->assertOk();

    currentTest()->postJson(botStarterQuestionUrl($fixture), ['question' => 'New?'], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    currentTest()->patchJson(
        botStarterQuestionUrl($fixture)."/{$fixture['first']->id}",
        ['question' => 'Renamed?'],
        spaHeaders(),
    )->assertStatus(409);

    currentTest()->deleteJson(
        botStarterQuestionUrl($fixture)."/{$fixture['first']->id}",
        [],
        spaHeaders(),
    )->assertStatus(409);

    assertDatabaseHas('bot_starter_questions', [
        'id' => $fixture['first']->id,
        'question' => 'Where is my order?',
    ]);
});

<?php

declare(strict_types=1);

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\OrgRole;
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
use App\Services\Conversations\ConversationReader;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The admin conversation-review surface (Phase 6a)
|--------------------------------------------------------------------------
|
| Two endpoints, both read-only:
|
|   GET  .../conversations                    the thread list, filterable and server-paginated
|   GET  .../conversations/{conversation}     one thread's transcript
|
| WHAT THIS FILE IS FOR AND WHAT IT IS NOT. It exercises the projection, the filters, the ordering
| and the role split. It is NOT the isolation suite: `tests/Security/ConversationAdminAccessTest.php`
| runs the two-organization canary harness, and the two are separate because a leak test that shares
| a fixture with a shape test ends up asserting the shape.
|
| TIME IS FROZEN IN `beforeEach`. Every window assertion here is a comparison against `now`, and
| `ConversationFactory` defaults `started_at` to a faker date over the last thirty days — so an
| unfrozen clock makes a "last seven days" filter admit rows at random and produces a test that
| fails one run in five for a reason nobody reproduces.
*/

beforeEach(function (): void {
    // `Carbon::setTestNow()` and not Laravel's `freezeTime()` helper, which this suite does not
    // autoload; `AuthSessionTest` and `AuditLogTest` both pin the clock this way.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One organization with one bot, a member per role, and the two provider rows a `provider_calls`
 * row needs to exist at all.
 *
 * IT IS A SINGLE ORGANIZATION ON PURPOSE, AND THAT IS SAFE HERE ONLY BECAUSE NOTHING IN THIS FILE
 * ASSERTS AN ABSENCE ACROSS TENANTS. `pest-testing` NN1 forbids a one-org fixture for an ISOLATION
 * test — "with one tenant there is nothing to leak" — and every assertion below is about what the
 * surface returns for the organization that owns the rows. The cross-tenant half lives in
 * `tests/Security/`, on `tenantPair()`.
 *
 * @return array{org: Organization, bot: Bot, connection: ProviderConnection, model: ProviderModelEntry, owner: User, admin: User, analyst: User, manager: User}
 */
function conversationFixture(): array
{
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->create();
    $connection = ProviderConnection::factory()->recycle($org)->create();
    $model = ProviderModelEntry::factory()->recycle($org)->recycle($connection)->create();

    return [
        'org' => $org,
        'bot' => $bot,
        'connection' => $connection,
        'model' => $model,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create(),
        'admin' => User::factory()->recycle($org)->orgRole(OrgRole::Admin)->create(),
        'analyst' => User::factory()->recycle($org)->orgRole(OrgRole::Analyst)->create(),
        'manager' => User::factory()->recycle($org)->orgRole(OrgRole::KnowledgeManager)->create(),
    ];
}

// ── the list ─────────────────────────────────────────────────────────────────────────────────────

it('returns one page in the fixed envelope, newest activity first', function (): void {
    $f = conversationFixture();
    $now = CarbonImmutable::now('UTC');

    // THREE THREADS WITH DISTINCT ACTIVITY INSTANTS, created in an order that is NOT the expected
    // one — otherwise "sorted by last_activity_at" and "sorted by insertion" are the same assertion
    // and the ORDER BY could be deleted.
    $middle = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create([
        'started_at' => $now->subDays(2), 'last_activity_at' => $now->subDays(2),
    ]);
    $oldest = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create([
        'started_at' => $now->subDays(9), 'last_activity_at' => $now->subDays(9),
    ]);
    $newest = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create([
        'started_at' => $now->subDay(), 'last_activity_at' => $now->subHour(),
    ]);

    $response = currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations")
        ->assertOk();

    // THE ENVELOPE IS `{"data": {"conversations": [...], "meta": {...}}}`, and the console's own
    // reader THROWS rather than degrading when it cannot read that shape — an unreadable envelope
    // is not an empty list.
    $response->assertJsonPath('data.meta.total', 3)
        ->assertJsonPath('data.meta.page', 1)
        ->assertJsonPath('data.meta.total_pages', 1)
        ->assertJsonPath('data.meta.sort', 'last_activity_at')
        ->assertJsonPath('data.meta.dir', 'desc')
        // ALWAYS NULL ON THIS ENDPOINT — there is no free-text `filter` parameter, and the key
        // stays present because `meta` is one shared component across every list in this API.
        ->assertJsonPath('data.meta.filter', null);

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('data.conversations');

    expect(array_column($rows, 'id'))->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('caps the page size and echoes what was APPLIED rather than what was asked for', function (): void {
    $f = conversationFixture();

    Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->count(3)->create();

    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?per_page=2&page=2")
        ->assertOk()
        ->assertJsonPath('data.meta.per_page', 2)
        ->assertJsonPath('data.meta.page', 2)
        ->assertJsonPath('data.meta.total', 3)
        ->assertJsonPath('data.meta.total_pages', 2)
        ->assertJsonCount(1, 'data.conversations');

    // ABOVE THE MAXIMUM IS A 422 AND NOT A SILENT CLAMP, so a client asking for more than the
    // platform serves finds out rather than paginating against a size it did not choose.
    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?per_page=101")
        ->assertStatus(422);
});

it('closes the sortable set, the status vocabulary and the channel vocabulary', function (): void {
    $f = conversationFixture();

    // A caller-chosen `sort` reaches an ORDER BY, so the set is closed per endpoint. `started_at`
    // is in it and `id` deliberately is not — `started_at` IS the creation time on this table, so
    // an `id` sort would be the same ordering under a third name.
    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?sort=started_at&dir=asc")
        ->assertOk()
        ->assertJsonPath('data.meta.sort', 'started_at')
        ->assertJsonPath('data.meta.dir', 'asc');

    foreach ([
        'sort=id',
        'sort=content',
        'status=deleted',
        'channel=carrier_pigeon',
        'bot_id=not-a-ulid',
    ] as $query) {
        expect(currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?{$query}")
            ->status())->toBe(422, "?{$query} was accepted and should not have been");
    }
});

it('filters by bot, status, channel, participant and window', function (): void {
    $f = conversationFixture();
    $other = Bot::factory()->recycle($f['org'])->create();
    $now = CarbonImmutable::now('UTC');

    $inWindow = ['started_at' => $now->subDay(), 'last_activity_at' => $now->subDay()];

    $wanted = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create($inWindow);
    $endedThread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->ended()->create($inWindow);
    $otherBot = Conversation::factory()->recycle($f['org'])->recycle($other)->create($inWindow);
    $embedded = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])
        ->channel(ConversationChannel::Embedded)->create($inWindow);
    $old = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create([
        'started_at' => $now->subDays(60), 'last_activity_at' => $now->subDays(60),
    ]);

    $ask = function (string $query) use ($f): array {
        /** @var list<array<string, mixed>> $rows */
        $rows = currentTest()->actingAs($f['owner'])
            ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?{$query}")
            ->assertOk()
            ->json('data.conversations');

        return array_column($rows, 'id');
    };

    // POSITIVE CONTROL FIRST: unfiltered, every thread is there. Without it each assertion below
    // also passes when the endpoint returns nothing at all.
    expect($ask(''))->toHaveCount(5);

    expect($ask("bot_id={$other->id}"))->toBe([$otherBot->id]);
    expect($ask('status='.ConversationStatus::Ended->value))->toBe([$endedThread->id]);
    expect($ask('channel='.ConversationChannel::Embedded->value))->toBe([$embedded->id]);
    expect($ask('session_id='.$wanted->anonymous_session_id))->toBe([$wanted->id]);

    // THE WINDOW IS HALF-OPEN AND APPLIES TO `started_at`, not to `last_activity_at`: "threads that
    // STARTED in this window" partitions the timeline exactly and "threads that were ACTIVE in it"
    // does not — a thread started in January and answered in March would belong to both months.
    // RAW-URL-ENCODED, and that is not test hygiene — it is the wire. `toIso8601String()` renders
    // the offset as `+00:00`, and a bare `+` in a query string decodes to a SPACE, which the `date`
    // rule then refuses. The 422 is correct HTTP behaviour rather than a defect, and it is worth
    // pinning here so nobody "fixes" the rule to accept a mangled instant.
    $from = rawurlencode($now->subDays(7)->toIso8601String());
    $until = rawurlencode($now->addHour()->toIso8601String());

    expect($ask("from={$from}&until={$until}"))->not->toContain($old->id);
    expect($ask("from={$from}&until={$until}"))->toHaveCount(4);

    // A BOT ID FROM ANOTHER ORGANIZATION IS AN EMPTY PAGE AND NOT AN ERROR. There is no `exists:`
    // rule on it, deliberately — an unscoped one would answer "does this id exist anywhere in the
    // platform" to any member of any organization.
    $foreign = Bot::factory()->recycle(Organization::factory()->create())->create();

    expect($ask("bot_id={$foreign->id}"))->toBe([]);
});

it('refuses an inverted window with a per-field message rather than an empty page', function (): void {
    $f = conversationFixture();
    $now = CarbonImmutable::now('UTC');

    currentTest()->actingAs($f['owner'])
        ->getJson(
            "/api/v1/organizations/{$f['org']->id}/conversations"
            .'?from='.rawurlencode($now->toIso8601String())
            .'&until='.rawurlencode($now->subDay()->toIso8601String()),
        )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['until']]);
});

it('filters by an authenticated participant, which is the subject-access query', function (): void {
    $f = conversationFixture();
    $visitor = User::factory()->recycle($f['org'])->orgRole(OrgRole::Analyst)->create();

    $theirs = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])
        ->channel(ConversationChannel::Playground)->by($visitor)->create();
    Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    /** @var list<array<string, mixed>> $rows */
    $rows = currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations?user_id={$visitor->id}")
        ->assertOk()
        ->json('data.conversations');

    expect(array_column($rows, 'id'))->toBe([$theirs->id])
        // EXACTLY ONE PARTICIPANT COLUMN IS EVER POPULATED — the database refuses both and neither
        // — and the projection publishes both keys so a client never has to guess which shape it
        // is looking at.
        ->and($rows[0]['user_id'])->toBe($visitor->id)
        ->and($rows[0]['anonymous_session_id'])->toBeNull();
});

// ── the transcript ───────────────────────────────────────────────────────────────────────────────

it('resolves each citation to the excerpt the model read, and carries the turn\'s cost and latency', function (): void {
    $f = conversationFixture();

    $thread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    $question = Message::factory()->recycle($thread)->create(['content' => 'What is the refund window?']);
    $answer = Message::factory()->recycle($thread)->assistant()->settled('Thirty days. [1]')->create();

    Citation::factory()->recycle($answer)->labelled('1')
        ->rendering('refunds.pdf', 'Refunds are accepted for 30 days.', ['page' => 4])
        ->create();

    RetrievalTrace::factory()->recycle($answer)->forOrg($f['org']->id, $f['bot']->id)->create([
        'original_query' => 'What is the refund window?',
        'rewritten_query' => 'refund window policy',
    ]);

    Feedback::factory()->recycle($answer)->negative()->commenting('Too terse.')->create();

    // TWO ATTEMPTS: a primary that failed and the fallback that answered. The second carries
    // `fallback_metadata`, which is the whole reason the column exists — a fallback row with an
    // empty object is indistinguishable from a first attempt.
    $primary = ProviderCall::factory()
        ->recycle($f['org'])->recycle($f['bot'])->recycle($f['connection'])->recycle($f['model'])
        ->forTurn($answer)->failed('provider_temporary')->create();

    $settling = ProviderCall::factory()
        ->recycle($f['org'])->recycle($f['bot'])->recycle($f['connection'])->recycle($f['model'])
        ->forTurn($answer)->fallbackFrom($primary)->create([
            'input_tokens' => 900,
            'cache_read_tokens' => 100,
            'output_tokens' => 40,
            'first_token_latency_ms' => 310,
            'total_latency_ms' => 2_400,
        ]);

    $answer->provider_call_id = $settling->id;
    $answer->save();

    $response = currentTest()->actingAs($f['analyst'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations/{$thread->id}")
        ->assertOk();

    $response->assertJsonPath('data.id', $thread->id)
        ->assertJsonPath('data.messages_truncated', false)
        ->assertJsonCount(2, 'data.messages')
        ->assertJsonPath('data.messages.0.id', $question->id)
        ->assertJsonPath('data.messages.1.id', $answer->id)
        // THE CITATION CARRIES THE EXCERPT ITSELF, not a chunk reference to resolve. `citations`
        // denormalizes it at write time precisely so a transcript survives the source being purged
        // — which is also why an Analyst holds no `sources.view`.
        ->assertJsonPath('data.messages.1.citations.0.label', '1')
        ->assertJsonPath('data.messages.1.citations.0.title', 'refunds.pdf')
        ->assertJsonPath('data.messages.1.citations.0.excerpt', 'Refunds are accepted for 30 days.')
        ->assertJsonPath('data.messages.1.citations.0.location.page', 4)
        // THE TRACE, WHICH THIS SURFACE READS BY DECISION (ADR-074) and §6.5 names verbatim.
        ->assertJsonPath('data.messages.1.retrieval_trace.original_query', 'What is the refund window?')
        ->assertJsonPath('data.messages.1.retrieval_trace.rewritten_query', 'refund window policy')
        ->assertJsonPath('data.messages.1.retrieval_trace.filters.org_id', $f['org']->id)
        ->assertJsonPath('data.messages.1.retrieval_trace.insufficient_evidence', false)
        ->assertJsonPath('data.messages.1.feedback.0.rating', 'negative')
        ->assertJsonPath('data.messages.1.feedback.0.comment', 'Too terse.')
        // PER-TURN LATENCY, TOKENS AND FALLBACK, from `provider_calls` — two attempts, oldest
        // first, and the message names the one that settled it.
        ->assertJsonCount(2, 'data.messages.1.provider_calls')
        ->assertJsonPath('data.messages.1.provider_calls.0.status', 'failed')
        ->assertJsonPath('data.messages.1.provider_calls.0.error_class', 'provider_temporary')
        ->assertJsonPath('data.messages.1.provider_calls.1.id', $settling->id)
        ->assertJsonPath('data.messages.1.provider_calls.1.input_tokens', 900)
        ->assertJsonPath('data.messages.1.provider_calls.1.total_latency_ms', 2_400)
        ->assertJsonPath('data.messages.1.provider_calls.1.fallback_metadata.from_provider_call_id', $primary->id)
        ->assertJsonPath('data.messages.1.settling_provider_call_id', $settling->id);

    // A USER TURN COSTS NOTHING AND CITES NOTHING, and the keys are present rather than absent so
    // no client has to branch on whether a message "has" the fields.
    $response->assertJsonPath('data.messages.0.citations', [])
        ->assertJsonPath('data.messages.0.provider_calls', [])
        ->assertJsonPath('data.messages.0.feedback', [])
        ->assertJsonPath('data.messages.0.retrieval_trace', null)
        ->assertJsonPath('data.messages.0.settling_provider_call_id', null);
});

it('includes turns that are still in flight, which the public transcript deliberately hides', function (): void {
    $f = conversationFixture();

    $thread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    Message::factory()->recycle($thread)->create();
    $pending = Message::factory()->recycle($thread)->assistant()->create();

    // THE DIFFERENCE FROM `rt/v1`'s TRANSCRIPT, ASSERTED. A `pending` row would render as a blank
    // bubble that never fills for a visitor polling their own thread, so the runtime filters it
    // out. "Which turns are still open" is an operational question with a partial index behind it,
    // and a turn that died mid-stream is exactly what an operator opens this screen to find.
    currentTest()->actingAs($f['admin'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations/{$thread->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.messages')
        ->assertJsonPath('data.messages.1.id', $pending->id)
        ->assertJsonPath('data.messages.1.status', 'pending')
        // NULL AND NOT AN EMPTY STRING: `messages_content_not_blank` refuses `''`, so there is one
        // spelling of "this turn has produced no text".
        ->assertJsonPath('data.messages.1.content', null);
});

it('bounds the transcript and says so, rather than ending mid-argument in silence', function (): void {
    $f = conversationFixture();

    $thread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    Message::factory()->recycle($thread)->count(ConversationReader::MAX_MESSAGES + 3)->create();

    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations/{$thread->id}")
        ->assertOk()
        ->assertJsonCount(ConversationReader::MAX_MESSAGES, 'data.messages')
        // THE FLAG IS THE POINT. Without it a reviewer sees the thread stop and concludes the
        // conversation stopped there, on a surface whose whole job is answering "what did we
        // actually tell this customer".
        ->assertJsonPath('data.messages_truncated', true);
});

it('404s an unknown conversation at binding time', function (): void {
    $f = conversationFixture();

    currentTest()->actingAs($f['owner'])
        ->getJson("/api/v1/organizations/{$f['org']->id}/conversations/01jqzzzzzzzzzzzzzzzzzzzzzz")
        ->assertStatus(404);
});

// ── the role split ───────────────────────────────────────────────────────────────────────────────

it('serves the three roles that hold conversations.view and refuses the one that does not', function (): void {
    $f = conversationFixture();
    $thread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    $list = "/api/v1/organizations/{$f['org']->id}/conversations";
    $transcript = "{$list}/{$thread->id}";

    foreach (['owner', 'admin', 'analyst'] as $role) {
        // `expect(...)->toBe(200, ...)` and not `assertOk($message)`: TestResponse's assertions
        // take no message argument, so the name of the role that failed has to come from an
        // expectation rather than from the assertion — and without it a failure here says only
        // "expected 200, got 403" for one of three roles.
        expect(currentTest()->actingAs($f[$role])->getJson($list)->status())
            ->toBe(200, "{$role} was refused the conversation list");
        expect(currentTest()->actingAs($f[$role])->getJson($transcript)->status())
            ->toBe(200, "{$role} was refused the transcript");
    }

    // THE KNOWLEDGE MANAGER IS REFUSED, AND IT IS A DECISION RATHER THAN AN OVERSIGHT. §6.4 is a
    // six-item list and every item is a source operation; "review parsed content" is the source
    // detail projection that `sources.view` already serves. A transcript is verbatim end-user text.
    //
    // 403 AND NOT 404: the admin API is authenticated and is not enumeration-sensitive — a member
    // of the organization is already entitled to know the record is there. The 404 deny belongs to
    // the public runtime and SDK surfaces.
    currentTest()->actingAs($f['manager'])->getJson($list)
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    currentTest()->actingAs($f['manager'])->getJson($transcript)
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('401s a guest on both endpoints', function (): void {
    $f = conversationFixture();
    $thread = Conversation::factory()->recycle($f['org'])->recycle($f['bot'])->create();

    currentTest()->getJson("/api/v1/organizations/{$f['org']->id}/conversations")->assertStatus(401);
    currentTest()->getJson("/api/v1/organizations/{$f['org']->id}/conversations/{$thread->id}")->assertStatus(401);
});

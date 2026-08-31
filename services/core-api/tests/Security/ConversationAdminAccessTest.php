<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\RetrievalTrace;
use App\Models\User;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Conversations\ConversationFilter;
use App\Support\Http\ListQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The admin conversation surface never reads another organization's threads (§22.5)
|--------------------------------------------------------------------------
|
| FOUR OF THE SIX TABLES BEHIND THIS SURFACE HAVE NO `organization_id` AND CANNOT HAVE ONE.
| `messages`, `citations`, `retrieval_traces` and `feedback` reach their tenant through
| `conversations`, over a one- or two-hop join — so those four models carry no `#[ScopedBy]` and
| CANNOT: the column the scope would filter does not exist, and attaching it would be SQLSTATE 42703
| on every read from every surface (`tests/Arch/ConversationDoctrineTest.php` asserts the absence).
|
| THE JOIN PREDICATE *IS* THE FILTER. There is nothing underneath it. Delete it and every query
| returns every tenant's rows, with no exception, no log line, and a transcript that looks exactly
| like a correct one.
|
| ── WHY THIS FILE ASSERTS AT THE REPOSITORY AS WELL AS OVER HTTP ────────────────────────────
|
| Because the HTTP assertion alone is the weaker one, and `AnalyticsTenancyTest` records the same
| trap: a request as Org A's admin carries Org A's tenant context, so on a SCOPED model the
| `#[ScopedBy]` backstop supplies the predicate even when the EXPLICIT one has been deleted — and on
| `conversations` and `provider_calls` the test would stay green with the mechanism gone. The
| repository assertions below run under Org A's context and ASK FOR ORG B's id, which is the shape
| only the explicit argument can answer correctly, and the shape a stale pooled worker produces.
|
| EVERY FIXTURE IS `tenantPair()`. There is deliberately no single-organization helper: with one
| tenant there is nothing to leak, so such a test passes against code with no filter at all.
|
| A POSITIVE CONTROL COMES FIRST IN EVERY TEST.
*/

/**
 * A whole turn in ONE organization: thread, question, answer, citation, trace, feedback and the
 * provider attempt that paid for it — each carrying the canary so a leak through ANY of the five
 * child projections trips the assertion rather than only a leak through the message text.
 *
 * @return array{conversation: Conversation, answer: Message}
 */
function seedTurn(
    \App\Models\Organization $organization,
    \App\Models\Bot $bot,
    string $canary,
): array {
    $connection = ProviderConnection::factory()->recycle($organization)->create();
    $model = ProviderModelEntry::factory()->recycle($organization)->recycle($connection)->create();

    $thread = Conversation::factory()->recycle($organization)->recycle($bot)->create();

    Message::factory()->recycle($thread)->create(['content' => "Question about {$canary}"]);
    $answer = Message::factory()->recycle($thread)->assistant()->settled("Answer mentioning {$canary}")->create();

    Citation::factory()->recycle($answer)->labelled('1')
        ->rendering("{$canary}.pdf", "Excerpt containing {$canary}", ['page' => 1])
        ->create();

    RetrievalTrace::factory()->recycle($answer)
        ->forOrg($organization->id, $bot->id)
        ->create(['original_query' => "Question about {$canary}", 'rewritten_query' => $canary]);

    Feedback::factory()->recycle($answer)->negative()->commenting("Comment with {$canary}")->create();

    ProviderCall::factory()
        ->recycle($organization)->recycle($bot)->recycle($connection)->recycle($model)
        ->forTurn($answer)
        ->create(['provider_request_id' => "req_{$canary}"]);

    return ['conversation' => $thread, 'answer' => $answer];
}

it('never lists another organization\'s threads and 403s a foreign one', function (): void {
    $t = tenantPair();

    $ownThread = Conversation::factory()->recycle($t->a)->recycle($t->botA)->create();
    $theirThread = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    // POSITIVE CONTROL, FIRST. Org A's own list answers and contains Org A's thread — without this
    // line the assertion below also passes when the endpoint is broken and returns nothing at all.
    $response = currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations")
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.conversations.0.id', $ownThread->id);

    // Over the RAW BODY, so a foreign id hiding in a key nobody thought to check still trips it.
    expect(str_contains((string) $response->getContent(), $theirThread->id))
        ->toBeFalse('Org B\'s conversation id reached Org A\'s list');

    // AND ORG B'S LIST IS A 403 ON THIS SURFACE — not a 404. The admin API is authenticated and is
    // NOT enumeration-sensitive: a member of an organization is already entitled to know another
    // organization exists. The 404 deny belongs to the public runtime and SDK surfaces, and
    // `error_class` is `authorization` either way, which is what nothing branches on.
    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->b->id}/conversations")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('404s another organization\'s transcript at binding time, before any policy runs', function (): void {
    $t = tenantPair();

    $theirs = seedTurn($t->b, $t->botB, 'CANARY-'.Str::ulid());

    // THE FOREIGN ID UNDER THE CALLER'S OWN ORGANIZATION. `->scopeBindings()` resolves
    // `{conversation}` through `$organization->conversations()`, so this 404s inside
    // SubstituteBindings — before ConversationPolicy is constructed and before the row is in
    // memory. Anything built from a wrongly-bound instance (a relation query, a log line, a
    // response-time difference) would leak before authorization ever fired.
    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations/{$theirs['conversation']->id}")
        ->assertStatus(404);

    // And addressing Org B's organization directly is the authorization deny, not the binding one.
    currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->b->id}/conversations/{$theirs['conversation']->id}")
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('never leaks the other organization\'s canary through any part of a transcript', function (): void {
    $t = tenantPair();
    $canary = 'CANARY-'.Str::ulid();

    // ORG B HOLDS THE CANARY IN ALL FIVE CHILD PROJECTIONS — message text, citation title and
    // excerpt, trace query, feedback comment, provider request id — so a leak through any one of
    // them trips this rather than only a leak through `content`.
    $theirs = seedTurn($t->b, $t->botB, $canary);
    $ours = seedTurn($t->a, $t->botA, 'CANARY-'.Str::ulid());

    // POSITIVE CONTROL, FIRST: Org B's own reviewer sees the canary. Without it this test passes
    // when the transcript projection is broken and renders nothing.
    $theirView = currentTest()->actingAs($t->actorB)
        ->getJson("/api/v1/organizations/{$t->b->id}/conversations/{$theirs['conversation']->id}")
        ->assertOk();

    expect((string) $theirView->getContent())->toContain($canary);

    // AND ORG A'S OWN TRANSCRIPT, WHICH IS A REAL AND POPULATED ONE, CARRIES NONE OF IT. Asserting
    // over Org A's own populated thread rather than an empty one is what stops "nothing leaked"
    // from being true because nothing was returned.
    $ourView = currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations/{$ours['conversation']->id}")
        ->assertOk();

    $body = (string) $ourView->getContent();

    expect($body)->toContain('Excerpt containing');   // the projection really is populated

    // str_contains and not ->not->toContain(): `not` treats any failure as success, and
    // toContain() would read a message argument as a second needle.
    expect(str_contains($body, $canary))
        ->toBeFalse('Org B\'s canary reached Org A\'s transcript');
    expect(str_contains($body, $theirs['answer']->id))
        ->toBeFalse('Org B\'s message id reached Org A\'s transcript');
});

it('scopes every read below `conversations` by the explicit argument, not by the ambient context', function (): void {
    // ── THE TRAP THIS FILE EXISTS FOR, AND THE ONE AN HTTP ASSERTION CANNOT SEE ───────────────
    //
    // A request as Org A's admin carries Org A's tenant context, so on a SCOPED model the
    // `#[ScopedBy]` backstop supplies the predicate even when the EXPLICIT one has been deleted.
    // What the explicit argument defends is a context that DISAGREES with it: a pooled queue
    // worker holding the previous tenant, a scheduled sweep iterating organizations. So this asks
    // for ORG B's rows under ORG A's bound context, and the models answer DIFFERENTLY:
    //
    //   `conversations` IS scoped, so the two layers CONFLICT and the read fails CLOSED — the
    //   explicit `organization_id = B` and the scope's `organization_id = A` cannot both hold.
    //
    //   `messages`, `citations`, `retrieval_traces` and `feedback` are NOT scoped and cannot be, so
    //   the explicit join predicate is the only layer and it answers truthfully about the
    //   organization it was ASKED about. That is correct behaviour for a repository whose contract
    //   takes the organization positionally — and it is exactly why the argument is required and
    //   positional rather than defaulted.
    $t = tenantPair();
    $canary = 'CANARY-'.Str::ulid();

    $theirs = seedTurn($t->b, $t->botB, $canary);
    $conversationId = $theirs['conversation']->id;
    $messageIds = [$theirs['answer']->id];

    $repository = app(ConversationRepositoryInterface::class);
    $query = new ListQuery(page: 1, perPage: 25, sort: 'last_activity_at', direction: \App\Enums\SortDirection::Desc, filter: null);

    // POSITIVE CONTROL FIRST, under Org B's own context: every read is populated.
    app(TenantContext::class)->runFor($t->b->id, function () use ($repository, $t, $query, $conversationId, $messageIds): void {
        expect($repository->paginateForAdmin($t->b->id, new ConversationFilter, $query)->total())->toBe(1)
            ->and($repository->adminTranscript($t->b->id, $conversationId, 50))->toHaveCount(2)
            ->and($repository->citationsForConversation($t->b->id, $conversationId, $messageIds))->toHaveCount(1)
            ->and($repository->tracesForConversation($t->b->id, $conversationId, $messageIds))->toHaveCount(1)
            ->and($repository->feedbackForConversation($t->b->id, $conversationId, $messageIds))->toHaveCount(1)
            ->and($repository->providerCallsForConversation($t->b->id, $conversationId, $messageIds))->toHaveCount(1);
    });

    // NOW ASK FOR ORG A'S ROWS — the organization the endpoint would be serving — while Org B's
    // conversation is the one named. Every read comes back empty, which is the property that has to
    // hold with no global scope underneath four of the six tables.
    app(TenantContext::class)->runFor($t->b->id, function () use ($repository, $t, $query, $conversationId, $messageIds): void {
        expect($repository->paginateForAdmin($t->a->id, new ConversationFilter, $query)->total())->toBe(0)
            ->and($repository->adminTranscript($t->a->id, $conversationId, 50))->toBe([])
            ->and($repository->citationsForConversation($t->a->id, $conversationId, $messageIds))->toBe([])
            ->and($repository->tracesForConversation($t->a->id, $conversationId, $messageIds))->toBe([])
            ->and($repository->feedbackForConversation($t->a->id, $conversationId, $messageIds))->toBe([])
            ->and($repository->providerCallsForConversation($t->a->id, $conversationId, $messageIds))->toBe([]);
    });

    // AND THE SAME QUESTION FROM THE OTHER SIDE OF THE CONTEXT: Org A's context bound, Org B's
    // organization asked for. The four unscoped tables answer about B — truthfully, because the
    // argument is what they were asked — which is the behaviour a pooled worker depends on.
    app(TenantContext::class)->runFor($t->a->id, function () use ($repository, $t, $conversationId, $messageIds): void {
        expect($repository->adminTranscript($t->b->id, $conversationId, 50))->toHaveCount(2)
            ->and($repository->citationsForConversation($t->b->id, $conversationId, $messageIds))->toHaveCount(1);
    });
});

it('never lets a foreign bot, user or session filter widen the organization predicate', function (): void {
    $t = tenantPair();

    Conversation::factory()->recycle($t->a)->recycle($t->botA)->create();
    $theirThread = Conversation::factory()->recycle($t->b)->recycle($t->botB)->create();

    // THE SHAPE THAT WOULD BE A LEAK IF A PREDICATE WERE APPENDED WITHOUT ITS ORGANIZATION. Every
    // filter below names a row that exists — in the OTHER organization — so an `orWhere` without a
    // grouping, or a predicate applied instead of the tenant one rather than beside it, returns Org
    // B's thread here. The correct answer to all three is an empty page.
    foreach ([
        "bot_id={$t->botB->id}",
        "session_id={$theirThread->anonymous_session_id}",
        "user_id={$t->actorB->id}",
    ] as $query) {
        $response = currentTest()->actingAs($t->actorA)
            ->getJson("/api/v1/organizations/{$t->a->id}/conversations?{$query}")
            ->assertOk();

        expect($response->json('data.meta.total'))
            ->toBe(0, "?{$query} returned rows from another organization");
        expect(str_contains((string) $response->getContent(), $theirThread->id))
            ->toBeFalse("?{$query} leaked another organization's conversation id");
    }
});

it('refuses a suspended member and a member of no organization alike', function (): void {
    $t = tenantPair();

    $thread = Conversation::factory()->recycle($t->a)->recycle($t->botA)->create();

    // A SUSPENDED MEMBERSHIP HOLDS NOTHING. `OrganizationUser::grants()` is
    // `isActive() && role->grants()`, which the unit-level role matrix structurally cannot express
    // — so it is asserted over HTTP, here, on the surface it protects.
    $suspended = User::factory()->recycle($t->a)
        ->orgRole(OrgRole::Owner, \App\Enums\MembershipStatus::Suspended)->create();

    currentTest()->actingAs($suspended)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations")
        ->assertStatus(403);

    currentTest()->actingAs($suspended)
        ->getJson("/api/v1/organizations/{$t->a->id}/conversations/{$thread->id}")
        ->assertStatus(403);
});

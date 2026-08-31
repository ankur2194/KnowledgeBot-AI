<?php

declare(strict_types=1);

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §22.5 — cross-tenant and cross-VISITOR access on the public chat runtime
|--------------------------------------------------------------------------
|
| TWO SCOPES, AND THE SECOND IS THE ONE AN ORDINARY USER CAN BREACH. The organization scope stops a
| cross-tenant read; the PARTICIPANT scope stops one visitor reading another visitor's transcript
| inside the same tenant, which needs nothing but a changed ULID in a URL. An organization-only check
| passes every cross-tenant test in this file and leaks every conversation in the product.
|
| Every case here uses a two-organization fixture. A one-organization fixture cannot fail an
| isolation test — there is nothing to leak, so it passes against code with no filter at all — which
| is why `tenantPair()` exists and why there is deliberately no single-org helper.
|
| Every case asserts the POSITIVE CONTROL FIRST. Without it the suite goes green the moment the
| surface breaks and returns nothing at all, which is how an isolation suite becomes decoration.
*/

beforeEach(function (): void {
    relaxPublicSurfaceLimiters();
});

it('never serves one tenant\'s conversation to another tenant\'s session', function (): void {
    $a = chatFixture(origin: 'https://a.example');
    $b = chatFixture(origin: 'https://b.example');

    $tokenA = chatSessionToken($a);
    $tokenB = chatSessionToken($b);

    $conversationB = (string) currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($tokenB))
        ->assertStatus(201)
        ->json('data.id');

    // POSITIVE CONTROL. Org B's own session can read it — so a failure below is a filter doing its
    // job rather than the endpoint being broken.
    currentTest()->getJson("/rt/v1/conversations/{$conversationB}/messages", chatHeaders($tokenB))->assertOk();

    // THE REAL ASSERTION, and the body must be indistinguishable from a conversation that never
    // existed: a 403 would confirm the row is there.
    expect(currentTest()->getJson("/rt/v1/conversations/{$conversationB}/messages", chatHeaders($tokenA)))
        ->toDenyAsNotFound('rt/v1');

    expect(currentTest()->postJson("/rt/v1/conversations/{$conversationB}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Whose conversation is this?',
    ], chatHeaders($tokenA, 'text/event-stream')))->toDenyAsNotFound('rt/v1');
});

it('never serves one VISITOR\'s conversation to another visitor of the SAME tenant', function (): void {
    // THE CHEAPER ATTACK, AND THE ONE AN ORGANIZATION-ONLY CHECK MISSES ENTIRELY. Two sessions on one
    // bot: both are legitimate, both pass every tenant predicate, and only the participant scope
    // keeps them apart.
    $fixture = chatFixture();

    $first = chatSessionToken($fixture);
    $second = chatSessionToken($fixture);

    expect($first)->not->toBe($second, 'two mints produced the same token, so this test proves nothing');

    $conversation = (string) currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($first))
        ->assertStatus(201)
        ->json('data.id');

    currentTest()->getJson("/rt/v1/conversations/{$conversation}/messages", chatHeaders($first))->assertOk();

    expect(currentTest()->getJson("/rt/v1/conversations/{$conversation}/messages", chatHeaders($second)))
        ->toDenyAsNotFound('rt/v1');
});

it('never serves one tenant\'s citations — the one surface that returns source text — to another', function (): void {
    $a = chatFixture(origin: 'https://a.example');
    $b = chatFixture(origin: 'https://b.example');

    $canary = 'CANARY-'.Str::ulid();

    $tokenA = chatSessionToken($a);
    $tokenB = chatSessionToken($b);

    // READ INSIDE ORG B'S CONTEXT. `Conversation` is org-scoped and a test is outside the request
    // that bound one, so an unbound read answers null for a row that plainly exists.
    $conversationB = asTenant($b->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey(currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($tokenB))->json('data.id'))
        ->firstOrFail());

    $answer = new Message;
    $answer->id = (string) Str::ulid();
    $answer->conversation_id = $conversationB->id;
    $answer->role = MessageRole::Assistant;
    $answer->content = 'Refunds are accepted. [S1]';
    $answer->status = MessageStatus::Complete;
    $answer->save();

    $citation = new Citation;
    $citation->message_id = $answer->id;
    $citation->chunk_id = $b->chunkId;
    $citation->label = '1';
    $citation->display_title = 'Refund policy';
    $citation->location_metadata = ['page' => 1];
    // THE CANARY IS IN THE EXCERPT, which is where tenant SOURCE TEXT actually lives on this surface.
    // Planting it in a title would make the assertion pass against a leak of the excerpt.
    $citation->excerpt = "Refunds are accepted for 30 days. {$canary}";
    $citation->save();

    // POSITIVE CONTROL: the canary IS returned to the tenant that planted it.
    $mine = currentTest()->getJson("/rt/v1/messages/{$answer->id}/citations", chatHeaders($tokenB));

    expect((string) $mine->assertOk()->getContent())->toContain($canary);

    // AND IS ABSENT FROM THE OTHER TENANT'S RESPONSE, body and all.
    $theirs = currentTest()->getJson("/rt/v1/messages/{$answer->id}/citations", chatHeaders($tokenA));

    expect($theirs)->toDenyAsNotFound('rt/v1')
        ->and((string) $theirs->getContent())->not->toContain($canary);
});

it('never lets one tenant\'s session rate an answer in another tenant', function (): void {
    $a = chatFixture(origin: 'https://a.example');
    $b = chatFixture(origin: 'https://b.example');

    $tokenA = chatSessionToken($a);
    $tokenB = chatSessionToken($b);

    // READ INSIDE ORG B'S CONTEXT. `Conversation` is org-scoped and a test is outside the request
    // that bound one, so an unbound read answers null for a row that plainly exists.
    $conversationB = asTenant($b->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey(currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($tokenB))->json('data.id'))
        ->firstOrFail());

    $answer = new Message;
    $answer->id = (string) Str::ulid();
    $answer->conversation_id = $conversationB->id;
    $answer->role = MessageRole::Assistant;
    $answer->content = 'Refunds are accepted for 30 days.';
    $answer->status = MessageStatus::Complete;
    $answer->save();

    // POSITIVE CONTROL.
    currentTest()->postJson("/rt/v1/messages/{$answer->id}/feedback", ['rating' => 'positive'], chatHeaders($tokenB))
        ->assertOk();

    expect(currentTest()->postJson("/rt/v1/messages/{$answer->id}/feedback", ['rating' => 'negative'], chatHeaders($tokenA)))
        ->toDenyAsNotFound('rt/v1');

    // AND THE VERDICT DID NOT MOVE. A 404 that had already written the row would be the worst of
    // both: the attacker is told nothing and the metric changes anyway.
    expect(\App\Models\Feedback::query()->where('message_id', '=', $answer->id)->count())->toBe(1);
});

it('resolves a bot\'s retrieval scope from its OWN organization\'s active versions only', function (): void {
    // NON-NEGOTIABLE 2's FOURTH FILTER TERM, asserted at the layer that computes it. If this widened
    // past the organization, every downstream layer would agree with it — the snapshot would simply
    // say the other tenant's versions are searchable.
    $a = chatFixture(origin: 'https://a.example');
    $b = chatFixture(origin: 'https://b.example');

    $scopes = app(\App\Repositories\Contracts\RetrievalScopeRepositoryInterface::class);

    $now = now('UTC')->toIso8601String();

    // THE REPOSITORY TAKES THE ORGANIZATION EXPLICITLY *AND* RELIES ON THE GLOBAL SCOPE AS A
    // BACKSTOP — that is the two-layer design, and it means a call from outside a request needs the
    // context bound. Binding ORG A's for both calls below is deliberate: the second one asks for
    // ORG B's bot under ORG A's scope and must resolve nothing.
    $resolvedA = asTenant($a->organization->id, fn () => $scopes->forBot($a->organization->id, (string) $a->bot->id, $now));
    $resolvedB = asTenant($b->organization->id, fn () => $scopes->forBot($b->organization->id, (string) $b->bot->id, $now));

    // POSITIVE CONTROL ON BOTH SIDES: each resolves its own, so an empty result below would be a
    // broken query rather than a filter.
    expect($resolvedA->versionIds)->toBe($a->versionIds)
        ->and($resolvedB->versionIds)->toBe($b->versionIds)
        ->and(array_intersect($resolvedA->versionIds, $resolvedB->versionIds))->toBe([]);

    // AND THE ORGANIZATION ARGUMENT IS THE MECHANISM, not the bot id: asking for B's bot under A's
    // organization must resolve NOTHING rather than quietly returning B's versions.
    expect(asTenant($a->organization->id, fn () => $scopes->forBot($a->organization->id, (string) $b->bot->id, $now))->versionIds)
        ->toBe([]);
});

it('excludes a retired version from the scope, so a superseded answer cannot be cited', function (): void {
    // NON-NEGOTIABLE 5's READ SIDE. `retired_at` is what stops a replaced version answering beside
    // the version that replaced it, and a scope query missing that predicate is not an error
    // anywhere — it is a well-formed citation from a document the tenant thought they had replaced.
    $fixture = chatFixture();
    $scopes = app(\App\Repositories\Contracts\RetrievalScopeRepositoryInterface::class);
    $now = now('UTC')->toIso8601String();

    $resolve = fn (): array => asTenant(
        $fixture->organization->id,
        fn () => $scopes->forBot($fixture->organization->id, (string) $fixture->bot->id, $now),
    )->versionIds;

    // POSITIVE CONTROL FIRST. Without it the assertion below passes when the query is simply broken.
    expect($resolve())->toBe($fixture->versionIds);

    \Illuminate\Support\Facades\DB::table('source_versions')
        ->whereIn('id', $fixture->versionIds)
        ->update(['retired_at' => now('UTC')]);

    expect($resolve())->toBe([]);
});

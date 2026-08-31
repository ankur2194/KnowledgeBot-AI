<?php

declare(strict_types=1);

use App\Enums\ConversationChannel;
use App\Enums\FeedbackRating;
use App\Enums\MessageRole;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| rt/v1 — the five non-streaming endpoints
|--------------------------------------------------------------------------
|
| The relay has its own file (ChatStreamRelayTest), because it needs a real socket. What is here is
| the surface around it: the public bot projection, conversation creation, the permitted history,
| feedback and citation detail — plus the authentication split, which is the one place this surface
| deliberately does NOT answer 404.
*/

beforeEach(function (): void {
    // The read/write throttles keep their counters in Valkey and nothing resets them between tests.
    // `relaxPublicSurfaceLimiters()` says at length why this is the framework's own supported way to
    // opt out, and which test must therefore NOT call it.
    relaxPublicSurfaceLimiters();
});

it('serves the public bot projection and nothing about its configuration', function (): void {
    $scenario = chatScenario();
    $response = currentTest()->getJson('/rt/v1/bot', chatHeaders($scenario->token));

    $response->assertOk()->assertJsonPath('data.public_bot_id', $scenario->fixture->bot->public_bot_id);

    $body = (string) $response->getContent();

    // NOT THE INTERNAL ULID, NOT THE CONNECTION, NOT THE MODEL. `BotResource` — the admin projection
    // — carries all three, and the tempting economy of one resource with a per-field `when($isAdmin)`
    // is what this separation refuses: it puts the decision on every field rather than on the class,
    // so the next field added is public by default and nothing says so.
    foreach ([
        (string) $scenario->fixture->bot->id,
        (string) $scenario->fixture->connection->id,
        (string) $scenario->fixture->model->id,
        (string) $scenario->fixture->model->model,
    ] as $internal) {
        expect(str_contains($body, $internal))->toBeFalse(
            'the public bot projection published an internal identifier',
        );
    }
});

it('answers a missing or malformed bearer with 401 authentication, not 404', function (?string $bearer): void {
    chatScenario();

    // THE ONE DELIBERATE EXCEPTION TO THE 404 RULE ON THIS SURFACE. A missing, malformed or expired
    // bearer is `authentication`, and the widget's refresh flow triggers on exactly that pair and on
    // nothing else — rendering it as a 404 leaves a live visitor with a dead composer and no
    // re-mint. What must never be 401 is a VALID bearer against a resource it does not own.
    $headers = $bearer === null ? ['Accept' => 'application/json'] : chatHeaders($bearer);

    currentTest()->getJson('/rt/v1/bot', $headers)
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication')
        ->assertJsonPath('retryable', false);
})->with([
    'no Authorization header at all' => null,
    'a bearer with the wrong prefix' => 'Bearer-ish-nonsense',
    'the right prefix and nothing else' => 'kbw_',
    'segments that are not ULIDs' => 'kbw_1.2.3',
    'a well-shaped token nobody minted' => 'kbw_01JKB000000000000000000001.01JKB000000000000000000002.fabricated',
]);

it('opens a conversation scoped to the session, with no field the client could have chosen', function (): void {
    $scenario = chatScenario();
    $response = currentTest()->postJson('/rt/v1/conversations', ['locale' => 'en-GB'], chatHeaders($scenario->token));

    $response->assertStatus(201)->assertJsonPath('data.locale', 'en-GB');

    // READ INSIDE THE TENANT CONTEXT. `Conversation` is org-scoped and a test is outside the request
    // that bound one, so an unbound read answers null for a row that plainly exists.
    $conversation = asTenant($scenario->fixture->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey($response->json('data.id'))
        ->firstOrFail());

    expect($conversation->organization_id)->toBe($scenario->fixture->organization->id)
        ->and($conversation->bot_id)->toBe($scenario->fixture->bot->id)
        // `embedded`, RESOLVED FROM THE MINT ORIGIN AND NOT FROM THE BODY. `playground` is the
        // channel whose actor type unlocks `retrieval.trace` on the relay, so a body field naming a
        // channel would be an escalation rather than configuration.
        ->and($conversation->channel)->toBe(ConversationChannel::Embedded)
        // EXACTLY ONE PARTICIPANT — `conversations_participant_exclusive`. "Both" is reachable for a
        // signed-in visitor on hosted chat and is the state that double-counts unique sessions.
        ->and($conversation->user_id)->toBeNull()
        ->and($conversation->anonymous_session_id)->not->toBeNull();

    // NEITHER PARTICIPANT COLUMN IS PUBLISHED. The session id is derived from a live bearer, and a
    // customer's own analytics scrapes response bodies off the DOM.
    expect((string) $response->getContent())
        ->not->toContain((string) $conversation->anonymous_session_id);
});

it('refuses a body field the contract does not define', function (): void {
    $scenario = chatScenario();
    // Laravel IGNORES unknown keys, so without the explicit check a client that renamed a field would
    // have it silently dropped and would see a plausible answer to a different question. This is the
    // counterpart of the far side's `extra="forbid"`.
    $conversation = $scenario->conversation;

    currentTest()->postJson("/rt/v1/conversations/{$conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
        'text' => 'the token frame\'s field name, which is not an alias',
    ], chatHeaders($scenario->token, 'text/event-stream'))
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['text']]);
});

it('refuses `text` in place of `content`, naming the field a client can render against', function (): void {
    $scenario = chatScenario();
    // A client that posts `text` gets a 422 on EVERY send — a total outage for that one client,
    // invisible to the other two. The `errors` map is what makes it renderable rather than opaque.
    $conversation = $scenario->conversation;

    currentTest()->postJson("/rt/v1/conversations/{$conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'text' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['content', 'text']]);
});

it('returns the settled transcript and omits turns still in flight', function (): void {
    $scenario = chatScenario();
    $conversation = asTenant($scenario->fixture->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey($scenario->conversation)
        ->firstOrFail());

    $user = writeMessageRow($conversation, MessageRole::User, 'Do you refund after 30 days?', 'complete');
    $answer = writeMessageRow($conversation, MessageRole::Assistant, 'Refunds are accepted for 30 days.', 'complete');
    $inFlight = writeMessageRow($conversation, MessageRole::Assistant, null, 'pending');

    $response = currentTest()->getJson("/rt/v1/conversations/{$conversation->id}/messages", chatHeaders($scenario->token));

    $ids = array_column((array) $response->assertOk()->json('data.messages'), 'id');

    expect($ids)->toBe([$user, $answer])
        // A `pending` row has no content and would render as a bubble that never fills, because this
        // endpoint is a poll and not a stream.
        ->and($ids)->not->toContain($inFlight);

    // NO COST DATA AND NO RETRY GRAPH. `provider_call_id` is the join key to token counts, latencies
    // and the vendor's own request id; `parent_message_id` says how many times the platform tried.
    $entry = $response->json('data.messages.0');

    expect(array_keys((array) $entry))
        ->toEqualCanonicalizing(['id', 'role', 'content', 'status', 'created_at']);
});

it('returns the evidence behind one answer, hydrated from this tenant\'s own chunks', function (): void {
    $scenario = chatScenario();
    $conversation = asTenant($scenario->fixture->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey($scenario->conversation)
        ->firstOrFail());
    $answer = writeMessageRow($conversation, MessageRole::Assistant, 'Refunds are accepted. [S1]', 'complete');

    $citation = new Citation;
    $citation->message_id = $answer;
    $citation->chunk_id = $scenario->fixture->chunkId;
    $citation->label = '1';
    $citation->display_title = 'Refund policy';
    $citation->location_metadata = ['page' => 1];
    $citation->excerpt = 'Refunds are accepted for 30 days.';
    $citation->save();

    currentTest()->getJson("/rt/v1/messages/{$answer}/citations", chatHeaders($scenario->token))
        ->assertOk()
        ->assertJsonPath('data.citations.0.label', '1')
        ->assertJsonPath('data.citations.0.excerpt', 'Refunds are accepted for 30 days.')
        ->assertJsonPath('data.citations.0.location.page', 1)
        // `source_id` IS NOT PUBLISHED. It is an admin-surface identifier and would let a visitor
        // correlate answers across conversations to enumerate a tenant's corpus.
        ->assertJsonMissingPath('data.citations.0.source_id');
});

it('records one verdict per submitter and replaces it rather than appending', function (): void {
    $scenario = chatScenario();
    $conversation = asTenant($scenario->fixture->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey($scenario->conversation)
        ->firstOrFail());
    $answer = writeMessageRow($conversation, MessageRole::Assistant, 'Refunds are accepted.', 'complete');

    currentTest()->postJson("/rt/v1/messages/{$answer}/feedback", [
        'rating' => 'negative',
        'comment' => 'That is not what the policy says.',
    ], chatHeaders($scenario->token))->assertOk()->assertJsonPath('data.acknowledged', true);

    currentTest()->postJson("/rt/v1/messages/{$answer}/feedback", [
        'rating' => 'positive',
    ], chatHeaders($scenario->token))->assertOk();

    $rows = Feedback::query()->where('message_id', '=', $answer)->get();   // no org scope: chained

    // ONE ROW. Appending would let one visitor move the satisfaction metric by clicking repeatedly,
    // which is the cheapest possible way to make an analytics panel useless.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->rating)->toBe(FeedbackRating::Positive)
        // An empty comment box CLEARS the previous comment rather than storing a second spelling of
        // "none" — `feedback_comment_not_blank` refuses `''` in the database.
        ->and($rows->first()?->comment)->toBeNull();
});

it('refuses feedback on the visitor\'s own question with the same 404 as an unknown id', function (): void {
    $scenario = chatScenario();
    $conversation = asTenant($scenario->fixture->organization->id, fn (): Conversation => Conversation::query()
        ->whereKey($scenario->conversation)
        ->firstOrFail());
    $question = writeMessageRow($conversation, MessageRole::User, 'Do you refund?', 'complete');

    // A thumbs-up on your own question is not a verdict on anything, and the database cannot object
    // — `feedback` has no role predicate. It is a 404 rather than a 422 because the caller has no
    // business learning which message ids are which role.
    expect(currentTest()->postJson("/rt/v1/messages/{$question}/feedback", ['rating' => 'positive'], chatHeaders($scenario->token)))
        ->toDenyAsNotFound('rt/v1');
});

/**
 * Write one message row directly.
 *
 * `messages` has no factory state for "belongs to THIS conversation with THIS status and content",
 * and every column here is outside `$fillable` by design.
 */
function writeMessageRow(Conversation $conversation, MessageRole $role, ?string $content, string $status): string
{
    // NO EXPLICIT ID: `HasUlids` mints a LOWER-CASE one, and every id the application writes is
    // spelled that way. Under `COLLATE "C"` an upper-case fixture id is a different value from
    // everything around it and sorts before all of them.
    $message = new Message;
    $message->conversation_id = $conversation->id;
    $message->role = $role;
    $message->content = $content;
    $message->status = \App\Enums\MessageStatus::from($status);
    $message->save();

    return (string) $message->id;
}

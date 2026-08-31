<?php

declare(strict_types=1);

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\RetrievalTrace;
use App\Models\UsageEvent;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SseFixtureServer;

/*
|--------------------------------------------------------------------------
| The SSE relay, against a REAL socket
|--------------------------------------------------------------------------
|
| `Http::fake()` IS BANNED HERE AND CANNOT FAIL A STREAMING TEST. A faked body is a string, so the
| relay drains it in microseconds: buffering, event ordering, heartbeat cadence, read timeouts and
| disconnect handling are all untestable and every one of those bugs passes. `SseFixtureServer` is
| `php -S` on a loopback port that can hold a connection open, go quiet for a measured interval, and
| hang up mid-stream — the three behaviours a fake cannot have.
|
| The response is consumed through an output-buffer callback so every write is TIMESTAMPED. Asserting
| on the final body alone proves the parser and nothing about the stream.
*/

/**
 * Drive one streamed response and record every write with the instant it happened.
 *
 * `ob_start($callback, 1)` — chunk size ONE — makes the callback fire on every output rather than at
 * the end, which is what turns `sendContent()` into a timeline. Returning `''` swallows the bytes so
 * they never reach the real output buffer and never trip `beStrictAboutOutputDuringTests`.
 *
 * @return list<array{at: float, chunk: string}>
 */
function drainStream(\Symfony\Component\HttpFoundation\Response $response): array
{
    $writes = [];

    ob_start(function (string $buffer) use (&$writes): string {
        if ($buffer !== '') {
            $writes[] = ['at' => microtime(true), 'chunk' => $buffer];
        }

        return '';
    }, 1);

    try {
        // `Response::sendContent()` AND NOT `StreamedResponse::sendContent()` in the signature: the
        // parameter is the base response Laravel's test client hands back, and narrowing it here
        // would only move the cast to every call site.
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    return $writes;
}

/**
 * The concatenated body, for the assertions that are about content rather than timing.
 *
 * @param  list<array{at: float, chunk: string}>  $writes
 */
function streamBody(array $writes): string
{
    return implode('', array_column($writes, 'chunk'));
}

/**
 * The frame names in the order they were written, `: ping` comments included as `ping`.
 *
 * @param  list<array{at: float, chunk: string}>  $writes
 * @return list<string>
 */
function streamFrameNames(array $writes): array
{
    preg_match_all('/^(?:event: (\S+)|: ping)$/m', streamBody($writes), $matches, PREG_SET_ORDER);

    $names = [];

    foreach ($matches as $match) {
        // GROUP 1 IS ABSENT ON A COMMENT, because the `: ping` alternative captures nothing. That is
        // deliberate: a heartbeat has no name on the wire, and giving it one in the pattern would
        // make the comment indistinguishable from an event called `ping` in every assertion below.
        $names[] = $match[1] ?? 'ping';
    }

    return $names;
}

/**
 * One complete, well-formed answer, as the data plane would emit it.
 *
 * @return list<array{delay_ms?: int, raw?: string}>
 */
function happyStreamSteps(string $conversationId, string $chunkId, string $connectionId): array
{
    // `__MESSAGE_ID__` IS SUBSTITUTED BY THE FIXTURE SERVER from the request body's own
    // `message_id`, because Laravel mints that id before the call and the fixture cannot know it in
    // advance. That is also the honest shape: `message.start` and `message.complete` both carry the
    // id the far side WAS GIVEN, and minting one there would hand the client an id resolving to no
    // row.
    $messageId = '__MESSAGE_ID__';

    return [
        ['raw' => "event: message.start\ndata: ".json_encode([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'created_at' => '2026-08-27T09:15:02Z',
        ])."\n\n"],
        ['raw' => "event: status\ndata: {\"stage\":\"retrieving\"}\n\n"],
        // A MEASURED SILENCE THAT OUTLASTS ONE READ TIMEOUT. This is the retrieval leg, and it is the
        // interval the relay has to answer with `: ping` — the only thing that makes a departed
        // client observable at all.
        //
        // THE DURATION IS TIED TO `kb.timeouts.stream_read`, WHICH THE TEST LOWERS TO ONE SECOND.
        // A heartbeat is produced by a read TIMING OUT, so a silence shorter than the timeout
        // produces none — and a test asserting `ping` against the production 15 s value would either
        // take fifteen seconds or pass for the wrong reason.
        ['delay_ms' => 1_300],
        ['raw' => "event: citations\ndata: ".json_encode([
            'citations' => [[
                'index' => 1,
                'source_id' => (string) Str::ulid(),
                'source_version_id' => (string) Str::ulid(),
                'chunk_id' => $chunkId,
                'title' => 'Refund policy',
                'url' => null,
                'score' => 0.83,
            ]],
        ])."\n\n"],
        ['raw' => "event: status\ndata: {\"stage\":\"generating\"}\n\n"],
        ['raw' => "event: token\ndata: {\"text\":\"Refunds are \"}\n\n"],
        ['delay_ms' => 60],
        ['raw' => "event: token\ndata: {\"text\":\"accepted for 30 days.\"}\n\n"],
        // THE THREE INTERNAL-ONLY FRAMES, all emitted by the data plane on a real turn.
        ['raw' => "event: provider.usage\ndata: ".json_encode([
            'ordinal' => 1,
            'connection_id' => $connectionId,
            'provider' => 'openai',
            'model' => 'fixture-chat-model',
            'outcome' => 'success',
            'error_class' => null,
            'stop_reason' => 'stop',
            'provider_request_id' => 'req_fixture_1',
            'input_tokens' => 50,
            'output_tokens' => 300,
            'cached_tokens' => 1_200,
            'cache_read_tokens' => 1_000,
            'cache_write_tokens' => 200,
            'reasoning_tokens' => 100,
            'latency_ms' => 4_200,
            'first_token_ms' => 900,
        ])."\n\n"],
        ['raw' => "event: retrieval.trace\ndata: ".json_encode([
            'trace' => [
                'rewritten_query' => 'refund window',
                'candidates' => [['chunk_id' => $chunkId, 'score' => 0.83]],
                'selected' => [['chunk_id' => $chunkId]],
                'insufficient_evidence' => false,
                'stages' => ['retrieve_ms' => 640],
            ],
        ])."\n\n"],
        ['raw' => "event: message.complete\ndata: ".json_encode([
            'message_id' => $messageId,
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 1_250, 'completion_tokens' => 300],
        ])."\n\n"],
    ];
}

beforeEach(function (): void {
    // ONE SECOND, DOWN FROM FIFTEEN. Guzzle applies `read_timeout` with `stream_set_timeout()`, which
    // takes whole seconds, so one is the floor — and it is what makes the heartbeat assertion cost a
    // second and a half rather than a quarter of a minute. Nothing else in the relay reads this value.
    config(['kb.timeouts.stream_read' => 1]);

    relaxPublicSurfaceLimiters();
});

// A LEAKED `php -S` IS A LEAKED PORT, and the symptom is not a failure — it is a suite that gets
// slower and eventually cannot bind. `stopAll()` is a static registry so this needs no `$this`.
afterEach(function (): void {
    SseFixtureServer::stopAll();
});

it('relays the six client frames, drops the internal ones, and heartbeats through the silence', function (): void {
    $scenario = chatScenario();
    // The message id is minted by Laravel before the call, so the fixture cannot know it in advance.
    // The frames below carry a PLACEHOLDER that is substituted once the row exists — which is also
    // the honest shape: the far side echoes the id it was given.
    $server = SseFixtureServer::start(happyStreamSteps(
        $scenario->conversation,
        $scenario->fixture->chunkId,
        (string) $scenario->fixture->connection->id,
    ));

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    $response->assertOk();
    // `str_starts_with` AND NOT AN EXACT MATCH: Symfony appends `; charset=UTF-8` to a text type, and
    // the clients branch on the PREFIX for exactly that reason.
    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/event-stream');
    $response->assertHeader('X-Accel-Buffering', 'no');

    $writes = drainStream($response->baseResponse);
    $names = streamFrameNames($writes);

    // THE SIX FORWARDED NAMES, IN ORDER, with `citations` BEFORE the first token (non-negotiable 8).
    expect($names)->toContain('message.start', 'status', 'citations', 'token', 'message.complete');

    // CITATIONS BEFORE THE FIRST TOKEN — non-negotiable 8, and the one ordering assertion a
    // final-body check cannot make. `array_search` is `int|false` and both positions are proved
    // present by the assertion above, so the casts describe rather than assume.
    expect((int) array_search('citations', $names, true))
        ->toBeLessThan((int) array_search('token', $names, true));

    // AND NOT ONE INTERNAL FRAME. This union is shared with a widget on a page we do not control.
    expect($names)->not->toContain('provider.usage')
        ->and($names)->not->toContain('provider.fallback')
        ->and($names)->not->toContain('retrieval.trace')
        ->and($names)->not->toContain('heartbeat');

    // EXACTLY ONE TERMINAL FRAME. Never both, never neither.
    expect(count(array_filter($names, static fn (string $n): bool => $n === 'message.complete' || $n === 'error')))
        ->toBe(1);

    // THE HEARTBEAT FIRED DURING THE 250 ms SILENCE. It is the disconnect probe, not merely proxy
    // keepalive: PHP learns the client is gone only when a WRITE FAILS, so with no writes there is
    // nothing to fail.
    expect($names)->toContain('ping');

    // AND IT WAS A STREAM RATHER THAN A BLOB. Asserting on the final body alone passes against a
    // relay that buffered everything and flushed once — which is the production defect this file
    // exists for. The gap between the first write and the last must reflect the upstream's own
    // pacing.
    $last = $writes[count($writes) - 1];
    $span = $last['at'] - $writes[0]['at'];

    expect(count($writes))->toBeGreaterThan(4, 'the whole answer arrived in fewer writes than it had frames')
        ->and($span)->toBeGreaterThan(0.2, 'every write landed at once, so this was a buffer and not a stream');
});

it('sends the credential map top-level, beside `config` and never inside it', function (): void {
    $scenario = chatScenario();
    $server = SseFixtureServer::start(happyStreamSteps(
        $scenario->conversation,
        $scenario->fixture->chunkId,
        (string) $scenario->fixture->connection->id,
    ));

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    drainStream($response->baseResponse);

    $received = $server->received();

    expect($received)->toHaveCount(1, 'the relay called the data plane more or fewer than once');

    $body = json_decode($received[0]['body'], true);

    expect($body)->toBeArray()
        // ADR-011: a SIBLING of `config`, never a member of it. Inside, it would be hashed into
        // `configuration_version` — so every rotation would invalidate every cached answer — and
        // persisted by every path that stores a snapshot.
        ->and($body)->toHaveKey('provider_credentials')
        ->and($body['config'])->not->toHaveKey('provider_credentials');

    // THE DECRYPTED KEY IS THERE, keyed by connection id. A test that only asserted the key's
    // ABSENCE from the snapshot would pass against a relay that sent no credential at all.
    expect($body['provider_credentials'])
        ->toHaveKey((string) $scenario->fixture->connection->id)
        ->and($body['provider_credentials'][(string) $scenario->fixture->connection->id])
        ->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    // AND THE SNAPSHOT ITSELF CARRIES NO CREDENTIAL ANYWHERE IN IT, at any depth.
    expect(json_encode($body['config']))
        ->not->toContain(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    // THE HEADERS THE FAR SIDE VERIFIES. `X-KB-Bot-Id` is present HERE and absent on every other
    // call this client makes — a chat turn has exactly one bot, and the far side raises `validation`
    // without it because the mandatory Qdrant filter has a bot-access term.
    $headers = $received[0]['headers'];

    expect($headers)->toHaveKeys([
        'x-kb-org-id', 'x-kb-bot-id', 'x-kb-actor-type', 'x-kb-operation',
        'x-kb-request-id', 'x-kb-config-version', 'x-kb-contract-version',
        'x-kb-deadline', 'x-kb-idempotency-key', 'x-kb-timestamp', 'x-kb-signature',
    ])
        ->and($headers['x-kb-org-id'])->toBe($scenario->fixture->organization->id)
        ->and($headers['x-kb-bot-id'])->toBe((string) $scenario->fixture->bot->id)
        ->and($headers['x-kb-operation'])->toBe('chat.execute')
        ->and($headers['x-kb-actor-type'])->toBe('anonymous_session');

    // THE DEADLINE IS ANCHORED TO THIS REQUEST'S START, WHICH IS NOT THE SAME CLAIM AS "IN THE
    // FUTURE" — AND ASSERTING THE SECOND MADE THIS TEST A STOPWATCH ON THE WHOLE SUITE.
    //
    // It used to read `expect($deadline)->toBeGreaterThan(microtime(true) * 1000)`. Under PHP-FPM
    // that is the same statement, because one request is one process. Under PHPUnit it is not:
    // tests/bootstrap.php defines `LARAVEL_START` at the suite's first statement, deliberately, so
    // that `requestEpoch()`'s production branch is the branch the suite executes. The consequence
    // it did not name is this one — every request-scoped deadline in the suite is measured from
    // the SUITE's boot, so an assertion that the deadline has not passed yet is really an assertion
    // that the suite reaches this line within `kb.timeouts.internal` seconds of starting. It did,
    // alone (24 s). It did not in the Feature run (332 s), where the deadline arrived 9.7 s stale
    // and the failure read as a defect in code that was correct.
    //
    // The property that actually distinguishes `requestEpoch()` from `callEpoch()` is the anchor,
    // so assert the anchor. This is duration-independent and strictly stronger: it pins the
    // millisecond rather than a range.
    $deadline = (int) $headers['x-kb-deadline'];

    // If the constant were ever absent, `requestEpoch()` would fall back to `microtime(true)` and
    // every assertion below would hold by construction against a branch that never ships. That is
    // the tautology tests/bootstrap.php exists to prevent, so check it rather than assume it.
    expect(defined('LARAVEL_START'))->toBeTrue();

    $epochMs = (int) round((float) constant('LARAVEL_START') * 1000);
    $budgetMs = (int) config('kb.timeouts.internal') * 1000;
    $nowMs = (int) round(microtime(true) * 1000);

    expect($deadline)->toBe($epochMs + $budgetMs);

    // THE CAN-IT-FAIL HALF. The line above pins a number this file could have copied from the
    // controller; this one pins the thing a copy cannot fake. Swap `requestEpoch()` for
    // `callEpoch()` upstream and the deadline moves forward by exactly the time elapsed since boot
    // — invisible to an equality the test derived the same way, caught here for any elapsed time
    // above zero, which a suite that has already opened an HTTP connection always has.
    expect($nowMs - $epochMs)->toBeGreaterThan(0)
        ->and($deadline)->toBeLessThan($nowMs + $budgetMs);

    // `allowed_version_ids` IS THE RESOLVED ACTIVE SET — non-negotiable 2's fourth filter term,
    // resolved here because only this plane can resolve it.
    expect($body['config']['allowed_version_ids'])->toBe($scenario->fixture->versionIds)
        ->and($body['config']['embedding_model_version'])->toBe($scenario->fixture->embeddingIdentity);

    // THE PUBLIC BODY'S `content` BECAME THE INTERNAL `query`. Neither name is an alias for the
    // other, and the rename happens in exactly one place.
    expect($body['query'])->toBe('Do you refund after 30 days?');
});

it('writes the message, its citations, its trace and its provider call in one transaction', function (): void {
    $scenario = chatScenario();
    $server = SseFixtureServer::start(happyStreamSteps(
        $scenario->conversation,
        $scenario->fixture->chunkId,
        (string) $scenario->fixture->connection->id,
    ));

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    drainStream($response->baseResponse);

    $answer = Message::query()
        ->where('conversation_id', '=', $scenario->conversation)
        ->where('role', '=', 'assistant')
        ->firstOrFail();

    // THE TRANSCRIPT IS WHAT THE READER SAW, accumulated from the token frames rather than read off
    // a terminal frame — because a cut stream may never deliver one.
    expect($answer->content)->toBe('Refunds are accepted for 30 days.')
        ->and($answer->status)->toBe(MessageStatus::Complete);

    // `retrieval_traces` HAD NO WRITER ON EITHER PLANE UNTIL THIS. ADR-074 took the name off the data
    // plane's ALLOWED_TABLES precisely so this plane owns it.
    $trace = RetrievalTrace::query()->where('message_id', '=', $answer->id)->firstOrFail();

    expect($trace->original_query)->toBe('Do you refund after 30 days?')
        ->and($trace->rewritten_query)->toBe('refund window')
        // THE FOUR MANDATORY FILTER TERMS ARE NAMED — `retrieval_traces_filters_name_mandatory_terms`
        // refuses a row missing any of them.
        ->and(array_keys($trace->filters))
        ->toEqualCanonicalizing(['org_id', 'bot_ids', 'source_status', 'source_version_id'])
        ->and($trace->filters['org_id'])->toBe($scenario->fixture->organization->id)
        ->and($trace->filters['source_version_id'])->toBe($scenario->fixture->versionIds);

    // THE CITATION WAS HYDRATED FROM THIS TENANT'S OWN `chunks`. The wire carries no excerpt and no
    // locator; both are denormalized here, under the turn's organization and version scope.
    $citation = $answer->citations()->firstOrFail();

    expect($citation->label)->toBe('1')
        ->and($citation->chunk_id)->toBe($scenario->fixture->chunkId)
        ->and($citation->excerpt)->toBe('Refunds are accepted for 30 days.')
        ->and($citation->location_metadata['page'] ?? null)->toBe(1);

    // ONE `provider_calls` ROW PER ATTEMPT, with the token conversion applied: the wire's disjoint
    // 50 uncached plus 1 000 read plus 200 written is 1 250 in the column, and the 100 reasoning
    // tokens come OUT of the 300 output.
    $call = asTenant($scenario->fixture->organization->id, fn (): ProviderCall => ProviderCall::query()
        ->where('message_id', '=', $answer->id)
        ->firstOrFail());

    expect($call->input_tokens)->toBe(1_250)
        ->and($call->cache_read_tokens)->toBe(1_000)
        ->and($call->cache_write_tokens)->toBe(200)
        ->and($call->output_tokens)->toBe(200)
        ->and($call->reasoning_tokens)->toBe(100)
        ->and($call->organization_id)->toBe($scenario->fixture->organization->id);

    // AND THE MESSAGE NAMES THE ATTEMPT THAT SETTLED IT — the cycle in the schema, closed in time.
    expect((string) $answer->fresh()?->provider_call_id)->toBe((string) $call->id);

    // METERED INTO THE LEDGER, from the ROW rather than from the tally, so a re-derivation collides
    // instead of adding.
    $events = asTenant(
        $scenario->fixture->organization->id,
        fn () => UsageEvent::query()->where('dedupe_key', '=', $call->id)->get(),
    );

    expect($events)->toHaveCount(2, 'a billed turn writes an input row and an output row');

    // INPUT IS `input_tokens` ALONE — the column is already the total — and OUTPUT is
    // `output + reasoning`, because every vendor here bills reasoning at the output rate.
    expect($events->pluck('quantity')->sort()->values()->all())->toBe([300, 1_250]);
});

it('writes its rows on a stream that was cut before its terminal frame', function (): void {
    $scenario = chatScenario();
    // THE ONE THAT REGRESSES SILENTLY. The upstream hangs up after two tokens and a usage frame —
    // no `message.complete`, no `error`. The provider still billed what it generated, so the row
    // still has to exist; a finalizer that waited for a terminal frame writes nothing and the spend
    // is invisible rather than wrong.
    $server = SseFixtureServer::start([
        ['raw' => "event: message.start\ndata: ".json_encode([
            'message_id' => '__MESSAGE_ID__',
            'conversation_id' => $scenario->conversation,
            'created_at' => '2026-08-27T09:15:02Z',
        ])."\n\n"],
        ['raw' => "event: token\ndata: {\"text\":\"Refunds are \"}\n\n"],
        ['raw' => "event: provider.usage\ndata: ".json_encode([
            'ordinal' => 1,
            'connection_id' => (string) $scenario->fixture->connection->id,
            'provider' => 'openai',
            'model' => 'fixture-chat-model',
            'outcome' => 'cancelled',
            'error_class' => 'user_cancellation',
            'stop_reason' => null,
            'provider_request_id' => 'req_cut',
            'input_tokens' => 40,
            'output_tokens' => 12,
            'cached_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'latency_ms' => 800,
            'first_token_ms' => 400,
        ])."\n\n"],
        // …and the server returns. The socket closes with no terminal frame.
    ]);

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    $writes = drainStream($response->baseResponse);
    $names = streamFrameNames($writes);

    // THE CLIENT IS STILL OWED A TERMINAL FRAME. A server that can still write knows more than a
    // client guessing — and the class is one of the eighteen. `stream_lost` is a CLIENT-LOCAL
    // sentinel, is not on the wire, and must never be serialized.
    expect($names)->toContain('error')
        ->and(streamBody($writes))->toContain('"error_class":"internal_dependency"')
        ->and(streamBody($writes))->not->toContain('stream_lost');

    $answer = Message::query()
        ->where('conversation_id', '=', $scenario->conversation)
        ->where('role', '=', 'assistant')
        ->firstOrFail();

    // THE PARTIAL ANSWER IS THE TRANSCRIPT. What the reader saw is what the row says; a transcript
    // that disagreed with the wire is worse than a short one.
    expect($answer->content)->toBe('Refunds are ')
        ->and($answer->status)->toBe(MessageStatus::Failed);

    // AND THE TOKENS ARE BILLED. This is the assertion the whole `ignore_user_abort` /
    // `finally` / re-bound-tenant-context arrangement exists to make true.
    $call = asTenant($scenario->fixture->organization->id, fn (): ProviderCall => ProviderCall::query()
        ->where('message_id', '=', $answer->id)
        ->firstOrFail());

    expect($call->input_tokens)->toBe(40)->and($call->output_tokens)->toBe(12);

    expect(asTenant($scenario->fixture->organization->id, fn (): int => UsageEvent::query()
        ->where('dedupe_key', '=', $call->id)
        ->count()))->toBe(2);
});

it('collapses a re-submitted client_message_id into one turn and one bill', function (): void {
    $scenario = chatScenario();
    $server = SseFixtureServer::start(happyStreamSteps(
        $scenario->conversation,
        $scenario->fixture->chunkId,
        (string) $scenario->fixture->connection->id,
    ));

    config(['services.ai.url' => $server->url()]);

    $clientMessageId = (string) Str::ulid();

    $first = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => $clientMessageId,
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    drainStream($first->baseResponse);

    // THE SAME ID AGAIN — a double submit, a StrictMode double effect, a queued send flushed after a
    // session refresh. It must not run a second generation.
    $second = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => $clientMessageId,
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    $replay = streamFrameNames(drainStream($second->baseResponse));

    expect($replay)->toContain('message.start', 'message.complete');

    // ONE UPSTREAM CALL, TOTAL. This is the assertion that the id is doing its job: a second call
    // here is a second retrieval, a second generation and a second bill for one question.
    expect($server->received())->toHaveCount(1);

    expect(Message::query()->where('conversation_id', '=', $scenario->conversation)->count())->toBe(2)
        ->and(asTenant($scenario->fixture->organization->id, fn (): int => ProviderCall::query()
            ->where('conversation_id', '=', $scenario->conversation)
            ->count()))->toBe(1);
});

it('relays a data-plane refusal as an `error` FRAME, because the status line is already spent', function (): void {
    $scenario = chatScenario();
    // THE DATA PLANE IS CALLED FROM INSIDE THE STREAM CALLBACK, so by the time it can refuse, 200 and
    // the SSE headers are on the wire. That is not a compromise — it is what
    // `kb-internal-api-contracts` means by "the response has already started": a failure afterwards
    // is an `error` frame or it is a dropped connection, and every client is told to branch on the
    // CONTENT TYPE rather than on the status for precisely this reason.
    //
    // The refusals that CAN still be a status are THIS plane's own — the gate, the rate limit, the
    // quota, the configuration resolution — and every one of them runs before `response()->stream()`
    // is even constructed. The empty-scope test below is one of those, and asserts a real 422.
    $server = SseFixtureServer::start([], 422, json_encode([
        'error_class' => 'validation',
        'message' => 'the internal chat request failed validation',
        'retryable' => false,
        'request_id' => '01JKB0000000000000000FAIL',
        'errors' => ['config.allowed_version_ids' => ['too_short']],
    ], JSON_THROW_ON_ERROR));

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'));

    $response->assertOk();

    $body = streamBody(drainStream($response->baseResponse));

    // THE CLASS THE DATA PLANE ASSIGNED, VERBATIM. Never re-derived from the status: a
    // `provider_temporary` arriving as a 503 would come back out as `internal_dependency` and the
    // client's retry decision would be made against a class nobody assigned (ADR-029 at the relay).
    expect($body)->toContain('event: error')
        ->and($body)->toContain('"error_class":"validation"')
        ->and($body)->toContain('"retryable":false');

    // THE TURN SETTLES AS FAILED RATHER THAN BEING LEFT PENDING. A `pending` row is a turn the
    // unsettled-message index keeps pointing at forever.
    $answer = Message::query()
        ->where('conversation_id', '=', $scenario->conversation)
        ->where('role', '=', 'assistant')
        ->firstOrFail();

    expect($answer->status)->toBe(MessageStatus::Failed)
        ->and(asTenant($scenario->fixture->organization->id, fn (): int => ProviderCall::query()
            ->where('message_id', '=', $answer->id)
            ->count()))->toBe(0);
});

it('refuses a bot whose retrieval scope is empty, before any credential is decrypted', function (): void {
    $scenario = chatScenario();
    // AN EMPTY SCOPE IS A REAL STATE — a bot with no assigned source, or whose every source is
    // disabled — and it must be refused rather than sent: `Filter(must=[])` is a confirmed MATCH-ALL
    // in Qdrant and `MatchAny(any=[])` matches nothing without saying so, so an empty
    // `allowed_version_ids` on the wire is either every tenant's chunks or none.
    $server = SseFixtureServer::start([]);
    config(['services.ai.url' => $server->url()]);

    DB::table('source_versions')
        ->whereIn('id', $scenario->fixture->versionIds)
        ->update(['activated_at' => null]);

    currentTest()->postJson("/rt/v1/conversations/{$scenario->conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($scenario->token, 'text/event-stream'))
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');

    // NOT ONE BYTE WENT TO THE DATA PLANE. The refusal happened while the status line was still
    // available, which is the whole reason the resolution runs before `response()->stream()`.
    expect($server->received())->toBe([]);
});

it('closes the upstream body so the data plane stops billing', function (): void {
    $scenario = chatScenario();
    // `$up?->cancel()` IN THE `finally`. Closing the PSR body is what makes FastAPI see
    // `http.disconnect`, cancel the pipeline task and close the provider socket; skip it and the
    // vendor generates billable tokens nobody will read for as long as the model wants to talk.
    //
    // What is observable from here is the resource: after the relay finishes, the handle must be
    // closed rather than left open for the request's lifetime.
    $resource = fopen('php://memory', 'r+');

    if (! is_resource($resource)) {
        // `fopen()` on `php://memory` cannot fail in practice; the guard is what makes the three
        // calls below well-typed rather than three casts, and it fails with a sentence instead of a
        // TypeError if the impossible happens.
        throw new \RuntimeException('could not open an in-memory stream for the cancellation probe');
    }

    fwrite($resource, ": ping\n\n");
    rewind($resource);

    $stream = new \App\Services\Internal\UpstreamStream($resource);

    expect(is_resource($resource))->toBeTrue();

    $stream->cancel();

    expect(is_resource($resource))->toBeFalse('the upstream body was left open');

    // IDEMPOTENT. The `finally` runs on every terminal path and a second call must not fatal on a
    // closed handle.
    $stream->cancel();
})->group('unit-ish');

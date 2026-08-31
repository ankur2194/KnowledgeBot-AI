<?php

declare(strict_types=1);

use App\Enums\BotStatus;
use App\Enums\ConversationChannel;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sdk\WidgetSessionService;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\ChatFixture;
use Tests\Support\SpaSession;
use Tests\Support\SseFixtureServer;

/*
|--------------------------------------------------------------------------
| The D5 playground credential — the BEHAVIOUR half
|--------------------------------------------------------------------------
|
| The mint is an ADMIN route that issues a PUBLIC RUNTIME bearer, which is the whole design:
| routes/api_public.php states as a decision that `ResolveChatSession` resolves ONE mechanism and
| that adding a second is a deliberate change to the four-mechanisms rule. So the console mints a
| `kbw_` session instead and uses the completely UNCHANGED streaming path. Every assertion below is
| about what the RECORD says, never about what the token looks like — the two token strings are
| deliberately indistinguishable.
|
| THE AUTHORIZATION AND ISOLATION HALF IS tests/Security/PlaygroundSessionAccessTest.php. The two
| are separate files because they fail for different reasons: a red test here is a bug in the
| endpoint or the relay, a red test there is a privilege or tenant boundary.
|
| `Http::fake()` APPEARS NOWHERE AND CANNOT. A faked SSE body is a string, so the relay drains it in
| microseconds and every buffering, ordering, heartbeat and disconnect bug passes (pest-testing NN4).
| `SseFixtureServer` is `php -S` on a loopback port that can hold a connection open and go quiet for
| a measured interval, and it also RECORDS what it received — which is how the internal request's
| own `X-KB-Actor-Type` is asserted below rather than inferred.
|
| EVERY HELPER IN THIS FILE HAS A NAME OF ITS OWN. Pest declares test-file helpers at FILE SCOPE, so
| a name another test file already uses is a redeclaration fatal in a full run and only in a full
| run — which is why none of `ChatStreamRelayTest`'s `drainStream`/`streamFrameNames`/
| `happyStreamSteps` is reused by reference here.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN, so no rate-limiter bucket is shared with another test or
    // with the previous run of the suite (RefreshDatabase rolls back PostgreSQL and has no opinion
    // about a Valkey counter).
    SpaSession::isolateRateLimits(currentTest());
    relaxPublicSurfaceLimiters();

    // ONE SECOND, DOWN FROM FIFTEEN. A heartbeat is produced by a READ TIMING OUT, so a test that
    // asserts one against the production value would take fifteen seconds or pass for the wrong
    // reason. Guzzle applies `read_timeout` with `stream_set_timeout()`, which takes whole seconds.
    config(['kb.timeouts.stream_read' => 1]);
});

// A LEAKED `php -S` IS A LEAKED PORT, and the symptom is not a failure — it is a suite that gets
// slower and eventually cannot bind.
afterEach(function (): void {
    SseFixtureServer::stopAll();
});

/**
 * A chat-ready organization plus an administrator of it, with a live SPA session already
 * established.
 *
 * `chatFixture()` builds the bot `published` and `publicly accessible` and wires every row
 * `ConfigSnapshotResolver` has to find; what it does NOT build is a human, because the public
 * runtime surface has none. The playground does, so this adds one.
 *
 * @return array{fixture: ChatFixture, actor: User}
 */
function playgroundActor(?ChatFixture $fixture = null): array
{
    $fixture ??= chatFixture();

    $actor = User::factory()->recycle($fixture->organization)->orgRole(OrgRole::Admin)->create([
        'email' => SpaSession::uniqueEmail('playground-admin'),
    ]);

    SpaSession::establish(currentTest(), $actor);

    return ['fixture' => $fixture, 'actor' => $actor];
}

/**
 * The mint route, addressed for one fixture.
 */
function playgroundMintPath(ChatFixture $fixture): string
{
    return "/api/v1/organizations/{$fixture->organization->id}/bots/{$fixture->bot->id}/playground-session";
}

/**
 * Mint through the REAL endpoint and return the bearer.
 *
 * Through the endpoint rather than through the service, because the endpoint is where the six
 * checks are — a helper that called `WidgetSessionService::mintPlayground()` directly would produce
 * a credential no authorization decision stands behind, and every test built on it would be testing
 * the fixture.
 */
function playgroundToken(ChatFixture $fixture): string
{
    $response = currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders());

    $response->assertStatus(201);

    return (string) $response->json('data.token');
}

/**
 * One complete, well-formed answer INCLUDING all three internal-only frames.
 *
 * The three are what make this file's central assertion expressible at all: a stream that never
 * carried `retrieval.trace` cannot prove that the trace was FORWARDED to one caller and REFUSED to
 * another, and a stream that carried only the trace cannot prove the other two stayed refused to
 * the caller who legitimately got it.
 *
 * @return list<array{delay_ms?: int, raw?: string}>
 */
function playgroundStreamSteps(string $conversationId, string $chunkId, string $connectionId): array
{
    // SUBSTITUTED BY THE FIXTURE SERVER from the request body's own `message_id`: Laravel mints that
    // id before the call, so the fixture cannot know it in advance, and inventing one here would
    // hand the client an id resolving to no row.
    $messageId = '__MESSAGE_ID__';

    return [
        ['raw' => "event: message.start\ndata: ".json_encode([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'created_at' => '2026-08-27T09:15:02Z',
        ], JSON_THROW_ON_ERROR)."\n\n"],
        ['raw' => "event: status\ndata: {\"stage\":\"retrieving\"}\n\n"],
        // A MEASURED SILENCE THAT OUTLASTS ONE READ TIMEOUT — the retrieval leg, and the interval
        // the relay has to answer with `: ping`. Tied to `kb.timeouts.stream_read`, lowered to one
        // second in `beforeEach`.
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
        ], JSON_THROW_ON_ERROR)."\n\n"],
        ['raw' => "event: token\ndata: {\"text\":\"Refunds are \"}\n\n"],
        ['delay_ms' => 60],
        ['raw' => "event: token\ndata: {\"text\":\"accepted for 30 days.\"}\n\n"],
        // ── THE THREE INTERNAL-ONLY FRAMES ────────────────────────────────────────────────────
        ['raw' => "event: provider.usage\ndata: ".json_encode([
            'ordinal' => 1,
            'connection_id' => $connectionId,
            'provider' => 'openai',
            'model' => 'fixture-chat-model',
            'outcome' => 'success',
            'error_class' => null,
            'stop_reason' => 'stop',
            'provider_request_id' => 'req_playground_1',
            'input_tokens' => 50,
            'output_tokens' => 300,
            'cached_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'latency_ms' => 4_200,
            'first_token_ms' => 900,
        ], JSON_THROW_ON_ERROR)."\n\n"],
        ['raw' => "event: provider.fallback\ndata: ".json_encode([
            'from_connection_id' => $connectionId,
            'to_connection_id' => $connectionId,
            'reason' => 'provider_temporary',
        ], JSON_THROW_ON_ERROR)."\n\n"],
        ['raw' => "event: retrieval.trace\ndata: ".json_encode([
            'trace' => [
                'rewritten_query' => 'refund window',
                'candidates' => [['chunk_id' => $chunkId, 'score' => 0.83]],
                'selected' => [['chunk_id' => $chunkId]],
                'insufficient_evidence' => false,
                'stages' => ['retrieve_ms' => 640],
            ],
        ], JSON_THROW_ON_ERROR)."\n\n"],
        ['raw' => "event: message.complete\ndata: ".json_encode([
            'message_id' => $messageId,
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 1_250, 'completion_tokens' => 300],
        ], JSON_THROW_ON_ERROR)."\n\n"],
    ];
}

/**
 * Drive one streamed response and return every write, with the instant it happened.
 *
 * `ob_start($callback, 1)` — chunk size ONE — makes the callback fire on every output rather than at
 * the end, which is what turns `sendContent()` into a timeline. Returning `''` swallows the bytes so
 * they never trip `beStrictAboutOutputDuringTests`.
 *
 * @return list<array{at: float, chunk: string}>
 */
function drainPlaygroundStream(\Symfony\Component\HttpFoundation\Response $response): array
{
    $writes = [];

    ob_start(function (string $buffer) use (&$writes): string {
        if ($buffer !== '') {
            $writes[] = ['at' => microtime(true), 'chunk' => $buffer];
        }

        return '';
    }, 1);

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    return $writes;
}

/**
 * The frame names in the order they were written, `: ping` comments included as `ping`.
 *
 * @param  list<array{at: float, chunk: string}>  $writes
 * @return list<string>
 */
function playgroundFrameNames(array $writes): array
{
    preg_match_all(
        '/^(?:event: (\S+)|: ping)$/m',
        implode('', array_column($writes, 'chunk')),
        $matches,
        PREG_SET_ORDER,
    );

    $names = [];

    foreach ($matches as $match) {
        // GROUP 1 IS ABSENT ON A COMMENT: a heartbeat has no name on the wire, and giving it one in
        // the pattern would make the comment indistinguishable from an event called `ping`.
        $names[] = $match[1] ?? 'ping';
    }

    return $names;
}

/**
 * Open a conversation and submit one turn against a scripted upstream, returning the frame names.
 *
 * @return array{names: list<string>, body: string, received: list<array{method: string, uri: string, headers: array<string, string>, body: string}>, conversation: string}
 */
function playgroundTurn(ChatFixture $fixture, string $token): array
{
    $conversation = (string) currentTest()
        ->postJson('/rt/v1/conversations', [], chatHeaders($token))
        ->assertStatus(201)
        ->json('data.id');

    $server = SseFixtureServer::start(playgroundStreamSteps(
        $conversation,
        $fixture->chunkId,
        (string) $fixture->connection->id,
    ));

    config(['services.ai.url' => $server->url()]);

    $response = currentTest()->postJson("/rt/v1/conversations/{$conversation}/messages", [
        'client_message_id' => (string) Str::ulid(),
        'content' => 'Do you refund after 30 days?',
    ], chatHeaders($token, 'text/event-stream'));

    $response->assertOk();

    $writes = drainPlaygroundStream($response->baseResponse);

    return [
        'names' => playgroundFrameNames($writes),
        'body' => implode('', array_column($writes, 'chunk')),
        'received' => $server->received(),
        'conversation' => $conversation,
    ];
}

// -------------------------------------------------------------------------------------------
// The mint
// -------------------------------------------------------------------------------------------

it('mints a playground bearer that the UNCHANGED rt/v1 surface resolves', function (): void {
    ['fixture' => $fixture] = playgroundActor();

    $response = currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders());

    $response->assertStatus(201);

    $token = (string) $response->json('data.token');

    // THE SAME SHAPE AS A WIDGET TOKEN, DELIBERATELY. A token whose shape announced its authority
    // would tell anyone who found one in a paste which of the two they had.
    expect($token)->toStartWith(WidgetSessionService::PREFIX)
        ->and($response->json('data.expires_in'))->toBe(config('kb.playground.session_ttl_seconds'));

    // A LIVE CREDENTIAL MUST NOT BE CACHEABLE. `private` alone permits the browser's own cache and
    // would make the response replayable from a back button.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // THE POSITIVE CONTROL FOR EVERY REFUSAL TEST IN THIS FILE AND ITS SECURITY SIBLING: the token
    // this endpoint produces actually resolves, on the surface it was minted for, with nothing about
    // `ResolveChatSession` changed. Without this the negative assertions also pass against a mint
    // that returns rubbish.
    currentTest()->postJson('/rt/v1/conversations', [], chatHeaders($token))->assertStatus(201);
});

it('returns a session token and NOTHING else — no provider credential, no bot secret', function (): void {
    ['fixture' => $fixture] = playgroundActor();

    $response = currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders());

    $response->assertStatus(201);

    /** @var array<string, mixed> $data */
    $data = (array) $response->json('data');

    // AN EXACT KEY SET, not an absence test. An absence test has to enumerate every field name
    // somebody might add, and the first one nobody thought of passes it.
    expect(array_keys($data))->toEqualCanonicalizing(['token', 'expires_in']);

    $body = (string) $response->getContent();

    // NON-NEGOTIABLE 9, WITH ITS POSITIVE CONTROL. The fixture's connection really does hold a
    // decrypted-able credential — the relay sends it to the data plane on every turn — so its
    // absence here is a redaction rather than a fixture that never had one to leak.
    expect($fixture->connection->fresh()?->credential_ciphertext)->not->toBeNull(
        'the fixture connection holds no sealed credential, so asserting its absence proves nothing',
    );

    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))->toBeFalse(
        'the mint response carries a provider credential',
    );
    expect(str_contains($body, (string) $fixture->connection->id))->toBeFalse(
        'the mint response names a provider connection',
    );
    expect(str_contains($body, (string) $fixture->bot->public_bot_id))->toBeFalse(
        'the mint response carries the bot\'s public identifier, which is not the caller\'s to need here',
    );
});

it('stores a HASH with a TTL and never the token, exactly as the sdk/v1 mint does', function (): void {
    ['fixture' => $fixture, 'actor' => $actor] = playgroundActor();

    $token = playgroundToken($fixture);
    $secret = substr($token, strrpos($token, '.') + 1);

    $key = 'sess:'.$fixture->organization->id.':'.$fixture->bot->id.':'.substr(hash('sha256', $secret), 0, 32);

    /** @var array<string, string> $record */
    $record = Redis::connection('coordination')->hgetall($key);

    expect($record)->not->toBe([], 'the playground mint wrote no session record at the catalog key');

    // THE SAME MACHINERY AS THE WIDGET MINT, NOT A SECOND ONE: the record carries the FULL digest so
    // a collision on the key's truncation cannot authenticate, and it must not carry the secret.
    expect($record['token_hash'] ?? null)->toBe(hash('sha256', $secret));

    foreach ($record as $field => $value) {
        expect(str_contains($value, $secret))->toBeFalse(
            "the playground session record's `{$field}` contains the plaintext secret",
        );
    }

    // THE DISCRIMINATOR IS IN THE RECORD AND THE ACTOR IS TOO. Neither is inferable from the token
    // string, which is the property that keeps the two kinds apart.
    expect($record['kind'] ?? null)->toBe(WidgetSessionService::KIND_PLAYGROUND)
        ->and($record['user_id'] ?? null)->toBe($actor->id)
        ->and($record['origin'] ?? null)->toBe(WidgetSessionService::PLAYGROUND_ORIGIN);

    // AND IT EXPIRES, on the PLAYGROUND'S OWN lifetime rather than the widget's. A session record
    // with no TTL is a bearer that never stops working — which is why the mint is one atomic script.
    $ttl = (int) Redis::connection('coordination')->ttl($key);

    expect($ttl)->toBeGreaterThan(0)
        ->toBeLessThanOrEqual((int) config('kb.playground.session_ttl_seconds'));

    // IT IS THE SHORTER OF THE TWO, ASSERTED RATHER THAN ASSUMED — the whole reason
    // `WidgetSessionService::ttl()` takes the kind as a REQUIRED argument is that a new kind must
    // not inherit another's lifetime by omitting one.
    expect((int) config('kb.playground.session_ttl_seconds'))
        ->toBeLessThan((int) config('kb.widget.session_ttl_seconds'));
});

it('carries two abilities and no admin verb, and cannot submit feedback', function (): void {
    // PINNED AS AN EXACT SET rather than tested for the ABSENCE of admin verbs: an absence test has
    // to enumerate what "admin" means, and the first verb nobody thought of passes it.
    expect(WidgetSessionService::PLAYGROUND_ABILITIES)->toBe(['chat:send', 'chat:read']);

    foreach (WidgetSessionService::PLAYGROUND_ABILITIES as $ability) {
        expect(str_starts_with($ability, 'chat:'))->toBeTrue(
            "the playground token carries `{$ability}`, which is not a chat-surface ability",
        );
    }

    // `feedback:submit` IS ABSENT ON PURPOSE. §8.23 reports a satisfaction figure from END USERS,
    // and an operator rating their own test run puts a reviewer's thumb into it. The shipped panel
    // renders no thumbs; this is the server-side half of the same decision.
    //
    // THROUGH A CLOSURE TAKING PLAIN `string`/`array`, deliberately, and it is the idiom
    // `AuditLoggerTest`'s `$isOneOf` uses for the same reason: called on the constants directly,
    // PHPStan folds both sides to their literal values and reports the comparison as always true or
    // always false — which is exactly the shape of assertion that stops being a check the moment a
    // constant grows an entry. The runtime check is the point.
    $holds = static fn (string $ability, array $abilities): bool => in_array($ability, $abilities, true);

    expect($holds('feedback:submit', WidgetSessionService::PLAYGROUND_ABILITIES))->toBeFalse();
    expect($holds('feedback:submit', WidgetSessionService::ABILITIES))->toBeTrue(
        'the WIDGET token lost `feedback:submit`, so the asymmetry above is not the one this test claims',
    );
});

it('records the turn on the playground channel, against the acting user and no session digest', function (): void {
    ['fixture' => $fixture, 'actor' => $actor] = playgroundActor();

    $token = playgroundToken($fixture);

    $conversation = (string) currentTest()
        ->postJson('/rt/v1/conversations', [], chatHeaders($token))
        ->assertStatus(201)
        ->json('data.id');

    $row = asTenant(
        $fixture->organization->id,
        fn (): Conversation => Conversation::query()->whereKey($conversation)->firstOrFail(),
    );

    // THE CHANNEL IS DERIVED FROM THE CREDENTIAL AND FROM NOTHING ELSE. A body field naming it would
    // let a widget on a stranger's marketing site claim the actor type that unlocks the trace.
    expect($row->channel)->toBe(ConversationChannel::Playground);

    // EXACTLY ONE PARTICIPANT COLUMN — `conversations_participant_exclusive` refuses both, and
    // `conversations_authenticated_channel` refuses a `playground` row with neither.
    expect($row->user_id)->toBe($actor->id)
        ->and($row->anonymous_session_id)->toBeNull();
});

it('audits the issuance with the DERIVED session id and no token in any form', function (): void {
    ['fixture' => $fixture, 'actor' => $actor] = playgroundActor();

    $token = playgroundToken($fixture);
    $secret = substr($token, strrpos($token, '.') + 1);

    $row = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_PLAYGROUND_SESSION_MINTED)
        ->where('subject_id', '=', $fixture->bot->id)
        ->latest('created_at')
        ->firstOrFail();

    expect($row->organization_id)->toBe($fixture->organization->id)
        ->and($row->actor_id)->toBe($actor->id);

    /** @var array<string, mixed> $details */
    $details = (array) $row->details;

    expect($details['bot_id'] ?? null)->toBe($fixture->bot->id)
        ->and($details['session_id'] ?? null)->toBe(substr(hash('sha256', $secret), 0, 32))
        ->and($details['diagnostics'] ?? null)->toBeTrue()
        ->and($details['expires_in'] ?? null)->toBe((int) config('kb.playground.session_ttl_seconds'));

    // THE WHOLE ROW, SERIALIZED, CARRIES NEITHER THE BEARER NOR ITS SECRET — not echoed, not
    // fingerprinted, not a prefix. `audit_logs` is append-only and long-lived by design.
    $serialized = (string) json_encode($row->toArray());

    expect(str_contains($serialized, $secret))->toBeFalse('the audit row contains the session secret');
    expect(str_contains($serialized, $token))->toBeFalse('the audit row contains the session token');
});

it('refuses a bot that is not reachable from the playground, and says which state it is in', function (BotStatus $status): void {
    // `draft` IS REFUSED ON THE AUTHORITY OF ITS OWN CASE COMMENT in App\Enums\BotStatus: "Never
    // reachable from any channel, including the admin playground." `paused` and `archived` are
    // refused because those cases say "Neither answers."
    ['fixture' => $fixture] = playgroundActor();

    $fixture->bot->status = $status;
    $fixture->bot->save();

    currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error_class', 'internal_dependency');
})->with([
    'draft' => BotStatus::Draft,
    'paused' => BotStatus::Paused,
    'archived' => BotStatus::Archived,
]);

it('mints for a bot in `testing`, which is the state the playground exists for', function (): void {
    ['fixture' => $fixture] = playgroundActor();

    $fixture->bot->status = BotStatus::Testing;
    $fixture->bot->save();

    // THE POSITIVE HALF OF THE STATUS MATRIX. Without it the three refusals above are satisfied by a
    // check that refuses everything — and `testing` is precisely the state that distinguishes the
    // playground's reachability rule from `isRetrievable()`'s.
    currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders())->assertStatus(201);
});

it('refuses to mint for a suspended organization', function (): void {
    ['fixture' => $fixture] = playgroundActor();

    Organization::query()->whereKey($fixture->organization->id)
        ->update(['status' => OrganizationStatus::Suspended->value]);

    // CHECK 5's FIRST HALF. A playground turn spends the organization's provider quota, and a
    // suspended organization may not.
    currentTest()->postJson(playgroundMintPath($fixture), [], spaHeaders())->assertStatus(409);
});

// -------------------------------------------------------------------------------------------
// The stream — property 1 and property 2, on the same scripted upstream
// -------------------------------------------------------------------------------------------

it('forwards `retrieval.trace` to a playground session and ONLY the trace', function (): void {
    ['fixture' => $fixture] = playgroundActor();

    $turn = playgroundTurn($fixture, playgroundToken($fixture));

    // THE SIX CLIENT FRAMES STILL ARRIVE, IN ORDER. Without this the trace assertion below could
    // pass on a stream that had broken in some other way and happened to emit one frame.
    expect($turn['names'])->toContain('message.start', 'status', 'citations', 'token', 'message.complete');

    // THE ONE FRAME THIS WHOLE CHANGE EXISTS FOR.
    expect($turn['names'])->toContain('retrieval.trace');

    // AND ONLY THAT ONE. `ClientEvents::allows()` already encodes this and it is deliberately NOT
    // widened: `provider.usage` carries per-attempt token counts and the tenant's `connection_id`,
    // `provider.fallback` carries our routing topology and the tenant's model ladder, and
    // `heartbeat` never crosses that class at all.
    expect($turn['names'])->not->toContain('provider.usage');
    expect($turn['names'])->not->toContain('provider.fallback');
    expect($turn['names'])->not->toContain('heartbeat');

    // EXACTLY ONE TERMINAL FRAME. Never both, never neither.
    expect(count(array_filter(
        $turn['names'],
        static fn (string $n): bool => $n === 'message.complete' || $n === 'error',
    )))->toBe(1);

    // THE TRACE'S CONTENT REACHED THE CLIENT rather than an empty frame with the right name — the
    // panel renders candidates, and a name-only assertion passes against a relay that forwarded a
    // stripped frame.
    expect(str_contains($turn['body'], 'rewritten_query'))->toBeTrue()
        ->and(str_contains($turn['body'], $fixture->chunkId))->toBeTrue();

    // AND THE INTERNAL REQUEST SAID WHO WAS ASKING. `X-KB-Actor-Type` is inside the canonical
    // signing string, and it is what the data plane's own diagnostics contract keys on.
    expect($turn['received'])->toHaveCount(1);
    expect($turn['received'][0]['headers']['x-kb-actor-type'] ?? null)->toBe('user');
});

it('refuses `retrieval.trace` to an ordinary widget session, which still receives everything else', function (): void {
    // PROPERTY 1, AND IT IS THE REGRESSION THAT MATTERS MOST. The widget runs inside an iframe on a
    // page the customer controls and we do not; the whole reason `retrieval.trace` is absent from
    // the published `KbEvent` union is that "a client that can NAME them is a client that can RENDER
    // them".
    $fixture = chatFixture();
    $widgetToken = chatSessionToken($fixture);

    // THE RESOLVED SESSION IS UNCHANGED, ASSERTED AT THE SOURCE. Both fields are what
    // `ClientEvents::allows()` reads, and they are AND-ed — so either one alone would refuse.
    $session = app(WidgetSessionService::class)->resolve($widgetToken);

    expect($session->actorType)->toBe(\App\Enums\ActorType::AnonymousSession)
        ->and($session->diagnostics)->toBeFalse()
        ->and($session->userId)->toBeNull()
        ->and($session->abilities)->toEqualCanonicalizing(WidgetSessionService::ABILITIES);

    $turn = playgroundTurn($fixture, $widgetToken);

    // THE POSITIVE CONTROL, AND IT IS WHAT MAKES "REFUSED THE TRACE" DISTINGUISHABLE FROM "RECEIVED
    // NOTHING". The same scripted upstream, the same relay, the same five client frames — only the
    // credential differs.
    expect($turn['names'])->toContain('message.start', 'status', 'citations', 'token', 'message.complete');
    expect(str_contains($turn['body'], 'Refunds are '))->toBeTrue(
        'the widget session received no answer text, so the absence assertion below proves nothing',
    );

    // THE REAL ASSERTION. Note the shape: `expect(str_contains(...))->toBeFalse()` for the BODY,
    // because Pest's `toContain(mixed ...$needles)` takes no message argument and `not` treats any
    // failure as success — a label passed there becomes a second needle and the expression passes
    // unconditionally.
    expect($turn['names'])->not->toContain('retrieval.trace');
    expect(str_contains($turn['body'], 'rewritten_query'))->toBeFalse(
        'the retrieval trace\'s payload reached a widget session',
    );

    // AND THE OTHER THREE ARE STILL REFUSED TO IT TOO — the widget's position did not change in
    // either direction.
    expect($turn['names'])->not->toContain('provider.usage');
    expect($turn['names'])->not->toContain('provider.fallback');
    expect($turn['names'])->not->toContain('heartbeat');

    expect($turn['received'][0]['headers']['x-kb-actor-type'] ?? null)->toBe('anonymous_session');
});

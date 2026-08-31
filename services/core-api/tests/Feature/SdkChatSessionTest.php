<?php

declare(strict_types=1);

use App\Enums\BotAccessMode;
use App\Enums\BotDomainStatus;
use App\Enums\BotStatus;
use App\Services\Sdk\WidgetSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| sdk/v1 — the mint, the bootstrap, and the six rejection shapes
|--------------------------------------------------------------------------
|
| Every rejection on this surface is a 404 with a BYTE-IDENTICAL body. A 403 on a foreign identifier
| answers "that bot id is real, your domain just is not on its list", which is an enumeration oracle
| over every tenant's bots — and the body matters as much as the status, because an attacker reads
| the body.
|
| `toDenyAsNotFound('sdk/v1')` is what proves that: it fires a REAL request at a path on the same
| surface that certainly has no route, reusing the request id of the response under test so even that
| field matches, and requires the two raw bodies to be equal byte for byte. It compares against a
| live control rather than against a copy of the strings, so it keeps working after both drift.
*/

/**
 * THE ORIGIN THE FIXTURE ALLOW-LISTS. A constant rather than `beforeEach` state, so every negative
 * case below can be a near-MISS of it rather than an arbitrary string — a near-miss is exactly what a
 * substring check would wrongly admit, and an arbitrary string would pass against one.
 */
const ALLOWED_ORIGIN = 'https://customer.example';

beforeEach(function (): void {
    // THE `sdk-bootstrap` LIMITER IS TEN PER MINUTE PER IP AND COUNTS 404s. This file fires more
    // than ten rejections from `127.0.0.1`, and its counter lives in Valkey where nothing resets it
    // between tests — so without this, tests start failing with a 429 that has nothing to do with
    // what they assert, and WHICH ones fail moves with test order. The last test in this file
    // deliberately does NOT relax it and asserts the limiter is real.
    relaxPublicSurfaceLimiters();
});

it('mints a session for an allow-listed origin and returns a usable bearer', function (): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    $response = currentTest()->postJson('/sdk/v1/session', [
        'bot_id' => $fixture->bot->public_bot_id,
        'user_token' => null,
    ], ['Origin' => ALLOWED_ORIGIN]);

    $response->assertStatus(201);

    $token = $response->json('data.token');

    expect($token)->toBeString()
        ->and($token)->toStartWith(WidgetSessionService::PREFIX)
        ->and($response->json('data.expires_in'))->toBe(config('kb.widget.session_ttl_seconds'));

    // A LIVE CREDENTIAL MUST NOT BE CACHEABLE. `private` alone permits the browser's own cache and
    // would make the response replayable from a back button.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // THE POSITIVE CONTROL FOR EVERY REJECTION TEST BELOW: the token this surface produces actually
    // resolves. Without it the negative assertions also pass against a mint that returns rubbish.
    $session = app(WidgetSessionService::class)->resolve($token);

    expect($session->organizationId)->toBe($fixture->organization->id)
        ->and($session->botId)->toBe($fixture->bot->id)
        ->and($session->embedderOrigin)->toBe(ALLOWED_ORIGIN)
        ->and($session->abilities)->toEqualCanonicalizing(WidgetSessionService::ABILITIES);
});

it('stores only the token\'s digest, never the token', function (): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    $token = currentTest()->postJson('/sdk/v1/session', ['bot_id' => $fixture->bot->public_bot_id], [
        'Origin' => ALLOWED_ORIGIN,
    ])->json('data.token');

    expect($token)->toBeString();

    $secret = substr((string) $token, strrpos((string) $token, '.') + 1);
    $key = 'sess:'.$fixture->organization->id.':'.$fixture->bot->id.':'.substr(hash('sha256', $secret), 0, 32);

    /** @var array<string, string> $record */
    $record = Redis::connection('coordination')->hgetall($key);

    expect($record)->not->toBe([], 'the mint wrote no session record at the catalog key');

    // A VALKEY DUMP MUST NOT BE A SET OF LIVE CREDENTIALS. The record carries the full digest so a
    // collision on the key's truncation cannot authenticate; it must not carry the secret itself.
    expect($record['token_hash'] ?? null)->toBe(hash('sha256', $secret));

    foreach ($record as $field => $value) {
        expect(str_contains($value, $secret))->toBeFalse(
            "the session record's `{$field}` contains the plaintext secret",
        );
    }

    // AND IT EXPIRES. A session record with no TTL is a bearer that never stops working — which is
    // why the mint is one atomic script rather than an HSET followed by an EXPIRE.
    expect((int) Redis::connection('coordination')->ttl($key))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual((int) config('kb.widget.session_ttl_seconds'));
});

it('mints a token that carries no admin ability, ever', function (): void {
    // It is minted with NO HUMAN AUTHENTICATION AT ALL — anyone who can put the loader on an
    // allow-listed page gets one. An ability like `sources.delete` on it would turn an XSS on a
    // customer's marketing site into a deleted knowledge base (`laravel-sanctum-auth`).
    //
    // PINNED AS AN EXACT SET rather than tested for the ABSENCE of admin verbs: an absence test has
    // to enumerate what "admin" means, and the first verb nobody thought of passes it.
    expect(WidgetSessionService::ABILITIES)->toBe(['chat:send', 'chat:read', 'feedback:submit']);

    // Every one names a chat-surface action on one bot's own conversation. A namespace outside these
    // two is a different surface, and this token has no business reaching one.
    foreach (WidgetSessionService::ABILITIES as $ability) {
        expect(str_starts_with($ability, 'chat:') || str_starts_with($ability, 'feedback:'))
            ->toBeTrue("`{$ability}` is outside the two namespaces a chat session may act in");
    }
});

/*
|--------------------------------------------------------------------------
| THE SIX REJECTION SHAPES
|--------------------------------------------------------------------------
|
| `laravel-sanctum-auth`'s Definition of done names exactly these: unlisted origin,
| `https://<allowed>.evil.com`, no `Origin`, `Origin: null`, unknown bot id, and a valid bot from the
| wrong origin. All 404, all byte-identical.
*/

it('answers every rejection with the same 404 body', function (string $case): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    [$body, $headers] = match ($case) {
        // 1. An origin that is simply not on the list.
        'unlisted origin' => [
            ['bot_id' => $fixture->bot->public_bot_id],
            ['Origin' => 'https://not-a-customer.example'],
        ],
        // 2. THE SUFFIX NEAR-MISS. `startsWith` admits it, and OWASP names the whole substring
        //    family "very insecure". There is no safe substring form of an origin check.
        'a subdomain of the allowed origin under an attacker domain' => [
            ['bot_id' => $fixture->bot->public_bot_id],
            ['Origin' => 'https://customer.example.evil.com'],
        ],
        // 3. Absent entirely. The browser sets `Origin` and page script cannot forge it, so its
        //    absence means the claim was never made — never a default-allow.
        'no Origin header' => [
            ['bot_id' => $fixture->bot->public_bot_id],
            [],
        ],
        // 4. THE STRING "null", which is what a sandboxed iframe, a `data:` document and a
        //    cross-origin redirect all send. Allow-listing it allow-lists every opaque context on
        //    the internet.
        'Origin: null' => [
            ['bot_id' => $fixture->bot->public_bot_id],
            ['Origin' => 'null'],
        ],
        // 5. A bot id that names nothing.
        'unknown bot id' => [
            ['bot_id' => 'pub_'.str_repeat('0', 32)],
            ['Origin' => ALLOWED_ORIGIN],
        ],
        // 6. A REAL BOT FROM A REAL BUT WRONG ORIGIN — the case a 403 would confirm.
        'a valid bot from another tenant\'s allowed origin' => (function () {
            $other = chatFixture(origin: 'https://other-customer.example');

            return [
                ['bot_id' => $other->bot->public_bot_id],
                ['Origin' => ALLOWED_ORIGIN],
            ];
        })(),
        // UNREACHABLE, AND PRESENT ANYWAY. `match` over a string is not exhaustive to the analyser,
        // and the alternative — an `if/else` chain — loses the property that makes this readable:
        // the dataset name and the request it produces sit on one line. A dataset row with no arm
        // fails HERE, naming itself, instead of silently reusing the previous case's request.
        default => throw new \RuntimeException("no request is defined for the `{$case}` rejection"),
    };

    expect(currentTest()->postJson('/sdk/v1/session', $body, $headers))->toDenyAsNotFound('sdk/v1');
})->with([
    'unlisted origin',
    'a subdomain of the allowed origin under an attacker domain',
    'no Origin header',
    'Origin: null',
    'unknown bot id',
    'a valid bot from another tenant\'s allowed origin',
]);

it('answers a bot that is published but PRIVATE with the same 404', function (): void {
    // TWO SEPARATE FACTS, AND BOTH HAVE TO AGREE. A private published bot is a real configuration —
    // an internal helpdesk bot reachable only from an authenticated surface — and treating status
    // alone as the gate would make every one of them world-reachable through the widget.
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    // `DB::table()` AND NOT `Bot::query()`. The model is org-scoped and a test has no bound tenant
    // context, so an Eloquent update here matches `1 = 0` and changes NOTHING — the test would then
    // assert a 404 against a bot that is still public and fail for a reason that has nothing to do
    // with the rule. Writing the row unscoped is the honest fixture manipulation.
    DB::table('bots')->where('id', '=', $fixture->bot->id)
        ->update(['access_mode' => BotAccessMode::Private->value]);

    expect(currentTest()->postJson('/sdk/v1/session', ['bot_id' => $fixture->bot->public_bot_id], [
        'Origin' => ALLOWED_ORIGIN,
    ]))->toDenyAsNotFound('sdk/v1');
});

it('answers a bot that is public but NOT PUBLISHED with the same 404', function (BotStatus $status): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    // Unscoped, for the reason the private-bot case above states at length.
    DB::table('bots')->where('id', '=', $fixture->bot->id)->update(['status' => $status->value]);

    expect(currentTest()->postJson('/sdk/v1/session', ['bot_id' => $fixture->bot->public_bot_id], [
        'Origin' => ALLOWED_ORIGIN,
    ]))->toDenyAsNotFound('sdk/v1');
})->with([
    'draft' => BotStatus::Draft,
    'paused' => BotStatus::Paused,
    'archived' => BotStatus::Archived,
]);

it('stops working the moment the domain leaves the allow-list, without waiting for the TTL', function (): void {
    // THE REVOCATION STORY FOR A CREDENTIAL WITH NO DATABASE ROW. `resolve()` re-reads the bot's live
    // status and allow-list on EVERY request rather than trusting the snapshot it stored at mint, so
    // a removed domain kills the session on the next call.
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);
    $token = chatSessionToken($fixture);

    // POSITIVE CONTROL FIRST. Without it this passes when the surface is broken and 404s everything.
    currentTest()->getJson('/rt/v1/bot', chatHeaders($token))->assertOk();

    DB::table('bot_domains')
        ->where('bot_id', '=', $fixture->bot->id)
        ->update(['status' => BotDomainStatus::Disabled->value]);

    expect(currentTest()->getJson('/rt/v1/bot', chatHeaders($token)))->toDenyAsNotFound('rt/v1');
});

it('serves origin-validated bootstrap configuration without minting a credential', function (): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    $response = currentTest()->postJson('/sdk/v1/bootstrap', [
        'bot_id' => $fixture->bot->public_bot_id,
    ], ['Origin' => ALLOWED_ORIGIN]);

    $response->assertOk()
        ->assertJsonPath('data.public_bot_id', $fixture->bot->public_bot_id)
        ->assertJsonPath('data.name', $fixture->bot->name);

    // THE PUBLIC PROJECTION CARRIES NO CONFIGURATION. Not the internal ULID, not the connection, not
    // the model, not the retrieval parameters, not the limits — a stranger loads this page.
    $body = (string) $response->getContent();

    foreach ([
        (string) $fixture->bot->id,
        (string) $fixture->connection->id,
        (string) $fixture->model->id,
        (string) $fixture->model->model,
    ] as $secret) {
        expect(str_contains($body, $secret))->toBeFalse(
            'the SDK bootstrap published an internal identifier or a model name',
        );
    }

    // NO SESSION WAS CREATED. Drawing a launcher is not a reason to issue a credential: a page that
    // loads the loader and never opens the widget would otherwise burn one session per view.
    expect($body)->not->toContain(WidgetSessionService::PREFIX);
});

it('refuses bootstrap from the wrong origin with the same 404', function (): void {
    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    expect(currentTest()->postJson('/sdk/v1/bootstrap', ['bot_id' => $fixture->bot->public_bot_id], [
        'Origin' => 'https://customer.example.evil.com',
    ]))->toDenyAsNotFound('sdk/v1');
});

it('counts only the MISSES, so probing costs the prober and legitimate traffic pays nothing', function (): void {
    // NO `relaxPublicSurfaceLimiters()` HERE. This is the positive control for the helper every
    // other test in this file calls: without it, that helper would have quietly disabled a security
    // control for the whole suite and nothing would say so.
    //
    // `Limit::…->after(fn ($response) => $response->getStatusCode() === 404)` is what makes the
    // counter tick on a REJECTION only. That is the enumeration cover the 404 rule depends on:
    // `botByPublicId()` is deliberately unscoped by organization, so unlimited guesses at a public
    // bot id is the one thing that would make its 128 bits worth attacking.
    // THE SUBJECT IS FIXED FOR THE WHOLE TEST AND FRESH PER RUN. Minting it INSIDE the closure
    // would give every request its own bucket, so the counter would never accumulate and the test
    // would pass against a limiter that counts nothing; reusing a constant would inherit whatever
    // the previous run left in Valkey, which nothing resets between tests.
    $subject = 'boot-test:'.\Illuminate\Support\Str::ulid();

    \Illuminate\Support\Facades\RateLimiter::for(
        'sdk-bootstrap',
        static fn (): \Illuminate\Cache\RateLimiting\Limit => \Illuminate\Cache\RateLimiting\Limit::perMinute(3)
            ->by($subject)
            ->after(static fn (\Symfony\Component\HttpFoundation\Response $response): bool => $response->getStatusCode() === 404),
    );

    $fixture = chatFixture(origin: ALLOWED_ORIGIN);

    // FOUR SUCCESSES, WHICH IS MORE THAN THE LIMIT. None of them counts, so none is refused.
    for ($i = 0; $i < 4; $i++) {
        currentTest()->postJson('/sdk/v1/session', ['bot_id' => $fixture->bot->public_bot_id], [
            'Origin' => ALLOWED_ORIGIN,
        ])->assertStatus(201);
    }

    // THREE MISSES EXHAUST IT, and the fourth is refused — as `rate_limit`, which is a different
    // class from the 404 the probe was earning, and is retryable.
    for ($i = 0; $i < 3; $i++) {
        currentTest()->postJson('/sdk/v1/session', ['bot_id' => 'pub_'.str_repeat('0', 32)], [
            'Origin' => ALLOWED_ORIGIN,
        ])->assertStatus(404);
    }

    currentTest()->postJson('/sdk/v1/session', ['bot_id' => 'pub_'.str_repeat('0', 32)], [
        'Origin' => ALLOWED_ORIGIN,
    ])->assertStatus(429)->assertJsonPath('error_class', 'rate_limit');
});

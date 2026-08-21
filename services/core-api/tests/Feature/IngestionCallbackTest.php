<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Http\Requests\IngestionCallbackRequest;
use App\Models\AuditLog;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Internal\InternalRequestSigner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| POST /internal/v1/callbacks/ingestion — the data plane reporting a run
|--------------------------------------------------------------------------
|
| THE DATA PLANE HAS NO INGESTION ROUTER YET (`services/ai-service/app/api/internal/v1/` holds only
| `embedding.py`), so nothing calls this route in a running system: a source reaches `queued` and
| stops. That is the expected state rather than a defect, and it is exactly why this file exists —
| the Laravel half is written against the contract, and these tests are what the other half will be
| written against.
|
| FOUR PROPERTIES ARE UNDER TEST HERE AND NOTHING ELSE IS:
|
|   1. AUTHENTICATION. The internal surface carries no cookie, no bearer token and no session. "It
|      is only reachable from the private network" is TOPOLOGY, NOT AUTHORIZATION — Milvus's
|      CVE-2025-64513 was one trusted internal header away from full admin.
|   2. THE ORDERING GUARD. `WHERE sequence > progress_sequence` plus a `current_job_id` equality.
|      Without it a Celery retry re-emitting stage 6 after stage 9 flips a `ready` source back to
|      `processing` and takes an already-published version out of retrieval, with a 200 on both.
|   3. THE VERIFICATION GATE. `Indexing -> Ready` without a passing verification is the single
|      failure users describe as "the bot only knows half the document".
|   4. THE DELIVERY COUNTER, which is the gap this callback exists to close: the worker bumps a
|      durable per-version redelivery count and CANNOT write the column, because `source_versions`
|      is not in `ALLOWED_TABLES` and never may be.
|
| The tenant scope comes from `X-KB-Org-Id`, which is INSIDE the HMAC canonical string — that is
| what makes it trustworthy, and it is why a callback cannot be aimed at another organization by
| flipping a header.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');
    Queue::fake();
});

/**
 * A queued text source of a fresh organization, plus its single item.
 *
 * BUILT THROUGH THE REAL CREATE ENDPOINT rather than by hand, so the item's `current_job_id` and
 * `progress_sequence` are claimed by the code the callback is guarded against rather than by a
 * fixture's idea of it.
 *
 * @return array{org: Organization, source: KnowledgeSource, item: SourceItem}
 */
function ingestionRunFixture(): array
{
    $org = Organization::factory()->create(['name' => 'Callback Org', 'slug' => 'callback-org-'.Str::lower((string) Str::ulid())]);

    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('callback-owner')]);

    SpaSession::establish(currentTest(), $owner);

    $created = currentTest()->postJson(
        "/api/v1/organizations/{$org->id}/sources",
        ['type' => 'text', 'name' => 'Refund policy', 'content' => 'Refunds are accepted for 30 days.'],
        spaHeaders(),
    );

    $created->assertCreated();

    SpaSession::freshProcess();

    $source = KnowledgeSource::query()->withoutGlobalScopes()
        ->findOrFail((string) $created->json('data.id'));

    return [
        'org' => $org,
        'source' => $source,
        'item' => SourceItem::query()->withoutGlobalScopes()->where('source_id', '=', $source->id)->sole(),
    ];
}

/**
 * A full version identity, as the data plane computes it.
 *
 * EVERY COMPONENT IS THE DATA PLANE'S AND NONE OF THEM IS GUESSABLE HERE. The three `*_cfg_version`
 * strings are digests over Docling, RapidOCR and chunker option maps plus resolved model-pin commit
 * shas; `embedding_model_version` is provider, model id, the width the vendor actually returned and
 * a digest over a fixed probe set. A control plane that computed any of them would produce an
 * ingest key that matches nothing, and `UNIQUE (source_item_id, ingest_key)` would silently stop
 * deduplicating — every resubmission minting a new version and re-embedding the corpus at a
 * provider's per-token price.
 *
 * @return array<string, string>
 */
function ingestionIdentity(string $salt = 'a'): array
{
    return [
        'content_hash' => hash('sha256', 'content-'.$salt),
        'ingest_key' => hash('sha256', 'key-'.$salt),
        'parser_cfg_version' => 'parser/v1:docling:9f2a1c4e77b1',
        'ocr_cfg_version' => 'ocr/v1:rapidocr:0c1d2e3f4a5b',
        'chunker_cfg_version' => 'chunker/v1:structural:aabbccddeeff',
        'embedding_model_version' => 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1',
    ];
}

/**
 * POST one frame, signed exactly as the data plane would.
 *
 * THE SIGNED BYTES ARE THE SENT BYTES. The body is serialized ONCE, hashed, and handed to `call()`
 * as raw content — `postJson()` would re-encode it, and re-encoding JSON to hash it is not
 * byte-stable, which produces intermittent 401s on requests that were signed correctly.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headerOverrides
 * @return TestResponse<\Illuminate\Http\Response>
 */
function postIngestionFrame(
    string $organizationId,
    array $body,
    array $headerOverrides = [],
    ?string $signatureOverride = null,
): TestResponse {
    $path = '/internal/v1/callbacks/ingestion';
    $payload = json_encode($body, JSON_THROW_ON_ERROR);

    $headers = [
        'X-KB-Org-Id' => $organizationId,
        'X-KB-Actor-Type' => 'system',
        'X-KB-Operation' => 'ingestion.progress',
        'X-KB-Request-Id' => (string) Str::ulid(),
        'X-KB-Contract-Version' => (string) config('kb.contract_version'),
        'X-KB-Timestamp' => (string) time(),
    ];

    // `$headerOverrides + $headers` AND NOT THE REVERSE. PHP's `+` keeps the LEFT operand's value
    // for a duplicate key, so writing the defaults first would make every override a no-op — and
    // the replay test would then pass a fresh nonce on both calls and assert nothing.
    $headers = $headerOverrides + $headers;

    // THE CALLBACK RING, because this helper plays the part of FastAPI. `services.ai.hmac.*` is
    // the OUTBOUND ring this application signs WITH, and signing a callback with it is the exact
    // production defect the two-ring split exists to prevent — a helper that used it would make
    // every assertion below pass against a verifier wired to the wrong direction.
    /** @var array<string, string> $keys */
    $keys = (array) config('services.ai.callback_hmac.keys');
    $keyId = (string) array_key_first($keys);

    // The prefix the PEER emits, taken from the list this application accepts. Not
    // `kb.signing_prefix`: that is what Laravel emits outbound, and the two are only equal
    // until someone bumps one of them.
    /** @var list<string> $prefixes */
    $prefixes = (array) config('services.ai.callback_hmac.accepted_prefixes');

    $signature = $signatureOverride ?? (new InternalRequestSigner(
        (string) ($prefixes[0] ?? 'KB1'),
        $keyId,
        $keys[$keyId],
    ))->sign('POST', $path, $payload, $headers);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers + ['X-KB-Signature' => $signature] as $name => $value) {
        $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
    }

    /** @var TestResponse<\Illuminate\Http\Response> $response */
    $response = currentTest()->call('POST', $path, [], [], [], $server, $payload);

    return $response;
}

// ── authentication ───────────────────────────────────────────────────────────────────────────────

it('refuses an unsigned callback, and says nothing about which check failed', function (): void {
    $f = ingestionRunFixture();

    $body = [
        'job_id' => $f['item']->current_job_id, 'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id, 'sequence' => 1, 'stage' => 'fetch',
        'status' => SourceState::Fetching->value,
    ];

    $unsigned = currentTest()->postJson('/internal/v1/callbacks/ingestion', $body);

    $unsigned->assertStatus(401)->assertJsonPath('error_class', 'authentication');

    // A FORGED SIGNATURE IS THE SAME REFUSAL, BYTE FOR BYTE. A verifier that distinguishes "no
    // signature" from "wrong signature" from "unknown key id" is an oracle for tuning an attack;
    // one class, one status, one sentence.
    $forged = postIngestionFrame($f['org']->id, $body, [], 'k1:'.str_repeat('0', 64));

    $forged->assertStatus(401)->assertJsonPath('error_class', 'authentication');

    $strip = static fn (TestResponse $r): string => (string) preg_replace(
        '/"request_id":"[^"]*"/', '"request_id":"*"', (string) $r->getContent(),
    );

    expect($strip($unsigned))->toBe($strip($forged));

    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);
});

it('refuses a callback signed with the OUTBOUND key ring', function (): void {
    $f = ingestionRunFixture();

    $body = [
        'job_id' => $f['item']->current_job_id, 'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id, 'sequence' => 1, 'stage' => 'fetch',
        'status' => SourceState::Fetching->value,
    ];

    // THE DIRECTION IS THE POINT. `k1` is what Laravel signs its own outbound calls with; a
    // verifier that resolved secrets from that ring would 401 every real callback (FastAPI signs
    // with `c1`) AND would make a leaked outbound key sufficient to forge one with any
    // `X-KB-Org-Id` it liked. Both halves are the same one-line config read, so this test pins it.
    $headers = [
        'X-KB-Org-Id' => $f['org']->id,
        'X-KB-Actor-Type' => 'system',
        'X-KB-Operation' => 'ingestion.progress',
        'X-KB-Request-Id' => (string) Str::ulid(),
        'X-KB-Contract-Version' => (string) config('kb.contract_version'),
        'X-KB-Timestamp' => (string) time(),
    ];

    /** @var array<string, string> $outbound */
    $outbound = (array) config('services.ai.hmac.keys');
    $outboundId = (string) config('services.ai.hmac.active');

    $signature = (new InternalRequestSigner(
        (string) config('kb.signing_prefix'),
        $outboundId,
        $outbound[$outboundId],
    ))->sign('POST', '/internal/v1/callbacks/ingestion', json_encode($body, JSON_THROW_ON_ERROR), $headers);

    postIngestionFrame($f['org']->id, $body, $headers, $signature)
        ->assertStatus(401)->assertJsonPath('error_class', 'authentication');

    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);
});

it('honours every prefix in the inbound accepted list, and no other', function (): void {
    // THE TWO-DEPLOY PREFIX BUMP, which is the only reason this is configuration rather than a
    // constant. A verifier reading a key no deployment sets would default to ['KB1'] and this
    // would fail on the retired prefix while the operator's variable did nothing.
    config(['services.ai.callback_hmac.accepted_prefixes' => ['KB2', 'KB1']]);

    $f = ingestionRunFixture();

    $frame = fn (int $sequence, string $prefix): array => [
        'body' => [
            'job_id' => $f['item']->current_job_id, 'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id, 'sequence' => $sequence, 'stage' => 'fetch',
            'status' => SourceState::Fetching->value,
        ],
        'prefix' => $prefix,
    ];

    /** @var array<string, string> $keys */
    $keys = (array) config('services.ai.callback_hmac.keys');
    $keyId = (string) array_key_first($keys);

    $sign = function (array $body, string $prefix, array $headers) use ($keys, $keyId): string {
        return (new InternalRequestSigner($prefix, $keyId, $keys[$keyId]))
            ->sign('POST', '/internal/v1/callbacks/ingestion', json_encode($body, JSON_THROW_ON_ERROR), $headers);
    };

    foreach (['KB1', 'KB2'] as $i => $prefix) {
        $body = $frame($i + 1, $prefix)['body'];

        $headers = [
            'X-KB-Org-Id' => $f['org']->id,
            'X-KB-Actor-Type' => 'system',
            'X-KB-Operation' => 'ingestion.progress',
            'X-KB-Request-Id' => (string) Str::ulid(),
            'X-KB-Contract-Version' => (string) config('kb.contract_version'),
            'X-KB-Timestamp' => (string) time(),
        ];

        postIngestionFrame($f['org']->id, $body, $headers, $sign($body, $prefix, $headers))->assertOk();
    }

    // AND NOTHING ELSE. A prefix that left the list is refused on the second deploy, which is what
    // makes dropping it a real retirement rather than a comment.
    $body = $frame(3, 'KB3')['body'];

    $headers = [
        'X-KB-Org-Id' => $f['org']->id,
        'X-KB-Actor-Type' => 'system',
        'X-KB-Operation' => 'ingestion.progress',
        'X-KB-Request-Id' => (string) Str::ulid(),
        'X-KB-Contract-Version' => (string) config('kb.contract_version'),
        'X-KB-Timestamp' => (string) time(),
    ];

    postIngestionFrame($f['org']->id, $body, $headers, $sign($body, 'KB3', $headers))
        ->assertStatus(401)->assertJsonPath('error_class', 'authentication');
});

it('refuses a replayed request id and a stale timestamp', function (): void {
    $f = ingestionRunFixture();

    $body = [
        'job_id' => $f['item']->current_job_id, 'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id, 'sequence' => 1, 'stage' => 'fetch',
        'status' => SourceState::Fetching->value,
    ];

    $requestId = (string) Str::ulid();

    postIngestionFrame($f['org']->id, $body, ['X-KB-Request-Id' => $requestId])->assertOk();

    // THE SAME NONCE AGAIN. Skew tolerance and a replay nonce are set TOGETHER — a 60-second window
    // with no nonce is not a replay defence, it is a 60-second window in which every captured
    // request can be resent. The frame below is byte-identical and correctly signed, and it is
    // still refused.
    postIngestionFrame($f['org']->id, $body, ['X-KB-Request-Id' => $requestId])
        ->assertStatus(401)->assertJsonPath('error_class', 'authentication');

    // AND THE OTHER HALF: a correctly signed request from outside the window.
    postIngestionFrame(
        $f['org']->id,
        $body,
        ['X-KB-Timestamp' => (string) (time() - (int) config('kb.signature_skew_seconds') - 5)],
    )->assertStatus(401);
});

it('refuses a signed frame whose org header is not a ULID, as validation rather than as auth', function (): void {
    $f = ingestionRunFixture();

    // REACHED ONLY BECAUSE THE SIGNATURE VERIFIED — the header is inside the canonical string — so
    // this is a well-formed caller sending a malformed value. `validation` renders 422, and there
    // is deliberately no 400 anywhere in the taxonomy for it to be instead.
    postIngestionFrame('not-a-ulid', [
        'job_id' => $f['item']->current_job_id, 'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id, 'sequence' => 1, 'stage' => 'fetch',
        'status' => SourceState::Fetching->value,
    ])->assertStatus(422)->assertJsonPath('error_class', 'validation');
});

// ── the ordering guard ───────────────────────────────────────────────────────────────────────────

it('applies frames in order and refuses one that would rewind the run', function (): void {
    $f = ingestionRunFixture();

    $frame = fn (int $sequence, SourceState $status): array => [
        'job_id' => $f['item']->current_job_id,
        'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id,
        'sequence' => $sequence,
        'stage' => $status->value,
        'status' => $status->value,
    ];

    postIngestionFrame($f['org']->id, $frame(1, SourceState::Fetching))
        ->assertOk()->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.status', SourceState::Fetching->value);

    // THE STAGES IN ORDER, because the transition table has no shortcuts — `fetching` cannot reach
    // `normalizing` — and a guard test that also broke the machine would be red for two reasons.
    postIngestionFrame($f['org']->id, $frame(2, SourceState::Parsing))
        ->assertOk()->assertJsonPath('data.applied', true);

    postIngestionFrame($f['org']->id, $frame(3, SourceState::Normalizing))
        ->assertOk()->assertJsonPath('data.applied', true);

    expect($f['item']->fresh()?->progress_sequence)->toBe(3);

    // THE FRAME THAT WOULD REWIND. A Celery retry re-emitting an earlier stage after a later one
    // has landed is an ordinary Tuesday, and without this guard it flips a `ready` source back to
    // `processing` and takes an already-published version out of retrieval.
    //
    // IT IS A 200, NOT A 4xx. A permanently-failing response in front of a task that is going to
    // re-emit the frame is how the taxonomy ends up retrying something whose whole meaning is
    // "already superseded".
    postIngestionFrame($f['org']->id, $frame(2, SourceState::Parsing))
        ->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.reason', 'out_of_order');

    expect($f['source']->fresh()?->status)->toBe(SourceState::Normalizing);
    expect($f['item']->fresh()?->progress_sequence)->toBe(3);
});

it('ignores a frame from a superseded run outright, rather than merely ordering it', function (): void {
    $f = ingestionRunFixture();

    // A run whose sequences also start at 1. Without the `current_job_id` equality, its stage-3
    // frame would read as the FUTURE of the live run and would apply.
    postIngestionFrame($f['org']->id, [
        'job_id' => (string) Str::ulid(),
        'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id,
        'sequence' => 3,
        'stage' => 'parse',
        'status' => SourceState::Parsing->value,
    ])->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.reason', 'stale_job');

    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);
    expect($f['item']->fresh()?->progress_sequence)->toBe(0);
});

it('refuses a frame describing an item this organization does not have', function (): void {
    $f = ingestionRunFixture();

    postIngestionFrame($f['org']->id, [
        'job_id' => $f['item']->current_job_id,
        'source_id' => $f['source']->id,
        'source_item_id' => (string) Str::ulid(),
        'sequence' => 1,
        'stage' => 'fetch',
        'status' => SourceState::Fetching->value,
    ])->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.reason', 'unknown_item');
});

// ── the version row, the verification gate, and activation ───────────────────────────────────────

it('rolls the SOURCE up from every item, so one finishing early cannot strand the others', function (): void {
    // ── THE ORDINARY CASE, NOT AN EXOTIC ONE ─────────────────────────────────────────────────
    //
    // `UploadIntake::MAX_BATCH` is 10, so a source with several items is what a multi-file upload
    // always produces. Each item is its own run with its own frames, and the runs do not proceed in
    // lockstep. Mirroring the newest frame onto the source reported whichever item spoke last and
    // then asked the transition table for the edge between two items' unrelated stages: file A
    // reaching `ready` put the SOURCE in `ready`, file B's next `chunking` frame found no
    // `Ready -> Chunking` edge, and the 422 it produced is `validation` — non-retryable, so the
    // worker gave up. The rollback took B's `progress_sequence` with it, so B could never publish.
    $f = ingestionRunFixture();

    $itemB = new SourceItem;
    $itemB->organization_id = $f['org']->id;
    $itemB->source_id = $f['source']->id;
    $itemB->canonical_key = 'text:'.$f['source']->id.':b';
    $itemB->current_job_id = $f['item']->current_job_id;
    $itemB->save();

    $send = function (SourceItem $item, int $sequence, SourceState $status, array $extra = []) use ($f): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $item->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $item->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra);
    };

    $identityA = ingestionIdentity('item-a');
    $identityB = ingestionIdentity('item-b');

    // A runs all the way through while B has not started.
    $send($f['item'], 1, SourceState::Fetching)->assertOk();
    $send($f['item'], 2, SourceState::Parsing, ['version' => $identityA])->assertOk();

    // THE SOURCE SHOWS THE LEAST ADVANCED ITEM. B has no version row at all, which is `queued`.
    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);

    foreach ([3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $seq => $status) {
        $send($f['item'], $seq, $status, ['version' => $identityA])->assertOk();
    }

    $send($f['item'], 7, SourceState::Ready, ['version' => $identityA, 'verified' => true])->assertOk();

    // A HAS PUBLISHED AND THE SOURCE HAS NOT. Its own version is `ready` and its pointer is set;
    // the source still reports the work B has not done.
    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);
    expect($f['item']->fresh()?->current_version_id)->not->toBeNull();

    // ── AND B CAN STILL RUN, WHICH IS THE WHOLE FINDING ──────────────────────────────────────
    $send($itemB, 1, SourceState::Fetching)->assertOk();
    $send($itemB, 2, SourceState::Parsing, ['version' => $identityB])->assertOk();

    expect($f['source']->fresh()?->status)->toBe(SourceState::Parsing);

    foreach ([3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $seq => $status) {
        $send($itemB, $seq, $status, ['version' => $identityB])->assertOk();
    }

    $send($itemB, 7, SourceState::Ready, ['version' => $identityB, 'verified' => true])->assertOk();

    // EVERY ITEM SETTLED CLEAN, so and only so does the source.
    expect($f['source']->fresh()?->status)->toBe(SourceState::Ready);
});

it('reports the source as failed when any item failed, even with a sibling ready', function (): void {
    $f = ingestionRunFixture();

    $itemB = new SourceItem;
    $itemB->organization_id = $f['org']->id;
    $itemB->source_id = $f['source']->id;
    $itemB->canonical_key = 'text:'.$f['source']->id.':b';
    $itemB->current_job_id = $f['item']->current_job_id;
    $itemB->save();

    $send = function (SourceItem $item, int $sequence, SourceState $status, array $extra = []) use ($f): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $item->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $item->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra);
    };

    $identity = ingestionIdentity('failing-sibling');

    $send($f['item'], 1, SourceState::Fetching)->assertOk();
    $send($f['item'], 2, SourceState::Parsing, ['version' => $identity])->assertOk();
    foreach ([3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $seq => $status) {
        $send($f['item'], $seq, $status, ['version' => $identity])->assertOk();
    }
    $send($f['item'], 7, SourceState::Ready, ['version' => $identity, 'verified' => true])->assertOk();

    $send($itemB, 1, SourceState::Fetching)->assertOk();
    $send($itemB, 2, SourceState::Failed)->assertOk();

    // ANY FAILURE, NOT ALL OF THEM. Half a source is not `ready`: the pill is the operator's only
    // signal that a document they uploaded is not answerable, and averaging it away turns a red
    // badge into a support ticket.
    expect($f['source']->fresh()?->status)->toBe(SourceState::Failed);
});

it('lets a retired version be re-derived, and refuses to walk the LIVE one backwards', function (): void {
    // ── THE CRAWLED PAGE THAT GOES A -> B -> BACK TO A ───────────────────────────────────────
    //
    // `(source_item_id, ingest_key)` is unique, so re-deriving old content resolves the OLD version
    // row rather than minting one. That row is `ready` or `ready_with_warnings`, and the table has
    // no edge out of either to `parsing` — so the page could never be ingested again: every frame
    // 422'd as `validation`, which is non-retryable.
    $f = ingestionRunFixture();

    $send = function (int $sequence, SourceState $status, array $extra = []) use ($f): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra);
    };

    $contentA = ingestionIdentity('content-a');
    $contentB = ingestionIdentity('content-b');

    $run = function (int $base, array $identity) use ($send): void {
        $send($base + 1, SourceState::Fetching)->assertOk();
        $send($base + 2, SourceState::Parsing, ['version' => $identity])->assertOk();
        foreach ([3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $offset => $status) {
            $send($base + $offset, $status, ['version' => $identity])->assertOk();
        }
        $send($base + 7, SourceState::Ready, ['version' => $identity, 'verified' => true])->assertOk();
    };

    $run(0, $contentA);
    $run(10, $contentB);

    $versionA = SourceVersion::query()->withoutGlobalScopes()
        ->where('ingest_key', '=', $contentA['ingest_key'])->sole();

    expect($versionA->retired_at)->not->toBeNull();

    // ── CONTENT REVERTS TO A ─────────────────────────────────────────────────────────────────
    $send(21, SourceState::Fetching)->assertOk();
    $send(22, SourceState::Parsing, ['version' => $contentA])->assertOk();

    $versionA = SourceVersion::query()->withoutGlobalScopes()
        ->where('ingest_key', '=', $contentA['ingest_key'])->sole();

    // THE ROW RE-ENTERED, and its retirement stamp went with the run that retired it. A row left
    // outside `source_versions_one_active_per_item` — activated, still retired — is a row nothing
    // stops a second version being activated beside.
    expect($versionA->status)->toBe(SourceState::Parsing);
    expect($versionA->retired_at)->toBeNull();
    expect($versionA->activated_at)->toBeNull();
    expect($versionA->delivery_count)->toBe(0);

    foreach ([23 => SourceState::Normalizing, 24 => SourceState::Chunking, 25 => SourceState::Embedding, 26 => SourceState::Indexing] as $seq => $status) {
        $send($seq, $status, ['version' => $contentA])->assertOk();
    }

    $send(27, SourceState::Ready, ['version' => $contentA, 'verified' => true])
        ->assertOk()->assertJsonPath('data.activated', true);

    expect($f['item']->fresh()?->current_version_id)->toBe($versionA->id);

    // ── AND THE LIVE VERSION IS NOT WALKED BACKWARDS ─────────────────────────────────────────
    //
    // A run that re-derives the identity of the version currently SERVING has nothing to publish.
    // Taking it to `parsing` would drop a published version out of retrieval for the length of a
    // run whose only outcome is the row that is already there — NN5 says the previous version
    // serves until the new one is verified, and there is no new one.
    $send(28, SourceState::Parsing, ['version' => $contentA])
        ->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.reason', 'live_version_unchanged')
        ->assertJsonPath('data.status', SourceState::Ready->value);

    expect(SourceVersion::query()->withoutGlobalScopes()
        ->where('ingest_key', '=', $contentA['ingest_key'])->sole()->status)->toBe(SourceState::Ready);
    expect($f['item']->fresh()?->current_version_id)->toBe($versionA->id);
});

it('walks a run to a verified publication and switches the active-version pointer', function (): void {
    $f = ingestionRunFixture();

    $identity = ingestionIdentity();

    $send = function (int $sequence, SourceState $status, array $extra = []) use ($f): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra);
    };

    $send(1, SourceState::Fetching)->assertOk();

    // THE IDENTITY ARRIVES WHEN THE RUN HAS RESOLVED IT, which for a crawl is after the fetch. The
    // version row is born at THIS status rather than at `queued` and then transitioned, because
    // there is no prior state to transition from — the row did not exist a statement ago, and
    // `queued` cannot reach `parsing` in the table.
    $send(2, SourceState::Parsing, ['version' => $identity])->assertOk();

    $version = SourceVersion::query()->withoutGlobalScopes()
        ->where('source_item_id', '=', $f['item']->id)->sole();

    expect($version->version_number)->toBe(1);
    expect($version->ingest_key)->toBe($identity['ingest_key']);
    expect($version->status)->toBe(SourceState::Parsing);
    expect($version->activated_at)->toBeNull();

    foreach ([3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $seq => $status) {
        $send($seq, $status, ['version' => $identity])->assertOk();
    }

    // NOT RETRIEVABLE AT `indexing`, AND THIS IS THE STATE WHOSE READING MATTERS MOST: the points
    // are already in the collection and the active-version pointer still names the previous
    // version. Anything that treats "points are present" as "the version is live" has re-invented
    // the half-a-document bug.
    expect($f['item']->fresh()?->current_version_id)->toBeNull();

    // ── THE VERIFICATION GATE ────────────────────────────────────────────────────────────────
    //
    // The same frame WITHOUT `verified` is refused. `canTransitionTo()` defaults the flag to false
    // precisely so a caller who forgot to thread the verification result through is refused loudly
    // rather than publishing a version that indexed half a document — which fails nowhere and shows
    // up months later as a bot that knows the first eleven pages of a contract.
    $send(7, SourceState::Ready, ['version' => $identity])
        ->assertStatus(422)->assertJsonPath('error_class', 'validation');

    expect($f['source']->fresh()?->status)->toBe(SourceState::Indexing);

    $send(7, SourceState::Ready, ['version' => $identity, 'verified' => true, 'chunk_count' => 42])
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('data.status', SourceState::Ready->value);

    $version = $version->fresh();
    $item = $f['item']->fresh();

    expect($version?->status)->toBe(SourceState::Ready);
    expect($version?->activated_at)->not->toBeNull();
    expect($version?->retired_at)->toBeNull();
    // ACTIVATION IS A POINTER, NEVER A STATUS COLUMN. `source_items.current_version_id` is the only
    // thing that makes a version live, and the partial unique index is what makes the switch
    // atomic — a boolean lets two rows both claim it after a race with no single statement able to
    // flip them together.
    expect($item?->current_version_id)->toBe($version?->id);

    $row = AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_VERSION_ACTIVATED)->sole();

    expect($row->details['version_number'] ?? null)->toBe(1);
    expect($row->details['chunk_count'] ?? null)->toBe(42);
    expect($row->details['ingest_key'] ?? null)->toBe($identity['ingest_key']);
    expect($row->details['embedding_model_version'] ?? null)->toBe($identity['embedding_model_version']);
    // NO ACTOR. The pointer switch is the pipeline's, reported by a signed internal callback;
    // attributing it to the admin who created the source would be a claim the trail cannot support.
    expect($row->actor_id)->toBeNull();
});

it('retires the prior version when a second one publishes, and never before', function (): void {
    $f = ingestionRunFixture();

    $first = ingestionIdentity('first');
    $second = ingestionIdentity('second');

    $send = function (int $sequence, SourceState $status, array $extra = []) use ($f): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra);
    };

    // THE WHOLE STAGE ORDER, because the transition table has no shortcuts: `fetching` cannot
    // reach `indexing`, and a test that skipped stages would be asserting against a machine this
    // application does not implement.
    $walk = function (int $from, array $identity) use ($send): int {
        $stages = [
            SourceState::Fetching, SourceState::Parsing, SourceState::Normalizing,
            SourceState::Chunking, SourceState::Embedding, SourceState::Indexing,
        ];

        foreach ($stages as $offset => $status) {
            // The identity rides from the SECOND frame on: a crawl resolves its content hash only
            // after fetching, so `fetching` is the one stage that legitimately has no version yet.
            $send($from + $offset, $status, $offset === 0 ? [] : ['version' => $identity])->assertOk();
        }

        return $from + count($stages);
    };

    $next = $walk(1, $first);

    $send($next, SourceState::Ready, ['version' => $first, 'verified' => true, 'chunk_count' => 5])
        ->assertOk();

    $firstVersion = SourceVersion::query()->withoutGlobalScopes()
        ->where('ingest_key', '=', $first['ingest_key'])->sole();

    // A SECOND RUN over the same item, with a different ingest key — which is what a genuine
    // content change or a reprocess produces.
    KnowledgeSource::query()->withoutGlobalScopes()->whereKey($f['source']->id)
        ->update(['status' => SourceState::Queued->value]);

    $next = $walk($next + 1, $second);

    // STILL LIVE. The prior version keeps serving every query until the new one is indexed AND
    // verified — retiring it at `indexing` is the window in which the bot knows nothing.
    expect($f['item']->fresh()?->current_version_id)->toBe($firstVersion->id);
    expect($firstVersion->fresh()?->retired_at)->toBeNull();

    $send($next, SourceState::Ready, ['version' => $second, 'verified' => true, 'chunk_count' => 6])
        ->assertOk()->assertJsonPath('data.activated', true);

    $secondVersion = SourceVersion::query()->withoutGlobalScopes()
        ->where('ingest_key', '=', $second['ingest_key'])->sole();

    expect($secondVersion->version_number)->toBe(2);
    expect($f['item']->fresh()?->current_version_id)->toBe($secondVersion->id);
    expect($firstVersion->fresh()?->retired_at)->not->toBeNull();

    // VERIFY BEFORE READY, READY BEFORE SWITCH, SWITCH BEFORE RETIRE — and the trail records the
    // retirement as PART OF A PUBLISH, which is what `superseded_by_version_id` distinguishes from
    // a version simply withdrawn by an archive or a delete.
    $retired = AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_VERSION_RETIRED)->sole();

    expect($retired->details['superseded_by_version_id'] ?? null)->toBe($secondVersion->id);
    expect($retired->subject_id)->toBe($firstVersion->id);
});

it('refuses an EMPTY version object, which required_with reads as absent', function (): void {
    $f = ingestionRunFixture();

    // ── THE ONE SHAPE THAT FELL THROUGH ─────────────────────────────────────────────────────
    //
    // `Validator::validateRequired()` treats `[]` as absent, so `required_with:version` fired on
    // none of the six component rules and `{"version": {}}` validated clean. `toFrame()` then
    // handed the empty array to `VersionIdentity::fromArray()`, whose `str()` raises on the first
    // missing key — a 500 classed `internal_dependency`, which the taxonomy marks RETRYABLE, so
    // the worker redelivered a frame that would fail identically forever.
    postIngestionFrame($f['org']->id, [
        'job_id' => $f['item']->current_job_id,
        'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id,
        'sequence' => 1,
        'stage' => 'parsing',
        'status' => SourceState::Parsing->value,
        'version' => [],
    ])->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['version']);

    // A PARTIAL-BUT-NON-EMPTY IDENTITY ALWAYS 422'D CORRECTLY, and still does — the components are
    // `required_with` precisely because a half-identity would mint a version whose ingest key
    // describes a different run than the row it sits on.
    postIngestionFrame($f['org']->id, [
        'job_id' => $f['item']->current_job_id,
        'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id,
        'sequence' => 1,
        'stage' => 'parsing',
        'status' => SourceState::Parsing->value,
        'version' => ['content_hash' => hash('sha256', 'x')],
    ])->assertStatus(422)->assertJsonPath('error_class', 'validation');

    expect(SourceVersion::query()->withoutGlobalScopes()
        ->where('source_item_id', '=', $f['item']->id)->count())->toBe(0);
});

it('refuses to mint a version straight into a ready state', function (): void {
    $f = ingestionRunFixture();

    // A SINGLE FABRICATED FRAME MUST NOT BE ABLE TO PUBLISH. Publication is the act of superseding
    // something, and nothing was verified against a row that did not exist a statement ago — so a
    // birth into either Ready flavour is refused even when the frame claims a verification.
    postIngestionFrame($f['org']->id, [
        'job_id' => $f['item']->current_job_id,
        'source_id' => $f['source']->id,
        'source_item_id' => $f['item']->id,
        'sequence' => 1,
        'stage' => 'publish',
        'status' => SourceState::Ready->value,
        'verified' => true,
        'version' => ingestionIdentity(),
    ])->assertStatus(422)->assertJsonPath('error_class', 'validation');

    expect(SourceVersion::query()->withoutGlobalScopes()->count())->toBe(0);
    expect($f['item']->fresh()?->current_version_id)->toBeNull();
});

// ── the delivery counter ─────────────────────────────────────────────────────────────────────────

it('carries the durable delivery counter, as a ceiling rather than an increment', function (): void {
    $f = ingestionRunFixture();

    $identity = ingestionIdentity();

    $send = function (int $sequence, SourceState $status, array $extra = []) use ($f, $identity): TestResponse {
        return postIngestionFrame($f['org']->id, [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
            'version' => $identity,
        ] + $extra);
    };

    // THE GAP THIS FIELD CLOSES. `app/ingestion/tasks.py` bumps a durable per-version redelivery
    // count as its step 2 and CANNOT write the column: `source_versions` is not in `ALLOWED_TABLES`
    // and must never be, because ADR-033 property 2 fails the moment Laravel serves the row. So the
    // counter rides the callback that already crosses the seam on every delivery.
    $send(1, SourceState::Fetching, ['delivery_count' => 1])->assertOk();

    $version = SourceVersion::query()->withoutGlobalScopes()->sole();

    expect($version->delivery_count)->toBe(1);

    $send(2, SourceState::Parsing, ['delivery_count' => 3])->assertOk();

    expect($version->fresh()?->delivery_count)->toBe(3);

    // A CEILING, NOT AN INCREMENT: a frame reporting a LOWER value cannot rewind a counter whose
    // whole purpose is to bound redelivery, and a frame replayed past the sequence guard cannot
    // double-count.
    $send(3, SourceState::Normalizing, ['delivery_count' => 2])->assertOk();

    expect($version->fresh()?->delivery_count)->toBe(3);

    // AND A VALUE ABOVE THE DATA PLANE'S `MAX_DELIVERIES` IS ACCEPTED. There is deliberately no
    // CHECK constraint and no validation maximum at 3: the worker READS a value above the cap to
    // decide to give up, so a rule there would refuse the very frame that reports the give-up —
    // inside the task that is trying to leave the retry loop.
    $send(4, SourceState::Chunking, ['delivery_count' => 9])->assertOk();

    expect($version->fresh()?->delivery_count)->toBe(9);
});

// ── the accepted set and the read set (findings B2 and S1) ───────────────────────────────────────

it('refuses every spelling of `verified` that it would then read as NOT verified', function (array $spelling): void {
    // ── FINDING B2 ────────────────────────────────────────────────────────────────────────────
    //
    // Laravel's `boolean` rule accepts `true`, `false`, `1`, `0`, `"1"` and `"0"`, and `validated()`
    // performs NO cast — it returns the input. `toFrame()` reads the flag with `=== true`, which is
    // the correct way to read a gate whose false value must be unambiguous, and `1 === true` is
    // `false`. So a frame carrying `"verified": 1` PASSED validation as a well-formed verification
    // claim and was applied as its opposite: the version did not activate, `Indexing -> Ready` was
    // refused, and the caller got `200 {applied: true, activated: false}` — indistinguishable from
    // an ordinary mid-run frame unless the worker inspects `activated`.
    //
    // WHAT MAKES IT A PUBLICATION BUG RATHER THAN A COSMETIC ONE: any serialization that yields `1`
    // instead of a JSON `true` — `int(chunks_ok)`, a numpy bool, a value round-tripped through a
    // Celery payload — means the source NEVER PUBLISHES and the previous version serves forever,
    // with a 200 on every frame. A 422 naming the field is what a worker author can act on.
    $f = ingestionRunFixture();

    $identity = ingestionIdentity();

    $send = fn (int $sequence, SourceState $status, array $extra = []): TestResponse => postIngestionFrame(
        $f['org']->id,
        [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
            'version' => $identity,
        ] + $extra,
    );

    $send(1, SourceState::Fetching)->assertOk();

    foreach ([2 => SourceState::Parsing, 3 => SourceState::Normalizing, 4 => SourceState::Chunking, 5 => SourceState::Embedding, 6 => SourceState::Indexing] as $seq => $status) {
        $send($seq, $status)->assertOk();
    }

    $send(7, SourceState::Ready, ['verified' => $spelling['value']])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        // THE FIELD IS NAMED. A 422 with no `errors` map is the deliberate-refusal shape a client
        // reserves for something else entirely; a malformed claim has to say which key was wrong.
        ->assertJsonStructure(['errors' => ['verified']]);

    // NOTHING WAS APPLIED AND NOTHING PUBLISHED. The refusal has to be at the boundary rather than
    // downstream of a write, or the frame has already moved the run before being rejected.
    expect($f['item']->fresh()?->current_version_id)->toBeNull();
    expect($f['source']->fresh()?->status)->toBe(SourceState::Indexing);

    // THE POSITIVE CONTROL. A literal JSON `true` on the very same frame publishes — so the
    // refusals above are the rule doing its job and not the run being stuck for some other reason.
    $send(7, SourceState::Ready, ['verified' => true])
        ->assertOk()
        ->assertJsonPath('data.activated', true);
})->with([
    // THE FOUR SPELLINGS `boolean` ADMITTED AND `=== true` READ AS FALSE. `"true"` and `"false"`
    // are deliberately absent: neither is an accepted `boolean` value, so both were already 422s,
    // and the comment that used to defend the strict read cited `"false"` — the one value that
    // never needed defending — while the four below went unaddressed.
    'integer one' => [['value' => 1]],
    'integer zero' => [['value' => 0]],
    'string one' => [['value' => '1']],
    'string zero' => [['value' => '0']],
]);

it('refuses a list-shaped warning_summary instead of storing it as numeric keys', function (): void {
    // ── FINDING S1 ────────────────────────────────────────────────────────────────────────────
    //
    // `array` accepts both JSON spellings, because PHP has one type for both. The rule's comment
    // used to say a list "would be a constraint violation rendered as a 500" — it would not, and
    // the value never reaches the constraint as a list: `JsonObjectCast::set()` does
    // `json_encode((object) $value)`, and `(object) ["ocr_low","table_unplaced"]` becomes
    // `{"0":"ocr_low","1":"table_unplaced"}`. `jsonb_typeof` is `object`, so
    // `source_versions_warning_summary_is_object` passes; unlike `bots.theme` this column has no
    // key-set CHECK behind it. The frame was accepted 200 `applied: true` and the detail projection
    // then published `warnings: [{code: "0", versions: 1}]`, which nothing on either plane can tell
    // from a real warning code — `SourceWarningResource` correctly puts no enum on `code`, because
    // the vocabulary belongs to the data plane.
    $f = ingestionRunFixture();

    $frame = fn (int $sequence, SourceState $status, array $extra): TestResponse => postIngestionFrame(
        $f['org']->id,
        [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra,
    );

    // `queued -> fetching` first: the identity is resolved during the run, so the version row is
    // born on the frame after it, exactly as the publication walk above does it.
    $frame(1, SourceState::Fetching, [])->assertOk();

    // A REFUSED FRAME DOES NOT ADVANCE `progress_sequence`, so every attempt below is sequence 2
    // and the successful one lands there too — which is also a small proof that the 422s wrote
    // nothing.
    $send = fn (array $extra): TestResponse => $frame(2, SourceState::Parsing, ['version' => ingestionIdentity()] + $extra);

    $send(['warning_summary' => ['ocr_low', 'table_unplaced']])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['warning_summary']]);

    // A MAP WITH NUMERIC KEYS IS THE SAME DEFECT REACHED FROM THE OTHER SIDE, and a list check
    // alone would miss it: `json_decode` turns the object key `"0"` into the PHP integer key `0`,
    // so `{"0":"a","2":"b"}` is not a list and would have been stored exactly as the mangling
    // above produces.
    $send(['warning_summary' => ['0' => 'ocr_low', '2' => 'table_unplaced']])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');

    expect(SourceVersion::query()->withoutGlobalScopes()->count())->toBe(0);

    // POSITIVE CONTROL, BOTH WAYS. A name-keyed map is accepted and reaches the column, and an
    // EMPTY value is accepted too — `[]` and `{}` decode to the same PHP value, so "the empty list"
    // is not a distinguishable input to refuse, and the cast writes either as `{}`.
    $send(['warning_summary' => ['ocr_low_confidence' => 3]])->assertOk();

    $version = SourceVersion::query()->withoutGlobalScopes()->sole();

    expect($version->warning_summary)->toBe(['ocr_low_confidence' => 3]);

    $frame(3, SourceState::Normalizing, [
        'version' => ingestionIdentity(),
        'warning_summary' => [],
    ])->assertOk();
});

it('bounds warning_summary in both dimensions, and accepts the last legal frame', function (): void {
    // ── THE KEY SET IS THE DATA PLANE'S, WHICH IS EXACTLY WHY IT NEEDS A CEILING ──────────────
    //
    // `JsonObjectMap` closed the SHAPE last batch — an object keyed by name, never a list — and
    // left both SIZES open: a frame with ten thousand keys, or one key of four kilobytes, was
    // accepted and published verbatim as `warnings[].code` on the detail projection. This is a
    // signed service-to-service seam, so the caller is a worker of ours rather than an attacker;
    // the refusal is sized to be unreachable by correct code and to stop a runaway loop, not to be
    // tight. See `IngestionCallbackRequest::MAX_WARNING_CODES` for where 416 comes from.
    $f = ingestionRunFixture();

    $frame = fn (int $sequence, SourceState $status, array $extra): TestResponse => postIngestionFrame(
        $f['org']->id,
        [
            'job_id' => $f['item']->current_job_id,
            'source_id' => $f['source']->id,
            'source_item_id' => $f['item']->id,
            'sequence' => $sequence,
            'stage' => $status->value,
            'status' => $status->value,
        ] + $extra,
    );

    $frame(1, SourceState::Fetching, [])->assertOk();

    // A REFUSED FRAME DOES NOT ADVANCE `progress_sequence`, so every attempt below is sequence 2.
    $send = fn (array $summary): TestResponse => $frame(
        2,
        SourceState::Parsing,
        ['version' => ingestionIdentity(), 'warning_summary' => $summary],
    );

    $codes = fn (int $count): array => array_fill_keys(
        array_map(static fn (int $i): string => 'ocr_low_coverage:'.$i, range(1, $count)),
        1,
    );

    $send($codes(IngestionCallbackRequest::MAX_WARNING_CODES + 1))
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        // THE FIELD IS NAMED. A 422 with no `errors` map is a different refusal shape entirely, and
        // a worker author reading this response has to be told which key was wrong.
        ->assertJsonStructure(['errors' => ['warning_summary']]);

    // THE LENGTH BOUND IS A SEPARATE DIMENSION AND A SEPARATE PROBE. One long key inside a legal
    // count passes the `max:` rule and is caught by the rule object, which is the half that cannot
    // appear in the published manifest — so it is the half most likely to be assumed rather than
    // checked.
    $send([str_repeat('a', IngestionCallbackRequest::MAX_WARNING_CODE_LENGTH + 1) => 1])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['warning_summary']]);

    // NOTHING WAS WRITTEN BY EITHER REFUSAL. The bound has to be at the boundary rather than
    // downstream of the version insert, or the frame has already landed before being rejected.
    expect(SourceVersion::query()->withoutGlobalScopes()->count())->toBe(0);

    // ── THE POSITIVE CONTROL, AT THE BOUNDARY RATHER THAN NEAR IT ────────────────────────────
    //
    // Exactly `MAX_WARNING_CODES` keys, one of them exactly `MAX_WARNING_CODE_LENGTH` characters.
    // An off-by-one in either bound refuses this frame, and a suite that only probed the refusals
    // would go green over a rule that rejects every legitimate frame as well.
    $atTheLimit = $codes(IngestionCallbackRequest::MAX_WARNING_CODES - 1)
        + [str_repeat('a', IngestionCallbackRequest::MAX_WARNING_CODE_LENGTH) => 1];

    expect($atTheLimit)->toHaveCount(IngestionCallbackRequest::MAX_WARNING_CODES);

    $send($atTheLimit)->assertOk();

    $version = SourceVersion::query()->withoutGlobalScopes()->sole();

    expect($version->warning_summary)->toHaveCount(IngestionCallbackRequest::MAX_WARNING_CODES);
});

<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Jobs\SyncBotAccessJob;
use App\Jobs\SyncSourceStatusJob;
use App\Models\Bot;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Models\User;
use App\Services\Internal\InternalRequestSigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The two payload-maintenance ops — Laravel's half
|--------------------------------------------------------------------------
|
| `source_status` and `bot_ids` are the two of the four mandatory Qdrant filter terms that CHANGE
| AFTER THE WRITE, from the control plane, with no re-ingest. Until these jobs existed a disable
| was a column change nobody's query noticed and a deleted bot's id stayed in every payload that
| named it.
|
| WHAT IS ASSERTED HERE: that the admin actions dispatch, that the dispatch carries the right scope
| and the right direction, that the request is SIGNED and carries every collection the source's
| versions live in, that a report which does not verify is a failure rather than a 200, and that
| the compensation is asymmetric on purpose — an enable is reverted and a disable is not.
|
| `Http::fake()` is legitimate here for the same reason it is in `SubmitIngestionJobTest`: these are
| buffered request/response calls with no stream, so none of the buffering, heartbeat or disconnect
| bugs `pest-testing` bans the fake for can hide behind it.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');
});

const SYNC_IDENTITY = 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1';
const SYNC_SECOND_IDENTITY = 'emb/v1:openai:text-embedding-3-small:d1536:1122334455aa';

/**
 * An organization, its owner (signed in), and a source in a named state with one item.
 *
 * BUILT FROM FACTORIES AND NOT THROUGH `POST /sources`, unlike `SubmitIngestionJobTest`. The create
 * endpoint leaves a source at `queued`, and `queued -> disabled` is deliberately not an edge of
 * `SourceState::transitionTable()` — the state this file needs is the one a source reaches after a
 * version has published, which is what `syncVersion()` supplies.
 *
 * @return array{org: Organization, source: KnowledgeSource, item: SourceItem}
 */
function syncFixture(string $label, SourceState $status = SourceState::Ready): array
{
    $org = Organization::factory()->create([
        'name' => 'Sync Org', 'slug' => 'sync-'.$label.'-'.Str::lower((string) Str::ulid()),
    ]);

    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('sync-'.$label)]);

    SpaSession::establish(currentTest(), $owner);

    $source = KnowledgeSource::factory()->recycle($org)->status($status)->create();

    $item = new SourceItem;
    $item->organization_id = $org->id;
    $item->source_id = $source->id;
    $item->canonical_key = 'text:'.$source->id;
    $item->save();

    return ['org' => $org, 'source' => $source, 'item' => $item];
}

/**
 * One `source_versions` row under an explicit embedding identity, optionally activated.
 *
 * The identity is the parameter that matters to this file: it is what the collection name is
 * derived from, so two versions under two identities are two collections and are the fixture for
 * "a source re-indexed after an embedding-model change".
 */
function syncVersion(
    Organization $org,
    SourceItem $item,
    int $number,
    string $identity,
    bool $activate = false,
): SourceVersion {
    $version = new SourceVersion;
    $version->organization_id = $org->id;
    $version->source_item_id = $item->id;
    $version->version_number = $number;
    $version->content_hash = hash('sha256', $item->id.':content:'.$number);
    $version->ingest_key = hash('sha256', $item->id.':key:'.$number);
    $version->parser_cfg_version = 'parser/v1:docling-2.118';
    $version->ocr_cfg_version = 'ocr/v1:rapidocr';
    $version->chunker_cfg_version = 'chunker/v1:structure';
    $version->embedding_model_version = $identity;
    $version->status = SourceState::Ready;

    if ($activate) {
        $version->activated_at = CarbonImmutable::now();
    }

    $version->save();

    if ($activate) {
        SourceItem::withoutGlobalScopes()->whereKey($item->id)
            ->update(['current_version_id' => $version->id]);
    }

    return $version;
}

/**
 * A 200 report from the far side, in the shape `syncPayload()` insists on.
 *
 * @return array{operation: string, passed: bool, rewritten: int, verified: int, collections: list<array<string, mixed>>}
 */
function syncReport(bool $passed = true, int $rewritten = 4, int $verified = 4): array
{
    return [
        'operation' => 'source.status.sync',
        'passed' => $passed,
        'rewritten' => $rewritten,
        'verified' => $verified,
        'collections' => [],
    ];
}

// ── the dispatch, from the admin actions ──────────────────────────────────────

it('dispatches a disabled sync with no revert when a source is disabled', function (): void {
    $queue = Queue::fake();
    $f = syncFixture('disable');
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);

    currentTest()->putJson(
        "/api/v1/organizations/{$f['org']->id}/sources/{$f['source']->id}/status",
        ['status' => 'disabled'],
        spaHeaders(),
    )->assertOk();

    $job = $queue->pushed(SyncSourceStatusJob::class)->first();

    expect($job)->not->toBeNull();
    expect($job->sourceStatus)->toBe('disabled');
    // NULL ON PURPOSE. A disable that never reaches the index must not put the row back to
    // `ready`: that would tell the operator the source is ready — true of the index and the
    // opposite of what they asked for — and discard the only durable record that they asked.
    expect($job->revertTo)->toBeNull();
});

it('dispatches a ready sync with a revert when a source is enabled', function (): void {
    $queue = Queue::fake();
    $f = syncFixture('enable', SourceState::Disabled);
    // ACTIVATED, because a source with no active version cannot be enabled at all — the enable
    // path refuses it rather than showing a green pill with no corpus behind it.
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);

    $base = "/api/v1/organizations/{$f['org']->id}/sources/{$f['source']->id}/status";
    currentTest()->putJson($base, ['status' => 'ready'], spaHeaders())->assertOk();

    $job = $queue->pushed(SyncSourceStatusJob::class)
        ->first(fn (SyncSourceStatusJob $j): bool => $j->sourceStatus === 'ready');

    expect($job)->not->toBeNull();
    // `ready` AND NOT the row's flavour: the indexer writes the plain `ready` on every point, so
    // that is the value the index has actually held, and the far side refuses the other one.
    expect($job->sourceStatus)->toBe('ready');
    expect($job->revertTo)->toBe('disabled');
});

// ── the wire ──────────────────────────────────────────────────────────────────

it('signs the status sync and sends every collection the source lives in', function (): void {
    Queue::fake();
    $f = syncFixture('wire', SourceState::Disabled);
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);
    // A SECOND IDENTITY: this source was re-indexed after the organization changed embedding
    // model, so its points are in two collections. A rewrite that addressed only the active
    // version's would verify the collection it was given and leave the other half answering.
    syncVersion($f['org'], $f['item'], 2, SYNC_SECOND_IDENTITY);
    // A DUPLICATE of the first, which must not double the list: the far side counts per
    // collection, and addressing one twice makes the totals stop meaning "points in this source".
    syncVersion($f['org'], $f['item'], 3, SYNC_IDENTITY);

    Http::fake(['*/internal/v1/maintenance/source-status' => Http::response(syncReport(), 200)]);

    $job = new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'disabled');

    $before = microtime(true);
    app()->call([$job, 'handle']);
    $after = microtime(true);

    Http::assertSent(function (Request $request) use ($f, $before, $after): bool {
        $body = $request->body();
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            if (stripos($name, 'x-kb-') === 0 && strcasecmp($name, 'X-KB-Signature') !== 0) {
                $headers[$name] = (string) $values[0];
            }
        }

        // THE SIGNED BYTES ARE THE SENT BYTES.
        $keyId = (string) config('services.ai.hmac.active');

        /** @var array<string, string> $keys */
        $keys = (array) config('services.ai.hmac.keys');

        $expected = (new InternalRequestSigner((string) config('kb.signing_prefix'), $keyId, $keys[$keyId]))
            ->sign('POST', '/internal/v1/maintenance/source-status', $body, $headers);

        expect($request->header('X-KB-Signature')[0] ?? null)->toBe($expected);
        expect($headers['X-KB-Org-Id'] ?? null)->toBe($f['org']->id);
        expect($headers['X-KB-Operation'] ?? null)->toBe('source.status.sync');

        // REQUIRED ON A MUTATION, and DERIVED from the instruction so two deliveries of one
        // instruction carry one key.
        expect($headers['X-KB-Idempotency-Key'] ?? null)->toBe(hash(
            'sha256',
            $f['org']->id."\x1fsource.status.sync\x1f".$f['source']->id."\x1fdisabled",
        ));

        // MEASURED FROM THE MOMENT THE JOB RAN, not from `LARAVEL_START` — the worker's boot,
        // which would send a deadline already in the past (finding Q2/B1). The bounds are the
        // clock either side of `handle()`, so only a value computed inside that window passes.
        $budgetMs = (int) round((float) config('kb.timeouts.maintenance') * 1000);

        expect((int) ($headers['X-KB-Deadline'] ?? 0))
            ->toBeGreaterThanOrEqual((int) round($before * 1000) + $budgetMs)
            ->toBeLessThanOrEqual((int) round($after * 1000) + $budgetMs);

        /** @var array{source_id: string, source_status: string, embedding_model_versions: list<string>} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded['source_id'])->toBe($f['source']->id);
        expect($decoded['source_status'])->toBe('disabled');
        expect($decoded['embedding_model_versions'])
            ->toHaveCount(2)
            ->toContain(SYNC_IDENTITY)
            ->toContain(SYNC_SECOND_IDENTITY);

        // NO TENANT IN THE BODY. The organization is the signed header; a body-supplied tenant is
        // forgeable against a signature that does not cover it.
        expect(array_key_exists('org_id', $decoded))->toBeFalse();

        return true;
    });
});

it('treats a report that did not verify as a failure rather than a success', function (): void {
    Queue::fake();
    $f = syncFixture('unverified', SourceState::Disabled);
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);

    Http::fake([
        '*/internal/v1/maintenance/source-status' => Http::response(syncReport(passed: false), 200),
    ]);

    $job = new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'disabled');

    // A 200 WHOSE COUNTS DO NOT AGREE IS NOT A SUCCESS. `set_payload` returns the same
    // acknowledgement whether it rewrote ten thousand points or none, which is exactly why the far
    // side spends a filtered count — and why reading `passed` is not optional here.
    expect(fn () => app()->call([$job, 'handle']))->toThrow(\App\Exceptions\KbException::class);
});

it('sends nothing when the row has moved on since the dispatch', function (): void {
    Queue::fake();
    $f = syncFixture('superseded');
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);

    Http::fake(['*' => Http::response(syncReport(), 200)]);

    // The job says `disabled`; the row says `ready`. Applying it would rewrite the payload
    // BACKWARDS and the far side would verify that rewrite and report a pass.
    $job = new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'disabled');

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});

it('sends nothing for a source that has never been indexed', function (): void {
    Queue::fake();
    $f = syncFixture('unindexed', SourceState::Disabled);

    Http::fake(['*' => Http::response(syncReport(), 200)]);

    $job = new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'disabled');

    app()->call([$job, 'handle']);

    // NOT the same as a rewrite that found no points: the far side REFUSES an empty collection
    // set, because zero collections and zero points report identical counts. The emptiness is
    // decided here, where the reason is knowable.
    Http::assertNothingSent();
});

// ── the compensation ──────────────────────────────────────────────────────────

it('reverts an enable that never reached the index, and leaves a disable alone', function (): void {
    Queue::fake();
    $f = syncFixture('compensate', SourceState::Disabled);
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);

    $base = "/api/v1/organizations/{$f['org']->id}/sources/{$f['source']->id}/status";
    currentTest()->putJson($base, ['status' => 'ready'], spaHeaders())->assertOk();

    (new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'ready', revertTo: 'disabled'))
        ->failed(new \RuntimeException('index unreachable'));

    // THE CONSOLE NOW AGREES WITH WHAT THE INDEX WILL ACTUALLY DO, and the operator's obvious next
    // action — enabling again — re-runs the sync.
    expect(KnowledgeSource::query()->withoutGlobalScopes()->findOrFail($f['source']->id)->status)
        ->toBe(SourceState::Disabled);

    // Back to `ready`, then a disable — so the second half runs against a row that says
    // `disabled`, which is what a disable's `failed()` must leave alone.
    currentTest()->putJson($base, ['status' => 'ready'], spaHeaders())->assertOk();
    currentTest()->putJson($base, ['status' => 'disabled'], spaHeaders())->assertOk();

    (new SyncSourceStatusJob($f['org']->id, $f['source']->id, 'disabled'))
        ->failed(new \RuntimeException('index unreachable'));

    // LEFT ALONE. Reverting would say `ready`, which is what the index says and the opposite of
    // what the operator asked for; the `failed_jobs` row is the truth.
    expect(KnowledgeSource::query()->withoutGlobalScopes()->findOrFail($f['source']->id)->status)
        ->toBe(SourceState::Disabled);
});

// ── bot access ────────────────────────────────────────────────────────────────

it('dispatches a scoped grant when a bot is assigned to a source', function (): void {
    $queue = Queue::fake();
    $f = syncFixture('grant');
    $bot = Bot::factory()->recycle($f['org'])->create();

    currentTest()->postJson(
        "/api/v1/organizations/{$f['org']->id}/bots/{$bot->id}/source-assignments",
        ['source_id' => $f['source']->id],
        spaHeaders(),
    )->assertCreated();

    $job = $queue->pushed(SyncBotAccessJob::class)->first();

    expect($job)->not->toBeNull();
    expect($job->grant)->toBeTrue();
    expect($job->botId)->toBe($bot->id);
    // SCOPED, never null: a null scope on a grant would assign this bot to every source the
    // tenant owns.
    expect($job->sourceIds)->toBe([$f['source']->id]);
});

it('dispatches nothing for a grant that is created disabled', function (): void {
    $queue = Queue::fake();
    $f = syncFixture('grant-off');
    $bot = Bot::factory()->recycle($f['org'])->create();

    currentTest()->postJson(
        "/api/v1/organizations/{$f['org']->id}/bots/{$bot->id}/source-assignments",
        ['source_id' => $f['source']->id, 'enabled' => false],
        spaHeaders(),
    )->assertCreated();

    // A disabled assignment grants nothing, and the indexer agrees — it writes only ENABLED
    // assignments into `bot_ids`. Putting the id in the payload would make the filter match for a
    // bot whose operator has switched it off.
    expect($queue->pushed(SyncBotAccessJob::class))->toHaveCount(0);
});

it('dispatches an organization-wide revoke when a bot is deleted', function (): void {
    $queue = Queue::fake();
    $f = syncFixture('bot-delete');
    $bot = Bot::factory()->recycle($f['org'])->create();

    // 200 AND NOT 204: every success body on this surface carries a `data` key, and this route
    // answers with an acknowledgement rather than the deleted resource.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$f['org']->id}/bots/{$bot->id}",
        [],
        spaHeaders(),
    )->assertOk();

    $job = $queue->pushed(SyncBotAccessJob::class)->first();

    expect($job)->not->toBeNull();
    expect($job->grant)->toBeFalse();
    // NULL, and it has to be: the assignments were withdrawn inside the delete's transaction, so
    // by dispatch time there is nothing left to enumerate. It is also the only scope that catches
    // a source whose grant was withdrawn earlier and whose payload still holds the id.
    expect($job->sourceIds)->toBeNull();
});

it('refuses to construct an organization-wide grant at all', function (): void {
    // REFUSED IN THE CONSTRUCTOR so it cannot even be serialized onto the queue: catching it at
    // execution time would put a job in `failed_jobs` whose payload describes an operation no
    // contract offers, which reads to an operator as a transient failure worth replaying.
    expect(fn () => new SyncBotAccessJob('01J00000000000000000000000', '01J0000000000000000000000B', true, null))
        ->toThrow(\InvalidArgumentException::class);
});

it('signs the bot access sync and carries the direction and the scope', function (): void {
    Queue::fake();
    $f = syncFixture('bot-wire');
    syncVersion($f['org'], $f['item'], 1, SYNC_IDENTITY, activate: true);
    $bot = Bot::factory()->recycle($f['org'])->create();

    Http::fake([
        '*/internal/v1/maintenance/bot-access' => Http::response(
            ['operation' => 'bot.access.sync', 'passed' => true, 'rewritten' => 2, 'verified' => 0,
                'collections' => []],
            200,
        ),
    ]);

    $job = new SyncBotAccessJob($f['org']->id, $bot->id, false, [$f['source']->id]);

    app()->call([$job, 'handle']);

    Http::assertSent(function (Request $request) use ($f, $bot): bool {
        /** @var array{bot_id: string, grant: bool, source_ids: list<string>|null, embedding_model_versions: list<string>} $decoded */
        $decoded = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        expect($decoded['bot_id'])->toBe($bot->id);
        expect($decoded['grant'])->toBeFalse();
        expect($decoded['source_ids'])->toBe([$f['source']->id]);
        expect($decoded['embedding_model_versions'])->toBe([SYNC_IDENTITY]);
        expect($request->header('X-KB-Operation')[0] ?? null)->toBe('bot.access.sync');

        return true;
    });
});

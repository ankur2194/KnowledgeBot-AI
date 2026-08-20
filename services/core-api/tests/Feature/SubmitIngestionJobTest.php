<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Jobs\SubmitIngestionJob;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\Internal\InternalRequestSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| SubmitIngestionJob — the Laravel half of ingestion dispatch
|--------------------------------------------------------------------------
|
| THE DATA PLANE HAS NO INGESTION ROUTER YET, so `Http::fake()` here is not standing in for a
| service that exists — it is the contract this change is written against, and these assertions are
| the specification the other half has to satisfy. That is a legitimate use of a fake and it is not
| the banned one: `pest-testing` bans `Http::fake()` for STREAMING, where a faked body is a string
| the relay drains in microseconds and every buffering, heartbeat and disconnect bug passes. A
| submission is a buffered request/response with no stream at all.
|
| WHAT IS ASSERTED: the request is SIGNED, the idempotency key is DERIVED FROM A STABLE
| IDENTIFIER rather than minted, the body carries no credential and no document text, and the job is
| a NO-OP on both of the two races that can reach it.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');
});

/**
 * A queued source of a fresh organization, created through the real endpoint.
 *
 * @return array{org: Organization, source: KnowledgeSource, item: SourceItem, job: SubmitIngestionJob}
 */
function submitJobFixture(): array
{
    // THE FAKE IS CAPTURED rather than reached for through the facade afterwards: `Queue::pushed()`
    // exists only on the fake, so the facade spelling resolves at runtime and is invisible to the
    // analyser — and an assertion the analyser cannot see is one that can rot into a no-op.
    $queue = Queue::fake();

    $org = Organization::factory()->create([
        'name' => 'Submit Org', 'slug' => 'submit-org-'.Str::lower((string) Str::ulid()),
    ]);

    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('submit-owner')]);

    SpaSession::establish(currentTest(), $owner);

    $created = currentTest()->postJson(
        "/api/v1/organizations/{$org->id}/sources",
        ['type' => 'text', 'name' => 'Refund policy', 'content' => 'Refunds are accepted for 30 days.'],
        spaHeaders(),
    )->assertCreated();

    $source = KnowledgeSource::query()->withoutGlobalScopes()
        ->findOrFail((string) $created->json('data.id'));

    $item = SourceItem::query()->withoutGlobalScopes()->where('source_id', '=', $source->id)->sole();

    return [
        'org' => $org,
        'source' => $source,
        'item' => $item,
        // The job the endpoint DISPATCHED, taken from the fake rather than reconstructed — a
        // hand-built job would assert this test's idea of the arguments instead of the endpoint's.
        'job' => $queue->pushed(SubmitIngestionJob::class)->first(),
    ];
}

it('signs the submission, derives the idempotency key, and sends no credential or content', function (): void {
    $f = submitJobFixture();

    Http::fake([
        '*/internal/v1/ingestion/jobs' => Http::response(['job_id' => $f['job']->jobId], 202),
    ]);

    app()->call([$f['job'], 'handle']);

    Http::assertSent(function (Request $request) use ($f): bool {
        $body = $request->body();
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            if (stripos($name, 'x-kb-') === 0 && strcasecmp($name, 'X-KB-Signature') !== 0) {
                $headers[$name] = (string) $values[0];
            }
        }

        // THE SIGNED BYTES ARE THE SENT BYTES. Re-encoding JSON to hash it is not byte-stable and
        // produces intermittent 401s, so this recomputes the signature over the EXACT body the
        // client sent and requires it to match what the client claimed.
        $keyId = (string) config('services.ai.hmac.active');

        /** @var array<string, string> $keys */
        $keys = (array) config('services.ai.hmac.keys');

        $expected = (new InternalRequestSigner((string) config('kb.signing_prefix'), $keyId, $keys[$keyId]))
            ->sign('POST', '/internal/v1/ingestion/jobs', $body, $headers);

        expect($request->header('X-KB-Signature')[0] ?? null)->toBe($expected);

        // THE TENANT SCOPE IS INSIDE THE SIGNATURE, which is the whole reason the far side may
        // trust it: flip one header and a legitimately signed request would otherwise execute
        // against another organization.
        expect($headers['X-KB-Org-Id'] ?? null)->toBe($f['org']->id);
        expect($headers['X-KB-Operation'] ?? null)->toBe('ingestion.submit');

        // AN ABSOLUTE INSTANT, never a duration and never re-derived downstream.
        expect((int) ($headers['X-KB-Deadline'] ?? 0))->toBeGreaterThan((int) (microtime(true) * 1000));

        // DERIVED, NEVER MINTED. A retry that generated a new key is a duplicate ingestion of the
        // same document — same bytes, same parse, same embedding spend, and two versions racing the
        // pointer — rather than a replay of one.
        expect($headers['X-KB-Idempotency-Key'] ?? null)->toBe(hash(
            'sha256',
            $f['org']->id."\x1fingestion.submit\x1f".implode("\x1f", [
                $f['source']->id, $f['item']->id, 'text', (string) $f['item']->content_hash, '',
            ]),
        ));

        // NO CREDENTIAL AND NO DOCUMENT TEXT ON THE WIRE. The data plane resolves and decrypts the
        // embedding credential at execution time through its own provider accessor, and reads the
        // object itself from the STORAGE KEY below — putting bytes here would put tenant document
        // text in a Valkey job payload, in `failed_jobs`, and in every span that instruments
        // request bodies.
        expect(str_contains($body, 'Refunds are accepted'))->toBeFalse();
        expect(str_contains(strtolower($body), 'credential'))->toBeFalse();

        // DECODED RATHER THAN SUBSTRING-MATCHED for the key itself: `json_encode` escapes the
        // forward slash as `\/` by default, so a raw `str_contains` on a path is a test that fails
        // for a reason that has nothing to do with the property. The two absence assertions above
        // are safe as substrings — neither needle contains an escapable byte — and absence is the
        // direction where a substring is the stronger check anyway.
        /** @var array{items: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded['items'][0]['storage_key'] ?? null)->toBe($f['item']->storage_key);
        expect($decoded['items'][0]['content_hash'] ?? null)->toBe($f['item']->content_hash);
        expect($decoded['items'][0]['current_version_id'] ?? null)->toBeNull();

        return true;
    });
});

it('sends the same idempotency key twice for the same submission, and a different one after a reprocess', function (): void {
    $f = submitJobFixture();

    Http::fake([
        '*/internal/v1/ingestion/jobs' => Http::response(['job_id' => 'accepted'], 202),
    ]);

    $keys = [];

    $capture = function () use (&$keys): void {
        Http::assertSent(function (Request $request) use (&$keys): bool {
            $keys[] = (string) ($request->header('X-KB-Idempotency-Key')[0] ?? '');

            return true;
        });
    };

    app()->call([$f['job'], 'handle']);
    $capture();

    // THE SAME JOB AGAIN — which is what a redelivery is. The source is still `queued` and the job
    // id still current, so the submission is repeated and must be RECOGNISABLE as a replay.
    app()->call([$f['job'], 'handle']);
    $capture();

    expect($keys[0])->toBe($keys[1] ?? null);
    expect($keys[0])->not->toBe('');

    // AND AFTER A REPROCESS THE KEY MOVES, because the force nonce is a component of the
    // fingerprint. Without that, an explicit reprocess of unchanged content would dedupe against
    // the completed run and the admin would see "already processed" for a button that promised the
    // opposite.
    $reprocessed = new SubmitIngestionJob(
        $f['org']->id,
        $f['source']->id,
        (string) $f['item']->current_job_id,
        forceNonce: (string) Str::ulid(),
    );

    app()->call([$reprocessed, 'handle']);
    $capture();

    expect(array_slice($keys, -1)[0])->not->toBe($keys[0]);
});

it('is a no-op when the run has already moved on, or when a newer dispatch superseded it', function (): void {
    $f = submitJobFixture();

    Http::fake();

    // THE RUN ALREADY MOVED ON. A redelivery of this job after the data plane's first callback
    // landed would otherwise submit a second time and mint a second Celery task for a version
    // already parsing. The check reads the ROW rather than trusting the payload.
    KnowledgeSource::query()->withoutGlobalScopes()->whereKey($f['source']->id)
        ->update(['status' => SourceState::Parsing->value]);

    app()->call([$f['job'], 'handle']);

    Http::assertNothingSent();

    // SUPERSEDED BEFORE IT RAN. A second reprocess re-stamped every item while this dispatch sat in
    // the queue; submitting now would put two live runs on one item and both would race the
    // active-version pointer.
    KnowledgeSource::query()->withoutGlobalScopes()->whereKey($f['source']->id)
        ->update(['status' => SourceState::Queued->value]);

    SourceItem::query()->withoutGlobalScopes()->whereKey($f['item']->id)
        ->update(['current_job_id' => (string) Str::ulid()]);

    app()->call([$f['job'], 'handle']);

    Http::assertNothingSent();
});

it('fails the source rather than leaving it queued forever when submission cannot succeed', function (): void {
    $f = submitJobFixture();

    // A submission that never reached the data plane leaves a source sitting in `queued` forever,
    // which reads in the console as "still working" and is indistinguishable from a slow document.
    // `queued -> failed` is an edge of the table, it is reviewable, and — this is the part that
    // matters — the PRIOR VERSION KEEPS SERVING, because failure is terminal for the RUN and not
    // for the item.
    $f['job']->failed(new \RuntimeException('the queue gave up'));

    expect($f['source']->fresh()?->status)->toBe(SourceState::Failed);
});

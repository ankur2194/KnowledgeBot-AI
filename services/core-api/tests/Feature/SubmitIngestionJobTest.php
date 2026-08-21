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

    // The instants either side of the call, so the deadline assertion below can be a SANDWICH
    // rather than a tolerance. See the dedicated deadline test at the foot of this file for why
    // `> now` was not an assertion at all.
    $before = microtime(true);

    app()->call([$f['job'], 'handle']);

    $after = microtime(true);

    Http::assertSent(function (Request $request) use ($f, $before, $after): bool {
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

        // AN ABSOLUTE INSTANT, never a duration and never re-derived downstream — AND MEASURED
        // FROM THE MOMENT THIS JOB RAN. The bounds are the clock either side of `handle()`, so the
        // only value that satisfies both is one computed from a clock read inside that window.
        $budgetMs = (int) round((float) config('kb.timeouts.ingestion') * 1000);

        expect((int) ($headers['X-KB-Deadline'] ?? 0))
            ->toBeGreaterThanOrEqual((int) round($before * 1000) + $budgetMs)
            ->toBeLessThanOrEqual((int) round($after * 1000) + $budgetMs);

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

it('does not force `failed` onto a run that has already started, or onto a newer dispatch', function (): void {
    // ── `failed()` RUNS AFTER THE LAST ATTEMPT, WHICH IS NOT "THE RUN FAILED" ────────────────
    //
    // The submission has a 20 s timeout and the far side does not roll back. A 202 lost to that
    // timeout means the Celery task IS running, its first callback has already moved this source to
    // `parsing`, and stamping `failed` on top of it is worse than the state it replaces:
    // `Failed -> Parsing` is not an edge, so every subsequent frame 422s as `validation` —
    // non-retryable, so the worker gives up — the run completes on the far side, and nothing is
    // ever activated. `handle()` has guarded on both of these since it was written; `failed()`
    // guarded on neither.
    $f = submitJobFixture();

    $f['source']->forceFill(['status' => SourceState::Parsing->value])->save();

    $f['job']->failed(new \RuntimeException('a 202 we never saw'));

    expect($f['source']->fresh()?->status)->toBe(SourceState::Parsing);

    // ── AND SUPERSESSION ────────────────────────────────────────────────────────────────────
    //
    // A second reprocess re-stamped every item while this dispatch was exhausting its attempts. The
    // source is `queued` for the NEW run, and failing it here kills a submission nothing has tried.
    $f['source']->forceFill(['status' => SourceState::Queued->value])->save();

    SourceItem::withoutGlobalScopes()->whereKey($f['item']->id)
        ->update(['current_job_id' => (string) Str::ulid()]);

    $f['job']->failed(new \RuntimeException('superseded before it ran'));

    expect($f['source']->fresh()?->status)->toBe(SourceState::Queued);
});

// ── the deadline epoch (finding B1) ──────────────────────────────────────────────────────────────

it('measures X-KB-Deadline from the moment the job runs, never from the worker\'s boot', function (): void {
    // ── WHAT WENT WRONG, AND WHY THE OLD ASSERTION COULD NOT SEE IT ──────────────────────────
    //
    // `InternalAiClient` computed every deadline from `LARAVEL_START`. That constant is
    // PROCESS-scoped: `public/index.php` and `artisan` are the only two places that define it, and
    // under PHP-FPM one process serves one request, so it IS the request's start. A `queue:work`
    // or Horizon worker is a process that lives for hours, so there it is the WORKER'S BOOT — and
    // `X-KB-Deadline` was therefore `boot + 20 s`, an instant already in the past for any worker up
    // longer than twenty seconds and receding further for the rest of the process's life. The far
    // side converts the header verbatim and clamps the remaining budget at zero, so once the
    // FastAPI ingestion router lands EVERY submission from a warm worker is refused before a byte
    // is parsed, with this service correct in every log.
    //
    // The old assertion here was `deadline > microtime(true) * 1000`, and it could not fail —
    // `phpunit.xml` bootstrapped `vendor/autoload.php`, which defines nothing, so `defined()` was
    // false for the whole suite and the client took its `microtime(true)` fallback, which is
    // correct by construction. The suite exercised the fallback and never the production path.
    expect(defined('LARAVEL_START'))->toBeTrue(
        'LARAVEL_START is not defined in this process, so the client is taking its fallback branch '
        .'and this test is asserting nothing about the branch that ships. tests/bootstrap.php is '
        .'what defines it; if phpunit.xml no longer points at that file, restore it before reading '
        .'this suite as green.',
    );

    $f = submitJobFixture();

    Http::fake([
        '*/internal/v1/ingestion/jobs' => Http::response(['job_id' => $f['job']->jobId], 202),
    ]);

    $before = microtime(true);

    app()->call([$f['job'], 'handle']);

    $after = microtime(true);

    $deadline = 0;

    Http::assertSent(function (Request $request) use (&$deadline): bool {
        $deadline = (int) ($request->header('X-KB-Deadline')[0] ?? 0);

        return true;
    });

    $budget = (float) config('kb.timeouts.ingestion');
    $budgetMs = (int) round($budget * 1000);

    // NOW + THE INGESTION BUDGET, to the millisecond, expressed as the interval it must land in.
    // A tolerance would have to be guessed; these two bounds are measured.
    expect($deadline)
        ->toBeGreaterThanOrEqual((int) round($before * 1000) + $budgetMs)
        ->toBeLessThanOrEqual((int) round($after * 1000) + $budgetMs);

    // THE REGRESSION ASSERTION, NAMED. `LARAVEL_START` in this process is the suite's boot, which
    // stands in for the worker's; the framework has been up for at least one clock tick by the time
    // any test body runs, so a deadline still derived from it is strictly smaller than one derived
    // from the call — and this comparison says so directly rather than inferring it from a window.
    expect($deadline)->toBeGreaterThan(
        (int) round((LARAVEL_START + $budget) * 1000),
        'X-KB-Deadline is measured from LARAVEL_START, which in a queue worker is the worker\'s '
        .'boot and not this job — so every submission from a warm worker carries a deadline in the '
        .'past and is refused before a byte is parsed',
    );
});

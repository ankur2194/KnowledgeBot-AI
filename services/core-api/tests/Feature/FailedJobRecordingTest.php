<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The failed-job recorder, and the table finding R8 found missing
|--------------------------------------------------------------------------
|
| `config/queue.php` named `failed_jobs` from the skeleton onward and no migration created it. The
| absence was invisible to 1,300 passing tests for a reason worth stating, because it decides what
| this file has to assert: THE RECORDER IS ONLY REACHED WHEN A JOB HAS EXHAUSTED ITS ATTEMPTS.
| No happy path touches it, `Queue::fake()` replaces the whole mechanism, and a job that fails
| inside a test usually fails by throwing into the assertion rather than through the worker's
| shutdown path. So it was found by an operator-visible failure on a real upload, months late, and
| the message it produced was the RECORDER's exception rather than the job's.
|
| THE ASSERTIONS THEREFORE GO THROUGH `app('queue.failer')` AND NOT THROUGH A JOB. Dispatching a
| job that throws proves the job throws; it does not prove the failer can write, because the worker
| loop that calls the failer is not what a Pest test runs. The failer is the object the framework
| hands the exception to, and calling it directly is the narrowest thing that would have failed on
| 2026-08-23 and passes on 2026-08-24.
|
| It is also a CONTRACT test in disguise: the column set is Laravel's, not ours, and the driver is
| selected by an env var. A future `QUEUE_FAILED_DRIVER` change or a framework upgrade that renames
| a column breaks these tests rather than breaking an operator's recovery two weeks later.
*/

it('records a permanently failed job, which is what R8 could not do', function (): void {
    $uuid = (string) Str::uuid();
    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => 'App\\Jobs\\SubmitIngestionJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\SubmitIngestionJob', 'command' => 'serialized'],
    ], JSON_THROW_ON_ERROR);

    $returned = app('queue.failer')->log(
        'redis',
        'ai-dispatch',
        $payload,
        new \RuntimeException('the exception that actually killed the job'),
    );

    // The driver is `database-uuids`, so the handle the operator gets back is the job's own uuid
    // rather than a bigserial id. That is the whole reason for the driver: the serial is not stable
    // across a restore, and `queue:retry` takes this value.
    expect($returned)->toBe($uuid);

    $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();

    expect($row)->not->toBeNull();
    assert($row !== null);

    expect($row->connection)->toBe('redis');
    expect($row->queue)->toBe('ai-dispatch');
    expect($row->payload)->toBe($payload);
    // THE FIELD THAT WAS BEING LOST. Under R8 the recorder threw before writing, so the
    // operator read `relation "failed_jobs" does not exist` INSTEAD of this string.
    expect($row->exception)->toContain('the exception that actually killed the job');
    expect($row->failed_at)->not->toBeNull();
});

it('finds and forgets by uuid, which is what queue:retry and queue:forget do', function (): void {
    $uuid = (string) Str::uuid();
    app('queue.failer')->log('redis', 'default', json_encode(['uuid' => $uuid], JSON_THROW_ON_ERROR), new \RuntimeException('x'));

    $found = app('queue.failer')->find($uuid);
    expect($found)->not->toBeNull();
    assert($found !== null);

    // Through an array cast rather than `->id`: `FailedJobProviderInterface::find()` is typed
    // `object|null`, so the property is invisible to static analysis. The uuid IS the id here —
    // the `database-uuids` provider maps it — and that mapping is what `queue:retry <uuid>` uses.
    expect(((array) $found)['id'] ?? null)->toBe($uuid);

    expect(app('queue.failer')->forget($uuid))->toBeTrue();
    expect(app('queue.failer')->find($uuid))->toBeNull();
});

it('answers rather than raising when the handle is not a uuid at all', function (): void {
    // WHY THE COLUMN IS `char(36)` AND NOT PostgreSQL's native `uuid`. An operator running
    // `queue:retry` from a copied-and-truncated id must get "no such failed job", not SQLSTATE
    // 22P02 out of a maintenance command. Against a native uuid column this line throws.
    expect(app('queue.failer')->find('not-a-uuid'))->toBeNull();
});

it('prunes on failed_at, which is what puts the table inside the retention policy', function (): void {
    $old = (string) Str::uuid();
    $fresh = (string) Str::uuid();

    app('queue.failer')->log('redis', 'default', json_encode(['uuid' => $old], JSON_THROW_ON_ERROR), new \RuntimeException('old'));
    app('queue.failer')->log('redis', 'default', json_encode(['uuid' => $fresh], JSON_THROW_ON_ERROR), new \RuntimeException('fresh'));

    DB::table('failed_jobs')->where('uuid', $old)->update(['failed_at' => Carbon::now()->subDays(20)]);

    // The scheduled entry is `queue:prune-failed --hours=336` — two weeks. Twenty days is past it,
    // and the second row is not, so this asserts the boundary rather than "delete does something".
    $pruned = app('queue.failer')->prune(Carbon::now()->subHours(336));

    expect($pruned)->toBe(1)
        ->and(DB::table('failed_jobs')->where('uuid', $old)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->where('uuid', $fresh)->exists())->toBeTrue();
});

it('refuses a second row for one uuid, because the recorder is itself retried', function (): void {
    $uuid = (string) Str::uuid();
    $payload = json_encode(['uuid' => $uuid], JSON_THROW_ON_ERROR);

    app('queue.failer')->log('redis', 'default', $payload, new \RuntimeException('first'));

    // A worker killed between the insert and the ack re-runs the shutdown path. Without the unique
    // index this is two rows for one job, and `queue:retry` re-dispatches it twice.
    expect(fn () => app('queue.failer')->log('redis', 'default', $payload, new \RuntimeException('second')))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('holds no organization column, and that is a decision rather than an omission', function (): void {
    // Every tenant-owned table in this schema carries `organization_id NOT NULL`. This one does not,
    // and the migration says why: an Eloquent model here would be in reach of OrganizationScope,
    // and a global scope over a table the framework writes with a raw query builder is a scope that
    // silently does not apply — which is worse than no scope, because it reads as protection.
    //
    // The assertion is here so that adding the column (or a model) has to argue with this comment.
    $columns = collect(DB::select(
        'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
        ['failed_jobs'],
    ))->pluck('column_name')->sort()->values()->all();

    expect($columns)->toBe(['connection', 'exception', 'failed_at', 'id', 'payload', 'queue', 'uuid']);
});

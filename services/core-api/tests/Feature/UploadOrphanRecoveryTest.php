<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\PendingSourceObject;
use App\Models\SourceItem;
use App\Models\User;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Repositories\Contracts\PendingSourceObjectRepositoryInterface;
use App\Support\Kb\ObjectKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Upload orphan recovery — security finding S3
|--------------------------------------------------------------------------
|
| `SourceService::create()` writes the bytes BEFORE the rows, because object storage does not join a
| PostgreSQL transaction and the alternative ordering commits a row pointing at nothing. The cost is
| an object under a source id that never became a row — and that object sits inside
| `org/{org}/sources/{sourceId}/`, a prefix the phase-2 purge only ever visits for sources that
| EXIST, so deletion verification CERTIFIES IT CLEAN WHILE THE BYTES SURVIVE. That is
| non-negotiable 6 failing in the one direction that produces a signed proof of a deletion that did
| not happen.
|
| WHAT IS ASSERTED HERE, in the order the mechanism runs:
|
|   * the create path RESERVES before it writes and RELEASES after it commits, on both the upload
|     and the pasted-text branches;
|   * a create that fails after the write leaves the reservation, which is the only thing naming
|     the object;
|   * the sweep's three guards each hold — the grace window, the claim check, and the TENANT
|     CONTEXT that makes the claim check able to answer at all;
|   * and the database refuses the two reservations that would turn the sweep into a
|     delete-any-object primitive.
|
| THE THIRD GUARD IS THE ONE WITH THE WORST FAILURE MODE AND IT GETS THE TWO-ORGANIZATION FIXTURE
| `pest-testing` NN2 demands. `SourceItem` is `#[ScopedBy(OrganizationScope::class)]` and that scope
| FAILS CLOSED — unbound, it applies `whereRaw('1 = 0')`. A sweep that forgot to bind a context
| would therefore be told "nothing claims this key" FOR EVERY KEY IN THE DATABASE and would delete
| every live original it had a reservation for, while exiting 0. A one-organization fixture cannot
| fail that test; the claimed-key case has to be a DIFFERENT organization from the collected one.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');

    // The create path dispatches SubmitIngestionJob, which is not this file's subject and would
    // otherwise try to sign a request to a service that is not running.
    Queue::fake();
});

/**
 * One organization and its signed-in owner.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own: Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run — and only
 * in a full run.
 *
 * @return array{org: Organization, owner: User}
 */
function orphanFixture(string $label): array
{
    $org = Organization::factory()->create([
        'name' => 'Orphan Org', 'slug' => 'orphan-'.$label.'-'.Str::lower((string) Str::ulid()),
    ]);

    $owner = User::factory()->recycle($org)->orgRole(OrgRole::Owner)
        ->create(['email' => SpaSession::uniqueEmail('orphan-'.$label)]);

    return ['org' => $org, 'owner' => $owner];
}

/**
 * A stale reservation whose object really is on the fake disk, so a deletion is observable.
 *
 * The object is written directly rather than through `SourceObjectWriter`: this is the state AFTER
 * a create died, and reproducing it through the create path would mean killing a request mid-flight.
 */
function orphanReservation(Organization $org, string $body = 'orphaned bytes'): PendingSourceObject
{
    $reservation = PendingSourceObject::factory()->recycle($org)->stale()->create();

    Storage::disk('s3')->put($reservation->storage_key, $body);

    return $reservation;
}

/**
 * Run the sweep once and assert its exit code.
 *
 * A HELPER BECAUSE OF A TYPE, not for brevity. `TestCase::artisan()` is declared
 * `PendingCommand|int` — it returns a bare int once the command has been mocked — so a direct
 * `->assertExitCode()` is a level-8 `method.nonObject` at every call site. Narrowing it in one
 * place is the alternative to seven identical suppressions, and the `assert()` is what makes the
 * narrowing a claim the runtime also checks.
 *
 * @param  array<string, mixed>  $arguments
 */
function sweepOrphans(int $exitCode = 0, array $arguments = []): void
{
    $pending = currentTest()->artisan('kb:sweep-orphan-objects', $arguments);

    assert($pending instanceof PendingCommand);

    $pending->assertExitCode($exitCode);
}

// ── the create path reserves and releases ────────────────────────────────────────────────────────

it('releases every reservation once a pasted source has committed', function (): void {
    $f = orphanFixture('paste');
    SpaSession::establish(currentTest(), $f['owner']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$f['org']->id}/sources",
        ['type' => 'text', 'name' => 'Refund policy', 'content' => 'Refunds are accepted for 30 days.'],
        spaHeaders(),
    )->assertCreated();

    $sourceId = (string) $response->json('data.id');
    $key = (string) SourceItem::withoutGlobalScopes()
        ->where('source_id', $sourceId)->value('storage_key');

    // THE OBJECT IS THERE AND THE LEDGER IS EMPTY. Both halves matter: an empty ledger with no
    // object would mean the reservation was never made, and this assertion would pass against a
    // service that had had the whole mechanism deleted.
    Storage::disk('s3')->assertExists($key);

    expect(PendingSourceObject::query()->count())->toBe(0);
});

it('releases every reservation once an uploaded batch has committed', function (): void {
    $f = orphanFixture('upload');
    SpaSession::establish(currentTest(), $f['owner']);

    $response = currentTest()->post(
        "/api/v1/organizations/{$f['org']->id}/sources",
        [
            'type' => 'file',
            'name' => 'Two handbooks',
            'files' => [
                // `.md`, not `.txt`. The intake gate's extension allow-list is the same one the
                // document parser is closed to, and `txt` is not on it — a fixture that used one
                // would test the refusal path while claiming to test the write path.
                UploadedFile::fake()->createWithContent('one.md', '# The first handbook'),
                UploadedFile::fake()->createWithContent('two.md', '# The second handbook, which differs'),
            ],
        ],
        spaHeaders(),
    )->assertCreated();

    $sourceId = (string) $response->json('data.id');
    $keys = SourceItem::withoutGlobalScopes()
        ->where('source_id', $sourceId)->pluck('storage_key')->all();

    expect($keys)->toHaveCount(2);

    foreach ($keys as $key) {
        Storage::disk('s3')->assertExists((string) $key);
    }

    expect(PendingSourceObject::query()->count())->toBe(0);
});

it('leaves the reservation behind when the create transaction fails after the write', function (): void {
    $f = orphanFixture('failed-create');
    SpaSession::establish(currentTest(), $f['owner']);

    // THE FAILURE THIS WHOLE MECHANISM EXISTS FOR, AND IT HAS TO BE CONSTRUCTED — WHICH IS ITSELF
    // WORTH RECORDING. Both known reachable triggers are closed: the invalid-UTF-8 `display_name`
    // that walked the gate and was refused by PostgreSQL after the write is caught at
    // `UploadIntake::assertExtensionIsAllowed()` (finding Q1), and two byte-identical files in one
    // batch are refused by the gate with a 422 before any object is written. What remains is any
    // OTHER database failure between the object write and the commit — latent rather than absent —
    // so the only honest way to exercise it is to make the commit fail on purpose.
    //
    // THE SEAM IS THE REPOSITORY BECAUSE THAT IS WHERE THE TRANSACTION IS. Every object this
    // request writes has already been stored by the time `create()` is called; anything that throws
    // there leaves bytes with no row, which before this ledger meant bytes nothing in the system
    // could name.
    $repository = \Mockery::mock(KnowledgeSourceRepositoryInterface::class);

    // THE ONE SUPPRESSION IN THIS FILE, AND WHAT IT IS FOR. `Mockery::mock()` is declared
    // `LegacyMockInterface&MockInterface`; PHPStan resolves only the legacy half, whose
    // `shouldReceive()` returns a union without `Mockery\Expectation`, so `andThrow()` reads as an
    // undefined method at level 8. `assert($x instanceof MockInterface)` does not help — that
    // interface INHERITS `shouldReceive()` rather than redeclaring it — and the alternative is a
    // hand-written stub implementing all nineteen methods of the repository contract. Scoped to the
    // single line below, per phpstan.neon's rule about suppressions.
    // @phpstan-ignore-next-line
    $repository->shouldReceive('create')->andThrow(new \RuntimeException('the row would not commit'));

    // `app()->instance()` and not `currentTest()->instance()`, which is protected on TestCase.
    app()->instance(KnowledgeSourceRepositoryInterface::class, $repository);

    currentTest()->postJson(
        "/api/v1/organizations/{$f['org']->id}/sources",
        ['type' => 'text', 'name' => 'Doomed', 'content' => 'These bytes will outlive their row.'],
        spaHeaders(),
    )->assertStatus(500);

    expect(PendingSourceObject::query()->count())->toBe(1);

    $reservation = PendingSourceObject::query()->firstOrFail();
    $key = $reservation->storage_key;

    // THE ORPHAN, AND THE PROOF THAT IT IS ONE: the bytes are on the disk, no `source_items` row
    // names them, and the reservation is the only thing in the system that does.
    Storage::disk('s3')->assertExists($key);

    expect(SourceItem::withoutGlobalScopes()->where('storage_key', $key)->exists())->toBeFalse()
        ->and($reservation->organization_id)->toBe($f['org']->id);

    // THE MOCK GOES BEFORE THE SWEEP RUNS. `kb:sweep-orphan-objects` resolves the same repository
    // contract to ask `storageKeyIsClaimed()`, and a mock with no expectation for that method
    // throws — which the sweep would dutifully report as a failed row and a non-zero exit, turning
    // this into a test of Mockery rather than of the sweep.
    app()->forgetInstance(KnowledgeSourceRepositoryInterface::class);

    // AND IT IS COLLECTABLE, which is the whole claim. Fresh, the sweep correctly skips it; aged
    // past the grace window it is exactly the input the sweep exists for.
    sweepOrphans();
    Storage::disk('s3')->assertExists($key);

    // `now()` and not `CarbonImmutable::now()`: `created_at` is a mutable `Illuminate\Support\Carbon`
    // on this model (there is no `immutable_datetime` cast for it), and assigning the immutable type
    // is a level-8 property-type error.
    $reservation->created_at = now()->subDays(7);
    $reservation->save();

    sweepOrphans();
    Storage::disk('s3')->assertMissing($key);

    expect(PendingSourceObject::query()->count())->toBe(0);
});

// ── the sweep ────────────────────────────────────────────────────────────────────────────────────

it('collects a stale reservation nothing claims, and says how many', function (): void {
    $f = orphanFixture('collect');
    $reservation = orphanReservation($f['org']);

    sweepOrphans();

    Storage::disk('s3')->assertMissing($reservation->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(0);
});

it('skips a reservation inside the grace window, because its request may still be running', function (): void {
    $f = orphanFixture('grace');

    // FRESH, which is the factory's default precisely so a test that forgets `->stale()` exercises
    // the skip rather than the delete.
    $reservation = PendingSourceObject::factory()->recycle($f['org'])->create();
    Storage::disk('s3')->put($reservation->storage_key, 'still being uploaded');

    sweepOrphans();

    // BOTH SURVIVE. This is the guard that stops the sweep from destroying the object of a request
    // whose rows are about to commit — the exact failure the write-before-row ordering was chosen
    // to avoid, arriving from the other direction.
    Storage::disk('s3')->assertExists($reservation->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(1);
});

it('leaves a claimed object alone and retires only the stale reservation', function (): void {
    $f = orphanFixture('claimed');
    $reservation = orphanReservation($f['org'], 'a live original');

    // THE LOST-RELEASE CASE: the transaction committed and the `release()` did not run — a crash
    // between the two, or a release that itself failed. The object belongs to a live source.
    $item = new SourceItem;
    $item->organization_id = $f['org']->id;
    $item->source_id = $reservation->source_id;
    $item->canonical_key = $reservation->storage_key;
    $item->storage_key = $reservation->storage_key;
    $item->content_hash = hash('sha256', 'a live original');
    $item->mime = 'text/plain';
    $item->byte_size = 15;

    // The composite foreign key needs the source row to exist, which in this case it does — that is
    // what "claimed" means.
    $source = \App\Models\KnowledgeSource::factory()->recycle($f['org'])->create(['id' => $reservation->source_id]);
    $item->source_id = $source->id;
    $item->save();

    sweepOrphans();

    Storage::disk('s3')->assertExists($reservation->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(0);
});

it('binds each row\'s own tenant context, so one organization\'s claim cannot shield another\'s orphan', function (): void {
    // THE TWO-ORGANIZATION FIXTURE, and the assertion the whole file is shaped around. `SourceItem`
    // is scoped and the scope fails CLOSED: a sweep that never bound a context would read "nothing
    // claims this" for BOTH keys and delete BOTH objects, exiting 0. A sweep that bound the wrong
    // one would do the same for the mismatched row. Only a sweep binding each row's own
    // organization gets both answers right.
    $alpha = orphanFixture('alpha');
    $bravo = orphanFixture('bravo');

    $claimed = orphanReservation($alpha['org'], 'ALPHA keeps this');
    $orphan = orphanReservation($bravo['org'], 'BRAVO loses this');

    $source = \App\Models\KnowledgeSource::factory()->recycle($alpha['org'])
        ->create(['id' => $claimed->source_id]);

    $item = new SourceItem;
    $item->organization_id = $alpha['org']->id;
    $item->source_id = $source->id;
    $item->canonical_key = $claimed->storage_key;
    $item->storage_key = $claimed->storage_key;
    $item->content_hash = hash('sha256', 'ALPHA keeps this');
    $item->mime = 'text/plain';
    $item->byte_size = 15;
    $item->save();

    sweepOrphans();

    Storage::disk('s3')->assertExists($claimed->storage_key);
    Storage::disk('s3')->assertMissing($orphan->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(0);
});

it('deletes nothing under --dry-run', function (): void {
    $f = orphanFixture('dry');
    $reservation = orphanReservation($f['org']);

    sweepOrphans(0, ['--dry-run' => true]);

    Storage::disk('s3')->assertExists($reservation->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(1);
});

it('refuses to run at all under a grace window below the floor', function (): void {
    $f = orphanFixture('floor');
    $reservation = orphanReservation($f['org']);

    // A REFUSAL AND NOT A CLAMP. A clamp would let a deployment set this to zero, believe it had,
    // and silently get the floor — the wrong number for the operator and for the reader both. The
    // failing exit code is what reaches the ScheduledTaskFailed listener once an hour until it is
    // fixed.
    config(['kb.upload_orphan_grace_minutes' => 0]);

    sweepOrphans(1);

    Storage::disk('s3')->assertExists($reservation->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(1);
});

it('processes at most one tick\'s worth and leaves the rest for the next tick', function (): void {
    $f = orphanFixture('limit');

    $first = orphanReservation($f['org']);

    // Older rows go first, so the one created a moment later must be the survivor. `collectable()`
    // orders by `created_at`, which is what stops a backlog starving its own head.
    $second = PendingSourceObject::factory()->recycle($f['org'])->create([
        'created_at' => CarbonImmutable::now('UTC')->subDays(6),
    ]);
    Storage::disk('s3')->put($second->storage_key, 'second');

    config(['kb.upload_orphan_sweep_limit' => 1]);

    sweepOrphans();

    Storage::disk('s3')->assertMissing($first->storage_key);
    Storage::disk('s3')->assertExists($second->storage_key);

    expect(PendingSourceObject::query()->count())->toBe(1);
});

// ── what the database refuses ────────────────────────────────────────────────────────────────────

it('refuses a reservation whose key is outside its own organization prefix', function (): void {
    $alpha = orphanFixture('prefix-alpha');
    $bravo = orphanFixture('prefix-bravo');

    $ledger = app(PendingSourceObjectRepositoryInterface::class);

    // BRAVO'S PREFIX, ALPHA'S ROW. Without
    // `pending_source_objects_key_is_tenant_scoped` this row would make the sweep delete another
    // tenant's object on ALPHA's behalf — a delete-any-object primitive whose argument comes out of
    // a table.
    $foreign = ObjectKey::originalUpload($bravo['org']->id, (string) Str::ulid(), bin2hex(random_bytes(32)));

    expect(fn () => $ledger->reserve($alpha['org']->id, (string) Str::ulid(), $foreign))
        ->toThrow(QueryException::class);
});

it('refuses a second reservation of a key already pending', function (): void {
    $f = orphanFixture('duplicate');

    $ledger = app(PendingSourceObjectRepositoryInterface::class);
    $sourceId = (string) Str::ulid();
    $key = ObjectKey::originalUpload($f['org']->id, $sourceId, bin2hex(random_bytes(32)));

    $ledger->reserve($f['org']->id, $sourceId, $key);

    // A SECOND ROW FOR ONE KEY WOULD BE SWEPT TWICE, and the second sweep would delete an object
    // the first had already accounted for — or, worse, one a retry had legitimately rewritten.
    expect(fn () => $ledger->reserve($f['org']->id, $sourceId, $key))
        ->toThrow(QueryException::class);
});

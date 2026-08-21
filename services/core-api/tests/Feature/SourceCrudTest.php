<?php

declare(strict_types=1);

use App\Enums\ChunkContentType;
use App\Enums\ChunkIndexStatus;
use App\Enums\DocumentElementKind;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Jobs\SubmitIngestionJob;
use App\Models\AuditLog;
use App\Models\Chunk;
use App\Models\DocumentElement;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The knowledge-source endpoints — BEHAVIOUR
|--------------------------------------------------------------------------
|
| The authorization and isolation half is tests/Security/SourceEndpointAccessTest.php. This file is
| what the endpoints DO: the envelope, the state machine, the audit rows, and the one dispatch that
| crosses the seam.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS, even here — a one-organization fixture passes every assertion
| below against code with the tenant filter deleted.
|
| `Queue::fake()` IS NOT `Http::fake()`. The create and reprocess paths dispatch
| `SubmitIngestionJob`, whose handler calls the AI service; the suite's `QUEUE_CONNECTION` is `sync`,
| so an unfaked dispatch would run that handler INLINE and turn every 201 into a 503 against an
| ai-api that is not there. Faking the queue stops the dispatch and lets the assertion be about what
| was ENQUEUED, which is the thing this seam owes.
|
| `Storage::fake('s3')` for the same class of reason on the other side: a pasted body is written to
| object storage BEFORE the row exists, and a real disk here would reach for SeaweedFS. The key is
| still generated and still checked by `source_items_storage_key_is_tenant_scoped` on the INSERT, so
| the fake removes the network and not the property.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');
    Queue::fake();
});

/**
 * Two organizations, an owner in the first, and nothing else.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run — and only
 * in a full run, which is the worst possible time to discover it.
 *
 * @return array{orgA: Organization, orgB: Organization, ownerA: User}
 */
function sourceCrudFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Source Org ALPHA', 'slug' => 'source-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Source Org BRAVO', 'slug' => 'source-bravo']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('source-owner-alpha')]),
    ];
}

// ── create ───────────────────────────────────────────────────────────────────────────────────────

it('creates a text source, its first item, and queues it in one act', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources",
        [
            'type' => 'text',
            'name' => 'Refund policy',
            'description' => 'The 30-day rule, pasted from the wiki.',
            'content' => 'Refunds are accepted for 30 days.',
            'tags' => ['policy', 'support'],
        ],
        spaHeaders(),
    );

    $response->assertCreated()
        ->assertJsonPath('data.type', 'text')
        ->assertJsonPath('data.name', 'Refund policy')
        // NOT `draft`. Creation and submission are one act on this endpoint, because a caller who
        // has just handed over content has plainly submitted it — a source left in `draft` would be
        // a console showing "nothing is happening" for a document the operator believes they added.
        ->assertJsonPath('data.status', SourceState::Queued->value)
        ->assertJsonPath('data.status_permits_retrieval', false)
        ->assertJsonPath('data.tags', ['policy', 'support'])
        ->assertJsonPath('data.origin_url', null)
        ->assertJsonPath('data.deleted_at', null)
        ->assertJsonPath('data.purged_at', null);

    $sourceId = (string) $response->json('data.id');

    // EVERY SOURCE HAS AT LEAST ONE ITEM, INCLUDING A SINGLE-FILE UPLOAD AND A PASTE. Not a special
    // case and never one: the pointer switch, the missing-page counter and citation provenance all
    // key off `source_item_id`, and code that reads a version pointer off the SOURCE for one kind
    // and off the ITEM for another diverges the first time somebody adds a second thing to it.
    $item = SourceItem::query()->withoutGlobalScopes()->where('source_id', '=', $sourceId)->sole();

    expect($item->canonical_key)->toBe('text');
    expect($item->mime)->toBe('text/plain');
    // THE RAW BYTES AS STORED, which is the hash rule for an upload (a crawl hashes NORMALIZED
    // content instead, or every page of a site looks changed every night). Note what has already
    // happened to them: Laravel's global `TrimStrings` middleware strips leading and trailing
    // whitespace from every string field including this one, so what is hashed is the trimmed body
    // and a paste that differed only in a trailing newline is the SAME version. That is a benign
    // reading for prose and it is stated here so nobody later discovers it as a hash mismatch.
    expect($item->content_hash)->toBe(hash('sha256', 'Refunds are accepted for 30 days.'));
    expect($item->byte_size)->toBe(33);
    expect($item->current_version_id)->toBeNull();

    // THE STORAGE KEY IS INSIDE THIS ORGANIZATION'S OWN PREFIX, which the database checks against
    // the ROW'S OWN tenant column rather than against the service's promise —
    // `source_items_storage_key_is_tenant_scoped` is a `||` concatenation, so a key built for
    // another organization is refused by the INSERT.
    expect($item->storage_key)->toStartWith("org/{$f['orgA']->id}/");

    // AND INSIDE THIS SOURCE'S OWN PREFIX — asserted against the id of the row that was actually
    // created, so a key built for any other source fails here rather than passing a `toStartWith`
    // on a shape. This is the assertion the endpoint did not have when it wrote its object to
    // `org/{org}/sources/text/{hash}.txt`: that key was well-formed, tenant-scoped, and OUTSIDE the
    // prefix `_purge_objects(org_id, source_id, …)` sweeps and `object_versions_remaining()`
    // enumerates — so a source delete purged the version prefixes, verification certified them
    // clean, and the pasted body survived with nothing reporting it. Non-negotiable 6 is not "we
    // deleted it", it is "we proved we deleted it", and an unreachable object breaks the proof
    // rather than merely the delete.
    expect($item->storage_key)->toStartWith("org/{$f['orgA']->id}/sources/{$sourceId}/");
    expect($item->storage_key)->toBe(
        "org/{$f['orgA']->id}/sources/{$sourceId}/original/".hash('sha256', 'Refunds are accepted for 30 days.').'.txt'
    );

    Storage::disk('s3')->assertExists((string) $item->storage_key);

    // THE GUARD'S TWO COLUMNS ARE CLAIMED BY THE SAME TRANSACTION THAT CREATED THE ROW. A job id
    // with no sequence reset, or a reset with no job id, is half a guard.
    expect($item->current_job_id)->not->toBeNull();
    expect($item->progress_sequence)->toBe(0);

    // AND THE DISPATCH CARRIES THE SAME JOB ID, so the callback that eventually arrives can be
    // matched to the run that produced it rather than merely ordered against it.
    Queue::assertPushed(
        SubmitIngestionJob::class,
        fn (SubmitIngestionJob $job): bool => $job->organizationId === $f['orgA']->id
            && $job->sourceId === $sourceId
            && $job->jobId === $item->current_job_id
            && $job->forceNonce === null,
    );
});

it('gives two sources with byte-identical text two separate objects', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // ONE ORGANIZATION, ONE BODY, TWO SOURCES. A real shape and not a contrived one: the same
    // policy pasted into a "Refund policy" source and a "Returns" source is a Tuesday afternoon in
    // an admin console, and the two are independent objects an operator can delete independently.
    $body = 'Refunds are accepted for 30 days.';

    $keys = [];

    foreach (['Refund policy', 'Returns policy'] as $name) {
        $created = currentTest()->postJson(
            "/api/v1/organizations/{$f['orgA']->id}/sources",
            ['type' => 'text', 'name' => $name, 'content' => $body],
            spaHeaders(),
        );

        $created->assertCreated();

        $item = SourceItem::query()->withoutGlobalScopes()
            ->where('source_id', '=', (string) $created->json('data.id'))->sole();

        // The digest is the same for both, which is the whole point: it is the KEY that must
        // differ, not the hash. `source_items.content_hash` is a fact about the bytes and is
        // expected to collide; the object location is a fact about ownership and must not.
        expect($item->content_hash)->toBe(hash('sha256', $body));

        $keys[] = (string) $item->storage_key;
    }

    // THE ASSERTION THE OLD CODE FAILED. `org/{org}/sources/text/{hash}.txt` put both bodies at one
    // key — content-addressed directly under `sources/`, so identical text anywhere in the
    // organization was one shared object with NO REFERENCE COUNT behind it. Deleting either source
    // would then have taken the other's body, and the survivor would keep answering right up to the
    // moment its next reprocess read an object that was no longer there.
    //
    // `kb-tenancy-isolation`:109 does permit dedupe within an organization — and the one place this
    // repository actually does it, extracted images, is explicitly refcounted
    // (`services/ai-service/app/deletion/tasks.py`:180, "dropped only once no surviving element
    // references them"). Nothing refcounts a pasted body. Absent that machinery the dedupe boundary
    // is the source, and a duplicated object is the cheap side of the trade.
    expect($keys[0])->not->toBe($keys[1]);

    Storage::disk('s3')->assertExists($keys[0]);
    Storage::disk('s3')->assertExists($keys[1]);

    // Both are still reachable by a source-scoped sweep, which is the property that makes two
    // objects correct rather than merely different.
    foreach ($keys as $key) {
        expect($key)->toStartWith("org/{$f['orgA']->id}/sources/");
        expect($key)->toContain('/original/');
    }
});

it('creates a url source with its origin, and no object behind it', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources",
        ['type' => 'url', 'name' => 'Docs site', 'origin_url' => 'https://Example.COM:443/docs/#intro'],
        spaHeaders(),
    );

    $response->assertCreated()->assertJsonPath('data.origin_url', 'https://Example.COM:443/docs/#intro');

    $item = SourceItem::query()->withoutGlobalScopes()
        ->where('source_id', '=', (string) $response->json('data.id'))->sole();

    // THE CANONICAL KEY IS NORMALIZED AND THE URL IS NOT. Normalization is lossy on purpose — two
    // addresses that differ only in host case, a default port or a fragment are one page and one
    // request — while a CITATION has to link to something a human can open, which is why both
    // columns exist. `/docs/` keeps its trailing slash: only the EMPTY path's slash is dropped.
    expect($item->canonical_key)->toBe('https://example.com/docs/');
    expect($item->url)->toBe('https://Example.COM:443/docs/#intro');
    expect($item->storage_key)->toBeNull();
    expect($item->content_hash)->toBeNull();
});

it('agrees with the database about which URLs are acceptable, in both directions', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $url = "/api/v1/organizations/{$f['orgA']->id}/sources";

    // ── ACCEPTED: `@` IN THE PATH IS ORDINARY ────────────────────────────────────────────────
    //
    // Mastodon and Medium address a profile as `/@handle`, and a documentation page can perfectly
    // well be `/u/a@b.com`. The CHECK used to forbid `@` anywhere after the scheme, so these were
    // refused by PostgreSQL — as an unconverted `QueryException` and therefore a 500, because
    // `url:http,https` had already let them through.
    foreach (['https://docs.example.com/u/a@b.com', 'https://mastodon.social/@user'] as $accepted) {
        currentTest()->postJson($url, [
            'type' => 'url', 'name' => 'Handbook', 'origin_url' => $accepted,
        ], spaHeaders())->assertCreated()->assertJsonPath('data.origin_url', $accepted);
    }

    // ── REFUSED, AND AS A 422 RATHER THAN A 500 ──────────────────────────────────────────────
    //
    // Credentials in a crawl target are the hazard the constraint exists for: we would send them to
    // a host we do not control and log them on the way. `url:http,https` permits the userinfo
    // group, so the request-side rule has to state it too or the refusal happens at the INSERT.
    $refused = currentTest()->postJson($url, [
        'type' => 'url', 'name' => 'Handbook', 'origin_url' => 'https://user:password@internal.example.com/',
    ], spaHeaders());

    $refused->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['origin_url']);
});

it('refuses a body whose content does not match its declared type, in both directions', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $url = "/api/v1/organizations/{$f['orgA']->id}/sources";

    // A `url` SOURCE WITH NOTHING TO CRAWL. `knowledge_sources_origin_url_matches_type` is an
    // equality between two booleans, so both mistakes are one constraint — and the FormRequest
    // states both halves so neither reaches the database as a 500.
    currentTest()->postJson($url, ['type' => 'url', 'name' => 'No target'], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['origin_url']]);

    // AND THE OTHER DIRECTION: a `url` source carrying a paste is a caller who believes they
    // submitted text, and silently dropping the field would crawl the URL and never tell them the
    // paste went nowhere.
    currentTest()->postJson($url, [
        'type' => 'url', 'name' => 'Both', 'origin_url' => 'https://example.com', 'content' => 'paste',
    ], spaHeaders())->assertStatus(422)->assertJsonStructure(['errors' => ['content']]);

    // AND A `text` SOURCE WITH NO CONTENT: without it there is nothing to hash and nothing a
    // version could ever be published from.
    currentTest()->postJson($url, ['type' => 'text', 'name' => 'Empty'], spaHeaders())
        ->assertStatus(422)->assertJsonStructure(['errors' => ['content']]);

    Queue::assertNothingPushed();
});

it('refuses a `file` source that carries no files, rather than creating one with no item', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // THE PART NAME IS `files[0]`, INDEXED EVEN FOR ONE FILE. The FormRequest declares `files` and
    // `files.*`, so a validation failure keys on `files.0` and the console can render it against the
    // row an operator can see. THE SHAPE IS PINNED rather than merely documented: an absent `files`
    // rule would make `validated()` DISCARD the parts silently and answer 201 for a source with no
    // content at all.
    //
    // What this asserts now that the intake has landed is the OTHER half of the same pairing — a
    // declared `file` source with no parts. It would otherwise reach the repository with an empty
    // item list, and "every source has at least one item, including a single-file upload" is the one
    // rule the `source_items` migration says must never acquire a special case.
    //
    // The intake gate itself is tests/Unit/UploadIntakeGateTest.php and the endpoint's half is
    // tests/Feature/SourceUploadTest.php.
    $response = currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources",
        ['type' => 'file', 'name' => 'Handbook'],
        spaHeaders(),
    );

    $response->assertStatus(422)->assertJsonStructure(['errors' => ['files']]);

    Queue::assertNothingPushed();
});

it('refuses to create anything in a suspended organization', function (): void {
    $f = sourceCrudFixture();

    $f['orgA']->status = OrganizationStatus::Suspended;
    $f['orgA']->save();

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources",
        ['type' => 'text', 'name' => 'Probe', 'content' => 'probe'],
        spaHeaders(),
    )->assertStatus(409);

    Queue::assertNothingPushed();
});

// ── the paginated envelope ───────────────────────────────────────────────────────────────────────

it('publishes the envelope the console reads, with meta beside the array inside data', function (): void {
    $f = sourceCrudFixture();

    KnowledgeSource::factory()->recycle($f['orgA'])->count(3)->create();
    KnowledgeSource::factory()->recycle($f['orgB'])->count(2)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources?per_page=2&sort=name&dir=desc",
        spaHeaders(),
    );

    // `apps/web/src/lib/table/envelope.ts` THROWS rather than degrading when it cannot read this
    // shape, because an unreadable envelope is not an empty list — `{rows: [], rowCount: 0}` would
    // render the first-run "Add your first source" state to an administrator whose organization has
    // two hundred documents, which is the moment somebody uploads them all again.
    $response->assertOk()
        ->assertJsonCount(2, 'data.sources')
        ->assertJsonPath('data.meta.page', 1)
        ->assertJsonPath('data.meta.per_page', 2)
        // THREE, NOT FIVE. The other organization's two rows are not in the total either — a count
        // that leaked would be a cardinality oracle even with no row bodies attached.
        ->assertJsonPath('data.meta.total', 3)
        ->assertJsonPath('data.meta.total_pages', 2)
        ->assertJsonPath('data.meta.sort', 'name')
        ->assertJsonPath('data.meta.dir', 'desc')
        ->assertJsonPath('data.meta.filter', null);
});

it('refuses a page size above the platform cap rather than clamping it silently', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // A 422 AND NOT A CLAMP. A client asking for more than the platform serves finds out, rather
    // than paginating against a size it did not choose and computing every page count wrong.
    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources?per_page=".(ListQuery::MAX_PER_PAGE + 1),
        spaHeaders(),
    )->assertStatus(422)->assertJsonStructure(['errors' => ['per_page']]);

    // AND THE SORT SET IS CLOSED: a caller-chosen `sort` reaches an ORDER BY, so an open set is a
    // caller choosing which index the query uses at best.
    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources?sort=description",
        spaHeaders(),
    )->assertStatus(422)->assertJsonStructure(['errors' => ['sort']]);
});

// ── edit ─────────────────────────────────────────────────────────────────────────────────────────

it('edits metadata and refuses the four fields that have their own routes', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])
        ->status(SourceState::Ready)->create(['name' => 'Old name']);

    SpaSession::establish(currentTest(), $f['ownerA']);

    $url = "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}";

    currentTest()->patchJson($url, ['name' => 'New name', 'tags' => ['a']], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'New name')
        ->assertJsonPath('data.tags', ['a'])
        // UNCHANGED. The edit names no lifecycle field, and nothing here re-queues anything: none
        // of the editable columns is a component of the ingest key, so an automatic reprocess would
        // produce the same key and dedupe against the completed run.
        ->assertJsonPath('data.status', SourceState::Ready->value);

    // `missing` AND NOT `prohibited`. An ABSENT rule would make `validated()` discard the field
    // silently, so a client that had not been updated would disable a source, receive a 200, and
    // find it still answering. `prohibited` passes for `null`, `""` and `[]`, which is the same
    // silent drop reached one step later.
    foreach (['status' => 'disabled', 'type' => 'url', 'origin_url' => 'https://example.com', 'content' => 'x'] as $field => $value) {
        currentTest()->patchJson($url, [$field => $value], spaHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [$field]]);
    }

    Queue::assertNothingPushed();
});

it('checks the retrieval window against the STORED row, so a partial PATCH cannot invert it', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Ready)->create([
        'effective_at' => CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        'expires_at' => null,
    ]);

    SpaSession::establish(currentTest(), $f['ownerA']);

    $url = "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}";

    // ── THE HALF A `date` RULE CANNOT SEE ────────────────────────────────────────────────────
    //
    // `after:effective_at` compares against `$this->input('effective_at')`, which on a PATCH that
    // does not name it is `null` — and every timestamp is "after" null, so the rule passed
    // vacuously while nothing checked the value on the row. The request then reached
    // `knowledge_sources_window_ordered` and came back as an unconverted `QueryException`: a 500
    // on a route whose documented failure shape is a per-field map.
    currentTest()->patchJson($url, ['expires_at' => '2026-08-01T00:00:00Z'], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['expires_at']);

    // THE OTHER DIRECTION, because a caller can invert the window from either end.
    $source->forceFill(['expires_at' => CarbonImmutable::parse('2026-10-01T00:00:00Z')])->save();

    currentTest()->patchJson($url, ['effective_at' => '2026-11-01T00:00:00Z'], spaHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expires_at']);

    // AN EXPLICIT NULL CLEARS THAT END, which makes the window open-ended and therefore valid. The
    // presence test is `has()` and not `filled()` for exactly this: `filled()` reads the clear as
    // "not supplied" and would compare against the value the request is removing.
    currentTest()->patchJson($url, ['expires_at' => null], spaHeaders())->assertOk();

    // AND A WINDOW THAT IS ORDERED IS STILL ACCEPTED.
    currentTest()->patchJson($url, ['expires_at' => '2027-01-01T00:00:00Z'], spaHeaders())->assertOk();
});

it('carries an edited retrieval window down onto the chunks a query actually reads', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Ready)->create([
        'effective_at' => null,
        'expires_at' => null,
    ]);

    $version = crudLiveVersion($source, SourceState::Ready);

    // One element and one chunk, built with the window the source had AT CHUNK TIME — which is how
    // the data plane writes it: a Qdrant filter cannot reach up a foreign-key chain, so the pair is
    // denormalized onto every chunk rather than read off the source at query time.
    $element = new DocumentElement;
    $element->organization_id = $source->organization_id;
    $element->source_version_id = $version->id;
    $element->seq = 0;
    $element->kind = DocumentElementKind::Text;
    $element->text = 'Refunds are accepted for 30 days.';
    $element->char_start = 0;
    $element->char_end = 33;
    $element->save();

    $chunk = new Chunk;
    $chunk->organization_id = $source->organization_id;
    $chunk->source_id = $source->id;
    $chunk->source_item_id = $version->source_item_id;
    $chunk->source_version_id = $version->id;
    $chunk->seq = 0;
    $chunk->document_element_id = $element->id;
    $chunk->element_ids = [$element->id];
    $chunk->heading_path = [];
    $chunk->char_start = 0;
    $chunk->char_end = 33;
    $chunk->lang = 'en';
    $chunk->content_type = ChunkContentType::Prose;
    $chunk->token_count = 8;
    $chunk->content_hash = hash('sha256', 'chunk');
    $chunk->text = $element->text;
    $chunk->vector_point_id = (string) Str::uuid();
    $chunk->index_status = ChunkIndexStatus::Indexed;
    $chunk->embedding_model_id = $version->embedding_model_version;
    $chunk->parser_version = $version->parser_cfg_version;
    $chunk->chunker_version = $version->chunker_cfg_version;
    $chunk->save();

    expect($chunk->expires_at)->toBeNull();

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->patchJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}",
        ['expires_at' => '2026-08-01T00:00:00Z'],
        spaHeaders(),
    )->assertOk();

    // WITHOUT THIS THE EDIT REACHES NOTHING. A reprocess cannot repair it either: neither column is
    // a component of the ingest key, so a re-run dedupes against the completed version and changes
    // nothing. PostgreSQL is what Qdrant is rebuilt FROM, so this is where the window has to land.
    expect($chunk->fresh()?->expires_at)->not->toBeNull();
});

it('refuses an edit that names nothing, so the trail cannot record a change that did not happen', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->patchJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}",
        [],
        spaHeaders(),
    )->assertStatus(422)->assertJsonStructure(['errors' => ['source']]);

    // A REAL PER-FIELD MAP AND NOT A BARE `abort(422)`: `apps/web` discriminates the ADR-031
    // resolver refusal on the shape `validation` WITH NO MAP, so a second map-less 422 on this
    // surface would wear that signature.
    expect(AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_UPDATED)->count())->toBe(0);
});

// ── the state machine ────────────────────────────────────────────────────────────────────────────

it('refuses every transition the table does not have an edge for', function (
    SourceState $from,
    string $requested,
): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status($from)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // A REFUSAL WITH A REAL ERROR CLASS, NEVER A SILENT NO-OP. `validation` renders 422, is never
    // retryable and is never fallback-eligible — all three of which are true of an illegal
    // transition, because no number of attempts makes `deleted -> ready` legal. A 409 would render
    // as `internal_dependency` in this application's envelope and tell a client a DEPENDENCY was
    // unwell when the request was simply wrong about the row.
    $response = currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => $requested],
        spaHeaders(),
    )->assertStatus(422)->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['status']]);

    // ── THE FIELD MESSAGE IS PRODUCT COPY, BECAUSE THE CLIENT RENDERS IT VERBATIM ─────────────
    //
    // `apps/web` renders a `validation` envelope's per-field messages as-is, on the general premise
    // that a Laravel validation message is end-user copy. This one used to name
    // `App\Enums\SourceState::transitionTable()` — a PHP class on a customer's console — and the
    // client grew a special case to discard it. The operator's sentence still exists; it is on the
    // exception and in the log line `SourceService::illegal()` writes, which is where operators
    // look. Asserted on the WHOLE BODY rather than on the field, so a leak into the envelope's
    // `message` (which Laravel summarizes from the first field error) is caught by the same line.
    expect((string) $response->getContent())->not->toContain('transitionTable');
    expect((string) $response->getContent())->not->toContain('App\\Enums');

    // POSITIVE CONTROL: the field still says something, and it names both ends so the reader can
    // see what they asked for. A test asserting only an absence passes against an empty string.
    expect((string) $response->json('errors.status.0'))
        ->toContain($from->value)
        ->toContain($requested);

    expect($source->fresh()?->status)->toBe($from);
})->with([
    // `draft` has three edges and `disabled` is not one of them: nothing has been ingested, so
    // there is nothing to withdraw from retrieval.
    'draft cannot be disabled' => [SourceState::Draft, 'disabled'],
    // A run in flight is not a state a human moves out of by hand.
    'parsing cannot be disabled' => [SourceState::Parsing, 'disabled'],
    // TERMINAL. The row is empty in the transition table, and an empty row rather than an absent
    // key is what makes `canTransitionTo()` refuse without a fallback to guess at.
    'deleted cannot be re-enabled' => [SourceState::Deleted, 'ready'],
    // ONE-WAY. Physical removal is two-phase and `deleting` is the phase the purge worker owns.
    'deleting cannot be re-enabled' => [SourceState::Deleting, 'ready'],
]);

it('refuses a transition to the state the source already holds', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Disabled)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // A TRANSITION IS NOT A STATE ASSERTION. A move to the state the row already holds would return
    // 200 and write a `source.disabled` audit row describing a change that did not happen, which
    // makes the trail wrong in the one direction nobody checks it in.
    currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => 'disabled'],
        spaHeaders(),
    )->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);

    expect(AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_DISABLED)->count())->toBe(0);
});

it('refuses ready_with_warnings on the status endpoint, because the flavour is a fact not a choice', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Disabled)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // The two ready states are IDENTICAL for retrieval and differ in exactly one thing — whether
    // this source's live content parsed cleanly. A client that could send either would be able to
    // erase the only durable signal that says "this document parsed badly and published anyway".
    currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => SourceState::ReadyWithWarnings->value],
        spaHeaders(),
    )->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
});

// ── disable and enable ───────────────────────────────────────────────────────────────────────────

it('disables a source immediately and audits it with the status it actually held', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])
        ->status(SourceState::Ready)->create(['name' => 'Refund policy']);

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => 'disabled'],
        spaHeaders(),
    )->assertOk()
        ->assertJsonPath('data.status', SourceState::Disabled->value)
        // THE HEADLINE REQUIREMENT, VISIBLE IN ONE FIELD: the status term of the retrieval filter
        // stops being satisfied on the next query. Nothing is purged and no job has to succeed
        // first, which is what makes re-enabling a metadata write.
        ->assertJsonPath('data.status_permits_retrieval', false)
        ->assertJsonPath('data.deleted_at', null);

    $row = AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_DISABLED)->sole();

    expect($row->details['status'] ?? null)->toBe(SourceState::Disabled->value);
    // READ UNDER THE SAME ROW LOCK THAT WROTE THE NEW VALUE, so two concurrent moves serialise and
    // neither row can name a status the source never held.
    expect($row->details['previous_status'] ?? null)->toBe(SourceState::Ready->value);
    expect($row->details['name'] ?? null)->toBe('Refund policy');
    expect($row->actor_id)->toBe($f['ownerA']->id);

    // NOTHING WAS RE-SUBMITTED. Disabling is not a reprocess, and every vector is retained.
    Queue::assertNothingPushed();
});

/**
 * One item with one ACTIVATED version, which is what makes a source enable-able at all.
 *
 * The pointer is written separately from `activated_at` deliberately: the enable path reads
 * `source_items.current_version_id` and never the timestamps, so a fixture that set only the
 * timestamps would pass against an implementation that had quietly switched to the other reading.
 */
function crudLiveVersion(KnowledgeSource $source, SourceState $status): SourceVersion
{
    $item = new SourceItem;
    $item->organization_id = $source->organization_id;
    $item->source_id = $source->id;
    $item->canonical_key = 'text:'.$source->id;
    $item->save();

    $version = new SourceVersion;
    $version->organization_id = $source->organization_id;
    $version->source_item_id = $item->id;
    $version->version_number = 1;
    $version->content_hash = hash('sha256', $item->id.':content');
    $version->ingest_key = hash('sha256', $item->id.':key');
    $version->parser_cfg_version = 'parser/v1:docling-2.118';
    $version->ocr_cfg_version = 'ocr/v1:rapidocr';
    $version->chunker_cfg_version = 'chunker/v1:structure';
    $version->embedding_model_version = 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1';
    $version->status = $status;
    $version->warning_summary = [];
    $version->activated_at = now()->toImmutable();
    $version->save();

    SourceItem::withoutGlobalScopes()->whereKey($item->id)
        ->update(['current_version_id' => $version->id]);

    return $version;
}

it('re-enables into the flavour the live version published as, not the one the client asked for', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Disabled)->create();

    // A LIVE VERSION THAT PUBLISHED CLEAN, so the answer is plain `ready` because of what the
    // VERSION says rather than because there was nothing to ask. The warned case is the sibling
    // test above; the no-corpus case is the one below.
    crudLiveVersion($source, SourceState::Ready);

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => 'ready'],
        spaHeaders(),
    )->assertOk()->assertJsonPath('data.status', SourceState::Ready->value);

    expect(AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_ENABLED)->sole()
        ->details['previous_status'] ?? null)->toBe(SourceState::Disabled->value);
});

it('refuses to enable a source that has never published a version', function (): void {
    $f = sourceCrudFixture();

    // THE TWO-HOP ROUTE AROUND A REFUSAL THE TABLE DOCUMENTS AS IMPOSSIBLE. `Failed -> Ready`
    // without a new run is one of the four transitions `SourceState::transitionTable()` names as
    // refused by construction — and `Failed -> Disabled` and `Disabled -> Ready` are both edges, so
    // walking around it took two requests. The source came out `ready` with `current_version_id`
    // null: `status_permits_retrieval` true, the console pill green, and not one chunk behind it.
    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Failed)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => 'disabled'],
        spaHeaders(),
    )->assertOk();

    $refused = currentTest()->putJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/status",
        ['status' => 'ready'],
        spaHeaders(),
    );

    $refused->assertStatus(422)->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors(['status']);

    // AND IT POINTS AT THE ROUTE THAT ACTUALLY INDEXES SOMETHING, rather than at the state machine.
    expect((string) $refused->json('errors.status.0'))->toContain('Reprocess');

    expect($source->fresh()?->status)->toBe(SourceState::Disabled);
});

// ── reprocess ────────────────────────────────────────────────────────────────────────────────────

it('reprocesses with a fresh force nonce, re-claims every item, and answers 202', function (): void {
    $f = sourceCrudFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $created = currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources",
        ['type' => 'text', 'name' => 'Refund policy', 'content' => 'Refunds are accepted for 30 days.'],
        spaHeaders(),
    )->assertCreated();

    $sourceId = (string) $created->json('data.id');

    // Move it out of `queued`, because `queued -> queued` is not an edge — a reprocess of a run
    // that has not started is a caller who has misread the row.
    KnowledgeSource::query()->withoutGlobalScopes()->whereKey($sourceId)
        ->update(['status' => SourceState::Ready->value]);

    $firstJobId = SourceItem::query()->withoutGlobalScopes()
        ->where('source_id', '=', $sourceId)->sole()->current_job_id;

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$sourceId}/reprocess",
        [],
        spaHeaders(),
    );

    // 202 AND NOT 200: nothing has been reprocessed when this returns. The submission is a queued
    // job, and the PREVIOUS VERSION KEEPS SERVING until the new one is indexed AND verified.
    $response->assertStatus(202)->assertJsonPath('data.status', SourceState::Queued->value);

    $item = SourceItem::query()->withoutGlobalScopes()->where('source_id', '=', $sourceId)->sole();

    // EVERY ITEM IS RE-CLAIMED FOR THE NEW JOB AND THE SEQUENCE IS RESET WITH IT. Without this a
    // reprocess dispatched while an earlier run is in flight produces two live jobs whose sequences
    // both start at 1, and the older one's stage-3 frame would rewind the newer one's stage-7.
    expect($item->current_job_id)->not->toBe($firstJobId);
    expect($item->progress_sequence)->toBe(0);

    $row = AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_REPROCESS_REQUESTED)->sole();

    // THE FORCE NONCE IS ECHOED AND IT IS NOT A CREDENTIAL. It is the one component of the ingest
    // key that changes when nothing else did, which is what makes an explicit reprocess reach a
    // worker instead of deduping against the completed run — and recording it is what lets a trail
    // answer "did this request actually cause a new version".
    expect($row->details['force_nonce'] ?? null)->toBeString();
    expect($row->details['item_count'] ?? null)->toBe(1);

    // AND THE DISPATCH CARRIES THE SAME NONCE. A retry that generated a new one would be a
    // duplicate job rather than a replay.
    Queue::assertPushed(
        SubmitIngestionJob::class,
        fn (SubmitIngestionJob $job): bool => $job->sourceId === $sourceId
            && $job->jobId === $item->current_job_id
            && $job->forceNonce === $row->details['force_nonce'],
    );
});

// ── delete ───────────────────────────────────────────────────────────────────────────────────────

it('fails a source whose submission could not be enqueued, so Reprocess is not a dead button', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->status(SourceState::Ready)->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // ── THE DISPATCH IS OUTSIDE THE TRANSACTION AND HAD NO COMPENSATION ─────────────────────
    //
    // By the time it runs, the source is COMMITTED at `queued` and the audit row is written. A
    // broker unreachable for those two seconds left a source queued for a job that does not exist —
    // and `queued -> queued` is deliberately not an edge, so pressing Reprocess again 422s forever.
    // Nothing else would ever run: `SubmitIngestionJob::failed()` is a handler for a job that was
    // never enqueued. The source was unrecoverable short of deleting it.
    // `app()->instance()` and not Mockery: the same swap every other suite here uses, and it keeps
    // the double's shape visible to the analyser rather than behind a magic method. It EXTENDS the
    // real dispatcher rather than reimplementing the interface, so a method added to the contract
    // later cannot make this test the thing that has to be edited.
    app()->instance(Dispatcher::class, new class(app()) extends BusDispatcher
    {
        public function dispatch($command): mixed
        {
            throw new \RuntimeException('the broker is unreachable');
        }
    });

    currentTest()->postJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}/reprocess",
        [],
        spaHeaders(),
    )->assertStatus(500);

    // `queued -> failed` IS an edge and `failed -> queued` is the edge back, so the operator's
    // obvious repair works. The original exception is what surfaced; the compensation is silent.
    expect($source->fresh()?->status)->toBe(SourceState::Failed);
});

it('deletes in two phases: the row survives, out of retrieval, with nothing proven yet', function (): void {
    $f = sourceCrudFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])
        ->status(SourceState::Ready)->create(['name' => 'Refund policy', 'type' => SourceType::Text]);

    SpaSession::establish(currentTest(), $f['ownerA']);

    $response = currentTest()->deleteJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}",
        [],
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.status', SourceState::Deleting->value)
        ->assertJsonPath('data.status_permits_retrieval', false);

    expect($response->json('data.deleted_at'))->toBeString();
    // `purged_at` STAYS NULL, and the two are not the same claim: one says we removed it, the other
    // says we PROVED we removed it, and only the second is what a retention obligation is measured
    // against. `knowledge_sources_purge_follows_delete` refuses a proof with no delete.
    expect($response->json('data.purged_at'))->toBeNull();

    $row = AuditLog::query()->where('operation', '=', AuditLogger::SOURCE_DELETED)->sole();

    // THE SCALE OF WHAT IS GOING, AS SCALARS, read inside the transaction and BEFORE phase 2
    // removes anything — a read taken afterwards would record two zeroes onto exactly the row that
    // needs them most.
    expect($row->details['item_count'] ?? null)->toBe(0);
    expect($row->details['version_count'] ?? null)->toBe(0);
    expect($row->actor_id)->toBe($f['ownerA']->id);

    // A SECOND DELETE IS NOT A 200. `deleting` has exactly one legal edge and it is not to itself,
    // so the refusal comes from the transition table rather than from a special case — and a 200
    // would claim this actor performed a deletion the trail records only once.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}",
        [],
        spaHeaders(),
    )->assertStatus(422);
});

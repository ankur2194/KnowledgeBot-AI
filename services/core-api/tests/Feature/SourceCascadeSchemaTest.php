<?php

declare(strict_types=1);

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| What the source cascade refuses, asserted against the database itself
|--------------------------------------------------------------------------
|
| EVERY TEST BELOW WRITES THROUGH THE MODEL AND NOT THROUGH AN ENDPOINT, on purpose — the same call
| tests/Feature/BotSchemaTest.php makes and for the same reason. These are the constraints that have
| to hold for a writer that never ran a FormRequest: a repair script, a console command, a seeder,
| an ingestion callback, a Celery worker writing through `app/db/writes.py`'s allow-listed direct
| path. A test that drove an HTTP route would prove the FormRequest works and would say nothing
| about the row.
|
| THE PARTIAL UNIQUE INDEX IS WHY THIS FILE EXISTS. `source_versions_one_active_per_item` is the
| whole of atomic publication: a boolean `is_active` lets two concurrent publishes both claim it
| with no single statement that flips them together, and postgresql-patterns' definition of done
| asks for a test that observes 23505 on the second. Everything else here is the shape work around
| it.
|
| tests/Security/KnowledgeSourceTenancyTest.php holds the OTHER half — the composite foreign keys
| and the organization scope — because those fail for a different reason, and a red test there is a
| tenant boundary rather than a shape.
*/

/**
 * Attempt a write and return the QueryException it raised, or null if it succeeded.
 *
 * THE SAVEPOINT IS LOAD-BEARING. `RefreshDatabase` wraps each test in one transaction, and in
 * PostgreSQL a statement that raises inside a transaction ABORTS IT — every subsequent statement
 * fails with 25P02 until a rollback. Without the nested `transaction()`, which Laravel implements as
 * SAVEPOINT / ROLLBACK TO SAVEPOINT when one is already open, the first expected violation poisons
 * every line after it and the positive control that follows can never run.
 */
function cascadeAttempt(\Closure $write): ?QueryException
{
    try {
        // The closure returns a value rather than nothing so the transaction helper's return type
        // is resolvable; the value itself is discarded.
        (new KnowledgeSource)->getConnection()->transaction(static function () use ($write): bool {
            $write();

            return true;
        });

        return null;
    } catch (QueryException $e) {
        return $e;
    }
}

/**
 * A source, an item, and as many versions as asked for — written field by field, because every
 * column on `source_items` and `source_versions` is outside `$fillable` deliberately.
 *
 * @return array{0: KnowledgeSource, 1: SourceItem}
 */
function seedSourceAndItem(Organization $organization): array
{
    $source = KnowledgeSource::factory()->recycle($organization)->create([
        'type' => SourceType::File,
    ]);

    $item = new SourceItem;
    $item->organization_id = $organization->id;
    $item->source_id = $source->id;
    $item->canonical_key = 'org/'.$organization->id.'/sources/'.$source->id.'/items/handbook.pdf';
    $item->display_name = 'handbook.pdf';
    $item->storage_key = 'org/'.$organization->id.'/sources/'.$source->id.'/original/handbook.pdf';
    $item->content_hash = str_repeat('a', 64);
    $item->mime = 'application/pdf';
    $item->byte_size = 4096;
    $item->save();

    return [$source, $item];
}

function makeVersion(SourceItem $item, int $number, ?string $ingestKey = null): SourceVersion
{
    $version = new SourceVersion;
    $version->organization_id = $item->organization_id;
    $version->source_item_id = $item->id;
    $version->version_number = $number;
    $version->content_hash = str_repeat('b', 64);
    $version->ingest_key = $ingestKey ?? bin2hex(random_bytes(32));
    $version->parser_cfg_version = 'parser/v1';
    $version->ocr_cfg_version = 'ocr/v1';
    $version->chunker_cfg_version = 'chunker/v1';
    $version->embedding_model_version = 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1';
    $version->status = SourceState::Indexing;
    $version->save();

    return $version;
}

it('permits at most one ACTIVE version per item, and proves it in the database', function (): void {
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    // THE POSITIVE CONTROL, AND IT IS THE POINT RATHER THAN A PREAMBLE: one activation must
    // succeed. A partial unique index written with the wrong predicate would refuse every
    // activation, which is a total outage that makes the negative assertion below look strongest.
    $first = makeVersion($item, 1);
    $first->status = SourceState::Ready;
    $first->activated_at = CarbonImmutable::now();
    $first->save();

    expect($first->refresh()->hasLivePeriod())->toBeTrue();

    // A SECOND ACTIVATION OF THE SAME ITEM. This is a Celery redelivery in the ordinary case: two
    // runs each read `current_version_id`, each see the other's row as not-yet-committed, and each
    // activate. 23505 on the second is the whole mechanism.
    $second = makeVersion($item, 2);

    $exception = cascadeAttempt(static function () use ($second): void {
        $second->status = SourceState::Ready;
        $second->activated_at = CarbonImmutable::now();
        $second->save();
    });

    expect($exception)->toBeInstanceOf(
        QueryException::class,
        'two versions of one item are active at once. Retrieval filters the active version, so this '
        .'is a page answering out of two revisions simultaneously with nothing raising.'
    );
    expect(str_contains((string) $exception?->getMessage(), 'source_versions_one_active_per_item'))
        ->toBeTrue((string) $exception?->getMessage());

    // AND THE INDEX IS PARTIAL, NOT ABSOLUTE. Retiring the first must let the second through —
    // otherwise the constraint would forbid ever publishing a second revision of anything, which is
    // the failure mode a non-partial unique index on (source_item_id) would produce.
    $first->retired_at = CarbonImmutable::now();
    $first->save();

    $second->status = SourceState::Ready;
    $second->activated_at = CarbonImmutable::now();
    $second->save();

    expect($second->refresh()->hasLivePeriod())->toBeTrue()
        ->and($first->refresh()->hasLivePeriod())->toBeFalse();

    // AND MANY RETIRED VERSIONS COEXIST. The index's predicate excludes them, so a page with a long
    // history is not a page that has run out of activations.
    $third = makeVersion($item, 3);
    $third->status = SourceState::Ready;
    $third->activated_at = CarbonImmutable::now();
    $third->retired_at = CarbonImmutable::now();
    $third->save();

    expect(SourceVersion::withoutGlobalScopes()->where('source_item_id', $item->id)->count())->toBe(3);
});

it('refuses a retirement that never had an activation, and one that precedes it', function (): void {
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $version = makeVersion($item, 1);

    // "Retired" names the end of a period that never began. Without this a sweep looking for
    // versions that served and stopped would count rows that never served at all.
    $orphanRetirement = cascadeAttempt(static function () use ($version): void {
        $version->retired_at = CarbonImmutable::now();
        $version->save();
    });

    expect($orphanRetirement)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $orphanRetirement?->getMessage(), 'source_versions_retire_after_activate'))
        ->toBeTrue((string) $orphanRetirement?->getMessage());

    // And the ordering within the period. A retirement before the activation makes every "what was
    // live at time T" query return two versions or none.
    $fresh = SourceVersion::withoutGlobalScopes()->findOrFail($version->id);

    $backwards = cascadeAttempt(static function () use ($fresh): void {
        $fresh->activated_at = CarbonImmutable::now();
        $fresh->retired_at = CarbonImmutable::now()->subHour();
        $fresh->save();
    });

    expect($backwards)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $backwards?->getMessage(), 'source_versions_retire_not_before_activate'))
        ->toBeTrue((string) $backwards?->getMessage());
});

it('dedups a version by (item, ingest key), which is what makes a reprocess idempotent', function (): void {
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $key = bin2hex(random_bytes(32));

    makeVersion($item, 1, $key);

    // THE SAME CONTENT AND THE SAME FOUR CONFIGURATIONS PRODUCE THE SAME KEY, so the second request
    // must not mint a second version. This index is what makes a retried ingestion resolve to the
    // existing row rather than accumulate.
    $duplicate = cascadeAttempt(static function () use ($item, $key): void {
        makeVersion($item, 2, $key);
    });

    expect($duplicate)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $duplicate?->getMessage(), 'source_versions_item_ingest_key'))
        ->toBeTrue((string) $duplicate?->getMessage());

    // AND A DIFFERENT KEY IS ADMITTED, which is the positive control: an OCR config change, a
    // chunker bump or a reprocess nonce all change the key and must produce a new version. Without
    // this line the test would also pass against an index that refused every second version.
    $newRun = makeVersion($item, 2);

    expect($newRun->exists)->toBeTrue();
});

it('refuses an uppercase or malformed content hash, on every table that has one', function (): void {
    // R4: the hash is sixty-four LOWERCASE hex characters. `char(64)` alone would accept sixty-four
    // spaces, and an uppercase digest is the case that actually bites — it compares unequal under
    // COLLATE "C", so a corpus that had not changed would re-embed at a provider's per-token price
    // and nothing would raise.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $uppercase = cascadeAttempt(static function () use ($item): void {
        $item->content_hash = str_repeat('A', 64);
        $item->save();
    });

    expect($uppercase)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $uppercase?->getMessage(), 'source_items_content_hash_is_hex'))
        ->toBeTrue((string) $uppercase?->getMessage());

    $fresh = SourceItem::withoutGlobalScopes()->findOrFail($item->id);

    $blank = cascadeAttempt(static function () use ($fresh): void {
        $fresh->content_hash = str_repeat(' ', 64);
        $fresh->save();
    });

    expect($blank)->toBeInstanceOf(QueryException::class);
});

it('refuses a storage key outside the row\'s own organization prefix', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    [$source, $item] = seedSourceAndItem($organization);

    // kb-tenancy-isolation layer 5, enforced by the database against the ROW'S OWN tenant column.
    // A service can forget to prefix a key; a CHECK comparing against `organization_id` cannot.
    $foreignPrefix = cascadeAttempt(static function () use ($item, $other, $source): void {
        $item->storage_key = 'org/'.$other->id.'/sources/'.$source->id.'/original/handbook.pdf';
        $item->save();
    });

    expect($foreignPrefix)->toBeInstanceOf(
        QueryException::class,
        'an object key pointing into another organization\'s prefix was accepted, so a download URL '
        .'signed from this row would reach their storage.'
    );
    expect(str_contains((string) $foreignPrefix?->getMessage(), 'source_items_storage_key_is_tenant_scoped'))
        ->toBeTrue((string) $foreignPrefix?->getMessage());
});

it('refuses a display name that is a path, a control character, or a relative directory', function (string $displayName): void {
    // The storage key is GENERATED and never derived from this value, which is what makes traversal
    // structurally impossible. This constraint is the cheap second statement: a filename that LOOKS
    // like a path will eventually be joined to one by somebody.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $exception = cascadeAttempt(static function () use ($item, $displayName): void {
        $item->display_name = $displayName;
        $item->save();
    });

    expect($exception)->toBeInstanceOf(QueryException::class);
})->with([
    'a POSIX separator' => ['../../etc/passwd'],
    'a Windows separator' => ['..\\..\\evil.exe'],
    'a bare parent directory' => ['..'],
    'a single dot' => ['.'],
    'whitespace only' => ['   '],
]);

it('cannot use a CHECK constraint against a NUL truncation, and that is measured rather than assumed', function (): void {
    // `x.pdf\0.php` IS THE CLASSIC UPLOAD-NAME ATTACK and it does NOT reach this column, so the
    // `[[:cntrl:]]` clause of `source_items_display_name_is_not_a_path` never fires on it. Measured
    // on this database: `SELECT :v::text` bound with that string returns `x.pdf`, length 5. libpq
    // binds a C string, so the value is truncated at the NUL BEFORE PostgreSQL sees it — which
    // means PostgreSQL's own refusal of NUL in `text` (22021) never fires either.
    //
    // WHY THIS IS WRITTEN DOWN INSTEAD OF DELETED WITH THE DATASET ROW IT CAME FROM: because the
    // obvious reading of the constraint is that it defends against this, and it does not. The
    // defense is upstream and structural — the storage key is GENERATED, never derived from this
    // value, so a truncated name reaches no filesystem path — and the constraint's control-character
    // clause covers the ones that DO survive the binding, such as a newline smuggled into a
    // Content-Disposition header. A reviewer looking for the NUL case in the dataset above will not
    // find it; this test is why.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $item->display_name = "x.pdf\0.php";
    $item->save();

    expect($item->refresh()->display_name)->toBe('x.pdf');
});

it('refuses a control character that DOES survive the parameter binding', function (): void {
    // The half of the clause that is real. A newline in a filename is what turns a
    // `Content-Disposition` header into two headers, and unlike a NUL it travels through libpq
    // intact.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $exception = cascadeAttempt(static function () use ($item): void {
        $item->display_name = "invoice.pdf\r\nX-Injected: 1";
        $item->save();
    });

    expect($exception)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $exception?->getMessage(), 'source_items_display_name_is_not_a_path'))
        ->toBeTrue((string) $exception?->getMessage());
});

it('accepts an ordinary filename, and a unicode one', function (): void {
    // The positive control for the dataset above. Without it a CHECK that refused EVERYTHING would
    // pass every row of it — and `display_name` is the string a Content-Disposition header
    // re-emits, so refusing legitimate names is a real product failure rather than an
    // over-restriction nobody notices.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $item->display_name = 'Contrat de service (2026) — révisé.pdf';
    $item->save();

    expect($item->refresh()->display_name)->toBe('Contrat de service (2026) — révisé.pdf');
});

it('ties the origin URL to the source type in both directions', function (): void {
    $organization = Organization::factory()->create();

    // A crawl source with nothing to crawl.
    $noUrl = cascadeAttempt(static function () use ($organization): void {
        KnowledgeSource::factory()->recycle($organization)->create(['type' => SourceType::Url]);
    });

    expect($noUrl)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $noUrl?->getMessage(), 'knowledge_sources_origin_url_matches_type'))
        ->toBeTrue((string) $noUrl?->getMessage());

    // And the other direction: a file upload carrying a URL somebody expects us to fetch. Written
    // as one equality between two booleans so both mistakes hit the same constraint.
    $strayUrl = cascadeAttempt(static function () use ($organization): void {
        KnowledgeSource::factory()->recycle($organization)
            ->create(['type' => SourceType::File, 'origin_url' => 'https://example.com/sitemap.xml']);
    });

    expect($strayUrl)->toBeInstanceOf(QueryException::class);

    // The positive control: a crawl source WITH its URL.
    $crawl = KnowledgeSource::factory()->recycle($organization)
        ->crawl('https://example.com/sitemap.xml')->create();

    expect($crawl->refresh()->origin_url)->toBe('https://example.com/sitemap.xml');
});

it('refuses a non-http origin URL and one carrying userinfo', function (string $url): void {
    // NOT THE SSRF CHECK — that is the crawler's, it resolves DNS and re-checks after every
    // redirect, and no CHECK constraint substitutes for it. What this refuses is the class of value
    // that should never have reached the column: the schemes that are not fetches at all, and the
    // credential-carrying userinfo forms that make a stored URL a way to hand a secret to whoever
    // reads the row.
    $organization = Organization::factory()->create();

    $exception = cascadeAttempt(static function () use ($organization, $url): void {
        KnowledgeSource::factory()->recycle($organization)->crawl($url)->create();
    });

    expect($exception)->toBeInstanceOf(QueryException::class);
})->with([
    'file scheme' => ['file:///etc/passwd'],
    'gopher scheme' => ['gopher://example.com/'],
    'no scheme at all' => ['example.com/sitemap.xml'],
    'userinfo carrying a credential' => ['https://admin:hunter2@example.com/'],
    'a space in the authority' => ['https://exa mple.com/'],
]);

it('round-trips a PostgreSQL text array through the cast, including the shapes that break JSON', function (): void {
    // App\Support\Casts\PostgresTextArrayCast, against a real `text[]` column. The built-in `array`
    // cast is JSON and would write `["a","b"]`, which reads back as a ONE-element array whose single
    // element is that JSON fragment — silently.
    $organization = Organization::factory()->create();

    $tags = [
        'policy',
        // A comma, which is the separator: an implementation that split on it would cut this in
        // half and produce two tags nobody created.
        'legal, reviewed',
        // A double quote and a backslash, which are the escape characters.
        'the "old" set',
        'path\\like',
        // Braces, which delimit the literal itself.
        '{not an array}',
        // The literal word NULL, which has an UNQUOTED spelling meaning something else entirely.
        'NULL',
        // Non-ASCII, to prove nothing is byte-mangled on the way through.
        'révisé — 契約',
    ];

    $source = KnowledgeSource::factory()->recycle($organization)->create(['tags' => $tags]);

    expect($source->refresh()->tags)->toBe($tags);

    // AND THE EMPTY CASE, which is the majority state and the one an encoding bug hides in.
    $untagged = KnowledgeSource::factory()->recycle($organization)->create(['tags' => []]);

    expect($untagged->refresh()->tags)->toBe([]);
});

it('refuses a blank tag and a tag set past its bound', function (): void {
    $organization = Organization::factory()->create();

    $blank = cascadeAttempt(static function () use ($organization): void {
        KnowledgeSource::factory()->recycle($organization)->create(['tags' => ['policy', '']]);
    });

    expect($blank)->toBeInstanceOf(QueryException::class);
    expect(str_contains((string) $blank?->getMessage(), 'knowledge_sources_tags_well_formed'))
        ->toBeTrue((string) $blank?->getMessage());

    $tooMany = cascadeAttempt(static function () use ($organization): void {
        KnowledgeSource::factory()->recycle($organization)->create([
            'tags' => array_map(static fn (int $i): string => 'tag-'.$i, range(1, 51)),
        ]);
    });

    expect($tooMany)->toBeInstanceOf(QueryException::class);
});

it('holds the warning summary as a JSON OBJECT even when it is empty', function (): void {
    // JsonObjectCast, against the CHECK that refuses the array spelling. PHP cannot tell an empty
    // array from an empty map, so the built-in `array` cast writes the no-warnings majority as `[]`
    // — a JSON ARRAY — and every clean version would be refused by the database with a message
    // pointing at the constraint rather than at the encoding.
    $organization = Organization::factory()->create();
    [, $item] = seedSourceAndItem($organization);

    $version = makeVersion($item, 1);

    expect($version->refresh()->warning_summary)->toBe([]);

    $version->warning_summary = ['ocr_coverage' => 0.83, 'pages_degraded' => 2];
    $version->save();

    expect($version->refresh()->warning_summary)
        ->toBe(['ocr_coverage' => 0.83, 'pages_degraded' => 2]);
});

it('refuses a status outside the fifteen, on both tables that carry one', function (): void {
    // R3: one vocabulary, two columns, generated from SourceState::values() so the enum and the two
    // CHECK constraints cannot drift. The enum cast makes an invalid value unreachable through the
    // model, so the raw attribute is set past it — which is exactly the writer this file simulates.
    $organization = Organization::factory()->create();
    [$source, $item] = seedSourceAndItem($organization);

    $badSource = cascadeAttempt(static function () use ($source): void {
        $source->setRawAttributes(['status' => 'processing'] + $source->getRawOriginal(), true);
        $source->syncChanges();
        $source->newQueryWithoutScopes()->whereKey($source->id)->update(['status' => 'processing']);
    });

    expect($badSource)->toBeInstanceOf(
        QueryException::class,
        '`processing` was accepted, and it appears nowhere in services/ai-service/app/ingestion/'
        .'states.py — the seven-value list the old factory docblock carried is superseded.'
    );

    $badVersion = cascadeAttempt(static function () use ($item): void {
        $version = makeVersion($item, 1);
        $version->newQueryWithoutScopes()->whereKey($version->id)->update(['status' => 'pending']);
    });

    expect($badVersion)->toBeInstanceOf(QueryException::class);
});

it('keeps the whole cascade addressable by a ULID that is not a UUID', function (): void {
    // postgresql-patterns' definition of done: every id is `char(26) COLLATE "C"` and there is no
    // uuid column except `chunks.vector_point_id`. Asserted on the values rather than on the
    // catalog, because a `char(26)` column holding a truncated UUID would satisfy an introspection
    // check and break every cross-service join.
    $organization = Organization::factory()->create();
    [$source, $item] = seedSourceAndItem($organization);
    $version = makeVersion($item, 1);

    foreach ([$source->id, $item->id, $version->id] as $id) {
        expect(strlen($id))->toBe(26)
            ->and(Str::isUlid($id))->toBeTrue();
    }
});

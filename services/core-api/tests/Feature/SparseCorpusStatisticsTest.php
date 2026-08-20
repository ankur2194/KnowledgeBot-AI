<?php

declare(strict_types=1);

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Repositories\Contracts\SparseCorpusStatisticsRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Finding C2 — the document-frequency rollup
|--------------------------------------------------------------------------
|
| The tenancy property here is NOT the usual one. Scoping to the organization is necessary and not
| sufficient: the IDF factor must be summed over the RESOLVED ACTIVE-VERSION SET, because a term's
| rarity is a property of the scope it was counted in. An organization-wide rollup leaves a weak
| oracle INSIDE an organization, across bots — a part number appearing in one document weighs
| differently for a bot that cannot see that document, so the ranking leaks the existence of
| documents outside the bot's scope through a 200 with plausible results.
|
| These tests assert that the SCHEMA can express that scoping. `CorpusStatistics.for_scope` on the
| data-plane side raises on a foreign org, a moved version set and a foreign analyzer, and it reads
| these columns to do it; a rollup that could only be read org-wide would make all three checks
| unenforceable no matter how carefully the Python is written.
*/

const ANALYZER = 'sparse/v1';

/**
 * A REAL `source_versions` ROW, and the reason these ids stopped being `Str::ulid()` literals.
 *
 * `2026_08_07_000600`'s docblock recorded a foreign key it could not create — `source_versions` did
 * not exist in this repository — and stated what was owed when the table landed: a COMPOSITE
 * `(organization_id, source_version_id)` key on both sparse tables, `ON DELETE CASCADE` because
 * these rows are derived and must not be able to block the purge of the thing they were derived
 * from. Phase C1 paid it (`2026_08_20_002400`), so a statistic against an invented version id is
 * now a 23503 — which is exactly the point: a rollup that can name a version nobody created is a
 * rollup that survives its own corpus.
 *
 * THE SEEDING IS SLOWER AND THE TESTS ARE UNCHANGED IN WHAT THEY ASSERT. Every scoping property
 * below is still about `(organization, version set, analyzer)`; the only difference is that the
 * version ids now exist, which is what production guarantees and what the fixture previously did
 * not.
 */
function sparseVersion(Organization $organization, int $versionNumber = 1): string
{
    $source = KnowledgeSource::factory()->recycle($organization)->create(['type' => SourceType::File]);

    $item = new SourceItem;
    $item->organization_id = $organization->id;
    $item->source_id = $source->id;
    $item->canonical_key = 'org/'.$organization->id.'/sources/'.$source->id.'/items/'.Str::ulid();
    $item->save();

    $version = new SourceVersion;
    $version->organization_id = $organization->id;
    $version->source_item_id = $item->id;
    $version->version_number = $versionNumber;
    $version->content_hash = bin2hex(random_bytes(32));
    $version->ingest_key = bin2hex(random_bytes(32));
    $version->parser_cfg_version = 'parser/v1';
    $version->ocr_cfg_version = 'ocr/v1';
    $version->chunker_cfg_version = 'chunker/v1';
    $version->embedding_model_version = 'emb/v1:test:probe:d8:0000000000000000';
    $version->status = SourceState::Indexing;
    $version->save();

    return $version->id;
}

/**
 * @param  array<int, int>  $frequencies  term id => document frequency
 */
function seedTerms(string $orgId, string $versionId, int $documentTotal, array $frequencies, string $analyzer = ANALYZER): void
{
    $connection = Schema::getConnection();

    $connection->table('sparse_version_statistics')->insert([
        'organization_id' => $orgId,
        'source_version_id' => $versionId,
        'analyzer' => $analyzer,
        'document_total' => $documentTotal,
    ]);

    foreach ($frequencies as $termId => $frequency) {
        $connection->table('sparse_term_frequencies')->insert([
            'organization_id' => $orgId,
            'source_version_id' => $versionId,
            'analyzer' => $analyzer,
            'term_id' => $termId,
            'document_frequency' => $frequency,
        ]);
    }
}

/**
 * Read through the repository with a tenant context bound, as every real caller does.
 *
 * The models carry #[ScopedBy(OrganizationScope::class)], which FAILS CLOSED when no context is
 * bound — so a bare call here returns nothing, which is the correct production behaviour and a
 * confusing test failure. Binding it explicitly is what a request or a job does, and the last test
 * in this file asserts the unbound case on purpose rather than leaving it implicit.
 *
 * @param  list<string>  $versionIds
 * @param  list<int>  $termIds
 * @return array{document_total: int, document_frequencies: array<int, int>}
 */
function loadStats(string $orgId, array $versionIds, array $termIds, string $analyzer = ANALYZER): array
{
    return app(\App\Support\Tenancy\TenantContext::class)->runFor(
        $orgId,
        fn (): array => app(SparseCorpusStatisticsRepositoryInterface::class)
            ->load($orgId, $versionIds, $termIds, $analyzer),
    );
}

it('sums only the versions in the resolved active-version set', function (): void {
    $org = Organization::factory()->create();

    $active = sparseVersion($org);
    $retired = sparseVersion($org, versionNumber: 2);

    seedTerms($org->id, $active, documentTotal: 10, frequencies: [111 => 3]);
    // A retired or not-yet-published version. Its rows exist — they are written against the NEW
    // version id in the same transaction as its chunks, BEFORE it is active — and they must
    // contribute nothing until the active set names them.
    seedTerms($org->id, $retired, documentTotal: 90, frequencies: [111 => 80]);

    $stats = loadStats($org->id, [$active], [111], ANALYZER);

    // If this read summed the ORGANIZATION rather than the version set, both numbers would be
    // wildly different and the IDF of term 111 would reflect a corpus this query cannot search.
    expect($stats['document_total'])->toBe(10)
        ->and($stats['document_frequencies'])->toBe([111 => 3]);

    // Positive control on the other direction: the retired version's rows really are there, so
    // the assertion above is not passing because nothing was seeded.
    $both = loadStats($org->id, [$active, $retired], [111], ANALYZER);

    expect($both['document_total'])->toBe(100)
        ->and($both['document_frequencies'])->toBe([111 => 83]);
});

it('never sums another organization\'s statistics, even when handed their version id', function (): void {
    // THE TITLE CHANGED WITH THE FIXTURE AND THE PROPERTY DID NOT. It used to read "even for the
    // same version id" and seeded one invented ULID under both tenants — a collision ULID entropy
    // makes impossible, and which the composite foreign key added by `2026_08_20_002400` now makes
    // unwritable outright, since a version id belongs to exactly one organization. The stronger
    // shape is the one below: hand Org A's read Org B's REAL version id and require it to see
    // nothing. The org predicate is what refuses it, not the absence of the row.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // ONE VERSION ID PER ORGANIZATION NOW, AND THE TEST IS STRONGER FOR IT. The old fixture reused
    // a single invented ULID across both tenants to simulate a collision that ULID entropy makes
    // impossible; with the composite foreign key in place a version id genuinely belongs to exactly
    // one organization, so the shared-id shape cannot be written at all. What is asserted is
    // unchanged and is the property that matters: Org A's read must return Org A's numbers, and
    // Org B's rows must contribute nothing to it.
    $versionA = sparseVersion($orgA);
    $versionB = sparseVersion($orgB);

    seedTerms($orgA->id, $versionA, documentTotal: 5, frequencies: [222 => 2]);
    seedTerms($orgB->id, $versionB, documentTotal: 500, frequencies: [222 => 400]);

    $stats = loadStats($orgA->id, [$versionA], [222], ANALYZER);

    expect($stats['document_total'])->toBe(5)
        ->and($stats['document_frequencies'])->toBe([222 => 2]);

    // AND ORG A NAMING ORG B'S VERSION READS NOTHING. The organization predicate is what refuses
    // it, not the absence of the row — the row is right there, with a document total two orders of
    // magnitude larger, and a query that dropped the org term would return it.
    $foreign = loadStats($orgA->id, [$versionB], [222], ANALYZER);

    expect($foreign['document_total'])->toBe(0)
        ->and($foreign['document_frequencies'])->toBe([]);
});

it('never sums across analyzers', function (): void {
    // Term ids are blake2b under a fixed personalization, so two analyzers are two different id
    // spaces. Summing across them produces a number that is not a document frequency of anything,
    // and nothing would raise — a foreign term id is a legal bigint that simply matches no
    // posting, so the read returns a plausible number instead of an error.
    //
    // THE FIXTURE IS THE TEST. One analyzer per version cannot fail this assertion no matter what
    // the query binds, so both analyzers are seeded against THE SAME (org, version) and given
    // DISTINCT numbers in both tables. Every quantity below is chosen so that an analyzer-blind
    // read produces a value no correct read can produce:
    //
    //   document_frequency  4 and 9   -> a blind sum is 13, not 4 and not 9
    //   document_total     10 and 70  -> a blind sum is 80, not 10 and not 70
    //
    // The totals deliberately differ from each other rather than being equal, so the failure is
    // not the ambiguous "exactly double" that an equal-total fixture would produce.
    $org = Organization::factory()->create();
    $versionId = sparseVersion($org);

    seedTerms($org->id, $versionId, 10, [333 => 4], 'sparse/v1');
    seedTerms($org->id, $versionId, 70, [333 => 9], 'sparse/v2');

    $first = loadStats($org->id, [$versionId], [333], 'sparse/v1');

    expect($first['document_frequencies'])->toBe([333 => 4])
        // THE DOCUMENT TOTAL IS THE HALF THAT LOOKS ANALYZER-INDEPENDENT AND IS NOT. It reads as a
        // count of the version's chunks, which the analyzer does not change — but the ROW is
        // written per analyzer, so an unbound analyzer here sums 80 into the IDF numerator and
        // shifts every score by a constant. `sparse_version_statistics` is a second table with a
        // second predicate; asserting only the frequencies leaves it entirely unexercised.
        ->and($first['document_total'])->toBe(10);

    // Both directions. Asserting only `sparse/v1` would still pass against a query hard-coded to
    // 'sparse/v1' — which is not an analyzer binding, it is a constant.
    $second = loadStats($org->id, [$versionId], [333], 'sparse/v2');

    expect($second['document_frequencies'])->toBe([333 => 9])
        ->and($second['document_total'])->toBe(70);

    // An analyzer with no rows returns the empty scope, not the union of the two that do exist.
    $absent = loadStats($org->id, [$versionId], [333], 'sparse/v3');

    expect($absent['document_frequencies'])->toBe([])
        ->and($absent['document_total'])->toBe(0);
});

it('never sums across analyzers on the no-terms path either', function (): void {
    // `load()` returns EARLY when the caller analyzed the query to no terms — a dense-only run —
    // and that branch reaches `documentTotal()` through its own call site. A refactor that drops
    // the analyzer argument on one of the two call sites is caught here and nowhere else.
    $org = Organization::factory()->create();
    $versionId = sparseVersion($org);

    seedTerms($org->id, $versionId, 3, [777 => 1], 'sparse/v1');
    seedTerms($org->id, $versionId, 40, [777 => 1], 'sparse/v2');

    expect(loadStats($org->id, [$versionId], [], 'sparse/v1')['document_total'])->toBe(3)
        ->and(loadStats($org->id, [$versionId], [], 'sparse/v2')['document_total'])->toBe(40);
});

it('raises rather than widening when the active-version set is empty', function (): void {
    // The same rule tenant_filter enforces on the Qdrant side. An empty scope is a valid outcome
    // upstream; widening it is not, and with no version predicate this query would sum every
    // version in the organization.
    $org = Organization::factory()->create();

    expect(fn (): array => loadStats($org->id, [], [111], ANALYZER))->toThrow(\InvalidArgumentException::class);

    expect(fn (): array => loadStats('', [(string) Str::ulid()], [111], ANALYZER))->toThrow(\InvalidArgumentException::class);
});

it('stores a term id above the signed 32-bit range', function (): void {
    // Qdrant sparse indices are UNSIGNED 32-bit and PostgreSQL has no unsigned types. An
    // `integer` column tops out at 2^31-1, so roughly HALF of every analyzer's term space would
    // fail to insert — and the symptom would be a sparse branch that quietly indexes less than it
    // was given.
    $org = Organization::factory()->create();
    $versionId = sparseVersion($org);

    $highTerm = 4294967295;   // 2^32 - 1

    seedTerms($org->id, $versionId, 1, [$highTerm => 1]);

    $stats = loadStats($org->id, [$versionId], [$highTerm], ANALYZER);

    expect($stats['document_frequencies'])->toBe([$highTerm => 1]);
});

it('refuses a term id outside the unsigned 32-bit range', function (): void {
    $org = Organization::factory()->create();

    expect(function () use ($org): void {
        seedTerms($org->id, sparseVersion($org), 1, [4294967296 => 1]);
    })->toThrow(QueryException::class);

    expect(function () use ($org): void {
        seedTerms($org->id, sparseVersion($org, versionNumber: 2), 1, [-1 => 1]);
    })->toThrow(QueryException::class);
});

it('refuses a zero document frequency, because absence already means zero', function (): void {
    // CorpusStatistics.idf_for treats an absent term as df = 0, i.e. maximally rare. Storing zeros
    // would double a table that already reaches term cardinality times version count, purely to
    // express the default.
    $org = Organization::factory()->create();

    expect(function () use ($org): void {
        seedTerms($org->id, sparseVersion($org), 1, [444 => 0]);
    })->toThrow(QueryException::class);
});

it('is reproducible by key, so a redelivered task overwrites rather than accumulates', function (): void {
    // The primary keys are the mechanism: (organization_id, source_version_id, analyzer, term_id).
    // A second insert of the same key is a unique violation, so the writer's ON CONFLICT DO UPDATE
    // has something to conflict on — and an append-only shape, where a Celery redelivery would
    // double every frequency, is unrepresentable.
    $org = Organization::factory()->create();
    $versionId = sparseVersion($org);

    seedTerms($org->id, $versionId, 1, [555 => 1]);

    expect(function () use ($org, $versionId): void {
        seedTerms($org->id, $versionId, 1, [555 => 2]);
    })->toThrow(QueryException::class);
});

it('returns the document total even when the query analyzed to no terms', function (): void {
    // A dense-only run. "The scope holds no documents" and "the lookup failed" must stay
    // distinguishable — the second is an error, never a degradation.
    $org = Organization::factory()->create();
    $versionId = sparseVersion($org);

    seedTerms($org->id, $versionId, 12, [666 => 3]);

    $stats = loadStats($org->id, [$versionId], [], ANALYZER);

    expect($stats['document_total'])->toBe(12)
        ->and($stats['document_frequencies'])->toBe([]);
});

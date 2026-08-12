<?php

declare(strict_types=1);

use App\Models\Organization;
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

    $active = (string) Str::ulid();
    $retired = (string) Str::ulid();

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

it('never sums another organization\'s statistics, even for the same version id', function (): void {
    // A version id collision across organizations should be impossible, and the org predicate is
    // what makes that claim safe rather than a hope about ULID entropy.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $versionId = (string) Str::ulid();

    seedTerms($orgA->id, $versionId, documentTotal: 5, frequencies: [222 => 2]);
    seedTerms($orgB->id, $versionId, documentTotal: 500, frequencies: [222 => 400]);

    $stats = loadStats($orgA->id, [$versionId], [222], ANALYZER);

    expect($stats['document_total'])->toBe(5)
        ->and($stats['document_frequencies'])->toBe([222 => 2]);
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
    $versionId = (string) Str::ulid();

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
    $versionId = (string) Str::ulid();

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
    $versionId = (string) Str::ulid();

    $highTerm = 4294967295;   // 2^32 - 1

    seedTerms($org->id, $versionId, 1, [$highTerm => 1]);

    $stats = loadStats($org->id, [$versionId], [$highTerm], ANALYZER);

    expect($stats['document_frequencies'])->toBe([$highTerm => 1]);
});

it('refuses a term id outside the unsigned 32-bit range', function (): void {
    $org = Organization::factory()->create();

    expect(function () use ($org): void {
        seedTerms($org->id, (string) Str::ulid(), 1, [4294967296 => 1]);
    })->toThrow(QueryException::class);

    expect(function () use ($org): void {
        seedTerms($org->id, (string) Str::ulid(), 1, [-1 => 1]);
    })->toThrow(QueryException::class);
});

it('refuses a zero document frequency, because absence already means zero', function (): void {
    // CorpusStatistics.idf_for treats an absent term as df = 0, i.e. maximally rare. Storing zeros
    // would double a table that already reaches term cardinality times version count, purely to
    // express the default.
    $org = Organization::factory()->create();

    expect(function () use ($org): void {
        seedTerms($org->id, (string) Str::ulid(), 1, [444 => 0]);
    })->toThrow(QueryException::class);
});

it('is reproducible by key, so a redelivered task overwrites rather than accumulates', function (): void {
    // The primary keys are the mechanism: (organization_id, source_version_id, analyzer, term_id).
    // A second insert of the same key is a unique violation, so the writer's ON CONFLICT DO UPDATE
    // has something to conflict on — and an append-only shape, where a Celery redelivery would
    // double every frequency, is unrepresentable.
    $org = Organization::factory()->create();
    $versionId = (string) Str::ulid();

    seedTerms($org->id, $versionId, 1, [555 => 1]);

    expect(function () use ($org, $versionId): void {
        seedTerms($org->id, $versionId, 1, [555 => 2]);
    })->toThrow(QueryException::class);
});

it('returns the document total even when the query analyzed to no terms', function (): void {
    // A dense-only run. "The scope holds no documents" and "the lookup failed" must stay
    // distinguishable — the second is an error, never a degradation.
    $org = Organization::factory()->create();
    $versionId = (string) Str::ulid();

    seedTerms($org->id, $versionId, 12, [666 => 3]);

    $stats = loadStats($org->id, [$versionId], [], ANALYZER);

    expect($stats['document_total'])->toBe(12)
        ->and($stats['document_frequencies'])->toBe([]);
});

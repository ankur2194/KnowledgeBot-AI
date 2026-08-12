<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\SparseTermFrequency;
use App\Models\SparseVersionStatistic;
use App\Repositories\Contracts\SparseCorpusStatisticsRepositoryInterface;
use InvalidArgumentException;

final class EloquentSparseCorpusStatisticsRepository implements SparseCorpusStatisticsRepositoryInterface
{
    /**
     * @param  list<string>  $allowedVersionIds
     * @param  list<int>  $termIds
     * @return array{document_total: int, document_frequencies: array<int, int>}
     */
    public function load(
        string $organizationId,
        array $allowedVersionIds,
        array $termIds,
        string $analyzer,
    ): array {
        if ($organizationId === '' || $allowedVersionIds === []) {
            // AN EMPTY SCOPE IS A VALID OUTCOME UPSTREAM; WIDENING IT IS NOT. The same rule
            // `tenant_filter` enforces on the Qdrant side: with no version predicate this query
            // would sum every version in the organization, which is the cross-bot oracle the
            // version-set scoping exists to close — and it would return a plausible ranking with
            // nothing raised anywhere.
            throw new InvalidArgumentException(
                'Sparse corpus statistics cannot be read without an organization and a non-empty '
                .'active-version set. An organization-wide sum weights a query by documents the '
                .'querying bot cannot see.',
            );
        }

        if ($termIds === []) {
            // No terms is not an error and not an empty scope: the caller analyzed a query to no
            // terms, which is a dense-only run. The document total is still real and is what
            // distinguishes "the scope holds no documents" from "the lookup failed".
            return [
                'document_total' => $this->documentTotal($organizationId, $allowedVersionIds, $analyzer),
                'document_frequencies' => [],
            ];
        }

        $rows = SparseTermFrequency::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('source_version_id', $allowedVersionIds)
            // The analyzer is part of the key, not a filter of convenience: term ids under two
            // analyzers are two different id spaces, and summing across them produces a number
            // that is not a document frequency of anything.
            ->where('analyzer', '=', $analyzer)
            ->whereIn('term_id', $termIds)
            ->groupBy('term_id')
            ->selectRaw('term_id, sum(document_frequency) as document_frequency')
            ->get();

        /** @var array<int, int> $frequencies */
        $frequencies = [];

        foreach ($rows as $row) {
            $frequencies[(int) $row->getAttribute('term_id')] = (int) $row->getAttribute('document_frequency');
        }

        return [
            'document_total' => $this->documentTotal($organizationId, $allowedVersionIds, $analyzer),
            'document_frequencies' => $frequencies,
        ];
    }

    /**
     * @param  list<string>  $allowedVersionIds
     */
    private function documentTotal(string $organizationId, array $allowedVersionIds, string $analyzer): int
    {
        return (int) SparseVersionStatistic::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('source_version_id', $allowedVersionIds)
            ->where('analyzer', '=', $analyzer)
            ->sum('document_total');
    }
}

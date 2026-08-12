<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

interface SparseCorpusStatisticsRepositoryInterface
{
    /**
     * Document frequencies for $termIds within EXACTLY this scope.
     *
     * $allowedVersionIds is the resolved active-version set — the same set the Qdrant tenant
     * filter is built from — and NOT the organization as a whole. An empty version set RAISES
     * rather than widening: an org-wide sum would leave a cross-bot oracle inside the
     * organization, where a term's rarity reflects documents the queried bot cannot see.
     *
     * THE SCOPE IS THREE ARGUMENTS, NOT TWO. $analyzer is the third, it is required and
     * positional like the others, and it is the one whose omission is invisible. Term ids are
     * `blake2b(term)` under a fixed personalization, so two analyzers are two id spaces over the
     * same text: a foreign term id is a legal bigint matching no posting, and a read that drops
     * the analyzer predicate returns a number that is not a document frequency of anything
     * without raising. The implementation issues
     * `organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3 AND
     * term_id = ANY($4)` for the frequencies and the same first three predicates for the total.
     *
     * The production reader is `CorpusStatisticsStore` in
     * services/ai-service/app/retrieval/sparse.py, which issues the same query on the data-plane
     * side. This method exists because Laravel owns the schema and therefore owns the statement
     * that proves the schema can express the scoping — a rollup that could only be read org-wide
     * would make `CorpusStatistics.for_scope`'s three checks unenforceable no matter how carefully
     * the Python is written.
     *
     * @param  list<string>  $allowedVersionIds
     * @param  list<int>  $termIds
     * @return array{document_total: int, document_frequencies: array<int, int>}
     */
    public function load(
        string $organizationId,
        array $allowedVersionIds,
        array $termIds,
        string $analyzer,
    ): array;
}

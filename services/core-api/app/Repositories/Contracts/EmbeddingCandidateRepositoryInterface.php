<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Services\Embedding\EmbeddingCandidate;

interface EmbeddingCandidateRepositoryInterface
{
    /**
     * Every (connection, model row) pair in $organizationId that could conceivably embed.
     *
     * THE ORGANIZATION IS A REQUIRED, POSITIONAL ARGUMENT and there is no overload without it. The
     * global scope reads the same TenantContext this method's caller does, so on a queue worker
     * with a stale context both layers fail together unless the argument is explicit
     * (laravel-control-plane).
     *
     * "Could conceivably" is doing no work: this method applies NO capability judgement at all. It
     * returns candidates and the data plane decides. Filtering here on a locally-guessed notion of
     * "looks like an embedding model" would be the second resolution path that
     * embedding_selection.py exists to prevent.
     *
     * @return list<EmbeddingCandidate>
     */
    public function forOrg(string $organizationId): array;
}

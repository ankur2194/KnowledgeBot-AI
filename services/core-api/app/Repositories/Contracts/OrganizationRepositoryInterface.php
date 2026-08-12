<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Organization;
use App\Services\Embedding\EmbeddingDesignation;

interface OrganizationRepositoryInterface
{
    /**
     * Write (or clear) the embedding designation for ONE organization.
     *
     * $organizationId is required and positional for the same reason every other repository method
     * takes it: the global scope reads the ambient context, so on a path where that context is
     * stale both layers fail together unless the argument is explicit.
     *
     * Returns the refreshed organization.
     */
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
    ): Organization;
}

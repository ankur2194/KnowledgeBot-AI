<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Organization;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Embedding\EmbeddingDesignation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EloquentOrganizationRepository implements OrganizationRepositoryInterface
{
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
    ): Organization {
        return DB::transaction(function () use ($organizationId, $designation): Organization {
            // lockForUpdate, because this is a read-modify-write on a value another transaction
            // can change between the read and the write — two administrators designating at once
            // is textbook write skew (postgresql-patterns, "Isolation"). What is at stake is not a
            // counter: the losing write decides which vector space every future corpus is indexed
            // under, and nothing downstream would ever raise about it.
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            if (! $organization instanceof Organization) {
                throw new RuntimeException("Organization [{$organizationId}] no longer exists.");
            }

            // forceFill is NOT used and the columns are NOT fillable: the designation is written
            // here, through one method, and nowhere else. Assigning the attributes directly keeps
            // Model::shouldBeStrict()'s guard on mass assignment intact for every other path.
            $organization->embedding_connection_id = $designation?->connectionId;
            $organization->embedding_model = $designation?->model;

            // The database has the last word. The composite foreign key
            // (id, embedding_connection_id) -> provider_connections (organization_id, id) rejects
            // a designation that names another organization's connection even if every layer
            // above it were bypassed, and the CHECK rejects half a designation.
            $organization->save();

            return $organization;
        });
    }
}

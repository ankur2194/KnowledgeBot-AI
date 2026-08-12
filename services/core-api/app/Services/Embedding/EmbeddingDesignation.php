<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use App\Models\Organization;

/**
 * The organization's explicit answer to "which connection embeds", as a PAIR.
 *
 * A bare connection id would not do: one connection legitimately carries several embedding rows,
 * and `text-embedding-3-large` and `text-embedding-3-small` on one credential are two vector
 * spaces. Designating the connection alone would leave the space undecided, and "undecided" is
 * resolved differently by the indexer and by the query path only when someone edits an unrelated
 * row — which is the silent failure the whole of C1 is arranged around.
 */
final readonly class EmbeddingDesignation
{
    public function __construct(
        public string $connectionId,
        public string $model,
    ) {}

    /**
     * Read the stored designation off an organization, or null when it has none.
     *
     * Null is a legitimate, common state: an organization with exactly one embedding-capable
     * connection never needs to designate anything, and the resolution rule answers it from the
     * connection set. The CHECK constraint on the table guarantees the two columns are both set or
     * both null, so a half-designation cannot reach this method.
     */
    public static function fromOrganization(Organization $organization): ?self
    {
        $connectionId = $organization->embedding_connection_id;
        $model = $organization->embedding_model;

        if ($connectionId === null || $model === null) {
            return null;
        }

        return new self($connectionId, $model);
    }

    /**
     * @return array{connection_id: string, model: string}
     */
    public function toArray(): array
    {
        return ['connection_id' => $this->connectionId, 'model' => $this->model];
    }
}

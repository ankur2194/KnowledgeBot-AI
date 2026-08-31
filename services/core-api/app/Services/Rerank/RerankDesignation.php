<?php

declare(strict_types=1);

namespace App\Services\Rerank;

use App\Models\Organization;

/**
 * The organization's answer to "which connection reranks", as a PAIR.
 *
 * A bare connection id would not do, for the same reason it would not do for embedding: one
 * connection legitimately carries several model rows, and two ranking models on one credential are
 * two different score distributions. `app/rag/rerank.py`'s calibration is keyed by
 * `(provider, model)` and `RerankCalibration` refuses to be constructed for a scale nobody has
 * measured — so designating the connection alone would name a credential and leave the thing that
 * decides what a score MEANS undecided.
 *
 * NULL IS NOT AN ABSENT VALUE HERE, IT IS A CHOICE. `RerankDesignation::fromOrganization()`
 * returning null means the organization has chosen not to rerank, and the pipeline serves fused
 * order through `evidence.select_unranked`. Contrast EmbeddingDesignation, where null means "let
 * the resolution rule pick" and the failure to pick blocks ingestion outright.
 */
final readonly class RerankDesignation
{
    public function __construct(
        public string $connectionId,
        public string $model,
    ) {}

    /**
     * Read the stored designation off an organization, or null when it has none.
     *
     * The CHECK constraint on the table guarantees the two columns are both set or both null, so a
     * half-designation cannot reach this method — which is what lets the null branch below mean one
     * thing rather than three.
     */
    public static function fromOrganization(Organization $organization): ?self
    {
        $connectionId = $organization->rerank_connection_id;
        $model = $organization->rerank_model;

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

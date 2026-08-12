<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\ProviderConnectionStatus;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface;
use App\Services\Embedding\EmbeddingCandidate;

final class EloquentEmbeddingCandidateRepository implements EmbeddingCandidateRepositoryInterface
{
    /**
     * @return list<EmbeddingCandidate>
     */
    public function forOrg(string $organizationId): array
    {
        // THREE org predicates on one query, and none of them is redundant.
        //
        //   1. `where('provider_models.organization_id', $organizationId)` — the explicit scope.
        //      This is the mechanism; the #[ScopedBy] global scope on ProviderModelEntry is a backstop
        //      that reads the ambient context and therefore fails in exactly the situation the
        //      explicit argument is for (a worker whose context is stale or empty).
        //   2. The join predicate carries `organization_id` on BOTH sides. Joining on
        //      `provider_connection_id` alone would be correct only because the composite foreign
        //      key makes it correct — and a join that is safe because of a constraint written in
        //      another file is a join that stops being safe when somebody relaxes that constraint.
        //   3. `whereHas`-free by construction: an eager-loaded relation would issue a SECOND
        //      query whose scoping depends on the relation's own model, and the connection row is
        //      what supplies `provider`, which is half the vector-space identity.
        //
        // The connection STATUS filter is a capability question only in the trivial sense: a
        // revoked credential cannot embed anything. It is deliberately the only judgement this
        // method makes, and it is about the credential rather than about the model.
        $rows = ProviderModelEntry::query()
            ->join('provider_connections', function ($join) use ($organizationId): void {
                $join->on('provider_connections.id', '=', 'provider_models.provider_connection_id')
                    ->on('provider_connections.organization_id', '=', 'provider_models.organization_id')
                    ->where('provider_connections.organization_id', '=', $organizationId);
            })
            ->where('provider_models.organization_id', '=', $organizationId)
            ->where('provider_models.enabled', '=', true)
            ->where('provider_connections.status', '=', ProviderConnectionStatus::Active->value)
            // Deterministic in the SET of rows rather than in whatever order the planner returned
            // them. The data-plane rule already de-duplicates and re-sorts by its own total key,
            // so this is belt and braces — but it makes the REQUEST BODY stable too, which is what
            // makes a readiness response diffable between two runs.
            ->orderBy('provider_models.provider_connection_id')
            ->orderBy('provider_models.model')
            ->get([
                'provider_models.provider_connection_id',
                'provider_models.model',
                'provider_models.capability_flags',
                'provider_models.context_window',
                'provider_models.max_output_tokens',
                'provider_connections.provider as connection_provider',
            ]);

        return array_values($rows->map(static function (ProviderModelEntry $row): EmbeddingCandidate {
            $provider = $row->getAttribute('connection_provider');

            return new EmbeddingCandidate(
                connectionId: $row->provider_connection_id,
                provider: is_string($provider) ? $provider : '',
                model: $row->model,
                supported: $row->supportedCapabilities(),
                contextWindow: $row->context_window,
                maxOutputTokens: $row->max_output_tokens,
            );
        })->all());
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\ProviderModelDeletion;
use App\Models\Organization;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Services\Providers\NewProviderModelEntry;
use App\Services\Providers\ProviderModelEdit;
use Closure;
use Illuminate\Support\Facades\DB;

final class EloquentProviderModelRepository implements ProviderModelRepositoryInterface
{
    /**
     * @return list<ProviderModelEntry>
     */
    public function forConnection(string $organizationId, string $connectionId): array
    {
        // BOTH PREDICATES, EXPLICITLY. The composite foreign key makes `provider_connection_id`
        // functionally determine `organization_id`, so the second term is redundant against a
        // consistent database — and that is precisely why it is written out: "correct because of a
        // constraint in another file" is not the property this layer exists to have. The explicit
        // pair is also exactly the leading two columns of
        // `provider_models_org_connection_model`, so this is an index range rather than a filter.
        //
        // ORDER: `model` then `id`. `model` because that is what an operator scans the table by
        // and it is the third column of the index, so the range comes out pre-sorted; `id` as the
        // tie-breaker because `model` is unique within a connection today and a deterministic
        // order must not depend on that staying true.
        /** @var list<ProviderModelEntry> $rows */
        $rows = ProviderModelEntry::query()
            ->where('organization_id', '=', $organizationId)
            ->where('provider_connection_id', '=', $connectionId)
            ->orderBy('model')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    public function modelExists(string $organizationId, string $connectionId, string $model): bool
    {
        return ProviderModelEntry::query()
            ->where('organization_id', '=', $organizationId)
            ->where('provider_connection_id', '=', $connectionId)
            ->where('model', '=', $model)
            ->exists();
    }

    public function entryExists(string $organizationId, string $connectionId, string $modelId): bool
    {
        // ALL THREE PREDICATES. The organization is the tenant scope, the connection is the pair
        // check no constraint on `bots` performs, and the key is the row. Dropping the connection
        // term would silently accept a bot naming connection A with a model registered under
        // connection B — a configuration the database will store and the data plane cannot
        // interpret, because the credential and the model would come from different accounts.
        return ProviderModelEntry::query()
            ->where('organization_id', '=', $organizationId)
            ->where('provider_connection_id', '=', $connectionId)
            ->whereKey($modelId)
            ->exists();
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function create(
        string $organizationId,
        string $connectionId,
        NewProviderModelEntry $input,
        Closure $audit,
    ): ProviderModelEntry {
        return DB::transaction(function () use ($organizationId, $connectionId, $input, $audit): ProviderModelEntry {
            $row = new ProviderModelEntry;

            // The ownership column is assigned here, from the authenticated context passed in as
            // an argument — never from the DTO, which does not carry one, and never from request
            // input. It is not in $fillable, so this is the only place it can be set. The same
            // reasoning EloquentProviderConnectionRepository::attachModel() states, and the
            // composite foreign key `(organization_id, provider_connection_id)` re-checks the pair
            // at the statement boundary.
            $row->organization_id = $organizationId;
            $row->provider_connection_id = $connectionId;
            $row->model = $input->model;
            $row->display_name = $input->displayName;

            // THE ENVELOPE, NOT A BARE LIST. `ProviderModelEntry::supportedCapabilities()` reads
            // `capability_flags['supported']` and returns [] — "claims nothing" — for anything
            // else, with no error anywhere. Writing `$input->supported` directly here is a
            // readiness bug that surfaces days later as an organization that cannot ingest.
            $row->capability_flags = ['supported' => $input->supported];

            $row->context_window = $input->contextWindow;
            $row->max_output_tokens = $input->maxOutputTokens;
            $row->enabled = $input->enabled;

            foreach ($input->pricing->toColumns() as $column => $value) {
                $row->setAttribute($column, $value);
            }

            // 23505 IS POSSIBLE HERE AND IS NOT HANDLED IN THIS FILE. The service performs a
            // scoped pre-flight existence check for the readable message and catches the SQLSTATE
            // for the race that check cannot win. The unique index
            // `provider_models_org_connection_model` is the authority either way.
            $row->save();

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT
            // so an ON_FAILURE_ABORT audit failure rethrows and takes the row with it.
            $audit($row);

            return $row;
        });
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function update(
        string $organizationId,
        string $connectionId,
        string $modelId,
        ProviderModelEdit $edit,
        Closure $audit,
    ): ?ProviderModelEntry {
        return DB::transaction(function () use (
            $organizationId,
            $connectionId,
            $modelId,
            $edit,
            $audit,
        ): ?ProviderModelEntry {
            $row = $this->lock($organizationId, $connectionId, $modelId);

            if ($row === null) {
                // The route binding already 404'd a foreign id long before this line; reaching
                // here means the row was deleted between the binding and this transaction. Null
                // rather than an exception, so the caller renders the same 404 the binding would
                // have rather than a 500 describing a race.
                return null;
            }

            $row->display_name = $edit->displayName;
            $row->capability_flags = ['supported' => $edit->supported];
            $row->context_window = $edit->contextWindow;
            $row->max_output_tokens = $edit->maxOutputTokens;
            $row->enabled = $edit->enabled;

            foreach ($edit->pricing->toColumns() as $column => $value) {
                $row->setAttribute($column, $value);
            }

            // `model` IS NOT ASSIGNED, and there is no member on ProviderModelEdit that could
            // carry it — see that class for why a rename is a re-index rather than an edit.
            $row->save();

            $audit($row);

            return $row;
        });
    }

    /**
     * @param  Closure(ProviderModelEntry): void  $audit
     */
    public function delete(
        string $organizationId,
        string $connectionId,
        string $modelId,
        Closure $audit,
    ): ProviderModelDeletion {
        return DB::transaction(function () use (
            $organizationId,
            $connectionId,
            $modelId,
            $audit,
        ): ProviderModelDeletion {
            $row = $this->lock($organizationId, $connectionId, $modelId);

            if ($row === null) {
                return ProviderModelDeletion::Missing;
            }

            // THE DESIGNATION CHECK, INSIDE THE TRANSACTION AND UNDER THE SAME ROW LOCK THE
            // DESIGNATION WRITE TAKES. EloquentOrganizationRepository::designateEmbeddingConnection()
            // does `Organization::query()->whereKey(...)->lockForUpdate()`, so the two serialise on
            // this row: whichever gets the lock first, the loser re-reads the committed value
            // rather than acting on a stale one. Without it, an administrator designating this
            // pair between the controller's pre-flight 409 and this DELETE would win, and the
            // organization would be left naming a `provider_models` row that no longer exists —
            // discovered at the next upload, as a resolution error nobody can connect to an action.
            //
            // THE LOCK ALONE ONLY CLOSED ONE DIRECTION, WHICH IS WORTH STATING HERE BECAUSE THIS
            // COMMENT USED TO IMPLY OTHERWISE. Serialising says which transaction runs second; it
            // does not say what that one checks. This side re-reads the DESIGNATION, so a
            // designate racing a delete is refused here. A delete racing a DESIGNATE was open
            // until designateEmbeddingConnection() grew the mirror-image check — an org-scoped
            // existence query on the pair, under the same lock — because it wrote the designation
            // without re-reading the catalog. Both halves are needed and neither is redundant.
            //
            // NOTHING IN THE DATABASE ENFORCES THIS. There is no foreign key from
            // `organizations (embedding_connection_id, embedding_model)` to
            // `provider_models (provider_connection_id, model)`; the composite key on
            // `embedding_connection_id` ties the designation to a CONNECTION only. So unlike the
            // connection delete — where the constraint is the authority and the check is the good
            // message — THIS check IS the authority, which is why it is here rather than only in
            // the controller.
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            // BOTH DESIGNATIONS, NOT ONE (`docs/22` § T36). This check read the embedding pair
            // only, so deleting the row the RERANK designation named succeeded and reranking
            // stopped — no error, no metric movement, answers quietly worse. The two are separate
            // arms rather than an `||` because the caller turns the verdict into the operator's
            // remedy, and the remedies are different endpoints.
            //
            // NO SECOND LOCK IS TAKEN, and that is why this is a safe place to add the arm.
            // `$organization` is already held FOR UPDATE and carries both pairs;
            // `designateRerankConnection()` re-verifies the catalog under this same
            // `organizations` row lock, so the rerank designate/delete race serialises exactly as
            // the embedding one does. Reaching for a lock on `provider_models` here instead would
            // order the two paths model -> organizations while the designate path orders them
            // organizations -> model, which is the ABBA deadlock that method's docblock warns
            // about at length.
            if ($organization instanceof Organization) {
                if ($organization->embedding_connection_id === $connectionId
                    && $organization->embedding_model === $row->model) {
                    return ProviderModelDeletion::DesignatedForEmbedding;
                }

                if ($organization->rerank_connection_id === $connectionId
                    && $organization->rerank_model === $row->model) {
                    return ProviderModelDeletion::DesignatedForRerank;
                }
            }

            // BEFORE the delete, because after it there is nothing left to describe: this is a
            // hard delete and the audit row is the only surviving record of the catalog entry. It
            // is also inside the transaction, so an ON_FAILURE_ABORT write failure leaves the row
            // intact rather than deleting it untraceably.
            $audit($row);

            $row->delete();

            return ProviderModelDeletion::Deleted;
        });
    }

    /**
     * One catalog row of ONE organization's connection, locked FOR UPDATE.
     *
     * The lock is what makes "read the row, decide, write it" a decision rather than a guess: two
     * edits, or an edit racing a delete, serialise here instead of interleaving. Both ownership
     * predicates are on the SELECT rather than only on the model's global scope, because the lock
     * has to be taken on a row this organization actually owns — a lock acquired under a stale
     * ambient context would be a lock on somebody else's row.
     */
    private function lock(
        string $organizationId,
        string $connectionId,
        string $modelId,
    ): ?ProviderModelEntry {
        return ProviderModelEntry::query()
            ->where('organization_id', '=', $organizationId)
            ->where('provider_connection_id', '=', $connectionId)
            ->whereKey($modelId)
            ->lockForUpdate()
            ->first();
    }
}

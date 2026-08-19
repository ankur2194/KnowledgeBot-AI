<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\ProviderModelDeletion;
use App\Models\ProviderModelEntry;
use App\Services\Providers\NewProviderModelEntry;
use App\Services\Providers\ProviderModelEdit;
use Closure;

/**
 * The catalog of models under one connection.
 *
 * ── EVERY METHOD TAKES `$organizationId` AND `$connectionId` FIRST, AND POSITIONALLY ───────────
 *
 * BOTH, on every method, and not because the second implies the first. It does — the composite
 * foreign key `(organization_id, provider_connection_id) -> provider_connections (organization_id,
 * id)` makes a connection id resolve to exactly one organization, which is the same argument the
 * create migration uses to justify its tenant-leading unique index. But "correct only because of a
 * constraint written in another file" is not the property this layer is for: the explicit
 * organization predicate is the MECHANISM (kb-tenancy-isolation), `#[ScopedBy(OrganizationScope)]`
 * on the model is the backstop, and the two fail differently. The backstop reads the ambient
 * TenantContext, so it fails in exactly the situation the explicit argument exists for — a queue
 * worker or a pooled request whose context is stale.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ────────────────────────────
 *
 * All three `provider.model.*` operations are `ON_FAILURE_ABORT`, so a failed audit write must
 * roll the state change back. App\Services\Audit\AuditLogger opens no transaction of its own — it
 * cannot know what else belongs inside one — and `Illuminate\Support\Facades\DB` is arch-pinned to
 * App\Repositories\Eloquent, so this layer is the only place the wrapping can happen. The closure
 * is REQUIRED rather than optional: there is no overload that omits it and `null` is not an
 * accepted value, so a future caller cannot write the state change with no row.
 *
 * The closure runs INSIDE the transaction, after the write so the row's identifiers are settled,
 * and before the commit so an ON_FAILURE_ABORT rethrow takes the change with it.
 */
interface ProviderModelRepositoryInterface
{
    /**
     * Every catalog row under ONE connection of ONE organization.
     *
     * ORDERED DETERMINISTICALLY AND NEVER BY THE PLANNER'S WHIM: two reads of an unchanged set
     * must be byte-identical, or the console's table re-orders itself between polls and a contract
     * test comparing two response bodies is a coin flip.
     *
     * Returns DISABLED rows too. A disabled model is what an operator asking "why is this model
     * missing from the bot's dropdown" needs to see, and filtering it out here would make the
     * question unanswerable from the console while the row sat in the table.
     *
     * @return list<ProviderModelEntry>
     */
    public function forConnection(string $organizationId, string $connectionId): array;

    /**
     * Whether this organization's connection already carries a row for this model identifier.
     *
     * IT EXISTS SO THE DUPLICATE IS A 422 AND NOT A 500. The authority is the unique index
     * `provider_models_org_connection_model`; this is the good error message, and the service
     * catches SQLSTATE 23505 for the race it cannot win. It is a REPOSITORY method and not a
     * `unique:` validation rule for the reason DesignateEmbeddingConnectionRequest writes out at
     * length: `unique:` queries the table with NO organization predicate unless somebody remembers
     * to add one, which is the exact shape of Filament CVE-2026-48067.
     */
    public function modelExists(string $organizationId, string $connectionId, string $model): bool;

    /**
     * Store one catalog row, in one transaction, for ONE organization's connection.
     *
     * @param  Closure(ProviderModelEntry): void  $audit  invoked inside the transaction
     *
     * @throws \Illuminate\Database\QueryException SQLSTATE 23505 when the (organization,
     *                                             connection, model) triple already exists. The
     *                                             service maps it onto the same 422 the pre-flight
     *                                             check produces; the unique index is the
     *                                             authority and the check is only the good message
     */
    public function create(
        string $organizationId,
        string $connectionId,
        NewProviderModelEntry $input,
        Closure $audit,
    ): ProviderModelEntry;

    /**
     * Replace the row's mutable attributes under a row lock.
     *
     * THE MODEL IDENTIFIER IS NOT WRITABLE HERE BY CONSTRUCTION: `ProviderModelEdit` has no member
     * that could carry one, so this method cannot rename a row even if a caller wanted it to. See
     * that class for why a rename is a re-index rather than an edit.
     *
     * @param  Closure(ProviderModelEntry): void  $audit  invoked inside the transaction
     * @return ProviderModelEntry|null null when no such row exists under THIS organization's
     *                                 connection
     */
    public function update(
        string $organizationId,
        string $connectionId,
        string $modelId,
        ProviderModelEdit $edit,
        Closure $audit,
    ): ?ProviderModelEntry;

    /**
     * Hard-delete one catalog row — unless the organization's embedding designation names it.
     *
     * THE DESIGNATION CHECK IS INSIDE THE TRANSACTION, and that is the whole reason this returns an
     * enum rather than a bool. There is no foreign key from
     * `organizations (embedding_connection_id, embedding_model)` to
     * `provider_models (provider_connection_id, model)`, so unlike the connection delete NOTHING IN
     * THE DATABASE will refuse this. A controller pre-flight alone would lose to an administrator
     * designating the pair between the read and the DELETE, and the organization would be left
     * naming a row that does not exist. See App\Enums\ProviderModelDeletion.
     *
     * @param  Closure(ProviderModelEntry): void  $audit  invoked inside the transaction, BEFORE the
     *                                                    row is removed — after it there is nothing
     *                                                    left to describe
     */
    public function delete(
        string $organizationId,
        string $connectionId,
        string $modelId,
        Closure $audit,
    ): ProviderModelDeletion;
}

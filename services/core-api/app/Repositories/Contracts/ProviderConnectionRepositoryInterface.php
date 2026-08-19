<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProviderConnection;
use App\Services\Providers\NewProviderConnection;
use App\Services\Providers\ProviderConnectionEdit;
use Closure;

/**
 * ── EVERY METHOD TAKES `$organizationId` FIRST, AND POSITIONALLY ────────────────────────────────
 *
 * That is the MECHANISM of tenant isolation on this table; `#[ScopedBy(OrganizationScope::class)]`
 * on the model is the backstop. The two fail differently and both are needed: the global scope
 * reads the ambient TenantContext, so it fails in exactly the situation the explicit argument
 * exists for — a queue worker or an Octane request whose context is stale or empty
 * (laravel-control-plane, "Where the tenant scope is enforced"). A method here that derived the
 * organization from the record it was handed would have one layer, not two, and the layer it kept
 * would be the ambient one.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ─────────────────────────────
 *
 * All four provider-connection audit operations are `ON_FAILURE_ABORT`, which means a failed audit
 * write must roll the state change back. App\Services\Audit\AuditLogger deliberately opens no
 * transaction of its own — it cannot know what else belongs inside one — and
 * `Illuminate\Support\Facades\DB` is arch-pinned to App\Repositories\Eloquent, so this layer is
 * the only place the wrapping can happen. Making the closure a REQUIRED argument rather than an
 * optional one is what stops a future caller writing the state change with no row: there is no
 * overload that omits it, and `null` is not an accepted value.
 *
 * The closure is invoked INSIDE the transaction, after the write so the row's identifiers are
 * settled, and before the commit so an ON_FAILURE_ABORT rethrow takes the change with it.
 */
interface ProviderConnectionRepositoryInterface
{
    /**
     * Store one connection and its model rows, in one transaction, for ONE organization.
     *
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     * @param  Closure(ProviderConnection): void  $audit  invoked inside the transaction
     */
    public function create(
        string $organizationId,
        NewProviderConnection $input,
        array $sealed,
        Closure $audit,
    ): ProviderConnection;

    /**
     * Every connection belonging to ONE organization, oldest first.
     *
     * ORDERED DETERMINISTICALLY AND NEVER BY THE PLANNER'S WHIM: two reads of an unchanged set
     * must be byte-identical, or the console's list re-orders itself between polls and a contract
     * test comparing two response bodies is a coin flip.
     *
     * Returns every status. A revoked or invalid connection is exactly what an operator asking
     * "why can I not ingest" needs to see, and filtering it out here would make the question
     * unanswerable from the console while the row sat in the table.
     *
     * @return list<ProviderConnection>
     */
    public function forOrg(string $organizationId): array;

    /**
     * Whether ONE connection id belongs to ONE organization.
     *
     * ── IT EXISTS SO A BOT NAMING A FOREIGN CONNECTION IS A 422 AND NOT A 500 ─────────────────
     *
     * `bots_connection_same_org` is a composite foreign key against
     * `provider_connections (organization_id, id)`, so the database already refuses the row — a bot
     * cannot name another tenant's credential, which is the guarantee that matters and it is not
     * being duplicated here. What it refuses it refuses as SQLSTATE 23503, rendered by the error
     * envelope as a 500: a bug report about the server for what is plainly a bad request, on a
     * field the form has an input for.
     *
     * A REPOSITORY METHOD AND NOT AN `exists:` VALIDATION RULE, for the reason
     * `DesignateEmbeddingConnectionRequest` writes out at length: `exists:` queries the table with
     * NO organization predicate unless somebody remembers to add one, which is the exact shape of
     * Filament CVE-2026-48067. It would also turn the endpoint into an existence oracle over the
     * whole platform — "this id exists but you may not use it" and "this id does not exist" would
     * be two different validation outcomes. Scoped here, a foreign id and an unknown id are
     * indistinguishable, which is the same property the 404-at-binding-time rule gives the routes.
     */
    public function existsForOrg(string $organizationId, string $connectionId): bool;

    /**
     * Apply a label and/or status edit under a row lock.
     *
     * NO CREDENTIAL PATH EXISTS HERE BY CONSTRUCTION: `ProviderConnectionEdit` has no member that
     * could carry one, so this method cannot write ciphertext even if a caller wanted it to.
     *
     * @param  Closure(ProviderConnection): void  $audit  invoked inside the transaction
     * @return ProviderConnection|null null when no such row exists in THIS organization
     */
    public function update(
        string $organizationId,
        string $connectionId,
        ProviderConnectionEdit $edit,
        Closure $audit,
    ): ?ProviderConnection;

    /**
     * Hard-delete one connection and the `provider_models` rows under it, in one transaction.
     *
     * CHILDREN FIRST, BECAUSE THE FOREIGN KEY IS `ON DELETE RESTRICT` AND NOT `CASCADE`. That
     * choice is the create migration's, and it is right: a cascade would let a mis-scoped delete
     * quietly take a catalog of models with it. Deleting them explicitly, inside the same
     * transaction and under the same organization predicate, is the version an operator can
     * reason about.
     *
     * @param  Closure(ProviderConnection): void  $audit  invoked inside the transaction, BEFORE
     *                                                    the row is removed — after it, there is
     *                                                    nothing left to describe
     * @return bool false when no such row exists in THIS organization
     *
     * @throws \Illuminate\Database\QueryException SQLSTATE 23503 when the row is still referenced
     *                                             — in practice `organizations.embedding_connection_id`,
     *                                             whose composite FK is also ON DELETE RESTRICT. The
     *                                             service maps that one constraint onto a 409 and
     *                                             rethrows anything else.
     */
    public function delete(
        string $organizationId,
        string $connectionId,
        Closure $audit,
    ): bool;

    /**
     * Replace the sealed secret, bump `credential_version`, and return the connection to `active`.
     *
     * `key_version` comes from `$sealed` and is the KEK's version, NOT a rotation counter — see
     * the migration that adds `credential_version` for why the two cannot be the same column.
     *
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     * @param  Closure(ProviderConnection): void  $audit  invoked inside the transaction
     * @return ProviderConnection|null null when no such row exists in THIS organization
     */
    public function rotateCredential(
        string $organizationId,
        string $connectionId,
        array $sealed,
        Closure $audit,
    ): ?ProviderConnection;
}

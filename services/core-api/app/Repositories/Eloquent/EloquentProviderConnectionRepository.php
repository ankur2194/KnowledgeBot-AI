<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\ProviderConnectionStatus;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Providers\NewProviderConnection;
use App\Services\Providers\NewProviderModel;
use App\Services\Providers\ProviderConnectionEdit;
use Closure;
use Illuminate\Support\Facades\DB;

final class EloquentProviderConnectionRepository implements ProviderConnectionRepositoryInterface
{
    /**
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     * @param  Closure(ProviderConnection): void  $audit
     */
    public function create(
        string $organizationId,
        NewProviderConnection $input,
        array $sealed,
        Closure $audit,
    ): ProviderConnection {
        return DB::transaction(function () use ($organizationId, $input, $sealed, $audit): ProviderConnection {
            $connection = new ProviderConnection;

            // The ownership column is assigned here, from the authenticated context passed in as
            // an argument — never from the DTO, which does not carry one, and never from request
            // input. It is not in $fillable, so this is the only place it can be set.
            $connection->organization_id = $organizationId;
            $connection->provider = $input->provider;
            $connection->label = $input->label;
            $connection->status = ProviderConnectionStatus::Active;

            // The ciphertext columns are not fillable either. They are `bytea`, so the driver
            // sends them as binary; nothing here ever holds the plaintext.
            $connection->setAttribute('credential_ciphertext', $sealed['credential_ciphertext']);
            $connection->setAttribute('data_key_ciphertext', $sealed['data_key_ciphertext']);
            $connection->setAttribute('key_version', $sealed['key_version']);
            $connection->setAttribute('last_four', $sealed['last_four']);

            $connection->save();

            foreach ($input->models as $model) {
                $this->attachModel($organizationId, $connection, $model);
            }

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT
            // so an ON_FAILURE_ABORT audit failure rethrows and takes the connection AND its model
            // rows with it. `provider.connection.created` is ABORT, so a credential that was
            // stored without a record of who stored it is not a state this table can reach.
            $audit($connection);

            return $connection;
        });
    }

    /**
     * @return list<ProviderConnection>
     */
    public function forOrg(string $organizationId): array
    {
        // The explicit predicate is the mechanism; #[ScopedBy(OrganizationScope::class)] on the
        // model adds the same term from the ambient TenantContext and is the backstop. Both are
        // present on purpose — the backstop is what catches a query somebody forgot to route
        // through here, and the explicit argument is what still works when the context is stale.
        //
        // ORDER: `created_at` then `id`. A ULID is already time-ordered so `id` alone would do,
        // but stating both means the order survives a future switch to UUIDv7 (which is also
        // time-ordered but not lexicographically comparable to a ULID) without silently
        // re-sorting every client's list.
        /** @var list<ProviderConnection> $rows */
        $rows = ProviderConnection::query()
            ->where('organization_id', '=', $organizationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * @param  Closure(ProviderConnection): void  $audit
     */
    public function update(
        string $organizationId,
        string $connectionId,
        ProviderConnectionEdit $edit,
        Closure $audit,
    ): ?ProviderConnection {
        return DB::transaction(function () use ($organizationId, $connectionId, $edit, $audit): ?ProviderConnection {
            $connection = $this->lock($organizationId, $connectionId);

            if ($connection === null) {
                // The route binding already 404'd a foreign id long before this line; reaching
                // here means the row was deleted between the binding and this transaction. Null
                // rather than an exception, so the caller renders the same 404 the binding would
                // have rather than a 500 describing a race.
                return null;
            }

            if ($edit->label !== null) {
                $connection->label = $edit->label;
            }

            if ($edit->status !== null) {
                $connection->status = $edit->status;
            }

            $connection->save();

            $audit($connection);

            return $connection;
        });
    }

    /**
     * @param  Closure(ProviderConnection): void  $audit
     */
    public function delete(
        string $organizationId,
        string $connectionId,
        Closure $audit,
    ): bool {
        return DB::transaction(function () use ($organizationId, $connectionId, $audit): bool {
            $connection = $this->lock($organizationId, $connectionId);

            if ($connection === null) {
                return false;
            }

            // BEFORE the delete, because after it there is nothing left to describe: this is a
            // hard delete and the audit row is the only surviving record of the connection. It is
            // also inside the transaction, so an ON_FAILURE_ABORT write failure leaves the
            // connection intact rather than deleting it untraceably.
            $audit($connection);

            // CHILDREN FIRST. `provider_models` has a composite FK to
            // (organization_id, provider_connection_id) that is ON DELETE RESTRICT, so the parent
            // delete below would fail with 23503 while any model row survives. Both predicates
            // are present for the same reason the FK is composite: a delete keyed only on
            // `provider_connection_id` would be correct solely because of a constraint written in
            // another file.
            ProviderModelEntry::query()
                ->where('organization_id', '=', $organizationId)
                ->where('provider_connection_id', '=', $connectionId)
                ->delete();

            // The remaining ON DELETE RESTRICT reference is
            // `organizations.embedding_connection_id`. It is checked in the controller as a 409
            // with an actionable sentence; if a designation lands between that check and this
            // statement, PostgreSQL raises 23503 and the service maps it onto the SAME 409. The
            // constraint is the authority, the check is the good error message.
            $connection->delete();

            return true;
        });
    }

    /**
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     * @param  Closure(ProviderConnection): void  $audit
     */
    public function rotateCredential(
        string $organizationId,
        string $connectionId,
        array $sealed,
        Closure $audit,
    ): ?ProviderConnection {
        return DB::transaction(function () use ($organizationId, $connectionId, $sealed, $audit): ?ProviderConnection {
            $connection = $this->lock($organizationId, $connectionId);

            if ($connection === null) {
                return null;
            }

            $connection->setAttribute('credential_ciphertext', $sealed['credential_ciphertext']);
            $connection->setAttribute('data_key_ciphertext', $sealed['data_key_ciphertext']);
            // FROM THE VAULT, NOT INCREMENTED. `key_version` is the KEY-ENCRYPTING KEY's version,
            // so it moves when the platform rotates the KEK and stands still when a tenant
            // replaces a provider key. Incrementing it here would eventually make this row's data
            // key unwrappable — see the migration that adds `credential_version`.
            $connection->setAttribute('key_version', $sealed['key_version']);
            $connection->setAttribute('last_four', $sealed['last_four']);

            // Computed from the LOCKED row, so two concurrent rotations serialise rather than both
            // reading the same value and writing the same next one. The CHECK on the column keeps
            // it >= 1 whatever a future caller does.
            $connection->setAttribute('credential_version', $connection->credential_version + 1);

            // A rotation is the operator asserting the credential is good again. `invalid` was set
            // by a failed connection check against the OLD key and says nothing about the new one;
            // `revoked` is the state a rotation is most often the undo of. Leaving either in place
            // would store a working key that every capability query still skips.
            $connection->status = ProviderConnectionStatus::Active;

            $connection->save();

            $audit($connection);

            return $connection;
        });
    }

    /**
     * One connection of ONE organization, locked FOR UPDATE.
     *
     * The lock is what makes "read the row, decide, write it" a decision rather than a guess: two
     * rotations, or a rotation racing a revoke, serialise here instead of interleaving. The
     * organization predicate is on the SELECT rather than only on the model's global scope,
     * because the lock has to be taken on a row this organization actually owns — a lock acquired
     * under a stale ambient context would be a lock on somebody else's row.
     */
    private function lock(string $organizationId, string $connectionId): ?ProviderConnection
    {
        return ProviderConnection::query()
            ->where('organization_id', '=', $organizationId)
            ->whereKey($connectionId)
            ->lockForUpdate()
            ->first();
    }

    private function attachModel(
        string $organizationId,
        ProviderConnection $connection,
        NewProviderModel $model,
    ): void {
        $row = new ProviderModelEntry;

        // Denormalized deliberately, and it is what the composite foreign key
        // (organization_id, provider_connection_id) checks against. Copied from the argument
        // rather than from $connection->organization_id so the two cannot silently agree with each
        // other while both disagreeing with the caller.
        $row->organization_id = $organizationId;
        $row->provider_connection_id = $connection->id;
        $row->model = $model->model;
        $row->display_name = $model->displayName;
        $row->capability_flags = ['supported' => $model->supported];
        $row->context_window = $model->contextWindow;
        $row->max_output_tokens = $model->maxOutputTokens;
        $row->enabled = true;

        $row->save();
    }
}

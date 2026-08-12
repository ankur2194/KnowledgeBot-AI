<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\ProviderConnectionStatus;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Providers\NewProviderConnection;
use App\Services\Providers\NewProviderModel;
use Illuminate\Support\Facades\DB;

final class EloquentProviderConnectionRepository implements ProviderConnectionRepositoryInterface
{
    /**
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     */
    public function create(
        string $organizationId,
        NewProviderConnection $input,
        array $sealed,
    ): ProviderConnection {
        return DB::transaction(function () use ($organizationId, $input, $sealed): ProviderConnection {
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

            return $connection;
        });
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

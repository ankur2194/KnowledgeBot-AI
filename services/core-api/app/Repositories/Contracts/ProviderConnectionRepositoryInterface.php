<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProviderConnection;
use App\Services\Providers\NewProviderConnection;

interface ProviderConnectionRepositoryInterface
{
    /**
     * Store one connection and its model rows, in one transaction, for ONE organization.
     *
     * @param  array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}  $sealed
     */
    public function create(
        string $organizationId,
        NewProviderConnection $input,
        array $sealed,
    ): ProviderConnection;
}

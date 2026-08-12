<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One stored provider credential, envelope-encrypted.
 *
 * `credential_ciphertext` and `data_key_ciphertext` are `bytea`, NEVER `text`, and neither is
 * indexed. Both rules are from kb-security-baseline §18.2 and postgresql-patterns, and both have a
 * silent failure behind them: ciphertext in a `text` column goes through client-encoding
 * conversion, so a `pg_dump`/restore across a different `client_encoding` mangles bytes that were
 * never valid UTF-8 to begin with; and a unique or b-tree index over ciphertext is an equality
 * oracle over secrets.
 *
 * `key_version` beside the wrapped key is what makes a KEK rotation a DATA change — re-wrap every
 * DEK — instead of a schema migration.
 *
 * THE UNIQUE INDEX ON (organization_id, id) IS NOT REDUNDANT WITH THE PRIMARY KEY. `id` alone is
 * already unique, so as a uniqueness constraint this index says nothing. It exists to be the
 * TARGET of composite foreign keys: PostgreSQL requires a referenced column list to be backed by a
 * unique constraint, and two later tables — `provider_models` and the embedding designation on
 * `organizations` — point at it so that "this row's connection belongs to this row's organization"
 * is enforced by the database rather than by a service that can be bypassed. It is the same guard
 * kb-tenancy-isolation NN2 mandates for `bot_source_assignments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $providers = $this->quotedList(Provider::values());
        $statuses = $this->quotedList(ProviderConnectionStatus::values());

        $this->run(<<<SQL
            CREATE TABLE provider_connections (
                id                     char(26) COLLATE "C" PRIMARY KEY,
                organization_id        char(26) COLLATE "C" NOT NULL
                                       REFERENCES organizations (id) ON DELETE RESTRICT,
                provider               text NOT NULL,
                label                  text NOT NULL,
                credential_ciphertext  bytea NOT NULL,
                data_key_ciphertext    bytea NOT NULL,
                key_version            integer NOT NULL,
                last_four              char(4) NOT NULL,
                status                 text NOT NULL DEFAULT 'active',
                last_tested_at         timestamptz,
                last_test_status       text,
                created_at             timestamptz NOT NULL DEFAULT now(),
                updated_at             timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT provider_connections_provider_check CHECK (provider IN ({$providers})),
                CONSTRAINT provider_connections_status_check   CHECK (status   IN ({$statuses}))
            )
        SQL);

        $this->run(
            'CREATE UNIQUE INDEX provider_connections_org_scoped_key '
            .'ON provider_connections (organization_id, id)',
        );

        // Tenant-leading, always. Never (status, organization_id): a status-leading index makes
        // the tenant predicate a filter over every organization's rows in that state.
        $this->run(
            'CREATE INDEX provider_connections_org_status '
            .'ON provider_connections (organization_id, status)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS provider_connections');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root.
 *
 * Written as SQL rather than through the Schema builder because three of the choices below have no
 * Blueprint expression and every one of them is load-bearing (postgresql-patterns):
 *
 *   char(26) COLLATE "C"   the ULID the wire already carries, compared with memcmp and pinned to
 *                          byte order forever. Without COLLATE "C" the index sorts through
 *                          ICU/glibc, so a base-image bump can leave a b-tree whose order no
 *                          longer matches its collation and lookups miss rows that are present.
 *   text + CHECK           not a PG enum: `ALTER TYPE ... ADD VALUE` cannot be rolled back, so a
 *                          new status would be an irreversible migration.
 *   timestamptz            never `timestamp`; a naive column silently means "whatever the session
 *                          TimeZone was".
 *
 * No `organization_id` column, obviously, and therefore no OrganizationScope on the model: this IS
 * the organization. Its authorization comes from the membership row, re-read per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statuses = $this->quotedList(OrganizationStatus::values());

        $this->run(<<<SQL
            CREATE TABLE organizations (
                id          char(26) COLLATE "C" PRIMARY KEY,
                name        text NOT NULL,
                slug        text NOT NULL,
                status      text NOT NULL DEFAULT 'active',
                settings    jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT organizations_status_check CHECK (status IN ({$statuses}))
            )
        SQL);

        // GLOBALLY unique, not per-anything — an organization slug has no parent to be unique
        // within. Stated here because every Valkey key derived from a user-chosen string is
        // prefixed with org_id precisely because most such strings are NOT globally unique
        // (WSO2 CVE-2025-13475), and a reader needs to know which case this column is.
        $this->run('CREATE UNIQUE INDEX organizations_slug_unique ON organizations (slug)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS organizations');
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
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

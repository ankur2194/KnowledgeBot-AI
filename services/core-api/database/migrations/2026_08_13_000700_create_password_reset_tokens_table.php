<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The framework's password-reset token store, in the framework's exact shape.
 *
 * `Illuminate\Auth\Passwords\DatabaseTokenRepository` reads and writes only three columns — `email`,
 * `token`, `created_at` — and `config/auth.php` already names this table. This migration adds
 * nothing to that shape and takes nothing away, for a reason that has no external symptom:
 *
 *   `email` IS THE PRIMARY KEY, AND THAT IS THE CORRECTNESS PROPERTY, NOT A CONVENTION.
 *   create() is deleteExisting()-then-insert(). If a ULID `id` were added and `email` lost its
 *   uniqueness, that pair stops being atomic against itself: two concurrent forgot-password
 *   requests each delete, each insert, and two rows survive. exists() then reads whichever row
 *   first() happens to return, so ONE OF THE TWO EMAILED LINKS SILENTLY NEVER WORKS. Nothing logs,
 *   nothing 500s, and the only observable is a user saying "the link didn't work".
 *
 * `token` is `text` and holds a BCRYPT HASH of the token (DatabaseTokenRepository::getPayload()),
 * not the token and not raw bytes — so `bytea` would be wrong here, unlike every other digest
 * column in this schema.
 *
 * `COLLATE "C"` makes the primary-key equality a `memcmp` and pins the ordering to bytes forever
 * (postgresql-patterns). It is safe ONLY because every write and every read of this column is
 * lowercase-normalised in the FormRequest, which is also what makes it agree with
 * `users_email_unique`, an index on `lower(email)`.
 *
 * NO `organization_id`, AND NO FK CHAIN TO ONE. A password reset is a fact about a USER, and a user
 * is not owned by an organization (see the `users` migration). When postgresql-patterns' "every new
 * table reaches an organization" CI check is finally written, this table and
 * `email_verification_tokens` are its two deliberate exceptions and need an annotated
 * `// tenancy-exempt:` entry rather than a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                email       text COLLATE "C" PRIMARY KEY,
                token       text NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // deleteExpired() is `WHERE created_at < ?`, a range predicate the primary key cannot
        // serve. Without this index the prune command sequential-scans, which is harmless today and
        // is exactly the kind of thing nobody revisits once the table is large.
        $this->run('CREATE INDEX password_reset_tokens_created_at ON password_reset_tokens (created_at)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS password_reset_tokens');
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

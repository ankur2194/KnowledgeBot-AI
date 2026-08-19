<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A monotonic generation counter for the credential stored on a provider connection.
 *
 * ── WHY THIS IS NOT `key_version` ───────────────────────────────────────────────────────────────
 *
 * The brief for the rotation endpoint said "bump `key_version`". That column cannot carry this
 * meaning, and the disagreement is reported rather than silently resolved.
 *
 * `key_version` is the KEY-ENCRYPTING KEY's version. kb-security-baseline §18.2: *"Storing
 * `key_version` alongside the wrapped key makes KEK rotation a data change (rewrap the DEKs)
 * instead of a schema migration."* The create migration for this table says the same thing, and
 * App\Support\Crypto\CredentialVault::seal() writes it from `config('kb.kek_version')` and from
 * nowhere else. `CredentialVault::open()` ignores it today only because exactly one KEK is
 * configured; the moment a second one exists, `open()` must select the KEK BY THIS NUMBER. A
 * credential rotation that incremented it would therefore write a version naming a KEK that never
 * wrapped the row, and the failure would appear at the next KEK rotation as ciphertext that cannot
 * be unwrapped — for every credential rotated since. That is a latent data-loss bug, not a
 * stylistic objection, so `key_version` keeps its meaning and the counter gets a column of its own.
 *
 * ── WHAT IT IS FOR ─────────────────────────────────────────────────────────────────────────────
 *
 * "Which generation of this tenant's provider key was live on that date." It starts at 1 for every
 * existing and every new row, and PUT …/credential increments it inside the same transaction that
 * replaces the ciphertext. It is written into the `provider.connection.credential_rotated` audit
 * row beside `key_version`, which is the only place it is read; it is deliberately NOT rendered by
 * ProviderConnectionResource, because a client has no decision to make with it and the published
 * component is closed.
 *
 * ── LOCK SAFETY ────────────────────────────────────────────────────────────────────────────────
 *
 * `ADD COLUMN ... integer NOT NULL DEFAULT 1` is the fast path: the default is a NON-VOLATILE
 * literal, so the value goes into `pg_attribute.attmissingval` and no existing row is touched
 * (postgresql-patterns, the lock table). The ACCESS EXCLUSIVE lock is momentary — but momentary
 * only if it is granted immediately, which is what `lock_timeout` plus `retry()` is for: a blocked
 * DDL queues readers behind itself, so failing fast ten times is a non-event and waiting once is
 * an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run("SET lock_timeout = '3s'");

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_connections
                ADD COLUMN credential_version integer NOT NULL DEFAULT 1
        SQL), 2000);

        // A counter that can go backwards is not a counter. The CHECK is cheap and it is what
        // stops a future UPDATE that computes the next value from a stale read writing a 0.
        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_connections
                ADD CONSTRAINT provider_connections_credential_version_positive
                CHECK (credential_version >= 1)
        SQL), 2000);
    }

    public function down(): void
    {
        $this->run(
            'ALTER TABLE provider_connections '
            .'DROP CONSTRAINT IF EXISTS provider_connections_credential_version_positive',
        );
        $this->run('ALTER TABLE provider_connections DROP COLUMN IF EXISTS credential_version');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

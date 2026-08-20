<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE UNIQUE INDEX `provider_models (organization_id, id)`, WHICH SAYS NOTHING AND ENABLES
 * EVERYTHING.
 *
 * `id` is already the primary key, so as a uniqueness CONSTRAINT this index is vacuous: it admits
 * exactly the set of tables the primary key already admits. It exists to be the TARGET of a
 * composite foreign key. PostgreSQL requires a referenced column list to be backed by a unique
 * constraint, so without this index the statement
 *
 *     FOREIGN KEY (organization_id, provider_model_id)
 *         REFERENCES provider_models (organization_id, id)
 *
 * fails outright with 42830 — "there is no unique constraint matching given keys". `bots` carries
 * exactly that foreign key, and `bot_fallback_models` carries it a second time, so the index has to
 * exist before either table is created.
 *
 * IT IS THE SAME INDEX `provider_connections_org_scoped_key` ALREADY IS, for the same reason, and
 * 2026_08_07_000300 writes that reasoning out at length: "this row's connection belongs to this
 * row's organization" is enforced by the database rather than by a service that can be bypassed.
 * `provider_connections` got its copy in its own CREATE TABLE because the tables that point at it
 * were already planned. `provider_models` did not, because at the time nothing referenced a model
 * row at all — the catalogue was a leaf. A bot naming a model is what makes it an interior node,
 * and this is the missing half of that promotion.
 *
 * WHY THIS IS ITS OWN MIGRATION AND NOT A LINE IN `create_bots_table`. It is an ALTER on a
 * populated table and therefore has a lock story; the bots migration is a CREATE on a table nobody
 * is reading and has none. Folding the two together would hide a `lock_timeout` inside a file whose
 * every other statement is lock-free, which is exactly where the next reader would stop looking for
 * one.
 *
 * `CREATE INDEX` takes a `SHARE` lock: readers are unaffected, WRITES to `provider_models` block
 * for the length of the build (postgresql-patterns, "Migrations that lock"). That is acceptable
 * here and would not be on `chunks`: this table holds a handful of rows per connection, so the
 * build is milliseconds. `CONCURRENTLY` is deliberately NOT used — it cannot run inside a
 * transaction, and every migration in this directory runs inside one, so adopting it would mean
 * `public $withinTransaction = false` and a partially-applied migration on failure. Trading a
 * millisecond of write blocking for that is a bad trade.
 *
 * The `retry()` around it buys less than it looks, for the reason 2026_08_19_001200 records: the
 * whole file is one transaction, so the second attempt after a 55P03 fails with 25P02 instead of
 * re-acquiring the lock. The `lock_timeout` half is the part doing real work — it turns an
 * indefinite queue behind one long reader into a fast, loud failure. The shape is copied from the
 * three ALTER migrations beside it deliberately rather than diverged from.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A bounded wait, not a queue: a blocked DDL blocks readers it would never have blocked,
        // because PostgreSQL's lock queue is ordered (postgresql-patterns, Gotchas).
        $this->run("SET lock_timeout = '3s'");

        retry(10, fn () => $this->run(
            'CREATE UNIQUE INDEX provider_models_org_scoped_key '
            .'ON provider_models (organization_id, id)',
        ), 2000);
    }

    public function down(): void
    {
        $this->run('DROP INDEX IF EXISTS provider_models_org_scoped_key');
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE TWO COLUMNS THE INGESTION CALLBACK GUARD NEEDS, AND HAS NOWHERE ELSE TO LIVE.
 *
 * ═══ WHY THIS MIGRATION EXISTS AT ALL ═════════════════════════════════════════════════════════
 *
 * `kb-internal-api-contracts` and `laravel-control-plane` both state the same rule in the same
 * words: every async callback carries `(job_id, sequence, stage, status)` and Laravel applies it
 * under `WHERE sequence > progress_sequence`, because a Celery retry re-emitting stage 6 after
 * stage 9 has landed will otherwise flip a `ready` source back to `processing` and take an
 * already-published version out of retrieval.
 *
 * Nothing in the Phase C1 schema can hold either half of that guard. `source_versions` carries
 * `delivery_count`, which is a DIFFERENT counter — the durable redelivery bound the data plane
 * reads to decide when to give up — and a version row does not exist for the FIRST callback of a
 * run, which is the one that resolves identity. `valkey-keyspaces` closes the other escape:
 * "deliberately not in Valkey: job status and delivery counters". So the guard is a PostgreSQL
 * column or it is not a guard.
 *
 * ═══ WHY ON `source_items` AND NOT ON `source_versions` ═══════════════════════════════════════
 *
 * A RUN IS SCOPED TO AN ITEM, not to a version. `app/ingestion/tasks.py` takes the Valkey lock on
 * `source_item_id` "for the whole run", and the run's first act is to resolve the ingest key —
 * which is what DECIDES which version row the run belongs to, and may resolve to an existing one.
 * A sequence counter on `source_versions` therefore could not guard the frame that creates the
 * version, which is precisely the frame a redelivery is most likely to duplicate.
 *
 * Every callback frame names an item; only the later ones name a version. The item is the identity
 * the guard can be stated against uniformly.
 *
 * ═══ `current_job_id` IS THE OTHER HALF, AND IT IS NOT DECORATION ═════════════════════════════
 *
 * `progress_sequence` alone orders the frames of ONE run. It says nothing about frames from a
 * SUPERSEDED run — a reprocess dispatched while an earlier run is still in flight produces two
 * live jobs whose sequences both start at 1, and the older one's stage-3 frame would then rewind
 * the newer one's stage-7. Laravel mints the job id at dispatch and stores it here; a callback
 * naming any other job is ignored outright rather than compared. That is why the sequence is reset
 * to 0 by the same statement that claims the column, and why the two are written together.
 *
 * ═══ LOCK SAFETY ═════════════════════════════════════════════════════════════════════════════
 *
 * `ADD COLUMN` with a NON-VOLATILE default has not rewritten the table since PostgreSQL 11 — the
 * default is stored in `pg_attribute` and materialized on read — so this is a catalog update under
 * a brief ACCESS EXCLUSIVE lock rather than a full rewrite of every page (postgresql-patterns).
 * `now()` would be volatile and would rewrite; `0` is not. The CHECK is added in the same statement
 * as the column, so it is validated against a table that provably holds only the default.
 *
 * NO QUESTION MARK APPEARS IN ANY SQL STRING OR SQL COMMENT IN THIS FILE. PDO rewrites a bare
 * question mark into a positional placeholder while scanning the statement and does not reliably
 * skip comments while doing so.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            ALTER TABLE source_items
                ADD COLUMN current_job_id char(26) COLLATE "C",
                ADD COLUMN progress_sequence integer NOT NULL DEFAULT 0,
                ADD CONSTRAINT source_items_progress_sequence_non_negative
                    CHECK (progress_sequence >= 0)
        SQL);
    }

    public function down(): void
    {
        // The constraint goes with the column it constrains; naming it anyway so a partially
        // applied `up()` — the column added, the constraint not — still reverses cleanly.
        $this->run(
            'ALTER TABLE source_items '
            .'DROP CONSTRAINT IF EXISTS source_items_progress_sequence_non_negative, '
            .'DROP COLUMN IF EXISTS progress_sequence, '
            .'DROP COLUMN IF EXISTS current_job_id',
        );
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

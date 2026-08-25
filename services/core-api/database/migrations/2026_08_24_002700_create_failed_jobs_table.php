<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE TABLE `config/queue.php` HAS BEEN PROMISING SINCE THE SKELETON. Finding R8.
 *
 * ═══ HOW THE ABSENCE WAS FOUND, BECAUSE IT IS THE REASON THIS FILE IS DOCUMENTED AT ALL ══════
 *
 * `'failed' => ['driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'), 'table' => 'failed_jobs']`
 * named a table no migration created. Nothing caught it, and nothing could have: the recorder runs
 * only when a job has exhausted its attempts, which no test does and no happy path reaches. It
 * surfaced on 2026-08-24, on the first real upload, as
 *
 *     SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "failed_jobs" does not exist
 *
 * THE SECOND CONSEQUENCE IS THE EXPENSIVE ONE. The recorder's own exception is what reaches the
 * log, *in place of* the exception that actually killed the job — so an operator reads a missing
 * table where the stack trace should be, and the useful one is sealed inside the payload of an
 * INSERT that failed. Finding R6 took materially longer to diagnose for exactly this reason.
 *
 * This is finding **F7's shape a second time** (`sanctum:prune-expired`, scheduled against a table
 * 0 migrations create), which is what makes the pattern worth naming rather than just fixing: *a
 * framework-default table name in config, with no migration behind it, fails only on the path
 * nobody exercises.* The two resolved in opposite directions and both are correct — F7 is a
 * PERMANENT omission under decision D11 (no token is ever minted, so the pruner is a no-op with a
 * cron slot; `routes/console.php` states it), and this one is a table the system genuinely needs.
 * Read them together before adding a third.
 *
 * ═══ WHY THE MIGRATION AND NOT `QUEUE_FAILED_DRIVER=null` ════════════════════════════════════
 *
 * `null` is a real option and was rejected on evidence in this tree, not on preference. Two
 * things already depend on the rows existing:
 *
 *   1. `routes/console.php` carries `queue:prune-failed --hours=336` as a PENDING entry whose
 *      comment is explicit — "uncomment once the table exists" — and puts the table inside the
 *      retention policy. A retention policy over a driver that discards is not a policy.
 *   2. Ingestion's whole design assumes a permanent failure is recoverable BY AN OPERATOR.
 *      `SubmitIngestionJob` is the seam between an accepted upload and the data plane; when it
 *      exhausts its attempts the source is a row whose bytes are on disk and whose work never
 *      started. `queue:retry` is the recovery, and it retries out of this table. With the `null`
 *      driver the only recovery is re-uploading the file, which mints a new source id and a new
 *      object — the § R5/S3 orphan shape, produced by the recovery procedure itself.
 *
 * ═══ THIS TABLE HOLDS TENANT DATA, WHICH IS THE ONE THING ABOUT IT THAT IS NOT ROUTINE ═══════
 *
 * `payload` is the serialized job, and `exception` is a full trace with arguments. Every
 * tenant-bearing job in this service implements `ShouldBeEncrypted`, so the payload arrives here
 * already ciphertext — but the trace does not, and neither does `queue`. Three consequences that
 * are enforced elsewhere and are recorded here because this is the table they are about:
 *
 *   * It is inside the retention policy (336 hours), swept by `queue:prune-failed`, which this
 *     migration unblocks and which is scheduled in the same change.
 *   * It has NO `organization_id` and deliberately no model. Adding an Eloquent model would put
 *     it in reach of `OrganizationScope`, and a global scope over a table the framework writes
 *     with a raw query builder is a scope that silently does not apply — worse than none.
 *     Operators read it through `queue:failed`, never through the tenant surface.
 *   * Nothing in `app/Http` may select from it. There is no admin endpoint for failed jobs, and
 *     the first one would have to answer the redaction question this docblock is deferring.
 *
 * ═══ COLUMN CHOICES THAT DIVERGE FROM `php artisan queue:failed-table` ═══════════════════════
 *
 * `uuid` is `char(36) COLLATE "C"` and NOT PostgreSQL's native `uuid` type, which is the choice a
 * reviewer will want to argue with. `DatabaseUuidFailedJobProvider::forget()` and `queue:retry`
 * both compare the column to an operator-supplied string. Against a native `uuid` column,
 * `WHERE uuid = 'typo'` is SQLSTATE 22P02 — a 500 from a maintenance command instead of "no such
 * failed job". The framework treats the value as a string throughout; the column matches it.
 *
 * `payload` and `exception` are `text` rather than a bounded type on purpose: a truncated trace is
 * worse than a large row, and this table is pruned rather than grown.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE failed_jobs (
                id           bigserial PRIMARY KEY,

                -- The job's own uuid, minted at dispatch and carried inside `payload`. It is the
                -- handle `queue:retry` and `queue:forget` take, which is why the `database-uuids`
                -- driver exists at all: the bigserial id is not stable across a restore.
                uuid         char(36) COLLATE "C" NOT NULL,

                connection   text NOT NULL,
                queue        text NOT NULL,

                -- Ciphertext for every job that implements ShouldBeEncrypted. Not assumed to be:
                -- a job added without the interface writes plaintext here and nothing objects,
                -- which is the review stop this comment exists to be.
                payload      text NOT NULL,
                exception    text NOT NULL,

                -- THE RETENTION CLOCK. `queue:prune-failed --hours=336` deletes on this column.
                failed_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // The framework's own lookup, and the reason a duplicate cannot be recorded twice: the
        // recorder is itself retried by the queue worker's shutdown path.
        $this->run('CREATE UNIQUE INDEX failed_jobs_uuid ON failed_jobs (uuid)');

        // `queue:prune-failed` deletes `WHERE failed_at <= ?`. Without this it is a sequential
        // scan over the one table whose size is unbounded between sweeps.
        $this->run('CREATE INDEX failed_jobs_failed_at ON failed_jobs (failed_at)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS failed_jobs');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

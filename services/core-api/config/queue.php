<?php

declare(strict_types=1);

/*
 * THE ONE ARITHMETIC (laravel-queues-valkey):
 *
 *     --timeout  <  retry_after  <  lock TTL          and     stop_grace_period > --timeout
 *
 * Every arrow that points the wrong way produces a SILENT duplicate or a permanent wedge, never an
 * error. `retry_after` is set on the CONNECTION, not the queue, and is stamped onto the reservation
 * at pop time — which is why two budgets need two connections rather than two queues.
 *
 * UNDER HORIZON THERE IS NO `queue:work` PROCESS TO PASS `--timeout` TO. The supervisor owns it, so
 * the left-hand side of the inequality lives in config/horizon.php:
 *
 *     valkey       supervisor timeout  120 s  <  retry_after  180 s  <  expireAfter/UniqueFor ≥ 240 s
 *     valkey-long  supervisor timeout 1500 s  <  retry_after 1800 s  <  expireAfter/UniqueFor ≥ 1900 s
 *
 * The 60 s and 300 s margins cover SIGKILL settle and clock skew. Laravel's stub retry_after is 90 s
 * against a 60 s default timeout — a 30 s margin that any job doing two HTTP round trips will eat,
 * after which the reservation expires mid-run and a second worker processes the same job.
 */

return [

    'default' => env('QUEUE_CONNECTION', 'valkey'),

    // Exactly two connections. Not three, and no `sync` or `database` fallback: a connection that
    // exists is a connection something will silently be dispatched onto.
    'connections' => [

        // Control-plane dispatch work: `ai-dispatch` (submit ingestion / crawl / deletion /
        // evaluation to FastAPI) and `notify`. Longest job is one signed round trip plus a
        // background_jobs write.
        'valkey' => [
            'driver' => 'redis',
            'connection' => 'default',          // valkey-core db 0, noeviction
            'queue' => env('REDIS_QUEUE', 'ai-dispatch'),
            'retry_after' => 180,
            // Never 0: "Setting block_for to 0 will cause queue workers to block indefinitely until
            // a job is available. This will also prevent signals such as SIGTERM from being handled
            // until the next job has been processed." — i.e. a deploy hangs.
            'block_for' => 5,
            // We dispatch from inside DB::transaction (audit row + job row + dispatch). Without
            // this the worker pops the job before the commit lands and fails with
            // ModelNotFoundException for a row the dispatcher just created.
            'after_commit' => true,
        ],

        // Long-running control-plane work: `maintenance` (reconciliation sweeps, quota rollups) and
        // `exports` (CSV / ZIP / PDF over usage_events and conversations).
        'valkey-long' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_LONG_QUEUE', 'maintenance'),
            'retry_after' => 1800,
            'block_for' => 5,
            'after_commit' => true,
        ],

    ],

    'batching' => [
        'database' => 'pgsql',
        'table' => 'job_batches',
    ],

    // failed_jobs holds the FULL payload plus the exception trace, indefinitely, until
    // queue:prune-failed runs. That makes it a tenant-data store: every tenant-bearing job
    // implements ShouldBeEncrypted, and this table is inside the retention policy.
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => 'pgsql',
        'table' => 'failed_jobs',
    ],

];

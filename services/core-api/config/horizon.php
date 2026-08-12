<?php

declare(strict_types=1);

return [

    // Horizon's own logical DB on valkey-core (db 3). Never the queue DB: `horizon:clear` and
    // Horizon's trimming would otherwise sit next to the queue lists.
    'use' => 'horizon',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'domain' => null,
    'path' => env('HORIZON_PATH', 'horizon'),

    // The dashboard renders EVERY organization's job payloads, tags and exception traces side by
    // side. It is a cross-tenant surface, so the `viewHorizon` gate (AppServiceProvider) must
    // resolve a PLATFORM OPERATOR — a tenant admin who can open /horizon is a tenancy layer-1
    // breach, not a UX bug. The published gate defaults to local-only: keeping that default in
    // production is a lockout, replacing it with `true` is the breach.
    'middleware' => ['web', 'auth', 'can:viewHorizon'],

    'waits' => [
        'redis:ai-dispatch' => 60,
        'redis:notify' => 120,
        'redis:maintenance' => 600,
        'redis:exports' => 600,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 128,

    'defaults' => [
        // SUPERVISOR `timeout` IS THE `--timeout` OF THE ONE ARITHMETIC. Under Horizon there is no
        // queue:work command line to pass it to. It must stay BELOW its connection's retry_after
        // (config/queue.php), and Horizon's own docs confirm why: with the `auto` balancing
        // strategy Horizon force-kills in-progress workers it considers hanging at this value.
        'worker-fast' => [
            'connection' => 'valkey',
            'queue' => ['ai-dispatch', 'notify'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 3600,          // recycle the process; PHP-FPM-style leak insurance
            'maxJobs' => 0,
            'memory' => 192,
            // `tries` is the CAP only — kb-error-taxonomy decides every retry, per error_class,
            // inside the job. A value here that exceeds the class's policy retries a
            // never-retryable failure to the cap.
            //
            // (Written above the key rather than as an aligned trailing block: Pint's
            // array_indentation rule re-indents a comment continuation line to the array's own
            // level, which turns the aligned block into ragged text that reads as if it belonged
            // to the next key. One rule, one place — do not restore the alignment.)
            'tries' => 1,
            'timeout' => 120,           // < retry_after 180
            'nice' => 0,
        ],

        'worker-long' => [
            'connection' => 'valkey-long',
            'queue' => ['maintenance', 'exports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 7200,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 1500,          // < retry_after 1800
            'nice' => 0,
        ],
    ],

    // One worker container per cost class. A single --queue=a,b,c worker lets a 20-minute export
    // starve ingestion submission behind it, and no worker may listen to both a `valkey` and a
    // `valkey-long` queue — the two have different retry_after values and the reservation is
    // stamped from the connection.
    'environments' => [

        'production' => [
            'worker-fast' => [
                'minProcesses' => 2,
                'maxProcesses' => 12,
            ],
            'worker-long' => [
                'minProcesses' => 1,
                'maxProcesses' => 4,
            ],
        ],

        'staging' => [
            'worker-fast' => [
                'minProcesses' => 1,
                'maxProcesses' => 4,
            ],
            'worker-long' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'worker-fast' => [
                'maxProcesses' => 2,
            ],
            'worker-long' => [
                'maxProcesses' => 1,
            ],
        ],

    ],

];

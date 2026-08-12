<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

/*
 * Every credential below is resolved FILE-FIRST through KbSecrets: `DB_PASSWORD_FILE`
 * (or `_PATH`) names a mounted secret and wins over any inline `DB_PASSWORD`. The DSN-shaped
 * `*_URL` keys go through the same resolver even though their NAMES are not secret-shaped —
 * a `postgres://user:pass@host/db` string is key material regardless of what it is called, and
 * the arch test's name pattern cannot see that. See App\Support\Kb\KbSecrets.
 */

return [

    // PostgreSQL, always — including in the test suite. The schema depends on jsonb, partial unique
    // indexes (the single-active-version constraint), CHECK constraints and composite foreign keys,
    // all of which SQLite silently accepts and does not enforce (pest-testing NN6).
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => KbSecrets::get('DB_URL'),
            'host' => env('DB_HOST', 'postgres'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'knowledgebot'),
            'username' => env('DB_USERNAME', 'knowledgebot'),
            // DB_PASSWORD_FILE=/run/secrets/postgres_password
            'password' => KbSecrets::get('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        // phpredis, not Predis: Laravel's documented recommendation and the default value of this
        // key. A worker issues an EVAL per poll on every queue, forever — the C extension is the
        // right side of that trade (laravel-queues-valkey).
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),

            // Empty, not the framework's `{APP_NAME}_database_` default. The Valkey keyspace is one
            // designed namespace shared with the FastAPI side (valkey-keyspaces): queues:{name},
            // horizon:*, idem:{org}:… must appear literally, or the two runtimes cannot see the same
            // keys. Environment separation is the logical DB plus a Valkey 9.1 database-level ACL
            // user, never a client-side prefix — Laravel's prefix has historically not reached queue
            // key names anyway (laravel/framework#27896).
            'prefix' => env('REDIS_PREFIX', ''),

            // NO `serializer` and NO `compression`. Laravel's docs state plainly that both are
            // unsupported by the `redis` QUEUE driver; setting them here writes payloads the queue
            // worker cannot read back, and the failure is a job that vanishes rather than an error.
        ],

        // valkey-core, logical db 0: Laravel queues AND the default cache store. noeviction.
        'default' => [
            'url' => KbSecrets::get('REDIS_URL'),
            'host' => env('REDIS_HOST', 'valkey-core'),
            'username' => env('REDIS_USERNAME'),
            'password' => KbSecrets::get('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
        ],

        // valkey-core, logical db 2: locks, fences, idempotency records, replay nonces, breakers,
        // rate-limit counters — everything shared with the data plane and everything that fails
        // OPEN when it disappears.
        'coordination' => [
            'url' => KbSecrets::get('REDIS_COORDINATION_URL'),
            'host' => env('REDIS_HOST', 'valkey-core'),
            'username' => env('REDIS_USERNAME'),
            'password' => KbSecrets::get('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_COORDINATION_DB', 2),
        ],

        // valkey-core, logical db 3. The connection name `horizon` is reserved by Horizon itself.
        'horizon' => [
            'url' => KbSecrets::get('REDIS_URL'),
            'host' => env('REDIS_HOST', 'valkey-core'),
            'username' => env('REDIS_USERNAME'),
            'password' => KbSecrets::get('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_HORIZON_DB', 3),
            'options' => [
                'prefix' => env('HORIZON_PREFIX', 'horizon:'),
            ],
        ],

        // valkey-cache: allkeys-lru, no persistence, holds tenant text. Reachable only through
        // Cache::store('ephemeral'). Overridden per worker under --parallel.
        'ephemeral' => [
            'url' => KbSecrets::get('REDIS_CACHE_URL'),
            'host' => env('REDIS_CACHE_HOST', 'valkey-cache'),
            'username' => env('REDIS_CACHE_USERNAME'),
            'password' => KbSecrets::get('REDIS_CACHE_PASSWORD'),
            'port' => (int) env('REDIS_CACHE_PORT', 6379),
            'database' => (int) env('REDIS_CACHE_DB', 0),
        ],

    ],

];

<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    // The DEFAULT store must be the non-evicting instance. Four independent mechanisms resolve
    // through it and every one of them fails OPEN when its key disappears:
    //   - WithoutOverlapping  (Illuminate\Contracts\Cache\Repository — the default store, and there
    //     is NO WAY to point it at another store; uniqueVia() covers ShouldBeUnique only)
    //   - ShouldBeUnique / ShouldBeUniqueUntilProcessing
    //   - the scheduler's onOneServer and withoutOverlapping mutexes
    //   - the queue:restart / queue:pause signals a worker polls each iteration
    // On an allkeys-lru instance an evicted lock key reads as "lock is free" and an evicted queue
    // list reads as "empty queue" — neither raises anything, in either direction
    // (laravel-queues-valkey, laravel-scheduler, valkey-keyspaces).
    'default' => env('CACHE_STORE', 'valkey'),

    'stores' => [

        // valkey-core: maxmemory-policy noeviction, AOF everysec. Logical db 0, shared with the
        // Laravel queues. maxmemory-policy is a SERVER setting, so a different logical db does not
        // and cannot fix an evicting instance.
        'valkey' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],

        // valkey-cache: allkeys-lru, no RDB, no AOF. Reachable ONLY as Cache::store('ephemeral'),
        // never as the default, and only for the two read-through families that may be lost:
        // cfg:{org}:{bot}:{version} and quota:{org}:{period}:{metric} (valkey-keyspaces).
        // lock_connection stays on `default` on purpose: a lock is absence-sensitive, so even a lock
        // taken through this store must land on the instance that never evicts.
        'ephemeral' => [
            'driver' => 'redis',
            'connection' => 'ephemeral',
            'lock_connection' => 'default',
        ],

        // Test-process-local only. Never a candidate for CACHE_STORE: ArrayStore implements
        // LockProvider, so onOneServer acquires a lock successfully in each replica's own memory and
        // every replica runs the task — with no exception, no warning and no log line.
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

    ],

    // Overridden per worker under `php artisan test --parallel` (ParallelTesting::setUpProcess in
    // AppServiceProvider) — Laravel token-scopes the DATABASE and nothing else.
    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'kb'), '_').'_cache_'),

];

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Empty, and it stays empty until there is something a FRESH ENVIRONMENT genuinely cannot start
 * without.
 *
 * Test fixtures do not belong here. Every isolation test builds its own two organizations through
 * tenantPair() (tests/Support/tenancy.php) with a fresh per-test canary; a shared seeded tenant is
 * exactly the fixture that makes an isolation suite pass against code with no filter at all
 * (pest-testing NN1).
 *
 * One row that WILL belong here eventually: the `scheduler_ticks` seed. It is seeded by the
 * MIGRATION rather than by a seeder, so that a scheduler which never started reads as an ancient
 * timestamp rather than a missing Prometheus series — an alert fires on a value, never on an
 * absence (laravel-scheduler).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}

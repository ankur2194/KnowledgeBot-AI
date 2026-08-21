<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The suite bootstrap — LARAVEL_START, then the autoloader
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS AT ALL, WHEN `vendor/autoload.php` WAS DOING THE JOB.
|
| `LARAVEL_START` is defined in exactly two places in a deployed system — `public/index.php` for an
| HTTP request and `artisan` for a console command — and `App\Services\Internal\InternalAiClient`
| reads it to compute `X-KB-Deadline` for every request-scoped internal call. `phpunit.xml` used to
| bootstrap `vendor/autoload.php`, which defines nothing, so `defined('LARAVEL_START')` was FALSE
| for the whole suite and the client's `microtime(true)` fallback was the only branch a test could
| ever reach. The production branch was therefore unexecuted and unfalsifiable: the deadline
| assertion in tests/Feature/SubmitIngestionJobTest.php could not fail, because the fallback is
| always correct by construction, and a real defect (finding B1 — a warm queue worker sending a
| deadline in the past, measured from the WORKER'S BOOT) sat behind a green test.
|
| Defining it here makes the branch under test the branch that ships. It is deliberately the FIRST
| statement, before the autoloader, for the same reason `public/index.php:16` puts it before
| `require vendor/autoload.php`: the constant is meant to be the earliest instant the process can
| name, and a constant defined after several thousand class-map entries have been read is a
| slightly-late one that would quietly bias any arithmetic derived from it.
|
| THE `defined()` GUARD IS NOT DEFENSIVE NOISE. `php artisan test` reaches PHPUnit through
| `artisan`, which defines the constant at its line 9; a bare `define()` here would then emit
| "Constant LARAVEL_START already defined" — and `phpunit.xml` sets `failOnWarning="true"`, so the
| notice would fail the run rather than merely printing.
|
| WHAT THIS CHANGES FOR THE REST OF THE SUITE: one thing, and it is intended. Any request-scoped
| internal call made from a test now computes its deadline from the SUITE'S boot rather than from
| the moment of the call, exactly as an FPM request computes it from the request's boot. Nothing
| else in `app/` or `vendor/` reads the constant — the only other reader in the tree is Laravel's
| own `health-up.blade.php`, which prints a render time and is not exercised here.
*/

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';

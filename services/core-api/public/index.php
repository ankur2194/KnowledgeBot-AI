<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// LARAVEL_START is load-bearing, not a profiling nicety. X-KB-Deadline on every internal call made
// from an HTTP REQUEST is an ABSOLUTE epoch-millisecond value derived from this instant plus that
// call's budget in config/kb.php (kb-internal-api-contracts). Deriving it from a fresh microtime()
// inside the client would restart the budget downstream, which is exactly what the absolute-deadline
// rule exists to prevent.
//
// THAT IS TRUE OF A REQUEST AND ONLY OF A REQUEST, and the qualifier is the whole of finding B1.
// This constant is PROCESS-scoped: it is one request's start only because one FPM process serves
// one request. A queue worker is a long-lived process where it is the worker's BOOT, so a queued
// caller measures from `microtime(true)` instead — App\Services\Internal\InternalAiClient makes
// the choice at each call site through `requestEpoch()` and `callEpoch()`.
//
// A test now ties the header to this constant rather than a comment doing it: the suite bootstrap
// (tests/bootstrap.php) defines LARAVEL_START so the request-scoped branch is the one under test,
// and tests/Feature/EmbeddingConfigurationTest.php asserts the readiness deadline is exactly
// LARAVEL_START plus that budget while tests/Feature/SubmitIngestionJobTest.php asserts the queued
// deadline is measured from the call.
define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

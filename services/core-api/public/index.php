<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// LARAVEL_START is load-bearing, not a profiling nicety. X-KB-Deadline on every internal call is an
// ABSOLUTE epoch-millisecond value derived from this instant plus the chat budget in config/kb.php
// (kb-internal-api-contracts). Deriving it from a fresh microtime() inside the client would restart
// the budget downstream, which is exactly what the absolute-deadline rule exists to prevent — and a
// DoD test must assert the deadline is computed from this constant once
// App\Services\Internal\InternalAiClient lands. No such test exists today, and it is unwritable
// until then: there is no client to call and tests/Contract holds no cases. Nothing but this
// comment currently ties X-KB-Deadline to this constant.
define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

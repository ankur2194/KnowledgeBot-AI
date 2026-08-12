<?php

declare(strict_types=1);

use App\Logging\KbJsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * `service` and `env` are the two process-wide fields on every log line, and they are resolved
 * HERE — once, at config-build time — rather than inside the formatter, for two reasons:
 *
 *  1. `tests/Arch/DoctrineTest.php` bans `env()` outside `config/`. A value read anywhere else is
 *     correct until the first `php artisan config:cache`, after which it is silently null.
 *  2. They are two of the four permitted Loki stream labels. Re-reading the environment per record
 *     would let a mid-process mutation split one container's logs across two streams.
 *
 * OTEL_SERVICE_NAME and OTEL_RESOURCE_ATTRIBUTES first, in both cases, because those are what the
 * OTel Resource carries and therefore what the Collector projects onto the `service` and `env`
 * METRIC labels (`transform/metric_labels` in infrastructure/docker/otel/collector.yaml). A log
 * field that disagrees with the metric label for the same process is the quiet version of a broken
 * dashboard: both queries succeed and they describe different fleets.
 */
$kbLogService = (string) env('OTEL_SERVICE_NAME', 'core-api');
$kbLogEnv = KbJsonFormatter::environmentFrom(
    (string) env('OTEL_RESOURCE_ATTRIBUTES', ''),
    (string) env('APP_ENV', 'production'),
);

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'stdout'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            // Named, for the same reason the two leaf channels are: LogManager::parseChannel()
            // defaults a stack's Monolog channel name to $app->environment(), so without this every
            // line written through the DEFAULT channel — which is most of them, including every
            // reported exception — carries `logger: "production"`, a second copy of `env` dressed
            // up as provenance.
            'name' => 'app',
            'channels' => explode(',', (string) env('LOG_STACK', 'stdout')),
            'ignore_exceptions' => false,
        ],

        // STDOUT AS JSON, AND NOTHING ELSE. No `daily`, no `single`, no file channel at all:
        //  - a rotating file inside a container is written to a layer nobody reads and lost on every
        //    restart, while quietly filling the host's disk in the meantime;
        //  - the Collector's `file_log` receiver reads the container's stdout, so the app never blocks
        //    on the log pipeline (OTEL_LOGS_EXPORTER=none for the same reason);
        //  - JSON because every log line must carry error_class, request_id and org_id as FIELDS —
        //    a line-formatted message cannot be queried by them in Loki.
        // Nothing written here may contain a provider credential, an X-KB-Signature, a packed
        // prompt, or retrieved tenant content (kb-observability-conventions, kb-security-baseline).
        //
        // App\Logging\KbJsonFormatter, NOT Monolog\Formatter\JsonFormatter. The stock formatter
        // emits `{message, context, level, level_name, channel, datetime, extra}` — none of the
        // eight fields the log contract requires, `context` nested under a key that makes every
        // Loki query a JSON path expression, and `level` as MONOLOG'S INTEGER (200, 400). The
        // Collector's severity parser is guarded with `type(attributes.level) == "string"`
        // precisely because an integer errors the operator, so every line this service wrote
        // reached Loki with no `level` stream label at all (finding #56). The class docblock
        // carries the measured evidence for the severity strings.
        'stdout' => [
            'driver' => 'monolog',
            // `name` is the Monolog CHANNEL name, and it is set explicitly because Laravel's
            // LogManager::parseChannel() defaults it to `$app->environment()` — so without this the
            // `logger` field on every line reads "production", which is `env` a second time and
            // tells a reader nothing about where the line came from.
            'name' => 'stdout',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stdout',
            ],
            'formatter' => KbJsonFormatter::class,
            // No `appendNewline`: this formatter terminates its own line, because "one JSON object
            // per line" is the contract rather than a handler option somebody can turn off.
            'formatter_with' => [
                'service' => $kbLogService,
                'env' => $kbLogEnv,
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // Same shape on stderr, for the console and scheduler containers where an operator wants
        // application output separated from command output. Same formatter, same fields: a second
        // line shape on the second stream is how half the fleet ends up unqueryable.
        'stderr' => [
            'driver' => 'monolog',
            'name' => 'stderr',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => KbJsonFormatter::class,
            'formatter_with' => [
                'service' => $kbLogService,
                'env' => $kbLogEnv,
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ],

        // LogManager::createEmergencyLogger() reads ONLY `path` from this entry and builds its own
        // StreamHandler — a driver/handler/formatter block here is silently ignored. Left as a
        // stream URL so the last-resort logger for "logging itself failed" still reaches the
        // container's output instead of storage/logs/laravel.log, which nobody collects.
        'emergency' => [
            'path' => 'php://stderr',
        ],

    ],

];

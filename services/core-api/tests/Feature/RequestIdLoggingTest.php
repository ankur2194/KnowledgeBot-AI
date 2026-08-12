<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Logging\KbJsonFormatter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Correlation, end to end (finding #56)
|--------------------------------------------------------------------------
|
| These tests drive the SHIPPED `stdout` channel — `config/logging.php` untouched except for the
| stream, which is redirected to a temporary file. Building a private channel with the same options
| would only prove this file agrees with itself, and would keep agreeing after the real channel
| drifted: the whole defect being fixed here was a config entry pointing at the wrong formatter.
*/

/**
 * Point the real `stdout` channel at a file and return its path.
 *
 * `forgetChannel` matters: LogManager memoises a resolved channel, so without it a channel
 * resolved earlier in the request keeps writing to php://stdout and the file stays empty.
 */
function kbCaptureStdoutChannel(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kb-log-');

    config(['logging.channels.stdout.handler_with.stream' => $path]);
    Log::forgetChannel('stdout');

    $GLOBALS['kb_captured_log_files'][] = $path;

    return $path;
}

afterEach(function (): void {
    /** @var list<string> $paths */
    $paths = $GLOBALS['kb_captured_log_files'] ?? [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    $GLOBALS['kb_captured_log_files'] = [];
});

/**
 * @return list<array<string, mixed>>
 */
function kbReadLogLines(string $path): array
{
    $lines = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (trim($line) === '') {
            continue;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $lines[] = $decoded;
    }

    return $lines;
}

it('writes a real contract-shaped line through the configured stdout channel', function (): void {
    $path = kbCaptureStdoutChannel();

    Route::middleware('runtime')
        ->get('/rt/v1/_kb_test/log', function (): array {
            Log::channel('stdout')->error('probe line', ['error_class' => 'internal_dependency']);

            return ['ok' => true];
        })
        ->name('runtime._kb_test.log');

    currentTest()->getJson('/rt/v1/_kb_test/log')->assertOk();

    $lines = kbReadLogLines($path);

    expect($lines)->toHaveCount(1);

    $line = $lines[0];

    foreach (KbJsonFormatter::REQUIRED_FIELDS as $field) {
        expect(array_key_exists($field, $line))->toBeTrue($field);
    }

    // `service` and `env` come from config/logging.php's two env() reads, so a non-empty value here
    // is what proves `formatter_with` actually reached the constructor — the shape of the defect
    // being fixed was a config entry that resolved to the wrong thing in silence.
    //
    // NOT asserted as 'testing'. `env` prefers OTEL_RESOURCE_ATTRIBUTES' deployment.environment.name
    // over APP_ENV, deliberately, so that the log field and the Collector-projected `env` METRIC
    // label describe one fleet — and a developer checkout whose .env pins that attribute correctly
    // reports its own value here while phpunit.xml's APP_ENV says `testing`. Pinning either string
    // would make this test a function of whose machine it runs on. The resolution RULE is pinned in
    // tests/Unit/KbJsonFormatterTest.php, where it can be exercised without an environment.
    expect($line['severity'])->toBe('ERROR')
        ->and($line['operation'])->toBe('runtime._kb_test.log')
        ->and($line['error_class'])->toBe('internal_dependency')
        ->and($line['request_id'])->toBeString()
        ->and($line['logger'])->toBe('stdout');

    expect($line['service'])->toBeString();
    expect($line['service'])->not->toBe('');
    expect($line['env'])->toBeString();
    expect($line['env'])->not->toBe('');
});

it('puts the same request id on every line of one request, and a different one on the next', function (): void {
    $path = kbCaptureStdoutChannel();

    Route::middleware('runtime')
        ->get('/rt/v1/_kb_test/twice', function (): array {
            Log::channel('stdout')->info('first');
            Log::channel('stdout')->info('second');

            return ['ok' => true];
        })
        ->name('runtime._kb_test.twice');

    $first = currentTest()->getJson('/rt/v1/_kb_test/twice')->assertOk();
    $second = currentTest()->getJson('/rt/v1/_kb_test/twice')->assertOk();

    $lines = kbReadLogLines($path);

    expect($lines)->toHaveCount(4);

    // Stable within a request…
    expect($lines[0]['request_id'])->toBe($lines[1]['request_id'])
        ->and($lines[2]['request_id'])->toBe($lines[3]['request_id']);

    // …and distinct across requests. A constant id correlates nothing.
    expect($lines[0]['request_id'])->not->toBe($lines[2]['request_id']);

    // The client is told which id its request wore, so a bug report carries the grep key.
    expect($first->headers->get(RequestId::HEADER))->toBe($lines[0]['request_id'])
        ->and($second->headers->get(RequestId::HEADER))->toBe($lines[2]['request_id']);
});

it('adopts a well-formed inbound X-KB-Request-Id so one id spans both planes', function (): void {
    $path = kbCaptureStdoutChannel();

    Route::middleware('runtime')
        ->get('/rt/v1/_kb_test/adopt', function (): array {
            Log::channel('stdout')->info('adopted');

            return ['ok' => true];
        })
        ->name('runtime._kb_test.adopt');

    $supplied = '01JAAAAAAAAAAAAAAAAAAAAAAA';

    $response = currentTest()->getJson('/rt/v1/_kb_test/adopt', [RequestId::HEADER => $supplied]);

    expect($response->headers->get(RequestId::HEADER))->toBe($supplied)
        ->and(kbReadLogLines($path)[0]['request_id'])->toBe($supplied);
});

it('refuses an inbound id that could forge a log line, and mints its own instead', function (string $hostile): void {
    $path = kbCaptureStdoutChannel();

    Route::middleware('runtime')
        ->get('/rt/v1/_kb_test/hostile', function (): array {
            Log::channel('stdout')->info('hostile');

            return ['ok' => true];
        })
        ->name('runtime._kb_test.hostile');

    currentTest()->getJson('/rt/v1/_kb_test/hostile', [RequestId::HEADER => $hostile])->assertOk();

    $lines = kbReadLogLines($path);

    // One line, not two: a newline in the id must not be able to split one record into two, the
    // second of which the caller wrote.
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['request_id'])->not->toBe($hostile)
        ->and($lines[0]['request_id'])->toBeString();
})->with([
    'a newline splice' => ["01JAAAA\n{\"severity\":\"INFO\",\"message\":\"forged\"}"],
    'a quote' => ['01JAAAA"injected'],
    'too short' => ['x'],
    'unbounded' => [str_repeat('a', 200)],
    'empty' => [''],
]);

it('gives the error envelope the same request id the log line carries', function (): void {
    $path = kbCaptureStdoutChannel();

    Route::middleware('runtime')
        ->get('/rt/v1/_kb_test/boom', function (): never {
            Log::channel('stdout')->warning('about to fail');

            abort(404);
        })
        ->name('runtime._kb_test.boom');

    $response = currentTest()->getJson('/rt/v1/_kb_test/boom')->assertStatus(404);

    // bootstrap/app.php's render closure reads X-KB-Request-Id off the REQUEST, which this
    // middleware writes back — so the id in the envelope and the id in the log line are one string
    // by construction rather than by two separate ULID calls agreeing by luck.
    expect($response->json('request_id'))->toBe(kbReadLogLines($path)[0]['request_id']);
});

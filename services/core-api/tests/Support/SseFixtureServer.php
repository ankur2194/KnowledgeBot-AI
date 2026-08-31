<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A REAL HTTP server that streams real SSE over a real socket, with real delays.
 *
 * ═══ WHY THIS EXISTS AT ALL: `Http::fake()` CANNOT FAIL A STREAMING TEST ════════════════════
 *
 * `pest-testing` non-negotiable 4 and `kb-internal-api-contracts` both say it, and the mechanism is
 * worth stating because the failure is invisible: a faked body is a STRING, so the relay drains it
 * in microseconds. Buffering, event ordering, heartbeat cadence, read timeouts and disconnect
 * handling are all untestable, `resource()` over a string stream never blocks, and every one of
 * those bugs passes. A green suite over `Http::fake()` proves the parser and nothing else.
 *
 * So this is `php -S` on a loopback port, driven by a scripted sequence of delays and raw frames. It
 * can hold the connection open, it can go quiet for a measured interval, and it can HANG UP
 * MID-STREAM — the three behaviours a fake cannot have.
 *
 * ═══ IT ALSO RECORDS WHAT IT RECEIVED, WHICH IS THE OTHER HALF ═════════════════════════════
 *
 * The relay is the only place `provider_credentials` is constructed, and the only way to assert that
 * a decrypted key crossed the wire — and that it crossed in the RIGHT PLACE, top-level and not
 * inside `config` — is to read the bytes the far side actually got. `received()` returns them.
 *
 * ═══ THE TWO THINGS THAT MAKE `php -S` STREAM RATHER THAN BUFFER ═══════════════════════════
 *
 * `-d output_buffering=0` and `-d implicit_flush=1` on the server process, plus an `ob_flush()` /
 * `flush()` after every frame in the router. Without them the built-in server accumulates the whole
 * response and sends it at once — which is exactly the production defect these tests exist to catch,
 * reproduced in the harness instead of in the code under test.
 */
final class SseFixtureServer
{
    /**
     * Every server started in the current test, so `afterEach` can stop them without holding one.
     *
     * A SERVER THAT IS NOT STOPPED IS A LEAKED `php -S` PROCESS AND A LEAKED PORT, and the symptom
     * is not a failure — it is a suite that gets slower and eventually cannot bind. Registering here
     * means a test can start one and forget it, and `stopAll()` is one line in `afterEach` with no
     * `$this` state to type.
     *
     * @var list<self>
     */
    private static array $running = [];

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct(
        private readonly string $directory,
        private readonly int $port,
    ) {}

    /**
     * Boot a server that answers ONE scripted response.
     *
     * @param  list<array{delay_ms?: int, raw?: string}>  $steps  played in order. `delay_ms` sleeps
     *                                                            WITHOUT writing, which is what a thinking upstream looks like and is the only way
     *                                                            to exercise the relay's idle-read branch. `raw` is written verbatim, so a test can
     *                                                            send a malformed frame, a bare comment, or two frames in one write.
     * @param  int  $status  the response status. Anything other than 200 is answered as a JSON error
     *                       envelope instead — the pre-stream refusal path, which is a different code path in
     *                       the client and has to be reachable from here.
     * @param  string  $errorBody  the JSON body for a non-200
     */
    public static function start(array $steps, int $status = 200, string $errorBody = '{}'): self
    {
        $directory = sys_get_temp_dir().'/kb-sse-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create the fixture server's directory {$directory}");
        }

        file_put_contents($directory.'/script.json', json_encode([
            'status' => $status,
            'error_body' => $errorBody,
            'steps' => $steps,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($directory.'/router.php', self::router());

        $port = self::freePort();
        $server = new self($directory, $port);
        $server->spawn();

        self::$running[] = $server;

        return $server;
    }

    /**
     * Stop every server this test started. Safe to call when none were.
     */
    public static function stopAll(): void
    {
        foreach (self::$running as $server) {
            $server->stop();
        }

        self::$running = [];
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * Every request the server received, most recent last.
     *
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    public function received(): array
    {
        $requests = [];

        foreach (glob($this->directory.'/request-*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                /** @var array{method: string, uri: string, headers: array<string, string>, body: string} $decoded */
                $requests[] = $decoded;
            }
        }

        return $requests;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            // TERMINATED RATHER THAN CLOSED. `proc_close()` WAITS for the child, and a built-in
            // server never exits on its own — the suite would hang here rather than fail, which is
            // the worst way for a harness to break.
            proc_terminate($this->process, SIGKILL);

            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($this->process);
            $this->process = null;
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function spawn(): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [
                PHP_BINARY,
                // THE TWO FLAGS THAT MAKE IT A STREAM. See the class docblock.
                '-d', 'output_buffering=0',
                '-d', 'implicit_flush=1',
                '-S', '127.0.0.1:'.$this->port,
                '-t', $this->directory,
                $this->directory.'/router.php',
            ],
            $descriptors,
            $this->pipes,
            $this->directory,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('could not start the SSE fixture server');
        }

        $this->process = $process;

        // WAIT FOR THE PORT TO ANSWER, and never `sleep()` a fixed interval: a value tuned on the
        // machine that had the problem last is the flake every CI-less suite ends up with. Two
        // seconds is an eternity for a loopback bind and is a ceiling rather than a delay.
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        $this->stop();

        throw new RuntimeException('the SSE fixture server did not accept a connection within 2s');
    }

    /**
     * A free loopback port, taken by binding one and closing it.
     *
     * There is a race between the close and the server's bind, and it is accepted deliberately: the
     * alternative is a fixed port, which collides with a parallel worker every time and produces a
     * failure that reads as a code defect. `SO_REUSEADDR` on the built-in server closes the window
     * to microseconds.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if (! is_resource($socket)) {
            throw new RuntimeException("could not reserve a port for the fixture server: {$error}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || ! str_contains($name, ':')) {
            throw new RuntimeException('could not read the reserved port');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * The router the built-in server runs.
     *
     * WRITTEN AS A STRING RATHER THAN SHIPPED AS A FILE so the harness is one class: a second file
     * under `tests/` that only ever runs inside a subprocess is a file nobody notices going stale,
     * and PHPStan would analyse it as though it were part of the suite.
     */
    private static function router(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            $dir = __DIR__;
            $script = json_decode((string) file_get_contents($dir . '/script.json'), true);

            // RECORDED BEFORE ANYTHING IS WRITTEN, so a test can assert on the request even when the
            // response is a hang-up. One file per request: the relay is meant to call exactly once,
            // and a test that asserts "once" needs to be able to count.
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
                }
            }

            $body = (string) file_get_contents('php://input');

            file_put_contents(
                $dir . '/request-' . microtime(true) . '-' . bin2hex(random_bytes(4)) . '.json',
                json_encode([
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'headers' => $headers,
                    'body' => $body,
                ], JSON_THROW_ON_ERROR)
            );

            // `__MESSAGE_ID__` IS ECHOED FROM THE REQUEST, which is what the real endpoint does:
            // Laravel mints the assistant `messages` row before the call, and `message.start` and
            // `message.complete` both carry the id they were GIVEN. A fixture that invented one would
            // hand the client an id that resolves to no row — the exact defect
            // `ChatExecuteRequest.message_id`'s docblock exists to prevent — and every id assertion
            // downstream would be testing the fixture.
            $decoded = json_decode($body, true);
            $messageId = is_array($decoded) && is_string($decoded['message_id'] ?? null)
                ? $decoded['message_id']
                : '__MESSAGE_ID__';

            if (($script['status'] ?? 200) !== 200) {
                http_response_code((int) $script['status']);
                header('Content-Type: application/json');
                echo $script['error_body'];

                return true;
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');

            foreach ($script['steps'] as $step) {
                if (isset($step['delay_ms'])) {
                    usleep(((int) $step['delay_ms']) * 1000);

                    continue;
                }

                echo str_replace('__MESSAGE_ID__', $messageId, (string) ($step['raw'] ?? ''));

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }

            return true;
            PHP;
    }
}

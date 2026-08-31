<?php

declare(strict_types=1);

namespace App\Services\Internal;

use Generator;

/**
 * The live SSE body coming back from `POST /internal/v1/chat/stream`, as a thing the relay can pump.
 *
 * ═══ IT OWNS A SOCKET, AND `cancel()` IS THE ONLY REASON IT IS AN OBJECT ════════════════════
 *
 * A generator alone would carry the frames and nothing else. What the relay needs beyond frames is
 * the ability to CLOSE THE BODY when the client goes away, because that close is what Starlette
 * delivers to the chat endpoint as `http.disconnect`, which cancels the pipeline task, which closes
 * the provider socket, which is what stops the vendor generating tokens nobody will read. Skip it
 * and the turn keeps billing for as long as the model wants to talk
 * (`laravel-control-plane`, the relay reference's `finally`).
 *
 * ═══ THE THREE THINGS `frames()` CAN YIELD, AND WHY `null` IS ONE OF THEM ═══════════════════
 *
 *   ParsedEvent          a complete frame — `event:` plus `data:` plus the blank line.
 *   ParsedEvent(heartbeat) an SSE COMMENT (`: ping`) from upstream. FastAPI's own SSE path inserts
 *                        one after 15 s of generator idleness. It is surfaced as a named frame so
 *                        the relay has one shape to match on, and `ClientEvents` refuses it.
 *   null                 A READ RETURNED NOTHING AND THE STREAM IS STILL OPEN. This is the case
 *                        that makes the relay's heartbeat work at all: `stream_get_line()` returns
 *                        `false` when the read timeout elapses with no bytes, and that is
 *                        INDISTINGUISHABLE FROM EOF unless `feof()` is consulted separately. Without
 *                        the `null`, a silent upstream would either look like a finished stream (the
 *                        relay writes a terminal frame for a turn still running) or block the FPM
 *                        child until the socket died — and in the second case the relay writes
 *                        nothing, so `connection_aborted()` stays `0` and a departed client is never
 *                        noticed. PHP learns the client is gone only when a write FAILS.
 *
 * ═══ `read_timeout` IS SET BY THE CALLER AND IS LOAD-BEARING ════════════════════════════════
 *
 * Guzzle's `read_timeout` is documented to default to `default_socket_timeout` and is in fact never
 * set (guzzle#2783), so with `'stream' => true` — which returns as soon as headers land —
 * `Http::timeout()` does not bound the body read at all. `InternalAiClient::openChatStream()` sets
 * it explicitly, ABOVE the heartbeat interval and below twice it, so a healthy-but-quiet upstream
 * produces a `null` rather than a stall.
 *
 * THIS CLASS DOES NOT RE-ASSERT IT, and the constructor says at length why the obvious belt —
 * `stream_set_timeout()` on the handle — is a warning rather than a belt.
 *
 * ═══ THE PARSER IS LINE-ORIENTED AND DELIBERATELY MINIMAL ═══════════════════════════════════
 *
 * `id:` and `retry:` are ignored: token streams are NOT resumable (`kb-internal-api-contracts`, the
 * `Last-Event-ID` gotcha), so an id we forwarded would invite a reconnect that either re-bills the
 * turn or replays tokens the reader already saw. Multiple `data:` lines in one frame are joined with
 * a newline, per the SSE grammar; the data plane never emits one, and a parser that dropped all but
 * the last would truncate an answer rather than fail.
 */
final class UpstreamStream
{
    /**
     * The upstream frame name for an SSE comment. Not one of the data plane's nine event names —
     * see `ClientEvents` for why it lives on the internal-only list anyway.
     */
    public const HEARTBEAT = 'heartbeat';

    /**
     * Bytes any single SSE line may carry before the stream is abandoned.
     *
     * A bound rather than a preference: `stream_get_line()` with no length reads until the delimiter,
     * so a peer that never sends a newline is an unbounded string in the memory of an FPM child that
     * is already holding a slot in `pm.max_children`. 512 KiB is two orders of magnitude above the
     * largest real frame (a `retrieval.trace` over a full candidate set) and is not a product limit.
     */
    private const MAX_LINE_BYTES = 524_288;

    /** @var resource|null */
    private $resource;

    /**
     * @param  resource  $resource  the SOCKET the handler is reading, detached from the PSR body by
     *                              `InternalAiClient::openChatStream()`. Not `Response::resource()`,
     *                              which is a userland wrapper — see below.
     * @param  (callable(): void)|null  $onCancel  an extra teardown the caller owns — closing the
     *                                             Guzzle response object itself, for instance. Run
     *                                             exactly once, after the resource is closed.
     */
    public function __construct($resource, private $onCancel = null)
    {
        // ── NOTHING IS SET ON THE RESOURCE HERE, AND THAT IS A MEASURED DECISION ─────────────
        //
        // The obvious belt on Guzzle's `read_timeout` is `stream_set_timeout()` on this handle. It
        // does not work, and it does not fail quietly either:
        //
        //     stream_set_timeout(): GuzzleHttp\Psr7\StreamWrapper::stream_set_option is not implemented!
        //
        // `Illuminate\Http\Client\Response::resource()` hands back a USERLAND STREAM WRAPPER around
        // the PSR body, and that wrapper implements no `stream_set_option`, so PHP raises a warning —
        // which Laravel's error handler turns into an `ErrorException` and the relay then reports as
        // `internal_dependency` on a turn that was about to work. It is exactly the class of defect
        // `Http::fake()` cannot surface: a faked body is a string, its wrapper is never exercised,
        // and the whole path passes.
        //
        // The timeout is Guzzle's `read_timeout` option, applied by the StreamHandler to the REAL
        // socket before it ever becomes a PSR body. That is the mechanism; this was only ever a
        // restatement of it, and the restatement is what broke.
        //
        // `openChatStream()` also `detach()`es the PSR body rather than wrapping it, so what arrives
        // here is the socket itself — which is what makes `feof()` mean "the far side hung up" and
        // `fclose()` mean "the far side is told".
        $this->resource = $resource;
    }

    /**
     * Pump the body. Ends when the upstream closes it, or when `cancel()` has been called.
     *
     * @return Generator<int, ParsedEvent|null>
     */
    public function frames(): Generator
    {
        $name = '';
        $data = [];

        while (true) {
            $resource = $this->resource;

            // `cancel()` may be called from the relay's `finally` while this generator is suspended
            // at a `yield`; resuming it afterwards must end rather than read a closed resource.
            if (! is_resource($resource)) {
                return;
            }

            $line = stream_get_line($resource, self::MAX_LINE_BYTES, "\n");

            if ($line === false) {
                if (feof($resource)) {
                    return;   // the upstream finished, cleanly or otherwise
                }

                // A read timeout with the stream still open: the far side is thinking. THIS is the
                // yield the relay turns into `: ping`, and the ping is the disconnect probe.
                yield null;

                continue;
            }

            $line = rtrim($line, "\r");

            if ($line === '') {
                // End of frame. A frame with no `event:` line is not one of ours — the data plane
                // always names its frames — so it is dropped rather than forwarded under a guessed
                // name.
                if ($name !== '') {
                    yield new ParsedEvent($name, implode("\n", $data));
                }

                $name = '';
                $data = [];

                continue;
            }

            if (str_starts_with($line, ':')) {
                // An SSE comment. FastAPI's `: ping`. Surfaced as a frame so the relay has one shape
                // to match on; `ClientEvents` refuses it, and the relay answers with its OWN comment
                // rather than forwarding this one.
                yield new ParsedEvent(self::HEARTBEAT, '');

                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');

            // ONE leading space after the colon is part of the SSE framing and is stripped; a second
            // one is payload. `ltrim($value)` would eat indentation the sender meant.
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }

            match ($field) {
                'event' => $name = $value,
                'data' => $data[] = $value,
                // `id:` and `retry:` are deliberately ignored — see the class docblock. Anything
                // else is a field this contract does not define.
                default => null,
            };
        }
    }

    /**
     * Close the body so the data plane sees `http.disconnect`. Idempotent.
     *
     * Called from the relay's `finally` on EVERY terminal path — completion, client abort, and a
     * relayed error — because the reason to close is the same in all three: whatever this process
     * does next, nobody is going to read another token.
     */
    public function cancel(): void
    {
        $resource = $this->resource;
        $this->resource = null;

        if (is_resource($resource)) {
            fclose($resource);
        }

        $onCancel = $this->onCancel;
        $this->onCancel = null;

        if ($onCancel !== null) {
            $onCancel();
        }
    }
}

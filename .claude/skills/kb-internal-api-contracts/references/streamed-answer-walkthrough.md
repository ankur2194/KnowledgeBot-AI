# Worked example — one streamed answer, including cancellation

Depth for `kb-internal-api-contracts`. Spec: `docs/06-architecture.md` §11, `docs/14-reliability.md` §19.1, `docs/15-observability.md` §20.2.

```
1. Widget → Laravel   POST /api/v1/chat/{conversation}/messages  Accept: text/event-stream
2. Laravel            validates session token + origin, checks org quota, resolves the config snapshot
                      (bot, provider connection, decrypted key, model, pipeline params) → config_version 47
3. Laravel → FastAPI  POST /internal/v1/chat/stream · Accept: text/event-stream · traceparent ·
                      X-KB-Org-Id · X-KB-Bot-Id · X-KB-Actor-Type: anonymous_session · X-KB-Config-Version: 47 ·
                      X-KB-Operation: chat.execute · X-KB-Idempotency-Key: sha256(org|chat.message|conv|client_msg)
4. FastAPI            retrieve → fuse → rerank → pack (emits status), assigns citations, calls the provider
5. FastAPI → Laravel  message.start · status · citations · token… · provider.usage · message.complete
6. Laravel            relays all but provider.usage, flushing after each event
7. Stream ends        FastAPI closes its span; Laravel writes message row + usage row (idempotency key
                      `usage.finalize(message_id)`) + analytics event — in one finally block
```

Laravel's relay, with the cancellation path — the part that is actually easy to get wrong:

```php
// Deliberately response()->stream() and not response()->eventStream(): eventStream() can only emit
// named events, and the mandated `: ping` heartbeat is an SSE *comment*. `event: heartbeat` is not a
// substitute — it is on the never-forward list below. See `laravel-control-plane` for the mechanics.
return response()->stream(function () use ($upstream, $ctx) {
    $finalizer = new StreamFinalizer($ctx);       // idempotent on message_id; safe to reach twice
    // MUST be true. At its default, PHP terminates the script the moment it detects the abort, so the
    // connection_aborted() check below is unreachable, cancel() never runs, and `finally` is skipped —
    // the stream bills tokens and writes no usage row. This one line is the bug in Gotcha 2.
    ignore_user_abort(true);
    try {
        foreach ($upstream->events() as $event) { // generator over the FastAPI SSE stream;
            if ($event === null) {                //   yields null on each idle read timeout
                echo ": ping\n\n";                // an SSE comment — `event: heartbeat` is on the
                ob_flush(); flush();              //   never-forward list and is not a substitute
                if (connection_aborted()) {       // the heartbeat IS the disconnect probe: a silent
                    throw new ClientGoneException();  //   provider means no writes, and with no write
                }                                 //   PHP never notices the client left
                continue;
            }
            if ($event->name === 'provider.usage') {
                $finalizer->recordUsage($event->data);  // internal-only: never forwarded
                continue;
            }
            $finalizer->countToken($event);       // running tally — do NOT wait for provider.usage,
                                                  // a cut stream may never deliver it
            echo "event: {$event->name}\ndata: {$event->json()}\n\n";
            ob_flush(); flush();                  // one flush per event, or nothing streams
            if (connection_aborted()) {           // only ever true AFTER a write attempt — see gotchas
                throw new ClientGoneException();
            }
        }
    } catch (ClientGoneException) {
        $upstream->cancel();                      // closes the internal request → FastAPI sees
                                                  // http.disconnect → provider call is cancelled
        $finalizer->markCancelled();              // finish_reason=cancelled, usage_estimated=true
    } finally {
        $finalizer->commit();                     // message row + usage row + analytics, always
    }
}, 200, [
    'Content-Type'  => 'text/event-stream',
    'Cache-Control' => 'no-cache, no-transform',
    'X-Accel-Buffering' => 'no',
]);
```

Cancellation is a first-class outcome, not an error: `finish_reason: "cancelled"`, usage finalized from the running tally, `user_cancellation` recorded (docs/14 §19.1) — the cancellation-rate metric depends on it (docs/15 §20.2).

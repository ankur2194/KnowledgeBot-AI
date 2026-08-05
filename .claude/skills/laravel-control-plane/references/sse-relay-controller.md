# The SSE relay controller, and why `stream()` rather than `eventStream()`

Companion to `SKILL.md`. `StreamChatMessageController` in full — authorization and config resolution
before the first byte, the abort-owning stream callback, the heartbeat that doubles as the disconnect
probe, the three terminal paths, and the `finally` that cancels upstream and commits usage. Then the
rationale for `response()->stream()` over `response()->eventStream()`. Spec: docs/04-functional-channels-chat.md §8.18–8.24.

```php
// services/core-api/app/Http/Controllers/Api/V1/StreamChatMessageController.php
public function __invoke(SendMessageRequest $request, string $conversationId): StreamedResponse
{
    // Authorize and resolve BEFORE the first byte. Once 200 + headers are on the wire the status
    // line is spent: a later failure can only be an SSE `error` event, never a 4xx.
    $ctx  = $this->gate->authorize($request->actor(), $conversationId);   // the six checks, quota
    $snap = $this->config->resolve($ctx);            // bot + provider + decrypted key + params
    $deadlineMs = (int) round(LARAVEL_START * 1000) + 55_000;
    $fin = $this->finalizer->for($ctx, $snap);       // idempotent on message_id

    return response()->stream(function () use ($ctx, $snap, $deadlineMs, $fin): Generator {
        // Default behaviour is to KILL the script on an aborted connection, and that teardown is a
        // bailout: the finally below would never run and usage would never be written. Owning the
        // abort is what makes finalization reachable.
        ignore_user_abort(true);
        set_time_limit(0);                            // FPM request_terminate_timeout is the ceiling

        $up = null;
        try {
            yield ": open\n\n";                       // first write; arms the abort probe
            $up = $this->ai->openChatStream($snap, $ctx, $deadlineMs);

            // frames() yields a ParsedEvent, or null when a read returned nothing before
            // read_timeout (distinguished from EOF by feof on the resource).
            foreach ($up->frames() as $frame) {
                if ($frame === null || $frame->name === 'heartbeat') {
                    yield ": ping\n\n";               // SSE comment: keeps proxies awake AND is the
                    continue;                         // only way a silent disconnect becomes visible
                }
                if ($frame->name === 'provider.usage') { $fin->recordUsage($frame->data); continue; }
                if (! ClientEvents::allows($frame->name, $ctx->actorType)) { continue; }  // allow-list
                $fin->observe($frame);                // running tally — a cut stream may never
                yield "event: {$frame->name}\ndata: {$frame->json()}\n\n";   // deliver provider.usage
                if (connection_aborted() === 1) { throw new ClientGoneException; }
            }
            $fin->markComplete();
        } catch (ClientGoneException) {
            $fin->markCancelled();                    // finish_reason=cancelled, 499, not an error
        } catch (InternalAiException $e) {            // class assigned by FastAPI, relayed verbatim
            $fin->markFailed($e->errorClass);
            yield "event: error\ndata: ".json_encode([
                'error_class' => $e->errorClass, 'message' => $e->operatorMessage,
                'retryable' => $e->retryable, 'request_id' => $ctx->requestId,
            ])."\n\n";
        } finally {
            $up?->cancel();     // closes the PSR body → FastAPI sees http.disconnect → the provider
                                // call is cancelled. Skip this and it generates billable tokens
                                // nobody will read, for as long as the model wants to talk.
            $fin->commit();     // message row + usage row + analytics, one transaction, always
        }
    }, 200, [
        'Content-Type'      => 'text/event-stream',
        'Cache-Control'     => 'no-cache, no-transform',
        'X-Accel-Buffering' => 'no',
    ]);
}
```

**Why `stream()` and not `eventStream()`.** `response()->eventStream()` is the better default in general — it sets `text/event-stream`, `Cache-Control: no-cache` and `X-Accel-Buffering: no`, flushes per yield, breaks on `connection_aborted()`, and terminates the stream with a `</stream>` frame you suppress via `endStreamWith:`. It cannot emit an SSE **comment**, so `: ping` is unreachable and our heartbeat would have to become a real `event:` — which is on the internal-only, never-forwarded list. Returning a `Generator` from `response()->stream()` keeps the auto-flush and the `X-Accel-Buffering` header while leaving frame bytes ours.

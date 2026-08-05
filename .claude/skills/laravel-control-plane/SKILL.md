---
name: laravel-control-plane
description: The Laravel 13 control plane in services/core-api — app shape, tenant-scoped Eloquent, the signed FastAPI client, and the SSE relay carrying a chat answer to the browser. Use whenever adding a controller, middleware, provider, model scope, or internal AI call, or when a stream buffers, stalls, or bills tokens after the client left. PHP learns the client is gone only when a write fails, so the heartbeat is the disconnect probe. Pairs with kb-internal-api-contracts (wire) and kb-error-taxonomy (retries).
---

# Laravel Control Plane

Laravel **13.x** (released 17 Mar 2026; PHP 8.3–8.5; bug fixes to Q3 2027, security to 17 Mar 2028), PHP 8.4, PostgreSQL, Valkey, PHP-FPM behind Traefik, in `services/core-api/`.
**Authoritative spec:** docs/06-architecture.md §11, docs/12-api-areas.md §17, docs/02-functional-auth-tenancy-bots.md §8.2–8.4, docs/04-functional-channels-chat.md §8.18–8.24

## Non-negotiables

- **Laravel is the only process that may open a connection to `ai-api`, and exactly one class does it.** `InternalAiClient` is the sole caller; a controller, job, or service that builds its own `Http::` call to the AI service bypasses signing, deadline propagation, and the retry ban. Boundary: `kb-architecture-map`. Wire: `kb-internal-api-contracts`.
- **Laravel never retries its chat call to FastAPI.** The provider adapter owns provider retries (`kb-error-taxonomy`, "Retry ownership"). `Http::retry()` on the internal client multiplies attempts across tiers — 3×3 is 9 provider calls from one click. Job *submission* may retry on connect failure only.
- **`X-KB-Deadline` is absolute epoch milliseconds computed from Laravel's own remaining budget**, never a duration and never re-derived downstream. Chat deadline 60 s → internal call 55 s (`kb-error-taxonomy`, timeout budgets).
- **Usage is finalized on every terminal path, including client abort.** A cancelled stream still billed tokens; `finish_reason: "cancelled"` with `usage_estimated` is a normal outcome, not an error (docs/14 §19.1). This is what forces `ignore_user_abort(true)` — see Gotcha 1.
- **The error class FastAPI assigned crosses to the client verbatim.** Laravel never re-derives a class from an HTTP status, and never renders a provider message to an end user (`kb-error-taxonomy`).
- **Every tenant-owned query, cache key, storage path, and queue payload carries the organization** (`kb-tenancy-isolation`). A global scope is the backstop; the explicit scope is the mechanism.
- **A decrypted provider credential exists only inside the request that uses it** — resolved into the signed request body, never into a log line, an API Resource, an audit `details` payload, or an exception message (`kb-security-baseline`).

## How we use it

### Application shape

Laravel 11+ skeleton, unchanged in 13: no `app/Http/Kernel.php`, no `app/Console/Kernel.php`, no `app/Exceptions/Handler.php`. Everything wires through `bootstrap/app.php` — `->withRouting()`, `->withMiddleware()`, `->withExceptions()`, `->withProviders()`. Layering follows the house pattern (`Keyntech-CRM/.claude/skills/laravel-backend`): **Controller → Service → Repository interface → Eloquent**, constructor injection only, FormRequest in, API Resource out, no Eloquent outside a repository. Scaffold with `php artisan make:*`, then move.

```
services/core-api/app/
├── Http/{Controllers/Api/V1,Requests,Resources,Middleware}
├── Services/                # use cases: ChatGate, ConfigSnapshotResolver, StreamFinalizer
├── Services/Internal/       # InternalAiClient + UpstreamStream — the only FastAPI callers
├── Repositories/{Contracts,Eloquent} · Models/{,Scopes} · Providers/
└── Support/Tenancy/         # TenantContext, resolved per request, cleared in a finally
```

Every exception renders through **one** `render` closure registered in `withExceptions()`, producing the single envelope `{"error_class","message","retryable","request_id"}` that `kb-internal-api-contracts` defines for both the SSE `error` event and the non-streaming body. Status comes from the `error_class` → status map, not from the exception type — including the 403-admin / **404-public** split (`kb-error-taxonomy` footnote 1, `laravel-rbac-policies`).

### Where the tenant scope is enforced

Two layers, and each fails differently:

- **`#[ScopedBy(OrganizationScope::class)]`** on every org-owned model. Catches the query you forgot. Fails silently for `DB::table()`, `DB::select()`, raw SQL, and any `withoutGlobalScopes()` — none of which touch the Eloquent builder. CI greps for all three (`kb-tenancy-isolation`, DoD).
- **An explicit `forOrg($ctx->orgId)` on every repository method**, required and positional. Catches the queue worker whose container-resolved `TenantContext` is stale or empty — the global scope reads that same context, so on that path both layers fail together unless the argument is explicit.

The global scope reads `TenantContext`, set from the authenticated session or token and **never from request input**. Jobs carry `organization_id` in the payload, set the context on entry and clear it in a `finally` — a pooled worker retaining the previous tenant is the silent failure (`kb-tenancy-isolation`, the CVE-2023-28859 shape).

### Calling FastAPI, and relaying the stream

The whole path, in the two classes that own it. This is the hardest thing Laravel does here.

```php
// services/core-api/app/Services/Internal/InternalAiClient.php — the only caller of ai-api.
public function openChatStream(ConfigSnapshot $snap, ChatContext $ctx, int $deadlineMs): UpstreamStream
{
    // Serialize ONCE and sign those exact bytes. Re-encoding JSON to hash it is not
    // byte-stable and produces intermittent 401s (kb-internal-api-contracts).
    $body = json_encode($snap->toArray(), JSON_THROW_ON_ERROR);
    $ts   = (string) time();

    // Build the X-KB-* set ONCE and derive both the signature and the request from it.
    // Never hand-write the canonical string from a second literal list: the signed set and
    // the sent set drift the moment someone adds a header, and the failure is a 401 on a
    // request that looks correct in the log.
    $kb = [
        'X-KB-Org-Id'          => $ctx->orgId,        // never defaulted, never from the body
        'X-KB-Bot-Id'          => $ctx->botId,
        'X-KB-Actor-Type'      => $ctx->actorType,
        'X-KB-Operation'       => 'chat.execute',
        'X-KB-Request-Id'      => $ctx->requestId,    // also the replay nonce
        'X-KB-Config-Version'  => (string) $snap->version,
        'X-KB-Contract-Version'=> 'v1',
        'X-KB-Deadline'        => (string) $deadlineMs,   // ABSOLUTE epoch ms
        'X-KB-Idempotency-Key' => $ctx->idempotencyKey,
        'X-KB-Timestamp'       => $ts,
    ];

    // The header block is NOT optional. Method + path + timestamp + body alone leaves the
    // request forgeable: X-KB-Org-Id is the tenant scope for the entire data plane, so an
    // unsigned org header means a legitimately signed request can be replayed against
    // another organization by flipping one value (kb-internal-api-contracts).
    $canonical = "KB1\nPOST\n/internal/v1/chat/stream\n{$ts}\n".hash('sha256', $body)."\n";
    $names = array_map('strtolower', array_keys($kb));    // lowercased name, trimmed value
    $lines = array_map(fn ($n, $v) => $n.':'.trim($v), $names, array_values($kb));
    sort($lines, SORT_STRING);                            // byte order — the verifier sorts too
    $canonical .= implode("\n", $lines);

    $keyId = config('services.ai.hmac.active');           // two ids live during rotation
    $sig   = $keyId.':'.hash_hmac('sha256', $canonical, config("services.ai.hmac.keys.{$keyId}"));

    $response = Http::baseUrl(config('services.ai.url'))
        ->withBody($body, 'application/json')             // exactly the bytes hashed above
        ->withHeaders($kb + [
            'Accept'         => 'text/event-stream',      // not an X-KB-* header, not signed
            'X-KB-Signature' => $sig,                     // redacted from every log line
        ])
        ->connectTimeout(3)->timeout(55)
        // stream => true routes the request through Guzzle's StreamHandler and returns as soon as
        // headers land. read_timeout is documented to default to default_socket_timeout and in fact
        // defaults to nothing (guzzle#2783) — unset, a dead upstream blocks this worker forever.
        ->withOptions(['stream' => true, 'read_timeout' => 20])
        // NO ->retry(): retry ownership is the provider adapter's (kb-error-taxonomy).
        ->post('/internal/v1/chat/stream')->throw();

    return new UpstreamStream($response->resource());     // PHP stream resource over the SSE body
}
```

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

### Long-running requests under PHP-FPM

A streamed answer occupies **one FPM child for its entire life** — 20 s of retrieval and generation is 20 s of one slot in `pm.max_children`. Size for concurrent *streams*, not requests per second, and give chat its own pool: a burst of streams on a shared pool starves every admin request behind it.

`max_execution_time` will not save you and is not a bound here: it counts script execution only — *"Any time spent on activity that happens outside the execution of the script such as system calls…, stream operations, database queries, etc. is not included"* — so a worker blocked reading the upstream socket ages forever without tripping it. The real ceiling is FPM's `request_terminate_timeout`, which is **`0` (off) by default** and kills the child outright when set: set it above the 60 s chat deadline (90 s), never below, or it becomes a mid-answer SIGTERM with no finalization.

### Owned elsewhere — cite, never restate

Sanctum tokens, sessions, widget session tokens → `laravel-sanctum-auth`. Policies, gates, the permission catalog, the 403/404 deny split → `laravel-rbac-policies`. Queue connections, workers, job retries → `laravel-queues-valkey`. `routes/console.php` and recrawl scheduling → `laravel-scheduler`. Migrations, indexes, JSONB → `postgresql-patterns`. Pest/PHPUnit conventions → `pest-testing`. Header table, signing canonical string, event schema → `kb-internal-api-contracts`. Retry, timeout and breaker policy → `kb-error-taxonomy`.

## Gotchas

- **A user closes the tab, tokens keep billing, and no `usage` row is ever written — for streams that ended normally too.** Two compounding causes. PHP *"will not detect that the user has aborted the connection until an attempt is made to send information to the client"*, so a relay blocked on an upstream read learns nothing for as long as the model is thinking — the heartbeat is the disconnect probe, not merely proxy keepalive. And with `ignore_user_abort` at its default, the abort PHP does eventually notice **terminates the script through a bailout that skips `finally`** <!-- UNVERIFIED: bailout-skips-finally confirmed from secondary sources, not php.net; register_shutdown_function is documented as the exception -->, so the finalizer never commits. `ignore_user_abort(true)` + explicit `connection_aborted()` checks after each write, or belt-and-braces `register_shutdown_function`.
- **`Http::fake()` makes every streaming bug pass.** A faked body is a string, so the relay drains it in microseconds: buffering, event ordering, heartbeat cadence, read timeouts and disconnect handling are all untestable, and `resource()` over a string stream never blocks. The relay test must run against a real SSE fixture server that delays between events and can hang up mid-stream (`kb-internal-api-contracts`, contract tests; `pest-testing`).
- **The stream works in `curl` and arrives as one blob in the browser.** PHP's `output_buffering` (4096 in `php.ini-production`) or `zlib.output_compression`, a Traefik `compress` middleware that has not excluded `text/event-stream`, or a middleware that calls `$response->getContent()`. A `Generator` handed to `stream()` flushes for you; a plain closure needs `ob_flush(); flush();` per event. Never open an `ob_start()` inside the callback — a nested buffer swallows every flush, which is how a debug bar turns a stream into a blob.
- **A streamed answer stalls forever and one FPM child never returns.** Guzzle's `read_timeout` is documented to default to `default_socket_timeout` and is in fact never set (guzzle#2783), and with `'stream' => true` Guzzle returns once headers arrive so `Http::timeout()` does not bound the body read. <!-- UNVERIFIED: the exact scope of Guzzle's `timeout` under StreamHandler was not re-read from source --> Set `read_timeout` explicitly, below the FastAPI heartbeat interval times two.
- **Usage is short by the whole answer even though the stream completed.** Finalization waited for the terminal `provider.usage` event. Streaming usage is opt-in or last-message-only depending on provider (`kb-provider-adapter-contract`), and a cut stream never delivers it. Tally incrementally, commit in `finally`, key the write on `message_id` so the abort path and the happy path cannot double-write.
- **The widget POST 419s in production and works in local dev — or the reverse.** Laravel 13 replaced `VerifyCsrfToken` with `PreventRequestForgery`, which checks `Sec-Fetch-Site` first and only falls back to token validation when origin verification is unavailable. That header *is only sent over HTTPS*, so plain-HTTP dev silently takes the token path and HTTPS prod takes the origin path — different code, different verdict. Public runtime, SDK, and mobile routes are bearer-token authenticated and live **outside** the `web` group entirely: no session, no cookie, no ambient authority, no CSRF question.
- **A stream route sits behind session middleware and a second tab hangs.** Common advice says call `Session::save()` first because native PHP sessions hold an exclusive lock for the request. Laravel does not use native sessions — `FileSessionHandler` takes no request-long lock (`sharedGet` on read, locked `put` on write), so the classic block does not occur. The real defect is that session middleware has no business on a token-authenticated stream route at all; `StartSession` writes on `terminate()`, i.e. after the stream closes, which is exactly the wrong ordering for anything you wanted persisted mid-answer.
- **FastAPI's spans show up as their own root trace.** `opentelemetry-auto-laravel` does **not** instrument the HTTP client; `traceparent` rides out only because `opentelemetry-auto-guzzle` hooks `GuzzleHttp\Client::transfer()`. Miss that package and every internal call is unparented, with no error anywhere. Details and the assertion to write: `kb-observability-conventions`.
- **`abort_unless($x->organization_id === $orgId, 403)` on a public route is an enumeration oracle.** On the public runtime and SDK surfaces a foreign identifier must 404; the `error_class` stays `authorization` either way and nothing branches on the status (`kb-error-taxonomy`, `laravel-rbac-policies`).
- **Laravel 13 ships an AI SDK, `Str::toEmbeddings()`, and `DB::whereVectorSimilarTo()`.** None of them are used here. Provider calls, embedding, and retrieval belong to the data plane (ADR-001, ADR-003, ADR-005); a control-plane shortcut into any of the three puts provider credentials, token spend, and an unfiltered similarity query outside every guarantee this architecture makes. `kb-tenancy-isolation` has no way to reach a query the control plane issued directly against pgvector.
- **A callback from FastAPI rewinds a job.** Progress callbacks arrive out of order after a Celery retry; the `UPDATE` must be guarded `WHERE sequence > progress_sequence`, or a `ready` source flips back to `processing` and an already-published version leaves retrieval (`kb-internal-api-contracts`).

## Official docs

- [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) — support window, PHP 8.3 floor, `PreventRequestForgery`, controller attributes, `Queue::route`.
- [Laravel — streamed responses & event streams](https://laravel.com/docs/13.x/responses#streamed-responses) — `stream()`, generator auto-flush, `eventStream()`, `StreamedEvent`, `endStreamWith`.
- [Laravel — HTTP client](https://laravel.com/docs/13.x/http-client) (`timeout`/`connectTimeout` defaults, `withOptions`, `Http::fake()`) and [Eloquent query scopes](https://laravel.com/docs/13.x/eloquent#query-scopes) (`#[ScopedBy]`, `#[Scope]`, `withoutGlobalScopes`).
- [Laravel — error handling](https://laravel.com/docs/13.x/errors) and [CSRF protection](https://laravel.com/docs/13.x/csrf) — `withExceptions()` render/report/level, `Sec-Fetch-Site` origin verification.
- [PHP — connection handling](https://www.php.net/manual/en/features.connection-handling.php) and [`ignore_user_abort`](https://www.php.net/manual/en/function.ignore-user-abort.php) — abort detection requires a write attempt.
- [PHP — `set_time_limit`](https://www.php.net/manual/en/function.set-time-limit.php) and [FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php) — stream time is not counted; `request_terminate_timeout`, `pm.max_children`.
- [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html) — `stream`, `read_timeout`, `timeout`, `connect_timeout`.

## Definition of done

- [ ] `grep -rn "ai-api\|services.ai.url" services/core-api/app | grep -v Services/Internal` is empty — one caller only, and it carries every header in the `kb-internal-api-contracts` table.
- [ ] No `->retry(` anywhere on the internal chat client; `X-KB-Deadline` is absolute epoch ms derived from `LARAVEL_START`, asserted by a test.
- [ ] The signed bytes are the sent bytes: one `json_encode`, hashed and passed to `withBody()`; a round-trip test against the FastAPI verifier passes, and a replayed `X-KB-Request-Id` returns 401.
- [ ] Streaming test runs against a real SSE fixture server (never `Http::fake()`) and asserts: inter-event wall-clock gaps, a `: ping` at least every 20 s, exactly one terminal event, and a `usage` row written after a mid-stream client hangup.
- [ ] `ignore_user_abort(true)` is set in every stream callback and the finalizer is idempotent on `message_id`; a test proves the abort path and the completion path together write exactly one usage row.
- [ ] Upstream cancellation is asserted: after a simulated client hangup the fixture server observes the connection closed within one event interval.
- [ ] Every org-owned model carries `#[ScopedBy(OrganizationScope::class)]` **and** every repository method takes an explicit org argument; CI greps for `withoutGlobalScopes(`, `DB::table(`, `DB::select(`.
- [ ] One `render` closure produces the `{error_class, message, retryable, request_id}` envelope; a test asserts a foreign identifier returns 403 on admin routes and 404 on public/SDK routes with the same `error_class`.
- [ ] Public runtime, SDK, and mobile routes are outside the `web` group (no session, no `PreventRequestForgery`); asserted by a route-list test.
- [ ] FPM: chat has its own pool, `request_terminate_timeout` ≥ 90 s, and `pm.max_children` is sized against peak concurrent streams, not RPS.
- [ ] `./vendor/bin/pint` clean and `./vendor/bin/phpstan analyse` passes at the configured level.

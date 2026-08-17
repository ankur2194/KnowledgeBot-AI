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
- **A decrypted provider credential exists only inside the request that uses it**, and it goes into the signed body as an entry in the **top-level `provider_credentials` map — never merged into the configuration snapshot** (docs/22 decision 1, resolved as ADR-011; the map shape is finding F12, ruled 2026-08-12 as `docs/22` § G7). The map is keyed by `connection_id` and **one turn can need three entries** — chat, embedding, rerank — so a turn whose surfaces resolve to different connections decrypts each one separately. Laravel is the only process that decrypts; no value reaches a log line, an API Resource, an audit `details` payload, or an exception message (`kb-security-baseline`, `kb-internal-api-contracts`).
- **Laravel 13's first-party AI APIs are out of bounds in `services/core-api`** — the AI SDK, `Str::toEmbeddings()`, and `DB::whereVectorSimilarTo()`. ADR-001/003/005 are **reaffirmed** against them (docs/22 decision 7, resolved), and the reason is **custody, not API quality**, so a good release note is not grounds to reopen it per-feature. The AI SDK would put provider credentials, spend accounting, and token-usage normalization inside the control plane and outside `kb-provider-adapter-contract`: a call made that way appears in no `provider_calls` row, no `usage_events` row, and no quota check. `Str::toEmbeddings()` is that same provider call wearing a string helper's clothes, with the identical accounting gap. `DB::whereVectorSimilarTo()` is the serious one — a **second retrieval path carrying none of the four mandatory Qdrant filters**, no rerank, and no evidence threshold; one such query in a controller is a cross-tenant read that reviews as one line of ORM (`kb-tenancy-isolation`). Revisit only if Qdrant is dropped in favour of pgvector as the vector store, and even then the AI SDK stays out: credential custody is a separate argument from where vectors live.

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
    //
    // The credential is decrypted HERE, into a local that dies with this method, and attached
    // as a TOP-LEVEL field beside `config` — never merged into the snapshot (docs/22 decision 1).
    // Inside the snapshot it would be hashed into $snap->version, so every rotation would move
    // configuration_version and invalidate every cached answer; and every path that persists a
    // snapshot — playground record, retrieval trace, queued job body — would persist a plaintext
    // key with it, into a column nobody redacts (kb-security-baseline).
    // A MAP, KEYED BY connection_id — one turn can need chat, embedding and rerank keys, and
    // ADR-031 explicitly permits those to be different connections (docs/22 § G7). Decrypt each
    // one at the point it enters the map; never build an intermediate array of plaintext keys.
    $body = json_encode($snap->toArray() + [
        'provider_credentials' => array_map(
            fn (string $id): string => $this->credentials->decryptFor($id),
            $snap->connectionIdsInPlay(),   // chat + embedding + rerank, deduplicated
        ),
    ], JSON_THROW_ON_ERROR);
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
    // KB1 absorbed defect 13's header coverage before anything was deployed, so the prefix stays.
    // It bumps only for a canonical-string change made after a release — and the verifier accepts
    // both prefixes for one window while this signer emits one (kb-internal-api-contracts).
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

**The relay controller lives in full in [`references/sse-relay-controller.md`](references/sse-relay-controller.md)**: `StreamChatMessageController` (authorize and resolve the config snapshot before the first byte, `ignore_user_abort(true)` and `set_time_limit(0)`, the `: ping` heartbeat that is also the disconnect probe, the client-allow-list filter, the three terminal paths — complete, cancelled, error relayed verbatim — and the `finally` that cancels the upstream body and commits usage), plus **why `stream()` and not `eventStream()`** (`eventStream()` cannot emit an SSE comment, so `: ping` would have to become a forwarded event). Read it before changing anything in the callback.

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
- **A tenant's provider bill exceeds the sum of their `usage_events` rows — or one bot answers out of another organization's documents.** Someone reached for Laravel 13's first-party AI, embedding, or vector-search APIs from a controller or a job. Neither failure raises anything: a call through the AI SDK or `Str::toEmbeddings()` writes no `provider_calls` row, no `usage_events` row, and passes no quota check, so the spend is invisible to accounting rather than wrong; `DB::whereVectorSimilarTo()` returns rows perfectly happily with no organization filter, because the tenant filter lives in the Qdrant query builder and pgvector never sees it. Both are decided boundaries, not judgement calls — see the non-negotiable above for the reasoning and the one condition that reopens it.
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
- [ ] The signed bytes are the sent bytes: one `json_encode`, hashed and passed to `withBody()`; a round-trip test against the FastAPI verifier passes, and a replayed `X-KB-Request-Id` returns 401. The canonical prefix is `KB1`.
- [ ] `rg -n 'provider_credentials' services/core-api/app` shows exactly one construction site, in `InternalAiClient`, at the top level of the body and not inside the snapshot; a test rotates only one credential and asserts `X-KB-Config-Version` and `X-KB-Idempotency-Key` are unchanged, and that **no value in the map** appears in a log line, API Resource, audit `details`, or exception message. **The fixture carries more than one entry and the assertion iterates the values** — an assertion naming one field, or a single-entry fixture, passes vacuously against a map and leaves the second and third keys untested (`docs/22` § G7).
- [ ] `rg -n 'toEmbeddings\(|whereVectorSimilarTo\(|use\s+(Illuminate|Laravel)\\(AI|Ai)\\' services/core-api/app` returns nothing, and the same grep runs as a required CI check alongside the tenancy greps. **Scoped to `app`, like the `ai-api` grep above, and not to `services/core-api/`** — Laravel 13 ships `toEmbeddings(` and `whereVectorSimilarTo(` in `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php`, so the unscoped form matches 5 vendored lines (measured 2026-08-11) under every tool that does not honour `.gitignore`: `/usr/bin/grep -r` and `rg --no-ignore`. A checkbox that fails correct code is a checkbox reviewers learn to tick anyway. If this is ever automated again, carry `--exclude-dir=vendor`, which the deleted tenancy escape-hatch gate did for exactly this reason: `vendor/` is gitignored, so the failure is invisible to a CI checkout and reproducible for every developer — the worst possible split. <!-- UNVERIFIED: the exact namespace Laravel 13 ships its AI SDK under was not confirmed against the release; widen the third alternative to whatever `composer show` reports rather than narrowing it -->
- [ ] `services/core-api/database/migrations/` creates no `vector` extension and no vector column, and no repository issues a similarity query; retrieval is reachable from Laravel only through `InternalAiClient` (`kb-architecture-map`).
- [ ] Streaming test runs against a real SSE fixture server (never `Http::fake()`) and asserts: inter-event wall-clock gaps, a `: ping` at least every 20 s, exactly one terminal event, and a `usage` row written after a mid-stream client hangup.
- [ ] `ignore_user_abort(true)` is set in every stream callback and the finalizer is idempotent on `message_id`; a test proves the abort path and the completion path together write exactly one usage row.
- [ ] Upstream cancellation is asserted: after a simulated client hangup the fixture server observes the connection closed within one event interval.
- [ ] Every org-owned model carries `#[ScopedBy(OrganizationScope::class)]` **and** every repository method takes an explicit org argument; CI greps for `withoutGlobalScopes(`, `DB::table(`, `DB::select(`.
- [ ] One `render` closure produces the `{error_class, message, retryable, request_id}` envelope; a test asserts a foreign identifier returns 403 on admin routes and 404 on public/SDK routes with the same `error_class`.
- [ ] Public runtime, SDK, and mobile routes are outside the `web` group (no session, no `PreventRequestForgery`); asserted by a route-list test.
- [ ] FPM: chat has its own pool, `request_terminate_timeout` ≥ 90 s, and `pm.max_children` is sized against peak concurrent streams, not RPS.
- [ ] `./vendor/bin/pint` clean and `./vendor/bin/phpstan analyse` passes at the configured level.

---
name: kb-internal-api-contracts
description: The Laravel↔FastAPI seam — the signed internal transport, the metadata every internal request carries, the sync/async split, and the normalized SSE event schema clients receive. Use whenever adding or changing an internal endpoint, a streaming event, a job callback, or an idempotency key in services/core-api/ or services/ai-service/. Browser, widget, and mobile clients never call FastAPI; this skill owns that boundary. Pairs with kb-error-taxonomy (the classes it carries) and fastapi-service (routing).
---

# Internal API Contracts — Laravel ↔ FastAPI

Laravel control plane (`services/core-api`) ↔ FastAPI AI data plane (`services/ai-service`), HTTP/1.1 over the private `application` Docker network. Internal contract version `v1`, path prefix `/internal/v1`. The spec pins no framework versions; the runtime skills do — Laravel **13.x** (`laravel-control-plane`) and FastAPI **0.141.1** / Starlette **1.3.1** (`fastapi-service`). This contract is deliberately version-independent of both: it is a wire shape, and a framework upgrade that changes it is a breaking change to `v1`.
**Authoritative spec:** docs/06-architecture.md §11, docs/12-api-areas.md §17, docs/14-reliability.md §19.5, docs/17-testing-performance.md §22.2, docs/08-ingestion-pipeline.md §13.3

## Non-negotiables

- **No client ever reaches FastAPI.** Next.js, the Preact widget, and the React Native app speak only to Laravel over HTTPS (docs/06 §11.1). That single rule is what buys centralized authentication, centralized authorization, one rate-limit surface, provider credentials that never leave the server, a hidden internal topology, and versionable public APIs. `ai-api` joins `application` and `data` only — never `edge`, never a Traefik router label, never a host `ports:` mapping.
- **Every internal request carries the full metadata header set below.** A request without `X-KB-Org-Id` is rejected `400` at the FastAPI dependency, never defaulted and never inferred from the body — FastAPI cannot build a tenant-safe Qdrant filter without it (`kb-tenancy-isolation`).
- **Contracts are versioned from day one.** `/internal/v1/...`, mirrored by `X-KB-Contract-Version`. A shipped shape is never silently changed; add a field or add `/internal/v2`. Public APIs are versioned independently (docs/12 §17) — the two version numbers move at different rates and must not be conflated.
- **Every mutation carries an idempotency key whose fingerprint includes the configuration version.** A key derived from content alone silently swallows a legitimate reprocess (docs/08 §13.3).
- **Error classes and retry eligibility cross the wire verbatim.** Laravel relays the class FastAPI assigned; it never re-derives one from an HTTP status. `kb-error-taxonomy` defines the classes and the retry policy.
- **HTTP contracts are OpenAPI documents in `packages/contracts/`, and both sides are covered by contract tests** (docs/17 §22.2). The FastAPI-generated schema is exported into that package; Laravel's client is validated against it in CI.

## How we use it

### Boundaries — named, not redefined here

- Provider request/response translation → `kb-provider-adapter-contract`.
- Error classes, retry policy, circuit breakers → `kb-error-taxonomy` (we carry them across the wire; we do not define them).
- Tenant filtering and scoping → `kb-tenancy-isolation`.
- FastAPI routers, dependencies, Pydantic models → `fastapi-service`.
- Laravel controllers, HTTP client configuration, queue wiring → `laravel-control-plane`.

### Transport and service authentication

Plaintext HTTP on the private network; **HMAC-SHA256 signed requests**, not bare bearer tokens and not mTLS. The network already gives confidentiality-by-isolation; the signature gives per-request integrity and a replay window, at zero certificate-rotation cost. Both directions are signed — FastAPI's progress callbacks into Laravel use their own key id.

```
canonical = "KB1\n" + METHOD + "\n" + PATH + "\n" + X-KB-Timestamp + "\n" + sha256_hex(raw_body) + "\n"
          + "\n".join(f"{k}:{v}" for k, v in sorted(kb_headers))   # every X-KB-* header except the signature,
                                                                   # name lowercased, value stripped
X-KB-Signature = key_id + ":" + hex(hmac_sha256(secret[key_id], canonical))
```

**The `X-KB-*` headers are in the canonical string because `X-KB-Org-Id` is the tenant scope for the entire data plane.** A signature over method, path, timestamp and body alone leaves it forgeable: flip one header and a legitimately signed request executes against another organization, which defeats every downstream layer at once — `kb-tenancy-isolation` builds its filter from `ctx.org_id` precisely *because* it arrives signed. The verifier recomputes the set from **all `X-KB-*` headers actually present** rather than from a caller-supplied signed-headers list; a list is itself attacker-controlled, so anything omitted from it can be added or dropped freely. Adding, removing, or altering any `X-KB-*` header therefore breaks the signature by construction.

- Reject when `|now − timestamp| > 60s`, and reject a repeated `X-KB-Request-Id` seen inside `120s` (Valkey `SET NX EX 120`). Skew tolerance and replay TTL must be set together — a wide window with no nonce is not a replay defence.
- Compare with `hash_equals()` / `hmac.compare_digest()`. Never `===`/`==` — a byte-wise compare leaks the signature one byte at a time.
- Method and path are in the canonical string on purpose: a body-only signature is replayable against a *different* endpoint that accepts the same body.
- Sign the **exact bytes sent**. Serialize once into a string, hash that string, send that string. Re-encoding JSON to hash it is not byte-stable and produces intermittent 401s.
- Request bodies are always fully buffered and small — only *responses* stream — so body signing never conflicts with SSE. HMAC gives integrity and authenticity, not confidentiality; that comes from the network being private. If the seam ever spans hosts, add TLS underneath rather than changing the signing scheme.
- Two key ids are live at once during rotation; verifiers accept both, signers use the newer. `key_id` in the signature is what makes that possible.

### Required internal request headers

| Header | When | Value and rule |
|---|---|---|
| `traceparent` | always | W3C Trace Context, injected by the OTel SDK. One trace spans client → Laravel → FastAPI → provider (docs/15 §20.1). |
| `X-KB-Request-Id` | always | ULID, one per inbound client request. Doubles as the replay nonce. Logged by both services (docs/15 §20.3). |
| `X-KB-Org-Id` | always | Organization ULID. Absent → `400`. Never defaulted, never read from the body. |
| `X-KB-Bot-Id` | bot-scoped ops | Chat, retrieval diagnostics, ingestion, crawl, deletion, evaluation. Absent on `provider.test` and health. |
| `X-KB-Actor-Id` | human-initiated | Admin user ULID. Absent for anonymous widget sessions and scheduler-initiated work. |
| `X-KB-Actor-Type` | always | `user` \| `anonymous_session` \| `scheduler` \| `system`. Drives what the diagnostics contracts may return. |
| `X-KB-Operation` | always | `<group>.<op>` — `chat.execute`, `ingestion.submit`, `deletion.submit`. Metric labels, rate-limit buckets, and log fields key off this, not the URL path. |
| `X-KB-Config-Version` | always | Monotonic integer of the resolved configuration snapshot sent in the body. |
| `X-KB-Idempotency-Key` | every mutation | See below. Absent on a mutation → `400`. |
| `X-KB-Contract-Version` | always | `v1`. Must match the path prefix; a mismatch is `409`, not a best-effort guess. |
| `X-KB-Deadline` | always | **Absolute** Unix epoch milliseconds at which the caller stops caring, not a duration. Set by Laravel from its own remaining budget. FastAPI checks the remainder before each provider attempt and never constructs a fresh duration downstream. Timeout budgets and the nesting arithmetic: `kb-error-taxonomy`. |
| `X-KB-Timestamp`, `X-KB-Signature` | always | Above. `X-KB-Signature` is redacted from every log line. |

**Configuration snapshot vs version.** Laravel resolves bot settings, provider connection, model, pipeline parameters and pinned model versions, and sends the **whole snapshot in the request body**; the header carries only its version. FastAPI never queries Laravel's tables and never caches config across requests — the snapshot is the input, so a replayed job reproduces byte-identically and the playground can run a temporary override without mutating anything (docs/04 §8.24).

### Idempotency keys

`X-KB-Idempotency-Key = sha256(org_id | operation | fingerprint)` where the fingerprint is operation-specific:

| Operation | Fingerprint components |
|---|---|
| `ingestion.submit` | source id, source version id, content hash, parser config version, chunking config version, embedding model version (docs/08 §13.3) |
| `crawl.submit` | source id, crawl run id, normalized root URL, include/exclude rule version |
| `deletion.submit` | source id, version id set, deletion mode |
| `evaluation.run` | dataset id, dataset revision, bot config version |
| `chat.message` / `usage.finalize` | conversation id + client message id / message id |

FastAPI stores `key → {method, path, body_hash, status, response}` in Valkey for 24h (Stripe's floor). Same key + same method/path/body hash → replay the stored response verbatim, including its status. Same key + **different** body → `422`; key still in flight → `409` with `Retry-After` (IETF `Idempotency-Key` draft §2.6–2.7). Storing method and path is what stops a key minted for one endpoint being replayed against another.

### Synchronous vs asynchronous

| Contract group | Mode | Endpoint | Result path |
|---|---|---|---|
| Chat request + streaming events | sync, SSE | `POST /internal/v1/chat/stream` | streamed to Laravel, relayed to client |
| Provider configuration reference | sync | `POST /internal/v1/providers/test` | response body |
| Retrieval request + result | sync | `POST /internal/v1/retrieval/diagnose` | response body (playground/admin only) |
| Ingestion command + progress events | async | `POST /internal/v1/ingestion/jobs` | `202 {job_id}` + progress callbacks |
| Crawl command + page result | async | `POST /internal/v1/crawl/jobs` | `202 {job_id}` + page callbacks |
| Deletion command + verification result | async | `POST /internal/v1/deletion/jobs` | `202 {job_id}` + verification callback |
| Evaluation command + metric result | async | `POST /internal/v1/evaluation/runs` | `202 {run_id}` + metric callback |

Async jobs call back into `POST /internal/v1/callbacks/{group}` on Laravel — **Laravel never polls FastAPI**. Every callback carries `(job_id, sequence, stage, status)`; Laravel applies it under `WHERE sequence > progress_sequence` so a Celery retry cannot rewind the job. A nightly reconciliation sweep re-queries jobs that have gone silent past their timeout; that is the only place a poll is legal.

### Client-facing SSE event schema

FastAPI emits normalized events; Laravel forwards an **approved subset** to the client. This is the contract every client codes against — widget, hosted chat, mobile, and playground all parse exactly this.

```
event: message.start      data: {"message_id":"01J…","conversation_id":"01J…","created_at":"2026-08-04T09:15:02Z"}
event: status             data: {"stage":"retrieving"|"reranking"|"generating"}
event: citations          data: {"citations":[{"index":1,"source_id":"01J…","source_version_id":"01J…",
                                  "chunk_id":"01J…","title":"Refund policy","url":null,"score":0.83}]}
event: token              data: {"text":"Refunds are "}
event: message.complete   data: {"message_id":"01J…","finish_reason":"stop"|"length"|"cancelled"
                                  |"insufficient_evidence"|"error","usage":{"prompt_tokens":1841,"completion_tokens":96}}
event: error              data: {"error_class":"provider_rate_limit","message":"…","retryable":true}
: ping
```

- `citations` is emitted **before the first `token`** — citations are assigned from retrieved evidence pre-generation, never parsed out of model output (docs/07 §12.16–12.17).
- `token` carries text and nothing else; it is the only high-frequency event and every extra key is paid per token.
- `id:` is a per-stream sequence for gap detection only — **token streams are not resumable** (see gotchas). `: ping` is an SSE comment, not an event: it keeps proxies awake without firing `onmessage`.
- Exactly one terminal event per stream: `message.complete` or `error`. Never both, never neither.

**The non-streaming HTTP error envelope is the same shape**, so one parser serves both surfaces and one discriminator drives every retry decision:

```json
{"error_class": "provider_rate_limit", "message": "…", "retryable": true, "request_id": "01J…"}
```

`error_class` is the only field callers branch on — never the HTTP status, which varies by surface for one class (`kb-error-taxonomy`, the 403/404 footnote). `message` is operator-facing and safe to log but never rendered verbatim to an end user. `request_id` echoes `X-KB-Request-Id` so a failure is greppable across both services.

**`validation` is the one class that adds a field, and it adds it as a superset**, so a single parser still serves every error:

```json
{"error_class": "validation", "message": "…", "retryable": false, "request_id": "01J…",
 "errors": {"name": ["The name may not be greater than 120 characters."]}}
```

`errors` is `Record<string, string[]>` keyed by input field name, present **only** on `validation`, and absent everywhere else — never `null`, never `{}`. Without it a 422 cannot be rendered against the field that caused it, which is the difference between a form that explains itself and one where Save silently does nothing (`rhf-zod-forms`). A key that matches no rendered field must still surface somewhere; an error nobody can display is an infinite retry loop the user drives by hand.

**Internal-only events Laravel consumes and does not forward:** `provider.usage` (token counts and cost → `usage` table), `provider.fallback` (a fallback model ran → analytics), `retrieval.trace` (candidate and reranker scores → returned only to the playground when `X-KB-Actor-Type=user` and the actor holds the diagnostics permission), `heartbeat`. Leaking any of these to a widget exposes internal topology and cost data.

### Worked example — one streamed answer, including cancellation

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

## Gotchas

- **The stream hangs, then the whole answer lands at once.** Something buffered it. Each of these defeats streaming independently and none of them errors: a Traefik `buffering` middleware attached to the chat router (Traefik does not buffer unless you ask it to — so this is always something someone added); a `compress` middleware gzipping `text/event-stream` (gzip withholds bytes until it has a worthwhile block — exclude that content type); PHP `output_buffering`/`zlib.output_compression` left on; a missing per-event `ob_flush(); flush()`; a missing `X-Accel-Buffering: no` if any nginx sits in the path. `response()->eventStream()` fixes the last two for you and is the reason to prefer it. CI never catches any of this because test clients read the full body — assert *inter-event wall-clock gaps*, not just the final payload.
- **Usage is never finalized because the client vanished.** Three compounding traps. (1) A browser closing an `EventSource` sends nothing; PHP learns of it only when a *write fails*, so `connection_aborted()` stays `0` through an arbitrarily long silent wait — the heartbeat is what makes disconnect detectable at all, not just what keeps proxies awake. (2) Unless Laravel explicitly cancels the upstream request, FastAPI never sees `http.disconnect` and the provider keeps generating billable tokens nobody will read. Starlette only emits `http.disconnect` for a `StreamingResponse`, and polling `request.is_disconnected()` is flaky. Do **not** hand-roll the receive-channel watch: Starlette's `StreamingResponse` already runs that task group, and FastAPI's native SSE path adds the cancellation checkpoints and the 15 s heartbeat on top — reimplementing it duplicates the machinery and loses the checkpoints (`fastapi-service`). What this contract requires of you is to let the resulting `CancelledError` propagate into the provider client rather than swallowing it. (3) Do not make finalization depend on the provider's terminal usage event: streaming usage is opt-in or last-message-only depending on provider (`kb-provider-adapter-contract`), so a cut stream may never deliver it. Tally incrementally, commit in `finally`, key the write on `message_id` so the abort path and the normal path cannot double-write.
- **A retried ingestion submission creates a duplicate job — or worse, silently skips a needed one.** If the idempotency fingerprint omits the parser/chunking/embedding config versions, a reprocess triggered *by a config change* dedupes against the original job and returns its stale `job_id`: no error, no new version, retrieval quality quietly regresses. Include every component in docs/08 §13.3. The mirror-image bug is a retry wrapper that mutates the body (a timestamp, a re-resolved config) while reusing the key — that must be `422`, never a merge, which is why the stored record keeps a body hash.
- **The internal API is reachable from the internet.** Three ways in, all one line each: `ai-api` added to the `edge` network "temporarily"; a `traefik.enable=true` default at the Compose level with no per-service `traefik.enable=false`; or `ports:` instead of `expose:` on `ai-api`, which publishes to the host and bypasses Docker networks entirely. Assert it in CI: an external `curl https://<public-host>/internal/v1/health` must not return 200, and `docker compose config` must show `ai-api` off `edge` (docs/18 §24.1, §24.3).
- **`Last-Event-ID` reconnection replays or double-bills.** `EventSource` auto-reconnects on *any* drop and resends the last `id:`. If Laravel treats that as "resume", it either re-runs the provider call (billed twice, different answer) or replays buffered tokens the user already saw. Decision: token streams are not resumable. On a reconnect carrying `Last-Event-ID`, Laravel returns the *persisted* state — `message.complete` when generation finished, otherwise an `error` event carrying **one of the 18 classes** (`kb-error-taxonomy`) — and the client closes the source and offers Retry. A server that can still write a terminal event knows *why* the stream ended and names that cause; `stream_lost` is **not** a wire class and must never appear in an `error_class` field. It is a **client-local sentinel** for the one case the server cannot report — no terminal event arrived at all, because the connection itself died. Clients may raise it internally; nothing serializes it, nothing metrics it, and it does not make the taxonomy 19. Send `retry: 10000` on stream open so a mass drop does not reconnect-storm at the browser's 3s default.
- **Long silences kill the connection before the first token.** Retrieval + rerank runs seconds before generation starts (docs/17 §23 targets ≤1.5s retrieval, ≤4s first token) and idle timers sit lower than people assume — though **not the one usually blamed.** Traefik's `respondingTimeouts.idleTimeout` is the maximum a *keep-alive* connection stays idle **between** requests; an in-flight SSE response is not idle, so a long silence mid-stream is not cut by it (verified against the pinned v3.7 release by `traefik-routing`, which owns the edge settings that genuinely do cut a stream — `writeTimeout` and `lifeCycle.graceTimeOut`). What the heartbeat actually defends against here is nearer and more certain: PHP learns the client is gone only on a failed write, so with no writes there is nothing to fail (`laravel-control-plane`); and a self-hoster's own proxy — nginx defaults `proxy_read_timeout`/`fastcgi_read_timeout` to 60s — is a hop we do not control. Emit `: ping` every 15s from **both** FastAPI and Laravel — Laravel must generate its own, because the silent window can sit inside Laravel's validation and quota checks before a single upstream byte exists. 15s is chosen to stay well under the tightest hop, not to match any one of them.
- **Six tabs and the seventh request hangs.** Over HTTP/1.1 the browser allows ~6 connections per origin, and every open SSE stream holds one — a user with several hosted-chat tabs blocks ordinary XHR on the same origin, which reads exactly like a backend stall. TLS through Traefik negotiates HTTP/2 and the limit disappears; plain-HTTP local development does not, so reproduce this in dev before blaming the API.
- **Out-of-order progress callbacks rewind a job.** A Celery retry re-emits stage 6 after stage 9 has landed. Without the `sequence` guard the admin progress bar runs backwards and, far worse, a `ready` source can be flipped back to `processing`, taking an already-published version out of retrieval. Guard on `(job_id, sequence)` in the `UPDATE`, and treat callbacks as idempotent.
- **`Http::fake()` makes SSE bugs invisible.** A faked response body is a string, so the relay loop drains it instantly and every buffering, heartbeat, ordering, and disconnect bug passes. The Laravel↔FastAPI contract test must run against a real SSE fixture server that emits events with delays and can hang up mid-stream; the FastAPI side asserts its emitted event names and payloads against the same OpenAPI schema Laravel's client is generated from (docs/17 §22.2).
- **A `429` is not one thing.** Tenant quota, Laravel rate limit, and provider rate limit are all plausibly `429`, and their retry policies differ (never / after a window / after `Retry-After` with backoff). FastAPI must put the class in the body and Laravel must relay it unchanged — see `kb-error-taxonomy`.

## Official docs

- [WHATWG HTML — Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html) — wire format, `id`/`retry`/comment semantics, the reconnection algorithm. [MDN — Using server-sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events) — `EventSource` behaviour, `Last-Event-ID`.
- [Laravel — Streamed responses & event streams](https://laravel.com/docs/responses#event-streams) — `response()->stream()`, `eventStream()`, flushing. [Starlette — Requests](https://www.starlette.io/requests/) / [Responses](https://www.starlette.io/responses/) — `is_disconnected()`, `StreamingResponse`.
- [W3C Trace Context](https://www.w3.org/TR/trace-context/) — `traceparent` propagation across the seam.
- [IETF `Idempotency-Key` header draft](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/) and [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests) — replay, conflict, and TTL semantics.
- [Traefik — EntryPoints & timeouts](https://doc.traefik.io/traefik/routing/entrypoints/), [Buffering](https://doc.traefik.io/traefik/middlewares/http/buffering/), [Compress](https://doc.traefik.io/traefik/middlewares/http/compress/) — the three settings that break streaming.
- [Compose file — networks](https://docs.docker.com/reference/compose-file/networks/) — `expose` vs `ports`, network membership. [OpenAPI 3.1](https://spec.openapis.org/oas/latest.html) — the publication format for internal contracts.

## Definition of done

- [ ] Endpoint lives under `/internal/v1/`, `X-KB-Contract-Version` is validated against the path, and the OpenAPI document in `packages/contracts/` is regenerated and committed with the Laravel client generated or validated from it.
- [ ] Contract tests exist on **both** sides — FastAPI asserts what it emits, Laravel asserts what it parses (docs/17 §22.2). All required headers are asserted present by a FastAPI dependency; missing `X-KB-Org-Id` returns `400` (test proves it).
- [ ] Signature verified with a constant-time compare, timestamp skew ≤60s, `X-KB-Request-Id` replay-blocked in Valkey; a replayed request test returns `401`.
- [ ] Mutations require `X-KB-Idempotency-Key` whose fingerprint includes every configuration version listed for the operation; tests cover same-key/same-body replay, same-key/different-body `422`, and concurrent in-flight `409`.
- [ ] Async jobs return `202` with an id and report via signed callbacks; no polling loop added; callbacks guarded on `(job_id, sequence)`.
- [ ] For streaming changes: an integration test asserts inter-event timing (not just the final body), heartbeat presence, exactly one terminal event, and correct finalization after a mid-stream client hangup.
- [ ] No internal-only event (`provider.usage`, `retrieval.trace`, `provider.fallback`) is forwarded to a non-admin client; a test asserts the widget stream's event-name allow-list.
- [ ] `docker compose config` shows the service off the `edge` network with no Traefik router labels and no host `ports:`.

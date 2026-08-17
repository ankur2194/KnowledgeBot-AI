---
name: fastapi-service
description: The ASGI application shape for the AI data plane — lifespan client construction, dependency-injected HMAC/org/deadline context, SSE chat streaming, event-loop offloading, health probes, and the KB error-envelope handlers. Use whenever adding a router, dependency, middleware, exception handler, health endpoint, or streaming response under services/ai-service/app/. Owns the FastAPI application only — not Pydantic model design, not Celery tasks, not retrieval logic. Pairs with kb-internal-api-contracts (the wire it serves).
---

# FastAPI Service — `services/ai-service`

FastAPI **0.141.1** · Starlette **1.3.1** · Uvicorn **0.52.1** · Pydantic **2.13.4** · anyio **4.14.2** · CPython **3.13.x**.
**Authoritative spec:** docs/06-architecture.md §11, docs/12-api-areas.md §17.5, docs/05-tech-stack.md §9.5, docs/14-reliability.md §19.4, docs/17-testing-performance.md §23

Version floors that are not cosmetic: FastAPI **≥ 0.140.13** — 0.140.12 fixed `format_sse_event` line splitting (multi-line `data` was mis-framed on the wire) and 0.140.13 fixed `status_code` being ignored on SSE endpoints. Starlette **≥ 1.0** removed `@app.route`, `@app.middleware`, `@app.exception_handler`, `on_startup`/`on_shutdown`; FastAPI keeps its own `@app.middleware`/`@app.exception_handler`, so Starlette snippets copied from the web will not import. CPython floor **≥ 3.12.4** is a security floor, not a preference (`kb-security-baseline`); we pin 3.13 rather than 3.14 only because the docling/onnxruntime wheel matrix is the gating constraint — those are the **parsing and OCR** stacks, which ADR-030 deliberately keeps local; nothing embeds or reranks in-process any more. Revisit when the wheels catch up. <!-- UNVERIFIED: 3.14 wheel coverage for docling/rapidocr not re-checked -->

## Non-negotiables

- **This service authenticates a *caller*, never a user.** Every route under `/internal/v1` verifies the HMAC signature, timestamp skew, and replay nonce, and takes org/bot/actor from the *verified* headers — never from the body, never inferred from the peer address (`kb-internal-api-contracts`). There is no session, no cookie, no Sanctum token, and no query against Laravel's tables. Skipping this makes network position the only control, and Compose is one `ports:` line away from removing it.
- **Nothing CPU-bound runs in `async def`.** Parsing, OCR and tokenizing block the single event loop for their full duration, so *every* concurrent SSE stream in that worker stalls — including the heartbeats that keep proxies from hanging up. The offload rule is below and it is not optional. Embedding and reranking are no longer on this list: ADR-030 made them provider HTTP calls, which belong on the loop with the other I/O.
- **`asyncio.CancelledError` is re-raised, always.** A swallowed cancellation leaks the span (spans export only on `end()`), leaves the provider generating billable tokens, and leaves the stream task alive for the life of the process (`kb-observability-conventions`, `kb-internal-api-contracts` gotcha 2). Catch it to record `finish_reason="cancelled"`, then `raise`.
- **Streaming spans end in a `finally`, and finalization that must `await` is shielded.** An unshielded `await` inside `finally` on a cancelled task re-raises immediately, so the usage row never gets written — the exact failure `kb-error-taxonomy` calls "cancellation must still finalize usage".
- **One error envelope, produced by handlers, for every failure path** — including `RequestValidationError`, which FastAPI otherwise renders as `{"detail": [...]}`. Laravel relays `error_class` verbatim and never re-derives it from a status (`kb-error-taxonomy`).
- **Long-lived clients and pools are built once in `lifespan`; `/health/ready` fails until they exist.** Reporting ready early routes traffic into a worker that cannot serve it. There is no model load here any more (ADR-030) — and `lifespan` must NOT make a provider warm-up call, because that makes boot depend on a third party.
- **This service writes only the tables in `ALLOWED_TABLES` (`app/db/writes.py`) and owns no migration.** They are derived, rebuildable artifacts whose schema lives in Laravel's migrations; we write rows into a schema we do not define (`kb-architecture-map`). **A name not already on that tuple is a review stop, not a refactor — and the test is ADR-033's three properties, not a count**: the row must be derived and rebuildable in the ADR-010 sense, the public API must neither read nor write it, and Laravel must own its migration. `writes.py` states this itself: *"The invariant is not the number."* The list has already grown once — ADR-032's two BM25 corpus-statistics tables — so any sentence here naming a length would be wrong again on the next admission, and would stop a review for a correct change. What makes an addition unsafe is failing property 2: a data-plane write into a table the public API serves lands beside Laravel's own writer with no policy, no audit row, and no framework-applied tenant scope — and it fails nowhere, the row is simply there. Two further rules make the direct write safe. Chunk and element rows are written **only against the new, not-yet-active `source_version_id`**, never against the version currently serving, which is why a direct write cannot corrupt live state. And this service **never flips `source_items.current_version_id`** — it reports counts, checksum and readiness on §17.5's ingestion callback and Laravel activates (`kb-source-lifecycle`).

## How we use it

### Layout

```
services/ai-service/app/
  main.py                 create_app(), lifespan, exception handlers, router include
  api/deps.py             verify_hmac, request_context, deadline, request_logger
  api/health.py           /health/live, /health/ready, /health/deps (admin-only)
  api/internal/v1/        chat.py providers.py retrieval.py ingestion.py crawl.py
                          deletion.py evaluation.py — one router per contract group
  core/                   config.py errors.py signing.py idempotency.py
  contracts/              Pydantic request/response models → pydantic-contracts
  services/               retrieval, prompt, provider adapters → owning skills
  observability/          instruments and span helpers → opentelemetry-instrumentation
```

`create_app()` is a function, not a module-level `app = FastAPI()`, so tests build isolated apps. `main.py` exposes `app = create_app()` for the server's import string.

### `def` vs `async def`, and how we actually offload

FastAPI runs an `async def` path operation **on the event loop** and a plain `def` one in an anyio worker thread. Both worker-thread routes — sync endpoints, sync dependencies, and every `run_in_threadpool()` — share **one `CapacityLimiter(40)` per event loop**, and `anyio.to_thread.run_sync` defaults to `abandon_on_cancel=False`, meaning it runs inside `CancelScope(shield=True)`: a cancelled request waits for the thread to finish anyway.

| Work | Where it goes | Why |
|---|---|---|
| Provider HTTP, Qdrant, Valkey, object storage | `async def`, async clients | I/O-bound; the loop is what makes concurrency work |
| Embedding, reranking, generation | `async def`, async clients — they are **provider HTTP calls** (ADR-030), not local inference | Row kept because people still look for it here. If you find yourself adding a `CapacityLimiter` for an embedder, something local came back |
| Local OCR / layout inference (rapidocr / onnxruntime, inside Docling) | `await run_in_threadpool(...)` with a **dedicated `CapacityLimiter`** | Releases the GIL in native code, so threads are real parallelism — but it must not eat the shared 40. In practice this runs in Celery, not the API |
| Chunking, regex, pure-Python text normalization | Celery (`celery-workers`), not a thread | Pure Python holds the GIL; a thread just moves the stall |
| Parsing, OCR, crawling, indexing | Celery, always | Minutes-scale and retryable; `kb-error-taxonomy` §timeouts budgets them as jobs |

### Dependencies, and where they must be created

- `verify_hmac` is a **router-level** dependency (`APIRouter(dependencies=[Depends(verify_hmac)])`), so a new endpoint cannot forget it. It calls `await request.body()` — FastAPI caches the bytes on the `Request` and re-reads the cache when it parses the body field, so signing the exact received bytes costs nothing. **Never do this in a `BaseHTTPMiddleware`:** consuming the receive stream there without replaying it makes the endpoint await a body that no longer exists, and the request hangs until the deadline with no error line.
- `request_context` (org, bot, actor, operation, config version, request id) and `deadline` are separate `Depends`. `use_cache=True` is the default, so resolving them from three places costs one call — but the cache key is the callable object, so wrapping one in a `lambda` or `functools.partial` silently re-runs it.
- **Clients and models are created in `lifespan` and handed out through `request.state`, never constructed inside a dependency and never at import time.** Per-request construction means a fresh TLS handshake on every provider call (hundreds of ms straight onto the §23 4 s first-token budget) plus socket exhaustion under load; import-time construction binds to whichever loop happens to be running and produces `RuntimeError: Event loop is closed` / "attached to a different loop" the moment a test uses a second loop.

### Lifespan and workers

`lifespan` is an `@asynccontextmanager` passed as `FastAPI(lifespan=…)`. `@app.on_event` is deprecated in FastAPI and its Starlette equivalents were deleted in 1.0. Yield a mapping and it merges into `request.state` — typed, per-request access.

**Both `request.state` and `app.state` are in use here and the split is deliberate, not a leftover.** A yielded mapping reaches `request.state` only when lifespan actually *ran*, and `httpx.ASGITransport` does not run it — so the names a contract test must be able to install without booting a lifespan (the settings, the HMAC key ring, the replay-nonce store) are assigned onto `app.state` as well, and the dependency layer reads them from there. The clients the health probe and the routers use are read from `request.state`. `app/api/deps.py` names both sets explicitly (`APP_STATE_NAMES` / `REQUEST_STATE_NAMES`) and reading from the wrong one **raises** rather than returning `None`, which is what a plain `getattr` did before. Type the yielded mapping with a **`total=True`** `TypedDict` matching `REQUEST_STATE_NAMES` exactly: under `total=False` every key is optional, so the annotation cannot disagree with the dict literal and mypy has nothing to say about a stale name — which is exactly how `embedder` and `reranker` stayed declared for months after ADR-030 deleted both.

**`ai-api` runs one process per container** (`ai-api` replicas in Compose) rather than `--workers N`. Be clear about why, because the original reason is gone: each Uvicorn worker used to load its own copy of a 2 GB embedder plus a 1.5 GB reranker, so `--workers 4` was 14 GB resident. ADR-030 removed both, and the resident set is now clients and pools. The one-process rule is retained for predictable per-container accounting and graceful stream draining, **not** for memory — anyone re-tuning it should know the old number no longer applies. Production invocation is `fastapi run --host 0.0.0.0 --port 8000 app/main.py` or the equivalent `uvicorn app.main:app`; install `fastapi[standard-no-fastapi-cloud-cli]`, because plain `[standard]` pulls `fastapi-cloud-cli` into the image.

### Health

`/health/live` returns 200 if the event loop is responsive and makes **zero** dependency calls. `/health/ready` checks the dependencies whose failure belongs to *us*: Qdrant, Valkey and PostgreSQL. Read the set out of the code rather than out of this line — `grep -n 'checks: dict\[str, bool\]' services/ai-service/app/api/health.py` prints all of it — because this sentence has already been wrong twice. Object storage is excluded (only ingestion needs it) and so is **anything reached over a provider API**: embeddings, reranking, chat (`kb-observability-conventions`).

**PostgreSQL was missing from this probe until 2026-08-11**, and an unreachable one was then reported by nothing at all. It is not an exception to the provider rule; the rule turns on *who the failure belongs to*, not on how many replicas go down. A provider outage is one tenant's credential and the container can still serve everyone else. An unreachable source of truth means no request on any replica can be served, so taking every replica out is the correct answer rather than the feared one.

That provider exclusion got *stronger*, not weaker, when embedding moved off-process. **Readiness must never probe an external provider.** Traefik removes an unhealthy container from rotation and Compose's `restart:` reacts to a process exiting, not to a failing healthcheck — so a readiness probe that trips on a transient provider blip pulls *every* replica out of the edge simultaneously, with nothing in the system able to put them back. Provider reachability is per-bot and per-credential anyway, never per-process: one tenant's revoked key must not take a container out of rotation. The old `embedding_model` check is gone with the model (ADR-030).

**Each check is one real round trip, and presence of the client object is not readiness.** Every client `lifespan` builds connects lazily, so `is not None` reports green on a container where every request fails: `AsyncQdrantClient` and `redis.asyncio` both do it, and `AsyncConnectionPool.open()` returns *before* its first connection exists — measured 0.00 s against TEST-NET-3 with `pool_size=2` and `pool_available=0`, because `pool_size` counts slots being filled, not live connections. The pool therefore needs a checkout **and** a statement (`app/db/pool.py` states the other half of that division: `open_pool` must return against a database thirty seconds late, so `ready()` is what has to answer 503 while it stays late — do not re-add `wait=True` to move the check back there). Bound each probe well inside the healthcheck budget, run them **concurrently** so adding one does not multiply the worst case, catch broadly — every way a probe can fail is one answer to the one question this endpoint asks — and give each dependency its own key in the 503 body so a red probe names the failing one instead of sending a debugger somewhere else.

Cache the readiness result for 5 s. The `start_period` that used to be sized for a multi-GB model load should be re-sized — with no model to load, a long one only delays the moment a genuinely broken container is noticed.

### Error envelope

One `KbError(error_class, message, retryable, retry_after)` type, one handler per family, all rendering the same body. `kb-error-taxonomy` owns the class → status map; this is only the rendering:

```python
# app/main.py — registered on the app, not per-router.
def _envelope(exc: KbError) -> JSONResponse:
    return JSONResponse(
        status_code=STATUS_FOR[exc.error_class],
        content={"error_class": exc.error_class, "message": exc.message,
                 "retryable": exc.retryable, "request_id": current_request_id()},
        headers={"Retry-After": str(exc.retry_after)} if exc.retry_after else None,
    )

app = FastAPI(lifespan=lifespan, exception_handlers={
    KbError:                lambda r, e: _envelope(e),
    RequestValidationError: lambda r, e: _envelope(KbError("validation", ...)),  # not {"detail": [...]}
    Exception:              lambda r, e: _envelope(KbError("internal_dependency", ...)),  # never leak str(e)
})
```

### The one that matters — the streamed chat endpoint

```python
# services/ai-service/app/api/internal/v1/chat.py
router = APIRouter(prefix="/internal/v1", dependencies=[Depends(verify_hmac)])

@router.post("/chat/stream", response_class=EventSourceResponse)
async def chat_stream(
    body: ChatStreamRequest,                                   # shape → pydantic-contracts
    ctx: Annotated[RequestContext, Depends(request_context)],  # verified headers only
    deadline: Annotated[Deadline, Depends(deadline)],          # X-KB-Deadline, absolute
    st: Annotated[AppState, Depends(app_state)],               # lifespan-owned clients/models
) -> AsyncIterator[ServerSentEvent]:
    """Everything — retrieval, rerank, provider — runs INSIDE the generator. Work done in the
    endpoint body before the response is returned sits outside Starlette's disconnect-watching
    task group, so a client that hangs up during an 8 s retrieval leg is not noticed at all."""
    span = tracer.start_span("kb.chat.pipeline")               # start_span + finally, never `with`
    outcome, error_class, emitted = "success", "none", 0
    try:
        with trace.use_span(span, end_on_exit=False):
            # Deadline is checked against a monotonic clock derived once from the header; the
            # header is wall-clock epoch millis and an NTP step mid-request would move it.
            with anyio.fail_after(deadline.remaining()):
                ev = await retrieve(body, ctx, st)             # emits `status` spans internally
            yield ServerSentEvent(event="message.start", data=ev.start)
            yield ServerSentEvent(event="citations", data=ev.citations)  # ALWAYS before token 1

            # `async with` is load-bearing: cancelling here closes the socket, which is what
            # actually stops the provider generating. A bare `await client.post(...)` kept in a
            # detached task keeps billing after the client is gone.
            async with st.provider.stream(body, ctx, deadline) as chunks:
                async for c in chunks:
                    if not c.text:
                        continue                               # role/empty first delta — not TTFT
                    emitted += 1
                    yield ServerSentEvent(event="token", data={"text": c.text})

            yield ServerSentEvent(event="provider.usage", data=ev.usage)   # Laravel-only event
            yield ServerSentEvent(event="message.complete", data=ev.complete)
    except asyncio.CancelledError:
        outcome, error_class = "cancelled", "user_cancellation"
        raise                                                  # NEVER swallow — see non-negotiables
    except KbError as exc:
        outcome, error_class = "error", exc.error_class
        # The response already started, so no exception handler can run and no status can change:
        # a mid-stream failure is an SSE event or it is a dropped connection.
        yield ServerSentEvent(event="error",
                              data={"error_class": exc.error_class, "message": exc.message,
                                    "retryable": exc.retryable})
    finally:
        span.set_attributes({"kb.error_class": error_class, "kb.finish_reason": outcome,
                             "kb.org_id": ctx.org_id, "kb.operation": ctx.operation})
        span.end()                                             # sync: safe on a cancelled task
        # Anything that awaits must be shielded and bounded, or cancellation kills it at the
        # first checkpoint and the usage row is lost.
        with anyio.CancelScope(shield=True):
            with anyio.move_on_after(2.0):
                await record_usage(ctx, emitted, outcome)
```

No `StreamingResponse`, no manual `\n\n` framing, no hand-rolled heartbeat task: `response_class=EventSourceResponse` on an async-generator path operation makes FastAPI serialize each `ServerSentEvent`, set `Cache-Control: no-cache` and `X-Accel-Buffering: no`, insert `: ping\n\n` after **15 s** of generator idleness (which is exactly the interval `kb-internal-api-contracts` requires), and — critically — insert an `await anyio.sleep(0)` checkpoint after every yielded item so cancellation can actually be delivered.

## Gotchas

- **A user closes the tab, the provider bill keeps climbing, and the request never appears in Tempo.** Two independent causes, usually together. Uvicorn's `send()` *silently returns* when the client is gone — it does not raise, so "the write will fail eventually" is false here; disconnect is detected only because Starlette races the body iterator against `http.disconnect` on the receive channel and cancels the scope. And if your generator catches `CancelledError` (or worse, `except Exception` around an `await`) without re-raising, that cancel is absorbed: the loop keeps pulling provider chunks, the span never ends so it is never exported, and the task outlives the request. Re-raise, every time.
- **A hand-written `StreamingResponse` streams perfectly and never notices a hangup.** Cancellation in asyncio is delivered at a checkpoint; a generator whose producer is always faster than the consumer may never suspend, so it never reaches one. FastAPI's SSE and JSONL paths insert `await anyio.sleep(0)` after each item for exactly this reason (fastapi#14680) — a bespoke generator does not get that for free. Use `EventSourceResponse`; if you must hand-roll, add the checkpoint yourself.
- **The client disconnected 40 seconds ago and the worker is still busy.** The classic form was a stream blocked in `await run_in_threadpool(rerank, ...)`: `anyio.to_thread.run_sync` defaults to `abandon_on_cancel=False`, i.e. `CancelScope(shield=True)`, so cancellation is deferred until the thread returns and threads cannot be killed. Reranking is a provider HTTP call now, so the *shape* moved rather than the lesson: a cancelled request still leaves the provider billing for work nobody will read. Bound the unit before starting it (`bge-reranker`), never the clock around it. The thread-offload version of this still bites for parsing and OCR.
- **p95 latency triples under load with no errors, no slow query, and CPU at 100% on one core.** Something CPU-bound ran on the loop and blocked every other stream in that worker for its full duration. The symptom is uniform latency inflation across unrelated endpoints, which points at everything except the real cause. Grep for `async def` handlers that touch `docling`, `rapidocr`, `onnxruntime` or a tokenizer. Historically this was an embedder called from `async def`; that specific cause is gone with the local model, but the failure mode belongs to any native call.
- **Requests queue invisibly at exactly 40 concurrent, and nothing logs it.** Sync endpoints, sync dependencies, and `run_in_threadpool` all share `anyio`'s one default `CapacityLimiter(40)` per loop. Past that they wait on the limiter — no error, no 503, no metric. Give the model offload its own limiter and keep sync dependencies out of the hot path.
- **`RuntimeError: Caught handled exception, but response already started.`** An exception escaped after the first SSE byte was flushed. Starlette's handler wrapper cannot rewrite a response whose `http.response.start` has been sent, so it re-raises this instead. Every failure inside the generator must become an `event: error` payload; only failures raised *before* the generator yields can be an HTTP status.
- **`fastapi.exceptions.FastAPIError: Response not awaited.`** A dependency with `yield` has a bare `except` / `except Exception` around its `yield` and does not re-raise. Since the request-scoped `AsyncExitStack` closes *after* the response is sent, swallowing there breaks the teardown chain. Log-and-re-raise in dependency teardown; never absorb.
- **HMAC verification passes locally and 401s intermittently in Compose.** Either the signature was computed over a re-serialized body (`json.dumps(await request.json())` is not byte-stable — key order, unicode escaping, and separators all differ) or clock skew exceeded 60 s between containers. Sign and verify the exact bytes from `await request.body()`, compare with `hmac.compare_digest`, and treat skew and the Valkey replay nonce as one setting (`kb-internal-api-contracts`).
- **The endpoint hangs forever and the body never arrives.** A `BaseHTTPMiddleware` read `request.body()` / iterated `request.stream()` and did not replay it downstream. This is why signature verification, request logging, and org context are **dependencies**, not middleware — dependencies run inside the route, share the cached body, and can return a typed envelope. Reserve middleware for things that never touch the body (CORS is not needed here at all: no browser reaches this service).
- **A `deadline exhausted` fires the instant the request arrives, or never fires at all.** `X-KB-Deadline` is absolute epoch **milliseconds**, not a duration and not seconds. Convert once at the dependency into a monotonic deadline (`time.monotonic() + (hdr_ms/1000 - time.time())`) and check `remaining()` against `time.monotonic()` afterwards — recomputing from `time.time()` mid-request makes an NTP step look like an expired deadline.
- **`/health/ready` returns 200 while every answer fails.** The probe asserted something cheaper than what it claimed to check. The historical form was a model attribute left `None` because the load was fired with `asyncio.create_task` in lifespan instead of awaited; the *current* form is the one to guard against, and asserting the yielded client objects exist is now the bug rather than the fix — every one of them connects lazily, so `is not None` is green on a container whose dependency is gone. Readiness makes one real round trip per dependency (see Health above), and for a pool that is a checkout **plus** a statement: the checkout is what fails when the server is gone, the statement is what fails when the checkout succeeded and the session is dead anyway (failover, restart, an idle connection reaped by a proxy). A container that takes time to become ready is correct behaviour, not a bug to work around. Two inverse mistakes are worse: **never** extend readiness to a provider round-trip, and never omit a dependency whose failure nothing else reports — PostgreSQL was omitted here until 2026-08-11.
- **`WEB_CONCURRENCY` in the environment silently sets `--workers`.** Check it before blaming the flag. This used to triple memory per container because each forked process ran lifespan and loaded its own embedder and reranker; with no resident models that cost is gone, so if you are re-tuning concurrency, tune it against measurements rather than against the old constraint.
- **Validation failures reach Laravel as `{"detail": [...]}` and get relayed as an untyped 422.** FastAPI's built-in `request_validation_exception_handler` is registered by default and wins unless you override `RequestValidationError` explicitly. Override it, map to `error_class: "validation"`, and never echo the offending value back — the body can contain user questions and retrieved chunk text (`kb-observability-conventions`).
- **Ingestion reports success, the bot keeps answering from the old version, and a Celery task then retries forever on an integrity error.** The data plane flipped `source_items.current_version_id` itself instead of reporting readiness on the §17.5 callback, so two writers raced the one column that decides which version is live — and `postgresql-patterns`' partial unique index ("at most one active version per item") turned a lifecycle bug into an `IntegrityError` inside a task with no way to make progress. FastAPI writes rows against the *new, inactive* `source_version_id`; Laravel activates. Grep the service for `current_version_id`: it may appear in a callback payload model and nowhere else — never on the left of an assignment, never in a `SET`.
- **Token counts in `usage` run 1–2 ahead of what the client received on a cancelled stream.** FastAPI's SSE path decouples the generator from the wire through a memory object stream with `max_buffer_size=1`, plus one item held by the keepalive inserter. This is correct — the provider billed those tokens — so do not "fix" it by counting flushes.

## Official docs

- [FastAPI — Lifespan events](https://fastapi.tiangolo.com/advanced/events/) · [Concurrency and `async`/`await`](https://fastapi.tiangolo.com/async/) · [Dependencies with `yield`](https://fastapi.tiangolo.com/tutorial/dependencies/dependencies-with-yield/) — the `def` vs `async def` dispatch rule and teardown ordering.
- [FastAPI — Server-Sent Events](https://fastapi.tiangolo.com/advanced/sse/) and the [`fastapi.sse` reference](https://fastapi.tiangolo.com/reference/sse/) — `EventSourceResponse`, `ServerSentEvent`, keepalive behavior.
- [FastAPI — Handling errors](https://fastapi.tiangolo.com/tutorial/handling-errors/) — overriding `RequestValidationError` and the default handlers.
- [FastAPI — Server workers](https://fastapi.tiangolo.com/deployment/server-workers/) and [in containers](https://fastapi.tiangolo.com/deployment/docker/) — why one process per container.
- [Starlette — Responses](https://www.starlette.io/responses/) / [Requests](https://www.starlette.io/requests/) — `StreamingResponse` disconnect handling, `is_disconnected()`. [Release notes](https://www.starlette.io/release-notes/) — the 1.0 removals.
- [Uvicorn settings](https://www.uvicorn.org/settings/) — `--workers`, `--lifespan`, `--timeout-keep-alive` (5 s), `--timeout-graceful-shutdown` (unset = wait forever on in-flight streams), `--limit-concurrency`.
- [AnyIO — Threads](https://anyio.readthedocs.io/en/stable/threads.html) and [Cancellation and timeouts](https://anyio.readthedocs.io/en/stable/cancellation.html) — `abandon_on_cancel`, the default limiter, `CancelScope(shield=True)`, `fail_after`/`move_on_after`.
- [WHATWG — Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html) — the wire format `format_sse_event` implements.

## Definition of done

- [ ] Every `/internal/v1` router carries `dependencies=[Depends(verify_hmac)]`; a test posts an unsigned and a replayed request and gets `401`, and a request missing `X-KB-Org-Id` gets `400`.
- [ ] No `async def` handler or dependency calls into `docling`/`rapidocr`/`onnxruntime`/a tokenizer/a parser; native offloads use a dedicated `CapacityLimiter`, not the shared default.
- [ ] Streaming endpoints use `response_class=EventSourceResponse` on an async generator; no hand-built `text/event-stream` framing exists in the repo.
- [ ] A test hangs up mid-stream and asserts: the provider client's socket closed, `CancelledError` propagated (not swallowed), the span exported with `kb.finish_reason="cancelled"`, and the usage row written from the shielded `finally`.
- [ ] All retrieval and provider work happens inside the generator; nothing but validation and dependency resolution runs before the response is returned.
- [ ] Every failure renders the same envelope with a `kb-error-taxonomy` class — including `RequestValidationError` (a test asserts the body is not `{"detail": …}`) and unhandled `Exception` (a test asserts `str(exc)` does not reach the body).
- [ ] HTTP clients, the Qdrant/Valkey clients and the psycopg pool are created in `lifespan` and reached via `request.state`; a grep finds no client constructed at module import or inside a `Depends`. The `TypedDict` describing what `lifespan` yields is `total=True` and matches `app/api/deps.py`'s `REQUEST_STATE_NAMES` exactly — under `total=False` every key is optional, so mypy cannot disagree with the dict literal and a name that no longer exists can sit in the annotation indefinitely (it did, for `embedder` and `reranker`, months after ADR-030 deleted both).
- [ ] `/health/live` passes with every dependency blackholed. `/health/ready` is red for **every** required dependency — a test blackholes each in turn and asserts the 503 body names that one by key, with the set taken from `grep -n 'checks: dict\[str, bool\]' app/api/health.py` rather than transcribed here — and it must go red on a dependency whose client object still exists, since lazy clients make a presence check pass. It **stays green with every provider unreachable**: a test blackholes the provider egress and asserts 200.
- [ ] The container runs one process (no `--workers`, `WEB_CONCURRENCY` unset) and its healthcheck `start_period` is sized against measured cold-start time — not against the multi-GB model load that no longer happens.
- [ ] `pyproject.toml` pins `fastapi[standard-no-fastapi-cloud-cli]>=0.140.13`, and the exported OpenAPI document is committed to `packages/contracts/` (`kb-internal-api-contracts`).
- [ ] No SQL or ORM write under `services/ai-service/app` targets a table outside `ALLOWED_TABLES` (`app/db/writes.py`), and the service carries no migration tool or DDL; CI's allow-list gate **imported that tuple** rather than restating it, and separately pinned its membership by name, so an addition, a swap or a rename all failed until a reviewer stated which of ADR-033's three properties admits it. Both were CI gates and both were deleted on 2026-08-17, so this checkbox is now the check. **Do not write a count into this checkbox** — the previous one said "the fifth name" and would have blocked ADR-032's two legitimate tables.
- [ ] A test writes a version's chunks and asserts the previous version still serves until Laravel's activation callback lands, and that no code path assigns `current_version_id`.

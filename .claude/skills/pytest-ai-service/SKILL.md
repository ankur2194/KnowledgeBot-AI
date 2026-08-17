---
name: pytest-ai-service
description: pytest 9 conventions for the FastAPI data plane in services/ai-service/tests/. Use whenever writing a unit, contract, integration or security test, an async fixture, a Celery task, or a provider adapter — and whenever a loop closes early, xdist workers share a canary, or a streaming test is green while production never notices a hangup. httpx.ASGITransport buffers the whole SSE body, so the chat path runs on a real Uvicorn socket. Pairs with pest-testing (the Laravel half) and kb-tenancy-isolation (the contract it proves).
---

# Pytest — AI Data Plane (`services/ai-service`)

pytest **9.1.1** · pytest-asyncio **1.4.0** · anyio **4.14.2** · httpx **0.28.1** · testcontainers **4.15.0** · pytest-xdist **3.8.0** · pytest-cov **7.1.0** · qdrant-client **1.19.0**, on CPython 3.13 (`fastapi-service`), installed with `uv`. Tests live in `services/ai-service/tests/`.
The Laravel half is `pest-testing`, the browser half `vitest-playwright`; this is the third and it does not restate them. RAG **quality** scoring is `ragas-evaluation` — a judged metric is a model result, not an assertion, and it runs in the scheduled CI tier, never here.
**Authoritative spec:** docs/17-testing-performance.md §22.1–22.6, docs/19-repo-structure-adrs.md §27, docs/18-deployment-backup-cicd.md §26

## Non-negotiables

1. **Two organizations, a fresh canary, and the positive control asserted first.** `pest-testing` NN1–NN2 applies here unchanged and is the single most important rule in the testing doctrine: assert the canary *is* returned to Org B **before** asserting it is absent from Org A. Without that line a broken filter, an empty collection, a 500, or a `KbError` raised before the query all satisfy "canary absent" and the isolation suite is decoration. There is deliberately no single-org fixture to reach for.
2. **Isolation and retrieval assertions run against a real Qdrant container.** `QdrantClient(":memory:")` does not propagate the root filter into prefetch branches — that propagation is server-side only, so the leaky query of `kb-tenancy-isolation` *leaks in memory and is safe in production*, exactly inverting what the test proves. In-memory is legal for chunk-payload shape and for `Filter` construction unit tests; it is never legal for a §22.5 claim.
3. **Nothing in-process stands in for the chat stream.** `httpx.ASGITransport` collects every `http.response.body` into a list and returns `b"".join(...)` as one chunk, and its `receive()` yields `http.disconnect` only *after* `response_complete` is set. `starlette.testclient.TestClient` does the same through a `BytesIO`. So both buffer the whole SSE body and **cannot deliver a mid-stream disconnect at all** — the Python twin of `Http::fake()` (`pest-testing` NN4) and `route.fulfill()` (`vitest-playwright` NN4). Streaming and cancellation tests bind a real Uvicorn to a real socket.
4. **Every shared resource is worker-scoped, not just the database.** `pest-testing`'s parallel hazard, one runtime over: under `-n auto` the Qdrant collection, the Valkey logical DB, the SeaweedFS bucket, the cache prefix **and the Celery queue names** must all carry `worker_id`, or worker 2's canary lands in worker 1's assertion and the failure only ever appears in CI.
5. **`task_always_eager` is never the evidence for a Celery claim.** Eager execution skips the broker entirely, so JSON serialization of the payload never runs, `acks_late` / `reject_on_worker_lost` / redelivery never happen, `request.delivery_info` is empty, and soft and hard time limits do not fire — they need SIGUSR1 and the prefork pool (`celery-workers`). The split is in the table below.
6. **No bypass exists to be exercised.** No `allowed_version_ids=None`, no `verify_hmac` skip flag, no `internal=True` kwarg, no fixture that widens a filter (`kb-tenancy-isolation` NN4). If a test is awkward without one, the production code is wrong.

## How we use it

### Configuration — the two loop scopes, set explicitly

```toml
# services/ai-service/pyproject.toml — pytest 9 also accepts native [tool.pytest] or
# pytest.toml, but the two tables cannot both be present. Keep one.
[tool.pytest.ini_options]
asyncio_mode = "auto"                          # every async def test and fixture is handled
asyncio_default_fixture_loop_scope = "session" # unset = the FIXTURE'S OWN scope, plus a warning
asyncio_default_test_loop_scope = "session"    # unset = "function". Mismatch is the bug below.
addopts = "--strict-markers --strict-config -ra -W error::DeprecationWarning"
markers = ["integration: needs real containers", "security: the §22.5 suite"]
```

Both options exist, they default differently, and **the defaults disagree with each other**: an unset fixture scope resolves to the fixture's caching scope while the test scope resolves to `function`. So a `scope="session"` async client fixture is created on a session loop and awaited from a function loop — `RuntimeError: <Future> attached to a different loop`, or `Event loop is closed`, appearing on the *second* test rather than the first. Set both to `session` so the lifespan-owned clients of `fastapi-service` (which bind to whichever loop constructed them) live on the loop that uses them. `asyncio_mode = "auto"` is chosen because in `strict` mode an async fixture decorated with plain `@pytest.fixture` hands the test an un-awaited async-generator object instead of the value — a deprecation warning since 1.2.0, and the reason for `-W error`.

### What each suite owns, and may not claim

| Suite | Owns | May not assert |
|---|---|---|
| `unit/` (no containers, no app) | `tenant_filter()` construction, chunk boundaries, `error_class` classification, cost math, citation validation, URL/SSRF validation, deletion selection, Pydantic model shape (`pydantic-contracts`) | anything about a query *result*; anything about a stream; tenancy — a filter object is not a search |
| `contract/` (`ASGITransport` + `LifespanManager`, fakes injected) | HMAC verify/replay/skew, missing-header `400`, the one error envelope incl. `RequestValidationError`, the emitted OpenAPI matching `packages/contracts/` | inter-event timing, disconnect, anything the transport buffers away |
| `integration/` (real containers, real socket) | the four Qdrant filters against a server, atomic version publish, deletion + verification, SSE framing and gaps, **the cancellation path**, a real prefork Celery worker | provider behaviour, browser rendering |
| `security/` (real containers) | every §22.5 case: cross-tenant API and vector access, SSRF payloads, malicious filenames, oversized files, prompt-injection samples, XSS in source text, secret redaction, rate limits | — this suite is never optional, whoever runs it |

`unit/` and `contract/` need nothing running; `integration/` and `security/` need Postgres, Qdrant and Valkey. Those tiers were once a per-push/merge-queue split with the databases supplied as workflow `services:`; there is no CI now, so every tier runs wherever you run it and the containers come from `testcontainers`. Container fixtures still read `KB_TEST_QDRANT_URL` and only start a container when it is absent — and pin the image tag to the Compose `test` profile, because `QdrantContainer`'s own default drifts on every testcontainers release.

### The one that matters — cancellation over a real socket

```python
# services/ai-service/tests/integration/test_chat_stream_cancel.py
import asyncio, socket, contextlib
import httpx, pytest, pytest_asyncio, uvicorn

pytestmark = pytest.mark.integration

@pytest_asyncio.fixture(scope="session")
async def live_url(app):
    """A real Uvicorn on a real port. ASGITransport and TestClient both buffer the whole
    response body and only yield http.disconnect once it is complete, so neither can
    reproduce a client that vanishes mid-generation — the exact bug this test exists for."""
    s = socket.socket(); s.bind(("127.0.0.1", 0)); port = s.getsockname()[1]; s.close()
    server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="warning"))
    task = asyncio.create_task(server.serve())
    while not server.started:                      # lifespan loads the models; do not sleep-guess
        await asyncio.sleep(0.02)
    yield f"http://127.0.0.1:{port}"
    server.should_exit = True
    await task

async def test_client_hangup_cancels_the_provider_and_still_finalizes_usage(
    live_url, signed, tenant, fake_provider, spans, db
):
    first_token = asyncio.Event()

    async with httpx.AsyncClient(timeout=30.0) as client:
        req = signed("POST", "/internal/v1/chat/stream", tenant.body)   # HMAC over exact bytes
        async with client.stream("POST", live_url + req.path, **req.kw) as resp:
            assert resp.status_code == 200
            names = []
            async for line in resp.aiter_lines():
                if line.startswith("event: "):
                    names.append(line[7:])
                    if names[-1] == "token":
                        first_token.set()
                        break                       # leaving the block sends RST, not FIN:
            # `break` closes the response and the socket while the generator is still yielding.
            # A clean end() would look like a completed answer and never enter this path at all
            # (the same distinction vitest-playwright's fixture draws with cut() vs end()).

    assert names[:2] == ["message.start", "citations"]   # citations precede token 1 (docs/07 §12.16)

    # The provider socket must actually close — that is what stops the tokens being billed.
    await asyncio.wait_for(fake_provider.disconnected.wait(), timeout=5.0)
    assert fake_provider.tokens_sent < fake_provider.tokens_scripted

    span = await spans.await_ended("kb.chat.pipeline")   # spans export on end(); a swallowed
    assert span.attributes["kb.finish_reason"] == "cancelled"   # CancelledError never ends one
    assert span.attributes["kb.error_class"] == "user_cancellation"

    # Written from the shielded `finally` (fastapi-service). Cancellation is an outcome,
    # not an error: it is excluded from the error rate and still finalizes usage
    # (kb-error-taxonomy). A test that stops at "the stream ended" misses both halves.
    usage = await db.usage_for(tenant.message_id)
    assert usage.completion_tokens == fake_provider.tokens_sent and usage.estimated is True
```

`fake_provider` is a second Uvicorn app that replays a **recorded** provider SSE transcript frame by frame, with real `await asyncio.sleep()` gaps, `\r\n` on some frames, one frame split mid-UTF-8 codepoint, and an `on_disconnect` event. It is one fixture serving both the cancellation test above and the adapter tests below, so the frame shapes cannot drift apart.

### Provider adapters, with no network

Point the SDK's `base_url` at that fixture server. Nothing is stubbed inside the adapter, so real SDK stream parsing, the real `usage` accounting of `openai-api`/`anthropic-api`, and the real stop-reason mapping all execute. Recorded cassettes (`vcrpy`) cover only **non-streaming** shapes — error bodies, 402/429 discrimination, usage payloads — because a cassette replays a body, not its chunk boundaries, and the chunk boundaries are the thing under test. `httpx.MockTransport` returns the handler's `Response` untouched, so it *does* preserve a lazily-iterated stream and is fine for unit-level frame parsing; it has no socket, so it can never prove the connection closed. Live provider calls are the scheduled tier's smoke job only, never per push (§22.2).

**Never faked:** the tenant filter, the HMAC verifier, the SSE transport on the chat path, the error envelope, Qdrant in a §22.5 test. **Always faked:** the LLM provider (docs/18 §24.6). **Always frozen:** time — `freezegun` or an injected clock for version-activation windows, idempotency TTLs, `Retry-After`, replay skew and retention cutoffs, so an assertion never depends on which side of midnight CI ran.

### Celery — eager versus a real worker

| Claim | Eager is enough | Needs a real prefork worker + Valkey |
|---|---|---|
| Stage logic, lifecycle transitions, idempotency claim/release | ✅ | |
| Payload is JSON-serializable, `traceparent` survives the wire | | ✅ — eager never serializes |
| Retry countdown, `error_class` gating, delivery cap | | ✅ — eager raises through instead of enqueuing |
| `soft_time_limit` / `time_limit` firing, `SoftTimeLimitExceeded` checkpoint | | ✅ — limits need SIGUSR1 and prefork |
| Redelivery after SIGKILL, `acks_late`, `reject_on_worker_lost` | | ✅ |
| Task span is a new root with a link to the submitter | | ✅ |

The in-process helper worker from `celery.contrib.testing` is not prefork either, so it buys ordering, not time limits. <!-- UNVERIFIED: celery.contrib.pytest fixture names and the default pool of celery.contrib.testing.worker.start_worker were not re-read from source this revision -->

## Gotchas

- **The streaming test is green and production delivers the answer in one chunk, or never notices a hangup.** `httpx.ASGITransport` awaits the whole app before returning and joins every body part into a single chunk; `TestClient` writes into a `BytesIO`. Both then hand `http.disconnect` to the app only after the response completed, so `anyio.sleep(0)` checkpoints, heartbeat insertion, `CancelledError` propagation, and the shielded usage `finally` are all untestable through them. Use a real Uvicorn and assert inter-event wall-clock gaps, never a chunk count.
- **Every async test errors on the second test in the file with "attached to a different loop", or a session client is closed after the first test.** `asyncio_default_fixture_loop_scope` and `asyncio_default_test_loop_scope` were left unset, so the fixture got its own caching scope and the test got `function`. Set both explicitly to the same value; setting only the one the startup warning names makes it worse, not better.
- **A tutorial's `event_loop` fixture override does nothing, or errors on import.** The `event_loop` fixture was **removed in pytest-asyncio 1.0.0**; `event_loop_policy` overriding is deprecated as of 1.4.0 in favour of the `pytest_asyncio_loop_factories` hook. Most async-pytest answers online predate both.
- **An `async def` test "passes" without running.** On pytest ≥ 8 it does not — an unhandled coroutine now *fails* with "async def functions are not natively supported". The surviving silent version is an async **fixture** under `strict` mode with a plain `@pytest.fixture`: the test receives an async-generator object, and truthiness assertions on it pass. `asyncio_mode = "auto"` plus `-W error` removes both shapes.
- **The isolation suite is green and staging leaks.** One of three, all fatal, all fixed by the same fixture: one organization was seeded, the assertion ran against `QdrantClient(":memory:")`, or the retrieval call was stubbed. Two orgs, real container, real pipeline entry point.
- **The isolation suite is green because everything returned nothing.** A `KbError` before the query, an empty collection, an unindexed fixture — all satisfy the negative assertion. The positive control is the only defence and it is the line people delete when the suite gets slow.
- **A canary from `gw3` appears in `gw0`'s assertion; the rate-limit test fails only under `-n auto`.** `worker_id` (session-scoped, `"gw0"`/`"master"`) must feed the Qdrant collection name, the Valkey DB index, the bucket, the cache prefix and the queue names. Never call `FLUSHDB` or drop a shared collection from a test.
- **A test asserts a row a Celery worker wrote and finds nothing.** The test held an open transaction the worker's connection cannot see — `pest-testing`'s `RefreshDatabase` trap in Python form. Commit before dispatching, and truncate rather than roll back in `integration/`.
- **`ModuleNotFoundError` or a `DeprecationWarning` that `-W error` turns into a failure on `from testcontainers.qdrant import QdrantContainer`.** testcontainers 4.15 moved every module to `testcontainers.community.<name>`; the old paths are shims that warn. Import `testcontainers.community.qdrant` / `.valkey` / `.postgres`, and install the matching extra (`testcontainers[qdrant]` pulls `qdrant-client`; `postgres` and `valkey` pull nothing — bring your own driver).
- **The suite passes locally and the merge-queue job starts a second Qdrant.** The container fixture ignored the workflow-provided service. Short-circuit on `KB_TEST_QDRANT_URL`; testcontainers 4.15 has no container-reuse feature, so an unconditional session fixture is a cold start per job.
- **A contract test 401s intermittently.** The signature was computed over re-serialized JSON. `json.dumps(await request.json())` is not byte-stable (key order, unicode escaping, separators), and the `X-KB-*` headers are inside the canonical string, so a fixture that adds one header breaks it by construction (`kb-internal-api-contracts`). Sign the exact bytes the test sends.
- **`/health/ready` is 200 in a test and the embedder is `None`.** `ASGITransport` does not run lifespan at all. Wrap with `asgi-lifespan`'s `LifespanManager` (last released 2023 — pin it and expect no fixes) or use `TestClient` as a context manager; either way inject stub models, because loading real weights per test run costs more than the suite.
- **A `-W error` run fails inside a dependency, not our code.** Keep the global filter at `error::DeprecationWarning` and add narrow `filterwarnings` ignores keyed to the offending module, never a blanket `ignore` — the async-fixture and testcontainers traps above are both *only* visible as warnings.
- **A faithfulness number appears in a required check.** Ragas is nondeterministic even at temperature 0 and its instance-level agreement with humans is near noise (`ragas-evaluation`). It gates in the scheduled tier against `samples/`, on paired differences with clustered standard errors — never as a unit assertion.
- **A secret-redaction test passes because the credential was never used.** Same shape as the isolation control: assert the fixture credential actually reached a provider call, *then* assert it appears in no log line, no span attribute, no audit detail and no response body (`kb-security-baseline`).

## Official docs

- [pytest — changelog](https://docs.pytest.org/en/stable/changelog.html) and [Configuration](https://docs.pytest.org/en/stable/reference/customize.html) — pytest 9's native TOML tables, `strict` mode, subtests.
- [pytest-asyncio — Configuration](https://pytest-asyncio.readthedocs.io/en/stable/reference/configuration.html) and [Changelog](https://pytest-asyncio.readthedocs.io/en/stable/reference/changelog.html) — the two loop-scope options and their differing defaults, the 1.0 removal of `event_loop`, the 1.4 `pytest_asyncio_loop_factories` hook.
- [AnyIO — Testing](https://anyio.readthedocs.io/en/stable/testing.html) and [Cancellation](https://anyio.readthedocs.io/en/stable/cancellation.html) — the alternative plugin (do not run both) and the shield semantics the cancellation test asserts.
- [HTTPX — Async clients](https://www.python-httpx.org/async/) and [`_transports/asgi.py`](https://github.com/encode/httpx/blob/0.28.1/httpx/_transports/asgi.py) — the buffering and `http.disconnect` behaviour quoted above is only in the source.
- [Starlette — TestClient](https://www.starlette.io/testclient/) — including its lifespan handling as a context manager.
- [testcontainers-python](https://testcontainers-python.readthedocs.io/) — the `testcontainers.community.*` layout, wait strategies, Ryuk.
- [pytest-xdist](https://pytest-xdist.readthedocs.io/) — `worker_id` and `testrun_uid`, and `--dist loadgroup` for serialising the rate-limit tests.
- [Celery — Testing](https://docs.celeryq.dev/en/stable/userguide/testing.html) — `task_always_eager`'s stated limits and the contrib fixtures.

## Definition of done

- [ ] Every §22.5 case exists under `security/`, runs against real containers in every CI tier, and each isolation test uses the two-org fixture, a per-test canary, and asserts the positive control first. `grep -rn 'QdrantClient(":memory:")' tests/security tests/integration` returns nothing.
- [ ] `pyproject.toml` sets `asyncio_mode = "auto"` and **both** `asyncio_default_fixture_loop_scope` and `asyncio_default_test_loop_scope` to the same value; no `event_loop` or `event_loop_policy` fixture is defined anywhere.
- [ ] No test on a `text/event-stream` route uses `ASGITransport` or `TestClient`: `grep -rn "ASGITransport\|TestClient" tests/` shows matches only under `contract/`.
- [ ] The cancellation test exists and asserts all four: the provider socket closed, tokens billed < tokens scripted, a span exported with `kb.finish_reason="cancelled"`, and a usage row written from the shielded `finally`.
- [ ] Stream tests cover a frame split mid-UTF-8 codepoint, `\r\n` line endings, `: ping` not surfaced as an event, `citations` before token 1, exactly one terminal event, and inter-event wall-clock gaps.
- [ ] Under `-n auto`, the Qdrant collection, Valkey DB, bucket, cache prefix and queue names all carry `worker_id`; the suite passes at `-n 4` and under `-p randomly` reordering.
- [ ] Container fixtures honour `KB_TEST_*` endpoints when CI supplies them, import from `testcontainers.community.*`, and pin image tags to the Compose `test` profile.
- [ ] Celery claims about serialization, retries, time limits or redelivery run against a real prefork worker; `grep -rn "task_always_eager" tests/` appears only in `unit/`.
- [ ] Provider adapters are exercised against the recorded-SSE fixture server, not a stub inside the adapter; cassettes cover non-streaming shapes only; no live provider call runs outside the scheduled tier.
- [ ] `-W error::DeprecationWarning` is on, with narrow per-module ignores only; no test freezes or reads wall-clock time without an injected or frozen clock.
- [ ] No Ragas metric appears in a per-push or merge-queue assertion.

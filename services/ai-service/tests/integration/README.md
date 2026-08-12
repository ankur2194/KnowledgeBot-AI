# `integration/` — real containers, real sockets

Marked `@pytest.mark.integration`. **Testcontainers is the only supported path**, in CI and
locally alike: `ci.yml`'s second ai-service step runs `-m integration` against the runner's own
Docker daemon, and the fixtures in `tests/conftest.py` start Postgres, Qdrant and Valkey
themselves. There is no workflow-`services:` tier, and this file used to say there was.

**Do not set `KB_TEST_PG_DSN`, `KB_TEST_QDRANT_URL` or `KB_TEST_VALKEY_URL`.** The
short-circuits for them in `tests/conftest.py` are real code written for a tier that does not
exist, and setting any of them today takes the whole suite down rather than redirecting it: they
carry the `KB_` prefix, so `check_environment` (`app/core/config.py`) rejects them as `KB_*`
names matching no setting, `get_settings()` raises, and every module calling it at import
— `app/worker/__init__.py:38` — fails to import. Measured 2026-08-11 with all three set:

```
$ KB_TEST_PG_DSN=… KB_TEST_QDRANT_URL=… KB_TEST_VALKEY_URL=… pytest -m "not integration"
RuntimeError: 3 KB_* environment variable(s) match no setting and are not recorded as a known gap
!!!!!! Interrupted: 4 errors during collection !!!!!!
42 deselected, 4 errors
```

That is the entire run, not four tests. Finding #81/#82.

Wiring those three up is a **decision, not an edit**, which is why this is a warning rather than
a fix. The exemption map they would go in — `EXTERNALLY_READ_VARIABLES` — means "deployment
configuration read at its point of use by a module that must not import `app.core.config`", and
`test_every_externally_read_variable_is_still_set` enforces that by requiring a `compose.yaml`
assignment or a Dockerfile reader for every entry. A test-harness variable has neither, so
admitting it means weakening that guard and permanently pre-authorising the `KB_TEST_*` spelling
inside every production container. `tests/unit/test_settings_dsn.py`'s
`test_the_kb_test_endpoint_overrides_are_rejected_by_check_environment` pins today's answer, so
this section fails loudly if someone changes it without changing the docs.

## May assert

- The four Qdrant filters **against a server**, atomic version publish, deletion plus its
  verification.
- SSE framing and inter-event gaps, and **the cancellation path** — on a real Uvicorn from
  `tests/support/live_server.py`, against `tests/support/fake_provider.py` on its own real socket.
- A real prefork Celery worker: payload JSON-serializability, `traceparent` survival, retry
  countdowns, `soft_time_limit` firing, redelivery after SIGKILL, `acks_late`.

## May **not** assert

- Provider behaviour (the provider is always faked) or browser rendering.

## The rules that make this tier worth running

1. **Never an in-process transport.** `ASGITransport` and `TestClient` both buffer the whole body
   and cannot deliver a mid-stream disconnect, so a streaming test written over either passes
   against an implementation that streams nothing.
2. **Assert on timing and chunking, not only on the assembled text.** A correct implementation and a
   fully buffered one produce identical final text. The observable difference is *when* the first
   token arrived, so that is what the assertion has to capture. Never assert a chunk *count* —
   Nagle merges writes, and the fixture sets `TCP_NODELAY` precisely so gaps survive.
3. **`cut()`, not `end()`.** A clean FIN is a normal end-of-stream and drives the completed-answer
   path. Only destroying the socket reproduces a vanished peer.
4. **Truncate, do not roll back.** A test asserting a row a Celery worker wrote from another process
   will find nothing if the test holds an open transaction. Commit before dispatching.
5. **Never `QdrantClient(":memory:")`.** Its fusion path ignores the root filter, so the leaky query
   is safe in memory and only leaks in production — exactly inverting what the test proves.

The cancellation test is the one that must exist and must assert all four: the provider socket
closed, tokens billed < tokens scripted, a span exported with `kb.finish_reason="cancelled"`, and a
usage row written from the shielded `finally`.

```
uv run pytest tests/integration -q -m integration
```

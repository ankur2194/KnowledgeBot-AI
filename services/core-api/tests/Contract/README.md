# `Contract/` — the Laravel ↔ FastAPI seam

Laravel's internal client validated against the OpenAPI document in `packages/contracts/`, and the
SSE relay validated against a real SSE fixture server.

## `Http::fake()` is banned in this directory

A faked response body is a **string**. The relay loop drains it in microseconds, so every buffering,
ordering, heartbeat and disconnect bug passes — and those are the only bugs this directory exists to
find. It is the PHP twin of `httpx.ASGITransport` and Playwright's `route.fulfill()`; all three
produce a green suite over a relay that streams nothing.

Streaming contract tests run against a fixture server that:

- writes events with **real delays between them**, so inter-event wall-clock gaps are assertable;
- emits `: ping` comments, which must never surface as events;
- can **hang up mid-stream by destroying the socket** — `res.end()` is a clean FIN and drives the
  completed-answer path, which is not the case under test.

## May assert

- The signed request: canonical string, `X-KB-*` header coverage, constant-time compare, 60 s skew,
  replay rejection of a repeated `X-KB-Request-Id`. A test that bypasses signing is not a contract
  test.
- Every required internal header present, and `400` when `X-KB-Org-Id` is absent.
- The error envelope relayed **verbatim** — `error_class` never re-derived from an HTTP status.
- Idempotency: same key/same body replays, same key/different body `422`, in-flight `409`.
- The relay: `citations` before the first `token`, exactly one terminal event, heartbeat presence,
  and usage finalized after a mid-stream client hangup.
- `provider_credential` is a **top-level** body field, excluded from the configuration hash and from
  every idempotency fingerprint — rotate only the key and assert `X-KB-Config-Version` and
  `X-KB-Idempotency-Key` are both unchanged.

## May **not** assert

- Retrieval quality, provider behaviour, or anything a browser renders.
- Anything requiring another process to read the database — that is `Integration/`.

```
./vendor/bin/pest --testsuite=Contract
```

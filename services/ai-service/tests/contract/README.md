# `contract/` — the wire, with fakes injected

`httpx.ASGITransport` plus `asgi_lifespan.LifespanManager`, with stub models injected. This is the
**only** tier where an in-process transport is legal, and `tests/unit/test_harness_guards.py`
enforces that by scanning for the identifier.

`ASGITransport` does not run lifespan at all, which is why the manager is mandatory: without it
nothing populates either state object, so every `/internal/v1` route renders 500 with
`origin=SELF` on a missing key ring and `/health/ready` answers 503 against a container that is
in fact fine.

_(This line used to read "without it `/health/ready` returns 200 while the embedder is `None`."
Both halves are dead: ADR-030 removed the embedder and the `embedding_model` check with it, and
the direction was backwards anyway — an unpopulated `request.state` yields `None` for every
probed client, and `None` is not ready. `tests/unit/test_api_state_split.py`'s
`test_from_request_state_returns_none_when_lifespan_never_ran` is the executable form.)_

## May assert

- HMAC verification: a valid signature, a tampered `X-KB-*` header, a skewed timestamp, a replayed
  `X-KB-Request-Id`. Signatures are produced by `tests/support/signing.py`, which signs **the exact
  bytes the test sends** — a signature computed over a re-serialization verifies by luck and 401s
  intermittently.
- The missing-header `400`: no `X-KB-Org-Id`, no default, never inferred from the body.
- The one error envelope, including `RequestValidationError` and the `errors` superset that only
  `validation` carries.
- Idempotency: same key + same body replays verbatim; same key + different body is `422`; in-flight
  is `409` with `Retry-After`.
- The emitted OpenAPI document matching `packages/contracts/`.

## May **not** assert

- **Inter-event timing.** The transport joins every `http.response.body` message into one chunk, so
  every gap in this tier is zero by construction.
- **Disconnect, cancellation, or anything downstream of it.** The transport yields `http.disconnect`
  only *after* `response_complete` is set, so a mid-stream hangup cannot be produced here at all —
  the shielded usage `finally`, the `CancelledError` propagation and the heartbeat are all
  unreachable. Those are `integration/`, on a real Uvicorn.
- **Anything that needs a real Qdrant.** Retrieval results and tenancy are not this tier's.

## Never

A test that bypasses signing is not a contract test. There is no `verify_hmac` skip flag to reach
for, and if one appears in the application that is the finding.

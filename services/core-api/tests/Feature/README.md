# `Feature/` — HTTP through the full stack

`RefreshDatabase`: one `BEGIN`/`ROLLBACK` per test. PostgreSQL, never SQLite — the schema depends on
`jsonb`, partial indexes, CHECK constraints and the composite foreign keys that guard
`bot_source_assignments`, and SQLite accepts what Postgres rejects.

## May assert

- Request → response for every public API surface: status, body shape, resource fields, validation
  errors keyed by input field name.
- Policy outcomes reached through a real route, with a real authenticated actor.
- Queue *dispatch* (`Queue::fake()` is legal here) and event dispatch.

## May **not** assert

- **Anything another process must see.** `RefreshDatabase` holds an open transaction, so those rows
  do not exist for any other connection and a worker's writes vanish on rollback. That test belongs
  in `Integration/`. Do not "fix" it by asserting on the job payload instead — that is how these
  tests stop testing anything.
- **Isolation.** A Feature test seeds whatever it needs, which is usually one organization. Isolation
  claims live in `Security/` and go through `tenantPair()`.
- **Streaming.** `Http::fake()` is a string body; the relay drains it instantly and every buffering,
  heartbeat, ordering and disconnect bug passes.

## Two habits that pay for themselves

**Freeze time.** `freezeTime()` or `travelTo(...)` in `beforeEach` for anything touching a
source-version activation window, an idempotency TTL, `Retry-After`, or a retention cutoff — so an
assertion never depends on which side of midnight CI ran.

**`recycle()`, not `for()`.** `for($orgA)` fixes one edge; every nested factory the definition
resolves still mints its own organization.

```
./vendor/bin/pest --testsuite=Feature
```

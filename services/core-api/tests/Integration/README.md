# `Integration/` — real containers, cross-process

The **only** suite on `DatabaseTruncation`, and that is not a style choice. `RefreshDatabase` wraps
each test in a transaction it rolls back, so the rows it wrote do not exist for any other connection
— a real queue worker, a Celery callback, or the SSE fixture server all see an empty database.
Truncation commits, at the cost of a `TRUNCATE` sweep per test. That cost buys exactly one thing:
**another process can see the rows.**

## Belongs here

- The async callback path — FastAPI calling back into `POST /internal/v1/callbacks/{group}`,
  including the `(job_id, sequence)` guard that stops a Celery retry rewinding a job.
- A **real** queue worker: `retry_after` versus `timeout` arithmetic, unique-job locks, overlap
  locks, `failed_jobs` rows.
- Any test whose assertion is made by the SSE fixture server rather than by the test process.
- The scheduler's `onOneServer` / `withoutOverlapping` locks, which silently do nothing without a
  shared cache lock.

## Does **not** belong here

The synchronous chat path. Laravel sends the whole resolved configuration snapshot in the request
body rather than having FastAPI read its tables, so nothing across the boundary needs to see a row.
That test is a `Contract/` test and should stay on the cheaper database strategy.

## Parallelism

`--parallel` tokenizes **the database only**. The Qdrant collection, the Valkey database, the
SeaweedFS bucket and the rate limiter are shared across workers unless
`ParallelTesting::setUpProcess` tokenizes them too. Never call `Cache::flush()` or `FLUSHDB` from a
test — it is another worker's state you are erasing, and the failure only ever appears in CI.

```
./vendor/bin/pest --testsuite=Integration    # merge-queue tier; needs the `test` Compose profile
```

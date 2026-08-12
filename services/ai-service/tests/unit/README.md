# `unit/` — no containers, no app, no network

The tier that still runs on the day a container is slow, which is the day it is needed.

## May assert

- `tenant_filter()` **construction** — the four terms, `must` and not `should`, empty scope raising.
- Chunk boundaries and chunk metadata shape.
- `error_class` classification and the taxonomy tables.
- Cost and token math; citation validation; URL and SSRF validation; deletion key selection.
- Pydantic model shape.
- Celery configuration arithmetic and the worker's registration set.
- The harness guards in `test_harness_guards.py` — the Definition-of-done greps, executable.

## May **not** assert

- **Anything about a query *result*.** A `Filter` object is not a search. `QdrantClient(":memory:")`
  is legal here and only here, because its fusion path ignores the root filter — which makes it
  useful for constructing filters and actively misleading for asserting on what a query returns.
- **Anything about a stream.** No transport lives here.
- **Tenancy.** A filter that was built correctly and never executed proves nothing about isolation.
  Isolation claims belong to `security/`, against two organizations and a real Qdrant.
- **Celery serialization, retries, time limits or redelivery.** `task_always_eager` is permitted in
  this tier and is never the evidence for any of those: eager execution skips the broker, so JSON
  serialization never runs, `acks_late` never happens, `request.delivery_info` is empty, and the
  time limits need SIGUSR1 and the prefork pool.

## Running

```
uv run pytest tests/unit -q          # per-push tier, no services required
uv run pytest tests/unit -q -n 4     # must also pass under xdist
```

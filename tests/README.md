# Testing KnowledgeBot AI

There is no test code in this directory. Tests live in the runtime that owns them —
`services/core-api/tests/`, `services/ai-service/tests/`, `apps/*/tests/`,
`packages/contracts/test/`. This file is the map: the four tiers, when each runs, and the exact
command per workspace.

---

## The two rules that everything else is arranged around

**1. Every in-process test double for streaming is a false green.**

`Http::fake()` returns a complete body. Playwright's `route.fulfill()` base64-encodes the whole
response into one CDP message and intercepts at the Request stage only, so it cannot chunk a
`text/event-stream`. `httpx.ASGITransport` joins every `http.response.body` message into one chunk
and yields `http.disconnect` only _after_ the response completed; `starlette.TestClient` does the
same through a `BytesIO`. MSW can stream but does not deliver a client `abort()` to the handler.

Each of them passes against an implementation that streams nothing and never notices a hangup —
which is precisely the bug the test was written to catch. **Streaming paths run against a real
socket:** a fixture server on a real port, a live Uvicorn.

| Runtime                   | The real socket                                                                                                     |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `apps/web`, `apps/mobile` | `apps/*/tests/fixtures/sse-server.ts` (twins; drift-checked by `packages/contracts/test/sse-fixture-drift.test.ts`) |
| `services/ai-service`     | `tests/support/live_server.py` for the app, `tests/support/fake_provider.py` for the provider                       |
| `services/core-api`       | the same TypeScript fixture server, started by the Contract suite                                                   |

Assert on **timing and chunking**, not only on the final assembled text. A correct implementation and
a fully buffered one produce identical text; the observable difference is _when_ the first token
arrived. Never assert a chunk _count_ — the fixture disables Nagle so gaps survive, but two frames
written back to back still legitimately arrive in one read.

**2. A one-organization fixture cannot fail an isolation test.**

With a single tenant there is nothing to leak, so the test passes with every tenant filter deleted.
Isolation suites use two organizations with overlapping but **distinguishable** data — never
`name: 'Test'` in both, which makes a leak invisible in the assertion — and they assert the positive
control **first**: the canary _is_ visible to Org B, then it is _not_ visible to Org A. Without that
first line a 500, an empty index, a broken export, or an error raised before the query all satisfy
"canary absent".

Entry points: `tenantPair()` (`services/core-api/tests/Support/tenancy.php`), the two-org fixtures in
`services/ai-service/tests/conftest.py`, and the worker-scoped `orgs` fixture in Playwright. There is
deliberately no single-organization helper in any runtime, and
`services/core-api/tests/Unit/TenantHarnessShapeTest.php` fails if one appears.

---

## The four tiers

| Tier            | Needs                                                         | Runs                            |
| --------------- | ------------------------------------------------------------- | ------------------------------- |
| **unit**        | nothing — no containers, no app, no network                   | per push                        |
| **contract**    | the app in-process, fakes injected, plus the OpenAPI document | per push                        |
| **integration** | real containers and real sockets                              | merge queue                     |
| **security**    | real containers                                               | **every tier, unconditionally** |

Where each tier lives:

| Tier        | `services/core-api`                 | `services/ai-service` | `apps/web` · `apps/widget`              | `apps/mobile` | `packages/contracts` |
| ----------- | ----------------------------------- | --------------------- | --------------------------------------- | ------------- | -------------------- |
| unit        | `tests/Unit/`, `tests/Arch/`        | `tests/unit/`         | `tests/unit/`, `tests/components/`      | `tests/`      | `test/`              |
| contract    | `tests/Contract/`, `tests/Feature/` | `tests/contract/`     | —                                       | —             | `test/`              |
| integration | `tests/Integration/`                | `tests/integration/`  | `tests/e2e/`                            | —             | —                    |
| security    | `tests/Security/`                   | `tests/security/`     | `tests/e2e/` (browser-only §22.5 cases) | —             | —                    |

**Scheduled tier**, nightly and never gating a PR: RAG quality scoring against `samples/`
(`ragas-evaluation`), live-provider smoke calls, the ADR-010 Qdrant rebuild drill, and
`--order-by=random` / `-p randomly` reordering runs. A judged metric is a model result, not an
assertion; it never appears in a per-push or merge-queue check.

### The security tier is never test-impact-analysis gated

Pest 5's impact analysis follows PHP call graphs and pytest's follows imports. The dependencies that
break isolation cross a process boundary into FastAPI, Qdrant, Valkey and object storage, which
neither can see — a change that opens a cross-tenant hole can leave every call graph untouched. So
`tests/Security/`, `tests/security/` and `tests/Arch/` run on **every** invocation in **every** tier.
Impact analysis is a local-iteration accelerator only.

---

## Commands, per workspace

### Node workspaces — always `--filter`, never `-r`

```bash
pnpm --filter @kb/contracts test          # the frame parser, the error envelope, the twin drift check
pnpm --filter @kb/web       test          # vitest run  (projects: unit + components)
pnpm --filter @kb/web       test:unit     # the node project alone — the SSE read loop
pnpm --filter @kb/web       e2e           # playwright; needs next build && next start
pnpm --filter @kb/widget    test
pnpm --filter @kb/widget    e2e           # two origins, served by the harness in tests/harness/
pnpm --filter @kb/mobile    test          # jest
```

**Never `pnpm -r test`.** Four concrete reasons, any one of which is enough:

1. It fans out to `@kb/design-tokens`, which has no `test` script, and to browser-mode suites on
   machines with no browsers installed — so "the suite passed" and "the suite ran" stop being the
   same statement.
2. The runners are different (Vitest, Jest, Playwright) and their sharding flags are different. CI
   shards each workspace independently; `-r` cannot express that and silently runs everything on one
   worker.
3. `@kb/web` and `@kb/widget` need `@kb/contracts` **built**, not just present. Recursive execution
   orders by dependency graph for `build`, and the ordering guarantee people assume it gives `test`
   is not one they should be relying on.
4. Output interleaves and the exit code is one number. When it goes red, the first question is
   always "which workspace", and `-r` is the one form that does not answer it.

The root `package.json` carries `web:test`, `contracts:test` and friends — each of which is a
`--filter` invocation, for exactly this reason.

### PHP — `services/core-api`

```bash
./vendor/bin/pest --testsuite=Unit          # + Arch; no database
./vendor/bin/pest --testsuite=Feature
./vendor/bin/pest --testsuite=Contract
./vendor/bin/pest --testsuite=Security      # every tier, every run
./vendor/bin/pest --testsuite=Integration   # needs the `test` Compose profile
./vendor/bin/pest --parallel --processes=4  # tokenize Qdrant/Valkey/cache in ParallelTesting
```

PostgreSQL, never SQLite: the schema depends on `jsonb`, partial indexes, CHECK constraints and the
composite foreign keys that guard `bot_source_assignments`, and SQLite accepts what Postgres rejects.

### Python — `services/ai-service`

```bash
uv run pytest tests/unit -q                 # no containers; also must pass under -n 4
uv run pytest tests/contract -q
uv run pytest tests/integration -q -m integration
uv run pytest tests/security -q -m security
```

Container fixtures short-circuit on `KB_TEST_QDRANT_URL`, `KB_TEST_PG_DSN` and `KB_TEST_VALKEY_URL`
when CI provides the services, and fall back to testcontainers locally with image tags pinned to the
Compose `test` profile.

---

## Bringing the dependencies up

```bash
docker compose --profile test up -d        # postgres-test, qdrant-test, valkey-test, the fixture origins
```

Never `sleep`-then-connect. `depends_on: condition: service_healthy` with real healthchecks is what
turns "connection refused on the first CI run, green on the rerun" into a startup that either works
or fails for a readable reason.

---

## CI

No workflows are committed this round. When they land, the shape they must have:

- `unit` + `contract` on every push, per runtime, sharded.
- `integration` in the merge queue with Postgres, Qdrant and Valkey as workflow `services:`.
- `security` in **both**, unconditionally, never impact-gated.
- Quality scoring, live-provider smoke, the rebuild drill and randomized-order runs on a schedule.
- The slow job must be **required**, not merely present. A merge-queue job nobody blocks on is a job
  that has already stopped running.

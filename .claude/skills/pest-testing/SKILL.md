---
name: pest-testing
description: Pest 5 conventions for the Laravel control plane in services/core-api/tests/. Use whenever writing a Feature, Contract, Security or arch test, a model factory, a dataset, or the CI test job — and whenever a test flakes or passes in CI while staging leaks. A one-organization fixture cannot fail an isolation test and Http::fake() cannot fail an SSE test; the harnesses for both live here. Pairs with kb-tenancy-isolation (the contract this proves) and laravel-rbac-policies (the layer it exercises).
---

# Pest Testing — Laravel Control Plane

Pest **5.0.3** (requires PHP ≥ 8.4, PHPUnit ^13.2.6) with `pestphp/pest-plugin-laravel` **5.0.1** (requires `laravel/framework ^13.23`), on Laravel **13.24** / PHP 8.4. Tests live in `services/core-api/tests/`.
Python tests are `pytest-ai-service`; browser and component tests are `vitest-playwright`. RAG **quality** scoring is `ragas-evaluation` and is a different activity from correctness testing — a faithfulness score moving is a model result, not a failed assertion, and it gates in its own pipeline step (docs/18 §26.10). Never put a judged metric in this suite.
**Authoritative spec:** docs/17-testing-performance.md §22.1–22.6, docs/13-security.md §18.4, docs/18-deployment-backup-cicd.md §24.5 §26

## Non-negotiables

1. **An isolation test that seeds one organization is not an isolation test.** With a single tenant there is nothing to leak, so the test passes against code with no filter at all. Every §22.5 case runs `tenantPair()` — two organizations, a per-test canary planted in **Org B's** content, asserted absent from Org A's result (`kb-tenancy-isolation`). There is deliberately no single-org helper to reach for.
2. **Every isolation assertion carries a positive control.** Assert the canary *is* returned to Org B before asserting it is not returned to Org A. A suite without this goes green the moment retrieval, export, or the analytics aggregate breaks entirely — which is how an isolation suite becomes decoration.
3. **No test may weaken the tenant filter, and no bypass exists to be called.** `kb-tenancy-isolation` NN4: no env flag, no `internal=true`, no fixture that disables scoping. If a test is hard to write without one, the production code is wrong.
4. **`Http::fake()` is banned on the chat relay path.** A faked body is a string, so the relay drains instantly and every buffering, heartbeat, ordering and disconnect bug passes (`kb-internal-api-contracts`). Streaming contract tests run against a real SSE fixture server that delays between events and can hang up mid-stream.
5. **Arch tests are suite members, not a nicety.** The doctrine no runtime test can observe — layering, `env()`, debug helpers, who may call the AI service — is encoded in `arch()` and fails CI. See the list below; it is the highest-leverage content in this file.
6. **PostgreSQL, never SQLite.** The schema depends on `jsonb`, partial indexes, CHECK constraints and the composite foreign keys that guard `bot_source_assignments` (`kb-tenancy-isolation` NN2, `postgresql-patterns`). SQLite accepts what Postgres rejects, so a green suite proves nothing about the constraint that is the whole point.

## How we use it

### Layout and `tests/Pest.php`

`Unit/` (no framework boot, mock the repository interface — `laravel-control-plane`), `Feature/` (HTTP through the full stack), `Contract/` (Laravel↔FastAPI against the OpenAPI document in `packages/contracts/`), `Security/` (the §22.5 suite), `Integration/` (real containers, cross-process), `Arch/`.

```php
// services/core-api/tests/Pest.php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Security', 'Contract');

// Integration is the only suite that pays for committed data — see the verdict below.
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseTruncation::class)
    ->in('Integration');

pest()->use(Illuminate\Foundation\Testing\WithCachedConfig::class)->in('Feature', 'Security');
// Unit/ extends nothing: no container, no database, no facades. If a unit test needs one, it is a feature test.
```

### Database strategy — the verdict

**`RefreshDatabase` everywhere except `Integration/`.** On Laravel 13 it migrates only when the schema is stale and otherwise wraps each test in a transaction it rolls back — one `BEGIN`/`ROLLBACK` per test. `DatabaseMigrations` re-runs every migration per test and turns a 90 s suite into minutes; it is never the right answer here. `DatabaseTruncation` commits and then truncates, costing a `TRUNCATE` sweep per test, and is the price of admission for exactly one thing: **another process must see the rows.** That is the async callback path (`kb-internal-api-contracts` — Celery calls back into Laravel), a real queue worker (`laravel-queues-valkey`), and any test whose assertion is made by the SSE fixture server. The synchronous chat path does *not* need it, because Laravel sends the whole resolved configuration snapshot in the request body rather than having FastAPI read its tables.

**Parallelism.** `php artisan test --parallel --processes=N` (needs `brianium/paratest`) creates `kb_test_1 … kb_test_N` automatically and appends the token. It does that for **the database only**. Qdrant collections, Valkey databases, SeaweedFS buckets and the rate limiter are shared across workers, and that is the parallel hazard that bites — see gotchas. Tokenize them yourself in `AppServiceProvider::boot()`:

```php
ParallelTesting::setUpProcess(fn (int $token) => config([
    'services.qdrant.collection' => "kb_test_{$token}",
    'database.redis.default.database' => 8 + $token,
    'cache.prefix' => "kb_test_{$token}_",
]));
```

### The two-org canary harness

This is the centrepiece: the helper exists so that writing a leaky test takes more effort than writing a correct one.

```php
// services/core-api/tests/Support/tenancy.php  (autoloaded from tests/Pest.php)
function tenantPair(): TenantPair
{
    $canary = 'CANARY-'.Str::ulid();   // fresh per test — a stale index can never satisfy it

    [$a, $b] = Organization::factory()->count(2)->create()->all();

    // recycle() pins ONE organization for every relation these factories resolve.
    // Without it BotFactory's nested KnowledgeSource::factory() mints a THIRD org and
    // the assertion below passes for a reason that has nothing to do with the filter.
    $botA = Bot::factory()->recycle($a)->create();
    $botB = Bot::factory()->recycle($b)->create();

    // ->indexed() drives the real ingestion path into the test Qdrant container.
    // QdrantClient(":memory:") ignores the root filter (kb-tenancy-isolation) — never use it here.
    KnowledgeSource::factory()->recycle($b)->assignedTo($botB)
        ->indexed("Refunds are accepted for 30 days. {$canary}")->create();

    return new TenantPair(
        a: $a, b: $b, botA: $botA, botB: $botB, canary: $canary,
        actorA: User::factory()->recycle($a)->orgRole(OrgRole::Admin)->create(),
        actorB: User::factory()->recycle($b)->orgRole(OrgRole::Admin)->create(),
    );
}

// services/core-api/tests/Security/CrossTenantTest.php   (§22.5)
it('never exposes another organization\'s content', function (string $surface) {
    $t = tenantPair();

    // POSITIVE CONTROL, FIRST (NN2). Without this line the test also passes when the
    // surface is broken and returns nothing at all.
    expect(readSurface($surface, $t->b, $t->botB, $t->actorB))->toContain($t->canary);

    // The real assertion. readSurface() returns the raw response body, so a canary hiding
    // in a citation title, an export cell, or a cached completion still trips it.
    expect(readSurface($surface, $t->a, $t->botA, $t->actorA))->not->toContain($t->canary);
})->with('tenant surfaces');

// Every surface kb-tenancy-isolation's Definition of done names. Adding a seventh surface
// to the product without adding it here is meant to look conspicuously incomplete.
dataset('tenant surfaces', [
    'chat answer and citations', 'retrieval diagnostics', 'source list',
    'analytics aggregate', 'CSV export', 'warm answer cache',
]);
```

`KnowledgeSourceFactory` also ships a **`crossOrg()`** state that deliberately assigns a source from one org to a bot in another — the one row that can span two organizations. It is used only to assert the service **and** the composite FK reject it. That state is the reason `assignedTo()` never quietly infers an org.

### Architecture tests that encode the non-negotiables

```php
// services/core-api/tests/Arch/DoctrineTest.php
arch()->preset()->php();        // die, var_dump, deprecated functions
arch()->preset()->security();   // eval, md5/sha1, uniqid, mt_rand, extract — kb-security-baseline
arch()->preset()->laravel();

arch('clients never reach FastAPI, so only one class may call it')   // kb-architecture-map NN3
    ->expect('Illuminate\Support\Facades\Http')->toOnlyBeUsedIn('App\Services\Internal')
    ->and('App\Services\Internal\InternalAiClient')->toOnlyBeUsedIn('App\Services');

arch('a controller cannot touch a model, so it cannot skip a policy or a scope')
    ->expect('App\Models')->toOnlyBeUsedIn(['App\Repositories\Eloquent', 'App\Policies', 'Database']);

arch('every policy is org-scoped by construction')                   // laravel-rbac-policies
    ->expect('App\Policies')->toHaveSuffix('Policy')->toExtend(App\Policies\OrgScopedPolicy::class);

arch('raw query builders stay where the org scope is applied')       // kb-tenancy-isolation
    ->expect('Illuminate\Support\Facades\DB')->toOnlyBeUsedIn('App\Repositories\Eloquent');

arch('env() is read once, in config')      // config/ holds no classes, so it is not scanned
    ->expect('env')->not->toBeUsed();

arch('no credential type can reach a response body')                 // kb-security-baseline
    ->expect('App\Http\Resources')->not->toUse(['App\Support\Crypto\KeyVault', 'decrypt']);

arch('no test-only bypass exists to be called')                      // kb-tenancy-isolation NN4
    ->expect('Tests')->not->toBeUsedIn('App');   // Tests\ must be in composer autoload-dev to be scanned <!-- UNVERIFIED -->
```

Two rules resist `arch()` because they are string-level, and stay greps in CI: `withoutGlobalScopes(` with no arguments, and any literal `ai-api`/internal host outside `App\Services\Internal`. One resists it because it is an attribute, so it is a reflection test instead — and it is the one that catches a *new* model, the case a reviewer is least likely to notice:

```php
test('every org-owned model carries the organization scope', function (string $class) {
    expect((new ReflectionClass($class))->getAttributes(ScopedBy::class))->not->toBeEmpty();
})->with(fn () => orgOwnedModels());   // reflects over App\Models for an organization_id column
```

### What must never be faked

**SSE** (NN4). **The tenant filter** (NN3). **Time** — never leave it to wall-clock: `freezeTime()` or `travelTo(CarbonImmutable::parse('2026-08-04 09:00:00'))` in `beforeEach` for anything touching a source-version activation window, an idempotency TTL, `Retry-After`, or a retention cutoff, so the assertion does not depend on which side of midnight CI ran. **Provider calls** are the opposite: never live in CI — the fake provider adapter of docs/18 §24.6, plus recorded responses for the adapter contract tests (§22.2).

## Gotchas

- **The isolation suite is green and staging leaks.** One of three: the fixture seeded one organization, the vector assertion ran against `QdrantClient(":memory:")` whose fusion path ignores the root filter, or the AI service was `Http::fake()`d so no filter ever executed. `tenantPair()` plus the real `test` Compose profile fixes all three (`kb-tenancy-isolation`).
- **The isolation suite is green because *everything* returns nothing.** A broken export, an empty index, or a 500 swallowed by `assertOk` absent from the test all satisfy "canary not present". The positive control is the only defence, and it is the assertion people delete first when it is slow.
- **A canary from worker 2 turns up in worker 1's assertion; the rate-limit test fails only in CI.** Laravel tokenizes the *database* under `--parallel` and nothing else, so all workers share one Qdrant collection, one Valkey database, and one cache prefix. Tokenize them in `ParallelTesting::setUpProcess`, and never call `Cache::flush()` or `FLUSHDB` in a test.
- **A queue or callback test asserts a row a second process wrote, and finds nothing.** `RefreshDatabase` holds an open transaction; that data does not exist for any other connection, and the worker's writes vanish on rollback. Move the test to `Integration/` (`DatabaseTruncation`) — do not "fix" it by asserting on the job payload instead, which is how these tests stop testing anything.
- **A factory silently creates a third organization.** `Bot::factory()->for($orgA)` fixes one edge; every *nested* factory the definition resolves still mints its own org. `recycle($orgA)` is what pins them all. Symptom: an isolation test that passes with the filter deleted.
- **The SSE test passes and production delivers the whole answer in one chunk.** Test clients read the full body, so buffering is invisible. Assert inter-event wall-clock gaps, exactly one terminal event, heartbeat presence, and correct usage finalization after a mid-stream hangup (`kb-internal-api-contracts`).
- **The suite passes alone and fails in a full run, or passes only in file order.** Shared Valkey/cache state, a static counter, or a `travelTo` never rolled back. Run `--order-by=random` in a nightly job; ordering dependence found on a Friday costs an hour, found during an incident costs the afternoon.
- **Coverage reads 92% and the tenant scope has no meaningful test.** Line coverage counts execution, not assertion. Run mutation testing narrowly where a false-green is most expensive: `--mutate --covered-only --min=90` scoped with `mutates(App\Policies\...::class)` over policies and repository scopes. Do not gate the whole codebase on a mutation score; it will be turned off.
- **A secret-redaction test passes because the key was never used.** Same failure shape as the isolation control: assert the fixture credential actually reached a provider call, *then* assert it appears in no log line, no audit `details`, and no response body (`kb-security-baseline`).
- **Pest 5's Test Impact Analysis reruns nothing after a change that breaks isolation.** Impact analysis follows PHP call graphs; our riskiest dependencies cross a process boundary into FastAPI and Qdrant, which it cannot see. Run `Arch/` and `Security/` unconditionally on every CI run, and let TIA accelerate only local iteration. <!-- UNVERIFIED: TIA's exact opt-in flag and its cache invalidation rules were not read from the CLI reference -->
- **`connection refused` on the first CI run, green on the rerun.** The test job started before Qdrant/Valkey/Postgres were accepting connections. Use `depends_on: condition: service_healthy` with real healthchecks and shard with `--shard=1/4` (time-balanced from `tests/.pest/shards.json`) — never a `sleep`, whose value is always tuned to the machine that had the problem last.
- **`assertDatabaseHas` on an encrypted column never matches.** The column holds ciphertext with a per-credential data key, so the plaintext is not in the row. Assert through the model accessor, and assert the *masked* form on the API Resource.

## Official docs

- [Pest — Arch testing](https://pestphp.com/docs/arch-testing), [Datasets](https://pestphp.com/docs/datasets), [Configuring tests](https://pestphp.com/docs/configuring-tests), [Optimizing tests](https://pestphp.com/docs/optimizing-tests), [Mutation testing](https://pestphp.com/docs/mutation-testing)
- [Pest 5 announcement](https://pestphp.com/docs/pest5-now-available) and [Upgrade guide](https://pestphp.com/docs/upgrade-guide) — PHP 8.4 / PHPUnit 13 floor, plugin version bumps.
- [Laravel — Testing: Getting Started](https://laravel.com/docs/13.x/testing) — `--parallel`, `ParallelTesting` hooks, `WithCachedConfig`, coverage `--min`.
- [Laravel — Database Testing](https://laravel.com/docs/13.x/database-testing) — `RefreshDatabase` vs `DatabaseMigrations` vs `DatabaseTruncation`, `#[Seed]`.
- [Laravel — Eloquent Factories](https://laravel.com/docs/13.x/eloquent-factories) — `recycle()`, `for()`, `has()`, sequences.
- [Laravel — Mocking & time travel](https://laravel.com/docs/13.x/mocking) — `freezeTime()`, `travelTo()`.
- [PHPUnit 13 changelog](https://github.com/sebastianbergmann/phpunit/blob/13.0.0/ChangeLog-13.0.md) — behaviour Pest 5 inherits.

## Definition of done

- [ ] Every §22.5 case exists and runs: cross-tenant API **and** vector access, CORS/origin, SSRF payloads, malicious filenames, oversized files, prompt-injection samples, XSS in source content, secret redaction, rate limits.
- [ ] Every isolation test calls `tenantPair()`, uses a per-test canary, and asserts the positive control before the negative one. `grep -rn "Organization::factory()->create()" tests/Security` returns nothing.
- [ ] The isolation suite runs against the real `test` Compose profile (Postgres, Qdrant, Valkey, ai-api); `Http::fake()` appears nowhere under `tests/Security` or `tests/Contract`.
- [ ] Streaming contract tests hit a real SSE fixture server and assert inter-event gaps, one terminal event, heartbeat, and mid-stream hangup finalization.
- [ ] `tests/Arch/DoctrineTest.php` contains all three presets plus the seven project rules above; the `ScopedBy` reflection test covers every org-owned model; CI greps for `withoutGlobalScopes(` and hard-coded internal hosts.
- [ ] `RefreshDatabase` for `Feature`/`Security`/`Contract`; `DatabaseTruncation` only in `Integration`; no SQLite connection in `phpunit.xml`.
- [ ] Under `--parallel`, Qdrant collection, Valkey database and cache prefix are token-scoped; the suite passes at `--processes=4` and under `--order-by=random`.
- [ ] Every factory for a tenant-owned model is `recycle()`-safe; a test asserts a `crossOrg()` assignment is rejected by both the service and the database.
- [ ] Time-dependent tests freeze time explicitly; no assertion depends on the current date.
- [ ] `./vendor/bin/pest --mutate --covered-only --min=90` passes over `App\Policies` and the repository tenant scopes.

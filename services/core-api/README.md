# core-api — KnowledgeBot AI control plane

Laravel 13 / PHP 8.4. This service owns **all** authentication, authorization, rate limiting, quota
accounting and credential handling. FastAPI trusts its caller by design, so a check skipped here is
a check that does not exist anywhere.

**Status: skeleton.** Real manifests, real configuration, the framework files, and an empty-but-correct
app tree. No models, no controllers, no migrations, no policies, no jobs yet.

---

## Shape

```
app/
├── Console/Commands/          kb:dump-form-rules (the only command that exists today)
├── Http/{Controllers/{Api/V1,Internal},Middleware,Requests,Resources}
├── Jobs/                      every job: ShouldQueue + TracingLinked + ShouldBeEncrypted, ULIDs only
├── Models/{,Scopes}           every org-owned model carries #[ScopedBy(OrganizationScope::class)]
├── Policies/                  every policy extends OrgScopedPolicy
├── Providers/AppServiceProvider.php
├── Repositories/{Contracts,Eloquent}   the ONLY place DB:: and Eloquent may be used
├── Services/                  use cases: ChatGate, ConfigSnapshotResolver, StreamFinalizer
├── Services/Internal/         InternalAiClient + UpstreamStream — the ONLY callers of ai-api
└── Support/{Tenancy,Kb}       TenantContext (set per request, cleared in a finally)
```

Work outside-in: **route → middleware → FormRequest → Policy → Service → Repository → Model →
Resource.** The FormRequest validates shape, the Policy decides permission, the Service decides
behaviour, the Resource shapes output. An Eloquent model is never returned raw.

`app/Services/Internal/` holds `InternalAiClient` and `InternalRequestSigner` — the only classes
permitted to open a connection to `ai-api`. Two enforcement rules point at that namespace:
`tests/Arch/DoctrineTest.php` pins the `Http` facade to it, and `tests/Arch/StringLevelDoctrineTest.php`
pins the literals `ai-api` / `services.ai.url` to it by scanning the token stream.

This paragraph used to say the directory "is empty today" and that the second rule was a **CI grep**.
Both are false now: the directory has been populated since 2026-08-19, and there is no CI —
`.github/` was deleted on 2026-08-17 and the greps became the Pest arch tests named above.

---

## Four route groups, and why there is no `routes/api.php`

| File | Prefix | Middleware group | Surface | Deny |
|---|---|---|---|---|
| `routes/api_admin.php` | `api/v1` | `api` (+ `statefulApi`) | admin | **403** |
| `routes/api_public.php` | `rt/v1` | `runtime` | public_runtime | **404** |
| `routes/api_sdk.php` | `sdk/v1` | `sdk` | sdk | **404** |
| `routes/internal.php` | `internal/v1` | `internal` | internal | 403 |

One shared `api.php` is exactly how a public runtime route ends up inheriting the admin group's
session, cookie and CSRF stack. Only the admin group is the `api` group, because `statefulApi()`
prepends `EnsureFrontendRequestsAreStateful` to `api` and no public surface may acquire ambient
session authority from a stateful `Origin`.

`rt/*` and `sdk/*` sit **outside** `api/`, and `config/cors.php` lists all three explicitly —
Laravel 11+ leaves that file unpublished with `paths` defaulting to `['api/*', 'sanctum/csrf-cookie']`,
so an SDK route anywhere else gets no CORS headers, the browser rejects the preflight, and the
server log is empty.

`routes/web.php` holds exactly one route. `GET /up` is registered by `withRouting(health: '/up')`
in `bootstrap/app.php` and is **not** duplicated there.

---

## Configuration — twelve files, none stock

Everything else uses the framework default and is not published, because an unread config file is a
lie about what is configured. In particular `broadcasting.php`, `mail.php`, `hashing.php` and
`view.php` are deliberately absent.

| File | The value that breaks something if it moves |
|---|---|
| `app.php` | `timezone` is the literal `'UTC'`, not an env read. No `schedule_timezone`. |
| `auth.php` | four client classes, four mechanisms, no fifth. |
| `cache.php` | default store `valkey` (noeviction). `WithoutOverlapping` cannot be pointed anywhere but the default store. |
| `cors.php` | published; `rt/*` and `sdk/*` listed; `supports_credentials` true; exact origins. |
| `database.php` | `pgsql`; `redis.client=phpredis`; **no** `options.serializer`, **no** `options.compression`. |
| `filesystems.php` | SeaweedFS S3, `use_path_style_endpoint` true. |
| `horizon.php` | supervisor `timeout` **is** the `--timeout` of the queue arithmetic: 120 / 1500. |
| `kb.php` | timeout budgets, the backoff ladder, the four surfaces — each quoted from one skill, once. |
| `logging.php` | stdout JSON. No daily files in a container. |
| `queue.php` | two connections, `retry_after` 180 / 1800, `after_commit` true on both. |
| `sanctum.php` | `expiration => null`, `last_used_at => false`, exact stateful hosts with ports. |
| `services.php` | `services.ai.url`, the HMAC key map (two ids live during rotation), Qdrant collection. |
| `session.php` | cookie domain scoped to the admin/API hosts only — never `.<domain>`. |

> The brief called for "exactly twelve"; the enumerated list contains thirteen names and all thirteen
> ship. Flagged rather than resolved by dropping one.

### The one arithmetic

```
--timeout  <  retry_after  <  lock TTL          stop_grace_period > --timeout

valkey       supervisor timeout  120 <  retry_after  180 < expireAfter/UniqueFor >= 240
valkey-long  supervisor timeout 1500 <  retry_after 1800 < expireAfter/UniqueFor >= 1900
```

Under Horizon there is no `queue:work` process to pass `--timeout` to — the supervisor owns it, so
the left-hand side lives in `config/horizon.php` and the middle in `config/queue.php`.

---

## Running it

**Prerequisites: PHP 8.4.1 or newer, and Composer 2, on the host.** `composer.json` requires
`php: ^8.4.1` and `config.platform.php` pins `8.4.1`, and
Composer refuses to resolve `composer.lock` on anything older — there is no `--ignore-platform-reqs`
escape hatch that produces a working install, because the pinned Laravel 13 and PHPUnit 13 need 8.4
at runtime, not just at resolve time. **Without PHP 8.4:** `make up`, then run every command below
inside the container — `cd infrastructure/docker && docker compose exec laravel-api <command>`. This
is the common case on a developer machine; the distribution PHP on Ubuntu 20.04 LTS is 7.4.

`composer.lock` is committed, so `composer install` resolves. The commands below are what CI runs.

```bash
composer install
php artisan key:generate
php artisan migrate                       # six migrations: tenancy, providers, BM25 statistics

composer lint                             # pint --test
composer stan                             # phpstan, level 8, no baseline
composer test                             # pest
composer test:par                         # --parallel --processes=4
composer test:arch                        # runs unconditionally in CI
composer test:security                    # runs unconditionally in CI
composer test:random                      # --order-by=random, nightly
composer dump:form-rules                  # -> packages/contracts/rules/*.json
composer ci                               # lint + stan + arch + test
```

`php artisan kb:dump-form-rules --check` is the CI gate: it exits non-zero when a FormRequest's
rules no longer match the committed contract. Two FormRequests exist today and both have a
committed counterpart in `packages/contracts/rules/`, so the gate has something to compare and is
no longer vacuous.

### Notes on the toolchain

- **`artisan` is excluded from Pint** (`notPath`). The `declare_strict_types` fixer would insert the
  declaration after a shebang line, which is only tolerated because the CLI SAPI strips it —
  not a property worth depending on.
- **There is no `phpstan-baseline.neon`, and its absence is the point.** On an empty tree there is
  nothing to baseline; once one exists, every future violation has a two-second fix and the ratchet
  stops being a ratchet. Suppress a genuine false positive at the line, with a reason.
- **PostgreSQL in every suite, never SQLite.** The schema depends on `jsonb`, partial unique indexes,
  CHECK constraints and composite foreign keys — SQLite accepts what PostgreSQL rejects, so a green
  SQLite suite proves nothing about the constraint that is the point.
- **`brianium/paratest` is pinned to a placeholder `^9.0`** matched to PHPUnit 13. Verify it at the
  first `composer require --dev` and correct the constraint. Never `"*"`.

---

## Things that are load-bearing and look like style

- `LARAVEL_START` in `public/index.php` and `artisan`. `X-KB-Deadline` is an **absolute** epoch-ms
  instant derived from it; a fresh `microtime()` inside the client restarts the budget downstream.
- `ignore_user_abort(true)` and the `: ping` heartbeat in the SSE relay. PHP learns the client is
  gone only when a **write fails**, so the heartbeat is the disconnect probe — and with
  `ignore_user_abort` at its default, the abort skips `finally` and usage is never finalized.
- The relay is `response()->stream()`, **not** `eventStream()`: `: ping` is an SSE *comment* and
  `eventStream()` can only emit named events.
- Middleware priority puts `TenantContext` **before** `SubstituteBindings`. Registered after it,
  bindings resolve with no tenant context and the route 404s — which reads as a routing bug.
- `database/ci/enable-rls.sql` sets **`FORCE ROW LEVEL SECURITY`**. Without it the table owner
  bypasses every policy silently, `pg_policies` still looks reassuring, and the tripwire is
  permanently green while proving nothing. There is **no RLS in production**; this is a CI detector.
- `tests/Support/tenancy.php` has no single-organization helper and will not get one. A one-org
  fixture cannot fail an isolation test.

---

## Boundaries

This service does not touch `services/ai-service/`, `apps/`, `infrastructure/`, `packages/`,
`samples/` or `scripts/`. The one exception is `kb:dump-form-rules`, which writes generated JSON
into `packages/contracts/rules/` — a directory owned by `admin-web-engineer` and never created
speculatively from here.

Deletion paths inside this service are written by `deletion-engineer`, because deletion's two phases
live on opposite sides of the Laravel/FastAPI seam.

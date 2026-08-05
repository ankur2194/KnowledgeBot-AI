---
name: github-actions-pipeline
description: GitHub Actions CI/CD for the KnowledgeBot monorepo — the PHP + Python + Node job graph, containerized test dependencies, three package-manager caches, and the gates CI owns, not review (tenancy greps, /metrics catalog diff, form-rules manifest, size-limit budgets, the CI-only RLS tripwire). Use whenever editing .github/workflows/, adding a job, cache key, required check, or artifact hand-off, or when a fork PR dies on an empty secret. Pairs with security-scanning-toolchain (which scanners run where).
---

# GitHub Actions Pipeline

Runners `ubuntu-24.04` (= `ubuntu-latest` today) · `actions/checkout@v7` · `setup-node@v7` · `setup-python@v7` · `cache@v6` · `upload-artifact@v7` ↔ `download-artifact@v8` · `shivammathur/setup-php@v2` · `pnpm/action-setup@v6` · `astral-sh/setup-uv@v9` · `docker/{login@v4,setup-buildx@v4,build-push@v7}` · `actions/attest-build-provenance@v4`.
**Authoritative spec:** docs/18-deployment-backup-cicd.md §26, docs/17-testing-performance.md §22, docs/19-repo-structure-adrs.md §27, docs/05-tech-stack.md §9.16

## Non-negotiables

- **No secret reaches a fork PR job, and none is needed for a green PR.** GitHub passes no repository secret to a `pull_request` run from a fork and hands it a read-only `GITHUB_TOKEN`. A self-hostable product gets fork PRs, so every gate on the required-check list must run without credentials. Provider keys, registry pushes and OIDC exchanges live in `merge_group`/`push` jobs guarded by `if: github.event.pull_request.head.repo.fork != true`. Never `pull_request_target` — it runs privileged against the base ref with secrets in scope (`kb-security-baseline`).
- **The tenancy, metric-catalog, form-rules and size-limit gates are required checks, not reports.** Earlier waves moved these out of review deliberately. A gate that runs with `continue-on-error: true`, behind `|| true`, or on a non-required workflow is the same as deleting it.
- **Ragas never gates a unit suite.** Evaluation is LLM-judged quality scoring with real provider calls, its own quota and its own error taxonomy (`ragas-evaluation`). It runs in its own scheduled job against `samples/` only. A faithfulness dip must not block an unrelated PR. §26 lists it as pipeline step 10 in a linear sequence — do not read that as "in the same job as tests".
- **Test jobs never call a live provider.** The fake adapter (docs/18 §24.6) and recorded responses cover adapter contract tests; live smoke tests are a scheduled job (`kb-provider-adapter-contract`).
- **No credential, DSN, or KEK is echoed by a workflow.** Actions redacts registered secrets in logs, not values you derive from them (`kb-security-baseline`).

## How we use it

**Three trigger tiers.** The matrix is PHP + Python + Node across `apps/{web,widget,mobile}`, `services/{core-api,ai-service}`, `packages/contracts` — too wide to run whole on every push.

| Tier | Trigger | Jobs |
|---|---|---|
| Per push / PR | `pull_request`, `push: main` | format, static analysis, ESLint, unit + arch + contract tests per workspace, all enforcement greps, contract diffs, widget build + `size`, `next build`. No secrets, no containers beyond the test services. |
| Merge queue | `merge_group: [checks_requested]` | Integration tests against real Postgres/Qdrant/Valkey/SeaweedFS, Playwright E2E shards against `next build && next start`, image build + container CVE scan. |
| Scheduled | `schedule`, `workflow_dispatch` | Ragas regression, Pest mutation (`--mutate --min=90`), `--order-by=random`, provider live smoke, Qdrant rebuild-reproduction check, the nightly security sweep. |

Which scanners run in which tier, and which of them may block, is `security-scanning-toolchain`'s call — it also puts SBOM and the licence gate in the release job, not the merge queue. Deploy (§26 steps 11–16) is a separate `release.yml` on tag, using `environment:` for the production approval gate and OIDC (`permissions: id-token: write`) rather than a long-lived registry password.

**Two JavaScript test runners, on purpose.** `apps/mobile` is Jest + React Native Testing Library (§9.3, `expo-react-native`); `apps/web` and `apps/widget` are Vitest 4 + Playwright (`vitest-playwright`). Never `pnpm -r test` — always `pnpm --filter <workspace> test`. `packages/contracts` runs its drift suite under Vitest while `apps/mobile` consumes the same schemas under Jest, so the mobile job typechecks against the **built** `packages/contracts` output, not its source.

The control-plane job, complete — it carries most of the reasoning:

```yaml
# .github/workflows/ci.yml (excerpt)
on:
  pull_request:
  merge_group: { types: [checks_requested] }
  push: { branches: [main] }

# cancel-in-progress must not apply to merge_group: a cancelled merge-queue check
# evicts the PR from the queue and the author has to re-add it by hand.
concurrency:
  group: ci-${{ github.event.merge_group.head_ref || github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

permissions:
  contents: read            # jobs needing more raise it locally, never here

jobs:
  core-api:
    runs-on: ubuntu-24.04   # pinned: ubuntu-latest moves image, and the move swaps
                            # the preinstalled PHP/Node/Python out from under us
    defaults: { run: { working-directory: services/core-api } }
    services:
      postgres:
        image: postgres:18-alpine
        env: { POSTGRES_PASSWORD: ci, POSTGRES_DB: kb_test }
        ports: ['5432:5432']   # required: this job runs ON the runner, so the
                               # service label is not a resolvable hostname
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s --health-retries 20
      valkey:
        image: valkey/valkey:8-alpine
        ports: ['6379:6379']
        options: --health-cmd "valkey-cli ping" --health-interval 5s --health-retries 20
      qdrant:
        image: qdrant/qdrant:v1.16.1
        ports: ['6333:6333']
        # deliberately no --health-cmd: the image ships no shell utilities, so any
        # in-container probe fails forever and the job hangs for the whole retry
        # budget before reporting an unrelated error. Wait from the runner instead.
        # Data-service image tags are docker-compose-stack's pin, not this skill's;
        # keep the CI tags identical to the compose `test` profile.
        # <!-- UNVERIFIED: qdrant/qdrant image contents not re-checked this revision -->
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, redis, bcmath, intl
          coverage: none      # xdebug roughly doubles the Pest suite; only the
                              # nightly mutation job sets pcov
      - uses: actions/cache@v6
        with:
          # the Composer download cache, never vendor/ — a restored vendor/ tree
          # outlives a composer.lock change and CI then tests the old dependency
          path: ~/.cache/composer/files
          key: composer-${{ hashFiles('services/core-api/composer.lock') }}
      - run: composer install --no-interaction --prefer-dist --no-progress

      - run: timeout 60 bash -c 'until curl -sf localhost:6333/readyz; do sleep 1; done'

      - name: Tenancy escape hatches must be annotated
        working-directory: .
        run: |
          # Each legitimate raw query carries `// tenancy-exempt: <reason>` on the
          # same line; grep -v drops those, so anything left is unreviewed. This is
          # why the allow-list is inline and not a paths file — it moves with the
          # code and dies with it. Constructs and rationale: kb-tenancy-isolation.
          ! grep -rnE 'withoutGlobalScopes\(|DB::table\(|DB::select\(' \
              services/core-api/app services/core-api/database \
            | grep -v 'tenancy-exempt:'

      - name: Every Qdrant call is filtered
        run: |
          # The Laravel grep above covers only half the system. Non-negotiable 2 is a
          # DATA-PLANE rule, and until this existed the flagship invariant had no gate
          # on the side that actually queries Qdrant. Failure mode (kb-tenancy-isolation):
          # HTTP 200, normal latency, no log line, another tenant's chunks.
          #
          # Every retrieval entry point must take a filter positionally — no default, and
          # no `filter or models.Filter()`, because Filter(must=[]) is a MATCH-ALL.
          ! grep -rnE 'query_points\(|\.search\(|scroll\(|count\(' \
              services/ai-service/app --include=*.py \
            | grep -v 'tenancy-exempt:' \
            | grep -vF 'app/retrieval/search.py'   # the one wrapper that builds the filter
          # And no prefetch leaf may go out unfiltered.
          ! grep -rn 'Prefetch(' services/ai-service/app --include=*.py \
            | grep -v 'query_filter='

      - name: Migrate, then arm the RLS tripwire
        run: |
          php artisan migrate --force
          # RLS is a CI-only detector, never the authorization mechanism —
          # postgresql-patterns declines it in production (PgBouncer leaks the GUC
          # across pooled sessions). It must run as kb_ci_app, a NON-OWNER role:
          # the table owner bypasses RLS silently unless FORCE is set, which makes
          # the tripwire permanently green and proves nothing.
          psql "postgres://postgres:ci@localhost:5432/kb_test" -f database/ci/enable-rls.sql

      - run: php artisan test --parallel --processes=4
        env:
          DB_CONNECTION: pgsql       # never sqlite — jsonb, partial unique indexes
          DB_HOST: 127.0.0.1         # and CHECK constraints must actually fire
          DB_USERNAME: kb_ci_app
          QDRANT_URL: http://localhost:6333

      - name: Form-rules manifest is current
        run: |
          php artisan kb:dump-form-rules
          git diff --exit-code -- ../../packages/contracts/rules
```

`packages/contracts/test/form-drift.test.ts` runs in the Node job and proves the Zod schemas and the FormRequests agree *behaviourally* (probe values, both directions) — the manifest diff alone only proves the dump is fresh (`rhf-zod-forms`).

## Gotchas

- **A lint job reports zero problems on a file with an obvious violation.** Next 16 removed `next lint` and the `eslint` key, and `next build` no longer lints — a job whose "lint" is `next build` passes while checking nothing. Run `eslint .` as its own step, and assert the config actually resolves (`eslint --print-config apps/web/src/app/page.tsx > /dev/null`) so a missing flat config fails loudly instead of matching no files and exiting 0.
- **`download-artifact` reports "Unable to find any artifacts" for an artifact uploaded seconds earlier in the same run.** v3 and v4+ write to different backing services and cannot see each other. Keep the pair current together: `upload-artifact@v7` ↔ `download-artifact@v8`. (v4+ of both is unsupported on GHES; a self-hosted-Enterprise fork must pin v3.2.2 on both sides.)
- **`Unable to locate executable file: pnpm`** from `actions/setup-node` with `cache: pnpm`. `setup-node` shells out to the package manager to find its store; `pnpm/action-setup@v6` must come **before** it. On pnpm ≥ 11 collapse both into `pnpm/setup`.
- **The form-rules diff fails on a PR that touched no form.** The dump iterated the route collection in registration order or serialised a PHP array with unstable key order. The command must sort keys and pin its JSON flags; verify by running it twice on a clean tree and diffing.
- **The `/metrics` catalog diff passes trivially and an uncatalogued instrument still ships.** Prometheus client libraries register lazily — the metric does not exist until its code path executes, so scraping a freshly booted service finds nothing to diff. Register every instrument at module import, then scrape after the smoke suite has driven each path. **And scrape the Collector's `prometheus` exporter, never the services' own `/metrics`** — telemetry is OTLP *push* everywhere, and under PHP-FPM and Celery prefork each request lands in a different process with its own memory, so a per-service endpoint returns one worker's fragment and the diff reports a catalog gap that does not exist (`opentelemetry-instrumentation`). Two independent ways this gate reports success while checking nothing; it needs both fixes. Catalog and label allow-list: `kb-observability-conventions`.
- **A widget PR merges 40 kB over budget with a red table in the log.** `size-limit` was run with `|| true`, or on a `continue-on-error` matrix leg, or in a job that is not a required check. It must run in the same job as the build (it reads `dist/`) and its exit code must be the job's. Its numbers are brotli by default (`preact-vite-library`), so they will not match `du` and the PR comment must say so.
- **A fork PR fails at `docker/login-action` with an unhelpful credential error.** `${{ secrets.X }}` interpolates to the empty string in a fork run, so the failure surfaces as a malformed login, not as "no secret". Gate every credentialed step on `github.event.pull_request.head.repo.fork != true` and let the check be skipped, not failed. Same rule for OIDC: `id-token: write` on a fork PR yields a token your cloud trust policy will not accept.
- **Merge-queue entries fail as "canceled" and get evicted.** A workflow-level `cancel-in-progress: true` matches the merge group's ref against a later one. Make it conditional on `github.event_name == 'pull_request'`.
- **A green push-tier run, then a merge-queue failure in a suite the PR never ran.** That is the design, not a bug — but the merge queue must therefore have every integration job listed as a required check, and the workflow file must exist on the default branch or `merge_group` never fires at all.
- **Pest `--parallel` flakes only in CI.** Laravel tokenizes the database per process and nothing else; the Qdrant collection, Valkey logical DB and cache prefix must be token-scoped in `ParallelTesting::setUpProcess` (`pest-testing`). Never `Cache::flush()`.
- **Playwright passes locally and fails CI with 419.** `SANCTUM_STATEFUL_DOMAINS` must include the `baseURL` host **with its port** (`vitest-playwright`). Run visual specs only inside `mcr.microsoft.com/playwright:v1.62.1` — a runner-native browser renders different glyphs and every snapshot diffs.
- **The full-history secret scan reports zero findings on every PR, forever.** `actions/checkout` clones at `fetch-depth: 1` by default, so `--log-opts="--all"` walks one commit. Any job that reads git history — Gitleaks, a `merge-base` SAST baseline, a changelog check — needs an explicit `fetch-depth: 0` (`security-scanning-toolchain`).
- **`ubuntu-latest` is Ubuntu 24.04 today and rolls over a 1–2 month window.** A job that passed yesterday fails on a different default toolchain. Pin `ubuntu-24.04`; `ubuntu-26.04` is preview. `ubuntu-24.04-arm` exists if a multi-arch image build is ever wanted.

## Official docs

- [Workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax) — `concurrency` (including the newer `queue: single|max`, which cannot combine with `cancel-in-progress: true`), `strategy`, `permissions`, reusable-workflow `uses`/`secrets`.
- [Events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows) — `pull_request` fork rules, `merge_group: checks_requested`, `schedule`.
- [Service containers](https://docs.github.com/en/actions/using-containerized-services/about-service-containers) — `ports` vs service-label hostnames, `options` health probes, `credentials`.
- [Secure use reference](https://docs.github.com/en/actions/reference/security/secure-use) — why `pull_request_target` shares the privileged cache and secrets.
- [OIDC in cloud providers](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-in-cloud-providers) — `id-token: write`, token exchange.
- [actions/runner-images](https://github.com/actions/runner-images) — current labels and what `-latest` maps to.
- [actions/cache](https://github.com/actions/cache), [setup-node](https://github.com/actions/setup-node), [setup-python](https://github.com/actions/setup-python), [setup-uv](https://github.com/astral-sh/setup-uv), [setup-php](https://github.com/shivammathur/setup-php), [pnpm/action-setup](https://github.com/pnpm/action-setup).

## Definition of done

- [ ] Every gate on the required-check list runs green on a fork PR with zero secrets available; credentialed steps are `skipped`, not `failed`.
- [ ] `merge_group: {types: [checks_requested]}` present, workflow file on the default branch, and `cancel-in-progress` conditional on `pull_request`.
- [ ] All runners pinned to `ubuntu-24.04`; no `ubuntu-latest`, no `@main` or branch-pinned action.
- [ ] `upload-artifact` and `download-artifact` majors verified as a working pair by an actual round trip in CI.
- [ ] ESLint runs as its own step and a deliberately violating file fails it.
- [ ] Tenancy grep fails on a newly added unannotated `DB::table(`, and passes once `// tenancy-exempt: <reason>` is added.
- [ ] `/metrics` diff fails when an uncatalogued instrument is added; label allow-list unit test runs in the same job.
- [ ] `kb:dump-form-rules` is idempotent (run twice, clean tree) and `git diff --exit-code` fails on a one-character `max:` change; `form-drift.test.ts` fails on the same change.
- [ ] `size-limit` exit code is the job's; a +1 kB bundle change fails the PR.
- [ ] Pest `Arch/` and `Security/` suites run unconditionally (no test-impact-analysis gating), `--processes=4` passes.
- [ ] RLS tripwire connects as a non-owner role; flipping a policy off makes an isolation test fail.
- [ ] Ragas runs only on `schedule`/`workflow_dispatch`, never as a PR required check.
- [ ] Caches keyed on lockfile hashes (`composer.lock`, `pnpm-lock.yaml`, `uv.lock`); no `vendor/`, `node_modules/`, or `.venv/` restored directly.
- [ ] No workflow echoes a DSN, provider key, or KEK; `security-scanning-toolchain` owns Gitleaks over the same tree.

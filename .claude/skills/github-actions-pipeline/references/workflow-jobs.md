# Workflow jobs — the full YAML

Companion to `SKILL.md`. The trigger table and the gate table there are the contract; this file is
the YAML an implementer needs once. Data-service image tags are `docker-compose-stack`'s pin, not
this skill's — keep every tag here byte-identical to the compose `test` profile.

**Both workflows now exist, and where a job lives is not what this file's section headings imply.**
`.github/workflows/gates.yml` holds the install-free jobs — `boundary-greps`, `enforcement-greps`,
`observability-rules`, `compose-ci-tag-drift`, `compose-invariants`, `repo-artifact-consistency` —
so the grep-shaped and telemetry sections below are `gates.yml`'s, not `ci.yml`'s. `ci.yml` holds
the jobs that install something or run a container: `core-api`, `ai-service`, `node`, `images` and
the scanners. **The shipped workflows are authoritative and have diverged from the YAML below in
detail** (job splits, marker-scoped pytest steps, `load: true` on the core-api image build). Read
this file for the *reasoning* — every comment here is an argument someone had to make once — and
read the workflow for what actually runs. Where the two disagree on a mechanism rather than a
detail, that is a finding, not a preference.

## Workflow top matter

```yaml
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
```

## `core-api` — the control-plane job

Carries most of the reasoning, so read it before adding any other job.

```yaml
jobs:
  core-api:
    runs-on: ubuntu-24.04   # pinned: ubuntu-latest moves image, and the move swaps
                            # the preinstalled PHP/Node/Python out from under us
    defaults: { run: { working-directory: services/core-api } }
    services:
      # Service alias, database, user and password are the FOUR canonical test-DB values, and
      # they must match `services/core-api/phpunit.xml` and the compose `test` profile exactly.
      # These three disagreed in three different ways until 2026-08-07, which is why
      # `make test-up && vendor/bin/pest` had never worked. The alias is `postgres-test`, not
      # `postgres`: a test database reachable as bare `postgres` is one connection-string typo
      # away from a suite that truncates the real one.
      postgres-test:
        image: postgres:18-alpine
        env: { POSTGRES_USER: kb_test, POSTGRES_PASSWORD: kb_test, POSTGRES_DB: kb_test }
        ports: ['5432:5432']   # required: this job runs ON the runner, so the
                               # service label is not a resolvable hostname
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s --health-retries 20
      valkey:
        image: valkey/valkey:9.1.1   # NOT 8-alpine — 8 has no DELIFEQ and no
                                     # database-level ACL, so the two-instance
                                     # boundary tests pass against nothing (Gotchas)
        ports: ['6379:6379']
        options: --health-cmd "valkey-cli ping" --health-interval 5s --health-retries 20
      qdrant:
        image: qdrant/qdrant:v1.18.3   # matches qdrant-client==1.18.0; server and
                                       # client minors are pinned and bumped together
        ports: ['6333:6333']
        # deliberately no --health-cmd: the image ships no shell utilities, so any
        # in-container probe fails forever and the job hangs for the whole retry
        # budget before reporting an unrelated error. Wait from the runner instead.
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

      - name: Migrate, then arm the RLS tripwire
        run: |
          php artisan migrate --force
          # RLS is a CI-only detector, never the authorization mechanism —
          # postgresql-patterns declines it in production (PgBouncer leaks the GUC
          # across pooled sessions). It must run as kb_ci_app, a NON-OWNER role:
          # the table owner bypasses RLS silently unless FORCE is set, which makes
          # the tripwire permanently green and proves nothing.
          psql "postgres://kb_test:kb_test@localhost:5432/kb_test" -f database/ci/enable-rls.sql

      - run: php artisan test --parallel --processes=4
        env:
          DB_CONNECTION: pgsql       # never sqlite — jsonb, partial unique indexes
          DB_HOST: 127.0.0.1         # and CHECK constraints must actually fire
          DB_USERNAME: kb_ci_app
          QDRANT_URL: http://localhost:6333

      # Both generated artifacts are asserted with the generator's own `--check`, never with
      # `dump && git diff --exit-code`. `git diff` CANNOT SEE AN UNTRACKED FILE, so on a tree
      # where the artifact has never been committed the dump writes new files and the diff
      # passes having compared nothing — a green required check standing for an assertion that
      # never ran. `--check` writes nothing and exits non-zero if any file WOULD change.
      - name: OpenAPI document is current
        # Reads config('session.cookie') live into components.securitySchemes, so do not add a
        # SESSION_COOKIE to this job (or to phpunit.xml) without regenerating the document.
        run: php artisan kb:dump-openapi --check

      - name: Form-rules manifest is current
        run: php artisan kb:dump-form-rules --check
```

`packages/contracts/test/form-drift.test.ts` runs in the Node job and proves the Zod schemas and the
FormRequests agree *behaviourally* (probe values, both directions) — the manifest diff alone only
proves the dump is fresh (`rhf-zod-forms`).

`kb:dump-openapi --check` has no behavioural twin — nothing exercises a generated client against a
live server — so the committed document is held current by exactly two things: `OpenApiDocumentTest`
inside the Pest suite, and this step. That is why it is a step and not a review habit.

## `ai-service` — the data-plane greps

Both steps are pure text passes with no containers and no secrets, so they run in the per-push tier.

```yaml
      - name: Every Qdrant call is filtered
        run: |
          # The Laravel grep above covers only half the system. Non-negotiable 2 is a
          # DATA-PLANE rule, and until this existed the flagship invariant had no gate
          # on the side that actually queries Qdrant. Failure mode (kb-tenancy-isolation):
          # HTTP 200, normal latency, no log line, another tenant's chunks.
          #
          # ACCUMULATE, never `! grep`. Under `set -e` bash IGNORES the failure of a command
          # whose status is inverted with `!`, so in a step with two checks only the LAST one
          # can fail the job — the first is silently advisory and reads exactly like a gate.
          set -u; fail=0
          flag() { [ -n "${1//[[:space:]]/}" ] || return 0; printf '%s\n' "$1"; echo "::error::$2"; fail=1; }

          # Every retrieval entry point must take a filter positionally — no default, and
          # no `filter or models.Filter()`, because Filter(must=[]) is a MATCH-ALL.
          flag "$( grep -rnE 'query_points\(|\.search\(|scroll\(|count\(' \
                     services/ai-service/app --include=*.py \
                   | grep -v 'tenancy-exempt:' \
                   | grep -vF 'app/retrieval/search.py' || true )" \
               "retrieval call outside the one filter-building wrapper"
          # And no prefetch leaf may go out unfiltered.
          flag "$( grep -rn 'Prefetch(' services/ai-service/app --include=*.py \
                   | grep -v 'query_filter=' || true )" "unfiltered Prefetch leaf"
          exit $fail

      - name: Deletion targets identifiers, never text
        run: |
          # Project non-negotiable 6 / kb-deletion-and-verification §8.17: "Deletion
          # targets stable identifiers, never text matching." Until this step existed the
          # only enforcement was a reviewer noticing. Failure mode: headers, disclaimers,
          # licence blocks and pricing tables repeat VERBATIM across documents and across
          # tenants, so a payload-text or content-hash match issued for source A cuts a
          # hole in source B. Nothing errors, every count stays plausible, and it surfaces
          # weeks later as a bot that stopped citing a clause nobody edited.
          #
          # (a) An ALLOW-LIST of filter keys, not a deny-list of bad ones — a deny-list
          # cannot anticipate the next payload field somebody adds. The payload contract is
          # exactly the six fields kb-tenancy-isolation names, plus chunk_id; every other
          # key (text, content, content_hash, title, heading_path, excerpt, url) is a
          # content match wearing a filter's clothes. Escape hatch on the same line, same
          # shape as the tenancy grep: `deletion-key-exempt: <reason>`.
          # Accumulator, not `! grep` — see the tenancy step: under `set -e` a negated
          # command's failure is ignored, so check (a) below could never fail the job.
          set -u; fail=0
          flag() { [ -n "${1//[[:space:]]/}" ] || return 0; printf '%s\n' "$1"; echo "::error::$2"; fail=1; }
          flag "$( grep -rnE 'FieldCondition\(\s*key=' \
              services/ai-service/app --include=*.py \
            | grep -vE "key=[\"'](org_id|bot_ids|source_id|source_item_id|source_version_id|source_status|chunk_id)[\"']" \
            | grep -v 'deletion-key-exempt:' \
            | grep -vF 'app/deletion/filters.py' || true )" \
            "filter key outside the seven-key delete allow-list"
                                                   # the one builder; its keys come from the
                                                   # DELETE_KEYS tuple, unit-tested against all
                                                   # SEVEN keys above — the six-field payload
                                                   # contract PLUS chunk_id, which deletion
                                                   # targets and no query filter ever does.
                                                   # Not equal to the six on purpose (Gotchas).
          # (b) No full-text matcher reaches Qdrant at all, and no delete or count takes a
          # text/content/hash kwarg. Lexical matching in this platform is a sparse vector
          # (bge-m3-embeddings; its source is open — finding C2), never MatchText — so a MatchText in the data plane
          # is either a delete filter or a retrieval path that bypasses fusion. Both are bugs.
          flag "$( grep -rnE 'MatchText\(|(delete|count)[a-z_]*\([^)]*(text|content|content_hash)=' \
              services/ai-service/app --include=*.py \
            | grep -v 'deletion-key-exempt:' || true )" \
            "full-text matcher or text/hash kwarg on a delete or count"
          exit $fail

      - name: The data plane writes only its allow-listed tables
        run: |
          # Open decision 2, RESOLVED (kb-architecture-map, fastapi-service): services/ai-service
          # writes only the names in ALLOWED_TABLES and NOTHING else. Every other table is
          # Laravel's, read and write, and Laravel owns all migrations. Failure mode: a data-plane INSERT/UPDATE
          # into a control-plane table lands beside Laravel's own writer with no policy check, no
          # audit row, and no framework-applied tenant scope. Nothing errors; the row is just
          # there, and it is found by a customer, not by a test.
          #
          # ALLOW-LIST, not a deny-list of bad table names — same reasoning as the deletion-key
          # gate above: a deny-list cannot see the table somebody invents tomorrow. So detect
          # every write statement, then subtract the allowed names; whatever is left fails.
          # Escape hatch on the same line, same shape as the other two greps:
          # `table-write-exempt: <reason>`. A second exempted line is a review stop, not a merge.
          #
          # IMPORT THE LIST, NEVER RESTATE IT, AND NEVER ASSERT ITS LENGTH. The shipped gate reads
          # `from app.db.writes import ALLOWED_TABLES` and joins it with `|`. The literal below is
          # what this reference used to carry and it is exactly the drift the rule exists to stop:
          # the list grew from four names to six when ADR-032 restored the sparse arm, and a
          # private copy keeps subtracting the OLD spelling — so a renamed table reads as a
          # violation while the stale name keeps passing. Membership is pinned separately, by
          # name, against a reviewed list, so an addition, a swap and a rename are all diffs a
          # reviewer approves against ADR-033's three properties: the row is derived and
          # rebuildable in the ADR-010 sense, no public API path reads or writes it, and Laravel
          # owns its migration. A count admits a swap and a rename and blocks a correct addition.
          #
          # NOT `! grep …` here, deliberately. `set -e` is specified to ignore the failure of a
          # command whose status is inverted with `!`, so in a multi-check step only the LAST
          # `! grep` can fail the job — every earlier one reports and passes. Accumulate instead
          # and `exit $fail`.
          set -u
          ALLOWED=$(python -c 'from app.db.writes import ALLOWED_TABLES; print("|".join(ALLOWED_TABLES))')
          [ -n "$ALLOWED" ] || { echo "::error::ALLOWED_TABLES imported empty; every check below is vacuous"; exit 1; }
          WRITE='(insert[[:space:]]+into|update|delete[[:space:]]+from|copy)[[:space:]]+"?[a-z_][a-z0-9_]*"?'
          fail=0
          flag() { [ -n "${1//[[:space:]]/}" ] || return 0; printf '%s\n' "$1"; echo "::error::$2"; fail=1; }

          # (a) Every SQL write names a table; subtract the allow-list. `WRITE` requires whitespace
          # after the keyword, so Python's `d.update(...)` / `cfg.update(other)` never match.
          flag "$( grep -rniE "$WRITE" services/ai-service/app --include=*.py --include=*.sql \
                 | grep -v 'table-write-exempt:' \
                 | grep -viE "(insert[[:space:]]+into|update|delete[[:space:]]+from|copy)[[:space:]]+\"?($ALLOWED)\"?[^a-z0-9_]" \
                 || true )" "write outside ALLOWED_TABLES"

          # (b) grep is line-based, so a write whose table sits on the NEXT line would be seen by
          # (a) and subtracted by nothing. Uppercase-only, so prose like "# needs update" is safe.
          flag "$( grep -rnE '\b(INSERT INTO|UPDATE|DELETE FROM|COPY)[[:space:]]*$' \
                     services/ai-service/app --include=*.py --include=*.sql \
                 | grep -v 'table-write-exempt:' || true )" "SQL write with no table name on its line"

          # (c) The data plane defines no schema and has no ORM to hide table targets behind:
          # writes are psycopg text (opentelemetry-instrumentation pins PsycopgInstrumentor).
          # If an ORM ever lands, this fails and (a)/(b) must grow a model-symbol allow-list
          # in the same commit — that is the point of failing here rather than degrading quietly.
          flag "$( grep -rniE 'create[[:space:]]+table|alter[[:space:]]+table|drop[[:space:]]+table|\balembic\b|sqlalchemy|sqlmodel|tortoise' \
                     services/ai-service/app services/ai-service/pyproject.toml \
                 || true )" "the data plane owns no schema and no ORM"

          # (d) Atomic publication: FastAPI writes rows against the NEW, not-yet-active
          # source_version_id and reports readiness on the §17.5 callback; LARAVEL flips
          # source_items.current_version_id. Two writers on that column meet the partial unique
          # index ("at most one active version per item", postgresql-patterns) as an IntegrityError
          # inside a Celery task that retries forever. Naming the column in a callback payload
          # model is fine — `IngestionStatus(current_version_id=…)` does not match; assigning it
          # (`item.current_version_id =`) or `SET current_version_id =` does.
          flag "$( grep -rniE 'set[[:space:]]+"?current_version_id"?[[:space:]]*=|\.current_version_id[[:space:]]*=[^=]' \
                     services/ai-service/app --include=*.py --include=*.sql \
                 | grep -v 'table-write-exempt:' || true )" "activation is Laravel's; report readiness instead"

          exit $fail
```

## `qdrant-rebuild-proof` — ADR-010 as a merge gate

```yaml
  qdrant-rebuild-proof:
    # MERGE GATE, not the nightly. ADR-010 requires Qdrant to be reconstructable from
    # PostgreSQL + SeaweedFS. The violation is a payload key written at index time that
    # exists in no column — a curated title, a boost weight, a language guess, a hand-fixed
    # excerpt. Point counts and chunk counts both match afterwards, so counts cannot see it
    # (kb-architecture-map Gotcha 5); only the ranking moves. Scheduled, it merged and was
    # caught overnight at best. The full-corpus rebuild stays on the nightly; this is the
    # fixture-sized version, ~90 s, that runs before merge.
    runs-on: ubuntu-24.04
    if: github.event_name == 'merge_group'
    services:
      # Service alias, database, user and password are the FOUR canonical test-DB values, and
      # they must match `services/core-api/phpunit.xml` and the compose `test` profile exactly.
      # These three disagreed in three different ways until 2026-08-07, which is why
      # `make test-up && vendor/bin/pest` had never worked. The alias is `postgres-test`, not
      # `postgres`: a test database reachable as bare `postgres` is one connection-string typo
      # away from a suite that truncates the real one.
      postgres-test:
        image: postgres:18-alpine
        env: { POSTGRES_USER: kb_test, POSTGRES_PASSWORD: kb_test, POSTGRES_DB: kb_test }
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U postgres" --health-interval 5s --health-retries 20
      qdrant:
        image: qdrant/qdrant:v1.18.3
        ports: ['6333:6333']
      seaweedfs:
        image: chrislusf/seaweedfs:4.40   # the pin from `seaweedfs-s3` (4.30 is the floor —
        command: server -s3 -dir=/data    # multipart ETag correctness, #9772); 8333 = `weed s3`
        ports: ['8333:8333']
    steps:
      - uses: actions/checkout@v7
      - uses: astral-sh/setup-uv@v9
        with: { enable-cache: true, cache-dependency-glob: services/ai-service/uv.lock }
      - run: timeout 60 bash -c 'until curl -sf localhost:6333/readyz; do sleep 1; done'
      - name: Rebuild a fixture collection and diff the payload key set
        working-directory: services/ai-service
        run: uv run pytest tests/integration/test_adr010_rebuild.py -q --no-header
```

What that test asserts, because the assertion is the whole gate:

```python
# services/ai-service/tests/integration/test_adr010_rebuild.py
seed_fixture(orgs=1, sources=3, chunks=~200)      # PostgreSQL rows + SeaweedFS objects only
client.delete_collection(COLLECTION)               # prove it from truth, not from a snapshot
run_module("app.maintenance.rebuild_index", org_id=FIXTURE_ORG)

observed = set().union(*(p.payload.keys() for p in scroll_all(client, COLLECTION)))
assert observed == EXPECTED_PAYLOAD_KEYS   # SET equality — symmetric difference, not len().
# len(observed) == len(EXPECTED) passes while a curated `title` swaps in for a dropped
# `url`, which is precisely the bug this job exists for.
assert golden_query_point_ids() == RECORDED_POINT_IDS
# A key can be present and still be sourced from the wrong column, so the payload diff is
# necessary and not sufficient: re-run the golden queries and compare ranked point ids.
```

## `observability-rules` — the three telemetry gates

File checks only: no secrets, no service containers, no build. Per-push tier, path-filtered on
`infrastructure/observability/**`.

```yaml
  observability-rules:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v7

      - name: Alert rules carry the required labels and annotations
        run: |
          # A line grep cannot tell which rule a stray `severity:` belongs to — it passes a
          # file where the label sits on the wrong rule — so drive it off the parsed
          # document. yq -e exits 1 on empty output, so a clean run makes `!` succeed and a
          # violation prints the offending alert name and fails the step.
          ! yq -e '.groups[].rules[] | select(has("alert"))
                   | select(((.labels.severity // "") | test("^(page|ticket)$") | not)
                            or (.annotations.summary // "") == ""
                            or (.annotations.runbook // "") == "")
                   | .alert' infrastructure/observability/prometheus/rules/*.yml

      - name: Every severity a rule emits has a real Alertmanager route
        run: |
          # `amtool config routes test` exits 0 for an unrouted severity too — it just falls
          # through to the default receiver. So compare the RESOLVED receiver against a
          # deliberately-named black hole. Symptom without this: Prometheus shows the alert
          # firing and nobody is notified (prometheus-grafana-loki-tempo Gotchas).
          for sev in $(yq -r '.groups[].rules[].labels.severity // empty' \
                        infrastructure/observability/prometheus/rules/*.yml | sort -u); do
            recv=$(docker run --rm -v "$PWD/infrastructure/observability:/o:ro" \
                     --entrypoint amtool prom/alertmanager:v0.33.1 \
                     config routes test --config.file=/o/alertmanager/alertmanager.yml \
                     severity="$sev")
            [ "$recv" = "kb-unrouted" ] && { echo "::error::severity=$sev routes nowhere"; exit 1; }
          done

      - name: promtool check rules + test rules
        run: |
          # `check rules` is syntax. `test rules` is the unit suite — the only one of the two
          # that can fail on a rule which parses fine and computes the wrong thing (e.g. a
          # kb:chat_error_ratio:5m that drops timeouts). -w is load-bearing: promtool resolves
          # each test file's `rule_files:` relative to the CWD, not to the test file.
          docker run --rm -v "$PWD/infrastructure/observability:/o:ro" \
            -w /o/prometheus --entrypoint sh prom/prometheus:v3.13.2 -c \
            'promtool check rules rules/*.yml && promtool test rules tests/*.yml'
```

# Contributing

Read [`docs/00-index.md`](docs/00-index.md) before your first change, and the skill that owns the area
you are touching (`.claude/skills/<name>/SKILL.md`) before your first change _in that area_. The skills
are more current than the original specification wherever the two disagree; that divergence is
recorded in [`docs/22-spec-findings-and-decisions.md`](docs/22-spec-findings-and-decisions.md).

---

## One top-level area per change

A pull request touches **one** of `apps/web`, `apps/widget`, `apps/mobile`, `services/core-api`,
`services/ai-service`, `packages/*`, `infrastructure/*`, `docs/`, `scripts/`.

This is not a size preference. Every boundary in this system is enforced by something narrow — a
network membership, a table allow-list, a CI grep, a bundle-size budget — and a change spanning two
areas is a change where one of those enforcers is being routed around. When a change genuinely has to
cross a boundary, say so in the description and name the boundary; two of them exist that _must_ move
together and are documented as such (the OKLCH colour grammar in `apps/web` and `services/core-api`,
and any signing-scheme change in both planes).

`packages/`, `samples/` and `scripts/` have single owners. See the shared-directory table in
[`CLAUDE.md`](CLAUDE.md). Importing from `packages/contracts` is expected; **forking anything out of
it is the drift the contract review exists to catch.**

---

## Running the tests

Each runtime owns its own dependency graph, its own lockfile, and its own runner. There is no wrapper.

| Runtime          | Where                 | Commands                                                                                                                            |
| ---------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Laravel          | `services/core-api`   | `composer lint` (Pint), `composer stan` (Larastan 8), `composer test` (Pest), `composer test:arch`, `composer test:security`        |
| FastAPI          | `services/ai-service` | `uv run ruff check .`, `uv run mypy`, `uv run pytest`                                                                               |
| Web              | repo root             | `pnpm web:lint`, `pnpm web:typecheck`, `pnpm web:test` (Vitest), `pnpm web:e2e` (Playwright — **runs zero tests today**, see below) |
| Widget           | `apps/widget`         | `pnpm lint`, `pnpm typecheck`, `pnpm test`, `pnpm e2e`, `pnpm size`                                                                 |
| Mobile           | `apps/mobile`         | `pnpm lint`, `pnpm typecheck`, `pnpm test` (Jest + jest-expo)                                                                       |
| Contracts        | repo root             | `pnpm contracts:lint`, `pnpm contracts:typecheck`, `pnpm contracts:test`, `pnpm contracts:build`                                    |
| Compose          | repo root             | `make lint-compose` — validates every `-f` combination parses and interpolates                                                      |
| Prometheus rules | repo root             | `make promtool`                                                                                                                     |

Four things that catch people:

- **`pnpm web:e2e` and the `components` Vitest project both run zero tests, and the table above cannot
  say so on its own.** `pnpm exec playwright test --list` in `apps/web` returns `Total: 0 tests in 0
files` — `apps/web/tests/e2e/` holds only `.gitkeep` — and the `components` project returns `No test
files found`, shielded by `passWithNoTests: true` at `apps/web/vitest.config.ts:16`. Neither is a
  defect: the specs have not been written, and writing them needs the chat surface. The defect is
  citing either as coverage, which is easy to do because `apps/widget`'s Playwright suite is real
  (`Total: 22 tests in 5 files`, real cross-origin, real sockets) and sits one directory away. Delete
  both halves of this bullet the day the first spec lands, together with `passWithNoTests`. See
  `docs/22` § F5.
- **`uv run mypy` takes no path argument, and `uv run mypy .` is not the same command.**
  `[tool.mypy]` sets `files = ["app"]` under `strict = true`; passing `.` overrides that and drags
  `tests/`, `scripts/` and every stray module into strict checking. CI runs
  `uv run --frozen mypy` with no target, and so should you.
- **`pnpm contracts:build` must run before the mobile typecheck.** `apps/mobile` typechecks against the
  _built_ output of `@kb/contracts`, not its source (ADR-028).
- **`make promtool` must run from the rules directory**, which is why it is a Make target.
  `promtool test rules` resolves `rule_files:` relative to the working directory, so running it from
  the repository root finds nothing — and a passing run of zero tests looks identical to a passing run.

Test databases and the local crawl fixture origin come up with `make test-up` (the `test` Compose
profile). The image versions there are pinned to match CI, and a drift check diffs the two.

---

## The enforcement greps

CI runs a set of checks that are greps rather than tests, because the things they protect fail
_silently_ and so cannot be asserted from a passing test.

**The two lists below are not the same claim.** `.github/workflows/gates.yml` is the source of truth
for what a pull request actually has to survive today; the reasoning behind each check lives in
`.claude/skills/github-actions-pipeline/references/workflow-jobs.md`, which also specifies checks that
have not been authored yet. Keep the split honest — when a gate lands, move its bullet up rather than
letting the second list quietly read as enforcement.

### Enforced today — `.github/workflows/gates.yml`

Six jobs, every one a pure text/file pass: no install, no service containers, no secrets. Keep it that
way even though every lockfile is now committed and `ci.yml` installs — these run in seconds on a fork
PR with no secrets available, and a gate that needs an install inherits every reason an install fails.

- **Tenancy.** `withoutGlobalScopes(`, `DB::table(`, `DB::select(` in `services/core-api`; unfiltered
  `query_points(`, `.search(`, `scroll(`, `count(` in `services/ai-service`, plus a `Prefetch(` with no
  `query_filter=` on the line. Exempt a line with a `tenancy-exempt:` comment on the same line, and
  expect that exemption to be read in review. (The `Prefetch(` check has a known defect — qdrant-client
  spells the _leaf_ parameter `filter=`, so the gate as written passes on a filtered and an unfiltered
  prefetch alike. `docs/22` § _CI gate defects_, item 4.)
- **Deletion keys.** Every `FieldCondition(key=…)` must name one of the seven allow-listed keys.
  `MatchText(` and any `text=` / `content=` / `content_hash=` kwarg on a delete or count is a hard
  failure — deletion targets stable identifiers, never text. Escape hatch: `deletion-key-exempt:`.
- **The write allow-list.** `services/ai-service` writes only the tables in
  `app.db.writes.ALLOWED_TABLES` and nothing else (ADR-012, table list superseded by
  [ADR-033](docs/19-repo-structure-adrs.md)). Migrations, ORMs and any assignment to
  `current_version_id` are refused there. The gate _imports_ the tuple — do not restate the list, or
  its length, in a docstring, a config or this file, because a second copy drifts silently.
  **Admission is three properties, never a count:** the row is derived and rebuildable in the
  ADR-010 sense; no public API path reads or writes the table; Laravel owns the migration. A name
  that satisfies all three may join; a name that satisfies two is a review stop.
- **Cardinalities asserted by import**, in the same job: seven delete keys (six payload filter fields
  plus `chunk_id`) and eighteen error classes. A nineteenth class is a red build. **The table
  allow-list is deliberately not on this list any more** — it is asserted by **membership**, not by
  cardinality: `KB_TABLE_REVIEW_PIN` at `gates.yml:423` names the reviewed set and is compared
  set-wise against the tuple imported from `app/db/writes.py`. The old `[ "$t" = "4" ]` assertion no
  longer exists, and the block's own comment states its honest limit — the pin stops a table arriving
  _unreviewed_; it cannot stop a reviewer approving the pin without checking the three properties.
  Adding a name means adding it to the pin **in the same pull request** and saying which property
  justifies it.
- **Telemetry.** Alert label and annotation completeness, Alertmanager route coverage against the
  deliberately-named `kb-unrouted` receiver, and `promtool check rules` plus `test rules` — with the
  declared test-case count asserted non-zero, because a passing run of zero tests looks identical to a
  passing run.
- **Compose invariants.** Network membership, published host ports, profile names, `depends_on` across
  profiles, and the no-named-volume rule on `valkey-cache`. Twelve assertions, read live off the four
  compose files.
- **Compose/CI image drift.** Every data-service image any workflow uses must equal the `test`-profile
  pin in `compose.yaml`.
- **Repo artifact consistency.** Regenerated design tokens, the byte-identical SSE fixture twins in
  `apps/web` and `apps/mobile`, the widget's `__KB_*` constants agreeing across `src/`, `vite.config.ts`
  and `env.d.ts`, one corpus version across `samples/VERSION` and the two TOMLs, `deploy: preflight` as
  a Makefile _prerequisite_, a zero-dependency root `package.json` with no fifth lockfile, and a
  non-zero floor on the vendored SAST rule count.
- **No Server Actions** in `apps/web` — a `'use server'` directive is a POST endpoint with no route, no
  middleware and no rate limit.
- **The error-rate matcher is never re-derived.** `outcome="error"` outside `kb-recording.yml` omits
  every timeout. Escape hatch: `outcome-matcher-exempt:`.
- **Laravel's first-party AI APIs** (`Str::toEmbeddings()`, `DB::whereVectorSimilarTo()`, and
  `laravel/ai` / `laravel/mcp` in `composer.json`) anywhere in `services/core-api` (ADR-017).

**A gate count is not a coverage number.** Roughly a third of the checks above cannot fail on today's
tree — the corpus they scan is a skeleton, so the banned token has nowhere to appear yet. Each job ends
with a `vacuous-today:` ledger step that names its own zero-candidate checks in the job output. Delete
a ledger line when the corpus it names grows a real call site; never delete the ledger.

### Also enforced today — `.github/workflows/ci.yml`

**`ci.yml` exists.** Seven jobs — `core-api`, `ai-service`, `node`, `images`, `dependency-scan`,
`dependency-scan-full`, `secret-scan` — and unlike `gates.yml` it installs. Every lockfile is
committed, so `composer install`, `uv sync --frozen` and `pnpm install --frozen-lockfile` all run,
and with them Pest, pytest, Vitest, ESLint, `next build` and `size-limit`. The `images` job builds
**six** images with `docker/build-push-action` (core-api; ai-service `runtime`, `runtime-crawl` and
`runtime-evaluation`; web; widget), which is what put the `RUN`-line assertions inside those
Dockerfiles under enforcement for the first time — that was finding **O22**, and it is closed.

Two consequences worth knowing before you cite a check as a control:

- **`size-limit` runs in the same step as the widget build and its exit code is the job's.** It can
  fail on a pull request that touched no widget file, because `packages/contracts` is billed to the
  widget's 30 kB brotli shell. When it does, the fix is trimming the package — **never raising the
  limit.**
- **The RLS tripwire is armed but unobserved.** `database/ci/enable-rls.sql` runs; the suite still
  connects as the owner role, so the policies are never exercised. The step says so in its own
  output rather than leaving the absence to be inferred.

### Still owed — and the list lives in one place

**Do not maintain a second copy of it here.** The authoritative list is the
`DELIBERATELY NOT HERE — gates that are OWED and cannot pass today` block at the top of
`.github/workflows/ci.yml`. It names each unwired gate, its owner, and — this is the part a
duplicate always loses — **the specific artifact whose absence is the blocker**, so you can tell
whether your change unblocks one. Highlights, for orientation only:

- **The ADR-010 rebuild proof** (merge-queue tier, asserting a reconstructed **payload** rather than
  a count), the `/metrics` catalog diff, `trivy image`, `pip-audit`, Opengrep SAST, and the
  eval-corpus regression gate.
- **The error taxonomy's Page column diffed against the named alert rules.** Held back deliberately:
  eight error classes are uncovered _by design_ and the `TODO(ADR)` block at the top of
  `infrastructure/observability/prometheus/rules/kb-alerts.yml` enumerates them, so the gate is red
  until each uncovered class has a rule or a recorded decision. A required check that cannot pass gets
  disabled within a week, which is worse than not having it.
- **The licence gate in `scripts/security/`.** Still blocked, but no longer on the same blocker: all
  three model revisions resolved on 2026-08-07, and resolving them turned three false reds into one
  true one — `docling-models` at v2.3.0 is CDLA-Permissive-2.0, not the MIT its row claimed. See
  [`SECURITY.md`](SECURITY.md) § _Why this is a policy and not a courtesy_.
- **`osv-scanner` is wired on the schedule tier only**, not as a pull-request gate, because it is red
  on today's tree for 13 fixable advisories nobody on the team introduced. Promote it in the commit
  that lands the bumps.

The form-rules diff (`php artisan kb:dump-form-rules --check`) has moved out of this list: it runs in
the `core-api` job and has two real FormRequests to compare.

Several of the specified greps have known defects — they are unsatisfiable as written, or they pass on
incorrect code. They are catalogued in `docs/22` § _CI gate defects found by writing code against the
gates_. **If a gate blocks correct code, fix the gate in the owning skill rather than working around
it locally**, and add the finding to `docs/22` if it is not already there.

One defect class is worth knowing before you write a gate of your own: **a gate must name the token it
bans, so the file documenting the ban trips it.** Sixteen claims in this repo have that shape, and in
every one of them the tripping line is the _explanation_. Mitigate in the same commit as the gate —
anchored pattern, path scope, or an exempt marker — and never by deleting the sentence that turned the
build red. See `docs/22` § _Self-tripping enforcement_.

---

## The ADR process

**This is the most important convention in the repository.** Everything in `docs/01-*.md` through
`docs/21-*.md` is a verbatim extract of the original specification. It is never edited. A deviation
from it is recorded, never applied by rewriting the source — otherwise the specification becomes a
description of the code, and the reason for every difference is lost the moment it stops being
obvious.

A deviation therefore takes **two** pieces, in the same pull request:

1. **An ADR entry in `docs/19-repo-structure-adrs.md` §28.** The next free number — read it off the
   last `### ADR-` heading in that file rather than from here, because a number written into prose is
   wrong the moment somebody lands one — plus a decision, a reason, and the trade-off. Keep it short:
   it is a pointer.
2. **The argument in `docs/22-spec-findings-and-decisions.md`.** The context, the options genuinely
   considered, why each rejected one was rejected, the consequences including the ones that hurt, and —
   wherever you can name it — the **revisit condition**: the observable thing that would make this
   decision wrong.

Rules that are not negotiable:

- **An ADR records a decision that was made.** If it has not been made, write the options, the
  trade-offs and a recommendation, and mark it `Proposed`. Never resolve an open question by writing an
  ADR that quietly picks a side.
- **Never edit an accepted ADR in place.** Supersede it: a new number, a stated reason, and
  `Superseded by ADR-NNN` on the old one. The reasoning of the moment is the thing worth keeping.
- **An ADR listing only advantages is a marketing document** and nobody will trust the next one. State
  what the decision costs.
- **Never delete a recorded finding because it now reads as obvious.** The log is a history of what was
  not obvious at the time. Mark it resolved and leave it.
- **Check the numbering** of anything you add to a numbered list in `docs/22`. That file has been
  renumbered once already after an insertion in the wrong place; the _Spec defects_ list is 1–27, the
  scaffolding findings are O1–O27, the ADR-030 consequences are C1–C3, the audit rounds are A1–A13 and
  S1–S17, the enforcement-claim audit is E1–E4, the 2026-08-10 scope re-baseline is R1–R8, and the
  stub-completion findings are F1–F12.
  **Append, never insert** — several of these are cited by name from code comments, and C2 alone is
  named in six places under `services/ai-service/app/ingestion/`.
- **A code comment may cite a finding this file does not hold under that name, and the fix is a
  pointer, not a renumber.** Two exist today and both are deliberate: three modules cite _"finding
  C1"_ for the **embedding**-credential question while `docs/22` records C1 under a rerank title, and
  `app/providers/embedding_selection.py` cites _"finding S12"_ for the multi-credential wire question
  while S12 is the SSE frame parsers. In each case the finding's own text carries a pointer at its
  head so the comment leads somewhere true. Preserve those pointers; renaming a heading to fix a label
  breaks every citation of it, and `docs/00-index.md` besides.

Not every change needs an ADR. A bug fix, a test, a dependency patch bump: no. Anything that makes the
code disagree with `docs/01`–`docs/21`, changes a pinned version with a stated reason, moves a
boundary, or closes an option: yes.

---

## Metric catalog PR first, then code

The metric catalog in `.claude/skills/kb-observability-conventions/references/metric-catalog.md` is
**closed**, and the label allow-list is the union of its `Extra labels` columns — the two are one
artifact, and a unit test at the instrument wrapper fails if they disagree.

So a new metric, a new label, or a new label _value_ is a documentation change that lands **before** the
code that emits it. Not as ceremony: an ad-hoc label is how cardinality explodes, and the rule that
outranks the allow-list is that no label may carry a tenant id, a user id, a URL, a query string, or
free text. A proposed label whose values cannot be enumerated is refused rather than appended.

Two conventions worth knowing before you propose one:

- If your family's results are not the shared four (`success` `error` `timeout` `cancelled`), name the
  label **`disposition`**, not `outcome`. Reusing `outcome` for a wider enum is valid PromQL that
  silently under-reports by exactly the traffic that failed.
- Span names in the catalogued tree are permanent. An off-catalogue span exports fine and matches no
  rule, alert or dashboard in the repository, so the failure is silence in the surface you would use to
  debug.

Alerting rules follow the same order: a class marked as paging in the error taxonomy must have a named
route, and CI diffs the two.

---

## Lockfiles and frozen installs

Every lockfile is committed: `composer.lock`, `uv.lock`, the generated `requirements.lock`, and one
`pnpm-lock.yaml` per workspace importer. CI installs frozen — `--no-install` / `--require-hashes` /
`--frozen-lockfile` — so a resolver that quietly picks a different version fails the build instead of
shipping.

- **`requirements.lock` is generated, never hand-edited** (ADR-022). `pyproject.toml` + `uv.lock` are
  authoritative; the export exists for `pip-audit` and the SBOM. CI regenerates it and diffs, so any
  manual edit — including adding a comment — is a red check.
- **One `pnpm-lock.yaml` per workspace importer, and the rule is not a count.** `.npmrc` sets
  `shared-workspace-lockfile=false`, so pnpm writes a lockfile for **every** importer — including the
  dependency-free ones. There are six today: `apps/{web,widget,mobile}` and `packages/contracts` carry
  real resolutions, and the root plus `packages/design-tokens` carry a 13-line file with an empty
  `.: {}` importer. **A dependency-free importer produces an _empty_ lockfile, not _no_ lockfile** —
  which is why the invariant is _the root importer resolves nothing_, not _the root has no lockfile_.
  `gates.yml` asserts it the correct way (it parses the root lockfile and checks the importer is
  empty); prose that says "four lockfiles, not five" was counting, and counting is what went stale.
  A root dependency is still a violation: it makes the root a resolution target the security scanners
  and the CI cache keys were not built for.
- **Exact pins.** `.npmrc` sets `save-exact=true`. A caret is how two packages that must move together
  drift apart between two installs of the same commit.
- **Base images are pinned by digest, model revisions by commit sha.** An unpinned `FROM` makes the
  SBOM a description of a build that no longer exists and makes "we fixed that CVE" unfalsifiable.
- **A dependency addition is a licence question.** The gate denies AGPL-3.0, SSPL-1.0, GPL-2.0/3.0,
  BUSL-1.1, Elastic-2.0, CC-BY-NC-\*, OpenRAIL-M, and fails closed on `UNKNOWN`. Model weights appear
  in no SBOM and are governed separately through `models.manifest.toml`.

---

## Do not edit `docs/01`–`docs/21`

They are verbatim specification extracts and `docs/00-index.md` says so. Four files in `docs/` are
writable: `00-index.md`, `19-repo-structure-adrs.md` (§28 only — the ADR list), `22-spec-findings-and-decisions.md`,
and `23-unverified-claims.md`.

If the specification is wrong — and it is, in twenty-seven catalogued places — that is a `docs/22`
entry plus, where a choice was made, an ADR. Correcting the extract in place would make the two
documents agree and destroy the record of the correction.

`.claude/skills/**` and `.claude/agents/**` are owned by the agents that maintain them. If a skill is
wrong, report it rather than rewriting it: documentation drifting from a skill is bad, documentation
silently rewriting one is worse.

---

## Commits

Conventional-commit style subjects, scoped to the area (`feat(retrieval): …`, `fix(core-api): …`,
`docs(adr): …`). Reference the ADR or the `docs/22` item a change closes. If a change closes a finding,
say which one — the log is only useful if items get marked closed.

# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Architecture decisions are **not** changelog entries. They live in `docs/19-repo-structure-adrs.md`
§28 with their reasoning in `docs/22-spec-findings-and-decisions.md`; this file records what changed
for someone running the software, and links to the ADR where a change has one.

## [Unreleased]

Nothing has been released. This section describes the repository skeleton — every top-level area
exists and is configured, and no business logic does.

### Added

- **Specification and decision record.** The original specification split into 21 verbatim sections
  under `docs/`, with an index, a table of the invariants most likely to be violated, a findings log
  (`docs/22`), and a triaged register of unverified claims (`docs/23`). ADR-001…030.
- **Root documentation.** `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE` (Apache-2.0,
  ADR-019), `NOTICE`, and this file.
- **Laravel control plane skeleton** (`services/core-api`) — application shape, tenant-scoped Eloquent
  conventions, the signed internal client, the SSE relay, and the Pest harness layout. One image
  serving six services (ADR-023).
- **FastAPI data plane skeleton** (`services/ai-service`) — the ASGI application, dependency-injected
  request context, Celery worker and queue topology with explicit task imports, and the provider
  adapter protocol. Three build targets keep Chromium and ragas out of the API image (ADR-023).
- **Next.js application** (`apps/web`) — admin console at `/*` and hosted chat at `/c/[publicBotId]`,
  with `Host`-to-namespace routing and a per-response CSP nonce in `proxy.ts` (ADR-027).
- **Preact widget** (`apps/widget`) — the host-page loader, the iframe application, the versioned
  postMessage bridge, and a `size-limit` budget on the app shell.
- **Expo application** (`apps/mobile`) — navigation, secure token storage, and the streaming chat
  surface built on `expo/fetch`.
- **`packages/contracts`** — one SSE frame parser, one `KbError`, the client event union, and the
  shared form schemas behind a `./forms` subpath so Zod stays out of the widget bundle (ADR-028).
- **`packages/design-tokens`** — tokens shared by web and widget.
- **Compose topology** (`infrastructure/docker`) — four networks with `data` and `observability`
  internal, digest-pinnable images, healthchecks, resource limits, stop grace periods, and three
  profiles. Corrects §24.5's `core` profile (ADR-024); the `gpu` overlay ADR-024 added was removed
  again by ADR-030, which left no GPU workload to overlay.
- **Observability stack** (`infrastructure/observability`) — Prometheus rules and unit tests,
  Alertmanager routing with an explicitly-named unrouted receiver, Loki, Tempo, and Grafana
  provisioned from git. The OTel Collector ships in every deployment and is reachable from `apps/web`
  over `edge` with no public route (ADR-024, ADR-025).
- **Security tooling** (`scripts/security`) — the licence gate, the vendored SAST rules and their rule
  count check, and the licence and vulnerability policy files.
- **Developer and operational scripts** (`scripts/dev`, `scripts/ops`) — `bootstrap.sh`, which
  generates local secrets and then asserts the object store refuses anonymous access; the local
  hostname guide; a reset script; a backup script; and a restore drill.
- **Root toolchain configuration** — pnpm 10 workspace with four lockfiles and a zero-dependency root,
  Node 22, TypeScript 5.9, ESLint 9 flat config per workspace, Prettier 3, and a `Makefile` whose only
  real justification is that `make deploy` writes the production `-f` flags down once (ADR-020).

### Changed

- **Embedding and reranking are no longer performed locally** (ADR-030). Both now go through the
  existing provider adapter layer, under each organization's own encrypted credential, with the same
  quota accounting and error taxonomy as chat. For anyone running the software this changes three
  things: **no GPU is required or supported** (`compose.gpu.yaml`, the `gpu` profile and the
  `runtime-gpu` build stage are gone, and the AI service image no longer carries CUDA wheels);
  **ingestion now has an outbound dependency and a per-document cost**, because chunk text is sent to
  the configured embedding provider; and **reranking may be unavailable**, since only some providers
  expose a ranking endpoint — the pipeline then serves the fused order and records the skip rather
  than failing. Parsing and OCR are unaffected and remain entirely local: `models.manifest.toml` now
  holds three rows, all Docling and RapidOCR.

### Known gaps

- **Model revision shas are unresolved** — three `TODO-RESOLVE-SHA` placeholders in
  `services/ai-service/models.manifest.toml`. `scripts/security/license_gate.py` is _written_ to
  reject them, but nothing invokes it: not a workflow, not a Makefile target, not a Dockerfile. So the
  placeholders are caught by review only, and they remain a blocker for the first image build because a
  placeholder is not a resolvable model revision.
- **`docs/22` has 27 findings open after scaffolding and 3 open after ADR-030.** O1, the one blocking
  item, is closed by ADR-029. Of the rest, **C2** is the largest: hybrid retrieval has lost its sparse
  producer, and whether it is replaced by local BM25 or dropped decides the shape of the query path.
- **CI is partial.** `.github/workflows/gates.yml` exists and carries six jobs — `enforcement-greps`,
  `observability-rules`, `compose-ci-tag-drift`, `compose-invariants`, `repo-artifact-consistency` and
  `boundary-greps` — deliberately scoped to pure text and file passes so they can be required checks
  before any lockfile exists. **`ci.yml` has not been authored**, so nothing that needs
  `composer install`, `uv sync`, `pnpm install`, a built image or a running stack runs anywhere: Pest,
  pytest, Vitest, Playwright, ESLint, `next build`, `size-limit`, and the ADR-010 Qdrant rebuild proof.
  Four further gates are specified but held out of `gates.yml` because they are **red today**, not
  because they were forgotten — the error-taxonomy-to-alert-name diff (eight classes uncovered by
  design), the `vuln-ignores.toml` `test_id` existence check, the `license_gate.py` model arm, and the
  metric-catalog and form-rules diffs. `CONTRIBUTING.md` § _The enforcement greps_ carries the split.
- **Roughly a third of the landed gates cannot fail on today's tree.** The corpus they scan is a
  skeleton, so the banned token has nowhere to appear yet. Each job prints a `vacuous-today:` ledger
  naming its own zero-candidate checks, so the gap is stated in the job output rather than implied
  away by a green tick.

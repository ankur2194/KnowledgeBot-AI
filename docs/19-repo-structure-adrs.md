# Repository Structure and Architecture Decision Records

> Part of the **KnowledgeBot AI** specification — §27–28, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 27. Repository Structure

A monorepo is recommended for this showcase because it makes contracts, infrastructure, documentation, and coordinated releases easier to demonstrate.

Suggested top-level areas:

- `apps/web` — Next.js admin and hosted web.
- `apps/widget` — Preact widget loader and iframe application.
- `apps/mobile` — React Native and Expo application.
- `services/core-api` — Laravel application.
- `services/ai-service` — FastAPI, RAG, ingestion, providers, and workers.
- `packages/contracts` — shared API schemas and generated clients where practical.
- `packages/design-tokens` — shared visual tokens.
- `infrastructure/docker` — Compose, proxy, and local environment.
- `infrastructure/observability` — dashboards and telemetry configuration.
- `docs` — product, architecture, operations, ADRs, and API documentation.
- `samples` — safe sample documents and evaluation datasets.
- `scripts` — controlled operational utilities.

The PHP, Python, TypeScript, and mobile projects retain their normal internal framework conventions.

---

## 28. Architecture Decision Records

Important decisions should be documented as ADRs.

### ADR-001: Use Direct Official LLM APIs

**Decision:** Integrate provider APIs directly and do not use LiteLLM Gateway.

**Reason:** Demonstrates provider adapter design, preserves provider-specific capabilities, removes an additional runtime dependency, and gives immediate access to official features.

**Trade-off:** More provider-specific implementation and maintenance.

### ADR-002: Use Laravel as the Control Plane

**Decision:** Laravel owns business logic, tenancy, authentication, configuration, and public APIs.

**Reason:** Strong fit for the developer's background and for conventional SaaS business capabilities.

### ADR-003: Use Python FastAPI for AI and RAG

**Decision:** Use FastAPI instead of NestJS for AI workloads.

**Reason:** Better document, OCR, embedding, reranking, and evaluation ecosystem.

### ADR-004: Use PostgreSQL as Source of Truth

**Decision:** Store authoritative product state in PostgreSQL.

### ADR-005: Use Qdrant for Vector Retrieval

**Decision:** Use Qdrant rather than only pgvector.

**Reason:** Demonstrates a dedicated vector engine and supports advanced hybrid retrieval workflows.

### ADR-006: Use Hybrid Retrieval and Reranking

**Decision:** Combine dense and sparse retrieval, then rerank.

**Reason:** Improves semantic and exact-term retrieval quality.

### ADR-007: Use Iframe Isolation for the Widget

**Decision:** Use a small loader and iframe-based chat application.

**Reason:** Prevents host-site styling conflicts and simplifies security boundaries.

### ADR-008: Use SSE for Chat Streaming

**Decision:** Use Server-Sent Events as the default response stream.

**Reason:** Simpler and appropriate for one-direction generation streaming.

### ADR-009: Use Docker Compose Before Kubernetes

**Decision:** Use Compose for development and portfolio deployment.

**Reason:** Keeps operations understandable and avoids unnecessary orchestration complexity.

### ADR-010: Treat Vector Data as Rebuildable

**Decision:** PostgreSQL and object storage are authoritative; Qdrant can be rebuilt.

**Reason:** Improves recoverability and avoids hidden state.

---

ADR-011 through ADR-018 resolve the eight questions the specification left open. They were decided
after the specification was written, so unlike ADR-001…010 each carries the alternatives it ruled out.
**The full argument for every one of them — including the failure symptom and, where one exists, the
revisit condition — is in [`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md).**
Read it there before reopening any of these; the summaries below are pointers, not the reasoning.

### ADR-011: Send the Provider Credential in the Internal Request Body

**Status: `Accepted`; its *cardinality* is widened by finding F12, ruled 2026-08-12 as
[`docs/22` § G7](22-spec-findings-and-decisions.md).** The field is a keyed map,
`provider_credentials: {connection_id: SecretStr}`, because one chat turn can need three keys — chat,
embedding and rerank. All three properties below are unchanged and hold for the map's **values**; only
the count moved, so this ADR is widened rather than superseded.

**Decision:** Laravel decrypts and sends the credential as a top-level `SecretStr` field, excluded
from the configuration snapshot hash and on the never-forward list.

**Reason:** Holding the KEK in FastAPI would force it to read Laravel's tables without reducing blast
radius; a separate fetch endpoint adds a hop to the 4-second first-token budget. Excluding it from the
snapshot is what keeps a key rotation from invalidating every cached answer — and keeps a plaintext key
out of every persisted snapshot. **Revisit if** the AI service ever leaves the operator's trust boundary.

### ADR-012: FastAPI Writes Four Derived Tables Directly

**Status: `Accepted`; its *table list* is superseded by [ADR-033](#adr-033-the-write-allow-list-is-allowed_tables-and-it-now-holds-six-names).**
The rule below — direct write against a closed allow-list, Laravel owning every table the public API
touches and every migration, and the activation flip staying Laravel's — stands unchanged and unedited.
What changed is only which names are on the list: ADR-032 added two sparse-statistics tables, and ADR-033
restates the invariant as the **three admission properties** rather than a count. The list itself lives in
`services/ai-service/app/db/writes.py`.

**Decision:** `services/ai-service` writes `chunks`, `document_elements`, `retrieval_traces`, and
`evaluation_results` — and nothing else. Laravel owns all migrations, every other table, and the
active-version pointer flip.

**Reason:** A callback ships every chunk's text through a PHP request body to a table Laravel never
reads. Direct writes are safe because they target the not-yet-active version, so nothing reads them
until Laravel flips the pointer. Enforced in CI as an allow-list.

### ADR-013: Keep `web` off the `application` Network

**Decision:** `apps/web` is on `edge` only, ratifying `kb-architecture-map`'s deviation from §24.3.

**Reason:** It has no Server Actions and produces no org-scoped server bytes, so it has nothing to ask
`ai-api` for. On `application`, "clients never reach FastAPI" would be enforced by nobody.

### ADR-014: Fallback Triggers on Capacity Signals, Not Unknown Models

**Decision:** Provider overload and capacity codes map to `provider_temporary` (retryable,
fallback-eligible); `model_not_found` stays `provider_permanent_request`. No new error class.

**Reason:** The model name came from the tenant's configuration, so an unrecognised model is a
misconfiguration they must see. Falling back would silently serve a different model at a different price
and quality with no error anywhere.

### ADR-015: Deletion and Erasure Have Different Reach

**Decision:** Source deletion breaks the citation link and retains `citations.excerpt`,
`retrieval_traces.selected_evidence`, and `evaluation_results.retrieved_evidence`. Data-subject erasure
is a superset that overwrites those three in place, under the same two-phase-plus-proof contract, and is
refused outright when it collides with a legal hold.

**Reason:** A transcript should survive an admin removing a stale document; a §18.10 erasure request must
not. Overwriting in place rather than deleting rows keeps historical metrics from silently moving.

### ADR-016: Do Not Adopt Haystack

**Decision:** Haystack is not part of this stack. Supersedes the §9.5 fencing sentence in
`docs/05-tech-stack.md` and the glossary entry in `docs/21-risks-licensing-glossary.md`.

**Reason:** Four verified disqualifications, any one of which is fatal: `filter_policy` leaves no
configuration in which our tenant filter and a facet filter both survive; `DocumentJoiner` discards the
per-branch scores the trace requires; its point identity makes delete-by-filter match nothing **and
report success**; and telemetry defaults on. `.claude/skills/haystack-pipelines/` is retained as the
record of why.

### ADR-017: Reaffirm ADR-001/003/005 Against Laravel 13's AI APIs

**Decision:** The control plane uses none of Laravel 13's AI SDK, `Str::toEmbeddings()`, or
`DB::whereVectorSimilarTo()`. Enforced by a CI grep over `services/core-api`.

**Reason:** Custody, not API quality. Those calls would sit outside the adapter contract's usage
accounting, and `whereVectorSimilarTo()` is a second retrieval path with none of the four mandatory
filters. **Revisit only if** Qdrant is dropped for pgvector — and even then, not the AI SDK.

### ADR-018: Absorb the Signing-Scheme Change into `v1`

**Decision:** The canonical string now covers every `X-KB-*` header under the existing `KB1` prefix. From
the first deployed release, any change to the canonical string, header set, or hash bumps the prefix, and
the verifier accepts both for one release window.

**Reason:** No signer has ever been deployed, so bumping now would make the version describe authoring
history rather than deployment history. The dual-accept window exists because signer and verifier deploy
at different times.

---

ADR-019 through ADR-028 were decided while the repository skeleton was scaffolded — the point at which
a choice the specification left implicit had to become a file. **All ten are `Accepted`**; ADR-001…018
are likewise accepted and none of them is superseded. They follow the ADR-011…018 pattern: **the full
argument, the alternatives, and — for ADR-019, 020, 021, 022, 025 and 028 — the revisit condition live
in [`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md)**, and the entries
below are pointers. Several of them are already cited from the artefacts they govern (`Makefile`,
`compose.yaml`, both `Dockerfile`s, `pyproject.toml`, `packages/contracts/package.json`,
`apps/web/src/proxy.ts`), so changing one without superseding it leaves those comments lying.

### ADR-019: Licence the Project Apache-2.0, with a NOTICE File

**Decision:** `LICENSE` is Apache-2.0 and `NOTICE` ships beside it. Third-party attribution required by
§35 accumulates in `NOTICE`, not in the README.

**Reason:** We hand images and source to self-hosters we do not vet, so a patent grant they receive
directly (§3) is worth more than the extra permissiveness of MIT. AGPL-3.0 and BUSL-1.1 are both on the
licence gate's *deny* list for our dependencies, on the argument that a self-hoster inherits terms they
never agreed to — adopting one of them as our own outbound licence would refute that argument in the
same repository that enforces it.

**Trade-off:** Apache-2.0 permits a competitor to run this as a service and contribute nothing back,
which is exactly what BUSL exists to prevent. Accepted; see `docs/22` for why the alternative costs
more than it saves here.

### ADR-020: Pin the Toolchain the Specification Left Open

**Decision:** pnpm 10.x via `packageManager`, Node 22 LTS, TypeScript 5.9, ESLint 9 flat config **per
workspace** plus `eslint-plugin-security`, Prettier 3, ruff 0.14 + mypy 1.18 for Python, **Larastan
level 8 with no baseline**, and `jest-expo`'s major locked to the Expo SDK major.

**Reason:** §9 names frameworks and no versions, so every workspace would otherwise resolve its own.
Level 8 rather than 9 is the one entry with a real argument: level 9 makes every Eloquent `mixed` an
error, which produces a baseline inside a week — and a baselined analyser reports on code nobody is
writing any more, so it is a disabled one with a green check.

**Trade-off:** Level 8 accepts unchecked `mixed` in exactly the Eloquent surface where a tenancy bug
would hide. That gap is covered by the CI tenancy greps and the two-organization Pest harness, not by
the analyser.

### ADR-021: Pin `qdrant-client` at 1.18.0 Against Server v1.18.3

**Decision:** `qdrant-client==1.18.0`, resolving the 1.18.0-versus-1.19.0 disagreement between
`qdrant-hybrid-search` and `kb-tenancy-isolation` in favour of the pin that matches the server tag
`docker-compose-stack` runs.

**Reason:** Client and server minors move together in Qdrant, and the mismatch does not fail loudly —
it fails at the one call whose newer field the older server ignores.

### ADR-022: `pyproject.toml` + `uv.lock` Are Authoritative; `requirements.lock` Is a Generated Export

**Decision:** Python dependency resolution is uv's. `requirements.lock` exists only because `pip-audit`
and the SBOM tooling read requirements format, is committed, and is regenerated and diffed in CI.

**Reason:** Two hand-maintained lockfiles for one dependency graph diverge, and the divergence is
invisible: the image builds from `uv.lock` while the scanner reads `requirements.lock`, so the audited
tree is not the shipped tree.

### ADR-023: Dockerfiles Live Beside Their Service

**Decision:** Each `Dockerfile` sits in the directory it builds. `services/core-api` is **one image
serving six services** and contains nginx plus php-fpm. `services/ai-service` has three shipped
targets: `runtime`, `runtime-crawl`, `runtime-evaluation`.

**Reason:** A Dockerfile under `infrastructure/` makes every `COPY` a `../..` and takes the
`.dockerignore` out of the build context. nginx is *inside* the core-api image because Traefik v3 has
no FastCGI provider, and a separate nginx service would be a third member of the edge∩application set
ADR-013's check closes. The ai-service split keeps Chromium (~500 MB, only the crawler needs it) and
ragas (a provider-calling evaluation harness) out of the image that serves chat.

**Trade-off:** Six services share one digest, so a change for one rebuilds all six; and three Python
targets is three images to scan.

### ADR-024: Correct §24.5's Compose Profiles

**Decision:** There is **no `core` profile**. GPU is an **overlay file** (`compose.gpu.yaml`), not a
profile. `otel-collector` carries no `profiles:` key and ships in every deployment.

**Reason:** Compose enables every service *without* a `profiles:` key, so tagging the required services
`core` makes a bare `docker compose up` start nothing. A profile includes or excludes a whole service
and cannot patch a device reservation onto `ai-api`. And a collector behind the `observability` profile
means a deployment without that profile drops every span at the exporter with no error.

### ADR-025: `otel-collector` Joins `edge`, With No Traefik Router

**Decision:** `otel-collector` is on `[edge, observability]`, has no router, no labels, and
`kb.edge: "false"`. Browser telemetry posts same-origin to a Next route handler, which forwards over
`edge`.

**Reason:** One membership solves both halves: `apps/web` reaches `http://otel-collector:4318`
server-side, and the browser never learns the collector's address — no CORS block, no public route.
The alternative, putting `web` on `observability`, would make `ai-api` resolvable from a route handler
and reopen exactly the hole ADR-013 closes.

**Trade-off:** The collector sits on a non-`internal` network and so has egress it does not need.

### ADR-026: One SeaweedFS Container With `-s3.config`; Two Valkey Services

**Status: `Accepted`; its *fail-open reasoning* is superseded by [ADR-037](#adr-037-the-fail-open-pair-is-the-other-way-round-and-the-probe-that-would-have-caught-it-could-not).**
Both halves of the **Decision** below — one SeaweedFS container started with `-s3.config` plus the
`seaweedfs-s3` alias, and two Valkey services rather than two logical databases — stand unchanged and
unedited, and so does the whole Valkey `maxmemory-policy` argument. What changed is only the sentence
about **what happens when a file is missing**: measured against the pinned tags on 2026-08-11, an absent
or zero-byte `identities.json` is a **fatal** startup error, and it is Valkey that comes up wide open
without its `aclfile`. The Reason below states both the other way round. ADR-037 carries the
measurement, what it changes about the mitigation, and the sites still repeating the old claim.

**Decision:** SeaweedFS collapses §24.2's four services into one container, started with an explicit
`-s3.config` identities file and a `seaweedfs-s3` network alias. Valkey is **two services**, not two
logical databases: `valkey-core` (`noeviction`, AOF) and `valkey-cache` (`allkeys-lru`, persistence
off).

**Reason:** Without `-s3.config` the S3 gateway runs Allow-All — it **fails open**, so a mistyped or
unreadable path yields a wide-open object store that starts cleanly and logs nothing; `bootstrap.sh`
asserts an anonymous `ListBuckets` is refused because you cannot see this by looking. The alias is
needed because both clients are configured for `seaweedfs-s3` while the service is named `seaweedfs`.
The Valkey split is not a naming convention: `maxmemory-policy`, `appendonly`, and `save` are all
server-level, so `SELECT n` cannot separate a queue that must never evict from a cache that must.

### ADR-027: `apps/web` Route Namespaces — Admin at `/*`, Hosted Chat at `/c/[publicBotId]`

**Decision:** Admin owns the root namespace; hosted chat lives under the single `/c/` prefix. `proxy.ts`
maps `Host` to namespace and rewrites a bare `/<publicBotId>` on `chat.<domain>` under `/c/`. That
mapping is **routing and never authorization**.

**Reason:** Two sibling root groups both want `/`, which Next cannot resolve; and with chat unprefixed,
the static `/bots` route beats the dynamic chat route, so `chat.<domain>/bots` renders the admin bots
page. Host-as-authorization is refused because CVE-2025-29927 skipped every Next middleware with one
request header — Laravel's 401/403/404 is the gate.

**Trade-off:** Hosted-chat URLs carry a `/c/` segment that serves no visitor.

### ADR-028: `packages/contracts` Ships Two Subpath Exports

**Decision:** `"@kb/contracts"` is zero-dependency and budgeted at ≤ 1 kB brotli inside the widget's
30 kB shell. `"@kb/contracts/forms"` carries the Zod schemas with `zod` as an **optional peer**.

**Reason:** The alternative — one entry point, relying on tree-shaking to keep Zod out of the widget —
is precisely the silently-passing size gate `preact-vite-library` warns about: it holds until one
import shape defeats it, and the first signal is a red `size-limit` run on a PR that touched no widget
file. A subpath makes the exclusion structural, and `apps/widget/eslint.config.mjs` bans the import
outright.

**Trade-off:** Two build entries, two type-resolution surfaces, and a peer dependency `apps/web` and
`apps/mobile` must remember to install.

---

ADR-029 is the first decision here taken against **landed code rather than a file that had to exist**.
Finding O1 was not an open question — it was a live divergence between two error handlers already
written, each locally defensible, rendering one failure two contradictory ways. It follows the
ADR-011…028 pattern: **the full argument, the rejected alternatives, and the revisit condition are in
[`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md)**; the entry below is a
pointer. `services/ai-service/app/core/errors.py` and `app/main.py` cite it by number, so changing it
without superseding it leaves those comments lying.

### ADR-029: `internal_dependency` Takes an `origin` Axis; the Taxonomy Stays at 18

**Decision:** `internal_dependency` gains a second axis, `Origin`, exactly as `authorization` already
has a second axis in `Surface`. `origin: downstream` (the default) keeps **503 / `retryable: true`**;
`origin: self` — an unmapped exception in our own code — renders **500 / `retryable: false`**. `origin`
is **not** a wire field: it selects a status and a `retryable` value the envelope already carries.
**`ErrorClass` stays at exactly 18**, and `assert len(ErrorClass) == 18` stays.

**Reason:** A bug is not a brownout. 503 / retryable tells a client to run a full backoff ladder against
a defect that cannot succeed on any attempt, and Laravel already said 500 for the same failure — so a
consumer, which cannot tell which plane produced an envelope, got two contradictory instructions from
one fault. A **19th class** was rejected: the count is asserted at import, quoted across at least five
skills, and every adapter mapping table would grow a row, all to express a rendering difference on one
row. **Collapsing both planes to 500** was rejected because `downstream`'s 503 reading is correct and
load-bearing — it is the honest "come back shortly" a real dependency outage deserves.

**Trade-off:** Two materially different failures now share one `error_class`, and `error_class` is the
metric label. The closed label allow-list has no `origin`, so **no dashboard or alert can separate our
own defects from a dependency brownout** — both page, under one series. **Revisit when** that split is
needed on a dashboard, or when a second class wants an `origin`: at that point "a second axis on exactly
one row" has stopped being an idiom and become an undeclared dimension, and the honest move is to
restructure the taxonomy rather than grant a third exception.

---

ADR-030 is a **scoping** decision rather than a technical one: it draws the line between the machine
learning this project performs and the machine learning it buys. Unlike every ADR above it, it did not
come from the specification, from a file that had to exist, or from landed code — it came from the
product owner (2026-08-06, restated 2026-08-07), and it invalidates a model stack that §9.12 names by
name. It is **`Accepted`**. It follows the ADR-011…029 pattern: **the full argument, the rejected
alternatives, the revisit condition, and the three consequences it leaves open are in
[`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md)**; the entry below is a
pointer. `services/ai-service/app/main.py`, `app/core/config.py` and `app/api/health.py` cite it by
number, so changing it without superseding it leaves those comments lying.

### ADR-030: No Local Model Inference for Embedding or Reranking

**Decision:** Embeddings and reranking are produced by **external providers through the existing
provider adapter layer** — the same abstraction that serves chat, the same per-organization encrypted
credentials, the same quota accounting, the same error taxonomy — through two new capabilities,
`embed(texts, *, model)` and `rerank(query, passages, *, model)`. Not a second credential path and not
a single global vendor. No embedding or reranking model is loaded in this repository, and there is no
GPU deployment. **The line falls below document processing:** Docling's layout and TableFormer models
and RapidOCR's ONNX weights stay local, and so do `/models`, `HF_HOME`, `HF_HUB_OFFLINE=1`,
`models.manifest.toml`, `picklescan`, and the model-weights arm of the licence gate. The rule is *no
local model inference for embedding or reranking*, **not** *no local computation* — BM25 is a
statistical ranking function, not a model, and is not excluded by it. **Narrows ADR-006**, which stays
accepted: hybrid retrieval and reranking remain the design, but reranking becomes capability-gated and
the sparse arm loses its producer. **Supersedes the model choices in §9.12** (`docs/05` — BGE-M3 and
`bge-reranker-v2-m3`) and every §9.12-derived mention listed in `docs/22`.

**Reason:** The local stack cost a **2 GB embedder plus a 1.5 GB reranker resident in every worker
process** — which is what forced `ai-api` to `WEB_CONCURRENCY=1` and made container replicas the only
scaling unit — and it required a self-hoster to provision a GPU to reach the specified 1.5 s retrieval
budget, for a product benefit the tenant never sees. The alternatives were both considered and both
rejected. **Keeping the local models** keeps every one of those costs on every deployment and keeps two
model families under version, licence, revision-sha and CVE management for capabilities five vendors
already sell. **A single global embedding vendor on one platform credential** is cheaper to build and
was rejected because it puts customer chunk text on a vendor the organization did not choose, under a
credential no organization can rotate, outside the quota accounting and the error taxonomy every other
provider call already flows through — a second credential path is the thing this platform most
consistently refuses to have.

**Trade-off:** Three, each recorded as an open finding in `docs/22` with a recommendation and **no
decision taken here**: reranking is no longer universally available (**C1** — two of five configured
providers expose a ranking endpoint), hybrid search loses its sparse producer (**C2** — API embedding
endpoints return dense vectors only), and embedding-model identity becomes a vendor **alias** that can
be re-pointed at re-trained weights with no diff here, no error at the call site and no metric anywhere
(**C3**). Two more that are not findings but are real: customer chunk text now leaves the deployment
for the organization's configured embedding provider, which local embedding did not; and query
embedding plus reranking are now network round trips inside the 1.5 s retrieval leg. **Revisit when**
any of: an **air-gapped deployment** with no provider egress becomes a supported shape (the platform
cannot embed at all in that world, and a local embedder returns as an optional deployment *profile*,
never as the default); measured embedding spend per ingested document exceeds the amortized cost of the
GPU this removed; or the query-embedding round trip's p95 pushes the retrieval leg past its 1.5 s
budget on the default configuration.

---

ADR-031 through ADR-035 are the decisions **ADR-030's consequences forced**. ADR-030 was accepted with
three consequences deliberately left open — C1, C2 and C3 in
[`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md) — and each of them has now
been answered by code that had to choose in order to exist. They follow the ADR-011…030 pattern: **the
full argument, the rejected alternatives and the revisit condition are in `docs/22`**; the entries below
are pointers. All five are **`Accepted`**. One of them, **ADR-033, supersedes ADR-012's table list** —
the only supersession in this register so far, and ADR-012's entry above carries the pointer.

Each is cited by number or by finding name from the module that implements it
(`app/providers/embedding_selection.py`, `app/api/internal/v1/embedding.py`, `app/retrieval/sparse.py`,
`app/retrieval/collection.py`, `app/ingestion/embedding/embedder.py`, `app/db/writes.py`, and the two
Laravel migrations `2026_08_07_000500` and `2026_08_07_000600`), so changing one without superseding it
leaves those comments lying.

### ADR-031: The Organization Designates the Embedding Connection; Disagreement Is a Refusal

**Decision:** Which of an organization's provider connections supplies the embedding credential is
answered by **one** function — `resolve_embedding_connection` in
`services/ai-service/app/providers/embedding_selection.py` — called by the indexer, the query path and
the connection-save path alike. An explicit designation, stored on `organizations` as the **pair**
`embedding_connection_id` + `embedding_model` (composite FK to `provider_connections
(organization_id, id)`, `ON DELETE RESTRICT`, plus `CHECK num_nonnulls(...) <> 1`), wins outright and is
never substituted. Otherwise ineligible candidates are dropped with a recorded reason, and **eligible
candidates that disagree on `(provider, model)` are a hard `validation` failure, not a tiebreak**. Only
when the eligible set names one space does the deterministic smallest-key rule pick among them, and that
step chooses a *payer*, never a space. The same computation is exposed to Laravel as
`POST /internal/v1/embedding/readiness`, which answers **200 with `selected: null`** for an organization
that cannot embed.

**Reason:** `(provider, model)` *is* the vector space — `EmbeddingSpace` derives the Qdrant collection
name from it — so breaking a tie by convention would let an unrelated connection edit move the space
under an indexed corpus. Cosine distance is defined between any two vectors of equal width, so nothing
raises, no metric moves and there is no diff; only ranking changes. **Per-bot designation** was ruled out
on the contract, not on taste: a source is embedded before it is assigned to any bot, so one source's
chunks would sit in one space while a second bot queried another. **Laravel computing the answer
locally** was ruled out because the vendor axis is repository data with a source per cell, and a looser
local "is at least one eligible" check passes an ambiguous configuration at save time and fails it at the
first upload — after the parse and the OCR spend. **A platform-default embedding credential** was refused
as a privacy boundary rather than on cost.

**Trade-off:** adding a second embedding-capable connection on a different model **stops an
organization ingesting** until an operator designates one — a working configuration broken by an
unrelated addition, accepted because the alternative is silently indexing into a different space. The
refusal is also only as good as the capability matrix: four of the five embedding cells are `UNVERIFIED`
and fail closed, so an organization on a vendor that does publish an endpoint can be refused for a
missing fixture (`docs/23`). **Revisit when** a second vendor's embedding cell becomes `SUPPORTED` —
ambiguity stops being rare and the "designate one" instruction starts firing on ordinary configurations —
or when one organization legitimately needs two embedding spaces at once, at which point the designation
is no longer an organization column.

### ADR-032: Restore the Sparse Arm With Local BM25; Corpus Statistics Ride the Query Vector

**Decision:** C2 resolves toward **local BM25** (`services/ai-service/app/retrieval/sparse.py`), which
ADR-030 does not reach — a statistical ranking function is not a model. The **document side carries
saturation and length normalization only**: no `N`, no `df`, and `BM25_AVGDL` is a fixed constant rather
than a live corpus average. The **IDF factor is applied to the query vector**, from statistics scoped to
`(org_id, allowed_version_ids)` and bound to that scope by `CorpusStatistics.for_scope`. `SPARSE_MODIFIER`
stays `None`. IDF uses the Lucene form `ln(1 + (N - df + 0.5) / (df + 0.5))`. A statistics read that
fails is an **error**, never a degradation, and an empty sparse vector is never written or queried.

**Reason:** a document vector carrying corpus statistics would be invalidated by every subsequent ingest,
and an ADR-010 rebuild would produce a *different* index that passes every count and checksum the drill
specifies — only the ranking would move. Qdrant's own `modifier=IDF` computes document frequencies
**collection-wide across every tenant** on the pinned 1.18.3 server: a relevance error and a weak
cross-tenant oracle. **Dropping the sparse arm** (C2's option (a)) was rejected because dense-only
retrieval with an optional reranker is cosine similarity and nothing else, and exact tokens — part
numbers, error codes, surnames, API symbols — are precisely what the lexical arm is for. **Classical
Robertson/Sparck-Jones IDF** was rejected because it goes negative once a term appears in more than half
the documents, and under a dot product that makes a *matching* term subtract from the score.

**Trade-off:** BM25 is not BGE-M3's learned lexical head — no synonymy, no subword generalization, and
zero term overlap across scripts, so cross-lingual recall now rides entirely on the dense arm. The
statistics are a second write path (ADR-033) and one more PostgreSQL read inside the 1.5 s leg. And
`tokenize` is **unimplemented**: the arm produces nothing until a segmentation decision is made, which is
the open half of C2. **Revisit when** the server pin moves to ≥ 1.19.0 *and* `IdfCorpusParams` is
confirmed to scope document frequencies by filter — query-side IDF becomes a choice rather than the only
tenant-safe route — or when an evaluation run shows the measured chunk-length distribution far from
`BM25_AVGDL`.

### ADR-033: The Write Allow-List Is `ALLOWED_TABLES`, and It Now Holds Six Names

**Status: `Accepted`. Supersedes ADR-012's table list** — and nothing else in ADR-012, whose rule,
reasoning and constraints all stand.

**Decision:** the data plane's PostgreSQL write allow-list is whatever `ALLOWED_TABLES` in
`services/ai-service/app/db/writes.py` holds, and **admission is three properties, not a count**: the row
is derived and rebuildable in the ADR-010 sense; no public API path reads or writes the table; Laravel
owns the migration. `sparse_version_statistics` and `sparse_term_frequencies` are admitted on those
grounds (ADR-032), bringing the list to six. **Documentation names the invariant and points at the
module; it does not restate the list or the number.**

**Reason:** "exactly four" became false the moment a legitimate table was added, and "exactly six" will
fail the same way — a number restated in seven files is seven things to forget, and the properties are
what a reviewer must actually check. **A Laravel callback** for the statistics was rejected on ADR-012's
own arithmetic, which is worse here than for chunks: one row per `(version, term)`, tens of thousands per
document, shipped through a PHP request body into a table Laravel never reads. **Hanging the per-version
document total on `source_versions`** was rejected because that table does not exist in this repository,
and inventing it to hold a BM25 counter puts a `kb-source-lifecycle` table inside a change about lexical
retrieval. **Computing document frequencies from Qdrant at query time** is the cross-tenant statistic
ADR-032 exists to avoid.

**Trade-off:** the cardinality is what CI asserts today, so the gate is **red against the tree** until it
is updated — and once it is, a count check has to become a membership check or it stops meaning anything.
A six-name list is also a weaker signal in review than a four-name one, and the drift this most fears is a
seventh name admitted by "one more is fine". **Revisit when** a proposed name satisfies all three
properties and still feels wrong: that is evidence the properties are incomplete, not that the list should
grow.

### ADR-034: `sparse_analyzer` Is Part of the Embedding Space, So an Analyzer Change Is a Reindex

**Decision:** `SPARSE_ANALYZER_VERSION` is a field of `EmbeddingSpace` and is folded into the blake2b
digest that names the collection (`services/ai-service/app/retrieval/collection.py`). Changing the
tokenizer, the normalization, the term-id hash or any of the three pinned BM25 parameters bumps it,
which changes the collection name, which is a reindex. `assert_analyzer` guards both the write and the
query path.

**Reason:** two analyzers sharing one collection **do not error**. Sparse indices are unsigned integers
and every integer is legal, so a foreign term id matches no posting: the mismatched half of the corpus
quietly loses its lexical recall while dense retrieval keeps every panel populated. Unlike a dense width
mismatch there is nothing for the server to reject. Putting the version in the space makes the migration
the one this repository already has — a second collection, both live, the active-version pointer moving
once. **An `analyzer` payload field filtered at query time** was rejected: it leaves two encodings in one
collection, costs a payload index and a term on every query, and fails *open* the first time a query
forgets the term. **A name suffix outside the digest** reproduces the slug-collision hazard the digest
exists to close. **Doing nothing** fails silently, permanently, with no exception and no diff.

**Trade-off:** the cost is paid in the wrong currency. A bump changes the collection name, so it re-embeds
the **dense** vectors too — and those are billed provider calls now (ADR-030). A tokenizer fix after the
first large ingest is a corpus-wide re-embed with a bill attached, not a configuration edit, so the
tokenizer must be settled first. **Revisit when** Qdrant can re-encode one named vector of a collection in
place, or when the sparse and dense branches are split into separate collections — either makes the
analyzer's identity independent of the embedding space's, and the coupling then costs money for nothing.

### ADR-035: Embedding-Model Identity Is Measured by a Fixed Probe, and the Digest Never Names a Collection

**Decision:** identity is `EmbeddingSpace` **plus** a `canary_digest` — a sha256 over the vectors a
provider returns for five fixed, dull, in-repo probe strings, embedded as `PASSAGE`, formatted
fixed-point to four decimals with negative zero folded, non-finite components raising, and the scheme,
precision, probe index and width mixed into the payload
(`services/ai-service/app/ingestion/embedding/embedder.py`). `classify_canary` returns five verdicts and
checks `DIFFERENT_SPACE` **before** comparing digests; `CONFIRMED_DRIFT` requires two consecutive
disagreements and raises. **Nothing is repaired automatically.** The digest goes into
`embedding_model_version` and therefore into the ingest key; it is deliberately **not** in the space, and
therefore not in the collection name.

**Reason:** a vendor embedding model id is an alias with no dated snapshot. A silent re-train yields
vectors from a different space at the same width, and cosine distance accepts them — no error, no metric,
no diff, and ranking that degrades for the older half of the corpus forever. The served-model string is
the alias echoed back, so a probe is the only detector available. **The digest inside the space** was
rejected because a vendor blip would silently spawn a second collection, the corpus would divide itself
between two of them, and the automatic repair would look exactly like a healthy bootstrap. **Hashing raw
floats** was rejected because embedding fleets are not bit-reproducible, and a digest in the ingest key
that flaps turns every resubmission into a new source version. **`round()`** was rejected for
shortest-repr: it emits `0.1` for one float and `0.09999999999999999` for its neighbour, so a "rounded"
digest still moves on the last bits. **One probe** was rejected because a re-quantization can leave a
single vector unmoved. **Failing on the first disagreement** hands the vendor an outage switch.

**Trade-off:** the detector's sensitivity is one **unmeasured** constant — too fine and it flaps and
re-versions the corpus, too coarse and a real re-quantization lands inside the rounding. The probe is
fixed to the passage side, so a drift affecting only the query path is invisible to it. And detection is
not response: where the disagreement counter lives, what pages, and the reindex runbook are all still
open (`docs/22` § C3). **Revisit when** the hourly probe flaps at precision 4 against any configured
provider — the answer is then not a finer constant but **removing the digest from the ingest key** and
keeping it in telemetry only — or when a vendor publishes pinnable dated embedding snapshots, which
demotes the canary from sole detector to cheap corroboration.


### ADR-036: A Documented Invariant States a Rule or a Measuring Command, Never a Count

**Status: `Accepted` 2026-08-12 — option (a), narrowed, exactly as the *Recommendation* below states
it.** Proposed 2026-08-10; ruled on by Ankur alongside the eight other open items closed that day. The
argument, the full option set and the evidence are in
[`docs/22`](22-spec-findings-and-decisions.md) § *The scope re-baseline — 2026-08-10*; what the
acceptance immediately cost is in § *The rulings of 2026-08-12*, **G1**.

**Context.** ADR-033 already made this call once, for one list: *"admission is three properties, not a
count"*, on the reasoning that `"exactly four"` became false the moment a legitimate table was added
and `"exactly six"` would fail the same way. The 2026-08-10 re-baseline found **six more instances of
the identical defect** in six different documents — one workflow file, four lockfiles, three
unresolved model shas, zero migrations, zero FormRequests, four writable tables — each true when
written, each false within weeks, and **none of them detected by anything**, because prose is not
executed. That is not six mistakes; it is one convention that does not exist yet.

**Options.**

**(a) Generalize ADR-033: no cardinality in prose.** Where a membership rule exists, state the rule.
Where only a fact exists, state the fact **and the one-line command that re-measures it**, so a reader
can check in seconds instead of trusting the sentence. *Costs:* a rule reads as vaguer than a number,
and "six jobs" is genuinely more useful to a reviewer than "some jobs" — some counts are load-bearing
orientation, not invariants, and a blanket ban makes documents worse in exactly those places.

**(b) Keep counts, add a CI gate that re-derives each one.** *Costs:* a gate per count, each one a
bespoke parser, and the gate becomes the thing people debug. It also cannot see a count in a sentence
it was not told about, which is how all seven of these survived.

**(c) Keep counts and accept the drift**, correcting on the next audit. *Costs:* this **is** the
status quo, and the re-baseline measured what it costs — the false claims propagate. `docs/22` § E4
recorded a false enforcement claim reaching three citations before anyone noticed.

**Recommendation: (a), narrowed.** Ban a count only where it is an **invariant** — something a change
could violate. Orientation counts ("seven jobs", "eighteen error classes") stay, provided the sentence
names where the authoritative list lives so a reader can check rather than believe. The distinguishing
question is *"would a legitimate change make this number wrong?"* — if yes it is an invariant and must
be a rule; if no it is orientation and may be a number.

**Decision (accepted 2026-08-12): the recommendation, verbatim.** A count that a legitimate change
could make wrong is an **invariant** and must be written as a rule, or as the one-line command that
re-measures it. A count that no rule rests on is **orientation** and may stay a number, on the single
condition that the sentence names where the authoritative list lives.

**How the test is calibrated, because its two halves read as if they disagree.** Taken literally,
*"would a legitimate change make this number wrong?"* is true of almost every number — adding a job
makes "seven jobs" wrong. The recommendation's own examples are what settle it, and they are part of
the decision: *"seven jobs"* and *"eighteen error classes"* are named as orientation that **stays**.
The question the test is actually asking is whether the number **is** the invariant — whether a
reader would act on the count itself as the rule. `ALLOWED_TABLES` has four names *is* the rule a
reviewer applies, so it is banned; *the gate suite has six jobs* was a description of a file that
named itself, so it stayed (that file has since been deleted, which is the cleanest possible
illustration of why the count was never the rule). When the two readings disagree, write the rule and the command both: that
outcome is never wrong, only longer.

**Consequences, including the ones that hurt.**

- **A rule reads as vaguer than a number, and sometimes it is.** *"Admission is three properties"*
  does not tell a reviewer at a glance whether the list grew this week; *"six names"* did. The
  compensation is the measuring command, and a command is only better than a count if it measures the
  right thing (`docs/22` § R5, where a `grep -c NotImplementedError` counted prose in comments) and if
  its **scope** is the claim's scope (`docs/22` § *Not corrected here*, where widening a grep past
  `.claude/` made it fail on a correct tree). Both of those were learned the expensive way and neither
  is implied by this ADR's one-line statement.
- **This ADR forbids nothing that CI enforces.** Prose is still not executed; the convention is a
  review habit with no gate behind it, which is precisely option (b)'s complaint about option (a).
  The honest claim is that it makes the defect *nameable*, not that it makes it detectable.
- **It cost something on the day it was accepted.** Its own evidence row, `docs/22` § R5, had decayed
  again — three of the seven figures published in it and in `CLAUDE.md` were false against the tree
  within days of the batch that wrote the bodies landing them. That is recorded as **G1** and
  is deliberately not treated as a counter-argument: it is the first observation of the thing the
  convention exists to stop, made by applying the convention.

**Revisit condition:** the next time a count in a document is found false against the tree **after
this date**. One recurrence is evidence the convention did not take, and the answer is then (b) for
that specific number — a gate that re-derives it — rather than another round of prose corrections.
The R5 decay of 2026-08-12 does **not** count against this, because it predates the acceptance by
hours; the clock starts here.

---

ADR-037 is the second supersession in this register, and like ADR-033 it replaces **one claim inside an
accepted ADR rather than the ADR**. It came from neither the specification nor a file that had to exist:
it came from the credential-file audit (#66) putting the two containers on a host and reading their exit
codes. It follows the ADR-011…036 pattern — **the full argument, the measurement, the rejected framings
and the revisit condition are in [`docs/22`](22-spec-findings-and-decisions.md)**; the entry below is a
pointer.

### ADR-037: The Fail-Open Pair Is the Other Way Round, and the Probe That Would Have Caught It Could Not

**Status: `Accepted`. Supersedes ADR-026's fail-open reasoning** — and nothing else in ADR-026, whose
decision, topology and Valkey server-settings argument all stand.

**Decision:** the two reputations ADR-026 recorded are inverted, and the mitigations follow the
measurement rather than the folklore. **`seaweedfs:4.40` fails _closed_ on an absent, directory-shaped
or zero-byte `identities.json`** — the config load is fatal and the process exits **255**, so the
container crash-loops instead of serving. The Allow-All state is real but is reached two other ways:
**dropping `-s3.config` from the command**, or a config that parses to `"identities": []`. **`valkey:9.1.1`
fails _open_ on an absent or zero-byte `aclfile`** — it starts cleanly as `user default on nopass ~* &*
+@all` and an unauthenticated `SET pwned 1` returns `OK`. So the mandatory-file argument applies to
**both** files, for opposite reasons, and `users.acl` is the one that must exist before `docker compose
up`, not after. Both Valkey healthchecks merge stderr and require the whole probe output to equal
`PONG`; the SeaweedFS healthcheck asserts an anonymous `ListBuckets` is refused every 15 s rather than
once at bootstrap.

**Reason:** the old healthcheck, `valkey-cli … ping | grep -q PONG`, returned **exit 0 against a
wide-open server**. The failed `AUTH` goes to *stderr* while the `nopass` default user answers `PING` on
*stdout*, so `grep` matched, Docker reported **healthy**, and the one state the probe existed to detect
was the one state it could not report. The probe had been hardened against a server rejecting
everything and was blind to one accepting everything — which is the general shape: a liveness check
written against the loud failure cannot see the silent one. On the SeaweedFS side, the same audit found
`/cluster/healthz` answering happily on a gateway running full Allow-All, so the master's liveness says
nothing at all about the gateway's auth posture. **Reasoning from vendor documentation** was the framing
that produced the inverted claim in the first place and is rejected on the evidence: every sentence
corrected here was plausible, was repeated in every file that had reason to mention it, and survived
fifty-odd research passes. `docs/22` § ADR-037 lists the sites still carrying it with `file:line` and
an owner, so the extent is a table rather than a number. The rule is to run the container and read the
exit code.

**Trade-off:** the correction makes `users.acl` a deploy-blocking artifact with a **mode** requirement
that differs from its neighbour's — the valkey entrypoint drops to uid 999 so the file must stay `0644`,
while seaweedfs runs as root and takes `0600` — so two credential files that look symmetrical now carry
asymmetric, unenforced permission bits, and getting one wrong is a stack that will not boot. And the
15-second anonymous-`ListBuckets` probe runs **inside** the container, so it cannot see a network-level
exposure and a 403 does not prove the keys are the right ones; it is a probe, not the control.
**Revisit when** either image is bumped — this rests on the measured behaviour of `seaweedfs:4.40` and
`valkey:9.1.1` and on nothing either vendor documents, so a tag bump re-opens both halves and the check
is to re-run the four states (file absent, directory, zero-byte, well-formed) against each image rather
than to re-read this entry.

---

ADR-038…043 come from one effort — building user authentication and session management across
`services/core-api` and `apps/web` — which made forty-four numbered decisions. They are **six** ADRs, not
forty-four, because most of those forty-four are implementation rulings that record no architectural
choice: which file a helper lives in, which parameter is untyped to avoid an LSP fatal, which Vitest
project needs a `define`. The six below are the ones a future change can *violate*. As with ADR-011…037,
**the full argument, the options actually considered, the rejected alternatives and the reversals are in
[`docs/22`](22-spec-findings-and-decisions.md)** § *The auth-and-session decisions — ADR-038…043*; the
entries here are the decisions and what each one costs. That section also carries the reversals, which
are the part most likely to be re-litigated: six of the forty-four overturned an earlier decision in the
same effort, and one of those overturned a formula whose author had published it as measured.

Two of these carry a numbering hazard worth stating once. The effort's own decisions are cited as
**plan D1…D44**; `docs/22` § G6 already uses the bare label `D7` for an unrelated tracker item, so the
`plan D<n>` form is used everywhere below rather than the bare number.

### ADR-038: One Authentication Mechanism on the Admin Surface, Enforced by Three Negatives

**Decision:** the admin surface (`api/*`) has exactly one credential — the Sanctum SPA cookie session,
`HttpOnly`, `Secure`, `SameSite=Lax`, with the CSRF token echoed as `X-XSRF-TOKEN` — and it is enforced
by **removal**, not by convention. `App\Http\Middleware\RejectBearerToken` is *prepended* to the `api`
middleware group, so any request carrying an `Authorization: Bearer` header throws
`AuthenticationException` → 401 before any guard runs (plan D10). `Laravel\Sanctum\HasApiTokens` is
deliberately **not** on `App\Models\User`; no migration creates `personal_access_tokens`;
`sanctum:prune-expired` stays commented out in `routes/console.php` as a **permanent** omission under
this ADR rather than a pending entry; and `abilities:`/`ability:` middleware appears on no route (plan
D11). `config/auth.php` keeps **both** `guards.web` and `guards.sanctum`, and that is one mechanism
rather than two: `config/sanctum.php` sets `guard => ['web']`, so `auth:sanctum` *delegates* to the
session guard, and deleting `guards.sanctum` would break every admin route that already existed.

**Reason:** two mechanisms on one endpoint means two authorization paths and one of them drifts. The
trait was going to be added *solely* so that `tokenCan()` existed for one Definition-of-done test, and
it is not needed for authentication — measured in vendor: `Guard::__invoke()` checks
`supportsTokens($user)` and returns the session user **unchanged** when the trait is absent, so cookie
auth works without it. Rejected alternatives: **create `personal_access_tokens` in the framework's
shape** so `findToken()` returns `null` → 401 — it makes the wrong credential merely *fail* instead of
stating the invariant, and it plants a table for a credential nothing mints; **leave the bearer branch
live** — it is a pre-existing unauthenticated **500** reachable by anyone (any `Authorization: Bearer`
on an `auth:sanctum` route reaches `PersonalAccessToken::findToken()` against a table no migration
creates → `42P01`), and this ADR fixes it as a side effect; **add the trait for the test** — that grants
the admin surface a second credential type in order to make one assertion writable.

**Trade-off, and it is a real loss:** the `laravel-sanctum-auth` Definition-of-done test —
*"`tokenCan()` returns `true` under a session while the same route still 403s"* — becomes
**unwritable**, because `tokenCan()` does not exist. That test proved abilities are not the gate. The
property is now enforced structurally by three negatives in
`services/core-api/tests/Security/SingleCredentialMechanismTest.php`: a bearer header on any `api/v1`
route is 401 and never 500; nothing calls `createToken()` on a *user*; and `User` does not use the
trait, asserted by reflection with a failure message citing this ADR so a future "just add the trait"
is a red build that explains itself. That is stronger, and it is also **not the same claim** — nobody
has demonstrated on this codebase that a `TransientToken`'s `can()` returns `true` for every string,
because no code path can construct one, so the gotcha survives as doctrine rather than as evidence.
A future mobile-PAT effort must deliberately re-add **four** things together, on its own surface: the
trait, the published `personal_access_tokens` migration, `Sanctum::authenticateAccessTokensUsing()` so
a revoked membership stops an already-minted token on its next request, and the prune schedule.
**Revisit when** a second client class needs a credential on `api/*` — the answer is a new surface with
its own route group and its own mechanism, never a second mechanism on this one.

### ADR-039: Emailed Capabilities Are Opaque Hashed Database Rows, Never Signed URLs, and Never a Path Segment

**Decision:** all three emailed flows — password reset, email verification, organization invitation —
carry a 32-random-byte token minted by one helper (`App\Support\Kb\OpaqueToken`), hex-encoded in the
emailed URL and stored **only as its digest**: `token_hash bytea` behind a unique index for the two new
tables, and the framework's bcrypt `token text` for `password_reset_tokens`, whose shape
`DatabaseTokenRepository` dictates. **No token is ever a path segment** (plan D2): invitation preview
and accept are POSTs carrying the token in the body, and the three SPA landing routes take it in the
query string. The field is named `token` in **every** FormRequest that accepts one (plan D13).

**Reason:** the decisive argument is mechanical rather than aesthetic. `URL::hasValidSignature()`
validates against `$request->url()` — the **API** URL — while the URL the recipient clicked is the
**SPA** URL, so a `temporarySignedRoute` cannot be validated after the SPA echoes its parameters back
without reconstructing and re-verifying the signed SPA URL by hand, where any difference in
query-parameter order or percent-encoding silently fails `hash_equals`: a bug that reproduces on some
mail clients and not others. Four further reasons, each independently sufficient: a signed URL is a
bearer capability that *additionally* carries the user id and `sha1(email)`; single use is expressible
on a row and not on a signature, so a leaked link in an archived mailbox keeps working until `expires`;
a row is revocable, which is what lets a resend kill the previous link; and password reset was already
an opaque hashed DB token, so matching it is the *smaller* design — one table shape, one expiry story,
one prune command, one test harness. Rejected: **a signed URL plus a consumed-token denylist**, which
is a table anyway, with two mechanisms instead of one. The `{token}` **path segment** was rejected
because a capability in a path lands in Traefik access logs, in `Referer` and in browser history; it
also deletes the `/invitations-<anything>` prefix hazard, since all three routes are exact paths.

**Trade-off:** one extra table and one prune-schedule entry, and the REST shape is awkward — reading a
preview is a POST. The token is still in a URL *in the email*, which is unavoidable, and it is in the
address bar for one page load, mitigated rather than removed by `Referrer-Policy: no-referrer` and a
`history.replaceState` that strips `?token=` after reading it. **Accepted residual, written down rather
than argued away:** a queued notification puts the **plaintext** token in the Valkey job body for the
life of the job. Not queueing re-opens the timing oracle ADR-040 closes, and re-minting inside the job
changes the token after the row was written — so the Security suite asserts the plaintext is absent
from `storage/logs`, from every log line and from the response body, and deliberately **not** from
Valkey. **Revisit when** a flow needs a capability that is not single-use; a signature is the right
shape for that and this ADR would be the wrong one to stretch.

### ADR-040: Account Non-Enumeration Is a Response-Shape Rule, and It Is Not the Deny-Oracle Property

**Decision:** on the admin auth surface, the N ways of failing one endpoint produce **one** response —
same status, same body bytes. Bad login credentials are **422 `validation` with the message on `email`
only** — never on `password`, never 401 (plan D7). Forgot-password collapses all three broker outcomes
(sent, unknown user, broker-throttled) into one 200. Invitation preview and register collapse **five**
invalid-token cases into one refusal: unknown, expired, accepted, revoked, and a pending invitation
into a *suspended organization* (plan D33, the fifth, which the design had not enumerated). The
property is asserted in `tests/Security/AccountEnumerationTest.php` by comparing failures **pairwise
against each other**, never against a missing route, plus a forbidden-fragment scan of the bodies.

**Reason:** a 401 *from* `/login` is a redirect loop in any SPA with a global 401 interceptor, and a
message on `password` says *"the address exists, the secret is wrong"*, which is the oracle itself.
The broker-throttle collapse is the subtle one: returning 429 on throttled and 200 on unknown **is** an
oracle, because probing twice proves the first probe created a token, which proves the account exists.
**This is a different property from the 403-admin / 404-public deny split** that
`tests/Security/DenyOracleTest.php` and `expect()->toDenyAsNotFound()` own, whose reference is always a
*live control* on the same surface, and conflating them produces the wrong test in both directions:
`toDenyAsNotFound()` on an admin auth route would **pass while asserting the wrong property**, and
turning an admin auth denial into a 404 "to be safe" would fail `DenyOracleTest`'s admin arm and make
its public arm vacuous. Rejected: **401 for bad credentials** (above); **429 on a broker-throttled
forgot-password** (above); **`unique:users,email` on register**, which turns `/register` into a live
account-existence oracle for anyone who can POST; and **distinguishing unknown-address from
wrong-password in the audit `reason`** (plan D27), because it costs a second `retrieveByCredentials()`
probe *outside* `SessionGuard::attempt()`'s 200 ms timebox, reintroducing in our own code the exact
differential the framework spends a timebox closing — and an investigator can ask the same question of
the same database later.

**Trade-off:** three costs, and the third is the one that will break. **(1)** 422 for a credential
failure is taxonomy-adjacent rather than taxonomy-clean; any future client that wants 401 must
special-case `/auth/login` in its interceptor. **(2)** The collapse costs the caller information they
may legitimately want: a recipient whose invitation genuinely expired is told only that it is no longer
valid, so support cannot distinguish that from a typo without reading the database. **(3)** It is a
**timing** property as much as a shape property, and the timing half rests on something outside these
endpoints — `PasswordBroker::sendResetLink()` sends the mail *inside* its 200 ms timebox, so a
synchronous SMTP send blows the floor on the exists-branch and re-opens the oracle. That is why every
auth notification is `ShouldQueue`: a **security** requirement, not a throughput one, and one that a
well-meaning "send it inline, it's just one email" would silently undo. **Revisit when** a flow needs a
distinguishable failure for support; the answer is an authenticated read surface, not a richer public
error body.

### ADR-041: `audit_logs` Is Append-Only and Monthly Range-Partitioned, and the Write-Failure Policy Is Per Operation

**Decision:** `audit_logs` is built in its final shape now — **range-partitioned monthly on
`created_at`**, primary key `(id, created_at)`, no outbound foreign key, `REVOKE UPDATE, DELETE`, and an
**allow-listed** `details` writer (plan D9). `subject_type` holds a **fully-qualified class name**, not
a table-ish name (plan D32). And the write-failure policy is **per operation**, declared in
`AuditLogger::OPERATIONS`: `ON_FAILURE_ABORT` where the audited change can still be rolled back, which
rethrows unwrapped so the render closure classifies the SQLSTATE, and `ON_FAILURE_LOG` where it cannot,
which records an ERROR and lets the response stand (plan D20). **Read the constant for the membership of
each policy** — do not restate it or its cardinality here or anywhere (ADR-036; plan D29 applied the same
rule to the test that pins the operation names, and `docs/22` § **H14** is what happens when a count is
restated in prose beside the constant it describes).

**Reason:** the per-operation split resolved a **genuine contradiction between two skills**.
`kb-observability-conventions` and its Definition of done say an audit write failure aborts the
operation; the brief said it must not turn a successful login into a 500. Both are right in their own
domain, and the domain is the *operation*: for a **state change** the row belongs in the same
transaction and must block the commit, so a role change that is not recorded did not happen; for a
**session act** the cookie has already been issued or destroyed, so aborting would return 500 to a
caller who *is* logged in **and** still lose the row — the worst of both. Putting the policy in one
constant rather than in a per-call-site argument means a caller cannot accidentally pick the lenient
policy for a role change. Rejected: **one policy for the whole logger** (either choice is wrong for half
the operations); **`'user'`-style `subject_type` values**, which the migration had documented while
every writer in the tree passed `::class` — two spellings of one fact in a free-text column means a
query for a subject finds half its rows, and the FQCN wins because it is what the code already
produces, because `::class` is compiler-checked where a literal is not, and because it matches
Laravel's morph convention; **converting to a partitioned table later**, which is a full rewrite.

**Trade-off, and the first one is that the headline guarantee is not enforced.** Measured on live
PostgreSQL 18.4: the ACL is correct — `pg_class.relacl` shows no UPDATE and no DELETE on the parent or
on any partition — but `rolsuper` is **true** for the only login role, so
`UPDATE audit_logs SET operation='tampered'` returned `UPDATE 1`. `TRUNCATE` is not revoked either,
because the Integration suite's `DatabaseTruncation` needs it. Append-only therefore rests on the ACL as
an *audit artifact*, on `AuditLog`'s PHP-level refusals, and on no code path issuing the statement; that
is written into the migration rather than papered over, and it is open as `docs/22` § **H3** (plan D21).
Second: partitioning buys a runway, and a runway runs out — **`kb:create-audit-partitions` must stay
scheduled**, because at 00:00 on the first of a month past the last partition every audit insert fails
with `23514` and every ABORT-policy action returns 500, total and instant, on a clock. Third:
`AuditLog::organizationId()` **throws** for platform-scope rows rather than returning `''` (plan D22),
because `OrgOwned::organizationId(): string` is non-nullable and an empty string would be a lie the
policy layer compares against a real org id — so platform-scope rows are not authorizable through any
org policy. Harmless while no read surface exists; it must be decided when the audit-log viewer lands.
**Revisit when** a non-superuser application role exists in `infrastructure/docker/` — that is the
change that converts the REVOKE from a record into a control, and it is also the moment `TRUNCATE`
should be revoked and the test that currently `markTestSkipped`s should assert.

### ADR-042: Registration Is Invitation-Gated, and the Session Wire Is Flat, Enveloped and Total

**Decision:** **no HTTP route creates an organization.** The first organization and its owner come from
`kb:bootstrap-organization`, which refuses to run when any organization exists, has no `--force`, and
**never accepts a password in any form** — it mints a password-reset link instead. Every other user
arrives through an invitation, and `RegisterRequest` validates **no `email`** (plan D3): the invitation
token is the sole authority for the address. The session wire is one flat resource, `SessionResource`
(plan D5) — every field in `required`, every nullable typed `["string","null"]` — over the role catalog
`owner | admin | knowledge_manager | analyst` (plan D6). Every success body is wrapped in `data` (plan
D23) and the web client unwraps once at the fetch boundary, never at a render site. A collection
response is `{"data":{"invitations":[…]}}` rather than `{"data":[…]}`, and `resend` is a single-action
controller (plan D26).

**Reason:** invite-only onboarding is what makes tenancy the *first* fact about a user rather than a
later one — there is no moment where an authenticated identity exists with no organization and no
inviter. The bootstrap command's four properties are each independently sufficient to keep it from being
a production backdoor: refusal when any org exists (an upsert would silently **change** an existing
owner, which is precisely the backdoor); no password anywhere, so the secret never reaches shell
history, an env file, or `docker compose config` output — which `make prod-config` runs before every
deploy and which renders every interpolated value in full; `--print-link` off by default with help text
saying it writes a credential to the terminal; and interactive by default while fully non-interactive
when every option is supplied. The wire decisions are mostly **forced by tooling**, and recording which
is which matters because the aesthetic reading of each differs from the real one: flat *and* total
because `tests/Contract/OpenApiDocumentTest.php` requires every resource component be **closed**
(`additionalProperties: false`) *and* **total** (declared ⊆ required), so there are no optional fields
to have; `data` because `ResponseShape::$properties` maps a response *key* to a schema class, making an
unwrapped body literally unpublishable by `kb:dump-openapi` — and both endpoints that predate all auth
work already wrapped, which is how a set of MSW fixtures written unwrapped against an assumption was
caught as a real cross-plane break; `{"invitations":[…]}` because `DumpOpenApiCommand` **cannot express
"an array of"** for a response key while the contract test requires `additionalProperties: false`, which
an array schema cannot carry; `resend` as `__invoke` because `arch()->preset()->laravel()` limits a
controller's public methods to the seven resource verbs plus `__construct`/`__invoke`/`middleware`.
Rejected: **HTTP self-service signup** (no invitation, no tenant, no bound on abuse); **409 for the
already-registered address and for a suspended organization**, because the render closure maps 409 to
`internal_dependency`, which a client reads as *"something on our side is unavailable, retry shortly"* —
false twice over for a suspended org, since nothing is unavailable and retrying never works while it is
suspended (plan D36, which removed the stale `409`s from the published error lists so the document stops
advertising a status that cannot occur).

**Trade-off:** the register path **does** disclose one thing — "an account already exists for this
address" — to a caller who has already proven possession of an invitation token bound to that exact
address, which an org admin deliberately sent there. That is a deliberate exception to ADR-040 and is
the only one. Second: because an invitation creates no membership row, `MembershipStatus::Invited`
loses its producer and is retained with a comment naming `organization_invitations` as the new owner —
a dead enum case, kept because removing it is a three-step CHECK-constraint migration for no functional
benefit (`docs/22` § **H11**). Third: there is no self-service path at all, so onboarding a new customer
is an operator action, and the command that does it is one whose safety rests entirely on the
refuse-if-any-exists check. **Revisit when** a second organization can be created through the API —
that refusal is the property which then has to move, and it is the only thing keeping the command out of
production reach.

### ADR-043: Two Org-Owned Models Deliberately Carry No `#[ScopedBy]`, Because `OrganizationScope` Fails Closed

**Decision:** `OrganizationInvitation` and `EmailVerificationToken` both `implements OrgOwned` and
**neither carries `#[ScopedBy(OrganizationScope::class)]`**, matching the pre-existing decision on
`OrganizationUser`. Isolation for these tables comes from the other direction: the guest read is by
`token_hash` — a 256-bit random behind a unique index — and every admin read goes through a repository
method that takes `organization_id` as a **required positional argument**.

**Reason:** `OrganizationScope::apply()` **fails closed**: with no bound `TenantContext` it appends
`whereRaw('1 = 0')`. The guest paths — preview, register, accept, verify — read these tables *before*
any organization is known, so with the scope attached every lookup would return nothing, **always**, and
that failure renders as a perfectly plausible *"this invitation is no longer valid."* Registration would
be silently and totally broken with green-looking code, no exception and no log line. Rejected
alternative, and the reason it loses is the important half: **keep `#[ScopedBy]` and call
`withoutGlobalScope(OrganizationScope::class)` on the guest read.** That is *worse* than not scoping,
because the tenancy gate greps the **plural** `withoutGlobalScopes(` and cannot see the singular form —
so the bypass would be invisible to CI while the model looked correctly scoped in review. Recorded as
`docs/22` § **H1**; widening the pattern is a one-line follow-up.

**Trade-off:** the reflection arch rule *"every org-owned model carries `#[ScopedBy]`"* cannot be
enabled unqualified, because three models now claim the exemption — enabling it needs an annotated
exception list carrying each model's reason, in the `// tenancy-exempt: <reason>` style the tenancy
skill's Definition of done already establishes. Until that list exists the rule stays off, and a
**new** org-owned model can ship unscoped with nothing complaining: that is the direction that hurts,
because the models here are exempt for a stated reason and the next one might be exempt by accident.
And the substitute protection is a convention rather than a mechanism — a repository method that grows
an `organization_id`-optional overload silently loses the whole guard, and no test would notice.
**Revisit when** H1 is closed (the rejected alternative becomes merely worse rather than invisible), or
when a **fourth** model wants the exemption — at which point the annotated exception list must be
written *before* that model lands, not after.

### ADR-044: In Development Nothing Self-Restarts; `unless-stopped` Is Production-Only

**Decision:** `compose.override.yaml` — the overlay production never loads — overrides **every** service
to `restart: "no"` through one `&dev-no-restart` anchor, and `compose.yaml` keeps `unless-stopped` for
production. `make down` runs `docker compose --profile '*' down`. Both directions were asserted by
`compose-invariants` checks **(9)** and **(9b)** — nothing in the dev render may self-restart, *and*
the base file must still name `unless-stopped` on five services by name. **Those checks were deleted
with `.github/` on 2026-08-17**, so the decision stands with no enforcement behind it; the two
measuring greps in `compose.override.yaml`'s footer are what a reviewer has instead.

**Reason:** `unless-stopped` exempts only containers the operator stopped **before** the daemon went
away. Anything still running — or still flapping — when the daemon stops comes back when it next starts,
and on Docker Desktop the daemon stops constantly: a Desktop restart, `wsl --shutdown`, an auto-update, a
host reboot. That is the reported symptom ("I stopped them and they came back by themselves"), and Docker
Desktop's own `AutoStart=False` does not touch it, because the revival is the daemon's restart manager
rather than Desktop's launcher. A second, separate cost was measured on Engine 29.6.2 with two containers
that exit 255 immediately: `unless-stopped` reached `RestartCount` 9 in 30 s while `no` stayed exited at
0 — but `docker stop` settled **both**, so a crash loop does *not* defeat the stop button and only the
daemon-restart mechanism explains the symptom. The `--profile '*'` half is its own measured finding: a
plain `down` leaves a **profiled** service's container running and `--remove-orphans` does not remove it
either (a profiled service is not an orphan), which is why `postgres-test` and `valkey-test` outlived
every shutdown; see `docs/22` § **I1**.

**Rejected:** a `profiles:` key in the base file. This is the same rule that moved mailpit's *definition*
into the dev overlay: a profile is one forgotten flag away from applying in production, whereas a service
— or a policy — that exists only in the file production never loads has nothing to remember in either
direction. Measured while making that earlier change and worth keeping: `profiles: []` in an overlay does
**not** clear a profile set in the base file, so the overlay could never have fixed it. Also rejected: documenting the policy in prose. Compose merges `restart:`
by **replacement**, so a service the overlay does not name silently keeps `unless-stopped` in dev
(measured with a two-service probe: `a -> no`, `b -> unless-stopped` from one render), there is no
file-wide restart setting to lean on, and the next service added to `compose.yaml` will be forgotten.
Hence a gate rather than a paragraph.

**Trade-off:** in development a container killed by a transient failure — a dependency restarting, an
OOM, a laptop resuming from sleep — now stays dead until the next `docker compose up -d`, which is
idempotent. That is the intended half: a crash you must notice is a crash you fix, and the alternative is
what `laravel-worker-long` did on this host, logging `RedisException` connection-refused for 98 minutes
after `valkey-core` was stopped. The overlay's service list is a maintenance burden by construction and
its only real defence is check (9). **Revisit when** Compose grows a file-scoped restart setting, at
which point the per-service list — and half of that check — can go.

### ADR-045: The Stop Signal Must Be One PID 1 Actually Handles, and It Is Set Per Service

**Decision:** `stop_signal: SIGTERM` on the four core-api services whose PID 1 is not php-fpm —
`laravel-api`, `laravel-api-stream`, `laravel-worker`, `laravel-worker-long`. It is **not** a `STOPSIGNAL`
in the Dockerfile. `laravel-scheduler` is exempt (`schedule:work` traps `INT TERM QUIT` through Laravel's
`$this->trap()` helper, so the inherited SIGQUIT is already graceful) and so is `laravel-migrate`
(`MigrateCommand` traps nothing, so no signal helps it). A `compose-invariants` check **(9d)** asserted
the rule with both exemptions named rather than counted; it was deleted with `.github/` on 2026-08-17.

**Reason:** the image inherits `STOPSIGNAL SIGQUIT` from its `php:*-fpm*` base, which is correct only when
PID 1 *is* php-fpm. Here PID 1 is `kb-serve` (bash, `trap shutdown TERM INT`) or `php artisan horizon`
(`ListensForSignals` traps `TERM USR1 USR2 CONT`, plus `INT`). **A signal PID 1 has no handler for is
discarded** — the kernel ignores default-disposition signals for PID 1, SIGKILL and SIGSTOP excepted — so
`docker stop` did nothing, Docker waited out the full `stop_grace_period` and SIGKILLed: **26 minutes and
exit 137** for `laravel-worker-long`. Measured with the image alone, two arms: a PID 1 trapping only TERM
survives SIGQUIT indefinitely, while one trapping SIGQUIT exits on it — so delivery was never the gap.
After the change the five php services stop in **15 s, every one exit 0**. The consequence worth stating
is what the grace periods were doing: every drain they are priced for — a worker finishing a job
mid-`UPDATE`, a stream finalizing the usage row the tenant is billed from — **never ran**, and the number
bought delay instead of safety.

**Rejected:** `STOPSIGNAL SIGTERM` in the Dockerfile. The same image serves php-fpm for `laravel-api`'s
fpm child, where SIGQUIT *is* the graceful stop and SIGTERM cuts a stream mid-answer, so a
per-image default would fix four services by breaking the one case the base image got right.

**Amended 2026-08-17 — the follow-up this ADR deferred has landed, and the deferral was the right call
made for a reason that has now expired.** `kb-serve`'s trap is `TERM INT QUIT`
(`services/core-api/Dockerfile`). It was recorded here as a follow-up rather than smuggled in because it
needs an image rebuild; the rebuild has since happened for other reasons, so the cost that justified
deferring it is gone. **Measured on the image, both directions:** with `trap shutdown TERM INT`, a bare
`docker run` + `docker stop -t 30` took **31 s and exited 137**; with `QUIT` added, **0 s, exit 0**. Note
what that measurement is *not* — it is not a second finding about compose. The compose path was already
correct, because `stop_signal: SIGTERM` is what this ADR decided. What the trap fixes is **every path that
does not read compose.yaml**: an operator debugging one container by hand, and any bare `docker run` of this
image directly. There the inherited `STOPSIGNAL SIGQUIT` is what Docker actually delivers. The two
mechanisms are now deliberately belt-and-braces and **both stay** — the per-service `stop_signal:` is not
made redundant by the trap, because a future core-api service whose PID 1 is neither `kb-serve` nor Horizon
would inherit SIGQUIT with no trap of its own, which is what check (9d) keeps enforcing.

**Trade-off:** the requirement now lives in Compose, invisible from the image, so a **new** core-api
service inherits SIGQUIT and nothing about the container says so — which is precisely what check (9d)
exists for, and why its exemptions are names rather than a count. `laravel-migrate` keeps a 300 s grace
that is now documented as a **deadline rather than a promise**: nothing there handles a signal, so a stop
during a migration still ends in SIGKILL mid-DDL. ~~**Revisit when** `kb-serve` gains a `QUIT` arm~~ —
**that happened on 2026-08-17; see the amendment above**, and the per-service settings are now the
belt-and-braces half rather than the only half. Still **revisit when** a core-api service runs php-fpm as
PID 1 (it would need the exemption, with its reason), or when one runs a PID 1 that traps neither signal —
the trap protects `kb-serve` and Horizon specifically, not the image in general.

### ADR-046: The `edge` Subnet Is Pinned Outside Docker's Dynamic Address Pool

**Decision:** `x-edge-subnet: &edge-subnet "${KB_EDGE_SUBNET:-10.207.0.0/16}"`. The other three networks
stay unpinned. This changes the default **value** only; the reasoning for *why* the range is pinned at
all — one anchor feeding the `edge` ipam block and `TRUSTED_PROXIES` on both routed Laravel services, so
the CIDR Laravel trusts an `X-Forwarded-For` from cannot drift by half an edit — is unchanged and lives in
`compose.yaml`'s `x-edge-subnet` block beside `config/trustedproxy.php`. No document may retype the CIDR
a second time.

**Reason:** the old default, `172.24.0.0/16`, sat **inside** the daemon's built-in address pools
(172.17.0.0/12 in /16 chunks, plus 192.168.0.0/16; this host configures none of its own). A pinned range
inside the pool can be handed to an **unpinned** network, and it was handed to one of ours: after a `down`
freed all four project networks, the next `up` failed with *"failed to create network knowledgebot_edge:
invalid pool request: Pool overlaps with other one on this address space"* because `knowledgebot_data`
was created first and took 172.24 — the allocator hands out the lowest free /16, other projects on this
host hold 172.17–172.23, and Compose does not create `edge` first. It reproduces on **every** down/up
cycle, and it is the worst kind of intermittent: a hard stop whose apparent fixes (retry,
`docker network prune`) work at random. `10.0.0.0/8` is in neither built-in pool, so no dynamically
allocated network — ours or another project's — can ever be given the range. Verified before the edit
(`docker network create --subnet 10.207.0.0/16` succeeds; `ip route get 10.207.0.1` resolves via the
default gateway) and after, across two full cycles.

**Rejected:** pinning the other three networks "for symmetry" — that puts three more ranges at risk to
fix what one line fixes, and the base file's existing instruction not to pin them stands for that reason.
Rejected too: unpinning `edge` and letting Docker choose, which is the defect the pin exists to close —
a trusted-proxy CIDR Docker may reassign stops matching, `$request->ip()` reverts to Traefik's address,
and all five per-IP limiters collapse into one global bucket with nothing red anywhere.

**Trade-off:** changing the pin **recreates the network once**, restarting the six containers on `edge`,
and `preflight.sh` §3b reports the live network's range against the declared one until that happens. The
residual risk is now the only one: a host route or VPN that really uses 10.207/16, which `KB_EDGE_SUBNET`
exists for. And the change had a documentation cost that is itself an ADR-036 episode — four files kept
naming the old CIDR afterwards, one of them a commented example that would have re-pinned the very range
the move escapes (`docs/22` § **I5**). **Revisit when** the daemon's `default-address-pools` are
configured on a deployment host, which changes which ranges are safe to pin.

---

ADR-047…052 come from the provider-connection and model-catalogue effort of 2026-08-19 — the pass that
completed the provider-connection resource (`index`/`show`/`update`/`destroy` beside the existing
`store`), added credential rotation and a per-connection model catalogue, and built the three admin
screens that drive them. They **supersede nothing**, and one of them — ADR-052 — closes at the relay
boundary a property ADR-029 had already decided, without changing ADR-029. As with ADR-038…046 the
narrative lives in [`docs/22`](22-spec-findings-and-decisions.md) § *The provider-lifecycle decisions —
ADR-047…052*, and the findings the effort turned up are § *Found while building the provider surface —
2026-08-19* (**J1–J7**); the entries below are the decisions and what each one costs.

**Two of the six are corrections to the instruction that asked for them, and both say so in place.**
An ADR written as if the right answer had been obvious from the start teaches nothing, and the two
here are the ones a reader will be tempted by in exactly the same way — the brief said *bump
`key_version`* (ADR-048), and the skill's own worked example refuses the rotation ADR-047 permits.

### ADR-047: Credential Rotation Is Its Own Re-Authenticating Endpoint, and It Is Permitted on a Revoked or Invalid Connection

**Decision:** rotation is `PUT …/provider-connections/{providerConnection}/credential` on a
single-action controller, and `PATCH …/{providerConnection}` accepts `label` and `status` and **no
credential field at all**. Rotation re-authenticates the actor — `current_password:web` as a rule on
`RotateProviderCredentialRequest`, so it runs *before* anything reads or writes the row — and carries
its own limiter (`throttle:credential-rotation`) beside the group's `throttle:admin`, keyed per actor
**and** per IP. The organization's status is checked (409 when it is not Active). **The connection's
own status deliberately is not:** a `revoked` or `invalid` connection may be rotated, and the rotation
returns it to `active`.

**Reason:** the separation from `update` is the security control rather than REST taste. One route
means one permission and one re-authentication policy covering both a relabel and a credential
replacement, and the weaker of each pair wins; splitting them also makes *"the edit endpoint may never
accept a credential"* checkable by reading one FormRequest instead of by reasoning about a branch.
**The rotate-a-dead-connection half is a deliberate divergence from `kb-security-baseline` §18.4's
worked example**, which spells check 5 as
`abort_unless($credential->status === CredentialStatus::Active, 409)`. That is wrong for this product:
`invalid` is the state a failed connection check leaves
behind and `revoked` is the state an operator sets when a key leaks, so replacing the key is the remedy
for *both*, and refusing would make delete-and-recreate the only escape from a bad key — losing the
connection id, its `provider_models` rows, and its embedding designation if it held one. That is a
re-index (ADR-031, ADR-049) as the price of a typo. The divergence is recorded, not resolved:
`docs/22` § **J7**. **Rejected:** folding rotation into the PATCH (above); a sixth method on the
resource controller — `arch()->preset()->laravel()` limits a controller's public methods to the seven
verbs plus `__construct`/`__invoke`/`middleware`, which is the preset working rather than an obstacle,
and is why `ResendInvitationController` exists (ADR-042); **`throttle:admin` alone**, whose
(organization, user) budget is over a hundred password guesses a minute from a legitimately signed-in
session — that makes the §18.3 check a formality, and the account axis is what gives it meaning; and
the skill's **freshness-window** shape (`requireReauthenticationWithin($actor, minutes: 15)`) — a
window is state about a session, a password check is evidence at the moment of the act, and only the
rule form runs early enough to make *"a failed password does not touch the row"* true by construction.

**Trade-off:** three, and the second is the one that will bite. **(1)** Because a FormRequest validates
before the controller authorizes, a member with the wrong role *and* the wrong password gets **422
rather than 403** — which leaks nothing, since the only password the rule can test is the caller's own,
but it is not the status a reader expects. **(2)** The limiter is tight on purpose and its account axis
is the **actor**, not the connection, so an operator rotating several connections at once — which is
precisely what a vendor-wide key leak requires — spends one budget for the whole set. Read the
`credential-rotation` limiter in `AppServiceProvider` for the live numbers rather than trusting a
figure here (ADR-036). **(3)** Check 6 assumes the actor *has* a password: an SSO identity or a machine
account has nothing to re-check, and this endpoint would need a second re-authentication mechanism.
**Revisit when** a non-password identity can hold `providers.manage` — the shape of check 6 has to be
decided again rather than adapted — or when the leak-storm case is reported, at which point the
limiter's per-actor budget is what moves, never the password check.

### ADR-048: `key_version` Names the KEK, So a Credential Rotation Bumps a Separate `credential_version`

**Status: `Accepted`. This is a correction to the brief that requested the endpoint**, which said to
bump `key_version` on rotation. It is recorded as the trap it is rather than as a preference.

**Decision:** `provider_connections.key_version` keeps its single meaning — the version of the
**key-encrypting key** that wrapped this row, written by `CredentialVault::seal()` from
`config('kb.kek_version')` and from nowhere else — and a credential rotation does not touch it. The
generation counter gets its own column: `credential_version integer NOT NULL DEFAULT 1` with a `>= 1`
CHECK (migration `2026_08_19_001100`), incremented inside the same transaction that replaces the
ciphertext, written into the `provider.connection.credential_rotated` audit row beside `key_version`,
and rendered by no resource.

**Reason:** incrementing `key_version` on a credential rotation writes a version **naming a KEK that
never wrapped that row.** `CredentialVault::open()` ignores the column today only because exactly one
KEK is configured; the moment a second exists, `open()` must select the KEK *by that number*, and every
credential rotated in the meantime becomes ciphertext that cannot be unwrapped. Nothing fails at the
moment of damage — the write succeeds, the response is a 200, and the fault surfaces at the next KEK
rotation, for every credential rotated since. That is latent data loss, not a naming quibble.
`kb-security-baseline` §18.2 says the same thing and its worked example passes
`keyVersion: $dek->kekVersion()` under the comment *"rotation = rewrap, no migration"* — so this is a
case where the **skill was right and the instruction was wrong**, which is worth stating because the
instruction is the more recent artifact and the natural tie-break goes the other way. **Rejected:**
overloading `key_version` with both meanings (above); **inferring the generation from `audit_logs`** —
that table is monthly-partitioned and prunable (ADR-041), so a question about a live row would depend
on a retention policy; **no counter at all** — *"which generation of this tenant's key was live on that
date"* is the first question a `provider_auth` incident asks, and `updated_at` answers it only until
the row is touched for anything else.

**Trade-off:** two integer columns spelled `*_version` now sit side by side on one table, which is the
exact confusion this ADR exists to prevent, and only the migration's docblock says which is which.
**Nothing enforces the split, and nothing can today:** an UPDATE that bumps `key_version` on rotation
would pass every test in the tree, because a single-KEK deployment cannot observe the difference — the
tests cannot see it either. **Revisit when** a second KEK is configured. That is simultaneously the
moment the distinction becomes observable, the moment `open()` must start reading `key_version`, and
the right moment to add the assertion that a rotation left it alone.

### ADR-049: Deleting a Provider Connection Is a Guarded Hard Delete, and the Embedding Designation Is Surfaced as a 409

**Decision:** `DELETE …/provider-connections/{providerConnection}` removes the connection row and its
`provider_models` rows in **one transaction**; the audit row is the only thing that survives. It is
refused with **409** while the connection is the organization's designated embedding credential, with a
sentence naming the remedy, and with 409 while the organization is not Active. The composite
`ON DELETE RESTRICT` on `organizations.embedding_connection_id` (ADR-031's migration) remains the
authority — the application check exists to produce a usable message, and a designation that lands
between the check and the DELETE is mapped from PostgreSQL's `23503` onto the *same* sentence rather
than a 500.

**Reason:** hard rather than soft, because `status` already carries `revoked` and that **is** the soft
delete — a second, structural one means two spellings of "gone" and every catalogue read has to filter
on both, which is the shape that eventually serves a revoked connection. Cascading the catalogue rows
is not a convenience: a `provider_models` row keyed to a connection that no longer exists is
unresolvable, and the FK would refuse the delete anyway. The load-bearing choice is **surfacing the
RESTRICT instead of working around it**: clearing the designation on the operator's behalf would return
the organization to resolve-by-rule under ADR-031, which may select a *different* `(provider, model)`
than the corpus was indexed under — a re-index, not a setting, and a decision an operator has to make
with their eyes open. **Rejected:** clearing the designation inside the delete (above); a **422** keyed
on a field, because there is no request body and the refusal is about the state of a *different* record
— unlike the designation conflict in `EloquentOrganizationRepository`, which *is* about the submitted
pair and is therefore correctly a 422; and a **204**, because every success body on this surface
carries `data` and an empty `#[ResponseShape]` publishes `"properties": []`, which is not a JSON Schema
object (ADR-042, plan D8).

**Trade-off:** a 409 renders as `internal_dependency`, whose class-mapped copy — *"something on our
side is unavailable, try again shortly"* — is false twice over, so the client has to render the
server's `message` verbatim. Telling this deliberate 409 from a genuine 500 needed a **message
sentinel** when this was written; **ADR-053 closed that** (`docs/22` § **J2**) and the client now reads
the envelope's `actionable` flag, so the recognition is structural and the trade-off is only that the
message is rendered at all. Second: the connection's history now exists only in `audit_logs` — label,
provider and masked key are in the deleted row's audit detail, and a pruned partition makes it
unreconstructable. **Revisit when** an audit-log read surface lands, which turns reconstructability
from theory into a question someone will ask.

### ADR-050: Pricing Is Three Exact Columns, and the Column's Ceiling Is Deliberately Above the Form's Bound

**Decision:** `provider_models` gains `input_price_per_million` and `output_price_per_million`
(`numeric(14, 6)`, nullable) and `price_currency` (`text`, constrained to `^[A-Z]{3}$`), with two more
CHECKs: a price may not exist without a currency — **one-directional**, a currency with no prices is
legal — and no price may be negative (migration `2026_08_19_001200`). `docs/11` §16.2 lists "pricing
metadata" and `docs/02` §8.4 bounds its use to *estimated reporting*; no migration had created a
column. The FormRequests bound a price well below what the column can hold, and **the gap is
deliberate**.

**Reason:** a price is compared, summed and multiplied, so it is a real column and not a `jsonb` key —
`'9' > '10'` is true in the text spelling, and `capability_flags` is `jsonb` on this same table only
because the *key set* there is the vendor's. `numeric` and never `double precision`: `0.15` is not
`0.15` in binary floating point, and an estimate summed over a month's usage drifts by an amount nobody
can reproduce to a tenant. The unit is **in the column name** because a bare `input_price` is the
column somebody later divides by 1,000 "because it is obviously per-thousand", and the result is wrong
by three orders of magnitude and still plausible. **The width gap is the generalizable half and is the
reason this is an ADR rather than a migration:** were the column's ceiling flush with the form's bound,
the boundary value would pass validation and then raise SQLSTATE `22003` from the driver, rendered as a
**500** — a bug report about the server for a value the form said was fine. A refusal has to happen
where there is a field to key it on. **Rejected:** `jsonb` (above); `char(3)` for the currency, which
accepts `'us'`, silently stores `'us '`, and makes every later comparison against `'USD'` fail against
a value that looks right in a console; a CHECK enumerating real ISO-4217 codes, which makes a new
currency a migration; and requiring the currency whenever the row exists — an operator records the
vendor's billing currency before they have looked the prices up, and refusing that makes the form
unfillable in the order a human fills it.

**Trade-off:** the platform now stores a number it does not verify and cannot refresh. A list price is
a fact about a vendor's public pricing page, typed in by hand, and it goes stale in silence — every
report built on it inherits that, which is why §8.4's *"estimated"* is doing real work. `NULL` means
"nobody recorded a price" and must never be read as zero, and **nothing enforces that**: a SUM over a
partially priced catalogue reports a plausible under-estimate. And the currency is per row, so one
organization can hold a catalogue in two currencies — the CHECK stops a price with no currency, not a
total across two of them. **Revisit when** anything *bills* from these columns rather than estimating:
an amount that reaches an invoice needs a source, an as-of date and an audit trail, and three nullable
columns provide none of the three.

### ADR-051: The Capability Vocabulary Stays Out of `packages/contracts`; the Control Plane Constrains Spelling, Not Membership

**Decision:** `supported.*` on the connection body and `capability_flags` on a catalogue row validate
as an **open shape** — `string|max:64` plus `regex:/^[a-z][a-z0-9_]*$/` — with **no `Rule::in`**, and
the published OpenAPI component types the field as `array<string>` with **no enum**. The authority is
`class Capability(StrEnum)` in `services/ai-service/app/providers/contract.py`; the matrix that decides
whether a claimed flag is *honoured* is `app/providers/capabilities.py`, which carries a source per
cell. The admin console holds **one** catalogue of flags for its own rendering, and
`apps/web/tests/unit/model-catalogue.test.ts` reads the enum out of the Python source and
**set-compares**, behind a positive control that asserts the file was found and the class parsed.

**Reason:** a closed copy in the shared package would assert a guarantee **no layer makes**. The data
plane adds a capability when a vendor ships one, so a shared enum would have the control plane rejecting
a flag the data plane already honours — a validation failure produced by nothing but a stale copy, on
the plane that has no opinion on the question. `packages/contracts` is also imported by the widget and
by mobile, neither of which has any use for a provider capability, and a second copy of a vocabulary is
the drift `contract-steward` exists to catch. **The pattern constrains spelling, not membership, and
that distinction is the point:** it makes `Embedding`, `embedding-flag` and a four-kilobyte string
impossible while leaving *what the set contains* to the plane that knows. The **positive control** is
what makes the set-compare a test rather than a decoration — a moved file or a renamed class otherwise
makes it pass vacuously, which this repository has already shipped twice (`docs/22` § **H2**, § **I6**).
**Rejected:** the shared enum (above); `Rule::in` against a PHP constant, which is the same drift one
plane closer; validating **nothing**, which turns an operator's typo into a data-plane refusal at the
next upload instead of a 422 on the form they are looking at; and **generating** the enum from
`contract.py` at build time — a cross-language codegen step, and a fourth artifact to keep current, for
a list whose membership question the control plane never asks.

**Trade-off:** an operator can save a flag that will never be honoured and get no feedback at save
time. The refusal is supposed to arrive later, from the data plane, by name, with the matrix cell
quoted — and **for a rerank flag it does not arrive at all**, because the only reachable caller of the
coherence check walks embedding candidates only. That is `docs/22` § **J1**, and it is owed work rather
than a consequence anyone chose. Second: the drift test reads another language's source with a regex,
which is exactly as fragile as it sounds; the positive control bounds the damage to a loud failure
rather than a silent pass, and it does not remove the fragility. **Revisit when** a second consumer
needs the vocabulary — at which point the right shape is the data plane *publishing* it at runtime, not
a third hand-maintained copy.

### ADR-052: The Internal Relay Carries the Whole Envelope, and a Relayed `retryable: false` Is `ORIGIN_SELF`

**Status: `Accepted`. Supersedes nothing.** It closes at the **relay boundary** a property ADR-029
already decided; ADR-029's decision, reasoning and constraints all stand unchanged.

**Decision:** `InternalAiClient::relay()` reads **four** fields, not two — `error_class`, `message`,
`retryable`, and the `errors` map that `validation` envelopes carry — and `KbException::relayed()` maps
a relayed `retryable: false` onto `ORIGIN_SELF` while anything else, **including a missing or
non-boolean field**, maps to `ORIGIN_DOWNSTREAM`. The HTTP status is still never consulted. The
`errors` map is relayed verbatim, with each message and each field path length-bounded before it enters
our own response body. The envelope field was already optional and typed, so carrying it is additive
and no published component changed shape.

**Reason:** ADR-029 split `internal_dependency` on an origin axis exactly so that a defect (`self`,
500, never retryable) is not rendered as a brownout (`downstream`, 503, retryable). The data plane's
`_handle_unexpected` raises with `origin=Origin.SELF` and therefore puts `retryable: false` on the wire
— and `origin` is a *rendering input*, never a wire field, which a Contract test pins — so that boolean
is **the only evidence of origin that crosses the seam**. Dropping it let `relayed()` default to
`ORIGIN_DOWNSTREAM`, let `bootstrap/app.php` recompute `retryable: true`, and let the admin console run
a full backoff ladder against a guaranteed failure. **That is finding O1 re-opened one hop later, on
the exact envelope O1 was about**, which is the transferable lesson: a decision about how two planes
agree on a field survives only where *every* hop that copies the field preserves it, and a relay that
reads a subset is such a hop. The `errors` half has the same shape — the data plane builds a real
per-field map on every validation envelope, dropping it turned a per-field refusal into an opaque
sentence, and it broke an invariant a client tests structurally: `apps/web` discriminates the ADR-031
resolver refusal on `validation` **with no map**, which is sound only while every *other* `validation`
keeps its map. **Rejected:** deriving the origin from the status — a status is a rendering of a class,
and re-deriving anything from it is the failure the carrier exists to prevent; treating a missing or
non-boolean `retryable` as `false`, which would let an absent key assert SELF origin and suppress a
legitimate retry, so `null` means *"the envelope did not say"*; and relaying `origin` itself as a wire
field, which would publish an internal rendering input on the public envelope.

**Trade-off:** `retryable` now carries two readings on one field — *may this be retried* for the client
and *which origin did the raiser assign* for the relay — and they coincide only on
`internal_dependency`. The mapping is inert on the other seventeen rows because
`ErrorTaxonomy::retryable()` overrides on that row alone, which is deliberate but is also a coupling a
future class could break by needing the axis explicitly. The relay also copies a **downstream-authored**
per-field map into our own response body, which makes the two length bounds load-bearing rather than
defensive. And the fix did not, at the time, reach the other half of the same problem: a deliberate 4xx
and a genuine 500 arrived at a client as the same `(error_class, retryable)` pair, so the console needed
a message sentinel. **ADR-053 closed that half** — `docs/22` § **J2** — by adding `actionable` to the
envelope, which this relay now carries as a **fifth** field for precisely the reason stated above; the
count in the Decision paragraph is the count as of this ADR, and ADR-053 is where it moved.
**Revisit when** a second internal endpoint is relayed: the relay is one private method today, and
*"every field the envelope defines crosses"* is a rule a second call site can break silently.

### ADR-053: The Envelope Says Whether Its `message` Is Addressed to a Person, and That Is Not a Status

**Status: `Accepted`. Closes `docs/22` § J2. Amends the envelope ADR-029 and ADR-052 describe.**

**Decision:** the error envelope carries a fifth field, **`actionable: boolean`** — *true* when
`message` was written for this condition and may be shown to an operator, *false* when it is a fixed
placeholder chosen to say nothing. Both planes compute it in the one place that chooses the message:
`bootstrap/app.php` derives it from the arms of its message `match` (false for `status >= 500`, false
for either `authorization` constant, false for a Laravel `ValidationException`, otherwise the
exception's own verdict), and `services/ai-service/app/main.py` reads it off `KbError.actionable`,
which `_handle_unexpected` sets to `False` and every other raise leaves `True`. It is **required** in
the `ErrorEnvelope` OpenAPI component and **optional** in `packages/contracts/src/envelope.ts`, the
same asymmetry `request_id` carries and for the same reason — that interface is the union of the HTTP
body and the SSE `error` frame, which does not carry the field. It **fails closed** in both
directions that can be wrong: `toKbError` reads `payload.actionable === true`, `KbError`'s constructor
parameter defaults to `false`, and `KbException::relayed()` defaults its argument to `false`.

**Reason:** the taxonomy has no 409 row, deliberately (ADR-029's arm in the render closure spells out
why), so an unclassified 4xx our own code raised keeps its status and renders as `internal_dependency`
with `retryable: false` — and an unhandled exception renders as `internal_dependency` with
`retryable: false` too. ADR-049's actionable *"clear the designation first, then delete"* and *"the
service could not complete this request"* were therefore **the same envelope** to a client, and
`apps/web` told them apart by comparing `message` against a client-side copy of the 5xx constant. That
worked, and it was a **deny-by-exclusion filter whose premise was a property of the whole server tree
rather than of the response in hand**: the first `abort(400, $detail)` reachable from those screens
would have broken it silently, and in the worse direction — a defect whose message happened to differ
would have read to an operator as advice. A client inferring an HTTP status from a string is exactly
the coupling `error_class` exists to remove, so the fix belongs on the envelope. **Rejected:**
carrying the HTTP **status** on the envelope, which is complete and publishes a field every client can
then branch on *instead of* `error_class` — the coupling the 18-class taxonomy exists to remove, and a
door that cannot be closed once opened; a nineteenth error class for "conflict", which the taxonomy is
closed against and which a live tripwire in the data plane's tests would fire on; and re-using
`validation` for the delete conflict, which would collide with the structural discriminator
`apps/web` already uses for the ADR-031 resolver refusal (`validation` **with no map**).

**Trade-off:** it is a fifth field on an envelope whose smallness was a feature, and it is one more
thing two planes must agree on — mitigated by the cross-plane Contract test, which reads
`app/main.py` as data and now pins the key set, the order and this value. The derivation is a
conjunction rather than a lookup, so it is prose-in-code that a sixth arm on the message `match` could
fall out of; it sits directly beside that `match` for that reason. The split it draws is by
**producer, not by class** — a FormRequest 422 is not actionable while a `KbException::validation` is
— which reads as an inconsistency until you see that the first one's payload is the `errors` map.
And the field invites exactly one misuse, which the OpenAPI description names in capitals: inferring a
status from it. **Revisit when** a client wants to distinguish *kinds* of deliberate 4xx (409 versus
410 versus 428) — a boolean cannot, and that is the point at which the status question genuinely
re-opens rather than being answered by a flag.

### ADR-054: A Capability Flag Is a Claim, and the Catalogue Write Path Stays Free of the Data Plane

**Status: `Accepted`. Closes `docs/22` § J1 by accepting the deferral rather than removing it.**

**Decision:** `provider_models.capability_flags` is a **claim about a model, not a verified fact**,
and Laravel writes it without consulting the data plane. `capabilities.assert_row_coherent` keeps its
single caller — `embedding_selection.ineligibility()` — so an incoherent **embedding** row is reported
by name in the readiness verdict's `rejected[]`, and an incoherent **rerank** row is reported nowhere.
The console says so at the point of entry: `model-form.tsx` renders a standing sentence on the rerank
task stating that whether the connection's provider can rerank at all is checked neither there nor on
save. The docblocks in both services that claimed a save-time refusal — five in
`services/ai-service`, plus `assert_org_can_embed`'s own, which describes a function nothing calls —
have been corrected to say what the code does.

**Reason:** the honest alternative is a coherence call on the write path, and it buys the rerank
family a refusal at the price of making a **metadata edit** synchronously dependent on `ai-api`. An
operator renaming a model, or correcting a price, would get a failure whenever the data plane is
briefly restarting — on an operation that has no dependency today and no reason to acquire one. The
asymmetry that remains is real and is the cost being accepted: for the embedding family the refusal
does arrive, by name, with the matrix cell quoted, because the designation screen asks the data plane
a question it has to ask anyway. For every other family it does not arrive, so the console's copy is
the whole of the feedback. **Rejected:** the synchronous check above; and mirroring the provider ×
task matrix into `apps/web` so the console could answer the third question itself, which would put a
**fourth copy** of a table that already lives in one place behind ADR-051's line — the control plane
constrains spelling, not membership, and a client-side copy would go stale in the silent direction
(claiming a vendor cannot do something it now can).

**Trade-off:** an operator can save `openrouter` + `["rerank"]`, see a 200, see no rejection anywhere,
and get no reranking — and the only thing standing between them and that is a sentence they may not
read. Reranking degrades to the fused order with no error, so the symptom is answer quality drifting
for as long as nobody looks, which is the failure mode the rerank gate's own module documents at
length. The console's client-side coherence check covers the two rules computable from the flags
themselves and cannot cover the third, so the screen is *partially* authoritative, which is a worse
thing to be than either fully or not at all. **Revisit when** a second family joins the readiness
question — the moment anything else makes the data plane answer a per-connection capability question
on a path an operator already waits for, the marginal cost of asking about rerank there is near zero
and this decision inverts.

### ADR-055: The Ordered Fallback Chain Is a Table, Because `jsonb` Cannot Carry a Foreign Key

**Status: `Accepted`. Supersedes nothing.**

**Decision:** the optional fallback model chain (`docs/02` §8.3, `docs/11` §16.3) is
`bot_fallback_models` — a real table with its own ULID primary key, a denormalized
`organization_id`, a zero-based `position`, and **two** composite foreign keys,
`(organization_id, bot_id) → bots (organization_id, id)` and
`(organization_id, provider_model_id) → provider_models (organization_id, id)`, both `RESTRICT`. Two
unique indexes hold it (one position per bot; one appearance per model per bot) plus the FK-child
index on `(organization_id, provider_model_id)` that the catalogue delete path probes. It is **not**
a `jsonb` array on `bots`. Migration `2026_08_19_001700`; the file's docblock carries the ranked
argument and this ADR carries the decision.

**Reason:** three reasons, ranked, and the first one settles it on its own. **(1) `jsonb` cannot
carry a foreign key.** Every other model reference in this schema is guarded by a composite key
against `(organization_id, id)` of its parent — `bots_model_same_org` for the primary model,
`provider_models_connection_same_org` one level down — precisely so that a row cannot name another
tenant's. `["01J…","01J…"]` naming a model row in another organization is a perfectly valid `jsonb`
value, the database has no opinion about it, and the failure that follows is **this tenant's
conversations answered on that tenant's credential**, billed to them, visible in their provider
dashboard, with every downstream layer agreeing because it was told whose credential answers. A
`jsonb` chain leaves the *primary* model guarded by the database and its *replacements* guarded by
whichever service last wrote them. **(2) It is joined on.** The catalogue delete path has to answer
*"is any bot still using this model"* before removing a `provider_models` row; against a table that
question is `ON DELETE RESTRICT` and costs nothing, and against `jsonb` it is a containment scan over
every bot in the organization that has to be remembered by whoever writes the delete. **(3) It is
edited one element at a time.** A snapshot is written whole; a chain is reordered, appended to and
pruned by an operator in a form, and read-modify-write of a `jsonb` array is a lost update the moment
two tabs are open.

**The counter-argument, on the record rather than dismissed:** the chain *is* part of the
configuration snapshot that crosses the internal seam, and `postgresql-patterns` admits `jsonb` for
exactly that shape — a provider/bot configuration snapshot written once and read whole. It does not
win, and the reason generalizes: **a snapshot is assembled at request time from the source of truth;
it is not the source of truth.** `retrieval_traces.filters` is `jsonb` for the same reason, and
nobody would propose storing `bots.provider_model_id` there.

**Rejected:** the `jsonb` array (above); a `belongsToMany` **pivot** keyed
`(bot_id, provider_model_id)` — `attach()` writes neither a ULID nor the denormalized
`organization_id`, so the pivot spelling produces rows this schema refuses while looking entirely
correct at the call site; and a **CHECK excluding the bot's primary model** from its own chain, which
would have to read `bots.provider_model_id` from another table (a CHECK may not) and whose trigger
spelling would put a piece of validation logic somewhere nobody looks for it.

**Trade-off:** reading a bot's configuration for the snapshot is no longer one row, so the chain is a
join on a path that had none. The denormalized `organization_id` is the price of the second composite
key — a third copy of a fact that the foreign keys then make impossible to get wrong, but that still
has to be *set*, and the idiom most likely to be reached for (`attach()`) does not set it. And the
primary model is still **not** excluded from its own chain by the database: a chain that re-lists the
bot's primary model is a legal row today, refused only by the write service, which is the next step's
work and does not exist yet. **Revisit when** configuration-snapshot assembly shows this join in the
chat path's p95. The answer then is a materialized snapshot *derived from* this table, never a
replacement for it — and that distinction is the whole ADR.

### ADR-056: `bots.view` Is Held by All Four Roles, Which **Extends** §6.4 and §6.5 Rather Than Reading Them

**Status: `Accepted`. Supersedes nothing. It is an extension of the specification, decided with the
repo owner, and it is labelled as one at all three sites that encode it** — `Permission::BotsView`,
`OrgRole::grants()`, and `tests/Unit/RolePermissionMatrixTest.php`, which states the matrix
independently so the two have to agree.

**Decision:** two permissions. `bots.manage` — every write, including publish, pause, archive,
delete, the origin allow-list, the starter questions, the retrieval configuration and the fallback
chain — goes to **Owner and Admin**, and that *is* the spec: §6.2 lists "Create and publish bots" as
an Owner capability and §6.3 lists "Manage bots" as an Organization Administrator one. (Two shipped
docblocks additionally say §6.4 *"excludes bot publish from the Knowledge Manager explicitly"*. **It
does not** — §6.4 is silent, which is the same silence those docblocks correctly describe one
paragraph later; the grant is right and one sentence of its justification is not. `docs/22` § **K6**.)
`bots.view` goes to **all four roles**. §6.4 and §6.5 never mention bots **in either direction**, so
the Knowledge Manager and Analyst grants are an addition to the specification and not a reading of
its silence.

**Reason:** two named upcoming surfaces, neither of which works without it. **Phase C6** has a
Knowledge Manager assign knowledge sources *to* bots; the assignment screen is a list of bots, so a
role that cannot read one cannot do the job §6.4 *does* give them. **Phase E** has an Analyst review
conversations *per bot*; a transcript is uninterpretable without the bot that produced it — the name,
the model and the answer mode are what make it readable. Neither grant widens what a member of the
organization may see: a bot's configuration carries no credential (`provider_connection_id` is a
reference and the key behind it never leaves the vault) and no end-user content.

**Rejected:** **reading the silence as a denial**, which leaves the Knowledge Manager holding a Phase
C6 job they cannot perform and invites the worse repair — gating a bot-list endpoint on a *sources*
permission, so that a screen's authorization no longer matches what the screen shows. A narrower
**`bots.list`** returning names and ids only: two permissions where the surfaces need one, and the
Analyst genuinely needs the model and the answer mode rather than the name. A separate
**`bots.publish`** case: it would be granted to exactly the same two roles as `bots.manage`, and a
permission nobody grants differently is a permission that fails silently in both directions — what
makes publishing stricter than a rename is the **publish guard** (no model, no assigned source,
`allow_general_answers` still false in RAG-first mode), which is check 5 of the six and has no
argument position in `OrgScopedPolicy::permit()`. And **deferring the grant to Phase C/E**, which
would put the policy, the matrix test and every role-enumerating dataset under edit by whoever is
building the screen, at the time they are building it.

**Trade-off, stated as the named cost:** this is a rule that lives in the repository and nowhere in
the specification, so a reviewer who checks §6.4 finds nothing supporting it — which is exactly why
it is written out three times and why the matrix test states the whole Analyst row independently.
Second, and this is the most surprising diff in that file: **the Analyst row is no longer all-false.**
"An analyst holds nothing" was a true shortcut and is now a false one, so a dataset built on it will
go green for the wrong reason. Third, the origin allow-list — a real security control — sits behind
`bots.manage` rather than behind something stricter, deliberately: the roles that would hold the
stricter permission are the same two, so splitting it would only move where the reviewer looks.
**Revisit when** §6.4 or §6.5 is amended upstream, or — the more likely trigger — when a role must
see *some* bots and not others. `bots.view` is organization-wide; the first agency-shaped request
("this member may see one client's bot") is a **scoping** change, not a permission change, and this
permission cannot express it.

**Amended 2026-08-19 — the grant as first implemented disclosed the operator-authored prompt to a
reporting-only role, and the repair narrows the PROJECTION rather than the grant.** Everything above
stands unchanged: both roles keep `bots.view`, and no permission moved.

**What was found.** The security read of the same batch that shipped the bot endpoints (finding
**L1**, `docs/22` § _The security read of the bots surface_) found `App\Http\Resources\BotResource` publishing `system_instruction` and
`answer_style_instruction` **unconditionally** — no `when()`, no Gate call, nothing conditional in
the file — while both read endpoints authorize `bots.view` and nothing more. `BotCollectionResource`
maps that resource per row, so an **Analyst** (the role this ADR extended the grant to; it holds
`bots.view` and *no other permission in the entire catalog*) could read every bot's full
operator-authored system prompt with one `GET /organizations/{org}/bots?per_page=100`. That was
**live, not latent**, from the moment the endpoints landed.

**Why it is more than a mis-set flag: the codebase already contradicted itself about this exact
string.** `App\Services\Audit\AuditLogger`'s bot allow-list **refuses** `system_instruction` from
`details`, on the stated ground that it is *"the exact string a prompt-injection review is about"*
and that the audit table is append-only, long-lived and exportable — a table read by the
organization's own administrators. It is not defensible to withhold a string from *that* table on
that reasoning and hand the same string, unredacted, to the narrowest role in the catalog over the
API. And the grant's justification above never covered it: it argues from the assignment screen
(*"the assignment screen is a list of bots"*) and from transcript review (*"the name, the model and
the answer mode are what make it readable"*), and defends the width with *"a bot's configuration
carries no credential … and no end-user content"* — true, and **silent about the prompt**. The
widest read permission in this catalog acquired its width from a justification that never mentioned
the field with the highest blast radius on the row.

**Decision.** `system_instruction` and `answer_style_instruction` become a **management-only
projection**: a caller holding `bots.manage` receives the stored value, every other caller receives
`null`. **Both keys stay present in every response** — dropping one would make the response *shape*
vary by caller, and `packages/contracts/src/resources/bots.ts` already types both as nullable, so a
null costs no contract change while an absent key would. The flag is a **required** constructor
argument on `BotResource` (a default would let a new call site inherit a decision it never made) and
is computed **once per request** in `BotController`: `Gate::allows('update', $bot)` on `show`, the
new `OrganizationPolicy::manageBots()` on `index`, and the literal `true` on `store`/`update`, where
the `Gate::authorize()` one line above has already proved `bots.manage`. That placement is
mechanical rather than stylistic — `OrgScopedPolicy::permit()` resolves membership per check and is
deliberately never memoized across organizations, so the obvious spelling
(`$request->user()->can('update', $bot)` inside `toArray()`) is one `organization_users` read **per
row** on a hundred-row page, and no correctness test can see the difference. `manageBots` authorizes
nothing and must never produce a 403; it carries `Permission::BotsManage` exactly as `createBot`
does, because this is one permission with two call-site spellings. `consent_text` is **not** in
scope: it is rendered to end users before their first message, so hiding it would be theatre.

**Rejected — and the first one is what a reader reaches for.** **Revoking `bots.view` from the
Knowledge Manager and the Analyst**, i.e. reverting this ADR. That re-reads the silence as a denial,
which the section above rejected for reasons the disclosure does not touch: Phase C6's assignment
screen and Phase E's per-bot transcript review still need the name, the model and the answer mode,
and *none of that is the prompt*. One mis-projected field is not evidence that the other
twenty-eight were wrong, and trading a real capability for a fix that a projection provides is how a
defensible grant gets deleted by the next incident review. **A separate `bots.view_instructions`
permission**: it would be granted to exactly the roles that already hold `bots.manage`, which is the
failure this ADR's own rejection of `bots.publish` names — a permission nobody grants differently
fails silently in both directions. **Dropping the keys rather than nulling them**, which is the
shape-varies-by-caller problem above. **A second, narrower resource for the reading roles**
(`BotSummaryResource`): two components in the generated client for one table, guaranteed to diverge
at the next column, and it answers the *list* while leaving `show` — which the same roles reach —
untouched. **Asking the Gate inside `toArray()`**, which is correct, is what every reviewer would
have written, and is a hundred membership reads per page; it is rejected here in writing so the next
person to "simplify" the flag away finds the reason first.

**Trade-off, stated as the named cost.** A client cannot distinguish *"this bot has no system
instruction"* from *"you may not see it"* — both are `null`. That is accepted rather than papered
over: the majority of bots genuinely have no instruction, so the ambiguity exists on the wire
regardless, and the alternative (a `*_visible` sibling flag) publishes the permission matrix to
every caller for no gain the console cannot get from the role it already knows. Second, the
permission is now consulted in **two** places for one field — the policy that authorizes the read
and the projection that shapes it — so a future permission change has two call sites to move;
`tests/Security/BotEndpointAccessTest.php` asserts the projection per role, on **both** reads, from
a four-role dataset, because a single-role fixture cannot fail that test, and
`tests/Feature/BotCrudTest.php` asserts the membership-read count does not scale with the page.
**Revisit when** a role must read the prompt without being able to write it — the
review-before-publish shape — at which point the flag stops being derived from `bots.manage` and
becomes its own permission, and this projection is the seam it plugs into.

### ADR-057: The Evidence Threshold Ships Nullable, With No Default, Stored Beside Its Scale

**Status: `Accepted`. Supersedes nothing; it is ADR-030's consequence reaching the control-plane
schema, and it is the same argument ADR-031 makes about `(provider, model)` being the vector space.**

**Decision:** `bots.evidence_threshold double precision` is **nullable with no column default**, and
`bots.evidence_threshold_scale text` sits beside it. Three CHECKs hold the pair:
`bots_evidence_threshold_paired` (`num_nonnulls(...) <> 1` — either alone is uninterpretable),
`bots_evidence_threshold_scale_check` (the vocabulary, generated at migration time from
`EvidenceThresholdScale::values()`), and `bots_evidence_threshold_range` ([0, 1] on a bounded scale,
unconstrained on a logit). `App\Enums\EvidenceThresholdScale` is the data plane's `RerankScale`
(`services/ai-service/app/providers/contract.py`) **minus `uncalibrated`**, with the same string
values for the three members it keeps.

**Reason:** `evidence.min_score = 0.30 on the sigmoid scale` was a property of `bge-reranker-v2-m3`
under `normalize=True`. ADR-030 replaced that one local model with a per-organization **provider**,
and the providers do not agree with it or with each other — an unbounded signed logit for one, a
bounded relevance score for another. The scale is therefore a property of the `(provider, model)`
pair, `CALIBRATIONS` in the data plane is **empty on purpose**, and `RerankCalibration` refuses
construction on an uncalibrated pair rather than defaulting. **A column default here would fail no
test, and that is precisely why it must not exist:** `0.30` is a valid float on every scale, so
applying it to a logit passes almost everything and applying a logit threshold to a bounded score
refuses almost everything. Nothing raises. Only the refusal rate moves, only in aggregate, and since
ADR-030 it moves for **one tenant** and not the rest — the first evidence would be a customer saying
the answers got worse. `uncalibrated` is absent from the enum by construction: it is not a scale, it
is the statement that no characterization exists, so a row reading
`evidence_threshold = 0.30, scale = uncalibrated` would be a stored contradiction — a number nobody
may compare anything to. **The enum and the CHECK must move together.** The constraint is generated
from `EvidenceThresholdScale::values()` *at migration time*, so an existing database keeps the
vocabulary it was migrated with: a fourth thresholdable member is an enum case **and** an `ALTER`, in
one migration, or the two drift with nothing to notice.

**Rejected:** the column default (above); defaulting the **scale** alone and leaving the number null,
which the pair constraint refuses and correctly — a scale with no number is as uninterpretable as a
number with no scale; storing a **normalized 0–1** value and converting at read time, which needs the
calibration that is exactly what does not exist, so the stored number would be fabricated; a
**NOT NULL column with a sentinel** (`-1`, `0`) meaning unset, which is the same failure one level
down because a sentinel is a valid float on the logit scale; and **deriving the scale at read time**
from the bot's current `(provider, model)` instead of storing it — the model can be changed without
re-deciding the threshold, and a number calibrated on one scale would then be read silently against
another. Storing the scale is what makes that mismatch detectable at all.

**Trade-off:** a bot ships with no threshold, so *"no configured floor"* is now a state the query
path has to handle explicitly rather than a number it can always read — this ADR moves that question
to the data plane rather than answering it, which is the point and is also a real hole in the
control-plane surface until the write endpoint decides what an operator is shown. Second,
`bots_evidence_threshold_range` catches only the bounded half: a nonsense logit of `1e6` is accepted,
correctly, because a logit has no bounds to check against — the portability problem is only *partly*
constrainable and the constraint should not be mistaken for the whole guard. Third, two columns
encode one concept, and while the database refuses a half-populated pair, nothing in the resource or
service layer does. **Revisit when** `CALIBRATIONS` gains its first non-empty entry. That is the
moment a default becomes derivable — and the right shape then is a default **resolved from the
`(provider, model)` pair**, not a value stored on the bot, which is a different decision from this
one and needs its own number.

### ADR-058: `tenantPair()` Is Activated in Half, and the Canary Lives in a Bot's Welcome Message Until Phase C

**Status: `Accepted`. Closes `docs/22` § H9 in part — the fixture no longer throws; the position the
canary was designed for is still Phase C's.**

**Decision:** `tenantPair()`'s **signature is unchanged** and its body no longer raises. It builds two
organizations, one bot in each, one admin in each, and a per-test canary planted in **Org B's bot
welcome message**. The `KnowledgeSource` half of the shipped design stays commented, carrying a
`TODO(phase-c)` that says to **move** the canary when `KnowledgeSourceFactory::indexed()` lands —
never to plant a second one. All **six** `TenantPair` properties are narrowed from `object` to
`Organization`, `Bot` and `User`.

**Reason, on the canary's position:** the design has always been that it lives in Org B's *indexed
source content*, so that a leak through retrieval, a citation title, a cached completion or an export
trips it. That needs the `knowledge_sources → source_items → source_versions → chunks` migrations,
`App\Models\KnowledgeSource`, and a real Qdrant container in the `test` profile — all Phase C. The
welcome message is the closest analogue available now and is not a token gesture: it is
**tenant-authored text that crosses the wire** on the bot list, the bot detail, the widget bootstrap
and the hosted-chat first-run screen, four of the surfaces this phase is about to build, so a leak
through any of them trips it today. **Reason, on the type narrowing** — which is wider than the two
properties the brief named: static analysis runs over `tests/` at level 8 and **rejects a property
read on `object`**, so a partial narrowing leaves the fixture unusable from the tests that consume
it. More importantly, `object` lets a test hand `$t->botB` to anything that wanted `$t->botA` — and
the mistake that matters most is exactly that one: a negative assertion run as the organization that
*planted* the canary passes while proving the opposite of what it claims, and it type-checks in
silence.

**Rejected:** leaving `tenantPair()` throwing until Phase C with every two-org test built inline —
that is where § H9 left it, and inline fixtures multiply, against a helper whose entire purpose is
that a leaky test is harder to write than a correct one. **Two canaries**, one in a bot field and one
later in source content: two assertions to keep in step and a test that can pass on the wrong one,
which is why the TODO says *move*. A **second helper** for the bot-only pair — the thing everyone
imports and nobody migrates off, and the same argument `pest-testing` makes against a
one-organization helper. And **widening the properties back to `object`** to satisfy the
commented-out `expect('App\Models')->toOnlyBeUsedIn([…])` rule in `tests/Arch/DoctrineTest.php`: that
is the wrong fix, it is flagged in the file as such, and the right one is the rule's allow-list
carrying `Tests\Support`.

**Trade-off:** the fixture is now **half a fixture that looks whole**. A test written against it
asserts isolation over a control-plane text field, while the surfaces the canary was designed to
police — retrieval, citations, exports — are not covered by it and will not be until Phase C; the
commented block is the only thing that says so, and a reader who sees a green isolation suite will
not go looking for it. Second, the three `App\Models` imports make `TenantPair.php` the first
violation of that arch rule on the day it is enabled. **Revisit when**
`KnowledgeSourceFactory::indexed()` lands against a real Qdrant container — that is the move. The
observable that says it is overdue is a Phase C isolation test building its own source fixture
inline: the § H9 shape, one layer up.

### ADR-059: A Paginated List Is an Object Envelope With a Shared `meta` Block That Echoes the Query the Server Applied

**Status: `Accepted`. Supersedes nothing; it completes the room
`ProviderConnectionCollectionResource` and `ProviderModelCollectionResource` left for it, and the
array did not move.**

**Decision:** the body is `{"data": {"<key>": [...], "meta": {...}}}`. `meta` is **one published
component** — `ListMetaResource` — carrying `page`, `per_page`, `total`, `total_pages`, `sort`, `dir`
and `filter`, referenced by every paginated list the API will publish. `App\Support\Http\ListQuery`
is the validated request primitive: **1-based** pages matching `LengthAwarePaginator`, a **required
positional** list of this endpoint's sortable columns closed with `Rule::in`, `SortDirection` closing
the direction, and `DEFAULT_PER_PAGE` / `MAX_PER_PAGE` / `MAX_FILTER_LENGTH` as named constants —
read them rather than restating them here (ADR-036). `PaginatedCollection` is a **trait**. There are
**no links**.

**Reason:** four, and two of them are mechanical rather than aesthetic. **The wrapper is an object
because the tooling cannot express an array:** `#[ResponseShape]` maps a response *key* to a resource
class, so it cannot say "an array of", and `tests/Contract/OpenApiDocumentTest.php` requires every
published component to carry `additionalProperties: false`, which an array-typed schema cannot.
**The applied query is echoed because the server's values and the client's request can legitimately
differ:** `ListQuery::fromValidated()` clamps `per_page` for callers that never ran a FormRequest and
applies the endpoint's default sort when the client named none, so a client that assumed its own
parameters were in force computes the wrong page count from the first clamped response and keeps
computing it. `filter` is echoed **normalized** — trimmed, and `null` rather than an empty string —
so a "showing results for X" chip matches the rows that came back. `total` **and** `total_pages` are
both published rather than derived, because `ceil(total / per_page)` uses a `per_page` the client may
not have. **The sortable-column list is an argument with no default and no wildcard**, because a
caller-chosen `sort` reaches an `ORDER BY`: an open set is a caller choosing the index at best and
injecting at worst, and making the argument required and positional means an endpoint cannot obtain a
working list query without stating its sortable columns out loud. **It is a trait because of how the
OpenAPI discovery works:** `OpenApiDocumentTest` globs `app_path('Http/Resources')/*.php`
non-recursively and asserts against `ProvidesOpenApiSchema` implementors, so an inherited
`openApiSchemas()` would report a subclass that forgot to name its own component as *the parent's*
name being missing. A trait keeps the declaration in the file whose `toArray()` it describes, which
is the property that whole test file rests on; `Concerns/` is one level down and is not scanned.

**Rejected:** a **bare top-level array** (above); Laravel's own `ResourceCollection` envelope with
generated `links` — three clients navigate by their own routing and reach the API through a proxy
whose external origin the server does not reliably know, so a generated absolute URL is either wrong
or an **internal hostname on the wire**; **cursor pagination**, which cannot produce the `total` the
admin tables display, for lists that are per-organization and small; **0-based pages**, which would
disagree with the paginator, with every `?page=` it generates and with `meta.page`, and would put an
off-by-one in every repository instead of in `offset()` alone; a shared abstract **`ListRequest`** —
`kb:dump-form-rules` ignores abstract classes while the test asserting every FormRequest has a dumped
document still counts them, so the base-class spelling breaks the contract gate; and
**pattern-constraining `filter`**, which is a free-text term a human types and whose character class
would refuse the strings customers actually search for — what makes it safe is that it never reaches
SQL *as* SQL (bound as a parameter, `LIKE` metacharacters escaped by the repository) and what bounds
it is `MAX_FILTER_LENGTH`.

**Trade-off:** `per_page` is bounded in two places that are deliberately **not flush** — the
validation rule produces a 422 for an HTTP caller, and `fromValidated()` clamps **silently** for a
service or job that never ran one. That is ADR-050's width-gap shape one layer up, and it is also the
thing that will confuse the first non-HTTP caller, who will ask for a thousand rows, receive the
maximum, and be told nothing. Second, `total` is a `COUNT(*)` on every page request; fine at these
cardinalities and not fine at conversation scale. Third, the envelope now nests twice
(`data.bots`), so every client destructures one level deeper than it does for a single resource, and
no existing single-resource response looks like it. **Revisit when** the first list whose `total` is
expensive — conversations or messages — reaches this envelope. `total_pages` and a cursor are
mutually exclusive answers, and the right move is a **second** envelope for keyset lists rather than
a `total` that stalls or lies; the observable is the count appearing in that endpoint's p95.

---

ADR-060 and ADR-061 come from the **Phase B audits** — the two read-only reads over the whole bots
console effort. `git log --oneline b976735..HEAD` is the phase (read the range, not a number: the
count moved between the brief that commissioned this record and the record being written, and it
moves again on the next commit); `docs/22` § _The Phase B audits — M1–M7_ is the findings log and the
coverage. **Neither audit returned a Blocking issue**, and both ADRs below are the durable half of a
single finding — the data-loss path, `docs/22` § **M3** — split at the seam it broke on: the server
saying what it withheld (ADR-060) and the client refusing to seed what was withheld (ADR-061).

**ADR-060 is the third narrow supersession in this register**, after ADR-033 and ADR-037. It
overturns **one named rejection** inside ADR-056's 2026-08-19 amendment. Nothing else in ADR-056
moves: `bots.view` is still held by all four roles, the two instruction fields are still a
management-only projection, and both keys are still present in every response.

### ADR-060: A Response States Its Own Projection; a Client Never Re-Derives One From a Role

**Status: `Accepted`. Supersedes the `*_visible` rejection in ADR-056's amendment, and only that
claim.** ADR-056's decision, its grant and its projection all stand as written.

**Decision:** where a response body varies by caller, **the body says how it varied**.
`App\Http\Resources\BotResource` publishes `instructions_visible`, a boolean set from the same
`$withInstructions` flag that decides the projection, declared one line below the two fields it
describes. It is a **required** constructor argument (a default would let a new call site inherit a
decision it never made) and is computed **once per request** in `BotController`, exactly where
ADR-056's amendment already computes the projection. Clients read that flag; **no client re-derives
the projection from a permission, a role, or a session**.

**Reason — ADR-056's amendment rejected this flag, and both halves of the stated reason turned out
to be wrong.** The rejection read: a `*_visible` sibling flag *"publishes the permission matrix to
every caller for no gain the console cannot get from the role it already knows."*

**(1) It publishes no matrix.** The flag carries one bit about the **caller's own** grant on the
**row in hand** — a fact that caller can already establish by attempting the write. It says nothing
about any other caller, any other role, or any other row. What it actually resolves is an ambiguity
that ADR-056's amendment *itself* named as its accepted cost: a client could not distinguish *"this
bot has no system instruction"* from *"you may not see it"*, because both were `null`. That
ambiguity was already on the wire; the flag splits it and adds nothing to it.

**(2) "The role it already knows" answers a question about the session; the projection is resolved
per record, per request.** The two are allowed to disagree — a role promoted mid-session, a cached
detail row, any refetch skew — and when they did, the console's hand-written third spelling of the
server's grant map seeded a **withheld `null`** into a form control. `sometimes|nullable|string`
accepted it, and **saving a rename wrote `null` over both operator-authored prompts and returned
200.** That is the finding, in full at `docs/22` § **M3**; it was live on the shipped console and it
is the reason a documentation-level preference became a data-integrity rule.

**The generalization, which is the part worth carrying:** a client that infers the *shape* of a body
from a permission it believes it holds is deriving a per-record fact from a per-session one. The
server knows the answer for free — it just applied it — and every spelling on the client is a copy
that can be stale by one round trip.

**Rejected:**

- **Keep the derivation and fix the console's copy of the grant map.** Repairs the instance and
  leaves the class: the grant map was already a *third* spelling, so this is a fourth, and the next
  panel copies whichever one it finds. It also cannot be made correct — no client-side copy of a
  role can answer a question the server resolved against a row.
- **Drop the withheld keys rather than nulling them**, so their absence is the signal. That is the
  shape-varies-by-caller problem ADR-056's amendment rejected on grounds that have **not** moved:
  `packages/contracts/src/resources/bots.ts` types both as nullable, so a null costs no contract
  change while an absent key does, and a per-caller key set is the thing `strictObject` and the
  resource-drift suite exist to refuse.
- **A sentinel string** (`"«withheld»"`). It is a legal value of a free-text column, so it is
  indistinguishable from a prompt an operator typed, and the first bot whose instruction quotes it
  is a support ticket nobody can reproduce.
- **A conditional write guard alone** — an ETag or a `retrieval_configuration_version`-style
  pre-image check that refuses the destructive PATCH server-side. That is the right **backstop** and
  it is not this decision: it turns silent data loss into a 409 after the operator has already typed
  the change, and it cannot tell the *panel* whether to render a control, which is the question that
  has to be answered before the request exists.
- **A per-field map** (`{"system_instruction": false, "answer_style_instruction": false}`). One flag
  covers both fields because **one permission** does; a map invites a per-field permission model
  nothing implements, and ADR-056's rejection of a separate `bots.view_instructions` still stands.

**Trade-off, as the named cost.** The flag is now a required member of **two** contracts — the PHP
resource's constructor and the client's form-source type — so a new call site cannot inherit the
decision, but neither can it be written without stating one; that friction is deliberate and it will
read as boilerplate to the next person who adds a bot-shaped response. Second, the wire now carries a
key whose only consumer is our own console, so a third-party client generated from the OpenAPI
document sees a field it will never use. Third, and this is the one that can actually bite:
**nothing structurally binds `instructions_visible` to the two nulls it describes** — they are three
independent expressions in one `toArray()`, and a future edit can move one without the others.
`tests/Security/BotEndpointAccessTest.php` asserts the flag and the projection **together, per role,
on both reads**, from a four-role dataset; that test is the only thing holding them, and a
single-role fixture could not fail it.

**Revisit when** a **second** field on any resource acquires a per-caller projection. One boolean per
field does not scale, and the right shape at that point is a single `withheld: [...]` list on the
envelope rather than N sibling booleans — a different decision needing its own number, into which
`instructions_visible` becomes the first entry. The observable that says it is due is a second
`*_visible` key appearing anywhere in `app/Http/Resources`.

### ADR-061: A Withheld Field Is OMITTED From Client Form State, Never Seeded as `null`

**Status: `Accepted`. Supersedes nothing; it is ADR-060's client half, and neither is sufficient
alone.**

**Decision:** the shared panel-defaults builder in `apps/web` **omits the key** for any field the
server reported as withheld, rather than seeding it `null`. `instructions_visible: false` means the
two instruction keys are **absent** from the form's `defaultValues`, and the flag is a **required**
member of the form-source type so that a call site cannot inherit the decision. It is done **once, at
the source**, so no panel has to remember it. Omission alone is not sufficient, and the panel carries
a second line: the card body renders **conditionally** — either the two controls, or a sentence
stating that the fields were not sent and are not empty.

**Reason:** `sometimes` leaves an absent key alone; a **present `null` clears the column**. That
asymmetry is the entire decision, and it is invisible at the call site — an omitted key and a `null`
key look equally harmless in a defaults object, and only one of them is a destructive write. The
second line exists because **React Hook Form submits a registered input's DOM value whether or not
`defaultValues` named it**, and the app's clearable-text helper maps `""` to `null` — so a
rendered-but-unseeded textarea walks straight back into the path omission just closed. Rendering
*nothing* is also the only honest option available: for a caller without the grant, a bot with a
4,000-character prompt and a bot with none are byte-identical on the wire, so any control at all
would be asserting something the server declined to say.

**Rejected:**

- **A disabled textarea.** It is still registered, so RHF still submits its DOM value — `disabled` is
  an affordance, not a guard. Worse, it renders an empty box, which a viewer reads as *"this bot has
  no system prompt"*: the precise statement the projection refuses to make.
- **Filtering the withheld keys out of the request body at submit time.** Correct, and one layer too
  late. It is a fourth place to remember, it lives in the file most likely to be copied for the next
  panel, and it is invisible from the defaults builder that caused the problem — so the same bug
  ships again the first time somebody writes a panel without reading the submit handler.
- **Seeding `undefined` instead of omitting the key.** Indistinguishable at the type level while
  `Object.keys()`, every spread and every serializer disagree. The type has to say the key **may be
  absent**, or a call site reads a value that is not there.
- **Making the server reject a `null` on those two fields outright.** It converts silent data loss
  into a 422, which is strictly better, and it belongs on the server's list rather than this one —
  but it also forbids the legitimate *"clear this prompt"* write that the nullable column exists for,
  so it is a narrowing of the API to compensate for a client defect.
- **Refusing to render the panel at all without `bots.manage`.** Already true, structurally, since
  commit `c9634d5`: without the permission the panel branches to a component that mounts no form, no
  resolver and no defaults. It does **not** cover the case this ADR is about — a caller who *does*
  hold `bots.manage` but whose row was fetched while the projection said otherwise.

**Trade-off:** an operator without the grant is shown a sentence where a control would be, so the
screen tells them a field exists and declines to say whether it is set; that is accepted, because the
alternative is a guess. Second, form state and resource state now differ in **key set** and not only
in value, so anything that diffs the two must compare keys — the phase's window tests assert on
**keys and never on values** for exactly this reason, since a body carrying `system_instruction:
null` is byte-identical to the destructive request and a value assertion would pass against the bug.
Third, the rule is enforced by one builder and by review; nothing prevents a panel from constructing
its own `defaultValues`.

**Revisit when** a form field's value can be legitimately absent for a reason that is **not** a
permission — a sparse read, a projection by cost rather than by grant. Omission would then mean two
things and the form could not tell them apart. The observable is the first `?fields=` or
sparse-fieldset parameter on any read endpoint in `services/core-api/routes`.

---

ADR-062…065 come from **Phase C, step C1** — the step that lands the
`knowledge_sources → source_items → source_versions → document_elements / chunks` cascade as Laravel
migrations, and with it closes `docs/22` § **G2** (finding #79) on the removal condition that ruling
itself named. All four exist for one reason, worth stating once instead of four times: **two in-repo
sources disagreed about the schema and the DDL had to pick one.** In every case the binding source is
[`.claude/skills/postgresql-patterns/SKILL.md`](../.claude/skills/postgresql-patterns/SKILL.md)'s
runnable DDL together with the Python that already executes against those column names, and the
losing side is the prose docblock in
`services/core-api/database/factories/KnowledgeSourceFactory.php` — a scaffold whose column list has
never been executed by anything, because `definition()` throws.

**The docblock is not sloppy, and that is what makes these decisions worth recording.** It is a
faithful transcription of `docs/11` §16.4, which is verbatim specification and may not be edited. So
three of the four ADRs below are the *specification* losing to an implementation contract that was
written later and knows more, and each says which spec line it deviates from. **ADR-065 is the one
that goes the other way**: it overrules the skill, says so in its own status line, and leaves that
skill owing a correction which is not this register's to make.

### ADR-062: The Active-Version Pointer Is `source_items.current_version_id`, and the Schema Has No Second One

**Status: `Accepted`. Supersedes nothing. It resolves a disagreement between `docs/11` §16.4
(verbatim spec) and `.claude/skills/kb-source-lifecycle/SKILL.md` in the _skill's_ favour, and records
the deviation here rather than by rewriting the extract.**

**Decision:** `source_items.current_version_id` is the only column in the schema that makes a version
live. `knowledge_sources` gets **no** `active_version_id` and no `current_version_id`. Every source
has at least one `source_items` row — a single-file upload is a one-item source, not a special case —
and the enforcement is the partial unique index on `source_versions` from `postgresql-patterns`:
`CREATE UNIQUE INDEX … ON source_versions (source_item_id) WHERE activated_at IS NOT NULL AND
retired_at IS NULL`. The activation itself is Laravel's, on the §17.5 ingestion status callback.

**Reason.** `kb-source-lifecycle` states the failure directly: *"code that reads
`source.current_version_id` for uploads but `item.current_version_id` for crawls will diverge the
first time someone adds a second file."* Two pointers are not two representations of one fact — they
are two facts, and **nothing in the schema can force them to agree.** There is no foreign key that
says "this source's pointer must name a version of one of this source's items", so divergence is not
a constraint violation, it is a source that is live by one read and dormant by the other. That is a
lifecycle bug with no failure mode: the source list renders from one pointer, the retrieval filter
resolves the active version set from the other, and the observable symptom is *a document that
answers questions after it was replaced*, which reads as a caching problem for as long as anyone is
willing to look. The second reason is ownership: `services/ai-service/app/db/writes.py:50` names this
as the one column the data plane must **never** assign — *"Two writers on the one column that decides
which version is live turns a lifecycle bug into a constraint violation inside a Celery task, retried
forever."* That rule is stated about **one** column, and a sibling pointer is a second column the same
rule would have to be restated for, in a service whose whole discipline is that it does not know the
rule exists.

**Rejected.**

- **A sibling `knowledge_sources.active_version_id` for the single-file upload case**, which is how
  `docs/11` §16.4 (*"Current version ID"* on `knowledge_sources` **and** on `source_items`) and the
  factory docblock both read. It is the natural shape for the common case and it is exactly the
  divergence above. The upload path is also where it would look safest, because a one-item source
  makes the two pointers trivially equal — until the day it has two items, when nothing raises.
- **Only `knowledge_sources.active_version_id`, dropping the item-level pointer.** A crawl is many
  independently versioned items; one pointer per source cannot express "page 14 re-versioned, the
  other 399 did not", so a single changed page would re-publish the site or nothing at all.
- **A boolean `source_versions.is_active`.** Refused upstream by `kb-source-lifecycle` NN2 and
  restated here because it is the shape people reach for: two concurrent publishes both read "no
  active version", both set their own, and there is no single statement that flips them together.
  The pointer plus the partial unique index has one.
- **A generated column or a view on `knowledge_sources` that resolves the item pointer.** It removes
  the divergence but keeps the *reading habit* that causes it, so the first place someone needs it in
  a `WHERE` clause it becomes a real column again, and this ADR is re-litigated by accident.

**Trade-off.** Every read that wants "is this source live" pays a join through `source_items`, and
that is the majority of source-list reads — the admin table, the bot's assigned-source list, the
public source panel. The API resource must also present a shape the spec describes and the schema does
not, so *something* computes the source-level rollup; it is a resource concern by decision, and
nothing prevents a future migration from materializing it back onto the table and reintroducing the
divergence with a cache-shaped justification. **Revisit when** a read path needs the pointer and
demonstrably cannot afford the join — the observable is `current_version_id` appearing in the `select`
list of a *source*-level query, or a source list issuing one join per row at a page size the admin
console actually uses. The right answer at that point is a materialized rollup that is **written by
one statement together with the pointer flip**, never a second column that a different code path
maintains.

### ADR-063: A Version Row Carries Three Configuration Versions Including `ocr_cfg_version`, and Two Timestamps Rather Than One `published_at`

**Status: `Accepted`. Supersedes nothing. Deviates from `docs/11` §16.4, which lists parser and
chunker configuration versions and _no_ OCR one, and from §13.3's idempotency key, which omits it
too — that gap is `docs/22` § _Spec defects_ item 1, open since the first research pass, and this ADR
is the schema half of closing it.**

**Decision:** `source_versions` carries `parser_cfg_version`, `ocr_cfg_version`,
`chunker_cfg_version` and `embedding_model_version`, all `text NOT NULL`, **spelled exactly as the
Python keyword arguments are**; and two nullable timestamps, `activated_at` and `retired_at`, with
`CHECK (retired_at IS NULL OR activated_at IS NOT NULL)`. There is no `published_at`.

**Reason, on the OCR column.** `app/ingestion/identity.py:66-77` makes `ocr_cfg_version` a member of
`INGEST_KEY_PARTS`, and the key is what decides whether a source version already exists — `UNIQUE
(source_item_id, ingest_key)` is the dedup. A schema that omits the column has nowhere to put a
non-empty value, and both ways out are bad. Drop the part and **an OCR retune is a silent no-op**: the
resubmission dedupes against the completed run, the admin sees "already processed", and the new
settings never reach a document — which is why the function raises `VALIDATION` on an empty component
with a message naming this exact failure, so the second way out is that every ingest raises. The
column is four bytes of ceremony against a change that is invisible at the moment it fails and is
discovered as *"OCR quality never improved"* weeks later.

**Reason, on the spelling.** The factory reads `parser_config_version` / `chunking_config_version`;
the Python passes `parser_cfg_version` / `chunker_cfg_version`. A column spelled differently from the
argument means a mapping table at every construction site, and a mapping table is precisely where a
key component goes missing without raising — the failure `INGEST_KEY_PARTS`' two-directional
membership check exists to make impossible *inside* the module and cannot see outside it.

**Reason, on the two timestamps — this is the load-bearing half.** The pointer constraint is
`CREATE UNIQUE INDEX … WHERE activated_at IS NOT NULL AND retired_at IS NULL`. A single `published_at`
can say *this version was published*; it cannot say *and is still the live one*, so the predicate has
no second term and the index cannot be written. **"At most one live version per item" then stops
being provable by the database** and becomes a property of ordering discipline inside
`publish_version()` — under at-least-once Celery delivery, the one guarantee ordering discipline
cannot provide. The two timestamps are not a naming preference; they are the two terms of the only
constraint that makes atomic publication (Non-negotiable 5) enforceable rather than intended.
`docs/11` §16.4 agrees here, listing *"Activated time"* and *"Retired time"*; the factory docblock is
alone on `published_at`.

**Rejected.**

- **The factory's `parser_config_version` / `chunking_config_version` / `published_at`**, all three,
  for the reasons above.
- **`published_at` plus a boolean `is_active`.** Reintroduces ADR-062's rejected boolean and inherits
  its race.
- **`published_at` plus `superseded_at`.** Functionally identical — two terms, so the index works —
  and rejected only because it renames the two timestamps that `postgresql-patterns`' runnable DDL,
  the partial index, the orphan-sweep index (`WHERE activated_at IS NULL`) and the retire-after-
  activate CHECK all already use. A rename that buys nothing costs four call sites and one review
  where somebody has to work out whether the two schemes mean the same thing.
- **A single `cfg jsonb` blob holding all four versions.** No per-key `NOT NULL`, so the empty
  component the ingest key refuses becomes representable again; and the key needs an exact string, so
  every read is a `->>` with a cast and a null-coalesce that quietly supplies the empty value.
- **Making `ocr_cfg_version` nullable for born-digital documents that never ran OCR.** This is the
  tempting one, because a PDF with a text layer genuinely did not OCR. It is wrong for the same
  reason the empty string is: the column versions the *configuration in force*, not the *execution*,
  and a document that did not OCR today is one parser change away from OCRing tomorrow — under a null
  the key does not move and the reprocess dedupes away.

**Trade-off.** Four opaque `text NOT NULL` columns on a table that grows with every reprocess, and one
of them reads as a lie until you know the distinction above: a born-digital page carries an
`ocr_cfg_version` describing OCR that never ran. The CHECK is a third constraint to keep in step with
the two indexes. And the schema now carries four version strings whose *format* nothing validates —
they are opaque to PostgreSQL by design, so a caller writing a bare vendor model id into
`embedding_model_version` instead of `EmbeddingModelIdentity.version` (ADR-035) produces a perfectly
valid row. **Revisit when** a fifth configuration version joins `INGEST_KEY_PARTS` — a normalizer
version, a table-serializer version. At five the structured-blob argument gets real, and the right
shape is then a `jsonb` with a **generated, indexed** key column so the ingest key is still one exact
string. The observable is the second migration that adds a `*_cfg_version` column, and the tripwire
is `INGEST_KEY_PARTS` itself: it is the authority on membership and order, and a component added
there with no migration behind it is this ADR's revisit condition arriving.

### ADR-064: One 15-Value Status Vocabulary, Shared by `knowledge_sources`, `source_items` and `source_versions`

**Status: `Accepted`. Supersedes nothing. Deviates from the factory docblock's seven-value
source-level list, which is _not a subset_ of the vocabulary the code uses.**

**Decision:** all three status columns are `text NOT NULL` with a `CHECK` constraint over the same
vocabulary, spelled exactly as `SourceState` in `services/ai-service/app/ingestion/states.py` — the
enum that asserts its own membership at import. Read the enum rather than a list here (ADR-036);
`grep -n '^assert len(SourceState)' services/ai-service/app/ingestion/states.py` is the cardinality
and `postgresql-patterns`' runnable DDL carries the CHECK text. Not a PostgreSQL `enum` type.

**This is `docs/22` § _Spec defects_ item 4 being implemented, not re-opened.** That entry already
ruled *"processing states are version-level and roll up for display; `Draft`/`Archived` are
source-level only"* against §16.4's three status columns. It settled the semantics and left the
storage open; this ADR answers the storage question the migrations had to answer, and answers it the
way the ruling points — **the rollup is computed, never a second column in a second vocabulary.** One
consequence follows directly and is accepted: a single shared CHECK admits, at every level, values
that level never uses — `archived` on a version row, `chunking` on a source row — because narrowing
per level was rejected below.

**Reason.** The factory's seven values — `pending | processing | ready | ready_with_warnings |
failed | disabled | deleting` — look like a sensible source-level rollup of the version-level
machine, and the trap is that **two of them exist nowhere in `SourceState`**. `pending` and
`processing` are not narrower spellings of `queued` and of the six working states; they are new
states, so adopting that list would have the schema admit two values no Python constant can produce,
no transition table names, and no `DATA_PLANE_OBSERVED` membership check would ever report. The first
row the ingestion status callback writes then either fails the CHECK or passes through a translation
layer that somebody has to own.

The second reason is why the translation layer is the worse of those two. `source_status` is one of
the four mandatory Qdrant payload filter terms (`app/retrieval/tenancy.py`), and the retrievable set
is `RETRIEVABLE = {ready, ready_with_warnings}` — a **closed** set matched positively. Under ADR-010
that payload value is a projection of a PostgreSQL column, so two vocabularies make the projection a
*translation*, and a translation with an unknown input has to choose a default. Defaulting to a
non-retrievable value hides live documents; defaulting to a retrievable one serves content from a
version that is not active, which is Non-negotiable 5 broken by a mapping table. Neither shows up as
an error. One vocabulary means the projection is a copy, and a copy has no default.

**Rejected.**

- **The seven-value rollup on `knowledge_sources` with fifteen on `source_versions`.** The rollup
  framing is the appealing part and it is a **display** concern: "this source is busy" is a sentence
  about a set of items, computed per request. Giving it a column in the same schema as the
  authoritative machine, under the same name, is one migration away from being read as authoritative —
  and the read that does it will be a `WHERE status = 'ready'` written by someone who has only ever
  seen the source-level table.
- **A PostgreSQL `enum` type.** `postgresql-patterns` Gotcha: `ALTER TYPE … ADD VALUE` cannot be
  rolled back, values cannot be dropped or renamed, and a lifecycle change becomes an irreversible
  deploy step. `DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE` is reversible and keeps
  the values greppable.
- **An integer state code with a lookup table.** Smaller, and unreadable in `psql`, in a log line and
  in the Qdrant payload — where the value is matched as a keyword and the wire already carries the
  string.
- **A narrower vocabulary for `source_items.status`**, on the grounds that an item cannot be
  `archived`. True today and unenforced by anything: it is a third vocabulary to keep in step for the
  benefit of refusing a value nothing writes.

**Trade-off.** A `knowledge_sources.status` that can legally hold `chunking` is momentarily
nonsensical — a *source* does not chunk, a version of one of its items does — and that oddity is
accepted as the price of one vocabulary. Adding a state is now three `ALTER TABLE`s under
`ACCESS EXCLUSIVE` rather than one, mitigated by `NOT VALID` + `VALIDATE` per the migration table in
`postgresql-patterns`. **And the sharpest cost is that the two definitions are joined by nothing:**
`states.py` asserts its own membership at import, the CHECK text asserts its own, and **no test
compares them.** A state added to the enum and to two of the three constraints is a row that fails to
save in one table and saves in the other, discovered at the callback. That contract test — read the
enum, read the constraint text out of `information_schema`, compare as sets — is **owed work**, named
here rather than implied, and it belongs to `test-engineer` across both runtimes. **Revisit when**
`len(SourceState)` changes; that is the moment the missing test is either written or missed.

### ADR-065: `content_hash` Is `char(64) COLLATE "C"` Hex Everywhere, Which Overrules `postgresql-patterns`

**Status: `Accepted`. Supersedes no ADR. It contradicts an accepted skill:
`.claude/skills/postgresql-patterns/SKILL.md` line 52 specifies `content_hash bytea NOT NULL — 32 raw
bytes, not 64 hex chars`. That line is now wrong and is owed a correction from the skill's owner; this
register does not edit skills, so the obligation is recorded in `docs/22` § P1 rather than discharged
here.**

**Decision:** every `content_hash` column in the cascade is `char(64) COLLATE "C" NOT NULL` holding a
lowercase hex sha256 — one spelling on `source_items`, on `source_versions` and on `chunks` alike —
with `CHECK (content_hash ~ '^[0-9a-f]{64}$')` in the migration.

**Reason, in the order the evidence was found.**

1. **Python emits hex and nothing in this tree emits bytes.** `app/ingestion/chunking/chunker.py:963`
   is `hashlib.sha256(text.encode("utf-8")).hexdigest()`, and that value is a member of the chunk
   metadata schema whose completeness is asserted at import.
2. **The ingest key composes `content_hash` as a *string*.** `app/ingestion/identity.py` takes
   `content_hash: str`, joins it with `|` into the hashed tuple, and guards each component with
   `if INGEST_KEY_SEPARATOR in value` — a `str` operation that raises `TypeError` against `bytes`. So
   `bytea` puts a `bin2hex` at every construction site, and **the failure mode of forgetting one is
   not a crash**: `bin2hex` applied to a value that is already hex returns a different, valid,
   128-character string, which hashes to a perfectly well-formed key for an identity that does not
   exist. The row never dedupes against its own completed run, and nothing raises.
3. **This repository has already measured what `bytea` costs here — twice.** `docs/22` § **J3**
   records two `BinaryCast` defects, both silent and both on `bytea` columns: a PDO stream **drained
   by the first read**, so the second access returned `''` and was reported as *"ciphertext is
   truncated"* — a message that sends the reader to key management, which is the wrong place
   entirely — and PostgreSQL's `\x` hex heuristic applied to **already-decoded** bytes, which a
   random 12-byte IV triggers about one time in 65,536 and which decodes silently to different,
   shorter bytes. Those columns hold sealed credentials, where `bytea` is not optional and the cost
   is paid deliberately. Paying it again on a value that has a canonical text form everywhere else is
   buying a demonstrated defect class for 32 bytes a row.
4. **The skill already stores a sha256 as text one line below the line this ADR overrules.**
   `ingest_key char(64) COLLATE "C"` sits four lines under `content_hash bytea`. Two sha256 digests in
   one `CREATE TABLE`, spelled two ways, is what makes the `bytea` line read as an oversight — though
   whether it is one is the owner's call, not this file's.

There is a fifth consideration that is a consequence rather than a reason: the Qdrant payload carries
`content_hash` as a string, and `app/rag/evidence.py:348` dedups on `isinstance(content_hash, str)`,
**tolerating a non-match as *"cannot be proven identical"***. A non-string reaching that payload
therefore disables exact-duplicate dedup with no error — the symptom is context budget spent twice on
one passage, which no assertion in the suite can see.

**Rejected.**

- **`bytea`, as the skill specifies.** Its argument is real and is not being waved away: 32 bytes per
  row on `chunks`, the table the same skill says reaches hundreds of millions of rows, plus the extra
  width in every b-tree that includes the column. Overruled because all four reasons above are
  *measured in this repository* while the storage argument is a projection, and because the direction
  of regret is asymmetric — see the trade-off.
- **Splitting by writer: `bytea` where only Laravel writes (`source_items`, `source_versions`) and
  hex on `chunks`.** This is the hardest one to refuse. It honours both sources exactly, the byte
  saving is almost entirely on `chunks` anyway, and both conversion sites would sit in PHP where the
  cast layer already exists. It is rejected because it puts **two spellings of one hash in one
  schema**, and the query that trips over it is the one written under pressure: a deletion or
  rebuild audit joining `source_versions.content_hash = chunks.content_hash` is a type error at best,
  and once somebody adds the cast that makes it run, a comparison that is silently always false.
- **`char(64)` at the database's default collation.** Same storage, `strcoll` per comparison, and the
  index is invalidated by a libc or ICU upgrade — `postgresql-patterns`' own ULID table makes this
  argument and it transfers unchanged.
- **`bytea` plus a generated hex column.** Pays both storage costs and turns "which one is
  authoritative" into a per-query decision, which is the question this ADR exists to close.

**Trade-off, and it is a real one.** We accept roughly double the per-hash storage on the largest
tables in the schema, and we accept `char(n)`'s blank padding, which means a wrong-length value is
silently padded rather than refused — the `CHECK` is the compensating control and it is mandatory, not
decorative, because without it `char(64)` is *less* self-enforcing than `bytea` was. The reversal is
also expensive: `ALTER COLUMN TYPE` from `char(64)` to `bytea` is not binary-coercible, so it is a
full rewrite plus reindex under `ACCESS EXCLUSIVE` on `chunks`. That asymmetry is stated rather than
hidden — this decision is cheap now and dear to undo, which is exactly why the storage argument
deserved to be written out in full above instead of summarized.

**Owed work, recorded and deliberately not done here.** `.claude/skills/postgresql-patterns/SKILL.md`
line 52 now states a column type this schema does not use, inside the runnable DDL that is the
authority for everything else on that table — so a reader who trusts the file, as they should, will
write `bytea` into the next migration and the next model cast. **The correction is owed by that
skill's owner; `docs-adr-writer` does not edit the skill tree, and an accepted skill being wrong is
reported rather than rewritten.** Until it lands, `postgresql-patterns` and this ADR disagree in
writing, and this ADR is the one the migrations follow. The item is `docs/22` § **P1**, and it is open.

**Revisit when** the storage difference becomes operationally visible — the observable is `chunks`
appearing in a capacity or backup-window conversation with its on-disk size quoted, or the
`content_hash` index alone exceeding what fits comfortably in `shared_buffers`. The re-decision at
that point is not "switch to `bytea`" but "switch **and** move the two `str`-composition sites in
`identity.py` and the payload projection with it", and this ADR's job is to make sure whoever does it
knows that is three changes rather than one.

### ADR-066: `original/` Is Source-Scoped and Sits *Beside* `versions/`, Not Inside It

**Status: `Accepted`. Supersedes no ADR. It contradicts an accepted skill:
`.claude/skills/seaweedfs-s3/SKILL.md` non-negotiable 1 (line 15) and its layout diagram (lines
28–37) fix every key as
`org/{org_id}/sources/{source_id}/versions/{source_version_id}/` with `original/` and `derived/` as
tails *inside* it, and line 37 justifies that with "the phase-2 sweep and its verification are the
same prefix string". Those lines are now wrong about `original/` and remain right about `derived/`.
`.claude/skills/kb-tenancy-isolation/SKILL.md` line 97 restates the same prefix and inherits the
same correction. This register does not edit skills; the obligation was recorded in `docs/22` § Q11
and **discharged on 2026-08-25** — all three skills now describe this layout, and a third site the
finding had not listed (`kb-security-baseline/references/file-upload-safety.md`) was found and
corrected in the same pass.
Owed since commit `ec58a19` (2026-08-20), which shipped the departure and said an ADR was owed.**

**Decision:** the irreplaceable bytes of a source live at

```
org/{org_id}/sources/{source_id}/original/{content_hash}        ← uploads, no suffix
org/{org_id}/sources/{source_id}/original/{content_hash}.txt    ← pasted text
org/{org_id}/sources/{source_id}/versions/{source_version_id}/derived/…
```

`original/` is a sibling of `versions/`, scoped to the **source**; `derived/` is unchanged and stays
scoped to the **version**. On the Laravel side every one of these strings is built by
`App\Support\Kb\ObjectKey` and by nothing else. **The data-plane twin was written on 2026-08-25**
— `app/storage/objects.py`, mirroring `ObjectKey` method for method — and
`tests/contract/test_object_key_cross_language.py` runs the PHP class in a container and compares the
strings character for character, in both the accept and the refuse direction. That test is the
enforcement this ADR needs and a docstring cannot give it: two transcriptions of one layout, each
agreeing with its own documentation and disagreeing on bytes, is a failure neither runtime's own suite
can see.

**This paragraph used to say the twin did not exist, and warned that whoever wrote it must write it
against this ADR rather than the skill's diagram. That warning was already too late** — the Python
side had been composing keys since Phase C, without a module, and it had the layout half wrong: it
built only `originalUpload()`'s spelling, so every pasted-text source resolved to a key nothing had
written and failed as `storage` with a message saying its bytes were missing. They were not missing.
`docs/22` § **S2**.

**Reason: the fixed layout cannot be honoured by the code that holds the bytes, and that is
structural rather than a preference.** `2026_08_20_002000_create_source_versions_table.php` makes
`ingest_key`, `parser_cfg_version`, `ocr_cfg_version`, `chunker_cfg_version` and
`embedding_model_version` all `NOT NULL`, with CHECKs (`^[0-9a-f]{64}$` on the key, `btrim(…) <> ''`
on each configuration version) that refuse a placeholder. **Every one of those values is produced by
the data plane, during and after parsing.** So Laravel structurally cannot mint a `source_versions`
row at intake: there is no version id at the moment the bytes arrive, and there cannot be one. A key
that names a version therefore cannot be built by the request that has the content. This is not a
sequencing problem that better code would solve — it is ADR-063's schema being applied to the moment
before the schema has anything to say.

**Rejected.**

- **Mint the version row at intake and keep the specified key.** Blocked by the five columns above.
  The only way through is to relax them, which is the sub-option below.
- **Relax the CHECKs so intake can write a placeholder version row.** This is the one that looks
  cheapest and is the most expensive. `ingest_key` is the dedup — `UNIQUE (source_item_id,
  ingest_key)` is what makes a resubmission recognise its own completed run — and a placeholder key
  is a perfectly well-formed 64-character string that hashes to an identity nothing else will ever
  compute. The row never dedupes against the real run, the real run inserts a second row, and
  nothing raises. It is exactly ADR-065's `bin2hex`-on-hex failure shape, one table over.
- **Keep the shipped key, `org/{org}/sources/text/{sha256}.txt`.** Rejected on **two independent
  defects**, both found by writing `ObjectKey` rather than by review. (a) **Nothing could ever
  delete it.** The phase-2 purge sweeps the prefixes it is given, under a docstring that forbids
  widening one to make a sweep succeed, and verification enumerates under those same prefixes — so a
  key outside them is not merely missed, it is **certified clean while it survives**. That is
  Non-negotiable 6 failing in the one direction that produces a signed proof of a deletion that did
  not happen. (b) **It deduped across sources with no reference count.** `{content_hash}.txt`
  directly under `sources/` is one object for every source in the organization whose bytes are
  identical, so deleting either of two identical pastes took the other's body.
- **Keep cross-source dedupe and add a reference count.** The correct refcount for a body referenced
  by an unbounded number of sources is a table nobody has asked for, on the delete path, where a
  wrong count is another tenant's content. A duplicate object costs bytes; a shared object costs
  someone else's document.
- **Write the original under a synthetic pending prefix and *move* it once the version exists.** An
  S3 move is a copy plus a delete, performed on the one object in this system that cannot be
  recomputed from anything else, with a window in which the copy is the only copy. It also puts two
  prefixes on the sweep instead of one and makes a crashed ingest leave bytes in a location whose
  disposition nobody recorded.
- **A separate `uploads/` segment beside `original/`, so pasted and uploaded bodies never share a
  prefix.** Rejected because it is a second string to keep in step with
  `_purge_objects(…, include_original=…)` on the other side of the seam, for a distinction that
  cannot arise: a source is either pasted or uploaded, never both.

**Consequences, including the ones that hurt.**

1. **`purge_retired_version` must never pass `include_original`, unconditionally and with no
   caller-supplied flag.** One object now serves *every* version of the source, so a call that was
   harmless under the specified layout — where each version owned its own copy — would destroy the
   bytes the *successor* version was built from, and fail silently until the next reprocess found
   nothing to read. This is the single most important line in this ADR and it is a rule with no
   mechanism behind it: `include_original` derives from `original_disposition`, and nothing checks
   that it does.
2. **`seaweedfs-s3` line 37's justification splits in two.** "The sweep and its verification are the
   same prefix string" was already conditional — it held because `original/` sat inside the prefix
   being asserted. It is now true of `derived/` (one prefix per version) and false of `original/`
   (one prefix per source, swept once). The deletion seam therefore carries **two** prefix strings
   where the skill promised one, and the compensating property is stated below.
3. **Verification keeps the distinction that is its whole value, and it does not come from the
   filesystem.** Retained and missed are told apart by the **disposition recorded before the
   sweep**, never by what enumeration finds. An object under `original/` when the disposition was
   *remove* is a failed purge; an **empty** `original/` when the disposition was *retain* is the
   opposite finding — a retention obligation destroyed while the admin view is about to report an
   object that is gone. Only one of those two is visible if the code infers intent from what it
   sees.
4. **Dedupe is now scoped to one source by construction**, which is defect (b) above gone rather
   than compensated for. The cost is real: two sources holding the same PDF hold two objects.
5. **The purge already had the ids it needs.** `_purge_objects(org_id, source_id, version_ids, *,
   include_original)` receives both ids and already treated `original/` as a separate disposition,
   so the change needed no new argument and no widened prefix — which is why this ADR is a
   correction to two docstrings and a skill rather than to a signature.

**Trade-off.** The repository now disagrees in writing with an accepted skill about a key format,
and a key format is exactly the kind of rule a reader looks up rather than derives. Until
`seaweedfs-s3` moves, someone consulting it — correctly — will write a version-scoped `original/`
key, and the failure that produces is the certified-clean survivor from rejected option (b): an
object outside every swept prefix. `ObjectKey`'s class docblock and `deletion/tasks.py:186-241` are
the compensating controls, and both are prose.

**Revisit when** a *derived* artifact needs to outlive the version that produced it, or an
*original* genuinely differs per version. The observable for the first is `derived/snapshot/`, which
`seaweedfs-s3:137` already classifies as irreplaceable and tier-1-offsite while its own phase-2
sweep drops `derived/` unconditionally (`docs/22` § Q12 — a contradiction this ADR did not create
and does not fix). The observable for the second is a source type whose bytes are re-fetched per
version rather than re-parsed per version; recrawl is the candidate, and if a crawl ever stores a
per-version body under `original/`, the source-scoped prefix is wrong and this decision is due
again.

### ADR-067: Ingestion Is Organization-Scoped; `X-KB-Bot-Id` Is Absent, and Bot Access Stays a Query-Time Filter

**Status: `Accepted`. Supersedes no ADR. It contradicts an accepted skill:
`.claude/skills/kb-internal-api-contracts/SKILL.md` line 64 lists **ingestion** among the
`X-KB-Bot-Id` bot-scoped operations, and names only `provider.test` and health as the exceptions.
That row is wrong for ingestion. The obligation is `docs/22` § Q13; this register does not edit
skills.**

**Decision:** `X-KB-Bot-Id` is **absent** on `ingestion.submit` and on `embedding.readiness`. Bot
access to indexed content is enforced where it already is — as a **query-time** Qdrant payload
filter (Non-negotiable 2, `kb-tenancy-isolation`) — and never as an index-time scope.

**Reason.** A knowledge source is owned by the **organization** and assigned to **zero or many**
bots; `bot_source_assignments` is the many-to-many that Phase C's C6 step landed. There is
therefore no single bot id to send, and at the moment that matters most there is no bot id at all:
the first upload of a source happens *before* any assignment exists, because the console's
assignment panel lives on the source-detail screen the upload creates. The data plane already agrees
— `app/api/deps.py:132` types `bot_id` as `str | None` and `:456-458` names the case in a comment:
*"absent for an organization-scoped operation — embedding readiness, a source upload before
assignment"*. So the two live artifacts on this seam are consistent with each other and the
published table is the outlier.

**This is a wire decision and not an editorial one, which is why it is an ADR.** The `X-KB-*`
headers are inside the canonical string, and the verifier recomputes the covered set from **all
`X-KB-*` headers actually present** rather than from a caller-supplied list. Adding or removing one
therefore breaks the signature by construction — so "the table is wrong" cannot be fixed on one side
and cannot be fixed by a patch release of a document. Both signers change together or every
submission is a 401.

**The failure the table would have caused is total and one-directional.** A FastAPI ingestion router
written from line 64 would call `required("x-kb-bot-id")`, and every ingestion submission from every
tenant would be `validation` → **422** before a byte was parsed, with Laravel correct in every log —
the same shape as `docs/22` § Q2's deadline defect, discovered at the same seam and for the same
reason: the two sides were built from different documents.

**Rejected.**

- **Send the id of one assigned bot.** There is no primary assignment — the grant carries a priority
  and an enabled flag, not a rank among peers — so "one" means "whichever the query returned first".
  It also puts a bot inside a *signed tenant scope* that scopes nothing, which is worse than an
  absent header: a reviewer reading the canonical string would reasonably conclude the far side
  filters on it.
- **Refuse ingestion until the source has at least one assignment.** This inverts the product model
  (upload, then assign) and would make the first upload into a brand-new organization impossible.
  It also cannot work for crawl, where the source exists before anyone has decided which bots
  should see it.
- **Send a sentinel — `-`, `all`, or the organization id.** The header is signed, so a sentinel is a
  value both sides must special-case forever. `deps.py` already collapses empty to `None` and states
  that *absent and empty mean the same thing; neither is a default* — a sentinel makes that three
  spellings of one condition, which is how the next reader gets it wrong.
- **Make bot access an index-time scope: write the assigned bot ids into the Qdrant payload at
  upsert, so the header would have something to mean.** This is the only option that is
  architecturally coherent, and it is the one to understand. It is rejected because assignment
  changes *after* indexing: granting one bot access to an existing source would require a payload
  rewrite across every point of every version of that source, and revoking it would require the same
  rewrite with a data-exposure deadline attached. It also makes a chunk's stored payload depend on
  which *other* records reference it, so an ADR-010 rebuild from PostgreSQL would have to reproduce
  the assignment table's state at index time rather than its state now — which is the payload-drift
  failure `kb-architecture-map` Gotcha 5 describes, arrived at deliberately.
- **Leave the table alone and let each router author decide.** The header set is signed; two authors
  deciding differently is a 401, not a divergence.

**Consequences.** `X-KB-Actor-Type` becomes the only header narrowing an ingestion request beyond
the organization, which is what actually gates diagnostics on the far side. Per-bot ingestion
metrics are unavailable by construction and must be derived from the assignment table at read time.
And the operation's rate-limit bucket keys on `(org, operation)` rather than `(org, bot)` — a
tenant with many bots gets one ingestion budget, which is the correct reading of a shared corpus and
is stated here so nobody re-derives it as a bug.

**Revisit when** an ingestion-time decision genuinely depends on which bot will read the result —
bot-specific chunking, a bot-specific embedding model, or per-bot redaction. Any of those makes the
source stop being organization-scoped, at which point the header becomes meaningful and this
decision is wrong. The observable is a `bot_id` parameter appearing on any function under
`app/ingestion/`.

### ADR-068: `docs/08` §13.3 Describes the **Ingest Key**, Not the Transport Replay Key; the Two Are Different Keys With Different Components

**Status: `Accepted`. Supersedes no ADR. Deviates from `docs/08` §13.3, which is one component list
serving two purposes, and corrects `.claude/skills/kb-internal-api-contracts/SKILL.md` line 122's
`ingestion.submit` fingerprint row and line 208's hazard note, both of which cite §13.3 for the
**transport** key. `docs/22` § _Spec defects_ items 1 and 2 recorded the two symptoms in the first
research pass; this ADR names the cause. ADR-063 is the schema half of the same defect. Skill
obligation: `docs/22` § Q13.**

**Decision:** there are two keys and `app/ingestion/identity.py` is authoritative on the first.

| | **Ingest key** | **Transport idempotency key** |
|---|---|---|
| Who owns it | data plane, `identity.py` | control plane, `IngestionSubmission::fingerprint()` |
| Where it lives | `source_versions.ingest_key`, `UNIQUE (source_item_id, ingest_key)` | `X-KB-Idempotency-Key`, Valkey, 24 h |
| What it dedupes | **work identity** — does this version already exist? | **delivery** — is this the same submission again? |
| Components | `INGEST_KEY_PARTS`, including all three `*_cfg_version` and `embedding_model_version` | source id, then per item `(item id, canonical key, content hash)`, then `force_nonce` |
| Configuration versions | **in** | **absent, necessarily** |

**Reason, and it is the same structural fact ADR-066 rests on.** The three configuration versions and
the embedding-model identity are produced by the data plane, during and after parsing. Laravel does
not have them at submission time and cannot. A transport fingerprint that included them would be a
fingerprint Laravel cannot compute — so §13.3's list is not a specification of the replay key at all;
it is a specification of the *ingest* key, being cited for the wrong one.

**Two things follow, and both were already recorded as defects without the cause being named.**

1. **§13.3 omits `ocr_cfg_version` while §13.4 makes an OCR configuration change a version
   trigger.** Read literally, an OCR retune is a **silent no-op**: the resubmission dedupes against
   the completed run, the admin sees "already processed", and the new settings never reach a
   document. `INGEST_KEY_PARTS` includes it, and `compute_ingest_key` raises `VALIDATION` on an
   empty component with a message naming this exact failure. `identity.py` wins.
2. **§13.3 lists "source version" as a component, which is impossible.** The ingest key is what
   *decides* whether a version row should exist, so a key containing the version id is circular.
   `INGEST_KEY_PARTS` substitutes `source_item_id`, which is the identity that exists before the
   run. The circularity is not a typo: it is what a single list looks like once it has been asked to
   serve both keys, because a *transport* key legitimately may name a version and an *identity* key
   never can.

**How the hazard the skill warns about is actually covered.** Line 208 says that a fingerprint
omitting the configuration versions makes *"a reprocess triggered by a config change dedupe against
the original job and return its stale `job_id`"*. That is true of a design where the transport key is
the only key, and it is not true here: the shipped fingerprint carries `force_nonce`, which is what
an explicit reprocess moves, and the *ingest* key carries the configuration versions, so a
configuration change that the operator did not ask for still produces a new version. The two keys
cover the two cases between them, and neither covers both alone. **That is the reason to keep them
separate rather than an accident of who could compute what.**

**Rejected.**

- **One key for both jobs, computed by Laravel from what it has.** This is §13.3 read literally. It
  cannot include the configuration versions, so it silently becomes a delivery key wearing an
  identity key's name — and the first OCR retune is the silent no-op above.
- **One key for both jobs, computed by the data plane and returned to Laravel.** Inverts the
  transport: the replay key has to exist *before* the request, because its whole purpose is to
  recognise a retry of a request that may never have arrived.
- **Have Laravel pin the configuration versions at submission time from a snapshot.** Tempting,
  because the snapshot mechanism already exists for chat. Rejected because the values are not
  configuration Laravel resolves — they are outputs of parsing (which OCR ran, at what settings, on
  this document), so a pinned value would be a guess that the run then contradicts, and the ingest
  key would name an identity the work does not have.
- **Drop the transport key on `ingestion.submit` and rely on the ingest key alone.** The ingest key
  is computed too late: a duplicate submission that never reaches parsing is not deduped by anything,
  and `SubmitIngestionJob` has already demonstrated (commit `ec58a19`) what a wrong uniqueness key on
  that path costs — a silently discarded dispatch with nothing in `failed_jobs`.
- **Edit `docs/08` §13.3.** Barred by `docs/00-index.md`: `01`–`21` are verbatim extracts and a
  deviation is recorded as an ADR, never by rewriting the extract.

**Consequences.** `docs/08` §13.3 stays wrong on the page, and it is short, plausible and exactly
where an implementer looks — so this ADR and § Q13's skill correction are the only things standing
between the next reader and a fingerprint that dedupes an OCR retune away. The two keys also mean
two separate "did we already do this?" answers on one submission path, which can disagree: a replayed
delivery of an unchanged submission is a transport replay (stored response returned verbatim), while
a *new* submission whose configuration is unchanged is an ingest-key hit (no new version). Anyone
debugging "why did nothing happen" has to establish which of the two fired.

**Revisit when** `INGEST_KEY_PARTS` gains or loses a member — the same tripwire ADR-063 names — or
when the control plane acquires a legitimate reason to know a configuration version before the run,
which today it does not and cannot.

### ADR-069: The Two Mutable Payload Terms Are Carried by Two Synchronous, Verified Internal Operations — `source.status.sync` and `bot.access.sync`

**Status: `Accepted`. Closes the two `TODO(phase-c)` markers in
`services/core-api/app/Services/Sources/SourceService.php` (`disable()` and `enable()`) and the
`TODO(phase-c)` Qdrant-payload item in `app/Services/Bots/BotService.php`. Adds two operations to
`.claude/skills/kb-internal-api-contracts/SKILL.md`. Does **not** close the config-snapshot
active-version resolver, which stays Phase D.**

**Decision.** Four payload fields decide what a query may see — `org_id`, `bot_ids`,
`source_status`, `source_version_id`. Two of them are immutable facts about the point. The other
two change **after** the write, from the control plane, with no re-ingest, and they are carried by:

| Operation | Effect | Dispatched by |
|---|---|---|
| `source.status.sync` | one filtered `set_payload` per collection rewriting `source_status` | `SourceService::disable()` / `::enable()` → `SyncSourceStatusJob` |
| `bot.access.sync` | scroll + read-modify-write adding or removing one id in the `bot_ids` list | `BotSourceAssignmentService::assign()` / `::remove()`, `BotService::delete()` → `SyncBotAccessJob` |

Both are `POST /internal/v1/maintenance/{source-status,bot-access}`, HMAC-verified,
`X-KB-Idempotency-Key` required, and — unlike `submitIngestion` next door — **synchronous, answering
200 with the verification counts**.

**Reason the response is the proof rather than a 202.** `set_payload` returns the same
acknowledgement whether it rewrote ten thousand points or none. `kb-deletion-and-verification`'s
rule is not about deletion specifically; it is about the store acknowledging work it did not do, and
a filtered count is the only evidence any of this happened. A 202 would hand the caller exactly the
acknowledgement that proves nothing, and the caller is a queued job that can afford to wait. So the
data plane counts **positively** — points now carrying the intended value, compared against the
points in scope, `exact=True` on every count — and a report whose counts disagree is an exception on
both sides rather than a `passed: false` body a caller could ignore.

**Reason the collection set travels in the body.** The collection name encodes the embedding space,
so a source re-indexed after its organization changed embedding model has points in two of them. The
set is every DISTINCT `source_versions.embedding_model_version` of the source — **every** version and
not only the active ones, because a retired version's points survive until the purge. Laravel owns
that table and resolves it; the handler opens no database connection, for the same reason
`embedding/readiness` does not. An **empty** collection set is refused outright rather than treated
as a no-op: zero collections and zero points report identical counts, so the emptiness is decided in
the job, where the reason — "this source has never been indexed" — is knowable.

**Reason `bot.access.sync` is a read-modify-write and can only ever be one.** `bot_ids` is a LIST on
each point holding every bot the source is assigned to. A delete-by-filter on it destroys the chunks
the other bots still answer from, at HTTP 200, first observed weeks later. There is no shape of this
request that expresses a delete. Points are grouped by their *resulting* `bot_ids` value before
writing, and the loop re-scrolls from the beginning each pass rather than paging with an offset —
a rewritten point stops matching the filter, so an offset would skip whichever point moved up into
its place. `MAX_REWRITE_PASSES` turns the resulting "acknowledged but not applied" infinite loop
into a failure with a number in it.

**Reason `source_ids: null` is revoke-only.** It means every point in the organization and it exists
for the deleted-bot case: the `bot_source_assignments` rows are gone by dispatch time, so nothing can
enumerate what the bot could see. The same scope on a grant would assign a bot to every source the
tenant owns; it is refused in `SyncBotAccessJob`'s **constructor**, so it cannot be serialized onto
the queue, and again in `InternalAiClient` and again at the router.

**Reason the compensation is asymmetric.** `SyncSourceStatusJob::failed()` reverts an **enable** and
never a **disable**. An enable that did not land leaves the points carrying `disabled`: the console
says ready and every question goes unanswered — `kb-tenancy-isolation`'s correct-filter-wrong-payload
case — so reverting the row makes the console agree with what the index will actually do and makes
the operator's obvious next action re-run the sync. A disable that did not land leaves the points
carrying `ready`: reverting would *also* say ready, which is true of the index and the opposite of
what the operator asked for, and would discard the only durable record that they asked. The row stays
`disabled`, the `failed_jobs` row is the truth, and both branches log the divergence first.
`SyncBotAccessJob` has no compensation at all and says so: the assignment row is already committed
with its own `bot.source_assignment.*` audit row, which `AuditLogger` marks `ON_FAILURE_ABORT`
precisely so a retrieval-scope change is never silently unwound.

**Reason there is no replay store for the idempotency key.** The header is required — it is inside
the canonical string, so a caller that omits it has a signer that disagrees with ours — and it is
derived from the instruction so two deliveries carry one key. It is deliberately **not** stored: the
answer *is* the verification count, and returning a first delivery's stale count would tell the
control plane an index it has not looked at since is in a state it may no longer be in. Both
operations are convergent — the far side selects the points still *needing* the change — so
re-running one costs a filtered count and rewrites nothing. Idempotency is a property of the
operation here, not a claim in Valkey.

**Rejected alternatives.**

- **Purge and re-ingest on disable.** Turns a two-second toggle into a full reprocess at a provider's
  per-token price, and `kb-source-lifecycle` defines disable as excluding from retrieval *while every
  vector stays* — which is what makes re-enabling a payload write.
- **Wait for the config-snapshot resolver instead.** That resolver is what makes a disable immediate
  with no payload write, and it lands with the chat path. It is complementary rather than an
  alternative: the snapshot makes a disable instant, the payload rewrite makes it durable and makes a
  re-enable possible without a re-ingest. Waiting would have left both halves missing.
- **A `passed: false` 200 instead of an exception.** A body a caller can ignore is a body a caller
  will ignore, and this caller commits a control-plane status on the strength of the answer.
- **Deriving the collection set on the data-plane side.** A data-plane read to decide the scope of a
  data-plane write is a second authority on what the scope is, and the two disagree exactly when it
  matters.

**Consequences.** `SourceService::disable()` now has a failure mode it did not have before — the
index was told and did not verify — which is loud, lands in `failed_jobs`, and is strictly better
than the previous state, where the index was never told at all. `bot_ids` residue after a failed
revoke is stale rather than dangerous, because ULIDs are never reused, so no future bot can inherit
the term. And `bot.access.sync`'s cost scales with the corpus rather than being constant, which is
why `kb.timeouts.maintenance` is 45 s and why `SyncBotAccessJob`'s `#[Timeout]` is 90.

**Revisit when** the config-snapshot active-version resolver lands: at that point a disable is
already effective through the snapshot before this job runs, and the question worth re-asking is
whether the payload rewrite should become a background reconciliation rather than a dispatch on the
admin path. Also revisit if `bot_ids` ever stops being a list — the read-modify-write exists only
because it is one.

### ADR-070: The Upload Orphan Is Recovered by a Write-Ahead Key Ledger and a Control-Plane Sweep, Not by the Data Plane

**Status: `Accepted`. Closes the `TODO(phase-c)` in
`services/core-api/app/Services/Sources/Upload/SourceObjectWriter.php` and the security finding it
reported. Narrows `kb.maintenance.sweep_orphan_objects` in
`services/ai-service/app/maintenance/tasks.py`, which stays unowned for what remains of it. Does
**not** change the write-before-row ordering, which is reaffirmed.**

**A note on the label, because two findings share it.** The orphan finding is the
**security-auditor's S3**, and `docs/22` § S3 is an unrelated finding about the SSE Pydantic models.
Nothing renumbers either; the code comments say "security finding S3" and mean this one, and every
one of them sits next to a description of the defect so the label is never load-bearing.

**Decision.** `SourceService::create()` writes objects before the rows, because object storage does
not join a PostgreSQL transaction. That produces an orphan when the request dies in between. The
orphan is now **named before it is created**: `pending_source_objects` holds one row per key the
request is about to write, inserted before the bytes and deleted after the transaction commits. An
unmatched row is the input to `kb:sweep-orphan-objects`, an hourly Laravel command that re-asks
`source_items` whether anything claims the key, deletes the object only when nothing does, and skips
any reservation younger than `kb.upload_orphan_grace_minutes`.

**Reason this is a security finding rather than housekeeping.** The orphan sits at
`org/{org}/sources/{sourceId}/original/{sha256}` for a `sourceId` that never became a row. The
phase-2 purge sweeps the prefixes of sources that **exist**, and
`services/ai-service/app/deletion/verification.py` enumerates only under the prefixes it was given —
so the object is not merely missed, it is **certified clean while it survives**. That is
non-negotiable 6 failing in the one direction that produces a signed proof of a deletion that did not
happen. It is also not self-healing: `ObjectKey::originalUpload()` is source-scoped and the source id
is minted per request, so retrying the identical upload writes a **different** key.

**Reason the ledger and not "rows first".** `SourceObjectWriter` named both options. Rows-first —
insert `source_items` with a not-yet-written marker, write the object, clear the marker — changes the
failure mode of the whole create path: every reader of `source_items` acquires a state in which the
object may not be there, and `source_items_stored_object_is_complete` has to be weakened to let the
half-written row exist at all. The ledger is purely additive: no existing reader changes, no CHECK is
relaxed, and the only new failure mode is a row kept longer than it should be.

**Reason the sweep is Laravel's and not Celery beat's.** `routes/console.php` draws the line as
*this file decides whether a tenant's work should start; beat schedules data-plane repair*, and this
sweep's input is a **control-plane table** no process in the data plane can read. The data plane
keeps the adjacent sweep that genuinely is its own — `sweep-abandoned-multipart-uploads`, whose
parts `ListObjectsV2` cannot even see — and that separation is why nothing runs twice.

**Reason the sweep binds a tenant context per row.** `SourceItem` carries
`#[ScopedBy(OrganizationScope::class)]` and that scope **fails closed**: with no bound context it
applies `whereRaw('1 = 0')`. The claim check asked from a console command with no context therefore
answers "nothing claims this key" **for every key in the database**, and the sweep deletes every live
original it has a reservation for while exiting 0. Each row is processed inside
`TenantContext::runFor($row->organization_id, …)`. The Pest test for this is a two-organization
fixture — a claimed key in one, an orphan in the other — and removing the `runFor` was measured to
make it fail by destroying the claimed object, which is the only way to know the test is a test.

**Reason `PendingSourceObject` is the one org-owned model with no scope.** For the mirror-image
reason `OrganizationUser` and `OrganizationInvitation` have none: the reader that matters runs
without a context. A scoped model here would make the sweep read zero rows, hourly, forever, exiting
0 and reporting nothing to collect — the sweep silently ceasing to exist, which is the state the
finding describes. What replaces the scope is stated in the model's docblock: an explicit
organization predicate on every repository method, a database CHECK tying the key to the row's own
organization prefix, and a table that holds no tenant content.

**Reason the grace window is a refusal and not a clamp.** Between "not claimed" and `delete()` there
is a window in which a create transaction can commit; the grace window is what makes it negligible.
A configured window below `MINIMUM_GRACE_MINUTES` fails the whole tick with a message rather than
being silently raised to the floor, because a clamp lets a deployment believe it set zero and get
fifteen. The command also re-asks the claim **after** the delete and reports a race as a failing exit
code: nothing can undo the deletion, so the one thing that must not happen is silence.

**Rejected alternatives.**

- **Walk the bucket and delete what `source_items` does not name.** O(objects) against SeaweedFS
  every tick, blind to abandoned multipart parts anyway, and a full-bucket delete-anything primitive
  driven by a query.
- **`ON DELETE CASCADE` from `organizations`.** Deleting the ledger row does not delete the object.
  A cascade silently discards the only record naming bytes that are still on disk, outside every
  prefix the purge sweeps — the finding returning by a shorter road. `RESTRICT` makes an unswept
  orphan block the delete, loudly.
- **Releasing inside the create transaction.** A rollback would take the release with it, which is
  harmless; a commit that then failed on a later statement would have discarded the ledger entry for
  an object nothing points at, which is not.
- **Failing the request when the release fails.** The source exists and the caller's work succeeded;
  a 500 there invites a retry that creates a *second* source with a second set of objects. The
  release is best-effort and the sweep's claimed-count is what a lost release looks like.
- **An audit row per collected object.** `AuditLogger::OPERATIONS` is a closed catalog, the sweep has
  no actor and no surviving subject, and the precedent is `SubmitIngestionJob`: do not invent an
  operation for something no person did.

**Consequences.** Every create request now performs one extra INSERT per object and one DELETE per
source; the reservation failing fails the request, deliberately, because nothing has been written
yet. `organizations` gains a delete dependency that a stale reservation can block. And the sweep is
one of the few scheduled entries whose `ScheduledTaskFailed` is a real incident rather than a
dependency blip.

**Revisit when** the upload path ever writes an object it does not name in `source_items` — an
extracted thumbnail, say — because the claim check would then report it unclaimed and delete it. The
check is "does a `source_items` row name this key", not "is anything using it", and a second kind of
object needs a second question rather than a wider grace window.

### ADR-071: The E2E Administrator Is a Real Login With an Environment-Supplied Credential, Never a Seeder or a Test-Only Route

**Status: `Accepted`. Adds `apps/web/tests/e2e/auth.setup.ts`, which is the file
`playwright.config.ts` has declared a `setup` project for since it was written and which never
existed. Unblocks the `admin` Playwright project — nine spec files, 51 tests — and closes the
"THE `*.setup.ts` ITSELF" exclusion recorded in four of them.**

**Decision.** The setup fills the real `/login` form with `KB_E2E_ADMIN_EMAIL` /
`KB_E2E_ADMIN_PASSWORD` from the environment, asserts an authenticated surface rendered, and writes
`playwright/.auth/admin.json`. With no credential set it **deletes any stale state file and skips**,
which leaves `pnpm web:e2e` meaning "run the public project" — the behaviour every admin spec's
file-scope guard already assumed.

**Reason it drives the real route.** `playwright.config.ts` states it at the project: "No test-only
login endpoint, no `?org=` override, no seeded superuser that skips membership — a faked credential
cannot fail an isolation test." Logging in for real exercises `GET /sanctum/csrf-cookie`, the
URL-decoded `X-XSRF-TOKEN` echo, Sanctum's `fromFrontend()` classification of an `app.<domain>` →
`api.<domain>` request, both rate limiters, the membership read that resolves
`current_organization_id`, and the `verified` gate. A session minted any other way is a session no
isolation spec can fail against.

**Reason the credential is not seeded.** `DatabaseSeeder` is empty on purpose and its docblock
argues that test fixtures do not belong in it. `kb:bootstrap-organization` is the supported path and
is deliberately built so it *cannot* hand a password to a machine: it accepts one in no form,
creates the owner with an unusable 64-hex placeholder it never prints, and mails a reset link. That
is a property to respect, not to route around, so the password an E2E run uses is one a human set
through the ordinary flow. The consequence is accepted: a prepared machine is a manual step, and the
skip is what keeps an unprepared one honest.

**Reason the skip deletes the stale state file.** `playwright/.auth/` is gitignored but not
ephemeral. Skipping while leaving yesterday's file behind makes every admin spec's `existsSync`
guard pass, the specs run, and they restore an expired session — every one failing at a redirect to
`/login`, which reads as "the console is broken" rather than "you did not export the credential".
Deleting it makes the guard true if and only if this run authenticated.

**Reason the session cookie is not the proof.** `src/proxy.ts` records the measurement: Laravel
issues `kb_session` **to a guest**, because every auth form calls `GET /sanctum/csrf-cookie` first —
which is why the proxy's "looks signed in" redirect was removed. A setup that proved itself by
finding the cookie would pass having authenticated nothing. The proof is that `<CurrentOrgBadge/>`
rendered: it returns `null` unless the session query answered `authenticated` **and** named a
`current_organization_id` resolving to an active membership, and it is drawn in the browser from
`GET /api/v1/auth/me`. The cookie is still checked, separately, for the different reason that a
storageState with no cookies is a valid-looking artifact restoring no session.

**Also in this change, and each is a rule that had no mechanism.**

- `tests/e2e/admin/harness.ts` — four byte-identical copies of the axe tag set, the summarizer and
  the storage-state guard became one. The tag set is what drifts first and drifts invisibly: a spec
  scanning `wcag2aa` while its neighbours scan `wcag22aa` reports a clean page and checks less than
  the file beside it claims.
- Four new specs — `providers`, `models`, `embedding`, `members` — completing the Phase A admin
  surface. Each carries an axe pass, a dark-mode pass, a form-or-dialog pass, and an operability
  block for what a scanner cannot see. **None of them submits anything**: an E2E run against a real
  stack that created connections, registered models, saved a designation or sent invitations would
  leave residue in whatever organization the operator pointed it at.
- `tests/Security/InternalKeyRingSeparationTest.php` — `phpunit.xml` says beside the two
  `AI_CALLBACK_HMAC_KEY_C*` values that a shared secret would let "a verifier wired to the wrong ring
  still pass every callback test", and nothing checked it. `IngestionCallbackTest`'s outbound-ring
  test does not close it: it signs with the outbound **id**, so the verifier refuses on the id lookup
  before any secret is compared, and it would still pass if the two rings held one value. The new
  test signs with a callback **id** and the outbound **secret**, which reaches the comparison, and
  pairs it with the control that the same id and the correct secret is *not* 401. Both were verified
  by making the rings share a secret: two tests go red, the wire test answering 200 instead of 401.
- Two product-visible false statements removed. `/settings` and `/settings/providers` both told the
  administrator that the embedding screen "is being built and is not available yet". The link was
  rendered a batch before the route existed, deliberately, and the sentence was what made a 404 read
  as "not yet" — but `(admin)/settings/embedding/page.tsx` exists, so the sentence had become worse
  than the 404 it explained.

**Rejected alternatives.** A test-only login endpoint or a seeded superuser (the config's own comment
forbids both, and a credential that skips membership cannot fail an isolation test). A password
option on `kb:bootstrap-organization` (four independent properties of that command exist to make it
impossible, and `KB_BOOTSTRAP_OWNER_PASSWORD` would contain the string PASSWORD and need
allow-listing in `tests/Arch/SecretsResolverTest.php` — that suite telling you not to). Failing
rather than skipping without a credential (it would make `pnpm web:e2e` red on every machine that has
not been prepared, including for the public project, which needs no credential at all).

**Consequences.** The admin specs are now *runnable*, which is not the same as *run*: every one of
them still carries a "THIS FILE HAS NEVER BEEN EXECUTED" banner and every selector in them was read
out of a component rather than observed. The banners come off when somebody has a green run to point
at, and a first red run should be read as "the spec is wrong" at least as readily as "the page is
wrong". `--fail-on-flaky-tests` remains a property of the invocation and cannot be set from the
config: with `retries: 1`, a spec that only ever passes on the retry exits 0 forever.

**Revisit when** a second E2E role is needed. Several specs skip on "the signed-in role does not hold
`X`", and the coverage they skip — the analyst's 403 surfaces most of all — needs a second
storageState and a second project rather than a wider grant on the first account.

---

### ADR-072: The E2E Browser Origin Is `http://localhost`, and the Browser Itself Is a Build Stage

**Status: `Accepted`. Adds the `browsers` stage to `apps/web/Dockerfile` and one environment variable
to `playwright.config.ts` (`KB_E2E_API_ORIGIN`). Records the topology the first-ever green Playwright
run was made on, 2026-08-24. **Amended 2026-08-25** with a fourth required override — see
`CORS_ALLOWED_ORIGINS` below — after a second run on this same harness closed `docs/22` § R1 at
58 passed / 24 skipped / 0 red.

Do not read a figure for the first run from this line. It has carried one that disagreed with
`apps/web/tests/e2e/admin/harness.ts` since the day both were written, which is ADR-036's argument
arriving inside ADR-036's own register; `harness.ts` is where the run is described and it is the
authority.**

**Decision, in three parts.**

1. **The browser is a build stage, not a runtime install.** `apps/web/Dockerfile` gains a `browsers`
   stage: `FROM deps`, `playwright install --with-deps chromium`, `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`.
   Nothing in the production path descends from it, so it changed the size of zero shipped images.
2. **The browser origin is `http://localhost:3000` and the API origin is `http://localhost:8080`.**
   Not `app.<domain>` / `api.<domain>`, which is the topology `scripts/dev/hosts.md` teaches and the
   one this run was first attempted on.
3. **`webServer.env.NEXT_PUBLIC_API_ORIGIN` is `process.env.KB_E2E_API_ORIGIN ?? 'http://api.invalid'`.**
   The default is unchanged and stays the default.

**Why the browser had to be a build stage.** `knowledgebot/web:dev` is `node:22-bookworm-slim` plus
the workspace install: it carries no browser, and — measured — **zero** of `libnss3`, `libnspr4`,
`libatk-1.0`, `libcups2`, `libgbm1`, `libasound2`. So the gap was not "chromium is missing", which a
runtime `playwright install` would close; it was that the downloaded binary could not dynamically
link, and the error for *that* is a spawn failure naming no library. `--with-deps` runs `apt-get`,
needs root, and is exactly the thing a build stage is for. Two suites were blocked on this and neither
had ever run: `vitest --project components` (**448 tests**, browser mode via `playwright-core`) and
the whole Playwright suite. The revision is never written down here — the CLI invoked is the one
`pnpm install` put in the workspace — because a second place to update produces the message this stage
exists to prevent: `Executable doesn't exist at /ms-playwright/chromium_headless_shell-<n>/…`.

**Why `localhost` and not the four dev hostnames.** Two constraints have to hold at once, and the
named-host topology satisfies only the first:

- **Same-site.** Sanctum's SPA flow is cookie-based, so the browser origin and the API origin must
  share a registrable domain or `kb_session` is never attached and every request answers 401.
- **Secure context.** `use-file-uploads.ts` calls `crypto.randomUUID()`, which Web Crypto exposes only
  where the context is potentially trustworthy. On `http://app.knowledgebot.example:3000` it is
  `undefined`, the handler throws, and the files the user chose never become rows — five specs, no
  visible error (`docs/22` § R4).

`http://localhost` is the one origin satisfying both without TLS: same-site with itself, and
potentially-trustworthy by specification. The Playwright container therefore joins the
`laravel-api` container's **network namespace** (`--network container:knowledgebot-laravel-api-1`), so
Playwright's own Next server on `:3000` and the FPM pool on `:8080` are both `localhost` to the
browser. Laravel's `SESSION_DOMAIN` is empty (host-only — a leading-dot domain is invalid for
`localhost` and the cookie is dropped), `SESSION_SECURE_COOKIE=false`,
`SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8080`, and — **added 2026-08-25, because this
list was incomplete and the omission is invisible in every log** —
`CORS_ALLOWED_ORIGINS=http://localhost:3000`. `config/cors.php` sets `supports_credentials => true`,
so `allowed_origins` is an exact list that no pattern widens; without the entry the browser's login
preflight is refused, `auth.setup.ts` times out, the page reports *"the reason was not reported"*,
and **nothing reaches a Laravel log at all** because the request never arrives. `curl` does not
enforce CORS, so the obvious reproduction returns `200` and points away from the cause. `docs/22`
§ R9.

**Why `KB_E2E_API_ORIGIN` had to exist, and why `api.invalid` stays the default.** The literal
`http://api.invalid` was correct for what the config could do at the time and made the `setup` project
**unreachable by construction**: `auth.setup.ts` drives the real `/login` form, `lib/api/browser.ts`
posts it to `NEXT_PUBLIC_API_ORIGIN` from the browser, and there is no server-side proxy on that path
(`proxy.ts` is routing and CSP, and says explicitly that it is not the gate). Pinned to `.invalid`,
every sign-in fails on DNS, `admin.json` is never written, and all 51 `[admin]` specs skip on their own
guard — which reads as "no admin credential is set" rather than "this configuration cannot
authenticate". The default keeps the property it was chosen for: a spec that reaches the network fails
on DNS instead of quietly talking to a host that happens to exist.

**What this is not.** It is not a claim that the product works over plain HTTP, and no shipped
configuration serves it that way — Traefik has one entrypoint and it is `websecure`. It is a statement
about a test harness, and § R4 records the one product-visible consequence: a comment that explained
`crypto.randomUUID`'s availability by browser version rather than by secure context.

**Known cost, stated rather than hidden.** `next start` warns *"does not work with `output: standalone`"*
on every run, because `next.config.ts` sets `output: 'standalone'` and `playwright.config.ts`'s
`webServer.command` is `pnpm build && pnpm start`. It serves correctly today. The two are nonetheless
in disagreement, and the honest fix — `node .next/standalone/server.js` — is `admin-web-engineer`'s to
make, because standalone traces its own `node_modules` and changing the command changes what the specs
are running against.

**Revisit when** the hosted-chat origin needs coverage. `chat.<domain>` is a *different origin from*
`app.<domain>` on purpose (ADR-027), and one `localhost` cannot be two origins — so the `(chat)`
group's CSP and its cookie isolation cannot be tested under this topology at all. That needs Traefik,
the dev leaf certificate, and the dev CA trusted inside the browser container.

---

ADR-073…075 came from the effort of **2026-08-26/27** that built the five provider wire adapters, the
RAG stage modules, the conversation schema, the fallback router and the rerank designation. The full
register is [`docs/22`](22-spec-findings-and-decisions.md) § *Found while building the adapters, the
RAG stages and the conversation schema — T1–T41*; the three below are the items from it that are
decisions rather than defects. None supersedes anything. Two of them — 074 and 075 — are **applications
of an accepted ADR rather than departures from one**, which is why neither carries a supersession line:
ADR-074 is ADR-033's three admission properties producing a removal for the first time, and ADR-075 is
`postgresql-patterns`' partitioning rule found not to apply, with the reason named.

**ADR-073 is different in kind and should be read first.** It is not a new decision at all: it was
taken in code on 2026-08-26, is recorded in full in a docstring, and was cited from five sites in
`services/ai-service` as **"ADR-069"** — a number this register had already assigned to *The Two
Mutable Payload Terms*, above. So for a day the analyzer decision had no ADR and its citations pointed
at an unrelated one. The five citations have since been changed to name the decision rather than a
number, which is the correct interim (`docs/22` § T41); ADR-073 is the number they may now carry.
**The rule that produced the collision is worth stating once: a number is assigned here and nowhere
else.** A call site that mints one is how two decisions come to share a heading and neither can be
looked up.

### ADR-073: The BM25 Analyzer Is NFKC → Casefold → NFKC, Character Bigrams for Unsegmented Scripts, and Word-Character Runs Everywhere Else — Pinned at `bm25/v1` Only While No Corpus Exists

**Status: `Accepted` 2026-08-26, recorded here 2026-08-27. Supersedes nothing. Closes the open half of
ADR-032 (finding C2) and retires the dated ruling that held it open,
[`docs/22`](22-spec-findings-and-decisions.md) § G6. Constrained by ADR-034 and does not change
it.** The authority on what was chosen is `services/ai-service/app/retrieval/sparse.py`'s `tokenize`
docstring, which was written with the implementation; this entry is the register's copy of the argument
and the place a code comment may point.

**Context.** ADR-032 restored the sparse arm with locally computed BM25 and shipped every part of it
except the analyzer, deliberately: `tokenize` raised, and the hold was recorded as a dated ruling
rather than a to-do, *"precisely so it would not be filled with a plausible default"*. The plausible
default is whitespace segmentation, and the reason it is dangerous is not that it is inaccurate — it is
that its inaccuracy is **invisible in every metric this pipeline has**. An entire Chinese, Japanese or
Thai sentence becomes one term, that term is in no query, the lexical branch returns nothing, and *a
branch that matches nothing is indistinguishable from a corpus that contains nothing*.

**Decision, in four parts.**

1. **Normalization is NFKC, then `casefold()`, then NFKC again.** The second pass is not redundant:
   full case folding can emit sequences that are not NFKC-normalized, so a single pass is not
   idempotent and the function could disagree with itself across a round trip through storage.
   Compatibility folding is what makes fullwidth `ＡＢＣ` and `ABC` one term.
2. **Characters in a script written without word separators are emitted as character bigrams**, with a
   run of length one emitted as itself, because a one-character run has no bigram and dropping it would
   silently lose every single-ideograph term. The membership test is a fixed table of codepoint ranges,
   `_UNSEGMENTED_RANGES`. **Hangul is deliberately absent** — Korean is space-segmented, so bigramming
   it would shred words that segment correctly on their own.
3. **Everything else is a maximal run of word characters** — Unicode categories `Lu Ll Lt Lm Lo Nd Nl
   No Mn Mc`. `Mn`/`Mc` are in the set on purpose: dropping combining marks decomposes Devanagari and
   Arabic words into pieces that hash to ids no query will produce. Punctuation separates, so `abc-123`
   is two terms. A script boundary ends a run even with no punctuation between it, so `漢字abc` is two
   runs analyzed by two rules.
4. **`SPARSE_ANALYZER_VERSION` stays `bm25/v1`**, and the same function analyzes passages and queries —
   `encode_passage` and `encode_query` both call it and neither pre-processes its input.

**Reason the ranges are a table and not a library.** `unicodedata` exposes no script property, so the
alternatives are `regex`, PyICU, or a per-language segmenter. Every one of them would make **a version
in a lockfile part of the analyzer identity**, and by ADR-034 the analyzer identity is inside the
collection name — so a routine dependency bump would silently be a corpus-wide reindex, and the diff
that caused it would be one line in `uv.lock`. A hand-written range table is cruder and is *ours*: it
changes only when someone changes it, in a file whose neighbours say what that costs.

**Reason the version did not move, and this is the load-bearing half.** `run_version` raised
`NotImplementedError` until this function existed, so **no corpus has ever been indexed under
`bm25/v1`** and this choice invalidates nothing. That is the entire argument for taking the decision at
that moment rather than deferring it: the identical decision taken after the first tenant indexes is a
full re-embed of every organization's dense vectors at a provider's per-token price (ADR-034), charged
to whoever happens to be holding it. **The freedom is a property of the corpus being empty, and it
expires the first time a tenant indexes.**

**Rejected alternatives.**

- **Whitespace segmentation.** The default everyone reaches for, and the failure above: silent, total,
  per-language loss of the lexical arm with every dashboard green. Rejected on the failure mode rather
  than on accuracy.
- **A segmentation library (`regex` script properties, PyICU, jieba, MeCab, SudachiPy).** Better
  segmentation than bigrams, and it puts a third party's version number inside the collection name. It
  also adds a model or a dictionary to a repository whose whole scoping decision (ADR-030) is about
  what is loaded locally — a dictionary is not model inference, but it is one more pinned artifact with
  a licence and a revision, for a gain no evaluation run has yet measured.
- **Keeping joiners, so `abc-123` stays one term.** Tempting because part numbers are exactly what the
  lexical arm is for. Rejected because **every rule that keeps a joiner has to keep it identically on
  both sides, forever**: a query written `abc 123`, `abc‑123` with a non-breaking hyphen, or `ABC-123`
  must reach the same id as the indexed passage, and the analyzer that guarantees that is the one with
  the fewest special cases. Splitting costs a little precision and **costs no recall**, because a query
  containing `abc-123` splits the same way.
- **A single NFKC pass.** Cheaper and not idempotent under casefolding; the failure is a term that
  survives one trip through storage and not two.
- **Per-language analyzers selected by detected language.** Makes the analyzer a function of a detector
  whose output can differ between the indexing run and the query, which is the query/passage
  disagreement this decision exists to make structurally impossible.
- **Bumping to `bm25/v2` "to be safe".** Rejected because it is not free and reads as if it were: the
  version is inside the collection name, so a defensive bump renames the collection and re-embeds the
  dense vectors. A version bump is a migration, not a hygiene measure.

**Consequences, including the ones that hurt.** Bigrams are a real degradation against a real
segmenter: they roughly double the term count for CJK text, they produce term ids that cross word
boundaries, and precision on those languages is worse than a dictionary segmenter would give — this is
a floor that makes the arm work at all, not a good analyzer for CJK. Term overlap across scripts is
**zero**, so cross-lingual retrieval rides entirely on the dense branch, exactly as ADR-032 already
recorded. `abc-123` splitting costs precision on part numbers, which is the lexical arm's own use case.
And each of `BM25_K1`, `BM25_B`, `BM25_AVGDL`, `TERM_ID_MODULUS` and the term-hash personalization is an
input to this identity: changing any one of them is this decision changing, with the same price.

**Revisit condition, and it is an expiry rather than a trigger.** This decision is cheap to change
**only while no corpus has been indexed under `bm25/v1`**, and that stops being true at the first
successful `run_version`. The observable is the existence of any Qdrant collection whose name carries
the current `EmbeddingSpace` digest — after that, an analyzer change is a planned migration (a second
collection, both live, the active-version pointer moving once) with a provider bill attached, and never
an edit. Revisit **before** that point if an evaluation run over a CJK or Thai corpus shows bigram
recall materially below a segmenter's; revisit **after** it only with the migration budgeted.

---

### ADR-074: `retrieval_traces` Leaves the Data Plane's Write Allow-List; Laravel Writes It in the Same Transaction as the Message

**Status: `Accepted` 2026-08-27. Supersedes nothing. Closes
[`docs/22`](22-spec-findings-and-decisions.md) § T28. It changes the membership of `ALLOWED_TABLES` in
`services/ai-service/app/db/writes.py` and therefore narrows ADR-033's list, which is _applying_
ADR-033 rather than replacing it: ADR-033's decision is that admission is three properties rather than
a list, and this is the first time those properties have produced a removal. ADR-012's original
rationale is likewise untouched.**

**Context.** ADR-033's second admission property is *"no public API path reads or writes the table"*,
and ADR-033 calls it the one that actually bites. `retrieval_traces` was admitted under it. Two
admin-facing surfaces then arrived that are precisely a request to **read** that table from a public
API path: the playground's diagnostics panel, and the per-turn diagnostics on a historical conversation
transcript. The second is the one with no way out — a live SSE frame can serve the playground, and
nothing but the row can serve a transcript read six weeks later.

**Decision.** `retrieval_traces` comes off `ALLOWED_TABLES`. The data plane continues to **build** the
trace — `app/rag/runner.py` assembles `RetrievalTrace` with every field `docs/04` §8.24 requires and
emits it as the `retrieval.trace` frame — and never writes the row. Laravel's relay finalizer persists
it, inside the same transaction that already writes the message row, the citation rows and the usage
row.

**Reason: the sibling, not the property in the abstract.** `citations` and `retrieval_traces` are the
same kind of row — per-message diagnostics of one turn, written once when the turn finalizes, read
afterwards by an admin screen — and `citations` has always been Laravel's. The split across planes was
the anomaly; property 2 is the rule that names it. Arguing the property abstractly invites the
interpretation that was rejected below; arguing from the sibling does not, because there is no reading
of "these two rows are different" that survives looking at the schema.

**Reason the predicted cost turned out to be already paid.** § T28 priced the third option at *"a round
trip of trace data back across the seam"*. There is no extra round trip: the frame is already on the
wire because the playground needs it live, and the finalizer already parses the stream. Persisting it
costs one more `INSERT` inside a transaction that was already open — and buys atomicity the split could
not: **a trace can no longer outlive or precede the message it describes.** Under the old arrangement
two writers on two planes wrote two rows with no transaction between them.

**Rejected alternatives** — the three § T28 enumerated, which are not equivalent:

- **The playground reads the live `retrieval.trace` frame and never the table.** Works for the
  playground and does nothing for a historical transcript, which is the surface that cannot be served
  any other way. It answers the easier half of the problem and leaves the harder half to be answered
  again, differently.
- **Declare that an admin read is not "the public API".** The cheapest edit and the most expensive
  consequence: it weakens property 2 for **every other name on the list**, permanently, on an argument
  that would apply equally to the next one. A property with an exception for the case in front of you
  is not a property.
- **Keep the data plane as the writer and have Laravel read the row it does not write.** This is the
  status quo restated, and it is what property 2 forbids: a data-plane writer sitting beside a Laravel
  reader, with no policy, no audit row, and none of the framework-applied organization scope that every
  other conversation-side read goes through.

**Consequences, including the one that is invisible from here.** `erase_data_subject` (ADR-015,
`docs/13` §18.10) overwrites three verbatim columns in place — `citations.excerpt`,
`retrieval_traces.selected_evidence`, `evaluation_results.retrieved_evidence`. **Two of those three are
now swept over the core-api seam and only `evaluation_results.retrieved_evidence` is a local
statement.** `app/deletion/tasks.py` says so at its own docstring; if that sentence and the comment in
`writes.py` ever disagree, the tuple is the authority. Second: the trace's persistence now depends on
the frame reaching the finalizer, so a frame the relay drops is a row that is never written — which
makes `docs/22` § T32 (the client event union has no name for `retrieval.trace`) a *neighbour* of this
decision rather than an unrelated parser gap. Third, and stated because it is the honest cost:
`retrieval_traces` has a migration and a model and, at the time of writing, **no writer on either
plane** — the relay finalizer is Phase 4. The check is
`grep -rn 'retrieval_traces' services/core-api/app services/ai-service/app`, read for a statement
rather than for a mention.

**What this does not do.** It does not decide whether any *other* allow-listed name should follow.
Each is a separate application of the three properties, and the removal that produced this ADR is
evidence the properties work, not evidence the list should shrink.

**Revisit when** an admin surface stops being the only reader — specifically, when something inside the
data plane needs to read a trace it did not just produce. The evaluation suite is the candidate: it
reads `evaluation_results` locally today, and the day it wants a *production* turn's trace it will be
reading a Laravel-owned table over the seam, which is the mirror image of the problem this ADR closed.
The answer at that point is a read endpoint on the seam, not a re-admission.

---

### ADR-075: `messages` and `provider_calls` Stay Unpartitioned; Retention Is `retention_expires_at` Plus a Partial Index on `conversations`

**Status: `Accepted` 2026-08-27. Supersedes nothing. Closes
[`docs/22`](22-spec-findings-and-decisions.md) § T23 and retires the unresolved-contradiction paragraph
in `services/core-api/database/migrations/2026_08_26_002800_create_conversations_table.php`, which now
records this ruling and its expiry instead.
[`postgresql-patterns`](../.claude/skills/postgresql-patterns/SKILL.md)'s Definition of done asks for
both tables to be `PARTITION BY RANGE (created_at)`; this ADR does _not_ say that rule is wrong, and
the skill is owed no correction.**

**Context.** `postgresql-patterns` requires the partitioning decision to be taken *before the first row
lands*, which is correct and is why this is an ADR rather than a backlog item. D1 shipped the six
conversation tables unpartitioned and wrote the contradiction into the migration's docblock rather than
resolving it silently. The ruling is that the rule's precondition does not hold for these two tables.

**Decision.** `messages` and `provider_calls` are ordinary tables. Retention is a `retention_expires_at`
column on `conversations` with a partial index over it, so a sweep deletes **conversations** in bounded
batches and the children go with the cascade — rather than sweeping `messages` by date.

**Reason: the precedent is the argument, and it does not transfer.** `audit_logs` is monthly
range-partitioned (ADR-041) and is exactly the right shape for it — append-only, no children, nothing
holding a foreign key into it — so `PRIMARY KEY (id, created_at)` costs it nothing and
`DETACH PARTITION CONCURRENTLY` is available. `usage_events` (2026-08-27) is partitioned for the same
reasons and its own docblock states them. `messages` is the opposite shape: **it is referenced.** A
partitioned table's unique constraints must contain the partition key, so `messages` would take
`PRIMARY KEY (id, created_at)`, every referencing table would carry a denormalized `message_created_at`
alongside a composite foreign key, and `provider_calls.message_id` — nullable, `ON DELETE SET NULL` —
could not be a foreign key at all. That trades away referential-integrity constraints to buy a
retention mechanism this schema already has by another route.

**Do not take a count of those references from this entry (ADR-036).** The migration docblock says four
and names them; the tree currently holds a fifth the docblock does not count, `messages`' own
self-referencing `messages_parent_same_conversation` — `(conversation_id, parent_message_id) REFERENCES
messages (conversation_id, id) ON DELETE SET NULL (parent_message_id)` — which is the strongest instance
of the argument, because a partitioned self-reference would have to carry the partition key on both
sides of a constraint whose whole job is to keep a reply inside its own conversation. The command is

```bash
grep -rn 'REFERENCES messages' services/core-api/database/migrations/
```

**Why weakening one of those constraints is the specific risk, rather than a general worry.**
`docs/22` § Q3 is this repository's own record of it: a composite foreign key was quietly weakened, and
the test covering it wrote a row violating **both** halves and asserted a disjunction, so dropping the
constraint left forty tests green. A denormalized `message_created_at` maintained by application code
across four or five tables is that shape multiplied, and the failure is a child row pointing at a
message in a different conversation — which every downstream filter would then *agree* with.

**Rejected alternatives.**

- **Partition both tables now, as the skill asks.** The honest reading of the Definition of done, and
  it is rejected on the trade above rather than on effort. It also pulls in a partition creation and
  pruning subsystem of the kind `audit_logs` needed two console commands and a scheduler entry for —
  real machinery whose failure mode is a missing future partition and an insert that errors at
  midnight.
- **Partition `provider_calls` only.** It has one child-shaped relationship and looks like the easy
  half. Rejected because `provider_calls.message_id` is the reference that partitioning `messages`
  breaks, so splitting the decision leaves the two tables disagreeing about whether that link is a
  foreign key, and because a per-table answer means the retention story is two mechanisms.
- **Delete from `messages` by date directly, with no partitioning and no `conversations` sweep.** This
  is the shape the partial index was chosen to avoid: an unbounded date scan over the largest table in
  the schema, with the cascade fanning out per row.

**Consequences, including the ones that hurt.** A batched delete is strictly weaker than
`DETACH PARTITION CONCURRENTLY`: it takes row locks, it produces dead tuples for autovacuum to reclaim,
and it competes with live traffic in a way a detach does not. The skill is right that converting after
rows exist is a full-table rewrite, so this decision is genuinely one-way in practice. And the
retention path now depends on a *partial* index continuing to be chosen by the planner — a predicate the
query must match exactly, which is a coupling between a migration and a sweep query that nothing
mechanically checks.

**Revisit condition, and it is a measurement rather than a feeling** — a ruling with no stated way to
become wrong is the shape `docs/22` § Q5 and § Q6 are both about. The trade re-opens when either of two
things is observed: **a retention sweep's p99 stops fitting its batch window**, or **the partial index
stops being chosen for that sweep** (an `EXPLAIN` showing a sequential scan on `conversations`, or the
predicate drifting out of alignment with the query). Whoever observes it owns re-opening this, and the
migration that reverses it is a full-table rewrite that must be budgeted as one — never attached as a
follow-up to something else.

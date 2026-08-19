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

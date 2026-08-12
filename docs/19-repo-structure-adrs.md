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
reviewer applies, so it is banned; `gates.yml` has six jobs is a description of a file that names
itself, so it stays. When the two readings disagree, write the rule and the command both: that
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

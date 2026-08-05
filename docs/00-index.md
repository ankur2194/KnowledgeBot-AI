# KnowledgeBot AI — Specification Index

The full specification lives in `KnowledgeBot-AI.md` (3,816 lines). It is split here so agents can load only the sections a task needs. **Every file below is a verbatim extract** — the split adds a title header and nothing else. When spec and code disagree, the spec is authoritative until an ADR says otherwise.

Cite sections as `docs/<file>.md §<n>`.

| File | Spec §§ | Covers |
|---|---|---|
| [01-product-scope.md](01-product-scope.md) | §1–7 | Purpose, vision, goals, non-goals, roles, user journeys |
| [02-functional-auth-tenancy-bots.md](02-functional-auth-tenancy-bots.md) | §8.1–8.7 | Auth, **multi-tenancy rules**, bot config, provider/model management, adapter responsibilities, routing & fallback |
| [03-functional-knowledge-sources.md](03-functional-knowledge-sources.md) | §8.8–8.17 | Source types, **15-state lifecycle**, uploads, per-format document handling, crawling, crawl security, recrawl, **deletion & verification** |
| [04-functional-channels-chat.md](04-functional-channels-chat.md) | §8.18–8.24 | Chat UX, hosted chat, embedded SDK + its security, mobile, conversations, analytics, admin playground |
| [05-tech-stack.md](05-tech-stack.md) | §9 | The chosen stack per layer, with rationale |
| [06-architecture.md](06-architecture.md) | §10–11 | Control plane vs data plane, component map, service communication, SSE, internal contracts |
| [07-rag-query-pipeline.md](07-rag-query-pipeline.md) | §12 | The 20 query stages: filters, dense, sparse, fusion, rerank, threshold, packing, prompt, citations |
| [08-ingestion-pipeline.md](08-ingestion-pipeline.md) | §13 | 18 ingestion stages, idempotency, versioning, atomic publication, failure recovery |
| [09-chunking.md](09-chunking.md) | §14 | Structure-aware chunking, tables, repeated-content removal, chunk metadata |
| [10-embedding-indexing.md](10-embedding-indexing.md) | §15 | Embedding service, collections, re-embedding, index rebuild |
| [11-data-model.md](11-data-model.md) | §16 | All PostgreSQL entities: tenancy, providers, bots, sources, crawl, conversations, evaluation, ops |
| [12-api-areas.md](12-api-areas.md) | §17 | Admin, public runtime, SDK, mobile, and internal AI API surfaces |
| [13-security.md](13-security.md) | §18 | Credentials, authz, widget security, **prompt injection**, file & crawler security, privacy, audit |
| [14-reliability.md](14-reliability.md) | §19 | Error taxonomy, retry policy, circuit breakers, timeouts, idempotency, degradation |
| [15-observability.md](15-observability.md) | §20 | Trace coverage, metric catalog, structured logging, health checks |
| [16-evaluation.md](16-evaluation.md) | §21 | Evaluation datasets, metrics, experiments, regression gate |
| [17-testing-performance.md](17-testing-performance.md) | §22–23 | Unit/contract/integration/e2e/security/perf tests, latency targets |
| [18-deployment-backup-cicd.md](18-deployment-backup-cicd.md) | §24–26 | Compose services, networks, profiles, sizing, backup/DR, CI/CD pipeline |
| [19-repo-structure-adrs.md](19-repo-structure-adrs.md) | §27–28 | Monorepo layout and ADR-001…010 |
| [20-roadmap-mvp-demo.md](20-roadmap-mvp-demo.md) | §29–33 | MVP set, post-MVP, phases 0–7, acceptance criteria, demo script |
| [21-risks-licensing-glossary.md](21-risks-licensing-glossary.md) | §34–40 | Risk table, licensing, docs deliverables, glossary, official reference links |

Two sections have no counterpart in the original spec. They were produced while
building the skill library and record what reading the spec closely revealed:

| File | Holds |
|---|---|
| [22-spec-findings-and-decisions.md](22-spec-findings-and-decisions.md) | Spec defects found during research, open decisions, ADR candidates, and external constraints |
| [23-unverified-claims.md](23-unverified-claims.md) | Every `UNVERIFIED` marker in the skill library, triaged by how it closes |

## Where the invariants live

The rules most likely to be violated by a well-meaning implementation, and their source:

| Invariant | Source |
|---|---|
| Every tenant record carries an org identifier; every Qdrant point carries tenant + bot payload | `02` §8.2 |
| Every vector query filters org, bot access, active source status, active version | `07` §12.6 |
| Browser/mobile clients never call FastAPI directly — always through Laravel | `06` §11.1 |
| PostgreSQL is source of truth; Qdrant is a derived, rebuildable index | `06` §10.3, `19` ADR-010 |
| A source version stays searchable until its replacement is fully indexed and activated | `03` §8.9, `08` §13.5 |
| Deletion targets stable identifiers, never text matching, and is verified afterwards | `03` §8.17 |
| Retrieved source content is untrusted data and can never override instructions | `07` §12.14, `13` §18.6 |
| Citations derive from retrieved evidence, not free-form model output | `07` §12.16–12.17 |
| No provider credential ever reaches a client | `13` §18.2, `04` §8.20 |

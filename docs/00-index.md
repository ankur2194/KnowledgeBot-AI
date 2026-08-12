# KnowledgeBot AI — Specification Index

The full specification lives in `KnowledgeBot-AI.md` (3,816 lines). It is split here so agents can load only the sections a task needs. **Every file below is a verbatim extract** — the split adds a title header and nothing else. When spec and code disagree, the spec is authoritative until an ADR says otherwise.

Cite sections as `docs/<file>.md §<n>`.

**Do not edit `01`–`21`.** A deviation is recorded as an ADR, never by rewriting the extract. The one
place the rule is relaxed is `19-repo-structure-adrs.md` **§28**, which is where new ADRs are appended —
§27 and every ADR-001…010 entry there remain verbatim. Writable files in this directory: `00`, `19` §28,
`22`, `23`.

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
| [19-repo-structure-adrs.md](19-repo-structure-adrs.md) | §27–28 | Monorepo layout and ADR-001…037. **Every number is `Accepted`** — ADR-036 was the last `Proposed` one and was accepted 2026-08-12 as option (a), narrowed. Two are **narrow supersessions**: ADR-033 replaces ADR-012's table list, ADR-037 replaces ADR-026's fail-open reasoning — in both cases the superseded ADR's decision stands and only the named claim moves |
| [20-roadmap-mvp-demo.md](20-roadmap-mvp-demo.md) | §29–33 | MVP set, post-MVP, phases 0–7, acceptance criteria, demo script |
| [21-risks-licensing-glossary.md](21-risks-licensing-glossary.md) | §34–40 | Risk table, licensing, docs deliverables, glossary, official reference links |

Two sections have no counterpart in the original spec. They were produced while
building the skill library and record what reading the spec closely revealed:

| File | Holds |
|---|---|
| [22-spec-findings-and-decisions.md](22-spec-findings-and-decisions.md) | Spec defects found during research, the resolved decisions behind ADR-011…035 and ADR-037 with their rejected alternatives, external constraints, the findings still open after scaffolding (O1–O27), ADR-030's three consequences (C1–C3, now largely closed by ADR-031…035 with what remains open stated per finding), the spec text ADR-030 supersedes, the enforcement-claim audit (E1–E4) separating what CI enforces from what is only specified, the 2026-08-10 scope re-baseline (R1–R8) recording the documents that went false about the repository once all six scaffolding Non-goals were crossed, and the 2026-08-11 stub-completion findings (F1–F13) — which now also carry a refutation (F9), a correction to a figure this effort's own planning repeated three times (F10), the two contract gaps ADR-030's degraded path opened (F11, F12), and five Collector settings that read as configuration and configured nothing (F13), and the **2026-08-12 rulings (G1–G19)** — nine deliberately-open questions closed on one day, four of which went a different way from the brief that requested them, plus the six things found while recording them (G10–G15), and the four from the pass that then closed G10 and G11: a site table that went stale in one day and whose stale rows invite a re-break (G16), the `blur >= 3.0` degradation that reached the index as silence and now warns (G17), the `logit` metric gap ruled rather than deferred (G18), and a hadolint invocation that could never have read a per-file config (G19), and the three the first cold start of the stack produced (G20–G22): a widget origin that answered 403 at the edge while its healthcheck reported healthy, one missing root file that stopped `pnpm lint` in both node containers, and a documented development workflow that executes neither on the host nor in any image — and **G23**, where the three verifications Batch 16 recorded as owed were finally run: hadolint and the Pest allow-list mutation both confirmed, while the `blur >= 3.0` reproduction **refuted its own premise** and left the detector correct but unfired |
| [23-unverified-claims.md](23-unverified-claims.md) | Every `UNVERIFIED` marker in the skill library, triaged by how it closes, plus the claims raised by scaffolding, by ADR-030, by ADR-031…035, by the stub-completion effort and by the 2026-08-12 rulings that carry no marker — including the four things this host structurally cannot verify (the Actions runtime, and any mobile behaviour on a device), and one real degradation that currently reaches the index as silence (a page classified as a picture loses its text with no warning) |

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
| Both planes render an unhandled internal exception identically — 500, `internal_dependency`, `retryable: false` | `19` ADR-029 |
| No embedding or reranking model runs locally; both go through the provider adapter layer, and **reranking is an optional, capability-gated stage** whose skip is recorded on the trace | `19` ADR-030, `22` § C1 |
| One rule decides which connection embeds, and eligible connections that disagree on `(provider, model)` are refused rather than tie-broken — that pair *is* the vector space | `19` ADR-031 |
| Sparse retrieval is locally computed BM25; **no corpus statistic is ever stored in Qdrant**, and IDF is applied to the query vector from `(org_id, allowed_version_ids)` | `19` ADR-032 |
| The data plane's PostgreSQL write allow-list is `ALLOWED_TABLES` in `app/db/writes.py`; membership is *derived and rebuildable*, *untouched by any public API path*, *migrated by Laravel* — never a count | `19` ADR-012 + ADR-033 |
| An embedding space names its collection; the drift digest never does, and changing the embedding model or the sparse analyzer is a reindex | `19` ADR-034, ADR-035 |
| A count a legitimate change could make wrong, and that a reader would act on *as the rule*, is written as a rule or as the command that re-measures it — never as a number. Orientation counts stay, provided the sentence names where the authoritative list lives | `19` ADR-036, `22` § R1–R8 + G1 |
| Which of the two committed credential files fails **open** is measured, never reasoned: `seaweedfs` exits 255 without its identities file, `valkey` starts wide open without its ACL file — the reverse of what eight files said — and a `ping \| grep -q PONG` probe reports healthy against a wide-open server | `19` ADR-037 |

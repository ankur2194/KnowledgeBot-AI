# KnowledgeBot AI — Module Inventory

Derived from the original product document [`KnowledgeBot-AI.md`](KnowledgeBot-AI.md) (3,816 lines).
Every module below is grouped by deliverable surface, with the spec sections it comes from.

> **Caveat:** this file is an inventory of what the *original* document specifies, and several entries
> have since been changed by ADR — see [Deviations from the original document](#deviations-from-the-original-document)
> at the end. The count is not restated here on purpose (ADR-036): the table is the list.
> For what actually exists in the tree today, grep the code; see `CLAUDE.md`.

---

## A. Client applications

### `apps/web` — Next.js admin + hosted web (§9.1, §27)

- Auth & session UI (login, verification, reset, invitations) — §8.1
- Organization settings & user/role administration — §8.1, §17.1
- Provider connection manager + model catalog editor (incl. connection test) — §8.4
- Bot manager: config, prompts, model selection, retrieval settings, appearance, allowed origins,
  starter questions, source assignments — §8.3
- Knowledge source manager + upload UI (drag-drop, batch, progress, cancel) — §8.10
- Source preview & inspection (parsed content, chunks, warnings, versions, freshness) — §8.16
- Crawl configuration & schedule UI — §8.12, §8.14
- Processing-job progress view — §13.6
- Conversation review browser — §8.22
- Analytics dashboard — §8.23
- Evaluation UI (datasets, cases, runs, comparisons) — §21
- Audit-log viewer — §18.11
- **Admin playground / retrieval debugger** (dense, sparse, fusion, rerank scores,
  selected and excluded context) — §8.24
- Hosted chat page (public / password / authenticated modes, theming) — §8.19

### `apps/widget` — embeddable chat SDK (§8.20, §9.2)

- Host-page loader script (launcher, open/close, config passing)
- Preact iframe chat application
- `postMessage` bridge with origin checks + versioned envelope
- SDK configuration surface
- SDK event API (opened, closed, conversation started, message sent, response completed,
  citation opened, feedback submitted, error)
- Widget security layer (origin allow-list, short-lived tokens, signed user metadata, CSP, sandbox)

### `apps/mobile` — React Native + Expo (§8.21, §9.3)

- Sign-in / org selection, bot picker, conversation list
- Streaming chat surface, citation viewer, feedback submission
- SecureStore credential persistence
- Android + iOS builds

---

## B. Laravel control plane — `services/core-api` (§9.4, §17.1–17.4)

- Authentication (email/password, verification, reset, Sanctum sessions + personal access tokens,
  optional 2FA)
- Multi-tenancy & organization model — §8.2
- RBAC: roles, permissions, policies, the six-check protected-action rule — §18.4
- Provider connection management + envelope-encrypted credentials + connection-test action — §8.4, §18.2
- Provider/model catalog with capability flags and pricing metadata — §8.4
- Bot management + domain allow-list + source assignment — §8.3
- Knowledge-source metadata & lifecycle orchestration — §8.9
- Upload intake, validation, hashing, object-storage handoff — §8.10
- Crawl configuration + schedule ownership — §8.14
- Conversations, messages, citations, feedback — §8.22
- Public chat runtime API + **SSE relay** to clients — §11.3, §17.2
- Chat-session issuance for hosted / widget / mobile — §17.3
- Quotas, rate limiting, usage recording — §8.23
- Analytics aggregation (PostgreSQL-only, no warehouse) — §8.23
- Audit logging with redaction — §18.11
- Laravel queue workers + scheduler (recrawl, maintenance, retention) — §9.4
- Signed internal client to FastAPI (trace ID, org, bot, idempotency key, config snapshot) — §11.2
- Versioned OpenAPI documentation — §11.4

---

## C. FastAPI AI data plane — `services/ai-service` (§9.5, §17.5)

### Internal API surface (§17.5)

Chat execution · provider connection test · ingestion submission + status callback · crawl submission ·
deletion submission · retrieval diagnostics · evaluation execution · health and readiness.
Never publicly exposed.

### Provider layer (§8.5–8.7)

- Internal request/response abstraction + capability flags
- Five adapters: OpenAI, Anthropic, DeepSeek, NVIDIA NIM, OpenRouter
- Stream-event translation, token-usage extraction, stop-reason mapping, error classification
- Routing modes (fixed / manual / ordered fallback) + fallback eligibility rules
- Circuit breakers — §19.3

### Ingestion pipeline (§13)

- Acquisition, validation, hashing, object storage
- Docling parsing: PDF, DOCX, XLSX, PPTX, HTML, Markdown, images — §8.11, §9.10
- OCR subsystem incl. scanned-page detection and confidence warnings
- Structural normalization, content cleanup, repeated-boilerplate removal, metadata enrichment
- Idempotency, source versioning, atomic publication, old-version retirement, failure recovery — §13.3–13.7

### Chunking (§14)

Structure-aware boundaries · table serialization with header association · parent/child metadata ·
full chunk metadata schema.

### Embedding & indexing (§15)

- Embedding service (batching, retry, dimension validation, version reporting)
- Sparse representation builder
- Qdrant collection design
- Re-embedding workflow
- Index rebuild (source / bot / organization / whole collection)

### Crawling (§8.12–8.14, §9.11)

Guarded fetch · sitemap and sitemap-index discovery · include/exclude rules ·
depth, page, concurrency and delay policy · JS-render policy · SSRF envelope ·
HTML-to-Markdown extraction · canonical, redirect and duplicate handling ·
change detection · recrawl scheduling · missing-page policy.

### RAG query pipeline (§12)

The 20 stages: validation → access/quota → conversation condensation → normalization → rewriting →
filters → dense retrieval → sparse retrieval → RRF fusion → dedup/diversity → rerank →
evidence threshold → context packing → prompt construction → provider call → streaming →
citation linking → citation validation → usage recording → feedback hooks.
Plus conflicting-source handling and strict-RAG vs RAG-first modes.

### Deletion & verification (§8.17)

Immediate logical exclusion · background purge across PostgreSQL, Qdrant, SeaweedFS and Valkey ·
cascading identity · verification job.

### Evaluation (§21, §9.15)

Dataset & case management · retrieval metrics · Ragas LLM-judged metrics ·
experiment runner with immutable config snapshots · regression gate.

### Celery workers (§9.5, §24.2)

Ingestion · crawl · embedding · evaluation (and deletion) queues.

### Supporting

Pydantic internal contracts · error taxonomy mapping · telemetry instrumentation.

---

## D. Shared packages (§27)

| Package                   | Contents                                              |
| ------------------------- | ----------------------------------------------------- |
| `packages/contracts`      | shared API schemas, SSE frame types, generated clients |
| `packages/design-tokens`  | tokens shared by web and widget                        |
| `samples`                 | sample documents and evaluation datasets               |
| `scripts`                 | controlled operational utilities                       |

---

## E. Data-model modules (§16)

Eight relational schema groups:

1. Identity & tenancy — `users`, `organizations`, `organization_users`, roles and permissions
2. Provider configuration — `provider_connections`, `provider_models`
3. Bots — `bots`, `bot_domains`, `bot_starter_questions`, `bot_source_assignments`
4. Knowledge sources — `knowledge_sources`, `source_items`, `source_versions`,
   `document_elements`, `chunks`
5. Crawling — `crawl_configurations`, `crawl_runs`, `crawl_page_results`
6. Conversations — `conversations`, `messages`, `provider_calls`, `retrieval_traces`,
   `citations`, `feedback`
7. Evaluation — `evaluation_datasets`, `evaluation_cases`, `evaluation_runs`, `evaluation_results`
8. Operations — `background_jobs`, `audit_logs`, `usage_events`

Plus Qdrant collections and payload schema, Valkey keyspaces, and SeaweedFS bucket layout.

---

## F. Cross-cutting modules

- **Security** — §18 — credential envelope encryption, authentication, authorization, widget security,
  prompt-injection defense, file security, crawler security, output sanitization, privacy controls, audit
- **Reliability** — §19 — 17-category error taxonomy, retry/backoff, circuit breakers,
  nine timeout classes, idempotency, graceful degradation
- **Observability** — §20 — single-trace coverage, metric catalog
  (chat, retrieval, providers, ingestion, crawling, infrastructure), structured logging, health checks
- **Testing** — §22 — unit, contract, integration, end-to-end, security and performance suites

---

## G. Infrastructure & operations (§24–26)

- **`infrastructure/docker`** — Compose topology: `traefik`, `web`, `sdk`, `laravel-api`,
  `laravel-worker`, `laravel-scheduler`, `ai-api`, four `ai-worker-*` roles, `postgres`, `qdrant`,
  `valkey`, SeaweedFS (master / volume / filer / s3); four networks (`edge`, `application`, `data`,
  `observability`); five profiles (`core`, `observability`, `gpu`, `dev-tools`, `test`)
- **`infrastructure/observability`** — otel-collector, Prometheus, Grafana, Loki, Tempo or Jaeger,
  plus dashboards
- **Backup & DR** — §25 — PostgreSQL, object storage, Qdrant snapshot *or rebuild*,
  key recovery material, restore runbooks
- **CI/CD** — §26 — the 16-step pipeline including Trivy, Syft, Semgrep, Gitleaks, Hadolint,
  and the RAG regression suite
- **`docs`** — 19 documentation deliverables — §36

---

## Deviations from the original document

The modules listed above that have since been changed by ADR and no longer match the original spec:

| Original spec                                     | Current decision                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Local BGE-M3 embedding service (§9.12, §15.2)     | ADR-030 — embedding goes out through the provider adapter layer; no local weights     |
| Local BGE reranker cross-encoder (§9.12, §12.11)  | ADR-030 — reranking is a provider call, therefore capability-gated rather than guaranteed |
| Haystack as internal pipeline toolkit (§9.5, §40) | ADR-016 — dropped; KnowledgeBot owns its own stage runner                             |
| **Sanctum sessions _+ personal access tokens_ (§B)** | **ADR-038 — the admin API has exactly ONE credential: the Sanctum SPA cookie session.** `App\Models\User` deliberately does not use `HasApiTokens`, nothing mints a token, no migration creates `personal_access_tokens`, and `App\Http\Middleware\RejectBearerToken` answers 401 to any `Authorization: Bearer` on the `api` group. PATs are not deferred — they are **excluded from this surface**, and a future mobile effort must re-add the trait, the table, the prune schedule and `Sanctum::authenticateAccessTokensUsing()` deliberately, on its own surface |
| **Optional 2FA (§B)**                             | **Out of scope and not built.** No ADR: it was never decided against, it was simply not in the auth-and-session scope. Distinct from the row above, which is a decision |

Sparse retrieval survives as locally computed BM25 under ADR-032.

**On the `apps/web` and `services/core-api` auth rows specifically:** login, logout, `GET /me`, org
switch, password reset, email verification, invitation-gated registration and organization invitations
are **built**, with a real partitioned `audit_logs` table (ADR-041). What is deliberately absent from
the built surface is personal access tokens, 2FA, and global `users.status` suspension — the first by
ADR-038, the other two out of scope. Read ADR-038…043 in
[`docs/19-repo-structure-adrs.md`](docs/19-repo-structure-adrs.md) before treating either row as owed
work, and grep the tree rather than this file for what exists.

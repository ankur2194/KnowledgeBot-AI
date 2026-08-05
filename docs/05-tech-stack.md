# Recommended Technology Stack

> Part of the **KnowledgeBot AI** specification — §9, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 9. Recommended Technology Stack

## 9.1 Web and Admin Frontend

| Area | Technology | Purpose |
|---|---|---|
| Framework | Next.js with App Router | Admin application, hosted chat, authenticated web experience |
| Language | TypeScript | Type safety and shared contracts |
| UI styling | Tailwind CSS | Utility-based styling |
| UI components | shadcn/ui and Radix primitives | Accessible, customizable admin components |
| Forms | React Hook Form | Form state and validation integration |
| Validation | Zod | Client-side schemas and shared TypeScript validation |
| Server state | TanStack Query where useful | API caching, mutations, retries, and background refresh |
| Tables | TanStack Table | Admin data tables |
| Charts | Apache ECharts or Recharts | Usage and quality dashboards |
| Testing | Vitest and Playwright | Component, integration, and end-to-end testing |

Next.js should be used as the frontend application layer, not as a second independent business backend. Laravel remains the authoritative business API.

## 9.2 Embedded Chat SDK

| Area | Technology | Purpose |
|---|---|---|
| UI runtime | Preact | Small client bundle |
| Language | TypeScript | Typed SDK contracts |
| Build system | Vite | Library and iframe application builds |
| Isolation | Iframe | Prevent host-page CSS and script conflicts |
| Distribution | Versioned static assets and optional npm package | CDN and package-based installation |
| Communication | `postMessage` with strict origin checks | Host page to widget communication |

## 9.3 Mobile

| Area | Technology | Purpose |
|---|---|---|
| Framework | React Native | Shared Android and iOS application |
| Tooling | Expo | Development, native integration, and builds |
| Routing | Expo Router | Application navigation |
| Data | TanStack Query | API state |
| Secure storage | Expo SecureStore | Sensitive local values |
| Testing | Jest and React Native Testing Library | Mobile application testing |

## 9.4 Core Business Backend

| Area | Technology | Purpose |
|---|---|---|
| Framework | Laravel | Main business API and control plane |
| Language | PHP | Business application implementation |
| Authentication | Laravel Sanctum | Web session and token authentication |
| Authorization | Laravel policies and a permission package or internal RBAC | Tenant-aware permissions |
| Scheduling | Laravel Scheduler | Recrawl and maintenance orchestration |
| Queues | Laravel Queue with Valkey | Business background jobs |
| API documentation | OpenAPI generated from maintained contracts | Public and internal API documentation |
| Testing | Pest or PHPUnit | Unit, feature, and integration testing |

Laravel will own:

- Users.
- Organizations.
- Roles.
- Bots.
- Provider configuration metadata.
- Encrypted credentials.
- Knowledge source metadata.
- Source assignment.
- Conversation records.
- Usage records.
- Audit logs.
- Public chat-session issuance.
- Admin APIs.
- Scheduling policies.

## 9.5 AI and RAG Service

| Area | Technology | Purpose |
|---|---|---|
| Framework | FastAPI | Internal AI/RAG service API |
| Language | Python | Access to RAG, document, OCR, embedding, and evaluation ecosystem |
| Pipeline framework | ~~Haystack, used selectively~~ — **not adopted, superseded by ADR-016** | ~~Composable indexing and query pipelines~~ — replaced by an explicit stage runner in `app/rag/` |
| Background jobs | Celery | Parsing, OCR, crawling, embedding, deletion, and evaluation jobs |
| Data validation | Pydantic | Typed internal contracts |
| HTTP clients | Official provider SDKs or official documented HTTP clients | Direct provider integration |
| Testing | pytest | AI-service testing |

Python is preferred over Node.js for the AI service because the document-processing, OCR, embedding, reranking, evaluation, and RAG ecosystem is substantially stronger and more direct in Python.

~~Haystack should be used as an internal pipeline toolkit, not as the application's domain architecture. KnowledgeBot-owned interfaces and data models remain authoritative.~~

> **Not adopted — superseded by ADR-016.** The fence above is not buildable: Haystack's Qdrant retrievers default to `filter_policy=REPLACE`, so no configuration lets our tenant filter and a caller's facet filter both survive, and its fusion discards the per-branch ranks and scores stage 9 records. The line is kept rather than deleted because the decision only makes sense against it. Retrieval, fusion, parsing, crawling, embedding, reranking, evaluation and provider calls each already have an owner; the mapping and the verified evidence are in `.claude/skills/haystack-pipelines/SKILL.md`.

## 9.6 Relational Database

**PostgreSQL** is the primary relational database.

It will store:

- Accounts and tenant data.
- Bot configurations.
- Provider and model metadata.
- Source metadata and versions.
- Chunk metadata and processing states.
- Conversations and messages.
- Usage and analytics events.
- Evaluation datasets and results.
- Audit logs.
- Job summaries.

PostgreSQL is preferred because it is reliable, strongly transactional, mature, open source, and suitable for JSON metadata in addition to relational structures.

## 9.7 Vector Database

**Qdrant** is the dedicated vector database.

It will store:

- Dense embedding vectors.
- Sparse vectors or sparse-search representation.
- Chunk text when appropriate.
- Tenant, bot, source, version, language, document-type, and access payloads.
- Retrieval metadata.

Qdrant is selected because the project specifically aims to demonstrate advanced RAG capabilities such as:

- Dense retrieval.
- Sparse retrieval.
- Hybrid queries.
- Metadata filtering.
- Payload-based tenant isolation.
- Fusion.
- Reranking workflows.
- Collection aliases or versioned indexing strategies where useful.

PostgreSQL remains the source of truth. Qdrant is a derived search index and must be rebuildable.

## 9.8 Cache and Queue Broker

**Valkey** will be used for:

- Laravel queues.
- Celery broker or backend where compatible with the selected client configuration.
- Rate-limit counters.
- Short-lived chat session data.
- Distributed locks.
- Idempotency keys.
- Selected retrieval caches.
- Selected answer caches when explicitly enabled.

Different workloads must use separate logical databases, key prefixes, or separate instances in production-sensitive deployments.

## 9.9 Object Storage

**SeaweedFS with its S3-compatible interface** is the recommended default open-source object storage.

It will store:

- Original uploaded files.
- Crawl snapshots when enabled.
- Extracted images.
- Normalized document artifacts.
- Generated exports.
- Evaluation result files.
- Backup artifacts before transfer to secondary storage.

The application must use an S3-compatible storage abstraction so that SeaweedFS can be replaced with another S3-compatible system or a managed object store without changing business logic.

## 9.10 Document Processing

**Docling** is the primary document conversion and structure-extraction tool.

Use it for:

- PDF.
- DOCX and supported office documents.
- PPTX.
- XLSX.
- Images.
- HTML.
- Markdown.
- OCR-enabled document conversion.
- Structured document representation.

Additional utilities may be used for edge cases, but Docling should be the default entry point to avoid a fragmented parser stack.

For old binary Office formats, a controlled LibreOffice conversion worker may convert files into modern formats before Docling processing.

## 9.11 Website Crawling

**Crawl4AI** is the recommended crawler and page-to-Markdown processor.

It will be wrapped by KnowledgeBot-owned crawl policies for:

- URL validation.
- Sitemap handling.
- Include and exclude rules.
- JavaScript rendering policy.
- Concurrency.
- SSRF protection.
- Content-size limits.
- Change detection.
- Versioning.
- Scheduling.

## 9.12 Embeddings and Reranking

Recommended initial open-weight models:

- **BGE-M3** for multilingual dense embeddings and retrieval-oriented representations.
- **BGE reranker family**, with `bge-reranker-v2-m3` as an initial multilingual reranking option.

The exact model version must be pinned in deployment configuration and recorded against each indexed source version.

The architecture must allow future replacement of embedding and reranker models without rewriting the source-ingestion domain.

Changing the embedding model requires a controlled re-indexing process because vector dimensions and semantic behavior may change.

## 9.13 Reverse Proxy

**Traefik** is recommended for Docker-based routing.

Responsibilities:

- Expose only ports 80 and 443.
- Terminate TLS.
- Route requests to web, API, SDK, and internal dashboards that are intentionally exposed.
- Apply security headers.
- Apply request-size limits.
- Support automatic certificate management in public deployments.

Caddy is a valid simpler alternative. Only one reverse proxy should be selected for implementation.

## 9.14 Observability

Recommended stack:

- OpenTelemetry SDKs and Collector for traces, metrics, and logs correlation.
- Prometheus for metrics storage.
- Grafana for dashboards.
- Loki for centralized logs.
- Tempo or Jaeger for distributed traces.
- Optional Arize Phoenix for RAG-specific trace inspection and experiments.

The minimal first deployment may start with structured logs, OpenTelemetry traces, Prometheus, and Grafana.

## 9.15 RAG Evaluation

**Ragas** will be used for repeatable evaluation workflows.

Evaluation should include both automated metrics and human review.

The system should maintain its own evaluation dataset and experiment records so that results remain comparable over time.

## 9.16 CI/CD and Security Tooling

Recommended open-source tooling:

- GitHub Actions or a self-hosted CI alternative.
- Trivy for container and dependency vulnerability scanning.
- Syft for software bill of materials generation.
- Semgrep for static analysis.
- Gitleaks for secret scanning.
- Hadolint for Dockerfile checks.
- Renovate for dependency update proposals.

## 9.17 Open-Source Development Note

The application stack can be open source, but the external APIs from OpenAI, Anthropic, DeepSeek, NVIDIA, and OpenRouter are hosted commercial or third-party services.

For a strictly open-source development environment:

- Use Docker Engine and the Docker Compose plugin on Linux.
- Do not describe Docker Desktop as open-source.
- Keep the provider adapter architecture ready for a future self-hosted inference provider.


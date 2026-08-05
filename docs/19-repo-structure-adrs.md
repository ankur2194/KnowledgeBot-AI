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


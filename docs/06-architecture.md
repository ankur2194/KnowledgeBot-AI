# High-Level Architecture and Service Communication

> Part of the **KnowledgeBot AI** specification — §10–11, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 10. High-Level Architecture

KnowledgeBot AI consists of five major application surfaces and supporting infrastructure.

### 10.1 User-Facing Components

1. Next.js admin and hosted web application.
2. Preact embedded chat widget.
3. React Native mobile application.
4. Laravel public and admin API.
5. FastAPI internal AI/RAG service.

### 10.2 Data and Infrastructure Components

- PostgreSQL.
- Qdrant.
- Valkey.
- SeaweedFS.
- Celery workers.
- Laravel queue workers.
- Laravel scheduler.
- Traefik.
- Observability services.

### 10.3 Responsibility Separation

#### Laravel: Control Plane

Laravel is the authoritative control plane for business operations.

It decides:

- Who the user is.
- Which organization owns the request.
- Which bot may be used.
- Which provider configuration applies.
- Which sources belong to the bot.
- Whether the request is within limits.
- Which retention and privacy policy applies.
- Which operations are authorized.

#### FastAPI: AI Data Plane

FastAPI is the AI data plane.

It performs:

- Provider calls.
- Query transformation.
- Retrieval.
- Reranking.
- Context construction.
- Prompt construction.
- Document parsing.
- OCR.
- Crawling.
- Chunking.
- Embedding.
- Vector indexing.
- Evaluation execution.
- Source-index deletion.

#### PostgreSQL: Source of Truth

PostgreSQL stores authoritative state.

#### Qdrant: Rebuildable Retrieval Index

Qdrant stores the optimized retrieval representation.

#### SeaweedFS: Binary and Derived Artifacts

SeaweedFS stores file objects and processing artifacts.

#### Valkey: Ephemeral Coordination

Valkey handles queues, rate limits, locks, and short-lived cache data.

---

## 11. Service Communication

## 11.1 Browser and Mobile Communication

The Next.js application, embedded widget, and mobile app communicate with Laravel through HTTPS.

The clients must not call the FastAPI service directly.

Benefits:

- Centralized authentication.
- Centralized authorization.
- Consistent rate limiting.
- Provider credentials remain private.
- Internal topology remains hidden.
- Easier API versioning.

## 11.2 Laravel to FastAPI Communication

Laravel communicates with FastAPI over the private Docker network.

Communication types:

- Synchronous internal API calls for chat execution and diagnostics.
- Asynchronous job submission for ingestion, crawling, deletion, and evaluation.
- Signed internal requests or mutual service authentication.

Every request should carry:

- Trace identifier.
- Organization identifier.
- Bot identifier when applicable.
- Acting user identifier when applicable.
- Idempotency key for mutation requests.
- Requested operation.
- Configuration snapshot or configuration version.

## 11.3 Streaming

Server-Sent Events are recommended for chatbot response streaming.

Flow:

1. Client opens a streaming request to Laravel.
2. Laravel validates the request.
3. Laravel opens an internal streaming request to FastAPI.
4. FastAPI streams normalized provider events.
5. Laravel forwards approved events to the client.
6. Laravel and FastAPI finalize usage and telemetry when the stream ends.

SSE is preferred over WebSockets because chatbot generation is primarily server-to-client streaming after one request.

WebSockets may be added later for richer real-time collaboration but are not required for the initial release.

## 11.4 Internal Contracts

Internal contracts must be versioned.

Important contract groups:

- Chat request and streaming events.
- Provider configuration reference.
- Retrieval request and result.
- Ingestion command and progress events.
- Crawl command and page result.
- Deletion command and verification result.
- Evaluation command and metric result.

Contracts should be documented through OpenAPI where HTTP is used.


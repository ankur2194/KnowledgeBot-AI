# Testing Strategy and Performance Targets

> Part of the **KnowledgeBot AI** specification — §22–23, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 22. Testing Strategy

## 22.1 Unit Tests

Test:

- Domain rules.
- Tenant scopes.
- Provider parameter mapping.
- Error classification.
- Chunking helpers.
- URL validation.
- Deletion selection.
- Citation validation.
- Cost calculations.

## 22.2 Contract Tests

Test contracts between:

- Frontend and Laravel.
- Laravel and FastAPI.
- FastAPI and provider adapters.
- AI service and Qdrant.
- AI service and object storage.

Provider adapters should use recorded or mocked official responses for repeatability, with limited live smoke tests.

## 22.3 Integration Tests

Test:

- PostgreSQL transactions.
- Qdrant filters.
- Valkey queues.
- SeaweedFS upload and retrieval.
- Complete ingestion of sample documents.
- Source deletion.
- Crawl change detection.
- Streaming through Laravel.

## 22.4 End-to-End Tests

Critical scenarios:

- Create organization and bot.
- Configure provider.
- Upload PDF.
- Wait for ready state.
- Ask a question.
- Receive citation.
- Embed widget on approved origin.
- Reject widget on unapproved origin.
- Recrawl a changed page.
- Delete source and confirm it is no longer retrievable.

## 22.5 Security Tests

Test:

- Cross-tenant API access.
- Cross-tenant vector filters.
- CORS and origin enforcement.
- SSRF payloads.
- Malicious filenames.
- Oversized files.
- Prompt injection samples.
- XSS in source content.
- Secret redaction.
- Rate limits.

## 22.6 Performance Tests

Measure:

- Concurrent chat streams.
- First-token latency.
- Retrieval latency.
- Large PDF ingestion.
- Sitemap crawl throughput.
- Vector-search performance.
- Queue backlogs.
- Provider timeout behavior.

---

## 23. Performance Targets

These are project targets, not guaranteed external-provider service levels.

### Chat

- API validation before AI processing: typically below 250 ms under normal load.
- Retrieval and reranking: target below 1.5 seconds for a normal query on showcase-scale data.
- First visible token: target below 4 seconds when the provider responds normally.
- Streaming should begin as soon as the first provider text event is available.

### Admin

- Standard list and detail pages: target below 1 second server response for normal datasets.
- Long-running operations must be asynchronous.

### Ingestion

- Upload confirmation should be immediate after object storage completion.
- Parsing, OCR, and embedding should never block the HTTP request.
- Progress should update during long jobs.

### Availability

- A single-node showcase deployment is expected to have maintenance downtime.
- The architecture should support later horizontal scaling of stateless services and workers.


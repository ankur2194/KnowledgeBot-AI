# Observability

> Part of the **KnowledgeBot AI** specification — §20, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 20. Observability

## 20.1 Trace Coverage

A single trace should connect:

- Client request.
- Laravel validation.
- FastAPI request.
- Query rewriting.
- Dense retrieval.
- Sparse retrieval.
- Fusion.
- Reranking.
- Context building.
- Provider call.
- Streaming completion.
- Database writes.

## 20.2 Key Metrics

### Chat

- Requests per minute.
- Active streams.
- First-token latency.
- Total latency.
- Error rate.
- Cancellation rate.
- Insufficient-evidence rate.
- Fallback rate.

### Retrieval

- Dense search latency.
- Sparse search latency.
- Reranker latency.
- Candidate count.
- Selected evidence count.
- Empty retrieval rate.
- Average evidence score.

### Providers

- Request count.
- Success rate.
- Rate-limit rate.
- Timeout rate.
- Token usage.
- Estimated cost.
- Latency by model.

### Ingestion

- Queue depth.
- Processing duration by file type.
- Pages per minute.
- OCR usage.
- Embedding throughput.
- Failure rate.
- Retry count.
- Vector upsert latency.

### Crawling

- Pages discovered.
- Pages changed.
- Pages skipped.
- Pages missing.
- Crawl duration.
- HTTP error distribution.

### Infrastructure

- CPU.
- Memory.
- Disk.
- Database connections.
- Valkey memory.
- Qdrant collection size.
- Object storage usage.
- Worker concurrency.

## 20.3 Structured Logging

Logs should include:

- Timestamp.
- Severity.
- Service.
- Environment.
- Trace ID.
- Request ID.
- Organization ID where safe.
- Bot ID where safe.
- Job ID.
- Error class.
- Duration.

Logs must not include:

- Raw API keys.
- Passwords.
- Full authorization headers.
- Unredacted sensitive user content by default.

## 20.4 Health Checks

Each service needs:

- Liveness check.
- Readiness check.
- Dependency status.

Readiness should fail when a required dependency prevents useful service.


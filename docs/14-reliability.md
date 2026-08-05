# Reliability and Error Handling

> Part of the **KnowledgeBot AI** specification — §19, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 19. Reliability and Error Handling

## 19.1 Error Categories

The system should classify errors into:

- Validation error.
- Authentication error.
- Authorization error.
- Tenant quota error.
- Rate-limit error.
- Provider authentication error.
- Provider rate-limit error.
- Provider temporary error.
- Provider permanent request error.
- Retrieval error.
- Parsing error.
- OCR error.
- Crawl error.
- Vector indexing error.
- Storage error.
- Internal dependency error.
- User cancellation.

## 19.2 Retry Policy

Retries should use bounded exponential backoff with jitter for eligible transient errors.

Do not retry:

- Invalid credentials.
- Invalid input.
- Unsupported file.
- Authorization failure.
- Confirmed content-policy refusal.

## 19.3 Circuit Breakers

Provider and dependency adapters should support temporary circuit-breaking after repeated failures to avoid amplifying outages.

The admin dashboard should display degraded provider or dependency state.

## 19.4 Timeouts

Separate timeouts should exist for:

- Provider connection.
- Provider first token.
- Provider total response.
- Document parsing.
- OCR page.
- Web crawl request.
- Internal service call.
- Qdrant query.
- Embedding batch.

## 19.5 Idempotency

Idempotency is required for:

- File-ingestion requests.
- Crawl runs.
- Source deletion.
- Provider-usage finalization.
- Conversation message submission where client retries are possible.

## 19.6 Graceful Degradation

Examples:

- If analytics aggregation fails, chat should continue.
- If optional tracing fails, chat should continue.
- If reranking is unavailable, the bot may use fused retrieval only when the bot policy permits it and the event is recorded.
- If the primary model is unavailable, configured fallback may run.
- If no evidence is found, the bot should refuse rather than invent.


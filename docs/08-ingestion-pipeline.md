# Ingestion Pipeline

> Part of the **KnowledgeBot AI** specification — §13, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 13. Ingestion Pipeline

## 13.1 Ingestion Objectives

The ingestion pipeline must be:

- Asynchronous.
- Idempotent.
- Versioned.
- Observable.
- Restartable.
- Secure.
- Able to publish atomically.
- Able to remove derived knowledge.

## 13.2 Ingestion Stages

1. Source creation.
2. File or URL acquisition.
3. Validation.
4. Hashing.
5. Object storage.
6. Parsing or crawling.
7. OCR when required.
8. Structural normalization.
9. Content cleanup.
10. Metadata enrichment.
11. Chunking.
12. Embedding.
13. Sparse representation creation.
14. Vector upsert.
15. Verification.
16. Version activation.
17. Old-version retirement.
18. Cache invalidation.

## 13.3 Idempotency

Each ingestion request should have an idempotency key.

Duplicate processing should be prevented by considering:

- Organization.
- Source.
- Source version.
- Content hash.
- Parser configuration version.
- Chunking configuration version.
- Embedding model version.

Reprocessing is required when content is unchanged but processing configuration changes.

## 13.4 Source Versioning

A new source version is created when:

- Uploaded content changes.
- A crawled page changes.
- Parser configuration changes.
- OCR configuration changes.
- Chunking strategy changes.
- Embedding model changes.
- An administrator explicitly requests reprocessing.

Only one version is active for a given source item at a time.

## 13.5 Atomic Publication

New vectors should not become partially visible during processing.

Recommended behavior:

- Index all chunks with the new version identifier.
- Verify expected chunk counts.
- Mark the version ready in PostgreSQL.
- Atomically switch the active version metadata.
- Retire and later delete the prior vector version.

## 13.6 Processing Progress

The admin interface should display:

- Current stage.
- Percentage where meaningful.
- Items completed.
- Items remaining.
- Start time.
- Elapsed time.
- Worker identifier.
- Warning count.
- Error summary.
- Retry count.

## 13.7 Failure Recovery

Jobs should be retryable at safe boundaries.

Examples:

- A transient provider embedding failure should retry embedding without reparsing the document.
- A Qdrant failure should retry indexing using stored normalized chunks.
- An OCR failure on one page should allow a configurable partial-success result.
- A permanent unsupported-file error should not retry indefinitely.

Failed jobs must go to a reviewable failed-job state.


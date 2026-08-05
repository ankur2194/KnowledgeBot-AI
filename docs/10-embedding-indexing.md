# Embedding and Indexing

> Part of the **KnowledgeBot AI** specification — §15, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 15. Embedding and Indexing

## 15.1 Embedding Service

The AI service will provide an internal embedding component independent of the generation-provider adapters.

It must support:

- Batch embedding.
- Configurable batch size.
- Retry.
- Device selection.
- Model version reporting.
- Dimension validation.
- Language-aware testing.
- Throughput metrics.

## 15.2 Local Versus Hosted Embeddings

The recommended showcase configuration uses an open-weight embedding model hosted within the KnowledgeBot infrastructure.

Benefits:

- Demonstrates self-hosted AI processing.
- Avoids sending all knowledge text to an external embedding API.
- Provides reproducible embeddings.
- Reduces provider lock-in.

A hosted embedding provider may be added through an embedding adapter later.

## 15.3 Index Collections

A practical initial design is one Qdrant collection per embedding configuration or environment, with tenant and bot isolation through payload filters.

Avoid creating one collection for every small bot unless operational testing proves it necessary.

## 15.4 Re-Embedding

A re-embedding operation must:

- Create a new index version.
- Process sources in the background.
- Keep the current index active.
- Verify the new index.
- Switch bot retrieval configuration.
- Remove the old vectors after a safe period.

## 15.5 Index Rebuild

Because PostgreSQL and object storage are authoritative, the complete Qdrant index should be rebuildable.

The system should provide an administrator operation to:

- Rebuild one source.
- Rebuild one bot.
- Rebuild one organization.
- Rebuild an entire collection.


# API Areas

> Part of the **KnowledgeBot AI** specification — §17, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 17. API Areas

The API should be versioned from the beginning.

## 17.1 Admin API

Areas:

- Authentication.
- Organizations.
- Users and invitations.
- Roles and permissions.
- Provider connections.
- Provider models.
- Bots.
- Bot appearance and domains.
- Knowledge sources.
- Uploads.
- Crawling.
- Processing jobs.
- Conversations.
- Analytics.
- Evaluations.
- Audit logs.

## 17.2 Public Chat Runtime API

Areas:

- Bot public configuration.
- Chat session creation.
- Conversation creation.
- Message submission.
- SSE response stream.
- Conversation history where permitted.
- Feedback submission.
- Citation detail.

## 17.3 SDK API

The SDK uses the public runtime API but may have dedicated endpoints for:

- Origin-validated bootstrap configuration.
- Short-lived widget session tokens.
- Signed user metadata validation.

## 17.4 Mobile API

The mobile app uses the same versioned public runtime and authenticated user APIs.

## 17.5 Internal AI API

Areas:

- Chat execution.
- Provider connection test.
- Ingestion submission.
- Ingestion status callback.
- Crawl submission.
- Deletion submission.
- Retrieval diagnostics.
- Evaluation execution.
- Health and readiness.

The internal API must not be exposed publicly.


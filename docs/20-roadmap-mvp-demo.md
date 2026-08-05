# MVP, Post-MVP, Delivery Phases, Acceptance, Demo

> Part of the **KnowledgeBot AI** specification — §29–33, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 29. MVP Feature Set

The MVP should be complete enough to demonstrate the full lifecycle.

### Required

- Multi-tenant organizations and roles.
- Bot creation and configuration.
- Direct OpenAI, Anthropic, DeepSeek, NVIDIA NIM, and OpenRouter adapters.
- Provider connection tests.
- Fixed model selection.
- PDF, DOCX, XLSX, PPTX, Markdown, text, HTML, and image ingestion.
- OCR for scanned documents.
- Single URL, URL list, and sitemap crawling.
- Manual and scheduled recrawl.
- Change detection.
- Source versioning.
- Source disable and deletion.
- PostgreSQL, Qdrant, Valkey, and S3-compatible object storage.
- Hybrid retrieval.
- Reranking.
- Citations.
- Strict insufficient-evidence behavior.
- Admin playground with retrieval debugging.
- Hosted chat.
- Embeddable iframe widget.
- React Native mobile app with basic chat.
- Conversation history and feedback.
- Usage and latency reporting.
- Docker Compose environment.
- Automated tests.
- Basic observability.
- Evaluation dataset and experiment runner.

### Strongly Recommended

- Ordered provider fallback.
- Source authority priority.
- Prompt versioning.
- Retrieval configuration versioning.
- Audit logs.
- Signed widget user metadata.
- Deletion verification.
- CI security scanning.

---

## 30. Post-MVP Features

- Local self-hosted LLM inference adapter.
- Local multimodal image understanding.
- Visual document retrieval.
- Advanced parent-child retrieval.
- Multi-query and decomposition strategies.
- Agent tools with explicit permissions.
- Google Drive, SharePoint, Notion, Confluence, and Git connectors.
- Enterprise SSO.
- SCIM.
- White-label mobile builds.
- Human handoff.
- Voice input and output.
- Advanced PII detection and redaction.
- Semantic answer cache.
- Automatic source quality scoring.
- Answer claim-to-citation verification.
- A/B testing of prompts and models.
- Billing and subscriptions.
- Organization-specific custom domains.
- Regional data-residency controls.
- High-availability deployment.

---

## 31. Delivery Phases

## Phase 0: Foundation and Decisions

Deliverables:

- Final product scope.
- ADRs.
- Monorepo.
- Docker networks and base services.
- CI baseline.
- Shared API conventions.
- Authentication and organization model.

## Phase 1: Core SaaS and Providers

Deliverables:

- Users, organizations, and roles.
- Bot management.
- Provider connection management.
- Official provider adapters.
- Provider test console.
- Basic hosted chat without RAG.
- Streaming.
- Usage tracking.

## Phase 2: Document Ingestion

Deliverables:

- Upload flow.
- SeaweedFS integration.
- Docling processing.
- OCR.
- Source versions.
- Chunking.
- Local embeddings.
- Qdrant indexing.
- Source preview.

## Phase 3: RAG Query Pipeline

Deliverables:

- Dense retrieval.
- Sparse retrieval.
- Fusion.
- Reranking.
- Context packing.
- Grounded prompt.
- Citations.
- Insufficient-evidence behavior.
- Retrieval playground.

## Phase 4: Website Crawling and Synchronization

Deliverables:

- Single URL.
- URL lists.
- Sitemap discovery.
- Crawl policies.
- Change detection.
- Scheduled recrawl.
- Missing-page behavior.
- SSRF controls.

## Phase 5: Publishing Channels

Deliverables:

- Hosted chat polish.
- Widget loader.
- Iframe chat.
- Domain allow-list.
- Short-lived sessions.
- Mobile app.

## Phase 6: Quality and Operations

Deliverables:

- Evaluation datasets.
- Ragas integration.
- Observability.
- Security tests.
- Load tests.
- Backups.
- Restore guide.
- Deployment documentation.

## Phase 7: Portfolio Presentation

Deliverables:

- Public demo.
- Architecture diagrams.
- Demo knowledge set.
- Recorded walkthrough.
- Case study.
- Screenshots.
- Technical README.
- Trade-off explanation.

---

## 32. Acceptance Criteria

The project is considered portfolio-complete when all of the following are demonstrated:

1. An administrator can create an organization and bot.
2. An administrator can add valid credentials for at least three provider types and test them.
3. A bot can switch between configured official provider integrations.
4. A PDF with text and a scanned PDF can be processed.
5. A Word document, spreadsheet, presentation, Markdown file, and image can be processed.
6. A sitemap can be crawled.
7. A changed web page creates a new active version without partial availability.
8. An unchanged page is skipped.
9. A source can be disabled and immediately excluded from retrieval.
10. A source can be deleted and its vectors are verified as removed.
11. Dense and sparse retrieval results are visible in diagnostics.
12. Reranking results are visible.
13. Answers contain working citations.
14. The bot refuses when evidence is insufficient.
15. The hosted chat streams responses.
16. The widget works on an approved domain and fails on an unapproved origin.
17. The mobile app can chat and open citations.
18. Cross-tenant access tests pass.
19. A repeatable RAG evaluation run produces stored results.
20. The complete environment starts through documented Docker Compose commands.
21. Only approved public ports are exposed in production configuration.
22. Backup and restore procedures are documented and tested at least once.

---

## 33. Demo Scenario

A strong demonstration should use a fictional or approved company knowledge set containing:

- Product guide PDF.
- Scanned warranty document.
- Pricing spreadsheet.
- Sales presentation.
- Support FAQ in Markdown.
- Public documentation website with a sitemap.
- Product screenshots containing visible text.

Demo sequence:

1. Show an empty new bot.
2. Add provider credentials.
3. Upload multiple formats.
4. Add the documentation sitemap.
5. Show processing progress.
6. Inspect parsed content and chunks.
7. Ask an exact product-code question to demonstrate sparse retrieval.
8. Ask a conceptual question to demonstrate dense retrieval.
9. Ask a question requiring two sources.
10. Open citations to a PDF page and spreadsheet sheet.
11. Ask an unanswerable question and show refusal.
12. Modify a web page and run recrawl.
13. Show that only changed content is reprocessed.
14. Delete the warranty source.
15. Ask the warranty question again and show that the deleted knowledge is not used.
16. Switch the same bot between OpenAI, Claude, DeepSeek, NVIDIA NIM, and OpenRouter models.
17. Show provider-specific usage and latency.
18. Open the same bot through the embedded widget and mobile app.
19. Run an evaluation comparison between two retrieval configurations.
20. Show traces and operational dashboards.


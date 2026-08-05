# KnowledgeBot AI

> **Multi-Model RAG Chatbot Platform**

**Document type:** Product Requirements Document, System Architecture Specification, Technical Design, Delivery Plan, and Operational Guide  
**Project status:** Planned  
**Primary purpose:** Portfolio and skills showcase with production-oriented architecture  
**Architecture style:** Modular monolith for business capabilities plus a focused AI/RAG service  
**Deployment model:** Docker-first, self-hostable, open-source application stack  
**LLM integration decision:** Direct integration with each provider's official API or official SDK; no LiteLLM Gateway  
**Last updated:** 4 August 2026

---

## Table of Contents

1. Product purpose, vision, goals, users, and scope
2. User journeys and complete functional requirements
3. Recommended open-source technology stack
4. High-level architecture and service communication
5. RAG query, ingestion, chunking, embedding, and indexing pipelines
6. Relational and vector data models
7. Public, admin, SDK, mobile, and internal API areas
8. Security, privacy, reliability, and error handling
9. Observability, evaluation, testing, and performance targets
10. Docker deployment, scaling, backup, and CI/CD
11. Repository structure and architecture decisions
12. MVP, roadmap, phases, acceptance criteria, and demo plan
13. Risks, licensing, documentation, glossary, and official references

---

## 1. Document Purpose

This document is the complete project definition for **KnowledgeBot AI — Multi-Model RAG Chatbot Platform**.

A reader should be able to understand the following without needing a separate product or architecture document:

- What KnowledgeBot AI is and why it exists.
- Who will use it.
- Which problems it solves.
- What is included in the first release and what is intentionally excluded.
- How the web application, admin panel, embedded chat SDK, mobile application, backend, AI service, databases, queues, storage, crawling, retrieval, and LLM providers work together.
- How documents, websites, PDFs, images, spreadsheets, presentations, and Markdown files become searchable knowledge.
- How content updates and deletions are reflected in the RAG knowledge base.
- How multi-model LLM integrations are designed without a third-party LLM gateway.
- How the project will be secured, tested, deployed, monitored, backed up, and maintained.
- Which features demonstrate RAG engineering and software architecture skills.
- How the project can remain minimal enough to complete while still appearing production-ready.

This is a design and planning document. It does not contain implementation code.

---

## 2. Product Summary

KnowledgeBot AI is a self-hostable platform for creating AI chatbots that answer questions using approved business knowledge.

Administrators create a bot, connect one or more knowledge sources, configure an LLM provider and model, and publish the bot through:

1. A hosted web chat page.
2. An embeddable website chat widget.
3. Android and iOS applications.
4. A documented API for approved integrations.

Knowledge sources may include:

- Text-based PDFs.
- Scanned PDFs.
- PDFs containing images, diagrams, and tables.
- Individual images.
- Microsoft Word documents.
- Microsoft Excel workbooks.
- Microsoft PowerPoint presentations.
- Markdown and plain-text files.
- HTML files.
- Web pages supplied as individual URLs.
- Websites discovered through XML sitemaps.
- Lists of approved URLs.

The platform converts these sources into structured content, divides the content into retrievable chunks, creates dense and sparse search representations, stores the vectors and metadata, retrieves the best evidence for each question, reranks that evidence, and asks the configured LLM to produce a grounded answer with citations.

KnowledgeBot AI is not merely a generic chatbot. Its main purpose is to demonstrate:

- Multi-tenant SaaS architecture.
- Clean separation between business logic and AI workloads.
- Direct, provider-specific LLM API integration.
- Advanced document ingestion.
- Hybrid retrieval.
- Reranking.
- Source citations.
- Content versioning and deletion.
- Periodic website synchronization.
- Background processing.
- Secure embeddable SDK design.
- Observability and RAG evaluation.
- Docker-based deployment.

---

## 3. Product Vision

### 3.1 Vision Statement

Enable an organization to turn its controlled documents and websites into an accurate, source-grounded AI assistant that can use different LLM providers without becoming locked into a single model vendor.

### 3.2 Positioning

KnowledgeBot AI is positioned as a **technical showcase and production-oriented reference platform**, not as an attempt to compete immediately with large commercial chatbot SaaS products.

It should look and behave like a focused, well-architected product that could be extended into a commercial SaaS platform.

### 3.3 Core Product Promise

> Add approved knowledge, choose an AI model, publish the bot, and receive answers that show where the information came from.

### 3.4 Key Differentiators

- Direct official API integration for each LLM provider.
- Multi-model configuration per bot.
- Open-source application and infrastructure stack.
- Hybrid dense and sparse retrieval.
- Reranked evidence before generation.
- Source-aware answers with page, slide, sheet, section, or URL citations.
- Structured handling of difficult documents.
- Periodic website recrawling and change detection.
- Reliable source removal and knowledge deletion.
- Web, embedded, mobile, and API-based access.
- Retrieval debugging and evaluation tools for administrators.

---

## 4. Goals

### 4.1 Primary Goals

1. Build a complete RAG chatbot platform that can be demonstrated publicly.
2. Showcase full-stack, backend, AI, DevOps, security, and architecture skills.
3. Support direct integrations with multiple commercial LLM providers.
4. Support accurate ingestion of common business document formats.
5. Produce answers grounded only in the knowledge available to the selected bot.
6. Provide visible and verifiable citations.
7. Support periodic website content synchronization.
8. Support complete removal of source knowledge.
9. Keep the initial architecture understandable and maintainable.
10. Run the complete platform through Docker-based development and deployment.

### 4.2 Secondary Goals

- Allow multiple organizations and users.
- Allow one organization to create multiple bots.
- Allow each bot to have different providers, models, prompts, knowledge, appearance, and retrieval settings.
- Track provider usage, latency, errors, token usage, and estimated cost.
- Provide a reusable web SDK and mobile application.
- Provide evaluation datasets and repeatable RAG quality measurements.
- Allow future support for local models and self-hosted inference.

### 4.3 Portfolio Goals

The finished project should clearly demonstrate the following during a technical interview or client presentation:

- The reason for using Laravel and Python as separate bounded services.
- The reason for choosing direct official LLM APIs rather than a gateway.
- The provider adapter architecture.
- The full ingestion lifecycle.
- The source and version identity model.
- The hybrid retrieval and reranking strategy.
- The difference between relational metadata and vector search data.
- Cross-tenant security controls.
- Asynchronous background processing.
- How website updates and deletions are synchronized.
- How hallucination risk is reduced.
- How RAG quality is tested rather than judged informally.
- How the system is operated using containers, health checks, logs, metrics, and backups.

---

## 5. Non-Goals for the Initial Release

The following are intentionally excluded from the first complete portfolio release:

- Training or fine-tuning foundation models.
- Building a general-purpose autonomous agent platform.
- Arbitrary code execution by the chatbot.
- A marketplace for third-party tools.
- Full enterprise SSO and SCIM provisioning.
- Complex billing and tax automation.
- Kubernetes deployment.
- Multi-region active-active deployment.
- Voice calling or real-time speech conversations.
- Video understanding.
- Live human-agent contact-center routing.
- Unlimited custom workflow automation.
- Crawling private systems that require complex browser authentication.
- Replacing a document management system.
- Guaranteeing factual correctness beyond the supplied sources.
- Supporting every possible legacy or proprietary file format.

These may be added later, but they should not delay the core RAG platform.

---

## 6. Target Users and Roles

### 6.1 Platform Owner

The platform owner manages the entire KnowledgeBot installation.

Responsibilities:

- Manage organizations.
- View system health.
- Configure global policies.
- Manage feature flags.
- Review provider failures and system-wide usage.
- Control limits for storage, bots, users, and ingestion.
- Access platform-level audit logs.

### 6.2 Organization Owner

The organization owner controls one tenant.

Responsibilities:

- Manage organization settings.
- Invite and remove users.
- Configure provider API credentials.
- Create and publish bots.
- Manage knowledge sources.
- Review conversations and analytics.
- Configure retention and privacy options.

### 6.3 Organization Administrator

An administrator manages operational settings but may not own billing or destructive organization-level actions.

Responsibilities:

- Manage bots.
- Manage sources.
- Configure prompts and models.
- Start ingestion and recrawl jobs.
- Review failures.
- Review conversations and evaluations.

### 6.4 Knowledge Manager

A knowledge manager maintains the content used by bots.

Responsibilities:

- Upload files.
- Add websites and sitemaps.
- Review parsed content.
- Trigger reprocessing.
- Disable, archive, or delete sources.
- View source freshness.

### 6.5 Analyst or Reviewer

A reviewer inspects chatbot quality.

Responsibilities:

- Review conversations.
- Add feedback.
- Create evaluation questions.
- Compare retrieval or model configurations.
- Review source citations and retrieval traces.

### 6.6 End User

An end user chats with a published bot through the hosted chat, embedded widget, mobile application, or API.

The end user may be anonymous or authenticated depending on the bot configuration.

---

## 7. Main User Journeys

### 7.1 Create and Publish a Bot

1. An administrator creates a bot.
2. The administrator enters the bot name, description, welcome message, and system instructions.
3. The administrator chooses the provider and model.
4. The administrator selects retrieval defaults.
5. The administrator adds knowledge sources.
6. The platform processes the sources.
7. The administrator tests questions in a private playground.
8. The administrator reviews citations and retrieved chunks.
9. The administrator configures appearance and allowed domains.
10. The administrator publishes the bot.
11. The platform provides hosted-chat and embed instructions.

### 7.2 Upload a Document

1. A knowledge manager uploads a file.
2. The file is validated and stored in object storage.
3. A content hash is calculated.
4. A source and source version are created.
5. A background ingestion job is started.
6. The file is parsed, including OCR when required.
7. Structured elements are extracted.
8. The content is cleaned and normalized.
9. Chunks are created using document structure.
10. Dense embeddings and sparse representations are generated.
11. Chunks and vectors are written to Qdrant.
12. The source version becomes active.
13. The administrator can inspect pages, sections, chunks, and warnings.

### 7.3 Add a Website from a Sitemap

1. An administrator enters a sitemap URL.
2. The platform validates the URL and crawl policy.
3. Sitemap indexes and child sitemaps are discovered.
4. URLs are filtered using include and exclude rules.
5. Approved pages are crawled in the background.
6. Main content is converted into structured Markdown.
7. Each page receives its own source item identity and version.
8. Changed pages are embedded and published.
9. Unchanged pages are skipped.
10. Removed sitemap pages are marked missing and handled according to the configured deletion policy.
11. Future crawls run on the selected schedule.

### 7.4 Ask a Question

1. The end user sends a question.
2. The request is authenticated or assigned an anonymous session.
3. The platform checks bot status, origin, rate limit, and quota.
4. Conversation history is condensed when necessary.
5. The question is normalized and optionally rewritten for retrieval.
6. Dense and sparse retrieval run with tenant and bot filters.
7. Results are fused and reranked.
8. The context builder selects the best evidence within a context budget.
9. The configured provider adapter calls the official LLM API.
10. The response streams to the client.
11. Citations are attached to answer statements.
12. Usage, retrieval details, latency, and errors are stored.
13. The end user may rate the answer.

### 7.5 Remove Knowledge

1. An administrator deletes or disables a source.
2. The source becomes immediately unavailable to new retrieval queries.
3. A background deletion job removes related vectors and derived artifacts.
4. Cached retrieval and answer entries are invalidated.
5. The system verifies that no active vector points remain for the source.
6. The action is recorded in the audit log.
7. The original file is deleted or retained according to the configured retention policy.

---

## 8. Functional Scope

## 8.1 Authentication and Accounts

The platform will support:

- Email and password authentication.
- Email verification.
- Password reset.
- Secure session authentication for the web application.
- Personal access tokens for approved API use.
- Organization invitations.
- User activation and suspension.
- Role-based access control.
- Optional two-factor authentication as a post-MVP security enhancement.

Laravel will own authentication, sessions, users, organizations, roles, and permissions.

## 8.2 Multi-Tenancy

The platform will use shared application services with strict tenant isolation.

Every tenant-owned relational record must contain an organization identifier either directly or through an enforced parent relationship.

Every Qdrant point must include tenant and bot identifiers in its payload.

Every storage object must be stored under a tenant-aware path or bucket prefix.

Every cache and queue key must use tenant-safe namespacing where tenant data is involved.

Cross-tenant access must be prevented at:

- HTTP authorization.
- Query scopes.
- Service-layer policies.
- Vector search filters.
- Object-storage paths.
- Analytics queries.
- Logs and exports.

## 8.3 Bot Management

Each bot will have:

- Name.
- Slug.
- Description.
- Status: draft, testing, published, paused, or archived.
- Public or private access mode.
- Welcome message.
- Placeholder text.
- Suggested starter questions.
- System instruction.
- Answer-style instruction.
- Provider and model selection.
- Optional fallback model chain.
- Embedding model configuration.
- Reranker configuration.
- Retrieval configuration.
- Citation configuration.
- Refusal and insufficient-evidence behavior.
- Knowledge-source assignments.
- Appearance configuration.
- Allowed website origins.
- Rate limits.
- Conversation retention settings.
- Data collection and consent text.

A bot may use multiple knowledge sources. A source may be assigned to one or more bots in the same organization.

## 8.4 Provider and Model Management

The admin panel will support direct configuration for:

- OpenAI.
- Anthropic Claude.
- DeepSeek.
- NVIDIA NIM API.
- OpenRouter.
- Future providers through the same adapter contract.

Each provider connection will contain:

- Provider type.
- Display name.
- API base URL when the provider officially supports configuration.
- Encrypted API key or token.
- Optional organization, project, or account identifier.
- Timeout settings.
- Retry policy.
- Enabled or disabled state.
- Last successful connection test.
- Last failure summary.
- Usage limits defined by the organization.

Each model record will contain:

- Provider connection.
- Official model identifier.
- Display name.
- Model family.
- Supported input types.
- Text generation capability.
- Image input capability.
- Tool-use capability.
- Structured-output capability.
- Reasoning capability.
- Context-window information entered from provider documentation.
- Maximum configured output.
- Pricing metadata used only for estimated reporting.
- Enabled or disabled state.
- Recommended use category.

The system must not assume that all providers support identical parameters. Provider-specific capabilities must be explicit.

## 8.5 Direct Official API Integration

KnowledgeBot AI will not use LiteLLM Gateway.

Instead, the AI service will implement an internal provider abstraction with separate provider adapters.

The common internal request will describe the desired behavior, while each provider adapter will translate that request into the provider's official format.

The shared internal request may contain:

- Model identifier.
- System instruction.
- Conversation messages.
- Retrieved context.
- Maximum output.
- Temperature when supported.
- Reasoning setting when supported.
- Structured response schema when supported.
- Tool definitions when enabled in future.
- Image inputs when supported.
- Streaming requirement.
- Metadata and trace identifiers.

The common internal response will normalize only information required by KnowledgeBot:

- Text deltas.
- Completed text.
- Stop reason.
- Input tokens.
- Output tokens.
- Cached tokens when reported.
- Reasoning tokens when reported.
- Provider request identifier.
- Provider latency.
- Error classification.
- Safety or refusal status.

Provider-specific details should also be preserved in a restricted diagnostic payload so that useful provider features are not lost through over-normalization.

## 8.6 Provider Adapter Responsibilities

Every provider adapter must handle:

- Authentication.
- Request translation.
- Official endpoint selection.
- Streaming event translation.
- Timeout handling.
- Retryable versus non-retryable error classification.
- Rate-limit information.
- Token-usage extraction.
- Stop-reason mapping.
- Provider request IDs.
- Model-specific parameter validation.
- Safety or refusal responses.
- Provider-specific caching options when supported.
- Provider-specific reasoning options when supported.
- Provider-specific structured output when supported.

The adapter layer must not silently discard a provider feature. Unsupported options must be rejected or clearly ignored with a diagnostic warning.

## 8.7 Provider Routing and Fallback

The first release should keep routing understandable.

Supported modes:

1. **Fixed model:** Every request uses the selected model.
2. **Manual model selection:** An authorized tester chooses a model in the playground.
3. **Ordered fallback:** A secondary model is used only when the primary provider returns an eligible operational failure.

Fallback should be allowed for:

- Provider outage.
- Connection timeout.
- Temporary server error.
- Rate-limit response when configured.
- Model temporarily unavailable.

Fallback should not automatically occur for:

- Authentication failure.
- Invalid request.
- Content-policy refusal.
- Tenant quota exceeded.
- Context too large due to an application bug.
- User cancellation.

Every fallback event must be visible in telemetry and conversation diagnostics.

## 8.8 Knowledge Source Types

Supported source categories:

- Uploaded document.
- Uploaded image.
- Single web page.
- URL list.
- Sitemap.
- Manual text entry.
- Markdown entry.

Future source categories:

- Google Drive.
- SharePoint.
- Notion.
- Confluence.
- Git repositories.
- Helpdesk systems.
- Database records.

The future categories should use a connector contract but are not part of the initial implementation.

## 8.9 Source Lifecycle

A source will have one of these states:

- Draft.
- Queued.
- Fetching.
- Parsing.
- Normalizing.
- Chunking.
- Embedding.
- Indexing.
- Ready.
- Ready with warnings.
- Failed.
- Disabled.
- Deleting.
- Deleted.
- Archived.

The currently active source version remains searchable until a new version is fully indexed and activated. This prevents incomplete knowledge during reprocessing.

## 8.10 File Upload Requirements

The upload system will support:

- Drag-and-drop upload.
- File browser selection.
- Multiple files per batch.
- Configurable maximum file size.
- Configurable per-organization storage limits.
- MIME detection independent of filename extension.
- Duplicate detection using content hashes.
- Progress display.
- Cancellation before processing begins.
- Clear status and error reporting.
- Reprocessing with changed parser settings.

Security checks will include:

- Extension allow-list.
- MIME allow-list.
- File-size limit.
- Archive expansion limit.
- Decompression-bomb protection.
- Malware scanning integration point.
- Filename normalization.
- Path traversal prevention.
- Parser timeout.
- Maximum pages, sheets, and slides limits.

## 8.11 Supported Document Handling

### PDF

The platform should extract:

- Embedded text.
- Page boundaries.
- Headings and paragraphs when detectable.
- Lists.
- Tables.
- Captions.
- Images and their locations.
- Document metadata.
- Page numbers for citations.

Scanned or image-based pages should use OCR.

The parser should record warnings for:

- Pages with little or no readable content.
- Low OCR confidence.
- Unsupported encryption.
- Password protection.
- Corrupted pages.
- Very large images.
- Unrecognized tables.

### Word Documents

The platform should preserve:

- Heading hierarchy.
- Paragraph order.
- Lists.
- Tables.
- Hyperlinks.
- Headers and footers when useful.
- Footnotes when supported.
- Section boundaries.

### Excel Workbooks

The platform should treat each sheet as a structured unit.

It should extract:

- Workbook name.
- Sheet name.
- Used cell ranges.
- Table headers.
- Cell values.
- Displayed formula results when available.
- Named tables when available.
- Repeated header relationships.

It should avoid converting a large sheet into an unstructured wall of text.

Chunks should identify:

- Sheet name.
- Table or region name.
- Row range.
- Column headers.

### PowerPoint Presentations

The platform should extract:

- Slide title.
- Slide body text.
- Speaker notes when enabled.
- Tables.
- Image references.
- Slide number.

Each citation should identify the slide number.

### Markdown, HTML, and Plain Text

The platform should preserve:

- Heading hierarchy.
- Lists.
- Tables.
- Code sections as text when allowed.
- Links.
- Section boundaries.

### Images

Image processing should support:

- OCR.
- Basic metadata.
- Optional image description through a configured multimodal model.
- Association with a parent PDF page, document, slide, or standalone source.

The first release should use OCR and optional visual description. Dedicated visual vector retrieval may be added later.

## 8.12 Website Crawling

The crawler will support:

- A single URL.
- A manually supplied list of URLs.
- XML sitemap URL.
- Sitemap index files.
- Multiple nested sitemaps.
- Same-domain deep crawling with a configured depth.
- Include patterns.
- Exclude patterns.
- Maximum page count.
- Crawl delay.
- Request timeout.
- Per-domain concurrency limit.
- User-agent configuration.
- JavaScript rendering for approved pages.
- Main-content extraction.
- HTML-to-Markdown normalization.
- Canonical URL handling.
- Redirect handling.
- Duplicate content detection.

The crawler must respect platform security and administrator policy. Robots.txt behavior should be configurable but should default to respecting it.

## 8.13 Crawl Security

The crawler is an SSRF-sensitive component and must be isolated.

It must reject or restrict:

- Loopback addresses.
- Private network ranges unless explicitly approved for a controlled installation.
- Link-local addresses.
- Cloud metadata endpoints.
- Non-HTTP protocols.
- Excessive redirects.
- DNS rebinding behavior.
- URLs containing embedded credentials.
- Extremely large responses.
- Unsupported content types.

Network egress rules should restrict the crawler container where practical.

## 8.14 Periodic Recrawling

Each crawl source may have:

- Manual-only mode.
- Daily schedule.
- Weekly schedule.
- Monthly schedule.
- Custom cron-like schedule controlled by administrators.

A periodic crawl will:

1. Re-read the sitemap or URL list.
2. Compare discovered URLs with known URLs.
3. Check ETag and Last-Modified headers when available.
4. Fetch content when required.
5. Calculate a normalized-content hash.
6. Skip unchanged pages.
7. Create a new page version for changed content.
8. Add newly discovered pages.
9. Mark missing pages according to policy.
10. Publish the new version only after successful indexing.

Missing-page policies:

- Keep previously indexed content and report it as missing.
- Disable after one missing crawl.
- Disable after a configurable number of consecutive missing crawls.
- Delete automatically after a retention period.

The default should be conservative: disable after repeated confirmation rather than deleting immediately because of one temporary sitemap or server failure.

## 8.15 Manual Text Knowledge

Administrators may create small knowledge entries directly in the admin panel.

Examples:

- Business hours.
- Support policy.
- Product disclaimers.
- Temporary announcements.

Manual entries will support title, body, tags, effective date, expiry date, bot assignments, and version history.

## 8.16 Source Preview and Inspection

For every source, administrators should be able to view:

- Original filename or URL.
- File type.
- Content hash.
- Source status.
- Current active version.
- Last successful processing time.
- Last crawl time.
- Next scheduled crawl.
- Number of pages, slides, sheets, sections, and chunks.
- Parser and OCR warnings.
- Extracted normalized content.
- Chunk boundaries.
- Vector indexing status.
- Assigned bots.
- Deletion state.

## 8.17 Source Deletion and Knowledge Removal

Knowledge removal is a first-class requirement.

### Immediate Logical Removal

When a source is disabled or deletion begins, new retrieval requests must exclude it immediately using source-status filters and source-version activation rules.

### Physical Removal

The deletion workflow will remove:

- Dense vectors.
- Sparse vectors.
- Chunk payloads.
- Derived normalized documents.
- Extracted images when no longer referenced.
- OCR outputs.
- Cached retrieval results.
- Cached answers tied to the source version.
- Original uploaded objects according to retention settings.

### Cascading Identity

Every vector point must be traceable to:

- Organization.
- Bot assignment.
- Source.
- Source item.
- Source version.
- Document element.
- Chunk.

Deletion queries must use stable identifiers, not text matching.

### Verification

After deletion, a verification job will confirm:

- No active chunks remain in PostgreSQL.
- No matching vector points remain in Qdrant.
- No active source assignment remains.
- No retrievable cache entry remains.

An administrator should be able to see deletion completion and any remaining retention obligations.

## 8.18 Chat Experience

The chat experience will include:

- Bot identity and avatar.
- Welcome message.
- Suggested questions.
- Message composer.
- Streaming responses.
- Stop-generation action.
- Retry action.
- Copy answer.
- Source citations.
- Source preview drawer.
- Conversation reset.
- Positive or negative feedback.
- Optional feedback comment.
- Clear error state.
- Clear insufficient-evidence response.
- Mobile-responsive layout.
- Keyboard accessibility.
- Screen-reader labels.

Optional later features:

- User file attachment during a conversation.
- Voice input.
- Text-to-speech.
- Conversation export.
- Human handoff.

## 8.19 Hosted Chat

Each published bot may have a hosted chat URL.

Hosted chat can support:

- Public access.
- Password-protected access.
- Authenticated organization access.
- Theme configuration.
- Custom page title and metadata.
- Optional privacy notice.

The hosted chat is useful for testing and demonstrations before website embedding.

## 8.20 Embedded Web SDK

The web SDK will be built separately from the Next.js application.

Recommended technology:

- Preact.
- TypeScript.
- Vite library build.
- Iframe-based UI as the default isolation model.
- A small host-page loader script.

The loader should remain lightweight and should:

- Create the launcher.
- Open and close the iframe.
- Pass configuration safely.
- Exchange approved events through `postMessage`.
- Avoid inheriting or modifying client-site CSS.
- Avoid exposing provider credentials.

The iframe application will handle the full chat UI.

### SDK Configuration

Supported configuration may include:

- Public bot identifier.
- Launcher position.
- Launcher icon.
- Initial open state.
- Locale.
- Theme preference.
- User metadata token when generated by the client's backend.
- Page URL and page title metadata if approved.

Sensitive configuration must never be accepted directly from untrusted JavaScript.

### SDK Events

The SDK may emit:

- Widget opened.
- Widget closed.
- Conversation started.
- Message sent.
- Response completed.
- Citation opened.
- Feedback submitted.
- Error occurred.

### SDK Security

- Validate allowed origins.
- Issue short-lived chat session tokens.
- Do not expose provider API keys.
- Sign authenticated end-user metadata.
- Rate-limit by bot, origin, session, and IP.
- Sanitize all rendered content.
- Restrict iframe permissions.
- Apply Content Security Policy.

## 8.21 Mobile Application

The mobile application will use React Native with Expo.

The first release will be a reusable KnowledgeBot client rather than a separate custom app per customer.

Capabilities:

- Sign in to an organization when private access is required.
- Select an available bot.
- Start and continue conversations.
- Stream answers.
- Open citations.
- Submit feedback.
- Persist basic local preferences securely.
- Support Android and iOS.

Later capabilities:

- Push notifications.
- Deep links to a bot or conversation.
- Offline display of previously loaded messages.
- White-label builds.
- Voice input.

The mobile app will call the same Laravel-facing public runtime APIs as the web clients.

## 8.22 Conversation Management

A conversation will contain:

- Organization.
- Bot.
- Channel: hosted web, embedded web, mobile, admin playground, or API.
- Authenticated user or anonymous session.
- Start time.
- Last activity time.
- Locale.
- Status.
- Consent state when applicable.
- Messages.
- Provider calls.
- Retrieval traces.
- Feedback.

Conversation retention will be configurable by organization or bot.

Administrators may be allowed to view conversations only when the organization's privacy policy enables it.

Sensitive values should be redacted before logging or analytics where practical.

## 8.23 Analytics

The admin dashboard should show:

- Total conversations.
- Total messages.
- Unique sessions.
- Average response latency.
- First-token latency.
- Provider error rate.
- Fallback rate.
- Positive and negative feedback rate.
- Questions with insufficient evidence.
- Most cited sources.
- Sources with low retrieval usage.
- Token usage by provider and model.
- Estimated provider cost.
- Ingestion success and failure counts.
- Crawl freshness.
- Storage usage.

The first release can use aggregated PostgreSQL queries. A dedicated analytics warehouse is unnecessary.

## 8.24 Admin Playground

The playground is essential for showcasing the project.

It should allow an authorized administrator to:

- Select a bot.
- Ask questions.
- Temporarily select a different model.
- View the final answer.
- View dense retrieval results.
- View sparse retrieval results.
- View fusion scores.
- View reranker scores.
- View selected context.
- View excluded results and reasons.
- View source metadata.
- View provider latency and usage.
- Save a question to an evaluation dataset.

The playground should clearly separate production bot settings from temporary test overrides.

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
| Pipeline framework | Haystack, used selectively | Composable indexing and query pipelines |
| Background jobs | Celery | Parsing, OCR, crawling, embedding, deletion, and evaluation jobs |
| Data validation | Pydantic | Typed internal contracts |
| HTTP clients | Official provider SDKs or official documented HTTP clients | Direct provider integration |
| Testing | pytest | AI-service testing |

Python is preferred over Node.js for the AI service because the document-processing, OCR, embedding, reranking, evaluation, and RAG ecosystem is substantially stronger and more direct in Python.

Haystack should be used as an internal pipeline toolkit, not as the application's domain architecture. KnowledgeBot-owned interfaces and data models remain authoritative.

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

---

## 12. RAG Query Pipeline

The query pipeline is the most important technical feature of KnowledgeBot AI.

## 12.1 Pipeline Stages

1. Request validation.
2. Access and quota validation.
3. Conversation-context preparation.
4. Query normalization.
5. Optional query rewriting.
6. Retrieval filters.
7. Dense retrieval.
8. Sparse retrieval.
9. Result fusion.
10. Deduplication and diversity control.
11. Reranking.
12. Evidence thresholding.
13. Context packing.
14. Prompt construction.
15. Official LLM API call.
16. Response streaming.
17. Citation linking.
18. Output validation.
19. Usage recording.
20. Feedback and evaluation hooks.

## 12.2 Request Validation

Validate:

- Bot is published or requester is an authorized tester.
- Organization is active.
- Channel is allowed.
- Origin is allowed for embedded use.
- Session token is valid.
- Question is non-empty and within length limits.
- Rate limits are available.
- Provider connection is enabled.
- Model is enabled.
- At least one active knowledge source exists unless the bot explicitly permits general model answers.

## 12.3 Conversation Context

Long conversation history should not be sent unbounded to retrieval or the LLM.

The system will maintain:

- Recent message window.
- Optional conversation summary.
- Current user question.
- Resolved references from previous messages when required.

A history condensation step may rewrite follow-up questions into standalone retrieval queries.

Example concept:

- User asks: “What is the warranty?”
- User follows: “Does it cover accidental damage?”
- Retrieval query becomes: “Does the product warranty cover accidental damage?”

The original user wording remains available to the generation step.

## 12.4 Query Normalization

Normalization may include:

- Unicode normalization.
- Whitespace cleanup.
- Language detection.
- Removal of obvious UI noise.
- Preservation of important numbers, codes, and names.
- Detection of requests that do not require retrieval, such as greetings.

## 12.5 Query Rewriting

Query rewriting is optional and configurable.

Possible outputs:

- One standalone query.
- Multiple subqueries for multi-part questions.
- Extracted filters such as date, product, category, or source tag.

The system should store both original and rewritten queries for diagnostics.

Query rewriting must not change the user's intent.

## 12.6 Retrieval Filters

Every Qdrant query must filter by:

- Organization identifier.
- Bot identifier or permitted source assignment.
- Active source status.
- Active source version.

Optional filters:

- Language.
- Source type.
- Tags.
- Effective date.
- Expiry date.
- User access group.
- Product or category metadata.

Tenant and access filters are mandatory and must never be delegated to the LLM.

## 12.7 Dense Retrieval

Dense retrieval finds semantically similar chunks using embeddings.

Initial configurable default:

- Retrieve approximately 20 dense candidates.

The exact number should be tuned through evaluation.

## 12.8 Sparse Retrieval

Sparse retrieval finds lexically relevant chunks and is important for:

- Product codes.
- Error messages.
- Names.
- Acronyms.
- Exact phrases.
- Numbers.
- Legal wording.

Initial configurable default:

- Retrieve approximately 20 sparse candidates.

## 12.9 Fusion

Dense and sparse results will be combined using a supported fusion strategy such as Reciprocal Rank Fusion.

Fusion should produce a larger candidate set before reranking.

The system should retain:

- Dense score.
- Sparse score.
- Dense rank.
- Sparse rank.
- Fused score.

## 12.10 Deduplication and Diversity

Near-identical chunks may appear from:

- Repeated navigation.
- Duplicate documents.
- Repeated headers and footers.
- Multiple pages with copied policy text.

The pipeline should:

- Remove exact duplicates.
- Penalize near duplicates.
- Limit excessive results from one document when broader evidence is useful.
- Keep adjacent chunks when they complete a passage.

## 12.11 Reranking

The fused candidates will be reranked with a cross-encoder reranker.

Initial configurable default:

- Rerank approximately 20 to 30 candidates.
- Retain approximately 6 to 10 evidence chunks.

Reranking improves relevance by jointly evaluating the query and candidate text.

The reranker score must be visible in the admin playground.

## 12.12 Evidence Threshold

The system must support an evidence threshold.

When no evidence meets the threshold, the bot should:

- State that the answer could not be found in the available sources.
- Avoid inventing an answer.
- Optionally suggest a narrower question.
- Record an insufficient-evidence event.

The threshold must be evaluated using real test questions rather than chosen only by intuition.

## 12.13 Context Packing

Context packing selects evidence within the model's available context budget.

It should consider:

- Reranker score.
- Source diversity.
- Chunk length.
- Adjacent chunk relationships.
- Heading context.
- Table integrity.
- Citation identity.
- Model context limit.
- Reserved space for instructions, conversation history, and output.

The context builder should avoid cutting a table or structured list in a misleading location.

## 12.14 Prompt Construction

The prompt should clearly separate:

- Trusted system instructions.
- Bot behavior instructions.
- User conversation.
- Retrieved source content.
- Source identifiers.
- Citation requirements.

Retrieved documents must be treated as untrusted data, not instructions.

The prompt should explicitly state that instructions contained inside sources must not override platform or bot instructions.

## 12.15 Answer Generation

The selected provider adapter calls the official API.

The generation request should require:

- Grounding in supplied evidence.
- Clear answer.
- No unsupported factual claims.
- Citation markers linked to evidence.
- Honest insufficient-evidence behavior.
- Safe handling of conflicting sources.
- Appropriate response style.

## 12.16 Citation Generation

Citations must be based on retrieved evidence, not generated free-form by the model.

Each evidence item receives a stable citation label before generation.

The final response may cite:

- PDF page.
- Word section.
- Excel sheet and row range.
- PowerPoint slide.
- Web page URL and title.
- Manual entry title.
- Image source.

The UI should allow the user to open the citation and see the relevant excerpt.

## 12.17 Citation Validation

After generation, the system should verify:

- Citation labels exist.
- Citation labels belong to the selected context.
- No unknown citation identifier is shown.
- At least one citation is present when the answer contains factual source-based claims, unless citations are disabled.

A later enhancement may map individual answer sentences to supporting evidence and flag weakly supported sentences.

## 12.18 Conflicting Sources

When retrieved sources conflict, the bot should:

- Acknowledge the conflict.
- Identify the relevant source dates or versions when available.
- Prefer explicitly configured authoritative sources.
- Avoid silently choosing an answer without explanation.

Source priority may be configured through metadata.

## 12.19 General Knowledge Behavior

Each bot will have one of two modes:

1. **Strict RAG mode:** Answer only from active knowledge sources.
2. **RAG-first mode:** Use sources first and allow general model knowledge only when clearly disclosed.

The default should be strict RAG mode because the project is intended to demonstrate grounded answers.

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

---

## 14. Chunking Strategy

## 14.1 Principles

Chunking should preserve meaning and structure rather than split only by character count.

The chunker should understand:

- Headings.
- Paragraphs.
- Lists.
- Tables.
- Pages.
- Slides.
- Sheets.
- Sections.
- Captions.

## 14.2 Recommended Defaults

Initial configurable targets:

- Approximately 350 to 700 tokens per chunk.
- Approximately 10 to 15 percent overlap where structural continuity requires it.
- Smaller chunks for FAQs and precise policies.
- Larger chunks for explanatory prose when coherence matters.

These are starting values and must be tuned through evaluation.

## 14.3 Parent and Child Context

Each chunk should retain parent metadata such as:

- Document title.
- Heading path.
- Page or slide.
- Sheet and table.
- Source URL.

A parent-child retrieval enhancement may later retrieve small child chunks but return a larger parent section to the LLM.

## 14.4 Tables

Tables should be represented with headers repeated or associated with each relevant row group.

A table chunk should not lose the meaning of columns.

Large tables should be divided by logical row groups with stable sheet, table, and range metadata.

## 14.5 Repeated Content Removal

The normalizer should identify repeated:

- Headers.
- Footers.
- Cookie notices.
- Navigation menus.
- Legal boilerplate repeated on every page.

Removal rules must be conservative so that important repeated policies are not accidentally deleted.

## 14.6 Chunk Metadata

Every chunk should include:

- Chunk identifier.
- Organization identifier.
- Source identifier.
- Source item identifier.
- Source version identifier.
- Bot-assignment identifiers or an efficient access mapping.
- Sequence number.
- Parent element identifier.
- Heading path.
- Page, slide, sheet, row range, or URL.
- Language.
- Content type.
- Token count.
- Content hash.
- Created time.
- Effective and expiry times when applicable.
- Embedding model identifier.
- Parser version.
- Chunker version.

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

---

## 16. Data Model

The exact schema will be finalized during implementation, but the following entities are required.

## 16.1 Identity and Tenancy

### users

- ID.
- Name.
- Email.
- Password hash.
- Verification status.
- Account status.
- Last login.
- Timestamps.

### organizations

- ID.
- Name.
- Slug.
- Status.
- Default timezone.
- Retention settings.
- Storage quota.
- Request quota.
- Timestamps.

### organization_users

- Organization ID.
- User ID.
- Role.
- Status.
- Invitation metadata.

### roles and permissions

- Role definitions.
- Permission definitions.
- Organization-scoped assignments.

## 16.2 Provider Configuration

### provider_connections

- ID.
- Organization ID.
- Provider type.
- Display name.
- Encrypted credential reference.
- Base URL when allowed.
- Additional encrypted settings.
- Enabled state.
- Last tested time.
- Last test status.
- Timestamps.

### provider_models

- ID.
- Provider connection ID.
- Official model identifier.
- Display name.
- Capability flags.
- Context configuration.
- Output limits.
- Pricing metadata.
- Enabled state.
- Timestamps.

## 16.3 Bots

### bots

- ID.
- Organization ID.
- Name.
- Slug.
- Description.
- Status.
- Access mode.
- Provider model ID.
- Fallback settings.
- Prompt configuration version.
- Retrieval configuration ID.
- Theme configuration.
- Retention configuration.
- Timestamps.

### bot_domains

- Bot ID.
- Allowed origin.
- Status.

### bot_starter_questions

- Bot ID.
- Question.
- Sort order.

### bot_source_assignments

- Bot ID.
- Source ID.
- Priority.
- Optional access metadata.
- Enabled state.

## 16.4 Knowledge Sources

### knowledge_sources

- ID.
- Organization ID.
- Type.
- Name.
- Status.
- Original location.
- Storage object reference.
- Crawl configuration ID.
- Current version ID.
- Tags.
- Effective and expiry times.
- Created by.
- Timestamps.
- Deletion timestamps.

### source_items

A source may contain many independently versioned items, such as pages within a sitemap.

- ID.
- Source ID.
- Canonical identity.
- URL or internal path.
- Title.
- Status.
- Current version ID.
- Last discovered time.
- Missing count.
- Timestamps.

### source_versions

- ID.
- Source item ID.
- Version number.
- Content hash.
- Parser configuration version.
- Chunker configuration version.
- Embedding model version.
- Processing status.
- Warning summary.
- Activated time.
- Retired time.
- Timestamps.

### document_elements

- ID.
- Source version ID.
- Parent element ID.
- Element type.
- Sequence.
- Page, slide, sheet, or section metadata.
- Normalized content reference.
- Structured metadata.

### chunks

- ID.
- Source version ID.
- Document element ID.
- Sequence.
- Text.
- Token count.
- Content hash.
- Metadata.
- Vector point ID.
- Index status.
- Timestamps.

## 16.5 Crawling

### crawl_configurations

- Source ID.
- Sitemap or seed URLs.
- Schedule.
- Include rules.
- Exclude rules.
- Maximum pages.
- Maximum depth.
- JavaScript rendering policy.
- Missing-page policy.
- Robots policy.
- User agent.
- Concurrency and delay.

### crawl_runs

- ID.
- Source ID.
- Trigger type.
- Status.
- URLs discovered.
- URLs fetched.
- URLs changed.
- URLs unchanged.
- URLs added.
- URLs missing.
- Errors.
- Start and finish times.

### crawl_page_results

- Crawl run ID.
- Source item ID.
- URL.
- HTTP status.
- ETag.
- Last-Modified.
- Content hash.
- Result status.
- Error summary.

## 16.6 Conversations

### conversations

- ID.
- Organization ID.
- Bot ID.
- User ID or anonymous session ID.
- Channel.
- Status.
- Locale.
- Consent metadata.
- Started time.
- Last activity time.
- Retention expiry.

### messages

- ID.
- Conversation ID.
- Role.
- Content.
- Status.
- Parent message ID when retrying.
- Provider call ID when applicable.
- Created time.

### provider_calls

- ID.
- Organization ID.
- Bot ID.
- Conversation ID.
- Message ID.
- Provider connection ID.
- Model ID.
- Provider request ID.
- Status.
- Input tokens.
- Output tokens.
- Other token categories.
- Estimated cost.
- First-token latency.
- Total latency.
- Fallback metadata.
- Error class.
- Timestamps.

### retrieval_traces

- ID.
- Message ID.
- Original query.
- Rewritten query.
- Filters.
- Retrieval configuration version.
- Candidate summaries.
- Selected evidence.
- Insufficient-evidence result.
- Timing breakdown.

### citations

- ID.
- Message ID.
- Chunk ID.
- Citation label.
- Display title.
- Location metadata.
- Excerpt.

### feedback

- ID.
- Message ID.
- Rating.
- Comment.
- Submitted by user or anonymous session.
- Timestamps.

## 16.7 Evaluation

### evaluation_datasets

- ID.
- Organization ID.
- Bot ID.
- Name.
- Description.
- Status.

### evaluation_cases

- Dataset ID.
- Question.
- Expected answer or grading notes.
- Expected sources.
- Tags.
- Difficulty.

### evaluation_runs

- ID.
- Dataset ID.
- Bot configuration snapshot.
- Model configuration.
- Retrieval configuration.
- Status.
- Aggregate metrics.
- Start and finish times.

### evaluation_results

- Evaluation run ID.
- Case ID.
- Generated answer.
- Retrieved evidence.
- Metric results.
- Human-review status.
- Error details.

## 16.8 Operations

### background_jobs

- ID.
- Organization ID.
- Type.
- Entity reference.
- External worker job ID.
- Status.
- Progress.
- Retry count.
- Error summary.
- Timestamps.

### audit_logs

- ID.
- Organization ID.
- Actor.
- Action.
- Entity type and ID.
- Before and after summary.
- IP and user agent where appropriate.
- Trace ID.
- Timestamp.

### usage_events

- Organization ID.
- Bot ID.
- Event type.
- Quantity.
- Provider and model.
- Timestamp.
- Aggregation metadata.

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

---

## 18. Security Architecture

## 18.1 Security Principles

- Least privilege.
- Tenant isolation.
- Deny by default.
- No provider credentials in clients.
- Validate at boundaries.
- Treat uploaded and crawled content as untrusted.
- Keep secrets out of logs.
- Make destructive actions auditable.
- Separate public and internal networks.

## 18.2 Credential Security

Provider API keys must be:

- Encrypted at rest using application-level envelope encryption or Laravel-supported encrypted storage with a protected master key.
- Decrypted only in the trusted backend or AI service for the shortest practical time.
- Redacted in APIs, logs, and admin views.
- Replaceable without database migrations.
- Testable through a controlled connection-check action.

The encryption key must be delivered through a secret-management mechanism or protected environment configuration, never committed to Git.

## 18.3 Authentication Security

- Secure, HTTP-only cookies for web sessions.
- CSRF protection for session-authenticated requests.
- Short-lived access tokens where applicable.
- Refresh or reauthentication policies for sensitive actions.
- Password rate limiting.
- Email verification.
- Optional two-factor authentication.

## 18.4 Authorization

Every protected action must check:

- Authenticated identity.
- Organization membership.
- Role or permission.
- Entity ownership.
- Entity status.
- Destructive-action policy.

UI hiding is not authorization.

## 18.5 Embedded Widget Security

- Allowed-origin validation.
- Short-lived session token.
- Signed authenticated-user metadata.
- Strict CORS.
- Content Security Policy.
- Sandboxed iframe.
- Rate limiting.
- Abuse detection hooks.
- Optional CAPTCHA challenge after suspicious behavior.

## 18.6 Prompt Injection Defense

Crawled and uploaded content may contain malicious instructions.

Controls:

- Clearly delimit source content.
- State that source content is untrusted data.
- Never allow source text to change system instructions.
- Do not provide sensitive tools to the initial RAG bot.
- Filter or flag common prompt-injection patterns for diagnostics.
- Preserve source provenance.
- Require explicit future permission design before adding actions or tools.

Prompt-injection detection is a defense layer, not a guarantee.

## 18.7 File Security

- MIME detection.
- Size limits.
- Parser sandboxing where practical.
- CPU and memory limits on worker containers.
- Processing timeouts.
- Malware-scanning integration.
- Zip-bomb protection.
- Password-protected file policy.
- No direct execution of macros.
- Strip active content from converted artifacts.

## 18.8 Crawler Security

- SSRF protections.
- DNS and redirect validation.
- Network egress restrictions.
- Response-size limits.
- Request timeout.
- Concurrency limits.
- Content-type allow-list.
- No arbitrary local-file access.

## 18.9 Output Security

- Render model output through a safe Markdown renderer.
- Sanitize HTML.
- Disable arbitrary scripts.
- Validate URLs before creating links.
- Add `rel` protections to external links.
- Avoid rendering unsafe embedded content.

## 18.10 Data Privacy

Organizations should control:

- Whether conversations are stored.
- Retention duration.
- Whether administrators may review conversations.
- Whether anonymous metadata is collected.
- Whether user feedback comments are stored.
- Whether crawl snapshots are retained.

The project should provide deletion workflows for users, conversations, sources, and organizations.

## 18.11 Audit Logging

Audit the following:

- Login security events.
- User and role changes.
- Provider credential changes.
- Bot publish and configuration changes.
- Source upload, disable, and deletion.
- Crawl schedule changes.
- Data exports.
- Retention-policy changes.
- Destructive operations.

Sensitive values must not be written into audit details.

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

---

## 21. RAG Quality Evaluation

## 21.1 Why Evaluation Is Required

RAG quality cannot be proven only through a few successful demonstrations.

The project must provide a repeatable evaluation process.

## 21.2 Evaluation Dataset

Create representative questions across:

- Direct factual lookup.
- Exact names and codes.
- Multi-document synthesis.
- Table questions.
- Date-sensitive questions.
- Ambiguous questions.
- Unanswerable questions.
- Conflicting-source questions.
- Follow-up questions.
- Multilingual questions if supported.

Each case should optionally define:

- Expected answer.
- Required facts.
- Expected source or sources.
- Forbidden unsupported claims.
- Grading notes.

## 21.3 Metrics

Track metrics such as:

- Retrieval hit rate.
- Source recall.
- Context precision.
- Answer relevance.
- Faithfulness or groundedness.
- Citation correctness.
- Citation completeness.
- Unanswerable-question refusal accuracy.
- Latency.
- Cost.

Automated metrics must be supplemented with human review.

## 21.4 Experiments

Compare:

- Dense-only versus hybrid retrieval.
- Different chunk sizes.
- Different overlap.
- Different embedding models.
- Different rerankers.
- Different top-K values.
- Query rewriting enabled or disabled.
- Different LLMs.
- Different prompt versions.

Every experiment must record an immutable configuration snapshot.

## 21.5 Regression Gate

A release affecting ingestion, retrieval, prompts, or providers should run a minimum evaluation suite.

A release should be blocked or reviewed when:

- Groundedness decreases beyond tolerance.
- Citation accuracy decreases.
- Unanswerable-question hallucination increases.
- Retrieval hit rate decreases.
- Latency or cost increases unexpectedly.

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

---

## 24. Docker and Deployment Design

## 24.1 Docker Principles

- Every application service runs in a container.
- Development and deployment use Docker Compose.
- Only Traefik exposes host ports by default.
- Databases and internal APIs remain on private networks.
- Containers run as non-root where practical.
- Images use multi-stage builds.
- Runtime images contain only required dependencies.
- Health checks are defined.
- Persistent data uses named volumes or mounted storage paths.
- Secrets are not baked into images.

## 24.2 Suggested Services

### Public Edge

- `traefik`

### Application

- `web` — Next.js admin and hosted web.
- `sdk` — static widget and iframe assets.
- `laravel-api` — PHP application runtime.
- `laravel-worker` — Laravel queue workers.
- `laravel-scheduler` — Laravel scheduler process.
- `ai-api` — FastAPI internal service.
- `ai-worker-ingestion` — document and chunking jobs.
- `ai-worker-crawl` — web crawl jobs.
- `ai-worker-embedding` — embedding and indexing jobs.
- `ai-worker-evaluation` — evaluation jobs.

A minimal deployment may combine some AI worker roles in one worker container with separate queues.

### Data

- `postgres`
- `qdrant`
- `valkey`
- `seaweedfs-master` or simplified SeaweedFS deployment.
- `seaweedfs-volume`
- `seaweedfs-filer`
- `seaweedfs-s3`

The exact SeaweedFS topology may be simplified for a portfolio environment.

### Observability

- `otel-collector`
- `prometheus`
- `grafana`
- `loki`
- `tempo` or `jaeger`

## 24.3 Networks

Suggested logical networks:

- `edge`: Traefik and intentionally routed services.
- `application`: web, Laravel, AI service, workers.
- `data`: application services and data services.
- `observability`: services and telemetry stack.

Network membership should be minimal.

## 24.4 Exposed Ports

Public host ports:

- 80.
- 443.

Development-only optional ports may be bound to localhost for database or dashboard inspection, but should not be enabled in production configuration.

## 24.5 Environment Profiles

Compose profiles may include:

- `core`: required application and data services.
- `observability`: metrics, logs, and traces.
- `gpu`: local embedding or OCR acceleration.
- `dev-tools`: local mail viewer, database UI, and diagnostics.
- `test`: isolated test dependencies.

## 24.6 Development Environment

Development should support:

- Bind-mounted source code.
- Hot reload for Next.js and SDK.
- Laravel development server or containerized web server.
- FastAPI reload mode.
- Separate test databases.
- Seed data.
- Fake provider adapter for local testing.
- Local mail capture.
- Sample documents and evaluation data.

## 24.7 Production Environment

Production should use:

- Immutable versioned images.
- Production dependency installs.
- No source bind mounts.
- Restricted container users.
- Resource limits.
- Restart policies.
- TLS.
- Backup jobs.
- Central logging.
- Monitoring alerts.

## 24.8 Initial Server Sizing

A practical external-LLM showcase deployment may begin around:

- 4 to 8 virtual CPU cores.
- 8 to 16 GB memory.
- Fast SSD storage.
- Additional storage based on document volume.

Local embedding, reranking, OCR, and image understanding may require more memory or a supported GPU. Worker concurrency should be configured according to actual resources.

These are planning estimates and must be validated through load tests.

## 24.9 Scaling Path

Scale independently:

- Next.js instances.
- Laravel API instances.
- Laravel workers.
- FastAPI instances.
- Ingestion workers.
- Crawl workers.
- Embedding workers.
- Qdrant deployment.
- PostgreSQL.
- Object storage.

Kubernetes should be considered only when operational needs justify it.

---

## 25. Backup and Disaster Recovery

## 25.1 Backup Scope

Back up:

- PostgreSQL.
- SeaweedFS objects.
- Qdrant snapshots or rebuild metadata.
- Critical configuration.
- Encryption-key recovery material through a separate secure process.
- Grafana dashboards and infrastructure configuration.

Valkey should not be treated as the only durable store for critical business data.

## 25.2 PostgreSQL Backups

- Daily logical or physical backup.
- Transaction-log or point-in-time recovery for serious deployments.
- Encrypted backup storage.
- Retention policy.
- Periodic restore tests.

## 25.3 Qdrant Recovery

Two recovery options:

1. Restore Qdrant snapshots.
2. Rebuild vectors from PostgreSQL metadata and stored normalized content.

The second option is essential to prove that Qdrant is a derived index.

## 25.4 Object Storage Backups

Original sources are critical and should be replicated or backed up to independent storage.

## 25.5 Recovery Documentation

Document procedures for:

- PostgreSQL restore.
- Object storage restore.
- Qdrant restore or rebuild.
- Secret restoration.
- Full environment recreation.

---

## 26. CI/CD Pipeline

A typical pipeline should include:

1. Check formatting.
2. Run static analysis.
3. Run unit tests.
4. Run contract tests.
5. Run frontend tests.
6. Build application images.
7. Scan dependencies and images.
8. Generate SBOM.
9. Run integration tests with containers.
10. Run a small RAG regression suite.
11. Publish versioned images.
12. Deploy to staging.
13. Run smoke tests.
14. Require approval for production.
15. Deploy production.
16. Run post-deployment health checks.

Database migrations should be backward-compatible where possible and should have a rollback or recovery plan.

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

---

## 34. Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Scope becomes too large | Project remains unfinished | Keep connectors, agents, voice, and billing out of MVP |
| Too many technologies | Maintenance complexity | Use clear service boundaries and avoid duplicate responsibilities |
| Provider APIs differ | Adapter complexity | Capability-based provider contract and provider-specific diagnostics |
| Poor document parsing | Inaccurate answers | Docling, OCR, previews, warnings, sample corpus tests |
| Weak retrieval | Hallucination or irrelevant answers | Hybrid retrieval, reranking, thresholds, evaluation |
| Cross-tenant vector leakage | Severe security issue | Mandatory payload filters and automated isolation tests |
| Crawler SSRF | Infrastructure compromise | URL validation, network controls, redirect and DNS checks |
| Deletion misses vectors | Removed content still influences answers | Stable IDs, logical disable, physical deletion, verification job |
| Provider cost grows | Unexpected expense | Quotas, token tracking, model limits, fallback policy |
| External provider outage | Chat unavailable | Optional ordered fallback and transparent error states |
| OCR consumes resources | Slow ingestion | Dedicated queues, limits, optional GPU, page-level retries |
| Docker host lacks resources | Unstable demo | Worker concurrency controls and documented sizing |
| Automated evaluation is misleading | False quality confidence | Human review and multiple metrics |
| Dependency or license changes | Compliance concern | Pinned versions, SBOM, periodic license review |

---

## 35. Open-Source and Licensing Policy

The repository should include:

- Project license.
- Third-party notices.
- Dependency lock files.
- Container base-image records.
- Generated software bill of materials.
- Model names and model-license references.
- Clear statement that external LLM APIs are separate services.

Before public or commercial release, review the licenses of:

- UI packages.
- Document parsers.
- OCR engines.
- Embedding models.
- Reranker models.
- Observability components.
- Container images.

Pin dependencies and model revisions to make builds reproducible.

---

## 36. Documentation Deliverables

The project should eventually contain:

- Main README.
- Product overview.
- Architecture overview.
- ADRs.
- Local development guide.
- Production deployment guide.
- Environment-variable reference.
- Provider integration guide.
- Knowledge ingestion guide.
- Crawling guide.
- RAG configuration guide.
- Security guide.
- Backup and restore guide.
- Troubleshooting guide.
- API documentation.
- Widget integration guide.
- Mobile development guide.
- Evaluation guide.
- Contribution guide.
- Changelog.

---

## 37. Recommended Final Product Description

### Short Description

KnowledgeBot AI is a multi-model RAG chatbot platform that turns documents, images, spreadsheets, presentations, and websites into source-grounded AI conversations.

### Medium Description

KnowledgeBot AI is an open-source, self-hostable multi-model RAG chatbot platform. Administrators can upload business documents, crawl websites, configure direct integrations with multiple official LLM APIs, and publish source-grounded chatbots through web, embedded, mobile, and API channels. The platform uses hybrid retrieval, reranking, citations, source versioning, periodic recrawling, and verified knowledge deletion.

### Portfolio Description

KnowledgeBot AI demonstrates production-oriented full-stack and AI architecture using Next.js, Laravel, FastAPI, React Native, PostgreSQL, Qdrant, Valkey, Docling, Crawl4AI, and Docker. It integrates OpenAI, Anthropic Claude, DeepSeek, NVIDIA NIM, and OpenRouter directly through their official APIs. The project includes complex document ingestion, OCR, hybrid vector and lexical retrieval, reranking, grounded response generation, source citations, multi-tenancy, secure website embedding, mobile chat, RAG evaluation, observability, and complete source lifecycle management.

---

## 38. Glossary

**Bot:** A configured KnowledgeBot assistant with its own model, prompt, sources, appearance, and access policy.

**Chunk:** A retrievable segment of normalized source content.

**Citation:** A link between an answer and the source evidence used to support it.

**Control plane:** The business and administrative layer managed by Laravel.

**Data plane:** The AI execution and retrieval layer managed by FastAPI.

**Dense retrieval:** Semantic search using embedding-vector similarity.

**Embedding:** A numeric representation of text used for semantic search.

**Fusion:** Combining rankings from multiple retrieval methods.

**Grounded answer:** An answer based on supplied and cited evidence.

**Hybrid retrieval:** Retrieval combining dense semantic search and sparse lexical search.

**Knowledge source:** An uploaded file, web source, image, or manual content entry.

**LLM provider adapter:** KnowledgeBot-owned integration that translates internal requests to one official provider API.

**OCR:** Optical character recognition used to extract text from images or scanned documents.

**RAG:** Retrieval-Augmented Generation, where relevant source content is retrieved and supplied to an LLM before answer generation.

**Reranker:** A model that scores query and candidate pairs to improve evidence ordering.

**Source item:** An independently versioned item inside a source, such as one page in a sitemap.

**Source version:** A specific processed state of source content and processing configuration.

**Sparse retrieval:** Lexical or token-oriented retrieval useful for exact terms and identifiers.

**Vector database:** A database optimized for storing and searching embedding vectors.

---

## 39. Official Reference Material

The following official project and provider documentation should be treated as the starting reference during implementation. Exact API behavior and model capabilities must be rechecked before development because provider APIs evolve.

### Application Frameworks

- Next.js documentation: https://nextjs.org/docs
- Laravel documentation: https://laravel.com/docs
- FastAPI documentation: https://fastapi.tiangolo.com/
- React Native documentation: https://reactnative.dev/docs/getting-started
- Expo documentation: https://docs.expo.dev/
- Preact documentation: https://preactjs.com/guide/v10/getting-started
- Vite documentation: https://vite.dev/guide/

### AI Providers

- OpenAI API documentation: https://platform.openai.com/docs
- Anthropic API documentation: https://docs.anthropic.com/en/api
- DeepSeek API documentation: https://api-docs.deepseek.com/
- NVIDIA NIM API documentation: https://docs.api.nvidia.com/nim/docs/api-quickstart
- OpenRouter documentation: https://openrouter.ai/docs

### RAG and Data Processing

- Haystack documentation: https://docs.haystack.deepset.ai/
- Qdrant documentation: https://qdrant.tech/documentation/
- Qdrant hybrid search: https://qdrant.tech/documentation/search/hybrid-queries/
- Docling documentation: https://docling-project.github.io/docling/
- Docling supported formats: https://docling-project.github.io/docling/usage/supported_formats/
- Crawl4AI documentation: https://docs.crawl4ai.com/
- Ragas documentation: https://docs.ragas.io/

### Data and Infrastructure

- PostgreSQL documentation: https://www.postgresql.org/docs/
- Valkey documentation: https://valkey.io/docs/
- SeaweedFS repository and documentation: https://github.com/seaweedfs/seaweedfs
- Docker Engine documentation: https://docs.docker.com/engine/
- Docker Compose documentation: https://docs.docker.com/compose/
- Traefik documentation: https://doc.traefik.io/traefik/

### Observability and Security

- OpenTelemetry documentation: https://opentelemetry.io/docs/
- Prometheus documentation: https://prometheus.io/docs/
- Grafana documentation: https://grafana.com/docs/grafana/latest/
- Trivy documentation: https://trivy.dev/latest/docs/
- Syft documentation: https://github.com/anchore/syft
- Semgrep documentation: https://semgrep.dev/docs/
- Gitleaks documentation: https://github.com/gitleaks/gitleaks

---

## 40. Final Architecture Recommendation

For the initial production-oriented showcase, use the following fixed architecture:

- **Next.js + TypeScript** for the admin and hosted web application.
- **Preact + TypeScript + Vite** for the embedded iframe chat SDK.
- **React Native + Expo** for Android and iOS.
- **Laravel** for authentication, tenancy, bots, provider settings, source metadata, chat orchestration, analytics, scheduling, and public APIs.
- **Python FastAPI** for direct LLM-provider integration, ingestion, crawling, embeddings, retrieval, reranking, prompting, and evaluation.
- **Official provider APIs and SDKs directly**, without LiteLLM Gateway.
- **PostgreSQL** as the authoritative relational database.
- **Qdrant** as the vector and hybrid retrieval database.
- **Valkey** for queues, rate limits, locks, and short-lived cache.
- **SeaweedFS** for S3-compatible object storage.
- **Docling** for document parsing and OCR orchestration.
- **Crawl4AI** for website and sitemap crawling.
- **BGE-M3** as the initial embedding model.
- **BGE reranker** as the initial reranking model.
- **Haystack** as a selective internal RAG pipeline toolkit.
- **Ragas** for evaluation.
- **OpenTelemetry, Prometheus, Grafana, Loki, and Tempo or Jaeger** for observability.
- **Traefik** as the only public reverse proxy.
- **Docker Engine and Docker Compose** for development and deployment.

This combination is detailed enough to demonstrate serious architecture and RAG engineering while remaining achievable if the scope is delivered in phases.


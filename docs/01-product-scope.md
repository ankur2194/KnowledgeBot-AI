# Product Purpose, Vision, Goals, Users, and Journeys

> Part of the **KnowledgeBot AI** specification — §1–7, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

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


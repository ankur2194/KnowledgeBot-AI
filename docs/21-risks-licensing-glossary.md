# Risks, Licensing, Documentation, Glossary, References

> Part of the **KnowledgeBot AI** specification — §34–40, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

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

- Haystack documentation: https://docs.haystack.deepset.ai/ — *not adopted (ADR-016); retained for verifying the evaluation evidence only.*
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
- ~~**Haystack** as a selective internal RAG pipeline toolkit.~~ **Not adopted — superseded by ADR-016:** no Haystack component can carry our mandatory tenant filter (`filter_policy` defaults to `REPLACE`), so the RAG pipeline is an explicit stage runner we own. See `.claude/skills/haystack-pipelines/SKILL.md`.
- **Ragas** for evaluation.
- **OpenTelemetry, Prometheus, Grafana, Loki, and Tempo or Jaeger** for observability.
- **Traefik** as the only public reverse proxy.
- **Docker Engine and Docker Compose** for development and deployment.

This combination is detailed enough to demonstrate serious architecture and RAG engineering while remaining achievable if the scope is delivered in phases.


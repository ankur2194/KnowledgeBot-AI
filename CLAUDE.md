# KnowledgeBot AI

Self-hostable multi-model RAG chatbot platform. Documents, images, spreadsheets, presentations, and crawled websites become source-grounded AI conversations with citations.

**Status:** planning complete, implementation not started. The repository currently holds the specification, the docs split, and the skill/agent library — no application code yet.

## Read this first

- **[docs/00-index.md](docs/00-index.md)** — the specification split into 21 sections, plus a table of the invariants most likely to be violated and where each is defined.
- **`.claude/skills/kb-architecture-map/SKILL.md`** — who owns what, and the boundaries between services.
- `KnowledgeBot-AI.md` — the original 3,816-line spec. Prefer the `docs/` split; read this only when you need the whole document.

## Architecture in one paragraph

Laravel is the **control plane**: identity, tenancy, bots, provider configuration, encrypted credentials, source metadata, conversations, quotas, and every public API. FastAPI is the **AI data plane**: provider calls, retrieval, reranking, prompting, parsing, OCR, crawling, embedding, indexing, evaluation, and deletion. PostgreSQL is the source of truth; Qdrant is a derived, rebuildable retrieval index; Valkey handles queues, locks, and rate limits; SeaweedFS stores objects. Browser, widget, and mobile clients talk **only** to Laravel — never to FastAPI.

## Working here

Delegate implementation to the agent that owns the area rather than editing across boundaries. Each agent in `.claude/agents/` opens with the exact skill files it must read; those skills carry the conventions, the gotchas, and a `## Definition of done`.

| Area | Agent |
|---|---|
| Laravel control plane | `control-plane-engineer` |
| LLM provider adapters | `provider-adapter-engineer` |
| Parsing, OCR, chunking | `ingestion-engineer` |
| Crawling and recrawl | `crawler-engineer` |
| Retrieval and reranking | `retrieval-engineer` |
| Knowledge deletion | `deletion-engineer` |
| Next.js admin + hosted chat | `admin-web-engineer` |
| Embedded widget SDK | `widget-sdk-engineer` |
| React Native app | `mobile-engineer` |
| Docker, Traefik, data services | `platform-devops-engineer` |
| Tracing, metrics, logs | `observability-engineer` |
| RAG evaluation | `rag-eval-engineer` |
| Tests across all runtimes | `test-engineer` |
| Security review *(read-only)* | `security-auditor` |
| Contract review *(read-only)* | `contract-steward` |
| Docs and ADRs | `docs-adr-writer` |

## Non-negotiables

These are architectural, not stylistic. Breaking one is a bug even when tests pass.

1. **Tenant isolation is enforced in code, never delegated to the LLM.** Every relational query, vector search, storage path, and cache key is scoped to an organization.
2. **Every Qdrant query filters** organization, bot access, active source status, and active source version.
3. **Clients never reach FastAPI.** Authentication, authorization, rate limiting, and credential handling all live in Laravel.
4. **Qdrant is rebuildable.** Never store anything there that cannot be reconstructed from PostgreSQL and object storage.
5. **Source versions publish atomically.** A new version becomes searchable only after it is fully indexed and verified; the previous version serves until then.
6. **Deletion uses stable identifiers and is verified.** Never delete vectors by text match, and always confirm removal afterwards.
7. **Retrieved content is untrusted data.** Source text can never alter system or bot instructions.
8. **Citations come from retrieved evidence**, assigned before generation — not from free-form model output.
9. **No provider credential reaches a client**, a log, an API response, or an audit detail.

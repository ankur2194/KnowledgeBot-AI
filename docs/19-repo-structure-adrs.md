# Repository Structure and Architecture Decision Records

> Part of the **KnowledgeBot AI** specification — §27–28, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

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

ADR-011 through ADR-018 resolve the eight questions the specification left open. They were decided
after the specification was written, so unlike ADR-001…010 each carries the alternatives it ruled out.
**The full argument for every one of them — including the failure symptom and, where one exists, the
revisit condition — is in [`docs/22-spec-findings-and-decisions.md`](22-spec-findings-and-decisions.md).**
Read it there before reopening any of these; the summaries below are pointers, not the reasoning.

### ADR-011: Send the Provider Credential in the Internal Request Body

**Decision:** Laravel decrypts and sends `provider_credential` as a top-level `SecretStr` field, excluded
from the configuration snapshot hash and on the never-forward list.

**Reason:** Holding the KEK in FastAPI would force it to read Laravel's tables without reducing blast
radius; a separate fetch endpoint adds a hop to the 4-second first-token budget. Excluding it from the
snapshot is what keeps a key rotation from invalidating every cached answer — and keeps a plaintext key
out of every persisted snapshot. **Revisit if** the AI service ever leaves the operator's trust boundary.

### ADR-012: FastAPI Writes Four Derived Tables Directly

**Decision:** `services/ai-service` writes `chunks`, `document_elements`, `retrieval_traces`, and
`evaluation_results` — and nothing else. Laravel owns all migrations, every other table, and the
active-version pointer flip.

**Reason:** A callback ships every chunk's text through a PHP request body to a table Laravel never
reads. Direct writes are safe because they target the not-yet-active version, so nothing reads them
until Laravel flips the pointer. Enforced in CI as an allow-list.

### ADR-013: Keep `web` off the `application` Network

**Decision:** `apps/web` is on `edge` only, ratifying `kb-architecture-map`'s deviation from §24.3.

**Reason:** It has no Server Actions and produces no org-scoped server bytes, so it has nothing to ask
`ai-api` for. On `application`, "clients never reach FastAPI" would be enforced by nobody.

### ADR-014: Fallback Triggers on Capacity Signals, Not Unknown Models

**Decision:** Provider overload and capacity codes map to `provider_temporary` (retryable,
fallback-eligible); `model_not_found` stays `provider_permanent_request`. No new error class.

**Reason:** The model name came from the tenant's configuration, so an unrecognised model is a
misconfiguration they must see. Falling back would silently serve a different model at a different price
and quality with no error anywhere.

### ADR-015: Deletion and Erasure Have Different Reach

**Decision:** Source deletion breaks the citation link and retains `citations.excerpt`,
`retrieval_traces.selected_evidence`, and `evaluation_results.retrieved_evidence`. Data-subject erasure
is a superset that overwrites those three in place, under the same two-phase-plus-proof contract, and is
refused outright when it collides with a legal hold.

**Reason:** A transcript should survive an admin removing a stale document; a §18.10 erasure request must
not. Overwriting in place rather than deleting rows keeps historical metrics from silently moving.

### ADR-016: Do Not Adopt Haystack

**Decision:** Haystack is not part of this stack. Supersedes the §9.5 fencing sentence in
`docs/05-tech-stack.md` and the glossary entry in `docs/21-risks-licensing-glossary.md`.

**Reason:** Four verified disqualifications, any one of which is fatal: `filter_policy` leaves no
configuration in which our tenant filter and a facet filter both survive; `DocumentJoiner` discards the
per-branch scores the trace requires; its point identity makes delete-by-filter match nothing **and
report success**; and telemetry defaults on. `.claude/skills/haystack-pipelines/` is retained as the
record of why.

### ADR-017: Reaffirm ADR-001/003/005 Against Laravel 13's AI APIs

**Decision:** The control plane uses none of Laravel 13's AI SDK, `Str::toEmbeddings()`, or
`DB::whereVectorSimilarTo()`. Enforced by a CI grep over `services/core-api`.

**Reason:** Custody, not API quality. Those calls would sit outside the adapter contract's usage
accounting, and `whereVectorSimilarTo()` is a second retrieval path with none of the four mandatory
filters. **Revisit only if** Qdrant is dropped for pgvector — and even then, not the AI SDK.

### ADR-018: Absorb the Signing-Scheme Change into `v1`

**Decision:** The canonical string now covers every `X-KB-*` header under the existing `KB1` prefix. From
the first deployed release, any change to the canonical string, header set, or hash bumps the prefix, and
the verifier accepts both for one release window.

**Reason:** No signer has ever been deployed, so bumping now would make the version describe authoring
history rather than deployment history. The dual-accept window exists because signer and verifier deploy
at different times.


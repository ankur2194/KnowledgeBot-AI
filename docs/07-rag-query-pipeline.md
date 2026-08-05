# RAG Query Pipeline

> Part of the **KnowledgeBot AI** specification — §12, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

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


# Per-stage obligations — RAG query pipeline

Companion to `../SKILL.md`. Source: docs/07-rag-query-pipeline.md §12. Stage numbering is §12.1 and is stable — trace fields, metrics, and playground panels key off it.

## 1–2. Request, access, and quota validation

Reject before any AI cost is incurred (§12.2): bot published or requester an authorised tester; org active; channel allowed; origin allowed for embedded use; session token valid; question non-empty and within the length cap; rate limit available; provider connection enabled; model enabled; at least one active knowledge source unless the bot permits general model answers.

Target for the whole of stages 1–2 is under 250 ms (docs/17-testing-performance.md §23). Error classes and their retry/timeout behaviour: `kb-error-taxonomy`.

## 3. Conversation-context preparation

Maintain a bounded recent-message window, an optional rolling summary, the current question, and resolved references. History is never sent unbounded to retrieval or the LLM — an unbounded window is how a long conversation silently eats the context budget that stage 13 thinks it has.

Record: turns used, summary text (it is model output and must be reproducible in the trace), summary token cost.

## 4. Query normalization

Unicode normalization, whitespace cleanup, language detection, UI-noise removal. **Numbers, product codes, error codes, and proper names are preserved verbatim** — they are precisely what the sparse branch exists to match (§12.8).

Also detects requests that need no retrieval (greetings, thanks). Those skip stages 6–13 and go straight to a bounded canned or model reply, with `needs_retrieval = false` in the trace so the panel does not read as an empty-retrieval failure.

## 5. Query rewriting

Optional and configurable. Outputs one standalone query, several subqueries for multi-part questions, or extracted facets (date, product, category, tag). **Extracted facets are advisory optional filters only** — they never touch the mandatory tenant/bot/status/version filter.

Rules enforced in `../SKILL.md`: intent immutable; both queries stored; retrieval runs on last-user-turn ‖ rewrite; entity guard with fallback to the original. The *original* wording, not the rewrite, is what the generation prompt shows as the user's question.

## 6. Retrieval filters

Mandatory: organization id, bot id or permitted source assignment, active source status, active source version. Optional: language, source type, tags, effective date, expiry date, access group, product/category metadata.

Owned by `kb-tenancy-isolation`. Qdrant filter syntax by `qdrant-hybrid-search`. Payload field names by `kb-chunking-rules`. This pipeline requires the filter and records the resolved object; it defines none of the three.

## 7–8. Dense and sparse retrieval

Dense (BGE-M3) finds semantic matches; sparse finds exact lexical matches — product codes, error strings, names, acronyms, quoted phrases, numbers, legal wording. Both default to ~20 candidates. Run them concurrently; their latencies are separate metrics (docs/15-observability.md §20.2).

Record per candidate: score and rank, per branch, independently. A candidate found by only one branch keeps a null rank for the other — that asymmetry is the most diagnostic thing in the playground.

## 9. Fusion

Reciprocal Rank Fusion, `score(d) = Σ 1/(k + rank)`, 1-indexed, absent documents contributing zero. Fused client-side in the AI service so both branches' ranks and scores survive into the trace (§12.9 requires all five numbers). Fusion deliberately produces a *larger* set than the reranker will consume.

`fusion.k` is explicit config. Per-branch weights are permitted but start at 1.0 and move only by evaluation.

## 10. Deduplication and diversity

Sources of near-duplicates: repeated navigation, duplicate documents, repeated headers and footers, copied policy text across pages.

- Exact duplicates (normalized text hash) are removed.
- Near duplicates are **penalized**, not deleted — a penalty is recoverable by the reranker, a deletion is not.
- `diversity.max_per_document` caps how many chunks one document contributes when broader evidence would help.
- **Adjacency exemption:** a chunk adjacent by `(source_version_id, chunk_index)` to an already-retained chunk is never dropped by dedup or by the per-document cap. §12.10 requires keeping adjacent chunks that complete a passage.

## 11. Reranking

Cross-encoder over `(retrieval_query, chunk_text)`. Consumes `rerank.candidates` (20–30 default), retains `rerank.retain` (6–10 default). The reranker cannot recover recall stage 7–8 never had — raising candidate depth is the first tuning experiment.

The reranker score must be visible in the playground (§12.11). Model, batching, sequence-length truncation, and score scale: `bge-reranker`.

## 12. Evidence threshold

Applied to the reranker score only. When nothing passes: state the answer was not found in the available sources, do not invent one, optionally suggest a narrower question, and record an `insufficient_evidence` event (it is a §20.2 chat metric, not just a log line).

The threshold is calibrated against the unanswerable and ambiguous cases in the evaluation dataset (§21.2), measured by refusal accuracy against hallucination rate (§21.3). Intuition is not an acceptable input.

## 13. Context packing

Inputs to the decision (§12.13): reranker score, source diversity, chunk length, adjacency, heading context, table integrity, citation identity, model context limit, and reserved space for instructions, history, and output.

- Budget = model limit − reserved output − prompt overhead − history. Compute before packing, not after.
- Tables and structured lists are atomic blocks.
- Order is positional, not score-descending: best first, second-best last, weakest in the middle.
- A block that does not fit is excluded with a reason; packing continues so a smaller later block can still land.

## 14. Prompt construction

Six sections in fixed order — platform instructions, bot behaviour, conversation, retrieved content (fenced, marked as data), source identifiers, citation requirements — with an explicit statement that instructions inside sources do not override platform or bot instructions. `prompt.version` is recorded so §21.4 can A/B it.

## 15–16. Generation and streaming

The provider adapter calls the official API (ADR-001). The request requires grounding in supplied evidence, no unsupported claims, citation markers drawn from the assigned labels, honest insufficient-evidence behaviour, and explicit conflict handling. Streaming starts at the first provider text event; first-token target under 4 s. Translation, fallback, and truncation stop reasons: `kb-provider-adapter-contract`.

## 17–18. Citation linking and output validation

Labels were assigned in stage 13. Linking writes `citations` rows (chunk id, label, display title, location metadata, excerpt) so the UI can open a citation and show the excerpt. Location metadata is per-format: PDF page, Word section, Excel sheet + row range, PowerPoint slide, web URL + title, manual entry title, image source.

Validation asserts: every label exists, every label belongs to the packed set, no unknown identifier is displayed, and at least one citation is present when the answer makes factual source-based claims and citations are enabled. Sentence-level support mapping is a documented later enhancement (§12.17), not MVP.

## 19–20. Usage recording, feedback, evaluation hooks

Usage lands in `provider_calls` (tokens, cost, first-token and total latency, fallback metadata). The message id must be available to `feedback` and to "save this question to an evaluation dataset" from the playground (§8.24) — that is the loop that produces the data every default in this pipeline is tuned from.

## Exclusion-reason vocabulary

Fixed strings; the playground groups on them and evaluation counts them.

| Reason | Stage |
|---|---|
| `exact_duplicate` | 10 |
| `near_duplicate_penalized` | 10 (retained, score adjusted) |
| `document_diversity_cap` | 10 |
| `below_rerank_candidate_cutoff` | 11 |
| `below_evidence_threshold` | 12 |
| `above_retain_limit` | 12 |
| `context_budget_exhausted` | 13 |
| `unknown_citation_label` | 18 |

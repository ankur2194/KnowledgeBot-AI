---
name: kb-rag-query-contract
description: The 20-stage grounded-answer contract for KnowledgeBot AI — rewriting, hybrid retrieval, RRF fusion, dedup, reranking, evidence threshold, context packing, citation validation. Use whenever editing query-path code in services/ai-service/, changing a top-K or the refusal threshold, or debugging an answer that is wrong, uncited, or falsely refused. Owns stage order and defaults, not the tenant filter or Qdrant syntax. Pairs with kb-tenancy-isolation (the filter it requires) and bge-reranker (the threshold's scale, and why stage 11 is capability-gated).
---

# RAG Query Contract

Stack: FastAPI + Python in `services/ai-service/`, Qdrant, provider-API embeddings and provider-API reranking (ADR-030 — no local models), and an explicit stage runner we own. **No Haystack (ADR-016 — not adopted)**: its Qdrant retrievers cannot carry our tenant filter and its fusion discards the per-branch ranks stage 9 requires. The four verified findings are in `haystack-pipelines`; reopening needs an ADR superseding 016, never an import.
**Authoritative spec:** docs/07-rag-query-pipeline.md §12, docs/04-functional-channels-chat.md §8.24, docs/16-evaluation.md §21, docs/15-observability.md §20.2, docs/11-data-model.md §16.6

## Non-negotiables

- **The 20 stages run in the order below, always.** Skipping a stage is a config value (`rewrite.enabled = false`), never a code path that jumps. An agent that "optimises" by fusing before filtering, or reranking before dedup, produces a retrieval trace nobody can debug and an evaluation run nobody can reproduce.
- **Every vector query carries the mandatory org + bot + active-status + active-version filter.** This pipeline *requires* that contract and must never delegate any part of it to the LLM or to a rewritten query's "extracted filters". Defined by `kb-tenancy-isolation`; retrieval without it is a cross-tenant leak, not a bug.
- **Retrieved content is untrusted data.** It is delimited, labelled as data, and explicitly ranked below platform and bot instructions in the prompt. Depth of defence — pattern flagging, provenance, tool policy — belongs to `kb-security-baseline` (docs/13-security.md §18.6).
- **Citations are assigned before generation and validated after.** Each packed chunk gets a stable label; the model may only reference those labels; every label in the output is checked back against the packed set. Free-form model-authored citations are a defect, not a style choice (§12.16–12.17).
- **When nothing clears the evidence threshold, the bot refuses.** It states the answer is not in the sources, records an `insufficient_evidence` event, and does not fall through to model knowledge. Strict RAG is the default mode; RAG-first is opt-in per bot and must disclose when it leaves the sources (§12.19).
- **Every default here is a starting point to be moved by evaluation, never by intuition.** A changed top-K, threshold, or prompt version without an evaluation run attached (docs/16-evaluation.md §21.4–21.5) is an unreviewable change. The threshold in particular must be set from real unanswerable test questions.
- **Every stage records its own trace fragment.** The admin playground (§8.24) must be able to show dense results, sparse results, fusion scores, reranker scores, selected context, *and excluded results with reasons*. If a stage drops a candidate silently, the playground is lying.

## How we use it

### The 20 stages

Per-stage obligations, tuning notes, and the exclusion-reason vocabulary live in [references/pipeline-stages.md](references/pipeline-stages.md). This table is the map.

| # | Stage | Knobs | Records into `retrieval_traces` / playground |
|---|---|---|---|
| 1 | Request validation | length cap, channel allowlist | — (fails fast; see `kb-error-taxonomy`) |
| 2 | Access + quota validation | rate limits | — |
| 3 | Conversation-context prep | `history.window_turns`, `summary.enabled` | turns used, summary text, summary token cost |
| 4 | Query normalization | unicode form, noise rules | normalized query, detected language, `needs_retrieval` |
| 5 | Query rewriting | `rewrite.enabled`, `rewrite.max_subqueries` | **original query and rewritten query, both** |
| 6 | Retrieval filters | optional facets only | the full resolved filter object |
| 7 | Dense retrieval | `dense.top_k` = **20** | per-candidate dense score + dense rank, latency |
| 8 | Sparse retrieval | `sparse.top_k` = **20** | per-candidate sparse score + sparse rank, latency |
| 9 | Fusion (RRF) | `fusion.k` = **60**, per-branch weights | fused score, and both input ranks per candidate |
| 10 | Dedup + diversity | `diversity.max_per_document` (**no near-duplicate knob** — see references) | dropped candidates + reason |
| 11 | Reranking **(capability-gated)** | `rerank.candidates` = **20–30**, `rerank.retain` = **6–10** | rerank score per candidate, latency — **or** a closed `RerankSkipReason` and the degraded marker |
| 12 | Evidence threshold **(only if 11 ran)** | a `(provider, model)` calibration — there is **no portable default** | pass/fail per candidate, `above_retain_limit` drops, `insufficient_evidence` flag |
| 13 | Context packing | `context.budget_tokens`, `context.reserve_output` | packed order, per-chunk tokens, budget-evicted chunks |
| 14 | Prompt construction | `prompt.version` | prompt version id, section token counts |
| 15 | Provider call | model, temperature | delegated to `provider_calls`; see `kb-provider-adapter-contract` |
| 16 | Response streaming | — | first-token latency |
| 17 | Citation linking | `citations.enabled` | label → chunk id map (written to `citations`) |
| 18 | Output validation | `citations.require_at_least_one` | unknown/invalid labels found, action taken |
| 19 | Usage recording | — | token counts, cost, total latency |
| 20 | Feedback + eval hooks | — | message id available for `feedback` and dataset capture |

Stages 7–13 are the tunable core. Stage 6's filter is not tunable — it is a security boundary.

### Defaults, and the standing instruction about them

Ship §12.7–12.12's depths as written: dense 20, sparse 20, RRF fusion, rerank 20–30, retain 6–10. The threshold is the one default that **cannot** be shipped as written: `evidence.min_score = 0.30 on the sigmoid scale` was a property of `bge-reranker-v2-m3` under `normalize=True`, and ADR-030 replaced that model with a per-organization provider whose scale may be an unbounded logit or a bounded relevance score. There is no portable number; `bge-reranker` owns the `(provider, model)` calibration and refuses rather than defaulting. **The depths are starting points, not findings.** Every one of them moves only through an evaluation run with an immutable config snapshot (§21.4). Snapshot the whole retrieval config under a `retrieval_configuration_version` and write that version into every trace — a trace without it cannot be replayed and is worthless for a regression gate.

The first experiment to run is candidate depth. A reranker can only reorder what stage 1 handed it, so recall@candidates is a hard ceiling on final quality; 20–30 candidates is a thin set to be asking a reranker to rescue — and for an org whose provider cannot rerank at all, stage 1's recall *is* the final quality. Anthropic's contextual-retrieval eval reranks a much wider pool and finds top-20 into the model beats top-10 and top-5, while Liu et al. report only ~1–1.5% gain going from 20 to 50 *retrieved* documents without reranking — so the payoff is in retrieving wide and packing narrow, which is exactly what stages 7–13 are shaped for.

### Query rewriting

Rewriting turns a follow-up into a standalone retrieval query (§12.3). Rules:

- **Intent is immutable.** The rewrite resolves pronouns and elided subjects. It does not add constraints, guess a product, translate, or expand into keyword lists — retrieval-oriented rewrites (keyword bags, hypothetical documents) measurably hurt by distorting intent and over-weighting rare terms.
- **Both queries are stored** (`original_query`, `rewritten_query`) and the *original* wording goes to the generation step. Retrieval uses the last user turn concatenated with the rewrite, not the rewrite alone — preserving the user's phrasing while adding the missing context is the configuration that wins in published multi-turn ablations, and it is also the cheapest insurance against a bad rewrite.
- **Entity guard, enforced in code:** every code-like token in the original (digits, `[A-Z]{2,}`, hyphenated alphanumerics, quoted spans) must survive into the rewrite. If one is missing, discard the rewrite and retrieve on the original. Record the fallback.

### Worked example — the evidence core (stages 9–13, 17)

```python
# services/ai-service/app/rag/evidence.py
RRF_K = 60  # NOT Qdrant's default of 2. We fuse here, not server-side, because
            # §12.9 requires retaining dense/sparse rank AND score per candidate,
            # which a server-side fusion result does not hand back.

def fuse(dense: list[Hit], sparse: list[Hit]) -> list[Candidate]:
    pool: dict[str, Candidate] = {}
    for branch, hits in (("dense", dense), ("sparse", sparse)):
        for rank, hit in enumerate(hits, start=1):          # RRF ranks are 1-indexed
            c = pool.setdefault(hit.chunk_id, Candidate(hit))
            setattr(c, f"{branch}_rank", rank)
            setattr(c, f"{branch}_score", hit.score)         # kept for the playground only
            c.fused_score += 1.0 / (RRF_K + rank)            # magnitude is deliberately ignored
    return sorted(pool.values(), key=lambda c: -c.fused_score)

def select(cands: list[Candidate], cfg: RetrievalConfig, trace: Trace) -> list[Evidence]:
    kept = dedup_and_diversify(cands, cfg, trace)            # exact-dup drop by content_hash,
                                                             # max_per_document cap, neighbours spared.
                                                             # NO near-dup penalty: no detector exists,
                                                             # and the one candidate detector's firing
                                                             # set is a SUBSET of the adjacency
                                                             # exemption, so it could never fire
    outcome = rerank_gate(trace.retrieval_query, kept[: cfg.rerank_candidates], cfg, trace)
    if not outcome.applied:                                  # capability-gated: the provider may have
        return select_unranked(kept, cfg, trace)             # no rerank endpoint at all (ADR-030).
                                                             # NEVER threshold the fused score here.
    scored = outcome.scored
    cal = calibration_for(cfg.provider, cfg.rerank_model)    # raises if uncalibrated — a threshold
                                                             # borrowed from another model's
                                                             # distribution is the bug, not the fix
    trace.rerank = [(c.chunk_id, s, cal.scale) for c, s in scored]

    over = [(c, s) for c, s in scored if s >= cfg.evidence_min_score]
    for c, s in scored:
        if s < cfg.evidence_min_score:
            trace.exclude(c, "below_evidence_threshold", score=s)
    for c, s in over[cfg.rerank_retain:]:                    # the retain cap drops candidates too,
        trace.exclude(c, "above_retain_limit", score=s)      # and §8.24 needs the reason for each
    passing = over[: cfg.rerank_retain]
    if not passing:
        trace.insufficient_evidence = True                   # stage 12 refusal — no fallthrough
        return []

    return pack(passing, cfg, trace)                         # stage 13

def pack(passing, cfg, trace) -> list[Evidence]:
    budget = cfg.context_budget_tokens - cfg.reserve_output - trace.prompt_overhead_tokens
    ordered = interleave_ends(passing)   # rank 1 first, rank 2 LAST — the middle of a long
                                         # context is where models lose facts (Liu et al. 2024)
    out, used = [], 0
    for i, (cand, score) in enumerate(ordered):
        block = render_block(cand)       # atomic: a table or list is packed whole or not at all
        if used + block.tokens > budget:
            trace.exclude(cand, "context_budget_exhausted")
            continue                     # skip and keep going — a later chunk may still fit
        out.append(Evidence(label=f"S{len(out) + 1}", chunk=cand, score=score, block=block))
        used += block.tokens             # label is assigned HERE, before the prompt is built
    trace.packed = [(e.label, e.chunk.chunk_id) for e in out]
    return out
```

### Prompt construction and citation validation

The prompt is six labelled sections in this order: platform instructions → bot behaviour → conversation → **retrieved content, fenced and marked as data** → source identifiers → citation requirements. The platform section states explicitly that instructions found inside sources must not override it. Never interpolate retrieved text into the system section, and never let a rewritten query's text reach the instruction sections.

After generation (stage 18): extract every `[S<n>]` from the answer; any label not in `trace.packed` is stripped and logged as `unknown_citation_label`; if the answer makes factual claims and cites nothing while `citations.enabled`, treat it as a failed generation, not a good answer. Conflicting sources (§12.18) are surfaced, not silently resolved — the prompt instructs the model to name the conflict, cite both labels, and prefer a source carrying higher configured priority or a later effective date, and to say which rule it applied.

## Gotchas

- **Hybrid search suddenly favours whatever sparse returns first, on queries where dense was obviously right.** Qdrant's RRF constant defaults to **k = 2**, while Elasticsearch, OpenSearch, and every RRF tutorial use **60**. At k=2 the rank-1 hit of each branch contributes `1/3` and rank-5 contributes `1/7` — top ranks dominate ~30× more sharply than the literature default. Set `fusion.k` explicitly and record it in the config snapshot; never inherit it. **And `k` does not mean the same thing on both sides of the wire:** Qdrant scores `1/((pos+1)/weight + k − 1)` with a 0-based `pos`, so a server-side `k=60` behaves as textbook 59. Our production path fuses here in Python with the textbook `1/(k + rank)` at `RRF_K = 60`; the eval harness and the playground, which *do* use server-side fusion for comparison, must pass **61** to be the same setting. Two numbers, one behaviour — `qdrant-hybrid-search` carries the arithmetic.
- **The bot never refuses, no matter how irrelevant the corpus is.** Symptom of thresholding on the *fused* score: RRF discards magnitude by construction, so the top candidate scores `2/(k+1)` whether it is a perfect match or noise. The evidence threshold is defined **only** on the reranker score. For the same reason, never threshold on dense cosine either — dual-encoder similarity is not calibrated and is not comparable across queries (arXiv:2408.04887).
- **The refusal rate flips to ~0% or ~100% after a reranker config change.** Rank scores come on incompatible scales — NVIDIA NIM returns an unbounded logit, Cohere-shaped APIs a bounded relevance score — and the same float is valid on all of them. Since ADR-030 the model is **per organization**, so this can now fire for one tenant and not the rest, which makes it far harder to spot. Store the scale beside the threshold, key the calibration by `(provider, model)`, and refuse to threshold against an uncalibrated pair. Specifics: `bge-reranker`.
- **The bot answers "not in your sources" on evidence it retrieved well, and no number changed.** A threshold read on the wrong scale. `0.30` is a valid float on a logit scale and on a bounded one, the trace stores a plausible-looking score either way, and no test fails — only the refusal rate moves, and only in aggregate. Every prose statement of a threshold anywhere in this repo names its scale beside the number for exactly this reason, and `RerankCalibration` validates the pairing at construction.
- **Answer quality degrades for weeks on one tenant and nothing alerts.** Their provider could not rerank — either the vendor publishes no ranking route, or it publishes one whose score scale this platform cannot threshold (two different `RerankSkipReason` members with opposite remedies) — and the pipeline served the fused order. Correct behaviour, but it must be *visible*. The skip reason belongs on the trace and in a metric, or a capability gap reads as a defect in the retrieval stack. Worse variant: a provider **outage** recorded as a skip. `RerankSkipReason` is closed and contains no member meaning "the call failed" precisely to make that impossible (`bge-reranker`).
- **The answer cites `[S7]` when only six chunks were packed, or cites a document the trace shows was never retrieved.** Citation labels were assigned after generation. Attaching citations post-hoc collapses citation recall by roughly 3× versus generating inline against pre-assigned labels (ALCE, arXiv:2305.14627): the model writes plausible prose that matches no passage, and nothing can retroactively map it. Labels are assigned in `pack()` and are the only identifiers the model ever sees.
- **The model reports a value from the wrong column of a table.** The packer cut an oversized table between rows, orphaning the header, or truncated mid-row. Render tables and structured lists as atomic blocks — pack whole or exclude with `context_budget_exhausted` — and never character-split them to fit. Chunk-level table integrity is `kb-chunking-rules`; the packer must not undo it.
- **A follow-up about a specific part number returns generic marketing pages.** The rewrite dropped the code — "does the XR-400B cover accidental damage?" became "does the warranty cover accidental damage?". The entity guard above catches this; without it, the trace looks healthy because retrieval genuinely succeeded, just for the wrong question. <!-- UNVERIFIED: mechanism is consistent with reported "rewrites distort intent" findings, but I found no study isolating entity-drop rates. -->
- **The answer stops one sentence short of the fact, and the missing sentence is in the corpus.** Stage 10 dropped the adjacent chunk that completed the passage. **Only two things in stage 10 can drop a candidate** — the exact-duplicate check on `content_hash` and the per-document cap — so this is the cap, spending a document's quota before the completing chunk was reached; there is no near-duplicate suppression to blame (that penalty was ruled out on 2026-08-12, see references). The exemption is what prevents it: a chunk adjacent by `(source_version_id, seq)` to something already retained is never dropped by the cap, though it still *counts* towards its document's total, or one long adjacent run takes the whole answer while the cap reports itself satisfied. §12.10 requires keeping adjacent chunks. <!-- UNVERIFIED: no measured study of this failure; the adjacency rule is spec-mandated and the mechanism is inferred. -->
- **Adding more evidence makes the answer worse, and 20 chunks scores below answering with none at all.** Positional degradation — accuracy is U-shaped in the packed order, worst in the middle (Liu et al., TACL 2024). This is why `pack()` puts rank 1 first and rank 2 last, and why `rerank.retain` stays at 6–10 rather than "everything that passed". Newer long-context models flatten the curve somewhat; do not assume it away without an eval run.
- **A generated answer is silently cut off mid-sentence with no error.** The packer consumed the window that generation needed. `context.reserve_output` must be subtracted from the budget *before* packing, and it must account for the prompt sections, tool definitions if any, and thinking tokens — all input. Recent provider APIs accept an over-budget request and truncate rather than rejecting it, so the adapter must surface the truncation stop reason; see `kb-provider-adapter-contract` and `kb-error-taxonomy`.
- **The bot follows an instruction that came out of a crawled page.** Retrieved text reached an instruction section, or the fencing was interpolated rather than escaped. Retrieved content is one section, one fence, marked as data, always after the instruction sections. `kb-security-baseline` owns the rest of the defence.
- **The playground shows fewer results than the trace implies, with no explanation.** Every drop — dedup, diversity cap, candidate cutoff, threshold, **retain cap**, budget — must call `trace.exclude(candidate, reason)`. The retain cap is the one that gets forgotten, because `[: cfg.rerank_retain]` looks like a slice rather than a filter: the panel then shows fewer candidates than were reranked, all of them passing the threshold, and no row saying `above_retain_limit`. §8.24 requires "excluded results *and reasons*"; a stage that filters without recording makes the whole panel untrustworthy.

## Official docs

- [Reciprocal rank fusion — Elasticsearch](https://www.elastic.co/docs/reference/elasticsearch/rest-apis/reciprocal-rank-fusion) — the RRF formula and the k=60 convention.
- [Hybrid queries — Qdrant](https://qdrant.tech/documentation/concepts/hybrid-queries/) — server-side fusion, the k=2 default, and Qdrant's own RRF-vs-DBSF guidance.
- [BAAI/bge-reranker-v2-m3 model card](https://huggingface.co/BAAI/bge-reranker-v2-m3) — historical, and still the clearest worked example of why a threshold number is meaningless without its scale.
- [Lost in the Middle (Liu et al., TACL 2024)](https://aclanthology.org/2024.tacl-1.9/) — positional degradation; the reason for the packing order.
- [Enabling LLMs to Generate Text with Citations (ALCE, EMNLP 2023)](https://arxiv.org/abs/2305.14627) — measured cost of post-hoc citation attachment.
- [Relevance Filtering for Embedding-based Retrieval (arXiv:2408.04887)](https://arxiv.org/abs/2408.04887) — why raw cosine cannot be thresholded across queries.
- [Introducing Contextual Retrieval — Anthropic](https://www.anthropic.com/news/contextual-retrieval) — retrieve-wide/pack-narrow evidence and rerank depth.

## Definition of done

- [ ] All 20 stages present in declared order; disabled stages are config-off, not skipped code paths.
- [ ] Every Qdrant query carries the mandatory filter (`kb-tenancy-isolation`); a test asserts a cross-org query returns zero hits.
- [ ] `fusion.k` is set explicitly in config, not inherited from the client default.
- [ ] `retrieval_traces` row written per message with original query, rewritten query, resolved filters, `retrieval_configuration_version`, per-candidate dense/sparse/fused/rerank scores and ranks, exclusions with reasons, packed order, and the timing breakdown.
- [ ] Playground renders every field in §8.24, including excluded results with reasons.
- [ ] Evidence threshold is defined on the rerank score with its scale pinned beside it, keyed by `(provider, model)`, and raises rather than defaulting when the pair is uncalibrated; an unanswerable-question eval case produces a refusal plus an `insufficient_evidence` event, and no answer.
- [ ] A provider with no rerank capability produces a traced skip with a reason and the degraded marker — never a silent pass-through, never a threshold on the fused score, and never a skip standing in for an error.
- [ ] A test drops candidates by the retain cap and asserts each one appears in the trace as `above_retain_limit` — the reranked count minus the packed count is fully accounted for by exclusion reasons.
- [ ] Citation labels assigned in the packer before prompt construction; a test feeds a fabricated `[S99]` through output validation and asserts it is stripped and logged.
- [ ] Table/list blocks are packed atomically — a test with an oversized table asserts it is excluded whole, never split.
- [ ] Entity guard test: a rewrite that drops a product code falls back to the original query and records the fallback.
- [ ] Any changed default lands with an evaluation run id and a config snapshot; the §21.5 regression gate is green.
- [ ] Retrieval + reranking stays under the 1.5 s target (docs/17-testing-performance.md §23) at the shipped candidate counts.

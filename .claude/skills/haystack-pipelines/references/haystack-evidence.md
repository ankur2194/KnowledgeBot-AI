# Haystack 3.0 — the evaluation evidence

Companion to `../SKILL.md`, which carries the verdict, the runner we build instead, and the gotchas.
Both tables below are the evidence that verdict rests on, moved here verbatim. Versions read from the
PyPI JSON API and the source at `deepset-ai/haystack@main` / `deepset-ai/haystack-core-integrations@main`
on 2026-08-05.

## What Haystack 3.0 genuinely offers, and why none of it lands here

Haystack 3.0 is a serious release: `AsyncPipeline` folded into `Pipeline` with `run_async`/`run_async_generator`/`stream`, first-class `warm_up_async`/`close_async` component lifecycles, an Agent with `before_run`/`before_llm`/`before_tool`/`after_tool` hooks, allow-listed deserialization, and tracing that is **no longer auto-enabled** (you attach an OTel connector explicitly). The problem is not quality. It is that every layer it offers is a layer we have already decided.

| Haystack layer | Our owner | Verdict |
|---|---|---|
| `ChatGenerator` per provider | `app/providers/` — 5 adapters, ADR-001 | Bypassed. Our `ChatRequest`/`ChatResult`/`StopReason`/`Usage` contract is finer-grained than Haystack's. |
| `QdrantDocumentStore` + retrievers | `app/retrieval/` on `qdrant-client` | Bypassed — see the collisions below. |
| `DocumentJoiner(join_mode="reciprocal_rank_fusion")` | `app/rag/evidence.py::fuse` | Bypassed. `k` is hardcoded 61 and per-branch scores are overwritten. |
| Converters / `DocumentSplitter` | Docling + `kb-chunking-rules` | Bypassed. Our boundaries follow document structure, not word counts. |
| Rankers | `bge-reranker-v2-m3` directly (`bge-reranker`) | Bypassed. Moved out of core in 3.0 anyway. |
| Evaluators | Ragas (docs/21 §33) + §21.4 config snapshots | Bypassed. |
| `Pipeline` DAG runtime + serialization | `app/rag/pipeline.py`, in `../SKILL.md` | Not needed. 20 stages, one order, no branch, no loop. |
| Agent + hooks | — | Out of MVP scope. **This is the one that could change the answer.** |

## The four collisions, from source

| Our contract requires | What the code does | Where |
|---|---|---|
| Tenant filter cannot be replaced by a caller | Retrievers default to `filter_policy=FilterPolicy.REPLACE`; a runtime `filters=` **replaces** init filters outright. `MERGE` exists but explicitly rejects native filters: *"Native Qdrant filters cannot be used with filter_policy set to MERGE."* There is no mode in which our `models.Filter` and a facet filter both survive. | `integrations/qdrant/.../retrievers/qdrant/retriever.py` |
| Payload key `org_id`, point id from `chunk_id` | `payload = document.to_dict(flatten=False)` → every meta field lands under `meta.*`. Point id is `uuid.uuid5(UUID("3896d314-1e95-4a3a-b45a-945f9f0b541d"), document.id).hex`, and `Document.id` itself defaults to a SHA-256 of content+meta+embedding. | `.../document_stores/qdrant/converters.py` |
| Per-branch rank + score retained through fusion | Server-side path returns one fused score and no branch ranks. In-process `DocumentJoiner` calls `_reciprocal_rank_fusion`, which hardcodes `k = 61` (no parameter) and returns `replace(doc, score=fused)` — the branch score is gone. | `haystack/utils/misc.py`, `haystack/components/joiners/document_joiner.py` |
| Empty scope raises; `==` means equality | `convert_filters_to_qdrant({})` returns `None` → an unfiltered, match-all query. `_build_eq_condition` turns a string value **containing a space** into `MatchText` (a token/substring match), not `MatchValue`. `in`, `!=`, and `not in` each apply the space test with a *different* polarity than `==`. | `.../document_stores/qdrant/filters.py` |

A narrow adoption survives none of this: to keep our payload you replace the converters, to keep our filter you replace the filter DSL and pin `filter_policy=REPLACE`, to keep our trace you replace the retriever and the joiner. What remains is `qdrant-client` with a `meta.` prefix tax, a beta-classified package in the path of every tenant query, and `haystack-ai`'s own hard `openai>=1.99.2` floor constraining the SDK our OpenAI, DeepSeek, and OpenRouter adapters share.

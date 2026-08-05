---
name: haystack-pipelines
description: The record of why KnowledgeBot does not depend on Haystack, and the explicit stage runner in services/ai-service/app/rag/ we build instead. Use whenever someone proposes adopting Haystack, QdrantDocumentStore, a Haystack retriever, joiner, or Pipeline graph, when reviewing a dependency addition under services/ai-service/, or when reading the toolkit line in docs/05-tech-stack.md §9.5. Pairs with kb-rag-query-contract (the pipeline we hand-build) and kb-tenancy-isolation (the filter no Haystack component can carry).
---

# Haystack — evaluated, not adopted

Evaluated against **haystack-ai 3.0.0** (released 2026-07-20, Apache-2.0, Python ≥3.10) and **qdrant-haystack 10.5.0** (2026-08-03, still classified *Development Status :: 4 - Beta*). **Verdict: no Haystack package is a dependency of `services/ai-service`.** Versions read from the PyPI JSON API and the source at `deepset-ai/haystack@main` / `deepset-ai/haystack-core-integrations@main` on 2026-08-05.
**Authoritative spec:** docs/05-tech-stack.md §9.5, docs/07-rag-query-pipeline.md §12, docs/19-repo-structure-adrs.md ADR-001/005/006, docs/16-evaluation.md §21

**This file contradicts a line of the spec, deliberately.** `docs/05-tech-stack.md` §9.5 lists *"Haystack, used selectively"* and §9.5's prose already fences it: *"an internal pipeline toolkit, not the application's domain architecture. KnowledgeBot-owned interfaces and data models remain authoritative."* Haystack appears in exactly three places in 3,816 lines of spec — a table row, that sentence, and a glossary bullet. It has no ADR, no §12 stage assigned to it, and no §21 evaluation role (the glossary names **Ragas** for that). The evidence below is that the fence in §9.5 cannot be built: every Haystack surface that touches our data either drops the tenant filter or discards a field stage 9 must record. Raise an ADR-011 to close the loop; do not silently add the dependency.

## Non-negotiables

- **Nothing sits between `tenant_filter()` and `qdrant_client`.** The four mandatory terms (`kb-tenancy-isolation` NN-3) are built in one expression and handed to the client call in the same function. A document-store abstraction that *accepts* a filter is an abstraction that can *replace* one, and the failure mode is HTTP 200 with another tenant's answer — no exception, no log line, no metric.
- **Qdrant payload keys stay top-level and are ours.** `org_id`, `bot_ids`, `source_id`, `source_item_id`, `source_version_id`, `source_status` (`kb-tenancy-isolation`, §14.6). Any library that namespaces them under a wrapper key silently breaks every hand-written `FieldCondition`, every delete-by-filter, and the rebuild assertion in `kb-architecture-map`.
- **Point IDs are derived from our `chunk_id` by a rule written in this repository.** Non-negotiable 6 of `CLAUDE.md` — deletion uses stable identifiers and is verified. An id derived by a third-party constant is a deletion contract owned by someone else's `main` branch.
- **Fusion happens in our process, with `fusion.k` from the config snapshot.** Stage 9 must retain each candidate's dense rank, dense score, sparse rank, and sparse score for the playground (§8.24). Any fusion that returns one number per candidate has already destroyed the trace, and no downstream code can rebuild it.
- **Provider calls go through `app/providers/` and nothing else** (ADR-001, `kb-provider-adapter-contract`). A second generation path means a second usage-accounting path, a second stop-reason map, and a second place a credential can be logged.
- **Reintroducing Haystack is an ADR, not an import.** It changes the payload contract, the deletion contract, and the trace contract at once. `pyproject.toml` is the enforcement point; see Definition of done.

## How we use it

### What Haystack 3.0 genuinely offers, and why none of it lands here

Haystack 3.0 is a serious release: `AsyncPipeline` folded into `Pipeline` with `run_async`/`run_async_generator`/`stream`, first-class `warm_up_async`/`close_async` component lifecycles, an Agent with `before_run`/`before_llm`/`before_tool`/`after_tool` hooks, allow-listed deserialization, and tracing that is **no longer auto-enabled** (you attach an OTel connector explicitly). The problem is not quality. It is that every layer it offers is a layer we have already decided.

| Haystack layer | Our owner | Verdict |
|---|---|---|
| `ChatGenerator` per provider | `app/providers/` — 5 adapters, ADR-001 | Bypassed. Our `ChatRequest`/`ChatResult`/`StopReason`/`Usage` contract is finer-grained than Haystack's. |
| `QdrantDocumentStore` + retrievers | `app/retrieval/` on `qdrant-client` | Bypassed — see the collisions below. |
| `DocumentJoiner(join_mode="reciprocal_rank_fusion")` | `app/rag/evidence.py::fuse` | Bypassed. `k` is hardcoded 61 and per-branch scores are overwritten. |
| Converters / `DocumentSplitter` | Docling + `kb-chunking-rules` | Bypassed. Our boundaries follow document structure, not word counts. |
| Rankers | `bge-reranker-v2-m3` directly (`bge-reranker`) | Bypassed. Moved out of core in 3.0 anyway. |
| Evaluators | Ragas (docs/21 §33) + §21.4 config snapshots | Bypassed. |
| `Pipeline` DAG runtime + serialization | `app/rag/pipeline.py`, below | Not needed. 20 stages, one order, no branch, no loop. |
| Agent + hooks | — | Out of MVP scope. **This is the one that could change the answer.** |

### The four collisions, from source

| Our contract requires | What the code does | Where |
|---|---|---|
| Tenant filter cannot be replaced by a caller | Retrievers default to `filter_policy=FilterPolicy.REPLACE`; a runtime `filters=` **replaces** init filters outright. `MERGE` exists but explicitly rejects native filters: *"Native Qdrant filters cannot be used with filter_policy set to MERGE."* There is no mode in which our `models.Filter` and a facet filter both survive. | `integrations/qdrant/.../retrievers/qdrant/retriever.py` |
| Payload key `org_id`, point id from `chunk_id` | `payload = document.to_dict(flatten=False)` → every meta field lands under `meta.*`. Point id is `uuid.uuid5(UUID("3896d314-1e95-4a3a-b45a-945f9f0b541d"), document.id).hex`, and `Document.id` itself defaults to a SHA-256 of content+meta+embedding. | `.../document_stores/qdrant/converters.py` |
| Per-branch rank + score retained through fusion | Server-side path returns one fused score and no branch ranks. In-process `DocumentJoiner` calls `_reciprocal_rank_fusion`, which hardcodes `k = 61` (no parameter) and returns `replace(doc, score=fused)` — the branch score is gone. | `haystack/utils/misc.py`, `haystack/components/joiners/document_joiner.py` |
| Empty scope raises; `==` means equality | `convert_filters_to_qdrant({})` returns `None` → an unfiltered, match-all query. `_build_eq_condition` turns a string value **containing a space** into `MatchText` (a token/substring match), not `MatchValue`. `in`, `!=`, and `not in` each apply the space test with a *different* polarity than `==`. | `.../document_stores/qdrant/filters.py` |

A narrow adoption survives none of this: to keep our payload you replace the converters, to keep our filter you replace the filter DSL and pin `filter_policy=REPLACE`, to keep our trace you replace the retriever and the joiner. What remains is `qdrant-client` with a `meta.` prefix tax, a beta-classified package in the path of every tenant query, and `haystack-ai`'s own hard `openai>=1.99.2` floor constraining the SDK our OpenAI, DeepSeek, and OpenRouter adapters share.

### What we build instead

```python
# services/ai-service/app/rag/pipeline.py — the whole framework, and it is this long.
from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass, field
from typing import Any, Awaitable, Callable

from opentelemetry import trace

tracer = trace.get_tracer("kb.rag")


@dataclass(frozen=True)
class RetrievalConfig:
    version: str                  # retrieval_configuration_version — every trace carries it (§21.4)
    fusion_k: int = 60            # explicit and ours. Qdrant's server-side RRF defaults to k=2;
    dense_top_k: int = 20         # Haystack's DocumentJoiner hardcodes 61. Neither is a choice.
    sparse_top_k: int = 20
    disabled: frozenset[str] = frozenset()


@dataclass
class Candidate:
    chunk_id: str
    dense_rank: int | None = None
    dense_score: float | None = None
    sparse_rank: int | None = None
    sparse_score: float | None = None
    fused_score: float = 0.0


@dataclass
class Ctx:
    tenant_filter: Any            # models.Filter from kb-tenancy-isolation. Positional, no default,
    cfg: RetrievalConfig          # and no framework layer able to substitute one.
    search: Callable[[str, int, Any], Awaitable[list[tuple[str, float]]]]  # thin qdrant-client wrapper
    branches: dict[str, list[tuple[str, float]]] = field(default_factory=dict)
    candidates: list[Candidate] = field(default_factory=list)
    stage_log: list[dict[str, Any]] = field(default_factory=list)


Stage = Callable[[Ctx], Awaitable[None]]


async def run_pipeline(stages: list[tuple[str, Stage]], ctx: Ctx) -> Ctx:
    """20 fixed stages in one order: a list, not a graph. Nothing branches, loops, or rewires at
    runtime, so a DAG engine buys nothing — and a list cannot silently leave an input unconnected."""
    for name, stage in stages:
        if name in ctx.cfg.disabled:
            ctx.stage_log.append({"stage": name, "status": "disabled"})   # config-off, never a jump
            continue
        with tracer.start_as_current_span(f"kb.rag.{name}") as span:      # kb-observability-conventions
            span.set_attribute("kb.retrieval_configuration_version", ctx.cfg.version)
            t0 = time.perf_counter()
            try:
                await stage(ctx)
            finally:                                                      # a raising stage still times
                ctx.stage_log.append({"stage": name, "ms": round((time.perf_counter() - t0) * 1000, 2)})
    return ctx


async def retrieve(ctx: Ctx) -> None:
    """Two single-vector queries, not one server-side fusion query: a fused Qdrant response carries
    one score per point and no per-branch rank, and stages 7-9 must record both."""
    dense, sparse = await asyncio.gather(
        ctx.search("dense", ctx.cfg.dense_top_k, ctx.tenant_filter),
        ctx.search("sparse", ctx.cfg.sparse_top_k, ctx.tenant_filter),
    )
    ctx.branches = {"dense": dense, "sparse": sparse}


async def fuse(ctx: Ctx) -> None:
    pool: dict[str, Candidate] = {}
    for branch, hits in ctx.branches.items():
        for rank, (chunk_id, score) in enumerate(hits, start=1):          # RRF ranks are 1-indexed
            c = pool.setdefault(chunk_id, Candidate(chunk_id))
            setattr(c, f"{branch}_rank", rank)
            setattr(c, f"{branch}_score", score)                          # survives fusion — §8.24
            c.fused_score += 1.0 / (ctx.cfg.fusion_k + rank)
    ctx.candidates = sorted(pool.values(), key=lambda c: -c.fused_score)


if __name__ == "__main__":                                                # python -m app.rag.pipeline
    async def fake_search(using: str, limit: int, flt: Any) -> list[tuple[str, float]]:
        assert flt is not None, "unfiltered search"   # the assertion a document store cannot host
        return [("a", 0.91), ("b", 0.88)] if using == "dense" else [("b", 4.2), ("c", 3.1)]

    out = asyncio.run(run_pipeline(
        [("retrieve", retrieve), ("fuse", fuse)],
        Ctx(tenant_filter=object(), cfg=RetrievalConfig(version="rc_2026_08_01"), search=fake_search),
    ))
    print(*out.candidates, sep="\n")
    print(out.stage_log)
```

Stages 10–13 and 17 are already written in `kb-rag-query-contract` (`dedup_and_diversify`, `select`, `pack`); they plug into this runner unchanged.

### What would change the answer

Re-evaluate if any of these becomes true — not on a version bump alone:

1. **We adopt agentic retrieval** — a planner that decides its own sub-queries and tool calls per turn. That is a graph, and Haystack 3.0's `Agent` with typed hooks and native `step_count`/`token_usage`/`tool_call_counts` outputs is real leverage we would otherwise hand-roll. This is the strongest candidate.
2. **A retriever gains a non-overridable filter** — a `FilterPolicy` variant (or a `mandatory_filters` init arg) that *ANDs* rather than replaces, and accepts a native `models.Filter`. This is the single blocking issue; everything else is annoying, this one is disqualifying.
3. **The joiner exposes `k` and preserves per-branch scores** (e.g. into `Document.meta`), removing the trace-destruction problem.
4. **`qdrant-haystack` leaves beta and lets us own the payload/id mapping** — a converter hook rather than a hardcoded `to_dict(flatten=False)` + `uuid5` namespace.
5. **We need more than one vector backend.** We do not (ADR-005 pins Qdrant); a store abstraction earns its keep only when there are two stores.

Items 2–4 are checked by running the Definition-of-done greps against the integration source, not by reading release notes.

## Gotchas

*(For anyone who adopts a Haystack component anyway — each is the symptom you would actually see.)*

- **Retrieval returns another organization's chunks the first time a facet filter is used, and never before.** `filter_policy` defaults to `REPLACE`, so `retriever.run(query_embedding=v, filters=<facet>)` discards the tenant filter set at init. Every test that omits runtime filters passes. The `MERGE` escape is closed by design: passing a native `models.Filter` under `MERGE` raises `ValueError`.
- **A pipeline runs green and returns every tenant's data, with no exception anywhere.** `Pipeline.run(data=...)` routes by component name; a renamed component or a misspelled key leaves `filters` unset, `convert_filters_to_qdrant(None)` returns `None`, and Qdrant executes an unfiltered — legal, successful, slower — query. The KB rule is that an unfiltered call must be *unrepresentable*; a dict-routed graph makes it merely *unlikely*.
- **Two organizations with similar names retrieve each other's content.** `_build_eq_condition` maps a string value with a space to `MatchText`, so `{"field": "meta.tenant_name", "operator": "==", "value": "Acme Corp"}` matches "Acme Corporate Holdings". Values without a space keep `MatchValue` — so the bug appears the day a tenant name gains a space. The `in`/`!=`/`not in` builders apply the same space test with the opposite polarity, so `==` and `in` disagree on the same value.
- **Deletion verification reports zero remaining vectors while the source still answers.** Payloads are written under `meta.*` by `to_dict(flatten=False)`, so a hand-written `delete(filter=Filter(must=[FieldCondition(key="source_version_id", ...)]))` matches nothing and returns success. `kb-deletion-and-verification` treats a zero-delete as proof of removal; here it is proof of a key-name mismatch.
- **Re-indexing an unchanged source doubles the point count.** `Document.id` defaults to `sha256(content + meta + embedding + sparse_embedding)`, computed in `__post_init__`. Change the embedding model, add a metadata field, or construct the Document with the embedding already attached instead of letting an embedder fill it in, and the id — hence the `uuid5` point id — moves. The old points are orphaned, not overwritten, and stage 10's dedup hides the duplication from the answer.
- **`fusion.k` in the config snapshot has no effect on results.** `QdrantHybridRetriever` passes `rrf_k` only when set (and it needs Qdrant server ≥ 1.16.0; `rrf_weights` needs ≥ 1.17.0); unset, it sends a plain `FusionQuery(fusion=RRF)` and inherits Qdrant's `k=2`. `DocumentJoiner` ignores the config entirely and uses 61. Two components, two constants, neither ours — and the §21.5 regression gate cannot explain the diff.
- **Independent `dense.top_k` and `sparse.top_k` are unreachable.** `_query_hybrid` builds both `Prefetch` objects with no `limit`, so both branches inherit the outer `top_k`. Stages 7 and 8 are separately tunable in our contract and are not separately tunable there.
- **An air-gapped or compliance-reviewed deployment makes outbound calls to `eu.posthog.com`.** `haystack/telemetry/_telemetry.py` reads `os.getenv("HAYSTACK_TELEMETRY_ENABLED", "true")` — **on by default** — and `pipeline_running` posts the pipeline's component inventory (including each component's `_get_telemetry_data()`, which for generators is the model name) plus a `user_id` persisted to a config file on disk. Errors are swallowed by a `NullHandler`, so a blocked egress leaves no log line either. KnowledgeBot is a self-hostable product; unsolicited egress from the data plane is a customer-facing property, not a preference.
- **`pip install qdrant-haystack` upgrades the core framework under you.** The integration pins `haystack-ai>=2.29.0` with no upper bound and is versioned on its own line (10.5.0 against haystack 3.0.0), so a routine lockfile refresh can cross a major boundary of a package that just moved 30+ components out of core. Two independently versioned dependencies with an open-ended constraint between them is exactly the runtime dependency ADR-001 removed from the provider path.
- **A Haystack trace appears as a second root next to `kb.rag.*`.** 3.0 no longer auto-enables tracing, so this only bites if someone attaches the OTel connector: Haystack emits its own `haystack.pipeline.run` / `haystack.component.run` span names, which violate the permanent `kb.<domain>.<operation>` catalog in `kb-observability-conventions` and split the single-trace requirement into two naming schemes on one trace.

## Official docs

- [Haystack docs](https://docs.haystack.deepset.ai/) — component and Pipeline reference.
- [Haystack release notes](https://haystack.deepset.ai/release-notes) — version index; 3.0.0 is 2026-07-20.
- [Haystack 3.0 migration guide](https://haystack.deepset.ai/release-notes/3.0.0) — what moved out of core, and the `AsyncPipeline` removal.
- [deepset-ai/haystack](https://github.com/deepset-ai/haystack) — `haystack/utils/misc.py` (RRF k=61), `haystack/telemetry/_telemetry.py`, `haystack/document_stores/types/filter_policy.py`.
- [deepset-ai/haystack-core-integrations — `integrations/qdrant`](https://github.com/deepset-ai/haystack-core-integrations/tree/main/integrations/qdrant) — the converter, filter, store, and retriever sources cited above.
- [Haystack metadata filtering](https://docs.haystack.deepset.ai/docs/metadata-filtering) — the `field`/`operator`/`value` DSL and its `meta.` prefix.

## Definition of done

- [ ] `grep -rn "haystack" services/ai-service/ --include=*.py --include=*.toml --include=*.lock` returns nothing.
- [ ] `haystack-ai`, `qdrant-haystack`, and any `*-haystack` package are absent from `services/ai-service/pyproject.toml` and the lockfile; CI fails on a new occurrence.
- [ ] Retrieval calls `qdrant_client` directly through the `app/retrieval/` wrapper, with `tenant_filter(...)` built in the same call site (`kb-tenancy-isolation` Definition of done).
- [ ] Fusion is in-process; `fusion.k` comes from `RetrievalConfig` and is written into `retrieval_traces.retrieval_configuration_version`; per-candidate `dense_rank`, `dense_score`, `sparse_rank`, `sparse_score` all survive to the playground.
- [ ] Qdrant payload keys are top-level and match the six-field contract; a rebuild test asserts key names, not just point counts.
- [ ] Point ids derive from `chunk_id` by a rule defined in this repository; the deletion verifier reproduces them without importing a third-party constant.
- [ ] If Haystack is proposed again: an ADR-011 exists, and the "what would change the answer" items 2–4 are re-checked **against the integration source**, with the file paths and the commit recorded.

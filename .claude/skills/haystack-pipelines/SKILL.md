---
name: haystack-pipelines
description: The record of why KnowledgeBot does not depend on Haystack — ADR-016 dropped it, and the four verified findings are the reason. Use when anyone proposes adopting Haystack, QdrantDocumentStore, a Haystack retriever, joiner, or Pipeline graph; when a retrieval, ingestion or evaluation task looks like it wants a pipeline framework; when reviewing a dependency addition under services/ai-service/; or when reading the superseded toolkit lines in docs/05-tech-stack.md §9.5 and docs/21 §40. Also the labelled counter-example for the banned kb.rag.* span names. Pairs with kb-rag-query-contract (the stage runner we own instead) and kb-tenancy-isolation (the filter no Haystack component can carry).
---

# Haystack — evaluated, not adopted (ADR-016)

Findings verified against **haystack-ai 3.0.0** (2026-07-20, Apache-2.0, Python ≥3.10) and **qdrant-haystack 10.5.0** (2026-08-03, still *Development Status :: 4 - Beta*), read from the PyPI JSON API and the source at `deepset-ai/haystack@main` / `deepset-ai/haystack-core-integrations@main` on 2026-08-05. **These are the versions the evidence was verified against — not versions we depend on.** No `haystack-*` package is a dependency of `services/ai-service`, and adding one is a bug, not a refactor.
**Authoritative spec:** ADR-016 (docs/19-repo-structure-adrs.md), which **supersedes** the `docs/05-tech-stack.md` §9.5 pipeline-framework row and fencing sentence and the `docs/21-risks-licensing-glossary.md` §40 entry; docs/22-spec-findings-and-decisions.md decision 6 (the evaluation); docs/07-rag-query-pipeline.md §12; docs/16-evaluation.md §21.

**The spec's history is deliberately preserved, not deleted.** Haystack appeared exactly three times in 3,816 lines — the §9.5 table row *"Haystack, used selectively"*, the §9.5 sentence fencing it as *"an internal pipeline toolkit, not the application's domain architecture"*, and the §40 bullet. It had no ADR, no §12 pipeline stage, and no §21 evaluation role, and the spec had already independently assigned every job it would do. ADR-016 closed the gap the only way the evidence allows: **the §9.5 fence is not buildable.** Every Haystack surface that touches our data either drops the tenant filter or discards a field stage 9 must record. Those three lines now carry a *not adopted* marker pointing here.

## Non-negotiables

- **Do not add `haystack-ai`, `qdrant-haystack`, or any `haystack-*-integration` to any manifest** — not `pyproject.toml`, not a lockfile, not a Dockerfile, not a test extra, not "just for evaluation". CI fails on a new occurrence. The four findings below are the reason, and each is a symptom seen in the source, not a preference.
- **Finding 1 — the tenant filter cannot survive a facet filter.** Qdrant retrievers default to `filter_policy=REPLACE`, so a runtime `filters=` **replaces** the init filter outright; the `MERGE` escape explicitly raises on a native `models.Filter` (*"Native Qdrant filters cannot be used with filter_policy set to MERGE"*). There is no configuration in which both survive. *Symptom: retrieval returns another organization's chunks the first time a facet filter is used, and never before — every test that omits runtime filters passes.*
- **Finding 2 — fusion destroys the trace.** `DocumentJoiner._reciprocal_rank_fusion` hardcodes `k = 61` with no parameter and returns `replace(doc, score=fused)`, discarding the per-branch score; the server-side path returns one fused score and no branch ranks at all. *Symptom: `fusion.k` in the config snapshot has no effect on results, and the §8.24 playground has no dense/sparse rank or score to show — data no downstream code can rebuild.*
- **Finding 3 — point identity and payload keys are not ours.** Point ids are `uuid5(<a namespace constant in their package>, document.id)` over a SHA-256 of content+meta+embedding, and `to_dict(flatten=False)` nests every field under `meta.*`. *Symptom: delete-by-filter on `source_version_id` matches nothing **and reports success** — which `kb-deletion-and-verification` reads as proof of removal while the source keeps answering.*
- **Finding 4 — unsolicited egress from a self-hosted install.** `haystack/telemetry/_telemetry.py` reads `os.getenv("HAYSTACK_TELEMETRY_ENABLED", "true")` — **on by default** — and `pipeline_running` posts the component inventory plus a disk-persisted `user_id` to `eu.posthog.com`; errors are swallowed by a `NullHandler`. *Symptom: an air-gapped or compliance-reviewed deployment makes third-party calls from the data plane, and a blocked egress leaves no log line either.* KnowledgeBot is a self-hostable product; this is a customer-facing property.
- **Nothing sits between `tenant_filter()` and `qdrant_client`.** The four mandatory terms (`kb-tenancy-isolation` NN-3) are built in one expression and handed to the client call in the same function. A document store that *accepts* a filter is an abstraction that can *replace* one, and the failure mode is HTTP 200 with another tenant's answer — no exception, no log line, no metric.
- **Reopening this needs a new ADR that supersedes ADR-016 — never an import.** It would change the payload contract, the deletion contract, and the trace contract at once. The conditions recorded at decision time are in [references/haystack-evidence.md](references/haystack-evidence.md) under *Historical*; they are a record, not an invitation.

## How we use it

**We do not.** Every job Haystack would have done already has an owner, and each owner was assigned by the spec independently of Haystack — nothing below was invented to replace it.

| Job | What does it here | Where |
|---|---|---|
| Retrieval | our own two `query_points` calls — dense and sparse, concurrent, each carrying the four mandatory payload filters built at the call site | `app/retrieval/`; `qdrant-hybrid-search`, `kb-tenancy-isolation` |
| Fusion | Python RRF at `RRF_K = 60`, `1/(k + rank)` with 1-indexed ranks, keeping each candidate's dense rank, dense score, sparse rank **and** sparse score | `app/rag/evidence.py::fuse`; `kb-rag-query-contract` stage 9 |
| Pipeline orchestration | an explicit stage runner: 20 stages, one order, no branch, no loop — a list, not a graph | [references/stage-runner-instead.md](references/stage-runner-instead.md); `kb-rag-query-contract` |
| Parsing | Docling, plus the OCR engine policy on top of it | `docling-parsing`, `ocr-pipeline` |
| Chunking | structure-aware boundaries and the full chunk metadata schema | `kb-chunking-rules` |
| Crawling | Crawl4AI behind our guarded fetch | `crawl4ai-crawler` |
| Embedding | BGE-M3, dense + sparse from one pass | `bge-m3-embeddings` |
| Reranking | `bge-reranker-v2-m3` cross-encoder, and the evidence threshold on its scale | `bge-reranker` |
| Evaluation | Ragas, on the `evaluate` queue, judging through our provider path | `ragas-evaluation`; docs/16 §21 |
| Provider calls | the adapter contract — five official SDKs, one internal shape (ADR-001) | `app/providers/`; `kb-provider-adapter-contract` |
| Document-store abstraction | none. ADR-005 pins one backend; a store abstraction earns its keep only when there are two | `qdrant-hybrid-search` |

Both evidence tables are in [references/haystack-evidence.md](references/haystack-evidence.md), verbatim: every Haystack 3.0 layer against the owner in the table above, and the four findings traced to the exact source files. It also records what a *narrow* adoption would leave — keeping our payload means replacing the converters, keeping our filter means replacing the filter DSL, keeping our trace means replacing the retriever and the joiner, and what remains is `qdrant-client` with a `meta.` prefix tax plus a beta-classified package in the path of every tenant query.

### Counter-example: the span names deliberately shown wrong here

This skill is where the banned span names are allowed to exist, **as the labelled wrong answer** — so a grep for them lands in context rather than in code:

```text
BANNED — do not copy. The module is called `rag`, so these keep getting proposed:
  kb.rag.normalize   kb.rag.retrieve   kb.rag.fuse   kb.rag.rerank   kb.rag.pack
```

`kb-observability-conventions` owns every span name, declares them permanent, and its trace tree has **no `rag` domain**. The catalogued query path is:

```text
kb.query.normalize → kb.retrieval.filters → kb.retrieval.dense ‖ kb.retrieval.sparse
  → kb.retrieval.fuse → kb.retrieval.dedupe → kb.retrieval.rerank
  → kb.retrieval.threshold → kb.context.pack → kb.prompt.build
```

An off-catalogue name errors nowhere: the spans export fine to Tempo and simply match no rule, alert, or dashboard query in the repo. The failure is a Retrieval dashboard of flat "No data" panels and dead trace-to-logs links — silence in exactly the surface you would use to debug retrieval. The defence is a `STAGE_SPANS` lookup that raises `KeyError` on an unknown stage rather than minting a name at runtime. Haystack's OTel connector reintroduces the same problem from the other side: `haystack.pipeline.run` / `haystack.component.run` are a second naming scheme on one trace, and a second root beside `kb.retrieval.*`.

## Gotchas

*(Kept because a rejected dependency comes back as a pull request, not as a proposal. Each is what you would actually see, beyond the four findings above.)*

- **A pipeline runs green and returns every tenant's data, with no exception anywhere.** `Pipeline.run(data=...)` routes by component name; a renamed component or a misspelled key leaves `filters` unset, `convert_filters_to_qdrant(None)` returns `None`, and Qdrant executes an unfiltered — legal, successful, slower — query. The KB rule is that an unfiltered call must be *unrepresentable*; a dict-routed graph makes it merely *unlikely*.
- **Two organizations with similar names retrieve each other's content.** `_build_eq_condition` maps a string value containing a space to `MatchText`, so `{"field": "meta.tenant_name", "operator": "==", "value": "Acme Corp"}` matches "Acme Corporate Holdings". Values without a space keep `MatchValue` — the bug appears the day a tenant name gains a space. `in`, `!=` and `not in` apply the same space test with the opposite polarity, so `==` and `in` disagree on one value.
- **Re-indexing an unchanged source doubles the point count.** `Document.id` defaults to `sha256(content + meta + embedding + sparse_embedding)` computed in `__post_init__`. Change the embedding model, add a metadata field, or attach the embedding before construction instead of letting an embedder fill it in, and the id — hence the `uuid5` point id — moves. Old points are orphaned, not overwritten, and stage 10's dedup hides it from the answer.
- **Independent `dense.top_k` and `sparse.top_k` are unreachable.** `_query_hybrid` builds both `Prefetch` objects with no `limit`, so both branches inherit the outer `top_k`. Stages 7 and 8 are separately tunable in our contract and are not separately tunable there.
- **`pip install qdrant-haystack` upgrades the core framework under you.** The integration pins `haystack-ai>=2.29.0` with no upper bound and is versioned on its own line (10.5.0 against haystack 3.0.0), so a routine lockfile refresh can cross a major boundary of a package that just moved 30+ components out of core. `haystack-ai` also carries a hard `openai>=1.99.2` floor on the SDK three of our adapters share. Two independently versioned dependencies with an open-ended constraint between them is exactly the runtime coupling ADR-001 removed from the provider path.

## Official docs

*(For verifying the findings above, not for using the library.)*

- [Haystack docs](https://docs.haystack.deepset.ai/) — component and Pipeline reference.
- [Haystack release notes](https://haystack.deepset.ai/release-notes) — version index; 3.0.0 is 2026-07-20 — and its [3.0 migration guide](https://haystack.deepset.ai/release-notes/3.0.0), for what moved out of core and the `AsyncPipeline` removal.
- [deepset-ai/haystack](https://github.com/deepset-ai/haystack) — `haystack/utils/misc.py` (RRF k=61), `haystack/telemetry/_telemetry.py`, `haystack/document_stores/types/filter_policy.py`.
- [deepset-ai/haystack-core-integrations — `integrations/qdrant`](https://github.com/deepset-ai/haystack-core-integrations/tree/main/integrations/qdrant) — the converter, filter, store, and retriever sources cited above.
- [Haystack metadata filtering](https://docs.haystack.deepset.ai/docs/metadata-filtering) — the `field`/`operator`/`value` DSL and its `meta.` prefix.

## Definition of done

- [ ] `grep -rn "haystack" services/ai-service/ --include=*.py --include=*.toml --include=*.lock` returns nothing; `haystack-ai`, `qdrant-haystack` and any `*-haystack` package are absent from `services/ai-service/pyproject.toml` and the lockfile, and CI fails on a new occurrence.
- [ ] `grep -rn 'kb\.rag\.' services/ apps/ infrastructure/` returns nothing — in the whole repo the string survives only as the labelled counter-example in this file (and the finding that records it, docs/22 S5). Every stage span name comes from `STAGE_SPANS`, every value in it is in the `kb-observability-conventions` catalogue, and a test asserts that.
- [ ] Retrieval calls `qdrant_client` directly through the `app/retrieval/` wrapper, with `tenant_filter(...)` built at the same call site (`kb-tenancy-isolation` Definition of done).
- [ ] Fusion is in-process; `RRF_K` comes from config and is written into `retrieval_traces.retrieval_configuration_version`; per-candidate `dense_rank`, `dense_score`, `sparse_rank`, `sparse_score` all survive to the playground.
- [ ] Qdrant payload keys are top-level and match the six-field contract; a rebuild test asserts key names, not just point counts.
- [ ] Point ids derive from `chunk_id` by a rule defined in this repository; the deletion verifier reproduces them without importing a third-party constant.
- [ ] `docs/05-tech-stack.md` §9.5 and `docs/21-risks-licensing-glossary.md` §40 still carry their original Haystack lines, marked *not adopted — superseded by ADR-016* and pointing here. Deleting them loses the history this file exists to explain.
- [ ] If Haystack is proposed again: a new ADR superseding ADR-016 exists, and the *Historical* conditions in `references/haystack-evidence.md` were re-checked **against the integration source**, with file paths and commit recorded.

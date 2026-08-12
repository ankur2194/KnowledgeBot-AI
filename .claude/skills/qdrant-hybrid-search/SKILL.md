---
name: qdrant-hybrid-search
description: Hybrid dense + sparse retrieval against Qdrant for services/ai-service/ — collection and payload-index setup, the Query API, RRF fusion, and upsert/count/delete syntax. Use whenever creating a collection, writing a query_points, upsert, count or delete call, shaping point payloads, or debugging retrieval that returns nothing, everything, or another tenant's chunks. Owns the client syntax, not the filter contract or the stage order. Pairs with kb-tenancy-isolation (the four mandatory filters) and bge-m3-embeddings (the vectors, and finding C2 on whether the sparse branch survives).
---

# Qdrant Hybrid Search

Server `qdrant/qdrant:v1.18.3` (2026-07-17), `qdrant-client==1.18.0`. Client and server minors are pinned together and bumped together.
**Authoritative spec:** docs/10-embedding-indexing.md §15.3 §15.5, docs/07-rag-query-pipeline.md §12.6–12.9, docs/11-data-model.md §16.4, docs/03-functional-knowledge-sources.md §8.17

*Why not 1.19.0:* the `v1.19.0` tag and Docker `latest` shipped 2026-08-04 with a matching `qdrant-client` 1.19.0, but no GitHub release entry, changelog, or blog post exists yet. It deprecates `on_disk`/`always_ram` in favour of a `memory: cold|cached|pinned` enum everywhere, removes the legacy `/points/search|recommend|discover` REST endpoints, and adds `SearchParams.idf` corpus scoping. Nothing below needs any of it. Keep the collection and index configuration in one module so the `memory=` migration is a single edit. <!-- UNVERIFIED: v1.19.0's GA status — inferred from the Docker `latest` digest, not from an announcement -->

## Non-negotiables

1. **Every query carries all four tenant terms in `must`, at the top level *and* in every `Prefetch` leaf.** `org_id`, `bot_ids`, `source_status`, `source_version_id` — built by `tenant_filter(...)` in `kb-tenancy-isolation`, which owns the contract. This file owns only the syntax that expresses it.
2. **No filter parameter in this service has a default value, and no code writes `filter or models.Filter()`.** `Filter(must=[])` is a **match-all** (see Gotchas). An unfiltered query is a legal, successful, cross-tenant query — HTTP 200, normal latency, no log line.
3. **Deletion strategy is `kb-deletion-and-verification`'s, not ours.** Its rulings, which this file only implements: delete by point id resolved from `chunks.vector_point_id`; a filter delete is an orphan **sweep**, never the primary mechanism; `wait=True` on every delete; verification counts on the bare `org_id`+`source_id` identity with `exact=True` and no status or version predicate. Do not re-derive or contradict them.
4. **The collection is rebuildable and holds nothing authoritative** (ADR-010). Payloads carry identifiers and citation locators only — never the only copy of anything. The point id is the **deterministic** `uuid5(POINT_NS, f"{org_id}:{source_version_id}:{seq}")` defined by `kb-source-lifecycle`, stored back in `chunks.vector_point_id`, so PostgreSQL can always name every point it owns. It is deliberately **not** the `chunk_id`: chunk ids are ULID `char(26)` (`postgresql-patterns`) and Qdrant accepts only an unsigned integer or a UUID, and a freshly minted id is not stable across a Celery replay — which is exactly how the duplicate-vector bug `kb-source-lifecycle` guards against gets back in.
5. **One collection per embedding configuration** (§15.3), payload-partitioned by tenant — Qdrant's own recommendation, and Qdrant Cloud caps a cluster at 1000 collections. Collection-per-tenant is not a growth path.
6. **Payload indexes are created before the first upsert.** Qdrant builds the extra HNSW edges for a payload field *only after* that field's index exists; indexing later leaves the graph unaware of it and every filtered search degrades to a scan until a forced re-index (bump `ef_construct` by 1) rebuilds every segment.

## How we use it

### Collection and indexes — `services/ai-service/app/retrieval/collection.py`

Dense is the provider's embedding, at a **provider- and model-dependent width** (ADR-030) recorded on the `EmbeddingSpace` — not a fixed 1024. Sparse *was* BGE-M3's learned lexical weights, produced by the same local model in the same pass; API embedding endpoints return **dense only**, so the sparse branch currently has no producer. That is finding **C2**, open: drop the sparse arm, or fill it with locally-computed BM25 (a statistical ranking function, not a model, so not excluded by ADR-030). Until it lands, treat every sparse code path below as retained-but-unfed. Two named vectors still ride on **one point**, so a single upsert or delete moves both.

```python
COLLECTION = EmbeddingSpace(...).collection   # derived, never a literal: the name encodes provider,
                              # model, width and distance, and is never reused across models.
                              # The canary digest is deliberately NOT in it (bge-m3-embeddings).

client.create_collection(
    collection_name=COLLECTION,
    vectors_config={"dense": models.VectorParams(
        size=1024, distance=models.Distance.COSINE, on_disk=True)},   # originals on disk…
    sparse_vectors_config={"sparse": models.SparseVectorParams()},    # no modifier — bge-m3-embeddings
    quantization_config=models.ScalarQuantization(                    # …quantized copy in RAM
        scalar=models.ScalarQuantizationConfig(
            type=models.ScalarType.INT8, quantile=0.99, always_ram=True)),
    hnsw_config=models.HnswConfigDiff(m=0, payload_m=16),  # no global graph; one sub-graph per tenant
)

# BEFORE the first upsert. org_id is the only field that gets is_tenant.
# EVERY index is `keyword`, including the five id fields. See below — this is not an oversight.
client.create_payload_index(COLLECTION, "org_id", field_schema=models.KeywordIndexParams(
    type=models.KeywordIndexType.KEYWORD, is_tenant=True))
for f in ("bot_ids", "source_id", "source_item_id", "source_version_id", "source_status"):
    client.create_payload_index(COLLECTION, f, field_schema=models.KeywordIndexParams(
        type=models.KeywordIndexType.KEYWORD))
```

**`UuidIndexParams` is wrong here, and this file said otherwise until 2026-08-07 (finding O2).**
It stores a UUID as 16 bytes instead of a 36-byte string — same `Match` semantics as `keyword`,
materially less RAM — which is a real saving and completely inapplicable, because **our ids are
ULIDs, not UUIDs** (`postgresql-patterns`): 26-character Crockford base32, which is not parseable
as a UUID. A UUID index over ULID values is a startup-time rejection at best and **a filter that
matches nothing at worst** — and a filter that matches nothing looks exactly like an empty corpus:
HTTP 200, zero candidates, no exception, on the five fields that carry every tenant filter. The
authority is `services/ai-service/app/retrieval/collection.py`'s `PAYLOAD_INDEXES`, which is
`keyword` throughout and has been since it was written; this skill was the thing that was wrong.
(The point id *is* a UUID — `uuid5`, see below — but a point id is not a payload field and takes
no payload index.) `is_tenant=True` co-locates one org's vectors on disk so a tenant-scoped query is a sequential read; it is a **locality hint, not access control** — it enforces nothing. `m=0` + `payload_m=16` is Qdrant's documented multitenant pattern: no collection-wide graph, one graph per `org_id`, so a query never traverses another tenant's region.

Scalar int8 (4× compression) rather than binary or 1-bit TurboQuant: Qdrant's own guidance is that 1-bit compression *"resulted in significant data loss and precision drops for vectors smaller than a thousand dimensions"*. Note this is now a **per-space** judgement — the width is provider- and model-dependent (ADR-030), so a 1024-dim space sits on that boundary while a 3072-dim one does not. Move to 4-bit TurboQuant (8×) only behind a recall measurement (docs/16-evaluation.md §21).

### The tenant-filtered hybrid query — `services/ai-service/app/retrieval/search.py`

Two filtered branch queries, fused in Python. Never one `prefetch` + fusion query: a fused response
carries one score per point and none of the four per-branch inputs stage 9 and §8.24 require (Gotchas).

```python
from dataclasses import dataclass
from qdrant_client import QdrantClient, models
from app.retrieval.tenancy import tenant_filter   # kb-tenancy-isolation owns this

RRF_K = 60                # OUR Python fusion, textbook 1/(k + rank). Owned by
                          # kb-rag-query-contract §12.9 and snapshotted with the config.
QDRANT_SERVER_RRF_K = 61  # A DIFFERENT constant with a different owner: Qdrant's `fusion.k`,
                          # eval/playground comparison only. Never assert the two are equal.

@dataclass
class Candidate:                      # one row of the §8.24 playground table
    point: models.ScoredPoint
    dense_rank: int | None = None
    dense_score: float | None = None
    sparse_rank: int | None = None
    sparse_score: float | None = None
    fused_score: float = 0.0

def hybrid_search(client: QdrantClient, ctx, allowed_version_ids: list[str],
                  dense: list[float], sparse: models.SparseVector,
                  branch_k: int = 20, limit: int = 20) -> list[Candidate]:
    """Stages 7-9 of kb-rag-query-contract. `f` is positional and required: an
    unfiltered call must be unrepresentable, not merely discouraged."""
    f = tenant_filter(ctx, allowed_version_ids)   # raises EmptyScopeError on an empty scope.
    # One object, all four tenant terms in `must`, passed to BOTH calls below — two queries
    # means two chances to forget it, so neither call builds its own filter.

    branches: dict[str, list[models.ScoredPoint]] = {}
    for name, vector in (("dense", dense), ("sparse", sparse)):
        response = client.query_points(     # or one query_batch_points — each request still
            collection_name=COLLECTION,     # carries its own query_filter; there is no shared one
            query=vector,
            using=name,              # named vectors have no default branch; required every call
            query_filter=f,          # top level is `query_filter`; Prefetch's is `filter`
            limit=branch_k,
            with_payload=["chunk_id", "source_id", "source_version_id", "page", "url"],
            with_vectors=False,      # a returned vector is recoverable plaintext (Morris et al. 2023)
            timeout=5,
        )
        branches[name] = response.points     # QueryResponse.points, not a bare list

    pool: dict[str, Candidate] = {}
    for name, points in branches.items():
        for rank, p in enumerate(points, start=1):        # RRF ranks are 1-indexed
            c = pool.setdefault(p.payload["chunk_id"], Candidate(point=p))
            setattr(c, f"{name}_rank", rank)
            setattr(c, f"{name}_score", p.score)   # magnitude is kept for the trace, never fused
            c.fused_score += 1.0 / (RRF_K + rank)
    return sorted(pool.values(), key=lambda c: -c.fused_score)[:limit]
```

Writes, counts and deletes use the same builder and the same explicit flags:

```python
client.upsert(COLLECTION, points=[models.PointStruct(
    id=point_id(chunk),   # uuid5(POINT_NS, f"{org}:{version}:{seq}") — u64 or UUID only, and deterministic
    vector={"dense": d, "sparse": s},                    # both branches, one point
    payload={**payload, "chunk_id": chunk_id},           # the ULID rides in the payload, never as the id
)], wait=True)                                           # wait=True or §13.5's count check is a lie

client.delete(COLLECTION, points_selector=models.PointIdsList(points=point_ids), wait=True)
client.delete(COLLECTION, points_selector=models.FilterSelector(filter=orphan_sweep_filter),
              wait=True)                                 # sweep only — kb-deletion-and-verification
client.count(COLLECTION, count_filter=identity_filter, exact=True).count == 0
```

Emit `kb_vector_upsert_duration_seconds{collection}` and `kb_vector_upsert_points_total{collection,outcome}` on writes, and `kb_retrieval_duration_seconds{stage="dense"|"sparse"|"fuse"}` on the query path (`kb-observability-conventions`). `collection` is a bounded label because collections are per embedding model, never per tenant.

**Not defined here.** The four filter terms and the payload contract → `kb-tenancy-isolation`. Stage order, top-Ks, thresholds → `kb-rag-query-contract`. Deletion ordering and the four verification checks → `kb-deletion-and-verification`. What fills the vectors, and the embedding-space identity the collection name derives from → `bge-m3-embeddings`. What fills the payload → `kb-chunking-rules`.

## Gotchas

- **A search returns every organization's chunks, nothing raised, and the filter looks present in review.** `Filter(must=[])` is a **match-all**, confirmed in `lib/segment/src/index/query_optimization/query_checker.rs` at v1.18.3: `check_must` evaluates `conditions.iter().all(check)`, and `.all()` over an empty collection is `true`. So `filter or models.Filter()`, a `Filter()` default argument, and a conditionally-built condition list that came out empty are all **full-collection scans across every tenant** wearing the shape of a filtered query. The asymmetry makes it worse: `should: []` uses `.any()` and matches *nothing*, so the same mistake in `should` fails loudly while in `must` it fails silently. Filters are positional, required, and raise on empty scope. This is the single most important paragraph in this file.
- **A query missing its per-prefetch filters passes every unit test and leaks nothing in production — until either end changes.** The propagation is inverted between the two runtimes. The **server** merges the root filter into every prefetch leaf (`lib/shard/src/query/planned_query.rs`: `let filter = Filter::merge_opts(propagate_filter.clone(), filter);` under the comment *"Filters are propagated into the leaves"*) — undocumented, source-only, and it ANDs correctly only because our terms are in `must`; `merge_owned` concatenates clause lists per type, so a tenant term in `should` would widen to `tenant OR anything_else`. `QdrantClient(":memory:")` does the opposite: `local_collection.py::_merge_sources` takes the fusion branch, ignores `query_filter` entirely, and calls `self.retrieve(ids, ...)` — unfiltered by construction. So the same broken query **leaks under the in-memory client that unit tests use and is safe in production by an accident nobody wrote down.** Put `filter=` on every leaf, and run isolation tests against a real Qdrant container with two seeded orgs and a canary string.
- **A point ID resolves across the tenant boundary and nothing complains.** `retrieve()` takes no filter parameter at all. Neither does `lookup_from`, and `WithLookup` has no filter field while its `with_payload` defaults to **`true`** server-side — so `query_points_groups` + `with_lookup` pulls whole payloads out of another collection with no tenant condition anywhere. Never expose a point id to a client; authorize any id-addressed fetch against PostgreSQL first.
- **A verification job passes against a collection still absorbing writes — or an ingest that "succeeded" is not searchable.** The wire defaults and the Python client's defaults are **opposite**, and the docs describe the wire. REST `UpdateParams.wait` is `#[serde(default)]` on a `bool`, i.e. `false` — an acknowledgement of receipt, not a commit. `qdrant-client` defaults `wait=True` and explicitly sends it. Likewise `count`: the docs prose says *"the count is approximate for performance reasons"*, but `CountRequestInternal::default_exact()` returns `true`, the gRPC handler unwraps to the same, and the client defaults `exact=True` — the prose is a **docs bug**. Pass both explicitly anyway: the disagreement becomes moot, and a `curl`, a Laravel smoke check, or a raw-REST rebuild script gets the wire default, not the client's. Watch the near-miss: `SearchParams.exact` is a *different* field meaning "brute-force this search", and it does default to `false`.
- **Hybrid search suddenly favours whatever the sparse branch returned first, on queries where dense was obviously right.** A fusion path inherited Qdrant's `DEFAULT_RRF_K = 2` instead of the 60 used by Elasticsearch, OpenSearch and the RRF literature — roughly a 30× difference in how sharply rank 1 dominates. Two traps stacked: `models.FusionQuery(fusion=models.Fusion.RRF)` has **no field for k**, so the shape most examples show can only ever use the default; k arrives via `models.RrfQuery(rrf=models.Rrf(k=...))`, added in server 1.16.0. And Qdrant's formula is `1/((pos+1)/weight + k - 1)` with `pos` 0-based, so **Qdrant's k is the literature's k plus one** — a server-side `k=60` behaves as textbook 59. Our chat path meets neither number: it fuses in Python at `RRF_K = 60` with `1/(k + rank)`. `QDRANT_SERVER_RRF_K = 61` exists only where a comparison run talks to Qdrant's fusion, and 61 is what textbook 60 is spelled on that side. For reference only, since it is the number most often quoted at us: Haystack's `DocumentJoiner` hardcodes 61 with no parameter — Haystack is **not adopted** (ADR-016), so that is a comparison point in the literature, not a code path we can configure (`haystack-pipelines`). Record whichever k a path actually used in its `retrieval_configuration_version` (`kb-rag-query-contract` §12.9); never expect the numbers to match.
- **The bot never refuses, however irrelevant the corpus.** Somebody thresholded the fused score — a `score_threshold` on a fusion query, or a cutoff on `Candidate.fused_score`. RRF discards magnitude by construction — only ranks enter the formula — so the top fused candidate scores the same whether it is a verbatim match or noise, and a threshold there can only ever cut the tail. The evidence threshold lives on the **rerank score** and nowhere else (`kb-rag-query-contract` §12.12; `bge-reranker` owns the scale). Since ADR-030 that scale is **provider-and-model dependent** — NVIDIA NIM returns an unbounded logit, Cohere-shaped APIs a bounded relevance score — so there is no repo-wide `sigmoid`/`0.30` any more; a threshold belongs to a `(provider, model)` calibration derived from an eval run. The same float is valid on every scale and nothing raises when they are swapped: only the refusal rate moves, and only in aggregate. And when the org's provider cannot rerank at all, there is no score and therefore **no threshold** — do not fall back to the fused one.
- **The playground's retrieval panel shows a fused score and dashes in the four columns beside it, and no code change can refill them.** Somebody put the branches behind `prefetch` and let Qdrant fuse. A fused response is one score per point: the per-branch ranks and scores that §12.9 and §8.24 require were discarded server-side and are not recoverable from the result. Banned on the production chat path — that path issues the two filtered `query_points` calls above and fuses in Python. Server-side fusion survives only in the eval harness and the playground's "what would server fusion have done" comparison, which records `QDRANT_SERVER_RRF_K` as its own field. The banned shape, so it is recognisable on sight:

  ```python
  client.query_points(                       # NOT the chat path — comparison runs only
      collection_name=COLLECTION,
      prefetch=[   # filter= on EVERY leaf; the server propagates the root filter down but
                   # QdrantClient(":memory:") does not — see the propagation gotcha above.
          models.Prefetch(query=dense,  using="dense",  limit=branch_k, filter=f),
          models.Prefetch(query=sparse, using="sparse", limit=branch_k, filter=f),
      ],
      query=models.RrfQuery(rrf=models.Rrf(k=QDRANT_SERVER_RRF_K)),  # FusionQuery cannot carry k
      query_filter=f, limit=limit, with_vectors=False,
  )
  ```
- **`AttributeError: 'QdrantClient' object has no attribute 'search'` after a dependency bump.** `search`, `search_batch`, `recommend` and `discover` were **removed** — not deprecated — from the client in 1.16.0, and server 1.19.0 deletes the matching REST endpoints. Every tutorial, blog post and StackOverflow answer written before late 2025 shows the dead API. `query_points` and `query_batch_points` are the only search surface.
- **Every filtered query is slow and `is_tenant` seems to do nothing.** Payload indexes were created after ingestion. Extra HNSW edges for a payload field are generated only once that field's index exists, and tenant co-location happens during optimization — both apply going forward, not retroactively. Index first, then upsert; to repair, force a re-index by bumping `ef_construct` by 1, which rebuilds every segment.
- **A sparse-only tuning change makes no difference, or `sparse` returns far more than `limit`.** Sparse vectors use an exact inverted index: no HNSW, no quantization, no approximation, and `SparseVectorParams` has exactly two fields (`index`, `modifier`). The unit trap follows: `SparseIndexParams.full_scan_threshold` counts **vectors**, while the dense `HnswConfigDiff.full_scan_threshold` counts **kilobytes**. Same name, same collection, different units.
- **Sparse recall collapses after someone enables `modifier=IDF` "because that is what the docs show".** IDF is for BM25-style vectors carrying raw term frequencies. The learned weights this collection was designed around were *already* term importance, so IDF double-counted rarity — hence the modifier is unset. **That reasoning must be revisited if finding C2 lands on BM25**, because raw term frequencies are exactly what IDF is for; `bge-m3-embeddings` owns the ruling either way. It matters for tenancy too: with IDF on, document frequencies are computed **collection-wide across every tenant** by default, which is both a relevance error and a weak cross-tenant statistical oracle. Server 1.19.0 adds `SearchParams(idf=models.IdfCorpusParams(corpus=<org filter>))` to scope it — but **ADR-021 pins us to 1.18.3**, so that scoping is not available today. The tenancy objection therefore stands on its own and survives whichever way C2 lands: turning IDF on for a BM25 branch on the pinned server means cross-tenant document frequencies with no way to scope them.
- **A whole batch fails with a point-id validation error on the first upsert of a new ingest path — and the "fix" is worse than the bug.** Qdrant accepts only an unsigned integer or a UUID as a point id, so a 26-character ULID `chunk_id` (`postgresql-patterns`) or a composite like `f"{source_id}:{seq}"` is **rejected outright** and nothing lands. That failure is loud. The sneaky one is reaching for `uuid4()` to get past it: it is accepted, HTTP 200, no log line — and then a retried upsert writes a second copy of every chunk it already wrote, and a rebuild from PostgreSQL mints *different* ids for byte-identical content, so deletion by `chunks.vector_point_id` misses the strays and the ADR-010 rebuild proof stops reproducing. The point id is the deterministic `uuid5(POINT_NS, f"{org_id}:{source_version_id}:{seq}")` (`kb-source-lifecycle`), stored back into `chunks.vector_point_id` — a genuine `uuid` column — so a rebuild reproduces byte-identical ids and PostgreSQL can always name every point it owns (§8.17). `chunk_id` travels in the payload, where retrieval, dedup and citation read it.
- **A query with `using=` omitted errors or silently searches the wrong branch.** A collection with named vectors has no default vector, so `using` is required on every `Prefetch` and on any single-branch `query_points`. The names `"dense"` and `"sparse"` are part of the collection contract — changing one is a re-index, not a rename.
- **A chunk is indexed but never retrievable by the sparse branch.** Its `models.SparseVector` had empty `indices`/`values`. An empty sparse vector matches nothing, forever, and no error is raised at upsert. Post-ADR-030 there is a second way to reach the same state — a `None` sparse vector because nothing produces one (finding C2) — and the two must not be confused: a deliberate dense-only point is a decision, a silently empty one is a bug. Enforce the distinction at the upserter, and assert non-empty weights on the first batch of every ingest run.
- **A newly `Ready` source is missing chunks and re-running the job "fixes" it.** Ingestion upserted with `wait=False` for throughput and the publication check counted immediately (§13.5). Either upsert with `wait=True`, or make the count a separate step that retries — never both in the same call chain with the flag off.

## Official docs

- [Qdrant — Hybrid queries](https://qdrant.tech/documentation/concepts/hybrid-queries/) — `prefetch`, fusion shapes, the `k=2` default, RRF vs DBSF. Says nothing about filters.
- [Qdrant — Filtering](https://qdrant.tech/documentation/concepts/filtering/) — `must`/`should`/`must_not`/`min_should` semantics.
- [Qdrant — Indexing](https://qdrant.tech/documentation/manage-data/indexing/) — payload index types, `is_tenant`, `is_principal`, `enable_hnsw`, and the create-indexes-first rule.
- [Qdrant — Multitenancy](https://qdrant.tech/documentation/manage-data/multitenancy/) — payload partitioning as the default, the `m=0` + `payload_m` pattern, the 1000-collection ceiling.
- [Qdrant — Sparse vectors](https://qdrant.tech/documentation/concepts/vectors/#sparse-vectors) and [Points](https://qdrant.tech/documentation/concepts/points/) — sparse index config, `wait`, `exact`, delete selectors.
- [Qdrant — Quantization](https://qdrant.tech/documentation/guides/quantization/) — the compression-ratio selection table and the sub-1000-dimension warning.
- [qdrant-client on GitHub](https://github.com/qdrant/qdrant-client) — `local/local_collection.py` is where the in-memory fusion divergence lives; read it before trusting a `:memory:` test.
- [Qdrant server releases](https://github.com/qdrant/qdrant/releases) — 1.16 configurable RRF `k`, 1.17 weighted RRF, 1.18 TurboQuant, 1.19 legacy-endpoint removal.

## Definition of done

- [ ] Every `query_points`, `count`, `scroll`, `set_payload` and filter-delete in the change set takes its `Filter` from `tenant_filter(...)`; no filter parameter has a default and none is built inside an `if`.
- [ ] Every `models.Prefetch(...)` carries `filter=`; the top level carries `query_filter=`; a grep for `Prefetch(` shows no leaf without one.
- [ ] Two-organization isolation test runs against a **real Qdrant container** (never `:memory:`), seeds a canary string in org B, and asserts zero hits for org A across dense-only, sparse-only, and fused queries.
- [ ] A test asserts `Filter(must=[])` never reaches the client: `tenant_filter` raises on an empty scope, and the wrapper rejects a `Filter` whose `must` is empty or `None`.
- [ ] The chat path issues two separate filtered `query_points` calls and fuses in Python, keeping `dense_rank`, `dense_score`, `sparse_rank`, `sparse_score` and `fused_score` per candidate; `grep -rn "RrfQuery(\|FusionQuery(\|prefetch=" services/ai-service/app/rag services/ai-service/app/retrieval` returns nothing outside the eval/playground comparison module.
- [ ] Each RRF `k` is a separately named constant set from config, and each is snapshotted into the `retrieval_configuration_version` of the path that used it: `RRF_K = 60` for our Python fusion (owned by `kb-rag-query-contract`); for server-side fusion, Qdrant's `fusion.k`, which defaults to **2** and must be passed explicitly as 61 to mean textbook 60. Those are the only two fusion paths that exist — Haystack's hardcoded 61 is a literature reference (ADR-016, not adopted) and nothing snapshots a `k` from a package that can never be installed. No test, comment or config asserts that two of these numbers are equal; a comparison report that omits the k it ran under is the defect.
- [ ] Payload indexes for all six payload fields are created in the collection bootstrap **before** any upsert, with `is_tenant=True` on `org_id` only; `hnsw_config` sets `m=0` and `payload_m=16`.
- [ ] Point id is `uuid5(POINT_NS, f"{org_id}:{source_version_id}:{seq}")` (`kb-source-lifecycle`) and is written back to `chunks.vector_point_id` in the same transaction as the chunk row. Re-running the indexer for a version produces byte-identical ids — assert that in a test, because the failure mode is a silent doubling of every vector rather than an error.
- [ ] Every upsert and delete passes `wait=True` explicitly; every verification `count` passes `exact=True` explicitly; no code relies on either default.
- [ ] Collection bootstrap is idempotent and re-runnable, and a full rebuild from PostgreSQL + object storage reproduces the collection (§15.5) — proven by a restore drill, not asserted.
- [ ] `kb_vector_upsert_duration_seconds`, `kb_vector_upsert_points_total`, and `kb_retrieval_duration_seconds{stage}` are emitted with `collection` as the only collection-shaped label.
- [ ] No `with_vectors=True` on any tenant-facing read path; payload projections list fields explicitly rather than returning the whole payload.

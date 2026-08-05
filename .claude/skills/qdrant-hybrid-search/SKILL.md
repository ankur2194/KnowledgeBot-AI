---
name: qdrant-hybrid-search
description: Hybrid dense + sparse retrieval against Qdrant for services/ai-service/ — collection and payload-index setup, the Query API, RRF fusion, and upsert/count/delete syntax. Use whenever creating a collection, writing a query_points, upsert, count or delete call, shaping point payloads, or debugging retrieval that returns nothing, everything, or another tenant's chunks. Owns the client syntax, not the filter contract or the stage order. Pairs with kb-tenancy-isolation (the four mandatory filters) and bge-m3-embeddings (the vectors).
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

Dense is BGE-M3's 1024-dim CLS vector; sparse is its learned lexical weights. Two named vectors on **one point**, so a single upsert or delete moves both.

```python
COLLECTION = "kb_bge_m3_v1"   # name encodes the embedding configuration; never reused across models

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
client.create_payload_index(COLLECTION, "org_id", field_schema=models.UuidIndexParams(
    type=models.UuidIndexType.UUID, is_tenant=True))
for f in ("bot_ids", "source_id", "source_item_id", "source_version_id"):
    client.create_payload_index(COLLECTION, f, field_schema=models.UuidIndexParams(
        type=models.UuidIndexType.UUID))
client.create_payload_index(COLLECTION, "source_status", field_schema=models.KeywordIndexParams(
    type=models.KeywordIndexType.KEYWORD))
```

`UuidIndexParams` stores a UUID as 16 bytes instead of a 36-byte string — same `Match` semantics as `keyword`, materially less RAM on the five fields every query touches. `source_status` is not a UUID, so it stays `keyword`. `is_tenant=True` co-locates one org's vectors on disk so a tenant-scoped query is a sequential read; it is a **locality hint, not access control** — it enforces nothing. `m=0` + `payload_m=16` is Qdrant's documented multitenant pattern: no collection-wide graph, one graph per `org_id`, so a query never traverses another tenant's region.

Scalar int8 (4× compression) rather than binary or 1-bit TurboQuant: Qdrant's own guidance is that 1-bit compression *"resulted in significant data loss and precision drops for vectors smaller than a thousand dimensions"*, and BGE-M3's 1024 dims sit exactly on that boundary. Move to 4-bit TurboQuant (8×) only behind a recall measurement (docs/16-evaluation.md §21).

### The tenant-filtered hybrid query — `services/ai-service/app/retrieval/search.py`

```python
from qdrant_client import QdrantClient, models
from app.retrieval.tenancy import tenant_filter   # kb-tenancy-isolation owns this

RRF_K = 61  # textbook RRF k=60. Qdrant scores 1/((pos+1)/w + k - 1) with pos 0-based,
            # i.e. 1/(rank + k - 1) — so Qdrant's k is the literature's k PLUS ONE.
            # The server default is 2, which is textbook k=1: ~30x sharper than intended.

def hybrid_search(client: QdrantClient, ctx, allowed_version_ids: list[str],
                  dense: list[float], sparse: models.SparseVector,
                  branch_k: int = 20, limit: int = 20) -> list[models.ScoredPoint]:
    """Stages 7-9 of kb-rag-query-contract. `f` is positional and required: an
    unfiltered call must be unrepresentable, not merely discouraged."""
    f = tenant_filter(ctx, allowed_version_ids)   # raises EmptyScopeError on an empty scope

    response = client.query_points(
        collection_name=COLLECTION,
        prefetch=[
            # filter= on EVERY leaf. The server would propagate the root filter down,
            # but QdrantClient(":memory:") does not — see Gotchas. Never rely on it.
            models.Prefetch(query=dense,  using="dense",  limit=branch_k, filter=f),
            models.Prefetch(query=sparse, using="sparse", limit=branch_k, filter=f),
        ],
        query=models.RrfQuery(rrf=models.Rrf(k=RRF_K)),  # NOT FusionQuery — it cannot carry k
        query_filter=f,          # top level is `query_filter`; Prefetch's is `filter`
        limit=limit,
        with_payload=["chunk_id", "source_id", "source_version_id", "page", "url"],
        with_vectors=False,      # a returned vector is recoverable plaintext (Morris et al. 2023)
        timeout=5,
    )
    return response.points       # QueryResponse.points, not a bare list
```

Writes, counts and deletes use the same builder and the same explicit flags:

```python
client.upsert(COLLECTION, points=[models.PointStruct(
    id=str(chunk_id),                                    # UUIDv7 or uint only — no arbitrary strings
    vector={"dense": d, "sparse": s},                    # both branches, one point
    payload=payload)], wait=True)                        # wait=True or §13.5's count check is a lie

client.delete(COLLECTION, points_selector=models.PointIdsList(points=point_ids), wait=True)
client.delete(COLLECTION, points_selector=models.FilterSelector(filter=orphan_sweep_filter),
              wait=True)                                 # sweep only — kb-deletion-and-verification
client.count(COLLECTION, count_filter=identity_filter, exact=True).count == 0
```

Emit `kb_vector_upsert_duration_seconds{collection}` and `kb_vector_upsert_points_total{collection,outcome}` on writes, and `kb_retrieval_duration_seconds{stage="dense"|"sparse"|"fuse"}` on the query path (`kb-observability-conventions`). `collection` is a bounded label because collections are per embedding model, never per tenant.

**Not defined here.** The four filter terms and the payload contract → `kb-tenancy-isolation`. Stage order, top-Ks, thresholds → `kb-rag-query-contract`. Deletion ordering and the four verification checks → `kb-deletion-and-verification`. What fills the vectors → `bge-m3-embeddings`. What fills the payload → `kb-chunking-rules`.

## Gotchas

- **A search returns every organization's chunks, nothing raised, and the filter looks present in review.** `Filter(must=[])` is a **match-all**, confirmed in `lib/segment/src/index/query_optimization/query_checker.rs` at v1.18.3: `check_must` evaluates `conditions.iter().all(check)`, and `.all()` over an empty collection is `true`. So `filter or models.Filter()`, a `Filter()` default argument, and a conditionally-built condition list that came out empty are all **full-collection scans across every tenant** wearing the shape of a filtered query. The asymmetry makes it worse: `should: []` uses `.any()` and matches *nothing*, so the same mistake in `should` fails loudly while in `must` it fails silently. Filters are positional, required, and raise on empty scope. This is the single most important paragraph in this file.
- **A query missing its per-prefetch filters passes every unit test and leaks nothing in production — until either end changes.** The propagation is inverted between the two runtimes. The **server** merges the root filter into every prefetch leaf (`lib/shard/src/query/planned_query.rs`: `let filter = Filter::merge_opts(propagate_filter.clone(), filter);` under the comment *"Filters are propagated into the leaves"*) — undocumented, source-only, and it ANDs correctly only because our terms are in `must`; `merge_owned` concatenates clause lists per type, so a tenant term in `should` would widen to `tenant OR anything_else`. `QdrantClient(":memory:")` does the opposite: `local_collection.py::_merge_sources` takes the fusion branch, ignores `query_filter` entirely, and calls `self.retrieve(ids, ...)` — unfiltered by construction. So the same broken query **leaks under the in-memory client that unit tests use and is safe in production by an accident nobody wrote down.** Put `filter=` on every leaf, and run isolation tests against a real Qdrant container with two seeded orgs and a canary string.
- **A point ID resolves across the tenant boundary and nothing complains.** `retrieve()` takes no filter parameter at all. Neither does `lookup_from`, and `WithLookup` has no filter field while its `with_payload` defaults to **`true`** server-side — so `query_points_groups` + `with_lookup` pulls whole payloads out of another collection with no tenant condition anywhere. Never expose a point id to a client; authorize any id-addressed fetch against PostgreSQL first.
- **A verification job passes against a collection still absorbing writes — or an ingest that "succeeded" is not searchable.** The wire defaults and the Python client's defaults are **opposite**, and the docs describe the wire. REST `UpdateParams.wait` is `#[serde(default)]` on a `bool`, i.e. `false` — an acknowledgement of receipt, not a commit. `qdrant-client` defaults `wait=True` and explicitly sends it. Likewise `count`: the docs prose says *"the count is approximate for performance reasons"*, but `CountRequestInternal::default_exact()` returns `true`, the gRPC handler unwraps to the same, and the client defaults `exact=True` — the prose is a **docs bug**. Pass both explicitly anyway: the disagreement becomes moot, and a `curl`, a Laravel smoke check, or a raw-REST rebuild script gets the wire default, not the client's. Watch the near-miss: `SearchParams.exact` is a *different* field meaning "brute-force this search", and it does default to `false`.
- **Hybrid search suddenly favours whatever the sparse branch returned first, on queries where dense was obviously right.** Qdrant's `DEFAULT_RRF_K = 2` versus the 60 used by Elasticsearch, OpenSearch and the RRF literature — roughly a 30× difference in how sharply rank 1 dominates. Two traps stacked: `models.FusionQuery(fusion=models.Fusion.RRF)` has **no field for k**, so the shape most examples show can only ever use the default; k arrives via `models.RrfQuery(rrf=models.Rrf(k=...))`, added in server 1.16.0. And Qdrant's formula is `1/((pos+1)/weight + k - 1)` with `pos` 0-based, so **Qdrant's k is the literature's k plus one** — passing `k=60` gives you textbook 59. Set `RRF_K = 61`, and snapshot it in the `retrieval_configuration_version` (`kb-rag-query-contract` §12.9).
- **The bot never refuses, however irrelevant the corpus.** Somebody put `score_threshold` on the fused query. RRF discards magnitude by construction — only ranks enter the formula — so the top fused candidate scores the same whether it is a verbatim match or noise, and a threshold there can only ever cut the tail. The evidence threshold lives on the `bge-reranker` logit scale and nowhere else (`kb-rag-query-contract` §12.12).
- **Server-side fusion returns the answer but destroys the trace.** `query_points` with `prefetch` hands back one fused score per point; §12.9 and the admin playground (§8.24) require *dense rank, dense score, sparse rank, sparse score, and fused score* per candidate. Where the trace is mandatory — i.e. the production chat path — issue the two branches as separate filtered `query_points` calls (or one `query_batch_points`) and fuse in Python at the same `RRF_K`. Server-side fusion is for the eval harness and for the playground's "what would server fusion have done" comparison. The two must be configured from the same value, or an eval result does not describe production.
- **`AttributeError: 'QdrantClient' object has no attribute 'search'` after a dependency bump.** `search`, `search_batch`, `recommend` and `discover` were **removed** — not deprecated — from the client in 1.16.0, and server 1.19.0 deletes the matching REST endpoints. Every tutorial, blog post and StackOverflow answer written before late 2025 shows the dead API. `query_points` and `query_batch_points` are the only search surface.
- **Every filtered query is slow and `is_tenant` seems to do nothing.** Payload indexes were created after ingestion. Extra HNSW edges for a payload field are generated only once that field's index exists, and tenant co-location happens during optimization — both apply going forward, not retroactively. Index first, then upsert; to repair, force a re-index by bumping `ef_construct` by 1, which rebuilds every segment.
- **A sparse-only tuning change makes no difference, or `sparse` returns far more than `limit`.** Sparse vectors use an exact inverted index: no HNSW, no quantization, no approximation, and `SparseVectorParams` has exactly two fields (`index`, `modifier`). The unit trap follows: `SparseIndexParams.full_scan_threshold` counts **vectors**, while the dense `HnswConfigDiff.full_scan_threshold` counts **kilobytes**. Same name, same collection, different units.
- **Sparse recall collapses after someone enables `modifier=IDF` "because that is what the docs show".** IDF is for BM25-style vectors carrying raw term frequencies. BGE-M3's weights are already learned term importance, so IDF double-counts rarity — `bge-m3-embeddings` owns this ruling; leave the modifier unset. It matters for tenancy too: with IDF on, document frequencies are computed **collection-wide across every tenant** by default, which is both a relevance error and a weak cross-tenant statistical oracle. Server 1.19.0 adds `SearchParams(idf=models.IdfCorpusParams(corpus=<org filter>))` to scope it; if a BM25 branch is ever added, that scoping is mandatory, not optional.
- **A whole batch fails with a point-id validation error on the first upsert of a new ingest path.** Qdrant accepts only an unsigned integer or a UUID as a point id. `chunk_id` is UUIDv7 and satisfies this; a composite key like `f"{source_id}:{seq}"` does not. Keep the point id equal to `chunk_id` and store it back into `chunks.vector_point_id`, or deletion loses its only stable handle (§8.17).
- **A query with `using=` omitted errors or silently searches the wrong branch.** A collection with named vectors has no default vector, so `using` is required on every `Prefetch` and on any single-branch `query_points`. The names `"dense"` and `"sparse"` are part of the collection contract — changing one is a re-index, not a rename.
- **A chunk is indexed but never retrievable by the sparse branch.** Its `models.SparseVector` had empty `indices`/`values` — an all-stopword or whitespace chunk. An empty sparse vector matches nothing, forever, and no error is raised at upsert. `bge-m3-embeddings` handles the conversion; assert non-empty lexical weights on the first batch of every ingest run.
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
- [ ] `RRF_K` is set from config (61 for textbook 60), recorded in `retrieval_configuration_version`, and identical between the server-fusion path and the Python fusion path.
- [ ] Payload indexes for all six payload fields are created in the collection bootstrap **before** any upsert, with `is_tenant=True` on `org_id` only; `hnsw_config` sets `m=0` and `payload_m=16`.
- [ ] Point id is `uuid5(POINT_NS, f"{org_id}:{source_version_id}:{seq}")` (`kb-source-lifecycle`) and is written back to `chunks.vector_point_id` in the same transaction as the chunk row. Re-running the indexer for a version produces byte-identical ids — assert that in a test, because the failure mode is a silent doubling of every vector rather than an error.
- [ ] Every upsert and delete passes `wait=True` explicitly; every verification `count` passes `exact=True` explicitly; no code relies on either default.
- [ ] Collection bootstrap is idempotent and re-runnable, and a full rebuild from PostgreSQL + object storage reproduces the collection (§15.5) — proven by a restore drill, not asserted.
- [ ] `kb_vector_upsert_duration_seconds`, `kb_vector_upsert_points_total`, and `kb_retrieval_duration_seconds{stage}` are emitted with `collection` as the only collection-shaped label.
- [ ] No `with_vectors=True` on any tenant-facing read path; payload projections list fields explicitly rather than returning the whole payload.

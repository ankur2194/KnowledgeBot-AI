---
name: kb-source-lifecycle
description: Identity, lifecycle state, versioning, and atomic-publication doctrine for KnowledgeBot knowledge sources. Use whenever writing or reviewing ingestion orchestration, source state transitions, version activation, idempotency keys, reprocess triggers, or restart-after-failure logic — anywhere source_items, source_versions, or the active-version pointer are touched. Owns the states up to and including Deleting; physical removal is kb-deletion-and-verification. Pairs with celery-workers (how the stages run).
---

# Source Lifecycle, Versioning, and Atomic Publication

Doctrine skill — no library of its own. Binds PostgreSQL (source of truth), Qdrant (derived index), and the Celery ingestion workers in `services/ai-service/`.
**Authoritative spec:** docs/03-functional-knowledge-sources.md §8.9, §8.14, §8.16, docs/08-ingestion-pipeline.md §13, docs/10-embedding-indexing.md §15.4–15.5, docs/11-data-model.md §16.4

## Non-negotiables

- **A version becomes searchable only after it is fully indexed *and* verified.** The prior version keeps serving until the pointer switch. Marking ready before verification is the single failure that users see as "the bot only knows half the document" (§8.9, §13.5).
- **Activation is a pointer, never a status column.** `source_items.current_version_id` is the only thing that makes a version live. Enforce it in the schema: `CREATE UNIQUE INDEX ... ON source_versions (source_item_id) WHERE retired_at IS NULL AND activated_at IS NOT NULL`. A boolean `is_active` lets two rows both claim it after a race and there is no single statement that flips them together.
- **The idempotency key covers content *and* every processing configuration version.** A content-only key silently swallows parser, OCR, chunker, and embedding-model changes — the reprocess returns "already done" and nothing changes (§13.3, §13.4).
- **Every retrieval query filters active source status and active source version.** This whole skill is worthless if one query omits the version predicate — a retired version keeps answering. Filter contract: `kb-tenancy-isolation`; query stages: `07` §12.6.
- **PostgreSQL is authoritative; Qdrant is derived and rebuildable** (ADR-010, §15.5). Lifecycle state lives in `source_versions`, never inferred from what happens to be in the collection.
- **Every point is traceable to org → bot → source → source_item → source_version → element → chunk** (§8.17). The version component is what makes both the switch and the orphan sweep possible.

## How we use it

### Three levels of identity

| Level | Table | What it is | Versioned? |
|---|---|---|---|
| Source | `knowledge_sources` | the admin's unit of intent — one upload, one sitemap, one crawl config | no |
| Source item | `source_items` | **the unit of independent versioning** — one page, one uploaded file | no |
| Source version | `source_versions` | one immutable processing result: content hash + parser/OCR/chunker/embedding config versions + status | is the version |

**Every source has at least one item — single-file uploads get a row too.** Do not special-case a one-item source; the pointer switch, the missing-page counter, and citation provenance all key off `source_item_id`, and code that reads `source.current_version_id` for uploads but `item.current_version_id` for crawls will diverge the first time someone adds a second file.

**Why a sitemap page is its own item.** A nightly recrawl of a 400-page site typically changes 3 pages. If the sitemap were one item, one changed page would force a new version of all 400 — reparse, re-embed, reindex, and a switch that flips the entire site at once, so retrieval is either wholly stale or wholly new with no middle. Per-page items let 3 versions publish while 397 sit untouched, give `missing_count` somewhere to live for the missing-page policy (§8.14), and let a citation name a URL rather than "the sitemap".

### What creates a new version

Six triggers (§13.4). All of them must be reachable from the idempotency key, or the trigger is a no-op:

| Trigger | Key component that changes |
|---|---|
| Uploaded content or crawled page changed | `content_hash` |
| Parser configuration changed | `parser_cfg_version` |
| OCR configuration changed | `ocr_cfg_version` |
| Chunking strategy changed | `chunker_cfg_version` |
| Embedding model changed | `embedding_model_version` |
| Administrator requests reprocess | `force_nonce` (the reprocess request id) |

Only one version is active per **item** at a time. Hash the *normalized* content for crawls (§8.14 step 5) and the *raw bytes* for uploads — see Gotchas.

```python
# services/ai-service/app/ingestion/identity.py
INGEST_KEY_SCHEME = "v1"   # bump only to force a global reprocess; it invalidates every key

def ingest_key(req: IngestRequest) -> str:
    # §13.3 minus the circular "source version" (the key is what decides whether a
    # version exists) plus ocr_cfg_version, which §13.3 omits and §13.4 requires.
    parts = [INGEST_KEY_SCHEME, req.org_id, req.source_id, req.source_item_id,
             req.content_hash, req.parser_cfg_version, req.ocr_cfg_version,
             req.chunker_cfg_version, req.embedding_model_version,
             req.force_nonce or ""]
    return hashlib.sha256("|".join(parts).encode()).hexdigest()
    # persisted on source_versions; UNIQUE (source_item_id, ingest_key) is the dedup.
```

### The state set

The enum is the union across all three levels; each level uses a subset. Processing states (`Fetching`…`Indexing`) are **version** states rolled up for display onto the item and source. `Draft` and `Archived` are source-level only. "Retrievable" always means *through the active-version pointer* — points can exist in Qdrant and still be unreachable.

| State | Legal next | Retrievable | Meaning |
|---|---|---|---|
| Draft | Queued, Deleted, Archived | no | created, never submitted; no version exists |
| Queued | Fetching, Failed, Deleting | prior version serves | accepted, idempotency key resolved, awaiting a worker |
| Fetching | Parsing, Failed, Deleting | prior version serves | acquiring bytes or crawling the URL |
| Parsing | Normalizing, Failed | prior version serves | Docling / OCR extraction (`docling-parsing`) |
| Normalizing | Chunking, Failed | prior version serves | structural normalization + cleanup, `document_elements` written |
| Chunking | Embedding, Failed | prior version serves | `chunks` rows written (`kb-chunking-rules`) |
| Embedding | Indexing, Failed | prior version serves | dense + sparse representations built |
| Indexing | Ready, Ready with warnings, Failed | **no** — points exist but the pointer still names the old version | upsert + verification |
| Ready | Queued, Disabled, Deleting, Archived | **yes** | verified and activated |
| Ready with warnings | Queued, Disabled, Deleting, Archived | **yes — identical to Ready** | activated; parser/OCR warnings are advisory only (§8.11) |
| Failed | Queued, Disabled, Deleting, Archived | no new content; **prior version keeps serving** | terminal for this run, reviewable failed-job record (§13.7) |
| Disabled | Ready, Ready with warnings, Deleting, Archived | no — excluded by the status filter immediately (§8.17) | vectors retained, re-enable is instant |
| Deleting | Deleted | no | physical removal in flight — **hand off to `kb-deletion-and-verification`** |
| Deleted | *terminal* | no | removal verified |
| Archived | Ready, Deleting | no | metadata + objects retained, vectors dropped; restore by rebuild (§15.5) <!-- UNVERIFIED: spec names Archived but never defines its semantics --> |

Transitions not in that table are bugs. In particular: `Indexing → Ready` without a passing verification, `Ready → Deleted` skipping `Deleting`, any edge out of `Deleted`, and `Failed → Ready` without a new run.

> **15 states, not 14.** §8.9 enumerates 15; early summaries of the spec collapsed `Ready` and `Ready with warnings` into one, since they differ only in the warning summary and behave identically for retrieval. Implement 15 enum values.

### The activation sequence — ordering is the whole point

```python
# services/ai-service/app/ingestion/publish.py
POINT_NS = uuid.UUID("6f1e1b1e-0000-4000-8000-000000000001")  # frozen forever; it *is* point identity

def point_id(c: Chunk) -> str:
    # Deterministic, so a retried upsert overwrites what it already wrote instead
    # of adding a second copy. Qdrant ids may only be u64 or UUID, so the readable
    # key is folded through uuid5 rather than passed as a string.
    return str(uuid.uuid5(POINT_NS, f"{c.org_id}:{c.source_version_id}:{c.seq}"))

def publish_version(v: SourceVersion, chunks: list[Chunk]) -> None:
    # 1. INDEX under the NEW version id. Retrieval filters on the item's active
    #    version, so these points are written but unreachable — this is safe.
    for batch in batched(chunks, 128):
        qdrant.upsert(collection_name=v.collection,
                      points=[to_point(point_id(c), c) for c in batch],
                      wait=True)   # default is false = an ack, not a commit

    # 2. VERIFY expected chunk count. exact=True — the default count is approximate.
    indexed = qdrant.count(v.collection, count_filter=version_filter(v), exact=True).count
    if indexed != len(chunks):
        raise VerificationFailed(v.id, expected=len(chunks), found=indexed)
        # -> Failed. The prior version was never touched and still serves.

    # 3. REPORT READINESS. The data plane's last act. ADR-012: FastAPI writes only
    #    the tables in ALLOWED_TABLES (app/db/writes.py) — never source_versions,
    #    never source_items, and it never performs an activation.
    report_ingestion_status(v.id, state="indexed_verified", chunk_count=indexed)
```

**Steps 4–6 are Laravel's, inside one transaction, on that callback** (ADR-012). Readers move at
commit, so no query observes two active versions or none:

```php
DB::transaction(function () use ($v) {
    $priorId = SourceItem::whereKey($v->source_item_id)   // 4. MARK READY + SWITCH POINTER
        ->lockForUpdate()->value('current_version_id');
    SourceVersion::whereKey($v->id)->update([
        'status' => $v->terminalReadyStatus(), 'activated_at' => now(),
    ]);
    SourceItem::whereKey($v->source_item_id)->update(['current_version_id' => $v->id]);
    if ($priorId) {                                       // 5. RETIRE the old row
        SourceVersion::whereKey($priorId)->update(['retired_at' => now()]);
    }
});
// 6. INVALIDATE caches keyed to the retired version (§13.2 stage 18), then delete its
//    vectors only after a grace period — in-flight answers still hold its ids.
InvalidateAnswerCache::dispatch($v->source_item_id);
if ($priorId) DeleteRetiredVectors::dispatch($priorId)->delay(RETIREMENT_GRACE_SECONDS);
```

Do not reorder. Verify before ready, ready before switch, switch before retire, retire before delete.

**Why the split is not bureaucracy.** The verification in step 2 is a data-plane fact — only the
worker that wrote the points can count them — while the pointer flip is the single act that decides
what every tenant's next query sees. Putting both in the worker gives two services write access to the
one column the partial unique index guards, and the failure is not a race that loses a write: it is an
`IntegrityError` raised inside a Celery task, which retries, which raises again. The ingest reports
success, the bot keeps answering from the previous version, and the queue quietly burns a worker
forever. Laravel is where activation belongs because Laravel is where the audit row, the policy check,
and the retention clock already are.

### Restartable failure recovery

A stage is complete only when its output is committed to PostgreSQL or object storage. On restart, resume at the first stage whose output is missing — never mid-stage, never from the top. The four safe boundaries, what each commits, and what a retry after it must **not** redo → **[references/restartable-recovery.md](references/restartable-recovery.md)**.

### Not this skill

- Physical vector/payload/object removal and the post-delete verification job → `kb-deletion-and-verification` (this skill hands off at `Deleting`).
- Chunk sizing, boundaries, and chunk metadata fields → `kb-chunking-rules`.
- Celery task wiring, queues, routing, and retry decorators → `celery-workers`.
- Docling parser options, OCR engine selection, warning taxonomy → `docling-parsing`.
- Crawl scheduling, ETag/Last-Modified checks, URL discovery → `crawl4ai-crawler`.

## Gotchas

- **Half a document shows up in answers.** `qdrant.upsert` defaults to `wait=false` — the response acknowledges receipt, not commit. Verifying or switching the pointer on that ack reads a collection still absorbing writes: it passes on a warm collection under light load and exposes a partial version under a bulk reindex. Pass `wait=True` on every ingestion upsert. Cost is throughput, and ingestion is async anyway.
- **Verification passes on a short index.** Qdrant's `count` is approximate by default; mid-optimization it can be off by hundreds. Comparing an approximate count against `len(chunks)` is theatre — it both false-passes and false-fails. Always `exact=True`.
- **Duplicate vectors after a retry.** Random point ids plus Celery's at-least-once delivery means a retry after a partially-successful upsert writes a *second* copy of every chunk it already wrote. Retrieval then returns the same passage twice, the context budget is spent on duplicates, and the deletion query removes only one set. Derive ids with `uuid5(POINT_NS, org:version:seq)`. **Never rotate `POINT_NS`** — every existing point instantly becomes an orphan no deletion query can reach.
- **Two workers ingest the same version at once.** The Valkey/Redis Celery transport hides a task for `visibility_timeout` (default 3600s) and redelivers it if unacked; a 90-minute OCR run gets picked up by a second worker while the first is still going, and both race to switch the pointer. Set `broker_transport_options={'visibility_timeout': ...}` above the longest ingestion *and* take a Valkey lock on `source_item_id` for the whole run. Wiring: `celery-workers`.
- **An OCR config change silently no-ops.** §13.3's key list omits the OCR configuration version, yet §13.4 makes an OCR config change a version trigger. Content hash unchanged + key unchanged = the request dedupes against the completed run, the admin sees "already processed", and the new OCR settings never land. `ocr_cfg_version` is in the key above deliberately. The same trap kills the explicit reprocess button when nothing else changed — that is what `force_nonce` is for.
- **A crawl source re-versions every page every night.** Hashing raw HTML catches rotating CSRF tokens, render timestamps, ad slots, and "N users online" — every page looks changed, so every page re-embeds, reindexes, and switches nightly. Hash the *normalized* content (§8.14 step 5). Uploads are the reverse: hash the raw bytes, because normalization is downstream of the parser and the parser config is already a separate key component.
- **Orphaned vectors from a killed reprocess.** A run that dies between upsert and pointer switch leaves a complete set of points for a version that never activated. They are invisible to retrieval (the version filter excludes them) *and* invisible to deletion (deletion walks the active chunk set), so they accumulate silently until the collection is oversized and slow. Sweep on `source_versions` where `activated_at IS NULL`, status is terminal, and the row is older than the grace period. The escape hatch is a rebuild (§15.5) — Qdrant is derived, so nuking and rebuilding a collection is always legal.
- **Deleting the retired version's vectors immediately breaks in-flight answers.** A request that already retrieved chunk ids from the old version and is still reranking, packing, or generating will cite points that no longer exist. Hold `RETIREMENT_GRACE_SECONDS` at or above the maximum answer latency plus the answer-cache TTL.
- **`Disabled` is not `Deleting`.** Disabled excludes the source from retrieval immediately via the status filter but keeps every vector, so re-enabling is a metadata write. Deleting is one-way and verified. Disabling is not a way to reclaim storage; deleting is not a way to hide something for a week.

## Official docs

- [Qdrant — Points](https://qdrant.tech/documentation/concepts/points/) — `wait` semantics, permitted id types (u64 / UUID), upsert idempotency by id.
- [Qdrant — Counting points](https://qdrant.tech/documentation/concepts/points/#counting-points) — the `exact` flag.
- [Celery — Redis broker transport options](https://docs.celeryq.dev/en/stable/getting-started/backends-and-brokers/redis.html) — `visibility_timeout` and redelivery.
- [PostgreSQL — partial indexes](https://www.postgresql.org/docs/current/indexes-partial.html) — how the single-active-version constraint is enforced.
- [OpenSearch alias blue/green indexing](https://aimconsulting.com/insights/opensearch-aliases-blue-green-deployments/) — the same pointer-switch pattern in a search engine, useful as a sanity check on ordering.

## Definition of done

- [ ] Every source, including single-file uploads, has a `source_items` row; nothing reads a version pointer off `knowledge_sources`.
- [ ] Partial unique index proves at most one active version per `source_item_id`; a concurrent double-publish test hits it.
- [ ] `ingest_key` includes org, source, item, content hash, parser, OCR, chunker, and embedding-model versions, plus `force_nonce`; a test changes *only* the OCR config version and asserts a new version is created.
- [ ] Every ingestion upsert passes `wait=True`; every verification count passes `exact=True`.
- [ ] Point ids are `uuid5`-derived; re-running a completed publish produces zero net new points.
- [ ] Verification failure leaves the prior version active and the source in `Failed` — asserted by an integration test that kills the run mid-upsert.
- [ ] Retirement deletes old vectors on a delay, not inline with the switch.
- [ ] Restart from each safe boundary resumes without repeating the prior stage.
- [ ] Every state transition emitted is in the table above; illegal transitions raise rather than log.

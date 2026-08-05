---
name: kb-deletion-and-verification
description: Knowledge removal doctrine for KnowledgeBot AI — the two-phase delete (immediate logical exclusion, then background purge of PostgreSQL, Qdrant, SeaweedFS and Valkey) and the verification job that proves it. Use whenever writing or reviewing disable/delete paths, deletion workers, retention or legal-hold handling, or debugging a deleted source that still answers. Owns deletion ordering and proof; earlier lifecycle states belong to kb-source-lifecycle. Pairs with qdrant-hybrid-search (delete-by-filter syntax).
---

# Knowledge Deletion and Verification

Spans PostgreSQL (truth), Qdrant (derived index), SeaweedFS/S3 (objects), Valkey (caches, locks): phase 1 in `services/core-api/` (Laravel), phase 2 + verification in `services/ai-service/` (Celery). No image versions are pinned in the spec yet — pin them in `infrastructure/docker` before implementation.
**Authoritative spec:** docs/03-functional-knowledge-sources.md §8.17 §8.14, docs/01-product-scope.md §7.5, docs/13-security.md §18.10 §18.11, docs/11-data-model.md §16.4, docs/20-roadmap-mvp-demo.md §32 §33

Acceptance criteria 9 and 10 are a *demo step*: the warranty source is deleted on stage and the warranty question is re-asked (§33 steps 14–15). A surviving vector is not a latent bug, it is a visible product failure in front of the audience.

## Non-negotiables

- **Logical exclusion commits before the first byte is physically removed.** Flip status, retire the active version, invalidate caches, `COMMIT` — *then* enqueue the purge. Reverse it and a query already past the filter stage retrieves a half-deleted version: chunk rows gone but points alive, or points alive but the object holding the citation excerpt already swept. The user sees a citation that resolves to nothing, which is worse than the stale answer you were trying to prevent.
- **Deletion targets stable identifiers, never text matching** (§8.17). A payload-text or content-hash match deletes another source's identical boilerplate — headers, disclaimers and pricing tables repeat verbatim across documents, so a text-matched delete silently cuts a hole in a source nobody touched.
- **`Deleted` is written by the verification job, not by the delete call returning.** A 200 from Qdrant means the request was accepted, not that the org's points are gone. Until all four checks pass the source stays `Deleting`.
- **Verification must not reuse the retrieval filter.** Retrieval filters already exclude inactive status and non-active versions (`kb-tenancy-isolation`, docs/07 §12.6), so counting with that filter proves only that excluded points are excluded. Count on the bare identity (`org_id` + `source_id`) with no status predicate.
- **Removing knowledge is not removing the file.** The original object obeys the retention policy independently (§8.17, §7.5 step 7). A source can legitimately end as *knowledge removed, original retained* — the admin view must say so (§8.17 "remaining retention obligations"), not report a clean delete.
- **Every disable and delete is audited** (§18.11) with a before/after summary that carries identifiers and counts only. Never write chunk text into `audit_logs` — that recreates in the audit table the content you were asked to erase.

## How we use it

### Two phases, one boundary

| | Phase 1 — logical exclusion | Phase 2 — physical removal |
|---|---|---|
| Where | `core-api`, inside the admin request | `ai-service` Celery worker |
| When | synchronous, before the API responds | after phase 1 commits |
| Effect | source unreachable by *new* queries | artifacts destroyed |
| Reversible | yes (this is what `Disabled` is) | no |
| Failure mode | request fails, nothing changed | job retries, state stays `Deleting` |

`Disabled` is phase 1 with phase 2 deliberately skipped — vectors stay, so re-enabling is instant. Only `Deleting` runs phase 2. Lifecycle states and their legal transitions: `kb-source-lifecycle`.

### The identity chain

Every Qdrant point payload and every derived object key carries the full chain, so any level is a deletable handle:

`organization → bot assignment → source → source item → source version → document element → chunk`

Target the **narrowest** level that matches the intent, and always `AND org_id`:

| Intent | Delete key |
|---|---|
| Retire prior version after recrawl (§13.5) | `source_version_id` (exact, known) |
| One crawled page disappeared | `source_item_id` |
| Delete a source | `source_id` — **not** the version ids |
| Unassign a bot | not a delete at all; the assignment row changes, points stay |
| Delete an organization | `org_id` |

`org_id` is redundant for correctness when you already have a `source_id`, and mandatory for safety: with it, a wrong `source_id` deletes nothing; without it, it deletes someone else's source.

### The ordered sequence

1. Admin confirms delete → `core-api` authorizes, writes the audit entry.
2. `knowledge_sources.status` → `Deleting`; `current_version_id` cleared; every `source_versions.retired_at` set.
3. Bot assignments deactivated (retrieval's bot-access predicate now fails).
4. Retrieval and answer caches for the org+source invalidated.
5. **`COMMIT`.** Everything above is one transaction; the API responds only after it.
6. Purge job enqueued with `(org_id, source_id, job_id)` — *ids only*, never a snapshot of version ids.
7. Worker takes a Valkey lock on the source, resolves **all** version ids from PostgreSQL now.
8. Qdrant points deleted by id (`chunks.vector_point_id`) with `wait=true`, then an `org_id`+`source_id` filter sweep for orphans (and for retries after step 10 already removed the chunk rows).
9. Derived objects swept by version-scoped prefix; shared extracted images dropped only when unreferenced.
10. Relational rows deleted in one transaction: `chunks` → `document_elements` → `source_versions` → `source_items`.
11. Caches invalidated a **second** time — a query in flight during step 5 can have repopulated them.
12. Original object deleted or marked retained per retention policy; the decision is recorded either way.
13. Verification job runs the four checks against the primary.
14. On pass, `core-api` writes `Deleted` plus the retained-object report. On fail, the source stays `Deleting` and the reaper retries.

```python
# services/ai-service/app/deletion/tasks.py
@celery_app.task(bind=True, acks_late=True, max_retries=5)
def purge_source(self, org_id: str, source_id: str, job_id: str) -> None:
    """Phase 2. Idempotent and restartable — every step is a no-op on re-run.
    Never invoked before phase 1 has COMMITTED in core-api."""
    with valkey_lock(f"purge:{org_id}:{source_id}", ttl=900):  # TTL, so a killed worker self-heals
        # Resolve identity NOW, not at enqueue time: a recrawl may have activated
        # new versions in between, and a stale id list leaves their points alive.
        version_ids = pg.all_version_ids(org_id, source_id)   # active AND retired

        checkpoint(job_id, "vectors")
        # Delete by resolved point id, not by filter: `chunks.vector_point_id` already
        # holds every id, and Qdrant re-resolves a stored filter on WAL replay, so
        # filter-deleted points can return after a restart (see Gotchas).
        # Dense + sparse ride the same point — one delete removes both.
        vectors.delete_points(org_id, pg.point_ids(version_ids), wait=True)
        # Then one filter-scoped sweep, for points whose chunk row was already lost.
        vectors.delete_by_source(org_id=org_id, source_id=source_id, wait=True)

        checkpoint(job_id, "objects")
        for vid in version_ids:                                # normalized docs, OCR output
            objects.delete_prefix(derived_prefix(org_id, source_id, vid))
        objects.delete_unreferenced_images(org_id, source_id)  # content-hash-deduped images

        checkpoint(job_id, "relational")
        with pg.transaction():
            pg.delete_chunks(version_ids)
            pg.delete_document_elements(version_ids)
            pg.delete_versions_items_and_assignments(org_id, source_id)

        checkpoint(job_id, "caches")
        caches.invalidate_source(org_id, source_id)             # keyspace: `valkey-keyspaces`

    verify_source_purged.delay(org_id, source_id, job_id)


@celery_app.task
def verify_source_purged(org_id: str, source_id: str, job_id: str) -> None:
    """The four checks of §8.17. Reads the PRIMARY — replica lag reads as success."""
    failed = []
    if pg.primary.count_chunks(org_id, source_id):            failed.append("chunks")
    if vectors.count_exact(org_id, source_id):                failed.append("vectors")   # exact, no status predicate
    if pg.primary.count_active_assignments(source_id):        failed.append("assignments")
    if caches.any_key_for_source(org_id, source_id):          failed.append("cache")
    if failed:
        raise DeletionVerificationFailed(source_id, failed)    # stays `Deleting`; reaper re-runs
    core_api.mark_deleted(org_id, source_id,
                          retained=pg.retention_obligations(source_id))
```

### Artifact inventory

| Artifact | Store | Targeted by | Verified by |
|---|---|---|---|
| Dense vectors | Qdrant | point ids from `chunks.vector_point_id`, then a `source_id` filter sweep | `count(exact=true)` on `org_id`+`source_id` = 0 |
| Sparse vectors, chunk payloads | Qdrant | the same points — do **not** issue a second delete | same count |
| `chunks`, `document_elements` | PostgreSQL | `source_version_id IN (all versions)` | `count(*) = 0` on primary |
| `source_versions`, `source_items` | PostgreSQL | `source_id` | `count(*) = 0` |
| Bot assignments | PostgreSQL | `source_id` | no active assignment row |
| Normalized documents, OCR outputs | SeaweedFS | version-scoped key prefix sweep | **list object versions** under prefix = 0 |
| Extracted images | SeaweedFS | prefix, **plus** a reference check for hash-deduped images | 0 live referencing elements AND prefix empty |
| Cached retrieval results | Valkey | tracked key set per org+source | `EXISTS` on tracked set = 0 |
| Cached answers | Valkey | key carries the source-version fingerprint of its evidence | as above |
| Original upload | SeaweedFS | retention policy; may be **kept** | deletion report names the retained object and its release date |

### Missing pages on crawled sources (§8.14)

Four policies, and the default is *disable after repeated confirmation*, not delete — one 503 or one truncated sitemap makes every page look missing at once.

- `source_items.missing_count` increments only on a crawl run that **succeeded**. A failed or partial run must increment nothing, or a single bad sitemap fetch disables the whole site.
- Any successful rediscovery resets `missing_count` to 0. Consecutive means consecutive.
- Add a run-level circuit breaker: if a run would mark more than a configured fraction of known items missing, fail the run and apply no policy.
- "Disable after N" is phase 1 only. Only the "delete after a retention period" policy is allowed to enqueue phase 2.

### Not defined here

- Lifecycle states before `Deleting`, and which transitions are legal — `kb-source-lifecycle`.
- Qdrant filter, delete and count API syntax — `qdrant-hybrid-search`.
- The mandatory tenant filter contract — `kb-tenancy-isolation`.
- Cache key layout and namespacing — `valkey-keyspaces`.

## Gotchas

- **The deleted source still answers, and Qdrant is provably empty.** The answer came from the answer cache. Vectors are the loud artifact and caches are the quiet one; a cached answer outlives its evidence unless the key carries the source-version fingerprint of the evidence that produced it. Invalidate in phase 1 *and* again at the end of phase 2 — step 5's window lets a reader that loaded the value *before* the commit write it back *after*. This is not theoretical: Docker Distribution shipped exactly this bug (CVE-2026-35172) — the delete cleared the shared cache entry but left the scoped membership record, and the next read repopulated it and made the deleted blob readable again.
- **One version's points survive a source delete.** The job was enqueued carrying `current_version_id`, a recrawl activated a new version before the worker picked it up, and the delete filtered on the retired id. Enqueue identifiers only; resolve versions inside the job; delete sources by `source_id`. Version-scoped deletes belong only to §13.5's retire-prior-version step, where the id is exact and immediate.
- **Verification passes, then the content comes back in search.** Three silent causes. Qdrant's prose docs say `count` is approximate by default; the server, the gRPC handler, and the Python client all default it to **`true`**, so the docs are simply wrong here. The real exposure is a non-Python caller: a `curl` probe or a Laravel smoke check hits the REST wire defaults. Pass `exact: true` explicitly everywhere so the two paths cannot disagree. An approximate count merges per-segment cardinality estimates with no cross-segment dedup, so it is not a stale truth, it is a guess. Never verify with the collection's `points_count`, which is documented as approximate and inflates while the optimizer temporarily duplicates points. And the PostgreSQL checks must read the primary — replica lag reads as success.- **Points deleted by filter come back after a Qdrant restart.** A filter-based delete stores the *filter* in the WAL and re-resolves it against live segments on every apply, including replay — so replay is not a deterministic function of the log (qdrant#9575). Filter deletes are also applied in 512-point batches that release the segment write lock in between, so a concurrent search sees a half-deleted source. Delete by point id resolved from `chunks.vector_point_id`, and keep the filter delete as a follow-up sweep only. <!-- UNVERIFIED: fix merged to Qdrant `dev` 2026-07-07; not confirmed in a tagged release -->
- **Verification always passes and never catches anything.** The check reused the retrieval filter, so it asked "are any *active* points left for a source I already marked inactive?" — a tautology. Drop status and version predicates from the verification filter; keep only `org_id` + `source_id`.
- **A source is stuck in `Deleting` for days.** The worker was OOM-killed between steps 8 and 10. Nothing is wrong with the data — the state machine just has no way out. Every phase-2 step writes a `background_jobs` checkpoint, the whole task is idempotent, and a scheduled reaper re-enqueues anything in `Deleting` past a threshold. Never "fix" this by flipping the source back to `Ready`: half its chunks are gone and it will answer with holes.
- **Two workers purge the same source and the second one raises on a missing row.** Take a Valkey lock keyed on the source with a TTL, and make every step tolerate already-absent state. Retries after `acks_late` are normal operation, not an exception path.
- **Deleting the source deleted the conversation history.** `citations.chunk_id` points at `chunks`; a naive `ON DELETE CASCADE` takes past answers with it. Break the link, don't cascade: null the chunk reference and keep the citation's stored label and location so old transcripts stay readable. See the spec gap below.
- **Erasure is claimed while the excerpts are still in PostgreSQL.** `citations.excerpt`, `retrieval_traces.selected_evidence` and `evaluation_results.retrieved_evidence` each store verbatim source text and are **not** in §8.17's artifact list. For a source delete they may stay. For a data-subject erasure request they must be swept too — and so must the vectors, because inversion attacks recover 92% of 32-token inputs *exactly* from their embeddings ([Morris et al. 2023](https://arxiv.org/abs/2310.06816)), so "we kept only the vector" is not a defence. <!-- UNVERIFIED: no spec section assigns ownership of erasure sweeps over these conversation-side copies -->
- **Legal hold and erasure look like a contradiction; they are not.** GDPR Art. 17(3)(b)/(e) exempts data needed for a legal obligation or the defence of legal claims, and Art. 18 with Recital 67 names the mechanism — *restriction of processing*: "making the selected personal data unavailable to users," with the restriction "clearly indicated in the system." Art. 18(2) permits **storage**, not retrieval. So the split is exact: segregate and flag the original object, and still delete every embedding, chunk and cache entry over it. Use object-lock **governance** mode, never compliance mode — a compliance-mode lock cannot be shortened by anyone, root included, so applying one to data that may later face an erasure request creates a conflict with no technical exit.
- **The original is "deleted" and still in the bucket.** On a versioned S3 bucket a plain DELETE writes a delete marker; GET 404s while the bytes survive as a noncurrent version, still billed and still restorable. Sweeps must issue version-specific deletes (or a `NoncurrentVersionExpiration` lifecycle rule), and verification must **list object versions** under the prefix — listing keys returns empty and proves nothing.
- **A Qdrant delete is not erasure.** It flips a soft-delete bit. The point stops being returned immediately, but the raw vector bytes and the point's HNSW node stay in the segment until the vacuum optimizer rebuilds it — which needs that *segment* to be 20% deleted (`deleted_threshold`) **and** to hold at least 1000 vectors (`vacuum_min_vector_number`), so a small or lightly-deleted segment is never reclaimed on any timeline. Snapshots copy segments as-is, so a backup taken in between carries the deleted vectors and restoring it restores them — unswept backups are the single most common erasure failure regulators found in the EDPB's 2025 audit of 764 controllers. Search exclusion is what the four checks assert and what the demo needs; an erasure *attestation* additionally needs a forced re-index (bumping `ef_construct` by 1 rebuilds every segment) and a backup-expiry plan. <!-- UNVERIFIED: that Qdrant snapshots skip compaction is inferred from its source, not documented -->

## Official docs

- [Qdrant — delete points and filtering](https://qdrant.tech/documentation/concepts/points/#delete-points) — delete-by-filter, `wait`, and point-id semantics.
- [Qdrant — counting points](https://qdrant.tech/documentation/concepts/points/#counting-points) — `exact` vs estimated counts, which is what verification hangs on.
- [Qdrant — optimizer](https://qdrant.tech/documentation/operations/optimizer/) — vacuum thresholds; when soft-deleted vectors actually leave the segment.
- [GDPR Article 17 — right to erasure](https://gdpr-info.eu/art-17-gdpr/) and [Recital 67 — restriction of processing](https://gdpr-info.eu/recitals/no-67/) — the erasure obligation and the legal-hold mechanism that coexists with it.
- [EDPB — 2025 coordinated enforcement action on the right to erasure](https://www.edpb.europa.eu/system/files/2026-02/edpb_cef-report_2025_right-to-erasure_en.pdf) — 764 controllers audited; unswept backups and derived copies top the findings.
- [Text Embeddings Reveal (Almost) As Much As Text](https://arxiv.org/abs/2310.06816) — embedding inversion; why a stored vector is not anonymised content.
- [Celery — task idempotency and `acks_late`](https://docs.celeryq.dev/en/stable/userguide/tasks.html#ensure-your-tasks-are-idempotent) — the retry semantics phase 2 is written against.

## Definition of done

- [ ] Disabling a source excludes it from retrieval before the API responds, and leaves its vectors intact.
- [ ] Deleting a source commits status, version retirement, assignment deactivation and cache invalidation in one transaction *before* the purge job is enqueued.
- [ ] The purge job receives identifiers only; version ids are resolved inside the job.
- [ ] Every Qdrant and object-store delete carries `org_id` alongside the narrower identifier.
- [ ] No deletion path anywhere matches on chunk text, payload text or content hash.
- [ ] Vector deletes are issued by point id resolved from `chunks.vector_point_id`; any filter delete is a sweep, not the primary mechanism.
- [ ] The purge task is idempotent, and killing the worker mid-purge then re-running completes the deletion with no manual repair.
- [ ] A reaper re-enqueues sources left in `Deleting` past the threshold; the state is never manually reverted.
- [ ] Verification runs all four §8.17 checks on the primary, with an exact count and no status/version predicate.
- [ ] `Deleted` is written only by a passing verification; a failure leaves the source in `Deleting`.
- [ ] Integration test: index → answer from the source → delete → re-ask → refusal, with the answer cache warm from step 2.
- [ ] Cross-tenant test: purging org A's source leaves org B's identically-named source and its byte-identical boilerplate chunks intact.
- [ ] Object-store verification lists object *versions* under the prefix, not keys.
- [ ] A scheduled reconciliation sweeper compares Qdrant points against surviving PostgreSQL chunk ids and records its output — that log is the deletion evidence.
- [ ] The admin deletion view shows completion and any retained original with its release date.
- [ ] Audit entries record the deletion with identifiers and counts, and no source text.

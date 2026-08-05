---
name: valkey-keyspaces
description: The whole Valkey keyspace for KnowledgeBot AI as one designed namespace — instance and logical-DB split, every key family's pattern, TTL and eviction posture, the answer-cache fingerprint, locks, rate limiters and idempotency records. Use whenever adding a cache key, a lock, a counter, a queue, or a TTL in either service, or when jobs vanish, a deleted source keeps answering, or Valkey memory climbs. maxmemory-policy is server-wide, so one instance cannot serve both queues and caches. Pairs with kb-tenancy-isolation (org prefix) and celery-workers (broker).
---

# Valkey Keyspaces — One Namespace, Two Instances

Valkey **9.1.1** (2026-07-21; 9.0.5 is the conservative alternative — `DELIFEQ` needs ≥ 9.0.0, database-level ACL needs ≥ 9.1.0). Clients: `redis-py` via `kombu[redis]` in the data plane, `phpredis` in Laravel. Pin the image by digest in `infrastructure/docker`.
**Authoritative spec:** docs/05-tech-stack.md §9.8, docs/06-architecture.md §10.2, docs/14-reliability.md §19.5, docs/18-deployment-backup-cicd.md §25.1

Valkey is a fork of Redis 7.2 under the Linux Foundation (BSD-3), and half the ecosystem's documentation still says "Redis". Everything we speak is the Redis wire protocol; the URL scheme is `redis://` (`valkey://` raises `No such transport` in kombu — `celery-workers`). What has genuinely diverged and matters here: **`DELIFEQ`** (9.0.0, compare-and-delete — no Lua needed to release a lock), **`SET … IFEQ`** (8.1, compare-and-swap), **`MSETEX`** (9.1), and **database-level ACL** (9.1), which is what turns our logical-DB split from a convention into an enforced boundary. Redis went the other way — modules, vector sets, a query engine, AGPLv3 — none of which we use. Do not copy a Redis 8 recipe without checking it exists here.

## Non-negotiables

- **Every key's second segment is `{org_id}`.** `idem:{org_id}:…` (`kb-error-taxonomy`), `lock:{org_id}:…`, `ans:{org_id}:…`. A cache key without an org segment is a cross-tenant leak with a TTL — WSO2 CVE-2025-13475 was exactly this (`kb-tenancy-isolation`). **One documented exception:** the replay nonce (below), because it is checked *before* any organization is resolved.
- **Queue, broker and coordination keys must never be evicted; cache keys must be.** `maxmemory-policy` is a *server* setting shared by every logical DB, so this is not resolvable by DB index — see below. Evicting `_kombu.binding.*` makes kombu raise `InconsistencyError`; evicting a queue list deletes jobs with no error at all (`celery-workers`).
- **Answer- and retrieval-cache keys carry the source-version fingerprint of the evidence scope.** Without it a cached answer outlives its deleted evidence (`kb-deletion-and-verification`, `kb-tenancy-isolation`). With it, the read-repopulate race is *benign*, not merely narrow — see the example.
- **A TTL is not a deletion guarantee.** Expired keys stay resident until lazily touched or sampled by the active cycle, which tolerates up to 10% expired keys at any moment; and a snapshot taken before expiry stores the value *with* its TTL. Persistence posture per instance is therefore an erasure control, not a performance knob.
- **Valkey is never the source of truth** (docs/18 §25.1). Job state, delivery counts, usage and quota truth, and audit live in PostgreSQL. Every Valkey family must be reconstructible or discardable.
- **Locks are advisory.** Correctness comes from a conditional write in PostgreSQL guarded by a fencing token; the Valkey lock only prevents wasted work. Anything that would be *incorrect* if two holders ran must not depend on the lock alone.
- **No key family ships without a stated cardinality bound and either a TTL or a named deleter.** An unbounded key family is this system's equivalent of an unbounded metric label (`kb-observability-conventions`).

## How we use it

### Two instances — the eviction conflict, resolved

`maxmemory`, `maxmemory-policy`, `save` and `appendonly` are all server-level. `SELECT 3` changes nothing about any of them. So one instance cannot simultaneously guarantee "never lose a queued job" and "shed cold cache under pressure" — and getting it wrong deletes queued jobs silently. §9.8 explicitly permits separate instances; take it.

| | `valkey-core` | `valkey-cache` |
|---|---|---|
| `maxmemory-policy` | **`noeviction`** — a write at the ceiling fails loudly | `allkeys-lru` |
| Persistence | AOF `appendfsync everysec` + RDB | **none** — no RDB, no AOF, no `save` line |
| Holds | queues, broker, idempotency, nonces, locks, breakers, sessions, limiters, deletion index | answer cache, retrieval cache, resolved-config cache, quota read-through |
| Loss impact | jobs and correctness | a latency spike |
| Contains tenant text | no | **yes** — which is why it never touches disk |
| Logical DBs | 0 Laravel queues **+ default cache store** · 1 Celery broker · 2 coordination · 3 Horizon | 0 |

`noeviction` means writes start failing at the ceiling. That is the intended behaviour: alert on `redis_memory_used_bytes / maxmemory` well before it, and never "fix" a full core instance by switching its policy. Logical DBs inside `valkey-core` buy blast radius (`FLUSHDB` hits one workload) and per-DB key counts in `INFO keyspace` — nothing else. On 9.1 make it real: give each service an ACL user scoped to its own database *and* its own key patterns, so `ai-api` physically cannot touch `queues:*`.

### The grammar

`{family}:{org_id}:{scope…}:{discriminator}` — family first so a family is greppable, ACL-matchable (`~ans:*`) and countable; org second, always. It matches `idem:`/`lock:` as already fixed by `kb-error-taxonomy`, and it realizes `kb-tenancy-isolation`'s `kb:{org_id}:{bot_id}:…` sketch with a concrete family token in place of the literal `kb:`. Never build a segment from a user-chosen string (a bot slug, a source name); use ULIDs and hex digests only. An org-wide purge iterates the thirteen known families by prefix — deterministic, and unlike a `SCAN MATCH kb:{org}:*` sweep it cannot miss a key written mid-iteration.

> `laravel-sanctum-auth` currently mints widget sessions at `kb:{org_id}:sdk:sess:{sha256}` — org-first. That is the one shipped key that does not match this grammar; it should become `sess:{org_id}:{bot_id}:{sha256}`. Flagged, not silently rewritten.

### The catalog

Value shapes, sizing arithmetic and the rate-limiter Lua are in **[references/key-catalog.md](references/key-catalog.md)**.

| Family | Pattern | TTL | Where | Owner | Bounded by |
|---|---|---|---|---|---|
| Answer cache | `ans:{org_id}:{bot_id}:{sha256(q_norm ⋮ config_version ⋮ evidence_fp)}` | 900 s | cache | ai-service | distinct questions; LRU is the real bound |
| Retrieval cache | `retr:{org_id}:{bot_id}:{sha256(q_norm ⋮ evidence_fp)}` | 300 s | cache | ai-service | as above |
| Answer index (for deletion) | `ansidx:{org_id}:{source_id}` → SET of `ans:` keys | 24 h | **core** | ai-service | capped at 10 000 members/source |
| Resolved config | `cfg:{org_id}:{bot_id}:{config_version}` | 300 s | cache | core-api | version in the key ⇒ never stale |
| Quota read-through | `quota:{org_id}:{period}:{metric}` | 60 s | cache | core-api | orgs × metrics; PostgreSQL is truth |
| Idempotency record | `idem:{org_id}:{operation}:{key}` | 24 h | core | both | mutations per org per day |
| Replay nonce | `nonce:{key_id}:{request_id}` — **no org segment** | 120 s | core | ai-service, core-api | requests per 120 s |
| Distributed lock | `lock:{org_id}:{resource}:{id}` | PX per call site | core | both | live jobs |
| Fence counter | `fence:{org_id}:{resource}:{id}` | none — deleted with the resource | core | both | resources |
| Rate limiter | `rl:{org_id}:{bot_id}:{scope}:{subject}:{window}` | 2 × window | core | core-api | **attacker-controlled** — see gotchas |
| Circuit breaker | `cb:{org_id}:{connection_id}:{model\|_gateway}[:{upstream}]` | 600 s sliding | core | ai-service | orgs × connections × models × upstreams |
| Chat session | `sess:{org_id}:{bot_id}:{session_id}` | 1800 s sliding | core | core-api | concurrent widget sessions |
| Laravel queues | `queues:{name}`, `:delayed`, `:reserved`, `:notify` | none | core db 0 | core-api | queue depth |
| Laravel default cache store | `{APP}_cache_*` — `Cache::lock()`, `WithoutOverlapping`, `RateLimiter`, scheduler mutexes (`framework/schedule-*`) | per entry | **core db 0** | core-api | framework |
| Horizon | `horizon:*` | mixed | core db 3 | core-api | metrics retention |
| Celery broker | `{ingest\|embed\|crawl\|evaluate\|maintenance}`, `unacked`, `unacked_index`, `_kombu.binding.*`, `*.pidbox` | none | core db 1 | ai-service | queue depth × payload; unacked ≈ in-flight × visibility timeout |

`rl` scopes are `bot`, `origin`, `session`, `ip` — all four checked together in one call, per `kb-security-baseline` §18.5; the limits and the `by()` composition are `laravel-sanctum-auth`'s, the physical key, TTL and eviction posture are this file's. `nonce` is the one family with no org segment, and the reason is ordering: **the replay check runs as part of signature verification, before any organization has been resolved.** Keying it on `X-KB-Org-Id` would make replay protection depend on a value that verification has not yet established, which inverts the trust order. It keys on the signing `key_id` and the `X-KB-Request-Id` ULID instead — globally unique, so an org segment would add no isolation. (This file originally justified the exception by `X-KB-Org-Id` being outside the HMAC canonical string. That was a correct reading of the contract and a real vulnerability: the tenant scope of every data-plane call was forgeable. `kb-internal-api-contracts` now signs the full `X-KB-*` header set, so the header is trustworthy — but it is trustworthy only *after* verification, which is why the key stays as it is.)

**Laravel's default cache store is on `valkey-core`, not on the cache instance.** `Cache::lock()`, `WithoutOverlapping`, `RateLimiter`, and both scheduler mutexes live in the *default* store, and every one of them fails **open** when its key disappears (`laravel-queues-valkey`, `laravel-scheduler`). The evicting instance is reachable from Laravel only as an explicitly named store — `Cache::store('ephemeral')` — used for `cfg:` and `quota:` and nothing else.

**Deliberately not in Valkey:** job status and delivery counters (`background_jobs`), usage and quota truth (`usage_events`), audit, conversation content, retrieval traces, and any config snapshot on the FastAPI side — FastAPI receives the snapshot in the request body and caches nothing across requests (`kb-internal-api-contracts`). Valkey memory and hit-rate observability comes from `redis_exporter`; do not mint `kb_*` metrics for it (`kb-observability-conventions`).

### The answer cache, end to end

```python
# services/ai-service/app/cache/answers.py
import hashlib, json

ANSWER_TTL = 900
SEP = "\x1f"                      # unit separator: unambiguous, cannot appear in a ULID or digest

def evidence_fp(allowed_version_ids: list[str]) -> str:
    """Fingerprint of the *resolved retrieval scope* — every source_version this bot may
    search right now, not just the versions that produced one answer.

    This is the whole deletion story. Retiring, disabling or adding a version changes the
    set, so every later request computes a DIFFERENT key and can never reach the stale
    entry. Trade-off, taken deliberately: any source change on a bot cold-starts its whole
    answer cache. Narrowing this to 'the versions cited in the answer' would make added
    sources invisible for a full TTL, which is the worse failure."""
    return hashlib.sha256(SEP.join(sorted(allowed_version_ids)).encode()).hexdigest()[:32]

def answer_key(ctx, q_norm: str, allowed_version_ids: list[str]) -> str:
    fp = hashlib.sha256(SEP.join(
        (q_norm, str(ctx.config_version), evidence_fp(allowed_version_ids))).encode()).hexdigest()
    return f"ans:{ctx.org_id}:{ctx.bot_id}:{fp}"        # org is segment 2, always

async def get_answer(ctx, q_norm, allowed_version_ids):
    if not allowed_version_ids:
        raise EmptyScopeError(ctx.org_id)               # kb-tenancy-isolation: never widen, never cache
    raw = await CACHE.get(answer_key(ctx, q_norm, allowed_version_ids))
    return json.loads(raw) if raw else None             # a cache outage degrades silently (kb-error-taxonomy)

async def put_answer(ctx, q_norm, allowed_version_ids, payload, cited_source_ids):
    key = answer_key(ctx, q_norm, allowed_version_ids)
    # SET … EX. Never SETEX (superseded), and never a bare SET on an existing key: SET
    # DISCARDS any prior TTL unless KEEPTTL is passed, which is how a cache entry becomes
    # immortal and an "expiring" record grows the DB forever.
    await CACHE.set(key, json.dumps(payload), ex=ANSWER_TTL)
    # Reclamation index lives on CORE, not beside the entries it tracks. On the LRU
    # instance the index would be evicted before the answers, and §8.17's check 4 would
    # then report "no cache entries for this source" about answers that are still readable.
    pipe = CORE.pipeline()
    for sid in cited_source_ids:
        idx = f"ansidx:{ctx.org_id}:{sid}"
        pipe.sadd(idx, key)
        pipe.expire(idx, 86_400, gt=True)               # GT: never shorten a longer existing TTL
    await pipe.execute()

async def invalidate_source(org_id: str, source_id: str) -> int:
    """Called TWICE per delete: in phase 1 before COMMIT, and again at the end of phase 2
    (kb-deletion-and-verification steps 4 and 11). The second call closes the window a
    reader opens by loading the pre-commit scope and writing back after — the bug Docker
    Distribution shipped as CVE-2026-35172.

    What makes that window survivable rather than merely narrow: such a reader writes
    under the OLD evidence fingerprint, and no request issued after the commit will ever
    compute that fingerprint again. The fingerprint is the correctness mechanism; these
    two calls reclaim the memory and are what check 4 actually observes."""
    idx = f"ansidx:{org_id}:{source_id}"
    keys = await CORE.smembers(idx)
    if keys:
        await CACHE.unlink(*keys)                       # UNLINK, not DEL — frees off-thread
    await CORE.delete(idx)
    return len(keys)
```

### Locks, honestly

`SET lock:{org_id}:{resource}:{id} {token} NX PX {ttl}`; release with `DELIFEQ key token` (9.0.0+) — never `DEL`, which deletes whoever holds it *now*. A holder that pauses past its `PX` — a 15-minute OCR call, a GC pause, a frozen container — loses the lock while still running, and no amount of TTL tuning removes that: Valkey expires on the wall clock, not a monotonic one. So:

- **Correctness-critical** (version publication, purge, usage finalization): take `INCR fence:{org_id}:{resource}:{id}` as a fencing token, carry it into the write, and make PostgreSQL reject the stale writer (`UPDATE … WHERE fence_token < :token`). The lock is then an optimization and its expiry is harmless.
- **Best-effort** (crawl politeness, model-load mutex, beat-tick dedup): the plain lock is enough; a double run costs work, not correctness.
- **Redlock is not our answer.** Its safety is disputed — Kleppmann's critique of its timing assumptions stands, antirez's rebuttal is worth reading, Redisson deprecated its `RedLock` in favour of a fenced lock, and Valkey's own page tells you to add fencing tokens regardless. Five nodes do not fix a paused holder. One instance plus a fence is strictly simpler and strictly no less safe.

### Rate limiting

**Sliding-window counter** (two adjacent fixed windows, weighted by elapsed fraction), evaluated for all four `kb-security-baseline` scopes in a single `EVALSHA` so the composite decision is atomic and costs one round trip. Not a fixed window: it lets 2× the limit through across a boundary, which is exactly how a burst-shaped abuser gets by. Not a token bucket: per-subject state plus refill math is more memory and more code for a limit expressed in requests-per-window. `INCR` then `EXPIRE` as two commands is a bug, not a shortcut — die in between and the counter is immortal and that subject is banned forever. Laravel's built-in `throttle` is fixed-window and stays only on coarse admin routes.

## Gotchas

- **Ingestion jobs vanish; sources sit in `Parsing` forever and no worker logs an error.** The broker shared an instance running `allkeys-lru`, and Valkey evicted a queue list or a `_kombu.binding.*` set under memory pressure — an eviction is not an error anywhere. `maxmemory-policy` is server-wide, so a DB index cannot save you. Two instances, `noeviction` on core.
- **The coordination DB grows without bound and eventually rejects writes.** An idempotency record was rewritten on completion with a bare `SET`, which discards the 24 h TTL. Pass `KEEPTTL` (or re-assert `EX 86400`) on every overwrite of a TTL'd key, and assert `TTL key >= 0` in the test that covers the completion path.
- **A deleted source still answers, and the reaper's `SCAN MATCH ans:{org}:*` sweep reported nothing to clean.** SCAN only guarantees keys present for the *whole* iteration; anything written mid-iteration may be missed — which is precisely the read-repopulate case. `MATCH` also filters after retrieval, so the sweep is full-keyspace work that still proves nothing. The deletion proof reads the tracked index with `EXISTS`/`SMEMBERS`; SCAN is for capacity forensics only.
- **Two workers publish the same source version and the pointer flips twice, with the lock "held".** The holder stalled past its `PX`. This is unfixable at the lock layer. Fence the write in PostgreSQL; treat the lock as advisory. Symptom to watch for: duplicate points in Qdrant plus two `activated_at` writes seconds apart.
- **A worker's retry runs unprotected because someone else's lock got deleted.** `DEL lock:…` on release, after the TTL had already expired and the next worker had acquired. `DELIFEQ key token`, always.
- **A distributed scraper is never throttled, and legitimate users are limited at exactly double the configured rate each minute.** Fixed-window counters. Move to the sliding-window Lua; verify with a test that fires `limit` requests at `T-1s` and `limit` more at `T+1s` and asserts rejection.
- **One client exhausts `valkey-core` and every enqueue starts failing.** IPv6 rate limiting keyed on the full address: a single /64 allocation is 2^64 distinct keys, each with a 2-window TTL. Key IPv6 subjects on the **/64 prefix**. More generally the `rl:` family's only bound is `distinct subjects × TTL` — it is the one family whose cardinality an attacker sets, so size `maxmemory` against it.
- **The circuit breaker "closes" mid-outage and every worker stampedes a sick provider.** Breaker state sat on the LRU instance and was evicted; absent state reads as healthy. Breakers, limiters, locks, nonces and idempotency records are all *absence-sensitive* — losing them fails open. They belong on `noeviction`, without exception.
- **A nightly sweep runs once per replica, or a `WithoutOverlapping` job double-executes, and every lock call returned "acquired".** `CACHE_STORE` was pointed at `valkey-cache`. Laravel's scheduler mutexes, `Cache::lock()` and `RateLimiter` are ordinary cache entries, and an evicted lock key reads as *free* — no exception on either side (`laravel-scheduler`, `laravel-queues-valkey`). The default store lives on `valkey-core`; the evicting instance is only ever `Cache::store('ephemeral')`.
- **An erasure request is signed off and the tenant's cached answers are still recoverable from a backup.** Expiry is lazy plus a sampling cycle that tolerates ~10% expired keys resident, so "the TTL passed" says nothing about the bytes; an RDB written *before* expiry stores the value with its TTL, and the AOF keeps the write until a rewrite drops it. (A save does skip already-expired keys — that is not the hole.) The only provable posture is the one above: no RDB, no AOF, no backup job on `valkey-cache`, and tenant text nowhere else in the keyspace.
- **A cache-invalidation listener silently does nothing, or fires minutes late.** It was built on `notify-keyspace-events` `expired`. That event fires when the server *deletes* the key — lazily or on the active cycle — not at logical expiry, and pub/sub is fire-and-forget with no replay for a subscriber that was disconnected. Invalidation is an explicit call in the deletion path, never a notification.
- **Two environments share a logical DB and steal each other's jobs, or a `FLUSHDB` during an incident wipes the wrong workload.** A DB index is not a namespace guarantee: kombu applies no prefix at all, and Laravel's client-level prefix has historically not reached queue key names (laravel/framework#27896). <!-- UNVERIFIED: not re-checked against Laravel 13 --> One DB per workload per environment, enforced with a 9.1 database-level ACL user rather than a config convention.
- **A hot bot's cache hit rate reads 0% after an unrelated source upload.** Expected: the evidence fingerprint covers the bot's whole resolved scope, so adding a source rekeys every entry. If this becomes a cost problem, the fix is a shorter TTL, never a narrower fingerprint.

## Official docs

- [Valkey — key eviction](https://valkey.io/topics/lru-cache/) — the eight `maxmemory-policy` values, `volatile-*` degrading to `noeviction`, `maxmemory-samples`.
- [Valkey — EXPIRE](https://valkey.io/commands/expire/) and [SET](https://valkey.io/commands/set/) — lazy vs active expiry, the 10% tolerance, `NX/XX/GT/LT`, and `SET`'s TTL-discarding default.
- [Valkey — persistence](https://valkey.io/topics/persistence/) — RDB vs AOF, `appendfsync` options, fork cost, rewrite.
- [Valkey — SCAN](https://valkey.io/commands/scan/) — the iteration guarantees and their limits; why `KEYS` blocks.
- [Valkey — distributed locks](https://valkey.io/topics/distlock/) and [DELIFEQ](https://valkey.io/commands/delifeq/) — `SET NX PX`, safe release, the clock-drift and fencing-token caveats.
- [Kleppmann, *How to do distributed locking*](https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html) and [antirez's reply](http://antirez.com/news/101) — read both before defending any lock design here.
- [Valkey — keyspace notifications](https://valkey.io/topics/notifications/) — when `expired` actually fires, and the fire-and-forget delivery model.
- [Valkey 9.1 release notes](https://valkey.io/blog/valkey-9-1-delivers-improvements-in-security-performance-and-more/) — database-level ACL, `MSETEX`, `HGETDEL`. [Valkey 9.0](https://valkey.io/blog/introducing-valkey-9/) — `DELIFEQ`, hash-field expiry, cluster databases.
- [Kombu — Redis transport source](https://github.com/celery/kombu/blob/main/kombu/transport/redis.py) — the exact broker keys we must not evict.
- [Cloudflare — how we built rate limiting](https://blog.cloudflare.com/counting-things-a-lot-of-different-things/) — the sliding-window-counter approximation and its error bound.

## Definition of done

- [ ] Two Valkey services exist; `valkey-core` runs `maxmemory-policy noeviction` with AOF `everysec`, `valkey-cache` runs `allkeys-lru` with **no** RDB and **no** AOF. A test asserts both via `CONFIG GET`.
- [ ] No backup job, snapshot, or volume mount targets `valkey-cache`.
- [ ] Every key produced by the change set matches `{family}:{org_id}:…` with a family from the catalog; the only key without an org segment is `nonce:`. A CI grep over both services fails on a literal key string built outside the key-builder module.
- [ ] Answer- and retrieval-cache keys include the evidence fingerprint; a test indexes a source, warms the cache, deletes the source, re-asks, and asserts a refusal (`kb-deletion-and-verification`).
- [ ] `invalidate_source` is called in phase 1 *and* at the end of phase 2; a test that repopulates the cache between the two asserts the second call reclaims it.
- [ ] The `ansidx:` index lives on `valkey-core`; a test asserts a full `valkey-cache` eviction leaves the deletion check still able to enumerate keys.
- [ ] Every TTL'd key that is ever overwritten is overwritten with `KEEPTTL` or an explicit `EX`; a test asserts `TTL >= 0` after the idempotency completion write.
- [ ] Locks use `SET NX PX` and release with `DELIFEQ`; no `DEL` on a lock key anywhere. Every correctness-critical lock has a fencing token compared in the guarded `UPDATE`, with a test that a stale token's write is rejected.
- [ ] Rate limiting is one `EVALSHA` covering bot, origin, session and IP; a boundary test proves no 2× burst; IPv6 subjects are keyed on the /64.
- [ ] Laravel's **default** cache store resolves to `valkey-core`; `valkey-cache` is reachable only as `Cache::store('ephemeral')`. A test asserts `Cache::lock()` and the scheduler mutex land on the `noeviction` instance.
- [ ] Each service connects with its own ACL user, scoped to its logical DB and its key patterns; a test asserts `ai-api` cannot read `queues:*`.
- [ ] No production code calls `KEYS`, and no invalidation, verification, or deletion path depends on `SCAN` or on keyspace notifications.
- [ ] An alert fires on `redis_memory_used_bytes / maxmemory` for `valkey-core` well before the ceiling; nothing in the repo re-exports Valkey metrics as `kb_*`.

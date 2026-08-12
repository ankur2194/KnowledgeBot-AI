# Key catalog — value shapes, sizing, and the limiter script

Companion to `SKILL.md`. The catalog table there is the contract; this file is the detail an
implementer needs once. Every family below is `{family}:{org_id}:…` unless noted.

## Value shapes

| Family | Type | Value | Notes |
|---|---|---|---|
| `ans:` | string | `{"answer":…,"citations":[…],"usage":{…},"cached_at":…}` JSON | Never store the packed prompt or the raw chunk text — `kb-observability-conventions`'s never-logged list applies to caches too. |
| `retr:` | string | JSON list of `{chunk_id, point_id, score, rank}` | IDs and scores only; the text is re-fetched from PostgreSQL so a cache entry can never outlive its row. |
| `ansidx:` | set | members are full `ans:` key strings | Capped at 10 000; on overflow set `ansidx:{org}:{source}:overflow` and record it in the deletion report — correctness still holds via the fingerprint, only reclamation degrades to TTL. |
| `cfg:` | string | the resolved snapshot Laravel sends in the request body | Immutable per `config_version`, so it is safe to serve without revalidation. |
| `quota:` | string | integer | Read-through of a PostgreSQL aggregate. A miss reads PostgreSQL; a stale value never authorizes an over-quota action — the enforcing check re-reads the primary. |
| `idem:` | hash | `state` (`in_flight`\|`done`), `method`, `path`, `body_hash`, `status`, `response` | Claim with `HSETNX state in_flight` + `EXPIRE … NX`; complete with `HSET` + `EXPIRE … KEEPTTL` semantics (see the SKILL gotcha). `in_flight` on a matching key → `409`; different `body_hash` → `422` (`kb-internal-api-contracts`). |
| `nonce:` | string | `1` | `SET … NX EX 120`. Presence is the whole signal. |
| `lock:` | string | random 128-bit token, hex | `SET … NX PX`; release `DELIFEQ key token`. |
| `fence:` | string | integer | `INCR`. Never expires; deleted when the guarded resource is deleted. |
| `rl:` | string | integer | `INCRBY` inside the Lua below; TTL set in the same script. |
| `cb:` | hash | `state`, `opened_at`, `failures`, `successes`, `half_open_probes` | `EXPIRE` refreshed on every write so an idle breaker ages out; a *missing* breaker means closed, which is why this family is never evictable. |
| `sess:` | hash | `bot_id`, `origin`, `issued_at`, `end_user_ref` | Never the session token itself — the key *is* the token lookup. No provider credential, ever. |
| `{queue}\x06\x16{pri}` | list | the same JSON message body as the bare queue | kombu's priority split, `priority_steps = [0, 3, 6, 9]`; priority 0 is the bare name. Polled by every `BRPOP` whether or not anything publishes a priority. |
| `unacked_mutex` | string | random token | `SET … PX 300000 NX`, released by a compare-and-delete Lua. Held for microseconds in the normal case and for the full 300 s if the holder dies mid-sweep. |
| `{uuid}.reply.celery.pidbox` | list | one JSON reply frame per responding worker | Created by the worker, drained and `DEL`ed by the caller. No TTL: the caller's `DEL` is the deleter, so an abandoned `inspect` leaves a key until someone reaps it. |

The three Celery families above carry **no org segment**, and that is correct rather than an
exception to bless: they are transport-internal, one broker serves every tenant, and the tenant
scope lives inside the message body. They are also the reason the org-purge sweep is a list of
org-scoped families rather than a keyspace walk.

## Cardinality — what actually bounds each family

- **Bounded by orgs × a small constant:** `cfg:`, `quota:`, `fence:`, `cb:` (× connections × models; the OpenRouter upstream slug widens it, which is why `kb-error-taxonomy` allows the slug as a *key component* and `kb-observability-conventions` bans it as a metric label).
- **Bounded by workload:** `idem:` (mutations/day), `nonce:` (RPS × 120 s), `lock:` (concurrent jobs), `sess:` (concurrent widget sessions), queue and broker keys (queue depth; broker RAM ≈ in-flight payload bytes × visibility timeout, so the 7200 s timeout from `celery-workers` is also a memory decision).
- **Bounded only by eviction:** `ans:`, `retr:`. This is fine and intended — it is the entire reason these live on the LRU instance.
- **Bounded by the attacker:** `rl:`. `distinct subjects × 2 windows`. Size `maxmemory` on `valkey-core` against a hostile estimate of this family, not a friendly one, and aggregate IPv6 to /64.

`ansidx:` is the awkward one: it is on the `noeviction` instance but its size tracks `ans:`, which is
unbounded. The 10 000-member cap plus the 24 h TTL is what keeps it from being an eviction-free family
with an eviction-driven size.

## Sizing the two instances

Start from these and re-derive from `INFO memory` after a week of real traffic.

- `valkey-core`: `maxmemory` = 2 × (peak queue depth × mean payload) + hostile `rl:` estimate + 20% headroom. Alert at 70%. A `noeviction` instance that reaches its ceiling refuses writes, so headroom is the entire safety margin.
- `valkey-cache`: `maxmemory` sized to the working set you *want* to hold, not the one you have — LRU is the mechanism, not a failure. `maxmemory-samples 10` (default 5) because the entries are large and mis-evicting a hot answer is more expensive than the extra CPU.

## The rate limiter

Sliding-window counter over two adjacent fixed windows, weighted by the elapsed fraction of the
current one. Approximation error is small and always in the conservative direction for the
burst-at-boundary case that fixed windows get wrong. One script call decides all four scopes.

```lua
-- KEYS  = one current-window key per scope (bot, origin, session, ip)
-- ARGV  = window_seconds, now_ms, limit_1..limit_n
-- Returns 0 on allow, or the 1-based index of the first scope that would be exceeded.
local w      = tonumber(ARGV[1])
local now    = tonumber(ARGV[2]) / 1000
local cur    = math.floor(now / w)
local elapsed = (now % w) / w
for i = 1, #KEYS do
  local limit = tonumber(ARGV[2 + i])
  local c = tonumber(redis.call('GET', KEYS[i] .. ':' .. cur)) or 0
  local p = tonumber(redis.call('GET', KEYS[i] .. ':' .. (cur - 1))) or 0
  if p * (1 - elapsed) + c + 1 > limit then
    return i                       -- reject BEFORE incrementing: a rejected request
  end                              -- must not deepen the hole it is already in
end
for i = 1, #KEYS do
  local k = KEYS[i] .. ':' .. cur
  redis.call('INCR', k)
  redis.call('EXPIRE', k, w * 2)   -- same script as the INCR: two commands here is the
end                                -- immortal-counter bug
return 0
```

Call with `EVALSHA` and fall back to `EVAL` on `NOSCRIPT` — a Valkey restart or `SCRIPT FLUSH`
empties the script cache and an `EVALSHA`-only client then fails every request. Note that Valkey 9.1
moved the Lua engine into its own module; scripting is still enabled by default, but confirm the
module is loaded in the deployed image before shipping a script-only path.
<!-- UNVERIFIED: module packaging in the official 9.1 image not confirmed -->

`subject` in `rl:{org_id}:{bot_id}:{scope}:{subject}:{window}` is a 16-hex-char truncated
HMAC of the raw value keyed on an app secret — for `origin` and `session` this is only tidiness,
but for `ip` it keeps a directly identifying value out of a keyspace we may dump during an
incident. It does not reduce cardinality; nothing does except the /64 aggregation.

## Operational notes

- **Never `KEYS`, never `FLUSHALL`.** `FLUSHDB` is legitimate on `valkey-cache` db 0 and nowhere else.
- **`--bigkeys` / `MEMORY USAGE` for forensics**, `SCAN` with a small `COUNT` if you must walk the
  keyspace; both on a replica if one exists, never on the primary during traffic.
- **Migration to a second instance** is a config change, not a data migration: point `valkey-cache`
  at an empty instance and let it warm. Do not copy cache data across — a stale entry restored under
  a fingerprint that has since changed is unreachable anyway, and one restored under a fingerprint
  that has *not* changed is exactly the deleted-evidence hazard.
- **Health**: `valkey-core` is a required dependency for both `/health/ready` endpoints;
  `valkey-cache` is not — a cache outage degrades silently (`kb-error-taxonomy`'s degradation
  matrix). Wiring readiness to the cache instance turns a latency blip into a fleet-wide restart.

# `infrastructure/docker/valkey/` — two instances, one ACL file

`core.conf` and `cache.conf` configure the two Valkey **services**; `users.acl` is mounted into
**both**. This file carries the prose that cannot live inside `users.acl` — see the first section
for why that is not a stylistic choice.

---

## `users.acl` HAS NO COMMENT SYNTAX. THIS IS A BOOT BLOCKER, NOT A LINT.

Verified against a running `valkey/valkey:9.1.1`:

| Line shape | Result |
|---|---|
| `user <name> …` on **one** line | accepted |
| blank line | accepted |
| `# anything` | **fatal** — `Aborting Valkey startup because of ACL errors: users.acl:1 should start with user keyword followed by the username` |
| `\` line continuation | **fatal** — `users.acl:2: Syntax error` |

`#` is not a comment marker in ACL syntax at all: it introduces a **password hash**
(`#<sha256 hex>`), which is why a leading `#` is read as a malformed rule rather than ignored.
The server then refuses to start — loudly and correctly.

So: **one `user` line per user, no continuations, no comments, and every explanation lives here.**

That applies to `users.acl.example` too, because `bootstrap.sh` copies it **verbatim**.

---

## ⚠ AN ABSENT OR EMPTY `users.acl` FAILS **OPEN**. THIS IS THE OPPOSITE OF MALFORMED CONTENT.

The distinction above is about *malformed* content, and it is easy to over-generalise into "Valkey
refuses to start without its ACL file". **It does not.** Measured 2026-08-11 against
`valkey/valkey:9.1.1` with the real `core.conf`:

| `aclfile` target | Result |
|---|---|
| malformed content (`#` comment, `\` continuation) | **fatal** — aborts startup. Safe. |
| **absent** (Docker created a *directory* at the bind source) | **starts, exit 0** |
| **zero bytes** | **starts, exit 0** |

In both of the silent cases `ACL LIST` returns:

```
user default on nopass sanitize-payload ~* &* +@all
```

and an unauthenticated `SET pwned 1` returns `OK`. That is unauthenticated, full-privilege access
to every queue, session, lock, fence, rate limiter and cache entry of every tenant, from anything
that can reach the port.

For years this repository asserted the reverse — that Valkey fails closed and the SeaweedFS S3
gateway fails open. **Both halves were backwards.** SeaweedFS `4.40` treats an absent or zero-byte
`-s3.config` as a *fatal* config load (`exit 255`); what actually opens *it* is omitting the flag
or shipping a config that parses to zero identities. See
`../seaweedfs/identities.json.example`'s `__README` for those measurements.

**The latch.** The `valkey-core` and `valkey-cache` healthchecks in `compose.yaml` now assert the
whole probe output is exactly `PONG`, with stderr merged in. The previous `… ping | grep -q PONG`
returned **exit 0 against a wide-open server**: the failed `AUTH` goes to stderr while the default
`nopass` user answers the `PING` on stdout. Note that `ACL WHOAMI` is *not* a usable probe —
`kb-observer` holds `+acl|log` only, so it returns `NOPERM` against a correctly configured server.
`ACL SAVE` also rewrites this file in the server's own normalized form, which is a second reason
nothing but rules can survive in it.

---

## Why per-service ACL users at all

The logical-DB split (0 Laravel queues + default cache, 1 Celery broker, 2 coordination,
3 Horizon) buys blast radius and per-DB key counts in `INFO keyspace` — and nothing else, on its
own. A DB index is not a namespace guarantee: kombu applies no prefix at all, and Laravel's
client-level prefix has historically not reached queue key names
([laravel/framework#27896](https://github.com/laravel/framework/issues/27896)). The ACL is what
turns the convention into a boundary the server enforces, so that `ai-api` **physically cannot**
touch `queues:*`.

Do not "simplify" this to `requirepass`. One shared password for four workloads is one credential
that can be scoped to nothing and rotated for nobody.

### `user default off`

The first line, and it stays. With the default user enabled, every misconfigured client silently
connects with **full privileges** instead of failing, and "the ACL is working" goes unverified for
a year. With it off, a service that was given no credential cannot connect at all: the symptom is
`NOAUTH Authentication required` on the first queue push — immediate, loud, and unambiguous.

`scripts/ops/preflight.sh` treats the *absence* of this line as a finding, and treats its presence
as correct. It is deliberately not flagged.

### One ACL file, both servers

An ACL user is a **service identity**, not a per-server credential. `kb-core` authenticates the
same way against `valkey-cache` (reached only as `Cache::store('ephemeral')`) as against
`valkey-core`; what separates the two is the key patterns, not the password. Two files would mean
two passwords per service, two rotations, and two copies of the patterns — which is the part that
is actually the boundary — drifting apart with nothing comparing them.

Both `core.conf` and `cache.conf` therefore carry `aclfile /etc/valkey/users.acl`, and **both**
compose services mount it. Omitting the mount on either one makes that instance refuse to start.

---

## The users

### `kb-core` — Laravel (control plane)

```
~queues:* ~*_cache_* ~horizon:* ~framework:*
~idem:* ~lock:* ~fence:* ~rl:* ~sess:* ~cfg:* ~quota:*
resetchannels &laravel*
+@all -@dangerous -flushall -flushdb -keys -shutdown -config -debug -replicaof -module
```

Queues and the **default** cache store (db 0), Horizon (db 3), and the coordination families it
shares with the data plane (db 2). `~*_cache_*` is what covers everything routed through the cache
prefix — `Cache::lock()`, `WithoutOverlapping`, `RateLimiter`, both scheduler mutexes, the
`queue:restart` signal, and redis-driver sessions (which are cache-backed through
`SESSION_STORE=valkey`). Change `CACHE_PREFIX` and this pattern must change with it.

Denials, each for a specific incident:

- `-keys` — no production code may call `KEYS`. It blocks the server for a full-keyspace walk, and
  every alternative already exists (`SCAN` for capacity forensics, the tracked `ansidx:` index for
  the deletion proof).
- `-flushall -flushdb` — `php artisan cache:clear` against the wrong store is how queued ingestion
  jobs disappear. A genuine flush is an operator action through a separate admin user, never
  something an application credential can do.
- `-config` — `CONFIG SET maxmemory-policy` from the application would silently convert the
  `noeviction` instance into an evicting one, and an eviction is not an error anywhere.

### `kb-ai` — FastAPI + Celery (data plane)

```
~ingest ~embed ~crawl ~evaluate ~maintenance
~ingest??[369] ~embed??[369] ~crawl??[369] ~evaluate??[369] ~maintenance??[369]
~unacked ~unacked_index ~unacked_mutex
~celeryev.* ~_kombu.binding.* ~*.pidbox ~*.pidbox??[369]
~idem:* ~nonce:* ~lock:* ~fence:* ~cb:* ~ansidx:* ~ans:* ~retr:*
resetchannels &/1.celery.pidbox &/1.celeryev/worker.*
+@all -@dangerous -flushall -flushdb -keys -shutdown -config -debug -replicaof -module
```

Broker (db 1), coordination (db 2), and the answer/retrieval caches on the cache instance.

#### The broker half of this grant is dictated by kombu, not by us

Every pattern above the `idem:` line is a key or channel **kombu's Redis transport chooses**, not
one we name. Deriving them from the catalog produces a grant that is short in four places, and the
four are invisible until a worker boots. Each was found by reading `ACL LOG` off a running server
while the workers crash-looped — which is why `kb-observer` now carries `+acl|log` (below).

The observed set, from `kombu/transport/redis.py` on kombu 5.6 and confirmed against a live
`valkey/valkey:9.1.1`:

| Pattern | What actually uses it |
|---|---|
| `~unacked_mutex` | `Channel.unacked_mutex_key`. `restore_visible()` takes a `redis.lock.Lock` on it *at consumer startup*, before any message moves. Sibling of `unacked`/`unacked_index`, and the one the catalog forgot. **This is the key the five-container crash loop was denied.** |
| `~{queue}??[369]` | kombu's priority queues. `_q_for_pri(q, pri)` returns `q` for priority 0 and `q + '\x06\x16' + str(pri)` otherwise, over `priority_steps = [0, 3, 6, 9]`. `_brpop_start` BRPOPs **all four variants of every active queue on every poll**, so a worker touches `ingest\x06\x163` whether or not anything is ever published at a priority. `??` is the two-byte `\x06\x16` separator; `[369]` is `priority_steps` minus the zero case. |
| `~*.pidbox??[369]` | the same suffix on the reply mailbox. `{oid}.reply.celery.pidbox` is a **direct** exchange, so it is a plain list key, not a channel — and it gets the priority suffix like any other queue. `~*.pidbox` alone does not match it: the suffix is trailing. |
| `~celeryev.*` | Celery's gossip bootstep, which creates a per-worker event queue `celeryev.{uuid}` (and its three priority variants). Not optional: gossip is on unless a worker is started `--without-gossip`, and the command lines in `compose.yaml` do not. |

`~*.reply.celery.pidbox` was removed as **strictly redundant** — such a key ends in `.pidbox`, so
`~*.pidbox` already matched it. Verified, not assumed.

`\x06\x16` cannot be written as an escape here. `~"*.pidbox\x06\x163"` **parses and matches
nothing** — the server starts clean and the pattern is dead. That failure is silent and
fail-*closed*, so it looks like the grant is simply missing. Verified on 9.1.1; use the glob.

#### Channels: `PSUBSCRIBE` is matched **literally**, `PUBLISH` is matched as a glob

The two rules are different code paths, and this is the trap that survived three fix attempts:

- `PUBLISH`/`SUBSCRIBE` glob-match the channel against each granted pattern.
- `PSUBSCRIBE` **string-compares** the requested pattern against each granted pattern. A grant of
  `&/1.celeryev/*` does **not** authorize `PSUBSCRIBE /1.celeryev/worker.*`, even though every
  channel the pattern can ever match is granted. The granted pattern must equal the subscribed one.

kombu subscribes to fanout exchanges with `psubscribe`, so the grant must carry the exact topic
`_get_publish_topic` builds: `keyprefix_fanout + exchange` (+ `/` + routing key when
`fanout_patterns`). `keyprefix_fanout` is `'/{db}.'` — **the broker database number is part of
every channel name.** `KB_BROKER_URL` ends in `/1`, hence `/1.`; repoint the broker at another db
and both channel grants must move with it.

That is also why the previous `&celery* &_kombu*` granted nothing real: the live channels are
`/1.celery.pidbox` and `/1.celeryev/worker.online|offline`, and neither starts with `celery`. Both
dead patterns were removed. `&/1.celeryev/worker.*` is one entry doing two jobs — the literal
gossip `PSUBSCRIBE` pattern, and a glob covering the `worker.online`/`worker.offline` publishes.

Task events (`/1.celeryev/task.*`) are deliberately **not** granted: the workers run without `-E`
and `worker_send_task_events` is unset. Enabling either will produce a channel denial, which
`ACL LOG` will now name.

#### What is still not granted, and must not be

**Note the absence of `~queues:*`, `~horizon:*`, `~*_cache_*` and `~framework:*`.** The data plane
must not be able to read — still less delete — Laravel's queues, sessions or cache. Every widening
above stays inside a Celery-owned prefix; none of them can reach a Laravel family. Re-verified
after the change, against the running server:

| Attempt | Result |
|---|---|
| `kb-ai` → `LLEN queues:default` | `NOPERM` |
| `kb-ai` → `GET kb_database_cache_x` | `NOPERM` |
| `kb-ai` → `GET horizon:x` / `GET framework:x` | `NOPERM` |
| `kb-ai` → `PUBLISH laravel-x` | `NOPERM … access a channel` |
| `kb-core` → `EXISTS ingest` / `GET ans:o:b:x` | `NOPERM` |
| `kb-core` → `PSUBSCRIBE /1.celeryev/worker.*` | `NOPERM … access a channel` |

`~*` for `kb-ai` would have cleared the crash loop in one line and deleted this table with it.
Nothing would ever have failed to report the loss.

**Note the absence of `~queues:*`, `~horizon:*` and `~*_cache_*`.** The data plane must not be able
to read — still less delete — Laravel's queues.

That boundary has been **verified by hand** against `valkey/valkey:9.1.1` (`kb-ai` → `LLEN
queues:t` returns `NOPERM`; see the table under "Passwords"). It is **not covered by any automated
test** — nothing in `services/*/tests/` or `.github/workflows/` exercises a per-user key pattern.
Writing that test is listed as pre-deployment work under "`<!-- UNVERIFIED -->` Database-level
ACL" below; until it exists, a pattern edited out of this file breaks the boundary silently.

### `kb-observer` — read-only forensics

```
~* resetchannels +@read +ping +select +config|get +info +memory|usage +memory|stats
+client|list +slowlog|get +latency|history +acl|log -keys
```

No write command at all, so a 3 a.m. paste cannot delete a queue. `+@read` would otherwise include
`KEYS`, hence the trailing `-keys`.

Four of those grants are not read commands and are each here for a specific incident. They share a
shape: **an ACL denial in this stack does not look like an ACL denial.** It looks like a healthy
container, or a crash in library code, or nothing at all. Every grant below buys back the ability
to *see* one.

- **`+ping`** — `valkey-cli ping` **exits 0 while printing `NOAUTH Authentication required`**. The
  container healthcheck was therefore green on a server the client could not authenticate to: an
  unfalsifiable probe, which is worse than no probe because it is believed. `+config|get` came in
  with it, so `CONFIG GET maxmemory-policy` can assert `noeviction` on core and `allkeys-lru` on
  cache from outside the application — the one setting whose misconfiguration deletes queued jobs
  with no error anywhere (`valkey-keyspaces`).

- **`+acl|log`** — added while diagnosing the Celery broker denials above. `ACL LOG` is the **only**
  place the server names the key or channel it refused; the client sees
  `NoPermissionError: No permissions to access a key` with no object, and `redis-py` raises it from
  inside a pipeline, so the traceback is 40 frames of library code. Before this grant **no user in
  this file could read it**: `acl|log` is `@admin`, and both application users carry `-@dangerous`.
  The result was an ACL that could fail but could not be interrogated — the diagnosis had to be
  reconstructed by reading kombu's source and probing patterns by hand. `ACL LOG` is read-only and
  reports no key *values*; `acl|setuser`, `acl|load` and `acl|deluser` remain denied, and were
  confirmed denied after the change.

- **`+select`** — `SELECT` is `@connection`, not `@read`, so the observer was **pinned to db 0**:
  Laravel's queues and cache were visible and the Celery broker (db 1), coordination (db 2) and
  Horizon (db 3) were not. The incident this file documents lived entirely in db 1. A forensics
  user that cannot reach three of the four databases is a forensics user for one workload.
  `SELECT` moves a connection's db index and reads nothing, and every write command stays denied.

Re-verified on the running server after the change: `DBSIZE`/`SCAN` on db 1 succeed; `LPUSH`,
`KEYS`, `FLUSHALL` and `ACL SETUSER` all return `NOPERM`.

**There is no user with `+acl|load`, and that is a deliberate cost with a sharp edge.** No
credential in this file can reload `users.acl` in place, so **every change to it requires
restarting the Valkey containers** — which loads the file from scratch. See the warning under
"Passwords" before you do that.

It has no container consumer today: the two `redis_exporter` sidecars that will use it
(`infrastructure/observability/exporters/README.md`) do not exist yet, so until then it is a
by-hand credential — `valkey-cli --user kb-observer`. `valkey_kb_observer_password` is declared in
`compose.yaml` anyway so that `scripts/ops/preflight.sh`, which derives its list from that block,
demands the file exists and is well formed.

---

## Passwords

`#<sha256 hex of the password>`, never `>plaintext` — a `>` form in a committed file is a
plaintext credential in git, and `scripts/ops/preflight.sh` fails on it.

**`preflight.sh` is deploy-time; it gates nothing on a pull request.** A `>plaintext` line pushed
to a branch is caught when someone runs `make deploy`, not by CI. Two of preflight's three
`users.acl` checks would work equally well as PR gates, because this file is *committed* and
therefore readable without a deployment: the `>plaintext` form, and the presence of `user default
off`. The third — the `#0…0` placeholder check — must stay deploy-only: the placeholders are red
by design in the repo, so a CI job asserting their absence would fail every PR.

The file is **committed with `#0…0` placeholders** rather than generated, because the mount path
must always exist. `scripts/dev/bootstrap.sh` rewrites the three hashes from freshly generated
values and writes the matching plaintexts to `infrastructure/docker/secrets/valkey_kb_*_password`,
in file order: `kb-core`, `kb-ai`, `kb-observer`. **Do not reorder the users** — the rewrite
replaces the first remaining placeholder on each pass.

The hash here and the plaintext in `secrets/` are two halves of one credential and only one half is
regenerable. Losing the plaintext means rewriting both. **With the stack down**, delete `users.acl`
*and* the three password files, then re-run `bootstrap.sh`: it re-renders `users.acl` from
`users.acl.example` and mints all three afresh. (`git checkout -- users.acl` no longer works —
the file is gitignored; only the `.example` is tracked.) Bootstrap warns when it finds the halves
out of sync.

### DRIFT BETWEEN THIS FILE AND THE RUNNING SERVER IS A LATENT STACK-WIDE OUTAGE

Because nothing can `ACL LOAD`, the server's rules are whatever `users.acl` said **at container
start**. The file can then be edited, reverted, or restored from the template, and the running
stack will not notice — until something restarts Valkey, at which point the *new* file is the only
truth and every service whose hash no longer matches gets `WRONGPASS` at once. With
`user default off` there is no degraded mode: Laravel, Horizon, the scheduler and the whole data
plane lose their broker in the same second.

This was found live. `users.acl` on disk held three `#0{64}` placeholders while the running
`valkey-core` was authenticating the real passwords in `secrets/`, so a routine
`docker compose restart valkey-core` — the only way to apply an ACL change — would have taken the
entire stack down as a *side effect of fixing something else*. The repair is to re-derive the
hashes from the plaintexts that already exist, rather than to regenerate the credential:

```sh
# For each of kb-core, kb-ai, kb-observer, in file order:
openssl dgst -sha256 -r < secrets/valkey_kb_ai_password | cut -d' ' -f1
```

`bootstrap.sh` now checks exactly this and refuses to leave the two halves disagreeing.

**Never restart Valkey to apply an ACL edit without proving the file first.** The candidate is
cheap to test in isolation and the test costs about fifteen seconds:

```sh
docker run -d --name acltest -v "$PWD/valkey/users.acl":/etc/valkey/users.acl:ro \
  valkey/valkey:9.1.1 valkey-server --aclfile /etc/valkey/users.acl --save '' --appendonly no
docker logs acltest | grep -i Aborting          # malformed ACL => the server refuses to start
docker exec acltest valkey-cli --user kb-ai --pass "$(cat secrets/valkey_kb_ai_password)" PING
```

A `PONG` there is the proof that a live restart will not lock the stack out. A malformed rule is
the *safe* failure — Valkey aborts startup and says so. The dangerous one is a file that parses
cleanly and authenticates nobody.

Verified behaviour on `valkey/valkey:9.1.1` with the rules above:

| Attempt | Result |
|---|---|
| connect with no credentials | `NOAUTH Authentication required` |
| `kb-core` → `LPUSH queues:t` | allowed |
| `kb-ai` → `LLEN queues:t` | `NOPERM No permissions to access a key` |
| `kb-core` → `GET ans:o:b:x` | `NOPERM No permissions to access a key` |
| `kb-observer` → `LPUSH` / `KEYS` | `NOPERM … no permissions to run the …` |
| `kb-core` → `FLUSHDB` / `CONFIG SET` | `NOPERM … no permissions to run the …` |

---

## `<!-- UNVERIFIED -->` Database-level ACL

Valkey 9.1.0 added database-level ACL, and it is the clause that would make the logical-DB split an
enforced boundary rather than a key-pattern approximation — e.g. denying `kb-ai` any access to db 0
and db 3 outright, instead of relying on the `~queues:*` / `~horizon:*` patterns being an
exhaustive description of what lives there.

The exact selector syntax has **not** been verified against a running 9.1.1 server, and writing a
guessed clause into an ACL file is worse than omitting it: Valkey refuses to start on a malformed
ACL, and a clause that *parses* but means something else grants more than intended with no error.

Before the first deployment: confirm the syntax against `ACL HELP` / `ACL GETUSER default` on
`valkey/valkey:9.1.1`, add the clause to `kb-core` and `kb-ai`, and add the integration test that
proves `kb-ai` cannot `LLEN queues:ai-dispatch`. Until then the key patterns are the only
enforcement, and they are pattern-based: a key family added to db 0 without a matching pattern here
is **not denied**, it is simply not granted — which fails closed for that user but does not
constrain the databases themselves.

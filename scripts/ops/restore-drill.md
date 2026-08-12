# Restore drill

**An untested backup is an assumption.** This drill is the thing that converts it into a fact, and
it is run on a schedule — quarterly at minimum, and after every SeaweedFS or PostgreSQL major
version bump — not after an incident.

It restores into a **throwaway Compose project**, never over a live one. Two reasons: a drill that
can damage production is a drill nobody runs, and a drill that runs beside production is the only
way to compare the two.

Budget about 90 minutes. Most of it is step 5.

---

## What is being proven, and what is deliberately not restored

| Store | Drill action | Why |
|---|---|---|
| PostgreSQL | `pg_restore` from `postgres.dump` | source of truth |
| SeaweedFS | untar `seaweed.tar.gz` | the only irreplaceable bytes: `original/` and `derived/snapshot/` |
| **Qdrant** | **REBUILT from PostgreSQL + object storage — never restored** | see below |
| valkey-core | not restored | its AOF is restart durability; restoring it resurrects stale reservations, spent idempotency records, and breaker state describing an outage that ended weeks ago |
| valkey-cache | nothing to restore | it never wrote anything, by construction |

### Why Qdrant is rebuilt rather than restored, in one paragraph

A snapshot restore **does not prove ADR-010; it hides violations of it.** If someone wrote a
payload key that exists in no PostgreSQL column — a curated title, a boost weight, a language
guess, a hand-fixed excerpt — a restore reproduces it perfectly. Counts cannot detect it: every
vector is present, chunk counts match, and only the *ranking* moved. The bot starts answering
differently and nothing in the restore log is wrong. A rebuild from PostgreSQL is the only
procedure where such a key has nowhere to come from, so its absence becomes visible.

Two rebuild traps that are separately silent:

- **Payload indexes are per-collection and are NOT created by uploading points.** Without them the
  tenant filter degrades to a full scan — correct results, no error, p95 up 20×. Creating them is
  a **precondition of the alias swap**, asserted before it.
- **Qdrant snapshots do not contain aliases.** (Relevant if anyone ever does restore one: the
  restore leaves the alias unbound and every query fails with "collection not found".)

---

## 0. Preconditions

```bash
BACKUP=/path/to/backups/20260806T031500Z
cat "$BACKUP/MANIFEST.txt"
( cd "$BACKUP" && sha256sum -c <(sed -n '/^sha256:/,$p' MANIFEST.txt | tail -n +2) )
```

If the checksums do not match, **stop**. A drill against a corrupt archive proves the archive is
corrupt and nothing else — which is worth knowing, but do not continue past it and report a pass.

Record the start time. Recovery *time* is half of what this drill measures.

---

## 1. Bring up an isolated target

```bash
cd infrastructure/docker
COMPOSE_PROJECT_NAME=kb-drill \
  docker compose -f compose.yaml -f compose.prod.yaml up -d postgres seaweedfs qdrant valkey-core
```

`COMPOSE_PROJECT_NAME` gives this its own volumes, its own network namespace, and its own
container names. Nothing here can reach the production project.

`-f compose.yaml -f compose.prod.yaml` — the same explicit pair production uses. A drill run
against the dev overlay proves the dev overlay works.

---

## 2. PostgreSQL

```bash
docker compose -p kb-drill exec -T postgres \
  psql -U knowledgebot -d postgres -c 'DROP DATABASE IF EXISTS knowledgebot;' \
                                   -c 'CREATE DATABASE knowledgebot;'

docker compose -p kb-drill exec -T postgres \
  pg_restore --username=knowledgebot --dbname=knowledgebot \
             --no-owner --no-privileges --exit-on-error < "$BACKUP/postgres.dump"
```

`--exit-on-error` is not optional. `pg_restore` defaults to continuing past failures and exiting
0, so a restore missing a constraint, an index, or an entire table looks identical to a clean one
in the log.

**Assert, do not eyeball:**

```sql
SELECT count(*) FROM organizations;
SELECT count(*) FROM source_items;
SELECT count(*) FROM chunks;
-- The pointer constraint must exist. Without it, two concurrent publishes both succeed.
SELECT indexdef FROM pg_indexes WHERE indexname = 'source_versions_one_active_per_item';
-- Exactly one active version per item, proven rather than assumed.
SELECT source_item_id, count(*) FROM source_versions
 WHERE activated_at IS NOT NULL AND retired_at IS NULL
 GROUP BY 1 HAVING count(*) > 1;   -- must return zero rows
```

---

## 3. Object storage

```bash
docker compose -p kb-drill stop seaweedfs
docker run --rm -v kb-drill_seaweed:/data -v "$BACKUP:/backup:ro" alpine:3.21 \
  sh -c 'rm -rf /data/* && tar -C /data -xzf /backup/seaweed.tar.gz'
docker compose -p kb-drill start seaweedfs
```

### 3a. The gateway must be CLOSED before anything else touches it

```bash
docker compose -p kb-drill exec -T seaweedfs \
  wget -q -S -O /dev/null http://127.0.0.1:8333/ 2>&1 | grep 'HTTP/'
```

**Must be 403.** A 200 means the gateway is in **Allow-All mode** — it starts cleanly and it logs
nothing. A restore that quietly produces a world-readable object store is worse than one that
failed.

What a 200 does *not* mean (ADR-037, measured against the pinned `chrislusf/seaweedfs:4.40`): an
absent, directory-shaped or zero-byte `identities.json` is a **fatal** config load, exit **255**, so
that container would be crash-looping and this `exec` would fail rather than answer. Allow-All is
reached only by **dropping `-s3.config`** from the command, or by a config that **parses to
`"identities": []`**. During a drill the tempting wrong move is to make a crash-looping `seaweedfs`
go away by writing `{}` into the config path — that is exactly the wide-open state, and the drill
would then pass step 3 and fail nothing. Restore the file from `identities.json.example` and re-run
`scripts/dev/bootstrap.sh` instead.

### 3b. PostgreSQL is the manifest — reconcile against it

The archive is a filesystem copy; the question it cannot answer is *"is every object we believe we
have actually here?"* Only PostgreSQL can answer that:

```
for every row in source_items:
    HeadObject(org/{org_id}/sources/{source_id}/versions/{current_version_id}/original/{content_hash})
```

Record the **missing-key list**. That list *is* the restore gap, and it is the §25.5 evidence. An
empty list is the pass condition; a non-empty one is the finding.

Then run `weed shell` → `volume.fsck` and `fs.verify` to catch volume-level damage the tar
preserved faithfully.

---

## 4. Valkey — nothing to do, and that is the point

Start `valkey-core` empty. Confirm both instances' posture against the **running containers**,
not against the config files:

```bash
docker compose -p kb-drill exec valkey-core  valkey-cli CONFIG GET maxmemory-policy  # noeviction
docker compose -p kb-drill exec valkey-core  valkey-cli CONFIG GET appendonly        # yes
docker compose -p kb-drill exec valkey-cache valkey-cli CONFIG GET maxmemory-policy  # allkeys-lru
docker compose -p kb-drill exec valkey-cache valkey-cli CONFIG GET appendonly        # no
docker compose -p kb-drill exec valkey-cache valkey-cli CONFIG GET save              # empty string
```

A config file says what was intended; `CONFIG GET` says what is true. They diverge when a mount
path is wrong, and a `valkey-cache` that quietly runs with the defaults is writing tenant answers
to disk.

---

## 5. Qdrant — REBUILD. This is the step that matters.

Do **not** restore a snapshot. Run the rebuild job against the restored PostgreSQL and the
restored object store.

Preconditions asserted **before** the alias swap:

- [ ] the new collection exists with the correct vector and sparse-vector configuration
- [ ] **payload indexes are created** — before the swap, not after
- [ ] the point id for every chunk is the deterministic
      `uuid5(POINT_NS, "{org_id}:{source_version_id}:{seq}")`, matching `chunks.vector_point_id`

Then assert, and note the shape of each assertion — this is where a rebuild proof usually goes
wrong:

- [ ] **point count == chunk count**, per organization
- [ ] **payload-key SET equality** against the expected key set — **sets, not cardinality**. A
      curated `title` swapping in for a dropped `url` keeps `len()` identical, which is exactly how
      this check has passed while being violated.
- [ ] every payload key maps to a **named PostgreSQL column**
- [ ] the golden evaluation queries return the **same ranked point ids** as before. A key can be
      present and sourced from the wrong column; only ranking detects that.
- [ ] alias bound, and a tenant-filtered query uses the payload **index** (`EXPLAIN`-equivalent, or
      a latency comparison against a deliberately unindexed collection)

Record wall-clock time for this step separately. It usually dominates RTO.

---

## 6. Application

```bash
COMPOSE_PROJECT_NAME=kb-drill \
  docker compose -f compose.yaml -f compose.prod.yaml up -d
```

- [ ] `laravel-migrate` exits **0**, and `php artisan migrate:status` reports nothing pending. A
      restored database one migration behind the image is the single most common post-restore
      failure, and it presents as a 500 on a missing column.
- [ ] `ai-api` reaches healthy inside its 300 s `start_period`
- [ ] a chat request against a restored bot returns a **grounded, cited answer**
- [ ] a chat request against a source that was **deleted before the backup** returns a **refusal**.
      This is the one that catches a restore resurrecting deleted knowledge, and it is invisible in
      every count-based check.

---

## 7. Tear down and write it up

```bash
COMPOSE_PROJECT_NAME=kb-drill docker compose \
  -f compose.yaml -f compose.prod.yaml down --volumes --remove-orphans
```

Record, in the operations log:

| Field | |
|---|---|
| Backup timestamp | |
| Drill date and operator | |
| **RTO** — wall clock, backup on disk → grounded answer | |
| **RPO** — newest row in the restored dump vs. the incident time it stands in for | |
| Qdrant rebuild duration | |
| Missing-key list from 3b | |
| Every step that did not pass first time | |

**The failures are the output.** A drill that passes cleanly and produces no notes usually means
something was skipped — most often 3b (nobody reconciled against PostgreSQL) or 5 (someone
restored a snapshot because it was faster).

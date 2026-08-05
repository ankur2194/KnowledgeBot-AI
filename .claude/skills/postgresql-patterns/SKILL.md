---
name: postgresql-patterns
description: Schema, indexing, migration, and query-shape doctrine for the PostgreSQL 18 source-of-truth database behind KnowledgeBot AI. Use whenever writing a migration, choosing a primary-key or column type, adding an index or a foreign key, shaping a tenant-scoped query, or debugging a deploy that locked a live table. Owns the schema; Eloquent conventions belong to laravel-control-plane and vectors to qdrant-hybrid-search. Pairs with kb-source-lifecycle (the partial unique index that makes activation atomic).
---

# PostgreSQL Schema, Indexing, and Migration Patterns

PostgreSQL **18.x** (18.4 is current; 18 released 2025-09-25, supported to 2030-11-14 — the spec pins no version, so this file does). Read by both `services/core-api/` (Laravel, owns migrations) and `services/ai-service/` (FastAPI/Celery, writes `source_versions`, `document_elements`, `chunks`).
**Authoritative spec:** docs/11-data-model.md §16, docs/05-tech-stack.md §9.6, docs/06-architecture.md §10, docs/18-deployment-backup-cicd.md §25.2

## Non-negotiables

- **Every tenant-owned table either holds `organization_id NOT NULL` or reaches one through a `NOT NULL` FK chain no code path can bypass** (`kb-tenancy-isolation` NN1). A nullable link in `chunks → source_versions → source_items → knowledge_sources` is an orphan row that belongs to nobody and is readable by anybody. `bot_source_assignments` is the one row that can span two orgs: denormalize `organization_id` onto it and make `(organization_id, bot_id)` and `(organization_id, source_id)` **composite** foreign keys.
- **The active-version pointer is `source_items.current_version_id` plus a partial unique index — never a boolean.** `kb-source-lifecycle` NN2. Two concurrent publishes both read "no active version" and both set their own; a boolean has no statement that flips them together. The index below is the enforcement.
- **PostgreSQL is the source of truth and Qdrant is derived** (ADR-010). Nothing in Qdrant may be unreconstructable from these tables plus SeaweedFS — which means `chunks.text`, `chunks.metadata`, and `chunks.vector_point_id` are all mandatory columns, not caches.
- **No `ON DELETE CASCADE` reaches conversation history.** `citations.chunk_id` cascading takes past answers with it when a source is deleted (`kb-deletion-and-verification`). Break the link, keep the transcript.
- **`audit_logs` is append-only and outlives the record it describes** (`kb-security-baseline` §18.11). No FK from it to the entity it audits, no `ON DELETE` anything, and `REVOKE UPDATE, DELETE` from the application role.
- **Credential ciphertext is `bytea`, never `text`, and is never indexed.** A unique or b-tree index over ciphertext is an equality oracle over secrets; `text` invites an encoding round-trip that silently corrupts bytes (`kb-security-baseline` §18.2).

## How we use it

### Identifiers: ULID stored as `char(26) COLLATE "C"`

The wire format is already frozen as a ULID — `X-KB-Org-Id` (`kb-internal-api-contracts`), the `Ulid` constrained `str` in `pydantic-contracts`, Laravel's `HasUlids`. **Keep it, and store the same 26 characters the wire carries.** The alternative — 128 bits in a `uuid` column, ULID on the wire — buys ~40% smaller indexes and costs a permanent dual representation: every log line, every `psql` paste, and every cross-service join needs a conversion, and the first place someone forgets is a bug class that no type checker catches.

| Choice | Bytes on a b-tree leaf | Comparison | Verdict |
|---|---|---|---|
| `char(26) COLLATE "C"` | 8B index-tuple header + 27B varlena, MAXALIGN → 40B, +4B line pointer ≈ **44B** | `memcmp` | **ours** |
| `char(26)` at the database's default collation | same 44B | ICU/glibc `strcoll` per comparison, and the index is **invalidated by a libc/ICU upgrade** | never |
| `uuid` (UUIDv7) | 8B + 16B → 24B, +4B ≈ **28B** | `memcmp` | correct, but a second id format |
| `bytea` (16 raw bytes) | ≈ 28B | `memcmp` | worst of both: unreadable in logs, no Laravel/Eloquent affordance |

The `COLLATE "C"` is load-bearing twice over, and both failures are silent. <!-- UNVERIFIED: the per-entry byte figures are computed from IndexTupleData (8B) + MAXALIGN'd datum + ItemIdData (4B), not measured — confirm with pgstatindex on a seeded table before quoting them -->
 It also lets `LIKE 'prefix%'` use the plain index without `text_pattern_ops`.

**UUIDv7 is now native** (`uuidv7()`, PG 18) and is the right default for a greenfield schema — same 48-bit-millisecond prefix as a ULID, half the index footprint. We are not greenfield on this decision. Where a column is *already* a UUID for an external reason, use `uuid`: `chunks.vector_point_id` holds the `uuid5(POINT_NS, org:version:seq)` value from `kb-source-lifecycle` because Qdrant point ids may only be u64 or UUID.

**Never `gen_random_uuid()` / `uuidv4()` as a primary key.** A random key writes to a uniformly random leaf page, so the insert working set is the *entire* index rather than its rightmost page: past the point where the index stops fitting in `shared_buffers`, every insert becomes a random read, and every touched page becomes a full-page image in WAL after the next checkpoint. Time-ordered keys append to one hot page. This is the difference between an index that is 90% full and one that settles near 70% after page splits — on `chunks` and `document_elements` (the two tables that reach hundreds of millions of rows) that difference is measured in tens of gigabytes.

### The runnable example — `source_versions` and the pointer constraint

```sql
-- services/core-api/database/migrations/*_create_source_versions_table.php, via DB::statement().
-- Written as SQL because both runtimes read this table and neither owns the vocabulary.
CREATE TABLE source_versions (
    id                      char(26) COLLATE "C" PRIMARY KEY,          -- ULID
    organization_id         char(26) COLLATE "C" NOT NULL
                            REFERENCES organizations (id) ON DELETE RESTRICT,
    source_item_id          char(26) COLLATE "C" NOT NULL
                            REFERENCES source_items (id) ON DELETE RESTRICT,
    version_number          integer NOT NULL,
    content_hash            bytea   NOT NULL,        -- 32 raw bytes, not 64 hex chars
    ingest_key              char(64) COLLATE "C" NOT NULL,             -- kb-source-lifecycle ingest_key()
    parser_cfg_version      text NOT NULL,
    ocr_cfg_version         text NOT NULL,
    chunker_cfg_version     text NOT NULL,
    embedding_model_version text NOT NULL,
    status                  text NOT NULL,           -- 15 values; CHECK, not a PG enum (see Gotchas)
    warning_summary         jsonb,
    activated_at            timestamptz,
    retired_at              timestamptz,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT source_versions_status_check CHECK (status IN (
        'draft','queued','fetching','parsing','normalizing','chunking','embedding',
        'indexing','ready','ready_with_warnings','failed','disabled','deleting','deleted','archived')),
    CONSTRAINT source_versions_retire_after_activate
        CHECK (retired_at IS NULL OR activated_at IS NOT NULL)
);

-- THE pointer constraint. At most one live version per item, proven by the database.
-- Two publish_version() runs (Celery redelivery, kb-source-lifecycle) each read
-- current_version_id, each see the other's row as not-yet-committed, and each activate.
-- The second COMMIT gets 23505 and rolls back whole. A boolean is_active cannot do this,
-- and neither can SELECT ... FOR UPDATE on a row that does not exist yet.
CREATE UNIQUE INDEX source_versions_one_active_per_item
    ON source_versions (source_item_id)
    WHERE activated_at IS NOT NULL AND retired_at IS NULL;

-- Idempotency dedup. Doubles as the FK-child index for source_item_id, so no separate
-- single-column index is needed — PostgreSQL creates neither for you.
CREATE UNIQUE INDEX source_versions_item_ingest_key
    ON source_versions (source_item_id, ingest_key);

-- Tenant-leading composite: every read is "this org's versions in state X". org first,
-- always. Never (status, organization_id) — a status-leading index makes the tenant
-- predicate a filter over every org's rows in that state.
CREATE INDEX source_versions_org_status_created
    ON source_versions (organization_id, status, created_at DESC);

-- Orphan sweep: terminal versions that never activated (kb-source-lifecycle Gotchas).
-- Partial, so it indexes the ~0.1% of rows the sweeper cares about.
CREATE INDEX source_versions_never_activated
    ON source_versions (organization_id, created_at) WHERE activated_at IS NULL;

-- The deletion decision, in the same file because it is the same design.
CREATE TABLE citations (
    id            char(26) COLLATE "C" PRIMARY KEY,
    message_id    char(26) COLLATE "C" NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
    chunk_id      char(26) COLLATE "C"          REFERENCES chunks (id)   ON DELETE SET NULL,
    label         text  NOT NULL,      -- denormalized ON PURPOSE: the transcript must
    display_title text  NOT NULL,      -- stay readable after the source is purged, so
    location      jsonb NOT NULL,      -- everything the UI renders lives on this row and
    excerpt       text  NOT NULL       -- nothing it renders is fetched through chunk_id.
);
CREATE INDEX citations_chunk_id ON citations (chunk_id);  -- or SET NULL seq-scans citations
```

### Row-level security: not in production

**Decision: no RLS.** Isolation is enforced in code at seven layers (`kb-tenancy-isolation` NN7) and RLS would be an eighth that replaces none of them, at three specific costs. (1) The tenant GUC has to survive PgBouncer. `SET SESSION app.org_id` in transaction pooling leaks the *previous* tenant's value onto the next client of that backend — RLS then authorizes cross-tenant reads with a policy that reviews as correct. `SET LOCAL` is safe but only covers statements inside an explicit transaction, and neither Laravel's default request path nor a Celery task wraps its reads in one. (2) Outside a transaction `current_setting('app.org_id', true)` is NULL, the policy filters everything, the app breaks loudly, and the fix someone reaches for is `COALESCE(...)` or a permissive fallback — which fails *open*. (3) The migration role owns the tables and bypasses its own policies unless every table is `FORCE ROW LEVEL SECURITY`; one `GRANT` mistake makes the whole layer vacuous while the `pg_policies` view still looks reassuring.

What we do instead: `organization_id NOT NULL` on every tenant-owned table, org-leading composite indexes so the scoped query is also the fast query, and the CI greps in `kb-tenancy-isolation`'s Definition of done. RLS **may** be enabled in the CI database against a non-owner test role purely as a tripwire — an unscoped query returns fewer rows and the isolation test fails. That is a detector, never an authorization mechanism.

### Migrations that lock

`ACCESS EXCLUSIVE` blocks *readers*. Worse, it queues: a 30-second reporting query holds `ACCESS SHARE`, the DDL waits behind it, and every query arriving after the DDL waits behind the DDL. A one-millisecond catalog update takes the product down for 30 seconds.

| Operation | Lock | Scans / rewrites? |
|---|---|---|
| `ADD COLUMN` with a **non-volatile** default (literal, `now()`) | ACCESS EXCLUSIVE, momentary | no — value goes in `pg_attribute.attmissingval` |
| `ADD COLUMN ... DEFAULT gen_random_uuid()` / `clock_timestamp()`, identity, **stored** generated | ACCESS EXCLUSIVE, held throughout | **full table + all indexes rewritten** |
| `ADD COLUMN ... GENERATED ALWAYS AS (...) VIRTUAL` (PG 18 default) | ACCESS EXCLUSIVE, momentary | never rewrites |
| `CREATE INDEX` | `SHARE` | blocks all writes for the whole build; readers fine |
| `CREATE INDEX CONCURRENTLY` | SHARE UPDATE EXCLUSIVE | two passes; cannot run in a transaction |
| `ALTER COLUMN SET NOT NULL` | ACCESS EXCLUSIVE | full scan under the lock |
| `ADD CONSTRAINT ... NOT NULL ... NOT VALID` then `VALIDATE CONSTRAINT` (PG 18) | ACCESS EXCLUSIVE momentary, then **SHARE UPDATE EXCLUSIVE** | scan happens under the weak lock |
| `ALTER COLUMN TYPE` | ACCESS EXCLUSIVE | rewrite + reindex, unless binary-coercible and `USING` is a no-op |
| `ADD FOREIGN KEY` | SHARE ROW EXCLUSIVE (both tables) | scans child; `NOT VALID` + `VALIDATE` splits it |

Every migration touching a table with traffic opens with a bounded wait and retries rather than queueing:

```php
public $withinTransaction = false;   // CONCURRENTLY cannot run inside a transaction
public function up(): void {
    DB::statement("SET lock_timeout = '3s'");                      // 55P03 instead of a queue
    retry(10, fn () => DB::statement('ALTER TABLE chunks ADD COLUMN lang text'), 2000);
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS chunks_org_lang ON chunks (organization_id, lang)');
}
```

### JSONB, transactions, locks, pooling

**JSONB earns its place for three shapes only:** provider/bot **configuration snapshots** written once and read whole (`evaluation_runs.bot_configuration_snapshot`, `retrieval_traces.filters`), **capability flags** whose key set the provider owns and we do not (`provider_models.capability_flags`), and **warning summaries**. Anything queried by a predicate, aggregated, or joined on becomes a real column — `chunks.token_count` is an `integer`, not `metadata->>'token_count'`. Index cost: a `GIN ... jsonb_path_ops` index supports only `@>` but is roughly half the size of default `jsonb_ops`, which additionally supports `?`/`?|`/`?&`; **neither helps `data->>'k' = 'v'`** — that needs `CREATE INDEX ON t ((data->>'k'))`.

**Never call Qdrant, SeaweedFS, or a provider inside an open transaction.** The vector store is not transactional: a rollback leaves the points, a commit failure after a successful upsert leaves orphans, and the HTTP round trip pins `xmin` for its whole duration so autovacuum stops reclaiming dead tuples *database-wide*. `kb-source-lifecycle`'s `publish_version` gets the order right: upsert and verify outside, then one short transaction for the ready-mark and the pointer switch. When you need "if PostgreSQL committed then Qdrant must eventually see it", write an outbox row in the same transaction and let a Celery task drain it — not a two-phase commit Qdrant does not implement. Enforce it: `idle_in_transaction_session_timeout = '15s'` and `statement_timeout` on the application role.

**Isolation.** READ COMMITTED everywhere except read-modify-write on a value another transaction can change between the read and the write — quota admission (`SELECT remaining … then INSERT usage_events`) is write skew and needs `SELECT ... FOR UPDATE` on the `organizations` row or `SERIALIZABLE`. Any code path set to REPEATABLE READ or SERIALIZABLE must retry `40001` from the top; a serialization failure is not an error to log.

**Locks.** Valkey is the lock of record for cross-service jobs (`kb-deletion-and-verification`, `valkey-keyspaces`) because the lock must outlive a database connection. PostgreSQL advisory locks are for database-only singletons — the `Deleting` reaper, the retention sweeper — and must be the **`_xact_` variants** (`pg_advisory_xact_lock`), released by COMMIT. Use the two-argument `(classid, objid)` form with a fixed classid; the one-argument form over `hashtext(key)` is a 32-bit space where distinct keys collide.

**Pooling.** PgBouncer in **transaction** mode; `SET SESSION`, `LISTEN`, session advisory locks, and SQL-level `PREPARE` do not survive it, and protocol-level prepared statements need `max_prepared_statements > 0` (default 0). Session mode only for the migration role. Sizing and deployment: `platform-devops`.

**Not this skill:** Eloquent models, scopes, casts, and repository shape → `laravel-control-plane`. Vector storage and delete-by-filter → `qdrant-hybrid-search`. Cache/lock keyspaces → `valkey-keyspaces`. Backup, restore, PITR, and `pg_upgrade` → `platform-devops` (docs/18 §25.2).

## Gotchas

- **A routine deploy makes the whole API time out for 30 seconds and the migration itself took 4 ms.** `ALTER TABLE` requested `ACCESS EXCLUSIVE`, waited behind one long analytics query, and every request that arrived meanwhile queued *behind the waiter* — PostgreSQL's lock queue is ordered, so a blocked DDL blocks readers it would never have blocked. `SET lock_timeout` before every DDL statement and retry; a migration that fails fast ten times is a non-event, a migration that waits is an outage.
- **`ALTER TABLE ... ADD COLUMN embedded_at timestamptz DEFAULT clock_timestamp()` rewrites 400M rows and the deploy never finishes.** The fast path applies only to a *non-volatile* default; `now()` qualifies (evaluated once, same value for every existing row), `clock_timestamp()` and `gen_random_uuid()` do not. Add the column with no default, backfill in batches, then `ALTER COLUMN SET DEFAULT`.
- **Adding `NOT NULL` to a live table hangs it for the length of a full scan.** On PG 18 do it in two statements: `ADD CONSTRAINT c NOT NULL col NOT VALID` (momentary ACCESS EXCLUSIVE, no scan) then `VALIDATE CONSTRAINT c` (SHARE UPDATE EXCLUSIVE, scans while writes continue). On PG ≤17 the equivalent is a `NOT VALID` CHECK, validate, then `SET NOT NULL` — which skips its own scan because the valid CHECK proves it. Do **not** combine drop and set in one statement: the drop happens first and the optimization is lost.
- **`CREATE INDEX CONCURRENTLY` fails inside a Laravel migration with "cannot run inside a transaction block".** Laravel wraps each migration in a transaction on PostgreSQL because the driver supports transactional DDL. Set `public $withinTransaction = false;`. And check afterwards: a failed CONCURRENTLY build leaves an `indisvalid = false` index that is never used but is still maintained on every write — query `pg_index` for it and `DROP INDEX CONCURRENTLY` before retrying, or the next attempt fails on the duplicate name.
- **Deleting one source takes 40 minutes and the purge job times out in `Deleting` forever.** PostgreSQL does **not** index the referencing side of a foreign key. `chunks.source_version_id` unindexed means each `DELETE FROM source_versions` row does a sequential scan of `chunks` to check the constraint — hundreds of millions of rows, per version, holding locks. Every FK column gets an index, or is the leading column of one that already exists.
- **The customer deletes a source and their whole chat history goes blank.** `citations.chunk_id REFERENCES chunks ON DELETE CASCADE`. The cascade is invisible in the code that issues the delete; nobody drew the graph. Rule: for any `ON DELETE` other than `RESTRICT` you must be able to name every table reachable transitively. `citations.chunk_id` is `ON DELETE SET NULL` with label, title, location, and excerpt denormalized onto the citation row. `messages → conversations` may cascade (same lifetime, same retention). `organizations → *` is `RESTRICT`: org deletion runs through the purge worker in a defined order, not through one statement that takes an ACCESS EXCLUSIVE lock on forty tables and deadlocks.
- **A composite index exists for the query and PostgreSQL sequential-scans anyway.** The index is `(status, organization_id)` and the query filters both — but the planner sees an index whose leading column has 15 distinct values across every tenant. Tenant-owned indexes lead with `organization_id`, always. PG 18's b-tree **skip scan** does let a multicolumn index serve a query with no restriction on the leading column, but it works by enumerating that column's distinct values: with `organization_id` leading and thousands of orgs it is useless. Do not drop a narrower index expecting skip scan to cover it.
- **`ALTER TYPE ... ADD VALUE` for a new lifecycle state cannot be rolled back and blocks the deploy.** PostgreSQL enums cannot drop or rename a value, and before PG 12 could not add one in a transaction at all. Our status columns are `text` with a `CHECK` constraint: adding a state is `DROP CONSTRAINT` + `ADD CONSTRAINT ... NOT VALID` + `VALIDATE`, all reversible, and the 15 values in `kb-source-lifecycle` stay in one greppable place.
- **A nightly retention `DELETE` bloats `messages` past its own live size and every query slows down.** Deleting 30 days of rows from a heap leaves 30 days of dead tuples that autovacuum must find, and the space is reused, not returned. `messages`, `provider_calls`, `usage_events`, and `audit_logs` are `PARTITION BY RANGE (created_at)` monthly: retention becomes `DETACH PARTITION CONCURRENTLY` then `DROP TABLE`, which is O(1) and takes no ACCESS EXCLUSIVE lock on the parent. Every partitioned table's key must include `created_at`, so the primary key is `(id, created_at)` — plan that before the first row lands, because converting later is a full rewrite.
- **An `idempotency_keys` insert races itself and two workers both execute the operation.** `SELECT` then `INSERT` is not atomic at READ COMMITTED. Claim with `INSERT ... ON CONFLICT (organization_id, operation, key) DO NOTHING RETURNING id` — an empty result means someone else owns it. Do **not** reach for `MERGE`: it is a join, not a speculative insert, and PostgreSQL's own notes point at `INSERT ... ON CONFLICT` for exactly this. Two concurrent `MERGE`s that both take the NOT MATCHED branch raise a unique violation instead of updating.
- **Id comparison goes through a collation library, and a base-image bump silently corrupts the indexes.** Without `COLLATE "C"` a text index sorts through ICU/glibc; the ordering is a property of the *library version*, so a distro upgrade can leave a b-tree whose order no longer matches its collation, and lookups miss rows that are present. `COLLATE "C"` makes comparison `memcmp` and pins the ordering to bytes forever. ULIDs are Crockford base32 — byte order and lexicographic order already agree.
- **Provider credentials read back as mojibake after a restore.** Ciphertext in a `text` column goes through client encoding conversion; `pg_dump`/`restore` across a different `client_encoding` mangles bytes that were never valid UTF-8 to begin with. `bytea`, always, with `key_version` beside it so KEK rotation is a data change (`kb-security-baseline`).
- **`SELECT count(*)` on the admin dashboard takes 8 seconds per org.** An index-only scan needs the visibility map current, and on a table under constant insert it never is. Either accept the cost on a `WHERE organization_id = $1` counting query, or read from `usage_events` aggregates. Never use `pg_class.reltuples` for anything a tenant sees — it is a stale estimate that autovacuum updates.

## Official docs

- [PostgreSQL 18 release notes](https://www.postgresql.org/docs/18/release-18.html) — `uuidv7()`, b-tree skip scan, `NOT NULL ... NOT VALID`, virtual generated columns, async I/O.
- [Versioning policy](https://www.postgresql.org/support/versioning/) — supported majors and EOL dates; the source of the 18.x pin.
- [`ALTER TABLE`](https://www.postgresql.org/docs/18/sql-altertable.html) — which forms rewrite, which scan, and the `NOT VALID` / `VALIDATE CONSTRAINT` lock split.
- [Explicit locking](https://www.postgresql.org/docs/18/explicit-locking.html) — the lock-mode conflict matrix and advisory-lock functions.
- [Partial indexes](https://www.postgresql.org/docs/18/indexes-partial.html) — the single-active-version constraint.
- [JSON types and indexing](https://www.postgresql.org/docs/18/datatype-json.html#JSON-INDEXING) — `jsonb_ops` vs `jsonb_path_ops`.
- [Table partitioning](https://www.postgresql.org/docs/18/ddl-partitioning.html) — range partitioning and `DETACH PARTITION CONCURRENTLY`.
- [Transaction isolation](https://www.postgresql.org/docs/18/transaction-iso.html) — write skew and the `40001` retry contract.
- [`MERGE`](https://www.postgresql.org/docs/18/sql-merge.html) — the note directing concurrent upserts to `INSERT ... ON CONFLICT`.
- [PgBouncer configuration](https://www.pgbouncer.org/config.html) — pooling modes and `max_prepared_statements`.

## Definition of done

- [ ] Every new table has `organization_id char(26) COLLATE "C" NOT NULL` or a `NOT NULL` FK chain to one; a CI check fails on a new table with neither.
- [ ] Every id column is `char(26) COLLATE "C"`; `grep` shows no `gen_random_uuid()`/`uuidv4()` default and no `uuid` column except `chunks.vector_point_id`.
- [ ] `source_versions_one_active_per_item` exists; a concurrent double-publish integration test observes `23505` on the second commit and one active version afterwards.
- [ ] Every tenant-owned composite index leads with `organization_id`; `EXPLAIN (ANALYZE, BUFFERS)` on the changed queries shows an index scan, not a filter.
- [ ] Every FK referencing column is indexed or leads an existing index; a source-delete integration test on a seeded 1M-chunk fixture completes inside the purge job's timeout.
- [ ] No `ON DELETE CASCADE` reaches `citations`, `messages`, `retrieval_traces`, or `audit_logs`; a test deletes a source and asserts the prior transcript still renders label, title, location, and excerpt.
- [ ] Every migration touching a populated table sets `lock_timeout` and retries; `CONCURRENTLY` migrations set `$withinTransaction = false`; no `ADD COLUMN` carries a volatile default.
- [ ] No HTTP or Qdrant call appears between `BEGIN` and `COMMIT`; `idle_in_transaction_session_timeout` and `statement_timeout` are set on the application role.
- [ ] Advisory locks are `pg_advisory_xact_lock(classid, objid)`; no `SET SESSION` outside the migration role.
- [ ] `messages`, `provider_calls`, `usage_events`, `audit_logs` are range-partitioned on `created_at` with `created_at` in the primary key; retention drops partitions rather than deleting rows.
- [ ] `audit_logs` has no outbound FK and the application role has no `UPDATE`/`DELETE` on it.

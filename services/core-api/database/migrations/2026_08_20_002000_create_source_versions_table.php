<?php

declare(strict_types=1);

use App\Enums\SourceState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * ONE IMMUTABLE PROCESSING RESULT, and the table atomic publication is built on (docs/11 §16.4,
 * docs/08 §13, kb-source-lifecycle).
 *
 * Lifted from `postgresql-patterns`' runnable `source_versions` example, with the four Phase C1
 * rulings applied. What changed from that example, and why, is stated at each site rather than
 * left as a diff for somebody to notice.
 *
 * ═══ THE POINTER CONSTRAINT IS THE POINT OF THIS FILE ═════════════════════════════════════════
 *
 * `source_versions_one_active_per_item` — a PARTIAL UNIQUE INDEX on `(source_item_id)` where the
 * row is activated and not retired. Two `publish_version()` runs (a Celery redelivery is the
 * ordinary way this happens) each read `current_version_id`, each see the other's row as
 * not-yet-committed, and each activate. The second COMMIT gets 23505 and rolls back whole.
 *
 * A BOOLEAN `is_active` CANNOT DO THIS, and neither can `SELECT ... FOR UPDATE` on a row that does
 * not exist yet. That is `kb-source-lifecycle` NN2 and it is the reason this index is not merely a
 * uniqueness nicety: it is the only thing standing between a redelivered task and two live versions
 * of one page answering different questions.
 *
 * IT IS DELIBERATELY NOT TENANT-LEADING, and this is the one index in the cascade where that is
 * correct. `(organization_id, source_item_id)` would be a WEAKER constraint expressed as a wider
 * one: an item belongs to exactly one organization, so adding the tenant column cannot separate two
 * rows that the item column already collides — but it would read as though tenancy were part of the
 * guarantee, and the day somebody "fixes" it by adding a WHERE clause on the organization the
 * constraint quietly stops being global. The uniqueness being asserted is per ITEM, so the index is
 * on the item. It is transcribed from `postgresql-patterns` byte for byte for the same reason.
 *
 * ═══ THE FOREIGN KEY THIS FILE OWES `source_items` ════════════════════════════════════════════
 *
 * `source_items.current_version_id` points here and this table points back at `source_items`, so
 * one of the two constraints has to be added after both tables exist. That `ALTER TABLE` is at the
 * bottom of `up()` and its `DROP CONSTRAINT` is at the top of `down()`. `ON DELETE RESTRICT`, so a
 * version that is currently live cannot be deleted out from under the pointer — the purge must null
 * the pointer first, which is exactly the ordering kb-deletion-and-verification requires.
 *
 * ═══ RULING R2: THE COLUMN SPELLINGS, AND THE ONE THAT IS A BUG IF OMITTED ════════════════════
 *
 * `parser_cfg_version`, `ocr_cfg_version`, `chunker_cfg_version` — ALL THREE.
 * `database/factories/KnowledgeSourceFactory.php`'s docblock listed only two of them, omitting OCR,
 * and docs/08 §13.3's key list omits it as well while §13.4 makes an OCR config change a version
 * trigger. `services/ai-service/app/ingestion/identity.py:66-77` settles it: `ocr_cfg_version` is a
 * component of `INGEST_KEY_PARTS`. Omitting it means an OCR retune produces the same ingest key,
 * the request dedupes against the completed run, the admin sees "already processed", and the new
 * OCR settings never land — a silent no-op with a success message.
 *
 * `activated_at` AND `retired_at`, never `published_at`. The factory docblock said `published_at`.
 * The partial unique index above depends on BOTH columns — activated and not yet retired — so a
 * single `published_at` cannot express the constraint at all.
 *
 * ═══ RULING R4: `content_hash` IS HEX ═════════════════════════════════════════════════════════
 *
 * `char(64) COLLATE "C"` with a hex CHECK, departing from `postgresql-patterns`' `bytea`. The four
 * reasons are recorded in full on `source_items`, and the ADR is being written in parallel.
 * `ingest_key` is the same shape for the same reason — it IS a sha256 hexdigest, produced by
 * `identity.ingest_key()` and compared for exact equality and nothing else.
 *
 * ═══ THE DURABLE DELIVERY COUNTER ═════════════════════════════════════════════════════════════
 *
 * `delivery_count` exists because `services/ai-service/app/ingestion/tasks.py:92-95` says the
 * redelivery bound is "durable, counted in PostgreSQL per version — not `request.retries`, which a
 * requeue does not increment". `MAX_DELIVERIES = 3` is the data plane's constant and is
 * DELIBERATELY NOT A CHECK CONSTRAINT HERE: the worker's step 2 is "bump the counter; past the cap,
 * fail the version and `Ignore()`", which requires READING a value above the cap. A CHECK at 3
 * would make the bump itself raise, inside the task that is trying to give up, which is the retry
 * loop it exists to leave.
 *
 * WHO BUMPS IT IS AN OPEN INTERNAL CONTRACT AND IS FLAGGED RATHER THAN ASSUMED. This table is NOT
 * in `services/ai-service/app/db/writes.py`'s `ALLOWED_TABLES` and must not be — ADR-033 property 2
 * (no public API path reads or writes it) fails immediately, since Laravel serves this row. So the
 * worker cannot bump the column directly, and the increment has to arrive through a Laravel-owned
 * internal endpoint on the ingestion callback path. That endpoint is not written here; the column
 * it needs is.
 *
 * ═══ WHAT IS NOT ON THIS TABLE ════════════════════════════════════════════════════════════════
 *
 * `chunk_count`. It is tempting — publication verifies an expected total — but the count is
 * `SELECT count(*) FROM chunks WHERE ... source_version_id = ...` over rows the data plane writes,
 * and a denormalized copy would be a second number that can disagree with the first while both look
 * authoritative. The verification compares the worker's own count against Qdrant's `exact=True`
 * count, and neither of those is this table's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $states = $this->quotedList(SourceState::values());

        $this->run(<<<SQL
            CREATE TABLE source_versions (
                id                      char(26) COLLATE "C" PRIMARY KEY,
                organization_id         char(26) COLLATE "C" NOT NULL
                                        REFERENCES organizations (id) ON DELETE RESTRICT,
                source_item_id          char(26) COLLATE "C" NOT NULL,

                -- Monotonic within the item, and human-facing: "version 4 of this page". Unique
                -- per item below, so two concurrent runs cannot both claim a number.
                version_number          integer NOT NULL,

                -- R4: sixty-four lowercase hex characters, not thirty-two raw bytes.
                -- Hash the NORMALIZED content for crawls (§8.14 step 5) and the RAW BYTES for
                -- uploads. Getting that backwards is the defect where a crawl re-versions every
                -- page every night because the raw HTML carries a CSRF token, a render timestamp
                -- and an ad slot. Nothing here can enforce which one was hashed; it is recorded so
                -- the person reading this column knows what it is supposed to mean.
                content_hash            char(64) COLLATE "C" NOT NULL,

                -- THE IDEMPOTENCY KEY, and it is what makes every one of the six version triggers
                -- reachable. `identity.ingest_key()` is sha256 over scheme, org, source, item,
                -- content hash, the THREE config versions, the embedding model version, and the
                -- force nonce — joined with "|". A key missing any component makes that component's
                -- trigger a silent no-op.
                ingest_key              char(64) COLLATE "C" NOT NULL,

                -- R2: ALL THREE CONFIG VERSIONS. See the docblock for what omitting the OCR one
                -- costs. Plain `text` and not COLLATE "C": these are `scheme:label` strings that a
                -- human reads in an operator console.
                parser_cfg_version      text NOT NULL,
                ocr_cfg_version         text NOT NULL,
                chunker_cfg_version     text NOT NULL,

                -- `EmbeddingModelIdentity.version` verbatim, e.g.
                -- emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1 — provider, model id,
                -- returned width, and a digest over a fixed probe set. NOT a bare vendor model
                -- name: that is an alias, and ADR-035 exists because an alias can be re-pointed at
                -- different weights with no diff anywhere in this repository.
                embedding_model_version text NOT NULL,

                -- R3: the same fifteen values knowledge_sources.status carries.
                status                  text NOT NULL DEFAULT 'draft',

                -- ADVISORY PARSER AND OCR WARNINGS (§8.11), which never gate retrieval — a
                -- `ready_with_warnings` version is identical to `ready` for every query. One of the
                -- three shapes postgresql-patterns admits jsonb for: written once, read whole,
                -- never queried by predicate and never joined on.
                --
                -- NOT NULL WITH A `{}` DEFAULT, which is a deliberate adjustment to
                -- postgresql-patterns' example (where the column is nullable). The reason is the
                -- cast: App\Support\Casts\JsonObjectCast writes `{}` for an empty map and reads
                -- NULL back as an empty map, so a nullable column would hold two spellings of "no
                -- warnings" that no reader could tell apart. The CHECK pins the JSON type, which is
                -- the other half of the same story — the built-in `array` cast serializes an empty
                -- PHP array as `[]`, a JSON ARRAY, and this constraint refuses it by name.
                warning_summary         jsonb NOT NULL DEFAULT '{}'::jsonb,

                -- THE DURABLE REDELIVERY BOUND. See the docblock: no CHECK at MAX_DELIVERIES, on
                -- purpose.
                delivery_count          integer NOT NULL DEFAULT 0,

                -- R2: activated AND retired. Both nullable, both load-bearing in the partial
                -- unique index below.
                activated_at            timestamptz,
                retired_at              timestamptz,

                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT source_versions_status_check CHECK (status IN ({$states})),

                -- ── R4: the two digests are lowercase hex, and the columns say so ────────────
                -- `char(64)` alone would accept sixty-four spaces. An UPPERCASE digest is the case
                -- that matters: it compares unequal under COLLATE "C", so a corpus that had not
                -- changed would re-embed at a provider's per-token price and nothing would raise.
                CONSTRAINT source_versions_content_hash_is_hex
                    CHECK (content_hash ~ '^[0-9a-f]{64}\$'),
                CONSTRAINT source_versions_ingest_key_is_hex
                    CHECK (ingest_key ~ '^[0-9a-f]{64}\$'),

                -- ── the config versions are present and non-blank ───────────────────────────
                -- A blank config version is a component of the ingest key that contributes
                -- nothing, which makes the trigger it represents undetectable.
                CONSTRAINT source_versions_config_versions_present CHECK (
                    btrim(parser_cfg_version) <> ''
                    AND btrim(ocr_cfg_version) <> ''
                    AND btrim(chunker_cfg_version) <> ''
                    AND btrim(embedding_model_version) <> ''
                ),

                CONSTRAINT source_versions_version_number_positive CHECK (version_number >= 1),
                CONSTRAINT source_versions_delivery_count_non_negative CHECK (delivery_count >= 0),

                -- ── the warning summary is an OBJECT ────────────────────────────────────────
                CONSTRAINT source_versions_warning_summary_is_object
                    CHECK (jsonb_typeof(warning_summary) = 'object'),

                -- ── activation ordering ─────────────────────────────────────────────────────
                -- postgresql-patterns' constraint, kept verbatim: a version cannot be retired if
                -- it was never activated, because "retired" names the end of a period that never
                -- began.
                CONSTRAINT source_versions_retire_after_activate
                    CHECK (retired_at IS NULL OR activated_at IS NOT NULL),
                -- And the ordering within the period, which the example leaves implicit. A
                -- retirement timestamp before the activation would make every "what was live at
                -- time T" query return two versions or none.
                CONSTRAINT source_versions_retire_not_before_activate
                    CHECK (retired_at IS NULL OR retired_at >= activated_at),

                -- ── the ownership guard ─────────────────────────────────────────────────────
                CONSTRAINT source_versions_item_same_org
                    FOREIGN KEY (organization_id, source_item_id)
                    REFERENCES source_items (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // The target of `document_elements`', `chunks`' and — in the seventh migration of this set
        // — `sparse_version_statistics`' and `sparse_term_frequencies`' composite foreign keys.
        // Vacuous as a uniqueness constraint because `id` is already the primary key.
        $this->run(
            'CREATE UNIQUE INDEX source_versions_org_scoped_key '
            .'ON source_versions (organization_id, id)',
        );

        // ── THE POINTER CONSTRAINT ────────────────────────────────────────────────────────────
        //
        // At most one live version per item, proven by the database. Transcribed from
        // postgresql-patterns byte for byte; see the class docblock for why it is not
        // tenant-leading and must not be made so.
        $this->run(
            'CREATE UNIQUE INDEX source_versions_one_active_per_item '
            .'ON source_versions (source_item_id) '
            .'WHERE activated_at IS NOT NULL AND retired_at IS NULL',
        );

        // IDEMPOTENCY DEDUP. `UNIQUE (source_item_id, ingest_key)` is what makes a re-request for
        // content and configuration that have not changed resolve to the existing version instead
        // of minting a second one. Also transcribed verbatim.
        $this->run(
            'CREATE UNIQUE INDEX source_versions_item_ingest_key '
            .'ON source_versions (source_item_id, ingest_key)',
        );

        // THE VERSION HISTORY OF ONE ITEM, AND THE FK-CHILD INDEX FOR `source_item_id`, IN ONE
        // OBJECT. postgresql-patterns' example notes that the ingest-key index doubles as the
        // FK-child index — which was true for its SINGLE-column foreign key and is only half true
        // for the COMPOSITE one this schema uses, because the referential-integrity probe names
        // (organization_id, source_item_id) and that index leads with the item alone. This one
        // leads with the pair, so the probe is an index scan rather than a scan plus a filter, and
        // it additionally makes "version 4 of this page" and "the newest version of this page"
        // index-only reads.
        $this->run(
            'CREATE UNIQUE INDEX source_versions_org_item_version '
            .'ON source_versions (organization_id, source_item_id, version_number)',
        );

        // The operator read: "this organization's versions in state X, newest first".
        // Tenant-leading, always.
        $this->run(
            'CREATE INDEX source_versions_org_status_created '
            .'ON source_versions (organization_id, status, created_at DESC)',
        );

        // THE ORPHAN SWEEP. A run that dies between the upsert and the pointer switch leaves a
        // complete set of points for a version that never activated: invisible to retrieval (the
        // version filter excludes them) AND invisible to deletion (deletion walks the active chunk
        // set), so they accumulate silently until the collection is oversized and slow. Partial, so
        // it indexes the fraction of a percent of rows the sweeper cares about.
        $this->run(
            'CREATE INDEX source_versions_never_activated '
            .'ON source_versions (organization_id, created_at) WHERE activated_at IS NULL',
        );

        // ── THE OWED FOREIGN KEY, THE OTHER HALF OF THE POINTER ───────────────────────────────
        //
        // `source_items.current_version_id` could not carry this when that table was created,
        // because this one did not exist yet. RESTRICT rather than SET NULL: a SET NULL would let a
        // purge silently un-publish a live page — the source stops answering, nothing raises, and
        // the only evidence is a NULL where a pointer used to be. The purge must retire and re-point
        // before it deletes, which is the ordering kb-deletion-and-verification already requires.
        $this->run(<<<'SQL'
            ALTER TABLE source_items
                ADD CONSTRAINT source_items_current_version_same_org
                FOREIGN KEY (organization_id, current_version_id)
                REFERENCES source_versions (organization_id, id) ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        // Reverse order: the constraint on the OTHER table first, or the drop below fails on a
        // dependency that outlives its own migration.
        $this->run(
            'ALTER TABLE source_items DROP CONSTRAINT IF EXISTS source_items_current_version_same_org',
        );
        $this->run('DROP TABLE IF EXISTS source_versions');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

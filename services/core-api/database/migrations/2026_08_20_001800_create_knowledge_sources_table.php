<?php

declare(strict_types=1);

use App\Enums\SourceState;
use App\Enums\SourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE ADMIN'S UNIT OF INTENT: one upload, one sitemap, one crawl configuration, one paste
 * (docs/03 §8.9, docs/11 §16.4, kb-source-lifecycle "Three levels of identity").
 *
 * This is the first of the six tables in the source cascade — `knowledge_sources` ->
 * `source_items` -> `source_versions` -> `document_elements` / `chunks`, plus
 * `bot_source_assignments` off to the side — and the ordering of those migrations is the FK
 * dependency order, not a preference.
 *
 * ═══ WHY THIS CASCADE LANDS NOW: FINDING #79 ══════════════════════════════════════════════════
 *
 * `services/ai-service/app/deletion/relational.py` has been issuing `DELETE FROM chunks ...` and
 * `DELETE FROM document_elements ...` against tables NO MIGRATION IN THIS REPOSITORY CREATED. That
 * is ADR-033 property 3 — "Laravel owns the migration" — violated in the open, pinned by ruling on
 * 2026-08-12 because both available repairs were worse than the finding at the time. These
 * migrations are the repair the ruling was waiting for. The purge statements in `relational.py` are
 * transcribed below wherever a column or an index exists to serve one, so the two sides can be read
 * against each other rather than trusted to agree.
 *
 * ═══ SQL, NOT THE SCHEMA BUILDER ══════════════════════════════════════════════════════════════
 *
 * Raw SQL through `run()` for the reasons 2026_08_07_000100 records, and here they are all load
 * bearing at once: `char(26) COLLATE "C"` keys, `text` + CHECK instead of a native PG enum,
 * `timestamptz`, COMPOSITE foreign keys, a PostgreSQL `text[]` column, and a partial unique index
 * two migrations down that is the whole of atomic publication. The Schema builder can express none
 * of them.
 *
 * `Schema::getConnection()->statement()` and NEVER the `DB` facade: `tests/Arch/DoctrineTest.php`
 * pins that facade to `App\Repositories\Eloquent`, where the organization scope is applied.
 *
 * NO QUESTION MARK APPEARS IN ANY SQL STRING OR ANY SQL COMMENT IN THIS FILE, and the `{0,1}`
 * spellings below are that rule rather than a taste in regular expressions: PDO rewrites a bare
 * question mark into a positional placeholder while scanning the statement and does not reliably
 * skip SQL comments while doing so. A statement that will not prepare is a cheap failure; one that
 * prepares against the wrong parameter count is not.
 *
 * ═══ RULING R1: THERE IS NO ACTIVE-VERSION POINTER ON THIS TABLE ══════════════════════════════
 *
 * docs/11 §16.4 lists "Current version ID" among this table's columns AND among `source_items`'.
 * ONLY THE SECOND ONE IS BUILT, and the divergence is deliberate.
 *
 * A source-level pointer is MEANINGLESS the moment a crawl gives one source four hundred
 * independently-versioned items: there is no single current version of a sitemap, and a column that
 * claims otherwise will be read by somebody. `kb-source-lifecycle` is explicit that the item is
 * "the unit of independent versioning" and that "nothing reads a version pointer off
 * `knowledge_sources`" — and `services/ai-service/app/db/writes.py:50` states from the other side
 * that the data plane never assigns the pointer at all. The partial unique index that makes
 * publication atomic is keyed on `source_item_id`; a second pointer here would be a denormalized
 * copy with no constraint able to hold the two in step.
 *
 * WHAT A READER WHO WANTS "THE CURRENT VERSION OF THIS SOURCE" ACTUALLY WANTS is the set of active
 * versions across the source's items, which is a join and is what the retrieval-scope resolution
 * already performs.
 *
 * ═══ RULING R3: ONE FIFTEEN-VALUE STATUS VOCABULARY ═══════════════════════════════════════════
 *
 * `status` here and `source_versions.status` two migrations down carry the SAME CHECK list,
 * generated from `App\Enums\SourceState::values()` — the fifteen values of
 * `services/ai-service/app/ingestion/states.py`, in contract order.
 *
 * A NARROWER SUBSET PER TABLE WOULD BE DEFENSIBLE ON PAPER AND IS REFUSED. `Draft` and `Archived`
 * are source-level and the processing states are version-level, so a tempting reading is that this
 * column should admit only the source-level subset. But `kb-source-lifecycle` has the version's
 * processing state ROLLED UP onto the item and the source for display, so a CHECK here that refused
 * `Parsing` would refuse the row the rollup writes — and it would refuse it at 3am inside an
 * ingestion callback, not in review.
 *
 * The factory docblock in `database/factories/KnowledgeSourceFactory.php` carried a SEVEN-value
 * list including `pending` and `processing`. Neither string appears anywhere in `SourceState`; that
 * list is superseded, and the factory has been corrected in the same change.
 *
 * ═══ WHAT IS DELIBERATELY NOT ON THIS TABLE ═══════════════════════════════════════════════════
 *
 * `crawl_configuration_id` — docs/11 §16.4 lists it and there is no `crawl_configurations` table in
 * this repository. A column with no foreign key pointing at a table that does not exist is exactly
 * the shape `2026_08_07_000600` refused for `sparse_*.source_version_id`, and it reported the debt
 * rather than inventing the parent. Same call: the crawl configuration lands with the crawl
 * surface, and `origin_url` below carries the one fact a crawl source cannot be created without.
 *
 * `storage_key` and `content_hash` — they are per ITEM, not per source, because a source with four
 * hundred pages has four hundred of each. They are on `source_items`.
 *
 * A `display_name` for an uploaded file — also per item, and it is the user's FILENAME, which is
 * never a path (`kb-security-baseline/references/file-upload-safety.md`: "Generate the storage key
 * yourself ... Keep the user's filename in a `display_name` column"). `name` here is the admin's
 * label for the whole source and is a different string with a different provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = $this->quotedList(SourceType::values());
        $states = $this->quotedList(SourceState::values());

        $this->run(<<<SQL
            CREATE TABLE knowledge_sources (
                id                char(26) COLLATE "C" PRIMARY KEY,
                organization_id   char(26) COLLATE "C" NOT NULL
                                  REFERENCES organizations (id) ON DELETE RESTRICT,

                -- WHAT KIND OF THING THIS IS, and deliberately not what kind of FILE it is. The
                -- parser decides that from the sniffed MIME type; a client-settable file type is
                -- the input kb-security-baseline's upload rules refuse to trust.
                type              text NOT NULL,

                -- The admin's label. Plain `text` and not COLLATE "C": it is sorted and searched
                -- for humans, so the database's collation is the right answer.
                name              text NOT NULL,
                description       text,

                -- THE CRAWL TARGET, AND THE ONE COLUMN ON THIS TABLE A SECURITY REVIEW READS.
                -- COLLATE "C" because it is only ever compared for exact equality. It is present
                -- exactly when the source is a URL and absent otherwise, so "which sources cause us
                -- to make outbound requests" is answerable from the column being NOT NULL rather
                -- than from a join. The SSRF envelope that decides whether a given URL may be
                -- fetched is kb-security-baseline's and lives in the crawler; this is where the
                -- operator's request is recorded.
                origin_url        text COLLATE "C",

                -- Fifteen values, shared with source_versions. See the docblock (R3).
                status            text NOT NULL DEFAULT 'draft',

                -- A REAL PostgreSQL ARRAY, not jsonb and not a join table. postgresql-patterns
                -- admits jsonb for three shapes and a flat set of short labels is none of them; a
                -- `source_tags` table would be a second entity with its own tenancy to get right,
                -- for a value nothing joins on. App\Support\Casts\PostgresTextArrayCast is what
                -- keeps the PHP side honest — the built-in `array` cast writes JSON into this
                -- column and the failure is silent in both directions.
                tags              text[] NOT NULL DEFAULT '{}'::text[],

                -- TIME-SCOPED KNOWLEDGE (docs/11 §16.4). Retrieval must stop returning a policy
                -- once it lapses, which is why kb-chunking-rules carries the same pair down onto
                -- every chunk: a filter cannot reach up the FK chain at query time.
                effective_at      timestamptz,
                expires_at        timestamptz,

                -- WHO ADDED IT. RESTRICT rather than SET NULL: a source whose creator is unknown is
                -- a source nobody can be asked about, and there is no user-deletion path in this
                -- application for the constraint to obstruct. Nullable because the bootstrap
                -- command and any future system-created source have no user behind them.
                created_by        char(26) COLLATE "C" REFERENCES users (id) ON DELETE RESTRICT,

                -- THE TWO-PHASE DELETE (kb-deletion-and-verification), as two columns because they
                -- are two different facts and an incident asks about both. `deleted_at` is the
                -- moment the source stopped being retrievable — logical exclusion, immediate, the
                -- only thing a customer experiences. `purged_at` is the moment the background purge
                -- was VERIFIED, which is what a retention or erasure obligation is actually
                -- measured against. Collapsing them into one timestamp makes "we removed it" and
                -- "we proved we removed it" the same claim, and only one of those is defensible.
                --
                -- The deletion PATHS themselves are deletion-engineer's, on both sides of the
                -- Laravel/FastAPI seam. These columns are the schema they need.
                deleted_at        timestamptz,
                purged_at         timestamptz,

                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabularies, generated from the enums ─────────────────────────
                CONSTRAINT knowledge_sources_type_check   CHECK (type   IN ({$types})),
                CONSTRAINT knowledge_sources_status_check CHECK (status IN ({$states})),

                -- ── blank is not a value ─────────────────────────────────────────────────────
                -- NULL means "not set" and is a real state for the nullable columns below. An
                -- empty or whitespace-only string would be a SECOND spelling of it that every
                -- renderer would have to test for separately, and one of them would forget.
                CONSTRAINT knowledge_sources_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT knowledge_sources_description_not_blank
                    CHECK (description IS NULL OR btrim(description) <> ''),

                -- ── the origin URL is present exactly when the source is a URL ───────────────
                -- Written as an equality between two booleans rather than as two implications, so
                -- BOTH mistakes are refused by one constraint: a crawl source with nothing to
                -- crawl, and a file upload carrying a URL somebody expects us to fetch.
                CONSTRAINT knowledge_sources_origin_url_matches_type
                    CHECK ((type = 'url') = (origin_url IS NOT NULL)),
                -- The scheme allow-list, at the column. This is NOT the SSRF check — that is the
                -- crawler's, it resolves DNS and re-checks after every redirect, and nothing a
                -- CHECK constraint can express substitutes for it. What this refuses is the class
                -- of value that should never have reached the column at all: file://, gopher://,
                -- and the credential-carrying userinfo forms that make a stored URL a way to hand
                -- a secret to whoever reads the row. `{0,1}` and not the one-character optional
                -- quantifier — see the docblock.
                CONSTRAINT knowledge_sources_origin_url_scheme
                    CHECK (origin_url IS NULL OR origin_url ~ '^https{0,1}://[^@[:space:]]+\$'),

                -- ── the tag set ──────────────────────────────────────────────────────────────
                -- A NULL element makes `list<string>` a lie on the PHP side; the blank element is
                -- a tag nobody can type and nobody can remove from a filter UI. `&&` is array
                -- overlap, which is the subset test written without a subquery — a CHECK
                -- constraint may not contain one.
                CONSTRAINT knowledge_sources_tags_well_formed CHECK (
                    array_position(tags, NULL) IS NULL
                    AND NOT (tags && ARRAY['']::text[])
                    AND cardinality(tags) <= 50
                ),

                -- ── time windows and deletion timestamps must be orderable ───────────────────
                -- A window that closes before it opens is a source that is never retrievable and
                -- reads in the console as though it were.
                CONSTRAINT knowledge_sources_window_ordered CHECK (
                    expires_at IS NULL OR effective_at IS NULL OR expires_at > effective_at
                ),
                -- A purge that predates the logical delete would mean the vectors went before the
                -- source stopped answering — the exact ordering kb-deletion-and-verification
                -- exists to forbid — and a purge with no delete at all is a row claiming a proof
                -- for something that never happened.
                CONSTRAINT knowledge_sources_purge_follows_delete CHECK (
                    purged_at IS NULL OR (deleted_at IS NOT NULL AND purged_at >= deleted_at)
                )
            )
        SQL);

        // THE TARGET OF EVERY CHILD TABLE'S COMPOSITE FOREIGN KEY, and — like `bots_org_scoped_key`
        // — vacuous as a uniqueness constraint, because `id` is already the primary key.
        // `source_items`, `chunks` and `bot_source_assignments` all point at it, so "this child's
        // source belongs to this child's organization" is a database fact rather than a service's
        // promise.
        $this->run(
            'CREATE UNIQUE INDEX knowledge_sources_org_scoped_key '
            .'ON knowledge_sources (organization_id, id)',
        );

        // The admin list: "this organization's sources in state X, newest first". TENANT-LEADING,
        // always — never (status, organization_id), which would make the tenant predicate a filter
        // over every organization's rows in that state. PG 18's b-tree skip scan does not rescue a
        // status-leading index here: it works by enumerating the leading column's distinct values,
        // and at organization scale that is thousands of them.
        $this->run(
            'CREATE INDEX knowledge_sources_org_status_created '
            .'ON knowledge_sources (organization_id, status, created_at DESC)',
        );

        // THE FK-CHILD INDEX FOR `users`, AND IT IS THE ONE INDEX IN THIS CASCADE THAT DOES NOT
        // LEAD WITH THE TENANT. That is not an exception to the rule, it is the rule being read
        // correctly: tenant-leading is about the queries a TENANT issues, and this index exists for
        // the referential-integrity check PostgreSQL runs when a `users` row is deleted, which
        // names `created_by` and nothing else. An (organization_id, created_by) index would not
        // serve it, because the RI probe has no organization in hand — it is deleting a user, who
        // may belong to several.
        //
        // No `// tenancy-exempt:` marker: that convention is for a QUERY that legitimately omits an
        // organization scope, and an index is not a query. Nothing reads this index without one.
        $this->run('CREATE INDEX knowledge_sources_created_by ON knowledge_sources (created_by)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS knowledge_sources');
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
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

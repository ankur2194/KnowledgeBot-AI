<?php

declare(strict_types=1);

use App\Enums\ChunkContentType;
use App\Enums\ChunkIndexStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE RETRIEVABLE UNIT, and the second table of finding #79.
 *
 * ═══ FINDING #79, THE OTHER HALF ══════════════════════════════════════════════════════════════
 *
 * `services/ai-service/app/deletion/relational.py:200-210` holds four statements against this
 * table — the version purge, its count, the terminal org-residue delete and its count — and no
 * migration in this repository created it. `RELATIONAL_PURGE_ORDER` puts `chunks` FIRST, before
 * `document_elements`, and the schema below is what makes that order not merely a convention: every
 * chunk references its element with `ON DELETE RESTRICT`, so removing the elements first would be
 * refused by the database rather than silently orphaning a citation.
 *
 * ═══ THE COLUMN SET IS `ChunkMetadata`, FIELD FOR FIELD ═══════════════════════════════════════
 *
 * `chunker.py:189-287` declares a frozen dataclass of thirty-two fields WITH NO DEFAULTS, and the
 * absence of defaults is the design: "every field, every chunk". A partially-populated schema is
 * the one defect that breaks citation and deletion at the same time, which is why the table
 * mirrors it rather than storing the metadata as a jsonb bag. `postgresql-patterns` says the same
 * thing from the other side — anything queried by a predicate is a real column, and
 * `chunks.token_count` is an `integer`, not `metadata->>'token_count'`.
 *
 * TWO FIELDS ARE STRUCTURALLY DIFFERENT HERE AND ONE IS ABSENT. All three are stated rather than
 * left as a diff:
 *
 *   `chunk_id`   is this table's `id`. It is the same value; a second column holding it would be
 *                a primary key with an alias.
 *   `row_range`  is a Python 2-tuple and becomes `row_start` / `row_end`, two nullable integers
 *                held together by a CHECK. An `int4range` was the alternative and is worse: nothing
 *                queries it by containment, `range_agg` is not wanted, and the pair prints legibly
 *                in a citation ("rows 2-15") without an accessor.
 *   `bot_ids`    IS NOT A COLUMN, and this is the one deliberate departure from "the table is those
 *                fields". It is the Qdrant PAYLOAD term, written at upsert time; its authoritative
 *                home in PostgreSQL is `bot_source_assignments`, one row per grant, with the
 *                composite keys that make a cross-organization assignment impossible. A denormalized
 *                array here would be a SECOND statement of that grant with no constraint able to
 *                hold the two in step — and it would make assigning a source to one more bot an
 *                UPDATE over every chunk of every version of every item of that source, which on
 *                this table is tens of millions of rows for a checkbox. ADR-010's rebuildability is
 *                satisfied without it: a payload rebuild reads `chunks` joined to
 *                `bot_source_assignments`, both of which are in PostgreSQL.
 *                `kb-tenancy-isolation` already records that `bot_ids` "lags a payload rewrite, and
 *                that is fine" — it is one of the two redundant filter terms that exist to fail
 *                closed, not to be timely.
 *
 * ═══ `vector_point_id` IS THE ONE LEGITIMATE `uuid` COLUMN IN THIS SCHEMA ═════════════════════
 *
 * Qdrant point ids may only be u64 or UUID, so the readable key `org:version:seq` is folded through
 * `uuid5(POINT_NS, ...)` by `app/ingestion/identity.py`. Everything else in this database is a ULID
 * in `char(26) COLLATE "C"`, and `postgresql-patterns`' definition of done greps for exactly that
 * exception by name.
 *
 * IT IS DETERMINISTIC, AND THE DETERMINISM IS WHAT MAKES A RETRY SAFE: a redelivered upsert
 * overwrites what it already wrote instead of adding a second copy. Random ids plus at-least-once
 * delivery means retrieval returns the same passage twice, the context budget is spent on
 * duplicates, and the deletion query removes only one set. The UNIQUE index below is what turns a
 * mistake in that derivation into an insert failure rather than into two rows pointing at one point.
 * NEVER ROTATE `POINT_NS`: every existing point instantly becomes an orphan no deletion query can
 * reach.
 *
 * ═══ `text` IS MANDATORY AND IS NOT A CACHE ═══════════════════════════════════════════════════
 *
 * ADR-010: PostgreSQL is the source of truth and Qdrant is derived, which means nothing in the
 * collection may be unreconstructable from these tables plus object storage. `chunks.text` and
 * `chunks.vector_point_id` are therefore mandatory columns rather than conveniences — without the
 * first a rebuild cannot re-embed, and without the second it cannot name what it is replacing.
 *
 * It is also UNTRUSTED DATA (non-negotiable 7). Nothing downstream may treat a byte of it as an
 * instruction. No constraint here can help with that; it is said because this is the column the
 * untrusted bytes are read back out of.
 *
 * ═══ R4: `content_hash` IS HEX ════════════════════════════════════════════════════════════════
 *
 * `char(64) COLLATE "C"`, matching `source_items` and `source_versions`. `chunker.py:963` is
 * `sha256(...).hexdigest()` and the Qdrant payload dedupes on that string. The full argument and
 * the cost of departing from `postgresql-patterns`' `bytea` are on `source_items`; the ADR is being
 * written in parallel.
 *
 * ═══ THE INDEX BUDGET, STATED BECAUSE IT IS THE LARGEST TABLE IN THE SCHEMA ═══════════════════
 *
 * Eight indexes, and every one of them is either a constraint or a foreign key's referencing side —
 * PostgreSQL creates neither for you, and the failure when one is missing is
 * `postgresql-patterns`' worked example: "Deleting one source takes 40 minutes and the purge job
 * times out in Deleting forever". Three of the eight are PARTIAL, on nullable reference columns,
 * which is safe for a referential-integrity probe because the probe binds a NOT NULL value and the
 * planner can prove `col = $2` implies `col IS NOT NULL`.
 *
 * THERE IS NO INDEX ON `index_status`, DELIBERATELY. The rebuild sweep that would use one does not
 * exist yet, and a ninth b-tree maintained on every insert into this table is not a thing to add
 * for a query nobody has written. When that sweep lands it gets a partial index — `WHERE
 * index_status <> 'indexed'` — in the same change as the query.
 */
return new class extends Migration
{
    public function up(): void
    {
        $contentTypes = $this->quotedList(ChunkContentType::values());
        $indexStatuses = $this->quotedList(ChunkIndexStatus::values());

        $this->run(<<<SQL
            CREATE TABLE chunks (
                -- ChunkMetadata.chunk_id. Relational chunk identity, and NOT what the point id is
                -- derived from — that is uuid5 over org:version:seq, because a chunk id minted per
                -- run is not stable across a replay.
                id                      char(26) COLLATE "C" PRIMARY KEY,

                -- ── identity and tenancy ────────────────────────────────────────────────────
                -- Denormalized down the whole chain on purpose: the four ids are four of the six
                -- mandatory Qdrant payload fields, written at upsert FROM THIS ROW rather than from
                -- a job argument. A payload assembled from a job argument is a payload that can
                -- disagree with the database.
                organization_id         char(26) COLLATE "C" NOT NULL
                                        REFERENCES organizations (id) ON DELETE RESTRICT,
                source_id               char(26) COLLATE "C" NOT NULL,
                source_item_id          char(26) COLLATE "C" NOT NULL,
                source_version_id       char(26) COLLATE "C" NOT NULL,

                -- ── position in the document ────────────────────────────────────────────────
                -- `seq` is document order AND a component of the point id, so it must be
                -- deterministic across a replay. Unique per version below.
                seq                     integer NOT NULL,
                document_element_id     char(26) COLLATE "C" NOT NULL,
                -- A prose chunk spans several elements and the singular column above loses all but
                -- one of them. Deletion and citation both need the full set, so it is stored as an
                -- array rather than as a join table: nothing joins on it, it is read whole with the
                -- row, and a `chunk_elements` table would be tens of millions of rows to express an
                -- ordered list that is never queried by its members.
                element_ids             char(26)[] NOT NULL,
                -- Small-to-big: retrieve the child, return the enclosing section.
                parent_element_id       char(26) COLLATE "C",
                -- The embedded prefix AND the citation's "where in the document". Empty only for a
                -- genuinely flat document; an empty path on a structured one means the heading
                -- stack was not carried, and the citation degrades to "Terms.pdf, page 7".
                heading_path            text[] NOT NULL DEFAULT '{}'::text[],

                -- ── citation locators ───────────────────────────────────────────────────────
                page                    integer,
                -- Set only when a chunk legitimately crosses a page break, which it may do only to
                -- complete an unterminated sentence. Without it the citation points at page 7 while
                -- the excerpt is on page 8.
                page_end                integer,
                slide                   integer,
                sheet                   text COLLATE "C",
                -- Named table or region. Disambiguates two tables on one sheet, where "rows 2-15"
                -- alone names neither.
                table_ref               text COLLATE "C",
                row_start               integer,
                row_end                 integer,
                url                     text COLLATE "C",
                -- HTML id or heading slug. Without it a crawl citation opens the page at the top
                -- and the user cannot find the sentence.
                anchor                  text COLLATE "C",
                -- Offsets into the normalized document: the excerpt highlight the citation contract
                -- promises. A page number alone cannot produce it.
                char_start              integer NOT NULL,
                char_end                integer NOT NULL,

                -- ── content facts ───────────────────────────────────────────────────────────
                lang                    text COLLATE "C" NOT NULL,
                content_type            text NOT NULL,
                -- From the injected `measure`, excluding sentinel tokens. An ESTIMATE rather than a
                -- vendor's own count since ADR-030 removed the local tokenizer, and recorded
                -- because it is the only evidence available if a provider is later found to be
                -- trimming long passages silently.
                token_count             integer NOT NULL,
                -- R4. sha256 of the exact string passed to the embedder, prefix included.
                content_hash            char(64) COLLATE "C" NOT NULL,
                -- The chunk this one overlaps, so packing can drop a near-duplicate instead of
                -- spending two evidence slots on the same paragraph.
                overlap_of              char(26) COLLATE "C",

                -- ── the text itself, and the point it became ────────────────────────────────
                -- Mandatory, not a cache. See the docblock.
                text                    text NOT NULL,
                vector_point_id         uuid NOT NULL,
                index_status            text NOT NULL DEFAULT 'pending',

                -- ── config identity ─────────────────────────────────────────────────────────
                -- `embedding_model_id` is EmbeddingModelIdentity.version verbatim, and it is the
                -- DURABLE record of what each vector was made by. That is what makes ADR-010's
                -- rebuild answerable after a provider swap: PostgreSQL can name every chunk
                -- embedded under the old identity without re-reading a single vector. A collection
                -- holding two embedding configurations is silently wrong — cosine scores across
                -- them are not comparable and nothing raises.
                embedding_model_id      text NOT NULL,
                parser_version          text NOT NULL,
                chunker_version         text NOT NULL,

                -- ── time ────────────────────────────────────────────────────────────────────
                -- `created_at` IS ChunkMetadata.created_at. It is passed into the chunker rather
                -- than read from a clock there, because chunking is a pure function of its inputs
                -- and publication verifies an expected total before activating. The column default
                -- covers the rows nobody passes one for.
                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),
                -- Time-scoped policies retrieval must stop returning once they lapse. Carried down
                -- onto the chunk rather than read off the source at query time, because a Qdrant
                -- filter cannot reach up a foreign-key chain.
                effective_at            timestamptz,
                expires_at              timestamptz,

                -- ── the closed vocabularies ─────────────────────────────────────────────────
                CONSTRAINT chunks_content_type_check  CHECK (content_type IN ({$contentTypes})),
                CONSTRAINT chunks_index_status_check  CHECK (index_status IN ({$indexStatuses})),

                -- ── R4: the digest is lowercase hex ────────────────────────────────────────
                CONSTRAINT chunks_content_hash_is_hex
                    CHECK (content_hash ~ '^[0-9a-f]{64}\$'),

                -- ── the element set ────────────────────────────────────────────────────────
                -- A chunk with no elements has no provenance, so a citation cannot be built and a
                -- deletion cannot find what to remove. A NULL element makes `list<string>` a lie on
                -- the PHP side. And `document_element_id` must be IN the set: it is the PRIMARY
                -- element of the chunk, not a different one, and a row where the two disagree is a
                -- citation pointing at a paragraph the chunk does not contain.
                CONSTRAINT chunks_element_ids_well_formed CHECK (
                    cardinality(element_ids) >= 1
                    AND array_position(element_ids, NULL) IS NULL
                    AND document_element_id = ANY (element_ids)
                ),
                CONSTRAINT chunks_heading_path_well_formed CHECK (
                    array_position(heading_path, NULL) IS NULL
                    AND NOT (heading_path && ARRAY['']::text[])
                ),

                -- ── the locators ───────────────────────────────────────────────────────────
                CONSTRAINT chunks_seq_non_negative CHECK (seq >= 0),
                CONSTRAINT chunks_offsets_ordered CHECK (
                    char_start >= 0 AND char_end >= char_start
                ),
                CONSTRAINT chunks_pages_ordered CHECK (
                    page IS NULL OR (page >= 1 AND (page_end IS NULL OR page_end >= page))
                ),
                -- A page_end with no page names the end of a range with no beginning.
                CONSTRAINT chunks_page_end_needs_page
                    CHECK (page_end IS NULL OR page IS NOT NULL),
                CONSTRAINT chunks_slide_positive CHECK (slide IS NULL OR slide >= 1),
                -- ChunkMetadata.row_range is a PAIR. Either both ends are present or neither is;
                -- `num_nonnulls(...) <> 1` is that written without repeating the column names, and
                -- it is the same construction `bots_evidence_threshold_paired` uses.
                CONSTRAINT chunks_row_range_paired CHECK (
                    num_nonnulls(row_start, row_end) <> 1
                    AND (row_end IS NULL OR row_end >= row_start)
                ),
                CONSTRAINT chunks_locator_not_blank CHECK (
                    (sheet     IS NULL OR btrim(sheet)     <> '')
                    AND (table_ref IS NULL OR btrim(table_ref) <> '')
                    AND (url       IS NULL OR btrim(url)       <> '')
                    AND (anchor    IS NULL OR btrim(anchor)    <> '')
                ),

                -- ── content facts ──────────────────────────────────────────────────────────
                -- An empty chunk is a point that matches queries and answers nothing.
                CONSTRAINT chunks_text_not_blank CHECK (btrim(text) <> ''),
                CONSTRAINT chunks_token_count_positive CHECK (token_count >= 1),
                CONSTRAINT chunks_lang_not_blank CHECK (btrim(lang) <> ''),
                CONSTRAINT chunks_config_identity_present CHECK (
                    btrim(embedding_model_id) <> ''
                    AND btrim(parser_version) <> ''
                    AND btrim(chunker_version) <> ''
                ),
                CONSTRAINT chunks_window_ordered CHECK (
                    expires_at IS NULL OR effective_at IS NULL OR expires_at > effective_at
                ),
                -- A chunk does not overlap itself. The one-row cycle is the only one a CHECK can
                -- see, and it is the one a loop writes when it reached for a default.
                CONSTRAINT chunks_not_self_overlapping
                    CHECK (overlap_of IS NULL OR overlap_of <> id),

                -- ── the ownership guards, one per reference ────────────────────────────────
                -- FIVE COMPOSITE KEYS, and none of them is redundant with another. The chain
                -- version -> item -> source is already enforced upstream, so a reader may ask why
                -- `source_id` and `source_item_id` are checked here at all: because they are
                -- DENORMALIZED COPIES that go into the Qdrant payload. A copy that disagrees with
                -- the chain is a point whose payload names a source the chunk does not belong to,
                -- and the tenant filter would agree with it. These keys make the copies checkable;
                -- they do not make them chain-consistent, which is the writer's job and is why the
                -- writer is the ingestion pipeline and not an endpoint.
                CONSTRAINT chunks_source_same_org
                    FOREIGN KEY (organization_id, source_id)
                    REFERENCES knowledge_sources (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT chunks_item_same_org
                    FOREIGN KEY (organization_id, source_item_id)
                    REFERENCES source_items (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT chunks_version_same_org
                    FOREIGN KEY (organization_id, source_version_id)
                    REFERENCES source_versions (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT chunks_element_same_org
                    FOREIGN KEY (organization_id, document_element_id)
                    REFERENCES document_elements (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT chunks_parent_element_same_org
                    FOREIGN KEY (organization_id, parent_element_id)
                    REFERENCES document_elements (organization_id, id) ON DELETE RESTRICT
                -- The SELF-reference on `overlap_of` is NOT here. A composite foreign key needs a
                -- unique constraint over the referenced columns, and `chunks (organization_id, id)`
                -- does not exist until the index below is created — declaring it inside CREATE
                -- TABLE fails with 42830. It is added by ALTER TABLE immediately after.
            )
        SQL);

        // The target of this table's own `overlap_of` key. Vacuous as a uniqueness constraint
        // because `id` is already the primary key.
        $this->run('CREATE UNIQUE INDEX chunks_org_scoped_key ON chunks (organization_id, id)');

        // THE SELF-REFERENCE, AND IT IS RESTRICT LIKE EVERY OTHER KEY HERE. That is safe for the
        // purge specifically because an overlap always names a chunk of the SAME version, and
        // `relational.py` deletes a version's chunks in ONE statement: PostgreSQL resolves
        // referential integrity for rows removed by the same command together, so the
        // mutually-referencing set goes as a set. Measured on this database rather than assumed. If
        // an overlap ever legitimately crossed a version boundary this would have to become NO
        // ACTION, and the purge statement would have to move with it.
        $this->run(<<<'SQL'
            ALTER TABLE chunks
                ADD CONSTRAINT chunks_overlap_same_org
                FOREIGN KEY (organization_id, overlap_of)
                REFERENCES chunks (organization_id, id) ON DELETE RESTRICT
        SQL);

        // ── THE INDEX THE PURGE READS ─────────────────────────────────────────────────────────
        //
        // Leading pair exactly as `relational.py` binds it: `organization_id = %s AND
        // source_version_id = ANY(%s)`, and `organization_id = %s` alone for the terminal residue
        // sweep. Also the FK-child index for `chunks_version_same_org`.
        //
        // UNIQUE on (organization, version, seq) because that triple is what the point id is
        // derived from — `uuid5(POINT_NS, org:version:seq)`. Two chunks sharing it would derive the
        // SAME point id, so the second upsert would overwrite the first and one chunk would simply
        // never be retrievable, with the row still present in PostgreSQL and every count agreeing.
        // The unique index turns that into an insert failure.
        $this->run(
            'CREATE UNIQUE INDEX chunks_org_version_seq ON chunks (organization_id, source_version_id, seq)',
        );

        // THE POINT IDENTITY, GLOBALLY UNIQUE. Deletion targets stable identifiers and never a text
        // match (non-negotiable 6), so this is the column a verification reads back. Global rather
        // than per-organization because the value is a uuid5 over a string that already contains
        // the organization: two organizations colliding here would mean the derivation is broken,
        // and a per-org index would hide exactly that.
        $this->run('CREATE UNIQUE INDEX chunks_vector_point_id ON chunks (vector_point_id)');

        // FK-CHILD INDEXES FOR THE REMAINING FOUR REFERENCES. Tenant-leading, so each is also the
        // read it would otherwise need a second index for: "every chunk of this source", "every
        // chunk of this item, in version order", "which chunks cite this element".
        $this->run('CREATE INDEX chunks_org_source ON chunks (organization_id, source_id)');
        $this->run(
            'CREATE INDEX chunks_org_item ON chunks (organization_id, source_item_id, source_version_id)',
        );
        $this->run(
            'CREATE INDEX chunks_org_element ON chunks (organization_id, document_element_id)',
        );

        // The two nullable references, PARTIAL. See the docblock: a referential-integrity probe
        // binds a NOT NULL value, so a partial index is usable for it, and the majority of chunks
        // have neither a parent element nor an overlap.
        $this->run(
            'CREATE INDEX chunks_org_parent_element ON chunks (organization_id, parent_element_id) '
            .'WHERE parent_element_id IS NOT NULL',
        );
        $this->run(
            'CREATE INDEX chunks_org_overlap_of ON chunks (organization_id, overlap_of) '
            .'WHERE overlap_of IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS chunks');
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

<?php

declare(strict_types=1);

use App\Enums\DocumentElementKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE PARSER'S OUTPUT, ELEMENT BY ELEMENT — and one of the two tables of finding #79.
 *
 * ═══ FINDING #79, CLOSED HERE AND IN THE NEXT MIGRATION ═══════════════════════════════════════
 *
 * `services/ai-service/app/deletion/relational.py:204-217` holds four statements against this
 * table:
 *
 *     DELETE FROM document_elements WHERE organization_id = %s AND source_version_id = ANY(%s)
 *     SELECT COUNT(*) FROM document_elements WHERE organization_id = %s AND source_version_id = ANY(%s)
 *     DELETE FROM document_elements WHERE organization_id = %s
 *     SELECT COUNT(*) FROM document_elements WHERE organization_id = %s
 *
 * No migration in this repository created the table they address. That is ADR-033 property 3
 * violated in the open, pinned by ruling on 2026-08-12 because both repairs available then were
 * worse than the finding. This migration is the repair.
 *
 * The two column names those statements depend on are `organization_id` and `source_version_id`,
 * and BOTH are below with the shapes the statements assume. `relational.py`'s own comment says the
 * names were "stated from postgresql-patterns ... unverified against a schema"; they are verified
 * now, and the index below leads with exactly the pair the purge and the residue sweep bind.
 *
 * ═══ `organization_id` IS DENORMALIZED ONTO THIS TABLE, AND IT IS NOT REDUNDANT ═══════════════
 *
 * The FK chain `document_elements -> source_versions -> source_items -> knowledge_sources` already
 * reaches an organization, and `kb-tenancy-isolation` NN1 would be satisfied by the chain alone.
 * The column is here anyway for three reasons that are all operational rather than theoretical:
 *
 *   1. THE PURGE BINDS IT DIRECTLY. `relational.py`'s `version_scope()` RAISES on a blank
 *      organization — "a deletion statement without an organization is not scoped at all" — so
 *      every statement it issues names this column. Without it the purge would have to join three
 *      tables to delete a row, inside a worker, per version.
 *   2. THE TERMINAL ORG-RESIDUE SWEEP HAS NO VERSION IDS AT ALL. `DELETE FROM document_elements
 *      WHERE organization_id = %s` is the last act of an organization purge and cannot be expressed
 *      through the chain, because the chain's upper rows are gone by then.
 *   3. A FOREIGN KEY TO `organizations` EXISTS ON THIS TABLE AND DID NOT BEFORE. The rows the data
 *      plane writes today carry an organization id that nothing checks against the organization
 *      table — the value is whatever the job payload said. The composite key below closes that:
 *      an element's organization must agree with its VERSION's organization, so a payload that
 *      named the wrong tenant is refused by the database rather than indexed by it.
 *
 * ═══ WHAT THIS TABLE HOLDS, AND HOW "AND NOTHING ELSE" WAS READ ══════════════════════════════
 *
 * `chunker.py:308-310` is the authority: *"`document_elements` carries `organization_id`,
 * `source_version_id`, a parent, a sequence and a locator, and nothing else"*.
 *
 * THE SENTENCE IS MAKING A CLAIM ABOUT WHAT THE CHUNKER CANNOT READ OFF AN ELEMENT, and that is how
 * it is read here. Its subject is `ChunkContext`'s reason for existing: eleven of `ChunkMetadata`'s
 * thirty-two fields are properties of the SOURCE VERSION rather than of any element, so
 * `chunk_document()` cannot populate them from the elements it is given. `kind` and `text` are not
 * among those eleven and are not what the sentence is excluding — the chunker dispatches on
 * `element.kind` (headings emit nothing, tables go to `chunk_table`, code is never re-wrapped) and
 * the chunk's text is assembled FROM the elements' text. A table with neither would make the
 * chunker's own module unimplementable.
 *
 * So: identity, tenancy, parent, sequence, locator — plus `kind` and `text`, which are the element
 * itself. Nothing about chunking, nothing about embedding, no config identity, no bot assignment.
 * The reading is written out because it is a reading, and the alternative reading would produce a
 * table nothing could use.
 *
 * ═══ THE PARENT IS A SELF-REFERENCE, AND IT IS WHAT MAKES SMALL-TO-BIG POSSIBLE ══════════════
 *
 * Retrieval matches a child element and returns the enclosing section. That requires the enclosing
 * section to be nameable from the child, which is this column. It is composite-guarded like every
 * other reference here, so an element cannot be parented into another tenant's document.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kinds = $this->quotedList(DocumentElementKind::values());

        $this->run(<<<SQL
            CREATE TABLE document_elements (
                id                 char(26) COLLATE "C" PRIMARY KEY,

                -- DENORMALIZED, AND NOW ACTUALLY CONSTRAINED. See the docblock: this column has
                -- been written by the data plane with no foreign key behind it, which is what the
                -- terminal org-residue sweep exists to clean up after.
                organization_id    char(26) COLLATE "C" NOT NULL
                                   REFERENCES organizations (id) ON DELETE RESTRICT,
                source_version_id  char(26) COLLATE "C" NOT NULL,

                -- The enclosing element, or NULL at the top of the document.
                parent_element_id  char(26) COLLATE "C",

                -- DOCUMENT ORDER. Not a display nicety: chunk packing merges adjacent elements and
                -- a citation's "where in the document" is derived from it, so it must be stable
                -- across a replay of the same parse.
                seq                integer NOT NULL,

                -- Ten values, transcribed from chunker.py's ELEMENT_KINDS.
                kind               text NOT NULL,

                -- THE ELEMENT'S OWN TEXT. Plain `text`, never COLLATE "C": it is human language,
                -- it is what a small-to-big retrieval returns when it widens from a child to its
                -- parent, and it is never compared for equality.
                --
                -- IT IS UNTRUSTED DATA (non-negotiable 7). Nothing downstream may treat a byte of
                -- it as an instruction, and no constraint here can help with that — the defense is
                -- layered in the prompt assembly, and it is named here because this column is where
                -- the untrusted bytes first come to rest.
                text               text NOT NULL,

                -- ── the locator: where in the original this element was ─────────────────────
                -- All nullable, because no document format has all of them. A PDF has a page, a
                -- deck has a slide, a spreadsheet has a sheet and a region, a crawled page has a
                -- URL and an anchor. `char_start`/`char_end` are offsets into the NORMALIZED
                -- document and are the only locator every format has, which is why they are the
                -- two that are NOT NULL — they are what produces the excerpt highlight the
                -- citation contract promises, and a page number alone cannot.
                page               integer,
                slide              integer,
                sheet              text COLLATE "C",
                table_ref          text COLLATE "C",
                url                text COLLATE "C",
                anchor             text COLLATE "C",
                char_start         integer NOT NULL,
                char_end           integer NOT NULL,

                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT document_elements_kind_check CHECK (kind IN ({$kinds})),

                -- ── the locator has to be readable ──────────────────────────────────────────
                CONSTRAINT document_elements_seq_non_negative CHECK (seq >= 0),
                CONSTRAINT document_elements_offsets_ordered CHECK (
                    char_start >= 0 AND char_end >= char_start
                ),
                CONSTRAINT document_elements_page_positive
                    CHECK (page IS NULL OR page >= 1),
                CONSTRAINT document_elements_slide_positive
                    CHECK (slide IS NULL OR slide >= 1),
                CONSTRAINT document_elements_locator_not_blank CHECK (
                    (sheet     IS NULL OR btrim(sheet)     <> '')
                    AND (table_ref IS NULL OR btrim(table_ref) <> '')
                    AND (url       IS NULL OR btrim(url)       <> '')
                    AND (anchor    IS NULL OR btrim(anchor)    <> '')
                ),

                -- ── an element is not its own parent ────────────────────────────────────────
                -- A one-row cycle is the only cycle a CHECK can see, and it is also the only one a
                -- writer produces by accident: `parent_element_id = element.id` is what a loop
                -- writes when the parent stack was empty and somebody reached for a default.
                -- Deeper cycles are the writer's problem and cannot be expressed here.
                CONSTRAINT document_elements_not_self_parented
                    CHECK (parent_element_id IS NULL OR parent_element_id <> id),

                -- ── the ownership guard ────────────────────────────────────────────────────
                -- The SELF-reference is not here. It cannot be: a composite foreign key needs a
                -- unique constraint over the referenced columns, and `(organization_id, id)` does
                -- not exist until the index below is created. Declaring it inside CREATE TABLE
                -- fails with 42830 — "there is no unique constraint matching given keys for
                -- referenced table" — so it is added by ALTER TABLE after the index, and dropped
                -- with the table.
                CONSTRAINT document_elements_version_same_org
                    FOREIGN KEY (organization_id, source_version_id)
                    REFERENCES source_versions (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // The target of `chunks`' three element references AND of this table's own parent key.
        // Vacuous as a uniqueness constraint because `id` is already the primary key.
        $this->run(
            'CREATE UNIQUE INDEX document_elements_org_scoped_key '
            .'ON document_elements (organization_id, id)',
        );

        // THE SELF-REFERENCE, now that its target exists. RESTRICT like every other key in this
        // cascade: a section may not be removed while its children still name it, and the purge
        // removes a whole version's elements in one statement, which PostgreSQL resolves as a set.
        $this->run(<<<'SQL'
            ALTER TABLE document_elements
                ADD CONSTRAINT document_elements_parent_same_org
                FOREIGN KEY (organization_id, parent_element_id)
                REFERENCES document_elements (organization_id, id) ON DELETE RESTRICT
        SQL);

        // ── THE INDEX THE PURGE READS, AND DOCUMENT ORDER IN ONE OBJECT ───────────────────────
        //
        // Its leading pair is exactly what `relational.py` binds — `organization_id = %s AND
        // source_version_id = ANY(%s)` for the version purge, and `organization_id = %s` alone for
        // the terminal residue sweep, which is the leading one-column prefix. postgresql-patterns'
        // worked failure is this index being absent on the sibling table: "Deleting one source
        // takes 40 minutes and the purge job times out in Deleting forever", because PostgreSQL
        // does not index the referencing side of a foreign key.
        //
        // UNIQUE, because document order is a fact and not a preference: two elements claiming
        // sequence 12 of one version make chunk packing non-deterministic, and the symptom is an
        // evaluation run that will not reproduce.
        $this->run(
            'CREATE UNIQUE INDEX document_elements_org_version_seq '
            .'ON document_elements (organization_id, source_version_id, seq)',
        );

        // FK-CHILD INDEX FOR THE SELF-REFERENCE, and the read that walks a section's children.
        // PARTIAL, because the referential-integrity probe binds a NOT NULL value — PostgreSQL can
        // prove `parent_element_id = $2` implies `parent_element_id IS NOT NULL`, so the partial
        // index is usable for it — and because top-level elements are a large fraction of the rows
        // in a flat document. An index that does not carry them is smaller on every write.
        $this->run(
            'CREATE INDEX document_elements_org_parent '
            .'ON document_elements (organization_id, parent_element_id) '
            .'WHERE parent_element_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS document_elements');
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

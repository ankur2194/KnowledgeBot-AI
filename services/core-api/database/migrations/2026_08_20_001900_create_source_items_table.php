<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE UNIT OF INDEPENDENT VERSIONING: one page, one uploaded file (docs/11 §16.4,
 * kb-source-lifecycle "Three levels of identity").
 *
 * ═══ EVERY SOURCE HAS AT LEAST ONE ITEM, INCLUDING A SINGLE-FILE UPLOAD ═══════════════════════
 *
 * There is no special case for a one-item source and there must never be one. The pointer switch,
 * the missing-page counter and citation provenance all key off `source_item_id`, so code that reads
 * a version pointer off the SOURCE for uploads and off the ITEM for crawls diverges the first time
 * somebody adds a second file to an existing source — and it diverges silently, because both
 * branches work in isolation.
 *
 * ═══ WHY A SITEMAP PAGE IS ITS OWN ITEM ═══════════════════════════════════════════════════════
 *
 * A nightly recrawl of a four-hundred-page site typically changes three pages. If the sitemap were
 * one item, one changed page would force a new version of all four hundred — reparse, re-embed,
 * reindex, and a pointer switch that flips the entire site at once, so retrieval is either wholly
 * stale or wholly new with nothing in between. Per-page items let three versions publish while 397
 * sit untouched, give `missing_count` somewhere to live for the missing-page policy (§8.14), and
 * let a citation name a URL rather than "the sitemap".
 *
 * ═══ RULING R1: `current_version_id` IS THE ONE AND ONLY ACTIVE-VERSION POINTER ═══════════════
 *
 * It is nullable, it has no default, and it is the ONLY thing that makes a version live.
 * `knowledge_sources` carries no counterpart — see that migration's docblock for why a source-level
 * pointer is meaningless once a crawl gives one source many independently-versioned items.
 *
 * ITS FOREIGN KEY IS NOT DECLARED HERE, AND THAT IS A CIRCULARITY RATHER THAN AN OVERSIGHT.
 * `source_versions` references this table and this column references `source_versions`, so one of
 * the two constraints has to be added after both tables exist. It is added by
 * `2026_08_20_002000_create_source_versions_table.php`, in the same file as the partial unique
 * index that is the other half of atomic publication, and it is dropped there on the way down.
 * Splitting it that way keeps the whole pointer mechanism readable in one place.
 *
 * THE POINTER IS NOT A BOOLEAN AND CANNOT BECOME ONE. `kb-source-lifecycle` NN2: two concurrent
 * publishes both read "no active version" and both set their own; a boolean `is_active` has no
 * single statement that flips them together. The enforcement is
 * `source_versions_one_active_per_item`, a partial unique index, one migration down.
 *
 * ═══ RULING R3, READ LITERALLY: THERE IS NO `status` COLUMN HERE ══════════════════════════════
 *
 * docs/11 §16.4 lists "Status" among this table's columns. The Phase C1 ruling names exactly two
 * columns that carry the fifteen-value vocabulary — `knowledge_sources.status` and
 * `source_versions.status` — and this is not one of them, so it is not built.
 *
 * THE SUBSTANCE, NOT JUST THE LETTER: an item's state is a ROLLUP of its versions', and a third
 * denormalized copy is a third thing to keep in step across two runtimes on a callback path. The
 * item's real state is `current_version_id` (is anything live) plus that version's own `status`,
 * and both are one join away. If a later phase finds a query that genuinely cannot afford the join,
 * the column lands then, with that query named in its migration — which is a better trade than
 * shipping a column now whose writer nobody has decided on. Recorded as a divergence from docs/11
 * rather than resolved silently.
 *
 * ═══ RULING R4: `content_hash` IS HEX TEXT, NOT `bytea` ═══════════════════════════════════════
 *
 * `char(64) COLLATE "C"`, sixty-four lowercase hex characters, with a CHECK that says so. This
 * DELIBERATELY DEPARTS from `postgresql-patterns`, whose runnable example specifies `bytea NOT NULL
 * -- 32 raw bytes, not 64 hex chars`, and the departure is recorded in an ADR being written in
 * parallel with this migration. Four reasons, in the order they bite:
 *
 *   1. THE DATA PLANE EMITS HEX EVERYWHERE. `app/ingestion/chunking/chunker.py:963` is
 *      `sha256(...).hexdigest()`. A `bytea` column means a conversion at every write and every
 *      read, in a process that does not own the schema.
 *   2. `app/ingestion/identity.py` JOINS `content_hash` AS A STRING into the `"|"`-separated
 *      ingest key. The key is `sha256` over that joined string, so the hash's TEXT form is part of
 *      an identity that is already persisted and already deduped against. Changing the storage type
 *      does not change the key, but it does put two representations of one value in play, and the
 *      one that reaches the key is the string.
 *   3. THE QDRANT PAYLOAD DEDUPES ON THE STRING. Payload values are JSON; there is no bytes type to
 *      round-trip through.
 *   4. `docs/22` FINDING J3 RECORDED TWO MEASURED `BinaryCast` DEFECTS IN THIS REPOSITORY. The
 *      binary path is not hypothetical here — it has already been got wrong twice, on columns where
 *      the failure was visible. On a content hash the failure is invisible: a mis-round-tripped
 *      digest does not error, it simply never matches, so every ingest looks like new content and
 *      the corpus re-embeds at a provider's per-token price.
 *
 * The cost of the departure is stated rather than hidden: sixty-four characters instead of
 * thirty-two bytes, so the column is roughly twice the size and the b-tree over it — there is none
 * on this table — would be too. That is a real cost and it is smaller than any of the four above.
 *
 * ═══ THE STORAGE KEY IS TENANT-PREFIXED, AND THE DATABASE CHECKS IT ═══════════════════════════
 *
 * `kb-tenancy-isolation` requires object-storage paths to be organization-scoped, and
 * `kb-security-baseline`'s upload rules require the key to be GENERATED rather than derived from
 * the uploaded filename. The CHECK below compares the key against THIS ROW'S OWN
 * `organization_id`, which is a thing a CHECK constraint can do and a service can forget: a row
 * whose object lives under another tenant's prefix is refused by the database.
 *
 * The user's filename lives in `display_name` and is never a path — its own CHECK refuses a
 * separator, a control character, and the two directory-relative names.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE source_items (
                id                  char(26) COLLATE "C" PRIMARY KEY,
                organization_id     char(26) COLLATE "C" NOT NULL
                                    REFERENCES organizations (id) ON DELETE RESTRICT,
                source_id           char(26) COLLATE "C" NOT NULL,

                -- THE STABLE IDENTITY OF THIS ITEM WITHIN ITS SOURCE, and what makes a recrawl
                -- recognise a page it has seen before rather than creating a second row for it.
                -- For a crawl it is the normalized URL; for an upload it is the generated object
                -- key. COLLATE "C" because it is only ever compared for exact equality, and unique
                -- per (organization, source) below.
                canonical_key       text COLLATE "C" NOT NULL,

                -- The live URL, when there is one. Separate from `canonical_key` because
                -- normalization is lossy on purpose — two URLs that differ only in a tracking
                -- parameter are one page — and a citation has to link to something a human can
                -- open.
                url                 text COLLATE "C",

                -- Human-readable, plain `text` and not COLLATE "C": read and sorted by people.
                title               text,

                -- THE USER'S FILENAME, AND NEVER A PATH. See the docblock and
                -- kb-security-baseline/references/file-upload-safety.md. It is re-emitted only
                -- inside a Content-Disposition header, where it is a string and not a location.
                display_name        text,

                -- ── the stored object ────────────────────────────────────────────────────────
                -- All four are nullable together: a pasted `text` source has no object at all, and
                -- a crawled page has a content hash without one. The CHECK below refuses the
                -- half-populated combination.
                storage_key         text COLLATE "C",
                content_hash        char(64) COLLATE "C",
                mime                text COLLATE "C",
                byte_size           bigint,

                -- THE ACTIVE-VERSION POINTER. Its foreign key is added by the next migration —
                -- see the docblock (R1).
                current_version_id  char(26) COLLATE "C",

                -- ── the missing-page policy (§8.14) ──────────────────────────────────────────
                -- A page that vanishes from a sitemap is not deleted on the first miss: a
                -- transient 404 during a deploy would otherwise drop a live page out of the
                -- corpus. The counter is what makes "gone for N consecutive crawls" expressible,
                -- and `last_discovered_at` is what makes a freshness display and a stale-item
                -- sweep possible without walking every version.
                last_discovered_at  timestamptz,
                missing_count       integer NOT NULL DEFAULT 0,

                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                -- ── blank is not a value ─────────────────────────────────────────────────────
                CONSTRAINT source_items_canonical_key_shape CHECK (
                    btrim(canonical_key) <> '' AND length(canonical_key) <= 2048
                ),
                CONSTRAINT source_items_title_not_blank
                    CHECK (title IS NULL OR btrim(title) <> ''),

                -- ── the filename is not a location ───────────────────────────────────────────
                -- A separator makes it one, a control character makes it a truncation primitive
                -- (`x.pdf` followed by a NUL), and `.`/`..` are directory-relative names that some
                -- consumer downstream will resolve. None of these is the check that keeps us
                -- safe — the storage key is generated and never derived from this value, which is
                -- what makes traversal structurally impossible — but a filename that LOOKS like a
                -- path will eventually be joined to one by somebody, and the row is the cheapest
                -- place to say no.
                CONSTRAINT source_items_display_name_is_not_a_path CHECK (
                    display_name IS NULL OR (
                        btrim(display_name) <> ''
                        AND display_name !~ '[/\\]'
                        AND display_name !~ '[[:cntrl:]]'
                        AND display_name <> '.'
                        AND display_name <> '..'
                    )
                ),

                -- ── the object storage key is inside THIS ROW'S organization prefix ──────────
                -- kb-tenancy-isolation layer 5, enforced by the database against the row's own
                -- tenant column rather than by the service that wrote it. `||` concatenation
                -- against `organization_id` is what makes this a per-row check instead of a
                -- pattern that would accept any organization's prefix.
                CONSTRAINT source_items_storage_key_is_tenant_scoped CHECK (
                    storage_key IS NULL
                    OR storage_key LIKE 'org/' || organization_id || '/%'
                ),

                -- ── a stored object has a hash, a type and a size ───────────────────────────
                -- The reverse is not required: a crawled page has a content hash and no object.
                -- What is refused is an object we cannot verify, cannot label and cannot bill.
                CONSTRAINT source_items_stored_object_is_complete CHECK (
                    storage_key IS NULL
                    OR (content_hash IS NOT NULL AND mime IS NOT NULL AND byte_size IS NOT NULL)
                ),

                -- ── R4: the content hash is lowercase hex, and the column says so ───────────
                -- `char(64)` alone would accept sixty-four spaces. The CHECK is what makes the
                -- type a statement about the VALUE rather than about its width, and it is what
                -- catches an uppercase digest — which compares unequal under COLLATE "C" and would
                -- therefore re-version a corpus that had not changed.
                CONSTRAINT source_items_content_hash_is_hex CHECK (
                    content_hash IS NULL OR content_hash ~ '^[0-9a-f]{64}$'
                ),

                CONSTRAINT source_items_url_scheme CHECK (
                    url IS NULL OR url ~ '^https{0,1}://[^@[:space:]]+$'
                ),
                CONSTRAINT source_items_mime_shape CHECK (
                    mime IS NULL OR mime ~ '^[[:alnum:]!#$&^_.+-]+/[[:alnum:]!#$&^_.+-]+$'
                ),
                CONSTRAINT source_items_byte_size_non_negative
                    CHECK (byte_size IS NULL OR byte_size >= 0),
                CONSTRAINT source_items_missing_count_non_negative
                    CHECK (missing_count >= 0),

                -- ── the ownership guard ─────────────────────────────────────────────────────
                -- COMPOSITE, like every cross-entity reference in this schema: without it an item
                -- could name another tenant's source, and every downstream filter would AGREE,
                -- because you would have taught it that Org B's source owns Org A's item.
                CONSTRAINT source_items_source_same_org
                    FOREIGN KEY (organization_id, source_id)
                    REFERENCES knowledge_sources (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // The target of `source_versions`' composite foreign key, and of `chunks`'. Vacuous as a
        // uniqueness constraint because `id` is already the primary key.
        $this->run(
            'CREATE UNIQUE INDEX source_items_org_scoped_key ON source_items (organization_id, id)',
        );

        // IDENTITY WITHIN THE SOURCE, AND THE FK-CHILD INDEX FOR `source_id` IN ONE OBJECT. A
        // recrawl looks an item up by (organization, source, canonical key); the referential
        // integrity check PostgreSQL runs when a `knowledge_sources` row is deleted names
        // (organization_id, source_id), which is this index's leading pair. PostgreSQL creates
        // neither for you, and without the second one every source delete sequentially scans this
        // table.
        $this->run(
            'CREATE UNIQUE INDEX source_items_org_source_canonical '
            .'ON source_items (organization_id, source_id, canonical_key)',
        );

        // THE FK-CHILD INDEX FOR THE POINTER, created here even though the constraint it serves is
        // added by the next migration. Without it every `DELETE FROM source_versions` — which is
        // what a purge does, in bulk — sequentially scans this table to check that no item still
        // points at the row being removed.
        $this->run(
            'CREATE INDEX source_items_org_current_version '
            .'ON source_items (organization_id, current_version_id)',
        );

        // THE MISSING-PAGE SWEEP (§8.14), partial so it indexes the small minority of rows the
        // sweeper cares about rather than every page of every site. Tenant-leading, because the
        // sweep runs per organization.
        $this->run(
            'CREATE INDEX source_items_org_missing '
            .'ON source_items (organization_id, last_discovered_at) WHERE missing_count > 0',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS source_items');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

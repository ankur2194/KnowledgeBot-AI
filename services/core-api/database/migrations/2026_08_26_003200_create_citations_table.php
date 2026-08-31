<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One footnote on one answer (docs/11 §16.6, docs/07 §12.15).
 *
 * ═══ EVERYTHING THE UI RENDERS IS ON THIS ROW, AND NOTHING IT RENDERS IS FETCHED THROUGH
 *     `chunk_id` ═══════════════════════════════════════════════════════════════════════════
 *
 * postgresql-patterns names this table twice, and both times as the worked example of a cascade
 * that destroys history: *"The customer deletes a source and their whole chat history goes blank.
 * `citations.chunk_id REFERENCES chunks ON DELETE CASCADE`. The cascade is invisible in the code
 * that issues the delete; nobody drew the graph."* The rule it states is the one implemented here:
 * `chunk_id` is `ON DELETE SET NULL`, and `label`, `display_title`, `location_metadata` and
 * `excerpt` are DENORMALIZED ONTO THIS ROW so the transcript stays readable after the source is
 * purged.
 *
 * THE DENORMALIZATION IS THE FEATURE, NOT A CACHE. It must never be "refreshed" from `chunks`: a
 * citation is what the answer said at the time it was given, and re-reading the chunk would silently
 * update a past answer's footnote to match a document that has since been re-crawled. That is the
 * same argument `conversations.consent_text_snapshot` makes one table up, and the same one
 * `retrieval_traces.retrieval_configuration_version` makes beside it.
 *
 * `chunk_id` survives as a POINTER FOR THE LIVE CASE ONLY — "open this citation in the source
 * viewer" works while the chunk exists and degrades to a read-only footnote when it does not. It is
 * NOT the four-term-filtered retrieval path and must never be used as one: resolving a chunk by id
 * is an existence oracle (kb-tenancy-isolation names `retrieve` by point id as exactly that), so any
 * surface that follows this pointer authorizes against `conversations.organization_id` first.
 *
 * `citations_chunk_referential` is not optional. Without an index on the referencing side,
 * `SET NULL` makes every `DELETE FROM chunks` sequentially scan this table — and a source deletion
 * removes chunks by the hundred thousand.
 *
 * ═══ NO `organization_id`; THE CHAIN IS `messages -> conversations` ═════════════════════════
 *
 * kb-tenancy-isolation NN1 names `citations/feedback -> messages -> conversations` as its example of
 * the NOT NULL FK chain. `App\Models\Citation` therefore carries no `#[ScopedBy]` — see the model.
 *
 * NOTE THE ASYMMETRY WITH `chunk_id`: `message_id` is NOT NULL and reaches an organization, while
 * `chunk_id` is nullable and reaches a DIFFERENT organization's table through a column with no
 * composite guard. That is not a hole. The chunk is not what makes this row tenant-owned — the
 * message is — and a citation naming a foreign chunk id would render nothing (the surface
 * authorizes through the conversation) and cite nothing (the text is on this row). A composite
 * guard is unavailable anyway: this table has no `organization_id` to build one from, and adding
 * one would create the denormalized copy that can disagree with its parent, which the messages
 * migration explains at length.
 *
 * ═══ THE LABEL IS UNIQUE WITHIN ITS MESSAGE ════════════════════════════════════════════════
 *
 * §12.15 assigns citation labels BEFORE generation, from retrieved evidence, and non-negotiable 8
 * is that they never come from free-form model output. Two rows carrying `[1]` on one answer is
 * either a double-write or a label that came from the model after all; both render as a footnote
 * list with two number ones in it, and neither raises without this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE citations (
                id                char(26) COLLATE "C" PRIMARY KEY,

                -- NOT NULL and CASCADE: a footnote with no answer is not a shorter record, it is a
                -- dangling one. "Deleting a message takes its citations."
                message_id        char(26) COLLATE "C" NOT NULL
                                  REFERENCES messages (id) ON DELETE CASCADE,

                -- NULLABLE and SET NULL. `chunks.id` is a plain `char(26) COLLATE "C"` primary key
                -- (2026_08_20_002200), so this is a simple key. THE ACTION IS THE WHOLE POINT OF
                -- THIS TABLE'S DESIGN — see the docblock.
                chunk_id          char(26) COLLATE "C"
                                  REFERENCES chunks (id) ON DELETE SET NULL,

                -- ── everything the UI renders, denormalized on purpose ──────────────────────
                -- The marker in the answer text: `1`, `2`. Unique within the message, below.
                label             text COLLATE "C" NOT NULL,
                -- The document's name as it was when the answer was given.
                display_title     text NOT NULL,
                -- Page, slide, sheet, row range, URL, anchor — whichever locators the chunk had.
                -- jsonb because the key set differs per source type and nothing queries it by
                -- predicate: it is fetched whole with the row and handed to a renderer. That is one
                -- of the three shapes postgresql-patterns admits jsonb for.
                location_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                -- The quoted passage. NOT NULL: a citation with no excerpt is a claim with no
                -- evidence, and the whole contract of §12.15 is that the evidence is shown.
                excerpt           text NOT NULL,

                created_at        timestamptz NOT NULL DEFAULT now(),

                -- ── shape ───────────────────────────────────────────────────────────────────
                -- NULL means "not set" everywhere in this schema, and none of these three columns
                -- is nullable, so blank is the only wrong spelling left and it is refused.
                CONSTRAINT citations_label_not_blank         CHECK (btrim(label) <> ''),
                CONSTRAINT citations_display_title_not_blank CHECK (btrim(display_title) <> ''),
                CONSTRAINT citations_excerpt_not_blank       CHECK (btrim(excerpt) <> ''),

                -- `json_encode([])` is `[]`, a JSON ARRAY, and one array-shaped row makes every
                -- `location_metadata->>'page'` read silently return nothing for it.
                -- App\Support\Casts\JsonObjectCast is the writer-side half.
                CONSTRAINT citations_location_metadata_is_object
                    CHECK (jsonb_typeof(location_metadata) = 'object')
            )
        SQL);

        // ONE LABEL PER ANSWER, and the index the transcript renderer reads the footnote list from.
        // It leads with `message_id` because this table has no `organization_id` and the parent
        // whose organization is fixed is the narrowest leading term available; it doubles as the
        // FK-child index for the cascade from `messages`.
        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX citations_message_label_unique
                ON citations (message_id, label)
        SQL);

        // FK-CHILD INDEX FOR `chunk_id`, AND IT IS NOT OPTIONAL. postgresql-patterns puts it in the
        // same code block as the table: "or SET NULL seq-scans citations". A source deletion removes
        // chunks by the hundred thousand and each one would otherwise scan this table.
        //
        // NOT led by `message_id`: a referential check asks "does any row anywhere reference this
        // chunk", with no message in hand, so a message-leading index cannot serve it.
        $this->run(<<<'SQL'
            CREATE INDEX citations_chunk_referential
                ON citations (chunk_id) WHERE chunk_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS citations');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

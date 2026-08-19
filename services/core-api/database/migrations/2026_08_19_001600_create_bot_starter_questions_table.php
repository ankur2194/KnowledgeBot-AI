<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The suggested starter questions a bot shows before its first turn (docs/02 §8.3, docs/11 §16.3).
 *
 * ═══ WHY THIS IS A TABLE AND NOT A jsonb ARRAY ON `bots` ══════════════════════════════════════
 *
 * postgresql-patterns admits jsonb for three shapes — a configuration snapshot read whole, a
 * capability-flag set whose keys the provider owns, and a warning summary. An ORDERED, EDITABLE,
 * INDIVIDUALLY-ADDRESSABLE list of tenant-authored strings is none of them. docs/11 §16.3 lists it
 * as its own table for the same reason, and the practical half is that a starter question is a row
 * an admin reorders, edits and deletes one at a time; expressing that against a jsonb array means
 * read-modify-write of the whole array, which is a lost update the moment two tabs are open.
 *
 * ═══ THE ORDER IS EXPLICIT, AND IT IS UNIQUE ══════════════════════════════════════════════════
 *
 * `sort_order` is a real column and not an implicit `created_at` ordering, because the order is a
 * product decision the operator makes and re-makes. It is UNIQUE per bot, so "which question is
 * second" always has exactly one answer — two rows sharing a position would make the rendered list
 * depend on whatever the planner felt like, which is a rendering that differs between two reads of
 * an unchanged set and is untestable by construction.
 *
 * THE UNIQUENESS IS *NOT* DEFERRABLE, AND THAT IS A DECISION WITH A COST. Reordering an existing
 * list by swapping two positions therefore needs a strategy — write the new order through a
 * temporary offset, or delete-and-reinsert the whole list inside one transaction — rather than two
 * naive UPDATEs, the first of which would collide. A `DEFERRABLE INITIALLY DEFERRED` constraint
 * would make the naive form work, and it is not used here because a deferred unique constraint
 * cannot use the index for lookups the same way and, more importantly, moves the failure from the
 * statement that caused it to the COMMIT, where the error message names neither. The service layer
 * owning reordering explicitly is the cheaper half of that trade; Phase B's write endpoints are
 * where it lands.
 *
 * ═══ THE COMPOSITE FOREIGN KEY ════════════════════════════════════════════════════════════════
 *
 * `(organization_id, bot_id) -> bots (organization_id, id)`, with `organization_id` denormalized so
 * the key can exist. Identical construction and identical reasoning to `bot_domains`, and it
 * matters less here than it does there — a starter question is not a security control — but it is
 * applied uniformly on purpose: a schema where the guard is present on the rows somebody thought
 * were sensitive is a schema where the next table's author has to make that judgement too.
 *
 * ON DELETE RESTRICT for the reason `bot_domains` records: the transitive set is empty, so CASCADE
 * would be safe, and bot deletion is an orchestrated flow that does not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE bot_starter_questions (
                id              char(26) COLLATE "C" PRIMARY KEY,
                organization_id char(26) COLLATE "C" NOT NULL
                                REFERENCES organizations (id) ON DELETE RESTRICT,
                bot_id          char(26) COLLATE "C" NOT NULL,

                -- Plain `text`, not COLLATE "C": this is prose a human reads, so the database's
                -- collation is the right comparison for it. The opposite call from `origin` and
                -- `public_bot_id`, and the difference is whether the string is ever compared for
                -- anything but exact equality.
                question        text NOT NULL,

                -- Zero-based, so "first" is 0 in the database and in the response body and nobody
                -- has to remember which. TENANT-AUTHORED CONTENT IS ESCAPED ON RENDER, everywhere:
                -- `question` reaches the chat surface as a suggestion chip and reaches the admin
                -- console as an editable field, and neither may interpolate it into markup.
                sort_order      integer NOT NULL,

                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT bot_starter_questions_sort_order_nonnegative
                    CHECK (sort_order >= 0),

                -- Blank is not a value. NULL is impossible here (NOT NULL), so a whitespace-only
                -- string would be the ONLY way to store "a chip with no label" — a control the user
                -- can see, can click, and cannot read.
                CONSTRAINT bot_starter_questions_not_blank
                    CHECK (btrim(question) <> ''),

                CONSTRAINT bot_starter_questions_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // ONE INDEX DOING THREE JOBS again: the uniqueness constraint on the position, the FK-child
        // index for the composite key, and the read path — the chat surface asks for one bot's
        // questions IN ORDER, which this index yields directly with no sort node.
        //
        // Tenant-leading costs nothing in constraint strength: `bot_id` functionally determines
        // `organization_id` through the composite foreign key above, so this admits exactly the
        // same set of tables as `UNIQUE (bot_id, sort_order)` would.
        $this->run(
            'CREATE UNIQUE INDEX bot_starter_questions_org_bot_position '
            .'ON bot_starter_questions (organization_id, bot_id, sort_order)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS bot_starter_questions');
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

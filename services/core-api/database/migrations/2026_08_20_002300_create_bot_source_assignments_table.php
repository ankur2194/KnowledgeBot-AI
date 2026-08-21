<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH BOT MAY ANSWER FROM WHICH SOURCE (docs/11 §16.3, docs/02 §8.3).
 *
 * ═══ THIS IS THE ONE ROW IN THE SCHEMA THAT CAN SPAN TWO ORGANIZATIONS ════════════════════════
 *
 * `kb-tenancy-isolation` non-negotiable 2, verbatim: *"`bot_id` and `source_id` each inherit their
 * own org and nothing in the FK graph forces them to agree ... Enforce it in the service AND in the
 * schema — denormalize `organization_id` onto the row and make `(organization_id, bot_id)` and
 * `(organization_id, source_id)` composite foreign keys. One mis-scoped insert here is a permanent
 * leak that every downstream filter agrees with, because you taught it that Org B's source belongs
 * to Org A's bot."*
 *
 * READ THAT LAST CLAUSE AGAIN, BECAUSE IT IS WHAT MAKES THIS TABLE DIFFERENT FROM EVERY OTHER ONE
 * HERE. A mis-scoped row is not a bug the tenant filter catches — it is a bug the tenant filter
 * ENFORCES. `bot_ids` is one of the four mandatory Qdrant filter terms, resolved from this table, so
 * a single wrong row makes a correctly-filtered query return another organization's documents at
 * normal latency with a well-formed citation and an HTTP 200. Nothing logs, nothing alerts, and
 * `retrieval_traces.filters` records a filter that was, by its own lights, correct.
 *
 * THE TWO COMPOSITE KEYS ARE THE ENFORCEMENT. Both reference `(organization_id, id)` of their
 * parent, so this row's own `organization_id` has to agree with the bot's AND with the source's —
 * which is only possible when the bot and the source agree with each other. The denormalized column
 * is not a convenience: it is the shared term that makes the agreement expressible in the schema at
 * all. Without it there are two independent single-column keys and no constraint relating them.
 *
 * `database/factories/KnowledgeSourceFactory.php::crossOrg()` exists ONLY to attempt this row and
 * assert that it is refused, and `tests/Security/KnowledgeSourceTenancyTest.php` names the
 * constraint below in its assertion. That is deliberate: a test that asserted merely "an exception
 * was raised" would pass if the write failed for an unrelated reason, such as a not-null violation
 * on a column the fixture forgot.
 *
 * ═══ WHY `assignedTo()` MUST NEVER INFER THE ORGANIZATION ═════════════════════════════════════
 *
 * If the fixture derived `organization_id` from the bot, or from the source, then `crossOrg()` would
 * be INEXPRESSIBLE — the derived value would always agree with one side by construction, one of the
 * two keys would always pass, and the other would be the only thing under test. Worse, a service
 * that derived it the same way would produce rows that satisfy both keys while still being wrong in
 * the case the keys exist for. The organization is stated by the caller and checked against both
 * parents.
 *
 * ═══ WHAT IS ON THIS ROW BESIDES THE TWO IDS ══════════════════════════════════════════════════
 *
 * `priority` and `enabled` are docs/11 §16.3's. Priority is an operator's preference between
 * sources for one bot — a tie-break the retrieval stage may use, never a filter — and `enabled` is
 * the per-assignment off switch, which is a different fact from disabling the SOURCE: turning a
 * source off removes it from every bot, turning an assignment off removes it from one.
 *
 * "OPTIONAL ACCESS METADATA" IS NOT BUILT, and that is a divergence from docs/11 §16.3 recorded
 * rather than resolved silently. `postgresql-patterns` admits jsonb for exactly three shapes — a
 * configuration snapshot written once and read whole, capability flags whose key set a vendor owns,
 * and a warning summary — and an undefined bag called "access metadata" is none of them. A jsonb
 * column with no known keys on the one row that decides cross-tenant reachability is where somebody
 * eventually stores a filter. It lands when its keys are known, with a CHECK closing the key set the
 * way `bots.theme` does.
 *
 * ═══ THERE IS NO `bot_source_assignments_org_scoped_key` ══════════════════════════════════════
 *
 * Every table in this cascade that is a PARENT carries a vacuous `(organization_id, id)` unique
 * index as the target of its children's composite keys. This table has no children, so the index
 * would be a b-tree maintained on every write for no constraint at all. Stated rather than left
 * looking like an omission: add it in the same change as the first child, never before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE bot_source_assignments (
                id               char(26) COLLATE "C" PRIMARY KEY,

                -- DENORMALIZED, AND IT IS THE WHOLE MECHANISM. See the docblock: this is the shared
                -- term that makes "the bot and the source belong to the same organization" a thing
                -- the schema can state.
                organization_id  char(26) COLLATE "C" NOT NULL
                                 REFERENCES organizations (id) ON DELETE RESTRICT,

                bot_id           char(26) COLLATE "C" NOT NULL,
                source_id        char(26) COLLATE "C" NOT NULL,

                -- An operator's preference between this bot's sources. A TIE-BREAK the retrieval
                -- stage may consult, never a filter — a lower priority must never make a source
                -- unretrievable, because "this source is less important" and "this source is not
                -- visible" are different statements and only the second one is `enabled`.
                priority         integer NOT NULL DEFAULT 0,

                -- The per-assignment off switch. Distinct from disabling the SOURCE, which removes
                -- it from every bot at once.
                enabled          boolean NOT NULL DEFAULT true,

                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),

                -- A negative priority is not a lower preference, it is a typo for a value somebody
                -- meant to be first. Zero is the default and the floor.
                CONSTRAINT bot_source_assignments_priority_non_negative CHECK (priority >= 0),

                -- ── THE TWO GUARDS THIS TABLE EXISTS FOR ───────────────────────────────────
                CONSTRAINT bot_source_assignments_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT bot_source_assignments_source_same_org
                    FOREIGN KEY (organization_id, source_id)
                    REFERENCES knowledge_sources (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // ONE ASSIGNMENT PER (BOT, SOURCE), AND THE FK-CHILD INDEX FOR `bot_id` IN ONE OBJECT.
        //
        // The uniqueness is not cosmetic: two rows for one pair would make "is this source assigned"
        // answerable two ways, and disabling one of them would leave the other granting access. The
        // retrieval-scope resolution reads `WHERE organization_id = ... AND bot_id = ...`, which is
        // this index's leading pair, and so does the referential-integrity probe PostgreSQL runs
        // when a `bots` row is deleted.
        $this->run(
            'CREATE UNIQUE INDEX bot_source_assignments_org_bot_source '
            .'ON bot_source_assignments (organization_id, bot_id, source_id)',
        );

        // THE OTHER DIRECTION, AND THE FK-CHILD INDEX FOR `source_id`. "Which bots can answer from
        // this source" is the question a source-delete guard asks and the question an operator asks
        // before disabling one; without this index the delete-time integrity check sequentially
        // scans the table.
        $this->run(
            'CREATE INDEX bot_source_assignments_org_source '
            .'ON bot_source_assignments (organization_id, source_id)',
        );

        // THE RESOLUTION ORDER, PARTIAL ON THE ONLY ROWS THAT GRANT ANYTHING. The retrieval scope
        // is built from enabled assignments in priority order, and a disabled row contributes
        // nothing to it. Partial, so the index carries only the rows that can appear in a scope —
        // and expressed POSITIVELY (`WHERE enabled`) rather than as `NOT disabled`, which is the
        // same direction kb-tenancy-isolation NN5 requires of the filter itself.
        $this->run(
            'CREATE INDEX bot_source_assignments_org_bot_priority '
            .'ON bot_source_assignments (organization_id, bot_id, priority) WHERE enabled',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS bot_source_assignments');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

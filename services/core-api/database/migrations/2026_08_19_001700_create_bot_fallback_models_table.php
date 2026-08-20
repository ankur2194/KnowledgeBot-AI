<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The optional fallback model chain (docs/02 §8.3, "Optional fallback model chain"; docs/11 §16.3
 * lists it on `bots` as "Fallback settings").
 *
 * ═══ THE DECISION THIS FILE EXISTS TO RECORD: WHY THIS IS NOT jsonb ON `bots` ═════════════════
 *
 * postgresql-patterns admits jsonb for exactly three shapes: a provider/bot CONFIGURATION SNAPSHOT
 * written once and read whole, CAPABILITY FLAGS whose key set the provider owns and we do not, and
 * WARNING SUMMARIES. An ordered list of `provider_models` ids is none of the three, and the reasons
 * are ranked below because the first one alone settles it.
 *
 *   1. jsonb CANNOT CARRY A FOREIGN KEY. This is the whole argument. Every other model reference in
 *      this schema is guarded by a COMPOSITE key against `(organization_id, id)` of its parent —
 *      `bots_model_same_org` for the primary model, `provider_models_connection_same_org` one level
 *      down — precisely so a row cannot name another tenant's. A jsonb array of ids has no such
 *      guard and cannot be given one: `["01J...", "01J..."]` naming a model row in another
 *      organization is a perfectly valid jsonb value, the database has no opinion about it, and the
 *      failure that follows is this tenant's conversations answered on THAT tenant's credential,
 *      billed to them and visible in their provider dashboard, with every downstream layer
 *      agreeing. Storing the fallback chain as jsonb would mean the PRIMARY model is guarded by the
 *      database and its REPLACEMENTS are guarded by whichever service last wrote them.
 *
 *   2. IT IS JOINED ON AND QUERIED BY PREDICATE. postgresql-patterns: "Anything queried by a
 *      predicate, aggregated, or joined on becomes a real column." The catalogue delete path has to
 *      answer "is any bot still using this model" before removing a `provider_models` row; against
 *      a real table that question is `ON DELETE RESTRICT` and costs nothing, and against jsonb it
 *      is a containment scan over every bot in the organization that has to be remembered by
 *      whoever writes the delete.
 *
 *   3. IT IS EDITED ONE ELEMENT AT A TIME. A snapshot is written whole; a chain is reordered,
 *      appended to and pruned by an operator in a form. Read-modify-write of a jsonb array is a
 *      lost update the moment two tabs are open — the same argument `bot_starter_questions` makes.
 *
 * The counter-argument, stated so it is on the record rather than dismissed: the chain IS part of
 * the configuration snapshot that crosses the internal seam, and snapshots are jsonb elsewhere. But
 * that snapshot is ASSEMBLED at request time from the source of truth; it is not the source of
 * truth. `retrieval_traces.filters` is jsonb for the same reason and nobody would propose storing
 * `bots.provider_model_id` there.
 *
 * ═══ WHY THE ROW CARRIES `organization_id` AND ITS OWN ULID ═══════════════════════════════════
 *
 * `organization_id` is denormalized so that BOTH composite foreign keys can exist — one against the
 * bot, one against the model — which is what forces the bot and the model onto the same tenant. The
 * row is therefore the second place in this schema (after Phase C's `bot_source_assignments`) where
 * two independently-owned entities meet, and it gets the same treatment
 * kb-tenancy-isolation NN2 mandates there.
 *
 * A ULID PRIMARY KEY RATHER THAN A COMPOSITE `(bot_id, provider_model_id)`. It costs one column and
 * buys a row that can be addressed by an API and by an audit entry, and it keeps the model a
 * first-class Eloquent record instead of a `belongsToMany` pivot — which matters, because
 * `attach()` writes neither a ULID nor a denormalized `organization_id`, so the pivot spelling
 * would produce rows this schema refuses and would look correct in the call site.
 *
 * ═══ THE TWO UNIQUE CONSTRAINTS ═══════════════════════════════════════════════════════════════
 *
 * One position per bot, and one appearance per model per bot. The second is the interesting one: a
 * chain listing the same model twice is a retry against the model that just failed, dressed as a
 * fallback. It is also the shape a careless "add to chain" button produces on a double click.
 *
 * The PRIMARY model is deliberately NOT excluded by a constraint. Doing so would require the CHECK
 * to read `bots.provider_model_id` from another table, which a CHECK may not do; expressing it as a
 * trigger would put a piece of validation logic somewhere nobody looks for it. It belongs in the
 * service, and the service is Phase B's write endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE bot_fallback_models (
                id                char(26) COLLATE "C" PRIMARY KEY,
                organization_id   char(26) COLLATE "C" NOT NULL
                                  REFERENCES organizations (id) ON DELETE RESTRICT,
                bot_id            char(26) COLLATE "C" NOT NULL,
                provider_model_id char(26) COLLATE "C" NOT NULL,

                -- Zero-based, matching bot_starter_questions.sort_order, so the two orderable
                -- child tables of `bots` do not disagree about what "first" is.
                position          integer NOT NULL,

                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT bot_fallback_models_position_nonnegative CHECK (position >= 0),

                -- THE TWO GUARDS THAT MAKE THE CHAIN SAFE, and the reason this is a table.
                CONSTRAINT bot_fallback_models_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT bot_fallback_models_model_same_org
                    FOREIGN KEY (organization_id, provider_model_id)
                    REFERENCES provider_models (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // The order, and the FK-child index for the bot key. Tenant-leading costs nothing in
        // constraint strength — `bot_id` functionally determines `organization_id` through the
        // composite foreign key above — and it yields one bot's chain already in order, which is
        // exactly how the configuration snapshot reads it.
        $this->run(
            'CREATE UNIQUE INDEX bot_fallback_models_org_bot_position '
            .'ON bot_fallback_models (organization_id, bot_id, position)',
        );

        // No model twice in one chain.
        $this->run(
            'CREATE UNIQUE INDEX bot_fallback_models_org_bot_model '
            .'ON bot_fallback_models (organization_id, bot_id, provider_model_id)',
        );

        // THE FK-CHILD INDEX FOR THE *MODEL* KEY, which neither index above provides: both lead
        // with `bot_id` after the organization, so neither can serve a probe on
        // `provider_model_id`. Without this, deleting a `provider_models` row sequentially scans
        // this table to check the constraint — the failure postgresql-patterns records for
        // `chunks.source_version_id`. It is also the index the catalogue's own delete path reads
        // to explain WHICH bots are still using a model an operator is trying to remove.
        $this->run(
            'CREATE INDEX bot_fallback_models_org_model '
            .'ON bot_fallback_models (organization_id, provider_model_id)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS bot_fallback_models');
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The mirrored `provider_models` row (docs/11 §16.2) — and, for finding C1, the row that decides
 * whether a connection can embed at all.
 *
 * `capability_flags` is jsonb because the key set is the PROVIDER's and not ours; that is one of
 * exactly three shapes postgresql-patterns permits jsonb for. `context_window` and
 * `max_output_tokens` are real integer columns rather than jsonb members because they are
 * compared, not read whole.
 *
 * CAPABILITY IS READ FROM THIS ROW AND NEVER PARSED FROM `model`. `Capability` in
 * services/ai-service/app/providers/contract.py records the reason at length: vendors have already
 * retired ids that code was parsing, and "text-embedding" in a name is not a contract. The
 * matching rule on the data-plane side (`capabilities.can_embed`) is the AND of this row's flag
 * and a sourced vendor fact; setting the flag alone cannot make a vendor grow an endpoint.
 *
 * `organization_id` is DENORMALIZED onto this row on purpose, and the composite foreign key
 * `(organization_id, provider_connection_id)` is why. docs/11 §16.2 hangs provider_models off the
 * connection alone, which reaches an organization through a NOT NULL chain and satisfies
 * kb-tenancy-isolation NN1 — but only the composite form makes it IMPOSSIBLE for a model row to
 * name a connection in another organization, and this table is read by the embedding-candidate
 * query whose entire job is to decide which of an organization's credentials embeds its corpus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE provider_models (
                id                      char(26) COLLATE "C" PRIMARY KEY,
                organization_id         char(26) COLLATE "C" NOT NULL
                                        REFERENCES organizations (id) ON DELETE RESTRICT,
                provider_connection_id  char(26) COLLATE "C" NOT NULL,
                model                   text NOT NULL,
                display_name            text NOT NULL,
                capability_flags        jsonb NOT NULL DEFAULT '{}'::jsonb,
                context_window          integer NOT NULL DEFAULT 0,
                max_output_tokens       integer NOT NULL DEFAULT 0,
                enabled                 boolean NOT NULL DEFAULT true,
                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT provider_models_connection_same_org
                    FOREIGN KEY (organization_id, provider_connection_id)
                    REFERENCES provider_connections (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // ONE INDEX, TENANT-LEADING, DOING THREE JOBS. It is the uniqueness constraint, the
        // FK-child index for the composite key above, and the read path's index.
        //
        // One row per (connection, model id). A connection legitimately carries several rows —
        // text-embedding-3-large and text-embedding-3-small on one credential are two vector
        // spaces — which is exactly why an embedding designation is a (connection, model) PAIR and
        // not a bare connection id.
        //
        // LEADING WITH `organization_id` COSTS NOTHING IN CONSTRAINT STRENGTH, and that is a
        // property of THIS schema rather than a general truth about unique indexes — normally
        // prepending a column weakens a uniqueness constraint. Here it cannot: two rows sharing a
        // `provider_connection_id` cannot differ in `organization_id`, because the composite
        // foreign key above requires `(organization_id, provider_connection_id)` to exist in
        // `provider_connections (organization_id, id)`, and `id` is that table's primary key — so
        // a connection id resolves to exactly one organization. `provider_connection_id`
        // functionally determines `organization_id`, enforced by the database at every statement
        // boundary, and `UNIQUE (organization_id, provider_connection_id, model)` therefore admits
        // exactly the same set of tables as `UNIQUE (provider_connection_id, model)` would. The
        // earlier connection-leading form was the only index in these six migrations whose leading
        // column was not the tenant, and it bought nothing for the difference.
        //
        // What the order buys, in exchange for nothing:
        //
        //   1. The tenant predicate leads, so `organization_id = ?` is a RANGE on the index rather
        //      than a filter applied after it. postgresql-patterns states the rule and the failure
        //      it prevents ("never (status, organization_id)"); the planner's objection to a
        //      low-cardinality leading column is the same objection here, one column over. PG 18's
        //      b-tree skip scan does not rescue the other direction at organization scale.
        //   2. Its two-column prefix is EXACTLY the referencing column list of the composite
        //      foreign key, so it is also the FK-child index PostgreSQL does not create for you —
        //      the same doubling-up `source_versions_item_ingest_key` relies on in
        //      postgresql-patterns. That is why the separate
        //      `(organization_id, provider_connection_id, enabled)` index is gone: this index
        //      subsumes its stated purpose, and no query in the tree asks for the `enabled` half.
        //   3. For one organization it yields rows already ordered by
        //      `(provider_connection_id, model)`, which is exactly the ORDER BY
        //      `EloquentEmbeddingCandidateRepository::forOrg` issues to make a readiness request
        //      body diffable between two runs. The index it replaces could not supply that sort.
        //
        // `enabled` is deliberately in no index. `forOrg` filters it, but only within one
        // organization's rows — a handful of models per connection — so it is a heap filter over a
        // range the index has already narrowed, not a scan. An `(organization_id, enabled)` index
        // would be speculative: written for a row count this table does not have.
        $this->run(
            'CREATE UNIQUE INDEX provider_models_org_connection_model '
            .'ON provider_models (organization_id, provider_connection_id, model)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS provider_models');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

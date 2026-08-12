<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * FINDING C1, CONTROL-PLANE HALF: where the embedding designation lives.
 *
 * ADR-030 moved embedding onto the provider adapter layer and left "which of an organization's
 * connections embeds" decided nowhere. services/ai-service/app/providers/embedding_selection.py
 * now owns the resolution RULE and takes an optional `EmbeddingDesignation(connection_id, model)`.
 * This migration is where that designation is stored.
 *
 * ── WHY THE ORGANIZATION AND NOT THE BOT ────────────────────────────────────────────────────────
 *
 * provider-adapter-engineer recommended org-level and ruled out per-bot on the contract rather
 * than on taste. THAT REASONING HOLDS, and the check is one line of the data plane: `EmbeddingRequest`
 * carries no `bot_id`. It cannot, because a source belongs to the organization and has not been
 * assigned to any bot at the moment its chunks are embedded — `bot_source_assignments` is written
 * later and is many-to-many. A per-bot embedding model would therefore have to be resolved from a
 * bot that does not exist yet at embed time, and when a source is later assigned to two bots with
 * different designations, its ALREADY-WRITTEN chunks sit in one vector space while one of the two
 * bots queries another. Cosine distance is defined between any two vectors of equal width, so that
 * bot would get plausible neighbours, no error, no metric movement, and worse answers.
 *
 * The one thing per-bot would have bought — two bots in one organization on different embedding
 * models — is not a feature, it is two corpora. If it is ever wanted it is a second EmbeddingSpace
 * and a second index of the same sources, which is a reindex, not a column.
 *
 * ── WHY TWO COLUMNS AND NOT ONE ─────────────────────────────────────────────────────────────────
 *
 * `text-embedding-3-large` and `text-embedding-3-small` are two vector spaces on ONE credential, so
 * a bare `embedding_connection_id` would leave the space undecided. EmbeddingSpace derives the
 * Qdrant collection name from (provider, model, width, distance, schema_version); the model half
 * has to be pinned here or the designation does not name a space.
 *
 * ── THE THREE CONSTRAINTS, AND WHAT EACH ONE STOPS ──────────────────────────────────────────────
 *
 * 1. COMPOSITE FOREIGN KEY (id, embedding_connection_id) -> provider_connections (organization_id, id).
 *    This is the tenancy guard, in the database. Without it an organization could designate ANOTHER
 *    organization's connection, and the consequence is not an information leak in the usual
 *    direction — it is this tenant's chunk text being sent to another tenant's provider account,
 *    billed to them and readable in their provider dashboard. A plain single-column FK to
 *    provider_connections(id) would accept that row happily. Note the referencing column list
 *    includes this table's own primary key: that is what ties the two organizations together.
 *    MATCH SIMPLE (the default) means the constraint is not checked when
 *    `embedding_connection_id` is NULL, which is precisely the "no designation yet" state.
 *
 * 2. CHECK num_nonnulls(...) <> 1 — both columns or neither. A connection with no model names no
 *    space; a model with no connection names no credential. Either half alone is a designation
 *    that cannot be resolved, and it would be discovered at the first upload, which is the late
 *    discovery C1 exists to remove.
 *
 * 3. ON DELETE RESTRICT on the composite FK. Deleting a connection that is currently designated
 *    must fail loudly rather than SET NULL, because SET NULL would silently return the
 *    organization to "resolve by rule" — and if a second embedding-capable connection existed, the
 *    rule could then pick a DIFFERENT (provider, model) than the corpus was indexed under. The
 *    operator undesignates first, and sees what that means.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ───────────────────────────────────────────────────────────────
 *
 * No platform-default embedding credential column, and no "fall back to a platform key" flag. That
 * was recommended against and not on cost: a platform-funded credential sends tenant chunk text to
 * a vendor the organization never authorised, which is a privacy boundary rather than a
 * convenience. If it is ever offered it is opt-in per organization and audited, which is a
 * different column with a different name and an ADR in front of it.
 *
 * No cached readiness verdict, either. The verdict is a pure function of the connection set and
 * the capability matrix; caching it would mean a matrix change or a model-row edit could leave a
 * stale "ready" behind, and the failure of a stale ready is an ingest run that spends parse and
 * OCR before failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A bounded wait, not a queue. ACCESS EXCLUSIVE blocks readers AND queues behind any
        // in-flight reader, so a 4 ms catalog update can take the product down for the length of
        // one long analytics query (postgresql-patterns). Failing fast ten times is a non-event.
        $this->run("SET lock_timeout = '3s'");

        // Both ADD COLUMNs are nullable with no default, so the value goes nowhere near
        // pg_attribute.attmissingval and no rewrite happens: momentary lock, no table scan.
        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN embedding_connection_id char(26) COLLATE "C",
                ADD COLUMN embedding_model         text
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_embedding_designation_complete
                CHECK (num_nonnulls(embedding_connection_id, embedding_model) <> 1)
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_embedding_connection_same_org
                FOREIGN KEY (id, embedding_connection_id)
                REFERENCES provider_connections (organization_id, id)
                ON DELETE RESTRICT
        SQL), 2000);

        // The referencing side of the FK above. Partial, because the overwhelming majority of rows
        // have no designation and an index over them would be dead weight on every write.
        $this->run(<<<'SQL'
            CREATE INDEX organizations_embedding_connection_id
                ON organizations (embedding_connection_id)
                WHERE embedding_connection_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP INDEX IF EXISTS organizations_embedding_connection_id');
        $this->run(
            'ALTER TABLE organizations '
            .'DROP CONSTRAINT IF EXISTS organizations_embedding_connection_same_org',
        );
        $this->run(
            'ALTER TABLE organizations '
            .'DROP CONSTRAINT IF EXISTS organizations_embedding_designation_complete',
        );
        $this->run(
            'ALTER TABLE organizations '
            .'DROP COLUMN IF EXISTS embedding_model, '
            .'DROP COLUMN IF EXISTS embedding_connection_id',
        );
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

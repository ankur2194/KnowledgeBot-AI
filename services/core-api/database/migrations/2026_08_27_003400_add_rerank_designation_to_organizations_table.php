<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE RERANK DESIGNATION: which of an organization's connections supplies the rerank credential.
 *
 * The third per-surface provider choice. `provider_connection_id` on `bots` decides who answers,
 * `organizations.embedding_connection_id` decides who embeds (ADR-031), and until this migration
 * "use NVIDIA NIM for reranking and OpenAI for chat" was not expressible anywhere in the platform:
 * `app/rag/rerank.py::rerank_gate` takes a `model` argument and there is nothing upstream of it
 * that decides what that model is.
 *
 * IT MIRRORS THE EMBEDDING DESIGNATION DELIBERATELY AND IN EVERY STRUCTURAL DETAIL — two columns,
 * the same three constraints, the same partial index — because the two are the same kind of fact
 * about the same table, and a second shape for it would mean two things to check when a connection
 * is deleted and two migrations to read when either one misbehaves. Where they differ is ONE thing,
 * and it is the whole of the difference:
 *
 * ── NULL IS A SUPPORTED OPERATING MODE HERE, NOT AN UNCONFIGURED ONE ───────────────────────────
 *
 * A null embedding designation means the resolution rule in
 * services/ai-service/app/providers/embedding_selection.py picks, and if it cannot pick, the
 * organization CANNOT INGEST A SINGLE DOCUMENT — every chunk is embedded before it is indexed and
 * there is no degraded mode.
 *
 * A null rerank designation means stage 11 does not run. `rerank_gate` returns
 * `RerankSkipReason.MODEL_NOT_CONFIGURED` before any call goes out, `evidence.select_unranked`
 * selects on branch agreement instead, and the answer is served from fused order. That is a
 * cheaper, measured, deliberately supported mode — the skip is on the retrieval trace as
 * `rerank_skip_reason` — and it is why there is no resolve-by-rule for reranking on either side of
 * the seam: with no ML model to fall back to (ADR-030) and no calibrated default scale
 * (`rerank.CALIBRATIONS` is empty on purpose), guessing a reranker would be guessing a threshold,
 * and 0.30 is a valid float on every scale a vendor returns.
 *
 * So the operator's choice is three-valued and all three are legitimate: no reranking, a reranker
 * that works, or a reranker the data plane refuses at query time. Only the third is a problem, and
 * it is not one this table can detect — see below.
 *
 * ── WHAT THIS MIGRATION AND ITS ENDPOINT DELIBERATELY DO NOT ENCODE ────────────────────────────
 *
 * WHICH VENDORS CAN RERANK. That is repository-level data in the data plane and it is three
 * separate questions, not one: does the vendor publish a ranking route
 * (`capabilities.PROVIDER_TASKS`), does this model row claim the capability
 * (`provider_models.capability_flags`), and can this platform threshold the score scale that comes
 * back (`capabilities.RERANK_SCALE`, finding #47). `capabilities.can_rerank` is the AND of all
 * three and is the only place that question is answered. A CHECK constraint here naming providers,
 * or a PHP copy of the matrix behind the endpoint, would be a second decision procedure that can
 * disagree with the first — and it would disagree the day a vendor ships a ranking endpoint,
 * silently, in the direction of refusing a configuration that works.
 *
 * The consequence is stated rather than hidden: an organization CAN designate a (connection, model)
 * pair this platform will not rerank with, the write succeeds, and the skip is reported at query
 * time on the retrieval trace. That is the correct division — Laravel owns tenancy and existence,
 * the data plane owns capability — and it is not a gap.
 *
 * ── THE THREE CONSTRAINTS, AND WHAT EACH ONE STOPS ─────────────────────────────────────────────
 *
 * 1. COMPOSITE FOREIGN KEY (id, rerank_connection_id) -> provider_connections (organization_id, id).
 *    The tenancy guard, in the database, and the consequence of its absence is the same one the
 *    embedding migration spells out: not a read leak, but THIS tenant's user questions and
 *    retrieved chunk text being sent to ANOTHER tenant's provider account — billed to them and
 *    readable in their provider dashboard. A single-column FK to provider_connections(id) would
 *    accept that row happily. The referencing column list includes this table's own primary key;
 *    that is what ties the two organizations together. MATCH SIMPLE (the default) means the
 *    constraint is not checked while `rerank_connection_id` is NULL, which is exactly the "no
 *    reranking" state.
 *
 * 2. CHECK num_nonnulls(...) <> 1 — both columns or neither. A connection with no model names no
 *    reranker; a model with no connection names no credential. Either half alone is a designation
 *    nothing can act on, and a half-designation reads to `rerank_gate` as `model = null`, i.e. as
 *    "reranking is off" — the operator's choice silently discarded with no error anywhere.
 *
 * 3. ON DELETE RESTRICT on the composite FK, and it is a WEAKER argument than embedding's, stated
 *    honestly. There, SET NULL would return the organization to resolve-by-rule and could move the
 *    vector space under an already-indexed corpus. Here, SET NULL would merely turn reranking off.
 *    RESTRICT is still right, for a different reason: turning reranking off is a change to answer
 *    quality with NO error, no metric jump on any single request, and no trace of a decision — the
 *    exact failure this platform is least able to notice. The operator undesignates first and sees
 *    what that means. `ProviderConnectionService::DESIGNATED_FOR_RERANK` is the sentence they get.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ──────────────────────────────────────────────────────────────
 *
 * No `rerank_enabled` boolean. `rerank_gate`'s `enabled` argument is a BOT-level switch, and a
 * second org-level one would make "why is reranking off" a two-table question with no rule for
 * which wins. Null here IS off for the whole organization.
 *
 * No `rerank_top_k`, no `min_score`, no calibration column. A threshold belongs to a
 * `(provider, model)` calibration derived from an evaluation run (`rerank.CALIBRATIONS`), and a
 * per-organization number would let an operator set a value on a scale nobody has measured — which
 * is precisely what `RerankCalibration.__post_init__` exists to make unconstructible.
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
                ADD COLUMN rerank_connection_id char(26) COLLATE "C",
                ADD COLUMN rerank_model         text
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_rerank_designation_complete
                CHECK (num_nonnulls(rerank_connection_id, rerank_model) <> 1)
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_rerank_connection_same_org
                FOREIGN KEY (id, rerank_connection_id)
                REFERENCES provider_connections (organization_id, id)
                ON DELETE RESTRICT
        SQL), 2000);

        // The referencing side of the FK above, and it is what makes the connection DELETE's
        // constraint check an index lookup instead of a sequential scan of `organizations`.
        // Partial, because the overwhelming majority of rows have no rerank designation and an
        // index over them would be dead weight on every write.
        $this->run(<<<'SQL'
            CREATE INDEX organizations_rerank_connection_id
                ON organizations (rerank_connection_id)
                WHERE rerank_connection_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP INDEX IF EXISTS organizations_rerank_connection_id');
        $this->run(
            'ALTER TABLE organizations '
            .'DROP CONSTRAINT IF EXISTS organizations_rerank_connection_same_org',
        );
        $this->run(
            'ALTER TABLE organizations '
            .'DROP CONSTRAINT IF EXISTS organizations_rerank_designation_complete',
        );
        $this->run(
            'ALTER TABLE organizations '
            .'DROP COLUMN IF EXISTS rerank_model, '
            .'DROP COLUMN IF EXISTS rerank_connection_id',
        );
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

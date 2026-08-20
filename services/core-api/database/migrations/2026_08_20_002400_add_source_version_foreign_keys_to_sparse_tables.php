<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE FOREIGN KEY `2026_08_07_000600` OWED, PAID.
 *
 * That migration's docblock states the debt verbatim, and this file is the redemption of it, word
 * for word:
 *
 *     "`source_version_id` carries NO foreign key, because `source_versions` does not exist in this
 *      repository yet — no migration creates it. ... What is owed when `source_versions` lands is
 *      one migration adding `FOREIGN KEY (organization_id, source_version_id) REFERENCES
 *      source_versions (organization_id, id) ON DELETE CASCADE` to both tables — composite, for the
 *      same reason every other cross-entity key here is composite, and CASCADE rather than RESTRICT
 *      because these rows are derived and must not be able to block the purge of the thing they
 *      were derived from."
 *
 * `source_versions` landed four migrations ago. Both constraints are added below, exactly as
 * specified, and nothing else about those two tables is touched.
 *
 * ═══ CASCADE, AND IT IS THE ONE PLACE IN THIS CASCADE THAT IS NOT RESTRICT ════════════════════
 *
 * Every other foreign key in the six tables of Phase C1 is `ON DELETE RESTRICT`, because every
 * other child is something a purge has to remove DELIBERATELY, in a verified order, with a count on
 * both sides. These two are different in kind: `sparse_version_statistics` and
 * `sparse_term_frequencies` hold BM25 document frequencies that are a pure function of the
 * version's own chunk text under a fixed analyzer. They are derived in the ADR-010 sense, they are
 * authoritative for nothing, and a rebuild reproduces every value exactly.
 *
 * RESTRICT HERE WOULD BE ACTIVELY HARMFUL RATHER THAN MERELY STRICT. A version whose statistics
 * were not purged first could not be deleted at all, so a crash between two steps of the purge
 * would wedge the version in `Deleting` — and `postgresql-patterns` already records what that looks
 * like from the outside ("the purge job times out in Deleting forever"). Worse, the statistics
 * outliving their version is the state `sparse.idf` raises on: "document frequency ... exceeds the
 * scope total", which is the message this codebase reserves for a statistics set read over a wider
 * scope than the query — i.e. an interrupted purge would raise a CROSS-TENANT ALARM.
 *
 * THIS DOES NOT MAKE THE EXPLICIT PURGE REDUNDANT. `relational.py`'s `RELATIONAL_PURGE_ORDER` still
 * deletes both tables by name, with counts, before it touches `source_versions` — a cascade is a
 * safety net that produces no evidence, and deletion has to be VERIFIED (non-negotiable 6). What
 * the cascade guarantees is that the net exists when the ordered purge is interrupted.
 *
 * NAMING THE TABLE REACHABLE THROUGH THE CASCADE, as `postgresql-patterns` requires for any
 * `ON DELETE` other than RESTRICT: `source_versions` -> `sparse_version_statistics` and
 * `source_versions` -> `sparse_term_frequencies`. Neither has children, so the graph stops there.
 * Nothing reaches `citations`, `messages`, `retrieval_traces` or `audit_logs`.
 *
 * ═══ NO NEW INDEX, AND THAT IS CHECKED RATHER THAN ASSUMED ════════════════════════════════════
 *
 * PostgreSQL does not index the referencing side of a foreign key, so a child normally needs one or
 * every parent delete sequentially scans it. Both of these tables already lead their PRIMARY KEY
 * with exactly the pair the new constraints name:
 *
 *     sparse_version_statistics  PRIMARY KEY (organization_id, source_version_id, analyzer)
 *     sparse_term_frequencies    PRIMARY KEY (organization_id, source_version_id, analyzer, term_id)
 *
 * The referential-integrity probe binds `(organization_id, source_version_id)`, which is the leading
 * two-column prefix of both. `2026_08_07_000600` argues at length that these tables have exactly one
 * index each and that a second one would be "a b-tree maintained on every insert into the
 * highest-cardinality table in this schema" — that argument survives this migration intact, and
 * adding an index here would be the first thing to break it.
 *
 * ═══ LOCKING ═════════════════════════════════════════════════════════════════════════════════
 *
 * `ADD FOREIGN KEY` takes SHARE ROW EXCLUSIVE on both tables and SCANS THE CHILD. On a live
 * deployment with real corpora, `sparse_term_frequencies` is terms x versions x analyzers and that
 * scan is not free — so `lock_timeout` is set first and the statement retries rather than queueing
 * behind a long read, because a blocked DDL blocks every reader that arrives after it.
 *
 * NOT SPLIT INTO `NOT VALID` + `VALIDATE CONSTRAINT`, deliberately, and the reason is specific to
 * these two tables rather than a general preference. The split exists to move the scan under a
 * weaker lock, and it is the right shape for a populated table. Here the tables are EMPTY in every
 * environment this migration will run in before the source cascade exists — there is no ingestion
 * path that could have written a sparse statistic for a version that could not be created — so the
 * scan is over zero rows and the split would add a second statement and a second failure mode for
 * no benefit. WHAT WOULD CHANGE THAT: any deployment where these tables are already populated. The
 * two-statement form is `ADD CONSTRAINT ... NOT VALID` followed by `VALIDATE CONSTRAINT`, and it is
 * named here so the person who needs it does not have to derive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 55P03 instead of a queue. A migration that fails fast ten times is a non-event; a
        // migration that waits is an outage, because PostgreSQL's lock queue is ordered and a
        // blocked DDL blocks readers it would never have blocked.
        $this->run("SET lock_timeout = '3s'");

        $this->run(<<<'SQL'
            ALTER TABLE sparse_version_statistics
                ADD CONSTRAINT sparse_version_statistics_version_same_org
                FOREIGN KEY (organization_id, source_version_id)
                REFERENCES source_versions (organization_id, id) ON DELETE CASCADE
        SQL);

        $this->run(<<<'SQL'
            ALTER TABLE sparse_term_frequencies
                ADD CONSTRAINT sparse_term_frequencies_version_same_org
                FOREIGN KEY (organization_id, source_version_id)
                REFERENCES source_versions (organization_id, id) ON DELETE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->run("SET lock_timeout = '3s'");

        // Reverse order.
        $this->run(
            'ALTER TABLE sparse_term_frequencies '
            .'DROP CONSTRAINT IF EXISTS sparse_term_frequencies_version_same_org',
        );
        $this->run(
            'ALTER TABLE sparse_version_statistics '
            .'DROP CONSTRAINT IF EXISTS sparse_version_statistics_version_same_org',
        );
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

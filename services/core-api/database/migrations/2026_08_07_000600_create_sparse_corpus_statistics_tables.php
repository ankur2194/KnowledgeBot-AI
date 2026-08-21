<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * FINDING C2, CONTROL-PLANE HALF: the document-frequency rollup behind the sparse arm.
 *
 * ADR-030 removed the sparse producer along with local model inference. C2 restores it with
 * locally computed BM25 — permitted explicitly, because a statistical ranking function is not a
 * model. `services/ai-service/app/retrieval/sparse.py` splits BM25 across the two vectors so that
 * every corpus-dependent quantity sits on the QUERY side: the passage vector is a pure function of
 * its own text, and the IDF factor is applied per request from these tables. That split is what
 * keeps the index rebuildable and keeps one tenant's statistics out of another's points.
 *
 * `services/ai-service` owns no migration, so the schema lands here. Its `app/db/writes.py` holds a
 * closed allow-list of the tables the data plane may write, and ADR-033 fixes what admission to it
 * means: NOT a count — the count moved when these two tables were admitted and it will move again,
 * so any file restating it is wrong on the next legitimate addition. A name belongs on that list
 * only when all three of these hold at once, and adding one that fails any of them is a review stop
 * rather than a refactor:
 *
 *   1. Every row is DERIVED AND REBUILDABLE in the ADR-010 sense — a pure function of content
 *      already in PostgreSQL and object storage, reproduced exactly by a rebuild, authoritative
 *      for nothing.
 *   2. NO PUBLIC API PATH reads or writes it. This is the property that actually bites: a
 *      data-plane write into a table Laravel serves lands beside Laravel's own writer with no
 *      policy, no audit row and no framework-applied tenant scope, and it fails nowhere — the row
 *      is simply there.
 *   3. LARAVEL OWNS THE MIGRATION. Schema is never defined on the data-plane side, in any form.
 *
 * Both tables below satisfy all three. (1) is argued under "THESE TABLES ARE DERIVED" and enforced
 * by the absence of any history, soft delete or audit column. (2) holds because nothing under
 * `app/Http/` names either table: the only Laravel reader is
 * `App\Repositories\Eloquent\EloquentSparseCorpusStatisticsRepository`, which reads and never
 * writes, and which exists to prove this schema can express the version-set scoping below. (3) is
 * this file.
 *
 * ── THE SCOPE IS THE VERSION SET, NOT THE ORGANIZATION, AND THAT IS THE WHOLE DESIGN ───────────
 *
 * The read is `organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3 AND
 * term_id = ANY($4)`, where `$2` is the RESOLVED ACTIVE-VERSION SET the tenant filter is built
 * from — not the organization as a whole, and `$3` is the analyzer, which is a predicate rather
 * than a filter of convenience: it is the third column of the primary key, and the section below
 * gives the reason a read that omits it fails silently instead of erroring. The identical
 * statement is restated on the data-plane side in `app/retrieval/sparse.py`; the two must agree
 * placeholder for placeholder, because whoever implements the store reads only one of them.
 *
 * Scoping to the organization alone would leave a weak oracle INSIDE an organization,
 * across bots: a term's IDF reflects how rare it is in the scope it was summed over, so an
 * organization-wide rollup makes a part number appearing in one document weigh differently for a
 * bot that cannot see that document. Nothing leaks a chunk, but the RANKING leaks the existence of
 * documents outside the bot's scope, and it does so through a 200 with plausible results.
 *
 * The schema is what makes that scoping expressible rather than optional: `source_version_id` is
 * part of the primary key, so there is no way to read a row without naming a version, and
 * therefore no query shape that sums across an organization by accident. `CorpusStatistics.for_scope`
 * on the data-plane side raises on a foreign org, on a moved version set (a disable or a delete
 * moves the fingerprint), and on a foreign analyzer — and those three checks read THESE columns.
 *
 * ── ANALYZER IS PART OF THE KEY ────────────────────────────────────────────────────────────────
 *
 * Term ids are `blake2b(term)` under a fixed personalization, so two analyzers produce two
 * different id spaces over the same text. Summing across them yields a number that is not a
 * document frequency of anything, and nothing would raise. Keying on the analyzer means a bump
 * writes new rows beside the old ones, the old ones keep serving the versions indexed under them,
 * and `for_scope`'s analyzer check has a column to compare against.
 *
 * ── THESE TABLES ARE DERIVED, IN THE ADR-010 SENSE ─────────────────────────────────────────────
 *
 * Every row is a pure function of the version's chunk text under `SPARSE_ANALYZER_VERSION`.
 * Nothing here is a source of truth, nothing here is backed up separately, and a rebuild from
 * PostgreSQL plus object storage reproduces every value exactly — which is the same property the
 * vectors have and for the same reason. Hence `document_frequency` and `document_total` carry no
 * history, no soft delete and no audit trail: there is nothing to reconstruct that the chunks do
 * not already carry.
 *
 * ── LIFECYCLE: THREE RULES, NOT THE THREE PROPERTIES ABOVE ─────────────────────────────────────
 *
 * 1. Written in the SAME TRANSACTION as the version's `chunks` rows. The primary keys are what
 *    make a redelivered Celery task overwrite rather than accumulate — an `ON CONFLICT ... DO
 *    UPDATE` on a natural key, not an append.
 * 2. Written against the NEW, NOT-YET-ACTIVE `source_version_id`. This is non-negotiable #5 in
 *    CLAUDE.md, seen from an unexpected angle: statistics that appeared before the version was
 *    published would change the IDF of terms in queries against the PREVIOUS version, so the old
 *    version's ranking would shift under a query nobody re-ran. Because the read is scoped by the
 *    active-version set, a version that is not yet active contributes nothing, automatically.
 * 3. Removed WITH the version. Also automatic for correctness — a retired version leaves the
 *    active set and stops weighting queries at the instant its vectors stop matching them — and
 *    the physical purge is deletion's, by the same identifiers it already uses.
 *
 * ── THE FOREIGN KEY THAT IS MISSING, DELIBERATELY AND TEMPORARILY ──────────────────────────────
 *
 * `source_version_id` carries NO foreign key, because `source_versions` does not exist in this
 * repository yet — no migration creates it. The tenancy requirement is met regardless:
 * kb-tenancy-isolation NN1 asks for `organization_id NOT NULL` OR a NOT NULL FK chain, and these
 * tables hold the column directly, with its own FK to `organizations`.
 *
 * What is owed when `source_versions` lands is one migration adding
 * `FOREIGN KEY (organization_id, source_version_id) REFERENCES source_versions (organization_id, id)
 *  ON DELETE CASCADE` to both tables — composite, for the same reason every other cross-entity key
 * here is composite, and CASCADE rather than RESTRICT because these rows are derived and must not
 * be able to block the purge of the thing they were derived from. Reported rather than guessed at:
 * inventing `source_versions` to hang a key on would put a table kb-source-lifecycle owns into a
 * migration about BM25.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The per-version document total: how many chunks the version contributed. The IDF
        // formula's numerator, and meaningless without the same scope the frequencies carry —
        // which is why it is keyed identically rather than stored on `source_versions`.
        //
        // Read as `organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3`, summed.
        // The analyzer predicate is as load-bearing here as it is on the frequencies and is easier
        // to forget, because a document total READS as analyzer-independent: it counts chunks, and
        // a version has the same chunks under either analyzer. The COLUMN is not independent even
        // so, because the row is written per analyzer — so an analyzer-blind sum over a version
        // indexed under two of them adds both rows and lands in the IDF numerator.
        //
        // And precisely because the count really does not depend on the analyzer, those two rows
        // normally hold the SAME number, so the wrong answer is a clean doubling: a corpus twice
        // its real size, every score shifted by one constant, no row missing, nothing raised. A
        // fixture that seeds the same total under both analyzers therefore cannot distinguish a
        // bound analyzer from an unbound one by inspection of any single read — which is why the
        // test for this seeds two DIFFERENT totals.
        $this->run(<<<'SQL'
            CREATE TABLE sparse_version_statistics (
                organization_id    char(26) COLLATE "C" NOT NULL
                                   REFERENCES organizations (id) ON DELETE RESTRICT,
                source_version_id  char(26) COLLATE "C" NOT NULL,
                analyzer           text NOT NULL,
                document_total     integer NOT NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (organization_id, source_version_id, analyzer),
                CONSTRAINT sparse_version_statistics_total_non_negative
                    CHECK (document_total >= 0)
            )
        SQL);

        $this->run(<<<'SQL'
            CREATE TABLE sparse_term_frequencies (
                organization_id     char(26) COLLATE "C" NOT NULL
                                    REFERENCES organizations (id) ON DELETE RESTRICT,
                source_version_id   char(26) COLLATE "C" NOT NULL,
                analyzer            text NOT NULL,
                term_id             bigint NOT NULL,
                document_frequency  integer NOT NULL,
                PRIMARY KEY (organization_id, source_version_id, analyzer, term_id),
                -- bigint, not integer. Qdrant sparse indices are UNSIGNED 32-bit, and PostgreSQL
                -- has no unsigned types: `integer` tops out at 2^31-1, so every term hashing above
                -- that would fail the insert. Roughly half of them would.
                CONSTRAINT sparse_term_frequencies_term_id_uint32
                    CHECK (term_id >= 0 AND term_id < 4294967296),
                -- A document frequency of zero is not a row. `CorpusStatistics.idf_for` already
                -- treats an absent term as df = 0 (maximally rare), so storing zeros would double
                -- the table to express the default.
                CONSTRAINT sparse_term_frequencies_positive
                    CHECK (document_frequency > 0)
            )
        SQL);

        // ── THERE IS NO SECOND INDEX ON EITHER TABLE, AND THAT IS THE DESIGN ──────────────────
        //
        // The primary key serves every access path in the tree, in both directions:
        //
        //   read    `organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3 AND
        //            term_id = ANY($4)` — all four columns, in key order, equality-or-ANY
        //            throughout (`EloquentSparseCorpusStatisticsRepository::load`). Note that
        //            dropping the analyzer predicate would not merely widen the result: it would
        //            also leave `term_id` unable to use the key at all, so the wrong answer would
        //            arrive slowly as well as silently.
        //   purge   `organization_id = %s AND source_version_id = ANY(%s)` — the leading
        //            two-column prefix (services/ai-service/app/deletion/relational.py).
        //   residue `organization_id = %s` — the leading one-column prefix, same file.
        //   write   upserted `ON CONFLICT` on this exact key, per lifecycle rule 1 above.
        //
        // This file previously carried `CREATE INDEX sparse_term_frequencies_version ON
        // sparse_term_frequencies (source_version_id, analyzer)`, justified as serving "the write
        // and delete paths, which address a version WITHOUT knowing its organization first". That
        // access path does not exist and cannot be built: `relational.py`'s `version_scope()`
        // RAISES on a blank organization — "a deletion statement without an organization is not
        // scoped at all" — so the purge worker has no way to bind a version id without one, and
        // every one of the eight statements it holds for these two tables leads with
        // `organization_id`. An index nobody can name a query for is not free: it is a second
        // b-tree maintained on every insert into the highest-cardinality table in this schema
        // (terms x versions x analyzers), and it reads as intentional to the next person, who will
        // preserve it.
        //
        // Measured rather than assumed: on PostgreSQL 18 even the hypothetical
        // `WHERE source_version_id = ? AND analyzer = ?` plans as an Index Scan on this primary
        // key via b-tree skip scan, not a sequential scan — so the "sequential scan of a table
        // that reaches term cardinality times version count" the removed comment warned about was
        // not the alternative either. postgresql-patterns' caveat still applies (skip scan
        // enumerates the leading column's distinct values, which is useless at organization
        // scale), but it does not need to: no code can issue that predicate.
        //
        // WHAT WOULD REOPEN IT: a version-keyed access path that legitimately arrives without a
        // tenant — a global orphan sweep over `source_versions`, say. That is not a free choice.
        // kb-tenancy-isolation requires such a query to carry an inline `// tenancy-exempt:
        // <reason>` marker, and the index would then be added in the same change as the query, with
        // that query named here.
        //
        // THAT MARKER IS READ BY REVIEWERS AND BY NOTHING ELSE. This comment used to end "so the CI
        // grep allow-lists it deliberately", which was true when it was written and is not true
        // now: `.github/` was deleted on 2026-08-17 and nothing replaced it, so no grep allow-lists
        // anything and no build fails on a missing marker. The marker is still required — it is how
        // the next reader learns the omission was deliberate — but it is a convention held up by
        // review, and a comment claiming a mechanism that does not exist is worse than one claiming
        // none, because it tells a reader the case is already covered.
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS sparse_term_frequencies');
        $this->run('DROP TABLE IF EXISTS sparse_version_statistics');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

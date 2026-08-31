<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * What retrieval actually did for one answer (docs/11 §16.6, docs/07 §12).
 *
 * ═══ THIS TABLE IS THE ONLY EVIDENCE THAT A QUERY WAS TENANT-FILTERED ═══════════════════════
 *
 * kb-tenancy-isolation opens on what the leak looks like: HTTP 200, normal latency, normal
 * candidate counts, a well-formed answer with well-formed citations pointing at a document the
 * organization never uploaded. No exception, no log line, no metric — *"and without
 * `retrieval_traces.filters` you cannot even bound which past answers were affected."* That
 * sentence is why `filters` is NOT NULL, why it is checked, and why this row is written for every
 * query rather than only for the ones somebody is debugging.
 *
 * `retrieval_traces_filters_name_mandatory_terms` requires the four filter terms of NN3 to be
 * PRESENT AS KEYS: `org_id`, `bot_ids`, `source_status`, `source_version_id`. BE PRECISE ABOUT WHAT
 * THAT PROVES AND WHAT IT DOES NOT. It proves the trace RECORDS four terms. It cannot prove the
 * query CARRIED them — the row is written by the same code that built the filter, so a caller that
 * assembled a narrower filter and then described a complete one would satisfy it. What it does buy
 * is the thing the skill asks for: a trace that CANNOT say which organization it was scoped to is
 * refused at write time rather than discovered, empty, during an incident. The real guard is the
 * data plane's `tenant_filter()` having no default and no optional argument.
 *
 * ═══ NO `organization_id`; IT REACHES ONE THROUGH `messages -> conversations` ════════════════
 *
 * kb-tenancy-isolation NN1's second shape, and the chain is NOT NULL end to end. `App\Models\
 * RetrievalTrace` carries no `#[ScopedBy]` for the reason `Message` does not: the scope appends a
 * predicate on a column that does not exist. Reads go through a repository method taking
 * `organization_id` as a required positional argument, joined through `messages`.
 *
 * ═══ ONE TRACE PER MESSAGE, ENFORCED BY A UNIQUE KEY ════════════════════════════════════════
 *
 * `message_id` is UNIQUE, not merely indexed. A second trace for one answer would make the
 * diagnostics panel show whichever row came back first, and the §21.5 regression gate replay
 * against whichever row it joined — two different explanations of the same answer, with nothing
 * saying which is real. A RETRY produces a new MESSAGE (`messages.parent_message_id`), and that new
 * message gets its own trace.
 *
 * ═══ FOUR jsonb COLUMNS AND TWO DIFFERENT SHAPES, WHICH IS THE POINT ════════════════════════
 *
 *   filters            OBJECT   the four mandatory terms plus §12.6's optional ones.
 *   timing_breakdown   OBJECT   per-stage milliseconds, keyed by stage name.
 *   candidate_summaries ARRAY   ordered, one entry per candidate. ORDER IS THE DATA.
 *   selected_evidence   ARRAY   ordered, one entry per passage that survived the threshold.
 *
 * THE TWO ARRAYS GET `jsonb_typeof(...) = 'array'` AND NOT `'object'`, and the asymmetry is
 * deliberate rather than an oversight in a schema whose every other jsonb column is an object. A
 * ranked list IS a list: its order is the ranking, and storing it as a map keyed by position would
 * make that order a property of key iteration, which JSON does not promise. The consequence reaches
 * the model: `App\Support\Casts\JsonObjectCast` must NOT be used on these two — its `(object)` cast
 * turns `[0 => …, 1 => …]` into `{"0":…,"1":…}`, which these constraints then refuse by type.
 * Eloquent's built-in `array` cast is correct here for exactly the reason it is wrong on an object
 * column: `json_encode([])` is `[]`, which is what an empty ranked list should be.
 *
 * AN EMPTY `selected_evidence` IS THE REFUSAL CASE AND IT IS A REAL ROW. `insufficient_evidence`
 * true with an empty array is precisely §12.13's "the bot declined to answer", and §8.23 counts it.
 * `retrieval_traces_insufficient_has_no_evidence` ties the two together so the flag cannot disagree
 * with the list beside it.
 *
 * ═══ THE QUERY TEXT IS TENANT PROSE AND IT IS STORED, DELIBERATELY ══════════════════════════
 *
 * `original_query` is what the human typed and `rewritten_query` is what §12.2 turned it into.
 * Storing both is the only way to explain an answer that retrieved the wrong thing because the
 * rewrite went wrong, which is a real and common failure. It is ALSO the most sensitive free text
 * on this row, so it lives here — behind the conversation's own retention deadline, cascaded away
 * with the message — and NEVER in `audit_logs`, which is append-only and outlives everything.
 * `AuditLogger`'s knowledge-source block states the same rule for document text.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE retrieval_traces (
                id                              char(26) COLLATE "C" PRIMARY KEY,

                -- NOT NULL and CASCADE: the trace explains one message and has no meaning without
                -- it. This is the NN1 chain and the "deleting a message takes its trace" rule.
                message_id                      char(26) COLLATE "C" NOT NULL
                                                REFERENCES messages (id) ON DELETE CASCADE,

                -- ── the two queries ─────────────────────────────────────────────────────────
                original_query                  text NOT NULL,
                -- NULL when §12.2 left the query alone, which is a real outcome and is different
                -- from a rewrite that happened to produce the same string.
                rewritten_query                 text,

                -- ── what the search was scoped to ───────────────────────────────────────────
                -- NOT NULL, no default. See the docblock: a trace that cannot say which
                -- organization it was scoped to is worthless during the incident it exists for.
                filters                         jsonb NOT NULL,

                -- Without it a trace cannot be replayed, which makes it worthless to the §21.5
                -- regression gate. Copied from `bots.retrieval_configuration_version` at query
                -- time, so an edit to the bot afterwards cannot rewrite the explanation of an
                -- answer that has already been given.
                retrieval_configuration_version integer NOT NULL,

                -- ── what came back, in order ────────────────────────────────────────────────
                candidate_summaries             jsonb NOT NULL DEFAULT '[]'::jsonb,
                selected_evidence               jsonb NOT NULL DEFAULT '[]'::jsonb,
                insufficient_evidence           boolean NOT NULL DEFAULT false,

                -- Per-stage milliseconds. §8.23's latency panel reads it; nothing filters on it.
                timing_breakdown                jsonb NOT NULL DEFAULT '{}'::jsonb,

                created_at                      timestamptz NOT NULL DEFAULT now(),

                -- ── shape ───────────────────────────────────────────────────────────────────
                CONSTRAINT retrieval_traces_original_query_not_blank
                    CHECK (btrim(original_query) <> ''),
                CONSTRAINT retrieval_traces_rewritten_query_not_blank
                    CHECK (rewritten_query IS NULL OR btrim(rewritten_query) <> ''),
                CONSTRAINT retrieval_traces_configuration_version_positive
                    CHECK (retrieval_configuration_version >= 1),

                -- ── the jsonb shapes: two objects and two arrays ────────────────────────────
                CONSTRAINT retrieval_traces_filters_is_object
                    CHECK (jsonb_typeof(filters) = 'object'),
                CONSTRAINT retrieval_traces_timing_breakdown_is_object
                    CHECK (jsonb_typeof(timing_breakdown) = 'object'),
                -- ARRAY, not object. The order IS the ranking. See the docblock.
                CONSTRAINT retrieval_traces_candidate_summaries_is_array
                    CHECK (jsonb_typeof(candidate_summaries) = 'array'),
                CONSTRAINT retrieval_traces_selected_evidence_is_array
                    CHECK (jsonb_typeof(selected_evidence) = 'array'),

                -- ── the four mandatory filter terms are NAMED ───────────────────────────────
                -- `jsonb_exists(...)` and NOT the one-character jsonb existence operator: PDO
                -- rewrites that character into a positional placeholder while scanning the
                -- statement, and the prepare then fails on a parameter nobody bound. The function
                -- form is identical to the server and invisible to PDO.
                --
                -- Read the docblock before relying on this: it proves the trace RECORDS four terms,
                -- not that the query CARRIED them.
                CONSTRAINT retrieval_traces_filters_name_mandatory_terms CHECK (
                    jsonb_exists(filters, 'org_id')
                    AND jsonb_exists(filters, 'bot_ids')
                    AND jsonb_exists(filters, 'source_status')
                    AND jsonb_exists(filters, 'source_version_id')
                ),

                -- ── the refusal is consistent with itself ───────────────────────────────────
                -- A trace that declined to answer while holding evidence, or answered while holding
                -- none, is a stored contradiction that §8.23's "questions with insufficient
                -- evidence" would count wrongly in one direction or the other.
                CONSTRAINT retrieval_traces_insufficient_has_no_evidence CHECK (
                    insufficient_evidence = false
                    OR jsonb_array_length(selected_evidence) = 0
                )
            )
        SQL);

        // ONE TRACE PER MESSAGE. Unique rather than merely indexed — see the docblock. It doubles as
        // the FK-child index, so the cascade from `messages` is an index scan rather than the
        // sequential scan postgresql-patterns records as the cause of a 40-minute delete.
        //
        // It leads with `message_id` for the reason the messages migration states: this table has no
        // `organization_id`, and the parent whose organization is fixed is the narrowest leading
        // term available.
        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX retrieval_traces_message_unique
                ON retrieval_traces (message_id)
        SQL);

        // §8.23's "questions with insufficient evidence", and the eval suite's regression set.
        // Partial, because a refusal is the small minority of rows and the only one either reads.
        $this->run(<<<'SQL'
            CREATE INDEX retrieval_traces_insufficient
                ON retrieval_traces (created_at DESC)
                WHERE insufficient_evidence = true
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS retrieval_traces');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

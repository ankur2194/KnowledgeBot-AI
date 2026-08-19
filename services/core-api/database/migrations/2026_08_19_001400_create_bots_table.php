<?php

declare(strict_types=1);

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\BotStatus;
use App\Enums\EvidenceThresholdScale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The retrieval scope (docs/02 §8.3, docs/11 §16.3).
 *
 * `bot_ids` is one of the four mandatory Qdrant filter terms (kb-tenancy-isolation NN3), so this
 * row is not merely another tenant-owned record: its identity is a term in every vector query the
 * platform issues. A bot quietly attached to the wrong organization defeats an isolation assertion
 * without touching a line of retrieval code, which is why every ownership fact below is enforced by
 * the database and not by the service that writes it.
 *
 * Written as SQL through DB::statement() for the reasons 2026_08_07_000100 records: `char(26)
 * COLLATE "C"`, `text` + CHECK instead of a native PG enum, `timestamptz`, and — new here —
 * composite foreign keys and a jsonb key-set constraint, none of which the Schema builder can
 * express.
 *
 * ═══ IDENTITY: THREE DIFFERENT KEYS, AND NONE OF THEM IS INTERCHANGEABLE ══════════════════════
 *
 *   id              the ULID. INTERNAL. It appears in admin URLs, in the configuration snapshot,
 *                   and in the `bot_ids` payload term. It is never handed to an end user.
 *   public_bot_id   the OPAQUE token a hosted-chat URL, a widget snippet and a theme stylesheet
 *                   request carry. Globally unique, because it is resolved with no organization in
 *                   hand — the request that carries it has not authenticated anything yet.
 *   slug            the HUMAN handle, unique PER ORGANIZATION.
 *
 * THE PUBLIC ID IS NOT THE ULID, AND THAT IS A SECURITY DECISION RATHER THAN A COSMETIC ONE. A ULID
 * carries a 48-bit millisecond timestamp in its leading characters, so publishing one publishes the
 * creation time of every bot and makes neighbouring bots guessable by anyone who has seen two of
 * them. It is also the identifier the admin surface authorizes against; reusing it on the
 * unauthenticated surface means one leaked string addresses both. The shape is pinned to
 * `^[A-Za-z0-9_-]{1,64}$` by a CHECK, and that grammar is not ours to widen: it is already
 * validated on the client side at
 * apps/web/src/app/(chat)/c/[publicBotId]/theme.css/route.ts:66, which refuses to forward anything
 * else as a path segment. Two independent statements of one grammar; a change to either is a change
 * to both.
 *
 * `public_bot_id` and `origin` on the child table are `text COLLATE "C"` rather than plain `text`
 * for the reason every id column in this schema is: both are compared for EXACT EQUALITY and
 * nothing else, so `memcmp` is the correct comparison and pinning it to byte order means a
 * libc/ICU upgrade cannot silently invalidate the index. Human-readable text — `name`,
 * `description`, `welcome_message` — is plain `text`, because those are sorted and searched for
 * humans and the database's collation is the right answer there.
 *
 * THE SLUG IS UNIQUE PER ORGANIZATION AND NOT GLOBALLY, which is the opposite of
 * `organizations.slug` and the reason that migration says so explicitly. Every Valkey key, rate
 * limit counter and cache key derived from a bot slug therefore carries `org_id` FIRST — a slug
 * alone is not globally unique, and keying on one is a shipped CVE class (WSO2 CVE-2025-13475,
 * where consent granted in one tenant applied to same-named applications in others), not a
 * hypothetical.
 *
 * ═══ THE TWO COMPOSITE FOREIGN KEYS ═══════════════════════════════════════════════════════════
 *
 * `provider_connection_id` and `provider_model_id` are each guarded by a composite key against
 * `(organization_id, id)` of their parent, the same construction
 * `provider_models_connection_same_org` uses. Without it a bot could name another tenant's
 * connection — which would mean THIS tenant's conversations billed to THAT tenant's provider
 * account and readable in their provider dashboard, while every downstream layer agreed, because
 * you told it whose credential answers.
 *
 * BOTH COLUMNS ARE NULLABLE AND THE FOREIGN KEYS ARE STILL CORRECT, because a multi-column foreign
 * key defaults to `MATCH SIMPLE`: the constraint is not checked at all when ANY referencing column
 * is NULL. `organization_id` is NOT NULL and the model id is not, so a bot with no model chosen yet
 * skips the check and a bot WITH one is fully checked. `MATCH FULL` would be wrong here — it would
 * reject exactly the half-populated draft state this table has to hold, since `organization_id` is
 * never null.
 *
 * They are nullable because a bot is created before it is configured. The state "draft, no model
 * yet" is the first state every bot is in, and the publish guard — not a NOT NULL constraint — is
 * what refuses to expose one.
 *
 * ═══ THE EVIDENCE THRESHOLD IS NOT AN ORDINARY NUMBER ═════════════════════════════════════════
 *
 * IT IS NULLABLE, IT HAS NO DEFAULT, AND IT IS STORED BESIDE ITS SCALE. This is the one retrieval
 * knob on this table that cannot be shipped with the specification's value, and the reason is
 * recorded in kb-rag-query-contract §12 and in bge-reranker: `evidence.min_score = 0.30 on the
 * sigmoid scale` was a property of `bge-reranker-v2-m3` under `normalize=True`, and ADR-030
 * replaced that one local model with a per-organization provider. The scale is a property of the
 * `(provider, model)` PAIR — an unbounded logit for one vendor, a bounded relevance score for
 * another — and `CALIBRATIONS` in the data plane is EMPTY ON PURPOSE, with `RerankCalibration`
 * refusing construction on an uncalibrated pair rather than defaulting.
 *
 * SEEDING A DEFAULT HERE WOULD FAIL NO TEST, WHICH IS EXACTLY WHY IT MUST NOT BE DONE. `0.30` is a
 * valid float on every scale: applying it to a logit passes almost everything, applying a logit
 * threshold to a bounded score refuses almost everything. Nothing raises. Only the refusal rate
 * moves, and only in aggregate, and since ADR-030 it moves for ONE TENANT and not the rest. A
 * column default would put that number on every bot ever created, silently, and the first evidence
 * would be a customer saying the answers got worse.
 *
 * Three constraints hold the pair together, and each catches a different mistake:
 *   bots_evidence_threshold_paired  a number with no scale, or a scale with no number, is refused.
 *                                   Either alone is uninterpretable.
 *   bots_evidence_threshold_scale   the vocabulary, generated from EvidenceThresholdScale::values()
 *                                   so the enum and the constraint cannot drift. `uncalibrated` —
 *                                   a real member of the data plane's RerankScale — is absent by
 *                                   construction: it means "no characterization exists", so a
 *                                   threshold carrying it is a stored contradiction.
 *   bots_evidence_threshold_range   a threshold on a BOUNDED scale must lie in [0, 1]. The only
 *                                   part of the portability problem a constraint can catch: 1.7 is
 *                                   refused on sigmoid and accepted on a logit, correctly in both
 *                                   directions.
 *
 * ═══ THE RETRIEVAL DEPTHS, AND THE VERSION THAT MAKES THEM REPLAYABLE ═════════════════════════
 *
 * dense 20, sparse 20, rerank candidates 20–30, retain 6–10 — docs/07 §12.7–12.12 as written, and
 * they are STARTING POINTS rather than findings: kb-rag-query-contract requires every one of them
 * to move through an evaluation run with an immutable configuration snapshot, never by intuition.
 * The CHECK ranges on the two rerank knobs are the specification's own bands and are enforced here
 * because a value outside them is not a tuning choice, it is a typo that would change what the
 * §21.5 regression gate is comparing.
 *
 * `retrieval_configuration_version` is not decoration. A retrieval trace without it cannot be
 * replayed, which makes it worthless to the regression gate — so the version is a real column that
 * the service bumps on every write that touches a retrieval knob, and it travels into the
 * configuration snapshot and into every trace the bot produces.
 *
 * ═══ PUBLISHING, APPEARANCE, AND THE ESCAPE HATCH ═════════════════════════════════════════════
 *
 * `allow_general_answers` DEFAULTS FALSE and is a SEPARATE COLUMN FROM `answer_mode`, deliberately.
 * The mode says how the pipeline is meant to behave; this flag is the publish guard's only escape
 * hatch — the one field an operator has to set on purpose before a bot may answer from anything but
 * its sources. Folding them into one field would make "RAG-first but not yet cleared to publish"
 * unexpressible, and the guard would have nothing left to check.
 *
 * `theme` is jsonb, and it is one of exactly three shapes postgresql-patterns admits jsonb for: a
 * configuration snapshot written once and read whole. Nothing queries it by predicate, aggregates
 * it, or joins on it — it is fetched entire and handed to a stylesheet renderer.
 *
 * ITS KEY SET IS CLOSED BY A CHECK, AND THE VOCABULARY IS NOT OURS TO INVENT. `primary`, `accent`
 * and `radius` are exactly the three keys apps/web/src/lib/theme.ts reads off a tenant-supplied
 * theme; every other custom property in that module's `WRITABLE_PROPERTIES` — the whole
 * `-foreground` and accent-ramp family — is DERIVED at render time and is explicitly never
 * form-settable, because contrast is derived and never chosen. A fourth key here would be a value
 * the renderer drops on the floor, stored forever, and rendered nowhere.
 *
 * WHAT THIS CONSTRAINT DOES NOT DO, AND MUST NOT BE MISTAKEN FOR: it constrains the key set and the
 * value TYPES, not the value GRAMMAR. Whether `primary` is a legal `oklch()` triple, whether
 * `radius` is one of the six values `packages/design-tokens` publishes, and — the one that is
 * easiest to miss — whether the supplied accent can be given readable text at all, are the
 * FormRequest's job on the write path. theme.ts flags that third rule explicitly for this service:
 * a `primary` whose lightness lands in the unreachable band (measured there as L in [0.538, 0.634]
 * for some chroma/hue combinations, bottoming out at 4.143:1) is REFUSED by the renderer, so a
 * Laravel grammar that accepts it tells the customer their colour was accepted and then quietly
 * serves the platform default. That rule belongs in the FormRequest, and it is named here so the
 * PR that writes one cannot claim nobody said.
 *
 * ═══ WHAT IS NOT ON THIS TABLE ════════════════════════════════════════════════════════════════
 *
 * The FALLBACK MODEL CHAIN is `bot_fallback_models`, a real table, and 2026_08_19_001700 records
 * the reasoning at length. The short form: postgresql-patterns admits jsonb for three shapes and an
 * ordered list of foreign keys is none of them — jsonb cannot carry a foreign key, so a jsonb chain
 * could name another tenant's model row and no constraint in the database would object, which is
 * precisely the guard `bots_model_same_org` exists to provide for the PRIMARY model.
 *
 * `bot_source_assignments` is Phase C's and is the one row in the schema that can span two
 * organizations (kb-tenancy-isolation NN2). The `bots (organization_id, id)` unique index below is
 * already the target its composite foreign key will need.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statuses = $this->quotedList(BotStatus::values());
        $accessModes = $this->quotedList(BotAccessMode::values());
        $answerModes = $this->quotedList(BotAnswerMode::values());
        $scales = $this->quotedList(EvidenceThresholdScale::values());

        $this->run(<<<SQL
            CREATE TABLE bots (
                id                              char(26) COLLATE "C" PRIMARY KEY,
                organization_id                 char(26) COLLATE "C" NOT NULL
                                                REFERENCES organizations (id) ON DELETE RESTRICT,

                -- Identity. `public_bot_id` is COLLATE "C" because it is only ever compared for
                -- exact equality; `name` and the prose fields are not, because they are read and
                -- sorted by humans.
                public_bot_id                   text COLLATE "C" NOT NULL,
                name                            text NOT NULL,
                slug                            text NOT NULL,
                description                     text,

                -- Voice. All nullable: a bot with no welcome message renders the platform default,
                -- which is a real state and not a missing one. Empty string and NULL would then be
                -- two spellings of it, so the CHECK below refuses the blank spelling outright
                -- rather than leaving two.
                welcome_message                 text,
                placeholder_text                text,
                system_instruction              text,
                answer_style_instruction        text,

                -- Lifecycle. text + CHECK, never a native PG enum: ALTER TYPE ... ADD VALUE cannot
                -- be rolled back, so a sixth status would be an irreversible migration.
                status                          text NOT NULL DEFAULT 'draft',
                access_mode                     text NOT NULL DEFAULT 'private',

                -- Model selection. Nullable, MATCH SIMPLE, composite-guarded — see the docblock.
                provider_connection_id          char(26) COLLATE "C",
                provider_model_id               char(26) COLLATE "C",

                -- Retrieval. docs/07 §12.7-12.12 as written.
                answer_mode                     text    NOT NULL DEFAULT 'strict',
                dense_top_k                     integer NOT NULL DEFAULT 20,
                sparse_top_k                    integer NOT NULL DEFAULT 20,
                rerank_candidates               integer NOT NULL DEFAULT 20,
                rerank_retain                   integer NOT NULL DEFAULT 6,

                -- NO DEFAULT, AND THAT IS THE POINT. See the docblock: 0.30 is a valid float on
                -- every scale, so a default would move only the refusal rate, only in aggregate,
                -- and fail no test.
                evidence_threshold              double precision,
                evidence_threshold_scale        text,

                -- Without this a trace cannot be replayed and is worthless to the §21.5 gate.
                retrieval_configuration_version integer NOT NULL DEFAULT 1,

                -- Publishing and appearance.
                allow_general_answers           boolean NOT NULL DEFAULT false,
                theme                           jsonb   NOT NULL DEFAULT '{}'::jsonb,

                -- Per-bot rate limits. NULL means "the platform default applies", which is a
                -- different fact from a configured limit that happens to equal it — an operator
                -- asking whether somebody set this has to be able to tell them apart.
                rate_limit_per_minute           integer,
                rate_limit_per_day              integer,

                -- Conversation retention. NULL means "keep until the organization's own retention
                -- policy says otherwise"; a number is a per-bot override in days.
                retention_days                  integer,

                -- Data collection and consent (docs/02 §8.3). The flag defaults FALSE for the same
                -- reason `access_mode` defaults `private`: the fail-closed direction is the one
                -- where a forgotten form field is a support ticket rather than a disclosure.
                collect_end_user_data           boolean NOT NULL DEFAULT false,
                consent_text                    text,

                created_at                      timestamptz NOT NULL DEFAULT now(),
                updated_at                      timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabularies, generated from the enums ─────────────────────────
                CONSTRAINT bots_status_check      CHECK (status      IN ({$statuses})),
                CONSTRAINT bots_access_mode_check CHECK (access_mode IN ({$accessModes})),
                CONSTRAINT bots_answer_mode_check CHECK (answer_mode IN ({$answerModes})),

                -- ── the public identifier's grammar ───────────────────────────────────────────
                -- Byte-for-byte the pattern apps/web already refuses to forward anything else for.
                -- The `{0,1}` spelling replaces the usual one-character optional quantifier
                -- throughout this file, and so does every comment: PDO rewrites a bare question
                -- mark into a positional placeholder while scanning the statement, and it does not
                -- reliably skip SQL comments while doing so. A statement that will not prepare is
                -- a cheap failure; one that prepares against the wrong parameter count is not.
                CONSTRAINT bots_public_bot_id_shape
                    CHECK (public_bot_id ~ '^[A-Za-z0-9_-]{1,64}\$'),

                -- ── blank is not a value ──────────────────────────────────────────────────────
                -- NULL means "not set" and is a real state everywhere below. An empty or
                -- whitespace-only string would be a SECOND spelling of it that every renderer
                -- would have to test for separately, and one of them would forget.
                CONSTRAINT bots_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT bots_slug_shape     CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9]){0,1}\$'),
                CONSTRAINT bots_text_not_blank CHECK (
                    (description              IS NULL OR btrim(description)              <> '')
                    AND (welcome_message          IS NULL OR btrim(welcome_message)          <> '')
                    AND (placeholder_text         IS NULL OR btrim(placeholder_text)         <> '')
                    AND (system_instruction       IS NULL OR btrim(system_instruction)       <> '')
                    AND (answer_style_instruction IS NULL OR btrim(answer_style_instruction) <> '')
                    AND (consent_text             IS NULL OR btrim(consent_text)             <> '')
                ),

                -- ── the retrieval bands of docs/07 §12.7-12.12 ────────────────────────────────
                -- A value outside these is not a tuning choice, it is a typo that changes what the
                -- regression gate is comparing. The depths themselves still move only through an
                -- evaluation run.
                CONSTRAINT bots_dense_top_k_range  CHECK (dense_top_k  BETWEEN 1 AND 200),
                CONSTRAINT bots_sparse_top_k_range CHECK (sparse_top_k BETWEEN 1 AND 200),
                CONSTRAINT bots_rerank_candidates_range CHECK (rerank_candidates BETWEEN 20 AND 30),
                CONSTRAINT bots_rerank_retain_range     CHECK (rerank_retain     BETWEEN 6  AND 10),
                -- Reranking can only reorder what retrieval handed it, so retaining more than
                -- were ever ranked is a configuration that cannot mean anything.
                --
                -- IT IS UNREACHABLE TODAY AND THAT IS STATED RATHER THAN LEFT TO BE DISCOVERED:
                -- the two bands above are disjoint (retain tops out at 10, candidates starts at
                -- 20), so no row this table accepts can violate it and no test can make it fire.
                -- It is kept because the bands are §12.7-12.12's STARTING POINTS, expected to move
                -- through evaluation runs — and the day one of them does, this is the constraint
                -- that stops the pair becoming nonsensical. A reviewer looking for its test will
                -- not find one; this paragraph is why.
                CONSTRAINT bots_rerank_retain_within_candidates
                    CHECK (rerank_retain <= rerank_candidates),
                CONSTRAINT bots_retrieval_configuration_version_positive
                    CHECK (retrieval_configuration_version >= 1),

                -- ── the evidence threshold and its scale ──────────────────────────────────────
                CONSTRAINT bots_evidence_threshold_paired CHECK (
                    num_nonnulls(evidence_threshold, evidence_threshold_scale) <> 1
                ),
                CONSTRAINT bots_evidence_threshold_scale_check CHECK (
                    evidence_threshold_scale IS NULL OR evidence_threshold_scale IN ({$scales})
                ),
                -- The one half of "0.30 is a valid float on every scale" a constraint can catch.
                CONSTRAINT bots_evidence_threshold_range CHECK (
                    evidence_threshold_scale IS NULL
                    OR evidence_threshold_scale = 'logit'
                    OR evidence_threshold BETWEEN 0 AND 1
                ),

                -- ── the theme's closed key set ────────────────────────────────────────────────
                -- `jsonb_exists(...)` and NOT the one-character jsonb existence operator: PDO
                -- rewrites that character into a positional placeholder while scanning the
                -- statement, and the prepare then fails on a parameter nobody bound. The function
                -- form is identical to the server and invisible to PDO.
                -- The `theme - 'k' - 'k' - 'k' = '{}'` form is a key-set SUBSET test written
                -- without a subquery, which a CHECK constraint may not contain.
                CONSTRAINT bots_theme_vocabulary CHECK (
                    jsonb_typeof(theme) = 'object'
                    AND theme - 'primary' - 'accent' - 'radius' = '{}'::jsonb
                    AND (NOT jsonb_exists(theme, 'primary') OR jsonb_typeof(theme -> 'primary') = 'string')
                    AND (NOT jsonb_exists(theme, 'accent')  OR jsonb_typeof(theme -> 'accent')  = 'string')
                    AND (NOT jsonb_exists(theme, 'radius')  OR jsonb_typeof(theme -> 'radius')  = 'string')
                ),

                -- ── limits and retention ──────────────────────────────────────────────────────
                -- A rate limit of zero is not a limit, it is a bot that answers nobody, and it is
                -- a plausible typo for "no limit" — which is spelled NULL.
                CONSTRAINT bots_rate_limits_positive CHECK (
                    (rate_limit_per_minute IS NULL OR rate_limit_per_minute >= 1)
                    AND (rate_limit_per_day IS NULL OR rate_limit_per_day >= 1)
                ),
                CONSTRAINT bots_retention_days_positive
                    CHECK (retention_days IS NULL OR retention_days >= 1),
                -- Collecting end-user data without telling anyone what for is not a state this
                -- table will hold. The consent text is what the disclosure renders.
                CONSTRAINT bots_consent_text_present_when_collecting
                    CHECK (collect_end_user_data = false OR consent_text IS NOT NULL),

                -- ── the two ownership guards ──────────────────────────────────────────────────
                -- MATCH SIMPLE (the default): unchecked when the nullable half is NULL, fully
                -- checked when it is not. See the docblock for why MATCH FULL would be wrong.
                CONSTRAINT bots_connection_same_org
                    FOREIGN KEY (organization_id, provider_connection_id)
                    REFERENCES provider_connections (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT bots_model_same_org
                    FOREIGN KEY (organization_id, provider_model_id)
                    REFERENCES provider_models (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // THE TARGET OF EVERY CHILD TABLE'S COMPOSITE FOREIGN KEY, and — like
        // `provider_connections_org_scoped_key` — vacuous as a uniqueness constraint, because `id`
        // is already the primary key. `bot_domains`, `bot_starter_questions`,
        // `bot_fallback_models` and, in Phase C, `bot_source_assignments` all point at it so that
        // "this child's bot belongs to this child's organization" is a database fact.
        $this->run('CREATE UNIQUE INDEX bots_org_scoped_key ON bots (organization_id, id)');

        // PER ORGANIZATION, NOT GLOBAL. The opposite of organizations.slug, which has no parent to
        // be unique within. This is the column every rate-limit and cache key is tempted to be
        // built from, and the reason none of them may be built from it alone.
        $this->run('CREATE UNIQUE INDEX bots_org_slug_unique ON bots (organization_id, slug)');

        // GLOBAL, because it is resolved with no organization in hand: a hosted-chat request, a
        // widget bootstrap and a theme stylesheet request all arrive carrying this token and
        // nothing else. That is exactly why it is not the ULID.
        $this->run('CREATE UNIQUE INDEX bots_public_bot_id_unique ON bots (public_bot_id)');

        // FK-CHILD INDEXES. PostgreSQL does not index the REFERENCING side of a foreign key, so
        // without these every `DELETE FROM provider_models` and every `DELETE FROM
        // provider_connections` sequentially scans `bots` to check the constraint — the same
        // failure postgresql-patterns records for `chunks.source_version_id`, one table over and
        // several orders of magnitude smaller. They lead with `organization_id` so they are also
        // the index the admin list uses when it filters bots by the model they run on.
        $this->run(
            'CREATE INDEX bots_org_connection ON bots (organization_id, provider_connection_id)',
        );
        $this->run(
            'CREATE INDEX bots_org_model ON bots (organization_id, provider_model_id)',
        );

        // The admin list's own index: "this organization's bots in state X". Tenant-leading,
        // always — never (status, organization_id), which would make the tenant predicate a filter
        // over every organization's rows in that state, and which PG 18's skip scan does not
        // rescue at organization scale.
        //
        // NO SEPARATE ORDERING INDEX, and that is deliberate rather than an omission: the default
        // list order is by `id`, and `bots_org_scoped_key (organization_id, id)` already yields one
        // organization's rows in ULID order — which is creation order, because a ULID's leading 48
        // bits are a millisecond timestamp and COLLATE "C" makes lexicographic order byte order.
        // An `(organization_id, created_at)` index would be a second copy of that ordering.
        $this->run('CREATE INDEX bots_org_status ON bots (organization_id, status)');
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS bots');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

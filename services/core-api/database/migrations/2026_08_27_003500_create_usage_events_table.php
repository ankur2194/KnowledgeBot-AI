<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Enums\UsageEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE QUOTA LEDGER (docs/11 §16, docs/12 §8.23). One row is one accumulating fact — tokens billed
 * for one provider attempt, bytes an upload added — attributed to an organization and, where there
 * is one, a bot.
 *
 * The table name is not chosen here: `config/queue.php:52` already names it ("exports (CSV / ZIP /
 * PDF over usage_events and conversations)"), `kb-tenancy-isolation` NN1 lists it among the tables
 * that hold `organization_id` directly, and `valkey-keyspaces` records "usage and quota truth
 * (`usage_events`)" as deliberately NOT in Valkey.
 *
 * Written as SQL through `Schema::getConnection()->statement()` like every migration here
 * (2026_08_07_000100 records the reasoning). Range partitioning, a composite primary key on a
 * partitioned parent, and `PARTITION OF` have no Blueprint expression at all.
 *
 * ═══ THE PARTITIONING DECISION, AND THE CRITERION IT TURNS ON ════════════════════════════════
 *
 * THIS TABLE IS `PARTITION BY RANGE (occurred_at)`, MONTHLY, WITH `PRIMARY KEY (id, occurred_at)`.
 *
 * THE CRITERION IS WHAT HOLDS A FOREIGN KEY *INTO* THE TABLE — not how big it gets, and not what
 * `postgresql-patterns`' Definition of done happens to list. That is the criterion the 2026-08-27
 * ruling in 2026_08_26_002800_create_conversations_table.php turns on, and it is worth restating
 * because the same skill line names `messages`, `provider_calls` AND `usage_events`, and the ruling
 * went one way for the first two and this migration goes the other way for the third:
 *
 *   A partitioned table's UNIQUE constraints must contain every partition-key column. So the
 *   primary key becomes `(id, created_at)` — and every table referencing it must then denormalize
 *   that timestamp and carry a COMPOSITE foreign key, or lose the foreign key entirely.
 *
 *   `messages` has FOUR references (`citations`, `retrieval_traces`, `feedback`, and
 *   `provider_calls.message_id`), so partitioning it trades four referential-integrity constraints
 *   for a retention mechanism the parent `conversations` row already provides by another route.
 *   `docs/22` § Q3 is this repository's own record of what a quietly weakened composite key costs.
 *
 *   `audit_logs` is the opposite shape (ADR-041): append-only, no children, nothing referencing it,
 *   so `PRIMARY KEY (id, created_at)` costs it nothing and `DETACH PARTITION CONCURRENTLY` becomes
 *   an O(1) retention drop that takes no ACCESS EXCLUSIVE lock on the parent.
 *
 * MEASURED AGAINST THIS TABLE, IT IS THE `audit_logs` SHAPE:
 *
 *   * NOTHING REFERENCES IT AND NOTHING WILL. A usage event is a leaf. It is read by aggregates
 *     (`SUM(quantity) … GROUP BY`) and by exports, and neither of those is a foreign key. The one
 *     thing that could have been a child — a per-period rollup row — is deliberately not built (see
 *     "WHAT IS NOT IN HERE"), and if it ever is, it references the ORGANIZATION and the period, not
 *     an individual event.
 *   * IT IS APPEND-ONLY. Every column is either an attribution the server resolves, a measurement
 *     the server takes, or provenance. Nothing updates a row: a correction is a new row of the
 *     opposite-direction type, which is why `storage.bytes.removed` exists as its own case rather
 *     than as a negative quantity.
 *   * ITS RETENTION IS A WHOLE-MONTH DROP. That is the only retention shape this table can have —
 *     a partial delete of a billing period is not a retention policy, it is a corrupted invoice.
 *
 * SO IT IS PARTITIONED, AND IT FOLLOWS THE `audit_logs` PRECEDENT COMPLETELY: no DEFAULT partition,
 * two months of runway created here, `kb:create-usage-partitions` daily to keep three months ahead,
 * and `kb:prune-usage-partitions` shipped and DELIBERATELY NOT SCHEDULED because dropping a month of
 * billing history is a retention decision nobody has made.
 *
 * ── HOW THIS DECISION BECOMES WRONG ─────────────────────────────────────────────────────────
 *
 * Stated, because a ruling with no stated expiry is the shape `docs/22` § Q5 and § Q6 are both
 * about. Exactly one thing reverses it: SOMETHING ACQUIRING A FOREIGN KEY INTO THIS TABLE. The
 * plausible candidate is a per-event dispute or adjustment record ("this line was credited"), and
 * the moment such a table wants `usage_event_id REFERENCES usage_events`, it cannot have it — it
 * would need `(usage_event_id, usage_event_occurred_at)` and a composite key, which is exactly the
 * trade the `messages` ruling refused. Whoever proposes that table owns re-opening this, and the
 * migration that un-partitions is a full rewrite budgeted as one.
 *
 * Note what does NOT reverse it: row count. Partitioning here is not a performance bet — the
 * org-leading indexes below are what make the aggregates fast, and PG 18's skip scan does not
 * rescue a non-tenant-leading index at organization scale. It is a RETENTION MECHANISM, and the
 * argument for it does not weaken if the table stays small.
 *
 * ═══ THE PARTITION KEY IS `occurred_at`, NOT `created_at`, AND THE TWO ARE DIFFERENT FACTS ════
 *
 * `occurred_at` is WHEN THE USAGE HAPPENED — the provider attempt's own timestamp, the moment the
 * upload was admitted. `created_at` is when this row was written, which for an event derived by
 * `kb:rollup-usage` from a `provider_calls` row whose finalizer died can be an hour later.
 *
 * Every aggregate in this application groups by `occurred_at`, because that is what a billing
 * period means, so partitioning on `created_at` would put the partition key on an axis no query
 * filters and PARTITION PRUNING WOULD NEVER FIRE. It would also make a late-arriving event land in
 * the wrong month's partition, so a dropped partition would take a live period's rows with it.
 *
 * ═══ `occurred_at` HAS NO DEFAULT, AND THAT IS A DEPARTURE FROM `audit_logs` WITH A REASON ════
 *
 * `audit_logs.created_at` defaults to `now()` so that a row inserted by any other means still lands
 * in a partition. Here the same default would be a footgun, because `occurred_at` is HALF OF THE
 * DEDUPE IDENTITY: `usage_events_dedupe` must contain the partition key (a partitioned table's
 * unique indexes must), so two rows with the same `(organization_id, event_type, dedupe_key)` and
 * DIFFERENT `occurred_at` are both accepted.
 *
 * The rule that makes the dedupe index work is therefore: **`occurred_at` IS DERIVED FROM THE
 * SOURCE ROW AND NEVER FROM THE CLOCK.** A re-derivation of the same provider call must compute the
 * same instant, or `kb:rollup-usage` double-counts every hour, forever, in a direction that reads
 * as ordinary growth. With a `DEFAULT now()` a writer that simply forgot the column would do
 * exactly that, silently. Without one it fails with a NOT NULL violation on the first insert.
 * A loud failure at the first write beats a plausible wrong number in a billing report.
 *
 * ═══ WHAT IS NOT IN HERE ═════════════════════════════════════════════════════════════════════
 *
 * NO ROLLED-UP AGGREGATE ROWS, and no `aggregates` table beside this one. §8.23's tiles are
 * aggregates over `conversations`, `messages`, `provider_calls`, `retrieval_traces`, `feedback` and
 * `knowledge_sources` — those tables are the truth for each of those facts, and a mirrored copy
 * would be a second number for one fact with no statement of which is right. `kb:rollup-usage`
 * DERIVES ledger rows for usage that was never recorded (the aborted stream, the finalizer that
 * died) and RECONCILES the Valkey counters against this table; it does not build a warehouse.
 *
 * NO `estimated_cost`. Money lives on `provider_calls`, with its currency beside it, and the
 * argument is that migration's: summing two currencies is a silent wrong answer. A cost column here
 * would be a second copy of a number computed from `provider_models` pricing that can change, so
 * the ledger would disagree with the invoice the moment a price was corrected. Cost is computed at
 * READ time from tokens times the price in force, in one place.
 *
 * NO `updated_at`. Append-only; see above.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = $this->quotedList(UsageEventType::values());
        $providerAttributed = $this->quotedList(UsageEventType::providerAttributed());
        $providers = $this->quotedList(Provider::values());

        $this->run(<<<SQL
            CREATE TABLE usage_events (
                id                   char(26) COLLATE "C" NOT NULL,

                -- THE PARTITION KEY, and FIRST after the id on purpose: it is the axis every
                -- aggregate filters on and the axis retention drops on. NO DEFAULT — see the
                -- docblock: it is half of the dedupe identity, so a writer that forgets it must
                -- fail rather than land a duplicate under a different instant.
                occurred_at          timestamptz NOT NULL,

                -- NN1: this table holds the organization DIRECTLY. RESTRICT, like every other
                -- reference to `organizations` in this schema — org deletion is a purge worker in a
                -- defined order, never one statement taking ACCESS EXCLUSIVE on forty tables.
                organization_id      char(26) COLLATE "C" NOT NULL
                                     REFERENCES organizations (id) ON DELETE RESTRICT,

                -- NULLABLE, because not all metered usage belongs to a bot: an upload consumes the
                -- ORGANIZATION's storage and is attributed to a source, not to whichever bot may
                -- later be assigned it. Composite-guarded below when present.
                bot_id               char(26) COLLATE "C",

                event_type           text NOT NULL,

                -- `bigint`, not `integer`. A month of a busy organization's input tokens passes
                -- 2^31 without being remarkable, and an overflow here is a wrong bill rather than
                -- an error: PostgreSQL raises on integer overflow, so the symptom is a 500 on a
                -- ledger write, i.e. usage that stops being recorded at all.
                quantity             bigint NOT NULL,

                -- Both NULL for a storage event and both present for a token event; the CHECK below
                -- is generated from UsageEventType::providerAttributed() so the two cannot drift.
                -- `provider` is the closed vendor vocabulary; `model` is the vendor's own model id,
                -- verbatim, and is deliberately NOT a foreign key to `provider_models`: a catalogue
                -- row may be removed and a billing record must still name what answered.
                provider             text,
                model                text,

                -- ── IDEMPOTENCY ──────────────────────────────────────────────────────────────
                -- The stable identity of the WORK this row meters, chosen by the writer: the
                -- provider_calls ULID for a token event, the source_items ULID for a storage one.
                -- It is what makes kb:rollup-usage safe to run twice, which it will be, because it
                -- runs on a schedule. COLLATE "C": compared for exact equality and nothing else.
                dedupe_key           text COLLATE "C" NOT NULL,

                -- jsonb, and one of the three shapes postgresql-patterns admits it for: a snapshot
                -- written once and read whole. It carries the PROVENANCE of the row — which path
                -- recorded it, which source row it was derived from, which rollup run produced it —
                -- so that a number in a billing report can be traced back to the thing that caused
                -- it. Nothing queries it by predicate; the columns above are what queries.
                aggregation_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,

                -- WHEN THE ROW WAS WRITTEN, which is a different fact from occurred_at. A row whose
                -- created_at is far after its occurred_at was derived by the rollup rather than
                -- recorded live, and that gap is the only evidence a finalizer died.
                created_at           timestamptz NOT NULL DEFAULT now(),

                -- Every partitioned table's key includes the partition key. See the docblock.
                PRIMARY KEY (id, occurred_at),

                -- ── the closed vocabularies, generated from the enums ────────────────────────
                CONSTRAINT usage_events_type_check CHECK (event_type IN ({$types})),
                CONSTRAINT usage_events_provider_check
                    CHECK (provider IS NULL OR provider IN ({$providers})),

                -- ── attribution is paired with the type, in BOTH directions ─────────────────
                -- A token event with no provider is a cost nobody can price. A storage event WITH
                -- one attributes our own bytes to a vendor account, which is a line in a
                -- reconciliation that will never match anything.
                CONSTRAINT usage_events_provider_attribution_paired CHECK (
                    (event_type NOT IN ({$providerAttributed})
                        OR (provider IS NOT NULL AND model IS NOT NULL))
                    AND (event_type IN ({$providerAttributed})
                        OR (provider IS NULL AND model IS NULL))
                ),

                -- ── quantities ──────────────────────────────────────────────────────────────
                -- NON-NEGATIVE, AND THAT IS WHY `storage.bytes.removed` IS ITS OWN TYPE. A ledger
                -- admitting negative quantities admits a row that silently cancels another, and no
                -- constraint can tell that from a correction.
                CONSTRAINT usage_events_quantity_nonnegative CHECK (quantity >= 0),

                -- ── shape ───────────────────────────────────────────────────────────────────
                CONSTRAINT usage_events_dedupe_key_not_blank CHECK (btrim(dedupe_key) <> ''),
                CONSTRAINT usage_events_model_not_blank
                    CHECK (model IS NULL OR btrim(model) <> ''),
                -- `json_encode([])` is `[]`, a JSON ARRAY, and one array-shaped row makes every
                -- `aggregation_metadata->>'...'` read silently return nothing for it.
                -- App\Support\Casts\JsonObjectCast is the writer-side half.
                CONSTRAINT usage_events_aggregation_metadata_is_object
                    CHECK (jsonb_typeof(aggregation_metadata) = 'object'),

                -- ── the ownership guard ─────────────────────────────────────────────────────
                -- COMPOSITE, against `bots_org_scoped_key`. A simple key on `bot_id` would let one
                -- tenant's usage be attributed to another tenant's bot, and every per-bot breakdown
                -- downstream would AGREE with it — because the row would have told them whose bot
                -- spent the money. MATCH SIMPLE (the default) is what makes it correct on a storage
                -- row: a multi-column foreign key is not checked at all when any referencing column
                -- is NULL.
                CONSTRAINT usage_events_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        // ── INDEXES, ON THE PARENT ───────────────────────────────────────────────────────────
        //
        // Created on the PARENT, which is what makes them exist on every partition that exists now
        // and on every one kb:create-usage-partitions makes next quarter. An index created
        // per-partition instead is one somebody forgets, on the partition that is currently hot.
        //
        // ORG-LEADING, ALWAYS (postgresql-patterns). Never (occurred_at, organization_id): a
        // time-leading index makes the tenant predicate a filter over every organization's usage in
        // the window, which on this table is "every token the platform billed this month".

        // THE QUOTA QUERY. `SUM(quantity) WHERE organization_id = ? AND event_type IN (...) AND
        // occurred_at >= period_start` — one organization, one metric, one period. Partition pruning
        // narrows it to the months the period spans; this index narrows it to the rows.
        $this->run(<<<'SQL'
            CREATE INDEX usage_events_org_type_occurred
                ON usage_events (organization_id, event_type, occurred_at DESC)
        SQL);

        // The per-bot breakdown, and the FK-child index for `usage_events_bot_same_org` — so a bot
        // delete's referential check is an index scan rather than a sequential scan of every usage
        // event in the platform. Partial: storage rows have no bot and indexing their NULLs buys
        // nothing.
        $this->run(<<<'SQL'
            CREATE INDEX usage_events_org_bot_occurred
                ON usage_events (organization_id, bot_id, occurred_at DESC)
                WHERE bot_id IS NOT NULL
        SQL);

        // §8.23's "tokens and estimated cost by model". Partial for the same reason: a storage row
        // has no model.
        $this->run(<<<'SQL'
            CREATE INDEX usage_events_org_model_occurred
                ON usage_events (organization_id, model, occurred_at DESC)
                WHERE model IS NOT NULL
        SQL);

        // ── THE DEDUPE INDEX, AND WHAT IT CAN AND CANNOT PROMISE ─────────────────────────────
        //
        // `occurred_at` IS IN IT BECAUSE PostgreSQL REQUIRES IT — a partitioned table's unique
        // index must contain every partition-key column — and that is a real weakening, stated
        // rather than hidden: two rows with the same (organization, type, dedupe_key) and DIFFERENT
        // occurred_at are both accepted, one per partition.
        //
        // What closes the gap is not this index, it is the rule in the docblock: `occurred_at` is
        // DERIVED FROM THE SOURCE ROW, never from the clock, so a re-derivation of the same work
        // computes the same instant, lands in the same partition, and collides here.
        // App\Services\Usage\UsageRecorder is the only writer and takes it as a required argument.
        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX usage_events_dedupe
                ON usage_events (organization_id, event_type, dedupe_key, occurred_at)
        SQL);

        // THE TABLE IS WRITABLE THE MOMENT IT EXISTS. Without a partition covering the first write,
        // the INSERT fails with 23514 "no partition of relation \"usage_events\" found for row" —
        // which on this table means usage silently stops being metered and every quota reads low.
        // Current month plus next, so a deploy at 23:59 on the last day of a month is a non-event;
        // kb:create-usage-partitions keeps the runway ahead of that.
        //
        // THERE IS DELIBERATELY NO DEFAULT PARTITION, for the reason 2026_08_13_001000 gives about
        // `audit_logs`: with one present, every later `CREATE TABLE … PARTITION OF` must scan the
        // default to prove no row belongs in the new range, holding ACCESS EXCLUSIVE on it while the
        // parent is being written. The runway plus a loud failure is the better trade.
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        $this->createMonthPartition($month);
        $this->createMonthPartition($month->addMonth());
    }

    public function down(): void
    {
        // Dropping a partitioned table drops its partitions with it — no CASCADE needed, and no
        // enumeration that could miss the one created last night.
        $this->run('DROP TABLE IF EXISTS usage_events');
    }

    /**
     * One monthly partition, `usage_events_YYYY_MM`, half-open [start, start + 1 month).
     *
     * THE NAME IS THE CONTRACT. `kb:prune-usage-partitions` parses the month back out of it and
     * refuses to touch a relation whose name does not match, so a hand-made partition under another
     * name is never dropped by accident — and never pruned either. The pattern lives in
     * App\Repositories\Eloquent\EloquentUsageEventPartitionRepository; keep the two spellings in
     * step.
     *
     * `IF NOT EXISTS` so this is idempotent against a partition the command already made: a
     * migration re-run on a database that ran the command first must not fail.
     */
    private function createMonthPartition(CarbonImmutable $month): void
    {
        $name = 'usage_events_'.$month->format('Y_m');
        $from = $month->format('Y-m-d H:i:sP');
        $to = $month->addMonth()->format('Y-m-d H:i:sP');

        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" PARTITION OF usage_events
                FOR VALUES FROM ('{$from}') TO ('{$to}')
        SQL);
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

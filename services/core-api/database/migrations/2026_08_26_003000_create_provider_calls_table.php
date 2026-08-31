<?php

declare(strict_types=1);

use App\Enums\ProviderCallStatus;
use App\Support\Kb\ErrorTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt against one provider (docs/11 §16.6). The billing record, the latency record, and the
 * only place a failure is attributed to a vendor rather than to the platform.
 *
 * ═══ ONE ROW IS ONE ATTEMPT, NOT ONE TURN ═══════════════════════════════════════════════════
 *
 * A turn that failed on the primary model and succeeded on a fallback writes TWO rows. That is the
 * reason `ProviderCallStatus` has no `fell_back` value and the reason `message_id` is not unique
 * here: a single row would have to choose ONE connection, one token count and one cost for two
 * different vendors, and the failed attempt's tokens are real money that would vanish from §8.23's
 * "estimated provider cost". `fallback_metadata` on the second row records why it was reached.
 *
 * ═══ THE FOREIGN KEY ACTIONS, AND THE ONE ASYMMETRY THAT IS DELIBERATE ══════════════════════
 *
 *   organization_id                     -> organizations       RESTRICT
 *   (organization_id, bot_id)           -> bots                RESTRICT
 *   (organization_id, provider_connection_id) -> provider_connections RESTRICT
 *   (organization_id, model_id)         -> provider_models     RESTRICT
 *   conversation_id                     -> conversations       SET NULL
 *   message_id                          -> messages            SET NULL
 *
 * THE FOUR `RESTRICT`s ARE THE SAME RULE THIS SCHEMA APPLIES EVERYWHERE HISTORY POINTS AT
 * CONFIGURATION: a row that appears in history makes its parent undeletable, and the parent is
 * withdrawn rather than deleted. For the connection it is this step's brief, stated settled — "a
 * `provider_connection` must not be deletable out from under a `provider_calls` row that records
 * history" — and the reason is that the connection IS the credential: "which vendor account was
 * billed for this answer" is not derivable from anything else on the row, and a customer disputing
 * an invoice is answered with that fact or is not answered.
 *
 * `model_id` IS RESTRICT FOR THE SAME REASON AND IT IS THE LARGER COMMITMENT, so it is named:
 * `provider_models` rows are hard-deleted today by `ProviderModelService::delete()`, and once this
 * table has rows that path starts raising SQLSTATE 23503 for a catalog row that has ever answered a
 * question. THAT IS AN OBLIGATION ON D3, NOT A DEFECT TODAY — nothing writes this table yet — and
 * it is written here rather than left to be discovered as a 500. The repair is the one this schema
 * already uses twice: the delete becomes a refusal with a sentence, and `provider_models.enabled =
 * false` is what an operator does instead. The rejected alternative was `ON DELETE SET NULL` plus a
 * denormalized model string, which keeps the catalog cleanable and makes the cost report cite a
 * model identifier no table resolves — history that cannot be checked against anything.
 *
 * THE TWO `SET NULL`s ARE THE OPPOSITE DIRECTION AND ARE NOT AN INCONSISTENCY. Retention removes
 * CONTENT, not COST. When the sweeper deletes a conversation it cascades to that thread's messages,
 * and this row must survive the cascade holding `organization_id`, `bot_id`, its tokens and its
 * price — otherwise honouring a retention policy silently rewrites last quarter's spend. So both
 * links are nullable and both null out, and every aggregate in §8.23 that must not move groups by
 * `organization_id` and `bot_id`, which never do.
 *
 * ═══ `error_class` IS CHECKED AGAINST THE TAXONOMY, GENERATED FROM IT ═══════════════════════
 *
 * The 18 values come from `App\Support\Kb\ErrorTaxonomy::RETRYABLE`, which
 * `tests/Contract/ErrorTaxonomyParityTest.php` pins against `services/ai-service/app/core/errors.py`
 * class for class. So this constraint cannot drift from either plane: a 19th class added on the
 * Python side is a red parity test, and a value this column would accept that neither plane knows
 * is unrepresentable. Writing the list out by hand here is how a dashboard ends up with a bucket
 * called `provider_error` that nothing in the taxonomy produces.
 *
 * ═══ MONEY IS `numeric` AND CARRIES ITS CURRENCY ════════════════════════════════════════════
 *
 * `numeric(16, 8)` and never `double precision`, for the reason the pricing migration
 * (2026_08_19_001200) writes out at length: a price multiplied by a token count in the millions
 * accumulates a binary rounding error, and the symptom is an invoice line nobody can reproduce.
 * Eight fractional digits because a single call costs fractions of a cent.
 *
 * `estimated_cost_currency` is here for the reason `provider_models.price_currency` is: SUMMING TWO
 * CURRENCIES IS A SILENT WRONG ANSWER. An organization with a USD-priced connection and a
 * EUR-priced one produces a total that is neither, and nothing raises. Both columns are nullable
 * together — an unpriced catalog row yields a call with no cost estimate, which is a real state and
 * is different from a call that cost zero.
 *
 * ═══ TOKENS: FIVE COUNTERS, AND `input_tokens` MEANS DIFFERENT THINGS TO DIFFERENT VENDORS ══
 *
 * The five are §16.6's "input, output, and other token categories" spelled out. The cache pair and
 * `reasoning_tokens` exist because they are BILLED DIFFERENTLY — a cache write costs more than a
 * plain input token and a cache read costs far less — so folding them into `input_tokens` makes the
 * cost estimate wrong in a direction that depends on the provider.
 *
 * THE NORMALIZATION IS THE ADAPTER'S JOB AND IT IS NOT OPTIONAL: kb-anthropic records that
 * Anthropic's `input_tokens` EXCLUDES cached tokens while every other provider here includes them.
 * This column holds the normalized value — total input, cache included — and the two cache columns
 * are a breakdown of it, never an addition to it. A row where the breakdown exceeds the total is
 * refused by `provider_calls_cache_within_input`, which is the only half of that normalization a
 * constraint can check.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statuses = $this->quotedList(ProviderCallStatus::values());
        $errorClasses = $this->quotedList(array_keys(ErrorTaxonomy::RETRYABLE));
        $failed = ProviderCallStatus::Failed->value;
        $succeeded = ProviderCallStatus::Succeeded->value;

        $this->run(<<<SQL
            CREATE TABLE provider_calls (
                id                      char(26) COLLATE "C" PRIMARY KEY,

                -- ── tenancy and attribution: the four columns that survive every cascade ─────
                organization_id         char(26) COLLATE "C" NOT NULL
                                        REFERENCES organizations (id) ON DELETE RESTRICT,
                bot_id                  char(26) COLLATE "C" NOT NULL,

                -- NULLABLE, AND SET NULL. Retention removes content, not cost. See the docblock.
                conversation_id         char(26) COLLATE "C"
                                        REFERENCES conversations (id) ON DELETE SET NULL,
                message_id              char(26) COLLATE "C"
                                        REFERENCES messages (id) ON DELETE SET NULL,

                -- ── which credential was billed, and which model answered ────────────────────
                -- Both NOT NULL and both RESTRICT: a call that cannot name the account it billed is
                -- not a billing record.
                provider_connection_id  char(26) COLLATE "C" NOT NULL,
                model_id                char(26) COLLATE "C" NOT NULL,

                -- The vendor's own identifier for this request, echoed from their response headers
                -- or body. It is what a support ticket to the provider quotes, and it is the ONLY
                -- field on this row that lets somebody else reproduce our failure. COLLATE "C":
                -- compared for exact equality and nothing else.
                provider_request_id     text COLLATE "C",

                status                  text NOT NULL DEFAULT 'pending',

                -- ── token accounting ────────────────────────────────────────────────────────
                -- NULL means "the provider told us nothing", which is a real outcome (a stream that
                -- died before its usage frame) and is different from zero.
                input_tokens            integer,
                cache_read_tokens       integer,
                cache_write_tokens      integer,
                output_tokens           integer,
                reasoning_tokens        integer,

                -- ── money ───────────────────────────────────────────────────────────────────
                estimated_cost          numeric(16, 8),
                estimated_cost_currency text,

                -- ── latency ─────────────────────────────────────────────────────────────────
                -- NULL on a non-streaming call and on one that never produced a token. §8.23 lists
                -- first-token latency as its own metric because it is the one a user feels.
                first_token_latency_ms  integer,
                total_latency_ms        integer,

                -- ── why this attempt happened at all ────────────────────────────────────────
                -- jsonb, and one of the three shapes postgresql-patterns admits it for: a snapshot
                -- written once and read whole. Nothing queries it by predicate. `{}` on a primary
                -- attempt; on a fallback it carries the attempt ordinal, the model that was tried
                -- first, and the error class that made it ineligible.
                fallback_metadata       jsonb NOT NULL DEFAULT '{}'::jsonb,

                error_class             text,

                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabularies ─────────────────────────────────────────────────
                CONSTRAINT provider_calls_status_check CHECK (status IN ({$statuses})),
                -- Generated from ErrorTaxonomy::RETRYABLE, which the contract suite pins against
                -- the data plane's errors.py. See the docblock.
                CONSTRAINT provider_calls_error_class_check
                    CHECK (error_class IS NULL OR error_class IN ({$errorClasses})),
                -- A failure with no class is an outage nobody can categorise, and a SUCCESS with
                -- one is a contradiction §8.23 would count in both buckets. Written as two
                -- one-directional implications rather than an equivalence, because `cancelled` and
                -- `pending` are deliberately free: a cancelled call legitimately carries
                -- `user_cancellation` — a real member of the taxonomy — and equally legitimately
                -- carries nothing, since the caller going away is not a fault to attribute. An
                -- equivalence would force one of those two spellings and refuse the other, and
                -- MessageStatus already records why counting a closed laptop lid as a provider
                -- error makes the error-rate dashboard unusable.
                --
                -- The two statuses are interpolated from the enum, not typed as literals: a value
                -- renamed in PHP and left spelled out here is a constraint that silently stops
                -- applying to the state it was written for.
                CONSTRAINT provider_calls_error_class_paired_with_status CHECK (
                    (status <> '{$failed}'    OR error_class IS NOT NULL)
                    AND (status <> '{$succeeded}' OR error_class IS NULL)
                ),

                -- ── token accounting ────────────────────────────────────────────────────────
                CONSTRAINT provider_calls_tokens_nonnegative CHECK (
                    (input_tokens        IS NULL OR input_tokens        >= 0)
                    AND (cache_read_tokens   IS NULL OR cache_read_tokens   >= 0)
                    AND (cache_write_tokens  IS NULL OR cache_write_tokens  >= 0)
                    AND (output_tokens       IS NULL OR output_tokens      >= 0)
                    AND (reasoning_tokens    IS NULL OR reasoning_tokens   >= 0)
                ),
                -- THE ONE HALF OF THE ANTHROPIC NORMALIZATION A CONSTRAINT CAN CHECK. `input_tokens`
                -- is the TOTAL input, cache included; the two cache columns are a breakdown of it.
                -- A row whose breakdown exceeds its total means the adapter added where it should
                -- have partitioned, and the symptom is a cost estimate that is wrong for exactly one
                -- vendor.
                CONSTRAINT provider_calls_cache_within_input CHECK (
                    input_tokens IS NULL
                    OR coalesce(cache_read_tokens, 0) + coalesce(cache_write_tokens, 0)
                       <= input_tokens
                ),

                -- ── money ───────────────────────────────────────────────────────────────────
                -- A cost with no currency is a number nobody may add to another number.
                CONSTRAINT provider_calls_cost_needs_currency CHECK (
                    estimated_cost IS NULL OR estimated_cost_currency IS NOT NULL
                ),
                CONSTRAINT provider_calls_cost_nonnegative CHECK (
                    estimated_cost IS NULL OR estimated_cost >= 0
                ),
                CONSTRAINT provider_calls_cost_currency_iso CHECK (
                    estimated_cost_currency IS NULL OR estimated_cost_currency ~ '^[A-Z]{3}\$'
                ),

                -- ── latency ─────────────────────────────────────────────────────────────────
                -- A first token cannot arrive after the call finished. When both are present the
                -- inequality is the only check that catches two clocks being read in the wrong
                -- order, which renders as a negative "time to answer" in the dashboard.
                CONSTRAINT provider_calls_latency_nonnegative CHECK (
                    (first_token_latency_ms IS NULL OR first_token_latency_ms >= 0)
                    AND (total_latency_ms   IS NULL OR total_latency_ms       >= 0)
                ),
                CONSTRAINT provider_calls_first_token_within_total CHECK (
                    first_token_latency_ms IS NULL
                    OR total_latency_ms IS NULL
                    OR first_token_latency_ms <= total_latency_ms
                ),

                -- ── jsonb is an object, including when it is empty ───────────────────────────
                -- `json_encode([])` is `[]`, a JSON ARRAY, and one array-shaped row makes every
                -- `fallback_metadata->>'...'` query silently return nothing for it.
                -- App\Support\Casts\JsonObjectCast is the writer-side half.
                CONSTRAINT provider_calls_fallback_metadata_is_object
                    CHECK (jsonb_typeof(fallback_metadata) = 'object'),

                -- ── the ownership guards ────────────────────────────────────────────────────
                -- Three composite keys, each against its parent's `(organization_id, id)` unique
                -- index. Without them a call could name another tenant's bot, connection or model —
                -- which is this tenant's questions billed to that tenant's provider account and
                -- readable in their vendor dashboard, with every downstream layer agreeing.
                CONSTRAINT provider_calls_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT provider_calls_connection_same_org
                    FOREIGN KEY (organization_id, provider_connection_id)
                    REFERENCES provider_connections (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT provider_calls_model_same_org
                    FOREIGN KEY (organization_id, model_id)
                    REFERENCES provider_models (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // THE OTHER HALF OF THE CYCLE. `messages.provider_call_id` could not carry its key when that
        // table was created, because this one did not exist. SET NULL rather than RESTRICT: a
        // provider call is only ever removed with its organization, and a message that outlived its
        // call is still a message.
        $this->run(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_provider_call_fk
                FOREIGN KEY (provider_call_id)
                REFERENCES provider_calls (id) ON DELETE SET NULL
        SQL);

        // ── INDEXES ──────────────────────────────────────────────────────────────────────────
        // ORG-LEADING, ALWAYS. Every aggregate in §8.23 — spend, error rate, fallback rate, latency
        // — is "this organization's calls in this window", and a time-leading index would make the
        // tenant predicate a filter over every organization's calls in it.

        // The cost and latency report, and the FK-child index for `provider_calls_bot_same_org`.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_org_bot_created
                ON provider_calls (organization_id, bot_id, created_at DESC)
        SQL);

        // "Token usage by provider and model" (§8.23), and the FK-child index for
        // `provider_calls_model_same_org`.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_org_model_created
                ON provider_calls (organization_id, model_id, created_at DESC)
        SQL);

        // FK-child index for `provider_calls_connection_same_org`. It is also the query behind "is
        // this connection still in use" — the question `ProviderConnectionService::delete()` will
        // have to ask once this table has rows, and the reason that path becomes a refusal.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_org_connection_created
                ON provider_calls (organization_id, provider_connection_id, created_at DESC)
        SQL);

        // "Provider error rate" and the fallback rate. Partial on the failures, which are the small
        // minority of rows and the only ones either metric counts.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_org_failures
                ON provider_calls (organization_id, error_class, created_at DESC)
                WHERE error_class IS NOT NULL
        SQL);

        // The diagnostics panel: every attempt behind one turn, in the order they were made. Also
        // the FK-child index for `message_id`, so the cascade that nulls this column when a message
        // goes is an index scan.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_message_created
                ON provider_calls (message_id, created_at)
                WHERE message_id IS NOT NULL
        SQL);

        // FK-child index for `conversation_id`. Same reason, one level up.
        $this->run(<<<'SQL'
            CREATE INDEX provider_calls_conversation_created
                ON provider_calls (conversation_id, created_at)
                WHERE conversation_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // The constraint on `messages` goes FIRST and explicitly. Dropping `provider_calls` would
        // take it anyway, but a `down()` that relies on that is one that stops being reversible the
        // day somebody reorders the drops.
        $this->run('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_provider_call_fk');
        $this->run('DROP TABLE IF EXISTS provider_calls');
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
        Schema::getConnection()->statement($sql);
    }
};

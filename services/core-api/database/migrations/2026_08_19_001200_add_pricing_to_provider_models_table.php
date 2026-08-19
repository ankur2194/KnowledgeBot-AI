<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING METADATA ON `provider_models` — the column docs/11 §16.2 lists and no migration created.
 *
 * docs/11 §16.2 ends the `provider_models` field list with "Pricing metadata"; docs/02 §8.4 says
 * what it is for and, more usefully, what it is NOT for: *"Pricing metadata used only for
 * estimated reporting."* Every choice below follows from that clause.
 *
 * ── WHY THREE COLUMNS AND NOT ONE JSONB BLOB ───────────────────────────────────────────────────
 *
 * postgresql-patterns admits jsonb for exactly three shapes, and this is none of them: a price is
 * compared, summed and multiplied, so it is a real column. `capability_flags` is jsonb on this same
 * table because the KEY SET is the provider's; a price has one key set, ours, and it is two numbers
 * and a currency. `metadata->>'input_price'` would also be a text comparison wearing a number's
 * clothes — `'9' > '10'` is true in that spelling.
 *
 * ── WHY `numeric` AND NEVER `double precision` ─────────────────────────────────────────────────
 *
 * A price is a decimal quantity that gets multiplied by a token count in the millions. In binary
 * floating point `0.15` is not 0.15, and an estimate summed over a month's usage drifts by an
 * amount nobody can reproduce or explain to a tenant. `numeric(14, 6)` is exact: six fractional
 * digits, which is enough for the cheapest published per-million-token prices (fractions of a
 * cent), and eight integral digits.
 *
 * THE COLUMN'S CEILING IS DELIBERATELY HIGHER THAN THE FORM'S, AND THE GAP IS THE POINT. Both
 * FormRequests bound a price at 1,000,000 — already absurd for a per-million-token list price — and
 * `numeric(14, 6)` holds up to 99,999,999.999999. If the two were flush (`numeric(12, 6)` stops at
 * 999,999.999999, which is BELOW the form's own bound) the boundary value would pass validation and
 * then raise SQLSTATE 22003 from the driver, rendered as a 500: a bug report about the server for a
 * value the form said was fine. The refusal has to happen where there is a field to key it on.
 *
 * ── WHY PER MILLION TOKENS AND WHY THAT IS IN THE COLUMN NAME ──────────────────────────────────
 *
 * Every vendor publishes per-million-token pricing today, so storing it in that unit means the
 * operator types the number they are reading and no conversion happens anywhere. The UNIT IS IN
 * THE NAME because a bare `input_price` is the column somebody later divides by 1,000 "because it
 * is obviously per-thousand", and the resulting estimate is wrong by three orders of magnitude and
 * still plausible.
 *
 * ── THE THREE CONSTRAINTS, AND WHAT EACH ONE STOPS ─────────────────────────────────────────────
 *
 * 1. `provider_models_price_needs_currency` — a price may not exist without a currency. A bare
 *    `15.00` is not an amount: an organization billed by an EU reseller and one billed by OpenAI
 *    directly would both store `15.00`, a report would add them, and the sum would be a number in
 *    no currency at all. The constraint is one-directional on purpose — a currency with NO prices
 *    is permitted, because that is the state of a row whose operator recorded the vendor's billing
 *    currency before they had looked the prices up, and refusing it would make the form
 *    unfillable in the order a human fills it.
 *
 * 2. `provider_models_price_nonnegative` — no negative price. Not defensive noise: the estimated
 *    spend report is a SUM, and one negative row silently cancels other rows out, so the failure
 *    is a total that looks reasonable and is wrong. A credit or a discount is not a list price and
 *    does not belong on a catalog row.
 *
 * 3. `provider_models_price_currency_iso` — exactly three upper-case letters. `text` rather than
 *    `char(3)` precisely so this can be enforced rather than papered over: `char(3)` would accept
 *    `'us'` and silently store `'us '`, and every later comparison against `'USD'` would fail
 *    against a value that LOOKS right in a console. This says nothing about whether the code is a
 *    real ISO 4217 currency — that is a list that changes, and pinning it in a CHECK would make a
 *    new currency a migration.
 *
 * ── LOCK SAFETY ────────────────────────────────────────────────────────────────────────────────
 *
 * Same shape as 2026_08_07_000500 and 2026_08_19_001100: a bounded `lock_timeout` so a blocked DDL
 * fails fast with 55P03 instead of queueing every reader behind itself, wrapped in `retry()`. All
 * three columns are NULLABLE WITH NO DEFAULT, so no value goes near `pg_attribute.attmissingval`,
 * no rewrite happens and the ACCESS EXCLUSIVE lock is momentary.
 *
 * WHAT THE `retry()` ACTUALLY BUYS HERE IS LESS THAN IT LOOKS, AND THAT IS WORTH WRITING DOWN
 * RATHER THAN LEAVING FOR THE NEXT READER TO REDISCOVER. `Migrator::runMigration()` wraps a
 * migration in `$connection->transaction($callback)` whenever the grammar supports transactional
 * DDL and `$migration->withinTransaction` is true — PostgreSQL's grammar does and the default is
 * true, so this whole file is ONE transaction. A statement that fails with 55P03 therefore aborts
 * that transaction, and every retry attempt after it fails with 25P02 ("current transaction is
 * aborted") rather than re-acquiring the lock. The `lock_timeout` half is doing real work — it
 * turns an indefinite queue into a fast, loud failure — and the retry half is effectively "fail
 * once, clearly". This file COPIES the established shape deliberately rather than diverging from
 * the two migrations beside it; making the retry real would mean `public $withinTransaction =
 * false` on all three, which is a decision about the whole migration set and not a drive-by.
 *
 * NO `NOT VALID` / `VALIDATE CONSTRAINT` SPLIT, AND THAT IS A DECISION RATHER THAN AN OMISSION.
 * The split exists to move a validating scan off the strong lock — but with the file running as
 * one transaction, every lock it takes is held until COMMIT, so `ADD CONSTRAINT ... NOT VALID`
 * followed by `VALIDATE CONSTRAINT` would hold the same ACCESS EXCLUSIVE lock for the same
 * duration and buy nothing. What makes the plain form safe here is the other half: the three
 * columns were created NULL in the statement above, so the validating scan is over a table where
 * every row satisfies all three constraints by construction and cannot find a violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A bounded wait, not a queue. ACCESS EXCLUSIVE blocks readers AND queues behind any
        // in-flight reader, so a 4 ms catalog update can take the product down for the length of
        // one long analytics query (postgresql-patterns).
        $this->run("SET lock_timeout = '3s'");

        // Nullable with no default: momentary lock, no table scan, no rewrite. NULL is a real
        // state here and not a placeholder — it means "this operator has not recorded a price",
        // which is different from "this model is free" (0.000000) and has to stay different, or a
        // spend estimate silently reports an unpriced catalog as costing nothing.
        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_models
                ADD COLUMN input_price_per_million  numeric(14, 6),
                ADD COLUMN output_price_per_million numeric(14, 6),
                ADD COLUMN price_currency           text
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_models
                ADD CONSTRAINT provider_models_price_needs_currency
                CHECK (
                    price_currency IS NOT NULL
                    OR num_nonnulls(input_price_per_million, output_price_per_million) = 0
                )
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_models
                ADD CONSTRAINT provider_models_price_nonnegative
                CHECK (
                    (input_price_per_million  IS NULL OR input_price_per_million  >= 0)
                    AND (output_price_per_million IS NULL OR output_price_per_million >= 0)
                )
        SQL), 2000);

        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE provider_models
                ADD CONSTRAINT provider_models_price_currency_iso
                CHECK (price_currency IS NULL OR price_currency ~ '^[A-Z]{3}$')
        SQL), 2000);

        // NO INDEX. Nothing filters, sorts or joins on a price: the estimate is computed from rows
        // already narrowed to one organization by `provider_models_org_connection_model`, and an
        // index written for a row count this table does not have is write cost with no reader.
    }

    public function down(): void
    {
        $this->run(
            'ALTER TABLE provider_models '
            .'DROP CONSTRAINT IF EXISTS provider_models_price_currency_iso, '
            .'DROP CONSTRAINT IF EXISTS provider_models_price_nonnegative, '
            .'DROP CONSTRAINT IF EXISTS provider_models_price_needs_currency',
        );

        $this->run(
            'ALTER TABLE provider_models '
            .'DROP COLUMN IF EXISTS price_currency, '
            .'DROP COLUMN IF EXISTS output_price_per_million, '
            .'DROP COLUMN IF EXISTS input_price_per_million',
        );
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};

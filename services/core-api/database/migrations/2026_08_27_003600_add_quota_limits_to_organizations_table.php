<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE FOUR QUOTA LIMITS (§8.23, §6.1). Storage, bots, users, monthly tokens — the numbers
 * `App\Services\Quotas\QuotaGate` refuses against.
 *
 * They live on `organizations` and not in `settings` jsonb, and that is the same call
 * `postgresql-patterns` makes everywhere: jsonb earns its place for a snapshot written once and read
 * whole, and these are read by a predicate on every metered action and compared against an
 * aggregate. `settings->>'bots_quota'` would be a text comparison against a bigint sum, unindexable
 * and silently string-ordered — `'9'::text > '10'::text`.
 *
 * ═══ NULL MEANS UNLIMITED. ZERO MEANS NOTHING IS ALLOWED. THEY ARE DIFFERENT STATES ══════════
 *
 * This is the single most important sentence in this migration and the one a reader is most likely
 * to get backwards, because both render as "no number" on a form. All four columns are NULLABLE and
 * default to NULL, so EVERY EXISTING ORGANIZATION IS UNMETERED THE MOMENT THIS MIGRATION RUNS —
 * which is the only safe direction for a backfill-free deploy: a `DEFAULT 0` would refuse every
 * upload and every chat turn in the platform at the instant of the migration, and a
 * `DEFAULT <some number>` would invent a plan nobody sold.
 *
 * `QuotaGate` reads NULL as "this metric is not metered for this organization" and skips it
 * entirely. It reads 0 as a real limit that nothing satisfies. An operator who wants to stop an
 * organization writes 0; an operator who wants to stop METERING it writes null.
 *
 * ═══ THE COLUMNS ARE `bigint` WHERE THE UNIT IS BYTES OR TOKENS ══════════════════════════════
 *
 * `storage_bytes_quota` and `monthly_tokens_quota` are `bigint` because both exceed 2^31 in ordinary
 * use — 2 GiB is a small storage plan and 2.1 billion tokens is a large but unremarkable month —
 * and both are COMPARED against a `SUM()` over `usage_events.quantity`, which is `bigint`. Mixing
 * widths there is not an error, it is an implicit upcast that works until the LIMIT overflows on
 * the way in. `bots_quota` and `users_quota` are `integer`: an organization with two billion bots
 * has a different problem.
 *
 * ═══ WHO MAY SET THEM — AND A CONTRADICTION BETWEEN THE SPEC AND THIS SURFACE ════════════════
 *
 * READ THIS BEFORE WIDENING THE PERMISSION. §6.1 gives "Control limits for storage, bots, users,
 * and ingestion" to the PLATFORM OWNER — the platform-level role (`users.is_platform_owner`), not
 * an organization role — while §6.2 gives the Organization Owner "Manage organization settings".
 * Those two sentences do not agree about who owns the numbers in these four columns, and this
 * migration does not resolve the disagreement: it records it.
 *
 * What ships is the narrow reading plus a guard, and the guard is in the SERVICE rather than in a
 * constraint because it needs the actor:
 *
 *   * `Permission::QuotasManage` is held by the Organization Owner alone among the four org roles.
 *   * `App\Services\Quotas\QuotaLimitService` permits an org owner to LOWER a limit or to set one
 *     that was unlimited, and requires `users.is_platform_owner` to RAISE or REMOVE one.
 *
 * The reasoning is that a quota an organization can raise is not a quota. Self-service TIGHTENING
 * is safe and useful (an operator capping their own spend); self-service LOOSENING is a plan change,
 * and §6.1 says who makes those. This is reported as a spec/task contradiction rather than settled
 * here — there is no platform-admin surface in this application at all, so the platform owner's half
 * currently has no screen, and that is the gap a reviewer should look at first.
 *
 * ═══ NO `plan` COLUMN, AND NO PLAN TABLE ════════════════════════════════════════════════════
 *
 * Four numbers, not a foreign key to a `plans` catalogue. A plan is a commercial object with a
 * price, a term and a currency, and none of those exist anywhere in this schema; introducing the
 * reference without them would mean a table whose only column is a name. When plans arrive they add
 * a `plan_id` beside these columns and these become the per-organization OVERRIDE — which is the
 * shape every metered platform converges on anyway, because the exception is what a support ticket
 * asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A bounded wait, not a queue. ACCESS EXCLUSIVE blocks readers AND queues behind any
        // in-flight reader, so a 4 ms catalog update can take the product down for the length of one
        // long analytics query (postgresql-patterns). Failing fast ten times is a non-event.
        $this->run("SET lock_timeout = '3s'");

        // ONE STATEMENT FOR ALL FOUR, so the lock is acquired once rather than four times. Every
        // column is nullable with NO DEFAULT, so nothing reaches pg_attribute.attmissingval and no
        // rewrite happens: momentary lock, no table scan, no backfill.
        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN storage_bytes_quota bigint,
                ADD COLUMN bots_quota          integer,
                ADD COLUMN users_quota         integer,
                ADD COLUMN monthly_tokens_quota bigint
        SQL), 2000);

        // ONE CONSTRAINT COVERING ALL FOUR rather than four constraints, because the rule is one
        // rule: a negative limit is not a smaller limit, it is a limit nothing can satisfy AND a
        // number that makes `used <= limit` false for a brand-new organization. Zero is legal and
        // means exactly that — see the docblock on why 0 and NULL are different states.
        retry(10, fn () => $this->run(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_quotas_nonnegative CHECK (
                    (storage_bytes_quota IS NULL OR storage_bytes_quota >= 0)
                    AND (bots_quota          IS NULL OR bots_quota          >= 0)
                    AND (users_quota         IS NULL OR users_quota         >= 0)
                    AND (monthly_tokens_quota IS NULL OR monthly_tokens_quota >= 0)
                )
        SQL), 2000);

        // NO INDEX ON ANY OF THE FOUR, and that is deliberate rather than an omission. Every read of
        // these columns is `WHERE id = ?` on the organization already bound by the route, so they are
        // reached through the primary key. An index would be maintained on every organization write
        // to serve a query nobody issues — "which organizations have a storage quota above X" is a
        // platform-operator report that does not exist and would be a sequential scan over a table
        // with one row per tenant either way.
    }

    public function down(): void
    {
        $this->run(
            'ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_quotas_nonnegative',
        );
        $this->run(
            'ALTER TABLE organizations '
            .'DROP COLUMN IF EXISTS monthly_tokens_quota, '
            .'DROP COLUMN IF EXISTS users_quota, '
            .'DROP COLUMN IF EXISTS bots_quota, '
            .'DROP COLUMN IF EXISTS storage_bytes_quota',
        );
    }

    private function run(string $sql): void
    {
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};

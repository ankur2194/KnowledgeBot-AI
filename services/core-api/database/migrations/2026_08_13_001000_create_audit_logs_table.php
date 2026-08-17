<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only record of who did what, and the FIRST table in this schema that deliberately
 * outlives the rows it describes.
 *
 * Written as raw SQL through Schema::getConnection()->statement() like every other migration here
 * (the DB facade is pinned to App\Repositories\Eloquent by tests/Arch/DoctrineTest.php — a grep in
 * database/ used to back that up and went with CI), but for one extra reason: NOTHING below has a Blueprint
 * expression. Range partitioning, a composite primary key on a partitioned parent, and REVOKE are
 * all outside the schema builder's vocabulary.
 *
 * FOUR DECISIONS, EACH FROM postgresql-patterns, EACH LOAD-BEARING
 * ===============================================================
 *
 * 1. PARTITION BY RANGE (created_at), MONTHLY. `postgresql-patterns:166`: a nightly retention
 *    DELETE leaves a month of dead tuples that autovacuum must find, and the space is reused rather
 *    than returned — the table bloats past its own live size and every query on it slows down.
 *    Retention here is `DETACH PARTITION CONCURRENTLY` then `DROP TABLE`: O(1), and it takes no
 *    ACCESS EXCLUSIVE lock on the parent, so the write path never stalls behind housekeeping.
 *    `kb:prune-audit-partitions` is that command and it contains no DELETE.
 *
 * 2. PRIMARY KEY (id, created_at), NOT (id). A partitioned table's unique constraints must include
 *    every partition-key column, so `PRIMARY KEY (id)` is simply rejected by PostgreSQL. Stated
 *    here anyway because `postgresql-patterns:166` is explicit that this has to be decided BEFORE
 *    the first row lands: converting a non-partitioned audit_logs later is a full rewrite of the
 *    one table whose whole value proposition is that it is never rewritten. `id` alone stays unique
 *    by construction — it is a ULID — and no code path looks a row up by id without a time bound.
 *
 * 3. NO OUTBOUND FOREIGN KEY, AND NO `ON DELETE` OF ANY KIND (`postgresql-patterns:17`, `:192`,
 *    `:197`). `organization_id`, `actor_id`, `subject_type` and `subject_id` are PLAIN columns.
 *    An FK here has exactly two possible behaviours and both destroy the table's purpose: RESTRICT
 *    makes the audit trail block the deletion it is the evidence of, and CASCADE/SET NULL deletes
 *    or blanks the evidence along with the record. "Who deleted this user in March" is answerable
 *    only if the row survives the user. The cost is real and accepted: a subject id can dangle,
 *    nothing joins, and a reader resolving one must handle "gone".
 *
 * 4. REVOKE UPDATE, DELETE (`postgresql-patterns:197`). Append-only is enforced by the GRANT, not
 *    by discipline — see the block at the bottom for what that does and does not buy on a schema
 *    where one role owns and writes everything.
 *
 * WHAT IS DELIBERATELY *NOT* CONSTRAINED
 * ======================================
 * `operation` has no CHECK. Every other enum-ish column in this schema is `text` + CHECK, and the
 * inversion is deliberate: a status column is a CLOSED set the application defines, while the set
 * of auditable operations GROWS with every audited feature. A CHECK would mean a migration per
 * audited action and — the part that matters — an audit row REFUSED because someone shipped the
 * call site without the migration. Refusing to record an event is the one failure direction this
 * table may never have. The closed list lives in App\Services\Audit\AuditLogger::OPERATIONS, where
 * a typo costs a test rather than a lost row. `outcome` IS closed and IS checked, because it is
 * derived from that same map and can only ever be the two values below.
 *
 * `details` is `jsonb NOT NULL DEFAULT '{}'` with a `jsonb_typeof(...) = 'object'` CHECK. The check
 * is not decoration: `json_encode([])` is `[]`, a JSON ARRAY, and one array-shaped row makes every
 * `details->>'…'` query in a future admin surface silently return nothing for it. The writer never
 * sets the column when it has nothing to say, so the DEFAULT is what produces `{}`.
 *
 * NO organization_id NOT NULL, AND NO OrganizationScope ON THE MODEL. `organization_id` is nullable
 * because some audited events belong to no organization: a failed login for an address that is not
 * a user has no tenant, and it is the single most security-relevant row in the table. See
 * App\Models\AuditLog for the scope decision that follows from that, and note that
 * `postgresql-patterns`' Definition-of-done item "every new table has organization_id NOT NULL or a
 * NOT NULL FK chain to one" is knowingly not met here — this table is the documented exception,
 * annotated because no CI check exists for it yet.
 */
return new class extends Migration
{
    /**
     * The two values `outcome` may hold. Mirrors App\Services\Audit\AuditLogger::OUTCOME_* — the
     * map there decides which one a given operation gets, so the two cannot disagree per row.
     */
    private const OUTCOMES = ['success', 'failure'];

    public function up(): void
    {
        $outcomes = $this->quotedList(self::OUTCOMES);

        // No `SET lock_timeout` / retry() wrapper: postgresql-patterns requires that for DDL on a
        // table that already has traffic. This statement creates the table.
        $this->run(<<<SQL
            CREATE TABLE audit_logs (
                id               char(26) COLLATE "C" NOT NULL,
                -- FIRST after the key on purpose: it is the partition key, it is set by the writer
                -- (Eloquent, so Carbon::setTestNow() moves it) and it defaults to now() so a row
                -- inserted by any other means still lands in a partition.
                created_at       timestamptz NOT NULL DEFAULT now(),
                operation        text NOT NULL,
                outcome          text NOT NULL DEFAULT 'success',
                -- NULLABLE, and no FK. See the class docblock: an org-less event is a real event.
                organization_id  char(26) COLLATE "C",
                -- The user who acted. NULL for an unauthenticated event (a failed login), and NULL
                -- for a system action. No FK: the actor may be deleted and the row must remain.
                actor_id         char(26) COLLATE "C",
                -- What was acted ON, as a (type, ULID) pair rather than an FK. `subject_type` holds a
                -- fully-qualified CLASS NAME ('App\Models\User', 'App\Models\OrganizationInvitation'),
                -- written as `SomeModel::class` at the call site. It has no CHECK, for the same reason
                -- `operation` has none: the set is open and a refused audit row is worse than a row
                -- nobody wrote rules for.
                --
                -- THE FQCN, NOT A TABLE-ISH NAME, and this comment used to say the opposite. Every
                -- writer in the tree passes `::class` — so the stated convention and the actual data
                -- disagreed, which is the worst state for a free-text column: two spellings of one
                -- fact means a query for a subject finds half its rows. The FQCN wins on three
                -- counts — it is what the code already produces, `::class` is checked by the compiler
                -- while a string literal is not, and it matches Laravel's own morph-type convention,
                -- so a future `morphTo()` on this pair resolves with no map.
                subject_type     text,
                subject_id       char(26) COLLATE "C",
                -- `inet`, not text: an audit trail is read with network predicates ("everything
                -- from this /24"), and text cannot answer that. The writer validates before
                -- binding, so an X-Forwarded-For of "../../etc" is dropped rather than 22P02-ing
                -- the audit insert.
                ip_address       inet,
                user_agent       text,
                -- The bridge to telemetry, and the ONLY one. An audit row and the log lines for the
                -- same request share this id, which is what lets an investigator move between two
                -- stores that must never BE one store (kb-observability-conventions, §18.11).
                request_id       text,
                details          jsonb NOT NULL DEFAULT '{}'::jsonb,

                -- Every partitioned table's key includes the partition key. See docblock (2).
                PRIMARY KEY (id, created_at),

                CONSTRAINT audit_logs_operation_not_blank CHECK (operation <> ''),
                CONSTRAINT audit_logs_outcome_check CHECK (outcome IN ({$outcomes})),
                -- A subject is a pair or it is absent. A type with no id is unresolvable and an id
                -- with no type is ambiguous across every table.
                CONSTRAINT audit_logs_subject_paired
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
                CONSTRAINT audit_logs_details_is_object
                    CHECK (jsonb_typeof(details) = 'object'),
                -- User-Agent is attacker-controlled and unbounded on the wire. The writer truncates
                -- too; this is the backstop, on the table that can never be cleaned up in place.
                CONSTRAINT audit_logs_user_agent_bounded
                    CHECK (user_agent IS NULL OR length(user_agent) <= 512)
            ) PARTITION BY RANGE (created_at)
        SQL);

        // Indexes are created on the PARENT, which is what makes them exist on every existing
        // partition and on every partition created later — including the ones
        // kb:create-audit-partitions makes at 03:00 next quarter. An index created per-partition
        // instead is one somebody forgets, on the partition that is currently hot.
        //
        // ORG-LEADING, ALWAYS (postgresql-patterns:164). Never (created_at, organization_id): a
        // time-leading index makes the tenant predicate a filter over every org's rows in the
        // window, which on this table is "every login in the platform this month".
        $this->run(<<<'SQL'
            CREATE INDEX audit_logs_org_created
                ON audit_logs (organization_id, created_at DESC)
        SQL);

        // "What did this member do." Partial, because most rows on the guest paths have no actor
        // and indexing their NULLs buys nothing.
        $this->run(<<<'SQL'
            CREATE INDEX audit_logs_org_actor_created
                ON audit_logs (organization_id, actor_id, created_at DESC)
                WHERE actor_id IS NOT NULL
        SQL);

        // "The audit trail for THIS record" — the query an admin UI opens with.
        $this->run(<<<'SQL'
            CREATE INDEX audit_logs_org_subject_created
                ON audit_logs (organization_id, subject_type, subject_id, created_at DESC)
                WHERE subject_id IS NOT NULL
        SQL);

        // The org-less rows: failed logins for unknown addresses, platform-scope events. Without
        // this they are reachable only by a sequential scan, because every index above leads with a
        // column that is NULL for exactly these rows — and they are the ones an intrusion
        // investigation starts from.
        $this->run(<<<'SQL'
            CREATE INDEX audit_logs_platform_created
                ON audit_logs (created_at DESC)
                WHERE organization_id IS NULL
        SQL);

        // THE TABLE IS WRITABLE THE MOMENT IT EXISTS. Without a partition covering now(), the very
        // first INSERT fails with 23514 "no partition of relation \"audit_logs\" found for row" —
        // and by the audit-write-failure policy in AuditLogger that is either a 500 on a state
        // change or a lost login record. Current month plus next, so a deploy at 23:59 on the last
        // day of a month is a non-event; kb:create-audit-partitions keeps the runway ahead of that.
        //
        // There is DELIBERATELY NO DEFAULT PARTITION. It looks like the safer choice and is not:
        // with a DEFAULT partition present, every later `CREATE TABLE … PARTITION OF` must scan the
        // default to prove no row belongs in the new range, holding ACCESS EXCLUSIVE on it while
        // the parent is being written — which is precisely the lock-queue stall
        // postgresql-patterns:117 forbids. The runway plus a loud failure is the better trade.
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        $partitions = [
            $this->createMonthPartition($month),
            $this->createMonthPartition($month->addMonth()),
        ];

        $this->revokeWrites($partitions);
    }

    public function down(): void
    {
        // Dropping a partitioned table drops its partitions with it — no CASCADE needed, and no
        // enumeration that could miss the one created last night.
        $this->run('DROP TABLE IF EXISTS audit_logs');
    }

    /**
     * One monthly partition, `audit_logs_YYYY_MM`, half-open [start, start + 1 month).
     *
     * THE NAME IS THE CONTRACT. kb:prune-audit-partitions parses the month back out of it with
     * /^audit_logs_(\d{4})_(\d{2})$/ and refuses to touch anything that does not match, so a
     * hand-made partition under another name is never dropped by accident — and never pruned
     * either. Keep the two spellings in step.
     *
     * `IF NOT EXISTS` so this is idempotent against a partition kb:create-audit-partitions already
     * made: a migration re-run on a database that ran the command first must not fail.
     *
     * @return string the partition's relation name
     */
    private function createMonthPartition(CarbonImmutable $month): string
    {
        $name = 'audit_logs_'.$month->format('Y_m');
        $from = $month->format('Y-m-d H:i:sP');
        $to = $month->addMonth()->format('Y-m-d H:i:sP');

        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" PARTITION OF audit_logs
                FOR VALUES FROM ('{$from}') TO ('{$to}')
        SQL);

        return $name;
    }

    /**
     * Append-only, enforced by the grant (`postgresql-patterns:197`).
     *
     * WHY `current_user` AND NOT A LITERAL ROLE NAME. There is exactly one application role in this
     * deployment and the migration connection is it: `config/database.php` reads one DB_USERNAME
     * (`knowledgebot` in .env, `kb_test` in phpunit.xml) and there is no separate migration role.
     * `current_user` therefore resolves to the role that will do the writing, in every environment,
     * with no third spelling of the name to drift — and if a dedicated owner/app split is ever
     * introduced, this REVOKE hits the OWNER and the new app role keeps its UPDATE/DELETE. That is
     * a real gap, it is called out in the report for this change, and the fix belongs with the role
     * split rather than with a guessed literal here.
     *
     * WHAT THIS BUYS TODAY, MEASURED RATHER THAN ASSUMED — AND IT IS LESS THAN IT LOOKS.
     * Measured on 2026-08-13 against PostgreSQL 18.4 (this DDL run in a rolled-back transaction on
     * the live `postgres` container): the REVOKE lands, `pg_class.relacl` shows no UPDATE and no
     * DELETE for the role on the parent or on any partition — and `UPDATE audit_logs SET …` then
     * SUCCEEDS anyway, because `knowledgebot` is the only login role in this deployment and
     * Compose's `POSTGRES_USER` makes it a SUPERUSER, which bypasses every ACL check. So append-only
     * is currently enforced by the ACL being *auditable*, by App\Models\AuditLog refusing in PHP, and
     * by no code path issuing the statement — NOT by the database. Closing it is an infrastructure
     * change (a non-superuser application role, `infrastructure/docker/`, owned by
     * platform-devops-engineer), and it is reported rather than worked around here: writing a
     * migration that pretends otherwise would be the worse outcome.
     *
     * THE OTHER TWO LIMITS, stated plainly because a security control believed to be stronger than it
     * is, is worse than a missing one:
     *   * A table OWNER can GRANT the privilege back to itself. PostgreSQL owners have no implicit
     *     privileges (so the REVOKE does bite a non-superuser owner) but they do have the right to
     *     re-grant. Real append-only needs the app role NOT to own the table, i.e. the role split.
     *   * TRUNCATE is a separate privilege and is NOT revoked. It erases evidence as thoroughly as
     *     DELETE. It is left in place because DatabaseTruncation (tests/Integration) needs it on
     *     every table; revoke it together with the role split, when the truncating role is the test
     *     role and not the application's.
     *
     * PARTITIONS ARE INCLUDED, and that is the non-obvious half. Privileges on a partition are NOT
     * inherited from the parent: access routed THROUGH the parent checks only the parent, but
     * `UPDATE audit_logs_2026_08 SET …` checks the partition, and a partition created by this role
     * is OWNED by it with the owner's default privileges. Revoking only on the parent leaves a
     * fully writable back door named one underscore away. kb:create-audit-partitions repeats this
     * for every partition it creates, for the same reason.
     *
     * @param  list<string>  $partitions
     */
    private function revokeWrites(array $partitions): void
    {
        $relations = implode(', ', array_merge(
            ['audit_logs'],
            array_map(static fn (string $name): string => "\"{$name}\"", $partitions),
        ));

        // One statement, a relation list and a grantee list — not a DO block walking pg_inherits.
        // The partitions created above are the only ones that exist at this point, so the loop had
        // nothing to discover, and `$do$` inside a PHP heredoc is variable interpolation waiting to
        // produce a silently broken dollar-quoted body.
        //
        // PUBLIC holds no table privileges by default, so revoking from it is a no-op today. It is
        // here to state the intent against a future `GRANT … ON ALL TABLES … TO PUBLIC` in an ops
        // script, which would otherwise re-open writes with nothing to notice it.
        // CURRENT_USER *AND* THE APPLICATION ROLE, and the second grantee is the one that matters.
        // Under the role split (D21) CURRENT_USER here is the migration role, which OWNS these tables —
        // and revoking from an owner is near-useless: measured on PostgreSQL 18.4, an owner can
        // `GRANT UPDATE … TO` itself straight back and does not even need a new session. The grantee that
        // must lose UPDATE/DELETE is the role the request-serving containers authenticate as, and that is
        // no longer CURRENT_USER.
        //
        // TRUNCATE is included because a partition created by this migration receives the OWNER's default
        // privileges. The app role never receives TRUNCATE from any GRANT in `apply-roles.sh`, so this is
        // belt to that braces rather than the mechanism.
        //
        // THE NAME COMES FROM CONFIG, NEVER A LITERAL: `database.connections.pgsql.username` is by
        // definition the role the application connects as, so the two cannot disagree. It is validated and
        // quoted as an identifier because a role name cannot be bound as a query parameter — and an
        // unvalidated interpolation into DDL is the one place in this file that would deserve the name
        // "SQL injection". When the split is not deployed this resolves to the same role as CURRENT_USER,
        // making the extra grantee a harmless no-op rather than an error.
        $appRole = (string) config('kb.db_app_role');

        // `\RuntimeException`, fully qualified: a migration file declares NO namespace, so
        // `use RuntimeException;` is a non-compound import that PHP reports as "has no effect" — which
        // the test environment escalates to an ErrorException and which turned every AuditLog test red.
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $appRole) !== 1) {
            throw new \RuntimeException(
                "Refusing to issue REVOKE for role '{$appRole}': a role name is interpolated into DDL "
                .'and cannot be bound as a parameter, so only a validated identifier may be used.'
            );
        }

        $this->run(
            "REVOKE UPDATE, DELETE, TRUNCATE ON TABLE {$relations} FROM PUBLIC, CURRENT_USER, \"{$appRole}\""
        );
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
        // App\Repositories\Eloquent, and that arch test is now the only thing that does.
        Schema::getConnection()->statement($sql);
    }
};

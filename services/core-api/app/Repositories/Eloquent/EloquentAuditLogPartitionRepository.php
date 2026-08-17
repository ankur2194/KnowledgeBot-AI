<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The partition catalogue and the DDL over it — the only place in the application that names an
 * `audit_logs_*` relation.
 *
 * WHY IT LIVES UNDER App\Repositories\Eloquent. This is raw SQL against specific schema objects, and
 * tests/Arch/DoctrineTest.php puts raw query builders here on purpose. Everything below reads the
 * PostgreSQL catalogue or issues DDL; none of it is expressible through a model, and putting it in
 * the two console commands instead would duplicate the name validation — the one part that must not
 * be duplicated, because it is what stops a catalogue string being interpolated into a DROP.
 *
 * NO INTERFACE, DELIBERATELY, unlike AuditLogRepositoryInterface. Nothing mocks partition DDL: a
 * fake that pretends to detach a partition proves nothing about the only thing worth proving, which
 * is that PostgreSQL accepted the statement. Both commands are exercised against the real database
 * or not at all.
 *
 * IDENTIFIERS CANNOT BE BOUND. DDL and the catalogue casts below take no placeholders, so every
 * relation name is interpolated — and therefore every one of them passes through
 * :self::assertPartitionName() first, INCLUDING the names read back out of pg_class. A name is not
 * trustworthy because of where it came from: `audit_logs_2026_08"; DROP TABLE users; --` is a legal
 * PostgreSQL table name.
 *
 * // tenancy-exempt: partition DDL is schema-level, not row-level. There is no organization_id to
 * // scope on a CREATE TABLE, and a per-tenant partition scheme would leak tenant identity into the
 * // catalogue (one relation per org, readable by anyone who can read pg_class) while making the
 * // retention drop O(tenants) instead of O(1).
 */
final class EloquentAuditLogPartitionRepository
{
    public const PARENT = 'audit_logs';

    /**
     * The partition-name grammar, and it is a contract rather than a convention: the month a
     * partition covers is recovered from its NAME by :self::monthOf(), and the prune command refuses
     * to touch a relation whose name does not match. The migration that creates the table spells the
     * same shape; keep the two in step.
     */
    private const NAME_PATTERN = '/^audit_logs_(\d{4})_(\d{2})$/';

    public static function nameFor(CarbonImmutable $month): string
    {
        return self::PARENT.'_'.$month->startOfMonth()->format('Y_m');
    }

    /**
     * The month a partition name claims to cover, or null if the name is not one of ours.
     *
     * Null rather than an exception: the prune command walks the catalogue, and a relation someone
     * created by hand is a thing to skip and report, never a thing to drop and never a crash.
     */
    public static function monthOf(string $name): ?CarbonImmutable
    {
        if (preg_match(self::NAME_PATTERN, $name, $matches) !== 1) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $matches[1].'-'.$matches[2].'-01 00:00:00',
            'UTC',
        );

        return $month instanceof CarbonImmutable ? $month->startOfMonth() : null;
    }

    /**
     * Create the partition for one month if it is not already there.
     *
     * @return bool whether this call created it
     */
    public function ensureMonth(CarbonImmutable $month): bool
    {
        $name = self::nameFor($month);
        $start = $month->startOfMonth();

        if (in_array($name, $this->partitionNames(), true)) {
            return false;
        }

        // Half-open [from, to): PostgreSQL range bounds are inclusive-lower, exclusive-upper, so
        // consecutive months meet exactly and no timestamp falls in both or in neither. `IF NOT
        // EXISTS` on top of the catalogue check above because two schedulers on two hosts can reach
        // this line in the same second, and a duplicate-relation error would fail a task whose
        // intent was already satisfied.
        $this->run(sprintf(
            "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
            $this->quoteIdentifier($name),
            self::PARENT,
            $start->format('Y-m-d H:i:sP'),
            $start->addMonth()->format('Y-m-d H:i:sP'),
        ));

        // A fresh partition is OWNED by the role that created it, with the owner's default
        // privileges — so it is UPDATE-able and DELETE-able directly by name even though the parent
        // is not. Privileges are not inherited through the partition hierarchy: access routed
        // through the parent checks the parent, but `UPDATE audit_logs_2026_08 SET …` checks the
        // partition. The migration says the same thing at more length; this is the half that has to
        // run every month forever rather than once.
        $this->revokeWrites($name);

        return true;
    }

    /**
     * Every live partition of audit_logs, by name, oldest first (the name sorts chronologically —
     * that is what the zero-padded `YYYY_MM` is for).
     *
     * Detach-pending partitions are EXCLUDED: they are half-way out of the hierarchy and the only
     * legal next step for one is FINALIZE, which :self::pendingDetachNames() serves.
     *
     * @return list<string>
     */
    public function partitionNames(): array
    {
        return $this->names(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_inherits i
              JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = to_regclass('audit_logs')
               AND NOT i.inhdetachpending
             ORDER BY c.relname
        SQL);
    }

    /**
     * Partitions left mid-detach by an interrupted `DETACH … CONCURRENTLY`.
     *
     * THIS IS NOT A THEORETICAL STATE. `DETACH PARTITION CONCURRENTLY` runs in two internal
     * transactions and waits for concurrent readers in between; a SIGKILL, a deploy, or a
     * `statement_timeout` in that window leaves the partition attached-but-pending. In that state
     * PostgreSQL refuses another CONCURRENTLY detach on the same parent — so without this step the
     * prune command would fail identically every run, forever, having never got past the wreckage of
     * the one run that was interrupted.
     *
     * @return list<string>
     */
    public function pendingDetachNames(): array
    {
        return $this->names(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_inherits i
              JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = to_regclass('audit_logs')
               AND i.inhdetachpending
             ORDER BY c.relname
        SQL);
    }

    /**
     * Tables named like a partition that are no longer part of the hierarchy — detached successfully
     * by an earlier run that then died before the DROP.
     *
     * They still hold their rows and their indexes but nothing writes to them and nothing reads them,
     * so they are pure retained data: exactly what the retention window exists to remove.
     *
     * @return list<string>
     */
    public function detachedTableNames(): array
    {
        return $this->names(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'r'
               AND n.nspname = current_schema()
               AND c.relname ~ '^audit_logs_[0-9]{4}_[0-9]{2}$'
               AND NOT EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhrelid = c.oid)
             ORDER BY c.relname
        SQL);
    }

    public function finalizeDetach(string $name): void
    {
        $this->assertPartitionName($name);

        $this->run(sprintf(
            'ALTER TABLE %s DETACH PARTITION %s FINALIZE',
            self::PARENT,
            $this->quoteIdentifier($name),
        ));
    }

    /**
     * Detach one partition without taking ACCESS EXCLUSIVE on the parent.
     *
     * CONCURRENTLY IS THE WHOLE POINT (`postgresql-patterns:166`). A plain DETACH takes ACCESS
     * EXCLUSIVE on the parent, which blocks READERS, and PostgreSQL's lock queue is ordered — so one
     * long-running query in front of it stalls every audit write that arrives behind it. The price is
     * that CONCURRENTLY cannot run inside a transaction block, which is why the command checks
     * :self::insideTransaction() before it starts.
     */
    public function detachConcurrently(string $name): void
    {
        $this->assertPartitionName($name);

        $this->run(sprintf(
            'ALTER TABLE %s DETACH PARTITION %s CONCURRENTLY',
            self::PARENT,
            $this->quoteIdentifier($name),
        ));
    }

    /**
     * Drop a table that is already detached. Refuses to drop anything still attached, because
     * `DROP TABLE` on a live partition is a silent retention change: it removes rows inside the
     * window and nothing afterwards distinguishes it from a partition that never existed.
     */
    public function dropDetached(string $name): void
    {
        $this->assertPartitionName($name);

        if (in_array($name, $this->partitionNames(), true)) {
            throw new InvalidArgumentException(
                "Refusing to drop {$name}: it is still an attached partition of ".self::PARENT
                .'. Detach it first — dropping a live partition deletes audit rows that are inside '
                .'the retention window.',
            );
        }

        $this->run('DROP TABLE IF EXISTS '.$this->quoteIdentifier($name));
    }

    /**
     * How many rows a partition holds.
     *
     * A real `count(*)`, not `pg_class.reltuples` (`postgresql-patterns:170` — an estimate autovacuum
     * updates, never a number anyone should act on). It is a sequential scan of one month of one
     * table, it runs once per pruned partition, and its output is the only place the row count of a
     * dropped partition is ever recorded. That trade is worth one scan.
     */
    public function rowCount(string $name): int
    {
        $this->assertPartitionName($name);

        $count = $this->scalar('SELECT count(*) AS value FROM '.$this->quoteIdentifier($name));

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * True when a transaction is already open on this connection.
     *
     * `DETACH PARTITION CONCURRENTLY` is rejected inside one (25001), and the suite runs every
     * Feature test inside a RefreshDatabase transaction — so this is what lets the command say which
     * of those two situations it is in, instead of surfacing a driver error the reader has to decode.
     */
    public function insideTransaction(): bool
    {
        // BOTH CONNECTIONS, and the answer is the conservative OR of them. `RefreshDatabase` opens its
        // transaction on the DEFAULT connection; when `pgsql_ddl` resolves to a distinct role it is a
        // SEPARATE PDO handle with its own transaction state, so asking only the DDL one reports 0 inside
        // a Feature test and the command proceeds to run destructive DDL it was written to refuse.
        // Erring towards "yes, we are in a transaction" costs a refusal a caller can retry; erring the
        // other way costs an ACCESS EXCLUSIVE lock on audit_logs, or a DETACH that never rolls back.
        return Schema::getConnection()->transactionLevel() > 0
            || $this->connection()->transactionLevel() > 0;
    }

    /**
     * THE DDL CONNECTION — same server, same database, a role that may `CREATE` in `public` (D21).
     *
     * Every statement in this class is DDL or a catalogue read: `CREATE TABLE … PARTITION OF`,
     * `DETACH`, `DROP`, `REVOKE`, `pg_class`. Measured under the role split, as the application role:
     * `CREATE TABLE … PARTITION OF audit_logs` fails at `permission denied for schema public`, BEFORE
     * any ownership check, because the app role holds USAGE and not CREATE. Running these on the
     * default connection therefore breaks `kb:create-audit-partitions` — and losing that command means
     * that at 00:00 on the first of a month past the runway every audit insert fails with 23514 and
     * every ABORT-policy action returns 500, on a clock.
     *
     * `Schema::connection()` rather than `DB::connection()`: both are legal here (the arch rule
     * confines `DB::` to this namespace, which this class is in), but staying on the facade this file
     * already uses keeps the arch surface unchanged and the diff honest.
     *
     * When the split is not deployed, `pgsql_ddl` falls back to the same role as `pgsql`, so this is
     * exactly the previous behaviour — see the connection's docblock in config/database.php for why the
     * fallback is the APP role and not `kb_migrate`.
     */
    private function connection(): \Illuminate\Database\Connection
    {
        // THE SAME CONNECTION OBJECT WHEN THE ROLES ARE THE SAME, and that is not an optimisation.
        //
        // A second named connection is a second PDO handle with its own transaction. Where the split is
        // NOT deployed — every developer machine, the whole test suite — the two roles are identical, so a
        // second handle buys nothing and costs two things that both bite: `RefreshDatabase` wraps only the
        // default handle, so DDL issued on the other one is NOT rolled back and leaks partitions into the
        // test database; and `insideTransaction()` cannot see the ambient transaction. Reusing the default
        // handle makes the unsplit case byte-for-byte what it was before D21.
        //
        // When the roles DO differ we are in a deployment with the split, where these statements must run
        // as the migration role and where there is no ambient transaction to inherit.
        $ddlRole = config('database.connections.pgsql_ddl.username');
        $appRole = config('database.connections.pgsql.username');

        return $ddlRole === $appRole
            ? Schema::getConnection()
            : Schema::connection('pgsql_ddl')->getConnection();
    }

    /**
     * Whether UPDATE and DELETE are absent from the relation's ACL for the connection's role.
     *
     * READS pg_class.relacl RATHER THAN has_table_privilege(), and the difference is the whole reason
     * this method exists. `has_table_privilege()` answers "would this statement succeed", which for a
     * SUPERUSER is always yes no matter what was granted — so a test built on it would pass on a
     * non-superuser and pass on a superuser for opposite reasons. The ACL answers "was the privilege
     * granted", which is the thing the migration actually did.
     *
     * A NULL `relacl` means "owner's default privileges, never touched" — i.e. the revoke did not
     * happen — and must NOT read as revoked, which is why the presence of an explicit ACL is part of
     * the answer rather than an afterthought.
     */
    public function writesRevoked(string $relation): bool
    {
        if ($relation !== self::PARENT) {
            $this->assertPartitionName($relation);
        }

        // The inner query re-joins pg_class rather than referencing the outer `c.relacl` from inside a
        // function-in-FROM: that form needs LATERAL semantics in a correlated subquery and is the kind
        // of thing that works on one PostgreSQL and not the next.
        // ARGUMENT ORDER MATCHES PLACEHOLDER ORDER — role first, relation second — and getting it
        // backwards is SILENT. The role placeholder sits in the subquery ABOVE the relation's, so a
        // swapped call asks for `rolname = 'audit_logs'` and `to_regclass('<role>')`; the latter is NULL,
        // the outer query returns NO ROWS, and this method answers `false` for every relation — i.e.
        // "writes are still granted" about a correctly revoked table. I wrote it that way first and the
        // ACL spec caught it, which is the one test that reads the real catalogue rather than a fixture.
        //
        // The comment lives HERE and not beside the closing `SQL,` for a reason worth a line: the
        // indented `SQL,` is the heredoc's closing identifier, so anything above it is STRING CONTENT.
        // The same note placed there became part of the query and PostgreSQL answered
        // `syntax error at or near "MATCHES"`.
        $rows = $this->connection()->select(sprintf(<<<'SQL'
            SELECT (c.relacl IS NOT NULL) AS has_acl,
                   coalesce((
                       SELECT count(*)
                         FROM pg_class inner_c
                         CROSS JOIN LATERAL aclexplode(inner_c.relacl) a
                        WHERE inner_c.oid = c.oid
                          AND a.grantee = (SELECT r.oid FROM pg_roles r WHERE r.rolname = '%s')
                          AND a.privilege_type IN ('UPDATE', 'DELETE', 'TRUNCATE')
                   ), 0) AS write_grants
              FROM pg_class c
             WHERE c.oid = to_regclass('%s')
        SQL, $this->applicationRole(), $relation));

        $first = $rows[0] ?? null;

        if (! is_object($first)) {
            return false;
        }

        $columns = get_object_vars($first);
        $hasAcl = $columns['has_acl'] ?? null;
        $grants = $columns['write_grants'] ?? null;

        // PDO_PGSQL renders booleans as 't'/'f' strings, so compare against both spellings rather
        // than casting — (bool) 'f' is TRUE and would invert this answer silently.
        $aclPresent = $hasAcl === true || $hasAcl === 't' || $hasAcl === 1 || $hasAcl === '1';

        return $aclPresent && is_numeric($grants) && (int) $grants === 0;
    }

    /**
     * See the migration's revokeWrites() for the full accounting. In short: CURRENT_USER is the
     * application role in this deployment, partitions do not inherit the parent's privileges, and
     * TRUNCATE is knowingly left alone.
     */
    private function revokeWrites(string $name): void
    {
        $this->assertPartitionName($name);

        // `FROM PUBLIC, CURRENT_USER` — a role_specification list, not a DO block. The DO block this
        // replaced needed dollar quoting, and `$do$` inside a PHP heredoc is variable interpolation.
        $this->run(sprintf(
            'REVOKE UPDATE, DELETE, TRUNCATE ON TABLE %s FROM PUBLIC, CURRENT_USER, "%s"',
            $this->quoteIdentifier($name),
            $this->applicationRole(),
        ));
    }

    /**
     * The role the APPLICATION connects as — the grantee whose privileges actually matter here.
     *
     * ── WHY NOT `current_user` ───────────────────────────────────────────────────────────────────
     * `writesRevoked()` used to ask whether `current_user` held UPDATE/DELETE, and under the role split
     * that question answers the wrong thing: on this connection `current_user` is the MIGRATION role,
     * which owns the table and has also been revoked, so the check would report "writes revoked" on any
     * tree — passing for the wrong reason, which is worse than failing. The subject has to be named.
     *
     * Read from `database.connections.pgsql.username` rather than a literal, because that value IS by
     * definition the role the request-serving containers authenticate as, so the check and the runtime
     * cannot disagree. When the split is not deployed it resolves to the same role as `current_user` and
     * the behaviour is unchanged.
     *
     * VALIDATED, because it is interpolated into SQL as a quoted literal and as an identifier, and a role
     * name cannot be bound as a parameter. Same gate and same reason as `quoteIdentifier()` below.
     */
    private function applicationRole(): string
    {
        $role = (string) config('kb.db_app_role');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to interpolate the role name '{$role}': it is written into DDL and into a "
                .'catalogue query, and cannot be bound as a parameter.'
            );
        }

        return $role;
    }

    /**
     * The gate every interpolated identifier passes.
     *
     * Deliberately an allow-list of one exact shape rather than an escaping routine: escaping is a
     * thing that can be got subtly wrong, and there is no legitimate audit partition name outside
     * `audit_logs_YYYY_MM`.
     */
    private function assertPartitionName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to issue SQL for '{$name}': an audit partition name must match "
                .self::NAME_PATTERN.'. Identifiers cannot be bound as parameters, so an unvalidated '
                .'name here is a DDL injection.',
            );
        }
    }

    private function quoteIdentifier(string $name): string
    {
        // Safe only because assertPartitionName() (or the PARENT constant) precedes every call. The
        // double quotes stay so a case-folding surprise is impossible.
        return '"'.$name.'"';
    }

    /**
     * @return list<string>
     */
    private function names(string $sql): array
    {
        $names = [];

        foreach ($this->connection()->select($sql) as $row) {
            if (! is_object($row)) {
                continue;
            }

            $name = get_object_vars($row)['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function scalar(string $sql): mixed
    {
        $rows = $this->connection()->select($sql);
        $first = $rows[0] ?? null;

        if (! is_object($first)) {
            return null;
        }

        return get_object_vars($first)['value'] ?? null;
    }

    private function run(string $sql): void
    {
        $this->connection()->statement($sql);
    }
}

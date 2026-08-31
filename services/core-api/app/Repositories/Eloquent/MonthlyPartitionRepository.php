<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The partition catalogue and the DDL over it, for a table that is `PARTITION BY RANGE (<ts>)`
 * MONTHLY with a `{parent}_YYYY_MM` naming contract.
 *
 * ── WHY THIS IS AN ABSTRACT BASE AND NOT TWO CLASSES ─────────────────────────────────────────
 *
 * `audit_logs` (ADR-041) and `usage_events` (2026_08_27_003500) are the two partitioned tables in
 * this schema, and they are partitioned for the same reason — append-only, no children, retention is
 * a whole-month DROP rather than a DELETE that bloats the heap. Everything below is catalogue reads
 * and DDL parameterised by ONE relation name, and duplicating it would duplicate the part that must
 * NOT be duplicated: `assertPartitionName()`, which is the only thing standing between a relation
 * name read out of `pg_class` and a `DROP TABLE`.
 *
 * `EloquentAuditLogPartitionRepository` was the original and its docblock carries the reasoning for
 * every choice here; this class is that file with the relation name lifted out. What stayed behind
 * is the part that is genuinely audit-specific: the append-only GRANT (`kb-security-baseline`
 * §18.11 requires it for the compliance record and states nothing of the kind for a usage ledger)
 * and the `pg_class.relacl` check that proves it.
 *
 * ── IDENTIFIERS CANNOT BE BOUND ──────────────────────────────────────────────────────────────
 *
 * DDL and the catalogue casts below take no placeholders, so every relation name is interpolated —
 * and therefore every one of them passes through :self::assertPartitionName() first, INCLUDING the
 * names read back out of `pg_class`. A name is not trustworthy because of where it came from:
 * `usage_events_2026_08"; DROP TABLE users; --` is a legal PostgreSQL table name.
 *
 * ── WHY IT LIVES UNDER App\Repositories\Eloquent ─────────────────────────────────────────────
 *
 * This is raw SQL against specific schema objects, and tests/Arch/DoctrineTest.php puts raw query
 * builders here on purpose. None of it is expressible through a model.
 *
 * NO INTERFACE, DELIBERATELY. Nothing mocks partition DDL: a fake that pretends to detach a
 * partition proves nothing about the only thing worth proving, which is that PostgreSQL accepted the
 * statement.
 *
 * // tenancy-exempt: partition DDL is schema-level, not row-level. There is no organization_id to
 * // scope on a CREATE TABLE, and a per-tenant partition scheme would leak tenant identity into the
 * // catalogue (one relation per org, readable by anyone who can read pg_class) while making the
 * // retention drop O(tenants) instead of O(1).
 */
abstract class MonthlyPartitionRepository
{
    /** The partitioned parent relation. Overridden by every subclass; never used at this value. */
    public const PARENT = '';

    /**
     * The month a partition covers, recovered from its NAME.
     *
     * THE NAME IS THE CONTRACT: the prune command refuses to touch a relation whose name does not
     * match, so a hand-made partition under another name is never dropped by accident — and never
     * pruned either. The migration that creates the table spells the same shape; keep the two in
     * step.
     */
    protected static function namePattern(): string
    {
        return '/^'.preg_quote(static::PARENT, '/').'_(\d{4})_(\d{2})$/';
    }

    /**
     * The same grammar as a POSIX regex, for the `pg_class` scan in :self::detachedTableNames().
     *
     * Derived from PARENT rather than written out, so the two spellings of one rule cannot drift —
     * which is the failure that would leave a detached-but-undropped partition invisible to the
     * prune command that created it.
     */
    protected static function relnameRegex(): string
    {
        return '^'.static::PARENT.'_[0-9]{4}_[0-9]{2}$';
    }

    public static function nameFor(CarbonImmutable $month): string
    {
        return static::PARENT.'_'.$month->startOfMonth()->format('Y_m');
    }

    /**
     * The month a partition name claims to cover, or null if the name is not one of ours.
     *
     * Null rather than an exception: the prune command walks the catalogue, and a relation someone
     * created by hand is a thing to skip and report, never a thing to drop and never a crash.
     */
    public static function monthOf(string $name): ?CarbonImmutable
    {
        if (preg_match(static::namePattern(), $name, $matches) !== 1) {
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
        $name = static::nameFor($month);
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
            static::PARENT,
            $start->format('Y-m-d H:i:sP'),
            $start->addMonth()->format('Y-m-d H:i:sP'),
        ));

        $this->afterPartitionCreated($name);

        return true;
    }

    /**
     * A hook that runs on every partition this class creates, forever — not once, at migration time.
     *
     * It exists because PRIVILEGES ARE NOT INHERITED THROUGH THE PARTITION HIERARCHY: access routed
     * through the parent checks the parent, but `UPDATE audit_logs_2026_08 SET …` checks the
     * PARTITION, and a fresh partition is owned by the role that created it with the owner's default
     * privileges. A subclass whose parent is append-only-by-GRANT re-applies the REVOKE here.
     *
     * Default: nothing. A table that is append-only by CONVENTION rather than by GRANT gets no
     * statement, and that is a decision the subclass states rather than one this base assumes.
     */
    protected function afterPartitionCreated(string $name): void
    {
        // Intentionally empty. See the docblock.
    }

    /**
     * Every live partition of the parent, by name, oldest first (the name sorts chronologically —
     * that is what the zero-padded `YYYY_MM` is for).
     *
     * Detach-pending partitions are EXCLUDED: they are half-way out of the hierarchy and the only
     * legal next step for one is FINALIZE, which :self::pendingDetachNames() serves.
     *
     * @return list<string>
     */
    public function partitionNames(): array
    {
        return $this->names(sprintf(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_inherits i
              JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = to_regclass('%s')
               AND NOT i.inhdetachpending
             ORDER BY c.relname
        SQL, static::PARENT));
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
        return $this->names(sprintf(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_inherits i
              JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = to_regclass('%s')
               AND i.inhdetachpending
             ORDER BY c.relname
        SQL, static::PARENT));
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
        return $this->names(sprintf(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'r'
               AND n.nspname = current_schema()
               AND c.relname ~ '%s'
               AND NOT EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhrelid = c.oid)
             ORDER BY c.relname
        SQL, static::relnameRegex()));
    }

    public function finalizeDetach(string $name): void
    {
        $this->assertPartitionName($name);

        $this->run(sprintf(
            'ALTER TABLE %s DETACH PARTITION %s FINALIZE',
            static::PARENT,
            $this->quoteIdentifier($name),
        ));
    }

    /**
     * Detach one partition without taking ACCESS EXCLUSIVE on the parent.
     *
     * CONCURRENTLY IS THE WHOLE POINT (`postgresql-patterns`). A plain DETACH takes ACCESS EXCLUSIVE
     * on the parent, which blocks READERS, and PostgreSQL's lock queue is ordered — so one
     * long-running query in front of it stalls every write that arrives behind it. The price is that
     * CONCURRENTLY cannot run inside a transaction block, which is why the command checks
     * :self::insideTransaction() before it starts.
     */
    public function detachConcurrently(string $name): void
    {
        $this->assertPartitionName($name);

        $this->run(sprintf(
            'ALTER TABLE %s DETACH PARTITION %s CONCURRENTLY',
            static::PARENT,
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
                "Refusing to drop {$name}: it is still an attached partition of ".static::PARENT
                .'. Detach it first — dropping a live partition deletes rows that are inside the '
                .'retention window.',
            );
        }

        $this->run('DROP TABLE IF EXISTS '.$this->quoteIdentifier($name));
    }

    /**
     * How many rows a partition holds.
     *
     * A real `count(*)`, not `pg_class.reltuples` (`postgresql-patterns` — an estimate autovacuum
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
     *
     * BOTH CONNECTIONS, and the answer is the conservative OR of them. `RefreshDatabase` opens its
     * transaction on the DEFAULT connection; when `pgsql_ddl` resolves to a distinct role it is a
     * SEPARATE PDO handle with its own transaction state, so asking only the DDL one reports 0
     * inside a Feature test and the command proceeds to run destructive DDL it was written to
     * refuse. Erring towards "yes, we are in a transaction" costs a refusal a caller can retry;
     * erring the other way costs an ACCESS EXCLUSIVE lock, or a DETACH that never rolls back.
     */
    public function insideTransaction(): bool
    {
        return Schema::getConnection()->transactionLevel() > 0
            || $this->connection()->transactionLevel() > 0;
    }

    /**
     * THE DDL CONNECTION — same server, same database, a role that may `CREATE` in `public` (D21).
     *
     * Every statement in this class is DDL or a catalogue read. Measured under the role split, as
     * the application role: `CREATE TABLE … PARTITION OF` fails at `permission denied for schema
     * public`, BEFORE any ownership check, because the app role holds USAGE and not CREATE.
     *
     * THE SAME CONNECTION OBJECT WHEN THE ROLES ARE THE SAME, and that is not an optimisation. A
     * second named connection is a second PDO handle with its own transaction. Where the split is
     * NOT deployed — every developer machine, the whole test suite — the two roles are identical, so
     * a second handle buys nothing and costs two things that both bite: `RefreshDatabase` wraps only
     * the default handle, so DDL issued on the other one is NOT rolled back and leaks partitions into
     * the test database; and `insideTransaction()` cannot see the ambient transaction.
     */
    protected function connection(): Connection
    {
        $ddlRole = config('database.connections.pgsql_ddl.username');
        $appRole = config('database.connections.pgsql.username');

        return $ddlRole === $appRole
            ? Schema::getConnection()
            : Schema::connection('pgsql_ddl')->getConnection();
    }

    /**
     * The gate every interpolated identifier passes.
     *
     * Deliberately an allow-list of one exact shape rather than an escaping routine: escaping is a
     * thing that can be got subtly wrong, and there is no legitimate partition name outside
     * `{parent}_YYYY_MM`.
     */
    protected function assertPartitionName(string $name): void
    {
        if (preg_match(static::namePattern(), $name) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to issue SQL for '{$name}': a ".static::PARENT.' partition name must '
                .'match '.static::namePattern().'. Identifiers cannot be bound as parameters, so an '
                .'unvalidated name here is a DDL injection.',
            );
        }
    }

    protected function quoteIdentifier(string $name): string
    {
        // Safe only because assertPartitionName() (or the PARENT constant) precedes every call. The
        // double quotes stay so a case-folding surprise is impossible.
        return '"'.$name.'"';
    }

    /**
     * @return list<string>
     */
    protected function names(string $sql): array
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

    protected function scalar(string $sql): mixed
    {
        $rows = $this->connection()->select($sql);
        $first = $rows[0] ?? null;

        if (! is_object($first)) {
            return null;
        }

        return get_object_vars($first)['value'] ?? null;
    }

    protected function run(string $sql): void
    {
        $this->connection()->statement($sql);
    }
}

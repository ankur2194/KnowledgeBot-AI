<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use InvalidArgumentException;

/**
 * The `audit_logs` partition catalogue — the only place in the application that names an
 * `audit_logs_*` relation.
 *
 * ── WHAT MOVED, AND WHAT DELIBERATELY DID NOT ────────────────────────────────────────────────
 *
 * Everything that is "monthly range partitioning over one relation" now lives in
 * `MonthlyPartitionRepository`, because `usage_events` is partitioned the same way for the same
 * reasons and duplicating it would duplicate `assertPartitionName()` — the one part that must not be
 * duplicated, because it is what stops a catalogue string being interpolated into a DROP. The base
 * class carries the reasoning for every choice in it: the `IF NOT EXISTS` on top of a catalogue
 * check, `DETACH … CONCURRENTLY`'s lock argument, why `rowCount()` is a real `count(*)`, why
 * `insideTransaction()` ORs two connections, and why the DDL connection is the same object when the
 * roles are the same.
 *
 * WHAT STAYED IS THE APPEND-ONLY GRANT AND THE CHECK THAT PROVES IT, and it stayed because it is
 * NOT a property of partitioning — it is `kb-security-baseline` §18.11's requirement for the
 * compliance record specifically. `usage_events` is append-only by convention and by having no
 * writer that updates; `audit_logs` is append-only by `REVOKE`, and the difference is deliberate.
 * Moving the REVOKE into the base would have applied a security control to a table nobody decided
 * it for, which is how a control acquires a scope nobody can justify later.
 *
 * ── THE PARTITION-NAME GRAMMAR IS DERIVED FROM `PARENT`, NOT RESTATED ────────────────────────
 *
 * `MonthlyPartitionRepository::namePattern()` builds `/^audit_logs_(\d{4})_(\d{2})$/` from the
 * constant below, and `relnameRegex()` builds the POSIX twin the `pg_class` scan uses. The migration
 * that creates the table spells the same shape by hand; keep the two in step.
 */
final class EloquentAuditLogPartitionRepository extends MonthlyPartitionRepository
{
    public const PARENT = 'audit_logs';

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
     * THE HOOK, AND IT HAS TO RUN EVERY MONTH FOREVER RATHER THAN ONCE.
     *
     * A fresh partition is OWNED by the role that created it, with the owner's default privileges —
     * so it is UPDATE-able and DELETE-able directly by name even though the parent is not.
     * Privileges are not inherited through the partition hierarchy: access routed through the parent
     * checks the parent, but `UPDATE audit_logs_2026_08 SET …` checks the partition. The migration
     * says the same thing at more length; this is the half that runs on a schedule.
     */
    protected function afterPartitionCreated(string $name): void
    {
        $this->revokeWrites($name);
    }

    /**
     * See the migration's revokeWrites() for the full accounting. In short: CURRENT_USER is the
     * application role in this deployment, partitions do not inherit the parent's privileges, and
     * TRUNCATE is knowingly left alone on the parent.
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
     * name cannot be bound as a parameter. Same gate and same reason as `quoteIdentifier()`.
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
}

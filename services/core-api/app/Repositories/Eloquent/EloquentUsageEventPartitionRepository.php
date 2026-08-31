<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

/**
 * The `usage_events` partition catalogue — the only place in the application that names a
 * `usage_events_*` relation.
 *
 * Everything it does lives in `MonthlyPartitionRepository`, which carries the reasoning for each
 * choice: why the name is a contract the prune command parses back, why `assertPartitionName()`
 * gates every interpolated identifier including the ones read out of `pg_class`, why the detach is
 * CONCURRENTLY, and why `insideTransaction()` ORs two connections.
 *
 * ── ONE THING IT DELIBERATELY DOES NOT DO, AND THE ABSENCE IS A DECISION ─────────────────────
 *
 * NO `REVOKE UPDATE, DELETE` ON THE PARENT OR ON A NEW PARTITION. `EloquentAuditLogPartitionRepository`
 * does exactly that on every partition it creates, and it is tempting to mirror it here because
 * `usage_events` is append-only too. It is not mirrored, for a reason worth stating rather than
 * leaving to look like an oversight:
 *
 *   THE AUDIT REVOKE IS `kb-security-baseline` §18.11's REQUIREMENT FOR THE COMPLIANCE RECORD, not a
 *   property of partitioning and not a house style. Nothing states an equivalent for a usage ledger,
 *   and applying a security control to a table nobody decided it for is how a control acquires a
 *   scope no one can justify later — and, more practically, how a future correction path (a credit,
 *   a reprice) finds itself blocked by a GRANT whose reason nobody can reconstruct.
 *
 *   The measured value would also be nil today: 2026_08_13_001000 records that the REVOKE lands, the
 *   ACL shows no UPDATE, and `UPDATE audit_logs SET …` SUCCEEDS ANYWAY, because Compose's
 *   `POSTGRES_USER` is a superuser and superusers bypass every ACL check. So the audit REVOKE is
 *   currently enforced by being AUDITABLE, not by the database.
 *
 * What makes this table append-only in practice is that `App\Services\Usage\UsageRecorder` is its
 * only writer and issues only INSERTs, and that a correction is expressed as a new row of the
 * opposite-direction type (`storage.bytes.removed`) rather than as an UPDATE. If that ever stops
 * being true, this class is where the GRANT would go and the decision above is what has to be
 * revisited.
 */
final class EloquentUsageEventPartitionRepository extends MonthlyPartitionRepository
{
    public const PARENT = 'usage_events';
}

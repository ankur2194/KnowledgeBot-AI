<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogFilter;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * audit_logs: one writer, and — since Phase 6a — one reader.
 *
 * THIS DOCBLOCK USED TO SAY "there is no read side yet, and adding one is a change to this interface
 * rather than a query somewhere else". The read side is `paginate()` below, added here rather than
 * anywhere else for exactly that reason: `AuditLog` carries no `#[ScopedBy]` — the scope's predicate
 * is FALSE for the NULL-org platform rows and would hide them from every surface forever — so the
 * explicit `organization_id` argument on this method is the ONLY tenancy this table has, and a
 * second query somewhere else would be a second place to forget it.
 *
 * WHY AN INTERFACE FOR A ONE-METHOD WRITER. Two reasons, and neither is symmetry. It is what lets
 * tests/Unit/AuditLoggerTest.php exercise the allow-list — the part of this unit that decides
 * whether a credential reaches an append-only table — with no database, no container and no
 * facades, which is the only shape Unit/ admits. And it is what keeps App\Services\Audit\AuditLogger
 * free of Eloquent, so the audit decision and the audit write are separately reviewable.
 *
 * `write()` takes the already-sanitized values. It performs no filtering of its own on purpose:
 * two places that both redact are two places that can disagree about what redaction means, and the
 * one that is wrong is the one nobody tested.
 */
interface AuditLogRepositoryInterface
{
    /**
     * Append one row.
     *
     * @param  array<string, scalar>  $details  already allow-listed and fingerprinted by AuditLogger
     */
    public function write(
        string $operation,
        string $outcome,
        ?string $organizationId,
        ?string $actorId,
        ?string $subjectType,
        ?string $subjectId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
        array $details,
    ): AuditLog;

    /**
     * One page of ONE organization's audit trail.
     *
     * ── WHAT AN ORG-SCOPED READER SEES FOR A PLATFORM-SCOPE ROW: NOTHING, BY DECISION ────────
     *
     * `organization_id` is nullable and a NULL means the event belongs to no tenant — a failed login
     * for an address that is not a user, a platform-scope action. Those rows are excluded from every
     * result of this method, and that is the correct answer rather than an accident of SQL: they
     * describe people who may not be members of this organization at all, so including them would be
     * a cross-tenant read wearing a null.
     *
     * IT IS WRITTEN DOWN HERE BECAUSE THE SQL ANSWERS IT BY ACCIDENT. `organization_id = ?` is
     * already false for a NULL under three-valued logic, so the exclusion happens whether or not
     * anybody decided it — which means a future `orWhereNull(...)` "so operators can see login
     * failures" would look like a feature request rather than like the boundary change it is.
     * `tests/Security/AuditTrailAccessTest.php` pins the direction with a platform row it plants on
     * purpose.
     *
     * §6.1 gives "Access platform-level audit logs" to the PLATFORM OWNER, and
     * `audit_logs_platform_created` is the partial index that exists to serve that surface when it
     * is built. It is not this one.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(
        string $organizationId,
        AuditLogFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator;
}

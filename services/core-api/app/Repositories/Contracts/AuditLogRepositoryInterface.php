<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AuditLog;

/**
 * The write side of audit_logs. There is no read side yet, and adding one is a change to this
 * interface rather than a query somewhere else.
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
}

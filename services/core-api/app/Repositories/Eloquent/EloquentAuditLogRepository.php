<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;

/**
 * The only class that inserts into audit_logs.
 *
 * EVERY ATTRIBUTE IS ASSIGNED EXPLICITLY, and never through create()/fill()/forceFill(). AuditLog
 * has an empty $fillable so mass assignment would be refused anyway; the point of writing it out is
 * that the column list of the audit table is visible in one screen, which is what makes "does
 * anything here carry a secret" a question a reviewer can answer.
 *
 * `details` IS OMITTED WHEN EMPTY rather than set to []. The column is
 * `jsonb NOT NULL DEFAULT '{}'::jsonb` with a `jsonb_typeof(details) = 'object'` CHECK, and
 * Eloquent's `array` cast encodes an empty PHP array as `[]` — a JSON ARRAY, which that CHECK
 * rejects (correctly: one array-shaped row makes every `details->>'…'` predicate silently skip it).
 * Leaving the attribute unset lets the column default produce `{}`.
 */
final class EloquentAuditLogRepository implements AuditLogRepositoryInterface
{
    /**
     * @param  array<string, scalar>  $details
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
    ): AuditLog {
        $log = new AuditLog;

        $log->operation = $operation;
        $log->outcome = $outcome;
        $log->organization_id = $organizationId;
        $log->actor_id = $actorId;
        $log->subject_type = $subjectType;
        $log->subject_id = $subjectId;
        $log->ip_address = $ipAddress;
        $log->user_agent = $userAgent;
        $log->request_id = $requestId;

        if ($details !== []) {
            $log->details = $details;
        }

        // save() on a NEW model is an INSERT, which the grant permits. AuditLog::performUpdate()
        // refuses the other branch, so this can never silently become an UPDATE on a model that was
        // loaded rather than constructed.
        $log->save();

        return $log;
    }
}

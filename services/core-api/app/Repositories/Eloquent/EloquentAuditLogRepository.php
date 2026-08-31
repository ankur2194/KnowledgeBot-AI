<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Services\Audit\AuditLogFilter;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only class that inserts into audit_logs, and the only one that reads them for a tenant.
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

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(
        string $organizationId,
        AuditLogFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator {
        $rows = AuditLog::query()
            // ══ THE ONLY TENANCY LAYER THIS TABLE HAS ═════════════════════════════════════════
            //
            // `AuditLog` deliberately carries NO `#[ScopedBy(OrganizationScope::class)]` — the
            // scope appends `organization_id = ?`, which is FALSE for the NULL-org platform rows,
            // so attaching it would make every one of them unreachable through Eloquent forever,
            // from every surface, with no error. See the model's own docblock. There is therefore
            // NOTHING under this line: delete it and this endpoint returns the whole platform's
            // audit trail to any member of any organization, at normal latency, in a well-formed
            // envelope.
            //
            // IT ALSO DECIDES, AS A SIDE EFFECT OF SQL'S THREE-VALUED LOGIC, WHAT AN ORG-SCOPED
            // READER SEES FOR A PLATFORM-SCOPE ROW: nothing, because `NULL = '01J…'` is NULL and
            // not TRUE. That is the intended answer and the interface states it as a decision
            // rather than leaving it to be re-derived from this line.
            ->where('audit_logs.organization_id', '=', $organizationId)
            // ── the four filters the endpoint publishes ──────────────────────────────────────
            //
            // `audit_logs_org_actor_created (organization_id, actor_id, created_at DESC) WHERE
            // actor_id IS NOT NULL`: the predicate below is equality, which PROVES the partial
            // index's own condition, so the index is usable.
            ->when(
                $filter->actorId !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.actor_id', '=', $filter->actorId),
            )
            // `operation` and `outcome` HAVE NO INDEX AND DO NOT GET ONE. Both are closed
            // vocabularies with a few dozen and two members respectively, so neither is selective;
            // they are applied on top of whichever org-leading index the other predicates chose,
            // which bounds the scan to ONE organization's rows in the window. A composite index per
            // low-cardinality filter on an append-only table written by every state change is a
            // write cost paid on every request to narrow a set that is already tenant-bounded.
            ->when(
                $filter->operation !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.operation', '=', $filter->operation),
            )
            ->when(
                $filter->outcome !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.outcome', '=', $filter->outcome),
            )
            // `audit_logs_org_subject_created (organization_id, subject_type, subject_id,
            // created_at DESC) WHERE subject_id IS NOT NULL` — "the audit trail for THIS record",
            // the query that index was created for. WITH BOTH terms the leading three columns are
            // constrained and the DESC suffix already satisfies the default sort, so the read is an
            // index range. WITH ONLY `subject_type` it is not used at all: a partial index requires
            // the query to prove its predicate and `subject_type = ?` does not imply `subject_id IS
            // NOT NULL`, so that shape falls to `audit_logs_org_created` plus a filter. The reverse
            // pairing — an id with no type — is refused before it reaches here, by the FormRequest
            // and again by `AuditLogFilter`'s constructor.
            ->when(
                $filter->subjectType !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.subject_type', '=', $filter->subjectType),
            )
            ->when(
                $filter->subjectId !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.subject_id', '=', $filter->subjectId),
            )
            // BOUND AS PRE-RENDERED STRINGS AND NEVER AS CARBON OBJECTS — Laravel's PostgreSQL
            // grammar drops the microseconds, and on a half-open upper bound that silently excludes
            // everything in the current second. `App\Support\Database\SqlTimestamp` carries the
            // measurement behind that sentence.
            ->when(
                $filter->fromBound !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.created_at', '>=', $filter->fromBound),
            )
            ->when(
                $filter->untilBound !== null,
                fn (Builder $b): Builder => $b->where('audit_logs.created_at', '<', $filter->untilBound),
            )
            ->orderBy('audit_logs.'.$query->sort, $query->direction->value)
            // THE TIE-BREAK, and on this table it is doing real work rather than covering an
            // unlikely collision: `created_at` is written by Eloquent from one `Carbon::now()` per
            // request, so several rows from one action share it exactly. Without a total order a
            // page boundary would repeat or drop rows in the middle of an audit trail — the one
            // place a missing row is worse than a duplicate.
            //
            // IT FOLLOWS THE REQUESTED DIRECTION rather than being a bare ascending `orderBy('id')`
            // — which is what `EloquentKnowledgeSourceRepository::paginate()` does, and the
            // difference is deliberate. `id` is a ULID, so it carries the same ordering as
            // `created_at`; a fixed ASC tie-break under a DESC sort makes the rows that SHARE an
            // instant come back oldest-first inside a newest-first page, which on an audit trail is
            // a sequence of related rows displayed backwards. Either form gives the total order
            // pagination needs; only this one is monotone.
            ->orderBy('audit_logs.id', $query->direction->value)
            ->paginate(perPage: $query->perPage, page: $query->page);

        /** @var LengthAwarePaginator<int, AuditLog> $rows */
        return $rows;
    }
}

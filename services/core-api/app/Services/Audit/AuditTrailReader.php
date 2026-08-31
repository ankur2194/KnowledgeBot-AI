<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Support\Http\ListQuery;
use Illuminate\Container\Attributes\Give;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The read half of `audit_logs`, kept apart from `AuditLogger` on purpose.
 *
 * ── WHY A SECOND CLASS AND NOT A METHOD ON `AuditLogger` ────────────────────────────────────
 *
 * `AuditLogger` is the class that decides WHETHER A CREDENTIAL REACHES AN APPEND-ONLY TABLE: the
 * per-operation `details` allow-list, the echo/fingerprint split, the drop reporting, the
 * write-failure policy. It is reviewed as one unit and `tests/Unit/AuditLoggerTest.php` walks it
 * with no database. Adding a paginated read to it would put a `LengthAwarePaginator` in the
 * constructor's blast radius and give the class two audiences, and the first thing that happens to
 * a class with two audiences is that a reader's convenience method starts shaping the writer.
 *
 * The two share the repository INTERFACE rather than a service, which is where the sharing belongs:
 * `AuditLogRepositoryInterface` is the one place `organization_id` is applied to this table.
 *
 * ── THE BINDING IS `#[Give]`, MATCHING `AuditLogger` ────────────────────────────────────────
 *
 * `AuditLogRepositoryInterface` has no `AppServiceProvider` binding — `AuditLogger` resolves its
 * implementation with the same attribute, and the interface exists so the unit suite can substitute
 * a spy rather than so the container can choose between two implementations. There is exactly one
 * implementation and there is not going to be a second.
 *
 * ── NO AUDIT ROW IS WRITTEN FOR READING THE AUDIT TRAIL ─────────────────────────────────────
 *
 * §18.11's list is credential changes, user and role changes, bot publish and configuration
 * changes, source lifecycle, exports and retention changes. A read is none of them, and auditing
 * one here would be self-referential in the direction that hurts: every page turn appends a row to
 * the table being paged, so the trail grows while it is being read and the newest page is mostly a
 * record of somebody looking at it.
 *
 * AN EXPORT IS DIFFERENT AND IS NOT THIS ENDPOINT. §18.11 names exports explicitly, so a CSV or a
 * bulk download of this table is an audited operation — it just is not a paginated read, and the
 * distinction is the one that keeps the audit useful.
 */
final class AuditTrailReader
{
    public function __construct(
        // No service-provider binding needed — see the class docblock.
        #[Give(EloquentAuditLogRepository::class)]
        private readonly AuditLogRepositoryInterface $repository,
    ) {}

    /**
     * One page of this organization's audit trail.
     *
     * The organization is taken from the ROUTE-BOUND record through `OrgOwned::organizationId()`,
     * never from request input, and it is passed positionally into the repository because on this
     * table that argument is the whole of the tenancy — `AuditLog` carries no `#[ScopedBy]`, for
     * reasons its own docblock sets out at length.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function list(
        Organization $organization,
        AuditLogFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator {
        return $this->repository->paginate($organization->organizationId(), $filter, $query);
    }
}

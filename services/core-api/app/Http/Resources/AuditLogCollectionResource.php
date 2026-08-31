<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PaginatedCollection;
use App\Models\AuditLog;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PAGE of an organization's audit trail.
 *
 * ── THE BODY, EXACTLY ─────────────────────────────────────────────────────────────────────────
 *
 *     { "data": { "audit_logs": [ …rows… ],
 *                 "meta": { "page": 1, "per_page": 25, "total": 4291, "total_pages": 172,
 *                           "sort": "created_at", "dir": "desc", "filter": null } } }
 *
 * `meta` is a SIBLING OF THE COLLECTION INSIDE `data` — the shape `apps/web/src/lib/table/
 * envelope.ts` reads and THROWS rather than degrading when it cannot.
 *
 * `meta.filter` IS ALWAYS NULL and that is declared rather than accidental:
 * `IndexAuditLogsRequest` passes `freeText: false`, because every filter this endpoint offers is an
 * equality or a range over an indexed column and the only free-text targets the table has are
 * `user_agent` and the `details` jsonb — where an `ILIKE '%term%'` is a sequential scan of a
 * partitioned append-only table that grows with every state change in the platform. The key stays
 * present because `meta` is the SHARED `ListMetaResource` component.
 *
 * ── THIS COLLECTION IS THE WHOLE SURFACE; THERE IS NO ROW ENDPOINT ──────────────────────────
 *
 * `GET .../audit-logs/{auditLog}` is deliberately not built. It would need a route-model binding
 * over a table whose primary key is the composite `(id, created_at)` a partitioned table requires
 * and whose model has no `#[ScopedBy]` backstop, so the binding would resolve a row with no tenant
 * predicate and hand it to a policy that THROWS on a platform-scope row. Filtering the list to one
 * subject or one actor answers every question a row endpoint would, through the one query shape
 * that carries the organization predicate.
 *
 * @property-read LengthAwarePaginator<int, AuditLog> $resource
 */
final class AuditLogCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    use PaginatedCollection;

    /**
     * The response key, and it is the TABLE NAME rather than a shortened noun.
     *
     * `sources`, `bots` and `conversations` are all the plural of the thing; "audit logs" has no
     * shorter true plural — `audits` would name the activity rather than the rows, and `logs` would
     * collide with the telemetry sense of the word on a surface whose whole job is to be the store
     * that telemetry is NOT.
     */
    private const KEY = 'audit_logs';

    /**
     * @param  LengthAwarePaginator<int, AuditLog>  $resource
     */
    public function __construct(
        LengthAwarePaginator $resource,
        private readonly ListQuery $query,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // `->items()` and not the paginator itself: the envelope publishes an ARRAY under its
            // key, and a paginator serialized directly would carry Laravel's own `links`/`meta`
            // shape, which is not the one this API publishes.
            self::KEY => array_map(
                fn (AuditLog $log): array => (new AuditLogResource($log))->toArray($request),
                array_values($this->resource->items()),
            ),
            'meta' => $this->listMeta($this->resource, $this->query, $request),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return AuditLogResource::openApiSchemas() + self::listEnvelopeSchemas(
            component: 'AuditLogCollectionResource',
            key: self::KEY,
            itemComponent: 'AuditLogResource',
            description: 'One page of an organization\'s audit trail, newest first by default, with '
                .'the pagination and applied-query state beside it. `meta.filter` is always null: '
                .'this endpoint accepts no free-text term, and the key remains because `meta` is '
                .'one shared component across every list in this API.',
            itemsDescription: 'The audited events on this page. SCOPED TO THE ORGANIZATION IN THE '
                .'PATH AND TO NOTHING ELSE — platform-scope rows, which carry no organization (a '
                .'failed login for an address that belongs to no user, a platform action), are '
                .'never returned here whatever the filters say. §6.1 assigns those to the platform '
                .'owner, on a surface that does not exist yet.',
        );
    }
}

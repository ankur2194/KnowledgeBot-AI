<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAuditLogsRequest;
use App\Http\Resources\AuditLogCollectionResource;
use App\Models\Organization;
use App\Services\Audit\AuditTrailReader;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's own audit trail (§18.11).
 *
 * ── ONE ACTION, AND THE ABSENCE OF A SECOND IS A DECISION ────────────────────────────────────
 *
 * There is no `show`. A row endpoint would need a route-model binding over a table whose primary
 * key is the composite `(id, created_at)` a partitioned table requires and whose model deliberately
 * carries NO `#[ScopedBy]` — so the binding would resolve a row with no tenant predicate and hand
 * it to a policy that THROWS on a platform-scope row. Filtering the list to one actor or one
 * subject answers every question a row endpoint would, through the one query shape that carries the
 * organization predicate. There is no write path either, in any phase: `AuditLogger` is the only
 * writer, the migration REVOKES UPDATE and DELETE on the table and on every partition from the
 * application role, and the model refuses both in PHP first so the failure is a sentence rather
 * than SQLSTATE 42501.
 *
 * ══ WHAT AN ORG-SCOPED READER SEES FOR A PLATFORM-SCOPE ROW: NOTHING ═══════════════════════
 *
 * `audit_logs.organization_id` is NULLABLE, and a NULL is not a gap — it is an event that belongs to
 * no tenant: a failed login for an address that is not a user, a platform-scope action. Those rows
 * are excluded from this endpoint, always, whatever the filters say. Including them would be a
 * cross-tenant read wearing a null: they describe people who may not be members of this
 * organization at all.
 *
 * IT IS WRITTEN DOWN IN THREE PLACES BECAUSE THE SQL ANSWERS IT BY ACCIDENT. `organization_id = ?`
 * is already false for a NULL under three-valued logic, so the exclusion happens whether or not
 * anybody decided it — which means a future "let operators see login failures too" would arrive as
 * a one-line `orWhereNull()` that looks like a feature and is a boundary change.
 * `AuditLogRepositoryInterface::paginate()` states the decision, the Eloquent repository states it
 * at the predicate, and `tests/Security/AuditTrailAccessTest.php` plants a platform row and pins the
 * direction. §6.1 assigns platform-level audit logs to the PLATFORM OWNER, and
 * `audit_logs_platform_created` is the partial index waiting for that surface.
 *
 * ══ NON-NEGOTIABLE 9 ON A READ PATH ════════════════════════════════════════════════════════
 *
 * No credential can appear in a `details` blob because none was ever written: `AuditLogger`
 * allow-lists `details` PER OPERATION and every bearer value is admitted only as a keyed
 * `<name>_fingerprint`. THE OBLIGATION OF THIS SURFACE IS NOT TO WIDEN THAT — `AuditLogResource`
 * renders `details` whole and enriches nothing, so no field reaches a client that the write-side
 * allow-list never reviewed. Proven against a real sealed fixture credential in
 * `tests/Security/AuditTrailAccessTest.php`: seal, rotate, read this endpoint, grep the serialized
 * body for the plaintext, its last four and the actor's password.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ─────────────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which re-reads `organization_users` on every request.
 * 3. ROLE / PERMISSION — `Gate::authorize('viewAuditLog', $organization)`, demanding `audit.view`,
 *    which the Organization Owner and the Administrator hold and the Knowledge Manager and the
 *    Analyst do not. `Permission::AuditView` carries the reasoning, including that the grant is an
 *    EXTENSION of the specification: §6.1 gives "Access platform-level audit logs" to the platform
 *    owner and §6.2-§6.5 name no org role.
 * 4. ENTITY OWNERSHIP — the organization is the record, resolved through `OrgOwned`, and the
 *    repository takes `organization_id` positionally. On this table that argument is the ONLY
 *    tenancy layer: there is no global scope underneath it.
 * 5. ENTITY STATUS — DELIBERATELY NONE. A suspended organization's operator needs its audit trail
 *    more than an active one's does; that is what an incident looks like. Nothing here writes, so
 *    there is no state a 409 would protect.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`.
 *
 * ── READING THE TRAIL IS NOT ITSELF AUDITED ────────────────────────────────────────────────
 *
 * §18.11's list is credential changes, user and role changes, bot publish and configuration
 * changes, source lifecycle, EXPORTS and retention changes. A paginated read is none of them, and
 * auditing one here would be self-referential in the direction that hurts: every page turn would
 * append a row to the table being paged, so the newest page becomes mostly a record of somebody
 * looking at it. An EXPORT of this table is different and IS on §18.11's list — it is just not this
 * endpoint. `AuditTrailReader`'s docblock carries the same distinction.
 */
final class AuditLogController extends Controller
{
    /**
     * ONE PAGE of this organization's audited events, newest first.
     *
     * ── THE ENVELOPE IS FIXED AND IS NOT THIS ACTION'S TO RESHAPE ────────────────────────────
     *
     *     {"data": {"audit_logs": [...], "meta": {page, per_page, total, total_pages, sort, dir, filter}}}
     *
     * ── `operation` AND `outcome` ARE CLOSED; `subject_type` IS NOT ──────────────────────────
     *
     * A filter value outside `AuditLogger::OPERATIONS` is a 422 and not an empty page, because the
     * writer refuses to record an operation outside that map — so such a value cannot match a row
     * that will ever exist, and answering with an empty list would let a caller read "this never
     * happened" out of a typo. `subject_type` is the opposite and deliberately so: the column has
     * no CHECK, because "the set is open and a refused audit row is worse than a row nobody wrote
     * rules for", so closing it on the read side would 422 a legitimate query the day a new kind of
     * record is first audited.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AuditLogCollectionResource::class],
        description: 'One page of this organization\'s audit trail, with `meta` beside the array '
            .'inside `data`. NEWEST FIRST by default — an audit trail is read from the present '
            .'backwards, and every index on the table is built `created_at DESC` for exactly that. '
            .'PLATFORM-SCOPE ROWS ARE NEVER RETURNED: an event that belongs to no organization '
            .'(a failed login for an address that is not a user) is outside this surface whatever '
            .'the filters say, because it describes somebody who may not be a member here. '
            .'`details` is rendered whole and is allow-listed PER OPERATION at write time, so no '
            .'credential, token or key fragment can appear in it — a bearer value is admitted only '
            .'as `<name>_fingerprint`. 422 for an `operation` or `outcome` outside its closed '
            .'vocabulary, and for a `subject_id` sent without a `subject_type`. `meta.filter` is '
            .'always null: this endpoint takes no free-text term.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function index(
        IndexAuditLogsRequest $request,
        Organization $organization,
        AuditTrailReader $trail,
    ): AuditLogCollectionResource {
        // CHECKS 3 AND 4. The organization IS the record here — there is no per-row policy on this
        // table, because `AuditLog::organizationId()` throws on a platform-scope row and a policy
        // that could only be reached through an exception is not a policy.
        Gate::authorize('viewAuditLog', $organization);

        $query = $request->toQuery();

        // No 409 — see the class docblock, check 5.
        return new AuditLogCollectionResource(
            $trail->list($organization, $request->toFilter(), $query),
            $query,
        );
    }
}

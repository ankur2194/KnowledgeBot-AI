<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuotaLimitsRequest;
use App\Http\Resources\QuotaResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Quotas\QuotaGate;
use App\Services\Quotas\QuotaLimitService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's four quota ceilings: read them, and set them.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4), and they differ between the two actions:
 *   1. authenticated identity        `auth:sanctum` on the route group — both actions
 *   2. organization membership       `org.member`, which RE-READS the row from PostgreSQL — both
 *   3. role / permission             Gate::authorize() below. `show` demands `analytics.view`;
 *                                    `update` demands `quotas.manage`, which the Organization Owner
 *                                    alone holds among the four org roles.
 *   4. entity ownership              the policy resolves membership of THE RECORD's organization,
 *                                    and `->scopeBindings()` 404s a foreign `{organization}` before
 *                                    any of it runs — both actions
 *   5. entity status                 `abort_unless` on OrganizationStatus in `update` ONLY. `show`
 *                                    carries none, deliberately: reading how much of an allowance is
 *                                    left is exactly what a suspended organization's operator needs
 *                                    to do, and the action writes nothing.
 *   6. rate limit                    `throttle:admin` on the route group — both actions
 *
 * Checks 5 and 6 are the ones reviewers forget, because 1 through 4 are visible in the route file
 * and these two are not.
 *
 * ── THERE IS A SEVENTH CHECK ON `update`, AND IT IS NOT ONE OF THE SIX ──────────────────────
 *
 * `QuotaLimitService` refuses a RAISE from anyone who is not `users.is_platform_owner`. It is not
 * check 3 wearing a different hat: `OrgScopedPolicy::permit()` takes `(user, record, permission)`
 * and has no argument position for the DIRECTION of a change, which is a comparison between the
 * submitted numbers and the persisted ones. The house split puts it in the service — the FormRequest
 * validates shape, the Policy decides permission, the Service decides behaviour — and the service's
 * docblock carries the §6.1-versus-§6.2 contradiction that makes the guard necessary at all.
 *
 * ── WHY `show` IS `analytics.view` AND NOT `quotas.manage` ──────────────────────────────────
 *
 * Reading how much of an allowance is used is a REPORT, and it is the same number
 * `AnalyticsResource.storage_bytes_used` already publishes to every holder of `analytics.view`.
 * Gating the read behind the WRITE permission would mean only the Organization Owner could see a
 * figure that is already on the dashboard, which is a difference nobody could act on and a rule the
 * next reader would delete as inconsistent.
 */
final class QuotaController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => QuotaResource::class],
        description: 'Every quota metric for one organization: used, limit, remaining. Wrapped in '
            .'`data` because the action returns the Resource itself and Laravel wraps it. A '
            .'nonexistent `{organization}` 404s at binding time, before this action runs.',
        // No 422: there is no request body and no query string to reject. No 409: this action
        // writes nothing and a suspended organization may read it.
        errors: [401, 403, 404, 429, 500],
    )]
    public function show(Organization $organization, QuotaGate $quotas): QuotaResource
    {
        Gate::authorize('viewQuotas', $organization);

        return new QuotaResource($organization, $quotas->snapshot($organization));
    }

    /**
     * Replace all four ceilings.
     *
     * A PUT rather than a PATCH because the four are a COMPLETE SET: on this body an omitted key
     * and a null key would otherwise mean the same thing, and they mean opposite things — "leave it
     * alone" and "remove this ceiling entirely". `UpdateQuotaLimitsRequest` puts `present` on every
     * field for exactly that reason.
     *
     * 403 HAS TWO PRODUCERS HERE and they carry the same `error_class`: the policy refusing a caller
     * who does not hold `quotas.manage`, and `QuotaLimitService` refusing a RAISE from a caller who
     * is not a platform owner. Nothing branches on the status (`kb-error-taxonomy` footnote 1); the
     * two are distinguished by their message, which is what an operator reads.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => QuotaResource::class],
        description: 'The ceilings AFTER they were applied, with usage recomputed. Wrapped in '
            .'`data`. A nonexistent `{organization}` 404s at binding time.',
        errors: [401, 403, 404, 409, 422, 429, 500],
    )]
    public function update(
        UpdateQuotaLimitsRequest $request,
        Organization $organization,
        QuotaLimitService $limits,
        QuotaGate $quotas,
    ): JsonResponse {
        Gate::authorize('manageQuotas', $organization);

        // CHECK 5. A suspended organization's plan is not editable from inside it. This is the
        // stricter of the two directions and it is deliberate: the only edit that would matter on a
        // suspended organization is RAISING a ceiling, which is the act §6.1 assigns to the platform
        // owner and which a suspension is frequently the consequence of.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $written = $limits->apply(
            $organization,
            $request->toLimits(),
            $this->actor(),
            $request,
        );

        // THE ORGANIZATION THE WRITE RETURNED, NOT THE BOUND ONE. `apply()` returns the row as it
        // stands after the transaction; the bound `$organization` still holds the pre-write columns,
        // so rendering off it would echo the operator's PREVIOUS ceilings back at them on the very
        // response that changed them.
        return (new QuotaResource($written, $quotas->snapshot($written)))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The authenticated user, for the audit row's `actor_id` AND for the platform-owner check.
     *
     * IT IS THE USER AND NOT THE ID, unlike every other controller on this surface, because
     * `QuotaLimitService` needs `is_platform_owner` off the model. Never used as a scope — the
     * organization is.
     */
    private function actor(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }
}

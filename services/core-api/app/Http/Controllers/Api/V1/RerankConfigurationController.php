<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignateRerankConnectionRequest;
use App\Http\Resources\RerankConfigurationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Rerank\RerankDesignation;
use App\Services\Rerank\RerankDesignationService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's rerank designation: which connection and model score retrieval candidates.
 *
 * THE THIRD PER-SURFACE PROVIDER CHOICE. `bots.provider_connection_id` decides who answers,
 * `organizations.embedding_connection_id` decides who embeds, and these two actions decide who
 * reranks. Before them "use NVIDIA NIM for reranking and OpenAI for chat" was not expressible
 * anywhere in the platform.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4) for both actions:
 *   1. authenticated identity        `auth:sanctum` on the route group
 *   2. organization membership       `org.member`, which RE-READS the row from PostgreSQL
 *   3. role / permission             Gate::authorize() below — providers.view / providers.manage
 *   4. entity ownership              the policy resolves membership of THE RECORD's organization,
 *                                    and `->scopeBindings()` 404s a foreign `{organization}` before
 *                                    any of it runs
 *   5. entity status                 `abort_unless` on OrganizationStatus in `update`, carrying
 *                                    OrganizationStatus::SUSPENDED_REFUSAL as the message — a 409
 *                                    with an EMPTY message renders as `internal_dependency`'s class
 *                                    copy, which is false twice for a suspension. `show` carries
 *                                    NONE, deliberately: reading the configuration is exactly what
 *                                    a suspended organization's operator needs to do.
 *   6. rate limit                    `throttle:admin` on the route group
 *
 * Checks 5 and 6 are the ones reviewers forget, because 1 through 4 are visible in the route file
 * and these two are not.
 *
 * ── NEITHER ACTION CROSSES THE SEAM, AND THAT IS THE DESIGN ────────────────────────────────────
 *
 * EmbeddingConfigurationController calls the data plane on both actions, because "can this
 * organization embed" is a resolution rule that lives over there and its answer BLOCKS ingestion.
 * There is no equivalent question here. Whether the designated vendor can usefully rerank is
 * decided per query by `rerank_gate`, before any provider call goes out, and reported on the
 * retrieval trace as `rerank_skip_reason`; every one of its outcomes is servable, because a skipped
 * rerank falls through to `evidence.select_unranked` and fused order. So there is nothing here to
 * block a write ON, and a readiness round trip would be latency spent on a verdict that never
 * changes the outcome of the request making it. RerankDesignationService carries the full argument,
 * including what would have to be decided before an admin-screen warning could be added.
 */
final class RerankConfigurationController extends Controller
{
    /**
     * The stored designation.
     *
     * A PURE READ OF `organizations`, off the row the route binding already resolved and the tenant
     * middleware already proved belongs to this caller. No service call, no query, no cross-seam
     * request — which is why there is no 503 in the error list and why a suspended organization is
     * not refused.
     *
     * No 422: there is no request body to reject.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => RerankConfigurationResource::class],
        description: 'The organization\'s rerank designation. Wrapped in `data` because the action '
            .'returns the Resource itself and Laravel wraps it. A nonexistent `{organization}` '
            .'404s at binding time, before this action runs.',
        errors: [401, 403, 404, 429, 500],
    )]
    public function show(Organization $organization): RerankConfigurationResource
    {
        // CHECKS 3 AND 4. The record IS the organization, which already implements OrgOwned, so
        // permit() still resolves membership of the record's organization and "admin of some
        // organization" stays unrepresentable.
        Gate::authorize('viewRerankConfiguration', $organization);

        // The STORED pair off the bound row. Nothing on this path writes, so the bound organization
        // IS the current one.
        return new RerankConfigurationResource(
            $organization,
            RerankDesignation::fromOrganization($organization),
        );
    }

    /**
     * Set or clear the designation.
     *
     * A PUT rather than a PATCH because the designation is a PAIR and the two halves are
     * meaningless apart — a partial update of one of them is a state the database CHECK rejects
     * anyway. Clearing is expressed as `{"connection_id": null, "model": null}` rather than as a
     * DELETE, because turning reranking off is a supported operating mode rather than the removal
     * of a resource.
     *
     * 409 is check 5 — a suspended organization may not change which of its credentials sees its
     * users' questions.
     *
     * 422 has THREE producers, all sharing `error_class: validation` and differing only in whether
     * `errors` is present: the FormRequest's half-designation rule, which carries a per-field map;
     * the service's org-scoped connection check, which carries a sentence and no map because the
     * refusal is about a record rather than a field's shape; and the catalog re-verification inside
     * EloquentOrganizationRepository::designateRerankConnection(), likewise mapless, which refuses a
     * pair whose `provider_models` row was deleted after the operator's form was drawn.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => RerankConfigurationResource::class],
        description: 'The designation AFTER it was applied, wrapped in `data`. A nonexistent '
            .'`{organization}` 404s at binding time, before this action runs.',
        errors: [401, 403, 404, 409, 422, 429, 500],
    )]
    public function update(
        DesignateRerankConnectionRequest $request,
        Organization $organization,
        RerankDesignationService $designations,
    ): JsonResponse {
        Gate::authorize('designateRerankConnection', $organization);

        // CHECK 5. A suspended organization is not editable, and this edit is one whose only
        // symptom would be answer quality — there is no error, no metric jump on any single
        // request, and nothing on the response to say reranking stopped.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $written = $designations->designate(
            $organization,
            $request->designation(),
            $this->actorId(),
            $request,
        );

        // THE ORGANIZATION THE WRITE RETURNED, NOT THE BOUND ONE. `designate()` returns the row as
        // it stands after the transaction; the bound `$organization` still holds the pre-write
        // columns, so rendering off it would echo the operator's PREVIOUS designation back at them
        // on the very response that changed it — null after a designate, and the old pair after a
        // clear.
        return (new RerankConfigurationResource(
            $written,
            RerankDesignation::fromOrganization($written),
        ))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The admin user id, for the audit row's `actor_id`. Never used as a scope — the organization
     * is.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

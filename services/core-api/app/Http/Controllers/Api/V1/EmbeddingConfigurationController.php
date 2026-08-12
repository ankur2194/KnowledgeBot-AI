<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignateEmbeddingConnectionRequest;
use App\Http\Resources\EmbeddingReadinessResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Embedding\EmbeddingDesignationService;
use App\Services\Embedding\EmbeddingReadinessService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's embedding configuration: what it resolves to, and which connection supplies
 * it (finding C1).
 *
 * THE SIX CHECKS (kb-security-baseline §18.4) for both actions:
 *   1. authenticated identity        `auth:sanctum` on the route group
 *   2. organization membership       `org.member`, which RE-READS the row from PostgreSQL
 *   3. role / permission             Gate::authorize() below — providers.view / providers.manage
 *   4. entity ownership              the policy resolves membership of THE RECORD's organization
 *   5. entity status                 abort_unless on OrganizationStatus, below
 *   6. rate limit                    `throttle:admin` on the route group
 *
 * Checks 5 and 6 are the ones reviewers forget, because 1 through 4 are visible in the route file
 * and these two are not.
 */
final class EmbeddingConfigurationController extends Controller
{
    /**
     * Why this organization can — or cannot — ingest a document.
     *
     * A READ THAT CROSSES THE SEAM, deliberately. The verdict is not cached and not stored: it is
     * a pure function of the connection set and the capability matrix, and a cached "ready" that
     * outlived a model-row edit would send an ingest run to spend parse and OCR before failing.
     *
     * No 422: there is no request body to reject. No 409 either — reading the banner is exactly what
     * a suspended organization's operator needs to be able to do.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => EmbeddingReadinessResource::class],
        description: 'The organization\'s embedding readiness. Wrapped in `data` because the action '
            .'returns the Resource itself and Laravel wraps it.',
        errors: [401, 403, 429, 500, 503],
    )]
    public function show(
        Organization $organization,
        EmbeddingReadinessService $readiness,
    ): EmbeddingReadinessResource {
        Gate::authorize('viewEmbeddingReadiness', $organization);

        return new EmbeddingReadinessResource(
            $readiness->for($organization, $this->actorId()),
        );
    }

    /**
     * Set or clear the designation.
     *
     * Unlike a connection save, this one DOES hard-fail when the pair cannot resolve — 422,
     * `validation`, carrying the data plane's own explanation verbatim. Here the operator is
     * explicitly choosing which connection embeds, and accepting a choice that cannot resolve
     * would store a configuration whose only symptom is a failed upload later.
     *
     * 409 is check 5 — a suspended organization may not move its vector space. 422 is both the
     * FormRequest's half-designation rule and the resolver's refusal, which share an `error_class`
     * of `validation` and differ only in whether `errors` is present.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => EmbeddingReadinessResource::class],
        description: 'The readiness AFTER the designation was applied, wrapped in `data`.',
        errors: [401, 403, 409, 422, 429, 500, 503],
    )]
    public function update(
        DesignateEmbeddingConnectionRequest $request,
        Organization $organization,
        EmbeddingDesignationService $designations,
    ): JsonResponse {
        Gate::authorize('designateEmbeddingConnection', $organization);

        // Check 5. A suspended organization is not editable — and a designation change is the one
        // edit that would otherwise be a no-op with a lasting consequence, because it decides the
        // vector space of everything indexed after the suspension is lifted.
        abort_unless($organization->status === OrganizationStatus::Active, 409);

        $result = $designations->designate(
            $organization,
            $request->designation(),
            $this->actorId(),
        );

        return (new EmbeddingReadinessResource($result['readiness']))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The admin user id, for X-KB-Actor-Id. Never used as a scope — the organization is.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderConnectionRequest;
use App\Http\Resources\EmbeddingReadinessResource;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Providers\ProviderConnectionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Storing a provider credential.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity        `auth:sanctum`
 *   2. organization membership       `org.member`, re-read from PostgreSQL per request
 *   3. role / permission             Gate::authorize('createProviderConnection') — providers.manage
 *   4. entity ownership              the policy resolves membership of THE RECORD's organization
 *   5. entity status                 a suspended organization may not add a credential
 *   6. rate limit                    `throttle:admin`
 *
 * Check 6 in the §18.3 sense — re-authentication for a destructive action — is NOT performed here
 * and does not need to be: creating a new connection breaks nothing that is currently working.
 * ROTATING an existing credential does break every live bot on that provider and must re-
 * authenticate; that endpoint is not in this change set and is reported as owed.
 */
final class ProviderConnectionController extends Controller
{
    /**
     * The response deliberately carries TWO things: the connection that was stored, and the
     * organization's embedding readiness AFTER storing it.
     *
     * The readiness never blocks the write. It is here so the surface that just accepted a
     * credential can immediately say "you still cannot ingest anything, and here is why" — which
     * is the whole of finding C1's user-visible half. Returning only the connection would leave
     * the operator with a green save and an organization that fails on its first upload.
     *
     * NOTE THE ASYMMETRY THE PUBLISHED DOCUMENT HAS TO CARRY: this body is hand-built, so
     * `embedding_readiness` sits at the TOP LEVEL beside `data` and is NOT wrapped — while the same
     * resource arrives under `data` on GET/PUT /embedding-configuration, which returns the Resource
     * and lets Laravel wrap it. One resource, two envelopes. A generated client that assumed one
     * shape would be wrong on the other endpoint, which is why the envelope is declared here rather
     * than inferred from a return type.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * packages/contracts/rules/StoreProviderConnectionRequest.json, dumped from executing
     * `rules()`, and docs/22 finding 19 rules that the FormRequest is the only source of a request
     * rule — a schema here would be the third copy that ruling exists to prevent.
     */
    #[ResponseShape(
        status: 201,
        properties: [
            'data' => ProviderConnectionResource::class,
            'embedding_readiness' => EmbeddingReadinessResource::class,
        ],
        description: 'The stored connection, plus the organization\'s embedding readiness AFTER '
            .'storing it. The readiness never blocks the write.',
        errors: [401, 403, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreProviderConnectionRequest $request,
        Organization $organization,
        ProviderConnectionService $connections,
    ): JsonResponse {
        Gate::authorize('createProviderConnection', $organization);

        abort_unless($organization->status === OrganizationStatus::Active, 409);

        $result = $connections->create($organization, $request->toData(), $this->actorId());

        return response()->json([
            'data' => (new ProviderConnectionResource($result['connection']))->toArray($request),
            'embedding_readiness' => (new EmbeddingReadinessResource($result['readiness']))
                ->toArray($request),
        ], 201);
    }

    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

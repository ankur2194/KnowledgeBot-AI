<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderConnectionRequest;
use App\Http\Requests\UpdateProviderConnectionRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\EmbeddingReadinessResource;
use App\Http\Resources\ProviderConnectionCollectionResource;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Embedding\EmbeddingDesignation;
use App\Services\Providers\ProviderConnectionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's provider connections: list, read, create, edit, delete.
 *
 * Replacing a stored credential is NOT here. It is
 * App\Http\Controllers\Api\V1\RotateProviderCredentialController, a single-action controller, and
 * the reason is the same one that put the invitation resend in its own class:
 * `arch()->preset()->laravel()` restricts a controller's public methods to the seven resource verbs
 * plus `__construct`, `__invoke` and `middleware`, so a `rotateCredential()` method fails the Arch
 * suite. That is the preset working rather than an obstacle — a verb outside the seven is a
 * single-action controller, which is also how it reads in the route file.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all five.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL
 *    on every request. Neither a session value nor a token row is evidence of CURRENT membership,
 *    so a user removed from the organization stops working on the very next request.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the first statement of every body. `index` and
 *    `show` demand `providers.view`; `store`, `update` and `destroy` demand `providers.manage`.
 *    THE SPLIT IS NOT "OWNER AND ADMIN ONLY": §6.4 gives a Knowledge Manager `providers.view` and
 *    nothing else, because an ingestion operator has to be able to see whether the organization
 *    can embed at all. So a knowledge_manager reads this resource and is refused every write on
 *    it; an analyst holds nothing in this catalog and is refused all five.
 *
 * 4. ENTITY OWNERSHIP — `->scopeBindings()` on the group resolves `{providerConnection}` through
 *    `$organization->providerConnections()`, so a foreign or unknown id 404s at BINDING time,
 *    before any policy runs and before the row is in memory. A bare `ProviderConnection $c`
 *    binding would be a global find with no organization predicate, executed inside
 *    SubstituteBindings, upstream of every check. The policy then resolves membership of THE
 *    RECORD's organization, and the repository read still takes `organization_id` as a required
 *    positional argument — three layers, and the binding is the one that runs first.
 *
 * 5. ENTITY STATUS — a 409 on `store`, `update` and `destroy` when the organization is not
 *    Active, EACH CARRYING OrganizationStatus::SUSPENDED_REFUSAL as its message. A 409 raised
 *    with no message renders as `internal_dependency`'s class-mapped copy — "Something on our
 *    side is unavailable. Try again shortly." — which is false twice for a suspension; see that
 *    constant. `index` and `show` deliberately have NONE: reading which credentials exist and why
 *    ingestion is blocked is exactly what a suspended organization's operator needs to do, and it
 *    changes nothing. `destroy` carries a SECOND status check that is not about the organization
 *    at all — see the action.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    NOT performed by any action here and does not need to be. Creating a connection breaks
 *    nothing that is currently working; a relabel breaks nothing; and a DELETE is refused outright
 *    while the connection is load-bearing for embedding, which is the one way it could break
 *    something silently. ROTATING a credential does break every live bot on that provider the
 *    instant it commits, and that endpoint re-authenticates — see
 *    RotateProviderCredentialController.
 *
 * ── ALL FIVE ACTIONS WRITE OR READ ONLY THE MASKED FORM ────────────────────────────────────────
 *
 * No action here decrypts anything. `ProviderConnectionResource` reads `last_four` from a stored
 * column, so rendering a hundred connections performs zero vault calls and cannot leak a key even
 * from a fixture whose plaintext really was sealed.
 */
final class ProviderConnectionController extends Controller
{
    /**
     * Every connection this organization owns.
     *
     * A WRAPPER OBJECT AND NOT A BARE ARRAY — `{"data": {"connections": [...]}}`. The reason is
     * mechanical: `#[ResponseShape]` maps a response KEY to a resource class and cannot express
     * "an array of", and tests/Contract/OpenApiDocumentTest.php requires every published component
     * to carry `additionalProperties: false`, which an array-typed schema cannot. See
     * ProviderConnectionCollectionResource.
     *
     * NO 409 and no 422: there is no request body to reject, and reading the list is what a
     * suspended organization's administrator most needs to be able to do.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderConnectionCollectionResource::class],
        description: 'Every provider connection belonging to this organization, whatever its '
            .'status, oldest first. Revoked and invalid rows are included: an operator diagnosing '
            .'"why can I not ingest" has to be able to see the credential that stopped working. '
            .'Each carries `masked_key` and never a credential. The array is nested inside an '
            .'object; see ProviderConnectionCollectionResource for why the document cannot express '
            .'a bare array here.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        ProviderConnectionService $connections,
    ): ProviderConnectionCollectionResource {
        // CHECKS 3 AND 4. A list has no row to take an organization from, so the ability is on
        // OrganizationPolicy and the RECORD is the organization itself — which already implements
        // OrgOwned, so permit() still resolves membership of the record's organization and "admin
        // of some organization" stays unrepresentable.
        Gate::authorize('viewProviderConnections', $organization);

        // No 409 — see the class docblock, check 5.
        return new ProviderConnectionCollectionResource($connections->list($organization));
    }

    /**
     * One connection.
     *
     * The row is already in memory: `->scopeBindings()` resolved it through
     * `$organization->providerConnections()`, so there is no second query to scope and no service
     * call to make. The policy is what refuses the remaining case — the row IS in this
     * organization and the caller's role is wrong.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderConnectionResource::class],
        description: 'One provider connection, wrapped in `data`. A foreign or unknown '
            .'`{providerConnection}` 404s at binding time, before this action runs.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function show(
        Organization $organization,
        ProviderConnection $providerConnection,
    ): ProviderConnectionResource {
        // CHECKS 3 AND 4 — on the CONNECTION, not on the organization, so the policy resolves
        // membership of THE RECORD's organization.
        Gate::authorize('view', $providerConnection);

        return new ProviderConnectionResource($providerConnection);
    }

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
     *
     * THIS ACTION NOW WRITES AN AUDIT ROW, and it did not before. §18.11 requires credential
     * changes audited and the provider surface wrote nothing at all — a real gap rather than a
     * deferred nicety. `provider.connection.created` is ON_FAILURE_ABORT and is written inside the
     * repository's transaction, so a credential stored without a record of who stored it is not a
     * state this table can reach.
     */
    #[ResponseShape(
        status: 201,
        properties: [
            'data' => ProviderConnectionResource::class,
            'embedding_readiness' => EmbeddingReadinessResource::class,
        ],
        description: 'The stored connection, plus the organization\'s embedding readiness AFTER '
            .'storing it. The readiness never blocks the write. A nonexistent `{organization}` '
            .'404s at binding time, before this action runs.',
        // 404 IS NOT ABOUT A CONNECTION HERE — there is none yet to be missing. It is the
        // `{organization}` binding, which is on this route exactly as it is on its ten siblings: a
        // ULID that resolves to no row 404s inside SubstituteBindings, upstream of the policy and
        // upstream of this method. Every other operation under this binding declared it and this
        // one did not, so a generated client had no 404 branch for a request that really can 404.
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreProviderConnectionRequest $request,
        Organization $organization,
        ProviderConnectionService $connections,
    ): JsonResponse {
        Gate::authorize('createProviderConnection', $organization);

        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $result = $connections->create(
            $organization,
            $request->toData(),
            $this->actorId(),
            $request,
        );

        return response()->json([
            'data' => (new ProviderConnectionResource($result['connection']))->toArray($request),
            // The STORED designation off the bound row. `create()` writes a CONNECTION and never
            // touches `organizations.embedding_connection_id`, so the bound row is current — and
            // this is precisely the surface that needs the field: an operator who has just stored
            // a credential and still cannot ingest needs to know whether an existing designation
            // is the thing failing.
            'embedding_readiness' => (new EmbeddingReadinessResource(
                $result['readiness'],
                EmbeddingDesignation::fromOrganization($organization),
            ))
                ->toArray($request),
        ], 201);
    }

    /**
     * Rename a connection, or move its lifecycle status.
     *
     * IT MUST NEVER ACCEPT A CREDENTIAL, and that is enforced three ways rather than by the
     * absence of a rule: UpdateProviderConnectionRequest declares no `credential` field, its
     * `toData()` returns a DTO with no member to hold one, and
     * `ProviderConnectionService::update()` never reaches the vault. A key is replaced by
     * `PUT …/provider-connections/{id}/credential`, which re-authenticates.
     *
     * The 409 is check 5. A suspended organization may read its configuration and may not change
     * it — including the status field, because flipping a connection back to `active` in a
     * suspended organization is a change that takes effect the moment the suspension lifts.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderConnectionResource::class],
        description: 'The connection after the edit, wrapped in `data`. Only `label` and `status` '
            .'are editable; the request body carries no credential field and this endpoint cannot '
            .'store one. 409 when the organization is not active; 422 when the body changes '
            .'neither field.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateProviderConnectionRequest $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderConnectionService $connections,
    ): ProviderConnectionResource {
        // CHECKS 3 AND 4.
        Gate::authorize('update', $providerConnection);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new ProviderConnectionResource($connections->update(
            $organization,
            $providerConnection,
            $request->toData(),
            $this->actorId(),
            $request,
        ));
    }

    /**
     * A GUARDED HARD DELETE: the connection row and its `provider_models` rows go, in one
     * transaction, and the audit row is the only thing that survives.
     *
     * ── THE SECOND STATUS CHECK, WHICH IS NOT ABOUT THE ORGANIZATION ───────────────────────────
     *
     * A connection that is the organization's designated embedding credential is refused with a
     * 409 and a sentence naming the remedy. This is precisely what the composite
     * `ON DELETE RESTRICT` on `organizations.embedding_connection_id` intends — its migration says
     * the operator "undesignates first, and sees what that means", because clearing the
     * designation returns the organization to resolve-by-rule, which may select a DIFFERENT
     * (provider, model) than the existing corpus was indexed under. That is a re-index, not a
     * setting, and it is a decision an operator has to make with their eyes open.
     *
     * NOTHING HERE WORKS AROUND THE CONSTRAINT. The check below is only the good error message;
     * the constraint is the authority, and if a designation lands between this line and the DELETE
     * the service maps PostgreSQL's 23503 onto the SAME sentence rather than a 500.
     *
     * A 409 AND NOT A 422, unlike the suspended-organization case on `InvitationController::store`.
     * The distinction there was that a 422 keyed on a path no form renders routes through the
     * SPA's `applyServerErrors` to a banner. Here the refusal is not about a field of the
     * submitted body — there is no body — it is about the state of a different record entirely, so
     * there is no field to key an error on and the class-mapped sentence for a 409
     * (`internal_dependency`) would be wrong in the same way. What makes the 409 acceptable is
     * that the render closure preserves an unclassified 4xx's own status AND its own message, so
     * the actionable sentence reaches the client verbatim in `message`.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The connection and its model rows are gone. 409 when the connection is the '
            .'organization\'s designated embedding credential — clear the designation first — or '
            .'when the organization is not active. A foreign or unknown `{providerConnection}` '
            .'404s at binding time, before this action runs.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderConnectionService $connections,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4.
        Gate::authorize('delete', $providerConnection);

        // CHECK 5, first half: the organization.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        // CHECK 5, second half: the connection's role in the organization's configuration.
        // Read from the bound organization row, which the tenant middleware and the scoped binding
        // have already established belongs to this caller.
        abort_if(
            $organization->embedding_connection_id === $providerConnection->id,
            409,
            ProviderConnectionService::DESIGNATED_FOR_EMBEDDING,
        );

        $connections->delete($organization, $providerConnection, $this->actorId(), $request);

        // An acknowledgement and not the deleted resource: the only thing the caller learns is
        // that it happened, and `index` is where the new set is read. Never a 204 — every success
        // body on this surface carries a `data` key, and an empty #[ResponseShape] would publish
        // `"properties": []`, which is not a JSON Schema object (D8).
        return AcknowledgementResource::ok();
    }

    /**
     * The admin user id, for the audit row's `actor_id` and for X-KB-Actor-Id. Never used as a
     * SCOPE — the organization is.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

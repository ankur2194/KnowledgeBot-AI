<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderModelRequest;
use App\Http\Requests\UpdateProviderModelRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\ProviderModelCollectionResource;
use App\Http\Resources\ProviderModelResource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Services\Providers\ProviderModelService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The model catalog under one provider connection: list, read, register, replace, delete.
 *
 * docs/11 §16.2 gives `provider_models` its own row set — identifier, display name, capability
 * flags, context configuration, output limits, pricing metadata, enabled state — and until now the
 * only way to write one was as a nested array inside a connection CREATE. That made the catalog
 * append-only-at-birth: a model the vendor added last week could not be registered without
 * deleting and re-creating the credential, which is a rotation nobody asked for.
 *
 * ── THE SEGMENT NAME IS THE WIRING, AND IT IS `{model}` ───────────────────────────────────────
 *
 * `->scopeBindings()` on the route group makes `{model}` resolve through the PARENT parameter's
 * relation, and the relation name is DERIVED: `Model::childRouteBindingRelationshipName()` is
 * `Str::plural(Str::camel($childType))`, so `{model}` resolves through
 * `ProviderConnection::models()`, which exists. Verified against
 * vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:2534 rather than assumed.
 *
 * THE PARENT IS THE PRECEDING BOUND PARAMETER, not the first one:
 * `Route::parentOfParameter()` returns `array_values($this->parameters)[$key - 1]`, so `{model}`'s
 * parent is `{providerConnection}` and `{providerConnection}`'s is `{organization}`. Both hops are
 * scoped, which is what makes a foreign model under a connection the caller DOES own a 404 at
 * BINDING time. That is the case the composite foreign key and the scoped binding exist for
 * together, and it is asserted directly in tests/Security/ProviderModelAccessTest.php.
 *
 * WHY THE SEGMENT NAME MATTERS SO MUCH: getting it wrong does not fail loudly. A `{providerModel}`
 * segment would derive `providerModels()`, which does not exist, and every request would 404 — the
 * visible failure. The invisible one is worse in the other direction: a bare
 * `ProviderModelEntry $model` binding on a route with no scoping is
 * `ProviderModelEntry::find($id)` with NO organization predicate, executed inside
 * SubstituteBindings, UPSTREAM of every check in this file.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS ALSO LOAD-BEARING ───────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `parentOfParameter()` reads the parameter bag as it
 * stands. So the parent must already have been converted to a model when the child is resolved,
 * which it is only if the signature lists them in path order. Every action below therefore takes
 * `Organization`, then `ProviderConnection`, then `ProviderModelEntry`.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all five.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL
 *    on every request. Neither a session value nor a token row is evidence of CURRENT membership,
 *    so a user removed from the organization stops working on the very next request.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `$this->
 *    authorize()` does not exist: since Laravel 11 the base controller no longer uses
 *    AuthorizesRequests, and calling it fatals at runtime rather than at analysis time.
 *    `index` and `show` demand `providers.view`; `store`, `update` and `destroy` demand
 *    `providers.manage`. THE SPLIT IS NOT "OWNER AND ADMIN ONLY": §6.4 gives a Knowledge Manager
 *    `providers.view` and nothing else, because an ingestion operator has to be able to see
 *    whether the organization can embed at all — and these rows' capability flags are half of that
 *    answer.
 *
 * 4. ENTITY OWNERSHIP — three layers. The scoped binding 404s a foreign id at EITHER level before
 *    any policy runs; the policy resolves membership of THE RECORD's organization; and every
 *    repository method takes `organization_id` and `provider_connection_id` as required positional
 *    arguments. `index` and `store` authorize against the PARENT connection, because there is no
 *    catalog row yet to take an organization from — and the parent is the correct scope anyway,
 *    since `(organization_id, provider_connection_id)` is what the write is about to be checked
 *    against.
 *
 * 5. ENTITY STATUS — an explicit line of its own on `store`, `update` and `destroy`: a 409 when
 *    the organization is not Active, carrying OrganizationStatus::SUSPENDED_REFUSAL as its
 *    message. A 409 raised with no message renders as `internal_dependency`'s class-mapped copy,
 *    which is false twice for a suspension; see that constant. `index` and `show` deliberately
 *    have NONE — reading which
 *    models exist and which claim `embedding` is exactly what a suspended organization's operator
 *    needs to do while working out why ingestion stopped, and it changes nothing. `destroy`
 *    carries a SECOND status check that is not about the organization's status at all; see the
 *    action.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    NOT performed here and does not need to be: nothing on this surface touches a credential.
 *    Creating a row breaks nothing that works; replacing one changes metadata; and a DELETE is
 *    refused outright while the row is load-bearing for embedding, which is the one way it could
 *    break something silently.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, the service it calls imports no vault, and ProviderModelResource
 * renders no field of the parent connection except its ULID. A catalog listing therefore performs
 * zero decryptions and has no masked form to leak — asserted, from rows whose plaintext really was
 * sealed, in tests/Security/ProviderModelAccessTest.php.
 */
final class ProviderModelController extends Controller
{
    /**
     * Every model registered under this connection.
     *
     * A WRAPPER OBJECT AND NOT A BARE ARRAY — `{"data": {"models": [...]}}`. The reason is
     * mechanical: `#[ResponseShape]` maps a response KEY to a resource class and cannot express
     * "an array of", and tests/Contract/OpenApiDocumentTest.php requires every published component
     * to carry `additionalProperties: false`, which an array-typed schema cannot. See
     * ProviderModelCollectionResource.
     *
     * NO 409 and no 422: there is no request body to reject, and reading the catalog is what a
     * suspended organization's administrator most needs to be able to do.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderModelCollectionResource::class],
        description: 'Every model row under this connection, enabled or not, ordered by model '
            .'identifier. Disabled rows are included: an operator asking "why is this model '
            .'missing from the bot\'s dropdown" has to be able to find it. The array is nested '
            .'inside an object; see ProviderModelCollectionResource for why the document cannot '
            .'express a bare array here.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderModelService $models,
    ): ProviderModelCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT. A list has no catalog row to take an organization from,
        // and the connection is the right scope anyway — it is the record whose catalog is being
        // read. `view` is `providers.view`, which a knowledge_manager holds.
        Gate::authorize('view', $providerConnection);

        // No 409 — see the class docblock, check 5.
        return new ProviderModelCollectionResource($models->list($organization, $providerConnection));
    }

    /**
     * One catalog row.
     *
     * The row is already in memory: `->scopeBindings()` resolved it through
     * `$providerConnection->models()`, itself resolved through
     * `$organization->providerConnections()`, so there is no second query to scope and no service
     * call to make. The policy is what refuses the remaining case — the row IS under this
     * organization's connection and the caller's role is wrong.
     *
     * `$organization` AND `$providerConnection` ARE UNUSED IN THE BODY AND MUST NOT BE REMOVED.
     * `ImplicitRouteBinding::resolveForRoute()` converts a path segment to a model only if the
     * ACTION'S SIGNATURE declares it, and `Route::parentOfParameter()` requires the preceding
     * parameter to already BE a UrlRoutable. Drop `$providerConnection` and `{providerConnection}`
     * stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding falls to the
     * unscoped `else` branch — `ProviderModelEntry::resolveRouteBinding($id)` — executed inside
     * SubstituteBindings, upstream of the Gate call below. Same for `update` and `destroy`.
     *
     * WHAT THAT WOULD ACTUALLY LEAK, STATED PRECISELY RATHER THAN DRAMATICALLY: not another
     * tenant's row. `resolveRouteBinding()` builds its query through `newQuery()`, so
     * `#[ScopedBy(OrganizationScope::class)]` still appends `organization_id = <ambient context>`
     * — the backstop layer holds. What is lost is the CONNECTION predicate, so any catalog row of
     * ANY of this organization's connections would resolve under any other connection's URL. That
     * is a real defect and it is exactly the case
     * tests/Security/ProviderModelAccessTest.php's "404s a model of a DIFFERENT connection inside
     * the SAME organization" exists to catch — a case no cross-tenant test can see, which is why
     * it is asserted on its own.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderModelResource::class],
        description: 'One model row, wrapped in `data`. A foreign or unknown `{providerConnection}` '
            .'or `{model}` 404s at binding time, before this action runs — including a model that '
            .'exists but belongs to a DIFFERENT connection of the same organization.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function show(
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderModelEntry $model,
    ): ProviderModelResource {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD's
        // organization.
        Gate::authorize('view', $model);

        return new ProviderModelResource($model);
    }

    /**
     * Register one model under this connection.
     *
     * AUTHORIZED AGAINST THE PARENT CONNECTION, deliberately. The row does not exist yet, so there
     * is nothing to take an organization from — and `createModel` on ProviderConnectionPolicy is
     * the correct scope anyway, since the composite foreign key
     * `(organization_id, provider_connection_id)` is what the write is about to be checked
     * against. Authorizing against the ORGANIZATION instead would let this pass for a caller who
     * is a member of the right organization but is addressing a connection in it they were never
     * shown.
     *
     * A DUPLICATE `(organization, connection, model)` IS A 422 AND NOT A 500, in two layers: an
     * org-scoped pre-flight query in ProviderModelService for the readable message keyed on the
     * `model` field, and a catch of SQLSTATE 23505 on the unique index
     * `provider_models_org_connection_model` for the race that check cannot win. The index is the
     * authority; the check is the good error. Neither is a `unique:` validation rule — see
     * StoreProviderModelRequest for why a `unique:` rule on a tenant-owned column is the shape of
     * Filament CVE-2026-48067.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * packages/contracts/rules/StoreProviderModelRequest.json, dumped from executing `rules()`,
     * and docs/22 finding 19 rules that the FormRequest is the only source of a request rule.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => ProviderModelResource::class],
        description: 'The registered model row, wrapped in `data`. 409 when the organization is '
            .'not active; 422 when this connection already carries a row for the same model '
            .'identifier, keyed on `model`.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreProviderModelRequest $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderModelService $models,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT.
        Gate::authorize('createModel', $providerConnection);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $row = $models->create(
            $organization,
            $providerConnection,
            $request->toData(),
            $this->actorId(),
            $request,
        );

        return response()->json([
            'data' => (new ProviderModelResource($row))->toArray($request),
        ], 201);
    }

    /**
     * Replace the row's mutable attributes.
     *
     * A PUT AND NOT A PATCH, and the reason is the generated contract rather than REST taste: with
     * seven mutable attributes, "a body that changes nothing is refused" is expressible in
     * `rules()` only as `required_without_all` naming six siblings on each of seven fields, and the
     * readable alternative — an `after()` closure — is INVISIBLE to `kb:dump-form-rules`, so the
     * generated client would never be told the constraint exists. UpdateProviderModelRequest states
     * this at length.
     *
     * THE MODEL IDENTIFIER IS NOT EDITABLE, and that is enforced three ways rather than by the
     * absence of a rule: UpdateProviderModelRequest declares no `model` field, `ProviderModelEdit`
     * has no member to hold one, and the repository never assigns the column. It is half of the
     * vector-space identity for everything already embedded through this row, and
     * `organizations.embedding_model` references it as a bare string with no foreign key — so
     * nothing in the database would follow a rename.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ProviderModelResource::class],
        description: 'The row after the replacement, wrapped in `data`. The official model '
            .'identifier is immutable and the request body carries no field for it. 409 when the '
            .'organization is not active.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateProviderModelRequest $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderModelEntry $model,
        ProviderModelService $models,
    ): ProviderModelResource {
        // CHECKS 3 AND 4.
        Gate::authorize('update', $model);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new ProviderModelResource($models->update(
            $organization,
            $providerConnection,
            $model,
            $request->toData(),
            $this->actorId(),
            $request,
        ));
    }

    /**
     * A GUARDED HARD DELETE: the catalog row goes and the audit row is the only thing that
     * survives.
     *
     * ── THE SECOND STATUS CHECK, WHICH IS NOT ABOUT THE ORGANIZATION'S STATUS ─────────────────
     *
     * A row that is the (connection, model) PAIR named by `organizations.embedding_connection_id`
     * and `organizations.embedding_model` is refused with a 409 and a sentence naming the remedy.
     * Deleting it would leave the designation pointing at a catalog row that no longer exists, and
     * the failure would surface at the NEXT UPLOAD as a resolution error nobody can connect to an
     * action taken days earlier — which is precisely the late discovery finding C1 exists to
     * remove.
     *
     * UNLIKE THE CONNECTION DELETE, NOTHING IN THE DATABASE BACKS THIS UP. That one is refused by
     * the composite `ON DELETE RESTRICT` on `embedding_connection_id` and the controller check is
     * only the good message. Here there is no foreign key from
     * `(embedding_connection_id, embedding_model)` to `provider_models (provider_connection_id,
     * model)` — `embedding_model` is a bare `text` column — so this check IS the authority. That is
     * why it is performed TWICE: once here, on the already-bound organization row, for the fast
     * readable error; and once inside the repository's transaction, under the same
     * `lockForUpdate()` on the organization that
     * EloquentOrganizationRepository::designateEmbeddingConnection() takes.
     *
     * THE LOCK IS ONLY HALF OF IT, and this paragraph used to stop one sentence too early. Taking
     * the same lock makes the two transactions serialise; it does not decide what the SECOND one
     * does when it wins. This side re-reads the designation, so designate-then-delete is refused —
     * but the designation write did not re-read the CATALOG, so delete-then-designate committed a
     * designation naming a row that had just been removed, with both requests returning 200 and
     * `organizations.embedding_model` being a bare `text` column with no foreign key to notice.
     * Both sides now re-verify under that lock, which is what actually closes it in both
     * directions.
     *
     * A 409 AND NOT A 422: the refusal is not about a field of a submitted body — there is no body
     * — it is about the state of a different record entirely, so there is no field to key an error
     * on. What makes that acceptable is that bootstrap/app.php's render closure preserves an
     * unclassified 4xx's own status AND its own message, so the actionable sentence reaches the
     * client verbatim in `message` (as `error_class: internal_dependency`, `retryable: false` —
     * 409 is a RENDERING of an existing taxonomy row, not a nineteenth class).
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The model row is gone. 409 when it is the (connection, model) pair the '
            .'organization\'s embedding designation names — clear the designation first — or when '
            .'the organization is not active. A foreign or unknown `{providerConnection}` or '
            .'`{model}` 404s at binding time, before this action runs.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        ProviderConnection $providerConnection,
        ProviderModelEntry $model,
        ProviderModelService $models,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4.
        Gate::authorize('delete', $model);

        // CHECK 5, first half: the organization.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        // CHECK 5, second half: this row's role in the organization's configuration. Read from the
        // bound organization row, which the tenant middleware and the scoped binding have already
        // established belongs to this caller. BOTH HALVES OF THE PAIR ARE COMPARED — a connection
        // match alone would refuse every model under the designated connection, including the
        // dozen the designation does not name.
        abort_if(
            $organization->embedding_connection_id === $providerConnection->id
                && $organization->embedding_model === $model->model,
            409,
            ProviderModelService::DESIGNATED_FOR_EMBEDDING,
        );

        // AND THE RERANK DESIGNATION, which this check did not read (`docs/22` § T36). Both halves
        // of THIS pair are compared for the same reason the embedding one compares both: a
        // connection match alone refuses every model under the designated connection.
        //
        // This is the FAST readable error and not the authority. The authority is the repository's
        // re-read inside the delete's transaction, under the `organizations` row lock the
        // designation write takes — a designation landing between this line and the DELETE wins,
        // and that caller gets the same sentence a millisecond later.
        abort_if(
            $organization->rerank_connection_id === $providerConnection->id
                && $organization->rerank_model === $model->model,
            409,
            ProviderModelService::DESIGNATED_FOR_RERANK,
        );

        $models->delete($organization, $providerConnection, $model, $this->actorId(), $request);

        // An acknowledgement and not the deleted resource: the only thing the caller learns is
        // that it happened, and `index` is where the new set is read. Never a 204 — every success
        // body on this surface carries a `data` key, and an empty #[ResponseShape] would publish
        // `"properties": []`, which is not a JSON Schema object (D8).
        return AcknowledgementResource::ok();
    }

    /**
     * The admin user id, for the audit row's `actor_id`. Never used as a SCOPE — the organization
     * is.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

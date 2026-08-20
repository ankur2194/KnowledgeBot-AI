<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\SourceState;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSourceStatusRequest;
use App\Http\Resources\SourceResource;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sources\SourceService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * DISABLE AND ENABLE — the two lifecycle moves a human makes to a knowledge source directly.
 *
 * ── WHY THIS IS ITS OWN ROUTE AND ITS OWN CONTROLLER ──────────────────────────────────────────
 *
 * `status` is the column that decides whether a source is in the corpus at all, and it is one of
 * the four mandatory Qdrant filter terms. Two doors to that column are two places a check has to
 * be — the same argument that put the bot lifecycle on `BotStatusController` and credential
 * rotation on its own route. `UpdateSourceRequest` declares `status` as `missing` rather than
 * dropping the rule, because an absent rule means `validated()` SILENTLY DISCARDS the field: a
 * client that had not been updated would disable a source, receive a 200, and find it still
 * answering.
 *
 * `missing` and not `prohibited` — `prohibited` passes for `null`, `""` and `[]`, and
 * `UpdateBotRequest` records the measurement.
 *
 * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's public
 * methods to the seven resource verbs plus `__construct`, `__invoke` and `middleware` — the same
 * reason `BotStatusController` and `RotateProviderCredentialController` exist. A `transition`
 * method on `SourceController` would fail the arch suite.
 *
 * ── DISABLE IS IMMEDIATE, AND THAT IS THIS PHASE'S HEADLINE REQUIREMENT ──────────────────────
 *
 * One column changes and the source leaves retrieval on the next query. Nothing is purged, no job
 * has to succeed, and every vector is retained — which is what makes re-enabling a metadata write
 * rather than a re-ingestion. The mechanism is the STATUS FILTER: `source_status` is matched
 * POSITIVELY against `['ready','ready_with_warnings']` in every tenant filter, and
 * `kb-tenancy-isolation` NN5 is why that direction matters — a `match` condition is not satisfied
 * by a point that lacks the value, so a positive filter fails closed while a `must_not` would fail
 * open.
 *
 * The one thing that could still answer after a disable is a CACHED ANSWER, and it cannot for a
 * reason that lives in another file: `valkey-keyspaces` keys `ans:` on a fingerprint of the
 * RESOLVED retrieval scope, so a disable changes the key rather than requiring a purge somebody has
 * to remember.
 *
 * `Disabled` IS NOT `Deleting`. Disabling is not a way to reclaim storage and deleting is not a way
 * to hide something for a week.
 *
 * ── THE GUARD IS NOT HERE, AND THAT IS THE WHOLE DESIGN ───────────────────────────────────────
 *
 * `SourceState::transitionTable()` is the only statement of the machine and `SourceService` is what
 * asks it, under the row lock that also reads `previous_status` for the audit row. A copy of the
 * legality check here would be the copy that drifts, and it would drift silently — the two `Ready`
 * edges out of `Indexing` carry a verification condition that a hand-written comparison cannot
 * express at all.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ───────────────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, re-reading `organization_users` per request.
 * 3. ROLE / PERMISSION — `Gate::authorize('update', $source)`, i.e. `sources.manage`. There is
 *    deliberately no `sources.disable`: `KnowledgeSourcePolicy` records that a separate permission
 *    for each lifecycle move would be granted to exactly the same three roles, and a permission
 *    nobody grants differently is a permission that fails silently in both directions.
 * 4. ENTITY OWNERSHIP — the scoped binding 404s a foreign or unknown `{source}` at BINDING time;
 *    the policy resolves membership of THE RECORD'S organization; the repository takes the
 *    organization as a required positional argument.
 * 5. ENTITY STATUS — the organization's half here (409 when it is not Active) and the source's own
 *    half in the transition table, which is where a status check belongs and where `permit()` has
 *    no argument position for it.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`. No §18.3 re-authentication:
 *    disabling withdraws a source this organization owns and is instantly reversible.
 */
final class SourceStatusController extends Controller
{
    /**
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED. `ImplicitRouteBinding::
     * resolveForRoute()` converts a path segment to a model only if the ACTION'S SIGNATURE declares
     * it, and `Route::parentOfParameter()` requires the preceding parameter to already BE a
     * `UrlRoutable`. Drop it and `{organization}` stays a raw string, so `{source}` falls to the
     * unscoped `KnowledgeSource::resolveRouteBinding($id)` executed inside `SubstituteBindings` —
     * upstream of the Gate call below.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SourceResource::class],
        description: 'The source after the move, wrapped in `data`. A DISABLE takes effect on the '
            .'next query — it is one column, every vector is retained, and no job has to succeed '
            .'first. An ENABLE is sent as `ready` whatever the source published as: which of the '
            .'two ready states it lands in is READ FROM ITS LIVE VERSIONS, because "did this '
            .'document parse cleanly" is a fact about the content rather than a choice, and '
            .'`status` in the response says which one it chose. 409 when the organization is not '
            .'active; 422 when the source already holds the requested state — a transition is not a '
            .'state assertion — and when the transition table has no edge for the move, which is '
            .'the case for a source that is `deleting`, `deleted` or mid-run.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function __invoke(
        UpdateSourceStatusRequest $request,
        Organization $organization,
        KnowledgeSource $source,
        SourceService $sources,
    ): SourceResource {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name.
        Gate::authorize('update', $source);

        // CHECK 5, the organization's half. The source's own half is the transition table's.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $actorId = $this->actorId();

        // TWO SERVICE METHODS AND NOT ONE WITH A FLAG, because they write DIFFERENT AUDIT
        // OPERATIONS — `source.disabled` and `source.enabled` — and `AuditLogger` derives `outcome`
        // and the details allow-list from the operation name. A single method taking the target
        // state would have to map state back to operation, which is this branch written once more
        // in a place where it is harder to see.
        $moved = $request->toStatus() === SourceState::Disabled
            ? $sources->disable($organization, $source, $actorId, $request)
            : $sources->enable($organization, $source, $actorId, $request);

        return new SourceResource($moved);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SourceResource;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sources\SourceService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST .../sources/{source}/reprocess` — run this source through the pipeline again.
 *
 * ── A POST AND NOT A PUT, BECAUSE IT IS NOT IDEMPOTENT AND MUST NOT BE ───────────────────────
 *
 * Every call mints a NEW `force_nonce`, which is a component of the ingest key and therefore
 * produces a genuinely new version of every item. That is the whole point: a resubmission of
 * unchanged content with unchanged configuration produces an identical key, `UNIQUE
 * (source_item_id, ingest_key)` resolves it to the version that already exists, and the admin sees
 * "already processed" — correct for a retry, and exactly wrong for a button labelled Reprocess.
 *
 * TWO CALLS THEREFORE COST TWO RUNS, and that is the honest reading of the button rather than an
 * oversight. WHAT REFUSES THE DOUBLE-PRESS IS THE TRANSITION TABLE, NOT THE QUEUE: `queued ->
 * queued` is not an edge of `SourceState::transitionTable()`, so the second press is a 422 naming
 * the field. `SubmitIngestionJob` is `ShouldBeUniqueUntilProcessing` keyed on `(organization, job
 * id)` and deliberately NOT on the source — `SubmitIngestionJob::uniqueId()` records the
 * measurement, that keying it on the source made a second legitimate dispatch a silent drop — so
 * what the queue collapses is one dispatch delivered twice, and nothing else. Every item is
 * re-stamped with the new job id besides, so the older run's callbacks are ignored outright rather
 * than merely ordered.
 *
 * ── AND THIS IS WHY THE ENDPOINT DOES NOT HONOUR `Idempotency-Key` ───────────────────────────
 *
 * A client-supplied idempotency header on THIS route would have to mean "if you have seen this key,
 * return the earlier response", which is precisely the dedupe the force nonce exists to defeat. The
 * internal seam still carries `X-KB-Idempotency-Key`, derived server-side and covering the nonce,
 * so a RETRY of one submission is a replay while a SECOND press is a second run. The two ideas
 * look alike and are opposites; `SourceController` states the same conclusion for the create path.
 *
 * ── `sources.manage`, NOT `sources.upload` ────────────────────────────────────────────────────
 *
 * `KnowledgeSourcePolicy::reprocess()` records the argument: no new content is admitted, no storage
 * quota is consumed, and nothing untrusted enters the system that was not already there. What it
 * DOES spend is provider embedding tokens on every item, which is a billing concern the service
 * records on the audit row as `item_count` rather than an authorization one this policy can
 * express.
 *
 * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's public
 * methods to the seven resource verbs plus `__construct`, `__invoke` and `middleware`.
 *
 * ── THE SIX CHECKS ────────────────────────────────────────────────────────────────────────────
 *
 * 1. `auth:sanctum`. 2. `org.member`, re-read per request. 3. `Gate::authorize('reprocess', …)` =
 * `sources.manage`. 4. Scoped binding 404s a foreign id at binding time, the policy resolves the
 * RECORD'S organization, the repository takes it positionally. 5. 409 for a suspended organization
 * here; the source's own half is the transition table, which refuses a reprocess of anything with
 * no legal edge to `Queued` — a `deleting` or `deleted` source, and a run already in flight.
 * 6. `throttle:admin` plus `verified`.
 */
final class ReprocessSourceController extends Controller
{
    /**
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED — see `SourceStatusController`.
     */
    #[ResponseShape(
        status: 202,
        properties: ['data' => SourceResource::class],
        description: 'The source, wrapped in `data`, moved to `queued` with every item claimed for '
            .'a new run. 202 rather than 200 because nothing has been reprocessed yet: the '
            .'submission is a queued job, the pipeline reports progress onto `status`, and the '
            .'previous version KEEPS SERVING every query until the new one is indexed AND verified. '
            .'409 when the organization is not active; 422 when the source has no legal edge to '
            .'`queued` — a run already in flight, or a source being deleted.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function __invoke(
        Request $request,
        Organization $organization,
        KnowledgeSource $source,
        SourceService $sources,
    ): \Illuminate\Http\JsonResponse {
        // CHECKS 3 AND 4.
        Gate::authorize('reprocess', $source);

        // CHECK 5, the organization's half.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $requeued = $sources->reprocess($organization, $source, $this->actorId(), $request);

        return response()->json([
            'data' => (new SourceResource($requeued))->toArray($request),
        ], 202);
    }

    /**
     * The admin user id, for the audit row's `actor_id`. Never used as a SCOPE.
     */
    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}

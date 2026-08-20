<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Exceptions\KbException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IngestionCallbackRequest;
use App\Http\Resources\IngestionAcknowledgementResource;
use App\Services\Sources\SourceService;
use App\Support\Contracts\ResponseShape;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * `POST /internal/v1/callbacks/ingestion` — the data plane reporting one step of one run.
 *
 * ── LARAVEL NEVER POLLS FASTAPI, WHICH IS WHY THIS ROUTE EXISTS ──────────────────────────────
 *
 * `kb-internal-api-contracts` makes async jobs report inbound and names the ONE legal poll: a
 * nightly reconciliation sweep over jobs that have gone silent past their timeout. Everything else
 * arrives here.
 *
 * ── THE ORGANIZATION COMES FROM THE SIGNED HEADER AND FROM NOWHERE ELSE ──────────────────────
 *
 * `X-KB-Org-Id` is inside the HMAC canonical string precisely so this line can trust it: the
 * covered header set is recomputed from every `X-KB-*` header actually present, so flipping the org
 * would break the signature by construction. Reading it from the BODY instead would make the tenant
 * scope a parameter — `kb-tenancy-isolation` NN6 — and every predicate below would then agree with
 * a caller's choice of victim.
 *
 * It is bound as the ambient `TenantContext` for the width of the call as well as passed
 * positionally into every repository method, and BOTH are load-bearing here for opposite reasons.
 * The explicit argument is the mechanism. The ambient binding is not merely a backstop on this
 * path: `OrganizationScope` FAILS CLOSED with `whereRaw('1 = 0')` when nothing is bound, so an
 * unbound callback would read no rows at all and answer `unknown_item` for every frame of every
 * healthy run — a silent, total failure of the pipeline that looks like a data-plane bug.
 *
 * ── AUTHENTICATION IS THE SIGNATURE, VERIFIED BEFORE THIS CLASS EXISTS ───────────────────────
 *
 * `VerifyInternalSignature` runs first in the `internal` middleware group, ahead of
 * `SubstituteBindings`, so an unsigned request never reaches a route model binding, a controller,
 * or a database read. There is no user, no session, no policy and no `Gate::authorize()` on this
 * surface — and the absence is deliberate rather than an omission: the caller is a service, and
 * what it may do is decided by the route it reached and by the organization inside the signature.
 *
 * ── THE ORDERING GUARD IS THE POINT OF THE WHOLE PATH ────────────────────────────────────────
 *
 * `WHERE sequence > progress_sequence`, plus a `current_job_id` equality, both read under a
 * `lockForUpdate()` on the item, inside the same transaction as every write the frame performs.
 * Without it a Celery retry re-emitting stage 6 after stage 9 has landed flips a `ready` source back
 * to `processing` — taking an already-published version out of retrieval, with a 200 on both
 * frames and nothing anywhere to say so.
 *
 * The guard's two columns live on `source_items` rather than on `source_versions`, and the reason is
 * that a run is scoped to an ITEM: the Valkey lock the worker takes is per `source_item_id`, and
 * the run's first act is to resolve the ingest key — which is what decides which version row it
 * belongs to. A counter on the version could not guard the frame that CREATES the version, which is
 * exactly the frame a redelivery duplicates.
 *
 * ── A SINGLE-ACTION CONTROLLER ────────────────────────────────────────────────────────────────
 *
 * `arch()->preset()->laravel()` limits a controller's public methods to the seven resource verbs
 * plus `__construct`, `__invoke` and `middleware`. `callbacks/{group}` will grow siblings — crawl,
 * deletion, evaluation — and each is its own class rather than a method here, because each writes a
 * different set of tables and a shared `handle()` switching on a group string is where the guard
 * gets skipped for one of them.
 */
final class IngestionCallbackController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => IngestionAcknowledgementResource::class],
        description: 'What Laravel did with the frame. A refused frame is still a 200 with '
            .'`applied: false` and a named reason: a stale or out-of-order frame is the ordering '
            .'guard working, and a 4xx would put a permanently-failing request in front of a task '
            .'that is going to re-emit it. 401 when the signature, the timestamp skew or the replay '
            .'nonce refuses; 422 when the frame is malformed or names a transition the table '
            .'forbids. This operation is not published in the generated OpenAPI document — the '
            .'internal seam is not a client surface.',
        errors: [401, 422, 500, 503],
    )]
    public function __invoke(
        IngestionCallbackRequest $request,
        SourceService $sources,
        TenantContext $tenancy,
    ): IngestionAcknowledgementResource {
        $organizationId = (string) $request->header('X-KB-Org-Id', '');

        if (! Str::isUlid($organizationId)) {
            // `validation` -> 422, which is what the contract specifies for a missing or malformed
            // required header. NEVER a 400: there is no 400 row in the taxonomy at all, so a
            // contract line demanding one specifies a status no correct implementation produces.
            //
            // Reached only if the signature verified — the header is inside the canonical string —
            // so this is a well-formed caller sending a malformed value rather than an attack, and
            // the message can say what is wrong.
            throw KbException::validation(
                'X-KB-Org-Id must be an organization ULID. It is the tenant scope for every write '
                .'this callback performs, and it is never defaulted and never read from the body.',
            );
        }

        $frame = $request->toFrame();

        return new IngestionAcknowledgementResource(
            // `runFor()` binds the ambient scope and clears it in its own `finally`, so there is no
            // path out of this closure — including a thrown `IllegalSourceTransition` on its way to
            // becoming a 422 — that leaves the context bound for the next request this process
            // serves. See the class docblock for why the ambient binding is not optional here.
            $tenancy->runFor(
                $organizationId,
                fn () => $sources->applyProgress($organizationId, $frame),
            ),
        );
    }
}

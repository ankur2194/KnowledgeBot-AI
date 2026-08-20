<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\SourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSourcesRequest;
use App\Http\Requests\StoreSourceRequest;
use App\Http\Requests\UpdateSourceRequest;
use App\Http\Resources\SourceCollectionResource;
use App\Http\Resources\SourceResource;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sources\SourceService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An organization's knowledge sources: list, read, add, edit, remove.
 *
 * ── WHAT A SOURCE ROW DECIDES ─────────────────────────────────────────────────────────────────
 *
 * `source_status` and `source_version_id` are two of the four mandatory Qdrant filter terms
 * (`kb-tenancy-isolation` NN3) and both are resolved from these tables, so a mistake on this
 * surface is not "somebody saw a document list" — it is a bot answering out of a corpus the
 * organization never uploaded, at normal latency, with a well-formed citation. Which is why almost
 * none of that boundary lives in this file: the tenant filter is the data plane's, the state
 * machine is `SourceState`'s, the permission is `KnowledgeSourcePolicy`'s, and this class decides
 * an ORDERING OF CHECKS.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ────────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` reads the parameter bag as
 * it stands — it returns `array_values($this->parameters)[$key - 1]`, the PRECEDING bound parameter
 * and not the first one. So the parent must already have been converted to a model when the child
 * is resolved, which it is only if the signature lists them in path order. Every action below takes
 * `Organization` and then `KnowledgeSource`.
 *
 * `$organization` IS UNUSED IN `show()`'s BODY AND MUST NOT BE REMOVED. Drop it and
 * `{organization}` stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding
 * falls to the unscoped `else` branch — `KnowledgeSource::resolveRouteBinding($id)` — executed
 * inside `SubstituteBindings`, upstream of the Gate call. `BotController`'s docblock states the
 * failure at length and it holds identically here, with the same aggravating detail: the parent IS
 * the organization, so the scoped binding and the `#[ScopedBy]` backstop would both be leaning on
 * the same ambient `TenantContext`, and a stale context is exactly the case the explicit predicate
 * exists for.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all five. The admin surface is
 *    the Sanctum SPA cookie session, not a bearer token.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL on
 *    every request. Neither a session value nor a token row is evidence of CURRENT membership.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `$this->
 *    authorize()` does not exist: since Laravel 11 the base controller no longer uses
 *    AuthorizesRequests, and calling it fatals at runtime rather than at analysis time.
 *    `index` and `show` demand `sources.view`; `update` and `destroy` demand `sources.manage`;
 *    `store` demands `sources.manage` on the ORGANIZATION and additionally `sources.upload` when
 *    the body carries files — see `store()` for why that second gate is where it is.
 *
 * 4. ENTITY OWNERSHIP — three layers. The scoped binding 404s a foreign or unknown `{source}` at
 *    BINDING time, before any policy is constructed and before the row is in memory; the policy
 *    resolves membership of THE RECORD'S organization through `OrgOwned`; and every repository
 *    method takes `organization_id` as a required positional argument. `index` and `store`
 *    authorize against the PARENT ORGANIZATION, because there is no source row yet to take an
 *    organization from — `OrganizationPolicy::viewSources()`, `::createSource()` and
 *    `::uploadSource()` are that half.
 *
 * 5. ENTITY STATUS — an explicit line of its own on `store`, `update` and `destroy`: a 409 when the
 *    organization is not Active, carrying `OrganizationStatus::SUSPENDED_REFUSAL`. The SOURCE'S own
 *    status is checked by the state machine rather than here, and only where a state machine can
 *    see it: `destroy` is a transition to `Deleting` and is refused for a source that has no legal
 *    edge to it. `index` and `show` deliberately have NONE — reading which sources exist and why
 *    one is not answering is exactly what a suspended organization's operator needs to do.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    NOT performed. Nothing here touches a credential or verifies a password, and `destroy` is
 *    phase 1 of a REVERSIBLE-until-purged removal on a row this organization owns outright.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, `SourceService` imports no vault, and `SourceResource` renders no
 * provider field at all. A source listing performs zero decryptions and has no masked form to leak.
 * The embedding credential this corpus is eventually embedded with is resolved and decrypted on the
 * DATA PLANE, at execution time, through the provider layer's own accessor — nothing under
 * `app/ingestion/` accepts a credential parameter, and nothing on this path sends one.
 */
final class SourceController extends Controller
{
    /**
     * ONE PAGE of this organization's sources.
     *
     * ── THE ENVELOPE IS FIXED AND IS NOT THIS ACTION'S TO RESHAPE ─────────────────────────────
     *
     *     {"data": {"sources": [...], "meta": {page, per_page, total, total_pages, sort, dir, filter}}}
     *
     * `apps/web/src/lib/table/envelope.ts` is written against exactly that and THROWS rather than
     * degrading when it cannot read it, because an unreadable envelope is not an empty list.
     *
     * ── WHY THE QUERY STRING GOES THROUGH A FormRequest AT ALL ────────────────────────────────
     *
     * Because `sort` reaches an `ORDER BY` and `per_page` decides how much work one caller may ask
     * the database for. `IndexSourcesRequest` closes the sortable set with `Rule::in(...)` and caps
     * `per_page` at `ListQuery::MAX_PER_PAGE`; without both, a list endpoint is a query whose cost
     * and plan the caller chooses. It also puts the parameters in
     * `packages/contracts/rules/IndexSourcesRequest.json`, which is the only place a generated
     * client learns they exist.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SourceCollectionResource::class],
        description: 'One page of this organization\'s knowledge sources, with `meta` beside the '
            .'array inside `data`. EVERY lifecycle state is included — drafts, failed runs, '
            .'disabled sources and rows whose purge is still in flight — because an operator asking '
            .'"where did that document go" has to be able to find it. `page` is 1-based; '
            .'`per_page`, `sort`, `dir` and `filter` are echoed AS APPLIED, which may differ from '
            .'what was asked for because the platform clamps the page size and falls back to the '
            .'endpoint default sort.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function index(
        IndexSourcesRequest $request,
        Organization $organization,
        SourceService $sources,
    ): SourceCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT. A list has no source row to take an organization from, and
        // the organization is the right scope anyway — it is the record whose sources are read.
        Gate::authorize('viewSources', $organization);

        $query = $request->toQuery();

        // No 409 — see the class docblock, check 5.
        return new SourceCollectionResource($sources->list($organization, $query), $query);
    }

    /**
     * One source's metadata and lifecycle state.
     *
     * The row is already in memory: `->scopeBindings()` resolved it through `$organization->
     * sources()`, so there is no second query to scope and no service call to make. The policy is
     * what refuses the remaining case — the row IS in this organization and the caller's role is
     * wrong.
     *
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED. See the class docblock.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SourceResource::class],
        description: 'One source, wrapped in `data`. There is no active-version pointer on this '
            .'shape and there never will be one: a crawl gives a single source hundreds of '
            .'independently-versioned items, so "the current version of this source" is a set and a '
            .'join rather than a value. A foreign or unknown `{source}` 404s at binding time, '
            .'before this action runs, and the body is byte-identical to the 404 for a path with no '
            .'route.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function show(Organization $organization, KnowledgeSource $source): SourceResource
    {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name.
        Gate::authorize('view', $source);

        return new SourceResource($source);
    }

    /**
     * Add one source, give it its first item, and submit it for processing.
     *
     * ── TWO GATES, AND THE SECOND ONE IS NOT SYMMETRY ─────────────────────────────────────────
     *
     * `createSource` carries `sources.manage`. A body carrying FILES additionally demands
     * `uploadSource`, i.e. `sources.upload`, and `Permission::SourcesUpload`'s own docblock states
     * why the two are separate for a set of roles that currently holds both: uploading is the one
     * action that consumes a storage quota and hands bytes to an untrusted parser. Granting them
     * apart later is a role change; discovering that the gate was never asked is a rewrite.
     *
     * ── THE SOURCE IS CREATED AND SUBMITTED IN ONE ACT ────────────────────────────────────────
     *
     * `Draft` means "created, never submitted; no version exists" and it is a real state — but not
     * one this endpoint can leave a caller in, because a caller who has just handed us content has
     * plainly submitted it. The transition and the dispatch are `SourceService`'s, and the job is
     * dispatched AFTER THE COMMIT (`after_commit` on the `valkey` connection) so the worker cannot
     * pop it before the rows exist.
     *
     * ── `type: file` RUNS THE INTAKE GATE, AND THE FILES NEVER TOUCH THIS BODY ────────────────
     *
     * `$request->file('files')` is handed to the service unread. The six-step gate of
     * `kb-security-baseline` — size, extension allow-list on the NFKC-normalized name, MIME sniffed
     * from content by libmagic, the extension/MIME cross-check, the OPC macro and embedded-object
     * refusal, and the SHA-256 — lives in `UploadIntake`, in one method, in one order, because THE
     * ORDER IS THE SECURITY PROPERTY and an order spread across a controller and a service is an
     * order nobody can read. This action decides authorization and organization status; it decides
     * nothing about bytes.
     *
     * A REFUSED BATCH IS A 422 WITH ONE ENTRY PER FILE, keyed `files.0`, `files.1`, … Nothing is
     * stored for a batch with any refusal in it — no object, no source row, no item — and every
     * refusal writes a `source.upload.rejected` audit row carrying a closed reason token, because a
     * caller grinding at the gate with crafted files otherwise leaves no trace anywhere.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * `packages/contracts/rules/StoreSourceRequest.json`, dumped from executing `rules()`, and
     * docs/22 finding 19 rules that the FormRequest is the only source of a request rule. The
     * numbers a console needs BEFORE it posts — the byte ceiling, the accepted MIME types and the
     * batch cap — are `GET .../sources/upload-limits`, which publishes the FormRequest's own
     * constants rather than a second copy of them.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => SourceResource::class],
        description: 'The created source, wrapped in `data`, already moved out of `draft` into '
            .'`queued` — creation and submission are one act, because a caller who has handed over '
            .'content has submitted it. 409 when the organization is not active; 422 for a body '
            .'whose content does not match its declared `type`, and — for `type: file` — with one '
            .'entry per refused part, keyed `files.0`, `files.1`, …, when the upload intake gate '
            .'refuses a file on size, extension, sniffed MIME, an extension/MIME disagreement, an '
            .'embedded macro or object, a decompression cap, or a duplicate of another part in the '
            .'same request. A batch with any refusal in it stores NOTHING. The response is the '
            .'SOURCE and never a job handle: progress is read by polling this resource, because the '
            .'platform reports it onto `status` rather than through a job resource clients would '
            .'have to learn.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreSourceRequest $request,
        Organization $organization,
        SourceService $sources,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT.
        Gate::authorize('createSource', $organization);

        $input = $request->toData();

        if ($input->type === SourceType::File) {
            // THE SECOND GATE, ASKED BEFORE THE REFUSAL BELOW so the permission boundary is exercised
            // on this path from the day the route exists rather than from the day the intake does.
            // A gate added at the same time as the feature is a gate nobody has ever seen deny.
            Gate::authorize('uploadSource', $organization);
        }

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        // THE PARTS, UNREAD. `$request->file('files')` returns the `UploadedFile` objects Symfony
        // built from `$_FILES`, keyed by the index the client sent — which is what lets a per-file
        // 422 name `files.0`. Nothing in this body opens one, sizes one, names one or asks it what
        // type it is: every one of those is a step of the gate, and a step performed here would be a
        // step performed twice or a step performed instead.
        $files = $request->file('files');

        $source = $sources->create(
            $organization,
            $input,
            $this->actorId(),
            $request,
            is_array($files) ? $files : [],
        );

        return response()->json([
            'data' => (new SourceResource($source))->toArray($request),
        ], 201);
    }

    /**
     * Edit one source's metadata. A PATCH: an absent field is left alone.
     *
     * ── A BODY THAT NAMES NOTHING IS REFUSED, AND THE REFUSAL IS HERE RATHER THAN IN `rules()` ─
     *
     * A request that changes nothing still returns 200 and still writes a `source.updated` audit
     * row describing an edit that did not happen, which makes the trail lie in the one direction
     * nobody checks. The declarative spelling with five optional fields is `required_without_all`
     * naming four siblings on each of five, which `UpdateProviderModelRequest` rejected as
     * unreadable.
     *
     * It is raised as a ValidationException with a real per-field map rather than a bare
     * `abort(422)`: the error envelope documents `errors` as present only on `validation` and only
     * when a producer made one, and `apps/web` discriminates the ADR-031 resolver refusal on the
     * shape `validation` WITH NO MAP. A second map-less 422 on this surface would wear that
     * signature.
     *
     * ── NOTHING HERE RE-QUEUES ANYTHING ──────────────────────────────────────────────────────
     *
     * None of the editable columns is a component of the ingest key, so an automatic reprocess
     * would produce the same key, dedupe against the completed run, and report "already processed"
     * — the exact silent no-op `force_nonce` exists to make impossible. Re-running the pipeline is
     * `POST .../reprocess`, which mints one.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SourceResource::class],
        description: 'The source after the edit, wrapped in `data`. 409 when the organization is '
            .'not active; 422 for an empty body, for a window that closes before it opens, and for '
            .'`status`, `type`, `origin_url` or `content` — each of which is refused by name with a '
            .'message pointing at the route that does perform it, because an absent rule would '
            .'discard the field silently and answer 200.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateSourceRequest $request,
        Organization $organization,
        KnowledgeSource $source,
        SourceService $sources,
    ): SourceResource {
        // CHECKS 3 AND 4.
        Gate::authorize('update', $source);

        // CHECK 5, the organization's half.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $edit = $request->toData();

        if ($edit->isEmpty()) {
            throw ValidationException::withMessages([
                'source' => 'An edit has to name at least one field. A request that changes nothing '
                    .'would still return 200 and would still write a `source.updated` audit row '
                    .'describing an edit that did not happen, which makes the trail wrong in the '
                    .'one direction nobody checks it in.',
            ]);
        }

        return new SourceResource(
            $sources->update($organization, $source, $edit, $this->actorId(), $request),
        );
    }

    /**
     * PHASE 1 OF A TWO-PHASE DELETE. The source stops answering immediately; the purge follows.
     *
     * ── THIS IS NOT A HARD DELETE, AND THE RESPONSE SAYS SO ───────────────────────────────────
     *
     * The row survives with `deleted_at` set and `status` at `deleting`, so the response is the
     * SOURCE rather than an acknowledgement: a console that has just deleted something needs to be
     * able to show that the purge is running and, later, that `purged_at` was written. An
     * acknowledgement would make "is it gone yet" unanswerable without a second endpoint.
     *
     * Phase 2 — removing vectors from Qdrant by stable identifier, removing objects, invalidating
     * the four Valkey families, and the VERIFICATION that proves each of those — is
     * `deletion-engineer`'s on both sides of the seam, and this action deliberately dispatches
     * nothing: a purge job dispatched from here would be a second implementation of an ordering
     * that has to be right once.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete of the same source is a 422 rather than a 200,
     * because `Deleting` has exactly one legal edge and it is not to itself — and a 200 would claim
     * this actor performed a deletion the trail records only once.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SourceResource::class],
        description: 'The source after phase 1, wrapped in `data`: `status` is `deleting`, '
            .'`deleted_at` is set, and `purged_at` is still null because nothing has been PROVEN '
            .'removed yet. PostgreSQL records the removal immediately and no job has to succeed for '
            .'that to be true — but NOTHING IN THIS DEPLOYMENT YET PERFORMS THE RETRIEVAL '
            .'EXCLUSION: `source_status` is a Qdrant payload field written at upsert, and the '
            .'resolved active-version set that would make the exclusion immediate is not built on '
            .'this side either. See the `TODO(phase-c)` markers on `SourceService::disable()`. '
            .'409 when the organization is not active; 422 when the source has no legal edge to '
            .'`deleting`, which includes a second delete of the same row.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        KnowledgeSource $source,
        SourceService $sources,
    ): SourceResource {
        // CHECKS 3 AND 4.
        Gate::authorize('delete', $source);

        // CHECK 5, the organization's half. The source's own half is the transition table's, asked
        // inside the service under the row lock.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new SourceResource(
            $sources->delete($organization, $source, $this->actorId(), $request),
        );
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

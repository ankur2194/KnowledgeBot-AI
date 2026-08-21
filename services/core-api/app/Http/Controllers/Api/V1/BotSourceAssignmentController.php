<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBotSourceAssignmentsRequest;
use App\Http\Requests\StoreBotSourceAssignmentRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\BotSourceAssignmentCollectionResource;
use App\Http\Resources\BotSourceAssignmentResource;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Bots\BotSourceAssignmentService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which knowledge sources one bot may answer from: list, grant, withdraw.
 *
 * ── THIS IS THE HIGHEST-STAKES CHILD SURFACE ON A BOT ─────────────────────────────────────────
 *
 * `bot_ids` is one of the four mandatory Qdrant filter terms (`kb-tenancy-isolation` NN3) and it is
 * resolved from `bot_source_assignments`, so a row here is not a preference — it is what makes a
 * corpus reachable from a bot, and every downstream check AGREES with it. `BotDomainController`
 * grants a page on the public internet the right to boot a widget; this one grants a bot the right
 * to read documents.
 *
 * ── TWO GATES, ON TWO RECORDS, AND NEITHER IS THE ORGANIZATION ────────────────────────────────
 *
 * The write names two records that could belong to two organizations, and `Permission::
 * SourcesAssign` states the split in as many words: *"It is authorized against the SOURCE
 * (`KnowledgeSourcePolicy::assign()`) and the bot is additionally authorized with `bots.view`."*
 * So:
 *
 *   THE BOT     `Gate::authorize('view', $bot)` — `bots.view`, held by all four roles, and the
 *               reason `Permission::BotsView` gives for granting it to `knowledge_manager` is
 *               literally this surface. It authorizes against the PARENT BOT RECORD, following
 *               `BotPolicy::manageChildren()`'s idiom: authorizing against the ORGANIZATION instead
 *               would pass for a caller who belongs to the right organization and is addressing a
 *               bot in it that they were never shown.
 *   THE SOURCE  `Gate::authorize('assign', $source)` — `sources.assign`, which owner, admin and
 *               knowledge_manager hold and analyst does not.
 *
 * WHY NOT `manageChildren` ITSELF: it carries `bots.manage`, which `knowledge_manager` does not
 * hold — and `BotPolicy`'s own docblock says all four roles hold `bots.view` *"knowledge_manager
 * because Phase C6 has them assign sources to bots"*. Using `manageChildren` here would deny the
 * one role the permission catalog names as this action's performer. The idiom that matters — the
 * record is the PARENT BOT and never the organization — is followed exactly; the permission is the
 * one `Permission::SourcesAssign` prescribes.
 *
 * NEITHER GATE IS THE TENANCY GUARD, and `KnowledgeSourcePolicy::assign()` says so: a caller really
 * can be a legitimate admin of the organization whose bot is named, and the write would still be a
 * cross-tenant disclosure. The guard is the pair of composite foreign keys on the table.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ────────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` returns
 * `array_values($this->parameters)[$key - 1]` — the PRECEDING bound parameter, not the first one.
 * So each parent must already be a model when its child is resolved, which it is only if the
 * signature lists them in path order: `Organization`, then `Bot`, then `BotSourceAssignment`.
 *
 * `$organization` AND `$bot` ARE UNUSED IN SOME BODIES AND MUST NOT BE REMOVED. Drop `$bot` and
 * `{bot}` stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding falls to
 * the unscoped `else` branch executed inside `SubstituteBindings`, upstream of the Gate call.
 * `#[ScopedBy(OrganizationScope::class)]` would still hold, so what is lost is the BOT predicate:
 * any assignment of any of this organization's bots would resolve under any other bot's URL.
 *
 * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
 * `Str::plural(Str::camel($childType))`, so `{sourceAssignment}` resolves through
 * `Bot::sourceAssignments()`, which exists for exactly this. `{assignment}` would derive
 * `assignments()`, which `App\Models\Bot` does not have, and every request here would 404.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all three.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which re-reads `organization_users` per request.
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `index` demands
 *    `bots.view` on the bot; `store` demands `bots.view` on the bot AND `sources.assign` on the
 *    source; `destroy` demands `bots.view` on the bot AND `sources.assign` on the source the grant
 *    names. The second gate cannot be first — there is no source record until one has been resolved
 *    from an org-scoped read — so it is asked as early as a source can exist, which is the same
 *    ordering `SourceController::store()` uses for its `uploadSource` gate.
 * 4. ENTITY OWNERSHIP — the scoped binding 404s a foreign `{bot}` or `{sourceAssignment}` at
 *    BINDING time; both policies resolve membership of THE RECORD'S organization; `source_id` is a
 *    body field and is resolved through a repository read whose organization is a required
 *    positional argument, so a foreign id is a 404 rather than an oracle; and every repository
 *    method takes `organization_id` AND `bot_id` as required positional arguments.
 * 5. ENTITY STATUS — a 409 when the organization is not Active, on `store` and `destroy`. `index`
 *    has none: reading which documents a bot may use is exactly what a suspended organization's
 *    operator needs to do. The SOURCE'S own status is checked in the service, which refuses a grant
 *    to a source that is being deleted — on `store` only. THE BOT'S OWN STATUS IS NOT CHECKED, and that is
 *    deliberate: withdrawing a grant from a published bot is the correct emergency action, and
 *    refusing it because the bot is live would make the console useless in the one moment it
 *    matters. An archived bot's grants can still be tidied, for the reason `BotDomainController`
 *    gives — an archived bot answers nobody. And the source-status check is on `store` ONLY:
 *    withdrawing a grant from a source that is being deleted is exactly what should happen next.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`. No §18.3 re-authentication:
 *    nothing here touches a credential or verifies a password, and a withdrawn grant is re-creatable
 *    in one request.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, `BotSourceAssignmentService` imports no vault, and the resource
 * renders two ULIDs, an integer, a boolean, two timestamps and a `SourceResource` — which itself
 * renders no provider field at all.
 */
final class BotSourceAssignmentController extends Controller
{
    /**
     * ONE PAGE of the sources this bot may answer from.
     *
     * ── THE ENVELOPE IS FIXED AND IS NOT THIS ACTION'S TO RESHAPE ─────────────────────────────
     *
     *     {"data": {"source_assignments": [...], "meta": {page, per_page, total, total_pages, sort, dir, filter}}}
     *
     * `apps/web/src/lib/table/envelope.ts` is written against exactly that and THROWS rather than
     * degrading when it cannot read it, because an unreadable envelope is not an empty list.
     *
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED — see the class docblock.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotSourceAssignmentCollectionResource::class],
        description: 'One page of this bot\'s source assignments, with `meta` beside the array '
            .'inside `data`. DISABLED GRANTS ARE INCLUDED — a disabled row grants nothing, and '
            .'hiding it would make "why is this bot not answering from that document" unanswerable '
            .'from the console. Each row carries the full source beside the grant, so no second '
            .'request is needed to render a name. `page` is 1-based; `per_page`, `sort`, `dir` and '
            .'`filter` are echoed AS APPLIED, which may differ from what was asked for because the '
            .'platform clamps the page size and falls back to the endpoint default sort. The '
            .'filter searches the SOURCE\'s name and crawl URL, because the grant row itself holds '
            .'no text.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function index(
        IndexBotSourceAssignmentsRequest $request,
        Organization $organization,
        Bot $bot,
        BotSourceAssignmentService $assignments,
    ): BotSourceAssignmentCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT. `view` is `bots.view`, which all four roles hold.
        Gate::authorize('view', $bot);

        $query = $request->toQuery();

        // No 409 — see the class docblock, check 5.
        return new BotSourceAssignmentCollectionResource(
            $assignments->list($organization, $bot, $query),
            $query,
        );
    }

    /**
     * Grant this bot access to one source.
     *
     * ── THE SOURCE IS RESOLVED HERE SO THE SECOND GATE HAS A RECORD TO AUTHORIZE AGAINST ─────
     *
     * `Gate::authorize('assign', $source)` needs the row. The read carries the organization as a
     * required positional argument, so an id naming another organization's source resolves to null
     * and becomes a 404 — byte-identical to a path with no route — BEFORE any policy is consulted
     * and before the caller can learn whether that id exists anywhere.
     *
     * THE SERVICE READS IT AGAIN, and the duplication is deliberate rather than an oversight. The
     * service is reachable from a console command or a job that never ran this controller, and its
     * refusals — the deleted-source check, the duplicate, the cap — are its own to make on a row it
     * resolved itself. Two org-scoped reads of one row inside one request is the cheap half of that
     * trade; a service that trusted a record handed to it by a caller is the expensive half.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * `packages/contracts/rules/StoreBotSourceAssignmentRequest.json`, dumped from executing
     * `rules()`, and docs/22 finding 19 rules that the FormRequest is the only source of a request
     * rule.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => BotSourceAssignmentResource::class],
        description: 'The grant, wrapped in `data`, with the granted source nested inside it. 409 '
            .'when the organization is not active. 422 when the bot already has this source, when '
            .'the bot is at the maximum number of assigned sources, or when the source is being '
            .'deleted. A `source_id` that names no source of THIS organization is a 404 with a body '
            .'byte-identical to a path with no route — never a validation error, which would be an '
            .'existence oracle over every customer\'s document ids rendered as a form message. The '
            .'grant does NOT make the source answerable on its own: reachability is the AND of the '
            .'organization, this row\'s `enabled`, the source\'s status and the item\'s '
            .'active-version pointer.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreBotSourceAssignmentRequest $request,
        Organization $organization,
        Bot $bot,
        BotSourceAssignmentService $assignments,
        KnowledgeSourceRepositoryInterface $sources,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT BOT — first statement, before anything reads the body.
        Gate::authorize('view', $bot);

        $input = $request->toData();

        $source = $this->requireSource($sources, $organization, $input->sourceId);

        // THE SECOND GATE, ON THE OTHER RECORD THE GRANT NAMES. It cannot come first: there is no
        // source record to authorize against until an org-scoped read has produced one, and the
        // read is what turns a foreign id into a 404 rather than a 403 that confirms it exists.
        Gate::authorize('assign', $source);

        // CHECK 5, the organization's half. The source's own half is the service's.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $assignment = $assignments->add($organization, $bot, $input, $this->actorId(), $request);

        return response()->json([
            'data' => (new BotSourceAssignmentResource($assignment, $source))->toArray($request),
        ], 201);
    }

    /**
     * Withdraw one grant.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor withdrew a grant the
     * trail does not record them withdrawing.
     *
     * ── THE SOURCE GATE IS ASKED ON THE WAY OUT TOO ──────────────────────────────────────────
     *
     * Withdrawing a grant is a change to the same fact granting it changed, so it takes the same
     * two permissions. An analyst holding `bots.view` but not `sources.assign` can therefore read
     * this list and remove nothing from it, which is the asymmetry `Permission::SourcesAssign`
     * describes.
     *
     * ── WHAT HAPPENS IF THE SOURCE IS GONE, STATED RATHER THAN ASSUMED ──────────────────────
     *
     * `requireSource()` 404s when the id resolves to nothing in this organization, so a grant whose
     * source row had vanished would be UNWITHDRAWABLE. That is a real branch in the code and it is
     * unreachable in this schema: `bot_source_assignments_source_same_org` is `ON DELETE RESTRICT`,
     * so a `knowledge_sources` row cannot be removed while any grant references it — and the source
     * DELETE endpoint is a soft delete that leaves the row in place. A source that is merely
     * `deleting` or `deleted` still resolves, so withdrawing a grant from one is the ordinary case
     * and is not refused; only the check on the way IN (`store`) cares about that status.
     *
     * If that key is ever relaxed to CASCADE, this branch becomes reachable and the right answer
     * changes — the grant would already be gone with the source — so the branch is named here
     * rather than left as a line nobody can explain.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The grant is gone. The source itself is untouched — withdrawing an assignment '
            .'removes the bot\'s access to it, not the document — and every other bot assigned to '
            .'the same source keeps answering from it. 409 when the organization is not active. A '
            .'foreign or unknown `{bot}` or `{sourceAssignment}` 404s at binding time, before this '
            .'action runs, and so does a second delete of the same grant.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        Bot $bot,
        BotSourceAssignment $sourceAssignment,
        BotSourceAssignmentService $assignments,
        KnowledgeSourceRepositoryInterface $sources,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('view', $bot);

        // AND ON THE SOURCE THE GRANT NAMES.
        Gate::authorize('assign', $this->requireSource($sources, $organization, $sourceAssignment->source_id));

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $assignments->remove($organization, $bot, $sourceAssignment, $this->actorId(), $request);

        // An acknowledgement and not the deleted resource — never a 204, because an empty
        // #[ResponseShape] would publish `"properties": []`, which is not a JSON Schema object (D8).
        return AcknowledgementResource::ok();
    }

    /**
     * One source of THIS organization, or the 404 a foreign id must produce.
     *
     * THE ORGANIZATION IS A REQUIRED POSITIONAL ARGUMENT OF THE READ, so this is the scoped lookup
     * the route binding performs for `{source}` on the sources routes — done by hand here because
     * the id arrives in a body rather than a path and no binding can scope it.
     *
     * `NotFoundHttpException` AND NOT `abort(404, …)` WITH A MESSAGE: `bootstrap/app.php` replaces
     * the message with one constant per rendered status precisely so a denied row, a row in another
     * organization and a row that never existed are one response. A message here would reopen the
     * enumeration oracle at the body while the status stayed correct.
     */
    private function requireSource(
        KnowledgeSourceRepositoryInterface $sources,
        Organization $organization,
        string $sourceId,
    ): KnowledgeSource {
        $source = $sources->find($organization->organizationId(), $sourceId);

        if ($source === null) {
            throw new NotFoundHttpException;
        }

        return $source;
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

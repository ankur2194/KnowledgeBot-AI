<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBotsRequest;
use App\Http\Requests\StoreBotRequest;
use App\Http\Requests\UpdateBotRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\BotCollectionResource;
use App\Http\Resources\BotResource;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bots\BotService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An organization's bots: list, read, create, edit, delete.
 *
 * A bot is the RETRIEVAL SCOPE. `bot_ids` is one of the four mandatory Qdrant filter terms
 * (kb-tenancy-isolation NN3), so a mistake on this surface is not "somebody saw a settings page" —
 * it is a bot answering out of a corpus, on a credential, and against a quota that authorization
 * was supposed to bound. Which is why almost none of that boundary lives in this file: the tenant
 * filter is the data plane's, the credential is the vault's, the permission is `BotPolicy`'s, and
 * this class decides an ordering of checks.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ────────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` reads the parameter bag as
 * it stands — it returns `array_values($this->parameters)[$key - 1]`, the PRECEDING bound
 * parameter and not the first one. So the parent must already have been converted to a model when
 * the child is resolved, which it is only if the signature lists them in path order. Every action
 * below therefore takes `Organization` and then `Bot`.
 *
 * `$organization` IS UNUSED IN `show()`'s BODY AND MUST NOT BE REMOVED. Drop it and
 * `{organization}` stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding
 * falls to the unscoped `else` branch — `Bot::resolveRouteBinding($id)` — executed inside
 * `SubstituteBindings`, upstream of the Gate call. `ProviderModelController`'s docblock states the
 * failure at length and it holds identically here, with one difference worth naming: there, the
 * backstop `#[ScopedBy(OrganizationScope::class)]` still held and only the CONNECTION predicate was
 * lost. Here the parent IS the organization, so the scoped binding and the backstop would both be
 * relying on the same ambient `TenantContext` — and a stale context is exactly the case the
 * explicit predicate exists for.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all five. The admin surface is
 *    the Sanctum SPA cookie session, not a bearer token.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL on
 *    every request. Neither a session value nor a token row is evidence of CURRENT membership, so a
 *    user removed from the organization stops working on the very next request.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `$this->
 *    authorize()` does not exist: since Laravel 11 the base controller no longer uses
 *    AuthorizesRequests, and calling it fatals at runtime rather than at analysis time.
 *    `index` and `show` demand `bots.view`; `store`, `update` and `destroy` demand `bots.manage`.
 *    THE SPLIT IS WIDER THAN THE PROVIDER SURFACES' — all four roles hold `bots.view`, because
 *    Phase C6 has a Knowledge Manager assign sources TO bots and Phase E has an Analyst review
 *    conversations PER bot. §6.4 and §6.5 mention bots in neither direction, so both grants are an
 *    EXTENSION of the specification decided with the repo owner, recorded in `App\Enums\Permission`
 *    and `OrgRole::grants()`, and asserted PER ACTION rather than from a uniform dataset — a
 *    uniform dataset goes green against a `view` silently mapped to `bots.manage`, and here that
 *    mis-mapping would differ only for the two roles a hand-written dataset is most likely to
 *    under-cover.
 *
 * 4. ENTITY OWNERSHIP — three layers. The scoped binding 404s a foreign or unknown `{bot}` at
 *    BINDING time, before any policy is constructed and before the row is in memory; the policy
 *    resolves membership of THE RECORD'S organization through `OrgOwned`; and every repository
 *    method takes `organization_id` as a required positional argument. `index` and `store`
 *    authorize against the PARENT ORGANIZATION, because there is no bot row yet to take an
 *    organization from — `OrganizationPolicy::viewBots()` and `::createBot()` are that half.
 *
 * 5. ENTITY STATUS — an explicit line of its own on `store`, `update` and `destroy`: a 409 when the
 *    organization is not Active, carrying `OrganizationStatus::SUSPENDED_REFUSAL`. `update` carries
 *    two more, and both live in `BotService` because both are decided against the state the edit
 *    LEAVES the bot in rather than against the body: an archived bot is read-only, and the publish
 *    guard refuses a bot that would end up `published` with no model or with `rag_first` and the
 *    escape hatch closed. `index` and `show` deliberately have NONE — reading which bots exist and
 *    why one is not answering is exactly what a suspended organization's operator needs to do while
 *    working out why, and it changes nothing.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    NOT performed here. Nothing on this surface touches a credential or verifies a password, and
 *    the one destructive action, `destroy`, removes a row this organization owns outright rather
 *    than a key that authenticates it. A confirmation belongs in the console, not in a second
 *    password prompt.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, `BotService` imports no vault, and `BotResource` renders no field of
 * the referenced connection except its ULID. A bot listing therefore performs zero decryptions and
 * has no masked form to leak — asserted, from rows whose plaintext really was sealed, in
 * tests/Security/BotEndpointAccessTest.php.
 */
final class BotController extends Controller
{
    /**
     * ONE PAGE of this organization's bots — the first paginated endpoint in this API.
     *
     * ── THE ENVELOPE IS FIXED AND IS NOT THIS ACTION'S TO RESHAPE ─────────────────────────────
     *
     *     {"data": {"bots": [...], "meta": {page, per_page, total, total_pages, sort, dir, filter}}}
     *
     * `meta` is a SIBLING of the collection INSIDE `data`. `apps/web/src/lib/table/envelope.ts` is
     * already written against exactly that and THROWS rather than degrading when it cannot read it,
     * because an unreadable envelope is not an empty list — `{rows: [], rowCount: 0}` would render
     * the first-run "Create your first bot" state to an administrator whose organization has two
     * hundred of them.
     *
     * ── WHY THE QUERY STRING GOES THROUGH A FormRequest AT ALL ────────────────────────────────
     *
     * Because `sort` reaches an `ORDER BY` and `per_page` decides how much work one caller may ask
     * the database for. `IndexBotsRequest` closes the sortable set with `Rule::in(...)` and caps
     * `per_page` at `ListQuery::MAX_PER_PAGE`; without both, a list endpoint is a query whose cost
     * and plan the caller chooses. It also puts the parameters in
     * `packages/contracts/rules/IndexBotsRequest.json`, which is the only place a generated client
     * learns they exist.
     *
     * NO 409 and no 422 beyond the query string: there is no request body to reject, and reading
     * the list is what a suspended organization's administrator most needs to be able to do.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotCollectionResource::class],
        description: 'One page of this organization\'s bots, with `meta` beside the array inside '
            .'`data`. Every lifecycle state is included — drafts and archived rows alike, because '
            .'an operator asking "where did that bot go" has to be able to find it. `page` is '
            .'1-based; `per_page`, `sort`, `dir` and `filter` are echoed AS APPLIED, which may '
            .'differ from what was asked for because the platform clamps the page size and falls '
            .'back to the endpoint default sort.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function index(
        IndexBotsRequest $request,
        Organization $organization,
        BotService $bots,
    ): BotCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT. A list has no bot row to take an organization from, and
        // the organization is the right scope anyway — it is the record whose bots are being read.
        // `viewBots` is `bots.view`, which all four roles hold.
        Gate::authorize('viewBots', $organization);

        $query = $request->toQuery();

        // No 409 — see the class docblock, check 5.
        return new BotCollectionResource($bots->list($organization, $query), $query);
    }

    /**
     * One bot's whole configuration.
     *
     * The row is already in memory: `->scopeBindings()` resolved it through `$organization->bots()`,
     * so there is no second query to scope and no service call to make. The policy is what refuses
     * the remaining case — the row IS in this organization and the caller's role is wrong.
     *
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED. See the class docblock: the
     * parameter is what makes `{organization}` a bound model when `{bot}` is resolved, and without
     * it the binding falls to an unscoped global lookup executed upstream of the Gate call below.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotResource::class],
        description: 'One bot, wrapped in `data`, including its system instruction — this is an '
            .'authenticated-only shape and the public chat surfaces publish their own, much '
            .'smaller, resource. A foreign or unknown `{bot}` 404s at binding time, before this '
            .'action runs, and the body is byte-identical to the 404 for a path with no route.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function show(Organization $organization, Bot $bot): BotResource
    {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name.
        Gate::authorize('view', $bot);

        return new BotResource($bot);
    }

    /**
     * Create one bot, in `draft`.
     *
     * AUTHORIZED AGAINST THE PARENT ORGANIZATION, deliberately: the row does not exist yet, so
     * there is nothing to take an organization from. `OrganizationPolicy::createBot()` carries
     * `bots.manage`, which a Knowledge Manager and an Analyst do not hold.
     *
     * A DUPLICATE SLUG IS A 422 AND NOT A 500, in two layers: an org-scoped pre-flight query in
     * `BotService` for the readable message keyed on the `slug` field, and a catch of SQLSTATE
     * 23505 on `bots_org_slug_unique` for the race that check cannot win. The index is the
     * authority; the check is the good error. Neither is a `unique:` validation rule — see
     * `StoreBotRequest` for why a `unique:` rule on a tenant-owned column is the shape of Filament
     * CVE-2026-48067, and for the second reason that applies only here: the slug is unique PER
     * ORGANIZATION, so an unscoped rule would refuse a handle another tenant happens to have taken
     * and turn the form into an existence oracle over the platform.
     *
     * `status` IS NOT SETTABLE HERE. A bot is created `draft`, always; publishing is a transition
     * and it has a route. `StoreBotRequest` and `NewBot` both record why.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * `packages/contracts/rules/StoreBotRequest.json`, dumped from executing `rules()`, and docs/22
     * finding 19 rules that the FormRequest is the only source of a request rule.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => BotResource::class],
        description: 'The created bot, wrapped in `data`, in `draft` with a freshly minted '
            .'`public_bot_id`. 409 when the organization is not active; 422 for a duplicate slug '
            .'(keyed on `slug`), for a (connection, model) pair this organization cannot use, or '
            .'for a theme colour the renderer would refuse.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreBotRequest $request,
        Organization $organization,
        BotService $bots,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT.
        Gate::authorize('createBot', $organization);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $bot = $bots->create($organization, $request->toData(), $this->actorId(), $request);

        return response()->json([
            'data' => (new BotResource($bot))->toArray($request),
        ], 201);
    }

    /**
     * Edit one bot. A PATCH: an absent field is left alone.
     *
     * ── A BODY THAT NAMES NOTHING IS REFUSED, AND THE REFUSAL IS HERE RATHER THAN IN `rules()` ─
     *
     * `UpdateProviderConnectionRequest` refuses its empty body declaratively and states the cost of
     * not doing so: a request that changes nothing still returns 200 and still writes an `updated`
     * audit row describing an edit that did not happen, which makes the trail lie in the one
     * direction nobody checks. With twenty-five optional fields the declarative spelling is
     * `required_without_all` naming twenty-four siblings on each of twenty-five fields — the exact
     * construction `UpdateProviderModelRequest` rejected as unreadable, where the answer was to make
     * the endpoint a PUT. That is not available here, because a bot's complete state includes
     * columns this endpoint must never accept.
     *
     * It is raised as a ValidationException with a real per-field map rather than as a bare
     * `abort(422)`, and that is not fussiness: the error envelope documents `errors` as "present
     * only on `validation`, and only when a producer made one", and `apps/web` discriminates the
     * ADR-031 resolver refusal on the shape `validation` WITH NO MAP. A second map-less 422 on this
     * surface would be a second thing wearing that signature.
     *
     * ── EVERYTHING ELSE THIS ACTION REFUSES IS DECIDED IN `BotService`, ON THE RESULTING STATE ─
     *
     * An archived bot is read-only, including its status. The publish guard refuses a bot that would
     * END UP `published` with no model, or in `rag_first` with `allow_general_answers` still false —
     * and it is evaluated against the resulting state rather than against the transition, so
     * CLEARING the model on an already-published bot is refused by the same check that refuses
     * publishing a model-less draft. A transition-only guard would miss the first case entirely
     * while looking correct.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotResource::class],
        description: 'The bot after the edit, wrapped in `data`. `retrieval_configuration_version` '
            .'has moved if and only if a retrieval knob\'s VALUE changed. 409 when the organization '
            .'is not active, when the bot is archived (which is terminal and read-only, including '
            .'its status), or when the publish guard refuses the resulting state; 422 for an empty '
            .'body, a duplicate slug, an unusable (connection, model) pair, an evidence threshold '
            .'without its scale, or a theme colour the renderer would refuse.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateBotRequest $request,
        Organization $organization,
        Bot $bot,
        BotService $bots,
    ): BotResource {
        // CHECKS 3 AND 4.
        Gate::authorize('update', $bot);

        // CHECK 5, the organization's half. The bot's own two halves are BotService's, because both
        // are decided against the state this edit leaves the row in.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $edit = $request->toData();

        if ($edit->isEmpty()) {
            throw ValidationException::withMessages([
                'bot' => 'An edit has to name at least one field. A request that changes nothing '
                    .'would still return 200 and would still write a `bot.updated` audit row '
                    .'describing an edit that did not happen, which makes the trail wrong in the '
                    .'one direction nobody checks it in.',
            ]);
        }

        return new BotResource(
            $bots->update($organization, $bot, $edit, $this->actorId(), $request),
        );
    }

    /**
     * A HARD DELETE: the bot goes, its origin allow-list, starter questions and fallback chain go
     * with it, and the audit row is the only thing that survives.
     *
     * ── THE CHILDREN ARE REMOVED IN CODE, NOT BY THE DATABASE ─────────────────────────────────
     *
     * All three child tables reference `bots (organization_id, id)` with `ON DELETE RESTRICT`, so
     * this is a deliberate cascade written at the one call site that performs it rather than a
     * property of the schema — `EloquentBotRepository::delete()` records why the keys are not
     * CASCADE, and the short form is that a fourth child table added later fails loudly there
     * instead of being swept away silently.
     *
     * ── `BotStatus::isEditable()` IS DELIBERATELY NOT CONSULTED HERE ──────────────────────────
     *
     * An archived bot refuses every EDIT and accepts a delete. Using the same predicate for both
     * would make an archived bot undeletable, which is the opposite of what archiving means: the
     * status says the configuration is a historical record, not that the row is immortal.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor performed a deletion
     * the trail does not record.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The bot is gone, along with its origin allow-list, its starter questions and '
            .'its fallback chain. 409 when the organization is not active. A foreign or unknown '
            .'`{bot}` 404s at binding time, before this action runs, and so does a second delete of '
            .'the same bot.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        Bot $bot,
        BotService $bots,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4.
        Gate::authorize('delete', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $bots->delete($organization, $bot, $this->actorId(), $request);

        // An acknowledgement and not the deleted resource: the only thing the caller learns is that
        // it happened, and `index` is where the new set is read. Never a 204 — every success body
        // on this surface carries a `data` key, and an empty #[ResponseShape] would publish
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

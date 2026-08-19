<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBotDomainRequest;
use App\Http\Requests\UpdateBotDomainRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\BotDomainCollectionResource;
use App\Http\Resources\BotDomainResource;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bots\BotDomainService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One bot's widget origin allow-list: list, add, promote or withdraw, remove.
 *
 * A ROW HERE IS A SECURITY CONTROL AND NOT A PREFERENCE. It is what lets a page on the public
 * internet boot a chat widget that speaks with this organization's credential, on this
 * organization's corpus, against this organization's quota — and every downstream check AGREES with
 * it, because it has been told that this origin belongs to that bot. There is no later layer that
 * catches a bad row; the row simply works.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ────────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` reads the parameter bag as
 * it stands — it returns `array_values($this->parameters)[$key - 1]`, the PRECEDING bound parameter
 * and not the first one. So each parent must already have been converted to a model when its child
 * is resolved, which it is only if the signature lists them in path order. Every action below
 * therefore takes `Organization`, then `Bot`, then `BotDomain`.
 *
 * `$organization` AND `$bot` ARE UNUSED IN SOME BODIES AND MUST NOT BE REMOVED. Drop `$bot` and
 * `{bot}` stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding falls to
 * the unscoped `else` branch — `BotDomain::resolveRouteBinding($id)` — executed inside
 * `SubstituteBindings`, upstream of the Gate call. `ProviderModelController`'s docblock states the
 * failure at length and it holds identically here, with the same shape of consequence it names:
 * `#[ScopedBy(OrganizationScope::class)]` on `BotDomain` would still append the organization
 * predicate, so what is lost is the BOT predicate — any allow-list entry of any of this
 * organization's bots would resolve under any other bot's URL. No cross-tenant test can see that,
 * which is why tests/Security/BotChildEndpointAccessTest.php asserts it on its own.
 *
 * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
 * `Str::plural(Str::camel($childType))`, so `{domain}` resolves through `Bot::domains()`, which
 * exists for exactly this. `{botDomain}` would derive `botDomains()`, which does not, and every
 * request here would 404.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all four. The admin surface is
 *    the Sanctum SPA cookie session, not a bearer token.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL on
 *    every request. Neither a session value nor a token row is evidence of CURRENT membership.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `$this->
 *    authorize()` does not exist: since Laravel 11 the base controller no longer uses
 *    AuthorizesRequests, and calling it fatals at runtime rather than at analysis time.
 *    `index` demands `bots.view` through `BotPolicy::view()`, which all four roles hold —
 *    `Permission::BotsView` names "a bot's configuration, its origin allow-list, and its starter
 *    questions" in as many words. The three WRITES demand `bots.manage` through
 *    `BotPolicy::manageChildren()`, which only owner and admin hold.
 *
 *    `manageChildren` AUTHORIZES AGAINST THE PARENT BOT AND NOT AGAINST THE CHILD ROW, on every
 *    write including the ones that have a child row in hand. That is the ability's own design and
 *    the reason is in its docblock: the composite foreign key the write is checked against is
 *    `(organization_id, bot_id)`, so the bot is the correct scope — and authorizing against the
 *    ORGANIZATION instead would pass for a caller who belongs to the right organization and is
 *    addressing a bot in it they were never shown. Until this file existed the ability had NO CALL
 *    SITE, and the three child models still have no policies of their own, so
 *    `Gate::authorize('update', $domain)` would silently DENY — no policy means deny.
 *
 * 4. ENTITY OWNERSHIP — three layers. The scoped binding 404s a foreign or unknown `{bot}` or
 *    `{domain}` at BINDING time, before any policy is constructed and before the row is in memory;
 *    the policy resolves membership of THE PARENT BOT'S organization through `OrgOwned`; and every
 *    repository method takes `organization_id` AND `bot_id` as required positional arguments.
 *
 * 5. ENTITY STATUS — an explicit line of its own on `store`, `update` and `destroy`: a 409 when the
 *    organization is not Active, carrying `OrganizationStatus::SUSPENDED_REFUSAL`. `index`
 *    deliberately has NONE — reading which origins are allowed is exactly what a suspended
 *    organization's operator needs to do while working out why an embed stopped, and it changes
 *    nothing.
 *
 *    THE BOT'S OWN STATUS IS DELIBERATELY NOT CHECKED, and it is the omission a later reader is
 *    most likely to mistake for a gap. `BotStatus::isEditable()` refuses every write to an ARCHIVED
 *    bot because its configuration is the record of what answered past conversations — and an
 *    archived bot answers nobody on any channel, so its allow-list grants nothing and removing an
 *    origin from it is a CLEANUP rather than a rewrite of history. Refusing that would make a
 *    stale grant permanently unremovable, which is the wrong direction for a security control.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    NOT performed here: nothing on this surface touches a credential or verifies a password, and
 *    removing an origin narrows access rather than widening it.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, `BotDomainService` imports no vault, and `BotDomainResource` renders
 * an origin, a status and two timestamps. An allow-list listing performs zero decryptions.
 */
final class BotDomainController extends Controller
{
    /**
     * Every entry on this bot's allow-list.
     *
     * A WRAPPER OBJECT AND NOT A BARE ARRAY — `{"data": {"domains": [...]}}`. The reason is
     * mechanical: `#[ResponseShape]` maps a response KEY to a resource class and cannot express
     * "an array of", and every published component must carry `additionalProperties: false`, which
     * an array-typed schema cannot. See `BotDomainCollectionResource`.
     *
     * NO 409 and no 422: there is no request body to reject, and reading the allow-list is what a
     * suspended organization's administrator most needs to be able to do.
     *
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED — see the class docblock.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotDomainCollectionResource::class],
        description: 'Every origin on this bot\'s widget allow-list, PENDING AND DISABLED ONES '
            .'INCLUDED — an operator asking "why is my widget refused on this site" has to be able '
            .'to find the row. Ordered by origin, deterministically; the order carries no '
            .'precedence, because an allow-list is a set and the lookup is an exact match. AN '
            .'EMPTY ARRAY DENIES EVERY ORIGIN and must never be read as "unrestricted".',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        Bot $bot,
        BotDomainService $domains,
    ): BotDomainCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT. A list has no entry row to take an organization from,
        // and the bot is the right scope anyway — it is the record whose allow-list is being read.
        // `view` is `bots.view`, which all four roles hold.
        Gate::authorize('view', $bot);

        // No 409 — see the class docblock, check 5.
        return new BotDomainCollectionResource($domains->list($organization, $bot));
    }

    /**
     * Add one origin, always `pending`.
     *
     * AUTHORIZED AGAINST THE PARENT BOT, deliberately: the row does not exist yet, so there is
     * nothing to take an organization from — and `manageChildren` is the correct scope anyway,
     * since `(organization_id, bot_id)` is what the write is about to be checked against.
     *
     * THE STORED VALUE IS NOT THE POSTED VALUE, and the response is where the caller finds out.
     * `StoreBotDomainRequest::toOrigin()` returns the RFC 6454 serialisation — lower-cased, one
     * trailing slash dropped, a default port dropped — so the console must render `origin` from
     * THIS RESPONSE rather than echoing the field it submitted, or it will show the operator a
     * string that is not what the browser will be compared against. `ExactOrigin` records why each
     * of those three normalisations is identity-preserving and why a non-empty path is REFUSED
     * rather than trimmed.
     *
     * A DUPLICATE IS A 422 AND NOT A 500, in two layers: an org-and-bot-scoped pre-flight query in
     * `BotDomainService` for the readable message keyed on `origin`, and a catch of SQLSTATE 23505
     * on `bot_domains_org_bot_origin` for the race that check cannot win. Neither is a `unique:`
     * validation rule — see `StoreBotDomainRequest` for why an unscoped one on this column would be
     * an existence oracle over every customer's embed sites.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * `packages/contracts/rules/StoreBotDomainRequest.json`, dumped from executing `rules()`, and
     * docs/22 finding 19 rules that the FormRequest is the only source of a request rule.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => BotDomainResource::class],
        description: 'The stored entry, wrapped in `data`, always in `pending` — a new origin '
            .'grants nothing until it is activated, because whether an origin is under the '
            .'operator\'s control is not something the entry form knows. RENDER `origin` FROM THIS '
            .'RESPONSE, not from what was posted: the value is normalised to the exact string a '
            .'browser sends. 409 when the organization is not active; 422 for a duplicate origin '
            .'(keyed on `origin`), for a full allow-list, or for anything that is not an exact '
            .'origin — a wildcard, a path, a query string, userinfo, an IPv6 literal, a bad port '
            .'or a host the grammar cannot store.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreBotDomainRequest $request,
        Organization $organization,
        Bot $bot,
        BotDomainService $domains,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $domain = $domains->add(
            $organization,
            $bot,
            $request->toOrigin(),
            $this->actorId(),
            $request,
        );

        return response()->json([
            'data' => (new BotDomainResource($domain))->toArray($request),
        ], 201);
    }

    /**
     * Promote, withdraw or re-enable one entry.
     *
     * ── THE BODY CARRIES `status` AND NOTHING ELSE, AND `origin` IS NOT EDITABLE ──────────────
     *
     * Enforced three ways rather than by the absence of a rule: `UpdateBotDomainRequest` declares no
     * `origin` field, `BotDomainService::changeStatus()` has no argument to hold one, and the
     * repository never assigns the column outside the create path. Editing an origin in place would
     * carry an existing promotion across to a DIFFERENT origin — a grant moved silently, with the
     * `bot.domain.created` row still naming the old value. Remove and re-add; the trail then says
     * both things happened.
     *
     * A NO-OP IS A 422 keyed on `status`: setting `active` on an already-active row would write a
     * `bot.domain.status_changed` audit row claiming a promotion that did not happen, on the one
     * table whose trail exists to say exactly when an origin started and stopped granting an embed.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotDomainResource::class],
        description: 'The entry after the change, wrapped in `data`. `origin` is immutable and the '
            .'request body carries no field for it. 409 when the organization is not active; 422 '
            .'when the entry already holds the requested status. A foreign or unknown `{bot}` or '
            .'`{domain}` 404s at binding time, before this action runs — including an entry that '
            .'exists but belongs to a DIFFERENT bot of the same organization.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateBotDomainRequest $request,
        Organization $organization,
        Bot $bot,
        BotDomain $domain,
        BotDomainService $domains,
    ): BotDomainResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT — see the class docblock for why `manageChildren` takes
        // the bot even when a child row is in hand.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new BotDomainResource($domains->changeStatus(
            $organization,
            $bot,
            $domain,
            $request->toStatus(),
            $this->actorId(),
            $request,
        ));
    }

    /**
     * Remove one origin from the allow-list.
     *
     * A HARD DELETE, and the `bot.domain.deleted` audit row is the only thing that survives it —
     * which is what makes `origin` and `status` load-bearing there rather than decorative:
     * `subject_id` resolves to nothing afterwards. That row plus the `bot.domain.created` row is
     * what closes finding L2 for an entry removed before the bot was.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor removed a grant the
     * trail does not record them removing.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The origin is off the allow-list. 409 when the organization is not active. A '
            .'foreign or unknown `{bot}` or `{domain}` 404s at binding time, before this action '
            .'runs, and so does a second delete of the same entry.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        Bot $bot,
        BotDomain $domain,
        BotDomainService $domains,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $domains->remove($organization, $bot, $domain, $this->actorId(), $request);

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

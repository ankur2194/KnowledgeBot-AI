<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatSessionResource;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use App\Services\Chat\PlaygroundSessionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/organizations/{organization}/bots/{bot}/playground-session` — issue the D5
 * playground's chat credential.
 *
 * ═══ THE WHOLE POINT: `rt/v1` STILL RESOLVES EXACTLY ONE MECHANISM ═════════════════════════
 *
 * `routes/api_public.php` states the rule as a decision rather than a gap: *"`ResolveChatSession`
 * resolves ONE mechanism, the `kbw_` chat session, and adding a second is a deliberate change to the
 * four-mechanisms rule, not an omission to patch in a later route."* This route is what makes the
 * playground work WITHOUT touching that: the admin console calls it with its ordinary Sanctum SPA
 * cookie session, receives a `kbw_`-shaped bearer, and then uses the completely unchanged streaming
 * path. `ResolveChatSession` is not modified by this change and still reads
 * `$request->bearerToken()` and nothing else.
 *
 * What changed is not how many credential TYPES the runtime reads. It is who may be issued one of
 * the one type, and what that credential's server-side record says about them —
 * `WidgetSessionService::mintPlayground()` writes `kind: playground`, and `resolve()` derives
 * `actor_type: user` and `diagnostics: true` from that single stored field.
 *
 * ═══ THE SIX CHECKS (kb-security-baseline §18.4) ═══════════════════════════════════════════
 *
 *   1. AUTHENTICATED IDENTITY   `auth:sanctum` on the route group — the SPA cookie session, plus
 *                               `verified`. There is no bearer credential on this surface at all:
 *                               `RejectBearerToken` refuses one on `api/*` by design.
 *   2. ORGANIZATION MEMBERSHIP  `org.member`, re-reading `organization_users` from PostgreSQL on
 *                               every request.
 *   3. ROLE / PERMISSION        `Gate::authorize('update', $bot)` -> `bots.manage`, i.e. owner and
 *                               administrator only. NOT `bots.view`, which all four roles hold. A
 *                               playground turn spends the organization's provider quota and writes
 *                               a `conversations` row, so it is a WRITE wearing a chat control, and
 *                               the shipped panel hides its composer on `canManage === false` — a UI
 *                               check, which is not one of the six. There is deliberately no
 *                               `bots.playground` permission: it would be granted to exactly the
 *                               same two roles, and a permission nobody grants differently is a
 *                               permission that fails silently in both directions
 *                               (`App\Enums\Permission`, and the same argument `BotPolicy` gives for
 *                               there being no `publish` ability).
 *   4. ENTITY OWNERSHIP         `->scopeBindings()` resolves `{bot}` through `$organization->bots()`,
 *                               so a foreign or unknown id 404s at BINDING time — before the policy
 *                               and before the row is in memory. The policy then resolves membership
 *                               of THE RECORD'S organization, so a cross-org caller who somehow held
 *                               a bound row is still refused. A caller who IS a member of this
 *                               organization but lacks the permission gets the admin surface's 403.
 *   5. ENTITY STATUS            TWO halves, both here because both are facts about a row the caller
 *                               has already proved they may reach. The organization must be Active,
 *                               and the bot must be PLAYGROUND-REACHABLE — `testing` or `published`.
 *                               `BotStatus::isPlaygroundReachable()` carries why `draft`, `paused`
 *                               and `archived` are refused, and why that is a second narrow method
 *                               rather than a widening of `isRetrievable()`.
 *   6. RATE LIMIT               `throttle:admin`'s (organization, user) budget. No second limiter and
 *                               no §18.3 re-authentication: this mint touches no credential and
 *                               verifies no password, and the thing it enables — a chat turn — has
 *                               its own four-scope sliding window inside `ChatGate`, which is where
 *                               the money is actually spent. A second limiter here would refuse
 *                               requests the real one would have allowed.
 *
 * ═══ CHECK 5 IS NOT THE REVOCATION STORY AND MUST NOT BE MISTAKEN FOR ONE ══════════════════
 *
 * Everything above decides whether a credential is ISSUED. What decides whether it still WORKS is
 * `WidgetSessionService::resolve()`, which re-reads the bot's status and the actor's live membership
 * and `bots.manage` grant on EVERY request the bearer is used on. A demotion, a suspension or a bot
 * moved back to `draft` between the mint and the next turn is refused there, with the public runtime
 * surface's 404. That is the same shape the widget's credential has — its allow-list is re-read per
 * request — and it is why neither token needs to be short-lived to be revocable.
 *
 * ═══ THE RESPONSE CARRIES A LIVE CREDENTIAL AND NOTHING ELSE ═══════════════════════════════
 *
 * `ChatSessionResource` — `{token, expires_in}`, the SAME resource the SDK mint returns, reused
 * rather than copied so there is one description of what a chat-session response is. No provider
 * credential, no bot secret, no configuration, no `session_id`: the body is a bearer and its
 * lifetime. `no-store` for the reason the SDK mint sets it — a credential in an intermediary's cache
 * is a credential for whoever shares that cache.
 *
 * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's public
 * methods to the seven resource verbs plus `__construct`, `__invoke` and `middleware` — the same
 * reason `BotStatusController` and `RotateProviderCredentialController` exist.
 */
final class PlaygroundSessionController extends Controller
{
    /**
     * `$organization` IS UNUSED AS A SCOPE AND MUST NOT BE REMOVED — see `BotStatusController` for
     * the full failure. `ImplicitRouteBinding::resolveForRoute()` converts a path segment to a model
     * only if the ACTION'S SIGNATURE declares it, and `Route::parentOfParameter()` requires the
     * preceding parameter to already BE a `UrlRoutable`. Drop it and `{organization}` stays a raw
     * string, so `{bot}` falls to the unscoped `Bot::resolveRouteBinding($id)` inside
     * `SubstituteBindings` — upstream of the Gate call below. It is also read here, for check 5.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => ChatSessionResource::class],
        description: 'A short-lived chat-session token for the admin playground, bound to one bot '
            .'and to the acting administrator. It is the SAME `kbw_` credential the public chat '
            .'runtime already resolves — `POST /rt/v1/conversations` and '
            .'`POST /rt/v1/conversations/{conversation}/messages` accept it unchanged — and it '
            .'differs only in what its server-side record says: the turn is recorded on the '
            .'`playground` channel against the acting user, and `retrieval.trace` is forwarded on '
            .'the stream. The other three internal frames (`provider.usage`, `provider.fallback`, '
            .'`heartbeat`) stay refused to it exactly as they are to every other client. THIS BODY '
            .'CONTAINS A LIVE CREDENTIAL: never log it, never put it in a URL. 403 when the caller '
            .'may view the bot but not manage it — `bots.view` is not enough, because a playground '
            .'turn spends the organization\'s provider quota. 409 when the organization is not '
            .'active, or when the bot is `draft`, `paused` or `archived`: only `testing` and '
            .'`published` are reachable from the playground.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function __invoke(
        Request $request,
        Organization $organization,
        Bot $bot,
        PlaygroundSessionService $playground,
    ): JsonResponse {
        // CHECKS 3 AND 4 — on the ROW, so `BotPolicy` resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name. `update` and not a
        // new ability: see the class docblock.
        Gate::authorize('update', $bot);

        // CHECK 5, first half. A suspended organization may not spend its provider quota, and every
        // playground turn does.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        // CHECK 5, second half — the check `Gate::authorize()` structurally cannot make, because a
        // policy authorizes a CALLER and not a STATE. `draft` is refused on the authority of its own
        // case comment in `BotStatus`: *"Never reachable from any channel, including the admin
        // playground."*
        abort_unless(
            $bot->status->isPlaygroundReachable(),
            409,
            'This bot cannot be run from the playground while it is '.$bot->status->value.'. Move it '
            .'to testing or published first.',
        );

        $session = $playground->mint($bot, $this->actor()->id, $request);

        return response()
            ->json(['data' => (new ChatSessionResource($session))->toArray($request)], 201)
            // A LIVE CREDENTIAL. `private` alone is not enough: it permits the browser's own cache,
            // and this response must not be replayable from a back button.
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The authenticated administrator, whose id becomes the session's actor.
     *
     * NEVER USED AS A SCOPE — the organization is. It is the value that ends up in
     * `conversations.user_id`, in the audit row's `actor_id`, and as the internal request's actor
     * id, all three of which identify a person rather than bound a query.
     */
    private function actor(): User
    {
        $user = request()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}

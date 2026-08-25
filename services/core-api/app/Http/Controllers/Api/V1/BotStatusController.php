<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBotStatusRequest;
use App\Http\Resources\BotResource;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bots\BotService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * The bot lifecycle transition: draft → testing → published → paused → archived, in any order the
 * guard permits.
 *
 * ── WHY THIS IS ITS OWN ROUTE AND ITS OWN CONTROLLER ──────────────────────────────────────────
 *
 * `status` used to be one of twenty-five optional fields on `PATCH /bots/{bot}`, and it was the
 * only one of them that decides whether an END USER can reach the bot at all. Two doors to that
 * column are two places a check has to be — the same argument that put credential rotation on its
 * own route rather than on the connection PATCH, and it lands the same way here even though the
 * PERMISSION does not differ between the two doors. What differs is CHECK 5: a rename is refused
 * only for an archived bot, and a publish is additionally refused for a bot with no model or with
 * `rag_first` and the escape hatch closed. A transition endpoint is where that is impossible to
 * miss.
 *
 * The PATCH now declares `status` as `missing` rather than dropping the rule, because an absent
 * rule means `validated()` SILENTLY DISCARDS the field: a client that had not been updated would
 * publish a bot, receive a 200, and find it still in `draft`. `missing` and not `prohibited` —
 * `prohibited` passes for `null`, `""` and `[]`, and `UpdateBotRequest` records the measurement.
 *
 * A SINGLE-ACTION CONTROLLER because `arch()->preset()->laravel()` limits a controller's public
 * methods to the seven resource verbs plus `__construct`, `__invoke` and `middleware` — the same
 * reason `RotateProviderCredentialController` and `ResendInvitationController` exist. A `transition`
 * method on `BotController` would fail the arch suite.
 *
 * ── THE GUARD IS NOT HERE, AND THAT IS THE WHOLE DESIGN ───────────────────────────────────────
 *
 * `BotService::transition()` builds a one-column `BotEdit` and hands it to `BotService::update()`,
 * so every refusal on this path is the same code as on the PATCH: the archived read-only rule, the
 * publish guard, the row lock, the `retrieval_configuration_version` decision and the `bot.updated`
 * audit row. A copy of `assertPublishable()` here would be the copy that drifts — and it is subtle
 * enough to drift silently, because it runs on the state the write LEAVES the bot in rather than on
 * the transition. That reading is what makes "clear the model on an already-published bot" refuse
 * by the same check that refuses "publish a model-less draft", and a transition-only guard would
 * miss the first case entirely while looking correct.
 *
 * ALL THREE REFUSALS ARE NOW IMPLEMENTED, and this paragraph used to say the third was missing.
 * `BotPolicy` lists three: no model, no ASSIGNED SOURCE, and `allow_general_answers` false in
 * RAG-first mode. The second was blocked on `bot_source_assignments`, which Phase C created, so the
 * `TODO(phase-c)` in `BotService::assertPublishable()` is discharged: the check is
 * `BotService::PUBLISH_NEEDS_ASSIGNED_SOURCE` and this endpoint is what carries it. An assignment
 * that exists but is switched off does not count — a disabled grant is what `enabled: false` means.
 *
 * ── WHAT THE GUARD DELIBERATELY DOES NOT LOOK AT ──────────────────────────────────────────────
 *
 * `access_mode`. `published` + `public` is exactly what makes a bot answerable ANONYMOUSLY, and
 * this endpoint will publish such a bot with an EMPTY origin allow-list without comment. That is a
 * legitimate intermediate state and hosted chat serves it correctly; the refusal belongs at the
 * embed check, expressed as "some active row matches this exact origin" — false for the empty set
 * by construction — and never as "no row forbids it", which is true for it.
 * `BotDomainStatus::permitsEmbedding()` carries that rule and still has no consumer.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ───────────────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, re-reading `organization_users` per request.
 * 3. ROLE / PERMISSION — `Gate::authorize('update', $bot)`, i.e. `bots.manage`, owner and admin
 *    only. There is deliberately NO `bots.publish` permission: it would be granted to exactly the
 *    same two roles, and a permission nobody grants differently is a permission that fails silently
 *    in both directions (`App\Enums\Permission`).
 * 4. ENTITY OWNERSHIP — the scoped binding 404s a foreign or unknown `{bot}` at BINDING time; the
 *    policy resolves membership of THE RECORD'S organization; the repository takes the organization
 *    as a required positional argument.
 * 5. ENTITY STATUS — the organization's half here (409 when it is not Active) and the bot's two
 *    halves in `BotService`, both decided against the state the write leaves behind.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`. No §18.3 re-authentication:
 *    publishing exposes a bot the organization owns rather than touching a credential, and a
 *    confirmation belongs in the console.
 */
final class BotStatusController extends Controller
{
    /**
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED. `ImplicitRouteBinding::
     * resolveForRoute()` converts a path segment to a model only if the ACTION'S SIGNATURE declares
     * it, and `Route::parentOfParameter()` requires the preceding parameter to already BE a
     * `UrlRoutable`. Drop it and `{organization}` stays a raw string, so `{bot}` falls to the
     * unscoped `Bot::resolveRouteBinding($id)` executed inside `SubstituteBindings` — upstream of
     * the Gate call below. `BotController`'s docblock states the failure at length, including the
     * detail that matters here: the parent IS the organization, so the scoped binding and the
     * `#[ScopedBy]` backstop would both be leaning on the same ambient `TenantContext`.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotResource::class],
        description: 'The bot after the transition, wrapped in `data`, with `system_instruction` '
            .'and `answer_style_instruction` populated — reaching this endpoint requires '
            .'`bots.manage`, which is the permission that projection is gated on. 409 when the '
            .'organization is not active, when the bot is ARCHIVED (which is terminal and '
            .'read-only, including its status, so there is no un-archive transition), or when the '
            .'publish guard refuses the resulting state: no provider connection and model, or '
            .'`rag_first` answer mode with `allow_general_answers` still false. 422 when the bot '
            .'already holds the requested status — this is a transition rather than a state '
            .'assertion, and a no-op would write an audit row describing a change that did not '
            .'happen.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function __invoke(
        UpdateBotStatusRequest $request,
        Organization $organization,
        Bot $bot,
        BotService $bots,
    ): BotResource {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name.
        Gate::authorize('update', $bot);

        // CHECK 5, the organization's half. The bot's own two halves are BotService's, because both
        // are decided against the state this write leaves the row in.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new BotResource(
            $bots->transition($organization, $bot, $request->toStatus(), $this->actorId(), $request),
            // `withInstructions: true` WITHOUT A SECOND GATE CALL: `Gate::authorize('update', $bot)`
            // above carries `bots.manage` and has already passed, so this caller provably holds the
            // permission the projection is gated on. A second `Gate::allows()` could only agree —
            // at the cost of a second `organization_users` read — or disagree, which would mean the
            // authorization that let the write happen was wrong. `BotController` states this at
            // length.
            withInstructions: true,
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

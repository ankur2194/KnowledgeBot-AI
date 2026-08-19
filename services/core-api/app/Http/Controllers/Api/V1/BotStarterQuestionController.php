<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBotStarterQuestionRequest;
use App\Http\Requests\UpdateBotStarterQuestionRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Http\Resources\BotStarterQuestionCollectionResource;
use App\Http\Resources\BotStarterQuestionResource;
use App\Models\Bot;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bots\BotStarterQuestionService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One bot's suggested starter questions: list, add, edit or move, remove.
 *
 * These are the first-run affordance — three to six chips on an empty chat surface, from the bot's
 * configuration and NEVER generated client-side from the corpus, which would leak what the corpus
 * contains to anyone who can open the widget (kb-ai-chat-ux). They authorize nobody and bill
 * nothing, which is what makes this the LOWER-STAKES of the two child surfaces in this batch and is
 * why the audit rows it writes carry no question text; `BotDomainController` is the one where a row
 * is a grant.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ────────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE parameters and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` returns
 * `array_values($this->parameters)[$key - 1]` — the PRECEDING bound parameter, not the first one.
 * So each parent must already be a model when its child is resolved, which it is only if the
 * signature lists them in path order: `Organization`, then `Bot`, then `BotStarterQuestion`.
 *
 * `$organization` AND `$bot` ARE UNUSED IN SOME BODIES AND MUST NOT BE REMOVED. Drop `$bot` and
 * `{bot}` stays a raw string, `$parent instanceof UrlRoutable` is false, and the binding falls to
 * the unscoped `else` branch executed inside `SubstituteBindings`, upstream of the Gate call.
 * `#[ScopedBy(OrganizationScope::class)]` would still hold, so what is lost is the BOT predicate:
 * any question of any of this organization's bots would resolve under any other bot's URL. No
 * cross-tenant test can see that; tests/Security/BotChildEndpointAccessTest.php asserts it alone.
 *
 * THE SEGMENT NAME IS THE WIRING. `Model::childRouteBindingRelationshipName()` is
 * `Str::plural(Str::camel($childType))`, so `{starterQuestion}` resolves through
 * `Bot::starterQuestions()`, which exists for exactly this and already orders by `sort_order`.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ───────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group, for all four.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which re-reads `organization_users` per request.
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body. `index` demands
 *    `bots.view` (all four roles; `Permission::BotsView` names the starter questions in as many
 *    words); the three writes demand `bots.manage` through `BotPolicy::manageChildren()`, which
 *    only owner and admin hold. `manageChildren` takes the PARENT BOT even where a child row is in
 *    hand — its own docblock carries the reasoning, and the mechanical half is that the three child
 *    models have no policies of their own, so `Gate::authorize('update', $question)` would silently
 *    DENY: no policy means deny.
 * 4. ENTITY OWNERSHIP — the scoped binding 404s a foreign `{bot}` or `{starterQuestion}` at BINDING
 *    time; the policy resolves membership of the parent bot's organization; and every repository
 *    method takes `organization_id` AND `bot_id` as required positional arguments.
 * 5. ENTITY STATUS — a 409 when the organization is not Active, on `store`, `update` and `destroy`.
 *    `index` has none: reading the suggestions changes nothing. THE BOT'S OWN STATUS IS NOT
 *    CHECKED, for the reason `BotDomainController` states — an archived bot answers nobody, so its
 *    chips are unreachable and tidying them is not a rewrite of history.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`. No §18.3 re-authentication:
 *    nothing here touches a credential or verifies a password.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ─────────────────────────────────────────────────────
 *
 * This file imports no vault, `BotStarterQuestionService` imports no vault, and the resource
 * renders a string, an integer and two timestamps.
 */
final class BotStarterQuestionController extends Controller
{
    /**
     * Every starter question, in the order they render.
     *
     * A WRAPPER OBJECT AND NOT A BARE ARRAY — `{"data": {"starter_questions": [...]}}` — for the
     * mechanical reason `BotStarterQuestionCollectionResource` states.
     *
     * NO 409 and no 422: there is no request body to reject.
     *
     * `$organization` IS UNUSED IN THE BODY AND MUST NOT BE REMOVED — see the class docblock.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotStarterQuestionCollectionResource::class],
        description: 'Every starter question, ordered by `sort_order` ascending, which is the order '
            .'they render in. Positions are always 0..n-1 with no gaps — every write re-sequences '
            .'the whole list — so render straight from `sort_order` and treat a gap as a defect. An '
            .'empty array is the default state of a new bot.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function index(
        Organization $organization,
        Bot $bot,
        BotStarterQuestionService $questions,
    ): BotStarterQuestionCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT. `view` is `bots.view`, which all four roles hold.
        Gate::authorize('view', $bot);

        // No 409 — see the class docblock, check 5.
        return new BotStarterQuestionCollectionResource($questions->list($organization, $bot));
    }

    /**
     * Append one question to the end of the list.
     *
     * THE POSITION IS NOT A REQUEST FIELD. `StoreBotStarterQuestionRequest` records the two
     * reasons; the load-bearing one is that "0..n-1 with no gaps" is an invariant, and an invariant
     * a client can name is an invariant a client can break. Read `sort_order` off this response.
     *
     * The request body is NOT described in the OpenAPI document. Its rules live in
     * `packages/contracts/rules/StoreBotStarterQuestionRequest.json`, dumped from executing
     * `rules()`, and docs/22 finding 19 rules that the FormRequest is the only source of a request
     * rule.
     */
    #[ResponseShape(
        status: 201,
        properties: ['data' => BotStarterQuestionResource::class],
        description: 'The stored question, wrapped in `data`, appended at the end of the list — '
            .'`sort_order` is the server\'s arithmetic and the request carries no field for it. 409 '
            .'when the organization is not active; 422 for a blank question, one over the length '
            .'cap, or a list that is already at the maximum the chat surface renders.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function store(
        StoreBotStarterQuestionRequest $request,
        Organization $organization,
        Bot $bot,
        BotStarterQuestionService $questions,
    ): JsonResponse {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $question = $questions->add(
            $organization,
            $bot,
            $request->toQuestion(),
            $this->actorId(),
            $request,
        );

        return response()->json([
            'data' => (new BotStarterQuestionResource($question))->toArray($request),
        ], 201);
    }

    /**
     * Edit one question's text, move it, or both.
     *
     * ── `sort_order` IN THE BODY IS "MOVE THIS TO POSITION N", NOT A COLUMN WRITE ─────────────
     *
     * `bot_starter_questions_org_bot_position` is UNIQUE per bot and deliberately NOT deferrable,
     * so a direct write collides with whichever row holds the target position — SQLSTATE 23505
     * rendered as a 500 for a request the operator has every right to make. The service reads the
     * list under the bot's row lock, applies the move in memory and re-sequences every row inside
     * one transaction, so a reorder moves `updated_at` on the OTHER questions too. That is a real
     * consequence rather than an implementation detail, and it is why the response is the single
     * edited row while the console re-reads the list.
     *
     * A POSITION PAST THE END IS A 422 rather than a silent append: it means the console is working
     * from a stale read, and appending instead would return 200 for an instruction that was not
     * carried out.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => BotStarterQuestionResource::class],
        description: 'The question after the edit, wrapped in `data`. A move RE-SEQUENCES THE WHOLE '
            .'LIST, so every other question\'s `sort_order` and `updated_at` may have changed too — '
            .'re-read the collection rather than patching one row into a cached list. 409 when the '
            .'organization is not active; 422 for an empty body, a blank question, or a position '
            .'past the end of the list.',
        errors: [401, 403, 404, 409, 422, 429, 500, 503],
    )]
    public function update(
        UpdateBotStarterQuestionRequest $request,
        Organization $organization,
        Bot $bot,
        BotStarterQuestion $starterQuestion,
        BotStarterQuestionService $questions,
    ): BotStarterQuestionResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        return new BotStarterQuestionResource($questions->edit(
            $organization,
            $bot,
            $starterQuestion,
            $request->toData(),
            $this->actorId(),
            $request,
        ));
    }

    /**
     * Remove one question and close the gap it leaves.
     *
     * THE COMPACTION IS NOT TIDINESS. `sort_order` is published as a renderable index and
     * `BotStarterQuestionResource` promises there are no gaps, so a delete that left 0, 1, 3 would
     * make that promise false everywhere at once.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor performed a deletion
     * the trail does not record.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The question is gone and the remaining positions are closed up, so every '
            .'surviving question after it has moved — re-read the collection. 409 when the '
            .'organization is not active. A foreign or unknown `{bot}` or `{starterQuestion}` 404s '
            .'at binding time, before this action runs, and so does a second delete of the same '
            .'question.',
        errors: [401, 403, 404, 409, 429, 500, 503],
    )]
    public function destroy(
        Request $request,
        Organization $organization,
        Bot $bot,
        BotStarterQuestion $starterQuestion,
        BotStarterQuestionService $questions,
    ): AcknowledgementResource {
        // CHECKS 3 AND 4, ON THE PARENT BOT.
        Gate::authorize('manageChildren', $bot);

        // CHECK 5.
        abort_unless(
            $organization->status === OrganizationStatus::Active,
            409,
            OrganizationStatus::SUSPENDED_REFUSAL,
        );

        $questions->remove($organization, $bot, $starterQuestion, $this->actorId(), $request);

        // An acknowledgement and not the deleted resource — never a 204, because an empty
        // #[ResponseShape] would publish `"properties": []`, which is not a JSON Schema object (D8).
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

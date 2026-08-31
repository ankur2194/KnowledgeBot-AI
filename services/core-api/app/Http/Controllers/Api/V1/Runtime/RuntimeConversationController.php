<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRuntimeConversationRequest;
use App\Http\Resources\RuntimeConversationResource;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Chat\NewConversation;
use App\Services\Sdk\WidgetSession;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;

/**
 * `POST /rt/v1/conversations` — open a conversation.
 *
 * ═══ EVERY OWNERSHIP AND POLICY FIELD IS RESOLVED HERE, NOT POSTED ═════════════════════════
 *
 * The organization and the bot come from the session; the channel comes from the CREDENTIAL; the
 * consent snapshot and the retention window come from the bot's own row. The only thing the body may
 * say is the locale, and it selects nothing.
 *
 * THE CHANNEL IS THE ONE WORTH NAMING. `playground` is the channel whose actor type unlocks
 * `retrieval.trace` on the relay, so a body field naming it would let a widget on a stranger's
 * marketing site ask for the pipeline's internal topology. It is derived from how the caller
 * authenticated and from nothing else — specifically from the RESOLVED SESSION'S actor type, which
 * `WidgetSessionService::resolve()` derives in turn from the stored `kind` of the credential. A
 * caller cannot reach `playground` without holding a credential minted by
 * `POST /api/v1/organizations/{organization}/bots/{bot}/playground-session`, which is an admin route
 * behind `bots.manage`.
 *
 * ═══ `embedded` VERSUS `hosted` IS DECIDED FROM THE EMBEDDER ORIGIN ════════════════════════
 *
 * Both use the SAME credential — one auth path keeps the 404 rule, the rate-limit keys and the abuse
 * hooks identical across the two public surfaces — so the distinction is made from the origin the
 * session was MINTED against, which is the customer's page for the widget and our own hosted-chat
 * host for hosted chat. It is analytics only: neither channel is in
 * `ConversationChannel::authenticatedOnly()`, so neither grants anything the other does not.
 *
 * `mobile` is not reachable yet and is not faked: nothing in this application mints a personal
 * access token, so the React Native client has no credential for this surface. Reported rather than
 * approximated — labelling those turns `hosted` would put a number in the channel breakdown that is
 * simply untrue.
 */
final class RuntimeConversationController extends Controller
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ChatConfigurationRepositoryInterface $configuration,
    ) {}

    #[ResponseShape(
        status: 201,
        properties: ['data' => RuntimeConversationResource::class],
        description: 'The conversation that was opened.',
        errors: [401, 404, 422, 429],
    )]
    public function __invoke(StoreRuntimeConversationRequest $request, WidgetSession $session): JsonResponse
    {
        $bot = $this->configuration->botForOrg($session->organizationId, $session->botId);

        if ($bot === null) {
            abort(404);
        }

        $locale = $request->validated('locale');
        $locale = is_string($locale) ? $locale : null;
        $consentRequired = (bool) $bot->collect_end_user_data;
        $consentText = is_string($bot->consent_text) ? $bot->consent_text : null;
        // RESOLVED INTO A TIMESTAMP AT CREATION by the repository, so a later edit to the bot cannot
        // retroactively shorten or extend a conversation somebody already had.
        $retentionDays = $bot->retention_days === null ? null : (int) $bot->retention_days;

        // ── THE PARTICIPANT IS EXACTLY ONE OF TWO, AND THE CHANNEL DECIDES WHICH ────────────────
        //
        // `conversations_participant_exclusive` refuses a row carrying both a `user_id` and an
        // `anonymous_session_id`, and `conversations_authenticated_channel` refuses a `playground`
        // row carrying NEITHER — the two constraints between them make this branch the only shape
        // the table will hold. `NewConversation`'s two named constructors are the PHP half of the
        // same rule, which is why this is a choice between constructors rather than two nullable
        // arguments a caller fills in and hopes about.
        $input = $session->userId !== null
            ? NewConversation::forUser(
                ConversationChannel::Playground,
                $session->userId,
                $locale,
                $consentRequired,
                $consentText,
                $retentionDays,
            )
            : NewConversation::forAnonymousSession(
                $this->channelFor($session),
                // THE SESSION'S DERIVED ID, which is a one-way function of the bearer: derivable
                // from the token, useless without it. The TOKEN never reaches a column.
                $session->sessionId,
                $locale,
                $consentRequired,
                $consentText,
                $retentionDays,
            );

        $conversation = $this->conversations->create(
            $session->organizationId,
            $session->botId,
            $input,
        );

        return response()->json([
            'data' => (new RuntimeConversationResource($conversation))->toArray($request),
        ], 201);
    }

    /**
     * Which PUBLIC surface this session was minted for.
     *
     * READ FROM THE ORIGIN THE SESSION WAS BOUND TO AT MINT, never from a request header: the
     * frame's own `Origin` is our widget host on every call and would label every conversation
     * `hosted`.
     *
     * IT IS NOT REACHED FOR A PLAYGROUND SESSION, and that is why it still returns a two-value
     * choice rather than growing a third arm. A playground record carries
     * `WidgetSessionService::PLAYGROUND_ORIGIN`, which is deliberately not a valid origin and would
     * fall through to `Embedded` here — the wrong label AND a row the authenticated-channel
     * constraint would then accept, because `embedded` permits an anonymous participant. The branch
     * above keys on the ACTOR, which is the fact that decides both halves at once.
     */
    private function channelFor(WidgetSession $session): ConversationChannel
    {
        $hostedOrigins = config('kb.widget.hosted_origins');
        $hosted = is_array($hostedOrigins) ? array_values(array_filter($hostedOrigins, 'is_string')) : [];

        return in_array($session->embedderOrigin, $hosted, true)
            ? ConversationChannel::Hosted
            : ConversationChannel::Embedded;
    }
}

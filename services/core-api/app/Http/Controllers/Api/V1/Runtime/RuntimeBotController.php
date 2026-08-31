<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Http\Controllers\Controller;
use App\Http\Resources\RuntimeBotResource;
use App\Models\BotStarterQuestion;
use App\Repositories\Contracts\BotStarterQuestionRepositoryInterface;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Services\Sdk\WidgetSession;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\JsonResponse;

/**
 * `GET /rt/v1/bot` — the public configuration of the bot THIS SESSION already names.
 *
 * ═══ THERE IS NO IDENTIFIER IN THE PATH, AND THAT IS THE DESIGN ════════════════════════════
 *
 * The bot comes from the resolved chat session. A `{bot}` segment would be an identifier a caller
 * chooses, which means a lookup that can miss — and a lookup that can miss is an enumeration
 * surface. With no parameter there is nothing to enumerate: the route answers about one bot or the
 * request had no valid session at all.
 *
 * It also means the frame does not have to carry a bot id it could get wrong. The widget already has
 * one from its own boot configuration; this route deliberately does not consult it.
 *
 * ═══ IT DUPLICATES `POST /sdk/v1/bootstrap` AND BOTH ARE NEEDED ════════════════════════════
 *
 * The SDK one is unauthenticated and origin-validated, and the LOADER calls it before minting —
 * because the launcher has to be drawn before there is a session. This one is
 * session-authenticated, and the FRAME calls it after the handshake. Same projection, two
 * credentials, and neither can serve the other's caller: the loader has no session, and the frame's
 * `Origin` is our own iframe's and proves nothing about who is embedding us.
 */
final class RuntimeBotController extends Controller
{
    public function __construct(
        private readonly ChatConfigurationRepositoryInterface $configuration,
        private readonly BotStarterQuestionRepositoryInterface $starterQuestions,
    ) {}

    #[ResponseShape(
        status: 200,
        properties: ['data' => RuntimeBotResource::class],
        description: 'The bot the calling session is bound to, as an anonymous visitor sees it.',
        errors: [401, 404, 429],
    )]
    public function __invoke(WidgetSession $session): JsonResponse
    {
        // ALREADY RE-READ ONCE THIS REQUEST. `ResolveChatSession` -> `WidgetSessionService::resolve()`
        // checked the bot's live status and domain allow-list before routing, so reaching this line
        // means the bot is published, public and embedded from a listed origin. This read is for the
        // COLUMNS, and it is scoped to the session's organization rather than trusting the row the
        // guard happened to hold.
        $bot = $this->configuration->botForOrg($session->organizationId, $session->botId);

        if ($bot === null) {
            abort(404);
        }

        $questions = array_map(
            static fn (BotStarterQuestion $q): array => ['question' => (string) $q->question],
            $this->starterQuestions->forBot($session->organizationId, $session->botId),
        );

        return response()->json([
            'data' => (new RuntimeBotResource($bot, $questions))->toArray(request()),
        ]);
    }
}

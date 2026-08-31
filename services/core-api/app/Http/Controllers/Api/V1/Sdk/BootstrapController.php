<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sdk;

use App\Http\Controllers\Controller;
use App\Http\Requests\MintChatSessionRequest;
use App\Http\Resources\RuntimeBotResource;
use App\Models\BotStarterQuestion;
use App\Repositories\Contracts\BotStarterQuestionRepositoryInterface;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Services\Sdk\BotDomainMatcher;
use App\Support\Contracts\ResponseShape;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * `POST /sdk/v1/bootstrap` — origin-validated public configuration, before any session exists.
 *
 * ═══ WHY IT IS SEPARATE FROM THE MINT, AND WHY IT IS A POST ════════════════════════════════
 *
 * The loader has to draw a launcher on the customer's page BEFORE the iframe boots, and it needs the
 * bot's name, palette and welcome text to do it. It cannot use `GET /rt/v1/bot`, which requires the
 * session that does not exist yet; and it must not simply mint one, because a session is a
 * CREDENTIAL and drawing a button is not a reason to issue one — a page that loads the loader and
 * never opens the widget would burn a session per view.
 *
 * IT IS A POST BECAUSE THE BOT ID IS IN THE BODY, and the bot id is in the body rather than the path
 * for the same reason it is in the mint's body: a public identifier in a URL lands in Traefik access
 * logs, in `Referer` on every navigation away from the page, and in browser history. It is not a
 * credential, so that is untidiness rather than a leak — but the two SDK routes then differ in shape
 * for no reason, and a caller that got them the wrong way round would find out from a 405 whose
 * message names the route and its verb list.
 *
 * NOTHING IS WRITTEN, so the POST needs no idempotency key: it is a read that happens to carry its
 * argument in a body.
 *
 * ═══ IT SHARES `MintChatSessionRequest` DELIBERATELY ═══════════════════════════════════════
 *
 * The same two fields, the same grammar, the same `user_token` accepted-and-ignored. A second
 * FormRequest with the same rules is a second copy to keep in step, and the shipped loader posts one
 * body shape to both routes.
 *
 * ═══ SAME 404, SAME BODY, SAME LIMITER ═════════════════════════════════════════════════════
 *
 * Unknown bot id, a bot that is not live, an origin not on its list — one response. This endpoint is
 * strictly MORE exposed than the mint (it is the natural thing to probe, since it returns
 * configuration rather than a credential), so it carries the identical rules rather than looser ones.
 */
final class BootstrapController extends Controller
{
    public function __construct(
        private readonly ChatConfigurationRepositoryInterface $configuration,
        private readonly BotStarterQuestionRepositoryInterface $starterQuestions,
        private readonly BotDomainMatcher $domains,
        private readonly TenantContext $tenancy,
    ) {}

    #[ResponseShape(
        status: 200,
        properties: ['data' => RuntimeBotResource::class],
        description: 'The public configuration of one bot, for a loader on an allow-listed origin.',
        errors: [404, 422, 429],
    )]
    public function __invoke(MintChatSessionRequest $request): JsonResponse
    {
        // THE ONE ORG-AGNOSTIC LOOKUP ON THIS SURFACE, and the repository carries the
        // `tenancy-exempt` marker and the argument: there is no organization to scope by until this
        // row is read, and the identifier authorizes nothing on its own.
        $bot = $this->configuration->botByPublicId((string) $request->validated('bot_id'));

        if ($bot === null
            || ! $bot->status->isRetrievable()
            || ! $bot->access_mode->allowsAnonymous()
            || ! $this->domains->matches($bot, $request->headers->get('Origin'))) {
            abort(404);
        }

        // FROM HERE ON EVERY READ IS ORDINARY TENANT-SCOPED CODE. The organization came OUT of the
        // row rather than from request input, and binding it is what lets the starter-question read
        // below go through the same global scope every other read in this application does.
        $questions = $this->tenancy->runFor(
            (string) $bot->organization_id,
            fn (): array => array_map(
                static fn (BotStarterQuestion $q): array => ['question' => (string) $q->question],
                $this->starterQuestions->forBot((string) $bot->organization_id, (string) $bot->id),
            ),
        );

        return response()
            ->json(['data' => (new RuntimeBotResource($bot, $questions))->toArray($request)])
            // PRIVATE AND SHORT. The body is per-bot AND per-origin — a shared cache that keyed only
            // on the URL would serve one customer's bot configuration to another customer's page,
            // because the bot id is in the BODY and no cache varies on that.
            ->header('Cache-Control', 'private, no-store');
    }
}

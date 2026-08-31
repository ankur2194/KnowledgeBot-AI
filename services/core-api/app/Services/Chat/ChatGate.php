<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ConversationStatus;
use App\Exceptions\KbException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Quotas\ChatRateLimitScopes;
use App\Services\Quotas\QuotaGate;
use App\Services\Sdk\WidgetSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * THE SIX CHECKS, PLUS THE QUOTA, FOR ONE CHAT TURN — and every one of them runs BEFORE THE FIRST
 * BYTE.
 *
 * ═══ THE ORDER IS THE PROPERTY, AND IT IS THE REASON THIS CLASS EXISTS ══════════════════════
 *
 * Once `200 OK` and the SSE headers are on the wire the status line is SPENT: a later refusal can
 * only be an SSE `error` event on a successful response, the provider call has already been made,
 * and the tokens the quota was protecting have already been bought. A quota checked after the first
 * byte is a quota that REPORTS rather than one that REFUSES — `QuotaGate`'s own docblock says so at
 * length, and it is why `assertChatTurnPermitted()` was written and left uncalled until this class.
 *
 * So `authorize()` returns a fully resolved `ChatContext` or it throws, and the controller does not
 * open the stream until it has one.
 *
 * ═══ THE SIX CHECKS OF §18.4, NAMED, IN ORDER ══════════════════════════════════════════════
 *
 *   1. AUTHENTICATED IDENTITY — the chat-session bearer, resolved by `ResolveChatSession` before
 *      routing. This class receives an already-resolved `WidgetSession` and never a raw token: a
 *      gate that could be handed a token is a gate that could be handed an unverified one.
 *
 *   2. ORGANIZATION MEMBERSHIP — for a WIDGET session the session IS the membership on this
 *      surface: there is no user and no `organization_users` row; the scope came out of a
 *      server-side Valkey record written at mint time, and `WidgetSessionService::resolve()` has
 *      already re-read the bot's live status and domain allow-list, which is this surface's
 *      equivalent of re-reading the membership row. For a PLAYGROUND session it is the literal
 *      thing: `resolve()` re-reads `organization_users` for the record's organization and requires
 *      an ACTIVE membership granting `bots.manage` before this class is reached. Both are done, and
 *      neither is repeated here — this class receives an already-resolved session, and a gate that
 *      re-derived the membership would be a second opinion that can drift from the first.
 *
 *   3. ROLE / PERMISSION — the token's abilities. `chat:send` for a submission, `chat:read` for the
 *      transcript, `feedback:submit` for a verdict. They CAP what the token may attempt and are not
 *      the whole authorization: checks 4 and 5 below are.
 *
 *   4. ENTITY OWNERSHIP — the conversation belongs to this organization AND to this session. The
 *      second half is not optional and is the one an ordinary user can breach: an organization-only
 *      check lets any visitor read every other visitor's transcript in the same tenant by changing a
 *      ULID.
 *
 *   5. ENTITY STATUS — the bot is reachable from the credential's own surface (published AND public
 *      for a widget session; `testing` or `published` for a playground one — `BotStatus` carries the
 *      split and why the two lists are separate methods), and the conversation still accepts
 *      messages. An `ended` or `expired` conversation is not a 404: it exists and the visitor owns
 *      it, so the honest answer is a refusal that says so.
 *
 *   6. RATE LIMIT AND QUOTA — `QuotaGate::assertChatTurnPermitted()`, with the three platform scopes
 *      of §18.5 riding the same `EVALSHA` as the bot's own windows. Two error classes come out of
 *      it and they are not interchangeable: the bot's own ceiling is `tenant_quota` (403,
 *      non-retryable, never fallback-eligible) and the platform's is `rate_limit` (429 with a
 *      `Retry-After`).
 *
 * ═══ EVERY DENIAL ON THIS SURFACE IS A 404 WITH A BYTE-IDENTICAL BODY ══════════════════════
 *
 * Checks 3 and 4 `abort(404)`. A 403 on a foreign conversation id confirms the row exists and turns
 * the endpoint into an enumeration oracle over every tenant's conversations. The `error_class` stays
 * `authorization` either way and nothing branches on the status (`kb-error-taxonomy` footnote 1);
 * `bootstrap/app.php`'s render closure produces the identical body for a denied record, a foreign
 * one, one that never existed, and a URI with no route at all.
 *
 * Check 5 and check 6 are NOT 404s, and the difference is deliberate: they are facts about a
 * resource the caller has already proved they own, so hiding them would leave a visitor with a dead
 * composer and nothing to read.
 */
final readonly class ChatGate
{
    public function __construct(
        private ChatConfigurationRepositoryInterface $configuration,
        private ConversationRepositoryInterface $conversations,
        private QuotaGate $quota,
    ) {}

    /**
     * Authorize one message submission and resolve everything the turn needs.
     *
     * @throws KbException `tenant_quota` (403), `rate_limit` (429), `validation` (422)
     */
    public function authorizeSubmission(
        WidgetSession $session,
        string $conversationId,
        string $clientMessageId,
        string $content,
        Request $request,
        ?CarbonImmutable $at = null,
    ): ChatTurn {
        // CHECK 3 — the token's ability cap.
        if (! $session->can('chat:send')) {
            abort(404);
        }

        [$organization, $bot] = $this->organizationAndBot($session);

        // CHECK 4 — entity ownership, in BOTH dimensions.
        //
        // `anonymousSessionId()` AND NOT `sessionId`, and the difference is the whole of the
        // playground's ownership check. `findForParticipant()` prefers the anonymous predicate
        // whenever one is supplied, so passing the session digest unconditionally would send a
        // PLAYGROUND session — whose conversation is owned by a `user_id`, because
        // `conversations_participant_exclusive` refuses a row carrying both — down the wrong branch
        // and 404 every turn an administrator ever took. The accessor returns exactly one of the
        // two, which is the same exclusivity the constraint enforces one layer down.
        $conversation = $this->conversations->findForParticipant(
            $session->organizationId,
            $conversationId,
            $session->anonymousSessionId(),
            $session->userId,
        );

        if ($conversation === null || (string) $conversation->bot_id !== $session->botId) {
            abort(404);
        }

        // CHECK 5 — entity status. Not a 404: the caller owns this conversation and is entitled to
        // be told it is over.
        if (! $conversation->status->acceptsMessages()) {
            throw KbException::validation(
                'This conversation has ended. Start a new one to keep chatting.',
            );
        }

        // CHECK 6 — rate limit, then quota, BEFORE the first byte and before any credential is
        // decrypted. All four scopes ride one EVALSHA; the bot's own ceiling refuses as 403 and the
        // three platform scopes as 429.
        $this->quota->assertChatTurnPermitted(
            $organization,
            $bot,
            // ZERO IS THE CORRECT PRE-CHARGE AND NOT A PLACEHOLDER. The real token count is not
            // knowable before the turn runs, so this admits against usage ALREADY RECORDED — which
            // means "refuse an organization that is already over", exactly the behaviour a first turn
            // on a fresh period should get. The ledger row is written afterwards by `UsageRecorder`
            // from the `provider_calls` row, which is the only number anybody is billed on.
            0,
            $at,
            ChatRateLimitScopes::from($session->embedderOrigin, $session->sessionId, $request->ip()),
        );

        return new ChatTurn($organization, $bot, $conversation, $session, $clientMessageId, $content);
    }

    /**
     * Authorize a read of one conversation's transcript, or of one message's citations.
     *
     * @param  string  $ability  `chat:read` or `feedback:submit` — the caller names it rather than
     *                           this method inferring one from the verb, because a POST that only
     *                           submits an opinion is not the same authority as a POST that spends
     *                           tokens.
     */
    public function authorizeConversationAccess(
        WidgetSession $session,
        string $conversationId,
        string $ability = 'chat:read',
    ): Conversation {
        if (! $session->can($ability)) {
            abort(404);
        }

        // ONE OF THE TWO PARTICIPANT COLUMNS AND NEVER BOTH — see `authorizeSubmission()` above.
        $conversation = $this->conversations->findForParticipant(
            $session->organizationId,
            $conversationId,
            $session->anonymousSessionId(),
            $session->userId,
        );

        if ($conversation === null || (string) $conversation->bot_id !== $session->botId) {
            abort(404);
        }

        return $conversation;
    }

    /**
     * The organization and the bot this session names, re-read from PostgreSQL on every request.
     *
     * NEITHER IS CACHED ON THE SESSION RECORD, and that is the same rule `laravel-sanctum-auth`
     * non-negotiable 1 states for a Sanctum token: the record was written in the past and is not
     * evidence of a CURRENT state. A bot archived a minute ago, an organization suspended this
     * morning, a quota limit an owner just lowered — all of them have to bite on the next request,
     * not at the next TTL boundary.
     *
     * @return array{0: Organization, 1: Bot}
     */
    private function organizationAndBot(WidgetSession $session): array
    {
        $organization = $this->configuration->organization($session->organizationId);
        $bot = $this->configuration->botForOrg($session->organizationId, $session->botId);

        if ($organization === null || $bot === null) {
            abort(404);
        }

        return [$organization, $bot];
    }

    /**
     * Whether the conversation is one this class would still let a message into.
     *
     * Exposed so a caller that already holds the row does not have to re-derive the rule from
     * `ConversationStatus`, which is what puts a second copy of it in a controller.
     */
    public function acceptsMessages(Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Active;
    }
}

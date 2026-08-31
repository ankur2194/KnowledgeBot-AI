<?php

declare(strict_types=1);

namespace App\Services\Sdk;

use App\Enums\ActorType;
use InvalidArgumentException;

/**
 * A resolved chat session — who the bearer proves you are, on the public runtime surface.
 *
 * ═══ IT CARRIES SCOPE AND NOTHING ELSE ═════════════════════════════════════════════════════
 *
 * `organizationId` and `botId` are the tenant scope for every read and write the request goes on to
 * make, and they came out of a Valkey record that was written server-side at mint time — never from
 * a header, a body field or a query parameter (`kb-tenancy-isolation` NN6). The token the caller
 * presented selects the record; it does not describe it.
 *
 * ═══ `abilities` IS A CAP, NOT AN AUTHORIZATION ════════════════════════════════════════════
 *
 * It bounds what this TOKEN may attempt. Whether the action is permitted is a separate decision, and
 * on this surface it is the AND of the bot's status, its access mode, the live origin allow-list and
 * the record's own scope. A widget token may never carry an admin ability: it is minted with no
 * human authentication at all — anyone who can put the loader on an allow-listed page gets one — so
 * an ability like `sources.delete` on it would turn an XSS on a customer's marketing site into a
 * deleted knowledge base (`laravel-sanctum-auth`).
 *
 * ═══ TWO KINDS OF SESSION RESOLVE INTO THIS ONE OBJECT, AND THE KIND IS THE DISCRIMINATOR ══
 *
 * This paragraph used to read *"`actorType` IS ALWAYS `anonymous_session` FOR NOW"* and to say the
 * `user` path *"is not built, so the value is the fail-closed one"*. It is built now, and only for
 * the D5 admin playground: `WidgetSessionService::mintPlayground()` writes a record whose stored
 * `kind` is `playground`, and `resolve()` DERIVES `actorType`, `diagnostics` and the participant
 * predicate from that one stored field rather than from three independent ones. There is no record
 * shape in which `kind` says `widget` and `diagnostics` says `true`, because there is no second
 * field to disagree with the first.
 *
 * `actorType` is what `X-KB-Actor-Type` carries and what `ClientEvents::allows()` reads, so it
 * decides — AND-ed with `diagnostics` — whether `retrieval.trace` may be forwarded. An
 * `anonymous_session` can never satisfy that branch whatever else is true, which is why the widget
 * is unaffected by any of this. The hosted-chat "signed-in visitor" path is STILL not built: a
 * visitor who happens to hold an admin session mints an ordinary widget session and is
 * `anonymous_session`, which remains the fail-closed value rather than an optimistic guess.
 *
 * ═══ THE PARTICIPANT IS EXACTLY ONE OF TWO, AND THIS OBJECT MAKES THE WRONG PAIR UNBUILDABLE ═
 *
 * `conversations_participant_exclusive` refuses a row carrying both a `user_id` and an
 * `anonymous_session_id`, and refuses one carrying neither. The constructor below enforces the same
 * exclusivity one layer earlier — a `user` actor has a `userId`, a non-`user` actor does not — so
 * `anonymousSessionId()` can be a total function rather than a nullable field somebody has to
 * remember to check.
 */
final readonly class WidgetSession
{
    /**
     * @param  string  $sessionId  the truncated digest that forms the Valkey key's last segment. It
     *                             is DERIVABLE from the bearer and useless without it, which is what
     *                             makes it safe to use as a rate-limit subject and to log.
     * @param  string  $embedderOrigin  the origin proved AT MINT from the loader's own POST. Every
     *                                  later request re-validates THIS stored value against the
     *                                  bot's live allow-list — never the refreshing request's own
     *                                  `Origin`, which is our widget origin and would deny every
     *                                  message ever sent. A PLAYGROUND session has no embedder at
     *                                  all and carries `WidgetSessionService::PLAYGROUND_ORIGIN`, a
     *                                  value that is not a valid origin and therefore cannot match
     *                                  any `bot_domains` row — so if the playground branch in
     *                                  `resolve()` were ever removed, such a session would be
     *                                  REFUSED rather than admitted.
     * @param  list<string>  $abilities
     * @param  bool  $diagnostics  whether the caller established that this actor holds the
     *                             diagnostics permission. AND-ed with `$actorType` inside
     *                             `ClientEvents::allows()`, so `true` on an anonymous session grants
     *                             nothing — and no path can produce that pair anyway.
     */
    public function __construct(
        public string $organizationId,
        public string $botId,
        public string $sessionId,
        public string $embedderOrigin,
        public array $abilities,
        public ActorType $actorType = ActorType::AnonymousSession,
        public ?string $userId = null,
        public bool $diagnostics = false,
    ) {
        // A PROGRAMMING ERROR, LOUD. Both halves matter and neither is reachable from request input:
        // a `user` actor with no id would write a `playground` conversation the CHECK constraint
        // refuses, and a non-`user` actor carrying an id would match a transcript by `user_id` on a
        // surface whose participant is a session digest.
        if (($actorType === ActorType::User) !== ($userId !== null)) {
            throw new InvalidArgumentException(
                'A chat session is either a `user` actor WITH an actor id or a non-user actor with '
                .'NONE. `conversations_participant_exclusive` enforces the same exclusivity on the '
                .'row this session goes on to write.',
            );
        }
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    /**
     * The participant predicate for `conversations`, as the exclusive pair rather than as two
     * fields a caller has to reconcile.
     *
     * `sessionId` IS STILL THE RATE-LIMIT SUBJECT FOR BOTH KINDS — see `ChatRateLimitScopes`. What
     * changes between them is only which COLUMN identifies the participant: a widget visitor is a
     * session digest and has no account, an operator in the playground is a `user_id` and their
     * turns must not be readable by any other session for the same bot.
     */
    public function anonymousSessionId(): ?string
    {
        return $this->actorType === ActorType::User ? null : $this->sessionId;
    }
}

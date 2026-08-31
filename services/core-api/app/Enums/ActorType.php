<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `X-KB-Actor-Type` — who initiated the work, as the internal seam spells it.
 *
 * The four values are `kb-internal-api-contracts`' header table and
 * `ChatExecuteRequest.actor_type`'s `Literal` on the data plane, and they are a CLOSED set on both
 * sides: FastAPI's model is `extra="forbid"` / `strict`, so a fifth spelling is a 422 rather than a
 * degraded default.
 *
 * IT IS NOT DERIVED FROM WHETHER AN ACTOR ID IS PRESENT. `InternalAiClient`'s three existing call
 * sites write `$actorId === null ? 'system' : 'user'` inline, which is correct for THEM — an
 * ingestion submission is either an administrator pressing a button or the scheduler — and is wrong
 * for chat, where the common case is `anonymous_session`: a visitor on a customer's marketing site
 * has no actor id AND is not the platform acting on its own behalf. Reusing the inline ternary here
 * would label every widget conversation `system`, which is the value that decides what the
 * diagnostics contracts may return.
 *
 * `ClientEvents::allows()` reads it, so the value is a security input rather than a log label: it is
 * resolved from the AUTHENTICATED credential and never from a request header, a query parameter or a
 * body field.
 */
enum ActorType: string
{
    /** A signed-in human: the admin console, the playground, an authenticated mobile session. */
    case User = 'user';

    /** A visitor holding an origin-bound chat-session token. No account, no actor id. */
    case AnonymousSession = 'anonymous_session';

    /** `routes/console.php` — a recrawl dispatcher, a sweep, a rollup. */
    case Scheduler = 'scheduler';

    /** The platform acting on its own behalf: a queued job with no human origin. */
    case System = 'system';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

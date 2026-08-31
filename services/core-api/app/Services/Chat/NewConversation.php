<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ConversationChannel;

/**
 * A conversation about to be opened, fully specified before anything is written.
 *
 * ═══ THE PARTICIPANT IS EXACTLY ONE OF TWO, AND THE OBJECT MAKES THE WRONG SHAPE HARD ══════
 *
 * `conversations_participant_exclusive` refuses a row with both a `user_id` and an
 * `anonymous_session_id`, and refuses one with neither. "Both" is REACHABLE — a signed-in visitor on
 * hosted chat has a session AND an account — and it is the state that double-counts unique sessions
 * in every analytic. The two named constructors below are the only ways to build this, so the
 * caller picks a participant rather than filling in two nullable fields and hoping.
 *
 * ═══ THE CHANNEL IS RESOLVED FROM THE CREDENTIAL, NOT FROM THE REQUEST ═════════════════════
 *
 * A body field naming the channel would let a widget claim `playground` — which is the actor type
 * that unlocks `retrieval.trace` on the relay — so it is derived server-side from how the caller
 * authenticated. `conversations_authenticated_channel` is the database's half of the same rule: the
 * channels in `ConversationChannel::authenticatedOnly()` require a `user_id`.
 *
 * ═══ CONSENT IS A SNAPSHOT AND NOT A FLAG ═════════════════════════════════════════════════
 *
 * `conversations_consent_snapshot_present` requires the TEXT that was shown whenever consent was
 * required. Recording only a boolean makes the record mean "they agreed to whatever the bot says
 * today", which is worthless the first time the wording changes — and the wording is editable from
 * the admin console.
 */
final readonly class NewConversation
{
    /**
     * @param  string|null  $locale  BCP-47, from the client. NULL means "we were not told", which is
     *                               a real state and different from `en`; the column's CHECK bounds
     *                               the grammar so an unbounded string cannot reach it.
     */
    private function __construct(
        public ConversationChannel $channel,
        public ?string $userId,
        public ?string $anonymousSessionId,
        public ?string $locale,
        public bool $consentRequired,
        public ?string $consentText,
        public ?int $retentionDays,
    ) {}

    public static function forAnonymousSession(
        ConversationChannel $channel,
        string $anonymousSessionId,
        ?string $locale,
        bool $consentRequired,
        ?string $consentText,
        ?int $retentionDays,
    ): self {
        return new self(
            $channel,
            null,
            $anonymousSessionId,
            $locale,
            // A bot that collects end-user data with no consent text configured cannot claim consent
            // was required: the constraint refuses the row, and refusing here would refuse the
            // conversation. So the flag follows the TEXT rather than the setting, and the operator
            // sees the gap on the admin screen rather than in a 500.
            $consentRequired && $consentText !== null,
            $consentText,
            $retentionDays,
        );
    }

    public static function forUser(
        ConversationChannel $channel,
        string $userId,
        ?string $locale,
        bool $consentRequired,
        ?string $consentText,
        ?int $retentionDays,
    ): self {
        return new self(
            $channel,
            $userId,
            null,
            $locale,
            $consentRequired && $consentText !== null,
            $consentText,
            $retentionDays,
        );
    }
}

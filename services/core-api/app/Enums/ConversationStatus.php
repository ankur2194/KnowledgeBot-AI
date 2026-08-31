<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a conversation is still accepting turns, and if not, why it stopped (docs/04 §8.22).
 *
 * ── THREE VALUES, AND THE TWO TERMINAL ONES ARE SEPARATE BECAUSE THEIR CAUSES ARE ───────────
 *
 * `Ended` is somebody's decision — the visitor closed the widget, the admin closed the thread.
 * `Expired` is the clock: `conversations.retention_expires_at` passed, or the session went idle
 * past the window. Collapsing them into one `closed` value would make "how many people finish a
 * conversation" unanswerable from the console, and that number is the difference between a bot
 * that helps and a bot that is abandoned.
 *
 * ── THE TRANSITION TABLE IS THE POINT, AND IT IS DELIBERATELY A SINK ────────────────────────
 *
 * BOTH TERMINAL STATES ARE FINAL. There is no path back to `Active`, and that is not tidiness: a
 * conversation reopened after expiry would be a transcript whose `retention_expires_at` has already
 * been honoured by the retention sweeper — messages may already be gone — so the row would claim a
 * continuous thread over a hole. A visitor who comes back gets a NEW conversation, which is also
 * what the analytics means by "unique sessions".
 *
 * `canTransitionTo()` is what a service asks; nothing compares these values with `===` at a call
 * site. That is the same rule `SourceState` states for its own fifteen, and for the same reason:
 * a transition written inline is a transition nobody can review against the table.
 */
enum ConversationStatus: string
{
    /** Open. New turns are accepted. The only state a message may be appended in. */
    case Active = 'active';

    /** Closed on purpose, by the end user or by an administrator. */
    case Ended = 'ended';

    /** Closed by the clock: the retention window or the idle window elapsed. */
    case Expired = 'expired';

    /**
     * Whether a new turn may be appended to a conversation in this state.
     *
     * ONE VALUE, and every widening of this set is a message appended to a transcript whose
     * retention deadline has already been acted on.
     */
    public function acceptsMessages(): bool
    {
        return $this === self::Active;
    }

    /** Whether this state is a sink. */
    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }

    /**
     * The legal moves, in one table.
     *
     * @return array<string, list<self>>
     */
    public static function transitionTable(): array
    {
        return [
            self::Active->value => [self::Ended, self::Expired],
            // Sinks. See the class docblock: reopening is a transcript with a hole in it.
            self::Ended->value => [],
            self::Expired->value => [],
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, self::transitionTable()[$this->value], strict: true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

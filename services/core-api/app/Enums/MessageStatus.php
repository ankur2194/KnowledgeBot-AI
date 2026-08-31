<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far one message got (docs/11 §16.6).
 *
 * ── THE ROW EXISTS BEFORE THE ANSWER DOES, AND THAT IS WHY THIS COLUMN EXISTS ───────────────
 *
 * An assistant message is inserted as `Pending` at the moment the turn is accepted, moves to
 * `Streaming` on the first token, and settles into exactly one of three terminal values. Writing
 * the row only at the END would look simpler and would lose the two facts the diagnostics panel
 * (D5) and the analytics (§8.23) are built on: first-token latency has nothing to attach to, and a
 * turn the client abandoned mid-stream leaves no trace at all — which is the case
 * `user_cancellation` exists for in the error taxonomy.
 *
 * ── `Cancelled` IS NOT `Failed`, AND CONFLATING THEM CORRUPTS THE ERROR RATE ────────────────
 *
 * `user_cancellation` is a non-retryable class with no fault attached; `provider_temporary` is a
 * brownout. A dashboard that reports "provider error rate" and counts the visitor closing their
 * laptop lid in it is a dashboard that pages somebody at 3am for a closed lid. They are separate
 * values because they are separate events, and the SSE relay reaches the `finally` that records the
 * difference only because `ignore_user_abort(true)` is set.
 *
 * ── THE TRANSITION TABLE, AND THE ONE MOVE IT DELIBERATELY REFUSES ─────────────────────────
 *
 * `Pending -> Complete` is legal, because a non-streaming provider call returns whole. What is NOT
 * legal is any move OUT of a terminal state: a completed message that is later edited is not an
 * edit, it is a RETRY, and a retry is a new row carrying `parent_message_id`. That is stated in the
 * data model (§16.6, "Parent message ID when retrying") and it is what makes a transcript an
 * audit record rather than a mutable document.
 */
enum MessageStatus: string
{
    /** Accepted, no provider token yet. Every assistant message starts here. */
    case Pending = 'pending';

    /** The first token arrived. Only an assistant message is ever in this state. */
    case Streaming = 'streaming';

    /** Finished. A user message is inserted directly in this state. */
    case Complete = 'complete';

    /** The turn ended in an error. `provider_calls.error_class` carries which one. */
    case Failed = 'failed';

    /** The client went away, or the caller aborted. NOT an error — see the class docblock. */
    case Cancelled = 'cancelled';

    /** Whether this state is a sink. */
    public function isTerminal(): bool
    {
        return $this === self::Complete || $this === self::Failed || $this === self::Cancelled;
    }

    /**
     * Whether the content of a message in this state is the whole content.
     *
     * `Streaming` is deliberately false: a partial answer rendered as if it were finished is the
     * one presentation failure a reader cannot detect, because a truncated paragraph reads like a
     * short one.
     */
    public function isSettled(): bool
    {
        return $this->isTerminal();
    }

    /**
     * The legal moves, in one table.
     *
     * @return array<string, list<self>>
     */
    public static function transitionTable(): array
    {
        return [
            // Pending -> Complete is legal: a non-streaming provider call returns whole.
            self::Pending->value => [self::Streaming, self::Complete, self::Failed, self::Cancelled],
            self::Streaming->value => [self::Complete, self::Failed, self::Cancelled],
            // Sinks, all three. A change to a settled message is a RETRY — a new row carrying
            // `parent_message_id` — and never an edit. See the class docblock.
            self::Complete->value => [],
            self::Failed->value => [],
            self::Cancelled->value => [],
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

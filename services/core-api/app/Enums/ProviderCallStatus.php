<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one attempt against a provider ended (docs/11 §16.6).
 *
 * ── ONE ROW IS ONE ATTEMPT, WHICH IS WHY THERE IS NO `fell_back` VALUE ──────────────────────
 *
 * A turn that failed on the primary model and succeeded on a fallback writes TWO rows: a `Failed`
 * one naming the primary and its `error_class`, then a `Succeeded` one naming the fallback, with
 * `fallback_metadata` on the second recording why it was reached. A single row with a
 * `fell_back` status would have to choose ONE `provider_connection_id`, one token count and one
 * cost for two different vendors — and the cost of the failed attempt is real money that would
 * disappear from §8.23's "estimated provider cost".
 *
 * ── `Cancelled` IS SEPARATE FROM `Failed` FOR THE REASON `MessageStatus` STATES ─────────────
 *
 * `user_cancellation` is non-retryable and blames nobody; counting a closed laptop lid in the
 * provider error rate is what makes that dashboard unusable.
 *
 * `Pending` exists because the row is written when the call is DISPATCHED, not when it returns —
 * otherwise a call that never returned leaves nothing behind, and a call that never returned is
 * precisely the one an operator is looking for.
 */
enum ProviderCallStatus: string
{
    /** Dispatched, no terminal outcome recorded yet. */
    case Pending = 'pending';

    /** The provider answered. Token counts and cost on this row are final. */
    case Succeeded = 'succeeded';

    /** The provider did not answer. `error_class` says which of the 18 classes it was. */
    case Failed = 'failed';

    /** The caller went away before the provider finished. Tokens billed so far still count. */
    case Cancelled = 'cancelled';

    /**
     * Whether a row in this state must carry an `error_class`.
     *
     * `provider_calls_error_class_paired_with_status` is generated from this: a `Failed` row with
     * no class is an outage nobody can categorise, and a `Succeeded` row WITH one is a
     * contradiction the analytics would count as both.
     */
    public function requiresErrorClass(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

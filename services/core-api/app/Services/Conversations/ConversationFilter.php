<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Support\Database\SqlTimestamp;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How an operator narrows the conversation list. Everything here is a predicate over
 * `conversations` and nothing here reaches a second table.
 *
 * ── EVERY FIELD IS BACKED BY AN INDEX THAT LEADS WITH `organization_id` ──────────────────────
 *
 *   botId       `conversations_org_bot_activity (organization_id, bot_id, last_activity_at DESC)`
 *               — an exact index range when combined with this endpoint's default sort, which is
 *               the console's most common read.
 *   status      `conversations_org_status_started (organization_id, status, started_at DESC)`.
 *   userId      `conversations_org_user_started (…) WHERE user_id IS NOT NULL`. The predicate is
 *               `user_id = ?`, which implies the partial index's own condition, so the index is
 *               usable — a partial index is only usable when the query PROVES its predicate.
 *   sessionId   `conversations_org_session (…) WHERE anonymous_session_id IS NOT NULL`, same rule.
 *   from/until  the `started_at DESC` suffix of the two composite indexes above.
 *   channel     HAS NO INDEX OF ITS OWN and does not get one. It has four values, so it is never
 *               selective; it is applied as a filter on top of whichever org-leading index the
 *               other predicates chose, which is a scan of ONE organization's rows and not of the
 *               table. Adding `(organization_id, channel, …)` would be a fifth index on a table
 *               that is written on every turn, to narrow a set that is already tenant-bounded.
 *
 * ── THE WINDOW IS HALF-OPEN `[from, until)` AND EITHER END MAY BE ABSENT ────────────────────
 *
 * Unlike `AnalyticsWindow`, which requires both and refuses an empty range: an aggregate over an
 * unbounded window is a full-table scan on a screen that loads on every visit, whereas a LIST is
 * bounded by its page size whatever the window is. So there is no default window here and no
 * maximum width — the page size is the bound — and a caller that supplies neither end gets the
 * organization's whole history, newest first, one page at a time.
 *
 * WHAT IS STILL REFUSED is an INVERTED range, because `from >= until` selects nothing and renders
 * as "this bot has never been used" rather than as a bad request. The FormRequest refuses it first
 * with a per-field message; this constructor is the backstop for the service and job callers that
 * never ran one.
 *
 * ── THE COLUMN THE WINDOW APPLIES TO IS `started_at`, NOT `last_activity_at` ────────────────
 *
 * "Conversations that STARTED in this window" is a partition of the timeline; "conversations that
 * were ACTIVE in this window" is not — a thread started in January and answered in March belongs to
 * both months under the second reading and to neither under a naive `BETWEEN`. `AnalyticsWindow`
 * makes the same choice for the same reason, so the list and the dashboard count one population.
 *
 * THE ORGANIZATION IS DELIBERATELY NOT ON THIS OBJECT. It is a required positional argument on the
 * repository method, exactly as it is everywhere else in this layer, so it cannot be defaulted,
 * cannot be null, and cannot be forgotten by a caller who built a filter without one.
 */
final readonly class ConversationFilter
{
    /** The two bounds rendered for binding. See `SqlTimestamp` for why a Carbon may not be bound. */
    public ?string $fromBound;

    public ?string $untilBound;

    public function __construct(
        public ?string $botId = null,
        public ?ConversationChannel $channel = null,
        public ?ConversationStatus $status = null,
        public ?string $userId = null,
        public ?string $sessionId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
    ) {
        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException(
                'A conversation window is half-open [from, until) and must be non-empty. An '
                .'inverted or zero-width window selects nothing and renders as "this bot has never '
                .'been used" rather than as a bad request.',
            );
        }

        $this->fromBound = SqlTimestamp::bindOrNull($from);
        $this->untilBound = SqlTimestamp::bindOrNull($until);
    }
}

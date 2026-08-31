<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Every §8.23 tile, computed once, as data.
 *
 * ── ONE OBJECT RATHER THAN ELEVEN ENDPOINTS, AND ONE ORG-SCOPED QUERY PER TILE ───────────────
 *
 * The tiles are read together — they are one screen — so eleven endpoints would be eleven
 * round-trips, eleven authorization checks and eleven chances for one of them to be scoped
 * differently from the other ten. The queries stay separate (`kb-tenancy-isolation` names analytics
 * as its own isolation layer precisely because "group by bot_id and drop the redundant
 * organization_id" is such an easy thing to talk yourself into), and the object is what makes them
 * one response.
 *
 * ── NOTHING HERE IS A CREDENTIAL, AND NOTHING HERE CAN BECOME ONE ────────────────────────────
 *
 * Every field is a count, a sum, a percentile or a rate. `provider_calls` holds a
 * `provider_connection_id`, and no projection in `EloquentAnalyticsRepository` selects it: the
 * per-model breakdown groups on `(provider, model)`, which are a vendor name and a vendor model id.
 * Non-negotiable 9 is a property of the projections, not a promise made here.
 */
final readonly class AnalyticsSnapshot
{
    /**
     * @param  int  $conversations  threads STARTED in the window (`conversations.started_at`), not
     *                              threads active in it — a thread started last month and answered
     *                              today belongs to last month's cohort, and counting it twice is
     *                              what makes two months' totals exceed the year's.
     * @param  int  $messages  every turn in the window, both roles, all statuses.
     * @param  int  $uniqueSessions  distinct participants: the anonymous session token or the user
     *                               id, whichever the thread carries.
     *                               `conversations_participant_exclusive` guarantees exactly one, so
     *                               a `coalesce` of the two is a total function rather than a guess.
     * @param  list<ModelTokenUsage>  $tokensByModel  input and output kept APART, because they are
     *                                                priced apart and one total cannot be costed.
     */
    public function __construct(
        public int $conversations,
        public int $messages,
        public int $uniqueSessions,
        public LatencySummary $latency,
        public ProviderOutcomeSummary $providerOutcomes,
        public FeedbackSummary $feedback,
        public int $insufficientEvidenceAnswers,
        public array $tokensByModel,
        public IngestionSummary $ingestion,
        public int $storageBytesUsed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Services\Quotas\QuotaCounters;

/**
 * Assemble §8.23's dashboard: eleven tiles, eleven org-scoped queries, one object.
 *
 * ── NO AUDIT ROW ────────────────────────────────────────────────────────────────────────────
 *
 * §18.11 audits credential changes and destructive operations; reading a dashboard is neither, and
 * auditing it would bury the rows that matter under one per page load. Same call
 * `SourceService::list()` makes, for the same reason.
 *
 * ── STORAGE USED COMES FROM THE LEDGER AND NOT FROM A `sum(byte_size)` ──────────────────────
 *
 * There is a second, tempting source for that tile: `SUM(source_items.byte_size)` over the
 * organization's live sources. It is NOT used, and the difference is the whole reason the ledger
 * exists. That sum answers "how many bytes do the rows currently claim", which stops being the
 * storage bill the moment an object survives its row (an orphan the sweep has not collected yet) or
 * a row survives its object (a purge that removed bytes before the relational delete). The ledger
 * answers "how many bytes were added minus how many were released", which is the number
 * `QuotaGate` refuses against — so the dashboard and the gate cannot disagree about whether an
 * organization is over its storage limit, which is the one disagreement a customer notices.
 *
 * IT IS ALSO THE SAME READ THE QUOTA SCREEN MAKES, through the same class, so the two numbers on
 * two screens are one query with one answer.
 */
final readonly class AnalyticsService
{
    public function __construct(
        private AnalyticsRepositoryInterface $analytics,
        private QuotaCounters $counters,
    ) {}

    /**
     * Every tile for one organization and one window.
     *
     * ELEVEN SEPARATE QUERIES, ISSUED IN ORDER, AND NOT WRAPPED IN A TRANSACTION. Wrapping them
     * would give one consistent snapshot across all eleven, and it would hold a transaction open for
     * the duration of eleven aggregates — which pins `xmin` and stops autovacuum reclaiming dead
     * tuples database-wide (`postgresql-patterns`). A dashboard whose message count was taken 40 ms
     * after its conversation count is not wrong in any way a reader can perceive; a reporting query
     * that blocks vacuum on a live database is.
     */
    public function snapshot(Organization $organization, AnalyticsWindow $window): AnalyticsSnapshot
    {
        $organizationId = $organization->organizationId();

        return new AnalyticsSnapshot(
            conversations: $this->analytics->conversationCount($organizationId, $window),
            messages: $this->analytics->messageCount($organizationId, $window),
            uniqueSessions: $this->analytics->uniqueSessionCount($organizationId, $window),
            latency: $this->analytics->latency($organizationId, $window),
            providerOutcomes: $this->analytics->providerOutcomes($organizationId, $window),
            feedback: $this->analytics->feedback($organizationId, $window),
            insufficientEvidenceAnswers: $this->analytics->insufficientEvidenceCount(
                $organizationId,
                $window,
            ),
            tokensByModel: $this->analytics->tokensByModel($organizationId, $window),
            ingestion: $this->analytics->ingestion($organizationId, $window),
            // NOT WINDOWED, AND THAT IS THE TILE RATHER THAN AN OVERSIGHT. Storage used is a LEVEL,
            // not a flow: "how many bytes does this organization occupy right now" has no start
            // date, and applying the window would answer "how many bytes were added this month",
            // which is a different question that nobody's quota is expressed in.
            storageBytesUsed: $this->counters->authoritative(
                $organizationId,
                QuotaMetric::StorageBytes,
            )->used,
        );
    }
}

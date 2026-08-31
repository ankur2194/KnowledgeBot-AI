<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\FeedbackSummary;
use App\Services\Analytics\IngestionSummary;
use App\Services\Analytics\LatencySummary;
use App\Services\Analytics\ModelTokenUsage;
use App\Services\Analytics\ProviderOutcomeSummary;

/**
 * §8.23's tiles, one method per tile, ONE ORG-SCOPED QUERY EACH.
 *
 * ── WHY ONE QUERY PER TILE AND NOT ONE QUERY WITH TEN SUBSELECTS ─────────────────────────────
 *
 * The tiles read from six different tables with six different time columns and three different join
 * paths. A single statement would need every one of those as a correlated subquery, which is the
 * same work with one plan the planner cannot optimise per branch, and it would make adding a tile a
 * rewrite of the whole statement. Separate methods also mean a tile that is slow is a tile that can
 * be measured.
 *
 * ── `$organizationId` IS REQUIRED AND POSITIONAL ON EVERY METHOD ─────────────────────────────
 *
 * `kb-tenancy-isolation` lists analytics as its OWN isolation layer, and names the exact way it
 * fails: "aggregates over `usage_events`, `provider_calls` and `messages` get grouped by `bot_id`
 * and the `organization_id` predicate is dropped as redundant." It is never redundant. Three of the
 * six tables below (`messages`, `retrieval_traces`, `feedback`) do not even HOLD an organization —
 * they reach one through `conversations` — so on those the predicate is the only tenancy there is.
 *
 * NO DEFAULT VALUE ANYWHERE, and no method takes a nullable organization. An unfiltered analytics
 * query is a legal, successful query that returns every tenant's numbers, and nothing in the
 * response distinguishes it from a correct one.
 */
interface AnalyticsRepositoryInterface
{
    /** Threads STARTED in the window (`conversations.started_at`). */
    public function conversationCount(string $organizationId, AnalyticsWindow $window): int;

    /** Turns in the window, both roles, all statuses. */
    public function messageCount(string $organizationId, AnalyticsWindow $window): int;

    /**
     * Distinct participants: the anonymous session token or the user id, whichever the thread
     * carries. `conversations_participant_exclusive` guarantees exactly one of the two is set.
     */
    public function uniqueSessionCount(string $organizationId, AnalyticsWindow $window): int;

    public function latency(string $organizationId, AnalyticsWindow $window): LatencySummary;

    public function providerOutcomes(
        string $organizationId,
        AnalyticsWindow $window,
    ): ProviderOutcomeSummary;

    public function feedback(string $organizationId, AnalyticsWindow $window): FeedbackSummary;

    /**
     * Answers that refused for want of evidence — `retrieval_traces.insufficient_evidence`.
     *
     * THE ONE TILE THAT MEASURES A CORRECT REFUSAL RATHER THAN A FAILURE, which is why it is its own
     * number and not folded into the error rate: an insufficient-evidence answer is the RAG contract
     * working, and a bot whose count is climbing needs sources, not an on-call engineer.
     */
    public function insufficientEvidenceCount(string $organizationId, AnalyticsWindow $window): int;

    /**
     * Tokens and estimated cost per `(provider, model)`.
     *
     * READ FROM `provider_calls` AND NOT FROM THE LEDGER, deliberately: this tile needs the PRICE,
     * which lives on `provider_models` and is reachable only from the call row's `model_id`. The
     * ledger deliberately carries no cost column (its migration says why: a stored cost is a second
     * copy of a number computed from pricing that can change, so it disagrees with the invoice the
     * moment a price is corrected).
     *
     * @return list<ModelTokenUsage>
     */
    public function tokensByModel(string $organizationId, AnalyticsWindow $window): array;

    /** Ingestion outcomes over `source_versions` created in the window. */
    public function ingestion(string $organizationId, AnalyticsWindow $window): IngestionSummary;
}

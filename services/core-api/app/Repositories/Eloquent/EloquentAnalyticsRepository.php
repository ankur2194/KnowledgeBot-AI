<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\FeedbackRating;
use App\Enums\ProviderCallStatus;
use App\Enums\SourceState;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\RetrievalTrace;
use App\Models\SourceVersion;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Services\Analytics\AnalyticsWindow;
use App\Services\Analytics\FeedbackSummary;
use App\Services\Analytics\IngestionSummary;
use App\Services\Analytics\LatencySummary;
use App\Services\Analytics\ModelTokenUsage;
use App\Services\Analytics\ProviderOutcomeSummary;
use App\Services\Usage\UsageArithmetic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * §8.23's aggregates, in SQL. Aggregated PostgreSQL only — no warehouse, no second datastore.
 *
 * ═══ THE TENANT PREDICATE, TABLE BY TABLE, BECAUSE IT IS NOT THE SAME PREDICATE ══════════════
 *
 * Three of the six tables here hold `organization_id` and three do not, and the ones that do not are
 * the ones a reviewer is most likely to wave through:
 *
 *   conversations     organization_id, directly. `#[ScopedBy]` is the backstop.
 *   provider_calls    organization_id, directly, and it SURVIVES RETENTION — `conversation_id` and
 *                     `message_id` both null out, `organization_id` and `bot_id` never do. Every
 *                     aggregate that must keep adding up therefore groups on those two and NEVER
 *                     joins through the thread.
 *   source_versions   organization_id, directly.
 *
 *   messages          NO organization_id. It reaches one through `conversation_id`, which is
 *                     NOT NULL and ON DELETE CASCADE — the NN1 chain. So the tenant predicate is on
 *                     the JOINED `conversations` row and there is no second layer under it: `Message`
 *                     carries no `#[ScopedBy]` and cannot, because the column it would filter does
 *                     not exist. Forget the join and the query counts every tenant's turns.
 *   retrieval_traces  NO organization_id. Reaches one through `messages -> conversations`, a TWO-hop
 *                     join, which is the longest chain on this surface and the one most likely to
 *                     be shortened by somebody optimising.
 *   feedback          NO organization_id. Same two-hop chain.
 *
 * `kb-tenancy-isolation` names analytics as its own isolation layer for exactly this reason, and
 * names the failure: the `organization_id` predicate dropped "as redundant" because the query
 * already groups by bot. It is never redundant, and on three of these tables it is the only tenancy
 * that exists.
 *
 * ═══ WINDOWS ARE HALF-OPEN AND EVERY TABLE'S TIME COLUMN IS ITS OWN ══════════════════════════
 *
 * `conversations.started_at`, `messages.created_at`, `provider_calls.created_at`,
 * `retrieval_traces.created_at`, `feedback.created_at`, `source_versions.created_at`. They are not
 * interchangeable: a thread started in March and answered in April has a March `started_at` and an
 * April message, and both are correct for their own tile.
 *
 * ═══ WHY `Builder`-SHAPED HELPERS AND NOT ONE GIANT METHOD ══════════════════════════════════
 *
 * The three join paths are written ONCE each, below, and every tile that needs one calls it. A tile
 * that re-spelled the join is a tile that can spell it with the organization predicate missing, and
 * that is a defect no test catches unless the test happens to have two organizations — which is why
 * tests/Security/AnalyticsTenancyTest.php builds on `tenantPair()`.
 */
final class EloquentAnalyticsRepository implements AnalyticsRepositoryInterface
{
    public function conversationCount(string $organizationId, AnalyticsWindow $window): int
    {
        return $this->conversationsIn($organizationId, $window)->count();
    }

    public function messageCount(string $organizationId, AnalyticsWindow $window): int
    {
        return $this->messagesIn($organizationId, $window)->count();
    }

    public function uniqueSessionCount(string $organizationId, AnalyticsWindow $window): int
    {
        // `coalesce(anonymous_session_id, user_id)` is a TOTAL function here rather than a guess:
        // `conversations_participant_exclusive` is `num_nonnulls(user_id, anonymous_session_id) = 1`,
        // so exactly one of the two is present on every row and the coalesce always resolves. That
        // constraint is the reason this tile is one column rather than a UNION of two.
        //
        // A signed-in visitor on hosted chat also carries a session cookie, which is precisely the
        // state the constraint refuses to store — the migration's own words: "with both columns
        // populated, §8.23's unique sessions counts the same person twice".
        // `DB::raw` WITH A LITERAL, INTERPOLATION-FREE STRING. `count()` takes a column NAME, and
        // this is an expression, so it has to be raw — but the expression contains no value from
        // anywhere: two column names and a function. The organization predicate is bound, on the
        // builder, above.
        return $this->conversationsIn($organizationId, $window)
            ->distinct()
            ->count(DB::raw('coalesce(anonymous_session_id, user_id)'));
    }

    public function latency(string $organizationId, AnalyticsWindow $window): LatencySummary
    {
        // ONE STATEMENT FOR ALL FOUR PERCENTILES. Four statements would be four index scans over the
        // same rows, and — the part that matters — four SNAPSHOTS, so a p50 and a p95 could be
        // computed over different populations while a stream was landing between them.
        //
        // `percentile_disc` (discrete) rather than `percentile_cont`: the value is one an actual
        // request measured, so it can be found in the log. An interpolated 1,247.5 ms belongs to no
        // request and cannot be investigated.
        //
        // FILTERED TO `succeeded`, because a failed attempt's latency is time-to-error and mixing it
        // into a time-to-answer percentile makes a fast failure look like a fast answer. The NULLs
        // are skipped by the aggregate itself — `first_token_latency_ms` is NULL on a non-streaming
        // call and on one that never produced a token, which is a real state and not a zero.
        $row = $this->providerCallsIn($organizationId, $window)
            ->where('status', '=', ProviderCallStatus::Succeeded->value)
            ->selectRaw(<<<'SQL'
                percentile_disc(0.5)  WITHIN GROUP (ORDER BY first_token_latency_ms) AS ft_p50,
                percentile_disc(0.95) WITHIN GROUP (ORDER BY first_token_latency_ms) AS ft_p95,
                percentile_disc(0.5)  WITHIN GROUP (ORDER BY total_latency_ms)       AS total_p50,
                percentile_disc(0.95) WITHIN GROUP (ORDER BY total_latency_ms)       AS total_p95
            SQL)
            ->first();

        $values = $row instanceof ProviderCall ? $row->getAttributes() : [];

        return new LatencySummary(
            firstTokenP50Ms: $this->nullableInt($values['ft_p50'] ?? null),
            firstTokenP95Ms: $this->nullableInt($values['ft_p95'] ?? null),
            totalP50Ms: $this->nullableInt($values['total_p50'] ?? null),
            totalP95Ms: $this->nullableInt($values['total_p95'] ?? null),
        );
    }

    public function providerOutcomes(
        string $organizationId,
        AnalyticsWindow $window,
    ): ProviderOutcomeSummary {
        // ONE PASS WITH FOUR FILTERED COUNTS. Four queries would be four scans of the same index
        // range and four snapshots, and the ratio of two numbers read at two instants is a ratio of
        // two different populations.
        //
        // `cancelled` and `pending` appear in NEITHER outcome count, and that omission is the tile:
        // a user closing the tab is not a provider error, and counting it as one hides real outages
        // behind ordinary behaviour (App\Enums\MessageStatus records the same reasoning).
        //
        // THE FALLBACK DENOMINATOR IS `fallback_metadata = '{}'`, i.e. PRIMARY ATTEMPTS, and never
        // `count(DISTINCT message_id)` — that column is ON DELETE SET NULL, so a retention sweep
        // would shrink the denominator of a CLOSED month and the fallback rate would climb nightly
        // with no fallback having happened. ProviderOutcomeSummary carries the full argument.
        $row = $this->providerCallsIn($organizationId, $window)
            ->selectRaw(
                'count(*) FILTER (WHERE status = ?)                     AS succeeded,'
                .' count(*) FILTER (WHERE status = ?)                    AS failed,'
                ." count(*) FILTER (WHERE fallback_metadata = '{}'::jsonb)  AS primary_attempts,"
                ." count(*) FILTER (WHERE fallback_metadata <> '{}'::jsonb) AS fallback_attempts",
                [ProviderCallStatus::Succeeded->value, ProviderCallStatus::Failed->value],
            )
            ->first();

        $values = $row instanceof ProviderCall ? $row->getAttributes() : [];

        return new ProviderOutcomeSummary(
            succeeded: (int) ($values['succeeded'] ?? 0),
            failed: (int) ($values['failed'] ?? 0),
            primaryAttempts: (int) ($values['primary_attempts'] ?? 0),
            fallbackAttempts: (int) ($values['fallback_attempts'] ?? 0),
        );
    }

    public function feedback(string $organizationId, AnalyticsWindow $window): FeedbackSummary
    {
        $row = $this->feedbackIn($organizationId, $window)
            ->selectRaw(
                'count(*) FILTER (WHERE feedback.rating = ?) AS positive,'
                .' count(*) FILTER (WHERE feedback.rating = ?) AS negative',
                [FeedbackRating::Positive->value, FeedbackRating::Negative->value],
            )
            ->first();

        $values = $row instanceof Feedback ? $row->getAttributes() : [];

        return new FeedbackSummary(
            positive: (int) ($values['positive'] ?? 0),
            negative: (int) ($values['negative'] ?? 0),
        );
    }

    public function insufficientEvidenceCount(string $organizationId, AnalyticsWindow $window): int
    {
        return $this->retrievalTracesIn($organizationId, $window)
            ->where('retrieval_traces.insufficient_evidence', '=', true)
            ->count();
    }

    public function tokensByModel(string $organizationId, AnalyticsWindow $window): array
    {
        // ═══ THE BILLING ARITHMETIC, AND IT IS THE ONE THING ON THIS SURFACE THAT IS SILENTLY
        //     WRONG IF YOU WRITE THE OBVIOUS QUERY ════════════════════════════════════════════
        //
        // INPUT is `input_tokens` ALONE. It is ALREADY the normalized total — cache reads and cache
        // writes INCLUDED — and the two cache columns are a BREAKDOWN of it, which
        // `provider_calls_cache_within_input` enforces as `cache_read + cache_write <= input_tokens`.
        // So `input_tokens + cache_read_tokens + cache_write_tokens`, which reads as the obviously
        // correct sum, DOUBLE-COUNTS every cached token — and only on the vendors that cache, so the
        // bill is wrong by a percentage that varies by provider.
        //
        // OUTPUT is `output_tokens + reasoning_tokens`. They are DISJOINT counters and every vendor
        // here bills reasoning AT THE OUTPUT RATE, so an aggregate over bare `output_tokens`
        // under-reports a reasoning turn silently, plausibly, and by a factor that depends on the
        // question.
        //
        // Both expressions come from App\Services\Usage\UsageArithmetic rather than being spelled
        // here, so the SQL and the PHP that writes the ledger cannot drift; that class carries the
        // whole argument and tests/Unit/UsageArithmeticTest.php asserts the two agree.
        $input = UsageArithmetic::INPUT_SQL;
        $output = UsageArithmetic::OUTPUT_SQL;

        // THE JOINS ARE INNER AND THE PREDICATES ARE COMPOSITE. `provider_models` and
        // `provider_connections` are both joined ON THE ORGANIZATION AS WELL AS THE ID, so a
        // mis-scoped `model_id` cannot pull another tenant's price into this tenant's cost estimate.
        // The composite foreign keys already make that row unwritable; the join predicate is what
        // makes the READ unable to express it either.
        $rows = $this->providerCallsIn($organizationId, $window)
            ->join('provider_models', function ($join) use ($organizationId): void {
                $join->on('provider_models.id', '=', 'provider_calls.model_id')
                    ->where('provider_models.organization_id', '=', $organizationId);
            })
            ->join('provider_connections', function ($join) use ($organizationId): void {
                $join->on(
                    'provider_connections.id',
                    '=',
                    'provider_calls.provider_connection_id',
                )->where('provider_connections.organization_id', '=', $organizationId);
            })
            // GROUPED ON THE VENDOR AND THE MODEL STRING, not on `model_id`: two catalogue rows can
            // name the same vendor model on two connections, and a per-row breakdown would split one
            // model's spend across them for a reason that means nothing to a reader.
            //
            // `price_currency` is IN THE GROUP KEY on purpose. Summing two currencies is a silent
            // wrong answer, so a model whose price currency changed mid-window produces TWO rows
            // rather than one uncostable total (ModelTokenUsage carries the argument).
            ->groupBy(
                'provider_connections.provider',
                'provider_models.model',
                'provider_models.price_currency',
            )
            ->orderBy('provider_connections.provider')
            ->orderBy('provider_models.model')
            ->selectRaw(<<<SQL
                provider_connections.provider AS provider,
                provider_models.model AS model,
                provider_models.price_currency AS currency,
                coalesce(sum({$input}), 0) AS input_tokens,
                coalesce(sum({$output}), 0) AS output_tokens,
                sum(
                    ({$input}) * coalesce(provider_models.input_price_per_million, 0)
                    + ({$output}) * coalesce(provider_models.output_price_per_million, 0)
                ) / 1000000 AS estimated_cost
            SQL)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $values = $row->getAttributes();
            $currency = $values['currency'] ?? null;

            // NO PRICE MEANS NO COST, AND THAT IS NOT THE SAME AS ZERO. An unpriced catalogue row
            // yields tokens with a null estimate; rendering 0 there would report a spend of nothing
            // for a model that cost real money, which is the direction that never gets noticed. The
            // currency's absence IS the test: `provider_models_price_needs_currency` makes a price
            // without one unwritable, so a null currency means no price was recorded.
            $out[] = new ModelTokenUsage(
                provider: (string) ($values['provider'] ?? ''),
                model: (string) ($values['model'] ?? ''),
                inputTokens: (int) ($values['input_tokens'] ?? 0),
                outputTokens: (int) ($values['output_tokens'] ?? 0),
                // A STRING, never a float. `numeric(14, 6)` is exact and PDO hands it back as a
                // string; casting to float here would put the binary rounding error the pricing
                // migration refuses back into the number on its way to the wire.
                estimatedCost: is_string($currency) ? (string) ($values['estimated_cost'] ?? '0') : null,
                currency: is_string($currency) ? $currency : null,
            );
        }

        return $out;
    }

    public function ingestion(string $organizationId, AnalyticsWindow $window): IngestionSummary
    {
        // SUCCEEDED IS `ready` OR `ready_with_warnings`. A version with warnings IS searchable and IS
        // serving answers, so filing it as a failure reports an outage that is not happening.
        //
        // IN-FLIGHT IS PUBLISHED rather than omitted, because without it succeeded + failed does not
        // add up to the versions created in the window and a reader who cannot make the numbers add
        // up assumes one of them is wrong. The terminal-but-not-ingestion states (`disabled`,
        // `deleting`, `deleted`, `archived`, `draft`) are in none of the three: they are lifecycle
        // outcomes rather than ingestion outcomes, so counting them would make "did the work
        // succeed" a question about administration.
        $inFlight = [
            SourceState::Queued->value,
            SourceState::Fetching->value,
            SourceState::Parsing->value,
            SourceState::Normalizing->value,
            SourceState::Chunking->value,
            SourceState::Embedding->value,
            SourceState::Indexing->value,
        ];

        $placeholders = implode(', ', array_fill(0, count($inFlight), '?'));

        $row = SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->where('created_at', '>=', $window->fromBound)
            ->where('created_at', '<', $window->untilBound)
            ->selectRaw(
                'count(*) FILTER (WHERE status IN (?, ?)) AS succeeded,'
                .' count(*) FILTER (WHERE status = ?)      AS failed,'
                ." count(*) FILTER (WHERE status IN ({$placeholders})) AS in_flight",
                array_merge(
                    [
                        SourceState::Ready->value,
                        SourceState::ReadyWithWarnings->value,
                        SourceState::Failed->value,
                    ],
                    $inFlight,
                ),
            )
            ->first();

        $values = $row instanceof SourceVersion ? $row->getAttributes() : [];

        return new IngestionSummary(
            succeeded: (int) ($values['succeeded'] ?? 0),
            failed: (int) ($values['failed'] ?? 0),
            inFlight: (int) ($values['in_flight'] ?? 0),
        );
    }

    /**
     * THE BOT FILTER IS NOT ON THIS TABLE'S OWN COLUMN FOR EVERY TILE, so it is applied by whichever
     * helper owns the join. Written once per join path, below.
     *
     * @return Builder<Conversation>
     */
    private function conversationsIn(string $organizationId, AnalyticsWindow $window): Builder
    {
        $query = Conversation::query()
            ->where('conversations.organization_id', '=', $organizationId)
            ->where('conversations.started_at', '>=', $window->fromBound)
            ->where('conversations.started_at', '<', $window->untilBound);

        if ($window->botId !== null) {
            $query->where('conversations.bot_id', '=', $window->botId);
        }

        return $query;
    }

    /**
     * `messages` HOLDS NO ORGANIZATION. It reaches one only through `conversation_id`, which is
     * NOT NULL and ON DELETE CASCADE — that chain IS this table's tenancy (NN1), and the join below
     * is the only place it is expressed on this surface. `Message` carries no `#[ScopedBy]` and
     * cannot: the column it would filter does not exist, so there is no backstop under this join.
     *
     * @return Builder<Message>
     */
    private function messagesIn(string $organizationId, AnalyticsWindow $window): Builder
    {
        $query = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.organization_id', '=', $organizationId)
            ->where('messages.created_at', '>=', $window->fromBound)
            ->where('messages.created_at', '<', $window->untilBound);

        if ($window->botId !== null) {
            $query->where('conversations.bot_id', '=', $window->botId);
        }

        return $query;
    }

    /**
     * `provider_calls` HOLDS THE ORGANIZATION AND THE BOT DIRECTLY, AND BOTH SURVIVE RETENTION.
     * That is why no aggregate over this table joins `conversations`: `conversation_id` and
     * `message_id` are both ON DELETE SET NULL, so a joined form would silently drop every call
     * whose thread has been swept — and a cost report that shrinks when retention runs is a rewrite
     * of last quarter's spend.
     *
     * @return Builder<ProviderCall>
     */
    private function providerCallsIn(string $organizationId, AnalyticsWindow $window): Builder
    {
        $query = ProviderCall::query()
            ->where('provider_calls.organization_id', '=', $organizationId)
            ->where('provider_calls.created_at', '>=', $window->fromBound)
            ->where('provider_calls.created_at', '<', $window->untilBound);

        if ($window->botId !== null) {
            $query->where('provider_calls.bot_id', '=', $window->botId);
        }

        return $query;
    }

    /**
     * TWO HOPS TO AN ORGANIZATION: `retrieval_traces -> messages -> conversations`. Both links are
     * NOT NULL and ON DELETE CASCADE, so the chain cannot be broken by a row — but it CAN be broken
     * by a query that shortens it, which is what this helper exists to prevent.
     *
     * @return Builder<RetrievalTrace>
     */
    private function retrievalTracesIn(string $organizationId, AnalyticsWindow $window): Builder
    {
        $query = RetrievalTrace::query()
            ->join('messages', 'messages.id', '=', 'retrieval_traces.message_id')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.organization_id', '=', $organizationId)
            ->where('retrieval_traces.created_at', '>=', $window->fromBound)
            ->where('retrieval_traces.created_at', '<', $window->untilBound);

        if ($window->botId !== null) {
            $query->where('conversations.bot_id', '=', $window->botId);
        }

        return $query;
    }

    /**
     * The same two-hop chain, from `feedback`.
     *
     * @return Builder<Feedback>
     */
    private function feedbackIn(string $organizationId, AnalyticsWindow $window): Builder
    {
        $query = Feedback::query()
            ->join('messages', 'messages.id', '=', 'feedback.message_id')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.organization_id', '=', $organizationId)
            ->where('feedback.created_at', '>=', $window->fromBound)
            ->where('feedback.created_at', '<', $window->untilBound);

        if ($window->botId !== null) {
            $query->where('conversations.bot_id', '=', $window->botId);
        }

        return $query;
    }

    /**
     * `percentile_disc` returns NULL over an empty population, and NULL means "nothing to measure"
     * rather than zero. Casting it here would put "0 ms to first token" on a dashboard for an outage.
     */
    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * One `retrieval_traces` row, built from the `retrieval.trace` frame.
 *
 * ═══ THIS PLANE IS THE ONLY WRITER, BY DECISION RATHER THAN BY ACCIDENT ════════════════════
 *
 * `retrieval_traces` came off the data plane's `ALLOWED_TABLES` under ADR-074 / ADR-033 property 2,
 * because the public API READS it — so a data-plane write would land beside Laravel's own writer
 * with no policy, no audit row and no framework-applied tenant scope. `app/api/internal/v1/chat.py`
 * says the same thing from the other side: it EMITS one frame and writes no row.
 *
 * Until this class existed the table had a migration, a model, and no writer on either plane.
 *
 * ═══ IT IS WRITTEN IN THE SAME TRANSACTION AS THE MESSAGE, AND THAT IS THE POINT ═══════════
 *
 * `retrieval_traces` is CASCADE on `message_id`, so it cannot OUTLIVE the message. What a separate
 * transaction would allow is the mirror image — a trace that PRECEDES its message, explaining an
 * answer that does not exist yet and may never — and a trace that describes a turn the transcript
 * denies is worse than no trace, because §21.4 replays them as regression baselines.
 *
 * ═══ THE FOUR MANDATORY FILTER TERMS ARE NAMED, AND THE CONSTRAINT CHECKS ONLY THAT ════════
 *
 * `retrieval_traces_filters_name_mandatory_terms` requires `filters` to carry `org_id`, `bot_ids`,
 * `source_status` and `source_version_id`. READ THE MIGRATION'S OWN WARNING BEFORE RELYING ON IT: it
 * proves the trace RECORDS four terms, not that the QUERY carried them. This class fills them from
 * what Laravel resolved and shipped — the organization, the bot, the retrievable statuses and the
 * `allowed_version_ids` set — which is the honest thing it can say, and it is exactly the input the
 * data plane built its filter from.
 *
 * ═══ WHY `trace` IS AN OPEN OBJECT ON THE WIRE AND IS SPLIT ACROSS COLUMNS HERE ════════════
 *
 * `RetrievalTraceFrame.trace` is `dict[str, Any]` on purpose: its shape is the pipeline's own
 * `RetrievalTrace` and changes with the pipeline, and a second typed transcription would be a second
 * thing to keep in step. This class reads the four fields the TABLE has columns for and puts the
 * rest — every stage fragment — into `timing_breakdown`, which nothing queries by predicate. A
 * field that moves inside the pipeline therefore changes what is stored and breaks nothing.
 */
final readonly class RecordedTrace
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<mixed>  $candidateSummaries  AN ARRAY, NOT AN OBJECT: the ORDER IS THE RANKING,
     *                                           and `retrieval_traces_candidate_summaries_is_array`
     *                                           refuses the object spelling.
     * @param  list<mixed>  $selectedEvidence
     * @param  array<string, mixed>  $timingBreakdown
     */
    public function __construct(
        public string $originalQuery,
        public ?string $rewrittenQuery,
        public array $filters,
        public int $retrievalConfigurationVersion,
        public array $candidateSummaries,
        public array $selectedEvidence,
        public bool $insufficientEvidence,
        public array $timingBreakdown,
    ) {}

    /**
     * Build one from the frame's payload, the turn's own question, and the scope Laravel resolved.
     *
     * ── THE QUERY COMES FROM THE CONTEXT AND NOT FROM THE FRAME ────────────────────────────
     *
     * `retrieval_traces_original_query_not_blank` refuses an empty string, and the frame's own copy
     * of the question is whatever the pipeline recorded — which after stage 1's normalisation is not
     * necessarily the bytes the visitor typed. The transcript must say what was ASKED, so the
     * original comes from `ChatContext::$query` and the frame supplies only the REWRITE, which is
     * null when stage 5 left the query alone. Null there is a real outcome and is different from a
     * rewrite that happened to produce the same string.
     *
     * @param  array<string, mixed>  $trace  the frame's `trace` object, verbatim
     * @param  list<string>  $allowedVersionIds
     */
    public static function fromFrame(
        array $trace,
        string $originalQuery,
        string $organizationId,
        string $botId,
        array $allowedVersionIds,
        int $retrievalConfigurationVersion,
    ): self {
        $rewritten = $trace['rewritten_query'] ?? null;

        return new self(
            originalQuery: $originalQuery,
            rewrittenQuery: is_string($rewritten) && trim($rewritten) !== '' && $rewritten !== $originalQuery
                ? $rewritten
                : null,
            filters: [
                // THE FOUR MANDATORY TERMS, from what this plane resolved and shipped.
                'org_id' => $organizationId,
                // A LIST, because the payload field on a point is a list of every bot the source is
                // assigned to and the filter is a `MatchAny` over it. One element here is this turn's
                // scope, not a claim that the chunk is single-bot.
                'bot_ids' => [$botId],
                // The POSITIVE set. A `match` condition is not satisfied by a point lacking the
                // value, so a positive filter fails closed — which is why the trace records the
                // admitted statuses rather than the excluded ones.
                'source_status' => ['ready', 'ready_with_warnings'],
                'source_version_id' => $allowedVersionIds,
            ],
            retrievalConfigurationVersion: max(1, $retrievalConfigurationVersion),
            candidateSummaries: self::listOf($trace, 'candidates'),
            selectedEvidence: self::listOf($trace, 'selected'),
            insufficientEvidence: ($trace['insufficient_evidence'] ?? false) === true,
            // EVERYTHING ELSE. The stage fragments, the timings, the skip reasons — stored whole,
            // read whole, never queried by predicate, which is one of the three shapes
            // `postgresql-patterns` admits jsonb for.
            timingBreakdown: $trace,
        );
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return list<mixed>
     */
    private static function listOf(array $trace, string $key): array
    {
        $value = $trace[$key] ?? [];

        // `array_is_list` and not merely `is_array`: an associative array encodes as a JSON OBJECT
        // and `retrieval_traces_candidate_summaries_is_array` refuses it — inside the finalizer's
        // one transaction, which would lose the whole transcript over a shape.
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * `insufficient_evidence = true` and a non-empty `selected_evidence` is a stored contradiction
     * that `retrieval_traces_insufficient_has_no_evidence` refuses. Reconciled HERE so a pipeline
     * that reported both cannot take the transaction down with it: the REFUSAL wins, because it is
     * what the reader was actually told, and the evidence is dropped rather than the refusal.
     *
     * @return list<mixed>
     */
    public function consistentSelectedEvidence(): array
    {
        return $this->insufficientEvidence ? [] : $this->selectedEvidence;
    }
}

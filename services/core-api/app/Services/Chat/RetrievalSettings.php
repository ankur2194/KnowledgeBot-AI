<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\BotAnswerMode;
use App\Models\Bot;

/**
 * `ConfigSnapshot.retrieval` — the tunable core of stages 5-13, resolved from the bot's own row.
 *
 * ═══ EVERY VALUE COMES FROM `bots`, AND THE ONES THAT DO NOT ARE NAMED HERE ═════════════════
 *
 * `dense_top_k`, `sparse_top_k`, `rerank_candidates`, `rerank_retain`, `answer_mode` and
 * `retrieval_configuration_version` are columns with CHECK-constrained bands
 * (2026_08_19_001400). They move through an evaluation run, never through a code edit
 * (`kb-rag-query-contract`: a changed top-K with no evaluation attached is an unreviewable change).
 *
 * Four fields have NO column and are drawn from `config/kb.php` instead, because they are platform
 * defaults rather than per-bot configuration. They are listed on the fields; the important property
 * is that they are the SAME values `app/rag/runner.py::RetrievalConfig` defaults to, so a body that
 * carries them behaves exactly as a body that omitted them would.
 *
 * ═══ `rerank_top_n` IS ZERO WHEN THE ORGANIZATION HAS NO RANKING CONNECTION ═════════════════
 *
 * `0` is the config-off form of stage 11 on the far side: the stage still executes and still writes
 * a fragment carrying `disabled_by_configuration`, so the trace says the stage was off rather than
 * saying nothing. It is NOT a code path that jumps, and it is not the same as `rerank_connection:
 * null` — a bot may have a ranking connection available and be configured not to use it. Both are
 * expressed, separately, and `ConfigSnapshotResolver` decides.
 *
 * ═══ `retain` MUST NOT EXCEED `rerank_top_n` WHEN RERANKING RUNS ═══════════════════════════
 *
 * Retaining more candidates than were ever scored produces a short context and NO ERROR. The bots
 * table's own `bots_rerank_retain_within_candidates` says the same thing and is unreachable today
 * (the two bands are disjoint); this is the reachable half, because `rerank_top_n` here can be 0.
 * The far side bounds `retain` at `ge=1`, so it is clamped rather than zeroed.
 */
final readonly class RetrievalSettings
{
    private function __construct(
        public int $denseTopK,
        public int $sparseTopK,
        public int $rerankTopN,
        public int $fusionK,
        public int $retain,
        public int $maxPerDocument,
        public bool $rewriteEnabled,
        public int $reserveOutput,
        public int $historyWindowTurns,
        public int $questionMaxChars,
        public bool $citationsEnabled,
        public bool $requireAtLeastOneCitation,
    ) {}

    /**
     * @param  bool  $rerankAvailable  whether the organization designated a ranking-capable
     *                                 connection. False collapses `rerank_top_n` to 0 — see the
     *                                 class docblock.
     */
    public static function forBot(Bot $bot, bool $rerankAvailable): self
    {
        $rerankTopN = $rerankAvailable ? (int) $bot->rerank_candidates : 0;

        return new self(
            denseTopK: (int) $bot->dense_top_k,
            sparseTopK: (int) $bot->sparse_top_k,
            rerankTopN: $rerankTopN,
            // NEVER Qdrant's own default of 2. At k=2 the rank-1 hit of each branch dominates
            // roughly thirty times more sharply than the literature's 60, and the symptom is hybrid
            // search preferring whatever sparse returned first on queries where dense was obviously
            // right (`kb-rag-query-contract`).
            fusionK: (int) config('kb.retrieval.fusion_k'),
            // Clamped, not asserted: `retain` is `ge=1` on the far side, so a bot with reranking off
            // still has to send a positive number, and sending one larger than a `rerank_top_n` of 0
            // would be a 422 for a configuration the tenant never made.
            retain: $rerankTopN === 0
                ? (int) $bot->rerank_retain
                : min((int) $bot->rerank_retain, $rerankTopN),
            maxPerDocument: (int) config('kb.retrieval.max_per_document'),
            rewriteEnabled: (bool) config('kb.retrieval.rewrite_enabled'),
            reserveOutput: (int) config('kb.retrieval.reserve_output'),
            historyWindowTurns: (int) config('kb.retrieval.history_window_turns'),
            questionMaxChars: (int) config('kb.retrieval.question_max_chars'),
            // §12.7's grounding rule: a `strict` bot cites or refuses, while a `rag_first` one may
            // answer from general knowledge and therefore cannot be REQUIRED to produce a citation —
            // requiring one there turns every general answer into a refusal. `citations_enabled`
            // stays true either way: a `rag_first` answer that DID use a source must still say which.
            citationsEnabled: true,
            requireAtLeastOneCitation: $bot->answer_mode === BotAnswerMode::Strict,
        );
    }

    /**
     * @return array{dense_top_k: int, sparse_top_k: int, rerank_top_n: int, fusion_k: int, retain: int, max_per_document: int, rewrite_enabled: bool, reserve_output: int, history_window_turns: int, question_max_chars: int, citations_enabled: bool, require_at_least_one_citation: bool}
     */
    public function toArray(): array
    {
        return [
            'dense_top_k' => $this->denseTopK,
            'sparse_top_k' => $this->sparseTopK,
            'rerank_top_n' => $this->rerankTopN,
            'fusion_k' => $this->fusionK,
            'retain' => $this->retain,
            'max_per_document' => $this->maxPerDocument,
            'rewrite_enabled' => $this->rewriteEnabled,
            'reserve_output' => $this->reserveOutput,
            'history_window_turns' => $this->historyWindowTurns,
            'question_max_chars' => $this->questionMaxChars,
            'citations_enabled' => $this->citationsEnabled,
            'require_at_least_one_citation' => $this->requireAtLeastOneCitation,
        ];
    }
}

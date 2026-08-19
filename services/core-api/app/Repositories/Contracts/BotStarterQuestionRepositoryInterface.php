<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\BotStarterQuestion;
use App\Services\Bots\StarterQuestionEdit;
use Closure;

/**
 * One bot's starter questions, and the invariant that makes them orderable.
 *
 * ── THE INVARIANT: POSITIONS ARE 0..n-1, WITH NO GAPS AND NO DUPLICATES, AFTER EVERY WRITE ─────
 *
 * `bot_starter_questions_org_bot_position` is UNIQUE per bot and DELIBERATELY NOT DEFERRABLE. The
 * migration records the trade in full; the consequence for this layer is that no naive UPDATE can
 * reorder a list — "set this row to 2" collides with whichever row holds 2, as SQLSTATE 23505
 * rendered by the error envelope as a 500 for a request the operator has every right to make.
 *
 * So every mutating method here RE-SEQUENCES THE WHOLE LIST inside one transaction, in two passes:
 * every row is first shifted clear of the range it currently occupies, then written to its final
 * position. Two passes rather than one because a single pass would have new values overlapping old
 * ones and the unique index is checked per row, not per statement. `EloquentBotStarterQuestionRepository`
 * carries the arithmetic and the proof that the two ranges are disjoint.
 *
 * The invariant is why `sort_order` is not a client-settable column: an invariant a caller can name
 * is an invariant a caller can break. On the create path the position is the server's arithmetic
 * (append), and on the edit path the client's `sort_order` is an intent — "move this one to N" —
 * that this layer turns into a whole-list rewrite.
 *
 * ── EVERY METHOD TAKES `$organizationId` AND `$botId` FIRST, AND POSITIONALLY ──────────────────
 *
 * The explicit predicate is the MECHANISM (kb-tenancy-isolation) and
 * `#[ScopedBy(OrganizationScope::class)]` is the BACKSTOP; the two fail differently, and the
 * backstop reads the ambient `TenantContext`, which is exactly what is stale on the path the
 * explicit argument exists for. The bot predicate has no backstop at all — nothing in the model
 * layer knows which bot a request is about — so it exists only here.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ────────────────────────────
 *
 * All three `bot.starter_question.*` operations are `ON_FAILURE_ABORT`. §18.11 requires bot CONFIG
 * CHANGES audited, and a starter question is bot configuration: it is what a first-time visitor is
 * invited to ask. What those rows deliberately do NOT carry is the question TEXT — see
 * `App\Services\Audit\AuditLogger`, which refuses `welcome_message`, `placeholder_text` and
 * `description` from the `bot.*` rows on the same ground, that an append-only table an investigator
 * has to be able to read is the wrong place for unbounded tenant prose.
 */
interface BotStarterQuestionRepositoryInterface
{
    /**
     * Every starter question of ONE bot, in ONE organization, IN ORDER.
     *
     * Ordered by `sort_order` ascending, which the unique index yields directly with no sort node.
     * The order is the product decision the operator made; a caller that re-sorted would be
     * rendering a list nobody configured.
     *
     * @return list<BotStarterQuestion>
     */
    public function forBot(string $organizationId, string $botId): array;

    /** How many questions this bot already has, for the per-bot ceiling. */
    public function countForBot(string $organizationId, string $botId): int;

    /**
     * Append one question at the end of the list.
     *
     * THE POSITION IS COMPUTED UNDER THE BOT'S ROW LOCK, not read and then written. Two concurrent
     * appends that both read "the list has 3" would both write position 3 and the second would be a
     * 23505; serialising on the bot row is what makes the count they read the count they write
     * against.
     *
     * @param  Closure(BotStarterQuestion, int): void  $audit  invoked inside the transaction with
     *                                                         the new row and the resulting list
     *                                                         length
     */
    public function create(
        string $organizationId,
        string $botId,
        string $question,
        Closure $audit,
    ): BotStarterQuestion;

    /**
     * Edit one question's text, its position, or both — re-sequencing the list if it moved.
     *
     * @param  Closure(BotStarterQuestion, string, int): void  $audit  invoked inside the transaction
     *                                                                 with the updated row, a
     *                                                                 comma-joined list of the
     *                                                                 fields that CHANGED, and the
     *                                                                 list length
     * @return BotStarterQuestion|null null when no such question exists on this bot in this
     *                                 organization
     */
    public function update(
        string $organizationId,
        string $botId,
        string $questionId,
        StarterQuestionEdit $edit,
        Closure $audit,
    ): ?BotStarterQuestion;

    /**
     * Remove one question and CLOSE THE GAP it leaves.
     *
     * The compaction is not tidiness: `sort_order` is published to clients as a renderable index and
     * the resource says a gap is a defect rather than a state, so a delete that left 0,1,3 would
     * make that promise false everywhere at once.
     *
     * @param  Closure(BotStarterQuestion, int): void  $audit  invoked inside the transaction, BEFORE
     *                                                         the row is removed, with the resulting
     *                                                         list length
     * @return bool false when no such question exists on this bot in this organization
     */
    public function delete(
        string $organizationId,
        string $botId,
        string $questionId,
        Closure $audit,
    ): bool;
}

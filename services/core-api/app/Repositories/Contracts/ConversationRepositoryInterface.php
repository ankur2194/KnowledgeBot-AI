<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\FeedbackRating;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Services\Chat\NewConversation;
use App\Services\Chat\TurnRecord;
use App\Services\Conversations\ConversationFilter;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Every read and write anything in this application makes against the conversation graph.
 *
 * ── IT SERVES TWO SURFACES AND THAT IS WHY IT IS ONE INTERFACE ─────────────────────────────
 *
 * The public chat runtime (Phase 4) and the admin review surface (Phase 6a) read the same six
 * tables with DIFFERENT PREDICATE SETS: a visitor may read the thread their session owns, an
 * administrator may read every thread in their organization. What both need is identical and is the
 * dangerous part — the join UP to `conversations`, which is the only tenancy `messages`,
 * `citations`, `retrieval_traces` and `feedback` have.
 *
 * Two implementations would each write that join, and the second one would be written by somebody
 * reading the first, at which point the two are a copy rather than a shared rule. They live here so
 * that `scopedMessages()` is expressed ONCE and every method on both surfaces is a predicate added
 * to it. The methods are grouped and labelled by surface below; the runtime's carry a participant
 * predicate and the admin's carry none, and neither may be reached from the other's controller.
 *
 * ── EVERY METHOD IS SCOPED BY THE ORGANIZATION, AND THE RUNTIME'S BY THE SESSION AS WELL ────
 *
 * `messages`, `citations`, `retrieval_traces` and `feedback` have NO `organization_id` column — they
 * reach their tenant through `conversations`, which is the only row in the graph that carries one.
 * So the global scope cannot help below the conversation, and every method here joins UP to it and
 * predicates on the organization explicitly. That is not belt-and-braces; below `conversations` the
 * explicit predicate is the ONLY mechanism.
 *
 * The public surface adds a second scope on top: a visitor may read the conversation their session
 * OWNS and no other. An organization-scoped read alone would let one visitor read every other
 * visitor's transcript in the same tenant, which is a worse leak than a cross-tenant one because it
 * is the one an ordinary user can perform by changing a ULID in a URL.
 *
 * ── A DENIAL AND AN ABSENCE ARE THE SAME NULL ───────────────────────────────────────────────
 *
 * Every lookup returns null for "does not exist", "belongs to another organization" and "belongs to
 * another visitor" alike, because the caller renders all three as the same 404 with the same body. A
 * method that distinguished them would hand the controller a distinction it must not use.
 */
interface ConversationRepositoryInterface
{
    /**
     * Open a conversation for one bot in one organization.
     *
     * The organization and bot are ARGUMENTS and are never members of `NewConversation`: the DTO
     * carries what the client may influence, and the two ownership columns are what it may not.
     * `conversations_bot_same_org` is the composite foreign key that makes a mismatch a database
     * error rather than a conversation attributed to another tenant's bot.
     */
    public function create(string $organizationId, string $botId, NewConversation $input): Conversation;

    /**
     * One conversation, scoped to the organization AND to the session or user that owns it.
     *
     * @param  string|null  $anonymousSessionId  the chat session's derived id, for a visitor
     * @param  string|null  $userId  for an authenticated participant
     */
    public function findForParticipant(
        string $organizationId,
        string $conversationId,
        ?string $anonymousSessionId,
        ?string $userId,
    ): ?Conversation;

    /**
     * The last `$turns` complete exchanges, oldest first, as rendered strings for stage 3's window.
     *
     * ── RENDERED HERE RATHER THAN ON THE FAR SIDE, AND BOUNDED IN TWO DIMENSIONS ────────────
     *
     * `ChatExecuteRequest.history` is `tuple[str, ...]` with `max_length=200`, so the wire takes
     * strings and caps the COUNT. It does not cap the LENGTH, and a conversation whose earlier turns
     * were long answers would push the packer's budget past the model's window before a single
     * retrieved passage was added — which truncates the ANSWER, not the history, and reads as a bad
     * model rather than as a budget error. Each entry is therefore bounded here too.
     *
     * Only SETTLED messages are included: a `pending` or `streaming` row is a turn in flight, and a
     * turn that failed contributed nothing the model should be reminded of.
     *
     * @return list<string>
     */
    public function historyWindow(string $organizationId, string $conversationId, int $turns): array;

    /**
     * Open one turn: the visitor's message and the assistant row it will be answered into.
     *
     * ── BOTH ROWS, ONE TRANSACTION, AND THE SECOND ONE IS WHY ──────────────────────────────
     *
     * The assistant row must exist BEFORE the internal call, because `message.start` and
     * `message.complete` both carry its id and the finalizer keys its single write on it. Writing it
     * after the stream opened would mean a client abort in the first 200 ms leaves a `provider_calls`
     * row referencing a message that was never created.
     *
     * ── IT IS IDEMPOTENT ON `client_message_id`, AND THE RETURN SAYS WHICH PATH RAN ─────────
     *
     * A second submit of the same client-minted id finds the existing user row and returns the
     * assistant row already attached to it, with `opened = false`. The caller then relays the
     * PERSISTED state instead of running a second generation — same question, same retrieval, a
     * second provider bill — which is the entire reason the id is client-minted and stable across
     * re-renders.
     *
     * `messages_conversation_client_message_unique` is what makes that a race-free claim rather than
     * a check-then-write: two simultaneous submits both attempt the insert and one loses on the
     * index, and the loser re-reads instead of erroring.
     *
     * @return array{user: Message, assistant: Message, opened: bool}
     */
    public function openTurn(
        string $organizationId,
        string $conversationId,
        string $clientMessageId,
        string $content,
    ): array;

    /**
     * Commit one finished turn: the message row, its citations, its trace and its provider calls.
     *
     * ── ONE TRANSACTION, ALWAYS, AND IDEMPOTENT ON THE MESSAGE ID ──────────────────────────
     *
     * The four writes describe one event and must land together or not at all — `TurnRecord`'s
     * docblock carries the argument per table. Idempotence is on `messages.id`: the abort path and
     * the completion path can both reach the finalizer's `finally`, and a turn whose message is
     * already settled writes nothing further rather than duplicating its citations.
     *
     * @param  list<string>  $allowedVersionIds  the turn's own retrieval scope, used to bound the
     *                                           chunk hydration behind its citations
     * @return int how many `provider_calls` rows were written. The caller meters exactly those into
     *             `usage_events`, so a re-entry that wrote none also meters none.
     */
    public function finalizeTurn(
        string $organizationId,
        string $conversationId,
        string $botId,
        TurnRecord $record,
        array $allowedVersionIds,
    ): int;

    /**
     * Hydrate the columns a `citations` row needs but the wire does not carry.
     *
     * ── SCOPED TO THE ORGANIZATION *AND* TO THE TURN'S OWN VERSION SET ─────────────────────
     *
     * The chunk ids arrive over the wire, so a read keyed on them alone would have no tenant filter
     * of its own — and the one thing a citation must never need is a lookup that trusts an id it was
     * handed. The version-set predicate is the second half and is not redundant: it means a chunk
     * from a RETIRED version of this same tenant's source cannot be cited either, which is
     * non-negotiable 5 applied to the transcript rather than only to retrieval.
     *
     * An id that resolves to nothing is absent from the result and the caller DROPS the citation.
     *
     * @param  list<string>  $chunkIds
     * @param  list<string>  $allowedVersionIds
     * @return array<string, \App\Models\Chunk> keyed by chunk id; a subset of the input
     */
    public function hydrateChunks(string $organizationId, array $chunkIds, array $allowedVersionIds): array;

    /**
     * Move `conversations.last_activity_at` to now.
     *
     * SEPARATE FROM THE TURN WRITES ON PURPOSE. It is the column the retention sweeper and the admin
     * conversation list both order by, and it must move when a visitor SENDS — not only when a turn
     * SETTLES — or an abandoned mid-answer conversation ages as though nobody had touched it since
     * the previous turn. `conversations_activity_after_start` bounds it below the start instant, so
     * this can never write a negative duration into a dashboard.
     */
    public function touchActivity(string $organizationId, string $conversationId): void;

    /**
     * The transcript a visitor may read: settled messages of ONE conversation, oldest first.
     *
     * @return list<Message>
     */
    public function transcript(string $organizationId, string $conversationId, int $limit): array;

    /**
     * One message, scoped to the organization and to the conversation its participant owns.
     */
    public function findMessageForParticipant(
        string $organizationId,
        string $messageId,
        ?string $anonymousSessionId,
        ?string $userId,
    ): ?Message;

    /**
     * The citations attached to one message, in label order.
     *
     * @return list<\App\Models\Citation>
     */
    public function citationsFor(string $organizationId, string $messageId): array;

    /**
     * Record or replace a verdict on one assistant message.
     *
     * ── ONE VERDICT PER SUBMITTER PER MESSAGE, ENFORCED BY TWO PARTIAL UNIQUE INDEXES ───────
     *
     * `feedback_message_session_unique` and `feedback_message_user_unique`. A visitor changing their
     * mind UPDATES rather than appending, so a thumbs-down that became a thumbs-up is one row and
     * the analytics count one opinion. Appending would let one visitor move the satisfaction metric
     * by clicking repeatedly.
     */
    public function recordFeedback(
        string $organizationId,
        string $messageId,
        FeedbackRating $rating,
        ?string $comment,
        ?string $anonymousSessionId,
        ?string $userId,
    ): Feedback;

    // ═══ THE ADMIN REVIEW SURFACE (Phase 6a) ═══════════════════════════════════════════════════
    //
    // NO PARTICIPANT PREDICATE, ON PURPOSE, AND THAT IS THE ONE DIFFERENCE FROM EVERYTHING ABOVE.
    // These are read by an authenticated member of the organization holding `conversations.view`,
    // which `ConversationPolicy` establishes against THE ROW'S organization before any of them is
    // called. Calling one of these from the runtime would let any visitor read any thread in the
    // tenant; calling `findForParticipant()` from the admin surface would make an administrator
    // able to read only the threads they personally held. Neither is a fallback for the other.

    /**
     * One page of this organization's conversations.
     *
     * `conversations` is the one model in this graph that carries `#[ScopedBy(OrganizationScope::
     * class)]`, so there are two layers here — and the explicit `organization_id` argument is still
     * the mechanism. The global scope reads the ambient `TenantContext`, which is exactly what is
     * stale on a pooled worker, so the two agree on the request path and only the explicit one is
     * answering the question on any other.
     *
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function paginateForAdmin(
        string $organizationId,
        ConversationFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator;

    /**
     * Every message of one conversation, oldest first, INCLUDING THE UNSETTLED ONES.
     *
     * ── THE DIFFERENCE FROM `transcript()` IS ONE PREDICATE AND IT IS DELIBERATE ────────────
     *
     * The runtime's transcript is settled-only, because a `pending` row has no content and the
     * endpoint is a poll rather than a stream — it would render as a blank bubble that never fills.
     * An administrator needs the opposite: "which turns are still open" is a real operational
     * question, `messages_unsettled` is a partial index that exists to answer it, and a turn that
     * died mid-stream is precisely what somebody opens this screen to look at.
     *
     * @param  int  $limit  the read is bounded; the caller publishes whether the bound bit
     * @return list<Message>
     */
    public function adminTranscript(string $organizationId, string $conversationId, int $limit): array;

    /**
     * Every citation attached to one conversation's messages, grouped by message id, in label order.
     *
     * ONE STATEMENT FOR THE WHOLE PAGE AND NOT ONE PER MESSAGE. `citationsFor()` above is the
     * per-message form the runtime needs, and calling it in a loop over a two-hundred-message
     * transcript is two hundred round trips for a screen. The tenancy is identical either way: the
     * join up to `conversations` carrying `organization_id`, which is the only one this table has.
     *
     * ── `$messageIds` BOUNDS THE READ AND IS NOT THE SCOPE ──────────────────────────────────
     *
     * It is the page of messages the caller is actually rendering, so a thread far longer than the
     * transcript cap does not drag its whole citation history across the wire to be discarded. The
     * organization and conversation predicates are BOTH still applied — the same rule
     * `hydrateChunks()` states about ids that arrive from outside: an id list narrows a scoped read
     * and never replaces the scope.
     *
     * @param  list<string>  $messageIds
     * @return array<string, list<\App\Models\Citation>> keyed by `message_id`; a message with no
     *                                                   citations is ABSENT rather than mapped to []
     */
    public function citationsForConversation(string $organizationId, string $conversationId, array $messageIds): array;

    /**
     * Each message's retrieval trace, keyed by message id. At most one per message —
     * `retrieval_traces_message_unique`.
     *
     * READABLE ON THIS SURFACE BY DECISION, NOT BY OVERSIGHT. ADR-074 took `retrieval_traces` off
     * the data plane's write allow-list precisely because this endpoint and the D5 playground read
     * it, which makes the public API a reader of the table and therefore puts its writer on this
     * side of the seam. §6.5 grants an Analyst "Review source citations and retrieval traces" in as
     * many words, which is why the projection carries the whole row rather than a summary.
     *
     * TWO HOPS TO AN ORGANIZATION: `retrieval_traces -> messages -> conversations`. Both links are
     * NOT NULL and ON DELETE CASCADE, so the chain cannot be broken by a row — but it can be broken
     * by a query that shortens it, which is the only way this read leaks.
     *
     * @param  list<string>  $messageIds  bounds the read; see `citationsForConversation()`
     * @return array<string, \App\Models\RetrievalTrace> keyed by `message_id`
     */
    public function tracesForConversation(string $organizationId, string $conversationId, array $messageIds): array;

    /**
     * Every verdict left on one conversation's messages, grouped by message id.
     *
     * The same two-hop chain as the traces. At most one row per submitter per message, by the two
     * partial unique indexes — so a list rather than a value, because a thread can carry a
     * visitor's thumb and a reviewer's separately.
     *
     * @param  list<string>  $messageIds  bounds the read; see `citationsForConversation()`
     * @return array<string, list<Feedback>> keyed by `message_id`
     */
    public function feedbackForConversation(string $organizationId, string $conversationId, array $messageIds): array;

    /**
     * Every provider attempt behind one conversation's turns, grouped by message id, oldest first.
     *
     * ── IT DOES NOT JOIN `conversations`, AND THAT IS THE POINT OF THE METHOD ───────────────
     *
     * `provider_calls.conversation_id` and `.message_id` are BOTH `ON DELETE SET NULL` — retention
     * removes content and not cost — so a joined form silently drops every call whose thread has
     * been swept, and a cost figure that shrinks when retention runs is a rewrite of last quarter's
     * spend. The table carries its own `organization_id` and its own `#[ScopedBy]`, and the
     * predicate here is on that column directly.
     *
     * A call whose `message_id` is NULL is not attributable to a turn and is therefore ABSENT from
     * this result. Inside a live conversation that cannot happen — the assistant row is written
     * before the internal call opens, and `finalizeTurn()` always names it — so the case this
     * describes is a row whose message has already been deleted, which by then has no transcript to
     * appear in. The organization-wide total lives on the analytics surface, which reads this table
     * without any conversation predicate at all.
     *
     * @param  list<string>  $messageIds  bounds the read. It is applied here IN ADDITION to the
     *                                    organization and conversation predicates, never instead of
     *                                    them
     * @return array<string, list<\App\Models\ProviderCall>> keyed by `message_id`
     */
    public function providerCallsForConversation(string $organizationId, string $conversationId, array $messageIds): array;
}

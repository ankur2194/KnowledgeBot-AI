<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\ConversationStatus;
use App\Enums\FeedbackRating;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Chunk;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\RetrievalTrace;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Chat\NewConversation;
use App\Services\Chat\TurnRecord;
use App\Services\Conversations\ConversationFilter;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EloquentConversationRepository implements ConversationRepositoryInterface
{
    /**
     * The longest single history entry shipped in the prompt window, in characters.
     *
     * See the interface: the wire caps the COUNT of entries and not their length, so an unbounded
     * one pushes the packer's budget past the model's window before a retrieved passage is added and
     * truncates the ANSWER. Four thousand characters is roughly a long answer and is the same order
     * as `question_max_chars`.
     */
    private const MAX_HISTORY_ENTRY_CHARS = 4_000;

    public function create(string $organizationId, string $botId, NewConversation $input): Conversation
    {
        $conversation = new Conversation;

        // The two ownership columns are assigned HERE, from arguments, and neither is fillable.
        // `NewConversation` carries no member that could express either, so this is the only place
        // they can be set and a client cannot influence them even by over-posting.
        $conversation->organization_id = $organizationId;
        $conversation->bot_id = $botId;
        $conversation->channel = $input->channel;
        $conversation->user_id = $input->userId;
        $conversation->anonymous_session_id = $input->anonymousSessionId;
        $conversation->locale = $input->locale;
        $conversation->consent_required = $input->consentRequired;
        $conversation->consent_text_snapshot = $input->consentText;

        // ── THE TWO COLUMNS THAT HAVE DATABASE DEFAULTS AND ARE STILL WRITTEN HERE ───────────
        //
        // `status` DEFAULTs to `'active'` and `last_activity_at` to `now()`, so the ROW is correct
        // either way — and the OBJECT this method returns is not: Eloquent does not re-read a row
        // after an insert, so an unset attribute is `null` on the instance the API Resource is about
        // to render. `$conversation->status->value` on a null is a 500 on a request that succeeded,
        // which is the worst shape of this failure: the write happened and the caller was told it did
        // not.
        //
        // Stating them also makes the INITIAL STATE a decision of this method rather than of a
        // column default nobody reads — and `ConversationStatus::transitionTable()` is what governs
        // every move afterwards.
        $conversation->status = ConversationStatus::Active;

        // ── ONE CLOCK READ FOR ALL THREE TIMESTAMPS, AND THE ROW IS INVALID WITHOUT IT ──────
        //
        // `Conversation::CREATED_AT` is `started_at`, so Eloquent stamps that column itself from
        // `freshTimestamp()` inside `save()` — a SECOND reading of the clock, taken after this
        // method has finished assigning. `last_activity_at` used to be read here and `started_at`
        // there, and the table CHECKs `last_activity_at >= started_at`.
        //
        // Both columns serialize at SECOND precision, so the two reads do not have to be far apart
        // to disagree — they only have to fall either side of a second boundary. Read at
        // 06:34:05.999 and stamped at 06:34:06.001, the row is `last_activity_at` one second BEFORE
        // `started_at` and PostgreSQL rejects it with SQLSTATE 23514. The caller gets a 500 from
        // `POST /rt/v1/conversations` and nothing is wrong with the request; the next attempt
        // usually works, which is what makes it a bug report nobody can reproduce. Observed once in
        // a Feature run on 2026-08-31 and not in the same file run alone.
        //
        // Assigning `started_at` explicitly is what stops Eloquent taking its own reading:
        // `updateTimestamps()` skips `CREATED_AT` when the attribute is already dirty. `updated_at`
        // is deliberately left to the framework — no constraint involves it, and it is not the same
        // fact (see the model).
        $now = CarbonImmutable::now('UTC');

        $conversation->started_at = $now;
        $conversation->last_activity_at = $now;

        // A NUMBER OF DAYS ON THE BOT RESOLVES INTO A TIMESTAMP HERE, at creation, so the retention
        // sweeper reads one column and a later edit to the bot cannot retroactively shorten or
        // extend a conversation somebody has already had. NULL means "the organization's own policy
        // decides", which is a real state and is what the column's own comment says.
        // FROM `$now` TOO, for the same reason and against the sibling constraint
        // `retention_expires_at > started_at`. A third reading buys nothing and can only disagree.
        $conversation->retention_expires_at = $input->retentionDays === null
            ? null
            : $now->addDays($input->retentionDays);

        $conversation->save();

        return $conversation;
    }

    public function findForParticipant(
        string $organizationId,
        string $conversationId,
        ?string $anonymousSessionId,
        ?string $userId,
    ): ?Conversation {
        $query = Conversation::query()
            ->where('organization_id', '=', $organizationId)
            ->whereKey($conversationId);

        // THE PARTICIPANT PREDICATE IS NOT OPTIONAL AND HAS NO "ANY" BRANCH. With neither argument
        // supplied this returns null rather than the row: a caller that resolved no participant has
        // no business reading a transcript, and an unfiltered fall-through here would make every
        // visitor in the organization able to read every other visitor's conversation by changing a
        // ULID — which is the leak an ordinary user can perform, and therefore the worse one.
        if ($anonymousSessionId !== null) {
            $query->where('anonymous_session_id', '=', $anonymousSessionId);
        } elseif ($userId !== null) {
            $query->where('user_id', '=', $userId);
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * @return list<string>
     */
    public function historyWindow(string $organizationId, string $conversationId, int $turns): array
    {
        if ($turns <= 0) {
            return [];
        }

        // ONE TURN IS A PAIR, so the row budget is twice the turn budget. Taking `$turns` ROWS would
        // send half the window and would silently drop the visitor's own last question, which reads
        // as a model that forgot rather than as a truncated history.
        $rows = $this->scopedMessages($organizationId)
            ->where('messages.conversation_id', '=', $conversationId)
            ->whereIn('messages.status', [MessageStatus::Complete->value])
            ->whereNotNull('messages.content')
            // NEWEST FIRST here and reversed below: a `limit` on an ascending order takes the OLDEST
            // rows, which is the opposite of a window.
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit($turns * 2)
            ->get(['messages.role', 'messages.content']);

        $window = [];

        foreach ($rows->reverse() as $row) {
            $window[] = $row->role->value.': '
                .mb_substr((string) $row->content, 0, self::MAX_HISTORY_ENTRY_CHARS);
        }

        return $window;
    }

    /**
     * @return array{user: Message, assistant: Message, opened: bool}
     */
    public function openTurn(
        string $organizationId,
        string $conversationId,
        string $clientMessageId,
        string $content,
    ): array {
        // THE EXISTING-TURN CHECK RUNS FIRST AND IS NOT THE MECHANISM. It saves a doomed insert on
        // the common re-submit; the unique index is what makes the decision race-free, and the catch
        // below is the path two simultaneous submits actually take.
        $existing = $this->existingTurn($organizationId, $conversationId, $clientMessageId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($conversationId, $clientMessageId, $content): array {
                // NO EXPLICIT ID. `HasUlids` mints it — `strtolower((string) Str::ulid())` — and
                // that lower case is not cosmetic: `existingTurn()` finds the assistant row with
                // `messages.id > $user->id` under `COLLATE "C"`, where every uppercase byte sorts
                // BEFORE every lowercase one. One row minted the other way makes that comparison
                // answer about the alphabet rather than about time.
                $user = new Message;
                $user->conversation_id = $conversationId;
                $user->role = MessageRole::User;
                $user->content = $content;
                // A user turn is complete the moment it is written: there is nothing to stream.
                $user->status = MessageStatus::Complete;
                $user->client_message_id = $clientMessageId;
                $user->save();

                $assistant = new Message;
                $assistant->conversation_id = $conversationId;
                $assistant->role = MessageRole::Assistant;
                // NULL CONTENT AND `pending`, which is a different fact from an empty string:
                // `messages_content_not_blank` refuses `''` outright, and
                // `messages_content_present_when_complete` is what stops this row ever settling as
                // `complete` with nothing in it.
                $assistant->content = null;
                $assistant->status = MessageStatus::Pending;
                // NO `client_message_id` ON THE ASSISTANT ROW.
                // `messages_client_message_id_only_on_user` refuses it, and it would occupy the
                // unique-index slot the client's own retry needs.
                $assistant->save();

                return ['user' => $user, 'assistant' => $assistant, 'opened' => true];
            });
        } catch (QueryException $e) {
            // THE LOSER OF THE RACE RE-READS. Two simultaneous submits of one client message both
            // attempt the insert; the index refuses the second, and the correct answer is the turn
            // the winner opened rather than an error the visitor did nothing to deserve.
            $existing = $this->existingTurn($organizationId, $conversationId, $clientMessageId);

            if ($existing !== null) {
                return $existing;
            }

            // Not the unique violation: a real failure, re-thrown unwrapped so the envelope's own
            // handling and the vault scrubber both still apply.
            throw $e;
        }
    }

    /**
     * @param  list<string>  $allowedVersionIds
     */
    public function finalizeTurn(
        string $organizationId,
        string $conversationId,
        string $botId,
        TurnRecord $record,
        array $allowedVersionIds,
    ): int {
        return DB::transaction(function () use ($organizationId, $conversationId, $botId, $record, $allowedVersionIds): int {
            $message = $this->scopedMessages($organizationId)
                ->where('messages.conversation_id', '=', $conversationId)
                ->where('messages.id', '=', $record->messageId)
                // ROW LOCK, and it is what makes the idempotence a claim rather than a hope: the
                // abort path and the completion path can both reach the finalizer, and without the
                // lock both read `pending`, both proceed, and the citations are written twice —
                // where the second write fails on `citations_message_label_unique` and takes the
                // whole transaction with it.
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return 0;   // another tenant's message, or a conversation that has since gone
            }

            if ($message->status->isSettled()) {
                // ALREADY FINALIZED. Nothing further is written and nothing is metered — see the
                // interface's return contract.
                return 0;
            }

            $message->content = $record->content === '' ? null : $record->content;
            $message->status = $record->status;
            $message->save();

            $this->writeCitations($organizationId, $record, $allowedVersionIds);
            $this->writeTrace($record);

            return $this->writeProviderCalls($organizationId, $conversationId, $botId, $record);
        });
    }

    public function touchActivity(string $organizationId, string $conversationId): void
    {
        Conversation::query()
            ->where('organization_id', '=', $organizationId)
            ->whereKey($conversationId)
            // `update()` AND NOT `->first()->save()`: one statement, no read, and no chance of
            // clobbering a column another request changed between the read and the write. The
            // updated_at column is set explicitly because a mass update bypasses Eloquent's own
            // timestamping.
            ->update([
                'last_activity_at' => CarbonImmutable::now('UTC'),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);
    }

    /**
     * @return list<Message>
     */
    public function transcript(string $organizationId, string $conversationId, int $limit): array
    {
        /** @var list<Message> $rows */
        $rows = $this->scopedMessages($organizationId)
            ->where('messages.conversation_id', '=', $conversationId)
            // SETTLED ONLY. A `pending` row is a turn in flight — it has no content and would render
            // as a blank bubble that never fills, because the transcript endpoint is a poll and not
            // a stream.
            ->whereIn('messages.status', [
                MessageStatus::Complete->value,
                MessageStatus::Failed->value,
                MessageStatus::Cancelled->value,
            ])
            ->orderBy('messages.created_at')
            ->orderBy('messages.id')
            ->limit($limit)
            ->get()
            ->all();

        return $rows;
    }

    public function findMessageForParticipant(
        string $organizationId,
        string $messageId,
        ?string $anonymousSessionId,
        ?string $userId,
    ): ?Message {
        $query = $this->scopedMessages($organizationId)->where('messages.id', '=', $messageId);

        // The same rule as `findForParticipant()`: no participant, no row. See that method.
        if ($anonymousSessionId !== null) {
            $query->where('conversations.anonymous_session_id', '=', $anonymousSessionId);
        } elseif ($userId !== null) {
            $query->where('conversations.user_id', '=', $userId);
        } else {
            return null;
        }

        /** @var Message|null $row */
        $row = $query->first();

        return $row;
    }

    /**
     * @return list<Citation>
     */
    public function citationsFor(string $organizationId, string $messageId): array
    {
        /** @var list<Citation> $rows */
        $rows = Citation::query()
            ->join('messages', 'messages.id', '=', 'citations.message_id')
            ->join('conversations', function ($join) use ($organizationId): void {
                $join->on('conversations.id', '=', 'messages.conversation_id')
                    // THE ONLY TENANT PREDICATE AVAILABLE BELOW `conversations`. `citations` has no
                    // `organization_id` column, so the join to the one row that carries one IS the
                    // scope — there is no global scope to fall back on here.
                    ->where('conversations.organization_id', '=', $organizationId);
            })
            ->where('citations.message_id', '=', $messageId)
            ->orderBy('citations.label')
            ->select('citations.*')
            ->get()
            ->all();

        return $rows;
    }

    public function recordFeedback(
        string $organizationId,
        string $messageId,
        FeedbackRating $rating,
        ?string $comment,
        ?string $anonymousSessionId,
        ?string $userId,
    ): Feedback {
        return DB::transaction(function () use ($organizationId, $messageId, $rating, $comment, $anonymousSessionId, $userId): Feedback {
            $existing = Feedback::query()
                ->join('messages', 'messages.id', '=', 'feedback.message_id')
                ->join('conversations', function ($join) use ($organizationId): void {
                    $join->on('conversations.id', '=', 'messages.conversation_id')
                        ->where('conversations.organization_id', '=', $organizationId);
                })
                ->where('feedback.message_id', '=', $messageId)
                ->when(
                    $anonymousSessionId !== null,
                    fn ($query) => $query->where('feedback.submitted_by_session', '=', $anonymousSessionId),
                    fn ($query) => $query->where('feedback.submitted_by_user_id', '=', $userId),
                )
                ->select('feedback.*')
                ->lockForUpdate()
                ->first();

            $feedback = $existing ?? new Feedback;

            if ($existing === null) {
                $feedback->message_id = $messageId;
                // EXACTLY ONE SUBMITTER. `feedback_submitter_exclusive` refuses both and neither, and
                // the caller has already established which one this is — a session that also has a
                // user id is the state that double-counts.
                $feedback->submitted_by_user_id = $anonymousSessionId === null ? $userId : null;
                $feedback->submitted_by_session = $anonymousSessionId;
            }

            $feedback->rating = $rating;
            // NULL AND NOT '': `feedback_comment_not_blank` refuses the blank spelling, and an empty
            // comment box must clear the previous comment rather than store a second spelling of
            // "none".
            $feedback->comment = $comment === null || trim($comment) === '' ? null : $comment;
            $feedback->save();

            return $feedback;
        });
    }

    // ═══ THE ADMIN REVIEW SURFACE (Phase 6a) ═══════════════════════════════════════════════════
    //
    // Every method below reuses `scopedMessages()` — the same join the runtime uses — and adds no
    // participant predicate. See the interface for why that difference is the whole distinction
    // between the two surfaces and why neither may call the other's methods.

    /**
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function paginateForAdmin(
        string $organizationId,
        ConversationFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator {
        $rows = Conversation::query()
            // THE EXPLICIT PREDICATE, and it is not redundant with `#[ScopedBy]`. The global scope
            // reads the ambient TenantContext; this reads the organization the route bound and the
            // policy authorized. On the request path they agree. On a pooled worker they do not, and
            // this is the one that is answering the question.
            ->where('conversations.organization_id', '=', $organizationId)
            ->when(
                $filter->botId !== null,
                fn (Builder $b): Builder => $b->where('conversations.bot_id', '=', $filter->botId),
            )
            ->when(
                $filter->status !== null,
                fn (Builder $b): Builder => $b->where('conversations.status', '=', $filter->status?->value),
            )
            ->when(
                $filter->channel !== null,
                fn (Builder $b): Builder => $b->where('conversations.channel', '=', $filter->channel?->value),
            )
            ->when(
                $filter->userId !== null,
                fn (Builder $b): Builder => $b->where('conversations.user_id', '=', $filter->userId),
            )
            ->when(
                $filter->sessionId !== null,
                fn (Builder $b): Builder => $b->where('conversations.anonymous_session_id', '=', $filter->sessionId),
            )
            // BOUND AS PRE-RENDERED STRINGS, NEVER AS CARBON OBJECTS. Laravel's PostgreSQL grammar
            // formats a DateTimeInterface without microseconds, so a half-open upper bound would
            // silently exclude everything that happened in the current second — see SqlTimestamp,
            // where the measurement behind that sentence is recorded.
            ->when(
                $filter->fromBound !== null,
                fn (Builder $b): Builder => $b->where('conversations.started_at', '>=', $filter->fromBound),
            )
            ->when(
                $filter->untilBound !== null,
                fn (Builder $b): Builder => $b->where('conversations.started_at', '<', $filter->untilBound),
            )
            ->orderBy('conversations.'.$query->sort, $query->direction->value)
            // THE TIE-BREAK IS NOT OPTIONAL. Neither sortable column is unique within an
            // organization — a burst of widget sessions can share a `started_at` to the microsecond
            // — so without a total order PostgreSQL may legally return a row on page 2 that it
            // already returned on page 1, and the duplicate is invisible until somebody counts.
            //
            // IT FOLLOWS THE REQUESTED DIRECTION, for the reason `EloquentAuditLogRepository`
            // records: `id` is a ULID and therefore carries the same ordering as either sortable
            // column, so a fixed ascending tie-break under a descending sort returns the rows that
            // share an instant oldest-first inside a newest-first page.
            ->orderBy('conversations.id', $query->direction->value)
            ->paginate(perPage: $query->perPage, page: $query->page);

        /** @var LengthAwarePaginator<int, Conversation> $rows */
        return $rows;
    }

    /**
     * @return list<Message>
     */
    public function adminTranscript(string $organizationId, string $conversationId, int $limit): array
    {
        /** @var list<Message> $rows */
        $rows = $this->scopedMessages($organizationId)
            ->where('messages.conversation_id', '=', $conversationId)
            // NO STATUS PREDICATE. `transcript()` above filters to the settled states because a
            // visitor polling for their own answer would otherwise see a blank bubble; an
            // administrator asking "which turns are still open" needs exactly those rows, and
            // `messages_unsettled` is the partial index that exists for the question.
            ->orderBy('messages.created_at')
            ->orderBy('messages.id')
            ->limit($limit)
            ->get()
            ->all();

        return $rows;
    }

    /**
     * @return array<string, list<Citation>>
     */
    public function citationsForConversation(string $organizationId, string $conversationId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = Citation::query()
            ->join('messages', 'messages.id', '=', 'citations.message_id')
            ->join('conversations', function ($join) use ($organizationId): void {
                $join->on('conversations.id', '=', 'messages.conversation_id')
                    // THE ONLY TENANT PREDICATE AVAILABLE BELOW `conversations`, on both hops.
                    ->where('conversations.organization_id', '=', $organizationId);
            })
            ->where('messages.conversation_id', '=', $conversationId)
            // THE ID LIST NARROWS A SCOPED READ AND NEVER REPLACES THE SCOPE. The organization
            // predicate is on the join above and the conversation predicate is on the line above
            // this one; both stay whatever this list contains.
            ->whereIn('citations.message_id', array_values(array_unique($messageIds)))
            ->orderBy('citations.message_id')
            ->orderBy('citations.label')
            ->select('citations.*')
            ->get();

        return $this->groupByMessage($rows);
    }

    /**
     * @return array<string, RetrievalTrace>
     */
    public function tracesForConversation(string $organizationId, string $conversationId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = RetrievalTrace::query()
            ->join('messages', 'messages.id', '=', 'retrieval_traces.message_id')
            ->join('conversations', function ($join) use ($organizationId): void {
                $join->on('conversations.id', '=', 'messages.conversation_id')
                    ->where('conversations.organization_id', '=', $organizationId);
            })
            ->where('messages.conversation_id', '=', $conversationId)
            ->whereIn('retrieval_traces.message_id', array_values(array_unique($messageIds)))
            ->select('retrieval_traces.*')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            // ONE PER MESSAGE — `retrieval_traces_message_unique`. Keyed rather than appended, so a
            // second row for one message would be a loud overwrite here and a constraint violation
            // at the writer, rather than a silently duplicated panel.
            $out[(string) $row->message_id] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, list<Feedback>>
     */
    public function feedbackForConversation(string $organizationId, string $conversationId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = Feedback::query()
            ->join('messages', 'messages.id', '=', 'feedback.message_id')
            ->join('conversations', function ($join) use ($organizationId): void {
                $join->on('conversations.id', '=', 'messages.conversation_id')
                    ->where('conversations.organization_id', '=', $organizationId);
            })
            ->where('messages.conversation_id', '=', $conversationId)
            ->whereIn('feedback.message_id', array_values(array_unique($messageIds)))
            ->orderBy('feedback.message_id')
            ->orderBy('feedback.created_at')
            ->select('feedback.*')
            ->get();

        return $this->groupByMessage($rows);
    }

    /**
     * @return array<string, list<ProviderCall>>
     */
    public function providerCallsForConversation(string $organizationId, string $conversationId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = ProviderCall::query()
            // NO JOIN TO `conversations`, AND THAT IS THE POINT. Both link columns are ON DELETE SET
            // NULL, so a joined form drops every call whose thread retention has swept — a cost
            // figure that shrinks when retention runs. This table holds its own `organization_id`
            // and this is a predicate on that column.
            ->where('provider_calls.organization_id', '=', $organizationId)
            ->where('provider_calls.conversation_id', '=', $conversationId)
            // A call with no `message_id` is not attributable to a turn and has no place in a
            // transcript. Inside a live conversation the column is always set — the assistant row is
            // written before the internal call opens — so this excludes rows whose message has
            // already been deleted, which by then have no turn to appear under.
            ->whereNotNull('provider_calls.message_id')
            // IN ADDITION TO the two predicates above, never instead of them.
            ->whereIn('provider_calls.message_id', array_values(array_unique($messageIds)))
            ->orderBy('provider_calls.message_id')
            ->orderBy('provider_calls.created_at')
            ->orderBy('provider_calls.id')
            ->get();

        return $this->groupByMessage($rows);
    }

    /**
     * Group a flat result set by its `message_id`, preserving the order the query returned.
     *
     * A MESSAGE WITH NO ROWS IS ABSENT rather than mapped to an empty list, and the caller supplies
     * `[]`. The alternative — pre-seeding every message id — would make this helper need the message
     * list, which is a second source of truth about which messages exist.
     *
     * @template TModel of Model
     *
     * @param  \Illuminate\Support\Collection<int, TModel>  $rows
     * @return array<string, list<TModel>>
     */
    private function groupByMessage(\Illuminate\Support\Collection $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $key = (string) $row->getAttribute('message_id');
            $out[$key][] = $row;
        }

        return $out;
    }

    /**
     * The base query for every read below `conversations`: messages joined UP to their tenant.
     *
     * `messages` has no `organization_id`, so `#[ScopedBy(OrganizationScope::class)]` is not on that
     * model and there is no backstop here — this join IS the tenant filter. Every method that
     * touches a message goes through it for that reason, rather than each one writing its own.
     */
    /**
     * @return Builder<Message>
     */
    private function scopedMessages(string $organizationId): Builder
    {
        return Message::query()
            ->join('conversations', function ($join) use ($organizationId): void {
                $join->on('conversations.id', '=', 'messages.conversation_id')
                    ->where('conversations.organization_id', '=', $organizationId);
            })
            ->select('messages.*');
    }

    /**
     * @return array{user: Message, assistant: Message, opened: bool}|null
     */
    private function existingTurn(string $organizationId, string $conversationId, string $clientMessageId): ?array
    {
        $user = $this->scopedMessages($organizationId)
            ->where('messages.conversation_id', '=', $conversationId)
            ->where('messages.client_message_id', '=', $clientMessageId)
            ->first();

        if ($user === null) {
            return null;
        }

        // THE ASSISTANT ROW IS THE NEXT ONE IN THE CONVERSATION, found by id order rather than by a
        // foreign key, because `messages` has no "answers" column. ULIDs are time-ordered, so "the
        // first assistant row after this user row" is exactly the turn it opened — and `parent_message_id`
        // is deliberately NOT reused for this: that column means "this is a RETRY of that turn", and
        // overloading it would make a resend indistinguishable from a retry in every later read.
        $assistant = $this->scopedMessages($organizationId)
            ->where('messages.conversation_id', '=', $conversationId)
            ->where('messages.role', '=', MessageRole::Assistant->value)
            ->where('messages.id', '>', $user->id)
            ->orderBy('messages.id')
            ->first();

        if ($assistant === null) {
            return null;   // a user row with no answer row: treat it as un-opened and let the insert
            // fail loudly on the unique index rather than inventing a turn
        }

        /** @var Message $user */
        /** @var Message $assistant */
        return ['user' => $user, 'assistant' => $assistant, 'opened' => false];
    }

    /**
     * @param  list<string>  $allowedVersionIds
     */
    private function writeCitations(string $organizationId, TurnRecord $record, array $allowedVersionIds): void
    {
        if ($record->citations === []) {
            return;
        }

        foreach ($record->citations as $citation) {
            $row = new Citation;
            $row->message_id = $record->messageId;
            $row->chunk_id = $citation->chunkId;
            $row->label = $citation->label;
            $row->display_title = $citation->displayTitle;
            $row->location_metadata = $citation->locationMetadata;
            $row->excerpt = $citation->excerpt;
            $row->save();
        }
    }

    private function writeTrace(TurnRecord $record): void
    {
        $trace = $record->trace;

        if ($trace === null) {
            return;
        }

        // ONE TRACE PER MESSAGE — `retrieval_traces_message_unique`. A re-entry cannot reach this
        // method (the caller returns early on a settled message), so a duplicate here would be a
        // genuine defect and is left to fail loudly rather than being upserted away.
        $row = new RetrievalTrace;
        $row->message_id = $record->messageId;
        $row->original_query = $trace->originalQuery;
        $row->rewritten_query = $trace->rewrittenQuery;
        $row->filters = $trace->filters;
        $row->retrieval_configuration_version = $trace->retrievalConfigurationVersion;
        $row->candidate_summaries = $trace->candidateSummaries;
        $row->selected_evidence = $trace->consistentSelectedEvidence();
        $row->insufficient_evidence = $trace->insufficientEvidence;
        $row->timing_breakdown = $trace->timingBreakdown;
        $row->save();
    }

    private function writeProviderCalls(
        string $organizationId,
        string $conversationId,
        string $botId,
        TurnRecord $record,
    ): int {
        $written = 0;
        $settling = null;

        foreach ($record->providerCalls as $call) {
            // THE CONNECTION AND MODEL ARE RE-RESOLVED AGAINST THIS ORGANIZATION rather than trusted
            // from the frame. `provider_calls_connection_same_org` and `provider_calls_model_same_org`
            // would refuse a foreign id anyway — as SQLSTATE 23503, inside this transaction, taking
            // the transcript with it — so the ids are checked here and a call naming a row this
            // tenant does not own is SKIPPED. That loses one cost row and keeps the answer.
            $modelId = $this->modelIdFor($organizationId, $call->connectionId, $call->model);

            if ($modelId === null) {
                continue;
            }

            $row = new ProviderCall;
            $row->organization_id = $organizationId;
            $row->bot_id = $botId;
            $row->conversation_id = $conversationId;
            $row->message_id = $record->messageId;
            $row->provider_connection_id = $call->connectionId;
            $row->model_id = $modelId;
            $row->provider_request_id = $call->providerRequestId;
            $row->status = $call->status;
            $row->input_tokens = $call->inputTokens;
            $row->cache_read_tokens = $call->cacheReadTokens;
            $row->cache_write_tokens = $call->cacheWriteTokens;
            $row->output_tokens = $call->outputTokens;
            $row->reasoning_tokens = $call->reasoningTokens;
            $row->first_token_latency_ms = $call->firstTokenLatencyMs;
            $row->total_latency_ms = $call->totalLatencyMs;
            $row->fallback_metadata = $call->fallbackMetadata();
            $row->error_class = $call->errorClass;
            $row->save();

            $written++;
            // THE LAST ATTEMPT IS THE ONE THAT SETTLED THE MESSAGE — the ordinal is 1-based across
            // the whole turn, so the highest one is whichever connection actually answered.
            $settling = $row;
        }

        if ($settling !== null) {
            // `messages.provider_call_id` NAMES THE ATTEMPT THAT SETTLED THE TURN, which is the
            // whole reason the two tables reference each other. It is written LAST because the call
            // row has to exist first — the pair is a cycle in the schema and never in time.
            $this->scopedMessages($organizationId)
                ->where('messages.id', '=', $record->messageId)
                ->update(['provider_call_id' => $settling->id]);
        }

        return $written;
    }

    /**
     * The `provider_models` row id for one `(connection, model)` pair in ONE organization.
     */
    private function modelIdFor(string $organizationId, string $connectionId, string $model): ?string
    {
        $row = DB::table('provider_models')
            ->where('organization_id', '=', $organizationId)
            ->where('provider_connection_id', '=', $connectionId)
            ->where('model', '=', $model)
            ->first(['id']);

        return $row === null || ! is_string($row->id) ? null : $row->id;
    }

    /**
     * Hydrate the columns a `citations` row needs but the wire does not carry.
     *
     * SCOPED TO THE ORGANIZATION AND TO THE TURN'S OWN VERSION SET. The chunk ids arrived over the
     * wire, so a read keyed on them alone would have no tenant filter of its own — and a citation
     * must never need a lookup that trusts an id it was handed. A chunk from another tenant, or from
     * a version this turn was not permitted to search, resolves to nothing and the citation is
     * dropped by the caller rather than persisted against a placeholder.
     *
     * @param  list<string>  $chunkIds
     * @param  list<string>  $allowedVersionIds
     * @return array<string, Chunk>
     */
    public function hydrateChunks(string $organizationId, array $chunkIds, array $allowedVersionIds): array
    {
        if ($chunkIds === [] || $allowedVersionIds === []) {
            return [];
        }

        $rows = Chunk::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('source_version_id', $allowedVersionIds)
            ->whereIn('id', array_values(array_unique($chunkIds)))
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->id] = $row;
        }

        return $out;
    }
}

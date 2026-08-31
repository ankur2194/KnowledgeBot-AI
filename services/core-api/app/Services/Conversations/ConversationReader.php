<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The admin conversation-review use case: which threads exist, and what was said in one of them.
 *
 * ── IT IS A READER AND IT WRITES NOTHING, INCLUDING NO AUDIT ROW ─────────────────────────────
 *
 * §18.11 audits credential changes, configuration changes, publishes, deletions, exports and
 * retention changes. Reading a transcript is none of those, and `AuditLogger::record()` throws on an
 * operation that is not in its map — so auditing this surface would mean adding two operations to a
 * closed vocabulary that `tests/Unit/AuditLoggerTest.php` pins by name.
 *
 * THE COUNTER-ARGUMENT IS REAL AND IS RECORDED RATHER THAN DISMISSED: a transcript is end-user
 * content, and "which administrator read this customer's conversation" is a question a privacy
 * regime can legitimately ask. It is not answered today. The reason it is not being answered by a
 * `conversation.viewed` audit operation is volume rather than principle — a read-audit on a
 * paginated list writes a row per page turn, so the trail it produces is dominated by navigation
 * and the one interesting event is invisible inside it. If it is wanted, it belongs with the §18.10
 * privacy switch below, as one decision about who may look and what is recorded when they do.
 *
 * ── THE TRANSCRIPT IS FIVE STATEMENTS, NOT FOUR PER MESSAGE ─────────────────────────────────
 *
 * One for the messages and one for each child table, each bounded to the page of message ids this
 * read is actually returning, then grouped in PHP. `Message` declares all four as relations and
 * `->with([...])` would be the obvious spelling — and it is the wrong one here, because those
 * relations carry NO organization predicate and cannot: `messages`, `citations`, `retrieval_traces`
 * and `feedback` have no `organization_id` column, so `$message->citations` is `where message_id =
 * ?` and nothing else. `TranscriptTurn`'s docblock carries the full argument.
 */
final class ConversationReader
{
    /**
     * The most messages one transcript read returns.
     *
     * TWO HUNDRED, WHICH IS `RuntimeTranscriptController::MAX_MESSAGES` DELIBERATELY MATCHED rather
     * than coincidentally equal — the same number for a different reason, which is why it is a
     * separate constant. There it bounds what an UNAUTHENTICATED caller may ask for in one request.
     * Here the caller is an authenticated member behind `throttle:admin`, and the bound is about the
     * RESPONSE: every message carries its citations, its feedback, its provider attempts and its
     * retrieval trace, and a trace holds the whole candidate list, so an unbounded transcript is a
     * multi-megabyte body assembled to be scrolled.
     *
     * A hundred turns is a very long thread. `ConversationTranscript::$truncated` says when the cap
     * bit, because a cap a client cannot detect would make a transcript that ends mid-argument
     * indistinguishable from a conversation that ended there.
     */
    public const MAX_MESSAGES = 200;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
    ) {}

    /**
     * One page of this organization's threads.
     *
     * The organization is taken from the ROUTE-BOUND record through `OrgOwned::organizationId()`,
     * never from request input — `ConversationFilter` carries no organization field and could not
     * express one (`kb-tenancy-isolation` NN6).
     *
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function list(
        Organization $organization,
        ConversationFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator {
        return $this->conversations->paginateForAdmin(
            $organization->organizationId(),
            $filter,
            $query,
        );
    }

    /**
     * One thread's messages with everything the four child tables recorded about each.
     *
     * BOTH ARGUMENTS ARE ROUTE-BOUND RECORDS AND THE ORGANIZATION COMES FROM THE PARENT. The
     * conversation was resolved through `$organization->conversations()` by `->scopeBindings()`, so
     * the two agree by construction — and the organization id is still passed explicitly to every
     * repository call below, because a scoped binding is a routing fact and the predicate has to be
     * in the SQL.
     */
    public function transcript(Organization $organization, Conversation $conversation): ConversationTranscript
    {
        $organizationId = $organization->organizationId();

        // ONE MORE THAN THE CAP, so "there were more" is a fact rather than an inference from the
        // count being exactly the limit — which is also true of a thread with exactly 200 messages.
        $messages = $this->conversations->adminTranscript(
            $organizationId,
            $conversation->id,
            self::MAX_MESSAGES + 1,
        );

        $truncated = count($messages) > self::MAX_MESSAGES;

        if ($truncated) {
            $messages = array_slice($messages, 0, self::MAX_MESSAGES);
        }

        $messageIds = array_map(static fn (Message $message): string => (string) $message->id, $messages);

        $citations = $this->conversations->citationsForConversation($organizationId, $conversation->id, $messageIds);
        $traces = $this->conversations->tracesForConversation($organizationId, $conversation->id, $messageIds);
        $feedback = $this->conversations->feedbackForConversation($organizationId, $conversation->id, $messageIds);
        $calls = $this->conversations->providerCallsForConversation($organizationId, $conversation->id, $messageIds);

        $turns = [];

        foreach ($messages as $message) {
            $id = (string) $message->id;

            $turns[] = new TranscriptTurn(
                message: $message,
                // A MESSAGE WITH NO ROWS IS ABSENT FROM THE GROUPED MAP AND GETS `[]` HERE. The
                // repository deliberately does not pre-seed every id — that would make it need the
                // message list, which is a second statement of which messages exist.
                citations: $citations[$id] ?? [],
                trace: $traces[$id] ?? null,
                feedback: $feedback[$id] ?? [],
                providerCalls: $calls[$id] ?? [],
            );
        }

        return new ConversationTranscript(turns: $turns, truncated: $truncated);
    }
}

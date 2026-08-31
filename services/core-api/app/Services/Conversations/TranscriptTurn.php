<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Models\Citation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\ProviderCall;
use App\Models\RetrievalTrace;

/**
 * One message with everything the four child tables recorded about it.
 *
 * ── IT IS A VALUE OBJECT AND NOT A SET OF EAGER-LOADED RELATIONS, DELIBERATELY ───────────────
 *
 * `Message` declares `citations()`, `retrievalTrace()`, `feedback()` and `providerCalls()`, and
 * `->with([...])` would look like the obvious way to assemble this. It is not, for one reason that
 * is the whole of this file's justification: THOSE RELATIONS CARRY NO ORGANIZATION PREDICATE.
 * `messages`, `citations`, `retrieval_traces` and `feedback` have no `organization_id` column and
 * therefore no `#[ScopedBy]` backstop, so `$message->citations` is `where message_id = ?` and
 * nothing else. On a message this request already proved it may read that is *correct* — and it is
 * correct by an argument that lives in the caller rather than in the query, which is exactly the
 * shape that survives a refactor and stops being true.
 *
 * Assembling it here means every one of the five reads goes through a repository method that takes
 * `organization_id` positionally and joins up to `conversations` with it, so the tenancy predicate
 * is present in the SQL of each one and a reviewer can see it without reconstructing the call
 * chain. It also makes the read FIVE STATEMENTS FOR THE WHOLE TRANSCRIPT rather than four per
 * message: the repository fetches each child table once for the conversation and groups in PHP,
 * which is the difference between 5 queries and 401 on a two-hundred-message thread.
 *
 * ── `providerCalls` DOES NOT REACH ITS ORGANIZATION THROUGH THIS MESSAGE ────────────────────
 *
 * `provider_calls.message_id` and `.conversation_id` are both `ON DELETE SET NULL` — retention
 * removes CONTENT and not COST — so a joined read would silently drop every call whose thread has
 * been swept. The table carries its own `organization_id` and the repository predicates on that
 * directly. Within a live conversation the two agree; the point is that the query does not depend
 * on them agreeing.
 */
final readonly class TranscriptTurn
{
    /**
     * @param  list<Citation>  $citations  in label order
     * @param  RetrievalTrace|null  $trace  at most one — `retrieval_traces_message_unique`
     * @param  list<Feedback>  $feedback  at most one per submitter, by the two partial unique indexes
     * @param  list<ProviderCall>  $providerCalls  every attempt behind this turn, oldest first
     */
    public function __construct(
        public Message $message,
        public array $citations,
        public ?RetrievalTrace $trace,
        public array $feedback,
        public array $providerCalls,
    ) {}
}

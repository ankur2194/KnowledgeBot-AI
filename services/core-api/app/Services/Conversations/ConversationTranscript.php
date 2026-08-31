<?php

declare(strict_types=1);

namespace App\Services\Conversations;

/**
 * One conversation's messages, in order, each with its citations, trace, feedback and provider
 * attempts.
 *
 * ── IT IS BOUNDED, AND THE BOUND IS PUBLISHED RATHER THAN SILENT ────────────────────────────
 *
 * `ConversationReader::MAX_MESSAGES` caps the read and `$truncated` says whether it bit. A cap that
 * a client cannot detect is worse than no cap: a reviewer reading a long thread would see it end
 * mid-argument and conclude the conversation ended there, which on a surface whose entire purpose
 * is answering "what did we actually tell this customer" is the one wrong answer that matters.
 *
 * The same pattern `SourceDetailResource` uses for `warnings_truncated` and
 * `content_preview_truncated`, and for the same reason.
 */
final readonly class ConversationTranscript
{
    /**
     * @param  list<TranscriptTurn>  $turns  oldest first, `(created_at, id)` — the same total order
     *                                       `messages_conversation_created` provides, and the tie-break
     *                                       is not decoration: a user turn and its pending assistant
     *                                       row are written in one transaction and can share a
     *                                       microsecond, so without it PostgreSQL may legally return
     *                                       the answer before the question on a second read.
     * @param  bool  $truncated  whether the message cap was reached and rows were left unread
     */
    public function __construct(
        public array $turns,
        public bool $truncated,
    ) {}
}

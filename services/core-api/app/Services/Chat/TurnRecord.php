<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\MessageStatus;

/**
 * Everything one finished chat turn writes, as one object, so it can be committed in ONE transaction.
 *
 * ═══ WHY THE FOUR WRITES ARE ONE TRANSACTION AND NOT FOUR ═══════════════════════════════════
 *
 * The message row, the citation rows, the retrieval trace and the provider-call rows describe the
 * same event, and each one is meaningless — or actively misleading — without the others:
 *
 *   A TRACE WITHOUT ITS MESSAGE is an explanation of an answer nobody can find. `retrieval_traces`
 *   is CASCADE on `message_id`, so it cannot outlive the message; what a separate transaction adds
 *   is the reverse, a trace that PRECEDES it — a row explaining a message that does not exist yet
 *   and may never.
 *
 *   CITATIONS WITHOUT THEIR MESSAGE are footnotes with no answer, and the unique index on
 *   `(message_id, label)` means a partial write leaves the retry unable to insert the rest.
 *
 *   PROVIDER CALLS WITHOUT THE MESSAGE are cost with no attribution — which is not merely untidy,
 *   because `usage_events` is derived from them and would meter a turn the transcript denies.
 *
 * ADR-074 is why the trace is on this side at all: `retrieval_traces` came off the data plane's
 * `ALLOWED_TABLES` precisely so this plane owns it, and the reason given was that a data-plane write
 * would land beside Laravel's own writer with no policy and no audit row. This object is that
 * writer's input.
 *
 * ═══ IT IS BUILT INCREMENTALLY AND COMMITTED ONCE ═══════════════════════════════════════════
 *
 * `StreamFinalizer` accumulates into it as frames arrive, because a cut stream may never deliver a
 * terminal frame at all — the tally is what makes usage survive a client hangup. The commit happens
 * in a `finally`, keyed on `messageId`, so the abort path and the completion path cannot both write.
 */
final class TurnRecord
{
    /** @var list<RecordedCitation> */
    public array $citations = [];

    /** @var list<RecordedProviderCall> */
    public array $providerCalls = [];

    public ?RecordedTrace $trace = null;

    /**
     * The answer text, accumulated from `token` frames.
     *
     * ACCUMULATED HERE RATHER THAN READ FROM A TERMINAL FRAME, because there may not be one: the
     * client closes the tab, the socket dies, the deadline passes. What the reader saw is what this
     * string holds, and it is what the transcript must say — a transcript that disagreed with the
     * wire is worse than a short one.
     */
    public string $content = '';

    public MessageStatus $status = MessageStatus::Streaming;

    /**
     * The wire's `finish_reason`, verbatim.
     *
     * `cancelled` and `insufficient_evidence` are both CORRECT outcomes and neither is an error: a
     * refusal is the bot saying the answer is not in its sources, and a cancellation is a closed
     * laptop lid. Folding either into an error rate makes the error-rate panel unusable, which is
     * why the value is carried rather than derived from the status.
     */
    public ?string $finishReason = null;

    /**
     * The `error_class` a failure ended with, as the data plane assigned it.
     *
     * RELAYED VERBATIM AND NEVER RE-DERIVED FROM A STATUS. It is written to
     * `provider_calls.error_class`, whose CHECK is generated from `ErrorTaxonomy::RETRYABLE`, so a
     * class this plane invented would be a constraint violation rather than a silently wrong row.
     */
    public ?string $errorClass = null;

    public function __construct(public readonly string $messageId) {}
}

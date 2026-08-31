<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Models\ProviderCall;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Services\Internal\ParsedEvent;
use App\Services\Usage\UsageRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The running tally of one streamed turn, and the ONE transaction that commits it.
 *
 * ═══ IT TALLIES INCREMENTALLY BECAUSE A CUT STREAM MAY NEVER DELIVER A TERMINAL FRAME ══════
 *
 * The three compounding traps this class exists for, from `kb-internal-api-contracts` and
 * `laravel-control-plane`:
 *
 *   1. A BROWSER CLOSING A STREAM SENDS NOTHING. PHP learns the client is gone only when a WRITE
 *      FAILS, so `connection_aborted()` stays `0` through an arbitrarily long silence — which is why
 *      the heartbeat is the disconnect probe and not merely proxy keepalive.
 *
 *   2. THE ABORT PHP DOES EVENTUALLY NOTICE TERMINATES THE SCRIPT THROUGH A BAILOUT THAT SKIPS
 *      `finally`. `ignore_user_abort(true)` in the stream callback is what makes this class's
 *      `commit()` reachable at all; without it the tally is built perfectly and thrown away.
 *
 *   3. STREAMING USAGE IS OPT-IN OR LAST-MESSAGE-ONLY DEPENDING ON PROVIDER, so waiting for a
 *      terminal `provider.usage` and finalizing from that would under-report every cut stream by the
 *      whole answer. Every frame is folded in as it arrives.
 *
 * A cancelled turn is a NORMAL outcome and not an error: `finish_reason: "cancelled"` with usage
 * finalized from the tally, `user_cancellation` recorded, and NOT counted in the error rate (docs/14
 * §19.1). The provider billed the tokens it had already generated, so the row has to exist.
 *
 * ═══ ONE TRANSACTION, KEYED ON `message_id` ════════════════════════════════════════════════
 *
 * `commit()` is idempotent and takes a row lock: the abort path and the completion path can both
 * reach the relay's `finally`, and without the lock both would read `pending`, both would proceed,
 * and the second citation insert would fail on `citations_message_label_unique` — taking the whole
 * transcript with it. The repository owns that; this class owns being safe to call twice.
 *
 * ═══ `commit()` NEVER THROWS ═══════════════════════════════════════════════════════════════
 *
 * It runs inside the stream callback's `finally`, where the response has already started: an
 * exception there cannot become a status, cannot be rendered by the error envelope, and in PHP is
 * swallowed by the ASGI-equivalent layer with the only symptom being a truncated stream. So a
 * failure is logged with its `error_class` and the stream still ends cleanly. That is the one place
 * in this change where a failure is deliberately not loud to the caller — and it is loud to the
 * operator.
 */
final class StreamFinalizer
{
    private readonly TurnRecord $record;

    /**
     * The `provider.fallback` frames seen so far, keyed by ordinal.
     *
     * KEPT SEPARATELY AND PAIRED AT COMMIT, because they are two frames on the wire: `ProviderUsage`
     * carries no fallback fields at all, and `ProviderFallback` is emitted AHEAD of the replacement
     * model's first token, so the pair does not arrive together. Reading only the usage frame would
     * write a `provider_calls` row whose `fallback_metadata` is `{}` for an attempt that WAS a
     * fallback — which makes the ladder look free in every cost report.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $fallbacks = [];

    /**
     * Raw `citations` frame entries, held until commit.
     *
     * They cannot be turned into rows as they arrive: `citations.excerpt` and `location_metadata`
     * come from THIS plane's `chunks` table, and that hydration is one batched, org-scoped read
     * rather than one query per footnote inside a streaming loop.
     *
     * @var list<array<string, mixed>>
     */
    private array $citationFrames = [];

    private bool $committed = false;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly UsageRecorder $usage,
        /**
         * ═══ THE TENANT CONTEXT IS RE-BOUND AT COMMIT, AND THIS IS THE SUBTLE ONE ═══════════
         *
         * `ResolveChatSession` binds it with `TenantContext::runFor()`, which restores the previous
         * value in a `finally` — so it is bound for the whole middleware stack and for the
         * controller, and it is ALREADY UNBOUND by the time this class runs. A streamed response's
         * callback executes during `Response::send()`, AFTER the middleware stack has unwound; that
         * is not a quirk of ours, it is what `response()->stream()` means.
         *
         * Every read in `commit()` passes its organization explicitly, so correctness does not
         * depend on the ambient value — but `OrganizationScope` FAILS CLOSED with `1 = 0` when
         * nothing is bound, and three of the models involved carry it. Without this, `touchActivity`
         * would update zero rows and `meter()` would find zero `provider_calls` rows to bill, both
         * SILENTLY, on every single turn. That is the whole reason this dependency is here.
         */
        private readonly TenantContext $tenancy,
        private readonly ChatContext $context,
        private readonly ConfigSnapshot $snapshot,
    ) {
        $this->record = new TurnRecord($context->messageId);
    }

    /**
     * Fold one frame into the tally. Called for EVERY frame, forwarded or not.
     *
     * The internal-only frames are the interesting ones — they are the whole reason Laravel consumes
     * the stream rather than proxying it — but the forwarded ones matter too: `token` is what the
     * transcript is made of, and `message.complete` carries the finish reason.
     */
    public function observe(ParsedEvent $frame): void
    {
        match ($frame->name) {
            'token' => $this->appendToken($frame),
            'citations' => $this->recordCitations($frame),
            'provider.usage' => $this->recordProviderCall($frame),
            'provider.fallback' => $this->recordFallback($frame),
            'retrieval.trace' => $this->recordTrace($frame),
            'message.complete' => $this->recordCompletion($frame),
            'error' => $this->recordError($frame),
            default => null,
        };
    }

    /**
     * The client went away. Not an error, and the usage still has to be written.
     */
    public function markCancelled(): void
    {
        $this->record->status = MessageStatus::Cancelled;
        $this->record->finishReason ??= 'cancelled';
    }

    /**
     * The stream ended without a terminal frame — the connection itself died.
     *
     * `stream_lost` IS NOT WRITTEN ANYWHERE. It is a CLIENT-LOCAL sentinel for the one case a server
     * cannot report, it is not one of the eighteen classes, and serializing it would make the
     * taxonomy nineteen by accident. What this plane knows is that the turn did not finish, so the
     * row settles as `failed` with the class it actually has: none, unless a frame already assigned
     * one.
     */
    public function markInterrupted(): void
    {
        if ($this->record->status === MessageStatus::Streaming || $this->record->status === MessageStatus::Pending) {
            $this->record->status = MessageStatus::Failed;
            $this->record->finishReason ??= 'error';
        }
    }

    /**
     * Commit the message row, its citations, its trace and its provider calls — then meter usage.
     *
     * ── THE METERING IS OUTSIDE THE TRANSACTION AND THAT IS DELIBERATE ─────────────────────
     *
     * `usage_events` is a ledger with its own dedupe identity (`(org, type, dedupe_key, occurred_at)`,
     * where the key is the `provider_calls` ULID and the instant is the call's own `created_at`), so
     * re-deriving it collides instead of adding. Putting it inside the transcript's transaction
     * would mean a ledger failure loses the ANSWER, and the ledger is reconstructible from
     * `provider_calls` by `kb:rollup-usage` while the answer is not.
     *
     * ── IT NEVER THROWS ────────────────────────────────────────────────────────────────────
     */
    public function commit(): void
    {
        if ($this->committed) {
            return;
        }

        $this->committed = true;

        try {
            // RE-BOUND HERE — see the constructor. The stream callback runs after the middleware
            // stack has unwound, and three of the models below fail closed without it.
            $this->tenancy->runFor($this->context->organizationId, function (): void {
                $this->hydrateCitations();

                $written = $this->conversations->finalizeTurn(
                    $this->context->organizationId,
                    $this->context->conversationId,
                    $this->context->botId,
                    $this->record,
                    $this->snapshot->allowedVersionIds,
                );

                $this->conversations->touchActivity(
                    $this->context->organizationId,
                    $this->context->conversationId,
                );

                if ($written > 0) {
                    $this->meter();
                }
            });
        } catch (Throwable $e) {
            // THE ONE DELIBERATE SWALLOW IN THIS CHANGE. See the class docblock: the response has
            // already started, so this cannot become a status and cannot reach the error envelope.
            // It is logged with `error_class` so the existing panels see it and with `request_id` so
            // it is greppable across both services — and NOT with the exception object, because a
            // QueryException's message interpolates every binding and the bindings here include
            // tenant text.
            Log::error('chat turn finalization failed', [
                'error_class' => 'internal_dependency',
                'outcome' => 'error',
                'operation' => 'chat.finalize',
                'kb.message_id' => $this->context->messageId,
                'kb.conversation_id' => $this->context->conversationId,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * The tally, for a caller that wants to know how the turn ended without re-reading the row.
     */
    public function record(): TurnRecord
    {
        return $this->record;
    }

    private function appendToken(ParsedEvent $frame): void
    {
        $text = $frame->data()['text'] ?? null;

        if (is_string($text)) {
            $this->record->content .= $text;
            $this->record->status = MessageStatus::Streaming;
        }
    }

    private function recordCitations(ParsedEvent $frame): void
    {
        $citations = $frame->data()['citations'] ?? null;

        if (! is_array($citations)) {
            return;
        }

        // REPLACED, NOT APPENDED. The frame is emitted ONCE per turn, before the first token, and a
        // second one would be a re-run rather than more footnotes — appending would produce two
        // entries with label `1` and fail `citations_message_label_unique` inside the commit.
        $this->citationFrames = [];

        foreach ($citations as $entry) {
            if (is_array($entry)) {
                $this->citationFrames[] = $entry;
            }
        }
    }

    private function recordProviderCall(ParsedEvent $frame): void
    {
        $data = $frame->data();
        $ordinal = is_int($data['ordinal'] ?? null) ? $data['ordinal'] : 1;

        $this->record->providerCalls[] = RecordedProviderCall::fromFrame(
            $data,
            $this->fallbacks[$ordinal] ?? null,
        );
    }

    private function recordFallback(ParsedEvent $frame): void
    {
        $data = $frame->data();
        $ordinal = is_int($data['ordinal'] ?? null) ? $data['ordinal'] : 0;

        // HELD FOR THE USAGE FRAME OF THE SAME ORDINAL, which arrives after it. A same-connection
        // RETRY is not a fallback and never produces this frame at all, so the presence of one is
        // itself the signal — the pairing does not have to compare connection ids.
        $this->fallbacks[$ordinal] = $data;
    }

    private function recordTrace(ParsedEvent $frame): void
    {
        $trace = $frame->data()['trace'] ?? null;

        if (! is_array($trace)) {
            return;
        }

        $this->record->trace = RecordedTrace::fromFrame(
            $trace,
            $this->context->query,
            $this->context->organizationId,
            $this->context->botId,
            $this->snapshot->allowedVersionIds,
            $this->snapshot->retrievalConfigurationVersion,
        );
    }

    private function recordCompletion(ParsedEvent $frame): void
    {
        $reason = $frame->data()['finish_reason'] ?? null;
        $this->record->finishReason = is_string($reason) ? $reason : 'stop';

        // `cancelled` SETTLES AS CANCELLED AND NOT AS COMPLETE, even though it arrived on the
        // terminal success frame: the data plane emits `message.complete` for a cancellation on
        // purpose (exactly one terminal frame per stream, always), so the STATUS has to come from the
        // reason rather than from the frame's name.
        //
        // `insufficient_evidence` settles as COMPLETE and that is not a compromise: a refusal is a
        // correct outcome, the bot said the answer is not in its sources, and no provider call was
        // made. Counting it as a failure makes the error-rate panel unusable.
        $this->record->status = match ($this->record->finishReason) {
            'cancelled' => MessageStatus::Cancelled,
            'error' => MessageStatus::Failed,
            default => MessageStatus::Complete,
        };

        // A COMPLETE MESSAGE WITH NO CONTENT IS REFUSED BY
        // `messages_content_present_when_complete`, and a refusal legitimately produces one — the
        // bot's own refusal text is generated by the runner and arrives as tokens, but a turn that
        // produced none at all would take the transaction down. `failed` is the honest settlement:
        // the turn reported success and said nothing.
        if ($this->record->status === MessageStatus::Complete && $this->record->content === '') {
            $this->record->status = MessageStatus::Failed;
        }
    }

    private function recordError(ParsedEvent $frame): void
    {
        $data = $frame->data();
        $class = $data['error_class'] ?? null;

        // THE CLASS THE DATA PLANE ASSIGNED, VERBATIM. Never re-derived from a status: a
        // `provider_temporary` arriving as 503 would come back out as `internal_dependency`, and the
        // retry decision would be made against a class nobody assigned.
        $this->record->errorClass = is_string($class) && $class !== '' ? $class : null;
        $this->record->status = MessageStatus::Failed;
        $this->record->finishReason ??= 'error';
    }

    /**
     * Turn the held citation frames into rows, hydrating each from this organization's own `chunks`.
     */
    private function hydrateCitations(): void
    {
        if ($this->citationFrames === []) {
            return;
        }

        $chunkIds = [];

        foreach ($this->citationFrames as $entry) {
            $chunkId = $entry['chunk_id'] ?? null;

            if (is_string($chunkId) && $chunkId !== '') {
                $chunkIds[] = $chunkId;
            }
        }

        $chunks = $this->conversations->hydrateChunks(
            $this->context->organizationId,
            $chunkIds,
            $this->snapshot->allowedVersionIds,
        );

        foreach ($this->citationFrames as $entry) {
            $chunkId = is_string($entry['chunk_id'] ?? null) ? $entry['chunk_id'] : '';
            $citation = RecordedCitation::fromFrame($entry, $chunks[$chunkId] ?? null);

            // A CITATION WHOSE CHUNK DID NOT RESOLVE IS DROPPED, not persisted with a placeholder.
            // Under the scope above that means: another tenant's chunk, a chunk from a version this
            // turn was not permitted to search, or one deleted between generation and commit.
            if ($citation !== null) {
                $this->record->citations[] = $citation;
            }
        }
    }

    /**
     * Meter every `provider_calls` row this turn wrote into `usage_events`.
     *
     * ── IT READS THE ROWS BACK RATHER THAN METERING THE TALLY ──────────────────────────────
     *
     * `UsageRecorder::recordProviderCall()` takes a `ProviderCall` MODEL because the ledger's dedupe
     * identity is the row's own ULID and its own `created_at` — not a value this class could invent.
     * Metering the in-memory tally would need both, and a re-derivation later (`kb:rollup-usage`)
     * would then produce different ones and charge twice.
     *
     * The arithmetic itself is `UsageArithmetic`'s and is not repeated: input is `input_tokens`
     * ALONE because the column is already the normalized total, and output is
     * `output_tokens + reasoning_tokens` because every vendor here bills reasoning at the output
     * rate.
     */
    private function meter(): void
    {
        $rows = ProviderCall::query()
            ->where('organization_id', '=', $this->context->organizationId)
            ->where('message_id', '=', $this->context->messageId)
            ->get();

        foreach ($rows as $row) {
            $provider = Provider::tryFrom($this->providerOf($row));

            if ($provider === null) {
                // A provider string the enum does not know is a contract drift, not a billing event.
                // Skipping it loses one meter row and keeps the rest; recording it under a guessed
                // vendor would put an unpriceable line into a reconciliation.
                continue;
            }

            $this->usage->recordProviderCall($row, $provider, $this->modelOf($row));
        }
    }

    private function providerOf(ProviderCall $row): string
    {
        foreach ($this->record->providerCalls as $call) {
            if ($call->connectionId === (string) $row->provider_connection_id) {
                return $call->provider;
            }
        }

        return '';
    }

    private function modelOf(ProviderCall $row): string
    {
        foreach ($this->record->providerCalls as $call) {
            if ($call->connectionId === (string) $row->provider_connection_id) {
                return $call->model;
            }
        }

        return '';
    }
}

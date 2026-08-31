<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Enums\MessageStatus;
use App\Exceptions\KbException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Repositories\Contracts\ConversationRepositoryInterface;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Services\Chat\ChatContext;
use App\Services\Chat\ChatGate;
use App\Services\Chat\ConfigSnapshot;
use App\Services\Chat\ConfigSnapshotResolver;
use App\Services\Chat\StreamFinalizer;
use App\Services\Internal\InternalAiClient;
use App\Services\Internal\ParsedEvent;
use App\Services\Internal\UpstreamStream;
use App\Services\Sdk\WidgetSession;
use App\Services\Usage\UsageRecorder;
use App\Support\Contracts\ResponseShape;
use App\Support\Kb\ClientEvents;
use App\Support\Tenancy\TenantContext;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * `POST /rt/v1/conversations/{conversation}/messages` — the SSE relay.
 *
 * ═══ AUTHORIZE AND RESOLVE BEFORE THE FIRST BYTE. THE STATUS LINE IS SPENT AFTERWARDS ══════
 *
 * Once `200 OK` and the headers are on the wire, a later failure can only be an SSE `error` EVENT —
 * never a 4xx. So everything that can refuse runs first: the six checks, the rate limit, the quota,
 * the configuration snapshot, and the turn's own rows. `ChatGate` and `ConfigSnapshotResolver` throw
 * `KbException`s that the render closure turns into the ordinary envelope, and nothing has been
 * streamed at that point.
 *
 * ═══ `response()->stream()` AND NEVER `eventStream()` ══════════════════════════════════════
 *
 * `eventStream()` is the better default in general — it sets the three headers, flushes per yield,
 * breaks on `connection_aborted()` and terminates with a `</stream>` frame. IT CANNOT EMIT AN SSE
 * COMMENT. Our heartbeat is `: ping`, a comment, and it has to be one: a comment keeps proxies awake
 * WITHOUT firing `onmessage` in any client, while a named `heartbeat` event is on the internal-only,
 * never-forwarded list precisely because a client that can name it can render it. Returning a
 * `Generator` from `stream()` keeps the auto-flush and the `X-Accel-Buffering` header while leaving
 * the frame bytes ours.
 *
 * ═══ `ignore_user_abort(true)` IS WHAT MAKES THE `finally` REACHABLE ═══════════════════════
 *
 * PHP's default is to KILL the script on an aborted connection, and that teardown is a BAILOUT: the
 * `finally` below would never run, so the upstream would never be cancelled and the usage would never
 * be written. Owning the abort is the whole reason a cancelled turn can still be billed and recorded.
 *
 * `set_time_limit(0)` because `max_execution_time` is not a bound here anyway — it counts script
 * execution only, and time spent blocked on a socket is not script execution. The real ceiling is
 * FPM's `request_terminate_timeout`, which must sit ABOVE the 60 s chat deadline or it becomes a
 * mid-answer SIGTERM with no finalization.
 *
 * ═══ THE HEARTBEAT IS THE DISCONNECT PROBE, NOT MERELY PROXY KEEPALIVE ═════════════════════
 *
 * A browser closing an `EventSource` or aborting a `fetch` sends NOTHING that PHP can observe: PHP
 * learns the client is gone only when a WRITE FAILS. With no writes there is nothing to fail, so
 * `connection_aborted()` stays `0` through an arbitrarily long silence — and retrieval plus
 * generation is seconds of silence by design. Writing `: ping` on every idle read is what turns a
 * departed client into an observable event at all.
 *
 * ═══ THREE TERMINAL PATHS, AND ONE `finally` ═══════════════════════════════════════════════
 *
 *   COMPLETE    the upstream ended after its own terminal frame, which was forwarded verbatim.
 *   CANCELLED   the client went away. NOT an error: `finish_reason: "cancelled"`, usage finalized
 *               from the running tally, `user_cancellation` recorded (docs/14 §19.1). The provider
 *               billed the tokens it had already generated.
 *   ERROR       relayed VERBATIM. The class the data plane assigned crosses unchanged; this plane
 *               never re-derives one from a status.
 *
 * The `finally` cancels the upstream body — which is what makes FastAPI see `http.disconnect`, cancel
 * the pipeline task and close the provider socket — and commits the finalizer. Skip the cancel and
 * the vendor generates billable tokens nobody will read for as long as the model wants to talk.
 */
final class StreamChatMessageController extends Controller
{
    public function __construct(
        private readonly ChatGate $gate,
        private readonly ConfigSnapshotResolver $config,
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ProviderConnectionRepositoryInterface $connections,
        private readonly InternalAiClient $ai,
        private readonly UsageRecorder $usage,
        private readonly TenantContext $tenancy,
    ) {}

    #[ResponseShape(
        status: 200,
        // NO PROPERTIES, AND THAT IS WHY `mediaType` EXISTS. The body is a sequence of SSE frames,
        // not a JSON document; the frame union is published as `packages/contracts/src/sse/events.ts`
        // and is deliberately not transcribed into the OpenAPI document, because a third copy of a
        // contract three clients already parse is the copy that drifts.
        properties: [],
        description: 'A stream of the six client-facing frames, in order, with exactly one terminal '
            .'frame (`message.complete` or `error`) and `: ping` comments in between. A FAILURE MAY '
            .'ARRIVE EITHER WAY: before the first byte it is the ordinary JSON error envelope with a '
            .'real status; afterwards the status is spent and it can only be an `error` frame on a '
            .'200. Branch on the CONTENT TYPE, never on the status.',
        errors: [401, 403, 404, 422, 429],
        mediaType: 'text/event-stream',
    )]
    public function __invoke(SendChatMessageRequest $request, string $conversation, WidgetSession $session): StreamedResponse
    {
        // ── EVERYTHING THAT CAN REFUSE, BEFORE THE FIRST BYTE ────────────────────────────────
        $turn = $this->gate->authorizeSubmission(
            $session,
            $conversation,
            (string) $request->validated('client_message_id'),
            (string) $request->validated('content'),
            $request,
        );

        $snapshot = $this->config->resolve($turn->organization, $turn->bot);

        $opened = $this->conversations->openTurn(
            $session->organizationId,
            $conversation,
            $turn->clientMessageId,
            $turn->content,
        );

        $this->conversations->touchActivity($session->organizationId, $conversation);

        $assistantId = (string) $opened['assistant']->id;

        // ── A RE-SUBMIT REPLAYS THE PERSISTED STATE AND MAKES NO PROVIDER CALL ───────────────
        //
        // The client-minted id already produced a turn. `kb-internal-api-contracts` states the rule
        // for the reconnect case and it is the same rule here: return the PERSISTED state —
        // `message.complete` when generation finished, otherwise an `error` frame carrying one of
        // the eighteen classes — and let the client close the stream. Re-running would bill a second
        // generation for a question already answered, which is the exact defect a stable
        // `client_message_id` exists to prevent.
        if ($opened['opened'] === false) {
            return $this->replay($assistantId, $conversation, $opened['assistant']->status);
        }

        $context = new ChatContext(
            organizationId: $session->organizationId,
            botId: $session->botId,
            conversationId: $conversation,
            messageId: $assistantId,
            clientMessageId: $turn->clientMessageId,
            actorType: $session->actorType,
            actorId: $session->userId,
            // PER-REQUEST, AND IT IS ALSO THE REPLAY NONCE on the internal seam — so it must never be
            // derived from anything stable, or two legitimate submissions of one message would look
            // like a replay and the second would be refused with a 401.
            requestId: (string) ($request->headers->get('X-KB-Request-Id') ?: Str::ulid()),
            query: $turn->content,
            history: $this->conversations->historyWindow(
                $session->organizationId,
                $conversation,
                $snapshot->retrieval->historyWindowTurns,
            ),
            // AND-ED WITH THE ACTOR TYPE INSIDE `ClientEvents::allows()`, AND READ OFF THE RESOLVED
            // CREDENTIAL — never off a header, a query parameter or a body field, which are the
            // caller's to choose (`kb-tenancy-isolation` NN6 applied to an authority rather than to
            // a scope).
            //
            // THIS LINE USED TO BE A HARD-CODED `false` whose comment said "the admin playground is
            // what passes `true`, from a different credential". That credential exists now:
            // `WidgetSessionService::mintPlayground()`, issued by
            // `POST /api/v1/organizations/{organization}/bots/{bot}/playground-session` behind
            // `bots.manage`, and resolved by the SAME `ResolveChatSession` middleware and the SAME
            // one mechanism — the four-mechanisms rule is intact because what changed is who may be
            // issued a `kbw_` session and what its RECORD says, not how many credential types this
            // surface reads.
            //
            // A WIDGET SESSION IS STILL `false` HERE, and doubly refused: `resolve()` derives this
            // flag from the record's stored `kind`, and `ClientEvents::allows()` additionally
            // requires `ActorType::User`, which an `anonymous_session` can never be. Both conjuncts
            // would have to break for a trace to reach a page we do not control.
            diagnostics: $session->diagnostics,
        );

        // THE SEALED CREDENTIALS ARE READ HERE AND DECRYPTED INSIDE THE CLIENT. This controller
        // never holds a plaintext key: it passes ciphertext through to the one method permitted to
        // open it.
        $sealed = $this->connections->sealedFor(
            $session->organizationId,
            $snapshot->connectionIdsInPlay(),
        );

        $finalizer = new StreamFinalizer(
            $this->conversations,
            $this->usage,
            $this->tenancy,
            $context,
            $snapshot,
        );

        // ABSOLUTE EPOCH MILLISECONDS, from THIS REQUEST'S start. `LARAVEL_START` is process-scoped
        // and coincides with the request only under PHP-FPM, which is where this code runs; a queued
        // caller must use `callEpoch()` instead (finding B1/Q2). The budget is the INTERNAL one
        // (55 s), not the chat deadline (60 s), so five seconds are left for this plane to finalize
        // usage and close the stream cleanly after the upstream is done.
        $deadlineMs = (int) round(
            (defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true)) * 1000
            + (int) config('kb.timeouts.internal') * 1000,
        );

        // `yield from` AND NOT `fn (): Generator => …`, AND THE DIFFERENCE IS TOTAL SILENCE.
        //
        // `ResponseFactory::stream()` branches on `(new ReflectionFunction($callback))->isGenerator()`
        // — whether the CALLBACK ITSELF is a generator function, not whether it returns one. An arrow
        // function returning a Generator is NOT a generator function, so the framework takes the
        // plain-callback branch, invokes it once, throws the returned Generator away unread, and
        // sends a 200 with an empty body. Nothing raises: the headers are right, the status is right,
        // and the answer never appears.
        //
        // A closure containing `yield from` IS a generator function, so the framework iterates it and
        // performs the per-chunk `ob_flush(); flush()` that makes this a stream rather than a buffer.
        // Destroying the outer generator — which is what a client hangup does — propagates to the
        // delegated one, so `relay()`'s `finally` still runs and the usage is still written.
        return response()->stream(
            function () use ($snapshot, $context, $deadlineMs, $sealed, $finalizer): Generator {
                yield from $this->relay($snapshot, $context, $deadlineMs, $sealed, $finalizer);
            },
            200,
            $this->headers(),
        );
    }

    /**
     * The stream callback. Everything from here on runs AFTER the middleware stack has unwound.
     *
     * @param  array<string, array{credential_ciphertext: string, data_key_ciphertext: string}>  $sealed
     * @return Generator<int, string>
     */
    private function relay(
        ConfigSnapshot $snapshot,
        ChatContext $context,
        int $deadlineMs,
        array $sealed,
        StreamFinalizer $finalizer,
    ): Generator {
        // THE TWO LINES THE WHOLE FILE DEPENDS ON — see the class docblock. Without the first, the
        // abort PHP eventually notices tears the script down through a bailout that SKIPS `finally`,
        // and the usage row is never written.
        ignore_user_abort(true);
        set_time_limit(0);

        $upstream = null;
        $sawTerminal = false;

        try {
            // FIRST WRITE, AND IT ARMS THE ABORT PROBE. Until something has been written,
            // `connection_aborted()` cannot become 1 however long the client has been gone. It is a
            // COMMENT, so no client dispatches an event for it.
            yield ": open\n\n";

            // `retry:` ON OPEN. `EventSource` reconnects on any drop and the browser default is
            // 3 s, so a mass disconnect becomes a reconnect storm; 10 s spreads it. Our clients use
            // `fetch` and ignore this — it is here for correctness of the wire, not because we rely
            // on it, and token streams are explicitly NOT resumable.
            yield "retry: 10000\n\n";

            $upstream = $this->ai->openChatStream($snapshot, $context, $deadlineMs, $sealed);

            foreach ($upstream->frames() as $frame) {
                // A NULL IS AN IDLE READ AND NOT AN END. The upstream is thinking — retrieval and
                // generation are seconds of silence by design — and this write is the only thing
                // that can make a departed client observable.
                if ($frame === null || $frame->name === UpstreamStream::HEARTBEAT) {
                    yield ": ping\n\n";

                    if (connection_aborted() === 1) {
                        break;
                    }

                    continue;
                }

                // OBSERVED FIRST, FORWARDED SECOND. The tally must include a frame even when the
                // client is about to vanish — `provider.usage` in particular, which is consumed and
                // never forwarded, and which a cut stream may deliver as its last act.
                $finalizer->observe($frame);

                if ($frame->name === 'message.complete' || $frame->name === 'error') {
                    $sawTerminal = true;
                }

                // THE FORWARD ALLOW-LIST. Four frames stop here: `provider.usage` (cost data),
                // `provider.fallback` (routing topology), `retrieval.trace` (candidate ids, scores
                // and the whole filter object) and `heartbeat`. A client that can NAME them is a
                // client that can render them, and this union is shared with a widget running on a
                // page we do not control.
                if (! ClientEvents::allows($frame->name, $context->actorType, $context->diagnostics)) {
                    continue;
                }

                yield $frame->toSseFrame();

                // AFTER THE WRITE, NEVER BEFORE. `connection_aborted()` only becomes 1 once a write
                // has actually failed, so testing it before yielding tests the previous frame.
                if (connection_aborted() === 1) {
                    break;
                }
            }

            if (connection_aborted() === 1) {
                $finalizer->markCancelled();
            } elseif (! $sawTerminal) {
                // THE UPSTREAM ENDED WITHOUT SAYING WHY. Exactly one terminal frame per stream is
                // the contract, so none means the connection itself died. This plane owes the client
                // a terminal frame anyway — a server that can still write knows more than a client
                // guessing — and `internal_dependency` is the honest class: `stream_lost` is a
                // CLIENT-LOCAL sentinel, is not one of the eighteen, and must never be serialized.
                $finalizer->markInterrupted();

                yield $this->errorFrame(
                    'internal_dependency',
                    'The answer stream ended unexpectedly.',
                    true,
                    $context->requestId,
                );
            }
        } catch (KbException $e) {
            // THE CLASS THE DATA PLANE ASSIGNED, RELAYED VERBATIM. Never re-derived from a status: a
            // `provider_temporary` arriving as 503 would come back out as `internal_dependency` and
            // the client's retry decision would be made against a class nobody assigned (ADR-029 at
            // the relay boundary).
            $finalizer->observe(new ParsedEvent('error', json_encode([
                'error_class' => $e->errorClass,
            ], JSON_THROW_ON_ERROR)));

            yield $this->errorFrame($e->errorClass, $e->getMessage(), $e->retryable(), $context->requestId);
        } catch (Throwable $e) {
            // AN UNMAPPED EXCEPTION IN OUR OWN CODE — a defect, not a brownout. `retryable: false`,
            // because no number of attempts fixes a bug and a `true` here sends the client down a
            // full backoff ladder against a guaranteed failure (ADR-029 / finding O1).
            //
            // `$e->getMessage()` NEVER REACHES THE FRAME. A QueryException interpolates every
            // binding into its message and the bindings on this path include the visitor's question
            // and the tenant's own text.
            Log::error('unhandled exception in the chat relay', [
                'error_class' => 'internal_dependency',
                'outcome' => 'error',
                'operation' => 'chat.execute',
                'kb.message_id' => $context->messageId,
                'exception_class' => $e::class,
            ]);

            $finalizer->markInterrupted();

            yield $this->errorFrame(
                'internal_dependency',
                'The service could not complete this request.',
                false,
                $context->requestId,
            );
        } finally {
            // CLOSES THE PSR BODY -> FastAPI sees `http.disconnect` -> the pipeline task is cancelled
            // -> the provider socket closes. Skip this and the vendor keeps generating billable
            // tokens nobody will read, for as long as the model wants to talk.
            $upstream?->cancel();

            // MESSAGE ROW + CITATIONS + TRACE + PROVIDER CALLS, one transaction, on every terminal
            // path INCLUDING the abort. It re-binds the tenant context itself, because this callback
            // runs after the middleware stack has unwound.
            $finalizer->commit();
        }
    }

    /**
     * Replay a turn that has already run, without touching a provider.
     *
     * Two frames and no more: the client needs `message.start` to know which message this is, and a
     * terminal frame to stop. There is no way to replay the TOKENS — they were never stored as a
     * stream — and re-generating to produce them would be the second bill this whole path exists to
     * avoid.
     */
    private function replay(string $messageId, string $conversationId, MessageStatus $status): StreamedResponse
    {
        return response()->stream(function () use ($messageId, $conversationId, $status): Generator {
            ignore_user_abort(true);
            set_time_limit(0);

            yield ": open\n\n";

            yield "event: message.start\ndata: ".json_encode([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ], JSON_THROW_ON_ERROR)."\n\n";

            if ($status === MessageStatus::Complete || $status === MessageStatus::Cancelled) {
                yield "event: message.complete\ndata: ".json_encode([
                    'message_id' => $messageId,
                    'finish_reason' => $status === MessageStatus::Cancelled ? 'cancelled' : 'stop',
                    // NO USAGE. The counts belong to the ORIGINAL turn and are already metered; a
                    // second report of them would be read by a client as this request's cost.
                    'usage' => null,
                ], JSON_THROW_ON_ERROR)."\n\n";

                return;
            }

            // `pending`, `streaming` or `failed`. A turn still in flight cannot be joined — the
            // tokens are going to the first caller's socket — so the honest answer is that this
            // submission produced no new answer and the client should re-read the transcript.
            yield "event: error\ndata: ".json_encode([
                'error_class' => 'validation',
                'message' => 'This message has already been sent. Reload the conversation to see '
                    .'its answer.',
                'retryable' => false,
            ], JSON_THROW_ON_ERROR)."\n\n";
        }, 200, $this->headers());
    }

    private function errorFrame(string $errorClass, string $message, bool $retryable, string $requestId): string
    {
        return "event: error\ndata: ".json_encode([
            // THE ONLY FIELD A CALLER BRANCHES ON. Never the HTTP status, which varies by surface
            // for one class.
            'error_class' => $errorClass,
            // OPERATOR-FACING and safe to log; never a provider message, because provider error
            // bodies routinely echo the request and the request contains the packed prompt.
            'message' => $message,
            'retryable' => $retryable,
            // ECHOES `X-KB-Request-Id`, so one failure is greppable across both services.
            'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR)."\n\n";
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            // `no-transform` AS WELL AS `no-cache`: a proxy that "optimises" a text body by gzipping
            // it withholds bytes until it has a worthwhile block, which turns a stream into one late
            // blob with nothing raised anywhere.
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            // A stream is one long-lived response; a keep-alive negotiation on top of it buys
            // nothing and confuses intermediaries that try to reuse the connection.
            'Connection' => 'close',
        ];
    }
}

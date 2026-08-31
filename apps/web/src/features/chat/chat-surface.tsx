'use client';

import { KbError, type SseFrame } from '@kb/contracts';
import { isRetrievalTraceEvent, type KbAdminEvent, type RetrievalTraceData } from '@kb/contracts/admin';
import { ArrowDownIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { SkeletonLines } from '@/components/states';
import type { Credential } from '@/lib/api/browser';

import {
  announcementFor,
  applyEvent,
  startAssistantTurn,
  type AssistantTurn,
  type Turn,
} from './chat-state';
import { AnswerActions, FailureNote, MetadataLine, RefusalNote, StoppedNote } from './answer-outcomes';
import { ChatEmptyState } from './empty-state';
import { Composer } from './composer';
import { SourcesPanel } from './sources-panel';
import { AssistantProse, StreamCaret, UserTurn } from './turns';
import { streamAnswer, streamWithReMint } from './stream-answer';

/**
 * Hosted chat, the widget panel, the mobile screen and the playground render THE SAME CHAT — not
 * four interpretations of one. This component is that chat for `apps/web`, and BOTH of the app's
 * surfaces mount it: hosted chat at `/c/[publicBotId]` with a chat-session bearer, and the admin
 * playground inside the bot editor's fourth tab.
 *
 * ── THE CLIENT TALKS ONLY TO LARAVEL ─────────────────────────────────────────────────────────────
 * There is no code path from here to FastAPI. `streamAnswer()` posts to `rt/v1` on the Laravel
 * origin and reads the normalized SSE schema back; adding any other destination is an architecture
 * violation rather than a shortcut.
 *
 * ── THE CONVERSATION IS OPENED LAZILY, FROM THE SUBMIT HANDLER ───────────────────────────────────
 * `connection.ensureConversation()` resolves an id, CREATING one on first use. It is called from the
 * submit handler and never from an effect, for the reason the send is: React StrictMode invokes an
 * effect twice in development and it re-runs on any remount, and `POST /rt/v1/conversations` WRITES
 * A ROW — two conversations, one of them empty forever, in the customer's own analytics. It also
 * means a visitor who opens the page and leaves costs nothing at all.
 *
 * ── ONE `setState` PER FRAME, NOT PER TOKEN ──────────────────────────────────────────────────────
 * See `useTokenBuffer` below. This is the fix `stream-answer.ts` documents as required and which
 * this component did not do.
 */
export interface ChatConnection {
  /**
   * Resolves the conversation id AND the credential it is addressed with, creating both on FIRST
   * use and returning the same pair afterwards.
   *
   * ── THE CREDENTIAL COMES BACK FROM HERE RATHER THAN SITTING BESIDE IT, FOR TWO REASONS ────────
   * It is TAKEN AS AN ARGUMENT AND NEVER ASSUMED — the admin console holds the session cookie plus
   * `X-XSRF-TOKEN`, hosted chat holds an opaque bearer and NO cookie, and in local development all
   * three hosts are localhost on different ports, so a surface that assumed one passes every dev
   * test and 401s in production on exactly the other. That much was true when this was a plain
   * field.
   *
   * What the field could not express is that on hosted chat the credential DOES NOT EXIST YET at
   * first render: the `sdk/v1` mint is deferred to the first send, because a session is a credential
   * and drawing a composer is not a reason to issue one. A field forced the caller to hand over a
   * placeholder bearer — a credential-shaped lie one refactor away from being sent.
   *
   * IT MUST BE IDEMPOTENT ACROSS CALLS AND IT IS THE CALLER'S JOB TO MAKE IT SO. This component
   * calls it once per send; a caller that opened a new thread on every call would bill each turn to
   * a conversation of its own and make the transcript unreadable.
   */
  readonly connect: () => Promise<{
    readonly conversationId: string;
    readonly credential: Credential;
  }>;
  /**
   * THE ADMIN PLAYGROUND'S WIDER FRAME DECODER, and absent everywhere else.
   *
   * Omitted, `streamAnswer` defaults to `toKbEvent` and a `retrieval.trace` frame is not merely
   * unrendered here — it is unrepresentable, because the generator's yield type is the public six.
   * Supplied (`toKbAdminEvent` from `@kb/contracts/admin`), the trace reaches `onTrace` below and
   * nothing else changes. There is ONE frame parser either way; what varies is the event-name
   * allow-list on top of it.
   */
  readonly decode?: (frame: SseFrame) => KbAdminEvent | null;
  /**
   * REPLACE THE CREDENTIAL THE SERVER JUST REFUSED, keeping the same conversation. Supplied by the
   * admin playground and by nothing else today.
   *
   * It is called on exactly one condition — `error_class: authentication`, raised before the first
   * event of a send — and at most once per send. `streamWithReMint` owns those guards and its
   * docblock carries all four of them; what this field decides is only whether a second attempt is
   * possible at all. Omitted, the send behaves byte-identically to calling `streamAnswer` directly,
   * which is what hosted chat does.
   *
   * IT MUST RETURN THE SAME `conversationId`. A playground conversation is owned by
   * `conversations.user_id`, so a fresh mint for the same administrator still owns the thread;
   * opening a second one here would fragment one operator's test run across two rows in the
   * tenant's own analytics.
   */
  readonly reMint?: () => Promise<{
    readonly conversationId: string;
    readonly credential: Credential;
  }>;
}

export function ChatSurface({
  botName,
  corpusLine,
  suggestions = [],
  connection,
  model,
  onTrace,
  onFeedback,
  notConnected,
}: {
  readonly botName: string;
  readonly corpusLine?: string;
  readonly suggestions?: readonly string[];
  /** `null` disables the composer and renders `notConnected` in place. */
  readonly connection: ChatConnection | null;
  /** Shown in the metadata line only where the bot is configured to expose it. */
  readonly model?: string;
  /**
   * The playground's diagnostics sink. Called once per `retrieval.trace` frame, with the
   * CLIENT-MINTED turn id so a panel can key a trace to the turn it explains rather than to
   * "whichever was last".
   */
  readonly onTrace?: (turnId: string, trace: RetrievalTraceData) => void;
  /**
   * The thumbs, and the SERVER's message id rather than the client-minted turn id: feedback
   * addresses `POST /rt/v1/messages/{message}/feedback`, which is a row that does not exist until
   * `message.start` arrives. The control is gated on `turn.messageId !== null` for that reason —
   * a stream that failed before the first frame has nothing to rate, and offering a button that
   * would 404 is worse than offering none.
   *
   * Omitted, the thumbs are not rendered at all. That is the honest state for a surface with no
   * feedback endpoint rather than a pair of buttons that silently do nothing.
   */
  readonly onFeedback?: (messageId: string, verdict: 'up' | 'down', reason?: string) => void;
  /**
   * What to render instead of a composer when `connection` is null. A caller's node rather than a
   * fixed string, because the two surfaces cannot honestly say the same thing: hosted chat is
   * looking at a byte-identical 404 whose cause it must not guess, and the playground is looking at
   * a bot that has not been configured.
   */
  readonly notConnected?: ReactNode;
}) {
  const [turns, setTurns] = useState<readonly Turn[]>([]);
  const [draft, setDraft] = useState('');
  const [streaming, setStreaming] = useState(false);
  const abortRef = useRef<AbortController | null>(null);
  const startedAtRef = useRef<number>(0);
  const [elapsedMs, setElapsedMs] = useState<number | undefined>(undefined);

  const { scrollRef, pinned, jumpToLatest, onScroll } = useAutoscroll(turns);

  const last = turns.at(-1);
  const lastAssistant = last?.kind === 'assistant' ? last : null;

  const send = useCallback(
    async (content: string) => {
      if (connection === null || content.trim() === '') return;

      // A STABLE per-message id, not a per-render one: it is the idempotency key Laravel uses to
      // collapse a genuine duplicate — a double submit, a StrictMode double effect, a reconnect —
      // into one conversation, one provider call, one bill. It is also what reconciles the
      // optimistic user turn with the server's echo, so the turn cannot appear twice.
      const clientMessageId = crypto.randomUUID();

      setDraft('');
      setElapsedMs(undefined);
      startedAtRef.current = performance.now();
      setTurns((current) => [
        ...current,
        { kind: 'user', id: clientMessageId, text: content },
        startAssistantTurn(clientMessageId),
      ]);
      setStreaming(true);

      const controller = new AbortController();
      abortRef.current = controller;

      const update = (apply: (turn: AssistantTurn) => AssistantTurn) =>
        setTurns((current) =>
          current.map((turn) =>
            turn.kind === 'assistant' && turn.id === clientMessageId ? apply(turn) : turn,
          ),
        );

      /**
       * ── THE TOKEN BUFFER, AND IT IS ONE `setState` PER ANIMATION FRAME ────────────────────────
       *
       * One `setState` per `token` event is one React render per token over a GROWING transcript,
       * which is the shape that makes the composer drop keystrokes mid-answer: at 40 tokens/second
       * over a 600-token answer that is 600 renders of an ever-longer prose tree, and the input
       * handler queues behind every one of them.
       *
       * Buffering into a string and flushing on `requestAnimationFrame` collapses a frame's worth of
       * tokens into one update, which is the most a display can show anyway. `stream-answer.ts`
       * documents this as required; it was not done, and this is it.
       *
       * ── THE FLUSH GOES THROUGH `applyEvent`, NOT AROUND IT ────────────────────────────────────
       * A hand-written `text: turn.text + chunk` here would be a SECOND place that decides what a
       * token does — and the first thing it would get wrong is the `phase: 'streaming'` transition,
       * which is what removes the working skeleton and shows the caret. One reducer, one arm.
       *
       * ── AND IT FLUSHES SYNCHRONOUSLY BEFORE EVERY NON-TOKEN EVENT ─────────────────────────────
       * `message.complete` arriving while a frame is still pending would settle the turn, drop the
       * caret and reveal the actions over TRUNCATED text, and the buffered tail would then append
       * underneath a settled answer. Draining first makes the ordering exact rather than
       * probabilistic — and "probabilistic" here means it works on every machine that renders faster
       * than the network, which is every developer's.
       */
      let buffered = '';
      let frame: number | null = null;

      const flush = (): void => {
        frame = null;
        if (buffered === '') return;
        const text = buffered;
        buffered = '';
        update((turn) => applyEvent(turn, { event: 'token', data: { text } }));
      };

      const drain = (): void => {
        if (frame !== null) cancelAnimationFrame(frame);
        flush();
      };

      try {
        // SENT FROM THE SUBMIT HANDLER, never a useEffect: StrictMode invokes an effect twice in
        // development and it re-runs on any remount — two conversations, two provider calls, two
        // bills. `connect()` is awaited FIRST inside `streamWithReMint`, so a failure to mint a
        // session or open the thread is reported on the turn the user just typed rather than as a
        // silent no-op — and so the composer's `AbortController` already exists when it runs.
        //
        // `streamWithReMint` IS `streamAnswer` PLUS ONE CREDENTIAL REPLACEMENT, and with no
        // `reMint` it is `streamAnswer` exactly — the `client_message_id` below is minted once and
        // is the same on both attempts, which is what makes a second post an idempotent replay
        // rather than a second bill.
        for await (const event of streamWithReMint<KbAdminEvent>({
          open: connection.connect,
          ...(connection.reMint === undefined ? {} : { reMint: connection.reMint }),
          run: ({ conversationId, credential }) =>
            streamAnswer<KbAdminEvent>({
              conversationId,
              body: { client_message_id: clientMessageId, content },
              credential,
              signal: controller.signal,
              // `toKbEvent` when absent. See `ChatConnection.decode`.
              ...(connection.decode === undefined ? {} : { decode: connection.decode }),
            }),
        })) {
          if (event.event === 'token') {
            buffered += event.data.text;
            if (frame === null) frame = requestAnimationFrame(flush);
            continue;
          }

          // Everything below changes the turn's SHAPE rather than its text, so the buffered tail
          // has to land first — see the note above.
          drain();

          if (isRetrievalTraceEvent(event)) {
            // DIAGNOSTICS ARE NOT A TURN STATE. The trace explains an answer; it is not part of one,
            // and putting it on the transcript would put internal topology one `JSON.stringify`
            // away from a surface that also renders model output. It leaves through the sink and
            // the transcript never holds it.
            onTrace?.(clientMessageId, event.data);
            continue;
          }

          update((turn) => applyEvent(turn, event));
        }
      } catch (cause) {
        drain();
        // An abort is not a failure: `streamAnswer` is torn down and the server settles the row as
        // `cancelled`, which is the STOPPED outcome and keeps whatever already streamed.
        if (controller.signal.aborted) {
          update((turn) => ({ ...turn, phase: 'settled', outcome: 'stopped' }));
        } else {
          const error =
            cause instanceof KbError ? cause : new KbError(null, false, null, null, String(cause));
          update((turn) => ({ ...turn, phase: 'settled', outcome: 'failed', error }));
        }
      } finally {
        drain();
        setStreaming(false);
        abortRef.current = null;
        setElapsedMs(performance.now() - startedAtRef.current);
      }
    },
    [connection, onTrace],
  );

  const stop = useCallback(() => abortRef.current?.abort(), []);

  /**
   * REGENERATE, and it is a NEW turn rather than a replay.
   *
   * It re-sends the question with a FRESH `client_message_id`, which is the only shape that can
   * work: the old id is an idempotency key, so re-posting it replays the PERSISTED state and makes
   * no provider call — which is exactly right for a reconnect and exactly wrong for "answer that
   * again". `stream-answer.ts` says the same thing about the `stream_lost` path and for the same
   * reason.
   *
   * THE QUESTION COMES FROM THE PAIRED USER TURN, looked up by the shared id, because the assistant
   * turn does not hold it — and duplicating it there would be a second copy of a string the
   * transcript already has, which is how the two come to disagree after an edit.
   *
   * The old pair is left on screen. Discarding it would throw away an answer the tenant was billed
   * for, which is the same argument that makes Stop keep its partial text.
   */
  const regenerate = useCallback(
    (turnId: string) => {
      const question = turns.find((turn) => turn.kind === 'user' && turn.id === turnId);
      if (question === undefined || question.kind !== 'user') return;
      void send(question.text);
    },
    [send, turns],
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-4">
      {/* THE LIVE REGION ANNOUNCES STATE TRANSITIONS, NOT TOKENS. A region updated per token makes a
          screen reader stutter unusably; announcing the state and leaving the answer as ordinary
          navigable content is what actual screen-reader users prefer. The rAF buffer does not change
          that — it reduces renders, and this region is deliberately not one of the things a render
          updates. */}
      <p aria-live="polite" className="sr-only">
        {lastAssistant ? announcementFor(lastAssistant) : ''}
      </p>

      {connection === null ? (notConnected ?? <NotConnectedNotice />) : null}

      <div
        ref={scrollRef}
        onScroll={onScroll}
        className="relative flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto"
      >
        {turns.length === 0 ? (
          <ChatEmptyState
            botName={botName}
            corpusLine={corpusLine}
            suggestions={suggestions}
            disabled={connection === null}
            onPick={(suggestion) => void send(suggestion)}
          />
        ) : (
          turns.map((turn) => {
            if (turn.kind === 'user') return <UserTurn key={`u-${turn.id}`} text={turn.text} />;

            // A LOCAL BINDING, because narrowing a readonly property does not survive into a
            // closure: `turn.messageId !== null` in the ternary tells TypeScript nothing about the
            // arrow function's later read of it, and the repair people reach for is a non-null
            // assertion — which is the one spelling that would still compile after the field
            // legitimately becomes optional.
            const messageId = turn.messageId;
            const rate = onFeedback;

            return (
              <AssistantTurnView
                key={`a-${turn.id}`}
                turn={turn}
                model={model}
                elapsedMs={turn.phase === 'settled' ? elapsedMs : undefined}
                // OFFERED ONLY ON A SETTLED TURN AND ONLY WHEN THERE IS A CONNECTION. Regenerating
                // is a paid provider call, so a disabled surface must not offer one, and a turn that
                // is still streaming has Stop as the only control that matters.
                onRegenerate={
                  connection !== null && turn.phase === 'settled'
                    ? () => regenerate(turn.id)
                    : undefined
                }
                onFeedback={
                  connection !== null && messageId !== null && rate !== undefined
                    ? (verdict, reason) => rate(messageId, verdict, reason)
                    : undefined
                }
              />
            );
          })
        )}
      </div>

      {/* Surrendered autoscroll gets an explicit way back, rather than dragging the reader down. */}
      {pinned ? null : (
        <div className="flex justify-center">
          <Button variant="outline" size="sm" className="rounded-full shadow-lg" onClick={jumpToLatest}>
            <ArrowDownIcon aria-hidden />
            Jump to latest
          </Button>
        </div>
      )}

      <Composer
        value={draft}
        onChange={setDraft}
        onSubmit={() => void send(draft)}
        onStop={stop}
        streaming={streaming}
        disabled={connection === null}
      />
    </div>
  );
}

/** The default `notConnected` node: honest, and it guesses nothing. */
function NotConnectedNotice() {
  return (
    <Alert variant="info">
      <AlertTitle>This chat is not connected</AlertTitle>
      <AlertDescription>
        Messages cannot be sent from here yet.
      </AlertDescription>
    </Alert>
  );
}

function AssistantTurnView({
  turn,
  model,
  elapsedMs,
  onRegenerate,
  onFeedback,
}: {
  readonly turn: AssistantTurn;
  readonly model?: string;
  readonly elapsedMs?: number;
  readonly onRegenerate?: () => void;
  readonly onFeedback?: (verdict: 'up' | 'down', reason?: string) => void;
}) {
  const settled = turn.phase === 'settled';

  return (
    <div className="flex flex-col gap-3">
      {/* WORKING: a skeleton plus ONE honest label. Not a percentage, not a stage counter, and not
          the internal stage names. */}
      {turn.phase === 'sent' || turn.phase === 'working' ? (
        <div className="flex flex-col gap-2">
          <p className="text-sm text-muted-foreground">{turn.label}</p>
          <SkeletonLines lines={2} />
        </div>
      ) : null}

      {turn.text === '' ? null : (
        <div className="text-md">
          <AssistantProse text={turn.text} />
          {turn.phase === 'streaming' ? <StreamCaret /> : null}
        </div>
      )}

      {/* Sources resolve on settle, with the answer. */}
      {settled && turn.outcome !== 'failed' ? <SourcesPanel citations={turn.citations} /> : null}

      {settled && turn.outcome === 'refused' ? <RefusalNote /> : null}
      {settled && turn.outcome === 'stopped' ? <StoppedNote onRegenerate={onRegenerate} /> : null}
      {settled && turn.outcome === 'failed' && turn.error !== null ? (
        <FailureNote error={turn.error} onRetry={onRegenerate} />
      ) : null}

      {/* ACTIONS APPEAR ON A SETTLED TURN ONLY, and never on a failure — there is nothing to copy or
          rate. Hidden while streaming, when Stop is the only control that matters. */}
      {settled && turn.outcome !== 'failed' && turn.text !== '' ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <AnswerActions text={turn.text} onRegenerate={onRegenerate} onFeedback={onFeedback} />
          <MetadataLine model={model} elapsedMs={elapsedMs} />
        </div>
      ) : null}
    </div>
  );
}

/**
 * AUTOSCROLL FOLLOWS ONLY WHILE THE VIEWPORT IS ALREADY PINNED TO THE BOTTOM, AND SURRENDERS ON THE
 * FIRST UPWARD SCROLL.
 *
 * A view that drags the reader away from the paragraph they scrolled back to re-read is the single
 * most complained-about behaviour in a streaming UI. The surrender lasts for the rest of the message
 * — it is not re-armed by the next token — and "Jump to latest" is the way back.
 *
 * The threshold is generous because a streaming layout grows under the scroll position: an exact
 * comparison flips to "not pinned" on its own the moment a line wraps.
 */
function useAutoscroll(turns: readonly Turn[]) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const [pinned, setPinned] = useState(true);

  const onScroll = useCallback(() => {
    const element = scrollRef.current;
    if (!element) return;
    const distance = element.scrollHeight - element.scrollTop - element.clientHeight;
    setPinned(distance < 48);
  }, []);

  useEffect(() => {
    const element = scrollRef.current;
    if (!element || !pinned) return;
    element.scrollTop = element.scrollHeight;
  }, [turns, pinned]);

  const jumpToLatest = useCallback(() => {
    const element = scrollRef.current;
    if (!element) return;
    element.scrollTop = element.scrollHeight;
    setPinned(true);
  }, []);

  return { scrollRef, pinned, jumpToLatest, onScroll };
}

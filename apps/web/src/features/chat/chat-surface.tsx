'use client';

import { KbError } from '@kb/contracts';
import { ArrowDownIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

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
import { streamAnswer } from './stream-answer';

/**
 * Hosted chat, the widget panel, the mobile screen and the playground render THE SAME CHAT — not
 * four interpretations of one. This component is that chat for `apps/web`.
 *
 * ── THE CLIENT TALKS ONLY TO LARAVEL ─────────────────────────────────────────────────────────────
 * There is no code path from here to FastAPI. `streamAnswer()` posts to `rt/v1` on the Laravel
 * origin and reads the normalized SSE schema back; adding any other destination is an architecture
 * violation rather than a shortcut.
 *
 * ── THE RUNTIME IT NEEDS DOES NOT EXIST YET, AND THIS SAYS SO ────────────────────────────────────
 * A send needs a conversation id and a chat-session credential, both minted by the `sdk/v1`
 * bootstrap, which is deliberately out of scope. Rather than render a composer that silently does
 * nothing, `conversation === null` disables the composer and states the condition in place. Every
 * other state below is real and reachable the moment that endpoint lands.
 */
export interface ChatConversation {
  readonly id: string;
  readonly credential: Credential;
}

export function ChatSurface({
  botName,
  corpusLine,
  suggestions = [],
  conversation,
  model,
}: {
  readonly botName: string;
  readonly corpusLine?: string;
  readonly suggestions?: readonly string[];
  readonly conversation: ChatConversation | null;
  /** Shown in the metadata line only where the bot is configured to expose it. */
  readonly model?: string;
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
      if (conversation === null || content.trim() === '') return;

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

      try {
        // SENT FROM THE SUBMIT HANDLER, never a useEffect: StrictMode invokes an effect twice in
        // development and it re-runs on any remount — two conversations, two provider calls, two
        // bills.
        for await (const event of streamAnswer({
          conversationId: conversation.id,
          body: { client_message_id: clientMessageId, content },
          credential: conversation.credential,
          signal: controller.signal,
        })) {
          update((turn) => applyEvent(turn, event));
        }
      } catch (cause) {
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
        setStreaming(false);
        abortRef.current = null;
        setElapsedMs(performance.now() - startedAtRef.current);
      }
    },
    [conversation],
  );

  const stop = useCallback(() => abortRef.current?.abort(), []);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-4">
      {/* THE LIVE REGION ANNOUNCES STATE TRANSITIONS, NOT TOKENS. A region updated per token makes a
          screen reader stutter unusably; announcing the state and leaving the answer as ordinary
          navigable content is what actual screen-reader users prefer. */}
      <p aria-live="polite" className="sr-only">
        {lastAssistant ? announcementFor(lastAssistant) : ''}
      </p>

      {conversation === null ? (
        <Alert variant="info">
          <AlertTitle>This chat is not connected yet</AlertTitle>
          <AlertDescription>
            The chat runtime for this bot is not available on this deployment, so messages cannot be
            sent.
          </AlertDescription>
        </Alert>
      ) : null}

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
            disabled={conversation === null}
            onPick={(suggestion) => void send(suggestion)}
          />
        ) : (
          turns.map((turn) =>
            turn.kind === 'user' ? (
              <UserTurn key={`u-${turn.id}`} text={turn.text} />
            ) : (
              <AssistantTurnView
                key={`a-${turn.id}`}
                turn={turn}
                model={model}
                elapsedMs={turn.phase === 'settled' ? elapsedMs : undefined}
                onRegenerate={undefined}
              />
            ),
          )
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
        disabled={conversation === null}
      />
    </div>
  );
}

function AssistantTurnView({
  turn,
  model,
  elapsedMs,
  onRegenerate,
}: {
  readonly turn: AssistantTurn;
  readonly model?: string;
  readonly elapsedMs?: number;
  readonly onRegenerate?: () => void;
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
          <AnswerActions text={turn.text} onRegenerate={onRegenerate} />
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

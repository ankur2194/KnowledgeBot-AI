// Imported as a VALUE for exactly one purpose: `instanceof`. This hook never CONSTRUCTS a KbError
// — constructing one here would be the second-definition problem in miniature, since a locally
// built error with a plausible class is indistinguishable from a real one and is never right. It
// only carries the one `streamAnswer` threw, and it has to be able to recognise it: a type-only
// import erases at compile time and `error instanceof KbError` would not compile at all.
import { KbError } from '@kb/contracts';
import type { ChatSendBody, Citation, KbEvent } from '@kb/contracts';
import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { asOfflineError, getBearerToken, handleAuthenticationFailure } from '@/api/client';

import { streamAnswer } from './stream-answer';

/**
 * ONE AbortController, THREE cancel sources.
 *
 * A stream nobody is reading still bills tokens against the tenant's quota. Every one of these
 * three has to abort, and the abort is what makes it stop: the socket closes, Laravel's next write
 * fails, Laravel cancels its upstream call, FastAPI sees the disconnect and stops the provider.
 * Letting the socket die quietly is not equivalent — on the server side nothing distinguishes it
 * from a slow reader until a write is attempted, which may be seconds of generation later.
 *
 *  1. THE STOP BUTTON — the explicit user action.
 *  2. NAVIGATING AWAY — `useFocusEffect`'s cleanup. Note this fires on blur, not only on unmount:
 *     a stacked push leaves the chat screen MOUNTED, so an unmount-only cleanup never runs and the
 *     stream keeps going behind the screen the user is now looking at.
 *  3. APP STATE LEAVING 'active' — background, and on iOS also the app switcher and an incoming
 *     call ('inactive'). iOS suspends the socket on background anyway, but suspension is not
 *     cancellation: the server learns nothing, so the provider keeps generating. The explicit
 *     abort is the only thing that stops the bill.
 *
 * On return to foreground the UI reconciles rather than resumes: the transcript re-reads the
 * persisted message from Laravel. Nothing re-POSTs, because a re-POST re-runs a paid provider call.
 */

export type AnswerStreamStatus =
  | 'idle'
  | 'sending'
  | 'retrieving'
  | 'reranking'
  | 'generating'
  | 'done'
  | 'cancelled'
  /**
   * ITS OWN STATUS, not a flavour of 'error'. The phone genuinely lost the network — a lift, a
   * tunnel, airplane mode — and no request was ever answered, so there is no envelope, no
   * `error_class` and no `request_id` to show anyone. "You are offline" is a different sentence
   * with a different remedy than "something went wrong on our side", and collapsing the two trains
   * users to retry into a dead radio. The taxonomy has no class for it and must not grow one; see
   * `OfflineError` in src/api/client.ts.
   */
  | 'offline'
  | 'error';

export interface AnswerStreamState {
  readonly status: AnswerStreamStatus;
  /** Accumulated answer text. */
  readonly text: string;
  readonly citations: readonly Citation[];
  readonly messageId: string | null;
  /** The `KbError` from @kb/contracts — never a local copy. `stream_lost` here means the caller
   *  should re-read the persisted message and offer Retry with a NEW client_message_id. */
  readonly error: KbError | null;
}

const INITIAL: AnswerStreamState = {
  status: 'idle',
  text: '',
  citations: [],
  messageId: null,
  error: null,
};

export interface UseAnswerStream {
  readonly state: AnswerStreamState;
  /** Call from the SUBMIT HANDLER, never from a useEffect: an effect re-runs on any remount and
   *  runs twice under StrictMode — two conversations, two provider calls, two bills. */
  start(conversationId: string, body: ChatSendBody): Promise<void>;
  stop(): void;
  reset(): void;
}

/**
 * Test seams, and the same two `streamAnswer` already carries for the same reason: the read loop is
 * only worth testing against a REAL socket, and a fixture server binds an ephemeral port that no
 * build-time constant can know. Never set from application code — there is no runtime API-origin
 * override in this app, in any build type, because one hands a phishing host a live bearer token.
 */
export interface UseAnswerStreamOptions {
  readonly apiOrigin?: string;
  readonly idleGapMs?: number;
}

/**
 * One frame's worth of coalescing, and a canceller for it.
 *
 * `requestAnimationFrame` is what React Native provides and what this is sized against. A Jest
 * process has no frame loop, so the fallback keeps the same shape at roughly one frame; the point
 * of the buffer is that N token events become ONE setState, and that holds either way.
 */
function scheduleFrame(callback: () => void): () => void {
  if (typeof requestAnimationFrame === 'function') {
    const id = requestAnimationFrame(callback);
    return () => {
      cancelAnimationFrame(id);
    };
  }
  const id = setTimeout(callback, 16);
  return () => {
    clearTimeout(id);
  };
}

export function useAnswerStream(options: UseAnswerStreamOptions = {}): UseAnswerStream {
  const [state, setState] = useState<AnswerStreamState>(INITIAL);
  const controller = useRef<AbortController | null>(null);

  /**
   * THE RENDER BUDGET. One `setState` per `token` event is one React render per token over a
   * growing transcript, and the visible symptom is not a slow transcript — it is a composer that
   * drops keystrokes while the answer streams. Token text accumulates in this ref and is flushed
   * through `applyEvent` once per frame; a non-token event flushes first so ordering is preserved,
   * because `message.complete` landing before the last few tokens would render a finished answer
   * that is missing its ending.
   */
  const pendingText = useRef('');
  const cancelFrame = useRef<(() => void) | null>(null);

  /** Applies whatever text is buffered, synchronously. Safe to call when nothing is buffered. */
  const flushTokens = useCallback((): void => {
    cancelFrame.current?.();
    cancelFrame.current = null;
    const text = pendingText.current;
    if (text === '') return;
    pendingText.current = '';
    // Coalescing N token events into one carrying the concatenation is exactly equivalent under
    // `applyEvent`: it appends `data.text` and sets 'generating'. That equivalence is the reason
    // the buffer can live here rather than inside the reducer.
    setState((previous) => applyEvent(previous, { event: 'token', data: { text } }));
  }, []);

  const stop = useCallback((): void => {
    controller.current?.abort();
    controller.current = null;
  }, []);

  const reset = useCallback((): void => {
    stop();
    // The buffer is dropped, not flushed: a reset means the previous answer is gone, and flushing
    // would paint a few final tokens of it into a transcript that just cleared.
    cancelFrame.current?.();
    cancelFrame.current = null;
    pendingText.current = '';
    setState(INITIAL);
  }, [stop]);

  // CANCEL SOURCE 2 — navigating away. The cleanup runs on blur; see the note above about why an
  // unmount-only cleanup is not enough.
  useFocusEffect(
    useCallback(() => {
      return stop;
    }, [stop]),
  );

  // CANCEL SOURCE 3 — the app leaving the foreground.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next !== 'active') stop();
    });
    return () => {
      subscription.remove();
    };
  }, [stop]);

  const start = useCallback(
    async (conversationId: string, body: ChatSendBody): Promise<void> => {
      // 1. ONE STREAM AT A TIME. A second start while the first is still open is two open sockets
      //    and two provider calls billed against the same tenant for one screen, and the second
      //    answer interleaves into the first one's text.
      stop();
      cancelFrame.current?.();
      cancelFrame.current = null;
      pendingText.current = '';

      // 2. PRE-FLIGHT, BEFORE THE POST. `getBearerToken()` already returns null inside the 90 s
      //    expiry grace window, so this is not merely "have we ever logged in" — it is "can this
      //    token outlive the request we are about to make". Routing to login here keeps the
      //    composed paragraph in the composer's own state; discovering it from a 401 after the
      //    POST costs the user their draft and a round trip.
      const token = await getBearerToken();
      if (token === null) {
        handleAuthenticationFailure();
        return;
      }

      // 3. ONE CONTROLLER, captured in a local as well as in the ref, because "is this run still
      //    the one whose state may be written" and "was this run cancelled" are DIFFERENT
      //    questions and conflating them loses the Stop button's outcome.
      //
      //    `stop()` aborts and nulls the ref. A newer `start()` aborts, nulls, and then installs
      //    its own controller. So a null ref means THIS run was stopped — its 'cancelled' status is
      //    still ours to write — while a ref holding some OTHER controller means a newer run owns
      //    the state and this one must write nothing at all. Guarding on `!== run` alone would make
      //    a stopped stream stay at 'generating' forever, with a Stop button that visibly did
      //    nothing.
      const run = new AbortController();
      controller.current = run;
      const isSuperseded = (): boolean => controller.current !== null && controller.current !== run;

      setState({ ...INITIAL, status: 'sending' });

      try {
        // 4. THE READ LOOP. Every event goes through `applyEvent`, which is a pure reducer over the
        //    six client-facing events and the only thing that interprets them.
        for await (const event of streamAnswer({
          conversationId,
          body,
          token,
          signal: run.signal,
          apiOrigin: options.apiOrigin,
          idleGapMs: options.idleGapMs,
        })) {
          if (isSuperseded()) break;

          if (event.event === 'token') {
            pendingText.current += event.data.text;
            cancelFrame.current ??= scheduleFrame(flushTokens);
            continue;
          }

          // Anything that is not a token is rare and is ordered against the text around it, so the
          // buffer is drained first. `message.complete` arriving before the last tokens would show
          // a finished answer missing its final words.
          flushTokens();
          setState((previous) => applyEvent(previous, event));
        }

        if (isSuperseded()) return;
        flushTokens();

        // Cancellation is an OUTCOME, not a failure: `streamAnswer` returns rather than throwing on
        // an AbortError, so the loop simply ends with no terminal event applied. 499 /
        // `user_cancellation`, no toast, no retry, no error span.
        if (run.signal.aborted) {
          setState((previous) => ({ ...previous, status: 'cancelled' }));
        }
      } catch (error) {
        // Whatever text did arrive is real and paid for; it is flushed before the failure is
        // rendered so a partial answer is not silently discarded by its own error path.
        flushTokens();
        if (isSuperseded()) return;

        // 5a. OFFLINE. Never `internal_dependency`, which is retryable AND pages. The
        //     classification is made by src/features/chat/stream-answer.ts around the `fetch` call
        //     itself; the `asOfflineError` here is the second line, for a transport failure raised
        //     by any other path. It is deliberately NOT applied to arbitrary errors from the loop
        //     body — a `TypeError` from our own code must stay a bug.
        const offline = asOfflineError(error);
        if (offline !== null) {
          setState((previous) => ({ ...previous, status: 'offline' }));
          return;
        }

        if (error instanceof KbError) {
          // 5b. AUTHENTICATION IS TERMINAL AND IS NEVER RETRIED. The token was revoked from another
          //     device or expired mid-session; the class is non-retryable, and a retry loop against
          //     a dead token is how a device gets rate-limited out of its own login endpoint. The
          //     handler purges SecureStore, replaces the QueryClient, purges the on-disk transcript
          //     cache and routes to login; the draft survives in the composer's own state.
          if (error.error_class === 'authentication') {
            handleAuthenticationFailure();
          }
          // The KbError is carried INTACT — including `stream_lost`, which tells the caller to
          // re-read the persisted message from Laravel and only then offer Retry with a NEW
          // client_message_id. Re-POSTing the old one re-runs a paid provider call.
          setState((previous) => ({ ...previous, status: 'error', error }));
          return;
        }

        // 5c. Anything else is unknown, and unknown is permanent. `error` stays null rather than
        //     being wrapped: a KbError built here would carry a class this app invented, and a
        //     fabricated class is indistinguishable from a real one to every retry decision above.
        setState((previous) => ({ ...previous, status: 'error', error: null }));
      } finally {
        if (controller.current === run) controller.current = null;
      }
    },
    [flushTokens, options.apiOrigin, options.idleGapMs, stop],
  );

  return { state, start, stop, reset };
}

/**
 * Pure reducer over the six client-facing events, exported so it can be tested without a renderer.
 * The `error` event never reaches here — streamAnswer throws it as a KbError.
 */
export function applyEvent(state: AnswerStreamState, event: KbEvent): AnswerStreamState {
  switch (event.event) {
    case 'message.start':
      return { ...state, status: 'generating', messageId: event.data.message_id };
    case 'status':
      return { ...state, status: event.data.stage };
    case 'citations':
      // Always before the first token: citations are assigned from retrieved evidence
      // pre-generation, never parsed out of model output.
      return { ...state, citations: event.data.citations };
    case 'token':
      // `text` is the token EVENT's field. The REQUEST body's field is `content`; this is the one
      // legitimate `text:` in the whole chat path.
      return { ...state, status: 'generating', text: state.text + event.data.text };
    case 'message.complete':
      return {
        ...state,
        status: event.data.finish_reason === 'cancelled' ? 'cancelled' : 'done',
        messageId: event.data.message_id,
      };
    default:
      return state;
  }
}

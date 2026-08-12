/**
 * THE STREAM PROBE — test-only, and deliberately outside src/.
 *
 * It exists to put the REAL `streamAnswer()` in front of a REAL socket in a REAL browser, on our
 * origin, talking cross-origin to the API origin. That is the only arrangement in which the read
 * loop's production-only failures exist at all: a frame split between its two newlines, a UTF-8
 * codepoint split across a TCP boundary, a peer that vanishes without a terminal event, a bearer
 * that expires mid-conversation. A mocked response cannot chunk a `text/event-stream`, and a
 * same-process unit test cannot cut a socket.
 *
 * WHY A PROBE AND NOT THE APP. `src/app/app.tsx`'s submit handler is still a stub: sending from the
 * UI needs a conversation, which needs `POST rt/v1/conversations`, which does not exist. Waiting
 * for that would leave the read loop unproven while it is written. So the probe stands in for the
 * one call site the app will eventually have — and it stands in for it WITHOUT adding a branch to
 * src/, which is the property that keeps the e2e suite honest: nothing here can make a spec pass
 * that the shipped code would fail.
 *
 * It imports the shipped modules unchanged: `streamAnswer` (src/app/stream.ts), the session module
 * that owns the bearer and the refresh gate (src/app/session.ts), and the lazy renderer edge
 * (src/app/lazy-renderer.ts), which is how `token` text is allowed to reach the DOM.
 */
import type { KbEvent } from '@kb/contracts';
import { KbError } from '@kb/contracts';

import { renderAssistantMarkdown } from '../../../src/app/lazy-renderer.js';
import { setSession } from '../../../src/app/session.js';
import { streamAnswer } from '../../../src/app/stream.js';

interface ProbeRequest {
  readonly conversationId: string;
  readonly clientMessageId: string;
  readonly content: string;
  readonly apiOrigin: string;
  readonly token: string;
  readonly expiresIn?: number;
  readonly idleGapMs?: number;
  /** Abort the controller once this many events have been yielded — the stop-generation path. */
  readonly abortAfterEvents?: number;
  /**
   * What the LOADER would send back after a `session-expiring` ping. Supplying it here is exactly
   * what the loader's `send('session', { session })` → `onSession` → `setSession` chain does in
   * production; the probe never mints anything, because the frame never can.
   */
  readonly refreshWith?: { token: string; expires_in: number } | null;
  readonly refreshDelayMs?: number;
}

interface ProbeResult {
  readonly events: ReadonlyArray<{ event: string; data: unknown }>;
  readonly error: {
    name: string;
    error_class: string | null;
    retryable: boolean | null;
    retry_after: number | null;
    request_id: string | null;
    message: string;
  } | null;
  /** How many times the frame asked the loader to re-mint. One, or the single-flight gate is off. */
  readonly refreshRequests: number;
  /** The DOM after every `token` went through `renderAssistantMarkdown()`. */
  readonly rendered: string;
  readonly renderedText: string;
}

async function run(options: ProbeRequest): Promise<ProbeResult> {
  setSession({ token: options.token, expires_in: options.expiresIn ?? 1800 });

  const controller = new AbortController();
  const events: Array<{ event: string; data: unknown }> = [];
  let refreshRequests = 0;
  let text = '';

  const container = document.getElementById('kb-probe-output');
  if (container === null) throw new Error('probe container missing');
  container.replaceChildren();

  const stream = streamAnswer({
    conversationId: options.conversationId,
    body: { client_message_id: options.clientMessageId, content: options.content },
    signal: controller.signal,
    apiOrigin: options.apiOrigin,
    ...(options.idleGapMs === undefined ? {} : { idleGapMs: options.idleGapMs }),
    // `bridge.requestSessionRefresh` in the app. The frame ASKS; the loader acts.
    onSessionExpiring: () => {
      refreshRequests += 1;
      const grant = options.refreshWith;
      if (grant === undefined || grant === null) return;
      setTimeout(() => setSession(grant), options.refreshDelayMs ?? 20);
    },
  });

  let failure: ProbeResult['error'] = null;
  try {
    for await (const event of stream as AsyncGenerator<KbEvent>) {
      events.push({ event: event.event, data: event.data });
      if (event.event === 'token') {
        text += (event.data as { text: string }).text;
        // THE ONLY WAY ASSISTANT TEXT REACHES THE DOM. Never innerHTML, never a second sanitizer
        // configuration — markdown-it(html:false) → DOMPurify(array config, RETURN_DOM_FRAGMENT) →
        // replaceChildren, in the lazy chunk.
        await renderAssistantMarkdown(container, text);
      }
      if (options.abortAfterEvents !== undefined && events.length >= options.abortAfterEvents) {
        controller.abort();
      }
    }
  } catch (error) {
    failure =
      error instanceof KbError
        ? {
            name: error.name,
            error_class: error.error_class,
            retryable: error.retryable,
            retry_after: error.retry_after,
            request_id: error.request_id,
            message: error.message,
          }
        : {
            name: error instanceof Error ? error.name : 'unknown',
            error_class: null,
            retryable: null,
            retry_after: null,
            request_id: null,
            message: error instanceof Error ? error.message : String(error),
          };
  }

  return {
    events,
    error: failure,
    refreshRequests,
    rendered: container.innerHTML,
    renderedText: container.textContent ?? '',
  };
}

(window as unknown as Record<string, unknown>)['__kbProbe'] = { run };

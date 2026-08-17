// Runtime imports from the shared package. `KbError`, `createFrameBuffer`, `toKbEvent` and
// `toKbError` are real code there, not types, and this app forks none of them: a second KbError
// breaks `instanceof` and every retry decision made against it in lib/query/client.ts, and a
// second SSE parser is the drift packages/contracts exists to prevent.
import {
  KbError,
  STREAM_LOST,
  createFrameBuffer,
  isKbErrorEnvelope,
  isTerminalEvent,
  toKbError,
  toKbEvent,
} from '@kb/contracts';
import type { ChatSendBody, KbEvent } from '@kb/contracts';

import type { Credential } from '@/lib/api/browser';
import { API_ORIGIN } from '@/lib/env';

/**
 * The streaming send. ONE function serves both surfaces, and it takes the credential as an
 * argument so a caller cannot inherit the wrong one: the admin console sends the session cookie
 * plus X-XSRF-TOKEN, hosted chat sends the opaque chat-session token as a bearer and no cookie at
 * all (nextjs-app-router NN8).
 *
 * One options object, never positional arguments — the argument order is exactly the thing a
 * caller gets wrong, and `apiOrigin` exists solely so the SSE fixture server has a supported way
 * in. It is a test seam: set it in application code and a chat send goes to an origin the session
 * cookie was never scoped to, which fails as a 401 in production and passes everywhere else.
 *
 * Nothing enforces the "declared here, set nowhere" half — no lint rule, and no CI step
 * over apps/. Reviewer check; the plain `rg apiOrigin src/` returns mostly this prose, so drop
 * comment lines:
 *
 *   grep -rn 'apiOrigin' apps/web/src | grep -vE ':[0-9]+:[[:space:]]*(\*|//)'
 *
 * TWO hits, both in this file and both required (verified): the declaration below, and the
 * `options.apiOrigin ?? API_ORIGIN` that reads it. A THIRD hit is the defect — a caller passing the
 * option — and it is what the check is looking for.
 *
 * NOT a Server Action, for four independent reasons: there is no supported way to abort an
 * in-flight action, so the stop button would stop the UI while the provider kept generating and
 * usage was never finalized as `cancelled`; Next dispatches actions sequentially, one at a time per
 * client, so one generation blocks every other action for the length of the answer; ReadableStream
 * is not among React's documented serializable return values, so `citations`-before-first-`token`,
 * the `: ping` heartbeats and the `error_class` envelope would all be lost; and it puts the Next
 * process in the middle of a minutes-long stream for zero authorization benefit, since the browser
 * already holds the credential.
 *
 * NEVER the browser's built-in SSE client: it is GET-only, its constructor's only option is
 * `withCredentials` so it carries neither our POST body nor a header, and its automatic
 * `Last-Event-ID` reconnect is forbidden because token streams are not resumable. Its name is
 * absent from this package on purpose — CI greps for it (nextjs-app-router NN3).
 */
export interface StreamAnswerOptions {
  readonly conversationId: string;
  /** Exactly `{client_message_id, content}`. `text` is the token EVENT's field; posting it 422s
   *  every send. `client_message_id` is a stable per-message UUID so Laravel's idempotency key
   *  collapses a genuine duplicate. */
  readonly body: ChatSendBody;
  readonly credential: Credential;
  /** From an AbortController owned by the composer — the §8.18 stop-generation action. Aborting
   *  must produce one request, one `finish_reason: "cancelled"` message row and one usage row. */
  readonly signal: AbortSignal;
  /** Test seam only: the SSE fixture server's origin. Never set in application code. */
  readonly apiOrigin?: string;
  /** Test seam only: shortens the idle watchdog so a test need not wait 45 seconds. */
  readonly idleGapMs?: number;
}

/**
 * The idle-gap watchdog, sized off the server's 15 s `: ping` — three missed heartbeats.
 *
 * DELIBERATELY NOT `AbortSignal.timeout()`. That aborts the WHOLE request including the streamed
 * body, so it kills a healthy long generation at a fixed elapsed time; the symptom is "long answers
 * truncate at exactly N seconds" and it never reproduces on a short one. A streaming request has no
 * total-duration timeout — the server owns that, via its own deadline. What is detectable from here
 * is SILENCE, and any byte at all resets it, including a heartbeat comment that dispatches nothing.
 */
const IDLE_GAP_MS = 45_000;

/** The public runtime surface. NOT `api/v1` — that prefix is the admin group, which carries the
 *  Sanctum cookie session and PreventRequestForgery and is built to reject a bearer
 *  (services/core-api/bootstrap/app.php). `rt/v1` sits outside `api/` for exactly that reason. */
const path = (conversationId: string): string =>
  `/rt/v1/conversations/${encodeURIComponent(conversationId)}/messages`;

/**
 * Yields the six client-facing events in order. Implementation invariants, all of which have a
 * production-only failure mode:
 *
 *  - `TextDecoder.decode(value, { stream: true })`. Without `{ stream: true }` each chunk is
 *    treated as a complete input and the trailing bytes of a codepoint split across a TCP boundary
 *    are dropped — corruption that scales with chunk count, so it only ever appears in German,
 *    Hindi or emoji, on exactly the long answers people notice.
 *  - `createFrameBuffer()` from @kb/contracts for re-assembly. Never split on '\n\n' inline: a
 *    frame arrives split between the two newlines often enough to matter and never in a fixture.
 *  - `: ping` never surfaces as an event (the parser already returns null for it).
 *  - Exactly one terminal event. If the stream ends without `message.complete` or `error`, raise a
 *    KbError carrying the CLIENT-LOCAL `stream_lost` sentinel and offer Retry. `stream_lost` is
 *    never sent to a server, never metricked, and is not a 19th error class.
 *  - Send from the SUBMIT HANDLER, not a useEffect: StrictMode invokes an effect twice in
 *    development and it re-runs on any remount — two conversations, two provider calls, two bills.
 *  - Buffer tokens and flush on requestAnimationFrame. One setState per token is one React render
 *    per token over a growing transcript, and the composer starts dropping keystrokes.
 */
export async function* streamAnswer(options: StreamAnswerOptions): AsyncGenerator<KbEvent> {
  const origin = options.apiOrigin ?? API_ORIGIN;
  const idleGapMs = options.idleGapMs ?? IDLE_GAP_MS;

  const response = await fetch(`${origin}${path(options.conversationId)}`, {
    method: 'POST', // exactly why the browser's built-in SSE client is unusable: GET-only, no headers
    headers: streamHeaders(options.credential),
    ...credentialsFor(options.credential),
    body: JSON.stringify(options.body),
    signal: options.signal,
    cache: 'no-store',
  });

  // BRANCH ON THE CONTENT TYPE, NOT THE STATUS. A failure arrives as the JSON error envelope rather
  // than as SSE, and the status alone cannot tell you which you are holding: one class renders as
  // different statuses per surface (`authorization` is 403 on admin and 404 on public), so
  // status-driven logic reads an authorization 404 as "absent, so create it". A 200 that is not
  // `text/event-stream` is also possible — a captive portal, a proxy error page — and reading that
  // with the frame parser yields a stream that never produces an event and never terminates.
  const contentType = response.headers.get('content-type');
  if (!response.ok || contentType === null || !contentType.startsWith('text/event-stream')) {
    throw await toKbError(response);
  }

  // `Response.body` is null for a HEAD/204 and in any runtime whose fetch is not stream-backed.
  // It does not throw and it does not warn — the answer would simply arrive all at once, several
  // seconds late, while every assertion about the final text still passed. OUR bug, so `error_class`
  // is null (unknown, permanent) and explicitly NOT `internal_dependency`, which is retryable and
  // pages.
  if (response.body === null) {
    throw new KbError(
      null,
      false,
      null,
      null,
      'response.body is null: this request was not read as a stream, so the answer would arrive all at once',
    );
  }

  const reader = response.body.getReader();
  // UTF-8 only, and that is all our wire is. `{ stream: true }` on every decode is load-bearing:
  // without it each chunk is treated as a complete input and the trailing bytes of a codepoint that
  // straddled a TCP boundary are dropped. Corruption scales with chunk count, so English looks
  // perfect and German, Hindi and emoji rot in proportion to answer length.
  const decoder = new TextDecoder();
  // Frame re-assembly is the shared implementation. Never split on '\n\n' inline: a frame arrives
  // split between the two newlines often enough to matter and never in a hand-written fixture, and
  // the buffer also holds a trailing '\r' back in case its '\n' is in the next chunk.
  const frames = createFrameBuffer();

  let sawTerminal = false;
  let idleFired = false;
  let idleTimer: ReturnType<typeof setTimeout> | undefined;
  /**
   * A transport failure raised by read(), kept rather than rethrown.
   *
   * THIS IS THE HALF THE MOBILE IMPLEMENTATION IS MISSING, and it only shows up over a real socket.
   * A vanished peer sends an RST, not a FIN, so `read()` REJECTS with `TypeError: terminated`
   * (undici) / `TypeError: network error` (browsers) instead of resolving `{done: true}`. Letting
   * that propagate means the one case `stream_lost` exists for — the connection itself died and no
   * terminal event arrived — never actually produces `stream_lost`: the consumer gets a raw
   * TypeError, `instanceof KbError` is false in lib/query/client.ts, and the user is shown
   * "something went wrong" with no Retry affordance after a dropped answer.
   *
   * A clean FIN with no terminal event and an RST are the SAME event as far as this client is
   * concerned. Only the operator-facing message distinguishes them.
   */
  let transportFailure: unknown = null;

  const bumpWatchdog = (): void => {
    if (idleTimer !== undefined) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      idleFired = true;
      // Cancelling the reader makes the pending read() resolve `{done: true}`, so the loop exits
      // through the normal path and the `sawTerminal` check below decides what that meant.
      void reader.cancel();
    }, idleGapMs);
  };

  try {
    bumpWatchdog();

    for (;;) {
      let chunk: ReadableStreamReadResult<Uint8Array>;
      try {
        chunk = await reader.read();
      } catch (cause) {
        // Cancellation is not a transport failure and must keep its own path — the stop button
        // produces an AbortError here too, and it means "no error state at all".
        if (cause instanceof Error && cause.name === 'AbortError') throw cause;
        transportFailure = cause;
        break;
      }

      if (chunk.done) break;
      // Any byte proves the path is alive, INCLUDING a `: ping` comment that dispatches no event.
      bumpWatchdog();

      for (const frame of frames.push(decoder.decode(chunk.value, { stream: true }))) {
        // Returns null for anything this client has no business rendering: an unrecognised event
        // name, a frame with no `event:` field, unparseable JSON. The name allow-list is the part
        // with a security consequence — it is what keeps the internal-only events (`provider.usage`
        // token costs, `retrieval.trace` internal topology, `provider.fallback`) from ever being
        // renderable here even if a relay bug forwarded one.
        const event = toKbEvent(frame);
        if (event === null) continue;

        if (event.event === 'error') {
          const data: unknown = event.data;
          // The SSE error frame carries three keys. `request_id` and `Retry-After` exist only on the
          // HTTP envelope, so both are null here — inventing them would put a fabricated identifier
          // in front of a support engineer.
          throw isKbErrorEnvelope(data)
            ? new KbError(data.error_class, data.retryable, null, null, data.message)
            : new KbError(null, false, null, null, 'malformed error frame');
        }

        if (isTerminalEvent(event)) sawTerminal = true;
        // Citations always arrive before the first token — they are assigned from retrieved
        // evidence pre-generation, never parsed out of model output.
        yield event;
      }
    }

    // Exactly one terminal event per stream is the contract: `message.complete` or `error`, never
    // both and never neither. EOF without one means the connection itself died mid-answer, and that
    // is the ONE case the server cannot report — which is what the client-local `stream_lost`
    // sentinel is for. It is not a 19th error class: nothing serializes it, nothing metrics it, and
    // it never reaches an `error_class` field on the wire.
    //
    // DO NOT RE-POST. That re-runs a paid provider call. The caller re-reads the persisted message
    // from Laravel and only then offers Retry, with a NEW client_message_id.
    if (!sawTerminal) {
      throw new KbError(
        STREAM_LOST,
        true,
        null,
        null,
        whyLost(idleFired, idleGapMs, transportFailure),
      );
    }

    // A terminal event DID arrive and the socket then broke on the way to EOF. The answer is
    // complete and the failure is about a connection nobody needs any more, so it is swallowed
    // rather than turned into an error over a finished answer.
  } catch (error) {
    // The stop button, a navigation away and a tab teardown all land here as an AbortError.
    // Cancellation is an OUTCOME (499, `user_cancellation`), not a failure: no toast, no retry, no
    // error span. The server records finish_reason "cancelled" and finalizes usage from the running
    // tally.
    if (error instanceof Error && error.name === 'AbortError') return;
    throw error;
  } finally {
    if (idleTimer !== undefined) clearTimeout(idleTimer);
    // An early `return` from the generator — the consumer breaking out of its `for await` — still
    // has to close the socket, or the provider keeps generating against the tenant's quota for an
    // answer nobody will read.
    await reader.cancel().catch(() => {});
  }
}

/**
 * Operator-facing detail for the one error class the server can never send. Three ways to lose a
 * stream, one sentinel, and the message is the only thing that tells a support engineer which.
 */
function whyLost(idleFired: boolean, idleGapMs: number, transportFailure: unknown): string {
  if (idleFired) return `no data for ${idleGapMs} ms — three missed heartbeats`;
  if (transportFailure !== null) {
    const detail =
      transportFailure instanceof Error
        ? `${transportFailure.name}: ${transportFailure.message}`
        : String(transportFailure);
    return `the connection failed before a terminal event arrived (${detail})`;
  }
  return 'stream ended without a terminal event';
}

/**
 * The same credential discipline as lib/api/browser.ts, and for the same reason: in local
 * development app., chat. and the API are all localhost on different ports, so a cookie-assuming
 * helper passes every dev test and 401s in production on exactly one surface.
 */
function streamHeaders(credential: Credential): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: 'text/event-stream',
    'Content-Type': 'application/json',
  };

  if (credential.kind === 'session') headers['X-XSRF-TOKEN'] = credential.xsrf_token;
  else headers['Authorization'] = `Bearer ${credential.token}`;

  return headers;
}

/** 'omit' on the hosted-chat surface is a security control, not a default — see lib/api/browser.ts. */
function credentialsFor(credential: Credential): { credentials: RequestCredentials } {
  return { credentials: credential.kind === 'session' ? 'include' : 'omit' };
}

import type { ChatSendBody, KbEvent } from '@kb/contracts';
import {
  KbError,
  STREAM_LOST,
  createFrameBuffer,
  isKbErrorEnvelope,
  isTerminalEvent,
  toKbError,
  toKbEvent,
} from '@kb/contracts';

import { authHeaders, refreshSession } from './session.js';

/**
 * The streaming send, from inside the frame, to Laravel and only to Laravel.
 *
 * The parser is IMPORTED, never reimplemented: `createFrameBuffer()` is the chunk-boundary wrapper
 * around `parseFrame()`, and `KbError` is the one error class. Three clients now read the same
 * stream, and three hand-written parsers is three ways to disagree about `: ping` — the one that
 * drifts is the one nobody notices until a terminal event stops being handled.
 *
 * NOT enforced by anything today — no lint rule, and no CI at all. Reviewer
 * check, and the same one `packages/contracts` states from the declaring side:
 *
 *   grep -rnE '^[[:space:]]*(export )?(function parseFrame|class KbError)' apps/widget/src
 *
 * Empty today, verified, and it must stay empty. Anchored on a DECLARATION, and deliberately not
 * respelling the form it replaced: that one searched the same two names unanchored, so the only
 * line it ever matched was the comment stating the rule, and it could never go green. A check that
 * cannot pass is worse than no check, because it reads as enforcement. Run the anchored pattern
 * over packages/contracts/src instead and it returns the two canonical declarations — that is what
 * makes it a check rather than a tautology.
 *
 * NEVER the browser's built-in server-sent-events client: it cannot set `Authorization`, it is
 * GET-only while sending a message is a POST, and its automatic `Last-Event-ID` reconnect is
 * separately barred because token streams are not resumable. Its name is absent from this tree on
 * purpose — `no-restricted-globals` bans the identifier and CI greps for it.
 */
export interface StreamOptions {
  readonly conversationId: string;
  /** Exactly `{client_message_id, content}`. `content`, NOT `text` — `text` is the `token` EVENT's
   *  field name and posting it 422s every send. `client_message_id` is a stable per-message UUID
   *  so Laravel's idempotency key collapses a genuine duplicate into one conversation, one
   *  provider call, one bill. */
  readonly body: ChatSendBody;
  /** From an `AbortController` owned by the composer — the §8.18 stop-generation action. Aborting
   *  must produce one request, one `finish_reason: "cancelled"` message row and one usage row. */
  readonly signal: AbortSignal;
  /**
   * `bridge.requestSessionRefresh` — `toHost('session-expiring')` and nothing else.
   *
   * REQUIRED, not optional, and that is deliberate: an absent callback would turn every expired
   * session into a silent dead end, which is precisely the failure the refresh path exists to
   * prevent. The frame can never re-mint for itself (session.ts), so a send that meets a 401 with
   * no way to ask the loader has nothing left to do.
   */
  readonly onSessionExpiring: () => void;
  /** Test seam only: the SSE fixture server's origin. Never set in application code, which always
   *  uses `__KB_API_ORIGIN__`. */
  readonly apiOrigin?: string;
  /** Test seam only: shortens the idle watchdog so a test need not wait 45 seconds. */
  readonly idleGapMs?: number;
}

/**
 * Yields the SIX client-facing events in order. Implementation invariants, each with a
 * production-only failure mode:
 *
 *  - `fetch()` + `response.body.getReader()` + `TextDecoder.decode(value, { stream: true })`.
 *    Without `{ stream: true }` each chunk is treated as a complete input and the trailing bytes
 *    of a codepoint split across a TCP boundary are dropped — corruption that scales with chunk
 *    count, so it only appears in German, Hindi or emoji, on exactly the long answers people
 *    notice.
 *  - `createFrameBuffer()` from @kb/contracts for re-assembly. Never split on '\n\n' inline: a
 *    frame arrives split BETWEEN the two newlines often enough to matter and never in a fixture.
 *  - `: ping` never surfaces as an event (the shared parser already returns null for it).
 *  - EXACTLY ONE terminal event. If the stream ends without `message.complete` or `error`, raise a
 *    `KbError` carrying the CLIENT-LOCAL `stream_lost` sentinel and offer Retry. `stream_lost` is
 *    never sent to a server, never metricked, and is not a 19th error class.
 *  - A `401` with `error_class: authentication` asks the LOADER to re-mint (session.ts) and the
 *    send is queued, in order, never retried against the dead token.
 *  - Every event the UI renders is untrusted: `token` text goes through
 *    `renderAssistantMarkdown()` in ./lazy-renderer.ts — the shell's only edge to the lazy
 *    src/render/renderer.ts chunk, which the panel-open handler has already warmed — while
 *    `message` from an error envelope is OPERATOR-FACING. Log it; never render it. Users see a
 *    class-mapped sentence plus `request_id` (`kb-error-taxonomy`) — the widget runs inside a
 *    stranger's page and cannot leak an internal hostname or raw upstream provider text into it.
 *  - A mocked response cannot chunk a `text/event-stream`, so this path is tested against a
 *    fixture server that emits over time, never `route.fulfill()` and never MSW.
 */
/**
 * The idle-gap watchdog, sized off the server's 15 s `: ping` — three missed heartbeats.
 *
 * DELIBERATELY NOT `AbortSignal.timeout()`, which applies to the whole request INCLUDING the
 * streamed body: it kills a healthy long generation at a fixed elapsed time, and the symptom is
 * "long answers truncate at exactly N seconds" on exactly the answers people read most carefully.
 * A streaming request has no total-duration timeout — the server owns that through its own
 * deadline. What is detectable from here is SILENCE, and any byte at all resets it, including a
 * heartbeat comment that dispatches no event.
 */
const IDLE_GAP_MS = 45_000;

/**
 * The message-submission route on the PUBLIC CHAT RUNTIME group.
 *
 * `rt/v1`, not `api/v1`: `services/core-api/bootstrap/app.php` puts the admin API on `api/v1` with
 * Laravel's `api` middleware group, which `statefulApi()` prepends EnsureFrontendRequestsAreStateful
 * to. A public runtime call must never acquire ambient session authority from a stateful Origin, so
 * `rt` sits outside `api/` and config/cors.php lists it separately.
 *
 * The route does not exist yet — routes/api_public.php is a TODO, and the conversation that would
 * own this id is created by a `rt/v1/conversations` endpoint that does not exist either. The PREFIX
 * is what matters here and is fixed from the group definition; the segment spelling follows the two
 * routes already named for that group (`rt/v1/conversations`, `rt/v1/conversations/resume`) and is
 * flagged for the control plane to confirm rather than guessed at a second time later.
 */
function messagesUrl(origin: string, conversationId: string): string {
  return `${origin}/rt/v1/conversations/${encodeURIComponent(conversationId)}/messages`;
}

/**
 * `toKbError` IS IMPORTED, NOT WRITTEN HERE.
 *
 * A non-SSE response becomes the one `KbError` through the shared conversion in `@kb/contracts`.
 * This file carried a local copy for exactly one revision, and it was the third in the monorepo —
 * the kind of duplication whose symptom is invisible: `retry_after` comes off a HEADER rather than
 * the JSON body, so a copy that forgets it retries inside the window it was told to wait, forever,
 * with nothing logged. `retryable` is likewise a straight carry of the envelope's flag and is never
 * re-derived from the class name (ADR-029). One implementation, three clients.
 *
 * The shared signature is STRUCTURAL (`{status, headers.get, json}`), so the DOM `Response` this
 * file has satisfies it without a cast.
 */

/** An SSE response, or something else? Branch on the CONTENT TYPE, never the status. */
function isEventStream(response: Response): boolean {
  const contentType = response.headers.get('content-type');
  return response.ok && contentType !== null && contentType.startsWith('text/event-stream');
}

// No `require-yield` disable here: that rule ships in `eslint:recommended`, which this workspace
// never spreads (`tseslint.configs.recommended` does not include it), so the directive was dead —
// ESLint's own `reportUnusedDisableDirectives` said so the moment the config started running. It
// also stops being needed the moment this body yields for real.
export async function* streamAnswer(options: StreamOptions): AsyncGenerator<KbEvent> {
  const origin = options.apiOrigin ?? __KB_API_ORIGIN__;
  const idleGapMs = options.idleGapMs ?? IDLE_GAP_MS;
  const url = messagesUrl(origin, options.conversationId);

  /**
   * ONE request builder, called at most twice.
   *
   * `options.body` is built ONCE by the caller and re-sent byte-identically, which is what "each
   * queued send keeps the `Idempotency-Key` it was created with" means on this surface: the key is
   * derived server-side from `client_message_id`, so re-minting that id on the flush would turn one
   * message into two conversations, two provider calls and two bills. There is no client-generated
   * `Idempotency-Key` HEADER on the public chat surface — the id in the body is the whole identity
   * (`@kb/contracts` ChatSendBody), and inventing a header the control plane does not read would be
   * a contract this client made up on its own.
   *
   * The bearer, by contrast, is re-read on every call: `authHeaders()` returns a fresh object from
   * the module-scoped token, so the flush after a refresh carries the NEW token with the OLD body.
   */
  const post = (): Promise<Response> =>
    fetch(url, {
      method: 'POST', // exactly why the browser's built-in SSE client is unusable: GET-only
      headers: {
        ...authHeaders(),
        accept: 'text/event-stream',
        'content-type': 'application/json',
      },
      body: JSON.stringify(options.body),
      signal: options.signal,
    });

  let response = await post();

  /**
   * 401 / `authentication` → ASK THE LOADER, QUEUE THIS SEND, DO NOT RETRY IT.
   *
   * The distinction is the whole design. A retry re-posts against the dead token and fails
   * identically; a queued send waits for the token to be REPLACED and then goes exactly once, with
   * the same `client_message_id`. `refreshSession()` is single-flight, so ten concurrent sends
   * produce one `session-expiring` message and one mint, and they resume in the order they started
   * waiting.
   *
   * It rejects — with `error_class: 'authentication'`, once — if the loader does not answer within
   * ten seconds or has already failed. That rejection propagates out of this generator unchanged;
   * nothing here converts it into a second attempt.
   *
   * Branching on the CONTENT TYPE first is what keeps this from firing on an SSE stream that merely
   * happens to be a 401-shaped status: an SSE response is never an error envelope.
   */
  if (!isEventStream(response)) {
    const failure = await toKbError(response);
    if (response.status !== 401 && failure.error_class !== 'authentication') throw failure;
    await refreshSession(options.onSessionExpiring);
    response = await post();
    if (!isEventStream(response)) throw await toKbError(response);
  }

  /**
   * `response.body` is null for a body-less response and for any transport that is not really
   * streaming. Treated as OUR client bug: `error_class` null (unknown, permanent), explicitly not
   * `internal_dependency`, which is retryable and pages.
   */
  if (response.body === null) {
    throw new KbError(
      null,
      false,
      null,
      null,
      'response.body is null: this build is not reading the stream, so the answer would arrive all at once',
    );
  }

  const reader = response.body.getReader();
  // UTF-8 only, and that is all our wire is. `{ stream: true }` on every decode is load-bearing:
  // without it each chunk is treated as a complete input and the trailing bytes of a codepoint that
  // straddled a TCP boundary are dropped. The corruption scales with chunk count, so English looks
  // perfect and German, Hindi and emoji rot in proportion to answer length.
  const decoder = new TextDecoder();
  // Frame re-assembly is the SHARED implementation. Never split on '\n\n' inline: a frame arrives
  // split between the two newlines often enough to matter and never in a hand-written fixture, and
  // the buffer also holds a trailing '\r' back in case its '\n' is in the next chunk.
  const frames = createFrameBuffer();

  let sawTerminal = false;
  let idleFired = false;
  let idleTimer: ReturnType<typeof setTimeout> | undefined;

  const bumpWatchdog = (): void => {
    if (idleTimer !== undefined) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      idleFired = true;
      // Cancelling the reader makes the pending read() resolve `{done: true}`, so the loop leaves
      // through the normal path and the `sawTerminal` check below decides what that meant.
      void reader.cancel();
    }, idleGapMs);
  };

  try {
    bumpWatchdog();

    for (;;) {
      /**
       * THE VANISHED PEER DOES NOT ARRIVE AS `done`. It arrives as a REJECTION.
       *
       * A server that ends the response cleanly gives `{done: true}`; a server whose socket is
       * destroyed — a killed pod, a proxy timeout, a dropped mobile connection — makes this
       * `read()` reject with a bare `TypeError: network error`. Measured in Chromium against the
       * fixture's `cut()`, which destroys the socket rather than calling `end()`.
       *
       * That is precisely the case the client-local `stream_lost` sentinel exists for: the
       * connection itself died mid-answer, so no terminal event can ever arrive and the server
       * cannot tell us why. Letting the raw `TypeError` escape instead would hand the UI an error
       * with no `error_class` to map to a sentence and no `retryable` answer — an unknown, which
       * the taxonomy classifies as permanent, for the one failure that is genuinely transient.
       *
       * The wrap is narrow on purpose: ONLY the read is inside this try, so a defect in our own
       * loop below still surfaces as itself instead of being relabelled a network problem.
       */
      let chunk: ReadableStreamReadResult<Uint8Array>;
      try {
        chunk = await reader.read();
      } catch (cause) {
        // Cancellation is handled by the outer catch, and must not be recoloured here.
        if (cause instanceof Error && cause.name === 'AbortError') throw cause;
        // A transport failure AFTER the terminal event is not a lost stream: the answer is already
        // complete and this is just the socket going away a moment later.
        if (sawTerminal) break;
        throw new KbError(
          STREAM_LOST,
          true,
          null,
          null,
          `connection lost mid-stream (${cause instanceof Error ? cause.name : 'unknown'})`,
        );
      }
      const { done, value } = chunk;
      if (done) break;
      // Any byte proves the path is alive, INCLUDING a `: ping` comment that dispatches no event.
      bumpWatchdog();

      for (const frame of frames.push(decoder.decode(value, { stream: true }))) {
        // Returns null for anything this client has no business rendering: an unrecognised event
        // name, a frame with no `event:` field, unparseable JSON. The name allow-list is the part
        // with a security consequence — it is what keeps the internal-only events
        // (`provider.usage` token costs, `retrieval.trace` internal topology, `provider.fallback`)
        // from ever being renderable here even if a relay bug forwarded one.
        const event = toKbEvent(frame);
        if (event === null) continue;

        if (event.event === 'error') {
          const data: unknown = event.data;
          // The SSE error frame carries three keys. `request_id` and `Retry-After` exist only on
          // the HTTP envelope, so both are NULL here — inventing them would put a fabricated
          // identifier in front of a support engineer.
          throw isKbErrorEnvelope(data)
            ? new KbError(data.error_class, data.retryable, null, null, data.message)
            : new KbError(null, false, null, null, 'malformed error frame');
        }

        if (isTerminalEvent(event)) sawTerminal = true;
        // Citations always arrive BEFORE the first token — they are assigned from retrieved
        // evidence pre-generation, never parsed out of model output. The consumer renders `token`
        // text through `renderAssistantMarkdown()` (./lazy-renderer.ts); this generator never
        // touches the DOM, so there is no second sink for model output to reach.
        yield event;
      }
    }

    /**
     * EXACTLY ONE TERMINAL EVENT per stream: `message.complete` or `error`, never both and never
     * neither. EOF without one means the connection itself died mid-answer, and that is the one
     * case the server cannot report — which is what the CLIENT-LOCAL `stream_lost` sentinel is
     * for. It is not a 19th error class: nothing serializes it, nothing metrics it, and it never
     * reaches an `error_class` field on the wire.
     *
     * DO NOT RE-POST. That re-runs a paid provider call. The caller re-reads the persisted message
     * from Laravel and only then offers Retry, with a NEW client_message_id.
     */
    if (!sawTerminal) {
      throw new KbError(
        STREAM_LOST,
        true,
        null,
        null,
        idleFired
          ? `no data for ${idleGapMs} ms — three missed heartbeats`
          : 'stream ended without a terminal event',
      );
    }
  } catch (error) {
    // Stop-generation lands here as an AbortError. Cancellation is an OUTCOME (499,
    // `user_cancellation`), not a failure: no toast, no retry, no error span. The server records
    // finish_reason "cancelled" and finalizes usage from the running tally.
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

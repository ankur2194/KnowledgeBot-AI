// THE NAMED IMPORT IS THE POINT. Since SDK 56 `expo/fetch` also replaces `globalThis.fetch` on
// iOS and Android, but the documented opt-out env flag restores Hermes' XHR-backed fetch and
// `res.body` silently becomes null. A bare global `fetch` can be switched off that way, or shadowed
// by any wrapper (Sentry, an API-client library, a mock) that closed over the global before Expo
// installed itself. This form cannot be.
//
// WHAT KEEPS IT THIS FORM: `eslint.config.mjs`, the `src/features/chat/**` block — it bans the
// global `fetch`, `globalThis.fetch`/`global.fetch`, and a by-name `fetch` imported from any other
// module, and `ci.yml:654` runs `pnpm lint` here. This line used to claim CI GREPPED for the
// import; it never did, and the corrected claim is deliberately narrower. Nothing mechanical
// defends the `response.body === null` throw below, and nothing in this repo executes on Hermes.
import { fetch } from 'expo/fetch';
// Runtime imports from the shared package. `KbError`, `createFrameBuffer` and `toKbEvent` are real
// code there, not types, and this app forks none of them: a second KbError breaks `instanceof` and
// every retry decision made against it, and a third hand-written SSE parser is the drift
// packages/contracts exists to prevent.
import {
  KbError,
  STREAM_LOST,
  createFrameBuffer,
  isKbErrorEnvelope,
  isTerminalEvent,
  toKbEvent,
} from '@kb/contracts';
import type { ChatSendBody, KbEvent } from '@kb/contracts';

import { asOfflineError, toKbError } from '@/api/client';
import { API_ORIGIN } from '@/lib/env';

/**
 * The ONE place this app reads a chat stream.
 *
 * WHY expo/fetch AND NOT THE PLATFORM fetch: Hermes' fetch is XHR-backed and its `Response.body`
 * is `null`. It does not throw and it does not warn — it resolves ONCE, with the whole answer. So
 * streaming ceases to exist while every assertion about the final text still passes, and the only
 * visible symptom is that the answer appears all at once, several seconds late. A test that checks
 * the final string can never catch it, which is why `res.body === null` is asserted below and
 * treated as a client bug rather than a dependency failure.
 *
 * WHY NOT THE BROWSER'S BUILT-IN SSE CLIENT: its constructor's only option is `withCredentials`,
 * so it cannot carry `Authorization`; it is GET-only while sending a message is a POST with a
 * body; and its automatic `Last-Event-ID` reconnect is separately forbidden, because token streams
 * are not resumable and a resume either re-runs a paid provider call or replays tokens the user
 * already saw. Its name is absent from this package on purpose, and one of the two things that used
 * to be claimed here is real: `eslint.base.mjs`'s `no-restricted-globals` bans `EventSource`, it is
 * spread into this workspace's config, and `ci.yml:654` runs `pnpm lint`. CI does NOT grep for it.
 * There is a vendored semgrep rule (`scripts/security/rules/kb-ts-boundary.yaml`,
 * `kb-ts-eventsource-for-chat`) that would catch it, but no workflow runs the scan — `gates.yml`
 * only asserts the ruleset is non-empty. So ESLint is the whole enforcement.
 */

/**
 * The idle-gap watchdog, sized off the server's 15 s `: ping` — three missed heartbeats.
 *
 * DELIBERATELY NOT `AbortSignal.timeout()`. expo/fetch honours that for the WHOLE request including
 * the streamed body, so it kills a healthy long generation at a fixed elapsed time; the symptom is
 * "long answers truncate at exactly N seconds" and it never reproduces on a short one. A streaming
 * request has no total-duration timeout — the server owns that, via its own deadline. What we can
 * detect from here is SILENCE, and any byte at all resets it, including a heartbeat comment.
 */
const IDLE_GAP_MS = 45_000;

export interface StreamAnswerOptions {
  readonly conversationId: string;
  /**
   * Exactly `{client_message_id, content}`, typed from @kb/contracts. `content`, not `text` —
   * `text` is the `token` EVENT's field, and posting it 422s every send on this client and no
   * other, because each client's fixtures were written from the same source of truth the client
   * was. `client_message_id` is a stable per-message UUID (not a per-render one) so Laravel's
   * idempotency key collapses a genuine duplicate — a double tap, a remount — into one
   * conversation, one provider call, one bill.
   */
  readonly body: ChatSendBody;
  /** From src/api/client.ts's `getBearerToken()`. Null must be handled BEFORE calling this: route
   *  to login rather than sending and eating the 401, or the composed message is lost. */
  readonly token: string;
  /**
   * One controller, three cancel sources — the Stop button, navigating away, and the app going to
   * background. See use-answer-stream.ts. Aborting closes the socket, which makes Laravel's write
   * fail, which makes it cancel its upstream call, which makes FastAPI see the disconnect and stop
   * the provider. Letting the socket die quietly instead is NOT equivalent and is not free.
   */
  readonly signal: AbortSignal;
  /** Test seam only: the SSE fixture server's origin. Never set in application code. */
  readonly apiOrigin?: string;
  /** Test seam only: shortens the idle watchdog so a test need not wait 45 seconds. */
  readonly idleGapMs?: number;
}

export async function* streamAnswer(options: StreamAnswerOptions): AsyncGenerator<KbEvent> {
  const origin = options.apiOrigin ?? API_ORIGIN;
  const idleGapMs = options.idleGapMs ?? IDLE_GAP_MS;

  // `rt/v1`, NOT `api/v1`, and the difference is the whole authentication story of this client.
  // `api/v1` is the ADMIN group (bootstrap/app.php): Laravel's `api` middleware, which
  // statefulApi() prepends EnsureFrontendRequestsAreStateful to — a cookie session, CSRF, org
  // membership. This request carries a Sanctum personal access token in an Authorization header and
  // no cookie at all, so posting it there aims a bearer at the one group designed to reject bearers.
  // `rt/v1` is the public chat runtime group, and routes/api_public.php names mobile as exactly the
  // exception that carries a PAT on it. The endpoint does not exist in Laravel yet; the path is
  // pinned by a test (see tests/stream-answer.test.ts) so that when it lands, a wrong prefix fails a
  // test instead of shipping.
  let response: Awaited<ReturnType<typeof fetch>>;
  try {
    response = await fetch(
      `${origin}/rt/v1/conversations/${encodeURIComponent(options.conversationId)}/messages`,
      {
        method: 'POST', // exactly why the built-in SSE client is unusable: GET-only, no headers
        headers: {
          Authorization: `Bearer ${options.token}`,
          Accept: 'text/event-stream',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(options.body),
        signal: options.signal,
      },
    );
  } catch (error) {
    // OFFLINE IS DECIDED HERE, by the module that owns the transport, so the hook above never has
    // to guess from a `TypeError` it caught around application code. A `fetch` that never reached a
    // server rejects with a TypeError on every runtime this app ships on; that is not a server
    // failure, has no envelope, and must never be reported as `internal_dependency` — a class that
    // is retryable AND pages, so a tunnel would wake someone up. An AbortError is cancellation, not
    // a failure, and is re-thrown for the catch below to swallow.
    if (error instanceof Error && error.name === 'AbortError') throw error;
    const offline = asOfflineError(error);
    if (offline !== null) throw offline;
    throw error;
  }

  // A failure arrives as the JSON error envelope, not as SSE. Branch on `error_class`, never on the
  // status: one class renders as different statuses per surface (`authorization` is 403 on admin
  // and 404 on public), so status-driven logic reads an authorization 404 as "absent, so create it".
  const contentType = response.headers.get('content-type');
  if (!response.ok || contentType === null || !contentType.startsWith('text/event-stream')) {
    throw await toKbError(response);
  }

  // THE ASSERTION THAT CATCHES THE SILENT DEGRADATION. If this is ever null, the request did not go
  // through the streaming fetch — a wrapper closed over the global, or the opt-out flag is set in
  // the environment. It is OUR client bug, so `error_class` is null (unknown, permanent) and
  // explicitly NOT `internal_dependency`, which is retryable and pages.
  if (response.body === null) {
    throw new KbError(
      null,
      false,
      null,
      null,
      'response.body is null: this build is not using the streaming fetch, so the answer would arrive all at once',
    );
  }

  const reader = response.body.getReader();
  // UTF-8 only, and that is all our wire is. `{ stream: true }` on every decode is load-bearing:
  // without it each chunk is treated as a complete input and the trailing bytes of a codepoint that
  // straddled a TCP boundary are dropped. Corruption scales with chunk count, so English looks
  // perfect and Hindi, German and emoji rot in proportion to answer length — exactly the answers
  // people read most carefully.
  const decoder = new TextDecoder();
  // Frame re-assembly is the shared implementation. Never split on '\n\n' inline: a frame arrives
  // split between the two newlines often enough to matter and never in a hand-written fixture, and
  // the buffer also holds a trailing '\r' back in case its '\n' is in the next chunk.
  const frames = createFrameBuffer();

  let sawTerminal = false;
  let idleFired = false;
  /**
   * A transport failure raised by `read()` ITSELF, kept rather than rethrown.
   *
   * A VANISHED PEER SENDS AN RST, NOT A FIN, and the two arrive here in completely different
   * shapes. A clean end-of-body resolves `{done: true}` and falls out of the loop; a socket that was
   * destroyed mid-answer REJECTS the pending `read()` — `TypeError: terminated` on undici,
   * `TypeError: network error` in a browser, `TypeError: Network request failed` on React Native.
   * Letting that reject propagate is a real bug with an invisible symptom: the caller receives a
   * bare `TypeError`, `instanceof KbError` is false in the retry predicate in
   * src/lib/query-client.ts, and a dropped answer renders as a generic "something went wrong" with
   * no Retry affordance — which is precisely the case the `stream_lost` sentinel exists for. So the
   * failure is recorded, the loop breaks, and the `sawTerminal` check below decides what it meant.
   *
   * WHY THIS IS `stream_lost` AND NOT THE OFFLINE STATE, even though the runtime throws the same
   * `TypeError` here that it throws when the radio is off. The two are distinguishable by WHEN, and
   * only by when: a rejection at the `fetch` above means no server was ever reached, and is offline;
   * a rejection from `read()` means we had a response, headers, and possibly half an answer, and the
   * connection died mid-flight. From inside the loop nothing can tell a server hangup from a lift
   * door closing, and `stream_lost` is the right answer to both — it re-reads the persisted message
   * from Laravel and offers Retry, and if the device really is offline that re-read surfaces the
   * offline state on its own.
   */
  let transportFailure: unknown = null;
  let idleTimer: ReturnType<typeof setTimeout> | undefined;

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
      } catch (error) {
        // AN ABORT IS NOT A TRANSPORT FAILURE, and collapsing the two would turn every Stop press,
        // every navigation and every backgrounding into a `stream_lost` error toast with a Retry
        // button, for a stream the user deliberately ended. Cancellation is an outcome (499,
        // `user_cancellation`); it is re-thrown so the catch below takes its `return` path.
        if (error instanceof Error && error.name === 'AbortError') throw error;
        transportFailure = error;
        break;
      }

      const { done, value } = chunk;
      if (done) break;
      // Any byte proves the path is alive, INCLUDING a `: ping` comment that dispatches no event.
      bumpWatchdog();

      for (const frame of frames.push(decoder.decode(value, { stream: true }))) {
        // Returns null for anything this client has no business rendering: an unrecognised event
        // name, a frame with no `event:` field, unparseable JSON. The name allow-list is the part
        // with a security consequence — it is what keeps the internal-only events (`provider.usage`
        // token costs, `retrieval.trace` internal topology, `provider.fallback`) from ever being
        // renderable here even if a relay bug forwarded one.
        const event = toKbEvent(frame);
        if (event === null) continue;

        if (event.event === 'error') {
          const data: unknown = event.data;
          // The SSE error frame carries three keys. `request_id` and `Retry-After` exist only on
          // the HTTP envelope, so both are null here — inventing them would put a fabricated
          // identifier in front of a support engineer.
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
    // both and never neither. The absence of one means the connection itself died mid-answer, and
    // that is the ONE case the server cannot report — which is what the client-local `stream_lost`
    // sentinel is for. It is not a 19th error class: nothing serializes it, nothing metrics it, and
    // it never reaches an `error_class` field on the wire.
    //
    // ALL THREE WAYS TO GET HERE FOLD INTO ONE OUTCOME, and that is the point: a clean FIN with no
    // terminal event, a destroyed socket whose RST rejected `read()`, and the idle watchdog firing
    // on silence are indistinguishable to the user and have the same remedy. The message
    // distinguishes them for an operator reading a log; the class does not, because a client that
    // branched on them would offer three different Retry buttons for one situation.
    //
    // DO NOT RE-POST. That re-runs a paid provider call. The caller re-reads the persisted message
    // from Laravel and only then offers Retry, with a NEW client_message_id.
    if (!sawTerminal) {
      throw new KbError(
        STREAM_LOST,
        true,
        null,
        null,
        transportFailure !== null
          ? `connection destroyed mid-answer: ${String(transportFailure)}`
          : idleFired
            ? `no data for ${idleGapMs} ms — three missed heartbeats`
            : 'stream ended without a terminal event',
      );
    }
    // A transport failure AFTER the terminal event is not a failure at all: the answer completed and
    // the socket died on the way to being closed. Swallowing it here is deliberate — surfacing it
    // would put an error on a finished, correct answer.
  } catch (error) {
    // Stop, blur and background all land here as an AbortError. Cancellation is an OUTCOME (499,
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

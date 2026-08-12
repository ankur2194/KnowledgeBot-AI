import { once } from 'node:events';
import {
  createServer,
  type IncomingHttpHeaders,
  type IncomingMessage,
  type Server,
} from 'node:http';
import type { AddressInfo } from 'node:net';

import { KbError, STREAM_LOST, type KbEvent } from '@kb/contracts';
import { afterEach, describe, expect, it } from 'vitest';

import type { Credential } from '@/lib/api/browser';
import { streamAnswer } from '@/features/chat/stream-answer';

import { startSseFixture, type SseFixture, type StreamControl } from '../fixtures/sse-server';

/**
 * `streamAnswer()` over a real socket.
 *
 * `sse-fixture.test.ts` proves the FIXTURE reproduces six transport behaviours. This file turns
 * each of them into an assertion about the read loop, which is the code that ships.
 *
 * NO MSW IN THIS FILE, not even for the one non-SSE branch, and not merely because the repo doctrine
 * says a streaming test double is a false green. `setupServer` patches the global dispatcher for the
 * whole module, so an MSW instance registered for a JSON handler also sits in front of every
 * passthrough request to 127.0.0.1 — and a passthrough that buffers turns every timing assertion
 * below into a statement about nothing while leaving them green. The error-envelope branch gets its
 * own three-line `node:http` server instead.
 *
 * EVERY TEST IS NAMED AFTER A MECHANISM. The fixture answers `200 text/event-stream` on any path,
 * any method, any headers, so a spec called "chat works" would be asserting that a socket exists.
 * "reassembles a frame split between its two newlines" is a claim the transport can actually fail.
 *
 * `environment: 'node'` (the `unit` project): jsdom drops `ReadableStream`, `TextDecoder` and
 * `Response`, which is the entire surface under test.
 */

const CONVERSATION = '01JCONV0000000000000000000';

const CHAT: Credential = { kind: 'chat_session', token: 'cs_opaque_token' };
const ADMIN: Credential = { kind: 'session', xsrf_token: 'decoded==token' };

const BODY = { client_message_id: '01JMSGCLIENT0000000000000', content: 'Where is my refund?' };

/** Long enough that no correct test ever reaches it, short enough to fail before Vitest's 5 s. */
const HANG_MS = 2_000;

function withDeadline<T>(promise: Promise<T>, what: string): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const alarm = new Promise<never>((_resolve, reject) => {
    timer = setTimeout(
      () => reject(new Error(`${what} never arrived within ${HANG_MS} ms`)),
      HANG_MS,
    );
  });
  return Promise.race([promise, alarm]).finally(() => {
    clearTimeout(timer);
  });
}

// ── recording what the server actually received ──────────────────────────────────────────────────

interface Seen {
  readonly method: string;
  readonly url: string;
  readonly headers: IncomingHttpHeaders;
  /** Resolves once the request body is complete. */
  readonly body: Promise<string>;
  /** Resolves when the CONNECTION closes — the only way to see a client-side cancel from here. */
  readonly closed: Promise<void>;
}

/**
 * The fixture's own `requests()` hands back the `IncomingMessage`, which carries the method, the
 * path and the headers but not the body — nothing has read the stream yet. This attaches the
 * listeners inside the fixture's `onRequest` hook, before the response head is written, so the
 * POST body is captured without touching the twin region.
 */
function recorder(): { seen: Seen[]; onRequest: (request: IncomingMessage) => void } {
  const seen: Seen[] = [];
  return {
    seen,
    onRequest(request) {
      const chunks: Buffer[] = [];
      let settleBody!: (value: string) => void;
      const body = new Promise<string>((resolve) => {
        settleBody = resolve;
      });
      const finish = () => settleBody(Buffer.concat(chunks).toString('utf8'));
      request.on('data', (chunk: Buffer) => chunks.push(chunk));
      request.on('end', finish);
      request.on('aborted', finish);

      let settleClosed!: () => void;
      const closed = new Promise<void>((resolve) => {
        settleClosed = resolve;
      });
      request.socket.on('close', settleClosed);

      seen.push({
        method: request.method ?? '',
        url: request.url ?? '',
        headers: request.headers,
        body,
        closed,
      });
    },
  };
}

/**
 * Observe the arguments handed to `fetch` WITHOUT replacing it.
 *
 * `credentials` is unobservable on the server side in Node — there is no cookie jar, so 'omit' and
 * 'include' produce byte-identical requests. It is still a security property (the hosted-chat
 * surface must not be able to attach the admin session cookie the day its scope widens), so it is
 * asserted where it is decided: at the call. The real fetch still runs and the bytes still cross a
 * real socket; nothing here is a stand-in for a response.
 */
function observeFetch(): { calls: RequestInit[]; restore: () => void } {
  const calls: RequestInit[] = [];
  const real = globalThis.fetch;
  globalThis.fetch = ((input: RequestInfo | URL, init?: RequestInit) => {
    calls.push(init ?? {});
    return real(input, init);
  }) as typeof fetch;
  return { calls, restore: () => void (globalThis.fetch = real) };
}

// ── harness ──────────────────────────────────────────────────────────────────────────────────────

interface Run {
  events: KbEvent[];
  error: unknown;
  done: Promise<void>;
  /** Settles once an event with this name has been yielded BY streamAnswer. */
  yielded: (name: string) => Promise<void>;
}

/** Drive the generator to exhaustion, capturing whatever it throws. */
function consume(
  iterator: AsyncGenerator<KbEvent>,
  options: { readonly stopAfter?: string } = {},
): Run {
  const events: KbEvent[] = [];
  const waiting: Array<{ name: string; resolve: () => void; reject: (cause: unknown) => void }> =
    [];
  const run: Run = {
    events,
    error: null,
    done: Promise.resolve(),
    yielded(name) {
      if (events.some((event) => event.event === name)) return Promise.resolve();
      const promise = new Promise<void>((resolve, reject) => {
        waiting.push({ name, resolve, reject });
      });
      void promise.catch(() => {});
      return promise;
    },
  };

  run.done = (async () => {
    try {
      for await (const event of iterator) {
        events.push(event);
        for (const pending of waiting.splice(0, waiting.length)) {
          if (pending.name === event.event) pending.resolve();
          else waiting.push(pending);
        }
        // `break` out of a `for await` calls the generator's return(), which must still close the
        // socket — that is the case the `finally { reader.cancel() }` exists for.
        if (options.stopAfter !== undefined && event.event === options.stopAfter) break;
      }
    } catch (cause) {
      run.error = cause;
    }
    while (waiting.length > 0) {
      waiting.pop()?.reject(new Error('the stream ended before that event arrived'));
    }
  })();

  return run;
}

let openFixtures: SseFixture[] = [];

async function fixture(onRequest?: (request: IncomingMessage) => void): Promise<SseFixture> {
  const sse = await startSseFixture(onRequest);
  openFixtures.push(sse);
  return sse;
}

/**
 * `server.close()` waits for OPEN CONNECTIONS, and Node's fetch client opens a fresh pooled socket
 * the instant a streaming body is cancelled or reset, then holds it idle for undici's 4 s
 * keep-alive timeout. Measured on this box: the request's own socket closes at ~118 ms, a second
 * connection is accepted at ~118 ms, and `close()` returns at ~4081 ms — and the same 4 s appears
 * for a plain aborted `fetch()` with no `streamAnswer` in the picture at all, so it is a property of
 * the client pool rather than of the code under test.
 *
 * The thing that WOULD be a real leak — the request socket staying open after a cancel — is
 * asserted directly by the two cancellation specs, which await the server-side close. So the close
 * here is started and given a short grace period rather than awaited to completion; the listener is
 * shut immediately either way and the idle socket is reaped with the worker.
 */
afterEach(async () => {
  const fixtures = openFixtures;
  openFixtures = [];
  await Promise.all(
    fixtures.map((sse) =>
      Promise.race([
        sse.close().catch(() => {}),
        new Promise<void>((resolve) => setTimeout(resolve, 100)),
      ]),
    ),
  );
});

// ── the request the server saw ───────────────────────────────────────────────────────────────────

describe('the request streamAnswer issues', () => {
  it('is a POST to rt/v1 carrying exactly {client_message_id, content}', async () => {
    const seen = recorder();
    const sse = await fixture(seen.onRequest);
    const controller = new AbortController();

    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream: StreamControl = await sse.next();
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.error).toBeNull();
    const request = seen.seen[0];
    expect(request).toBeDefined();

    // `rt/v1`, NOT `api/v1`. The admin prefix carries the Sanctum cookie session and
    // PreventRequestForgery and is built to reject a bearer, so a send on that prefix 401s in
    // production and passes against any fixture — this fixture included, which answers 200 on every
    // path. Pinning the path here is what converts it from untested to tested.
    expect(request?.method).toBe('POST');
    expect(request?.url).toBe(`/rt/v1/conversations/${CONVERSATION}/messages`);

    // EXACTLY TWO KEYS. `extra` is rejected by the FormRequest, and `text` — the token EVENT's
    // field name — 422s every send.
    const parsed: unknown = JSON.parse(await withDeadline(request!.body, 'the request body'));
    expect(parsed).toEqual(BODY);
    expect(Object.keys(parsed as object).sort()).toEqual(['client_message_id', 'content']);

    expect(request?.headers.accept).toBe('text/event-stream');
    expect(request?.headers['content-type']).toBe('application/json');
  });

  it('carries a bearer and no XSRF header on the hosted-chat surface', async () => {
    const seen = recorder();
    const sse = await fixture(seen.onRequest);
    const controller = new AbortController();

    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(seen.seen[0]?.headers.authorization).toBe('Bearer cs_opaque_token');
    expect(seen.seen[0]?.headers['x-xsrf-token']).toBeUndefined();
  });

  it('carries the XSRF header and no bearer on the admin surface', async () => {
    const seen = recorder();
    const sse = await fixture(seen.onRequest);
    const controller = new AbortController();

    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: ADMIN,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    // Already URL-DECODED — Laravel compares the header to the decrypted cookie value, and `%3D`
    // padding echoed verbatim never matches.
    expect(seen.seen[0]?.headers['x-xsrf-token']).toBe('decoded==token');
    expect(seen.seen[0]?.headers.authorization).toBeUndefined();
  });

  it("sends credentials 'omit' from hosted chat and 'include' from admin", async () => {
    const observed = observeFetch();
    try {
      for (const credential of [CHAT, ADMIN]) {
        const sse = await fixture();
        const controller = new AbortController();
        const run = consume(
          streamAnswer({
            conversationId: CONVERSATION,
            body: BODY,
            credential,
            signal: controller.signal,
            apiOrigin: sse.url,
          }),
        );
        const stream = await sse.next();
        await stream.send('message.complete', { finish_reason: 'stop' });
        await stream.end();
        await withDeadline(run.done, 'the stream');
      }
    } finally {
      observed.restore();
    }

    // The security property, asserted where it is decided. 'include' from `chat.<domain>` is
    // silently empty today because the session cookie is scoped to app./api. — which is exactly why
    // it survives review: the code visibly HAS a credential, it just has the wrong one, and the day
    // the cookie scope widens by one config line that becomes a real cross-surface leak from the
    // surface that renders model output.
    expect(observed.calls[0]?.credentials).toBe('omit');
    expect(observed.calls[1]?.credentials).toBe('include');
  });
});

// ── the read loop ────────────────────────────────────────────────────────────────────────────────

describe('the read loop, over a real socket', () => {
  it('reassembles a frame split between its two terminating newlines', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    // Positive control: bytes are already flowing, so the split below is a real chunk boundary and
    // not one buffer handed over at the end.
    await withDeadline(run.yielded('message.start'), 'message.start');

    const frame = `event: token\ndata: ${JSON.stringify({ text: 'Refunds take 5 days.' })}\n\n`;
    // The nastiest boundary there is, and the one no hand-written fixture produces: a split BETWEEN
    // the two newlines that terminate the frame. Splitting on '\n\n' inline drops this frame
    // entirely and the answer loses a token in production only.
    await stream.split(frame, frame.length - 1);

    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.error).toBeNull();
    expect(run.events.map((event) => event.event)).toEqual([
      'message.start',
      'token',
      'message.complete',
    ]);
    expect(run.events[1]?.data).toEqual({ text: 'Refunds take 5 days.' });
  });

  it('decodes a codepoint split across a chunk boundary without a replacement character', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await withDeadline(run.yielded('message.start'), 'message.start');

    const frame = `event: token\ndata: ${JSON.stringify({ text: 'Rückerstattungen' })}\n\n`;
    // 0xC3 is the first byte of `ü`; +1 lands BETWEEN the two bytes of the codepoint. Computed, not
    // hardcoded, so editing the string cannot quietly move the split onto a character boundary and
    // turn the hardest case in the file into the easiest.
    const atByte = Buffer.from(frame, 'utf8').indexOf(0xc3) + 1;
    await stream.split(frame, atByte);

    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    const token = run.events.find((event) => event.event === 'token');
    // Without `{ stream: true }` this is 'R��ckerstattungen' — and the JSON.parse inside
    // toKbEvent survives it, so the frame still arrives and only the TEXT is wrong. English is
    // always perfect; the corruption scales with chunk count, so it shows up on exactly the long
    // German and Hindi answers people read most carefully.
    expect(token?.data).toEqual({ text: 'Rückerstattungen' });
    expect(JSON.stringify(run.events)).not.toContain('�');
  });

  it('never yields the `: ping` heartbeat comment as an event', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await withDeadline(run.yielded('message.start'), 'message.start');
    await stream.ping();
    await stream.ping();
    await stream.send('token', { text: 'Refunds ' });
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    // A parser that dispatches `: ping` fires an event with empty data every 15 s, which a
    // transcript reads as an empty token and renders as a stray bullet.
    expect(run.events.map((event) => event.event)).toEqual([
      'message.start',
      'token',
      'message.complete',
    ]);
  });

  it('yields the citations event before the first token', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('citations', { citations: [{ index: 1, title: 'Refund policy' }] });
    // THE ASSERTION IS THIS AWAIT: the consumer has the citations event at a moment when the token
    // frame does not exist anywhere, because this test has not written it yet and cannot until the
    // line returns. A buffering read loop deadlocks here rather than arriving late, so the verdict
    // does not depend on how fast the machine is.
    await withDeadline(run.yielded('citations'), 'the citations event');

    await stream.send('token', { text: 'Refunds ' });
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.events.map((event) => event.event)).toEqual([
      'message.start',
      'citations',
      'token',
      'message.complete',
    ]);
  });

  it('ignores an internal-only event name a relay bug forwarded', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    // Cost data and internal retrieval topology stop at Laravel. A client that can NAME them is a
    // client that can render them, so the allow-list is enforced here and not by trusting the relay.
    await stream.send('provider.usage', { prompt_tokens: 900, cost_usd: 0.12 });
    await stream.send('retrieval.trace', { collection: 'kb_org_01J_v3' });
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.events.map((event) => event.event)).toEqual(['message.complete']);
    expect(JSON.stringify(run.events)).not.toContain('cost_usd');
    expect(JSON.stringify(run.events)).not.toContain('kb_org_01J_v3');
  });
});

// ── terminal states ──────────────────────────────────────────────────────────────────────────────

describe('how a stream ends', () => {
  it('raises stream_lost when the peer vanishes without a terminal event', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('token', { text: 'Refunds ' });
    // POSITIVE CONTROL BEFORE THE NEGATIVE ONE. "No terminal event arrived" is also satisfied by a
    // stream that delivered nothing at all, so the partial answer has to be proved present first.
    await withDeadline(run.yielded('token'), 'the token event');
    // RST, not FIN. `end()` here is a NORMAL end of stream and drives the completed-answer path —
    // the fixture bug that makes a whole cancellation suite green over nothing.
    stream.cut();
    await withDeadline(run.done, 'the stream');

    // THIS IS THE ASSERTION THE MOBILE REFERENCE FAILS, and only a real socket can make it.
    // An RST does not resolve read() with `{done: true}`; it REJECTS it with
    // `TypeError: terminated` (undici) / `TypeError: network error` (browsers). A read loop that
    // lets that propagate never produces `stream_lost` for the one case the sentinel exists for:
    // the consumer gets a bare TypeError, `instanceof KbError` is false in lib/query/client.ts,
    // and a dropped answer renders as "something went wrong" with no Retry affordance.
    expect(run.error).toBeInstanceOf(KbError);
    const error = run.error as KbError;
    expect(error.error_class).toBe(STREAM_LOST);
    expect(error.retryable).toBe(true);
    // Operator-facing, so the log distinguishes a reset socket from a clean EOF and from the idle
    // watchdog — three different incidents behind one class name.
    expect(error.message).toContain('the connection failed');

    // The partial answer survives, and the completed-answer path was not taken.
    expect(run.events.map((event) => event.event)).toEqual(['message.start', 'token']);
  });

  it('raises stream_lost when the body ends cleanly with no terminal event', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('token', { text: 'Refunds ' });
    await withDeadline(run.yielded('token'), 'the token event');
    // A clean FIN after a partial answer. Laravel's relay dying mid-generation looks exactly like
    // this from here, and it is the one case the server cannot report.
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.error).toBeInstanceOf(KbError);
    const error = run.error as KbError;
    // The CLIENT-LOCAL sentinel. Not a 19th class: nothing serializes it and it never reaches an
    // `error_class` field on the wire.
    expect(error.error_class).toBe(STREAM_LOST);
    expect(error.retryable).toBe(true);
    expect(error.retry_after).toBeNull();
    expect(error.request_id).toBeNull();
    // The partial answer survives — the UI shows it plus Retry, never a completed answer.
    expect(run.events.map((event) => event.event)).toEqual(['message.start', 'token']);
  });

  it('does not raise stream_lost when message.complete arrived', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('token', { text: 'Refunds ' });
    await stream.send('message.complete', { finish_reason: 'stop' });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    // The negative control for the two specs above: the same clean FIN, and no error, because a
    // terminal event was in the body. A read loop that always raised stream_lost would pass both of
    // those and fail only this one.
    expect(run.error).toBeNull();
  });

  it('turns an `error` frame into a KbError with request_id and retry_after null', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('error', {
      error_class: 'provider_rate_limit',
      message: 'openai returned 429 for org-4a91 on host ai-worker-3.internal',
      retryable: true,
    });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    expect(run.error).toBeInstanceOf(KbError);
    const error = run.error as KbError;
    expect(error.error_class).toBe('provider_rate_limit');
    // Straight carry, never re-derived from the class name.
    expect(error.retryable).toBe(true);
    // BOTH NULL, and deliberately so: `request_id` and `Retry-After` exist only on the HTTP
    // envelope. Synthesising either here would put a fabricated identifier in front of a support
    // engineer who would then grep for it and find nothing.
    expect(error.request_id).toBeNull();
    expect(error.retry_after).toBeNull();
    // Operator-facing. Kept for the log; the UI renders ERROR_COPY plus the request_id.
    expect(error.message).toContain('ai-worker-3.internal');
  });

  it('treats a malformed error frame as unknown rather than inventing a class', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('error', { error_class: 'not_a_real_class', retryable: true });
    await stream.end();
    await withDeadline(run.done, 'the stream');

    const error = run.error as KbError;
    expect(error).toBeInstanceOf(KbError);
    // Null is unknown and unknown is permanently non-retryable. Honouring the `retryable: true` on
    // an envelope we could not validate would retry on a class that does not exist.
    expect(error.error_class).toBeNull();
    expect(error.retryable).toBe(false);
  });
});

// ── cancellation ─────────────────────────────────────────────────────────────────────────────────

describe('cancellation', () => {
  it('returns rather than throwing when the signal aborts mid-answer', async () => {
    const seen = recorder();
    const sse = await fixture(seen.onRequest);
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('token', { text: 'Refunds ' });
    await withDeadline(run.yielded('token'), 'the token event');

    controller.abort();
    await withDeadline(run.done, 'the stream');

    // Cancellation is an OUTCOME (499, `user_cancellation`), not a failure: no toast, no retry, no
    // error span, and above all NOT stream_lost — which carries a Retry affordance the user did not
    // ask for after pressing Stop.
    expect(run.error).toBeNull();
    expect(run.events.map((event) => event.event)).toEqual(['message.start', 'token']);

    // The socket must actually close. Aborting closes it, which makes Laravel's next write fail,
    // which makes it cancel its upstream call, which makes FastAPI stop the provider. Letting the
    // socket die quietly instead is not equivalent and is not free.
    await withDeadline(seen.seen[0]!.closed, 'the server-side connection close');
    expect(seen.seen).toHaveLength(1);
  });

  it('closes the socket when the consumer breaks out of the loop early', async () => {
    const seen = recorder();
    const sse = await fixture(seen.onRequest);
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
      }),
      // No abort signal involved: the consumer simply stops iterating, which calls the generator's
      // return() and runs its `finally`. Without `reader.cancel()` there the provider keeps
      // generating against the tenant's quota for an answer nobody will read.
      { stopAfter: 'token' },
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await stream.send('token', { text: 'Refunds ' });
    await withDeadline(run.done, 'the generator');

    expect(run.error).toBeNull();
    await withDeadline(seen.seen[0]!.closed, 'the server-side connection close');
  });

  it('raises stream_lost after the idle gap, and any byte resets the watchdog', async () => {
    const sse = await fixture();
    const controller = new AbortController();
    const run = consume(
      streamAnswer({
        conversationId: CONVERSATION,
        body: BODY,
        credential: CHAT,
        signal: controller.signal,
        apiOrigin: sse.url,
        // The production value is 45 s — three missed 15 s heartbeats. This is the same clock,
        // wound down, and it is a test seam for exactly that reason.
        idleGapMs: 250,
      }),
    );

    const stream = await sse.next();
    await stream.send('message.start', { message_id: '01JMSG' });
    await withDeadline(run.yielded('message.start'), 'message.start');

    // Two heartbeats spaced beyond the gap. A ping DISPATCHES NO EVENT, so a watchdog reset from
    // the event handler rather than from the read would fire here — and the symptom would be
    // "long answers die mid-retrieval", only ever on the slow ones.
    for (let i = 0; i < 3; i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 150));
      await stream.ping();
    }
    // Still alive after 450 ms of nothing but comments.
    expect(run.error).toBeNull();

    // Now genuine silence.
    await withDeadline(run.done, 'the idle watchdog');
    const error = run.error as KbError;
    expect(error).toBeInstanceOf(KbError);
    expect(error.error_class).toBe(STREAM_LOST);
    expect(error.message).toContain('250 ms');
  });
});

// ── the non-SSE branch ───────────────────────────────────────────────────────────────────────────

/**
 * A three-line JSON server. The SSE fixture answers `200 text/event-stream` unconditionally, so it
 * cannot produce this case, and MSW is not an option in this file for the reason in the header.
 */
async function startEnvelopeFixture(
  status: number,
  headers: Record<string, string>,
  body: string,
): Promise<{ url: string; close: () => Promise<void> }> {
  const server: Server = createServer((_request, response) => {
    response.writeHead(status, { 'content-type': 'application/json', ...headers });
    response.end(body);
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const { port } = server.address() as AddressInfo;
  return {
    url: `http://127.0.0.1:${port}`,
    close: () =>
      new Promise<void>((resolve, reject) => {
        server.close((error) => (error ? reject(error) : resolve()));
      }),
  };
}

describe('a failure that arrives as an envelope rather than as SSE', () => {
  it('branches on the content type and maps the envelope plus the Retry-After header', async () => {
    const json = await startEnvelopeFixture(
      429,
      { 'retry-after': '30', 'x-kb-request-id': '01JREQ' },
      JSON.stringify({
        error_class: 'rate_limit',
        message: 'composite window exhausted for bot 01JBOT on api-7.internal',
        retryable: true,
        request_id: '01JREQ',
      }),
    );
    try {
      const controller = new AbortController();
      const run = consume(
        streamAnswer({
          conversationId: CONVERSATION,
          body: BODY,
          credential: CHAT,
          signal: controller.signal,
          apiOrigin: json.url,
        }),
      );
      await withDeadline(run.done, 'the rejection');

      const error = run.error as KbError;
      expect(error).toBeInstanceOf(KbError);
      expect(error.error_class).toBe('rate_limit');
      // Seconds, off the HEADER. It is not in the JSON, so a mapper that only reads the body
      // produces null and the caller retries inside the window it was told to wait.
      expect(error.retry_after).toBe(30);
      expect(error.request_id).toBe('01JREQ');
    } finally {
      await json.close();
    }
  });

  it('rejects a 200 that is not text/event-stream instead of parsing it as frames', async () => {
    // A captive portal, a proxy error page, a misrouted JSON handler. Branching on the STATUS lets
    // this through into the frame parser, which then produces no event and never terminates — a
    // spinner that spins forever with a 200 in the network tab.
    const json = await startEnvelopeFixture(200, {}, JSON.stringify({ data: [] }));
    try {
      const controller = new AbortController();
      const run = consume(
        streamAnswer({
          conversationId: CONVERSATION,
          body: BODY,
          credential: CHAT,
          signal: controller.signal,
          apiOrigin: json.url,
        }),
      );
      await withDeadline(run.done, 'the rejection');

      const error = run.error as KbError;
      expect(error).toBeInstanceOf(KbError);
      // No envelope parsed => unknown => permanently non-retryable. Never an invented class.
      expect(error.error_class).toBeNull();
      expect(error.retryable).toBe(false);
    } finally {
      await json.close();
    }
  });
});

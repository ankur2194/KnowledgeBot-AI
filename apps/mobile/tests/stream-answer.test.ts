import { KbError, STREAM_LOST } from '@kb/contracts';
import type { KbEvent } from '@kb/contracts';
import { afterEach, beforeEach, describe, expect, it } from '@jest/globals';
import { createServer } from 'node:http';

import { streamAnswer } from '@/features/chat/stream-answer';

import { startSseFixture, type SseFixture } from './fixtures/sse-server';

/**
 * THE READ LOOP, against a real socket.
 *
 * Scope, stated up front so a green run is not over-read: these tests run on NODE. Node has
 * `ReadableStream` and a spec-complete `TextDecoder`; Hermes does not, and Hermes' XHR-backed
 * fetch fails by SUCCEEDING — the whole answer arrives at once and every assertion about the final
 * text still passes. So this file proves the PARSER and the LIFECYCLE. It cannot prove that
 * `res.body !== null` on a device. That is a device run.
 *
 * Device checklist this file deliberately does not attempt:
 *   - a real build streams (tokens arrive incrementally, not in one jump)
 *   - airplane mode mid-stream surfaces offline, not a server error
 *   - backgrounding mid-stream produces a server-side `finish_reason: "cancelled"` and a
 *     `user_cancellation` record — not merely a UI that stopped
 *   - a 401 mid-session preserves the composed draft
 *   - a reinstall over a live Keychain entry starts signed out
 */

const BODY = { client_message_id: '018f2b7c-0000-7000-8000-000000000000', content: 'hello' };

const CONVERSATION_ID = '01J0000000000000000000000A';

let sse: SseFixture;
/**
 * The request BODIES the fixture saw, captured as bytes off the socket.
 *
 * `requests()` hands back the `IncomingMessage`, which carries the method, the URL and the headers
 * — but its body is a stream that has to be drained while the request is live. Draining it here, in
 * the server's own request callback, is the only place that is true.
 */
let bodies: string[];

beforeEach(async () => {
  bodies = [];
  sse = await startSseFixture((request) => {
    const chunks: Buffer[] = [];
    request.on('data', (chunk: Buffer) => chunks.push(chunk));
    request.on('end', () => bodies.push(Buffer.concat(chunks).toString('utf8')));
  });
});

afterEach(async () => {
  await sse.close();
});

async function collect(
  signal: AbortSignal,
  idleGapMs = 5_000,
): Promise<{ events: KbEvent[]; error: unknown }> {
  const events: KbEvent[] = [];
  try {
    for await (const event of streamAnswer({
      conversationId: CONVERSATION_ID,
      body: BODY,
      token: 'test-token',
      signal,
      apiOrigin: sse.url,
      idleGapMs,
    })) {
      events.push(event);
    }
    return { events, error: null };
  } catch (error) {
    return { events, error };
  }
}

describe('streamAnswer', () => {
  it('asserts it actually got a streaming body', async () => {
    // The whole reason this app imports fetch from expo/fetch by name. Under Node the body is
    // always a stream, so this assertion documents the contract rather than exercising the
    // failure — the failure only exists on Hermes, and it is silent there.
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();
    const { error } = await collected;
    expect(error).toBeNull();
  });

  it('posts to rt/v1, not the admin api/v1 prefix', async () => {
    // THE DEFECT THIS PINS. `api/v1` is the ADMIN group in services/core-api/bootstrap/app.php:
    // Laravel's `api` middleware, which statefulApi() prepends EnsureFrontendRequestsAreStateful
    // to — a cookie session, CSRF, org membership. This client sends a Sanctum personal access
    // token in an Authorization header and no cookie at all, so `api/v1` aims a bearer at the one
    // group designed to reject bearers. routes/api_public.php names mobile as exactly the exception
    // that carries a PAT, on the PUBLIC runtime surface, which is mounted at `rt/v1`.
    //
    // The endpoint does not exist in Laravel yet and will not for some time — there is no chat,
    // conversation or message route, model or migration anywhere in the control plane. Asserting
    // the URL now is the point: three clients pointed at a wrong prefix with green socket-backed
    // suites is how "correct but unreachable" degrades into "confidently wrong", and this turns the
    // path from untested into pinned, so the day the route lands a wrong prefix fails here instead
    // of shipping.
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();
    await collected;

    const request = sse.requests()[0];
    expect(request?.url).toBe(`/rt/v1/conversations/${CONVERSATION_ID}/messages`);
    expect(request?.url).not.toContain('/api/v1/');
  });

  it('sends exactly {client_message_id, content} with a bearer token', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();
    await collected;

    const request = sse.requests()[0];
    expect(request?.method).toBe('POST');
    expect(request?.headers.authorization).toBe('Bearer test-token');
    expect(request?.headers.accept).toBe('text/event-stream');

    // THE BYTES THE SERVER RECEIVED, not the object this file happened to pass in. Asserting
    // `Object.keys(BODY)` only proves the fixture agrees with itself; a client that dropped a field
    // during serialisation, added an `extra`, or renamed one on the way out would sail past it.
    // `extra` is REJECTED by the FormRequest, so an added key is a 422 on every send.
    //
    // `content`, never `text`. `text` is the token EVENT's field; posting it 422s every send on
    // this client and no other, and the shared suite stays green because each client's fixtures
    // were written from the same source of truth the client was.
    expect(JSON.parse(bodies[0] ?? 'null')).toEqual({
      client_message_id: '018f2b7c-0000-7000-8000-000000000000',
      content: 'hello',
    });
    expect(Object.keys(JSON.parse(bodies[0] ?? 'null')).sort()).toEqual([
      'client_message_id',
      'content',
    ]);
  });

  it('observes real wall-clock gaps between events, not just the final text', async () => {
    // A mocked response delivers one complete body, so every timing assertion against it is
    // vacuous and a fully buffered stream passes. This measures the gap.
    //
    // THE MARGIN IS THE TEST'S OWN FAILURE MODE. This was a 120 ms fixture gap against a >= 100 ms
    // assertion: 20 ms of headroom, measured with `Date.now()`, on a runner that also gives the
    // fixture server's own `split()` a hard-coded 10 ms and can lose a scheduler slice to any other
    // worker. It flakes — and it is the ONLY test in this repo that can tell a streamed answer from
    // a buffered one, so a flake here gets muted or weakened, and after that a fully buffered
    // implementation passes silently forever. That is the expensive failure, not a red run.
    //
    // The fix is at the FIXTURE end: widen the gap the server writes, and leave the assertion
    // strictly below it with room on both sides. The assertion is not lowered — it is RAISED to
    // 250 ms, which a buffered implementation (both events arriving in one read, gap ~0 ms) fails
    // by two orders of magnitude, while a healthy stream clears it with 250 ms to spare.
    const FIXTURE_GAP_MS = 500;
    const MINIMUM_OBSERVED_GAP_MS = 250;
    const controller = new AbortController();
    const stream = sse.next();

    const arrivals: Array<{ event: string; at: number }> = [];
    const run = (async () => {
      for await (const event of streamAnswer({
        conversationId: CONVERSATION_ID,
        body: BODY,
        token: 'test-token',
        signal: controller.signal,
        apiOrigin: sse.url,
        idleGapMs: 5_000,
      })) {
        arrivals.push({ event: event.event, at: Date.now() });
      }
    })();

    const control = await stream;
    await control.send('token', { text: 'one ' });
    await new Promise((resolve) => setTimeout(resolve, FIXTURE_GAP_MS));
    const secondTokenSentAt = Date.now();
    await control.send('token', { text: 'two' });
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 2 },
    });
    await control.end();
    await run;

    expect(arrivals).toHaveLength(3);
    const first = arrivals[0];
    const second = arrivals[1];
    expect(second!.at - first!.at).toBeGreaterThanOrEqual(MINIMUM_OBSERVED_GAP_MS);
    // THE SAME CLAIM FROM THE OTHER SIDE, and the one no amount of buffering can satisfy: the first
    // token was in the consumer's hands BEFORE the server had written the second. A reader that
    // waits for the whole body cannot produce this ordering at any speed, and unlike the gap above
    // it does not depend on how long a scheduler slice took. Margin here is the full fixture gap.
    expect(first!.at).toBeLessThan(secondTokenSentAt);
  });

  it('reassembles a frame split across two chunks — including between the two newlines', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    // The split lands between "\n" and "\n": the exact boundary a naive `chunk.split('\n\n')`
    // gets wrong, and the one that never appears in a hand-written fixture.
    await control.raw('event: token\ndata: {"text":"split ');
    await new Promise((resolve) => setTimeout(resolve, 10));
    await control.raw('me"}\n');
    await new Promise((resolve) => setTimeout(resolve, 10));
    await control.raw('\n');
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();

    const { events, error } = await collected;
    expect(error).toBeNull();
    expect(events[0]).toEqual({ event: 'token', data: { text: 'split me' } });
  });

  it('decodes a multi-byte codepoint split across two chunks', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    // "grüße 🙂" — the ü is two bytes and the emoji is four. Split INSIDE the emoji. Without
    // `{ stream: true }` on TextDecoder.decode the trailing bytes are discarded and the text grows
    // a replacement character; English fixtures never catch it, and the corruption scales with
    // chunk count, so it appears on exactly the long answers people read most carefully.
    const frame = 'event: token\ndata: {"text":"grüße 🙂"}\n\n';
    const bytes = Buffer.from(frame, 'utf8');
    const emojiStart = bytes.indexOf(Buffer.from('🙂', 'utf8'));
    await control.split(frame, emojiStart + 2);

    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();

    const { events, error } = await collected;
    expect(error).toBeNull();
    expect(events[0]).toEqual({ event: 'token', data: { text: 'grüße 🙂' } });
    expect(JSON.stringify(events[0])).not.toContain('�');
  });

  it('never surfaces a heartbeat comment as an event', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    await control.ping();
    await control.send('token', { text: 'a' });
    await control.ping();
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();

    const { events } = await collected;
    // A `: ping` is an SSE comment, not an event. Rendering it puts a stray bullet in the
    // transcript; counting it as an event breaks "exactly one terminal event".
    expect(events.map((e) => e.event)).toEqual(['token', 'message.complete']);
  });

  it('yields exactly one terminal event', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    await control.send('message.start', {
      message_id: '01J1',
      conversation_id: '01J2',
      created_at: '2026-08-06T00:00:00Z',
    });
    await control.send('status', { stage: 'retrieving' });
    await control.send('citations', { citations: [] });
    await control.send('token', { text: 'answer' });
    await control.send('message.complete', {
      message_id: '01J1',
      finish_reason: 'stop',
      usage: { prompt_tokens: 10, completion_tokens: 2 },
    });
    await control.end();

    const { events, error } = await collected;
    expect(error).toBeNull();
    const terminals = events.filter((e) => e.event === 'message.complete' || e.event === 'error');
    expect(terminals).toHaveLength(1);
    // citations arrive BEFORE the first token — assigned from retrieved evidence pre-generation,
    // never parsed out of model output.
    const names = events.map((e) => e.event);
    expect(names.indexOf('citations')).toBeLessThan(names.indexOf('token'));
  });

  /**
   * A HANGUP HAS TWO SHAPES AND THEY REACH THE READ LOOP DIFFERENTLY. Both are asserted, because
   * for a long time only one of them was, and the OTHER one was the broken case.
   *
   *   FIN — a clean end of body with no terminal event. The pending `read()` RESOLVES
   *         `{done: true}` and the loop falls out normally.
   *   RST — a destroyed socket. The pending `read()` REJECTS with a `TypeError`: `terminated` on
   *         undici, `network error` in a browser, `Network request failed` on React Native.
   *
   * A loop that handles only the resolve shape rethrows a bare `TypeError` for the reject shape.
   * `instanceof KbError` is then false in the retry predicate in src/lib/query-client.ts, and a
   * dropped answer renders as a generic "something went wrong" with NO RETRY — which is exactly the
   * situation `stream_lost` exists to make retryable. Both must produce the same sentinel.
   */
  it('raises stream_lost when the peer destroys the socket mid-answer (RST)', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    await control.send('token', { text: 'half an ans' });
    // RST, not FIN. `end()` is a normal end-of-stream and drives the completed-answer path; only
    // destroying the socket reproduces the hangup that rejects a pending read.
    control.cut();

    const { events, error } = await collected;
    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBe(STREAM_LOST);
    // Retryable, which is what puts a Retry affordance in front of the user at all.
    expect((error as KbError).retryable).toBe(true);
    // The tokens that DID arrive were yielded before the failure — a half answer is real and was
    // paid for, and discarding it on the way out is a second bug hiding behind the first.
    expect(events.map((e) => e.event)).toEqual(['token']);
    // Not a 19th error class: nothing serializes it, and the caller must re-read the persisted
    // message rather than re-POSTing the same client_message_id — a re-POST re-runs a paid
    // provider call.
    expect(STREAM_LOST).toBe('stream_lost');
  });

  it('raises stream_lost when the body ends cleanly with no terminal event (FIN)', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    await control.send('token', { text: 'half an ans' });
    // A normal end-of-stream — but the contract is exactly ONE terminal event per stream, and there
    // was none. A relay that exits its loop without writing `message.complete` produces this, and
    // it is a lost answer just as surely as a destroyed socket is.
    await control.end();

    const { error } = await collected;
    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBe(STREAM_LOST);
    expect((error as KbError).retryable).toBe(true);
  });

  it('fires the idle watchdog on silence, and any byte resets it', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal, 250);
    const control = await stream;

    // Two heartbeats inside the window prove the watchdog is reset by ANY byte, including a
    // comment that dispatches no event.
    await control.send('token', { text: 'a' });
    await new Promise((resolve) => setTimeout(resolve, 150));
    await control.ping();
    await new Promise((resolve) => setTimeout(resolve, 150));
    await control.ping();
    // Then genuine silence, longer than the gap.
    const { error } = await collected;
    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBe(STREAM_LOST);
    // Deliberately NOT AbortSignal.timeout: that would have killed the stream at a fixed elapsed
    // time regardless of the heartbeats above, truncating a healthy long answer.
  });

  it('treats an abort as an outcome, not an error', async () => {
    const controller = new AbortController();
    const stream = sse.next();
    const collected = collect(controller.signal);
    const control = await stream;

    await control.send('token', { text: 'partial' });
    await new Promise((resolve) => setTimeout(resolve, 20));
    controller.abort();

    const { error } = await collected;
    // Cancellation is 499 / `user_cancellation`: no toast, no retry, no error span. The server
    // records finish_reason "cancelled" and finalizes usage from the running tally — asserting
    // THAT is a server-side test; this asserts the client does not treat it as a failure.
    expect(error).toBeNull();
  });

  it('maps a non-SSE failure response to the shared KbError, with Retry-After off the header', async () => {
    // A rejection arrives as the JSON error envelope, not as SSE. Branch on error_class, never on
    // the status: one class renders as different statuses per surface.
    //
    // `createServer` is imported statically at the top of this file, not with `await import()`.
    // Babel leaves a dynamic import intact and Jest runs this file as CommonJS, so the call reached
    // the VM as a real dynamic import and threw `A dynamic import callback was invoked without
    // --experimental-vm-modules` — a failure about the module system, in the one spec that has
    // nothing to do with it.
    const server = createServer((_request, response) => {
      response.writeHead(429, { 'content-type': 'application/json', 'retry-after': '30' });
      response.end(
        JSON.stringify({
          error_class: 'rate_limit',
          message: 'slow down',
          retryable: true,
          request_id: '01JREQ',
        }),
      );
    });
    server.listen(0, '127.0.0.1');
    await new Promise((resolve) => server.once('listening', resolve));
    const address = server.address() as { port: number };

    const controller = new AbortController();
    const events: KbEvent[] = [];
    let caught: unknown = null;
    try {
      for await (const event of streamAnswer({
        conversationId: CONVERSATION_ID,
        body: BODY,
        token: 'test-token',
        signal: controller.signal,
        apiOrigin: `http://127.0.0.1:${address.port}`,
      })) {
        events.push(event);
      }
    } catch (error) {
      caught = error;
    }
    await new Promise((resolve) => server.close(resolve));

    // Collected rather than discarded into an unused binding: the envelope path must yield NOTHING
    // before it throws. A half-rendered turn followed by an error toast is a different bug from a
    // clean rejection, and only an assertion can tell them apart.
    expect(events).toHaveLength(0);
    expect(caught).toBeInstanceOf(KbError);
    const error = caught as KbError;
    expect(error.error_class).toBe('rate_limit');
    expect(error.request_id).toBe('01JREQ');
    // Seconds, off the RESPONSE HEADER — it is not in the JSON envelope. Spelled in camelCase
    // it reads undefined and we retry inside the window we were told to wait.
    expect(error.retry_after).toBe(30);
  });
});

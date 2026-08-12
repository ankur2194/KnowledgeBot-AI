import { describe, expect, it } from 'vitest';

import { createFrameBuffer, TERMINAL_EVENTS } from '@kb/contracts';

import { startSseFixture, type SseFixture, type StreamControl } from '../fixtures/sse-server';

/**
 * `isTerminalEvent()` from @kb/contracts takes a decoded `KbEvent`, not a NAME — this file only ever
 * has the raw `event:` field off the frame, which is `string | null` before `toKbEvent()` has looked
 * at it. Widened from the exported tuple rather than re-typed, so a third terminal event added to
 * the contract lands here without an edit.
 */
const TERMINAL_EVENT_NAMES: ReadonlySet<string> = new Set<string>(TERMINAL_EVENTS);

/**
 * The fixture server, exercised over a real socket.
 *
 * `packages/contracts/test/parse-frame.test.ts` already drives the frame buffer with synthetic
 * strings, and that is the right place for the WHATWG field rules. It cannot prove the one thing
 * that matters most here: that the boundaries it simulates are boundaries a real TCP stream actually
 * produces, and that a reader built on `fetch` sees them.
 *
 * Every assertion below is about *arrival*, not about the assembled text. A correct implementation
 * and a fully buffered one produce identical final text — the observable difference is when the
 * bytes showed up. That is the whole reason `route.fulfill()` is banned on this path: it delivers
 * one buffered body, so a spec written over it is green against a UI that paints the entire answer
 * in one jump.
 *
 * ARRIVAL IS ASSERTED AS ORDER, NEVER AS A MILLISECOND BUDGET. Two assertions here used to be
 * wall-clock thresholds — `headersAt - started < 50` and `at('token') - at('citations') > 100` —
 * and neither number was a property of the code. Both are properties of the machine that ran it.
 * Under 16 busy loops on a 6-core WSL2 box the first failed 3/3 with 83.8 / 64.4 / 84.2 ms, and
 * under 48 it took the second down with it (`expected 69.98 to be greater than 100`), while the
 * transport was behaving perfectly in every one of those runs. A CI runner is noisier than that.
 * The cost is not the red build: it is that people learn to re-run until green, and the second
 * attempt is where a real regression walks through.
 *
 * The invariant underneath each of those numbers is a HAPPENS-BEFORE relation between two events
 * this test process can observe directly, so it is asserted that way instead — see `timeline`
 * below. That is still a timing assertion, and a strictly stronger one: a buffered transport cannot
 * satisfy "the client held the headers while the first body byte did not yet exist" at any speed.
 *
 * `environment: 'node'` (the `unit` project). jsdom drops Node's `ReadableStream`, `TextDecoder` and
 * `Response` globals, which are exactly what this reads.
 */

/**
 * The only number left in this file, and it is not a performance budget — it is a deadlock alarm.
 *
 * Every `withDeadline()` call below awaits something that the correct implementation settles in
 * single-digit milliseconds and that a buffering implementation NEVER settles, because the only
 * thing that could release it is a server write this test has not performed yet and will not
 * perform until the await returns. Any value between "a few ms" and the runner's own timeout
 * produces exactly the same verdict, which is precisely what the deleted thresholds could not say
 * about themselves. 2 s is two orders of magnitude above the observed settle time and comfortably
 * under Vitest's 5 s default `testTimeout`, so the failure is a legible assertion rather than the
 * runner killing the file with no explanation. Raising it costs nothing; lowering it toward the
 * real latency would re-introduce the defect this file was rewritten to remove.
 */
const HANG_MS = 2_000;

function withDeadline<T>(promise: Promise<T>, what: string): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const alarm = new Promise<never>((_resolve, reject) => {
    timer = setTimeout(() => {
      reject(
        new Error(
          `${what} never arrived. Nothing was written to the body while this was awaited, so a ` +
            `transport that holds bytes until the stream ends can never release it. See HANG_MS.`,
        ),
      );
    }, HANG_MS);
  });
  return Promise.race([promise, alarm]).finally(() => {
    clearTimeout(timer);
  });
}

/** Assert `first` was recorded strictly before `second`, and that both were recorded at all. */
function expectHappensBefore(timeline: readonly string[], first: string, second: string): void {
  const a = timeline.indexOf(first);
  const b = timeline.indexOf(second);
  // Both presence checks matter. `indexOf` returns -1 for a missing entry, and -1 < anything, so a
  // renamed marker would satisfy the ordering comparison while proving nothing.
  expect(timeline, `${first} was never recorded`).toContain(first);
  expect(timeline, `${second} was never recorded`).toContain(second);
  expect(
    a,
    `${first} (${a}) must precede ${second} (${b}) in ${JSON.stringify(timeline)}`,
  ).toBeLessThan(b);
}

interface DrainResult {
  events: Array<{ name: string | null }>;
  text: string;
  error: unknown;
}

interface Drain {
  /** Settles when the response HEADERS reach the client, independently of any body byte. */
  headers: Promise<Response>;
  /** Settles once the client has PARSED a frame with this event name. */
  observed: (name: string) => Promise<void>;
  /** Settles when the body is exhausted — by FIN or by RST. Never rejects; see `error`. */
  done: Promise<DrainResult>;
  /**
   * Hang up from the client side. Only teardown calls this, and it is not optional: `server.close()`
   * waits for open connections, so a test that fails while the stream is still open would block in
   * its own `finally` and be reported as `Test timed out in 5000ms` with the real assertion nowhere
   * in the output. That was observed, not imagined — it is what the first red-phase run produced.
   */
  abort: () => void;
}

/**
 * Read the stream, recording every observation into `timeline` as it happens.
 *
 * `timeline` is shared with `record()` on the server side, so one array holds both halves of the
 * conversation in the order this process actually observed them. That array is the machine-
 * independent substitute for the stopwatch: it cannot be satisfied by a fast machine or defeated by
 * a slow one, because every entry in it is caused by the test itself.
 */
function drain(url: string, timeline: string[]): Drain {
  const seen: Array<{ name: string | null }> = [];
  const waiting: Array<{ name: string; resolve: () => void; reject: (cause: unknown) => void }> =
    [];
  const hangUp = new AbortController();

  let settleHeaders!: (response: Response) => void;
  let failHeaders!: (cause: unknown) => void;
  const headers = new Promise<Response>((resolve, reject) => {
    settleHeaders = resolve;
    failHeaders = reject;
  });
  // A rejection nobody awaited would surface as an unhandled rejection and fail an unrelated file.
  void headers.catch(() => {});

  const done: Promise<DrainResult> = (async () => {
    // ONE decoder for the whole stream, in streaming mode. A decoder constructed per chunk emits
    // U+FFFD for each half of a split codepoint, and the mid-UTF-8 assertion below is what catches
    // it.
    const decoder = new TextDecoder('utf-8');
    const buffer = createFrameBuffer();
    let text = '';
    let error: unknown = null;

    let response: Response;
    try {
      response = await fetch(url, { signal: hangUp.signal });
    } catch (cause) {
      failHeaders(cause);
      return { events: seen, text, error: cause };
    }
    // Recorded BEFORE the promise is settled, so anything awaiting `headers` already sees the entry.
    timeline.push('client:headers');
    settleHeaders(response);

    try {
      for await (const bytes of response.body as unknown as AsyncIterable<Uint8Array>) {
        const decoded = decoder.decode(bytes, { stream: true });
        text += decoded;
        for (const frame of buffer.push(decoded)) {
          seen.push({ name: frame.event });
          timeline.push(`client:${frame.event ?? '<unnamed>'}`);
          // Drain the queue and push back whatever this frame did not satisfy. Index-free on
          // purpose: `splice` returns a fresh array, so the iteration is over the removed copy and
          // the re-queue below cannot disturb it.
          for (const pending of waiting.splice(0, waiting.length)) {
            if (pending.name === frame.event) pending.resolve();
            else waiting.push(pending);
          }
        }
      }
      text += decoder.decode();
    } catch (cause) {
      // A peer that vanished. Not a failure here — it is the case under test.
      error = cause;
    }

    // Anything still waiting is waiting for an event that will never come. Reject loudly rather
    // than leaving the test to die on the runner's timeout with no cause attached.
    while (waiting.length > 0) {
      const pending = waiting.pop();
      pending?.reject(new Error(`the stream ended before any '${pending.name}' frame arrived`));
    }

    return { events: seen, text, error };
  })();

  return {
    headers,
    done,
    abort() {
      hangUp.abort();
    },
    observed(name: string) {
      if (seen.some((event) => event.name === name)) return Promise.resolve();
      const promise = new Promise<void>((resolve, reject) => {
        waiting.push({ name, resolve, reject });
      });
      void promise.catch(() => {});
      return promise;
    },
  };
}

/**
 * Tear down in the one order that keeps a failure legible.
 *
 * Client first: `server.close()` waits for open connections, so closing the server while the
 * response is still streaming blocks forever — and a `finally` that blocks converts every real
 * assertion failure in the body into `Test timed out in 5000ms` with the cause discarded.
 */
async function shutdown(sse: SseFixture, run: Drain): Promise<void> {
  run.abort();
  await run.done;
  await sse.close();
}

/**
 * Wrap the fixture's control surface so every server-side action lands in the same timeline.
 *
 * The marker is pushed BEFORE the write is issued, not after it resolves. That dates the server's
 * action as early as it could possibly be, which makes every `client:X before server:Y` assertion
 * the hardest available version of the claim — the opposite of the direction a flake gets fixed in.
 */
function record(stream: StreamControl, timeline: string[]): StreamControl {
  const note = (what: string) => {
    timeline.push(`server:${what}`);
  };
  return {
    send(event, data) {
      note(event);
      return stream.send(event, data);
    },
    raw(chunk) {
      note('raw');
      return stream.raw(chunk);
    },
    split(text, atByte) {
      note('split');
      return stream.split(text, atByte);
    },
    ping() {
      note('ping');
      return stream.ping();
    },
    end() {
      note('end');
      return stream.end();
    },
    cut() {
      note('cut');
      stream.cut();
    },
  };
}

describe('the SSE fixture server', () => {
  it('flushes headers before the first body byte, so the pre-token UI is observable', async () => {
    const sse: SseFixture = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);

      // ORDER, NOT DURATION. `sse.next()` resolves inside the request handler, after `writeHead()`
      // and `flushHeaders()` and before a single body byte exists. So this await either returns
      // while the body is still empty, or it does not return at all — there is no third outcome and
      // no threshold to tune. Without `flushHeaders()` in the fixture, Node holds the header until
      // the first body write — and the first `stream.send()` in this test is below this line, so
      // that mutation deadlocks here rather than arriving 63.9 ms late on a busy laptop.
      const response = await withDeadline(run.headers, 'the response headers');

      expect(response.headers.get('content-type')).toBe('text/event-stream');
      // The whole proof, in one line: the client has the headers and the server has done nothing
      // else. Every `server:*` marker is still ahead of us in program order.
      expect(timeline).toEqual(['client:headers']);

      await stream.send('message.start', { message_id: '01JMSG' });
      await stream.send('message.complete', { finish_reason: 'stop' });
      await stream.end();

      const { events } = await run.done;
      expect(events.map((e) => e.name)).toEqual(['message.start', 'message.complete']);
      // Still first once everything has been written, which is what makes the assertion above a
      // statement about the run rather than about the moment it was sampled.
      expect(timeline[0]).toBe('client:headers');
    } finally {
      await shutdown(sse, run);
    }
  });

  it('delivers each event as it is written, never batched at the end of the stream', async () => {
    const sse = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);

      await stream.send('message.start', { message_id: '01JMSG' });
      await stream.send('citations', { citations: [{ index: 1, title: 'Refund policy' }] });

      // THE ASSERTION IS THIS AWAIT. The client parses the `citations` frame at a moment when the
      // `token` frame does not exist anywhere — not on the wire, not in a buffer, not in this test,
      // which has not called `send('token')` yet and cannot until this line returns. A transport
      // that holds the body until the stream ends deadlocks here instead of arriving 100 ms late,
      // so the verdict does not depend on how fast the box is. It replaces a 150 ms server-side
      // sleep and a `> 100 ms` gap assertion that failed on a loaded laptop with 69.98 ms while the
      // transport was behaving correctly.
      await withDeadline(run.observed('citations'), 'the citations frame');

      await stream.send('token', { text: 'Refunds ' });
      await stream.send('message.complete', { finish_reason: 'stop' });
      await stream.end();

      const { events } = await run.done;
      expect(events.map((e) => e.name)).toEqual([
        'message.start',
        'citations',
        'token',
        'message.complete',
      ]);

      // Written out as a happens-before over the shared timeline as well as an event order, because
      // the two claims are different: the list above says the frames arrived in the right sequence,
      // this says the client saw one of them before the server produced the next.
      expectHappensBefore(timeline, 'client:citations', 'server:token');

      // Citations are assigned from retrieved evidence before generation, never parsed out of model
      // output — so they precede the first token on the wire, always.
      expectHappensBefore(timeline, 'client:citations', 'client:token');
    } finally {
      await shutdown(sse, run);
    }
  });

  it('survives a frame split inside a multi-byte codepoint', async () => {
    const sse = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);

      const frame = `event: token\ndata: ${JSON.stringify({ text: 'Rückerstattungen' })}\n\n`;
      // 0xC3 is the first byte of `ü`; +1 lands between the two bytes of the codepoint. Computed
      // rather than hardcoded, so editing the string cannot silently move the split onto a
      // character boundary and turn the nastiest case in the suite into the most ordinary one.
      const atByte = Buffer.from(frame, 'utf8').indexOf(0xc3) + 1;

      await stream.send('message.start', { message_id: '01JMSG' });
      // POSITIVE CONTROL, and not optional. Under a transport that holds the whole body until end
      // of stream there IS no chunk boundary, so the reader gets one complete buffer, the decoder is
      // never asked to carry state across a read, and this test passes for the wrong reason — the
      // exact vacuity `route.fulfill()` is banned for. Proving bytes are already flowing before the
      // split is written is what keeps the assertion below about the decoder.
      await withDeadline(run.observed('message.start'), 'the message.start frame');
      await stream.split(frame, atByte);
      await stream.send('message.complete', { finish_reason: 'stop' });
      await stream.end();

      const { text, events } = await run.done;

      // The bug this catches only ever appears in German, Hindi or an emoji — never in an
      // English-language fixture, and never in staging until a customer uploads a German document.
      expect(text).not.toContain('�');
      expect(events.map((e) => e.name)).toEqual(['message.start', 'token', 'message.complete']);
      const token = events.find((e) => e.name === 'token');
      expect(token).toBeDefined();
      expect(text).toContain('Rückerstattungen');
    } finally {
      await shutdown(sse, run);
    }
  });

  it('never surfaces a heartbeat comment as an event', async () => {
    const sse = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);

      await stream.send('message.start', { message_id: '01JMSG' });
      // Same positive control as the split spec: the heartbeats must reach a reader that is already
      // reading, not a reader handed one buffer at the end.
      await withDeadline(run.observed('message.start'), 'the message.start frame');
      await stream.ping();
      await stream.ping();
      await stream.send('message.complete', { finish_reason: 'stop' });
      await stream.end();

      const { text, events } = await run.done;
      // On the wire, and not in the event list. A parser that dispatches `: ping` fires an
      // `onmessage` with empty data every 15 s, which the chat store reads as an empty token.
      expect(text).toContain(': ping');
      expect(events.map((e) => e.name)).toEqual(['message.start', 'message.complete']);
    } finally {
      await shutdown(sse, run);
    }
  });

  it('distinguishes a vanished peer from a completed answer', async () => {
    const sse = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);

      await stream.send('message.start', { message_id: '01JMSG' });
      await stream.send('token', { text: 'Refunds ' });
      // POSITIVE CONTROL BEFORE THE NEGATIVE ONE. "No terminal event arrived" is satisfied by a
      // stream that delivered nothing at all, so a partial answer has to be proved present first —
      // otherwise this spec is green against a transport that never wrote a byte, and the UI state
      // it is protecting (partial answer + Retry, never a completed answer) is untested.
      await withDeadline(run.observed('token'), 'the token frame');
      // RST, not FIN. `end()` here would be a NORMAL end of stream and would drive the completed
      // answer path — the fixture bug that makes a whole cancellation suite green over nothing.
      stream.cut();

      const { events, error } = await run.done;
      expect(events.map((e) => e.name)).toEqual(['message.start', 'token']);
      expect(error).not.toBeNull();
      expect(events.some((e) => e.name !== null && TERMINAL_EVENT_NAMES.has(e.name))).toBe(false);

      // `stream_lost` is a CLIENT-LOCAL sentinel for exactly this case — no terminal event arrived
      // because the connection itself died. It is not one of the 18 wire classes and must never
      // appear in an `error_class` field; the client raises it internally and offers Retry.
    } finally {
      await shutdown(sse, run);
    }
  });

  it('records every request, so an auth assertion has something to read', async () => {
    const sse = await startSseFixture();
    const timeline: string[] = [];
    // Outside the `try`, because `shutdown()` needs it. `drain()` starts the request and never
    // throws, so there is nothing here for the `try` to have caught.
    const run = drain(`${sse.url}/chat`, timeline);
    try {
      const stream = record(await sse.next(), timeline);
      await stream.send('message.complete', { finish_reason: 'stop' });
      await stream.end();
      await run.done;

      expect(sse.requests()).toHaveLength(1);
      expect(sse.requests()[0]?.url).toBe('/chat');
    } finally {
      await shutdown(sse, run);
    }
  });
});

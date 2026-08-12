/**
 * SSE fixture server — `apps/web` copy.
 *
 * TWIN: `apps/mobile/tests/fixtures/sse-server.ts`. Everything below the TWIN REGION marker is
 * byte-identical with that file, and `packages/contracts/test/sse-fixture-drift.test.ts` fails the
 * build if the two ever diverge. Fix a bug here, paste it there — or the other way round; the drift
 * test does not care which, only that they agree.
 *
 * WHY THERE ARE TWO COPIES AND NOT ONE PACKAGE. There are exactly two workspace packages,
 * `@kb/contracts` and `@kb/design-tokens`, and inventing a third is a finding rather than a refactor
 * (CLAUDE.md). These are test harnesses for two different runners — Vitest here, Jest in
 * `apps/mobile` — in workspaces with their own lockfiles. The thing that genuinely must not be
 * duplicated is the frame PARSER, and that is imported from `@kb/contracts` by both. A duplicated
 * fixture server that is provably identical is a smaller problem than a third package; a duplicated
 * fixture server that has silently drifted is a bigger one, which is what the drift test is for.
 *
 * HOW TO PUT THIS IN FRONT OF A BROWSER — the `apps/web`-specific part, and the only legal way:
 * redirect the request so the browser fetches it for real. `route.continue({ url })` leaves the
 * response stage untouched, so the chunking survives (same protocol required, hence `http://`):
 *
 *   await page.route('**' + '/api/v1/chat/*' + '/messages', (r) => r.continue({ url: `${sse.url}/chat` }));
 *
 * `route.fulfill()` cannot do this and never will: Playwright base64-encodes the whole body into one
 * CDP `Fetch.fulfillRequest` and registers interception at the Request stage only, so a fulfilled
 * response can never arrive in pieces. `route.fetch()` buffers too. MSW can stream, but a client
 * `AbortController.abort()` does not reach the handler under `setupServer`, so the stop-generation
 * path — the whole reason the cancellation design exists — is never exercised. Each of those
 * produces a green suite over a broken read loop.
 */

// ─── TWIN REGION BEGIN — byte-identical with the twin named above ────────────────────────────────
import { once } from 'node:events';
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';

/**
 * A real SSE server over a real socket.
 *
 * It exists because a mocked response cannot fail the tests that matter. A mock hands the reader one
 * complete body, so every chunk is a whole frame and every timing assertion is vacuous; the suite
 * goes green over a read loop that drops tokens the moment a frame straddles a TCP boundary. The
 * bugs this file reproduces — a frame split between its two newlines, a UTF-8 codepoint split across
 * chunks, a peer that vanishes without a terminal event — only exist when bytes actually cross a
 * socket.
 */
export interface StreamControl {
  /** Write a complete `event:`/`data:` frame. */
  send(event: string, data: unknown): Promise<void>;
  /** Write a raw string — for frames split mid-line or between the two newlines. */
  raw(chunk: string): Promise<void>;
  /**
   * Split a payload at a BYTE offset, which is how you land inside a multi-byte codepoint. The same
   * answer streams cleanly in English and grows replacement characters in German or Hindi, and no
   * English fixture can catch it.
   */
  split(text: string, atByte: number): Promise<void>;
  /** The heartbeat comment. It must never surface as an event. */
  ping(): Promise<void>;
  /** Clean end of stream: a normal FIN. */
  end(): Promise<void>;
  /**
   * Vanished peer: RST, not FIN. `res.end()` is a NORMAL end-of-stream and drives the completed
   * answer path; only destroying the socket reproduces the hangup that must yield `stream_lost`.
   */
  cut(): void;
}

export interface SseFixture {
  url: string;
  /** Resolves with the control surface once a client connects. */
  next(): Promise<StreamControl>;
  /** Every request the server saw — used to assert the Authorization header and the POST body. */
  requests(): readonly IncomingMessage[];
  close(): Promise<void>;
}

export async function startSseFixture(
  onRequest?: (request: IncomingMessage) => void,
): Promise<SseFixture> {
  const waiting: Array<(control: StreamControl) => void> = [];
  const queued: StreamControl[] = [];
  const seen: IncomingMessage[] = [];

  const server: Server = createServer((request, response) => {
    seen.push(request);
    onRequest?.(request);

    response.writeHead(200, {
      'content-type': 'text/event-stream',
      'cache-control': 'no-store',
      connection: 'keep-alive',
    });
    // Without this, Node holds the headers until the first body write and `await fetch()` in the
    // test hangs until the first token — so nothing about the pre-token UI can be tested at all.
    response.flushHeaders();
    // Nagle's algorithm merges two events written back to back into one read, and a timing
    // assertion then flakes.
    response.socket?.setNoDelay(true);

    const control = makeControl(response);
    const resolve = waiting.shift();
    if (resolve) resolve(control);
    else queued.push(control);
  });

  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const { port } = server.address() as AddressInfo;

  return {
    url: `http://127.0.0.1:${port}`,
    next: () =>
      new Promise<StreamControl>((resolve) => {
        const ready = queued.shift();
        if (ready) resolve(ready);
        else waiting.push(resolve);
      }),
    requests: () => seen,
    close: () =>
      new Promise<void>((resolve, reject) => {
        server.close((error) => (error ? reject(error) : resolve()));
      }),
  };
}

function makeControl(response: ServerResponse): StreamControl {
  // Await the write CALLBACK, not the return value: `write()` returning false means the buffer is
  // full, not that the bytes left.
  const write = (chunk: string | Uint8Array): Promise<void> =>
    new Promise<void>((resolve, reject) => {
      response.write(chunk, (error) => (error ? reject(error) : resolve()));
    });

  return {
    send: (event, data) => write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`),
    raw: (chunk) => write(chunk),
    async split(text, atByte) {
      const bytes = Buffer.from(text, 'utf8');
      await write(bytes.subarray(0, atByte));
      // A real gap between the two halves, so the reader genuinely sees two chunks. Without it the
      // two writes coalesce in the socket buffer and the split never reaches the parser.
      await new Promise((resolve) => setTimeout(resolve, 10));
      await write(bytes.subarray(atByte));
    },
    ping: () => write(': ping\n\n'),
    end: () =>
      new Promise<void>((resolve) => {
        response.end(resolve);
      }),
    cut() {
      response.socket?.destroy();
    },
  };
}

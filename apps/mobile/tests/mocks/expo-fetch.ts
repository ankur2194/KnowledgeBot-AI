import { request as httpRequest, type ClientRequest, type IncomingMessage } from 'node:http';

/**
 * `expo/fetch` under Jest — a streaming transport built on `node:http`.
 *
 * WHY NOT `globalThis.fetch`. What sits on the global inside a jest-expo run is not Node's fetch.
 * It is a Babel-transpiled stub (`function fetch(_x, _x2) { return _fetch.apply(this, arguments) }`)
 * that resolves in ~3 ms with a Response-shaped object carrying a `body` and NO `status` — which is
 * where the `KbError: HTTP undefined` in the old failure output came from, since `toKbError` reads
 * `response.status`. It cannot be captured around either: Jest seeds each test realm from the
 * WORKER process's globals, and the worker has loaded jest-expo's preset before the environment is
 * constructed, so the stub is already in place. Capturing before `super.setup()`, after it, and in
 * a setup file were all tried; all three see the stub.
 *
 * WHY NOT `undici` DIRECTLY. It is the implementation behind Node's own fetch and it connects fine
 * from here — `status 200`, `body` non-null — but the WHATWG body it hands back never pumps inside
 * this realm: `reader.read()` on a response whose frame the server had already written stayed
 * pending until the idle watchdog fired at 5 s, with and without an AbortSignal. `node:http` in the
 * same process, in the same test, returned 200 and delivered its chunks.
 *
 * So the transport is the layer that demonstrably works, wrapped in the surface the read loop
 * actually uses. The stream below is push-based and built from the TEST REALM's `ReadableStream`,
 * which is what removes the pumping problem: bytes are enqueued as `data` events arrive rather than
 * pulled through a cross-realm adapter.
 *
 * WHAT IS DELIBERATELY FAITHFUL, because the specs depend on it:
 *   - chunk boundaries are the socket's, never re-framed — a frame split across two writes reaches
 *     the parser as two chunks, which is the whole point of the fixture server;
 *   - an abort destroys the request and errors the stream with `name: 'AbortError'`, so the read
 *     loop takes its cancellation path (499 / `user_cancellation`) and not its failure path;
 *   - a peer that vanishes (`aborted`, or `close` before `end`) ERRORS the stream with a
 *     `TypeError`, because that is what every runtime this app ships on actually does — see below.
 *
 * READ THIS BEFORE TRUSTING A GREEN RUN. None of this changes what these tests can prove. Hermes'
 * XHR-backed fetch returns a `Response` whose `body` is `null`; it does not throw and does not warn,
 * it resolves once with the whole answer. Nothing here reproduces that, so:
 *
 *   - what these tests CAN prove: frame re-assembly across chunk boundaries, multi-byte codepoints
 *     split across chunks, heartbeat comments never surfacing as events, exactly one terminal
 *     event, the idle watchdog, and that abort tears the read loop down;
 *   - what they CANNOT prove: that `res.body !== null` on Hermes.
 *
 * That second proof is a device run against a real build, and it is a checklist item, not a test
 * file. The production module keeps its `import { fetch } from 'expo/fetch'` by name and asserts
 * `res.body !== null` at runtime, so the degradation stays loud on the device even though it is
 * unreachable here.
 *
 * THIS COMMENT USED TO SAY THAT NAMED IMPORT IS "WHAT CI GREPS FOR". It never was — no such grep
 * existed in any workflow or ESLint config on the day that claim was written, and there is no CI now.
 * The import is now enforced by `eslint.config.mjs`'s `src/features/chat/**` block, which bans the
 * global `fetch`, `globalThis.fetch`, and a by-name `fetch` from any other module — proven by
 * mutation, and running under `pnpm lint`. Note what that does NOT buy: deleting the import today
 * also fails 20 tests here, but every one of them reads `KbError: HTTP undefined` because of the
 * jest-expo stub described above, so the suite blames the server. Do not read those failures as
 * coverage of the streaming constraint.
 */

interface FetchInit {
  method?: string;
  headers?: Record<string, string>;
  body?: string;
  signal?: AbortSignal;
}

function abortError(): Error {
  const error = new Error('The operation was aborted');
  error.name = 'AbortError';
  return error;
}

/**
 * WHAT A DESTROYED SOCKET DOES TO A PENDING `read()`, and the false green this double used to hand
 * out.
 *
 * This mock previously CLOSED the stream when the peer vanished, which made a pending `read()`
 * resolve `{done: true}` — the same shape as a clean end-of-body. The read loop then fell out of
 * its `for(;;)` and raised `stream_lost` from its own missing-terminal-event check, and the hangup
 * spec passed. No runtime behaves that way. A FIN resolves `{done: true}`; an RST REJECTS the
 * pending read, as `TypeError: terminated` on undici, `TypeError: network error` in a browser, and
 * `TypeError: Network request failed` on React Native. A loop that only handles the resolve shape
 * rethrows a bare `TypeError`, `instanceof KbError` is false in the retry predicate, and a dropped
 * answer renders as a generic failure with no Retry — the exact case the sentinel exists for.
 *
 * So the double rejects, and the difference between FIN and RST is preserved rather than flattened.
 * `cause` carries the underlying Node error for anyone reading a failure message.
 */
function transportError(cause?: unknown): TypeError {
  return new TypeError('terminated', { cause });
}

/**
 * Every byte is ALSO captured here as it goes past, and `text()` reads from this rather than from
 * the socket. It has to: `ReadableStream.start()` runs synchronously at construction and attaches
 * the `data` listener, so an IncomingMessage begins flowing immediately — by the time the read loop
 * has looked at the status, decided this is the error path, and called `json()`, the `end` event has
 * already fired and a second set of listeners would wait forever. That is exactly how the
 * Retry-After spec went from a wrong assertion to a 5 s timeout.
 */
interface BodySink {
  readonly chunks: Uint8Array[];
  readonly finished: Promise<void>;
}

function makeBody(
  message: IncomingMessage,
  request: ClientRequest,
  sink: BodySink,
  settle: () => void,
  signal?: AbortSignal,
) {
  return new ReadableStream<Uint8Array>({
    start(controller) {
      let closed = false;
      let ended = false;

      const close = () => {
        if (closed) return;
        closed = true;
        settle();
        controller.close();
      };

      /** RST, not FIN: the pending `read()` REJECTS. See `transportError` above. */
      const fail = (cause?: unknown) => {
        if (closed) return;
        closed = true;
        settle();
        controller.error(transportError(cause));
      };

      message.on('data', (chunk: Buffer) => {
        sink.chunks.push(new Uint8Array(chunk));
        if (!closed) controller.enqueue(new Uint8Array(chunk));
      });
      // FIN: a clean end of body. This is the ONLY path that closes the stream.
      message.on('end', () => {
        ended = true;
        close();
      });
      message.on('aborted', () => fail(new Error('socket destroyed by peer')));
      message.on('close', () => {
        if (!ended) fail(new Error('socket closed before end of body'));
      });
      message.on('error', (error: unknown) => fail(error));

      if (signal !== undefined) {
        const onAbort = () => {
          if (closed) return;
          closed = true;
          settle();
          request.destroy();
          message.destroy();
          controller.error(abortError());
        };
        if (signal.aborted) onAbort();
        else signal.addEventListener('abort', onAbort, { once: true });
      }
    },
    cancel() {
      // An early `return` from the generator must still close the socket, or a real server would
      // keep generating against the tenant's quota for an answer nobody will read.
      message.destroy();
      request.destroy();
    },
  });
}

export const fetch = ((input: string | URL, init: FetchInit = {}) =>
  new Promise((resolve, reject) => {
    const url = new URL(String(input));
    if (init.signal?.aborted === true) {
      reject(abortError());
      return;
    }

    const request = httpRequest(
      {
        protocol: url.protocol,
        hostname: url.hostname,
        port: url.port,
        path: `${url.pathname}${url.search}`,
        method: init.method ?? 'GET',
        headers: init.headers ?? {},
      },
      (message) => {
        const status = message.statusCode ?? 0;

        let settle: () => void = () => {};
        const sink: BodySink = {
          chunks: [],
          finished: new Promise<void>((done) => {
            settle = done;
          }),
        };

        // Only ever used on the ERROR path, where the read loop throws before touching `body`.
        const text = async (): Promise<string> => {
          await sink.finished;
          return Buffer.concat(sink.chunks.map((chunk) => Buffer.from(chunk))).toString('utf8');
        };

        resolve({
          ok: status >= 200 && status < 300,
          status,
          headers: {
            get: (name: string): string | null => {
              const value = message.headers[name.toLowerCase()];
              if (value === undefined) return null;
              return Array.isArray(value) ? value.join(', ') : value;
            },
          },
          body: makeBody(message, request, sink, settle, init.signal),
          text,
          json: async (): Promise<unknown> => JSON.parse(await text()),
        });
      },
    );

    request.on('error', (error) => {
      // A CONNECTION FAILURE ARRIVES AS A `TypeError`, and reproducing that is not cosmetic — it is
      // the offline signal the whole app branches on. `node:http` emits a bare `Error` carrying
      // `code: 'ECONNREFUSED'`; every fetch this app actually runs on wraps it: React Native throws
      // `TypeError: Network request failed`, Node's own fetch and expo/fetch throw
      // `TypeError: fetch failed` with the original as `cause`. Passing the raw Node error through
      // would make a dead radio arrive here as a shape no runtime produces, and the offline path
      // would be untestable — or worse, "fixed" by teaching production code to recognise a Node
      // error code that never reaches a device.
      reject(
        init.signal?.aborted === true
          ? abortError()
          : new TypeError('Network request failed', { cause: error }),
      );
    });

    if (init.signal !== undefined) {
      init.signal.addEventListener(
        'abort',
        () => {
          request.destroy();
        },
        { once: true },
      );
    }

    if (init.body !== undefined) request.write(init.body);
    request.end();
  })) as unknown as typeof globalThis.fetch;

export default { fetch };

import type { RequestHandler } from 'msw';

/**
 * MSW mocks PLAIN JSON endpoints in component tests, and NOTHING on the chat path.
 *
 * It can stream — `HttpResponse` takes a ReadableStream and `sse()` exists — but a client
 * `AbortController.abort()` does not reach the handler under `setupServer`, so `request.signal`
 * never fires, the stream's `cancel()` never runs, and the consumer keeps receiving chunks after
 * the stop button. That is the exact path the §8.18 stop-generation action and the
 * `finish_reason: "cancelled"` accounting exist for. The chat path uses tests/fixtures/sse-server.ts.
 *
 * Two more reasons `sse()` is unusable here: it requires `accept: text/event-stream` on the
 * request, and constructing the handler throws outright when the browser's built-in SSE
 * constructor is missing from `globalThis` — which it is in Vitest's node environment. (That
 * identifier is deliberately not spelled anywhere in this package; CI greps for it.)
 *
 * Every payload built here is typed from `@kb/contracts`. A fixture typed by hand encodes what the
 * author believed the wire says, so a server-side rename ships instead of failing a typecheck.
 */
export const handlers: RequestHandler[] = [];

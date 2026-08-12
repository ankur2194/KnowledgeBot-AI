/**
 * One dispatched SSE frame, after WHATWG field interpretation and before any JSON parsing.
 *
 * A frame is NOT a chunk: a single `read()` can deliver half a frame, three frames, or a frame
 * split mid-UTF-8 codepoint. Re-assembly is `createFrameBuffer()` in ./parse-frame.ts, and getting
 * it wrong produces a UI that works locally and drops tokens in production.
 */
export interface SseFrame {
  /** The `event:` field, or null when the block carried none (WHATWG's default is "message"). */
  readonly event: string | null;
  /** The `data:` buffer: multiple `data:` lines joined with "\n", trailing newline removed. */
  readonly data: string;
  /** The `id:` field. A per-stream sequence for GAP DETECTION ONLY — token streams are not
   *  resumable, and nothing here ever sends it back as `Last-Event-ID`. */
  readonly id: string | null;
  /** The `retry:` field in milliseconds. The server sends `retry: 10000` on stream open so a mass
   *  drop does not reconnect-storm at the browser's 3 s default. */
  readonly retry: number | null;
}

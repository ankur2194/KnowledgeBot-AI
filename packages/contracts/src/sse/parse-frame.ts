import type { SseFrame } from './frame.js';

/**
 * Parse ONE event-stream block (the text between two blank lines) per the WHATWG
 * "event stream interpretation" rules. Declared here and nowhere else: a second implementation is
 * the drift `contract-steward` exists to catch, and the three clients would diverge on exactly the
 * chunk-boundary case none of their fixtures cover.
 *
 * NOT enforced by anything today. No lint rule covers it, and while a deleted CI job did grep under
 * apps/ and packages/, the closest it comes is `repo-artifact-consistency` diffing the two SSE
 * FIXTURE SERVERS (apps/web and apps/mobile) — the test doubles, not this parser. Reviewer check,
 * anchored on a DEFINITION so it does not match prose mentioning the rule (the unanchored
 * `rg "function parseFrame" apps/` matched apps/widget's comment about it):
 *
 *   grep -rnE '^[[:space:]]*(export )?function parseFrame' apps/     # must be empty (verified)
 *
 * Returns `null` for a block that dispatches nothing: a comment-only block (`: ping` — an SSE
 * comment, not an event, and rendering it as one puts a stray bullet in the transcript), an empty
 * block, or a block whose data buffer ended up empty, which the spec says must not dispatch.
 */
export function parseFrame(block: string): SseFrame | null {
  if (block === '') return null;

  let event: string | null = null;
  let id: string | null = null;
  let retry: number | null = null;
  const data: string[] = [];
  let sawData = false;

  for (const line of block.split('\n')) {
    if (line === '') continue;
    // A line starting with U+003A COLON is a comment and is ignored entirely.
    if (line.charCodeAt(0) === 0x3a) continue;

    const colon = line.indexOf(':');
    const field = colon === -1 ? line : line.slice(0, colon);
    let value = colon === -1 ? '' : line.slice(colon + 1);
    // EXACTLY ONE leading space is removed — "data:  x" carries a leading space in its value, and
    // trimming here corrupts pre-formatted model output and code blocks.
    if (value.charCodeAt(0) === 0x20) value = value.slice(1);

    switch (field) {
      case 'event':
        event = value;
        break;
      case 'data':
        data.push(value);
        sawData = true;
        break;
      case 'id':
        // The spec ignores an id field whose value contains U+0000 (escaped, never a raw NUL byte).
        if (!value.includes('\u0000')) id = value;
        break;
      case 'retry':
        if (/^\d+$/.test(value)) retry = Number(value);
        break;
      default:
        // Unknown field names are ignored, not an error. This is what lets the wire add a field
        // without breaking three deployed clients.
        break;
    }
  }

  if (!sawData) return null;
  // Multiple data lines join with "\n"; the spec's trailing-newline strip is implicit in the join.
  const body = data.join('\n');
  if (body === '') return null;

  return { event, data: body, id, retry };
}

/**
 * Re-assembles frames across chunk boundaries. This is the half that breaks in production: every
 * mocked chunk in a naive test is a whole frame, while real segments split between "\n" and "\n",
 * mid-`data:` line, and mid-UTF-8 codepoint.
 *
 * Feed it STRINGS decoded with `TextDecoder.decode(value, { stream: true })` — the decoder owns the
 * codepoint half of the problem, this owns the frame half. Three clients share it so there is one
 * implementation to get right.
 */
export function createFrameBuffer(): {
  push(chunk: string): SseFrame[];
  /**
   * Ends the stream. A block that never reached its blank line is DISCARDED — the spec is explicit
   * that an incomplete event at end-of-stream is not dispatched, and half a `token` frame is half a
   * JSON document. Always returns an empty array; it exists to reset state and to make "the stream
   * ended without a terminal event" the caller's decision, which is where `stream_lost` is raised.
   */
  flush(): SseFrame[];
} {
  let pending = '';

  return {
    push(chunk: string): SseFrame[] {
      pending += chunk;
      // A trailing "\r" may be the first half of a "\r\n" whose "\n" is in the next chunk.
      // Normalising it now would turn one line break into two and split a frame in half.
      const held = pending.endsWith('\r');
      const workable = held ? pending.slice(0, -1) : pending;
      const blocks = workable.replace(/\r\n|\r/g, '\n').split('\n\n');
      pending = (blocks.pop() ?? '') + (held ? '\r' : '');

      const frames: SseFrame[] = [];
      for (const block of blocks) {
        const frame = parseFrame(block);
        if (frame !== null) frames.push(frame);
      }
      return frames;
    },
    flush(): SseFrame[] {
      pending = '';
      return [];
    },
  };
}

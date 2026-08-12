/**
 * THE SSE HALF OF THE API HARNESS — a real `text/event-stream` over a real socket.
 *
 * WHY IT LIVES HERE AND NOT IN A THIRD COPY OF THE apps/web / apps/mobile FIXTURE. Those two are
 * byte-identical twins, and `packages/contracts/test/sse-fixture-drift.test.ts` fails the build when
 * they diverge; its header argues, correctly, for exactly two copies. A third copy would make that
 * drift test a three-way comparison against a comment that says there are two, and promoting the
 * fixture into `@kb/contracts` would put test-only code in the package whose whole budget rule is
 * that it carries almost none. This workspace already runs two real HTTP origins for its Playwright
 * harness, so the honest place for a streaming route is the API origin it already serves.
 *
 * WHY THE API SIDE (widget-origin.mjs) AND NOT THE CUSTOMER SIDE (customer-origin.mjs). The
 * customer's origin serves HTML and nothing else — in production it is a page we do not control and
 * there is no API on it at all. The chat stream is Laravel's, on `api.<domain>`, and the request is
 * made by the FRAME, so the only faithful arrangement is: frame document on the widget origin,
 * stream on the API origin, cross-origin between them with CORS and a bearer. That is what this
 * serves, and it is why the widget's proof of the read loop is a Playwright e2e over a real socket
 * rather than a unit test: the frame parser itself is already unit-tested inside `@kb/contracts`,
 * so what apps/widget has to prove is its own read loop.
 *
 * The verbs are the ones the apps/web fixture proved worth having, reimplemented here rather than
 * copied wholesale: `split(text, atByte)` (a BYTE offset, which is how you land inside a multi-byte
 * codepoint), `ping()` (the heartbeat comment, which must never surface as an event), `cut()` (RST
 * by destroying the socket, not `res.end()`'s FIN — only the former reproduces the vanished peer
 * that must yield `stream_lost`), and a request recorder so a spec can assert the bearer and the
 * body across a refresh.
 *
 * Node builtins only.
 */

/** A UTF-8 string whose second character is multi-byte, so a byte-level split lands inside it. */
const MULTIBYTE = 'Grüße — 你好 🙂';

function makeControl(response) {
  // Await the write CALLBACK, not the return value: `write()` returning false means the buffer is
  // full, not that the bytes left.
  const write = (chunk) =>
    new Promise((resolve, reject) => {
      response.write(chunk, (error) => (error ? reject(error) : resolve()));
    });

  // A real gap, so the reader genuinely sees two chunks. Without it the writes coalesce in the
  // socket buffer and the split never reaches the parser.
  const gap = () => new Promise((resolve) => setTimeout(resolve, 15));

  return {
    send: (event, data) => write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`),
    raw: (chunk) => write(chunk),
    async split(text, atByte) {
      const bytes = Buffer.from(text, 'utf8');
      await write(bytes.subarray(0, atByte));
      await gap();
      await write(bytes.subarray(atByte));
    },
    ping: () => write(': ping\n\n'),
    gap,
    end: () => new Promise((resolve) => response.end(resolve)),
    /** Vanished peer: RST, not FIN. `response.end()` is a NORMAL end of stream. */
    cut: () => response.socket?.destroy(),
  };
}

/**
 * Every scenario is keyed by the CONVERSATION ID, so the request the probe makes is exactly the
 * request the application makes — no extra query parameter, no test-only header, no branch in
 * src/. The id is the only thing a caller chooses anyway.
 */
const SCENARIOS = {
  /**
   * The whole event set, in order, with the two chunk-boundary hazards in the middle: a heartbeat
   * comment between frames, and a token frame split at a BYTE offset that falls inside a multi-byte
   * codepoint.
   */
  async happy(control, context) {
    await control.send('message.start', {
      message_id: 'msg_happy',
      conversation_id: context.conversationId,
      created_at: '2026-08-10T00:00:00Z',
    });
    await control.send('status', { stage: 'retrieving' });
    await control.send('citations', {
      citations: [
        {
          index: 1,
          source_id: 'src_1',
          source_version_id: 'sv_1',
          chunk_id: 'ch_1',
          title: 'Refund policy',
          url: 'https://docs.example/refunds',
          score: 0.71,
        },
      ],
    });
    // Must never surface as an event, and must still reset the client's idle watchdog.
    await control.ping();
    await control.gap();
    /**
     * Split INSIDE the frame AND INSIDE A CODEPOINT.
     *
     * Byte 31 is the boundary between `ü`'s two UTF-8 bytes in
     * `event: token\ndata: {"text":"Grüße …` — VERIFIED against the exact bytes this fixture
     * writes, not estimated. The offset is the whole point of the case: an earlier draft split at
     * byte 17, which lands inside `data: ` where every byte is ASCII, so a decoder with no
     * `{ stream: true }` reassembled it perfectly and the test passed with the bug present. A
     * chunk-boundary fixture that never crosses a codepoint boundary tests the same thing as no
     * fixture at all.
     */
    await control.split(`event: token\ndata: ${JSON.stringify({ text: MULTIBYTE })}\n\n`, 31);
    // Split between the two newlines that terminate a frame — the case that never appears in a
    // hand-written fixture and appears constantly on a real socket.
    await control.raw('event: token\ndata: {"text":" done"}\n');
    await control.gap();
    await control.raw('\n');
    // Internal-only events. Laravel does not forward these; if a relay bug ever did, the client's
    // name allow-list is what keeps token costs and internal topology unrenderable.
    await control.raw('event: provider.usage\ndata: {"prompt_tokens":41,"cost_usd":0.002}\n\n');
    await control.raw('event: retrieval.trace\ndata: {"qdrant":"kb-internal-1.local"}\n\n');
    await control.send('message.complete', {
      message_id: 'msg_happy',
      finish_reason: 'stop',
      usage: { prompt_tokens: 41, completion_tokens: 7 },
    });
    await control.end();
  },

  /** A terminal `error` frame. `request_id` and `Retry-After` exist only on the HTTP envelope, so
   *  the client must read both as null here rather than inventing them. */
  async 'error-frame'(control) {
    await control.send('message.start', {
      message_id: 'msg_err',
      conversation_id: 'error-frame',
      created_at: '2026-08-10T00:00:00Z',
    });
    await control.send('error', {
      error_class: 'internal_dependency',
      // The `downstream` sub-case: 503/retryable. The class name alone cannot say so (ADR-029),
      // which is the entire reason the flag is on the wire and must be carried straight through.
      retryable: true,
      message: 'upstream provider kb-internal-7.local returned 502',
    });
    await control.end();
  },

  /** The vanished peer: RST mid-answer, no terminal event. `stream_lost`, retryable. */
  async cut(control) {
    await control.send('message.start', {
      message_id: 'msg_cut',
      conversation_id: 'cut',
      created_at: '2026-08-10T00:00:00Z',
    });
    await control.send('token', { text: 'half an ans' });
    await control.gap();
    control.cut();
  },

  /** A CLEAN end of stream with no terminal event. Same sentinel, different cause — this is the one
   *  a `res.end()`-based fixture can express and the RST case above cannot. */
  async 'no-terminal'(control) {
    await control.send('message.start', {
      message_id: 'msg_noterm',
      conversation_id: 'no-terminal',
      created_at: '2026-08-10T00:00:00Z',
    });
    await control.send('token', { text: 'partial' });
    await control.end();
  },

  /** Silence. No ping, no bytes: only the client's idle watchdog can end this. */
  async idle(control) {
    await control.send('message.start', {
      message_id: 'msg_idle',
      conversation_id: 'idle',
      created_at: '2026-08-10T00:00:00Z',
    });
    // Deliberately never ends. The socket is closed when the client cancels its reader.
  },

  /** Tokens forever, so the spec can abort mid-stream and assert the generator RETURNS rather than
   *  throwing — cancellation is an outcome, not a failure. */
  async slow(control) {
    await control.send('message.start', {
      message_id: 'msg_slow',
      conversation_id: 'slow',
      created_at: '2026-08-10T00:00:00Z',
    });
    for (let i = 0; i < 200; i += 1) {
      try {
        await control.send('token', { text: `chunk ${i} ` });
      } catch {
        return; // the client went away; that is the point of the scenario
      }
      await control.gap();
    }
  },

  /** Model output is HOSTILE. It reaches the DOM only through renderAssistantMarkdown(). */
  async xss(control) {
    await control.send('message.start', {
      message_id: 'msg_xss',
      conversation_id: 'xss',
      created_at: '2026-08-10T00:00:00Z',
    });
    await control.send('token', {
      text: '<img src=x onerror="window.__kbPwned=1"> [click](javascript:alert(1)) **bold**',
    });
    await control.send('message.complete', {
      message_id: 'msg_xss',
      finish_reason: 'stop',
      usage: { prompt_tokens: 1, completion_tokens: 1 },
    });
    await control.end();
  },
};

/**
 * The two scenarios that are NOT a stream: they answer with the JSON error envelope, which is what
 * every failure on this surface looks like. The client must branch on the CONTENT TYPE rather than
 * the status — one class renders as different statuses per surface, so status-driven logic reads an
 * `authorization` 404 as "absent, so create it".
 */
const ENVELOPE_SCENARIOS = {
  'not-sse': () => ({
    status: 404,
    headers: { 'x-kb-request-id': 'req_notsse', 'retry-after': '30' },
    body: {
      error_class: 'authorization',
      retryable: false,
      message: 'bot pub_x is not visible to session kbw_… on host kb-internal-3.local',
      request_id: 'req_notsse',
    },
  }),
  /** First request 401s; the second — after the loader has re-minted and the frame has swapped the
   *  bearer — streams normally. The recorder is what lets a spec assert that the flush carried the
   *  NEW token and the SAME client_message_id. */
  expired: (attempt) =>
    attempt === 1
      ? {
          status: 401,
          headers: { 'x-kb-request-id': 'req_expired' },
          body: {
            error_class: 'authentication',
            retryable: false,
            message: 'widget session expired',
            request_id: 'req_expired',
          },
        }
      : null,
};

export const SSE_PATH = /^\/rt\/v1\/conversations\/([^/]+)\/messages$/;

export function scenarioNames() {
  return [...Object.keys(SCENARIOS), ...Object.keys(ENVELOPE_SCENARIOS)];
}

/**
 * Serve one streaming request.
 *
 * `attempt` is the 1-based count of requests already seen for this conversation id, which is how
 * `expired` distinguishes the send that met the dead token from the one that was flushed after the
 * refresh.
 */
export async function serveStream(request, response, { conversationId, attempt, cors }) {
  const envelope = ENVELOPE_SCENARIOS[conversationId]?.(attempt) ?? null;
  if (envelope !== null) {
    response.writeHead(envelope.status, {
      'content-type': 'application/json; charset=utf-8',
      ...envelope.headers,
      ...cors,
    });
    response.end(JSON.stringify(envelope.body));
    return;
  }

  // `expired` has no stream of its own: once the bearer has been replaced it behaves like any other
  // conversation, which is exactly the assertion — the flush is an ORDINARY send that happens to
  // carry the same body.
  const scenario =
    SCENARIOS[conversationId] ?? (conversationId === 'expired' ? SCENARIOS.happy : undefined);
  if (scenario === undefined) {
    response.writeHead(404, { 'content-type': 'application/json; charset=utf-8', ...cors });
    response.end('{}');
    return;
  }

  response.writeHead(200, {
    'content-type': 'text/event-stream',
    'cache-control': 'no-store',
    connection: 'keep-alive',
    'x-kb-request-id': `req_${conversationId}`,
    ...cors,
  });
  // Without this Node holds the headers until the first body write, so `await fetch()` in the
  // client hangs until the first token and nothing about the pre-token UI is testable at all.
  response.flushHeaders();
  // Nagle's algorithm merges two events written back to back into one read, and every
  // chunk-boundary assertion then depends on the kernel's mood.
  response.socket?.setNoDelay(true);

  const control = makeControl(response);
  try {
    await scenario(control, { conversationId, request });
  } catch {
    // The client hung up mid-scenario. That is a case under test, not a fixture failure.
  }
}

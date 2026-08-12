import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { API_BASE, WIDGET_BASE } from './_shadow.js';

/**
 * THE READ LOOP, OVER A REAL SOCKET, CROSS-ORIGIN.
 *
 * Everything here runs in a browser on the WIDGET origin and streams from the API origin, which is
 * the relationship the frame has in production. That is not decoration: a mocked response cannot
 * chunk a `text/event-stream`, `route.fulfill()` base64-encodes one whole body into a single CDP
 * message and can never deliver it in pieces, and MSW never sees a client `abort()` — each of those
 * produces a green suite over a broken read loop. The fixture that serves these scenarios is
 * tests/harness/sse-scenarios.mjs, mounted on the API half of tests/harness/widget-origin.mjs.
 *
 * The page under test is `/__probe`, which bundles the REAL `src/app/stream.ts` (see the harness
 * for why the app's own submit handler cannot drive this yet). Nothing in src/ knows the probe
 * exists.
 *
 * The frame PARSER is not re-tested here — it is unit-tested inside `@kb/contracts`, which is the
 * point of importing it rather than forking it. What is tested here is this client's own loop:
 * chunk boundaries, the heartbeat, the terminal-event rule, the refresh queue, cancellation.
 */

interface ProbeResult {
  events: Array<{ event: string; data: unknown }>;
  error: {
    name: string;
    error_class: string | null;
    retryable: boolean | null;
    retry_after: number | null;
    request_id: string | null;
    message: string;
  } | null;
  refreshRequests: number;
  rendered: string;
  renderedText: string;
}

interface ProbeRequest {
  conversationId: string;
  clientMessageId: string;
  content: string;
  apiOrigin: string;
  token: string;
  idleGapMs?: number;
  abortAfterEvents?: number;
  refreshWith?: { token: string; expires_in: number } | null;
  refreshDelayMs?: number;
}

/**
 * The POSITIVE CONTROL for every spec in this file. A streaming assertion that can pass because the
 * probe never loaded is the same falsely-green shape as a security assertion that passes because
 * the widget never booted.
 */
async function probe(page: Page): Promise<void> {
  await page.goto(`${WIDGET_BASE}/__probe`);
  await expect
    .poll(() =>
      page.evaluate(
        () => typeof (window as unknown as Record<string, unknown>)['__kbProbe'] === 'object',
      ),
    )
    .toBe(true);
}

async function run(page: Page, options: Omit<ProbeRequest, 'apiOrigin'>): Promise<ProbeResult> {
  return page.evaluate(
    async (request) =>
      (
        (window as unknown as Record<string, unknown>)['__kbProbe'] as {
          run(r: ProbeRequest): Promise<ProbeResult>;
        }
      ).run(request),
    { ...options, apiOrigin: API_BASE } as ProbeRequest,
  );
}

async function streamRequests(page: Page): Promise<
  Array<{
    conversation_id: string;
    attempt: number;
    authorization: string | null;
    body: { client_message_id: string; content: string } | null;
  }>
> {
  const response = await page.request.get(`${API_BASE}/__stream-requests`);
  const body = (await response.json()) as {
    requests: Array<{
      conversation_id: string;
      attempt: number;
      authorization: string | null;
      body: { client_message_id: string; content: string } | null;
    }>;
  };
  return body.requests;
}

test.describe('SSE read loop over a real cross-origin socket', () => {
  test('yields the client events in order, survives split frames, and never surfaces a heartbeat or an internal event', async ({
    page,
  }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'happy',
      clientMessageId: 'cmid_happy',
      content: 'how do refunds work?',
      token: 'kbw_probe.1',
    });

    expect(result.error).toBeNull();
    // `: ping` dispatches no event; `provider.usage` and `retrieval.trace` are not in the client
    // name allow-list, so a relay bug that forwarded token costs or internal topology still could
    // not render them here.
    expect(result.events.map((e) => e.event)).toEqual([
      'message.start',
      'status',
      'citations',
      'token',
      'token',
      'message.complete',
    ]);

    // The token frame was split at a BYTE offset inside a multi-byte codepoint AND a later frame was
    // split between its two terminating newlines. Both must reassemble exactly — no replacement
    // characters, no dropped tail. This is what `{ stream: true }` and the shared frame buffer buy.
    const text = result.events
      .filter((e) => e.event === 'token')
      .map((e) => (e.data as { text: string }).text)
      .join('');
    expect(text).toBe('Grüße — 你好 🙂 done');
    expect(text).not.toContain('�');

    // Assistant text reached the DOM only through renderAssistantMarkdown(), which is the lazy
    // chunk's only edge.
    expect(result.renderedText).toContain('Grüße — 你好 🙂 done');
  });

  test('an `error` frame becomes a KbError carrying the envelope’s own retryable, with null request_id and retry_after', async ({
    page,
  }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'error-frame',
      clientMessageId: 'cmid_err',
      content: 'x',
      token: 'kbw_probe.1',
    });

    expect(result.events.map((e) => e.event)).toEqual(['message.start']);
    expect(result.error?.name).toBe('KbError');
    expect(result.error?.error_class).toBe('internal_dependency');
    // CARRIED, never re-derived: `internal_dependency` renders 503/retryable for `downstream` and
    // 500/not-retryable for `self`, and the axis is deliberately not on the wire (ADR-029).
    expect(result.error?.retryable).toBe(true);
    // Both exist only on the HTTP envelope. Inventing them would put a fabricated identifier in
    // front of a support engineer.
    expect(result.error?.request_id).toBeNull();
    expect(result.error?.retry_after).toBeNull();
    // The operator-facing message is carried for logging. It names an internal host, which is
    // precisely why it must never cross the bridge to the host page (src/app/bridge.ts).
    expect(result.error?.message).toContain('kb-internal-7.local');
  });

  test('a peer that vanishes mid-answer raises the client-local stream_lost sentinel', async ({
    page,
  }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'cut',
      clientMessageId: 'cmid_cut',
      content: 'x',
      token: 'kbw_probe.1',
    });

    // The fixture destroys the socket (RST). `response.end()` would be a normal end of stream and
    // would exercise a different branch — see the `no-terminal` case below.
    expect(result.error?.error_class).toBe('stream_lost');
    expect(result.error?.retryable).toBe(true);
    expect(result.events.map((e) => e.event)).toEqual(['message.start', 'token']);
  });

  test('a clean EOF with no terminal event is also stream_lost', async ({ page }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'no-terminal',
      clientMessageId: 'cmid_noterm',
      content: 'x',
      token: 'kbw_probe.1',
    });

    expect(result.error?.error_class).toBe('stream_lost');
    expect(result.error?.message).toContain('without a terminal event');
  });

  test('silence trips the idle watchdog', async ({ page }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'idle',
      clientMessageId: 'cmid_idle',
      content: 'x',
      token: 'kbw_probe.1',
      // The production value is 45 s, three missed 15 s heartbeats. The seam exists so a test need
      // not wait that long; the BEHAVIOUR under test is that silence ends the stream at all.
      idleGapMs: 700,
    });

    expect(result.error?.error_class).toBe('stream_lost');
    expect(result.error?.message).toContain('missed heartbeats');
  });

  test('a non-SSE failure is classified from the envelope, not the status', async ({ page }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'not-sse',
      clientMessageId: 'cmid_notsse',
      content: 'x',
      token: 'kbw_probe.1',
    });

    // The fixture answers 404 — the public-surface rendering of `authorization`. A status-driven
    // client reads that as "absent, so create it"; this one reads the envelope.
    expect(result.error?.error_class).toBe('authorization');
    expect(result.error?.retryable).toBe(false);
    expect(result.error?.request_id).toBe('req_notsse');
    expect(result.error?.retry_after).toBe(30);
    expect(result.events).toEqual([]);
  });

  test('a 401 asks the loader to re-mint ONCE and flushes the send with the new bearer and the SAME client_message_id', async ({
    page,
  }) => {
    await probe(page);

    const before = (await streamRequests(page)).length;

    const result = await run(page, {
      conversationId: 'expired',
      clientMessageId: 'cmid_expired',
      content: 'still my question',
      token: 'kbw_probe.dead',
      // What the LOADER sends back over the bridge. The frame can never mint for itself.
      refreshWith: { token: 'kbw_probe.fresh', expires_in: 1800 },
    });

    expect(result.error).toBeNull();
    // ONE `session-expiring`. Two would burn the `sdk-bootstrap` composite limiter and orphan a
    // session — the single-flight gate lives in src/app/session.ts.
    expect(result.refreshRequests).toBe(1);
    expect(result.events.map((e) => e.event)).toContain('message.complete');

    const seen = (await streamRequests(page)).slice(before);
    expect(seen).toHaveLength(2);
    // QUEUED, NOT RETRIED: the second attempt went out only after the token was replaced.
    expect(seen[0]?.authorization).toBe('Bearer kbw_probe.dead');
    expect(seen[1]?.authorization).toBe('Bearer kbw_probe.fresh');
    // And it kept the identity it was created with. Re-minting `client_message_id` on the flush
    // would turn one message into two conversations, two provider calls and two bills.
    expect(seen[0]?.body?.client_message_id).toBe('cmid_expired');
    expect(seen[1]?.body?.client_message_id).toBe('cmid_expired');
    expect(seen[1]?.body?.content).toBe('still my question');
  });

  test('aborting mid-stream returns rather than throwing', async ({ page }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'slow',
      clientMessageId: 'cmid_slow',
      content: 'x',
      token: 'kbw_probe.1',
      abortAfterEvents: 2,
    });

    // Cancellation is an OUTCOME (499, `user_cancellation`), not a failure: no error, no retry.
    expect(result.error).toBeNull();
    expect(result.events.length).toBeGreaterThanOrEqual(2);
  });

  test('hostile model output is sanitized on its way to the DOM', async ({ page }) => {
    await probe(page);

    const result = await run(page, {
      conversationId: 'xss',
      clientMessageId: 'cmid_xss',
      content: 'x',
      token: 'kbw_probe.1',
    });

    expect(result.error).toBeNull();
    /**
     * markdown-it(`html: false`) → DOMPurify(array config, RETURN_DOM_FRAGMENT) → replaceChildren.
     *
     * The `<img …>` survives as ESCAPED TEXT, which is the correct output and is why the assertion
     * is about ELEMENTS and ATTRIBUTES rather than about the substring "onerror": the model wrote
     * that text, the visitor may legitimately see it, and what must not exist is an element bearing
     * it. markdown-it's own `validateLink` is what leaves the `javascript:` link as literal text
     * before DOMPurify is even reached.
     */
    expect(result.rendered).not.toContain('<img');
    expect(result.rendered).not.toContain('href="javascript:');
    expect(result.rendered).toContain('&lt;img'); // escaped, i.e. rendered as text
    expect(result.rendered).toContain('<strong>bold</strong>');
    expect(
      await page.evaluate(() => (window as unknown as Record<string, unknown>)['__kbPwned']),
    ).toBeUndefined();
  });
});

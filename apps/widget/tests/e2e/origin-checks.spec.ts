import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import {
  API_BASE,
  WIDGET_BASE,
  captureShadowRoots,
  expectWidgetBooted,
  mintCount,
  resizeBarrier,
  widgetFrame,
} from './_shadow.js';

/**
 * A RECEIPT BARRIER for a message the widget must IGNORE.
 *
 * Asserting "nothing happened" after a fixed sleep passes just as well when the message was never
 * delivered — and on a security spec that is the worst possible false green. So the spec installs
 * its OWN `message` listener on the target window first, and waits for that listener to see the
 * forged envelope. Listeners on one window run in registration order for the same event, and the
 * widget's listener was registered at boot, long before this one: by the time ours records the
 * message, the widget's has already been handed it and has already decided to drop it.
 *
 * That turns "wait and hope" into "wait for proof of delivery", and it fails loudly — the poll
 * times out — when the message never arrives at all.
 */
async function forgedMessageWasDelivered(page: Page, post: () => Promise<void>): Promise<void> {
  await page.evaluate(() => {
    const state = window as unknown as Record<string, unknown>;
    state['__kbForgedSeen'] = 0;
    addEventListener('message', (event: MessageEvent) => {
      const data = event.data as { ch?: unknown } | null;
      if (data !== null && typeof data === 'object' && data.ch === 'anything') {
        state['__kbForgedSeen'] = (state['__kbForgedSeen'] as number) + 1;
      }
    });
  });
  await post();
  await expect
    .poll(
      () =>
        page.evaluate(
          () => (window as unknown as Record<string, unknown>)['__kbForgedSeen'] as number,
        ),
      { timeout: 10_000 },
    )
    .toBeGreaterThan(0);
}

/**
 * THE SPECS THAT ONLY WORK CROSS-ORIGIN.
 *
 * Every assertion here would also pass with the origin and `event.source` checks deleted if the
 * fixture page shared an origin with the frame. That is why the harness runs the customer page on
 * one origin and the widget on another, and why these live in Playwright rather than in a unit
 * test with a hand-built MessageEvent.
 */
test.describe('origin and source checks', () => {
  test.beforeEach(async ({ page }) => {
    await captureShadowRoots(page);
  });

  test('a message from an UNEXPECTED ORIGIN is ignored', async ({ page }) => {
    /**
     * BASELINE BEFORE `goto`, AND POLL FOR `baseline + 1` — never `toBeGreaterThan(0)`.
     *
     * `/__mints` is a CUMULATIVE, process-wide counter and the suite runs single-worker against one
     * harness, so by the time this spec runs the count is already non-zero from earlier specs.
     * `toBeGreaterThan(0)` was therefore satisfied by HISTORY, on the first poll, before this page's
     * own mint had landed. `before` captured a stale number, the real mint arrived during the 500 ms
     * wait, and the spec failed with `Expected: 3, Received: 4` — reported as the forged message
     * having caused a mint, which is the one conclusion that would have been alarming and was
     * exactly backwards. The two specs below already do it this way.
     */
    const baseline = await mintCount(page, API_BASE);
    await page.goto('/');
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(baseline + 1);
    // POSITIVE CONTROL FIRST. Everything below is an assertion that nothing happened, and a widget
    // that never booted satisfies all of it.
    await expectWidgetBooted(page);

    const before = await mintCount(page, API_BASE);

    // The host document posts to ITSELF: same shape, same channel guess, wrong origin. The
    // loader's listener sees `event.source === window`, not the frame's contentWindow, and
    // `event.origin === <customer origin>`, not __KB_WIDGET_ORIGIN__ — two independent reasons to
    // drop it, and the spec asserts the outcome rather than which one fired.
    await forgedMessageWasDelivered(page, async () => {
      await page.evaluate(() => {
        window.postMessage({ kb: 1, ch: 'anything', type: 'ready' }, window.location.origin);
      });
    });

    expect(await mintCount(page, API_BASE)).toBe(before);
    // And the widget is still alive afterwards: a listener that threw on a hostile message would
    // leave the bridge dead, which is a different failure that "no extra mint" also permits.
    await resizeBarrier(page, 240);
    expect(await mintCount(page, API_BASE)).toBe(before);
  });

  test('a forged message from a SIBLING FRAME on the customer origin is ignored', async ({
    page,
  }) => {
    // This is the case an origin-only check cannot catch: the sibling carries the customer's
    // origin, and an `about:blank` frame the host created INHERITS that origin rather than being
    // opaque. `event.source` is set by the browser from the actual sending context and is what
    // excludes it — which is why it is checked FIRST.
    //
    // Note what the attacker CANNOT do here, and why: it tries to reach our chat frame directly,
    // and `parent.document.querySelectorAll('iframe')` does not find it, because the frame lives
    // in a CLOSED shadow root. That is a second, independent layer — but it is not the one under
    // test. What is under test is the message it CAN deliver: `parent.postMessage(...)` to the
    // loader's own listener, with a perfectly valid customer origin on it.
    // Baseline before `goto`, for the reason spelled out in the spec above: the mint counter is
    // cumulative across the whole run, so `toBeGreaterThan(0)` measures the suite's history rather
    // than this page.
    const baseline = await mintCount(page, API_BASE);
    await page.goto('/?attacker=1');
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(baseline + 1);
    await expectWidgetBooted(page);

    const before = await mintCount(page, API_BASE);

    await forgedMessageWasDelivered(page, async () => {
      await page.evaluate(() => {
        const forge = (window as unknown as Record<string, unknown>)['__kbForge'] as (
          envelope: unknown,
        ) => void;
        forge({ kb: 1, ch: 'anything', type: 'ready' });
        forge({ kb: 1, ch: 'anything', type: 'session-expiring' });
      });
    });

    expect(await mintCount(page, API_BASE)).toBe(before);
    // The bridge still works for the one window that is allowed to drive it.
    await resizeBarrier(page, 260);
    expect(await mintCount(page, API_BASE)).toBe(before);
  });

  test('a SECOND ready does not mint twice — one init per frame instance', async ({ page }) => {
    /**
     * The frame legitimately re-posts `ready` every 250 ms up to 20 times, because an `async` loader
     * may attach its listener after the frame is already up. Duplicate `ready`s are NORMAL, and
     * re-initialising on one is a state-machine reset an attacker would enjoy.
     *
     * THIS SPEC USED TO SLEEP SIX SECONDS "long enough to cover all 20 re-posts" AND EXERCISE
     * NOTHING. On a healthy boot the re-post timer stops the moment `init` lands — that is what
     * `session !== null` in the frame's interval does — so on the very path this spec runs, the
     * second `ready` it claimed to be waiting for is never sent. Six seconds of wall clock bought a
     * stimulus that does not exist.
     *
     * So the duplicates are now posted deliberately, from the REAL frame's own window: `event.source`
     * is genuinely `frame.contentWindow`, the origin is genuinely ours, and the channel id is the
     * real one — every check passes and the ONLY thing standing between this and a second mint is
     * the `initialised` latch. The `resize` that follows is the barrier: same source window, so the
     * browser delivers it after the two `ready`s, and seeing its effect proves they were processed.
     */
    const before = await mintCount(page, API_BASE);
    await page.goto('/');
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(before + 1);
    await expectWidgetBooted(page);

    const frame = await widgetFrame(page);
    await frame.evaluate(() => {
      const query = new URLSearchParams(location.search);
      const envelope = { kb: 1, ch: query.get('ch') ?? '', type: 'ready' };
      const host = query.get('origin') ?? '';
      parent.postMessage(envelope, host);
      parent.postMessage(envelope, host);
      parent.postMessage(envelope, host);
    });
    await resizeBarrier(page, 300);

    expect(await mintCount(page, API_BASE)).toBe(before + 1);
  });

  test('an unregistered embedder gets frame-ancestors none and never receives a session', async ({
    page,
  }) => {
    // Trusting `?origin=` is sound precisely because of this: the server echoes the VALIDATED
    // value into `frame-ancestors` on the framed response, so a page that lied never renders.
    const response = await page.request.get(
      `${WIDGET_BASE}/embed?bot=pub_01J8FIXTUREBOT0000000000&origin=${encodeURIComponent('https://evil.example')}&ch=x`,
    );
    expect(response.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(response.headers()['content-security-policy']).not.toContain('evil.example');
  });

  test('a session mint from an unlisted origin is a byte-identical 404', async ({ request }) => {
    // On a public/SDK surface a 403 on a foreign id confirms the row exists, so every rejection
    // is the same 404 with the same body.
    // `sdk/v1`: the SDK bootstrap is its own middleware group in bootstrap/app.php and sits
    // OUTSIDE `api/` so it cannot inherit the admin session stack by prefix.
    const unlisted = await request.post(`${API_BASE}/sdk/v1/session`, {
      headers: { origin: 'https://evil.example', 'content-type': 'application/json' },
      data: { bot_id: 'pub_01J8FIXTUREBOT0000000000', user_token: null },
    });
    const unknownBot = await request.post(`${API_BASE}/sdk/v1/session`, {
      headers: { origin: 'https://evil.example', 'content-type': 'application/json' },
      data: { bot_id: 'pub_does_not_exist', user_token: null },
    });

    expect(unlisted.status()).toBe(404);
    expect(unknownBot.status()).toBe(404);
    expect(await unlisted.text()).toBe(await unknownBot.text());
    // Vary: Origin on every CORS response, or a CDN serves one tenant's ACAO to another.
    expect(unlisted.headers()['vary']).toContain('Origin');
  });

  test('an identity claim sent over the bridge is ignored entirely', async ({ page }) => {
    // The bridge carries NO AUTHORITY. `set-bot` / `set-session` / any identity claim is refused
    // with no exception and no "trusted embedder" flag: a `set-bot` message turns one shared
    // allow-list entry into cross-tenant access.
    const before = await mintCount(page, API_BASE);
    await page.goto('/');
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(before + 1);
    const frame = await expectWidgetBooted(page);

    const frameSrc = await page.evaluate(() => {
      const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
        []) as ShadowRoot[];
      const frame = roots
        .map((r) => r.querySelector('iframe'))
        .find((el): el is HTMLIFrameElement => el !== null);
      const ch = frame === undefined ? '' : (new URL(frame.src).searchParams.get('ch') ?? '');
      // The RIGHT channel this time, so the only thing under test is the type allow-list.
      frame?.contentWindow?.postMessage(
        { kb: 1, ch, type: 'set-bot', payload: { botId: 'pub_someone_elses_bot' } },
        new URL(frame.src).origin,
      );
      frame?.contentWindow?.postMessage(
        { kb: 1, ch, type: 'set-session', payload: { session: { token: 'forged' } } },
        new URL(frame.src).origin,
      );
      return frame?.src ?? '';
    });

    /**
     * THE BARRIER, and here it can be exact: a legitimate `set-theme` posted from the SAME window,
     * to the SAME frame, immediately after the two identity claims. Delivery between one source and
     * one target is ordered, so the frame applying the theme proves it has already seen and dropped
     * `set-bot` and `set-session` — they are not in FROM_HOST, so `parse()` returns null before any
     * payload field is read.
     */
    await page.evaluate(() => {
      const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
        []) as ShadowRoot[];
      const element = roots
        .map((r) => r.querySelector('iframe'))
        .find((el): el is HTMLIFrameElement => el !== null);
      if (element === undefined) return;
      const target = new URL(element.src);
      element.contentWindow?.postMessage(
        {
          kb: 1,
          ch: target.searchParams.get('ch') ?? '',
          type: 'set-theme',
          payload: { value: 'dark' },
        },
        target.origin,
      );
    });
    await expect
      .poll(() => frame.evaluate(() => document.documentElement.dataset['theme']), {
        timeout: 10_000,
      })
      .toBe('dark');

    // The bot binding comes from the URL that minted the session and is never re-read.
    expect(frameSrc).toContain('bot=pub_01J8FIXTUREBOT0000000000');
    expect(await mintCount(page, API_BASE)).toBe(before + 1);
    // The frame is still on the session it was handed at `init`: a forged `set-session` that had
    // been accepted would have replaced the bearer, and a forged `set-bot` would have re-minted.
    expect(await frame.getAttribute('[data-phase]', 'data-phase')).toBe('ready');
  });

  test('a reload mid-conversation starts cleanly and mints again', async ({ page }) => {
    // Third tier of the resumption ladder is a SHIPPED state: with neither a partitioned cookie
    // nor sessionStorage, a top-level navigation starts a NEW conversation. The UI must promise no
    // history it cannot restore — no "restoring…" spinner it can never resolve.
    const before = await mintCount(page, API_BASE);
    await page.goto('/');
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(before + 1);
    await expectWidgetBooted(page);

    await page.reload();
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(before + 2);
    // Booted AGAIN — a reload that left a dead launcher would still satisfy the mint delta on its
    // own, since the mint happens during the handshake and says nothing about what came after.
    await expectWidgetBooted(page);

    const stray = await page.evaluate(() => document.body.textContent ?? '');
    expect(stray.toLowerCase()).not.toContain('restoring');
  });
});

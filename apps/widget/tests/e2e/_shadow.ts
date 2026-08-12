import { expect } from '@playwright/test';
import type { Frame, Page } from '@playwright/test';

/**
 * The launcher lives in a CLOSED shadow root, so `element.shadowRoot` is null and Playwright's
 * selector engine cannot enter it. That is a deliberate production property — the host page's
 * script must not be able to walk our chrome — and this file is the price of it.
 *
 * The instrumentation patches the BROWSER, not our code: an init script wraps
 * `Element.prototype.attachShadow` before any page script runs and keeps a reference to every root
 * created. Nothing in src/ knows this exists, so no spec can pass by virtue of a test-only branch
 * in the loader.
 *
 * Specs read the roots with a plain `page.evaluate(() => …)`, which Playwright serializes for
 * itself. There is deliberately no generic "run this callback in the shadow" helper here: it would
 * mean stringifying a function and rebuilding it in the page, which is a code-injection shape in a
 * file whose whole subject is not trusting strings.
 */
export const SHADOW_ROOTS = '__kbShadowRoots';

export async function captureShadowRoots(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const roots: ShadowRoot[] = [];
    (window as unknown as Record<string, unknown>)['__kbShadowRoots'] = roots;
    const original = Element.prototype.attachShadow;
    Element.prototype.attachShadow = function (init: ShadowRootInit): ShadowRoot {
      const root = original.call(this, init);
      roots.push(root);
      return root;
    };
  });
}

/** The API fixture's mint counter — read from the SERVER, not from page script, so the page under
 *  test cannot influence the number a spec asserts. */
export async function mintCount(page: Page, apiBase: string): Promise<number> {
  const response = await page.request.get(`${apiBase}/__mints`);
  const body = (await response.json()) as { count: number };
  return body.count;
}

export const API_BASE = process.env['KB_API_BASE_URL'] ?? 'http://127.0.0.1:4175';
export const WIDGET_BASE = process.env['KB_WIDGET_BASE_URL'] ?? 'http://127.0.0.1:4173';

/**
 * THE POSITIVE CONTROL, and the reason this file grew.
 *
 * Every security spec in this suite asserts that something did NOT happen — no extra mint, no state
 * change, no leaked style. Every one of those assertions is also satisfied by a widget that never
 * booted at all, for a reason having nothing to do with the attack under test: a build that emitted
 * no loader, a harness serving the wrong path (this suite spent its whole life green over exactly
 * that, an `api/v1` mint the fixture also faked), a bot id typo, a broken handshake. A green
 * security suite over a widget that never ran is the worst possible false green, because the suite
 * reads as coverage.
 *
 * So every negative is now preceded by this: proof, from four independent observables, that the
 * widget genuinely booted on the allowed origin.
 *
 *   1. exactly one shadow root — the loader ran, and the double-injection sentinel held
 *   2. exactly one launcher and one iframe inside it — the chrome was actually built
 *   3. the frame document is in the page's frame tree, at OUR origin's /embed — the browser did not
 *      refuse it (`frame-ancestors`) and the customer's CSP did not block it (`frame-src`)
 *   4. the app inside it reached `data-phase="ready"` — which happens only when `init` arrives,
 *      i.e. after `ready` → mint → `init` completed END TO END across two origins
 *
 * The fourth is the strong one: it cannot be true unless the whole handshake worked, and it is not
 * observable through anything the host page can fake, because it lives in a document on our origin.
 */
export async function expectWidgetBooted(page: Page): Promise<Frame> {
  await expect
    .poll(
      () =>
        page.evaluate(() => {
          const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
            []) as ShadowRoot[];
          return {
            roots: roots.length,
            launchers: roots.reduce(
              (n, r) => n + r.querySelectorAll('button.kb-launcher').length,
              0,
            ),
            frames: roots.reduce((n, r) => n + r.querySelectorAll('iframe').length, 0),
          };
        }),
      { timeout: 15_000 },
    )
    .toEqual({ roots: 1, launchers: 1, frames: 1 });

  const frame = await widgetFrame(page);
  /**
   * `data-phase="ready"` is set by src/app/app.tsx's `onInit`, so it is the handshake's own
   * completion signal rather than a proxy for it.
   *
   * `state: 'attached'`, NOT the default `'visible'`: the frame element is `display: none` until the
   * visitor opens the panel (launcher.css uses `display` rather than `opacity` so a hidden frame
   * cannot swallow the host page's clicks), which makes every node inside it invisible by
   * definition. Waiting for visibility here would time out on a perfectly healthy boot.
   */
  await frame.waitForSelector('[data-phase="ready"]', { state: 'attached', timeout: 15_000 });
  return frame;
}

/**
 * The frame document, from the page's FRAME TREE — which is independent of the closed shadow root
 * the element lives in. A spec can therefore drive the real frame's own window (posting a genuine
 * `ready` or `resize` from the one source the loader accepts) without any test-only hook in src/.
 */
export async function widgetFrame(page: Page): Promise<Frame> {
  let found: Frame | undefined;
  await expect
    .poll(
      () => {
        found = page.frames().find((frame) => frame.url().startsWith(`${WIDGET_BASE}/embed`));
        return found !== undefined;
      },
      { timeout: 15_000 },
    )
    .toBe(true);
  if (found === undefined) throw new Error('[kb] no widget frame');
  return found;
}

/**
 * A CAUSAL BARRIER, replacing "wait N milliseconds and hope".
 *
 * The frame posts a legitimate `resize` — an envelope the loader accepts, with a visible effect on
 * the iframe's inline height. Because both it and whatever the spec posted just before it come from
 * the SAME source window, the browser delivers them to the loader's listener in order, so observing
 * the height change proves the earlier message has already been processed and dropped. That is a
 * fact about ordering rather than a guess about timing, and unlike a sleep it cannot pass because
 * nothing was ever delivered: if the loader is not listening, the height never changes and the spec
 * fails.
 */
export async function resizeBarrier(page: Page, height: number): Promise<void> {
  const frame = await widgetFrame(page);
  await frame.evaluate((h) => {
    const query = new URLSearchParams(location.search);
    parent.postMessage(
      { kb: 1, ch: query.get('ch') ?? '', type: 'resize', payload: { height: h } },
      query.get('origin') ?? '',
    );
  }, height);

  await expect
    .poll(
      () =>
        page.evaluate(() => {
          const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
            []) as ShadowRoot[];
          const iframe = roots
            .map((r) => r.querySelector('iframe'))
            .find((el): el is HTMLIFrameElement => el !== null);
          return iframe?.style.height ?? '';
        }),
      { timeout: 10_000 },
    )
    .toBe(`${height}px`);
}

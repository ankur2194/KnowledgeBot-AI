import { expect, test } from '@playwright/test';

import {
  API_BASE,
  captureShadowRoots,
  expectWidgetBooted,
  mintCount,
  resizeBarrier,
} from './_shadow.js';

/**
 * A tag manager firing the snippet twice is NORMAL — so is a CMS rendering the footer partial on
 * both the layout and the page. Without the sentinel the customer gets two launchers, two mints
 * against the composite rate limit, two bridges, and every message sends twice.
 */
test.describe('double injection', () => {
  test.beforeEach(async ({ page }) => {
    await captureShadowRoots(page);
  });

  test('two copies of the snippet produce ONE launcher, ONE iframe, ONE mint and ONE warning', async ({
    page,
  }) => {
    const warnings: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'warning') warnings.push(message.text());
    });

    const before = await mintCount(page, API_BASE);

    await page.goto('/?inject=2');

    // The frame has to complete the handshake before the mint can be counted.
    await expect.poll(() => mintCount(page, API_BASE), { timeout: 15_000 }).toBe(before + 1);
    // POSITIVE CONTROL: one launcher, one frame, and the app inside it reached `ready`. "Exactly
    // one of everything" is otherwise indistinguishable from "none of anything".
    await expectWidgetBooted(page);

    const counts = await page.evaluate(() => {
      const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
        []) as ShadowRoot[];
      return {
        shadowRoots: roots.length,
        launchers: roots.reduce((n, r) => n + r.querySelectorAll('button.kb-launcher').length, 0),
        frames: roots.reduce((n, r) => n + r.querySelectorAll('iframe').length, 0),
        // No iframe of ours may leak into the host document itself.
        strayFrames: document.querySelectorAll('iframe').length,
      };
    });

    expect(counts.shadowRoots).toBe(1);
    expect(counts.launchers).toBe(1);
    expect(counts.frames).toBe(1);
    expect(counts.strayFrames).toBe(0);

    /**
     * THE SECOND POSITIVE CONTROL, and the one that proves the STIMULUS rather than the widget: the
     * duplicate snippet actually executed. Without this the whole spec passes on a page where the
     * second `<script>` 404'd, never ran, and therefore never tested the sentinel at all.
     */
    expect(warnings.filter((text) => text.includes('[kb] already loaded'))).toHaveLength(1);

    /**
     * And no second mint arrives late. `waitForTimeout(1000)` was a guess about how late "late"
     * could be; the barrier is a fact instead. `resize` comes from the frame's own window, so the
     * browser delivers it to the loader after every message the frame sent before it — including
     * the `ready` that a second, wrongly-created bridge would have minted from.
     */
    await resizeBarrier(page, 280);
    expect(await mintCount(page, API_BASE)).toBe(before + 1);
  });

  test('the sentinel is checked BEFORE any DOM is created', async ({ page }) => {
    // attachShadow() on an element that already has a shadow root throws NotSupportedError, so a
    // guard placed after the host <div> is created fails hard instead of warning. Exactly one
    // attachShadow call is the observable form of "checked first".
    await page.goto('/?inject=2');
    await expect
      .poll(() =>
        page.evaluate(
          () =>
            (
              ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
                []) as ShadowRoot[]
            ).length,
        ),
      )
      .toBe(1);
  });
});

import { expect, test } from '@playwright/test';

import { captureShadowRoots, expectWidgetBooted } from './_shadow.js';

/**
 * A customer page with an aggressive global reset — `* { font-family: cursive !important;
 * line-height: 3 !important }` and `div { position: static !important }`, both of which ship in
 * real CSS frameworks.
 *
 * TWO DIRECTIONS MATTER and this spec asserts both: their CSS must not reshape our UI, and our CSS
 * must not escape the shadow root into their page.
 *
 * The trap being tested: shadow DOM blocks SELECTOR MATCHING, not INHERITANCE. `font-family`,
 * `font-size`, `color`, `line-height`, `letter-spacing`, `direction` and `visibility` all cross
 * the boundary, and custom properties pierce it by design. `:host { all: initial }` is what stops
 * them, and it is also why nothing in launcher.css is sized in `rem` — `rem` resolves against the
 * HOST page's root font size, and the fixture sets `html { font-size: 62.5% }`.
 */
test.describe('hostile host-page CSS', () => {
  test.beforeEach(async ({ page }) => {
    await captureShadowRoots(page);
  });

  test('typography and layout inside the shadow root are unchanged', async ({ page }) => {
    await page.goto('/?css=hostile');
    // The widget must have mounted UNDER the hostile stylesheet before any computed style is worth
    // reading — "unchanged" is trivially true of a launcher that does not exist.
    await expectWidgetBooted(page);

    const computed = await page.evaluate(() => {
      const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
        []) as ShadowRoot[];
      const button = roots
        .map((r) => r.querySelector('button.kb-launcher'))
        .find((el): el is HTMLButtonElement => el !== null);
      if (button === undefined) return null;
      const style = getComputedStyle(button);
      const host = button.getRootNode() as ShadowRoot;
      const hostStyle = getComputedStyle(host.host as HTMLElement);
      return {
        fontFamily: style.fontFamily,
        lineHeight: style.lineHeight,
        letterSpacing: style.letterSpacing,
        width: style.width,
        height: style.height,
        // The layout that MUST survive is written on the host element with priority, because
        // :host rules lose to any host-page selector matching that element.
        hostPosition: hostStyle.position,
      };
    });

    expect(computed).not.toBeNull();
    expect(computed?.fontFamily).not.toContain('cursive');
    expect(computed?.lineHeight).not.toBe('normal');
    expect(computed?.letterSpacing).toBe('normal');
    // 56px from launcher.css, in px — not scaled by the page's 62.5% root font size.
    expect(computed?.width).toBe('56px');
    expect(computed?.height).toBe('56px');
    // `div { position: static !important }` must not unpin the widget.
    expect(computed?.hostPosition).toBe('fixed');
  });

  test('our styles do not leak into the host document', async ({ page }) => {
    await page.goto('/?css=hostile');
    /**
     * POSITIVE CONTROL. "No <style> of ours in their <head>" is trivially true of a page where the
     * widget never mounted, so the sleep this replaces was asserting nothing on any run where the
     * loader failed for an unrelated reason. `expectWidgetBooted` waits for the launcher, the frame
     * and the app's own `ready` phase — so by the time the leak is measured, everything that COULD
     * leak has actually been created.
     */
    await expectWidgetBooted(page);

    const leaked = await page.evaluate(() => {
      const h1 = document.querySelector('h1');
      return {
        // The loader emits no <style> and no <link> into the host document: launcher.css is
        // imported `?inline` as a string and installed with adoptedStyleSheets on the shadow root.
        // A stray sibling .css file would also mean a second request into a page whose CSP we have
        // not seen.
        hostStyleTags: document.querySelectorAll('head style, head link[rel="stylesheet"]').length,
        headingFont: h1 === null ? '' : getComputedStyle(h1).fontFamily,
      };
    });

    expect(leaked.hostStyleTags).toBe(1); // the fixture's own hostile <style>, and nothing of ours
    expect(leaked.headingFont).toContain('cursive'); // their page still looks like their page
  });
});

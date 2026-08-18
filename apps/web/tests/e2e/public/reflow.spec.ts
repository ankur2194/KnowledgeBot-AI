import { expect, test, type Page } from '@playwright/test';

/**
 * The three responsive floors from `kb-ui-accessibility` → *Responsive*, each of which is invisible
 * at the width the screen was built at.
 *
 * They run on the public routes for the same reason as the axe spec: no Laravel here, and the
 * primitives under test — card, field, button, composer, empty state — are the same ones the console
 * composes from.
 */

const ROUTES = ['/login', '/register', '/c/e2e-probe-bot', '/this-route-does-not-exist'] as const;

/** `scrollWidth` overshoots `clientWidth` by a subpixel on some layouts; 1px is rounding, not a bar. */
const horizontalOverflow = () =>
  document.documentElement.scrollWidth - document.documentElement.clientWidth;

test.describe('WCAG 1.4.10 reflow', () => {
  for (const route of ROUTES) {
    test(`${route} reflows at 320px with no horizontal page scroll`, async ({ page }) => {
      // 320 CSS px is the floor the criterion names. Wide content — tables, charts, code, long URLs
      // — may scroll inside its OWN container; the page body may not.
      await page.setViewportSize({ width: 320, height: 720 });
      await page.goto(route);
      expect(await page.evaluate(horizontalOverflow), `${route} at 320px`).toBeLessThanOrEqual(1);
    });
  }

  test('a long unbroken URL in an answer does not scroll the page body', async ({ page }) => {
    // The model emits one and the layout breaks — `kb-ai-chat-ux` lists this as a gotcha, and
    // `overflow-wrap: anywhere` on `.kb-prose` is the fix. Asserted on the PROSE CONTAINER rather
    // than by typing into the composer: the composer is disabled until the `sdk/v1` bootstrap
    // exists, so `fill()` would time out on correct product behaviour and read as a layout bug.
    await page.setViewportSize({ width: 320, height: 720 });
    await page.goto('/c/e2e-probe-bot');
    await page.evaluate((url) => {
      const probe = document.createElement('div');
      probe.className = 'kb-prose';
      probe.textContent = url;
      document.body.append(probe);
    }, `https://example.test/${'a'.repeat(300)}`);

    expect(await page.evaluate(horizontalOverflow)).toBeLessThanOrEqual(1);
  });
});

test.describe('WCAG 1.4.4 resize text — 200% zoom', () => {
  for (const route of ROUTES) {
    test(`${route} survives 200% zoom without losing content`, async ({ page }) => {
      // Halving the viewport at a fixed device scale is the same layout the browser produces at 200%
      // zoom: CSS pixels double in physical size, so half as many fit.
      await page.setViewportSize({ width: 640, height: 512 });
      await page.goto(route);
      expect(await page.evaluate(horizontalOverflow), `${route} at 200% zoom`).toBeLessThanOrEqual(1);

      // "Without loss of content or FUNCTION": the primary control must still be reachable and
      // hittable, not merely present. A sticky header plus a sticky composer at 200% can leave a
      // phone-sized viewport with no room for either.
      const controls = page.locator('button:visible, a[href]:visible');
      expect(await controls.count(), `${route} has no visible controls at 200% zoom`).toBeGreaterThan(0);
    });
  }
});

test.describe('WCAG 1.4.12 text spacing', () => {
  /**
   * The user stylesheet the criterion specifies, verbatim. A fixed-height container around text is
   * what breaks this, and it is the one override that reliably finds them.
   */
  const TEXT_SPACING = {
    'line-height': '1.5',
    'letter-spacing': '0.12em',
    'word-spacing': '0.16em',
  } as const;

  /**
   * Applied through the CSSOM, one element at a time, rather than with `page.addStyleTag`.
   *
   * `addStyleTag` injects a `<style>` element, and hosted chat's CSP is `style-src 'self'` with a
   * per-response nonce Playwright cannot know — so the injection is REFUSED and the spec fails with
   * a CSP error rather than a spacing verdict. That refusal is the policy working; it is not
   * something to relax for a test. `style.setProperty` is the same escape hatch `<BotThemeScope/>`
   * relies on: MDN is explicit that CSP does not intercept properties set directly on an element's
   * `style` object.
   */
  const applyTextSpacing = async (page: Page) => {
    await page.evaluate((spacing) => {
      for (const element of document.querySelectorAll<HTMLElement>('*')) {
        for (const [property, value] of Object.entries(spacing)) {
          element.style.setProperty(property, value, 'important');
        }
        if (element.tagName === 'P') element.style.setProperty('margin-bottom', '2em', 'important');
      }
    }, TEXT_SPACING);
  };

  for (const route of ROUTES) {
    test(`${route} does not clip text under the 1.4.12 overrides`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(route);
      await applyTextSpacing(page);

      // A clipped element is one whose content is taller than its box while overflow is hidden —
      // which is precisely the fixed-height-container failure, and is invisible to a screenshot
      // because the missing line is simply not drawn.
      const clipped = await page.evaluate(() =>
        [...document.querySelectorAll('h1, h2, h3, p, button, a, label, li, td, th')]
          .filter((element) => {
            const style = getComputedStyle(element);
            if (style.overflow === 'visible' && style.overflowY === 'visible') return false;
            if (style.display === 'none') return false;
            // VISUALLY HIDDEN TEXT IS NOT CLIPPED TEXT. `sr-only` is a 1x1 box with hidden overflow
            // BY DESIGN — the content is complete and available to assistive technology, which is
            // the whole point of it. Detected by its shape rather than by its class name, so any
            // visually-hidden idiom is excluded rather than only Tailwind's. Without this the check
            // flags every `<h1 class="sr-only">` in the app and reports the accessibility affordance
            // as an accessibility failure.
            if (element.clientHeight <= 1 && element.clientWidth <= 1) return false;
            return element.scrollHeight > element.clientHeight + 1;
          })
          .map((element) => `${element.tagName}: ${element.textContent?.trim().slice(0, 40)}`),
      );
      expect(clipped, `${route} clips text at the 1.4.12 spacing`).toEqual([]);
      expect(await page.evaluate(horizontalOverflow)).toBeLessThanOrEqual(1);
    });
  }
});

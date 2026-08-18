import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * `@axe-core/playwright` on every route this project can reach, plus the operability checks a
 * scanner cannot make.
 *
 * ── WHY THE ROUTE LIST IS SHORT, AND WHAT COVERS THE REST ────────────────────────────────────────
 * The `admin` project authenticates by driving the REAL login route against a real Laravel and
 * writing storageState (playwright.config.ts). There is no Laravel in this environment and no
 * test-only login endpoint by design — "a faked credential cannot fail an isolation test" — so the
 * authenticated routes are NOT scanned here. They need `pnpm e2e --project=admin` against a running
 * stack, and the specs for them belong in `tests/e2e/admin/`.
 *
 * What IS here is every route reachable without a session: the whole `(auth)` group, hosted chat,
 * and the two 404s. Between them they exercise every primitive the console also uses — the form
 * field anatomy, the button, the card, the alert, the empty state, the composer, and the global
 * focus ring — so a violation in the shared layer fails here rather than waiting for a stack.
 *
 * ── A GREEN AXE RUN IS ABOUT A THIRD OF THE JOB ──────────────────────────────────────────────────
 * `kb-ui-accessibility` says so, and the second describe block is that other two thirds: scanners do
 * not test operability. A `div[role="button"]` with `tabindex="0"` and no key handler scans clean.
 */

const PUBLIC_ROUTES = [
  ['/login', 'sign in'],
  ['/register', 'register'],
  ['/forgot-password', 'forgot password'],
  ['/reset-password?token=probe&email=probe%40example.test', 'reset password'],
  ['/verify-email', 'verify email'],
  ['/invitations/accept?token=probe', 'accept invitation'],
  ['/c/e2e-probe-bot', 'hosted chat'],
  ['/this-route-does-not-exist', 'not found'],
] as const;

/**
 * `disableRules` is EMPTY and stays that way. A rule turned off to make a run green is a violation
 * that has been renamed; if a finding is genuinely a false positive, exclude the specific selector
 * with a comment naming why, so the next person sees the scope rather than the silence.
 */
const scan = (page: Page) =>
  new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);

test.describe('axe-core, WCAG 2.2 AA', () => {
  for (const [route, name] of PUBLIC_ROUTES) {
    test(`${name} has no violations`, async ({ page }) => {
      await page.goto(route);
      const results = await scan(page).analyze();

      // The default failure message is a wall of JSON. Naming the rule and the first offending node
      // is what makes a red run actionable without opening the trace.
      const summary = results.violations.map(
        (violation) =>
          `${violation.id} (${violation.impact}): ${violation.help}\n    ${violation.nodes[0]?.target.join(' ')}`,
      );
      expect(summary, `${route}\n  ${summary.join('\n  ')}`).toEqual([]);
    });
  }

  test('hosted chat has no violations in dark mode either', async ({ page }) => {
    // Both modes, always. The tone families and the soft-status pairs are where contrast splits, and
    // hosted chat is the one surface whose dark mode comes from `prefers-color-scheme` rather than
    // from a class somebody set — so it is the one most likely to be checked in only one mode.
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/c/e2e-probe-bot');
    const results = await scan(page).analyze();
    expect(results.violations.map((v) => v.id)).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('every focusable element on a page shows a visible focus indicator', async ({ page }) => {
    await page.goto('/login');

    // `outline: none` with no replacement is a defect, including "temporarily". The global rule in
    // globals.css is a 2px --ring outline at 2px offset; this asserts it actually lands on things
    // rather than being overridden by a component that pasted a v3-era `outline-none`.
    const seen: string[] = [];
    for (let i = 0; i < 12; i += 1) {
      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => {
        const element = document.activeElement;
        if (!element || element === document.body) return null;
        const style = getComputedStyle(element);
        return {
          tag: element.tagName,
          name: element.getAttribute('aria-label') ?? element.id ?? element.textContent?.trim().slice(0, 30) ?? '',
          outlineStyle: style.outlineStyle,
          outlineWidth: Number.parseFloat(style.outlineWidth),
        };
      });
      if (!focused) continue;
      const id = `${focused.tag}:${focused.name}`;
      if (seen.includes(id)) break;
      seen.push(id);

      expect(focused.outlineStyle, `${id} has no focus outline`).not.toBe('none');
      expect(focused.outlineWidth, `${id} focus outline is thinner than 2px`).toBeGreaterThanOrEqual(2);
    }

    expect(seen.length, 'nothing was reachable by Tab').toBeGreaterThan(0);
  });

  test('the skip link is first in the shell and moves focus to main', async ({ page, context }) => {
    /**
     * Without it every admin page costs a keyboard user the whole sidebar — one of the six keyboard
     * failures `kb-ui-accessibility` says are always the same six.
     *
     * The skip link lives in `<AppShell/>`, which is admin-only, and there is no Laravel here to
     * authenticate against. `proxy.ts`'s bounce is a UX redirect keyed on COOKIE PRESENCE (its own
     * comment is explicit that presence is not a session), so a dummy cookie is enough to render the
     * shell CHROME. That is all this asserts: the link, its position, and where focus lands. It is
     * not coverage for anything behind the session — every data surface below renders its
     * unavailable state, which is exactly why this spec touches none of them.
     */
    await context.addCookies([
      { name: 'kb_session', value: 'e2e-chrome-probe', domain: 'localhost', path: '/' },
    ]);
    await page.goto('/sources');

    // FIRST IN THE DOM. A skip link that comes after the nav skips nothing.
    const first = page.locator('a, button, input, textarea, select, [tabindex]').first();
    await expect(first).toHaveText(/skip to content/i);

    // Off-screen, not hidden: `display: none` and `visibility: hidden` remove an element from the
    // focus order, which defeats the entire point.
    await page.keyboard.press('Tab');
    await expect(first).toBeFocused();
    await expect(first).toBeVisible();

    await page.keyboard.press('Enter');
    expect(await page.evaluate(() => document.querySelector(':target')?.id ?? location.hash)).toContain(
      'main',
    );
  });

  test('every icon-only control has an accessible name that says the action', async ({ page }) => {
    await page.goto('/c/e2e-probe-bot');
    const unnamed = await page.evaluate(() =>
      [...document.querySelectorAll('button, a[href]')]
        .filter((element) => {
          const text = element.textContent?.trim() ?? '';
          const label = element.getAttribute('aria-label') ?? '';
          const labelledBy = element.getAttribute('aria-labelledby') ?? '';
          const title = element.getAttribute('title') ?? '';
          return text === '' && label === '' && labelledBy === '' && title === '';
        })
        .map((element) => element.outerHTML.slice(0, 120)),
    );
    expect(unnamed, 'icon-only controls with no accessible name').toEqual([]);
  });

  test('accessible names on one screen do not contain one another', async ({ page }) => {
    // A namespace, not a list. Both Playwright and vitest-browser match a role's name by
    // case-insensitive SUBSTRING, so a control named "Message" beside one named "Send message" is
    // two elements for every locator — and for a screen-reader user, two controls whose names
    // contain each other. Found on the composer, where the textarea was "Message".
    await page.goto('/c/e2e-probe-bot');
    const names: string[] = await page.evaluate(() =>
      [...document.querySelectorAll('button, a[href], textarea, input')]
        .map((element) => element.getAttribute('aria-label') ?? element.textContent?.trim() ?? '')
        .filter((name) => name.length > 2),
    );

    const overlapping = names.flatMap((a, i) =>
      names
        .filter((b, j) => i !== j && b.toLowerCase().includes(a.toLowerCase()))
        .map((b) => `"${a}" is contained in "${b}"`),
    );
    expect([...new Set(overlapping)]).toEqual([]);
  });
});

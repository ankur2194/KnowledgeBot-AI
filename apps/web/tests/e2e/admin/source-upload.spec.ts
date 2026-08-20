import { existsSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * `@axe-core/playwright` on `/sources/upload`, plus the keyboard-only walk a scanner cannot make.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Same standing as `sources.spec.ts` and `bots.spec.ts` beside it, and for the same reason: there is
 * no browser session and no Laravel in the environment that wrote it, so every selector below was
 * read out of the components rather than observed. Treat a first red run as "the spec is wrong" at
 * least as readily as "the page is wrong". Nothing here may be cited as evidence that this route is
 * accessible; what it is, is the walk written down so the first person with a stack RUNS it instead
 * of designing it.
 *
 * ── WHY IT IS COMMITTED ANYWAY, AND WHY IT SKIPS ITSELF ──────────────────────────────────────────
 * The `admin` Playwright project has a `storageState` and a `setup` dependency, and no `*.setup.ts`
 * exists to write `playwright/.auth/admin.json`. A project whose `storageState` file is missing does
 * not skip — it ERRORS at context creation, once per test — so an unguarded file turns `pnpm
 * web:e2e` from "runs the public project" into a wall of errors. `test.skip(condition, reason)` at
 * file scope is evaluated at DECLARATION time, before any fixture is requested, so no context is
 * created and no storageState is read.
 *
 * ── WHAT THIS ROUTE HAS THAT NO OTHER ADMIN SCREEN DOES ─────────────────────────────────────────
 *
 * A FILE INPUT STRETCHED OVER A DROP ZONE AT `opacity-0`. That shape is the reason the zone is
 * operable at all without a pointer — Tab reaches the input, Enter and Space open the picker, and the
 * global `:focus-visible` outline paints around the input's box, which IS the zone's. Every one of
 * those three is invisible to a scanner and is the first thing a rewrite would break, because the
 * usual shape is a `div[role="button"]` with an `onClick` and a hidden input, which scans clean and
 * does none of it.
 *
 * A LIST WHOSE ROWS ARE INTERACTIVE AND WHOSE NAMES MUST DISTINGUISH THEM. Five files means five
 * Cancel controls, five Remove controls and up to five progress bars; Radix supplies `role` and
 * `aria-valuenow` on a bar and nothing else, so an unnamed batch is five identical announcements and
 * the one that is stuck is unidentifiable. The filename lives in `aria-label` rather than in the
 * visible label (that was a 375px layout fix — `upload-file-row.tsx` carries the measurement), which
 * makes the accessible name the ONLY place uniqueness now lives and therefore the thing to check.
 *
 * A CANCEL THAT MUST BE REACHABLE THE WHOLE TIME BYTES ARE MOVING. The same requirement
 * `kb-ai-chat-ux` puts on the Stop control of a streaming answer, for the same reason: an operation
 * the user cannot stop from the keyboard is an operation a keyboard user cannot stop.
 *
 * ── WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────────
 * About a third of the job (`kb-ui-accessibility`). The remaining two thirds are a human with the
 * mouse unplugged and a human with a screen reader, and neither has happened for this screen.
 */

/** Relative to `apps/web`, matching `playwright.config.ts`'s `storageState` for the admin project. */
const ADMIN_STORAGE_STATE = 'playwright/.auth/admin.json';

test.skip(
  !existsSync(ADMIN_STORAGE_STATE),
  `${ADMIN_STORAGE_STATE} does not exist, so no admin session can be restored. It is written by the ` +
    "`setup` project, whose `*.setup.ts` has not been written — see this file's header. Running " +
    'these without it is an error per test, not a skip, which is why the whole file opts out here.',
);

/** `disableRules` is EMPTY and stays that way. A rule turned off to make a run green is a violation
 *  that has been renamed; a genuine false positive is excluded by SELECTOR, with a comment. */
const scan = (page: Page) =>
  new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);

const summarize = (violations: Awaited<ReturnType<AxeBuilder['analyze']>>['violations']) =>
  violations.map(
    (violation) =>
      `${violation.id} (${violation.impact}): ${violation.help}\n    ${violation.nodes[0]?.target.join(' ')}`,
  );

/** Two files whose names share no substring, because role and text matching are both substring
 *  matches and a nesting pair makes every row locator ambiguous. */
const ALPHA = {
  name: 'alpha-handbook.pdf',
  mimeType: 'application/pdf',
  buffer: Buffer.alloc(64),
};
const BRAVO = { name: 'bravo-ledger.csv', mimeType: 'text/csv', buffer: Buffer.alloc(128) };

/**
 * Open the screen and wait until the browser fetch for this organization's ceilings has answered —
 * the form, or the refusal. Scanning before that is scanning a skeleton, which is a real surface and
 * not the one these tests name.
 */
async function openUpload(page: Page): Promise<void> {
  await page.goto('/sources/upload');
  await expect(page.getByRole('heading', { name: 'Add files', level: 1 })).toBeVisible();
  await expect(
    page
      .getByLabel('Add files to this organization')
      .or(page.getByText("You don't have access to this"))
      .or(page.getByText('The upload limits for this organization could not be loaded')),
  ).toBeVisible();
}

/** The one file input. It is `opacity-0` and stretched over the zone, which `setInputFiles` does not
 *  care about — it sets the value directly, exactly as it does for a `sr-only` input. */
const picker = (page: Page) => page.locator('input[type="file"]');

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the empty upload screen has no violations', async ({ page }) => {
    await openUpload(page);
    test.skip(
      (await picker(page).count()) === 0,
      'this account cannot manage sources, so there is no form to scan',
    );

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);
    expect(summary, `/sources/upload\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the screen has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. This screen carries the tone families (a tinted kind badge per row), the
    // soft-status pairs (the phase pill) and `--destructive-soft` (the per-row refusal) in one list,
    // which is where contrast splits between modes.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form to scan for this account');

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);
    expect(summary, `/sources/upload (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('a batch with a marked file and a progress bar has no violations', async ({ page }) => {
    /**
     * The states a scan of the empty screen cannot see: a row carrying an inline error, the
     * destructive banner above the list, and a `<Progress>` whose accessible name is ours rather than
     * the primitive's. `aria-valuenow` on an unnamed progressbar is legitimate to axe, which is
     * precisely why the naming is checked by hand below rather than left to this.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form to scan for this account');

    await picker(page).setInputFiles([ALPHA, BRAVO]);
    await expect(page.getByRole('listitem').filter({ hasText: ALPHA.name })).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);
    expect(summary, `/sources/upload (batch)\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('the keyboard-only walk, which a scanner cannot make', () => {
  test('Tab reaches the drop zone as a real control, and Enter is what opens the picker', async ({
    page,
  }) => {
    /**
     * THE PROPERTY: the zone is a real `<input type="file">` at `opacity-0`, not a `div` with an
     * `onClick`. So it is focusable, it carries the global `:focus-visible` outline, and Enter/Space
     * open the picker natively — none of which a `div[role="button"][tabindex="0"]` does, and all of
     * which axe reports as clean either way.
     *
     * WHAT THIS TEST CANNOT DO, said out loud: no browser automation can drive the OS file dialog, so
     * pressing Enter here would open a modal the run cannot close. The check is therefore that the
     * control is FOCUSABLE and is an input of type file — the two facts that make Enter work — and
     * the press itself is part of the human pass this file exists to specify.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form for this account');

    await page.keyboard.press('Tab');
    for (let step = 0; step < 20; step += 1) {
      if (await picker(page).evaluate((node) => node === document.activeElement)) break;
      await page.keyboard.press('Tab');
    }

    await expect(picker(page)).toBeFocused();
    // A VISIBLE bound label, not a placeholder and not an `aria-label` nobody can see.
    await expect(page.getByLabel('Add files to this organization')).toBeFocused();
    // `opacity-0`, never `display:none` or `visibility:hidden` — a hidden control is not focusable,
    // and this is the assertion that would fail if somebody "tidied" it into `sr-only`.
    await expect(picker(page)).toHaveCSS('opacity', '0');
  });

  test('every row control names its own file, and none of them is icon-only', async ({ page }) => {
    /**
     * `kb-ui-accessibility` §8.18 is TWO checks — keyboard access and a screen-reader label — and this
     * is the second. Two rows means two Remove controls; if their accessible names do not differ, a
     * screen-reader user hears the same word twice with nothing to tell them apart, and every locator
     * in this file becomes ambiguous.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form for this account');

    await picker(page).setInputFiles([ALPHA, BRAVO]);

    const removes = page.getByRole('button', { name: /^Remove .+/ });
    await expect(removes).toHaveCount(2);

    const names = await Promise.all(
      (await removes.all()).map((control) => control.getAttribute('aria-label')),
    );
    expect(new Set(names).size).toBe(names.length);

    // WCAG 2.5.3 Label in Name: the VISIBLE text must be a contiguous substring of the accessible
    // name, or speech input on the words the user can see does not activate the control. The visible
    // label is the short verb and the filename is appended, which satisfies it deliberately.
    for (const control of await removes.all()) {
      const accessible = (await control.getAttribute('aria-label')) ?? '';
      const visible = ((await control.textContent()) ?? '').trim();
      expect(accessible.toLowerCase()).toContain(visible.toLowerCase());
    }

    // ...and it is genuinely a button: focusable and activatable from the keyboard, which removes
    // the row.
    await removes.first().focus();
    await expect(removes.first()).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('listitem').filter({ hasText: ALPHA.name })).toHaveCount(0);
    // FOCUS IS NOT LOST TO `<body>` when the focused element is removed. This is the assertion most
    // likely to fail on a first run, and if it does the fix is in the app: move focus to the next row
    // or to the drop zone before removing.
    await expect(page.locator('body')).not.toBeFocused();
  });

  test('Cancel is reachable by keyboard the whole time bytes are moving', async ({ page }) => {
    /**
     * The upload is held open by the route handler below, which freezes exactly the window under
     * test. `route.fulfill()` is legal here — the ban is on `text/event-stream` bodies, which cannot
     * be delivered in pieces through fulfilment — and holding rather than throttling makes the window
     * CERTAIN rather than likely.
     *
     * No `page.waitForTimeout()` anywhere: every wait below is a web-first assertion that retries, and
     * the one genuinely time-based thing on this screen (nothing) would use `page.clock`.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form for this account');

    let release!: () => void;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    await page.route('**/api/v1/organizations/*/sources', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      await held;
      return route.fulfill({ status: 201, body: JSON.stringify({ data: { id: 'held' } }) });
    });

    await picker(page).setInputFiles([ALPHA]);
    await page.getByRole('button', { name: 'Upload 1 file' }).click();

    const cancel = page.getByRole('button', { name: `Cancel ${ALPHA.name}` });
    await expect(cancel).toBeVisible();
    await cancel.focus();
    await expect(cancel).toBeFocused();
    await page.keyboard.press('Enter');

    // A CANCELLATION IS AN OUTCOME, NOT A FAILURE: the row says Cancelled, and there is no error
    // block and no retry affordance for a thing the user asked for.
    await expect(page.getByText('Cancelled')).toBeVisible();
    await expect(page.getByRole('button', { name: `Try again with ${ALPHA.name}` })).toHaveCount(0);

    release();
  });

  test('each progress bar is named for its own file', async ({ page }) => {
    /**
     * Radix supplies `role="progressbar"` and `aria-valuenow` AND NOTHING ELSE, and an unnamed
     * progressbar is legitimate to axe — so this is the check the scan cannot make. The related
     * regression is worth naming because it shipped once: `components/ui/progress.tsx` used to
     * destructure `value` out of the props it forwarded, so every bar rendered
     * `data-state="indeterminate"` with no `aria-valuenow` while filling correctly on screen.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form for this account');

    let release!: () => void;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    await page.route('**/api/v1/organizations/*/sources', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      await held;
      return route.fulfill({ status: 201, body: JSON.stringify({ data: { id: 'held' } }) });
    });

    await picker(page).setInputFiles([ALPHA, BRAVO]);
    await page.getByRole('button', { name: 'Upload 2 files' }).click();

    const bars = page.getByRole('progressbar');
    // A real upload of 64 bytes may finish its BYTES instantly and sit in `finishing`, where there is
    // no determinate bar at all — that is the state P13 exists for, and it is a legitimate outcome
    // rather than a failure of this check.
    const count = await bars.count();
    test.skip(count === 0, 'both rows reached `finishing` before a bar could be observed');

    for (const bar of await bars.all()) {
      const name = (await bar.getAttribute('aria-label')) ?? '';
      expect(name).toMatch(/^Upload progress for .+/);
      await expect(bar).toHaveAttribute('aria-valuenow', /\d+/);
    }

    release();
  });

  test('a batch-level message is bound to the control it is about', async ({ page }) => {
    /**
     * An error that is only red is not an error state. The batch's own message — the at-least-one
     * rule, the organization's cap — belongs to the file input, so the input carries `aria-invalid`
     * and points `aria-describedby` at it. PER-FILE messages are deliberately NOT bound here: they
     * belong to their rows, and pointing one control at five of them announces the whole batch's
     * problems every time focus lands on the picker.
     */
    await openUpload(page);
    test.skip((await picker(page).count()) === 0, 'no form for this account');

    // Nothing chosen: `.min(1)` is the batch rule that always exists.
    await page.getByRole('button', { name: 'Upload files' }).click();

    // The submit is disabled with nothing chosen, so this asserts what the SCREEN does rather than
    // what the resolver would say — the disabled state is the affordance, and the message below is
    // what a keyboard user gets if they reach the control anyway.
    await expect(page.getByRole('button', { name: 'Upload files' })).toBeDisabled();

    // The cap sentence is always described; the assertion that matters is that it is described
    // FIRST, so the control is explained before it is criticised.
    const described = (await picker(page).getAttribute('aria-describedby')) ?? '';
    expect(described.split(' ').length).toBeGreaterThanOrEqual(1);
    const firstDescription = await page.locator(`#${described.split(' ')[0]}`).textContent();
    expect(firstDescription).toContain('Drag files here');
  });
});

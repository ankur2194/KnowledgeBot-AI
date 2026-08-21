import { existsSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * `@axe-core/playwright` on `/sources/{sourceId}`, plus the operability checks a scanner cannot make.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Same standing as `bots.spec.ts` and `sources.spec.ts`, and for the same reason: there is no
 * browser session and no Laravel in the environment that wrote it, so every selector below was read
 * out of the components rather than observed. Treat a first red run as "the spec is wrong" at least
 * as readily as "the page is wrong". Nothing here may be cited as evidence that this route is
 * accessible; what it is, is the check list written down so the first person with a stack RUNS it
 * instead of designing it.
 *
 * ── WHY IT IS COMMITTED ANYWAY, AND WHY IT SKIPS ITSELF ──────────────────────────────────────────
 *
 * The `admin` Playwright project has a `storageState` and a `setup` dependency, and NO `*.setup.ts`
 * exists to write `playwright/.auth/admin.json`. A Playwright project whose `storageState` file is
 * missing does not skip — it ERRORS at context creation, once per test — so committing these
 * unguarded would turn `pnpm web:e2e` from "runs the public project" into a wall of errors.
 *
 * `test.skip(condition, reason)` at file scope is evaluated at DECLARATION time, before any fixture
 * is requested, so no browser context is created and no storageState is read. When the setup lands,
 * these run. Writing that setup means inventing a credential and a seed contract, which is the
 * control plane's call rather than a test file's.
 *
 * ── WHAT THIS ROUTE HAS THAT `/sources` DOES NOT, AND WHY EACH ONE IS HERE ──────────────────────
 *
 * A FAN-OUT. The bot-assignment panel issues one request per bot on its page, because no endpoint
 * answers "which bots hold a grant on this source" (see `features/sources/source-assignment-api.ts`).
 * A component test can prove the wiring against four mocked responses; only a real run can show what
 * ten of them do to a page's time-to-interactive and to `throttle:admin`'s 120/minute budget.
 *
 * A WRITE WITH NO OPTIMISTIC ECHO. Assigning a source is a POST whose only in-flight feedback is a
 * disabled button, an `aria-busy` row and a polite live region. Whether that live region is actually
 * announced — and announced ONCE — cannot be observed from a DOM assertion, which is why the
 * keyboard walk below is written as steps a person performs with a screen reader running rather than
 * as an assertion that would pass against a silent page.
 *
 * A DESTRUCTIVE DIALOG WHOSE COPY IS COMPUTED. Unlike the list's, this consequence is built from the
 * detail resource's counts, so the numbers in it are seed-dependent and the assertion is on their
 * SHAPE rather than on their values.
 *
 * TENANT TEXT IN A BLOCK ELEMENT. The extracted preview is document content rendered `pre-wrap`; a
 * scanner will not tell you that a 40,000-character line has pushed the page 12,000px wide, and the
 * reflow check below is the one that would.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). A `div[role="button"]` with `tabindex="0"` and
 * no key handler scans clean, so the second describe block covers what it can and the keyboard-only
 * pass stays a human step — the one written out in `the keyboard-only walk` below.
 */

/** Relative to `apps/web`, matching `playwright.config.ts`'s `storageState` for the admin project. */
const ADMIN_STORAGE_STATE = 'playwright/.auth/admin.json';

test.skip(
  !existsSync(ADMIN_STORAGE_STATE),
  `${ADMIN_STORAGE_STATE} does not exist, so no admin session can be restored. It is written by the ` +
    '`setup` project, whose `*.setup.ts` has not been written — see this file\'s header. Running ' +
    'these without it is an error per test, not a skip, which is why the whole file opts out here.',
);

/**
 * `disableRules` is EMPTY and stays that way, exactly as in the public, bots and sources specs. A
 * rule turned off to make a run green is a violation that has been renamed; a genuine false positive
 * is excluded by SELECTOR, with a comment naming why.
 */
const scan = (page: Page) =>
  new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);

/** The public spec's failure formatter: the rule, its impact and the first offending node. */
const summarize = (violations: Awaited<ReturnType<AxeBuilder['analyze']>>['violations']) =>
  violations.map(
    (violation) =>
      `${violation.id} (${violation.impact}): ${violation.help}\n    ${violation.nodes[0]?.target.join(' ')}`,
  );

/**
 * Reach the detail screen THE WAY A USER DOES — from the list, by clicking a document's name.
 *
 * Deliberately not `page.goto('/sources/01J…')` with an id from a seed: the id is not knowable
 * without a seed contract that does not exist, and navigating from the list also exercises the link
 * this batch added to the name cell. It returns the source's own name, which every later assertion
 * needs and none of them may hard-code.
 */
async function openFirstSource(page: Page): Promise<string> {
  await page.goto('/sources');
  await expect(page.getByRole('heading', { name: 'Sources', level: 1 })).toBeVisible();

  const firstName = page.locator('tbody tr a[href^="/sources/"]').first();
  test.skip((await firstName.count()) === 0, 'no source is visible for this account');

  const name = ((await firstName.textContent()) ?? '').trim();
  await firstName.click();

  await expect(page.getByRole('heading', { name: 'Source', level: 1 })).toBeVisible();
  // Wait until the browser fetch has ANSWERED. Scanning before that is scanning a skeleton, which is
  // a real surface and not the one these tests name.
  await expect(
    page
      .getByRole('heading', { name, level: 2 })
      .or(page.getByText("You don't have access to this"))
      .or(page.getByText('You do not have access to this.')),
  ).toBeVisible();

  return name;
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the source detail screen has no violations', async ({ page }) => {
    await openFirstSource(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources/{id}\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the source detail screen has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. This screen carries a `--tone-*-surface` type chip, a soft-status pill, a
    // `--warning-soft` degraded note and two `--card-inset` strips — four of the places contrast
    // splits between modes, on one page.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openFirstSource(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources/{id} (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the delete confirmation has no violations while open', async ({ page }) => {
    const name = await openFirstSource(page);

    const trigger = page.getByRole('button', { name: `Actions for ${name}` });
    test.skip((await trigger.count()) === 0, 'this account cannot manage this source');

    await trigger.click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources/{id} — delete dialog\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the delete dialog states its consequence in numbers, not in adjectives', async ({ page }) => {
    /**
     * The whole reason this screen fetches `SourceDetailResource` before offering the control. The
     * assertion is on the SHAPE — a grouped integer followed by "searchable excerpts" — because the
     * values are seed-dependent, and on the ZERO case being a sentence rather than a row of zeroes.
     */
    const name = await openFirstSource(page);

    const trigger = page.getByRole('button', { name: `Actions for ${name}` });
    test.skip((await trigger.count()) === 0, 'this account cannot manage this source');

    await trigger.click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();

    const dialog = page.getByRole('dialog');
    const consequence = ((await dialog.textContent()) ?? '').replace(/\s+/g, ' ');

    // Either it says how much is live, in numbers, or it says nothing is.
    expect(
      /\d[\d,.  ]* searchable excerpts?/.test(consequence) ||
        consequence.includes('Nothing in it is live right now'),
    ).toBe(true);
    expect(consequence).toContain('This is phase 1 of 2');
    // The word "chunk" is ours and belongs in no rendered string.
    expect(consequence.toLowerCase()).not.toContain('chunk');

    // The typed gate, verbatim: a near miss stays disabled.
    const confirm = dialog.getByRole('button', { name: 'Delete source' });
    await expect(confirm).toBeDisabled();
    await dialog.getByRole('textbox').fill(`${name} `);
    await expect(confirm).toBeDisabled();
    await dialog.getByRole('textbox').fill(name);
    await expect(confirm).toBeEnabled();

    // ESCAPE CLOSES IT AND NOTHING IS DELETED. Focus returns to the trigger, which is the
    // primitive's job and exactly the part a hand-rolled dialog loses.
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });

  test('every assignment control names its own bot, and none is a bare verb', async ({ page }) => {
    /**
     * `kb-ui-accessibility` §8.18: keyboard access AND a screen-reader label, which are two checks. A
     * column of `<Button>`s scans clean whether or not their names distinguish the rows, and ten
     * controls called "Assign" are unusable with a screen reader and ambiguous to every locator.
     */
    await openFirstSource(page);

    const controls = page.getByRole('button', {
      name: /^(Assign this source to|Remove this source from) .+/,
    });
    test.skip((await controls.count()) === 0, 'no bot is visible for this account');

    const names = await Promise.all(
      (await controls.all()).map((control) => control.getAttribute('aria-label')),
    );
    expect(new Set(names).size).toBe(names.length);

    // And each is genuinely a button: focusable and activatable from the keyboard. Not activated
    // here — this test may not write a grant into whatever organization it is pointed at.
    await controls.first().focus();
    await expect(controls.first()).toBeFocused();
  });

  test('the in-flight announcement is polite, and empty when nothing is happening', async ({ page }) => {
    /**
     * The one channel that says a write is in flight to somebody not looking at the button. It must
     * be `role="status"` (polite) rather than `alert`, and EMPTY at rest — a live region holding
     * stale text is re-announced on every unrelated mutation of the node.
     */
    await openFirstSource(page);

    const status = page.locator('[role="status"]');
    test.skip((await status.count()) === 0, 'the assignment panel did not render for this account');

    expect(((await status.first().textContent()) ?? '').trim()).toBe('');
  });

  test('a source whose extracted text is one enormous line does not widen the page', async ({
    page,
  }) => {
    /**
     * TENANT TEXT IN A BLOCK ELEMENT, and the reflow rule (`kb-ui-accessibility`, 320px at 400%).
     * `whitespace-pre-wrap` preserves the server's blank lines; `break-words` is what keeps a
     * 40,000-character unbroken line from setting the document's width. A scanner reports neither.
     */
    await page.setViewportSize({ width: 375, height: 900 });
    await openFirstSource(page);

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    // A couple of pixels of rounding is not a horizontal scrollbar.
    expect(overflow).toBeLessThanOrEqual(2);
  });

  test('the extracted preview is text, and no markup in it became an element', async ({ page }) => {
    /**
     * Non-negotiable 7 at the sink. The server does not escape `content_preview` — deliberately —
     * so a document containing `<img src=x onerror=…>` is a live XSS test every time this page
     * renders one. This asserts the negative that matters: nothing inside the preview block is an
     * element the document did not put there.
     */
    await openFirstSource(page);

    const preview = page.locator('p.whitespace-pre-wrap').first();
    test.skip((await preview.count()) === 0, 'this source has no extracted text yet');

    const children = await preview.evaluate((node) => node.childElementCount);
    expect(children).toBe(0);
  });
});

/**
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE KEYBOARD-ONLY WALK — A HUMAN STEP, WRITTEN DOWN SO IT IS PERFORMED RATHER THAN DESIGNED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * None of it has been performed. It is not automatable in a way that would mean anything: every
 * step below is about what a person HEARS and where their focus visibly goes, and an assertion that
 * a node exists passes against a page that announces nothing.
 *
 *  1. From `/sources`, Tab to a document's name and press Enter. The detail screen loads and focus
 *     is at the top of the document, not lost on `<body>`.
 *  2. Tab once: "All sources, link". Shift+Tab returns to it from anywhere without a trap.
 *  3. Tab to the row-actions trigger. It announces as "Actions for <the document's name>, button" —
 *     NOT "button" and not "Actions". Enter opens the menu; Down/Up move between three items each
 *     of which announces its word ("Reprocess", "Disable"/"Enable", "Delete"); Escape closes it and
 *     focus returns to the trigger.
 *  4. Re-open, choose Delete. Focus moves INTO the dialog, the title is announced, and the
 *     consequence is read as one paragraph including its numbers. Tab cycles inside the dialog only.
 *     Escape closes it and focus returns to the trigger.
 *  5. Tab through the count tiles: they are a description list and are announced as term/value
 *     pairs, not as a run of unrelated numbers.
 *  6. Tab to an Assign button. It announces as "Assign this source to <bot>, button". Press Enter
 *     WITHOUT looking at the screen: the polite live region should say "Assigning <source> for
 *     <bot>…" ONCE, and the row's `aria-busy` should not produce a second announcement. When the
 *     write settles the region falls silent — it must not repeat, and it must not announce success,
 *     because the re-read row is the success.
 *  7. Press Enter on the same control again immediately: nothing happens, because every control in
 *     the panel is disabled while a write is in flight. A screen reader says "unavailable" and the
 *     live region has already said why.
 *  8. With a source whose organization has more than ten bots, Tab to "Next bots", press Enter, and
 *     confirm focus is not thrown to the top of the document.
 *  9. Every focused control shows the focus ring against BOTH planes — the card and the recessed
 *     `--card-inset` rows — which is the check `kb-motion-and-effects` owns and no scanner makes.
 * 10. Repeat 3–7 at 375px, where the action cluster wraps under the title.
 */

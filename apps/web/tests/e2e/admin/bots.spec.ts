import { existsSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * `@axe-core/playwright` on `/bots` and `/bots/[botId]`, plus the operability checks a scanner
 * cannot make.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * It was written in an environment with no browser session and no Laravel, against a container
 * whose chromium is not the pinned build (1194 symlinked as 1234). Every selector below was read
 * out of the components rather than observed in a browser, so treat a first red run as "the spec is
 * wrong" at least as readily as "the page is wrong". Nothing in this file may be cited as evidence
 * that these two routes are accessible; what it is, is the route list, the tag set and the checks
 * written down so the first person with a stack runs them instead of designing them.
 *
 * ── WHY IT IS COMMITTED ANYWAY, AND WHY IT SKIPS ITSELF ──────────────────────────────────────────
 *
 * `tests/e2e/public/accessibility.spec.ts` already names this file's address in as many words: the
 * authenticated routes "need `pnpm e2e --project=admin` against a running stack, and the specs for
 * them belong in `tests/e2e/admin/`". The slot exists; the `admin` project exists in
 * playwright.config.ts with its storageState and its `setup` dependency. What does NOT exist is any
 * `*.setup.ts`, so `playwright/.auth/admin.json` is never written — and a Playwright project whose
 * `storageState` file is missing does not skip, it ERRORS at context creation, once per test.
 * Committing these specs unguarded would turn `pnpm web:e2e` from "runs the public project" into a
 * wall of errors, which is a regression in an artifact nobody here can run to notice.
 *
 * Hence the file-level guard. `test.skip(condition, reason)` at file scope is evaluated at
 * DECLARATION time, before any fixture is requested, so no browser context is created and no
 * storageState is read. When the setup lands, these run.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ───────────────────────────────────────────────────────────────
 *
 * THE `*.setup.ts` ITSELF. Writing one means inventing a credential and a seed contract, and
 * `services/core-api/database/seeders/DatabaseSeeder.php` is empty ON PURPOSE with a docblock
 * arguing that test fixtures do not belong in it. Deciding how the E2E stack gets an administrator
 * is the control plane's call, not a test file's, and guessing at it would produce a setup that
 * looks authoritative and is fiction. The config's own comment states the shape it must have:
 * authenticate by driving the REAL login route, because "a faked credential cannot fail an
 * isolation test".
 *
 * A SEEDED BOT. These specs discover one from the list rather than assuming a fixture, and skip
 * with a message when the organization has none — the alternative is a spec that fails for a reason
 * that has nothing to do with accessibility.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). A `div[role="button"]` with `tabindex="0"` and
 * no key handler scans clean, so the second describe block covers what it can and the keyboard-only
 * pass stays a human step.
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
 * `disableRules` is EMPTY and stays that way, exactly as in the public spec. A rule turned off to
 * make a run green is a violation that has been renamed; a genuine false positive is excluded by
 * SELECTOR, with a comment naming why, so the next person sees the scope rather than the silence.
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
 * Open `/bots` and return the href of the first bot in the list, or null when there are none.
 *
 * ADDRESSED BY THE SLUG CELL, which is the one link into the editor (`features/bots/bot-columns.tsx`
 * — the cell is a `<Link href={`/bots/${row.original.id}`}>` on the ULID, never on the slug, because
 * the slug is a handle an operator may rename).
 */
async function firstBotHref(page: Page): Promise<string | null> {
  await page.goto('/bots');

  // The heading is server-rendered and the table is not, so waiting on the heading proves the route
  // resolved and waiting on either the link or the empty state proves the browser fetch answered.
  await expect(page.getByRole('heading', { name: 'Bots', level: 1 })).toBeVisible();

  const firstLink = page.locator('a[href^="/bots/"]').first();
  const emptyState = page.getByText('No bots yet');

  await expect(firstLink.or(emptyState)).toBeVisible();

  return (await firstLink.count()) > 0 ? firstLink.getAttribute('href') : null;
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the bots list has no violations', async ({ page }) => {
    await page.goto('/bots');
    await expect(page.getByRole('heading', { name: 'Bots', level: 1 })).toBeVisible();

    // THE TABLE, NOT ONLY THE SHELL. A scan that ran before the browser fetch answered would be
    // scanning a skeleton — which is a real surface and not the one this test names.
    await expect(
      page.getByRole('table').or(page.getByText('No bots yet')).or(page.getByText('This list could not be refreshed')),
    ).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/bots\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the bots list has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. The tone families and the soft-status pairs are where contrast splits, and
    // the status column is exactly a soft-status pair — the one cell most likely to be checked in
    // one mode only.
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/bots');
    await expect(page.getByRole('heading', { name: 'Bots', level: 1 })).toBeVisible();

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('the create-bot dialog has no violations while open', async ({ page }) => {
    // A DIALOG IS ITS OWN SURFACE. Focus trapping, the labelled title and the initial focus target
    // are all only present once it is open, and a scan of the page behind it says nothing about
    // them.
    await page.goto('/bots');

    const trigger = page.getByRole('button', { name: 'Add bot' });

    // The trigger renders NOTHING for a viewer without `bots.manage` (`bot-create-dialog.tsx`), so
    // its absence is a legitimate outcome of who the setup logged in as rather than a failure.
    test.skip((await trigger.count()) === 0, 'the signed-in role does not hold bots.manage');

    await trigger.click();
    await expect(page.getByRole('dialog')).toBeVisible();

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('every tab of the bot editor has no violations', async ({ page }) => {
    const href = await firstBotHref(page);

    test.skip(href === null, 'this organization has no bots, so there is no editor to scan');

    await page.goto(href as string);

    // EACH PANEL IS SCANNED, not just the one that opens. Radix renders the inactive panels with
    // `hidden`, so a violation in `Model & retrieval` is invisible to a scan of `Identity & voice` —
    // and the three panels are three different forms, which is where the label, the description
    // association and the error announcement live.
    for (const label of ['Identity & voice', 'Model & retrieval', 'Publishing']) {
      const tab = page.getByRole('tab', { name: label });

      await expect(tab).toBeVisible();
      await tab.click();
      await expect(tab).toHaveAttribute('aria-selected', 'true');

      const results = await scan(page).analyze();
      const summary = summarize(results.violations);

      expect(summary, `${href} — ${label}\n  ${summary.join('\n  ')}`).toEqual([]);
    }
  });

  test('the bot editor has no violations in dark mode', async ({ page }) => {
    const href = await firstBotHref(page);

    test.skip(href === null, 'this organization has no bots, so there is no editor to scan');

    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto(href as string);
    await expect(page.getByRole('tab', { name: 'Identity & voice' })).toBeVisible();

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the editor tabs follow the WAI-ARIA tabs keyboard pattern', async ({ page }) => {
    /**
     * A scanner reads the roles and is satisfied. `role="tablist"` with three `role="tab"` children
     * that only respond to a click is a clean scan and a control a keyboard user cannot operate —
     * the arrow keys are the pattern's whole point, and `Tab` must move OUT of the list rather than
     * between the tabs.
     */
    const href = await firstBotHref(page);

    test.skip(href === null, 'this organization has no bots, so there is no editor to operate');

    await page.goto(href as string);

    const identity = page.getByRole('tab', { name: 'Identity & voice' });
    const model = page.getByRole('tab', { name: 'Model & retrieval' });

    await identity.focus();
    await expect(identity).toBeFocused();

    await page.keyboard.press('ArrowRight');
    await expect(model).toBeFocused();

    // AND ONE TAB STOP FOR THE WHOLE LIST. Roving tabindex is what makes that true; without it a
    // keyboard user pays one keystroke per tab on the way to the form every time.
    await page.keyboard.press('Tab');
    await expect(model).not.toBeFocused();
  });

  test('every column header that sorts says so, and is a button', async ({ page }) => {
    /**
     * The table is SERVER-DRIVEN, so a sortable header is an interactive control that navigates.
     * `aria-sort` is the part a scanner does not check and a screen-reader user depends on: without
     * it the current sort is conveyed by an arrow glyph alone, which is the colour-independence rule
     * failing in a different medium.
     */
    await page.goto('/bots');
    await expect(page.getByRole('heading', { name: 'Bots', level: 1 })).toBeVisible();

    const sortButtons = page.getByRole('columnheader').getByRole('button');

    test.skip((await sortButtons.count()) === 0, 'the list rendered no table, so there are no headers');

    const first = sortButtons.first();

    await first.click();

    await expect(page.getByRole('columnheader').filter({ has: first })).toHaveAttribute(
      'aria-sort',
      /ascending|descending/,
    );
  });
});

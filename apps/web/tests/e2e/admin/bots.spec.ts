import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/bots` and `/bots/[botId]`, plus the operability checks a scanner
 * cannot make.
 *
 * ── FIRST EXECUTED 2026-08-24 ──────────────────────────────────────────────────────────────────
 *
 * The banner here used to read "THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY", and
 * that stopped being true on 2026-08-24. `./harness.ts` carries the account of that run — the
 * stack, the browser, and the four different reasons thirteen specs went red on it.
 *
 * It was written in an environment with no browser session and no Laravel, so every selector below
 * was read out of the components rather than observed. Every test in this file passed on the run
 * above, so it now says something about these two routes on that stack — and the container it was
 * written against had no chromium at all, which is what the `browsers` stage exists to fix.
 *
 * ── THE SETUP NOW EXISTS, AND THIS PARAGRAPH USED TO SAY IT DID NOT ────────────────────────────
 *
 * It said: "What does NOT exist is any `*.setup.ts`, so `playwright/.auth/admin.json` is never
 * written", and named writing one as out of scope because it "means inventing a credential and a
 * seed contract". `tests/e2e/auth.setup.ts` is that file. It invents neither: the credential comes
 * from `KB_E2E_ADMIN_EMAIL` / `KB_E2E_ADMIN_PASSWORD` in the environment, set by a human who ran
 * `kb:bootstrap-organization` and completed the ordinary password-reset flow — which is what that
 * command's refusal to accept a password in any form already required of anybody.
 *
 * THE FILE-SCOPE GUARD STAYS, and it is now exact rather than defensive. A Playwright project whose
 * `storageState` path is missing does not skip, it ERRORS at context creation, once per test, so
 * `pnpm web:e2e` on an unprepared machine would be a wall of errors instead of a public-project run.
 * `skipWithoutAdminSession()` is evaluated at DECLARATION time, before any fixture is requested, so
 * no browser context is created and no storageState is read — and `auth.setup.ts` DELETES a stale
 * state file when it skips, so the predicate is true if and only if this run authenticated.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ───────────────────────────────────────────────────────────────
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

skipWithoutAdminSession();

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

    // ADDRESS THE HEADER, THEN THE BUTTON INSIDE IT — not the other way round. This read
    //     page.getByRole('columnheader').filter({ has: sortButtons.first() })
    // and failed with "element(s) not found" on the first run that had rows to sort. Playwright
    // re-roots a `has:` locator at each candidate, so a `columnheader` locator inside `has:` looks
    // for a `<th>` INSIDE a `<th>`, which exists nowhere. The message is indistinguishable from
    // "the table has no sortable header", which is the opposite of what was on screen.
    const sortableHeaders = page.getByRole('columnheader').filter({ has: page.getByRole('button') });
    test.skip(
      (await sortableHeaders.count()) === 0,
      'the list rendered no table, so there are no headers',
    );

    const header = sortableHeaders.first();
    await header.getByRole('button').click();

    // `header` is a LOCATOR and is re-resolved here, which matters: the sort navigates and the
    // whole table is replaced, so a handle captured before the click would be detached.
    await expect(header).toHaveAttribute('aria-sort', /ascending|descending/);
  });
});

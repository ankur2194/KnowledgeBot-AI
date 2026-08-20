import { existsSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * `@axe-core/playwright` on `/sources`, plus the operability checks a scanner cannot make.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Same standing as `bots.spec.ts`, and for the same reason: there is no browser session and no
 * Laravel in the environment that wrote it, so every selector below was read out of the components
 * rather than observed. Treat a first red run as "the spec is wrong" at least as readily as "the page
 * is wrong". Nothing here may be cited as evidence that this route is accessible; what it is, is the
 * check list written down so the first person with a stack runs it instead of designing it.
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
 * control plane's call rather than a test file's (`bots.spec.ts` carries the full argument).
 *
 * ── WHAT THIS ROUTE HAS THAT `/bots` DOES NOT, AND WHY EACH ONE IS HERE ─────────────────────────
 *
 * A POLL. The list refetches every five seconds while any row is mid-run, which makes two things
 * checkable only here: that the refetch affordance is not a live region (a table announcing itself
 * every five seconds is a reason to stop using the product) and that the poll actually STOPS against a
 * real endpoint. `tests/components/sources-screen.test.tsx` can only assert the WIRING — that
 * `refetchInterval` is a function of the page and returns `false` for a settled one — and
 * `tests/unit/source-list.test.ts` pins the predicate; neither can observe elapsed traffic, and the
 * limiter that would eventually reject it is in Laravel.
 *
 * A DESTRUCTIVE DIALOG. Focus trapping, the labelled title, initial focus and the typed confirmation
 * only exist once it is open, and a scan of the page behind it says nothing about them.
 *
 * A FORBIDDEN STATE THAT IS REACHABLE BY AN ORDINARY MEMBER. `sources.view` is withheld from an
 * ANALYST while the sidebar offers this route to everybody, so the forbidden surface is a real page an
 * ordinary account reaches — the one case in the console where that is true, and it needs its own
 * signed-in role to test rather than a crafted error.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). A `div[role="button"]` with `tabindex="0"` and no
 * key handler scans clean, so the second describe block covers what it can and the keyboard-only pass
 * stays a human step.
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
 * `disableRules` is EMPTY and stays that way, exactly as in the public and bots specs. A rule turned
 * off to make a run green is a violation that has been renamed; a genuine false positive is excluded
 * by SELECTOR, with a comment naming why.
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
 * Open `/sources` and wait until the browser fetch has answered — the table, one of the two empty
 * states, or the refusal. Scanning before that is scanning a skeleton, which is a real surface and not
 * the one these tests name.
 */
async function openSources(page: Page): Promise<void> {
  await page.goto('/sources');
  await expect(page.getByRole('heading', { name: 'Sources', level: 1 })).toBeVisible();
  await expect(
    page
      .getByRole('table')
      .or(page.getByText('No sources yet'))
      .or(page.getByText('No matches'))
      .or(page.getByText("You don't have access to this"))
      .or(page.getByText('You do not have access to this.')),
  ).toBeVisible();
}

/** The first row's Delete control, or null when this organization has no source to delete. */
function firstDeleteButton(page: Page) {
  // Every row action's accessible name ends with the source's own name, so the prefix match is what
  // addresses "the delete button on some row" without knowing which sources are seeded.
  return page.getByRole('button', { name: /^Delete .+/ }).first();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the sources list has no violations', async ({ page }) => {
    await openSources(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the sources list has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. The tone families and the soft-status pairs are where contrast splits, and
    // this table has BOTH in one row — a `--tone-*-surface` type chip beside a soft-status pill — so
    // it is the densest colour surface in the console and the one most likely to be checked in one
    // mode only.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openSources(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the delete confirmation has no violations while open', async ({ page }) => {
    await openSources(page);

    const trigger = firstDeleteButton(page);
    // The control renders for `sources.manage` only, and not at all on a row whose removal is already
    // under way — so its absence is a legitimate outcome of what is seeded rather than a failure.
    test.skip((await trigger.count()) === 0, 'no deletable source is visible for this account');

    await trigger.click();
    await expect(page.getByRole('dialog')).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/sources — delete dialog\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the destructive dialog cannot be confirmed until the name is typed', async ({ page }) => {
    /**
     * The gate itself, and it is not an accessibility check — it is the one thing standing between a
     * mis-click and a two-phase removal. A scanner sees a labelled button either way.
     */
    await openSources(page);

    const trigger = firstDeleteButton(page);
    test.skip((await trigger.count()) === 0, 'no deletable source is visible for this account');

    // The accessible name is `Delete <name>`; the name itself is what has to be typed.
    const name = ((await trigger.getAttribute('aria-label')) ?? '').replace(/^Delete /, '');
    await trigger.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    // The verb in the button matches the verb in the title, and neither is "OK" or "Confirm".
    const confirm = dialog.getByRole('button', { name: 'Delete source' });
    await expect(confirm).toBeDisabled();

    // A NEAR MISS STAYS DISABLED. The comparison is verbatim, so the check is not "did they type
    // something".
    await dialog.getByRole('textbox').fill(`${name} `);
    await expect(confirm).toBeDisabled();

    await dialog.getByRole('textbox').fill(name);
    await expect(confirm).toBeEnabled();

    // ESCAPE CLOSES IT AND NOTHING IS DELETED. Focus returns to the trigger, which is the primitive's
    // job and is exactly the part a hand-rolled dialog loses.
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
  });

  test('every row action is reachable and named without a mouse', async ({ page }) => {
    /**
     * `kb-ui-accessibility` §8.18: keyboard access AND a screen-reader label, which are two checks. A
     * row of `<Button>`s scans clean whether or not their names distinguish the rows, and twenty-five
     * controls called "Delete" are unusable with a screen reader and ambiguous to every locator.
     */
    await openSources(page);

    const deletes = page.getByRole('button', { name: /^Delete .+/ });
    const count = await deletes.count();
    test.skip(count === 0, 'no deletable source is visible for this account');

    const names = await Promise.all(
      (await deletes.all()).map((control) => control.getAttribute('aria-label')),
    );
    // Each one names its own row, so they are distinct.
    expect(new Set(names).size).toBe(names.length);

    // And the control is genuinely a button: focusable, and activatable from the keyboard.
    await deletes.first().focus();
    await expect(deletes.first()).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
  });

  test('every column header that sorts says so, and is a button', async ({ page }) => {
    /**
     * The table is SERVER-DRIVEN, so a sortable header is an interactive control that navigates.
     * `aria-sort` is the part a scanner does not check and a screen-reader user depends on: without it
     * the current sort is conveyed by an arrow glyph alone, which is the colour-independence rule
     * failing in a different medium.
     */
    await openSources(page);

    const sortButtons = page.getByRole('columnheader').getByRole('button');
    test.skip(
      (await sortButtons.count()) === 0,
      'the list rendered no table, so there are no headers',
    );

    const first = sortButtons.first();
    await first.click();

    await expect(page.getByRole('columnheader').filter({ has: first })).toHaveAttribute(
      'aria-sort',
      /ascending|descending/,
    );
    // The sort is in the URL, which is what makes a filtered or ordered view shareable and a "no
    // results" report debuggable.
    await expect(page).toHaveURL(/[?&]sort=/);
  });

  test('the poll stops once nothing on the page is moving, and never announces itself', async ({
    page,
  }) => {
    /**
     * THE FAILURE THIS EXISTS FOR IS INVISIBLE IN A BROWSER: a `refetchInterval` that never stops
     * looks exactly like one that stops correctly, until an admin limiter starts rejecting real work
     * hours later. It can only be observed against a real endpoint, over time.
     *
     * `page.clock` RATHER THAN `waitForTimeout`, which is banned repo-wide and would also make this
     * test twelve seconds long. The clock is installed BEFORE the navigation, because the interval is
     * scheduled during the first render and a clock installed afterwards would not own that timer.
     *
     * It is written for an organization whose sources are all settled — a fresh seed with nothing
     * ingesting — because that is the state in which the answer is unambiguous. With a run in flight
     * the correct behaviour is the opposite one, so the test skips rather than inverts.
     */
    const listRequests: string[] = [];
    page.on('request', (request) => {
      if (/\/api\/v1\/organizations\/[^/]+\/sources\?/.test(request.url())) {
        listRequests.push(request.url());
      }
    });

    await page.clock.install();
    await openSources(page);

    const processing = page.getByRole('cell', {
      name: /Fetching|Parsing|Normalizing|Chunking|Embedding|Indexing|Queued|Deleting/,
    });
    test.skip(
      (await processing.count()) > 0,
      'a source is mid-run in this organization, so the list is CORRECT to keep polling',
    );

    const afterLoad = listRequests.length;
    // Four poll intervals. The assertion is that NOTHING happens across them, which is why the clock
    // is fast-forwarded rather than waited on: there is no event to wait for.
    await page.clock.fastForward(20_000);

    expect(listRequests.length, listRequests.join('\n')).toBe(afterLoad);

    // AND THE REFETCH AFFORDANCE IS NOT A LIVE REGION. A table that re-reads itself every five
    // seconds while a document ingests would otherwise announce itself every five seconds — which
    // states.md and `kb-ui-accessibility` both name as a reason to stop using the product. The bar
    // carries `aria-hidden`, so this asserts that every element bearing its one styling hook inside
    // the table surface is hidden from the accessibility tree.
    const indicators = page.locator('[data-slot="data-table-surface"] .kb-skeleton');
    for (const indicator of await indicators.all()) {
      await expect(indicator).toHaveAttribute('aria-hidden', 'true');
    }
  });
});

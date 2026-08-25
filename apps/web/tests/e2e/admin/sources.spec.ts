import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/sources`, plus the operability checks a scanner cannot make.
 *
 * ── FIRST EXECUTED 2026-08-24 ──────────────────────────────────────────────────────────────────
 *
 * The banner here used to read "THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY", and
 * that stopped being true on 2026-08-24. `./harness.ts` carries the account of that run — the
 * stack, the browser, and the four different reasons thirteen specs went red on it.
 *
 * Same origin as `bots.spec.ts`: written with no browser session and no Laravel, so every selector
 * below was read out of the components rather than observed. It passed on the run above, which makes
 * it evidence about this route on that stack rather than a check list waiting for one.
 *
 * ── THE SETUP EXISTS NOW, AND THIS FILE SKIPS WHEN NO SESSION WAS MINTED ───────────────────────
 *
 * This paragraph used to say NO `*.setup.ts` existed to write `playwright/.auth/admin.json` and that
 * writing one was the control plane's call rather than a test file's. `tests/e2e/auth.setup.ts` is
 * that file, and it decides nothing: the credential is `KB_E2E_ADMIN_EMAIL` /
 * `KB_E2E_ADMIN_PASSWORD` from the environment, set by a human who ran `kb:bootstrap-organization`
 * and completed the ordinary password-reset flow.
 *
 * THE FILE-SCOPE GUARD STAYS AND IS NOW EXACT. A Playwright project whose `storageState` path is
 * missing does not skip — it ERRORS at context creation, once per test — so an unguarded file turns
 * `pnpm web:e2e` from "runs the public project" into a wall of errors.
 * `skipWithoutAdminSession()` is evaluated at DECLARATION time, before any fixture is requested, so
 * no browser context is created and no storageState is read; and the setup DELETES a stale state
 * file when it skips, so the predicate is true if and only if this run authenticated.
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
 *
 * ── WHAT HAS ACTUALLY BEEN EXECUTED, AS OF 2026-08-21 ───────────────────────────────────────────
 *
 * Exactly one thing: the declaration-time guard below, via `playwright test --list`, in both
 * directions — green against the component as it stands, and red (with the whole run refusing to
 * collect) when its first pattern was made to miss. Every `page.*` line in this file remains
 * unobserved, and the header above still governs how to read a first red run.
 */

skipWithoutAdminSession();

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

/**
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * DECLARATION-TIME STALENESS GUARD — the reason the locators below cannot rot in silence again
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE FAILURE THIS EXISTS FOR ALREADY HAPPENED, AND IT WAS QUIETER THAN A FALSE PASS. Until
 * 2026-08-21 the three tests that reach a row action located
 * `getByRole('button', { name: /^Delete .+/ })`. The row actions had become an overflow menu several
 * batches earlier — one trigger named `Actions for {name}`, with `Delete` as a `menuitem` inside it —
 * so the locator matched zero elements and each test hit its own
 * `test.skip((await trigger.count()) === 0, …)` and reported "skipped". A skip reads as "the fixture
 * did not have one of those", which is a legitimate outcome here, so nothing about the output said
 * the spec was addressing a control that no longer exists.
 *
 * A `count() === 0` skip cannot distinguish "this account may not delete" from "this locator is
 * wrong", and it never will. What CAN distinguish them is the component's own source, so that is
 * what is asserted — synchronously, in the file body, which Playwright executes while COLLECTING
 * even though every test in this file is skipped for want of `playwright/.auth/admin.json`. A throw
 * here fails `playwright test` and even `playwright test --list`; there is no run of any project in
 * which it is not evaluated. That is the whole point: this file's tests cannot run in the
 * environments that currently exist, so the guard has to be the part that does.
 *
 * ITS BLAST RADIUS IS THE WHOLE RUN, AND THAT IS THE PRICE. A collection-time throw takes every
 * project down with it — measured 2026-08-21 by making the first pattern miss on purpose:
 * `playwright test --list` printed the message below and then `Total: 0 tests in 0 files`, so the
 * `public` project stops running too. That is the correct trade only because the guard cannot fire
 * on a seeded-data difference, a flaky network or a slow page: it reads one file off disk and
 * matches three literals. If it is red, the spec is wrong, and a spec that addresses controls which
 * do not exist is not usefully "passing" for the other projects' sake.
 *
 * IT IS NOT A SUBSTITUTE FOR RUNNING THE SPEC. It proves the markup the locators name is still in
 * the component. It cannot prove the control renders, is reachable, or behaves — those are the
 * assertions below, and they remain unexecuted (see this file's header).
 */
const ROW_ACTIONS_COMPONENT = fileURLToPath(
  new URL('../../../src/features/sources/source-row-actions.tsx', import.meta.url),
);

/**
 * The literal fragments every locator in this file depends on, each paired with the assertion that
 * would start skipping silently if it disappeared. Matched against the component SOURCE, so a rename
 * in `src/` is a red collection rather than three quiet skips.
 */
const ROW_ACTION_CONTRACT: ReadonlyArray<{ pattern: RegExp; why: string }> = [
  {
    // Anchored on `source.name` and not just on the words: a trigger labelled `Actions` with the
    // name somewhere else would satisfy a looser pattern and break every locator here.
    pattern: /aria-label=\{`Actions for \$\{source\.name\}`\}/,
    why: 'rowActionsTrigger() locates the overflow trigger by the accessible name `Actions for <name>`, and every test here derives the source name by stripping that prefix',
  },
  {
    // A DropdownMenuItem and not a Button — that distinction IS the drift this guard exists for.
    // `variant="destructive"` alone would also match the inline <Alert>, so the element name is
    // part of the pattern.
    pattern: /<DropdownMenuItem\s+variant="destructive"/,
    why: "the Delete entry is a `menuitem` inside the overflow menu, not a row button; openDeleteDialog() opens the menu and then selects it by role `menuitem` and the name `Delete`",
  },
  {
    pattern: /confirmLabel="Delete source"/,
    why: 'the confirm button in the destructive dialog is located by the name `Delete source`, which must match the verb in the title rather than being "OK" or "Confirm"',
  },
];

const ROW_ACTIONS_SOURCE = readFileSync(ROW_ACTIONS_COMPONENT, 'utf8');

for (const { pattern, why } of ROW_ACTION_CONTRACT) {
  if (!pattern.test(ROW_ACTIONS_SOURCE)) {
    throw new Error(
      `tests/e2e/admin/sources.spec.ts is stale: ${ROW_ACTIONS_COMPONENT} no longer matches ` +
        `${String(pattern)}.\n  ${why}.\n` +
        '  Every test in this file that reaches a row action would now match zero elements and ' +
        'SKIP rather than fail, which is quieter than a false pass. Update the locators (and this ' +
        'contract) to whatever the component renders now — do not delete the guard, and do not ' +
        'relax it to a pattern that would match anything.',
    );
  }
}

/**
 * The first row's overflow-menu trigger, whose accessible name carries the source's own name.
 *
 * Zero matches is still a legitimate outcome — the cluster renders for `sources.manage` only, and
 * not at all on a row whose removal is already under way — which is why the call sites skip on it.
 * The guard above is what keeps that skip meaning what it says.
 */
function rowActionsTrigger(page: Page) {
  return page.getByRole('button', { name: /^Actions for .+/ }).first();
}

/** `Actions for Quarterly report.pdf` -> `Quarterly report.pdf`. */
async function sourceNameFrom(trigger: ReturnType<typeof rowActionsTrigger>): Promise<string> {
  return ((await trigger.getAttribute('aria-label')) ?? '').replace(/^Actions for /, '');
}

/**
 * Open the row's menu and choose Delete, which is what opens the destructive dialog.
 *
 * Two steps and not one, mirroring `source-detail.spec.ts` — the same component, reached from the
 * other screen, already located this way there. `Delete` performs nothing on selection; it opens the
 * dialog, and the menu closes first so focus is never trapped between two overlays.
 */
async function openDeleteDialog(page: Page, trigger: ReturnType<typeof rowActionsTrigger>) {
  await trigger.click();
  await page.getByRole('menuitem', { name: 'Delete' }).click();

  return page.getByRole('dialog');
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

    const trigger = rowActionsTrigger(page);
    // The cluster renders for `sources.manage` only, and not at all on a row whose removal is already
    // under way — so its absence is a legitimate outcome of what is seeded rather than a failure.
    // That this skip means what it says, rather than "the locator is stale", is what the
    // declaration-time guard above holds.
    test.skip((await trigger.count()) === 0, 'no manageable source is visible for this account');

    const dialog = await openDeleteDialog(page, trigger);
    await expect(dialog).toBeVisible();

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

    const trigger = rowActionsTrigger(page);
    test.skip((await trigger.count()) === 0, 'no manageable source is visible for this account');

    // The trigger's accessible name is `Actions for <name>`; the name itself is what has to be typed.
    const name = await sourceNameFrom(trigger);

    const dialog = await openDeleteDialog(page, trigger);
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

    // ESCAPE CLOSES IT AND NOTHING IS DELETED.
    //
    // It does NOT assert where focus lands afterwards, and that omission is deliberate rather than an
    // oversight: the dialog was opened from a menu item that has already unmounted, so "focus returns
    // to the trigger" depends on how the menu and the dialog hand off — a claim about two Radix
    // primitives interacting that nobody here has watched happen. `source-detail.spec.ts` reaches the
    // same dialog through the same component and stops at the same line. It is step 4 of that file's
    // written-down keyboard walk, which is where an unautomatable claim belongs.
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });

  test('every row action is reachable and named without a mouse', async ({ page }) => {
    /**
     * `kb-ui-accessibility` §8.18: keyboard access AND a screen-reader label, which are two checks. A
     * row of icon-only `<Button>`s scans clean whether or not their names distinguish the rows, and
     * twenty-five triggers announcing "Actions" are unusable with a screen reader and ambiguous to
     * every locator — which is the failure the component's own comment at the trigger names.
     */
    await openSources(page);

    const triggers = page.getByRole('button', { name: /^Actions for .+/ });
    test.skip((await triggers.count()) === 0, 'no manageable source is visible for this account');

    const names = await Promise.all(
      (await triggers.all()).map((control) => control.getAttribute('aria-label')),
    );
    // Each one names its own row, so they are distinct.
    expect(new Set(names).size).toBe(names.length);

    // And the control is genuinely a button: focusable, and activatable from the keyboard. Enter
    // opens the MENU — the trigger performs nothing itself — so the assertion is the menu and its
    // three named items, not a dialog. Anything past this point (Down/Up between the items, where
    // focus lands when the menu closes) is step 3 of `source-detail.spec.ts`'s written-down keyboard
    // walk, because what it is really about is what a person hears.
    await triggers.first().focus();
    await expect(triggers.first()).toBeFocused();
    await page.keyboard.press('Enter');

    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Reprocess' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: /^(Disable|Enable)$/ })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Delete' })).toBeVisible();

    // Escape closes the menu and returns focus to the trigger, which is the primitive's job and the
    // part a hand-rolled menu loses. Unlike the dialog hand-off above, this one is a single
    // primitive's documented behaviour.
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(triggers.first()).toBeFocused();
  });

  test('every column header that sorts says so, and is a button', async ({ page }) => {
    /**
     * The table is SERVER-DRIVEN, so a sortable header is an interactive control that navigates.
     * `aria-sort` is the part a scanner does not check and a screen-reader user depends on: without it
     * the current sort is conveyed by an arrow glyph alone, which is the colour-independence rule
     * failing in a different medium.
     */
    await openSources(page);

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

import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/audit-logs` — this organization's append-only trail — plus the
 * operability checks a scanner cannot make.
 *
 * ── FIRST EXECUTION IS STILL AHEAD OF THIS FILE ────────────────────────────────────────────────
 *
 * `./harness.ts` carries the account of the 2026-08-24 and 2026-08-25 runs; this spec was written
 * after them, for a route that landed later, and every selector below was read out of the components
 * rather than observed.
 *
 * ── UNLIKE ITS NEIGHBOURS, THIS LIST IS NOT EMPTY ON THE HARNESS STACK ─────────────────────────
 *
 * `/conversations` and `/quotas` render against an organization that has never held a thread. The
 * audit trail is different: **signing in writes a row**, and `auth.setup.ts` signs in through the
 * real `/login` form before any of these specs run. So the table, its status pills and its actor
 * cells are genuinely on screen when the scan happens — which is what makes a green run here mean
 * more than a green run on the two lists that render an empty state.
 *
 * That also makes the ACTOR PICKER non-vacuous. `audit-screen.tsx` builds its options from the rows
 * it has, so on an organization with no rows it would be an empty select — a control that scans
 * clean and says nothing.
 *
 * ── THERE IS NO ROW ROUTE, AND THE ABSENCE IS DELIBERATE ──────────────────────────────────────
 *
 * `/audit-logs/{id}` does not exist and this file must not invent one. The binding would resolve
 * over a table whose model carries no `#[ScopedBy]` — `AuditLog` deliberately has none, because the
 * scope's predicate is FALSE for the NULL-org platform rows — so it would load a row with no tenant
 * predicate and hand it to a policy that throws on a platform-scope row. Filtering the list answers
 * the same questions through the one query shape that carries the organization predicate. A spec
 * that clicked through to a detail page would be asserting a route that must never be built.
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE ────────────────────────────────────────────────────────
 *
 * NOTHING IS WRITTEN, and on this surface that is not a choice a test makes: the surface has no
 * write. Rows are append-only — the application role holds no UPDATE and no DELETE, and retention is
 * a partition drop rather than a row delete. There is no form, no dialog and no destructive action
 * to open.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ──────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only and screen-reader passes stay
 * human steps.
 */

skipWithoutAdminSession();

/**
 * Open the route and wait until the browser fetch has resolved into ONE OF ITS OUTCOMES.
 *
 * The arms are mutually exclusive by construction — `ServerDataTable` renders exactly one of
 * forbidden / error / empty / table — which is the property an `.or()` chain needs and the one
 * `embedding.spec.ts` was missing when it went red on the first run.
 *
 * THE FORBIDDEN ARM IS NOT DECORATION HERE. `audit.view` is the narrowest grant in the console —
 * owner and admin only — while the sidebar offers this route to everybody, so a run signed in as an
 * analyst or a knowledge manager legitimately lands on it.
 */
async function openAuditLog(page: Page): Promise<void> {
  await page.goto('/audit-logs');

  await expect(page.getByRole('heading', { name: 'Audit log', level: 1 })).toBeVisible();

  await expect(
    page
      .getByRole('table', { name: 'Audit trail' })
      .or(page.getByText('Nothing has been audited yet'))
      .or(page.getByText('No audited events match these filters.'))
      .or(page.getByText('Audit trail could not be loaded'))
      .or(page.getByText("You don't have access to this")),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the audit trail has no violations', async ({ page }) => {
    await openAuditLog(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/audit-logs\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the audit trail has no violations in dark mode either', async ({ page }) => {
    /**
     * BOTH MODES, ALWAYS — and this table is where it matters most in the console. The outcome
     * column is a soft-status pair carrying a FAILURE tone, which is the one tone that must survive
     * both ramps: a `denied` row that reads as an ordinary row in dark mode is a security event
     * rendered as noise.
     */
    await page.emulateMedia({ colorScheme: 'dark' });
    await openAuditLog(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/audit-logs (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the filter row has no violations', async ({ page }) => {
    /**
     * ALWAYS RENDERED, unlike the table beneath it: the three `FilterSelect`s and the date pair are
     * in the `header` slot, which `ServerDataTable` renders regardless of the data state. The
     * `Operation` picker is the one worth scanning — its options are `AuditLogger::OPERATIONS`, a
     * closed vocabulary of thirty-odd strings, and a long select is where the label association gets
     * dropped.
     */
    await openAuditLog(page);

    await expect(page.getByRole('combobox', { name: 'Operation' })).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/audit-logs filters\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the five filters do not share an accessible name', async ({ page }) => {
    /**
     * A scanner checks that a control HAS a name, never that the name is UNIQUE on the page.
     * `audit-screen.tsx` labels its pickers `Actor` / `Operation` / `Outcome` with a comment saying
     * a bare "Filter" would resolve against every control on the row — this is the test that would
     * notice if someone made them all say it.
     */
    await openAuditLog(page);

    for (const name of ['Actor', 'Operation', 'Outcome', 'Audited from', 'Audited until']) {
      const control = page.getByLabel(name, { exact: true });
      await expect(control, `no control is labelled "${name}"`).toHaveCount(1);
    }
  });

  test('the trail says whose it is, so nobody reads it as the whole platform', async ({ page }) => {
    /**
     * NOT COSMETIC, AND NOT A SCANNER'S BUSINESS. Platform-scope rows — the ones whose
     * `organization_id` is NULL — are deliberately excluded from this endpoint, so a page that
     * presented itself as "the audit log" would be making a completeness claim that is false. The
     * disclosure under the table is the only thing that stops an operator concluding an event never
     * happened because it is not here.
     *
     * It is asserted by its SUBSTANCE rather than by the whole sentence, because the organization's
     * own name is interpolated into it and this spec may not know which organization the run was
     * pointed at.
     */
    await openAuditLog(page);

    await expect(
      page.getByText('Platform-level events that belong to no organization are not shown here.'),
    ).toBeVisible();
  });

  test('the table names itself, so a screen reader does not announce an unnamed table', async ({
    page,
  }) => {
    /**
     * `ServerDataTable` renders its `caption` as a visually hidden `<caption>`, which is what gives
     * the table an accessible name. The caption is invisible, so nothing but a test notices when it
     * is dropped — and this page has a second table-shaped thing nowhere near it, which is exactly
     * the case a name disambiguates.
     */
    await openAuditLog(page);

    const table = page.getByRole('table', { name: 'Audit trail' });

    test.skip(
      (await table.count()) === 0,
      'the trail rendered its empty or forbidden state, so there is no table to name',
    );

    await expect(table).toBeVisible();
  });

  test('the overflow note is not inside the definition list it counts', async ({ page }) => {
    /**
     * THE REGRESSION TEST FOR THE DEFECT THIS FILE'S FIRST EXECUTION FOUND.
     *
     * The `Recorded` cell renders up to four `<dt>`/`<dd>` pairs and then "3 more fields". That note
     * used to be a `<div>` INSIDE the `<dl>`, which is not a legal definition list — a `<div>` is
     * admitted there only as a wrapper around a term/definition group, never as a place for loose
     * text — and it made every scan of this route report `definition-list` (serious).
     *
     * This is written as a DOM assertion rather than left to the scans above, and the reason is
     * coverage rather than duplication: the note only renders for a row whose `details` carries more
     * than four keys, so whether the three axe scans see it at all depends on what happens to be on
     * page one. This one says so out loud — it SKIPS when no such row is visible, which is the
     * honest report, and a scan that silently missed the node would have been read as proof.
     */
    await openAuditLog(page);

    const note = page.getByText(/^(1 more field|\d+ more fields)$/);
    const count = await note.count();

    test.skip(
      count === 0,
      'no visible row records more than the preview count, so the overflow note did not render',
    );

    /**
     * THE NEAREST OF `dt`, `dd` OR `dl` — AND A BARE `closest('dl')` IS WRONG HERE, WHICH THIS SPEC
     * LEARNED BY GOING RED AGAINST A PAGE AXE HAD JUST PASSED.
     *
     * `ServerDataTable` renders BOTH layouts into the DOM and hides one with CSS: the real `<table>`
     * above 768px and a stack of row-cards below it. The card variant puts every cell inside a
     * `<dd>` of its OWN `<dl>`, so a `closest('dl')` over four matched notes reported two "inside a
     * list" — both of them legal, because they sit inside a `<dd>` rather than loose in the list.
     *
     * The bug shape is loose content whose nearest list ancestor is reached WITHOUT crossing a term
     * or a definition. `closest('dt, dd, dl')` returning the `<dl>` is exactly that, and it catches
     * the original defect — a `<div>` directly inside the list — as well as any wrapper around it.
     */
    const loose = await note.evaluateAll(
      (nodes) => nodes.filter((node) => node.closest('dt, dd, dl')?.tagName === 'DL').length,
    );

    expect(
      loose,
      `${loose} of ${count} overflow notes sit loose in a <dl>, where nothing but a term or a definition may go`,
    ).toBe(0);
  });

  test('sorting the trail keeps the page usable rather than replacing it with a skeleton', async ({
    page,
  }) => {
    /**
     * The table is SERVER-DRIVEN, so a sortable header is an interactive control that navigates.
     * What this checks is the thing a component test cannot: that a real round trip through the URL,
     * the query key and the endpoint comes back with a table still on screen — rather than blanking
     * to a skeleton, which `ServerDataTable` avoids by keeping the previous page's rows while the
     * next key loads.
     *
     * THE LOCATOR IS `columnheader` FILTERED BY `has: button`, not `columnheader >> button` —
     * `bots.spec.ts` records why: the second form reports "the table has no sortable header" when
     * one is on screen.
     */
    await openAuditLog(page);

    const sortable = page.getByRole('columnheader').filter({ has: page.getByRole('button') });
    const count = await sortable.count();

    test.skip(count === 0, 'the trail rendered no table, so there are no headers to sort by');

    await sortable.first().getByRole('button').click();

    // RE-QUERIED RATHER THAN REUSED. The whole table is replaced on a sort, so a handle captured
    // before the click would be detached by the time it is asserted.
    await expect(page.getByRole('table', { name: 'Audit trail' })).toBeVisible();
  });
});

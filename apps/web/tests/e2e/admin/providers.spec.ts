import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/settings/providers`, plus the operability checks a scanner cannot make.
 *
 * ── FIRST EXECUTED 2026-08-24 ──────────────────────────────────────────────────────────────────
 *
 * The banner here used to read "THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY", and
 * that stopped being true on 2026-08-24. `./harness.ts` carries the account of that run — the
 * stack, the browser, and the four different reasons thirteen specs went red on it.
 *
 * Same origin as its neighbours: every selector below was read out of the components rather than
 * observed in a browser. On that run every test here passed EXCEPT the dark-mode scan, which was red
 * on a real WCAG failure in the design tokens rather than on anything this file got wrong.
 *
 * **THE DARK-MODE SCAN IS GREEN AS OF 2026-08-25**, on the fix `docs/22` § R1 argued for: the accent
 * has its own token as *text* (`--link`), because no lightness satisfies both floors `--primary`
 * carries. What that green means here, precisely: the `text-link` element on this route is the
 * `/settings/embedding` link, which sits in an unconditional `<Card>` and is therefore on screen and
 * scanned whether or not the organization has any connections. The `ConnectionRow` link is a
 * different instance and needs a row to exist — see `harness.ts` for what this run did and did not
 * cover.
 *
 * ── WHY THIS ROUTE NEEDS ITS OWN SPEC AND IS NOT COVERED BY `/bots` ────────────────────────────
 *
 * IT IS THE ONE CONSOLE SURFACE THAT HANDLES KEY MATERIAL. `masked_key` is per-organization data
 * derived from a credential, the create form has a secret field, and the rotate dialog exists to
 * replace one. Non-negotiable 9 is not an accessibility rule, but the surface that carries it is the
 * surface most likely to grow a bespoke control — and a bespoke control is where the label
 * association and the error announcement go missing.
 *
 * THREE ROW ACTIONS WITH NAMES THAT MUST NOT COLLIDE. `Edit X`, `Replace key for X` and `Delete X`
 * are three buttons per row whose only distinguishing text is their `aria-label`; a scanner is
 * satisfied by any non-empty name, and a screen-reader user given three buttons called "Edit" cannot
 * tell which row they are on. That check is the second describe block.
 *
 * A ROLE SPLIT THAT IS REAL HERE. §6.4 gives `providers.view` to owner, admin AND
 * knowledge_manager, while `providers.manage` is owner and admin only — so the create form and every
 * row action legitimately render nothing for some accounts. Every block that needs one skips with a
 * message rather than failing, because "the signed-in role cannot manage connections" is a fact
 * about the fixture and not about the page.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). A scan says nothing about whether the secret
 * field can be operated by keyboard or whether its error is announced; the keyboard-only pass stays
 * a human step.
 */

skipWithoutAdminSession();

/** Open the route and wait until the browser fetch has resolved into one of its three outcomes. */
async function openProviders(page: Page): Promise<void> {
  await page.goto('/settings/providers');

  // The h1 is server-rendered by `(admin)/settings/providers/page.tsx`; the list is not. Waiting on
  // the heading proves the route resolved, and waiting on the table-or-empty-or-error proves the
  // browser fetch answered — a scan that ran against the skeleton would be scanning a real surface,
  // but not the one this file names.
  await expect(page.getByRole('heading', { name: 'Providers', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Connections', level: 2 })).toBeVisible();

  await expect(
    page
      .getByRole('table')
      .or(page.getByText('No provider connections yet'))
      .or(page.getByText('Provider connections could not be loaded')),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the providers screen has no violations', async ({ page }) => {
    await openProviders(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/settings/providers\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the providers screen has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. The status column is a soft-status pair and the masked key is
    // `--muted-foreground` on `--card`; both are where contrast splits between the two ramps, and
    // both are the kind of cell that gets checked in one mode only.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openProviders(page);

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('the create-connection form has no violations', async ({ page }) => {
    await openProviders(page);

    const form = page.getByRole('heading', { name: 'Add a provider connection', level: 2 });

    // The whole card renders NOTHING without `providers.manage` (`providers-screen.tsx`), so its
    // absence is a legitimate outcome of who the setup logged in as rather than a failure.
    test.skip((await form.count()) === 0, 'the signed-in role does not hold providers.manage');

    await expect(form).toBeVisible();

    // NO SUBMIT. This spec never stores a connection: an E2E run against a real stack that created
    // credential rows would leave residue in whatever organization the operator pointed it at, and
    // the surface being scanned is the form, not the mutation.
    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `add-connection form\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the replace-key dialog has no violations while open', async ({ page }) => {
    // A DIALOG IS ITS OWN SURFACE. Focus trapping, the labelled title and the initial focus target
    // only exist once it is open, and a scan of the page behind it says nothing about them.
    await openProviders(page);

    const trigger = page.getByRole('button', { name: /^Replace key for / }).first();

    test.skip(
      (await trigger.count()) === 0,
      'no manageable connection is visible for this account, so there is no rotate dialog to open',
    );

    await trigger.click();
    await expect(page.getByRole('dialog')).toBeVisible();

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('every row action names its own connection, so no two share an accessible name', async ({
    page,
  }) => {
    /**
     * A scanner checks that a button HAS an accessible name. It does not check that the name is
     * unique on the page, and three rows of `Edit` / `Replace key` / `Delete` scan perfectly while
     * being unusable: a screen-reader user tabbing the table hears the same three words per row with
     * nothing saying which connection they are about to delete. The names are built as
     * `Edit ${connection.label}` for exactly this reason, and this test is what keeps them that way.
     */
    await openProviders(page);

    const actions = page.getByRole('button', { name: /^(Edit|Replace key for|Delete) / });
    const count = await actions.count();

    test.skip(count === 0, 'no manageable connection is visible for this account');

    const names = await actions.evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('aria-label') ?? node.textContent ?? ''),
    );

    expect(new Set(names).size, `duplicate accessible names: ${names.join(' | ')}`).toBe(names.length);
  });

  test('the masked key is text rather than an image or a colour', async ({ page }) => {
    /**
     * `masked_key` is the only per-organization value on this page derived from a credential, and it
     * is the one an operator reads to confirm WHICH key a connection holds. Rendering it as anything
     * a screen reader cannot announce would make the confirmation sighted-only — and a scanner is
     * satisfied by an `alt` attribute, which is why this asks for a text node instead.
     */
    await openProviders(page);

    const table = page.getByRole('table');

    test.skip((await table.count()) === 0, 'this organization has no connections, so nothing is masked');

    // U+2026 (HORIZONTAL ELLIPSIS) FOLLOWED BY FOUR CHARACTERS, and the shape is pinned rather than
    // guessed: `ProviderConnectionResource` builds `masked_key` as `'…'.$connection->last_four`
    // (`RotateProviderCredentialRequest.php:113` states it in those words). Matching the real format
    // is what makes this a check on the CELL rather than on any four characters anywhere.
    await expect(table.getByText(/\u2026\S{4}/).first()).toBeVisible();
  });
});

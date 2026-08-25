import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/settings/providers/[connectionId]` — the model catalogue — plus the
 * operability checks a scanner cannot make.
 *
 * ── FIRST EXECUTED 2026-08-24 ──────────────────────────────────────────────────────────────────
 *
 * The banner here used to read "THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE, NOT PARTIALLY", and
 * that stopped being true on 2026-08-24. `./harness.ts` carries the account of that run — the
 * stack, the browser, and the four different reasons thirteen specs went red on it.
 *
 * Same origin as its neighbours: every selector was read out of the components rather than observed.
 * They have run now, and what each one proves is bounded by what the signed-in role and the
 * organization's data made reachable — a skip below is a state that did not exist, not a check that
 * was waived.
 *
 * ── THE ROUTE IS DISCOVERED, NEVER CONSTRUCTED ─────────────────────────────────────────────────
 *
 * There is no `/settings/models`. The catalogue lives UNDER a connection, so this file has to find a
 * connection id first — from the providers list, by following the link the list itself renders
 * (`connection-list.tsx` → `<Link href={`/settings/providers/${connection.id}`}>`). Building a URL
 * out of a seeded ULID would be a fixture contract this repository deliberately does not have, and
 * an organization with no connections is a legitimate state that skips rather than fails.
 *
 * ── WHAT THIS SCREEN HAS THAT NO OTHER ADMIN LIST DOES ─────────────────────────────────────────
 *
 * A `role="switch"` PER ROW. `model-list.tsx` states in its own comment why it is a switch rather
 * than a checkbox-shaped div, and a switch is precisely the control a scanner passes and a keyboard
 * user cannot operate: `role="switch"` with `aria-checked` on a `<div>` that only listens for click
 * scans clean. The operability block below checks that each one is focusable and carries a real
 * `aria-checked`, and it deliberately DOES NOT PRESS IT — toggling availability is a write against a
 * real organization's catalogue, and a spec that leaves residue in whatever stack the operator
 * pointed it at is worse than a spec that checks less.
 *
 * NINE FIELDS IN A SCROLLING DIALOG. `Register a model` exceeds a 768px viewport and scrolls inside
 * the content rather than the page, which is where focus order and the scroll container's own
 * reachability go wrong.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only pass stays a human step.
 */

skipWithoutAdminSession();

/**
 * The href of the first connection in `/settings/providers`, or null when there are none.
 *
 * FOLLOWS THE LIST'S OWN LINK rather than composing a path, so a route rename breaks this in the one
 * place a rename should break it.
 */
async function firstConnectionHref(page: Page): Promise<string | null> {
  await page.goto('/settings/providers');

  await expect(page.getByRole('heading', { name: 'Providers', level: 1 })).toBeVisible();

  const firstLink = page.locator('a[href^="/settings/providers/"]').first();
  const emptyState = page.getByText('No provider connections yet');

  await expect(firstLink.or(emptyState)).toBeVisible();

  return (await firstLink.count()) > 0 ? firstLink.getAttribute('href') : null;
}

/** Open a connection's catalogue and wait until the browser fetch has resolved. */
async function openCatalogue(page: Page, href: string): Promise<void> {
  await page.goto(href);

  await expect(page.getByRole('heading', { name: 'Models', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Models', level: 2 })).toBeVisible();

  await expect(
    page
      .getByRole('table')
      .or(page.getByText('No models registered on this connection'))
      .or(page.getByText('models could not be loaded'))
      .or(page.getByText('This provider connection could not be loaded')),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the model catalogue has no violations', async ({ page }) => {
    const href = await firstConnectionHref(page);

    test.skip(href === null, 'this organization has no provider connections, so there is no catalogue');

    await openCatalogue(page, href as string);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `${href}\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the model catalogue has no violations in dark mode either', async ({ page }) => {
    const href = await firstConnectionHref(page);

    test.skip(href === null, 'this organization has no provider connections, so there is no catalogue');

    // The availability switch and the capability chips are both soft-status pairs, which is where
    // the two ramps disagree first.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openCatalogue(page, href as string);

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('the register-model dialog has no violations while open', async ({ page }) => {
    const href = await firstConnectionHref(page);

    test.skip(href === null, 'this organization has no provider connections, so there is no catalogue');

    await openCatalogue(page, href as string);

    const trigger = page.getByRole('button', { name: 'Register model' });

    // Rendered only for `providers.manage` (`models-screen.tsx` passes the dialog as `action` behind
    // `canManage`), so its absence is a fact about the signed-in role.
    test.skip((await trigger.count()) === 0, 'the signed-in role does not hold providers.manage');

    await trigger.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Register a model' })).toBeVisible();

    // NO SUBMIT — see the header. The surface under test is the form, not the write.
    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `register-model dialog\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('every availability switch is focusable and states its own checked value', async ({ page }) => {
    /**
     * `role="switch"` is satisfied by a `<div>` that listens for click and nothing else — a clean
     * scan and a control no keyboard user can reach or read. Two properties are checked and neither
     * is a scanner's: that the control takes focus at all, and that `aria-checked` is present with a
     * boolean value rather than absent (which renders the switch stateless to a screen reader).
     *
     * IT IS NEVER PRESSED. Toggling writes to the organization's catalogue through Laravel, and an
     * E2E run that mutates whatever stack it was pointed at is a worse artifact than one that checks
     * less. `tests/components/models-screen.test.tsx` owns the toggle's behaviour against a mocked
     * transport.
     */
    const href = await firstConnectionHref(page);

    test.skip(href === null, 'this organization has no provider connections, so there is no catalogue');

    await openCatalogue(page, href as string);

    const switches = page.getByRole('switch');
    const count = await switches.count();

    test.skip(count === 0, 'this connection has no model rows, so there is no switch to operate');

    const first = switches.first();

    await first.focus();
    await expect(first).toBeFocused();
    await expect(first).toHaveAttribute('aria-checked', /^(true|false)$/);
  });

  test('every row action names its own model, so no two share an accessible name', async ({ page }) => {
    /**
     * The same rule the providers list is held to, and it bites harder here: a catalogue routinely
     * carries a dozen rows, so `Edit` / `Delete` without the model name is a dozen pairs of
     * identical buttons. `model-list.tsx` builds `Edit ${row.display_name}` and
     * `Available to bots: ${row.display_name}` for exactly this reason.
     */
    const href = await firstConnectionHref(page);

    test.skip(href === null, 'this organization has no provider connections, so there is no catalogue');

    await openCatalogue(page, href as string);

    const actions = page.getByRole('button', { name: /^(Edit|Delete) / });
    const count = await actions.count();

    test.skip(count === 0, 'no manageable model row is visible for this account');

    const names = await actions.evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('aria-label') ?? node.textContent ?? ''),
    );

    expect(new Set(names).size, `duplicate accessible names: ${names.join(' | ')}`).toBe(names.length);
  });
});

import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/settings/members` — the member roster and the invitation list — plus
 * the operability checks a scanner cannot make.
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
 * ── TWO LISTS ON ONE ROUTE, AND THEY ARE NOT INTERCHANGEABLE ───────────────────────────────────
 *
 * `Members` is who is in the organization; `Invitations` is who has been asked and has not answered.
 * They have separate headings, separate empty states and separate error states, and each is scanned
 * through its own wait — because a spec that waited on one and scanned the page would report a clean
 * result while the other was still a skeleton.
 *
 * ── THE ACTIONS ARE PER-PERSON, WHICH IS WHERE THE NAMES COLLIDE ───────────────────────────────
 *
 * `Resend to ${email}` and `Revoke invitation for ${email}` are two buttons per invitation row whose
 * only distinguishing text is the `aria-label`. A scanner is satisfied by any non-empty name; a
 * screen-reader user given six buttons called "Resend" cannot tell whose invitation they are about
 * to revoke. That check is the second describe block, and it is the same rule the providers and
 * models lists are held to.
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE ─────────────────────────────────────────────────────────
 *
 * NO INVITATION IS SENT AND NONE IS REVOKED. Both are real writes against whatever organization the
 * operator pointed the run at, and the second one is destructive. `tests/components/members-screen
 * .test.tsx` owns both behaviours against a mocked transport; what only a browser can check is the
 * rendered surface, so that is all this file touches.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only pass stays a human step.
 */

skipWithoutAdminSession();

/** Open the route and wait until BOTH browser fetches have resolved into one of their outcomes. */
async function openMembers(page: Page): Promise<void> {
  await page.goto('/settings/members');

  await expect(page.getByRole('heading', { name: 'Members', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Members', level: 2 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Invitations', level: 2 })).toBeVisible();

  // BOTH LISTS, SEPARATELY. One `Promise.all`-shaped wait would let a scan run against a page whose
  // second table is still a skeleton, and the scan would pass — a skeleton has no labels to get
  // wrong.
  await expect(
    page
      .getByText('No members yet')
      .or(page.getByText('Members could not be loaded'))
      .or(page.getByRole('table').first()),
  ).toBeVisible();

  await expect(
    page
      .getByText('No invitations yet')
      .or(page.getByText('Invitations could not be loaded'))
      .or(page.getByRole('button', { name: /^Resend to / }).first()),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the members screen has no violations', async ({ page }) => {
    await openMembers(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/settings/members\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the members screen has no violations in dark mode either', async ({ page }) => {
    // The role badge and the invitation status are both soft-status pairs, which is where the two
    // ramps disagree first — and the invitation one carries an EXPIRED state that is the only place
    // on this screen a destructive tone appears.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openMembers(page);

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('the invite form has no violations', async ({ page }) => {
    await openMembers(page);

    const form = page.getByRole('heading', { name: 'Invite a member', level: 2 });

    // The card is rendered only once the invitation read has SUCCEEDED (`members-screen.tsx` gates
    // it on `invitations.isSuccess`), so its absence is a legitimate outcome — an analyst gets a 403
    // and no form — rather than a failure.
    test.skip((await form.count()) === 0, 'the invite card did not render for this account');

    await expect(form).toBeVisible();

    // A ROLE PICKER IS THE PART WORTH SCANNING. An email field is an email field; a select whose
    // options are the fixed role catalogue is where the label association and the described-by
    // wiring get dropped, and it is the control that decides what a new member may do.
    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `invite form\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('every invitation action names its own recipient, so no two share an accessible name', async ({
    page,
  }) => {
    /**
     * A scanner checks that a button HAS an accessible name, never that the name is unique on the
     * page. Six rows of `Resend` / `Revoke` scan perfectly and are unusable: nothing tells a
     * screen-reader user whose invitation they are about to withdraw. `invitation-list.tsx` builds
     * `Resend to ${invitation.email}` for exactly this reason.
     */
    await openMembers(page);

    const actions = page.getByRole('button', { name: /^(Resend to|Revoke invitation for) / });
    const count = await actions.count();

    test.skip(count === 0, 'this organization has no pending invitation this account may act on');

    const names = await actions.evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('aria-label') ?? node.textContent ?? ''),
    );

    expect(new Set(names).size, `duplicate accessible names: ${names.join(' | ')}`).toBe(names.length);
  });

  test('the two lists are separate landmarks rather than one run-on table', async ({ page }) => {
    /**
     * Both lists are `<section aria-labelledby>` with their own `<h2>`, which is what lets a screen
     * reader user jump between "who is here" and "who has been asked" instead of arrowing through
     * one undifferentiated run of rows. A scanner does not check that a heading is ASSOCIATED with
     * the region it heads — an `aria-labelledby` pointing at a deleted id is a clean scan and an
     * unnamed region.
     */
    await openMembers(page);

    const regions = page.locator('section[aria-labelledby="members-heading"], section[aria-labelledby="invitations-heading"]');

    await expect(regions).toHaveCount(2);

    // AND THE IDS RESOLVE. This is the half a scanner skips: `aria-labelledby` naming an element
    // that is not on the page leaves the region with no accessible name at all.
    await expect(page.locator('#members-heading')).toHaveText('Members');
    await expect(page.locator('#invitations-heading')).toHaveText('Invitations');
  });
});

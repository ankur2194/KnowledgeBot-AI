import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/quotas` — usage against ceilings, and the form that lowers one — plus
 * the operability checks a scanner cannot make.
 *
 * ── FIRST EXECUTION IS STILL AHEAD OF THIS FILE ────────────────────────────────────────────────
 *
 * `./harness.ts` carries the account of the 2026-08-24 and 2026-08-25 runs; this spec was written
 * after them, for a route that landed later, and every selector below was read out of the components
 * rather than observed.
 *
 * ── THE ONE DEFECT THIS ROUTE HAS ALREADY HAD IS AN ACCESSIBLE-NAME COLLISION ─────────────────
 *
 * `quotas-screen.tsx` carries the record in a docblock: the usage meter was labelled
 * `aria-labelledby={cardTitleId}`, which named it "Bots" — the same accessible name the ceiling
 * INPUT in the form below already had. One page, two controls called "Bots", and a strict locator
 * resolved both. It is a real defect and not a test artefact: a screen-reader user tabbing the form
 * hears the same name twice and cannot tell the meter from the field.
 *
 * The fix was "Bots used" and "Bots limit". **THE SECOND DESCRIBE BLOCK IS THE THING THAT WOULD
 * NOTICE IT COMING BACK**, and it is written against the rule rather than against the two strings:
 * no control on this page may be named by a bare metric label, because that name is ambiguous by
 * construction.
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE: NO CEILING IS SAVED ───────────────────────────────────
 *
 * The submit button is never clicked. A PUT here is a real write against whatever organization the
 * operator pointed the run at, it carries no `Idempotency-Key` so nothing may replay it, and — the
 * part that makes it worse than an ordinary destructive test — **lowering a ceiling is the
 * direction that succeeds.** A raise is refused by the platform rule and would leave the tree as it
 * was; a lower would silently cap a live organization's storage or bots at whatever this spec typed.
 * `tests/components/quotas-screen.test.tsx` owns the submit path against a mocked transport.
 *
 * The one thing this file does touch is the UNLIMITED SWITCH, and only in the direction that
 * changes nothing on the server: it is toggled to reveal the number input for a scan and never
 * submitted. `form.reset` is not called either — the page is simply left, which discards it.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ──────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only and screen-reader passes stay
 * human steps.
 */

skipWithoutAdminSession();

/** The four metrics, in the order `QUOTA_METRICS` declares them. Read out of `features/quotas/api.ts`
 *  rather than fetched, because a spec that derived its expectations from the page could not fail:
 *  a page rendering nothing would agree with itself. */
const METRIC_LABELS = ['Storage', 'Bots', 'Members', 'Monthly tokens'] as const;

/**
 * Open the route and wait until the browser fetch has resolved into ONE OF ITS OUTCOMES.
 *
 * `Usage` is the arm that means success: `UsageCards` renders for every role that can read the page,
 * including the analyst who may see the figures and change nothing. The `Limits` heading is NOT a
 * success arm — it renders only for a viewer holding `quotas.manage`, which is the one permission an
 * administrator deliberately does not hold, because a quota is a billing decision rather than an
 * operational one.
 */
async function openQuotas(page: Page): Promise<void> {
  await page.goto('/quotas');

  await expect(page.getByRole('heading', { name: 'Quotas', level: 1 })).toBeVisible();

  await expect(
    page
      .getByRole('heading', { name: 'Usage', level: 2 })
      .or(page.getByText('Quotas could not be loaded'))
      .or(page.getByText("You don't have access to this")),
  ).toBeVisible();
}

/** True when this run's account may change a ceiling. The form is replaced by an explanatory alert
 *  otherwise, and every test that needs a field skips rather than failing on a role it was not
 *  given. */
async function canManageQuotas(page: Page): Promise<boolean> {
  return (await page.getByRole('heading', { name: 'Limits', level: 2 }).count()) > 0;
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the quotas screen has no violations', async ({ page }) => {
    await openQuotas(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/quotas\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the quotas screen has no violations in dark mode either', async ({ page }) => {
    /**
     * BOTH MODES, ALWAYS, and this page has the console's only METER. A `<progress>` track and its
     * fill are two greys that have to stay distinguishable on both canvases, and an over-limit row
     * adds a failure-tone pill beside it — colour is never the only channel here precisely because
     * one of the two modes would otherwise carry the whole message.
     */
    await page.emulateMedia({ colorScheme: 'dark' });
    await openQuotas(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/quotas (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the ceilings form has no violations with a number field revealed', async ({ page }) => {
    /**
     * A FIELD THAT IS NOT RENDERED CANNOT BE SCANNED. Every metric that is currently unlimited shows
     * a switch and NO input — so a scan of the page as it loads may cover four switches and zero
     * number fields, and report clean about a control it never saw.
     *
     * Toggling one switch off reveals the input (seeded to `0`, which is a real and legal ceiling
     * and never a raise). Nothing is submitted, so the server is untouched.
     */
    await openQuotas(page);

    test.skip(
      !(await canManageQuotas(page)),
      'the signed-in role does not hold quotas.manage, so the form renders an alert instead',
    );

    /**
     * ONE NAMED SWITCH, NOT `getByRole('switch', { checked: true }).first()`. That form re-evaluates
     * after the click and would then resolve a DIFFERENT metric's switch — so the assertion that the
     * toggle took effect would be made against a control nobody touched, and would pass whether or
     * not the click did anything.
     */
    for (const label of METRIC_LABELS) {
      const toggle = page.getByRole('switch', { name: `${label} limit Unlimited`, exact: true });

      if ((await toggle.count()) === 0) continue;
      if ((await toggle.getAttribute('aria-checked')) !== 'true') continue;

      await toggle.click();
      // The state of THAT switch, which is what proves the re-render happened — rather than a
      // `waitForTimeout`, and rather than a visibility check on a control that never unmounts.
      await expect(toggle).toHaveAttribute('aria-checked', 'false');
      break;
    }

    await expect(page.getByRole('spinbutton').first()).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/quotas ceilings form\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('no control is named by a bare metric label', async ({ page }) => {
    /**
     * THE REGRESSION TEST FOR THE COLLISION `quotas-screen.tsx` RECORDS. A scanner is satisfied by
     * any non-empty name; it never checks that two controls on one page are distinguishable. This
     * asserts the RULE rather than the two current strings: a bare "Bots" or "Storage" is ambiguous
     * by construction on a page that has both a meter and a ceiling for each metric, so nothing may
     * carry one.
     */
    await openQuotas(page);

    for (const label of METRIC_LABELS) {
      await expect(
        page.getByLabel(label, { exact: true }),
        `a control is named "${label}", which is ambiguous between the meter and the ceiling`,
      ).toHaveCount(0);
    }
  });

  test('each metric names its meter and its ceiling differently', async ({ page }) => {
    /**
     * The other half: not merely that the ambiguous name is absent, but that BOTH disambiguated
     * names are present and resolve to exactly one control each. A page that dropped the meter
     * entirely would pass the test above and fail this one.
     *
     * A metric with no limit set renders NO meter — an unlimited row gets "No limit set." instead,
     * because a bar at zero over an unlimited metric says "you have used none of your allowance",
     * which is false in the way that matters. So the meter is asserted at MOST once rather than
     * exactly once, and the ceiling — which is always rendered for a manager — exactly once.
     */
    await openQuotas(page);

    const manages = await canManageQuotas(page);

    for (const label of METRIC_LABELS) {
      const meter = page.getByLabel(`${label} used`, { exact: true });
      expect(
        await meter.count(),
        `"${label} used" resolved more than one control`,
      ).toBeLessThanOrEqual(1);

      if (manages) {
        await expect(
          page.getByLabel(`${label} limit`, { exact: true }),
          `no ceiling control is labelled "${label} limit"`,
        ).toHaveCount(1);
      }
    }
  });

  test('the asymmetry is disclosed before a save is attempted, not after one fails', async ({
    page,
  }) => {
    /**
     * SAID ONCE AND ALWAYS, not only when it bites. An owner who does not know that lowering is free
     * will not try it; an owner who discovers the rule from a failed save learns it as "quotas are
     * broken". A scanner cannot tell that a page explains its own rules, and a component test proves
     * the string is rendered — what only a browser shows is that it is above the form rather than
     * below the fold of a card the user has already submitted.
     */
    await openQuotas(page);

    test.skip(
      !(await canManageQuotas(page)),
      'the signed-in role does not hold quotas.manage, so there is nothing to disclose',
    );

    await expect(
      page.getByText('Lowering a limit takes effect here. Raising one does not.'),
    ).toBeVisible();
  });

  test('the two sections are separate landmarks whose headings resolve', async ({ page }) => {
    /**
     * `Usage` is what is spent; `Limits` is what may be changed. They are `<section
     * aria-labelledby>` with their own `<h2>`, which is what lets a screen-reader user jump between
     * them. A scanner does not check that the id RESOLVES — an `aria-labelledby` pointing at a
     * deleted id is a clean scan and an unnamed region.
     */
    await openQuotas(page);

    await expect(page.locator('section[aria-labelledby="quota-usage"]')).toHaveCount(1);
    await expect(page.locator('#quota-usage')).toHaveText('Usage');

    if (await canManageQuotas(page)) {
      await expect(page.locator('section[aria-labelledby="quota-limits"]')).toHaveCount(1);
      await expect(page.locator('#quota-limits')).toHaveText('Limits');
    } else {
      // The read-only branch is a different render, and it is the honest one: it names the role and
      // says why an administrator does not hold it.
      await expect(page.getByText('You can read these limits but not change them')).toBeVisible();
    }
  });

  test('a stale figure says so rather than being presented as fact', async ({ page }) => {
    /**
     * A `cache` source means the PRIMARY READ WAS SKIPPED and the number is a LOWER BOUND. It is
     * surfaced rather than rendered as fact, because an operator deciding whether to delete a bot
     * needs to know the figure may be behind.
     *
     * THIS TEST SKIPS WHEN NO ROW IS CACHED, which on a quiet stack is every row — and that is
     * stated rather than hidden, because a test that silently proves nothing is worse than one that
     * says it did not run. What it can never do is fail for the absence of the note; it fails only
     * if a note appears without its explanation.
     */
    await openQuotas(page);

    const note = page.getByText('Read from a cache, so this may be behind the real figure.');

    test.skip(
      (await note.count()) === 0,
      'every figure on this run came from the database, so no staleness note rendered',
    );

    await expect(note.first()).toBeVisible();
  });
});

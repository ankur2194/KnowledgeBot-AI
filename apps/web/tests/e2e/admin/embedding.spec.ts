import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/settings/embedding` — the designation screen and the readiness
 * verdict — plus the operability checks a scanner cannot make.
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
 * ── WHY THIS SCREEN IS THE ONE WORTH SCANNING MOST CAREFULLY ───────────────────────────────────
 *
 * IT IS A VERDICT SURFACE, NOT A LIST. Almost everything on it is a STATE rather than a row: a pill
 * that says whether the organization can embed at all, a destructive banner when it cannot, the
 * resolved pair, the pairs that were eligible, and the pairs that were refused with their reasons.
 * Every one of those is a place where meaning gets carried by colour and nothing else — which is the
 * failure mode a scanner is worst at, because a red pill with no word in it has perfect contrast.
 *
 * THE VERDICT HAS THREE CHANNELS BY DESIGN — glyph, colour AND word — and the second describe block
 * is what keeps the third one there. `readiness-panel.tsx` renders `Can embed` or `Ingestion
 * blocked`; a change that reduced the pill to a coloured dot would scan clean and would make the
 * single most consequential fact on the screen invisible to a screen reader and to anyone who cannot
 * separate the two hues.
 *
 * FOUR STATES, AND THE SPEC MUST NOT ASSUME WHICH ONE. A stack may legitimately be ready, blocked,
 * examined-nothing, or unable to load the verdict at all (an analyst gets a 403 here). Every wait
 * below admits the whole set; a spec that waited on `Can embed` would hang for six organizations out
 * of seven and then be "fixed" by loosening the scan.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ───────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only pass stays a human step.
 */

skipWithoutAdminSession();

/** Open the route and wait until the readiness read has resolved into one of its outcomes. */
async function openEmbedding(page: Page): Promise<void> {
  await page.goto('/settings/embedding');

  await expect(page.getByRole('heading', { name: 'Embedding', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Readiness', level: 2 })).toBeVisible();

  // EVERY OUTCOME, INCLUDING THE 403. `readiness-panel.tsx` renders the pill only once the verdict
  // is known, the first-run empty when nothing was examined, and a class-mapped ErrorState when the
  // read failed — so this is the full set and not a convenient subset.
  //
  // `.first()`, AND IT IS NOT DEFENSIVE PADDING. The four are not mutually exclusive and the first
  // real run proved it: an organization with no provider connections renders the `Ingestion blocked`
  // pill AND the `No candidates were examined` empty state together, because the pill states the
  // VERDICT and the empty state describes the CANDIDATE LIST — two different facts about the same
  // screen. Without `.first()` the chain is a strict-mode violation and every test in this file dies
  // in the helper, which reads as "the screen never loaded" and is the opposite of what happened.
  await expect(
    page
      .getByText('Can embed')
      .or(page.getByText('Ingestion blocked'))
      .or(page.getByText('No candidates were examined'))
      .or(page.getByText('Embedding readiness could not be loaded'))
      .first(),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the embedding screen has no violations', async ({ page }) => {
    await openEmbedding(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/settings/embedding\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the embedding screen has no violations in dark mode either', async ({ page }) => {
    // THE ONE SCREEN WHERE THIS MATTERS MOST. A destructive banner, a success pill and a failure
    // pill are three tone families on one page, and the destructive pair is the one that most often
    // passes in light and fails in dark.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openEmbedding(page);

    const results = await scan(page).analyze();
    expect(summarize(results.violations)).toEqual([]);
  });

  test('the designation form has no violations', async ({ page }) => {
    await openEmbedding(page);

    const form = page.getByRole('heading', { name: 'Designate the embedding pair', level: 2 });

    // The card renders only for a role that may designate; an analyst reads the verdict and no form.
    test.skip((await form.count()) === 0, 'the signed-in role cannot designate an embedding pair');

    await expect(form).toBeVisible();

    // NO SUBMIT. Saving a designation changes which credential pays for embedding and which vector
    // space the organization's documents are indexed under — the single most expensive write in the
    // console to make by accident. The surface under test is the form.
    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `designation form\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the readiness verdict is carried by a word, not only by a colour', async ({ page }) => {
    /**
     * THE THIRD CHANNEL. A `<StatusPill>` reduced to a coloured dot scans perfectly — contrast is
     * computed on what is there, and nothing in WCAG's automatable set notices that the only
     * remaining channel is hue. This asserts the WORD, which is what a screen reader announces and
     * what someone who cannot separate the two hues reads.
     */
    await openEmbedding(page);

    const verdict = page.getByText('Can embed').or(page.getByText('Ingestion blocked'));

    test.skip(
      (await verdict.count()) === 0,
      'no verdict was rendered — this organization examined no candidates, or the read failed',
    );

    await expect(verdict.first()).toBeVisible();
  });

  test('a blocked verdict announces itself rather than waiting to be found', async ({ page }) => {
    /**
     * `blocks_ingestion` renders a destructive `<Alert>`, and the primitive carries `role="alert"` —
     * a live region, so a screen reader is told when it APPEARS rather than only when somebody
     * navigates to it. That distinction is invisible to a scanner: an ordinary `<div>` with the same
     * text and the same contrast is a clean scan and a silent failure for the one message on this
     * screen that stops every upload in the organization.
     */
    await openEmbedding(page);

    const banner = page.getByText('No document can be ingested right now');

    test.skip((await banner.count()) === 0, 'this organization is not blocked, so there is no banner');

    await expect(page.getByRole('alert').filter({ hasText: 'No document can be ingested right now' }))
      .toBeVisible();
  });
});

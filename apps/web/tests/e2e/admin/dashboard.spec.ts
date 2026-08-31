import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/` — the admin overview — plus the operability checks a scanner cannot
 * make.
 *
 * ── FIRST EXECUTION IS STILL AHEAD OF THIS FILE ────────────────────────────────────────────────
 *
 * `./harness.ts` carries the account of the 2026-08-24 and 2026-08-25 runs; this spec was written
 * after them, for a surface that replaced a three-tile placeholder later, and every selector below
 * was read out of the components rather than observed.
 *
 * ── THE HARNESS ORGANIZATION MAKES THIS A ZERO DASHBOARD, AND THAT IS THE INTERESTING CASE ────
 *
 * Nothing on the stack these specs run against has ever asked a bot a question, so
 * `conversations === 0 && messages === 0` and the page renders its QUIET state: the "Nothing was
 * asked in this period" alert **beside real zeroes**, not instead of them. That distinction is the
 * whole design of this screen — a zero here is a measurement and not a missing number — so a run
 * against an empty organization tests the branch that is easiest to get wrong, rather than testing
 * nothing.
 *
 * What it does NOT cover: the `usage_by_model` table's rows, the three proportion bars with actual
 * segments, and every delta-free tile carrying a non-zero figure. Those need a populated
 * organization and are named here rather than left to be inferred from a green run.
 *
 * ── THE TILE CAPTIONS COLLIDE WITH THE SIDEBAR, WHICH IS WHY EVERY LOCATOR IS SCOPED TO `main` ─
 *
 * "Conversations" is a tile caption AND a navigation item on every admin route. An unscoped
 * `getByText('Conversations')` resolves both, and the failure mode is the bad one: it passes today
 * because the nav is always there, and would keep passing on the day the tile is deleted. Every
 * assertion below runs inside `page.getByRole('main')`.
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE ────────────────────────────────────────────────────────
 *
 * NO NUMBER IS ASSERTED. Every figure is seed-dependent, and a spec that pinned one would be
 * testing the fixture. What is asserted is that each tile is PRESENT and that the page labels the
 * window it is reporting — the two properties that survive any data state.
 *
 * NO WINDOW IS CHANGED, because there is no control that changes one: this endpoint publishes a
 * single server-defaulted window and the screen labels itself from the echo. A date filter would
 * change the query key shape, and the empty params object in `dashboard-screen.tsx` is there so
 * that the day it lands, a stale entry cannot answer a narrower question.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ──────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only and screen-reader passes stay
 * human steps.
 */

skipWithoutAdminSession();

/**
 * The eight tiles, in the order `TILES` declares them.
 *
 * READ OUT OF THE COMPONENT rather than derived from the page: a spec that collected the captions it
 * found and asserted they were the captions it found could not fail — a page rendering nothing would
 * agree with itself.
 */
const TILE_CAPTIONS = [
  'Conversations',
  'Participants',
  'Median answer time',
  '95th pct first token',
  'Provider error rate',
  'Fallback rate',
  'Answered from no evidence',
  'Storage used',
] as const;

/**
 * Open the overview and wait until the browser fetch has resolved into ONE OF ITS OUTCOMES.
 *
 * `Quality and reliability` is the success arm and it is unconditional — unlike the tiles, whose
 * skeleton renders the same grid at the same height, so waiting on a tile would not distinguish
 * loaded from loading. That is the point of the skeleton mirroring the loaded row box for box, and
 * it is exactly why it cannot be the wait condition.
 */
async function openOverview(page: Page): Promise<void> {
  await page.goto('/');

  await expect(page.getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible();

  await expect(
    page
      .getByRole('heading', { name: 'Quality and reliability', level: 2 })
      .or(page.getByText('This dashboard could not be loaded'))
      .or(page.getByText("You don't have access to this"))
      .or(page.getByText('These numbers belong to an organization')),
  ).toBeVisible();
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the overview has no violations', async ({ page }) => {
    await openOverview(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the overview has no violations in dark mode either', async ({ page }) => {
    /**
     * BOTH MODES, ALWAYS, and this page is the console's densest colour surface: eight tiles whose
     * glyphs sit at `--muted-foreground`, three proportion bars built from tone swatches, and an
     * info alert — plus the one thing a light-only check would never see, which is that a bar
     * segment's swatch has to stay distinguishable from the track on the dark canvas.
     */
    await page.emulateMedia({ colorScheme: 'dark' });
    await openOverview(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/ (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('every tile is on the page, so a deleted metric is a failure rather than a smaller grid', async ({
    page,
  }) => {
    /**
     * A KPI row that quietly lost a tile looks fine. Nothing in a scan, a screenshot diff or a type
     * check objects to seven tiles where there were eight, and the reader has no way to know a
     * number stopped being reported — which is worse than a number that is wrong, because a wrong
     * number gets questioned.
     */
    await openOverview(page);

    const main = page.getByRole('main');

    for (const caption of TILE_CAPTIONS) {
      await expect(
        main.getByText(caption, { exact: true }),
        `the "${caption}" tile is not on the overview`,
      ).toHaveCount(1);
    }
  });

  test('the page says which window it is reporting', async ({ page }) => {
    /**
     * THE RESOLVED WINDOW, ECHOED BY THE SERVER — never what the client asked for. `from` and
     * `until` both have server-side defaults, so labelling the page from the request would put a
     * date range on screen that the numbers may not be for. A dashboard with no window is a set of
     * figures nobody can act on: "12 conversations" is not a fact until it says over what.
     *
     * The dates themselves are not asserted — they move with the clock. What is asserted is the
     * bot-scope half of the same line, which is a closed vocabulary of two.
     */
    await openOverview(page);

    await expect(page.getByRole('main').getByText(/· (all bots|one bot)$/)).toBeVisible();
  });

  test('a quiet period is stated rather than left to look like a broken page', async ({ page }) => {
    /**
     * AN EMPTY WINDOW IS A REAL STATE AND NOT A FIRST-RUN ONE. Eight zeroes with no explanation read
     * as a dashboard that failed to load; the same eight zeroes with a sentence beside them read as
     * a quiet week. `dashboard-screen.tsx` gets this right by putting the alert BESIDE the numbers
     * rather than instead of them, and this test asserts both halves — the alert AND a tile still
     * on screen — because replacing the grid with the alert would pass a test that checked only the
     * sentence.
     */
    await openOverview(page);

    const quiet = page.getByText('Nothing was asked in this period');

    test.skip(
      (await quiet.count()) === 0,
      'this organization held conversations in the window, so the quiet state did not render',
    );

    await expect(quiet).toBeVisible();
    await expect(page.getByRole('main').getByText('Conversations', { exact: true })).toBeVisible();
  });

  test('each proportion bar carries a real name rather than an unlabelled graphic', async ({
    page,
  }) => {
    /**
     * `ProportionBar` renders an inline SVG with `role="img"` and an `aria-labelledby` pointing at a
     * `<title>` that spells out every segment — because without it a screen reader announces NOTHING
     * AT ALL for the graphic, and the legend below is the only thing left. A scanner checks that an
     * `img` role has a name; it does not check that the name says what the bar shows.
     *
     * A bar with no data renders NO graphic — it renders its caption and its own empty sentence —
     * so each is asserted as "either the named graphic or the honest sentence", which is the same
     * exclusive pair the list screens' `.or()` chains use.
     */
    await openOverview(page);

    const main = page.getByRole('main');

    /**
     * The pattern is a LITERAL beside its caption rather than built from it. `Provider attempts` and
     * `Source versions in this period` are safe to interpolate today and would stop being safe the
     * moment a caption gains a character a regex reads — and a spec that escaped its own inputs
     * would be doing work the three fixed strings make unnecessary.
     */
    const bars: readonly (readonly [string, RegExp, string])[] = [
      ['Thumbs', /^Thumbs: /, 'Nobody has rated an answer in this period.'],
      ['Provider attempts', /^Provider attempts: /, 'No provider call finished in this period.'],
      [
        'Source versions in this period',
        /^Source versions in this period: /,
        'No source was ingested in this period.',
      ],
    ];

    for (const [caption, named, empty] of bars) {
      await expect(
        // `role="img"` whose name STARTS with the caption: the rest of the name is the segment
        // breakdown, which is seed-dependent and must not be pinned.
        main.getByRole('img', { name: named }).or(main.getByText(empty, { exact: true })),
        `"${caption}" rendered neither a named bar nor its empty sentence`,
      ).toBeVisible();
    }
  });

  test('the usage table names itself and never sums two currencies', async ({ page }) => {
    /**
     * TWO PROPERTIES IN ONE WALK, because they are the same design decision.
     *
     * The table's accessible name comes from a visually hidden `<caption>` — invisible, so nothing
     * but a test notices when it is dropped, and this page has a second tabular-looking thing (the
     * tile grid) that a screen-reader user would otherwise have to tell apart from "table".
     *
     * And there is NO FOOTER TOTAL. `currency` travels with each row's number because summing two
     * currencies is a silent wrong answer — a USD figure added to a EUR one, computed in a browser.
     * A `<tfoot>` appearing here would be that bug, and it is the kind of thing added in good faith
     * by someone who reads a cost column and misses the currency beside it.
     */
    await openOverview(page);

    const table = page.getByRole('table', { name: 'Token usage and estimated cost, by model' });

    if ((await table.count()) === 0) {
      // The honest alternative, not a skip: an empty period renders a sentence in place of the
      // table, and asserting it is what stops this test passing against a page that lost both.
      await expect(page.getByText('No model answered in this period.')).toBeVisible();
      return;
    }

    await expect(table).toBeVisible();
    await expect(table.locator('tfoot'), 'the usage table grew a total row').toHaveCount(0);
  });

  test('the two sections are separate landmarks whose headings resolve', async ({ page }) => {
    /**
     * A scanner does not check that an `aria-labelledby` id RESOLVES — one pointing at a deleted
     * element is a clean scan and an unnamed region. These two are what let a screen-reader user
     * jump past the KPI grid to the part they came for.
     */
    await openOverview(page);

    await expect(page.locator('section[aria-labelledby="dashboard-quality"]')).toHaveCount(1);
    await expect(page.locator('#dashboard-quality')).toHaveText('Quality and reliability');

    await expect(page.locator('section[aria-labelledby="dashboard-usage"]')).toHaveCount(1);
    await expect(page.locator('#dashboard-usage')).toHaveText('Usage by model');
  });
});

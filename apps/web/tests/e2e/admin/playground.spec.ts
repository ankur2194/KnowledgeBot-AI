import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on the **Playground** tab of `/bots/{botId}` — the fourth panel of the bot
 * editor — plus the operability checks a scanner cannot make.
 *
 * ── WHY THIS IS NOT A FIFTH ENTRY IN `bots.spec.ts`'s TAB LOOP ────────────────────────────────
 *
 * That loop walks `Identity & voice`, `Model & retrieval` and `Publishing` and scans each panel,
 * which is right for three forms that differ only in their fields. The playground is not a form: it
 * is a live chat surface with a composer, a stop control, an announcement region and a retrieval
 * panel that only exists after a turn — four things whose accessibility questions have nothing to do
 * with a labelled input. Folding it into that loop would have added one more `page.getByRole('tab')`
 * click and covered none of them.
 *
 * `bots.spec.ts`'s loop names this file so the omission reads as a delegation rather than as drift.
 *
 * ── FIRST EXECUTION IS STILL AHEAD OF THIS FILE ────────────────────────────────────────────────
 *
 * `./harness.ts` carries the account of the 2026-08-24 and 2026-08-25 runs; this spec was written
 * after them, for a tab that landed later, and every selector below was read out of the components
 * rather than observed.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * NO TURN IS EVER TAKEN, AND THAT IS A HARDER RULE HERE THAN ANYWHERE ELSE IN THIS DIRECTORY
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The composer is never filled and `Send message` is never clicked. Three separate reasons, and each
 * one alone would be enough:
 *
 *   IT SPENDS REAL MONEY. `bot-playground-panel.tsx` says it plainly — a playground turn spends this
 *   organization's provider quota, which is why the gate is `bots.manage` rather than a chat
 *   permission: it is a WRITE wearing a chat control. A test suite that bills a tenant per run is a
 *   test suite nobody runs.
 *
 *   IT WRITES A CONVERSATION ROW. Turns taken here are recorded like any other conversation, so a
 *   send would put a `playground` thread into the same table `/conversations` reports and the same
 *   figures `/` aggregates. Two other specs in this directory would then be reading data this one
 *   manufactured.
 *
 *   AND IT WOULD PROVE NOTHING ON THIS STACK. The harness organization has no provider connection,
 *   so a send resolves to a refusal — a real one, correctly rendered, and not the streaming path the
 *   test would claim to be covering.
 *
 * What only a browser can check is the surface BEFORE the turn: that the composer is labelled, that
 * the empty state names the bot, that the retrieval panel says what it will contain, and that a bot
 * which cannot be run says so instead of offering a control that would 409. That is all this file
 * touches. The streaming path is owned by `tests/unit/chat-state.test.ts` and
 * `tests/components/*` against a hostile SSE fixture server, which can drive frames no live provider
 * would produce.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ──────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`), and less than that here: a chat surface's real
 * accessibility question is whether the live region announces a completed answer ONCE, which cannot
 * be observed from a DOM assertion. The keyboard-only and screen-reader passes stay human steps.
 */

skipWithoutAdminSession();

/**
 * Open the first bot's editor and select the Playground tab.
 *
 * Reached FROM THE LIST, like `source-detail.spec.ts` and for the same reason: a bot id is not
 * knowable without a seed contract that does not exist. Returns the editor's href so a failure names
 * the bot it was looking at.
 *
 * NOTHING IS FETCHED BY MOUNTING THIS TAB. The playground's connection is a lazy `useState`
 * initialiser — `connect` mints a session only when a turn is sent — so selecting the tab is inert,
 * which is what makes it safe to scan under the rule above.
 */
async function openPlayground(page: Page): Promise<string> {
  await page.goto('/bots');
  await expect(page.getByRole('heading', { name: 'Bots', level: 1 })).toBeVisible();

  const firstBot = page.locator('a[href^="/bots/"]').first();
  await expect(firstBot.or(page.getByText('No bots yet'))).toBeVisible();

  test.skip(
    (await firstBot.count()) === 0,
    'this organization has no bots, so there is no playground to open',
  );

  const href = (await firstBot.getAttribute('href')) ?? '';
  await firstBot.click();

  const tab = page.getByRole('tab', { name: 'Playground' });
  await expect(tab).toBeVisible();
  await tab.click();
  // `aria-selected`, not visibility: Radix keeps every panel mounted and hides the inactive ones, so
  // a visibility check on the panel would pass before the click.
  await expect(tab).toHaveAttribute('aria-selected', 'true');

  // The saved-configuration card is unconditional — it renders for every role and every bot status —
  // so it is the one honest wait for "the panel is on screen".
  await expect(
    page.getByRole('heading', { name: /^This run uses the bot.s saved settings$/, level: 3 }),
  ).toBeVisible();

  return href;
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the playground tab has no violations', async ({ page }) => {
    const href = await openPlayground(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `${href} — Playground\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the playground tab has no violations in dark mode either', async ({ page }) => {
    /**
     * BOTH MODES, ALWAYS. This panel deliberately gets NO tenant theming — `kb-ai-chat-ux`'s surface
     * table is explicit about it, because a brand-coloured chat panel inside the admin shell is the
     * one place the "console chrome stays neutral" rule breaks visibly — so what is on screen is
     * console tokens only, and both ramps have to carry it.
     */
    await page.emulateMedia({ colorScheme: 'dark' });
    const href = await openPlayground(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `${href} — Playground (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the composer is labelled by something other than its placeholder', async ({ page }) => {
    /**
     * A PLACEHOLDER IS NOT A LABEL: it disappears exactly when the user needs it — while they are
     * typing — and it is not reliably announced. `composer.tsx` carries an `sr-only` `<label
     * for="kb-composer">` for this reason, and the failure mode if it were dropped is invisible in
     * every screenshot and clean in most scanners, because the placeholder gives axe a name to find.
     *
     * The panel is not runnable for every viewer, so this skips when the composer is absent and
     * asserts the honest notice instead — never both, because the two are exclusive by construction.
     */
    const href = await openPlayground(page);

    const composer = page.getByLabel('Your question', { exact: true });

    if ((await composer.count()) === 0) {
      await expect(
        page
          .getByText('You can read this bot but not run it')
          .or(page.getByText(/^This bot cannot be run from the playground while it is /)),
        `${href} rendered neither a composer nor a reason there is none`,
      ).toBeVisible();
      return;
    }

    await expect(composer).toBeVisible();
    // AND THE LABEL IS NOT THE PLACEHOLDER. If the `sr-only` label were deleted and the placeholder
    // left, `getByLabel` would find nothing — but a future `aria-label="Ask a question…"` would
    // satisfy it while reintroducing exactly the problem, so the placeholder is checked separately.
    await expect(composer).toHaveAttribute('placeholder', /\S/);
  });

  test('a bot that cannot be run says so instead of offering a control that would fail', async ({
    page,
  }) => {
    /**
     * `PlaygroundSessionController` refuses `draft`, `paused` and `archived` with a 409, and a
     * deliberate 409 renders as `internal_dependency` — "Something on our side is unavailable. Try
     * again shortly." That sentence is false, unactionable, and would be blamed on the platform. So
     * the composer is not rendered at all for those states and the notice names the move that fixes
     * it.
     *
     * THIS TEST CANNOT DEMAND EITHER BRANCH: which one renders depends on the first bot's status,
     * which is a property of the organization the run was pointed at. What it asserts is that
     * EXACTLY ONE of them is on screen — a page with both, or with neither, is broken in a way no
     * scanner would report.
     */
    await openPlayground(page);

    const composer = page.getByLabel('Your question', { exact: true });
    const notice = page
      .getByText('You can read this bot but not run it')
      .or(page.getByText(/^This bot cannot be run from the playground while it is /));

    const runnable = (await composer.count()) > 0;
    const blocked = (await notice.count()) > 0;

    expect(
      runnable !== blocked,
      runnable
        ? 'the composer and a "cannot be run" notice are both on screen'
        : 'neither a composer nor a reason there is none rendered',
    ).toBe(true);
  });

  test('the retrieval panel says what it will hold before any turn is taken', async ({ page }) => {
    /**
     * AN EMPTY DIAGNOSTIC PANEL THAT SAYS NOTHING READS AS A BROKEN ONE. This is the whole reason
     * the playground exists — it is the only surface that shows dense and sparse scores, the fusion
     * ranking, the rerank scores and every EXCLUDED candidate with its reason — and an operator who
     * opens the tab and sees an empty card has no way to tell "ask something" from "this feature
     * does not work".
     *
     * Before a turn: the "Retrieval detail" card with its description. After one: "How this answer
     * was retrieved". This run only ever sees the first, and says so rather than asserting a
     * disjunction that would pass on a panel that rendered neither.
     */
    await openPlayground(page);

    await expect(page.getByRole('heading', { name: 'Retrieval detail', level: 3 })).toBeVisible();
    await expect(
      page.getByText(
        'Ask something above and every candidate this bot retrieved will be listed here, with the scores from each search branch and the reason anything was dropped.',
      ),
    ).toBeVisible();
  });

  test('the tab strip still has one stop for the whole list with a fourth tab on it', async ({
    page,
  }) => {
    /**
     * ROVING TABINDEX, RE-CHECKED BECAUSE THE LIST GREW. `bots.spec.ts` proves the WAI-ARIA keyboard
     * pattern over three tabs; adding a fourth is exactly the change that turns a roving tabindex
     * into four tab stops if the strip is ever rebuilt by hand. Without it a keyboard user pays one
     * extra keystroke on every trip to the form, forever, and nothing else in the suite would say
     * so.
     */
    await openPlayground(page);

    const tabs = page.getByRole('tab');
    await expect(tabs).toHaveCount(4);

    const stops = await tabs.evaluateAll(
      (nodes) => nodes.filter((node) => node.getAttribute('tabindex') !== '-1').length,
    );

    expect(stops, 'the tab strip has more than one tab stop, so roving tabindex was lost').toBe(1);
  });

  test('the saved settings are shown, so nobody mistakes a run for a scratch configuration', async ({
    page,
  }) => {
    /**
     * A TEMPORARY OVERRIDE DOES NOT EXIST YET, and the card says so. That is the disclosure this
     * panel turns on: an operator who believes they are testing a candidate model, and is in fact
     * spending quota on the saved one, draws the wrong conclusion from every answer.
     *
     * The VALUES are not asserted — they are the bot's own and seed-dependent. The LABELS are, and
     * `Model` is the one that matters: it is the field whose absence would make the card read as
     * decoration.
     */
    await openPlayground(page);

    /**
     * ANCHORED LITERAL PATTERNS, NOT `new RegExp(label)`. Two reasons and both are about what a
     * substring match would do here: `Model` is a substring of `Model & retrieval`, the tab beside
     * this one — which Radix keeps MOUNTED and merely `hidden`, so it is in the DOM and countable —
     * and `Rerank retain` is a substring of nothing today and would silently become one the day a
     * field is renamed. Written out rather than built, so the pattern is the thing on the page.
     */
    const fields: readonly (readonly [string, RegExp])[] = [
      ['Answer mode', /^Answer mode$/],
      ['Model', /^Model$/],
      ['Dense top-k', /^Dense top-k$/],
      ['Sparse top-k', /^Sparse top-k$/],
    ];

    for (const [label, pattern] of fields) {
      await expect(
        page.getByRole('term').filter({ hasText: pattern }),
        `the saved-settings card does not report "${label}"`,
      ).toHaveCount(1);
    }
  });
});

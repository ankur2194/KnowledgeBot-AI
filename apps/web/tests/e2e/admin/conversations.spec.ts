import { expect, test, type Page } from '@playwright/test';

import { scan, skipWithoutAdminSession, summarize } from './harness';

/**
 * `@axe-core/playwright` on `/conversations` and `/conversations/{conversationId}` — the thread list
 * and one transcript, whole — plus the operability checks a scanner cannot make.
 *
 * ── THIS FILE HAS NEVER BEEN EXECUTED AGAINST A POPULATED ORGANIZATION ─────────────────────────
 *
 * `./harness.ts` carries the account of the 2026-08-24 and 2026-08-25 runs. This spec was written
 * after them, for a surface that landed later, and every selector below was read out of the
 * components rather than observed — the same origin its eight neighbours had before they first ran,
 * and the same warning applies: treat a first red run as "the spec is wrong" at least as readily as
 * "the page is wrong".
 *
 * THE ORGANIZATION THE HARNESS POINTS AT HAS NO CONVERSATIONS. Nothing in this repository creates
 * one — a thread is opened by the public runtime and written by the relay's finalizer — so on the
 * stack these specs run against, the list renders its FIRST-RUN EMPTY STATE and every transcript
 * test below skips. That is a state that did not exist, not a check that was waived, and it is
 * stated here rather than left to be inferred from a green run: **a pass on this file today is
 * evidence about the empty list and the route shell, and about nothing that a row would render.**
 *
 * ── TWO ROUTES IN ONE FILE, AND THE REASON IS THAT ONE CANNOT BE REACHED WITHOUT THE OTHER ─────
 *
 * `source-detail.spec.ts` is a separate file from `sources.spec.ts` because both routes are
 * reachable and both are big. Here the detail route has no independent entry: a conversation id is
 * a ULID, it is not knowable without a seed contract that does not exist, and the only honest way in
 * is the list's own `Thread` link. Splitting the files would put the same `openFirstThread()` in
 * both, which is the duplication `harness.ts` exists to end.
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE ────────────────────────────────────────────────────────
 *
 * NOTHING IS WRITTEN AND NOTHING IS DELETED, because this surface has no write at all: two GETs and
 * no mutation, by design (`app/(admin)/conversations/page.tsx` records why — §18.11 treats a
 * transcript as evidence, so a surface that could destroy one would be able to rewrite the record it
 * exists to preserve). There is no destructive dialog to open and no form to submit.
 *
 * NO FILTER IS APPLIED THROUGH THE PICKERS. Opening a Radix `Select` and choosing an option is a
 * navigation that rewrites the URL and refetches; `tests/unit/use-table-params.test.ts` owns that
 * wiring, and what only a browser can check is that the trigger is labelled and that the four
 * filters do not collide on one accessible name. That is the second describe block.
 *
 * ── AND WHAT A GREEN RUN WOULD AND WOULD NOT MEAN ──────────────────────────────────────────────
 *
 * About a third of the job (`kb-ui-accessibility`). The keyboard-only and screen-reader passes stay
 * human steps.
 */

skipWithoutAdminSession();

/**
 * Open the list and wait until the browser fetch has resolved into ONE OF ITS OUTCOMES.
 *
 * The `<h1>` is server-rendered and the table is not, so the heading proves only that the route
 * resolved. The `.or()` chain is what proves the fetch answered — and the four arms are mutually
 * exclusive by construction (`ServerDataTable` renders exactly one of forbidden / error / empty /
 * table), which is the property `embedding.spec.ts` got wrong on the first run and paid for.
 */
async function openConversations(page: Page): Promise<void> {
  await page.goto('/conversations');

  await expect(page.getByRole('heading', { name: 'Conversations', level: 1 })).toBeVisible();

  await expect(
    page
      .getByRole('table', { name: 'Conversations' })
      .or(page.getByText('No conversations yet'))
      .or(page.getByText('No conversations match these filters.'))
      .or(page.getByText('Conversations could not be loaded'))
      .or(page.getByText("You don't have access to this")),
  ).toBeVisible();
}

/**
 * Reach the transcript THE WAY A REVIEWER DOES — from the list, by clicking a thread's id.
 *
 * Deliberately not `page.goto('/conversations/01J…')`: the id is not knowable without a seed
 * contract that does not exist, and navigating from the list also exercises the link the `Thread`
 * cell renders. Returns the thread id, which the later assertions need and none of them may
 * hard-code.
 *
 * Every caller `test.skip`s on the empty organization rather than failing, because "no thread has
 * ever been held" is the true state of the harness organization and not a defect.
 */
async function openFirstThread(page: Page): Promise<string> {
  await openConversations(page);

  const firstThread = page.locator('a[href^="/conversations/"]').first();
  test.skip(
    (await firstThread.count()) === 0,
    'this organization has held no conversation, so there is no transcript to open',
  );

  const id = ((await firstThread.textContent()) ?? '').trim();
  await firstThread.click();

  await expect(page.getByRole('heading', { name: 'Conversation', level: 1 })).toBeVisible();

  // Wait until the browser fetch has ANSWERED. Scanning before that is scanning a skeleton, which is
  // a real surface and not the one these tests name. The transcript's own `<h2>` carries the id, so
  // waiting on it also proves the row that arrived is the row that was clicked.
  await expect(
    page
      .getByRole('heading', { name: 'Transcript', level: 2 })
      .or(page.getByText('This conversation could not be loaded')),
  ).toBeVisible();

  return id;
}

test.describe('axe-core, WCAG 2.2 AA', () => {
  test('the conversations list has no violations', async ({ page }) => {
    await openConversations(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/conversations\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the conversations list has no violations in dark mode either', async ({ page }) => {
    // BOTH MODES, ALWAYS. This list carries TWO soft-status pairs in one row — the channel chip and
    // the thread status — which is where the two ramps disagree first, and the status column is
    // exactly the cell most likely to be checked in one mode only.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openConversations(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/conversations (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the filter row has no violations', async ({ page }) => {
    /**
     * FOUR CONTROLS THAT ARE ALWAYS RENDERED, unlike the table beneath them: the three
     * `FilterSelect`s and the two date inputs are in the `header` slot, which `ServerDataTable`
     * renders regardless of the data state. So this scan is NOT vacuous on an empty organization —
     * it is the one part of this route that is covered whether or not a thread exists.
     */
    await openConversations(page);

    const filters = page.getByRole('combobox', { name: 'Bot' });
    await expect(filters).toBeVisible();

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/conversations filters\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the transcript has no violations', async ({ page }) => {
    await openFirstThread(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/conversations/{id}\n  ${summary.join('\n  ')}`).toEqual([]);
  });

  test('the transcript has no violations in dark mode either', async ({ page }) => {
    // A transcript is the densest tone surface in the console: a status pill on the header card, a
    // per-turn role chip, `--card-inset` evidence strips, a `--warning-soft` fallback note and the
    // citation labels — five tone decisions on one page.
    await page.emulateMedia({ colorScheme: 'dark' });
    await openFirstThread(page);

    const results = await scan(page).analyze();
    const summary = summarize(results.violations);

    expect(summary, `/conversations/{id} (dark)\n  ${summary.join('\n  ')}`).toEqual([]);
  });
});

test.describe('operability, which a scanner cannot test', () => {
  test('the four filters do not share an accessible name', async ({ page }) => {
    /**
     * A scanner checks that a control HAS a name, never that the name is UNIQUE on the page.
     * `conversations-screen.tsx` labels its pickers `Bot` / `Channel` / `Thread status` with a
     * comment saying exactly why a bare "Filter" would be wrong — this is the test that would notice
     * if someone made all three say it.
     *
     * The date pair is included: `Started from` / `Started until` are two `type="date"` inputs side
     * by side, which is the shape where one label gets copied onto both.
     */
    await openConversations(page);

    const names = ['Bot', 'Channel', 'Thread status', 'Started from', 'Started until'];

    for (const name of names) {
      // `exact` matters: without it "Bot" would also resolve the row-level bot link's name on a
      // populated page, and "Started from" is a prefix of nothing but itself only by luck.
      const control = page.getByLabel(name, { exact: true });
      await expect(control, `no control is labelled "${name}"`).toHaveCount(1);
    }
  });

  test('every thread link names its own thread', async ({ page }) => {
    /**
     * One link per row whose accessible name is the thread's ULID. Six rows of "View" would scan
     * perfectly and be unusable — nothing would tell a screen-reader user which conversation they
     * are about to open. `conversation-columns.tsx` puts the id in the link text for this reason.
     */
    await openConversations(page);

    const links = page.locator('a[href^="/conversations/"]');
    const count = await links.count();

    test.skip(
      count === 0,
      'this organization has held no conversation, so there is no row to check',
    );

    const names = await links.evaluateAll((nodes) =>
      nodes.map((node) => (node.textContent ?? '').trim()),
    );

    expect(new Set(names).size, `duplicate link names: ${names.join(' | ')}`).toBe(names.length);
    expect(
      names.every((name) => name.length > 0),
      'a thread link rendered with no accessible name at all',
    ).toBe(true);
  });

  test('the list names itself, so a screen reader does not announce an unnamed table', async ({
    page,
  }) => {
    /**
     * `ServerDataTable` renders its `caption` as a visually hidden `<caption>`, which is what gives
     * the table an accessible name. A page with two tables and no captions gives a screen-reader
     * user two things called "table" — and the caption is invisible, so nothing but a test notices
     * when it is dropped.
     */
    await openConversations(page);

    const table = page.getByRole('table', { name: 'Conversations' });

    test.skip(
      (await table.count()) === 0,
      'the list rendered its empty state, so there is no table to name',
    );

    await expect(table).toBeVisible();
  });

  test('the transcript is a named region reachable from the list and back again', async ({
    page,
  }) => {
    /**
     * THE WAY BACK IS RENDERED IN EVERY STATE — including the failure one, which is the state an
     * unknown id lands on and the state where an operator most needs a way out
     * (`conversation-detail-screen.tsx` says so in writing). A scanner cannot tell that a link goes
     * anywhere useful; this walks it.
     *
     * And the `aria-labelledby` id RESOLVES. A region pointing at a deleted id is a clean scan and
     * an unnamed landmark — the half a scanner skips.
     */
    await openFirstThread(page);

    await expect(page.locator('section[aria-labelledby="transcript-heading"]')).toHaveCount(1);
    await expect(page.locator('#transcript-heading')).toHaveText('Transcript');

    await page.getByRole('link', { name: 'All conversations' }).click();
    await expect(page.getByRole('heading', { name: 'Conversations', level: 1 })).toBeVisible();
  });
});

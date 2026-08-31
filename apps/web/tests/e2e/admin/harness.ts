import { existsSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { test, type Page } from '@playwright/test';

/**
 * The three things every `admin/*.spec.ts` needs, in one place.
 *
 * ── WHY THIS MODULE EXISTS, AND WHY IT DID NOT UNTIL THERE WERE EIGHT SPECS ────────────────────
 *
 * `bots.spec.ts`, `sources.spec.ts`, `source-detail.spec.ts` and `source-upload.spec.ts` each
 * declared their own `ADMIN_STORAGE_STATE`, their own file-scope skip, their own `scan()` and their
 * own `summarize()` — four byte-identical copies of each, which is the shape
 * `tests/support/source-scan.ts` names in its own docblock as "the duplication that ends with the
 * two copies disagreeing". The tag set is the one that would drift first and the one where drift is
 * invisible: a spec scanning `wcag2aa` while its neighbours scan `wcag22aa` reports a clean page and
 * is checking less than the file next to it claims.
 *
 * NOT A `.spec.ts`, deliberately. The `admin` project's `testMatch` is `/admin\/.*\.spec\.ts/`, so a
 * helper here is unambiguously not collected as a test rather than merely happening not to contain
 * any.
 *
 * ── THIS FILE IS NOT A PLACE FOR PAGE SELECTORS ────────────────────────────────────────────────
 *
 * Only what every admin spec needs regardless of what it is looking at. A shared `openBots()` or
 * `firstConnectionHref()` would put one page's structure in the file every other page imports, and
 * the first rename would then break specs that never mention that page. Per-page navigation stays
 * in the spec that owns the page.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THESE SPECS WERE FIRST EXECUTED ON 2026-08-24, AND EVERY ONE OF THEM SAID IT NEVER HAD BEEN
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Each `admin/*.spec.ts` opened with a banner reading "THIS FILE HAS NEVER BEEN EXECUTED. NOT ONCE,
 * NOT PARTIALLY", written honestly by an author with no browser and no Laravel. The account lives
 * here rather than eight times over, for the same reason `scan()` does.
 *
 * THE RUN. A real stack — `laravel-api` on PostgreSQL and Valkey, `ai-api` on Qdrant — with the
 * session minted by `tests/e2e/auth.setup.ts` through the real `/login` form, in the pinned
 * `chromium_headless_shell-1234` from the `browsers` stage of `apps/web/Dockerfile`. ADR-072 records
 * the harness, including the one property of it that is not obvious: the browser origin has to be
 * `http://localhost:3000` rather than a named host, because `crypto.randomUUID` exists only in a
 * secure context.
 *
 * WHAT IT FOUND, AND WHY THE OLD BANNER'S ADVICE HELD IN BOTH DIRECTIONS. Thirteen specs failed on
 * the first run and the causes split four ways:
 *
 *   two   were SPEC defects  — an `.or()` chain of four outcomes that are not mutually exclusive
 *                              (`embedding.spec.ts`), and a `.click()` on a control the next line
 *                              asserts is disabled (`source-upload.spec.ts`)
 *   five  were the HARNESS   — `crypto.randomUUID` undefined over plain HTTP on a named host, so
 *                              chosen files never became rows
 *   one   was a REAL APP BUG — removing an upload row dropped focus to `<body>`, which
 *                              `source-upload.spec.ts` had predicted in writing before it ever ran
 *   one   was a REAL WCAG FAILURE — `.text-primary` measured 3.95:1 on the dark canvas against a
 *                              4.5:1 floor, and it was not a tuning error: no lightness satisfies
 *                              both floors that token carries. `docs/22` § R1. It was the ONLY
 *                              thing red: 55 pass, 24 skip, three failures, one defect, three
 *                              screens.
 *
 * So "treat a first red run as 'the spec is wrong' at least as readily as 'the page is wrong'" was
 * good advice, and so was its converse. What may be cited is what actually ran: a spec that PASSED
 * is evidence about that route on that stack, and nothing here is evidence about the keyboard-only
 * and screen-reader passes, which remain human steps.
 *
 * ── THE SECOND RUN, 2026-08-25: 58 PASS, 24 SKIP, 0 RED ──────────────────────────────────
 *
 * Same harness. **The three R1 failures are gone and nothing else moved** — +3 passes, the same 24
 * skips — which is the shape a real fix makes and a masking change does not. R1 closed by giving
 * the accent its own token as text (`--link` / `--link-active`).
 *
 * TWO PROPERTIES OF THIS RUN ARE WORTH MORE THAN THE FIGURE, because a green axe scan is evidence
 * only about elements that were ON SCREEN:
 *
 *   NOT VACUOUS on `/sources`. The organization had 2 knowledge sources, so the per-row link — the
 *   instance R1 singled out, because a list scales one defect by its row count — actually rendered
 *   and was scanned. On `/settings/providers` and `/settings/embedding` the `text-link` element is
 *   in an unconditional block, so those two are covered regardless of data.
 *
 *   NOT COVERED: `connection-list.tsx`'s `ConnectionRow` link. The organization had 0 provider
 *   connections, so that instance never rendered. It uses the same token and the unit matrix in
 *   `tests/unit/design-system.test.ts` proves the ratio, but no browser has scanned it. Say so
 *   rather than reading this run as covering every accent-as-text element in the console.
 *
 * ── THE THIRD RUN, 2026-08-31: THE WHOLE `admin` PROJECT — 63 PASS, 29 SKIP, 0 RED ────────────
 *
 * Five new specs — `conversations`, `audit-logs`, `quotas`, `dashboard`, `playground` — for the
 * surfaces D4–E4 added, executed for the first time, and then the whole project re-run to check
 * nothing regressed. `--workers=1 --fail-on-flaky-tests`, exit 0. **13 admin specs, 91 tests: 62
 * pass, 29 skip, 0 fail**, plus the `setup` project's one.
 *
 * ── THE "58 PASS, 24 SKIP" ABOVE IS NOT AN `admin`-PROJECT FIGURE, AND IT READS LIKE ONE ───────
 *
 * Measured on 2026-08-31 with `--list`: `admin` is **92** tests in 14 files (13 specs + the setup)
 * and `public` is **30** in 3. The eight specs that predate Phase D contribute **51** of those, so
 * 51 + 30 + 1 = **82 = 58 + 24**. The second run's headline was every project at once.
 *
 * **This matters because the note you are reading sits directly beneath it.** A reader comparing 33
 * (the five new specs alone) or 63 (the admin project) against 58 would conclude coverage shrank; it
 * grew by 40 tests. Neither of the two figures above says which projects it counted, and that is the
 * defect — not the number. A figure in a docblock is a claim, and a claim that cannot be decomposed
 * cannot be checked (ADR-036, and `docs/22` § T40 is what happens when one is believed).
 *
 * ── IT FOUND ONE REAL DEFECT, AND NOT IN THE NEW CODE PATHS ANYONE EXPECTED ───────────────────
 *
 * `/audit-logs` reported `definition-list` (serious) on **every** scan of the route, because the
 * `Recorded` cell's "3 more fields" overflow note was a `<div>` sitting loose inside the `<dl>` it
 * counts. Three of the four axe assertions in that file went red on one node.
 * `tests/components/audit-screen.test.tsx` renders the same cell and asserts the same text and could
 * not see it — a component test reads the DOM it was given and has no opinion about whether that DOM
 * is a legal definition list. Fixed in `features/audit/audit-columns.tsx`; the regression is pinned
 * by a DOM assertion in the spec rather than left to a scanner happening to reach the right row, and
 * that assertion was verified by reintroducing the defect (4 of 4 notes loose) and removing it again
 * (0 of 4). `docs/22` § T65.
 *
 * ONE OF THE SPEC'S OWN ASSERTIONS WAS WRONG FIRST, and the shape is worth carrying: the regression
 * test used `closest('dl')`, which reported two legal notes as violations, because
 * `ServerDataTable` renders BOTH layouts into the DOM — the real `<table>` above 768px and a stack
 * of row-cards below it, whose cells sit inside a `<dd>` of the card's own `<dl>`. **Any spec on any
 * `ServerDataTable` route matches every cell twice**, and a count assertion that does not expect
 * that is off by a factor of two. `closest('dt, dd, dl')` is the predicate that distinguishes loose
 * content from nested content.
 *
 * ── THE SKIPS MOVED, AND NOT BECAUSE ANY SPEC CHANGED ─────────────────────────────────────────
 *
 * 29 skips, and **the eight pre-existing specs own 21 of them** — up from what the second run saw,
 * on identical files. The organization's DATA changed: it had 2 knowledge sources on 2026-08-25 and
 * has **0** today, so `source-detail.spec.ts` skipped all 8 of its tests and `sources.spec.ts` skipped
 * its row-dependent one. **That retires the "NOT VACUOUS on `/sources`" claim above for this run** —
 * R1's per-row accent link was scanned in the second run and was not scanned in this one. Nothing
 * regressed; the evidence simply is not the same evidence, which is the whole reason a pass/skip
 * split is worth more than a pass count. `models.spec.ts` (0 pass, 5 skip) and `providers.spec.ts`
 * (3 pass, 3 skip) skip for the same reason they always have: 0 provider connections.
 *
 * The five new specs own the other 8: five on `/conversations` (the organization has held no thread,
 * and nothing in this repository creates one) and three on `/quotas` (the harness account is an
 * **admin**, and `quotas.manage` is owner-only by design — so the ceilings form, its number input and
 * its Unlimited switch have never been rendered in a browser by any run).
 *
 * ── TWO HARNESS FACTS ADR-072 DOES NOT CARRY, AND ONE OF THEM COSTS AN HOUR ────────────────
 *
 * `CORS_ALLOWED_ORIGINS` must include `http://localhost:3000`. ADR-072 lists `SESSION_DOMAIN`,
 * `SESSION_SECURE_COOKIE` and `SANCTUM_STATEFUL_DOMAINS` and stops there. Without the CORS origin
 * the browser's login preflight is rejected, `auth.setup.ts` times out on `waitForURL`, the page
 * shows *"That did not go through, and the reason was not reported"* — and **nothing reaches any
 * Laravel log**, because the request never arrives. Worse for whoever debugs it: `curl` does not
 * enforce CORS, so a curl reproduction of the identical login returns `200` and points away from
 * the cause. `docs/22` § R9.
 *
 * `ai-api` has to be up for `embedding.spec.ts`. The readiness verdict is resolved through
 * `InternalAiClient`, so without FastAPI the panel holds a retrying query, renders no verdict at
 * all, and `openEmbedding()` fails its gate — which reads as five broken embedding specs rather
 * than as a missing container.
 */

/** Relative to `apps/web`, matching `playwright.config.ts`'s `storageState` for the admin project. */
export const ADMIN_STORAGE_STATE = 'playwright/.auth/admin.json';

/**
 * Skip the WHOLE FILE when no admin session was minted. Call at module scope, before any `describe`.
 *
 * A Playwright project whose `storageState` path is missing does not skip — it ERRORS at context
 * creation, once per test — so without this an unprepared machine gets a wall of errors from
 * `pnpm web:e2e` instead of a public-project run. `test.skip(condition, reason)` at file scope is
 * evaluated at DECLARATION time, before any fixture is requested, so no browser context is created
 * and no storageState is read.
 *
 * THE GUARD IS EXACT ONLY BECAUSE `auth.setup.ts` DELETES A STALE FILE WHEN IT SKIPS. Without that,
 * this predicate would be true for a session that expired last week, and every spec would fail at a
 * redirect to `/login` — which reads as a broken console rather than as a missing credential.
 */
export function skipWithoutAdminSession(): void {
  test.skip(
    !existsSync(ADMIN_STORAGE_STATE),
    `${ADMIN_STORAGE_STATE} does not exist, so no admin session can be restored. It is written by ` +
      'the `setup` project (tests/e2e/auth.setup.ts), which skips itself unless KB_E2E_ADMIN_EMAIL ' +
      'and KB_E2E_ADMIN_PASSWORD are set. Running these without it is an error per test, not a ' +
      'skip, which is why the whole file opts out here.',
  );
}

/**
 * The scanner, with the tag set every admin surface is held to.
 *
 * `disableRules` is EMPTY and stays that way, exactly as in the public spec. A rule turned off to
 * make a run green is a violation that has been renamed; a genuine false positive is excluded by
 * SELECTOR, at the call site, with a comment naming why — so the next person sees the scope rather
 * than the silence.
 */
export const scan = (page: Page) =>
  new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']);

/** The public spec's failure formatter: the rule, its impact and the first offending node. */
export const summarize = (violations: Awaited<ReturnType<AxeBuilder['analyze']>>['violations']) =>
  violations.map(
    (violation) =>
      `${violation.id} (${violation.impact}): ${violation.help}\n    ${violation.nodes[0]?.target.join(' ')}`,
  );

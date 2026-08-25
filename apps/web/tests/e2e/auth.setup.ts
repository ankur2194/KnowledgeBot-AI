import { existsSync, mkdirSync, rmSync } from 'node:fs';
import { dirname } from 'node:path';

import { expect, test as setup } from '@playwright/test';

/**
 * The `setup` project. Authenticates once, against a REAL Laravel, and writes the storageState every
 * spec in the `admin` project restores.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE IS WHAT THE FOUR `admin/*.spec.ts` FILES HAVE BEEN WAITING FOR.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `playwright.config.ts` has declared an `admin` project with `dependencies: ['setup']` and a
 * `storageState` of `playwright/.auth/admin.json` since the config was written, and no `*.setup.ts`
 * ever existed — so `testMatch: /.*\.setup\.ts/` matched ZERO files, nothing wrote the state, and a
 * Playwright project whose `storageState` path is missing does not skip: it ERRORS at context
 * creation, once per test. Every admin spec therefore carries a file-scope `existsSync` guard and
 * every one of them has been inert since the day it was written. This file is the other half.
 *
 * ── IT DRIVES THE REAL LOGIN ROUTE, AND THAT IS THE WHOLE DESIGN CONSTRAINT ─────────────────────
 *
 * `playwright.config.ts` states it at the project: "No test-only login endpoint, no `?org=` override,
 * no seeded superuser that skips membership — a faked credential cannot fail an isolation test."
 * So this fills the form at `/login` and submits it. What that exercises, and what a fixture would
 * have skipped: `GET /sanctum/csrf-cookie`, the URL-decoded `X-XSRF-TOKEN` echo, Sanctum's
 * `fromFrontend()` classification of an `app.<domain>` -> `api.<domain>` request, the account AND IP
 * rate limiters, the membership read that resolves `current_organization_id`, and the `verified`
 * gate. A session minted any other way is a session no isolation spec can fail against.
 *
 * ── WHERE THE CREDENTIAL COMES FROM, AND WHY IT IS NOT SEEDED ──────────────────────────────────
 *
 * `KB_E2E_ADMIN_EMAIL` and `KB_E2E_ADMIN_PASSWORD`, from the environment, and nothing else. There is
 * deliberately no seeder behind them:
 *
 *   `services/core-api/database/seeders/DatabaseSeeder.php` IS EMPTY ON PURPOSE and its docblock
 *   argues that test fixtures do not belong in it. `kb:bootstrap-organization` is the supported way
 *   to create the first organization and its owner — and it is designed so that it CANNOT hand a
 *   password to a machine: it never accepts one in any form, creates the owner with an unusable
 *   64-hex placeholder it never prints, and mails a reset link (`--print-link` puts the URL on
 *   stdout for an environment with no mailbox). That is a deliberate property of the command, not a
 *   gap for this file to route around, so the password an E2E run uses is one a HUMAN set through
 *   the ordinary reset flow and then put in their shell.
 *
 * TO PREPARE A STACK FROM EMPTY:
 *
 *   docker compose exec laravel-api php artisan kb:bootstrap-organization \
 *     --name='E2E Org' --email='e2e-admin@example.test' --owner-name='E2E Admin' --print-link
 *   # open the printed URL, set a password, then:
 *   export KB_E2E_ADMIN_EMAIL='e2e-admin@example.test' KB_E2E_ADMIN_PASSWORD='…'
 *   pnpm web:e2e --fail-on-flaky-tests
 *
 * ── WITH NO CREDENTIAL IT SKIPS, AND IT DELETES THE STALE STATE FIRST ──────────────────────────
 *
 * The skip is what keeps `pnpm web:e2e` meaning "run the public project" on a machine that has not
 * been prepared, which is the behaviour every admin spec's guard already assumes.
 *
 * THE DELETION IS THE PART THAT IS NOT OBVIOUS AND IS THE REASON THIS BRANCH IS FIVE LINES RATHER
 * THAN ONE. `playwright/.auth/` is gitignored but it is not ephemeral — it survives on the machine
 * that ran yesterday. Skipping while leaving a state file behind means the admin specs' `existsSync`
 * guard passes, the specs run, and they restore an EXPIRED session: every one of them fails at a
 * redirect to `/login`, which reads as "the console is broken" rather than as "you did not export
 * the credential". Removing the file makes the guard exact — it is true if and only if this run
 * authenticated — and `existsSync` before `rmSync` because `force: true` would also swallow a
 * permission error on a file that is there.
 *
 * ── WHAT IT ASSERTS BEFORE SAVING, AND WHY THE COOKIE IS NOT ONE OF THE PROOFS ─────────────────
 *
 * `src/proxy.ts:166-195` records the measurement: LARAVEL ISSUES `kb_session` TO A GUEST. Every auth
 * form calls `GET /sanctum/csrf-cookie` first, so a browser that has merely LOADED `/login` already
 * carries the cookie — which is why the proxy's "looks signed in" redirect was removed. A setup that
 * proved itself by finding the cookie in the saved state would therefore pass having authenticated
 * nothing, and would hand every admin spec a guest session.
 *
 * The proof is that an AUTHENTICATED SURFACE RENDERED. `<CurrentOrgBadge/>` returns `null` unless the
 * session query answered `authenticated` AND named a `current_organization_id` that resolves to an
 * active membership, and it is rendered in the browser from `GET /api/v1/auth/me` — so its
 * `aria-label` is a statement about the whole chain. The cookie is still checked, separately and for
 * a different reason: a storageState with no cookies at all means `context.storageState()` captured
 * nothing and the file would be a valid-looking artifact that restores no session.
 */

/** Relative to `apps/web`, matching `playwright.config.ts`'s `storageState` for the admin project. */
const ADMIN_STORAGE_STATE = 'playwright/.auth/admin.json';

/** `config('session.cookie')`, never Laravel's `laravel_session` factory default — see `src/proxy.ts`. */
const SESSION_COOKIE = 'kb_session';

const email = process.env.KB_E2E_ADMIN_EMAIL;
const password = process.env.KB_E2E_ADMIN_PASSWORD;

setup('authenticate as an administrator', async ({ page, context }) => {
  if (email === undefined || email === '' || password === undefined || password === '') {
    // See the header: the stale state has to go before the skip, or the admin specs run against
    // yesterday's expired session and fail as though the console were broken.
    if (existsSync(ADMIN_STORAGE_STATE)) rmSync(ADMIN_STORAGE_STATE);

    setup.skip(
      true,
      'KB_E2E_ADMIN_EMAIL and KB_E2E_ADMIN_PASSWORD are not set, so no session can be minted and ' +
        `${ADMIN_STORAGE_STATE} has been removed rather than left stale. The admin project skips ` +
        'itself when that file is absent. See this file\'s header for how to prepare a stack: ' +
        '`php artisan kb:bootstrap-organization --print-link`, set a password through the printed ' +
        'link, then export both variables.',
    );

    return;
  }

  await page.goto('/login');

  // The heading is server-rendered by `(auth)/login/page.tsx`, so seeing it proves the route
  // resolved and the Next server is the one under test rather than a stale build.
  await expect(page.getByRole('heading', { name: 'Sign in', level: 1 })).toBeVisible();

  // BY LABEL, not by `input[type=email]`. The labels are what `login-form.tsx` associates through
  // shadcn's `FormField`, and addressing the control the way a screen reader does means a broken
  // association fails HERE — in one place, loudly — rather than in whichever accessibility spec
  // happens to scan next.
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);

  await page.getByRole('button', { name: 'Sign in' }).click();

  // `onSuccess` does `browserNavigation.assign(next)` — a FULL DOCUMENT navigation, not a router
  // push — and `next` defaults to '/' because `safeNext(undefined)` returns it. So the wait is for
  // the URL to stop being `/login`, and then for the overview to render.
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 });

  await expect(page.getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible();

  // THE PROOF. `<CurrentOrgBadge/>` renders nothing unless the session is authenticated AND its
  // `current_organization_id` resolves to an ACTIVE membership — see the header for why the cookie
  // cannot stand in for this.
  await expect(page.getByRole('link', { name: /^Current organization: / })).toBeVisible();

  const cookies = await context.cookies();

  expect(
    cookies.map((cookie) => cookie.name),
    'the browser context holds no session cookie, so the saved state would restore nothing',
  ).toContain(SESSION_COOKIE);

  // `storageState({path})` creates the directory itself in recent Playwright, but the failure when
  // it does not is an ENOENT inside a fixture teardown, which is a bad place to read one from.
  mkdirSync(dirname(ADMIN_STORAGE_STATE), { recursive: true });

  // COOKIES AND localStorage ONLY. sessionStorage is never captured by Playwright; nothing in this
  // console keeps a credential there, and if anything ever does it must be restored with
  // `addInitScript()` rather than expected to survive in this file.
  await context.storageState({ path: ADMIN_STORAGE_STATE });
});

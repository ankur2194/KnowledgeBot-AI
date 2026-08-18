import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.KB_WEB_BASE_URL ?? 'http://localhost:3000';

export default defineConfig({
  testDir: './tests/e2e',
  // Without fullyParallel, --shard assigns whole FILES and the shards imbalance badly.
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Blob reports merge across shards with `npx playwright merge-reports`.
  //
  // There is NO automated invocation of Playwright, and no CI in this repo to add one to, so
  // nothing here has ever run outside a laptop. Whoever runs it must pass
  // --fail-on-flaky-tests: with retries=1 below, a spec that only ever passes on the retry exits 0
  // forever, and the org-switch and streaming specs are exactly the kind that flake first and mean
  // it. The flag is a property of the invocation, not of this file; it cannot be set from here.
  reporter: process.env.CI ? [['blob'], ['list']] : [['list']],
  use: {
    baseURL,
    trace: 'on-first-retry',
    // Radix/shadcn transitions are the usual source of screenshot diffs between a laptop and CI.
    //
    // `reducedMotion` is NOT a top-level `use` option in @playwright/test 1.62 — only the handful
    // that may differ between consecutive tests in one worker (colorScheme, forcedColors, contrast,
    // screen, userAgent, viewport, testIdAttribute) are lifted out of BrowserContextOptions. The
    // rest are set through `contextOptions`, which is the spelling Playwright's own type docs use
    // for this exact key. Written flat it is a typecheck failure, not a silent no-op.
    contextOptions: { reducedMotion: 'reduce' },
  },
  projects: [
    {
      // Authenticates by driving the REAL login route against a real Laravel and writes
      // storageState. No test-only login endpoint, no ?org= override, no seeded superuser that
      // skips membership — a faked credential cannot fail an isolation test.
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
    },
    {
      // storageState persists cookies, so kb_session AND XSRF-TOKEN both come back. The name is
      // `config('session.cookie')` (services/core-api/config/session.php:23), never Laravel's
      // `laravel_session` factory default — see the constant in src/proxy.ts.
      // sessionStorage is never captured; anything kept there is restored with addInitScript().
      name: 'admin',
      dependencies: ['setup'],
      testMatch: /admin\/.*\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'playwright/.auth/admin.json',
      },
    },
    {
      // Hosted chat holds NO cookie: the bootstrap mints an origin-bound chat-session token per
      // page load. Running these specs with the admin storageState would hide the production-only
      // 401 that nextjs-app-router NN8 exists to catch.
      name: 'public',
      testMatch: /public\/.*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    // Never `next dev`: the production build is what ships, and dev-only behaviour (StrictMode
    // double effects, unminified bundles, no Full Route Cache) hides half of what these specs
    // assert. It also hides the opposite: the dev overlay injects inline styles that the `(chat)`
    // group's `style-src 'self'` refuses, so a dev run reports CSP violations that production does
    // not have.
    command: 'pnpm build && pnpm start',
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    timeout: 180_000,
    // `src/lib/env.ts` validates this at MODULE LOAD, so `next build` dies on a zod error before a
    // single spec runs without it. `.invalid` is reserved by RFC 2606 and never resolves, ON PURPOSE
    // — the same choice vitest.config.ts makes: a spec that reaches the network fails on DNS rather
    // than quietly talking to a host that happens to exist.
    env: { NEXT_PUBLIC_API_ORIGIN: 'http://api.invalid' },
  },
});

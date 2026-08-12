import { defineConfig, devices } from '@playwright/test';

/**
 * A REAL CROSS-ORIGIN HARNESS. This is the point of the file.
 *
 * A same-origin test passes with every origin check removed, which makes it worse than no test: it
 * is a green suite over a widget that would accept `postMessage` from anyone. So the fixture
 * "customer page" is served from a DIFFERENT ORIGIN than the iframe, by a different server, and
 * every spec drives the real loader against the real frame bundle.
 *
 * THREE ORIGINS, TWO webServer ENTRIES. The widget-origin harness binds two ports from one
 * process, which is faithful to production: `<widget-domain>` serves the loader and the static
 * iframe assets from nginx while `<widget-domain>/embed/*` and `api.<domain>` are both Laravel.
 *
 *   customer  http://localhost:4174   the hostile host page
 *   widget    http://127.0.0.1:4173   the loader + the iframe document (stands in for nginx+Laravel)
 *   api       http://127.0.0.1:4175   the session mint (stands in for Laravel)
 *
 * WHAT LOOPBACK CANNOT REPRODUCE, stated rather than papered over: `localhost` and `127.0.0.1` are
 * different ORIGINS but they are not different registrable domains, so the eTLD+1 separation
 * between `<widget-domain>` and `api.<domain>` is NOT asserted here. That assertion belongs to
 * `traefik-routing`'s deployment test, which compares real eTLD+1s, and to the build-time guard in
 * vite.config.ts. Point the three env vars below at real hosts-file names (`customer.test`,
 * `kb-widget.test`, `api.kb.test`) to get the cookie/`SameSite`/CHIPS behaviours too — loopback
 * cannot express `Partitioned` at all, since `__Host-` requires `Secure`.
 */
const customerBase = process.env['KB_CUSTOMER_BASE_URL'] ?? 'http://localhost:4174';
const widgetBase = process.env['KB_WIDGET_BASE_URL'] ?? 'http://127.0.0.1:4173';
const apiBase = process.env['KB_API_BASE_URL'] ?? 'http://127.0.0.1:4175';

export default defineConfig({
  testDir: './tests/e2e',
  // NOT parallel, and not an oversight. The harness is a shared stand-in for Laravel with a global
  // mint counter, and "exactly one session mint" is the assertion half these specs exist for. Two
  // workers against one counter turn that into a flake nobody can reproduce. Every spec still
  // measures a DELTA rather than an absolute, so the ordering is not load-bearing either.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 1 : 0,
  reporter: process.env['CI'] ? [['blob'], ['list']] : [['list']],
  use: {
    // The CUSTOMER's page is the baseURL: every spec starts where a visitor starts.
    baseURL: customerBase,
    trace: 'on-first-retry',
    /**
     * `reducedMotion` is NOT a top-level `use` option in Playwright 1.62.1 — it is a
     * BrowserContext option, reachable from `use` only through `contextOptions`. The type's own
     * usage example spells it exactly this way (playwright/types/test.d.ts, `contextOptions`).
     *
     * KEPT, NOT DELETED. It matters here: the launcher and the sliding panel animate, and every
     * spec in this suite polls for a settled DOM — `a.kb-degraded` appearing, a launcher count, a
     * computed style. Leaving motion on trades a real assertion for a flake nobody can reproduce.
     */
    contextOptions: {
      reducedMotion: 'reduce',
    },
  },
  projects: [
    {
      name: 'widget',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      /**
       * Builds BOTH artifacts and serves them. Never a dev server: the production build is what
       * ships to customers, and dev-only behaviour (unminified bundles, HMR client, prefresh)
       * hides half of what these specs assert — including the size of the thing being tested.
       *
       * The third build is the STREAM PROBE (tests/harness/probe.vite.config.ts), which bundles the
       * real `src/app/stream.ts` into a page this server hosts at `/__probe`. It emits into
       * `dist/probe/`, which no `.size-limit.json` glob matches, and it lives in tests/ rather than
       * as a `--mode` in the shipped vite.config.ts so that a test-only entry can never be built
       * into a customer artifact.
       *
       * The full-origin `KB_*_ORIGIN` form is used because the harness origins are http:// on
       * loopback and vite.config.ts's domain form always produces https://.
       */
      command:
        'pnpm run build && pnpm exec vite build --config tests/harness/probe.vite.config.ts && node tests/harness/widget-origin.mjs',
      url: `${widgetBase}/healthz`,
      reuseExistingServer: !process.env['CI'],
      timeout: 180_000,
      env: {
        KB_WIDGET_ORIGIN: widgetBase,
        KB_API_ORIGIN: apiBase,
        KB_WIDGET_PORT: new URL(widgetBase).port,
        KB_API_PORT: new URL(apiBase).port,
        KB_CUSTOMER_ORIGIN: customerBase,
        /**
         * WITHOUT THIS THE SERVER NEVER STARTS, AND EVERY SPEC IN THE SUITE FAILS.
         *
         * vite.config.ts refuses to build when the widget origin and the API origin share a guessed
         * registrable domain — the docs/22 defect 17 guard, and it is correct to have. But the two
         * loopback defaults are `127.0.0.1:4173` and `127.0.0.1:4175`: same host, different ports,
         * so the "last two labels" heuristic sees one registrable domain and throws. The build dies,
         * `webServer` exits 1, and Playwright reports "Process from config.webServer was not able to
         * start" for specs that have nothing to do with origins.
         *
         * Setting the escape hatch here is the honest fix rather than a weakening, because the
         * docblock at the top of this file ALREADY states that loopback cannot express eTLD+1
         * separation — `localhost` and `127.0.0.1` are different origins but not different
         * registrable domains. This suite asserts the postMessage origin checks, which only need two
         * distinct ORIGINS. The eTLD+1 assertion belongs to `traefik-routing`'s deployment test and
         * to the build-time guard itself, both of which still run against real hostnames.
         *
         * Point KB_WIDGET_BASE_URL / KB_API_BASE_URL at real hosts-file names (`kb-widget.test`,
         * `api.kb.test`) and the guard passes on its own merits — this line then changes nothing.
         */
        KB_ALLOW_SHARED_SUFFIX: '1',
      },
    },
    {
      command: 'node tests/harness/customer-origin.mjs',
      url: `${customerBase}/healthz`,
      reuseExistingServer: !process.env['CI'],
      timeout: 30_000,
      env: {
        KB_CUSTOMER_PORT: new URL(customerBase).port,
        KB_WIDGET_ORIGIN: widgetBase,
        // Used only by `?csp=strict`, which must name BOTH origins: it allows `script-src` to the
        // widget origin and `connect-src` to the api origin, then withholds `frame-src` so the frame
        // — and only the frame — is refused.
        KB_API_ORIGIN: apiBase,
      },
    },
  ],
});

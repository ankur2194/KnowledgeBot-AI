import preact from '@preact/preset-vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

/**
 * TWO builds in one file, selected by `--mode`.
 *
 *   --mode loader → dist/loader/kb-widget.js   one IIFE, no chunks, no emitted .css, <=5 kB br.
 *                                              Runs in the HOST document, on a customer's origin.
 *   --mode app    → dist/app/                  an ordinary code-split Vite app build, served in
 *                                              our iframe on <widget-domain>.
 *
 * VITE 8 NAMES ONLY: `build.rolldownOptions` and `oxc`. The Vite 7 spellings of both still
 * auto-convert, which is exactly why they must not be written here — the conversion is lossy for
 * anything Rolldown names differently and it is scheduled for removal, so a stale JSX block under
 * the old key silently loses its configuration the day it goes. CI greps this tree for the old
 * names, so they are described rather than spelled.
 */

/* ------------------------------------------------------------------------------------------------
 * Build-time constants.
 *
 * `define` is a TEXT SUBSTITUTION, not a binding. An undeclared `__KB_*` is NOT a build error: it
 * survives verbatim into the IIFE and the loader dies on a customer's live page with
 * `ReferenceError: __KB_API_ORIGIN__ is not defined` before the launcher paints. Declare every one
 * of them HERE and in src/env.d.ts; apps/widget/Dockerfile greps dist/ for a surviving `__KB_`.
 *
 * The UNSET case is quieter: `?? ''` would bake `https://undefined` onto a customer's page and
 * every session mint would fail CORS with no error on our side. So `env()` THROWS.
 *
 * TWO SPELLINGS, deliberately (see the report note on this seam):
 *   WIDGET_DOMAIN / DOMAIN         — Compose's variables, the two Traefik hostnames
 *                                    (`traefik-routing`), and the spelling in
 *                                    preact-vite-library/references/vite-config.md.
 *   KB_WIDGET_ORIGIN / KB_API_ORIGIN — the full-origin form apps/widget/Dockerfile already passes
 *                                    as build ARGs, and the only form that can express the
 *                                    http:// loopback origins the Playwright harness needs.
 * The origin form wins when present; otherwise the domain form is required and `env()` throws on
 * an unset DOMAIN / WIDGET_DOMAIN. With NEITHER set the build fails, which is the whole point.
 * ---------------------------------------------------------------------------------------------- */
const env = (key: string): string => {
  // `security/detect-object-injection` flags the computed key. Every call site passes a string
  // literal, this runs at BUILD time in our own Node process, and `process.env` is the operator's
  // environment — there is no request, no user and no untrusted key anywhere near it.
  // eslint-disable-next-line security/detect-object-injection -- build-time env read, literal keys only
  const value = process.env[key];
  if (value === undefined || value === '') {
    throw new Error(
      `[kb] ${key} is unset. Set ${key}, or pass the full origin as ` +
        `KB_WIDGET_ORIGIN / KB_API_ORIGIN. A widget built with an unset domain ships ` +
        `"https://undefined" and every session mint fails CORS on a customer's page.`,
    );
  }
  return value;
};

/** Trailing slashes are not part of an origin, and `event.origin === 'https://x/'` never matches. */
const asOrigin = (value: string): string => value.replace(/\/+$/, '');

const widgetOrigin = asOrigin(process.env['KB_WIDGET_ORIGIN'] || `https://${env('WIDGET_DOMAIN')}`);
const apiOrigin = asOrigin(process.env['KB_API_ORIGIN'] || `https://api.${env('DOMAIN')}`);

/**
 * The one architectural assertion this config can make on its own.
 *
 * `<widget-domain>` must share NO registrable suffix with `api.<domain>`: the admin session cookie
 * is scoped to the main domain, so an API on the widget's eTLD+1 puts a widget iframe on a hostile
 * customer page *same-site* with a real admin credential, and CHIPS cannot help because the cookie
 * is not the widget's (docs/22 defect 17). Collapsing them is a security regression, not a
 * simplification.
 *
 * "Last two labels" is a HEURISTIC — it is wrong for multi-part suffixes like `co.uk`, so there is
 * an explicit, greppable escape hatch rather than a silent pass. The authoritative assertion is
 * `traefik-routing`'s deployment test, which compares real eTLD+1s.
 */
const registrableGuess = (origin: string): string =>
  new URL(origin).hostname.split('.').slice(-2).join('.');

if (
  process.env['KB_ALLOW_SHARED_SUFFIX'] !== '1' &&
  registrableGuess(widgetOrigin) === registrableGuess(apiOrigin)
) {
  throw new Error(
    `[kb] the widget origin (${widgetOrigin}) and the API origin (${apiOrigin}) look like the ` +
      `same registrable domain. The widget MUST be a separate eTLD+1 (kb-security-baseline §18.5). ` +
      `If your suffix is multi-part (e.g. example.co.uk vs kbwidget.co.uk) and the eTLD+1s really ` +
      `do differ, set KB_ALLOW_SHARED_SUFFIX=1.`,
  );
}

export default defineConfig(({ mode, command }) => ({
  // Production JSX is Oxc's. There is deliberately NO React compatibility alias, so an accidental
  // `import … from 'react'` fails the build with "Failed to resolve import" instead of silently
  // adding ~12 kB to a 30 kB budget. Do not add one to unblock a build.
  oxc: { jsx: { runtime: 'automatic', importSource: 'preact' } },

  plugins: [
    // Tailwind v4 has no JS config and no standalone PostCSS step here: `@import 'tailwindcss'` in
    // src/app/styles.css is compiled by this plugin. App mode only — the LOADER's launcher.css is
    // imported `?inline` as a plain string and must never be handed to a CSS framework.
    // (This plugin is the one addition to the config pinned in preact-vite-library's reference;
    // that reference predates the styles.css deliverable, which requires it.)
    ...(mode === 'app' ? [tailwindcss()] : []),
    // preset-vite exists for prefresh HMR and pulls in @babel/core. Dev only.
    ...(command === 'serve' ? [preact()] : []),
  ],

  build: {
    // Our visitors are the CUSTOMERS' visitors, not ours. The default is
    // 'baseline-widely-available', which is our risk appetite applied to their audience. Pin the
    // list so a Vite minor cannot move it, and re-run `pnpm size` after any change — extra
    // transpilation is the single largest uncontrolled source of budget growth.
    target: ['chrome111', 'edge111', 'firefox114', 'safari16.4', 'ios16.4'],

    ...(mode === 'loader'
      ? {
          outDir: 'dist/loader',
          // Defaults to false under build.lib anyway; stated because the failure is invisible.
          // With CSS code splitting off, Vite bundles imported CSS into a sibling .css file — and
          // an IIFE on a customer page has nothing to inject it with. launcher.css is therefore
          // imported with `?inline`, which stops the emit entirely.
          cssCodeSplit: false,
          lib: {
            entry: 'src/loader/index.ts',
            // Mandatory for iife/umd. The global is never used as an API surface; the documented
            // entry point is the window.kbq queue (README).
            name: 'KBWidget',
            formats: ['iife' as const],
            fileName: () => 'kb-widget.js',
          },
          rolldownOptions: {
            // One file. The loader must never emit a second request into a page whose CSP we have
            // not seen, and the customer's `script-src` names exactly one path.
            output: { inlineDynamicImports: true },
          },
        }
      : {
          outDir: 'dist/app',
          // NOT in the config pinned by preact-vite-library, and load-bearing:
          // traefik-routing routes <widget-domain>/embed/* to LARAVEL, because `frame-ancestors`
          // is derived per bot from a live allow-list on every request and cannot be a static
          // header. So dist/app/index.html is NEVER served — Laravel emits the document and reads
          // dist/app/.vite/manifest.json to learn the hashed asset filenames.
          manifest: true,
          // The polyfill costs bytes for browsers our pinned target already excludes.
          modulePreload: { polyfill: false },
          rolldownOptions: {
            output: {
              // The chunk name derives from the MODULE FILENAME by default, so the entry
              // src/app/main.tsx would emit `main-<hash>.js` while .size-limit.json watches
              // `index-*.js` — a glob matching nothing is a budget that silently passes. Pin the
              // entry name instead of renaming the file, so a future rename cannot reintroduce it.
              // Chunk names keep the default `[name]-[hash].js`, which is exactly why the lazy
              // Markdown chunk is src/render/renderer.ts and not render/markdown.ts.
              entryFileNames: 'assets/index-[hash].js',
            },
          },
        }),
  },

  define: {
    __KB_VERSION__: JSON.stringify(
      process.env['KB_VERSION'] ?? process.env['npm_package_version'] ?? '0.0.0',
    ),
    // Baked in, never read from `iframe.src`, `document.currentScript.src` or a `data-*`
    // attribute — all three are host-writable before our code runs.
    __KB_WIDGET_ORIGIN__: JSON.stringify(widgetOrigin),
    __KB_API_ORIGIN__: JSON.stringify(apiOrigin),
  },

  /* ----------------------------------------------------------------------------------------------
   * Dev/preview servers. NEITHER SHIPS: the production `sdk` image is nginx serving `dist/`, so
   * everything below is a development affordance and nothing here can reach a customer's page.
   *
   * `allowedHosts` IS LOAD-BEARING IN THE COMPOSE STACK, AND ITS ABSENCE FAILED SILENTLY.
   * Vite rejects any request whose Host header is neither an IP literal nor listed here, with a
   * plain-text 403 — "Blocked request. This host is not allowed." MEASURED on 2026-08-12 against
   * the dev stack: `https://kb-widget.example/` through Traefik returned 403 while
   * `docker compose ps` reported the container HEALTHY, because the healthcheck probes
   * `http://127.0.0.1:8080/` and an IP host bypasses the check entirely. So the widget origin was
   * dead at the edge and every signal said it was fine. The compose healthcheck now sends the real
   * Host header for the same reason (infrastructure/docker/compose.override.yaml).
   *
   * It is derived from `widgetOrigin` rather than written out, so it cannot drift from the Traefik
   * router rule — both come from WIDGET_DOMAIN / KB_WIDGET_ORIGIN, and the container has only the
   * origin form at runtime (the domain form is a build ARG and is empty in the running container).
   * A literal here would be a second spelling of the hostname that nothing keeps in step.
   *
   * `preview` gets it too. `pnpm preview` is loopback-only today, but the two servers are one
   * decision and splitting them is how the next person meets this 403 from the other direction.
   * -------------------------------------------------------------------------------------------- */
  preview: {
    host: '127.0.0.1',
    port: 4173,
    strictPort: true,
    allowedHosts: [new URL(widgetOrigin).hostname],
  },
  // Only `host`/`port` are overridden in dev — infrastructure/docker/compose.override.yaml runs
  // `pnpm --filter widget dev --host 0.0.0.0 --port 8080`, and Vite's CLI has no `--allowedHosts`
  // flag (checked against vite 8.2.0's cli.js), which is why this cannot be fixed from Compose.
  server: {
    host: '127.0.0.1',
    port: 4172,
    strictPort: true,
    allowedHosts: [new URL(widgetOrigin).hostname],
  },
}));

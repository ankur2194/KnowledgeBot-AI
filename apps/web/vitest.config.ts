import react from '@vitejs/plugin-react';
import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

// `test.projects`, NEVER vitest.workspace.ts — that file was REMOVED in Vitest 4 and a leftover
// copy is silently ignored, so the suite runs with whatever defaults remain and nobody is told.

/** One literal, two consumers — `test.env` for the node project and the browser project's `define`.
 *  Two spellings of this string would let the two halves of the suite talk to different origins. */
const TEST_API_ORIGIN = 'http://api.invalid';

export default defineConfig({
  test: {
    // `passWithNoTests: true` LIVED HERE AND IS GONE, deleted with the first component spec exactly
    // as its own comment instructed. From the root it covered the WHOLE run, so an empty `components`
    // directory and a `unit` project whose glob had stopped matching were indistinguishable.
    //
    // Measured before deleting, because that comment also made a claim: `vitest run --project unit`
    // found 6 files and 80 tests, so `tests/unit/**/*.test.ts` was matching all along and the only
    // thing this key was hiding was the empty `components` project. Both projects now have specs, so
    // "No test files found" is a real regression signal again and nothing suppresses it.
    //
    // `src/lib/env.ts` validates NEXT_PUBLIC_API_ORIGIN at MODULE LOAD, so any spec importing a
    // module that transitively imports it needs the variable present before the import graph is
    // walked — otherwise the file dies on a zod error with no test having run.
    //
    // `.invalid` is reserved by RFC 2606 and never resolves, ON PURPOSE. Every streaming spec
    // passes an explicit `apiOrigin` pointing at the fixture server, so a spec that forgets one
    // fails on DNS rather than quietly reaching a host that happens to exist.
    env: { NEXT_PUBLIC_API_ORIGIN: TEST_API_ORIGIN },
    projects: [
      {
        // The SSE read loop over adversarial chunk boundaries, query-key construction, the
        // error_class -> copy mapping, schema behaviour. No DOM: jsdom drops Node's
        // ReadableStream/TextDecoder/Response globals, which is exactly what these tests need.
        extends: true,
        test: {
          name: 'unit',
          environment: 'node',
          include: ['tests/unit/**/*.test.ts'],
        },
      },
      {
        // Browser Mode went GA in Vitest 4 and the Playwright binaries are already installed for
        // the E2E suite, so the marginal cost is a launch. Providers are FACTORIES now, not
        // strings, and the old `@vitest/browser` package is deliberately not a dependency.
        extends: true,
        plugins: [react()],
        // `test.env` DOES NOT REACH A BROWSER, and the failure is not a wrong value — it is
        // `ReferenceError: process is not defined` thrown while IMPORTING the spec, so zero tests run
        // and the reported error names src/lib/env.ts rather than the spec. `env.ts:26` reads
        // `process.env.NEXT_PUBLIC_API_ORIGIN` literally, on purpose, because Next's bundler INLINES
        // that exact expression into the client bundle — there is no `process` object in a browser
        // either way. Vite does not do that inlining unless told, so this `define` is what makes the
        // browser project behave like a Next client build. Every component spec that touches
        // browserFetch, streamAnswer or anything under lib/api needs it.
        define: {
          'process.env.NEXT_PUBLIC_API_ORIGIN': JSON.stringify(TEST_API_ORIGIN),
        },
        optimizeDeps: {
          // Vitest reported this by name: zod is discovered mid-run through
          // @/lib/env -> zod, Vite re-optimizes, and the page RELOADS in the middle of a spec file —
          // "Vite unexpectedly reloaded a test ... may lead to flaky behaviour or duplicated test
          // runs". Pre-bundling it is the fix Vitest's own message asks for.
          //
          // The five below were added for the same reason and are the form stack. They were observed
          // exactly once — on the FIRST run after the first form spec landed, with a cold
          // node_modules/.vite, where Vite discovered them mid-run, reloaded the page, and the spec
          // failed to import. It did not reproduce after `rm -rf node_modules/.vite`, which is
          // precisely why they are listed: a warm local cache hides it and CI is always cold, so the
          // failure mode is "green on every machine that has run the suite before, red on CI".
          //
          // Anything a component spec imports transitively belongs here. `radix-ui` is the unified
          // package the shadcn new-york-v4 registry items pull (not the per-primitive
          // @radix-ui/react-* ones), so one entry covers every vendored primitive.
          include: [
            'zod',
            'react-hook-form',
            '@hookform/resolvers/zod',
            'radix-ui',
            'next-themes',
            'class-variance-authority',
          ],
        },
        test: {
          name: 'components',
          include: ['tests/components/**/*.test.tsx'],
          setupFiles: ['tests/msw/setup.ts'],
          // SERIALIZED, AND THIS IS A CORRECTNESS SETTING RATHER THAN A PERFORMANCE ONE.
          //
          // Browser Mode runs each spec FILE in its own iframe, but all those iframes are clients of
          // ONE service-worker registration — the worker is scoped to the origin, not to the frame. And
          // `worker.use(...)` does not configure a local object: it messages the shared worker to
          // prepend handlers. So with files in parallel, an override installed by file A is live for
          // file B, and `resetHandlers()` in A's afterEach tears down B's mid-run.
          //
          // Two failure modes, and the quiet one is worse. The loud one: a login POST in a spec that
          // deliberately expects 422 gets answered by another file's default 200, `onSuccess` fires,
          // and a real navigation kills the iframe — reported as "Cannot connect to the iframe ...
          // Received URL: http://localhost:PORT/sources", naming neither the spec nor the cause, at
          // roughly 1 run in 3. The quiet one: a spec asserting an ERROR path is served a success
          // handler belonging to another file and PASSES for the wrong reason, or vice versa. A flake
          // that fails is a nuisance; a flake that passes is a hole in the suite.
          //
          // The alternative — hardening every spec so a stray navigation cannot escape — treats the
          // symptom and leaves the handler bleed, which is the half that produces false passes.
          // Serializing removes the shared mutable state instead. The components suite is small and
          // browser-bound, so the wall-clock cost is a few seconds; `unit` is unaffected and still
          // parallel.
          fileParallelism: false,
          browser: {
            enabled: true,
            provider: playwright(),
            headless: true,
            instances: [{ browser: 'chromium' }],
          },
        },
      },
    ],
  },
  resolve: {
    alias: {
      '@': new URL('./src/', import.meta.url).pathname,
    },
  },
});

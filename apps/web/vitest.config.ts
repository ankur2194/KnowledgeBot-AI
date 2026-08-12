import react from '@vitejs/plugin-react';
import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

// `test.projects`, NEVER vitest.workspace.ts — that file was REMOVED in Vitest 4 and a leftover
// copy is silently ignored, so the suite runs with whatever defaults remain and nobody is told.
export default defineConfig({
  test: {
    // ROOT-LEVEL ONLY. Vitest 4 lists `passWithNoTests` in `NonProjectOptions`, so the same key
    // inside a `projects[].test` block is a typecheck error and is ignored at runtime — the failure
    // mode being avoided (a run that dies on "No test files found") would have come back anyway.
    //
    // Scaffold only: the `components` project ships with no specs yet. DELETE THIS with the first
    // component spec — from here it covers the WHOLE run, so it also hides a `unit` project whose
    // `include` glob stopped matching, which is a worse thing to hide than an empty directory.
    passWithNoTests: true,
    // `src/lib/env.ts` validates NEXT_PUBLIC_API_ORIGIN at MODULE LOAD, so any spec importing a
    // module that transitively imports it needs the variable present before the import graph is
    // walked — otherwise the file dies on a zod error with no test having run.
    //
    // `.invalid` is reserved by RFC 2606 and never resolves, ON PURPOSE. Every streaming spec
    // passes an explicit `apiOrigin` pointing at the fixture server, so a spec that forgets one
    // fails on DNS rather than quietly reaching a host that happens to exist.
    env: { NEXT_PUBLIC_API_ORIGIN: 'http://api.invalid' },
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
        test: {
          name: 'components',
          include: ['tests/components/**/*.test.tsx'],
          setupFiles: ['tests/msw/setup.ts'],
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

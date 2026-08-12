import { defineConfig } from 'vite';

/**
 * The stream probe's build. A THIRD Vite invocation, and deliberately not a third `--mode` inside
 * `vite.config.ts`: the shipped config describes the two artifacts a customer receives, and adding
 * a test-only mode to it is how a test-only branch eventually ships. This file is under tests/,
 * runs only from the Playwright `webServer` command, and emits into `dist/probe/`, which the
 * `.size-limit.json` globs do not match.
 *
 * It defines the same three `__KB_*` constants the real build does, because `define` is a TEXT
 * SUBSTITUTION: `src/app/stream.ts` reads `__KB_API_ORIGIN__` for its default origin, and an
 * unsubstituted identifier is a ReferenceError in the browser rather than a type error at build
 * time. The probe always passes an explicit `apiOrigin` anyway — the constant still has to exist,
 * and it being wrong-but-present is exactly the failure this substitution prevents.
 */
const widgetOrigin = process.env['KB_WIDGET_ORIGIN'] ?? 'http://127.0.0.1:4173';
const apiOrigin = process.env['KB_API_ORIGIN'] ?? 'http://127.0.0.1:4175';

export default defineConfig({
  build: {
    outDir: 'dist/probe',
    emptyOutDir: true,
    target: ['chrome111', 'edge111', 'firefox114', 'safari16.4', 'ios16.4'],
    lib: {
      entry: 'tests/harness/probe/main.ts',
      formats: ['es' as const],
      fileName: () => 'probe.js',
    },
    // The lazy Markdown chunk must stay lazy here too, or the probe would prove the renderer edge
    // against a shape the app never ships.
    rolldownOptions: { output: { chunkFileNames: '[name]-[hash].js' } },
  },
  define: {
    __KB_VERSION__: JSON.stringify('0.0.0-probe'),
    __KB_WIDGET_ORIGIN__: JSON.stringify(widgetOrigin),
    __KB_API_ORIGIN__: JSON.stringify(apiOrigin),
  },
});
